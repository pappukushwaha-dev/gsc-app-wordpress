<?php
$title = 'Store Profile';
$subTitle = 'Store Profile';

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';

SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

// --- Data Fetching Logic ---
$instanceId = $_GET['instance_id'] ?? null;
$store      = null;

$googleStatusLabel = 'Not Connected';
$domainStatusLabel = 'Verification Pending';
$sitemapStatusLabel = 'Not Submitted';
$latestSitemaps = [];

if ($instanceId) {
    // Main store data
    $stmt = $pdo->prepare("
        SELECT
            ws.instance_id,
            ws.shop_domain,
            ws.shop_name,
            ws.email AS shop_mail,
            wsp.company_name,
            wsp.logo_url,
            wsp.description,
            wsp.language,
            wsp.email AS company_mail,
            wsp.phone,
            wsp.address,
            wsp.social_links_json
        FROM WpSite ws
        LEFT JOIN WpSiteProfile wsp ON ws.instance_id = wsp.instance_id
        WHERE ws.instance_id = :instance_id
    ");
    $stmt->execute([':instance_id' => $instanceId]);
    $store = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($store) {
        // 1) Google Account status (google_accounts)
        try {
            $stmt = $pdo->prepare("
                SELECT email
                FROM google_accounts
                WHERE instance_id = :instance_id
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([':instance_id' => $instanceId]);
            $google = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($google) {
                $googleStatusLabel = 'Connected';
            } else {
                $googleStatusLabel = 'Not Connected';
            }
        } catch (Throwable $e) {
            // keep default label
        }

        // 2) Domain Verification status (gsc_domain_verifications)
        try {
            $stmt = $pdo->prepare("
                SELECT verification_status
                FROM gsc_domain_verifications
                WHERE instance_id = :instance_id
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([':instance_id' => $instanceId]);
            $domain = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($domain) {
                if ($domain['verification_status'] === 'verified') {
                    $domainStatusLabel = 'Verified';
                } elseif ($domain['verification_status'] === 'failed') {
                    $domainStatusLabel = 'Verification Failed';
                } else {
                    // pending or anything else
                    $domainStatusLabel = 'Verification Pending';
                }
            } else {
                // no row yet
                $domainStatusLabel = 'Verification Pending';
            }
        } catch (Throwable $e) {
            // keep default
        }

        // 3) Sitemap status + latest updates (sitemaps)
        try {
            // Latest sitemap for card
            $stmt = $pdo->prepare("
                SELECT sitemap_url, status, last_submitted_at
                FROM sitemaps
                WHERE instance_id = :instance_id
                ORDER BY last_submitted_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([':instance_id' => $instanceId]);
            $lastSitemap = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($lastSitemap) {
                // Status column in DB (e.g. Success, Pending, Error)
                $sitemapStatusLabel = $lastSitemap['status'] ? $lastSitemap['status'] : 'Not Submitted';
            } else {
                $sitemapStatusLabel = 'Not Submitted';
            }

            // Latest 5 sitemaps for table
            $stmt = $pdo->prepare("
                SELECT sitemap_url, status, last_submitted_at
                FROM sitemaps
                WHERE instance_id = :instance_id
                ORDER BY last_submitted_at DESC, id DESC
                LIMIT 5
            ");
            $stmt->execute([':instance_id' => $instanceId]);
            $latestSitemaps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {
            $latestSitemaps = [];
        }
    }
}
// --- End Data Fetching Logic ---

// no JS needed now
$script = '';

include './partials/layouts/layoutTop.php';
?>

<?php if ($store): ?>
    <?php
    $displayName  = $store['shop_name'] ?? $store['company_name'] ?? 'N/A';
    $displayEmail = $store['shop_mail'] ?? $store['company_mail'] ?? 'N/A';
    $logoUrl      = $store['logo_url']
        ? (str_starts_with($store['logo_url'], 'http')
            ? htmlspecialchars($store['logo_url'])
            : 'https://static.wixstatic.com/media/' . htmlspecialchars($store['logo_url']))
        : '';
    $initials     = strtoupper(substr($displayName, 0, 1));
    ?>
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 card border-none p-6">

        <div class="col-span-12 lg:col-span-6 xl:col-span-5 3xl:col-span-4">
            <div class="user-grid-card relative border border-neutral-200 dark:border-neutral-600 rounded-lg p-6">
                <img src="./assets/images/stores/bg.png" alt="Profile background" class="w-full h-40 object-cover rounded-lg">
                <div class="-mt-[100px]">
                    <div class="text-center border-b border-neutral-200 dark:border-neutral-600 pb-6">
                        <?php if ($logoUrl): ?>
                            <img src="<?= $logoUrl ?>" alt="Store Logo" class="relative z-10 w-[150px] h-[150px] mx-auto rounded-full object-cover border-4 border-white dark:border-neutral-700 shadow-md bg-white">
                        <?php else: ?>
                            <div class="relative z-10 w-[150px] h-[150px] mx-auto rounded-full flex items-center justify-center bg-cstm-primary drk-bg-cstm-primary-10 border-4 border-white dark:border-neutral-700 shadow-md">
                                <span class="text-12xl font-bold text-white"><?= $initials ?></span>
                            </div>
                        <?php endif; ?>
                        <h6 class="mb-0 mt-4 text-base font-semibold break-all"><?= htmlspecialchars($displayName) ?></h6>
                        <span class="text-gray-500 text-sm mb-4 break-all"><?= htmlspecialchars($displayEmail) ?></span>
                    </div>
                    <div class="mt-6">
                        <h6 class="text-lg font-semibold mb-4">Store Info</h6>
                        <ul class="space-y-3 p-6 bg-gray-50 dark:bg-gray-800 rounded-lg overflow-hidden">
                            <li class="flex flex-col sm:flex-row gap-1 mb-3">
                                <span class="min-w-[100px] font-semibold text-neutral-800 dark:text-neutral-200"> Site URL</span>
                                <span class="flex gap-1 text-gray-500 break-all font-medium"><span>:</span>
                                    <a href="<?= htmlspecialchars($store['shop_domain'] ?? '#') ?>" target="_blank" class="text-primary-600 hover:underline">
                                        <?= htmlspecialchars($store['shop_domain'] ?? 'N/A') ?>
                                    </a>
                                </span>
                            </li>
                            <li class="flex flex-col sm:flex-row gap-1 mb-3">
                                <span class="min-w-[100px] font-semibold text-neutral-800 dark:text-neutral-200"> Phone</span>
                                <span class="flex gap-1 text-gray-500 break-all font-medium"><span>:</span>
                                    <?= htmlspecialchars($store['phone'] ?? 'N/A') ?>
                                </span>
                            </li>
                            <li class="flex flex-col sm:flex-row gap-1 mb-3">
                                <span class="min-w-[100px] font-semibold text-neutral-800 dark:text-neutral-200"> Language</span>
                                <span class="flex gap-1 text-gray-500 break-all font-medium"><span>:</span>
                                    <?= htmlspecialchars($store['language'] ?? 'N/A') ?>
                                </span>
                            </li>
                            <li class="flex flex-col sm:flex-row gap-1 mb-3">
                                <span class="min-w-[100px] font-semibold text-neutral-800 dark:text-neutral-200"> Description</span>
                                <span class="flex gap-1 text-gray-500 break-all font-medium"><span>:</span>
                                    <?= htmlspecialchars($store['description'] ?? 'N/A') ?>
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-span-12 lg:col-span-6 xl:col-span-7 3xl:col-span-8">
            <div class="h-full border-0">
                <div class="card-body p-6 border border-neutral-200 rounded-lg dark:border-gray-600">
                    <div class="flex justify-between items-center mb-6 gap-3 flex-wrap">
                        <h6 class="font-semibold text-xl">Dashboard Overview</h6>
                        <a href="stores.php" class="btn btn-cstm-primary inline-flex items-center gap-2">
                            <iconify-icon icon="solar:arrow-left-linear" class="icon text-lg"></iconify-icon>
                            Back to Stores
                        </a>
                    </div>

                    <!-- Top 3 status cards -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                        <!-- Google Account Login -->
                        <div class="p-4 bg-neutral-100 dark:bg-neutral-800 rounded-lg">
                            <p class="text-xs font-medium text-secondary-light mb-1">
                                Google Account
                            </p>
                            <h6 class="text-lg md:text-xl font-semibold">
                                <?= htmlspecialchars($googleStatusLabel) ?>
                            </h6>
                        </div>

                        <!-- Domain Verification -->
                        <div class="p-4 bg-neutral-100 dark:bg-neutral-800 rounded-lg">
                            <p class="text-xs font-medium text-secondary-light mb-1">
                                Domain Verification
                            </p>
                            <h6 class="text-lg md:text-xl font-semibold">
                                <?= htmlspecialchars($domainStatusLabel) ?>
                            </h6>
                        </div>

                        <!-- Sitemap -->
                        <div class="p-4 bg-neutral-100 dark:bg-neutral-800 rounded-lg">
                            <p class="text-xs font-medium text-secondary-light mb-1">
                                Sitemap
                            </p>
                            <h6 class="text-lg md:text-xl font-semibold">
                                <?= htmlspecialchars($sitemapStatusLabel) ?>
                            </h6>
                        </div>
                    </div>

                    <h6 class="font-semibold text-lg mb-4">Latest Updates</h6>
                    <div class="overflow-x-auto mb-8 cstm-scroll-sm">
                        <table class="table bordered-table sm-table mb-0 table-auto">
                            <thead>
                                <tr>
                                    <th>Sitemap URL</th>
                                    <th>Last Updated</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($latestSitemaps)): ?>
                                    <?php foreach ($latestSitemaps as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['sitemap_url']); ?></td>
                                            <td>
                                                <?php
                                                if (!empty($row['last_submitted_at'])) {
                                                    // assuming last_submitted_at is stored in UTC in the DB
                                                    $dt = new DateTime($row['last_submitted_at'], new DateTimeZone('UTC'));

                                                    // convert to India time
                                                    $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));

                                                    echo htmlspecialchars($dt->format('d M Y H:i')); // e.g. 03 Dec 2025 07:24
                                                } else {
                                                    echo 'Never';
                                                }
                                                ?>
                                            </td>

                                            <td><?= htmlspecialchars($row['status']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-base text-secondary-light py-4">
                                            No sitemap updates found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="flex flex-col items-center justify-center py-24 text-center">
        <h3 class="text-2xl font-bold text-danger-600 mb-2">Store Not Found</h3>
        <p class="text-neutral-500 dark:text-neutral-400 mb-6">
            The requested store could not be found. Please check the URL and try again.
        </p>
        <a href="stores.php" class="inline-flex items-center gap-2 bg-primary-600 text-white rounded-lg px-6 py-3 font-semibold hover:bg-primary-700 transition-colors">
            <iconify-icon icon="solar:arrow-left-linear" class="icon text-lg"></iconify-icon>
            Back to Stores
        </a>
    </div>
<?php endif; ?>

<?php include './partials/layouts/layoutBottom.php'; ?>
