<?php
/**
 * cron/snapshot_daily.php  (WordPress)
 *
 * Pulls one day of Search Console data per connected site and writes it
 * into gsc_query_daily + gsc_page_query_daily.
 *
 * Run: php cron/snapshot_daily.php            (all sites due now)
 *      php cron/snapshot_daily.php <instance>  (one site, for testing)
 *      php cron/snapshot_daily.php --all       (every site, no stagger; manual catch-up)
 *      php cron/snapshot_daily.php --all --days=25   (also widen the lookback to fill a gap)
 *
 * Crontab — every 15 minutes. The script only picks the sites whose
 * stagger slot has arrived, so load stays flat across the day:
 *
 *   ⁠*⁠/15 * * * * cd /var/www/html/wordpress/googlesearchconsole \
 *       && /usr/bin/php cron/snapshot_daily.php >> /var/log/gsc_snap_ecwid.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/google/get_account.php';
require_once __DIR__ . '/../includes/google/token_manager.php'; // ensureAccessToken()

global $pdo;

const LOOKBACK_DAYS   = 5;     // re-pull last N days — GSC revises data for ~3 days
const GSC_LAG_DAYS    = 3;     // most recent complete day
const PQ_IMPR_FLOOR   = 3;     // skip only true noise (1-2 impressions). Most sites
                               // here are small, so a higher floor was emptying the
                               // whole page table.
const ROW_LIMIT       = 25000; // GSC max per request

$onlyInstance = null;
$runAll       = false;
$lookback     = LOOKBACK_DAYS;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--all') {
        $runAll = true;
    } elseif (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $lookback = max(LOOKBACK_DAYS, (int)$m[1]);
    } elseif ($arg !== '' && $arg[0] !== '-') {
        $onlyInstance = $arg;
    }
}

/**
 * Which column in google_accounts holds the instance id.
 *
 * The Wix app spells it `instanceId`; this codebase reaches the table
 * through getGoogleAccountByInstance() everywhere else, so the name was
 * never pinned down in a query. Detecting it once beats hardcoding a
 * guess that fails silently at 3am — a wrong column name here would make
 * the SELECT below return nothing and the cron would appear to run fine
 * while collecting no data at all.
 */
function gscAccountInstanceColumn(PDO $pdo): string
{
    static $col = null;
    if ($col !== null) {
        return $col;
    }

    $names = $pdo->query("SHOW COLUMNS FROM google_accounts")
                 ->fetchAll(PDO::FETCH_COLUMN, 0);

    foreach (['instanceId', 'instance_id', 'shop', 'shop_domain'] as $candidate) {
        if (in_array($candidate, $names, true)) {
            return $col = $candidate;
        }
    }

    throw new RuntimeException(
        'google_accounts has no recognisable instance column. Found: ' . implode(', ', $names)
    );
}

