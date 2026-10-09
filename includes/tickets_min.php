<?php
declare(strict_types=1);

/*
 Minimal ticket helpers.
 Expects $pdo (PDO) to be present (from includes/config.php).
 All operations MUST be scoped by instance_id.
*/

function generateTicketNumber(PDO $pdo, string $instanceId): string {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(id),0)+1 AS next_id FROM tickets WHERE instance_id = :i");
    $stmt->execute([':i' => $instanceId]);
    $next = (int)$stmt->fetchColumn();
    return sprintf('T-%s-%05d', date('Y'), $next);
}

/**
 * Create a ticket (minimal).
 * $data: ['instance_id','subject','priority' optional]
 */
function createTicketMinimal(PDO $pdo, array $data): array {
    try {
        $instanceId = trim($data['instance_id'] ?? '');
        $subject = trim($data['subject'] ?? '');
        $priority = $data['priority'] ?? 'normal';

        if ($instanceId === '' || $subject === '') {
            return ['success' => false, 'error' => 'Missing required fields'];
        }

        $pdo->beginTransaction();

        $ticketNumber = generateTicketNumber($pdo, $instanceId);

        $ins = $pdo->prepare("
            INSERT INTO tickets (instance_id, ticket_number, subject, status, priority)
            VALUES (:instance_id, :ticket_number, :subject, 'open', :priority)
        ");
        $ins->execute([
            ':instance_id' => $instanceId,
            ':ticket_number' => $ticketNumber,
            ':subject' => $subject,
            ':priority' => $priority
        ]);
        $ticketId = (int)$pdo->lastInsertId();

        $pdo->prepare("
            INSERT INTO ticket_status_history (ticket_id, instance_id, from_status, to_status, changed_by, note)
            VALUES (:ticket_id, :instance_id, NULL, 'open', 'system', 'created')
        ")->execute([':ticket_id' => $ticketId, ':instance_id' => $instanceId]);

        $pdo->commit();

        // Fire-and-forget: notify support of the new ticket. Never block the caller.
        try {
            require_once __DIR__ . '/mailer.php';
            notify_new_ticket($pdo, $ticketId);
        } catch (Throwable $e) {
            error_log('createTicketMinimal notify hook failed: ' . $e->getMessage());
        }

        return ['success' => true, 'ticket_id' => $ticketId, 'ticket_number' => $ticketNumber];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Post a message to a ticket.
 * $data: ['instance_id','ticket_id','message','is_internal' optional (0/1) ]
 */
function postTicketMessageMinimal(PDO $pdo, array $data): array {
    try {
        $instanceId = trim($data['instance_id'] ?? '');
        $ticketId = (int)($data['ticket_id'] ?? 0);
        $message = trim($data['message'] ?? '');
        $isInternal = !empty($data['is_internal']) ? 1 : 0;

        if ($instanceId === '' || $ticketId <= 0 || $message === '') {
            return ['success' => false, 'error' => 'Missing required fields'];
        }

        $pdo->beginTransaction();

        $ins = $pdo->prepare("
            INSERT INTO ticket_messages (ticket_id, instance_id, is_internal, message)
            VALUES (:ticket_id, :instance_id, :is_internal, :message)
        ");
        $ins->execute([
            ':ticket_id' => $ticketId,
            ':instance_id' => $instanceId,
            ':is_internal' => $isInternal,
            ':message' => $message
        ]);
        $messageId = (int)$pdo->lastInsertId();

        // Update ticket last_message_at and optionally status:
        // Simple rule: staff/internal notes don't change public status.
        // If message is internal (staff note) -> keep status; otherwise mark pending (you can adjust)
        // $newStatus = $isInternal ? null : 'pending';
        $newStatus = null;

        if ($newStatus !== null) {
            $pdo->prepare("UPDATE tickets SET last_message_at = NOW(), status = :status WHERE id = :id AND instance_id = :i")
                ->execute([':status' => $newStatus, ':id' => $ticketId, ':i' => $instanceId]);

            $pdo->prepare("
                INSERT INTO ticket_status_history (ticket_id, instance_id, from_status, to_status, changed_by, note)
                VALUES (:ticket_id, :instance_id, NULL, :to_status, 'system', 'message posted')
            ")->execute([':ticket_id' => $ticketId, ':instance_id' => $instanceId, ':to_status' => $newStatus]);
        } else {
            // just update last_message_at
            $pdo->prepare("UPDATE tickets SET last_message_at = NOW() WHERE id = :id AND instance_id = :i")
                ->execute([':id' => $ticketId, ':i' => $instanceId]);
        }

        $pdo->commit();

        return ['success' => true, 'message_id' => $messageId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Change ticket status (open/pending/closed)
 */
function changeTicketStatusMinimal(PDO $pdo, int $ticketId, string $instanceId, string $newStatus, string $changedBy = 'staff', string $note = null): array {
    $allowed = ['open','pending','closed'];
    if (!in_array($newStatus, $allowed, true)) {
        return ['success' => false, 'error' => 'Invalid status'];
    }
    try {
        $cur = $pdo->prepare("SELECT status FROM tickets WHERE id = :id AND instance_id = :i");
        $cur->execute([':id' => $ticketId, ':i' => $instanceId]);
        $old = $cur->fetchColumn();

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE tickets SET status = :status, updated_at = NOW() WHERE id = :id AND instance_id = :i");
        $stmt->execute([':status' => $newStatus, ':id' => $ticketId, ':i' => $instanceId]);

        $pdo->prepare("
            INSERT INTO ticket_status_history (ticket_id, instance_id, from_status, to_status, changed_by, note)
            VALUES (:ticket_id, :instance_id, :from_status, :to_status, :changed_by, :note)
        ")->execute([
            ':ticket_id' => $ticketId,
            ':instance_id' => $instanceId,
            ':from_status' => $old ?: null,
            ':to_status' => $newStatus,
            ':changed_by' => $changedBy,
            ':note' => $note
        ]);

        $pdo->commit();
        return ['success' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get ticket + messages (messages asc)
 */
// function getTicketWithMessagesMinimal(PDO $pdo, int $ticketId, string $instanceId): array {
//     $t = $pdo->prepare("SELECT * FROM tickets WHERE id = :id AND instance_id = :i");
//     $t->execute([':id' => $ticketId, ':i' => $instanceId]);
//     $ticket = $t->fetch(PDO::FETCH_ASSOC);
//     if (!$ticket) return ['success' => false, 'error' => 'Ticket not found'];

//     $m = $pdo->prepare("SELECT id, is_internal, message, created_at FROM ticket_messages WHERE ticket_id = :id AND instance_id = :i ORDER BY created_at ASC");
//     $m->execute([':id' => $ticketId, ':i' => $instanceId]);
//     $messages = $m->fetchAll(PDO::FETCH_ASSOC);

//     return ['success' => true, 'ticket' => $ticket, 'messages' => $messages];
// }


function getTicketWithMessagesMinimal(PDO $pdo, int $ticketId, string $instanceId)
{
    // Ticket
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = :tid AND instance_id = :i LIMIT 1");
    $stmt->execute([':tid' => $ticketId, ':i' => $instanceId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        return ['success' => false, 'error' => 'Ticket not found'];
    }

    // Messages
    $msgStmt = $pdo->prepare("
        SELECT id, is_internal, message, created_at
        FROM ticket_messages
        WHERE ticket_id = :tid AND instance_id = :i
        ORDER BY created_at ASC
    ");
    $msgStmt->execute([
        ':tid' => $ticketId,
        ':i' => $instanceId
    ]);
    $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

    // ✅ FETCH ATTACHMENTS
    $attStmt = $pdo->prepare("
        SELECT id, message_id, original_name, mime_type, size_bytes
        FROM ticket_attachments
        WHERE ticket_id = :tid AND instance_id = :i
    ");
    $attStmt->execute([
        ':tid' => $ticketId,
        ':i'   => $instanceId
    ]);
    $attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    // ✅ GROUP BY MESSAGE
    $attachmentsByMessage = [];
    foreach ($attachments as $a) {
        $attachmentsByMessage[$a['message_id']][] = [
            'id' => $a['id'],
            'original_name' => $a['original_name'],
            'mime_type' => $a['mime_type'],
            'size_bytes' => $a['size_bytes'],
            'url' => "./api/serve_ticket_attachment.php?attachment_id={$a['id']}&instance_id={$instanceId}"
        ];
    }

    // ✅ ATTACH TO MESSAGES
    foreach ($messages as &$m) {
        $m['attachments'] = $attachmentsByMessage[$m['id']] ?? [];
    }
    unset($m);

    return [
        'success' => true,
        'ticket' => $ticket,
        'messages' => $messages
    ];
}
