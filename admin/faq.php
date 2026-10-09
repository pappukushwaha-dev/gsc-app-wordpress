<?php

// Include core files for session and authentication logic
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';

// Start the database-backed session
SessionManager::startDatabaseSession();

// Redirect to login if the user is not authenticated
if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}
$title = 'FAQ';
$subTitle = 'Frequently Asked Questions';

// Use Single-Quoted HEREDOC to prevent PHP syntax errors within the JS
// Use Single-Quoted HEREDOC to prevent PHP syntax errors within the JS
$script = <<<'SCRIPT'
<script>
    document.addEventListener("DOMContentLoaded", function() {
        
        // --- MODAL INITIALIZATION ---
        const addFaqModalEl = document.getElementById("addFaqModal");
        const addCategoryModalEl = document.getElementById("addCategoryModal");
        const deleteModalEl = document.getElementById("deleteConfirmModal");
        
        const addFaqModalInstance = new Modal(addFaqModalEl);
        const addCategoryModalInstance = new Modal(addCategoryModalEl);
        const deleteModalInstance = new Modal(deleteModalEl);

        const confirmDeleteButton = document.getElementById("confirmDeleteButton");
        const deleteMessage = document.getElementById("deleteMessage");
        const faqTableBody = document.getElementById("faqTableBody");
        const categoryTableBody = document.getElementById("categoryTableBody");
        
        // New constants moved inside for scope access
        const commonAddButton = document.getElementById("commonAddButton");
        const faqTabsContainer = document.getElementById("faqTabs");

        let deleteTarget = null;

        // Function to show a dynamic notification
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

        // Function to update button text dynamically
        const updateCommonAddButtonName = () => {
            const activeTabButton = document.querySelector('#faqTabs button[aria-selected="true"]');
            let buttonText = 'Add New';

            if (activeTabButton) {
                if (activeTabButton.id === "faq-tab") {
                    buttonText = 'Add FAQ';
                } else if (activeTabButton.id === "category-tab") {
                    buttonText = 'Add Category';
                }
            }
            commonAddButton.querySelector('.icon').nextSibling.textContent = ' ' + buttonText;
        };

        // Function to fetch FAQs and render them
        const fetchFaqs = async () => {
            try {
                const response = await fetch("./core/Faq.php");
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const data = await response.json();
                
                if (data.error) {
                    showNotification(data.error, "error"); 
                    return;
                }
                
                renderFaqsAndCategories(data);
                updateCommonAddButtonName(); // Initial name set

            } catch (error) {
                console.error("Error fetching FAQs:", error);
                showNotification("Failed to load data. Please try again.", "error");
            }
        };

        // --- RENDERING FUNCTION ---
        const renderFaqsAndCategories = (data) => {
            faqTableBody.innerHTML = "";
            categoryTableBody.innerHTML = "";

            const categorySelect = document.getElementById("faqCategorySelect");
            categorySelect.innerHTML = '<option value="" disabled selected>Select a category...</option>';

            let allFaqs = [];
            
            data.forEach(category => {
                categoryTableBody.innerHTML += `
                    <tr>
                        <td>${category.name}</td>
                        <td class="px-6 py-4 flex justify-center text-center">
                            <button type="button" class="font-medium text-blue-600 dark:text-blue-500 hover:underline me-3 edit-category-button bg-cstm-primary-20 drk-bg-cstm-primary-20 hover:bg-cstm-primary-30 text-cstm-primary drk-text-cstm-primary font-medium w-10 h-10 flex justify-center items-center rounded-full" data-category-id="${category.id}" data-category-name="${category.name}"><iconify-icon icon="tabler:edit" class="icon text-xl"></iconify-icon></button>
                            <button type="button" class="text-danger-600 dark:text-danger-600 bg-danger-100 hover:bg-danger-200 dark:bg-danger-600/20 font-medium w-10 h-10 flex justify-center items-center rounded-full delete-category-button" data-category-id="${category.id}"><iconify-icon icon="tabler:trash" class="icon text-lg"></iconify-icon></button>
                        </td>
                    </tr>
                `;
                
                const option = document.createElement("option");
                option.value = category.id;
                option.textContent = category.name;
                categorySelect.appendChild(option);
                
                category.faqs.forEach(faq => {
                    allFaqs.push({
                        ...faq,
                        category_name: category.name,
                        category_id: category.id
                    });
                });
            });

            allFaqs.forEach(faq => {
                const date = new Date(faq.created_at).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
                const questionData = encodeURIComponent(faq.question);
                const answerData = encodeURIComponent(faq.answer);
                
                faqTableBody.innerHTML += `
                    <tr class="bg-white border dark:bg-gray-800 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                        <td class="px-6 py-4 font-medium text-gray-900 dark:text-white">${faq.question}</td>
                        <td class="px-6 py-4">${faq.answer.substring(0, 50)}${faq.answer.length > 50 ? '...' : ''}</td>
                        <td class="px-6 py-4 text-sm">${faq.category_name}</td>
                        <td class="px-6 py-4 text-sm">${date}</td>
                        <td class="px-6 py-4 flex justify-end text-start">
                            <button type="button" class="font-medium text-blue-600 dark:text-blue-500 hover:underline me-3 edit-faq-button bg-cstm-primary-20 drk-bg-cstm-primary-20 hover:bg-cstm-primary-30 text-cstm-primary drk-text-cstm-primary font-medium w-10 h-10 flex justify-center items-center rounded-full" 
                                data-faq-id="${faq.id}" 
                                data-question="${questionData}" 
                                data-answer="${answerData}" 
                                data-category-id="${faq.category_id}"><iconify-icon icon="tabler:edit" class="icon text-xl"></iconify-icon></button>
                            <button type="button" class="text-danger-600 dark:text-danger-600 bg-danger-100 hover:bg-danger-200 dark:bg-danger-600/20 font-medium w-10 h-10 flex justify-center items-center rounded-full delete-faq-button" data-faq-id="${faq.id}"><iconify-icon icon="tabler:trash" class="icon text-lg"></iconify-icon></button>
                        </td>
                    </tr>
                `;
            });
            
            initFlowbite();
        };

        // --- EVENT DELEGATION ---
        const clickHandler = (e) => {
            const target = e.target.closest("button");
            if (!target) return;
            
            if (target.classList.contains("add-faq-button") || target.classList.contains("edit-faq-button")) {
                const isEdit = target.classList.contains("edit-faq-button");
                document.getElementById("editFaqId").value = "";
                document.getElementById("questionTitle").value = "";
                document.getElementById("answerDescription").value = "";
                document.getElementById("faqCategorySelect").value = isEdit ? target.getAttribute("data-category-id") : ""; 

                if (isEdit) {
                    document.getElementById("editFaqId").value = target.getAttribute("data-faq-id");
                    document.getElementById("questionTitle").value = decodeURIComponent(target.getAttribute("data-question"));
                    document.getElementById("answerDescription").value = decodeURIComponent(target.getAttribute("data-answer"));
                }
                addFaqModalInstance.show();
            }
            else if (target.classList.contains("add-category-button") || target.classList.contains("edit-category-button")) {
                const isEdit = target.classList.contains("edit-category-button");
                document.getElementById("editCategoryId").value = "";
                document.getElementById("categoryTitle").value = "";

                if (isEdit) {
                    document.getElementById("editCategoryId").value = target.getAttribute("data-category-id");
                    document.getElementById("categoryTitle").value = target.getAttribute("data-category-name");
                }
                addCategoryModalInstance.show();
            }
            else if (target.classList.contains("delete-faq-button")) {
                const faqId = target.getAttribute("data-faq-id");
                deleteTarget = { type: "faq", id: faqId };
                deleteMessage.textContent = "Are you sure you want to delete this FAQ?";
                deleteModalInstance.show();
            }
            else if (target.classList.contains("delete-category-button")) {
                const categoryId = target.getAttribute("data-category-id");
                deleteTarget = { type: "category", id: categoryId };
                deleteMessage.textContent = "Are you sure you want to delete this category and all its associated FAQs? This cannot be undone.";
                deleteModalInstance.show();
            }
            
            if (target.getAttribute("data-modal-hide") === "addFaqModal") {
                addFaqModalInstance.hide();
            } else if (target.getAttribute("data-modal-hide") === "addCategoryModal") {
                addCategoryModalInstance.hide();
            } else if (target.getAttribute("data-modal-hide") === "deleteConfirmModal") {
                deleteModalInstance.hide();
            }
        };

        // Handle confirm delete
        confirmDeleteButton.addEventListener("click", () => {
            if (!deleteTarget) return;
            let payload = (deleteTarget.type === "faq") ? { id: deleteTarget.id } : { category_id: deleteTarget.id };

            fetch("./core/Faq.php", {
                method: "DELETE",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, "success");
                    fetchFaqs(); 
                } else {
                    showNotification(data.message, "error");
                }
            })
            .catch(error => console.error("Error deleting:", error))
            .finally(() => {
                deleteTarget = null;
                deleteModalInstance.hide(); 
            });
        });

        // Common Add Button Click Handler
        commonAddButton.addEventListener("click", () => {
            const activeTabButton = document.querySelector('#faqTabs button[aria-selected="true"]');
            if (activeTabButton.id === "faq-tab") {
                document.getElementById("editFaqId").value = "";
                document.getElementById("questionTitle").value = "";
                document.getElementById("answerDescription").value = "";
                document.getElementById("faqCategorySelect").value = ""; 
                addFaqModalInstance.show();
            } else if (activeTabButton.id === "category-tab") {
                document.getElementById("editCategoryId").value = "";
                document.getElementById("categoryTitle").value = "";
                addCategoryModalInstance.show();
            }
        });

        // Tab listener
        faqTabsContainer.addEventListener('click', (e) => {
            const target = e.target.closest('button');
            if (target && (target.id === 'faq-tab' || target.id === 'category-tab')) {
                setTimeout(updateCommonAddButtonName, 50); 
            }
        });

        // Save FAQ 
        document.getElementById("saveFaqButton").addEventListener("click", function() {
            const question = document.getElementById("questionTitle").value;
            const answer = document.getElementById("answerDescription").value;
            const editFaqId = document.getElementById("editFaqId").value;
            const categoryId = document.getElementById("faqCategorySelect").value;

            if (question === "" || answer === "" || categoryId === "") {
                showNotification("Question, Answer, and Category cannot be empty.", "error");
                return;
            }

            const formData = { id: editFaqId || null, question: question, answer: answer, category_id: categoryId };
            const method = editFaqId ? "PUT" : "POST";

            fetch("./core/Faq.php", {
                method: method,
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, "success");
                    addFaqModalInstance.hide(); 
                    fetchFaqs();
                } else {
                    showNotification(data.message, "error");
                }
            })
            .catch(error => console.error("Error saving FAQ:", error));
        });

        // Save Category
        document.getElementById("saveCategoryButton").addEventListener("click", function() {
            const categoryTitle = document.getElementById("categoryTitle").value;
            const categoryId = document.getElementById("editCategoryId").value;

            if (categoryTitle === "") {
                showNotification("Category title cannot be empty.", "error");
                return;
            }

            const formData = { id: categoryId || null, name: categoryTitle };
            const method = categoryId ? "PUT" : "POST";

            fetch("./core/Faq.php", {
                method: method,
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, "success");
                    addCategoryModalInstance.hide(); 
                    fetchFaqs();
                } else {
                    showNotification(data.message, "error");
                }
            })
            .catch(error => console.error("Error saving category:", error));
        });

        document.addEventListener("click", clickHandler);
        fetchFaqs();
    });
