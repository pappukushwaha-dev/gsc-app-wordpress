<?php
declare(strict_types=1);

/**
 * admin/core/TicketManager.php
 *
 * Admin-side DB layer for the Support Tickets feature.
 * Scoped per-instance via joins to HlSite (so admin can search/display
 * customer site + email alongside ticket data).
 *
 * Status enum:   open | pending | closed
 * Priority enum: low | normal | high | urgent
 */
class TicketManager
{
    private static PDO $pdo;

    public static function initialize(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    private static function assertPdo(): void
    {
        if (!isset(self::$pdo)) {
            throw new RuntimeException('PDO not initialized for TicketManager');
        }
    }

    public static function allowedStatuses(): array
    {
        return ['open', 'pending', 'closed'];
    }

    public static function allowedPriorities(): array
    {
        return ['low', 'normal', 'high', 'urgent'];
    }

    /* ------------------------------------------------------------
       LIST + FILTERS
    ------------------------------------------------------------ */
    private static function buildFilterSql(string $search, string $status, string $priority, array &$params): string
    {
        $where = " WHERE 1=1 ";

        if ($status !== '' && in_array($status, self::allowedStatuses(), true)) {
            $where .= " AND t.status = :status ";
            $params[':status'] = $status;
        }

        if ($priority !== '' && in_array($priority, self::allowedPriorities(), true)) {
            $where .= " AND t.priority = :priority ";
            $params[':priority'] = $priority;
        }

        if ($search !== '') {
            $where .= " AND (
                t.ticket_number LIKE :search_a
                OR t.subject       LIKE :search_b
                OR t.instance_id   LIKE :search_c
                OR hs.email        LIKE :search_d
                OR hs.shop_name    LIKE :search_e
                OR hs.shop_domain  LIKE :search_f
                OR hs.domain       LIKE :search_g
            ) ";
            $like = '%' . $search . '%';
            $params[':search_a'] = $like;
            $params[':search_b'] = $like;
            $params[':search_c'] = $like;
            $params[':search_d'] = $like;
            $params[':search_e'] = $like;
            $params[':search_f'] = $like;
            $params[':search_g'] = $like;
        }

        return $where;
    }

