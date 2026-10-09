<?php
// cron/backfill_catchup.php  (WordPress)
//
// One-time catch-up for sites that verified before the backfill hook
// existed. maybe_start_backfill.php only fires at the moment a property
// becomes verified, so every user already verified before it was
// installed has no history and no way to get any: snapshot_daily.php
// reaches back about five days, and insight_engine.php cannot report a
// trend it has no history for.
//
// This is exactly the hole the Wix app fell into — 1,359 connected
// accounts, 14 with data — and the same script cleared it there in about
// two days.
//
// Meant to run on cron for a day and then be REMOVED. It is a migration,
// not a permanent job.
//
// USAGE
//   php cron/backfill_catchup.php --status          how far along it is
//   php cron/backfill_catchup.php                   work until the budget runs out
//   php cron/backfill_catchup.php --workers=1       force it serial
//   php cron/backfill_catchup.php --retry-errors    queue the errors again
//
// CRON — every minute, not every five. A run holds a lock for as long as
// it works, so the extra firings find it busy and exit silently. The
// effect is that the next batch starts the moment the last one ends
// instead of waiting out the remainder of a slot.
//
//   * * * * * cd /var/www/html/wordpress/googlesearchconsole \
//       && /usr/bin/php cron/backfill_catchup.php >> /var/log/gsc_catchup_ecwid.log 2>&1

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

/**
 * Which column in google_accounts holds the instance id.
 *
 * The Wix app spells it `instanceId`. This codebase reaches that table
 * through getGoogleAccountByInstance() everywhere else, so the name was
 * never pinned down in a raw query — and a wrong guess would not throw.
 * It would return an empty queue, and this script would cheerfully report
 * "nothing left to backfill" while every site still had no data.
 */
function gscCatchupAccountColumn(PDO $pdo): string
{
    static $col = null;
    if ($col !== null) {
        return $col;
    }

    $names = $pdo->query("SHOW COLUMNS FROM google_accounts")->fetchAll(PDO::FETCH_COLUMN, 0);

    foreach (['instanceId', 'instance_id', 'shop', 'shop_domain'] as $candidate) {
        if (in_array($candidate, $names, true)) {
            return $col = $candidate;
        }
    }

    throw new RuntimeException(
        'google_accounts has no recognisable instance column. Found: ' . implode(', ', $names)
    );
}

// =====================================================================
// SETTINGS
// =====================================================================

// How many backfills run at once.
//
// The first version did one at a time on a fixed count of three per run,
// sized for an assumed 70 seconds each. The real spread turned out to be
// nothing like that: about 42% of instances have no verified account and
// finish in a tenth of a second, 43% take around 72 seconds, and the rest
// time out at the ceiling. Most of every five-minute slot was spent idle.
//
// Three at a time is deliberate restraint rather than a measured limit —
// the same Google credentials are in use by token refresh, sitemap
// submits and the setup wizard's live status check, and a migration
// should not be what makes those fail.
$WORKERS = 3;

// Stop starting new work after this many seconds; anything already
// running is allowed to finish. Keeps a run bounded without having to
// guess how many instances fit in the time.
$MAX_RUNTIME = 240;

// Hard ceiling per instance.
//
// A backstop, not the main defence. The ported backfill.php already
// stops itself on the first 401 or 403 rather than grinding through 90
// days of the same permission error — which is what produced 209 bogus
// "timeouts" on the Wix side.
//
// 300s leaves room for a large site with paginated results; a normal
// run is about 70 seconds.
//
// -k matters as much as the number: plain `timeout` sends only SIGTERM,
// and a process blocked inside a network call does not act on it, so
// timeout would wait for a death that never comes. SIGKILL follows.
$TIMEOUT      = 300;
$TIMEOUT_KILL = 30;

$DAYS     = 90;
$BACKFILL = __DIR__ . '/backfill.php';


// =====================================================================
// ARGUMENTS
// =====================================================================

