<?php
// api/google/get_sitemap_children.php  (WordPress)
//
// Lists the sitemaps contained in a sitemap index.
//
// sitemaps.list returns the index as a single entry — the files inside it
// are not included. Passing ?sitemapIndex=<url> returns those children
// instead, which is the same call Search Console's own drill-down makes.
//
// Nothing is written to the database. These are read on demand when a user
// expands an index row, and Google re-reads sitemaps on its own schedule,
// so a stored copy would go stale between syncs for no benefit.
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
    ?? ($_GET['instanceId'] ?? null)
    ?? ($_GET['instance_id'] ?? null)
    ?? ($_SESSION['instanceid'] ?? null)
    ?? ($_SESSION['instance_id'] ?? null);
$sitemapUrl = $data['sitemapUrl'] ?? ($_GET['sitemapUrl'] ?? null);

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
    ]);
    exit;
}
$accessToken = $tokenResult['access_token'];

// ---------------------------------------------------------------------
// RESOLVE THE PROPERTY
//
// The stored site_url is not reliable as a property string: a site saved
// as https://example.com/ can really live under https://www.example.com/,
// and a site on a sub-path is a URL-prefix property rather than sc-domain.
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

// Reduce a URL to "host-without-www + path" so http/https and www
// differences do not cause a mismatch.
$normKey = static function (string $url): string {
    $url   = preg_replace('#^https?://#i', '', trim($url));
    $parts = explode('/', (string)$url, 2);
    $host  = preg_replace('#^www\.#i', '', strtolower($parts[0]));
    $path  = isset($parts[1]) ? rtrim('/' . $parts[1], '/') : '';
    return $host . $path;
};

$sitemapHost = strtolower(preg_replace('#^www\.#i', '',
                  (string)parse_url($sitemapUrl, PHP_URL_HOST)));
$sitemapPath = (string)(parse_url($sitemapUrl, PHP_URL_PATH) ?: '');

$property = null;
$bestPath = -1;

foreach ($entries as $e) {
    $sv = (string)($e['siteUrl'] ?? '');
    if ($sv === '') continue;

    if (stripos($sv, 'sc-domain:') === 0) {
        $candHost = substr($sv, 10);
        $candPath = '/';
    } else {
        $candHost = (string)parse_url($sv, PHP_URL_HOST);
        $candPath = (string)(parse_url($sv, PHP_URL_PATH) ?: '/');
    }

    $candHost = strtolower(preg_replace('#^www\.#i', '', $candHost));
    if ($candHost !== $sitemapHost) continue;

    // a sub-path property only covers sitemaps beneath that path
    if ($candPath !== '/' && stripos($sitemapPath, $candPath) !== 0) continue;

    // the more specific path wins
    if (strlen($candPath) > $bestPath) {
        $bestPath = strlen($candPath);
        $property = $sv;
    }
}

if (!$property && count($entries) === 1) {
    $property = (string)($entries[0]['siteUrl'] ?? '');
}

if (!$property) {
    echo json_encode([
        'success' => false,
        'error'   => 'property_not_found',
        'message' => 'No Search Console property on this account covers that sitemap URL.',
    ]);
    exit;
}

// ---------------------------------------------------------------------
// FETCH THE CHILDREN
// ---------------------------------------------------------------------
$url = 'https://www.googleapis.com/webmasters/v3/sites/'
     . rawurlencode($property)
     . '/sitemaps?sitemapIndex=' . rawurlencode($sitemapUrl);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
$resp    = curl_exec($ch);
$http    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    error_log("get_sitemap_children.php cURL error for {$instanceId}: {$curlErr}");
    echo json_encode(['success' => false, 'error' => 'network_error']);
    exit;
}

if ($http < 200 || $http >= 300) {
    echo json_encode([
        'success'  => false,
        'error'    => 'google_error',
        'http'     => $http,
        'response' => json_decode((string)$resp, true) ?: $resp,
    ]);
    exit;
}

$children = json_decode((string)$resp, true)['sitemap'] ?? [];

// Trim the property prefix off each path so the UI can show
// "/pages-sitemap.xml" rather than the whole URL.
$prefix = rtrim(stripos($property, 'sc-domain:') === 0 ? '' : $property, '/');

$out = [];
foreach ($children as $c) {
    $path = (string)($c['path'] ?? '');
    if ($path === '') continue;

    // contents[] is split by type: a product sitemap that carries one image
    // per product returns web=12 AND image=12. Summing them reported 24 pages
    // for a file that holds 12 — Search Console counts only the web entries
    // as "discovered pages" and lists images and videos separately.
    $pages  = 0;
    $images = 0;
    $videos = 0;

    foreach (($c['contents'] ?? []) as $content) {
        $n    = (int)($content['submitted'] ?? 0);
        $type = strtolower((string)($content['type'] ?? ''));

        if ($type === 'image')      { $images += $n; }
        elseif ($type === 'video')  { $videos += $n; }
        else                        { $pages  += $n; }   // web, news, and anything new
    }

    $short = ($prefix !== '' && str_starts_with($path, $prefix))
        ? substr($path, strlen($prefix))
        : $path;

    $out[] = [
        'path'           => $path,
        'shortPath'      => $short,
        'lastSubmitted'  => $c['lastSubmitted']  ?? null,
        'lastDownloaded' => $c['lastDownloaded'] ?? null,
        'errors'         => (int)($c['errors']   ?? 0),
        'warnings'       => (int)($c['warnings'] ?? 0),
        'submittedUrls'  => $pages,
        'images'         => $images,
        'videos'         => $videos,
        'type'           => $c['type'] ?? null,
    ];
}

echo json_encode([
    'success'  => true,
    'property' => $property,
    'parent'   => $sitemapUrl,
    'total'    => count($out),
    'children' => $out,
], JSON_UNESCAPED_SLASHES);