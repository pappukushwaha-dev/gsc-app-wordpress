<?php
// api/google/fetch_sitemaps.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ----------------------------------
   Resolve instanceId
---------------------------------- */
$instanceId = $_GET['instanceId']
    ?? $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;

if (!$instanceId) {
    echo json_encode([
        'success' => false,
        'error'   => 'Missing instanceId'
    ]);
    exit;
}

/* ----------------------------------
   Fetch sitemap submission logs
---------------------------------- */
$stmt = $pdo->prepare("
    SELECT
        sitemap_url,
        status,
        http_code,
        submitted_at
    FROM sitemap_submission_logs
    WHERE instance_id = ?
    ORDER BY submitted_at DESC
    LIMIT 50
");
$stmt->execute([$instanceId]);

$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ----------------------------------
   Response
---------------------------------- */
echo json_encode([
    'success' => true,
    'logs'    => $logs
], JSON_UNESCAPED_SLASHES);

exit;
