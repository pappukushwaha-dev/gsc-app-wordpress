<?php
/**
 * includes/sample_fallback.php
 *
 * dashboard.php me SIRF EK JAGAH include karna hai - saare curl fetch ke BAAD,
 * aur `?>` (HTML shuru hone) se PEHLE.
 *
 * RULE:
 *   Chart me asli data aa gaya   -> kahin bhi sample nahi, banner bhi nahi.
 *   Chart me asli data nahi aaya -> saare khali section sample se bhar do.
 *
 * Yaani chart hi decide karta hai ki dashboard sample mode me hai ya nahi.
 */

require_once __DIR__ . '/sample_data.php';

// Google shuru ke dino me 1-2 din ka aadha-adhura data bhejta hai. Utne se
// chart khali jaisa dikhta hai, isliye kam se kam itne din chahiye.
$GSC_MIN_CHART_DAYS = 7;

// Kaun kaun se section sample dikha rahe hain
$gscSampleSections = [];

$totalClicks = array_sum($chartData['clicks'] ?? []);
$totalImpr   = array_sum($chartData['impressions'] ?? []);

$gscChartHasRealData = ($totalClicks > 0 || $totalImpr > 0);


if (!$gscChartHasRealData) {

    // ------------------------------------------------------------- CHART
   $chartData = gsc_sample_chart();
    $gscSampleSections[] = 'chart';
    if (empty($kpi['impressions']) && empty($kpi['clicks'])) {
        $kpi = gsc_sample_kpi();
        $gscSampleSections[] = 'kpi';
    }

    // --------------------------------------------------- BRAND KEYWORDS
    if (!isset($brandSplit) || !is_array($brandSplit)) {
        $brandSplit = [];
    }

    if (gsc_needs_sample($brandSplit['brand_keywords'] ?? [])) {
        $brandSplit['brand_keywords'] = gsc_sample_brand_keywords();
        $gscSampleSections[] = 'brand';
    }

    if (gsc_needs_sample($brandSplit['non_brand_keywords'] ?? [])) {
        $brandSplit['non_brand_keywords'] = gsc_sample_non_brand_keywords();
        $gscSampleSections[] = 'non_brand';
    }

    if (empty($brandSplit['branded_clicks']) && empty($brandSplit['non_branded_clicks'])) {
        $brandSplit = array_merge($brandSplit, gsc_sample_brand_split());
    }

    // ----------------------------------------------------- QUERY BY PAGE
    if (gsc_needs_sample($queryByPage ?? [])) {
        $queryByPage = gsc_sample_query_by_page();
        $gscSampleSections[] = 'query_by_page';
    }

    // ------------------------------------------------------- PAGES REPORT
    if (gsc_needs_sample($pagesReport ?? [])) {
        $pagesReportAll = gsc_sample_pages();
        $pagesReport    = array_slice($pagesReportAll, 0, 6);
        $gscSampleSections[] = 'pages';
    }

    // ------------------------------- OVERVIEW / COUNTRIES / DEVICES
    if (gsc_needs_sample($overviewReport ?? [])) {
        $overviewReport = gsc_sample_non_brand_keywords();
        $gscSampleSections[] = 'overview';
    }

    if (gsc_needs_sample($countriesReport ?? [])) {
        $countriesReport = gsc_sample_countries();
        $gscSampleSections[] = 'countries';
    }

    if (gsc_needs_sample($devicesReport ?? [])) {
        $devicesReport = gsc_sample_devices();
        $gscSampleSections[] = 'devices';
    }

    // ---------------------------------------------------- INTENT BUCKETS
    if (!isset($ecommerceIntentData) || !is_array($ecommerceIntentData)) {
        $ecommerceIntentData = [];
    }
    foreach (gsc_sample_ecommerce_intent() as $bucket => $sampleRows) {
        if (gsc_needs_sample($ecommerceIntentData[$bucket] ?? [])) {
            $ecommerceIntentData[$bucket] = $sampleRows;
            $gscSampleSections[] = 'ecom_' . $bucket;
        }
    }

    if (!isset($intentData) || !is_array($intentData)) {
        $intentData = [];
    }
    foreach (gsc_sample_search_intent() as $bucket => $sampleRows) {
        if (gsc_needs_sample($intentData[$bucket] ?? [])) {
            $intentData[$bucket] = $sampleRows;
            $gscSampleSections[] = 'intent_' . $bucket;
        }
    }

    // ------------------------------------------------------- ALL DATA TAB
    if (gsc_needs_sample($positionBands ?? [])) {
        $positionBands = gsc_sample_position_bands();
        $gscSampleSections[] = 'position_bands';
    }

    if (gsc_needs_sample($strikingDistance ?? [])) {
        $strikingDistance = gsc_sample_striking_distance();
        $gscSampleSections[] = 'striking_distance';
    }

    if (gsc_needs_sample($cannibalization ?? [])) {
        $cannibalization = gsc_sample_cannibalization();
        $gscSampleSections[] = 'cannibalization';
    }

    if (gsc_needs_sample($searchAppearanceRows ?? [])) {
        $searchAppearanceRows = gsc_sample_search_appearance();
        $gscSampleSections[] = 'search_appearance';
    }

    if (gsc_needs_sample($sitemapStatus ?? [])) {
        $sitemapStatus = gsc_sample_sitemap_status();
        $gscSampleSections[] = 'sitemap_status';
    }
}

// -------------------------------------------------------------------------
// Global flag - banner isse decide hota hai
// -------------------------------------------------------------------------
$gscShowingSample = !empty($gscSampleSections);

/**
 * Kya ye section sample data dikha raha hai?
 * Use: gsc_is_sample('brand'), gsc_is_sample('countries') ...
 */
function gsc_is_sample(string $section): bool
{
    global $gscSampleSections;
    return in_array($section, $gscSampleSections ?? [], true);
}

/**
 * Dashboard ke upar dikhne wala banner.
 *
 * DEPRECATED - returns nothing.
 *
 * partials/first-report-banner.php replaces it, included from dashboard.php
 * where this used to be called.
 *
 * Two reasons the partial is better than this string:
 *
 *   - It counts. This said "up to 48 hours" on day one and on day four
 *     alike, so a merchant returning on Thursday was told to wait two
 *     more days. The partial measures from verification_verified_at and
 *     says what is actually left, and once the window has passed it says
 *     that rather than counting into the negative.
 *
 *   - It links to why-first-report.php, which answers the question behind
 *     the banner: is something broken. One line of text cannot.
 *
 * Emptied rather than deleted so any remaining call site keeps working: a
 * fatal on a missing function is worse than a blank string, and two
 * banners saying nearly the same thing is worse than both.
 */
function gsc_sample_banner(): string
{
    return '';
}
