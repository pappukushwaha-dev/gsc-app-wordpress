<?php
declare(strict_types=1);

/**
 * scripts/cron_payload_queue.php  (WordPress)
 *
 * Fills in the payload for emails the webhook logged.
 *
 * The webhook records the send and nothing more, because the weekly digest
 * reaches around a thousand sites in one window and building each payload
 * inside the request would spawn a thousand PHP processes on a box already
 * running about a hundred cron jobs a minute. This runs on a schedule and
 * takes a fixed number of rows, so the same work happens at a pace that is
 * chosen rather than whatever Encharge happens to do.
 *
 * Crontab, every 30 minutes:
 *
 *   5,35 * * * * cd /var/www/html/wordpress/googlesearchconsole && /usr/bin/php scripts/cron_payload_queue.php >> /var/log/gsc_payload_queue_wordpress.log 2>&1
 *
 * Arguments:
 *
 *   --limit=20     rows this run, default BATCH_SIZE
 *   --type=x       only this email_type
 *   --id=123       one row, ignores state and attempts. For testing
 *   --dry          work it out and print, write nothing
 */

$started = microtime(true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/email_payload.php';

/* How many rows per run.
 *
 * At 20 every 30 minutes a thousand digests take about a day to drain,
 * which is well inside the week before the next one. Raise it if the queue
 * is not keeping up, but watch the load first: a weekly_digest row spawns
 * a PHP process, and the other types do not. */
const BATCH_SIZE = 20;

/* Give up after this many tries. A row that has failed three times is
 * failing for a reason that another attempt will not change, and leaving
 * it in the queue hides the rows that could still succeed. */
const MAX_ATTEMPTS = 3;

/* Types the builder knows. Anything else is marked skipped on sight rather
 * than attempted three times first. */
const KNOWN_TYPES = ['weekly_digest', 'first_report', 'crash_alert', 'page1_breakthrough'];

$logFile = __DIR__ . '/cron_payload_queue.log';

/* ---------- arguments ---------- */
$limit    = BATCH_SIZE;
$onlyType = null;
$onlyId   = 0;
$dry      = false;

foreach (array_slice($argv ?? [], 1) as $a) {
    if ($a === '--dry') {
        $dry = true;
    } elseif (strpos($a, '--limit=') === 0) {
        $limit = max(1, min(200, (int)substr($a, 8)));
    } elseif (strpos($a, '--type=') === 0) {
        $onlyType = substr($a, 7);
    } elseif (strpos($a, '--id=') === 0) {
        $onlyId = (int)substr($a, 5);
    }
}

/* ---------- one at a time ----------
 *
 * Every 30 minutes is comfortable for 20 rows, but a slow digest build can
 * run long, and two overlapping runs would claim the same rows and build
 * them twice. The lock is held for the process lifetime and released when
 * it exits, however it exits. */
$lockFile = __DIR__ . '/cron_payload_queue.lock';
$lock     = fopen($lockFile, 'c');

if ($lock === false) {
    qlog($logFile, 'cannot open lock file, exiting');
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    qlog($logFile, 'another run is still going, exiting');
    exit(0);
}

/* ---------- claim a batch ----------
 *
 * Claimed by raising the attempt count before any work starts, so a run
 * that dies mid-build does not leave the row looking untouched and
 * retryable forever. A row that genuinely needs another go gets it on the
 * next run, up to MAX_ATTEMPTS.
 *
 * Oldest first: the point of the queue is that nothing waits indefinitely,
 * and newest-first would starve a backlog whenever sends keep arriving. */
$where  = "is_payload_generated = 'no' AND payload_attempts < ?";
$params = [MAX_ATTEMPTS];

if ($onlyType !== null) {
    $where   .= " AND email_type = ?";
    $params[] = $onlyType;
}

if ($onlyId > 0) {
    /* Testing a single row: state and attempts are ignored on purpose. */
    $where  = "id = ?";
    $params = [$onlyId];
}

$sql = "SELECT id, email, instance_id, email_type, subject
        FROM encharge_email_logs
        WHERE {$where}
        ORDER BY id ASC
        LIMIT {$limit}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    qlog($logFile, 'nothing pending');
    exit(0);
}

