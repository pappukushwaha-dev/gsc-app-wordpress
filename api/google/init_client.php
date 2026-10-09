<?php
// /api/google/init_client.php
declare(strict_types=1);

// Load Google SDK
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoload)) {
    throw new Exception("Google SDK not installed: missing vendor/autoload.php");
}
require_once $autoload;

// Load DB + ENV
require_once __DIR__ . '/../../includes/config.php';

/**
 * Build Google Client instance using DB credentials
 */
function google_client(): Google_Client {
    global $pdo;

    // Fetch OAuth Client from DB
    $stmt = $pdo->prepare("SELECT client_id, client_secret, redirect_uri FROM google_settings LIMIT 1");
    $stmt->execute();
    $settings = $stmt->fetch();

    if (!$settings) {
        throw new Exception("Google API credentials missing in google_settings.");
    }

    $client = new Google_Client();
    $client->setClientId($settings['client_id']);
    $client->setClientSecret($settings['client_secret']);
    $client->setRedirectUri($settings['redirect_uri']);

    $client->setScopes([
        Google_Service_Webmasters::WEBMASTERS,
        'https://www.googleapis.com/auth/webmasters.readonly',
        'https://www.googleapis.com/auth/siteverification',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile',
    ]);

    $client->setAccessType('offline');
    $client->setPrompt('consent select_account');
    $client->setIncludeGrantedScopes(true);

    return $client;
}