</script>
SCRIPT;
?>

<?php include './partials/layouts/layoutTop.php' ?>

<div class="grid grid-cols-12 card border-none">
    <div class="col-span-12 p-6">

<div class="flex not_flex_mobile justify-between items-center mb-4">
    <ul class="ftab-style-gradient cstm-tab-style-gradient flex overflow-y-auto -mb-px text-sm font-medium text-center border-b border-gray-200 dark:border-gray-600" id="faqTabs" data-tabs-toggle="#faqTabContent" role="tablist">
        <li role="presentation" class="shrink-0">
            <button class="py-2.5 px-4 font-semibold text-base inline-flex items-center gap-3 text-neutral-600 rounded-t-lg" id="faq-tab" data-tabs-target="#faq-table-content" type="button" role="tab" aria-controls="faq-table-content" aria-selected="true">
                <iconify-icon icon="ph:question-light" class="icon text-lg me-1"></iconify-icon> FAQ Table
            </button>
        </li>
        <li role="presentation" class="shrink-0">
            <button class="py-2.5 px-4 font-semibold text-base inline-flex items-center gap-3 text-neutral-600 rounded-t-lg" id="category-tab" data-tabs-target="#category-table-content" type="button" role="tab" aria-controls="category-table-content" aria-selected="false">
                <iconify-icon icon="fluent:folder-24-regular" class="icon text-lg me-1"></iconify-icon> Category Management
            </button>
        </li>
    </ul>

    <button type="button" class="btn btn-cstm-primary my-4_mobile flex gap-2 justify-center items-center" id="commonAddButton">
        <iconify-icon icon="ic:baseline-plus" class="icon text-xl line-height-1"></iconify-icon>
        Add FAQ
    </button>
