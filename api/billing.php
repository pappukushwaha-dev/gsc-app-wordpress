<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e) {
        http_response_code(500);
        echo json_encode([
            'fatal' => true,
            'type' => $e['type'],
            'message' => $e['message'],
            'file' => $e['file'],
            'line' => $e['line'],
        ]);
    }
});


header('Content-Type: application/json; charset=utf-8');


/**
 * --------------------------------------------------
 * 1. Bootstrap (DB + session)
 * --------------------------------------------------
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/credentials.php';

/**
 * --------------------------------------------------
 * 2. Stripe Autoload
 * --------------------------------------------------
 */
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe library missing. Run composer require stripe/stripe-php']);
    exit;
}
require_once $autoloadPath;

/**
 * --------------------------------------------------
 * 3. Resolve Stripe Credentials (DB-backed)
 * --------------------------------------------------
 */
try {
    $stripeCreds = get_stripe_credentials($pdo);
} catch (Throwable $e) {
    $stripeCreds = get_stripe_credentials($pdo);
    echo($stripeCreds);
    error_log('Stripe credential error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Stripe credentials unavailable']);
    exit;
}

if (empty($stripeCreds['secret_key'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe secret key not configured']);
    exit;
}

\Stripe\Stripe::setApiKey($stripeCreds['secret_key']);

/**
 * --------------------------------------------------
 * 4. Auth
 * --------------------------------------------------
 */


$instanceId = $_POST['instance_id'] 
            ?? $_GET['instance_id'] 
            ?? ($_SESSION['instanceid'] ?? null);
$instanceId = is_string($instanceId) ? trim($instanceId) : null;

if ($instanceId) {
    $_SESSION['instanceid'] = $instanceId;
}


if (!$instanceId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing instance_id']);
    exit;
}


/**
 * --------------------------------------------------
 * 6. Read Input
 * --------------------------------------------------
 */
$planId        = $_POST['plan_id'] ?? null;     // DB id (free plan)
$priceId       = $_POST['price_id'] ?? null;   // Stripe price id (paid)
$billingPeriod = $_POST['billing_period'] ?? null;

if (
    !in_array($billingPeriod, ['monthly', 'yearly', 'free'], true) ||
    ($billingPeriod !== 'free' && !$priceId)
) {

    http_response_code(400);
    echo json_encode(['error' => 'Invalid plan request']);
    exit;
}

/**
 * --------------------------------------------------
 * 7. Fetch Pricing Plan
 * --------------------------------------------------
 */
$stmt = $pdo->prepare("
    SELECT id, name, code, price, billingPeriod
    FROM pricing_plans
WHERE billingPeriod = :bp
  AND showInMarket = 1
LIMIT 1

");
$stmt->execute([
    ':bp' => $billingPeriod
]);

$plan = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$plan) {
    http_response_code(404);
    echo json_encode(['error' => 'Plan not found']);
    exit;
}

$price  = (float) $plan['price'];
$isFree = ($billingPeriod === 'free' || $price <= 0.0);

/**
 * --------------------------------------------------
 * 8. Active Subscription Check
 * --------------------------------------------------
 */
$check = $pdo->prepare("
    SELECT billing_period
    FROM app_subscriptions
    WHERE instance_id = :instance_id
      AND status = 'active'
    LIMIT 1
");
$check->execute([':instance_id' => $instanceId]);






$activeBillingPeriod = $check->fetchColumn();

if ($activeBillingPeriod && $isFree) {
    http_response_code(409);
    echo json_encode([
        'error' => 'Cannot downgrade to Free while a paid plan is active'
    ]);
    exit;
}

/**
 * --------------------------------------------------
 * 9. FREE PLAN (DB-only activation)
 * --------------------------------------------------
 */
if ($isFree) {
    try {
        $pdo->beginTransaction();

        // app_subscriptions.instance_id is UNIQUE, so we can't insert a second
        // row for the same instance. Upsert by instance_id: flip whatever row
        // exists (paid / cancelled / trial) into an active Free row, or insert
        // fresh if there's none yet. Stripe fields are cleared so future
        // Stripe events for the old subscription don't collide with this Free
        // record.
        $stmt = $pdo->prepare("
            INSERT INTO app_subscriptions
                (
                    instance_id,
                    stripe_subscription_id,
                    customer_id,
                    plan_name,
                    billing_period,
                    status,
                    price,
                    currency,
                    raw_event,
                    started_at,
                    expires_on,
                    cancelled_at,
                    payment_method
                )
            VALUES
                (
                    :instance_id,
                    NULL,
                    NULL,
                    :plan_name,
                    'free',
                    'active',
                    0,
                    'USD',
                    NULL,
                    NOW(),
                    NULL,
                    NULL,
                    'free'
                )
            ON DUPLICATE KEY UPDATE
                stripe_subscription_id = NULL,
                customer_id            = NULL,
                plan_name              = VALUES(plan_name),
                billing_period         = 'free',
                status                 = 'active',
                price                  = 0,
                currency               = 'USD',
                raw_event              = NULL,
                started_at             = NOW(),
                expires_on             = NULL,
                cancelled_at           = NULL,
                payment_method         = 'free',
                updated_at             = NOW()
        ");

        $stmt->execute([
            ':instance_id' => (string)$instanceId,
            ':plan_name'   => $plan['name'],
        ]);

        $pdo->commit();

        header("Location: " . APP_URL . 'dashboard.php?instance_id=' . urlencode($instanceId));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Free plan insert error: ' . $e->getMessage());

        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to activate free plan',
        ]);
        exit;
    }
}
    $stmt = $pdo->prepare("
        SELECT email, domain
        FROM WpSite
        WHERE instance_id = :instance_id
        LIMIT 1
    ");
    $stmt->execute([
        ':instance_id' => $instanceId
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $ownerEmail = isset($row['email']) ? trim($row['email']) : null;
    $websiteUrl = isset($row['domain']) ? trim($row['domain']) : null;

    if (!empty($websiteUrl) && !preg_match("~^https?://~", $websiteUrl)) {
        $websiteUrl = "https://" . $websiteUrl;
    }

    if (!$ownerEmail || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Owner email not found for this instance']);
        exit;
    }

    if (!$websiteUrl || !filter_var($websiteUrl, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or missing website URL']);
        exit;
    }

/**
 * --------------------------------------------------
 * 10. PAID PLAN (Stripe Checkout)
 * --------------------------------------------------
 */
try {
    $session = \Stripe\Checkout\Session::create([
        'mode' => 'subscription',
       'customer_email' => $ownerEmail,

'allow_promotion_codes'=> true,

        'line_items' => [[
            'price' => $priceId,   
            'quantity' => 1,
            
        ]],


            // 🔥 IMPORTANT FIX
        'subscription_data' => [
            'metadata' => [
                'instance_id'  => (string)$instanceId,
                'platform' => 'wordpress-googlesearchconsole',
                'billing_period' => $billingPeriod,
                'price_id' => $priceId,
                'website_url'  => $websiteUrl,
                       
            ],
        ],

        'success_url' =>
            'https://makkpressapps.com/wordpress/googlesearchconsole/thank-you.php?session_id={CHECKOUT_SESSION_ID}&instance_id=' .
            urlencode($instanceId),

        'cancel_url' =>
           'https://makkpressapps.com/wordpress/googlesearchconsole/pricing.php',
    ]);

    header("Location: " . $session->url);
        exit;

}catch (Throwable $e) {
    error_log('Stripe checkout error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Stripe checkout failed',
        'stripe_error' => $e->getMessage(),
    ]);
}

