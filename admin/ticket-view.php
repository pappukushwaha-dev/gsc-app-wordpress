<?php
declare(strict_types=1);

$title    = 'Ticket Details';
$subTitle = 'Support Tickets';

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

$ticketId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($ticketId <= 0) {
    header('Location: tickets.php');
    exit;
}

$adminUsername = (string)($_SESSION['admin_username']
    ?? $_SESSION['admin_email']
    ?? 'admin');

/* ------------------------------------------------------------
   POST actions (PRG)
------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    // Fetch detail just to get instance_id for scoped writes
    $detail = TicketManager::getTicketDetail($ticketId);
    if (!$detail) {
        $_SESSION['ticket_flash'] = ['type' => 'error', 'msg' => 'Ticket not found.'];
        header('Location: tickets.php');
        exit;
    }
    $instanceId = (string)$detail['instance_id'];

    $result = ['success' => false, 'error' => 'Unknown action'];
    switch ($action) {
        case 'update_status':
            $newStatus = trim((string)($_POST['status'] ?? ''));
            $note      = trim((string)($_POST['note']   ?? ''));
            $result = TicketManager::updateStatus($ticketId, $instanceId, $newStatus, $adminUsername, $note !== '' ? $note : null);
            break;

        case 'update_priority':
            $newPriority = trim((string)($_POST['priority'] ?? ''));
            $result = TicketManager::updatePriority($ticketId, $instanceId, $newPriority, $adminUsername);
            break;

        case 'reply':
            $message    = (string)($_POST['message'] ?? '');
            $isInternal = !empty($_POST['is_internal']);
            $result = TicketManager::postAdminReply($ticketId, $instanceId, $message, $isInternal, $adminUsername);
            break;
    }

    $_SESSION['ticket_flash'] = $result['success']
        ? ['type' => 'success', 'msg' => ($result['unchanged'] ?? false) ? 'No change.' : 'Saved.']
        : ['type' => 'error',   'msg' => $result['error'] ?? 'Action failed.'];

    header('Location: ticket-view.php?id=' . $ticketId);
    exit;
}

/* ------------------------------------------------------------
   GET: load full ticket payload
------------------------------------------------------------ */
$ticket = TicketManager::getTicketDetail($ticketId);

if (!$ticket) {
    include './partials/layouts/layoutTop.php';
    ?>
    <div class="flex flex-col items-center justify-center text-center" style="padding-top:6rem;padding-bottom:6rem;">
        <h3 class="text-2xl font-bold text-danger-600 mb-2">Ticket Not Found</h3>
        <p class="text-neutral-500 dark:text-neutral-400 mb-6">The requested ticket could not be located.</p>
        <a href="tickets.php" class="inline-flex items-center gap-2 bg-primary-600 text-white rounded-lg px-6 py-3 font-semibold hover:bg-primary-700 transition-colors">
            <iconify-icon icon="solar:arrow-left-linear" class="icon text-lg"></iconify-icon>
            Back to Tickets
        </a>
    </div>
    <?php
    include './partials/layouts/layoutBottom.php';
    exit;
}

$instanceId = (string)$ticket['instance_id'];
$messages   = TicketManager::getMessages($ticketId, $instanceId);
$history    = TicketManager::getStatusHistory($ticketId, $instanceId);

$flash = $_SESSION['ticket_flash'] ?? null;
unset($_SESSION['ticket_flash']);

