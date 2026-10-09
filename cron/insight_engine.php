<?php
/**
 * cron/insight_engine.php  (WordPress)
 *
 * Reads the local snapshot tables and produces / updates / closes rows in
 * `insights`. Never calls Google — everything it needs is already sited
 * by snapshot_daily.php and build_ctr_curve.php.
 *
 * Crontab — once a day, and it must run AFTER the snapshot window and
 * after the weekly curve rebuild:
 *
 *   30 6 * * * cd /var/www/html/wordpress/googlesearchconsole \
 *       && /usr/bin/php cron/insight_engine.php >> /var/log/gsc_insights_ecwid.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
global $pdo;

// ---- tuning knobs (move to a config table later for per-plan control) ----
//
// The numbers the Wix build settled on after its first real backfill, kept
// unchanged here on purpose. There is no Ecwid data to tune against yet —
// the first test site has two query rows across ninety days. Change them
// when there is evidence to change them against, not before.
const MIN_IMPR_CTR      = 80;    // CTR insight needs this much 30-day volume on a page
const CTR_GAP_MIN       = 0.015; // 1.5 percentage points
const CTR_DAMPING       = 0.6;   // under-promise the estimate
const DROP_MIN_IMPR     = 60;    // baseline impressions before a drop counts
const DROP_PCT          = -0.35; // -35%
const NEWKW_MIN_IMPR    = 25;
const NEWKW_MAX_POS     = 30;
const RESOLVE_STABLE_D  = 14;    // days a metric must hold before auto-resolve
const DISMISS_DAYS      = 30;

$today = date('Y-m-d');

$instances = $pdo->query("
    SELECT DISTINCT instance_id FROM gsc_snapshot_runs
    WHERE status='done' AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
")->fetchAll(PDO::FETCH_COLUMN);

foreach ($instances as $instanceId) {
    // Gate: too little data to say anything honest.
    //
    // Most sites fail this, and that is the correct outcome — on Wix and
    // Shopify roughly three in four are skipped here. Telling someone with
    // nine impressions that their CTR is "12% below expected" would be
    // arithmetic, not insight. What it does mean is that the Action Center
    // needs an empty state that explains itself, rather than a blank panel
    // that looks broken.
    if (!hasEnoughData($pdo, $instanceId)) {
        echo "[engine] {$instanceId}: below minimum volume, skipped\n";
        continue;
    }

    $candidates = array_merge(
        detectCtrOpportunities($pdo, $instanceId),
        detectKeywordDrops($pdo, $instanceId),
        detectNewKeywords($pdo, $instanceId)
        // detectIndexingIssues($pdo, $instanceId)  // see the note on that
        //                                          // function before enabling
    );

    $stats = upsertInsights($pdo, $instanceId, $candidates, $today);
    $stats['resolved'] = autoResolve($pdo, $instanceId, $candidates, $today);

    echo "[engine] {$instanceId}: new={$stats['new']} deduped={$stats['deduped']} "
       . "escalated={$stats['escalated']} resolved={$stats['resolved']}\n";
}

// ==================================================================
// DETECTORS — each returns an array of candidate arrays
// ==================================================================

/**
 * CTR opportunity: a page ranks well but is clicked less than the site's
 * own median CTR for that position.
 */
