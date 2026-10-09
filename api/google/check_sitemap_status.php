<?php
// api/google/check_sitemap_status.php  (WordPress)
//
// Answers one question before step 3 renders anything: does this property
// already have a sitemap in Search Console?
//
// Step 3 used to decide that from our own database, which is not the
// authority — a user can remove a sitemap in Search Console directly
// and we would never know. Worse, the old page rendered the form first
// and swapped it out a second later, so the submit button was live during
// the swap and a sitemap already on the property could be submitted
// again.
//
// Google is asked first, and neither screen is shown until it answers.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/google/get_account.php';
require_once __DIR__ . '/../../includes/google/token_manager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$instanceId = $_GET['instanceId']
    ?? $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;

$sitemapUrl = $_GET['sitemapUrl'] ?? null;

if (!$instanceId) {
    echo json_encode(['success' => false, 'error' => 'Missing instanceId']);
    exit;
}

$google = getGoogleAccountByInstance($instanceId);

if (!$google || (empty($google['access_token']) && empty($google['refresh_token']))) {
    echo json_encode(['success' => false, 'error' => 'account_needs_reconnect']);
    exit;
}

// A failed refresh is not fatal here. The sited expiry is occasionally
// wrong, and Google answering 401 is a more reliable signal than our own
// bookkeeping — so a stale token is still tried.
$tokenResult = ensureAccessToken((int)$google['id'], 120);
$accessToken = $tokenResult['access_token'] ?? $google['access_token'];

if (empty($accessToken)) {
    echo json_encode(['success' => false, 'error' => 'account_needs_reconnect']);
    exit;
}

$stmt = $pdo->prepare("SELECT site_url FROM gsc_domain_verifications WHERE instance_id = ? LIMIT 1");
$stmt->execute([$instanceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['site_url'])) {
    echo json_encode(['success' => false, 'error' => 'Domain record not found']);
    exit;
}

$raw = trim((string)$row['site_url']);
if (!preg_match('~^https?://~i', $raw)) {
    $raw = 'https://' . $raw;
}
$host = strtolower(preg_replace('/^www\./i', '', parse_url($raw, PHP_URL_HOST) ?? ''));

if ($host === '') {
    echo json_encode(['success' => false, 'error' => 'Invalid site_url']);
    exit;
}

/** GET with a bearer token. */
function gsc_get(string $url, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}", "Accept: application/json"],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'Ecwid-GSC/1.0',
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return ['http' => $http, 'err' => $err, 'json' => is_string($body) ? json_decode($body, true) : null];
}

// ---------------------------------------------------------------------
// STEP 1 — find this shop's property
//
// Matched on host rather than on the raw string. The same site can be
// registered as https://shop.com/, sc-domain:shop.com or with www, and
// all three are the same property to the user. An exact string comparison
// reports "not found" for two of the three.
//
// Unverified registrations are skipped: a sitemap cannot be submitted
// against one, so treating it as the property would only produce a 403
// later with no explanation.
// ---------------------------------------------------------------------
$sitesRes = gsc_get('https://www.googleapis.com/webmasters/v3/sites', $accessToken);

if ($sitesRes['err']) {
    echo json_encode(['success' => false, 'error' => 'network_error']);
    exit;
}

if ($sitesRes['http'] === 401 || $sitesRes['http'] === 403) {
    echo json_encode(['success' => false, 'error' => 'account_needs_reconnect']);
    exit;
}

$property = null;

foreach (($sitesRes['json']['siteEntry'] ?? []) as $site) {
    $url  = $site['siteUrl'] ?? '';
    $perm = $site['permissionLevel'] ?? '';

    if ($perm === 'siteUnverifiedUser') {
        continue;
    }

    if (stripos($url, 'sc-domain:') === 0) {
        $siteHost = strtolower(preg_replace('/^www\./i', '', substr($url, 10)));
    } else {
        $siteHost = strtolower(preg_replace('/^www\./i', '', parse_url($url, PHP_URL_HOST) ?? ''));
    }

    if ($siteHost === $host) {
        $property = $url;
        break;
    }
}

if (!$property) {
    // Not an error. The merchant simply has no verified property yet, and
    // step 3 should show them the form rather than a failure.
    echo json_encode([
        'success'   => true,
        'submitted' => false,
        'reason'    => 'property_not_found'
    ]);
    exit;
}

// ---------------------------------------------------------------------
// STEP 2 — what sitemaps does that property hold?
// ---------------------------------------------------------------------
$smRes = gsc_get(
    'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/sitemaps',
    $accessToken
);

if ($smRes['err']) {
    echo json_encode(['success' => false, 'error' => 'network_error']);
    exit;
}

if ($smRes['http'] === 401 || $smRes['http'] === 403) {
    echo json_encode(['success' => false, 'error' => 'account_needs_reconnect']);
    exit;
}

$sitemaps  = $smRes['json']['sitemap'] ?? [];
$submitted = false;
$matched   = null;

// With a specific URL to look for, match it exactly. Without one, any
// sitemap on the property counts — the user has done the step.
foreach ($sitemaps as $sm) {
    $path = $sm['path'] ?? '';

    if ($sitemapUrl) {
        if (rtrim(strtolower($path), '/') === rtrim(strtolower($sitemapUrl), '/')) {
            $submitted = true;
            $matched   = $sm;
            break;
        }
    } else {
        $submitted = true;
        $matched   = $sm;
        break;
    }
}

