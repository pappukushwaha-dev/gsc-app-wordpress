<?php

declare(strict_types=1);

class StoreManager
{
    private static PDO $pdo;

    public static function initialize(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    private static function assertPdo(): void
    {
        if (!isset(self::$pdo)) {
            throw new RuntimeException('PDO not initialized');
        }
    }

    /* ------------------------------------------------------------
       TRIAL CHECK
    ------------------------------------------------------------ */
    private static function buildTrialMap(array $instanceIds): array
    {
        if (!$instanceIds) return [];

        $in = implode(',', array_fill(0, count($instanceIds), '?'));

        $stmt = self::$pdo->prepare("
            SELECT instance_id
            FROM app_free_trials
            WHERE instance_id IN ($in)
              AND status = 'active'
        ");
        $stmt->execute($instanceIds);

        return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
    }

    /* ------------------------------------------------------------
       SUBSCRIPTION PLAN
    ------------------------------------------------------------ */
    private static function getSubscriptionPlan(string $instanceId): ?string
    {
        $stmt = self::$pdo->prepare("
            SELECT plan_name, billing_period
            FROM app_subscriptions
            WHERE instance_id = ?
              AND status = 'active'
            ORDER BY started_at DESC
            LIMIT 1
        ");
        $stmt->execute([$instanceId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        return ucfirst($row['plan_name']) .
            ($row['billing_period'] !== 'free' ? " ({$row['billing_period']})" : '');
    }

    /* ------------------------------------------------------------
       STORE LIST
    ------------------------------------------------------------ */
    public static function getFilteredStores(string $search = '', int $perPage = 10, int $page = 1): array
    {
        self::assertPdo();

        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT
                ws.instance_id,
                ws.domain,
                ws.shop_name,
                ws.email AS owner_email,
                ws.event_type,
                ws.is_active,
                ws.created_at,
                ga.email AS google_email,
                gdv.verification_status,
                s.status AS sitemap_status,
                ss.status
                FROM WpSite ws
                LEFT JOIN google_accounts ga
                ON ga.instance_id COLLATE utf8mb4_unicode_ci = ws.instance_id COLLATE utf8mb4_unicode_ci
                LEFT JOIN gsc_domain_verifications gdv
                ON gdv.instance_id COLLATE utf8mb4_unicode_ci = ws.instance_id COLLATE utf8mb4_unicode_ci
                LEFT JOIN sitemaps s
                ON s.instance_id COLLATE utf8mb4_unicode_ci = ws.instance_id COLLATE utf8mb4_unicode_ci
                LEFT JOIN app_subscriptions ss
                ON ss.instance_id COLLATE utf8mb4_unicode_ci = ws.instance_id COLLATE utf8mb4_unicode_ci
            ";

        $conditions = [];

        if (strtolower($search) === 'active') {
            $conditions[] = "ws.event_type = 'install'";
        } elseif (strtolower($search) === 'inactive') {
            $conditions[] = "ws.event_type = 'uninstall'";
        } elseif ($search !== '') {
            $q = self::$pdo->quote('%' . $search . '%');
            $conditions[] = "
                (ws.shop_name LIKE $q
                OR ws.domain LIKE $q
                OR ws.email LIKE $q
                OR ga.email LIKE $q)
            ";
        }

        // Combine conditions
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= "
            GROUP BY ws.instance_id
            ORDER BY ws.created_at DESC
            LIMIT " . (int)$perPage . "
            OFFSET " . (int)$offset . "
        ";

        $stmt = self::$pdo->prepare($sql);
        $stmt->execute();

        $stores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$stores) return [];

        // Add status_label and plan_name safely
        $instanceIds = array_column($stores, 'instance_id');
        $trialMap = self::buildTrialMap($instanceIds);

        foreach ($stores as &$store) {
            $iid = $store['instance_id'];
            $store['status_label'] = (($store['event_type'] == 'install' ? 'Active' : 'Inactive'));
            $store['plan_name'] = isset($trialMap[$iid]) ? 'Trial' : (self::getSubscriptionPlan($iid) ?: 'No Plan');
        }

        return $stores;
    }

    /* ------------------------------------------------------------
       TOTAL COUNT
    ------------------------------------------------------------ */
    public static function getTotalStores(string $search = ''): int
    {
        self::assertPdo();

        $sql = "
            SELECT COUNT(DISTINCT ws.instance_id)
            FROM WpSite ws
            LEFT JOIN google_accounts ga
            ON ga.instance_id COLLATE utf8mb4_unicode_ci = ws.instance_id COLLATE utf8mb4_unicode_ci
        ";

        $conditions = [];

        if (strtolower($search) === 'active') {
            $conditions[] = "ws.event_type = 'install'";
        } elseif (strtolower($search) === 'inactive') {
            $conditions[] = "ws.event_type = 'uninstall'";
        } elseif ($search !== '') {
            $q = self::$pdo->quote('%' . $search . '%');
            $conditions[] = "
                (ws.shop_name LIKE $q
                OR ws.domain LIKE $q
                OR ws.email LIKE $q
                OR ga.email LIKE $q)
            ";
        }

        // Combine conditions
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $stmt = self::$pdo->prepare($sql);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /* ------------------------------------------------------------
       SINGLE STORE DETAILS
    ------------------------------------------------------------ */
    public static function getStoreDetails(string $instanceId): array|false
    {
        self::assertPdo();

        $stmt = self::$pdo->prepare("
            SELECT
                ws.*,
                ga.email AS google_email,
                gdv.verification_status,
                s.status AS sitemap_status
                FROM WpSite ws
                LEFT JOIN google_accounts ga
                ON ga.instance_id COLLATE utf8mb4_unicode_ci
                = ws.instance_id COLLATE utf8mb4_unicode_ci

                LEFT JOIN gsc_domain_verifications gdv
                ON gdv.instance_id COLLATE utf8mb4_unicode_ci
                = ws.instance_id COLLATE utf8mb4_unicode_ci

                LEFT JOIN sitemaps s
                ON s.instance_id COLLATE utf8mb4_unicode_ci
                = ws.instance_id COLLATE utf8mb4_unicode_ci

                LIMIT 1
        ");

        $stmt->execute([$instanceId]);

        $store = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$store) return false;

        /* STATUS */
        $store['status_label'] =
            ($store['google_email'] && $store['verification_status'] === 'verified')
            ? 'Active'
            : 'Inactive';

        /* PLAN */
        if (self::buildTrialMap([$instanceId])) {
            $store['plan_name'] = 'Trial';
        } else {
            $plan = self::getSubscriptionPlan($instanceId);
            $store['plan_name'] = $plan ?: 'Free';
        }

        return $store;
    }
}
