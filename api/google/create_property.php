<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/google/get_account.php';
require_once __DIR__ . '/../../includes/google/token_manager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ==========================================================
   READ INPUT
========================================================== */
$data = json_decode(file_get_contents('php://input'), true) ?: [];

$instanceId =
    $data['instance_id']
    ?? $data['instanceId']
    ?? $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;

$domain = $data['domain'] ?? null;

if (!$instanceId || !$domain) {
    echo json_encode([
        'success' => false,
        'error'   => 'missing_parameters'
    ]);
    exit;
}

/* ==========================================================
   NORMALIZE URL-PREFIX PROPERTY
========================================================== */
// $domain   = strtolower(trim($domain));
// $property = 'https://' . $domain . '/';

// The caller may send a bare host or a full URL depending on which page it
// came from, so the scheme is added only when it is missing. Blindly
// prefixing produced 'https://https://example.com//', which Google rejects
// as an invalid site URL.
$domain = strtolower(trim($domain));

if (!preg_match('~^https?://~i', $domain)) {
    $domain = 'https://' . $domain;
}

// Search Console wants exactly one trailing slash on a URL-prefix property.
$property = rtrim($domain, '/') . '/';

/* ==========================================================
   LOAD GOOGLE ACCOUNT
========================================================== */
$google = getGoogleAccountByInstance($instanceId);

if (!$google || empty($google['access_token'])) {
    echo json_encode([
        'success' => false,
        'error'   => 'We are unable to process your request. Please return to the previous steps, click on "Disconnect this account," and then reconnect your account.'
    ]);
    exit;
}

/* ==========================================================
   ENSURE ACCESS TOKEN
========================================================== */
$accountId   = (int) $google['id'];
$tokenResult = ensureAccessToken($accountId, 120);

$accessToken = $tokenResult['access_token'] ?? $google['access_token'];

if (empty($accessToken)) {
    echo json_encode([
        'success' => false,
        'error'   => 'We are unable to process your request. Please return to the previous steps, click on "Disconnect this account," and then reconnect your account.'
    ]);
    exit;
}

/* ==========================================================
   CREATE URL-PREFIX PROPERTY (SEARCH CONSOLE API)
========================================================== */
$apiUrl = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($property);

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => 'PUT',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$accessToken}",
        "Content-Type: application/json"
    ],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_USERAGENT      => 'Shopify-GSC/1.0'
]);

$response = curl_exec($ch);
$http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

/* ==========================================================
   NETWORK FAILURE
========================================================== */
if ($curlErr) {
    echo json_encode([
        'success' => false,
        'error'   => 'network_error',
        'message' => $curlErr
    ]);
    exit;
}

/* ==========================================================
   AUTH FAILURE
========================================================== */
if ($http === 401 || $http === 403) {
    echo json_encode([
        'success' => false,
        'error'   => 'We are unable to process your request. Please return to the previous steps, click on "Disconnect this account," and then reconnect your account.'
    ]);
    exit;
}

/* ==========================================================
   SUCCESS / ALREADY EXISTS
========================================================== */
if ($http >= 200 && $http < 300) {
    echo json_encode([
        'success'  => true,
        'property' => $property
    ]);
    exit;
}

if ($http === 409) {
    echo json_encode([
        'success'  => true,
        'property' => $property,
        'note'     => 'already_exists'
    ]);
    exit;
}

/* ==========================================================
   FALLBACK ERROR
========================================================== */
echo json_encode([
    'success' => false,
    'error'   => 'google_api_error',
    'http'    => $http,
    'message' => substr((string) $response, 0, 200)
]);
exit;
