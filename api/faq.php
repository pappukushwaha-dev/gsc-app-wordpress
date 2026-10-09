<?php
// Set the content type to JSON
header('Content-Type: application/json');

// Include DB config
require_once __DIR__ . '/../includes/config.php';

// Fetch FAQs with categories (Getting Started always first)
try {
    $stmt = $pdo->query("
        SELECT f.question, f.answer, c.name AS category
        FROM faqs f
        JOIN faq_categories c ON f.category_id = c.id
        ORDER BY
            CASE WHEN c.name = 'Getting Started' THEN 1 ELSE 2 END,
            c.name ASC,
            f.sort_order ASC
    ");
    $allFaqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by category
    $faqsByCategory = [];
    foreach ($allFaqs as $faq) {
        $faqsByCategory[$faq['category']][] = $faq;
    }

    // Return the data as a JSON object
    echo json_encode($faqsByCategory);
} catch (PDOException $e) {
    // Handle database errors gracefully
    http_response_code(500); // Internal Server Error
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['error' => 'An error occurred while fetching data.']);
}
?>