// ---------------------------------------------------------------------
// STEP 3 — mirror Google's list into our own table
//
// Google has just told us what it holds and this is the only place in the
// app that asks on a normal page load, so it is written down here. That
// is what fills the Connection Overview card: pages discovered, when
// Google last read the file, how many errors — none of which a submit
// call can ever return.
//
// It runs whether or not $submitted came out true. A false result only
// means the one URL we asked about is absent; any other sitemaps on the
// property are still real.
//
// Wrapped, because this endpoint decides which screen the wizard opens.
// The JS treats a failure as "could not answer" and falls back to the
// submit form — so a database hiccup must never be allowed to show a
// submit form for a sitemap Google already has.
// ---------------------------------------------------------------------
$synced = 0;

try {
    $exists = $pdo->prepare(
        "SELECT COUNT(*) FROM sitemaps WHERE instance_id = ? AND sitemap_url = ?"
    );

    // submission_count is left alone on both paths. It counts how many
    // times this app submitted the sitemap, and a sync is not a
    // submission — bumping it would make the number meaningless.
    $insert = $pdo->prepare("
        INSERT INTO sitemaps
            (instance_id, domain, sitemap_url, last_submitted_at, last_downloaded,
             status, is_index, warnings, errors, discovered_pages,
             last_synced_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
    ");

    $update = $pdo->prepare("
        UPDATE sitemaps SET
            domain            = ?,
            last_submitted_at = ?,
            last_downloaded   = ?,
            status            = ?,
            is_index          = ?,
            warnings          = ?,
            errors            = ?,
            discovered_pages  = ?,
            last_synced_at    = NOW(),
            updated_at        = NOW()
        WHERE instance_id = ? AND sitemap_url = ?
    ");

    $domainForDb = rtrim($raw, '/') . '/';

    foreach ($sitemaps as $sm) {
        $path = trim((string)($sm['path'] ?? ''));
        if ($path === '') {
            continue;
        }

        $errors   = (int)($sm['errors'] ?? 0);
        $warnings = (int)($sm['warnings'] ?? 0);
        $pending  = !empty($sm['isPending']);
        $isIndex  = !empty($sm['isSitemapsIndex']) ? 1 : 0;

        // Google sends RFC 3339; MySQL DATETIME wants Y-m-d H:i:s, and a
        // bad string silently becomes 0000-00-00.
        $toDate = static function (?string $iso): ?string {
            if (!$iso) return null;
            $ts = strtotime($iso);
            return $ts ? date('Y-m-d H:i:s', $ts) : null;
        };

        $lastSubmitted  = $toDate($sm['lastSubmitted']  ?? null);
        $lastDownloaded = $toDate($sm['lastDownloaded'] ?? null);

        // Pending means Google accepted the URL but has not read the file.
        // That is neither success nor failure, and calling it either is
        // what makes users resubmit a sitemap that was already fine.
        $status = $errors > 0
            ? 'Error'
            : (($pending || $lastDownloaded === null) ? 'Pending' : 'Success');

        // contents[] is split by type. A product sitemap carrying one
        // image per product returns web=N and image=N, so summing every
        // entry would report double the real page count. Search Console
        // counts only the web entries as discovered pages.
        //
        // contents[].indexed is ignored on purpose: Google stopped
        // populating it and always returns 0, which would read as
        // "nothing is indexed".
        $pages = null;
        if (!empty($sm['contents']) && is_array($sm['contents'])) {
            $pages = 0;
            foreach ($sm['contents'] as $c) {
                $type = strtolower((string)($c['type'] ?? ''));
                if ($type === 'image' || $type === 'video') continue;
                $pages += (int)($c['submitted'] ?? 0);
            }
        }

        $exists->execute([$instanceId, $path]);

        if ((int)$exists->fetchColumn() === 0) {
            $insert->execute([
                $instanceId, $domainForDb, $path, $lastSubmitted, $lastDownloaded,
                $status, $isIndex, $warnings, $errors, $pages
            ]);
        } else {
            $update->execute([
                $domainForDb, $lastSubmitted, $lastDownloaded,
                $status, $isIndex, $warnings, $errors, $pages,
                $instanceId, $path
            ]);
        }

        $synced++;
    }
} catch (Throwable $e) {
    error_log('check_sitemap_status.php mirror failed for ' . $instanceId . ': ' . $e->getMessage());
}

echo json_encode([
    'success'   => true,
    'submitted' => $submitted,
    'property'  => $property,
    'matched'   => $matched ? [
        'path'          => $matched['path']          ?? null,
        'lastSubmitted' => $matched['lastSubmitted'] ?? null,
        'isPending'     => $matched['isPending']     ?? null,
        'errors'        => $matched['errors']        ?? null,
        'warnings'      => $matched['warnings']      ?? null,
    ] : null,
    'all_sitemaps' => array_map(static fn($s) => $s['path'] ?? '', $sitemaps),
    'synced'       => $synced,
], JSON_UNESCAPED_SLASHES);
