<?php
/**
 * api/insights/index.php  (WordPress)
 *
 * Ported from the Wix app. The request/response contract is unchanged, so
 * action-center.php and the navbar bell work against it as they are.
 *
 * Serves the Action Center page and the navbar button.
 * Reads ONLY from the local database — never calls Google — so it is
 * fast and cannot consume Search Console quota however often it is polled.
 *
 * All requests are POST with a JSON body:
 *
 *   { "action": "summary" }
 *       -> { success, summary: { unread, open_count, at_risk, available, recovered } }
 *
 *   { "action": "feed", "status": "open", "severity": "all", "type": "all", "limit": 50 }
 *       -> { success, rows: [ ... ] }
 *
 *   { "action": "status", "id": 12, "to": "read|actioned|dismissed|new" }
 *       -> { success }
 *
 *   { "action": "mark_all" }
 *       -> { success, updated }
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

/** @var PDO $pdo */
global $pdo;

// ---------------------------------------------------------------
// Auth — every query is scoped to this instance, so one tenant can
// never read or modify another tenant's insights.
// ---------------------------------------------------------------
// Both spellings. This codebase sets the key inconsistently — some files
// write 'instanceid', others 'instance_id' — and reading only one means
// whichever the sign-in path happened to set decides whether the Action
// Center works at all.
$instanceId = $_SESSION['instanceid']
    ?? $_SESSION['instance_id']
    ?? null;

if (!$instanceId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'not_authenticated']);
    exit;
}

$raw    = file_get_contents('php://input') ?: '';
$in     = json_decode($raw, true);
$in     = is_array($in) ? $in : [];
$action = isset($in['action']) ? (string)$in['action'] : 'feed';

