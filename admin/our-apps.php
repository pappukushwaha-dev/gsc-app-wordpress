<?php
$title = 'Our Other Apps';
$subTitle = 'Manage Other Apps';

ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';
require_once __DIR__ . '/core/OtherAppsManager.php';

SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

OtherAppsManager::initialize($pdo);

// --- Handle Delete Request (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $appId = $_POST['id'] ?? '';

    if (empty($appId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'App ID is required.']);
        exit;
    }

    if (OtherAppsManager::deleteApp($appId)) { // Assuming deleteApp method exists in your manager
        echo json_encode(['success' => true, 'message' => 'App deleted successfully.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to delete app or app not found.']);
    }
    exit;
}


$perPage = (int)($_GET['show'] ?? 12);
$currentPage = (int)($_GET['page'] ?? 1);
$searchQuery = $_GET['search'] ?? '';

$apps = OtherAppsManager::getFilteredApps($searchQuery, $perPage, $currentPage);
$totalApps = OtherAppsManager::getTotalApps($searchQuery);
$totalPages = (int)ceil($totalApps / $perPage);

$script = '
<script>
document.addEventListener("DOMContentLoaded", () => {
    // --- AJAX Function to fetch and update apps ---
    function fetchApps(page = 1) {
        var perPage = $("#per-page-select").val();
        var searchQuery = $("#search-input").val();
        
        var url = "our-apps.php?show=" + perPage + "&page=" + page;
        if (searchQuery) {
            url += "&search=" + encodeURIComponent(searchQuery);
        }

        $.get(url, { ajax: 1 }, function(response) {
            $(".apps-container").html($(response).find(".apps-container").html());
            $(".pagination-container").html($(response).find(".pagination-container").html());
        });
    }

    // --- Delete Modal Logic ---
     const deleteModal = new Modal(document.getElementById("deleteConfirmModal"));
    const confirmDeleteButton = document.getElementById("confirmDeleteButton");
    const deleteMessage = document.getElementById("deleteMessage");

    let deleteTarget = null;

    $(document).on("click", ".delete-btn", function(e) {
        e.preventDefault();
        const id = $(this).data("id");
        const card = $(this).closest(".user-grid-card");

        deleteTarget = { id, card };
        deleteMessage.textContent = "Are you sure you want to delete this app?";
        deleteModal.show();
    });

    confirmDeleteButton.addEventListener("click", () => {
        if (!deleteTarget) return;

        $.post("our-apps.php", { id: deleteTarget.id, action: "delete" }, function(response) {
            if (response.success) {
                deleteTarget.card.fadeOut(300, function() { 
                    $(this).remove(); 
                    // After removing, refresh the list to update counts and pagination
                    fetchApps($("#pagination-controls .active").data("page") || 1);
                });
            } else {
                alert("Delete failed: " + response.error);
            }
        }).fail(function(xhr) {
            alert("An error occurred: " + xhr.responseText);
        }).always(function() {
            deleteTarget = null;
            deleteModal.hide();
        });
    });

    // --- Event Listeners for Filters and Pagination ---
    $("#search-input").on("keyup", function () { fetchApps(1); });
    $("#per-page-select").on("change", function () { fetchApps(1); });
    $(document).on("click", ".pagination a", function(e) {
        e.preventDefault();
        fetchApps($(this).data("page") || 1);
    });
});
</script>
';

include './partials/layouts/layoutTop.php';
?>

