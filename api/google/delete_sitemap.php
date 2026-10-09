<?php
// api/google/delete_sitemap.php  (WordPress)
//
// Removes one sitemap from Search Console and from the local `sitemaps`
// table. Deleting a sitemap does not deindex anything — Google keeps pages
// it has already crawled. It only takes the file off the property's list.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/google/get_account.php';
require_once __DIR__ . '/../../includes/google/token_manager.php';

// ---------------------------------------------------------------------
// INPUT
// ---------------------------------------------------------------------
$raw  = file_get_contents('php://input');
$data = json_decode((string)$raw, true) ?: [];

// Both spellings, plus the session as a last resort. The wizard JS sends
// instanceId, ported code sends instance_id, and this codebase writes the
// session key under both names in different files — accepting only one is
// how a caller gets 'Missing instanceId' with nothing to explain it.
$instanceId = $data['instanceId']
    ?? $data['instance_id']
    ?? ($_SESSION['instanceid'] ?? null)
    ?? ($_SESSION['instance_id'] ?? null);
$sitemapUrl = $data['sitemapUrl'] ?? null;

if (!$instanceId || !$sitemapUrl) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// ---------------------------------------------------------------------
// ACCOUNT + TOKEN
// ---------------------------------------------------------------------
$google = getGoogleAccountByInstance($instanceId);
if (!$google) {
    echo json_encode(['success' => false, 'error' => 'Google account not connected']);
    exit;
}

$tokenResult = ensureAccessToken((int)$google['id'], 120);
if (empty($tokenResult['success'])) {
    $code = $tokenResult['error'] ?? 'refresh_failed';
    echo json_encode([
        'success' => false,
        'error'   => ($code === 'invalid_grant' || $code === 'account_disconnected')
                     ? 'account_needs_reconnect' : 'token_failed',
        'code'    => $code,
    ]);
    exit;
}
$accessToken = $tokenResult['access_token'];

// ---------------------------------------------------------------------
// RESOLVE THE PROPERTY
//
// The property cannot be guessed from the stored site_url: a site saved as
// https://example.com/ may really live under https://www.example.com/, and
// a Wix sub-path site is a URL-prefix property, not sc-domain. Ask Google
// which properties this token can see and match on host + path.
// ---------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT site_url FROM gsc_domain_verifications WHERE instance_id = ? LIMIT 1");
$stmt->execute([$instanceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['site_url'])) {
    echo json_encode(['success' => false, 'error' => 'Domain not found for this instance']);
    exit;
}

$ch = curl_init('https://www.googleapis.com/webmasters/v3/sites');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
$sitesResp = curl_exec($ch);
$sitesHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($sitesHttp === 401 || $sitesHttp === 403) {
    echo json_encode(['success' => false, 'error' => 'account_needs_reconnect']);
    exit;
}

$entries = json_decode((string)$sitesResp, true)['siteEntry'] ?? [];

$sitemapHost = strtolower(preg_replace('#^www\.#i', '', (string)parse_url($sitemapUrl, PHP_URL_HOST)));
$sitemapPath = (string)(parse_url($sitemapUrl, PHP_URL_PATH) ?: '');

$property   = null;
$permission = null;
$bestRank   = -1;
$bestPath   = -1;

$rankMap = [
    'siteOwner'          => 3,
    'siteFullUser'       => 2,
    'siteRestrictedUser' => 1,
    'siteUnverifiedUser' => 0,
];

foreach ($entries as $entry) {
    $candidate = (string)($entry['siteUrl'] ?? '');
    $perm      = (string)($entry['permissionLevel'] ?? '');
    if ($candidate === '') continue;

    if (stripos($candidate, 'sc-domain:') === 0) {
        $candHost = substr($candidate, 10);
        $candPath = '/';
    } else {
        $candHost = (string)parse_url($candidate, PHP_URL_HOST);
        $candPath = (string)(parse_url($candidate, PHP_URL_PATH) ?: '/');
    }

    $candHost = strtolower(preg_replace('#^www\.#i', '', $candHost));
    if ($candHost === '' || $candHost !== $sitemapHost) continue;

    // A sub-path property only covers sitemaps under that path
    if ($candPath !== '/' && stripos($sitemapPath, $candPath) !== 0) continue;

    $rank = $rankMap[$perm] ?? -1;
    $len  = strlen($candPath);

    // Prefer stronger permission; on a tie prefer the more specific path
    if ($rank > $bestRank || ($rank === $bestRank && $len > $bestPath)) {
        $bestRank   = $rank;
        $bestPath   = $len;
        $property   = $candidate;
        $permission = $perm;
    }
}

if (!$property) {
    echo json_encode([
        'success' => false,
        'error'   => 'property_not_found',
        'message' => 'No Search Console property on this account covers that sitemap URL.',
    ]);
    exit;
}

if (!in_array($permission, ['siteOwner', 'siteFullUser'], true)) {
    echo json_encode([
        'success'    => false,
        'error'      => 'insufficient_permission',
        'message'    => "Your Google account has '{$permission}' access to '{$property}'. Deleting a sitemap needs owner or full access.",
        'property'   => $property,
    ]);
    exit;
}

// ---------------------------------------------------------------------
// DELETE FROM GOOGLE
//
// rawurlencode (not urlencode) for both segments: this is a URL path, where
// a space must become %20 rather than +.
// ---------------------------------------------------------------------
$apiUrl = 'https://www.googleapis.com/webmasters/v3/sites/'
        . rawurlencode($property)
        . '/sitemaps/'
        . rawurlencode($sitemapUrl);

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => 'DELETE',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_USERAGENT      => 'Ecwid-GSC/1.0',
]);
$response = curl_exec($ch);
$http     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    error_log("delete_sitemap.php cURL error for {$instanceId}: {$curlErr}");
    echo json_encode([
        'success' => false,
        'error'   => 'network_error',
        'message' => 'Could not reach Google. Please try again.',
        'debug'   => $curlErr,
    ]);
    exit;
}

// 204 = deleted. 404 = it was not on the property anyway, which for the
// user's purposes is the same outcome, so the local row is cleaned up too.
$deleted = ($http === 204 || $http === 404);

// ---------------------------------------------------------------------
// REMOVE THE LOCAL ROW
//
// Submission history in sitemap_submission_logs is left alone on purpose —
// that is the audit trail of what was attempted and when.
// ---------------------------------------------------------------------
$removedRows = 0;

if ($deleted) {
    try {
        $stmt = $pdo->prepare("DELETE FROM sitemaps WHERE instance_id = ? AND sitemap_url = ?");
        $stmt->execute([$instanceId, $sitemapUrl]);
        $removedRows = $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('delete_sitemap.php DB delete failed: ' . $e->getMessage());
        // Google side is already done; do not fail the request over this
    }
}

echo json_encode([
    'success'     => $deleted,
    'http'        => $http,
    'property'    => $property,
    'sitemapUrl'  => $sitemapUrl,
    'removedRows' => $removedRows,
    'message'     => $deleted
        ? 'Sitemap removed from Search Console.'
        : 'Google refused the delete request.',
    'response'    => $response ? (json_decode((string)$response, true) ?: $response) : null,
], JSON_UNESCAPED_SLASHES);