try {
    switch ($action) {

        // ===========================================================
        // SUMMARY — badge count + the four ledger numbers
        // ===========================================================
        case 'summary': {
            $sql = "
                SELECT
                    COALESCE(SUM(status = 'new'), 0)                       AS unread,
                    COALESCE(SUM(status IN ('new','read','actioned')), 0)  AS open_count,
                    COALESCE(SUM(
                        CASE WHEN status IN ('new','read','actioned') AND impact_clicks < 0
                             THEN -impact_clicks ELSE 0 END), 0)           AS at_risk,
                    COALESCE(SUM(
                        CASE WHEN status IN ('new','read','actioned') AND impact_clicks > 0
                             THEN impact_clicks ELSE 0 END), 0)            AS available,
                    COALESCE(SUM(
                        CASE WHEN status = 'resolved'
                             THEN ABS(impact_clicks) ELSE 0 END), 0)       AS recovered
                FROM insights
                WHERE instance_id = ?
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$instanceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            echo json_encode([
                'success' => true,
                'summary' => [
                    'unread'     => (int)($row['unread']     ?? 0),
                    'open_count' => (int)($row['open_count'] ?? 0),
                    'at_risk'    => (int)($row['at_risk']    ?? 0),
                    'available'  => (int)($row['available']  ?? 0),
                    'recovered'  => (int)($row['recovered']  ?? 0),
                ],
            ]);
            break;
        }

        // ===========================================================
        // FEED — filtered list, ordered by open-first then by money
        // ===========================================================
        case 'feed': {
            $status   = isset($in['status'])   ? (string)$in['status']   : 'open';
            $severity = isset($in['severity']) ? (string)$in['severity'] : 'all';
            $type     = isset($in['type'])     ? (string)$in['type']     : 'all';
            $limit    = isset($in['limit'])    ? (int)$in['limit']       : 50;
            $limit    = max(1, min(200, $limit));

            // whitelist everything that reaches SQL
            $validStatus = ['open','all','new','read','actioned','dismissed','resolved'];
            $validSev    = ['all','critical','high','medium','low'];
            // 'broken' and 'redirect' were missing from the Wix copy of this
            // list, and insight_engine.php produces both — detectCtrOpportunities
            // raises them when a still-ranking page turns out to 404 or
            // redirect. Filtering by either silently fell through to 'all',
            // so those tabs quietly showed everything instead.
            $validType   = ['all','ctr','drop','newkw','broken','redirect','indexing'];

            if (!in_array($status, $validStatus, true))   $status   = 'open';
            if (!in_array($severity, $validSev, true))    $severity = 'all';
            if (!in_array($type, $validType, true))       $type     = 'all';

            $where  = ['instance_id = ?'];
            $params = [$instanceId];

            if ($status === 'open') {
                $where[] = "status IN ('new','read','actioned')";
            } elseif ($status !== 'all') {
                $where[]  = 'status = ?';
                $params[] = $status;
            }

            if ($severity !== 'all') {
                $where[]  = 'severity = ?';
                $params[] = $severity;
            }

            if ($type !== 'all') {
                $where[]  = 'type = ?';
                $params[] = $type;
            }

            $sql = "
                SELECT id, type, entity, title, severity, impact_clicks,
                       what_json, why_text, rec_text, action_json, status,
                       occurrences, first_seen, last_seen, resolved_note, draft_json
                FROM insights
                WHERE " . implode(' AND ', $where) . "
                ORDER BY
                    (status IN ('new','read','actioned')) DESC,
                    FIELD(severity, 'critical', 'high', 'medium', 'low'),
                    ABS(impact_clicks) DESC,
                    id DESC
                LIMIT {$limit}
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = [
                    'id'            => (int)$r['id'],
                    'type'          => $r['type'],
                    'entity'        => $r['entity'],
                    'title'         => $r['title'],
                    'severity'      => $r['severity'],
                    'impact_clicks' => (int)$r['impact_clicks'],
                    'what'          => json_decode((string)$r['what_json'], true) ?: [],
                    'actions'       => json_decode((string)$r['action_json'], true) ?: null,
                    'why_text'      => $r['why_text'],
                    'rec_text'      => $r['rec_text'],
                    'status'        => $r['status'],
                    'occurrences'   => (int)$r['occurrences'],
                    'first_seen'    => $r['first_seen'],
                    'last_seen'     => $r['last_seen'],
                    'resolved_note' => $r['resolved_note'],
                    'draft'         => $r['draft_json']
                                        ? json_decode((string)$r['draft_json'], true)
                                        : null,
                ];
            }

            echo json_encode(['success' => true, 'rows' => $rows]);
            break;
        }

        // ===========================================================
        // DRAFT — save the title/description the user is writing
        // ===========================================================
        case 'draft': {
            $id    = isset($in['id']) ? (int)$in['id'] : 0;
            $dTtl  = isset($in['title']) ? trim((string)$in['title']) : '';
            $dDesc = isset($in['description']) ? trim((string)$in['description']) : '';

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'bad_request']);
                break;
            }

            // Length caps mirror the column size, not Google's display limit —
            // the UI warns about truncation, it does not block the user.
            $dTtl  = mb_substr($dTtl, 0, 300);
            $dDesc = mb_substr($dDesc, 0, 600);

            $stmt = $pdo->prepare("SELECT id FROM insights WHERE id = ? AND instance_id = ?");
            $stmt->execute([$id, $instanceId]);
            if ($stmt->fetchColumn() === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'not_found']);
                break;
            }

            $payload = ($dTtl === '' && $dDesc === '')
                ? null
                : json_encode(['title' => $dTtl, 'description' => $dDesc],
                              JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $pdo->prepare("UPDATE insights SET draft_json = ? WHERE id = ? AND instance_id = ?")
                ->execute([$payload, $id, $instanceId]);

            echo json_encode(['success' => true]);
            break;
        }

        // ===========================================================
        // STATUS — lifecycle change from a card button
        // ===========================================================
        case 'status': {
            $id = isset($in['id']) ? (int)$in['id'] : 0;
            $to = isset($in['to']) ? (string)$in['to'] : '';

            // 'resolved' is deliberately not user-settable — only the
            // insight engine closes an insight, after re-measuring it.
            $allowed = ['new', 'read', 'actioned', 'dismissed'];

            if ($id <= 0 || !in_array($to, $allowed, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'bad_request']);
                break;
            }

            $stmt = $pdo->prepare("SELECT status FROM insights WHERE id = ? AND instance_id = ?");
            $stmt->execute([$id, $instanceId]);
            $from = $stmt->fetchColumn();

            if ($from === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'not_found']);
                break;
            }

            // Dismissing hides the insight for 30 days. The engine may bring
            // it back sooner if the impact grows materially.
            $suppressUntil = ($to === 'dismissed')
                ? date('Y-m-d', strtotime('+30 days'))
                : null;

            $pdo->prepare("
                UPDATE insights
                SET status = ?, suppress_until = ?
                WHERE id = ? AND instance_id = ?
            ")->execute([$to, $suppressUntil, $id, $instanceId]);

            $pdo->prepare("
                INSERT INTO insight_events (insight_id, from_status, to_status, note)
                VALUES (?, ?, ?, 'user action')
            ")->execute([$id, $from, $to]);

            echo json_encode(['success' => true, 'from' => $from, 'to' => $to]);
            break;
        }

        // ===========================================================
        // MARK ALL — clears the badge without changing anything else
        // ===========================================================
        case 'mark_all': {
            $stmt = $pdo->prepare("
                UPDATE insights
                SET status = 'read'
                WHERE instance_id = ? AND status = 'new'
            ");
            $stmt->execute([$instanceId]);

            echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
            break;
        }

        // ===========================================================
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'unknown_action']);
    }

} catch (Throwable $e) {
    error_log('[api/insights] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}