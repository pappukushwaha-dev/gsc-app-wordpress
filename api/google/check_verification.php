<?php
// api/google/check_verification.php
declare(strict_types=1);

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, return JSON instead

header('Content-Type: application/json; charset=utf-8');

// Top-level error handler
try {
    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/google/get_account.php';
    require_once __DIR__ . '/../../includes/google/token_manager.php';

// ---------------------------
// Validate instance
// ---------------------------
$instanceId = $_GET['instanceId'] ?? null;
if (!$instanceId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing instanceId']);
    exit;
}

// ---------------------------
// Load Google Account
// ---------------------------
$google = getGoogleAccountByShop($instanceId);

if (!$google || empty($google['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Google account not connected']);
    exit;
}

// ---------------------------
// Ensure valid access token
// ---------------------------
$accountId = (int)$google['id'];
$tokenResult = ensureAccessToken($accountId, 120);

if (!$tokenResult['success']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Failed to obtain valid Google access token']);
    exit;
}

$accessToken = $tokenResult['access_token'];

// ---------------------------
// Load domain for this instance
// ---------------------------
$stmt = $pdo->prepare("
    SELECT site_url, meta_token 
    FROM gsc_domain_verifications 
    WHERE instance_id = ?
    LIMIT 1
");
$stmt->execute([$instanceId]);
$domain = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$domain) {
    echo json_encode(['success' => false, 'error' => 'Domain record not found']);
    exit;
}

$metaToken    = trim((string)$domain['meta_token']);
$rawSiteValue = trim((string)$domain['site_url']);

// ---------------------------
// Normalization helper
// - Ensure we have a host; if scheme missing, assume https for canonicalization
// ---------------------------
function normalize_host_and_variants(string $raw): array {
    if ($raw === '') {
        return ['host' => '', 'canonical' => '', 'variants' => []];
    }

    $parsed = parse_url($raw);
    if (empty($parsed['host'])) {
        $parsed = parse_url('https://' . $raw);
    }

    $host = strtolower($parsed['host'] ?? '');
    $host = preg_replace('/^www\./i', '', $host);

    $canonical = "https://{$host}/";

    $variants = [
        "https://{$host}/",
        "https://www.{$host}/",
        "http://{$host}/",
        "http://www.{$host}/"
    ];

    return [
        'host'      => $host,
        'canonical' => $canonical,
        'variants'  => array_values(array_unique($variants))
    ];
}

$norm            = normalize_host_and_variants($rawSiteValue);
$host            = $norm['host'];
$normalizedUrl   = $norm['canonical'];


if (!$host) {
    echo json_encode(['success' => false, 'error' => 'Invalid site_url']);
    exit;
}



// ---------------------------
// GOOGLE API REQUEST - siteVerification list
// ---------------------------
$apiUrl = "https://www.googleapis.com/siteVerification/v1/webResource";

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer " . $accessToken,
        "Content-Type: application/json"
    ],
    CURLOPT_TIMEOUT        => 10,
]);
$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$listHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr) {
    echo json_encode(['success' => false, 'error' => "cURL Error: $curlErr"]);
    exit;
}

// Handle Google API errors
if ($listHttp !== 200) {
    $errorMsg = 'Google API error';
    if ($listHttp === 401 || $listHttp === 403) {
        $errorMsg = 'Google API authentication failed. Please reconnect your Google account.';
    } elseif ($listHttp === 429) {
        $errorMsg = 'Google API rate limit exceeded. Please try again later.';
    }
    
    $json = json_decode($response, true);
    if (is_array($json) && isset($json['error']['message'])) {
        $errorMsg = $json['error']['message'];
    }
    
    http_response_code($listHttp >= 400 && $listHttp < 500 ? $listHttp : 500);
    echo json_encode(['success' => false, 'error' => $errorMsg, 'http_code' => $listHttp]);
    exit;
}

$json = json_decode($response, true);
if (!is_array($json)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Invalid Google response', 'raw' => substr($response, 0, 200)]);
    exit;
}

// ---------------------------
// CHECK VERIFIED via Google API
// ---------------------------
$verified              = false;
$accessibleSiteEntries = $json['items'] ?? [];

if (!empty($accessibleSiteEntries)) {
    foreach ($accessibleSiteEntries as $item) {
        $identifier = $item['site']['identifier'] ?? '';

        if ($identifier === '') {
            continue;
        }

        // match URL-prefix (normalize by trimming trailing slash for comparison)
        if (rtrim($identifier, '/') === rtrim($normalizedUrl, '/') ||
            strcasecmp($identifier, $normalizedUrl) === 0) {
            $verified = true;
            break;
        }


    }
}

// Always update check time
try {
    $pdo->prepare("
        UPDATE gsc_domain_verifications
        SET verification_checked_at = NOW()
        WHERE instance_id = ?
    ")->execute([$instanceId]);
} catch (Throwable $e) {
    error_log("check_verification.php: Failed to update verification_checked_at: " . $e->getMessage());
}



// ---------------------------
// If verified (either by API or by meta tag/fallback), update DB
// ---------------------------
if ($verified) {
    try {
        $stmt = $pdo->prepare("
            UPDATE gsc_domain_verifications
            SET
                verification_status      = 'verified',
                verification_verified_at = NOW(),
                verification_checked_at  = NOW()
            WHERE instance_id = ?
        ");
        $stmt->execute([$instanceId]);
    } catch (Throwable $e) {
        error_log("check_verification.php DB update failed: " . $e->getMessage());
    }
    require_once __DIR__ . '/../../includes/google/maybe_start_backfill.php';
    gsc_maybe_start_backfill($pdo, (string)$instanceId);
}


// ---------------------------
// Return to frontend
// ---------------------------
try {
echo json_encode([
    'success'               => true,
    'verified'              => (bool)$verified,
    'checked_host'          => $host,
    'checked_url_canonical' => $normalizedUrl,
    'meta_token'            => $metaToken,
    'accessible_site_entries' => $accessibleSiteEntries
], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    error_log("check_verification.php: JSON encode error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to generate response']);
}

} catch (Throwable $e) {
    // Top-level error handler
    http_response_code(500);
    error_log("check_verification.php: Unhandled error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    echo json_encode([
        'success' => false,
        'error' => 'An unexpected error occurred',
        'message' => $e->getMessage()
    ]);
}
exit;
