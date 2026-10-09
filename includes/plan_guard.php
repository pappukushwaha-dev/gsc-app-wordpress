<?php
declare(strict_types=1);

/**
 * includes/plan_guard.php
 *
 * Reusable plan / feature guard helpers.
 *
 * Usage:
 *  - For APIs: require_once 'includes/plan_guard.php'; requirePlanAllowedForApi();
 *  - For pages:
 *      * Fatal behavior (old): require_once 'includes/plan_guard.php'; requirePlanAllowedForPage();
 *      * Non-fatal (render header/footer and decide in JS): require_once 'includes/plan_guard.php'; requirePlanAllowedForPage(false);
 *
 * This file expects either:
 *  - you already have a function get_db_connection() that returns a mysqli or PDO instance,
 *    OR
 *  - a config.php next to it that defines DB_HOST, DB_USER, DB_PASS, DB_NAME constants.
 *
 * Adjust the SQL / column names to match your schema if you change them later.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get instance id from session or request safely.
 * ✅ PRIORITIZES URL/POST parameters over session (for admin "Login Into Store" functionality)
 */
function getInstanceIdFromSessionOrRequest(): ?string {
    if (session_status() === PHP_SESSION_NONE) session_start();

    // ✅ PRIORITIZE URL/POST parameters first (allows admin to switch stores)
    // Check POST first (for form submissions)
    if (!empty($_POST['instance_id'])) {
        $v = trim((string)$_POST['instance_id']);
        if ($v !== '') return $v;
    }
    if (!empty($_POST['instanceId'])) {
        $v = trim((string)$_POST['instanceId']);
        if ($v !== '') return $v;
    }
    
    // Check GET parameters (for admin "Login Into Store" links)
    if (!empty($_GET['instance_id'])) {
        $v = trim((string)$_GET['instance_id']);
        if ($v !== '') return $v;
    }
    if (!empty($_GET['instanceid'])) {
        $v = trim((string)$_GET['instanceid']);
        if ($v !== '') return $v;
    }

    // Fallback to session (for normal user navigation)
    $sid = $_SESSION['instanceid'] ?? $_SESSION['instance_id'] ?? null;
    if ($sid !== null) {
        $sid = trim((string)$sid);
        if ($sid !== '') return $sid;
    }

    return null;
}

/**
 * Return a mysqli connection. If your app defines get_db_connection(), this will use it.
 * Otherwise it will try to include a config.php with DB_HOST/DB_USER/DB_PASS/DB_NAME.
 */
