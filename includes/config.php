<?php
// includes/config.php  (WordPress platform)
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');   // errors go to the log, never to the client
ini_set('log_errors', '1');

date_default_timezone_set('UTC');

/* ==========================================================
   PLATFORM CONSTANTS
   APP_BASE  -> never ends with a slash
   APP_URL   -> always ends with a slash
   On the production host the panel is served from
   makkpressapps.com; on local/staging hosts the URL is built
   from the request host so the wizard links stay clickable.
========================================================== */

if (!defined('APP_BASE')) {
    define('APP_BASE', '/wordpress/googlesearchconsole');
}

$httpHost = $_SERVER['HTTP_HOST'] ?? '';
$isLocalHost = ($httpHost === '' )
    || str_contains($httpHost, 'localhost')
    || str_contains($httpHost, '127.0.0.1');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

if ($isLocalHost && $httpHost !== '') {
    $appUrl = $scheme . '://' . $httpHost . APP_BASE . '/';
} else {
    $appUrl = 'https://makkpressapps.com' . APP_BASE . '/';
}

if (!defined('APP_URL')) {
    define('APP_URL', $appUrl);
}
if (!defined('HOST_URL')) {
    define('HOST_URL', $appUrl);
}
if (!defined('APP_ENV')) {
    define('APP_ENV', $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production');
}

require_once __DIR__ . '/db.php';
