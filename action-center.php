<?php
/**
 * action-center.php  (WordPress)
 *
 * Full Action Center page. Sits in the project root next to index.php.
 * Reads only from api/insights/index.php (your own DB - no Google calls).
 *
 * STYLING
 * This project ships a precompiled Tailwind build, so arbitrary utility
 * classes do not exist in the CSS. All sizing lives in the scoped
 * .acp-* stylesheet below.
 *
 * DUPLICATE HEADING
 * layoutTop.php renders its own page heading from $title / $subTitle.
 * This page draws its own header card, so if you see "Action Center"
 * twice, blank the two variables below.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/plan_guard.php';

$planAccess       = getPlanAccessForPage();
$hasPremiumAccess = $planAccess['allowed'] ?? false;

// Both spellings, read and written. auth/callback.php writes 'instance_id'
// and pricing.php writes 'instanceid'; reading only one drops users at
// sign-in in the middle of a working session.
$instanceId = $_SESSION['instanceid']
    ?? $_SESSION['instance_id']
    ?? $_GET['instanceid']
    ?? $_GET['instance_id']
    ?? null;

if (!$instanceId) {
    header('Location: sign-in.php');
    exit;
}

$instanceId = trim((string)$instanceId);
$_SESSION['instanceid']  = $instanceId;
$_SESSION['instance_id'] = $instanceId;

/* ---- Site health: cached speed score + structured-data gaps ----
   Computed in includes/site_health.php so the navbar bell shows exactly
   the same figures. Cached data only - no API calls on page load. */
require_once __DIR__ . '/includes/site_health.php';

$gscHealth       = gsc_site_health($pdo, (string)$instanceId);
$rowSpeedScore   = $gscHealth['speed'];
$siteSchema      = $gscHealth['schema'];
$WS_APP_LINK     = $gscHealth['links']['speed'];    // from the other_apps table
$SCHEMA_APP_LINK = $gscHealth['links']['schema'];

// Blank these two if layoutTop.php duplicates the heading:
$title    = 'Action Center';
$subTitle = 'Insights';

$focusId = isset($_GET['focus']) ? (int)$_GET['focus'] : 0;

// APP_BASE is what every other page here uses, so it comes first. The
// dirname() fallback stays as a last resort only.
if (defined('APP_BASE') && trim((string)APP_BASE) !== '') {
    $acBase = '/' . trim((string)APP_BASE, '/');
} else {
    $acBase = rtrim((string)($_ENV['BASE_URL'] ?? getenv('BASE_URL') ?: ''), '/');
    if ($acBase === '') {
        $acBase = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    }
}

$acApiUrl = $acBase . '/api/insights/index.php';


/* ==========================================================
   WHY THE PAGE MIGHT BE EMPTY

   Most accounts have no insights, and they are not all empty
   for the same reason. Until now every one of them was told
   "Nothing needs attention" - which is true for a healthy site
   and a lie for a site we cannot see at all. The difference
   matters: one of these people has nothing to do, the others
   have something specific to fix.

   The reason is worked out here, on the server, because it
   needs three queries the feed endpoint does not make. The JS
   only uses it when the unfiltered list comes back empty.
========================================================== */

// These mirror hasEnoughData() in cron/insight_engine.php. If they change
// there, change them here - otherwise this page promises insights the
// engine has already decided not to generate.
if (!defined('ENGINE_MIN_IMPRESSIONS')) define('ENGINE_MIN_IMPRESSIONS', 200);
if (!defined('ENGINE_MIN_DAYS'))        define('ENGINE_MIN_DAYS', 10);

$acEmpty = [
    'reason' => 'all_clear',
    'impr30' => 0,
    'days30' => 0,
    'site'   => '',
    'minImpr'=> ENGINE_MIN_IMPRESSIONS,
    'minDays'=> ENGINE_MIN_DAYS,
];

