<?php
declare(strict_types=1);

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/credentials.php';

global $pdo;

/* ==========================================================
   INPUT
========================================================== */
$input = json_decode(file_get_contents('php://input'), true) ?: [];

$instanceId = $input['instanceId'] ?? null;
$metaToken  = $input['metaToken'] ?? null;

if (!$instanceId || !$metaToken) {
    echo json_encode([
        'success' => false,
        'error'   => 'missing_parameters'
    ]);
    exit;
}

/* ==========================================================
   LOAD STORE DATA
========================================================== */
$stmt = $pdo->prepare("
    SELECT instance_id, access_token
    FROM WpSite
    WHERE instance_id = ?
    LIMIT 1
");
$stmt->execute([$instanceId]);

$store = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$store || empty($store['instance_id']) || empty($store['access_token'])) {
    echo json_encode([
        'success' => false,
        'error'   => 'store_not_found'
    ]);
    exit;
}

$storeHash   = $store['instance_id'];
$accessToken = $store['access_token'];
$clientId    = $client_id ?? null;

if (!$clientId) {
    echo json_encode([
        'success' => false,
        'error'   => 'missing_client_id'
    ]);
    exit;
}

/* ==========================================================
   PREPARE SCRIPT CONTENT
========================================================== */
$scriptContent = <<<HTML
<script>
(function() {
  if (!document.querySelector('meta[name="google-site-verification"]')) {
    var meta = document.createElement('meta');
    meta.name = 'google-site-verification';
    meta.content = '{$metaToken}';
    document.head.appendChild(meta);
  }
})();
</script>
HTML;

$payload = [
    "name"           => "Google Site Verification",
    "description"    => "Injected by Google Search Console App",
    "html"           => $scriptContent,
    "location"       => "head",
    "visibility"     => "storefront",
    "kind"           => "script_tag",
    "load_method"    => "default",
    "auto_uninstall" => true,
    "enabled"        => true
];

/* ==========================================================
   STEP 1: CHECK IF SCRIPT EXISTS
========================================================== */

$listUrl = "https://api.bigcommerce.com/stores/{$storeHash}/v3/content/scripts";

$ch = curl_init($listUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "X-Auth-Token: {$accessToken}",
        "X-Auth-Client: {$clientId}",
        "Accept: application/json"
    ]
]);

$listResponse = curl_exec($ch);
$listHttp     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($listHttp !== 200) {
    echo json_encode([
        'success' => false,
        'error'   => 'failed_to_fetch_scripts',
        'http'    => $listHttp,
        'response'=> $listResponse
    ]);
    exit;
}

$listDecoded = json_decode($listResponse, true);

$existingUuid = null;

if (!empty($listDecoded['data'])) {
    foreach ($listDecoded['data'] as $script) {
        if ($script['name'] === "Google Site Verification") {
            $existingUuid = $script['uuid'];
            break;
        }
    }
}

/* ==========================================================
   STEP 2: UPDATE IF EXISTS, ELSE CREATE
========================================================== */

if ($existingUuid) {

    // UPDATE
    $apiUrl = "https://api.bigcommerce.com/stores/{$storeHash}/v3/content/scripts/{$existingUuid}";
    $method = "PUT";

} else {

    // CREATE
    $apiUrl = "https://api.bigcommerce.com/stores/{$storeHash}/v3/content/scripts";
    $method = "POST";
}

$ch = curl_init($apiUrl);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => [
        "X-Auth-Token: {$accessToken}",
        "X-Auth-Client: {$clientId}",
        "Content-Type: application/json",
        "Accept: application/json"
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT        => 20
]);

$response = curl_exec($ch);
$http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);

curl_close($ch);

if ($error) {
    echo json_encode([
        'success' => false,
        'error'   => 'curl_error',
        'message' => $error
    ]);
    exit;
}

$decoded = json_decode($response, true);

/* ==========================================================
   SUCCESS RESPONSE
========================================================== */

if (
    ($http === 200 || $http === 201) &&
    isset($decoded['data']['uuid'])
) {
    echo json_encode([
        'success' => true,
        'message' => $existingUuid
            ? 'Meta verification script updated successfully.'
            : 'Meta verification script created successfully.',
        'script_uuid' => $decoded['data']['uuid']
    ]);
    exit;
}

/* ==========================================================
   FAILURE
========================================================== */

echo json_encode([
    'success'  => false,
    'error'    => 'bigcommerce_api_error',
    'http'     => $http,
    'response' => $response
]);
exit;