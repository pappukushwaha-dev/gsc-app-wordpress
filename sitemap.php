<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/google/get_account.php';

session_start();
// Both spellings. auth/callback.php writes 'instance_id' and pricing.php
// writes 'instanceid', so reading only one drops users at sign-in in the
// middle of a working session.
$instanceId = $_SESSION['instanceid']
    ?? $_SESSION['instance_id']
    ?? null;

if (!$instanceId) {
    header("Location: sign-in.php");
    exit();
}

$title    = "Sitemap Management";
$subTitle = "SEO Tools";

// ---------------------------------------------------------------------
//  CONNECTION + PROPERTY
// ---------------------------------------------------------------------
$google      = getGoogleAccountByInstance($instanceId);
$isConnected = ($google && !empty($google['access_token']));

$stmt = $pdo->prepare("SELECT site_url FROM gsc_domain_verifications WHERE instance_id=? LIMIT 1");
$stmt->execute([$instanceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$siteUrl = null;

if (!empty($row['site_url'])) {
    $stored = trim($row['site_url']);

    if (stripos($stored, 'sc-domain:') === 0) {
        // A domain property covers both www and non-www. We cannot tell from
        // here which one actually serves the sitemap, so keep the host as
        // stored rather than stripping www — stripping it produced wrong
        // sitemap URLs for sites that only answer on www.
        $siteUrl = 'https://' . substr($stored, strlen('sc-domain:'));
    } else {
        $siteUrl = rtrim($stored, '/');
    }
}

// Prefilled suggestion for the add form, not the only sitemap allowed
$defaultSitemapUrl = $siteUrl ? $siteUrl . '/sitemap.xml' : '';

// ---------------------------------------------------------------------
//  ALL SITEMAPS FOR THIS INSTANCE
//
//  The old page read a single row with LIMIT 1. A site can have several
//  sitemaps — a Wix store alone produces pages, categories and products
//  files — so all of them are loaded and sorted with problems first.
// ---------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT sitemap_url, domain, last_submitted_at, last_downloaded, status,
           submission_count, last_status_message, is_index, warnings, errors,
           discovered_pages, last_synced_at, created_at, updated_at
    FROM sitemaps
    WHERE instance_id = ?
    ORDER BY last_submitted_at DESC, id DESC
");
$stmt->execute([$instanceId]);
$sitemaps = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Work out what state a sitemap is really in.
 *
 * "Submitted successfully" only means Google accepted the request — it says
 * nothing about whether Google could read the file. The honest signal is in
 * errors / warnings / last_downloaded, and those are only filled in by a
 * sync, so a never-synced row is reported as unknown rather than healthy.
 *
 * @return array{key:string,label:string,tone:string,message:string}
 */
function sitemapState(array $s): array
{
    $errors    = (int)($s['errors'] ?? 0);
    $warnings  = (int)($s['warnings'] ?? 0);
    $lastRead  = $s['last_downloaded'] ?? null;
    $status    = $s['status'] ?? 'Pending';

    if ($status === 'Error' || $errors > 0) {
        return [
            'key'     => 'error',
            'label'   => 'Error',
            'tone'    => 'bad',
            'short'   => 'needs fixing',
            'message' => 'Google reported an error reading this sitemap. Open the URL in a browser to check it exists and returns XML.',
        ];
    }

    if ($warnings > 0) {
        return [
            'key'     => 'warning',
            'label'   => $warnings . ' warning' . ($warnings === 1 ? '' : 's'),
            'tone'    => 'warn',
            'short'   => 'review in Search Console',
            'message' => 'Google read the file but could not use every URL in it. Open the sitemap report in Search Console to see which ones.',
        ];
    }

    // Two different situations both leave last_downloaded NULL, and telling
    // them apart is the whole reason last_synced_at exists. Reporting them
    // as one state meant asking users to sync things that were already
    // synced.
    if (!$lastRead) {
        $synced = $s['last_synced_at'] ?? null;

        if (!$synced) {
            return [
                'key'     => 'unsynced',
                'label'   => 'Status unknown',
                'tone'    => 'muted',
                'short'   => 'press Sync',
                'message' => 'This sitemap has been submitted, but its status has never been pulled from Google. Press Sync to find out whether Google has read it.',
            ];
        }

        return [
            'key'     => 'pending',
            'label'   => 'Waiting for Google',
            'tone'    => 'info',
            'short'   => 'nothing to do',
            'message' => 'Google knows about this sitemap but has not read it yet. Nothing needs doing — Google crawls on its own schedule, and a newly submitted sitemap usually takes a day or two.',
        ];
    }

    return [
        'key'     => 'ok',
        'label'   => 'Success',
        'tone'    => 'ok',
        'short'   => 'all good',
        'message' => 'Google has read this sitemap with no errors or warnings. Nothing to do here.',
    ];
}

// ---------------------------------------------------------------------
//  SUMMARY
// ---------------------------------------------------------------------
$totalSitemaps = count($sitemaps);
$indexCount    = 0;
$needsAttn     = 0;
$errorCount    = 0;
$warnCount     = 0;
$lastReadAny   = null;
$neverSynced   = true;
$totalPages    = null;   // null until at least one sitemap has been synced

foreach ($sitemaps as $s) {
    if (!empty($s['is_index'])) $indexCount++;

    if ($s['discovered_pages'] !== null) {
        $totalPages = (int)$totalPages + (int)$s['discovered_pages'];
    }

    $st = sitemapState($s);
    if ($st['key'] === 'error')   { $errorCount++; $needsAttn++; }
    if ($st['key'] === 'warning') { $warnCount++;  $needsAttn++; }

    if (!empty($s['last_downloaded'])) {
        $neverSynced = false;
        if (!$lastReadAny || $s['last_downloaded'] > $lastReadAny) {
            $lastReadAny = $s['last_downloaded'];
        }
    }
}

// ---------------------------------------------------------------------
//  SUBMISSION HISTORY
//
//  Failures are kept. The old query filtered to status = 'Success', so a
//  user could never see what went wrong — which is exactly what you want
//  the history for.
// ---------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT sitemap_url, status, http_code, google_response, submitted_at
    FROM sitemap_submission_logs
    WHERE instance_id = ?
    ORDER BY id DESC
    LIMIT 200
");
$stmt->execute([$instanceId]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// A submission log on its own is misleading: every row says the request was
// accepted, which tells you nothing about whether Google could read the
// file. Attaching each sitemap's current state answers the question people
// actually have — "I submitted this, so what happened?"
$stateByUrl = [];
foreach ($sitemaps as $sm) {
    $st = sitemapState($sm);
    $stateByUrl[$sm['sitemap_url']] = [
        'label' => $st['label'],
        'tone'  => $st['tone'],
    ];
}

$history = array_map(function (array $log) use ($stateByUrl, $siteUrl) {
    $url = $log['sitemap_url'];

    $log['short_url']    = ($siteUrl && str_starts_with($url, $siteUrl))
                           ? substr($url, strlen($siteUrl))
                           : $url;
    $log['google_state'] = $stateByUrl[$url] ?? null;

    return $log;
}, $history);

include './partials/layouts/layoutTop.php';
?>

<script>
    window.APP_BASE  = '<?= rtrim(APP_BASE, "/") ?>';
    window.GSC_INSTANCE = <?= json_encode($instanceId) ?>;
</script>

<style>
/* =======================================================================
   Sitemap Manager — premium surface
   Scoped to .smx- so nothing here can leak into the rest of the app.
   ======================================================================= */
.smx {
    --sx-ink:      #0f172a;
    --sx-ink-2:    #475569;
    --sx-ink-3:    #94a3b8;
    --sx-line:     #eaeef4;
    --sx-line-2:   #e2e8f0;
    --sx-surface:  #ffffff;
    --sx-blue:     #2563eb;
    --sx-blue-2:   #3b82f6;
    --sx-purple:   #7c3aed;
    --sx-green:    #059669;
    --sx-amber:    #d97706;
    --sx-red:      #dc2626;
    --sx-shadow:   0 1px 2px rgba(15,23,42,.04), 0 1px 3px rgba(15,23,42,.05);
    --sx-shadow-2: 0 4px 12px rgba(15,23,42,.06), 0 12px 32px rgba(15,23,42,.07);
    --sx-mono: ui-monospace, "SF Mono", SFMono-Regular, Menlo, Consolas, monospace;
    color: var(--sx-ink);
}
.dark .smx {
    --sx-ink:     #f1f5f9;
    --sx-ink-2:   #cbd5e1;
    --sx-ink-3:   #94a3b8;
    --sx-line:    #3f3f46;
    --sx-line-2:  #52525b;
    --sx-surface: #262626;
}

.smx-card {
    background: var(--sx-surface);
    border: 1px solid var(--sx-line);
    border-radius: 16px;
    box-shadow: var(--sx-shadow);
}

/* ---------------- header ---------------- */
.smx-head { padding: 26px 28px; display:flex; align-items:flex-start;
            justify-content:space-between; gap:24px; flex-wrap:wrap; }
.smx-head-l { display:flex; align-items:flex-start; gap:16px; min-width:0; }
.smx-head-ic {
    width:48px; height:48px; border-radius:14px; flex:none;
    background:linear-gradient(135deg,#eff4ff 0%,#e6edff 100%);
    border:1px solid #dbe6ff;
    display:flex; align-items:center; justify-content:center;
}
.dark .smx-head-ic { background:#1e293b; border-color:#334155; }
.smx-head-ic iconify-icon { font-size:24px; color:var(--sx-blue); }
.smx-title { margin:0; font-size:24px; font-weight:700; letter-spacing:-.025em; line-height:1.2; }
.smx-sub { margin:5px 0 0; font-size:13.5px; color:var(--sx-ink-3); }

.smx-domain {
    display:inline-flex; align-items:center; gap:7px; margin-top:10px;
    padding:5px 12px; border-radius:999px;
    background:#f8fafc; border:1px solid var(--sx-line-2);
    font-family:var(--sx-mono); font-size:12px; color:var(--sx-ink-2);
    max-width:100%; overflow:hidden;
}
.dark .smx-domain { background:#1e1e1e; }
.smx-domain iconify-icon { font-size:13px; color:var(--sx-green); flex:none; }
.smx-domain span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

.smx-head-r { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.smx-stamp { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--sx-ink-3); }
.smx-stamp i { width:6px; height:6px; border-radius:50%; background:var(--sx-green);
               display:inline-block; box-shadow:0 0 0 3px rgba(5,150,105,.14); }

.smx-btn {
    height:40px; padding:0 17px; border-radius:11px; cursor:pointer;
    font-size:13.5px; font-weight:550; font-family:inherit;
    display:inline-flex; align-items:center; gap:8px;
    border:1px solid var(--sx-line-2); background:var(--sx-surface); color:var(--sx-ink);
    transition:all .16s cubic-bezier(.4,0,.2,1);
    box-shadow:var(--sx-shadow);
}
.smx-btn:hover { border-color:#cbd5e1; transform:translateY(-1px); box-shadow:var(--sx-shadow-2); }
.smx-btn:active { transform:translateY(0); }
.smx-btn:focus-visible { outline:none; box-shadow:0 0 0 3px rgba(37,99,235,.18); }
.smx-btn iconify-icon { font-size:16px; }
.smx-btn[disabled] { opacity:.6; cursor:not-allowed; transform:none; }

.smx-btn-primary {
    background:linear-gradient(180deg,var(--sx-blue-2) 0%,var(--sx-blue) 100%);
    border-color:var(--sx-blue); color:#fff;
    box-shadow:0 1px 2px rgba(37,99,235,.28), 0 4px 12px rgba(37,99,235,.2);
}
.smx-btn-primary:hover { box-shadow:0 2px 6px rgba(37,99,235,.32), 0 10px 24px rgba(37,99,235,.26); }

/* ---------------- KPI cards ---------------- */
.smx-kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; }
.smx-kpi {
    position:relative; overflow:hidden;
    background:var(--sx-surface); border:1px solid var(--sx-line);
    border-radius:16px; padding:20px;
    box-shadow:var(--sx-shadow);
    transition:transform .18s cubic-bezier(.4,0,.2,1), box-shadow .18s, border-color .18s;
}
.smx-kpi:hover { transform:translateY(-2px); box-shadow:var(--sx-shadow-2); border-color:var(--sx-line-2); }
.smx-kpi::after {
    content:""; position:absolute; inset:0; pointer-events:none;
    background:linear-gradient(135deg, var(--kpi-wash, transparent) 0%, transparent 62%);
    opacity:.6;
}
.smx-kpi > * { position:relative; z-index:1; }
.smx-kpi-top { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
.smx-kpi-ic { width:38px; height:38px; border-radius:11px; display:flex;
              align-items:center; justify-content:center; background:var(--kpi-bg,#f1f5f9); }
.smx-kpi-ic iconify-icon { font-size:19px; color:var(--kpi-fg,#64748b); }
.smx-kpi-tag {
    font-size:10.5px; font-weight:650; padding:3px 9px; border-radius:999px;
    background:var(--kpi-bg,#f1f5f9); color:var(--kpi-fg,#64748b);
}
.smx-kpi-v { font-size:29px; font-weight:750; letter-spacing:-.035em; line-height:1;
             font-variant-numeric:tabular-nums; }
.smx-kpi-v.sm { font-size:17px; letter-spacing:-.02em; }
.smx-kpi-v .unit { font-size:13px; font-weight:500; color:var(--sx-ink-3); margin-left:5px; letter-spacing:0; }
.smx-kpi-k { font-size:13px; font-weight:550; color:var(--sx-ink-2); margin-top:9px; }
.smx-kpi-s { font-size:11.5px; color:var(--sx-ink-3); margin-top:3px; }

/* ---------------- info banner ---------------- */
.smx-banner {
    display:flex; gap:15px; align-items:flex-start;
    padding:18px 20px; border-radius:15px;
    background:linear-gradient(135deg,#f5f9ff 0%,#eef5ff 100%);
    border:1px solid #d8e6fe;
}
.dark .smx-banner { background:#172033; border-color:#2c3e5c; }
.smx-banner-ic {
    width:38px; height:38px; border-radius:11px; flex:none;
    background:#dbeafe; display:flex; align-items:center; justify-content:center;
}
.dark .smx-banner-ic { background:#1e3a5f; }
.smx-banner-ic iconify-icon { font-size:19px; color:var(--sx-blue); }
.smx-banner h4 { margin:0 0 4px; font-size:14px; font-weight:650; color:#1e3a8a; }
.dark .smx-banner h4 { color:#bfdbfe; }
.smx-banner p { margin:0; font-size:13px; line-height:1.6; color:#3b5b8c; }
.dark .smx-banner p { color:#93b4dd; }
.smx-banner-act { flex:none; align-self:center; }

/* ---------------- section head ---------------- */
.smx-sec { display:flex; align-items:center; justify-content:space-between;
           gap:14px; padding:0 4px; margin-bottom:2px; }
.smx-sec h2 { margin:0; font-size:16px; font-weight:650; letter-spacing:-.015em;
              display:flex; align-items:center; gap:9px; }
.smx-sec h2 iconify-icon { font-size:18px; color:var(--sx-blue); }
.smx-sec .hint { font-size:12.5px; color:var(--sx-ink-3); }

/* ---------------- sitemap cards ---------------- */
.smx-list { display:flex; flex-direction:column; gap:12px; }

.smx-sm {
    background:var(--sx-surface); border:1px solid var(--sx-line);
    border-radius:15px; overflow:hidden; box-shadow:var(--sx-shadow);
    transition:box-shadow .18s cubic-bezier(.4,0,.2,1), border-color .18s;
}
.smx-sm:hover { box-shadow:var(--sx-shadow-2); border-color:var(--sx-line-2); }
.smx-sm.is-open { border-color:#cdd9ee; box-shadow:var(--sx-shadow-2); }

.smx-sm-row {
    display:flex; align-items:center; gap:16px; padding:17px 20px;
    cursor:pointer; user-select:none; transition:background .14s;
}
.smx-sm-row:hover { background:#fcfdfe; }
.dark .smx-sm-row:hover { background:#2d2d2d; }

.smx-sm-chev { color:var(--sx-ink-3); display:inline-flex; flex:none;
               transition:transform .22s cubic-bezier(.4,0,.2,1); }
.smx-sm-chev iconify-icon { font-size:17px; }
.smx-sm.is-open .smx-sm-chev { transform:rotate(90deg); color:var(--sx-blue); }

.smx-sm-ic { width:40px; height:40px; border-radius:12px; flex:none;
             display:flex; align-items:center; justify-content:center;
             background:var(--sm-bg,#f1f5f9); }
.smx-sm-ic iconify-icon { font-size:20px; color:var(--sm-fg,#64748b); }

.smx-sm-id { flex:1; min-width:0; }
.smx-sm-name { font-family:var(--sx-mono); font-size:13.5px; font-weight:600;
               overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.smx-sm-meta { margin-top:4px; font-size:11.5px; color:var(--sx-ink-3);
               display:flex; gap:14px; flex-wrap:wrap; }
.smx-hintline { display:inline-flex; align-items:center; gap:5px; }
.smx-hintline iconify-icon { font-size:13px; }
.smx-hintline.act { color:var(--sx-blue); cursor:pointer; font-weight:550; }
.smx-hintline.act:hover { text-decoration:underline; }

.smx-sm-stat { flex:none; text-align:right; min-width:74px; }
.smx-sm-stat .n { font-size:19px; font-weight:700; letter-spacing:-.025em; line-height:1;
                  font-variant-numeric:tabular-nums; }
.smx-sm-stat .u { font-size:10.5px; color:var(--sx-ink-3); margin-top:4px; }

.smx-acts { flex:none; display:flex; gap:7px; }

/* ---------------- badges ---------------- */
.smx-badge {
    display:inline-flex; align-items:center; gap:5px; flex:none;
    font-size:11.5px; font-weight:600; padding:4px 11px; border-radius:999px;
    border:1px solid transparent; white-space:nowrap;
}
.smx-badge i { width:5px; height:5px; border-radius:50%; background:currentColor; }
.smx-badge.ok    { background:#ecfdf5; color:#047857; border-color:#c7f0e0; }
.smx-badge.warn  { background:#fffbeb; color:#b45309; border-color:#fde9c0; }
.smx-badge.bad   { background:#fef2f2; color:#b91c1c; border-color:#fbd5d5; }
.smx-badge.info  { background:#eff6ff; color:#1d4ed8; border-color:#cfe0fd; }
.smx-badge.muted { background:#f8fafc; color:#64748b; border-color:#e2e8f0; }
.dark .smx-badge.ok    { background:rgba(5,150,105,.16);  border-color:rgba(5,150,105,.3);  color:#6ee7b7; }
.dark .smx-badge.warn  { background:rgba(217,119,6,.16);  border-color:rgba(217,119,6,.3);  color:#fcd34d; }
.dark .smx-badge.bad   { background:rgba(220,38,38,.16);  border-color:rgba(220,38,38,.3);  color:#fca5a5; }
.dark .smx-badge.info  { background:rgba(37,99,235,.16);  border-color:rgba(37,99,235,.3);  color:#93c5fd; }
.dark .smx-badge.muted { background:rgba(148,163,184,.14);border-color:rgba(148,163,184,.26);color:#cbd5e1; }

.smx-tag {
    font-size:10px; font-weight:700; letter-spacing:.045em; text-transform:uppercase;
    padding:3px 8px; border-radius:6px; background:#f1f5f9; color:#64748b; flex:none;
}
.smx-tag.idx { background:#f3e8ff; color:var(--sx-purple); }
.dark .smx-tag { background:#3f3f46; color:#d4d4d8; }
.dark .smx-tag.idx { background:rgba(124,58,237,.2); color:#c4b5fd; }

/* ---------------- icon buttons ---------------- */
.smx-ibtn {
    width:34px; height:34px; border-radius:10px; flex:none;
    border:1px solid var(--sx-line-2); background:var(--sx-surface); color:var(--sx-ink-3);
    cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition:all .16s cubic-bezier(.4,0,.2,1); box-shadow:var(--sx-shadow);
    text-decoration:none;
}
.smx-ibtn:hover { color:var(--sx-ink-2); border-color:#cbd5e1;
                  transform:translateY(-1px); box-shadow:var(--sx-shadow-2); }
.smx-ibtn:active { transform:translateY(0); }
.smx-ibtn:focus-visible { outline:none; box-shadow:0 0 0 3px rgba(37,99,235,.18); }
.smx-ibtn iconify-icon { font-size:16px; }
.smx-ibtn.danger:hover { color:var(--sx-red); border-color:#fca5a5; background:#fef2f2; }
.dark .smx-ibtn.danger:hover { background:rgba(220,38,38,.12); }
.smx-ibtn[disabled] { opacity:.5; cursor:not-allowed; transform:none; }

/* ---------------- expandable detail ---------------- */
.smx-detail {
    overflow:hidden; max-height:0; opacity:0;
    transition:max-height .32s cubic-bezier(.4,0,.2,1), opacity .22s;
    border-top:1px solid transparent;
}
.smx-sm.is-open .smx-detail {
    max-height:1400px; opacity:1; border-top-color:var(--sx-line);
}
.smx-detail-in { padding:20px 22px 22px; background:#fbfcfe; }
.dark .smx-detail-in { background:#212121; }

.smx-alert { display:flex; gap:13px; align-items:flex-start;
             padding:14px 16px; border-radius:13px; margin-bottom:18px; max-width:820px; }
.smx-alert-ic { width:32px; height:32px; border-radius:10px; flex:none;
                display:flex; align-items:center; justify-content:center; }
.smx-alert-ic iconify-icon { font-size:17px; }
.smx-alert h5 { margin:0 0 3px; font-size:12.5px; font-weight:700;
                letter-spacing:.03em; text-transform:uppercase; }
.smx-alert p { margin:0; font-size:13px; line-height:1.6; color:var(--sx-ink-2); }

.smx-alert.ok    { background:#f0fdf9; border:1px solid #c7f0e0; }
.smx-alert.ok    .smx-alert-ic { background:#d1fae5; } .smx-alert.ok    .smx-alert-ic iconify-icon { color:#047857; }
.smx-alert.ok    h5 { color:#047857; }
.smx-alert.warn  { background:#fffbeb; border:1px solid #fde9c0; }
.smx-alert.warn  .smx-alert-ic { background:#fef3c7; } .smx-alert.warn  .smx-alert-ic iconify-icon { color:#b45309; }
.smx-alert.warn  h5 { color:#b45309; }
.smx-alert.bad   { background:#fef4f4; border:1px solid #fbd5d5; }
.smx-alert.bad   .smx-alert-ic { background:#fee2e2; } .smx-alert.bad   .smx-alert-ic iconify-icon { color:#b91c1c; }
.smx-alert.bad   h5 { color:#b91c1c; }
.smx-alert.info  { background:#f5f9ff; border:1px solid #d8e6fe; }
.smx-alert.info  .smx-alert-ic { background:#dbeafe; } .smx-alert.info  .smx-alert-ic iconify-icon { color:#2563eb; }
.smx-alert.info  h5 { color:#1d4ed8; }
.smx-alert.muted { background:#f8fafc; border:1px solid #e2e8f0; }
.smx-alert.muted .smx-alert-ic { background:#e9eef5; } .smx-alert.muted .smx-alert-ic iconify-icon { color:#64748b; }
.smx-alert.muted h5 { color:#64748b; }
.dark .smx-alert { background:#2a2a2a; border-color:#3f3f46; }

.smx-facts { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
             gap:16px; max-width:820px; }
.smx-fact { padding:12px 14px; border-radius:11px; background:var(--sx-surface);
            border:1px solid var(--sx-line); }
.smx-fk { font-size:10.5px; color:var(--sx-ink-3); margin-bottom:5px;
          letter-spacing:.02em; text-transform:uppercase; font-weight:600; }
.smx-fv { font-size:14px; font-weight:650; font-variant-numeric:tabular-nums; }
.smx-fv.bad  { color:var(--sx-red); }
.smx-fv.warn { color:var(--sx-amber); }
.smx-fv.mute { color:var(--sx-ink-3); font-weight:600; }

.smx-url-box {
    margin-top:16px; padding:12px 14px; border-radius:11px;
    background:var(--sx-surface); border:1px solid var(--sx-line);
    display:flex; align-items:center; gap:11px; max-width:820px;
}
.smx-url-box iconify-icon { font-size:15px; color:var(--sx-ink-3); flex:none; }
.smx-url-box a { font-family:var(--sx-mono); font-size:12px; color:var(--sx-blue);
                 text-decoration:none; word-break:break-all; }
.smx-url-box a:hover { text-decoration:underline; }

/* "View pages" on the card itself */
.smx-own { margin-top:16px; }
.smx-btn-sm { height:34px; padding:0 14px; font-size:12.5px; border-radius:10px; }
.smx-btn-count {
    margin-left:2px; padding:1px 7px; border-radius:999px;
    background:#eef2f7; color:var(--sx-ink-3);
    font-size:11px; font-weight:700; font-variant-numeric:tabular-nums;
}
.dark .smx-btn-count { background:#3f3f46; color:#a1a1aa; }
.js-own-list { margin-top:12px; max-width:820px; }

.smx-kids { margin-top:18px; padding-top:16px; border-top:1px solid var(--sx-line); }

/* children laid out like Search Console's drill-down */
.smx-kidtbl { width:100%; border-collapse:collapse; }
.smx-kidtbl thead th {
    text-align:left; font-size:10px; font-weight:700; color:var(--sx-ink-3);
    text-transform:uppercase; letter-spacing:.05em; padding:9px 12px;
    border-bottom:1px solid var(--sx-line); white-space:nowrap;
}
.smx-kidtbl thead th.r { text-align:right; }
.smx-kidtbl tbody td { padding:11px 12px; border-bottom:1px solid var(--sx-line);
                       font-size:12.5px; vertical-align:middle; }
.smx-kidtbl tbody tr:last-child td { border-bottom:0; }
.smx-kidtbl td.r { text-align:right; }
.smx-kidtbl td.mono { font-family:var(--sx-mono); color:var(--sx-ink-2); }
.smx-kidtbl td.dim { color:var(--sx-ink-3); white-space:nowrap; }
.smx-kidtbl td.num { font-weight:650; font-variant-numeric:tabular-nums; }
.smx-extra { display:block; font-size:10px; font-weight:600; color:var(--sx-ink-3);
             margin-top:2px; letter-spacing:.01em; }
.smx-kidrow:hover td { background:#f8fafc; }
.dark .smx-kidrow:hover td { background:#2f2f2f; }

.smx-linkbtn {
    border:0; background:none; padding:0; cursor:pointer; font-family:inherit;
    font-size:12px; font-weight:600; color:var(--sx-blue); white-space:nowrap;
}
.smx-linkbtn:hover { text-decoration:underline; }

/* the page list inside one sitemap */
.smx-urls { padding:4px 0 10px; }
.smx-urls-h { font-size:11px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
              color:var(--sx-ink-3); margin-bottom:9px; }
.smx-urls-list { max-height:320px; overflow-y:auto; border:1px solid var(--sx-line);
                 border-radius:11px; background:var(--sx-surface);
                 scrollbar-width:thin; scrollbar-color:#d1d5db transparent; }
.smx-urls-list::-webkit-scrollbar { width:6px; }
.smx-urls-list::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:999px; }
.smx-url-row { display:flex; align-items:center; justify-content:space-between; gap:14px;
               padding:9px 13px; border-bottom:1px solid var(--sx-line); }
.smx-url-row:last-child { border-bottom:0; }
.smx-url-row:hover { background:#f8fafc; }
.dark .smx-url-row:hover { background:#2f2f2f; }
.smx-url-row a { font-family:var(--sx-mono); font-size:12px; color:var(--sx-blue);
                 text-decoration:none; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.smx-url-row a:hover { text-decoration:underline; }
.smx-url-mod { font-size:11px; color:var(--sx-ink-3); white-space:nowrap; flex:none; }
.smx-urls-more { margin-top:9px; font-size:11.5px; color:var(--sx-ink-3); }
.smx-urls-loading, .smx-urls-empty { padding:14px 4px; font-size:12.5px; color:var(--sx-ink-3); }
.smx-kidurls td { background:#fbfcfe !important; padding:0 12px !important; }
.dark .smx-kidurls td { background:#212121 !important; }
.smx-kids-h { font-size:10.5px; font-weight:700; letter-spacing:.05em; text-transform:uppercase;
              color:var(--sx-ink-3); margin-bottom:8px; }
.smx-kid { display:flex; align-items:center; gap:12px; padding:10px 12px;
           border-radius:10px; transition:background .14s; }
.smx-kid:hover { background:#f1f5f9; }
.dark .smx-kid:hover { background:#2f2f2f; }
.smx-kid-dot { width:6px; height:6px; border-radius:50%; flex:none; }
.smx-kid-name { flex:1; font-family:var(--sx-mono); font-size:12.5px; color:var(--sx-ink-2);
                overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.smx-kid-when { font-size:11.5px; color:var(--sx-ink-3); white-space:nowrap; }
.smx-kid-n { font-size:12.5px; font-weight:650; min-width:48px; text-align:right;
             font-variant-numeric:tabular-nums; }

/* ---------------- add panel ---------------- */
/* locked domain + editable path, joined into one field */
.smx-combo {
    flex:1; min-width:280px; display:flex; align-items:stretch;
    height:48px; border-radius:13px; overflow:hidden;
    border:1px solid var(--sx-line-2); background:#fbfcfe;
    transition:all .16s;
}
.smx-combo:focus-within { background:var(--sx-surface); border-color:var(--sx-blue-2);
                          box-shadow:0 0 0 4px rgba(37,99,235,.1); }
.dark .smx-combo { background:#1e1e1e; }

.smx-combo-prefix {
    display:flex; align-items:center; padding:0 4px 0 16px;
    font-family:var(--sx-mono); font-size:13px; color:var(--sx-ink-3);
    background:#f1f5f9; border-right:1px solid var(--sx-line-2);
    white-space:nowrap; max-width:52%; overflow:hidden; text-overflow:ellipsis;
    user-select:none; flex:none;
}
.dark .smx-combo-prefix { background:#2a2a2a; }

.smx-combo-input {
    flex:1; min-width:0; border:0; outline:none; background:transparent;
    padding:0 16px; font-family:var(--sx-mono); font-size:13.5px; color:var(--sx-ink);
}
.smx-combo-input::placeholder { color:var(--sx-ink-3); }

.smx-add-wrap { position:relative; flex:1; min-width:260px; }
.smx-add-wrap > iconify-icon {
    position:absolute; left:15px; top:50%; transform:translateY(-50%);
    font-size:17px; color:var(--sx-ink-3); pointer-events:none;
}
.smx-input {
    width:100%; height:48px; padding:0 16px 0 44px; border-radius:13px;
    border:1px solid var(--sx-line-2); background:#fbfcfe; color:var(--sx-ink);
    font-family:var(--sx-mono); font-size:13.5px;
    transition:all .16s;
}
.smx-input::placeholder { color:var(--sx-ink-3); }
.smx-input:focus { outline:none; background:var(--sx-surface); border-color:var(--sx-blue-2);
                   box-shadow:0 0 0 4px rgba(37,99,235,.1); }
.dark .smx-input { background:#1e1e1e; }

.smx-chips { display:flex; align-items:center; gap:8px; margin-top:15px; flex-wrap:wrap; }
.smx-chips-lbl { font-size:12px; color:var(--sx-ink-3); }
.smx-chip {
    height:30px; padding:0 13px; border-radius:9px; border:1px solid var(--sx-line-2);
    background:var(--sx-surface); color:var(--sx-ink-2);
    font-family:var(--sx-mono); font-size:11.5px; cursor:pointer;
    transition:all .15s; box-shadow:var(--sx-shadow);
}
.smx-chip:hover { border-color:var(--sx-blue-2); color:var(--sx-blue);
                  background:#f6f9ff; transform:translateY(-1px); }
.dark .smx-chip:hover { background:rgba(37,99,235,.12); }

.smx-help { margin:16px 0 0; font-size:12.5px; color:var(--sx-ink-3);
            display:flex; gap:8px; align-items:flex-start; line-height:1.6; }
.smx-help iconify-icon { font-size:15px; flex:none; margin-top:1px; color:var(--sx-green); }

/* ---------------- history ---------------- */
.smx-tbl { width:100%; border-collapse:collapse; }
.smx-tbl thead th {
    text-align:left; font-size:10.5px; font-weight:700; color:var(--sx-ink-3);
    text-transform:uppercase; letter-spacing:.06em; padding:12px 22px;
    background:#fbfcfe; border-bottom:1px solid var(--sx-line); white-space:nowrap;
}
.dark .smx-tbl thead th { background:#212121; }
.smx-tbl tbody td { padding:14px 22px; border-bottom:1px solid var(--sx-line); font-size:13px; }
.smx-tbl tbody tr:last-child td { border-bottom:0; }
.smx-tbl tbody tr { transition:background .14s; }
.smx-tbl tbody tr:hover td { background:#fbfcfe; }
.dark .smx-tbl tbody tr:hover td { background:#2d2d2d; }
.smx-tbl .mono { font-family:var(--sx-mono); font-size:12.5px; }
.smx-tbl .dim { color:var(--sx-ink-3); }
.smx-ago { display:block; font-size:10.5px; color:var(--sx-ink-3); opacity:.75; margin-top:2px; }
.smx-dimtxt { font-size:11.5px; color:var(--sx-ink-3); }


.smx-pager { display:flex; align-items:center; justify-content:flex-end; gap:6px;
             padding:14px 22px; border-top:1px solid var(--sx-line); }
.smx-pg {
    min-width:34px; height:34px; padding:0 11px; border-radius:9px;
    border:1px solid var(--sx-line-2); background:var(--sx-surface);
    color:var(--sx-ink-2); font-size:12.5px; font-weight:550; font-family:inherit;
    cursor:pointer; display:inline-flex; align-items:center; justify-content:center;
    transition:all .15s;
}
.smx-pg:hover:not([disabled]) { border-color:#cbd5e1; background:#f8fafc; }
.smx-pg.on { background:var(--sx-blue); border-color:var(--sx-blue); color:#fff; }
.smx-pg[disabled] { opacity:.4; cursor:not-allowed; }

/* ---------------- empty ---------------- */
.smx-empty { padding:64px 28px; text-align:center; }
.smx-empty-ic { width:60px; height:60px; border-radius:18px; margin:0 auto 18px;
                background:linear-gradient(135deg,#f1f5f9 0%,#e9eef5 100%);
                border:1px solid var(--sx-line-2);
                display:flex; align-items:center; justify-content:center; }
.smx-empty-ic iconify-icon { font-size:28px; color:var(--sx-ink-3); }
.smx-empty h4 { margin:0 0 7px; font-size:16px; font-weight:650; }
.smx-empty p { margin:0 auto; font-size:13.5px; color:var(--sx-ink-3);
               max-width:430px; line-height:1.65; }

/* ---------------- locked ---------------- */
.smx-locked { padding:72px 28px; text-align:center; }
.smx-locked-ic { width:76px; height:76px; border-radius:24px; margin:0 auto 22px;
                 background:linear-gradient(135deg,#f1f5f9 0%,#e2e8f0 100%);
                 border:1px solid var(--sx-line-2);
                 display:flex; align-items:center; justify-content:center; }
.smx-locked-ic iconify-icon { font-size:34px; color:var(--sx-ink-3); }

.smx-scroll { scrollbar-width:thin; scrollbar-color:#d1d5db transparent; }
.smx-scroll::-webkit-scrollbar { height:6px; }
.smx-scroll::-webkit-scrollbar-track { background:transparent; }
.smx-scroll::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:999px; }

@media (max-width:1100px) { .smx-kpis { grid-template-columns:repeat(2,1fr); } }
@media (max-width:720px) {
    .smx-head { padding:20px; }
    .smx-head-r { width:100%; }
    .smx-kpis { grid-template-columns:1fr; }
    .smx-sm-row { flex-wrap:wrap; gap:12px; }
    .smx-sm-stat { min-width:auto; text-align:left; }
    .smx-banner { flex-wrap:wrap; }
    .smx-banner-act { width:100%; }
    .smx-detail-in { padding:16px; }
}
@media (prefers-reduced-motion: reduce) {
    .smx-kpi, .smx-btn, .smx-ibtn, .smx-chip, .smx-detail, .smx-sm-chev { transition:none; }
}

/* =======================================================================
   Confirm dialog
   Scoped to .gscm- and deliberately NOT nested under .smx, because the
   markup sits outside .page-wrapper — a parent with overflow or a
   transform would otherwise clip a fixed-position child.

   This exists because the browser's own confirm() and alert() are drawn
   by the browser at the top of the window. They cannot be centred, styled
   or themed, so the only way to move that box is to stop using it.
   ======================================================================= */
.gscm-ov {
    position:fixed; inset:0; z-index:9999;
    background:rgba(15,23,42,.45);
    backdrop-filter:blur(4px);
    display:flex; align-items:center; justify-content:center;
    padding:20px;
    animation:gscmFade .16s ease-out;
}
.gscm-ov[hidden] { display:none; }
@keyframes gscmFade { from { opacity:0 } to { opacity:1 } }

.gscm-card {
    width:min(440px,100%);
    background:#fff;
    border-radius:20px;
    padding:28px 28px 22px;
    box-shadow:0 24px 60px rgba(15,23,42,.28);
    animation:gscmRise .2s cubic-bezier(.22,1,.36,1);
    font-family:inherit; text-align:center;
}
.dark .gscm-card { background:#262626; }
@keyframes gscmRise {
    from { opacity:0; transform:translateY(12px) scale(.97) }
    to   { opacity:1; transform:none }
}

/* The icon carries the whole warning, so the rest of the box stays quiet. */
.gscm-icon {
    width:52px; height:52px; border-radius:50%; margin:0 auto 16px;
    display:flex; align-items:center; justify-content:center;
}
.gscm-icon iconify-icon { font-size:25px; }
.gscm-card[data-tone="danger"]  .gscm-icon { background:#fee2e2; color:#dc2626; }
.gscm-card[data-tone="neutral"] .gscm-icon { background:#dbeafe; color:#2563eb; }
.dark .gscm-card[data-tone="danger"]  .gscm-icon { background:rgba(220,38,38,.18); }
.dark .gscm-card[data-tone="neutral"] .gscm-icon { background:rgba(37,99,235,.18); }

.gscm-title { margin:0 0 8px; font-size:18px; font-weight:700;
              letter-spacing:-.02em; color:#0f172a; }
.dark .gscm-title { color:#f1f5f9; }

.gscm-body { margin:0 auto; max-width:330px; font-size:13px;
             line-height:1.6; color:#64748b; }
.dark .gscm-body { color:#a1a1aa; }

/* Long Wix sitemap URLs have to wrap, not stretch the dialog. */
.gscm-url {
    margin:14px 0; padding:11px 13px;
    border:1px solid #eaeef4; border-radius:11px; background:#f8fafc;
    font-family:ui-monospace,"SF Mono",SFMono-Regular,Menlo,Consolas,monospace;
    font-size:12px; line-height:1.55; color:#334155;
    word-break:break-all; text-align:left;
}
.dark .gscm-url { background:#1f1f1f; border-color:#3f3f46; color:#d4d4d8; }

.gscm-actions { display:flex; gap:10px; margin-top:20px; }
.gscm-actions[data-single="1"] .gscm-btn { flex:none; margin:0 auto; min-width:150px; }

.gscm-btn {
    flex:1; height:44px; border-radius:12px; border:1px solid transparent;
    cursor:pointer; font-family:inherit; font-size:14px; font-weight:600;
    transition:filter .15s, border-color .15s;
}
.gscm-btn:focus-visible { outline:2px solid #2563eb; outline-offset:2px; }
.gscm-btn-ghost { background:#fff; border-color:#e2e8f0; color:#475569; }
.gscm-btn-ghost:hover { border-color:#cbd5e1; }
.dark .gscm-btn-ghost { background:#2f2f2f; border-color:#3f3f46; color:#d4d4d8; }
.gscm-btn-danger  { background:#dc2626; color:#fff; }
.gscm-btn-primary { background:#2563eb; color:#fff; }
.gscm-btn-danger:hover, .gscm-btn-primary:hover { filter:brightness(1.07); }

@media (max-width:460px) {
    .gscm-actions { flex-direction:column-reverse; }
}
@media (prefers-reduced-motion: reduce) {
    .gscm-ov, .gscm-card { animation:none; }
}
</style>

<div class="page-wrapper smx">

    <?php if ($isConnected): ?>

        <!-- ============================= HEADER ============================= -->
        <div class="smx-card" style="margin-bottom:14px;">
            <div class="smx-head">
                <div class="smx-head-l">
                    <div class="smx-head-ic">
                        <iconify-icon icon="mdi:sitemap-outline"></iconify-icon>
                    </div>
                    <div style="min-width:0;">
                        <h1 class="smx-title">Sitemap Manager</h1>
                        <p class="smx-sub">Submit sitemaps and track what Google actually reads</p>
                        <?php if ($siteUrl): ?>
                            <div class="smx-domain">
                                <iconify-icon icon="solar:check-circle-outline"></iconify-icon>
                                <span><?= htmlspecialchars($siteUrl) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="smx-head-r">
                    <span id="syncStamp" class="smx-stamp"></span>
                    <button id="syncBtn" class="smx-btn">
                        <iconify-icon icon="solar:refresh-outline"></iconify-icon> Sync
                    </button>
                    <a href="#addSitemap" class="smx-btn smx-btn-primary">
                        <iconify-icon icon="solar:add-circle-outline"></iconify-icon> Add sitemap
                    </a>
                </div>
            </div>
        </div>

        <!-- ============================= KPIs ============================= -->
        <div class="smx-kpis" style="margin-bottom:14px;">

            <div class="smx-kpi" style="--kpi-bg:#f3e8ff;--kpi-fg:#7c3aed;--kpi-wash:rgba(124,58,237,.05);">
                <div class="smx-kpi-top">
                    <div class="smx-kpi-ic"><iconify-icon icon="solar:documents-outline"></iconify-icon></div>
                    <?php if ($indexCount): ?>
                        <span class="smx-kpi-tag"><?= (int)$indexCount ?> index</span>
                    <?php endif; ?>
                </div>
                <div class="smx-kpi-v"><?= (int)$totalSitemaps ?></div>
                <div class="smx-kpi-k">Sitemaps</div>
                <div class="smx-kpi-s">submitted to Search Console</div>
            </div>

            <div class="smx-kpi" style="<?= $needsAttn
                    ? '--kpi-bg:#fee2e2;--kpi-fg:#dc2626;--kpi-wash:rgba(220,38,38,.05);'
                    : '--kpi-bg:#d1fae5;--kpi-fg:#059669;--kpi-wash:rgba(5,150,105,.05);' ?>">
                <div class="smx-kpi-top">
                    <div class="smx-kpi-ic">
                        <iconify-icon icon="<?= $needsAttn ? 'solar:danger-triangle-outline' : 'solar:shield-check-outline' ?>"></iconify-icon>
                    </div>
                    <?php if ($needsAttn): ?>
                        <span class="smx-kpi-tag">action needed</span>
                    <?php else: ?>
                        <span class="smx-kpi-tag">all clear</span>
                    <?php endif; ?>
                </div>
                <div class="smx-kpi-v"><?= (int)$needsAttn ?></div>
                <div class="smx-kpi-k">Needs attention</div>
                <div class="smx-kpi-s">
                    <?php if ($needsAttn): ?>
                        <?= (int)$errorCount ?> error<?= $errorCount === 1 ? '' : 's' ?>,
                        <?= (int)$warnCount ?> warning<?= $warnCount === 1 ? '' : 's' ?>
                    <?php else: ?>
                        no errors or warnings
                    <?php endif; ?>
                </div>
            </div>

            <div class="smx-kpi" style="--kpi-bg:#dbeafe;--kpi-fg:#2563eb;--kpi-wash:rgba(37,99,235,.05);">
                <div class="smx-kpi-top">
                    <div class="smx-kpi-ic"><iconify-icon icon="solar:file-check-outline"></iconify-icon></div>
                    <?php if ($totalPages === null): ?>
                        <span class="smx-kpi-tag">not synced</span>
                    <?php endif; ?>
                </div>
                <div class="smx-kpi-v <?= $totalPages === null ? 'sm' : '' ?>">
                    <?php if ($totalPages !== null): ?>
                        <?= number_format($totalPages) ?>
                    <?php else: ?>
                        <span style="color:var(--sx-ink-3);">&mdash;</span>
                    <?php endif; ?>
                </div>
                <div class="smx-kpi-k">Pages discovered</div>
                <div class="smx-kpi-s">
                    <?= $totalPages !== null ? 'found by Google' : 'press Sync to pull this' ?>
                </div>
            </div>

            <div class="smx-kpi" style="--kpi-bg:#f1f5f9;--kpi-fg:#475569;--kpi-wash:rgba(71,85,105,.04);">
                <div class="smx-kpi-top">
                    <div class="smx-kpi-ic"><iconify-icon icon="solar:calendar-outline"></iconify-icon></div>
                </div>
                <div class="smx-kpi-v sm">
                    <?php if ($lastReadAny): ?>
                        <span class="js-date" data-utc="<?= htmlspecialchars($lastReadAny) ?>"></span>
                    <?php else: ?>
                        <span style="color:var(--sx-ink-3);">Never</span>
                    <?php endif; ?>
                </div>
                <div class="smx-kpi-k">Last read by Google</div>
                <div class="smx-kpi-s">
                    <?= $lastReadAny ? 'most recent crawl' : 'no sitemap read yet' ?>
                </div>
            </div>
        </div>

        <?php if ($neverSynced && $totalSitemaps > 0): ?>
            <!-- last_downloaded / errors / warnings are only filled by a sync,
                 so say so plainly instead of showing an invented status -->
            <div class="smx-banner" style="margin-bottom:14px;">
                <div class="smx-banner-ic"><iconify-icon icon="solar:info-circle-outline"></iconify-icon></div>
                <div style="flex:1;min-width:200px;">
                    <h4>Status not pulled from Google yet</h4>
                    <p>Submitting only tells Google a sitemap exists — it does not report whether the file could be read.
                       Sync to pull the real status, error counts and page totals.</p>
                </div>
                <div class="smx-banner-act">
                    <button class="smx-btn smx-btn-primary js-sync-alt">
                        <iconify-icon icon="solar:refresh-outline"></iconify-icon> Sync now
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- ============================= SITEMAPS ============================= -->
        <div class="smx-sec" style="margin:22px 0 12px;">
            <h2><iconify-icon icon="solar:documents-outline"></iconify-icon> Your sitemaps</h2>
            <span class="hint">Click a card to expand</span>
        </div>

        <?php if (!$sitemaps): ?>
            <div class="smx-card">
                <div class="smx-empty">
                    <div class="smx-empty-ic"><iconify-icon icon="solar:sitemap-outline"></iconify-icon></div>
                    <h4>No sitemaps submitted yet</h4>
                    <p>A sitemap tells Google which pages exist on your site.
                       Most site builders create one for you automatically — submit it below to help Google find your pages faster.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="smx-list">
                <?php foreach ($sitemaps as $i => $s):
                    $st       = sitemapState($s);
                    $rowId    = 'smd' . $i;
                    $isIndex  = !empty($s['is_index']);
                    $shortUrl = $siteUrl && str_starts_with($s['sitemap_url'], $siteUrl)
                                ? substr($s['sitemap_url'], strlen($siteUrl))
                                : $s['sitemap_url'];

                    $icons = [
                        'ok'    => ['solar:check-circle-outline',   '#d1fae5', '#059669'],
                        'warn'  => ['solar:shield-warning-outline', '#fef3c7', '#d97706'],
                        'bad'   => ['solar:close-circle-outline',   '#fee2e2', '#dc2626'],
                        'info'  => ['solar:hourglass-outline',      '#dbeafe', '#2563eb'],
                        'muted' => ['solar:question-circle-outline','#f1f5f9', '#64748b'],
                    ];
                    [$icon, $iconBg, $iconFg] = $icons[$st['tone']];
                    if ($isIndex && $st['tone'] === 'ok') {
                        [$icon, $iconBg, $iconFg] = ['solar:folder-with-files-outline', '#f3e8ff', '#7c3aed'];
                    }
                ?>
                <article class="smx-sm" data-card="<?= $rowId ?>">
                    <div class="smx-sm-row js-toggle">
                        <span class="smx-sm-chev"><iconify-icon icon="solar:alt-arrow-right-outline"></iconify-icon></span>

                        <div class="smx-sm-ic" style="--sm-bg:<?= $iconBg ?>;--sm-fg:<?= $iconFg ?>;">
                            <iconify-icon icon="<?= $icon ?>"></iconify-icon>
                        </div>

                        <div class="smx-sm-id">
                            <div class="smx-sm-name" title="<?= htmlspecialchars($s['sitemap_url']) ?>">
                                <?= htmlspecialchars($shortUrl) ?>
                            </div>
                            <div class="smx-sm-meta">
                                <span><?= $isIndex ? 'Sitemap index' : 'Sitemap' ?></span>
                                <?php if (!empty($s['last_downloaded'])): ?>
                                    <span>Read <span class="js-date" data-utc="<?= htmlspecialchars($s['last_downloaded']) ?>"></span></span>
                                <?php elseif ($st['key'] === 'pending'): ?>
                                    <span class="smx-hintline">
                                        <iconify-icon icon="solar:hourglass-outline"></iconify-icon>
                                        Google has not read it yet &mdash; nothing to do
                                    </span>
                                <?php elseif ($st['key'] === 'unsynced'): ?>
                                    <span class="smx-hintline act js-sync-alt-inline">
                                        <iconify-icon icon="solar:refresh-outline"></iconify-icon>
                                        Never checked &mdash; press Sync to see its status
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <span class="smx-tag <?= $isIndex ? 'idx' : '' ?>"><?= $isIndex ? 'Index' : 'Sitemap' ?></span>

                        <span class="smx-badge <?= $st['tone'] ?>">
                            <i></i><?= htmlspecialchars($st['label']) ?>
                        </span>

                        <div class="smx-sm-stat">
                            <?php if ($s['discovered_pages'] !== null): ?>
                                <div class="n"><?= number_format((int)$s['discovered_pages']) ?></div>
                                <div class="u">pages</div>
                            <?php else: ?>
                                <div class="n" style="color:var(--sx-ink-3);">&mdash;</div>
                                <div class="u"><?= htmlspecialchars($st['short'] ?? '') ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="smx-acts">
                            <a href="<?= htmlspecialchars($s['sitemap_url']) ?>" target="_blank" rel="noopener"
                               class="smx-ibtn" title="Open sitemap" onclick="event.stopPropagation();">
                                <iconify-icon icon="solar:square-top-down-outline"></iconify-icon>
                            </a>
                            <button type="button" class="smx-ibtn danger js-delete-sitemap"
                                    data-url="<?= htmlspecialchars($s['sitemap_url']) ?>"
                                    title="Remove from Search Console">
                                <iconify-icon icon="solar:trash-bin-trash-outline"></iconify-icon>
                            </button>
                        </div>
                    </div>

                    <div class="smx-detail" id="<?= $rowId ?>">
                        <div class="smx-detail-in">

                            <div class="smx-alert <?= $st['tone'] ?>">
                                <div class="smx-alert-ic">
                                    <iconify-icon icon="<?= [
                                        'ok'    => 'solar:check-circle-outline',
                                        'bad'   => 'solar:danger-triangle-outline',
                                        'warn'  => 'solar:shield-warning-outline',
                                        'info'  => 'solar:hourglass-outline',
                                        'muted' => 'solar:question-circle-outline',
                                    ][$st['tone']] ?>"></iconify-icon>
                                </div>
                                <div>
                                    <h5><?= $st['key'] === 'ok' ? 'Status' : 'What to do' ?></h5>
                                    <p><?= htmlspecialchars($st['message']) ?></p>
                                </div>
                            </div>

                            <div class="smx-facts">
                                <div class="smx-fact">
                                    <div class="smx-fk">Submitted</div>
                                    <div class="smx-fv">
                                        <?php if (!empty($s['last_submitted_at'])): ?>
                                            <span class="js-date" data-utc="<?= htmlspecialchars($s['last_submitted_at']) ?>"></span>
                                        <?php else: ?><span class="mute">&mdash;</span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="smx-fact">
                                    <div class="smx-fk">Times submitted</div>
                                    <div class="smx-fv"><?= (int)$s['submission_count'] ?></div>
                                </div>
                                <div class="smx-fact">
                                    <div class="smx-fk">Errors</div>
                                    <div class="smx-fv <?= (int)$s['errors'] > 0 ? 'bad' : '' ?>">
                                        <?= (int)$s['errors'] ?>
                                    </div>
                                </div>
                                <div class="smx-fact">
                                    <div class="smx-fk">Warnings</div>
                                    <div class="smx-fv <?= (int)$s['warnings'] > 0 ? 'warn' : '' ?>">
                                        <?= (int)$s['warnings'] ?>
                                    </div>
                                </div>
                            </div>

                            <div class="smx-url-box">
                                <iconify-icon icon="solar:link-outline"></iconify-icon>
                                <a href="<?= htmlspecialchars($s['sitemap_url']) ?>" target="_blank" rel="noopener">
                                    <?= htmlspecialchars($s['sitemap_url']) ?>
                                </a>
                            </div>

                            <!-- What is actually inside this file.
                                 Search Console only ever reports counts, and
                                 its index drill-down comes back empty on some
                                 properties, so this reads the sitemap XML
                                 itself — which is the only source that always
                                 knows what the file contains. -->
                            <div class="smx-own">
                                <button type="button" class="smx-btn smx-btn-sm js-view-own"
                                        data-url="<?= htmlspecialchars($s['sitemap_url']) ?>">
                                    <iconify-icon icon="solar:list-outline"></iconify-icon>
                                    <?= $isIndex ? 'View contents' : 'View pages' ?>
                                    <?php if ($s['discovered_pages'] !== null && (int)$s['discovered_pages'] > 0): ?>
                                        <span class="smx-btn-count"><?= number_format((int)$s['discovered_pages']) ?></span>
                                    <?php endif; ?>
                                </button>
                                <div class="js-own-list" hidden></div>
                            </div>

                            <?php if ($isIndex): ?>
                                <!-- children are fetched only when this card is opened -->
                                <div class="smx-kids">
                                    <div class="smx-kids-h">Sitemaps inside this index</div>
                                    <div class="js-kids" data-parent="<?= htmlspecialchars($s['sitemap_url']) ?>">
                                        <span style="font-size:12.5px;color:var(--sx-ink-3);">Loading&hellip;</span>
                                    </div>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ============================= ADD ============================= -->
        <div class="smx-sec" style="margin:26px 0 12px;">
            <h2><iconify-icon icon="solar:add-square-outline"></iconify-icon> Add a sitemap</h2>
        </div>

        <div class="smx-card" id="addSitemap" style="margin-bottom:14px;">
            <div style="padding:22px;">
                <div style="display:flex;gap:11px;flex-wrap:wrap;">
                    <?php if ($siteUrl): ?>
                        <!-- The domain is fixed. A sitemap has to live on the
                             verified property, so letting the whole URL be typed
                             only invites submissions Google will reject. -->
                        <div class="smx-combo">
                            <span class="smx-combo-prefix" title="<?= htmlspecialchars($siteUrl) ?>">
                                <?= htmlspecialchars($siteUrl) ?>/
                            </span>
                            <input type="text" id="sitemapPath" class="smx-combo-input"
                                   value="sitemap.xml"
                                   placeholder="sitemap.xml"
                                   spellcheck="false" autocomplete="off">
                        </div>
                        <input type="hidden" id="sitemapUrl" value="<?= htmlspecialchars($defaultSitemapUrl) ?>">
                    <?php else: ?>
                        <!-- No verified property on file, so there is no prefix
                             to lock to — take the whole URL. -->
                        <div class="smx-add-wrap">
                            <iconify-icon icon="solar:link-circle-outline"></iconify-icon>
                            <input type="text" id="sitemapUrl" class="smx-input"
                                   value="" placeholder="https://example.com/sitemap.xml">
                        </div>
                    <?php endif; ?>

                    <button id="submitSitemapBtn"
                            data-instance="<?= htmlspecialchars($instanceId) ?>"
                            class="smx-btn smx-btn-primary" style="height:48px;padding:0 22px;">
                        <iconify-icon icon="solar:upload-outline"></iconify-icon>
                        Submit to Google
                    </button>
                </div>

                <?php if ($siteUrl): ?>
                    <div class="smx-chips">
                        <span class="smx-chips-lbl">Common paths</span>
                        <?php foreach (['sitemap.xml', 'pages-sitemap.xml', 'blog-posts-sitemap.xml', 'store-products-sitemap.xml'] as $p): ?>
                            <button type="button" class="smx-chip js-quick" data-path="<?= htmlspecialchars($p) ?>">
                                <?= htmlspecialchars($p) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <p class="smx-help">
                    <iconify-icon icon="solar:shield-check-outline"></iconify-icon>
                    <?php if ($siteUrl): ?>
                        Only sitemaps on your verified property can be submitted, so the domain is fixed &mdash; enter the file name.
                        Submitting tells Google the file exists; press Sync afterwards to see whether Google could read it.
                    <?php else: ?>
                        Submitting tells Google the file exists. Press Sync afterwards to see whether Google could actually read it.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- ============================= HISTORY ============================= -->
        <div class="smx-sec" style="margin:26px 0 12px;">
            <h2><iconify-icon icon="solar:history-outline"></iconify-icon> Submission history</h2>
            <span class="hint">Every attempt, with where each sitemap stands now</span>
        </div>

        <div class="smx-card" style="overflow:hidden;margin-bottom:32px;">
            <div class="smx-scroll" style="overflow-x:auto;">
                <table class="smx-tbl">
                    <thead>
                        <tr>
                            <th style="width:38%;">Sitemap</th>
                            <th style="width:16%;">Submission</th>
                            <th style="width:20%;">Status on Google</th>
                            <th style="width:26%;">When</th>
                        </tr>
                    </thead>
                    <tbody id="submission-history-tbody">
                        <?php if (empty($history)): ?>
                            <tr>
                                <td colspan="4" style="text-align:center;padding:52px 22px;color:var(--sx-ink-3);">
                                    No sitemap submissions yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="submission-history-pagination" class="smx-pager" style="display:none;"></div>
        </div>

    <?php else: ?>

        <div class="smx-card">
            <div class="smx-locked">
                <div class="smx-locked-ic">
                    <iconify-icon icon="solar:lock-keyhole-outline"></iconify-icon>
                </div>
                <h3 style="margin:0 0 8px;font-size:20px;font-weight:700;letter-spacing:-.02em;">Sitemap Tools Locked</h3>
                <p style="margin:0 auto 26px;font-size:14px;color:var(--sx-ink-3);max-width:430px;line-height:1.65;">
                    To submit sitemaps and view submission history, you must first connect your Google Account.
                </p>
                <a href="setup-wizard.php?step=1" class="smx-btn smx-btn-primary" style="height:44px;padding:0 22px;">
                    <iconify-icon icon="solar:link-circle-outline"></iconify-icon>
                    Connect Google Account
                </a>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- =====================================================================
     CONFIRM DIALOG

     Deliberately outside .page-wrapper. A fixed-position element is
     clipped by any ancestor that has overflow or a transform on it, and
     the wrapper has both in places — putting this inside would have made
     it centre on the wrapper rather than the window.
     ===================================================================== -->
<div class="gscm-ov" id="gscmOverlay" hidden role="dialog" aria-modal="true" aria-labelledby="gscmTitle">
    <div class="gscm-card" id="gscmCard" data-tone="danger">
        <div class="gscm-icon">
            <iconify-icon id="gscmIcon" icon="solar:trash-bin-trash-outline"></iconify-icon>
        </div>

        <h3 class="gscm-title" id="gscmTitle">Remove this sitemap?</h3>
        <p class="gscm-body" id="gscmBody"></p>

        <div class="gscm-url" id="gscmUrl"></div>

        <div class="gscm-actions" id="gscmActions">
            <button type="button" class="gscm-btn gscm-btn-ghost"  id="gscmCancel">Cancel</button>
            <button type="button" class="gscm-btn gscm-btn-danger" id="gscmOk">Remove sitemap</button>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {

    const BASE     = (window.APP_BASE || "").toString().replace(/\/+$/, "");
    const INSTANCE = window.GSC_INSTANCE;

    // ---------------------------------------------------------------
    // confirm / alert dialogs
    //
    // The browser's confirm() and alert() are drawn at the top of the
    // window by the browser itself — they cannot be centred, styled or
    // themed. Both are replaced here so every dialog on this page looks
    // like the rest of the app.
    //
    // gscConfirm returns a promise, so
    //     if (!confirm(...)) return;
    // becomes
    //     if (!await gscConfirm({...})) return;
    // and nothing about the surrounding code changes.
    // ---------------------------------------------------------------
    const dlgOv     = document.getElementById("gscmOverlay");
    const dlgCard   = document.getElementById("gscmCard");
    const dlgIcon   = document.getElementById("gscmIcon");
    const dlgTitle  = document.getElementById("gscmTitle");
    const dlgBody   = document.getElementById("gscmBody");
    const dlgUrl    = document.getElementById("gscmUrl");
    const dlgActs   = document.getElementById("gscmActions");
    const dlgOk     = document.getElementById("gscmOk");
    const dlgCancel = document.getElementById("gscmCancel");

    let dlgSettle = null;   // resolver for the dialog currently open
    let dlgReturn = null;   // element that had focus before it opened

    function dlgClose(result) {
        if (!dlgOv) return;
        dlgOv.hidden = true;
        document.body.style.overflow = "";
        if (dlgReturn && dlgReturn.isConnected) dlgReturn.focus();
        const done = dlgSettle;
        dlgSettle = null;
        if (done) done(result);
    }

    function gscConfirm(opt) {
        opt = opt || {};

        // If the markup is somehow missing, fall back to the browser rather
        // than silently disabling whatever action asked for confirmation.
        if (!dlgOv) {
            return Promise.resolve(window.confirm(
                (opt.title || "Are you sure?") + (opt.url ? "\n\n" + opt.url : "")
            ));
        }

        return new Promise((resolve) => {
            if (dlgSettle) dlgClose(false);   // never leave an older one hanging

            dlgReturn = document.activeElement;
            dlgSettle = resolve;

            dlgCard.dataset.tone = opt.tone || "danger";
            dlgIcon.setAttribute("icon", opt.icon || "solar:trash-bin-trash-outline");

            dlgTitle.textContent = opt.title || "Are you sure?";
            dlgBody.textContent  = opt.body || "";
            dlgBody.hidden       = !opt.body;

            dlgUrl.textContent = opt.url || "";
            dlgUrl.hidden      = !opt.url;

            dlgOk.textContent = opt.confirmLabel || "Confirm";
            dlgOk.className   = "gscm-btn " +
                (dlgCard.dataset.tone === "danger" ? "gscm-btn-danger" : "gscm-btn-primary");

            dlgCancel.hidden      = !!opt.singleButton;
            dlgCancel.textContent = opt.cancelLabel || "Cancel";
            dlgActs.dataset.single = opt.singleButton ? "1" : "0";

            dlgOv.hidden = false;
            document.body.style.overflow = "hidden";   // stop the page scrolling behind
            dlgOk.focus();
        });
    }

    /** One-button dialog, for reporting a failure. */
    function gscAlert(title, body) {
        return gscConfirm({
            tone: "neutral",
            icon: "solar:info-circle-outline",
            title: title,
            body: body,
            confirmLabel: "OK",
            singleButton: true
        });
    }

    if (dlgOv) {
        dlgOk.addEventListener("click", () => dlgClose(true));
        dlgCancel.addEventListener("click", () => dlgClose(false));

        // Clicking the backdrop cancels; clicking inside the card does not.
        dlgOv.addEventListener("click", (e) => { if (e.target === dlgOv) dlgClose(false); });

        document.addEventListener("keydown", (e) => {
            if (dlgOv.hidden) return;
            if (e.key === "Escape") { e.preventDefault(); dlgClose(false); }
            if (e.key === "Enter" && document.activeElement !== dlgCancel) {
                e.preventDefault(); dlgClose(true);
            }
        });
    }

    // The browser knows the visitor's timezone. The old page mapped eight
    // country codes by hand and gave everyone else UTC, so most users saw
    // times that were simply wrong.
    const TZ = Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";

    /**
     * Formats both shapes of timestamp this page deals with:
     *   MySQL DATETIME    "2026-07-24 00:28:00"   (from our tables)
     *   RFC 3339 from API "2026-07-21T22:18:43.791Z" (straight from Google)
     *
     * The MySQL form has no timezone, so it needs "T" and a "Z" added.
     * Doing that to the RFC form produced "…791ZZ", which is invalid — the
     * raw string then leaked into the UI.
     */
    function fmtDate(value, withTime = true) {
        if (!value) return "";

        const str = String(value).trim();
        const isMysql = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(str);
        const d = new Date(isMysql ? str.replace(" ", "T") + "Z" : str);

        if (isNaN(d)) return str;

        const opts = { year: "numeric", month: "short", day: "numeric", timeZone: TZ };
        if (withTime) { opts.hour = "2-digit"; opts.minute = "2-digit"; opts.hour12 = false; }
        return d.toLocaleString("en-US", opts);
    }

    /** "3 days ago" — an exact timestamp alone makes you do the arithmetic. */
    function relTime(value) {
        if (!value) return "";
        const str = String(value).trim();
        const isMysql = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(str);
        const d = new Date(isMysql ? str.replace(" ", "T") + "Z" : str);
        if (isNaN(d)) return "";

        const secs = Math.floor((Date.now() - d.getTime()) / 1000);
        if (secs < 60)      return "just now";
        if (secs < 3600)    return Math.floor(secs / 60) + "m ago";
        if (secs < 86400)   return Math.floor(secs / 3600) + "h ago";
        if (secs < 2592000) return Math.floor(secs / 86400) + "d ago";
        if (secs < 31536000) return Math.floor(secs / 2592000) + "mo ago";
        return Math.floor(secs / 31536000) + "y ago";
    }

    function escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text == null ? "" : text;
        return div.innerHTML;
    }

    // render every server-rendered timestamp in the visitor's timezone
    document.querySelectorAll(".js-date").forEach(el => {
        el.textContent = fmtDate(el.dataset.utc, false);
    });

    // ---------------------------------------------------------------
    // expand / collapse a sitemap card
    // ---------------------------------------------------------------
    document.querySelectorAll(".smx-sm .js-toggle").forEach(rowEl => {
        rowEl.addEventListener("click", (e) => {
            if (e.target.closest("a, button")) return;   // let the actions work

            const card = rowEl.closest(".smx-sm");
            if (!card) return;

            card.classList.toggle("is-open");

            if (card.classList.contains("is-open")) {
                loadChildren(card);
            }
        });
    });

    // ---------------------------------------------------------------
    // children of a sitemap index, fetched the first time the card opens
    // ---------------------------------------------------------------
    function loadChildren(card) {
        const box = card.querySelector(".js-kids");
        if (!box || box.dataset.loaded) return;
        box.dataset.loaded = "1";

        fetch(`${BASE}/api/google/get_sitemap_children.php`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ instanceId: INSTANCE, sitemapUrl: box.dataset.parent })
        })
        .then(r => r.json())
        .then(json => {
            if (!json.success || !json.children || !json.children.length) {
                box.innerHTML = '<span style="font-size:12.5px;color:var(--sx-ink-3);">No sitemaps listed inside this index yet.</span>';
                return;
            }
            // laid out like Search Console's own drill-down: sitemap,
            // last read, status, discovered URLs
            box.innerHTML = `
                <table class="smx-kidtbl">
                    <thead>
                        <tr>
                            <th>Sitemap</th>
                            <th>Last read</th>
                            <th>Status</th>
                            <th class="r">Discovered URLs</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    ${json.children.map((c, idx) => {
                        const tone  = c.errors > 0 ? "bad" : (c.warnings > 0 ? "warn" : "ok");
                        const label = c.errors > 0 ? "Error"
                                    : (c.warnings > 0 ? c.warnings + " warning" + (c.warnings === 1 ? "" : "s")
                                                      : "Success");
                        return `
                        <tr class="smx-kidrow" data-url="${escapeHtml(c.path)}" data-urls-id="ku_${idx}_${Math.random().toString(36).slice(2,7)}">
                            <td class="mono">${escapeHtml(c.shortPath || c.path)}</td>
                            <td class="dim">${c.lastDownloaded ? fmtDate(c.lastDownloaded, false) : "Never"}</td>
                            <td><span class="smx-badge ${tone}"><i></i>${escapeHtml(label)}</span></td>
                            <td class="r num">
                                ${c.submittedUrls != null ? c.submittedUrls : "—"}
                                ${c.images ? `<span class="smx-extra" title="${c.images} image${c.images === 1 ? "" : "s"}">+${c.images} img</span>` : ""}
                                ${c.videos ? `<span class="smx-extra" title="${c.videos} video${c.videos === 1 ? "" : "s"}">+${c.videos} vid</span>` : ""}
                            </td>
                            <td class="r">
                                <button type="button" class="smx-linkbtn js-view-urls" data-url="${escapeHtml(c.path)}">
                                    View pages
                                </button>
                            </td>
                        </tr>
                        <tr class="smx-kidurls" hidden><td colspan="5"></td></tr>`;
                    }).join("")}
                    </tbody>
                </table>`;

            box.querySelectorAll(".js-view-urls").forEach(b => {
                b.addEventListener("click", (ev) => {
                    ev.stopPropagation();
                    toggleUrlList(b, b.dataset.url);
                });
            });
        })
        .catch(() => {
            box.innerHTML = '<span style="font-size:12.5px;color:#dc2626;">Could not load the sitemaps inside this index.</span>';
        });
    }

    // ---------------------------------------------------------------
    // the pages inside one sitemap
    //
    // Search Console cannot supply these — sitemaps.list returns counts
    // only. The sitemap file itself is public, so the API reads and parses
    // it directly.
    // ---------------------------------------------------------------
    function toggleUrlList(btn, sitemapUrl) {
        const row  = btn.closest("tr");
        const slot = row.nextElementSibling;              // the hidden <tr>
        if (!slot) return;

        const cell = slot.querySelector("td");

        if (!slot.hidden) {
            slot.hidden = true;
            btn.textContent = "View pages";
            return;
        }

        slot.hidden = false;
        btn.textContent = "Hide pages";

        if (slot.dataset.loaded) return;
        slot.dataset.loaded = "1";

        // same renderer as the card-level button — one code path, so the two
        // cannot drift apart
        renderSitemapContents(cell, sitemapUrl);
    }

    /**
     * Renders whatever a sitemap file contains into `target`.
     *
     * The same call covers both shapes: point it at an index and the API
     * returns the sitemaps listed inside, point it at a normal sitemap and
     * it returns the page URLs.
     */
    function renderSitemapContents(target, sitemapUrl) {
        target.innerHTML = '<div class="smx-urls-loading">Reading the sitemap&hellip;</div>';

        return fetch(`${BASE}/api/google/get_sitemap_urls.php`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ instanceId: INSTANCE, sitemapUrl, limit: 500 })
        })
        .then(r => r.json())
        .then(json => {
            if (!json.success) {
                target.innerHTML = `<div class="smx-urls-empty">${escapeHtml(json.message || "Could not read this sitemap.")}</div>`;
                return json;
            }

            // an index holds sitemaps, not pages
            if (json.isIndex) {
                if (!json.children || !json.children.length) {
                    target.innerHTML = '<div class="smx-urls-empty">This index lists no sitemaps.</div>';
                    return json;
                }
                target.innerHTML = `
                    <div class="smx-urls">
                        <div class="smx-urls-h">${json.children.length} sitemap${json.children.length === 1 ? "" : "s"} listed inside</div>
                        <div class="smx-urls-list">
                            ${json.children.map(u => `
                                <div class="smx-url-row">
                                    <a href="${escapeHtml(u)}" target="_blank" rel="noopener">${escapeHtml(u)}</a>
                                </div>`).join("")}
                        </div>
                    </div>`;
                return json;
            }

            if (!json.urls.length) {
                target.innerHTML = '<div class="smx-urls-empty">This sitemap contains no URLs.</div>';
                return json;
            }

            const more = json.total > json.urls.length
                ? `<div class="smx-urls-more">Showing the first ${json.urls.length} of ${json.total}</div>`
                : "";

            target.innerHTML = `
                <div class="smx-urls">
                    <div class="smx-urls-h">${json.total} page${json.total === 1 ? "" : "s"} in this sitemap</div>
                    <div class="smx-urls-list">
                        ${json.urls.map(u => `
                            <div class="smx-url-row">
                                <a href="${escapeHtml(u.loc)}" target="_blank" rel="noopener">${escapeHtml(u.loc)}</a>
                                <span class="smx-url-mod">${u.lastmod ? fmtDate(u.lastmod, false) : ""}</span>
                            </div>`).join("")}
                    </div>
                    ${more}
                </div>`;
            return json;
        })
        .catch(() => {
            target.innerHTML = '<div class="smx-urls-empty">Could not read this sitemap.</div>';
        });
    }

    // card-level "View pages" / "View contents"
    document.querySelectorAll(".js-view-own").forEach(btn => {
        btn.addEventListener("click", (e) => {
            e.stopPropagation();

            const list = btn.parentElement.querySelector(".js-own-list");
            if (!list) return;

            const label = btn.querySelector("iconify-icon")?.outerHTML || "";

            if (!list.hidden) {
                list.hidden = true;
                btn.innerHTML = label + " Show again";
                return;
            }

            list.hidden = false;

            if (!list.dataset.loaded) {
                list.dataset.loaded = "1";
                renderSitemapContents(list, btn.dataset.url);
            }
            btn.innerHTML = label + " Hide";
        });
    });

    // ---------------------------------------------------------------
    // quick path chips
    // ---------------------------------------------------------------
    const pathInput = document.getElementById("sitemapPath");   // locked-domain mode
    const urlInput  = document.getElementById("sitemapUrl");    // hidden, or the full-URL fallback
    const prefixEl  = document.querySelector(".smx-combo-prefix");

    /** The URL that will actually be submitted. */
    function currentSitemapUrl() {
        if (!pathInput) return (urlInput?.value || "").trim();

        const prefix = (prefixEl?.textContent || "").trim().replace(/\/+$/, "");
        const path   = pathInput.value.trim().replace(/^\/+/, "");
        return path ? prefix + "/" + path : "";
    }

    // Pasting a full URL into the path box is the natural thing to do, so
    // strip the origin rather than rejecting it — but only when it matches
    // the locked domain, otherwise the paste is left alone and the check
    // below catches it.
    if (pathInput) {
        const prefix = (prefixEl?.textContent || "").trim().replace(/\/+$/, "");

        pathInput.addEventListener("input", () => {
            const v = pathInput.value.trim();
            if (/^https?:\/\//i.test(v) && v.toLowerCase().startsWith(prefix.toLowerCase())) {
                pathInput.value = v.slice(prefix.length).replace(/^\/+/, "");
            }
            if (urlInput) urlInput.value = currentSitemapUrl();
        });

        pathInput.addEventListener("keydown", (e) => {
            if (e.key === "Enter") { e.preventDefault(); document.getElementById("submitSitemapBtn")?.click(); }
        });
    }

    document.querySelectorAll(".js-quick").forEach(chip => {
        chip.addEventListener("click", () => {
            const target = pathInput || urlInput;
            if (!target) return;
            target.value = chip.dataset.path;
            if (pathInput && urlInput) urlInput.value = currentSitemapUrl();
            target.focus();
        });
    });

    // ---------------------------------------------------------------
    // submit
    // ---------------------------------------------------------------
    const btn = document.getElementById("submitSitemapBtn");

    if (btn) {
        btn.addEventListener("click", async () => {
            const original = btn.innerHTML;
            const url = currentSitemapUrl();

            if (!url) {
                await gscAlert(
                    "No sitemap entered",
                    pathInput ? "Enter a sitemap file name, for example sitemap.xml."
                              : "Enter the full sitemap URL, for example https://example.com/sitemap.xml."
                );
                (pathInput || urlInput)?.focus();
                return;
            }

            // Only reachable in the full-URL fallback, where there is no
            // locked prefix to keep the domain honest.
            if (!/^https?:\/\//i.test(url)) {
                await gscAlert(
                    "That does not look like a URL",
                    "A sitemap URL starts with http:// or https:// — for example https://example.com/sitemap.xml."
                );
                (pathInput || urlInput)?.focus();
                return;
            }

            btn.disabled = true;
            btn.innerHTML = `<span class="loader"></span> Submitting…`;

            try {
                const res  = await fetch(`${BASE}/api/google/submit_sitemap.php`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "Accept": "application/json" },
                    // The Wix build sends a debugListSites flag here, because its
                    // submit endpoint hides property resolution behind it. This
                    // app's submit_sitemap.php has no such guard — it resolves the
                    // property from gsc_domain_verifications directly — so the flag
                    // is dropped rather than carried over as dead weight.
                    body: JSON.stringify({ instanceId: INSTANCE, sitemapUrl: url })
                });

                const text = await res.text();
                let json;
                try { json = JSON.parse(text); }
                catch { throw new Error("Server did not return JSON:\n" + text.slice(0, 500)); }

                if (json.success) {
                    btn.innerHTML = "✔ Submitted";
                    setTimeout(() => location.reload(), 700);
                } else {
                    await gscAlert(
                        "Could not submit the sitemap",
                        json.message || json.error || "Google did not accept the request. Try again in a moment."
                    );
                    btn.innerHTML = original;
                    btn.disabled = false;
                }
            } catch (e) {
                console.error("submit sitemap failed:", e);
                await gscAlert(
                    "Could not submit the sitemap",
                    e.message || "The request did not reach the server. Check your connection and try again."
                );
                btn.innerHTML = original;
                btn.disabled = false;
            }
        });
    }

    // ---------------------------------------------------------------
    // sync — pulls the real status from Google
    // ---------------------------------------------------------------
    async function runSync(sourceBtn) {
        const original = sourceBtn.innerHTML;
        sourceBtn.disabled = true;
        sourceBtn.innerHTML = `<span class="loader"></span> Syncing…`;

        try {
            const res  = await fetch(`${BASE}/api/google/sync_sitemaps.php`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ instanceId: INSTANCE })
            });
            const json = await res.json();

            if (json.success) {
                location.reload();
            } else {
                await gscAlert(
                    "Sync failed",
                    json.message || json.error || "Google did not return the sitemap list. Try again in a moment."
                );
                sourceBtn.innerHTML = original;
                sourceBtn.disabled = false;
            }
        } catch (e) {
            console.error("sync failed:", e);
            await gscAlert(
                "Sync failed",
                "The request did not reach the server. Check your connection and try again."
            );
            sourceBtn.innerHTML = original;
            sourceBtn.disabled = false;
        }
    }

    const syncBtn = document.getElementById("syncBtn");
    if (syncBtn) syncBtn.addEventListener("click", () => runSync(syncBtn));

    // the banner carries a second Sync button; same handler
    document.querySelectorAll(".js-sync-alt").forEach(b => {
        b.addEventListener("click", () => runSync(b));
    });

    // "press Sync to see its status" inside a card row — runs the same sync
    // rather than making the user hunt for the button in the header
    document.querySelectorAll(".js-sync-alt-inline").forEach(el => {
        el.addEventListener("click", (e) => {
            e.stopPropagation();          // do not expand the card
            if (syncBtn) runSync(syncBtn);
        });
    });

    // ---------------------------------------------------------------
    // delete
    // ---------------------------------------------------------------
    document.querySelectorAll(".js-delete-sitemap").forEach(b => {
        b.addEventListener("click", async (e) => {
            e.stopPropagation();
            const url = b.dataset.url;

            const confirmed = await gscConfirm({
                tone: "danger",
                icon: "solar:trash-bin-trash-outline",
                title: "Remove this sitemap?",
                body: "Pages already indexed stay indexed. This only takes the file off the property's list in Search Console.",
                url: url,
                confirmLabel: "Remove sitemap"
            });

            if (!confirmed) return;

            b.disabled = true;

            try {
                const res  = await fetch(`${BASE}/api/google/delete_sitemap.php`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ instanceId: INSTANCE, sitemapUrl: url })
                });
                const json = await res.json();

                if (json.success) {
                    location.reload();
                } else {
                    await gscAlert(
                        "Could not remove the sitemap",
                        json.message || json.error || "Google did not accept the request. Try again in a moment."
                    );
                    b.disabled = false;
                }
            } catch (err) {
                console.error("delete failed:", err);
                await gscAlert(
                    "Could not remove the sitemap",
                    "The request did not reach the server. Check your connection and try again."
                );
                b.disabled = false;
            }
        });
    });

    // ---------------------------------------------------------------
    // submission history + pagination
    // ---------------------------------------------------------------
    const allHistory      = <?= json_encode($history, JSON_UNESCAPED_SLASHES) ?>;
    const historyTbody    = document.getElementById("submission-history-tbody");
    const paginationBox   = document.getElementById("submission-history-pagination");

    if (historyTbody && paginationBox) {
        const PER_PAGE = 10;
        let currentPage = 1;

        function renderPage(page) {
            currentPage = page;
            historyTbody.innerHTML = "";

            if (!allHistory.length) {
                historyTbody.innerHTML =
                    '<tr><td colspan="4" style="text-align:center;padding:52px 22px;color:var(--sx-ink-3);">No sitemap submissions yet.</td></tr>';
                paginationBox.style.display = "none";
                return;
            }

            const totalPages = Math.ceil(allHistory.length / PER_PAGE);
            if (page < 1) page = 1;
            if (page > totalPages) page = totalPages;

            const start = (page - 1) * PER_PAGE;

            allHistory.slice(start, start + PER_PAGE).forEach(log => {
                const ok = log.status === "Success";

                // "Success" only ever meant Google accepted the request. Calling
                // it that put a green tick beside sitemaps Google had never
                // read. "Accepted" is what actually happened.
                const badge = ok
                    ? '<span class="smx-badge muted"><i></i>Accepted</span>'
                    : '<span class="smx-badge bad"><i></i>Rejected'
                      + (log.http_code ? " " + escapeHtml(log.http_code) : "") + '</span>';

                const gs = log.google_state;
                const googleBadge = gs
                    ? `<span class="smx-badge ${gs.tone}"><i></i>${escapeHtml(gs.label)}</span>`
                    : '<span class="smx-dimtxt">no longer listed</span>';

                historyTbody.innerHTML += `
                    <tr>
                        <td class="mono" title="${escapeHtml(log.sitemap_url)}">
                            ${escapeHtml(log.short_url || log.sitemap_url)}
                        </td>
                        <td>${badge}</td>
                        <td>${googleBadge}</td>
                        <td class="dim">
                            ${escapeHtml(fmtDate(log.submitted_at))}
                            <span class="smx-ago">${relTime(log.submitted_at)}</span>
                        </td>
                    </tr>`;
            });

            renderPagination();
        }

        function renderPagination() {
            paginationBox.innerHTML = "";
            const totalPages = Math.ceil(allHistory.length / PER_PAGE);

            if (totalPages <= 1) { paginationBox.style.display = "none"; return; }
            paginationBox.style.display = "flex";

            const first = currentPage === 1;
            const last  = currentPage === totalPages;

            paginationBox.innerHTML +=
                `<button data-page="${currentPage - 1}" class="smx-pg" ${first ? "disabled" : ""}>Prev</button>`;

            for (let i = 1; i <= totalPages; i++) {
                paginationBox.innerHTML +=
                    `<button data-page="${i}" class="smx-pg ${i === currentPage ? "on" : ""}">${i}</button>`;
            }

            paginationBox.innerHTML +=
                `<button data-page="${currentPage + 1}" class="smx-pg" ${last ? "disabled" : ""}>Next</button>`;

            paginationBox.querySelectorAll("button").forEach(b => {
                b.addEventListener("click", () => renderPage(parseInt(b.dataset.page, 10)));
            });
        }

        renderPage(1);
    }
});
</script>

<?php include './partials/layouts/layoutBottom.php'; ?>
