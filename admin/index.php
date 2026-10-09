<?php
// /wix/searchconsole/admin/index.php

$title = 'Admin Dashboard';
$subTitle = 'System Overview';

ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);

// Core includes
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';
require_once __DIR__ . '/core/StoreManager.php';

// Start DB-backed session
SessionManager::startDatabaseSession();

// Require admin auth
if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

/**
 * This <script> block is printed in layoutBottom.php
 */
$script = <<<HTML
<script>
document.addEventListener("DOMContentLoaded", function() {

    // Pagination constants
    const ITEMS_PER_PAGE = 7; // You can adjust this number
    let currentUsersPage = 1;

    // References for Latest Users Table
    const usersTbody = document.getElementById("latest-users-tbody");
    const userPaginationContainer = document.getElementById("latest-users-pagination");
    
    // Hit your stats API
    fetch("/wordpress/googlesearchconsole/api/dbSchema/get-admindash-stats.php")
        .then(res => {
            if (!res.ok) {
                throw new Error("Network response was not ok");
            }
            return res.json();
        })
        .then(data => {
            if (!data.success) {
                console.error("API Error:", data.error || "Unknown error");
                return;
            }

            // ======================================================
            // STAT CARDS
            // ======================================================
            document.getElementById("total-users-stat").textContent   = data.total_users    ?? 0;
            document.getElementById("active-users-stat").textContent  = data.active_users   ?? 0;
            document.getElementById("inactive-users-stat").textContent = data.inactive_users ?? 0;

            // ======================================================
            // LATEST USERS WITH PAGINATION
            // ======================================================
            const allLatestUsers = data.latest_users || [];
            
            function renderUserPage(page) {
                currentUsersPage = page;
                usersTbody.innerHTML = ""; // Clear loading state or previous data

                if (allLatestUsers.length === 0) {
                    usersTbody.innerHTML = '<tr><td colspan="3" class="text-center py-4 text-secondary-light">No new users found.</td></tr>';
                    userPaginationContainer.style.display = 'none';
                    return;
                }

                const totalPages = Math.ceil(allLatestUsers.length / ITEMS_PER_PAGE);
                if (page < 1) page = 1;
                if (page > totalPages) page = totalPages;

                const start = (page - 1) * ITEMS_PER_PAGE;
                const paginatedItems = allLatestUsers.slice(start, start + ITEMS_PER_PAGE);

                paginatedItems.forEach(user => {
                    const registerDate = user.created_at
                        ? new Date(user.created_at).toLocaleDateString("en-US", {
                            year: 'numeric', month: 'short', day: 'numeric'
                          })
                        : "-";

                    const row = `
                        <tr>
                            <td>
                                <div class="flex items-center">
                                    <div class="grow">
                                        <h6 class="text-base mb-0 font-medium">\${user.site_display_name ?? 'N/A'}</h6>
                                        <span class="text-sm text-secondary-light font-medium">\${user.owner_email ?? ''}</span>
                                    </div>
                                </div>
                            </td>
                            <td>\${registerDate}</td>
                            <td>
                                <a href="store-view.php?instance_id=\${user.instance_id}" class="bg-cstm-primary-5 hover:bg-cstm-primary-10 drk-bg-cstm-primary-10 text-cstm-primary drk-text-cstm-primary font-medium w-10 h-10 flex justify-center items-center rounded-full justify-self-center">
                                    <iconify-icon icon="majesticons:eye-line" class="text-xl text-cstm-primary drk-text-cstm-primary"></iconify-icon>
                                </a>
                            </td>
                        </tr>`;
                    usersTbody.innerHTML += row;
                });

                setupUserPagination();
            }

            function setupUserPagination() {
                userPaginationContainer.innerHTML = '';
                const totalPages = Math.ceil(allLatestUsers.length / ITEMS_PER_PAGE);

                if (totalPages <= 1) {
                    userPaginationContainer.style.display = 'none';
                    return;
                }

                userPaginationContainer.style.display = 'flex';

                // Previous Button
                const isFirstPage = currentUsersPage === 1;
                userPaginationContainer.innerHTML += `<button data-page="\${currentUsersPage - 1}" class="px-3 py-1 rounded-md text-sm font-medium hover:bg-gray-200 dark:hover:bg-neutral-600 \${isFirstPage ? 'opacity-50 cursor-not-allowed' : ''}" \${isFirstPage ? 'disabled' : ''}>Prev</button>`;

                // Page Number Buttons
                for (let i = 1; i <= totalPages; i++) {
                    const isActive = i === currentUsersPage;
                    userPaginationContainer.innerHTML += `<button data-page="\${i}" class="px-3 py-1 rounded-md text-sm font-medium \${isActive ? 'bg-cstm-primary text-white' : 'bg-gray-100 dark:bg-neutral-700'}">\${i}</button>`;
                }

                // Next Button
                const isLastPage = currentUsersPage === totalPages;
                userPaginationContainer.innerHTML += `<button data-page="\${currentUsersPage + 1}" class="px-3 py-1 rounded-md text-sm font-medium hover:bg-gray-200 dark:hover:bg-neutral-600 \${isLastPage ? 'opacity-50 cursor-not-allowed' : ''}" \${isLastPage ? 'disabled' : ''}>Next</button>`;

                userPaginationContainer.querySelectorAll('button').forEach(button => {
                    const page = parseInt(button.dataset.page, 10);
                    if (!isNaN(page)) {
                        button.addEventListener('click', () => renderUserPage(page));
                    }
                });
            }

            // Initial render for latest users
            renderUserPage(1);

            // ======================================================
            // LATEST TEMPLATES LIST (optional, you commented HTML)
            // ======================================================
            const templatesList = document.getElementById("latest-templates-list");
            if (templatesList) {
                templatesList.innerHTML = ""; // Clear loading state

                if (data.latest_templates && data.latest_templates.length > 0) {
                    data.latest_templates.forEach(template => {
                        const item = `
                            <div class="flex items-center gap-3 p-3 border border-neutral-200 dark:border-neutral-700 rounded-lg bg-neutral-50 dark:bg-neutral-800 border dark:border-neutral-600">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-full bg-cstm-primary-5 hover:bg-cstm-primary-10 drk-bg-cstm-primary-10 flex items-center justify-center shrink-0 shadow-inner">
                                        <iconify-icon icon="mdi:file-document-outline" class="text-xl text-cstm-primary drk-text-cstm-primary"></iconify-icon>
                                    </div>
                                    <div class="grow">
                                        <h6 class="text-base mb-0 font-medium">\${template.name}</h6>
                                        <span class="text-sm font-medium text-cstm-primary dark:text-neutral-50">\${template.slug}</span>
                                    </div>
                                </div>
                            </div>`;
                        templatesList.innerHTML += item;
                    });
                } else {
                    templatesList.innerHTML = '<p class="text-secondary-light">No new templates found.</p>';
                }
            }
        })
        .catch(err => {
            console.error("Fetch Error:", err);
        });
});
</script>
HTML;

