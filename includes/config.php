<?php
// includes/config.php  (WordPress platform - phase 1 bootstrap)
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');   // errors go to the log, never to the client
ini_set('log_errors', '1');

date_default_timezone_set('UTC');

/* The panel lives at /wordpress/googlesearchconsole on the same host
   pattern as the other five platforms. */
if (!defined('APP_BASE')) {
    define('APP_BASE', '/wordpress/googlesearchconsole');
}
if (!defined('APP_NAME')) {
    define('APP_NAME', 'wordpress');
}

require_once __DIR__ . '/db.php';