<div class="card h-full p-0 rounded-xl border-0 overflow-hidden">
    <div class="card-header border-b border-neutral-200 dark:border-neutral-600 py-4 px-6 flex items-center flex-wrap gap-3 justify-between">
        <div class="flex items-center flex-wrap gap-3">
            <span class="text-base font-medium text-secondary-light mb-0">Show</span>
            <select id="per-page-select" class="form-select form-select-sm w-auto dark:text-white border-neutral-200 dark:border-neutral-500 rounded-lg">
                <option value="12" <?= $perPage == 12 ? 'selected' : '' ?>>12</option>
                <option value="24" <?= $perPage == 24 ? 'selected' : '' ?>>24</option>
                <option value="36" <?= $perPage == 36 ? 'selected' : '' ?>>36</option>
                <option value="48" <?= $perPage == 48 ? 'selected' : '' ?>>48</option>
            </select>
            <form class="navbar-search" onsubmit="return false;">
                <input type="text" id="search-input" class="h-10 w-auto" name="search" placeholder="Search apps..." value="<?= htmlspecialchars($searchQuery) ?>">
                <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
            </form>
        </div>
        <a href="app-add.php" class="btn btn-cstm-primary text-sm px-7 py-3 rounded-lg flex items-center gap-2">
            <iconify-icon icon="ic:baseline-plus" class="icon text-xl"></iconify-icon>
            Add New App
        </a>
    </div>
    <div class="card-body p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 3xl:grid-cols-4 gap-6 apps-container">
            <?php if (empty($apps)): ?>
                <div class="col-span-full text-center py-12 text-neutral-500 dark:text-neutral-400">
                    <p class="text-lg">No apps found.</p>
                </div>
            <?php else: ?>
                <?php foreach ($apps as $app): ?>
                    <div class="user-grid-card">
                        <div class="relative border border-neutral-200 dark:border-neutral-600 rounded-2xl overflow-hidden h-full flex flex-col">
                            <span class="absolute top-3 left-3 text-white bg-cstm-primary text-sm px-3 py-1 drk-bg-cstm-primary rounded-md z-10">App</span>

                            <img src="assets/images/schema-template/bg.png" class="w-full object-cover">

                            <div class="dropdown absolute top-0 end-0 me-4 mt-4 z-20">
                                <button data-bs-toggle="dropdown" data-dropdown-toggle="dropdown-<?= $app['id'] ?>" class="bg-gradient-to-r from-white/50 w-8 h-8 rounded-lg border flex justify-center items-center text-white" type="button">
                                    <i class="ri-more-2-fill"></i>
                                </button>
                                <div id="dropdown-<?= $app['id'] ?>" class="z-30 hidden bg-white divide-y divide-gray-100 rounded-lg shadow-lg border dark:border-gray-600 w-44 dark:bg-gray-700">
                                    <ul class="p-2 text-sm text-gray-700 dark:text-gray-200">
                                        <li>
                                            <a href="app-edit.php?id=<?= htmlspecialchars($app['id']) ?>" class="rounded-lg w-full text-start px-4 py-2.5 hover:bg-gray-100 dark:hover:bg-gray-600 flex items-center gap-2">Edit</a>
                                        </li>
                                        <li>
                                            <button type="button" class="rounded-lg delete-btn w-full text-start px-4 py-2.5 hover:bg-danger-100 dark:hover:bg-danger-600/25 rounded hover:text-danger-500 flex items-center gap-2" data-id="<?= htmlspecialchars($app['id']) ?>">Delete</button>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <div class="px-6 pb-6 text-center mt--50">
                                <?php if (!empty($app['image_url'])): ?>
                                    <div class="flex justify-center items-center -mt-[50px] z-10 border bg-white border-width-2-px w-[100px] h-[100px] ms-auto me-auto rounded-full object-contain">
                                        <img src=".<?= htmlspecialchars($app['image_url']) ?>" class="w-full rounded-full">
                                    </div>
                                <?php else: ?>
                                    <div class=" -mt-[50px] z-10 border bg-cstm-primary border-width-2-px w-[100px] h-[100px] ms-auto me-auto rounded-full drk-bg-cstm-primary flex items-center justify-center text-8xl font-bold  bg-cstm-primary text-white dark:text-neutral-200">
                                        <?= strtoupper(substr($app['title'], 0, 1)) ?>
                                    </div>
                                <?php endif; ?>

                                <h6 class="text-lg mb-0 mt-1.5 h-6"><?= htmlspecialchars($app['title']) ?></h6>
                                <span class="mb-4 text-[13px] h-4"><?= htmlspecialchars($app['subtitle']) ?></span>

                                <div class="bcenter-border relative bg-gradient-to-r bg-cstm-primary-gradient-light drk-bg-cstm-primary-gradient-dark rounded-lg p-3 flex items-center justify-center  dark:bg-neutral-700">
                                    <p class="text-sm text-neutral-600 dark:text-neutral-300 m-0 cstm-limit-line-2">
                                        <?= htmlspecialchars($app['description']) ?>
                                    </p>
                                </div>

                                <!-- <div class="mt-4">
                                    <a href="<?= htmlspecialchars($app['button_link']) ?>" target="_blank" class="btn bg-cstm-primary-10 hover:bg-cstm-primary hover:drk-bg-cstm-primary hover:text-white dark:hover:text-white drk-bg-cstm-primary-30 text-cstm-primary dark:text-cstm-primary flex items-center justify-center gap-2 w-full">
                                        <iconify-icon icon="solar:external-link-bold-duotone"></iconify-icon>
                                        <?= htmlspecialchars($app['button_text']) ?>
                                    </a>
                                </div> -->
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="flex items-center justify-between flex-wrap gap-2 mt-6 pagination-container">
            <span>Showing <?= (($currentPage - 1) * $perPage) + 1 ?> to <?= min($currentPage * $perPage, $totalApps) ?> of <?= $totalApps ?> entries</span>
            <ul class="pagination flex flex-wrap items-center gap-2 justify-center">
                <li class="page-item <?= $currentPage <= 1 ? 'opacity-50 pointer-events-none' : '' ?>">
                    <a class="page-link bg-neutral-200 dark:bg-neutral-600 text-secondary-light font-semibold rounded-lg border-0 flex items-center justify-center h-8 w-8 text-base" href="#" data-page="<?= $currentPage - 1 ?>"><iconify-icon icon="ep:d-arrow-left"></iconify-icon></a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li><a class="page-link text-secondary-light font-semibold rounded-lg border-0 flex items-center justify-center h-8 w-8 text-base <?= $i == $currentPage ? 'active bg-cstm-primary text-white' : 'bg-neutral-300 dark:bg-neutral-600' ?>" data-page="<?= $i ?>" href="#"><?= $i ?></a></li>
                <?php endfor; ?>
                <li class="page-item <?= $currentPage >= $totalPages ? 'opacity-50 pointer-events-none' : '' ?>">
                    <a class="page-link bg-neutral-200 dark:bg-neutral-600 text-secondary-light font-semibold rounded-lg border-0 flex items-center justify-center h-8 w-8 text-base" href="#" data-page="<?= $currentPage + 1 ?>"><iconify-icon icon="ep:d-arrow-right"></iconify-icon></a>
                </li>
            </ul>
        </div>
    </div>
</div>

<div id="deleteConfirmModal" tabindex="-1" aria-hidden="true" class="modal fade hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="relative p-4 w-full max-w-2xl max-h-full bg-white rounded-lg shadow dark:bg-gray-700">
        <div class="modal-content">
            <div class="modal-body p-6 text-center">
                <svg class="mx-auto mb-4 text-gray-400 w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <h3 id="deleteMessage" class="mb-5 text-lg font-normal text-gray-600">Are you sure you want to delete this app?</h3>
                <div class="flex justify-center gap-3">
                    <button id="confirmDeleteButton" type="button" class="px-5 py-2.5 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700">Yes, delete</button>
                    <button data-modal-hide="deleteConfirmModal" type="button" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include './partials/layouts/layoutBottom.php'; ?>