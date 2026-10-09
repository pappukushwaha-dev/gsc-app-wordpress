<?php
// api/delete_ticket.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$instanceId = trim($input['instance_id'] ?? $_SESSION['instanceid'] ?? '');
$ticketId = isset($input['ticket_id']) ? (int)$input['ticket_id'] : 0;

if ($instanceId === '' || $ticketId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']); exit;
}

// Optional: check permission of current user here

try {
    // Delete attachments files from disk (optional, best-effort)
    $attStmt = $pdo->prepare("SELECT stored_path FROM ticket_attachments WHERE ticket_id = :tid AND instance_id = :i");
    $attStmt->execute([':tid' => $ticketId, ':i' => $instanceId]);
    $atts = $attStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($atts as $p) {
        if ($p && is_file($p)) @unlink($p);
    }

    $pdo->beginTransaction();
    // delete attachments rows and messages via FK cascade
    $del = $pdo->prepare("DELETE FROM tickets WHERE id = :id AND instance_id = :i");
    $del->execute([':id' => $ticketId, ':i' => $instanceId]);
    $pdo->commit();

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
