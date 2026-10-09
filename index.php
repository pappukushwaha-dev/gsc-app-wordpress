<?php
// Start the session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// -------------------------------------------------------------------------
// 1. Session & Config
// -------------------------------------------------------------------------
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/google/get_account.php';
require_once __DIR__ . '/includes/plan_guard.php';
// sample_data.php does not exist in this app. It supplies gsc_sample_badge(),
// gsc_sample_note(), gsc_sample_class(), gsc_sample_banner() and
// $gscSampleSections — used 25 times below, so their absence is a fatal, not
// a missing feature. The shim beside this file defines them as no-ops, which
// means the dashboard shows real data only and never the demo overlay.
// Port the real file across later if that overlay is wanted.
require_once __DIR__ . '/includes/sample_data.php';
$planAccess = getPlanAccessForPage();
$hasPremiumAccess = $planAccess['allowed'] ?? false;

// Both spellings, read and written. This codebase sets the session key
// inconsistently — auth/callback.php writes 'instance_id', pricing.php
// writes 'instanceid' — and reading only one sends users to sign-in in the
// middle of a working session.
$instanceId = $_SESSION['instanceid']
    ?? $_SESSION['instance_id']
    ?? $_GET['instanceid']
    ?? $_GET['instance_id']
    ?? null;

if (!$instanceId) {
    header("Location: sign-in.php");
    exit;
}

$instanceId = trim((string)$instanceId);
$_SESSION['instanceid']  = $instanceId;
$_SESSION['instance_id'] = $instanceId;