// When another script (backfill.php) includes this file only to reuse its
// functions, it defines SNAPSHOT_LIB_ONLY first. In that case the
// instance-selection loop below is skipped and the functions alone are
// exposed.
if (!defined('SNAPSHOT_LIB_ONLY')) {

// ------------------------------------------------------------------
// Pick sites that are connected, verified, and due in this slot
// ------------------------------------------------------------------
$accCol = gscAccountInstanceColumn($pdo);

// Two clauses here exist to stop the cron burning its slots on sites it
// can never reach. The Wix app shows what happens without them: 427 of
// its instances have no property Google will talk to, and its snapshot
// retries every one on every run — thousands of calls a day that cannot
// succeed, taking slots from sites that would.
//
// g.connected = 1
//   token_manager.php sets this to 0 the moment a refresh token comes
//   back invalid_grant, which is what happens when a user revokes
//   access or uninstalls. Without this line those keep being asked every
//   fifteen minutes
//   forever, because refresh_token IS NOT NULL is still true — the token
//   is present, it is simply dead.
//
// NOT EXISTS ... 'no_account'
//   That status means there is nothing to pull, ever. 'error' is
//   deliberately left in: a permission problem can be fixed by the
//   user reconnecting, and we want to notice when it is.
$sql = "
    SELECT g.id AS account_id, g.`{$accCol}` AS instance_id, v.site_url
    FROM google_accounts g
    JOIN gsc_domain_verifications v ON v.instance_id = g.`{$accCol}`
    WHERE v.verification_status = 'verified'
      AND g.connected = 1
      AND g.refresh_token IS NOT NULL
      AND NOT EXISTS (
            SELECT 1 FROM gsc_backfill_log b
            WHERE b.instance_id = g.`{$accCol}`
              AND b.status = 'no_account'
          )
";
$params = [];
if ($onlyInstance) {
    $sql .= " AND g.`{$accCol}` = ?";
    $params[] = $onlyInstance;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$instances = $stmt->fetchAll(PDO::FETCH_ASSOC);

$slot = (int)floor(((int)date('H') * 60 + (int)date('i')) / 15);  // 0..95

foreach ($instances as $inst) {
    $instanceId = $inst['instance_id'];

    // Stagger: each site gets one deterministic 15-minute slot per day.
    if (!$onlyInstance && !$runAll) {
        $mySlot = crc32($instanceId) % 96;
        if ($mySlot !== $slot) continue;
    }

    try {
        snapshotInstance($pdo, (int)$inst['account_id'], $instanceId, $inst['site_url'], $lookback);
    } catch (Throwable $e) {
        error_log("[snapshot] {$instanceId} failed: " . $e->getMessage());
    }
}

} // end: !defined('SNAPSHOT_LIB_ONLY')

// ==================================================================

function snapshotInstance(PDO $pdo, int $accountId, string $instanceId, string $siteUrl, int $lookback = LOOKBACK_DAYS): void
{
    // The project's own token manager. It refreshes and persists
    // internally, and returns ['success'=>bool, 'access_token'=>string].
    $tok = ensureAccessToken($accountId);
    if (empty($tok['success']) || empty($tok['access_token'])) {
        throw new RuntimeException('no valid access token: ' . ($tok['error'] ?? 'unknown'));
    }
    $token = $tok['access_token'];

    // Resolve the exact property string GSC has verified for this site
    // (domain vs URL-prefix). Needs the token, so it comes after.
    $property = gscPropertyId($siteUrl, $token);
    echo "[snapshot] {$instanceId} property resolved: {$property}\n";

    for ($back = GSC_LAG_DAYS; $back < GSC_LAG_DAYS + $lookback; $back++) {
        $date = date('Y-m-d', strtotime("-{$back} days"));

        // Idempotency: skip a day already marked done, unless it is inside
        // the revision window (the first 3 lookback days are always
        // re-pulled, because Google keeps revising them).
        if ($back >= GSC_LAG_DAYS + 3 && runIsDone($pdo, $instanceId, $date)) {
            continue;
        }

        markRun($pdo, $instanceId, $date, 'running');

        try {
            $qRows  = fetchGsc($property, $token, $date, ['query']);
            $pqRows = fetchGsc($property, $token, $date, ['page', 'query']);

            siteQueryRows($pdo, $instanceId, $date, $qRows);
            sitePageQueryRows($pdo, $instanceId, $date, $pqRows);

            markRun($pdo, $instanceId, $date, 'done', count($qRows), count($pqRows));
            echo "[snapshot] {$instanceId} {$date}: " . count($qRows) . " q, " . count($pqRows) . " pq\n";
        } catch (Throwable $e) {
            markRun($pdo, $instanceId, $date, 'failed', 0, 0, $e->getMessage());
            throw $e;   // one bad day means the whole site is in trouble; stop here
        }

        usleep(300000); // 0.3s between days — stays well under 1,200 QPM per site
    }
}

/**
 * One Search Analytics call, with pagination.
 *
 * A note on what this can and cannot see. It asks for a single day broken
 * down by query, and Google withholds query-level rows for searches rare
 * enough to identify an individual person — while still counting them in
 * the totals. So a low-traffic site can legitimately show 55 impressions
 * on a dashboard and return zero rows here. That is Google's behaviour,
 * not a fault in this code, and no amount of retrying changes it.
 */
function fetchGsc(string $property, string $token, string $date, array $dimensions): array
{
    $all      = [];
    $startRow = 0;
    $got      = 0;      // initialise so the loop condition is always defined

    do {
        $body = json_encode([
            'startDate'  => $date,
            'endDate'    => $date,
            'dimensions' => $dimensions,
            'rowLimit'   => ROW_LIMIT,
            'startRow'   => $startRow,
            'dataState'  => 'final',
        ]);

        $url = 'https://www.googleapis.com/webmasters/v3/sites/'
             . rawurlencode($property) . '/searchAnalytics/query';

        $attempt = 0;
        retry:
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => 'Ecwid-GSC/1.0',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Only 429 is worth waiting out. The Wix version also slept 30
        // seconds on a 403 and retried, which is what turned a
        // permission-denied site into a run that took half an hour: a
        // 403 here means the account cannot read this property at all,
        // and waiting does not change that.
        if ($code === 429 && $attempt < 1) {
            $attempt++;
            sleep(30);
            goto retry;
        }

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("GSC HTTP {$code}: " . substr((string)$resp, 0, 300));
        }

        $rows = json_decode((string)$resp, true)['rows'] ?? [];
        $all  = array_merge($all, $rows);
        $got  = count($rows);
        $startRow += $got;
    } while ($got === ROW_LIMIT);

    return $all;
}

