<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Define the log file location
$logFile = __DIR__ . '/post_crm_cron.log';

try {
    /**
     * 1. ESTABLISH TARGET DATABASE CONNECTION
     */
    $targetDb = new PDO(
        "mysql:host=localhost;dbname=heffl-crm-lead;charset=utf8mb4",
        "root",
        "Makkpress@123AA",
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    /**
     * 2. FETCH PENDING RECORDS (Collation Fix Applied on Join)
     */
    $stmt = $pdo->query("
        SELECT
            ws.*,
            wsp.phone,
            sub.plan_name,
            sub.status AS sub_status,
            sub.billing_period,
            sub.started_at,
            sub.cancelled_at,
            sub.expires_on
        FROM WpSite ws
        LEFT JOIN WpSiteProfile wsp ON ws.instance_id = wsp.instance_id
        LEFT JOIN app_subscriptions sub ON ws.instance_id = sub.instance_id COLLATE utf8mb4_unicode_ci
        WHERE ws.lead_flag = 0 
          AND ws.created_at <= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
          AND ws.created_at >= '2026-07-14 00:00:00'
          AND ws.email NOT LIKE '%makkpress%'
          AND ws.shop_domain NOT LIKE '%makkpress%'
        ORDER BY ws.id ASC
    ");

    $sites = $stmt->fetchAll();

    if (!$sites) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] INFO: No pending records found from July 14, 2026 onwards." . PHP_EOL, FILE_APPEND);
        die("<h3>No pending records found from July 14, 2026 onwards.</h3>");
    }

    $inserted = 0;
    $failed   = 0;

    /**
     * 3. PREPARE STATEMENTS OUTSIDE THE LOOP
     */
    $insertLead = $targetDb->prepare("
        INSERT INTO `lead` (
            user_id,
            website_id,
            source,
            name,
            email,
            phone_no,
            website,
            installation_status,
            cf_platform_name,
            cf_plan_name,
            cf_subscription_status,
            cf_subscription_frequen,
            cf_subscription_start_d,
            cf_subscription_end_dat,
            cf_free_trial,
            cf_money_back_guarantee,
            cf_app_name,
            cf_source,
            cf_signup_date,
            cf_rating_link,
            cf_login_link
        ) VALUES (
            :user_id,
            :website_id,
            :source,
            :name,
            :email,
            :phone_no,
            :website,
            :installation_status,
            :cf_platform_name,
            :cf_plan_name,
            :cf_subscription_status,
            :cf_subscription_frequen,
            :cf_subscription_start_d,
            :cf_subscription_end_dat,
            :cf_free_trial,
            :cf_money_back_guarantee,
            :cf_app_name,
            :cf_source,
            :cf_signup_date,
            :cf_rating_link,
            :cf_login_link
        )
    ");

    $updateSuccess = $pdo->prepare("UPDATE WpSite SET lead_flag = 1 WHERE id = :id");
    $updateFailed  = $pdo->prepare("UPDATE WpSite SET lead_flag = 2 WHERE id = :id");

    /**
     * 4. PROCESSING ENGINE LOOP
     */
    foreach ($sites as $site) {
        try {
            $signupDate = '';
            if (!empty($site['created_at'])) {
                $signupDate = date('d-M-Y h:i:s A', strtotime($site['created_at']));
            }

            $instanceId  = trim((string)$site['instance_id']);
            $displayName = $site['site_display_name'] ?? '';
            $siteUrl     = $site['shop_domain'] ?? '';
            $fullName    = trim("{$displayName} - {$siteUrl} - ecwid-google-search-console - Ecwid");

            $encodedId  = urlencode($instanceId);
            $ratingLink = "https://makkpressapps.com/wordpress/googlesearchconsole/customer-reviews.php?instanceid={$encodedId}";
            $loginLink  = "https://makkpressapps.com/wordpress/googlesearchconsole/dashboard.php?instanceid={$encodedId}";

            // Gather subscription variables safely
            $planName      = isset($site['plan_name']) ? trim((string)$site['plan_name']) : '';
            $billingPeriod = $site['billing_period'] ?? '';
            $subStatus     = !empty($site['sub_status']) ? trim((string)$site['sub_status']) : 'active';
            $startedAt     = $site['started_at'] ?? '';
            $cancelledAt   = $site['cancelled_at'] ?? null;
            $expiresOn     = $site['expires_on'] ?? '';
            $eventType     = isset($site['event_type']) ? trim((string)$site['event_type']) : '';

            // --- APP INSTALLED / UNINSTALLED FUNCTIONALITY ---
            if (stripos($eventType, 'uninstall') !== false || $eventType === 'AppRemoved') {
                $installationStatus = 'APPUNINSTALLED';
            } else {
                $installationStatus = 'APPINSTALLED';
            }

            // --- NO PLAN / SUBSCRIPTION DETAILS FALLBACK ---
            if ($planName === '') {
                $planName              = 'no plan';
                $subStatus             = '';
                $subscriptionFrequency = '';
                $startedAt             = '';
                $expiresOn             = '';
            } else {
                // Evaluate Plan Frequency Contextually
                if (stripos($planName, 'free') !== false) {
                    $subStatus             = 'active';
                    $subscriptionFrequency = 'Lifetime';
                    $startedAt             = !empty($startedAt) ? $startedAt : ($site['created_at'] ?? '');
                    $expiresOn             = '';
                } else {
                    $subscriptionFrequency = $billingPeriod;
                }

                if (empty(trim((string)$siteUrl))) {
                    $planName           = 'Unauthorized';
                    $subStatus = '';
                    $subscriptionFrequency = '';
                }

                // Map standard cancellation state directly if cancelled_at field is present
                if (!empty($cancelledAt)) {
                    $subStatus = 'cancelled';
                }

                // --- EXPIRED FUNCTIONALITY ---
                if (!empty($expiresOn)) {
                    $expireTimestamp = strtotime($expiresOn);
                    if ($expireTimestamp !== false && $expireTimestamp < time()) {
                        $subStatus = 'expired';
                    }
                }
            }

            $insertLead->execute([
                ':user_id'                 => $site['id'],
                ':website_id'              => $site['id'],
                ':source'                  => 'ecwid-google-search-console',
                ':name'                    => $fullName,
                ':email'                   => $site['email'],
                ':phone_no'                => $site['phone'] ?? '',
                ':website'                 => $site['shop_domain'],
                ':installation_status'     => $installationStatus,
                ':cf_platform_name'        => 'WordPress',
                ':cf_plan_name'            => $planName,
                ':cf_subscription_status'  => $subStatus,
                ':cf_subscription_frequen' => $subscriptionFrequency,
                ':cf_subscription_start_d' => $startedAt,
                ':cf_subscription_end_dat' => $expiresOn,
                ':cf_free_trial'           => 'No',
                ':cf_money_back_guarantee' => 'No',
                ':cf_app_name'             => 'ecwid-google-search-console',
                ':cf_source'               => 'Ecwid Google Search Console',
                ':cf_signup_date'          => $signupDate,
                ':cf_rating_link'          => $ratingLink,
                ':cf_login_link'           => $loginLink
            ]);

            $updateSuccess->execute([':id' => $site['id']]);
            $inserted++;

            echo "<p style='color: green; margin: 4px 0;'>✔ Successfully Migrated WpSite ID: <strong>{$site['id']}</strong> (Status: {$subStatus}, Installation: {$installationStatus})</p>";

        } catch (Exception $e) {
            $updateFailed->execute([':id' => $site['id']]);
            $failed++;

            echo "<p style='color: red; margin: 4px 0;'>
                    ✘ Failed Migrating WixSite ID: <strong>{$site['id']}</strong><br>
                    <small>Error: " . htmlspecialchars($e->getMessage()) . "</small>
                  </p>";
        }
    }

    /**
     * 5. RUNTIME METRIC VISUALIZATION & LOGGING
     */
    $totalExtracted = count($sites);
    $timestamp = date('Y-m-d H:i:s');
    
    // Append concise metrics line to log file
    $logMsg = "[$timestamp] SUMMARY - Extracted: $totalExtracted | Inserted: $inserted | Failed: $failed" . PHP_EOL;
    file_put_contents($logFile, $logMsg, FILE_APPEND);

} catch (PDOException $e) {
    // Append database/fatal initialization failures to log file
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] FATAL DATABASE ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    die("<h3>Fatal Database Exception Occurred:</h3><pre>" . htmlspecialchars($e->getMessage()) . "</pre>");
}