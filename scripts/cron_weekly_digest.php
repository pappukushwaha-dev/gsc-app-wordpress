<?php

declare(strict_types=1);

/**
 * cron_weekly_digest.php  (WordPress)
 *
 * Weekly "Your week in search" digest. Computes every section from the
 * snapshot tables (gsc_query_daily, gsc_page_query_daily, gsc_ctr_curve),
 * then pushes ~105 digest_* fields to the person in Encharge. The Encharge
 * flow "GSC - Weekly Digest" triggers on "Field Changed: Digest Pushed At"
 * and renders weekly-digest-email.html from those fields. No API calls to
 * Google here.
 *
 * Windows (3-day Search Console lag): current = today-9 .. today-3,
 * previous = today-16 .. today-10, "new" lookback = today-44 .. today-10.
 *
 * Push protocol (matches what was verified by hand in Encharge):
 *   - two pushes per site, PUSH_GAP_SECONDS apart, both with full data,
 *     each with a fresh digest_pushed_at timestamp. A person who has never
 *     had the field treats the first push as a creation (no trigger); the
 *     second push is a change and triggers. If both trigger, the flow's
 *     1-hour re-entry cooldown drops the second.
 *   - Encharge sometimes answers {"users":[null]} while a person is being
 *     processed; that is retried.
 *
 * Skips: unverified, no email, current-week impressions < MIN_WEEK_IMPRESSIONS,
 * nothing to report, or digest sent in the last 6 days (digest_last_sent_at).
 *
 * Usage:
 *   php scripts/cron_weekly_digest.php                    all eligible sites
 *   php scripts/cron_weekly_digest.php <instance_id>      one site
 *   php scripts/cron_weekly_digest.php <instance_id> --dry   print the payload, push nothing
 *   php scripts/cron_weekly_digest.php --dry              print payloads for all, push nothing
 *   add --force to ignore the 6-day limit and the impression floor (testing)
 *   add --to=<email> to push a site's digest to a test person instead of the
 *     real user (nothing in the DB is touched, digest_last_sent_at is not set)
 *   add --json (with an instance id) to print that site's digest fields as a
 *     single JSON object and push nothing. This is what
 *     includes/email_payload.php calls to build a stored email payload
 *
 * Crontab (Tuesday night; Encharge sends immediately on push):
 *   0 2 * * 3 cd /var/www/html/wordpress/googlesearchconsole && php scripts/cron_weekly_digest.php >> /var/log/gsc_digest_wordpress.log 2>&1
 *
 * One-time schema:
 *   ALTER TABLE WpSite ADD COLUMN digest_last_sent_at DATETIME NULL DEFAULT NULL;
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);
ini_set('memory_limit', '512M');

$ENCHARGE_WRITE_KEY = 'n4ZJTpZktcyj32fQLBW555m4N';
$APP_PUBLIC_BASE    = 'https://makkpressapps.com/wordpress/googlesearchconsole';
$PLATFORM_SOURCE    = 'WordPress';
$SITE_TABLE         = 'WpSite';

$GSC_LAG_DAYS         = 3;
$MIN_WEEK_IMPRESSIONS = 100;  // skip the digest below this
$MIN_QUERY_IMPR       = 10;   // a query needs this many impressions in the week to be listed
$MAX_RELEVANT_POS     = 30.0; // only rankings on pages 1-3 are worth reporting as moves or new
$MOVE_THRESHOLD       = 3.0;  // positions moved to count as up / down
$STEADY_BAND          = 1.0;  // |delta| below this = held position
$MIN_PAGE_CLICK_DELTA = 3;    // pages on the move: clicks changed by at least this
$MISSED_MIN_IMPR      = 100;  // seen-but-not-clicked: page impressions floor
$MISSED_CTR_RATIO     = 0.5;  // actual CTR below half of expected
$PUSH_GAP_SECONDS     = 5;
$RESEND_AFTER_DAYS    = 6;

$FIELD_PREFIX = 'digest_';

/* Global expected CTR by position band, used when gsc_ctr_curve has no
   trusted band for the store. Rough industry averages. */