function siteQueryRows(PDO $pdo, string $instanceId, string $date, array $rows): void
{
    if (!$rows) return;

    $sql = "INSERT INTO gsc_query_daily
              (instance_id, date, query_hash, query, clicks, impressions, position)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              clicks = VALUES(clicks),
              impressions = VALUES(impressions),
              position = VALUES(position)";
    $stmt = $pdo->prepare($sql);

    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $q = (string)($r['keys'][0] ?? '');
        if ($q === '') continue;
        $stmt->execute([
            $instanceId, $date, md5($q), mb_substr($q, 0, 500),
            (int)($r['clicks'] ?? 0),
            (int)($r['impressions'] ?? 0),
            round((float)($r['position'] ?? 0), 2),
        ]);
    }
    $pdo->commit();
}

function sitePageQueryRows(PDO $pdo, string $instanceId, string $date, array $rows): void
{
    if (!$rows) return;

    $sql = "INSERT INTO gsc_page_query_daily
              (instance_id, date, page_hash, query_hash, page, query, clicks, impressions, position)
            VALUES (?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              clicks = VALUES(clicks),
              impressions = VALUES(impressions),
              position = VALUES(position)";
    $stmt = $pdo->prepare($sql);

    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $impr = (int)($r['impressions'] ?? 0);
        if ($impr < PQ_IMPR_FLOOR) continue;          // long-tail noise, skip

        $page = (string)($r['keys'][0] ?? '');
        $q    = (string)($r['keys'][1] ?? '');
        if ($page === '' || $q === '') continue;

        $stmt->execute([
            $instanceId, $date, md5($page), md5($q),
            mb_substr($page, 0, 1000), mb_substr($q, 0, 500),
            (int)($r['clicks'] ?? 0), $impr,
            round((float)($r['position'] ?? 0), 2),
        ]);
    }
    $pdo->commit();
}

// ---------- helpers ----------

/**
 * Resolve the exact Search Console property string for a site.
 *
 * The same site can be registered several ways — https://shop.com/,
 * sc-domain:shop.com, with or without www — and they are all the same
 * property to the user but different strings to Google. Guessing the
 * format is what produces HTTP 400s, so the list is fetched and matched
 * instead.
 *
 * Falls back to the URL-prefix form of the sited URL if the list call
 * fails.
 */