function detectCtrOpportunities(PDO $pdo, string $instanceId): array
{
    // A page must still be active to be worth optimising. Sold-out
    // products, ended collections and deleted pages keep 90 days of
    // history but stop getting impressions once they are gone — telling a
    // user to "rewrite the title" of a product that no longer exists
    // destroys trust. Impressions in the last 7 days act as a liveness
    // check, and the CTR gap is measured over that recent window so the
    // position reflects where the page ranks NOW, not a stale 31-day
    // average.
    $sql = "
      SELECT page,
             SUM(clicks)      AS clicks,
             SUM(impressions) AS impressions,
             SUM(position * impressions) / NULLIF(SUM(impressions),0) AS position,
             MAX(date)        AS last_seen,
             SUM(CASE WHEN date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                      THEN impressions ELSE 0 END) AS recent_impr
      FROM gsc_page_query_daily
      WHERE instance_id = ?
        AND date >= DATE_SUB(CURDATE(), INTERVAL 31 DAY)
      GROUP BY page_hash, page
      HAVING impressions >= ?
         AND position BETWEEN 1 AND 15
         AND recent_impr >= 5
    ";
    $s = $pdo->prepare($sql);
    $s->execute([$instanceId, MIN_IMPR_CTR]);

    $curve   = loadCtrCurve($pdo, $instanceId);
    $fbCurve = loadCtrCurve($pdo, '__fallback__');  // top-position reference
    $out     = [];

    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $impr     = (int)$r['impressions'];
        $actual   = $impr > 0 ? (int)$r['clicks'] / $impr : 0;
        $band     = max(1, min(20, (int)round((float)$r['position'])));

        // For positions 1-2 the site's own curve is unreliable: on a small
        // site most top-ranked pages are themselves under-clicked, so
        // their average drags the "expected" CTR down to match the very
        // problem we want to flag. Top-position CTR is well established
        // industry-wide, so the global fallback is used there instead.
        $curveToUse = ($band <= 2) ? $fbCurve : $curve;
        $expected   = $curveToUse[$band] ?? null;
        if ($expected === null) continue;

        $gap = $expected - $actual;
        if ($gap < CTR_GAP_MIN) continue;

        // The query window is 31 days and the gap holds monthly, so the
        // recoverable-clicks estimate is impressions × gap, damped to
        // under-promise. Floored at 3: a small site's opportunities are
        // real but modest, and a 3-4 click monthly gain is still worth
        // showing.
        $impact = (int)round($impr * $gap * CTR_DAMPING);
        if ($impact < 3) continue;

        // Before telling anyone to "fix the title", confirm the page is
        // actually live. A page can keep ranking for days after it 404s or
        // gets redirected — Google is slow to drop it. Recommending a title
        // rewrite on a dead page is the fastest way to lose trust.
        $pageStatus = checkPageStatus($pdo, $r['page']);

        if ($pageStatus === 'dead') {
            // The page is gone but still pulling impressions — that traffic
            // is being wasted on a 404. More urgent than a CTR tweak.
            $out[] = [
                'type'      => 'broken',
                'dedup_key' => 'broken:' . $r['page'],
                'entity'    => $r['page'],
                'title'     => 'Page is broken but still ranking',
                'impact'    => $impact,
                'what'      => [
                    ['Page status',       '404 — Not Found'],
                    ['Average position',  number_format((float)$r['position'], 1)],
                    ['Impressions (30d)', number_format($impr)],
                    ['Wasted clicks/mo',  '~' . $impact],
                ],
                'why'  => 'This page still ranks in Google but returns a 404 (Not Found) when opened. People are clicking through and landing on an empty page.',
                'rec'  => 'Either restore this page or redirect it to the right page, so this traffic is not wasted.',
                'actions' => [
                    'primary'   => ['label' => 'Open page', 'view' => 'open'],
                    'secondary' => ['label' => 'View queries', 'view' => 'queries'],
                ],
            ];
            continue;
        }

        if ($pageStatus === 'redirect') {
            // Google is still showing the old URL that now redirects. The
            // link equity survives, but the redirect adds a hop and the old
            // URL keeps appearing instead of the destination.
            $out[] = [
                'type'      => 'redirect',
                'dedup_key' => 'redirect:' . $r['page'],
                'entity'    => $r['page'],
                'title'     => 'Google shows an old redirected URL',
                'impact'    => $impact,
                'what'      => [
                    ['Page status',       'Redirect (3xx)'],
                    ['Average position',  number_format((float)$r['position'], 1)],
                    ['Impressions (30d)', number_format($impr)],
                ],
                'why'  => 'Google is still showing this old URL, which now redirects to another page. Google has not picked up the new page yet.',
                'rec'  => 'Add the new page URL to your sitemap and submit it in Search Console so Google replaces the old URL with the new one.',
                'actions' => [
                    'primary'   => ['label' => 'Open page', 'view' => 'open'],
                    'secondary' => null,
                ],
            ];
            continue;
        }

        if ($pageStatus === 'error' || $pageStatus === 'unknown') {
            // Do not guess on a page we could not verify. Skip quietly
            // rather than risk a wrong recommendation.
            //
            // A site behind a password answers 401 to everything, which
            // lands here as 'unknown'. Those sites get no CTR insights,
            // which is right — nothing about a locked site can be verified
            // from outside.
            continue;
        }

        // pageStatus === 'ok' — a genuinely live, under-clicked page.
        $out[] = [
            'type'      => 'ctr',
            'dedup_key' => 'ctr:' . $r['page'],
            'entity'    => $r['page'],
            'title'     => 'Ranks well but under-clicked',
            'impact'    => $impact,
            'what'      => [
                ['Average position',   number_format((float)$r['position'], 1)],
                ['Impressions (30d)',  number_format($impr)],
                ['Actual CTR',         number_format($actual * 100, 1) . '%'],
                ['Expected at this position', number_format($expected * 100, 1) . '%'],
            ],
            'why'  => 'This page ranks well but people are not clicking. The title and description are not compelling enough.',
            'rec'  => 'Rewrite the title and description — include the words people search for and give them a reason to click.',
            'actions' => [
                'primary'   => ['label' => 'Edit title & meta', 'view' => 'meta'],
                'secondary' => ['label' => 'View queries',      'view' => 'queries'],
            ],
        ];
    }
    return $out;
}