/* ------------ Helpers (same look as tickets.php) ------------ */
function tvStatusBadge(string $status): string
{
    return match (strtolower($status)) {
        'open'    => 'bg-success-100 text-success-600 dark:bg-success-600/20 dark:text-success-400',
        'pending' => 'bg-warning-100 text-warning-600 dark:bg-warning-600/20 dark:text-warning-400',
        'closed'  => 'bg-neutral-200 text-neutral-700 dark:bg-neutral-600/30 dark:text-neutral-300',
        default   => 'bg-neutral-100 text-neutral-600',
    };
}
function tvPriorityBadge(string $priority): string
{
    return match (strtolower($priority)) {
        'urgent' => 'bg-danger-100 text-danger-600 dark:bg-danger-600/20 dark:text-danger-400',
        'high'   => 'bg-warning-100 text-warning-600 dark:bg-warning-600/20 dark:text-warning-400',
        'normal' => 'bg-neutral-100 text-neutral-600 dark:bg-neutral-600/20 dark:text-neutral-300',
        'low'    => 'bg-neutral-100 text-neutral-500 dark:bg-neutral-700/40 dark:text-neutral-400',
        default  => 'bg-neutral-100 text-neutral-600',
    };
}
function tvFmtDate(?string $dt): string
{
    if (!$dt) return 'N/A';
    $ts = strtotime($dt);
    return $ts === false ? 'N/A' : date('d M Y, H:i', $ts);
}
function tvBytes(int $b): string
{
    if ($b < 1024) return $b . ' B';
    if ($b < 1024 * 1024) return round($b / 1024, 1) . ' KB';
    return round($b / 1024 / 1024, 2) . ' MB';
}
function tvCustomerSiteUrl(array $row): string
{
    $d = trim((string)($row['domain'] ?? ''));
    if ($d === '') $d = trim((string)($row['shop_domain'] ?? ''));
    if ($d === '') return '';
    if (!preg_match('#^https?://#i', $d)) $d = 'https://' . $d;
    return $d;
}

$shopName     = trim((string)($ticket['shop_name'] ?? '')) ?: '—';
$customerMail = trim((string)($ticket['customer_email'] ?? '')) ?: '—';
$siteUrl      = tvCustomerSiteUrl($ticket);
$loginIntoUrl = APP_BASE . '/dashboard.php?instanceid=' . urlencode($instanceId);

include './partials/layouts/layoutTop.php';
?>

<style>
/* The compiled Tailwind build does not carry every utility this page
   names; these three cover the ones with visible effect. tv-stack
   spaces the stacked cards, tv-wrap keeps the line breaks in ticket
   messages, and the side column keeps its own gap on small screens. */
.tv-stack > * + * { margin-top: 1.5rem; }
.tv-wrap { white-space: pre-wrap; overflow-wrap: break-word; word-wrap: break-word; }
</style>

