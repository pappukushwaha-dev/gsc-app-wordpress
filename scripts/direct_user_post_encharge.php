<?php

declare(strict_types=1);

/**
 * direct_user_post_encharge.php
 * Initial push: sends users not yet in Encharge (post_encharge = 0), marks them synced.
 * CLI / URL safe. Port of the Wix script for WordPress.
 *
 * Differences from the Wix original, on purpose:
 * - is_paid uses the billing-based rule from api/start_trial.php
 *   (the Wix plan_name != FREE test misclassifies platforms where the
 *   free tier row carries a plan name)
 * - email/company/website come from WpSite itself (email, shop_name,
 *   domain/shop_domain) - no separate profile table needed
 * - google_accounts lookup retries with the other instance-id column
 *   spelling, since apps disagree (instanceId vs instance_id)
 * - the duplicated subscription block from the Wix file is gone
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$ENCHARGE_WRITE_KEY = 'n4ZJTpZktcyj32fQLBW555m4N';

/* Public base of THIS app, for the pricing/review links.
   Confirm the path before first run. */
$APP_PUBLIC_BASE = 'https://makkpressapps.com/wordpress/googlesearchconsole';

require_once __DIR__ . '/../includes/config.php';

$BATCH_LIMIT = 100;

/* -----------------------------------
 * Fetch users
 * ----------------------------------- */
