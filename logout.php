<?php
// /logout.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ✅ Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load config early so .env is available
include('/wordpress/googlesearchconsole/includes/config.php');
$BASE_URL = rtrim(getenv('BASE_URL') ?: ($_ENV['BASE_URL'] ?? ''), '/');

// Small helper to safely join URLs
function url_join($base, $path) {
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

// ✅ Unset just the instance first (your app check uses this)
unset($_SESSION['instanceid']);

// ✅ Clear the entire session & cookie for good measure
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

// 🚀 Redirect back to login (BASE_URL-aware)
header('Location: ' . url_join($BASE_URL, '/sign-in.php'));
exit;