/**
 * Keyword drop: compares the last 14 days against the prior 14, then
 * classifies the cause so the recommendation is specific.
 */
function detectKeywordDrops(PDO $pdo, string $instanceId): array
{
    $sql = "
      SELECT query,
        SUM(IF(date >= DATE_SUB(CURDATE(), INTERVAL 17 DAY), impressions, 0)) AS curr_impr,
        SUM(IF(date <  DATE_SUB(CURDATE(), INTERVAL 17 DAY), impressions, 0)) AS prev_impr,
        SUM(IF(date >= DATE_SUB(CURDATE(), INTERVAL 17 DAY), clicks, 0))      AS curr_clicks,
        SUM(IF(date <  DATE_SUB(CURDATE(), INTERVAL 17 DAY), clicks, 0))      AS prev_clicks,
        SUM(IF(date >= DATE_SUB(CURDATE(), INTERVAL 17 DAY), position*impressions, 0))
          / NULLIF(SUM(IF(date >= DATE_SUB(CURDATE(), INTERVAL 17 DAY), impressions, 0)),0) AS curr_pos,
        SUM(IF(date <  DATE_SUB(CURDATE(), INTERVAL 17 DAY), position*impressions, 0))
          / NULLIF(SUM(IF(date <  DATE_SUB(CURDATE(), INTERVAL 17 DAY), impressions, 0)),0) AS prev_pos
      FROM gsc_query_daily
      WHERE instance_id = ?
        AND date >= DATE_SUB(CURDATE(), INTERVAL 31 DAY)
      GROUP BY query_hash, query
      HAVING prev_impr >= ?
    ";
    $s = $pdo->prepare($sql);
    $s->execute([$instanceId, DROP_MIN_IMPR]);

    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $prevI = (int)$r['prev_impr'];
        $currI = (int)$r['curr_impr'];
        $prevC = (int)$r['prev_clicks'];
        $currC = (int)$r['curr_clicks'];
        $prevP = (float)$r['prev_pos'];
        $currP = (float)$r['curr_pos'];

        $imprChange  = ($currI - $prevI) / max($prevI, 1);
        $clickChange = ($currC - $prevC) / max($prevC, 1);
        $posChange   = $currP - $prevP;   // positive = worse

        // ---- classify the cause; each gets a different recommendation ----
        if ($imprChange <= DROP_PCT && $posChange >= 1.5) {
            $cause = 'ranking';
            $why   = 'The ranking has dropped — a competitor has moved above you.';
            $rec   = 'Search this term to see who is ranking above you, then improve your page to beat theirs.';
        } elseif ($imprChange <= DROP_PCT && abs($posChange) < 1.5) {
            $cause = 'demand';
            $why   = 'The ranking is stable but fewer people are searching for it. This is likely seasonal.';
            $rec   = 'Hold off on changes — compare with the same period last year. If it is seasonal, it will recover on its own.';
        } elseif ($clickChange <= DROP_PCT && $imprChange > -0.1 && abs($posChange) < 1.0) {
            $cause = 'serp';
            $why   = 'The ranking is stable but clicks have fallen. Something new in Google is taking the click before you.';
            $rec   = 'Search this term to see what new element appeared above you, then adjust your page to match it.';
        } else {
            continue;
        }

        $impact = (int)round(($currC - $prevC) * 2);  // 14d delta → monthly
        if ($impact >= -10) continue;

        $out[] = [
            'type'      => 'drop',
            'dedup_key' => 'drop:' . $r['query'],
            'entity'    => '"' . $r['query'] . '"',
            'title'     => match ($cause) {
                'ranking' => 'Losing visibility fast',
                'demand'  => 'Search demand falling',
                'serp'    => 'Clicks falling while position holds',
            },
            'impact' => $impact,
            'what'   => [
                ['Average position', number_format($prevP, 1) . ' → ' . number_format($currP, 1)],
                ['Impressions, 14d vs prior', ($imprChange >= 0 ? '+' : '') . round($imprChange * 100) . '%'],
                ['Clicks, 14d vs prior',      ($clickChange >= 0 ? '+' : '') . round($clickChange * 100) . '%'],
                ['Likely cause', ucfirst($cause)],
            ],
            'why' => $why,
            'rec' => $rec,
            'actions' => [
                'primary'   => ['label' => 'View rank history', 'view' => 'rank'],
                'secondary' => null,
            ],
        ];
    }
    return $out;
}

