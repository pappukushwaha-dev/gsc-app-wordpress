<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/tickets_min.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$instanceId = trim($input['instance_id'] ?? $_SESSION['instanceid'] ?? '');
$ticketId = isset($input['ticket_id']) ? (int)$input['ticket_id'] : 0;
$message = trim($input['message'] ?? '');
$isInternal = !empty($input['is_internal']) ? 1 : 0;

if ($instanceId === '' || $ticketId <= 0 || $message === '') {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$res = postTicketMessageMinimal($pdo, [
    'instance_id' => $instanceId,
    'ticket_id' => $ticketId,
    'message' => $message,
    'is_internal' => $isInternal
]);

echo json_encode($res);