include './partials/layouts/layoutTop.php';
?>

<!-- STAT CARDS -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">

    <!-- Total Users -->
    <a href="stores.php" target="_blank">
        <div class="card shadow-none border border-gray-200 dark:border-neutral-600 rounded-lg">
            <div class="card-body p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="font-medium text-neutral-900 dark:text-white mb-1">Total Users</p>
                        <h6 id="total-users-stat" class="mb-0 dark:text-white text-2xl">...</h6>
                    </div>
                    <div class="w-12 h-12 bg-cyan-600 rounded-full flex justify-center items-center">
                        <iconify-icon icon="gridicons:multiple-users" class="text-white text-2xl"></iconify-icon>
                    </div>
                </div>
            </div>
        </div>
    </a>

    <!-- Active Users -->
    <div class="card shadow-none border border-gray-200 dark:border-neutral-600 rounded-lg">
        <div class="card-body p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-medium text-neutral-900 dark:text-white mb-1">Active Users</p>
                    <h6 id="active-users-stat" class="mb-0 dark:text-white text-2xl">...</h6>
                    <!-- Optional small text like in screenshot -->
                    <!-- <p class="text-xs text-success-600 mt-1"><span id="active-users-last30">0</span> Last 30 days</p> -->
                </div>
                <div class="w-12 h-12 bg-success-600 rounded-full flex justify-center items-center">
                    <iconify-icon icon="mdi:account-check-outline" class="text-white text-2xl"></iconify-icon>
                </div>
            </div>
        </div>
    </div>

    <!-- Inactive Users -->
    <div class="card shadow-none border border-gray-200 dark:border-neutral-600 rounded-lg">
        <div class="card-body p-5">
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-medium text-neutral-900 dark:text-white mb-1">Inactive Users</p>
                    <h6 id="inactive-users-stat" class="mb-0 dark:text-white text-2xl">...</h6>
                    <!-- <p class="text-xs text-success-600 mt-1"><span id="inactive-users-last30">0</span> Last 30 days</p> -->
                </div>
                <div class="w-12 h-12 bg-danger-600 rounded-full flex justify-center items-center">
                    <iconify-icon icon="mdi:account-alert-outline" class="text-white text-2xl"></iconify-icon>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- LATEST USERS + (optional) TEMPLATES -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mt-6">
    <div class="lg:col-span-12">
        <div class="card h-full border-0">
            <div class="card-body p-6">
                <h6 class="text-lg font-semibold text-neutral-900 dark:text-white mb-4">Latest Registered Users</h6>
                <div class="cstm-scrollbar overflow-x-auto">
                    <table class="table min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr>
                                <th>User Details</th>
                                <th>Date Registered</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="latest-users-tbody">
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors dark:border-b dark:border-neutral-600">
                                <td colspan="3" class="py-4 text-center text-secondary-light">
                                    Loading...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div id="latest-users-pagination" class="mt-4 flex flex-wrap justify-end items-center gap-2" style="display: none;"></div>
            </div>
        </div>
    </div>

    <!-- Keep commented if you don't want templates box -->
    <!--
    <div class="lg:col-span-4">
        <div class="card h-full border-0">
            <div class="card-body p-6">
                <h6 class="font-semibold text-lg mb-4">Latest Schema Templates</h6>
                <div id="latest-templates-list" class="space-y-4">
                    <p class="text-secondary-light">Loading...</p>
                </div>
            </div>
        </div>
    </div>
    -->
</div>

<?php include './partials/layouts/layoutBottom.php'; ?>
