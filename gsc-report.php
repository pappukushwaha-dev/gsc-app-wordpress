<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/google/get_account.php';
require_once __DIR__ . '/includes/google/get_bigcommerce_site.php';

require_once __DIR__ . '/includes/plan_guard.php';

session_start();

$instanceId =
    $_SESSION['instance_id']
    ?? $_GET['instance']
    ?? $_GET['instance_id']
    ?? null;

if ($instanceId) {
    $_SESSION['instance_id'] = $instanceId;
}


if (!$instanceId) {
    header("Location: sign-in.php");
    exit();
}

$site = getWpSiteByInstance($instanceId);

if (!$site || empty($site['site_url'])) {
    die("BigCommerce store not connected properly.");
}

$siteUrl = rtrim($site['site_url'], '/');

$title = "Analytics Reports";
$subTitle = "Analytics Reports";
$google = getGoogleAccountByInstance($instanceId);

$isConnected = (
    $google &&
    (
        !empty($google['access_token']) ||
        !empty($google['refresh_token'])
    )
);


// Get plan info (non-fatal, only for UI)
$planAccess = getPlanAccessForPage();
$clientPlan = [
    'plan'    => $planAccess['plan'],
    'allowed' => (bool)$planAccess['allowed'],
];

include './partials/layouts/layoutTop.php';
?>

<script>
    // Expose plan summary to JS (NO instanceId)
    window._planAccess = <?= json_encode($clientPlan, JSON_UNESCAPED_SLASHES); ?>;
