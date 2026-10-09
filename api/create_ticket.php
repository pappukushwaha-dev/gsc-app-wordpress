<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/tickets_min.php';

// read body (JSON)
$input = json_decode(file_get_contents('php://input'), true) ?: [];

$instanceId = trim($input['instance_id'] ?? $_SESSION['instanceid'] ?? '');
$subject = trim($input['subject'] ?? '');

if ($instanceId === '' || $subject === '') {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$res = createTicketMinimal($pdo, ['instance_id' => $instanceId, 'subject' => $subject]);
echo json_encode($res);
