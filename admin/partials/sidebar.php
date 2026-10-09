<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../core/HeaderData.php';

// Fetch admin-specific data from the database
$adminData = get_app_settings($pdo);

// Initialize variables with fetched data
$logoPath = $adminData['logo_path'] ?? 'assets/images/analyticslogo1.png'; // Fallback if not found
$faviconPath = $adminData['favicon_path'] ?? 'assets/images/logo-icon.png'; // Fallback if not found

// Note: The 'logo-light.png' is typically for a dark-themed UI. 
// We will assume the fetched logo_path is for the light theme, 
// and the dark logo remains a static file unless a separate dark_logo_path is added to the database.
$darkLogoPath = 'assets/images/logo-light.png';
?>

<aside class="sidebar cstm-sidebar">
    <button type="button" class="sidebar-close-btn !mt-4">
        <iconify-icon icon="radix-icons:cross-2"></iconify-icon>
    </button>
    <div>
        <a href="index.php" class="sidebar-logo">
            <img src="<?= htmlspecialchars($logoPath) ?>" alt="site logo" class="light-logo">
            <img src="<?= htmlspecialchars($logoPath) ?>" alt="site logo" class="dark-logo">
            <img src="<?= htmlspecialchars($faviconPath) ?>" alt="site logo" class="logo-icon mx-auto">
        </a>
    </div>
    <div class="sidebar-menu-area">
        <ul class="sidebar-menu cstm-sidebar-menu" id="sidebar-menu">
            <li>
                <a href="index.php">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="menu-icon"></iconify-icon>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="stores.php">
                    <iconify-icon icon="flowbite:users-outline" class="menu-icon"></iconify-icon>
                    <span>Stores</span>
                </a>
            </li>
            <li>
                <a href="stores-active.php">
                    <iconify-icon icon="flowbite:users-outline" class="menu-icon"></iconify-icon>
                    <span>Active User</span>
                </a>
            </li>
            <li>
                <a href="stores-inactive.php">
                    <iconify-icon icon="flowbite:users-outline" class="menu-icon"></iconify-icon>
                    <span>Inactive Users</span>
                </a>
            </li>
            <li>
                <a href="faq.php">
                    <iconify-icon icon="mage:message-question-mark-round" class="menu-icon"></iconify-icon>
                    <span>FAQ</span>
                </a>
            </li>
            <li>
                <a href="settings.php">
                    <iconify-icon icon="icon-park-outline:setting-two" class="menu-icon"></iconify-icon>
                    <span>Settings</span>
                </a>
            </li>
            <li>
                <a href="instructions.php">
                    <iconify-icon icon="solar:document-text-outline" class="menu-icon"></iconify-icon>
                    <span>Instructions</span>
                </a>
            </li>
            <li>
                <a href="email-templates.php">
                    <iconify-icon icon="tabler:mail-cog" class="menu-icon"></iconify-icon>
                    <span>Email Templates</span>
                </a>
            </li>

            <li>
                <a href="tickets.php">
                    <iconify-icon icon="solar:ticket-outline" class="menu-icon"></iconify-icon>
                    <span>Tickets</span>
                </a>
            </li>

            <li>
                <a href="reviews.php">
                    <iconify-icon icon="mdi:star-circle-outline" class="menu-icon"></iconify-icon>
                    <span>Reviews</span>
                </a>
            </li>

            <li>
                <a href="cancel-plan.php">
                    <iconify-icon icon="tabler:calendar-cancel" class="menu-icon"></iconify-icon>
                    <span>Cancel Plan</span>
                </a>
            </li>


            <li class="mt-4 mb-2 ml-4 text-xs font-semibold text-neutral-500 dark:text-neutral-400 uppercase">
                <span>Our Apps</span>
            </li>
            <li>
                <a href="our-apps.php"> <iconify-icon icon="solar:widget-5-outline" class="menu-icon"></iconify-icon>
                    <span>Our Other Apps</span>
                </a>
            </li>
        </ul>
    </div>
</aside>