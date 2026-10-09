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

$domain = trim((string)($input['domain'] ?? ''));

if (!$instanceId || $domain === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'missing_parameters'
    ]);
    exit;
}

/* ==========================================================
   🔒 REUSE EXISTING TOKEN (CRITICAL FIX)
========================================================== */
$stmt = $pdo->prepare("
    SELECT meta_token
    FROM gsc_domain_verifications
    WHERE instance_id = ?
      AND meta_token IS NOT NULL
    LIMIT 1
");
$stmt->execute([$instanceId]);
$existingToken = $stmt->fetchColumn();

if (!empty($existingToken)) {
    echo json_encode([
        'success'   => true,
        'metaToken'=> $existingToken,
        'note'     => 'existing_token_reused'
    ]);
    exit;
}

/* ==========================================================
   GOOGLE ACCOUNT
========================================================== */
$googleAccount = getGoogleAccountByShop($instanceId);
if (!$googleAccount || empty($googleAccount['id'])) {
    echo json_encode([
        'success' => false,
        'error'   => 'google_not_connected'
    ]);
    exit;
}

$tokenResult = ensureAccessToken((int)$googleAccount['id'], 120);
if (!$tokenResult['success']) {
    echo json_encode([
        'success' => false,
        'error'   => 'google_token_failed'
    ]);
    exit;
}

$accessToken = $tokenResult['access_token'];

/* ==========================================================
   NORMALIZE DOMAIN → URL PREFIX
========================================================== */
$cleanDomain = preg_replace('#^https?://#i', '', $domain);
$cleanDomain = preg_replace('#^www\.#i', '', $cleanDomain);
$siteUrl     = 'https://' . $cleanDomain . '/';

/* ==========================================================
   REQUEST META TOKEN FROM GOOGLE (ONE TIME ONLY)
========================================================== */
$ch = curl_init('https://www.googleapis.com/siteVerification/v1/token');
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
        ],
        'verificationMethod' => 'META'
    ])
]);

$response = curl_exec($ch);
$http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode((string)$response, true);
$metaToken = $data['token'] ?? null;

if ($http !== 200 || !$metaToken) {
    echo json_encode([
        'success' => false,
        'error'   => 'google_token_api_failed',
        'debug'   => $response
    ]);
    exit;
}

/* ==========================================================
   NORMALIZE GOOGLE RESPONSE (RAW TOKEN ONLY)
========================================================== */
$metaToken = trim($metaToken);

// Google may return full <meta> tag — extract content
if (str_contains($metaToken, '<meta')) {
    if (preg_match('/content=["\']([^"\']+)["\']/', $metaToken, $m)) {
        $metaToken = trim($m[1]);
    } else {
        echo json_encode([
            'success' => false,
            'error'   => 'unable_to_parse_google_token'
        ]);
        exit;
    }
}

// Final safety
if (str_contains($metaToken, '<') || str_contains($metaToken, '>')) {
    echo json_encode([
        'success' => false,
        'error'   => 'invalid_token_after_normalization'
    ]);
    exit;
}

/* ==========================================================
   SAVE TOKEN (TOKEN ONLY — NEVER HTML)
========================================================== */
$stmt = $pdo->prepare("
    INSERT INTO gsc_domain_verifications
        (instance_id, site_url, meta_token, verification_status, verification_method)
    VALUES
        (?, ?, ?, 'pending', 'meta')
    ON DUPLICATE KEY UPDATE
        meta_token = VALUES(meta_token),
        updated_at = NOW()
");
$stmt->execute([
    $instanceId,
    $siteUrl,
    $metaToken
]);

/* ==========================================================
   RESPONSE
========================================================== */
echo json_encode([
    'success'   => true,
    'metaToken'=> $metaToken
]);
exit;
