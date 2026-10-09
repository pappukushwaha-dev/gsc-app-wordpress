<?php
declare(strict_types=1);

ini_set('display_errors', 1);
error_reporting(E_ALL);

class OtherAppsManager
{
    private static ?PDO $pdo = null;

    /**
     * Initialize PDO connection
     */
    public static function initialize(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * Get apps with search + pagination
     */
    public static function getFilteredApps(string $search = '', int $perPage = 10, int $page = 1): array
    {
        if (!self::$pdo) {
            throw new RuntimeException("OtherAppsManager not initialized with PDO");
        }

        $offset = ($page - 1) * $perPage;

        $sql = "SELECT * FROM other_apps WHERE 1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (title LIKE :search OR subtitle LIKE :search OR description LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        $sql .= " ORDER BY sort_order ASC, created_at DESC LIMIT :offset, :limit";

        $stmt = self::$pdo->prepare($sql);

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, PDO::PARAM_STR);
        }

        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count total apps (with optional search)
     */
    public static function getTotalApps(string $search = ''): int
    {
        if (!self::$pdo) {
            throw new RuntimeException("OtherAppsManager not initialized with PDO");
        }

        $sql = "SELECT COUNT(*) FROM other_apps WHERE 1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (title LIKE :search OR subtitle LIKE :search OR description LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get single app by ID
     */
    public static function getAppById(int $id): ?array
    {
        if (!self::$pdo) {
            throw new RuntimeException("OtherAppsManager not initialized with PDO");
        }

        $stmt = self::$pdo->prepare("SELECT * FROM other_apps WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create new app
     */
    public static function createApp(array $data): bool
    {
        if (!self::$pdo) {
            throw new RuntimeException("OtherAppsManager not initialized with PDO");
        }
        $sql = "INSERT INTO other_apps (title, subtitle, tag_line, description, image_url, button_text, app_price, button_link, status, sort_order, is_index)
                VALUES (:title, :subtitle, :tag_line, :description, :image_url, :button_text, :app_price, :button_link, :status, :sort_order, :is_index)";
                

        $stmt = self::$pdo->prepare($sql);
        return $stmt->execute([
            ':title'       => $data['title'] ?? '',
            ':subtitle'    => $data['subtitle'] ?? '',
            ':tag_line'    => $data['tag_line'] ?? '',
            ':description' => $data['description'] ?? '',
            ':image_url'   => $data['image_url'] ?? null,
            ':button_text' => $data['button_text'] ?? 'Install Now',
            ':app_price' => $data['app_price'] ?? '0',
            ':button_link' => $data['button_link'] ?? '',
            ':status'      => $data['status'] ?? 'active',
            ':sort_order'  => (int)($data['sort_order'] ?? 0),
            ':is_index'  => $data['is_index'] ?? 'disable',
        ]);
    }

    /**
     * Update app by ID
     */
    public static function updateApp(int $id, array $data): bool
    {
        if (!self::$pdo) {
            throw new RuntimeException("OtherAppsManager not initialized with PDO");
        }

        $sql = "UPDATE other_apps
                SET title = :title,
                    subtitle = :subtitle,
                      tag_line = :tag_line,
                    description = :description,
                    image_url = :image_url,
                    button_text = :button_text,
                    app_price = :app_price,
                    button_link = :button_link,
                    status = :status,
                    sort_order = :sort_order,
                    is_index = :is_index,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id";

        $stmt = self::$pdo->prepare($sql);
        return $stmt->execute([
            ':title'       => $data['title'] ?? '',
            ':subtitle'    => $data['subtitle'] ?? '',
            ':tag_line'    => $data['tag_line'] ?? '',
            ':description' => $data['description'] ?? '',
            ':image_url'   => $data['image_url'] ?? null,
            ':button_text' => $data['button_text'] ?? 'Install Now',
            ':app_price' => $data['app_price'] ?? '0',
            ':button_link' => $data['button_link'] ?? '',
            ':status'      => $data['status'] ?? 'active',
            ':sort_order'  => (int)($data['sort_order'] ?? 0),
            ':is_index'  => $data['is_index'] ?? 'disable',
            ':id'          => $id,
        ]);
    }

    /**
     * Delete app by ID
     */
    public static function deleteApp(int $id): bool
    {
        if (!self::$pdo) {
            throw new RuntimeException("OtherAppsManager not initialized with PDO");
        }

        $stmt = self::$pdo->prepare("DELETE FROM other_apps WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }



}
