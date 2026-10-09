<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';

if (!isset($storeId) || empty($storeId)) {
    http_response_code(400);
    exit('Missing storeId');
}

try {

    /* ============================================
       1. Check If Store Exists
    ============================================ */

    $stmt = $pdo->prepare("
        SELECT id 
        FROM WpSite 
        WHERE instance_id = ? 
        LIMIT 1
    ");
    $stmt->execute([$storeId]);
    $store = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$store) {
        http_response_code(200);
        exit('Store not found');
    }

    /* ============================================
       2. Soft Delete / Deactivate Store
    ============================================ */

    $stmt = $pdo->prepare("
        UPDATE WpSite
        SET 
            is_active = 0,
            uninstalled_at = NOW(),
            access_token = NULL
        WHERE instance_id = ?
    ");

    $stmt->execute([$storeId]);

    /* ============================================
       3. Optional: Mark Profile As Updated
    ============================================ */

    $stmt = $pdo->prepare("
        UPDATE WpSiteProfile
        SET updated_at = NOW()
        WHERE instance_id = ?
    ");

    $stmt->execute([$storeId]);

    http_response_code(200);
    echo "Uninstalled successfully";

} catch (Exception $e) {

    error_log("Uninstall Error: " . $e->getMessage());

    http_response_code(500);
    echo "Server error";
}