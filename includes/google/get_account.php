<?php
declare(strict_types=1);

/**
 * Shopify-native Google account fetcher
 * Uses instance_id (NOT user / JWT)
 */
function getGoogleAccountByInstance(string $instanceId): ?array
{
    if ($instanceId === '') {
        return null;
    }

    require_once __DIR__ . '/../config.php';
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM google_accounts
            WHERE instance_id = :instance_id
            LIMIT 1
        ");

        $stmt->execute([
            ':instance_id' => $instanceId
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

return [
    'id'            => (int)$row['id'],
    'instance_id'   => (string)$row['instance_id'],
    'email'         => $row['email'] ?? null,
    'picture'       => $row['picture'] ?? null,
    'access_token'  => $row['access_token'] ?? null,
    'refresh_token' => $row['refresh_token'] ?? null,

    // ✅ FIX: correct expiry column
    'expires_at'    => $row['token_expires_at'] ?? null,

    // ✅ FIX: expose OAuth scopes
    'scope'         => $row['scope'] ?? null,

    'connected'     => (int)($row['connected'] ?? 1),
    'last_error'    => $row['last_error'] ?? null,
];


    } catch (Throwable $e) {
        error_log('getGoogleAccountByInstance error: ' . $e->getMessage());
        return null;
    }
}

/**
 * 🔁 BACKWARD COMPATIBILITY
 * Old code may still call this.
 * Do NOT remove until migration is complete.
 */
function getGoogleAccountByShop(string $instanceId): ?array
{
    return getGoogleAccountByInstance($instanceId);
}


function getGoogleAccountByUser(string $instanceId): ?array
{
    if ($instanceId === '') {
        return null;
    }

    require_once __DIR__ . '/../config.php';
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM google_accounts
            WHERE instance_id = :instance_id
            LIMIT 1
        ");

        $stmt->execute([
            ':instance_id' => $instanceId
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

return [
    'id'            => (int)$row['id'],
    'instance_id'   => (string)$row['instance_id'],
    'email'         => $row['email'] ?? null,
    'picture'       => $row['picture'] ?? null,
    'access_token'  => $row['access_token'] ?? null,
    'refresh_token' => $row['refresh_token'] ?? null,

    // ✅ FIX: correct expiry column
    'expires_at'    => $row['token_expires_at'] ?? null,

    // ✅ FIX: expose OAuth scopes
    'scope'         => $row['scope'] ?? null,

    'connected'     => (int)($row['connected'] ?? 1),
    'last_error'    => $row['last_error'] ?? null,
];


    } catch (Throwable $e) {
        error_log('getGoogleAccountByInstance error: ' . $e->getMessage());
        die('dd');
        return null;
    }
}