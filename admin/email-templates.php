<?php
// Include core files for session and authentication logic
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';

// Include the database connection file
require_once __DIR__ . '/../includes/db.php';  // This ensures $pdo is available

// Start the database-backed session
SessionManager::startDatabaseSession();

// Redirect to login if the user is not authenticated
if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

$title = 'Email Template Manager';
$subTitle = 'Manage Templates and Categories';

// Debugging: Log a custom message indicating script execution
error_log("Debugging: email-template.php started at " . date('Y-m-d H:i:s'));

// Fetch Categories from the database using $pdo (since $pdo is the actual database connection)
$query = "SELECT * FROM email_categories";
$categoriesResult = $pdo->query($query);
$categories = $categoriesResult->fetchAll(PDO::FETCH_ASSOC);

// Debugging: Log categories fetched from the database
error_log("Debugging: Fetched categories: " . print_r($categories, true));

// Fetch Templates from the database using $pdo
$templateQuery = "SELECT * FROM email_templates";
$templatesResult = $pdo->query($templateQuery);
$templates = $templatesResult->fetchAll(PDO::FETCH_ASSOC);

// Debugging: Log templates fetched from the database
error_log("Debugging: Fetched templates: " . print_r($templates, true));

?>
<style>
    /* Hide the modal by default */
    .modal-container.hidden {
        display: none !important;
    }

    /* Show the modal when it's not hidden */
    .modal-container:not(.hidden) {
        display: flex !important;
    }
</style>
<?php include './partials/layouts/layoutTop.php' ?>

