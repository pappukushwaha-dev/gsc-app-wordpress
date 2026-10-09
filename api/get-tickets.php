<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// --- CRITICAL: THESE MUST BE CORRECT (leave as-is) ---
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/tickets_min.php';
// ----------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    if (!isset($pdo) || !$pdo instanceof \PDO) {
        throw new \Exception("Database connection object (\$pdo) not initialized.");
    }

    // allow debug via ?debug=1
    $debug = (isset($_GET['debug']) && in_array($_GET['debug'], ['1','true','yes'], true));

    // read input (json body or querystring/post)
    $raw = file_get_contents('php://input');
    $body = $raw ? json_decode($raw, true) : null;
    if (!is_array($body)) {
        $body = array_merge($_GET, $_POST);
    }

    $instanceId = trim((string)($body['instance_id'] ?? $_SESSION['instanceid'] ?? $_SESSION['instance_id'] ?? ''));

    if ($instanceId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing instance_id']);
        exit;
    }

    // Fetch owner_email (optional but useful)
 $ownerEmail = 'N/A';

$emailStmt = $pdo->prepare("
    SELECT email
    FROM WpSite
    WHERE instance_id = :i
    LIMIT 1
");

$emailStmt->execute([':i' => $instanceId]);
$instanceRow = $emailStmt->fetch(PDO::FETCH_ASSOC);

if ($instanceRow && !empty($instanceRow['email'])) {
    $ownerEmail = $instanceRow['email'];
}


    // single ticket view?
    $ticketId = isset($body['ticket_id']) ? (int)$body['ticket_id'] : (isset($_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0);

    if ($ticketId > 0) {
        // verify belongs to instance
        $v = $pdo->prepare("SELECT id FROM tickets WHERE id = :tid AND instance_id = :i LIMIT 1");
        $v->execute([':tid' => $ticketId, ':i' => $instanceId]);
        if (!$v->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Ticket not found or does not belong to this instance']);
            exit;
        }

        // Use helper if available
        if (function_exists('getTicketWithMessagesMinimal')) {
            $res = getTicketWithMessagesMinimal($pdo, $ticketId, $instanceId);
            if (!is_array($res)) {
                $res = ['success' => false, 'error' => 'Helper function returned invalid data.'];
            }
            $res['owner_email'] = $ownerEmail;
            echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // fallback: simple single-ticket + messages (if helper missing)
        $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = :tid AND instance_id = :i LIMIT 1");
        $stmt->execute([':tid' => $ticketId, ':i' => $instanceId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $msgStmt = $pdo->prepare("SELECT id, ticket_id, instance_id, is_internal, message, created_at FROM ticket_messages WHERE ticket_id = :tid AND instance_id = :i ORDER BY created_at ASC");
        $msgStmt->execute([':tid' => $ticketId, ':i' => $instanceId]);
        $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'owner_email' => $ownerEmail,
            'ticket' => $ticket,
            'messages' => $messages
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------- List / Search / Pagination ----------
    $page = max(1, (int)($body['page'] ?? 1));
    $limit = max(1, min(100, (int)($body['limit'] ?? 25)));
    $searchQuery = trim((string)($body['search'] ?? ''));

    $offset = ($page - 1) * $limit;

    // Build WHERE (use distinct placeholders for repeated values)
    $whereSql = "WHERE t.instance_id = :i ";
    $params = [':i' => $instanceId];

    if ($searchQuery !== '') {
        $whereSql .= "AND (t.ticket_number LIKE :search_ticket OR t.subject LIKE :search_subject) ";
        $params[':search_ticket'] = '%' . $searchQuery . '%';
        $params[':search_subject'] = '%' . $searchQuery . '%';
    }

    // total count
    $countSql = "SELECT COUNT(t.id) AS total_count FROM tickets t {$whereSql}";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total_count'] ?? 0);
    $totalPages = max(1, (int)ceil($totalCount / $limit));

    // main list query
    // Use ANY_VALUE for non-aggregated columns so ONLY_FULL_GROUP_BY doesn't fail
    $sql = "
        SELECT
            t.id,
            ANY_VALUE(t.ticket_number) AS ticket_number,
            ANY_VALUE(t.subject) AS subject,
            ANY_VALUE(t.status) AS status,
            ANY_VALUE(t.priority) AS priority,
            ANY_VALUE(t.created_at) AS created_at,
            ANY_VALUE(t.updated_at) AS updated_at,
            MAX(m.created_at) AS last_message_at
        FROM tickets t
        LEFT JOIN ticket_messages m ON t.id = m.ticket_id
        {$whereSql}
        GROUP BY t.id
        ORDER BY last_message_at DESC, t.updated_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);

    // Bind pagination as integers
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

    // bind instance id & search if present
    $stmt->bindValue(':i', $instanceId, PDO::PARAM_STR);
    if ($searchQuery !== '') {
        $stmt->bindValue(':search_ticket', $params[':search_ticket'], PDO::PARAM_STR);
        $stmt->bindValue(':search_subject', $params[':search_subject'], PDO::PARAM_STR);
    }

    $stmt->execute();
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'owner_email' => $ownerEmail,
        'tickets' => $tickets,
        'page' => $page,
        'limit' => $limit,
        'total_count' => $totalCount,
        'total_pages' => $totalPages
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    // controlled debug output
    $msg = (defined('APP_DEBUG') && APP_DEBUG) || (!empty($_GET['debug']) && $_GET['debug'] === '1')
         ? $e->getMessage()
         : 'Server error';
    echo json_encode(['success' => false, 'error' => $msg], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
