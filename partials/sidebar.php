<?php
// =====================================================
// SHOP + INSTANCE (SHOPIFY SAFE)
// =====================================================
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$instanceIdForApi =
  $_GET['instanceid']
  ?? $_GET['instance_id']
  ?? $_SESSION['instance_id']
  ?? $_SESSION['instanceid']
  ?? '';

if (!$instanceIdForApi) {
  header("Location: auth/install.php");
  exit;
}

$_SESSION['instance_id'] = $instanceIdForApi;
$_SESSION['instanceid']  = $instanceIdForApi;

// Ecwid: billing/upgrade goes to our internal pricing page (instance-scoped).
$billingUrl = APP_URL . 'pricing.php?instance_id=' . urlencode($instanceIdForApi);

require_once __DIR__ . '/../includes/sidebar_data.php';

/* ==========================================================
   PLAN STATE

   Decided here, in one query pair, instead of in the browser.

   The box used to fetch api/subscription-status.php, rebuild the
   plan shape from the JSON, and work out badge, status, note and
   button client-side. Every bug fixed on it in the last two days
   lived in that layer: a note toggled with style.display while
   the element ships with class="hidden"; a CTA that had no idea
   a trial existed; a check written against plan_type "paid" when
   Basic normalises to "free". All the same failure: the data
   leaves the database, becomes JSON, and is reassembled by code
   that knows less than the query did.
========================================================== */
function gsc_plan_state(PDO $pdo, string $instanceId): array
{
    $state = [
        'block'            => 'free',
        'plan_name'        => null,
        'billing'          => null,
        'status'           => null,
        'expires_on'       => null,
        'expires_iso'      => null,
        'expiry_label'     => null,
        'countdown'        => null,
        'still_has_access' => false,
    ];

    if ($instanceId === '') {
        return $state;
    }

    $sub = null;
    try {
        $q = $pdo->prepare("
            SELECT plan_name, billing_period, started_at, expires_on, cancelled_at
            FROM app_subscriptions
            WHERE instance_id = ?
            ORDER BY started_at DESC, id DESC
            LIMIT 1
        ");
        $q->execute([$instanceId]);
        $sub = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('gsc_plan_state subscription: ' . $e->getMessage());
    }

    $trial = null;
    try {
        $q = $pdo->prepare("
            SELECT status, started_at, expires_on, cancelled_at
            FROM app_free_trials
            WHERE instance_id = ?
            ORDER BY started_at DESC, id DESC
            LIMIT 1
        ");
        $q->execute([$instanceId]);
        $trial = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('gsc_plan_state trial: ' . $e->getMessage());
    }

    $now = new DateTimeImmutable('now');

    $planName = trim((string)($sub['plan_name'] ?? ''));
    $billing  = strtolower((string)($sub['billing_period'] ?? ''));

    /* Free, Basic, and anything on a 'limited' or 'free' billing period is
       the platform's free tier, not a subscription - EXCEPT Grow. On this
       app the Grow plan can sit in the table with billing_period='limited',
       and it is a paid plan; treating limited as free here would show every
       Grow customer the free block. subscription-status.php carries the
       same special case. */
    $isGrow  = (stripos($planName, 'grow') !== false);
    $freeish = !$isGrow
            && (in_array(strtolower($planName), ['free', 'basic'], true)
                || in_array($billing, ['limited', 'free'], true));

    $isPaidPlan = ($sub && $planName !== '' && !$freeish);

    if ($isPaidPlan) {
        if ($isGrow && in_array($billing, ['limited', 'free', ''], true)) {
            $billing = 'monthly';
        }

        $state['plan_name'] = $planName;
        $state['billing']   = $billing;

        $subStart   = gsc_plan_date($sub['started_at'] ?? null);
        $subExpiry  = gsc_plan_date($sub['expires_on'] ?? null);
        $subCancel  = gsc_plan_date($sub['cancelled_at'] ?? null);

        /* expires_on can be NULL on an active recurring row. The billing
           endpoint back-computes the next renewal from started_at and the
           interval; the same is done here (display only - no DB write) so
           the paid block can show a renewal countdown instead of nothing. */
        if (!$subExpiry && $subStart) {
            $stepYearly = in_array($billing, ['yearly', 'annual'], true);
            $subExpiry  = $subStart->modify($stepYearly ? '+1 year' : '+30 days');
            while ($subExpiry <= $now) {
                $subExpiry = $subExpiry->modify($stepYearly ? '+1 year' : '+30 days');
            }
        }

        /* No status column is read here. app_subscriptions' status is not
           what subscription-status.php trusts either - it derives the state
           from cancelled_at and expires_on, and the sidebar must agree with
           the billing endpoint or the two will tell the merchant different
           stories. */
        if ($subCancel && $subCancel <= $now) {
            $state['status'] = 'cancelled';
        } elseif ($subCancel && $subCancel > $now) {
            $state['status'] = 'cancel_scheduled';
        } elseif ($subExpiry && $subExpiry <= $now) {
            $state['status'] = 'expired';
        } else {
            $state['status'] = 'active';
        }

        // Checked before the active case: otherwise a merchant who
        // cancelled reads "ACTIVE PLAN" until the day it lapses.
        if ($state['status'] !== 'active') {
            $state['block'] = 'ending';
            $end = ($subCancel && $subCancel > $now) ? $subCancel : $subExpiry;
            $state['still_has_access'] = ($end && $end > $now);
            gsc_plan_fill_dates($state, $end);
            return $state;
        }

        $state['block'] = 'paid';
        $state['still_has_access'] = true;
        gsc_plan_fill_dates($state, $subExpiry);
        return $state;
    }

    // Checked after paid: someone who trialled and then subscribed still
    // has a spent trial row, and would otherwise be told their trial
    // expired while they are paying.
    if ($trial) {
        $trialExpiry = gsc_plan_date($trial['expires_on'] ?? null);
        $trialCancel = gsc_plan_date($trial['cancelled_at'] ?? null);

        // Derived from the dates, as the billing endpoint does; the stored
        // status column stays 'active' after expiry on this app.
        $running = $trialExpiry && $trialExpiry > $now
                && !($trialCancel && $trialCancel <= $now);

        $state['block']  = $running ? 'trial-active' : 'trial-expired';
        $state['status'] = $running ? 'active' : 'expired';
        $state['still_has_access'] = $running;

        gsc_plan_fill_dates($state, $trialExpiry);
        return $state;
    }

    return $state;
}

function gsc_plan_date($raw): ?DateTimeImmutable
{
    if (empty($raw)) return null;
    try {
        return new DateTimeImmutable((string)$raw);
    } catch (Throwable $e) {
        return null;
    }
}

function gsc_plan_fill_dates(array &$state, ?DateTimeImmutable $expiry): void
{
    if (!$expiry) return;

    $state['expires_on']   = $expiry->format('Y-m-d H:i:s');
    $state['expires_iso']  = $expiry->format('c');
    $state['expiry_label'] = $expiry->format('j M Y');

    $diff = (new DateTimeImmutable('now'))->diff($expiry);
    $state['countdown'] = sprintf('%dD %02dH %02dMIN', (int)$diff->days, $diff->h, $diff->i);
}

$planState = gsc_plan_state($pdo, (string)$instanceIdForApi);

$faviconPath = $adminData['favicon_path'] ?? 'assets/images/logo-icon.png'; 
?>



<aside class="sidebar cstm-sidebar">
  <button type="button" class="sidebar-close-btn !mt-4">
    <iconify-icon icon="radix-icons:cross-2"></iconify-icon>
  </button>

  <div>
    <a href="dashboard.php?instance_id=<?= urlencode($instanceIdForApi) ?>" class="sidebar-logo">
      <img src="<?= htmlspecialchars($logoPath) ?>" alt="site logo" class="light-logo">
      <img src="<?= htmlspecialchars($darkLogoPath) ?>" alt="site logo" class="dark-logo">
      <img src="<?= htmlspecialchars($faviconPath) ?>" alt="site logo" class="logo-icon">
    </a>
  </div>

  <div class="sidebar-menu-area flex flex-col justify-start">
    <ul class="sidebar-menu cstm-sidebar-menu" id="sidebar-menu">
      <li>
        <a href="dashboard.php?instance_id=<?= urlencode($instanceIdForApi) ?>">
          <iconify-icon icon="solar:widget-4-outline" class="menu-icon"></iconify-icon>
          <span>Dashboard</span>
        </a>
      </li>

      <li class="dropdown">
        <a href="javascript:void(0)" class="transition submenu-toggle">
          <iconify-icon icon="solar:chart-square-outline" class="menu-icon"></iconify-icon>
          <span class="flex-1">Analytics</span>
        </a>

        <ul class="sidebar-submenu max-h-0 overflow-hidden pl-3 transition-all duration-300 ease-in-out">

          <li>
            <a href="gsc-report.php?shop=<?= urlencode($shop) ?>&tab=overview" data-tab="overview"
              class="sidebar-tab-link block py-2 text-sm hover:text-blue-500 flex items-center gap-2">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
              <span>Overview</span>
            </a>
          </li>

          <li>
            <a href="gsc-report.php?shop=<?= urlencode($shop) ?>&tab=intent_commercial" data-tab="intent_commercial"
              class="sidebar-tab-link block py-2 text-sm hover:text-blue-500 flex items-center gap-2">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                  d="M3 3h7l11 11-7 7L3 10V3z" />
                <circle cx="7.5" cy="7.5" r="1.5" fill="currentColor" />
              </svg>
              <span>Commercial</span>
            </a>
          </li>

          <li>
            <a href="gsc-report.php?shop=<?= urlencode($shop) ?>&tab=dates" data-tab="dates"
              class="sidebar-tab-link block py-2 text-sm hover:text-blue-500 flex items-center gap-2">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
              <span>Brand vs Non Brand</span>
            </a>
          </li>
          <li>
            <a href="gsc-report.php?shop=<?= urlencode($shop) ?>&tab=qp" data-tab="qp"
              class="sidebar-tab-link block py-2 text-sm hover:text-blue-500 flex items-center gap-2">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
              </svg>
              <span>Top Page</span>
            </a>
          </li>

          <li>
            <a href="gsc-report.php?tab=intent_informational" data-tab="intent_informational"
              class="sidebar-tab-link block py-2 text-sm hover:text-blue-500 flex items-center gap-2">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                  d="M4 5a2 2 0 012-2h12v16H6a2 2 0 01-2-2V5z" />
                <path stroke-width="2" stroke-linecap="round" d="M8 3v16" />
              </svg>
              <span>Informational</span>
            </a>
          </li>

          <li>
            <a href="gsc-report.php?shop=<?= urlencode($shop) ?>&tab=pages" data-tab="intent_informational"
              class="sidebar-tab-link block py-2 text-sm hover:text-blue-500 flex items-center gap-2">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              <span>Pages</span>
            </a>
          </li>

          <li>
            <a href="gsc-report.php?shop=<?= urlencode($shop) ?>&tab=countries" data-tab="countries"
              class="sidebar-tab-link block py-2 text-sm hover:text-blue-500 flex items-center gap-2">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span>Countries</span>
            </a>
          </li>

          <li>
            <a href="gsc-report.php?shop=<?= urlencode($shop) ?>&tab=devices" data-tab="devices"
              class="sidebar-tab-link block py-2 text-sm hover:text-blue-500 flex items-center gap-2">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
              </svg>
              <span>Devices</span>
            </a>
          </li>

          <li>
            <a href="gsc-report.php?shop=<?= urlencode($shop) ?>&tab=appearance" data-tab="appearance"
              class="sidebar-tab-link block py-2 text-sm hover:text-blue-500 flex items-center gap-2">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
              </svg>
              <span>Search Appearance</span>
            </a>
          </li>

          <li>
            <a href="gsc-report.php?shop=<?= urlencode($shop) ?>&tab=intent_transactional" data-tab="intent_transactional"
              class="sidebar-tab-link block py-2 text-sm hover:text-blue-500 flex items-center gap-2">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <circle cx="9" cy="20" r="1.5" />
                <circle cx="17" cy="20" r="1.5" />
                <path
                  d="M5 6h2l2 9h8l2-6H9"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round" />
              </svg>
              <span>Transactional</span>
            </a>
          </li>

          <li>
            <a href="gsc-report.php?shop=<?= urlencode($shop) ?>&tab=intent_navigational" data-tab="intent_navigational"
              class="sidebar-tab-link block py-2 text-sm hover:text-blue-500 flex items-center gap-2">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <!-- Outer circle -->
                <circle cx="12" cy="12" r="9" stroke-width="2" />
                <!-- Compass needle -->
                <path
                  d="M14.8 9.2L13 13l-3.8 1.8L11 11l3.8-1.8z"
                  stroke-width="2"
                  stroke-linejoin="round"
                  stroke-linecap="round" />
              </svg>
              <span>Navigational</span>
            </a>
          </li>

        </ul>

      </li>

      <li class="dropdown">
        <a href="javascript:void(0)" class="transition submenu-toggle">
          <iconify-icon icon="solar:folder-outline" class="menu-icon"></iconify-icon>
          <span class="flex-1">Help Center</span>
        </a>

        <ul class="sidebar-submenu max-h-0 overflow-hidden pl-3 transition-all duration-300 ease-in-out">
          <li>
            <a href="faq.php?instance_id=<?= urlencode($instanceIdForApi) ?>" class="block py-2 text-sm hover:text-blue-500">
              <iconify-icon icon="solar:question-circle-outline" class="menu-icon"></iconify-icon>
              <span>FAQs</span></a>
          </li>
          <li>
            <a href="instructions.php?instance_id=<?= urlencode($instanceIdForApi) ?>" class="block py-2 text-sm hover:text-blue-500">
              <iconify-icon icon="solar:document-text-outline" class="menu-icon"></iconify-icon>
              <span>Instructions</span></a>
          </li>
          <li>
            <a href="manage-tickets.php?instance_id=<?= urlencode($instanceIdForApi) ?>" class="block py-2 text-sm hover:text-blue-500">
              <iconify-icon icon="mdi:ticket-confirmation-outline" class="menu-icon"></iconify-icon>
              <span>Manage Tickets</span>
            </a>
          </li>
        </ul>
      </li>

            <li class="dropdown">
        <a href="javascript:void(0)" class="transition submenu-toggle">
          <iconify-icon icon="icon-park-outline:setting-two" class="menu-icon"></iconify-icon>
          <span class="flex-1">Setting</span>
        </a>
        <ul class="sidebar-submenu max-h-0 overflow-hidden pl-3 transition-all duration-300 ease-in-out">
          <li>
            <a href="my_domains.php?instance_id=<?= urlencode($instanceIdForApi) ?>">
              <iconify-icon icon="solar:global-linear" class="menu-icon"></iconify-icon>
              <span>My Domains</span>
            </a>
          </li>

          <li>
            <a href="sitemap.php?instance_id=<?= urlencode($instanceIdForApi) ?>">
              <iconify-icon icon="solar:map-point-wave-outline" class="menu-icon"></iconify-icon>
              <span>Sitemap Manager</span>
            </a>
          </li>
        </ul>
      </li>

      
      <div class="my-3 bg-neutral-100 dark:bg-gray-800 border-neutral-200 border p-4 rounded-2xl text-center hidden">
        <p class="text-xl font-semibold text-neutral-900">Trial Plan</p>
        <p class="text-sm opacity-90 mt-1">11 Days left</p>

        <button class="w-full mt-2 h-10 btn btn-cstm-primary flex justify-center items-center">
          Upgrade Plan
        </button>
        <p class="text-sm opacity-90 mt-5"> If already activated</p>
        <button class="w-full mt-2 light h-10 btn btn-cstm-primary flex justify-center items-center">
          CLICK HERE
        </button>
      </div>
      <!-- <li>
        <a href="other-apps.php?instance_id=<?= urlencode($instanceIdForApi) ?>">
          <iconify-icon icon="solar:widget-outline" class="menu-icon"></iconify-icon>
          <span>Our Other Apps</span>
        </a>
      </li> -->
      <!-- <li>
        <a href="settings.php?instance_id=<?= urlencode($instanceIdForApi) ?>">
          <iconify-icon icon="icon-park-outline:setting-two" class="menu-icon"></iconify-icon>
          <span>Setting</span>
        </a>
      </li> -->

      <!-- Trial Plan Box -->
      <!-- OTHER APPS -->
      <li class="sidebar-menu-group-title">Our Other Apps</li>
      <ul id="otherAppsSidebar">
        <li class="text-sm text-gray-500 px-4">Loading apps...</li>
      </ul>
      <li>
        <a href="other-apps.php">
          <iconify-icon icon="solar:widget-5-outline" class="menu-icon"></iconify-icon>
          <span>More Apps</span>
        </a>
      </li>
    </ul>
    <!-- ===== Clean Plan box (no repetition, compact) ===== -->
    <!-- The old #sidebar-plan-box stood here: one box with empty fields
         and around 400 lines of JavaScript to fill them. Replaced by the
         five blocks below, chosen in PHP so only one is ever written to
         the page. -->

    <div id="card-sidebar-plan-box" class="sidebar-plan-box my-4 rounded-lg shadow-sm" role="region" aria-labelledby="card-plan-title-label">

      <!-- Loading skeleton -->
      <div id="card-plan-box-loading" class="animate-pulse space-y-2 p-4 rounded-lg border border-neutral-200 dark:border-neutral-600 hidden">
        <div class="h-4 w-1/3 bg-neutral-200 dark:bg-neutral-700 rounded"></div>
        <div class="h-6 w-2/3 bg-neutral-200 dark:bg-neutral-700 rounded mt-2"></div>
        <div class="h-10 w-full bg-neutral-200 dark:bg-neutral-700 rounded mt-4"></div>
      </div>

      <!-- ============ BLOCK 1: FREE TRIAL (Not Activated) ============ -->
      <?php if ($planState['block'] === 'free'): ?>

      <div id="card-plan-block-free" class="card-plan-block p-3 rounded-lg border border-blue-100 bg-gradient-to-b from-[#ebfaff] to-white dark:bg-neutral-800 dark:border-neutral-700">
        <div class="flex flex-col items-start justify-between mb-3 border-b pb-2 border-gray-200">
          <span class="flex items-center justify-center w-10 h-10 rounded-full bg-cstm-primary-10 text-blue-600 mb-3">
            <iconify-icon icon="ph:gift-duotone" class="text-2xl"></iconify-icon>
          </span>
          <h3 class="text-base font-bold text-neutral-900 dark:text-white">Free Trial</h3>
          <span class="text-xs font-semibold uppercase tracking-wider text-blue-600 mb-1">TRIAL AVAILABLE</span>
          <span class="text-[10px] font-bold text-white py-0.5 px-3 rounded-full bg-success-500">FREE TRIAL</span>
        </div>

        <div class="text-lg font-semibold text-blue-600 dark:text-white mb-0.5">14 Days Free</div>
        <div class="text-xs text-neutral-800 font-medium dark:text-neutral-400 mb-2">when you activate</div>

        <ul class="space-y-2 hidden mb-2">
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <span class="flex items-center justify-center w-5 h-5 rounded-full bg-cstm-primary-20 text-blue-600 shrink-0"><iconify-icon icon="mdi:chart-line" class="text-xs"></iconify-icon></span> Full access to all features
          </li>
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <span class="flex items-center justify-center w-5 h-5 rounded-full bg-cstm-primary-20 text-blue-600 shrink-0"><iconify-icon icon="mdi:magnify" class="text-xs"></iconify-icon></span> Advanced insights &amp; data
          </li>
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <span class="flex items-center justify-center w-5 h-5 rounded-full bg-cstm-primary-20 text-blue-600 shrink-0"><iconify-icon icon="mdi:shield-check-outline" class="text-xs"></iconify-icon></span> No credit card required
          </li>
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <span class="flex items-center justify-center w-5 h-5 rounded-full bg-cstm-primary-20 text-blue-600 shrink-0"><iconify-icon icon="mdi:check-circle-outline" class="text-xs"></iconify-icon></span> Cancel anytime
          </li>
        </ul>

        <a href="javascript:void(0)" class="openTrialModal text-xs font-semibold text-blue-600 hover:text-blue-700 inline-flex items-center gap-1 mb-2">
          Activate free trial <iconify-icon icon="mdi:chevron-right" class="text-base"></iconify-icon>
        </a>

        <a id="card-plan-cta-free" href="javascript:void(0)"
          class="openTrialModal block w-full text-center py-2.5 rounded-lg text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 transition shadow-sm">
          Start Free Trial
        </a>
      </div>

      <!-- ============ BLOCK 2: TRIAL ACTIVE ============ -->
      <?php elseif ($planState['block'] === 'trial-active'): ?>

      <div id="card-plan-block-trial-active" class="card-plan-block p-3 rounded-lg border border-success-100 bg-gradient-to-b from-success-50 to-white dark:bg-neutral-800 dark:border-neutral-700">
        <div class="flex flex-col items-start justify-between mb-3 border-b pb-3 border-gray-200">
          <span class="flex items-center justify-center w-10 h-10 rounded-full bg-success-100 text-success-500 mb-3">
            <iconify-icon icon="ph:shield-check-duotone" class="text-2xl"></iconify-icon>
          </span>
          <h3 class="text-base font-bold text-neutral-900 dark:text-white">Trial Active</h3>
          <span class="text-xs font-semibold uppercase tracking-wider text-success-500 mb-1">PREMIUM ACCESS</span>
          <span class="text-[10px] font-bold text-white py-0.5 px-3 rounded-full bg-success-500">TRIAL ACTIVE</span>
        </div>

        <!-- Timer Card Box -->
        <div class="rounded-xl bg-success-50 dark:bg-neutral-700/40 border border-success-300 dark:border-neutral-600 p-4 mb-3 text-center">
          <div class="flex items-center justify-center gap-1.5 text-neutral-800 dark:text-white">
            <span id="card-plan-countdown-active" class="text-lg font-bold" data-expires="<?= htmlspecialchars((string)$planState['expires_iso']) ?>"><?= htmlspecialchars((string)$planState['countdown']) ?></span>
          </div>
          <div class="text-[10px] font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-widest mt-0.5 mb-2">REMAINING</div>
          <div class="text-xs text-neutral-600 dark:text-neutral-300 text-center">Your trial ends on <span id="card-plan-expiry-active" class="font-semibold text-success-600 dark:text-success-400"><?= htmlspecialchars((string)$planState['expiry_label']) ?></span></div>
        </div>

        <ul class="space-y-2 hidden mb-4">
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <iconify-icon icon="mdi:check-circle-outline" class="text-success-500 text-xs shrink-0"></iconify-icon> Full access to all premium features
          </li>
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <iconify-icon icon="mdi:check-circle-outline" class="text-success-500 text-xs shrink-0"></iconify-icon> Advanced insights &amp; data
          </li>
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <iconify-icon icon="mdi:check-circle-outline" class="text-success-500 text-xs shrink-0"></iconify-icon> No credit card required
          </li>
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <iconify-icon icon="mdi:check-circle-outline" class="text-success-500 text-xs shrink-0"></iconify-icon> Cancel anytime
          </li>
        </ul>

        <a id="card-plan-cta-trial-active" href="pricing.php?instance_id=<?= urlencode($instanceIdForApi) ?>" target="_top"
          class="w-full py-2.5 rounded-lg text-[10px] font-medium text-white bg-success-800 hover:bg-success-900 transition flex items-center justify-center gap-2 shadow-sm">
          <iconify-icon icon="mdi:crown" class="text-sm text-amber-400"></iconify-icon>
          <span>Upgrade to Grow</span>
          <iconify-icon icon="mdi:chevron-right" class="text-sm ml-auto"></iconify-icon>
        </a>
      </div>

      <!-- ============ BLOCK 3: TRIAL EXPIRED ============ -->
      <?php elseif ($planState['block'] === 'trial-expired'): ?>

      <div id="card-plan-block-trial-expired" class="card-plan-block p-3 rounded-lg border border-danger-100 bg-gradient-to-b from-danger-50 to-white dark:bg-neutral-800 dark:border-neutral-700">
        <div class="flex flex-col items-start justify-between mb-3 border-b pb-2 border-gray-200">
          <span class="flex items-center justify-center w-10 h-10 rounded-full bg-danger-100 text-danger-500 mb-3">
            <iconify-icon icon="ph:x-circle-duotone" class="text-2xl"></iconify-icon>
          </span>
          <h3 class="text-base font-bold text-neutral-900 dark:text-white">Trial Expired</h3>
          <span class="text-xs font-semibold uppercase tracking-wider text-danger-500 mb-1">ACCESS ENDED</span>
          <span class="text-[10px] font-bold text-white py-0.5 px-3 rounded-full bg-danger-500">TRIAL ENDED</span>
        </div>

        <!-- Alert Box -->
        <div class="rounded-xl bg-danger-50 dark:bg-neutral-700/40 border border-danger-200/40 dark:border-neutral-600 p-2 mb-2 flex items-start gap-3">
          <div class="text-xs text-neutral-700 dark:text-neutral-300 leading-relaxed">
            <span class="font-bold text-neutral-900 dark:text-white mb-1">Your trial has ended on <span id="card-plan-expiry-expired" class="text-sm"><?= htmlspecialchars((string)$planState['expiry_label']) ?></span>.</span>
            Upgrade your plan to continue accessing premium insights and reports.
          </div>
        </div>

        <div class="text-xs font-medium text-neutral-800 dark:text-neutral-200 mb-2">What you're missing:</div>
        <ul class="space-y-2 hidden mb-3">
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <iconify-icon icon="mdi:close-circle-outline" class="text-danger-600 text-xs shrink-0"></iconify-icon> Performance data &amp; reports
          </li>
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <iconify-icon icon="mdi:close-circle-outline" class="text-danger-600 text-xs shrink-0"></iconify-icon> Advanced insights
          </li>
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <iconify-icon icon="mdi:close-circle-outline" class="text-danger-600 text-xs shrink-0"></iconify-icon> Historical data
          </li>
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <iconify-icon icon="mdi:close-circle-outline" class="text-danger-600 text-xs shrink-0"></iconify-icon> Regular updates
          </li>
        </ul>

        <a id="card-plan-cta-trial-expired" href="pricing.php?instance_id=<?= urlencode($instanceIdForApi) ?>" target="_top"
          class="w-full py-2.5 rounded-md text-xs font-medium text-danger-600 border border-danger-200 bg-white hover:bg-danger-50 transition flex items-center justify-center gap-1 shadow-sm">
          <span>View Plans &amp; Pricing</span>
          <iconify-icon icon="mdi:chevron-right" class="text-sm"></iconify-icon>
        </a>
      </div>

      <!-- ============ BLOCK 4: PAID / GROW ============ -->
      <?php elseif ($planState['block'] === 'paid'): ?>

      <div id="card-plan-block-paid" class="card-plan-block p-3 rounded-lg bg-cstm-primary-gradient-dark text-white relative overflow-hidden shadow-lg border border-blue-900">

        <!-- Top Right Paid Badge -->
        <span class="absolute top-4 right-4 text-[10px] font-bold tracking-wider px-2.5 py-0.5 rounded-full bg-success-600 text-white uppercase">PAID</span>

        <div class="flex flex-col items-start justify-between mb-3 border-b pb-2 border-gray-200">
          <span class="flex items-center justify-center w-10 h-10 rounded-full bg-blue-600/40 text-amber-400 mb-3 border border-blue-500/30">
            <iconify-icon icon="mdi:crown" class="text-2xl"></iconify-icon>
          </span>
          <h3 id="card-plan-title-paid" class="text-base font-bold text-white mb-1"><?= htmlspecialchars((string)($planState['plan_name'] ?: 'Your plan')) ?></h3>
          <span class="text-xs font-semibold uppercase tracking-wider text-blue-300">ACTIVE PLAN</span>
        </div>

        <!-- Dark Inner Box -->
        <div class="rounded-xl bg-[#071931]/80 border border-blue-800/50 p-2 mb-3 text-center" style="background-color: #08275E;">
          <div class="flex flex-col items-center justify-center gap-2 text-xl font-extrabold text-white">
            <div id="card-plan-countdown-paid" <?= $planState['expires_iso'] ? 'data-expires="' . htmlspecialchars((string)$planState['expires_iso']) . '"' : '' ?>>
              <?php if ($planState['countdown']): ?>
                <span class="text-lg"><?= htmlspecialchars((string)$planState['countdown']) ?></span>
                <div class="text-xs font-bold text-blue-300 uppercase tracking-widest">UNTIL RENEWAL</div>
              <?php else: ?>
                <!-- A recurring subscription stores no expiry. "Recurring" is
                     the honest label; inventing a date is how the dashboard
                     popup came to show "8H 59MIN" on a plan that was not
                     expiring at all. -->
                <span class="text-lg">Recurring</span>
                <div class="text-xs font-bold text-blue-300 uppercase tracking-widest"><?= htmlspecialchars(strtoupper((string)($planState['billing'] ?: 'ACTIVE'))) ?></div>
              <?php endif; ?>
            </div>

          </div>
        </div>
        <?php if ($planState['expiry_label']): ?>
          <div class="text-xs font-medium text-blue-200 text-center mb-2">Renews on <span id="card-plan-expiry-paid" class="font-medium text-white"><?= htmlspecialchars((string)$planState['expiry_label']) ?></span></div>
        <?php endif; ?>

        <ul class="space-y-2 hidden mt-2 mb-3">
          <li class="flex items-center gap-2.5 text-xs text-blue-100">
            <span class="rounded-full w-6 h-6 bg-cstm-primary-20 flex justify-center items-center shrink-0"><iconify-icon icon="mdi:chart-line" class="text-blue-400 text-xs shrink-0"></iconify-icon></span> Premium analytics &amp; reports
          </li>
          <li class="flex items-center gap-2.5 text-xs text-blue-100">
            <span class="rounded-full w-6 h-6 bg-cstm-primary-20 flex justify-center items-center shrink-0"><iconify-icon icon="mdi:magnify" class="text-blue-400 text-xs shrink-0"></iconify-icon></span> Advanced SEO insights
          </li>
          <li class="flex items-center gap-2.5 text-xs text-blue-100">
            <span class="rounded-full w-6 h-6 bg-cstm-primary-20 flex justify-center items-center shrink-0"><iconify-icon icon="mdi:sync" class="text-blue-400 text-xs shrink-0"></iconify-icon></span> Unlimited data sync
          </li>
          <li class="flex items-center gap-2.5 text-xs text-blue-100">
            <span class="rounded-full w-6 h-6 bg-cstm-primary-20 flex justify-center items-center shrink-0"><iconify-icon icon="mdi:headset" class="text-blue-400 text-xs shrink-0"></iconify-icon></span> Priority support
          </li>
          <li class="flex items-center gap-2.5 text-xs text-blue-100">
            <span class="rounded-full w-6 h-6 bg-cstm-primary-20 flex justify-center items-center shrink-0"><iconify-icon icon="mdi:cog-outline" class="text-blue-400 text-xs shrink-0"></iconify-icon></span> Cancel or change anytime
          </li>
        </ul>

        <a id="card-plan-cta-paid" href="pricing.php?instance_id=<?= urlencode($instanceIdForApi) ?>" target="_top"
          class="w-full py-2.5 rounded-lg text-xs font-medium text-cstm-primary bg-white hover:bg-blue-50 transition flex items-center justify-center gap-1 shadow-md">
          <span>Manage Plan</span>
          <iconify-icon icon="mdi:chevron-right" class="text-xs"></iconify-icon>
        </a>
      </div>
      <!-- ============ BLOCK 5: PLAN ENDING / ENDED ============

           Covers cancelled, non_renewing and lapsed. One block on purpose:
           to the merchant, "I cancelled it" and "it ran out" lead to the
           same next step, and splitting them means two screens that read
           the same.

           The distinction that DOES matter is inside: whether access is
           still running. non_renewing keeps working until the paid period
           ends, and that date is the only thing here worth reading.

           Cancelled and lapsed rows otherwise read "ACTIVE PLAN"
           until someone notices - on Wix that was 208 accounts.
      ============================================================= -->
      <?php elseif ($planState['block'] === 'ending'): ?>

      <div id="card-plan-block-ending" class="card-plan-block p-3 rounded-lg border dark:bg-neutral-800 dark:border-neutral-700"
           style="border-color:#fde68a;background:linear-gradient(to bottom,#fffbeb,#fff);">

        <div class="flex flex-col items-start justify-between mb-3 border-b pb-2 border-gray-200">
          <span class="flex items-center justify-center w-10 h-10 rounded-full mb-3" style="background:#fef3c7;color:#b45309;">
            <iconify-icon icon="ph:clock-countdown-duotone" class="text-2xl"></iconify-icon>
          </span>
          <h3 class="text-base font-bold text-neutral-900 dark:text-white"><?= htmlspecialchars((string)($planState['plan_name'] ?: 'Your plan')) ?></h3>
          <span class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:#b45309;"><?= $planState['still_has_access'] ? 'ENDING SOON' : 'PLAN ENDED' ?></span>
          <span class="text-[10px] font-bold text-white py-0.5 px-3 rounded-full" style="background:#f59e0b;"><?= $planState['still_has_access'] ? 'NOT RENEWING' : 'CANCELLED' ?></span>
        </div>

        <?php if ($planState['still_has_access'] && $planState['countdown']): ?>
          <div class="rounded-xl dark:bg-neutral-700/40 border dark:border-neutral-600 p-4 mb-3 text-center" style="background:#fffbeb;border-color:#fcd34d;">
            <div class="flex items-center justify-center gap-1.5 text-neutral-800 dark:text-white">
              <span id="card-plan-countdown-ending" class="text-lg font-bold" data-expires="<?= htmlspecialchars((string)$planState['expires_iso']) ?>"><?= htmlspecialchars((string)$planState['countdown']) ?></span>
            </div>
            <div class="text-[10px] font-bold text-neutral-500 dark:text-neutral-400 uppercase tracking-widest mt-0.5 mb-2">ACCESS REMAINING</div>
            <div class="text-xs text-neutral-600 dark:text-neutral-300 text-center">You keep everything until <span class="font-semibold dark:text-amber-400" style="color:#b45309;"><?= htmlspecialchars((string)$planState['expiry_label']) ?></span></div>
          </div>
        <?php elseif ($planState['expiry_label']): ?>
          <div class="rounded-xl bg-neutral-50 dark:bg-neutral-700/40 border border-neutral-200 dark:border-neutral-600 p-4 mb-3 text-center">
            <div class="text-sm font-semibold text-neutral-800 dark:text-white">Ended <?= htmlspecialchars((string)$planState['expiry_label']) ?></div>
          </div>
        <?php endif; ?>

        <!-- What they lose. Listing what they still have would be the wrong
             list. This is the reason to come back. -->
        <ul class="space-y-2 hidden mb-3">
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <iconify-icon icon="mdi:chart-line" class="text-xs shrink-0" style="color:#f59e0b;"></iconify-icon> Premium analytics &amp; reports
          </li>
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <iconify-icon icon="mdi:magnify" class="text-xs shrink-0" style="color:#f59e0b;"></iconify-icon> Advanced SEO insights
          </li>
          <li class="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-300">
            <iconify-icon icon="mdi:database-outline" class="text-xs shrink-0" style="color:#f59e0b;"></iconify-icon> Your data is kept &mdash; nothing is deleted
          </li>
        </ul>

        <a id="card-plan-cta-ending" href="pricing.php?instance_id=<?= urlencode($instanceIdForApi) ?>" target="_top"
          class="block w-full text-center py-2.5 rounded-lg text-xs font-medium text-white transition shadow-sm"
          style="background:#d97706;">
          <?= $planState['still_has_access'] ? 'Keep my plan' : 'Reactivate plan' ?>
        </a>
      </div>

      <?php endif; ?>

      <!-- Error -->
      <div id="card-plan-box-error" class="text-xs text-rose-600 mt-2 p-4 hidden"></div>
    </div>
    <!-- ===== end plan box ===== -->
    <ul class="mb-4 flex flex-col gap-2 sidebar-bottom-menus">
      <li class="review-btn-block w-full">
        <a href="javascript:void(0)" id="openReviewModal" class="btn bg-success-600 text-white justify-center gap-1 items-center w-full px-3">
          <iconify-icon icon="mdi:star-circle-outline" class="menu-icon text-xl"></iconify-icon>
          <span class="text-base">Leave a Review</span>
        </a>
      </li>
      <!-- <li>
        <button type="button" id="btn-new-ticket" class="btn btn-cstm-primary justify-center gap-1 items-center w-full px-3">
          <iconify-icon icon="mdi:headset" class="text-lg"></iconify-icon>
          <span>Contact Support</span>
        </button>
      </li> -->
      <!-- <li class="review-btn-block w-full">
        <a href="javascript:void(0)" onclick="CancellationFlow.open()" class="btn btn-cstm-primary light justify-center gap-1 items-center w-full px-3">
          <iconify-icon icon="mdi:close-circle-outline" class="menu-icon text-xl"></iconify-icon>
          <span>Cancel Subscription</span>
        </a>
      </li> -->
    </ul>
  </div>
</aside>

<script>
  document.addEventListener("DOMContentLoaded", () => {
    const body = document.body;
    const sidebar = document.querySelector(".sidebar");
    const sidebarToggle = document.querySelector(".sidebar-toggle");
    const sidebarMobileToggle = document.querySelector(".sidebar-mobile-toggle");
    const sidebarCloseBtn = document.querySelector(".sidebar-close-btn");

    // Create overlay if missing
    let overlay = document.querySelector(".sidebar-overlay");
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.className = "sidebar-overlay";
      document.body.appendChild(overlay);
    }

    function toggleSidebar() {
      const isOpen = body.classList.toggle("sidebar-open");
      sidebar.setAttribute("aria-expanded", isOpen);
      overlay.classList.toggle("active", isOpen);
    }

    [sidebarToggle, sidebarMobileToggle, sidebarCloseBtn, overlay].forEach(el => {
      if (el) el.addEventListener("click", toggleSidebar);
    });

    function handleResponsiveSidebar() {
      if (window.innerWidth <= 1024) {
        body.classList.remove("sidebar-open");
        overlay.classList.remove("active");
      }
    }

    window.addEventListener("resize", handleResponsiveSidebar);
    handleResponsiveSidebar();
  });
</script>

<script>
/* ==========================================================
   All that is left of the plan JavaScript.

   What stood here: a fetch of subscription-status.php, a
   normaliser, a renderer, a CTA builder and a five-minute
   refresh, deciding in the browser what the database already
   knew. PHP decides it above.

   Two things still need a browser:

     1. The countdown has to move without a reload.
     2. window.currentPlan was published by the removed block
        and is read by checkPageAccess below, which other pages
        call. It is set from PHP now rather than from a fetch.
========================================================== */

const instanceId = <?= json_encode($instanceIdForApi) ?>;

// Shape kept as the old code published it, so anything reading
// window.currentPlan elsewhere keeps working unchanged.
window.currentPlan = {
    type: <?= json_encode(
        $planState['block'] === 'paid' ? 'paid'
            : ($planState['block'] === 'trial-active' ? 'free'
            : ($planState['block'] === 'ending' && $planState['still_has_access'] ? 'paid' : 'none'))
    ) ?>,
    status:      <?= json_encode((string)($planState['status'] ?? '')) ?>,
    billing:     <?= json_encode((string)($planState['billing'] ?? '')) ?>,
    plan_name:   <?= json_encode($planState['plan_name']) ?>,
    block:       <?= json_encode($planState['block']) ?>,
    expires_on:  <?= json_encode($planState['expires_on']) ?>,
    trialExists: <?= json_encode(in_array($planState['block'], ['trial-active','trial-expired'], true)) ?>
};

document.dispatchEvent(new CustomEvent("planLoaded", { detail: window.currentPlan }));

(function () {
    'use strict';

    /* The ticker. Finds whichever countdown is on the page by its
       data-expires attribute, so it does not care which block is
       showing and needs no change when one is added. */
    function tick() {
        var els = document.querySelectorAll('[data-expires]');
        if (!els.length) return;

        var now = new Date();

        els.forEach(function (el) {
            var end = new Date(el.getAttribute('data-expires'));
            if (isNaN(end)) return;

            var ms = end - now;

            if (ms <= 0) {
                el.textContent = 'Expired';
                return;
            }

            var mins  = Math.floor(ms / 60000);
            var days  = Math.floor(mins / 1440);
            var hours = Math.floor((mins % 1440) / 60);
            var rem   = mins % 60;

            // Same shape PHP rendered, so nothing jumps on the first tick.
            el.textContent = days + 'D '
                + String(hours).padStart(2, '0') + 'H '
                + String(rem).padStart(2, '0') + 'MIN';
        });
    }

    // Every 30s. A minute-resolution display does not need per-second
    // work, and this box is on every page in the app.
    setInterval(tick, 30000);
})();

document.addEventListener("DOMContentLoaded", () => {

    window.checkPageAccess = function(requiredPlan = "paid") {
      const plan = window.currentPlan;
      const overlayElement = document.getElementById("page-lock-overlay");
      if (!overlayElement) return;
      const btn = document.getElementById("page-lock-btn");
      if (!plan) {
        overlayElement.style.display = 'flex';
        if (btn) {
          btn.textContent = "View Plans";
          btn.href = "current-plan.php";
        }
        return;
      }
      if (requiredPlan === "paid" && plan.type !== "paid") {
        overlayElement.style.display = "flex";
        if (btn) {
          btn.textContent = plan.type === "free" ? "Upgrade Plan" : "Activate Trial";
          btn.href = "current-plan.php?instance_id=" + encodeURIComponent(instanceId || "");
        }
        return;
      }
      overlayElement.style.display = "none";
    };

  });
</script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    // Only attach handler if we're NOT on manage-tickets page (which has its own handler)
    const currentPath = window.location.pathname || window.location.href;
    if (currentPath.includes('manage-tickets') || currentPath.includes('manage-tickets.php')) {
      return; // Let manage-tickets.php handle its own button
    }

    const sidebarBtn = document.getElementById('btn-new-ticket');

    if (sidebarBtn) {
      sidebarBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (window.TicketPopup && typeof window.TicketPopup.open === 'function') {
          window.TicketPopup.open();
        } else {
          console.warn('TicketPopup not available');
        }
      });
    }
  });
