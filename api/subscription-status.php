<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../includes/config.php'; // $pdo

/* --------------------------------------------------
   Helpers
-------------------------------------------------- */
function jsonResponse(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

function iso(?DateTimeImmutable $d): ?string {
    return $d ? $d->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM) : null;
}

/**
 * Extract useful fields from Shopify / Wix raw_event
 */
function extractEventFields(?string $raw): array {
    if (!$raw) return [];

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];

    $out = [];

    // ✅ Shopify AppSubscription payload
    if (!empty($decoded['app_subscription'])) {
        $sub = $decoded['app_subscription'];

        $out['price']    = isset($sub['price']) ? (float)$sub['price'] : null;
        $out['currency'] = $sub['currency'] ?? null;
        $out['interval'] = $sub['interval'] ?? null;
        $out['plan']     = $sub['name'] ?? null;
    }

    // ✅ Backward compatibility (Wix / legacy)
    foreach (['vendorProductId','invoiceId','price','currency'] as $k) {
        if (!isset($out[$k]) && isset($decoded[$k])) {
            $out[$k] = $decoded[$k];
        }
    }

    return $out;
}


/**
 * Normalize billing period safely
 */
function normalizeBillingPeriod(?string $period, array $evt): string {
    $p = strtolower((string)$period);
    $interval = strtolower((string)($evt['interval'] ?? ''));

    if (in_array($p, ['monthly','every_30_days'], true) || str_contains($interval, '30')) {
        return 'monthly';
    }

    if (in_array($p, ['yearly','annual','every_365_days'], true) || str_contains($interval, '365')) {
        return 'yearly';
    }

    return 'monthly'; // safe default
}

/* --------------------------------------------------
   Resolve instance
-------------------------------------------------- */
$instanceId =
    $_GET['instance_id']
    ?? $_POST['instance_id']
    ?? $_GET['instanceId']
    ?? $_SESSION['instanceid']
    ?? $_SESSION['instance_id']
    ?? '';

$instanceId = trim((string)$instanceId);