</script>
<div class="card h-full rounded-lg border-0 relative">
    <div class="card-body p-6">
        <div class="flex flex-col sm:flex-row justify-between items-center mb-6">
            <h6 class="text-xl font-semibold text-neutral-900 dark:text-white mb-4 sm:mb-0">Performance Report</h6>
            <div class="flex items-center gap-4">
                <div class="flex items-center space-x-2">
                    <span class="text-sm text-neutral-500 dark:text-gray-400">Range:</span>
                    <select id="date-range-filter" class="form-select rounded-lg leading-normal">
                        <option value="today">Today</option>
                        <option value="yesterday">Yesterday</option>
                        <option value="7days">Last 7 Days</option>
                        <option value="30days" selected>Last 30 Days</option>
                        <option value="lastmonth">Last Month</option>
                        <option value="90days">Last 90 Days</option>
                        <option value="12months">Last 12 Months</option>
                        <option value="365days">Last 365 Days</option>
                        <option value="lastyear">Last Year</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>

                <div id="custom-date-fields" class="hidden flex items-center gap-2">
                    <input type="date" id="start_date" class="form-input form-control">
                    <span class="text-neutral-400">-</span>
                    <input type="date" id="end_date" class="form-input form-control">
                    <button id="applyCustomBtn" class="btn btn-cstm-secondary">Apply</button>
                </div>

                <button id="refreshBtn" class="btn btn-cstm-primary">
                    <i class="fa-solid fa-arrows-rotate text-base text-white"></i>
                </button>
            </div>
        </div>

        <div class="relative flex justify-between items-start">

            <ul class="nav nav-tabs cstm-tab-style-gradient flex flex-wrap overflow-x-auto text-sm font-medium text-center mb-5" id="reportTabs" role="tablist">
                
                <!-- 1. Overview -->
                <li class="nav-item shrink-0">
                    <a href="javascript:void(0)" class="nav-link tab-btn active-tab py-2.5 px-4 border-b-4 font-semibold text-base inline-flex items-center gap-3 border-cstm-primary text-cstm-primary" data-type="keywords">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        Overview
                    </a>
                </li>
                
                <!-- 2. Commercial/Ecommerce query -->
                <li class="nav-item shrink-0">
                    <a href="javascript:void(0)"
                        class="nav-link tab-btn py-2.5 px-4 font-semibold text-base inline-flex items-center gap-3 text-neutral-500 hover:text-gray-600 dark:hover:text-gray-300"
                        data-type="intent_commercial">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                d="M3 3h7l11 11-7 7L3 10V3z" />
                            <circle cx="7.5" cy="7.5" r="1.5" fill="currentColor" />
                        </svg>
                        Commercial/Ecommerce
                    </a>
                </li>

                <!-- 3. Brand vs Non Brand -->
                <li class="nav-item shrink-0">
                    <a href="javascript:void(0)" class="nav-link tab-btn py-2.5 px-4 font-semibold text-base inline-flex items-center gap-3 text-neutral-500 hover:text-gray-600 dark:hover:text-gray-300" data-type="dates">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        Brand vs Non Brand
                    </a>
                </li>
                
                <!-- 4. Query by Page -->
                <li class="nav-item shrink-0">
                    <a href="javascript:void(0)" class="nav-link tab-btn py-2.5 px-4 font-semibold text-base inline-flex items-center gap-3 text-neutral-500 hover:text-gray-600 dark:hover:text-gray-300" data-type="qp">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                        </svg>
                        Top Pages
                    </a>
                </li>
                
                <!-- 5. Informational Query -->
                <li class="nav-item shrink-0">
                    <a href="javascript:void(0)"
                        class="nav-link tab-btn py-2.5 px-4 font-semibold text-base inline-flex items-center gap-3 text-neutral-500 hover:text-gray-600 dark:hover:text-gray-300"
                        data-type="intent_informational">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                d="M4 5a2 2 0 012-2h12v16H6a2 2 0 01-2-2V5z" />
                            <path stroke-width="2" stroke-linecap="round" d="M8 3v16" />
                        </svg>
                        Informational
                    </a>
                </li>
                
                <!-- 6. Pages -->
                <li class="nav-item shrink-0">
                    <a href="javascript:void(0)" class="nav-link tab-btn py-2.5 px-4 font-semibold text-base inline-flex items-center gap-3 text-neutral-500 hover:text-gray-600 dark:hover:text-gray-300" data-type="pages">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Pages
                    </a>
                </li>
                
                <!-- 7. Countries -->
                <li class="nav-item shrink-0">
                    <a href="javascript:void(0)" class="nav-link tab-btn py-2.5 px-4 font-semibold text-base inline-flex items-center gap-3 text-neutral-500 hover:text-gray-600 dark:hover:text-gray-300" data-type="countries">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Countries
                    </a>
                </li>
                
                <!-- 8. Devices -->
                <li class="nav-item shrink-0">
                    <a href="javascript:void(0)" class="nav-link tab-btn py-2.5 px-4 font-semibold text-base inline-flex items-center gap-3 text-neutral-500 hover:text-gray-600 dark:hover:text-gray-300" data-type="devices">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                        Devices
                    </a>
                </li>
                
                <!-- 9. Search Appearance -->
                <li class="nav-item shrink-0">
                    <a href="javascript:void(0)" class="nav-link tab-btn py-2.5 px-4 font-semibold text-base inline-flex items-center gap-3 text-neutral-500 hover:text-gray-600 dark:hover:text-gray-300" data-type="appearance">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
                        </svg>
                        Search Appearance
                    </a>
                </li>
                
                <!-- 10. Transactional Query -->
                <li class="nav-item shrink-0">
                    <a href="javascript:void(0)"
                        class="nav-link tab-btn py-2.5 px-4 font-semibold text-base inline-flex items-center gap-3 text-neutral-500 hover:text-gray-600 dark:hover:text-gray-300"
                        data-type="intent_transactional">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <circle cx="9" cy="20" r="1.5" />
                            <circle cx="17" cy="20" r="1.5" />
                            <path
                                d="M5 6h2l2 9h8l2-6H9"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        Transactional
                    </a>
                </li>

                <!-- Navigational (optional, keeping at end) -->
                <li class="nav-item shrink-0">
                    <a href="javascript:void(0)"
                        class="nav-link tab-btn py-2.5 px-4 font-semibold text-base inline-flex items-center gap-3 text-neutral-500 hover:text-gray-600 dark:hover:text-gray-300"
                        data-type="intent_navigational">
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
                        Navigational
                    </a>
                </li>

            </ul>

            <!-- <button id="toggleTabsBtn"
                class="hidden mt-2 text-sm font-semibold text-cstm-primary shrink-0 mobile_d_none" style="padding-left: 20px;">
                View More
            </button> -->

        </div>

        <div id="loader" class="hidden py-12 text-center">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-indigo-500 border-t-transparent"></div>
            <p class="mt-2 text-gray-500">Fetching data from Google...</p>
        </div>

        <div id="errorContainer" class="hidden mb-6 bg-danger-50 dark:bg-danger-600/20 border-l-4 border-danger-600 p-4 rounded-r-lg">
            <div class="flex gap-2">
                <div class="flex-shrink-0">
                    <i class="fa-solid fa-circle-xmark text-xl text-danger-600 dark:text-danger-600"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-danger-600 dark:text-danger-600" id="errorMsg">Error loading data.</p>
                </div>
            </div>
        </div>

        <div id="dashboardContent" class="space-y-8 opacity-0 transition-opacity duration-500">

            <div class="flex items-center rounded-lg overflow-x-auto w-full mb-6 tab_link_sticky bg-primary-400" role="group" id="stickyTabs"></div>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-10">
                <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 p-5">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Clicks</p>
                            <h6 class="text-2xl font-bold text-gray-900 dark:text-white mt-1" id="val-clicks">0</h6>
                        </div>
                        <span class="p-2 bg-primary-50 text-primary-600 rounded-lg dark:bg-primary-600/20 dark:text-primary-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"></path>
                            </svg>
                        </span>
                    </div>
                    <div id="spark-clicks" class="mt-4 -mx-2"></div>
                </div>
                <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 p-5">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Impressions</p>
                            <h6 class="text-2xl font-bold text-gray-900 dark:text-white mt-1" id="val-impr">0</h6>
                        </div>
                        <span class="p-2 bg-success-50 text-success-600 rounded-lg dark:bg-success-600/20 dark:text-success-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </span>
                    </div>
                    <div id="spark-impr" class="mt-4 -mx-2"></div>
                </div>
                <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 p-5">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Avg CTR</p>
                            <h6 class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><span id="val-ctr">0</span>%</h6>
                        </div>
                        <span class="p-2 bg-purple-50 text-purple-600 rounded-lg dark:bg-purple-600/20 dark:text-purple-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                        </span>
                    </div>
                    <div id="spark-ctr" class="mt-4 -mx-2"></div>
                </div>
                <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 p-5">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Avg Position</p>
                            <h6 class="text-2xl font-bold text-gray-900 dark:text-white mt-1" id="val-pos">0</h6>
                        </div>
                        <span class="p-2 bg-warning-50 text-warning-600 rounded-lg dark:bg-warning-600/20 dark:text-warning-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 002 2h2a2 2 0 002-2z"></path>
                            </svg>
                        </span>
                    </div>
                    <div id="spark-pos" class="mt-4 -mx-2"></div>
                </div>
            </div>

            <div id="brandSection" class="hidden tab_section">
                <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 p-6 mb-10">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-center">
                        <div>
                            <h6 class="font-bold text-gray-800 dark:text:white mb-1">Brand Impact Analysis</h6>
                            <p class="text-sm text-gray-500 mb-6">Distribution of traffic between your specific brand terms and generic queries.</p>
                            <div class="space-y-4">
                                <div class="flex justify-between items-center p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-100 dark:border-blue-800/30">
                                    <div class="flex items-center gap-3">
                                        <span class="w-3 h-3 rounded-full bg-[#487FFF]"></span>
                                        <span class="font-medium text-blue-900 dark:text-blue-200">Branded Clicks</span>
                                    </div>
                                    <span id="lbl-branded-clicks" class="font-bold text-lg text-blue-800 dark:text-blue-300">0</span>
                                </div>
                                <div class="flex justify-between items-center p-4 bg-gray-50 dark:bg-neutral-800 rounded-lg border border-gray-200 dark:border-gray-600">
                                    <div class="flex items-center gap-3">
                                        <span class="w-3 h-3 rounded-full bg-[#9CA3AF]"></span>
                                        <span class="font-medium text-gray-700 dark:text-blue-200">Non-Branded Clicks</span>
                                    </div>
                                    <span id="lbl-nonbranded-clicks" class="font-bold text-lg text-gray-800 dark:text-gray-200">0</span>
                                </div>
                            </div>
                        </div>
                        <div id="brand-donut-chart" class="flex justify-center"></div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10 tab_section" id="top_queries">
                <div id="" class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600">
                    <div class="px-6 py-4 border-b border-gray-100 dark:border-neutral-600 flex justify-between">
                        <h6 class="font-bold text-gray-800 dark:text-white" id="mainChartTitle">Performance Trends</h6>
                    </div>
                    <div class="p-6">
                        <div id="main-area-chart"></div>
                    </div>
                </div>

                <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600">
                    <div class="px-6 py-4 border-b border-gray-100 dark:border-neutral-600">
                        <h6 class="font-bold text-gray-800 dark:text-white">Analysis</h6>
                        <p class="text-xs text-gray-500" id="scatterSubtitle">Opportunity vs Performance</p>
                    </div>
                    <div class="p-6">
                        <div id="opportunity-chart"></div>
                    </div>
                </div>
            </div>

            <div id="detailed_report" class="mb-6 bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 overflow-hidden tab_section">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-neutral-600 flex justify-between items-center bg-gray-50 dark:bg-neutral-900">
                    <h6 class="font-bold text-gray-800 dark:text-white" id="tableTitle">Detailed Report</h6>
                    <div class="flex items-center gap-2">
                        <button id="downloadCsvBtn" title="Download this report as CSV" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-neutral-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 transition-colors">
                            <iconify-icon icon="solar:download-minimalistic-bold" class="text-base"></iconify-icon>
                            Download CSV
                        </button>
                    <span class="text-xs bg-white dark:bg-neutral-800 border dark:border-neutral-600 px-2 py-1 rounded text-gray-500" id="total-rows-badge">0 Rows</span>
                    </div>
                </div>

                <div class="overflow-x-auto p-6">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-neutral-700 table table-auto">
                        <thead class="bg-gray-50 dark:bg-neutral-800">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider" id="col-key">Metric</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Clicks</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Impr.</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">CTR</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Pos</th>
                            </tr>
                        </thead>
                        <tbody id="table-body" class="table-body-section bg-white dark:bg-neutral-800 divide-y divide-gray-200 dark:divide-neutral-700">
                        </tbody>
                    </table>
                </div>

                <div class="px-6 py-4 border-t border-gray-200 dark:border-neutral-600 flex items-center justify-between bg-gray-50 dark:bg-neutral-900">
                    <span class="text-sm text-gray-500 dark:text-gray-400" id="pagination-info">Showing 0 to 0 of 0 entries</span>
                    <div class="flex gap-2">
                        <button id="prevPageBtn" class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-neutral-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-neutral-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Previous
                        </button>
                        <button id="nextPageBtn" class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-neutral-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-neutral-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Next
                        </button>
                    </div>
                </div>
            </div>

            <!-- Position bands -->
            <div id="positionBandsSection" class="mb-6 bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 hidden tab_section">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-neutral-600 flex justify-between items-center">
                    <h6 class="font-bold text-gray-800 dark:text-white">Keywords grouped by position band</h6>
                </div>
                <div class="p-6 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-neutral-700 table table-auto">
                        <thead class="bg-gray-50 dark:bg-neutral-800">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Band</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Keywords</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Impressions</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Clicks</th>
                            </tr>
                        </thead>
                        <tbody id="positionBandsBody" class="table-main-body bg-white dark:bg-neutral-800 divide-y divide-gray-200 dark:divide-neutral-700"></tbody>
                    </table>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-neutral-600 flex items-center justify-between bg-gray-50 dark:bg-neutral-900 common-pagination">
                    <span class="text-sm text-gray-500 dark:text-gray-400 pagination-info">Showing 0 to 0 of 0 entries</span>
                    <div class="flex gap-2">
                        <button class="prevPageBtn px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-neutral-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-neutral-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Previous
                        </button>
                        <button class="nextPageBtn px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-neutral-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-neutral-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Next
                        </button>
                    </div>
                </div>
            </div>

            <!-- Striking distance -->
            <div id="strikingSection" class="mb-6 bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 hidden tab_section">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-neutral-600 flex justify-between items-center">
                    <h6 class="font-bold text-gray-800 dark:text-white">Striking distance</h6>
                    <p class="text-xs text-gray-500">Keywords currently ranking in positions 11–20.</p>
                </div>
                <div class="p-6 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-neutral-700 table table-auto">
                        <thead class="bg-gray-50 dark:bg-neutral-800">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Keyword</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Impressions</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Clicks</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">CTR</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Position</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Band</th>
                            </tr>
                        </thead>
                        <tbody id="strikingBody" class="table-main-body bg-white dark:bg-neutral-800 divide-y divide-gray-200 dark:divide-neutral-700"></tbody>
                    </table>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-neutral-600 flex items-center justify-between bg-gray-50 dark:bg-neutral-900 common-pagination">
                    <span class="text-sm text-gray-500 dark:text-gray-400 pagination-info">Showing 0 to 0 of 0 entries</span>
                    <div class="flex gap-2">
                        <button class="prevPageBtn px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-neutral-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-neutral-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Previous
                        </button>
                        <button class="nextPageBtn px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-neutral-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-neutral-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Next
                        </button>
                    </div>
                </div>
            </div>

        </div>

        <!-- Brand / Non-brand -->
        <div id="brandKeywordsSection" class="grid grid-cols-1 xl:grid-cols-2 gap-6 hidden">
            <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-neutral-600 flex justify-between items-center">
                    <h6 class="font-bold text-gray-800 dark:text-white">Brand keywords</h6>
                </div>
                <div class="p-6 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-neutral-700 table table-auto">
                        <thead class="bg-gray-50 dark:bg-neutral-800">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Keyword</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Impressions</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Clicks</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Position</th>
                            </tr>
                        </thead>
                        <tbody id="brandKeywordsBody" class="table-main-body bg-white dark:bg-neutral-800 divide-y divide-gray-200 dark:divide-neutral-700"></tbody>
                    </table>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-neutral-600 flex items-center justify-between bg-gray-50 dark:bg-neutral-900 common-pagination">
                    <span class="text-sm text-gray-500 dark:text-gray-400 pagination-info">Showing 0 to 0 of 0 entries</span>
                    <div class="flex gap-2">
                        <button class="prevPageBtn px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-neutral-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-neutral-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Previous
                        </button>
                        <button class="nextPageBtn px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-neutral-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-neutral-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Next
                        </button>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600">
                <div class="px-6 py-4 border-b border-gray-100 dark:border-neutral-600 flex justify-between items-center">
                    <h6 class="font-bold text-gray-800 dark:text-white">Non-brand keywords</h6>
                </div>
                <div class="p-6 overflow-x-auto cstm-scroll-sm">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-neutral-700 table table-auto">
                        <thead class="bg-gray-50 dark:bg-neutral-800">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Keyword</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Impressions</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Clicks</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Position</th>
                            </tr>
                        </thead>
                        <tbody id="nonBrandKeywordsBody" class="table-main-body bg-white dark:bg-neutral-800 divide-y divide-gray-200 dark:divide-neutral-700"></tbody>
                    </table>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-neutral-600 flex items-center justify-between bg-gray-50 dark:bg-neutral-900 common-pagination">
                    <span class="text-sm text-gray-500 dark:text-gray-400 pagination-info">Showing 0 to 0 of 0 entries</span>
                    <div class="flex gap-2">
                        <button class="prevPageBtn px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-neutral-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-neutral-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Previous
                        </button>
                        <button class="nextPageBtn px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-neutral-800 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-neutral-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                            Next
                        </button>
                    </div>
                </div>
            </div>

        </div>

        <!-- Cannibalization (for Query + Page tab) -->
        <div id="cannibalSection" class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 hidden">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-neutral-600 flex justify-between items-center">
                <h6 class="font-bold text-gray-800 dark:text-white">Cannibalization</h6>
                <p class="text-xs text-gray-500">Keywords that trigger impressions for multiple pages.</p>
            </div>
            <div class="p-6 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-neutral-700 table table-auto">
                    <thead class="bg-gray-50 dark:bg-neutral-800">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Keyword</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Pages</th>
                            <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Gap</th>
                        </tr>
                    </thead>
                    <tbody id="cannibalBody" class="bg-white dark:bg-neutral-800 divide-y divide-gray-200 dark:divide-neutral-700"></tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- PLAN LOCK OVERLAY -->
    <div id="plan-lock-overlay" class="hidden absolute inset-0 z-10 flex items-center justify-center bg-white/80 dark:bg-neutral-900/80 backdrop-blur-sm">
        <div class="max-w-md mx-4 p-6 rounded-2xl bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-700 shadow-xl">
            <div class="flex items-center gap-2 mb-3">
                <span class="inline-flex items-center justify-start rounded-full bg-cstm-primary/10 text-cstm-primary">
                    <i class="fa-solid fa-lock text-base"></i>
                </span>
                <div>
                    <h3 class="text-lg font-semibold text-neutral-900 dark:text-white">Analytics locked</h3>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400">
                        This report is available only for active paid plans or accounts with an active free trial.
                    </p>
                </div>
            </div>

            <ul class="ps-4 text-sm text-neutral-600 dark:text-neutral-300 mb-4 list-disc list-inside space-y-1">
                <li>Unlock advanced Performance</li>
                <li>Countries & Devices insights</li>
                <li>Get detailed brand vs non-brand segmentation</li>
                <li>See striking distance keywords and cannibalization opportunities</li>
                <li>And much more...</li>
            </ul>

            <div class="flex flex-wrap gap-3 items-center">
                <a href="/current-plan.php"
                    class="inline-flex items-center justify-center px-4 py-2 rounded-lg text-sm font-medium bg-cstm-primary text-white hover:bg-cstm-primary/90">
                    View plans & upgrade
                    <i class="fa-solid fa-arrow-right text-xs ml-2"></i>
                </a>
                <button type="button"
                    id="plan-lock-dismiss"
                    class="text-xs text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200 underline">
                    Continue exploring other features
                </button>
            </div>
        </div>
    </div>
    <!-- END PLAN LOCK OVERLAY -->

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script src="<?= APP_BASE ?>/assets/js/AnalyticsChart.js"></script>

