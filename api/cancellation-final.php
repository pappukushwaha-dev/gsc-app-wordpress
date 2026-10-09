<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['instance_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing instance_id']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE cancellation_sessions
        SET final_action = 'cancelled', completed = 1, leave_extra = :note, updated_at = NOW()
        WHERE instance_id = :id
    ");

    $stmt->execute([
        ':note' => $data['final_note'] ?? null,
        ':id'   => $data['instance_id']
    ]);

    echo json_encode(['success' => true, 'message' => 'Cancellation completed']);
    exit;

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
