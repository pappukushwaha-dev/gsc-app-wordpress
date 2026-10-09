<?php

declare(strict_types=1);

/**
 * cron_first_report.php  (WordPress)
 *
 * Detects the first Search Console data for each newly installed site and
 * pushes the "First Report" fields to Encharge. The Encharge flow
 * "Google Search Console - First Report" triggers on the field
 * "Googlesearch first Report Ready" changing to "yes".
 *
 * Data source: gsc_query_daily, already filled by cron/snapshot_daily.php.
 * No Search Console API calls are made here.
 *
 * A site qualifies when, over its available snapshot days:
 *   total clicks >= MIN_CLICKS  OR  total impressions >= MIN_IMPRESSIONS
 * Each site is pushed exactly once (WpSite.first_report_at is set).
 *
 * Launch guard: only sites installed on/after LAUNCH_DATE are considered,
 * so existing users never get a "first report" email retroactively.
 *
 * Usage:
 *   php scripts/cron_first_report.php                 all eligible sites
 *   php scripts/cron_first_report.php <instance_id>   one site, ignores the launch guard (testing)
 *
 * Crontab (daily, after the snapshot cron has had the whole day):
 *   15 6 * * * cd /var/www/html/wordpress/googlesearchconsole && php scripts/cron_first_report.php >> /var/log/gsc_first_report_ecwid.log 2>&1
 *
 * One-time schema change:
 *   ALTER TABLE WpSite ADD COLUMN first_report_at DATETIME NULL DEFAULT NULL;
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$ENCHARGE_WRITE_KEY = 'n4ZJTpZktcyj32fQLBW555m4N';
$APP_PUBLIC_BASE    = 'https://makkpressapps.com/wordpress/googlesearchconsole';
$PLATFORM_SOURCE    = 'WordPress';
$SITE_TABLE         = 'WpSite';

/* Sites installed before this date are never emailed. */
$LAUNCH_DATE = '2026-09-01';

/* Qualification thresholds. Either one is enough. */
$MIN_CLICKS      = 1;
$MIN_IMPRESSIONS = 10;

/* Encharge field API names. Confirm each one in Encharge > Settings >
   Custom Fields; the labels were created by hand so the API names may
   differ from the labels (e.g. "First Impressions" has no prefix). */
$F_READY       = 'googlesearch_first_report_ready';
$F_CLICKS      = 'googlesearch_first_clicks';
$F_IMPRESSIONS = 'first_impressions';
$F_QUERY_COUNT = 'first_query_count';
$F_TOP_QUERY   = 'first_top_query';
$F_REPORT_AT   = 'googlesearch_first_report_at';

require_once __DIR__ . '/../includes/config.php';

$logFile      = __DIR__ . '/cron_first_report.log';
$onlyInstance = $argv[1] ?? null;

/* -----------------------------------
 * Candidate sites: installed after launch, verified, not yet reported
 * ----------------------------------- */
$sql = "
    SELECT
        s.id          AS user_id,
        s.instance_id,
        s.email,
        s.created_at
    FROM {$SITE_TABLE} s
    WHERE s.email IS NOT NULL AND s.email <> ''
      AND s.is_active = 1
      AND s.first_report_at IS NULL
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
} else {
    $sql     .= " AND s.created_at >= ?";
    $params[] = $LAUNCH_DATE . ' 00:00:00';
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Per-site aggregate, run separately to avoid cross-table collation joins. */
$aggStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(clicks), 0)        AS clicks,
        COALESCE(SUM(impressions), 0)   AS impressions,
        COUNT(DISTINCT query_hash)      AS query_count,
        MIN(date)                       AS first_date,
        MAX(date)                       AS last_date
    FROM gsc_query_daily
    WHERE instance_id = ?
");

/* Top query: most clicks, then most impressions. */
$topStmt = $pdo->prepare("
    SELECT query, SUM(clicks) AS c, SUM(impressions) AS i
    FROM gsc_query_daily
    WHERE instance_id = ?
    GROUP BY query_hash, query
    ORDER BY c DESC, i DESC
    LIMIT 1
");

$markStmt = $pdo->prepare("UPDATE {$SITE_TABLE} SET first_report_at = NOW() WHERE id = ?");

$checked = 0;
$pushed  = 0;

foreach ($sites as $s) {
    $checked++;
    $instanceId = (string)$s['instance_id'];
    $email      = trim((string)$s['email']);

    $aggStmt->execute([$instanceId]);
    $agg = $aggStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $clicks      = (int)($agg['clicks'] ?? 0);
    $impressions = (int)($agg['impressions'] ?? 0);
    $queryCount  = (int)($agg['query_count'] ?? 0);

    $qualifies = ($clicks >= $MIN_CLICKS) || ($impressions >= $MIN_IMPRESSIONS);
    if (!$qualifies) {
        logLine($logFile, "{$email} | {$instanceId} | waiting | clicks={$clicks} impr={$impressions}");
        continue;
    }

    $topStmt->execute([$instanceId]);
    $top      = $topStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $topQuery = (string)($top['query'] ?? '');

    $payload = [
        'email'       => $email,
        'user_id'     => (int)$s['user_id'],
        'instance_id' => $instanceId,

        'googlesearch_source'       => $PLATFORM_SOURCE,
        $F_CLICKS                   => $clicks,
        $F_IMPRESSIONS              => $impressions,
        $F_QUERY_COUNT              => $queryCount,
        $F_TOP_QUERY                => mb_substr($topQuery, 0, 200),
        $F_REPORT_AT                => date(DATE_ATOM),
        'googlesearch_upgrade_link'   => $APP_PUBLIC_BASE . '/pricing.php?instanceid=' . urlencode($instanceId),
        'googlesearch_review_link'    => $APP_PUBLIC_BASE . '/customer-reviews.php?instanceid=' . urlencode($instanceId),
        'googlesearch_dashboard_link' => $APP_PUBLIC_BASE . '/action-center.php?instance_id=' . urlencode($instanceId),

        /* Trigger field last: Encharge processes the payload as one
           update, but keeping it last makes the intent obvious. */
        $F_READY                    => 'yes',
    ];

    $ch = curl_init('https://api.encharge.io/v1/people?api_key=' . $ENCHARGE_WRITE_KEY);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([$payload]),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp       = curl_exec($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpStatus >= 200 && $httpStatus < 300) {
        $markStmt->execute([(int)$s['user_id']]);
        $pushed++;
        logLine($logFile, "{$email} | {$instanceId} | PUSHED | clicks={$clicks} impr={$impressions} queries={$queryCount} top=\"{$topQuery}\" | HTTP {$httpStatus}");
    } else {
        /* Not marked, so it retries tomorrow. */
        logLine($logFile, "{$email} | {$instanceId} | FAILED | HTTP {$httpStatus} | " . substr((string)$resp, 0, 200));
    }

    usleep(200000);
}

trimLog($logFile);
echo "cron_first_report.php completed: checked={$checked} pushed={$pushed}\n";

/* ---------- helpers ---------- */

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
