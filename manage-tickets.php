<?php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

$instanceId =
    $_SESSION['instanceid']
    ?? $_SESSION['instance_id']
    ?? $_GET['instance_id']
    ?? null;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

$instanceId =
    $_SESSION['instance_id']
    ?? $_GET['instance_id']
    ?? null;

if ($instanceId) {
    $_SESSION['instance_id'] = $instanceId;
}

if (!$instanceId) {
    die('Missing instance id');
}


require_once __DIR__ . '/includes/google/get_bigcommerce_site.php';

$site = getWpSiteByInstance($instanceId);

if (!$site || empty($site['site_url'])) {
    die('BigCommerce store not connected.');
}

$title = 'Manage Tickets';
$subTitle = 'Tickets';

// IMPORTANT: Replace with your actual includes
include './partials/layouts/layoutTop.php';
?>

<div id="notification-bar" class="fixed top-4 right-4 z-[60] w-80 transition-opacity duration-300 opacity-0 pointer-events-none">
</div>

<div class="card h-full p-0 rounded-xl border-0 overflow-hidden">
    <div class="card-header border-b border-neutral-200 dark:border-neutral-600 bg-white dark:bg-neutral-700 py-4 px-6 flex items-center flex-wrap gap-3 justify-between">
        <div class="flex items-center gap-4">
            <div id="header-entries-info" class="text-sm text-neutral-600 dark:text-neutral-300">
                Showing — entries
            </div>


        </div>

        <div class="flex items-center flex-wrap gap-3">
            <form class="navbar-search" onsubmit="return false;">
                <input id="search-input" type="text" class="form-input h-10 rounded-lg px-3 text-sm dark:bg-neutral-700" placeholder="Search ticket # or subject" />
                <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
            </form>

            <label class="text-sm font-medium text-secondary-light dark:text-neutral-200">Show</label>
            <select id="per-page-select" class="form-select form-select-sm w-auto dark:bg-neutral-600 dark:text-white border-neutral-200 dark:border-neutral-500 rounded-lg text-sm">
                <option value="10">10</option>
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>

            <button id="refresh-btn" class="btn btn-cstm-primary">Refresh</button>
            <button id="btn-new-ticket" type="button" class="btn btn-cstm-primary" onclick="console.log('Inline onclick fired'); return false;">+ New Ticket</button>
        </div>
    </div>

    <div class="card-body p-6">
        <div class="border border-neutral-200 dark:border-neutral-700 rounded-xl overflow-hidden">
            <!-- <div class="p-3 flex items-center justify-between border-b border-neutral-100 dark:border-neutral-800">
                <div class="text-xs text-neutral-500 dark:text-neutral-400">Tickets (paginated)</div>
            </div> -->
            <div id="loading-indicator" class="text-xs text-neutral-500 dark:text-neutral-400 hidden">Loading…</div>
            <div class="overflow-x-auto cstm-scrollbar tickets-container">
                <table class="table bordered-table sm-table table-auto text-sm min-w-full">
                    <thead class="bg-gray-50 dark:bg-neutral-900">
                        <tr class="text-left text-sm font-semibold text-neutral-500 dark:text-neutral-400">
                            <th class="px-4 py-3">#</th>
                            <th class="px-4 py-3 w-96">Ticket</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Priority</th>
                            <th class="px-4 py-3">Last Message</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tickets-table-body" class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        <tr>
                            <td colspan="7" class="px-4 py-5 text-center text-neutral-500 dark:text-neutral-400">Loading tickets...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex items-center justify-between flex-wrap gap-2 mt-6">
            <div id="entries-info" class="text-sm text-neutral-600 dark:text-neutral-300"></div>

            <ul id="pagination" class="pagination flex flex-wrap items-center gap-2 justify-center">
            </ul>
        </div>
    </div>
</div>