<div class="grid grid-cols-12 gap-6 p-6 card border-none">

    <!-- Templates List Section -->
    <div class="col-span-12 lg:col-span-8">
        <div class="h-full p-0 rounded-xl border border-gray-200 dark:border-gray-600 overflow-hidden shadow-sm">
            <div class="card-header border-b border-neutral-200 dark:border-neutral-600 bg-white dark:bg-neutral-700 py-4 px-6 flex items-center flex-wrap gap-3 justify-between">
                <div class="flex items-center flex-wrap gap-3">
                    <span class="text-sm font-medium text-secondary-light mb-0">Show</span>
                    <select class="form-select form-select-sm w-auto dark:bg-neutral-600 dark:text-white border-neutral-200 dark:border-neutral-500 rounded-lg" id="templateItemsPerPageSelect">
                        <option>10</option>
                        <option>25</option>
                        <option>50</option>
                        <option>100</option>
                    </select>
                    <form class="navbar-search" onsubmit="return false;">
                        <input type="text" id="templateSearch" class="bg-white dark:bg-neutral-700 h-10 w-auto" placeholder="Search Templates">
                        <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                    </form>
                </div>
                <div class="flex items-center gap-3">
                    <a href="email-template-add.php" class="btn btn-cstm-primary flex items-center gap-2">
                        <iconify-icon icon="tabler:plus" class="icon text-base line-height-1"></iconify-icon>
                        Add Template
                    </a>
                </div>
            </div>
            <div class="card-body p-6">
                <div class="cstm-scrollbar overflow-x-auto">
                    <table class="table bordered-table sm-table mb-0">
                        <thead>
                            <tr>
                                <th scope="col" class="w-20">
                                    <div class="flex items-center gap-10 text-sm">
                                        <div class="form-check style-check flex items-center">
                                            <input class="form-check-input rounded border input-form-dark" type="checkbox" name="checkbox" id="selectAll">
                                        </div>
                                        S.L
                                    </div>
                                </th>
                                <th scope="col">Template Name</th>
                                <th scope="col">Category Name</th>
                                <th scope="col" class="text-center w-28">Action</th>
                            </tr>
                        </thead>
                        <tbody id="templateTableBody">
                            <?php foreach ($templates as $index => $template): ?>
                                <?php
                                // Fetch category name for each template
                                $categoryQuery = "SELECT name FROM email_categories WHERE id = :category_id";
                                $stmt = $pdo->prepare($categoryQuery);
                                $stmt->execute(['category_id' => $template['category_id']]);
                                $category = $stmt->fetch(PDO::FETCH_ASSOC);
                                ?>
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-10 text-sm">
                                            <div class="form-check style-check flex items-center">
                                                <input class="form-check-input rounded border border-neutral-400" type="checkbox" name="checkbox" id="SL-<?= $template['id'] ?>">
                                            </div>
                                            <?= $index + 1 ?>
                                        </div>
                                    </td>
                                    <td id="template-<?= $template['id'] ?>">
                                        <span class="template-name text-sm mb-0 font-medium text-gray-800 dark:text-gray-200">
                                            <?= htmlspecialchars($template['name']) ?>
                                        </span>
                                    </td>

                                    <td><span class="text-sm mb-0 font-normal text-secondary-light"><?= htmlspecialchars($category['name']) ?></span></td>
                                    <td class="text-center">
                                        <div class="flex items-center gap-3 justify-center">
                                            <a href="email-template-edit.php?id=<?= $template['id'] ?>" class="bg-cstm-primary-20 drk-bg-cstm-primary-20 hover:bg-cstm-primary-30 text-cstm-primary drk-text-cstm-primary font-medium w-10 h-10 flex justify-center items-center rounded-full" title="Edit Template">
                                                <iconify-icon icon="tabler:edit" class="icon text-xl"></iconify-icon>
                                            </a>
                                            <button
                                                type="button"
                                                class="text-danger-600 dark:text-danger-600 bg-danger-100 hover:bg-danger-200 dark:bg-danger-600/20 font-medium w-10 h-10 flex justify-center items-center rounded-full"
                                                title="Delete Template"
                                                onclick="showDeleteConfirmation(<?= $template['id'] ?>, 'template')">
                                                <iconify-icon icon="tabler:trash" class="icon text-lg"></iconify-icon>
                                            </button>

                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between flex-wrap gap-2 mt-6">
                    <span id="templatePaginationInfo" class="text-sm"></span>
                    <ul class="pagination flex flex-wrap items-center gap-2 justify-center" id="templatePagination"></ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Category List Section -->
    <div class="col-span-12 lg:col-span-4">
        <div class="card rounded-xl border border-gray-200 dark:border-gray-600 shadow-sm p-6">
            <div class="mb-4 flex justify-between items-center">
                <h3 class="text-xl font-semibold text-neutral-600 dark:text-neutral-200 flex items-center gap-2">
                    <i class="fas fa-tags text-gray-600 dark:text-gray-200 text-base"></i> Categories
                </h3>
                <button
                    id="addNewCategoryBtn"
                    class="btn btn-cstm-primary btn-sm flex items-center gap-1"
                    data-modal-target="addEditCategoryModal">
                    <iconify-icon icon="tabler:plus" class="icon text-base line-height-1"></iconify-icon>
                    Add
                </button>

            </div>

            <div class="space-y-3" id="categoryListContainer">
                <?php foreach ($categories as $category): ?>
                    <div class="flex items-center justify-between p-3 bg-neutral-100 dark:bg-neutral-800 rounded-lg shadow-xs" id="category-<?= $category['id'] ?>">
                        <span class="font-medium text-sm text-gray-800 dark:text-gray-200 category-name"><?= htmlspecialchars($category['name']) ?></span>
                        <div class="flex items-center gap-2">
                            <button type="button" class="bg-cstm-primary-20 drk-bg-cstm-primary-20 hover:bg-cstm-primary-30 text-cstm-primary drk-text-cstm-primary font-medium w-10 h-10 flex justify-center items-center rounded-full" title="Edit Category" onclick="editCategory(<?= $category['id'] ?>)">
                                <iconify-icon icon="tabler:edit" class="icon text-lg"></iconify-icon>
                            </button>
                            <button
                                type="button"
                                class="text-danger-600 dark:text-danger-600 bg-danger-100 hover:bg-danger-200 dark:bg-danger-600/20 font-medium w-10 h-10 flex justify-center items-center rounded-full"
                                title="Delete Category"
                                onclick="showDeleteConfirmation(<?= $category['id'] ?>, 'category')">
                                <iconify-icon icon="tabler:trash" class="icon text-lg"></iconify-icon>
                            </button>

                        </div>
                    </div>
                <?php endforeach; ?>

            </div>

            <p id="noCategoriesMessage" class="text-sm text-neutral-500 mt-4 hidden">No categories defined.</p>
        </div>
    </div>
</div>


<!-- Add/Edit Category Modal -->
<div id="addEditCategoryModal" tabindex="-1" aria-hidden="true" class="hidden modal-container fixed flex items-center justify-center inset-0 modal-container z-50 bg-cstm-black-60">
    <div class="relative p-4 w-full max-w-2xl max-h-full">
        <div class="relative bg-white rounded-lg shadow dark:bg-dark-2 text-center">
            <div class="flex items-center justify-between p-4 md:p-5 border-b rounded-t dark:border-gray-600">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white" id="categoryModalTitle">Add New Category</h3>
                <button type="button" class="text-gray-400 bg-transparent hover:bg-gray-200 rounded-lg text-sm w-8 h-8 ms-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white close-modal-btn" data-modal-hide="addEditCategoryModal">
                    <iconify-icon icon="ic:round-close" class="text-lg"></iconify-icon>
                    <span class="sr-only">Close modal</span>
                </button>
            </div>
            <div class="p-4 md:p-5 space-y-4">
                <div class="mb-3 text-start">
                    <label for="categoryNameInput" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Category Name</label>
                    <input type="text" class="form-control" placeholder="e.g., Transactional" id="categoryNameInput" required>
                </div>
                <input type="hidden" id="categoryIdInput">
            </div>
            <div class="flex justify-end items-center gap-4 p-4 md:p-5 border-t border-gray-200 rounded-b dark:border-gray-600">
                <button type="button" data-modal-hide="addEditCategoryModal" class="btn btn-cstm-muted close-modal-btn">Cancel</button>
                <button type="button" class="btn btn-cstm-primary" id="saveCategoryButton">Save Category</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteConfirmationModal" tabindex="-1" aria-hidden="true" class="hidden modal-container fixed flex items-center justify-center inset-0 modal-container z-50 bg-cstm-black-60">
    <div class="relative p-4 w-full max-w-2xl max-h-full">
        <div class="relative bg-white rounded-lg shadow dark:bg-dark-2 text-center">
            <div class="p-4 md:p-5">
                <i class="fas fa-trash text-4xl text-danger-500 mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Are you sure?</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">Do you want to delete this item? This action cannot be undone.</p>
                <input type="hidden" id="deleteItemId">
                <input type="hidden" id="deleteItemType">
            </div>
            <div class="flex items-center justify-center gap-4 p-4 md:p-5 border-t border-gray-200 rounded-b dark:border-gray-600">
                <button type="button" class="btn btn-cstm-muted close-modal-btn" data-modal-hide="deleteConfirmationModal">No</button>
                <button type="button" class="btn btn-cstm-danger" id="confirmDeleteButton">Yes, Delete</button>
            </div>
        </div>
    </div>
