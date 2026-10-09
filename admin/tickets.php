<?php
// File: /admin/tickets.php
$title    = 'Support Tickets';
$subTitle = 'Manage all incoming support requests.';

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';
require_once __DIR__ . '/core/TicketManager.php';

SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

TicketManager::initialize($pdo);

$perPage      = isset($_GET['show'])     ? max(1, (int) $_GET['show'])     : 10;
$currentPage  = isset($_GET['page'])     ? max(1, (int) $_GET['page'])     : 1;
$searchQuery  = isset($_GET['search'])   ? trim((string) $_GET['search'])  : '';
$statusFilter = isset($_GET['status'])   ? trim((string) $_GET['status'])  : '';
$prioFilter   = isset($_GET['priority']) ? trim((string) $_GET['priority']) : '';

$tickets    = TicketManager::getFilteredTickets($searchQuery, $statusFilter, $prioFilter, $perPage, $currentPage);
$totalCount = TicketManager::getTotalTickets($searchQuery, $statusFilter, $prioFilter);
$totalPages = max(1, (int) ceil($totalCount / $perPage));

/**
 * Status / priority badge classes — match the rest of the admin look.
 */
function ticketBadge(string $type, string $value): string
{
    $success = 'bg-success-100 text-success-600 dark:bg-success-600/20 dark:text-success-400';
    $danger  = 'bg-danger-100 text-danger-600 dark:bg-danger-600/20 dark:text-danger-400';
    $warning = 'bg-warning-100 text-warning-600 dark:bg-warning-600/20 dark:text-warning-400';
    $neutral = 'bg-neutral-100 text-neutral-600 dark:bg-neutral-600/20 dark:text-neutral-400';
    $info    = 'bg-info-100 text-info-600 dark:bg-info-600/20 dark:text-info-400';

    $v = strtolower($value);

    if ($type === 'status') {
        if ($v === 'open')    return $success;
        if ($v === 'pending') return $warning;
        if ($v === 'closed')  return $neutral;
        return $neutral;
    }
    if ($type === 'priority') {
        if ($v === 'urgent') return $danger;
        if ($v === 'high')   return $warning;
        if ($v === 'normal') return $neutral;
        if ($v === 'low')    return $info;
        return $neutral;
    }
    return $neutral;
}

