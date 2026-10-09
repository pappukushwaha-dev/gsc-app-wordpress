<?php
declare(strict_types=1);

/**
 * includes/email_payload.php  (WordPress)
 *
 * Given an instance and an email type, returns every field that email
 * renders, read from this app's own database.
 *
 * WHY THE WEBHOOK BUILDS THIS INSTEAD OF ENCHARGE SENDING IT
 *
 * Encharge's Send Webhook step takes flat key/value pairs typed by hand.
 * The weekly digest renders around sixty fields, so sending them all means
 * sixty rows per flow across five apps, and a row typed wrong fails
 * silently: the field arrives empty and the stored copy quietly disagrees
 * with the email nobody can see any more.
 *
 * The flow sends email_type and instance_id. This finds the rest.
 *
 * THE ONE THING THIS FILE DOES NOT COMPUTE
 *
 * The weekly digest. Its numbers come from roughly three hundred lines in
 * cron_weekly_digest.php, and a second copy of that would agree with the
 * email at first and drift later. This asks that file for them instead,
 * through its --json mode.
 *
 * Everything else is small enough to read directly, and the queries below
 * mirror the ones in the matching cron: same windows, same thresholds.
 * When a threshold changes in a cron it has to change here too, which is
 * the cost of not spawning a process for an email with eight fields.
 */

/* define(), not const.
 *
 * const is only legal at the top level of a file or inside a class, and
 * everything below sits inside the function_exists guard, so const there
 * is a parse error. define() has no such restriction.
 *
 * Guarded individually so a second include cannot redefine them. */

/* Matches $GSC_LAG_DAYS in the crons: Search Console data lags about three
   days, so "the last complete day" is three days back. */
if (!defined('GSC_PAYLOAD_LAG_DAYS')) {
    define('GSC_PAYLOAD_LAG_DAYS', 3);
}

/* Where cron_weekly_digest.php lives, relative to this file. */
if (!defined('GSC_DIGEST_CRON')) {
    define('GSC_DIGEST_CRON', __DIR__ . '/../scripts/cron_weekly_digest.php');
}

if (!defined('GSC_DIGEST_TIMEOUT')) {
    define('GSC_DIGEST_TIMEOUT', 25);
}

