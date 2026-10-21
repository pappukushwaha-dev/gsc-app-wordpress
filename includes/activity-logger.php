<?php
declare(strict_types=1);

/**
 * Lightweight USER-panel activity tracking: logins, logouts, page views
 * and key merchant actions (disconnect).
 *
 * One INSERT per event, wrapped so that tracking can never take the
 * product down: a missing table, a DB hiccup - anything - is swallowed
 * and the page renders as if the logger were not there.
 *
 * Refresh spam is filtered: an identical page_view (same instance + URL)
 * within 5 minutes is skipped.
 *
 * View the data in admin/activity-logs.php.
 * Table: user_activity_logs (see 01-user-activity-logs.sql).
 */

if (!function_exists('log_activity')) {

    /**
     * One activity row for the store in the session. page_view events are
     * de-duplicated: the same instance + URL inside 5 minutes logs once,
     * so refreshes do not flood the table.
     */
    function log_activity(string $actionType, string $pageUrl = ''): void
    {
        global $pdo;

        try {
            if (!isset($pdo) || !($pdo instanceof PDO)) {
                error_log('[activity-logger] skip: pdo unavailable');
                return;
            }

            $instanceId = (string) ($_SESSION['instance_id'] ?? $_SESSION['instanceid'] ?? '');
            if ($instanceId === '') {
                error_log('[activity-logger] skip: no instance in session');
                return;
            }

            $pageUrl = substr($pageUrl, 0, 500);

/* Static assets are never page views (missing ones are rewritten to
   the front controller - do not log them). */
if (preg_match('/\.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|otf|map|mp4|webm|json)$/i',
    (string) (parse_url($pageUrl, PHP_URL_PATH) ?? ''))) {
    return;
}

            /* Same page + refresh -> skip. A real visit after the window
               logs again, so repeated distinct visits are still tracked. */
            if ($actionType === 'page_view' && $pageUrl !== '') {
                $dup = $pdo->prepare(
                    "SELECT id FROM user_activity_logs
                     WHERE instance_id = :iid AND action_type = 'page_view' AND page_url = :page
                       AND created_at >= (NOW() - INTERVAL 5 MINUTE)
                     LIMIT 1"
                );
                $dup->execute([':iid' => $instanceId, ':page' => $pageUrl]);
                if ($dup->fetchColumn()) {
                    return; // refresh dedupe - normal, not an error
                }
            }

            /* Owner email for this store - resolved once per request and
               reused by the login + page_view calls that follow. */
            static $emailCache = null;
            if ($emailCache === null) {
                $emailCache = '';
                $stmt = $pdo->prepare("SELECT email FROM WpSite WHERE instance_id = :id LIMIT 1");
                $stmt->execute([':id' => $instanceId]);
                $emailCache = (string) ($stmt->fetchColumn() ?: '');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO user_activity_logs
                    (instance_id, email, action_type, page_url, ip_address, user_agent)
                 VALUES
                    (:iid, :email, :action, :page, :ip, :agent)'
            );
            $stmt->execute([
                ':iid'    => substr($instanceId, 0, 191),
                ':email'  => substr($emailCache, 0, 320),
                ':action' => $actionType,
                ':page'   => $pageUrl,
                ':ip'     => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                ':agent'  => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);
        } catch (Throwable $e) {
            // Tracking must never break the product - but the reason is
            // recorded so the admin can see why rows are missing.
            error_log('[activity-logger] ' . $actionType . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Called once per page render from the user-panel layouts and pages.
     * Logs a 'login' the first time a session is seen, then a
     * 'page_view' for the page being opened.
     */
    function track_page_activity(): void
    {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                return;
            }

            $instanceId = $_SESSION['instance_id'] ?? $_SESSION['instanceid'] ?? null;
            if (!$instanceId) {
                return;
            }

            if (empty($_SESSION['_activity_login_logged'])) {
                $_SESSION['_activity_login_logged'] = 1;
                log_activity('login');
            }

            log_activity('page_view', (string) ($_SERVER['REQUEST_URI'] ?? ''));
        } catch (Throwable $e) {
            // never break the page
        }
    }
}
