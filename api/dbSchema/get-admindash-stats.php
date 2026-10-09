<?php
header('Content-Type: application/json');

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../admin/core/SessionManager.php';
require_once __DIR__ . '/../../admin/core/AuthController.php';

SessionManager::startDatabaseSession();

// Make sure admin is logged in
if (!AuthController::isAuthenticated()) {
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized',
    ]);
    exit;
}

$instanceId = $_GET['instance_id'] ?? null;

try {

    /**
     * ============================================================
     * CASE 1: Per-store stats (Store Profile page)
     * ============================================================
     */
    if ($instanceId) {
        // --------------------------------------------
        // 1) Google account status (google_accounts)
        // --------------------------------------------
        $stmt = $pdo->prepare("
            SELECT email
            FROM google_accounts
            WHERE instanceId = :instance_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':instance_id' => $instanceId]);
        $google = $stmt->fetch(PDO::FETCH_ASSOC);

        $hasGoogleAccount = (bool) $google;
        $googleEmail      = $google['email'] ?? null;

        // --------------------------------------------
        // 2) Domain verification (gsc_domain_verifications)
        // --------------------------------------------
        $stmt = $pdo->prepare("
            SELECT verification_status, site_url
            FROM gsc_domain_verifications
            WHERE instance_id = :instance_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':instance_id' => $instanceId]);
        $domain = $stmt->fetch(PDO::FETCH_ASSOC);

        // verification_status values in DB: 'verified', 'pending', 'failed', etc.
        $domainVerified = $domain && $domain['verification_status'] === 'verified';

        // --------------------------------------------
        // 3) Last sitemap (sitemaps) - for card
        // --------------------------------------------
        $stmt = $pdo->prepare("
            SELECT sitemap_url, last_submitted, status
            FROM sitemaps
            WHERE instance_id = :instance_id
            ORDER BY last_submitted DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([':instance_id' => $instanceId]);
        $lastSitemap = $stmt->fetch(PDO::FETCH_ASSOC);

        $lastSitemapStatus    = null;  // Success / Pending / Error ...
        $lastSitemapFormatted = null;

        if ($lastSitemap) {
            $lastSitemapStatus = $lastSitemap['status'];

            $datePart = $lastSitemap['last_submitted']
                ? date('d M Y H:i', strtotime($lastSitemap['last_submitted']))
                : 'Never submitted';

            $lastSitemapFormatted = $lastSitemap['sitemap_url'] . ' • ' .
                $datePart . ' • ' .
                $lastSitemap['status'];
        }

        // --------------------------------------------
        // 4) Latest updates table (last 5 sitemaps)
        // --------------------------------------------
        $stmt = $pdo->prepare("
            SELECT sitemap_url, last_submitted, status
            FROM sitemaps
            WHERE instance_id = :instance_id
            ORDER BY last_submitted DESC, id DESC
            LIMIT 5
        ");
        $stmt->execute([':instance_id' => $instanceId]);
        $latest = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $latestUpdates = array_map(function ($row) {
            return [
                'sitemap_url'  => $row['sitemap_url'],
                'last_updated' => $row['last_submitted']
                    ? date('d M Y H:i', strtotime($row['last_submitted']))
                    : 'Never',
                'status'       => $row['status'],
            ];
        }, $latest);

        echo json_encode([
            'success'                => true,
            'mode'                   => 'instance',
            'instance_id'            => $instanceId,
            'has_google_account'     => $hasGoogleAccount,
            'google_email'           => $googleEmail,
            'domain_verified'        => $domainVerified,
            'last_sitemap_status'    => $lastSitemapStatus,
            'last_sitemap_formatted' => $lastSitemapFormatted,
            'latest_updates'         => $latestUpdates,
        ]);
        exit;
    }

    // /**
    //  * ============================================================
    //  * CASE 2: Global admin stats (Admin Dashboard)
    //  * ============================================================
    //  * (your old behaviour, kept intact)
    //  */
    // // 1. Total Users (from WixSite)
    // $stmt = $pdo->query("SELECT COUNT(*) FROM `WixSite`");
    // $totalUsers = (int) $stmt->fetchColumn();

    // // 2. Active Users (users that exist in google_accounts)
    // $stmt = $pdo->query("SELECT COUNT(DISTINCT `instanceId`) FROM `google_accounts`");
    // $activeUsers = (int) $stmt->fetchColumn();

    // // 3. Inactive Users = Total - Active
    // $inactiveUsers = max(0, $totalUsers - $activeUsers);

    // // 4. Latest Registered Users (from WixSite)
    // $latestUsersStmt = $pdo->query("
    //     SELECT 
    //         instance_id,
    //         site_display_name,
    //         owner_email,
    //         created_at
    //     FROM `WixSite`
    //     ORDER BY created_at DESC
    //     LIMIT 50
    // ");
    // $latestUsers = $latestUsersStmt->fetchAll(PDO::FETCH_ASSOC);

    // // 5. Templates from documentation (optional)
    // $latestTemplates = [];
    // $totalTemplates  = 0;

    // try {
    //     $stmt = $pdo->query("SELECT COUNT(*) FROM `documentation`");
    //     $totalTemplates = (int) $stmt->fetchColumn();

    //     $latestTemplatesStmt = $pdo->query("
    //         SELECT 
    //             id,
    //             title AS name,
    //             slug,
    //             created_at
    //         FROM `documentation`
    //         ORDER BY created_at DESC
    //         LIMIT 10
    //     ");
    //     $latestTemplates = $latestTemplatesStmt->fetchAll(PDO::FETCH_ASSOC);
    // } catch (Throwable $inner) {
    //     $totalTemplates  = 0;
    //     $latestTemplates = [];
    // }

    // echo json_encode([
    //     'success'          => true,
    //     'mode'             => 'global',
    //     'total_users'      => $totalUsers,
    //     'active_users'     => $activeUsers,
    //     'inactive_users'   => $inactiveUsers,
    //     'total_templates'  => $totalTemplates,
    //     'latest_users'     => $latestUsers,
    //     'latest_templates' => $latestTemplates,
    // ]);

    /**
     * ============================================================
     * CASE 2: Global admin stats (Admin Dashboard – Shopify)
     * ============================================================
     */

    // 1. Total Users (Shopify installs)
    $stmt = $pdo->query("
    SELECT COUNT(*) 
    FROM WpSite
");
    $totalUsers = (int) $stmt->fetchColumn();

    // 2. Active Users
    $stmt = $pdo->query("
    SELECT COUNT(*) 
    FROM WpSite 
    WHERE is_active = 1
");
    $activeUsers = (int) $stmt->fetchColumn();

    // 3. Inactive Users
    $stmt = $pdo->query("
    SELECT COUNT(*) 
    FROM WpSite 
    WHERE is_active = 0
");
    $inactiveUsers = (int) $stmt->fetchColumn();

    // 4. Latest Registered Users
    $latestUsersStmt = $pdo->query("
    SELECT 
        instance_id,
        shop_name AS site_display_name,
        email     AS owner_email,
        created_at
    FROM WpSite
    ORDER BY created_at DESC
    LIMIT 50
");
    $latestUsers = $latestUsersStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'        => true,
        'mode'           => 'global',
        'total_users'    => $totalUsers,
        'active_users'   => $activeUsers,
        'inactive_users' => $inactiveUsers,
        'latest_users'   => $latestUsers
    ]);
    exit;
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error'   => 'DB Error: ' . $e->getMessage(),
    ]);
}
