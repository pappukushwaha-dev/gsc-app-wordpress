<?php
$title = 'Reviews';
$subTitle = 'Reviews';

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';
require_once __DIR__ . '/core/ReviewManager.php';
require_once __DIR__ . '/core/CancelPlanManager.php';

SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

CancelPlanManager::initialize($pdo);

// Pagination / Search
$perPage = (int)($_GET['show'] ?? 10);
$currentPage = (int)($_GET['page'] ?? 1);
$searchQuery = $_GET['search'] ?? '';

$reviews = CancelPlanManager::getFilteredReviews($searchQuery, $perPage, $currentPage);
$totalRows = CancelPlanManager::getTotalReviews($searchQuery);
$totalPages = max(1, ceil($totalRows / $perPage));

// AJAX Reload Script
$script = '
<script>
    $(document).ready(function () {

        function fetchReviews(page = 1) {
            var perPage = $("#per-page-select").val();
            var searchQuery = $("#search-input").val();

            var url = "cancel-plan.php?show=" + perPage + "&page=" + page;
            if (searchQuery) {
                url += "&search=" + encodeURIComponent(searchQuery);
            }

            $.get(url, { ajax: 1 }, function (response) {

                $(".reviews-container").html($(response).find(".reviews-container").html());
                $(".pagination").html($(response).find(".pagination").html());

                // Update browser URL
                var newUrl = new URL(window.location.href);
                newUrl.searchParams.set("show", perPage);
                newUrl.searchParams.set("page", page);
                if (searchQuery) newUrl.searchParams.set("search", searchQuery);
                else newUrl.searchParams.delete("search");

                window.history.pushState({}, "", newUrl);
            });
        }

        $("#search-input").on("keyup", function () {
            fetchReviews(1);
        });

        $("#per-page-select").on("change", function () {
            fetchReviews(1);
        });

        $(document).on("click", ".pagination a", function (e) {
            e.preventDefault();
            var page = $(this).data("page") || 1;
            fetchReviews(page);
        });

    });
</script>
';

include './partials/layouts/layoutTop.php';
?>

