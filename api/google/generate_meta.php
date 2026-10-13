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
   The explicit instanceId from the request wins - every panel on
   this domain shares one PHP session cookie, so a session left by
   another app must not redirect someone's token to another site.
========================================================== */
$input = json_decode(file_get_contents('php://input'), true) ?: [];

$instanceId =
    ($input['instanceId'] ?? null)
    ?? $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;

$domain = trim((string)($input['domain'] ?? ''));

if (!$instanceId || $domain === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'missing_parameters'
    ]);
    exit;
}

/**
 * Copies the token onto the WpSite row the plugin reads, then nudges
 * the site's sync REST route so the meta tag lands within seconds.
 * Without this the token lived only in gsc_domain_verifications and
 * the plugin kept reporting "waiting for the token".
 */
function gscwp_push_token_to_site(PDO $pdo, string $instanceId, string $token): void
{
    try {
        $upd = $pdo->prepare("
            UPDATE WpSite
            SET verify_token = :token
            WHERE instance_id = :iid AND is_active = 1
        ");
        $upd->execute([':token' => $token, ':iid' => $instanceId]);

        $trig = $pdo->prepare("
            SELECT site_url, sync_secret
            FROM WpSite
            WHERE instance_id = :iid AND is_active = 1
            LIMIT 1
        ");
        $trig->execute([':iid' => $instanceId]);
        $t = $trig->fetch(PDO::FETCH_ASSOC);

        if ($t && !empty($t['sync_secret']) && !empty($t['site_url'])) {
            $ch = curl_init(rtrim((string) $t['site_url'], '/') . '/?rest_route=/gscwp/v1/sync');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'instance_id' => $instanceId,
                    'sync_secret' => (string) $t['sync_secret'],
                ]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Throwable $e) {
        error_log('generate_meta push to site: ' . $e->getMessage());
    }
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
    // self-heal older sites whose token never reached WpSite
    gscwp_push_token_to_site($pdo, $instanceId, (string) $existingToken);
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

// the plugin reads WpSite.verify_token - hand it over right now
gscwp_push_token_to_site($pdo, $instanceId, $metaToken);

/* ==========================================================
   RESPONSE
========================================================== */
echo json_encode([
    'success'   => true,
    'metaToken'=> $metaToken
]);
exit;
