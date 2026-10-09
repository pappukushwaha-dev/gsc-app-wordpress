<?php
/**
 * cron/backfill.php <instanceId> [days] [--force]   (WordPress)
 *
 * One-off historical pull for a single site. Reuses the exact
 * fetch/store/resolve functions from snapshot_daily.php, so there is no
 * second copy of that logic to keep in sync — it just drives them across
 * a wider date range.
 *
 *   php cron/backfill.php ecwid_abc123             # 90 days
 *   php cron/backfill.php ecwid_abc123 480         # 16 months
 *   php cron/backfill.php ecwid_abc123 90 --force  # re-pull days already marked done
 *
 * Safe to re-run: storage is upsert-based, and days already marked done
 * are skipped. Paces itself to stay well under the per-site quota.
 *
 * WHY THIS EXISTS
 * A user who connects today would otherwise see an empty Action
 * Center for about three weeks while the daily snapshot slowly
 * accumulates history. Backfilling on connect means their first visit
 * already has insights.
 *
 * It is not run by cron. maybe_start_backfill.php calls it in the
 * background the moment a property becomes verified. On the Wix side that
 * hook was missing for months and nobody noticed: 1,359 accounts were
 * connected and 14 had any history at all. Do not remove the hook.
 */

declare(strict_types=1);

// Load the snapshot cron's functions without running its instance loop.
define('SNAPSHOT_LIB_ONLY', true);
require __DIR__ . '/snapshot_daily.php';

// ------------------------------------------------------------------
// Arguments
// ------------------------------------------------------------------
$argsList   = array_slice($argv, 1);
$force      = in_array('--force', $argsList, true);
$positional = array_values(array_filter($argsList, static fn($a) => strpos($a, '--') !== 0));

$instanceId = $positional[0] ?? null;
$days       = isset($positional[1]) ? max(1, (int)$positional[1]) : 90;

if (!$instanceId) {
    fwrite(STDERR, "Usage: php cron/backfill.php <instanceId> [days] [--force]\n");
    exit(1);
}

/** @var PDO $pdo */
global $pdo;

// ------------------------------------------------------------------
// Account + property
// ------------------------------------------------------------------
$accCol = gscAccountInstanceColumn($pdo);

$stmt = $pdo->prepare("
    SELECT g.id AS account_id, v.site_url
    FROM google_accounts g
    JOIN gsc_domain_verifications v ON v.instance_id = g.`{$accCol}`
    WHERE g.`{$accCol}` = ?
      AND v.verification_status = 'verified'
      AND g.refresh_token IS NOT NULL
    LIMIT 1
");
$stmt->execute([$instanceId]);
$acct = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$acct) {
    fwrite(STDERR, "No verified, connected account for {$instanceId}\n");
    exit(1);
}

$tok = ensureAccessToken((int)$acct['account_id']);
if (empty($tok['success']) || empty($tok['access_token'])) {
    fwrite(STDERR, "Token failed: " . ($tok['error'] ?? 'unknown') . "\n");
    exit(1);
}
$token    = $tok['access_token'];
$property = gscPropertyId($acct['site_url'], $token);

echo "Backfill {$instanceId}\n";
echo "Property : {$property}\n";
echo "Window   : {$days} days (ending " . GSC_LAG_DAYS . " days ago)"
   . ($force ? "  [--force: ignoring days already marked done]" : "") . "\n";
echo str_repeat('-', 56) . "\n";

$done    = 0;
$skipped = 0;
$totalQ  = 0;
$totalPq = 0;

// Oldest first, so a partial run still leaves a contiguous recent block.
for ($back = GSC_LAG_DAYS + $days - 1; $back >= GSC_LAG_DAYS; $back--) {
    $date = date('Y-m-d', strtotime("-{$back} days"));

    // --force exists because a day is marked done even when Google
    // returned nothing, and that decision is permanent. For a site whose
    // traffic later grows, or one that was misconfigured at the time,
    // there was previously no way to ask again short of deleting rows
    // from gsc_snapshot_runs by hand.
    if (!$force && runIsDone($pdo, $instanceId, $date)) {
        $skipped++;
        continue;
    }

    markRun($pdo, $instanceId, $date, 'running');

    try {
        $qRows  = fetchGsc($property, $token, $date, ['query']);
        $pqRows = fetchGsc($property, $token, $date, ['page', 'query']);

        siteQueryRows($pdo, $instanceId, $date, $qRows);
        sitePageQueryRows($pdo, $instanceId, $date, $pqRows);

        markRun($pdo, $instanceId, $date, 'done', count($qRows), count($pqRows));

        $totalQ  += count($qRows);
        $totalPq += count($pqRows);
        $done++;

        echo "  {$date}: " . count($qRows) . " q, " . count($pqRows) . " pq\n";

    } catch (Throwable $e) {
        $msg = $e->getMessage();
        markRun($pdo, $instanceId, $date, 'failed', 0, 0, $msg);
        echo "  {$date}: FAILED — {$msg}\n";

        // Stop the whole run on a permission failure.
        //
        // This is the fix for what the Wix migration ran into. A 401 or
        // 403 means this account cannot read this property — not today,
        // not for any of the other 89 days. The old code kept going, so
        // one unauthorised site spent 90 iterations collecting 90
        // identical errors, and with the retry-and-sleep that used to sit
        // in fetchGsc it took long enough to be killed by an external
        // timeout. 209 instances were recorded as "timed out" when they
        // were really just permission-denied.
        //
        // Anything else — a network blip, one odd day — is still worth
        // stepping over, so only these two abort.
        if (preg_match('/GSC HTTP (401|403)/', $msg)) {
            echo "\nPermission denied for this property. Stopping — the remaining "
               . "days would fail the same way.\n";
            echo "The user needs to reconnect Google and grant Search Console access,\n";
            echo "or verify this property under the account they connected.\n";

            // Exit 2, not 0.
            //
            // Callers have to be able to tell "Google gave us nothing" from
            // "this account cannot read this property". Both end with zero
            // rows, but only the second is permanent, and only the second
            // should stop the daily snapshot from retrying forever.
            //
            // Reading the reason out of stdout does not work: the abort
            // message sits above two lines of "Next: ..." advice, and a
            // caller that samples the tail sees only those. A distinct exit
            // code is unambiguous.
            exit(2);
        }
    }

    // Pace: two calls per day. 0.4s keeps this far under 1,200 QPM per
    // site and is gentle on the shared project quota during a long run.
    usleep(400000);
}

echo str_repeat('-', 56) . "\n";
echo "Done. {$done} days pulled, {$skipped} already present.\n";
echo "Totals: {$totalQ} query rows, {$totalPq} page-query rows.\n";

// A site can pull all 90 days cleanly and still site nothing. Google
// withholds query-level rows for searches rare enough to identify a
// person, while still counting them in the totals a dashboard shows. So
// this is a real outcome, not a failure, and it is worth saying plainly
// rather than leaving someone to wonder why the table is empty.
if ($done > 0 && $totalQ === 0) {
    echo "\nGoogle returned no query-level rows for any day in this window.\n";
    echo "That usually means the site's search traffic is too low for Google to\n";
    echo "break it down by keyword. Totals may still appear in Search Console.\n";
}

echo "\nNext: php cron/build_ctr_curve.php {$instanceId}\n";
echo "Then: php cron/insight_engine.php {$instanceId}\n";
