<?php

declare(strict_types=1);

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';

session_start();

/* ===============================
   Resolve instance_id
================================ */
$instanceId =
    $_SESSION['instance_id']
    ?? $_GET['instance_id']
    ?? null;

if (!$instanceId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Missing instance_id'
    ]);
    exit;
}

try {

    /* ===============================
       Validate instance exists
    ================================ */
    $siteStmt = $pdo->prepare("
        SELECT shop_id
        FROM WpSite
        WHERE instance_id = :instance_id
        LIMIT 1
    ");

    $siteStmt->execute([':instance_id' => $instanceId]);
    $site = $siteStmt->fetch(PDO::FETCH_ASSOC);

    if (!$site) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => 'Invalid instance_id'
        ]);
        exit;
    }

    /* ===============================
       Fetch apps
    ================================ */
    $stmt = $pdo->prepare("
        SELECT
            id,
            title,
            subtitle,
            description,
            image_url,
            button_text,
            button_link,
            app_price,
            sort_order
        FROM other_apps
        WHERE status = 'active'
          AND is_index = 'enable'
        ORDER BY sort_order ASC, created_at DESC
    ");
    $stmt->execute();

    $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ===============================
       Normalize links
    ================================ */
    foreach ($apps as &$app) {

        // If button_link exists → keep it
        if (!empty($app['button_link'])) {
            // do nothing
        } else {
            $app['button_link'] = '#';
        }

        // Normalize fields
        $app['title'] = $app['title'] ?? '';
        $app['subtitle'] = $app['subtitle'] ?? '';
        $app['description'] = $app['description'] ?? '';
        $app['image_url'] = $app['image_url'] ?? '';
        $app['button_text'] = $app['button_text'] ?? 'Install';
        $app['app_price'] = $app['app_price'] ?? '0';
    }
    unset($app);

    echo json_encode([
        'success' => true,
        'count'   => count($apps),
        'data'    => $apps
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error'   => 'Database error',
        'details' => $e->getMessage()
    ]);
}
