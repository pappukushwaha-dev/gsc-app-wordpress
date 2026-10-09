<?php
declare(strict_types=1);

/**
 * TicketManager — admin-side ticket DB layer.
 *
 * All reads/writes go through here so the admin pages stay thin. Tickets are
 * scoped per-merchant by `instance_id` (Ecwid store id) and joined with
 * WpSite for the customer info shown on the list/detail screens.
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
            throw new RuntimeException('TicketManager: PDO not initialized');
        }
    }

    /* ============================================================
       Allowed enums (mirror tickets_min.php + manage-tickets.php)
    ============================================================ */
    public static function allowedStatuses(): array
    {
        return ['open', 'pending', 'closed'];
    }

    public static function allowedPriorities(): array
    {
        return ['low', 'normal', 'high', 'urgent'];
    }

    /* ============================================================
       List + count (filtered)
    ============================================================ */

    /**
     * Build the WHERE clause + bound params shared by list/count queries.
     * Accepts free-text search, status, priority. Returns [sql, params].
     */
    private static function buildFilters(string $search, string $status, string $priority): array
    {
        $where  = [];
        $params = [];

        $search   = trim($search);
        $status   = trim($status);
        $priority = trim($priority);

        if ($search !== '') {
            $where[] = "(
                t.ticket_number LIKE :search_q
                OR t.subject       LIKE :search_q
                OR t.instance_id   LIKE :search_q
                OR ws.email        LIKE :search_q
                OR ws.shop_name    LIKE :search_q
            )";
            $params[':search_q'] = '%' . $search . '%';
        }

        if ($status !== '' && in_array($status, self::allowedStatuses(), true)) {
            $where[] = "t.status = :status_f";
            $params[':status_f'] = $status;
        }

        if ($priority !== '' && in_array($priority, self::allowedPriorities(), true)) {
            $where[] = "t.priority = :priority_f";
            $params[':priority_f'] = $priority;
        }

        $sql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
        return [$sql, $params];
    }

    public static function getFilteredTickets(
        string $search = '',
        string $status = '',
        string $priority = '',
        int $perPage = 10,
        int $page = 1
    ): array {
        self::assertPdo();

        [$whereSql, $params] = self::buildFilters($search, $status, $priority);
        $offset = max(0, ($page - 1) * $perPage);

        $sql = "
            SELECT
                t.id,
                t.instance_id,
                t.ticket_number,
                t.subject,
                t.status,
                t.priority,
                t.created_at,
                t.updated_at,
                t.last_message_at,
                ws.shop_name,
                ws.email      AS customer_email,
                ws.domain     AS site_domain,
                ws.shop_domain AS shop_domain
            FROM tickets t
            LEFT JOIN WpSite ws
                ON ws.instance_id COLLATE utf8mb4_unicode_ci
                 = t.instance_id  COLLATE utf8mb4_unicode_ci
            $whereSql
            ORDER BY
                COALESCE(t.last_message_at, t.updated_at, t.created_at) DESC,
                t.id DESC
            LIMIT " . (int)$perPage . " OFFSET " . (int)$offset . "
        ";

        $stmt = self::$pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getTotalTickets(
        string $search = '',
        string $status = '',
        string $priority = ''
    ): int {
        self::assertPdo();

        [$whereSql, $params] = self::buildFilters($search, $status, $priority);

        $sql = "
            SELECT COUNT(t.id)
            FROM tickets t
            LEFT JOIN WpSite ws
                ON ws.instance_id COLLATE utf8mb4_unicode_ci
                 = t.instance_id  COLLATE utf8mb4_unicode_ci
            $whereSql
        ";

        $stmt = self::$pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /* ============================================================
       Single ticket + thread
    ============================================================ */

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
            LEFT JOIN WpSite hs
                ON hs.instance_id COLLATE utf8mb4_unicode_ci
                 = t.instance_id  COLLATE utf8mb4_unicode_ci
            WHERE t.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $ticketId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public static function getTicketWithSite(int $ticketId): ?array
    {
        self::assertPdo();

        $stmt = self::$pdo->prepare("
            SELECT
                t.*,
                ws.shop_name,
                ws.email       AS customer_email,
                ws.domain      AS site_domain,
                ws.shop_domain AS shop_domain,
                ws.is_active   AS site_is_active
            FROM tickets t
            LEFT JOIN WpSite ws
                ON ws.instance_id COLLATE utf8mb4_unicode_ci
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

        $mStmt = self::$pdo->prepare("
            SELECT id, is_internal, message, created_at
            FROM ticket_messages
            WHERE ticket_id = :tid AND instance_id = :i
            ORDER BY created_at ASC, id ASC
        ");
        $mStmt->execute([':tid' => $ticketId, ':i' => $instanceId]);
        $messages = $mStmt->fetchAll(PDO::FETCH_ASSOC);

        $aStmt = self::$pdo->prepare("
            SELECT id, message_id, original_name, mime_type, size_bytes
            FROM ticket_attachments
            WHERE ticket_id = :tid AND instance_id = :i
        ");
        $aStmt->execute([':tid' => $ticketId, ':i' => $instanceId]);
        $attachments = $aStmt->fetchAll(PDO::FETCH_ASSOC);

        $byMessage = [];
        foreach ($attachments as $a) {
            $byMessage[(int)$a['message_id']][] = [
                'id'            => (int)$a['id'],
                'original_name' => $a['original_name'],
                'mime_type'     => $a['mime_type'],
                'size_bytes'    => (int)$a['size_bytes'],
                'url'           => APP_BASE . '/api/serve_ticket_attachment.php?attachment_id='
                                 . (int)$a['id'] . '&instance_id=' . urlencode($instanceId),
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
            ORDER BY id ASC
        ");
        $stmt->execute([':tid' => $ticketId, ':i' => $instanceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ============================================================
       Mutations (with audit trail)
    ============================================================ */

    public static function updateStatus(
        int $ticketId,
        string $instanceId,
        string $newStatus,
        string $changedBy = 'staff',
        ?string $note = null
    ): array {
        self::assertPdo();

        if (!in_array($newStatus, self::allowedStatuses(), true)) {
            return ['success' => false, 'error' => 'Invalid status'];
        }

        try {
            $cur = self::$pdo->prepare("
                SELECT status FROM tickets
                WHERE id = :id AND instance_id = :i LIMIT 1
            ");
            $cur->execute([':id' => $ticketId, ':i' => $instanceId]);
            $oldStatus = $cur->fetchColumn();
            if ($oldStatus === false) {
                return ['success' => false, 'error' => 'Ticket not found'];
            }
            if ($oldStatus === $newStatus) {
                return ['success' => true, 'unchanged' => true];
            }

            self::$pdo->beginTransaction();

            self::$pdo->prepare("
                UPDATE tickets
                SET status = :status, updated_at = NOW()
                WHERE id = :id AND instance_id = :i
            ")->execute([
                ':status' => $newStatus,
                ':id'     => $ticketId,
                ':i'      => $instanceId,
            ]);

            self::$pdo->prepare("
                INSERT INTO ticket_status_history
                    (ticket_id, instance_id, from_status, to_status, changed_by, note)
                VALUES
                    (:tid, :i, :from, :to, :by, :note)
            ")->execute([
                ':tid'  => $ticketId,
                ':i'    => $instanceId,
                ':from' => $oldStatus ?: null,
                ':to'   => $newStatus,
                ':by'   => $changedBy,
                ':note' => $note,
            ]);

            self::$pdo->commit();
            return ['success' => true, 'old_status' => $oldStatus, 'new_status' => $newStatus];
        } catch (Throwable $e) {
            if (self::$pdo->inTransaction()) self::$pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public static function updatePriority(
        int $ticketId,
        string $instanceId,
        string $newPriority,
        string $changedBy = 'staff'
    ): array {
        self::assertPdo();

        if (!in_array($newPriority, self::allowedPriorities(), true)) {
            return ['success' => false, 'error' => 'Invalid priority'];
        }

        try {
            $cur = self::$pdo->prepare("
                SELECT priority FROM tickets
                WHERE id = :id AND instance_id = :i LIMIT 1
            ");
            $cur->execute([':id' => $ticketId, ':i' => $instanceId]);
            $oldPriority = $cur->fetchColumn();
            if ($oldPriority === false) {
                return ['success' => false, 'error' => 'Ticket not found'];
            }
            if ($oldPriority === $newPriority) {
                return ['success' => true, 'unchanged' => true];
            }

            self::$pdo->beginTransaction();

            self::$pdo->prepare("
                UPDATE tickets
                SET priority = :priority, updated_at = NOW()
                WHERE id = :id AND instance_id = :i
            ")->execute([
                ':priority' => $newPriority,
                ':id'       => $ticketId,
                ':i'        => $instanceId,
            ]);

            // Reuse the status_history table to record priority changes too.
            // The to_status column will hold a "priority:<value>" marker so the
            // existing audit-trail UI keeps working without a schema change.
            self::$pdo->prepare("
                INSERT INTO ticket_status_history
                    (ticket_id, instance_id, from_status, to_status, changed_by, note)
                VALUES
                    (:tid, :i, :from, :to, :by, :note)
            ")->execute([
                ':tid'  => $ticketId,
                ':i'    => $instanceId,
                ':from' => 'priority:' . $oldPriority,
                ':to'   => 'priority:' . $newPriority,
                ':by'   => $changedBy,
                ':note' => 'priority changed',
            ]);

            self::$pdo->commit();
            return ['success' => true, 'old_priority' => $oldPriority, 'new_priority' => $newPriority];
        } catch (Throwable $e) {
            if (self::$pdo->inTransaction()) self::$pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Admin posts a reply (or internal note) on a ticket. Updates
     * last_message_at and bumps updated_at so the list ordering reflects it.
     */
    public static function postReply(
        int $ticketId,
        string $instanceId,
        string $message,
        bool $isInternal = false
    ): array {
        self::assertPdo();

        $message = trim($message);
        if ($message === '') {
            return ['success' => false, 'error' => 'Message cannot be empty'];
        }

        try {
            // Ensure ticket exists and belongs to this instance.
            $chk = self::$pdo->prepare("
                SELECT id FROM tickets
                WHERE id = :id AND instance_id = :i LIMIT 1
            ");
            $chk->execute([':id' => $ticketId, ':i' => $instanceId]);
            if (!$chk->fetchColumn()) {
                return ['success' => false, 'error' => 'Ticket not found'];
            }

            self::$pdo->beginTransaction();

            self::$pdo->prepare("
                INSERT INTO ticket_messages
                    (ticket_id, instance_id, is_internal, message)
                VALUES
                    (:tid, :i, :internal, :message)
            ")->execute([
                ':tid'      => $ticketId,
                ':i'        => $instanceId,
                ':internal' => $isInternal ? 1 : 0,
                ':message'  => $message,
            ]);
            $messageId = (int)self::$pdo->lastInsertId();

            self::$pdo->prepare("
                UPDATE tickets
                SET last_message_at = NOW(), updated_at = NOW()
                WHERE id = :id AND instance_id = :i
            ")->execute([':id' => $ticketId, ':i' => $instanceId]);

            self::$pdo->commit();
            return ['success' => true, 'message_id' => $messageId];
        } catch (Throwable $e) {
            if (self::$pdo->inTransaction()) self::$pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
