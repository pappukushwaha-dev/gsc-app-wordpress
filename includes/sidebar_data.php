<?php
declare(strict_types=1);

/**
 * Sidebar data loader – Shopify
 * Safe to include on sign-in.php and all pages
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php'; // provides $pdo

/* =====================================================
   1. INSTANCE ID (SAFE)
===================================================== */
$instanceId = $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;;

/**
 * Sidebar is included on sign-in.php
 * → Never fatal if instance is missing
 */
if (!$instanceId) {
    // Defaults so layout files never crash
    $sidebarShop = null;
    $sidebarPlan = 'free';

    // Admin branding defaults (still needed by head.php)
    $logoPath     = 'assets/images/logo.png';
    $darkLogoPath = $logoPath;
    $faviconPath  = 'assets/images/favicon.png';

    return;
}

/* =====================================================
   2. FETCH SHOP DATA (WpSite)
===================================================== */
$stmt = $pdo->prepare("
    SELECT shop_name, shop_domain
    FROM WpSite
    WHERE instance_id = ?
    LIMIT 1
");
$stmt->execute([$instanceId]);
$shopRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shopRow) {
    $sidebarShop = null;
    $sidebarPlan = 'free';
} else {
    $sidebarShop = [
        'name'   => $shopRow['shop_name'] ?: $shopRow['shop_domain'],
        'domain' => $shopRow['shop_domain']
    ];
    $sidebarPlan = 'free'; // default, resolved below
}

/* =====================================================
   3. ACTIVE SUBSCRIPTION (app_subscriptions)
===================================================== */
$subStmt = $pdo->prepare("
    SELECT plan_name, billing_period, status
    FROM app_subscriptions
    WHERE instance_id = ?
    ORDER BY id DESC
    LIMIT 1
");
$subStmt->execute([$instanceId]);
$subscription = $subStmt->fetch(PDO::FETCH_ASSOC);

if ($subscription && in_array($subscription['status'], ['active', 'trialing'], true)) {
    // Example: Free / Grow
    $sidebarPlan = strtolower($subscription['plan_name']);
} else {

    /* =====================================================
       4. FREE TRIAL CHECK (app_free_trials)
    ===================================================== */
    $trialStmt = $pdo->prepare("
        SELECT status
        FROM app_free_trials
        WHERE instance_id = ?
          AND status = 'active'
          AND expires_on > NOW()
        LIMIT 1
    ");
    $trialStmt->execute([$instanceId]);
    $trial = $trialStmt->fetch(PDO::FETCH_ASSOC);

    if ($trial) {
        $sidebarPlan = 'trial';
    } else {
        $sidebarPlan = 'free';
    }
}

/* =====================================================
   5. ADMIN BRANDING (logo / favicon)
===================================================== */
try {
    $stmt = $pdo->prepare("
        SELECT value
        FROM admin_settings
        WHERE key_name = 'logo_path'
        LIMIT 1
    ");
    $stmt->execute();
    $logoPath = $stmt->fetchColumn() ?: 'assets/images/logo.png';
} catch (Throwable $e) {
    $logoPath = 'assets/images/logo.png';
}

try {
    $stmt = $pdo->prepare("
        SELECT value
        FROM admin_settings
        WHERE key_name = 'logo_dark_path'
        LIMIT 1
    ");
    $stmt->execute();
    $darkLogoPath = $stmt->fetchColumn() ?: $logoPath;
} catch (Throwable $e) {
    $darkLogoPath = $logoPath;
}

try {
    $stmt = $pdo->prepare("
        SELECT value
        FROM admin_settings
        WHERE key_name = 'favicon_path'
        LIMIT 1
    ");
    $stmt->execute();
    $faviconPath = $stmt->fetchColumn() ?: 'assets/images/favicon.png';
} catch (Throwable $e) {
    $faviconPath = 'assets/images/favicon.png';
}

/* =====================================================
   6. ACTIVE MENU HELPER
===================================================== */
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

$isActiveUrl = function (string $url) use ($currentPage): bool {
    return strtolower($currentPage) === strtolower($url);
};