function plan_guard_get_db(): mysqli {
    // If user code defines a getter, use it (supports either mysqli or PDO)
    if (function_exists('get_db_connection')) {
        $conn = get_db_connection();
        if ($conn instanceof mysqli) return $conn;
        // if app returned PDO, we still fall back to config-based mysqli below
    }

    // Try to include a config file with DB_* constants
    $configCandidates = [
        __DIR__ . '/config.php',
        __DIR__ . '/../config.php',
        __DIR__ . '/../includes/config.php',
        __DIR__ . '/../../config.php',
    ];
    foreach ($configCandidates as $c) {
        if (file_exists($c)) {
            @include_once $c;
            break;
        }
    }

    if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS') || !defined('DB_NAME')) {
        // last resort: try to use environment variables
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $name = getenv('DB_NAME') ?: '';
    } else {
        $host = DB_HOST;
        $user = DB_USER;
        $pass = DB_PASS;
        $name = DB_NAME;
    }

    $mysqli = @new mysqli($host, $user, $pass, $name);
    if ($mysqli->connect_errno) {
        error_log('plan_guard: DB connection failed: ' . $mysqli->connect_error);
        throw new RuntimeException('Database connection failed for plan guard.');
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

/**
 * Check whether the instance's plan allows the schema feature.
 *
 * Rules:
 *  - If there's an active paid subscription in the allowed set -> allowed
 *  - Else if there's an active free trial -> allowed
 *  - Otherwise -> not allowed
 *
 * Note: plans containing 'basic' or billing_period 'lifetime'/'one_time' are treated as free (not allowed)
 */
function isPlanAllowedForSchemaFeature(?string $instanceId): bool {
    if (!$instanceId) return false;

    // Allowed plan rules (edit these values to match your DB's plan_name strings)
    $allowedPlanNames = ['grow']; // lowercase strings that should be allowed
    $allowedBillingPeriods = ['monthly', 'yearly'];

    // Prefer existing PDO connection if configured (your config.php often sets $pdo)
    global $pdo;
    if (!empty($pdo) && $pdo instanceof PDO) {
        try {
            $now = new DateTimeImmutable('now');

            // 1) check latest paid subscription
            // Explicitly include Grow plan even if billing_period is 'limited'
            $paidSql = "
                SELECT plan_name, billing_period, status, started_at, cancelled_at, expires_on
              FROM app_subscriptions
WHERE instance_id = :iid
  AND (
      -- Explicitly include Grow plan (even if billing_period is 'limited')
      LOWER(plan_name) LIKE '%grow%'
      OR
      -- Standard paid plans
      billing_period <> 'limited'
  )
ORDER BY started_at DESC
LIMIT 1

            ";
            $stmt = $pdo->prepare($paidSql);
            $stmt->execute([':iid' => $instanceId]);
            $paid = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($paid) {
                $pStarted   = !empty($paid['started_at']) ? new DateTimeImmutable($paid['started_at']) : null;
                $pExpires   = !empty($paid['expires_on']) ? new DateTimeImmutable($paid['expires_on']) : null;
                $pCancelled = !empty($paid['cancelled_at']) ? new DateTimeImmutable($paid['cancelled_at']) : null;

                if ($pCancelled !== null) {
                    $paidStatus = 'cancelled';
                } elseif ($pExpires !== null && $pExpires <= $now) {
                    $paidStatus = 'expired';
                } else {
                    $paidStatus = 'active';
                }

                // If cancelled, treat as free plan (not allowed)
                if ($paidStatus === 'cancelled') {
                    return false; // Cancelled plans should be treated as free
                }

                // Normalize DB fields
                $dbPlanName = isset($paid['plan_name']) ? mb_strtolower(trim($paid['plan_name'])) : '';
                $dbBillingPeriod = isset($paid['billing_period']) ? mb_strtolower(trim($paid['billing_period'])) : '';
                $dbStatus = isset($paid['status']) ? mb_strtolower(trim($paid['status'])) : '';

                // If active subscription and DB status active, decide allowance
                if ($paidStatus === 'active' && $dbStatus === 'active') {
                    // Check if it's Grow plan first - always allow Grow plan
                    $isGrowPlan = strpos($dbPlanName, 'grow') !== false;
                    
                    if ($isGrowPlan) {
                        // Grow plan is always allowed, regardless of billing_period
                        return true;
                    } elseif (strpos($dbPlanName, 'basic') !== false || $dbBillingPeriod === 'lifetime' || $dbBillingPeriod === 'one_time') {
                        // treat basic/lifetime/one_time as free (not allowed)
                        // not allowed — fall through to trial check
                    } else {
                        // allow only if plan_name or billing_period matches allowed lists
                        if (in_array($dbPlanName, $allowedPlanNames, true) || in_array($dbBillingPeriod, $allowedBillingPeriods, true)) {
                            return true;
                        }
                    }
                }
            }

            // 2) check free trial
            $trialSql = "
                SELECT status, started_at, expires_on, cancelled_at
                FROM app_free_trials
                WHERE instance_id = :iid
                LIMIT 1
            ";
            $stmt = $pdo->prepare($trialSql);
            $stmt->execute([':iid' => $instanceId]);
            $trial = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($trial) {
                $tStarted   = !empty($trial['started_at']) ? new DateTimeImmutable($trial['started_at']) : null;
                $tExpires   = !empty($trial['expires_on']) ? new DateTimeImmutable($trial['expires_on']) : null;
                $tCancelled = !empty($trial['cancelled_at']) ? new DateTimeImmutable($trial['cancelled_at']) : null;

                if ($tCancelled !== null) {
                    $trialStatus = 'cancelled';
                } elseif ($tExpires !== null && $tExpires <= $now) {
                    $trialStatus = 'expired';
                } else {
                    $trialStatus = 'active';
                }

                if ($trialStatus === 'active') {
                    return true;
                }
            }

            return false;

        } catch (Throwable $e) {
            error_log('plan_guard PDO check failed: ' . $e->getMessage());
            return false; // conservative
        }
    }

    // Fallback: use mysqli connection
    try {
        $db = plan_guard_get_db();
    } catch (Throwable $e) {
        error_log('plan_guard: fallback DB error: ' . $e->getMessage());
        return false;
    }

    // paid subscription check (mysqli)
    $paidSql = "
        SELECT plan_name, billing_period, status, started_at, cancelled_at, expires_on
        FROM app_subscriptions
        WHERE instance_id = ?
        ORDER BY started_at DESC, id DESC
        LIMIT 1
    ";
    $paid = null;
    if ($stmt = $db->prepare($paidSql)) {
        $stmt->bind_param('s', $instanceId);
        $stmt->execute();
        $res = $stmt->get_result();
        $paid = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    } else {
        $esc = $db->real_escape_string($instanceId);
        $res = $db->query("SELECT plan_name, billing_period, status, started_at, cancelled_at, expires_on FROM app_subscriptions WHERE instance_id = '{$esc}' ORDER BY started_at DESC, id DESC LIMIT 1");
        $paid = $res ? $res->fetch_assoc() : null;
    }

    if ($paid) {
        $now = new DateTimeImmutable('now');
        $pCancelled = !empty($paid['cancelled_at']) ? new DateTimeImmutable($paid['cancelled_at']) : null;
        $pExpires = !empty($paid['expires_on']) ? new DateTimeImmutable($paid['expires_on']) : null;

        if ($pCancelled !== null) {
            $paidStatus = 'cancelled';
        } elseif ($pExpires !== null && $pExpires <= $now) {
            $paidStatus = 'expired';
        } else {
            $paidStatus = 'active';
        }

        // If cancelled, treat as free plan (not allowed)
        if ($paidStatus === 'cancelled') {
            return false; // Cancelled plans should be treated as free
        }

        // Normalize DB fields
        $dbPlanName = isset($paid['plan_name']) ? mb_strtolower(trim($paid['plan_name'])) : '';
        $dbBillingPeriod = isset($paid['billing_period']) ? mb_strtolower(trim($paid['billing_period'])) : '';
        $dbStatus = isset($paid['status']) ? mb_strtolower(trim($paid['status'])) : '';

        if ($paidStatus === 'active' && $dbStatus === 'active') {
            if (strpos($dbPlanName, 'basic') !== false || $dbBillingPeriod === 'lifetime' || $dbBillingPeriod === 'one_time') {
                // not allowed
            } else {
                if (in_array($dbPlanName, $allowedPlanNames, true) || in_array($dbBillingPeriod, $allowedBillingPeriods, true)) {
                    return true;
                }
            }
        }
    }

    // trial check (mysqli)
    $trialSql = "SELECT status, started_at, expires_on, cancelled_at FROM app_free_trials WHERE instance_id = ? LIMIT 1";
    $trial = null;
    if ($stmt = $db->prepare($trialSql)) {
        $stmt->bind_param('s', $instanceId);
        $stmt->execute();
        $res = $stmt->get_result();
        $trial = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    } else {
        $esc = $db->real_escape_string($instanceId);
        $res = $db->query("SELECT status, started_at, expires_on, cancelled_at FROM app_free_trials WHERE instance_id = '{$esc}' LIMIT 1");
        $trial = $res ? $res->fetch_assoc() : null;
    }

    if ($trial) {
        $now = new DateTimeImmutable('now');
        $tCancelled = !empty($trial['cancelled_at']) ? new DateTimeImmutable($trial['cancelled_at']) : null;
        $tExpires = !empty($trial['expires_on']) ? new DateTimeImmutable($trial['expires_on']) : null;

        if ($tCancelled !== null) {
            $trialStatus = 'cancelled';
        } elseif ($tExpires !== null && $tExpires <= $now) {
            $trialStatus = 'expired';
        } else {
            $trialStatus = 'active';
        }

        if ($trialStatus === 'active') return true;
    }

    return false;
}

/**
 * Deny JSON response (for APIs)
 */
function denyJson(string $message = 'Access denied', int $code = 403): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

/**
 * Deny HTML (for rendered pages) — optionally show overlay markup minimal fallback
 */
function denyHtml(string $message = 'Access denied - upgrade required'): void {
    // minimal UI safe for AJAX-disabled clients; you can make this match your overlay markup
    http_response_code(403);
    $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Upgrade Required</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f8fafc;color:#111;padding:32px;}
  .box{max-width:720px;margin:48px auto;padding:24px;border-radius:12px;background:white;border:1px solid #e6e9ee;box-shadow:0 8px 24px rgba(16,24,40,0.06);}
  a.btn{display:inline-block;padding:10px 14px;border-radius:8px;background:#0ea5a4;color:white;text-decoration:none;}
</style>
</head>
<body>
<div class="box">
  <h1>Upgrade Required</h1>
  <p>{$message}</p>
  <p><a class="btn" href="/current-plan.php">View Plans</a></p>
</div>
</body>
</html>
HTML;
    echo $html;
    exit;
}

/**
 * Helper for API endpoints: call at top of any API that must be protected.
 * It will read instance id from JSON body (POST) or session/GET and return it when allowed.
 * If not allowed, it will send a 403 JSON and exit.
 */
function requirePlanAllowedForApi(): string {
    // try JSON body first (useful for fetch POSTs)
    $raw = file_get_contents('php://input');
    $body = [];
    if ($raw) {
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) $body = $tmp;
    }

    $instanceId = $body['instanceId'] ?? getInstanceIdFromSessionOrRequest();
    if (!$instanceId) {
        denyJson('Missing instance id', 400);
    }
    if (!isPlanAllowedForSchemaFeature((string)$instanceId)) {
        denyJson('Feature not available for current plan', 403);
    }
    return (string)$instanceId;
}

/**
 * Helper for pages (server-side): call near top of page to block access.
 *
 * Behavior:
 *  - Default ($fatal = true): exactly same as old behavior: if not allowed -> denyHtml() and exit.
 *  - Non-fatal ($fatal = false): returns the instanceId when present even if not allowed, but DOES NOT exit.
 *    This is useful if you want to render the full page (header/footer) and control UX with client-side overlay.
 *
 * Returns:
 *  - string instanceId (when present)
 *  - If no instance id: fatal behavior always denies; non-fatal will return empty string.
 */
function requirePlanAllowedForPage(bool $fatal = true): string {
    $instanceId = getInstanceIdFromSessionOrRequest();
    if (!$instanceId) {
        if ($fatal) {
            denyHtml('Missing instance id. Please open via your account.');
        } else {
            // non-fatal: return empty string so caller can decide
            return '';
        }
    }

    $allowed = false;
    try {
        $allowed = isPlanAllowedForSchemaFeature((string)$instanceId);
    } catch (Throwable $e) {
        error_log('plan_guard requirePlanAllowedForPage check error: ' . $e->getMessage());
        $allowed = false;
    }

    if (!$allowed) {
        if ($fatal) {
            denyHtml('This feature requires a paid plan. Please upgrade to access Schema Version Control.');
        } else {
            // non-fatal: return instance id (so page can render and decide in JS)
            return (string)$instanceId;
        }
    }

    return (string)$instanceId;
}

/**
 * getPlanAccessForPage
 *
 * Return a small plan summary for the current instance (safe to send to client).
 * Does NOT include instanceId.
 *
 * Return:
 *  [
 *    'instanceId'  => string|null,   // (server-side, retained for internal use)
 *    'plan' => [
 *       'type'        => 'paid'|'free'|'none',
 *       'status'      => 'active'|'expired'|'cancelled'|'none',
 *       'trialExists' => bool,
 *       'trialActive' => bool,
 *       'trialExpired'=> bool
 *    ],
 *    'allowed' => bool   // whether feature should be considered allowed
 *  ]
 */
function getPlanAccessForPage(): array {
    $instanceId = getInstanceIdFromSessionOrRequest();
    if (!$instanceId) {
        return [
            'instanceId' => null,
            'plan' => [
                'type' => 'none',
                'status' => 'none',
                'trialExists' => false,
                'trialActive' => false,
                'trialExpired' => false
            ],
            'allowed' => false
        ];
    }

    // Default return structure
    $plan = [
        'type' => 'none',
        'status' => 'none',
        'trialExists' => false,
        'trialActive' => false,
        'trialExpired' => false
    ];
    $allowed = false;

    // Allowed plan rules (edit to match your DB)
    $allowedPlanNames = ['grow']; // lowercase
    $allowedBillingPeriods = ['monthly', 'yearly'];

    // Prefer PDO if available
    global $pdo;
    try {
        $now = new DateTimeImmutable('now');

        if (!empty($pdo) && $pdo instanceof PDO) {
            // Check latest paid subscription
            // Explicitly include Grow plan even if billing_period is 'limited'
            $paidSql = "
                SELECT plan_name, billing_period, status, started_at, cancelled_at, expires_on
               FROM app_subscriptions
WHERE instance_id = :iid
  AND (
      -- Explicitly include Grow plan (even if billing_period is 'limited')
      LOWER(plan_name) LIKE '%grow%'
      OR
      -- Standard paid plans
      (billing_period <> 'limited' AND billing_period <> 'free')
  )
ORDER BY started_at DESC
LIMIT 1

            ";
            $stmt = $pdo->prepare($paidSql);
            $stmt->execute([':iid' => $instanceId]);
            $paid = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($paid) {
                $pCancelled = !empty($paid['cancelled_at']) ? new DateTimeImmutable($paid['cancelled_at']) : null;
                $pExpires = !empty($paid['expires_on']) ? new DateTimeImmutable($paid['expires_on']) : null;

                if ($pCancelled !== null) $paidStatus = 'cancelled';
                elseif ($pExpires !== null && $pExpires <= $now) $paidStatus = 'expired';
                else $paidStatus = 'active';

                // If cancelled, treat as free plan (not allowed)
                if ($paidStatus === 'cancelled') {
                    $plan['type'] = 'free';
                    $plan['status'] = 'cancelled';
                    $allowed = false; // Cancelled plans should be treated as free
                } else {
                    // default reflect DB data
                    $plan['type'] = 'paid';
                    $plan['status'] = $paidStatus;

                    // Normalize DB fields
                    $dbPlanName = isset($paid['plan_name']) ? mb_strtolower(trim($paid['plan_name'])) : '';
                    $dbBillingPeriod = isset($paid['billing_period']) ? mb_strtolower(trim($paid['billing_period'])) : '';
                    $dbStatus = isset($paid['status']) ? mb_strtolower(trim($paid['status'])) : '';

                    if ($paidStatus === 'active' && $dbStatus === 'active') {
                    // Check if it's Grow plan first - always allow Grow plan
                    $isGrowPlan = strpos($dbPlanName, 'grow') !== false;
                    
                    if ($isGrowPlan) {
                        // Grow plan is always allowed, regardless of billing_period
                        $allowed = true;
                    } elseif (strpos($dbPlanName, 'basic') !== false || $dbBillingPeriod === 'lifetime' || $dbBillingPeriod === 'one_time') {
                        // treat basic/lifetime as free for client UI
                        $plan['type'] = 'free';
                        $plan['status'] = 'none';
                        // $allowed remains false
                    } else {
                        if (in_array($dbPlanName, $allowedPlanNames, true) || in_array($dbBillingPeriod, $allowedBillingPeriods, true)) {
                            $allowed = true;
                        }
                    }
                }
                }
            }

            // Check trial
            $trialSql = "
                SELECT status, started_at, expires_on, cancelled_at
                FROM app_free_trials
                WHERE instance_id = :iid
                LIMIT 1
            ";
            $stmt = $pdo->prepare($trialSql);
            $stmt->execute([':iid' => $instanceId]);
            $trial = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($trial) {
                $tCancelled = !empty($trial['cancelled_at']) ? new DateTimeImmutable($trial['cancelled_at']) : null;
                $tExpires = !empty($trial['expires_on']) ? new DateTimeImmutable($trial['expires_on']) : null;

                if ($tCancelled !== null) $trialStatus = 'cancelled';
                elseif ($tExpires !== null && $tExpires <= $now) $trialStatus = 'expired';
                else $trialStatus = 'active';

                $plan['trialExists'] = true;
                $plan['trialActive'] = ($trialStatus === 'active');
                $plan['trialExpired'] = ($trialStatus === 'expired');

                // If trial active and not already allowed by paid, allow feature
                if ($plan['trialActive']) {
                    $allowed = true;
                    // If type was none, indicate 'free' with active trial
                    if ($plan['type'] === 'none') {
                        $plan['type'] = 'free';
                        $plan['status'] = 'active';
                    }
                }
            }

            // If neither paid nor trial active, ensure plan.type is set
            if ($plan['type'] === 'none') {
                // Treat absence as free
                $plan['type'] = 'free';
            }

            return [
                'instanceId' => (string)$instanceId,
                'plan' => $plan,
                'allowed' => (bool)$allowed
            ];
        }

        // Fallback to mysqli
        $db = plan_guard_get_db();

        // paid subscription (mysqli)
        $paidSql = "
            SELECT plan_name, billing_period, status, started_at, cancelled_at, expires_on
            FROM app_subscriptions
            WHERE instance_id = ?
            ORDER BY started_at DESC, id DESC
            LIMIT 1
        ";
        $paid = null;
        if ($stmt = $db->prepare($paidSql)) {
            $stmt->bind_param('s', $instanceId);
            $stmt->execute();
            $res = $stmt->get_result();
            $paid = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        } else {
            $esc = $db->real_escape_string($instanceId);
            $res = $db->query("SELECT plan_name, billing_period, status, started_at, cancelled_at, expires_on FROM app_subscriptions WHERE instance_id = '{$esc}' ORDER BY started_at DESC, id DESC LIMIT 1");
            $paid = $res ? $res->fetch_assoc() : null;
        }

        if ($paid) {
            $pCancelled = !empty($paid['cancelled_at']) ? new DateTimeImmutable($paid['cancelled_at']) : null;
            $pExpires = !empty($paid['expires_on']) ? new DateTimeImmutable($paid['expires_on']) : null;

            if ($pCancelled !== null) $paidStatus = 'cancelled';
            elseif ($pExpires !== null && $pExpires <= $now) $paidStatus = 'expired';
            else $paidStatus = 'active';

            // If cancelled, treat as free plan (not allowed)
            if ($paidStatus === 'cancelled') {
                $plan['type'] = 'free';
                $plan['status'] = 'cancelled';
                $allowed = false; // Cancelled plans should be treated as free
            } else {
                $plan['type'] = 'paid';
                $plan['status'] = $paidStatus;

                // Normalize DB fields
                $dbPlanName = isset($paid['plan_name']) ? mb_strtolower(trim($paid['plan_name'])) : '';
                $dbBillingPeriod = isset($paid['billing_period']) ? mb_strtolower(trim($paid['billing_period'])) : '';
                $dbStatus = isset($paid['status']) ? mb_strtolower(trim($paid['status'])) : '';

                if ($paidStatus === 'active' && $dbStatus === 'active') {
                    // Check if it's Grow plan first - always allow Grow plan
                    $isGrowPlan = strpos($dbPlanName, 'grow') !== false;
                    
                    if ($isGrowPlan) {
                        // Grow plan is always allowed, regardless of billing_period
                        $allowed = true;
                    } elseif (strpos($dbPlanName, 'basic') !== false || $dbBillingPeriod === 'lifetime' || $dbBillingPeriod === 'one_time') {
                        $plan['type'] = 'free';
                        $plan['status'] = 'none';
                    } else {
                        if (in_array($dbPlanName, $allowedPlanNames, true) || in_array($dbBillingPeriod, $allowedBillingPeriods, true)) {
                            $allowed = true;
                        }
                    }
                }
            }
        }

        // trial (mysqli)
        $trialSql = "SELECT status, started_at, expires_on, cancelled_at FROM app_free_trials WHERE instance_id = ? LIMIT 1";
        $trial = null;
        if ($stmt = $db->prepare($trialSql)) {
            $stmt->bind_param('s', $instanceId);
            $stmt->execute();
            $res = $stmt->get_result();
            $trial = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        } else {
            $esc = $db->real_escape_string($instanceId);
            $res = $db->query("SELECT status, started_at, expires_on, cancelled_at FROM app_free_trials WHERE instance_id = '{$esc}' LIMIT 1");
            $trial = $res ? $res->fetch_assoc() : null;
        }

        if ($trial) {
            $tCancelled = !empty($trial['cancelled_at']) ? new DateTimeImmutable($trial['cancelled_at']) : null;
            $tExpires = !empty($trial['expires_on']) ? new DateTimeImmutable($trial['expires_on']) : null;

            if ($tCancelled !== null) $trialStatus = 'cancelled';
            elseif ($tExpires !== null && $tExpires <= $now) $trialStatus = 'expired';
            else $trialStatus = 'active';

            $plan['trialExists'] = true;
            $plan['trialActive'] = ($trialStatus === 'active');
            $plan['trialExpired'] = ($trialStatus === 'expired');

            if ($plan['trialActive']) {
                $allowed = true;
                if ($plan['type'] === 'none') {
                    $plan['type'] = 'free';
                    $plan['status'] = 'active';
                }
            }
        }

        if ($plan['type'] === 'none') {
            $plan['type'] = 'free';
        }

        return [
            'instanceId' => (string)$instanceId,
            'plan' => $plan,
            'allowed' => (bool)$allowed
        ];

    } catch (Throwable $e) {
        error_log('plan_guard getPlanAccessForPage error: ' . $e->getMessage());
        // conservative fallback
        return [
            'instanceId' => (string)$instanceId,
            'plan' => [
                'type' => 'none',
                'status' => 'none',
                'trialExists' => false,
                'trialActive' => false,
                'trialExpired' => false
            ],
            'allowed' => false
        ];
    }
}

/**
 * whoamiResponseForClient
 *
 * Outputs a safe JSON payload with plan summary.
 * Does NOT include instanceId.
 */
function whoamiResponseForClient(): void {
    try {
        $info = getPlanAccessForPage();
        $plan = $info['plan'] ?? [
            'type' => 'none',
            'status' => 'none',
            'trialExists' => false,
            'trialActive' => false,
            'trialExpired' => false
        ];
        $allowed = (bool)($info['allowed'] ?? false);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'plan' => $plan,
            'allowed' => $allowed
        ]);
        exit;
    } catch (Throwable $e) {
        error_log('plan_guard whoamiResponseForClient error: ' . $e->getMessage());
        header('Content-Type: application/json; charset=utf-8', true, 500);
        echo json_encode(['success' => false, 'message' => 'server_error']);
        exit;
    }
}
/**
 * Render a locked-feature overlay (UI helper)
 * Safe for reuse in multiple pages
 */
function renderLockedOverlay(
    string $title = 'Upgrade Required',
    string $message = 'This feature is available on paid plans.'
): void {
    ?>
    <div class="absolute inset-0 z-10 bg-white/60 dark:bg-neutral-900/80 backdrop-blur-sm
                flex flex-col items-center justify-center text-center p-6 rounded-lg">
        <div class="w-12 h-12 bg-neutral-200 dark:bg-neutral-700 rounded-full flex items-center justify-center mb-3">
            <iconify-icon
                icon="solar:lock-keyhole-bold-duotone"
                class="text-2xl text-neutral-500">
            </iconify-icon>
        </div>

        <h6 class="font-semibold text-gray-900 dark:text-white">
            <?= htmlspecialchars($title) ?>
        </h6>

        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 mb-3">
            <?= htmlspecialchars($message) ?>
        </p>

        <a href="/current-plan.php"
           class="btn btn-sm bg-cstm-primary text-white rounded-md px-4 py-2">
            Upgrade Plan
        </a>
    </div>
    <?php
}

