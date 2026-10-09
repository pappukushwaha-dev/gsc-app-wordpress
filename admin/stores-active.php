<?php
// File: /wix/googlesearchconsole/admin/stores-active.php
declare(strict_types=1);

$title = 'Active Stores';
$subTitle = 'Active Stores';

ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);

// Includes (same as stores.php)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';

// Start session & auth check (same protection as stores.php)
SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

// Build query string, but FORCE search=active
$query = $_GET;
$query['search'] = 'active'; // 🔴 filter only active users

// Optional: sensible defaults for pagination if not present
if (!isset($query['show'])) {
    $query['show'] = 10;
}
if (!isset($query['page'])) {
    $query['page'] = 1;
}

$qs = http_build_query($query);

// Redirect to main Stores page with ?search=active
header('Location: ' . APP_BASE . '/admin/stores.php?' . $qs);
exit;