function gscPropertyId(string $siteUrl, string $token): string
{
    $siteUrl = trim($siteUrl);
    $norm    = rtrim($siteUrl, '/');

    $ch = curl_init('https://www.googleapis.com/webmasters/v3/sites');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $entries = ($code === 200)
        ? (json_decode((string)$resp, true)['siteEntry'] ?? [])
        : [];

    // Reduce a URL to "host-without-www + path" so http/https and
    // www/non-www differences never cause a mismatch. The sited site_url
    // is often www-less while GSC holds the canonical https://www... form.
    $key = static function (string $url): string {
        $url   = trim($url);
        $url2  = preg_replace('#^https?://#i', '', $url);
        $parts = explode('/', $url2, 2);
        $host  = preg_replace('#^www\.#i', '', strtolower($parts[0]));
        $path  = isset($parts[1]) ? rtrim('/' . $parts[1], '/') : '';
        return $host . $path;
    };

    $want = $key($norm);

    /* ----------------------------------------------------------
       1) URL-prefix property matching on host+path

       Every candidate is collected before one is chosen, rather
       than returning the first match found.

       An account can hold the same site under BOTH
       http://example.com/ and https://example.com/. They reduce
       to the same key here — which is the point, since the stored
       URL rarely matches Google's exactly — so a first-match-wins
       loop picks whichever Google happened to list first, and that
       order is not stable between calls.

       That is not a cosmetic difference. The http:// property
       usually holds no data at all: Google does not error, it
       returns zero rows, and the caller reports the site as having
       no search traffic. One run of this resolver returned https
       and pulled 5,000 queries a day; the next returned http for
       the same account and reported ninety empty days.

       Preference, highest first:
         1. a scheme match with real permission
         2. https with real permission
         3. any https
         4. anything else that matched

       siteUnverifiedUser is ranked below everything: the property
       is listed but returns nothing, which is the same dead end.
    ---------------------------------------------------------- */
    $wantScheme = preg_match('#^https://#i', $norm) ? 'https' : 'http';

    $best      = null;
    $bestScore = -1;

    foreach ($entries as $e) {
        $sv = (string)($e['siteUrl'] ?? '');
        if ($sv === '' || stripos($sv, 'sc-domain:') === 0) continue;
        if ($key($sv) !== $want) continue;

        $isHttps  = (bool)preg_match('#^https://#i', $sv);
        $scheme   = $isHttps ? 'https' : 'http';
        $canRead  = (($e['permissionLevel'] ?? '') !== 'siteUnverifiedUser');

        $score = 0;
        if ($canRead)                 $score += 4;
        if ($isHttps)                 $score += 2;
        if ($scheme === $wantScheme)  $score += 1;

        if ($score > $bestScore) {
            $bestScore = $score;
            $best      = $sv;
        }
    }

    if ($best !== null) {
        return $best;
    }

    // 2) domain property (sc-domain:example.com) matching the host
    $hostParts = explode('/', preg_replace('#^https?://#i', '', $norm), 2);
    $host = preg_replace('#^www\.#i', '', strtolower($hostParts[0]));
    foreach ($entries as $e) {
        $sv = (string)($e['siteUrl'] ?? '');
        if (stripos($sv, 'sc-domain:') === 0
            && strcasecmp(preg_replace('#^www\.#i', '', substr($sv, 10)), (string)$host) === 0) {
            return $sv;
        }
    }

    // 3) any URL-prefix property this site sits under (path property)
    foreach ($entries as $e) {
        $sv = (string)($e['siteUrl'] ?? '');
        if (stripos($sv, 'sc-domain:') === 0 || $sv === '') continue;
        $svKey = $key($sv);
        if ($svKey !== '' && strpos($want, $svKey) === 0) {
            return $sv;
        }
    }

    // 4) exactly one property visible and nothing matched cleanly — use
    //    it. Almost every site owns one property, so this safely resolves
    //    the common "stored URL differs slightly from GSC" case.
    if (count($entries) === 1) {
        return (string)$entries[0]['siteUrl'];
    }

    // Fallback: URL-prefix form of the sited URL
    return rtrim($siteUrl, '/') . '/';
}

function runIsDone(PDO $pdo, string $instanceId, string $date): bool
{
    $s = $pdo->prepare("SELECT status FROM gsc_snapshot_runs
                        WHERE instance_id=? AND date=? LIMIT 1");
    $s->execute([$instanceId, $date]);
    return $s->fetchColumn() === 'done';
}

function markRun(PDO $pdo, string $instanceId, string $date, string $status,
                 int $q = 0, int $pq = 0, ?string $err = null): void
{
    $sql = "INSERT INTO gsc_snapshot_runs
              (instance_id, date, status, query_rows, pq_rows, error, finished_at)
            VALUES (?,?,?,?,?,?, IF(?='running', NULL, NOW()))
            ON DUPLICATE KEY UPDATE
              status=VALUES(status), query_rows=VALUES(query_rows),
              pq_rows=VALUES(pq_rows), error=VALUES(error),
              finished_at=VALUES(finished_at)";
    $pdo->prepare($sql)->execute([$instanceId, $date, $status, $q, $pq, $err, $status]);
}
