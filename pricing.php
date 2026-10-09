<?php

declare(strict_types=1);

$title = 'Current Plan';
$subTitle = 'View and manage your active plan';

// 1. SYSTEM INITIALIZATION
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', '1');
error_reporting(E_ALL);

// 2. HELPERS
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!function_exists('b64url_decode')) {
    function b64url_decode($data)
    {
        $r = strlen($data) % 4;
        if ($r) {
            $data .= str_repeat('=', 4 - $r);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

// 3. INSTANCE & SESSION LOGIC
$instanceId = $_GET['instanceid'] ?? $_GET['instanceId'] ?? null;
if (!$instanceId && isset($_GET['instance'])) {
    $parts = explode('.', $_GET['instance']);
    if (count($parts) >= 2) {
        $payload = json_decode(b64url_decode($parts[1]), true);
        $instanceId = $payload['instanceId'] ?? null;
    }
}
if ($instanceId) {
    $_SESSION['instanceid'] = $instanceId;
}
$instanceId = $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;

if (!$instanceId) {
    header("Location: sign-in.php");
    exit();
}

// 4. DATA FETCHING
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$fullUrl = $scheme . "://" . $host . "/wordpress/googlesearchconsole/api/get_plans.php?instance_id=" . urlencode((string)$instanceId);

$apiResponse = null;
$apiError = null;

if (function_exists('curl_init')) {
    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $raw = curl_exec($ch);
    $decoded = json_decode((string)$raw, true);
    curl_close($ch);

    if ($decoded && ($decoded['success'] ?? false)) {
        $apiResponse = $decoded;
    } else {
        $apiError = "API Connection Failed.";
    }
}

// 5. DATA PREP
$plansRaw = $apiResponse['plans'] ?? [];
$featuresMaster = $apiResponse['features_master'] ?? [];

$currentSub = $apiResponse['currentSubscription'] ?? null;
$siteUrl = $apiResponse['site_url'] ?? null;

$hasHadPaidGrow = false;

if (!empty($currentSub)) {
    $plan = strtolower($currentSub['plan_name'] ?? '');
    if ($plan === 'organic booster') {
        $hasHadPaidGrow = true;
    }
}

// 6. FAQ DATA (for accordion)
$faqs = [
    [
        'question' => 'Does the app notify Google when I update products?',
        'answer'   => 'Yes. The app automatically sends update signals to Google whenever your product pages change, helping them get crawled and indexed faster.',
    ],
    [
        'question' => 'What types of content updates are sent to Google?',
        'answer'   => 'The app detects and sends updates for products, collections, blog posts, and standard pages.',
    ],
    [
        'question' => 'Do I need to manually trigger content updates?',
        'answer'   => 'No. All content updates are detected automatically, and Google is notified without any manual action required.',
    ],
    [
        'question' => 'How does the app connect to Google Search Console?',
        'answer'   => 'How does the app connect to Google Search Console?',
    ],
    [
        'question' => 'Do I need to configure anything manually?',
        'answer'   => 'No. The app handles the technical setup. After verification, you can manage indexing and performance directly in Google Search Console.',
    ],
    [
        'question' => 'Can I connect multiple websites?',
        'answer'   => 'No. The app only supports connecting a single website to Google Search Console per store. If you have multiple sites, you’ll need a separate setup for each one.',
    ],
    [
        'question' => 'Can I submit my sitemap using this app?',
        'answer'   => 'After verification, you can submit your sitemap directly in Google Search Console to help Google discover your pages more efficiently.',
    ],
    [
        'question' => 'Does the app request indexing for me?',
        'answer'   => 'The app automatically notifies Google when important site updates occur. You can also manually request indexing for specific pages when faster updates are needed.',
    ],
    [
        'question' => 'How often should I request indexing?',
        'answer'   => 'Only request indexing when you publish new content or make significant updates. Google handles routine crawling and indexing automatically.',
    ],
    [
        'question' => 'What is site verification and why is it required?',
        'answer'   => 'Site verification confirms that you own the website. This allows Google to display performance data and provide indexing controls in Google Search Console.',
    ],
    [
        'question' => 'Do I need technical skills to verify my site?',
        'answer'   => 'No. The app automates the entire verification process by generating and installing the required Google verification token for you.',
    ],
    [
        'question' => 'How long does verification take?',
        'answer'   => 'Verification usually completes within a few minutes after the verification token is installed on your site.',
    ],
    [
        'question' => 'Why is my site not verifying?',
        'answer'   => 'Make sure the verification token has been successfully installed on your site. If you’re using a custom theme or advanced site settings, check that your theme does not block header injections or meta tags.',
    ],
    [
        'question' => 'Google is not indexing my pages. What can I do?',
        'answer'   => 'Use the URL Inspection tool in Google Search Console to request indexing and review any indexing or quality issues reported by Google.',
    ],
    [
        'question' => 'How can I check if Google received my updates?',
        'answer'   => 'In Google Search Console, use the URL Inspection tool to see when Google last crawled your updated pages and whether they are indexed.',
    ],
];


$trustedLogos = [
    [
        'src' => '/wix/googlesearchconsole/assets/images/clients/1.png',
        'alt' => 'Client Logo',
    ],
    [
        'src' => '/wix/googlesearchconsole/assets/images/clients/2.webp',
        'alt' => 'Client Logo',
    ],
    [
        'src' => '/wix/googlesearchconsole/assets/images/clients/3.webp',
        'alt' => 'Client Logo',
    ],
    [
        'src' => '/wix/googlesearchconsole/assets/images/clients/4.webp',
        'alt' => 'Client Logo',
    ],
    [
        'src' => '/wix/googlesearchconsole/assets/images/clients/5.webp',
        'alt' => 'Client Logo',
    ],
    [
        'src' => '/wix/googlesearchconsole/assets/images/clients/6.avif',
        'alt' => 'Client Logo',
    ],
    [
        'src' => '/wix/googlesearchconsole/assets/images/clients/7.webp',
        'alt' => 'Client Logo',
    ],
    [
        'src' => '/wix/googlesearchconsole/assets/images/clients/8.svg',
        'alt' => 'Client Logo',
    ],
    [
        'src' => '/wix/googlesearchconsole/assets/images/clients/9.svg',
        'alt' => 'Client Logo',
    ],

    [
        'src' => '/wix/googlesearchconsole/assets/images/clients/12.webp',
        'alt' => 'Client Logo',
    ],
    [
        'src' => '/wix/googlesearchconsole/assets/images/clients/13.png',
        'alt' => 'Client Logo',
    ],
    [
        'src' => '/wix/googlesearchconsole/assets/images/clients/14.webp',
        'alt' => 'Client Logo',
    ],
];



$heroQuotes = [
    [
        'logo'     => '/wix/json-ld/assets/images/clients/google.png',
        'quote'    => 'Google’s own documentation emphasizes that structured data helps search engines better understand content and can enable rich results in Search.',
        'cta_text' => 'See Google Search Docs',
        'cta_url'  => 'https://developers.google.com/search/docs/advanced/structured-data/intro-structured-data',
    ],
    [
        'logo'     => '/wix/json-ld/assets/images/clients/ecwid.png',
        'quote'    => 'Ecwid recommends verifying your store in Google Search Console and submitting a sitemap so Google can discover your products and pages faster.',
        'cta_text' => 'Ecwid SEO Guide',
        'cta_url'  => 'https://support.ecwid.com/hc/en-us/sections/207100029-SEO',
    ],
    [
        'logo'     => '/wix/json-ld/assets/images/clients/bing.png',
        'quote'    => 'Bing Webmaster Tools reports that pages with valid schema are more consistently indexed and understood compared to pages without structured data.',
        'cta_text' => 'Bing Webmaster Insights',
        'cta_url'  => 'https://www.bing.com/webmasters/help/what-is-structured-data-97e9b726',
    ],
    [
        'logo'     => '/wix/json-ld/assets/images/clients/yelp.png',
        'quote'    => 'Yelp’s rich snippets for reviews and ratings come from structured JSON-LD, improving trust signals and user engagement on search results.',
        'cta_text' => 'Why Rich Snippets Matter',
        'cta_url'  => 'https://www.yelp.com',
    ],
];



$clientTestimonials = [
    [
        'review' => '“This app made structured data incredibly easy for our store. We enabled product and FAQ schema in minutes, and rich results started appearing without any manual work. The automation and AI-ready approach give us confidence we’re set up correctly.”',
        'name' => 'Daniel Brooks',
        'designation' => 'Founder, D2C E-commerce Brand',
    ],
    [
        'review' => '“Most SEO apps just focus on Google, but I love that this optimizes for LLMs like ChatGPT, Gemini and more. The \'Search\' and \'FAQ\' schema types have significantly improved our landing page performance. We see this small investment going a long way.”',
        'name' => 'Maya Patel',
        'designation' => 'SaaS Product Manager',
    ],
    [
        'review' => '“I’m not technical at all, but this app made schema implementation effortless. Local business and service schema were set up with clear options, and everything works automatically in the background.”',
        'name' => 'James Carter',
        'designation' => 'Owner, Professional Services Company',
    ],
    [
        'review' => '“As a content-heavy site, managing article and blog schema manually was painful. This is a great alternative to automate the manual work so we can focus more on content creation.”',
        'name' => 'Mark Reynolds',
        'designation' => 'Content Lead, Digital Publishing Platform',
    ],
    [
        'review' => '“The \'Competitor Schema Spy\' is a game-changer. Being able to analyze how competitors structure their data while managing my own in one dashboard is incredible. We’ve seen roughly 17% lift in our click-through rates thanks to rich snippet enhancements like star ratings and FAQ schema.”',
        'name' => 'Elena Rodriguez',
        'designation' => 'Senior SEO Strategist',
    ],
];



// Normalize Current Sub Details
$currentPlanName = strtolower(trim($currentSub['plan_name'] ?? ''));
$rawBP = strtolower($currentSub['billing_period'] ?? '');
$currentBillingPeriod = 'free';
if (in_array($rawBP, ['month', 'monthly'])) $currentBillingPeriod = 'monthly';
if (in_array($rawBP, ['year', 'yearly']))   $currentBillingPeriod = 'yearly';


if ($currentSub) {

    $subType = strtolower($currentSub['type'] ?? '');

    if ($currentPlanName === 'organic booster') {

        // ✅ Grow → Organic Booster
        $currentLabel = 'Organic Booster (' . h($currentBillingPeriod) . ')';
    } elseif ($subType === 'free_trial') {

        // ✅ Free Trial
        $currentLabel = 'Free Trial';
    } elseif ($currentPlanName === 'free') {

        // ✅ Free (non-trial)
        $currentLabel = 'Basic (free)';
    } else {

        // fallback (safety)
        $currentLabel = h($currentSub['plan_name']) . " (" . h($currentBillingPeriod) . ")";
    }
} else {
    $currentLabel = "No active plan";
}

$siteUrlDisplay = $siteUrl ? preg_replace('#^https?://#', '', $siteUrl) : null;


$isGrow = ($currentPlanName === 'organic booster');

//$disableMonthly = $isGrow && $currentBillingPeriod === 'monthly';
//$isGrowYearlyActive  = $isGrow && $currentBillingPeriod === 'yearly';


$isGrowActive = false;

if (!empty($currentSub)) {
    $status      = strtolower($currentSub['status'] ?? '');
    $expiresOn   = $currentSub['expires_on'] ?? null;

    $now = new DateTime();

    $isValidPeriod = false;
    if (!empty($expiresOn)) {
        try {
            $expiryDate = new DateTime($expiresOn);
            $isValidPeriod = $expiryDate > $now; // still in future
        } catch (Exception $e) {
            $isValidPeriod = false;
        }
    }

    // ACTIVE only if status allows it AND not expired
    if (in_array($status, ['active', 'non_renewing'], true) && $isValidPeriod) {
        $isGrowActive = true;
    }

} 

$isGrowMonthlyActive = false;
$isGrowYearlyActive  = false;

$disableMonthly = false;
$disableYearly  = false;

if ($isGrowActive) {

    if ($currentBillingPeriod === 'monthly') {
        $isGrowMonthlyActive = true;
        $disableMonthly = true;
    }

    if ($currentBillingPeriod === 'yearly') {
        $isGrowYearlyActive = true;
        $disableYearly = true;
    }
}




$disableFree = $isGrow || $currentPlanName === 'free';       // grow → free


// Map Features for Table Comparison
$freeFeatures = [];
$growFeatures = [];
$growMonthlyPlan = null;
$growYearlyPlan = null;
$freePlan = null;


foreach ($plansRaw as $p) {
    $code = strtolower($p['code'] ?? '');
    $bp = strtolower($p['billingPeriod'] ?? $p['billing_period'] ?? '');


    if ($code === 'free') {
        $freePlan = $p;
        foreach ($p['features'] ?? [] as $f) {
            $freeFeatures[(int)$f['id']] = $f;
        }
    }
    if ($code === 'richsnippet') {
        if (in_array($bp, ['month', 'monthly'])) $growMonthlyPlan = $p;
        if (in_array($bp, ['year', 'yearly']))   $growYearlyPlan = $p;
        foreach ($p['features'] ?? [] as $f) {
            $growFeatures[(int)$f['id']] = $f;
        }
    }
}


?>
<!DOCTYPE html>
<html lang="en">
<?php
include './partials/head.php';
?>
<?php
$ctaText = '7 days free trial';

if ($hasHadPaidGrow) {
    $ctaText = 'Start Organic Booster Plan';
}
?>
<body>
    <div class="h-full">
        <div class="dark:bg-neutral-800 bg-neutral-100 dark:text-white space-y-10 py-10">

            <div class="card p-0 rounded-xl border-0 max-w-custom-pricing mx-auto">
                <div class="card-body ">

                    <div class="border border-neutral-200 dark:border-neutral-700 bg-white shadow-sm mb-6 rounded-lg bg_main_gradient">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 px-6 py-5">
                            <div class="text-sm text-neutral-500 dark:text-neutral-400">
                                <h4 class="text-xl font-semibold text-neutral-800 dark:text-neutral-100 flex items-center gap-2">
                                    <iconify-icon icon="heroicons:globe-alt-solid" class="text-cstm-primary text-2xl"></iconify-icon>
                                   <span class="hidden lg:block">Website</span>
                                    <span class="lg:hidden">
                                         <?php if ($siteUrlDisplay): ?>
                                    <p class="mt-1 text-sm text-neutral-600 ">
                                        <a href="<?= h($siteUrl) ?>" target="_blank"
                                            class="font-medium text-neutral-600 hover:underline group flex items-center gap-1 px-2 py-1.5 border dark:border-neutral-700 rounded-lg text-neutral-700 dark:text-neutral-300 hover:border-cstm-primary hover:text-cstm-primary transition-all max-w-max truncate border-gray-300 text-sm">

                                            <?= h($siteUrlDisplay) ?>
                                        </a>
                                    </p>
                                <?php endif; ?>
                                    </span>
                                </h4>
                                <?php if ($siteUrlDisplay): ?>
                                    <p class="mt-1 text-base text-neutral-600 hidden lg:block">
                                        <a href="<?= h($siteUrl) ?>" target="_blank"
                                            class="font-medium text-[#652ec3] hover:underline group flex items-center gap-1 px-2 py-1.5 border dark:border-neutral-700 rounded-lg text-base font-medium text-neutral-700 dark:text-neutral-300 hover:border-cstm-primary hover:text-cstm-primary transition-all max-w-max truncate border-gray-300 ">

                                            <?= h($siteUrlDisplay) ?>
                                        </a>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div class="text-center">
                                <h4 class="text-5xl font-semibold text-neutral-800 dark:text-neutral-100">Pricing Plans</h4>
                                <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-200">Choose the plan that fits your needs</p>
                            </div>

                            <div class="flex flex-col text-center lg:items-end gap-1">
                                <h4 class="text-xl font-semibold text-neutral-800 dark:text-neutral-100 hidden lg:block">Current Plan</h4>
                                <p class="mt-1">
                                    <span class="font-medium text-neutral-800 dark:text-neutral-100"><?= $currentLabel ?></span>
                                </p>


                                <?php if ($currentSub['expires_on'] ?? null): ?>
                                    <p class="mt-1 text-xs">Expires on <span class="font-medium"><?= h(date('d M Y', strtotime($currentSub['expires_on']))) ?></span></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white card border border-gray-200 dark:border-neutral-600 dark:bg-neutral-800 rounded-lg">
                        <!-- ================= DESKTOP TABLE (md+) ================= -->
                        <div class="hidden lg:block card-body overflow-x-auto">
                            <!-- 🔒 YOUR EXISTING TABLE CODE GOES HERE -->
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
                                                    <li><button class="px-6 py-2.5 rounded-lg font-semibold toggle-btn" data-target="monthly">Monthly</button></li>
                                                    <li><button class="px-6 py-2.5 rounded-lg font-semibold toggle-btn active" data-target="yearly">Yearly</button></li>
                                                    <p class="text-sm text-neutral-500 absolute discount-sticker bottom-4 -right-4 bg_orange_gradiant text-white text-xs font-semibold px-3 py-1 rounded-full shadow">🎁 3 Months FREE</p>
                                                </ul>
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
                                                   
                                                    <form method="post" action="api/billing.php">
                                                        <input type="hidden" name="instance_id" value="<?= h($instanceId) ?>">
                                                        <input type="hidden" name="price_id" value="<?= h($growMonthlyPlan['price_id']) ?>">
                                                        <input type="hidden" name="billing_period" value="monthly">

                                                        <button type="submit"
                                                            class="mt-8 px-8 py-2 rounded-lg min-w-[280px]
                                                     <?= $disableMonthly  ? 'bg-[#4871af] text-white cursor-not-allowed' : 'bg-cstm-primary text-white' ?>"
                                                            <?= $disableMonthly  ? 'disabled' : '' ?>>
                                                            <?=
                                                                $disableMonthly
                                                                ? 'Selected Plan'
                                                                : ($disableYearly
                                                                    ? 'Downgrade Not Allowed'
                                                                    : $ctaText)
                                                            ?>
                                                        </button>
                                                    </form>

                                                </div>

                                                <div class="yearly plan-variant">
                                                    <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-cstm-primary text-white text-xs font-semibold px-3 py-1 rounded-full shadow z-10 whitespace-nowrap">Most Popular</span>
                                                    <div class="relative max-w-max mx-auto">
                                                        <p class="text-neutral-800 text-xl font-semibold">Organic Booster</p>
                                                        <h3 class="my-3 text-4xl font-bold text-[#652ec3]"><span class="text-neutral-400 line-through text-2xl">$20</span> $<?= floor(($growYearlyPlan['price'] ?? 0) / 12) ?> <span class="text-sm text-neutral-700">/month</span></h3>
                                                        <p class="text-sm text-neutral-500 absolute discount-sticker bottom-3 -right-5 bg_orange_gradiant text-white text-xs font-semibold px-3 py-1 rounded-full shadow w-full max-w-max">🎁 3 Months FREE</p>
                                                        <p class="text-sm text-neutral-500 absolute -bottom-3 left-1/2 -translate-x-1/2 w-full">Paid annually</p>
                                                    </div>
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
                                        $fid = (int)$feature['id'];
                                        $fVal = function ($f) {
                                            if (!$f) return '—';
                                            return !empty($f['display_text']) ? $f['display_text'] : ($f['included'] ? 'Included' : 'Not included');
                                        };
                                    ?>
                                        <tr>

                                            <td class="px-10 py-4 font-semibold border border-gray-200 border-l border-gray-300 text-base relative">
                                                <?= h($feature['featureName']) ?>
                                                <?php if (!empty($feature['tooltip_text'])): ?>
                                                    <button type="button" data-tooltip-target="tooltip-<?= $fid ?>" class="tooltip_btn absolute text-gray-400 hover:text-[#652ec3] ml-1">
                                                        <iconify-icon icon="heroicons:information-circle-solid" class="w-4 h-4 inline align-middle tooltip_icon"></iconify-icon>
                                                    </button>
                                                    <div id="tooltip-<?= $fid ?>" role="tooltip" class="tooltip invisible absolute z-20 rounded-lg bg-gray-100 text-gray-900 px-3 py-2 text-sm shadow max-w-xs">
                                                        <?= h($feature['tooltip_text']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-10 py-4 border border-gray-200 pricing_font font-medium">
                                                <div class="flex items-center gap-4">
                                                    <iconify-icon
                                                        icon="heroicons:check-circle"
                                                        class="text-2xl text-success-600">
                                                    </iconify-icon>
                                                    <?= h($fVal($growFeatures[$fid] ?? null)) ?>
                                                </div>
                                            </td>
                                            <?php
                                            $f = $freeFeatures[$fid] ?? null;

                                            // text exists ONLY if display_text is not "-"
                                            $text = (!empty($f['display_text']) && $f['display_text'] !== '-')
                                                ? $f['display_text']
                                                : null;

                                            // icon rule
                                            $showTick = $text !== null;
                                            ?>

                                            <td class="py-4 border border-gray-200 pricing_font font-medium <?= $text ? 'px-10' : 'px-0 text-center' ?>">
                                                <div class="flex items-center <?= $text ? 'gap-4 justify-start' : 'justify-center' ?>">

                                                    <?php if ($showTick): ?>
                                                        <iconify-icon
                                                            icon="heroicons:check-circle"
                                                            class="text-2xl text-success-600">
                                                        </iconify-icon>

                                                        <span><?= h($text) ?></span>

                                                    <?php else: ?>
                                                        <iconify-icon
                                                            icon="heroicons:x-circle"
                                                            class="text-2xl text-danger-600">
                                                        </iconify-icon>
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

                            <!-- ===== Toggle (SAME classes & data-targets) ===== -->
                            <ul class="flex gap-2 bg-gray-100 dark:bg-neutral-600 rounded-lg p-1 w-fit mx-auto relative">
                                <li>
                                    <button class="px-6 py-2.5 rounded-lg font-semibold toggle-btn"
                                        data-target="monthly">
                                        Monthly
                                    </button>
                                </li>
                                <li>
                                    <button class="px-6 py-2.5 rounded-lg font-semibold toggle-btn active"
                                        data-target="yearly">
                                        Yearly
                                    </button>
                                </li>

                                <p class="absolute -top-3 -right-4 green_color_bg text-white text-xs font-semibold px-3 py-1 rounded-full shadow discount-sticker">
                                    🎁 3 Months FREE
                                </p>
                            </ul>

                            <!-- ================= GROW PLAN CARD ================= -->
                            <div class="border border-gray-200 rounded-xl p-5 bg-cstm-primary-10 shadow-sm relative">

                                <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-[#652ec3] text-white text-xs font-semibold px-3 py-1 rounded-full shadow">
                                    Most Popular
                                </span>

                                <!-- ===== Monthly Variant ===== -->
                                <div>
                                    <div class="monthly plan-variant hidden text-center">
                                        <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-cstm-primary text-white text-xs font-semibold px-3 py-1 rounded-full shadow z-10 whitespace-nowrap">Most Popular</span>
                                        <div class="relative">
                                            <p class="text-neutral-800 text-xl font-semibold">Organic Booster</p>
                                        <h3 class="my-3 text-4xl font-bold text-[#652ec3]">$<?= h($growMonthlyPlan['price'] ?? 0) ?> <span class="text-sm text-neutral-700">/month</span></h3>
                                        <?php if ($disableMonthly && !$isGrowMonthlyActive): ?>
                                                <p class="text-sm text-neutral-500 absolute -bottom-3 left-1/2 -translate-x-1/2 w-full">
                                                    You are already on a higher plan
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <form method="post" action="api/billing.php">
                                            <input type="hidden" name="instance_id" value="<?= h($instanceId) ?>">
                                            <input type="hidden" name="price_id" value="<?= h($growMonthlyPlan['price_id']) ?>">
                                            <input type="hidden" name="billing_period" value="monthly">

                                            <button type="submit"
                                                class="mt-8 px-8 py-2 rounded-lg w-full
                                                     <?= $disableMonthly  ? 'bg-[#4871af] text-white cursor-not-allowed' : 'bg-cstm-primary text-white' ?>"
                                                <?= $disableMonthly  ? 'disabled' : '' ?>>
                                                <?=
                                                    $disableMonthly
                                                    ? 'Selected Plan'
                                                    : ($disableYearly
                                                        ? 'Downgrade Not Allowed'
                                                        : $ctaText)
                                                ?>
                                            </button>


                                        </form>

                                    </div>

                                    <div class="yearly plan-variant text-center">
                                        <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-cstm-primary text-white text-xs font-semibold px-3 py-1 rounded-full shadow z-10 whitespace-nowrap">Most Popular</span>
                                        <div class="relative">
                                            <p class="text-neutral-800 text-xl font-semibold">Organic Booster</p>
                                            <div class="relative max-w-max mx-auto">
                                                <h3 class="my-3 text-4xl font-bold text-[#652ec3]"><span class="text-neutral-400 line-through text-2xl">$20</span> $<?= floor(($growYearlyPlan['price'] ?? 0) / 12) ?> <span class="text-sm text-neutral-700">/month</span></h3>
                                                <p class="min-w-max text-sm text-neutral-500 absolute discount-sticker green_color_bg text-white text-xs font-semibold px-3 py-1 rounded-full shadow -right-5 -top-1">🎁 3 Months FREE</p>
                                            </div>
                                            <p class="text-sm text-neutral-500 absolute -bottom-3 left-1/2 -translate-x-1/2 w-full">Paid annually</p>
                                        </div>
                                        <form method="post" action="api/billing.php" class="">
                                            <input type="hidden" name="instance_id" value="<?= h($instanceId) ?>">
                                            <input type="hidden" name="price_id" value="<?= h($growYearlyPlan['price_id']) ?>">
                                            <input type="hidden" name="billing_period" value="yearly">

                                            <button type="submit"
                                                class="px-8 py-2 mt-8 rounded-lg w-full <?= $disableYearly ? 'bg-gray-400' : 'bg-cstm-primary text-white' ?>"
                                                <?= $disableYearly ? 'disabled' : '' ?>>
                                                <?= $disableYearly ? 'Selected Plan' : $ctaText ?>
                                            </button>
                                        </form>

                                    </div>
                                </div>

                                <!-- ===== Features Accordion ===== -->
                                <button class="w-full mt-5 flex justify-between items-center text-sm font-semibold toggle-features">
                                    View Features
                                    <iconify-icon icon="heroicons:chevron-down"></iconify-icon>
                                </button>

                                <div class="features-list hidden mt-4 space-y-3">
                                    <?php foreach ($featuresMaster as $feature):
                                        $fid = (int)$feature['id'];
                                        $f = $growFeatures[$fid] ?? null;
                                        $text = !empty($f['display_text'])
                                            ? $f['display_text']
                                            : ($f['included'] ? 'Included' : null);
                                    ?>
                                        <div class="flex gap-3 items-center">
                                            <iconify-icon
                                                icon="<?= $text ? 'heroicons:check-circle' : 'heroicons:x-circle' ?>"
                                                class="text-xl <?= $text ? 'text-success-600' : 'text-danger-600' ?>">
                                            </iconify-icon>

                                            <div>
                                                <p class="font-medium"><?= h($feature['name']) ?></p>
                                                <?php if ($text): ?>
                                                    <p class="text-sm text-gray-500"><?= h($text) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- ================= FREE PLAN CARD ================= -->
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
                                            class="mt-8 px-8 py-2 rounded-lg w-full
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

                                <button class="w-full mt-5 flex justify-between items-center text-sm font-semibold toggle-features">
                                    View Features
                                    <iconify-icon icon="heroicons:chevron-down"></iconify-icon>
                                </button>

                                <div class="features-list hidden mt-4 space-y-3">
                                    <?php foreach ($featuresMaster as $feature):
                                        $fid = (int)$feature['id'];
                                        $f = $freeFeatures[$fid] ?? null;
                                        $text = (!empty($f['display_text']) && $f['display_text'] !== '-')
                                            ? $f['display_text']
                                            : ($f && !empty($f['included']) ? 'Included' : null);
                                    ?>
                                        <div class="flex gap-3 items-center">
                                            <iconify-icon
                                                icon="<?= $text ? 'heroicons:check-circle' : 'heroicons:x-circle' ?>"
                                                class="text-xl <?= $text ? 'text-success-600' : 'text-danger-600' ?>">
                                            </iconify-icon>

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


                </div>
            </div>

            <div class="card p-0 rounded-xl border-0 max-w-custom-pricing mx-auto">
                <div class="card-body">
                    <div class="card-body relative bg_main_gradient">

                        <div class="progress-carousel">

                            <?php foreach ($heroQuotes as $quote): ?>
                                <div class="relative h-[400px]">


                                    <!-- Center Content -->
                                    <div class="absolute inset-0 z-[2] flex flex-col items-center justify-center text-center px-4">

                                        <!-- Logo -->
                                        <img
                                            src="<?= h($quote['logo']) ?>"
                                            alt="Logo"
                                            class="h-12 mb-5">

                                        <!-- Quote -->
                                        <p class="hero-quote-text font-medium max-w-md mb-6">
                                            <?= h($quote['quote']) ?>
                                        </p>

                                        <!-- CTA -->
                                        <a href="<?= h($quote['cta_url']) ?>" target="_blank" class="btn btn-cstm-primary">
                                            <?= h($quote['cta_text']) ?>
                                            <iconify-icon icon="mdi:arrow-right" class="text-lg"></iconify-icon>
                                        </a>

                                    </div>
                                </div>
                            <?php endforeach; ?>

                        </div>

                        <!-- Progress bar -->
                        <div class="slider-progress">
                            <span></span>
                        </div>

                    </div>
                </div>
            </div>

            <div class="card p-0 rounded-xl border-0 max-w-custom-pricing mx-auto">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4">
                        <h6 class="font-bold text-xl">Trusted by Businesses Like Yours</h6>
                    </div>

                    <div class="multiple-carousel slick-dots-style-two h-fit">

                        <?php foreach ($trustedLogos as $logo): ?>
                            <div class="mx-2">
                                <div class="logo-card flex items-center justify-center bg-white dark:bg-neutral-800
                                            border border-neutral-200 dark:border-neutral-700
                                            rounded-xl shadow-sm">

                                    <img
                                        src="<?= h($logo['src']) ?>"
                                        alt="<?= h($logo['alt']) ?>"
                                        class="logo-img object-contain transition">

                                </div>
                            </div>
                        <?php endforeach; ?>

                    </div>
                </div>
            </div>

            <div class="card p-0 rounded-xl border-0 max-w-custom-pricing mx-auto">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4">
                        <h6 class="font-bold text-xl">Testimonials</h6>
                    </div>
                    <div class="testimonial-slider">
                        <?php foreach ($clientTestimonials as $testtimonials): ?>
                            <!-- Card -->
                            <div class="p-3 h-full h-auto">
                                <div class="bg-white border border-neutral-200 dark:border-neutral-700 rounded-xl shadow-sm p-6 h-full text-center dark:bg-neutral-600">
                                    <p class="text-neutral-600 mb-6">
                                        <?= h($testtimonials['review']) ?>
                                    </p>

                                    <div class="mt-4">
                                        <h4 class="font-semibold text-neutral-800 text-lg"><?= h($testtimonials['name']) ?></h4>
                                        <span class="text-sm text-neutral-500"><?= h($testtimonials['designation']) ?></span>
                                    </div>

                                </div>
                            </div>
                        <?php endforeach; ?>



                    </div>
                </div>
            </div>

            <div class="card p-0 rounded-xl border-0 max-w-custom-pricing mx-auto">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4">
                        <h6 class="font-bold text-xl">Frequently Asked Questions</h6>
                    </div>
                    <div id="vertical-tab-content">
                        <div id="vertical-about" role="tabpanel" aria-labelledby="vertical-about-tab">
                            <div id="accordion-collapse" data-accordion="collapse" class="grid grid-cols-1 md:grid-cols-2 gap-4">

                                <!-- LEFT COLUMN -->
                                <div class="space-y-4">
                                    <?php foreach ($faqs as $index => $faq): ?>
                                        <?php if ($index % 2 === 0): ?>
                                            <?php $i = $index + 1; ?>
                                            <div class="accordion-item cstm-accordion-item
                                                border border-neutral-200 dark:border-neutral-600
                                                mb-2 last:mb-0 rounded-2xl shadow-sm dark:bg-gray-800">

                                                <div id="accordion-collapse-heading-<?= $i ?>">
                                                    <button
                                                        type="button"
                                                        class="flex items-center justify-between w-full
                                                        text-base font-medium text-neutral-800
                                                        px-5 py-4 rounded-2xl
                                                        hover:bg-neutral-50 transition-colors duration-200
                                                        dark:hover:bg-neutral-800"
                                                        data-accordion-target="#accordion-collapse-body-<?= $i ?>"
                                                        aria-expanded="false"
                                                        aria-controls="accordion-collapse-body-<?= $i ?>">

                                                        <span class="text-start"><?= h($faq['question']) ?></span>

                                                        <span class="w-6 h-6 flex justify-center items-center
                                                            border border-cstm-primary rounded-full
                                                            text-cstm-primary text-base shrink-0">
                                                            <i class="ri-add-line"></i>
                                                        </span>
                                                    </button>
                                                </div>

                                                <div id="accordion-collapse-body-<?= $i ?>"
                                                    class="hidden"
                                                    aria-labelledby="accordion-collapse-heading-<?= $i ?>">

                                                    <div class="p-5 text-neutral-700 dark:text-neutral-300 leading-relaxed">
                                                        <p><?= h($faq['answer']) ?></p>
                                                    </div>
                                                </div>

                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>

                                <!-- RIGHT COLUMN -->
                                <div class="space-y-4">
                                    <?php foreach ($faqs as $index => $faq): ?>
                                        <?php if ($index % 2 !== 0): ?>
                                            <?php $i = $index + 1; ?>
                                            <div class="accordion-item cstm-accordion-item
                                                border border-neutral-200 dark:border-neutral-600
                                                mb-2 last:mb-0 rounded-2xl shadow-sm dark:bg-gray-800">

                                                <div id="accordion-collapse-heading-<?= $i ?>">
                                                    <button
                                                        type="button"
                                                        class="flex items-center justify-between w-full
                                                        text-base font-medium text-neutral-800
                                                        px-5 py-4 rounded-2xl
                                                        hover:bg-neutral-50 transition-colors duration-200
                                                        dark:hover:bg-neutral-800"
                                                        data-accordion-target="#accordion-collapse-body-<?= $i ?>"
                                                        aria-expanded="false"
                                                        aria-controls="accordion-collapse-body-<?= $i ?>">

                                                        <span><?= h($faq['question']) ?></span>

                                                        <span class="w-6 h-6 flex justify-center items-center
                                                            border border-cstm-primary rounded-full
                                                            text-cstm-primary text-base shrink-0">
                                                            <i class="ri-add-line"></i>
                                                        </span>
                                                    </button>
                                                </div>

                                                <div id="accordion-collapse-body-<?= $i ?>"
                                                    class="hidden"
                                                    aria-labelledby="accordion-collapse-heading-<?= $i ?>">

                                                    <div class="p-5 text-neutral-700 dark:text-neutral-300 leading-relaxed">
                                                        <p><?= h($faq['answer']) ?></p>
                                                    </div>
                                                </div>

                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>

                            </div>

                        </div>
                    </div>
                </div>




            </div>



        </div>
    </div>

    <?php
    include './partials/script.php';
    ?>
    <?php
    include './partials/layouts/layoutBottom.php';

    ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // ----------------------------
            // 1. TOGGLE LOGIC (Monthly / Yearly)
            // ----------------------------
            const buttons = document.querySelectorAll('.toggle-btn');
            const variants = document.querySelectorAll('.plan-variant');

            buttons.forEach(btn => {
                btn.addEventListener('click', () => {
                    buttons.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    const target = btn.dataset.target;
                    variants.forEach(v =>
                        v.classList.toggle('hidden', !v.classList.contains(target))
                    );
                });
            });

            // ----------------------------
            // 2. IMPROVED TOOLTIP LOGIC
            // ----------------------------
            document.querySelectorAll('[data-tooltip-target]').forEach(trigger => {
                const tooltipId = trigger.getAttribute('data-tooltip-target');
                const tooltip = document.getElementById(tooltipId);
                if (!tooltip) return;

                // Move to body to prevent being cut off by table containers
                document.body.appendChild(tooltip);

                trigger.addEventListener('mouseenter', () => {
                    // Remove 'invisible' before measuring
                    tooltip.classList.add('visible');

                    const triggerRect = trigger.getBoundingClientRect();
                    const tooltipRect = tooltip.getBoundingClientRect();
                    const viewportWidth = window.innerWidth;

                    // Calculate vertical center relative to the icon
                    let top = triggerRect.top + window.scrollY + (triggerRect.height / 2) - (tooltipRect.height / 2);

                    // Default position: 12px to the right of the icon
                    let left = triggerRect.right + 12;

                    // EDGE DETECTION: If tooltip goes off-screen right, flip it to the left side
                    if (left + tooltipRect.width > viewportWidth - 20) {
                        left = triggerRect.left - tooltipRect.width - 12;
                    }

                    // CLAMP: Prevent tooltip from going off the very top or bottom of viewport
                    const padding = 10;
                    const minTop = window.scrollY + padding;
                    const maxTop = window.scrollY + window.innerHeight - tooltipRect.height - padding;

                    if (top < minTop) top = minTop;
                    if (top > maxTop) top = maxTop;

                    tooltip.style.top = `${top}px`;
                    tooltip.style.left = `${left}px`;
                });

                trigger.addEventListener('mouseleave', () => {
                    tooltip.classList.remove('visible');
                });
            });
        });
    </script>


    <script>
        document.addEventListener("DOMContentLoaded", function() {
            var sliderTimer = 4000;
            var beforeEnd = 400;
            var imageSlider = document.querySelector('.progress-carousel');

            // Initialize Slick (still requires jQuery internally)
            jQuery(imageSlider).slick({
                autoplay: true,
                autoplaySpeed: sliderTimer,
                speed: 1000,
                arrows: false,
                dots: false,
                adaptiveHeight: true,
                pauseOnFocus: false,
                pauseOnHover: false,
                rtl: typeof rtlDirection !== "undefined" ? rtlDirection : false
            });

            function progressBar() {
                var progressSpans = document.querySelectorAll('.slider-progress span');

                progressSpans.forEach(function(span) {
                    span.style = '';
                    span.classList.remove('active');
                });

                setTimeout(function() {
                    progressSpans.forEach(function(span) {
                        span.style.transitionDuration = (sliderTimer / 1000) + 's';
                        span.classList.add('active');
                    });
                }, 100);
            }

            progressBar();

            // Slick events (still jQuery-based)
            jQuery(imageSlider).on('beforeChange', function() {
                progressBar();
            });

            jQuery(imageSlider).on('afterChange', function(e, slick, nextSlide) {
                titleAnim(nextSlide);
            });

            // Title Animation
            function titleAnim() {
                var currentSlide = imageSlider.querySelector('.slick-current h1');

                if (!currentSlide) return;

                currentSlide.classList.add('show');

                setTimeout(function() {
                    currentSlide.classList.remove('show');
                }, sliderTimer - beforeEnd);
            }

            titleAnim();
        });
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const carousels = document.querySelectorAll(".multiple-carousel");

            if (!window.jQuery || carousels.length === 0) return;

            carousels.forEach(function(carousel) {
                window.jQuery(carousel).slick({
                    infinite: true,
                    slidesToShow: 5,
                    slidesToScroll: 1,

                    arrows: false,
                    dots: false,
                    appendDots: jQuery(carousel).closest('.card-body'),

                    autoplay: true, // ✅ ENABLE autoplay
                    autoplaySpeed: 3000, // ✅ 3 seconds
                    speed: 600,
                    pauseOnHover: true, // ✅ good UX
                    pauseOnFocus: false,

                    rtl: typeof rtlDirection !== "undefined" ? rtlDirection : false,

                    responsive: [{
                            breakpoint: 1199,
                            settings: {
                                slidesToShow: 5
                            }
                        },
                        {
                            breakpoint: 991,
                            settings: {
                                slidesToShow: 4
                            }
                        },
                        {
                            breakpoint: 575,
                            settings: {
                                slidesToShow: 2
                            }
                        }
                    ]
                });

            });
        });
    </script>
    <style>
        .hero-quote-text {
            font-size: 20px;
            line-height: 1.6;
            font-style: italic;
            color: #000;
            margin-bottom: 32px;
        }

        .logo-card {
            width: 100%;
            height: 120px;
            padding: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
        }

        /* Logo scaling */
        .logo-img {
            width: 100%;
            height: auto;
        }

        .testimonial-slider .slick-track {
            display: flex;
        }
    </style>
    <script>
        $(document).ready(function() {
            $('.testimonial-slider').slick({
                slidesToShow: 3,
                slidesToScroll: 1,

                arrows: true, // ✅ arrows enabled
                dots: false,

                autoplay: true, // ✅ auto slide ON
                autoplaySpeed: 3000, // 4 seconds
                speed: 600,
                infinite: true, // ✅ loop forever

                pauseOnHover: true, // pause on hover (good UX)
                pauseOnFocus: false,
                pauseOnDotsHover: false,

                prevArrow: '<button class="slick-prev custom-arrow">‹</button>',
                nextArrow: '<button class="slick-next custom-arrow">›</button>',

                responsive: [{
                        breakpoint: 1024,
                        settings: {
                            slidesToShow: 2
                        }
                    },
                    {
                        breakpoint: 640,
                        settings: {
                            slidesToShow: 1
                        }
                    }
                ]
            });
        });
    </script>
    <script>
        // Monthly / Yearly Toggle (shared)
        document.querySelectorAll('.toggle-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                document.querySelectorAll('.plan-variant').forEach(v => v.classList.add('hidden'));
                document.querySelectorAll('.' + btn.dataset.target).forEach(v => v.classList.remove('hidden'));
            });
        });

        // Mobile accordion
        document.querySelectorAll('.toggle-features').forEach(btn => {
            btn.addEventListener('click', () => {
                btn.nextElementSibling.classList.toggle('hidden');
            });
        });
    </script>
</body>

</html>