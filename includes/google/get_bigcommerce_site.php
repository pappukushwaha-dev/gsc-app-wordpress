<?php
declare(strict_types=1);

/**
 * Shopify replacement for getWixSiteByInstance
 * Keeps SAME return structure
 */
function getWpSiteByInstance(string $instanceId): ?array
{
    if ($instanceId === '') {
        return null;
    }

    // USE PDO FROM GLOBAL SCOPE (already loaded)
    global $pdo;

    if (!isset($pdo) || !$pdo instanceof PDO) {
        error_log('❌ PDO not available in getWpSiteByInstance');
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                instance_id,
                domain,
                shop_name,
                email
            FROM WpSite
            WHERE instance_id = ?
              AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$instanceId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            error_log('❌ WpSite not found for instance: ' . $instanceId);
            return null;
        }

        return [
            'instance_id' => $row['instance_id'],
           'site_url' => 'https://' . rtrim($row['domain'], '/') . '/',

            'site_name'   => $row['shop_name'] ?? 'Shop',
            'email'       => $row['email'] ?? null,
            'platform'    => 'shopify',
        ];

    } catch (Throwable $e) {
        error_log('❌ getWpSiteByInstance error: ' . $e->getMessage());
        return null;
    }
}