if (!function_exists('gsc_email_payload')) {

/**
 * @param string $emailType weekly_digest | first_report | crash_alert |
 *                          page1_breakthrough, anything else returns the
 *                          common fields only
 */
function gsc_email_payload(PDO $pdo, string $instanceId, string $emailType): array
{
    $instanceId = trim($instanceId);

    if ($instanceId === '') {
        return [];
    }

    $site    = gsc_payload_site($pdo, $instanceId);
    $payload = gsc_payload_common($pdo, $instanceId, $site);

    switch ($emailType) {

        case 'weekly_digest':
            $payload += gsc_payload_digest($instanceId);
            break;

        case 'first_report':
            $payload += gsc_payload_first_report($pdo, $instanceId);
            break;

        case 'crash_alert':
            $payload += gsc_payload_crash($pdo, $instanceId);
            break;

        case 'page1_breakthrough':
            $payload += gsc_payload_page1($pdo, $instanceId);
            break;
    }

    return $payload;
}


/* ==========================================================
   SITE

   Column names differ between the five apps and have changed
   within them, so the row is fetched whole and the usable
   column picked off it rather than named in the SQL. A wrong
   column name in a SELECT is a fatal; here it is just a key
   that is not there.
========================================================== */
function gsc_payload_site(PDO $pdo, string $instanceId): array
{
    $out = ['domain' => '', 'email' => '', 'user_id' => 0];

    /* The verification row is the better source: it holds the property
       actually connected to Search Console, which is what every one of
       these emails is about. */
    try {
        $q = $pdo->prepare("
            SELECT site_url FROM gsc_domain_verifications
            WHERE instance_id = ? ORDER BY id DESC LIMIT 1
        ");
        $q->execute([$instanceId]);
        $url = (string)($q->fetchColumn() ?: '');

        if ($url !== '') {
            $out['domain'] = gsc_payload_clean_domain($url);
        }
    } catch (Throwable $e) {
        // falls through to the site table
    }

    try {
        $q = $pdo->prepare("SELECT * FROM WpSite WHERE instance_id = ? LIMIT 1");
        $q->execute([$instanceId]);
        $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];

        if ($row) {
            $out['email']   = (string)($row['email'] ?? $row['owner_email'] ?? '');
            $out['user_id'] = (int)($row['id'] ?? 0);

            if ($out['domain'] === '') {
                foreach (['domain', 'shop_domain', 'site_url', 'store_url', 'url'] as $c) {
                    if (!empty($row[$c])) {
                        $out['domain'] = gsc_payload_clean_domain((string)$row[$c]);
                        break;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // leave what we have
    }

    return $out;
}

/** Host only: no scheme, no sc-domain prefix, no trailing slash. */
function gsc_payload_clean_domain(string $raw): string
{
    $raw = trim($raw);

    if (strncmp($raw, 'sc-domain:', 10) === 0) {
        return substr($raw, 10);
    }

    $host = parse_url($raw, PHP_URL_HOST);

    return (string)($host ?: rtrim(preg_replace('#^https?://#i', '', $raw) ?? '', '/'));
}


/* ==========================================================
   COMMON

   Fields every template can reach for, whatever the email.
========================================================== */
function gsc_payload_common(PDO $pdo, string $instanceId, array $site): array
{
    $base = 'https://makkpressapps.com/wordpress/googlesearchconsole';
    $enc  = urlencode($instanceId);

    $domain = $site['domain'];

    $payload = [
        'googlesearch_source'         => 'WordPress',
        'googlesearch_website'        => $domain,
        'googlesearch_domain_name'    => $domain,
        'site_domain'                 => $domain,
        'googlesearch_dashboard_link' => $base . '/action-center.php?instance_id=' . $enc,
        'googlesearch_upgrade_link'   => $base . '/pricing.php?instanceid=' . $enc,
        'googlesearch_review_link'    => $base . '/customer-reviews.php?instanceid=' . $enc,
        'instance_id'                 => $instanceId,
    ];

    /* Paid or not, so a template can lock a section the same way the email
       did. Grow and Organic Booster are the paid names here; a limited or
       free billing period is the free tier. */
    $isPaid = 'no';

    try {
        $q = $pdo->prepare("
            SELECT plan_name, billing_period, status
            FROM app_subscriptions
            WHERE instance_id = ?
            ORDER BY (LOWER(status) = 'active') DESC, id DESC
            LIMIT 1
        ");
        $q->execute([$instanceId]);
        $sub = $q->fetch(PDO::FETCH_ASSOC) ?: [];

        if ($sub) {
            $billing = strtolower((string)($sub['billing_period'] ?? ''));
            $status  = strtolower((string)($sub['status'] ?? ''));
            $name    = strtolower((string)($sub['plan_name'] ?? ''));

            $freeish = in_array($name, ['free', 'basic'], true)
                    || in_array($billing, ['limited', 'free'], true);

            if (!$freeish && in_array($status, ['active', 'frozen', 'non_renewing'], true)) {
                $isPaid = 'yes';
            }
        }

        /* A running trial sees the paid email. */
        if ($isPaid === 'no') {
            $q = $pdo->prepare("
                SELECT 1 FROM app_free_trials
                WHERE instance_id = ? AND status = 'active' AND expires_on > NOW()
                LIMIT 1
            ");
            $q->execute([$instanceId]);
            if ($q->fetchColumn()) {
                $isPaid = 'yes';
            }
        }
    } catch (Throwable $e) {
        // leave it at no
    }

    $payload['googlesearch_is_paid'] = $isPaid;
    $payload['is_paid']              = $isPaid;

    return $payload;
}


/* ==========================================================
   WEEKLY DIGEST

   Asked of the cron that computes the email, not recomputed.
   See the note at the top of this file.
========================================================== */
function gsc_payload_digest(string $instanceId): array
{
    if (!is_file(GSC_DIGEST_CRON) || !function_exists('proc_open')) {
        return [];
    }

    $cmd = escapeshellarg(PHP_BINARY ?: 'php') . ' '
         . escapeshellarg(GSC_DIGEST_CRON) . ' '
         . escapeshellarg($instanceId) . ' --json';

    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $proc = @proc_open($cmd, $spec, $pipes, dirname(GSC_DIGEST_CRON));

    if (!is_resource($proc)) {
        return [];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out      = '';
    $deadline = time() + GSC_DIGEST_TIMEOUT;

    while (true) {
        $out .= (string)stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }

        /* A build that hangs would hold the webhook open until Encharge
           times out and retries, which starts the same hanging build
           again. */
        if (time() >= $deadline) {
            proc_terminate($proc, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return [];
        }

        usleep(100000);
    }

    $out .= (string)stream_get_contents($pipes[1]);

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $data = json_decode(trim($out), true);

    if (!is_array($data)) {
        return [];
    }

    /* Prefixed to match the names the template uses. */
    $prefixed = [];
    foreach ($data as $k => $v) {
        $prefixed['digest_' . $k] = $v;
    }

    return $prefixed;
}


/* ==========================================================
   FIRST REPORT

   Everything since the site started reporting, which is what
   the email says: "Google has sent the first search data".
========================================================== */
function gsc_payload_first_report(PDO $pdo, string $instanceId): array
{
    $out = [
        'googlesearch_first_clicks'    => 0,
        'first_impressions'            => 0,
        'first_query_count'            => 0,
        'first_top_query'              => '',
        'googlesearch_first_report_at' => '',
    ];

    try {
        $q = $pdo->prepare("
            SELECT
                COALESCE(SUM(clicks), 0)      AS clicks,
                COALESCE(SUM(impressions), 0) AS impressions,
                COUNT(DISTINCT query_hash)    AS query_count,
                MIN(date)                     AS first_date
            FROM gsc_query_daily
            WHERE instance_id = ?
        ");
        $q->execute([$instanceId]);
        $agg = $q->fetch(PDO::FETCH_ASSOC) ?: [];

        $out['googlesearch_first_clicks'] = (int)($agg['clicks'] ?? 0);
        $out['first_impressions']         = number_format((int)($agg['impressions'] ?? 0));
        $out['first_query_count']         = (int)($agg['query_count'] ?? 0);

        if (!empty($agg['first_date'])) {
            $out['googlesearch_first_report_at'] = (string)$agg['first_date'];
        }

        /* Most clicks, then most impressions, the same order the cron uses
           so the email and this agree on which query was top. */
        $q = $pdo->prepare("
            SELECT query, SUM(clicks) AS c, SUM(impressions) AS i
            FROM gsc_query_daily
            WHERE instance_id = ?
            GROUP BY query_hash, query
            ORDER BY c DESC, i DESC
            LIMIT 1
        ");
        $q->execute([$instanceId]);
        $out['first_top_query'] = (string)($q->fetchColumn() ?: '');

        /* The cron's own test for whether there was anything worth
           reporting: MIN_CLICKS 1 or MIN_IMPRESSIONS 10. */
        $out['googlesearch_first_report_ready'] =
            ((int)($agg['clicks'] ?? 0) >= 1 || (int)($agg['impressions'] ?? 0) >= 10)
                ? 'yes' : 'no';

    } catch (Throwable $e) {
        // zeros are a truthful answer when the tables cannot be read
    }

    return $out;
}


/* ==========================================================
   CRASH ALERT

   Last seven complete days against the seven before them, the
   same windows cron_crash_check.php compares.
========================================================== */
function gsc_payload_crash(PDO $pdo, string $instanceId): array
{
    $lag = GSC_PAYLOAD_LAG_DAYS;

    $curEnd    = date('Y-m-d', strtotime('-' . $lag . ' days'));
    $curStart  = date('Y-m-d', strtotime('-' . ($lag + 6) . ' days'));
    $prevEnd   = date('Y-m-d', strtotime('-' . ($lag + 7) . ' days'));
    $prevStart = date('Y-m-d', strtotime('-' . ($lag + 13) . ' days'));

    $out = [
        'crash_current_clicks'         => 0,
        'crash_previous_clicks'        => 0,
        'crash_drop_pct'               => 0,
        'googlesearch_crash_detected'  => 'no',
    ];

    try {
        $q = $pdo->prepare("
            SELECT COALESCE(SUM(clicks), 0)
            FROM gsc_query_daily
            WHERE instance_id = ? AND date BETWEEN ? AND ?
        ");

        $q->execute([$instanceId, $curStart, $curEnd]);
        $current = (int)$q->fetchColumn();

        $q->execute([$instanceId, $prevStart, $prevEnd]);
        $previous = (int)$q->fetchColumn();

        $drop = 0;
        if ($previous > 0 && $current < $previous) {
            $drop = (int)round((($previous - $current) / $previous) * 100);
        }

        /* Thresholds and field names checked against cron_crash_check.php:
           CRASH_DROP_PCT 20, MIN_PREV_CLICKS 50, GSC_LAG_DAYS 3, and the
           same two seven-day windows. */
        $isCrash = ($previous >= 50 && $drop >= 20);

        $out['crash_current_clicks']  = $current;
        $out['crash_previous_clicks'] = $previous;

        /* Zeroed when it is not a crash, as the cron does. A drop
           percentage sitting in the payload of an email that was not about
           a drop would read as though it had been. */
        $out['crash_drop_pct']              = $isCrash ? $drop : 0;
        $out['googlesearch_crash_detected'] = $isCrash ? 'yes' : 'no';

    } catch (Throwable $e) {
        // zeros
    }

    return $out;
}


/* ==========================================================
   PAGE 1 BREAKTHROUGH

   The query that moved onto page one: worse than position 12
   last week, 10 or better this week, with enough impressions
   to be real. Same test as cron_page1_check.php.
========================================================== */
function gsc_payload_page1(PDO $pdo, string $instanceId): array
{
    $lag = GSC_PAYLOAD_LAG_DAYS;

    $curEnd    = date('Y-m-d', strtotime('-' . $lag . ' days'));
    $curStart  = date('Y-m-d', strtotime('-' . ($lag + 6) . ' days'));
    $prevEnd   = date('Y-m-d', strtotime('-' . ($lag + 7) . ' days'));
    $prevStart = date('Y-m-d', strtotime('-' . ($lag + 13) . ' days'));

    $out = [
        'page1_query'                  => '',
        'page1_position'               => '',
        'page1_old_position'           => '',
        'page1_page_path'              => '',
        'googlesearch_page1_detected'  => 'no',
    ];

    try {
        /* Impression-weighted position on both sides, so one stray day at
           position 3 cannot carry a week that otherwise sat on page two. */
        $q = $pdo->prepare("
            SELECT c.query_hash, c.query, c.pos AS cur_pos, p.pos AS prev_pos
            FROM (
                SELECT query_hash, MAX(query) AS query,
                       SUM(impressions) AS impr,
                       SUM(position * impressions) / NULLIF(SUM(impressions), 0) AS pos
                FROM gsc_query_daily
                WHERE instance_id = ? AND date BETWEEN ? AND ?
                GROUP BY query_hash
                HAVING impr >= 20 AND pos <= 10
            ) c
            JOIN (
                SELECT query_hash,
                       SUM(position * impressions) / NULLIF(SUM(impressions), 0) AS pos,
                       COUNT(*) AS days
                FROM gsc_query_daily
                WHERE instance_id = ? AND date BETWEEN ? AND ?
                GROUP BY query_hash
            ) p ON p.query_hash = c.query_hash
            WHERE p.pos > 12 AND p.days >= 2
            ORDER BY c.impr DESC
            LIMIT 20
        ");
        $q->execute([$instanceId, $curStart, $curEnd, $instanceId, $prevStart, $prevEnd]);

        /* The cron walks the results and takes the first query it has not
           already sent an email about, tracked in gsc_page1_seen. Taking
           the top row regardless would name a different query from the one
           the customer actually read about, on any site that has had more
           than one breakthrough. */
        $seen = $pdo->prepare("
            SELECT 1 FROM gsc_page1_seen
            WHERE instance_id = ? AND query_hash = ?
        ");

        $row = [];

        while ($candidate = $q->fetch(PDO::FETCH_ASSOC)) {
            try {
                $seen->execute([$instanceId, $candidate['query_hash']]);
                if ($seen->fetchColumn()) {
                    continue;
                }
            } catch (Throwable $e) {
                /* No gsc_page1_seen table here: fall back to the top row,
                   which is what the cron would have picked on a site with
                   no history. */
            }

            $row = $candidate;
            break;
        }

        if ($row) {
            $out['page1_query']                 = (string)$row['query'];
            $out['page1_position']              = (string)round((float)$row['cur_pos'], 1);
            $out['page1_old_position']          = (string)round((float)$row['prev_pos'], 1);
            $out['googlesearch_page1_detected'] = 'yes';

            /* The page that actually ranks for it, by impressions. */
            $p = $pdo->prepare("
                SELECT page FROM gsc_page_query_daily
                WHERE instance_id = ? AND query_hash = ? AND date BETWEEN ? AND ?
                GROUP BY page ORDER BY SUM(impressions) DESC LIMIT 1
            ");
            $p->execute([$instanceId, $row['query_hash'], $curStart, $curEnd]);
            $out['page1_page_path'] = (string)($p->fetchColumn() ?: '');
        }

    } catch (Throwable $e) {
        // nothing found is a normal answer here
    }

    return $out;
}

}
