<?php
// api/google/check_property_status.php  (WordPress)
//
// Ported from the Wix app. Two jobs:
//
//   1. Say whether this instance's property exists in Search Console and
//      whether it is already verified.
//   2. If it IS verified, write that to the database — so a user who
//      already owns the property in Google never has to walk through the
//      Theme Customizer, app embed and token steps at all.
//
// That second job is the point. check_property.php only reports; this one
// records. Without it every user goes through the full manual flow
// even when Google already considers them verified.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/google/get_account.php';
require_once __DIR__ . '/../../includes/google/token_manager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// The session key is spelled both ways across this codebase, so both are
// accepted rather than betting on one.
$instanceId = $_GET['instanceId']
    ?? $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;

if (!$instanceId) {
    echo json_encode(['success' => false, 'error' => 'Missing instanceId']);
    exit;
}

$google = getGoogleAccountByInstance($instanceId);
if (
    !$google ||
    (empty($google['access_token']) && empty($google['refresh_token']))
) {
    echo json_encode(['success' => false, 'error' => 'account_needs_reconnect']);
    exit;
}

// Refresh the token if it is close to expiry. If the refresh fails but a
// token is still on file, it is used anyway and Google gets to decide —
// the sited expiry is occasionally wrong, and failing here would send a
// working account to the reconnect screen for nothing.
$accountId   = (int) $google['id'];
$tokenResult = ensureAccessToken($accountId, 120);
$accessToken = $tokenResult['access_token'] ?? $google['access_token'];

if (empty($accessToken)) {
    echo json_encode(['success' => false, 'error' => 'account_needs_reconnect']);
    exit;
}

$stmt = $pdo->prepare("SELECT site_url FROM gsc_domain_verifications WHERE instance_id = ? LIMIT 1");
$stmt->execute([$instanceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['success' => false, 'error' => 'Domain record not found']);
    exit;
}

// Reduce whatever is sited to a bare host.
//
// gsc_domain_verifications.site_url normally holds a full URL, but older
// rows may carry a bare domain, so the scheme is added when missing
// rather than assumed. www is stripped because Google and the user
// rarely agree on it.
$raw = trim((string)$row['site_url']);
if (!preg_match('~^https?://~i', $raw)) {
    $raw = 'https://' . $raw;
}
$host = strtolower(preg_replace('/^www\./i', '', parse_url($raw, PHP_URL_HOST) ?? ''));

if ($host === '') {
    echo json_encode(['success' => false, 'error' => 'Invalid site_url']);
    exit;
}

/**
 * Ask Google for the META verification token for this site.
 *
 * Called only after the property is known to be verified: the user
 * will not need to paste this, but storing it keeps the wizard's token
 * field populated and gives them something to re-add if the app embed is
 * ever switched off.
 */
function fetchMetaVerificationToken(string $accessToken, string $siteUrl): ?array
{
    $payload = json_encode([
        'site' => [
            'type'       => 'SITE',
            'identifier' => $siteUrl
        ],
        'verificationMethod' => 'META'
    ]);

    $ch = curl_init('https://www.googleapis.com/siteVerification/v1/token');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 20,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return null;
    }

    $json = json_decode((string)$response, true);

    if (empty($json['token'])) {
        return null;
    }

    $fullToken = trim($json['token']);

    // Google returns either a whole <meta> tag or a bare
    // "google-site-verification=TOKEN" string depending on the call. Both
    // shapes are handled so the sited token is always just the value.
    if (stripos($fullToken, '<meta') !== false) {
        preg_match('/content=["\']([^"\']+)["\']/i', $fullToken, $matches);
        $token   = $matches[1] ?? '';
        $metaTag = $fullToken;
    } else {
        $token   = trim(str_replace('google-site-verification=', '', $fullToken));
        $metaTag = '<meta name="google-site-verification" content="'
                 . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />';
    }

    return [
        'token'    => $token,
        'meta_tag' => $metaTag
    ];
}

$ch = curl_init("https://www.googleapis.com/webmasters/v3/sites");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer " . $accessToken],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_USERAGENT      => 'Ecwid-GSC/1.0',
]);
$response = curl_exec($ch);
$http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode(['success' => false, 'error' => 'network_error']);
    exit;
}

if ($http === 401 || $http === 403) {
    echo json_encode(['success' => false, 'error' => 'account_needs_reconnect']);
    exit;
}

$json  = json_decode((string)$response, true);
$sites = $json['siteEntry'] ?? [];

// Matching is on host, not on the raw string.
//
// check_property.php compares 'https://' . $domain . '/' against
// siteUrl exactly, which means a domain property (sc-domain:shop.com), a
// www variant, or an http:// registration all come back as "not found" —
// and the user is told to create a property they already have. The
// same site can legitimately be registered in several forms, so all of
// them are reduced to a host before comparing.
$propertyFound = false;
$verified      = false;
$matchedSite   = null;

foreach ($sites as $site) {
    $url  = $site['siteUrl'] ?? '';
    $perm = $site['permissionLevel'] ?? '';

    if (stripos($url, 'sc-domain:') === 0) {
        $siteHost = strtolower(preg_replace('/^www\./i', '', substr($url, 10)));
    } else {
        $siteHost = strtolower(preg_replace('/^www\./i', '', parse_url($url, PHP_URL_HOST) ?? ''));
    }

    if ($siteHost === $host) {
        $propertyFound = true;
        $matchedSite   = ['siteUrl' => $url, 'permissionLevel' => $perm];

        // Keep looking after an unverified match: the user may hold
        // the same site under two registrations, one of them verified.
        if ($perm !== 'siteUnverifiedUser') {
            $verified = true;
            break;
        }
    }
}

if ($verified) {

    $metaToken = null;
    $metaTag   = null;

    $verificationData = fetchMetaVerificationToken($accessToken, $row['site_url']);

    if ($verificationData) {
        $metaToken = $verificationData['token'];
        $metaTag   = $verificationData['meta_tag'];
    }

    $pdo->prepare("
        UPDATE gsc_domain_verifications
        SET verification_status      = 'verified',
            verification_verified_at = NOW(),
            verification_checked_at  = NOW(),
            meta_token               = ?,
            meta_tag                 = ?
        WHERE instance_id = ?
    ")->execute([
        $metaToken,
        $metaTag,
        $instanceId
    ]);

    // The Wix and Shopify apps pull 90 days of history the moment a
    // property becomes verified. The Action Center has not been ported to
    // this app yet, so that file does not exist here — it is checked for
    // rather than required, because a missing include is an uncatchable
    // fatal that would take this endpoint down with it. When the backfill
    // lands, this starts working on its own with no change here.
    $backfillHook = __DIR__ . '/../../includes/google/maybe_start_backfill.php';
    if (is_file($backfillHook)) {
        require_once $backfillHook;
        if (function_exists('gsc_maybe_start_backfill')) {
            try {
                gsc_maybe_start_backfill($pdo, (string)$instanceId);
            } catch (Throwable $e) {
                error_log('check_property_status.php backfill hook failed: ' . $e->getMessage());
            }
        }
    }

} else {

    $pdo->prepare("
        UPDATE gsc_domain_verifications
        SET verification_checked_at = NOW()
        WHERE instance_id = ?
    ")->execute([$instanceId]);
}

echo json_encode([
    'success'        => true,
    'property_found' => $propertyFound,
    'verified'       => $verified,
    'matched_site'   => $matchedSite,
    'host'           => $host
], JSON_UNESCAPED_SLASHES);
