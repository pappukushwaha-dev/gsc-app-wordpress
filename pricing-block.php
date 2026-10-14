<?php
declare(strict_types=1);

/**
 * The pricing comparison card, included from step-three.php inside the
 * "Congratulations" section (same placement as the Wix app's wizard).
 *
 * Expects $instanceId from the including scope; falls back to the URL,
 * then the session. Renders nothing when the caller has no instance or
 * the plans API is unreachable - the wizard page must not break because
 * pricing could not load.
 *
 * Plan codes come from this app's own seed (06-wp-plans.sql):
 * 'free' and 'grow' (grow has monthly + yearly rows).
 */

if (empty($instanceId)) {
    $instanceId = $_GET['instance_id'] ?? $_GET['instanceId']
        ?? $_SESSION['instanceid'] ?? $_SESSION['instance_id'] ?? null;
}
if (empty($instanceId)) {
    return; // nothing to price against
}

if (!function_exists('h')) {
    function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/* ---------- plans data (same builder the pricing page uses) ---------- */

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$plansUrl = $scheme . '://' . $host . APP_BASE . '/api/get_plans.php?instance_id=' . urlencode((string) $instanceId);

$apiResponse = null;
if (function_exists('curl_init')) {
    $ch = curl_init($plansUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $decoded = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    if ($decoded && ($decoded['success'] ?? false)) {
        $apiResponse = $decoded;
    }
}

if (!$apiResponse) {
    return; // pricing is optional here; stay quiet
}

$plansRaw        = $apiResponse['plans'] ?? [];
$featuresMaster  = $apiResponse['features_master'] ?? [];
$currentSub      = $apiResponse['currentSubscription'] ?? null;

/* ---------- current-subscription state ---------- */

$currentPlanName     = strtolower(trim($currentSub['plan_name'] ?? ''));
$rawBP               = strtolower($currentSub['billing_period'] ?? '');
$currentBillingPeriod = 'free';
if (in_array($rawBP, ['month', 'monthly'], true)) $currentBillingPeriod = 'monthly';
if (in_array($rawBP, ['year', 'yearly'], true))   $currentBillingPeriod = 'yearly';

$subType  = strtolower($currentSub['type'] ?? '');
$isTrial  = ($subType === 'free_trial');

if ($currentPlanName === 'grow') {
    $currentLabel = 'Organic Booster (' . h($currentBillingPeriod) . ')';
} elseif ($isTrial) {
    $currentLabel = 'Free Trial';
} elseif ($currentPlanName === 'free') {
    $currentLabel = 'Basic (free)';
} else {
    $currentLabel = "No active plan";
}

$isGrow = ($currentPlanName === 'grow');

$isGrowActive = false;
if (!empty($currentSub)) {
    $status    = strtolower($currentSub['status'] ?? '');
    $expiresOn = $currentSub['expires_on'] ?? null;

    $isValidPeriod = false;
    if (!empty($expiresOn)) {
        try {
            $isValidPeriod = new DateTime($expiresOn) > new DateTime();
        } catch (Exception $e) {
            $isValidPeriod = false;
        }
    }

    $isGrowActive = $isGrow
        && !$isTrial
        && in_array($status, ['active', 'non_renewing'], true)
        && $isValidPeriod;
}

$isGrowMonthlyActive = false;
$isGrowYearlyActive  = false;
$disableMonthly     = false;
$disableYearly      = false;

if ($isGrowActive) {
    if ($currentBillingPeriod === 'monthly') {
        $isGrowMonthlyActive = true;
        $disableMonthly     = true;
    }
    if ($currentBillingPeriod === 'yearly') {
        $isGrowYearlyActive = true;
        $disableYearly     = true;
    }
}

$disableFree = $isGrow || $currentPlanName === 'free';

$ctaText = 'Start Organic Booster Plan';

/* ---------- map plans + features ---------- */

$freeFeatures  = [];
$growFeatures   = [];
$growMonthlyPlan = null;
$growYearlyPlan  = null;
$freePlan       = null;

foreach ($plansRaw as $p) {
    $code = strtolower($p['code'] ?? '');
    $bp   = strtolower($p['billingPeriod'] ?? '');

    if ($code === 'free') {
        $freePlan = $p;
        foreach ($p['features'] ?? [] as $f) {
            $freeFeatures[(int) $f['id']] = $f;
        }
    }
    if ($code === 'grow') {
        if (in_array($bp, ['month', 'monthly'], true)) $growMonthlyPlan = $p;
        if (in_array($bp, ['year', 'yearly'], true))   $growYearlyPlan  = $p;
        foreach ($p['features'] ?? [] as $f) {
            $growFeatures[(int) $f['id']] = $f;
        }
    }
}

if (!$freePlan && !$growMonthlyPlan && !$growYearlyPlan) {
    return; // plans not seeded yet - nothing useful to render
}
?>

<div class="bg-white card border border-gray-200 dark:border-neutral-600 dark:bg-neutral-800 rounded-lg text-start mt-6">
    <!-- ================= DESKTOP TABLE (lg+) ================= -->
    <div class="hidden lg:block card-body overflow-x-auto">
        <table class="w-full border-collapse text-sm table-fixed" style="overflow: visible;">
            <colgroup>
                <col style="width:40%">
                <col style="width:35%">
                <col style="width:25%">
            </colgroup>

            <thead class="border-b">
                <tr>
                    <th class="text-start align-top p-6 border border-gray-300">
                        <h4 class="text-2xl font-semibold text-neutral-800">Choose the Plan That's Right for You</h4>
                        <div class="mt-8">
                            <ul class="flex gap-2 bg-gray-100 rounded-lg p-1 size-fit relative dark:bg-neutral-600">
                                <li><button type="button" class="px-6 py-2.5 rounded-lg font-semibold toggle-btn" data-target="monthly">Monthly</button></li>
                                <li><button type="button" class="px-6 py-2.5 rounded-lg font-semibold toggle-btn active" data-target="yearly">Yearly</button></li>
                            </ul>
                            <p class="text-sm text-neutral-500 discount-sticker mt-3 size-fit bg_orange_gradiant text-white text-xs font-semibold px-3 py-1 rounded-full shadow">🎁 3 Months FREE on yearly</p>
                        </div>
                    </th>

                    <th class="text-center p-6 border border-gray-300 bg-cstm-primary-10 plan-header-cell">
                        <div>
                            <div class="monthly plan-variant hidden">
                                <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-cstm-primary text-white text-xs font-semibold px-3 py-1 rounded-full shadow z-10 whitespace-nowrap">Most Popular</span>
                                <div class="relative">
                                    <p class="text-neutral-800 text-xl font-semibold">Organic Booster</p>
                                    <h3 class="my-3 text-4xl font-bold text-[#652ec3]"> $<?= h($growMonthlyPlan['price'] ?? 0) ?> <span class="text-sm text-neutral-700">/month</span></h3>
                                    <?php if ($disableMonthly && !$isGrowMonthlyActive): ?>
                                        <p class="text-sm text-neutral-500 absolute -bottom-3 left-1/2 -translate-x-1/2 w-full">
                                            You are already on a higher plan
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($growMonthlyPlan['price_id'])): ?>
                                <form method="post" action="api/billing.php">
                                    <input type="hidden" name="instance_id" value="<?= h($instanceId) ?>">
                                    <input type="hidden" name="price_id" value="<?= h($growMonthlyPlan['price_id']) ?>">
                                    <input type="hidden" name="billing_period" value="monthly">

                                    <button type="submit"
                                        class="mt-8 px-8 py-2 rounded-lg min-w-[280px]
                                               <?= $disableMonthly ? 'bg-[#4871af] text-white cursor-not-allowed' : 'bg-cstm-primary text-white' ?>"
                                        <?= $disableMonthly ? 'disabled' : '' ?>>
                                        <?= $disableMonthly ? 'Selected Plan' : ($disableYearly ? 'Downgrade Not Allowed' : $ctaText) ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>

                            <div class="yearly plan-variant">
                                <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-cstm-primary text-white text-xs font-semibold px-3 py-1 rounded-full shadow z-10 whitespace-nowrap">Most Popular</span>
                                <div class="relative max-w-max mx-auto">
                                    <p class="text-neutral-800 text-xl font-semibold">Organic Booster</p>
                                    <h3 class="my-3 text-4xl font-bold text-[#652ec3]"><span class="text-neutral-400 line-through text-2xl">$<?= h($growMonthlyPlan['price'] ?? 0) ?></span> $<?= number_format(($growYearlyPlan['price'] ?? 0) / 12, 2) ?> <span class="text-sm text-neutral-700">/month</span></h3>
                                    <p class="text-sm text-neutral-500 absolute discount-sticker bottom-3 -right-5 bg_orange_gradiant text-white text-xs font-semibold px-3 py-1 rounded-full shadow w-full max-w-max">🎁 3 Months FREE</p>
                                    <p class="text-sm text-neutral-500 absolute -bottom-3 left-1/2 -translate-x-1/2 w-full">Paid annually</p>
                                </div>

                                <?php if (!empty($growYearlyPlan['price_id'])): ?>
                                <form method="post" action="api/billing.php" class="">
                                    <input type="hidden" name="instance_id" value="<?= h($instanceId) ?>">
                                    <input type="hidden" name="price_id" value="<?= h($growYearlyPlan['price_id']) ?>">
                                    <input type="hidden" name="billing_period" value="yearly">

                                    <button type="submit"
                                        class="px-8 py-2 mt-8 rounded-lg min-w-[280px] <?= $disableYearly ? 'bg-gray-400' : 'bg-cstm-primary text-white' ?>"
                                        <?= $disableYearly ? 'disabled' : '' ?>>
                                        <?= $disableYearly ? 'Selected Plan' : $ctaText ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </th>

                    <th class="text-center p-6 border border-gray-300 plan-header-cell">
                        <div class="relative">
                            <p class="text-neutral-800 text-xl font-semibold"><?= h($freePlan['name'] ?? 'Free') ?></p>
                            <h3 class="my-3 text-4xl font-bold text-neutral-800">$0 <span class="text-sm text-neutral-700">/forever</span></h3>
                            <p class="text-sm text-neutral-500">Free starter plan</p>

                            <form method="post" action="api/billing.php">
                                <input type="hidden" name="instance_id" value="<?= h($instanceId) ?>">
                                <?php if (!empty($freePlan['id'])): ?>
                                    <input type="hidden" name="plan_id" value="<?= h($freePlan['id']) ?>">
                                <?php endif; ?>
                                <input type="hidden" name="billing_period" value="free">

                                <button type="submit"
                                    class="mt-8 px-8 py-2 rounded-lg min-w-[200px]
                                           <?= $disableFree ? 'bg-gray-300 text-neutral-700 cursor-not-allowed' : 'bg-neutral-800 text-white' ?>"
                                    <?= $disableFree ? 'disabled' : '' ?>>
                                    <?php
                                        if ($currentPlanName === 'free') {
                                            echo 'Selected Plan';
                                        } elseif ($isGrowActive) {
                                            echo 'Downgrade Not Allowed';
                                        } else {
                                            echo 'Activate Free Plan';
                                        }
                                    ?>
                                </button>
                            </form>
                        </div>
                    </th>
                </tr>
            </thead>

            <tbody class="divide-y">
                <?php foreach ($featuresMaster as $feature):
                    $fid  = (int) $feature['id'];
                    $fVal = function ($f) {
                        if (!$f) return '—';
                        return !empty($f['display_text']) && $f['display_text'] !== '-'
                            ? $f['display_text']
                            : (!empty($f['included']) ? 'Included' : 'Not included');
                    };
                ?>
                    <tr>
                        <td class="px-10 py-4 font-semibold border border-gray-200 border-l border-gray-300 text-base relative">
                            <?= h($feature['featureName'] ?? ($feature['name'] ?? '')) ?>
                            <?php if (!empty($feature['tooltip_text'] ?? ($feature['tooltip'] ?? null))): ?>
                                <button type="button" data-tooltip-target="tooltip-<?= $fid ?>" class="tooltip_btn absolute text-gray-400 hover:text-[#652ec3] ml-1">
                                    <iconify-icon icon="heroicons:information-circle-solid" class="w-4 h-4 inline align-middle tooltip_icon"></iconify-icon>
                                </button>
                                <div id="tooltip-<?= $fid ?>" role="tooltip" class="tooltip invisible absolute z-20 rounded-lg bg-gray-100 text-gray-900 px-3 py-2 text-sm shadow max-w-xs">
                                    <?= h($feature['tooltip_text'] ?? ($feature['tooltip'] ?? '')) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-10 py-4 border border-gray-200 pricing_font font-medium">
                            <div class="flex items-center gap-4">
                                <iconify-icon icon="heroicons:check-circle" class="text-2xl text-success-600"></iconify-icon>
                                <?= h($fVal($growFeatures[$fid] ?? null)) ?>
                            </div>
                        </td>
                        <?php
                            $f = $freeFeatures[$fid] ?? null;
                            // text exists ONLY if display_text is not "-"
                            $text = (!empty($f['display_text']) && $f['display_text'] !== '-')
                                ? $f['display_text']
                                : (!empty($f['included']) ? 'Included' : null);
                        ?>
                        <td class="py-4 border border-gray-200 pricing_font font-medium <?= $text ? 'px-10' : 'px-0 text-center' ?>">
                            <div class="flex items-center <?= $text ? 'gap-4 justify-start' : 'justify-center' ?>">
                                <?php if ($text): ?>
                                    <iconify-icon icon="heroicons:check-circle" class="text-2xl text-success-600"></iconify-icon>
                                    <span><?= h($text) ?></span>
                                <?php else: ?>
                                    <iconify-icon icon="heroicons:x-circle" class="text-2xl text-danger-600"></iconify-icon>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ================= MOBILE CARD VIEW ================= -->
    <div class="lg:hidden p-4 space-y-6">
        <ul class="flex gap-2 bg-gray-100 dark:bg-neutral-600 rounded-lg p-1 w-fit mx-auto relative">
            <li><button type="button" class="px-6 py-2.5 rounded-lg font-semibold toggle-btn" data-target="monthly">Monthly</button></li>
            <li><button type="button" class="px-6 py-2.5 rounded-lg font-semibold toggle-btn active" data-target="yearly">Yearly</button></li>
        </ul>
        <p class="text-xs text-center text-neutral-500 discount-sticker w-fit mx-auto bg_orange_gradiant text-white font-semibold px-3 py-1 rounded-full shadow">🎁 3 Months FREE on yearly</p>

        <!-- ===== PRO PLAN CARD ===== -->
        <div class="border border-gray-200 rounded-xl p-5 bg-cstm-primary-10 shadow-sm relative">
            <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-[#652ec3] text-white text-xs font-semibold px-3 py-1 rounded-full shadow">Most Popular</span>

            <div class="monthly plan-variant hidden text-center">
                <p class="text-neutral-800 text-xl font-semibold">Organic Booster</p>
                <h3 class="my-3 text-4xl font-bold text-[#652ec3]">$<?= h($growMonthlyPlan['price'] ?? 0) ?> <span class="text-sm text-neutral-700">/month</span></h3>

                <?php if (!empty($growMonthlyPlan['price_id'])): ?>
                <form method="post" action="api/billing.php">
                    <input type="hidden" name="instance_id" value="<?= h($instanceId) ?>">
                    <input type="hidden" name="price_id" value="<?= h($growMonthlyPlan['price_id']) ?>">
                    <input type="hidden" name="billing_period" value="monthly">

                    <button type="submit"
                        class="mt-6 px-8 py-2 rounded-lg w-full <?= $disableMonthly ? 'bg-[#4871af] text-white cursor-not-allowed' : 'bg-cstm-primary text-white' ?>"
                        <?= $disableMonthly ? 'disabled' : '' ?>>
                        <?= $disableMonthly ? 'Selected Plan' : ($disableYearly ? 'Downgrade Not Allowed' : $ctaText) ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <div class="yearly plan-variant text-center">
                <p class="text-neutral-800 text-xl font-semibold">Organic Booster</p>
                <h3 class="my-3 text-4xl font-bold text-[#652ec3]"><span class="text-neutral-400 line-through text-2xl">$<?= h($growMonthlyPlan['price'] ?? 0) ?></span> $<?= number_format(($growYearlyPlan['price'] ?? 0) / 12, 2) ?> <span class="text-sm text-neutral-700">/month</span></h3>
                <p class="text-sm text-neutral-500">Paid annually &middot; 🎁 3 Months FREE</p>

                <?php if (!empty($growYearlyPlan['price_id'])): ?>
                <form method="post" action="api/billing.php" class="">
                    <input type="hidden" name="instance_id" value="<?= h($instanceId) ?>">
                    <input type="hidden" name="price_id" value="<?= h($growYearlyPlan['price_id']) ?>">
                    <input type="hidden" name="billing_period" value="yearly">

                    <button type="submit"
                        class="px-8 py-2 mt-6 rounded-lg w-full <?= $disableYearly ? 'bg-gray-400' : 'bg-cstm-primary text-white' ?>"
                        <?= $disableYearly ? 'disabled' : '' ?>>
                        <?= $disableYearly ? 'Selected Plan' : $ctaText ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <button type="button" class="w-full mt-5 flex justify-between items-center text-sm font-semibold toggle-features">
                View Features
                <iconify-icon icon="heroicons:chevron-down"></iconify-icon>
            </button>

            <div class="features-list hidden mt-4 space-y-3">
                <?php foreach ($featuresMaster as $feature):
                    $fid  = (int) $feature['id'];
                    $f    = $growFeatures[$fid] ?? null;
                    $text = (!empty($f['display_text']) && $f['display_text'] !== '-')
                        ? $f['display_text']
                        : (!empty($f['included']) ? 'Included' : null);
                ?>
                    <div class="flex gap-3 items-center">
                        <iconify-icon
                            icon="<?= $text ? 'heroicons:check-circle' : 'heroicons:x-circle' ?>"
                            class="text-xl <?= $text ? 'text-success-600' : 'text-danger-600' ?>"></iconify-icon>
                        <div>
                            <p class="font-medium"><?= h($feature['featureName'] ?? ($feature['name'] ?? '')) ?></p>
                            <?php if ($text): ?>
                                <p class="text-sm text-gray-500"><?= h($text) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ===== FREE PLAN CARD ===== -->
        <div class="border border-gray-200 rounded-xl p-5 bg-white shadow-sm">
            <div class="text-center">
                <p class="text-neutral-800 text-xl font-semibold"><?= h($freePlan['name'] ?? 'Free') ?></p>
                <h3 class="my-3 text-4xl font-bold text-neutral-800">$0 <span class="text-sm text-neutral-700">/forever</span></h3>
                <p class="text-sm text-neutral-500">Free starter plan</p>

                <form method="post" action="api/billing.php">
                    <input type="hidden" name="instance_id" value="<?= h($instanceId) ?>">
                    <?php if (!empty($freePlan['id'])): ?>
                        <input type="hidden" name="plan_id" value="<?= h($freePlan['id']) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="billing_period" value="free">

                    <button type="submit"
                        class="mt-6 px-8 py-2 rounded-lg w-full <?= $disableFree ? 'bg-gray-300 text-neutral-700 cursor-not-allowed' : 'bg-neutral-800 text-white' ?>"
                        <?= $disableFree ? 'disabled' : '' ?>>
                        <?php
                            if ($currentPlanName === 'free') {
                                echo 'Selected Plan';
                            } elseif ($isGrowActive) {
                                echo 'Downgrade Not Allowed';
                            } else {
                                echo 'Activate Free Plan';
                            }
                        ?>
                    </button>
                </form>
            </div>

            <button type="button" class="w-full mt-5 flex justify-between items-center text-sm font-semibold toggle-features">
                View Features
                <iconify-icon icon="heroicons:chevron-down"></iconify-icon>
            </button>

            <div class="features-list hidden mt-4 space-y-3">
                <?php foreach ($featuresMaster as $feature):
                    $fid  = (int) $feature['id'];
                    $f    = $freeFeatures[$fid] ?? null;
                    $text = (!empty($f['display_text']) && $f['display_text'] !== '-')
                        ? $f['display_text']
                        : (!empty($f['included']) ? 'Included' : null);
                ?>
                    <div class="flex gap-3 items-center">
                        <iconify-icon
                            icon="<?= $text ? 'heroicons:check-circle' : 'heroicons:x-circle' ?>"
                            class="text-xl <?= $text ? 'text-success-600' : 'text-danger-600' ?>"></iconify-icon>
                        <div>
                            <p class="font-medium"><?= h($feature['featureName'] ?? ($feature['name'] ?? '')) ?></p>
                            <?php if ($text): ?>
                                <p class="text-sm text-gray-500"><?= h($text) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
    // Scoped to this block: the monthly/yearly toggle, the mobile feature
    // accordions, and the tooltips. Loaded once even if the block ever
    // renders twice, via the data attribute guard.
    (function () {
        if (window.__gscwpPricingBlockJs) return;
        window.__gscwpPricingBlockJs = true;

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.toggle-btn');
            if (!btn) return;

            document.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.plan-variant').forEach(v => {
                v.classList.toggle('hidden', !v.classList.contains(btn.dataset.target));
            });
            // mark every button with the same target active (desktop + mobile)
            document.querySelectorAll('.toggle-btn[data-target="' + btn.dataset.target + '"]')
                .forEach(b => b.classList.add('active'));
        });

        document.addEventListener('click', function (e) {
            const t = e.target.closest('.toggle-features');
            if (!t) return;
            t.closest('div').querySelector('.features-list')?.classList.toggle('hidden');
        });
    })();

    (function () {
        if (window.__gscwpPricingTooltips) return;
        window.__gscwpPricingTooltips = true;

        document.querySelectorAll('[data-tooltip-target]').forEach(trigger => {
            const tooltip = document.getElementById(trigger.getAttribute('data-tooltip-target'));
            if (!tooltip) return;

            document.body.appendChild(tooltip);

            trigger.addEventListener('mouseenter', () => {
                tooltip.classList.add('visible');

                const tr = trigger.getBoundingClientRect();
                const trr = tooltip.getBoundingClientRect();

                let top = tr.top + window.scrollY + (tr.height / 2) - (trr.height / 2);
                let left = tr.right + 12;

                if (left + trr.width > window.innerWidth - 20) {
                    left = tr.left - trr.width - 12;
                }

                const minTop = window.scrollY + 10;
                const maxTop = window.scrollY + window.innerHeight - trr.height - 10;
                top = Math.min(Math.max(top, minTop), maxTop);

                tooltip.style.top = `${top}px`;
                tooltip.style.left = `${left}px`;
            });

            trigger.addEventListener('mouseleave', () => tooltip.classList.remove('visible'));
        });
    })();
</script>
