<?php
require_once(__DIR__ . '/../includes/config.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| AUTHORITATIVE INSTANCE
|--------------------------------------------------------------------------
*/
$instanceId = $_SESSION['instance_id'] ?? $_GET['instance_id'] ?? null;

if (!$instanceId) {
    header('Location: sign-in.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| DEFAULTS
|--------------------------------------------------------------------------
*/
$userEmail       = 'N/A';
$shopDisplayName = 'Shop';
$initials        = 'SH';
$hasImage        = false;
$profileImageUrl = '';
$shopWebsiteUrl  = '';
$billingUrl      = 'pricing.php';

/*
|--------------------------------------------------------------------------
| LOAD STORE USING INSTANCE
|--------------------------------------------------------------------------
*/
try {
    $stmt = $pdo->prepare("
        SELECT 
            shop_name,
            email,
            domain,
            shop_domain
        FROM WpSite
        WHERE instance_id = ?
        LIMIT 1
    ");
    $stmt->execute([$instanceId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {

        // Email
        $userEmail = !empty($result['email'])
            ? htmlspecialchars($result['email'], ENT_QUOTES, 'UTF-8')
            : 'N/A';

        // Shop Name
        if (!empty($result['shop_name'])) {
            $shopDisplayName = htmlspecialchars($result['shop_name'], ENT_QUOTES, 'UTF-8');
        }

        // Generate initials
        $words = preg_split('/[\s@._-]+/', $shopDisplayName);
        $initials = '';
        foreach ($words as $word) {
            if ($word !== '') {
                $initials .= strtoupper($word[0]);
            }
        }
        $initials = substr($initials ?: 'SH', 0, 2);

        /*
        |--------------------------------------------------------------------------
        | Resolve Domain (domain → shop_domain)
        |--------------------------------------------------------------------------
        */
        $resolvedDomain = '';

        if (!empty($result['domain'])) {
            $resolvedDomain = trim($result['domain']);
        } elseif (!empty($result['shop_domain'])) {
            $resolvedDomain = trim($result['shop_domain']);
        }

        /*
        |--------------------------------------------------------------------------
        | Website URL
        |--------------------------------------------------------------------------
        */
        if ($resolvedDomain) {
            if (!preg_match('#^https?://#i', $resolvedDomain)) {
                $shopWebsiteUrl = 'https://' . htmlspecialchars($resolvedDomain, ENT_QUOTES, 'UTF-8');
            } else {
                $shopWebsiteUrl = htmlspecialchars($resolvedDomain, ENT_QUOTES, 'UTF-8');
            }
        }
    }
} catch (PDOException $e) {
    error_log('Header load error: ' . $e->getMessage());
}
?>

<!-- ========================================= -->
<!-- HEADER -->
<!-- ========================================= -->

<div class="navbar-header border-b border-neutral-200 dark:border-neutral-600">
    <div class="flex items-center justify-between">

        <!-- LEFT -->
        <div class="flex items-center gap-4">

            <button type="button" class="sidebar-toggle">
                <iconify-icon icon="heroicons:bars-3-solid" class="icon non-active"></iconify-icon>
                <iconify-icon icon="iconoir:arrow-right" class="icon active"></iconify-icon>
            </button>

            <button type="button" class="sidebar-mobile-toggle d-flex !leading-[0]">
                <iconify-icon icon="heroicons:bars-3-solid" class="icon !text-[30px]"></iconify-icon>
            </button>

            <!-- Upgrade (internal now, not Shopify billing) -->
            <a href="pricing.php">
                <button class="h-10 btn btn-cstm-primary flex items-center gap-2 px-3">
                    <iconify-icon icon="mdi:crown-outline" class="text-xl"></iconify-icon>
                    <span class="hidden xs:block">Upgrade</span>
                </button>
            </a>

            <!-- WEBSITE BUTTON -->
            <?php if (!empty($shopWebsiteUrl)): ?>
                <div class="relative block" id="propertyDropdown">
                    <div id="propertyDropdownBtn" class="cursor-pointer flex items-center justify-between max-w-[290px] px-3 py-1 bg-white border-0 border-neutral-200 rounded-lg hover:border-cstm-primary transition-all gap-1 sm:gap-6 shadow-md">
                        <a href="<?= $shopWebsiteUrl ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="group pointer-events-none flex items-center gap-2 text-xs font-medium text-neutral-700 dark:text-neutral-300 hover:border-cstm-primary hover:text-cstm-primary transition-all">
                            <span class="h-6 w-6 rounded-lg bg-cstm-primary-10 flex items-center justify-center flex-shrink-0">
                                <iconify-icon icon="heroicons:globe-alt-solid" class="text-cstm-primary text-base"></iconify-icon>
                            </span>
                            <div class="hidden text-left min-w-0 flex-1 md:grid mobile_d_none">
                                <h4 class="font-semibold text-neutral-900 text-xs text-start">
                                    Website
                                </h4>
                                <span class="text-[10px] text-neutral-500 truncate"><?= parse_url($shopWebsiteUrl, PHP_URL_HOST); ?></span>
                            </div>
                        </a>
                        <iconify-icon id="propertyArrow" icon="heroicons:chevron-down" class="text-xl text-neutral-500 transition duration-300">
                        </iconify-icon>
                    </div>
                    <!-- Dropdown -->
                    <div id="propertyDropdownMenu"
                        class="hidden fixed md:absolute left-0 top-[60px] md:top-[40px] mt-2 w-full max-w-[500px] md:min-w-[500px] max-h-[70vh] flex flex-col bg-white rounded-lg shadow-2xl border-0 border-neutral-200 overflow-hidden z-50">

                        <!-- Heading -->

                        <div class="px-4 py-3 border-0 border-neutral-200 flex items-center gap-3">
                            <span class="rounded-full h-6 w-6 bg-gray-100 flex items-center justify-center">
                                <iconify-icon icon="solar:layers-bold" class="text-md text-gray-700"></iconify-icon>
                            </span>
                            <h3 class="text-base font-semibold">
                                Select Property
                            </h3>

                        </div>
                        <div class="px-4 pb-4 overflow-y-auto cstm-scroll-sm">
                            <!-- Website -->
                            <h6 class="uppercase text-gray-500 text-xs mb-1">Current Property</h6>
                            <button
                                class="relative w-full flex items-center justify-between px-3 py-2 bg-cstm-primary-5 border border-gray-200 hover:bg-blue-100 transition rounded-lg gap-3">

                                <div class="absolute left-0 top-0 bottom-0 w-1 rounded-r-full bg-cstm-primary"></div>

                                <div class="flex items-center gap-4 flex-1 min-w-0">

                                    <div
                                        class="w-8 h-8 rounded-lg bg-cstm-primary-10 flex items-center justify-center">

                                        <iconify-icon
                                            icon="heroicons:globe-alt-solid"
                                            class="text-cstm-primary text-base">
                                        </iconify-icon>

                                    </div>

                                    <div class="text-left flex-1 min-w-0 grid">

                                        <p class="font-semibold text-sm text-start">
                                            Website
                                        </p>
                                        <div class="flex items-center gap-1 text-sm text-neutral-500">
                                            <a href="<?= $shopWebsiteUrl ?>" title=<?= $shopWebsiteUrl ?>" target="_blank" class="text-xs text-neutral-500 truncate max-w-[100px] sm:max-w-[220px]" style="text-align: left;">
                                                <?= parse_url($shopWebsiteUrl, PHP_URL_HOST); ?>
                                            </a>
                                            <iconify-icon icon="mdi:open-in-new" class="text-sm text-neutral-400 shrink-0"> </iconify-icon>
                                        </div>
                                    </div>

                                </div>

                                <div class="flex items-center gap-2 flex-shrink-0 bg-success-100 rounded-xl px-2 py-1 border border-success-300">
                                    <iconify-icon
                                        icon="heroicons:check-circle-solid"
                                        class="text-success-600 text-lg">
                                    </iconify-icon>
                                    <span
                                        class="rounded-full text-xs text-success-main font-medium">
                                        Active
                                    </span>
                                </div>

                            </button>

                            <h6 class="uppercase text-gray-500 text-xs mb-1 mt-4">Other Properties</h6>
                            <!-- Instagram -->
                            <div class="rounded-lg border-gray-200 border">
                                <button
                                    class="w-full flex items-center justify-between px-3 py-2 border-b border-gray-200 hover:bg-neutral-50 transition gap-3">
                                    <div class="flex items-center justify-between gap-4 flex-1 min-w-0">
                                        <div class="w-8 h-8 rounded-lg bg-cstm-primary-10 flex items-center justify-center">
                                            <iconify-icon icon="skill-icons:instagram" class="text-base"></iconify-icon>
                                        </div>

                                        <div class="flex flex-col md:flex-row items-start md:items-center gap-3 justify-between text-left flex-1 min-w-0">
                                            <div class="grid max-w-[220px]">
                                                <h6 class="text-xs font-semibold text-start">Instagram </h6>
                                                <p class="text-xs text-neutral-500 text-start truncate">
                                                    instagram.com/yourbrand
                                                </p>
                                            </div>

                                            <span class="badge-soon border-success-200 broder rounded-xl py-1.5 px-2 text-[10px] font-medium whitespace-nowrap" style="background-color: #fdf2d7; color: #cf5c1d">
                                                🚀 Coming Soon
                                            </span>
                                        </div>
                                    </div>

                                    <iconify-icon
                                        icon="heroicons:chevron-right"
                                        class="text-base text-neutral-800">
                                    </iconify-icon>

                                </button>

                                <!-- YouTube -->
                                <button
                                    class="w-full flex items-center justify-between px-3 py-2 border-b border-gray-200 hover:bg-neutral-50 transition gap-3">

                                    <div class="flex items-center justify-between gap-4 flex-1 min-w-0">

                                        <div class="w-8 h-8 rounded-lg bg-cstm-primary-10 flex items-center justify-center">
                                            <iconify-icon icon="logos:youtube-icon" class="text-sm"></iconify-icon>
                                        </div>

                                        <div class="flex flex-col md:flex-row items-start md:items-center gap-3 justify-between text-left flex-1 min-w-0">
                                            <div class="grid max-w-[220px]">
                                                <h6 class="text-xs font-semibold text-start">
                                                    YouTube
                                                </h6>
                                                <p class="text-xs text-neutral-500 text-start truncate">
                                                    youtube.com/@channel
                                                </p>
                                            </div>
                                            <span class="badge-soon border-success-200 broder rounded-xl py-1.5 px-2 text-[10px] font-medium whitespace-nowrap" style="background-color: #fdf2d7; color: #cf5c1d">
                                                🚀 Coming Soon
                                            </span>

                                        </div>

                                    </div>

                                    <iconify-icon
                                        icon="heroicons:chevron-right"
                                        class="text-base text-neutral-800">
                                    </iconify-icon>

                                </button>

                                <!-- Twitter -->
                                <button
                                    class="w-full flex items-center justify-between px-3 py-2 border-b border-gray-200 hover:bg-neutral-50 transition gap-3">
                                    <div class="flex items-center justify-between gap-4 flex-1 min-w-0">
                                        <div class="w-8 h-8 rounded-lg bg-cstm-primary-10 flex items-center justify-center">
                                            <iconify-icon icon="logos:x" class="text-sm"></iconify-icon>
                                        </div>

                                        <div class="flex flex-col md:flex-row items-start md:items-center gap-3 justify-between text-left flex-1 min-w-0">
                                            <div class="grid max-w-[220px]">
                                                <h6 class="text-xs font-semibold text-start">
                                                    X / Twitter
                                                </h6>
                                                <p class="text-xs text-neutral-500 text-start truncate">
                                                    x.com/yourhandle
                                                </p>
                                            </div>
                                            <span class="badge-soon border-success-200 broder rounded-xl py-1.5 px-2 text-[10px] font-medium whitespace-nowrap" style="background-color: #fdf2d7; color: #cf5c1d">
                                                🚀 Coming Soon
                                            </span>
                                        </div>
                                    </div>

                                    <iconify-icon
                                        icon="heroicons:chevron-right"
                                        class="text-base text-neutral-800">
                                    </iconify-icon>

                                </button>

                                <!-- TikTok -->
                                <button
                                    class="w-full flex items-center justify-between p-3 border-0 border-gray-200 hover:bg-neutral-50 transition gap-3">

                                    <div class="flex items-center justify-between gap-4 flex-1 min-w-0">

                                        <div class="w-8 h-8 rounded-lg bg-cstm-primary-10 flex items-center justify-center">
                                            <iconify-icon
                                                icon="logos:tiktok-icon"
                                                class="text-sm">
                                            </iconify-icon>
                                        </div>

                                        <div class="flex flex-col md:flex-row items-start md:items-center gap-3 justify-between text-left flex-1 min-w-0">

                                            <div class="grid max-w-[220px]">
                                                <h6 class="text-xs font-semibold text-start">
                                                    TikTok
                                                </h6>

                                                <p class="text-xs text-neutral-500 text-start truncate">
                                                    tiktok.com/@username
                                                </p>
                                            </div>

                                            <span
                                                class="badge-soon border-success-200 broder rounded-xl py-1.5 px-2 text-[10px] font-medium whitespace-nowrap"
                                                style="background-color: #fdf2d7; color: #cf5c1d">
                                                🚀 Coming Soon
                                            </span>

                                        </div>

                                    </div>

                                    <iconify-icon
                                        icon="heroicons:chevron-right"
                                        class="text-base text-neutral-800">
                                    </iconify-icon>

                                </button>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-3 border-t border-gray-200 bg-cstm-primary-5 px-4 py-2">
                            <!-- Left -->
                            <div class="flex items-center gap-4">

                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-cstm-primary-10">
                                    <iconify-icon
                                        icon="solar:shield-check-linear"
                                        class="text-lg text-cstm-primary">
                                    </iconify-icon>
                                </div>

                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900">
                                        More platforms coming soon!
                                    </h3>

                                    <p class="text-[10px] text-gray-500">
                                        We're working on adding more integrations for you.
                                    </p>
                                </div>

                            </div>

                            <!-- Right -->
                            <button
                                class="flex items-center gap-2 rounded-lg border border-cstm-primary bg-white px-3 py-2 text-xs font-semibold text-cstm-primary transition hover:bg-blue-50 whitespace-nowrap">
                                <iconify-icon
                                    icon="solar:bell-linear"
                                    class="text-lg">
                                </iconify-icon>
                                Stay Updated
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <!-- RIGHT -->
        <div class="flex items-center gap-3 relative">

            <button id="open-header-ticket"
                class="h-10 btn btn-cstm-primary sm:flex items-center gap-2 px-3 hidden">
                <iconify-icon icon="mdi:headset" class="text-lg"></iconify-icon>
                <span class="hidden lg:block">Contact Support</span>
            </button>

            <a href="instructions.php?instance_id=<?= urlencode($instanceId) ?>" class=" sm:flex hidden">
                <button class="h-10 btn btn-cstm-primary flex items-center gap-2 px-3">
                    <iconify-icon icon="mdi:file-document-outline" class="text-lg"></iconify-icon>
                    <span class="hidden lg:block">Installation Guide</span>
                </button>
            </a>
            <?php include __DIR__ . '/action-center-bell.php'; ?>

            <!-- PROFILE -->
            <div class="relative">
                <button data-dropdown-toggle="dropdownProfile"
                    class="flex items-center justify-center rounded-full"
                    type="button">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center bg-cstm-primary text-white font-bold text-lg">
                        <?= $initials ?>
                    </div>
                </button>

                <!-- DROPDOWN -->
                <div id="dropdownProfile"
                    class="hidden absolute right-0 top-12 bg-white dark:bg-neutral-800 rounded-lg shadow-lg border border-neutral-200 dark:border-neutral-600 p-3 min-w-max z-50">

                    <div class="py-3 px-4 rounded-lg bg-cstm-primary-10 drk-bg-cstm-primary-30 mb-4 flex justify-between gap-2">
                        <div>
                            <h6 class="text-lg font-semibold"><?= $userEmail ?></h6>
                            <span class="text-neutral-500"><?= $shopDisplayName ?></span>
                        </div>
                        <button type="button" id="dropdownCloseBtn">
                            <iconify-icon icon="radix-icons:cross-1" class="text-xl"></iconify-icon>
                        </button>
                    </div>

                    <ul class="space-y-1">
                        <li>
                            <a href="current-plan.php"
                                class="block px-2 py-2 hover:bg-gray-100 dark:hover:bg-neutral-700 rounded">
                                Current Plan
                            </a>
                        </li>
                        <li>
                            <a href="settings.php?instance_id=<?= urlencode($instanceId) ?>"
                                class="block px-2 py-2 hover:bg-gray-100 dark:hover:bg-neutral-700 rounded">
                                Settings
                            </a>
                        </li>
                        <li>
                            <a href="logout.php"
                                class="block px-2 py-2 hover:bg-gray-100 dark:hover:bg-neutral-700 rounded text-danger-600">
                                Log Out
                            </a>
                        </li>
                    </ul>

                </div>
            </div>

        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        /*
        |--------------------------------------------------------------------------
        | Authoritative Data From PHP
        |--------------------------------------------------------------------------
        */
        const instanceId = <?= json_encode($instanceId ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const ownerEmail = <?= json_encode($userEmail ?? 'N/A', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

        /*
        |--------------------------------------------------------------------------
        | Ticket Popup Integration
        |--------------------------------------------------------------------------
        */
        if (window.TicketPopup) {

            if (typeof window.TicketPopup.setInstanceId === 'function') {
                window.TicketPopup.setInstanceId(instanceId);
            }

            if (typeof window.TicketPopup.setOwnerEmail === 'function') {
                window.TicketPopup.setOwnerEmail(ownerEmail);
            }

            // Open popup from header button
            const openBtn = document.getElementById('open-header-ticket');
            if (openBtn) {
                openBtn.addEventListener('click', function() {
                    if (typeof window.TicketPopup.open === 'function') {
                        window.TicketPopup.open();
                    }
                });
            }

            // Success callback
            window.TicketPopup.onSuccess = function() {

                // Refresh tickets list if available
                if (typeof fetchTickets === 'function') {
                    try {
                        fetchTickets();
                    } catch (err) {
                        console.warn('fetchTickets error:', err);
                    }
                }

                // Show notification
                if (typeof showNotification === 'function') {
                    showNotification('Ticket created successfully', 'success');
                } else {
                    const toast = document.createElement('div');
                    toast.textContent = 'Ticket created successfully';
                    toast.style.cssText = `
                    position: fixed;
                    top: 12px;
                    right: 12px;
                    padding: 10px 14px;
                    background: #16a34a;
                    color: #fff;
                    border-radius: 8px;
                    z-index: 9999;
                `;
                    document.body.appendChild(toast);
                    setTimeout(() => toast.remove(), 3000);
                }
            };
        }

        /*
        |--------------------------------------------------------------------------
        | Profile Dropdown Toggle
        |--------------------------------------------------------------------------
        */
        const profileToggle = document.querySelector('[data-dropdown-toggle="dropdownProfile"]');
        const dropdown = document.getElementById('dropdownProfile');
        const closeBtn = document.getElementById('dropdownCloseBtn');

        if (profileToggle && dropdown) {
            profileToggle.addEventListener('click', function() {
                dropdown.classList.toggle('hidden');
            });
        }

        if (closeBtn && dropdown) {
            closeBtn.addEventListener('click', function() {
                dropdown.classList.add('hidden');
            });
        }

    });
</script>
<script>
    const dropdown = document.getElementById("propertyDropdown");
    const btn = document.getElementById("propertyDropdownBtn");
    const menu = document.getElementById("propertyDropdownMenu");
    const arrow = document.getElementById("propertyArrow");

    btn.addEventListener("click", function(e) {

        e.stopPropagation();

        menu.classList.toggle("hidden");

        arrow.classList.toggle("rotate-180");

    });

    document.addEventListener("click", function() {

        menu.classList.add("hidden");

        arrow.classList.remove("rotate-180");

    });

    menu.addEventListener("click", function(e) {

        e.stopPropagation();

    });
</script>