$stmt = $pdo->query("
    SELECT
        s.id AS user_id,
        s.instance_id,
        s.email,
        s.shop_name,
        s.shop_domain,
        s.domain,
        s.created_at
    FROM WpSite s
    WHERE s.email IS NOT NULL AND s.email <> ''
      AND s.post_encharge = 0
    ORDER BY s.id DESC
    LIMIT " . (int)$BATCH_LIMIT . "
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $u) {

    $email = trim((string)$u['email']);
    if ($email === '') {
        continue;
    }

    /* -----------------------------------
     * Trial status
     * ----------------------------------- */
    $trialStmt = $pdo->prepare("
        SELECT status, started_at, expires_on
        FROM app_free_trials
        WHERE instance_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $trialStmt->execute([$u['instance_id']]);
    $trialRow = $trialStmt->fetch(PDO::FETCH_ASSOC);

    $trial_active  = 'no';
    $trial_expired = 'no';
    $trial_start   = null;
    $trial_end     = null;

    if ($trialRow) {
        $expTs = !empty($trialRow['expires_on']) ? strtotime((string)$trialRow['expires_on']) : null;
        if ($trialRow['status'] === 'active' && ($expTs === null || $expTs > time())) {
            $trial_active = 'yes';
        } elseif ($trialRow['status'] === 'expired' || ($expTs !== null && $expTs <= time())) {
            $trial_expired = 'yes';
        }
        if (!empty($trialRow['started_at'])) {
            $trial_start = date(DATE_ATOM, strtotime((string)$trialRow['started_at']));
        }
        if (!empty($trialRow['expires_on'])) {
            $trial_end = date(DATE_ATOM, strtotime((string)$trialRow['expires_on']));
        }
    }

    /* -----------------------------------
     * Latest subscription -> plan flags
     * ----------------------------------- */
    $planStmt = $pdo->prepare("
        SELECT plan_name, billing_period, status, started_at, expires_on, cancelled_at
        FROM app_subscriptions
        WHERE instance_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $planStmt->execute([$u['instance_id']]);
    $planRow = $planStmt->fetch(PDO::FETCH_ASSOC);

    /* Paid is decided by billing_period, never by plan name or bare
       status - the same rule api/start_trial.php uses. On this platform
       a subscription row only exists for purchases (billing GROUP BY
       showed monthly/yearly only), no row = free. */
    $plan_name    = 'FREE';
    $plan_active  = 'no';
    $plan_expired = 'no';
    $is_paid      = 'no';

    if ($planRow) {
        $plan_name = $planRow['plan_name'] ?: 'FREE';
        $status    = strtolower(trim((string)$planRow['status']));
        $billing   = strtolower(trim((string)$planRow['billing_period']));
        $now       = time();
        $expiresTs = !empty($planRow['expires_on']) ? strtotime((string)$planRow['expires_on']) : null;

        $dateOk = ($expiresTs === null || $expiresTs > $now);
        if ($status === 'active' && $dateOk) {
            $plan_active = 'yes';
        } elseif (($expiresTs !== null && $expiresTs <= $now) || $status !== 'active') {
            $plan_expired = 'yes';
        }

        if ($plan_active === 'yes' && in_array($billing, ['monthly', 'yearly', 'annual'], true)) {
            $is_paid = 'yes';
        }
    }

    /* -----------------------------------
     * Setup wizard steps (table may not exist on every install)
     * ----------------------------------- */
    $setup_step_1 = 'no';
    $setup_step_2 = 'no';
    $setup_step_3 = 'no';
    try {
        $wizardStmt = $pdo->prepare("
            SELECT step1, step2, step3
            FROM setup_wizard_status
            WHERE instance_id = ?
            LIMIT 1
        ");
        $wizardStmt->execute([$u['instance_id']]);
        $wizard = $wizardStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $setup_step_1 = !empty($wizard['step1']) ? 'yes' : 'no';
        $setup_step_2 = !empty($wizard['step2']) ? 'yes' : 'no';
        $setup_step_3 = !empty($wizard['step3']) ? 'yes' : 'no';
    } catch (Throwable $e) {
        // table absent - steps stay no
    }

    /* -----------------------------------
     * Domain verification + domain name
     * ----------------------------------- */
    $domainVerified = 'no';
    $domainName = null;
    try {
        $domainStmt = $pdo->prepare("
            SELECT site_url, verification_status
            FROM gsc_domain_verifications
            WHERE instance_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $domainStmt->execute([$u['instance_id']]);
        $domainRow = $domainStmt->fetch(PDO::FETCH_ASSOC);
        if ($domainRow) {
            if (!empty($domainRow['site_url'])) {
                $domainName = $domainRow['site_url'];
            }
            if (strtolower((string)$domainRow['verification_status']) === 'verified') {
                $domainVerified = 'yes';
            }
        }
    } catch (Throwable $e) {
        // stays no
    }

    /* -----------------------------------
     * Google account connected
     * (column spelling differs per app - try both)
     * ----------------------------------- */
    $googleConnected = 'no';
    foreach (['instanceId', 'instance_id'] as $col) {
        try {
            /* google_accounts has no google_user_id column on this app;
               connected means connected = 1 with a refresh token still stored. */
            $googleStmt = $pdo->prepare("
                SELECT connected, refresh_token
                FROM google_accounts
                WHERE $col = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $googleStmt->execute([$u['instance_id']]);
            $googleRow = $googleStmt->fetch(PDO::FETCH_ASSOC);
            if ($googleRow && (int)$googleRow['connected'] === 1 && !empty($googleRow['refresh_token'])) {
                $googleConnected = 'yes';
            }
            break; // query worked - do not try the other spelling
        } catch (Throwable $e) {
            // wrong column name on this install - try the other one
        }
    }

    /* -----------------------------------
     * Build payload
     * ----------------------------------- */
    $pricingLink = $APP_PUBLIC_BASE . '/pricing.php?instanceid=' . urlencode((string)$u['instance_id']);
    $reviewLink  = $APP_PUBLIC_BASE . '/customer-reviews.php?instanceid=' . urlencode((string)$u['instance_id']);
    $dashLink    = $APP_PUBLIC_BASE . '/action-center.php?instance_id=' . urlencode((string)$u['instance_id']);

    $payload = [
        'email'       => $email,
        'user_id'     => (int)$u['user_id'],
        'instance_id' => $u['instance_id'],

        'app_name' => 'Google Search Console',

        'googlesearch_trial_active'  => $trial_active,
        'googlesearch_trial_expired' => $trial_expired,

        'googlesearch_setup_step_1' => $setup_step_1,
        'googlesearch_setup_step_2' => $setup_step_2,
        'googlesearch_setup_step_3' => $setup_step_3,

        'googlesearch_signup_date'   => date(DATE_ATOM, strtotime((string)$u['created_at'])),
        'googlesearch_last_activity' => date(DATE_ATOM),

        'googlesearch_source' => 'WordPress',
        'googlesearch_domain_verified' => $domainVerified,
        'googlesearch_google_connected' => $googleConnected,

        'googlesearch_plan_name'    => $plan_name,
        'googlesearch_plan_active'  => $plan_active,
        'googlesearch_plan_expired' => $plan_expired,
        'googlesearch_is_paid'      => $is_paid,
        'googlesearch_upgrade_link' => $pricingLink,
        'googlesearch_review_link'  => $reviewLink,
        'googlesearch_dashboard_link' => $dashLink,
    ];

    if ($trial_start !== null) {
        $payload['googlesearch_trial_start'] = $trial_start;
    }
    if ($trial_end !== null) {
        $payload['googlesearch_trial_end'] = $trial_end;
    }
    if (!empty($u['shop_name'])) {
        $payload['googlesearch_company'] = $u['shop_name'];
    }
    $website = $u['domain'] ?: $u['shop_domain'];
    if (!empty($website)) {
        $payload['googlesearch_website'] = $website;
    }
    if ($domainName !== null) {
        $payload['googlesearch_domain_name'] = $domainName;
    }

    /* -----------------------------------
     * Send to Encharge
     * ----------------------------------- */
    $apiURL = 'https://api.encharge.io/v1/people?api_key=' . $ENCHARGE_WRITE_KEY;

    $ch = curl_init($apiURL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([$payload]),
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response   = curl_exec($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    /* -----------------------------------
     * Mark synced
     * ----------------------------------- */
    if ($httpStatus >= 200 && $httpStatus < 300) {
        $upd = $pdo->prepare("UPDATE WpSite SET post_encharge = 1 WHERE id = ?");
        $upd->execute([$u['user_id']]);
    }


    @file_put_contents(
        __DIR__ . '/encharge_direct_sync.log',
        date('Y-m-d H:i:s') . ' | ' . $email . ' | HTTP ' . $httpStatus . PHP_EOL,
        FILE_APPEND
    );
}

echo "direct_user_post_encharge.php completed\n";
