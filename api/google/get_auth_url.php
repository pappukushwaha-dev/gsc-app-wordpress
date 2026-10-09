<?php
require_once __DIR__ . '/init_client.php';

header("Content-Type: application/json");

session_start();

try {
    $client = google_client(); // loads credentials from DB

    // ---------------------------------------------------
    // 🔹 Resolve instance_id (BigCommerce store_hash)
    // ---------------------------------------------------
    $instanceId =
        $_SESSION['instance_id']
        ?? $_GET['instanceId']
        ?? null;

    if (!$instanceId) {
        echo json_encode([
            'success' => false,
            'error'   => 'Missing instance ID'
        ]);
        exit;
    }

    // ---------------------------------------------------
    // 🔹 Pass instance_id via OAuth state
    // ---------------------------------------------------
$_SESSION['google_oauth_instance'] = $instanceId;
$client->setState($instanceId);

    // ---------------------------------------------------
    // Google OAuth Settings
    // ---------------------------------------------------
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setApprovalPrompt('force');
    $client->setIncludeGrantedScopes(true);

    $authUrl = $client->createAuthUrl();

    echo json_encode([
        'success' => true,
        'authUrl' => $authUrl
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
