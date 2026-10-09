<?php
define('API_REQUEST', true);
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/google/get_account.php';
require_once __DIR__ . '/../../includes/google/refresh_token.php';
require_once __DIR__ . '/../../includes/plan_guard.php';

$data = json_decode(file_get_contents("php://input"), true) ?: [];

$instanceId = $data['instanceId'] ?? null;
$regex      = $data['regex'] ?? '';
$dimension  = $data['dimension'] ?? 'query';
$range      = $data['range'] ?? '30days';

if (!$instanceId || !$regex) {
    echo json_encode(['success'=>false,'message'=>'Missing input']);
    exit;
}

if (!isPlanAllowedForSchemaFeature($instanceId)) {
    echo json_encode(['success'=>false,'message'=>'Feature not available for current plan']);
    exit;
}

/* dates */
$end = date('Y-m-d', strtotime('-3 days'));
$start = match($range) {
    '90days' => date('Y-m-d', strtotime('-90 days')),
    '12months' => date('Y-m-d', strtotime('-12 months')),
    default => date('Y-m-d', strtotime('-30 days')),
};

/* Google account */
$google = getGoogleAccountByInstance($instanceId);
if (!$google || empty($google['access_token'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Google account not connected'
    ]);
    exit;
}

$accessToken = $google['access_token'];

/* site */
$stmt = $pdo->prepare("SELECT site_url FROM gsc_domain_verifications WHERE instance_id=?");
$stmt->execute([$instanceId]);
$site = $stmt->fetchColumn();
$rawSite = trim($site);

$clean = preg_replace('#^https?://#', '', $rawSite);
$clean = preg_replace('#^www\.#', '', $clean);
$clean = rtrim($clean, '/');

// Prioritize URL-prefix properties (more common and easier to verify)
$siteVariants = [
    'https://' . $clean . '/',
    'https://www.' . $clean . '/',
    'sc-domain:' . $clean, // Fallback to domain property
];

/* payload */
$payload = json_encode([
    'startDate' => $start,
    'endDate' => $end,
    'dimensions' => [$dimension],
    'dimensionFilterGroups' => [[
        'filters' => [[
            'dimension' => $dimension,
            'operator' => 'includingRegex',
            'expression' => $regex
        ]]
    ]]
]);

/* call */
/* call Google API – try all site variants */
$response = null;
$json = null;

foreach ($siteVariants as $property) {

    $ch = curl_init(
        "https://searchconsole.googleapis.com/webmasters/v3/sites/" .
        urlencode($property) .
        "/searchAnalytics/query"
    );

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => $payload
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($response, true);

    // ✅ SUCCESS → break loop
    if ($json && !isset($json['error']) && $httpCode === 200) {
        break;
    }
    
    // Store last error for better error messages
    if ($json && isset($json['error'])) {
        $lastError = [
            'code' => $httpCode,
            'response' => $response,
            'property' => $property
        ];
    }
}

/* final safety check */
if (!$json || isset($json['error'])) {
    $errorDetails = 'Unknown GSC API error.';
    if (isset($json['error']['message'])) {
        $errorDetails = $json['error']['message'];
        if (isset($lastError['code']) && $lastError['code'] === 403) {
            $errorDetails .= ". This usually means the connected Google account does not have sufficient permission for the site property, or the property type is incorrect. Please check your Google Search Console permissions.";
        }
    }
    echo json_encode([
        'success' => false,
        'message' => 'Google API error: ' . $errorDetails,
        'google_error' => $json['error'] ?? $json,
        'tried_sites' => $siteVariants
    ]);
    exit;
}

$rows = $json['rows'] ?? [];

$totClicks = $totImpr = $totPos = 0;
foreach ($rows as $r){
    $totClicks += $r['clicks'];
    $totImpr += $r['impressions'];
    $totPos += $r['position'];
}

echo json_encode([
    'success'=>true,
    'metrics'=>[
        'clicks'=>$totClicks,
        'impressions'=>$totImpr,
        'ctr'=>$totImpr?round(($totClicks/$totImpr)*100,2):0,
        'position'=>count($rows)?round($totPos/count($rows),1):0
    ],
    'rows'=>array_map(fn($r)=>[
        'keys'=>$r['keys'],
        'clicks'=>$r['clicks'],
        'impressions'=>$r['impressions'],
        'ctr'=>round($r['ctr']*100,2),
        'position'=>round($r['position'],1)
    ],$rows)
]);
