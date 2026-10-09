<?php

declare(strict_types=1);

/**
 * cron_crash_check.php  (WordPress)
 *
 * Daily traffic-drop check per verified site, from gsc_query_daily
 * (filled by cron/snapshot_daily.php). No Search Console API calls.
 *
 * Compares the last 7 complete days of clicks against the 7 days before
 * them. Search Console data lags ~3 days, so "last 7" means
 * today-9 .. today-3 and "previous 7" means today-16 .. today-10.
 *
 * Crash  = drop >= CRASH_DROP_PCT  AND  previous-week clicks >= MIN_PREV_CLICKS
 * Pushes googlesearch_crash_detected = yes with the numbers.
 * Recovery pushes googlesearch_crash_detected = no so the next crash can
 * trigger again. Encharge flow "Crash Alert" fires on the no-to-yes change;
 * its "re-entry every 7 days" setting is the cooldown.
 *
 * Fields pushed:
 *   googlesearch_crash_detected   yes | no
 *   crash_drop_pct                integer percent (0 when no crash)
 *   crash_current_clicks          integer
 *   crash_previous_clicks         integer
 *
 * Usage:
 *   php scripts/cron_crash_check.php                 all verified sites
 *   php scripts/cron_crash_check.php <instance_id>   one site
 *   php scripts/cron_crash_check.php <instance_id> --force   push yes regardless (flow test)
 *
 * Crontab:
 *   30 6 * * * cd /var/www/html/wordpress/googlesearchconsole && php scripts/cron_crash_check.php >> /var/log/gsc_crash_ecwid.log 2>&1
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$ENCHARGE_WRITE_KEY = 'n4ZJTpZktcyj32fQLBW555m4N';
$APP_PUBLIC_BASE    = 'https://makkpressapps.com/wordpress/googlesearchconsole';
$PLATFORM_SOURCE    = 'WordPress';
$SITE_TABLE         = 'WpSite';

$CRASH_DROP_PCT  = 20;   // percent drop that counts as a crash
$MIN_PREV_CLICKS = 50;   // previous-week clicks needed before a drop matters
$GSC_LAG_DAYS    = 3;    // most recent complete day in gsc_query_daily

$F_DETECTED = 'googlesearch_crash_detected';
$F_DROP_PCT = 'crash_drop_pct';
$F_CURRENT  = 'crash_current_clicks';
$F_PREVIOUS = 'crash_previous_clicks';

require_once __DIR__ . '/../includes/config.php';

$logFile      = __DIR__ . '/cron_crash_check.log';
$onlyInstance = isset($argv[1]) && $argv[1][0] !== '-' ? $argv[1] : null;
$force        = in_array('--force', $argv, true);

/* Date windows */
$curEnd    = date('Y-m-d', strtotime('-' . $GSC_LAG_DAYS . ' days'));
$curStart  = date('Y-m-d', strtotime('-' . ($GSC_LAG_DAYS + 6) . ' days'));
$prevEnd   = date('Y-m-d', strtotime('-' . ($GSC_LAG_DAYS + 7) . ' days'));
$prevStart = date('Y-m-d', strtotime('-' . ($GSC_LAG_DAYS + 13) . ' days'));

/* -----------------------------------
 * Sites: active, verified, with email
 * ----------------------------------- */
$sql = "
    SELECT s.id AS user_id, s.instance_id, s.email
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

$sumStmt = $pdo->prepare("
    SELECT COALESCE(SUM(clicks), 0)
    FROM gsc_query_daily
    WHERE instance_id = ? AND date BETWEEN ? AND ?
");

$checked = 0;
$crashed = 0;

foreach ($sites as $s) {
    $checked++;
    $instanceId = (string)$s['instance_id'];
    $email      = trim((string)$s['email']);

    $sumStmt->execute([$instanceId, $curStart, $curEnd]);
    $current = (int)$sumStmt->fetchColumn();

    $sumStmt->execute([$instanceId, $prevStart, $prevEnd]);
    $previous = (int)$sumStmt->fetchColumn();

    $dropPct = 0;
    if ($previous > 0 && $current < $previous) {
        $dropPct = (int)round((($previous - $current) / $previous) * 100);
    }

    $isCrash = ($previous >= $MIN_PREV_CLICKS && $dropPct >= $CRASH_DROP_PCT);
    if ($force) {
        $isCrash = true;
        if ($dropPct === 0) {
            $dropPct = $CRASH_DROP_PCT;
        }
    }

    /* Sites below the click floor and not in a crash: nothing to say,
       and no point resetting a field that was never set. Skip the API call. */
    if (!$isCrash && $previous < $MIN_PREV_CLICKS) {
        logLine($logFile, "{$email} | {$instanceId} | skip | prev={$previous} cur={$current}");
        continue;
    }

    $payload = [
        'email'       => $email,
        'user_id'     => (int)$s['user_id'],
        'instance_id' => $instanceId,

        'googlesearch_source'         => $PLATFORM_SOURCE,
        $F_CURRENT                    => $current,
        $F_PREVIOUS                   => $previous,
        $F_DROP_PCT                   => $isCrash ? $dropPct : 0,
        'googlesearch_dashboard_link' => $APP_PUBLIC_BASE . '/action-center.php?instance_id=' . urlencode($instanceId),
        $F_DETECTED                   => $isCrash ? 'yes' : 'no',
    ];
    if ($isCrash) {
        /* Trigger field for the Encharge flow: changes only on a crash,
           never on the recovery push, so the flow needs no condition node. */
        $payload['crash_pushed_at'] = date('Y-m-d H:i:s');
    }

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

    if ($isCrash) {
        $crashed++;
    }

    logLine($logFile, "{$email} | {$instanceId} | " . ($isCrash ? 'CRASH' : 'ok')
        . " | prev={$previous} cur={$current} drop={$dropPct}% | HTTP {$httpStatus}"
        . ($httpStatus >= 300 ? ' | ' . substr((string)$resp, 0, 200) : ''));

    usleep(200000);
}

trimLog($logFile);
echo "cron_crash_check.php completed: window {$curStart}..{$curEnd} vs {$prevStart}..{$prevEnd} | checked={$checked} crashed={$crashed}\n";

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
