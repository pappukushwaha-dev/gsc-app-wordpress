<?php
// includes/google/token_manager.php
declare(strict_types=1);

require_once __DIR__ . '/refresh_token.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../credentials.php';

/**
 * Ensure a valid access token for $accountId.
 * Returns array: ['success'=>true,'access_token'=>string] or ['success'=>false,'error'=>'...']
 */
function ensureAccessToken(int $accountId, int $safetySeconds = 120) : array {
    global $pdo;

    // Fetch the account row
    $stmt = $pdo->prepare("SELECT * FROM google_accounts WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $accountId]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rec) return ['success'=>false, 'error'=>'not_found'];

    // If developer added 'connected' flag
    if (isset($rec['connected']) && (int)$rec['connected'] === 0) {
        return ['success'=>false, 'error'=>'account_disconnected'];
    }

    $now = new DateTime('now', new DateTimeZone('UTC'));
    $expiresAt = !empty($rec['token_expires_at']) ? new DateTime($rec['token_expires_at'], new DateTimeZone('UTC')) : null;

    // still valid?
    if ($expiresAt && ($expiresAt->getTimestamp() - $now->getTimestamp() > $safetySeconds) && !empty($rec['access_token'])) {
        return ['success'=>true, 'access_token'=>$rec['access_token']];
    }

    // Use a lightweight DB lock to avoid simultaneous refreshes (MySQL GET_LOCK)
    $lockName = "gsc_refresh_lock_" . $accountId;
    $got = false;

    try {
        try {
            $ldb = $pdo->prepare("SELECT GET_LOCK(:lock, 5) AS got");
            $ldb->execute([':lock' => $lockName]);
            $row = $ldb->fetch(PDO::FETCH_ASSOC);
            $got = (bool)($row['got'] ?? 0);
        } catch (Throwable $e) {
            // proceed without lock if GET_LOCK not available
            $got = false;
        }

        // If lock not obtained we still try to sleep briefly and re-check
        if (!$got) {
            usleep(200000); // 200ms
            $stmt = $pdo->prepare("SELECT access_token, token_expires_at FROM google_accounts WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $accountId]);
            $rec2 = $stmt->fetch(PDO::FETCH_ASSOC);
            $expiresAt2 = !empty($rec2['token_expires_at']) ? new DateTime($rec2['token_expires_at'], new DateTimeZone('UTC')) : null;
            if ($expiresAt2 && ($expiresAt2->getTimestamp() - $now->getTimestamp() > $safetySeconds) && !empty($rec2['access_token'])) {
                return ['success'=>true, 'access_token'=>$rec2['access_token']];
            }
        }

        // Perform refresh
        $refreshRecord = $rec;
        $refreshResult = null;
        try {
            $refreshResult = refreshGoogleToken($refreshRecord);
        } catch (Throwable $e) {
            error_log("refreshGoogleToken threw exception for account {$accountId}: " . $e->getMessage());
            return ['success'=>false, 'error'=>'refresh_exception', 'message'=>$e->getMessage()];
        }

        // Handle different return shapes
        if (is_string($refreshResult) && $refreshResult !== '') {
            return ['success'=>true, 'access_token'=>$refreshResult];
        }

        if (is_array($refreshResult) && isset($refreshResult['success']) && $refreshResult['success'] === true) {
            if (!empty($refreshResult['access_token'])) {
                return ['success'=>true, 'access_token'=>$refreshResult['access_token']];
            }
            $stmt = $pdo->prepare("SELECT access_token, token_expires_at FROM google_accounts WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $accountId]);
            $rowAfter = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($rowAfter['access_token'])) {
                return ['success'=>true, 'access_token'=>$rowAfter['access_token']];
            }
            return ['success'=>false, 'error'=>'no_token_returned'];
        }

        if (is_array($refreshResult)) {
            $err = $refreshResult['error'] ?? null;
            $googleResp = $refreshResult['google_response'] ?? null;
            $respText = is_string($googleResp) ? $googleResp : (is_array($googleResp) ? json_encode($googleResp) : '');
            $combined = strtolower(($err ?? '') . ' ' . $respText);

            if (strpos($combined, 'invalid_grant') !== false || stripos($respText, 'invalid_grant') !== false) {
                try {
                    $u = $pdo->prepare("UPDATE google_accounts SET connected = 0, last_error = :err, updated_at = NOW() WHERE id = :id");
                    $u->execute([':err' => json_encode($refreshResult), ':id' => $accountId]);
                } catch (Throwable $e) {
                    error_log("Failed to mark account {$accountId} disconnected: " . $e->getMessage());
                }
                return ['success'=>false, 'error'=>'invalid_grant', 'details'=>$refreshResult];
            }

            try {
                $pdo->prepare("UPDATE google_accounts SET last_error = :err, updated_at = NOW() WHERE id = :id")
                    ->execute([':err' => json_encode($refreshResult), ':id' => $accountId]);
            } catch (Throwable $e) {
                error_log("Failed to save last_error for account {$accountId}: " . $e->getMessage());
            }

            return ['success'=>false, 'error'=>'refresh_failed', 'details'=>$refreshResult];
        }

        return ['success'=>false, 'error'=>'refresh_unknown', 'data'=> $refreshResult];

    } finally {
        if (!empty($got)) {
            try {
                $pdo->prepare("SELECT RELEASE_LOCK(:lock)")->execute([':lock'=>$lockName]);
            } catch (Throwable $e) {
                error_log("Failed to release lock {$lockName}: " . $e->getMessage());
            }
        }
    }
}