<div class="card rounded-xl border-0 overflow-hidden">

    <div class="card-header border-b bg-white dark:bg-neutral-700 py-4 px-6 flex items-center gap-3 justify-between">
        <div class="flex items-center gap-3">
            <span class="text-base font-medium">Show</span>

            <select id="per-page-select"
                class="form-select form-select-sm w-auto dark:bg-neutral-600 rounded-lg">
                <option value="10" <?= $perPage == 10 ? 'selected' : '' ?>>10</option>
                <option value="25" <?= $perPage == 25 ? 'selected' : '' ?>>25</option>
                <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $perPage == 100 ? 'selected' : '' ?>>100</option>
            </select>

            <form class="navbar-search" onsubmit="return false;">
                <input type="text" id="search-input"
                    class="bg-white dark:bg-neutral-700 h-10"
                    placeholder="Search"
                    value="<?= htmlspecialchars($searchQuery) ?>">
                <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
            </form>
        </div>
    </div>

    <div class="card-body p-6">

        <div class="reviews-container overflow-x-auto">

            <table class="table bordered-table sm-table text-sm">
                <thead>
                    <tr>
                        <th>S.No</th>
                        <th>Store Name</th>
                        <th>Store URL</th>
                        <th>Email</th>
                        <th>Rating</th>
                        <th>Comment</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($reviews)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-6 text-neutral-500">No reviews found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reviews as $index => $r): ?>
                            <tr>
                                <td><?= (($currentPage - 1) * $perPage) + $index + 1 ?></td>

                                <td><?= htmlspecialchars($r['store_name'] ?? 'N/A') ?></td>

                                <td>
                                    <?php if (!empty($r['site_url'])): ?>
                                        <a href="<?= htmlspecialchars($r['site_url']) ?>" target="_blank" class="text-blue-600 underline">
                                            <?= htmlspecialchars($r['site_url']) ?>
                                        </a>
                                    <?php else: ?> N/A <?php endif; ?>
                                </td>

                                <td><?= htmlspecialchars($r['email']) ?></td>

                                <td class="font-semibold text-yellow-600"><?= $r['rating'] ?>/10</td>

                                <td><?= htmlspecialchars($r['comment']) ?></td>

                                <td><?= date("Y-m-d H:i", strtotime($r['created_at'])) ?></td>

                                <td class="flex gap-2">

                                    <!-- <button class="bg-cstm-primary-10 hover:bg-cstm-primary-20 drk-bg-cstm-primary-20 text-cstm-primary drk-text-cstm-primary w-10 h-10 flex justify-center items-center rounded-full"
                                        data-review='<?= json_encode($r, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'
                                        onclick='showReviewModal(JSON.parse(this.dataset.review))'>
                                        <i class="fa-solid fa-eye text-lg"></i>
                                    </button> -->

                                    <button class="bg-danger-100 hover:bg-danger-600/25 text-danger-600 dark:bg-danger-600/20 dark:hover:bg-danger-600/25 w-10 h-10 flex justify-center items-center rounded-full"
                                        onclick="deleteReview(<?= $r['id'] ?>)">
                                        <i class="fa-solid fa-trash text-lg"></i>
                                    </button>

                                </td>

                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>

            </table>

        </div>

        <div class="flex items-center justify-between mt-6">

            <span>Showing <?= (($currentPage - 1) * $perPage) + 1 ?>
                to <?= min($currentPage * $perPage, $totalRows) ?>
                of <?= $totalRows ?> entries
            </span>

            <ul class="pagination flex gap-2">

                <li class="<?= ($currentPage <= 1) ? 'opacity-50 pointer-events-none' : '' ?>">
                    <a data-page="<?= $currentPage - 1 ?>"
                        class="page-link bg-neutral-100 dark:bg-neutral-600 h-8 w-8 flex justify-center items-center rounded-lg text-sm"
                        href="#">
                        <iconify-icon icon="ep:d-arrow-left"></iconify-icon>
                    </a>
                </li>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li>
                        <a data-page="<?= $i ?>"
                            class="page-link h-8 w-8 flex justify-center items-center rounded-lg text-sm
                            <?= ($i == $currentPage) ? 'bg-cstm-primary text-white' : 'bg-neutral-100 dark:bg-neutral-600' ?>"
                            href="#">
                            <?= $i ?>
                        </a>
                    </li>
                <?php endfor; ?>

                <li class="<?= ($currentPage >= $totalPages) ? 'opacity-50 pointer-events-none' : '' ?>">
                    <a data-page="<?= $currentPage + 1 ?>"
                        class="page-link bg-neutral-100 dark:bg-neutral-600 h-8 w-8 flex justify-center items-center rounded-lg text-sm"
                        href="#">
                        <iconify-icon icon="ep:d-arrow-right"></iconify-icon>
                    </a>
                </li>

            </ul>
        </div>

    </div>
</div>

<div id="globalModal"
    class="fixed inset-0 bg-cstm-black-50 hidden items-center justify-center z-50 backdrop-blur-sm">

    <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-lg max-w-2xl w-full p-6 relative">

        <button id="modalCloseBtn"
            class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-xl">
            &times;
        </button>

        <div id="modalContent"></div>

        <div class="mt-6 text-right flex justify-end gap-3">
            <button id="modalCancelBtn"
                class="btn btn-cstm-muted hidden">
                Cancel
            </button>

            <button id="modalConfirmBtn"
                class="btn btn-cstm-primary hidden">
                Confirm
            </button>
        </div>
    </div>
</div>