<div class="grid grid-cols-1 xl:grid-cols-12 gap-6">

    <!-- LEFT: Thread + reply -->
    <div class="xl:col-span-8 tv-stack">

        <!-- Header card -->
        <div class="card rounded-xl border-0 overflow-hidden">
            <div class="card-body p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-sm text-secondary-light mb-1">
                            <?= htmlspecialchars((string)$ticket['ticket_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </div>
                        <h4 class="text-xl font-semibold text-neutral-900 dark:text-white mb-2">
                            <?= htmlspecialchars((string)$ticket['subject'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </h4>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?= tvStatusBadge((string)$ticket['status']) ?>">
                                <?= htmlspecialchars(ucfirst((string)$ticket['status']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </span>
                            <span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?= tvPriorityBadge((string)$ticket['priority']) ?>">
                                Priority: <?= htmlspecialchars(ucfirst((string)$ticket['priority']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </span>
                            <span class="text-xs text-secondary-light">
                                Created <?= htmlspecialchars(tvFmtDate((string)$ticket['created_at']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>
                    <a href="tickets.php" class="btn btn-cstm-muted inline-flex items-center gap-2">
                        <iconify-icon icon="solar:arrow-left-linear" class="icon text-lg"></iconify-icon>
                        Back
                    </a>
                </div>

                <?php if ($flash): ?>
                    <div class="mt-4 rounded-lg p-3 text-sm <?= $flash['type'] === 'success'
                        ? 'bg-success-100 text-success-600 dark:bg-success-600/20 dark:text-success-400'
                        : 'bg-danger-100 text-danger-600 dark:bg-danger-600/20 dark:text-danger-400' ?>">
                        <?= htmlspecialchars((string)$flash['msg'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Thread -->
        <div class="card rounded-xl border-0 overflow-hidden">
            <div class="card-body p-6">
                <h6 class="font-semibold text-lg mb-4">Conversation</h6>

                <?php if (empty($messages)): ?>
                    <div class="text-sm text-neutral-500 dark:text-neutral-400 py-6 text-center">
                        No messages on this ticket yet.
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($messages as $m):
                            $isInternal = !empty($m['is_internal']);
                            $msgClasses = $isInternal
                                ? 'border-l-4 border-warning-500 bg-warning-100/40 dark:bg-warning-600/10'
                                : 'border border-neutral-200 dark:border-neutral-600 bg-white dark:bg-neutral-800';
                        ?>
                            <div class="rounded-lg p-4 <?= $msgClasses ?>">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center gap-2 text-xs">
                                        <?php if ($isInternal): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-warning-200 text-warning-600 dark:bg-warning-600/30 dark:text-warning-300 font-medium">
                                                <iconify-icon icon="solar:lock-keyhole-bold-duotone"></iconify-icon>
                                                Internal note
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-neutral-100 text-neutral-600 dark:bg-neutral-700/40 dark:text-neutral-300 font-medium">
                                                Message
                                            </span>
                                        <?php endif; ?>
                                        <span class="text-secondary-light">
                                            <?= htmlspecialchars(tvFmtDate((string)$m['created_at']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="text-sm text-neutral-700 dark:text-neutral-200 tv-wrap">
                                    <?= htmlspecialchars((string)$m['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </div>

                                <?php if (!empty($m['attachments'])): ?>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <?php foreach ($m['attachments'] as $a): ?>
                                            <a href="<?= htmlspecialchars((string)$a['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                               target="_blank" rel="noopener"
                                               class="inline-flex items-center gap-2 text-xs px-3 py-1.5 rounded-lg bg-neutral-100 hover:bg-neutral-200 dark:bg-neutral-700 dark:hover:bg-neutral-600 text-neutral-700 dark:text-neutral-200">
                                                <iconify-icon icon="solar:paperclip-bold"></iconify-icon>
                                                <?= htmlspecialchars((string)$a['original_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                <span class="text-secondary-light">(<?= tvBytes((int)$a['size_bytes']) ?>)</span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reply form -->
        <div class="card rounded-xl border-0 overflow-hidden">
            <div class="card-body p-6">
                <h6 class="font-semibold text-lg mb-4">Reply</h6>
                <form method="post" action="ticket-view.php?id=<?= $ticketId ?>" class="space-y-4">
                    <input type="hidden" name="action" value="reply">
                    <textarea name="message" rows="5" required
                              class="form-control w-full rounded-lg dark:bg-neutral-700 dark:text-white"
                              placeholder="Type your response to the customer…"></textarea>

                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_internal" value="1" class="form-check-input">
                        <span>Internal note (not visible to customer)</span>
                    </label>

                    <div>
                        <button type="submit" class="btn btn-cstm-primary inline-flex items-center gap-2">
                            <iconify-icon icon="solar:plain-bold" class="text-lg"></iconify-icon>
                            Send Reply
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Status history -->
        <div class="card rounded-xl border-0 overflow-hidden">
            <div class="card-body p-6">
                <h6 class="font-semibold text-lg mb-4">Activity / Status History</h6>

                <?php if (empty($history)): ?>
                    <div class="text-sm text-neutral-500 dark:text-neutral-400 py-4 text-center">
                        No activity recorded yet.
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="table bordered-table sm-table table-auto text-sm">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Who</th>
                                    <th>Change</th>
                                    <th>Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $h):
                                    $from = (string)($h['from_status'] ?? '');
                                    $to   = (string)($h['to_status']   ?? '');
                                    $change = ($from !== '' || $to !== '')
                                        ? trim($from) . ' → ' . trim($to)
                                        : '—';
                                ?>
                                    <tr>
                                        <td class="whitespace-nowrap">
                                            <?= htmlspecialchars(tvFmtDate((string)$h['created_at']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        </td>
                                        <td><?= htmlspecialchars((string)($h['changed_by'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($change, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string)($h['note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT: Customer + controls -->
    <div class="xl:col-span-4 tv-stack">

        <!-- Customer card -->
        <div class="card rounded-xl border-0 overflow-hidden">
            <div class="card-body p-6">
                <h6 class="font-semibold text-lg mb-4">Customer</h6>

                <ul class="space-y-3 text-sm">
                    <li class="flex flex-col">
                        <span class="text-secondary-light text-xs uppercase tracking-wide">Shop Name</span>
                        <span class="font-medium"><?= htmlspecialchars($shopName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    </li>
                    <li class="flex flex-col">
                        <span class="text-secondary-light text-xs uppercase tracking-wide">Email</span>
                        <span class="font-medium break-all"><?= htmlspecialchars($customerMail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    </li>
                    <li class="flex flex-col">
                        <span class="text-secondary-light text-xs uppercase tracking-wide">Site URL</span>
                        <?php if ($siteUrl !== ''): ?>
                            <a href="<?= htmlspecialchars($siteUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                               target="_blank" rel="noopener"
                               class="text-primary-500 hover:text-primary-600 break-all">
                                <?= htmlspecialchars($siteUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </a>
                        <?php else: ?>
                            <span class="text-neutral-400">—</span>
                        <?php endif; ?>
                    </li>
                    <li class="flex flex-col">
                        <span class="text-secondary-light text-xs uppercase tracking-wide">Instance ID</span>
                        <span class="font-mono text-xs break-all"><?= htmlspecialchars($instanceId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    </li>
                </ul>

                <div class="mt-5 grid grid-cols-1 gap-2">
                    <a href="<?= htmlspecialchars($loginIntoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                       target="_blank" rel="noopener"
                       class="btn btn-cstm-primary inline-flex items-center justify-center gap-2">
                        <iconify-icon icon="solar:login-2-bold" class="text-lg"></iconify-icon>
                        Login Into Store
                    </a>
                    <a href="store-view.php?instance_id=<?= urlencode($instanceId) ?>"
                       class="btn btn-cstm-muted inline-flex items-center justify-center gap-2">
                        <iconify-icon icon="solar:eye-bold" class="text-lg"></iconify-icon>
                        View Store Profile
                    </a>
                </div>
            </div>
        </div>

        <!-- Status form -->
        <div class="card rounded-xl border-0 overflow-hidden">
            <div class="card-body p-6">
                <h6 class="font-semibold text-lg mb-4">Status</h6>
                <form method="post" action="ticket-view.php?id=<?= $ticketId ?>" class="space-y-3">
                    <input type="hidden" name="action" value="update_status">
                    <select name="status" class="form-select w-full rounded-lg dark:bg-neutral-700 dark:text-white">
                        <?php foreach (TicketManager::allowedStatuses() as $s): ?>
                            <option value="<?= $s ?>" <?= ((string)$ticket['status'] === $s) ? 'selected' : '' ?>>
                                <?= ucfirst($s) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="note"
                           class="form-control w-full rounded-lg dark:bg-neutral-700 dark:text-white"
                           placeholder="Optional note (audit trail)">
                    <button type="submit" class="btn btn-cstm-primary w-full">Update Status</button>
                </form>
            </div>
        </div>

        <!-- Priority form -->
        <div class="card rounded-xl border-0 overflow-hidden">
            <div class="card-body p-6">
                <h6 class="font-semibold text-lg mb-4">Priority</h6>
                <form method="post" action="ticket-view.php?id=<?= $ticketId ?>" class="space-y-3">
                    <input type="hidden" name="action" value="update_priority">
                    <select name="priority" class="form-select w-full rounded-lg dark:bg-neutral-700 dark:text-white">
                        <?php foreach (TicketManager::allowedPriorities() as $p): ?>
                            <option value="<?= $p ?>" <?= ((string)$ticket['priority'] === $p) ? 'selected' : '' ?>>
                                <?= ucfirst($p) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-cstm-primary w-full">Update Priority</button>
                </form>
            </div>
        </div>

    </div>
</div>

<?php include './partials/layouts/layoutBottom.php'; ?>
