<?php

// Include core files for session and authentication logic
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';
// --- FIX --- Removed duplicate APP_BASE definition. It should be in config.php

// Start the database-backed session
SessionManager::startDatabaseSession();

// Redirect to login if the user is not authenticated
if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}
$title = 'Instructions';
$subTitle = 'Documentation & Guides';

// Use Single-Quoted HEREDOC for the JavaScript block
$script = <<<'SCRIPT'
<script>
    document.addEventListener("DOMContentLoaded", function() {

        // --- MODAL INITIALIZATION ---
        const addSectionModalEl = document.getElementById("addSectionModal");
        const addCategoryModalEl = document.getElementById("addCategoryModal");
        const deleteModalEl = document.getElementById("deleteConfirmModal");
        const viewSectionModalEl = document.getElementById("viewSectionModal"); 

        const addSectionModal = new Modal(addSectionModalEl);
        const addCategoryModal = new Modal(addCategoryModalEl);
        const deleteModal = new Modal(deleteModalEl);
        const viewSectionModal = new Modal(viewSectionModalEl); 

        const confirmDeleteButton = document.getElementById("confirmDeleteButton");
        const deleteMessage = document.getElementById("deleteMessage");

 const sectionTableBody = document.getElementById("sectionTableBody");
const categoryTableBody = document.getElementById("categoryTableBody");

const commonAddButton = document.getElementById("commonAddButton");
const instructionsTabsContainer = document.getElementById("instructionsTabs");


        let deleteTarget = null;
        
        // (showNotification function is unchanged)
        const showNotification = (message, type) => {
            const notification = document.createElement("div");
            notification.className = `fixed top-5 right-5 p-4 rounded-md shadow-lg text-white z-[9999] opacity-0 transition-opacity duration-300`;
            if (type === "success") {
                notification.classList.add("bg-green-500");
            } else {
                notification.classList.add("bg-red-500");
            }
            notification.textContent = message;
            document.body.appendChild(notification);
            setTimeout(() => notification.style.opacity = "1", 10);
            setTimeout(() => {
                notification.style.opacity = "0";
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        };

        // (fetchInstructions function is unchanged)
        const fetchInstructions = async () => {
            try {
                const response = await fetch("./core/InstructionsManager.php");
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const data = await response.json();
                
                if (data.error) {
                    showNotification(data.error, "error");
                    return;
                }
                renderSectionsAndCategories(data);
updateCommonAddButtonName();


            } catch (error) {
                console.error("Error fetching instructions:", error);
                showNotification("Failed to load instructions. Please try again.", "error");
            }
        };





// ---------------- COMMON ADD BUTTON ----------------

const updateCommonAddButtonName = () => {
    const activeTab = document.querySelector(
        '#instructionsTabs button[aria-selected="true"]'
    );

    let text = "Add Instruction";

    if (activeTab?.id === "category-tab") {
        text = "Add Category";
    }

    commonAddButton.querySelector(".icon").nextSibling.textContent = text;
};

instructionsTabsContainer.addEventListener("click", (e) => {
    const btn = e.target.closest("button");
    if (!btn) return;

    if (btn.id === "sections-tab" || btn.id === "category-tab") {
        setTimeout(updateCommonAddButtonName, 50);
    }
});

commonAddButton.addEventListener("click", () => {
    const activeTab = document.querySelector(
        '#instructionsTabs button[aria-selected="true"]'
    );

    if (!activeTab) return;

    // Add Instruction
    if (activeTab.id === "sections-tab") {
        document.getElementById("editSectionId").value = "";
        document.getElementById("sectionTitle").value = "";
        document.getElementById("sectionSlug").value = "";
        document.getElementById("sectionContent").value = "";
        document.getElementById("sectionCategorySelect").value = "";

        addSectionModal.show(); // ✅ WORKS
    }

    // Add Category
    else if (activeTab.id === "category-tab") {
        document.getElementById("editCategoryId").value = "";
        document.getElementById("categoryTitle").value = "";

        addCategoryModal.show(); // ✅ WORKS
    }
});









        // (renderSectionsAndCategories function is unchanged from last time)
        const renderSectionsAndCategories = (data) => {
            sectionTableBody.innerHTML = "";
            categoryTableBody.innerHTML = "";
            const categorySelect = document.getElementById("sectionCategorySelect");
            categorySelect.innerHTML = '<option value="" disabled selected>Select a category...</option>';

            let allSections = [];
            
            data.forEach(category => {
                categoryTableBody.innerHTML += `
                    <tr>
                        <td>${category.name}</td>
                        <td class="px-6 py-4 flex items-center justify-center text-right">
                            <button type="button" class="font-medium text-blue-600 dark:text-blue-500 hover:underline me-3 edit-category-button bg-cstm-primary-20 drk-bg-cstm-primary-20 hover:bg-cstm-primary-30 text-cstm-primary drk-text-cstm-primary font-medium w-10 h-10 flex justify-center items-center rounded-full" data-category-id="${category.id}" data-category-name="${category.name}"><iconify-icon icon="tabler:edit" class="icon text-xl"></iconify-icon></button>
                            <button type="button" class="text-danger-600 dark:text-danger-600 bg-danger-100 hover:bg-danger-200 dark:bg-danger-600/20 font-medium w-10 h-10 flex justify-center items-center rounded-full delete-category-button" data-category-id="${category.id}"><iconify-icon icon="tabler:trash" class="icon text-lg"></iconify-icon></button>
                        </td>
                    </tr>
                `;

                const option = document.createElement("option");
                option.value = category.id;
                option.textContent = category.name;
                categorySelect.appendChild(option);
                
                category.sections.forEach(section => {
                    allSections.push({
                        ...section,
                        category_name: category.name,
                        category_id: category.id
                    });
                });
            });

            allSections.forEach(section => {
                const date = new Date(section.created_at).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
                const titleData = encodeURIComponent(section.title);
                const slugData = encodeURIComponent(section.slug);
                const contentData = encodeURIComponent(section.content);
                
                sectionTableBody.innerHTML += `
                    <tr class="bg-white border dark:bg-gray-800 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600" id="section-${section.id}">
                        <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">${section.title}</td>
                        <td class="px-6 py-4 text-sm text-cstm-primary drk-text-cstm-primary">${section.slug}</td>
                        <td class="px-6 py-4">${section.content.substring(0, 50)}${section.content.length > 50 ? '...' : ''}</td>
                        <td class="px-6 py-4 text-sm">${section.category_name}</td>
                        <td class="px-6 py-4 text-sm">${date}</td>
                        <td class="px-6 py-4 text-start flex justify-center">

                            <button type="button" class="font-medium text-blue-600 dark:text-blue-500 hover:underline me-3 view-section-button bg-cstm-primary-20 drk-bg-cstm-primary-20 hover:bg-cstm-primary-30 text-cstm-primary drk-text-cstm-primary font-medium w-10 h-10 flex justify-center items-center rounded-full" 
                                data-title="${titleData}" 
                                data-slug="${slugData}" 
                                data-content="${contentData}" 
                                data-category-name="${section.category_name}">
                                <iconify-icon icon="tabler:eye" class="icon text-xl"></iconify-icon>
                            </button>

                            <button type="button" class="font-medium text-blue-600 dark:text-blue-500 hover:underline me-3 edit-section-button bg-cstm-primary-20 drk-bg-cstm-primary-20 hover:bg-cstm-primary-30 text-cstm-primary drk-text-cstm-primary font-medium w-10 h-10 flex justify-center items-center rounded-full" 
                                data-section-id="${section.id}" 
                                data-title="${titleData}" 
                                data-slug="${slugData}" 
                                data-content="${contentData}" 
                                data-category-id="${section.category_id}"><iconify-icon icon="tabler:edit" class="icon text-xl"></iconify-icon>
                            </button>

                            <button type="button" class="text-danger-600 dark:text-danger-600 bg-danger-100 hover:bg-danger-200 dark:bg-danger-600/20 font-medium w-10 h-10 flex justify-center items-center rounded-full delete-section-button" data-section-id="${section.id}">
                                <iconify-icon icon="tabler:trash" class="icon text-lg"></iconify-icon>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            initFlowbite();
        };

        const clickHandler = (e) => {
            const target = e.target.closest("button");
            if (!target) return;
            
            // --- Section Actions (Edit/Add) ---
            if (target.classList.contains("add-section-button") || target.classList.contains("edit-section-button")) {
                const isEdit = target.classList.contains("edit-section-button");
                
                document.getElementById("editSectionId").value = "";
                document.getElementById("sectionTitle").value = "";
                document.getElementById("sectionSlug").value = "";
                document.getElementById("sectionContent").value = "";
                document.getElementById("sectionCategorySelect").value = isEdit ? target.getAttribute("data-category-id") : ""; 

                if (isEdit) {
                    document.getElementById("editSectionId").value = target.getAttribute("data-section-id");
                    document.getElementById("sectionTitle").value = decodeURIComponent(target.getAttribute("data-title"));
                    document.getElementById("sectionSlug").value = decodeURIComponent(target.getAttribute("data-slug"));
                    document.getElementById("sectionContent").value = decodeURIComponent(target.getAttribute("data-content"));
                }
                
                addSectionModal.show();
            }

            // --- View Button Handler ---
            else if (target.classList.contains("view-section-button")) {
                const title = decodeURIComponent(target.getAttribute("data-title"));
                const slug = decodeURIComponent(target.getAttribute("data-slug"));
                const content = decodeURIComponent(target.getAttribute("data-content"));
                const category = target.getAttribute("data-category-name");

                document.getElementById("viewSectionTitle").textContent = title;
                document.getElementById("viewSectionSlug").textContent = slug;
                document.getElementById("viewSectionCategory").textContent = category;
                
                // --- THIS IS THE FIX ---
                // Use .innerHTML to render HTML tags, not .textContent
                document.getElementById("viewSectionContent").innerHTML = content;
                // --- END OF FIX ---

                viewSectionModal.show();
            }
            
            // --- Category Actions (Edit/Add) ---
            else if (target.classList.contains("add-category-button") || target.classList.contains("edit-category-button")) {
                const isEdit = target.classList.contains("edit-category-button");
                document.getElementById("editCategoryId").value = "";
                document.getElementById("categoryTitle").value = "";
                if (isEdit) {
                    document.getElementById("editCategoryId").value = target.getAttribute("data-category-id");
                    document.getElementById("categoryTitle").value = target.getAttribute("data-category-name");
                }
                addCategoryModal.show();
            }
            
            // --- Delete Section ---
            else if (target.classList.contains("delete-section-button")) {
                const sectionId = target.getAttribute("data-section-id");
                deleteTarget = { type: "section", id: sectionId };
                deleteMessage.textContent = "Are you sure you want to delete this instruction section?";
                deleteModal.show();
            }
            
            // --- Delete Category ---
            else if (target.classList.contains("delete-category-button")) {
                const categoryId = target.getAttribute("data-category-id");
                deleteTarget = { type: "category", id: categoryId };
                deleteMessage.textContent = "Are you sure you want to delete this category and all its associated sections? This cannot be undone.";
                deleteModal.show();
            }
            
            // --- MODAL OVERLAY FIX ---
            if (target.getAttribute("data-modal-hide") === "addSectionModal") {
                addSectionModal.hide();
            } else if (target.getAttribute("data-modal-hide") === "addCategoryModal") {
                addCategoryModal.hide();
            } else if (target.getAttribute("data-modal-hide") === "deleteConfirmModal") {
                deleteModal.hide();
            } else if (target.getAttribute("data-modal-hide") === "viewSectionModal") {
                viewSectionModal.hide();
            }
        };

        // (confirmDeleteButton handler is unchanged)
        confirmDeleteButton.addEventListener("click", () => {
            if (!deleteTarget) return;
      let payload = {
    id: deleteTarget.id,
    type: deleteTarget.type // "section" or "category"
};

            
            fetch("./core/InstructionsManager.php", {
                method: "DELETE",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, "success");
                    fetchInstructions();
                } else {
                    showNotification(data.message, "error");
                }
            })
            .catch(error => console.error("Error deleting:", error))
            .finally(() => {
                deleteTarget = null;
                deleteModal.hide(); 
            });
        });

        // Event delegation listener
        document.addEventListener("click", clickHandler);

        // (saveSectionButton handler is unchanged)
        document.getElementById("saveSectionButton").addEventListener("click", function() {
            const title = document.getElementById("sectionTitle").value;
            const slug = document.getElementById("sectionSlug").value;
            const content = document.getElementById("sectionContent").value;
            const editSectionId = document.getElementById("editSectionId").value;
            const categoryId = document.getElementById("sectionCategorySelect").value; 

            if (title === "" || slug === "" || content === "" || categoryId === "") {
                showNotification("Title, Slug, Content, and Category are required.", "error");
                return;
            }
            const formData = {
                id: editSectionId || null,
                category_id: categoryId,
                title: title,
                slug: slug,
                content: content
            };
            const method = editSectionId ? "PUT" : "POST";
            const url = "./core/InstructionsManager.php";
            fetch(url, {
                method: method,
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, "success");
                    addSectionModal.hide(); 
                    fetchInstructions(); 
                } else {
                    showNotification(data.message, "error");
                }
            })
            .catch(error => console.error("Error saving section:", error));
        });

        // (saveCategoryButton handler is unchanged)
        document.getElementById("saveCategoryButton").addEventListener("click", function() {
            const categoryTitle = document.getElementById("categoryTitle").value;
            const categoryId = document.getElementById("editCategoryId").value;
            if (categoryTitle === "") {
                showNotification("Category title cannot be empty.", "error");
                return;
            }
            const formData = { id: categoryId || null, name: categoryTitle };
            const method = categoryId ? "PUT" : "POST";
            const url = "./core/InstructionsManager.php";
            fetch(url, {
                method: method,
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, "success");
                    addCategoryModal.hide(); 
                    fetchInstructions(); 
                } else {
                    showNotification(data.message, "error");
                }
            })
            .catch(error => console.error("Error saving category:", error));
        });

        // Initial fetch
        fetchInstructions();
    });



</script>
SCRIPT;
?>

<?php include './partials/layouts/layoutTop.php' ?>

<div class="grid grid-cols-12 card border-none">
    <div class="col-span-12 p-6">
        
<div class="flex not_flex_mobile justify-between items-center mb-4">

    <ul class="ftab-style-gradient cstm-tab-style-gradient flex overflow-y-auto -mb-px text-sm font-medium text-center"
        id="instructionsTabs"
        data-tabs-toggle="#instructionsTabContent"
        role="tablist">

        <li role="presentation" class="shrink-0">
            <button class="py-2.5 px-4 font-semibold text-base inline-flex items-center gap-3"
                id="sections-tab"
                data-tabs-target="#sections-table-content"
                type="button"
                role="tab"
                aria-selected="true">
                <iconify-icon icon="ph:list-bullets" class="icon text-lg me-1"></iconify-icon>
                Instructions Table
            </button>
        </li>

        <li role="presentation" class="shrink-0">
            <button class="py-2.5 px-4 font-semibold text-base inline-flex items-center gap-3"
                id="category-tab"
                data-tabs-target="#category-table-content"
                type="button"
                role="tab"
                aria-selected="false">
                <iconify-icon icon="fluent:folder-24-regular" class="icon text-lg me-1"></iconify-icon>
                Category Management
            </button>
        </li>
    </ul>

    <!-- ✅ COMMON ADD BUTTON -->
    <button type="button" class="btn btn-cstm-primary my-4_mobile flex gap-2 justify-center items-center" id="commonAddButton">
        <iconify-icon icon="ic:baseline-plus" class="icon text-xl line-height-1"></iconify-icon>
        Add Instruction
    </button>

</div>

        
        <div id="instructionsTabContent">
            
            <div class="hidden" id="sections-table-content" role="tabpanel" aria-labelledby="sections-tab">


                <div class="relative cstm-scroll-sm overflow-x-auto">
                    <table class="table bordered-table sm-table mb-0 table-auto text-sm">
                        <thead class="bg-gray-100">
                            <tr>
                                <th>Title</th>
                                <th>Slug</th>
                                <th>Content Snippet</th>
                                <th>Category</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="sectionTableBody">
                            </tbody>
                    </table>
                </div>
            </div>

            <div class="hidden" id="category-table-content" role="tabpanel" aria-labelledby="category-tab">
       
                
                <div class="relative cstm-scroll-sm overflow-x-auto">
                    <table class="table bordered-table sm-table mb-0 table-auto text-sm">
                        <thead class="bg-gray-100">
                            <tr>
                                <th>Category Name</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="categoryTableBody">
                            </tbody>
                    </table>
                </div>
            </div>
            
        </div>
    </div>
</div>

<div id="addSectionModal" tabindex="-1" aria-hidden="true" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 max-h-full">
    <div class="relative p-4 w-full max-w-2xl max-h-full">
        <div class="relative bg-white rounded-lg shadow dark:bg-dark-2 flex flex-col max-h-[95vh]">
            <div class="flex items-center justify-between p-4 md:p-5 border-b rounded-t dark:border-gray-600">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Add/Edit Section</h3>
                <button type="button" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ms-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white" data-modal-hide="addSectionModal">
                    <svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6" />
                    </svg>
                    <span class="sr-only">Close modal</span>
                </button>
            </div>
            <div class="p-4 md:p-5 space-y-4 overflow-y-auto cstm-scroll-sm">
                <form id="sectionForm">
                    <input type="hidden" id="editSectionId" value="">
                    
                    <div class="mb-3">
                        <label for="sectionTitle" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Title</label>
                        <input type="text" class="form-control" placeholder="Enter Title" id="sectionTitle" required>
                    </div>
                    <div class="mb-3">
                        <label for="sectionSlug" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Slug</label>
                        <input type="text" class="form-control" placeholder="Enter Slug" id="sectionSlug" required>
                    </div>

                    <div class="mb-3">
                         <label for="sectionCategorySelect" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Category</label>
                         <select id="sectionCategorySelect" class="form-control" required>
                            <option value="" disabled selected>Select a category...</option>
                            </select>
                    </div>

                    <div class="mb-3">
                        <label for="sectionContent" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Content</label>
                        <textarea class="form-control" id="sectionContent" rows="6" placeholder="Write the content" required></textarea>
                    </div>
                </form>
            </div>
            <div class="flex items-center justify-center gap-4 p-4 md:p-5 border-t border-gray-200 rounded-b dark:border-gray-600">
                <button type="button" data-modal-hide="addSectionModal" class="btn btn-cstm-primary light">Cancel</button>
                <button type="submit" class="btn btn-cstm-primary" id="saveSectionButton">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<div id="addCategoryModal" tabindex="-1" aria-hidden="true" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 max-h-full">
    <div class="relative p-4 w-full max-w-2xl max-h-full">
        <div class="relative bg-white rounded-lg shadow dark:bg-dark-2 flex flex-col max-h-[95vh]">
            <div class="flex items-center justify-between p-4 md:p-5 border-b rounded-t dark:border-gray-600">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Add/Edit Category</h3>
                <button type="button" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ms-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white" data-modal-hide="addCategoryModal">
                    <svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6" />
                    </svg>
                    <span class="sr-only">Close modal</span>
                </button>
            </div>
            <div class="p-4 md:p-5 space-y-4 overflow-y-auto cstm-scroll-sm">
                <form id="categoryForm">
                    <input type="hidden" id="editCategoryId" value="">
                    <div class="mb-3">
                        <label for="categoryTitle" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Category Name</label>
                        <input type="text" class="form-control" placeholder="Enter Category Name" id="categoryTitle" required>
                    </div>
                </form>
            </div>
            <div class="flex items-center justify-end gap-4 p-4 md:p-5 border-t border-gray-200 rounded-b dark:border-gray-600">
                <button type="button" data-modal-hide="addCategoryModal" class="btn btn-cstm-primary light">Cancel</button>
                <button type="submit" class="btn btn-cstm-primary" id="saveCategoryButton">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<div id="deleteConfirmModal" tabindex="-1" aria-hidden="true" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 max-h-full">
    <div class="relative p-4 w-full max-w-2xl max-h-full">
        <div class="relative bg-white rounded-lg shadow dark:bg-dark-2">
            <div class="p-5 text-center">
                <svg class="mx-auto mb-4 text-gray-400 w-12 h-12 dark:text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 id="deleteMessage" class="mb-5 text-lg font-normal text-gray-500 dark:text-gray-400">Are you sure you want to delete this item?</h3>
                <button id="confirmDeleteButton" type="button" class="text-white bg-red-600 hover:bg-red-800 focus:ring-4 focus:outline-none focus:ring-red-300 btn inline-flex items-center text-center me-2">Yes, delete</button>
                <button type="button" data-modal-hide="deleteConfirmModal" class="btn btn-cstm-primary light">Cancel</button>
            </div>
        </div>
    </div>
</div>

<div id="viewSectionModal" tabindex="-1" aria-hidden="true" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 max-h-full">
    <div class="relative p-4 w-full max-w-2xl max-h-full">
        <div class="relative bg-white rounded-lg shadow dark:bg-dark-2 flex flex-col max-h-[95vh]">
            <div class="flex items-center justify-between p-4 md:p-5 border-b rounded-t dark:border-gray-600">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">View Instruction</h3>
                <button type="button" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ms-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white" data-modal-hide="viewSectionModal">
                    <svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6" />
                    </svg>
                    <span class="sr-only">Close modal</span>
                </button>
            </div>
            <div class="p-4 md:p-5 space-y-4 overflow-y-auto cstm-scroll-sm">
                <div class="border-b border-gray-300 pb-3">
                    <label class="text-sm font-semibold text-neutral-900 dark:text-neutral-200">Title</label>
                    <p id="viewSectionTitle" class="text-gray-600 dark:text-white mt-1"></p>
                </div>
                <div class="border-b border-gray-300 pb-3">
                    <label class="text-sm font-semibold text-neutral-900 dark:text-neutral-200">Slug</label>
                    <p id="viewSectionSlug" class="text-gray-600 dark:text-white mt-1"></p>
                </div>
                <div class="border-b border-gray-300 pb-3">
                    <label class="text-sm font-semibold text-neutral-900 dark:text-neutral-200">Category</label>
                    <p id="viewSectionCategory" class="text-gray-600 dark:text-white mt-1"></p>
                </div>
                <div>
                    <label class="text-sm font-semibold text-neutral-900 dark:text-neutral-200">Content</label>
                    <p id="viewSectionContent" class="text-gray-600 dark:text-white mt-1 whitespace-pre-wrap"></p>
                </div>
            </div>
            <div class="flex items-center justify-end gap-4 p-4 md:p-5 border-t border-gray-200 rounded-b dark:border-gray-600">
                <button type="button" data-modal-hide="viewSectionModal" class="btn btn-cstm-primary">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include './partials/layouts/layoutBottom.php' ?>