try {
    $s = $pdo->prepare("SELECT verification_status, site_url
                        FROM gsc_domain_verifications
                        WHERE instance_id = ? LIMIT 1");
    $s->execute([$instanceId]);
    $dv = $s->fetch(PDO::FETCH_ASSOC) ?: [];

    $acEmpty['site'] = (string)($dv['site_url'] ?? '');
    $verified = (($dv['verification_status'] ?? '') === 'verified');

    $s = $pdo->prepare("SELECT COALESCE(SUM(impressions),0), COUNT(DISTINCT date)
                        FROM gsc_query_daily
                        WHERE instance_id = ?
                          AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $s->execute([$instanceId]);
    [$i30, $d30] = $s->fetch(PDO::FETCH_NUM) ?: [0, 0];
    $acEmpty['impr30'] = (int)$i30;
    $acEmpty['days30'] = (int)$d30;

    // gsc_backfill_log records why a backfill produced nothing. 'no_account'
    // means Google refused this account access to the property - the one
    // case the user can actually fix, and the one they were never told about.
    $bf = null;
    try {
        $s = $pdo->prepare("SELECT status FROM gsc_backfill_log WHERE instance_id = ? LIMIT 1");
        $s->execute([$instanceId]);
        $bf = $s->fetchColumn() ?: null;
    } catch (Throwable $e) {
        // table absent on this install - fall through to the volume checks
    }

    if (!$verified) {
        $acEmpty['reason'] = 'not_verified';
    } elseif ($bf === 'no_account') {
        $acEmpty['reason'] = 'no_permission';
    } elseif ($acEmpty['impr30'] === 0) {
        $acEmpty['reason'] = 'no_traffic';
    } elseif ($acEmpty['impr30'] < ENGINE_MIN_IMPRESSIONS
           || $acEmpty['days30'] < ENGINE_MIN_DAYS) {
        $acEmpty['reason'] = 'too_little_traffic';
    }
} catch (Throwable $e) {
    // Never break the page over the empty state. 'all_clear' is the same
    // thing this page said before, so a failure here is no worse than the
    // behaviour it replaces.
    error_log('action-center empty-state check: ' . $e->getMessage());
}

include './partials/layouts/layoutTop.php';
?>

<style>
/* ==================== Action Center - scoped to .acp-* ==================== */

.acp-shell { display: flex; flex-direction: column; gap: 14px; }

/* ---------------- header card ---------------- */
.acp-head {
    background: #fff; border: 1px solid #e9edf3; border-radius: 14px;
    padding: 16px 20px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 20px; flex-wrap: wrap;
}
.dark .acp-head { background: #262626; border-color: #404040; }

.acp-head-l { display: flex; align-items: center; gap: 13px; min-width: 0; }
.acp-head-ic {
    width: 42px; height: 42px; border-radius: 12px; flex: none;
    background: #eaf0ff; display: flex; align-items: center; justify-content: center;
}
.dark .acp-head-ic { background: #33406b; }
.acp-head h2 {
    margin: 0; font-size: 19px; font-weight: 700; letter-spacing: -.02em; color: #101828;
}
.dark .acp-head h2 { color: #fff; }
.acp-head .sub { margin: 2px 0 0; font-size: 12.5px; color: #98a2b3; }

.acp-head-r { display: flex; flex-direction: column; align-items: flex-end; gap: 9px; }
.acp-crumb { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #98a2b3; }
.acp-crumb a { color: var(--clr-primary, #487fff); text-decoration: none; }
.acp-crumb a:hover { text-decoration: underline; }
.acp-head-btns { display: flex; gap: 8px; }

/* ---------------- buttons ---------------- */
.acp-btn {
    height: 36px; padding: 0 14px; border-radius: 9px; cursor: pointer;
    font-size: 13px; font-weight: 500; line-height: 1; white-space: nowrap;
    display: inline-flex; align-items: center; justify-content: center; gap: 7px;
    border: 1px solid #e4e7ec; background: #fff; color: #475467;
    transition: border-color .12s, background .12s, opacity .12s;
}
.acp-btn:hover { border-color: #b9c0cc; background: #f9fafb; }
.acp-btn:disabled { opacity: .45; cursor: default; }
.dark .acp-btn { background: #262626; border-color: #404040; color: #d4d4d4; }

.acp-btn-primary {
    background: var(--clr-primary, #487fff);
    border-color: var(--clr-primary, #487fff); color: #fff;
}
.acp-btn-primary:hover {
    background: var(--clr-primary, #487fff);
    border-color: var(--clr-primary, #487fff); opacity: .92;
}
.acp-btn-sm { height: 31px; padding: 0 11px; font-size: 12px; border-radius: 8px; }
.acp-btn-block { width: 100%; }

/* ---------------- KPI tiles ---------------- */
.acp-kpis { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; }

.acp-kpi {
    background: #fff; border: 1px solid #e9edf3; border-radius: 14px;
    padding: 14px 16px 0; overflow: hidden; display: flex; flex-direction: column;
}
.dark .acp-kpi { background: #262626; border-color: #404040; }

.acp-kpi .k {
    margin: 0 0 7px; font-size: 9.5px; letter-spacing: .07em; text-transform: uppercase;
    color: #98a2b3; display: flex; align-items: center; gap: 5px; white-space: nowrap;
}
.acp-kpi .v {
    margin: 0; font-size: 27px; font-weight: 800; letter-spacing: -.04em; line-height: 1;
    display: inline-flex; align-items: center; gap: 5px;
}
.acp-kpi .v iconify-icon { font-size: 18px; line-height: 1; }
.acp-kpi .n { margin: 6px 0 10px; font-size: 11.5px; color: #98a2b3; }
.acp-kpi .spark {
    display: block; width: calc(100% + 32px);
    margin: auto -16px -1px; height: 40px;
}

.acp-info {
    width: 13px; height: 13px; border-radius: 50%; flex: none;
    border: 1px solid #d0d5dd; color: #98a2b3;
    font-size: 8.5px; font-weight: 700; line-height: 11px; text-align: center;
    cursor: help; text-transform: none; letter-spacing: 0;
}

@media (max-width: 1240px) { .acp-kpis { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 800px)  { .acp-kpis { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 440px)  { .acp-kpis { grid-template-columns: 1fr; } }

/* ---------------- filters ---------------- */
.acp-filters {
    background: #fff; border: 1px solid #e9edf3; border-radius: 14px;
    padding: 13px 18px;
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
}
.dark .acp-filters { background: #262626; border-color: #404040; }

.acp-fgroup { display: flex; align-items: center; gap: 8px; }
.acp-fgroup label { font-size: 12.5px; color: #98a2b3; font-weight: 500; }

.acp-select {
    height: 36px; padding: 0 32px 0 13px; border-radius: 9px;
    border: 1px solid #e4e7ec; background-color: #fff; color: #344054;
    font-size: 13px; font-weight: 500; font-family: inherit; cursor: pointer; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2398a2b3' stroke-width='2.5'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 11px center;
    transition: border-color .12s;
}
.acp-select:hover { border-color: #b9c0cc; }
.acp-select:focus { outline: none; border-color: var(--clr-primary, #487fff); box-shadow: 0 0 0 3px rgba(72,127,255,.12); }
.dark .acp-select { background-color: #262626; border-color: #404040; color: #d4d4d4; }

/* ---------------- insight cards ---------------- */
.acp-list {
    display: flex; flex-direction: column; gap: 12px;
}

.acp-row {
    display: flex; align-items: center; gap: 16px; padding: 18px 20px;
    background: #fff; border: 1px solid #e9edf3; border-radius: 13px;
    position: relative; transition: box-shadow .15s, transform .15s, border-color .15s;
    box-shadow: 0 1px 2px rgba(16,24,40,.04);
}
.dark .acp-row { background: #262626; border-color: #404040; }
.acp-row:hover {
    box-shadow: 0 4px 14px rgba(16,24,40,.07); transform: translateY(-1px); border-color: #dde1e8;
}
.dark .acp-row:hover { border-color: #4a4a4a; }
.acp-row.is-closed { opacity: .62; }

.acp-flash { animation: acpFlash 1.8s ease-out; }
@keyframes acpFlash {
    0%   { background: rgba(59,110,245,.10); }
    100% { background: transparent; }
}

/* left: icon + severity/type label */
.acp-lead { width: 78px; flex: none; display: flex; flex-direction: column; align-items: center; gap: 8px; }
.acp-lead-ic {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
}
.acp-lead-lbl { text-align: center; line-height: 1.3; width: 100%; }
.acp-lead-lbl .lv { display: block; font-size: 9px; font-weight: 700; letter-spacing: .04em; color: #6b7280; }
.acp-lead-lbl .ty { display: block; font-size: 9px; font-weight: 600; color: #9ca3af; margin-top: 1px; }
.dark .acp-lead-lbl .lv { color: #d4d4d4; }
.dark .acp-lead-lbl .ty { color: #a3a3a3; }

/* main */
/* main = centre column (title, url, metrics only now) */
/* body = two stacked rows, each split left | right */
.acp-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 14px; }

.acp-r1 { display: flex; align-items: flex-start; gap: 20px; }
.acp-r2 { display: flex; align-items: center; justify-content: space-between; gap: 20px; }

/* row 1 left: title + url */
.acp-headcol { flex: 0 0 300px; min-width: 0; }
.acp-r1 > .acp-rec { flex: 1 1 auto; min-width: 0; }

/* ---- per-card site health panel (speed + structured data) ---- */
.acp-hl {
    display: grid; grid-template-columns: 1fr 1fr; gap: 0;
    margin-top: 4px; border: 1px solid #eef0f4; border-radius: 10px;
    background: #fbfcfd; overflow: hidden;
}
.dark .acp-hl { border-color: #3f3f3f; background: #2c2c2c; }
@media (max-width: 860px) { .acp-hl { grid-template-columns: 1fr; } }
.acp-hl-item {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 11px 14px; min-width: 0; border-left: 1px solid #eef0f4;
}
.acp-hl-item:first-child { border-left: 0; }
.dark .acp-hl-item { border-left-color: #3f3f3f; }
@media (max-width: 860px) {
    .acp-hl-item { border-left: 0; border-top: 1px solid #eef0f4; }
    .acp-hl-item:first-child { border-top: 0; }
    .dark .acp-hl-item { border-top-color: #3f3f3f; }
}
.acp-hl-ic {
    width: 28px; height: 28px; flex: none; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
}
.acp-hl-ic iconify-icon { font-size: 15px; line-height: 1; }
.acp-hl-ic.s-bad  { background: #fef3f2; color: #d92d20; }
.acp-hl-ic.s-warn { background: #fffaeb; color: #dc6803; }
.acp-hl-ic.s-ok   { background: #ecfdf3; color: #039855; }
.acp-hl-ic.s-idle { background: #f2f4f7; color: #667085; }
.dark .acp-hl-ic.s-idle { background: #3a3a3a; color: #a3a3a3; }
.acp-hl-txt { min-width: 0; }
.acp-hl-k {
    font-size: 10px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase;
    color: #98a2b3; line-height: 1.4;
}
.acp-hl-v { font-size: 13px; font-weight: 700; line-height: 1.35; color: #101828; }
.dark .acp-hl-v { color: #f2f2f2; }
.acp-hl-v.v-bad  { color: #b42318; }
.acp-hl-v.v-warn { color: #b54708; }
.acp-hl-v.v-ok   { color: #067647; }
.acp-hl-d {
    margin: 2px 0 0; font-size: 11.5px; line-height: 1.5; color: #667085;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.dark .acp-hl-d { color: #a3a3a3; }
.acp-hl-a {
    display: inline-flex; align-items: center; gap: 3px; margin-top: 4px;
    font-size: 11.5px; font-weight: 700; text-decoration: none;
    background: none; border: 0; padding: 0; cursor: pointer; font-family: inherit;
}
.acp-hl-a:hover { text-decoration: underline; }
.acp-hl-a.a-speed  { color: #d9480f; }
.acp-hl-a.a-schema { color: #4a63e7; }
.acp-hl-a.a-idle   { color: #475467; }
.dark .acp-hl-a.a-idle { color: #d4d4d4; }
.acp-title { margin: 0; font-size: 15px; font-weight: 650; letter-spacing: -.01em; color: #1a1f2e; line-height: 1.3; }
.dark .acp-title { color: #fff; }
.acp-url {
    font-size: 12px; color: #3b6ef5; display: inline-flex; align-items: center; gap: 4px;
    text-decoration: none; word-break: break-all;
}
.acp-url a:hover { text-decoration: underline; }
.acp-url iconify-icon { font-size: 12px; opacity: .7; flex: none; }

/* row 1 right: recommended action, fills remaining width */
.acp-rec {
    flex: 1; min-width: 0; margin: 0; padding: 11px 14px; border-radius: 10px;
    background: #eefaf2; border: 1px solid #c9eed6;
}
.acp-rec.is-broken { background: #fdf0ef; border-color: #f8d5d1; }
.dark .acp-rec { background: #1e3328; border-color: #2f5540; }
.dark .acp-rec.is-broken { background: #3a2320; border-color: #5c352f; }
.acp-rec-k {
    font-size: 9px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
    color: #16a34a; margin-bottom: 3px;
}
.acp-rec.is-broken .acp-rec-k { color: #dc2626; }
.acp-rec-t { margin: 0; font-size: 12.5px; color: #374151; line-height: 1.5; }
.dark .acp-rec-t { color: #d4d4d4; }

/* row 2 left: metrics inline */
.acp-metrics { display: flex; gap: 30px; flex-wrap: wrap; flex: 1; min-width: 0; }
.acp-metrics .rm-k { font-size: 10.5px; color: #9ca3af; margin-bottom: 3px; }
.acp-metrics .rm-v { font-size: 13px; font-weight: 650; color: #1a1f2e; }
.dark .acp-metrics .rm-v { color: #fff; }
.acp-metrics .rm-v.bad  { color: #dc2626; }
.acp-metrics .rm-v.good { color: #16a34a; }

/* row 2 right: impact + button + status, all inline */
.acp-foot { flex: none; display: flex; align-items: center; gap: 18px; }
.acp-imp { flex: none; text-align: left; }
.acp-imp .n { display: inline-flex; align-items: center; gap: 4px; font-size: 24px; font-weight: 750; letter-spacing: -.03em; line-height: 1; }
.acp-imp .n iconify-icon { font-size: 17px; line-height: 1; }
.acp-imp .u { display: block; font-size: 11px; color: #9ca3af; margin-top: 4px; }
.acp-side-btns { display: flex; gap: 7px; }
.acp-side-btns .acp-btn { width: 118px; }
.acp-dot {
    display: flex; align-items: center; gap: 5px;
    font-size: 11px; color: #9ca3af; white-space: nowrap; text-transform: capitalize;
}
.acp-dot i { width: 6px; height: 6px; border-radius: 50%; flex: none; }

.acp-menu {
    position: absolute; right: 14px; top: 40px; z-index: 20;
    background: #fff; border: 1px solid #e4e7ec; border-radius: 10px;
    box-shadow: 0 10px 30px rgba(16,24,40,.14); overflow: hidden; min-width: 168px;
}
.dark .acp-menu { background: #333; border-color: #525252; }
.acp-menu button {
    display: block; width: 100%; text-align: left; border: 0; background: none;
    padding: 9px 14px; font-size: 13px; color: #475467; cursor: pointer;
}
.acp-menu button:hover { background: #f9fafb; }
.dark .acp-menu button { color: #d4d4d4; }
.dark .acp-menu button:hover { background: #404040; }

/* ---------------- detail drawer ---------------- */
.acp-scrim {
    position: fixed; inset: 0; background: rgba(16,24,40,.45);
    z-index: 1200; opacity: 0; pointer-events: none; transition: opacity .18s;
}
.acp-scrim.is-open { opacity: 1; pointer-events: auto; }

.acp-drawer {
    position: fixed; top: 0; right: 0; bottom: 0;
    width: 520px; max-width: 100%;
    background: #fff; z-index: 1210;
    transform: translateX(100%); transition: transform .22s cubic-bezier(.4,0,.2,1);
    display: flex; flex-direction: column;
    box-shadow: -14px 0 44px rgba(16,24,40,.18);
}
.acp-drawer.is-open { transform: translateX(0); }
.dark .acp-drawer { background: #262626; }

.acp-dhead {
    padding: 18px 20px; border-bottom: 1px solid #f2f4f7;
    display: flex; align-items: flex-start; justify-content: space-between; gap: 14px;
}
.dark .acp-dhead { border-color: #404040; }
.acp-dhead h3 {
    margin: 6px 0 0; font-size: 17px; font-weight: 650;
    color: #101828; letter-spacing: -.015em; line-height: 1.3;
}
.dark .acp-dhead h3 { color: #fff; }
.acp-dhead .ent { margin: 5px 0 0; font-size: 12px; color: #98a2b3; word-break: break-all; }
.acp-dclose {
    width: 30px; height: 30px; border-radius: 8px; border: 0; background: none;
    color: #98a2b3; cursor: pointer; flex: none;
    display: flex; align-items: center; justify-content: center;
}
.acp-dclose:hover { background: #f2f4f7; color: #475467; }
.dark .acp-dclose:hover { background: #404040; }

.acp-dbody { padding: 18px 20px; overflow-y: auto; flex: 1; }

.acp-dimp {
    display: flex; align-items: baseline; gap: 8px;
    padding: 14px 16px; border-radius: 11px; background: #f9fafb;
    border: 1px solid #f2f4f7; margin-bottom: 18px;
}
.dark .acp-dimp { background: #333; border-color: #404040; }
.acp-dimp .n { display: inline-flex; align-items: center; gap: 4px; font-size: 26px; font-weight: 800; letter-spacing: -.04em; line-height: 1; }
.acp-dimp .n iconify-icon { font-size: 18px; line-height: 1; }
.acp-dimp .u { font-size: 12px; color: #98a2b3; }

.acp-dsec { margin-bottom: 18px; }
.acp-dsec .lbl {
    margin: 0 0 8px; font-size: 9.5px; letter-spacing: .09em;
    text-transform: uppercase; color: #b8c0cc;
}
.acp-dsec p { margin: 0; font-size: 13.5px; color: #475467; line-height: 1.6; }
.dark .acp-dsec p { color: #d4d4d4; }

.acp-dtable { width: 100%; border-collapse: collapse; }
.acp-dtable td {
    padding: 9px 0; border-bottom: 1px solid #f2f4f7; font-size: 13px; color: #667085;
}
.dark .acp-dtable td { border-color: #404040; color: #a3a3a3; }
.acp-dtable tr:last-child td { border-bottom: 0; }
.acp-dtable td:last-child {
    text-align: right; font-weight: 650; color: #101828;
}
.dark .acp-dtable td:last-child { color: #fff; }

.acp-drec {
    font-size: 13.5px; color: #344054; line-height: 1.7;
    background: #f9fafb; border: 1px solid #eaecf0;
    border-left: 3px solid var(--sev, #98a2b3);
    border-radius: 9px; padding: 12px 14px;
    white-space: pre-line;   /* keep the numbered steps on separate lines */
}
.dark .acp-drec { background: #333; border-color: #404040; color: #e5e5e5; }

.acp-dnote {
    font-size: 12.5px; line-height: 1.55; border-radius: 9px;
    padding: 11px 13px; margin-bottom: 18px;
}
.acp-dnote.info { background: #eff8ff; border: 1px solid #b2ddff; color: #175cd3; }
.acp-dnote.warn { background: #fffaeb; border: 1px solid #fedf89; color: #b54708; }
.acp-dnote.good { background: #ecfdf3; border: 1px solid #abefc6; color: #027a48; }
.acp-dnote b { display: block; margin-bottom: 3px; }

.acp-dfoot {
    padding: 14px 20px; border-top: 1px solid #f2f4f7;
    display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap;
    background: #f9fafb;
}
.dark .acp-dfoot { border-color: #404040; background: #333; }

/* ---------------- inline meta editor ---------------- */
.acp-input {
    width: 100%; padding: 10px 12px; border-radius: 9px;
    border: 1px solid #d0d5dd; background: #fff; color: #101828;
    font-family: inherit; font-size: 13.5px; line-height: 1.5;
    transition: border-color .12s, box-shadow .12s;
}
.acp-input:focus {
    outline: none; border-color: var(--clr-primary, #487fff);
    box-shadow: 0 0 0 3px rgba(72,127,255,.14);
}
.dark .acp-input { background: #333; border-color: #525252; color: #fff; }
.acp-textarea { min-height: 76px; resize: vertical; }

.acp-count {
    margin-top: 5px; font-size: 11px; color: #98a2b3; text-align: right;
}
.acp-count.warn { color: #b54708; }
.acp-count.over { color: #d92d20; font-weight: 600; }

.acp-serp {
    border: 1px solid #eaecf0; border-radius: 10px; padding: 13px 15px; background: #fff;
}
.dark .acp-serp { background: #333; border-color: #404040; }
.acp-serp-url { font-size: 12px; color: #4d5156; margin-bottom: 4px; word-break: break-all; }
.dark .acp-serp-url { color: #a3a3a3; }
.acp-serp-title {
    font-size: 17px; line-height: 1.3; color: #1a0dab; margin-bottom: 4px;
    overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
.dark .acp-serp-title { color: #8ab4f8; }
.acp-serp-desc {
    font-size: 13px; line-height: 1.5; color: #4d5156;
    overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
.dark .acp-serp-desc { color: #bdc1c6; }

.acp-hint { margin: 10px 0 0; font-size: 12px; color: #98a2b3; line-height: 1.55; }
.acp-hint.ok { color: #027a48; }
.acp-hint.err { color: #d92d20; }

@media (max-width: 560px) {
    .acp-drawer { width: 100%; }
    .acp-dfoot .acp-btn { flex: 1; }
}

/* ---------------- states ---------------- */
.acp-state { padding: 60px 24px; text-align: center; }
.acp-state-ic {
    width: 54px; height: 54px; border-radius: 50%; background: #f2f4f7;
    display: flex; align-items: center; justify-content: center; margin: 0 auto 14px;
}
.dark .acp-state-ic { background: #404040; }
.acp-state h3 { margin: 0 0 6px; font-size: 16px; font-weight: 650; color: #101828; }
.dark .acp-state h3 { color: #fff; }
.acp-state p { margin: 0 auto; font-size: 13.5px; color: #98a2b3; max-width: 400px; }

.acp-skel {
    padding: 18px 20px; background: #fff; border: 1px solid #e9edf3;
    border-radius: 13px; box-shadow: 0 1px 2px rgba(16,24,40,.04);
}
.dark .acp-skel { background: #262626; border-color: #404040; }
.acp-skel-line {
    height: 12px; border-radius: 6px; margin-bottom: 10px;
    background: linear-gradient(90deg, #eef1f6 25%, #f8fafc 50%, #eef1f6 75%);
    background-size: 200% 100%; animation: acpShimmer 1.3s linear infinite;
}
@keyframes acpShimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

/* ---------------- responsive ---------------- */
@media (max-width: 1320px) {
    .acp-side { width: 138px; }
    .acp-metrics { gap: 18px; }
}
@media (max-width: 1100px) {
    .acp-row { flex-wrap: wrap; }
    .acp-main { flex-basis: 100%; order: 3; }
    .acp-imp { order: 4; }
    .acp-side { order: 5; width: auto; flex: 1; flex-direction: row; align-items: center; justify-content: flex-end; }
    .acp-side-btns { flex-direction: row; }
}
@media (max-width: 640px) {
    .acp-head { padding: 14px; }
    .acp-head-r { align-items: flex-start; width: 100%; }
    .acp-row { padding: 14px; gap: 12px; }
    .acp-lead { flex-direction: row; width: auto; gap: 9px; align-items: center; }
    .acp-metrics { gap: 16px; }
    .acp-imp { width: auto; }
    .acp-side { width: 100%; flex-direction: column; align-items: stretch; }
    .acp-side-btns { flex-direction: row; }
    .acp-side-btns .acp-btn { flex: 1; }
    .acp-flabel { width: 100%; }
    .acp-tools { margin-left: 0; width: 100%; }
}
@media (prefers-reduced-motion: reduce) {
    .acp-flash, .acp-skel-line { animation: none; }
}
</style>

<?php if (!$hasPremiumAccess): ?>

    <div class="acp-list">
        <div class="acp-state">
            <div class="acp-state-ic">
                <iconify-icon icon="solar:lock-keyhole-bold-duotone"
                    style="font-size:26px;color:#98a2b3;"></iconify-icon>
            </div>
            <h3>Action Center is a premium feature</h3>
            <p style="margin-bottom:18px;">
                Get a prioritised list of what to fix on your site, ranked by how much
                traffic each one is worth.
            </p>
            <button type="button" class="open-upgrade-modal acp-btn acp-btn-primary" style="margin:0 auto;">
                <iconify-icon icon="mdi:crown-outline" style="font-size:17px;"></iconify-icon>
                Upgrade Plan
            </button>
        </div>
    </div>

<?php else: ?>

<div class="acp-shell">

    <!-- ==================== HEADER ==================== -->
    <div class="acp-head">
        <div class="acp-head-l">
            <div class="acp-head-ic">
                <iconify-icon icon="solar:bolt-bold"
                    style="font-size:21px;color:var(--clr-primary,#487fff);"></iconify-icon>
            </div>
            <div>
                <h2>Action Center</h2>
                <p class="sub">Smart SEO insights that help you grow traffic and rankings</p>
            </div>
        </div>

        <div class="acp-head-r">
            <div class="acp-crumb">
                <iconify-icon icon="solar:home-smile-angle-outline" style="font-size:14px;"></iconify-icon>
                <a href="index.php">Dashboard</a>
                <span>/</span>
                <a href="action-center.php">Action Center</a>
            </div>
            <div class="acp-head-btns">
                <button type="button" class="acp-btn" id="acp-refresh">
                    <iconify-icon icon="solar:refresh-outline" style="font-size:15px;"></iconify-icon>
                    Refresh
                </button>
                <button type="button" class="acp-btn acp-btn-primary" id="acp-mark-all">
                    <iconify-icon icon="solar:check-read-outline" style="font-size:15px;"></iconify-icon>
                    Mark all as read
                </button>
            </div>
        </div>
    </div>

    <!-- ==================== KPI TILES ==================== -->
    <div class="acp-kpis">
        <div class="acp-kpi">
            <p class="k">Traffic at risk
                <span class="acp-info" title="Estimated monthly clicks you stand to lose across all open issues">i</span>
            </p>
            <p class="v" id="acp-risk" style="color:#d92d20;">&mdash;</p>
            <p class="n">clicks / month</p>
            <svg class="spark" id="spark-risk" preserveAspectRatio="none"></svg>
        </div>

        <div class="acp-kpi">
            <p class="k">Traffic opportunities
                <span class="acp-info" title="Estimated monthly clicks available from open opportunities">i</span>
            </p>
            <p class="v" id="acp-opp" style="color:#039855;">&mdash;</p>
            <p class="n">clicks / month</p>
            <svg class="spark" id="spark-opp" preserveAspectRatio="none"></svg>
        </div>

        <div class="acp-kpi">
            <p class="k">Recovered
                <span class="acp-info" title="Clicks regained from insights that were fixed and closed">i</span>
            </p>
            <p class="v" id="acp-recovered" style="color:#7a5af8;">&mdash;</p>
            <p class="n">from resolved insights</p>
            <svg class="spark" id="spark-rec" preserveAspectRatio="none"></svg>
        </div>

        <div class="acp-kpi">
            <p class="k">Open insights
                <span class="acp-info" title="Insights that are new, read, or being worked on">i</span>
            </p>
            <p class="v" id="acp-open" style="color:#1570ef;">&mdash;</p>
            <p class="n"><span id="acp-unread">0</span> need your attention</p>
            <svg class="spark" id="spark-open" preserveAspectRatio="none"></svg>
        </div>

        <div class="acp-kpi">
            <p class="k">Actioned insights
                <span class="acp-info" title="Insights you have acted on, awaiting the next measurement">i</span>
            </p>
            <p class="v" id="acp-actioned" style="color:#f79009;">&mdash;</p>
            <p class="n">in progress</p>
            <svg class="spark" id="spark-act" preserveAspectRatio="none"></svg>
        </div>
    </div>

    <!-- ==================== FILTERS ==================== -->
    <div class="acp-filters">
        <div class="acp-fgroup">
            <label>Status</label>
            <select id="acp-f-status" class="acp-select">
                <option value="all">All</option>
                <option value="open">Open</option>
                <option value="new">New</option>
                <option value="read">Read</option>
                <option value="actioned">Actioned</option>
                <option value="resolved">Resolved</option>
                <option value="dismissed">Dismissed</option>
            </select>
        </div>
        <div class="acp-fgroup">
            <label>Severity</label>
            <select id="acp-f-sev" class="acp-select">
                <option value="all">All</option>
                <option value="critical">Critical</option>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
            </select>
        </div>
        <div class="acp-fgroup">
            <label>Type</label>
            <select id="acp-f-type" class="acp-select">
                <option value="all">All</option>
                <option value="ctr">CTR</option>
                <option value="drop">Drops</option>
                <option value="newkw">New keywords</option>
                <option value="broken">Broken</option>
                <option value="indexing">Indexing</option>
            </select>
        </div>
        <div class="acp-fgroup" style="margin-left:auto;">
            <label>Sort by</label>
            <select id="acp-sort" class="acp-select">
                <option value="newest">Newest first</option>
                <option value="impact">Highest impact</option>
                <option value="severity">Severity</option>
                <option value="oldest">Oldest first</option>
            </select>
        </div>
    </div>

    <!-- ==================== LIST ==================== -->
    <div class="acp-list" id="acp-list">
        <div class="acp-skel">
            <div class="acp-skel-line" style="width:30%;"></div>
            <div class="acp-skel-line" style="width:56%;"></div>
        </div>
        <div class="acp-skel">
            <div class="acp-skel-line" style="width:38%;"></div>
            <div class="acp-skel-line" style="width:64%;"></div>
        </div>
        <div class="acp-skel">
            <div class="acp-skel-line" style="width:34%;"></div>
            <div class="acp-skel-line" style="width:50%;"></div>
        </div>
    </div>

</div>

<!-- ==================== DETAIL DRAWER ==================== -->
<div class="acp-scrim" id="acp-scrim"></div>
<aside class="acp-drawer" id="acp-drawer" role="dialog" aria-modal="true" aria-labelledby="acp-dtitle">
    <div class="acp-dhead">
        <div style="min-width:0;">
            <span class="acp-tag" id="acp-dtag"></span>
            <h3 id="acp-dtitle">&mdash;</h3>
            <p class="ent" id="acp-dent"></p>
        </div>
        <button type="button" class="acp-dclose" id="acp-dclose" aria-label="Close">
            <iconify-icon icon="solar:close-circle-outline" style="font-size:20px;"></iconify-icon>
        </button>
    </div>
    <div class="acp-dbody" id="acp-dbody"></div>
    <div class="acp-dfoot" id="acp-dfoot"></div>
</aside>

<script>
(function () {
    'use strict';

    var API    = <?= json_encode($acApiUrl) ?>;
    var FOCUS  = <?= json_encode($focusId) ?>;
    var listEl = document.getElementById('acp-list');

    var filters = { status: 'all', severity: 'all', type: 'all' };
    var sortBy  = 'newest';
    var rows    = [];
    var allRows = [];

    // ---------------- tokens ----------------
    var SEV = {
        critical: { bar: '#d92d20', tag: '#fee4e2', tagFg: '#d92d20', tile: '#fef3f2' },
        high:     { bar: '#f79009', tag: '#fef0c7', tagFg: '#b54708', tile: '#fffaeb' },
        medium:   { bar: '#1570ef', tag: '#d1e9ff', tagFg: '#175cd3', tile: '#eff8ff' },
        low:      { bar: '#039855', tag: '#d1fadf', tagFg: '#027a48', tile: '#ecfdf3' }
    };
    var SEV_FALLBACK = { bar: '#98a2b3', tag: '#f2f4f7', tagFg: '#667085', tile: '#f9fafb' };

    var TYPE = {
        ctr:      { label: 'CTR opportunity',  icon: 'solar:graph-up-bold' },
        drop:     { label: 'Keyword dropping', icon: 'solar:graph-down-bold' },
        newkw:    { label: 'New keyword',      icon: 'solar:key-bold' },
        broken:   { label: 'Broken page',      icon: 'solar:link-broken-bold' },
        redirect: { label: 'Redirected URL',   icon: 'solar:round-arrow-right-bold' },
        indexing: { label: 'Indexing issue',   icon: 'solar:danger-triangle-bold' }
    };

    var DOT = {
        new: '#1570ef', read: '#98a2b3', actioned: '#f79009',
        resolved: '#039855', dismissed: '#d0d5dd'
    };

    var FILTER_DEFS = {
        status: [
            ['all','All'], ['open','Open'], ['new','New'], ['read','Read'],
            ['actioned','Actioned'], ['resolved','Resolved'], ['dismissed','Dismissed']
        ],
        severity: [
            ['all','All'], ['critical','Critical'], ['high','High'],
            ['medium','Medium'], ['low','Low']
        ],
        type: [
            ['all','All'], ['ctr','CTR'], ['drop','Drops'],
            ['newkw','New keywords'], ['broken','Broken'], ['indexing','Indexing']
        ]
    };

    function fmt(n) { return Number(n || 0).toLocaleString(); }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }

    function ago(dateStr) {
        if (!dateStr) return '';
        var d = new Date(String(dateStr) + 'T00:00:00');
        if (isNaN(d.getTime())) return '';
        var days = Math.round((Date.now() - d.getTime()) / 86400000);
        if (days <= 0) return 'today';
        if (days === 1) return '1d ago';
        if (days < 30) return days + 'd ago';
        var m = Math.round(days / 30);
        return m + 'mo ago';
    }

    function api(body) {
        return fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        });
    }

    // ---------------- sparklines ----------------
    // Built from the real insight set, bucketed by first_seen. If there is
    // not enough history to plot, the line is hidden rather than invented.
    function spark(id, values, colour) {
        var el = document.getElementById(id);
        if (!el) return;
        if (!values || values.length < 2) { el.style.display = 'none'; return; }
        el.style.display = 'block';

        var w = 100, h = 40, pad = 4;
        var max = Math.max.apply(null, values.concat([1]));
        var min = Math.min.apply(null, values.concat([0]));
        var span = Math.max(max - min, 1);

        var pts = values.map(function (v, i) {
            var x = (i / (values.length - 1)) * w;
            var y = pad + (1 - (v - min) / span) * (h - pad * 2);
            return x.toFixed(2) + ',' + y.toFixed(2);
        });

        var line = 'M' + pts.join(' L');
        var area = line + ' L' + w + ',' + h + ' L0,' + h + ' Z';

        el.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
        el.innerHTML =
            '<defs><linearGradient id="g-' + id + '" x1="0" y1="0" x2="0" y2="1">' +
            '<stop offset="0%" stop-color="' + colour + '" stop-opacity=".22"/>' +
            '<stop offset="100%" stop-color="' + colour + '" stop-opacity="0"/>' +
            '</linearGradient></defs>' +
            '<path d="' + area + '" fill="url(#g-' + id + ')"/>' +
            '<path d="' + line + '" fill="none" stroke="' + colour + '" stroke-width="1.6" ' +
            'stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
    }

    function drawSparks() {
        if (!allRows.length) return;

        var sorted = allRows.slice().sort(function (a, b) {
            return String(a.first_seen).localeCompare(String(b.first_seen));
        });

        function cumulative(pick) {
            var total = 0;
            return sorted.map(function (r) { total += pick(r); return total; });
        }

        function isOpen(r) {
            return r.status === 'new' || r.status === 'read' || r.status === 'actioned';
        }

        spark('spark-risk', cumulative(function (r) {
            return (isOpen(r) && r.impact_clicks < 0) ? -r.impact_clicks : 0;
        }), '#d92d20');

        spark('spark-opp', cumulative(function (r) {
            return (isOpen(r) && r.impact_clicks > 0) ? r.impact_clicks : 0;
        }), '#039855');

        spark('spark-rec', cumulative(function (r) {
            return r.status === 'resolved' ? Math.abs(r.impact_clicks) : 0;
        }), '#7a5af8');

        spark('spark-open', cumulative(function (r) {
            return isOpen(r) ? 1 : 0;
        }), '#1570ef');

        spark('spark-act', cumulative(function (r) {
            return r.status === 'actioned' ? 1 : 0;
        }), '#f79009');

        var actioned = allRows.filter(function (r) { return r.status === 'actioned'; }).length;
        document.getElementById('acp-actioned').textContent = fmt(actioned);
    }

    // ---------------- filters ----------------
    function renderFilters() {
        var map = { status: 'acp-f-status', severity: 'acp-f-sev', type: 'acp-f-type' };
        Object.keys(map).forEach(function (dim) {
            var sel = document.getElementById(map[dim]);
            if (!sel) return;
            sel.value = filters[dim];
            if (!sel.dataset.wired) {
                sel.dataset.wired = '1';
                sel.addEventListener('change', function () {
                    filters[dim] = sel.value;
                    renderList();
                });
            }
        });
    }

    // ---------------- one row ----------------
    // Type controls the left icon colour, icon and short label - green for
    // CTR, purple for broken, etc. - matching the reference layout.

    /* ================= site health inside each insight card ================= */
    var ACP_BASE    = <?php echo json_encode($acBase); ?>;
    var SPEED_SCORE = <?php echo json_encode($rowSpeedScore); ?>;
    var SITE_SCHEMA = <?php echo json_encode($siteSchema, JSON_UNESCAPED_SLASHES); ?>;
    var WS_LINK     = <?php echo json_encode($WS_APP_LINK); ?>;
    var SCHEMA_LINK = <?php echo json_encode($SCHEMA_APP_LINK); ?>;

    function hlItem(state, icon, label, value, valueCls, desc, link) {
        return '<div class="acp-hl-item">' +
            '<span class="acp-hl-ic s-' + state + '">' +
                '<iconify-icon icon="' + icon + '"></iconify-icon>' +
            '</span>' +
            '<div class="acp-hl-txt">' +
                '<div class="acp-hl-k">' + esc(label) + '</div>' +
                '<div class="acp-hl-v ' + valueCls + '">' + esc(value) + '</div>' +
                (desc ? '<p class="acp-hl-d">' + esc(desc) + '</p>' : '') +
                (link || '') +
            '</div>' +
        '</div>';
    }

    function healthLine(r) {
        var out = '';

        /* Speed: the site's cached PageSpeed score. */
        if (SPEED_SCORE === null && SPEED_STATE === 'failed') {
            out += hlItem('idle', 'solar:bolt-bold', 'Site speed', 'Not tested', '',
                'See how fast your site loads for visitors and Google.',
                '<button type="button" class="acp-hl-a a-idle" data-runspeed="1">Check now \u2192</button>');
        } else if (SPEED_SCORE === null) {
            out += hlItem('idle', 'solar:bolt-bold', 'Site speed', 'Checking\u2026', '',
                'Measuring how fast your site loads.', '');
        } else if (SPEED_SCORE < 50) {
            out += hlItem('bad', 'solar:bolt-bold', 'Site speed', SPEED_SCORE + '/100 \u00b7 Slow', 'v-bad',
                'Slow pages rank lower and lose visitors before they load.',
                '<a class="acp-hl-a a-speed" target="_blank" rel="noopener" href="' + esc(WS_LINK) + '">Fix with Website Speedy \u2192</a>');
        } else if (SPEED_SCORE < 90) {
            out += hlItem('warn', 'solar:bolt-bold', 'Site speed', SPEED_SCORE + '/100 \u00b7 Needs work', 'v-warn',
                'Faster pages hold rankings better and convert more.',
                '<a class="acp-hl-a a-speed" target="_blank" rel="noopener" href="' + esc(WS_LINK) + '">Speed up with Website Speedy \u2192</a>');
        } else {
            out += hlItem('ok', 'solar:bolt-bold', 'Site speed', SPEED_SCORE + '/100 \u00b7 Fast', 'v-ok',
                'No speed issues found.', '');
        }

        /* Structured data: the site's schema state. */
        if (SITE_SCHEMA && SITE_SCHEMA.checked) {
            var miss = SITE_SCHEMA.missing || [];
            var inv  = SITE_SCHEMA.invalid || 0;
            if (inv > 0) {
                out += hlItem('bad', 'solar:code-scan-outline', 'Structured data',
                    inv + (inv > 1 ? ' items with errors' : ' item with errors'), 'v-bad',
                    'Invalid markup means Google will not show rich results.',
                    '<a class="acp-hl-a a-schema" target="_blank" rel="noopener" href="' + esc(SCHEMA_LINK) + '">Fix with our Schema app \u2192</a>');
            } else if (miss.length) {
                out += hlItem(miss.length >= 3 ? 'bad' : 'warn', 'solar:code-scan-outline', 'Structured data',
                    miss.slice(0, 2).join(', ') + (miss.length > 2 ? ' +' + (miss.length - 2) + ' more' : '') + ' missing',
                    miss.length >= 3 ? 'v-bad' : 'v-warn',
                    'No stars, prices or FAQs in Google without this markup.',
                    '<a class="acp-hl-a a-schema" target="_blank" rel="noopener" href="' + esc(SCHEMA_LINK) + '">Add with our Schema app \u2192</a>');
            } else {
                out += hlItem('ok', 'solar:code-scan-outline', 'Structured data', 'Complete', 'v-ok',
                    'Google can read the markup it needs for rich results.', '');
            }
        } else if (SCHEMA_STATE === 'failed') {
            out += hlItem('idle', 'solar:code-scan-outline', 'Structured data', 'Not checked', '',
                'See which schema types your pages are missing.',
                '<button type="button" class="acp-hl-a a-idle" data-runschema="1">Check now \u2192</button>');
        } else {
            out += hlItem('idle', 'solar:code-scan-outline', 'Structured data', 'Checking\u2026', '',
                'Reading the markup on your site.', '');
        }

        return '<div class="acp-hl">' + out + '</div>';
    }

    /* Neither check is triggered by a button any more: when the cache is
       empty the page fetches once, quietly, and repaints. Both endpoints
       are cache-first, so a warm cache costs one cheap round trip and no
       PSI quota. Each has a hard cap, so a chip can never sit at
       "Checking..." forever - it turns into a "Check now" link instead. */
    var SPEED_STATE  = (SPEED_SCORE !== null) ? 'done' : 'pending';
    var SCHEMA_STATE = (SITE_SCHEMA && SITE_SCHEMA.checked) ? 'done' : 'pending';

    function healthRepaint() {
        try {
            if (typeof renderList === 'function') { renderList(); }
        } catch (e) { /* list not built yet - the next render picks it up */ }
    }

    function pickScore(d) {
        if (!d || typeof d !== 'object') { return null; }
        var c = [
            d.perf_score, d.performance, d.score,
            d.result && d.result.perf_score,
            d.data && d.data.perf_score,
            d.scores && d.scores.performance
        ];
        for (var i = 0; i < c.length; i++) {
            var v = c[i];
            if (v !== undefined && v !== null && v !== '' && !isNaN(v)) { return Math.round(Number(v)); }
        }
        return null;
    }

    function runSiteSpeedCheck() {
        SPEED_STATE = 'pending';
        var done = false;
        var timer = setTimeout(function () {
            if (done) { return; }
            done = true; SPEED_STATE = 'failed'; healthRepaint();
        }, 45000);   // PSI is slow on a cold cache

        fetch(ACP_BASE + '/api/run_speed_test.php?strategy=mobile', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (done) { return; }
                var score = pickScore(data);
                if (score === null) { throw 0; }
                done = true; clearTimeout(timer);
                SPEED_SCORE = score; SPEED_STATE = 'done'; healthRepaint();
            })
            .catch(function () {
                if (done) { return; }
                done = true; clearTimeout(timer);
                SPEED_STATE = 'failed'; healthRepaint();
            });
    }

    function runSiteSchemaCheck() {
        SCHEMA_STATE = 'pending';
        var done = false;
        var timer = setTimeout(function () {
            if (done) { return; }
            done = true; SCHEMA_STATE = 'failed'; healthRepaint();
        }, 25000);

        /* entity=/ - the endpoint resolves it against this instance's own
           verified site, so the page never needs to know the host. */
        fetch(ACP_BASE + '/api/run_schema_check.php?entity=%2F', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (done) { return; }
                if (!data.success || !data.result) { throw 0; }
                var found = {}, inv = 0;
                (data.result.items || []).forEach(function (it) {
                    found[it.type] = true;
                    if (!it.valid) inv++;
                });
                (data.result.other_types || []).forEach(function (t) { found[t] = true; });
                var missNow = (SITE_SCHEMA.want || []).filter(function (w) {
                    if (found[w]) return false;
                    if (w === 'Article' && (found.BlogPosting || found.NewsArticle)) return false;
                    return true;
                });
                done = true; clearTimeout(timer);
                SITE_SCHEMA = { checked: true, missing: missNow, invalid: inv,
                                site_type: SITE_SCHEMA.site_type, want: SITE_SCHEMA.want };
                SCHEMA_STATE = 'done'; healthRepaint();
            })
            .catch(function () {
                if (done) { return; }
                done = true; clearTimeout(timer);
                SCHEMA_STATE = 'failed'; healthRepaint();
            });
    }

    if (SPEED_STATE === 'pending')  { runSiteSpeedCheck(); }
    if (SCHEMA_STATE === 'pending') { runSiteSchemaCheck(); }

    var TYPE_STYLE = {
        ctr:      { label: 'CTR Opportunity', icon: 'solar:graph-up-bold',          ic: '#16a34a', wash: '#e9f9f0' },
        drop:     { label: 'Keyword Drop',    icon: 'solar:graph-down-bold',        ic: '#e5484d', wash: '#fdecec' },
        newkw:    { label: 'New Keyword',     icon: 'solar:key-bold',               ic: '#3b82f6', wash: '#e8f0fe' },
        broken:   { label: 'Broken Page',     icon: 'solar:link-broken-bold',       ic: '#7c5cfc', wash: '#f0ecfe' },
        redirect: { label: 'Redirected URL',  icon: 'solar:round-arrow-right-bold', ic: '#f59e0b', wash: '#fef3e2' },
        indexing: { label: 'Indexing Issue',  icon: 'solar:danger-triangle-bold',   ic: '#e5484d', wash: '#fdecec' }
    };

    function renderRow(r) {
        var ts     = TYPE_STYLE[r.type] || { label: r.type, icon: 'solar:bolt-bold', ic: '#6b7280', wash: '#f2f4f7' };
        var gain   = r.impact_clicks >= 0;
        var closed = r.status === 'resolved' || r.status === 'dismissed';
        var broken = (r.type === 'broken' || r.type === 'indexing');

        var metrics = (r.what || []).slice(0, 4).map(function (p) {
            var v = String(p[1]);
            var cls = '';
            if (/^0(\.0)?%$/.test(v) || /404|not found/i.test(v)) cls = ' bad';
            else if (/expected/i.test(p[0])) cls = ' good';
            return '<div class="rm"><div class="rm-k">' + esc(p[0]) + '</div>' +
                   '<div class="rm-v' + cls + '">' + esc(v) + '</div></div>';
        }).join('');

        var recBox = r.rec_text
            ? '<div class="acp-rec' + (broken ? ' is-broken' : '') + '">' +
                '<div class="acp-rec-k">Recommended Action</div>' +
                '<p class="acp-rec-t">' + esc(r.rec_text) + '</p>' +
              '</div>'
            : '';

        // Only a single "View details" button for now - the drawer holds
        // everything else (edit meta, dismiss, mark actioned).
        var buttons =
            '<button class="acp-btn acp-btn-sm acp-btn-block" ' +
            'data-open="' + r.id + '" data-view="detail">View details</button>';

        var sevWord = String(r.severity || 'low').toUpperCase();
        var minus = String.fromCharCode(0x2212);

        return '' +
        '<article class="acp-row' + (closed ? ' is-closed' : '') + '" id="acp-card-' + r.id + '">' +

            // left rail: type icon + severity/type label
            '<div class="acp-lead">' +
                '<div class="acp-lead-ic" style="background:' + ts.wash + ';">' +
                    '<iconify-icon icon="' + ts.icon + '" style="font-size:20px;color:' + ts.ic + ';"></iconify-icon>' +
                '</div>' +
                '<div class="acp-lead-lbl">' +
                    '<span class="lv">' + esc(sevWord) + '</span>' +
                    '<span class="ty">' + esc(ts.label) + '</span>' +
                '</div>' +
            '</div>' +

            // body: two stacked rows, each split left / right
            '<div class="acp-body">' +

                // row 1 - title+url (left)  |  recommended action (right)
                '<div class="acp-r1">' +
                    '<div class="acp-headcol">' +
                     '<div class="acp-url">' +
                        '<span class="text-xs text-gray-500 font-medium">Page:</span> ' +
                            '<a href="' + esc(r.entity) + '" target="_blank" rel="noopener">' +
                                esc(r.entity) +
                                '<iconify-icon icon="solar:arrow-right-up-linear"></iconify-icon></a>' +
                        '</div>' +
                        '<h3 class="acp-title">' +  '<span class="text-xs text-gray-500 font-medium">Suggestion:</span> ' + esc(r.title) + '</h3>' +
                    '</div>' +
                    recBox +
                '</div>' +

                // row 1b - site health, full width under the two columns
                healthLine(r) +

                // row 2 - metrics (left)  |  impact + button + status (right)
                '<div class="acp-r2">' +
                    '<div class="acp-metrics">' + metrics + '</div>' +
                    '<div class="acp-foot">' +
                        '<div class="acp-imp">' +
                            '<span class="n" style="color:' + (gain ? '#16a34a' : '#dc2626') + ';">' +
                                '<iconify-icon icon="' + (gain ? 'mdi:trending-up' : 'mdi:trending-down') + '" aria-hidden="true"></iconify-icon>' +
                                fmt(Math.abs(r.impact_clicks)) + '</span>' +
                            '<span class="u">' + (gain ? 'potential clicks / month' : 'clicks at risk / month') + '</span>' +
                        '</div>' +
                        '<div class="acp-side-btns">' + buttons + '</div>' +
                        '<span class="acp-dot">' +
                            '<i style="background:' + (DOT[r.status] || '#d0d5dd') + ';"></i>' +
                            esc(r.status) + ' \u00b7 ' + ago(r.last_seen) +
                        '</span>' +
                    '</div>' +
                '</div>' +

            '</div>' +
        '</article>';
    }


    // ---------------- empty state ----------------
    //
    // Four reasons a feed can be empty, and only one of them means the
    // account is healthy. Telling all four "Nothing needs attention" is
    // what makes a working app look broken to the people it is failing.
    var EMPTY = <?= json_encode($acEmpty, JSON_UNESCAPED_SLASHES) ?>;

    function stateCta(href, label, icon) {
        return '<div style="margin-top:18px;">' +
            '<a href="' + esc(href) + '" class="acp-btn acp-btn-primary">' +
                '<iconify-icon icon="' + icon + '"></iconify-icon>' + esc(label) +
            '</a></div>';
    }

    function emptyStateHtml() {
        var r = EMPTY.reason;

        if (r === 'not_verified') {
            return stateBlock('solar:shield-warning-outline',
                'Finish connecting Search Console',
                'Your site is not verified with Google yet, so Google is not sharing any ' +
                'search data with us. It takes a couple of minutes.')
                + stateCta('setup-wizard.php?step=2', 'Finish setup', 'solar:play-circle-outline');
        }

        if (r === 'no_permission') {
            return stateBlock('solar:lock-keyhole-outline',
                'Google is refusing access to your data',
                'The Google account you connected does not have permission to read ' +
                esc(EMPTY.site || 'this property') + ' in Search Console. Reconnect with the ' +
                'account that owns it, and allow every permission it asks for.')
                + stateCta('setup-wizard.php?step=1', 'Reconnect Google', 'solar:refresh-outline');
        }

        if (r === 'no_traffic') {
            return stateBlock('solar:hourglass-outline',
                'Waiting for your first search data',
                'Everything is connected, but Google has not recorded any searches for your ' +
                'site yet. That is normal for a new site - it usually takes a few weeks ' +
                'after launch. We check every 15 minutes.');
        }

        if (r === 'too_little_traffic') {
            return stateBlock('solar:graph-new-up-outline',
                'Not enough search traffic yet',
                'We hold <strong>' + fmt(EMPTY.impr30) + '</strong> impressions across <strong>' +
                fmt(EMPTY.days30) + '</strong> days in the last month. That is real traffic, but ' +
                'not yet enough to tell a genuine trend from ordinary day-to-day noise.<br><br>' +
                'We would rather show you nothing than something we cannot stand behind. ' +
                'Insights start at around ' + fmt(EMPTY.minImpr) + ' impressions across ' +
                fmt(EMPTY.minDays) + ' days.');
        }

        return stateBlock('solar:check-circle-outline', 'Nothing needs attention',
            'New insights appear here after each daily check of your Search Console data.');
    }

    function stateBlock(icon, title, sub) {
        return '<div class="acp-state">' +
            '<div class="acp-state-ic">' +
                '<iconify-icon icon="' + icon + '" style="font-size:26px;color:#98a2b3;"></iconify-icon>' +
            '</div>' +
            '<h3>' + title + '</h3><p>' + sub + '</p></div>';
    }

    // ---------------- sorting ----------------
    function sortRows(list) {
        // critical is rank 0, so use a hasOwnProperty check rather than `|| 9` -
        // `0 || 9` evaluates to 9 and would sort critical last.
        var ORD = { critical: 0, high: 1, medium: 2, low: 3 };
        function rank(sev) {
            return Object.prototype.hasOwnProperty.call(ORD, sev) ? ORD[sev] : 9;
        }

        return list.slice().sort(function (a, b) {
            var ao = (a.status === 'resolved' || a.status === 'dismissed') ? 1 : 0;
            var bo = (b.status === 'resolved' || b.status === 'dismissed') ? 1 : 0;
            if (ao !== bo) return ao - bo;

            if (sortBy === 'impact')   return Math.abs(b.impact_clicks) - Math.abs(a.impact_clicks);
            if (sortBy === 'severity') return rank(a.severity) - rank(b.severity);
            if (sortBy === 'newest')   return String(b.first_seen).localeCompare(String(a.first_seen));
            if (sortBy === 'oldest')   return String(a.first_seen).localeCompare(String(b.first_seen));
            return 0;
        });
    }

    // Client-side filtering so every dropdown works instantly and does not
    // depend on the API honouring severity/type params.
    function applyFilters(list) {
        return (list || []).filter(function (r) {
            if (filters.status !== 'all') {
                if (filters.status === 'open') {
                    if (r.status === 'resolved' || r.status === 'dismissed') return false;
                } else if (r.status !== filters.status) {
                    return false;
                }
            }
            if (filters.severity !== 'all' && r.severity !== filters.severity) return false;
            if (filters.type !== 'all' && r.type !== filters.type) return false;
            return true;
        });
    }

    function renderList() {
        var view = applyFilters(rows);
        if (!view.length) {
            // An unfiltered empty feed and a filtered one mean different
            // things. Only the first is worth explaining.
            listEl.innerHTML = (filters.status === 'all' && filters.severity === 'all' && filters.type === 'all')
                ? emptyStateHtml()
                : stateBlock('solar:filter-outline', 'Nothing matches this filter',
                    'Try widening the status, severity, or type filter above.');
            return;
        }

        listEl.innerHTML = sortRows(view).map(renderRow).join('');

        if (FOCUS) {
            var el = document.getElementById('acp-card-' + FOCUS);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.classList.add('acp-flash');
                setTimeout(function () { el.classList.remove('acp-flash'); }, 2000);
            }
        }
    }

    // ---------------- loaders ----------------
    function loadSummary() {
        return api({ action: 'summary' }).then(function (d) {
            if (!d || !d.success) return;
            var s = d.summary;
            /* Trend arrows instead of sign characters. These figures are
               monthly click movements, not quantities: "minus 43" reads as a
               negative amount, while a red arrow pointing down beside 43
               reads as clicks going away. The colour on each figure still
               carries the meaning, so nothing rests on the arrow alone. */
            document.getElementById('acp-risk').innerHTML =
                '<iconify-icon icon="mdi:trending-down" aria-hidden="true"></iconify-icon>' + fmt(s.at_risk);
            document.getElementById('acp-opp').innerHTML =
                '<iconify-icon icon="mdi:trending-up" aria-hidden="true"></iconify-icon>' + fmt(s.available);
            document.getElementById('acp-recovered').innerHTML =
                '<iconify-icon icon="mdi:trending-up" aria-hidden="true"></iconify-icon>' + fmt(s.recovered);
            document.getElementById('acp-open').textContent      = fmt(s.open_count);
            document.getElementById('acp-unread').textContent    = fmt(s.unread);
            document.getElementById('acp-mark-all').disabled     = !s.unread;
        }).catch(function () { /* leave the dashes */ });
    }

    function loadAll() {
        return api({ action: 'feed', status: 'all', severity: 'all', type: 'all', limit: 200 })
            .then(function (d) {
                allRows = (d && d.success && d.rows) ? d.rows : [];
                drawSparks();
            })
            .catch(function () { allRows = []; });
    }

    function loadList() {
        return api({
            action:   'feed',
            status:   'all',
            severity: 'all',
            type:     'all',
            limit:    200
        }).then(function (d) {
            rows = (d && d.success && d.rows) ? d.rows : [];
            renderList();
        }).catch(function () {
            rows = [];
            listEl.innerHTML = stateBlock('solar:clock-circle-outline',
                'Insights not available yet',
                'Your Search Console history is still being collected. This usually takes a couple of weeks after setup.');
        });
    }

    // ---------------- detail drawer ----------------
    var drawer = document.getElementById('acp-drawer');
    var scrim  = document.getElementById('acp-scrim');
    var openId = null;
    var openRow = null;

    // position:fixed resolves against a transformed ancestor rather than the
    // viewport. The dashboard layout transforms its wrapper for the sidebar
    // animation, so move these two out to <body> before they are ever shown.
    if (drawer.parentNode !== document.body) {
        document.body.appendChild(scrim);
        document.body.appendChild(drawer);
    }

    // Each view adds a section specific to that action. Where the data does
    // not exist yet, the view says so plainly instead of showing a blank
    // panel or an invented chart.
    function viewExtra(view, r) {
        if (view === 'meta') {
            var draft = r.draft || {};
            var t = draft.title || '';
            var d = draft.description || '';
            var host = (window.location.hostname || 'your-site.com');
            return '' +
            '<div class="acp-dsec">' +
                '<p class="lbl">Write the new title</p>' +
                '<input type="text" class="acp-input" id="acp-mt" maxlength="120" ' +
                       'placeholder="e.g. Best Running Shoes 2026 \u2014 Top Rated Picks" ' +
                       'value="' + esc(t) + '">' +
                '<div class="acp-count" id="acp-mt-count"></div>' +
            '</div>' +

            '<div class="acp-dsec">' +
                '<p class="lbl">Write the new description</p>' +
                '<textarea class="acp-input acp-textarea" id="acp-md" maxlength="320" ' +
                          'placeholder="One sentence that answers the query and gives a reason to click.">' +
                          esc(d) + '</textarea>' +
                '<div class="acp-count" id="acp-md-count"></div>' +
            '</div>' +

            '<div class="acp-dsec">' +
                '<p class="lbl">How it will look in Google</p>' +
                '<div class="acp-serp">' +
                    '<div class="acp-serp-url">' + esc(host) + esc(r.entity) + '</div>' +
                    '<div class="acp-serp-title" id="acp-serp-t">Your title appears here</div>' +
                    '<div class="acp-serp-desc" id="acp-serp-d">Your description appears here.</div>' +
                '</div>' +
            '</div>' +

            '<div class="acp-dsec">' +
                '<p class="lbl">Then</p>' +
                '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
                    '<button type="button" class="acp-btn acp-btn-sm" id="acp-copy-t">' +
                        '<iconify-icon icon="solar:copy-outline" style="font-size:14px;"></iconify-icon>' +
                        'Copy title</button>' +
                    '<button type="button" class="acp-btn acp-btn-sm" id="acp-copy-d">' +
                        '<iconify-icon icon="solar:copy-outline" style="font-size:14px;"></iconify-icon>' +
                        'Copy description</button>' +
                    '<button type="button" class="acp-btn acp-btn-sm" id="acp-save-draft">' +
                        '<iconify-icon icon="solar:diskette-outline" style="font-size:14px;"></iconify-icon>' +
                        'Save draft</button>' +
                '</div>' +
                '<p class="acp-hint" id="acp-draft-msg">Paste these into the page\u2019s SEO settings ' +
                'in your Wix editor, publish, then mark this insight as actioned. ' +
                'The next daily check will confirm whether CTR recovered.</p>' +
            '</div>';
        }

        if (view === 'rank') {
            return '<div class="acp-dnote warn"><b>Rank history is still being collected</b>' +
            'Search Console only returns the current window, so trend charts are built from ' +
            'this app\u2019s own daily snapshots. The chart appears once there is enough history.</div>';
        }

        if (view === 'queries') {
            return '<div class="acp-dnote warn"><b>Query breakdown is not available yet</b>' +
            'This view lists every query driving impressions to the page. It becomes available ' +
            'once daily page and query snapshots are running.</div>';
        }

        if (view === 'keyword') {
            return '<div class="acp-dnote info"><b>How this was detected</b>' +
            'The query had no impressions in any earlier snapshot, and has now crossed the ' +
            'minimum impression threshold.</div>';
        }

        if (view === 'urls' || view === 'gsc') {
            return '<div class="acp-dnote warn"><b>Submitting URLs for indexing is not possible</b>' +
            'The URL Inspection API is read only, and the Indexing API only accepts job postings ' +
            'and broadcast events. Inspect the URLs in Search Console instead.</div>';
        }

        return '';
    }

    function openDrawer(id, view) {
        var r = null;
        for (var i = 0; i < rows.length; i++) {
            if (String(rows[i].id) === String(id)) { r = rows[i]; break; }
        }
        if (!r) return;

        openId = r.id;
        var sev    = SEV[r.severity] || SEV_FALLBACK;
        var gain   = r.impact_clicks >= 0;
        var closed = r.status === 'resolved' || r.status === 'dismissed';

        var tag = document.getElementById('acp-dtag');
        tag.textContent = r.severity;
        tag.setAttribute('style', 'background:' + sev.tag + ';color:' + sev.tagFg + ';');

        document.getElementById('acp-dtitle').textContent = r.title;
        document.getElementById('acp-dent').textContent =
            ((TYPE[r.type] || {}).label || r.type) + ' \u00b7 ' + r.entity;

        var facts = (r.what || []).map(function (p) {
            return '<tr><td>' + esc(p[0]) + '</td><td>' + esc(p[1]) + '</td></tr>';
        }).join('');

        document.getElementById('acp-dbody').innerHTML = '' +
            '<div class="acp-dimp">' +
                '<span class="n" style="color:' + (gain ? '#039855' : '#d92d20') + ';">' +
                    '<iconify-icon icon="' + (gain ? 'mdi:trending-up' : 'mdi:trending-down') + '" aria-hidden="true"></iconify-icon>' +
                    fmt(Math.abs(r.impact_clicks)) + '</span>' +
                '<span class="u">' + (gain ? 'potential clicks / month' : 'clicks at risk / month') + '</span>' +
            '</div>' +

            '<div class="acp-dsec">' +
                '<p class="lbl">What happened</p>' +
                '<table class="acp-dtable">' + facts + '</table>' +
            '</div>' +

            '<div class="acp-dsec">' +
                '<p class="lbl">Why it matters</p>' +
                '<p>' + esc(r.why_text) + '</p>' +
            '</div>' +

            (r.resolved_note
                ? '<div class="acp-dnote good"><b>Outcome</b>' + esc(r.resolved_note) + '</div>'
                : '') +

            '<div class="acp-dsec" style="--sev:' + sev.bar + ';">' +
                '<p class="lbl">Recommended action</p>' +
                '<div class="acp-drec" style="--sev:' + sev.bar + ';">' + esc(r.rec_text) + '</div>' +
                healthLine(r) +
            '</div>' +

            viewExtra(view, r) +

            '<div class="acp-dsec">' +
                '<p class="lbl">History</p>' +
                '<table class="acp-dtable">' +
                    '<tr><td>Status</td><td>' + esc(r.status) + '</td></tr>' +
                    '<tr><td>First detected</td><td>' + esc(r.first_seen) + '</td></tr>' +
                    '<tr><td>Last confirmed</td><td>' + esc(r.last_seen) + '</td></tr>' +
                    '<tr><td>Times detected</td><td>' + fmt(r.occurrences) + '</td></tr>' +
                '</table>' +
            '</div>';

        document.getElementById('acp-dfoot').innerHTML = closed
            ? '<button class="acp-btn" data-dclose="1">Close</button>' +
              '<button class="acp-btn acp-btn-primary" data-act="new" data-id="' + r.id + '">' +
              (r.status === 'dismissed' ? 'Restore' : 'Reopen') + '</button>'
            : '<button class="acp-btn" data-act="dismissed" data-id="' + r.id + '">Dismiss</button>' +
              '<button class="acp-btn" data-dclose="1">Close</button>' +
              '<button class="acp-btn acp-btn-primary" data-act="actioned" data-id="' + r.id + '">' +
              'Mark as actioned</button>';

        drawer.classList.add('is-open');
        scrim.classList.add('is-open');
        document.getElementById('acp-dclose').focus();

        openRow = r;
        if (view === 'meta') wireMetaEditor(r);

        // opening an unread insight counts as reading it
        if (r.status === 'new') {
            api({ action: 'status', id: r.id, to: 'read' })
                .then(function () { return Promise.all([loadSummary(), loadAll()]); })
                .then(function () { r.status = 'read'; renderList(); })
                .catch(function () { /* ignore */ });
        }
    }

    function closeDrawer() {
        openId = null;
        openRow = null;
        drawer.classList.remove('is-open');
        scrim.classList.remove('is-open');
    }

    // ---------------- meta editor ----------------
    // Google truncates around these widths. They are guidance, not hard
    // limits - Google re-writes titles anyway, so the counter warns rather
    // than blocks.
    var TITLE_LIMIT = 60;
    var DESC_LIMIT  = 155;

    function countClass(len, limit) {
        if (len > limit) return 'over';
        if (len > limit - 10) return 'warn';
        return '';
    }

    function wireMetaEditor(r) {
        var ti = document.getElementById('acp-mt');
        var de = document.getElementById('acp-md');
        if (!ti || !de) return;

        var tc = document.getElementById('acp-mt-count');
        var dc = document.getElementById('acp-md-count');
        var st = document.getElementById('acp-serp-t');
        var sd = document.getElementById('acp-serp-d');
        var msg = document.getElementById('acp-draft-msg');

        function sync() {
            var tl = ti.value.length;
            var dl = de.value.length;

            tc.textContent = tl + ' / ' + TITLE_LIMIT + ' characters' +
                (tl > TITLE_LIMIT ? ' - Google will cut this off' : '');
            tc.className = 'acp-count ' + countClass(tl, TITLE_LIMIT);

            dc.textContent = dl + ' / ' + DESC_LIMIT + ' characters' +
                (dl > DESC_LIMIT ? ' - Google will cut this off' : '');
            dc.className = 'acp-count ' + countClass(dl, DESC_LIMIT);

            st.textContent = ti.value || 'Your title appears here';
            sd.textContent = de.value || 'Your description appears here.';
        }

        ti.addEventListener('input', sync);
        de.addEventListener('input', sync);
        sync();

        function copy(text, label) {
            var done = function () {
                msg.textContent = label + ' copied to your clipboard.';
                msg.className = 'acp-hint ok';
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {
                    msg.textContent = 'Could not copy - select the text and copy manually.';
                    msg.className = 'acp-hint err';
                });
            } else {
                msg.textContent = 'Select the text and copy manually.';
                msg.className = 'acp-hint err';
            }
        }

        document.getElementById('acp-copy-t').addEventListener('click', function () {
            copy(ti.value, 'Title');
        });
        document.getElementById('acp-copy-d').addEventListener('click', function () {
            copy(de.value, 'Description');
        });

        document.getElementById('acp-save-draft').addEventListener('click', function () {
            var b = this;
            b.disabled = true;
            msg.textContent = 'Saving...';
            msg.className = 'acp-hint';

            api({
                action: 'draft',
                id: r.id,
                title: ti.value,
                description: de.value
            }).then(function (d) {
                b.disabled = false;
                if (d && d.success) {
                    r.draft = { title: ti.value, description: de.value };
                    msg.textContent = 'Draft saved. It will still be here when you come back.';
                    msg.className = 'acp-hint ok';
                } else {
                    msg.textContent = 'Could not save the draft. Your text is still in the box.';
                    msg.className = 'acp-hint err';
                }
            }).catch(function () {
                b.disabled = false;
                msg.textContent = 'Could not save the draft. Your text is still in the box.';
                msg.className = 'acp-hint err';
            });
        });
    }

    scrim.addEventListener('click', closeDrawer);
    document.getElementById('acp-dclose').addEventListener('click', closeDrawer);

    // ---------------- events ----------------
    document.addEventListener('click', function (e) {

        if (e.target.closest('[data-dclose]')) { closeDrawer(); return; }

        var rs = e.target.closest('[data-runspeed]');
        if (rs) { runSiteSpeedCheck(); healthRepaint(); return; }
        var rc = e.target.closest('[data-runschema]');
        if (rc) { runSiteSchemaCheck(); healthRepaint(); return; }

        var opener = e.target.closest('[data-open]');
        if (opener) {
            openDrawer(opener.getAttribute('data-open'), opener.getAttribute('data-view'));
            return;
        }

        var openMenus = document.querySelectorAll('.acp-menu');
        for (var i = 0; i < openMenus.length; i++) openMenus[i].remove();

        var kebab = e.target.closest('[data-menu]');
        if (kebab) {
            e.stopPropagation();
            var id = kebab.getAttribute('data-menu');
            var r  = null;
            for (var j = 0; j < rows.length; j++) {
                if (String(rows[j].id) === String(id)) { r = rows[j]; break; }
            }
            if (!r) return;

            var closed = r.status === 'resolved' || r.status === 'dismissed';
            var menu = document.createElement('div');
            menu.className = 'acp-menu';
            menu.innerHTML = closed
                ? '<button data-act="new" data-id="' + id + '">Reopen insight</button>'
                : '<button data-act="read" data-id="' + id + '">Mark as read</button>' +
                  '<button data-act="dismissed" data-id="' + id + '">Dismiss for 30 days</button>';
            kebab.closest('.acp-row').appendChild(menu);
            return;
        }

        var btn = e.target.closest('[data-act]');
        if (!btn) return;

        btn.disabled = true;
        api({ action: 'status', id: Number(btn.getAttribute('data-id')), to: btn.getAttribute('data-act') })
            .then(function () {
                closeDrawer();
                return Promise.all([loadSummary(), loadAll(), loadList()]);
            })
            .catch(function () { btn.disabled = false; });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var menus = document.querySelectorAll('.acp-menu');
        for (var i = 0; i < menus.length; i++) menus[i].remove();
        if (drawer.classList.contains('is-open')) closeDrawer();
    });

    document.getElementById('acp-sort').addEventListener('change', function () {
        sortBy = this.value;
        renderList();
    });

    document.getElementById('acp-refresh').addEventListener('click', function () {
        var b = this;
        b.disabled = true;
        Promise.all([loadSummary(), loadAll(), loadList()])
            .then(function () { b.disabled = false; })
            .catch(function () { b.disabled = false; });
    });

    document.getElementById('acp-mark-all').addEventListener('click', function () {
        api({ action: 'mark_all' })
            .then(function () { return Promise.all([loadSummary(), loadAll(), loadList()]); })
            .catch(function () { /* ignore */ });
    });

    renderFilters();
    loadSummary();
    loadAll();
    loadList();
})();
</script>

<?php endif; ?>

<?php include './partials/layouts/layoutBottom.php'; ?>