$statusOnly  = false;
$retryErrors = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--status') {
        $statusOnly = true;
    } elseif ($arg === '--retry-errors') {
        $retryErrors = true;
    } elseif (preg_match('/^--workers=(\d+)$/', $arg, $m)) {
        $WORKERS = max(1, (int)$m[1]);
    } elseif (preg_match('/^--runtime=(\d+)$/', $arg, $m)) {
        $MAX_RUNTIME = max(10, (int)$m[1]);
    }
}

function say(string $msg): void
{
    echo '[catchup ' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}


// =====================================================================
// THE ATTEMPT LOG
//
// Progress is tracked by attempts, not by rows. Two ordinary cases never
// produce a row — a site with no Search traffic, and an account with no
// verified property — and a queue defined by "has no rows" would hand
// those back on every run forever.
// =====================================================================

$pdo->exec("
    CREATE TABLE IF NOT EXISTS gsc_backfill_log (
        instance_id   VARCHAR(191) NOT NULL,
        status        VARCHAR(20)  NOT NULL,
        rows_written  INT UNSIGNED NOT NULL DEFAULT 0,
        took_seconds  DECIMAL(7,1) NULL,
        message       TEXT NULL,
        attempted_at  DATETIME NOT NULL,
        PRIMARY KEY (instance_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($retryErrors) {
    $n = $pdo->exec("DELETE FROM gsc_backfill_log WHERE status = 'error'");
    say("cleared {$n} error rows — they will be picked up again");
    exit(0);
}


// =====================================================================
// PROGRESS
//
// Two filters here are lessons from the Wix run.
//
// NULL instance ids: google_accounts collects rows from installs that
// never completed. NULL matches neither LEFT JOIN, so those rows sat
// permanently at the head of the queue and crashed every run on a NOT
// NULL insert. They are excluded rather than logged — there is no
// instance to record an attempt against.
//
// Unverified properties: backfill.php refuses to run without one, so
// including them would mean hundreds of sites entering the queue only
// to fail instantly and be logged as no_account. Filtering them out here
// keeps the number honest — "remaining" means sites that can actually
// be backfilled.
// =====================================================================

$accCol = gscCatchupAccountColumn($pdo);

$WHERE = "
    JOIN gsc_domain_verifications v
      ON v.instance_id = ga.`{$accCol}`
     AND v.verification_status = 'verified'
    LEFT JOIN gsc_backfill_log b ON b.instance_id = ga.`{$accCol}`
    LEFT JOIN gsc_query_daily  q ON q.instance_id = ga.`{$accCol}`
    WHERE b.instance_id IS NULL
      AND q.instance_id IS NULL
      AND ga.`{$accCol}` IS NOT NULL
      AND ga.`{$accCol}` <> ''
";

$remaining = (int)$pdo->query("
    SELECT COUNT(*)
    FROM google_accounts ga
    {$WHERE}
")->fetchColumn();

if ($statusOnly) {
    $breakdown = $pdo->query(
        "SELECT status, COUNT(*) c FROM gsc_backfill_log GROUP BY status"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $withData = (int)$pdo->query(
        "SELECT COUNT(DISTINCT instance_id) FROM gsc_query_daily"
    )->fetchColumn();

    say("instances with data : {$withData}");
    say("remaining           : {$remaining}");
    say('attempted           : ok=' . ($breakdown['ok'] ?? 0)
        . '  empty=' . ($breakdown['empty'] ?? 0)
        . '  no_account=' . ($breakdown['no_account'] ?? 0)
        . '  error=' . ($breakdown['error'] ?? 0));

    if (($breakdown['error'] ?? 0) > 0) {
        say('run with --retry-errors to queue the error ones again');
    }
    exit(0);
}


// =====================================================================
// ONE RUN AT A TIME
//
// LOCK_NB so a run that finds the lock taken gives up immediately rather
// than queueing behind it. With cron firing every minute, a pile of
// waiting processes would be far worse than a skipped tick.
// =====================================================================

$lockHandle = fopen(sys_get_temp_dir() . '/gsc_backfill_catchup.lock', 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit(0);   // silent: at one run a minute, logging every skip is noise
}

if (!is_file($BACKFILL)) {
    say('ERROR: backfill.php not found at ' . $BACKFILL);
    exit(1);
}

if ($remaining === 0) {
    say('nothing left to backfill — remove this job from cron');
    exit(0);
}

$TIMEOUT_BIN = trim((string)shell_exec('command -v timeout 2>/dev/null'));
if ($TIMEOUT_BIN === '') {
    say('WARNING: no `timeout` binary found — a hung instance can stall a run');
}

say("remaining: {$remaining} — up to {$MAX_RUNTIME}s with {$WORKERS} workers");


// =====================================================================
// THE QUEUE
//
// Fetched once, generously, and consumed from memory. Only one process
// runs at a time, so nothing else can claim these while this run works
// through them.
// =====================================================================

// Refetched whenever it empties rather than filled once. A single fetch
// was fine for a four-minute cron tick, but it silently caps a long run:
// point this at 1,100 instances with a large budget and it would stop
// after the first pageful and report itself finished.
//
// Anything already attempted is in gsc_backfill_log, so the same query
// naturally returns the next lot and never hands back what it just did.
$queueSize = max($WORKERS * 8, 40);
$stmt = $pdo->prepare("
    SELECT ga.`{$accCol}`
    FROM google_accounts ga
    {$WHERE}
    LIMIT {$queueSize}
");

$fetchQueue = static function () use ($stmt): array {
    $stmt->execute();
    return array_values(array_filter(
        $stmt->fetchAll(PDO::FETCH_COLUMN, 0),
        static fn($id) => $id !== null && trim((string)$id) !== ''
    ));
};

$queue = $fetchQueue();

$record = $pdo->prepare("
    INSERT INTO gsc_backfill_log
        (instance_id, status, rows_written, took_seconds, message, attempted_at)
    VALUES (?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        status = VALUES(status), rows_written = VALUES(rows_written),
        took_seconds = VALUES(took_seconds), message = VALUES(message),
        attempted_at = NOW()
");


// =====================================================================
// RUNNING THEM
//
// backfill.php runs as a child process rather than being included: it was
// written standalone, and a fatal inside it would otherwise take this
// runner down and leave the same rows at the head of the queue.
//
// Child output goes to a temp file, not a pipe. A pipe that nobody reads
// fills up and blocks the child — with several running at once and no one
// draining them, that deadlocks the whole run.
// =====================================================================

$startedAt = microtime(true);
$workers   = [];
$tally     = ['ok' => 0, 'empty' => 0, 'no_account' => 0, 'error' => 0];
$processed = 0;

/** @return array{proc:resource,id:string,file:string,started:float} */
function launch(string $id, string $backfill, int $days, string $timeoutBin, int $timeout, int $killAfter): ?array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg($backfill) . ' '
         . escapeshellarg($id) . ' '
         . escapeshellarg((string)$days);

    if ($timeoutBin !== '') {
        $cmd = escapeshellarg($timeoutBin) . ' -k ' . $killAfter . ' ' . $timeout . ' ' . $cmd;
    }

    $file = tempnam(sys_get_temp_dir(), 'gscbf_');
    $desc = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $file, 'w'],
        2 => ['file', $file, 'a'],
    ];

    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        @unlink($file);
        return null;
    }

    return ['proc' => $proc, 'id' => $id, 'file' => $file, 'started' => microtime(true)];
}

while (true) {

    $budgetLeft = (microtime(true) - $startedAt) < $MAX_RUNTIME;

    // Out of queued work but still inside the budget — go back for more.
    if ($budgetLeft && !$queue && count($workers) < $WORKERS) {
        $queue = $fetchQueue();
        if ($queue) {
            say('queue refilled — ' . count($queue) . ' more');
        }
    }

    // Fill free slots while there is both time and work.
    while ($budgetLeft && count($workers) < $WORKERS && $queue) {
        $id = array_shift($queue);
        $w  = launch($id, $BACKFILL, $DAYS, $TIMEOUT_BIN, $TIMEOUT, $TIMEOUT_KILL);

        if ($w === null) {
            say("ERROR      {$id}  could not start a process");
            $tally['error']++;
            continue;
        }
        $workers[] = $w;
    }

    if (!$workers) {
        break;   // nothing running and nothing startable
    }

    foreach ($workers as $slot => $w) {
        $st = proc_get_status($w['proc']);
        if ($st['running']) {
            continue;
        }

        // exitcode is only valid on the first call after termination —
        // proc_close would report -1 once the child has been reaped.
        $code = $st['exitcode'];
        proc_close($w['proc']);

        $took   = round(microtime(true) - $w['started'], 1);
        $output = (string)@file_get_contents($w['file']);
        @unlink($w['file']);

        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), 'strlen'));
        $tail  = implode(' | ', array_slice($lines, -2));

        $rows = (int)$pdo->query(
            "SELECT COUNT(*) FROM gsc_query_daily WHERE instance_id = " . $pdo->quote($w['id'])
        )->fetchColumn();

        // Four endings, only one of which is a problem:
        //   ok          rows landed
        //   empty       ran cleanly; the site simply has no Search traffic
        //   no_account  no permission or no verified property — nothing to
        //               pull, ever, until the user reconnects
        //   error       something went wrong; worth retrying later
        //
        // Exit 2 is checked BEFORE the zero-exit cases, and it is the whole
        // reason backfill.php has a distinct code for this. A permission
        // abort and a genuinely empty site both finish with zero rows, and
        // reading the difference out of stdout does not work — the abort
        // message sits above two lines of "Next: ..." advice, so a tail
        // sample sees only those and calls it 'empty'.
        //
        // The label matters beyond bookkeeping: snapshot_daily.php skips
        // instances logged as no_account. Marking these 'empty' would leave
        // several hundred sites being hit with a 403 every single day
        // forever, which is exactly the waste this was meant to prevent.
        if ($code === 124 || $code === 137) {
            $status = 'error';
            $tail   = "timed out after {$TIMEOUT}s";
        } elseif ($code === 2) {
            $status = 'no_account';
            $tail   = 'permission denied on this property (GSC 401/403)';
        } elseif ($code === 0 && $rows > 0) {
            $status = 'ok';
        } elseif ($code === 0) {
            $status = 'empty';
        } elseif (stripos($tail, 'no verified') !== false
               || stripos($tail, 'not connected') !== false
               || stripos($tail, 'account_disconnected') !== false) {
            $status = 'no_account';
        } else {
            $status = 'error';
        }

        $tally[$status]++;
        $processed++;

        try {
            $record->execute([$w['id'], $status, $rows, $took, mb_substr($tail, 0, 500)]);
        } catch (Throwable $e) {
            // Recording must never take the run down. Worst case the
            // instance is attempted again on a later run.
            say("ERROR      {$w['id']}  could not record attempt: " . $e->getMessage());
        }

        if ($status !== 'ok') {
            say(str_pad(strtoupper($status), 10) . " {$w['id']}  {$rows} rows  {$took}s  {$tail}");
        }

        unset($workers[$slot]);
    }

    // Nothing to do but wait for a child; 200ms keeps this loop off the CPU.
    if ($workers) {
        usleep(200000);
    }
}

$elapsed = round(microtime(true) - $startedAt, 1);

// Read the real figure rather than subtracting: over a long run other
// things move too, and a number that drifts from --status is worse than
// no number at all.
$left = (int)$pdo->query("
    SELECT COUNT(*)
    FROM google_accounts ga
    {$WHERE}
")->fetchColumn();

say("finished — " . json_encode($tally)
    . "  processed: {$processed} in {$elapsed}s  remaining: {$left}");


// =====================================================================
// WHAT TO WATCH
//
// Only failures and oddities are logged per instance; a successful one is
// counted in the summary and otherwise stays quiet. At this rate a line
// each would bury the things worth reading.
//
// 'empty' and 'no_account' are expected and need nothing. If 'error'
// climbs, --retry-errors puts those back in the queue — a timeout or a
// momentary Google failure often succeeds on a second pass. If the same
// ones fail twice, read the message column in gsc_backfill_log instead of
// retrying a third time.
// =====================================================================
