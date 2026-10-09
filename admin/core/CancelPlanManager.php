<?php
declare(strict_types=1);

class CancelPlanManager
{
    private static PDO $pdo;

    public static function initialize(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * Fetch reviews with search + pagination
     */
    public static function getFilteredReviews(string $search = '', int $perPage = 10, int $page = 1): array
    {
        try {
            $offset = ($page - 1) * $perPage;

            $sql = "
                SELECT 
                    r.id, 
                    r.instance_id, 
                    w.email, 
                    r.current_step, 
                    r.join_reason, 
                    r.leave_reason, 
                    r.offer_type, 
                    r.created_at,
                    w.site_display_name AS store_name, 
                    w.site_url
                FROM cancellation_sessions r
                LEFT JOIN WixSite w ON r.instance_id = w.instance_id
                WHERE 1=1
            ";

            $params = [];

            if (!empty($search)) {
                $sql .= "
                    AND (
                        w.email LIKE :q OR
                        r.join_reason LIKE :q OR
                        r.leave_reason LIKE :q OR
                        r.offer_type LIKE :q OR
                        w.site_display_name LIKE :q OR
                        w.site_url LIKE :q
                    )
                ";
                $params[':q'] = '%' . $search . '%';
            }

            // FIXED: MySQL-compatible pagination
            $sql .= " ORDER BY r.created_at DESC LIMIT :offset, :limit";

            $stmt = self::$pdo->prepare($sql);

            // Bind search params
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }

            // FIXED: MySQL LIMIT binding order (offset, limit)
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);

            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            error_log("CancelPlanManager Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Count reviews
     */
    public static function getTotalReviews(string $search = ''): int
    {
        try {
            $sql = "
                SELECT COUNT(*) 
                FROM cancellation_sessions r
                LEFT JOIN WixSite w ON r.instance_id = w.instance_id
                WHERE 1=1
            ";

            $params = [];

            if (!empty($search)) {
                $sql .= "
                    AND (
                        r.email LIKE :q OR
                        r.join_reason LIKE :q OR
                        r.leave_reason LIKE :q OR
                        r.offer_type LIKE :q OR
                        w.site_display_name LIKE :q OR
                        w.site_url LIKE :q
                    )
                ";
                $params[':q'] = '%' . $search . '%';
            }

            $stmt = self::$pdo->prepare($sql);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }

            $stmt->execute();
            return (int)$stmt->fetchColumn();

        } catch (PDOException $e) {
            error_log("CancelPlanManager Count Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get single review details for modal
     */
    public static function getReviewDetails(int $id): array|false
    {
        try {
            $sql = "
                SELECT 
                    r.*, 
                    w.site_display_name AS store_name, 
                    w.site_url
                FROM cancellation_sessions r
                LEFT JOIN WixSite w ON r.instance_id = w.instance_id
                WHERE r.id = ?
                LIMIT 1
            ";

            $stmt = self::$pdo->prepare($sql);
            $stmt->execute([$id]);

            return $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            error_log("CancelPlanManager Details Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete review
     */
    public static function deleteReview(int $id): bool
    {
        try {
            $stmt = self::$pdo->prepare("DELETE FROM cancellation_sessions WHERE id = ?");
            return $stmt->execute([$id]);

        } catch (PDOException $e) {
            error_log("CancelPlanManager Delete Error: " . $e->getMessage());
            return false;
        }
    }
}
