<?php
/**
 * partials/action-center-bell.php  (WordPress)
 *
 * Action Center button for the navbar.
 * Include from partials/navbar.php after the Installation Guide button:
 *
 *     <?php include __DIR__ . '/action-center-bell.php'; ?>
 *
 * NOTE ON STYLING
 * This project ships a precompiled Tailwind build, so arbitrary classes
 * like w-[380px] do not exist in the CSS. All sizing and positioning is
 * therefore done in the scoped stylesheet below.
 *
 * TWO CHANGES IN THIS VERSION
 *
 * 1. The three summary figures are now tabs. They were already the three
 *    ways a merchant reads this list: what is bleeding, what is
 *    available, everything. So they were labels for filters that did not
 *    exist. Clicking one filters the feed.
 *
 * 2. Impact is shown as a trend arrow rather than a plus or minus sign. These
 *    numbers are monthly click movements, not quantities: a minus 43 reads as
 *    a negative amount, while a red arrow pointing down beside 43 reads
 *    as what it is, and does so before the number is parsed.
 *
 *    The colour still carries the same meaning, so nothing rests on the
 *    arrow alone.
 */

// Both session spellings. This codebase writes the key inconsistently:
// some files use 'instanceid', others 'instance_id'. Reading only one
// means the bell silently disappears on whichever pages set the other.
$acInstanceId = $instanceId
    ?? ($_SESSION['instanceid'] ?? null)
    ?? ($_SESSION['instance_id'] ?? null);

// APP_BASE is what every other page in this app uses, so it comes first.
// The dirname() fallback would give this file's own directory (/partials)
// rather than the app root, so it is a last resort only.
if (defined('APP_BASE') && trim((string)APP_BASE) !== '') {
    $acBase = '/' . trim((string)APP_BASE, '/');
} else {
    $acBase = rtrim((string)($_ENV['BASE_URL'] ?? getenv('BASE_URL') ?: ''), '/');
    if ($acBase === '') {
        $acBase = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    }
}

$acApiUrl = $acBase . '/api/insights/index.php';

/* Site health line inside the panel: the same speed and structured-data
   figures the Action Center page shows, from the same include, so the two
   can never disagree. Cached values only - the bell loads on every page,
   so it must never trigger a check of its own. If nothing has been
   measured yet the strip simply does not render. */
$acHealth = [
    'speed'  => null,
    'schema' => ['checked' => false, 'missing' => [], 'invalid' => 0],
    'links'  => ['speed' => '', 'schema' => ''],
];
try {
    require_once __DIR__ . '/../includes/site_health.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        require_once __DIR__ . '/../includes/config.php';
        require_once __DIR__ . '/../includes/db.php';
    }
    if (isset($pdo) && $pdo instanceof PDO && $acInstanceId) {
        $acHealth = gsc_site_health($pdo, (string)$acInstanceId);
    }
} catch (Throwable $e) {
    // health strip is optional - the bell works without it
}

if ($acInstanceId):
?>

<style>
/* ---------- Action Center: all styles scoped to .ac-* ---------- */
.ac-wrap { position: relative; display: inline-block; }

