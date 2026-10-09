<?php
// includes/google/maybe_start_backfill.php  (WordPress)
//
// Kicks off the 90-day history pull for a site, once.
//
// WHY THIS FILE MATTERS MORE THAN IT LOOKS
//
// On the Wix side backfill.php existed for months and nothing ever called
// it. The result: 1,359 connected accounts, 14 with any history at all.
// snapshot_daily.php only reaches back about five days, and
// insight_engine.php cannot report a trend it has no history for — so
// every new user landed in the same hole, and clearing it took a
// two-day migration.
//
// This is the hook that stops that happening here. Do not remove it.
//
// WHERE TO CALL IT
//
//   require_once __DIR__ . '/maybe_start_backfill.php';
//   gsc_maybe_start_backfill($pdo, $instanceId);
//
// Call it from EVERY place that sets verification_status = 'verified',
// not just one. On Wix that is four separate files, and hooking only one
// of them would have left every user who took a different path with
// no history — which was the original bug, in a smaller form.
//
// Calling it more than once is free: it returns immediately if the work
// is already done or already running, and it never throws.

declare(strict_types=1);

/**
 * @return string One of: started, already_have_data, already_running,
 *                no_property, error. Returned for logging; callers can
 *                ignore it.
 */
function gsc_maybe_start_backfill(PDO $pdo, string $instanceId, int $days = 90): string
{
    $instanceId = trim($instanceId);
    if ($instanceId === '') {
        return 'error';
    }

    try {
        // Which column in google_accounts holds the instance id. Resolved
        // once and cached in a global, because the reconnect check below
        // needs it and this file has no other reason to touch that table.
        if (!isset($GLOBALS['gsc_acct_col'])) {
            $GLOBALS['gsc_acct_col'] = null;
            try {
                $names = $pdo->query("SHOW COLUMNS FROM google_accounts")
                             ->fetchAll(PDO::FETCH_COLUMN, 0);
                foreach (['instanceId', 'instance_id', 'shop', 'shop_domain'] as $c) {
                    if (in_array($c, $names, true)) {
                        $GLOBALS['gsc_acct_col'] = $c;
                        break;
                    }
                }
            } catch (Throwable $e) {
                // leave it null — the reconnect retry just will not happen
            }
        }

        // -------------------------------------------------------------
        // 1. Already has history?
        //
        // save_token.php runs on every token refresh, not only the first
        // connect, so without this check a long-lived site would kick
        // off a 90-day pull every hour.
        //
        // LIMIT 1 rather than COUNT(*): this only needs to know whether a
        // row exists, and gsc_query_daily is the table that grows.
        // -------------------------------------------------------------
        $stmt = $pdo->prepare("SELECT 1 FROM gsc_query_daily WHERE instance_id = ? LIMIT 1");
        $stmt->execute([$instanceId]);
        if ($stmt->fetchColumn()) {
            return 'already_have_data';
        }

        // -------------------------------------------------------------
        // 2. Attempted before?
        //
        // A site recorded as no_account or empty has already been tried
        // and produced nothing; repeating it on every reconnect would
        // just repeat a known result. 'error' rows ARE allowed through —
        // those are worth one more go.
        //
        // Wrapped separately so a missing table can never block the
        // backfill.
        // -------------------------------------------------------------
        try {
            $stmt = $pdo->prepare("
                SELECT status, attempted_at
                FROM gsc_backfill_log
                WHERE instance_id = ?
                LIMIT 1
            ");
            $stmt->execute([$instanceId]);
            $prior = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($prior && in_array($prior['status'], ['ok', 'empty', 'no_account'], true)) {

                // no_account is permanent only while nothing changes. It
                // covers "this Google account cannot read this property",
                // and the user's fix for that is to reconnect with the
                // right account — which is precisely when this function
                // runs again.
                //
                // Without this the fix would be silently ignored: they
                // reconnect, we look up the old failure, and skip. So a
                // reconnect since the last attempt earns another try.
                $reconnected = false;

                if ($prior['status'] === 'no_account') {
                    $chk = $pdo->prepare("
                        SELECT 1 FROM google_accounts
                        WHERE {$GLOBALS['gsc_acct_col']} = ?
                          AND updated_at > ?
                        LIMIT 1
                    ");
                    // guarded: the column name is resolved below, and if
                    // that lookup failed we simply do not get the retry
                    if (!empty($GLOBALS['gsc_acct_col'])) {
                        $chk->execute([$instanceId, $prior['attempted_at']]);
                        $reconnected = (bool)$chk->fetchColumn();
                    }
                }

                if (!$reconnected) {
                    return 'already_have_data';
                }
            }
        } catch (Throwable $e) {
            // no log table, or no updated_at column — carry on rather than
            // block the backfill over bookkeeping
        }

        // -------------------------------------------------------------
        // 3. Is there a verified property yet?
        //
        // This is why the call belongs after verification as well as
        // after the token is saved. The wizard connects Google in step 1
        // and verifies the domain in step 2, so at token time this row
        // does not exist and backfill.php would exit with "No verified,
        // connected account".
        //
        // Returning quietly here makes the step-1 call harmless, and the
        // call after verification the one that does the work.
        // -------------------------------------------------------------
        $stmt = $pdo->prepare("
            SELECT verification_status
            FROM gsc_domain_verifications
            WHERE instance_id = ?
            LIMIT 1
        ");
        $stmt->execute([$instanceId]);
        $status = (string)$stmt->fetchColumn();

        if ($status !== 'verified') {
            return 'no_property';
        }

        // -------------------------------------------------------------
        // 4. Already running?
        //
        // A lock file rather than a database flag, so a crashed process
        // releases it automatically. A stuck backfill holding a DB flag
        // would block that site permanently.
        // -------------------------------------------------------------
        $lockPath = sys_get_temp_dir() . '/gsc_ecwid_bf_' . md5($instanceId) . '.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            return 'error';
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return 'already_running';
        }
        // Released as this request ends. The child below holds no lock of
        // its own; this only stops two overlapping web requests from
        // launching the same backfill twice.
        flock($lock, LOCK_UN);
        fclose($lock);

        // -------------------------------------------------------------
        // 5. Launch it in the background.
        //
        // A backfill takes roughly 70 seconds for an ordinary site.
        // Running it inline would leave the user staring at the setup
        // screen for that long, or hit PHP's max_execution_time and die
        // halfway through.
        //
        // The `timeout` wrapper is a backstop, not the main defence —
        // backfill.php now stops itself on a 401 or 403 rather than
        // grinding through 90 days of the same permission error. 300s
        // leaves room for a large site with paginated results; -k sends
        // SIGKILL a little after SIGTERM, because a process blocked in a
        // network call ignores the polite signal.
        // -------------------------------------------------------------
        $php     = PHP_BINARY ?: '/usr/bin/php';
        $script  = realpath(__DIR__ . '/../../cron/backfill.php');
        $timeout = trim((string)@shell_exec('command -v timeout 2>/dev/null'));

        if (!$script || !is_file($script)) {
            error_log('gsc_maybe_start_backfill: cron/backfill.php not found');
            return 'error';
        }

        $cmd = escapeshellarg($php) . ' '
             . escapeshellarg($script) . ' '
             . escapeshellarg($instanceId) . ' '
             . escapeshellarg((string)$days);

        if ($timeout !== '') {
            $cmd = escapeshellarg($timeout) . ' -k 30 300 ' . $cmd;
        }

        // Output to a log, and & to detach so the web request returns at
        // once. Without the redirect the child inherits this request's
        // output stream and can hold the connection open.
        $logFile = '/var/log/gsc_ecwid_newuser_backfill.log';
        if (!is_writable(dirname($logFile))) {
            $logFile = sys_get_temp_dir() . '/gsc_ecwid_newuser_backfill.log';
        }

        @exec($cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &');

        error_log("gsc_maybe_start_backfill: started for {$instanceId}");
        return 'started';

    } catch (Throwable $e) {
        // Never let this break a connect or a verification. A missing
        // backfill is a gap in one user's history; a fatal here would
        // stop them setting the app up at all.
        error_log('gsc_maybe_start_backfill failed for ' . $instanceId . ': ' . $e->getMessage());
        return 'error';
    }
}
