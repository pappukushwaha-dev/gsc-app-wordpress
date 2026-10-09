<?php
ini_set("display_errors", 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/credentials.php';


/* ============================================
   1. Validate Required Params
============================================ */

if (empty($_GET['code']) || empty($_GET['store_id'])) {
    http_response_code(400);
    exit("Missing authorization parameters.");
}

$code     = $_GET['code'];
$store_id = $_GET['store_id'];

/* ============================================
   2. Load App Credentials Dynamically
============================================ */

$stmt = $pdo->prepare("
    SELECT client_id, client_secret, redirect_uri 
    FROM ecwid_settings 
    WHERE id = 1 
    LIMIT 1
");
$stmt->execute();
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    http_response_code(500);
    exit("App credentials not configured.");
}

$client_id     = $app['client_id'];
$client_secret = decrypt($app['client_secret'], ENCRYPTION_KEY);
$redirect_uri  = $app['redirect_uri'];

/* ============================================
   3. Exchange Code For Access Token
============================================ */

$token_url = "https://my.ecwid.com/api/oauth/token";

$postData = [
    'client_id'     => $client_id,
    'client_secret' => $client_secret,
    'code'          => $code,
    'redirect_uri'  => $redirect_uri,
    'grant_type'    => 'authorization_code'
];

$ch = curl_init($token_url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($postData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    $error = curl_error($ch);
    curl_close($ch);
    http_response_code(500);
    exit("cURL error: " . $error);
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code($httpCode);
    exit("Token request failed: " . $response);
}

$token_data = json_decode($response, true);

if (empty($token_data['access_token'])) {
    http_response_code(500);
    exit("Access token not returned.");
}

$access_token = $token_data['access_token'];

/* ============================================
   4. Check If Store Already Exists
============================================ */

$stmt = $pdo->prepare("
    SELECT id 
    FROM WpSite 
    WHERE instance_id = ? 
    LIMIT 1
");
$stmt->execute([$store_id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {

    $update = $pdo->prepare("
        UPDATE WpSite
        SET access_token = ?, 
            updated_at = NOW(), 
            is_active = 1
        WHERE instance_id = ?
    ");

    $update->execute([$access_token, $store_id]);

    $_SESSION['instance_id'] = $store_id;
    $_SESSION['user_id']     = $existing['id'];
    $_SESSION['app']         = 'ecwid';

    header("Location: " . HOST_URL . "setup-wizard.php");
    exit;
}

/* ============================================
   5. Fetch Store Profile
============================================ */

$profileCurl = curl_init("https://app.ecwid.com/api/v3/{$store_id}/profile");

curl_setopt_array($profileCurl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$access_token}"
    ],
    CURLOPT_TIMEOUT => 30
]);

$profileResponse = curl_exec($profileCurl);

if (curl_errno($profileCurl)) {
    $error = curl_error($profileCurl);
    curl_close($profileCurl);
    http_response_code(500);
    exit("Profile fetch error: " . $error);
}

curl_close($profileCurl);

$store_data = json_decode($profileResponse, true);

if (empty($store_data)) {
    http_response_code(500);
    exit("Failed to fetch store profile.");
}

/* ============================================
   6. Extract Required Data
============================================ */

$email      = $store_data['account']['accountEmail'] ?? '';
$shop_name  = $store_data['generalInfo']['storeUrl'] ?? '';
$domain     = parse_url($shop_name, PHP_URL_HOST);
$owner_id   = $store_data['account']['accountId'] ?? '';

/* ============================================
   7. Insert Store Record
============================================ */

$stmt = $pdo->prepare("
    INSERT INTO WpSite
    (
        instance_id,
        shop_id,
        shop_domain,
        domain,
        shop_name,
        event_type,
        app_id,
        access_token,
        shop_owner_id,
        email,
        raw_decoded,
        shop_data,
        is_active
    )
    VALUES (?, ?, ?, ?, ?, 'install', ?, ?, ?, ?, ?, ?, 1)
");

$stmt->execute([
    $store_id,
    $store_id,
    $domain,
    $domain,
    $shop_name,
    $client_id,
    $access_token,
    $owner_id,
    $email,
    json_encode($_GET),
    json_encode($store_data)
]);

$user_id = $pdo->lastInsertId();

/* ============================================
   8. Insert Store Profile
============================================ */

$stmt2 = $pdo->prepare("
    INSERT INTO WpSiteProfile
    (
        instance_id,
        email,
        website_url
    )
    VALUES (?, ?, ?)
");

$stmt2->execute([
    $store_id,
    $email,
    $shop_name
]);

/* ============================================
   9. Session + Redirect
============================================ */

$_SESSION['instance_id'] = $store_id;
$_SESSION['user_id']     = $user_id;
$_SESSION['app']         = 'ecwid';

header("Location: " . HOST_URL . "setup-wizard.php");
exit;