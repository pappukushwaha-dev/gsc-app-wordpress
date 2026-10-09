<?php

declare(strict_types=1);

/**
 * cron_page1_check.php  (WordPress)
 *
 * Weekly "page 1 breakthrough" detector, from gsc_query_daily and
 * gsc_page_query_daily (filled by cron/snapshot_daily.php). No API calls.
 *
 * A breakthrough is a query whose average position was worse than 10 in
 * the previous 7 days and is 10 or better in the last 7 days, with enough
 * impressions to matter. Each query fires once per site, ever
 * (gsc_page1_seen). Per run, one query per site is pushed (the one with
 * the most impressions); the rest are left for the weekly digest.
 *
 * Encharge flow "Page 1 Breakthrough" triggers on
 * googlesearch_page1_detected changing to yes. Sites that were flagged
 * last run and have nothing new this run are reset to no so the next
 * breakthrough can trigger again.
 *
 * Fields pushed:
 *   googlesearch_page1_detected   yes | no
 *   page1_query                   text
 *   page1_position                number (current avg, 1 decimal)
 *   page1_old_position            number (previous avg, 1 decimal)
 *   page1_page_path               text (URL of the ranking page, if known)
 *
 * Usage:
 *   php scripts/cron_page1_check.php                 all verified sites
 *   php scripts/cron_page1_check.php <instance_id>   one site
 *   php scripts/cron_page1_check.php <instance_id> --force   push the best current page-1 query even if seen (flow test)
 *
 * Crontab (weekly, Wednesday):
 *   0 7 * * 3 cd /var/www/html/wordpress/googlesearchconsole && php scripts/cron_page1_check.php >> /var/log/gsc_page1_ecwid.log 2>&1
 *
 * One-time schema:
 *   CREATE TABLE IF NOT EXISTS gsc_page1_seen (
 *     instance_id VARCHAR(191) NOT NULL,
 *     query_hash  CHAR(32) NOT NULL,
 *     query       VARCHAR(500) NOT NULL,
 *     first_seen  DATE NOT NULL,
 *     PRIMARY KEY (instance_id, query_hash)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
 *   ALTER TABLE WpSite ADD COLUMN page1_flag TINYINT NOT NULL DEFAULT 0;
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$ENCHARGE_WRITE_KEY = 'n4ZJTpZktcyj32fQLBW555m4N';
$APP_PUBLIC_BASE    = 'https://makkpressapps.com/wordpress/googlesearchconsole';
$PLATFORM_SOURCE    = 'WordPress';
$SITE_TABLE         = 'WpSite';

$GSC_LAG_DAYS    = 3;    // most recent complete day in gsc_query_daily
$MIN_IMPRESSIONS = 20;   // impressions in the last 7 days for a query to count
$PAGE1_MAX_POS   = 10.0; // position 10 or better = page 1
$MIN_PREV_POS    = 12.0; // previous-week avg must be worse than this (real page-2 to page-1 move, not hovering at 10)
$MIN_ROWS_PREV   = 2;    // days the query must have appeared in the previous week

$F_DETECTED = 'googlesearch_page1_detected';
$F_QUERY    = 'page1_query';
$F_POS      = 'page1_position';
$F_OLD_POS  = 'page1_old_position';
$F_PATH     = 'page1_page_path';

require_once __DIR__ . '/../includes/config.php';

$logFile      = __DIR__ . '/cron_page1_check.log';
$onlyInstance = isset($argv[1]) && $argv[1][0] !== '-' ? $argv[1] : null;
$force        = in_array('--force', $argv, true);

$curEnd    = date('Y-m-d', strtotime('-' . $GSC_LAG_DAYS . ' days'));
$curStart  = date('Y-m-d', strtotime('-' . ($GSC_LAG_DAYS + 6) . ' days'));
$prevEnd   = date('Y-m-d', strtotime('-' . ($GSC_LAG_DAYS + 7) . ' days'));
$prevStart = date('Y-m-d', strtotime('-' . ($GSC_LAG_DAYS + 13) . ' days'));

/* -----------------------------------
 * Sites: active, verified, with email
 * ----------------------------------- */
