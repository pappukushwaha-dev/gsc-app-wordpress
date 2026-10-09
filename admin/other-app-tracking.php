<?php
// admin/other-app-tracking.php
declare(strict_types=1);

$title    = 'App Clicks';
$subTitle = 'Which users open which of our other apps from this one.';

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

/* How many rows the list shows. The page is for checking what is
   happening, not for export, and the newest are the ones that
   matter. */
const PER_PAGE = 50;

/* ============================================================
   FILTERS
========================================================== */
$appFilter = isset($_GET['app_id']) ? max(0, (int)$_GET['app_id']) : 0;
$q         = trim((string)($_GET['q'] ?? ''));
$page      = max(1, (int)($_GET['page'] ?? 1));
$offset    = ($page - 1) * PER_PAGE;

/* ============================================================
   THE APP DROPDOWN
========================================================== */
$apps = [];
try {
    $apps = $pdo->query("SELECT id, title FROM other_apps ORDER BY title ASC")
                ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $apps = [];
}

/* ============================================================
   THE LIST
========================================================== */
$where  = [];
$params = [];

if ($appFilter > 0) {
    $where[] = "other_app_id = :app_id";
    $params[':app_id'] = $appFilter;
}
if ($q !== '') {
    /* Two separate placeholders: the app runs with native prepares,
       where a named parameter may appear only once per statement. */
    $where[] = "(email LIKE :q_email OR instance_id LIKE :q_inst)";
    $params[':q_email'] = '%' . $q . '%';
    $params[':q_inst']  = '%' . $q . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = 0;
$rows  = [];
$loadErr = null;

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM other_app_clicks {$whereSql}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listStmt = $pdo->prepare("
        SELECT id, instance_id, email, other_app_id, app_name,
               source_platform, clicked_at
        FROM other_app_clicks
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
   SUMMARY
========================================================== */
$sumTotal = 0;
$sumUsers = 0;
$sumTop   = '';

try {
    $sumTotal = (int)$pdo->query("SELECT COUNT(*) FROM other_app_clicks")->fetchColumn();
    $sumUsers = (int)$pdo->query("SELECT COUNT(DISTINCT instance_id) FROM other_app_clicks")->fetchColumn();
    $top = $pdo->query("
        SELECT app_name, COUNT(*) AS n
        FROM other_app_clicks
        GROUP BY app_name
        ORDER BY n DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    $sumTop = $top ? ($top['app_name'] . ' (' . $top['n'] . ')') : '-';
} catch (Throwable $e) {
    // the table may not exist yet; the cards show zeros
}

$qs = function (array $over = []) use ($appFilter, $q): string {
    $p = array_filter(['app_id' => $appFilter ?: null, 'q' => $q !== '' ? $q : null]) + $over;
    return '?' . http_build_query($p);
};
?>
<?php include './partials/layouts/layoutTop.php'; ?>

<style>
/* Stat cards: sized here rather than through utility classes, so the
   page does not depend on shades the compiled build may not carry. */
.at-cards { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.at-card  { flex: 1 1 200px; background: #fff; border-radius: .75rem; padding: 1.25rem 1.5rem; box-shadow: 0 1px 2px rgb(16 24 40 / .06); }
.dark .at-card { background: #1f2937; }
.at-card .at-label { font-size: .75rem; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
.dark .at-card .at-label { color: #9ca3af; }
.at-card .at-value { font-size: 1.5rem; font-weight: 700; margin-top: .25rem; overflow-wrap: anywhere; }
.at-toolbar { display: flex; gap: .75rem; flex-wrap: wrap; align-items: center; }
.at-toolbar select, .at-toolbar input { padding: .5rem .75rem; border: 1px solid #d1d5db; border-radius: .5rem; background: #fff; }
.dark .at-toolbar select, .dark .at-toolbar input { background: #374151; border-color: #4b5563; color: #fff; }
.at-toolbar button { padding: .5rem 1rem; border: 0; border-radius: .5rem; background: #0ea5a4; color: #fff; font-weight: 600; cursor: pointer; }
.at-pager a { display: inline-block; padding: .375rem .75rem; border: 1px solid #d1d5db; border-radius: .5rem; text-decoration: none; }
.dark .at-pager a { border-color: #4b5563; }
.at-pager .cur { background: #0ea5a4; color: #fff; border-color: #0ea5a4; }
</style>

<div class="grid grid-cols-12">
    <div class="col-span-12">

        <?php if ($loadErr): ?>
            <div class="card p-6 rounded-xl">
                <p class="text-danger-600">Could not read the tracking table. Has 01-wp-sites.sql (WpSite) and 01-wix-other-app-clicks.sql (other_app_clicks) been run on this database?</p>
            </div>
        <?php else: ?>

        <div class="at-cards">
            <div class="at-card"><div class="at-label">Total Clicks</div><div class="at-value"><?= number_format($sumTotal) ?></div></div>
            <div class="at-card"><div class="at-label">Unique Users</div><div class="at-value"><?= number_format($sumUsers) ?></div></div>
            <div class="at-card"><div class="at-label">Most Clicked</div><div class="at-value" style="font-size:1.1rem;"><?= h($sumTop) ?></div></div>
        </div>

        <div class="card h-full p-0 rounded-xl border-0 overflow-hidden">
            <div class="card-header border-b border-neutral-200 dark:border-neutral-600 bg-white dark:bg-neutral-700 py-4 px-6">
                <form method="get" class="at-toolbar">
                    <select name="app_id">
                        <option value="0">All apps</option>
                        <?php foreach ($apps as $a): ?>
                            <option value="<?= (int)$a['id'] ?>" <?= ($appFilter === (int)$a['id']) ? 'selected' : '' ?>>
                                <?= h($a['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search email or instance id">
                    <button type="submit">Apply</button>
                </form>
            </div>

            <div class="card-body p-6">
                <div class="overflow-x-auto">
                    <table class="table bordered-table sm-table table-auto text-sm w-full">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Email</th>
                                <th>Instance ID</th>
                                <th>App</th>
                                <th>Platform</th>
                                <th>Clicked At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                                <tr><td colspan="6" class="text-center py-4 text-neutral-500">No clicks recorded yet.</td></tr>
                            <?php else: foreach ($rows as $i => $r): ?>
                                <tr>
                                    <td><?= $offset + $i + 1 ?></td>
                                    <td><?= h($r['email'] !== '' ? $r['email'] : '-') ?></td>
                                    <td><?= h($r['instance_id']) ?></td>
                                    <td><?= h($r['app_name'] !== '' ? $r['app_name'] : ('#' . (int)$r['other_app_id'])) ?></td>
                                    <td><?= h($r['source_platform']) ?></td>
                                    <td><?= h(date('j M Y, H:i', strtotime((string)$r['clicked_at']))) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="at-pager" style="margin-top:1.25rem;display:flex;gap:.5rem;flex-wrap:wrap;">
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
                    Showing <?= $total === 0 ? 0 : $offset + 1 ?> to <?= min($offset + PER_PAGE, $total) ?> of <?= number_format($total) ?> clicks.
                </p>
            </div>
        </div>

        <?php endif; ?>

    </div>
</div>

<?php include './partials/layouts/layoutBottom.php'; ?>