if ($instanceId === '') {
    jsonResponse(400, [
        'success' => false,
        'error'   => 'missing_instance_id',
        'message' => 'instance_id is required'
    ]);
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$computeStatus = function (
    ?DateTimeImmutable $started,
    ?DateTimeImmutable $expires,
    ?DateTimeImmutable $cancelled
) use ($now): string {
    if ($cancelled && $cancelled <= $now) return 'cancelled';
    if ($cancelled && $cancelled >  $now) return 'cancel_scheduled';
    if ($expires   && $expires   <= $now) return 'expired';
    return 'active';
};

/* ==================================================
   1) PAID SUBSCRIPTION (Shopify / recurring)
   Includes: Grow plan, monthly/yearly billing, or any plan that's not basic/free
================================================== */
$stmt = $pdo->prepare(
    "SELECT * FROM app_subscriptions
     WHERE instance_id = :iid
       AND (
           -- Explicitly include Grow plan (even if billing_period is 'limited')
           (LOWER(plan_name) LIKE '%grow%')
           OR
           -- Standard paid plans with monthly/yearly billing
           (billing_period IN ('monthly', 'yearly', 'annual') 
            AND billing_period <> 'limited' 
            AND billing_period <> 'free'
            AND plan_name NOT LIKE '%basic%' 
            AND plan_name NOT LIKE '%Basic%' 
            AND plan_name NOT LIKE '%free%' 
            AND plan_name NOT LIKE '%Free%')
       )
     ORDER BY started_at DESC, id DESC
     LIMIT 1"
);
$stmt->execute([':iid' => $instanceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $started   = $row['started_at']   ? new DateTimeImmutable($row['started_at'],   new DateTimeZone('UTC')) : null;
    $expires   = $row['expires_on']   ? new DateTimeImmutable($row['expires_on'],   new DateTimeZone('UTC')) : null;
    $cancelled = $row['cancelled_at'] ? new DateTimeImmutable($row['cancelled_at'], new DateTimeZone('UTC')) : null;

    $evt = extractEventFields($row['raw_event'] ?? null);
    
    // For Grow plan, ensure billing_period is not 'limited' (default to monthly if it is)
    $billingPeriod = normalizeBillingPeriod($row['billing_period'], $evt);
    $planNameLower = strtolower($row['plan_name'] ?? '');
    if (strpos($planNameLower, 'grow') !== false && $billingPeriod === 'limited') {
        $billingPeriod = 'monthly'; // Grow plan should always be treated as paid
    }

    // If expires_on is NULL and subscription is active, calculate next billing date
    // Use started_at + interval from raw_event (e.g., "every_30_days" = 1 month)
    $shouldUpdateDatabase = false;
    if (!$expires && $started) {
        $expires = clone $started;
        
        // Check raw_event for interval first (more accurate)
        $interval = strtolower($evt['interval'] ?? '');
        if (strpos($interval, '30') !== false || $interval === 'every_30_days') {
            // Monthly billing
            $expires = $expires->modify('+30 days');
        } elseif (strpos($interval, '365') !== false || $interval === 'annual' || $interval === 'yearly') {
            // Yearly billing
            $expires = $expires->modify('+1 year');
        } elseif ($billingPeriod === 'yearly' || $billingPeriod === 'annual') {
            $expires = $expires->modify('+1 year');
        } else {
            // Default to monthly (30 days)
            $expires = $expires->modify('+30 days');
        }
        
        // If the calculated date is in the past, add another billing period until future
        while ($expires <= $now) {
            if (strpos($interval, '365') !== false || $interval === 'annual' || $interval === 'yearly' || $billingPeriod === 'yearly' || $billingPeriod === 'annual') {
                $expires = $expires->modify('+1 year');
            } else {
                $expires = $expires->modify('+30 days');
            }
        }
        
        // Mark that we should update the database
        $shouldUpdateDatabase = true;
    }

    $computedStatus = $computeStatus($started, $expires, $cancelled);
    
    // Update expires_on in database if we calculated it
    if ($shouldUpdateDatabase && $expires) {
        try {
            $updateStmt = $pdo->prepare("
                UPDATE app_subscriptions 
                SET expires_on = :expires_on 
                WHERE instance_id = :instance_id 
                  AND id = :id
            ");
            $updateStmt->execute([
                ':expires_on' => $expires->format('Y-m-d H:i:s'),
                ':instance_id' => $instanceId,
                ':id' => $row['id']
            ]);
        } catch (Exception $e) {
            // Log error but don't fail the request
            error_log('Failed to update expires_on in database: ' . $e->getMessage());
        }
    }

    // If plan is cancelled (cancel_scheduled or cancelled), treat as free plan
    if ($computedStatus === 'cancel_scheduled' || $computedStatus === 'cancelled') {
        // Return as free/limited plan instead of paid subscription
        jsonResponse(200, [
            'success' => true,
            'subscription' => [
                'type'           => 'subscription',
                'plan_name'      => 'Free',
                'billing_period' => 'limited',
                'status'         => 'active', // Show as active but with limited access
                'started_at'     => iso($started),
                'expires_on'     => iso($expires), // Show expiry date for countdown
                'cancelled_at'   => iso($cancelled),
                'shopifyPlanId'  => null,
                'price'          => null,
                'currency'       => null,
                'raw_event'      => $row['raw_event']
            ],
            'trial_eligible' => false
        ]);
    }

    // Always return the subscription, even if expired
    jsonResponse(200, [
        'success' => true,
        'subscription' => [
            'type'           => 'subscription',
            'plan_name'      => $row['plan_name'],
            'billing_period' => $billingPeriod,
            'status'         => $computedStatus, // Can be 'expired', 'active', 'cancelled', etc.
            'started_at'     => iso($started),
            'expires_on'     => iso($expires), // Calculated next billing date if NULL
            'cancelled_at'   => iso($cancelled),
            'shopifyPlanId'  => $evt['plan_handle'] ?? null,
            'price'          => $evt['price'] ?? null,
            'currency'       => $evt['currency'] ?? null,
            'raw_event'      => $row['raw_event']
        ],
        'trial_eligible' => false
    ]);
}

/* ==================================================
   2) FREE TRIAL
================================================== */
$stmt = $pdo->prepare(
    "SELECT * FROM app_free_trials
     WHERE instance_id = :iid
     ORDER BY started_at DESC, id DESC
     LIMIT 1"
);
$stmt->execute([':iid' => $instanceId]);
$trial = $stmt->fetch(PDO::FETCH_ASSOC);

if ($trial) {
    $started   = new DateTimeImmutable($trial['started_at'], new DateTimeZone('UTC'));
    $expires   = new DateTimeImmutable($trial['expires_on'], new DateTimeZone('UTC'));
    $cancelled = $trial['cancelled_at']
        ? new DateTimeImmutable($trial['cancelled_at'], new DateTimeZone('UTC'))
        : null;

    $trialStatus = $computeStatus($started, $expires, $cancelled);

    if ($trialStatus === 'active') {
        jsonResponse(200, [
            'success' => true,
            'subscription' => [
                'type'           => 'free_trial',
                'plan_name'      => 'Free Trial',
                'billing_period' => 'free',
                'status'         => 'active',
                'started_at'     => iso($started),
                'expires_on'     => iso($expires),
                'cancelled_at'   => iso($cancelled),
                'raw_event'      => $trial['raw_event']
            ],
            'trial_eligible' => false
        ]);
    }

    if ($trialStatus === 'expired' || $trialStatus === 'cancelled') {
        jsonResponse(200, [
            'success' => true,
            'subscription' => [
                'type'           => 'trial_expired',
                'plan_name'      => 'Free',
                'billing_period' => 'limited',
                'status'         => 'active',
                'started_at'     => iso($started),
                'expires_on'     => iso($expires),
                'cancelled_at'   => iso($cancelled),
                'raw_event'      => $trial['raw_event']
            ],
            'trial_eligible' => false
        ]);
    }
}

/* ==================================================
   3) FREE (LIMITED) - Includes Basic/Free plans (but NOT Grow)
================================================== */
$stmt = $pdo->prepare(
    "SELECT * FROM app_subscriptions
     WHERE instance_id = :iid
       AND LOWER(plan_name) NOT LIKE '%grow%'
       AND (billing_period = 'limited' 
            OR billing_period = 'free'
            OR plan_name LIKE '%basic%'
            OR plan_name LIKE '%Basic%'
            OR plan_name LIKE '%free%'
            OR plan_name LIKE '%Free%')
     ORDER BY started_at DESC, id DESC
     LIMIT 1"
);
$stmt->execute([':iid' => $instanceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $started = new DateTimeImmutable($row['started_at'], new DateTimeZone('UTC'));
    $planName = $row['plan_name'] ?? 'Basic';

    jsonResponse(200, [
        'success' => true,
        'subscription' => [
            'type'           => 'subscription',
            'plan_name'      => $planName,
            'billing_period' => 'limited',
            'status'         => 'active',
            'started_at'     => iso($started),
            'expires_on'     => null,
            'cancelled_at'   => null,
            'shopifyPlanId'  => null,
            'price'          => null,
            'currency'       => null,
            'raw_event'      => $row['raw_event']
        ],
        'trial_eligible' => false
    ]);
}

/* ==================================================
   4) NO PLAN
================================================== */
jsonResponse(200, [
    'success' => true,
    'subscription' => null,
    'trial_eligible' => true
]);
