<?php
// api/google/get_sitemap_urls.php  (WordPress)
//
// Lists the URLs inside a sitemap by fetching and parsing the file itself.
//
// The Search Console API cannot do this — sitemaps.list only returns counts,
// and Google's own UI links out to the Pages report rather than listing the
// URLs. The sitemap file is public, so reading it directly is the only way
// to show what is actually in it.
//
// Fetched server-side to avoid CORS, and never stored: the file changes
// whenever the site does, so a cached copy would go stale immediately.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/config.php';

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
$offset     = max(0, (int)($data['offset'] ?? 0));
$limit      = min(500, max(1, (int)($data['limit'] ?? 100)));

if (!$instanceId || !$sitemapUrl) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// ---------------------------------------------------------------------
// AUTHORISE THE REQUEST
//
// This endpoint fetches an arbitrary URL, so it must only ever fetch a
// sitemap that already belongs to the caller's own instance. Without this
// check it would be an open proxy that could be pointed at internal hosts.
// ---------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT 1 FROM sitemaps
    WHERE instance_id = ? AND sitemap_url = ?
    LIMIT 1
");
$stmt->execute([$instanceId, $sitemapUrl]);

if (!$stmt->fetchColumn()) {
    // also allow a child sitemap that sits under the same verified site
    $stmt = $pdo->prepare("SELECT site_url FROM gsc_domain_verifications WHERE instance_id = ? LIMIT 1");
    $stmt->execute([$instanceId]);
    $siteUrl = (string)($stmt->fetchColumn() ?: '');

    $normHost = static function (string $u): string {
        $h = (string)parse_url($u, PHP_URL_HOST);
        if ($h === '') $h = preg_replace('#^(?:sc-domain:)?#i', '', $u);
        return strtolower(preg_replace('#^www\.#i', '', $h));
    };

    if ($siteUrl === '' || $normHost($siteUrl) !== $normHost($sitemapUrl)) {
        echo json_encode(['success' => false, 'error' => 'not_your_sitemap']);
        exit;
    }
}

// Only http(s). Blocks file://, gopher:// and friends.
$scheme = strtolower((string)parse_url($sitemapUrl, PHP_URL_SCHEME));
if (!in_array($scheme, ['http', 'https'], true)) {
    echo json_encode(['success' => false, 'error' => 'invalid_url']);
    exit;
}

// ---------------------------------------------------------------------
// FETCH
// ---------------------------------------------------------------------
$ch = curl_init($sitemapUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_ENCODING       => '',   // handles gzip, including .xml.gz sitemaps
    CURLOPT_USERAGENT      => 'Ecwid-GSC/1.0',
]);
$body    = curl_exec($ch);
$http    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode([
        'success' => false,
        'error'   => 'fetch_failed',
        'message' => 'Could not download the sitemap.',
        'debug'   => $curlErr,
    ]);
    exit;
}

if ($http < 200 || $http >= 300) {
    echo json_encode([
        'success' => false,
        'error'   => 'http_' . $http,
        'http'    => $http,
        'message' => $http === 404
            ? 'The sitemap URL returns 404 — the file does not exist.'
            : "The sitemap URL returned HTTP {$http}.",
    ]);
    exit;
}

// ---------------------------------------------------------------------
// PARSE
// ---------------------------------------------------------------------
libxml_use_internal_errors(true);
$xml = simplexml_load_string((string)$body);

if ($xml === false) {
    echo json_encode([
        'success' => false,
        'error'   => 'invalid_xml',
        'message' => 'The file downloaded but is not valid XML.',
    ]);
    exit;
}

$rootName = $xml->getName();

// A sitemap index lists other sitemaps, not pages. Say so rather than
// returning an empty list.
if ($rootName === 'sitemapindex') {
    $children = [];
    foreach ($xml->sitemap as $sm) {
        $loc = trim((string)$sm->loc);
        if ($loc !== '') $children[] = $loc;
    }

    echo json_encode([
        'success'  => true,
        'isIndex'  => true,
        'total'    => count($children),
        'children' => $children,
        'urls'     => [],
        'message'  => 'This is a sitemap index. Open one of the sitemaps inside it to see its pages.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$all = [];
foreach ($xml->url as $u) {
    $loc = trim((string)$u->loc);
    if ($loc === '') continue;

    $all[] = [
        'loc'      => $loc,
        'lastmod'  => trim((string)$u->lastmod) ?: null,
        'priority' => trim((string)$u->priority) ?: null,
    ];
}

$total = count($all);
$page  = array_slice($all, $offset, $limit);

echo json_encode([
    'success'  => true,
    'isIndex'  => false,
    'total'    => $total,
    'offset'   => $offset,
    'limit'    => $limit,
    'returned' => count($page),
    'urls'     => $page,
], JSON_UNESCAPED_SLASHES);