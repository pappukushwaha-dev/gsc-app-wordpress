<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../includes/config.php';

try {
    // --- Require instance_id ---
    $instanceId = $_GET['instance_id'] ?? null;
    if (!$instanceId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Instance ID is required.']);
        exit;
    }

    // --- Fetch all FAQs for the instance ---
    $stmt = $pdo->prepare("
        SELECT 
            id,
            question,
            answer,
            sort_order,
            updated_date,
            category_id
        FROM WixFaq
        WHERE instance_id = :instanceId
        ORDER BY sort_order ASC, updated_date DESC
    ");
    $stmt->execute([':instanceId' => $instanceId]);

    $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Return a structured JSON object
    echo json_encode([
        'success' => true,
        'count' => count($faqs),
        'data' => $faqs
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}