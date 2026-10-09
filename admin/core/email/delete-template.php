
<?php
// email/delete-template.php
require_once __DIR__ . '/../../../includes/config.php'; // Include database connection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $templateId = $data['id'];

    // Check if template exists before attempting deletion
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_templates WHERE id = :id");
    $stmt->execute(['id' => $templateId]);
    if ($stmt->fetchColumn() == 0) {
        echo json_encode(['success' => false, 'message' => 'Template not found']);
        exit;
    }

    // Perform deletion (use prepared statements to prevent SQL injection)
    $stmt = $pdo->prepare("DELETE FROM email_templates WHERE id = :id");
    $stmt->execute(['id' => $templateId]);

    // Return success response
    echo json_encode(['success' => true]);
}
