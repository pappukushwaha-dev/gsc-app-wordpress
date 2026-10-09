<?php
declare(strict_types=1);

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/google/get_account.php';
require_once __DIR__ . '/../../includes/google/refresh_token.php';
require_once __DIR__ . '/../../includes/plan_guard.php';

session_start();

try {
    $input = json_decode(file_get_contents("php://input"), true);
    $instanceId = $input['instanceId'] ?? null;

    if (!$instanceId) {
        throw new Exception("Missing instanceId");
    }

    if (!isPlanAllowedForSchemaFeature($instanceId)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Feature not available for current plan'
        ]);
        exit;
    }

    $google = getGoogleAccountByUser($instanceId);
    if (!$google || empty($google['access_token'])) {
        throw new Exception("Google account not connected");
    }

    // 🔹 Get verified site
    $stmt = $pdo->prepare("
        SELECT site_url 
        FROM gsc_domain_verifications 
        WHERE instance_id = ?
        LIMIT 1
    ");
    $stmt->execute([$instanceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception("Site not found");
    }

    $site = rtrim($row['site_url'], '/');
    $siteUrlForApi = strpos($site, 'http') === 0
        ? $site
        : "sc-domain:$site";

    // 🔹 URLs to inspect (IMPORTANT)
    // In production: pull from sitemap or DB
    $urlsToCheck = [
        "https://$site/404-test",
        "https://$site/random-page",
    ];

    $errors404 = [];

    foreach ($urlsToCheck as $url) {
        $payload = json_encode([
            "inspectionUrl" => $url,
            "siteUrl"       => $siteUrlForApi
        ]);

        $ch = curl_init("https://searchconsole.googleapis.com/v1/urlInspection/index:inspect");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$google['access_token']}",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => $payload
        ]);

        $res = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($res, true);
        $status = $json['inspectionResult']['indexStatusResult']['coverageState'] ?? '';

        if (stripos($status, 'Not found') !== false || stripos($status, '404') !== false) {
            $errors404[] = [
                'url' => $url,
                'status' => $status
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'total_404' => count($errors404),
        'rows' => $errors404
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