</script>
<script>
  document.addEventListener('DOMContentLoaded', function() {

    const sidebar = document.getElementById('otherAppsSidebar');
    const instanceId = '<?php echo $_SESSION["instanceid"] ?? ""; ?>';

    if (!sidebar) {
      console.error('otherAppsSidebar not found');
      return;
    }

    if (!instanceId) {
      sidebar.innerHTML = '<li class="text-sm text-gray-500 px-4">Instance ID missing</li>';
      return;
    }

    fetch(`api/index-apps.php?instance_id=${instanceId}`)
      .then(res => res.json())
      .then(res => {

        if (!res.success || !Array.isArray(res.data)) {
          sidebar.innerHTML = '<li class="text-sm text-gray-500 px-4">No apps available.</li>';
          return;
        }

        sidebar.innerHTML = '';

        res.data.forEach(app => {

          if (!app.button_link) return; // 🔥 skip broken rows

          sidebar.innerHTML += `
                    <li>
                        <a href="${app.button_link}"
                           target="_blank"
                           class="flex items-center gap-3 px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-md">

                            <img src="${app.image_url || ''}"
                                 class="w-5 h-5 object-contain"
                                 alt="">

                            <span>${app.title}</span>
                        </a>
                    </li>
                `;
        });
      })
      .catch(err => {
        console.error(err);
        sidebar.innerHTML =
          '<li class="text-sm text-red-500 px-4">Failed to load apps.</li>';
      });
  });
</script>
<?php include __DIR__ . '/review-popup.php'; ?>
<?php include __DIR__ . '/cancel-popup.php'; ?>
<?php //include __DIR__ . '/trial-popup.php'; ?>
<?php include __DIR__ . '/preview-popup.php'; ?>