    public static function getFilteredTickets(
        string $search = '',
        string $status = '',
        string $priority = '',
        int $perPage = 10,
        int $page = 1
    ): array {
        self::assertPdo();

        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $params = [];
        $where  = self::buildFilterSql($search, $status, $priority, $params);

        $sql = "
            SELECT
                t.id,
                t.ticket_number,
                t.subject,
                t.status,
                t.priority,
                t.instance_id,
                t.created_at,
                t.updated_at,
                t.last_message_at,
                COALESCE(t.last_message_at, t.updated_at, t.created_at) AS last_activity,
                hs.shop_name,
                hs.shop_domain,
                hs.domain,
                hs.email AS customer_email
            FROM tickets t
            LEFT JOIN HlSite hs
                ON hs.instance_id COLLATE utf8mb4_unicode_ci
                 = t.instance_id  COLLATE utf8mb4_unicode_ci
            {$where}
            ORDER BY COALESCE(t.last_message_at, t.updated_at, t.created_at) DESC, t.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $stmt = self::$pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getTotalTickets(string $search = '', string $status = '', string $priority = ''): int
    {
        self::assertPdo();

        $params = [];
        $where  = self::buildFilterSql($search, $status, $priority, $params);

        $sql = "
            SELECT COUNT(t.id)
            FROM tickets t
            LEFT JOIN HlSite hs
                ON hs.instance_id COLLATE utf8mb4_unicode_ci
                 = t.instance_id  COLLATE utf8mb4_unicode_ci
            {$where}
        ";

        $stmt = self::$pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /* ------------------------------------------------------------
       DETAIL
    ------------------------------------------------------------ */
    public static function getTicketDetail(int $ticketId): ?array
    {
        self::assertPdo();

        $stmt = self::$pdo->prepare("
            SELECT
                t.*,
                hs.shop_name,
                hs.shop_domain,
                hs.domain,
                hs.email AS customer_email,
                hs.is_active AS shop_is_active
            FROM tickets t
            LEFT JOIN HlSite hs
                ON hs.instance_id COLLATE utf8mb4_unicode_ci
                 = t.instance_id  COLLATE utf8mb4_unicode_ci
            WHERE t.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $ticketId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getMessages(int $ticketId, string $instanceId): array
    {
        self::assertPdo();

        $msgStmt = self::$pdo->prepare("
            SELECT id, ticket_id, instance_id, is_internal, message, created_at
            FROM ticket_messages
            WHERE ticket_id = :tid AND instance_id = :i
            ORDER BY created_at ASC, id ASC
        ");
        $msgStmt->execute([':tid' => $ticketId, ':i' => $instanceId]);
        $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Attachments grouped by message_id
        $attStmt = self::$pdo->prepare("
            SELECT id, message_id, original_name, mime_type, size_bytes
            FROM ticket_attachments
            WHERE ticket_id = :tid AND instance_id = :i
        ");
        $attStmt->execute([':tid' => $ticketId, ':i' => $instanceId]);
        $attRows = $attStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byMessage = [];
        foreach ($attRows as $a) {
            $byMessage[(int)$a['message_id']][] = [
                'id'            => (int)$a['id'],
                'original_name' => (string)$a['original_name'],
                'mime_type'     => (string)$a['mime_type'],
                'size_bytes'    => (int)$a['size_bytes'],
                'url'           => '../api/serve_ticket_attachment.php'
                                 . '?attachment_id=' . (int)$a['id']
                                 . '&instance_id=' . urlencode($instanceId),
            ];
        }

        foreach ($messages as &$m) {
            $m['attachments'] = $byMessage[(int)$m['id']] ?? [];
        }
        unset($m);

        return $messages;
    }

    public static function getStatusHistory(int $ticketId, string $instanceId): array
    {
        self::assertPdo();

        $stmt = self::$pdo->prepare("
            SELECT id, from_status, to_status, changed_by, note, created_at
            FROM ticket_status_history
            WHERE ticket_id = :tid AND instance_id = :i
            ORDER BY created_at DESC, id DESC
        ");
        $stmt->execute([':tid' => $ticketId, ':i' => $instanceId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /* ------------------------------------------------------------
       MUTATIONS
    ------------------------------------------------------------ */
    public static function updateStatus(
        int $ticketId,
        string $instanceId,
        string $newStatus,
        string $changedBy,
        ?string $note = null
    ): array {
        self::assertPdo();

        if (!in_array($newStatus, self::allowedStatuses(), true)) {
            return ['success' => false, 'error' => 'Invalid status'];
        }

        try {
            $cur = self::$pdo->prepare("SELECT status FROM tickets WHERE id = :id AND instance_id = :i");
            $cur->execute([':id' => $ticketId, ':i' => $instanceId]);
            $old = $cur->fetchColumn();
            if ($old === false) {
                return ['success' => false, 'error' => 'Ticket not found'];
            }
            if ($old === $newStatus) {
                return ['success' => true, 'unchanged' => true];
            }

            self::$pdo->beginTransaction();

            self::$pdo->prepare("UPDATE tickets SET status = :status, updated_at = NOW() WHERE id = :id AND instance_id = :i")
                ->execute([':status' => $newStatus, ':id' => $ticketId, ':i' => $instanceId]);

            self::$pdo->prepare("
                INSERT INTO ticket_status_history
                    (ticket_id, instance_id, from_status, to_status, changed_by, note)
                VALUES
                    (:tid, :i, :from_status, :to_status, :changed_by, :note)
            ")->execute([
                ':tid'         => $ticketId,
                ':i'           => $instanceId,
                ':from_status' => (string)$old,
                ':to_status'   => $newStatus,
                ':changed_by'  => $changedBy,
                ':note'        => $note,
            ]);

            self::$pdo->commit();
            return ['success' => true];
        } catch (Throwable $e) {
            if (self::$pdo->inTransaction()) self::$pdo->rollBack();
            error_log('TicketManager::updateStatus failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Update failed'];
        }
    }

    public static function updatePriority(
        int $ticketId,
        string $instanceId,
        string $newPriority,
        string $changedBy
    ): array {
        self::assertPdo();

        if (!in_array($newPriority, self::allowedPriorities(), true)) {
            return ['success' => false, 'error' => 'Invalid priority'];
        }

        try {
            $cur = self::$pdo->prepare("SELECT priority FROM tickets WHERE id = :id AND instance_id = :i");
            $cur->execute([':id' => $ticketId, ':i' => $instanceId]);
            $old = $cur->fetchColumn();
            if ($old === false) {
                return ['success' => false, 'error' => 'Ticket not found'];
            }
            if ($old === $newPriority) {
                return ['success' => true, 'unchanged' => true];
            }

            self::$pdo->beginTransaction();

            self::$pdo->prepare("UPDATE tickets SET priority = :p, updated_at = NOW() WHERE id = :id AND instance_id = :i")
                ->execute([':p' => $newPriority, ':id' => $ticketId, ':i' => $instanceId]);

            // Log priority changes in status history with a "priority:" note so audit trail is unified.
            self::$pdo->prepare("
                INSERT INTO ticket_status_history
                    (ticket_id, instance_id, from_status, to_status, changed_by, note)
                VALUES
                    (:tid, :i, NULL, NULL, :changed_by, :note)
            ")->execute([
                ':tid'        => $ticketId,
                ':i'          => $instanceId,
                ':changed_by' => $changedBy,
                ':note'       => 'priority: ' . (string)$old . ' → ' . $newPriority,
            ]);

            self::$pdo->commit();
            return ['success' => true];
        } catch (Throwable $e) {
            if (self::$pdo->inTransaction()) self::$pdo->rollBack();
            error_log('TicketManager::updatePriority failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Update failed'];
        }
    }

    public static function postAdminReply(
        int $ticketId,
        string $instanceId,
        string $message,
        bool $isInternal,
        string $adminUsername
    ): array {
        self::assertPdo();

        $message = trim($message);
        if ($message === '') {
            return ['success' => false, 'error' => 'Message is required'];
        }

        try {
            self::$pdo->beginTransaction();

            $ins = self::$pdo->prepare("
                INSERT INTO ticket_messages
                    (ticket_id, instance_id, is_internal, message)
                VALUES
                    (:tid, :i, :internal, :message)
            ");
            $ins->execute([
                ':tid'      => $ticketId,
                ':i'        => $instanceId,
                ':internal' => $isInternal ? 1 : 0,
                ':message'  => $message,
            ]);
            $messageId = (int)self::$pdo->lastInsertId();

            // Per the existing rule in tickets_min.php: staff replies don't auto-change status.
            // Just bump last_message_at + updated_at so the ticket sorts to the top.
            self::$pdo->prepare("UPDATE tickets SET last_message_at = NOW(), updated_at = NOW() WHERE id = :id AND instance_id = :i")
                ->execute([':id' => $ticketId, ':i' => $instanceId]);

            // Audit trail entry (non-status) describing the reply
            self::$pdo->prepare("
                INSERT INTO ticket_status_history
                    (ticket_id, instance_id, from_status, to_status, changed_by, note)
                VALUES
                    (:tid, :i, NULL, NULL, :changed_by, :note)
            ")->execute([
                ':tid'        => $ticketId,
                ':i'          => $instanceId,
                ':changed_by' => $adminUsername,
                ':note'       => $isInternal ? 'internal note added' : 'admin replied',
            ]);

            self::$pdo->commit();
            return ['success' => true, 'message_id' => $messageId];
        } catch (Throwable $e) {
            if (self::$pdo->inTransaction()) self::$pdo->rollBack();
            error_log('TicketManager::postAdminReply failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Reply failed'];
        }
    }
}
