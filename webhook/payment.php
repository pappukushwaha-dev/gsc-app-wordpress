<?php
declare(strict_types=1);

require __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/credentials.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

use Stripe\Webhook;

$stripeCreds = get_stripe_credentials($pdo);

$endpointSecret = $stripeCreds['webhook_secret'];

if (!$endpointSecret) {
    http_response_code(500);
    echo 'Webhook secret not configured';
    exit;
}

function logToFile(string $label, $data): void
{
    $logFile = __DIR__ . '/stripe_webhook_debug.txt';

    $entry = "============================\n";
    $entry .= date('Y-m-d H:i:s') . " | " . $label . "\n";

    if (is_string($data)) {
        $entry .= $data . "\n";
    } else {
        $entry .= print_r($data, true) . "\n";
    }

    $entry .= "============================\n\n";

    file_put_contents($logFile, $entry, FILE_APPEND);
}
// logger
function logWebhook(PDO $pdo, string $stage, $data, ?string $eventType = null, ?string $error = null)
{
    $stmt = $pdo->prepare("
        INSERT INTO webhook_test_logs (stage, event_type, raw_data, error_message)
        VALUES (:stage, :event, :raw, :error)
    ");

    $stmt->execute([
        ':stage' => $stage,
        ':event' => $eventType,
        ':raw'   => json_encode($data, JSON_PRETTY_PRINT),
        ':error' => $error
    ]);
}

// Raw input
$payload = file_get_contents('php://input');

$headers = function_exists('getallheaders') ? getallheaders() : [];

$sigHeader = 
    $_SERVER['HTTP_STRIPE_SIGNATURE'] 
    ?? $_SERVER['REDIRECT_HTTP_STRIPE_SIGNATURE'] 
    ?? ($headers['Stripe-Signature'] ?? '')
    ?? '';

logWebhook($pdo, 'RAW 01', $payload);
logWebhook($pdo, 'RAW 05', $_SERVER);
logWebhook($pdo, 'HEADERS', $headers);

// verify signature
try {
    $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        logToFile('SIGNATURE OK', $event->type);

} catch (Throwable $e) {
    logWebhook($pdo, 'SIGNATURE_FAILED', $payload, null, $e->getMessage());
     logToFile('SIGNATURE FAILED', $e->getMessage());
    logToFile('FAILED PAYLOAD', $payload);
    http_response_code(400);
    exit;
}

// helper get plan
function getPlan(PDO $pdo, string $priceId): ?array
{
    $stmt = $pdo->prepare("
        SELECT name, billingPeriod
        FROM pricing_plans
        WHERE price_id = :pid
        LIMIT 1
    ");
    $stmt->execute([':pid' => $priceId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// event handle
switch ($event->type) {

    // subscription add/update
    case 'customer.subscription.created':
    case 'customer.subscription.updated':

        $sub = $event->data->object;
        $customerId = $sub->customer ?? null;
        // logToFile('SUB EVENT', $event->type);
        // logToFile('SUB OBJECT', $sub);
        $item = $sub->items->data[0] ?? null;

        $meta = $sub->metadata ?? (object)[];

       $instanceId = $meta->instance_id ?? null;
        $priceId = $item->price->id ?? null;

        logWebhook($pdo, 'SUB_META', [
            'subscription' => $sub->id,
            'instance_id' => $instanceId,
            'price_id' => $priceId
        ], $event->type);


        if (!$priceId || !$instanceId) {
            logWebhook($pdo, 'SKIPPED - PUS', [
                'priceId' => $priceId,
                'instance_id'  => $instanceId,
            ], $event->type, 'Missing price/user/site');
            break;
        }

        $plan = getPlan($pdo, $priceId);
        if (!$plan) {
            logWebhook($pdo, 'SKIPPED - PNF', [
                'priceId' => $priceId
            ], $event->type, 'Plan not found');
            break;
        }

        $stmt = $pdo->prepare("
            INSERT INTO app_subscriptions
            (instance_id, stripe_subscription_id, customer_id, plan_name, billing_period,
            status, price, currency, raw_event, started_at, expires_on, payment_method)
            VALUES
            (:instid,:sub,:cid,:plan,:period,:status,:price,:currency,:raw,
            FROM_UNIXTIME(:start),FROM_UNIXTIME(:end), :pm)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                stripe_subscription_id = VALUES(stripe_subscription_id),
                customer_id = COALESCE(VALUES(customer_id), customer_id),
                price = VALUES(price),
                currency = VALUES(currency),
                raw_event = VALUES(raw_event),
                expires_on = VALUES(expires_on),
                payment_method = VALUES(payment_method),
                updated_at = NOW()
        ");

        $stmt->execute([
            ':instid'      => $instanceId,
            ':sub'      => $sub->id,
            ':cid'      => $customerId,
            ':plan'     => $plan['name'],
            ':period'   => $plan['billingPeriod'],
            ':status'   => $sub->status,
            ':price'    => $item->price->unit_amount / 100,
            ':currency' => strtoupper($item->price->currency),
            ':raw'      => json_encode($event->toArray()),
            ':start'    => $sub->current_period_start,
            ':end'      => $sub->current_period_end,
            ':pm'       => 'Stripe'
        ]);

        logWebhook($pdo, 'SUBSCRIPTION_SAVED', [
            'subscription' => $sub->id,
            'priceId'      => $priceId
        ], $event->type);

    break;


    // subscription delete
    case 'customer.subscription.deleted':

        $sub = $event->data->object;

        $stmt = $pdo->prepare("
            UPDATE app_subscriptions
            SET status = 'cancelled',
                expires_on = NOW(),
                updated_at = NOW()
            WHERE stripe_subscription_id = :sid
        ");

        $stmt->execute([
            ':sid' => $sub->id
        ]);

        logWebhook($pdo, 'SUBSCRIPTION_CANCELLED', [
            'subscription' => $sub->id
        ], $event->type);

        break;


    // invoice payment success

case 'invoice.payment_succeeded':

    $invoice = $event->data->object;
    $customerId = $invoice->customer ?? null;

    $line = $invoice->lines->data[0] ?? null;
    if (!$line) {
        logWebhook($pdo, 'NO_LINE_ITEM', $invoice->id, $event->type);
        break;
    }

    // Extract IDs from YOUR payload structure
    $instanceId = $line->metadata->instance_id ?? null;
    $priceId    = $line->pricing->price_details->price ?? null;
    $subscriptionId = $invoice->parent->subscription_details->subscription ?? null;

    if (!$customerId && $subscriptionId) {
        $stmt = $pdo->prepare("
            SELECT customer_id 
            FROM app_subscriptions 
            WHERE stripe_subscription_id = :sid 
            LIMIT 1
        ");
        $stmt->execute([':sid' => $subscriptionId]);
        $customerId = $stmt->fetchColumn() ?: null;
    }

    if (!$instanceId || !$subscriptionId || !$priceId) {
        logWebhook($pdo, 'META_INCOMPLETE', [
            'instance_id'   => $instanceId,
            'subscription'  => $subscriptionId,
            'price_id'      => $priceId
        ], $event->type);
        break;
    }

    logWebhook($pdo, 'INVOICE_PARSED', [
        'invoice_id'   => $invoice->id,
        'subscription' => $subscriptionId,
        'price_id'     => $priceId,
        'instance_id'  => $instanceId
    ], $event->type);

    $plan = getPlan($pdo, $priceId);
    if (!$plan) {
        logWebhook($pdo, 'SKIPPED_PLAN', [
            'price_id' => $priceId
        ], $event->type);
        break;
    }

    try {
        $periodStart = $line->period->start;
        $periodEnd   = $line->period->end;

        // Check if subscription already exists
        $stmt = $pdo->prepare("
            SELECT id FROM app_subscriptions
            WHERE stripe_subscription_id = :sid
            LIMIT 1
        ");
        $stmt->execute([':sid' => $subscriptionId]);
        $exists = $stmt->fetch();

        if ($exists) {
            // Update existing
            $stmt = $pdo->prepare("
                UPDATE app_subscriptions
                SET status='active',
                    customer_id = COALESCE(:cid, customer_id),
                    price=:price,
                    currency=:currency,
                    raw_event=:raw,
                    expires_on=FROM_UNIXTIME(:end),
                    updated_at=NOW()
                WHERE stripe_subscription_id=:sid
            ");

            $stmt->execute([
                ':cid'      => $customerId,
                ':price'    => $invoice->amount_paid / 100,
                ':currency' => strtoupper($invoice->currency),
                ':raw'      => json_encode($event->toArray()),
                ':end'      => $periodEnd,
                ':sid'      => $subscriptionId
            ]);

            logWebhook($pdo, 'INVOICE_UPDATED', $subscriptionId, $event->type);

        } else {
            // Cancel other active subs for this instance
            $pdo->prepare("
                UPDATE app_subscriptions
                SET status = 'cancelled',
                    expires_on = NOW(),
                    updated_at = NOW()
                WHERE instance_id = :instid
                  AND status = 'active'
                  AND stripe_subscription_id != :sid
            ")->execute([
                ':instid' => $instanceId,
                ':sid'    => $subscriptionId
            ]);

            // Insert new
            $stmt = $pdo->prepare("
                INSERT INTO app_subscriptions
                (instance_id, stripe_subscription_id, customer_id, plan_name, billing_period,
                 status, price, currency, raw_event, started_at, expires_on, payment_method)
                VALUES
                (:instid,:sid,:cid,:plan,:period,'active',:price,:currency,:raw,
                 FROM_UNIXTIME(:start),FROM_UNIXTIME(:end), :pm)
                ON DUPLICATE KEY UPDATE
                    stripe_subscription_id = VALUES(stripe_subscription_id),
                    customer_id = COALESCE(VALUES(customer_id), customer_id),
                    plan_name = VALUES(plan_name),
                    billing_period = VALUES(billing_period),
                    status = VALUES(status),
                    price = VALUES(price),
                    currency = VALUES(currency),
                    raw_event = VALUES(raw_event),
                    expires_on = VALUES(expires_on),
                    payment_method = VALUES(payment_method),
                    updated_at = NOW()
            ");

            $stmt->execute([
                ':instid'   => $instanceId,
                ':sid'      => $subscriptionId,
                ':cid'      => $customerId,
                ':plan'     => $plan['name'],
                ':period'   => $plan['billingPeriod'],
                ':price'    => $invoice->amount_paid / 100,
                ':currency' => strtoupper($invoice->currency),
                ':raw'      => json_encode($event->toArray()),
                ':start'    => $periodStart,
                ':end'      => $periodEnd,
                ':pm'       => 'Stripe'
            ]);

            logWebhook($pdo, 'INVOICE_INSERTED', $subscriptionId, $event->type);
        }

    } catch (Throwable $e) {
        logWebhook($pdo, 'DB_ERROR', $subscriptionId, $event->type, $e->getMessage());
    }

    break;

    // debug
    default:
        logWebhook($pdo, 'UNHANDLED ', $event, $event->type);
}

// res
http_response_code(200);
echo json_encode(['success' => true]);
