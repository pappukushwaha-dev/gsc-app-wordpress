<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Define the log file location
$logFile = __DIR__ . '/update_crm_cron.log';

try {
    // -------------------------------------------------------------------------
    // 1. ESTABLISH TARGET DATABASE CONNECTION
    // -------------------------------------------------------------------------
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

    // -------------------------------------------------------------------------
    // 2. FETCH ONLY NEW / UPDATED SUBSCRIPTIONS
    // -------------------------------------------------------------------------
    $stmt = $pdo->query("
        SELECT
            sub.id,
            sub.instance_id,
            sub.plan_name,
            sub.status,
            sub.billing_period,
            sub.started_at,
            sub.expires_on,
            sub.updated_at
        FROM app_subscriptions sub
        LEFT JOIN WpSite ws
            ON sub.instance_id COLLATE utf8mb4_unicode_ci =
               ws.instance_id COLLATE utf8mb4_unicode_ci
        WHERE (
                sub.last_synced_at IS NULL
                OR sub.updated_at > sub.last_synced_at
              )
          AND sub.updated_at <= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
          AND (ws.email NOT LIKE '%makkpress%')
          AND (ws.shop_domain NOT LIKE '%makkpress%')
        ORDER BY sub.updated_at ASC
    ");

    $subscriptions = $stmt->fetchAll();

    if (!$subscriptions) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] INFO: No pending subscription records found." . PHP_EOL, FILE_APPEND);
        die("<h3>No pending subscription records found.</h3>");
    }

    $updated = 0;
    $failed  = 0;
    $skipped = 0;

    // -------------------------------------------------------------------------
    // 3. PREPARE STATEMENTS OUTSIDE THE LOOP
    // -------------------------------------------------------------------------
    // Update target CRM lead table metrics
    $updateLead = $targetDb->prepare("
        UPDATE `lead` SET 
            cf_plan_name            = :cf_plan_name,
            cf_subscription_status  = :cf_subscription_status,
            cf_subscription_frequen = :cf_subscription_frequen,
            cf_subscription_start_d = :cf_subscription_start_d,
            cf_subscription_end_dat = :cf_subscription_end_dat
        WHERE website LIKE :instance_match1
           OR cf_login_link LIKE :instance_match2
    ");

    // Track internal local sync timestamp metrics
    $updateSync = $pdo->prepare("
        UPDATE app_subscriptions
        SET last_synced_at = NOW()
        WHERE id = :id
    ");

    // -------------------------------------------------------------------------
    // 4. DATA SYNCHRONIZATION LOOP
    // -------------------------------------------------------------------------
    foreach ($subscriptions as $sub) {
        try {
            $planName   = isset($sub['plan_name']) ? trim((string)$sub['plan_name']) : '';
            $status     = isset($sub['status']) ? trim((string)$sub['status']) : 'active';
            $startedAt  = $sub['started_at'] ?? '';
            $expiresOn  = $sub['expires_on'] ?? '';

            // --- NO PLAN / SUBSCRIPTION DETAILS FALLBACK ---
            if ($planName === '') {
                $planName              = 'no plan';
                $status                = '';
                $subscriptionFrequency = '';
                $startedAt             = '';
                $expiresOn             = '';
            } else {
                // Handle Free Plan frequency override fallback rules
                $billingPeriod = $sub['billing_period'] ?? '';
                if (stripos($planName, 'free') !== false) {
                    $status                = 'active';
                    $subscriptionFrequency = 'Lifetime';
                } else {
                    $subscriptionFrequency = $billingPeriod;
                }
                
                // --- EXPIRED FUNCTIONALITY ---
                if (!empty($expiresOn)) {
                    $expireTimestamp = strtotime($expiresOn);
                    if ($expireTimestamp !== false && $expireTimestamp < time()) {
                        $status = 'expired';
                    }
                }
            }

            // Bind instance boundaries securely using wildcards
            $instanceMatch = '%' . trim((string)$sub['instance_id']) . '%';

            // Execute local to destination transaction update
            $updateLead->execute([
                ':cf_plan_name'            => $planName,
                ':cf_subscription_status'  => $status,
                ':cf_subscription_frequen' => $subscriptionFrequency,
                ':cf_subscription_start_d' => $startedAt,
                ':cf_subscription_end_dat' => $expiresOn,
                ':instance_match1'         => $instanceMatch,
                ':instance_match2'         => $instanceMatch
            ]);

            // Mark record synced only if a matching lead entry was successfully mutated
            if ($updateLead->rowCount() > 0) {
                $updateSync->execute([
                    ':id' => $sub['id']
                ]);
                $updated++;
                echo "<p style='color: green; margin: 4px 0;'>✔ Successfully updated Lead Details for Subscription ID: <strong>{$sub['id']}</strong> (Status: {$status})</p>";
            } else {
                $skipped++;
                echo "<p style='color: orange; margin: 4px 0;'>⚠ No matching Lead found for Subscription ID: <strong>{$sub['id']}</strong> (Skipped)</p>";
            }

        } catch (Exception $e) {
            $failed++;
            echo "<p style='color: red; margin: 4px 0;'>
                    ✘ Failed updating Lead details for Subscription ID: <strong>{$sub['id']}</strong><br>
                    <small>Error: " . htmlspecialchars($e->getMessage()) . "</small>
                  </p>";
        }
    }

    // -------------------------------------------------------------------------
    // 5. RUNTIME METRIC VISUALIZATION & LOGGING
    // -------------------------------------------------------------------------
    $totalExtracted = count($subscriptions);
    $timestamp = date('Y-m-d H:i:s');
    
    // Append structured metrics log line
    $logMsg = "[$timestamp] SUMMARY - Extracted: $totalExtracted | Updated: $updated | Skipped: $skipped | Failed: $failed" . PHP_EOL;
    file_put_contents($logFile, $logMsg, FILE_APPEND);

} catch (PDOException $e) {
    // Append database/fatal initialization failures to log file
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] FATAL DATABASE ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    die("<h3>Fatal Database Exception Occurred:</h3><pre>" . htmlspecialchars($e->getMessage()) . "</pre>");
}