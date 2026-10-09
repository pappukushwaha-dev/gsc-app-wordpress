<?php
require_once __DIR__ . '/init_client.php';
require_once __DIR__ . '/../../includes/google/load_token.php';
require_once __DIR__ . '/../../lib/google/verification.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ==========================================================
   INSTANCE (SHOPIFY) - Match setup-wizard.php order
========================================================== */
$instanceId = $_SESSION['instanceid']
    ?? $_SESSION['instance_id']
    ?? $_GET['instance_id']
    ?? $_GET['instanceId']
    ?? null;

// Clean and validate instance ID
$instanceId = $instanceId ? trim((string)$instanceId) : null;
$userId = getUserIdByInstance($instanceId);

$domain = $_POST['domain'] ?? '';
$method = $_POST['method'] ?? 'META';

try {
    $client = google_init_client($userId);
    $done   = gsc_insert_ownership($client, $domain, $method);

    echo json_encode(['success' => $done]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
