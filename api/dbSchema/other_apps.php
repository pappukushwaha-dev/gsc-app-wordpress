<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../includes/config.php';

try {
    // Fetch only active apps
    $stmt = $pdo->prepare("
        SELECT 
            id,
            title,
            subtitle,
            description,
            image_url,
            button_text,
            button_link,
            status,
            sort_order,
            created_at,
            updated_at
        FROM other_apps
        WHERE status = 'active'
        ORDER BY sort_order ASC, created_at DESC
    ");
    $stmt->execute();

    $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'count'   => count($apps),
        'data'    => $apps
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'DB error',
        'details' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine()
    ]);
}
