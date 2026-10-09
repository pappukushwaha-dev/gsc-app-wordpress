<?php
// api/google/check_property.php  (WordPress)
//
// Reports whether this instance's property exists in Search Console and
// whether it is verified. Read-only — check_property_status.php is the one
// that also writes the result to the database.
//
// WHY THIS WAS REWRITTEN
//
// The original compared strings exactly:
//
//     $property = rtrim($domain, '/') . '/';
//     if (($site['siteUrl'] ?? '') === $property) { ... }
//
// Google holds the same site under whichever form the user registered it:
//
//     https://example.com/          URL-prefix
//     https://www.example.com/      www variant
//     sc-domain:example.com         domain property
//     http://example.com/           older registration
//
// All four are the same property to the person looking at the screen, and
// an exact comparison finds only one of them. Everything else comes back
// "not found", and the wizard offers to create a property they already
// own.
//
// That is what produced the split screen: the status card said "Found"
// because check_property_status.php matches on host, while the block
// underneath still offered "Create Search Console Property" because this
// file did not. Both were reading the same account and disagreeing.
//
// The matching below is the same logic check_property_status.php uses, so
// the two cannot drift apart again.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/google/get_account.php';
require_once __DIR__ . '/../../includes/google/token_manager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ==========================================================
   INPUT
========================================================== */
// Both session spellings, because this codebase writes the key under
// either name depending on which file set it.
$instanceId = $_GET['instanceId']
    ?? $_GET['instance_id']
    ?? $_SESSION['instanceid']
    ?? $_SESSION['instance_id']
    ?? null;

$domain = $_GET['domain'] ?? null;

if (!$instanceId || !$domain) {
    echo json_encode(['success' => false, 'error' => 'missing_parameters']);
    exit;
}

/* ==========================================================
   NORMALISE THE DOMAIN WE WERE ASKED ABOUT

   The caller may send a bare host or a full URL depending on which
   page it came from, so the scheme is added when missing rather
   than assumed. www is stripped because Google and the user rarely
   agree on it.
========================================================== */
$raw = strtolower(trim((string)$domain));

if (!preg_match('~^https?://~i', $raw)) {
    $raw = 'https://' . $raw;
}

$wantHost = preg_replace('/^www\./i', '', (string)(parse_url($raw, PHP_URL_HOST) ?? ''));

if ($wantHost === '') {
    echo json_encode(['success' => false, 'error' => 'invalid_domain']);
    exit;
}

/* ==========================================================
   GOOGLE ACCOUNT
========================================================== */
$google = getGoogleAccountByInstance($instanceId);

if (!$google || (empty($google['access_token']) && empty($google['refresh_token']))) {
    echo json_encode(['success' => false, 'error' => 'account_needs_reconnect']);
    exit;
}

// A failed refresh is not fatal here. The stored expiry is occasionally
// wrong, and Google answering 401 is a more reliable signal than our own
// bookkeeping — so a stale token is still tried.
$tokenResult = ensureAccessToken((int)$google['id'], 120);
$accessToken = $tokenResult['access_token'] ?? $google['access_token'];

if (empty($accessToken)) {
    echo json_encode(['success' => false, 'error' => 'account_needs_reconnect']);
    exit;
}

/* ==========================================================
   ASK GOOGLE FOR THE PROPERTY LIST
========================================================== */
$ch = curl_init('https://www.googleapis.com/webmasters/v3/sites');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$accessToken}",
        "Accept: application/json",
    ],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_USERAGENT      => 'Ecwid-GSC/1.0',
]);

$response = curl_exec($ch);
$http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode(['success' => false, 'error' => 'network_error', 'message' => $curlErr]);
    exit;
}

if ($http === 401 || $http === 403) {
    echo json_encode(['success' => false, 'error' => 'account_needs_reconnect']);
    exit;
}

$data = json_decode((string)$response, true);

if (!is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'invalid_google_response']);
    exit;
}

// No properties at all is a normal state for a new account, not an error.
if (!isset($data['siteEntry'])) {
    echo json_encode([
        'success'  => true,
        'exists'   => false,
        'verified' => false,
        'message'  => 'No Search Console properties found',
    ]);
    exit;
}

/* ==========================================================
   MATCH ON HOST, NOT ON THE RAW STRING
========================================================== */
$exists      = false;
$verified    = false;
$matchedSite = null;

foreach ($data['siteEntry'] as $site) {
    $url  = (string)($site['siteUrl'] ?? '');
    $perm = (string)($site['permissionLevel'] ?? '');

    if (stripos($url, 'sc-domain:') === 0) {
        // sc-domain:example.com — strip the prefix, then www, same as the
        // URL branch. The Wix original skips the www strip here, so
        // sc-domain:www.example.com never matches example.com.
        $siteHost = preg_replace('/^www\./i', '', strtolower(substr($url, 10)));
    } else {
        $siteHost = preg_replace('/^www\./i', '', strtolower((string)(parse_url($url, PHP_URL_HOST) ?? '')));
    }

    if ($siteHost !== $wantHost) {
        continue;
    }

    $exists      = true;
    $matchedSite = ['siteUrl' => $url, 'permissionLevel' => $perm];

    // Keep looking after an unverified match: the same site can be held
    // under two registrations, only one of which is verified. Stopping at
    // the first would report the account as unverified when it is not.
    if ($perm !== 'siteUnverifiedUser') {
        $verified = true;
        break;
    }
}

echo json_encode([
    'success'      => true,
    'exists'       => $exists,
    'verified'     => $verified,
    'matched_site' => $matchedSite,
    'host'         => $wantHost, 
], JSON_UNESCAPED_SLASHES);