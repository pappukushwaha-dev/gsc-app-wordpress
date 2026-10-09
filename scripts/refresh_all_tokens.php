<?php
declare(strict_types=1);

/**
 * Refresh Google OAuth tokens that are expired or expiring soon
 * Safe for cron + browser execution
 */

// ------------------------------------------------------------
// Bootstrap
// ------------------------------------------------------------
$root = realpath(__DIR__ . '/..'); // ../searchconsole
require_once $root . '/includes/config.php';
require_once $root . '/includes/google/token_manager.php';
require_once $root . '/includes/google/get_account.php';

global $pdo;

// ------------------------------------------------------------
// Defaults
// ------------------------------------------------------------
$mode            = 'connected'; // connected|expired|disconnected|all
$force           = false;
$instanceFilter  = null;
$limit           = null;
$offset          = 0;
$windowMinutes   = 15; // refresh tokens expiring in next X minutes

// ------------------------------------------------------------
// CLI options
// ------------------------------------------------------------
if (PHP_SAPI === 'cli') {
    $opts = getopt('', [
        'mode:',
        'force',
        'instance:',
        'limit:',
        'offset:',
        'window:'
    ]);

    $mode           = $opts['mode']     ?? $mode;
    $force          = isset($opts['force']);
    $instanceFilter = $opts['instance'] ?? null;
    $limit          = isset($opts['limit'])  ? (int)$opts['limit']  : null;
    $offset         = isset($opts['offset']) ? (int)$opts['offset'] : 0;
    $windowMinutes  = isset($opts['window']) ? (int)$opts['window'] : $windowMinutes;
}

// ------------------------------------------------------------
// Browser fallback (?mode=connected&window=20)
// ------------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    $mode           = $_GET['mode']     ?? $mode;
    $force          = isset($_GET['force']) ? true : $force;
    $instanceFilter = $_GET['instance'] ?? $instanceFilter;
    $limit          = isset($_GET['limit'])  ? (int)$_GET['limit']  : $limit;
    $offset         = isset($_GET['offset']) ? (int)$_GET['offset'] : $offset;
    $windowMinutes  = isset($_GET['window']) ? (int)$_GET['window'] : $windowMinutes;
}

// Safety limits
$windowMinutes = max(1, min(120, $windowMinutes));

// ------------------------------------------------------------
// Header
// ------------------------------------------------------------
echo "[" . date('c') . "] Refresh tokens starting\n";
echo "Options: mode={$mode}, force=" . ($force ? '1' : '0')
   . ", window={$windowMinutes}min"
   . ", instance=" . ($instanceFilter ?? 'ALL')
   . ", limit=" . ($limit ?? 'ALL')
   . ", offset={$offset}\n";

// ------------------------------------------------------------
// Validate mode
// ------------------------------------------------------------
$validModes = ['connected', 'expired', 'disconnected', 'all'];
if (!in_array($mode, $validModes, true)) {
    echo "Invalid mode\n";
    exit(1);
}

// ------------------------------------------------------------
// CSV logs
// ------------------------------------------------------------
$tmpDir = $root . '/tmp';
@mkdir($tmpDir, 0755, true);

$successCsv = $tmpDir . '/refresh_success.csv';
$failedCsv  = $tmpDir . '/refresh_failed.csv';

if (!file_exists($successCsv)) {
    file_put_contents($successCsv, "id,instanceId,email,action,expires_at,ts\n");
}
if (!file_exists($failedCsv)) {
    file_put_contents($failedCsv, "id,instanceId,email,error,http,response,ts\n");
}

// ------------------------------------------------------------
// Build SQL
// ------------------------------------------------------------
$params = [];
$sql = "SELECT id, instanceId, email, token_expires_at, connected FROM google_accounts";

$where = [];

if ($mode === 'connected') {
    $where[] = "connected = 1";
    if (!$force) {
        $where[] = "
            (
                token_expires_at IS NULL
                OR token_expires_at <= DATE_ADD(NOW(), INTERVAL {$windowMinutes} MINUTE)
            )
        ";
    }
}

if ($mode === 'expired') {
    $where[] = "(token_expires_at IS NULL OR token_expires_at < NOW())";
}

if ($mode === 'disconnected') {
    $where[] = "connected = 0";
}

if ($instanceFilter) {
    $where[] = "instanceId = :instanceId";
    $params[':instanceId'] = $instanceFilter;
}

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY id ASC";

if ($limit) {
    $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
}

// ------------------------------------------------------------
// Fetch rows
// ------------------------------------------------------------
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
echo "Found {$total} account(s) to refresh\n";

// ------------------------------------------------------------
// Process
// ------------------------------------------------------------
$i = 0;
foreach ($rows as $r) {
    $i++;
    $id         = (int)$r['id'];
    $instanceId = $r['instanceId'] ?? '';
    $email      = $r['email'] ?? '';
    $connected  = (int)$r['connected'];

    echo "[{$i}/{$total}] {$email} ... ";

    try {
        if ($connected === 1) {
            // Main path: connected accounts
            $res = ensureAccessToken($id, 60);

            if (is_array($res) && ($res['success'] ?? false)) {
                echo "OK\n";
                $expires = $res['token_expires_at'] ?? '';
                file_put_contents(
                    $successCsv,
                    "{$id},{$instanceId},{$email},refresh_ok,{$expires}," . date('c') . "\n",
                    FILE_APPEND
                );
            } else {
                $err = is_array($res) ? ($res['error'] ?? 'unknown') : 'unknown';
                echo "FAILED ({$err})\n";
                file_put_contents(
                    $failedCsv,
                    "{$id},{$instanceId},{$email},{$err}," . ($res['http'] ?? '') . "," . json_encode($res) . "," . date('c') . "\n",
                    FILE_APPEND
                );
            }
        }
    } catch (Throwable $e) {
        echo "EXCEPTION\n";
        file_put_contents(
            $failedCsv,
            "{$id},{$instanceId},{$email},exception,," . addslashes($e->getMessage()) . "," . date('c') . "\n",
            FILE_APPEND
        );
    }

    usleep(200000); // 200ms
}

echo "[" . date('c') . "] Done\n";
exit(0);