/**
 * New keyword: a query absent from every snapshot older than 28 days.
 *
 * Only trustworthy once a backfill has run. Without history there is
 * nothing to be absent FROM, so every query looks new and the site is
 * flooded with false positives. That is one more reason the backfill hook
 * matters.
 */
function detectNewKeywords(PDO $pdo, string $instanceId): array
{
    $sql = "
      SELECT n.query,
             SUM(n.impressions) AS impressions,
             SUM(n.clicks)      AS clicks,
             SUM(n.position * n.impressions) / NULLIF(SUM(n.impressions),0) AS position
      FROM gsc_query_daily n
      WHERE n.instance_id = ?
        AND n.date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
        AND NOT EXISTS (
            SELECT 1 FROM gsc_query_daily o
            WHERE o.instance_id = n.instance_id
              AND o.query_hash  = n.query_hash
              AND o.date < DATE_SUB(CURDATE(), INTERVAL 28 DAY)
        )
      GROUP BY n.query_hash, n.query
      HAVING impressions >= ? AND position <= ?
    ";
    $s = $pdo->prepare($sql);
    $s->execute([$instanceId, NEWKW_MIN_IMPR, NEWKW_MAX_POS]);

    $curve = loadCtrCurve($pdo, $instanceId);
    $out   = [];

    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pos    = (float)$r['position'];
        $target = max(1, (int)round($pos) - 3);            // realistic 3-place gain
        $impact = (int)round((int)$r['impressions'] * ($curve[$target] ?? 0.02));
        if ($impact < 10) continue;

        $out[] = [
            'type'      => 'newkw',
            'dedup_key' => 'newkw:' . $r['query'],
            'entity'    => '"' . $r['query'] . '"',
            'title'     => $pos <= 15 ? 'New query close to page 1' : 'New query detected',
            'impact'    => $impact,
            'what'      => [
                ['First seen',        'within the last 28 days'],
                ['Average position',  '#' . number_format($pos, 1)],
                ['Impressions (28d)', number_format((int)$r['impressions'])],
                ['Clicks',            number_format((int)$r['clicks'])],
            ],
            'why' => 'Google has started showing you for a new search. It can reach page 1 easily right now.',
            'rec' => 'Search this term to see which page ranks, then add content on that page that answers this search.',
            'actions' => [
                'primary'   => ['label' => 'Keyword details', 'view' => 'keyword'],
                'secondary' => null,
            ],
        ];
    }
    return $out;
}