<script>
    const instanceId = "<?= $instanceId ?>";
    const appBase = "<?= APP_BASE ?>";
    let currentType = 'keywords';
    const INTENT_REGEX = {
    intent_informational: '(who|what|where|when|why|how|can|is|are|do|does)',
    intent_commercial: '(buy|purchase|order|checkout|shop|online|store|sale|discount|coupon|promo|code|clearance|deals?|cheap|affordable|price|cost|get)',
    intent_transactional: '(buy|purchase|order|checkout|subscribe|sign up|signup|register|download|install|book|booking|trial|free trial)',
    intent_navigational: '(login|sign in|signin|account|dashboard|portal|official|website|homepage|contact|support|helpdesk)',
    ecommerce_ready_to_buy: '(buy|order|purchase|price|cheap|discount|deal|sale|coupon|shop|store)',
    ecommerce_product_research: '(size|color|spec|specs|specification|model|material|dimension|weight)',
    ecommerce_trust_comparison: '(review|reviews|rating|ratings|vs|versus|comparison|best|top)',
    ecommerce_post_purchase: '(shipping|delivery|tracking|return|refund|warranty|cancel|exchange|support)'
};

    // Plan info from PHP
    const planAccess = window._planAccess || {
        plan: {
            type: 'none',
            status: 'none',
            trialExists: false,
            trialActive: false,
            trialExpired: false
        },
        allowed: false
    };
    const COUNTRY_MAP_ALPHA3 = {
        usa: "United States",
        ind: "India",
        gbr: "United Kingdom",
        are: "United Arab Emirates",
        aus: "Australia",
        can: "Canada",
        bra: "Brazil",
        mex: "Mexico",
        ven: "Venezuela",
        per: "Peru",
        chl: "Chile",
        col: "Colombia",
        zaf: "South Africa",
        nga: "Nigeria",
        egy: "Egypt",
        ken: "Kenya",
        isr: "Israel",
        lbn: "Lebanon",
        sau: "Saudi Arabia",
        qat: "Qatar",
        kwt: "Kuwait",
        omn: "Oman",
        bhr: "Bahrain",
        tur: "Turkey",
        irn: "Iran",
        irq: "Iraq",
        afg: "Afghanistan",
        pak: "Pakistan",
        bgd: "Bangladesh",
        npl: "Nepal",
        lka: "Sri Lanka",
        phl: "Philippines",
        mys: "Malaysia",
        vnm: "Vietnam",
        tha: "Thailand",
        idn: "Indonesia",
        jpn: "Japan",
        kor: "South Korea",
        chn: "China",
        sgp: "Singapore",
        hkg: "Hong Kong",
        twn: "Taiwan",
        deu: "Germany",
        fra: "France",
        esp: "Spain",
        ita: "Italy",
        pol: "Poland",
        nld: "Netherlands",
        swe: "Sweden",
        nor: "Norway",
        fin: "Finland",
        dnk: "Denmark",
        irl: "Ireland",
        che: "Switzerland",
        aut: "Austria",
        bel: "Belgium",
    };

    const STICKY_TAB_CONFIG = {
        keywords: [{
                id: "brandSection",
                label: "Brand Impact Analysis"
            },
            {
                id: "top_queries",
                label: "Top Queries and Analysis"
            },
            {
                id: "detailed_report",
                label: "Detailed Report"
            },
            {
                id: "positionBandsSection",
                label: "Keywords Grouped"
            },
            {
                id: "strikingSection",
                label: "Striking Distance"
            },
            {
                id: "brandKeywordsSection",
                label: "Brand vs Non-Brand"
            },
        ],

        pages: [{
                id: "top_queries",
                label: "Top Pages and Analysis"
            },
            {
                id: "detailed_report",
                label: "Detailed Report"
            },
        ],

        countries: [{
                id: "top_queries",
                label: "Countries Breakdown and Analysis"
            },
            {
                id: "detailed_report",
                label: "Detailed Report"
            },
        ],

        devices: [{
                id: "top_queries",
                label: "Device Breakdown and Analysis"
            },
            {
                id: "detailed_report",
                label: "Detailed Report"
            },
        ],

        appearance: [{
                id: "top_queries",
                label: "Performance and Analysis"
            },
            {
                id: "detailed_report",
                label: "Detailed Report"
            },
        ],

        dates: [{
                id: "brandSection",
                label: "Brand Impact"
            },
            {
                id: "top_queries",
                label: "Performance and Analysis"
            },
            {
                id: "detailed_report",
                label: "Detailed Report"
            },
            {
                id: "positionBandsSection",
                label: "Position Bands"
            },
            {
                id: "brandKeywordsSection",
                label: "Brand vs Non-Brand"
            },
        ],

        qp: [{
                id: "top_queries",
                label: "Query / Page Analysis"
            },
            {
                id: "detailed_report",
                label: "Detailed Report"
            },
            {
                id: "cannibalSection",
                label: "Cannibalization"
            },
        ],

        intent_informational: [{
                id: "top_queries",
                label: "Informational Queries Analysis"
            },
            {
                id: "detailed_report",
                label: "Detailed Report"
            }
        ],

        intent_commercial: [{
                id: "top_queries",
                label: "Commercial Queries Analysis"
            },
            {
                id: "detailed_report",
                label: "Detailed Report"
            }
        ],

        intent_transactional: [{
                id: "top_queries",
                label: "Transactional Queries Analysis"
            },
            {
                id: "detailed_report",
                label: "Detailed Report"
            }
        ],

        intent_navigational: [{
                id: "top_queries",
                label: "Navigational Queries Analysis"
            },
            {
                id: "detailed_report",
                label: "Detailed Report"
            }
        ],
    };

    // Pagination State
    let allRows = [];
    let currentPage = 1;
    const rowsPerPage = 10;

    function applyPlanLockUI() {
        const dashboard = document.getElementById('dashboardContent');
        const loader = document.getElementById('loader');
        const overlay = document.getElementById('plan-lock-overlay');
        const errorBox = document.getElementById('errorContainer');
        const dismiss = document.getElementById('plan-lock-dismiss');

        if (!dashboard || !overlay) return;

        if (!planAccess.allowed) {
            dashboard.classList.add('opacity-40', 'pointer-events-none');
            if (loader) loader.classList.add('hidden');
            if (errorBox) errorBox.classList.add('hidden');
            overlay.classList.remove('hidden');

            if (dismiss) {
                dismiss.addEventListener('click', () => {
                    overlay.classList.add('hidden');
                });
            }
        } else {
            dashboard.classList.remove('opacity-40', 'pointer-events-none');
            overlay.classList.add('hidden');
        }
    }

    document.addEventListener("DOMContentLoaded", () => {
        const dateRangeSelect = document.getElementById('date-range-filter');
        const customDateFields = document.getElementById('custom-date-fields');
        const applyCustomBtn = document.getElementById('applyCustomBtn');
        const refreshBtn = document.getElementById('refreshBtn');
        const tabs = document.querySelectorAll('.tab-btn');
        const prevBtn = document.getElementById('prevPageBtn');
        const nextBtn = document.getElementById('nextPageBtn');

        // Apply lock immediately
        applyPlanLockUI();

        // If not allowed, don't attach heavy handlers / fetch data
        if (!planAccess.allowed) {
            return;
        }

        // --- UI Handlers ---
        dateRangeSelect.addEventListener('change', (e) => {
            if (e.target.value === 'custom') {
                customDateFields.classList.remove('hidden');
            } else {
                customDateFields.classList.add('hidden');
                fetchData();
            }
        });

        applyCustomBtn.addEventListener('click', () => fetchData());
        refreshBtn.addEventListener('click', () => fetchData());

        // --- Tab Switching ---
        tabs.forEach(tab => {
            tab.addEventListener('click', (e) => {
                e.preventDefault();

                // Reset tabs styles
                tabs.forEach(t => {
                    t.classList.remove('active-tab', 'text-cstm-primary', 'border-cstm-primary', 'border-b-4');
                });

                // Activate clicked tab
                const btn = e.currentTarget;
                btn.classList.add('active-tab', 'text-cstm-primary', 'border-cstm-primary', 'border-b-4');
                btn.classList.remove('hover:text-gray-600');

                currentType = btn.dataset.type;
                fetchData();

                // --- Sync sidebar menu ---
                const sidebarLink = document.querySelector(`.sidebar-tab-link[data-tab="${currentType}"]`);
                if (sidebarLink) {
                    // Remove active-page from all links and li
                    document.querySelectorAll(".sidebar-submenu li, .sidebar-submenu li a").forEach(el => {

                        el.classList.remove("active-page", "show", "open");
                    });

                    // Add active-page to sidebar link and parent li
                    sidebarLink.classList.add("active-page");
                    const parentLi = sidebarLink.closest("li");
                    if (parentLi) {
                        parentLi.classList.add("active-page", "show", "open");
                    }
                }
            });
        });

        // ----- START - GSC sidebar tab links --------
        const isGSCPage = window.location.pathname.includes("gsc-report.php");

        if (isGSCPage) {
            const urlParams = new URLSearchParams(window.location.search);
            const initialTab = urlParams.get("tab");

            if (initialTab) {
                const initialBtn = document.querySelector(`.tab-btn[data-type="${initialTab}"]`);
                if (initialBtn) initialBtn.click();
                currentType = initialTab;
                const link = document.querySelector(`.sidebar-tab-link[data-tab="${initialTab}"]`);
                if (link) {
                    document.querySelectorAll(".sidebar-submenu li, .sidebar-submenu li a")
                        .forEach(el => el.classList.remove("active-page", "show", "open"));

                    link.classList.add("active-page");
                    const parentLi = link.closest("li");
                    if (parentLi) {
                        parentLi.classList.add("active-page", "show", "open");
                    }
                }
            }
            const sidebarTabLinks = document.querySelectorAll(".sidebar-tab-link");
            sidebarTabLinks.forEach(link => {
                link.addEventListener("click", (e) => {
                    e.preventDefault();

                    const tab = link.dataset.tab;
                    if (!tab) return;

                    currentType = tab;
                    const tabBtn = document.querySelector(`.tab-btn[data-type="${tab}"]`);
                    if (tabBtn) tabBtn.click();
                    history.pushState(null, "", `?tab=${tab}`);
                    document.querySelectorAll(".sidebar-submenu li, .sidebar-submenu li a")
                        .forEach(el => el.classList.remove("active-page", "show", "open"));

                    link.classList.add("active-page");
                    const parentLi = link.closest("li");
                    if (parentLi) {
                        parentLi.classList.add("active-page", "show", "open");
                    }
                });
            });
        }
        // ----- END - GSC sidebar tab links --------

        // --- Pagination Handlers ---
        prevBtn.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                renderTable();
            }
        });

        nextBtn.addEventListener('click', () => {
            const maxPage = Math.ceil(allRows.length / rowsPerPage);
            if (currentPage < maxPage) {
                currentPage++;
                renderTable();
            }
        });

        // Initial fetch
        fetchData();
    });

    async function fetchData() {
        if (!planAccess.allowed) {
            applyPlanLockUI();
            return;
        }

        // UI States
        document.getElementById('dashboardContent').classList.add('opacity-50', 'pointer-events-none');
        document.getElementById('loader').classList.remove('hidden');
        document.getElementById('errorContainer').classList.add('hidden');

        const range = document.getElementById('date-range-filter').value;
        let startDate = null;
        let endDate = null;

        if (range === 'custom') {
            startDate = document.getElementById('start_date').value;
            endDate = document.getElementById('end_date').value;
            if (!startDate || !endDate) {
                alert("Please select both start and end dates.");
                document.getElementById('loader').classList.add('hidden');
                document.getElementById('dashboardContent').classList.remove('opacity-50', 'pointer-events-none');
                return;
            }
        }

        try {
            let apiUrl = `${appBase}/api/google/get_gsc_report.php`;
            let payload = {
                instanceId,
                range,
                type: (currentType === 'dates' ? 'keywords' : currentType),
                start: startDate,
                end: endDate
            };

            /* 🔥 INTENT TABS → REGEX API */
            if (currentType.startsWith('intent_')) {
                apiUrl = `${appBase}/api/google/get_gsc_regex.php`;
                payload = {
                    instanceId,
                    regex: INTENT_REGEX[currentType],
                    dimension: 'query',
                    range
                };
            }

            const res = await fetch(apiUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify(payload)
            });


            const data = await res.json();

            if (!data.success) {
                // Backend plan denial
                if (data.message === 'Feature not available for current plan') {
                    document.getElementById('errorMsg').innerText = "This report is only available on paid plans or active trials.";
                    document.getElementById('errorContainer').classList.remove('hidden');
                    applyPlanLockUI();
                    return;
                }
                throw new Error(data.error || data.message || "Unknown API Error");
            }

            updateDashboard(data);
            renderStickyTabs();


        } catch (err) {
            document.getElementById('errorMsg').innerText = err.message;
            document.getElementById('errorContainer').classList.remove('hidden');
        } finally {
            document.getElementById('loader').classList.add('hidden');
            document.getElementById('dashboardContent').classList.remove('opacity-50', 'pointer-events-none');
            document.getElementById('dashboardContent').classList.remove('opacity-0');
        }
    }

    function updateDashboard(data) {
        if (currentType.startsWith('intent_')) {
            document.getElementById('brandSection')?.classList.add('hidden');
            document.getElementById('positionBandsSection')?.classList.add('hidden');
            document.getElementById('strikingSection')?.classList.add('hidden');
            document.getElementById('brandKeywordsSection')?.classList.add('hidden');
        }
        // 1. Update Top Stats Cards
        const m = data.metrics;
        document.getElementById('val-clicks').innerText = m.clicks.toLocaleString();
        document.getElementById('val-impr').innerText = m.impressions.toLocaleString();
        document.getElementById('val-ctr').innerText = m.ctr;
        document.getElementById('val-pos').innerText = m.position;

        // 2. BRAND SECTION LOGIC (Only visible for Keywords tab)
        const brandSection = document.getElementById('brandSection');
        if ((currentType === 'keywords' || currentType === 'dates') && m.brand_split) {
            brandSection.classList.remove('hidden');

            // Update Text Stats
            document.getElementById('lbl-branded-clicks').innerText = m.brand_split.branded_clicks.toLocaleString();
            document.getElementById('lbl-nonbranded-clicks').innerText = m.brand_split.non_branded_clicks.toLocaleString();

            // Render Donut Chart
            AnalyticsCharts.renderDonutChart(
                '#brand-donut-chart',
                [m.brand_split.branded_clicks, m.brand_split.non_branded_clicks],
                ['Branded', 'Non-Branded'],
                ['#487FFF', '#9CA3AF']
            );
        } else {
            brandSection.classList.add('hidden');
        }

        // 3. Store and Sort Data
        allRows = data.rows || [];

        if (currentType === 'dates') {
            // Sort by Date Ascending
            allRows.sort((a, b) => new Date(a.keys[0]) - new Date(b.keys[0]));
        } else {
            // Sort by Clicks Descending
            allRows.sort((a, b) => b.clicks - a.clicks);
        }

        // 4. Render Charts (using top slice for readability)
        const topSlice = allRows.slice(0, 15);
        const categories = topSlice.map(r => r.keys[0]);
        const clicksData = topSlice.map(r => r.clicks);
        const imprData = topSlice.map(r => r.impressions);

        // --- Render Sparklines ---
        AnalyticsCharts.renderSparkline('#spark-clicks', clicksData, '#487FFF');
        AnalyticsCharts.renderSparkline('#spark-impr', imprData, '#45B369');
        AnalyticsCharts.renderSparkline('#spark-ctr', topSlice.map(r => r.ctr), '#8252e9');
        AnalyticsCharts.renderSparkline('#spark-pos', topSlice.map(r => r.position), '#f4941e');

        // --- Render Main Chart ---
        const chartTitles = {
            'dates': 'Performance over Time',
            'keywords': 'Top Queries',
            'pages': 'Top Pages',
            'devices': 'Device Breakdown',
            'countries': 'Countries Breakdown',
            'qp': 'Query/Page Analysis'
        };
        document.getElementById('mainChartTitle').innerText = chartTitles[currentType] || 'Performance';

        if (currentType === 'devices' || currentType === 'appearance') {
            AnalyticsCharts.renderBarChart('#main-area-chart', categories, clicksData, '#487FFF');
        } else {
            AnalyticsCharts.renderMainChart('#main-area-chart', categories, clicksData, imprData);
        }

        // --- Render Opportunity Chart ---
        if (currentType === 'keywords' || currentType === 'pages' || currentType === 'qp') {
            const scatterData = allRows
                .filter(r => r.impressions > 20)
                .slice(0, 30)
                .map(r => ({
                    x: r.position,
                    y: r.ctr,
                    term: r.keys[0]
                }));

            AnalyticsCharts.renderOpportunityChart('#opportunity-chart',
                scatterData);
            document.getElementById('scatterSubtitle').innerText = "High CTR vs Low Position";
        } else {
            AnalyticsCharts.renderBarChart('#opportunity-chart', categories.slice(0, 5), imprData.slice(0, 5), '#45B369');
            document.getElementById('scatterSubtitle').innerText = "Top 5 by Impressions";
        }

        currentPage = 1;
        document.getElementById('total-rows-badge').innerText = allRows.length + " Rows";
        renderTable();

        // 6. Advanced reports (new)
        updateAdvancedReports(data.extra || {});
    }

    /* ------------------------------------------------------------
       DOWNLOAD CSV

       Exports the rows the report currently holds (allRows), not just
       the five on the visible page. Built in the browser - no extra
       server call - so it always matches the tab, date range and rows
       on screen. A BOM is prepended so Excel opens Hindi and other
       non-ASCII queries correctly.
    ------------------------------------------------------------ */
    document.getElementById('downloadCsvBtn').addEventListener('click', () => {
        if (!allRows.length) {
            alert('No data to download yet.');
            return;
        }

        const keyHeaders = {
            'keywords': 'Query',
            'pages': 'Page URL',
            'countries': 'Country',
            'devices': 'Device',
            'dates': 'Date',
            'qp': 'Query',
            'appearance': 'Type'
        };
        const keyHeader = keyHeaders[currentType] || 'Dimension';

        const header = [keyHeader];
        if (currentType === 'qp') header.push('Page');
        header.push('Clicks', 'Impressions', 'CTR (%)', 'Position');
        if (currentType === 'keywords') header.push('Brand');

        const rows = [header];
        allRows.forEach(r => {
            let keyDisplay = r.keys[0];
            if (currentType === 'countries') {
                const code = String(r.keys[0]).toLowerCase();
                keyDisplay = COUNTRY_MAP_ALPHA3[code] || code.toUpperCase();
            }

            const line = [keyDisplay];
            if (currentType === 'qp') line.push(r.keys[1] || '');
            line.push(r.clicks, r.impressions, r.ctr, r.position);
            if (currentType === 'keywords') line.push(r.isBranded ? 'Yes' : '');
            rows.push(line);
        });

        const escapeCell = v => {
            const s = String(v ?? '');
            return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
        };
        const csv = rows.map(line => line.map(escapeCell).join(',')).join('\r\n');

        const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `gsc-${currentType}-report-${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });

// --- downloadCsv END ---
    function renderTable() {
        const tbody = document.getElementById('table-body');
        tbody.innerHTML = "";

        // Update Header text
        const headers = {
            'keywords': 'Query',
            'pages': 'Page URL',
            'countries': 'Country',
            'devices': 'Device',
            'dates': 'Date',
            'qp': 'Query / Page',
            'appearance': 'Type',
            'intent_informational': 'Query',
            'intent_commercial': 'Query',
            'intent_transactional': 'Query',
            'intent_navigational': 'Query'
        };
        document.getElementById('col-key').innerText = headers[currentType] || 'Dimension';

        // Pagination logic
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;
        const pageRows = allRows.slice(start, end);
        const totalRows = allRows.length;

        // Update Info Text
        document.getElementById('pagination-info').innerText = `Showing ${totalRows === 0 ? 0 : start + 1} to ${Math.min(end, totalRows)} of ${totalRows} entries`;

        // Update Buttons
        document.getElementById('prevPageBtn').disabled = currentPage === 1;
        document.getElementById('nextPageBtn').disabled = end >= totalRows;

        pageRows.forEach(row => {
            const tr = document.createElement('tr');
            tr.className = "hover:bg-gray-50 dark:hover:bg-neutral-700/50 transition-colors";

            // Handle Key Formatting
            let keyDisplay = row.keys[0];

            if (currentType === 'countries') {
                const code = row.keys[0].toLowerCase();
                keyDisplay = COUNTRY_MAP_ALPHA3[code] || code.toUpperCase();
            }


            // Add Brand Badge logic
            if (currentType === 'keywords' && row.isBranded) {
                keyDisplay = `<div class="flex items-center gap-2">
                    <span>${row.keys[0]}</span>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 uppercase tracking-wide">Brand</span>
                </div>`;
            }

            if (currentType === 'qp' && row.keys[1]) {
                keyDisplay = `<div class="flex flex-col">
                    <span class="font-medium text-indigo-600 dark:text-indigo-400 truncate max-w-xs" title="${row.keys[0]}">${row.keys[0]}</span>
                    <span class="text-xs text-gray-400 truncate max-w-xs" title="${row.keys[1]}">${row.keys[1]}</span>
                </div>`;
            } else if (currentType === 'pages') {
                keyDisplay = `<a href="${row.keys[0]}" target="_blank" class="text-indigo-600 hover:underline truncate max-w-xs block" title="${row.keys[0]}">${row.keys[0]}</a>`;
            }

            tr.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">${keyDisplay}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500 dark:text-gray-300">${row.clicks.toLocaleString()}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500 dark:text-gray-300">${row.impressions.toLocaleString()}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500 dark:text-gray-300">${row.ctr}%</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500 dark:text-gray-300">${row.position}</td>
            `;
            tbody.appendChild(tr);
        });
    }


    function renderStickyTabs() {
        const container = document.getElementById("stickyTabs");
        if (!container) return;

        container.innerHTML = "";

        const config = STICKY_TAB_CONFIG[currentType] || [];

        config.forEach(item => {
            const section = document.getElementById(item.id);

            // Only render if section exists & is visible
            if (!section || section.classList.contains("hidden")) return;

            const a = document.createElement("a");
            a.href = `#${item.id}`;
            a.textContent = item.label;
            a.className =
                "bg-primary-400 hover:bg-primary-600 px-5 py-[11px] text-white shrink-0 transition";

            container.appendChild(a);
        });

        // Hide container if nothing to show
        container.classList.toggle("hidden", container.children.length === 0);
    }



    // ------------------------------------
    // Shared helper: Brand / Non-brand tables
    // ------------------------------------
    function showBrandTables(brandKeywords, nonBrandKeywords, brandSec, brandBody, nonBrandBody) {
        if (!brandKeywords.length && !nonBrandKeywords.length) return;

        brandSec.classList.remove('hidden');

        brandKeywords.slice(0, 20).forEach(r => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
            <td class="px-4 py-2 text-sm">${r.keys[0]}</td>
            <td class="px-4 py-2 text-sm text-right">${r.impressions.toLocaleString()}</td>
            <td class="px-4 py-2 text-sm text-right">${r.clicks.toLocaleString()}</td>
            <td class="px-4 py-2 text-sm text-right">${r.position}</td>
        `;
            brandBody.appendChild(tr);
        });

        nonBrandKeywords.slice(0, 20).forEach(r => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
            <td class="px-4 py-2 text-sm">${r.keys[0]}</td>
            <td class="px-4 py-2 text-sm text-right">${r.impressions.toLocaleString()}</td>
            <td class="px-4 py-2 text-sm text-right">${r.clicks.toLocaleString()}</td>
            <td class="px-4 py-2 text-sm text-right">${r.position}</td>
        `;
            nonBrandBody.appendChild(tr);
        });
    }

    function updateAdvancedReports(extra) {
        // DOM refs
        const posSection = document.getElementById('positionBandsSection');
        const posBody = document.getElementById('positionBandsBody');
        const strikingSec = document.getElementById('strikingSection');
        const strikingBody = document.getElementById('strikingBody');
        const brandSec = document.getElementById('brandKeywordsSection');
        const brandBody = document.getElementById('brandKeywordsBody');
        const nonBrandBody = document.getElementById('nonBrandKeywordsBody');
        const cannibalSec = document.getElementById('cannibalSection');
        const cannibalBody = document.getElementById('cannibalBody');

        // Clear everything + hide by default
        [posBody, strikingBody, brandBody, nonBrandBody, cannibalBody].forEach(t => t.innerHTML = '');
        [posSection, strikingSec, brandSec, cannibalSec].forEach(s => s.classList.add('hidden'));

        const positionBands = extra.position_bands || [];
        const strikingDistance = extra.striking_distance || [];
        const brandKeywords = extra.brand_keywords || [];
        const nonBrandKeywords = extra.non_brand_keywords || [];
        const cannibalization = extra.cannibalization || [];

        // Only show these for the Keywords tab
        // -----------------------------
        // -----------------------------
        // KEYWORDS TAB (FULL SEO FEATURES)
        // -----------------------------
        if (currentType === 'keywords' || currentType === 'dates') {

            // Position bands
            if (positionBands.length) {
                posSection.classList.remove('hidden');
                positionBands.forEach(b => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                <td class="px-4 py-2">${b.band}</td>
                <td class="px-4 py-2 text-right">${b.rows.toLocaleString()}</td>
                <td class="px-4 py-2 text-right">${b.impressions.toLocaleString()}</td>
                <td class="px-4 py-2 text-right">${b.clicks.toLocaleString()}</td>
            `;
                    posBody.appendChild(tr);
                });
            }

            // Striking distance
            if (strikingDistance.length) {
                strikingSec.classList.remove('hidden');
                strikingDistance.slice(0, 20).forEach(r => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                <td class="px-4 py-2">${r.keys[0]}</td>
                <td class="px-4 py-2 text-right">${r.impressions.toLocaleString()}</td>
                <td class="px-4 py-2 text-right">${r.clicks.toLocaleString()}</td>
                <td class="px-4 py-2 text-right">${r.ctr}%</td>
                <td class="px-4 py-2 text-right">${r.position}</td>
                <td class="px-4 py-2 text-right">${r.position_band}</td>
            `;
                    strikingBody.appendChild(tr);
                });
            }

            // ✅ Brand / Non-brand keywords
            showBrandTables(
                brandKeywords,
                nonBrandKeywords,
                brandSec,
                brandBody,
                nonBrandBody
            );
        }


        // ------------------------------------
        // BRAND vs NON-BRAND TAB (dates)
        // ------------------------------------
        if (currentType === 'dates') {

            // Position bands
            if (positionBands.length) {
                posSection.classList.remove('hidden');
                positionBands.forEach(b => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                <td class="px-4 py-2">${b.band}</td>
                <td class="px-4 py-2 text-right">${b.rows.toLocaleString()}</td>
                <td class="px-4 py-2 text-right">${b.impressions.toLocaleString()}</td>
                <td class="px-4 py-2 text-right">${b.clicks.toLocaleString()}</td>
            `;
                    posBody.appendChild(tr);
                });
            }

            // Brand / Non-brand ONLY
            showBrandTables(
                brandKeywords,
                nonBrandKeywords,
                brandSec,
                brandBody,
                nonBrandBody
            );
        }


        // Cannibalization – only for Query + Page tab (qp)
        if (currentType === 'qp' && cannibalization.length) {
            cannibalSec.classList.remove('hidden');
            cannibalization.slice(0, 20).forEach(item => {
                const pagesHtml = item.pages.map(p => {
                    return `
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-[11px] px-2 py-0.5 rounded-full bg-gray-100 dark:bg-neutral-700 text-gray-600 dark:text-gray-300">${p.page_type}</span>
                        <a href="${p.page}" target="_blank" class="text-xs text-indigo-600 dark:text-indigo-300 hover:underline truncate max-w-xs" title="${p.page}">${p.page}</a>
                        <span class="ml-auto text-[11px] text-gray-400">${p.impressions.toLocaleString()} impr · pos ${p.position}</span>
                    </div>
                `;
                }).join('');

                const tr = document.createElement('tr');
                tr.innerHTML = `
                <td class="px-4 py-3 align-top text-sm text-gray-800 dark:text-gray-100">${item.keyword}</td>
                <td class="px-4 py-3 align-top text-sm text-gray-700 dark:text-gray-200">${pagesHtml}</td>
                <td class="px-4 py-3 align-top text-sm text-right text-gray-600 dark:text-gray-300">${item.gap}%</td>
            `;
                cannibalBody.appendChild(tr);
            });
        }

        //  ENABLE PAGINATION FOR ALL TABLES
        setupPaginationForAllTables();
    }

    // Universal pagination for all tables
    function setupPaginationForAllTables() {
        document.querySelectorAll(".common-pagination").forEach(section => {
            const prevBtn = section.querySelector(".prevPageBtn");
            const nextBtn = section.querySelector(".nextPageBtn");
            const info = section.querySelector(".pagination-info");
            const tbody = section.parentElement.querySelector("tbody");

            let page = 1;
            const perPage = 10;

            function getRows() {
                return Array.from(tbody.querySelectorAll("tr"));
            }

            function render() {
                const rows = getRows();
                const total = rows.length;
                rows.forEach(r => r.classList.add("hidden"));
                const start = (page - 1) * perPage;
                const end = start + perPage;
                rows.slice(start, end).forEach(r => r.classList.remove("hidden"));
                info.textContent = `Showing ${total === 0 ? 0 : start + 1} to ${Math.min(end, total)} of ${total} entries`;
                prevBtn.disabled = page === 1;
                nextBtn.disabled = end >= total;
            }
            prevBtn.onclick = () => {
                if (page > 1) {
                    page--;
                    render();
                }
            };

            nextBtn.onclick = () => {
                const total = getRows().length;
                if (page * perPage < total) {
                    page++;
                    render();
                }
            };
            // Initial render
            setTimeout(render, 50);
        });
    }
</script>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        const sections = document.querySelectorAll(".tab_section");
        const tabs = document.querySelectorAll(".tab_link_sticky a");

        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        const id = entry.target.id;

                        tabs.forEach((tab) => {
                            tab.classList.remove("bg-primary-600");
                            tab.classList.add("bg-primary-400");

                            if (tab.getAttribute("href") === `#${id}`) {
                                tab.classList.remove("bg-primary-400");
                                tab.classList.add("bg-primary-600");
                            }
                        });
                    }
                });
            }, {
                rootMargin: "-120px 0px -60% 0px",
                threshold: 0.1,
            }
        );

        sections.forEach((section) => observer.observe(section));
    });
</script>





<?php include './partials/layouts/layoutBottom.php'; ?>