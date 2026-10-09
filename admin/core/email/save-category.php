<?php
// save-category.php
require_once __DIR__ . '/../../../includes/config.php'; // Include database connection

// Get the raw POST data (JSON body)
$data = json_decode(file_get_contents('php://input'), true);

// Extract the category name and ID (if provided for editing)
$categoryName = $data['name'] ?? '';
$categoryId = $data['id'] ?? null;

// Validate the data
if (empty($categoryName)) {
    echo json_encode(['success' => false, 'message' => 'Category name is required']);
    exit;
}

try {
    if ($categoryId) {
        // Edit existing category
        $query = "UPDATE email_categories SET name = :name WHERE id = :id";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['name' => $categoryName, 'id' => $categoryId]);
    } else {
        // Add new category
        $query = "INSERT INTO email_categories (name) VALUES (:name)";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['name' => $categoryName]);
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