/* Icon-only square button */
.ac-btn {
    width: 40px;
    height: 40px;
    padding: 0;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    position: relative;
    cursor: pointer;
    background: #fff;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 2px rgba(2,6,23,.05);
    transition: border-color .15s, box-shadow .15s;
}
.ac-btn:hover {
    border-color: var(--clr-primary, #487fff);
    box-shadow: 0 2px 8px rgba(2,6,23,.08);
}
.dark .ac-btn { background: #262626; border-color: #404040; }

.ac-btn-icon { font-size: 22px; line-height: 1; }

.ac-badge {
    position: absolute; top: -6px; right: -6px;
    min-width: 20px; height: 20px; padding: 0 5px;
    border-radius: 999px;
    background: #dc2626; color: #fff;
    font-size: 11px; font-weight: 700; line-height: 1;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid #fff;
    box-shadow: 0 0 0 1px rgba(0,0,0,.06);
}
.dark .ac-badge { border-color: #262626; }

/* ---------- popup ---------- */
.ac-pop {
    position: absolute; top: calc(100% + 8px); right: 0;
    width: 400px; max-width: calc(100vw - 32px);
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 12px 40px rgba(2,6,23,.16);
    z-index: 60; overflow: hidden;
    text-align: left;
    /* Bounded height with one scroll area, so a tall panel can never run
       past the bottom of the screen with its footer out of reach. */
    display: flex; flex-direction: column;
    max-height: calc(100vh - 96px);
}
.ac-pop > .ac-head, .ac-pop > .ac-strip, .ac-pop > .ac-foot { flex: none; }
.ac-scroll { flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain; }
.dark .ac-pop { background: #404040; border-color: #525252; }

.ac-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 12px 16px;
    border-bottom: 1px solid #e5e7eb; white-space: nowrap;
}
.dark .ac-head { border-color: #525252; }
.ac-head h6 {
    margin: 0; font-size: 15px; font-weight: 600;
    display: flex; align-items: center; gap: 8px;
    color: #111827;
}
.dark .ac-head h6 { color: #fff; }

.ac-link {
    background: none; border: 0; padding: 0; cursor: pointer;
    font-size: 12px; font-weight: 500; white-space: nowrap;
    color: var(--clr-primary, #487fff);
}
.ac-link:hover { text-decoration: underline; }
.ac-link:disabled { opacity: .4; cursor: default; text-decoration: none; }

/* ---------- the three figures, now tabs ----------

   They were already the three questions a merchant asks of this list, so
   they were labels for filters that did not exist. Same layout, now
   clickable. The selected one is marked by a bar along its bottom edge
   rather than by changing colour, because these figures carry meaning in
   their colour already: red for losses, green for gains.               */
.ac-strip {
    display: flex; padding: 0 16px;
    background: #f8fafc; border-bottom: 1px solid #e5e7eb;
}
.dark .ac-strip { background: #333; border-color: #525252; }

.ac-tab {
    flex: 1; min-width: 0;
    padding: 10px 0 8px; padding-left: 12px;
    border: 0; border-left: 1px solid #e5e7eb;
    border-bottom: 2px solid transparent;
    background: none; text-align: left; cursor: pointer;
    font: inherit;
    transition: background .12s, border-color .12s;
    margin-bottom: -1px;   /* sit the marker on the strip's own border */
}
.ac-tab:first-child { padding-left: 0; border-left: 0; }
.dark .ac-tab { border-left-color: #525252; }
.ac-tab:hover { background: rgba(2,6,23,.03); }
.dark .ac-tab:hover { background: rgba(255,255,255,.04); }

.ac-tab.is-on { border-bottom-color: var(--clr-primary, #487fff); }
.ac-tab:focus-visible { outline: 2px solid var(--clr-primary, #487fff); outline-offset: -2px; }

.ac-tab .k {
    margin: 0 0 2px; font-size: 10px; letter-spacing: .06em;
    text-transform: uppercase; color: #9ca3af; white-space: nowrap;
}
.ac-tab.is-on .k { color: #4b5563; }
.dark .ac-tab.is-on .k { color: #e5e5e5; }
.ac-tab .v {
    margin: 0; font-size: 15px; font-weight: 700; white-space: nowrap;
    display: inline-flex; align-items: center; gap: 2px;
}
.ac-tab .v iconify-icon { font-size: 16px; line-height: 1; }

/* ---------- the type tag on each row ----------

   The arrow on the right already says which direction this is, but only
   once the eye has travelled there. In the Insights tab the two kinds sit
   interleaved, and the tag lets the type be read at the same moment as
   the title rather than after it.

   Kept small and tinted rather than solid, so it labels the row without
   competing with the title for attention.                              */
.ac-tag {
    display: inline-block; margin-bottom: 3px;
    font-size: 9px; font-weight: 700; letter-spacing: .07em;
    text-transform: uppercase; padding: 2px 6px; border-radius: 4px;
    line-height: 1.4;
}
.ac-tag.is-risk { background: #fee2e2; color: #b91c1c; }
.ac-tag.is-opp  { background: #dcfce7; color: #15803d; }
.dark .ac-tag.is-risk { background: rgba(220,38,38,.18); color: #fca5a5; }
.dark .ac-tag.is-opp  { background: rgba(22,163,74,.18); color: #86efac; }

.ac-list { max-height: none; }

/* ---------- site health strip ---------- */
.ac-health { padding: 8px 10px 10px; border-bottom: 1px solid #f1f5f9; background: #fbfcfd; }
.dark .ac-health { border-color: #525252; background: #2b2b2b; }
.ac-hhdr {
    display: flex; align-items: center; gap: 5px;
    font-size: 9.5px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase;
    color: #b42318; padding: 0 4px 7px;
}
.ac-hhdr.is-clear { color: #9ca3af; }
.ac-hhdr iconify-icon { font-size: 12px; line-height: 1; }
.ac-hrow {
    display: flex; align-items: flex-start; gap: 9px;
    padding: 8px 10px; border-radius: 9px;
    border: 1px solid transparent; border-left-width: 3px;
    text-decoration: none; position: relative;
}
.ac-hrow + .ac-hrow { margin-top: 7px; }
.ac-hrow.t-bad  { background: #fff5f4; border-color: #fdd9d5; border-left-color: #d92d20; }
.ac-hrow.t-warn { background: #fffaf0; border-color: #fde9c8; border-left-color: #f79009; }
.ac-hrow.t-ok   { background: #f6fefa; border-color: #ccf0dd; border-left-color: #12b76a; }
.dark .ac-hrow.t-bad  { background: rgba(217,45,32,.12); border-color: rgba(217,45,32,.3); }
.dark .ac-hrow.t-warn { background: rgba(247,144,9,.12); border-color: rgba(247,144,9,.3); }
.dark .ac-hrow.t-ok   { background: rgba(18,183,106,.12); border-color: rgba(18,183,106,.3); }
.ac-hrow.t-busy { background: #f9fafb; border-color: #e9edf3; border-left-color: #98a2b3; }
.dark .ac-hrow.t-busy { background: rgba(255,255,255,.05); border-color: #4a4a4a; }
.ac-hrow.t-busy .ac-hic { background: #98a2b3; }
.ac-hrow.t-busy .ac-hic iconify-icon { animation: acHealthPulse 1.2s ease-in-out infinite; }
@keyframes acHealthPulse { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }
.ac-hic {
    width: 26px; height: 26px; flex: none; border-radius: 7px; margin-top: 1px;
    display: flex; align-items: center; justify-content: center; color: #fff;
}
.ac-hic iconify-icon { font-size: 14px; line-height: 1; }
.ac-hrow.t-bad  .ac-hic { background: #d92d20; box-shadow: 0 2px 6px rgba(217,45,32,.35); }
.ac-hrow.t-warn .ac-hic { background: #f79009; box-shadow: 0 2px 6px rgba(247,144,9,.32); }
.ac-hrow.t-ok   .ac-hic { background: #12b76a; }
.ac-htxt { flex: 1; min-width: 0; }
.ac-hk, .ac-hv { display: block; }
.ac-hk { font-size: 12.5px; font-weight: 800; line-height: 1.3; color: #111827; }
.dark .ac-hk { color: #f5f5f5; }
.ac-hv { margin-top: 2px; font-size: 11px; line-height: 1.45; color: #5b6472; }
.dark .ac-hv { color: #b8b8b8; }
.ac-hv b { font-weight: 800; }
.ac-hrow.t-bad  .ac-hv b { color: #b42318; }
.ac-hrow.t-warn .ac-hv b { color: #b54708; }
.ac-hcta {
    display: inline-flex; align-items: center; gap: 3px; margin-top: 6px;
    padding: 4px 10px; border-radius: 7px; color: #fff; text-decoration: none;
    font-size: 11px; font-weight: 700; white-space: nowrap;
    transition: transform .13s, box-shadow .13s, opacity .13s;
}
.ac-hcta:hover { transform: translateY(-1px); color: #fff; opacity: .95; }
.ac-hcta iconify-icon { font-size: 13px; line-height: 1; }
.ac-hcta.a-speed  { background: linear-gradient(135deg,#f79009,#f04438); box-shadow: 0 3px 8px rgba(240,68,56,.3); }
.ac-hcta.a-schema { background: linear-gradient(135deg,#487fff,#7c5cff); box-shadow: 0 3px 8px rgba(72,127,255,.3); }

.ac-item {
    display: flex; gap: 10px; padding: 12px 16px;
    text-decoration: none; border-bottom: 1px solid #f1f5f9;
    transition: background .12s;
}
.dark .ac-item { border-color: #525252; }
.ac-item:last-child { border-bottom: 0; }
.ac-item:hover { background: #f8fafc; }
.dark .ac-item:hover { background: #333; }
.ac-item.is-read { background: #fbfcfd; }
.dark .ac-item.is-read { background: #3a3a3a; }

.ac-sev  { width: 4px; border-radius: 999px; flex: none; }
.ac-main { flex: 1; min-width: 0; }
.ac-title { display: block; font-size: 13px; font-weight: 600; color: #111827; }
.dark .ac-title { color: #fff; }
.ac-item.is-read .ac-title { font-weight: 500; }
.ac-ent {
    display: block; font-size: 11px; color: #6b7280; margin-top: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ac-why { display: block; font-size: 12px; color: #4b5563; margin-top: 4px; line-height: 1.45; }
.dark .ac-why { color: #d4d4d4; }

/* ---------- impact ---------- */
.ac-imp { text-align: right; flex: none; }
.ac-imp .n {
    display: inline-flex; align-items: center; gap: 3px;
    font-size: 13px; font-weight: 700; white-space: nowrap;
}
.ac-imp .n iconify-icon { font-size: 16px; line-height: 1; }
.ac-imp .u { display: block; font-size: 10px; color: #9ca3af; white-space: nowrap; }
.ac-fix {
    display: inline-block; margin-top: 4px;
    border: 0; padding: 4px 8px;
    font-size: 11px; line-height: 1.3; border-radius: 6px;
    background: var(--clr-primary, #487fff); color: #fff;
}

.ac-empty { padding: 36px 20px; text-align: center; }
.ac-empty .t { margin: 0 0 4px; font-size: 13px; font-weight: 600; color: #111827; }
.dark .ac-empty .t { color: #fff; }
.ac-empty .s { margin: 0; font-size: 12px; color: #6b7280; }

.ac-foot { padding: 10px 12px; background: #f8fafc; }
.dark .ac-foot { background: #333; }
.ac-cta {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; height: 40px; border-radius: 8px; text-decoration: none;
    font-size: 14px; font-weight: 500; white-space: nowrap;
    background: var(--clr-primary, #487fff); color: #fff;
}
.ac-cta:hover { opacity: .92; color: #fff; }

@media (max-width: 480px) {
    .ac-pop { position: fixed; left: 16px; right: 16px; width: auto; top: 64px;
              max-height: calc(100vh - 88px); }
    .ac-head {flex-direction: column-reverse;}
}
@media (prefers-reduced-motion: reduce) {
    .ac-btn, .ac-tab, .ac-item { transition: none; }
}
</style>

<div class="ac-wrap">

    <button type="button" id="ac-btn" class="ac-btn"
        aria-haspopup="true" aria-expanded="false" aria-label="Action Center">
        <iconify-icon icon="noto:high-voltage" class="ac-btn-icon"></iconify-icon>
        <span id="ac-count" class="ac-badge"></span>
    </button>

    <div id="ac-pop" class="ac-pop" style="display:none;">

        <div class="ac-head">
            <h6>
                <iconify-icon icon="noto:high-voltage" style="font-size:18px;"></iconify-icon>
                Action Center
                <span id="ac-new-tag" style="font-size:10px;font-weight:700;padding:2px 6px;
                      border-radius:999px;background:#fee2e2;color:#dc2626;"></span>
            </h6>
            <div class="flex w-full sm:w-fit justify-between items-center gap-3">
                <button type="button" id="ac-mark-all" class="ac-link">Mark all read</button>
                <button type="button" id="ac-close" class="flex h-8 w-8 items-center justify-center rounded-lg text-neutral-400 transition-all duration-200 bg-neutral-100 hover:bg-neutral-200 hover:text-neutral-900 hover:rotate-90 dark:text-neutral-300 dark:hover:bg-neutral-700 dark:hover:text-danger-400"
                    aria-label="Close Action Center">
                    <iconify-icon icon="mdi:close" class="text-lg"></iconify-icon>
                </button>
            </div>
        </div>

        <!-- The three figures ARE the filter. role="tablist" so a screen
             reader announces them as one choice rather than three
             unrelated buttons. -->
        <div class="ac-strip" role="tablist" aria-label="Filter insights">
            <button type="button" class="ac-tab is-on" role="tab" aria-selected="true" data-ac-tab="all">
                <p class="k">Insights</p>
                <p class="v" id="ac-open" style="color:#111827;">&mdash;</p>
            </button>
            <button type="button" class="ac-tab" role="tab" aria-selected="false" data-ac-tab="risk">
                <p class="k">At risk</p>
                <p class="v" id="ac-risk" style="color:#dc2626;">&mdash;</p>
            </button>
            <button type="button" class="ac-tab" role="tab" aria-selected="false" data-ac-tab="opp">
                <p class="k">Opportunities</p>
                <p class="v" id="ac-opp" style="color:#16a34a;">&mdash;</p>
            </button>
        </div>

        <div class="ac-scroll">

        <?php
        /* These two are problems, not statistics, so they are written the
           way the insight cards are: what is wrong in the headline, the
           cost underneath, and a button that fixes it. Only what needs
           attention is shown; a healthy site collapses to one quiet green
           row, and a site with nothing measured yet gets no strip at all. */
        $acSpeed      = $acHealth['speed'];
        $acSchema     = $acHealth['schema'];
        $acSchemaOn   = !empty($acSchema['checked']);
        $acInv        = $acSchemaOn ? (int)$acSchema['invalid'] : 0;
        $acMiss       = $acSchemaOn ? (array)$acSchema['missing'] : [];
        $acSchemaBad  = $acSchemaOn && ($acInv > 0 || $acMiss);
        $acSpeedBad   = ($acSpeed !== null && $acSpeed < 90);
        $acSpeedLink  = (string)($acHealth['links']['speed'] ?? '');
        $acSchemaLink = (string)($acHealth['links']['schema'] ?? '');
        $acIssues     = ($acSpeedBad ? 1 : 0) + ($acSchemaBad ? 1 : 0);
        if (($acSpeed !== null) || $acSchemaOn):
        ?>
        <div class="ac-health" id="ac-health"<?php echo $acIssues === 0 && ($acSpeed === null || !$acSchemaOn) ? ' style="display:none;"' : ''; ?>>
            <?php if ($acIssues > 0): ?>
                <div class="ac-hhdr">
                    <iconify-icon icon="solar:danger-triangle-bold"></iconify-icon>
                    Needs attention (<?php echo $acIssues; ?>)
                </div>
            <?php else: ?>
                <div class="ac-hhdr is-clear">Site health</div>
            <?php endif; ?>

            <?php if ($acSpeedBad):
                $acSpeedTone = $acSpeed < 50 ? 'bad' : 'warn';
            ?>
                <div class="ac-hrow t-<?php echo $acSpeedTone; ?>">
                    <span class="ac-hic"><iconify-icon icon="solar:bolt-bold"></iconify-icon></span>
                    <span class="ac-htxt">
                        <span class="ac-hk"><?php echo $acSpeed < 50 ? 'Your site is slow' : 'Your site speed needs work'; ?></span>
                        <span class="ac-hv">
                            PageSpeed <b><?php echo (int)$acSpeed; ?>/100</b> on mobile.
                            <?php echo $acSpeed < 50
                                ? 'Slow pages rank lower and lose visitors before they load.'
                                : 'Faster pages hold rankings better and convert more.'; ?>
                        </span>
                        <?php if ($acSpeedLink !== ''): ?>
                            <a class="ac-hcta a-speed" target="_blank" rel="noopener"
                               href="<?php echo htmlspecialchars($acSpeedLink, ENT_QUOTES); ?>">
                                Fix with Website Speedy
                                <iconify-icon icon="solar:arrow-right-linear"></iconify-icon>
                            </a>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($acSchemaBad):
                $acSchemaTone = ($acInv > 0 || count($acMiss) >= 3) ? 'bad' : 'warn';
            ?>
                <div class="ac-hrow t-<?php echo $acSchemaTone; ?>">
                    <span class="ac-hic"><iconify-icon icon="solar:code-scan-outline"></iconify-icon></span>
                    <span class="ac-htxt">
                        <span class="ac-hk"><?php echo $acInv > 0 ? 'Your schema has errors' : 'Rich results are missing'; ?></span>
                        <span class="ac-hv">
                            <?php if ($acInv > 0): ?>
                                <b><?php echo $acInv; ?></b> invalid item<?php echo $acInv > 1 ? 's' : ''; ?> found.
                                Google will not show rich results for these pages.
                            <?php else: ?>
                                <b><?php echo htmlspecialchars(implode(', ', array_slice($acMiss, 0, 2))); ?><?php
                                    echo count($acMiss) > 2 ? ' +' . (count($acMiss) - 2) : ''; ?></b> missing.
                                No stars, prices or FAQs in Google without this markup.
                            <?php endif; ?>
                        </span>
                        <?php if ($acSchemaLink !== ''): ?>
                            <a class="ac-hcta a-schema" target="_blank" rel="noopener"
                               href="<?php echo htmlspecialchars($acSchemaLink, ENT_QUOTES); ?>">
                                <?php echo $acInv > 0 ? 'Fix with our Schema app' : 'Add with our Schema app'; ?>
                                <iconify-icon icon="solar:arrow-right-linear"></iconify-icon>
                            </a>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($acIssues === 0): ?>
                <div class="ac-hrow t-ok">
                    <span class="ac-hic"><iconify-icon icon="solar:check-circle-bold"></iconify-icon></span>
                    <span class="ac-htxt">
                        <span class="ac-hk">Speed and schema look good</span>
                        <span class="ac-hv">Nothing to fix on the technical side right now.</span>
                    </span>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
            <div class="ac-health" id="ac-health" style="display:none;"></div>
        <?php endif; ?>

        <div id="ac-list" class="ac-list">
            <div class="ac-empty"><p class="s">Loading&hellip;</p></div>
        </div>

        </div><!-- /.ac-scroll -->

        <div class="ac-foot">
            <a href="action-center.php" class="ac-cta">
                Go to Action Center <span id="ac-total" style="opacity:.75;"></span>
                <iconify-icon icon="solar:arrow-right-linear" style="font-size:18px;"></iconify-icon>
            </a>
        </div>
    </div>
</div>

<script>
// Which page counts as "the dashboard", where the panel opens by itself.
//
// dashboard.php IS the dashboard in this app, so that name matters here.
// index.php is listed alongside it because the Wix build uses that
// name, and the list costs nothing while a rename would otherwise break
// the auto-open silently.
// Listing the names rather than hardcoding one keeps this correct if the
// file is renamed again.
//
// Declared on window rather than as a top-level const: this partial loads
// on every page, and a bare `const` throws "already declared" the moment
// anything else uses the name, a parse error that takes down every other
// script on the page, not just this one.
window.isHomePage = <?=
    in_array(basename((string)($_SERVER['PHP_SELF'] ?? '')),
             ['dashboard.php', 'index.php'], true) ? 'true' : 'false'
?>;
(function () {
    const API      = <?= json_encode($acApiUrl) ?>;
    const btn      = document.getElementById('ac-btn');
    const pop      = document.getElementById('ac-pop');
    const count    = document.getElementById('ac-count');
    const list     = document.getElementById('ac-list');
    const markAll  = document.getElementById('ac-mark-all');
    const closeBtn = document.getElementById('ac-close');
    const tabs     = Array.from(document.querySelectorAll('[data-ac-tab]'));

    let listLoaded = false;
    let allRows    = [];      // fetched once, filtered in place
    let activeTab  = 'all';

    const SEV = {
        critical: '#dc2626',
        high:     '#d97706',
        medium:   '#2563eb',
        low:      '#16a34a'
    };

    const fmt = n => Number(n || 0).toLocaleString();
    const esc = s => String(s ?? '').replace(/[&<>"']/g,
        c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

    async function api(body) {
        const res = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    closeBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        pop.style.display = 'none';
        btn.setAttribute('aria-expanded', 'false');
    });

    function empty(title, sub) {
        return `<div class="ac-empty">
            <p class="t">${title}</p>
            <p class="s">${sub}</p>
        </div>`;
    }

    async function loadSummary() {
        try {
            const d = await api({ action: 'summary' });
            if (!d || !d.success) return;
            const s = d.summary;

            count.textContent   = s.unread > 9 ? '9+' : s.unread;
            count.style.display = s.unread ? 'flex' : 'flex';

            // Same arrows the rows use. A minus sign in front of a figure
            // reads as a negative amount; a downward arrow beside it reads
            // as clicks going away, which is what it means.
            document.getElementById('ac-risk').innerHTML =
                '<iconify-icon icon="mdi:trending-down" aria-hidden="true"></iconify-icon>' + fmt(s.at_risk);
            document.getElementById('ac-opp').innerHTML =
                '<iconify-icon icon="mdi:trending-up" aria-hidden="true"></iconify-icon>' + fmt(s.available);

            // No arrow on the count. It is a quantity, not a movement.
            document.getElementById('ac-open').textContent    = fmt(s.open_count);
            document.getElementById('ac-total').textContent   = '(' + fmt(s.open_count) + ')';
            document.getElementById('ac-new-tag').textContent = s.unread ? s.unread + ' new' : '';
            markAll.disabled = !s.unread;
        } catch (e) {
            markAll.disabled = true;
        }
    }

    /**
     * One row.
     *
     * The arrow and the colour say the same thing twice on purpose:
     * colour alone fails for anyone who cannot separate red from green,
     * and a 16px arrow alone is easy to miss.
     */
    function rowHtml(r) {
        const gain = Number(r.impact_clicks) >= 0;
        const read = r.status !== 'new';
        const why  = r.why_text || '';
        const col  = gain ? '#16a34a' : '#dc2626';
        const icon = gain ? 'mdi:trending-up' : 'mdi:trending-down';
        const word = gain ? 'up' : 'down';

        return `
        <a href="action-center.php?focus=${r.id}" class="ac-item ${read ? 'is-read' : ''}">
            <span class="ac-sev" style="background:${SEV[r.severity] || '#9ca3af'};"></span>
            <span class="ac-main">
                <span class="ac-tag ${gain ? 'is-opp' : 'is-risk'}">${gain ? 'Opportunity' : 'At risk'}</span>
                <span class="ac-title">${esc(r.title)}</span>
                <span class="ac-ent">${esc(r.entity)}</span>
                <span class="ac-why">${esc(why.length > 95 ? why.slice(0, 95) + '\u2026' : why)}</span>
            </span>
            <span class="ac-imp">
                <span class="n" style="color:${col};" title="${word} ${fmt(Math.abs(r.impact_clicks))} clicks per month">
                    <iconify-icon icon="${icon}" aria-hidden="true"></iconify-icon>
                    ${fmt(Math.abs(r.impact_clicks))}
                </span>
                <span class="u">clicks/mo</span>
                <span class="ac-fix">Fix issue</span>
            </span>
        </a>`;
    }

    /**
     * Paint whichever tab is selected.
     *
     * Filtered here rather than refetched: the split is by the sign of
     * impact_clicks, which is already in the rows we hold. A round trip
     * per tab would make the filter feel slower than scrolling past the
     * rows it removes.
     */
    function paint() {
        let rows = allRows;

        if (activeTab === 'risk') {
            rows = allRows.filter(r => Number(r.impact_clicks) < 0);
        } else if (activeTab === 'opp') {
            rows = allRows.filter(r => Number(r.impact_clicks) >= 0);
        }

        if (!rows.length) {
            // The message names the tab. "Nothing here" beside a count of
            // 12 in the tab next door is confusing on its own.
            const msg = {
                risk: ['Nothing losing clicks',   'No drops found in the latest check.'],
                opp:  ['No opportunities yet',    'These appear once there is enough data to compare.'],
                all:  ['Nothing needs attention', 'New insights appear after the daily check.']
            }[activeTab];

            list.innerHTML = empty(msg[0], msg[1]);
            return;
        }

        // Five fits without the popup needing a scroll on a laptop; the
        // full list is one click away in the footer.
        list.innerHTML = rows.slice(0, 5).map(rowHtml).join('');
    }

    async function loadList() {
        try {
            // Fetched wider than the five shown, so the tabs have something
            // to filter. With a limit of four, a feed of four drops would
            // leave Opportunities empty while its header counted several.
            const d = await api({ action: 'feed', status: 'open', limit: 30 });

            allRows = (d && d.success && Array.isArray(d.rows)) ? d.rows : [];
            paint();

        } catch (e) {
            list.innerHTML = empty('Insights not available yet',
                                   'Your Search Console data is still being collected.');
        }
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function (e) {
            e.stopPropagation();

            activeTab = t.getAttribute('data-ac-tab');

            tabs.forEach(function (o) {
                const on = (o === t);
                o.classList.toggle('is-on', on);
                o.setAttribute('aria-selected', String(on));
            });

            paint();
        });
    });


    /* ---------------- site health inside the panel ----------------
       The strip is rendered server-side from cache when there is one.
       When there is not, the checks run HERE, on first open - not on
       page load: this partial is on every page, and firing PSI on every
       page load would be a live API call behind every click in the app.
       Opening the panel is a deliberate act, so it is a fair moment to
       spend one. */
    var AC_HEALTH  = <?php echo json_encode($acHealth, JSON_UNESCAPED_SLASHES); ?>;
    var AC_BASE    = <?php echo json_encode($acBase); ?>;
    var acHealthEl = document.getElementById('ac-health');
    var acHealthRan = false;
    var acSpeedRunning = false;
    var acSchemaRunning = false;

    function acEsc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function acHealthRow(tone, icon, head, body, link, linkCls, linkText) {
        return '<div class="ac-hrow t-' + tone + '">' +
            '<span class="ac-hic"><iconify-icon icon="' + icon + '"></iconify-icon></span>' +
            '<span class="ac-htxt">' +
                '<span class="ac-hk">' + acEsc(head) + '</span>' +
                '<span class="ac-hv">' + body + '</span>' +
                (link ? '<a class="ac-hcta ' + linkCls + '" target="_blank" rel="noopener" href="' + acEsc(link) + '">' +
                        acEsc(linkText) + '<iconify-icon icon="solar:arrow-right-linear"></iconify-icon></a>' : '') +
            '</span>' +
        '</div>';
    }

    function acRenderHealth() {
        if (!acHealthEl) { return; }

        var sp   = AC_HEALTH.speed;
        var sc   = AC_HEALTH.schema || {};
        var miss = sc.missing || [];
        var inv  = sc.invalid || 0;
        var spLink = (AC_HEALTH.links && AC_HEALTH.links.speed) || '';
        var scLink = (AC_HEALTH.links && AC_HEALTH.links.schema) || '';

        var speedDone = (sp !== null && sp !== undefined);
        var speedBad  = speedDone && sp < 90;
        var schemaBad = sc.checked && (inv > 0 || miss.length);
        var rows = '', issues = 0;

        /* Each check reports for itself. One shared "checking" row meant
           the slower of the two (speed, up to 30s) finished in silence
           while the strip already looked settled. */
        if (!speedDone && acSpeedRunning) {
            rows += acHealthRow('busy', 'solar:bolt-bold', 'Checking site speed',
                'Running PageSpeed on your homepage, this takes up to half a minute.', '', '', '');
        }
        if (!sc.checked && acSchemaRunning) {
            rows += acHealthRow('busy', 'solar:code-scan-outline', 'Reading structured data',
                'Looking at the markup on your homepage.', '', '', '');
        }

        if (speedBad) {
            issues++;
            rows += acHealthRow(sp < 50 ? 'bad' : 'warn', 'solar:bolt-bold',
                sp < 50 ? 'Your site is slow' : 'Your site speed needs work',
                'PageSpeed <b>' + sp + '/100</b> on mobile. ' +
                (sp < 50 ? 'Slow pages rank lower and lose visitors before they load.'
                         : 'Faster pages hold rankings better and convert more.'),
                spLink, 'a-speed', 'Fix with Website Speedy');
        }
        if (schemaBad) {
            issues++;
            rows += acHealthRow((inv > 0 || miss.length >= 3) ? 'bad' : 'warn', 'solar:code-scan-outline',
                inv > 0 ? 'Your schema has errors' : 'Rich results are missing',
                inv > 0
                    ? '<b>' + inv + '</b> invalid item' + (inv > 1 ? 's' : '') + ' found. Google will not show rich results for these pages.'
                    : '<b>' + acEsc(miss.slice(0, 2).join(', ')) + (miss.length > 2 ? ' +' + (miss.length - 2) : '') +
                      '</b> missing. No stars, prices or FAQs in Google without this markup.',
                scLink, 'a-schema', inv > 0 ? 'Fix with our Schema app' : 'Add with our Schema app');
        }
        if (!issues && speedDone && sc.checked) {
            rows += acHealthRow('ok', 'solar:check-circle-bold', 'Speed and schema look good',
                'Nothing to fix on the technical side right now.', '', '', '');
        }
        if (!rows) { acHealthEl.style.display = 'none'; return; }

        acHealthEl.innerHTML =
            (issues > 0
                ? '<div class="ac-hhdr"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon> Needs attention (' + issues + ')</div>'
                : '<div class="ac-hhdr is-clear">Site health</div>') + rows;
        acHealthEl.style.display = '';
    }

    function acRunHealthChecks() {
        if (acHealthRan) { return; }
        acHealthRan = true;

        var sp = AC_HEALTH.speed;
        var sc = AC_HEALTH.schema || {};

        if (sp === null || sp === undefined) {
            acSpeedRunning = true;
            acRenderHealth();   // this check's own pending row
            fetch(AC_BASE + '/api/run_speed_test.php?strategy=mobile', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var v = (d && d.result && d.result.perf_score);
                    if (v === undefined || v === null) { throw 0; }
                    AC_HEALTH.speed = Math.round(Number(v));
                    acSpeedRunning = false;
                    acRenderHealth();
                })
                .catch(function () { acSpeedRunning = false; acRenderHealth(); });
        }

        if (!sc.checked) {
            acSchemaRunning = true;
            acRenderHealth();
            fetch(AC_BASE + '/api/run_schema_check.php?entity=%2F', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success || !d.result) { throw 0; }
                    var found = {}, inv = 0;
                    (d.result.items || []).forEach(function (it) {
                        found[it.type] = true;
                        if (!it.valid) { inv++; }
                    });
                    (d.result.other_types || []).forEach(function (t) { found[t] = true; });
                    var want = sc.want || [];
                    AC_HEALTH.schema = {
                        checked: true,
                        invalid: inv,
                        want: want,
                        missing: want.filter(function (w) {
                            if (found[w]) { return false; }
                            if (w === 'Article' && (found.BlogPosting || found.NewsArticle)) { return false; }
                            return true;
                        })
                    };
                    acSchemaRunning = false;
                    acRenderHealth();
                })
                .catch(function () { acSchemaRunning = false; acRenderHealth(); });
        }
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        document.getElementById('dropdownProfile')?.classList.add('hidden');

        const open = pop.style.display === 'none';
        pop.style.display = open ? 'flex' : 'none';
        btn.setAttribute('aria-expanded', String(open));

        if (open && !listLoaded) { listLoaded = true; loadList(); }
        /* After the list request, never before it: the insights feed is
           what the user opened this for, and it must not queue behind a
           speed test. */
        if (open) { setTimeout(acRunHealthChecks, 0); }
    });

    pop.addEventListener('click', e => e.stopPropagation());

    /* There is deliberately no document-level click handler here.

       One used to close the panel on any click anywhere, guarded only by
       stopPropagation on the panel itself. That guard covers the panel and
       nothing else, so every other overlay on the page closed this one as
       a side effect: dismissing the review popup shut the Action Center
       too, and the merchant never saw what they had opened.

       The panel now closes by its own close button, by the bell that
       opened it, or by Escape. All three are things the merchant did on
       purpose. */

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && pop.style.display !== 'none') {
            pop.style.display = 'none';
            btn.setAttribute('aria-expanded', 'false');
        }
    });

    markAll.addEventListener('click', async function (e) {
        e.stopPropagation();
        try {
            await api({ action: 'mark_all' });
            await loadSummary();
            await loadList();
        } catch (err) { /* ignore */ }
    });

    if (isHomePage) {
        pop.style.display = 'flex';
        btn.setAttribute('aria-expanded', 'true');

        if (!listLoaded) {
            listLoaded = true;
            loadList();
        }
        setTimeout(acRunHealthChecks, 0);
    }

    loadSummary();
    setInterval(loadSummary, 60000);
})();
</script>

<?php endif; ?>
