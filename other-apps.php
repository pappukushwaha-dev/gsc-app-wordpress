<?php

declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


$title = 'Our Other Apps';
$subTitle = 'Other Apps';

// --- Get Instance ID (if needed) ---
$instanceId = $_SESSION['instance_id'] ?? $_SESSION['instanceid'] ?? ($_GET['instance_id'] ?? null);
?>

<?php include './partials/layouts/layoutTop.php' ?>

<div class="">
    <div id="appsGrid" class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Apps will be injected here -->
    </div>

    <div class="flex items-center justify-between flex-wrap gap-2 mt-6">
        <span id="appsPaginationInfo" class="text-sm"></span>
        <ul class="pagination flex flex-wrap items-center gap-2 justify-center" id="appsPagination"></ul>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        const appsGrid = document.getElementById("appsGrid");
        const paginationInfo = document.getElementById("appsPaginationInfo");
        const paginationList = document.getElementById("appsPagination");

        let allApps = [];
        let currentPage = 1;
        let itemsPerPage = 6; // show 6 apps per page

        const renderApps = (apps, startIndex) => {
            appsGrid.innerHTML = "";
            if (apps.length === 0) {
                appsGrid.innerHTML = `
              <div class="col-span-full text-center py-12 text-neutral-500 dark:text-neutral-400">
                <p class="text-lg">No apps found.</p>
              </div>`;
                return;
            }

            apps.forEach(app => {
                const card = document.createElement("div");
                card.className = "bg-white dark:bg-gray-800 rounded-lg p-6 shadow-sm hover:shadow-md transition border dark:border-neutral-600 dark:bg-neutral-900";
                card.innerHTML = `
                <div class="flex items-start justify-between mb-3 gap-4">
                    <img src="${app.image_url}" alt="${app.title}" class="h-12 w-auto">
                </div>
                <h3 class="text-lg font-semibold text-red-600 dark:text-red-400 mb-2">${app.title}</h3>
                <p class="text-base text-gray-700 dark:text-gray-300 mb-4">${app.description}</p>
                 ${app.subtitle ? ` <div class="flex items-center text-xs text-gray-700 dark:text-gray-200 mb-4">
                                <i class="fa-solid fa-star text-yellow-400 mr-1"></i>
                                ${app.subtitle}
                            </div>` : ""}
                <a href="${app.button_link}" target="_blank"
                   class="inline-flex items-center text-cstm-primary drk-text-cstm-primary hover:underline font-medium text-sm">
                   ${app.button_text || "Learn More"} <span class="ml-1 drk-text-cstm-primary">→</span>
                </a>
            `;
                appsGrid.appendChild(card);
            });
        };

        const updatePagination = () => {
            const totalItems = allApps.length;
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            const start = (currentPage - 1) * itemsPerPage;
            const end = Math.min(start + itemsPerPage, totalItems);
            const pageApps = allApps.slice(start, end);

            renderApps(pageApps, start);
            paginationInfo.textContent = totalItems > 0 ?
                `Showing ${start + 1} to ${end} of ${totalItems} apps` :
                "No entries";

            paginationList.innerHTML = "";
            if (totalPages > 1) {
                const createPageItem = (page, text, isActive = false, isDisabled = false) => {
                    const li = document.createElement("li");
                    const a = document.createElement("a");
                    a.className = `page-link h-8 w-8 flex items-center justify-center rounded-lg text-sm ${
                    isActive ? "bg-cstm-primary text-white" : "bg-neutral-300 dark:bg-neutral-600"
                }`;
                    if (isDisabled) {
                        a.classList.add("pointer-events-none", "opacity-50");
                    }
                    a.innerHTML = text;
                    a.onclick = () => {
                        if (!isDisabled) {
                            currentPage = page;
                            updatePagination();
                        }
                    };
                    li.appendChild(a);
                    paginationList.appendChild(li);
                };

                createPageItem(currentPage - 1, '<iconify-icon icon="ep:d-arrow-left"></iconify-icon>', false, currentPage === 1);
                for (let i = 1; i <= totalPages; i++) {
                    createPageItem(i, i, i === currentPage);
                }
                createPageItem(currentPage + 1, '<iconify-icon icon="ep:d-arrow-right"></iconify-icon>', false, currentPage === totalPages);
            }
        };

        // Fetch apps from API
        fetch("./api/dbSchema/other_apps.php")
            .then(res => res.json())
            .then(data => {
                if (data.success && Array.isArray(data.data)) {
                    allApps = data.data;
                    updatePagination();
                } else {
                    appsGrid.innerHTML = `<div class="col-span-full text-center py-12 text-red-500">Failed to load apps.</div>`;
                }
            })
            .catch(err => {
                console.error("Apps fetch failed:", err);
                appsGrid.innerHTML = `<div class="col-span-full text-center py-12 text-red-500">Error loading apps.</div>`;
            });
    });
</script>

<?php include './partials/layouts/layoutBottom.php' ?>