</div>

        <div id="faqTabContent">

            <div class="hidden" id="faq-table-content" role="tabpanel" aria-labelledby="faq-tab">
                <div class="text-end mb-4">

                </div>

                <div class="relative overflow-x-auto shadow-md sm:rounded-lg">
                    <table class="table bordered-table sm-table mb-0 table-auto text-sm">
                        <thead class="bg-gray-100 dark:bg-gray-700 dark:text-gray-400">
                            <tr>
                                <th>Question</th>
                                <th>Answer Snippet</th>
                                <th>Category</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="faqTableBody">
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="hidden" id="category-table-content" role="tabpanel" aria-labelledby="category-tab">
               
                <div class="relative overflow-x-auto shadow-md sm:rounded-lg">
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

<div id="addFaqModal" tabindex="-1" aria-hidden="true" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-[calc(100%-1rem)] max-h-full">
    <div class="relative p-4 w-full max-w-2xl max-h-full">
        <div class="relative bg-white rounded-lg shadow dark:bg-dark-2">
            <div class="flex items-center justify-between p-4 md:p-5 border-b rounded-t dark:border-gray-600">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Add/Edit FAQ</h3>
                <button type="button" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ms-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white" data-modal-hide="addFaqModal">
                    <svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6" />
                    </svg>
                    <span class="sr-only">Close modal</span>
                </button>
            </div>
            <div class="p-4 md:p-5 space-y-4">
                <form id="faqForm">
                    <input type="hidden" id="editFaqId" value="">

                    <div class="mb-3">
                        <label for="questionTitle" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Question</label>
                        <input type="text" class="form-control" placeholder="Enter Question" id="questionTitle" required>
                    </div>

                    <div class="mb-3">
                        <label for="faqCategorySelect" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Category</label>
                        <select id="faqCategorySelect" class="form-control" required>
                            <option value="" disabled selected>Select a category...</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="answerDescription" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Answer</label>
                        <textarea class="form-control" id="answerDescription" rows="3" placeholder="Write the answer" required></textarea>
                    </div>
                </form>
            </div>
            <div class="flex items-center justify-center gap-4 p-4 md:p-5 border-t border-gray-200 rounded-b dark:border-gray-600">
                <button type="button" data-modal-hide="addFaqModal" class="btn btn-cstm-primary light">Cancel</button>
                <button type="submit" class="btn btn-cstm-primary" id="saveFaqButton">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<div id="addCategoryModal" tabindex="-1" aria-hidden="true" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-[calc(100%-1rem)] max-h-full">
    <div class="relative p-4 w-full max-w-2xl max-h-full">
        <div class="relative bg-white rounded-lg shadow dark:bg-dark-2">
            <div class="flex items-center justify-between p-4 md:p-5 border-b rounded-t dark:border-gray-600">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Add/Edit Category</h3>
                <button type="button" class="text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ms-auto inline-flex justify-center items-center dark:hover:bg-gray-600 dark:hover:text-white" data-modal-hide="addCategoryModal">
                    <svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6" />
                    </svg>
                    <span class="sr-only">Close modal</span>
                </button>
            </div>
            <div class="p-4 md:p-5 space-y-4">
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

<div id="deleteConfirmModal" tabindex="-1" aria-hidden="true" class="hidden overflow-y-auto overflow-x-hidden fixed top-0 right-0 left-0 z-50 justify-center items-center w-full md:inset-0 h-[calc(100%-1rem)] max-h-full">
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

<?php include './partials/layouts/layoutBottom.php' ?>