function renderTicketsTable(array $tickets, int $currentPage, int $perPage, int $totalCount, int $totalPages): string
{
    ob_start();
?>
    <div class="cstm-scroll-sm overflow-x-auto">
        <table class="table bordered-table sm-table table-auto text-sm">
            <thead>
                <tr>
                    <th>Ticket #</th>
                    <th>Subject / Instance ID</th>
                    <th>Customer Site / Email</th>
                    <th class="!text-center">Status</th>
                    <th class="!text-center">Priority</th>
                    <th>Last Activity</th>
                    <th class="!text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tickets)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-6 text-neutral-500 dark:text-neutral-400">
                            No tickets found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tickets as $t):
                        $statusClass = ticketBadge('status',   (string)($t['status']   ?? ''));
                        $prioClass   = ticketBadge('priority', (string)($t['priority'] ?? ''));

                        $shopName     = $t['shop_name']      ?? 'Unknown';
                        $custEmail    = $t['customer_email'] ?? '';
                        $siteDomain   = $t['site_domain']    ?: ($t['shop_domain'] ?? '');
                        $siteUrl      = $siteDomain ? (str_starts_with($siteDomain, 'http') ? $siteDomain : 'https://' . $siteDomain) : '';

                        $lastActivity = $t['last_message_at'] ?: ($t['updated_at'] ?: $t['created_at']);
                        $lastActivityFmt = '—';
                        if (!empty($lastActivity)) {
                            try {
                                $dt = new DateTime($lastActivity, new DateTimeZone('UTC'));
                                $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
                                $lastActivityFmt = $dt->format('d M Y, H:i');
                            } catch (Throwable $e) {
                                $lastActivityFmt = htmlspecialchars((string)$lastActivity);
                            }
                        }
                    ?>
                        <tr>
                            <td class="font-medium text-cstm-primary whitespace-nowrap">
                                <?= htmlspecialchars((string)$t['ticket_number']) ?>
                            </td>
                            <td>
                                <span class="block font-medium">
                                    <?= htmlspecialchars((string)$t['subject']) ?>
                                </span>
                                <span class="block text-xs text-secondary-light break-all">
                                    <?= htmlspecialchars((string)$t['instance_id']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="block font-medium">
                                    <?= htmlspecialchars((string)$shopName) ?>
                                </span>
                                <?php if ($custEmail): ?>
                                    <a href="mailto:<?= htmlspecialchars($custEmail) ?>"
                                        class="block text-xs text-primary-500 hover:underline">
                                        <?= htmlspecialchars($custEmail) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="block text-xs text-neutral-400">No email</span>
                                <?php endif; ?>
                            </td>
                            <td class="!text-center">
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?= $statusClass ?>">
                                    <?= htmlspecialchars(ucfirst((string)$t['status'])) ?>
                                </span>
                            </td>
                            <td class="!text-center">
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?= $prioClass ?>">
                                    <?= htmlspecialchars(ucfirst((string)$t['priority'])) ?>
                                </span>
                            </td>
                            <td class="whitespace-nowrap"><?= htmlspecialchars($lastActivityFmt) ?></td>
                            <td class="!text-center">
                                <a href="ticket-view.php?id=<?= (int)$t['id'] ?>"
                                    class="bg-cstm-primary-20 text-cstm-primary hover:bg-cstm-primary-30 w-10 h-10 inline-flex justify-center items-center rounded-full"
                                    title="View Ticket">
                                    <iconify-icon icon="solar:eye-bold" class="text-xl"></iconify-icon>
                                </a>
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
            <?php if ($totalCount === 0): ?>
                Showing 0 entries
            <?php else: ?>
                Showing <?= (($currentPage - 1) * $perPage) + 1 ?>
                to <?= min($currentPage * $perPage, $totalCount) ?>
                of <?= $totalCount ?> entries
            <?php endif; ?>
        </span>

        <ul class="pagination flex items-center gap-2">
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
                        <a href="#"
                            data-page="<?= $p ?>"
                            class="page-link rounded-lg h-8 w-8 flex items-center justify-center text-sm
                           <?= ($p == $currentPage) ? 'bg-cstm-primary text-white' : 'bg-neutral-100 dark:bg-neutral-700' ?>">
                            <?= $p ?>
                        </a>
                    </li>
                <?php elseif ($p == $currentPage - ($range + 1) || $p == $currentPage + ($range + 1)): ?>
                    <li><span class="h-8 w-8 flex items-center justify-center">...</span></li>
            <?php endif;
            endfor; ?>

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
    echo renderTicketsTable($tickets, $currentPage, $perPage, $totalCount, $totalPages);
    exit;
}

$script = <<<JS
<script>
$(document).ready(function () {

    let searchTimeout = null;

    function fetchTickets(page = 1) {
        let perPage  = $("#per-page-select").val();
        let search   = $("#search-input").val().trim();
        let status   = $("#status-filter").val();
        let priority = $("#priority-filter").val();

        $.get("tickets.php", {
            ajax: 1,
            show: perPage,
            page: page,
            search: search,
            status: status,
            priority: priority
        }, function (html) {
            $(".tickets-container-wrapper").html(html);

            let newUrl = new URL(window.location.href);
            newUrl.searchParams.set("show", perPage);
            newUrl.searchParams.set("page", page);

            search   ? newUrl.searchParams.set("search", search)     : newUrl.searchParams.delete("search");
            status   ? newUrl.searchParams.set("status", status)     : newUrl.searchParams.delete("status");
            priority ? newUrl.searchParams.set("priority", priority) : newUrl.searchParams.delete("priority");

            window.history.pushState({}, "", newUrl);
        });
    }

    $("#search-input").on("keyup", function () {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function () { fetchTickets(1); }, 300);
    });

    $("#per-page-select, #status-filter, #priority-filter").on("change", function () {
        fetchTickets(1);
    });

    $(document).on("click", ".pagination .page-link", function (e) {
        e.preventDefault();
        let page = $(this).data("page");
        if (page) fetchTickets(page);
    });
});
</script>
JS;

