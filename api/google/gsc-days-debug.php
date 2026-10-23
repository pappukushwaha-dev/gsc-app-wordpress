<?php
/**
 * TEMP DEBUG (remove after the data-parity investigation):
 * GET ?instance_id=...&days=90|28|365
 * Returns the day-by-day rows exactly as get_gsc_report.php's date
 * logic computes them (same window, same dataState), so they can be
 * compared 1:1 with the GSC UI's EXPORT CSV.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED);

session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/credentials.php';

$instanceId = $_GET['instance_id'] ?? ($_SESSION['instanceid'] ?? null);
$days       = (int) ($_GET['days'] ?? 90);
if (!in_array($days, [1, 7, 28, 90, 180, 365, 486], true)) {
    $days = 90;
}

if (!$instanceId) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'instance_id required']);
    exit;
}

/* Resolve the Google account (same as get_gsc_report) */
$stmt = $pdo->prepare('SELECT access_token, refresh_token FROM google_accounts WHERE instance_id = :id ORDER BY id DESC LIMIT 1');
$stmt->execute([':id' => $instanceId]);
$ga = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ga || empty($ga['access_token'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Google not connected']);
    exit;
}

$access_token = $ga['access_token'];

/* Credentials (google_settings) */
$stmt = $pdo->prepare('SELECT client_id, client_secret, redirect_uri FROM google_settings LIMIT 1');
$stmt->execute();
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Google credentials missing']);
    exit;
}

$client_secret = decrypt($app['client_secret'], ENCRYPTION_KEY);

/* Resolve the property from gsc_domain_verifications */
$stmt = $pdo->prepare('SELECT site_url FROM gsc_domain_verifications WHERE instance_id = :id ORDER BY id DESC LIMIT 1');
$stmt->execute([':id' => $instanceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['site_url'])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No verified property']);
    exit;
}

$rawSite = $row['site_url'];
$clean   = preg_replace('#^https?://#', '', $rawSite);
$clean   = preg_replace('#^www\.#', '', $clean);
$clean   = rtrim($clean, '/');
$variants = array_unique([
    'sc-domain:' . $clean,
    'https://' . $clean . '/',
    'https://www.' . $clean . '/',
]);

/* Same resolution as get_gsc_report.php 6b: sites.list exact match,
   URL-prefix first - so property_used mirrors the real app exactly. */
$ch = curl_init('https://www.googleapis.com/webmasters/v3/sites');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $access_token,
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_CONNECTTIMEOUT => 4,
]);
$sitesResp = curl_exec($ch);
$sitesCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$entries = ($sitesCode >= 200 && $sitesCode < 300)
    ? (json_decode((string) $sitesResp, true)['siteEntry'] ?? [])
    : [];

if ($entries) {
    $normKey = static function (string $url): string {
        $url   = preg_replace('#^https?://#i', '', trim($url));
        $parts = explode('/', (string) $url, 2);
        $host  = preg_replace('#^www\.#i', '', strtolower($parts[0]));
        $path  = isset($parts[1]) ? rtrim('/' . $parts[1], '/') : '';
        return $host . $path;
    };

    $want     = $normKey(rtrim((string) $rawSite, '/'));
    $wantHost = explode('/', $want, 2)[0];
    $match    = null;

    foreach ($entries as $e) {
        $sv = (string) ($e['siteUrl'] ?? '');
        if ($sv === '' || stripos($sv, 'sc-domain:') === 0) continue;
        if ($normKey($sv) === $want) { $match = $e; break; }
    }
    if (!$match) {
        foreach ($entries as $e) {
            $sv = (string) ($e['siteUrl'] ?? '');
            if (stripos($sv, 'sc-domain:') !== 0) continue;
            if (preg_replace('#^www\.#i', '', strtolower(substr($sv, 10))) === $wantHost) { $match = $e; break; }
        }
    }
    if (!$match && count($entries) === 1) { $match = $entries[0]; }
    if ($match) {
        $variants = [(string) ($match['siteUrl'] ?? '')];
    }
}

/* GSC UI preset window: last N days ending today-2, Pacific Time
   (America/Los_Angeles) — matches the GSC UI's data latency. */
$gscTz  = new DateTimeZone('America/Los_Angeles');
$gscNow = new DateTimeImmutable('now', $gscTz);
$end    = $gscNow->modify('-2 days')->format('Y-m-d');
$start  = (new DateTimeImmutable($end, $gscTz))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');

/* Try each property variant until one returns data */
$payloadBase = json_encode([
    'startDate'  => $start,
    'endDate'    => $end,
    'dimensions' => ['date'],
    'dataState'  => 'all',
    'rowLimit'   => 5000,
]);

$rows  = [];
$usedProperty = null;
$http = null;

foreach ($variants as $siteVariant) {
    $url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . urlencode($siteVariant) . '/searchAnalytics/query';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payloadBase,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $http     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http === 401) {
        /* Token stale — the app would refresh it; report it for the debug */
        $rows  = [];
        $error = '401 token stale';
        break;
    }

    $json = json_decode((string) $response, true);
    if ($http === 200 && isset($json['rows'])) {
        $rows  = $json['rows'];
        $usedProperty = $siteVariant;
        break;
    }
}

header('Content-Type: application/json');
echo json_encode([
    'success'       => true,
    'debug'         => true,
    'property_used' => $usedProperty,
    'window'        => ['start' => $start, 'end' => $end, 'days' => $days],
    'http'          => $http,
    'error'         => $error ?? null,
    'total_clicks'  => array_sum(array_column($rows, 'clicks')),
    'total_impressions' => array_sum(array_column($rows, 'impressions')),
    'days_count'    => count($rows),
    'rows'          => $rows,
], JSON_PRETTY_PRINT);
