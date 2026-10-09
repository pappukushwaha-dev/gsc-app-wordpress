<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';

$rawData = file_get_contents('php://input');

if (empty($rawData)) {
    http_response_code(400);
    exit('No payload');
}

$webhookData = json_decode($rawData, true);

if (!$webhookData || !isset($webhookData['eventType'])) {
    http_response_code(400);
    exit('Invalid JSON');
}

$eventType = $webhookData['eventType'];
$storeId   = $webhookData['storeId'] ?? null;

/* ============================================
   1. Log Webhook (Optional Logging Table)
============================================ */

try {

    $stmt = $pdo->prepare("
        INSERT INTO webhook_received (event_type, event_data, http_response_code)
        VALUES (?, ?, 200)
    ");

    $stmt->execute([
        $eventType,
        $rawData
    ]);

} catch (Exception $e) {
    // If logging fails, continue
}

/* ============================================
   2. Handle Events
============================================ */

switch ($eventType) {

    case 'application.installed':

        // You can optionally mark store active
        if ($storeId) {
            $stmt = $pdo->prepare("
                UPDATE WpSite
                SET is_active = 1, updated_at = NOW()
                WHERE instance_id = ?
            ");
            $stmt->execute([$storeId]);
        }

        break;

    case 'application.uninstalled':

        if ($storeId) {

            // Soft deactivate store
            $stmt = $pdo->prepare("
                UPDATE WpSite
                SET is_active = 0,
                    uninstalled_at = NOW()
                WHERE instance_id = ?
            ");

            $stmt->execute([$storeId]);
        }

        break;

    default:
        // Handle other events if needed
        break;
}

/* ============================================
   3. Success Response
============================================ */

http_response_code(200);
echo "OK";
exit;