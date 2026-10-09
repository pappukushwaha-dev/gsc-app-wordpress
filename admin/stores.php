<?php
// File: /admin/stores.php
$title = 'Stores';
$subTitle = 'Stores';

ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);

// Includes
require_once __DIR__ . '/../includes/config.php';
// require_once __DIR__ . '/../includes/db.php';

require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';
require_once __DIR__ . '/core/StoreManager.php';

SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

StoreManager::initialize($pdo);

$perPage = isset($_GET['show']) ? (int) $_GET['show'] : 10;
$currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$searchQuery = $_GET['search'] ?? '';

$stores = StoreManager::getFilteredStores($searchQuery, $perPage, $currentPage);
$totalStores = StoreManager::getTotalStores($searchQuery);
$totalPages = max(1, (int) ceil($totalStores / $perPage));

/**
 * Badge class generator
 */
function getBadgeClass($type, $value)
{
    $success = 'bg-success-100 text-success-600 dark:bg-success-600/20 dark:text-success-400';
    $danger = 'bg-danger-100 text-danger-600 dark:bg-danger-600/20 dark:text-danger-400';
    $warning = 'bg-warning-100 text-warning-600 dark:bg-warning-600/20 dark:text-warning-400';
    $neutral = 'bg-neutral-100 text-neutral-600 dark:bg-neutral-600/20 dark:text-neutral-400';

    if ($type === 'status') {
        return (strtolower((string) $value) === 'active') ? $success : $danger;
    }
    if ($type === 'verified') {
        $v = strtolower((string) $value);
        return ($v === 'verified') ? $success : (($v === 'failed') ? $danger : $warning);
    }
    if ($type === 'sitemap') {
        $v = strtolower((string) $value);
        return ($v === 'success') ? $success : (($v === 'error') ? $danger : $warning);
    }
    if ($type === 'bool') {
        return $value ? $success : $danger;
    }
    if ($type === 'plan') {
        $v = strtolower(trim((string) $value));
        if ($v === 'trial')
            return $warning;
        if ($v === 'free')
            return $neutral;
        return $success; // paid plans show success
    }

    return $neutral;
}

/**
 * Render table + pagination for AJAX requests
 */
