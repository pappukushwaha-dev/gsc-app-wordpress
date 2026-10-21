<?php
// admin/activity-logs.php
declare(strict_types=1);

$title    = 'Activity Logs';
$subTitle = 'When users log in and which pages they open.';

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';

SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

const PER_PAGE = 50;

/* ============================================================
   FILTERS
========================================================== */
$actionFilter = trim((string)($_GET['action'] ?? ''));
$q            = trim((string)($_GET['q'] ?? ''));
$page         = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($page - 1) * PER_PAGE;

$actions = [
    'login'      => 'Login',
    'logout'     => 'Logout',
    'disconnect' => 'Disconnect',
    'page_view'  => 'Page View',
];

/* ============================================================
   THE LIST
========================================================== */
$where  = [];
$params = [];

if (isset($actions[$actionFilter])) {
    $where[] = "action_type = :action";
    $params[':action'] = $actionFilter;
}
if ($q !== '') {
    /* Two separate placeholders: the app runs with native prepares,
       where a named parameter may appear only once per statement. */
    $where[] = "(email LIKE :q_email OR instance_id LIKE :q_inst OR page_url LIKE :q_page)";
    $params[':q_email'] = '%' . $q . '%';
    $params[':q_inst']  = '%' . $q . '%';
    $params[':q_page']  = '%' . $q . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total  = 0;
$rows   = [];
$loadErr = null;

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM user_activity_logs {$whereSql}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listStmt = $pdo->prepare("
        SELECT id, instance_id, email, action_type, page_url, ip_address, created_at
        FROM user_activity_logs
        {$whereSql}
        ORDER BY id DESC
        LIMIT " . PER_PAGE . " OFFSET {$offset}
    ");
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $loadErr = $e->getMessage();
}

$totalPages = max(1, (int)ceil($total / PER_PAGE));

/* ============================================================
   SUMMARY (last 30 days)
========================================================== */
$sumTotal = 0;
$sumLogins = 0;
$sumViews = 0;
$sumStores = 0;

try {
    $sumTotal  = (int)$pdo->query("SELECT COUNT(*) FROM user_activity_logs")->fetchColumn();
    $sumLogins = (int)$pdo->query("SELECT COUNT(*) FROM user_activity_logs WHERE action_type = 'login' AND created_at >= (NOW() - INTERVAL 30 DAY)")->fetchColumn();
    $sumViews  = (int)$pdo->query("SELECT COUNT(*) FROM user_activity_logs WHERE action_type = 'page_view' AND created_at >= (NOW() - INTERVAL 30 DAY)")->fetchColumn();
    $sumStores = (int)$pdo->query("SELECT COUNT(DISTINCT instance_id) FROM user_activity_logs WHERE created_at >= (NOW() - INTERVAL 30 DAY)")->fetchColumn();
} catch (Throwable $e) {
    // the table may not exist yet; the cards show zeros
}

$qs = function (array $over = []) use ($actionFilter, $q): string {
    $p = array_filter(['action' => $actionFilter !== '' ? $actionFilter : null, 'q' => $q !== '' ? $q : null]) + $over;
    return '?' . http_build_query($p);
};

$badgeFor = function (string $t): string {
    return match ($t) {
        'login'      => '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;background:#dcfce7;color:#15803d;">Login</span>',
        'logout'     => '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;background:#f1f5f9;color:#475569;">Logout</span>',
        'disconnect' => '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;background:#fee2e2;color:#b91c1c;">Disconnect</span>',
        default      => '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;background:#e0e7ff;color:#4338ca;">Page View</span>',
    };
};
?>
<?php include './partials/layouts/layoutTop.php'; ?>

<style>
.as-cards { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.as-card  { flex: 1 1 200px; background: #fff; border-radius: .75rem; padding: 1.25rem 1.5rem; box-shadow: 0 1px 2px rgb(16 24 40 / .06); }
.dark .as-card { background: #1f2937; }
.as-card .as-label { font-size: .75rem; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
.dark .as-card .as-label { color: #9ca3af; }
.as-card .as-value { font-size: 1.5rem; font-weight: 700; margin-top: .25rem; overflow-wrap: anywhere; }
.as-toolbar { display: flex; gap: .75rem; flex-wrap: wrap; align-items: center; }
.as-toolbar select, .as-toolbar input { padding: .5rem .75rem; border: 1px solid #d1d5db; border-radius: .5rem; background: #fff; }
.dark .as-toolbar select, .dark .as-toolbar input { background: #374151; border-color: #4b5563; color: #fff; }
.as-toolbar button { padding: .5rem 1rem; border: 0; border-radius: .5rem; background: #0ea5a4; color: #fff; font-weight: 600; cursor: pointer; }
.as-pager a { display: inline-block; padding: .375rem .75rem; border: 1px solid #d1d5db; border-radius: .5rem; text-decoration: none; }
.dark .as-pager a { border-color: #4b5563; }
.as-pager .cur { background: #0ea5a4; color: #fff; border-color: #0ea5a4; }
.as-page { max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: bottom; }
</style>

<div class="grid grid-cols-12">
    <div class="col-span-12">

        <?php if ($loadErr): ?>
            <div class="card p-6 rounded-xl">
                <p class="text-danger-600">Could not read the activity table. Has 01-user-activity-logs.sql been run on this database?</p>
            </div>
        <?php else: ?>

        <div class="as-cards">
            <div class="as-card"><div class="as-label">Total Events</div><div class="as-value"><?= number_format($sumTotal) ?></div></div>
            <div class="as-card"><div class="as-label">Logins (30 days)</div><div class="as-value"><?= number_format($sumLogins) ?></div></div>
            <div class="as-card"><div class="as-label">Page Views (30 days)</div><div class="as-value"><?= number_format($sumViews) ?></div></div>
            <div class="as-card"><div class="as-label">Active Users (30 days)</div><div class="as-value"><?= number_format($sumStores) ?></div></div>
        </div>

        <div class="card h-full p-0 rounded-xl border-0 overflow-hidden">
            <div class="card-header border-b border-neutral-200 dark:border-neutral-600 bg-white dark:bg-neutral-700 py-4 px-6">
                <form method="get" class="as-toolbar">
                    <select name="action">
                        <option value="">All activity</option>
                        <?php foreach ($actions as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= ($actionFilter === $key) ? 'selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search email, instance id or page">
                    <button type="submit">Apply</button>
                </form>
            </div>

            <div class="card-body p-6">
                <div class="overflow-x-auto">
                    <table class="table bordered-table sm-table table-auto text-sm w-full">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Time</th>
                                <th>Action</th>
                                <th>Email</th>
                                <th>Instance ID</th>
                                <th>Page</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                                <tr><td colspan="7" class="text-center py-4 text-neutral-500">No activity recorded yet.</td></tr>
                            <?php else: foreach ($rows as $i => $r): ?>
                                <tr>
                                    <td><?= $offset + $i + 1 ?></td>
                                    <td><?= h(date('j M Y, H:i', strtotime((string)$r['created_at']))) ?></td>
                                    <td><?= $badgeFor((string)$r['action_type']) ?></td>
                                    <td><?= h($r['email'] !== '' ? $r['email'] : '-') ?></td>
                                    <td><?= h($r['instance_id']) ?></td>
                                    <td>
                                        <?php if ($r['page_url'] !== ''): ?>
                                            <span class="as-page" title="<?= h($r['page_url']) ?>"><?= h($r['page_url']) ?></span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($r['ip_address'] !== '' ? $r['ip_address'] : '-') ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="as-pager" style="margin-top:1.25rem;display:flex;gap:.5rem;flex-wrap:wrap;">
                        <?php
                        $from = max(1, $page - 2);
                        $to   = min($totalPages, $page + 2);
                        if ($page > 1) echo '<a href="' . h($qs(['page' => $page - 1])) . '">&laquo; Prev</a>';
                        for ($p = $from; $p <= $to; $p++) {
                            echo $p === $page
                                ? '<span class="cur">' . $p . '</span>'
                                : '<a href="' . h($qs(['page' => $p])) . '">' . $p . '</a>';
                        }
                        if ($page < $totalPages) echo '<a href="' . h($qs(['page' => $page + 1])) . '">Next &raquo;</a>';
                        ?>
                    </div>
                <?php endif; ?>

                <p class="text-xs text-secondary-light" style="margin-top:1rem;">
                    Showing <?= $total === 0 ? 0 : $offset + 1 ?> to <?= min($offset + PER_PAGE, $total) ?> of <?= number_format($total) ?> events.
                </p>
            </div>
        </div>

        <?php endif; ?>

    </div>
</div>

<?php include './partials/layouts/layoutBottom.php'; ?>