include './partials/layouts/layoutTop.php';
?>

<div class="grid grid-cols-12">
    <div class="col-span-12">
        <div class="card h-full p-0 rounded-xl border-0 overflow-hidden">

            <div class="card-header border-b border-neutral-200 dark:border-neutral-600 bg-white dark:bg-neutral-700 py-4 px-6">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <h6 class="text-lg font-semibold mb-0">
                        Ticket Overview (<?= (int)$totalCount ?> Total)
                    </h6>
                </div>

                <div class="mt-4 grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">

                    <div class="sm:col-span-4">
                        <label class="block text-xs font-medium text-neutral-500 mb-1">Search:</label>
                        <div class="flex items-center px-3 w-80 h-10 bg-white rounded-lg border border-neutral-300 focus-within:border-violet-600 focus-within:ring-2 focus-within:ring-violet-600/20 transition dark:bg-neutral-700">
                            <iconify-icon icon="iconamoon:search-bold" class="text-cstm-primary mr-2 text-base transition"></iconify-icon>
                            <input type="text" id="search-input"
                                class="flex-1 bg-transparent text-sm placeholder:text-neutral-400 border-0 outline-none ring-0 shadow-none appearance-none focus:ring-0 focus:outline-none"
                                value="<?= htmlspecialchars($searchQuery, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                placeholder="Subject, ID, Email...">
                        </div>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-neutral-500 mb-1">Status:</label>
                        <select id="status-filter" class="form-select dark:bg-neutral-600 dark:text-white mt-0">
                            <option value="" <?= $statusFilter === ''        ? 'selected' : '' ?>>All</option>
                            <option value="open" <?= $statusFilter === 'open'    ? 'selected' : '' ?>>Open</option>
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="closed" <?= $statusFilter === 'closed'  ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-neutral-500 mb-1">Priority:</label>
                        <select id="priority-filter" class="form-select dark:bg-neutral-600 dark:text-white mt-0">
                            <option value="" <?= $prioFilter === ''       ? 'selected' : '' ?>>All</option>
                            <option value="low" <?= $prioFilter === 'low'    ? 'selected' : '' ?>>Low</option>
                            <option value="normal" <?= $prioFilter === 'normal' ? 'selected' : '' ?>>Normal</option>
                            <option value="high" <?= $prioFilter === 'high'   ? 'selected' : '' ?>>High</option>
                            <option value="urgent" <?= $prioFilter === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                        </select>
                    </div>

                    <div class="sm:col-span-4 md:text-right">
                        <label class="block text-xs font-medium text-neutral-500 mb-1">Show:</label>
                        <select id="per-page-select" class="form-select w-auto md:ml-auto dark:bg-neutral-600 dark:text-white mt-0">
                            <option value="10" <?= ($perPage == 10)  ? 'selected' : '' ?>>10</option>
                            <option value="25" <?= ($perPage == 25)  ? 'selected' : '' ?>>25</option>
                            <option value="50" <?= ($perPage == 50)  ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= ($perPage == 100) ? 'selected' : '' ?>>100</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card-body p-6 tickets-container-wrapper">
                <?= renderTicketsTable($tickets, $currentPage, $perPage, $totalCount, $totalPages) ?>
            </div>

        </div>
    </div>
</div>

<?php
echo $script;
include './partials/layouts/layoutBottom.php';
?>