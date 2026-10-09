<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/tickets_min.php';

try {
    $raw = file_get_contents('php://input');
    $input = $raw ? json_decode($raw, true) : $_POST;

    $instanceId = trim((string)($input['instance_id'] ?? $_SESSION['instanceid'] ?? ''));
    $ticketId   = isset($input['ticket_id']) ? (int)$input['ticket_id'] : 0;
    $newStatus  = isset($input['new_status']) ? trim((string)$input['new_status']) : '';
    $changedBy  = isset($input['changed_by']) ? trim((string)$input['changed_by']) : ($_SESSION['username'] ?? 'staff');
    $note       = isset($input['note']) ? trim((string)$input['note']) : null;

    if ($instanceId === '' || $ticketId <= 0 || $newStatus === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing required fields: instance_id, ticket_id, new_status']);
        exit;
    }

    // Validate allowed statuses
    $allowed = ['open', 'pending', 'closed'];
    if (!in_array($newStatus, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        exit;
    }

    // Optional: permission check placeholder
    // TODO: ensure current user/session is authorized to change tickets for this instance.
    // Example: if (!user_has_access_to_instance($_SESSION['user_id'], $instanceId)) { ... }

    // Ensure ticket exists and belongs to instance
    $stmt = $pdo->prepare("SELECT id, status FROM tickets WHERE id = :id AND instance_id = :i LIMIT 1");
    $stmt->execute([':id' => $ticketId, ':i' => $instanceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Ticket not found for this instance']);
        exit;
    }

    $oldStatus = $row['status'] ?? null;
    if ($oldStatus === $newStatus) {
        // No-op but return success for idempotency
        echo json_encode(['success' => true, 'message' => 'Status unchanged', 'ticket_id' => $ticketId, 'status' => $newStatus]);
        exit;
    }

    // Use helper to change status and record history
    $res = changeTicketStatusMinimal($pdo, $ticketId, $instanceId, $newStatus, $changedBy, $note);

    if (!is_array($res) || ($res['success'] ?? false) !== true) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $res['error'] ?? 'Failed to change status']);
        exit;
    }

    // Return success and some helpful metadata
    echo json_encode([
        'success'   => true,
        'ticket_id' => $ticketId,
        'old_status'=> $oldStatus,
        'new_status'=> $newStatus
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
}
