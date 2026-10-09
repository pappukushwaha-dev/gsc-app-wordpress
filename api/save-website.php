<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

session_start();

header("Content-Type: application/json");

$instanceId = $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;;

if (!$instanceId) {
    echo json_encode([
        'success' => false,
        'error'   => 'Missing instance'
    ]);
    exit;
}

$website = trim($_POST['website'] ?? '');

if (!$website) {
    echo json_encode([
        'success' => false,
        'error'   => 'Website required'
    ]);
    exit;
}

/* ==========================================================
   1️⃣ Normalize URL
========================================================== */

// Add https if missing
if (!preg_match('#^https?://#i', $website)) {
    $website = 'https://' . $website;
}

// Remove trailing slash (except root)
$website = rtrim($website, '/');

// Validate URL
if (!filter_var($website, FILTER_VALIDATE_URL)) {
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid URL format'
    ]);
    exit;
}

$parsed = parse_url($website);

$host = $parsed['host'] ?? null;
$path = $parsed['path'] ?? '';

if (!$host) {
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid URL'
    ]);
    exit;
}

/*
    domain column should contain:
    example.com
    example.com/path
*/
$domain = $host . $path;

try {

    $pdo->beginTransaction();

    /* ==========================================================
       2️⃣ Update WpSite (host + optional path)
    ========================================================== */

    $stmt = $pdo->prepare("
        UPDATE WpSite
        SET domain = ?, 
            shop_domain = ?, 
            updated_at = NOW()
        WHERE instance_id = ?
    ");
    $stmt->execute([
        $domain,
        $domain,
        $instanceId
    ]);

    /* ==========================================================
       3️⃣ Update WpSiteProfile (full URL)
    ========================================================== */

    $stmt = $pdo->prepare("
        UPDATE WpSiteProfile
        SET website_url = ?, 
            updated_at = NOW()
        WHERE instance_id = ?
    ");
    $stmt->execute([
        $website,
        $instanceId
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'domain'  => $domain,
        'website' => $website
    ]);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}