function renderStoresTable($stores, $currentPage, $perPage, $totalStores, $totalPages, $searchQuery)
{
    ob_start();
    ?>

    <div class="cstm-scroll-sm overflow-x-auto">
        <table class="table bordered-table sm-table table-auto text-sm">
            <thead>
                <tr>
                    <th>S.No.</th>
                    <th>Name / Email</th>
                    <th>Site URL</th>
                    <th class="!text-center">Google Account</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th>Verified</th>
                    <th>Meta Key</th>
                    <th>Sitemap</th>
                    <th>Installed On</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>

                <?php if (empty($stores)): ?>
                    <tr>
                        <td colspan="11" class="text-center py-4 text-neutral-500 dark:text-neutral-400">
                            No stores found.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php
                    $i = (($currentPage - 1) * $perPage) + 1;

                    foreach ($stores as $store):
                        $logoUrl = $store['logo_url'] ?? '';
                        $displayName = $store['shop_name'] ?? 'Unknown';
                        $email = $store['owner_email'] ?? $store['email'] ?? 'No Email';

                        $initials = strtoupper(substr($displayName, 0, 1));

                        $status = $store['status_label'] ?? 'Inactive';
                        $verificationStatus = $store['verification_status'] ?? 'Pending';
                        $metaKeyExists = strtolower((string) $verificationStatus) === 'verified';
                        $sitemapStatus = $store['sitemap_status'] ?? 'Pending';

                        $statusClass = getBadgeClass('status', $status);
                        $verifiedClass = getBadgeClass('verified', $verificationStatus);
                        $metaKeyClass = getBadgeClass('bool', $metaKeyExists);
                        $sitemapClass = getBadgeClass('sitemap', $sitemapStatus);

                        // Plan name (set in StoreManager)
                        $planLabel = $store['plan_name'] ?? 'Free';
                        $planClass = getBadgeClass('plan', $planLabel);
                        ?>

                        <tr>
                            <td><?= $i++; ?></td>

                            <td>
                                <div class="flex items-center gap-2">
                                    <?php if ($logoUrl): ?>
                                        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                            class="w-8 h-8 rounded-full object-cover" />
                                    <?php else: ?>
                                        <div
                                            class="w-8 h-8 rounded-full bg-cstm-secondary flex items-center justify-center text-sm font-bold text-white">
                                            <?= htmlspecialchars($initials, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>

                                    <div>
                                        <span class="block font-medium">
                                            <?= htmlspecialchars((string) ($displayName ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        </span>
                                        <span class="block text-xs text-secondary-light">
                                            <?= htmlspecialchars((string) ($email ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        </span>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <?php
                                // Prefer custom domain
                                $siteUrl = (string) ($store['domain'] ?? '');

                                // Fallback to myshopify domain
                                if (empty($siteUrl)) {
                                    $siteUrl = (string) ($store['shop_domain'] ?? '');
                                }

                                // Add https:// if missing
                                if (!empty($siteUrl) && strpos($siteUrl, 'http') !== 0) {
                                    $siteUrl = 'https://' . $siteUrl;
                                }

                                ?>
                                <?php if (!empty($siteUrl)): ?>
                                    <a href="<?= htmlspecialchars($siteUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank"
                                        class="text-primary-500 hover:text-primary-600">
                                        <?= htmlspecialchars($siteUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-neutral-400">—</span>
                                <?php endif; ?>
                            </td>

                            <td class="!text-center">
                                <?= htmlspecialchars((string) ($store['google_email'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </td>

                            <td>
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?= $planClass ?>">
                                    <?= htmlspecialchars((string) $planLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </span>
                            </td>

                            <td>
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?= $statusClass ?>">
                                    <?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </span>
                            </td>

                            <td>
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?= $verifiedClass ?>">
                                    <?= htmlspecialchars(ucfirst((string) $verificationStatus), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </span>
                            </td>

                            <td>
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?= $metaKeyClass ?>">
                                    <?= $metaKeyExists ? 'Yes' : 'No' ?>
                                </span>
                            </td>

                            <td>
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?= $sitemapClass ?>">
                                    <?= htmlspecialchars($sitemapStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </span>
                            </td>

                            <td>
                                <?php
                                $createdDate = !empty($store['created_at']) ? new DateTime($store['created_at']) : null;
                                echo $createdDate ? $createdDate->format('Y-m-d H:i:s') : 'N/A';
                                ?>
                            </td>

                            <td class="flex justify-center gap-2">
                                <a href="store-view.php?instance_id=<?= urlencode($store['instance_id']) ?>"
                                    class="bg-cstm-primary-20 hover:bg-cstm-primary-30 w-10 h-10 flex justify-center items-center rounded-full text-cstm-primary"
                                    title="View Store">
                                    <iconify-icon icon="solar:eye-bold" class="text-xl"></iconify-icon>
                                </a>

                                <a href="<?= APP_BASE . '/dashboard.php?instanceid=' . urlencode($store['instance_id']) ?>"
                                    target="_blank"
                                    class="bg-cstm-primary-20 hover:bg-cstm-primary-30 w-10 h-10 flex justify-center items-center rounded-full text-cstm-primary"
                                    title="Login Into Store">
                                    <iconify-icon icon="solar:login-2-bold" class="text-xl"></iconify-icon>
                                </a>

                                <a href="store-emails.php?instance_id=<?= urlencode($store['instance_id']) ?>"
                                    class="bg-warning-100 hover:bg-warning-600 text-warning-600 hover:text-white w-10 h-10 flex justify-center items-center rounded-full transition duration-300"
                                    title="Emails">
                                    <iconify-icon icon="solar:letter-bold" class="text-xl"></iconify-icon>
                                </a>

                                <button type="button"
                                    onclick="copyReviewLink('<?php echo htmlspecialchars($store['instance_id'], ENT_QUOTES); ?>')"
                                    class="bg-cstm-primary-20 hover:bg-cstm-primary-30 w-10 h-10 flex justify-center items-center rounded-full text-cstm-primary"
                                    title="Copy Review Link">
                                    <i class="fa-solid fa-star text-base"></i>
                                </button>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
    <div class="flex items-center justify-between flex-wrap gap-2 mt-6">

        <span class="text-sm">
            Showing <?= (($currentPage - 1) * $perPage) + 1 ?>
            to <?= min($currentPage * $perPage, $totalStores) ?>
            of <?= $totalStores ?> entries
        </span>

        <ul class="pagination flex items-center gap-2">

            <!-- Previous -->
            <li class="<?= ($currentPage <= 1) ? 'opacity-50 pointer-events-none' : '' ?>">
                <a href="#"
                    class="page-link bg-neutral-100 dark:bg-neutral-700 rounded-lg h-8 w-8 flex items-center justify-center"
                    data-page="<?= max(1, $currentPage - 1) ?>">
                    <iconify-icon icon="ep:d-arrow-left"></iconify-icon>
                </a>
            </li>

            <?php
            $range = 2;
            for ($p = 1; $p <= $totalPages; $p++):
                if (
                    $p == 1 ||
                    $p == $totalPages ||
                    ($p >= $currentPage - $range && $p <= $currentPage + $range)
                ):
                    ?>
                    <li>
                        <a href="#" data-page="<?= $p ?>"
                            class="page-link rounded-lg h-8 w-8 flex items-center justify-center text-sm
                           <?= ($p == $currentPage) ? 'bg-cstm-primary text-white' : 'bg-neutral-100 dark:bg-neutral-700' ?>">
                            <?= $p ?>
                        </a>
                    </li>
                <?php elseif ($p == $currentPage - ($range + 1) || $p == $currentPage + ($range + 1)): ?>
                    <li><span class="h-8 w-8 flex items-center justify-center">...</span></li>
                <?php endif;
            endfor; ?>

            <!-- Next -->
            <li class="<?= ($currentPage >= $totalPages) ? 'opacity-50 pointer-events-none' : '' ?>">
                <a href="#"
                    class="page-link bg-neutral-100 dark:bg-neutral-700 rounded-lg h-8 w-8 flex items-center justify-center"
                    data-page="<?= min($totalPages, $currentPage + 1) ?>">
                    <iconify-icon icon="ep:d-arrow-right"></iconify-icon>
                </a>
            </li>

        </ul>
    </div>

    <?php
    return ob_get_clean();
}

/**
 * AJAX REQUEST HANDLER
 */
if (!empty($_GET['ajax'])) {
    echo renderStoresTable($stores, $currentPage, $perPage, $totalStores, $totalPages, $searchQuery);
    exit;
}

$script = <<<JS
<script>
$(document).ready(function () {

    let searchTimeout = null;

    function fetchStores(page = 1) {

        let perPage = $("#per-page-select").val();
        let search = $("#search-input").val().trim();

        $.get("stores.php", {
            ajax: 1,
            show: perPage,
            page: page,
            search: search
        }, function (html) {

            $(".stores-container-wrapper").html(html);

            // Update URL
            let newUrl = new URL(window.location.href);
            newUrl.searchParams.set("show", perPage);
            newUrl.searchParams.set("page", page);

            if (search) newUrl.searchParams.set("search", search);
            else newUrl.searchParams.delete("search");

            window.history.pushState({}, "", newUrl);
        });
    }

    $("#search-input").on("keyup", function () {
        // Clear previous timeout
        clearTimeout(searchTimeout);
        
        // Set new timeout to debounce search
        searchTimeout = setTimeout(function() {
            fetchStores(1);
        }, 300); // Wait 300ms after user stops typing
    });

    $("#per-page-select").on("change", function () {
        fetchStores(1);
    });

    $(document).on("click", ".pagination .page-link", function (e) {
        e.preventDefault();
        let page = $(this).data("page");
        if (page) fetchStores(page);
    });

});

</script>
JS;

include './partials/layouts/layoutTop.php';
?>

<div class="grid grid-cols-12">
    <div class="col-span-12">
        <div class="card h-full p-0 rounded-xl border-0 overflow-hidden">

            <div
                class="card-header border-b border-neutral-200 dark:border-neutral-600 bg-white dark:bg-neutral-700 py-4 px-6 flex items-center justify-between">
                <div class="flex items-center gap-3 overflow-x-auto">
                    <span class="text-base font-medium text-secondary-light">Show</span>

                    <select id="per-page-select"
                        class="form-select form-select-sm w-auto dark:bg-neutral-600 dark:text-white">
                        <option value="10" <?= ($perPage == 10) ? 'selected' : '' ?>>10</option>
                        <option value="25" <?= ($perPage == 25) ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= ($perPage == 50) ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= ($perPage == 100) ? 'selected' : '' ?>>100</option>
                    </select>

                    <input type="text" id="search-input" class="h-10 w-auto navbar-search-input form-select"
                        value="<?= htmlspecialchars((string) ($searchQuery ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        placeholder="Search">

                </div>
            </div>

            <div class="card-body p-6 stores-container-wrapper">
                <?= renderStoresTable($stores, $currentPage, $perPage, $totalStores, $totalPages, $searchQuery) ?>
            </div>

        </div>
    </div>
</div>

<?php include './partials/layouts/layoutBottom.php'; ?>
<script>
function copyReviewLink(instanceId) {

    const url =
        "https://makkpressapps.com/wordpress/googlesearchconsole/customer-reviews.php?instanceid=" +
        encodeURIComponent(instanceId);

    navigator.clipboard.writeText(url)
        .then(function () {

            if (typeof showAlert === "function") {
                showAlert("Review link copied successfully.", "success");
            } else {
                alert("Review link copied successfully.");
            }

        })
        .catch(function () {

            if (typeof showAlert === "function") {
                showAlert("Failed to copy review link.", "error");
            } else {
                alert("Failed to copy review link.");
            }

        });

}
</script>