$sql = "
    SELECT s.id AS user_id, s.instance_id, s.email, s.page1_flag
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

/* Current-week queries on page 1 with enough impressions, joined to their
   previous-week average. Impression-weighted position on both sides. */
$breakStmt = $pdo->prepare("
    SELECT
        c.query_hash,
        c.query,
        c.impr AS cur_impr,
        c.pos  AS cur_pos,
        p.pos  AS prev_pos,
        p.days AS prev_days
    FROM (
        SELECT query_hash, MAX(query) AS query,
               SUM(impressions) AS impr,
               SUM(position * impressions) / SUM(impressions) AS pos
        FROM gsc_query_daily
        WHERE instance_id = ? AND date BETWEEN ? AND ?
        GROUP BY query_hash
        HAVING impr >= ? AND pos <= ?
    ) c
    JOIN (
        SELECT query_hash,
               SUM(position * impressions) / SUM(impressions) AS pos,
               COUNT(*) AS days
        FROM gsc_query_daily
        WHERE instance_id = ? AND date BETWEEN ? AND ?
        GROUP BY query_hash
    ) p ON p.query_hash = c.query_hash
    WHERE p.pos > ? AND p.days >= ?
    ORDER BY c.impr DESC
");

/* Best page-1 query right now, used only for --force testing. */
$forceStmt = $pdo->prepare("
    SELECT query_hash, MAX(query) AS query,
           SUM(impressions) AS impr,
           SUM(position * impressions) / SUM(impressions) AS pos
    FROM gsc_query_daily
    WHERE instance_id = ? AND date BETWEEN ? AND ?
    GROUP BY query_hash
    HAVING pos <= ?
    ORDER BY impr DESC
    LIMIT 1
");

$seenStmt = $pdo->prepare("SELECT 1 FROM gsc_page1_seen WHERE instance_id = ? AND query_hash = ?");
$markSeen = $pdo->prepare("INSERT IGNORE INTO gsc_page1_seen (instance_id, query_hash, query, first_seen) VALUES (?,?,?,?)");
$pathStmt = $pdo->prepare("
    SELECT page FROM gsc_page_query_daily
    WHERE instance_id = ? AND query_hash = ? AND date BETWEEN ? AND ?
    GROUP BY page ORDER BY SUM(impressions) DESC LIMIT 1
");
$flagStmt = $pdo->prepare("UPDATE {$SITE_TABLE} SET page1_flag = ? WHERE id = ?");

$checked = 0;
$pushed  = 0;
$reset   = 0;

foreach ($sites as $s) {
    $checked++;
    $instanceId = (string)$s['instance_id'];
    $email      = trim((string)$s['email']);
    $userId     = (int)$s['user_id'];
    $wasFlagged = (int)$s['page1_flag'] === 1;

    $pick = null;

    if ($force) {
        $forceStmt->execute([$instanceId, $curStart, $curEnd, $PAGE1_MAX_POS]);
        $row = $forceStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pick = [
                'query_hash' => $row['query_hash'],
                'query'      => $row['query'],
                'cur_pos'    => (float)$row['pos'],
                'prev_pos'   => (float)$row['pos'] + 5, // synthetic old position for the test
                'cur_impr'   => (int)$row['impr'],
            ];
        }
    } else {
        $breakStmt->execute([
            $instanceId, $curStart, $curEnd, $MIN_IMPRESSIONS, $PAGE1_MAX_POS,
            $instanceId, $prevStart, $prevEnd,
            $MIN_PREV_POS, $MIN_ROWS_PREV,
        ]);
        while ($row = $breakStmt->fetch(PDO::FETCH_ASSOC)) {
            $seenStmt->execute([$instanceId, $row['query_hash']]);
            if ($seenStmt->fetchColumn()) {
                continue; // already celebrated this query
            }
            $pick = [
                'query_hash' => $row['query_hash'],
                'query'      => $row['query'],
                'cur_pos'    => (float)$row['cur_pos'],
                'prev_pos'   => (float)$row['prev_pos'],
                'cur_impr'   => (int)$row['cur_impr'],
            ];
            break; // first unseen row = highest impressions
        }
    }

    if ($pick === null) {
        if ($wasFlagged) {
            /* Nothing new this week: reset so the next breakthrough triggers. */
            enchargePush($ENCHARGE_WRITE_KEY, [
                'email'       => $email,
                'user_id'     => $userId,
                'instance_id' => $instanceId,
                $F_DETECTED   => 'no',
            ]);
            $flagStmt->execute([0, $userId]);
            $reset++;
            logLine($logFile, "{$email} | {$instanceId} | reset");
        } else {
            logLine($logFile, "{$email} | {$instanceId} | none");
        }
        continue;
    }

    $pathStmt->execute([$instanceId, $pick['query_hash'], $curStart, $curEnd]);
    $pagePath = (string)($pathStmt->fetchColumn() ?: '');

    $payload = [
        'email'       => $email,
        'user_id'     => $userId,
        'instance_id' => $instanceId,

        'googlesearch_source'         => $PLATFORM_SOURCE,
        $F_QUERY                      => mb_substr((string)$pick['query'], 0, 200),
        $F_POS                        => round($pick['cur_pos'], 1),
        $F_OLD_POS                    => round($pick['prev_pos'], 1),
        $F_PATH                       => mb_substr($pagePath, 0, 500),
        'googlesearch_dashboard_link' => $APP_PUBLIC_BASE . '/action-center.php?instance_id=' . urlencode($instanceId),
        $F_DETECTED                   => 'yes',
        /* Trigger field for the Encharge flow: changes only on a real
           breakthrough, never on the reset push, so the flow needs no
           condition node and resets never enter it. */
        'page1_pushed_at'             => date('Y-m-d H:i:s'),
    ];

    /* If the site is still flagged from last run, flip to no first so the
       Field Changed trigger sees a real change. */
    if ($wasFlagged) {
        enchargePush($ENCHARGE_WRITE_KEY, [
            'email'       => $email,
            'user_id'     => $userId,
            'instance_id' => $instanceId,
            $F_DETECTED   => 'no',
        ]);
        usleep(500000);
    }

    [$httpStatus, $resp] = enchargePush($ENCHARGE_WRITE_KEY, $payload);

    if ($httpStatus >= 200 && $httpStatus < 300) {
        if (!$force) {
            $markSeen->execute([$instanceId, $pick['query_hash'], mb_substr((string)$pick['query'], 0, 500), $curEnd]);
        }
        $flagStmt->execute([1, $userId]);
        $pushed++;
        logLine($logFile, "{$email} | {$instanceId} | PAGE1 | \"{$pick['query']}\" "
            . round($pick['prev_pos'], 1) . " -> " . round($pick['cur_pos'], 1)
            . " | impr={$pick['cur_impr']} | {$pagePath} | HTTP {$httpStatus}");
    } else {
        logLine($logFile, "{$email} | {$instanceId} | FAILED | HTTP {$httpStatus} | " . substr((string)$resp, 0, 200));
    }

    usleep(200000);
}

trimLog($logFile);
echo "cron_page1_check.php completed: window {$curStart}..{$curEnd} vs {$prevStart}..{$prevEnd} | checked={$checked} pushed={$pushed} reset={$reset}\n";

/* ---------- helpers ---------- */

function enchargePush(string $key, array $payload): array
{
    $ch = curl_init('https://api.encharge.io/v1/people?api_key=' . $key);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([$payload]),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp];
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
