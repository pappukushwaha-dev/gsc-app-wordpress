<?php

/**
 * Stats Class
 * Provides methods to retrieve statistical data for the admin dashboard
 * from the database.
 */
class Stats {
    
    /**
     * Retrieves the total number of unique users (Wix site installations).
     * @return int The total user count.
     */
    public static function getTotalUsers(): int {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT instance_id) FROM WixSite");
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Database Error in getTotalUsers: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Retrieves the number of active users (users with 'APPINSTALLED' event_type).
     * @return int The active user count.
     */
    public static function getActiveUsers(): int {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM WixSite WHERE event_type = 'APPINSTALLED'");
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Database Error in getActiveUsers: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Retrieves the number of inactive users.
     * @return int The inactive user count.
     */
    public static function getInactiveUsers(): int {
        return self::getTotalUsers() - self::getActiveUsers();
    }
    
    /**
     * Retrieves the total number of Google accounts connected.
     * @return int The Google account count.
     */
    public static function getGoogleAccounts(): int {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM GoogleAnalytics");
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Database Error in getGoogleAccounts: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Retrieves the total number of Analytics Properties linked.
     * @return int The analytics property count.
     */
    public static function getAnalyticsProperties(): int {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM GoogleAnalytics WHERE ga4_property_id IS NOT NULL AND ga4_property_id != ''");
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Database Error in getAnalyticsProperties: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Fetches the latest registered stores with their profile information.
     * @param int $limit The number of stores to fetch.
     * @return array An array of store data.
     */
    public static function getLatestStores(int $limit = 10): array {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return [];
        }
        try {
            // First, get the main store records
            $stmt = $pdo->prepare("SELECT instance_id, site_display_name, owner_email, created_at, event_type FROM WixSite ORDER BY created_at DESC LIMIT :limit");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $wixSites = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // If no sites found, return an empty array
            if (empty($wixSites)) {
                return [];
            }

            // Extract instance_ids for the next query
            $instanceIds = array_column($wixSites, 'instance_id');
            
            // Prepare placeholders for the IN clause
            $placeholders = implode(',', array_fill(0, count($instanceIds), '?'));

            // Fetch profile data for the selected stores
            $profileQuery = "SELECT instance_id, logo_url, phone as phone_number, website_url FROM WixSiteProfile WHERE instance_id IN ($placeholders)";
            $profileStmt = $pdo->prepare($profileQuery);
            $profileStmt->execute($instanceIds);
            $profiles = $profileStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Map profile data to instance_id for easy lookup
            $profilesByInstanceId = array_column($profiles, null, 'instance_id');
            
            $displayStores = [];
            foreach ($wixSites as $site) {
                $profile = $profilesByInstanceId[$site['instance_id']] ?? [];

                // Determine logo URL or initial
                $logoUrl = null;
                $logoInitial = '';
                if (!empty($profile['logo_url'])) {
                    $logoUrl = 'https://static.wixstatic.com/media/' . $profile['logo_url'];
                } else {
                    $logoInitial = strtoupper(substr($site['site_display_name'] ?? 'N/A', 0, 1));
                }

                $displayStores[] = [
                    'name' => $site['site_display_name'] ?? 'N/A',
                    'email' => $site['owner_email'] ?? 'N/A',
                    'registered_on' => (new DateTime($site['created_at']))->format('d M Y'),
                    'logo_url' => $logoUrl,
                    'logo_initial' => $logoInitial,
                    'phone_number' => $profile['phone_number'] ?? 'N/A',
                    'website_url' => $profile['website_url'] ?? 'N/A',
                    'status' => ($site['event_type'] === 'APPINSTALLED') ? 'Active' : 'Inactive'
                ];
            }
            return $displayStores;
        } catch (PDOException $e) {
            error_log("Database Error in getLatestStores: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Retrieves the last 30-day change for all key metrics.
     * @return array An associative array with change values.
     */
    public static function getStatsChangeLast30Days(): array {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return [
                'totalUsers' => 0, 'totalUsersDirection' => 'down',
                'activeUsers' => 0, 'activeUsersDirection' => 'down',
                'inactiveUsers' => 0, 'inactiveUsersDirection' => 'down',
                'googleAccounts' => 0, 'googleAccountsDirection' => 'down',
                'analyticsProperties' => 0, 'analyticsPropertiesDirection' => 'down'
            ];
        }

        try {
            $query = "SELECT total_users, active_users, google_accounts, analytics_properties 
                     FROM daily_stats_snapshots 
                     WHERE date IN (CURDATE(), CURDATE() - INTERVAL 30 DAY) 
                     ORDER BY date DESC";
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $snapshots = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($snapshots) < 2) {
                return [
                    'totalUsers' => 0, 'totalUsersDirection' => 'up',
                    'activeUsers' => 0, 'activeUsersDirection' => 'up',
                    'inactiveUsers' => 0, 'inactiveUsersDirection' => 'down',
                    'googleAccounts' => 0, 'googleAccountsDirection' => 'up',
                    'analyticsProperties' => 0, 'analyticsPropertiesDirection' => 'up'
                ];
            }

            $today = $snapshots[0];
            $thirtyDaysAgo = $snapshots[1];

            $totalUsersChange = $today['total_users'] - $thirtyDaysAgo['total_users'];
            $activeUsersChange = $today['active_users'] - $thirtyDaysAgo['active_users'];
            $googleAccountsChange = $today['google_accounts'] - $thirtyDaysAgo['google_accounts'];
            $analyticsPropertiesChange = $today['analytics_properties'] - $thirtyDaysAgo['analytics_properties'];
            $inactiveUsersChange = ($today['total_users'] - $today['active_users']) - ($thirtyDaysAgo['total_users'] - $thirtyDaysAgo['active_users']);

            return [
                'totalUsers' => abs($totalUsersChange),
                'totalUsersDirection' => $totalUsersChange >= 0 ? 'up' : 'down',
                'activeUsers' => abs($activeUsersChange),
                'activeUsersDirection' => $activeUsersChange >= 0 ? 'up' : 'down',
                'inactiveUsers' => abs($inactiveUsersChange),
                'inactiveUsersDirection' => $inactiveUsersChange >= 0 ? 'up' : 'down',
                'googleAccounts' => abs($googleAccountsChange),
                'googleAccountsDirection' => $googleAccountsChange >= 0 ? 'up' : 'down',
                'analyticsProperties' => abs($analyticsPropertiesChange),
                'analyticsPropertiesDirection' => $analyticsPropertiesChange >= 0 ? 'up' : 'down'
            ];

        } catch (PDOException $e) {
            error_log("Database Error in getStatsChangeLast30Days: " . $e->getMessage());
            return [
                'totalUsers' => 0, 'totalUsersDirection' => 'down',
                'activeUsers' => 0, 'activeUsersDirection' => 'down',
                'inactiveUsers' => 0, 'inactiveUsersDirection' => 'down',
                'googleAccounts' => 0, 'googleAccountsDirection' => 'down',
                'analyticsProperties' => 0, 'analyticsPropertiesDirection' => 'down'
            ];
        }
    }

    /**
     * Fetches the monthly count of new app installations for the last year,
     * ensuring a complete 12-month array.
     * @return array An array of monthly counts.
     */
    public static function getMonthlyInstallations(): array {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return array_fill(0, 12, 0);
        }
        try {
            $query = "SELECT MONTH(created_at) AS month_num, COUNT(DISTINCT instance_id) AS count
                     FROM WixSite
                     WHERE created_at >= NOW() - INTERVAL 1 YEAR
                     GROUP BY month_num
                     ORDER BY month_num ASC";
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data = [];
            foreach ($results as $row) {
                $data[(int)$row['month_num']] = (int)$row['count'];
            }
            
            $monthlyCounts = [];
            for ($i = 1; $i <= 12; $i++) {
                $monthlyCounts[] = $data[$i] ?? 0;
            }

            return $monthlyCounts;
        } catch (PDOException $e) {
            error_log("Database Error in getMonthlyInstallations: " . $e->getMessage());
            return array_fill(0, 12, 0);
        }
    }

    /**
     * Fetches the weekly count of new GA connections for the last 7 weeks,
     * ensuring a complete 7-week array.
     * @return array An array of weekly counts.
     */
    public static function getWeeklyGAConnections(): array {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return array_fill(0, 7, 0);
        }
        try {
            $query = "SELECT YEARWEEK(created_at, 1) AS year_week, COUNT(*) AS count
                     FROM GoogleAnalytics
                     WHERE created_at >= NOW() - INTERVAL 7 WEEK
                     GROUP BY year_week
                     ORDER BY year_week ASC";
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data = [];
            foreach ($results as $row) {
                $data[$row['year_week']] = (int)$row['count'];
            }
            
            $weeklyCounts = [];
            $today = new DateTime();
            for ($i = 6; $i >= 0; $i--) {
                $date = (clone $today)->modify("-{$i} weeks");
                $weekKey = $date->format("oW"); // ISO-8601 week number
                $weeklyCounts[] = $data[$weekKey] ?? 0;
            }

            return array_reverse($weeklyCounts);

        } catch (PDOException $e) {
            error_log("Database Error in getWeeklyGAConnections: " . $e->getMessage());
            return array_fill(0, 7, 0);
        }
    }
}