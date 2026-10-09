<?php
// includes/google/refresh_token.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../credentials.php';

function refreshGoogleToken(array $record)
{
    global $pdo;

    // Basic validation
    if (empty($record['refresh_token'])) {
        error_log("refreshGoogleToken: missing refresh_token for record id: " . ($record['id'] ?? 'unknown'));
        return ['success' => false, 'error' => 'missing_refresh_token'];
    }

    // Prefer credentials.php-provided values
    $clientId = $googleClientId ?? null;
    $clientSecret = $googleClientSecret ?? null;

    // Fallback: read latest google_settings row
    if (empty($clientId) || empty($clientSecret)) {
        try {
            $stmt = $pdo->prepare("SELECT client_id, client_secret FROM google_settings ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $gs = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("refreshGoogleToken DB error while reading google_settings: " . $e->getMessage());
            return ['success' => false, 'error' => 'db_error', 'message' => $e->getMessage()];
        }

        if (!$gs || empty($gs['client_id']) || empty($gs['client_secret'])) {
            error_log("refreshGoogleToken: google_settings missing client credentials");
            return ['success' => false, 'error' => 'missing_client_credentials'];
        }

        $clientId = $gs['client_id'];
        $clientSecret = $gs['client_secret'];

        // If client_secret appears to be encrypted, try to decrypt
        if (!empty($clientSecret) && function_exists('decrypt')) {
            $maybeDecrypted = decrypt($clientSecret, defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : '');
            if ($maybeDecrypted !== false && $maybeDecrypted !== null) {
                $clientSecret = $maybeDecrypted;
            }
        }
    }

    if (empty($clientId) || empty($clientSecret)) {
        error_log("refreshGoogleToken: final client credentials empty");
        return ['success' => false, 'error' => 'missing_client_credentials_final'];
    }

    $refreshToken = $record['refresh_token'];

    // cURL request to token endpoint
    $ch = curl_init("https://oauth2.googleapis.com/token");
    $postFields = http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token'
    ]);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FAILONERROR => false,
    ]);

    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("refreshGoogleToken cURL error: $curlErr");
        return ['success' => false, 'error' => 'curl_error', 'message' => $curlErr];
    }

    // Try decode response
    $json = json_decode((string)$response, true);

    if ($http < 200 || $http >= 300) {
        error_log("refreshGoogleToken HTTP $http response for record id " . ($record['id'] ?? 'unknown') . ": " . substr((string)$response, 0, 2000));
        return [
            'success' => false,
            'http' => $http,
            'google_response' => $json ?? $response
        ];
    }

    if (!is_array($json) || empty($json['access_token'])) {
        error_log("refreshGoogleToken invalid token response: " . substr((string)$response, 0, 2000));
        return [
            'success' => false,
            'error' => 'invalid_token_response',
            'google_response' => $json ?? $response
        ];
    }

    // Build token data for storage
    $tokenData = $json;
    $tokenData['created'] = time();
    if (isset($json['expires_in'])) {
        $tokenData['expires_in'] = (int)$json['expires_in'];
    }

    // If Google returns a new refresh_token (rare on refresh), save it; otherwise keep existing
    $newRefreshToken = $json['refresh_token'] ?? $refreshToken;

    // Compute token_expires_at as DATETIME
    $expiresAtSql = null;
    if (!empty($tokenData['expires_in'])) {
        $expiresAtTs = time() + (int)$tokenData['expires_in'];
        $expiresAtSql = date('Y-m-d H:i:s', $expiresAtTs);
    }

    // Persist into DB
    try {
        $updateStmt = $pdo->prepare("
            UPDATE google_accounts
            SET access_token = :access_token,
                refresh_token = :refresh_token,
                token_expires_at = :token_expires_at,
                updated_at = NOW()
            WHERE id = :id
        ");

        $updateStmt->execute([
            ':access_token' => $json['access_token'],
            ':refresh_token' => $newRefreshToken,
            ':token_expires_at' => $expiresAtSql,
            ':id' => $record['id']
        ]);
    } catch (Throwable $e) {
        error_log("refreshGoogleToken DB update failed: " . $e->getMessage());
    }

    // Success — return the raw access token string
    return $json['access_token'];
}