$GLOBAL_CTR = [
    1 => 0.28, 2 => 0.15, 3 => 0.11, 4 => 0.08, 5 => 0.07,
    6 => 0.05, 7 => 0.04, 8 => 0.035, 9 => 0.03, 10 => 0.025,
    11 => 0.02, 12 => 0.018, 13 => 0.016, 14 => 0.015, 15 => 0.013,
    16 => 0.012, 17 => 0.011, 18 => 0.010, 19 => 0.009, 20 => 0.008,
];

require_once __DIR__ . '/../includes/config.php';

$logFile      = __DIR__ . '/cron_weekly_digest.log';
$onlyInstance = null;
$dry          = false;
$force        = false;
$overrideTo   = null;
$asJson       = false;
foreach (array_slice($argv ?? [], 1) as $a) {
    if ($a === '--dry') {
        $dry = true;
    } elseif ($a === '--force') {
        $force = true;
    } elseif ($a === '--json') {
        $asJson = true;
    } elseif (strpos($a, '--to=') === 0) {
        $overrideTo = substr($a, 5);
        $force      = true;
    } elseif ($a !== '' && $a[0] !== '-') {
        $onlyInstance = $a;
    }
}

/* --json is the email log builder's mode (includes/email_payload.php,
 * gsc_payload_digest). It prints one site's fields as a single JSON
 * object on stdout and exits without pushing, so it needs an instance id.
 * It implies --force: the row being built exists because the email already
 * went out, and that very send just set digest_last_sent_at, which would
 * otherwise fail the 6-day gate and build nothing. */
if ($asJson) {
    if ($onlyInstance === null || $onlyInstance === '') {
        fwrite(STDERR, "--json needs an instance id\n");
        exit(1);
    }
    $force = true;
}

$curEnd    = date('Y-m-d', strtotime('-' . $GSC_LAG_DAYS . ' days'));
$curStart  = date('Y-m-d', strtotime('-' . ($GSC_LAG_DAYS + 6) . ' days'));
$prevEnd   = date('Y-m-d', strtotime('-' . ($GSC_LAG_DAYS + 7) . ' days'));
$prevStart = date('Y-m-d', strtotime('-' . ($GSC_LAG_DAYS + 13) . ' days'));
$newStart  = date('Y-m-d', strtotime('-' . ($GSC_LAG_DAYS + 41) . ' days'));

/* Encharge turns any date-looking string into a date, so send real dates
   and let the template format them with the Liquid date filter. */
$weekStartIso = date(DATE_ATOM, strtotime($curStart . ' 12:00:00'));
$weekEndIso   = date(DATE_ATOM, strtotime($curEnd . ' 12:00:00'));

/* -----------------------------------
 * Sites
 * ----------------------------------- */
$sql = "
    SELECT s.id AS user_id, s.instance_id, s.email, s.shop_name, s.domain, s.shop_domain, s.digest_last_sent_at
    FROM {$SITE_TABLE} s
    WHERE s.email IS NOT NULL AND s.email <> ''
      AND s.is_active = 1
      AND EXISTS (
          SELECT 1 FROM gsc_domain_verifications v
          WHERE v.instance_id COLLATE utf8mb4_unicode_ci = s.instance_id COLLATE utf8mb4_unicode_ci
            AND v.verification_status = 'verified'
      )