$claim = $pdo->prepare("
    UPDATE encharge_email_logs
    SET payload_attempts = payload_attempts + 1
    WHERE id = ?
");

$done = $pdo->prepare("
    UPDATE encharge_email_logs
    SET payload = ?, is_payload_generated = 'yes',
        payload_error = '', payload_done_at = NOW()
    WHERE id = ?
");

$mark = $pdo->prepare("
    UPDATE encharge_email_logs
    SET is_payload_generated = ?, payload_error = ?
    WHERE id = ?
");

$built = 0;
$empty = 0;
$skip  = 0;
$fail  = 0;

foreach ($rows as $r) {
    $id         = (int)$r['id'];
    $instanceId = trim((string)$r['instance_id']);
    $type       = trim((string)$r['email_type']);

    /* ---- cannot be built, and never will be ---- */
    if ($instanceId === '') {
        $skip++;
        if (!$dry) {
            $mark->execute(['skipped', 'no instance_id on the row', $id]);
        }
        qlog($logFile, "#{$id} skipped, no instance_id");
        continue;
    }

    if ($type === '' || !in_array($type, KNOWN_TYPES, true)) {
        $skip++;
        if (!$dry) {
            $mark->execute(['skipped', 'no builder for type "' . $type . '"', $id]);
        }
        qlog($logFile, "#{$id} skipped, no builder for type '{$type}'");
        continue;
    }

    if (!$dry) {
        $claim->execute([$id]);
    }

    /* ---- build ---- */
    try {
        $payload = gsc_email_payload($pdo, $instanceId, $type);

    } catch (Throwable $e) {
        $fail++;
        if (!$dry) {
            $mark->execute(['no', mb_substr($e->getMessage(), 0, 255), $id]);
        }
        qlog($logFile, "#{$id} error: " . $e->getMessage());
        continue;
    }

    /* An empty result is not an error. A site with no Search Console rows
       yet genuinely has nothing to report, and the attempt counter will
       retire the row after MAX_ATTEMPTS rather than keeping it in the
       queue. */
    if (!$payload) {
        $empty++;

        if (!$dry) {
            $attempts = (int)$pdo->query(
                "SELECT payload_attempts FROM encharge_email_logs WHERE id = {$id}"
            )->fetchColumn();

            if ($attempts >= MAX_ATTEMPTS) {
                $mark->execute(['failed', 'builder returned nothing', $id]);
            } else {
                $mark->execute(['no', 'builder returned nothing', $id]);
            }
        }

        qlog($logFile, "#{$id} {$type} returned nothing");
        continue;
    }

    $built++;

    if ($dry) {
        echo "#{$id} {$type} {$r['email']} -> " . count($payload) . " fields\n";
        continue;
    }

    $done->execute([
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $id,
    ]);

    qlog($logFile, "#{$id} {$type} built, " . count($payload) . " fields");
}

$secs = round(microtime(true) - $started, 1);

$summary = "run: looked at " . count($rows)
         . ", built {$built}, empty {$empty}, skipped {$skip}, errored {$fail}"
         . ", {$secs}s" . ($dry ? ' (dry)' : '');

qlog($logFile, $summary);
echo $summary . "\n";

/* How much is left, so the log says whether the batch size is keeping up
   without anyone having to go and ask. */
try {
    $left = (int)$pdo->query("
        SELECT COUNT(*) FROM encharge_email_logs
        WHERE is_payload_generated = 'no' AND payload_attempts < " . MAX_ATTEMPTS
    )->fetchColumn();

    qlog($logFile, "pending after this run: {$left}");
    echo "pending: {$left}\n";
} catch (Throwable $e) {
    // the count is nice to have, not worth failing the run over
}

exit(0);


function qlog(string $file, string $line): void
{
    @file_put_contents($file, date('Y-m-d H:i:s') . ' | ' . $line . PHP_EOL, FILE_APPEND);

    if (is_file($file) && filesize($file) > 1048576) {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines !== false && count($lines) > 3000) {
            @file_put_contents($file, implode(PHP_EOL, array_slice($lines, -3000)) . PHP_EOL);
        }
    }
}