if ($instanceId) {
    try {
        $gateStmt = $pdo->prepare("
            SELECT step1, step2
            FROM setup_wizard_status
            WHERE instance_id = ?
            LIMIT 1
        ");
        $gateStmt->execute([$instanceId]);
        $gate = $gateStmt->fetch(PDO::FETCH_ASSOC);

        $setupDone = $gate
            && (int)$gate['step1'] === 1
            && (int)$gate['step2'] === 1;

        if (!$setupDone) {
            header('Location: setup-wizard.php?instance_id=' . urlencode($instanceId));
            exit;
        }
    } catch (Throwable $e) {
        // A failed lookup must not lock anyone out of their own dashboard.
        // Letting them through is the safer error: the wizard is still
        // reachable from the menu, but a false redirect would trap a
        // merchant whose setup is finished.
        error_log('index.php setup gate: ' . $e->getMessage());
    }
}

// -------------------------------------------------------------------------
// SHOW POPUP ONLY FOR 24 HOUR TRIAL (BASED ON TIME GAP)
// -------------------------------------------------------------------------
$showTrialPopup = false;
$trialExpiresOn = null;

$stmt = $pdo->prepare("
    SELECT started_at, expires_on
    FROM app_free_trials
    WHERE instance_id = ?
      AND status = 'active'
    ORDER BY started_at DESC
    LIMIT 1
");
$stmt->execute([$instanceId]);
$trial = $stmt->fetch(PDO::FETCH_ASSOC);

if ($trial && !empty($trial['started_at']) && !empty($trial['expires_on'])) {

    $startedAt = strtotime($trial['started_at']);
    $expiresAt = strtotime($trial['expires_on']);

    if ($startedAt && $expiresAt) {

        $durationSeconds = $expiresAt - $startedAt;
        $durationHours   = $durationSeconds / 3600;

        // ✅ 24 hour trial detection (allow small buffer)
        if ($durationHours > 0 && $durationHours <= 26) {
            $showTrialPopup = true;
            $trialExpiresOn = $trial['expires_on'];
        }
    }
}

global $pdo;
$title    = 'Search Console Dashboard';
$subTitle = 'Overview';

// -------------------------------------------------------------------------
// 2. STATUS CHECKS
// -------------------------------------------------------------------------

$INTENT_REGEX = [
    'informational' => '/\b(who|what|where|when|why|how|can|is|are|do|does)\b/i',
    'commercial' => '/\b(buy|purchase|order|checkout|shop|online|store|sale|discount|coupon|promo|code|clearance|deals?|cheap|affordable|price|cost|get)\b/i',
    'transactional' => '/\b(buy|purchase|order|checkout|subscribe|sign up|signup|register|download|install|book|booking|trial|free trial)\b/i',
    'navigational' => '/\b(login|sign in|signin|account|dashboard|portal|official|website|homepage|contact|support|helpdesk)\b/i'
];
// -------------------------------------------------------------------------
// ECOMMERCE INTENT REGEX (FOR READY TO BUY / RESEARCH / TRUST / POST PURCHASE)
// -------------------------------------------------------------------------

$ECOMMERCE_INTENT_REGEX = [
    'ready_to_buy' =>
    '/\b(buy|purchase|order|price|pricing|cost|cheap|deal|discount|sale|shop|hire|book|service|agency|software|tool|platform|system)\b/i',
    'product_research' =>
    '/\b(best|top|review|reviews|compare|comparison|vs|features|specs|which|guide|ideas|software|tool|platform|system)\b/i',
    'trust_comparison' =>
    '/\b(is it safe|legit|trust|rating|ratings|testimonial|customer review|worth it|good|reliable)\b/i',
    'post_purchase' =>
    '/\b(return|refund|warranty|track|order status|cancel order|support|replacement|guarantee)\b/i'
];

// Google connection
$googleAccount = getGoogleAccountByInstance($instanceId);
$isConnected   = ($googleAccount && !empty($googleAccount['access_token']));

// Domain verification
$stmt = $pdo->prepare(
    "SELECT site_url, verification_status 
     FROM gsc_domain_verifications 
     WHERE instance_id = ? 
     LIMIT 1"
);
$stmt->execute([$instanceId]);
$siteInfo        = $stmt->fetch(PDO::FETCH_ASSOC);
$verifiedSiteUrl = $siteInfo['site_url'] ?? null;
$isVerified      = ($siteInfo && $siteInfo['verification_status'] === 'verified');

// Sitemap status
// $stmt = $pdo->prepare(
//     "SELECT COUNT(*) AS total 
//      FROM sitemaps 
//      WHERE instance_id = ?"
// );
// $stmt->execute([$instanceId]);
// $sitemapStats = $stmt->fetch(PDO::FETCH_ASSOC);
// $hasSitemap   = ($sitemapStats['total'] > 0);
// ========================================================================
// 1. THE QUERY  —  replace the existing "Sitemap status" block
// ========================================================================

// Requires 02-add-discovered-pages.sql and 03-add-last-synced.sql to have
// been run, since it reads discovered_pages and last_synced_at.

// --------------------------------------------------------------------

// Sitemap status
//
// A count alone only answers "did we send one", which is not the question
// people are asking. A sitemap can be submitted and still be returning 404,
// or Google may not have read it yet — both of which showed a green tick
// before. These columns carry the real state.
$stmt = $pdo->prepare("
    SELECT
        COUNT(*)                                            AS total,
        COALESCE(SUM(errors), 0)                            AS errors,
        COALESCE(SUM(warnings), 0)                          AS warnings,
        SUM(last_downloaded IS NOT NULL)                    AS read_count,
        SUM(last_synced_at  IS NOT NULL)                    AS synced_count,
        COALESCE(SUM(discovered_pages), 0)                  AS pages,
        MAX(last_downloaded)                                AS last_read
    FROM sitemaps
    WHERE instance_id = ?
");
$stmt->execute([$instanceId]);
$sitemapStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$hasSitemap = ((int)($sitemapStats['total'] ?? 0)) > 0;

/**
 * Reduce the sitemaps for this instance to one headline state.
 *
 * Mirrors sitemapState() on the Sitemap Manager page so the two never
 * disagree. Ordered by urgency: a broken sitemap outranks a healthy one.
 *
 * @return array{key:string,label:string,cls:string,icon:string,detail:string}
 */
function gscSitemapHeadline(array $s): array
{
    $total  = (int)($s['total']        ?? 0);
    $errors = (int)($s['errors']       ?? 0);
    $warns  = (int)($s['warnings']     ?? 0);
    $read   = (int)($s['read_count']   ?? 0);
    $synced = (int)($s['synced_count'] ?? 0);

    if ($total === 0) {
        return [
            'key'    => 'none',
            'label'  => 'No Sitemap',
            'cls'    => 'bg-danger-50 text-danger-600',
            'icon'   => 'solar:close-circle-outline',
            'detail' => 'No sitemap has been submitted yet.',
        ];
    }

    if ($errors > 0) {
        return [
            'key'    => 'error',
            'label'  => 'Sitemap Error',
            'cls'    => 'bg-danger-50 text-danger-600',
            'icon'   => 'solar:danger-triangle-outline',
            'detail' => $errors . ' error' . ($errors === 1 ? '' : 's') . ' reported by Google.',
        ];
    }

    if ($warns > 0) {
        return [
            'key'    => 'warning',
            'label'  => 'Sitemap Warnings',
            'cls'    => 'bg-warning-50 text-warning-600',
            'icon'   => 'solar:shield-warning-outline',
            'detail' => $warns . ' warning' . ($warns === 1 ? '' : 's') . ' reported by Google.',
        ];
    }

    // Nothing has been pulled from Google, so "submitted" is all we honestly
    // know — it says nothing about whether the file works.
    if ($synced === 0) {
        return [
            'key'    => 'unknown',
            'label'  => 'Sitemap Submitted',
            'cls'    => 'bg-neutral-100 text-neutral-600',
            'icon'   => 'solar:question-circle-outline',
            'detail' => 'Submitted, but its status has not been checked with Google yet.',
        ];
    }

    if ($read === 0) {
        return [
            'key'    => 'pending',
            'label'  => 'Awaiting Google',
            'cls'    => 'bg-warning-50 text-warning-600',
            'icon'   => 'solar:hourglass-outline',
            'detail' => 'Google knows about it but has not read it yet. Nothing to do.',
        ];
    }

    return [
        'key'    => 'ok',
        'label'  => 'Sitemap Read',
        'cls'    => 'bg-success-50 text-success-main',
        'icon'   => 'solar:check-circle-outline',
        'detail' => 'Google has read your sitemap with no errors.',
    ];
}

$sitemapHead = gscSitemapHeadline($sitemapStats);

// -------------------------------------------------------------------------
// 3. SUBMISSION HISTORY
// -------------------------------------------------------------------------
$siteUrl = $verifiedSiteUrl ? rtrim($verifiedSiteUrl, '/') : null;
$sitemapUrl = $siteUrl ? $siteUrl . '/sitemap.xml' : null;

// Fetch submission history (same shape as sitemap.php)
$stmt = $pdo->prepare(
    "SELECT sitemap_url, status, http_code, submitted_at, google_response
     FROM sitemap_submission_logs
     WHERE instance_id = ?
     AND status = 'Success'
     ORDER BY id DESC
     LIMIT 1"
);
$stmt->execute([$instanceId]);
$historyRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* 👇 ADD THIS HERE */
$historyRaw = array_filter($historyRaw, function ($log) {
    return $log['status'] === 'Success';
});

$history = array_map(function ($log) {
    return [
        'status' => $log['status'],
        'http_code' => $log['http_code'],
        'submitted_at' => $log['submitted_at'],
        'google_response' => $log['google_response'],
        'sitemap_url' => $log['sitemap_url']
    ];
}, $historyRaw);


// Can we show GSC data?
$canShowData = ($isConnected && $isVerified);

// -------------------------------------------------------------------------
// 4. FETCH GSC CHART DATA (INTERNAL API)
// -------------------------------------------------------------------------
// $chartData = [
//     'dates'        => [],
//     'clicks'       => [],
//     'impressions'  => [],
// ];

// $kpi = [
//     'clicks'      => 0,
//     'impressions' => 0,
//     'ctr'         => 0,
//     'position'    => 0,
// ];

// if ($canShowData) {

//     $payload = json_encode([
//         'instanceId' => $instanceId,
//         'range'      => '30days',
//         'type'       => 'dates'
//     ]);

//     $apiUrl =
//         ((($_SERVER['HTTPS'] ?? '') && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') .
//         '://' . $_SERVER['HTTP_HOST'] .
//         APP_BASE . '/api/google/get_gsc_report.php';

//     $ch = curl_init();
//     curl_setopt_array($ch, [
//         CURLOPT_URL            => $apiUrl,
//         CURLOPT_RETURNTRANSFER => true,
//         CURLOPT_POST           => true,
//         CURLOPT_POSTFIELDS     => $payload,
//         CURLOPT_HTTPHEADER     => [
//             'Content-Type: application/json'
//         ],
//     ]);

//     $response = curl_exec($ch);
//     curl_close($ch);
//     $data = json_decode($response, true);
//     if (!empty($data['success']) && !empty($data['rows'])) {
//         foreach ($data['rows'] as $row) {
//             $date = $row['keys'][0] ?? null;
//             if (!$date) {
//                 continue;
//             }
//             $clicks = (int)($row['clicks'] ?? 0);
//             $impr   = (int)($row['impressions'] ?? 0);
//             $chartData['dates'][]       = $date;
//             $chartData['clicks'][]      = $clicks;
//             $chartData['impressions'][] = $impr;
//             $kpi['clicks']      += $clicks;
//             $kpi['impressions'] += $impr;
//             $kpi['ctr']         += (float)($row['ctr'] ?? 0);
//             $kpi['position']    += (float)($row['position'] ?? 0);
//         }
//         $count = count($data['rows']);
//         if ($count > 0) {
//             $kpi['ctr']      = round($kpi['ctr'] / $count, 2);
//             $kpi['position'] = round($kpi['position'] / $count, 1);
//         }
//     }
// }


// -------------------------------------------------------------------------
// 4. FETCH GSC CHART DATA + KPI TOTALS  (replaces the old section 4)
//
// Self-contained on purpose: $data is reused by every later fetch in this
// file, so anything that reads $data['rows'] further down would silently pick
// up keyword or page rows instead of daily rows. Everything this section
// needs is captured into its own variables before $data is reused.
//
// Two corrections to the KPI maths while we are here:
//
//   CTR — was the mean of each day's CTR, which gives a day with one
//         impression the same weight as a day with five hundred. The real
//         figure is total clicks / total impressions. GSC also returns ctr
//         as a fraction (0.0441), so it has to be multiplied by 100 before
//         it is shown as a percentage.
//
//   Position — was a plain average across days. Weighting by impressions
//         gives the position users actually saw.
// -------------------------------------------------------------------------

$chartData = [
    'dates'       => [],
    'clicks'      => [],
    'impressions' => [],
    'positions'   => [],
];

$kpi = [
    'clicks'      => 0,
    'impressions' => 0,
    'ctr'         => 0.0,   // already a percentage, e.g. 4.41
    'position'    => 0.0,
];

// Previous 30 days, used only for the change badges. Stays null when there
// is no earlier data, and the badges are then hidden rather than showing an
// invented figure.
$kpiPrev = null;

$apiUrl =
    ((($_SERVER['HTTPS'] ?? '') && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') .
    '://' . $_SERVER['HTTP_HOST'] .
    APP_BASE . '/api/google/get_gsc_report.php';
if ($canShowData) {

    // ---------- current 30 days ----------
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'instanceId' => $instanceId,
            'range'      => '30days',
            'type'       => 'dates',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $chartResponse = curl_exec($ch);
    curl_close($ch);

    $chartJson = json_decode((string)$chartResponse, true);

    $curClicks = 0;
    $curImpr   = 0;
    $curPosW   = 0.0;

    if (!empty($chartJson['success']) && !empty($chartJson['rows'])) {
        foreach ($chartJson['rows'] as $row) {
            $date = $row['keys'][0] ?? null;
            if (!$date) continue;

            $c = (int)($row['clicks'] ?? 0);
            $i = (int)($row['impressions'] ?? 0);
            $p = (float)($row['position'] ?? 0);

            $chartData['dates'][]       = $date;
            $chartData['clicks'][]      = $c;
            $chartData['impressions'][] = $i;
            $chartData['positions'][]   = $p;

            $curClicks += $c;
            $curImpr   += $i;
            $curPosW   += $p * $i;
        }
    }

    $kpi['clicks']      = $curClicks;
    $kpi['impressions'] = $curImpr;
    $kpi['ctr']         = $curImpr > 0 ? round(($curClicks / $curImpr) * 100, 2) : 0.0;
    $kpi['position']    = $curImpr > 0 ? round($curPosW / $curImpr, 1) : 0.0;

    // ---------- previous 30 days, for the comparison badges ----------
    // Asks for 60 days and keeps only the older half. If the endpoint does
    // not accept that range the response comes back empty and the badges
    // simply do not render.
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'instanceId' => $instanceId,
            'range'      => '60days',
            'type'       => 'dates',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $prevResponse = curl_exec($ch);
    curl_close($ch);

    $prevJson = json_decode((string)$prevResponse, true);

    if (!empty($prevJson['success']) && !empty($prevJson['rows'])) {

        // Anything at or after this date belongs to the current window
        $cutoff  = $chartData['dates'] ? min($chartData['dates']) : date('Y-m-d', strtotime('-30 days'));
        $pClicks = 0;
        $pImpr   = 0;
        $pPosW   = 0.0;

        foreach ($prevJson['rows'] as $row) {
            $date = $row['keys'][0] ?? null;
            if (!$date || $date >= $cutoff) continue;

            $c = (int)($row['clicks'] ?? 0);
            $i = (int)($row['impressions'] ?? 0);
            $p = (float)($row['position'] ?? 0);

            $pClicks += $c;
            $pImpr   += $i;
            $pPosW   += $p * $i;
        }

        if ($pImpr > 0) {
            $kpiPrev = [
                'clicks'      => $pClicks,
                'impressions' => $pImpr,
                'ctr'         => round(($pClicks / $pImpr) * 100, 2),
                'position'    => round($pPosW / $pImpr, 1),
            ];
        }
    }
}

// -------------------------------------------------------------------------
// KPI display helpers
// -------------------------------------------------------------------------

/**
 * Percentage change between two periods. Returns null when there is nothing
 * meaningful to compare against, so the caller can hide the badge instead of
 * printing "+100%" against a zero baseline.
 */
if (!function_exists('gscPctChange')) {
    function gscPctChange($current, $previous): ?float
    {
        $previous = (float)$previous;
        if ($previous <= 0) return null;
        return round((((float)$current - $previous) / $previous) * 100, 1);
    }
}

/**
 * The small up/down badge beside a KPI.
 *
 * $lowerIsBetter is for average position, where 18 -> 12 is an improvement
 * even though the number fell.
 */
if (!function_exists('gscChangeBadge')) {
    function gscChangeBadge(?float $pct, bool $lowerIsBetter = false, string $suffix = '%'): string
    {
        if ($pct === null || abs($pct) < 0.05) return '';

        $improved = $lowerIsBetter ? ($pct < 0) : ($pct > 0);
        $cls  = $improved ? 'text-success-main' : 'text-danger-600';
        $icon = $pct > 0 ? 'bx:bx-up-arrow-alt' : 'bx:bx-down-arrow-alt';

        return '<span class="inline-flex items-center text-xs font-bold ' . $cls . '">'
            . '<iconify-icon icon="' . $icon . '" width="14" height="14"></iconify-icon>'
            . number_format(abs($pct), 1) . $suffix
            . '</span>';
    }
}

/** 12400 -> 12.4K, 341 -> 341 */
if (!function_exists('gscShortNum')) {
    function gscShortNum($n): string
    {
        $n = (float)$n;
        if (abs($n) >= 1000000) return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
        if (abs($n) >= 1000)    return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
        return number_format($n);
    }
}

// Label for the comparison window shown under each figure
$kpiRangeLabel = 'vs ' . date('M j', strtotime('-60 days'))
    . ' – ' . date('M j, Y', strtotime('-31 days'));

// -------------------------------------------------------------------------
// FETCH TOP PAGES (DETAILED REPORT)
// -------------------------------------------------------------------------
$brandSplit = [
    'branded_clicks' => 0,
    'non_branded_clicks' => 0,
    'branded_impr' => 0,
    'non_branded_impr' => 0,
    'brand_keywords' => [],
    'non_brand_keywords' => [],
];
$positionBands     = [];
$strikingDistance  = [];
$cannibalization   = [];
if ($canShowData && $hasPremiumAccess) {

    $payload = json_encode([
        'instanceId' => $instanceId,
        'range'      => '30days',
        'type'       => 'keywords'
    ]);

    $apiUrl =
        ((($_SERVER['HTTPS'] ?? '') && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' .
        $_SERVER['HTTP_HOST'] .
        APP_BASE . '/api/google/get_gsc_report.php';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        // Bounded on purpose. cURL's default timeout is infinite, and this is
        // a blocking call to our own API made while the page renders — an
        // unbounded one holds a web server worker open for as long as the
        // other end takes to answer. Four of these had no timeout in the Wix
        // original, and the site became unreachable because of it.
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (!empty($data['success'])) {
        $brandSplit['branded_clicks']     = $data['metrics']['brand_split']['branded_clicks'] ?? 0;
        $brandSplit['non_branded_clicks'] = $data['metrics']['brand_split']['non_branded_clicks'] ?? 0;
        $brandSplit['brand_keywords']     = $data['extra']['brand_keywords'] ?? [];
        $brandSplit['non_brand_keywords'] = $data['extra']['non_brand_keywords'] ?? [];
        $brandSplit['branded_impr']     = $data['metrics']['brand_split']['branded_impr'] ?? 0;
        $brandSplit['non_branded_impr'] = $data['metrics']['brand_split']['non_branded_impr'] ?? 0;
        $positionBands    = $data['extra']['position_bands']    ?? [];
        $strikingDistance = $data['extra']['striking_distance'] ?? [];
    }
    $allKeywords = array_merge(
        $brandSplit['brand_keywords'],
        $brandSplit['non_brand_keywords']
    );
    // echo "<pre>";
    // print_r(array_slice($allKeywords,0,10));
    // echo "</pre>";
    $ecommerceIntentData = [
        'ready_to_buy' => [],
        'product_research' => [],
        'trust_comparison' => [],
        'post_purchase' => []
    ];

    foreach ($allKeywords as $row) {
        $query = strtolower($row['keys'][0] ?? '');
        $matched = false;
        foreach ($ECOMMERCE_INTENT_REGEX as $intent => $pattern) {
            if (preg_match($pattern, $query)) {
                $ecommerceIntentData[$intent][] = $row;
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $ecommerceIntentData['product_research'][] = $row;
        }
    }

    $intentData = [
        'informational' => [],
        'commercial' => [],
        'transactional' => [],
        'navigational' => []
    ];

    // normal intent classification
    foreach ($allKeywords as $row) {
        $query = strtolower($row['keys'][0] ?? '');
        foreach ($INTENT_REGEX as $intent => $pattern) {
            if (preg_match($pattern, $query)) {
                $intentData[$intent][] = $row;
                break;
            }
        }
    }

    /* OPTIONAL: sort by clicks */
    foreach ($ecommerceIntentData as $k => $rows) {
        usort($rows, fn($a, $b) => $b['clicks'] <=> $a['clicks']);
        $ecommerceIntentData[$k] = $rows;
    }
}
// -------------------------------------------------------------------------
// FETCH QUERY BY PAGE (QP / CANNIBALIZATION)
// -------------------------------------------------------------------------
$queryByPage = [];

if ($canShowData && $hasPremiumAccess) {

    $payload = json_encode([
        'instanceId' => $instanceId,
        'range'      => '30days',
        'type'       => 'qp'
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        // Bounded on purpose. cURL's default timeout is infinite, and this is
        // a blocking call to our own API made while the page renders — an
        // unbounded one holds a web server worker open for as long as the
        // other end takes to answer. Four of these had no timeout in the Wix
        // original, and the site became unreachable because of it.
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (!empty($data['success'])) {
        $queryByPage     = $data['rows'] ?? [];
        $cannibalization = $data['extra']['cannibalization'] ?? [];
    }
}

// -------------------------------------------------------------------------
// FETCH TOP PAGES (DETAILED REPORT)
// -------------------------------------------------------------------------
$pagesReport = [];

if ($canShowData) {

    $payload = json_encode([
        'instanceId' => $instanceId,
        'range'      => '30days',
        'type'       => 'pages'
    ]);

    $apiUrl =
        ((($_SERVER['HTTPS'] ?? '') && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' .
        $_SERVER['HTTP_HOST'] .
        APP_BASE . '/api/google/get_gsc_report.php';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        // Bounded on purpose. cURL's default timeout is infinite, and this is
        // a blocking call to our own API made while the page renders — an
        // unbounded one holds a web server worker open for as long as the
        // other end takes to answer. Four of these had no timeout in the Wix
        // original, and the site became unreachable because of it.
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (!empty($data['success']) && !empty($data['rows'])) {
        $pagesReport = $data['rows'];
    }

    // ✅ ADD HERE (IMPORTANT)
    $pagesReportAll = $pagesReport;          // full rows
    $pagesReport    = array_slice($pagesReport, 0, 6); // only 6 rows
}
// -------------------------------------------------------------------------
// FETCH OVERVIEW / COUNTRIES / DEVICES
// -------------------------------------------------------------------------

$overviewReport = [];
$countriesReport = [];
$devicesReport = [];

if ($canShowData) {

    $types = [
        'keywords'  => 'overviewReport',
        'countries' => 'countriesReport',
        'devices'   => 'devicesReport'
    ];

    foreach ($types as $type => $varName) {

        $payload = json_encode([
            'instanceId' => $instanceId,
            'range'      => '30days',
            'type'       => $type
        ]);

        $apiUrl =
            ((($_SERVER['HTTPS'] ?? '') && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' .
            $_SERVER['HTTP_HOST'] .
            APP_BASE . '/api/google/get_gsc_report.php';

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        // Bounded on purpose. cURL's default timeout is infinite, and this is
        // a blocking call to our own API made while the page renders — an
        // unbounded one holds a web server worker open for as long as the
        // other end takes to answer. Four of these had no timeout in the Wix
        // original, and the site became unreachable because of it.
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (!empty($data['success']) && !empty($data['rows'])) {
            $$varName = $data['rows'];
        }
    }
}
// -------------------------------------------------------------------------
// 5. APPS DATA
// -------------------------------------------------------------------------

// -------------------------------------------------------------------------
// 6. SEARCH APPEARANCE (rich-result type breakdown — for All Data tab)
// -------------------------------------------------------------------------
$searchAppearanceRows = [];

if ($canShowData && $hasPremiumAccess) {
    $payload = json_encode([
        'instanceId' => $instanceId,
        'range'      => '30days',
        'type'       => 'appearance'
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (!empty($data['success']) && !empty($data['rows'])) {
        $searchAppearanceRows = $data['rows'];
    }
}

// -------------------------------------------------------------------------
// 7. GOOGLE SITEMAP STATUS (indexed vs submitted — for All Data tab)
// -------------------------------------------------------------------------
$sitemapStatus = [];

if ($canShowData && $hasPremiumAccess && !empty($googleAccount['access_token']) && $verifiedSiteUrl) {
    // Build site identifier variants for GSC API (sc-domain, https, https+www)
    $clean = preg_replace('#^https?://#', '', (string)$verifiedSiteUrl);
    $clean = preg_replace('#^www\.#', '', (string)$clean);
    $clean = rtrim((string)$clean, '/');
    $siteVariantsForList = [
        'sc-domain:' . $clean,
        'https://' . $clean . '/',
        'https://www.' . $clean . '/',
    ];

    foreach ($siteVariantsForList as $variant) {
        $ch = curl_init('https://www.googleapis.com/webmasters/v3/sites/' . urlencode($variant) . '/sitemaps');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $googleAccount['access_token'],
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http >= 200 && $http < 300) {
            $j = json_decode((string)$resp, true);
            $entries = $j['sitemap'] ?? [];
            foreach ($entries as $s) {
                $totalSubmitted = 0;
                $totalIndexed   = 0;
                foreach (($s['contents'] ?? []) as $c) {
                    $totalSubmitted += (int)($c['submitted'] ?? 0);
                    $totalIndexed   += (int)($c['indexed'] ?? 0);
                }
                $sitemapStatus[] = [
                    'path'           => $s['path'] ?? '',
                    'lastSubmitted'  => $s['lastSubmitted'] ?? null,
                    'lastDownloaded' => $s['lastDownloaded'] ?? null,
                    'isPending'      => !empty($s['isPending']),
                    'warnings'       => (int)($s['warnings'] ?? 0),
                    'errors'         => (int)($s['errors'] ?? 0),
                    'submitted'      => $totalSubmitted,
                    'indexed'        => $totalIndexed,
                ];
            }
            if (!empty($sitemapStatus)) break;
        }
    }
}

// -------------------------------------------------------------------------
// 8. DB: SITE PROFILE / SUBMISSION STATS / RECENT INSPECTIONS (All Data tab)
// -------------------------------------------------------------------------
$siteProfile        = [];
$submissionStats30d = ['total' => 0, 'success' => 0, 'errors' => 0, 'last_at' => null];
$recentInspections  = [];
if ($instanceId) {
    try {
        $stmt = $pdo->prepare("SELECT company_name, business_name, category_name, business_type, language, address, email, phone, website_url, logo_url, created_at FROM WixSiteProfile WHERE instance_id = ? LIMIT 1");
        $stmt->execute([$instanceId]);
        $siteProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $siteProfile = [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status='Success' THEN 1 ELSE 0 END) AS success_count,
                SUM(CASE WHEN status<>'Success' THEN 1 ELSE 0 END) AS error_count,
                MAX(submitted_at) AS last_at
            FROM sitemap_submission_logs
            WHERE instance_id = ?
              AND submitted_at >= (NOW() - INTERVAL 30 DAY)
        ");
        $stmt->execute([$instanceId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $submissionStats30d['total']   = (int)($r['total'] ?? 0);
        $submissionStats30d['success'] = (int)($r['success_count'] ?? 0);
        $submissionStats30d['errors']  = (int)($r['error_count'] ?? 0);
        $submissionStats30d['last_at'] = $r['last_at'] ?? null;
    } catch (Throwable $e) {
        // table may not exist — ignore
    }

    try {
        $stmt = $pdo->prepare("
            SELECT inspected_url, response_json, fetched_at
            FROM url_inspection_cache
            WHERE instance_id = ?
            ORDER BY id DESC
            LIMIT 10
        ");
        $stmt->execute([$instanceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $resp = json_decode((string)($r['response_json'] ?? ''), true);
            $recentInspections[] = [
                'url'         => (string)$r['inspected_url'],
                'fetched_at'  => $r['fetched_at'] ?? null,
                'verdict'     => $resp['index']['verdict']        ?? null,
                'coverage'    => $resp['index']['coverage_state'] ?? null,
                'last_crawl'  => $resp['index']['last_crawl_time'] ?? null,
                'rich_verdict' => $resp['rich_results']['verdict'] ?? null,
            ];
        }
    } catch (Throwable $e) {
        $recentInspections = [];
    }
}

// -------------------------------------------------------------------------
// SAMPLE DATA FALLBACK  ()
// -------------------------------------------------------------------------
// Same story: this file substitutes demo rows when a section has no real
// data. Guarded rather than required, so the dashboard works without it and
// starts using it the moment it is added.
if (is_file(__DIR__ . '/includes/sample_fallback.php')) {
    require_once __DIR__ . '/includes/sample_fallback.php';
}
?>

<?php
// -------------------------------------------------------------------------
// 9. GSC PROPERTY DETAILS (for the Connection Details sidebar)
//
// Place this AFTER section 8, just before the sample-data fallback include.
//
// Property type cannot be worked out from the stored site_url. A property is
// either a domain property (sc-domain:example.com) or a URL-prefix property
// (https://www.example.com/), and the database only holds whatever URL was
// saved at setup time — which may differ from the canonical property. We saw
// a site stored as https://example.com/ whose real property was
// https://www.example.com/, a URL-prefix property.
//
// So we ask Google. sites.list is one lightweight call that returns every
// property the token can see, along with the permission level.
// -------------------------------------------------------------------------

$gscProperty = [
    'id'          => null,    // exact property string Google holds
    'type'        => null,    // 'domain' | 'url-prefix'
    'type_label'  => null,    // shown in the sidebar
    'permission'  => null,    // siteOwner / siteFullUser / siteRestrictedUser / siteUnverifiedUser
    'available'   => false,   // false when the lookup failed
];

if ($canShowData && !empty($googleAccount['access_token']) && $verifiedSiteUrl) {

    $ch = curl_init('https://www.googleapis.com/webmasters/v3/sites');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $googleAccount['access_token'],
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $sitesResp = curl_exec($ch);
    $sitesCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $entries = ($sitesCode >= 200 && $sitesCode < 300)
        ? (json_decode((string)$sitesResp, true)['siteEntry'] ?? [])
        : [];

    if ($entries) {

        // Reduce a URL to "host-without-www + path" so that http/https and
        // www/non-www differences do not cause a mismatch.
        $normKey = static function (string $url): string {
            $url   = preg_replace('#^https?://#i', '', trim($url));
            $parts = explode('/', (string)$url, 2);
            $host  = preg_replace('#^www\.#i', '', strtolower($parts[0]));
            $path  = isset($parts[1]) ? rtrim('/' . $parts[1], '/') : '';
            return $host . $path;
        };

        $want     = $normKey(rtrim((string)$verifiedSiteUrl, '/'));
        $wantHost = explode('/', $want, 2)[0];
        $match    = null;

        // 1) URL-prefix property matching on host + path
        foreach ($entries as $e) {
            $sv = (string)($e['siteUrl'] ?? '');
            if ($sv === '' || stripos($sv, 'sc-domain:') === 0) continue;
            if ($normKey($sv) === $want) {
                $match = $e;
                break;
            }
        }

        // 2) domain property covering this host
        if (!$match) {
            foreach ($entries as $e) {
                $sv = (string)($e['siteUrl'] ?? '');
                if (stripos($sv, 'sc-domain:') !== 0) continue;
                $domain = preg_replace('#^www\.#i', '', strtolower(substr($sv, 10)));
                if ($domain === $wantHost) {
                    $match = $e;
                    break;
                }
            }
        }

        // 3) only one property on the account — it must be this one
        if (!$match && count($entries) === 1) {
            $match = $entries[0];
        }

        if ($match) {
            $sv       = (string)($match['siteUrl'] ?? '');
            $isDomain = stripos($sv, 'sc-domain:') === 0;

            $gscProperty = [
                'id'         => $sv,
                'type'       => $isDomain ? 'domain' : 'url-prefix',
                'type_label' => $isDomain ? 'Domain Property' : 'URL-prefix Property',
                'permission' => (string)($match['permissionLevel'] ?? ''),
                'available'  => true,
            ];
        }
    }
}

// Human-readable permission level. Google returns these as enum strings.
$gscPermissionLabel = match ($gscProperty['permission'] ?? '') {
    'siteOwner'           => 'Owner',
    'siteFullUser'        => 'Full access',
    'siteRestrictedUser'  => 'Restricted access',
    'siteUnverifiedUser'  => 'Not verified',
    default               => null,
};

// -------------------------------------------------------------------------
// Connection dates — these were hardcoded in the sidebar. The verification
// row already carries them.
// -------------------------------------------------------------------------
$gscConnectedOn = null;
$gscLastUpdated = null;

try {
    $stmt = $pdo->prepare("
        SELECT created_at, verification_verified_at, updated_at
        FROM gsc_domain_verifications
        WHERE instance_id = ?
        LIMIT 1
    ");
    $stmt->execute([$instanceId]);
    $verRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $connectedRaw = $verRow['verification_verified_at'] ?: ($verRow['created_at'] ?? null);
    if ($connectedRaw) {
        $gscConnectedOn = date('j M Y', strtotime((string)$connectedRaw));
    }
    if (!empty($verRow['updated_at'])) {
        $gscLastUpdated = (string)$verRow['updated_at'];
    }
} catch (Throwable $e) {
    // column set may differ — leave both null and the sidebar shows a dash
}

/**
 * "2 minutes ago" style text from a datetime string.
 */
if (!function_exists('gscTimeAgo')) {
    function gscTimeAgo(?string $datetime): ?string
    {
        if (!$datetime) return null;
        $ts = strtotime($datetime);
        if (!$ts) return null;

        $diff = time() - $ts;
        if ($diff < 0)     return 'just now';
        if ($diff < 60)    return 'just now';
        if ($diff < 3600)  return floor($diff / 60) . ' minute' . (floor($diff / 60) == 1 ? '' : 's') . ' ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hour' . (floor($diff / 3600) == 1 ? '' : 's') . ' ago';
        if ($diff < 2592000) {
            $d = floor($diff / 86400);
            return $d . ' day' . ($d == 1 ? '' : 's') . ' ago';
        }
        return date('j M Y', $ts);
    }
}


$sitemapRows = [];

if ($hasSitemap) {
    $stmt = $pdo->prepare("
        SELECT sitemap_url, last_downloaded, last_synced_at, status,
               errors, warnings, is_index, discovered_pages
        FROM sitemaps
        WHERE instance_id = ?
        ORDER BY (errors > 0) DESC, (warnings > 0) DESC, last_submitted_at DESC
        LIMIT 4
    ");
    $stmt->execute([$instanceId]);
    $sitemapRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * State for one sitemap row, in the same order of urgency as the headline.
 *
 * @return array{label:string,cls:string,dot:string}
 */
function gscRowState(array $s): array
{
    if ((int)$s['errors'] > 0) {
        return ['label' => 'Error', 'cls' => 'text-danger-600', 'dot' => 'bg-danger-600'];
    }
    if ((int)$s['warnings'] > 0) {
        $n = (int)$s['warnings'];
        return [
            'label' => $n . ' warning' . ($n === 1 ? '' : 's'),
            'cls' => 'text-warning-600',
            'dot' => 'bg-warning-600'
        ];
    }
    if (empty($s['last_synced_at'])) {
        return ['label' => 'Not checked', 'cls' => 'text-neutral-500', 'dot' => 'bg-neutral-400'];
    }
    if (empty($s['last_downloaded'])) {
        return ['label' => 'Awaiting Google', 'cls' => 'text-warning-600', 'dot' => 'bg-warning-600'];
    }
    return ['label' => 'Read', 'cls' => 'text-success-main', 'dot' => 'bg-success-main'];
}
?>

<?php include './partials/layouts/layoutTop.php' ?>

<script>
    window.gscChartData = <?= json_encode($chartData); ?>;
</script>
<script>
    window.topPagesData = <?= json_encode($pagesReportAll ?? []); ?>;
</script>

<style>
    /* Improved GSC dropdown styles */
    #gsc-range-toggle {
        border: 1px solid rgba(15, 23, 42, 0.06);
    }

    #gsc-range-menu {
        box-shadow: 0 8px 20px rgba(2, 6, 23, 0.06);
    }

    #gsc-range-menu .gsc-range-item {
        background: transparent;
        border: none;
        width: 100%;
    }

    #gsc-range-menu .gsc-range-item:focus {
        outline: none;
    }

    #gsc-range-menu .input {
        border: 1px solid rgba(15, 23, 42, 0.06);
        background: transparent;
    }

    @media (max-width: 640px) {
        #gsc-range-menu {
            width: 76vw;
            left: 0;
            right: 0;
        }
    }

    .tab-btn {
        padding-bottom: 10px;
        color: #6b7280;
        border-bottom: 2px solid transparent;
    }

    .tab-btn:hover {
        color: var(--clr-primary);
    }
    .tab-btn.top-tab {
        border: 1px solid #d1d5db;
        padding: 6px 18px;
        border-radius: 6px;
        transition: .3s;
        background-color: #fff;
    }

    .tab-btn.top-tab:hover {
       color: var(--clr-primary);
        border-color: var(--clr-primary);
        box-shadow: inset 0 0 0 1px var(--clr-primary);
    }
    .active-tab {
        color: var(--clr-primary);
        border-color: var(--clr-primary);
        border-bottom: 4px solid
    }
</style>

<?php
/* The first-report strip, replacing gsc_sample_banner().

   The old one said "up to 48 hours" on every load, whatever the date.
   This partial counts from verification_verified_at, links to a page that
   explains the wait, and renders nothing once real data arrives, because
   it reads $gscSampleSections rather than deciding for itself. */
$frPartial = __DIR__ . '/partials/first-report-banner.php';
if (is_file($frPartial)) {
    include $frPartial;
}
?>

<div class="card border-0 border-gray-200 flex justify-between mb-3 bg-transparent">
    <nav class="flex overflow-x-auto gap-2 text-sm font-medium">
        <a href="https://makkpressapps.com/wordpress/googlesearchconsole/gsc-report.php?tab=keywords" class="tab-btn top-tab flex items-center gap-1 text-xs shrink-0">
            <iconify-icon icon="solar:widget-5-bold" class="text-sm"></iconify-icon>
            Overview
        </a>
        <button class="tab-btn top-tab flex items-center gap-1 text-xs shrink-0"
            data-tab="tab-2" data-scroll="ready_to_buy">
             <iconify-icon icon="solar:chart-2-bold" class="text-sm"></iconify-icon>
            Query Growth Engine
        </button>
        <button class="tab-btn top-tab flex items-center gap-1 text-xs shrink-0" data-tab="tab-5" data-scroll="product_research">
            <iconify-icon icon="solar:users-group-rounded-bold" class="text-sm"></iconify-icon>
            Lead Generator/Business
        </button>
        <button class="tab-btn top-tab flex items-center gap-1 text-xs shrink-0" data-tab="tab-1"  data-scroll="trust_comparison">
             <iconify-icon icon="solar:shield-check-bold" class="text-sm"></iconify-icon>
            Brand & Non-Brand keywords
        </button>
        <button class="tab-btn top-tab flex items-center gap-1 text-xs shrink-0" data-tab="tab-3" data-scroll="post_purchase">
            <iconify-icon icon="solar:document-text-bold" class="text-sm"></iconify-icon>
            Top Pages
        </button>
        <button class="tab-btn top-tab flex items-center gap-1 text-xs shrink-0" data-tab="tab-4" data-scroll="post_purchase">
             <iconify-icon icon="solar:documents-bold" class="text-sm"></iconify-icon>
            Pages Report
        </button>
    </nav>
</div>
<div class="grid grid-cols-1 md:grid-cols-12 gap-4 mt-2 mb-4">
    <div class="col-span-12 md:col-span-4">
        <div class="card border-0 px-6 py-4 h-full">
            <h2 class="text-lg font-neutral-800">Welcome back, Makkpress studio!</h2>
            <p class="text-sm text-gray-600 font-medium mt-2">Here's how site performed on Google Search</p>
        </div>
    </div>
    <div class="col-span-12 md:col-span-8">
        <div class="card bg-white dark:bg-neutral-800 border-0 border-neutral-200 dark:border-neutral-700 px-6 py-4">
            <div class="flex flex-row justify-between gap-6 flex-col sm:flex-row">

                <!-- Left -->
                <div class="flex-1">
                    <div class="flex items-center gap-4">
                        <!-- Google Icon -->
                        <div class="bg-white border-0 border-neutral-200 flex items-center justify-center shrink-0">
                            <iconify-icon icon="logos:google-icon" class="text-2xl"></iconify-icon>
                        </div>
                        <div>
                            <h5 class="text-base font-semibold dark:text-white">
                                Google Search Console
                            </h5>
                            <?php if (!empty($siteUrl)): ?>
                                <a href="<?= $siteUrl ?>" target="_blank"
                                    class="group flex items-center leading-[1] gap-1 border-0 dark:border-neutral-700 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:border-cstm-primary hover:text-cstm-primary transition-all max-w-[140px] lg:max-w-[290px] mobile_d_none">
                                    <span class="truncate min-w-0"><?= $siteUrl ?></span>
                                    <iconify-icon icon="lucide:external-link" class="text-sm"></iconify-icon>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right -->
                <div class="flex flex-col justify-between items-end w-full sm:w-fit">
                    <div class="flex items-center gap-3 w-full justify-between">
                        <!-- Connected Badge -->
                        <div
                            class="inline-flex items-center gap-2 rounded-full bg-success-50 text-success-main px-3 py-2 text-xs font-semibold">
                            <span class="w-2 h-2 rounded-full bg-success-600"></span>
                            <?= $isConnected ? 'Connected' : 'Not Connected' ?>
                        </div>

                        <!-- Menu -->
                        <div class="relative">
                            <button id="gscMenuBtn"
                                class="py-2 px-2 rounded-md border border-neutral-200 dark:border-neutral-600 flex items-center justify-center hover:bg-neutral-100 dark:hover:bg-neutral-700">
                                <iconify-icon icon="mdi:dots-vertical"></iconify-icon>
                            </button>

                            <!-- Dropdown -->
                            <div id="gscDropdown"
                                class="hidden absolute right-0 top-full mt-2 whitespace-nowrap bg-white dark:bg-neutral-800 rounded-xl border border-neutral-200 dark:border-neutral-700 shadow-xl overflow-hidden z-50">
                                <button id="openConnectionDetails" class="w-full px-5 py-4 text-sm flex items-center gap-3 hover:bg-neutral-50 dark:hover:bg-neutral-700">
                                    <iconify-icon icon="lucide:info" class="text-lg"></iconify-icon>
                                    <span class="text-sm font-medium dark:text-white"> Connection Details </span>
                                </button>
                                <button id="disconnectDirectBtn" class="hidden w-full px-5 py-4 text-sm flex items-center gap-3 text-danger-600 hover:bg-danger-100 dark:hover:bg-red-900/20">
                                    <iconify-icon icon="mdi:link-off" class="text-lg"></iconify-icon>
                                    <span class="text-sm font-medium">Disconnect</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Status Pills -->
            <div class="flex flex-wrap gap-3 mt-2">
                <div class="inline-flex items-center gap-2 bg-success-50 text-success-main font-semibold rounded-full px-3 py-1 text-xs">
                    <iconify-icon icon="solar:check-circle-outline" class="text-sm"></iconify-icon>
                    <?= $isVerified ? 'Domain Verified' : 'Domain not Verified' ?>
                </div>

                <a href="sitemap.php" title="<?= htmlspecialchars($sitemapHead['detail']) ?>" class="inline-flex items-center gap-2 font-semibold rounded-full px-3 py-1 text-xs transition hover:opacity-80 <?= $sitemapHead['cls'] ?>">
                    <iconify-icon icon="<?= $sitemapHead['icon'] ?>" class="text-sm"></iconify-icon>
                    <?= htmlspecialchars($sitemapHead['label']) ?>
                </a>



                <div class="inline-flex items-center gap-2 bg-success-50 text-success-main font-semibold rounded-full px-3 py-1 text-xs">
                    <iconify-icon icon="solar:check-circle-outline" class="text-sm"></iconify-icon>
                    API Authorized
                </div>
            </div>
        </div>
    </div>
</div>


<!-- Sidebar Container - start -->
<div id="gscSidebarBackdrop" class="fixed inset-0 bg-cstm-black-40 backdrop-blur-sm z-50 hidden opacity-0 transition-opacity duration-300"></div>
<div id="gscSidebar" class="fixed top-0 right-0 h-full w-full max-w-sm max-w-[320px] bg-white dark:bg-neutral-900 z-50 transform translate-x-full transition-transform duration-300 ease-in-out overflow-y-auto flex flex-col justify-between p-6 pt-4">

    <div>
        <!-- Sidebar Header -->
        <div class="flex items-center justify-between border-b border-neutral-100 dark:border-neutral-800 pb-3 mb-4">
            <h3 class="text-lg font-semibold text-neutral-900 dark:text-white leading-[1]">Google Search Console</h3>
            <button id="closeConnectionSidebar" class="h-8 w-8 flex justify-center items-center leading-[1] rounded-lg text-neutral-400 hover:text-neutral-900 dark:hover:text-white hover:bg-neutral-200 dark:hover:bg-neutral-800 transition-colors">
                <iconify-icon icon="lucide:x" class="text-xl"></iconify-icon>
            </button>
        </div>

        <!-- Connection Banner -->
        <div class="bg-success-50 dark:bg-emerald-950/30 border border-success-200 dark:border-emerald-900/50 rounded-lg p-4 mb-6 flex items-start gap-3">
            <div class="bg-success-main text-white p-1 rounded-full flex items-center justify-center shrink-0 mt-0.5">
                <iconify-icon icon="lucide:check" class="text-sm font-bold"></iconify-icon>
            </div>
            <div>
                <h4 class="text-sm font-semibold text-success-main dark:text-emerald-300">Connected</h4>
                <p class="text-xs text-success-600 dark:text-emerald-400/80 mt-0.5 leading-relaxed">
                    Your Search Console data is active and up to date.
                </p>
            </div>
        </div>

        <!-- Overview Title -->
        <h4 class="text-sm font-semibold text-neutral-900 dark:text-white mb-4">Connection Overview</h4>

        <!-- Data List -->
        <div class="space-y-3 text-xs">
            <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                <span class="text-neutral-500 dark:text-neutral-400 font-medium whitespace-nowrap flex-1">Google Account</span>
                <span class="text-neutral-900 dark:text-white font-semibold flex-1 truncate max-w-[180px]"><?= htmlspecialchars($googleAccount['email'] ?? 'hello@makkpress.com') ?></span>
            </div>

            <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                <span class="text-neutral-500 dark:text-neutral-400 font-medium whitespace-nowrap flex-1">Property</span>
                <a href="<?= htmlspecialchars($siteUrl ?? '#') ?>" target="_blank" class="text-neutral-900 dark:text-white font-semibold flex-1 hover:underline truncate max-w-[180px]">
                    <?= htmlspecialchars($siteUrl ?? '') ?>
                </a>
            </div>


            <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                <span class="text-neutral-500 dark:text-neutral-400 font-medium whitespace-nowrap flex-1">Property Type</span>
                <?php if ($gscProperty['available']): ?>
                    <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-semibold"
                        title="<?= htmlspecialchars((string)$gscProperty['id']) ?>">
                        <?= htmlspecialchars((string)$gscProperty['type_label']) ?>
                    </span>
                <?php else: ?>
                    <span class="inline-flex items-center gap-1 text-neutral-400 font-semibold">—</span>
                <?php endif; ?>
            </div>

            <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                <span class="text-neutral-500 dark:text-neutral-400 font-medium whitespace-nowrap flex-1">Access</span>
                <span class="inline-flex items-center gap-1 text-success-main dark:text-emerald-400 font-semibold">
                    <iconify-icon icon="mdi:check" class="text-sm"></iconify-icon> Authorized
                </span>
            </div>

            <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                <span class="text-neutral-500 dark:text-neutral-400 font-medium whitespace-nowrap flex-1">Domain Verified</span>
                <span class="inline-flex items-center gap-1 text-success-main dark:text-emerald-400 font-semibold">
                    <iconify-icon icon="mdi:check" class=" text-sm"></iconify-icon> <?= $isVerified ? 'Yes' : 'No' ?>
                </span>
            </div>

            <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                <span class="text-neutral-500 dark:text-neutral-400 font-medium whitespace-nowrap flex-1">Sitemap</span>
                <span class="inline-flex items-center gap-1 font-semibold <?= $sitemapHead['key'] === 'ok'    ? 'text-success-main dark:text-emerald-400'
                                                                                : ($sitemapHead['key'] === 'error' || $sitemapHead['key'] === 'none' ? 'text-danger-600'
                                                                                    : ($sitemapHead['key'] === 'warning' || $sitemapHead['key'] === 'pending' ? 'text-warning-600'
                                                                                        : 'text-neutral-500')) ?>"
                    title="<?= htmlspecialchars($sitemapHead['detail']) ?>">
                    <iconify-icon icon="<?= $sitemapHead['icon'] ?>" class="text-sm"></iconify-icon>
                    <?= htmlspecialchars($sitemapHead['label']) ?>
                </span>
            </div>

            <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                <span class="text-neutral-500 dark:text-neutral-400 font-medium whitespace-nowrap flex-1">Connected On</span>
                <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-semibold">
                    <?= !empty($siteProfile['created_at'])
                        ? date('j M Y', strtotime($siteProfile['created_at']))
                        : '—' ?>
                </span>
            </div>

            <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                <span class="text-neutral-500 dark:text-neutral-400 font-medium whitespace-nowrap flex-1">Data Status</span>
                <span class="inline-flex items-center gap-1.5 font-semibold" style="color:#16a34a;">
                    <span class="live-dot"></span>
                    Live
                </span>
            </div>
        </div>

        <!-- Info Box -->
        <div class="mt-3 bg-gray-50 dark:bg-blue-950/20 border border-gray-100 dark:border-blue-900/30 rounded-lg p-4 flex items-start gap-3">
            <iconify-icon icon="lucide:lock" class="text-primary-600 text-base shrink-0 mt-0.5"></iconify-icon>
            <div class="text-xs text-gray-700 dark:text-blue-300 leading-relaxed">
                We use the Google Search Console API to fetch your data securely.
                <a href="#" class="inline-flex items-center gap-1 text-primary-600 dark:text-blue-400 font-semibold hover:underline mt-1">
                    Learn more <iconify-icon icon="lucide:external-link" class="text-xs"></iconify-icon>
                </a>
            </div>
        </div>
    </div>

    <!-- Bottom Actions -->
    <div class="pt-6 border-t border-neutral-100 dark:border-neutral-800">
        <button id="disconnectDirectBtn1" class="w-full py-3 px-4 bg-danger-50 hover:bg-red-50 text-danger-600 dark:bg-neutral-800 dark:hover:bg-red-950/30 border border-neutral-200 dark:border-neutral-700 hover:border-red-200 rounded-lg text-xs font-semibold flex items-center justify-center gap-2 transition-all">
            <iconify-icon icon="lucide:unplug" class="text-base"></iconify-icon>
            Disconnect
        </button>
    </div>
</div>
<!-- Sidebar Container - end -->

<div class="grid grid-cols-12 gap-4 mb-4">
    <div class="col-span-12 lg:col-span-8 h-full">
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 items-stretch mb-4">

            <!-- Card 1: Total Clicks (Blue Theme) -->
            <div class="card border-0 flex flex-col justify-between transition-all p-4">
                <div>
                    <!-- Title & Help Icon -->
                    <div class="flex items-center gap-1.5 mb-2">
                        <span class="text-xs font-bold text-blue-600">Total Clicks</span>
                        <iconify-icon icon="lucide:circle-help" class="text-gray-400 hover:text-blue-500 cursor-pointer" width="14" height="14" data-tooltip-target="tooltip-total-clicks" data-tooltip-placement="top"></iconify-icon>
                        <div id="tooltip-total-clicks" role="tooltip"
                            class="absolute z-50 invisible inline-block w-60 p-3 text-sm text-gray-600 transition-opacity duration-300 bg-white border border-gray-200 rounded-lg shadow-lg opacity-0 tooltip dark:bg-neutral-800 dark:border-neutral-700 dark:text-gray-300">
                            <p class="text-xs leading-5">
                                Total Clicks represents the number of times users clicked your website from Google Search results during the selected period.
                            </p>

                            <div class="tooltip-arrow" data-popper-arrow></div>
                        </div>
                    </div>

                    <!-- Metric & Percentage Change -->
                    <div class="flex items-baseline gap-2 mb-1">
                        <h3 class="text-lg font-semibold text-blue-600 tracking-tight"
                            title="<?= number_format((int)$kpi['clicks']) ?> clicks">
                            <?= gscShortNum($kpi['clicks']) ?>
                        </h3>
                        <?= gsc_sample_badge(gsc_is_sample('kpi')) ?>
                        <?= $kpiPrev ? gscChangeBadge(gscPctChange($kpi['clicks'], $kpiPrev['clicks'])) : '' ?>
                    </div>

                    <!-- Date Range Subtext -->
                    <p class="text-xs font-medium text-gray-400">
                        <?= $kpiPrev ? htmlspecialchars($kpiRangeLabel) : 'Last 30 days' ?>
                    </p>
                </div>

                <!-- Sparkline Graphic SVG -->
                <div class="w-full pt-2 hidden">
                    <svg class="w-full h-8 text-primary-600 overflow-visible" viewBox="0 0 100 25" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M0 20 L10 16 L20 18 L30 14 L40 10 L50 15 L60 11 L70 12 L80 8 L90 10 L100 4" />
                    </svg>
                </div>
            </div>

            <!-- Card 2: Total Impressions (Purple Theme) -->
            <div class="card border-0 flex flex-col justify-between transition-all p-4">
                <div>
                    <!-- Title & Help Icon -->
                    <div class="flex items-center gap-1.5 mb-2">
                        <span class="text-xs font-bold text-purple-600">Total Impressions</span>
                        <iconify-icon icon="lucide:circle-help" class="text-gray-400 hover:text-purple-500 cursor-pointer" width="14" height="14" data-tooltip-target="tooltip-total-impressions" data-tooltip-placement="top"> </iconify-icon>
                        <div id="tooltip-total-impressions"
                            role="tooltip"
                            class="absolute z-50 invisible inline-block w-60 p-3 text-sm text-gray-600 transition-opacity duration-300 bg-white border border-gray-200 rounded-lg shadow-lg opacity-0 tooltip dark:bg-neutral-800 dark:border-neutral-700 dark:text-gray-300">

                            <p class="text-xs leading-5">
                                Total Impressions represents the number of times your website appeared in Google Search results during the selected period.
                            </p>

                            <div class="tooltip-arrow" data-popper-arrow></div>
                        </div>
                    </div>

                    <!-- Metric & Percentage Change -->
                    <div class="flex items-baseline gap-2 mb-1">
                        <h3 class="text-lg font-semibold text-purple-600 tracking-tight"
                            title="<?= number_format((int)$kpi['impressions']) ?> impressions">
                            <?= gscShortNum($kpi['impressions']) ?>
                        </h3>
                        <?= gsc_sample_badge(gsc_is_sample('kpi')) ?>
                        <?= $kpiPrev ? gscChangeBadge(gscPctChange($kpi['impressions'], $kpiPrev['impressions'])) : '' ?>
                    </div>

                    <!-- Date Range Subtext -->
                    <p class="text-xs font-medium text-gray-400">
                        <?= $kpiPrev ? htmlspecialchars($kpiRangeLabel) : 'Last 30 days' ?>
                    </p>
                </div>

                <!-- Sparkline Graphic SVG -->
                <div class="w-full pt-2 hidden">
                    <svg class="w-full h-8 text-primary-600 overflow-visible" viewBox="0 0 100 25" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M0 18 L10 14 L20 16 L30 11 L40 13 L50 7 L60 12 L70 9 L80 14 L90 6 L100 10" />
                    </svg>
                </div>
            </div>

            <!-- Card 3: Average CTR (Green Theme) -->
            <div class="card border-0 flex flex-col justify-between transition-all p-4">
                <div>
                    <!-- Title & Help Icon -->
                    <div class="flex items-center gap-1.5 mb-2">
                        <span class="text-xs font-bold text-success-main">Average CTR</span>
                        <iconify-icon icon="lucide:circle-help" class="text-gray-400 hover:text-emerald-500 cursor-pointer" width="14" height="14" data-tooltip-target="tooltip-average-ctr" data-tooltip-placement="top"> </iconify-icon>
                        <div id="tooltip-average-ctr"
                            role="tooltip"
                            class="absolute z-50 invisible inline-block w-60 p-3 text-sm text-gray-600 transition-opacity duration-300 bg-white border border-gray-200 rounded-lg shadow-lg opacity-0 tooltip dark:bg-neutral-800 dark:border-neutral-700 dark:text-gray-300">

                            <p class="text-xs leading-5">
                                Average CTR (Click-Through Rate) is the percentage of impressions that resulted in a click from Google Search.
                            </p>

                            <div class="tooltip-arrow" data-popper-arrow></div>
                        </div>
                    </div>

                    <!-- Metric & Percentage Change -->
                    <div class="flex items-baseline gap-2 mb-1">
                        <h3 class="text-lg font-semibold text-slate-800 tracking-tight">
                            <?= number_format((float)$kpi['ctr'], 2) ?>%
                        </h3>
                        <?= gsc_sample_badge(gsc_is_sample('kpi')) ?>
                        <?php
                        // CTR is itself a percentage, so the change is shown in
                        // percentage points rather than a percentage of a percentage.
                        if ($kpiPrev) {
                            $ctrDelta = round((float)$kpi['ctr'] - (float)$kpiPrev['ctr'], 2);
                            echo gscChangeBadge($ctrDelta !== 0.0 ? $ctrDelta : null, false, ' pp');
                        }
                        ?>
                    </div>

                    <!-- Date Range Subtext -->
                    <p class="text-xs font-medium text-gray-400">
                        <?= $kpiPrev ? htmlspecialchars($kpiRangeLabel) : 'Last 30 days' ?>
                    </p>
                </div>

                <!-- Sparkline Graphic SVG -->
                <div class="w-full pt-2 hidden">
                    <svg class="w-full h-8 text-success-600 overflow-visible" viewBox="0 0 100 25" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M0 19 L10 17 L20 20 L30 16 L40 11 L50 17 L60 10 L70 14 L80 11 L90 15 L100 12" />
                    </svg>
                </div>
            </div>

            <!-- Card 4: Average Position (Orange Theme) -->
            <div class="card border-0 flex flex-col justify-between transition-all p-4">
                <div>
                    <!-- Title & Help Icon -->
                    <div class="flex items-center gap-1.5 mb-2">
                        <span class="text-xs font-bold text-success-main">Average Position</span>
                        <iconify-icon icon="lucide:circle-help" class="text-gray-400 hover:text-amber-500 cursor-pointer" width="14" height="14" data-tooltip-target="tooltip-average-position" data-tooltip-placement="top"> </iconify-icon>
                        <div id="tooltip-average-position"
                            role="tooltip"
                            class="absolute z-50 invisible inline-block w-60 p-3 text-sm text-gray-600 transition-opacity duration-300 bg-white border border-gray-200 rounded-lg shadow-lg opacity-0 tooltip dark:bg-neutral-800 dark:border-neutral-700 dark:text-gray-300">
                            <p class="text-xs leading-5">
                                Average Position indicates the average ranking of your website in Google Search results for all queries during the selected period.
                            </p>
                            <div class="tooltip-arrow" data-popper-arrow></div>
                        </div>
                    </div>

                    <!-- Metric & Percentage Change -->
                    <div class="flex items-baseline gap-2 mb-1">
                        <h3 class="text-lg font-semibold text-slate-800 tracking-tight">
                            <?= number_format((float)$kpi['position'], 1) ?>
                        </h3>
                        <?= gsc_sample_badge(gsc_is_sample('kpi')) ?>
                        <?php
                        // A falling position number is an improvement, so this badge
                        // is coloured the other way round.
                        if ($kpiPrev) {
                            $posDelta = round((float)$kpi['position'] - (float)$kpiPrev['position'], 1);
                            echo gscChangeBadge($posDelta !== 0.0 ? $posDelta : null, true, '');
                        }
                        ?>
                    </div>

                    <!-- Date Range Subtext -->
                    <p class="text-xs font-medium text-gray-400">
                        <?= $kpiPrev ? htmlspecialchars($kpiRangeLabel) : 'Last 30 days' ?>
                    </p>
                </div>

                <!-- Sparkline Graphic SVG -->
                <div class="w-full pt-2 hidden">
                    <svg class="w-full h-8 text-success-600 overflow-visible" viewBox="0 0 100 25" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M0 18 L10 20 L20 17 L30 12 L40 16 L50 10 L60 14 L70 12 L80 15 L90 14 L100 8" />
                    </svg>
                </div>
            </div>
        </div>
        <div class="flex items-center justify-center rounded-lg bg-white drk-bg-cstm-primary-gradient-dark border-0 border-gray-200 dark:border-neutral-600 mb-4">
            <div
                class="card h-full dark:bg-neutral-800 border-0 border-gray-200 dark:border-neutral-600 relative w-full max-w-xl mx-auto">
                <div class="card-body p-5">

                    <!-- title, centred -->
                    <div class="flex justify-start items-center">
                        <h6 class="font-semibold text-lg flex items-center gap-2 text-neutral-900 dark:text-white flex-wrap mb-3">
                            <iconify-icon icon="solar:chart-2-bold" class="text-xl text-cstm-primary"></iconify-icon>
                            Traffic Performance (30 Days)
                            <?= gsc_sample_badge(gsc_is_sample('chart')) ?>
                        </h6>
                    </div>

                    <?php if ($canShowData && $hasPremiumAccess): ?>
                        <div id="gsc-chart-controls" class="flex flex-wrap items-center gap-4 mb-4 md:absolute top-3 right-4">
                            <div class="relative">
                                <button id="gsc-range-toggle"
                                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border bg-white dark:bg-neutral-800 text-sm shadow-sm"
                                    type="button">
                                    <span id="gsc-range-toggle-label" class="font-medium">Last 30 days</span>
                                    <svg class="w-4 h-4 text-gray-600" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M5.23 7.21a.75.75 0 011.06.02L10 11.293l3.71-4.06a.75.75 0 111.08 1.04l-4.25 4.658a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </button>

                                <div id="gsc-range-menu"
                                    class="hidden absolute left-0 mt-2 w-72 rounded-xl bg-white dark:bg-neutral-800 border border-gray-200 dark:border-neutral-700 shadow-lg z-10">
                                    <div class="p-3">
                                        <div class="flex flex-col gap-2">
                                            <button
                                                class="gsc-range-item text-left px-3 py-2 rounded hover:bg-gray-50 dark:hover:bg-neutral-700"
                                                data-range="7">Last 7 days</button>
                                            <button
                                                class="gsc-range-item text-left px-3 py-2 rounded hover:bg-gray-50 dark:hover:bg-neutral-700"
                                                data-range="30">Last 30 days</button>
                                            <button
                                                class="gsc-range-item text-left px-3 py-2 rounded hover:bg-gray-50 dark:hover:bg-neutral-700"
                                                data-range="90">Last 90 days</button>
                                            <button
                                                class="gsc-range-item text-left px-3 py-2 rounded hover:bg-gray-50 dark:hover:bg-neutral-700"
                                                data-range="365">Last year</button>
                                        </div>
                                        <hr class="my-3 border-gray-100 dark:border-neutral-700">
                                        <div class="text-xs text-neutral-500 mb-2">Or choose custom range</div>
                                        <div class="flex items-center gap-2">
                                            <label class="sr-only" for="gsc-start">Start date</label>
                                            <input id="gsc-start" type="date"
                                                class="input input-sm px-3 py-2 rounded-md border w-1/2" />
                                            <label class="sr-only" for="gsc-end">End date</label>
                                            <input id="gsc-end" type="date"
                                                class="input input-sm px-3 py-2 rounded-md border w-1/2" />
                                        </div>
                                        <div class="mt-3 flex justify-end gap-2">
                                            <button id="gsc-range-cancel"
                                                class="btn btn-sm px-3 py-1 rounded-md">Cancel</button>
                                            <button id="gsc-custom-apply"
                                                class="btn btn-sm px-3 py-1 rounded-md bg-cstm-primary text-white">Apply</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="ml-auto text-sm text-neutral-500 hidden">Showing <span id="gsc-range-label"
                                    class="font-medium">Last 30 days</span></div>
                        </div>

                        <!-- series captions double as the legend: click one to
                             hide or show that line -->
                        <div class="flex items-center justify-between px-1 mt-2 mb-1">
                            <span id="gsc-legend-clicks"
                                class="flex items-center gap-1.5 text-xs font-medium select-none transition-opacity"
                                style="color:#0ea5e9;">
                                <span class="w-2 h-2 rounded-full" style="background:#0ea5e9;"></span>
                                Clicks
                                <span id="gsc-total-clicks" class="text-neutral-500 dark:text-neutral-400"></span>
                            </span>
                            <span id="gsc-legend-impr"
                                class="flex items-center gap-1.5 text-xs font-medium select-none transition-opacity"
                                style="color:#6366f1;">
                                <span id="gsc-total-impr" class="text-neutral-500 dark:text-neutral-400"></span>
                                Impressions
                                <span class="w-2 h-2 rounded-full" style="background:#6366f1;"></span>
                            </span>
                        </div>

                        <div id="chart-area" class="min-h-[230px]"></div>

                    <?php else: ?>
                        <div class="h-[350px] flex flex-col items-center justify-center text-center
                                    bg-gray-50 dark:bg-neutral-700 rounded-lg
                                    border border-dashed border-gray-300 dark:border-neutral-600 p-6">

                            <div
                                class="w-12 h-12 bg-neutral-200 dark:bg-neutral-700 rounded-full flex items-center justify-center mb-3 mx-auto">
                                <iconify-icon icon="solar:lock-keyhole-bold-duotone"
                                    class="text-2xl text-neutral-400"></iconify-icon>
                            </div>

                            <h6 class="text-xl font-medium text-gray-900 dark:text-white">
                                Chart Locked
                            </h6>

                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 mb-4">
                                Upgrade your plan to unlock traffic insights.
                            </p>

                            <!-- ✅ UPGRADE BUTTON -->
                            <button type="button" class="open-upgrade-modal inline-flex items-center gap-2
                                    px-4 py-2 rounded-md
                                    bg-cstm-primary text-white
                                    text-sm font-medium
                                    hover:bg-cstm-primary/90 transition">
                                <iconify-icon icon="mdi:crown-outline" class="text-lg"></iconify-icon>
                                Upgrade Plan
                            </button>


                            <p class="text-xs text-gray-400 mt-3">
                                Available on paid plans & active trials
                            </p>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

        <div class="card border-0 p-6 hidden">
            <div class="flex justify-between items-center">
                <h3 class="text-lg font-semibold flex items-center gap-1">Submission History</h3>
                <a href="sitemap.php" class="text-xs font-bold text-blue-600 hover:text-blue-700 transition">View All</a>
            </div>

            <div class="cstm-scroll-sm overflow-x-auto">
                <table class="table bordered-table sm-table mb-0 w-full table-auto text-sm">
                    <thead>
                        <tr>
                            <th class="w-[55%]">Site Map Url</th>
                            <th class="!text-center w-[20%]">Status</th>
                            <!-- <th>Code</th> -->
                            <th class="w-[25%]">Date</th>
                            <!-- <th>Response</th> -->
                        </tr>
                    </thead>
                    <tbody id="submission-history-tbody">
                        <?php if (empty($history)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-10 text-neutral-500 ">No sitemap submissions yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="submission-history-pagination"
                class="card-footer bg-transparent border-t border-gray-200 dark:border-neutral-600 p-3 flex justify-end items-center gap-2"
                style="display: none;">
            </div>

        </div>
        <div class="card rounded-lg border-0">
            <div class="card-body p-0">

                <div class="flex items-center justify-between p-5 border-b border-gray-200 dark:border-neutral-600">
                    <h3 class="text-base font-semibold flex items-center gap-2">
                        <iconify-icon icon="solar:sitemap-outline" class="text-lg text-cstm-primary"></iconify-icon>
                        Sitemap
                    </h3>
                    <a href="sitemap.php" class="text-xs font-bold text-cstm-primary hover:underline">Manage</a>
                </div>

                <div class="p-5">

                    <?php
                    // Colours mirror the badge in the header card, so the two can
                    // never disagree about the same sitemap.
                    $tone = [
                        'ok'      => ['bg-success-50 dark:bg-success-600/15',  'text-success-main',  'border-success-200'],
                        'error'   => ['bg-danger-50 dark:bg-danger-600/15',    'text-danger-600',    'border-danger-200'],
                        'none'    => ['bg-danger-50 dark:bg-danger-600/15',    'text-danger-600',    'border-danger-200'],
                        'warning' => ['bg-warning-50 dark:bg-warning-600/15',  'text-warning-600',   'border-warning-200'],
                        'pending' => ['bg-warning-50 dark:bg-warning-600/15',  'text-warning-600',   'border-warning-200'],
                        'unknown' => ['bg-neutral-100 dark:bg-neutral-700/40', 'text-neutral-600',   'border-neutral-200'],
                    ][$sitemapHead['key']];
                    ?>

                    <div class="flex items-start gap-3 p-4 rounded-lg border <?= $tone[0] ?> <?= $tone[2] ?> dark:border-neutral-600">
                        <iconify-icon icon="<?= $sitemapHead['icon'] ?>" class="text-xl <?= $tone[1] ?> mt-0.5"></iconify-icon>
                        <div class="min-w-0">
                            <div class="font-semibold text-sm <?= $tone[1] ?>">
                                <?= htmlspecialchars($sitemapHead['label']) ?>
                            </div>
                            <p class="text-xs text-neutral-600 dark:text-neutral-400 mt-1 leading-relaxed">
                                <?= htmlspecialchars($sitemapHead['detail']) ?>
                            </p>
                        </div>
                    </div>

                    <?php if ($sitemapRows): ?>
                        <div class="mt-4 divide-y divide-gray-100 dark:divide-neutral-700">
                            <?php foreach ($sitemapRows as $sm):
                                $rs = gscRowState($sm);

                                // Trim the property prefix so a row reads
                                // "/pages-sitemap.xml" rather than the whole URL
                                $shortUrl = ($siteUrl && str_starts_with($sm['sitemap_url'], $siteUrl))
                                    ? substr($sm['sitemap_url'], strlen($siteUrl))
                                    : $sm['sitemap_url'];
                            ?>
                                <div class="flex items-center gap-3 py-2.5">
                                    <span class="w-2 h-2 rounded-full flex-none <?= $rs['dot'] ?>"></span>

                                    <a href="<?= htmlspecialchars($sm['sitemap_url']) ?>" target="_blank" rel="noopener"
                                        class="flex-1 min-w-0 text-xs text-neutral-700 dark:text-neutral-300 hover:text-cstm-primary truncate"
                                        style="font-family:ui-monospace,Menlo,monospace;"
                                        title="<?= htmlspecialchars($sm['sitemap_url']) ?>">
                                        <?= htmlspecialchars($shortUrl) ?>
                                    </a>

                                    <?php if (!empty($sm['is_index'])): ?>
                                        <span class="text-[9px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded
                                             bg-purple-100 text-purple-600 dark:bg-purple-600/25 dark:text-purple-300 flex-none">
                                            Index
                                        </span>
                                    <?php endif; ?>

                                    <span class="text-xs font-semibold flex-none <?= $rs['cls'] ?>">
                                        <?= htmlspecialchars($rs['label']) ?>
                                    </span>

                                    <span class="text-xs text-neutral-400 flex-none w-16 text-right">
                                        <?php if ($sm['discovered_pages'] !== null): ?>
                                            <?= number_format((int)$sm['discovered_pages']) ?> pg
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php $extra = (int)$sitemapStats['total'] - count($sitemapRows); ?>
                        <?php if ($extra > 0): ?>
                            <a href="sitemap.php" class="block mt-3 text-xs text-cstm-primary font-semibold hover:underline">
                                +<?= $extra ?> more sitemap<?= $extra === 1 ? '' : 's' ?>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($sitemapHead['key'] !== 'ok'): ?>
                        <a href="sitemap.php"
                            class="inline-flex items-center gap-2 mt-4 px-4 py-2 rounded-lg bg-cstm-primary text-white text-sm font-medium hover:bg-cstm-primary/90 transition">
                            <iconify-icon icon="solar:arrow-right-outline"></iconify-icon>
                            <?= $sitemapHead['key'] === 'none' ? 'Submit a sitemap' : 'Open Sitemap Manager' ?>
                        </a>
                    <?php endif; ?>

                </div>
            </div>
        </div>

    </div>

    <div class="col-span-12 lg:col-span-4 ">
        <!-- Card Container -->
        <div class="card border-0 p-6 mb-4 pb-0">
            <?php
            // 1. Calculate Total Clicks for Chart & Percentage calculations
            $totalClicks = 0;
            foreach ($devicesReport as $row) {
                $totalClicks += (int)($row['clicks'] ?? 0);
            }

            // Helper function to format numbers (e.g. 12400 -> 12.4K)
            if (!function_exists('formatKNumber')) {
                function formatKNumber($num)
                {
                    if ($num >= 1000) {
                        return number_format($num / 1000, 1) . 'K';
                    }
                    return (string)$num;
                }
            }

            // Process devices and extract series/labels/colors for ApexCharts
            $processedDevices = [];
            $chartSeries = [];
            $chartLabels = [];
            $chartColors = [];

            foreach ($devicesReport as $row) {
                $deviceName = trim($row['keys'][0] ?? 'Other');
                $clicks = (int)($row['clicks'] ?? 0);
                $percentage = $totalClicks > 0 ? round(($clicks / $totalClicks) * 100, 1) : 0;

                // Condition-based colors
                $deviceLower = strtolower($deviceName);
                if (str_contains($deviceLower, 'mobile')) {
                    $dotColor = 'bg-primary-600';
                    $hexColor = '#487fff'; // Blue
                } elseif (str_contains($deviceLower, 'desktop')) {
                    $dotColor = 'bg-success-600';
                    $hexColor = '#16a34a'; // Green
                } elseif (str_contains($deviceLower, 'tablet')) {
                    $dotColor = 'bg-cyan-600';
                    $hexColor = '#00b8f2'; // Purple
                } else {
                    $dotColor = 'bg-warning-600';
                    $hexColor = '#ff9f29'; // Fallback
                }

                $processedDevices[] = [
                    'name' => ucfirst($deviceName),
                    'clicks' => $clicks,
                    'percentage' => $percentage,
                    'dotColor' => $dotColor,
                    'hexColor' => $hexColor
                ];

                // ApexCharts Dynamic Data Arrays
                $chartSeries[] = $clicks;
                $chartLabels[] = ucfirst($deviceName);
                $chartColors[] = $hexColor;
            }
            ?>

            <!-- Header Title & Badges -->
            <div class="flex items-center gap-2 mb-4 flex-wrap">
                <h3 class="text-base font-bold text-gray-900 flex items-center gap-1.5">
                    Device Breakdown
                </h3><?= gsc_sample_badge(gsc_is_sample('chart')) ?>
                <iconify-icon icon="lucide:circle-help" class="text-gray-400 hover:text-gray-600 cursor-pointer transition-colors duration-200" width="18" height="18" data-tooltip-target="tooltip-device-breakdown" data-tooltip-placement="top"> </iconify-icon>
                <div id="tooltip-device-breakdown"
                    role="tooltip"
                    class="absolute z-50 invisible inline-block w-64 p-3 text-sm text-gray-600 transition-opacity duration-300 bg-white border border-gray-200 rounded-lg shadow-lg opacity-0 tooltip dark:bg-neutral-800 dark:border-neutral-700 dark:text-gray-300">

                    <p class="text-xs leading-5">
                        Device Breakdown shows how your website's clicks are distributed across desktop, mobile, and tablet devices during the selected period.
                    </p>

                    <div class="tooltip-arrow" data-popper-arrow></div>
                </div>
            </div>

            <!-- Main Content: Donut Chart + Device Legend -->
            <div class="flex flex-col sm:flex-row lg:flex-col 2xl:flex-row items-center justify-center gap-3">

                <!-- Left: Interactive Apex Donut Chart -->
                <div class="flex-1 relative min-w-[150px] max-w-[220px] w-full aspect-[1] flex items-center justify-center flex-shrink-0">
                    <div id="deviceBreakdownDonutChart" class="w-full h-full flex items-center justify-center"></div>
                </div>

                <!-- Right: Device Breakdown List -->
                <div class="flex-1 w-full space-y-4 mb-4">
                    <?php foreach ($processedDevices as $device): ?>
                        <div class="flex items-center justify-between max-w-[220px] mx-auto text-xs gap-2 ">
                            <!-- Dot + Device Name -->
                            <div class="flex items-center gap-2.5">
                                <span class="w-2 h-2 rounded-full <?= $device['dotColor'] ?> inline-block"></span>
                                <span class="font-bold text-gray-700"><?= htmlspecialchars($device['name']) ?></span>
                            </div>

                            <!-- Clicks + Percentage -->
                            <div class="flex items-center gap-1.5 font-semibold">
                                <span class="text-gray-900 font-extrabold"><?= formatKNumber($device['clicks']) ?></span>
                                <span class="text-xs text-gray-400">(<?= $device['percentage'] ?>%)</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>

        <!-- Card Wrapper -->
        <div class="card border-0 p-6">
            <?php
            if (!function_exists('formatCompactNumber')) {
                function formatCompactNumber($num)
                {
                    $num = (float)$num;
                    if ($num >= 1000) {
                        return number_format($num / 1000, 1) . 'K';
                    }
                    return (string)$num;
                }
            }
            ?>
            <div>
                <!-- Card Header: Title + Badges + Tooltip + View All Link -->
                <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
                    <div class="flex items-center gap-2">
                        <h3 class="text-base font-bold text-gray-900 dark:text-neutral-100 flex items-center gap-1.5">
                            Top Pages
                        </h3>
                        <?= gsc_sample_badge(gsc_is_sample('chart')) ?>

                        <!-- Tooltip Trigger Icon -->
                        <iconify-icon icon="lucide:circle-help" class="text-gray-400 hover:text-gray-600 cursor-pointer transition-colors duration-200" width="18" height="18" data-tooltip-target="tooltip-top-queries" data-tooltip-placement="top"> </iconify-icon>

                        <div id="tooltip-top-queries"
                            role="tooltip"
                            class="absolute z-50 invisible inline-block w-64 p-3 text-sm text-gray-600 transition-opacity duration-300 bg-white border border-gray-200 rounded-lg shadow-lg opacity-0 tooltip dark:bg-neutral-800 dark:border-neutral-700 dark:text-gray-300">

                            <p class="text-xs leading-5">
                                Device Breakdown shows how your website's clicks are distributed across desktop, mobile, and tablet devices during the selected period.
                            </p>

                            <div class="tooltip-arrow" data-popper-arrow></div>
                        </div>
                    </div>

                    <!-- Header Action Link -->
                    <a href="gsc-report.php?tab=qp" class="text-xs font-bold text-blue-600 hover:text-blue-700 transition">
                        View all
                    </a>
                </div>
                <!-- Clean Data Table -->
                <div class="overflow-x-auto">
                    <table class="table w-full text-left border-collapse">
                        <!-- Table Header -->
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-neutral-700/60 text-xs font-semibold text-gray-400">
                                <th class="pb-3 pr-2 font-normal">Query</th>
                                <th class="pb-3 px-2 font-normal">Page</th>
                                <th class="pb-3 px-2 text-right font-normal">Clicks</th>
                                <th class="pb-3 pl-2 text-right font-normal">Impressions</th>
                            </tr>
                        </thead>

                        <!-- Table Rows -->
                        <tbody class="divide-y divide-gray-50 dark:divide-neutral-700/40 text-xs">
                            <?php foreach (array_slice($queryByPage, 0, 6) as $row): ?>
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-neutral-800/40 transition-colors">

                                    <!-- Query Column -->
                                    <td class="py-3 pr-2 font-semibold text-gray-800 dark:text-neutral-200 max-w-[140px] truncate">
                                        <?= htmlspecialchars($row['keys'][0]) ?>
                                    </td>

                                    <!-- Page Link Column -->
                                    <td class="py-3 px-2 max-w-[160px] truncate">
                                        <a href="<?= htmlspecialchars($row['keys'][1]) ?>" target="_blank" class="text-blue-600 hover:underline">
                                            <?= htmlspecialchars($row['keys'][1]) ?>
                                        </a>
                                    </td>

                                    <!-- Clicks Column -->
                                    <td class="py-3 px-2 text-right font-bold text-gray-800 dark:text-neutral-200">
                                        <?= formatCompactNumber($row['clicks'] ?? 0) ?>
                                    </td>

                                    <!-- Impressions Column -->
                                    <td class="py-3 pl-2 text-right font-semibold text-gray-400">
                                        <?= formatCompactNumber($row['impressions'] ?? 0) ?>
                                    </td>

                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($canShowData && $hasPremiumAccess): ?>
    <?php if (is_file(__DIR__ . '/partials/reports/rich_results_summary.php')): ?>
        <?php include __DIR__ . '/partials/reports/rich_results_summary.php'; ?>
    <?php endif; ?>
<?php endif; ?>

<?php if ($showTrialPopup && $trialExpiresOn && 3 == 4): ?>
    <!-- Free Trial upgrade POPUP (24hr trial) -->
    <div id="trialUpgradePopup"
        class="fixed inset-0 bg-black/40 items-center justify-center bg-cstm-black-40 backdrop-blur-sm shadow-lg z-50 flex hidden">
        <div
            class="bg-white relative w-full max-w-2xl rounded-2xl border border-sky-200 dark:border-sky-800 bg-gradient-to-br from-sky-50 to-sky-100 dark:from-sky-900/40 dark:to-sky-800/30 dark:bg-neutral-800 p-6 shadow-xl">
            <button type="button" id="closeTrialPopup"
                class="absolute top-3 right-2 w-10 h-10 flex items-center justify-center rounded-full text-sky-600 dark:text-sky-400 hover:bg-sky-200/50 dark:hover:bg-sky-800/50"
                aria-label="Close">✕</button>
            <!-- <div class="flex items-center gap-3 mb-4">
                <div>
                    <h3 class="text-xl font-bold text-sky-900 dark:text-white">24-Hour Preview Access</h3>
                </div>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-500 text-white uppercase">Trial</span>
            </div> -->
            <div class="flex items-center justify-center gap-3 mb-5">
                <h3 class="text-xl font-bold text-sky-900 dark:text-white">
                    24-Hour Preview Access
                </h3>
                <span class="inline-flex items-center px-3 py-1
               rounded-full text-xs font-semibold
               bg-success-600 text-white uppercase">
                    Trial
                </span>
            </div>

            <p
                class="text-base text-sky-900 dark:text-sky-300 mb-4 text-center mb-4 max-w-max rounded-[24px] px-6 py-3 bg-success-100 dark:bg-neutral-600 mx-auto">
                Your Google Search Console is connected and your sitemap is live</p>

            <div class="flex justify-center items-center gap-4 mb-2">
                <div id="trialCountdownHours"
                    class="text-[40px] font-semibold text-sky-700 dark:text-white text-cstm-primary">
                    --
                </div>

                <span class="text-3xl font-semibold text-sky-400">:</span>

                <div id="trialCountdownMinutes"
                    class="text-[40px] font-semibold text-sky-700 dark:text-white text-cstm-primary ">
                    --
                </div>
            </div>

            <p
                class="text-center text-sm font-semibold text-neutral-600 dark:text-neutral-400 uppercase tracking-wider mb-4">
                remaining
            </p>

            <p class="text-sm text-sky-900 dark:text-sky-300 mb-4 text-center">
                You can explore SEO insights data and app features for the next <strong>24 hours.</strong><br> <strong>Start
                    a free 7-day trial</strong> Now to keep your insights active and continue using the app.</p>
            <div class="flex justify-center items-center">
                <a href="pricing.php?upgrade=1" class="btn bg-success-600 text-white">Start 7 day free trial</a>
            </div>
        </div>
    </div>
    <!-- <script>
        (function() {
            var expiresAt = <?= json_encode($trialExpiresOn ? preg_replace('/\s/', 'T', $trialExpiresOn) . 'Z' : null) ?>;
            var popup = document.getElementById('trialUpgradePopup');
            if (!popup || !expiresAt) return;

            function updateCountdown() {
                var elH = document.getElementById('trialCountdownHours');
                var elM = document.getElementById('trialCountdownMinutes');
                if (!elH || !elM) return;
                var now = new Date();
                var end = new Date(expiresAt);
                if (end <= now) {
                    elH.textContent = '0';
                    elM.textContent = 'Expired';
                    return;
                }
                var ms = end - now;
                var hours = Math.floor(ms / (1000 * 60 * 60));
                var mins = Math.floor((ms % (1000 * 60 * 60)) / (1000 * 60));
                elH.textContent = hours + 'H';
                elM.textContent = (mins < 10 ? '0' : '') + mins + ' MIN';
            }
            updateCountdown();
            setInterval(updateCountdown, 60000);

            function closeTrialPopup() {
                popup.classList.add('hidden');
                popup.classList.remove('flex');
            }
            document.getElementById('closeTrialPopup').addEventListener('click', closeTrialPopup);
            document.getElementById('trialPopupLater')?.addEventListener('click', closeTrialPopup);
            popup.addEventListener('click', function(e) {
                if (e.target === popup) closeTrialPopup();
            });
        })();
    </script> -->
<?php endif; ?>


<div class="card h-full rounded-lg border-0 overflow-x-auto relative">
    <div class="card-body">

        <div class="border-b border-gray-200 flex justify-between">
            <nav class="flex overflow-x-auto gap-4 text-sm font-medium ">
                <button class="tab-btn flex items-center gap-2 text-xs shrink-0 py-2.5 active-tab"
                    data-tab="tab-2">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                    </svg>
                    Growth Engine
                </button>
                <button class="tab-btn flex items-center gap-2 text-xs shrink-0 py-2.5" data-tab="tab-5">

                    <span data-tooltip-target="tooltip-information-query" class="primary_tooltip_btn  ml-1">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z" />
                        </svg>
                    </span>
                    <div id="tooltip-information-query" role="tooltip"
                        class="tooltip invisible absolute z-20 rounded-lg bg-gray-100 text-gray-900 px-3 py-2 text-sm shadow max-w-xs">
                        Lead Generator/Business
                    </div>
                    Lead Generator/Business
                </button>
                <button class="tab-btn flex items-center gap-2 text-xs shrink-0 py-2.5" data-tab="tab-1">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M7 7h6l6 6-6 6-6-6V7z" />
                        <circle cx="9.5" cy="9.5" r="1" fill="currentColor" />
                    </svg>

                    Brand & Non-Brand keywords
                </button>
                <button class="tab-btn flex items-center gap-2 text-xs shrink-0 py-2.5" data-tab="tab-3">

                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>


                    Top Pages</button>
                <button class="tab-btn flex items-center gap-2 text-xs shrink-0 py-2.5" data-tab="tab-4">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 17v-3m3 3v-5m3 5v-7" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M7 3h8l4 4v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z" />
                    </svg>

                    Pages Report
                </button>
                <button class="tab-btn flex items-center gap-2 text-xs shrink-0 py-2.5" data-tab="tab-overview">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Overview
                </button>

                <button class="tab-btn flex items-center gap-2 text-xs shrink-0 py-2.5" data-tab="tab-countries">
                    <!-- Globe Icon -->
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 2a10 10 0 100 20 10 10 0 000-20z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M2 12h20M12 2a15 15 0 010 20M12 2a15 15 0 000 20" />
                    </svg>
                    Countries
                </button>

                <button class="tab-btn flex items-center gap-2 text-xs shrink-0 py-2.5" data-tab="tab-devices">
                    <!-- Devices Icon -->
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9.75 17L9 21h6l-.75-4M4 3h16a2 2 0 012 2v9a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2z" />
                    </svg>
                    Devices
                </button>
                <button class="tab-btn flex items-center gap-2 text-xs shrink-0 py-2.5" data-tab="tab-all-data">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                    All Data
                </button>
            </nav>
            <!-- <div class="mobile_d_none ">
                <a href="gsc-report.php?tab=keywords" class="h-10 btn btn-cstm-primary flex items-center gap-2 px-3 mobile_d_none">
                    <iconify-icon icon="mdi:poll"></iconify-icon>


                    <span>Analytics report</span>
                </a>
            </div> -->
        </div>
        <div id="tab-loader" class="hidden flex items-center justify-center py-16">
            <div class="animate-spin rounded-full h-10 w-10 border-4 border-blue-500 border-t-transparent"></div>
        </div>
        <div id="tab-1" class="tab-content hidden">
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 mt-5">
                <div class="xl:col-span-6">
                    <div class="card border border-gray-200 rounded-lg h-full">
                        <div class="card-body">
                            <h3 class="text-lg font-semibold flex items-center gap-1 flex items-center gap-1">Brand
                                keywords
                                <span data-tooltip-target="tooltip-Brand-keywords" class="primary_tooltip_btn  ml-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z" />
                                    </svg>
                                </span>
                                <div id="tooltip-Brand-keywords" role="tooltip"
                                    class="tooltip invisible absolute z-20 rounded-lg bg-gray-100 text-gray-900 px-3 py-2 text-sm shadow max-w-xs">
                                    brand keywords
                                </div>
                                <?= gsc_sample_badge(gsc_is_sample('brand')) ?>
                            </h3>
                            <p class="text-sm text-gray-500 mb-2">Search queries that include your company or brand
                                name. These indicate users already aware of your brand.</p>
                            <?= gsc_sample_note(gsc_is_sample('brand')) ?>

                            <?php if (!$hasPremiumAccess): ?>
                                <div class="flex flex-col items-center justify-center text-center
                                                bg-gray-50 dark:bg-neutral-700 rounded-lg
                                                border border-dashed border-gray-300 dark:border-neutral-600 p-6">

                                    <div
                                        class="w-12 h-12 bg-neutral-200 dark:bg-neutral-700 rounded-full flex items-center justify-center mb-3 mx-auto">
                                        <iconify-icon icon="solar:lock-keyhole-bold-duotone"
                                            class="text-2xl text-neutral-400"></iconify-icon>
                                    </div>

                                    <h6 class="text-xl font-medium text-gray-900 dark:text-white">
                                        Brand Keywords Locked
                                    </h6>

                                    <p class="text-sm text-gray-500 mt-1 mb-4">
                                        Upgrade your plan to view branded search queries.
                                    </p>

                                    <button type="button"
                                        class="open-upgrade-modal btn btn-cstm-primary flex items-center justify-center gap-1">
                                        <iconify-icon icon="mdi:crown-outline" class="text-lg"></iconify-icon>
                                        Upgrade Plan
                                    </button>
                                </div>

                            <?php elseif (empty($brandSplit['brand_keywords'])): ?>
                                <p class="text-sm text-gray-500 text-center py-6">
                                    No branded keywords found
                                </p>
                            <?php else: ?>

                                <div class="overflow-x-auto">
                                    <table class="table table-sm w-full table-auto">
                                        <thead>
                                            <tr>
                                                <th>Keyword</th>
                                                <th class="text-center">Impressions</th>
                                                <th class="text-center">Clicks</th>
                                                <th class="text-center">Position</th>
                                            </tr>
                                        </thead>
                                        <?php if ($hasPremiumAccess): ?>
                                            <tbody>
                                                <?php foreach (array_slice($brandSplit['brand_keywords'], 0, 6) as $row): ?>
                                                    <tr>
                                                        <td class="max-w-[150px] truncate">
                                                            <?= htmlspecialchars($row['keys'][0]) ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <?= (int)$row['impressions'] ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <?= (int)$row['clicks'] ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <?= number_format($row['position'], 1) ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            <?php endif; ?>
                            <div class="text-sm text-gray-500 mt-3 flex justify-between flex-wrap gap-1">
                                Showing top 6 results ·
                                <a href="gsc-report.php?tab=keywords" class="text-blue-600 hover:underline">
                                    View full report →
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="xl:col-span-6">
                    <!-- NON-BRAND KEYWORDS -->
                    <div class="card border border-gray-200 rounded-lg h-full">
                        <div class="card-body">
                            <h3 class="text-lg font-semibold flex items-center gap-1">Non-brand keywords <span
                                    data-tooltip-target="tooltip-Non-Brand-keywords" class="primary_tooltip_btn  ml-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z" />
                                    </svg>
                                </span>
                                <div id="tooltip-Non-Brand-keywords" role="tooltip"
                                    class="tooltip invisible absolute z-20 rounded-lg bg-gray-100 text-gray-900 px-3 py-2 text-sm shadow max-w-xs">
                                    Non-brand keywords
                                </div>
                                <?= gsc_sample_badge(gsc_is_sample('non_brand')) ?>
                            </h3>
                            <?= gsc_sample_note(gsc_is_sample('non_brand')) ?>
                            <p class="text-sm text-gray-500 mb-2">Search queries that do not contain your brand name.
                                These represent new audience discovery and SEO growth opportunities.</p>

                            <?php if (!$hasPremiumAccess): ?>
                                <div class="flex flex-col items-center justify-center text-center
                                        bg-gray-50 dark:bg-neutral-700 rounded-lg
                                        border border-dashed border-gray-300 dark:border-neutral-600 p-6">

                                    <div
                                        class="w-12 h-12 bg-neutral-200 dark:bg-neutral-700 rounded-full flex items-center justify-center mb-3 mx-auto">
                                        <iconify-icon icon="solar:lock-keyhole-bold-duotone"
                                            class="text-2xl text-neutral-400"></iconify-icon>
                                    </div>

                                    <h6 class="text-xl font-medium text-gray-900 dark:text-white">
                                        Non-Brand Keywords Locked
                                    </h6>

                                    <p class="text-sm text-gray-500 mt-1 mb-4">
                                        Upgrade your plan to view non-brand search queries.
                                    </p>

                                    <button type="button"
                                        class="open-upgrade-modal btn btn-cstm-primary flex items-center justify-center gap-1">
                                        <iconify-icon icon="mdi:crown-outline" class="text-lg"></iconify-icon>
                                        Upgrade Plan
                                    </button>
                                </div>

                            <?php elseif (empty($brandSplit['non_brand_keywords'])): ?>
                                <p class="text-sm text-gray-500 text-center py-6">
                                    No non-brand keywords found
                                </p>
                            <?php else: ?>

                                <div class="overflow-x-auto">
                                    <table class="table table-sm w-full table-auto">
                                        <thead>
                                            <tr>
                                                <th>Keyword</th>
                                                <th class="text-center">Impressions</th>
                                                <th class="text-center">Clicks</th>
                                                <th class="text-center">Position</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($brandSplit['non_brand_keywords'], 0, 6) as $row): ?>
                                                <tr>
                                                    <td class="max-w-[150px] truncate">
                                                        <?= htmlspecialchars($row['keys'][0]) ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?= (int)$row['impressions'] ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?= (int)$row['clicks'] ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?= number_format($row['position'], 1) ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <div class="text-sm text-gray-500 mt-3 flex justify-between flex-wrap gap-1">
                                Showing top 6 results ·
                                <a href="gsc-report.php?tab=keywords" class="text-blue-600 hover:underline">
                                    View full report →
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div id="tab-3" class="tab-content hidden">
            <div class="grid grid-cols-1 gap-6 mt-5">

                <!-- <?php if ($hasPremiumAccess && !empty($queryByPage)): ?> -->

                <!-- PREMIUM CONTENT -->
                <div class="card h-full border border-gray-200 dark:border-neutral-600">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold flex items-center gap-1">Top Page <span
                                data-tooltip-target="tooltip-Query-Page" class="primary_tooltip_btn  ml-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z" />
                                </svg>
                            </span>
                            <div id="tooltip-Query-Page" role="tooltip"
                                class="tooltip invisible absolute z-20 rounded-lg bg-gray-100 text-gray-900 px-3 py-2 text-sm shadow max-w-xs">
                                Top Page
                            </div>
                            <?= gsc_sample_badge(gsc_is_sample('query_by_page')) ?>
                        </h3>
                        <?= gsc_sample_note(gsc_is_sample('query_by_page')) ?>
                        <p class="text-sm text-gray-500 mb-2">Pages on your website that receive the most traffic and
                            impressions from Google Search.</p>
                        <div class="overflow-x-auto">
                            <table class="table table-sm w-full table-auto">
                                <thead>
                                    <tr>
                                        <th>Query</th>
                                        <th>Page</th>
                                        <th class="text-right">Clicks</th>
                                        <th class="text-right">Impr.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($queryByPage, 0, 6) as $row): ?>
                                        <tr>
                                            <td class="truncate max-w-[150px]">
                                                <?= htmlspecialchars($row['keys'][0]) ?>
                                            </td>
                                            <td class="">
                                                <a href="<?= htmlspecialchars($row['keys'][1]) ?>" target="_blank"
                                                    class="text-blue-600 hover:underline truncate max-w-[290px]">
                                                    <?= htmlspecialchars($row['keys'][1]) ?>
                                                </a>
                                            </td>
                                            <td class="text-right">
                                                <?= (int)$row['clicks'] ?>
                                            </td>
                                            <td class="text-right">
                                                <?= (int)$row['impressions'] ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-sm text-gray-500 mt-3 flex justify-between flex-wrap gap-1">
                            Showing top 6 results ·
                            <a href="gsc-report.php?tab=qp" class="text-blue-600 hover:underline">
                                View full report →
                            </a>
                        </div>
                    </div>
                </div>

                <!-- <?php else: ?>

                    <div class="card border border-dashed border-gray-300">
                        <div class="card-body text-center py-10 dark:bg-neutral-700">
                            <div class="w-12 h-12 bg-neutral-200 dark:bg-neutral-700 rounded-full flex items-center justify-center mb-3 mx-auto">
                                <iconify-icon icon="solar:lock-keyhole-bold-duotone" class="text-2xl text-neutral-400"></iconify-icon>
                            </div>
                            <h3 class="text-xl font-semibold mb-2">
                                Query by Page is a Premium Feature
                            </h3>
                            <p class="text-gray-500 mb-4">
                                Upgrade to unlock query-level page insights and detailed performance data.
                            </p>
                            <a href="pricing.php?upgrade=1"
                                class="open-upgrade-modal btn btn-cstm-primary">
                                Upgrade to Premium
                            </a>
                        </div>
                    </div>

                <?php endif; ?> -->

            </div>
        </div>

        <div id="tab-4" class="tab-content hidden">
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 mt-5">

                <div class="xl:col-span-8">
                    <div
                        class="card h-full flex flex-col dark:bg-neutral-800 border border-gray-200 dark:border-neutral-600 relative overflow-hidden">
                        <div class="card-body p-6">

                            <div class="flex justify-between items-center">
                                <h3 class="text-lg font-semibold">Top Pages Report <span
                                        data-tooltip-target="tooltip-Top-Page-report" class="primary_tooltip_btn  ml-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z" />
                                        </svg>
                                    </span>
                                    <div id="tooltip-Top-Page-report" role="tooltip"
                                        class="tooltip invisible absolute z-20 rounded-lg bg-gray-100 text-gray-900 px-3 py-2 text-sm shadow max-w-xs">
                                        Top Pages Report
                                    </div>
                                    <?= gsc_sample_badge(gsc_is_sample('pages')) ?>
                                </h3>
                                <span class="text-sm text-gray-500">
                                    <?= min(6, count($pagesReportAll ?? $pagesReport)) ?> Rows
                                </span>
                            </div>
                            <p class="text-sm text-gray-500 mb-2">Detailed performance metrics for each page on your
                                website from Google Search.</p>

                            <div class="overflow-x-auto">
                                <table class="table table-sm w-full table-auto">
                                    <thead>
                                        <tr>
                                            <th class="truncate max-w-[150px]">Page URL</th>
                                            <th class="text-right">Clicks</th>
                                            <th class="text-right">Impr.</th>
                                            <th class="text-right">CTR</th>
                                            <th class="text-right">Pos</th>
                                        </tr>
                                    </thead>
                                    <tbody>

                                        <?php if (!$hasPremiumAccess): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-10 text-gray-500">

                                                    <div class="flex flex-col items-center justify-center gap-2">
                                                        <div
                                                            class="w-12 h-12 bg-neutral-200 dark:bg-neutral-700 rounded-full flex items-center justify-center mb-3 mx-auto">
                                                            <iconify-icon icon="solar:lock-keyhole-bold-duotone"
                                                                class="text-2xl text-neutral-400"></iconify-icon>
                                                        </div>

                                                        <div class="text-xl font-medium text-gray-900 dark:text-white">
                                                            Top Pages Locked
                                                        </div>

                                                        <div class="text-sm text-gray-500 mb-3">
                                                            Upgrade your plan to view top-performing pages.
                                                        </div>

                                                        <!-- ✅ UPGRADE BUTTON -->
                                                        <button type="button"
                                                            class="open-upgrade-modal btn btn-cstm-primary flex items-center justify-center gap-1">
                                                            <iconify-icon icon="mdi:crown-outline"
                                                                class="text-lg"></iconify-icon>
                                                            Upgrade Plan
                                                        </button>

                                                    </div>

                                                </td>
                                            </tr>


                                        <?php elseif (empty($pagesReport)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-gray-500 py-6">
                                                    No data available
                                                </td>
                                            </tr>

                                        <?php else: ?>

                                            <?php foreach ($pagesReport as $row): ?>
                                                <tr>
                                                    <td class="max-w-[150px] truncate">
                                                        <a href="<?= htmlspecialchars($row['keys'][0]) ?>" target="_blank"
                                                            class="text-blue-600 hover:underline truncate max-w-[394px]">
                                                            <?= htmlspecialchars($row['keys'][0]) ?>
                                                        </a>
                                                    </td>
                                                    <td class="text-right">
                                                        <?= (int)$row['clicks'] ?>
                                                    </td>
                                                    <td class="text-right">
                                                        <?= (int)$row['impressions'] ?>
                                                    </td>
                                                    <td class="text-right">
                                                        <?= number_format($row['ctr'], 2) ?>%
                                                    </td>
                                                    <td class="text-right">
                                                        <?= number_format($row['position'], 1) ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-sm text-gray-500 mt-4">
                                Showing 1 to
                                <?= count($pagesReport) ?> of
                                <?= count($pagesReportAll ?? $pagesReport) ?> entries
                                <a href="gsc-report.php?tab=keywords" class="text-blue-600 hover:underline">
                                    View full report →
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="xl:col-span-4">
                    <div class="card h-full dark:bg-neutral-800 border border-gray-200 dark:border-neutral-600">
                        <div class="card-body p-6">

                            <!-- Header -->
                            <div class="flex items-center justify-between mb-6">
                                <h6 class="font-bold text-lg text-neutral-900 dark:text-white flex items-center gap-2">
                                    <iconify-icon icon="fluent:data-histogram-20-filled"
                                        class="text-xl text-primary-600"></iconify-icon>
                                    Analysis <span data-tooltip-target="tooltip-Analysis"
                                        class="primary_tooltip_btn  ml-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z" />
                                        </svg>
                                    </span>
                                    <div id="tooltip-Analysis" role="tooltip"
                                        class="tooltip invisible absolute z-20 rounded-lg bg-gray-100 text-gray-900 px-3 py-2 text-sm shadow max-w-xs">
                                        Analysis
                                    </div>
                                </h6>

                                <?php if (!$hasPremiumAccess): ?>
                                    <span class="text-xs text-primary-600 font-medium flex items-center gap-1">
                                        <iconify-icon icon="mdi:lock-outline"></iconify-icon>
                                        Locked
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- KPI LIST -->
                            <div class="space-y-3">

                                <!-- Total Clicks -->
                                <div class="flex items-center justify-between
                                                p-4 rounded-xl border border-gray-200 dark:border-neutral-700
                                                bg-white dark:bg-neutral-900">

                                    <div>
                                        <p class="text-sm text-gray-500">Total Clicks</p>
                                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                                            <?= $hasPremiumAccess ? number_format($kpi['clicks']) : '—' ?>
                                        </h3>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <iconify-icon icon="mdi:cursor-default-click-outline"
                                            class="text-xl text-blue-500"></iconify-icon>

                                        <?php if (!$hasPremiumAccess): ?>
                                            <iconify-icon icon="mdi:lock-outline"
                                                class="text-lg text-primary-600"></iconify-icon>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Total Impressions -->
                                <div class="flex items-center justify-between
                                                p-4 rounded-xl border border-gray-200 dark:border-neutral-700
                                                bg-white dark:bg-neutral-900">

                                    <div>
                                        <p class="text-sm text-gray-500">Total Impressions</p>
                                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                                            <?= $hasPremiumAccess ? number_format($kpi['impressions']) : '—' ?>
                                        </h3>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <iconify-icon icon="mdi:eye-outline"
                                            class="text-xl text-green-500"></iconify-icon>

                                        <?php if (!$hasPremiumAccess): ?>
                                            <iconify-icon icon="mdi:lock-outline"
                                                class="text-lg text-primary-600"></iconify-icon>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Avg CTR -->
                                <div class="flex items-center justify-between
                                                p-4 rounded-xl border border-gray-200 dark:border-neutral-700
                                                bg-white dark:bg-neutral-900">

                                    <div>
                                        <p class="text-sm text-gray-500">Avg CTR</p>
                                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                                            <?= $hasPremiumAccess ? $kpi['ctr'] . '%' : '—' ?>
                                        </h3>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <iconify-icon icon="mdi:percent-outline"
                                            class="text-xl text-purple-500"></iconify-icon>

                                        <?php if (!$hasPremiumAccess): ?>
                                            <iconify-icon icon="mdi:lock-outline"
                                                class="text-lg text-primary-600"></iconify-icon>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Avg Position -->
                                <div class="flex items-center justify-between
                                                p-4 rounded-xl border border-gray-200 dark:border-neutral-700
                                                bg-white dark:bg-neutral-900">

                                    <div>
                                        <p class="text-sm text-gray-500">Avg Position</p>
                                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                                            <?= $hasPremiumAccess ? $kpi['position'] : '—' ?>
                                        </h3>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <iconify-icon icon="mdi:trophy-outline"
                                            class="text-xl text-primary-600"></iconify-icon>

                                        <?php if (!$hasPremiumAccess): ?>
                                            <iconify-icon icon="mdi:lock-outline"
                                                class="text-lg text-primary-600"></iconify-icon>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- CTA -->
                            <?php if (!$hasPremiumAccess): ?>
                                <div class="mt-6">
                                    <button type="button"
                                        class="open-upgrade-modal btn btn-cstm-primary flex items-center justify-center gap-1">
                                        <iconify-icon icon="mdi:crown-outline" class="text-lg"></iconify-icon>
                                        Upgrade Plan
                                    </button>
                                </div>
                            <?php endif; ?>

                            <p class="text-xs text-gray-400 italic text-center mt-4">
                                Data based on selected range (Default: 30 days).
                            </p>

                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="tab-5" class="tab-content hidden">
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 mt-5">



                <!-- LEFT: INTENT TABLES -->
                <?php foreach (['informational', 'commercial', 'transactional', 'navigational'] as $intent): ?>
                    <div class="xl:col-span-6">
                        <div class="card h-full border border-gray-200 dark:border-neutral-600">
                            <div class="card-body p-6">

                                <h3 class="text-lg font-semibold flex items-center gap-1 capitalize flex-wrap mb-1">
                                    <?= ucfirst($intent) ?> Query Information <span
                                        data-tooltip-target="tooltip-<?= ucfirst($intent) ?>"
                                        class="primary_tooltip_btn  ml-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z" />
                                        </svg>
                                    </span>
                                    <div id="tooltip-<?= ucfirst($intent) ?>" role="tooltip"
                                        class="tooltip invisible absolute z-20 rounded-lg bg-gray-100 text-gray-900 px-3 py-2 text-sm shadow max-w-xs">
                                        <?= ucfirst($intent) ?> Query Information
                                    </div>
                                    <?= gsc_sample_badge(gsc_is_sample('intent_' . $intent)) ?>
                                </h3>
                                <p class="text-sm text-gray-500 mb-3">
                                    <?php
                                    $intentDescriptions = [
                                        'informational' => 'Users searching for information, answers, or educational content.',
                                        'commercial' => 'Users comparing products, prices, or researching before buying.',
                                        'transactional' => 'High-intent searches where users are ready to purchase or take action.',
                                        'navigational' => 'Users trying to reach a specific brand, website, or page.'
                                    ];
                                    echo $intentDescriptions[$intent] ?? '';
                                    ?>
                                </p>
                                <?php if (!$hasPremiumAccess): ?>
                                    <div class="flex flex-col items-center justify-center text-center
                                                bg-gray-50 dark:bg-neutral-700 rounded-lg
                                                border border-dashed border-gray-300 dark:border-neutral-600 p-6">
                                        <div
                                            class="w-12 h-12 bg-neutral-200 dark:bg-neutral-700 rounded-full flex items-center justify-center mb-3">
                                            <iconify-icon icon="solar:lock-keyhole-bold-duotone"
                                                class="text-2xl text-neutral-400"></iconify-icon>
                                        </div>
                                        <h4 class="text-xl font-medium">Query Information Locked</h4>
                                        <p class="text-sm text-gray-500 mt-2 mb-4">
                                            Upgrade your plan to unlock information-based insights.
                                        </p>
                                        <button
                                            class="open-upgrade-modal btn btn-cstm-primary flex items-center justify-center gap-1">
                                            <iconify-icon icon="mdi:crown-outline" class="text-lg"></iconify-icon>
                                            Upgrade Plan
                                        </button>
                                    </div>

                                <?php else: ?>
                                    <div class="overflow-x-auto ">
                                        <table class="table table-sm w-full table-auto">
                                            <thead>
                                                <tr>
                                                    <th>Query</th>
                                                    <th class="text-right">Clicks</th>
                                                    <th class="text-right">Impr.</th>
                                                    <th class="text-right">Pos</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($intentData[$intent])): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center text-gray-500 py-4">
                                                            google search console
                                                            <?= $intent ?> requires upto 48 hours to collect your data and display it here
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach (array_slice($intentData[$intent], 0, 5) as $row): ?>
                                                        <tr>
                                                            <td class="truncate max-w-[150px]">
                                                                <?= htmlspecialchars($row['keys'][0]) ?>
                                                            </td>
                                                            <td class="text-right">
                                                                <?= (int)$row['clicks'] ?>
                                                            </td>
                                                            <td class="text-right">
                                                                <?= (int)$row['impressions'] ?>
                                                            </td>
                                                            <td class="text-right">
                                                                <?= number_format($row['position'], 1) ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                <div class="text-sm text-gray-500 mt-3 flex justify-between flex-wrap gap-1">
                                    Showing top 6 results ·
                                    <a href="gsc-report.php?tab=keywords" class="text-blue-600 hover:underline">
                                        View full report →
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>


            </div>
        </div>
        <div id="tab-overview" class="tab-content hidden">

            <div class="card border border-gray-200 rounded-lg">
                <div class="card-body p-6">

                    <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">Top Queries Overview
                        <?= gsc_sample_badge(gsc_is_sample('overview')) ?>
                    </h3>
                    <?= gsc_sample_note(gsc_is_sample('overview')) ?>
                    <div class="overflow-x-auto">
                        <table class="table table-sm w-full">

                            <thead>
                                <tr>
                                    <th>Query</th>
                                    <th class="text-right">Clicks</th>
                                    <th class="text-right">Impressions</th>
                                    <th class="text-right">Position</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach (array_slice($overviewReport, 0, 5) as $row): ?>

                                    <tr>

                                        <td class="truncate max-w-[200px]">
                                            <?= htmlspecialchars($row['keys'][0]) ?>
                                        </td>

                                        <td class="text-right">
                                            <?= (int)$row['clicks'] ?>
                                        </td>

                                        <td class="text-right">
                                            <?= (int)$row['impressions'] ?>
                                        </td>

                                        <td class="text-right">
                                            <?= number_format($row['position'], 1) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>
                    <div class="text-sm text-gray-500 mt-3 flex justify-between flex-wrap gap-1">
                        Showing top 6 results ·
                        <a href="gsc-report.php?tab=qp" class="text-blue-600 hover:underline">
                            View full report →
                        </a>
                    </div>
                </div>
            </div>

        </div>
        <div id="tab-countries" class="tab-content hidden">

            <div class="card border border-gray-200 rounded-lg">
                <div class="card-body p-6">

                    <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">Traffic by Country
                        <?= gsc_sample_badge(gsc_is_sample('countries')) ?>
                    </h3>
                    <?= gsc_sample_note(gsc_is_sample('countries')) ?>

                    <div class="overflow-x-auto">
                        <table class="table table-sm w-full">

                            <thead>
                                <tr>
                                    <th>Country</th>
                                    <th class="text-right">Clicks</th>
                                    <th class="text-right">Impressions</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach (array_slice($countriesReport, 0, 5) as $row): ?>

                                    <tr>

                                        <?php
                                        $code = strtoupper($row['keys'][0]);
                                        $countryName = class_exists('Locale') ? Locale::getDisplayRegion('-' . $code, 'en') : '';
                                        ?>
                                        <td>
                                            <?= htmlspecialchars($countryName ?: $code) ?>
                                        </td>
                                        <td class="text-right">
                                            <?= (int)$row['clicks'] ?>
                                        </td>
                                        <td class="text-right">
                                            <?= (int)$row['impressions'] ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>
                    <div class="text-sm text-gray-500 mt-3 flex justify-between flex-wrap gap-1">
                        Showing top 6 results ·
                        <a href="gsc-report.php?tab=keywords" class="text-blue-600 hover:underline">
                            View full report →
                        </a>
                    </div>

                </div>
            </div>

        </div>
        <div id="tab-devices" class="tab-content hidden">

            <div class="card border border-gray-200 rounded-lg">
                <div class="card-body p-6">

                    <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">Traffic by Device
                        <?= gsc_sample_badge(gsc_is_sample('devices')) ?>
                    </h3>
                    <?= gsc_sample_note(gsc_is_sample('devices')) ?>

                    <div class="overflow-x-auto">
                        <table class="table table-sm w-full">

                            <thead>
                                <tr>
                                    <th>Device</th>
                                    <th class="text-right">Clicks</th>
                                    <th class="text-right">Impressions</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($devicesReport as $row): ?>

                                    <tr>

                                        <td>
                                            <?= htmlspecialchars($row['keys'][0]) ?>
                                        </td>

                                        <td class="text-right">
                                            <?= (int)$row['clicks'] ?>
                                        </td>

                                        <td class="text-right">
                                            <?= (int)$row['impressions'] ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>
                    <div class="text-sm text-gray-500 mt-3 flex justify-between flex-wrap gap-1">
                        Showing top 6 results ·
                        <a href="gsc-report.php?tab=qp" class="text-blue-600 hover:underline">
                            View full report →
                        </a>
                    </div>
                </div>
            </div>

        </div>

        <div id="tab-all-data" class="tab-content hidden">
            <?php if ($canShowData && $hasPremiumAccess): ?>

                <!-- SITE PROFILE STRIP -->
                <?php if (!empty($siteProfile)): ?>
                    <div class="card border border-gray-200 dark:border-neutral-600 dark:bg-neutral-800 rounded-lg mt-5">
                        <div class="card-body p-4 flex flex-wrap items-center gap-4">
                            <?php if (!empty($siteProfile['logo_url'])): ?>
                                <img src="<?= htmlspecialchars((string)$siteProfile['logo_url']) ?>" alt="" class="w-12 h-12 rounded-md object-contain bg-gray-50 border border-gray-200">
                            <?php endif; ?>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-neutral-900 dark:text-white truncate">
                                    <?= htmlspecialchars((string)($siteProfile['company_name'] ?: $siteProfile['business_name'] ?: 'Your Site')) ?>
                                </p>
                                <p class="text-sm text-neutral-500 truncate">
                                    <?php
                                    $bits = array_filter([
                                        $siteProfile['category_name'] ?? null,
                                        $siteProfile['business_type'] ?? null,
                                        $siteProfile['language'] ?? null,
                                        $siteProfile['country'] ?? null,
                                    ]);
                                    echo htmlspecialchars(implode(' · ', $bits));
                                    ?>
                                </p>
                            </div>
                            <?php if ($verifiedSiteUrl): ?>
                                <a href="<?= htmlspecialchars((string)$verifiedSiteUrl) ?>" target="_blank" rel="noopener" class="text-sm text-blue-600 hover:underline whitespace-nowrap">
                                    <?= htmlspecialchars(parse_url((string)$verifiedSiteUrl, PHP_URL_HOST) ?: $verifiedSiteUrl) ?> ↗
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- INDEXING HEALTH -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-5">
                    <div class="card border border-gray-200 rounded-lg">
                        <div class="card-body p-5">
                            <h3 class="text-lg font-semibold mb-3">Sitemap Indexing (Google)</h3>
                            <?php if (empty($sitemapStatus)): ?>
                                <p class="text-sm text-neutral-500">No sitemap data from Google yet. Submit a sitemap from <a href="sitemap.php" class="text-blue-600 hover:underline">Sitemap Management</a>.</p>
                            <?php else: ?>
                                <?php
                                $totalSubmitted = array_sum(array_column($sitemapStatus, 'submitted'));
                                $totalIndexed   = array_sum(array_column($sitemapStatus, 'indexed'));
                                $coverage       = $totalSubmitted > 0 ? round(($totalIndexed / $totalSubmitted) * 100, 1) : 0;
                                ?>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                                    <div class="flex items-center gap-4 bg-warning-50 dark:bg-warning-800/50 dark:border-neutral-700 rounded-xl p-4 transition-all hover:shadow-sm">
                                        <div class="flex-shrink-0 flex items-center justify-center h-8 w-8 bg-warning-400 dark:bg-primary-900/30 text-white dark:text-primary-400 rounded-lg">
                                            <iconify-icon icon="solar:document-add-outline" class="text-lg"></iconify-icon>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-medium tracking-wider text-warning-600">Submitted</span>
                                            <h3 class="text-2xl font-bold text-neutral-900 dark:text-white leading-tight">
                                                <?= number_format($totalSubmitted) ?>
                                            </h3>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-4 bg-success-100 dark:bg-success-900/10 dark:border-success-900/20 rounded-xl p-4 transition-all hover:shadow-sm">
                                        <div class="flex-shrink-0 flex items-center justify-center h-8 w-8 bg-success-400 text-white rounded-lg shadow-sm shadow-success-200 dark:shadow-none">
                                            <iconify-icon icon="solar:check-circle-outline" class="text-lg"></iconify-icon>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-medium tracking-wider text-success-600 dark:text-success-500">Indexed</span>
                                            <h3 class="text-2xl font-bold text-neutral-900 dark:text-white leading-tight">
                                                <?= number_format($totalIndexed) ?>
                                            </h3>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-4 bg-purple-100 dark:bg-blue-900/10  dark:borderpurplee-900/20 rounded-xl p-4 transition-all hover:shadow-sm">
                                        <div class="flex-shrink-0 flex items-center justify-center h-8 w-8 bg-primary-400 text-white rounded-lg shadow-sm shadow-blue-200 dark:shadow-none">
                                            <iconify-icon icon="solar:pie-chart-2-outline" class="text-lg"></iconify-icon>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-medium tracking-wider text-primary-600 dark:text-primary-500">Coverage</span>
                                            <h3 class="text-2xl font-bold text-neutral-900 dark:text-white leading-tight">
                                                <?= $coverage ?>%
                                            </h3>
                                        </div>
                                    </div>
                                </div>
                                <div class="table-responsive overflow-x-auto">
                                    <table class="table bordered-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Sitemap</th>
                                                <th class="text-right">Submitted</th>
                                                <th class="text-right">Indexed</th>
                                                <th class="text-right">Errors</th>
                                                <th class="text-right">Warnings</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($sitemapStatus, 0, 5) as $s): ?>
                                                <tr>
                                                    <td class="truncate max-w-[260px]">
                                                        <a href="<?= htmlspecialchars((string)$s['path']) ?>" target="_blank" rel="noopener" class="text-blue-600 hover:underline">
                                                            <?= htmlspecialchars((string)$s['path']) ?>
                                                        </a>
                                                    </td>
                                                    <td class="text-right"><?= number_format((int)$s['submitted']) ?></td>
                                                    <td class="text-right"><?= number_format((int)$s['indexed']) ?></td>
                                                    <td class="text-right <?= $s['errors'] ? 'text-danger-600 font-medium' : '' ?>"><?= (int)$s['errors'] ?></td>
                                                    <td class="text-right <?= $s['warnings'] ? 'text-warning-600 font-medium' : '' ?>"><?= (int)$s['warnings'] ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card border border-gray-200 rounded-lg">
                        <div class="card-body p-5">
                            <h3 class="text-lg font-semibold mb-3">Submission Activity (30 days)</h3>
                            <?php
                            $total30  = (int)$submissionStats30d['total'];
                            $ok30     = (int)$submissionStats30d['success'];
                            $err30    = (int)$submissionStats30d['errors'];
                            $rate30   = $total30 > 0 ? round(($ok30 / $total30) * 100, 1) : 0;
                            ?>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <!-- Total -->
                                <div class="flex items-center gap-4 bg-primary-50 dark:bg-primary-900/10 rounded-xl p-4 transition-all hover:shadow-sm">

                                    <div class="flex-shrink-0 flex items-center justify-center h-8 w-8 bg-primary-400 text-white rounded-lg shadow-sm shadow-primary-200 dark:shadow-none">
                                        <iconify-icon icon="solar:layers-outline" class="text-2xl"></iconify-icon>
                                    </div>

                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium tracking-wider text-primary-600 dark:text-primary-400">
                                            Total
                                        </span>

                                        <h3 class="text-2xl font-bold text-neutral-900 dark:text-white leading-tight">
                                            <?= number_format($total30) ?>
                                        </h3>
                                    </div>

                                </div>

                                <!-- Success -->
                                <div class="flex items-center gap-4 bg-success-50 dark:bg-success-900/10 rounded-xl p-4 transition-all hover:shadow-sm">

                                    <div class="flex-shrink-0 flex items-center justify-center h-8 w-8 bg-success-400 text-white rounded-lg shadow-sm shadow-success-200 dark:shadow-none">
                                        <iconify-icon icon="solar:check-circle-outline" class="text-2xl"></iconify-icon>
                                    </div>

                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium tracking-wider text-success-600 dark:text-success-500">
                                            Success
                                        </span>

                                        <h3 class="text-2xl font-bold text-neutral-900 dark:text-white leading-tight">
                                            <?= number_format($ok30) ?>
                                        </h3>
                                    </div>

                                </div>

                                <!-- Errors -->
                                <div class="flex items-center gap-4 bg-danger-100 dark:bg-danger-900/10 rounded-xl p-4 transition-all hover:shadow-sm">

                                    <div class="flex-shrink-0 flex items-center justify-center h-8 w-8 bg-danger-400 text-white rounded-lg shadow-sm shadow-danger-200 dark:shadow-none">
                                        <iconify-icon icon="solar:danger-triangle-outline" class="text-lg"></iconify-icon>
                                    </div>

                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium tracking-wider text-danger-600 dark:text-danger-500">
                                            Errors
                                        </span>

                                        <h3 class="text-2xl font-bold <?= $err30 ? 'text-danger-600 dark:text-danger-500' : 'text-neutral-400' ?> leading-tight">
                                            <?= number_format($err30) ?>
                                        </h3>
                                    </div>

                                </div>

                            </div>
                            <div class="mt-5 bg-success-50 dark:bg-success-900/10 rounded-2xl p-4 border border-success-100 dark:border-success-900/20">

                                <!-- Header -->
                                <div class="flex items-center justify-between mb-3">
                                    <div class="flex items-center gap-2">
                                        <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-success-600 text-white shadow-sm shadow-success-200">
                                            <iconify-icon icon="solar:chart-outline" class="text-lg"></iconify-icon>
                                        </div>
                                        <div>
                                            <p class="text-base tracking-wider font-medium text-success-600 dark:text-success-500">
                                                Success rate
                                            </p>
                                        </div>
                                    </div>
                                    <h4 class="text-2xl font-black text-success-600 dark:text-success-500">
                                        <?= $rate30 ?>%
                                    </h4>
                                </div>

                                <!-- Progress Bar -->
                                <div class="relative w-full h-3 bg-success-100 dark:bg-neutral-700 rounded-full overflow-hidden">
                                    <!-- Glow -->
                                    <div class="absolute inset-y-0 left-0 bg-success-300 blur-md opacity-40 rounded-full"
                                        style="width: <?= max(0, min(100, $rate30)) ?>%">
                                    </div>
                                    <!-- Fill -->
                                    <div class="relative h-full bg-gradient-to-r from-success-500 to-success-400 rounded-full transition-all duration-500"
                                        style="width: <?= max(0, min(100, $rate30)) ?>%">
                                    </div>
                                </div>

                                <?php if (!empty($submissionStats30d['last_at'])): ?>
                                    <p class="text-sm text-neutral-500 mt-3">Last submission: <?= htmlspecialchars((string)$submissionStats30d['last_at']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- KPI SUMMARY -->
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mt-5">

                    <!-- Total Clicks -->
                    <div class="relative overflow-hidden rounded-lg border border-gray-200 dark:border-primary-900/20 bg-white dark:bg-neutral-800 p-5 transition-all duration-300 group">

                        <div class="absolute -top-3 -right-3 w-20 h-20 bg-primary-100 dark:bg-primary-900/20 rounded-full blur-3xl opacity-70 group-hover:scale-125 transition-all duration-500"></div>
                        <div class="relative flex items-start justify-between">

                            <div>
                                <p class="text-sm tracking-wider font-semibold text-primary-600 dark:text-primary-400">
                                    Total Clicks
                                </p>

                                <h4 class="text-3xl font-black text-neutral-900 dark:text-white mt-3 leading-none">
                                    <?= number_format((int)$kpi['clicks']) ?>
                                </h4>
                            </div>

                            <div class="absolute -top-3 -right-3 flex items-center justify-center w-12 h-12 text-primary-600 dark:shadow-none">
                                <iconify-icon icon="solar:cursor-outline" class="text-lg"></iconify-icon>
                            </div>
                        </div>
                    </div>

                    <!-- Total Impressions -->
                    <div class="relative overflow-hidden rounded-lg border border-gray-200 dark:border-warning-900/20 bg-white dark:bg-neutral-800 p-5 transition-all duration-300 group">

                        <div class="absolute -top-3 -right-3 w-20 h-20 bg-warning-100 dark:bg-warning-900/20 rounded-full blur-3xl opacity-70 group-hover:scale-125 transition-all duration-500"></div>

                        <div class="relative flex items-start justify-between">

                            <div>
                                <p class="text-sm tracking-wider font-semibold text-warning-600 dark:text-warning-400">
                                    Total Impressions
                                </p>

                                <h4 class="text-3xl font-black text-neutral-900 dark:text-white mt-3 leading-none">
                                    <?= number_format((int)$kpi['impressions']) ?>
                                </h4>
                            </div>

                            <div class="absolute -top-3 -right-3 flex items-center justify-center w-12 h-12 text-warning-600 dark:shadow-none">
                                <iconify-icon icon="solar:eye-outline" class="text-lg"></iconify-icon>
                            </div>

                        </div>

                    </div>

                    <!-- Avg CTR -->
                    <div class="relative overflow-hidden rounded-lg border border-gray-200 dark:border-success-900/20 bg-white dark:bg-neutral-800 p-5 transition-all duration-300 group">

                        <div class="absolute -top-3 -right-3 w-20 h-20 bg-success-100 dark:bg-success-900/20 rounded-full blur-3xl opacity-70 group-hover:scale-125 transition-all duration-500"></div>

                        <div class="relative flex items-start justify-between">

                            <div>
                                <p class="text-sm tracking-wider font-semibold text-success-600 dark:text-success-400">
                                    Avg CTR
                                </p>

                                <h4 class="text-3xl font-black text-neutral-900 dark:text-white mt-3 leading-none">
                                    <?= number_format((float)$kpi['ctr'], 2) ?>%
                                </h4>
                            </div>

                            <div class="absolute -top-3 -right-3 flex items-center justify-center w-12 h-12 text-success-600 dark:shadow-none">
                                <iconify-icon icon="solar:chart-outline" class="text-lg"></iconify-icon>
                            </div>

                        </div>

                    </div>

                    <!-- Avg Position -->
                    <div class="relative overflow-hidden rounded-lg border border-gray-200 dark:border-info-900/20 bg-white dark:bg-neutral-800 p-5 transition-all duration-300 group">

                        <div class="absolute -top-3 -right-3 w-20 h-20 bg-info-100 dark:bg-info-900/20 rounded-full blur-3xl opacity-70 group-hover:scale-125 transition-all duration-500"></div>

                        <div class="relative flex items-start justify-between">

                            <div>
                                <p class="text-sm tracking-wider font-semibold text-info-600 dark:text-info-400">
                                    Avg Position
                                </p>

                                <h4 class="text-3xl font-black text-neutral-900 dark:text-white mt-3 leading-none">
                                    <?= number_format((float)$kpi['position'], 1) ?>
                                </h4>
                            </div>

                            <div class="absolute -top-3 -right-3 flex items-center justify-center w-12 h-12  text-info-600 dark:shadow-none">
                                <iconify-icon icon="solar:ranking-outline" class="text-lg"></iconify-icon>
                            </div>

                        </div>

                    </div>

                </div>

                <!-- BRAND vs NON-BRAND SPLIT -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-5">
                    <div class="card border border-gray-200 rounded-lg">
                        <div class="card-body p-5">
                            <h3 class="text-lg font-semibold mb-3">Branded Search</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

                                <!-- Clicks -->
                                <div class="flex items-center gap-4 bg-success-50 dark:bg-success-900/10 rounded-xl p-4 transition-all hover:shadow-sm">
                                    <div class="flex-shrink-0 flex items-center justify-center h-8 w-8 bg-success-400 text-white rounded-lg shadow-sm shadow-success-200 dark:shadow-none">
                                        <iconify-icon icon="solar:cursor-outline" class="text-lg"></iconify-icon>
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium tracking-wider text-success-600 dark:text-success-500">
                                            Clicks
                                        </span>

                                        <h5 class="text-2xl font-bold text-neutral-900 dark:text-white leading-tight">
                                            <?= number_format((int)$brandSplit['branded_clicks']) ?>
                                        </h5>
                                    </div>
                                </div>

                                <!-- Impressions -->
                                <div class="flex items-center gap-4 bg-primary-50 dark:bg-primary-900/10 rounded-xl p-4 transition-all hover:shadow-sm">
                                    <div class="flex-shrink-0 flex items-center justify-center h-8 w-8 bg-primary-400 text-white rounded-lg shadow-sm shadow-primary-200 dark:shadow-none">
                                        <iconify-icon icon="solar:eye-outline" class="text-lg"></iconify-icon>
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium tracking-wider text-primary-600 dark:text-primary-400">
                                            Impressions
                                        </span>

                                        <h5 class="text-2xl font-bold text-neutral-900 dark:text-white leading-tight">
                                            <?= number_format((int)$brandSplit['branded_impr']) ?>
                                        </h5>
                                    </div>
                                </div>

                                <!-- Keywords -->
                                <div class="flex items-center gap-4 bg-warning-50 dark:bg-warning-900/10 rounded-xl p-4 transition-all hover:shadow-sm">
                                    <div class="flex-shrink-0 flex items-center justify-center h-8 w-8 bg-warning-400 text-white rounded-lg shadow-sm shadow-warning-200 dark:shadow-none">
                                        <iconify-icon icon="solar:hashtag-outline" class="text-lg"></iconify-icon>
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium tracking-wider text-warning-600 dark:text-warning-500">
                                            Keywords
                                        </span>
                                        <h5 class="text-2xl font-bold text-neutral-900 dark:text-white leading-tight">
                                            <?= number_format(count($brandSplit['brand_keywords'])) ?>
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card border border-gray-200 rounded-lg">
                        <div class="card-body p-5">
                            <h3 class="text-lg font-semibold mb-3">Non-Branded Search</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

                                <!-- Clicks -->
                                <div class="flex items-center gap-4 bg-success-50 dark:bg-success-900/10 rounded-xl p-4 transition-all hover:shadow-sm">
                                    <div class="flex-shrink-0 flex items-center justify-center h-8 w-8 bg-success-400 text-white rounded-lg shadow-sm shadow-info-200 dark:shadow-none">
                                        <iconify-icon icon="solar:cursor-outline" class="text-lg"></iconify-icon>
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium tracking-wider text-success-600 dark:text-success-500">
                                            Clicks
                                        </span>
                                        <h5 class="text-2xl font-bold text-neutral-900 dark:text-white leading-tight">
                                            <?= number_format((int)$brandSplit['non_branded_clicks']) ?>
                                        </h5>
                                    </div>
                                </div>

                                <!-- Impressions -->
                                <div class="flex items-center gap-4 bg-primary-50 dark:bg-primary-900/10 rounded-xl p-4 transition-all hover:shadow-sm">
                                    <div class="flex-shrink-0 flex items-center justify-center h-8 w-8 bg-primary-400 text-white rounded-lg shadow-sm shadow-primary-200 dark:shadow-none">
                                        <iconify-icon icon="solar:eye-outline" class="text-lg"></iconify-icon>
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium tracking-wider text-primary-600 dark:text-primary-400">
                                            Impressions
                                        </span>

                                        <h5 class="text-2xl font-bold text-neutral-900 dark:text-white leading-tight">
                                            <?= number_format((int)$brandSplit['non_branded_impr']) ?>
                                        </h5>
                                    </div>
                                </div>

                                <!-- Keywords -->
                                <div class="flex items-center gap-4 bg-warning-50 dark:bg-warning-900/10 rounded-xl p-4 transition-all hover:shadow-sm">
                                    <div class="flex-shrink-0 flex items-center justify-center h-8 w-8 bg-warning-400 text-white rounded-lg shadow-sm shadow-warning-200 dark:shadow-none">
                                        <iconify-icon icon="solar:hashtag-outline" class="text-lg"></iconify-icon>
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-sm font-medium tracking-wider text-warning-600 dark:text-warning-500">
                                            Keywords
                                        </span>
                                        <h5 class="text-2xl font-bold text-neutral-900 dark:text-white leading-tight">
                                            <?= number_format(count($brandSplit['non_brand_keywords'])) ?>
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- POSITION BANDS + STRIKING DISTANCE -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-5">
                    <div class="card border border-gray-200 rounded-lg">
                        <div class="card-body p-5">
                            <h3 class="text-lg font-semibold mb-4">Ranking Distribution (Position Bands)</h3>
                            <?php if (empty($positionBands)): ?>
                                <p class="text-sm text-neutral-500">No data.</p>
                            <?php else: ?>
                                <?php
                                $maxBandImpr = 1;
                                foreach ($positionBands as $b) {
                                    $maxBandImpr = max($maxBandImpr, (int)($b['impressions'] ?? 0));
                                }
                                $bandColors = [
                                    '1-2'   => 'bg-success-500',
                                    '3-5'   => 'bg-success-400',
                                    '6-10'  => 'bg-info-500',
                                    '11-20' => 'bg-warning-500',
                                    '21-50' => 'bg-warning-400',
                                    '51+'   => 'bg-danger-500',
                                ];
                                ?>
                                <div class="space-y-3 max-h-[350px] overflow-y-auto cstm-scroll-sm">
                                    <?php foreach ($positionBands as $b): ?>
                                        <?php
                                        $key  = (string)($b['band'] ?? '');
                                        $impr = (int)($b['impressions'] ?? 0);
                                        $clks = (int)($b['clicks'] ?? 0);
                                        $rows = (int)($b['rows'] ?? 0);
                                        $pct  = $maxBandImpr > 0 ? max(2, round(($impr / $maxBandImpr) * 100)) : 2;
                                        $color = $bandColors[$key] ?? 'bg-neutral-400';
                                        ?>
                                        <div class="relative overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-4 shadow-sm hover:shadow-md transition-all duration-300">

                                            <!-- Header -->
                                            <div class="flex items-start justify-between gap-3 mb-2">
                                                <div class="flex items-center gap-3">
                                                    <!-- Title -->
                                                    <div>
                                                        <h5 class="text-base font-semibold text-neutral-900 dark:text-white leading-tight">
                                                            Position <?= htmlspecialchars($key) ?>
                                                        </h5>
                                                    </div>

                                                </div>

                                                <!-- Badge -->
                                                <div class="px-3 py-1 rounded-full bg-neutral-100 dark:bg-neutral-700 text-xs font-semibold text-neutral-600 dark:text-neutral-300 whitespace-nowrap">
                                                    <?= number_format($rows) ?> kw
                                                </div>

                                            </div>

                                            <!-- Stats -->
                                            <div class="flex items-center gap-4 mb-2 text-sm">

                                                <div class="flex items-center gap-1 text-neutral-500">
                                                    <iconify-icon icon="solar:eye-outline" class="text-base"></iconify-icon>
                                                    <span><?= number_format($impr) ?> impr</span>
                                                </div>

                                                <div class="flex items-center gap-1 text-neutral-500">
                                                    <iconify-icon icon="solar:cursor-outline" class="text-base"></iconify-icon>
                                                    <span><?= number_format($clks) ?> clicks</span>
                                                </div>

                                            </div>

                                            <!-- Progress -->
                                            <div class="relative">
                                                <!-- Background -->
                                                <div class="w-full h-2 bg-neutral-100 dark:bg-neutral-700 rounded-full overflow-hidden">

                                                    <!-- Glow -->
                                                    <div class="h-2 absolute inset-y-0 left-0 blur-md opacity-30 rounded-full <?= $color ?>"
                                                        style="width: <?= $pct ?>%">
                                                    </div>

                                                    <!-- Fill -->
                                                    <div class="relative h-full rounded-full transition-all duration-500 <?= $color ?>"
                                                        style="width: <?= $pct ?>%">
                                                    </div>

                                                </div>

                                                <!-- Percentage -->
                                                <div class="flex justify-end absolute -top-5 right-0">
                                                    <span class="text-xs font-bold text-neutral-500">
                                                        <?= number_format($pct, 1) ?>%
                                                    </span>
                                                </div>

                                            </div>

                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card border border-gray-200 rounded-lg">
                        <div class="card-body p-5">
                            <h3 class="text-lg font-semibold flex items-center justify-between gap-2 mb-4">
                                Striking Distance (Positions 11–40)
                                <span class="text-xs font-normal bg-info-100 text-info-700 px-2 py-1 leading-normal font-semibold rounded-full">High SEO ROI</span>
                            </h3>
                            <div class="table-responsive overflow-x-auto max-h-[350px] overflow-y-auto cstm-scroll-sm">
                                <table class="table bordered-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Keyword</th>
                                            <th class="text-right">Pos.</th>
                                            <th class="text-right">Impr.</th>
                                            <th class="text-right">Clicks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($strikingDistance)): ?>
                                            <tr>
                                                <td colspan="4" class="text-center py-4 text-neutral-500">No keywords in striking distance</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach (array_slice($strikingDistance, 0, 10) as $row): ?>
                                                <tr>
                                                    <td class="truncate max-w-[220px]"><?= htmlspecialchars((string)($row['keys'][0] ?? '')) ?></td>
                                                    <td class="text-right"><?= number_format((float)($row['position'] ?? 0), 1) ?></td>
                                                    <td class="text-right"><?= (int)($row['impressions'] ?? 0) ?></td>
                                                    <td class="text-right"><?= (int)($row['clicks'] ?? 0) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-sm text-neutral-500 mt-2">Keywords ranking 11–40 — push these to page 1 first.</p>
                        </div>
                    </div>
                </div>

                <!-- INTENT + E-COMMERCE INTENT -->
                <?php
                $renderIntentCard = function (string $title, array $buckets) {
                    $totalRows = 0;
                    foreach ($buckets as $rows) {
                        $totalRows += count($rows);
                    }
                ?>
                    <div class="card border border-gray-200 rounded-lg">
                        <div class="card-body p-5">
                            <h3 class="text-lg font-semibold mb-4"><?= htmlspecialchars($title) ?></h3>
                            <?php if ($totalRows === 0): ?>
                                <p class="text-sm text-neutral-500">No data.</p>
                            <?php else: ?>
                                <?php foreach ($buckets as $name => $rows):
                                    $count = count($rows);
                                    $pct   = $totalRows > 0 ? round(($count / $totalRows) * 100) : 0;
                                    $clks  = 0;
                                    $impr = 0;
                                    foreach ($rows as $r) {
                                        $clks += (int)($r['clicks'] ?? 0);
                                        $impr += (int)($r['impressions'] ?? 0);
                                    }
                                ?>
                                    <div class="relative overflow-hidden rounded-xl bg-gray-50 p-4 hover:shadow-md transition-all duration-300 mb-4">
                                        <div class="relative flex items-start justify-between gap-3 mb-2">

                                            <!-- Left -->
                                            <div class="flex items-center gap-3">
                                                <div>
                                                    <h5 class="text-base font-medium text-neutral-900 dark:text-white leading-tight capitalize">
                                                        <?= htmlspecialchars(str_replace('_', ' ', $name)) ?>
                                                    </h5>
                                                </div>

                                            </div>

                                            <!-- Badge -->
                                            <div class="px-3 py-1 rounded-full bg-primary-400 dark:bg-neutral-700 text-xs font-semibold text-white dark:text-neutral-300 whitespace-nowrap">
                                                <?= number_format($count) ?> kw
                                            </div>

                                        </div>

                                        <!-- Stats -->
                                        <div class="relative flex items-center gap-5 mb-2 text-sm">

                                            <div class="flex items-center gap-1 text-neutral-500">
                                                <iconify-icon icon="solar:eye-outline" class="text-base"></iconify-icon>
                                                <span><?= number_format($impr) ?> impr</span>
                                            </div>

                                            <div class="flex items-center gap-1 text-neutral-500">
                                                <iconify-icon icon="solar:cursor-outline" class="text-base"></iconify-icon>
                                                <span><?= number_format($clks) ?> clicks</span>
                                            </div>

                                        </div>

                                        <!-- Progress -->
                                        <div class="relative">
                                            <!-- Track -->
                                            <div class="w-full h-2 bg-primary-100 dark:bg-neutral-700 rounded-full overflow-hidden">
                                                <!-- Glow -->
                                                <div class="absolute h-2 inset-y-0 left-0 bg-cstm-primary blur-md opacity-30 rounded-full"
                                                    style="width: <?= max(2, $pct) ?>%">
                                                </div>

                                                <!-- Fill -->
                                                <div class="relative h-full bg-cstm-primary rounded-full transition-all duration-500"
                                                    style="width: <?= max(2, $pct) ?>%">
                                                </div>

                                            </div>

                                            <!-- Percentage -->
                                            <div class="flex justify-end absolute -top-5 right-0">
                                                <span class="text-xs font-bold text-neutral-500">
                                                    <?= number_format($pct, 1) ?>%
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php
                };
                ?>
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-5">
                    <?php $renderIntentCard('Search Intent', $intentData ?? []); ?>
                    <?php $renderIntentCard('E-commerce Intent', $ecommerceIntentData ?? []); ?>
                </div>

                <!-- TOP QUERIES + TOP PAGES -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-5">
                    <div class="card border border-gray-200 rounded-lg">
                        <div class="card-body p-5">
                            <h3 class="text-lg font-semibold mb-4">Top Queries</h3>
                            <div class="table-responsive overflow-x-auto">
                                <table class="table bordered-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Query</th>
                                            <th class="text-right">Clicks</th>
                                            <th class="text-right">Impr.</th>
                                            <th class="text-right">CTR</th>
                                            <th class="text-right">Pos.</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($overviewReport)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-neutral-500">No data</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach (array_slice($overviewReport, 0, 10) as $row): ?>
                                                <tr>
                                                    <td class="truncate max-w-[220px]"><?= htmlspecialchars((string)($row['keys'][0] ?? '')) ?></td>
                                                    <td class="text-right"><?= (int)($row['clicks'] ?? 0) ?></td>
                                                    <td class="text-right"><?= (int)($row['impressions'] ?? 0) ?></td>
                                                    <td class="text-right"><?= number_format((float)($row['ctr'] ?? 0), 2) ?>%</td>
                                                    <td class="text-right"><?= number_format((float)($row['position'] ?? 0), 1) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-sm text-gray-500 mt-3 flex justify-between flex-wrap gap-1">
                                Top 10 ·
                                <a href="gsc-report.php?tab=keywords" class="text-blue-600 hover:underline">View full report →</a>
                            </div>
                        </div>
                    </div>
                    <div class="card border border-gray-200 rounded-lg">
                        <div class="card-body p-5">
                            <h3 class="text-lg font-semibold mb-4">Top Pages</h3>
                            <div class="table-responsive overflow-x-auto">
                                <table class="table bordered-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Page</th>
                                            <th class="text-right">Clicks</th>
                                            <th class="text-right">Impr.</th>
                                            <th class="text-right">CTR</th>
                                            <th class="text-right">Pos.</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $pagesAll = $pagesReportAll ?? $pagesReport; ?>
                                        <?php if (empty($pagesAll)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-neutral-500">No data</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach (array_slice($pagesAll, 0, 10) as $row): ?>
                                                <?php $pageUrl = (string)($row['keys'][0] ?? ''); ?>
                                                <tr>
                                                    <td class="truncate max-w-[220px]">
                                                        <a href="<?= htmlspecialchars($pageUrl) ?>" target="_blank" rel="noopener" class="text-blue-600 hover:underline">
                                                            <?= htmlspecialchars($pageUrl) ?>
                                                        </a>
                                                    </td>
                                                    <td class="text-right"><?= (int)($row['clicks'] ?? 0) ?></td>
                                                    <td class="text-right"><?= (int)($row['impressions'] ?? 0) ?></td>
                                                    <td class="text-right"><?= number_format((float)($row['ctr'] ?? 0), 2) ?>%</td>
                                                    <td class="text-right"><?= number_format((float)($row['position'] ?? 0), 1) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-sm text-gray-500 mt-3 flex justify-between flex-wrap gap-1">
                                Top 10 ·
                                <a href="gsc-report.php?tab=pages" class="text-blue-600 hover:underline">View full report →</a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SEARCH APPEARANCE -->
                <div class="card border border-gray-200 rounded-lg mt-5">
                    <div class="card-body p-5">
                        <h3 class="text-lg font-semibold mb-4">Search Appearance (Rich Result Types)</h3>
                        <?php if (empty($searchAppearanceRows)): ?>
                            <p class="text-sm text-neutral-500">No rich-result data in the last 30 days. Your pages may not have structured data triggering rich results.</p>
                        <?php else: ?>
                            <div class="table-responsive overflow-x-auto">
                                <table class="table bordered-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Appearance Type</th>
                                            <th class="text-right">Clicks</th>
                                            <th class="text-right">Impressions</th>
                                            <th class="text-right">CTR</th>
                                            <th class="text-right">Avg Position</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($searchAppearanceRows, 0, 10) as $row): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string)($row['key'] ?? ($row['keys'][0] ?? ''))) ?></td>
                                                <td class="text-right"><?= (int)($row['clicks'] ?? 0) ?></td>
                                                <td class="text-right"><?= (int)($row['impressions'] ?? 0) ?></td>
                                                <td class="text-right"><?= number_format((float)($row['ctr'] ?? 0), 2) ?>%</td>
                                                <td class="text-right"><?= number_format((float)($row['position'] ?? 0), 1) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-sm text-gray-500 mt-3">
                                <a href="rich-results-report.php" class="text-blue-600 hover:underline">Open rich results report →</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- IMAGE SEARCH + DISCOVER (lazy loaded) -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-5">
                    <div class="card border border-gray-200 rounded-lg">
                        <div class="card-body p-5">
                            <h3 class="text-lg font-semibold mb-3 flex items-center gap-2">
                                <iconify-icon icon="solar:gallery-bold-duotone" class="text-info-600 text-2xl"></iconify-icon>
                                Google Images Traffic (30 days)
                            </h3>
                            <div id="all-data-image-loader" class="text-sm text-neutral-500 py-4">Loading…</div>
                            <div id="all-data-image-content" class="hidden">

                                <!-- Stats Cards -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
                                    <!-- Clicks -->
                                    <div class="relative overflow-hidden rounded-xl  dark:border-primary-900/20 bg-primary-50 dark:bg-primary-900/10 p-4  hover:shadow-md transition-all duration-300">
                                        <div class="relative flex items-center gap-4">

                                            <!-- Icon -->
                                            <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-primary-600 text-white shadow-sm shadow-primary-200 dark:shadow-none">
                                                <iconify-icon icon="solar:cursor-outline" class="text-2xl"></iconify-icon>
                                            </div>

                                            <!-- Content -->
                                            <div>
                                                <p class="text-sm tracking-wider font-medium text-primary-600 dark:text-primary-400">
                                                    Clicks
                                                </p>

                                                <p class="text-3xl font-semibold text-neutral-900 dark:text-white leading-none mt-1"
                                                    id="all-data-image-clicks">
                                                    0
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Impressions -->
                                    <div class="relative overflow-hidden rounded-xl dark:border-info-900/20 bg-info-50 dark:bg-info-900/10 p-4 shadow-sm hover:shadow-md transition-all duration-300">
                                        <!-- Glow -->
                                        <div class="absolute -top-8 -right-8 w-24 h-24 bg-info-200 dark:bg-info-800/30 rounded-full blur-3xl opacity-60"></div>
                                        <div class="relative flex items-center gap-4">

                                            <!-- Icon -->
                                            <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-info-600 text-white shadow-sm shadow-info-200 dark:shadow-none">
                                                <iconify-icon icon="solar:eye-outline" class="text-2xl"></iconify-icon>
                                            </div>

                                            <!-- Content -->
                                            <div>
                                                <p class="text-sm tracking-wider font-medium text-info-600 dark:text-info-400">
                                                    Impressions
                                                </p>

                                                <p class="text-3xl font-semibold text-neutral-900 dark:text-white leading-none mt-1"
                                                    id="all-data-image-impr">
                                                    0
                                                </p>
                                            </div>

                                        </div>

                                    </div>

                                </div>

                                <!-- Query List -->
                                <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 p-4">

                                    <!-- Header -->
                                    <div class="flex items-center gap-3 mb-4">

                                        <div class="flex items-center justify-center w-11 h-11 rounded-xl bg-warning-100 dark:bg-warning-900/20 text-warning-600 dark:text-warning-400">
                                            <iconify-icon icon="solar:gallery-outline" class="text-xl"></iconify-icon>
                                        </div>

                                        <div>
                                            <p class="text-xs uppercase tracking-widest font-semibold text-warning-600 dark:text-warning-400">
                                                Insights
                                            </p>

                                            <h5 class="text-lg font-bold text-neutral-900 dark:text-white leading-tight">
                                                Top image-search queries
                                            </h5>
                                        </div>

                                    </div>
                                    <!-- List -->
                                    <ul id="all-data-image-list" class="space-y-2 text-sm"></ul>
                                </div>
                            </div>
                            <div id="all-data-image-empty" class="hidden text-sm text-neutral-500 py-2">
                                No image-search traffic in the last 30 days.
                            </div>
                        </div>
                    </div>
                    <div class="card border border-gray-200 rounded-lg">
                        <div class="card-body p-5">
                            <h3 class="text-lg font-semibold mb-3 flex items-center gap-2">
                                <iconify-icon icon="solar:compass-bold" class="text-success-600"></iconify-icon>
                                Google Discover Traffic (30 days)
                            </h3>
                            <div id="all-data-discover-loader" class="text-sm text-neutral-500 py-4">Loading…</div>
                            <div id="all-data-discover-content" class="hidden">
                                <div class="grid grid-cols-2 gap-3 mb-3">
                                    <div>
                                        <p class="text-xs text-neutral-500">Clicks</p>
                                        <p class="text-2xl font-bold" id="all-data-discover-clicks">0</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-neutral-500">Impressions</p>
                                        <p class="text-2xl font-bold" id="all-data-discover-impr">0</p>
                                    </div>
                                </div>
                                <p class="text-xs text-neutral-500">Top pages in Discover:</p>
                                <ul id="all-data-discover-list" class="text-sm mt-1 space-y-1"></ul>
                            </div>
                            <div id="all-data-discover-empty" class="hidden text-sm text-neutral-500 py-2">
                                No Discover traffic in the last 30 days. Discover is mobile-only feed-style traffic.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- COUNTRIES + DEVICES -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-5">
                    <div class="card border border-gray-200 rounded-lg">
                        <div class="card-body p-5">
                            <h3 class="text-lg font-semibold mb-4">Countries</h3>
                            <div class="table-responsive overflow-x-auto">
                                <table class="table bordered-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Country</th>
                                            <th class="text-right">Clicks</th>
                                            <th class="text-right">Impr.</th>
                                            <th class="text-right">CTR</th>
                                            <th class="text-right">Pos.</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($countriesReport)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-neutral-500">No data</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach (array_slice($countriesReport, 0, 10) as $row): ?>
                                                <?php
                                                $code = strtoupper((string)($row['keys'][0] ?? ''));
                                                $countryName = class_exists('Locale') ? Locale::getDisplayRegion('-' . $code, 'en') : '';
                                                ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($countryName ?: $code) ?></td>
                                                    <td class="text-right"><?= (int)($row['clicks'] ?? 0) ?></td>
                                                    <td class="text-right"><?= (int)($row['impressions'] ?? 0) ?></td>
                                                    <td class="text-right"><?= number_format((float)($row['ctr'] ?? 0), 2) ?>%</td>
                                                    <td class="text-right"><?= number_format((float)($row['position'] ?? 0), 1) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-sm text-gray-500 mt-3 flex justify-between flex-wrap gap-1">
                                Top 10 ·
                                <a href="gsc-report.php?tab=countries" class="text-blue-600 hover:underline">View full report →</a>
                            </div>
                        </div>
                    </div>
                    <div class="card border border-gray-200 rounded-lg">
                        <div class="card-body p-5">
                            <h3 class="text-lg font-semibold mb-4">Devices</h3>
                            <div class="table-responsive overflow-x-auto">
                                <table class="table bordered-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Device</th>
                                            <th class="text-right">Clicks</th>
                                            <th class="text-right">Impr.</th>
                                            <th class="text-right">CTR</th>
                                            <th class="text-right">Pos.</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($devicesReport)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-neutral-500">No data</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($devicesReport as $row): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars(ucfirst(strtolower((string)($row['keys'][0] ?? '')))) ?></td>
                                                    <td class="text-right"><?= (int)($row['clicks'] ?? 0) ?></td>
                                                    <td class="text-right"><?= (int)($row['impressions'] ?? 0) ?></td>
                                                    <td class="text-right"><?= number_format((float)($row['ctr'] ?? 0), 2) ?>%</td>
                                                    <td class="text-right"><?= number_format((float)($row['position'] ?? 0), 1) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-sm text-gray-500 mt-3">
                                <a href="gsc-report.php?tab=devices" class="text-blue-600 hover:underline">View full report →</a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CANNIBALIZATION -->
                <div class="card border border-gray-200 rounded-lg mt-5">
                    <div class="card-body p-5">
                        <h3 class="text-lg font-semibold flex items-center gap-2 flex-wrap mb-1">
                            Keyword Cannibalization
                            <span class="text-xs font-normal bg-warning-100 text-warning-700 px-2 py-0.5 rounded-full">Multiple pages competing</span>
                        </h3>
                        <p class="text-sm text-neutral-500 mb-4">
                            Queries where 2+ pages on your site compete for the same keyword. Lower gap % means tighter competition — pick one page to optimize and redirect/de-emphasize the others.
                        </p>
                        <div class="table-responsive overflow-x-auto">
                            <table class="table bordered-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Query</th>
                                        <th class="text-start max-w-[394px]" style="text-align: start;">Top Page</th>
                                        <th class="text-right"># Pages</th>
                                        <th class="text-right">Top Impr.</th>
                                        <th class="text-right">Gap %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($cannibalization)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-neutral-500">No cannibalization detected — each query is owned by a single page. 🎉</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach (array_slice($cannibalization, 0, 10) as $c):
                                            $keyword = (string)($c['keyword'] ?? '');
                                            $pages   = $c['pages'] ?? [];
                                            $top     = $pages[0] ?? null;
                                            $gap     = (int)($c['gap'] ?? 0);
                                            $gapColor = $gap < 30 ? 'text-danger-600' : ($gap < 60 ? 'text-warning-600' : 'text-success-600');
                                        ?>
                                            <tr>
                                                <td class="truncate max-w-[220px]"><?= htmlspecialchars($keyword) ?></td>
                                                <td class="truncate w-[394px] text-start">
                                                    <?php if ($top): ?>
                                                        <a class="max-w-[394px] truncate text-start block" href="<?= htmlspecialchars((string)($top['page'] ?? '')) ?>" target="_blank" rel="noopener" class="text-blue-600 hover:underline">
                                                            <?= htmlspecialchars((string)($top['page'] ?? '')) ?>
                                                        </a>
                                                    <?php endif; ?>

                                                </td>
                                                <td class="text-right"><?= count($pages) ?></td>
                                                <td class="text-right"><?= number_format((int)($top['impressions'] ?? 0)) ?></td>
                                                <td class="text-right font-semibold <?= $gapColor ?>"><?= $gap ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-sm text-gray-500 mt-3 flex justify-between flex-wrap gap-1">
                            Top 10 ·
                            <a href="gsc-report.php?tab=qp" class="text-blue-600 hover:underline">View full report →</a>
                        </div>
                    </div>
                </div>

                <!-- RECENTLY INSPECTED URLs -->
                <?php if (!empty($recentInspections)): ?>
                    <div class="card border border-gray-200 rounded-lg mt-5">
                        <div class="card-body p-5">
                            <h3 class="text-lg font-semibold mb-1">Recently Inspected URLs</h3>
                            <p class="text-sm text-neutral-500 mb-4">Last 10 URLs you inspected with Google's URL Inspection API (cached 24h).</p>
                            <div class="overflow-x-auto">
                                <table class="table table-sm w-full text-sm">
                                    <thead>
                                        <tr>
                                            <th>URL</th>
                                            <th>Verdict</th>
                                            <th>Coverage</th>
                                            <th>Rich Results</th>
                                            <th>Last Crawl</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentInspections as $i):
                                            $verdict = (string)($i['verdict'] ?? '');
                                            $vClass = match ($verdict) {
                                                'PASS' => 'bg-success-100 text-success-700',
                                                'FAIL' => 'bg-danger-100 text-danger-700',
                                                'PARTIAL' => 'bg-warning-100 text-warning-700',
                                                'NEUTRAL' => 'bg-neutral-100 text-neutral-700',
                                                default => 'bg-neutral-100 text-neutral-600',
                                            };
                                        ?>
                                            <tr>
                                                <td class="truncate max-w-[280px]">
                                                    <a href="<?= htmlspecialchars((string)$i['url']) ?>" target="_blank" rel="noopener" class="text-blue-600 hover:underline">
                                                        <?= htmlspecialchars((string)$i['url']) ?>
                                                    </a>
                                                </td>
                                                <td><span class="px-2 py-0.5 rounded text-xs font-medium <?= $vClass ?>"><?= htmlspecialchars($verdict ?: '—') ?></span></td>
                                                <td class="text-sm text-neutral-600"><?= htmlspecialchars((string)($i['coverage'] ?? '—')) ?></td>
                                                <td class="text-sm text-neutral-600"><?= htmlspecialchars((string)($i['rich_verdict'] ?? '—')) ?></td>
                                                <td class="text-xs text-neutral-500"><?= htmlspecialchars((string)($i['last_crawl'] ?? '—')) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($canShowData && !$hasPremiumAccess): ?>
                <div class="h-[350px] flex flex-col items-center justify-center text-center
                            bg-gray-50 dark:bg-neutral-700 rounded-lg
                            border border-dashed border-gray-300 dark:border-neutral-600 p-6 mt-5">
                    <div class="w-12 h-12 bg-neutral-200 dark:bg-neutral-700 rounded-full flex items-center justify-center mb-3">
                        <iconify-icon icon="solar:lock-keyhole-bold-duotone" class="text-2xl text-neutral-400"></iconify-icon>
                    </div>
                    <h6 class="text-xl font-medium text-gray-900 dark:text-white">All Data Locked</h6>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 mb-4">
                        Upgrade your plan to unlock the consolidated Search Console report.
                    </p>
                    <button type="button" class="open-upgrade-modal inline-flex items-center gap-2 px-4 py-2 rounded-md bg-cstm-primary text-white text-sm font-medium hover:bg-cstm-primary/90 transition">
                        <iconify-icon icon="mdi:crown-outline" class="text-lg"></iconify-icon>
                        Upgrade Plan
                    </button>
                    <p class="text-xs text-gray-400 mt-3">Available on paid plans &amp; active trials</p>
                </div>
            <?php else: ?>
                <div class="h-[280px] flex flex-col items-center justify-center text-center
                            bg-gray-50 dark:bg-neutral-700 rounded-lg
                            border border-dashed border-gray-300 dark:border-neutral-600 p-6 mt-5">
                    <h6 class="text-xl font-medium text-gray-900 dark:text-white">Connect &amp; Verify First</h6>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Finish the setup wizard to connect Google and verify your domain — then all your Search Console data will appear here.
                    </p>
                    <a href="setup-wizard.php" class="mt-4 inline-flex items-center gap-2 px-4 py-2 rounded-md bg-cstm-primary text-white text-sm font-medium">
                        Open Setup Wizard
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div id="tab-2" class="tab-content">
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 mt-2">

                <?php
                $labels = [
                    'ready_to_buy' => 'Ready to Buy',
                    'product_research' => 'Product Research',
                    'trust_comparison' => 'Trust & Comparison',
                    'post_purchase' => 'Post Purchase'
                ];
                $descriptions = [
                    'ready_to_buy' => 'Users ready to purchase. These queries usually convert the best and drive direct revenue.',
                    'product_research' => 'Users comparing products or solutions. They know the problem but are evaluating options.',
                    'trust_comparison' => 'Users looking for reviews, comparisons, or proof before making a decision.',
                    'post_purchase' => 'Existing customers searching for support, tracking, returns, or other post-purchase help.'
                ];
                ?>

                <?php foreach ($labels as $key => $title): ?>
                    <div class="xl:col-span-6">
                        <div class="card h-full border border-gray-200 dark:border-neutral-600" id="<?= $key ?>">
                            <div class="card-body p-6">
                                <?php
                                $rows     = $ecommerceIntentData[$key] ?? [];
                                $isSample = in_array('ecom_' . $key, $gscSampleSections ?? [], true);
                                ?>
                                <?= gsc_sample_note($isSample) ?>

                                <h3 class="text-lg font-semibold flex items-center gap-2 flex-wrap mb-1">
                                    <?= $title ?>
                                    <?= gsc_sample_badge($isSample) ?>
                                </h3>
                                <p class="text-sm text-gray-500 mb-3">
                                    <?= $descriptions[$key] ?? '' ?>
                                </p>
                                <?php if (!$hasPremiumAccess): ?>
                                    <div
                                        class="flex flex-col items-center justify-center text-center bg-gray-50 dark:bg-neutral-700 rounded-lg border border-dashed border-gray-300 dark:border-neutral-600 p-6">
                                        <div
                                            class="w-12 h-12 bg-neutral-200 dark:bg-neutral-700 rounded-full flex items-center justify-center mb-3">
                                            <iconify-icon icon="solar:lock-keyhole-bold-duotone"
                                                class="text-2xl text-neutral-400"></iconify-icon>
                                        </div>
                                        <h4 class="text-xl font-medium text-gray-900 dark:text-white">Ecommerce Query Locked
                                        </h4>
                                        <p class="text-sm text-gray-500 mt-2 mb-4"> Upgrade your plan to unlock ecommerce query
                                            insights.</p>
                                        <button
                                            class="open-upgrade-modal btn btn-cstm-primary flex items-center justify-center gap-1">
                                            <iconify-icon icon="mdi:crown-outline" class="text-lg"></iconify-icon>Upgrade Plan
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="overflow-x-auto <?= gsc_sample_class($isSample) ?>">
                                        <table class="table table-sm w-full table-auto">
                                            <thead>
                                                <tr>
                                                    <th>Query</th>
                                                    <th class="text-right">Clicks</th>
                                                    <th class="text-right">Impr.</th>
                                                    <th class="text-right">Pos</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (array_slice($rows, 0, 5) as $row): ?>
                                                    <tr>
                                                        <td class="max-w-[150px] truncate">
                                                            <?= htmlspecialchars($row['keys'][0] ?? '') ?>
                                                        </td>
                                                        <td class="text-right">
                                                            <?= (int)($row['clicks'] ?? 0) ?>
                                                        </td>
                                                        <td class="text-right">
                                                            <?= (int)($row['impressions'] ?? 0) ?>
                                                        </td>
                                                        <td class="text-right">
                                                            <?= number_format($row['position'] ?? 0, 1) ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                <div class="text-sm text-gray-500 mt-3 flex justify-between flex-wrap gap-1">
                                    Showing top 6 results ·
                                    <a href="gsc-report.php?tab=keywords" class="text-blue-600 hover:underline">
                                        View full report →
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="flex justify-end items-center mt-4">
            <a href="gsc-report.php?tab=keywords" class="h-10 btn btn-cstm-primary flex items-center gap-2 px-3">
                <iconify-icon icon="mdi:poll"></iconify-icon>
                <span>View Details reports</span>
            </a>
        </div>
    </div>
</div>

<!-- end -->

<div class="card h-full rounded-lg border-0 mb-8 mt-8 card-body">

    <div class="card h-full rounded-lg border-0 p-0 card-body">
        <div class="flex justify-start items-start">
            <h3 class="text-lg font-semibold mb-6 flex items-center gap-2">
                <i class="fa-solid fa-shapes text-cstm-primary"></i>
                Our Other Apps
            </h3>
        </div>

        <!-- ✅ SINGLE GRID ONLY -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6" id="otherAppsContainer">
            <p class="text-sm text-gray-500">Loading apps...</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 card h-full rounded-lg border-0">
        <?php foreach (($apps ?? []) as $app): ?>
            <div class="lg:col-span-6">
                <a href="<?= htmlspecialchars($app['link']) ?>" target="_blank" class="block h-full">
                    <div
                        class="h-full w-full card border border-gray-200 dark:border-neutral-600 rounded-xl overflow-hidden flex mobile_d_unset items-start p-4 hover:shadow-md transition-shadow dark:bg-neutral-800">

                        <div
                            class="flex-shrink-0 mr-4 h-16 w-16 bg-gray-100 dark:bg-neutral-700 rounded-full flex items-center justify-center border border-gray-200 dark:border-neutral-600">
                            <?php if (!empty($app['icon'])): ?>
                                <img src="<?= htmlspecialchars($app['icon']) ?>" alt="App Icon"
                                    class="h-10 w-10 object-contain">
                            <?php else: ?>
                                <iconify-icon icon="mdi:application" class="text-2xl text-gray-400"></iconify-icon>
                            <?php endif; ?>
                        </div>

                        <div class="flex-grow">
                            <div class="flex items-start justify-between">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white leading-tight">
                                    <?= htmlspecialchars($app['title']) ?>
                                </h3>
                                <span
                                    class="flex-shrink-0 text-xs font-medium text-cstm-primary bg-cstm-primary-10 dark:bg-cstm-primary-30 px-2 py-0.5 rounded-full ml-4">
                                    <?= htmlspecialchars($app['plan']) ?>
                                </span>
                            </div>

                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1 mb-3">
                                <?= htmlspecialchars($app['desc']) ?>
                            </p>
                            <?php if ($app['rating']): ?>
                                <div class="flex items-center text-xs text-gray-700 dark:text-gray-200">
                                    <i class="fa-solid fa-star text-yellow-400 mr-1"></i>
                                    Rated <span class="font-semibold mx-1">
                                        <?= $app['rating'] ?>
                                    </span> on Wix App Market
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<script>
    document.addEventListener("DOMContentLoaded", () => {
        const data = window.topPagesData || [];
        const tbody = document.getElementById("top-pages-body");

        if (!tbody) return;

        if (data.length === 0) {
            tbody.innerHTML = `
            <tr>
                <td colspan="3" class="text-center text-gray-500">
                    google search console requires upto 48 hours to collect your data and display it here
                </td>
            </tr>`;
            return;
        }

        data.forEach(row => {
            tbody.innerHTML += `
            <tr>
                <td class="truncate max-w-xs">
                    <a href="${row.url}" target="_blank" class="text-blue-600 hover:underline">
                        ${row.url.replace(/^https?:\/\//, '')}
                    </a>
                </td>
                <td>${row.clicks}</td>
                <td>${row.impressions}</td>
            </tr>
        `;
        });
    });
</script>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        // Pagination Logic
        const allHistoryData = <?= json_encode($history) ?>;
        const historyTbody = document.getElementById('submission-history-tbody');
        const paginationContainer = document.getElementById('submission-history-pagination');
        const sitemapUrlDisplay = "<?= htmlspecialchars($sitemapUrl ?? 'NA') ?>";
        const userTimezone = "<?= htmlspecialchars($userTimezone ?? 'UTC') ?>";

        // ONLY RUN PAGINATION IF TABLE EXISTS
        if (historyTbody && paginationContainer) {
            const ITEMS_PER_PAGE = 10;
            let currentPage = 1;

            // Convert PHP datetime to user's timezone format
            function formatDate(datetime) {
                if (!datetime) return '';

                // Create UTC date object from the database datetime string
                const utcDate = new Date(datetime + ' UTC');

                // Format according to user's timezone
                const options = {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false,
                    timeZone: userTimezone
                };

                return utcDate.toLocaleString('en-US', options);
            }

            // Escape HTML to prevent XSS
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function renderHistoryPage(page) {
                currentPage = page;
                historyTbody.innerHTML = '';

                if (allHistoryData.length === 0) {
                    historyTbody.innerHTML = '<tr><td colspan="5" class="text-center py-10 text-neutral-500">No sitemap submissions yet.</td></tr>';
                    paginationContainer.style.display = 'none';
                    return;
                }

                const totalPages = Math.ceil(allHistoryData.length / ITEMS_PER_PAGE);
                if (page < 1) page = 1;
                if (page > totalPages) page = totalPages;

                const start = (page - 1) * ITEMS_PER_PAGE;
                const paginatedItems = allHistoryData.slice(start, start + ITEMS_PER_PAGE);

                paginatedItems.forEach(log => {
                    const statusBadge = log.status === 'Success' ?
                        '<span class="badge badge-success text-xs bg-success-100 dark:bg-success-600/25 text-success-600 dark:text-success-400 rounded-full px-2 py-1 font-semibold">Success</span>' :
                        '<span class="badge badge-danger text-xs bg-danger-100 dark:bg-danger-600/25 text-danger-600 dark:text-danger-400 rounded-full px-2 py-1 font-semibold">Failed</span>';

                    const response = log.google_response ? escapeHtml(log.google_response.substring(0, 60)) + '...' : 'N/A';

                    historyTbody.innerHTML += `
                    <tr>
                        <td class="max-w-[320px] break-all">${log.sitemap_url}</td>
                        <td class="max-w-[30px] !text-center">${statusBadge}</td>
                        <td class="max-w-[50px]">${escapeHtml(formatDate(log.submitted_at))}</td>
                    </tr>
                `;
                });

                setupPagination();
            }

            function setupPagination() {
                paginationContainer.innerHTML = '';
                const totalPages = Math.ceil(allHistoryData.length / ITEMS_PER_PAGE);

                if (totalPages <= 1) {
                    paginationContainer.style.display = 'none';
                    return;
                }

                paginationContainer.style.display = 'flex';

                // Previous Button
                const isFirstPage = currentPage === 1;
                paginationContainer.innerHTML += `<button data-page="${currentPage - 1}" class="px-3 py-1 rounded-md text-sm font-medium hover:bg-gray-200 dark:hover:bg-neutral-600 ${isFirstPage ? 'opacity-50 cursor-not-allowed' : ''}" ${isFirstPage ? 'disabled' : ''}>Prev</button>`;

                // Page Number Buttons
                for (let i = 1; i <= totalPages; i++) {
                    const isActive = i === currentPage;
                    paginationContainer.innerHTML += `<button data-page="${i}" class="px-3 py-1 rounded-md text-sm font-medium ${isActive ? 'bg-cstm-primary text-white' : 'bg-gray-100 dark:bg-neutral-700'}">${i}</button>`;
                }

                // Next Button
                const isLastPage = currentPage === totalPages;
                paginationContainer.innerHTML += `<button data-page="${currentPage + 1}" class="px-3 py-1 rounded-md text-sm font-medium hover:bg-gray-200 dark:hover:bg-neutral-600 ${isLastPage ? 'opacity-50 cursor-not-allowed' : ''}" ${isLastPage ? 'disabled' : ''}>Next</button>`;

                paginationContainer.querySelectorAll('button').forEach(button => {
                    button.addEventListener('click', () => renderHistoryPage(parseInt(button.dataset.page)));
                });
            }

            // Initial render
            renderHistoryPage(1);
        }
    })
</script>
<?php
// Queue these for partials/script.php to echo AFTER the chart libraries.
$gscChartIsSample = gsc_is_sample('chart');

if (!isset($script)) {
    $script = '';
}

$script .= '<script>
    window.gscChartData = ' . json_encode($chartData) . ';
    window.gscChartIsSample = ' . ($gscChartIsSample ? 'true' : 'false') . ';
</script>';

if (!$gscChartIsSample) {
    $script .= '<script src="assets/js/homeOneChart.js"></script>';
}
$script .= '<script src="assets/js/gscSampleChart.js"></script>';
?>
<!-- =======================
   UPGRADE PLAN MODAL
======================= -->
<div id="upgradeModal"
    class="fixed inset-0 bg-black/40 bg-cstm-black-40 backdrop-blur-sm shadow-lg z-50 hidden items-center justify-center">

    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/40"></div>

    <!-- Modal Box -->
    <div class="relative bg-white dark:bg-neutral-800 rounded-xl
                max-w-lg mx-4 p-6 shadow-xl">

        <!-- Close -->
        <button id="closeUpgradeModal" class="absolute top-3 right-4 text-gray-400 hover:text-gray-600">
            ✕
        </button>

        <h2 class="text-xl font-semibold mb-2 text-neutral-900 dark:text-white">
            🚀 Unlock Premium Search Console Features
        </h2>

        <p class="text-sm text-gray-500 mb-4">
            Upgrade your plan to unlock advanced Google Search Console analytics and SEO insights.
        </p>

        <ul class="space-y-2 text-sm text-gray-700 dark:text-gray-300 mb-6">
            <li>✅ Brand vs Non-Brand keyword analysis & performance split</li>
            <li>✅ Query by Page reports (identify keyword cannibalization)</li>
            <li>✅ Top-performing pages with detailed metrics (clicks, impressions, CTR, position)</li>
            <li>✅ Intent-based query analysis (Informational, Commercial, Transactional, Navigational)</li>
            <li>✅ Full search analytics dashboard with extended date ranges</li>
            <li>✅ Advanced performance tracking & ranking insights</li>
        </ul>

        <div class="flex justify-end gap-3">
            <button id="cancelUpgrade" class="px-4 py-2 rounded-md text-sm
                           border border-gray-300 dark:border-neutral-600">
                Cancel
            </button>

            <!-- FINAL ACTION -->
            <a href="pricing.php?upgrade=1" class="px-5 py-2 rounded-md text-sm font-medium
                      bg-cstm-primary text-white hover:bg-cstm-primary/90">
                Upgrade Plan
            </a>
        </div>
    </div>
</div>

<div id="domain-disconnect-overlay" class="fixed inset-0 bg-neutral-900/60 backdrop-blur-sm z-[9998] hidden transition-opacity duration-300 opacity-0"></div>

<div id="domain-disconnect-modal" class="fixed top-1/2 left-1/2 z-[9999] w-full max-w-2xl bg-white dark:bg-neutral-800 rounded-2xl shadow-2xl p-6 hidden transition-all duration-300 opacity-0 scale-95 -translate-x-1/2 -translate-y-1/2 border border-neutral-200 dark:border-neutral-600" style="z-index: 9999;">
    <div class="text-center">
        <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-danger-100 dark:bg-danger-900/30 mb-4">
            <svg class="h-6 w-6 text-danger-600 dark:text-danger-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        </div>
        <h3 class="text-lg font-bold text-gray-900 dark:text-white" id="modal-title">Disconnect Account?</h3>
        <div class="mt-2">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Are you sure you want to disconnect? This will stop data synchronization with Google Search Console.
            </p>
        </div>
    </div>
    <div class="mt-6 grid grid-cols-2 gap-3">
        <button id="domainCancelDisconnect" type="button" class="w-full inline-flex justify-center rounded-lg border border-neutral-300 dark:border-neutral-600 shadow-sm px-4 py-2.5 bg-white dark:bg-neutral-800 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-neutral-700 transition-colors">
            Cancel
        </button>
        <button id="domainConfirmDisconnect" type="button" class="w-full inline-flex justify-center rounded-lg border border-transparent shadow-sm px-4 py-2.5 bg-danger-600 text-sm font-medium text-white hover:bg-danger-700 transition-colors">
            Disconnect
        </button>
    </div>
</div>

<style>
    .loader {
        border: 2px solid rgba(255, 255, 255, 0.1);
        border-left-color: currentColor;
        border-radius: 50%;
        width: 1rem;
        height: 1rem;
        animation: spin 1s linear infinite;
        display: inline-block;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    /* live status dot */
    .live-dot {
        position: relative;
        display: inline-flex;
        width: 8px;
        height: 8px;
        flex: none;
    }

    .live-dot::before {
        content: "";
        position: absolute;
        inset: 0;
        border-radius: 9999px;
        background: currentColor;
        opacity: .75;
        animation: livePing 1.5s cubic-bezier(0, 0, .2, 1) infinite;
    }

    .live-dot::after {
        content: "";
        position: relative;
        width: 8px;
        height: 8px;
        border-radius: 9999px;
        background: currentColor;
    }

    @keyframes livePing {

        75%,
        100% {
            transform: scale(2.2);
            opacity: 0;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .live-dot::before {
            animation: none;
            opacity: .35;
        }
    }
    .highlight-section {
        position: relative;
        animation: sectionHighlight 2.5s ease;
    }

    @keyframes sectionHighlight {
        0% {
            background-color: rgba(59, 130, 246, 0.25);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.35);
        }
        70% {
            background-color: rgba(59, 130, 246, 0.15);
            box-shadow: 0 0 0 8px rgba(59, 130, 246, 0);
        }
        100% {
            background-color: transparent;
            box-shadow: none;
        }
    }
</style>
<script>
    document.addEventListener("DOMContentLoaded", () => {
        const modal = document.getElementById("upgradeModal");

        // Open modal
        document.querySelectorAll(".open-upgrade-modal").forEach(btn => {
            btn.addEventListener("click", () => {
                modal.classList.remove("hidden");
                modal.classList.add("flex");
            });
        });

        // Close modal
        document.getElementById("closeUpgradeModal")?.addEventListener("click", closeModal);
        document.getElementById("cancelUpgrade")?.addEventListener("click", closeModal);

        // Close when clicking backdrop
        modal.addEventListener("click", (e) => {
            if (e.target === modal) closeModal();
        });

        function closeModal() {
            modal.classList.add("hidden");
            modal.classList.remove("flex");
        }
    });
</script>

<script>
    const tabs = document.querySelectorAll('.tab-btn');
    const contents = document.querySelectorAll('.tab-content');
    const loader = document.getElementById('tab-loader');

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {

            // Active tab style
            tabs.forEach(t => t.classList.remove('active-tab'));
            tab.classList.add('active-tab');

            // Hide all content
            contents.forEach(c => c.classList.add('hidden'));

            // Show loader
            loader.classList.remove('hidden');

            // Simulate loading delay
            setTimeout(() => {
                loader.classList.add('hidden');
                document.getElementById(tab.dataset.tab).classList.remove('hidden');

                // Lazy-load Image + Discover sections on first open of All Data tab
                if (tab.dataset.tab === 'tab-all-data') {
                    window.__allDataLazyLoad && window.__allDataLazyLoad();
                }
            }, 600); // adjust delay if needed
        });
    });


 const HEADER_HEIGHT = 140;

document.querySelectorAll('.top-tab').forEach(btn => {
    btn.addEventListener('click', function () {

        if (this.tagName.toLowerCase() === 'a') return;

        // Activate the corresponding lower tab
        const lowerTab = document.querySelector(
            `.tab-btn:not(.top-tab)[data-tab="${this.dataset.tab}"]`
        );

        if (lowerTab) {
            lowerTab.click();
        }

        setTimeout(() => {

            const tabContent = document.getElementById(this.dataset.tab);

            if (!tabContent) return;

            // Scroll to the tab content
            window.scrollTo({
                top: tabContent.getBoundingClientRect().top +
                     window.pageYOffset -
                     HEADER_HEIGHT -
                     16,
                behavior: 'smooth'
            });

            // Highlight the entire tab content
            setTimeout(() => {
                tabContent.classList.remove('highlight-section');
                void tabContent.offsetWidth;
                tabContent.classList.add('highlight-section');

                setTimeout(() => {
                    tabContent.classList.remove('highlight-section');
                }, 2500);
            }, 500);

        }, 600);
    });
});
</script>

<script>
    /* ============================================================
   ALL DATA TAB — lazy-load Image & Discover search type traffic
   ============================================================ */
    (function() {
        const INSTANCE_ID = <?= json_encode((string)$instanceId) ?>;
        const CAN_SHOW = <?= json_encode((bool)($canShowData && $hasPremiumAccess)) ?>;
        const API_BASE = (window.APP_BASE || '') + '/api/google/get_gsc_report.php';
        let imageLoaded = false;
        let discoverLoaded = false;

        function $(id) {
            return document.getElementById(id);
        }

        function fmt(n) {
            return Number(n || 0).toLocaleString();
        }

        function esc(s) {
            return String(s ?? '').replace(/[&<>"']/g, c =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [c]));
        }

        function totalsAndTop(rows, topKey) {
            let clicks = 0,
                impr = 0;
            for (const r of rows) {
                clicks += Number(r.clicks || 0);
                impr += Number(r.impressions || 0);
            }
            const sorted = rows.slice().sort((a, b) => (b.impressions || 0) - (a.impressions || 0));
            return {
                clicks,
                impr,
                top: sorted.slice(0, 5).map(r => ({
                    key: (r.keys && r.keys[0]) || r.key || '',
                    impressions: r.impressions || 0,
                    clicks: r.clicks || 0,
                }))
            };
        }

        async function loadSearchType(searchType, dim, loaderEl, contentEl, emptyEl, clicksEl, imprEl, listEl) {
            try {
                const res = await fetch(API_BASE, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        instanceId: INSTANCE_ID,
                        range: '30days',
                        type: dim,
                        searchType: searchType,
                    }),
                });
                const data = await res.json();
                loaderEl.classList.add('hidden');
                if (!data.success || !Array.isArray(data.rows) || data.rows.length === 0) {
                    emptyEl.classList.remove('hidden');
                    return;
                }
                const t = totalsAndTop(data.rows);
                clicksEl.textContent = fmt(t.clicks);
                imprEl.textContent = fmt(t.impr);
                listEl.innerHTML = t.top.map(r => `
                 <li class="group relative overflow-hidden rounded border-b border-gray-200 dark:border-neutral-700 dark:bg-neutral-800 p-4 transition-all duration-300">
                    <div class="relative flex items-start justify-between gap-4">
                        <!-- Left -->
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="min-w-0">
                                <h6 class="truncate text-sm font-semibold text-neutral-800 dark:text-neutral-200">
                                    ${esc(r.key)}
                                </h6>
                            </div>
                        </div>

                        <!-- Stats -->
                        <div class="flex gap-3 text-right whitespace-nowrap">
                            <div class="flex items-center gap-1 text-neutral-500 text-sm">
                                <iconify-icon icon="solar:eye-outline" class="text-sm"></iconify-icon>
                                <span>${fmt(r.impressions)} impr</span>
                            </div>
                            <div class="flex items-center gap-1 text-primary-600 dark:text-primary-400 text-sm mt-1">
                                <iconify-icon icon="solar:cursor-outline" class="text-sm"></iconify-icon>
                                <span>${fmt(r.clicks)} clicks</span>
                            </div>
                        </div>
                    </div>
                </li>
            `).join('');
                contentEl.classList.remove('hidden');
            } catch (err) {
                loaderEl.classList.add('hidden');
                emptyEl.textContent = 'Failed to load: ' + err.message;
                emptyEl.classList.remove('hidden');
            }
        }

        window.__allDataLazyLoad = function() {
            if (!CAN_SHOW || !INSTANCE_ID) return;

            if (!imageLoaded) {
                imageLoaded = true;
                loadSearchType(
                    'image', 'keywords',
                    $('all-data-image-loader'),
                    $('all-data-image-content'),
                    $('all-data-image-empty'),
                    $('all-data-image-clicks'),
                    $('all-data-image-impr'),
                    $('all-data-image-list')
                );
            }

            if (!discoverLoaded) {
                discoverLoaded = true;
                loadSearchType(
                    'discover', 'pages',
                    $('all-data-discover-loader'),
                    $('all-data-discover-content'),
                    $('all-data-discover-empty'),
                    $('all-data-discover-clicks'),
                    $('all-data-discover-impr'),
                    $('all-data-discover-list')
                );
            }
        };
    })();
</script>


<script>
    document.addEventListener('DOMContentLoaded', function() {

        const container = document.getElementById('otherAppsContainer');
        const instanceId = '<?php echo $instanceId; ?>';

        if (!instanceId) {
            container.innerHTML = '<p class="text-sm text-gray-500">Instance ID missing</p>';
            return;
        }

        fetch(`api/dbSchema/other_apps.php?instance_id=${instanceId}`)
            .then(res => res.json())
            .then(res => {
                if (!res.success || !res.data || res.data.length === 0) {
                    container.innerHTML = '<p class="text-sm text-gray-500">No apps available.</p>';
                    return;
                }

                container.innerHTML = '';

                res.data.forEach(app => {
                    container.innerHTML += `
                <div class="lg:col-span-6">
                    <a href="${app.button_link ?? '#'}" target="_blank" class="block h-full">
                        <div class="w-full card border border-gray-200 dark:border-neutral-600
                                    rounded-xl overflow-hidden flex flex-col sm:flex-row items-start p-4
                                    hover:shadow-md transition-shadow dark:bg-neutral-800 h-full">

                            <div class="flex-shrink-0 mr-4 h-16 w-16 bg-gray-50 rounded-full
                                        flex items-center justify-center border border-gray-200">
                                <img src="${app.image_url}" alt="App Icon"
                                     class="h-10 w-10 object-contain">
                            </div>

                            <div class="flex-grow">
                                <div class="flex flex-col sm:flex-row items-start justify-between">
                                    <h3 class="text-base font-semibold text-gray-900 leading-tight">
                                        ${app.title}
                                    </h3>

                                    ${app.app_price ? `
                                    <span class="text-xs font-medium text-cstm-primary
                                                 bg-cstm-primary-20 px-2 py-0.5 rounded-full ml-4 whitespace-nowrap">
                                        ${app.app_price}
                                    </span>` : ''}
                                </div>

                                <p class="text-sm text-gray-600 mt-1 mb-3">
                                    ${app.description ?? ''}
                                </p>
                            </div>
                        </div>
                    </a>
                </div>`;
                });
            })
            .catch(err => {
                console.error(err);
                container.innerHTML =
                    '<p class="text-sm text-red-500">Failed to load apps.</p>';
            });
    });
</script>




<!-- Device Breakdown - Graph - start -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var chartElement = document.querySelector("#deviceBreakdownDonutChart");
        if (chartElement && typeof ApexCharts !== 'undefined') {
            var options = {
                series: <?= json_encode($chartSeries) ?>,
                labels: <?= json_encode($chartLabels) ?>,
                colors: <?= json_encode($chartColors) ?>,
                chart: {
                    type: 'donut',
                    width: '100%',
                    height: '100%',
                    sparkline: {
                        enabled: false
                    }
                },
                stroke: {
                    show: true,
                    width: 2,
                    colors: ['#ffffff']
                },
                dataLabels: {
                    enabled: false
                },
                legend: {
                    show: false
                },
                plotOptions: {
                    pie: {
                        donut: {
                            size: '70%',
                            labels: {
                                show: true,
                                name: {
                                    show: true,
                                    fontSize: '12px',
                                    fontFamily: 'Inter, sans-serif',
                                    fontWeight: '600',
                                    color: '#9ca3af',
                                    offsetY: 20
                                },
                                value: {
                                    show: true,
                                    fontSize: '22px',
                                    fontFamily: 'Inter, sans-serif',
                                    fontWeight: '600',
                                    color: '#111827',
                                    offsetY: -16,
                                    formatter: function(val) {
                                        let num = Number(val);
                                        return num >= 1000 ? (num / 1000).toFixed(1) + 'K' : num;
                                    }
                                },
                                total: {
                                    show: true,
                                    label: 'Total Clicks',
                                    fontSize: '12px',
                                    fontFamily: 'Inter, sans-serif',
                                    fontWeight: '600',
                                    color: '#9ca3af',
                                    formatter: function() {
                                        let total = <?= (int)$totalClicks ?>;
                                        return total >= 1000 ? (total / 1000).toFixed(1) + 'K' : total;
                                    }
                                }
                            }
                        }
                    }
                },
                tooltip: {
                    enabled: true,
                    y: {
                        formatter: function(val) {
                            return val.toLocaleString() + " Clicks";
                        }
                    }
                }
            };

            var chart = new ApexCharts(chartElement, options);
            chart.render();
        }
    });
</script>
<script>
    // Ensure APP_BASE is always defined for frontend requests
    window.APP_BASE = '<?= rtrim(defined('APP_BASE') ? APP_BASE : '/', "/") ?>';
</script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const menuBtn = document.getElementById('gscMenuBtn');
        const dropdown = document.getElementById('gscDropdown');
        const openBtn = document.getElementById('openConnectionDetails');
        const closeBtn = document.getElementById('closeConnectionSidebar');
        const sidebar = document.getElementById('gscSidebar');
        const backdrop = document.getElementById('gscSidebarBackdrop');

        // Toggle Dropdown
        if (menuBtn && dropdown) {
            menuBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdown.classList.toggle('hidden');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target) && !menuBtn.contains(e.target)) {
                    dropdown.classList.add('hidden');
                }
            });
        }

        // Open Sidebar
        function openSidebar() {
            if (dropdown) dropdown.classList.add('hidden');
            if (backdrop && sidebar) {
                backdrop.classList.remove('hidden');
                setTimeout(() => {
                    backdrop.classList.remove('opacity-0');
                    sidebar.classList.remove('translate-x-full');
                }, 10);
                document.body.style.overflow = 'hidden'; // Lock background scrolling
            }
        }

        // Close Sidebar
        function closeSidebar() {
            if (backdrop && sidebar) {
                sidebar.classList.add('translate-x-full');
                backdrop.classList.add('opacity-0');
                setTimeout(() => {
                    backdrop.classList.add('hidden');
                    document.body.style.overflow = ''; // Unlock scrolling
                }, 300);
            }
        }

        if (openBtn) openBtn.addEventListener('click', openSidebar);
        if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
        if (backdrop) backdrop.addEventListener('click', closeSidebar);

        // ESC Key to close
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeSidebar();
        });
    });

    // -- Modal Logic --
    const openBtn = document.getElementById("disconnectDirectBtn");
    const openBtn1 = document.getElementById("disconnectDirectBtn1");
    const overlay = document.getElementById("domain-disconnect-overlay");
    const modal = document.getElementById("domain-disconnect-modal");
    const cancelBtn = document.getElementById("domainCancelDisconnect");
    const confirmBtn = document.getElementById("domainConfirmDisconnect");


    function openModal() {
        overlay.classList.remove("hidden");
        modal.classList.remove("hidden");
        setTimeout(() => {
            overlay.classList.remove("opacity-0");
            modal.classList.remove("opacity-0", "scale-95");
            modal.classList.add("opacity-100", "scale-100");
        }, 10);
    }

    function closeModal() {
        overlay.classList.remove("opacity-100");
        modal.classList.remove("opacity-100", "scale-100");
        modal.classList.add("opacity-0", "scale-95");

        setTimeout(() => {
            overlay.classList.add("hidden");
            modal.classList.add("hidden");
        }, 300);
    }

    if (openBtn) openBtn.addEventListener("click", openModal);
    if (openBtn1) openBtn1.addEventListener("click", openModal);
    if (overlay) overlay.addEventListener("click", closeModal);
    if (cancelBtn) cancelBtn.addEventListener("click", closeModal);

    if (confirmBtn) {
        confirmBtn.addEventListener("click", async () => {
            const originalText = confirmBtn.innerHTML;
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = `<span class="loader mr-2"></span> Processing...`;

            try {
                const res = await fetch(window.APP_BASE + "/includes/google/disconnect.php", {
                    method: "POST"
                });
                if (res.ok) window.location.href = "setup-wizard.php?step=1";
                else throw new Error("Failed to disconnect");
            } catch (err) {
                alert("Error: " + err.message);
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = originalText;
            }
        });
    }
</script>
<!-- Device Breakdown - Graph - end -->
<?php if (!empty($_SESSION['show_review_modal'])): ?>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            openReviewModalGlobal();
        });
    </script>
    <?php unset($_SESSION['show_review_modal']); ?>
<?php endif; ?>
<?php include './partials/layouts/layoutBottom.php' ?>