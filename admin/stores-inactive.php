<?php
// File: /wix/googlesearchconsole/admin/stores-inactive.php
declare(strict_types=1);

$title = 'Inactive Stores';
$subTitle = 'Inactive Stores';

ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);

// Includes (same as stores.php)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';

// Start session & auth check
SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

// Build query string, but FORCE search=inactive
$query = $_GET;
$query['search'] = 'inactive'; // 🔴 filter only inactive users

// Optional: sensible defaults
if (!isset($query['show'])) {
    $query['show'] = 10;
}
if (!isset($query['page'])) {
    $query['page'] = 1;
}

$qs = http_build_query($query);

// Redirect to main Stores page with ?search=inactive
header('Location: ' . APP_BASE . '/admin/stores.php?' . $qs);
exit;
