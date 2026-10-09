<?php
// Set the content type to JSON
header('Content-Type: application/json');

// Include DB config
require_once __DIR__ . '/../includes/config.php';

try {
    // Fetch documentation with categories
    $stmt = $pdo->query("
        SELECT d.id, d.slug, d.title, d.content, d.sort_order, 
               c.name AS category, c.sort_order AS category_sort
        FROM documentation d
        JOIN documentation_categories c ON d.category_id = c.id
        ORDER BY 
            c.sort_order ASC, 
            d.sort_order ASC
    ");
    
    $allDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by category
    $docsByCategory = [];
    foreach ($allDocs as $doc) {
        $docsByCategory[$doc['category']][] = [
            'id' => $doc['id'],
            'slug' => $doc['slug'],
            'title' => $doc['title'],
            'content' => $doc['content'],
            'sort_order' => $doc['sort_order']
        ];
    }

    // Return the data as a JSON object
    echo json_encode($docsByCategory, JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    // Handle database errors gracefully
    http_response_code(500); // Internal Server Error
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['error' => 'An error occurred while fetching documentation.']);
}
?>
