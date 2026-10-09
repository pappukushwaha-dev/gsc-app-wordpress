<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../../includes/config.php';

require_once __DIR__ . '/../../../includes/EmailHelper.php';

header('Content-Type: application/json');



// Get JSON input
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$toEmail      = isset($data['to_email']) ? trim($data['to_email']) : '';
$subject      = isset($data['subject']) ? $data['subject'] : '';
$body         = isset($data['body']) ? $data['body'] : '';
$placeholders = isset($data['placeholders']) && is_array($data['placeholders']) ? $data['placeholders'] : [];

// Validate email
if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid test email address']);
    exit;
}

try {
    // We’ll send a “raw test email” using the current subject & body,
    // not the DB template (so unsaved edits are included).
    sendRawTestEmail($pdo, $toEmail, $subject, $body, $placeholders);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
