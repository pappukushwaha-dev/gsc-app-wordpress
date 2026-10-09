<?php
declare(strict_types=1);

$title = 'Current Plan';
$subTitle = 'Manage your subscription';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// helper escape
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// Resolve instance id (support multiple names)
$instanceId = $_SESSION['instanceid'] ?? $_SESSION['instance_id'] ?? ($_GET['instance_id'] ?? ($_GET['instanceId'] ?? ($_POST['instance_id'] ?? null)));
$instanceId = is_string($instanceId) ? trim($instanceId) : null;

// API path
$apiUrl = '/wordpress/googlesearchconsole/api/subscription-status.php';

// Build absolute URL using host/scheme for server-side cURL
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? null);
if ($host) {
    $path = (strpos($apiUrl, '/') === 0) ? $apiUrl : '/' . ltrim($apiUrl, '/');
    $fullUrl = $scheme . '://' . $host . $path . '?' . http_build_query(['instance_id' => $instanceId]);
} else {
    $fullUrl = $apiUrl . '?' . http_build_query(['instance_id' => $instanceId]);
}

// call API
$apiResponse = null;
$apiError = null;
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_FAILONERROR, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Makkpress-Server/1.0 (+https://' . ($host ?? 'localhost') . ')');
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        $apiError = sprintf('Empty response from API. http_code=%s; curl_error=%s; url=%s', $httpCode ?: 'n/a', $curlErr ?: 'n/a', $fullUrl);
    } else {
        $decoded = @json_decode($raw, true);
        if (!is_array($decoded)) {
            $apiError = sprintf('API returned invalid JSON. http_code=%s; raw_len=%d; url=%s', $httpCode ?: 'n/a', strlen($raw), $fullUrl);
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $apiError .= ' raw=' . substr($raw, 0, 2000);
            }
        } else {
            $apiResponse = $decoded;
        }
    }
} else {
    $apiError = 'cURL not available in PHP on this server.';
}

// Normalize API response into $subscription
$subscription = null;
$trialEligible = false;
if (is_array($apiResponse)) {
    if (!empty($apiResponse['subscription']) && is_array($apiResponse['subscription'])) {
        $subscription = $apiResponse['subscription'];
    } else {
        // fallback if older flat shape returned
        $subscription = $apiResponse;
    }
    $trialEligible = !empty($apiResponse['trial_eligible']);
}

// compute view variables
$currentType = null; // 'paid' | 'free' | null
$currentLabel = 'No active plan';
$status = null;
$billingPeriod = null;
$expiresOn = null;
$startedOn = null;
$cancelledOn = null;
$shopifyPlanId = null;
$price = null;
$currency = null;
$billingCenterUrl = null;

if (is_array($subscription)) {
    $stype = strtolower((string)($subscription['type'] ?? ''));
    $planName = $subscription['plan_name'] ?? null;
    $billingPeriod = strtolower((string)($subscription['billing_period'] ?? $subscription['billingPeriod'] ?? ''));
    $status = strtolower((string)($subscription['status'] ?? ''));

    if ($stype === 'subscription') $currentType = 'paid';
    if ($stype === 'limited') $currentType = 'paid';
    if ($stype === 'free_trial') $currentType = 'free';

    if ($currentType === 'paid') {
        $currentLabel = h($planName ?? 'Subscription') . ($billingPeriod ? ' (' . h($billingPeriod) . ')' : '');
    } elseif ($currentType === 'free') {
        $currentLabel = ($planName ? h($planName) . ' ' : '') . 'Free Trial';
    } else {
        $currentLabel = $planName ? h($planName) : 'No active plan';
    }

    $expiresOn = $subscription['expires_on'] ?? ($subscription['expiresOn'] ?? null);
    $startedOn = $subscription['started_at'] ?? ($subscription['startedAt'] ?? null);
    $cancelledOn = $subscription['cancelled_at'] ?? ($subscription['cancelledAt'] ?? null);

    $shopifyPlanId = $subscription['shopifyPlanId'] ?? ($subscription['vendorProductId'] ?? null);
    $price = isset($subscription['price']) ? $subscription['price'] : null;
    $currency = $subscription['currency'] ?? null;

    if (!empty($subscription['raw_event']) && is_string($subscription['raw_event'])) {
        $rawEvt = @json_decode($subscription['raw_event'], true);
        if (is_array($rawEvt) && !empty($rawEvt['billing_center_url'])) {
            $billingCenterUrl = $rawEvt['billing_center_url'];
        }
    }
}

