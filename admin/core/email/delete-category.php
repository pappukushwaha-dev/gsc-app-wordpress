
<?php
// email/delete-category.php
require_once __DIR__ . '/../../../includes/config.php'; // Include database connection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $categoryId = $data['id'];
    
    // Check if category exists before attempting deletion
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_categories WHERE id = :id");
    $stmt->execute(['id' => $categoryId]);
    if ($stmt->fetchColumn() == 0) {
        echo json_encode(['success' => false, 'message' => 'Category not found']);
        exit;
    }

    // Perform deletion (use prepared statements to prevent SQL injection)
    $stmt = $pdo->prepare("DELETE FROM email_categories WHERE id = :id");
    $stmt->execute(['id' => $categoryId]);

    // Return success response
    echo json_encode(['success' => true]);
}