/**
 * Indexing: reads whatever a URL-inspection rotation has cached.
 *
 * NOT CALLED. Left in place so the logic is not lost, but two things are
 * missing before it can be switched on:
 *
 *   1. url_inspection_cache does not exist in this database. It was not
 *      part of the Action Center migration because nothing reads it yet.
 *   2. There is no cron populating it. Without one this returns nothing
 *      on every run regardless.
 *
 * Also note there is deliberately no "Request Indexing" action — that API
 * does not exist for general pages. The insight inspects and hands off to
 * Search Console.
 */
function detectIndexingIssues(PDO $pdo, string $instanceId): array
{
    $s = $pdo->prepare("
        SELECT inspected_url, response_json
        FROM url_inspection_cache
        WHERE instance_id = ?
          AND fetched_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $s->execute([$instanceId]);

    $bad = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $j     = json_decode((string)$r['response_json'], true);
        $state = $j['index']['coverage_state']
              ?? $j['inspectionResult']['indexStatusResult']['coverageState']
              ?? '';
        if (stripos($state, 'not indexed') !== false || stripos($state, 'soft 404') !== false) {
            $bad[$state][] = $r['inspected_url'];
        }
    }

    $out = [];
    foreach ($bad as $state => $urls) {
        if (count($urls) < 2) continue;
        $out[] = [
            'type'      => 'indexing',
            'dedup_key' => 'indexing:' . md5($state),
            'entity'    => count($urls) . ' URLs · ' . $state,
            'title'     => 'Pages missing from the index',
            'impact'    => -1 * count($urls) * 5,   // placeholder until demand matching lands
            'what'      => [
                ['Affected URLs', (string)count($urls)],
                ['Coverage state', $state],
            ],
            'why' => 'These URLs cannot receive any organic traffic while they stay out of the index.',
            'rec' => 'Inspect the affected URLs and confirm they are reachable, self-canonical, and present in your sitemap.',
            'actions' => [
                'primary'   => ['label' => 'Inspect URLs', 'view' => 'urls'],
                'secondary' => ['label' => 'Open in Search Console', 'view' => 'gsc'],
            ],
        ];
    }
    return $out;
}

// ==================================================================
// DEDUP + LIFECYCLE
// ==================================================================

/**
 * Fit a dedup key into its column without losing uniqueness.
 *
 * dedup_key is varchar(255) and holds "type:entity", where entity is a page
 * URL. A deep category path or a long product slug goes past 251
 * characters routinely — the insert then throws SQLSTATE 22001, and since
 * nothing catches it, the whole cron run ends and every instance after it
 * is skipped.
 *
 * Widening the column would work too, but this cannot be outgrown: the
 * readable head is kept for anyone reading the table by hand, and an md5
 * of the full key is appended so two long URLs sharing a prefix still get
 * different keys. 215 + 1 + 32 = 248, comfortably inside the column.
 *
 * Deterministic, so the same page produces the same key on every run and
 * dedup keeps working.
 */
function clampDedupKey(string $key): string
{
    return mb_strlen($key) <= 255
        ? $key
        : mb_substr($key, 0, 215) . ':' . md5($key);
}

/** entity is varchar(1000); the same reasoning, one column over. */
function clampEntity(string $entity): string
{
    return mb_strlen($entity) <= 1000 ? $entity : mb_substr($entity, 0, 1000);
}