</div>



<?php include './partials/layouts/layoutBottom.php' ?>

<script>
    document.addEventListener("DOMContentLoaded", function() {

        /* ------------------------------
           OPEN MODALS WITH data attributes
        ------------------------------ */
        document.querySelectorAll("[data-modal-target]").forEach(button => {
            button.addEventListener("click", function() {
                const modalId = button.getAttribute("data-modal-target");
                const modal = document.getElementById(modalId);

                if (modal) {
                    modal.classList.remove("hidden");
                    document.body.style.overflow = "hidden";
                }
            });
        });

        /* ------------------------------
           CLOSE MODALS
        ------------------------------ */
        document.querySelectorAll("[data-modal-hide], .close-modal-btn").forEach(button => {
            button.addEventListener("click", function() {
                const modalId = button.getAttribute("data-modal-hide") || "deleteConfirmationModal";
                const modal = document.getElementById(modalId);

                if (modal) {
                    modal.classList.add("hidden");
                    document.body.style.overflow = "";
                }
            });
        });

        /* ------------------------------
           CATEGORY SAVE LOGIC
        ------------------------------ */
        document.getElementById("saveCategoryButton").addEventListener("click", function() {

            const categoryName = document.getElementById("categoryNameInput").value.trim();
            const categoryId = document.getElementById("categoryIdInput").value;

            if (!categoryName) return alert("Category name cannot be empty");

            fetch("/wix/googlesearchconsole/admin/core/email/save-category.php", {
                    method: "POST",
                    body: JSON.stringify({
                        name: categoryName,
                        id: categoryId
                    }),
                    headers: {
                        "Content-Type": "application/json"
                    }
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert("Failed to save category");
                    }
                })
                .catch(() => alert("Error saving category"));
        });

        /* ------------------------------
           EDIT CATEGORY (POPULATE MODAL)
        ------------------------------ */
        window.editCategory = function(categoryId) {

            const categoryEl = document.querySelector(`#category-${categoryId} .category-name`);

            if (!categoryEl) return console.error("Category not found:", categoryId);

            document.getElementById("categoryNameInput").value = categoryEl.textContent.trim();
            document.getElementById("categoryIdInput").value = categoryId;

            document.getElementById("addEditCategoryModal").classList.remove("hidden");
            document.body.style.overflow = "hidden";
        };

        /* ------------------------------
           OPEN DELETE CONFIRM MODAL
        ------------------------------ */
        window.showDeleteConfirmation = function(id, type) {

            const modal = document.getElementById("deleteConfirmationModal");
            document.getElementById("deleteItemId").value = id;
            document.getElementById("deleteItemType").value = type;

            const itemTypeName = type === "category" ? "Category" : "Template";

            const itemName = type === "category" ?
                document.querySelector(`#category-${id} .category-name`)?.textContent.trim() :
                document.querySelector(`#template-${id} .template-name`)?.textContent.trim();

            document.querySelector("#deleteConfirmationModal .text-gray-500")
                .textContent = `Do you want to delete this ${itemTypeName}: ${itemName}?`;

            modal.classList.remove("hidden");
            document.body.style.overflow = "hidden";
        };

        /* ------------------------------
           CONFIRM DELETE
        ------------------------------ */
        document.getElementById("confirmDeleteButton").addEventListener("click", async function() {

            const id = document.getElementById("deleteItemId").value;
            const type = document.getElementById("deleteItemType").value;

            const endpoint = type === "category" ?
                "/wix/googlesearchconsole/admin/core/email/delete-category.php" :
                "/wix/googlesearchconsole/admin/core/email/delete-template.php";

            const response = await fetch(endpoint, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    id
                })
            });

            const result = await response.json();

            if (result.success) {
                location.reload();
            } else {
                alert("Failed to delete");
            }
        });

    });
</script>