";
$params = [];
if ($onlyInstance) {
    $sql     .= " AND s.instance_id = ?";
    $params[] = $onlyInstance;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Prepared statements */
$queryAgg = $pdo->prepare("
    SELECT query_hash, MAX(query) AS query,
           SUM(clicks) AS clicks, SUM(impressions) AS impr,
           SUM(position * impressions) / NULLIF(SUM(impressions), 0) AS pos,
           COUNT(DISTINCT date) AS days
    FROM gsc_query_daily
    WHERE instance_id = ? AND date BETWEEN ? AND ?
    GROUP BY query_hash
");
$seenHashes = $pdo->prepare("
    SELECT DISTINCT query_hash FROM gsc_query_daily
    WHERE instance_id = ? AND date BETWEEN ? AND ?
");
$pageAgg = $pdo->prepare("
    SELECT page, SUM(clicks) AS clicks, SUM(impressions) AS impr,
           SUM(position * impressions) / NULLIF(SUM(impressions), 0) AS pos
    FROM gsc_page_query_daily
    WHERE instance_id = ? AND date BETWEEN ? AND ?
    GROUP BY page
");
$curveStmt = $pdo->prepare("SELECT position_band, expected_ctr FROM gsc_ctr_curve WHERE instance_id = ?");
$paidStmt  = $pdo->prepare("
    SELECT status, billing_period, expires_on FROM app_subscriptions
    WHERE instance_id = ? ORDER BY id DESC LIMIT 1
");
$trialStmt = $pdo->prepare("
    SELECT status, expires_on FROM app_free_trials
    WHERE instance_id = ? ORDER BY id DESC LIMIT 1
");
$markStmt = $pdo->prepare("UPDATE {$SITE_TABLE} SET digest_last_sent_at = NOW() WHERE id = ?");

$checked = 0;
$pushed  = 0;
$skipped = 0;

foreach ($sites as $s) {
    $checked++;
    $instanceId = (string)$s['instance_id'];
    $email      = trim((string)$s['email']);
    $userId     = (int)$s['user_id'];

    /* Weekly limit */
    if (!$force && !empty($s['digest_last_sent_at'])) {
        $age = (time() - strtotime((string)$s['digest_last_sent_at'])) / 86400;
        if ($age < $RESEND_AFTER_DAYS) {
            logLine($logFile, "{$email} | {$instanceId} | skip | sent " . round($age, 1) . " days ago");
            $skipped++;
            continue;
        }
    }

    $domain = siteDomain($s);
    $brand  = brandTermsLite($s);

    /* ---------- query-level data ---------- */
    $queryAgg->execute([$instanceId, $curStart, $curEnd]);
    $cur = [];
    while ($r = $queryAgg->fetch(PDO::FETCH_ASSOC)) {
        $cur[$r['query_hash']] = $r;
    }
    $queryAgg->execute([$instanceId, $prevStart, $prevEnd]);
    $prev = [];
    while ($r = $queryAgg->fetch(PDO::FETCH_ASSOC)) {
        $prev[$r['query_hash']] = $r;
    }
    $seenHashes->execute([$instanceId, $newStart, $prevEnd]);
    $seen = array_flip($seenHashes->fetchAll(PDO::FETCH_COLUMN));

    $totals = function (array $rows): array {
        $c = 0; $i = 0; $pw = 0.0;
        foreach ($rows as $r) {
            $c  += (int)$r['clicks'];
            $i  += (int)$r['impr'];
            $pw += (float)$r['pos'] * (int)$r['impr'];
        }
        return [
            'clicks' => $c,
            'impr'   => $i,
            'ctr'    => $i > 0 ? $c / $i : 0.0,
            'pos'    => $i > 0 ? $pw / $i : 0.0,
        ];
    };
    $tc = $totals($cur);
    $tp = $totals($prev);

    if (!$force && $tc['impr'] < $MIN_WEEK_IMPRESSIONS) {
        logLine($logFile, "{$email} | {$instanceId} | skip | impressions={$tc['impr']}");
        $skipped++;
        continue;
    }

    /* ---------- classify queries ---------- */
    $up = []; $down = []; $new = []; $steady = []; $page1 = [];
    foreach ($cur as $h => $c) {
        if (isBrand((string)$c['query'], $brand)) {
            continue;
        }
        $cImpr = (int)$c['impr'];
        $cPos  = (float)$c['pos'];
        if (!isset($prev[$h])) {
            if ($cImpr >= $MIN_QUERY_IMPR && $cPos <= $MAX_RELEVANT_POS && !isset($seen[$h])) {
                $new[] = ['query' => $c['query'], 'pos' => $cPos, 'impr' => $cImpr];
            }
            continue;
        }
        $p     = $prev[$h];
        $pPos  = (float)$p['pos'];
        $delta = $pPos - $cPos; // positive = improved
        if ($cImpr < $MIN_QUERY_IMPR && (int)$p['impr'] < $MIN_QUERY_IMPR) {
            continue;
        }
        if (min($pPos, $cPos) > $MAX_RELEVANT_POS) {
            continue; // moved around on page 4+, nobody notices
        }
        $row = ['query' => $c['query'], 'old' => $pPos, 'new' => $cPos, 'delta' => abs($delta), 'impr' => $cImpr, 'clicks' => (int)$c['clicks']];
        if ($delta >= $MOVE_THRESHOLD) {
            $up[] = $row;
            if ($pPos > 12 && $cPos <= 10) {
                $page1[] = $row;
            }
        } elseif ($delta <= -$MOVE_THRESHOLD) {
            $down[] = $row;
        } elseif (abs($delta) < $STEADY_BAND && $cImpr >= $MIN_QUERY_IMPR) {
            $steady[] = $row;
        }
    }
    $byImpr = fn($a, $b) => $b['impr'] <=> $a['impr'];
    usort($up, $byImpr);
    usort($down, $byImpr);
    usort($new, $byImpr);
    usort($page1, $byImpr);
    usort($steady, $byImpr);

    /* ---------- pages ---------- */
    $pageAgg->execute([$instanceId, $curStart, $curEnd]);
    $pc = [];
    while ($r = $pageAgg->fetch(PDO::FETCH_ASSOC)) {
        $pc[$r['page']] = $r;
    }
    $pageAgg->execute([$instanceId, $prevStart, $prevEnd]);
    $pp = [];
    while ($r = $pageAgg->fetch(PDO::FETCH_ASSOC)) {
        $pp[$r['page']] = $r;
    }
    $moves = [];
    foreach (array_unique(array_merge(array_keys($pc), array_keys($pp))) as $page) {
        $c = (int)($pc[$page]['clicks'] ?? 0);
        $p = (int)($pp[$page]['clicks'] ?? 0);
        $d = $c - $p;
        if (abs($d) >= $MIN_PAGE_CLICK_DELTA) {
            $moves[] = ['path' => pagePath((string)$page), 'delta' => $d];
        }
    }
    usort($moves, fn($a, $b) => abs($b['delta']) <=> abs($a['delta']));

    /* ---------- seen but not clicked ---------- */
    $curveStmt->execute([$instanceId]);
    $curve = $GLOBAL_CTR;
    while ($r = $curveStmt->fetch(PDO::FETCH_ASSOC)) {
        $curve[(int)$r['position_band']] = (float)$r['expected_ctr'];
    }
    $missed = [];
    foreach ($pc as $page => $r) {
        $impr = (int)$r['impr'];
        if ($impr < $MISSED_MIN_IMPR) {
            continue;
        }
        $band = max(1, min(20, (int)round((float)$r['pos'])));
        $expected = $curve[$band] ?? 0.01;
        $actual   = $impr > 0 ? (int)$r['clicks'] / $impr : 0.0;
        if ($actual < $expected * $MISSED_CTR_RATIO) {
            $lost = (int)round(($expected - $actual) * $impr);
            if ($lost >= 3) {
                $missed[] = ['path' => pagePath((string)$page), 'impr' => $impr, 'clicks' => (int)$r['clicks'], 'lost' => $lost];
            }
        }
    }
    usort($missed, fn($a, $b) => $b['lost'] <=> $a['lost']);

    /* ---------- hero ---------- */
    $hero = ['type' => '', 'query' => '', 'detail' => '', 'old' => '', 'new' => '', 'clicks' => '', 'color' => '#0E7C4A', 'description' => ''];
    if ($page1) {
        $h = $page1[0];
        $hero = ['type' => 'page1', 'query' => $h['query'], 'detail' => 'hit page 1',
            'old' => fmtPos($h['old']), 'new' => fmtPos($h['new']), 'clicks' => (string)$h['clicks'], 'color' => '#0E7C4A',
            'description' => 'Page 1 keywords typically see 3 to 5x more clicks than page 2, so this is worth protecting. Keep the page that ranks for it fresh and linked from your main navigation.'];
    } elseif ($down && (!$up || $down[0]['impr'] >= $up[0]['impr'])) {
        $h = $down[0];
        $offPage1 = $h['old'] <= 10 && $h['new'] > 10;
        $hero = ['type' => 'drop', 'query' => $h['query'], 'detail' => $offPage1 ? 'dropped off page 1' : 'slipped ' . shownDelta($h['old'], $h['new']) . ' places',
            'old' => fmtPos($h['old']), 'new' => fmtPos($h['new']), 'clicks' => (string)$h['clicks'], 'color' => '#C0392B',
            'description' => 'Drops like this are usually recoverable when caught early. Check the page that ranks for it in the Action Center for title, content and internal link suggestions.'];
    } elseif ($up) {
        $h = $up[0];
        $hero = ['type' => 'up', 'query' => $h['query'], 'detail' => 'climbed ' . shownDelta($h['old'], $h['new']) . ' places',
            'old' => fmtPos($h['old']), 'new' => fmtPos($h['new']), 'clicks' => (string)$h['clicks'], 'color' => '#0E7C4A',
            'description' => 'Google is rewarding this page. A little more content depth or an internal link from a popular page can push it further.'];
    } elseif ($missed) {
        $h = $missed[0];
        $hero = ['type' => 'missed', 'query' => $h['path'], 'detail' => 'is seen but not clicked',
            'old' => number_format($h['impr']), 'new' => (string)$h['clicks'], 'clicks' => (string)$h['clicks'], 'color' => '#8A5A00',
            'description' => 'People see this page on Google but skip it. A clearer title and description usually fixes it; roughly ' . $h['lost'] . ' clicks a week are on the table.'];
    }

    /* ---------- counts, steady, subject ---------- */
    $upCount     = count($up);
    $downCount   = count($down);
    $newCount    = count($new);
    $pagesCount  = count($moves);
    $missedCount = count($missed);
    $totalChanges = $upCount + $downCount + $newCount + $pagesCount + $missedCount;

    if (!$force && $totalChanges === 0) {
        logLine($logFile, "{$email} | {$instanceId} | skip | nothing to report");
        $skipped++;
        continue;
    }

    $isSteady = ($hero['type'] !== 'page1' && $upCount <= $downCount && count($steady) >= 3) ? 'yes' : 'no';
    $steadyExample = $steady[0] ?? null;

    if ($hero['type'] === 'page1') {
        $subject = '"' . $hero['query'] . '" hit page 1 on ' . $domain;
    } elseif ($downCount > 0 && $upCount > 0) {
        $subject = "{$downCount} " . plural($downCount, 'keyword') . " dropped - {$upCount} improved on {$domain}";
    } elseif ($downCount > 0) {
        $subject = "{$downCount} " . plural($downCount, 'keyword') . " slipping on {$domain}";
    } elseif ($upCount > 0) {
        $subject = "{$upCount} " . plural($upCount, 'keyword') . " moving up on {$domain}";
    } elseif ($newCount > 0) {
        $subject = "{$newCount} new " . plural($newCount, 'search', 'searches') . " found {$domain} this week";
    } else {
        $subject = "Your week in search: {$domain}";
    }
    $rest = max(0, $totalChanges - 1);
    $preheader = $rest > 0
        ? "Plus {$rest} more " . plural($rest, 'change') . " across {$domain} this week."
        : "Your search summary for {$domain}.";

    /* ---------- paid? ---------- */
    $isPaid = 'no';
    $paidStmt->execute([$instanceId]);
    if ($pr = $paidStmt->fetch(PDO::FETCH_ASSOC)) {
        $expTs = !empty($pr['expires_on']) ? strtotime((string)$pr['expires_on']) : null;
        if (strtolower(trim((string)$pr['status'])) === 'active'
            && ($expTs === null || $expTs > time())
            && in_array(strtolower(trim((string)$pr['billing_period'])), ['monthly', 'yearly', 'annual'], true)) {
            $isPaid = 'yes';
        }
    }
    if ($isPaid === 'no') {
        $trialStmt->execute([$instanceId]);
        if ($tr = $trialStmt->fetch(PDO::FETCH_ASSOC)) {
            $expTs = !empty($tr['expires_on']) ? strtotime((string)$tr['expires_on']) : null;
            if ($tr['status'] === 'active' && ($expTs === null || $expTs > time())) {
                $isPaid = 'yes'; // premium preview sees the full digest
            }
        }
    }

    /* ---------- build fields ---------- */
    $d = [
        'site_domain' => $domain,
        'week_start'  => $weekStartIso,
        'week_end'    => $weekEndIso,
        'subject'     => $subject,
        'preheader'   => $preheader,

        'clicks'                    => number_format($tc['clicks']),
        'clicks_change'             => pctLabel($tc['clicks'], $tp['clicks']),
        'clicks_change_color'       => upDownColor((float)($tc['clicks'] - $tp['clicks'])),
        'impressions'               => number_format($tc['impr']),
        'impressions_change'        => pctLabel($tc['impr'], $tp['impr']),
        'impressions_change_color'  => upDownColor((float)($tc['impr'] - $tp['impr'])),
        'ctr'                       => number_format($tc['ctr'] * 100, 1) . '%',
        'ctr_change'                => signed(($tc['ctr'] - $tp['ctr']) * 100, 1),
        'ctr_change_color'          => upDownColor(round(($tc['ctr'] - $tp['ctr']) * 100, 1)),
        'avg_position'              => number_format($tc['pos'], 1),
        'avg_position_change'       => posLabel($tp['pos'], $tc['pos']),
        'avg_position_change_color' => upDownColor(($tp['pos'] > 0 && $tc['pos'] > 0) ? round($tp['pos'] - $tc['pos'], 1) : 0.0),

        'hero_query'        => $hero['query'],
        'hero_detail'       => $hero['detail'],
        'hero_old_position' => $hero['old'],
        'hero_new_position' => $hero['new'],
        'hero_clicks'       => $hero['clicks'],
        'hero_color'        => $hero['color'],
        'hero_description'  => $hero['description'],

        'is_steady'               => $isSteady,
        'steady_count'            => $isSteady === 'yes' ? (string)count($steady) : '',
        'steady_example_query'    => $isSteady === 'yes' && $steadyExample ? $steadyExample['query'] : '',
        'steady_example_position' => $isSteady === 'yes' && $steadyExample ? fmtPos($steadyExample['new']) : '',

        'up_count'     => $upCount,
        'down_count'   => $downCount,
        'new_count'    => $newCount,
        'pages_count'  => $pagesCount,
        'missed_count' => $missedCount,
        'total_changes' => (string)$totalChanges,

        'missed_top_name' => $missed[0]['path'] ?? '',
        'missed_top_lost' => isset($missed[0]) ? (string)$missed[0]['lost'] : '',

        'is_paid'        => $isPaid,
        'dashboard_link' => $APP_PUBLIC_BASE . '/action-center.php?instance_id=' . urlencode($instanceId),
        'upgrade_link'   => $APP_PUBLIC_BASE . '/pricing.php?instanceid=' . urlencode($instanceId),
    ];
    for ($i = 1; $i <= 5; $i++) {
        $r = $up[$i - 1] ?? null;
        $d["up_{$i}_query"] = $r ? mb_substr($r['query'], 0, 120) : '';
        $d["up_{$i}_old"]   = $r ? fmtPos($r['old']) : '';
        $d["up_{$i}_new"]   = $r ? fmtPos($r['new']) : '';
        $d["up_{$i}_delta"] = $r ? shownDelta($r['old'], $r['new']) : '';
        $r = $down[$i - 1] ?? null;
        $d["down_{$i}_query"] = $r ? mb_substr($r['query'], 0, 120) : '';
        $d["down_{$i}_old"]   = $r ? fmtPos($r['old']) : '';
        $d["down_{$i}_new"]   = $r ? fmtPos($r['new']) : '';
        $d["down_{$i}_delta"] = $r ? shownDelta($r['old'], $r['new']) : '';
    }
    for ($i = 1; $i <= 3; $i++) {
        $r = $new[$i - 1] ?? null;
        $d["new_{$i}_query"]       = $r ? mb_substr($r['query'], 0, 120) : '';
        $d["new_{$i}_position"]    = $r ? fmtPos($r['pos']) : '';
        $d["new_{$i}_impressions"] = $r ? number_format($r['impr']) : '';
        $r = $moves[$i - 1] ?? null;
        $d["page_{$i}_path"]      = $r ? $r['path'] : '';
        $d["page_{$i}_detail"]    = $r ? (($r['delta'] > 0 ? '+' : '-') . abs($r['delta']) . ' clicks') : '';
        $d["page_{$i}_direction"] = $r ? ($r['delta'] > 0 ? 'up' : 'down') : '';
    }
    for ($i = 1; $i <= 2; $i++) {
        $r = $missed[$i - 1] ?? null;
        $d["missed_{$i}_path"]        = $r ? $r['path'] : '';
        $d["missed_{$i}_impressions"] = $r ? number_format($r['impr']) : '';
        $d["missed_{$i}_clicks"]      = $r ? (string)$r['clicks'] : '';
    }

    $target = $overrideTo ?: $email;
    $person = ['email' => $target, 'googlesearch_source' => $PLATFORM_SOURCE];
    if (!$overrideTo) {
        $person += ['user_id' => $userId, 'instance_id' => $instanceId];
    }
    foreach ($d as $k => $v) {
        $person[$FIELD_PREFIX . $k] = $v;
    }

    if ($asJson) {
        /* The caller decodes the whole of stdout as one JSON object, so
           nothing else may reach stdout: no banner, no summary line. The
           keys are unprefixed; gsc_payload_digest adds digest_ to match the
           person fields this cron pushes. */
        echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        continue;
    }

    if ($dry) {
        echo "=== {$email} | {$instanceId} | {$subject}\n";
        echo json_encode($person, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        continue;
    }

    /* ---------- push twice ---------- */
    $person[$FIELD_PREFIX . 'pushed_at'] = date('Y-m-d H:i:s') . ' a';
    [$c1, $ok1] = enchargePush($ENCHARGE_WRITE_KEY, $person);
    sleep($PUSH_GAP_SECONDS);
    $person[$FIELD_PREFIX . 'pushed_at'] = date('Y-m-d H:i:s') . ' b';
    [$c2, $ok2] = enchargePush($ENCHARGE_WRITE_KEY, $person);

    if ($ok1 || $ok2) {
        if (!$overrideTo) {
            $markStmt->execute([$userId]);
        }
        $pushed++;
        logLine($logFile, "{$target} | {$instanceId} | PUSHED" . ($overrideTo ? ' (test override)' : '') . " | up={$upCount} down={$downCount} new={$newCount} pages={$pagesCount} missed={$missedCount} hero={$hero['type']} paid={$isPaid} | HTTP {$c1}/{$c2}");
    } else {
        logLine($logFile, "{$email} | {$instanceId} | FAILED | HTTP {$c1}/{$c2}");
    }
    usleep(300000);
}

trimLog($logFile);

if ($asJson) {
    /* The summary would corrupt the JSON on stdout, so it goes to the log. */
    logLine($logFile, "json mode | {$onlyInstance} | sites=" . count($sites));
} else {
    echo "cron_weekly_digest.php completed: window {$curStart}..{$curEnd} vs {$prevStart}..{$prevEnd} | checked={$checked} pushed={$pushed} skipped={$skipped}" . ($dry ? ' (dry)' : '') . "\n";
}

/* ============================ helpers ============================ */

function enchargePush(string $key, array $person): array
{
    for ($try = 1; $try <= 3; $try++) {
        $ch = curl_init('https://api.encharge.io/v1/people?api_key=' . $key);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([$person]),
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string)$resp, true);
        $ok   = $code >= 200 && $code < 300 && !empty($json['users'][0]['email']);
        if ($ok) {
            return [$code, true];
        }
        sleep(3);
    }
    return [$code ?? 0, false];
}

function siteDomain(array $s): string
{
    $raw = $s['domain'] ?: ($s['shop_domain'] ?: '');
    $h = strtolower(trim((string)$raw));
    $h = preg_replace('#^https?://#i', '', $h);
    $h = preg_replace('#^www\.#i', '', $h);
    return explode('/', $h)[0] ?: 'your site';
}

function brandTermsLite(array $s): array
{
    $out = [];
    if (!empty($s['shop_name'])) {
        $out[] = strtolower(trim((string)$s['shop_name']));
    }
    $host   = siteDomain($s);
    $labels = explode('.', $host);
    array_pop($labels);
    if (count($labels) > 1 && in_array(end($labels), ['co', 'com', 'net', 'org', 'ac', 'gov', 'edu'], true)) {
        array_pop($labels);
    }
    $handle = $labels ? (string)end($labels) : '';
    $platform = ['gohighlevel', 'msgsndr', 'leadconnectorhq', 'company', 'mybigcommerce', 'myshopify', 'wixsite', 'ecwid', 'websitespeedy'];
    if (in_array($handle, $platform, true) && count($labels) > 1) {
        $handle = (string)$labels[count($labels) - 2];
    }
    if ($handle !== '' && !in_array($handle, array_merge(['www', 'dev', 'shop', 'store', 'test', 'app', 'my'], $platform), true)) {
        $out[] = $handle;
        if (strpos($handle, '-') !== false) {
            $out[] = str_replace('-', '', $handle);
        }
    }
    return array_values(array_unique(array_filter($out, fn($t) => strlen($t) >= 3)));
}

function isBrand(string $query, array $terms): bool
{
    $q = strtolower($query);
    foreach ($terms as $t) {
        if ($t !== '' && strpos($q, $t) !== false) {
            return true;
        }
    }
    return false;
}

function pagePath(string $url): string
{
    $p = parse_url($url, PHP_URL_PATH);
    $p = $p === null || $p === '' ? '/' : $p;
    return mb_substr($p, 0, 80);
}

function fmtPos(float $p): string
{
    return $p >= 10 ? (string)(int)round($p) : number_format($p, 1);
}

function fmtDelta(float $d): string
{
    return (string)(int)round($d);
}

/* Delta as the reader will compute it from the two displayed positions. */
function shownDelta(float $old, float $new): string
{
    $d = abs((float)fmtPos($old) - (float)fmtPos($new));
    return $d >= 10 ? (string)(int)round($d) : rtrim(rtrim(number_format($d, 1), '0'), '.');
}

function pctLabel(int $cur, int $prev): string
{
    if ($prev <= 0) {
        return $cur > 0 ? 'new' : '0%';
    }
    return signed(($cur - $prev) / $prev * 100, 0) . '%';
}

function signed(float $v, int $dec): string
{
    $r = round($v, $dec);
    $s = number_format(abs($r), $dec);
    return ($r > 0 ? '+' : ($r < 0 ? '-' : '')) . $s;
}

function posLabel(float $prev, float $cur): string
{
    if ($prev <= 0 || $cur <= 0) {
        return 'no change';
    }
    $d = round($prev - $cur, 1);
    if (abs($d) < 0.1) {
        return 'no change';
    }
    return ($d > 0 ? 'up ' : 'down ') . number_format(abs($d), 1);
}

function upDownColor(float $diff): string
{
    return $diff < 0 ? '#F87171' : '#4ADE80';
}

function plural(int $n, string $one, ?string $many = null): string
{
    return $n === 1 ? $one : ($many ?? $one . 's');
}

function logLine(string $file, string $line): void
{
    @file_put_contents($file, date('Y-m-d H:i:s') . ' | ' . $line . PHP_EOL, FILE_APPEND);
}

function trimLog(string $file): void
{
    if (!is_file($file) || filesize($file) < 512 * 1024) {
        return;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines !== false && count($lines) > 2000) {
        file_put_contents($file, implode(PHP_EOL, array_slice($lines, -2000)) . PHP_EOL);
    }
}