function upsertInsights(PDO $pdo, string $instanceId, array $candidates, string $today): array
{
    $stats = ['new' => 0, 'deduped' => 0, 'escalated' => 0];

    $find = $pdo->prepare("SELECT id, status, severity, impact_clicks, suppress_until
                           FROM insights
                           WHERE instance_id=? AND dedup_key=?
                           ORDER BY id DESC LIMIT 1");

    foreach ($candidates as $c) {
        // Clamped before anything else touches them, so the lookup and the
        // insert can never disagree about what this insight's key is.
        $c['dedup_key'] = clampDedupKey((string)$c['dedup_key']);
        $c['entity']    = clampEntity((string)$c['entity']);

        $sev = severityFor($c['impact']);
        $find->execute([$instanceId, $c['dedup_key']]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);

        // --- dismissed: stay quiet unless the problem got materially worse
        if ($existing && $existing['status'] === 'dismissed') {
            $grew    = abs($c['impact']) > abs((int)$existing['impact_clicks']) * 1.5;
            $expired = $existing['suppress_until'] && $existing['suppress_until'] < $today;
            if (!$grew && !$expired) { $stats['deduped']++; continue; }
            $pdo->prepare("UPDATE insights SET status='new', suppress_until=NULL WHERE id=?")
                ->execute([$existing['id']]);
            logEvent($pdo, (int)$existing['id'], 'dismissed', 'new', 'impact grew past threshold');

            // The row is open again in the database, so the local copy has
            // to say so too.
            //
            // Without this line execution falls straight past the next
            // branch — which only fires for new/read/actioned — and reaches
            // the INSERT below. That tries to create a second card for a
            // dedup_key that now has an open row, collides with
            // uniq_open (instance_id, dedup_key, open_flag), and throws.
            //
            // The throw is not caught anywhere, so it does not just lose
            // one insight: it ends the whole run, and every instance after
            // this one in the loop is skipped silently.
            $existing['status'] = 'new';
        }

        // --- already open: age it, do NOT create a second card
        if ($existing && in_array($existing['status'], ['new', 'read', 'actioned'], true)) {
            $pdo->prepare("UPDATE insights
                           SET occurrences = occurrences + 1,
                               last_seen = ?, impact_clicks = ?, severity = ?,
                               what_json = ?, why_text = ?, rec_text = ?
                           WHERE id = ?")
                ->execute([
                    $today, $c['impact'], $sev,
                    json_encode($c['what']), $c['why'], $c['rec'],
                    $existing['id'],
                ]);
            $stats['deduped']++;
            if ($sev !== $existing['severity']) {
                $stats['escalated']++;
                logEvent($pdo, (int)$existing['id'], null, $existing['status'],
                         "severity {$existing['severity']} → {$sev}");
            }
            continue;
        }

        // --- genuinely new
        $pdo->prepare("INSERT INTO insights
              (instance_id, type, dedup_key, entity, title, severity, impact_clicks,
               what_json, why_text, rec_text, action_json, status, first_seen, last_seen)
              VALUES (?,?,?,?,?,?,?,?,?,?,?, 'new', ?, ?)")
            ->execute([
                $instanceId, $c['type'], $c['dedup_key'], $c['entity'], $c['title'],
                $sev, $c['impact'], json_encode($c['what']), $c['why'], $c['rec'],
                json_encode($c['actions']), $today, $today,
            ]);
        logEvent($pdo, (int)$pdo->lastInsertId(), null, 'new', 'detected');
        $stats['new']++;
    }

    return $stats;
}

/**
 * Anything open that the detectors did NOT re-emit has stopped being
 * true. Close it once it has stayed absent for RESOLVE_STABLE_D days.
 */
function autoResolve(PDO $pdo, string $instanceId, array $candidates, string $today): int
{
    $live = array_column($candidates, 'dedup_key');
    $ph   = $live ? implode(',', array_fill(0, count($live), '?')) : "''";

    $sql = "SELECT id, status, dedup_key FROM insights
            WHERE instance_id = ?
              AND status IN ('new','read','actioned')
              AND last_seen <= DATE_SUB(?, INTERVAL " . RESOLVE_STABLE_D . " DAY)
              AND dedup_key NOT IN ({$ph})";
    $s = $pdo->prepare($sql);
    $s->execute(array_merge([$instanceId, $today], $live));

    $n = 0;
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $note = $r['status'] === 'actioned'
            ? 'Metric recovered after your change. Closed automatically.'
            : 'The condition stopped being true. Closed automatically.';
        $pdo->prepare("UPDATE insights SET status='resolved', resolved_note=? WHERE id=?")
            ->execute([$note, $r['id']]);
        logEvent($pdo, (int)$r['id'], $r['status'], 'resolved', $note);
        $n++;
    }
    return $n;
}

// ==================================================================
// HELPERS
// ==================================================================

/**
 * Check a page's live HTTP status so we never recommend "fix the title"
 * on a page that 404s or has been redirected. Results are cached in
 * page_status_cache for 3 days, so each URL is hit at most once per cycle
 * — the engine runs daily but a page's up/down state rarely flips within
 * a few days.
 *
 * The cache is keyed on the URL hash and NOT on instance_id, so it is
 * shared across every site. Two shops linking the same URL cost one
 * fetch between them.
 *
 * Returns one of: 'ok' (2xx), 'redirect' (3xx), 'dead' (404/410),
 * 'error' (5xx / unreachable), or 'unknown' — which is where a
 * password-protected site lands, since it answers 401 to everything from
 * outside.
 */
function checkPageStatus(PDO $pdo, string $url): string
{
    // 1. cached?
    $c = $pdo->prepare("SELECT status, checked_at FROM page_status_cache
                        WHERE url_hash = ? LIMIT 1");
    $c->execute([md5($url)]);
    $row = $c->fetch(PDO::FETCH_ASSOC);
    if ($row && strtotime($row['checked_at']) > strtotime('-3 days')) {
        return $row['status'];
    }

    // 2. live check — HEAD first, falling back to a GET if HEAD is refused
    $code = httpStatusCode($url, true);
    if ($code === 405 || $code === 501 || $code === 0) {
        $code = httpStatusCode($url, false);
    }

    $status = match (true) {
        $code >= 200 && $code < 300    => 'ok',
        $code >= 300 && $code < 400    => 'redirect',
        $code === 404 || $code === 410 => 'dead',
        $code >= 500                   => 'error',
        default                        => 'unknown',
    };

    // 3. cache it
    $pdo->prepare("INSERT INTO page_status_cache (url_hash, url, http_code, status, checked_at)
                   VALUES (?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE
                     http_code  = VALUES(http_code),
                     status     = VALUES(status),
                     checked_at = VALUES(checked_at)")
        ->execute([md5($url), mb_substr($url, 0, 1000), $code, $status]);

    return $status;
}

function httpStatusCode(string $url, bool $head): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => $head,   // HEAD when true
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,   // we WANT to see the 3xx itself
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT      => 'Ecwid-GSC/1.0 (+status-check)',
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

function severityFor(int $impact): string
{
    $c = abs($impact);
    return $c >= 200 ? 'critical' : ($c >= 100 ? 'high' : ($c >= 40 ? 'medium' : 'low'));
}

function loadCtrCurve(PDO $pdo, string $instanceId): array
{
    $s = $pdo->prepare("SELECT position_band, expected_ctr FROM gsc_ctr_curve WHERE instance_id=?");
    $s->execute([$instanceId]);
    $curve = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $curve[(int)$r['position_band']] = (float)$r['expected_ctr'];
    }

    // Global fallback for thin sites. The array union keeps the site's
    // own bands where it has them and fills the gaps from here — which is
    // why build_ctr_curve.php can safely write only the bands it trusts.
    $fallback = [1=>0.28,2=>0.15,3=>0.11,4=>0.08,5=>0.06,6=>0.05,7=>0.04,8=>0.035,
                 9=>0.03,10=>0.026,11=>0.018,12=>0.015,13=>0.013,14=>0.011,15=>0.01,
                 16=>0.009,17=>0.008,18=>0.007,19=>0.007,20=>0.006];
    return $curve + $fallback;
}

function hasEnoughData(PDO $pdo, string $instanceId): bool
{
    $s = $pdo->prepare("SELECT SUM(impressions), COUNT(DISTINCT date)
                        FROM gsc_query_daily
                        WHERE instance_id=? AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $s->execute([$instanceId]);
    [$impr, $days] = $s->fetch(PDO::FETCH_NUM);

    // 200 impressions across at least 10 active days. Small sites rarely
    // clear a thousand a month, and this is enough for the curve and the
    // detectors to work without turning a handful of impressions into a
    // confident-sounding insight.
    return (int)$impr >= 200 && (int)$days >= 10;
}

function logEvent(PDO $pdo, int $insightId, ?string $from, string $to, ?string $note): void
{
    $pdo->prepare("INSERT INTO insight_events (insight_id, from_status, to_status, note)
                   VALUES (?,?,?,?)")->execute([$insightId, $from, $to, $note]);
}