// staff view detection
$isStaffView = !empty($_SESSION['is_staff']) || (!empty($_SESSION['staff_level']) && (int)$_SESSION['staff_level'] > 0);

// price formatter
function format_price_display($price, $currency = null) {
    if ($price === null || $price === 0) return 'Free';
    if ($currency) return h($currency) . ' ' . number_format((float)$price, 2);
    return '$' . number_format((float)$price, 2);
}

include './partials/layouts/layoutTop.php';
?>

<div class="card h-full p-0 rounded-xl border-0 overflow-hidden">
  <div class="card-header border-b border-neutral-200 dark:border-neutral-600 bg-white dark:bg-neutral-700 py-4 px-6 flex items-center justify-between">
    <div>
      <h4 class="text-xl font-medium text-neutral-800 dark:text-neutral-100 mb-0">Subscription</h4>
      <p class="text-base text-neutral-500 dark:text-neutral-400 mb-0">Quick view of your active plan and actions.</p>
      <?php if (!empty($apiError)): ?>
        <div class="text-xs text-warning mt-2">API error: <?php echo h($apiError); ?></div>
      <?php endif; ?>
    </div>

    <div class="flex items-center gap-3">
      <a href="pricing.php" class="inline-block px-4 py-2 rounded-md border border-primary-600 text-primary-600 text-sm">View Plans</a>
      <?php if (!empty($billingCenterUrl)): ?>
        <a href="<?php echo h($billingCenterUrl); ?>" class="inline-block px-4 py-2 rounded-md bg-primary-600 text-white text-sm">Billing Center</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card-body p-8">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

      <!-- Summary card (left) -->
      <div class="col-span-1">
        <div class="relative rounded-[24px] overflow-hidden border py-6 px-6 bg-neutral-100 dark:bg-neutral-800 border-neutral-200 dark:border-neutral-600">
          <div class="flex items-center gap-4">
            <span class="w-[56px] h-[56px] flex justify-center items-center rounded-2xl bg-white dark:bg-neutral-700">
              <iconify-icon icon="lucide:layers" class="text-primary-600 text-2xl"></iconify-icon>
            </span>
            <div>
              <h6 class="mb-0 text-lg"><?php echo $currentLabel ? h($currentLabel) : 'No active plan'; ?></h6>
              <p class="text-sm text-secondary-light mt-1 mb-0">Price: <?php echo format_price_display($price, $currency); ?> <?php if (!empty($billingPeriod)): ?><span class="text-xs">/ <?php echo h($billingPeriod); ?></span><?php endif; ?></p>
            </div>
          </div>

          <div class="mt-4 text-secondary-light">
            <?php if ($currentType === 'paid'): ?>
              <div class="text-sm"><strong>Status:</strong> <?php echo h(strtoupper((string)$status ?: 'active')); ?></div>
              <?php if (!empty($expiresOn) && strtolower((string)$billingPeriod) !== 'limited'): ?>
                <div class="text-sm mt-1"><strong>Next renewal / expires:</strong> <?php echo h(date('Y-m-d', strtotime($expiresOn))); ?></div>
              <?php endif; ?>
            <?php elseif ($currentType === 'free'): ?>
              <div class="text-sm"><strong>Status:</strong> On trial</div>
              <?php if ($expiresOn): ?><div class="text-sm mt-1"><strong>Trial ends:</strong> <?php echo h(date('Y-m-d', strtotime($expiresOn))); ?></div><?php endif; ?>
            <?php elseif ($subscription === null): ?>
              <div class="text-sm"><strong>Status:</strong> No active subscription</div>
            <?php else: ?>
              <div class="text-sm"><strong>Status:</strong> <?php echo h($status ?: 'unknown'); ?></div>
            <?php endif; ?>
          </div>

          <div class="mt-6">
            <?php if ($currentType === 'paid'): ?>
              <button id="change-plan" class="w-full inline-block px-3 py-2.5 rounded-lg border border-primary-600 <?php echo 'text-primary-600'; ?> text-sm">Change plan</button>

              <form method="post" action="api/billing.php" onsubmit="return confirm('Cancel subscription?');" class="mt-3">
                <input type="hidden" name="instance_id" value="<?php echo h($instanceId ?? ''); ?>">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="w-full inline-block px-3 py-2.5 rounded-lg bg-white text-red-600 border border-red-600 text-sm">Cancel subscription</button>
              </form>

            <?php elseif ($currentType === 'free'): ?>
              <a href="pricing.php" class="w-full inline-block px-3 py-2.5 rounded-lg bg-primary-600 text-white text-center text-sm">Upgrade to paid</a>

            <?php else: ?>
              <?php if ($trialEligible): ?>
                <form method="post" action="api/billing.php" onsubmit="return confirm('Activate free trial?');">
                  <input type="hidden" name="instance_id" value="<?php echo h($instanceId ?? ''); ?>">
                  <input type="hidden" name="action" value="activate_trial">
                  <button type="submit" class="w-full inline-block px-3 py-2.5 rounded-lg border border-primary-600 text-primary-600 text-sm">Activate Trial</button>
                </form>
              <?php else: ?>
                <a href="pricing.php" class="w-full inline-block px-3 py-2.5 rounded-lg bg-primary-600 text-white text-center text-sm">Choose a plan</a>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Billing & invoices (middle) - show only if relevant -->
      <?php $showBilling = !empty($billingCenterUrl) || ($currentType === 'paid' && strtolower((string)$billingPeriod) !== 'limited'); ?>
      <?php if ($showBilling): ?>
        <div class="col-span-1 lg:col-span-1">
          <div class="relative rounded-[24px] overflow-hidden border py-6 px-6 bg-white dark:bg-neutral-800 border-neutral-200 dark:border-neutral-600">
            <h6 class="mb-3 text-sm font-medium">Billing & invoices</h6>
            <div class="text-sm text-secondary-light">
              <?php if (!empty($billingCenterUrl)): ?>
                <p>Manage invoices and payment methods in your billing center.</p>
                <div class="mt-3">
                  <a href="<?php echo h($billingCenterUrl); ?>" class="inline-block px-3 py-2 rounded-lg bg-primary-600 text-white text-sm">Open Billing Center</a>
                </div>
              <?php else: ?>
                <p>Your subscription is managed by the app billing system. For invoices or payment methods please contact support.</p>
              <?php endif; ?>
            </div>

            <?php if ($isStaffView): ?>
              <div class="mt-4 text-sm text-secondary-light">
                <h6 class="mb-2 text-sm font-medium">Quick info (staff)</h6>
                <ul class="list-disc ml-4 space-y-1">
                  <li>Change plan → opens Pricing (choose a plan and confirm)</li>
                  <li>Cancel → backend handles grace/refund rules</li>
                  <li>Activate trial → backend decides eligibility</li>
                </ul>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- Support (right) - only for staff to avoid exposing instructions to customers -->
      <?php if ($isStaffView): ?>
        <div class="col-span-1 lg:col-span-1">
          <div class="relative rounded-[24px] overflow-hidden border py-6 px-6 bg-white dark:bg-neutral-800 border-neutral-200 dark:border-neutral-600">
            <h6 class="mb-3 text-sm font-medium">Support</h6>
            <p class="text-sm text-secondary-light mb-3">Need help with billing or plan changes? Contact support or use the billing center (if available).</p>
            <a href="/helpdesk" class="inline-block px-3 py-2 rounded-lg border border-neutral-300 text-sm">Contact Support</a>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const changeBtn = document.getElementById('change-plan');
    if (changeBtn) {
        changeBtn.addEventListener('click', () => {
            // go to pricing page anchor
            location.href = 'pricing.php#button-tab-content';
        });
    }
});
</script>

<?php include './partials/layouts/layoutBottom.php'; ?>
