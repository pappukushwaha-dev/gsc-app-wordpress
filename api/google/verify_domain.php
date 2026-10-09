<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/google/get_account.php';
require_once __DIR__ . '/../../includes/google/token_manager.php';

global $pdo;
/* ==========================================================
   INPUT
========================================================== */
$input = json_decode(file_get_contents('php://input'), true) ?: [];

$instanceId =
    $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? ($input['instanceId'] ?? null);

if (!$instanceId) {
    echo json_encode([
        'success' => false,
        'error'   => 'Your session has expired. Please refresh the page and try again.'
    ]);
    exit;
}

/* ==========================================================
   LOAD EXISTING PROPERTY (SINGLE SOURCE OF TRUTH)
========================================================== */
$stmt = $pdo->prepare("
    SELECT site_url, meta_token
    FROM gsc_domain_verifications
    WHERE instance_id = ?
    LIMIT 1
");
$stmt->execute([$instanceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['site_url']) || empty($row['meta_token'])) {
    echo json_encode([
        'success' => false,
        'error'   => 'Verification token not found. Please click "Generate Google Verification Token" first.'
    ]);
    exit;
}

$siteUrl = $row['site_url']; // EXACT URL-prefix
$metaToken = $row['meta_token'];
$metaTag = '<meta name="google-site-verification" content="' . htmlspecialchars($metaToken, ENT_QUOTES, 'UTF-8') . '">';


/* ==========================================================
   GOOGLE ACCOUNT
========================================================== */
$googleAccount = getGoogleAccountByShop($instanceId);
if (!$googleAccount || empty($googleAccount['id'])) {
    echo json_encode([
        'success' => false,
        'error'   => 'Your Google account is not connected. Please go back to Step 1 and connect Google.'
    ]);
    exit;
}

$tokenResult = ensureAccessToken((int)$googleAccount['id'], 120);
if (!$tokenResult['success']) {
    echo json_encode([
        'success' => false,
        'error'   => 'Your Google session expired. Please go back to Step 1, click "Disconnect", then reconnect your Google account.'
    ]);
    exit;
}

$accessToken = $tokenResult['access_token'];

/* ==========================================================
   VERIFY OWNERSHIP (META) — NO CREATION
========================================================== */
$verifyUrl =
    'https://www.googleapis.com/siteVerification/v1/webResource'
    . '?verificationMethod=meta';

$ch = curl_init($verifyUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$accessToken}",
        "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'site' => [
            'type'       => 'SITE',
            'identifier' => $siteUrl
        ]
    ]),
    CURLOPT_TIMEOUT => 20
]);

$response = curl_exec($ch);
$http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($err || $http !== 200) {
    // Try to extract Google's real reason from the response
    $googleReason = '';
    $parsed = json_decode((string)$response, true);
    if (is_array($parsed) && isset($parsed['error']['message'])) {
        $googleReason = (string)$parsed['error']['message'];
    }

    // Classify so the UI can react appropriately.
    $errorCode   = 'verification_failed';
    $userMessage = 'We could not verify your domain. Please make sure the verification token is added to your site, then try again.';

    $reasonLower = strtolower($googleReason);

    if ($err || $http === 0) {
        $errorCode   = 'network_error';
        $userMessage = 'We could not reach Google Search Console. Check your connection and try again in a moment.';
    } elseif (
        str_contains($reasonLower, 'token')
        || str_contains($reasonLower, 'not be found')
        || str_contains($reasonLower, 'not found')
        || str_contains($reasonLower, 'meta tag')
    ) {
        $errorCode   = 'token_not_found';
        $userMessage = 'Google could not find the verification token on your site. Open the instructions, add the token to your Ecwid site, wait a few seconds, then click Verify Domain again.';
    } elseif (
        str_contains($reasonLower, 'reach')
        || str_contains($reasonLower, 'fetch')
        || str_contains($reasonLower, 'timeout')
        || str_contains($reasonLower, 'unavailable')
    ) {
        $errorCode   = 'site_unreachable';
        $userMessage = 'Google could not load your site to check for the verification token. Make sure your site is published and publicly accessible, then try again.';
    } elseif ($http === 401 || $http === 403) {
        $errorCode   = 'google_auth_error';
        $userMessage = 'Your Google session expired. Please go back to Step 1, disconnect Google, then reconnect.';
    }

    echo json_encode([
        'success'    => false,
        'error'      => $userMessage,
        'error_code' => $errorCode,
        'site_url'   => $siteUrl,
        'http'       => $http,
        'debug'      => $googleReason
    ]);
    exit;
}

/* ==========================================================
   SAVE VERIFIED STATUS
========================================================== */
$stmt = $pdo->prepare("
    UPDATE gsc_domain_verifications
    SET
        verification_status = 'verified',
        verification_method = 'meta',
        meta_tag = ?,
        updated_at = NOW()
    WHERE instance_id = ?
");
$stmt->execute([$metaTag, $instanceId]);

require_once __DIR__ . '/../../includes/google/maybe_start_backfill.php';
gsc_maybe_start_backfill($pdo, (string)$instanceId);

echo json_encode([
    'success'       => true,
    'verified_site' => $siteUrl
]);
exit;