<div id="modal-add" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50 bg-cstm-black-40 backdrop-blur-sm shadow-lg">
    <div class="bg-white dark:bg-neutral-900 rounded-2xl shadow-xl max-w-2xl w-full p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h5 class="text-lg font-semibold">Create New Ticket</h5>
            <button type="button" id="add-close" class="btn-close">✕</button>
        </div>
        <div class="space-y-3">
            <div class="space-y-2">
                <label class="text-base dark:text-white">Subject</label>
                <input id="add-subject" class="form-input w-full form-control border dark:text-white" placeholder="Subject">
            </div>
            <div class="space-y-2">
                <label class="text-base dark:text-white">Priority</label>
                <select id="add-priority" class="form-select w-full">
                    <option value="low">Low</option>
                    <option value="normal">Normal</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>
            <div class="space-y-2">
                <p class="text-sm"><strong>Owner Email:</strong> <span id="add-owner-email" class="font-normal text-neutral-500 dark:text-neutral-400">Loading...</span></p>
                <div class="space-y-2">

                    <label class="text-base !mt-6 dark:text-white">Initial Message</label>
                    <textarea id="add-message" class="text-base form-input w-full form-control" rows="3" placeholder="Type initial message"></textarea>
                </div>
                <div class="space-y-2">
                    <label class="text-sm">Attachment (Optional)</label>
                    <input type="file" id="add-file" class="block w-full text-sm text-neutral-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-cstm-primary/10 file:text-cstm-primary hover:file:bg-cstm-primary/20 border border-gray-300 rounded-md cursor-pointer">
                    <div class="flex justify-end gap-2 pt-2">
                        <button id="add-cancel" class="btn btn-cstm-primary light">Cancel</button>
                        <button id="add-submit" class="btn btn-cstm-primary">Create</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

    <div id="modal-view" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50 bg-cstm-black-40 backdrop-blur-sm shadow-lg">
        <!-- Increased max-width and use full-height column layout so inner area can scroll -->
        <div class="bg-white dark:bg-neutral-900 rounded-2xl shadow-xl max-w-2xl w-full max-h-[90vh] overflow-hidden flex">
            <!-- Column container that fills available modal height -->
            <div class="w-full flex flex-col">

                <!-- HEADER (sticky) -->
                <div class="p-4 border-b border-neutral-200 dark:border-neutral-700 flex items-center justify-between">
                    <div>
                        <h5 id="view-ticket-number" class="text-base font-semibold">T-...</h5>
                        <div id="view-subject" class="text-xs text-neutral-500">subject</div>
                    </div>

                    <div class="flex items-center gap-2">
                        <select id="view-status" class="form-select text-sm">
                            <option value="open">open</option>
                            <!-- <option value="pending">pending</option> -->
                            <option value="closed">closed</option>
                        </select>
                        <button id="view-close-btn" class="btn btn-cstm-primary">Close</button>
                    </div>
                </div>
                

                <div class="overflow-y-auto">
                <!-- META (sticky under header) -->
                <div class="text-xs p-4 border-b border-neutral-200 dark:border-neutral-700 text-neutral-600 dark:text-neutral-300 grid grid-cols-2 md:grid-cols-3 gap-3">

                    <!-- Owner -->
                    <div class="card p-2 rounded-md bg-primary-50 dark:bg-primary-950/30 border-0 flex gap-2 items-center">
                        <span class="rounded-md p-2 bg-white dark:bg-neutral-800 flex justify-center items-center shrink-0">
                            <iconify-icon icon="tabler:user-circle" class="text-base text-primary-600"></iconify-icon>
                        </span>
                        <p class="flex flex-col gap-1 min-w-0 grid">
                            <strong>Owner:</strong>
                            <span id="view-owner-email" class="truncate min-w-0">Loading...</span>
                        </p>
                    </div>

                    <!-- Priority -->
                    <div class="card p-2 rounded-md bg-primary-50 dark:bg-primary-950/30 border-0 flex gap-2 items-center">
                        <span class="rounded-md p-2 bg-white dark:bg-neutral-800 flex justify-center items-center shrink-0">
                            <iconify-icon icon="tabler:alert-circle" class="text-base text-primary-600"></iconify-icon>
                        </span>
                        <p class="flex flex-col gap-1">
                            <strong>Priority:</strong>
                            <span id="meta-priority">normal</span>
                        </p>
                    </div>

                    <!-- Created -->
                    <div class="card p-2 rounded-md bg-primary-50 dark:bg-primary-950/30 border-0 flex gap-2 items-center">
                        <span class="rounded-md p-2 bg-white dark:bg-neutral-800 flex justify-center items-center shrink-0">
                            <iconify-icon icon="tabler:calendar-plus" class="text-base text-primary-600"></iconify-icon>
                        </span>
                        <p class="flex flex-col gap-1">
                            <strong>Created:</strong>
                            <span id="meta-created">-</span>
                        </p>
                    </div>

                    <!-- Last -->
                    <div class="card p-2 rounded-md bg-primary-50 dark:bg-neutral-800/30 border-0 flex gap-2 items-center">
                        <span class="rounded-md p-2 bg-white dark:bg-neutral-800 flex justify-center items-center shrink-0">
                            <iconify-icon icon="tabler:history" class="text-base text-primary-600"></iconify-icon>
                        </span>
                        <p class="flex flex-col gap-1">
                            <strong>Last:</strong>
                            <span id="meta-last">-</span>
                        </p>
                    </div>

                    <!-- Messages -->
                    <div class="card p-2 rounded-md bg-primary-50 dark:bg-primary-950/30 border-0 flex gap-2 items-center">
                        <span class="rounded-md p-2 bg-white dark:bg-neutral-800 flex justify-center items-center shrink-0">
                            <iconify-icon icon="tabler:messages" class="text-base text-primary-600"></iconify-icon>
                        </span>
                        <p class="flex flex-col gap-1">
                            <strong>Messages:</strong>
                            <span id="meta-count">0</span>
                        </p>
                    </div>

                </div>

                <!-- SCROLLABLE THREAD AREA (flex-1 makes this fill the middle, overflow-auto provides scrolling) -->
                <div id="view-thread" class="p-4" style="min-height:0;">
                    <div id="messages-list" class="space-y-4">
                        <!-- messages appended here -->
                    </div>

                    <div class="mt-4 text-xs text-neutral-500 border-t pt-4 hidden">
                        <p>Attachments:</p>
                        <div id="attachments-list" class="space-y-2 mt-2"></div>
                    </div>
                </div>
            </div>

                <!-- FOOTER / REPLY (sticky) -->
                <div class="mt-0 border-t pt-4 p-4 bg-white dark:bg-neutral-900">
                    <label class="text-xs">Reply</label>
                    <textarea id="reply-text" class="form-control form-input w-full !mb-6 !mt-2" rows="3" placeholder="Write a reply..."></textarea>

                    <div class="flex items-center gap-3 flex-col md:flex-row justify-center md:justify-between mt-2">
                        <div class="w-2/3">
                            <input type="file" id="reply-file" class="block w-full text-sm text-neutral-500 file:mr-4 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-cstm-primary/10 file:text-cstm-primary hover:file:bg-cstm-primary/20 border border-gray-300 rounded-md cursor-pointer">
                        </div>

                        <div class="flex gap-2">
                            <button id="reply-cancel" class="btn btn-cstm-primary light flex justify-center items-center gap-2"><iconify-icon icon="mdi:close-circle-outline" class="text-lg"></iconify-icon> Clear</button>
                            <button id="reply-send" class="btn btn-cstm-primary flex justify-center items-center gap-2"><iconify-icon icon="mdi:reply-outline" class="text-lg"></iconify-icon> Reply</button>
                            <button id="btn-delete-ticket" class="btn bg-danger-500 text-white flex justify-center items-center gap-2"> <iconify-icon icon="mdi:delete-outline" class="text-lg"></iconify-icon> Delete</button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>






    <div id="modal-status" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50 bg-cstm-black-40 backdrop-blur-sm shadow-lg">
        <div class="bg-white dark:bg-neutral-900 rounded-2xl shadow-xl max-w-2xl w-full p-6 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h5 class="text-base font-semibold">Change Ticket Status</h5>
                <button type="button" id="status-close" class="btn-close">✕</button>
            </div>
            <div class="space-y-3">
                <p class="text-sm text-neutral-700 dark:text-neutral-200">Set the new status for ticket <strong id="status-ticket-id">#...</strong></p>
                <select id="status-select" class="form-select w-full">
                    <option value="open">Open</option>
                    <!-- <option value="pending">Pending</option> -->
                    <option value="closed">Closed</option>
                </select>
                <div class="flex justify-end gap-2 pt-2">
                    <button id="status-cancel" class="btn btn-cstm-primary light">Cancel</button>
                    <button id="status-submit" class="btn btn-cstm-primary">Apply Status</button>
                </div>
            </div>
        </div>
    </div>

    <div id="modal-confirm-close" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50 bg-cstm-black-40 backdrop-blur-sm shadow-lg">
        <div class="bg-white dark:bg-neutral-900 rounded-2xl shadow-2xl max-w-2xl w-full p-6 max-h-[90vh] overflow-y-auto border border-neutral-200 dark:border-neutral-800">

            <!-- Header -->
            <div class="flex justify-between items-center pb-4 mb-5 border-b border-neutral-200 dark:border-neutral-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-danger-50 dark:bg-danger-950/30 flex items-center justify-center shrink-0">
                        <iconify-icon icon="tabler:circle-check" class="text-xl text-danger-600"></iconify-icon>
                    </div>

                    <h5 class="text-base font-semibold text-danger-600">
                        Close Ticket
                    </h5>
                </div>

                <button
                    type="button"
                    id="close-confirm-close"
                    class="btn-close w-8 h-8 rounded-lg flex items-center justify-center text-neutral-500 hover:text-neutral-700 hover:bg-neutral-100 dark:hover:text-neutral-200 dark:hover:bg-neutral-800 transition-colors"
                >✕</button>
            </div>

            <!-- Confirmation Message -->
            <div class="rounded-xl border border-danger-200 dark:border-danger-900/60 bg-danger-50 dark:bg-danger-950/20 p-4">
                <div class="flex gap-3">
                    <div class="shrink-0 mt-1">
                        <iconify-icon icon="tabler:alert-circle" class="text-xl text-danger-600"></iconify-icon>
                    </div>

                    <p class="text-sm leading-6 text-danger-600 dark:text-danger-200">
                        Are you sure you want to <span class="font-semibold">close</span> ticket <strong id="close-ticket-id">#...</strong>? This will set its status to <span class="font-semibold">closed<span class="font-semibold">.
                    </p>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex justify-end gap-2 pt-6">
                <button id="close-cancel" class="btn btn-cstm-primary light">
                    Cancel
                </button>

                <button id="close-submit" class="btn btn-cstm-primary">
                    Confirm Close
                </button>
            </div>

        </div>
    </div>

    <div id="modal-confirm-delete" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50 bg-cstm-black-40 backdrop-blur-sm shadow-lg">
        <div class="bg-white dark:bg-neutral-900 rounded-2xl shadow-2xl max-w-2xl w-full p-6 max-h-[90vh] overflow-y-auto border border-neutral-200 dark:border-neutral-800">

            <!-- Header -->
            <div class="flex justify-between items-center pb-4 mb-5 border-b border-neutral-200 dark:border-neutral-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-danger-50 dark:bg-danger-950/30 flex items-center justify-center shrink-0">
                        <iconify-icon icon="tabler:trash-x" class="text-xl text-danger-600"></iconify-icon>
                    </div>

                    <h5 class="text-base font-semibold text-danger-600">
                        Delete Ticket Permanently
                    </h5>
                </div>

                <button
                    type="button"
                    id="delete-close"
                    class="btn-close w-8 h-8 rounded-lg flex items-center justify-center text-neutral-500 hover:text-neutral-700 hover:bg-neutral-100 dark:hover:text-neutral-200 dark:hover:bg-neutral-800 transition-colors"
                >✕</button>
            </div>

            <!-- Warning -->
            <div class="rounded-xl border border-danger-200 dark:border-danger-900/60 bg-danger-50 dark:bg-danger-950/20 p-4">
                <div class="flex gap-3">
                    <div class="shrink-0 pt-0.5">
                        <iconify-icon icon="tabler:alert-triangle" class="text-xl text-danger-600"></iconify-icon>
                    </div>

                    <p class="text-sm leading-6 text-danger-800 dark:text-danger-200">
                        <span class="font-semibold">WARNING:</span> Are you sure you want to <span class="font-semibold">permanently delete</span> ticket <strong id="delete-ticket-id">#...</strong> and all its messages? This action cannot be undone.
                    </p>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex justify-end gap-2 pt-6">
                <button id="delete-cancel" class="btn btn-cstm-primary">
                    Cancel
                </button>

                <button id="delete-submit" class="btn bg-danger-500 text-white flex justify-center items-center gap-2">
                    Delete Permanently
                </button>
            </div>

        </div>
    </div>

    <?php
    // IMPORTANT: Replace with your actual includes
    include './partials/layouts/layoutBottom.php';
    ?>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const instanceId = '<?= htmlspecialchars((string)$instanceId, ENT_QUOTES) ?>';

            // Global state for instance details (owner email)
            let instanceDetails = {
                owner_email: 'N/A'
            };
            let notificationTimeout;

            // elements
            const ui = {
                loading: document.getElementById('loading-indicator'),
                ticketsBody: document.getElementById('tickets-table-body'),
                perPage: document.getElementById('per-page-select'),
                search: document.getElementById('search-input'),
                refresh: document.getElementById('refresh-btn'),
                entriesInfo: document.getElementById('entries-info'),
                headerEntriesInfo: document.getElementById('header-entries-info'),
                pagination: document.getElementById('pagination'),
                btnNew: document.getElementById('btn-new-ticket'),
                notificationBar: document.getElementById('notification-bar'),

                // Add Modal
                modalAdd: document.getElementById('modal-add'),
                addClose: document.getElementById('add-close'),
                addCancel: document.getElementById('add-cancel'),
                addSubmit: document.getElementById('add-submit'),
                addSubject: document.getElementById('add-subject'),
                addMessage: document.getElementById('add-message'),
                addPriority: document.getElementById('add-priority'),
                addFile: document.getElementById('add-file'),
                addOwnerEmail: document.getElementById('add-owner-email'),

                // View Modal (Relocated elements used)
                modalView: document.getElementById('modal-view'),
                viewTicketNumber: document.getElementById('view-ticket-number'),
                viewSubject: document.getElementById('view-subject'),
                viewStatus: document.getElementById('view-status'),
                viewCloseBtn: document.getElementById('view-close-btn'), // THIS IS THE FIXED CLOSE BUTTON
                messagesList: document.getElementById('messages-list'),
                replyText: document.getElementById('reply-text'),
                replyFile: document.getElementById('reply-file'),
                replySend: document.getElementById('reply-send'),
                replyCancel: document.getElementById('reply-cancel'),
                metaPriority: document.getElementById('meta-priority'),
                metaCreated: document.getElementById('meta-created'),
                metaLast: document.getElementById('meta-last'),
                metaCount: document.getElementById('meta-count'),
                attachmentsList: document.getElementById('attachments-list'),
                btnDeleteTicket: document.getElementById('btn-delete-ticket'),
                // btnChangeStatus: document.getElementById('btn-change-status'), // Removed from UI, but keeping in JS if needed
                viewOwnerEmail: document.getElementById('view-owner-email'),

                // Other Modals
                modalStatus: document.getElementById('modal-status'),
                statusClose: document.getElementById('status-close'),
                statusCancel: document.getElementById('status-cancel'),
                statusSubmit: document.getElementById('status-submit'),
                statusSelect: document.getElementById('status-select'),
                statusTicketIdDisplay: document.getElementById('status-ticket-id'),
                modalConfirmClose: document.getElementById('modal-confirm-close'),
                closeConfirmClose: document.getElementById('close-confirm-close'),
                closeCancel: document.getElementById('close-cancel'),
                closeSubmit: document.getElementById('close-submit'),
                closeTicketIdDisplay: document.getElementById('close-ticket-id'),
                modalConfirmDelete: document.getElementById('modal-confirm-delete'),
                deleteClose: document.getElementById('delete-close'),
                deleteCancel: document.getElementById('delete-cancel'),
                deleteSubmit: document.getElementById('delete-submit'),
                deleteTicketIdDisplay: document.getElementById('delete-ticket-id'),
            };



            // --- defensive listener helper (place after `const ui = { ... }`) ---
            function safeOn(el, evt, handler) {
                if (!el) {
                    console.warn(`safeOn: element for event '${evt}' is missing; listener skipped`);
                    return;
                }
                try {
                    el.addEventListener(evt, handler);
                } catch (e) {
                    console.error('safeOn: failed to attach listener', evt, e);
                }
            }

            // Replace direct .addEventListener calls below with safeOn(...) for items
            safeOn(ui.replyCancel, 'click', () => {
                // Clear reply fields (defensive copies)
                try {
                    if (ui.replyText) ui.replyText.value = '';
                    if (ui.replyFile) ui.replyFile.value = '';
                } catch (e) {
                    console.error('Error clearing reply inputs', e);
                }
            });

            // Example: ensure reply-send, add-submit and view-close are attached safely too
            safeOn(ui.replySend, 'click', async (e) => {
                // the original reply-send handler body goes here OR call existing function
                // If you already have a handler defined inline elsewhere, keep that but register using safeOn
                // For now assume you already have the handler defined later; this prevents double attaching.
            });

            safeOn(ui.addSubmit, 'click', async () => {
                /* existing create ticket logic remains — replace original direct .addEventListener call
                   with the safeOn pattern. If you prefer to keep the original code, just attach using safeOn. */
            });

            safeOn(ui.viewCloseBtn, 'click', () => {
                hideModal(ui.modalView);
                currentTicketId = null;
            });


            if (!instanceId) {
                ui.ticketsBody.innerHTML = `<tr><td colspan="7" class="px-4 py-5 text-center text-danger-600 dark:text-danger-400">Missing instance id</td></tr>`;
                return;
            }

            // state
            let currentPage = 1;
            let perPage = parseInt(ui.perPage.value, 10) || 25;
            let totalPages = 1;
            let totalCount = 0;
            let ticketsCache = [];
            let currentTicketId = null;
            let pendingActionTicketId = null;

            const setLoading = (val) => {
                ui.loading.classList.toggle('hidden', !val);
                ui.refresh.disabled = val;
                // Get fresh button reference since we clone it
                const btnNew = document.getElementById('btn-new-ticket');
                if (btnNew) btnNew.disabled = val;
            };

            function escapeHtml(s) {
                if (s === null || s === undefined) return '';
                return String(s).replace(/[&<>"']/g, (m) => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [m]));
            }

            function shortenFileName(name, maxLength = 26) {
                if (!name || name.length <= maxLength) return name;

                const extIndex = name.lastIndexOf('.');
                const ext = extIndex !== -1 ? name.slice(extIndex) : '';
                const base = extIndex !== -1 ? name.slice(0, extIndex) : name;

                const keep = Math.max(10, maxLength - ext.length - 3);
                return base.slice(0, keep) + '...' + ext;
            }


            function statusClasses(s) {
                if (s === 'open') return 'bg-success-100 text-success-700 dark:bg-success-900/40 dark:text-success-300';
                if (s === 'pending') return 'bg-warning-100 text-warning-700 dark:bg-warning-900/40 dark:text-warning-300';
                return 'bg-danger-100 text-danger-700 dark:bg-danger-900/40 dark:text-danger-300';
            }

            // Modal Helpers
            function showModal(el) {
                el.classList.remove('hidden');
                el.classList.add('flex');
            }

            function hideModal(el) {
                el.classList.add('hidden');
                el.classList.remove('flex');
            }

            // Utility function to get ticket number from ID (if cached)
            function getTicketNumber(id) {
                const ticket = ticketsCache.find(t => t.id === id);
                return ticket ? (ticket.ticket_number || ('#' + id)) : `#${id}`;
            }

            // NEW: Notification Function (Replaces all browser alerts)
            function showNotification(message, type = 'success') {
                clearTimeout(notificationTimeout);
                const icon = type === 'success' ? 'ion:checkmark-circle-outline' : 'ion:alert-circle-outline';
                const bgColor = type === 'success' ? 'bg-success-500' : 'bg-danger-500';

                ui.notificationBar.innerHTML = `
            <div class="flex items-center p-4 rounded-lg text-white shadow-xl ${bgColor}">
                <iconify-icon icon="${icon}" class="w-5 h-5 mr-3"></iconify-icon>
                <div class="text-sm font-medium">${message}</div>
            </div>
        `;
                ui.notificationBar.classList.remove('opacity-0', 'pointer-events-none');
                ui.notificationBar.classList.add('opacity-100');

                notificationTimeout = setTimeout(() => {
                    ui.notificationBar.classList.remove('opacity-100');
                    ui.notificationBar.classList.add('opacity-0', 'pointer-events-none');
                }, 5000);
            }

            // Function to handle the actual status change API call
            async function performStatusChange(ticketId, newStatus, note) {
                try {
                    const res = await fetch('./api/change_ticket_status.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            instance_id: instanceId,
                            ticket_id: ticketId,
                            new_status: newStatus,
                            changed_by: 'staff',
                            note: note
                        })
                    });
                    const json = await res.json();
                    if (!json.success) {
                        showAlert('Status change failed: ' + (json.error || json.message || 'Unknown error'), 'error');
                        return;
                    }
                    showAlert('Status successfully updated.', 'success');

                    // Refresh table list
                    await fetchTickets();
                    // If the View modal is open for this ticket, refresh it too
                    if (currentTicketId === ticketId) {
                        await openView(ticketId);
                    }
                } catch (e) {
                    console.error(e);
                    showAlert('Network error during status change.', 'error');
                }
            }

            // Function to handle the actual permanent deletion API call
            async function performPermanentDelete(ticketId) {
                try {
                    const res = await fetch('./api/delete_ticket.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            instance_id: instanceId,
                            ticket_id: ticketId
                        })
                    });
                    const json = await res.json();
                    if (!json.success) {
                        showAlert('Delete failed: ' + (json.error || json.message || 'Unknown error'), 'error');
                        return;
                    }

                    // Close view modal if it was open for the deleted ticket
                    if (currentTicketId === ticketId) {
                        hideModal(ui.modalView);
                        currentTicketId = null;
                    }

                    showAlert('Ticket deleted successfully.', 'success');
                    await fetchTickets();
                } catch (e) {
                    console.error(e);
                    showAlert('Network error during deletion.', 'error');
                }
            }

            // Function to render pagination
            function renderPagination(page, total) {
                const out = ui.pagination;
                out.innerHTML = '';
                const visible = 2;
                const start = Math.max(1, page - visible);
                const end = Math.min(total, page + visible);

                function makeA(p, label = null, active = false, disabled = false) {
                    const a = document.createElement('a');
                    a.className = `page-link ${active ? 'bg-cstm-primary text-white' : 'bg-neutral-100 dark:bg-neutral-600 dark:text-neutral-200'} text-secondary-light font-semibold rounded-lg border-0 flex items-center justify-center h-8 w-8 text-sm`;
                    if (disabled) {
                        a.classList.add('opacity-50');
                        a.style.pointerEvents = 'none';
                    }
                    a.href = '#';
                    a.dataset.page = p;
                    a.textContent = label || p;
                    return a;
                }

                // prev
                const liPrev = document.createElement('li');
                liPrev.appendChild(makeA(Math.max(1, page - 1), '‹', false, page <= 1));
                out.appendChild(liPrev);

                if (start > 1) {
                    const li = document.createElement('li');
                    li.appendChild(makeA(1, '1'));
                    out.appendChild(li);
                    if (start > 2) {
                        const dots = document.createElement('li');
                        dots.innerHTML = `<span class="px-2">...</span>`;
                        out.appendChild(dots);
                    }
                }

                for (let p = start; p <= end; p++) {
                    const li = document.createElement('li');
                    li.appendChild(makeA(p, String(p), p === page));
                    out.appendChild(li);
                }

                if (end < total) {
                    if (end < total - 1) {
                        const dots = document.createElement('li');
                        dots.innerHTML = `<span class="px-2">...</span>`;
                        out.appendChild(dots);
                    }
                    const li = document.createElement('li');
                    li.appendChild(makeA(total, String(total)));
                    out.appendChild(li);
                }

                const liNext = document.createElement('li');
                liNext.appendChild(makeA(Math.min(total, page + 1), '›', false, page >= total));
                out.appendChild(liNext);

                out.querySelectorAll('a.page-link').forEach(a => {
                    a.addEventListener('click', (ev) => {
                        ev.preventDefault();
                        const p = parseInt(a.dataset.page || '1', 10) || 1;
                        if (p === currentPage) return;
                        currentPage = p;
                        fetchTickets();
                    });
                });
            }

            function renderRow(idx, t) {
                const tr = document.createElement('tr');
                tr.dataset.ticketId = t.id;
                tr.className = 'hover:bg-gray-50/60 dark:hover:bg-neutral-900/60';
                tr.innerHTML = `
            <td class="px-4 py-3 text-sm">${idx}</td>
            <td class="px-4 py-3">
                <div class="font-semibold">${escapeHtml(t.ticket_number)}</div>
                <div class="text-neutral-500 text-xs">${escapeHtml(t.subject)}</div>
            </td>
            <td class="px-4 py-3">
                <span class="inline-block px-3 py-1 text-xs rounded-full ${statusClasses(t.status)}">${escapeHtml(t.status)}</span>
            </td>
            <td class="px-4 py-3 text-sm">${escapeHtml(t.priority)}</td>
            <td class="px-4 py-3 text-xs">${t.last_message_at ? escapeHtml(t.last_message_at) : '-'}</td>
            <td class="px-4 py-3 text-xs">${t.created_at ? escapeHtml(t.created_at) : '-'}</td>
            <td class="px-4 py-3 text-sm text-right">
                <div class="inline-flex items-center justify-end gap-2">
                    <button class="btn-icon bg-cstm-primary-20 drk-bg-cstm-primary-20 hover:bg-cstm-primary-30 text-cstm-primary drk-text-cstm-primary font-medium w-10 h-10 flex justify-center items-center rounded-full" data-action="view" data-id="${t.id}" title="View Ticket">
                        <iconify-icon icon="tabler:eye" class="text-xl"></iconify-icon>
                    </button>
                    <button class="btn-icon bg-cstm-primary-20 drk-bg-cstm-primary-20 hover:bg-cstm-primary-30 text-cstm-primary drk-text-cstm-primary font-medium w-10 h-10 flex justify-center items-center rounded-full" data-action="status" data-id="${t.id}" title="Change Status">
                        <iconify-icon icon="tabler:edit" class="text-xl"></iconify-icon>
                    </button>
                    <button class="btn-icon text-danger-600 dark:text-danger-600 bg-danger-100 dark:bg-danger-600/20 font-medium w-10 h-10 flex justify-center items-center rounded-full" data-action="close" data-id="${t.id}" title="Close Ticket">
                        <iconify-icon icon="tabler:trash"  class="text-xl"></iconify-icon>
                    </button>
                </div>
            </td>
        `;
                return tr;
            }

            function renderTable() {
                ui.ticketsBody.innerHTML = '';
                if (!ticketsCache || ticketsCache.length === 0) {
                    ui.ticketsBody.innerHTML = `<tr><td colspan="7" class="px-4 py-5 text-center text-neutral-500 dark:text-neutral-400">No tickets found.</td></tr>`;
                    ui.entriesInfo.textContent = '';
                    ui.headerEntriesInfo.textContent = `Showing 0 entries`;
                    ui.pagination.innerHTML = '';
                    return;
                }

                const startIdx = (currentPage - 1) * perPage;
                ticketsCache.forEach((t, i) => {
                    ui.ticketsBody.appendChild(renderRow(startIdx + i + 1, t));
                });

                ui.entriesInfo.textContent = `Showing ${Math.min(startIdx + 1, totalCount)} to ${Math.min(startIdx + ticketsCache.length, totalCount)} of ${totalCount} entries`;
                ui.headerEntriesInfo.textContent = `Showing ${totalCount} entries`;
                renderPagination(currentPage, totalPages);
            }

            // ---------- fetchTickets() - Added owner_email handling from consolidated API ----------
            async function fetchTickets() {
                setLoading(true);
                ui.ticketsBody.innerHTML = `<tr><td colspan="7" class="px-4 py-5 text-center text-neutral-500 dark:text-neutral-400">Loading tickets...</td></tr>`;
                try {
                    const params = new URLSearchParams();
                    params.set('instance_id', instanceId);
                    params.set('page', String(currentPage));
                    params.set('limit', String(perPage));
                    const q = ui.search.value.trim();
                    if (q) params.set('search', q);

                    const url = `./api/get-tickets.php?${params.toString()}`;
                    console.debug('Fetching tickets from', url);
                    const res = await fetch(url, {
                        cache: 'no-store'
                    });
                    if (!res.ok) throw new Error('Network response not ok: ' + res.status);
                    const json = await res.json();
                    console.debug('get-tickets response:', json);

                    if (!json.success) {
                        ui.ticketsBody.innerHTML = `<tr><td colspan="7" class="px-4 py-5 text-center text-danger-600 dark:text-danger-400">${escapeHtml(json.message || 'Failed to load tickets')}</td></tr>`;
                        ui.entriesInfo.textContent = '';
                        ui.headerEntriesInfo.textContent = `Showing — entries`;
                        ui.pagination.innerHTML = '';
                        ticketsCache = [];
                        totalCount = 0;
                        totalPages = 1;

                        if (json.error) {
                            showAlert('Error loading tickets: ' + json.error, 'error');
                        }
                        return;
                    }

                    // --- Update Instance Details from API response ---
                    if (json.owner_email) {
                        instanceDetails.owner_email = escapeHtml(json.owner_email);
                        ui.addOwnerEmail.textContent = instanceDetails.owner_email;
                        ui.viewOwnerEmail.textContent = instanceDetails.owner_email;
                    } else if (json.owner_email === null) {
                        instanceDetails.owner_email = 'N/A';
                        ui.addOwnerEmail.textContent = 'N/A';
                        ui.viewOwnerEmail.textContent = 'N/A';
                    }
                    // ---------------------------------------------------

                    // Data normalization (unchanged logic)
                    ticketsCache = Array.isArray(json.tickets) ? json.tickets :
                        (json.data && Array.isArray(json.data.tickets) ? json.data.tickets :
                            (Array.isArray(json.items) ? json.items :
                                (json.ticket ? [json.ticket] : [])));

                    totalCount = Number(json.total_count ?? (json.data && json.data.total_count) ?? (json.total ?? ticketsCache.length) ?? 0);
                    totalPages = Number(json.total_pages ?? (json.data && json.data.total_pages) ?? Math.max(1, Math.ceil(totalCount / perPage)));

                    ticketsCache = ticketsCache.map(t => {
                        return {
                            id: Number(t.id),
                            ticket_number: t.ticket_number || ('#' + (t.id || '')),
                            subject: t.subject || '',
                            status: t.status || 'open',
                            priority: t.priority || 'normal',
                            last_message_at: t.last_message_at || t.updated_at || '',
                            created_at: t.created_at || t.created || ''
                        };
                    });

                    // render table
                    renderTable();

                } catch (err) {
                    console.error('fetchTickets error', err);
                    ui.ticketsBody.innerHTML = `<tr><td colspan="7" class="px-4 py-5 text-center text-danger-600 dark:text-danger-400">Error loading tickets.</td></tr>`;
                    ui.entriesInfo.textContent = '';
                    ui.headerEntriesInfo.textContent = `Showing — entries`;
                    ui.pagination.innerHTML = '';
                    ticketsCache = [];
                    totalCount = 0;
                    totalPages = 1;
                    showAlert('Network error or invalid response from API.', 'error');

                } finally {
                    setLoading(false);
                }
            }

            // ---------- openView(ticketId) - Corrected API endpoint for single view ----------
            async function openView(ticketId) {
                if (!ticketId) {
                    console.warn('openView called without ticketId');
                    return;
                }

                currentTicketId = ticketId;
                ui.viewOwnerEmail.textContent = instanceDetails.owner_email; // Display cached owner email

                showModal(ui.modalView);

                ui.messagesList.innerHTML = 'Loading...';
                ui.attachmentsList.innerHTML = '';

                const currentStatus = ticketsCache.find(t => t.id === ticketId)?.status || 'open';
                ui.viewStatus.value = currentStatus;

                try {
                    // API call uses consolidated endpoint for single ticket view
                    const url = `./api/get-tickets.php?instance_id=${encodeURIComponent(instanceId)}&ticket_id=${encodeURIComponent(ticketId)}`;
                    console.debug('Fetching ticket details from', url);
                    const res = await fetch(url, {
                        cache: 'no-store'
                    });
                    if (!res.ok) {
                        throw new Error('Network error: ' + res.status);
                    }
                    const json = await res.json();
                    console.debug('get-tickets response (single view):', json);

                    const payload = json.success ? json :
                        (json.data ? json.data : json);

                    const t = payload.ticket || payload.data?.ticket || payload.data || payload;
                    const msgs = payload.messages || payload.data?.messages || payload.messages_list || payload.messagesList || [];

                    const ticketObj = Array.isArray(t) ? t[0] : t;

                    if (!ticketObj || !ticketObj.id) {
                        ui.messagesList.textContent = 'Ticket not found in response.';
                        console.error('Ticket object missing in response', json);
                        return;
                    }

                    function formatDateTime(dateTime) {
                        if (!dateTime) return '-';

                        // Convert "2026-08-10 05:54:13" to a valid Date format
                        const date = new Date(dateTime.replace(' ', 'T'));

                        if (isNaN(date.getTime())) return '-';

                        return date.toLocaleString('en-US', {
                            month: 'short',
                            day: '2-digit',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit',
                            hour12: true
                        });
                    }

                    // populate UI
                    ui.viewTicketNumber.textContent = ticketObj.ticket_number || ('#' + ticketObj.id);
                    ui.viewSubject.textContent = ticketObj.subject || '';
                    ui.metaPriority.textContent = ticketObj.priority || '';
                    ui.metaCreated.textContent = formatDateTime( ticketObj.created_at || ticketObj.created || '-' );
                    ui.metaLast.textContent = formatDateTime( ticketObj.last_message_at || ticketObj.updated_at || '-' );
                    ui.metaCount.textContent = (Array.isArray(msgs) ? msgs.length : 0);
                    ui.viewStatus.value = ticketObj.status || 'open';
                    ui.viewOwnerEmail.textContent = ticketObj.owner_email || instanceDetails.owner_email; // Use ticket owner email if available

                    // messages rendering
                    ui.messagesList.innerHTML = '';
                    if (!Array.isArray(msgs) || !msgs.length) {
                        if (ticketObj.initial_message && typeof ticketObj.initial_message === 'string') {
                            ui.messagesList.innerHTML = `<div class="p-3 rounded-lg border border-neutral-200 dark:border-neutral-700"><div class="text-xs text-neutral-500">${escapeHtml(ticketObj.created_at || '')}</div><div class="mt-1 whitespace-pre-wrap">${escapeHtml(ticketObj.initial_message)}</div></div>`;
                        } else {
                            ui.messagesList.innerHTML = `<div class="text-sm text-neutral-500">No messages yet.</div>`;
                        }
                    } else {
                        msgs.forEach(m => {
                            const created = m.created_at || m.created || m.timestamp || '';
                            const body = m.message ?? m.body ?? m.text ?? '';
                            const box = document.createElement('div');
                            box.className = 'p-3 rounded-lg border border-neutral-200 dark:border-neutral-700';
                            box.innerHTML = `<div class="text-xs text-neutral-500">${escapeHtml(created)}</div><div class="mt-1 whitespace-pre-wrap">${escapeHtml(body)}</div>`;
                            ui.messagesList.appendChild(box);

                            // if (m.attachments && Array.isArray(m.attachments) && m.attachments.length) {
                            //     const list = document.createElement('div');
                            //     list.className = 'mt-2 text-xs';
                            //     m.attachments.forEach(a => {
                            //         const aEl = document.createElement('div');
                            //         aEl.innerHTML = `<a href="${escapeHtml(a.url)}" target="_blank" class="underline text-sm">${escapeHtml(a.original_name || a.name || a.filename)}</a> <span class="text-neutral-400 text-xs">(${Math.round((a.size_bytes||a.size||0)/1024)} KB)</span>`;
                            //         list.appendChild(aEl);
                            //     });
                            //     ui.messagesList.appendChild(list);
                            // }
                            if (m.attachments && Array.isArray(m.attachments) && m.attachments.length) {

                                const list = document.createElement('div');
                                list.className = 'mt-2 space-y-2';

                                m.attachments.forEach(a => {

                                    const isImage = a.mime_type && a.mime_type.startsWith('image/');
                                    const isPdf = a.mime_type === 'application/pdf';
                                    const isZip = a.mime_type && a.mime_type.includes('zip');

                                    const safeUrl = escapeHtml(a.url);
                                    // const safeName = escapeHtml(a.original_name || 'file');
                                    const originalName = escapeHtml(a.original_name || 'file');
                                    const safeName = shortenFileName(originalName, 28);

                                    const fileSize = Math.round((a.size_bytes || 0) / 1024);

                                    const row = document.createElement('div');
                                    row.className = 'flex items-center justify-between gap-3 p-2 rounded-lg bg-neutral-50 dark:bg-neutral-800 hover:bg-neutral-100 dark:hover:bg-neutral-700 transition';

                                    /* ---------------- LEFT SIDE ---------------- */
                                    const left = document.createElement('div');
                                    left.className = 'flex items-center gap-3 min-w-0';

                                    // Attachment Icon
                                    const attachIcon = `
                                    <div class="w-7 h-7 flex items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white shadow">
                                        <svg xmlns="http://www.w3.org/2000/svg"
                                            class="w-4 h-4 text-neutral-500 flex-shrink-0"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor">
                                            
                                            <path stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M10 13a5 5 0 007.07 0l2.83-2.83a5 5 0 00-7.07-7.07L10 6m4 5a5 5 0 00-7.07 0L4.1 13.9a5 5 0 007.07 7.07L14 18"/>
                                        </svg>
                                    </div>
                                `;

                                    // 🖼️ Image / File Preview Icon
                                    let preview = '';
                                    if (isImage) {
                                        preview = `
                                        <img src="${safeUrl}"
                                            alt="${safeName}"
                                            class="w-10 h-10 object-contain rounded"
                                            loading="lazy">
                                    `;
                                    } else if (isPdf) {
                                        preview = `<div class="w-9 h-9 flex items-center justify-center rounded bg-red-100 text-red-600 font-bold text-xs">PDF</div>`;
                                    } else if (isZip) {
                                        preview = `<div class="w-9 h-9 flex items-center justify-center rounded bg-yellow-100 text-yellow-700 font-bold text-xs">ZIP</div>`;
                                    } else {
                                        preview = `<div class="w-9 h-9 flex items-center justify-center rounded bg-blue-100 text-blue-700 font-bold text-xs">FILE</div>`;
                                    }

                                    // 📄 File name + size
                                    const nameBlock = `
                                    <div class="min-w-0">
                                        <div class="text-xs font-semibold truncate whitespace-nowrap overflow-hidden max-w-[180px]"
                                            title="${originalName}">
                                            ${safeName}
                                        </div>

                                        <div class="text-[10px] text-neutral-400">${fileSize} KB</div>
                                    </div>
                                `;

                                    left.innerHTML = attachIcon + preview + nameBlock;

                                    /* ---------------- RIGHT SIDE (DOWNLOAD) ---------------- */
                                    const right = document.createElement('div');
                                    right.innerHTML = `
                                    <a href="${safeUrl}"
                                    download="${safeName}"
                                    class="flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800 hover:underline whitespace-nowrap">
                                    
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none"
                                            viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5m0 0l5-5m-5 5V4"/>
                                        </svg>

                                        Download
                                    </a>
                                `;

                                    row.appendChild(left);
                                    row.appendChild(right);
                                    list.appendChild(row);
                                });

                                ui.messagesList.appendChild(list);
                            }

                        });
                    }

                    // Scroll to the bottom of the message thread to show the latest messages/reply form
                    const threadContainer = document.getElementById('view-thread');
                    if (threadContainer) {
                        threadContainer.scrollTop = threadContainer.scrollHeight;
                    }

                } catch (err) {
                    console.error('openView error', err);
                    ui.messagesList.textContent = 'Network error while loading ticket.';
                    showAlert('Error loading ticket details.', 'error');
                }
            }


            // UPDATED: Create ticket logic to handle file uploads
            ui.addSubmit.addEventListener('click', async () => {
                const subj = ui.addSubject.value.trim();
                const msg = ui.addMessage.value.trim();
                const priority = ui.addPriority.value;
                const fileInput = ui.addFile;
                const file = fileInput.files[0];

                if (!subj || !msg) {
                    showAlert('Subject and message required.', 'error');
                    return;
                }

                ui.addSubmit.disabled = true; // Disable button during submission

                ui.addSubmit.innerHTML = `
                    <span class="inline-flex items-center gap-2">
                        <iconify-icon icon="line-md:loading-loop" class="text-base"></iconify-icon>
                        Creating...
                    </span>
                `;
                let ticketId = null;

                try {
                    // 1. Create Ticket
                    const res = await fetch('./api/create_ticket.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            instance_id: instanceId,
                            subject: subj,
                            priority: priority
                        })
                    });
                    const json = await res.json();
                    if (!json.success || !json.ticket_id) {
                        showAlert('Ticket creation failed: ' + (json.error || json.message || 'Unknown error'), 'error');
                        return;
                    }

                    ticketId = json.ticket_id;

                    // 2. Post Initial Message
                    const msgRes = await fetch('./api/post_ticket_message.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            instance_id: instanceId,
                            ticket_id: ticketId,
                            message: msg,
                            is_internal: 0
                        })
                    });
                    const msgJson = await msgRes.json();
                    if (!msgJson.success) {
                        showAlert('Ticket created, but initial message failed: ' + (msgJson.error || msgJson.message || 'Unknown error'), 'error');
                        return;
                    }

                    const messageId = msgJson.message_id || null;

                    // 3. Handle File Upload (if message and file exist)
                    if (file && messageId) {
                        const fd = new FormData();
                        fd.append('instance_id', instanceId);
                        fd.append('ticket_id', ticketId);
                        fd.append('message_id', messageId);
                        fd.append('file', file);

                        const upRes = await fetch('./api/upload_ticket_attachment.php', {
                            method: 'POST',
                            body: fd
                        });
                        const upJson = await upRes.json();
                        if (!upJson.success) {
                            showAlert('Ticket created, but file upload failed: ' + (upJson.error || upJson.message || ''), 'error');
                        }
                    }

                    closeAddModal();
                    fetchTickets();
                    showAlert('Ticket created successfully!', 'success');

                } catch (e) {
                    console.error(e);
                    showAlert('Network error during ticket creation.', 'error');
                } finally {
                    ui.addSubmit.disabled = false;
                     ui.addSubmit.innerHTML = 'Create';
                }
            });

            function showAddModal() {
                if (!ui.modalAdd) {
                    console.error('Modal element not found');
                    return;
                }
                if (ui.addSubject) ui.addSubject.value = '';
                if (ui.addMessage) ui.addMessage.value = '';
                if (ui.addPriority) ui.addPriority.value = 'normal';
                if (ui.addFile) ui.addFile.value = '';
                showModal(ui.modalAdd);
            }


            function closeAddModal() {
                const modal = document.getElementById('modal-add');
                if (modal) {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                    modal.style.display = 'none';
                    console.log('Modal closed');
                } else {
                    console.error('Modal element not found for closing');
                }
            }

            // Reply send
            ui.replySend.addEventListener('click', async () => {
                const txt = ui.replyText.value.trim();
                if (!txt) {
                    showAlert('Reply message required.', 'error');
                    return;
                }

                ui.replySend.disabled = true; // Disable button during submission
                try {
                    const res = await fetch('./api/post_ticket_message.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            instance_id: instanceId,
                            ticket_id: currentTicketId,
                            message: txt,
                            is_internal: 0
                        })
                    });
                    const json = await res.json();
                    if (!json.success) {
                        showAlert('Send failed: ' + (json.error || json.message || 'Unknown error'), 'error');
                        return;
                    }

                    // file upload if present and message_id returned
                    const fileInput = ui.replyFile;
                    if (fileInput && fileInput.files && fileInput.files[0]) {
                        const messageId = json.message_id || null;
                        if (messageId) {
                            const fd = new FormData();
                            fd.append('instance_id', instanceId);
                            fd.append('ticket_id', currentTicketId);
                            fd.append('message_id', messageId);
                            fd.append('file', fileInput.files[0]);
                            const upRes = await fetch('./api/upload_ticket_attachment.php', {
                                method: 'POST',
                                body: fd
                            });
                            const upJson = await upRes.json();
                            if (!upJson.success) {
                                showAlert('Reply sent, but file upload failed: ' + (upJson.error || upJson.message || ''), 'error');
                            }
                        }
                    }

                    ui.replyText.value = '';
                    ui.replyFile.value = '';
                    showAlert('Reply sent successfully.', 'success');
                    await openView(currentTicketId); // Refresh view to show new message

                } catch (e) {
                    console.error(e);
                    showAlert('Network error during reply.', 'error');
                } finally {
                    ui.replySend.disabled = false;
                }
            });

            if (ui.replyCancel) {
                ui.replyCancel.addEventListener('click', () => {
                    if (ui.replyText) ui.replyText.value = '';
                    if (ui.replyFile) ui.replyFile.value = '';
                });
            } else {
                console.warn('reply-cancel element not found');
            }


            // ---------------------------------------------
            // TICKET TABLE ACTIONS (List View)
            // ---------------------------------------------
            ui.ticketsBody.addEventListener('click', async (ev) => {
                const btn = ev.target.closest('button[data-action]');
                if (!btn) return;
                const action = btn.dataset.action;
                const id = parseInt(btn.dataset.id, 10);
                if (!id) return;

                pendingActionTicketId = id;

                // 1. View
                if (action === 'view') return openView(id);

                // 2. Change Status (Modal)
                if (action === 'status') {
                    ui.statusTicketIdDisplay.textContent = getTicketNumber(id);
                    // Pre-select current status
                    const currentStatus = ticketsCache.find(t => t.id === id)?.status || 'open';
                    ui.statusSelect.value = currentStatus;
                    showModal(ui.modalStatus);
                }

                // 3. Close Ticket (Modal)
                if (action === 'close') {
                    ui.closeTicketIdDisplay.textContent = getTicketNumber(id);
                    showModal(ui.modalConfirmClose);
                }
            });

            // ---------------------------------------------
            // MODAL SUBMITS 
            // ---------------------------------------------

            // Status Modal Submit 
            ui.statusSubmit.addEventListener('click', async () => {
                const id = pendingActionTicketId || currentTicketId;
                const newStatus = ui.statusSelect.value;
                if (!id) return;

                hideModal(ui.modalStatus);

                const note = pendingActionTicketId ? 'changed from list' : 'changed from UI';

                await performStatusChange(id, newStatus, note);
                pendingActionTicketId = null;
            });

            // Close Confirm Modal Submit 
            ui.closeSubmit.addEventListener('click', async () => {
                const id = pendingActionTicketId;
                if (!id) return;

                hideModal(ui.modalConfirmClose);
                await performStatusChange(id, 'closed', 'closed from list');
                pendingActionTicketId = null;
            });

            // Delete Permanently Modal Submit
            ui.deleteSubmit.addEventListener('click', async () => {
                const id = currentTicketId;
                if (!id) return;

                hideModal(ui.modalConfirmDelete);
                await performPermanentDelete(id);
            });

            // ---------------------------------------------
            // MODAL OPEN/CLOSE (Including View Modal Close Fix)
            // ---------------------------------------------
            // + New Ticket button - ensure it works
            // Use multiple approaches to ensure it works
            function setupNewTicketButton() {
                const btn = document.getElementById('btn-new-ticket');
                if (!btn) {
                    console.error('btn-new-ticket button not found in DOM');
                    return false;
                }

                // Remove any existing handlers by cloning
                const newBtn = btn.cloneNode(true);
                btn.parentNode.replaceChild(newBtn, btn);

                // Get fresh reference
                const freshBtn = document.getElementById('btn-new-ticket');
                if (!freshBtn) {
                    console.error('Failed to get fresh button reference');
                    return false;
                }

                // Ensure button is enabled
                freshBtn.disabled = false;
                freshBtn.style.pointerEvents = 'auto';
                freshBtn.style.cursor = 'pointer';

                // Attach handler with capture phase
                freshBtn.addEventListener('click', function handleNewTicketClick(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();

                    console.log('+ New Ticket button clicked');

                    // Get modal directly
                    const modal = document.getElementById('modal-add');
                    console.log('Modal element:', modal);

                    if (!modal) {
                        console.error('Modal element not found');
                        alert('Error: Modal not found. Please refresh the page.');
                        return;
                    }

                    // Show modal - multiple methods to ensure it works
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                    modal.style.display = 'flex';
                    modal.style.visibility = 'visible';
                    modal.style.opacity = '1';
                    modal.style.zIndex = '50';

                    // Clear form fields
                    const subjectEl = document.getElementById('add-subject');
                    const messageEl = document.getElementById('add-message');
                    const priorityEl = document.getElementById('add-priority');
                    const fileEl = document.getElementById('add-file');

                    if (subjectEl) subjectEl.value = '';
                    if (messageEl) messageEl.value = '';
                    if (priorityEl) priorityEl.value = 'normal';
                    if (fileEl) fileEl.value = '';

                    console.log('Modal should now be visible. Classes:', modal.className);
                    console.log('Modal style.display:', modal.style.display);

                    // Force a reflow to ensure display change takes effect
                    void modal.offsetHeight;
                }, true); // Capture phase to run before other handlers

                console.log('New Ticket button handler attached successfully');
                return true;
            }

            // Try to set up immediately
            if (!setupNewTicketButton()) {
                // If it fails, try again after a short delay
                setTimeout(() => {
                    if (!setupNewTicketButton()) {
                        console.error('Failed to set up New Ticket button after retry');
                    }
                }, 500);
            }

            // Also attach via event delegation as backup (runs in capture phase)
            document.addEventListener('click', function(e) {
                const target = e.target.closest('#btn-new-ticket');
                if (target) {
                    // Only handle if we're on manage-tickets page
                    const isManageTicketsPage = window.location.pathname.includes('manage-tickets') ||
                        window.location.href.includes('manage-tickets');
                    if (isManageTicketsPage) {
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();

                        console.log('New Ticket clicked via event delegation');

                        const modal = document.getElementById('modal-add');
                        if (modal) {
                            // Show modal with multiple methods
                            modal.classList.remove('hidden');
                            modal.classList.add('flex');
                            modal.style.display = 'flex';
                            modal.style.visibility = 'visible';
                            modal.style.opacity = '1';

                            // Clear form
                            const subjectEl = document.getElementById('add-subject');
                            const messageEl = document.getElementById('add-message');
                            const priorityEl = document.getElementById('add-priority');
                            const fileEl = document.getElementById('add-file');

                            if (subjectEl) subjectEl.value = '';
                            if (messageEl) messageEl.value = '';
                            if (priorityEl) priorityEl.value = 'normal';
                            if (fileEl) fileEl.value = '';

                            console.log('Modal opened via event delegation');
                        } else {
                            console.error('Modal not found in event delegation handler');
                        }
                    }
                }
            }, true); // Capture phase - runs before other handlers


            // Close and Cancel buttons - get fresh references and attach handlers
            function setupCloseButtons() {
                const closeBtn = document.getElementById('add-close');
                const cancelBtn = document.getElementById('add-cancel');

                if (closeBtn) {
                    // Remove existing handlers by cloning
                    const newCloseBtn = closeBtn.cloneNode(true);
                    closeBtn.parentNode.replaceChild(newCloseBtn, closeBtn);

                    // Get fresh reference and attach handler
                    const freshCloseBtn = document.getElementById('add-close');
                    freshCloseBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log('Close (X) button clicked');
                        closeAddModal();
                    });
                } else {
                    console.error('add-close button not found');
                }

                if (cancelBtn) {
                    // Remove existing handlers by cloning
                    const newCancelBtn = cancelBtn.cloneNode(true);
                    cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);

                    // Get fresh reference and attach handler
                    const freshCancelBtn = document.getElementById('add-cancel');
                    freshCancelBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log('Cancel button clicked');
                        closeAddModal();
                    });
                } else {
                    console.error('add-cancel button not found');
                }
            }

            // Set up close buttons
            setupCloseButtons();

            // Also close when clicking outside the modal (on backdrop)
            const modalAdd = document.getElementById('modal-add');
            if (modalAdd) {
                modalAdd.addEventListener('click', function(e) {
                    // If clicking on the backdrop (the modal container itself, not the inner content)
                    if (e.target === modalAdd) {
                        console.log('Clicked on modal backdrop, closing modal');
                        closeAddModal();
                    }
                });
            }

            // FIX: View Modal Close Button
            ui.viewCloseBtn.addEventListener('click', () => {
                hideModal(ui.modalView);
                currentTicketId = null;
            });

            ui.statusClose.addEventListener('click', () => hideModal(ui.modalStatus));
            ui.statusCancel.addEventListener('click', () => hideModal(ui.modalStatus));
            ui.closeConfirmClose.addEventListener('click', () => hideModal(ui.modalConfirmClose));
            ui.closeCancel.addEventListener('click', () => hideModal(ui.modalConfirmClose));
            ui.deleteClose.addEventListener('click', () => hideModal(ui.modalConfirmDelete));
            ui.deleteCancel.addEventListener('click', () => hideModal(ui.modalConfirmDelete));


            // ---------------------------------------------
            // VIEW MODAL ACTIONS (Updated to use custom notification/confirm)
            // ---------------------------------------------

            // Open delete modal from view screen
            ui.btnDeleteTicket.addEventListener('click', () => {
                if (!currentTicketId) return;
                ui.deleteTicketIdDisplay.textContent = getTicketNumber(currentTicketId);
                showModal(ui.modalConfirmDelete);
            });

            // Status dropdown change in View Modal (Kept browser confirm here for simplicity)
            ui.viewStatus.addEventListener('change', async () => {
                const newStatus = ui.viewStatus.value;
                const ticketNumber = getTicketNumber(currentTicketId);

                if (!confirm(`Are you sure you want to change the status of ${ticketNumber} to ${newStatus}?`)) {
                    // If canceled, revert dropdown selection to cached status and return.
                    const currentTicket = ticketsCache.find(t => t.id === currentTicketId);
                    if (currentTicket) {
                        ui.viewStatus.value = currentTicket.status;
                    }
                    return;
                }
                await performStatusChange(currentTicketId, newStatus, 'changed via view modal dropdown');
            });

            // refresh & per-page & search 
            ui.refresh.addEventListener('click', () => fetchTickets());
            ui.perPage.addEventListener('change', () => {
                perPage = parseInt(ui.perPage.value, 10) || 25;
                currentPage = 1;
                fetchTickets();
            });
            ui.search.addEventListener('keyup', (e) => {
                if (e.key === 'Enter') {
                    currentPage = 1;
                    fetchTickets();
                }
            });

            // initial load
            fetchTickets();
        });
    </script>