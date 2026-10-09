<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../core/HeaderData.php'; // Include the file that contains the data fetching function

// Start the session if it hasn't been started already
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Fetch admin-specific data from the database
$adminData = get_app_settings($pdo);

// Initialize variables with default values or fetched data
$adminEmail   = $adminData['admin_email'] ?? 'N/A';
$adminRole    = $adminData['role'] ?? 'N/A';
$brandName    = $adminData['brand_name'] ?? 'GA4';
$faviconPath  = $adminData['favicon_path'] ?? '';
$initials     = '';

// Generate initials from the Brand Name
if (!empty($brandName)) {
    $words = explode(' ', $brandName);
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
    }
    // Limit to two initials
    $initials = substr($initials, 0, 2);
}

// Wix user data (separate from admin)
$userEmail = 'N/A';
$siteDisplayNameWix = 'Admin';
$initialsWix = 'AD';

if (isset($_SESSION['instanceid'])) {
    $instanceId = $_SESSION['instanceid'];

    try {
        $stmt = $pdo->prepare("SELECT `owner_email`, `site_display_name` FROM `WixSite` WHERE `instance_id` = ? LIMIT 1");
        $stmt->execute([$instanceId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $userEmail = htmlspecialchars($result['owner_email']);
            if (!empty($result['site_display_name'])) {
                $siteDisplayNameWix = htmlspecialchars($result['site_display_name']);
                $words = explode(' ', $siteDisplayNameWix);
                $initialsWix = '';
                foreach ($words as $word) {
                    if (!empty($word)) {
                        $initialsWix .= strtoupper($word[0]);
                    }
                }
                $initialsWix = substr($initialsWix, 0, 2);
            }
        }
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
    }
}
?>

<div class="navbar-header border-b border-neutral-200 dark:border-neutral-600">
    <div class="flex items-center justify-between">
        <div class="col-auto">
            <div class="flex flex-wrap items-center gap-[16px]">
                <button type="button" class="sidebar-toggle">
                    <iconify-icon icon="heroicons:bars-3-solid" class="icon non-active"></iconify-icon>
                    <iconify-icon icon="iconoir:arrow-right" class="icon active"></iconify-icon>
                </button>
                <button type="button" class="sidebar-mobile-toggle d-flex !leading-[0]">
                    <iconify-icon icon="heroicons:bars-3-solid" class="icon !text-[30px]"></iconify-icon>
                </button>
            </div>
        </div>
        <div class="col-auto">
            <div class="flex flex-wrap items-center gap-3">

                <!-- Theme toggle -->
                <button type="button" id="theme-toggle" class="w-10 h-10 bg-neutral-200 dark:bg-neutral-700 dark:text-white rounded-full hidden justify-center items-center">
                    <span id="theme-toggle-dark-icon" class="hidden">
                        <i class="ri-sun-line"></i>
                    </span>
                    <span id="theme-toggle-light-icon" class="hidden">
                        <i class="ri-moon-line"></i>
                    </span>
                </button>

                <!-- Profile dropdown -->
                <button data-dropdown-toggle="dropdownProfile" class="flex justify-center items-center rounded-full" type="button">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center bg-primary-600 text-white font-bold text-lg">
                        <?php if ($faviconPath): ?>
                            <img src="<?= htmlspecialchars($faviconPath) ?>" alt="Favicon" class="w-full h-full object-cover rounded-full">
                        <?php else: ?>
                            <?= $initials ?>
                        <?php endif; ?>
                    </div>
                </button>
                <div id="dropdownProfile" class="z-10 hidden bg-white dark:bg-neutral-700 rounded-lg shadow-lg dropdown-menu-sm p-3">
                    <div class="py-3 px-4 rounded-lg bg-cstm-primary-10 drk-bg-cstm-primary-30 mb-4 flex items-center justify-between gap-2">
                        <div>
                            <h6 class="text-lg text-neutral-900 font-semibold mb-0"><?= $adminEmail ?></h6>
                            <span class="text-neutral-500"><?= $adminRole ?></span>
                        </div>
                        <button type="button" id="dropdownCloseBtn" class="hover:text-cstm-primary" aria-label="Close">
                            <iconify-icon icon="radix-icons:cross-1" class="icon text-xl"></iconify-icon>
                        </button>
                    </div>

                    <div class="max-h-[400px] overflow-y-auto scroll-sm pe-2">
                        <ul class="flex flex-col">
                            <li>
                                <a class="text-black px-0 py-2 hover:text-cstm-primary flex items-center gap-4" href="settings.php">
                                    <iconify-icon icon="icon-park-outline:setting-two" class="icon text-xl"></iconify-icon> Setting
                                </a>
                            </li>
                            <li>
                                <a class="text-black px-0 py-2 hover:text-cstm-primary flex items-center gap-4" href="logout.php">
                                    <iconify-icon icon="lucide:power" class="icon text-xl"></iconify-icon> Log Out
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Fix dropdown close behavior -->
<script>
    document.addEventListener("DOMContentLoaded", () => {
        const target = document.getElementById("dropdownProfile");
        const trigger = document.querySelector("[data-dropdown-toggle='dropdownProfile']");

        if (typeof Dropdown !== "undefined" && target && trigger) {
            let dropdown = new Dropdown(target, trigger);

            const closeBtn = document.getElementById("dropdownCloseBtn");
            if (closeBtn) {
                closeBtn.addEventListener("click", () => {
                    dropdown.hide();

                    // Re-initialize the dropdown to fix the double-click bug
                    dropdown = new Dropdown(target, trigger);
                });
            }
        }
    });
</script>