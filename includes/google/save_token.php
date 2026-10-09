<?php
function google_save_token(PDO $pdo, string $instanceId, array $token, array $userInfo)
{
    $expiresAt = null;
    if (!empty($token['expires_in'])) {
        $expiresAt = date('Y-m-d H:i:s', time() + (int)$token['expires_in']);
    }

    /* ==========================================
    NORMALIZE OAUTH SCOPE
    ========================================== */
    $scope = null;

    if (!empty($token['scope'])) {
        // Google may return scope as string or array
        $scope = is_array($token['scope'])
            ? implode(' ', $token['scope'])
            : (string) $token['scope'];
    }


    /* ==========================================
       CHECK EXISTING ACCOUNT
    ========================================== */
    $stmt = $pdo->prepare("
        SELECT id, refresh_token
        FROM google_accounts
        WHERE instance_id = ?
    ");
    $stmt->execute([$instanceId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $existingRefresh = $existing['refresh_token'] ?? null;

    /* ==========================================
       KEEP REFRESH TOKEN SAFE
    ========================================== */
    if (!empty($token['refresh_token']) && empty($existingRefresh)) {
        $finalRefreshToken = $token['refresh_token'];
    } else {
        $finalRefreshToken = $existingRefresh;
    }

    /* ==========================================
       UPDATE
    ========================================== */
    if ($existing) {

        $stmt = $pdo->prepare("
           UPDATE google_accounts
SET
    email            = :email,
    access_token     = :access,
    refresh_token    = :refresh,
    token_expires_at = :expires,
    scope            = :scope,
    connected        = 1,
    last_error       = NULL,
    updated_at       = NOW()

            WHERE instance_id = :instance
        ");

$stmt->execute([
    ':email'    => $userInfo['email'] ?? '',
    ':access'   => $token['access_token'] ?? null,
    ':refresh'  => $finalRefreshToken,
    ':expires'  => $expiresAt,
    ':scope'    => $scope,
    ':instance' => $instanceId
]);


    } 
/* ==========================================
   INSERT
========================================== */
else {

    $stmt = $pdo->prepare("
        INSERT INTO google_accounts
            (instance_id, email, access_token, refresh_token, token_expires_at, scope, connected)
        VALUES
            (:instance, :email, :access, :refresh, :expires, :scope, 1)
    ");

    $stmt->execute([
        ':instance' => $instanceId,
        ':email'    => $userInfo['email'] ?? '',
        ':access'   => $token['access_token'] ?? null,
        ':refresh'  => $finalRefreshToken,
        ':expires'  => $expiresAt,
        ':scope'    => $scope
    ]);
}

require_once __DIR__ . '/maybe_start_backfill.php';
gsc_maybe_start_backfill($pdo, (string)$instanceId);

    return true;
}