<script>
    const modal = document.getElementById("globalModal");
    const modalContent = document.getElementById("modalContent");
    const modalClose = document.getElementById("modalCloseBtn");
    const modalCancel = document.getElementById("modalCancelBtn");
    const modalConfirm = document.getElementById("modalConfirmBtn");

    function openModal(html, {
        showConfirm = false,
        confirmText = "Confirm",
        onConfirm = null
    } = {}) {
        modalContent.innerHTML = html;

        // 1. Handle Visibility
        if (showConfirm) {
            modalCancel.classList.remove("hidden");
            modalConfirm.classList.remove("hidden");
        } else {
            modalCancel.classList.add("hidden");
            modalConfirm.classList.add("hidden");
        }

        // 2. Update Button Text
        modalConfirm.textContent = confirmText;

        // Reset classes first
        modalConfirm.className = "btn";

        // Apply style based on action
        if (confirmText.toLowerCase() === "delete") {
            modalConfirm.classList.add("btn-cstm-danger");
        } else {
            modalConfirm.classList.add("btn-cstm-primary");
        }


        // 3. Handle Click Event (FIX: Just overwrite .onclick instead of replaceChild)
        modalConfirm.onclick = null; // Clear old handlers

        if (showConfirm && onConfirm) {
            modalConfirm.onclick = () => {
                onConfirm();
                closeModal();
            };
        }

        modal.classList.remove("hidden");
        modal.classList.add("flex");
    }

    function closeModal() {
        modal.classList.add("hidden");
        modal.classList.remove("flex");
    }

    modalClose.onclick = closeModal;
    modalCancel.onclick = closeModal;

    // Close on click outside
    window.onclick = function(event) {
        if (event.target == modal) {
            closeModal();
        }
    }
</script>

<script>
    function showReviewModal(data) {

        let ratingColor = data.rating >= 8 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400';
        let html = `
                <h2 class="text-2xl font-bold mb-6 text-gray-900 dark:text-white flex items-center">
                    <i class="fa-regular fa-file-lines h-6 w-6 mr-2"></i>
                    Review Details
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4 text-sm">
                    
                    <div class="space-y-3">
                        <div class="pb-2 border-b border-gray-100 dark:border-gray-600">
                            <p class="font-medium text-gray-500 dark:text-gray-400">Store Name</p>
                            <p class="text-gray-800 dark:text-gray-200">${data.store_name ?? 'N/A'}</p>
                        </div>
                        <div class="pb-2 border-b border-gray-100 dark:border-gray-600">
                            <p class="font-medium text-gray-500 dark:text-gray-400">Store URL</p>
                            ${data.site_url ? `<a href="${data.site_url}" target="_blank" class="text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300 transition truncate block">${data.site_url}</a>` : '<p class="text-gray-800 dark:text-gray-200">N/A</p>'}
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div class="pb-2 border-b border-gray-100 dark:border-gray-600">
                            <p class="font-medium text-gray-500 dark:text-gray-400">Email</p>
                            <p class="text-gray-800 dark:text-gray-200">${data.email}</p>
                        </div>
                        <div class="pb-2 border-b border-gray-100 dark:border-gray-600">
                            <p class="font-medium text-gray-500 dark:text-gray-400">Date Submitted</p>
                            <p class="text-gray-800 dark:text-gray-200">${data.created_at}</p>
                        </div>
                    </div>
                    
                    <div class="col-span-full pt-4 md:pt-0">
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg shadow-inner">
                            <p class="text-lg font-semibold text-gray-900 dark:text-white">Rating</p>
                            <p class="text-2xl font-extrabold ${ratingColor}">${data.rating}<span class="text-base font-normal">/10</span></p>
                        </div>
                    </div>

                    <div class="col-span-full pt-4 border-t border-gray-200 dark:border-gray-600">
                        <p class="font-medium text-gray-500 dark:text-gray-400 mb-2">Comment</p>
                        <div class="p-3 bg-gray-50 dark:bg-gray-700 rounded-lg text-gray-800 dark:text-gray-200 italic">
                            ${data.comment || 'No comment provided.'}
                        </div>
                    </div>
                </div>
        `;
        openModal(html, {
            showConfirm: false
        });
    }

    function deleteReview(id) {
        let html = `
                <h2 class="text-xl font-bold mb-4 text-danger-600 dark:text-danger-600">Delete Review</h2>
                <p class="text-sm text-neutral-600 dark:text-neutral-300">
                    Are you sure you want to delete this review permanently?
                </p>
            `;

        openModal(html, {
            showConfirm: true,
            confirmText: "Delete",
            onConfirm: () => {
                $.post("api/delete-review.php", {
                    id
                }, function(res) {
                    if (res.success) {
                        location.reload();
                    } else {
                        openModal(`<p class="text-red-600 font-medium">${res.message}</p>`);
                    }
                }, "json").fail(function(err) {
                    console.error("Delete API Error:", err);
                });

            }
        });
    }
</script>

<?php include './partials/layouts/layoutBottom.php'; ?>