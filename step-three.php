<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/google/get_account.php';
require_once __DIR__ . '/includes/google/get_bigcommerce_site.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['show_review_modal'] = true;

$instanceId = $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;

if (!$instanceId) {
    die("Missing instance ID");
}

// Google Account
$google = getGoogleAccountByUser($instanceId);
$isConnected = ($google && !empty($google['access_token']));

if (!$isConnected) {
    die("<div class='p-10 text-center text-red-600'>Google account not connected.</div>");
}

// Domain from verification table
$stmt = $pdo->prepare("SELECT site_url FROM gsc_domain_verifications WHERE instance_id = ?");
$stmt->execute([$instanceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// Build a usable website URL for default sitemap
$storedSiteUrl = $row['site_url'] ?? '';
$siteUrl = null;

if ($storedSiteUrl) {
    $storedSiteUrl = trim($storedSiteUrl);
    if (stripos($storedSiteUrl, 'sc-domain:') === 0) {
        // Convert sc-domain:example.com -> https://example.com/
        $host = substr($storedSiteUrl, strlen('sc-domain:'));
        $host = preg_replace('#^www\.#i', '', $host);
        $siteUrl = 'https://' . $host . '/';
    } else {
        // Assume it's already a URL, just normalize
        $siteUrl = rtrim($storedSiteUrl, '/') . '/';
    }
}

$sitemapDefault = $siteUrl ? $siteUrl . "sitemap.xml" : "";

// Optional: trial expiry for 24h preview popup countdown (after sitemap success)
$trialExpiresOnStep3 = null;
try {
    $stmt = $pdo->prepare("
        SELECT started_at, expires_on
        FROM app_free_trials
        WHERE instance_id = ? AND status = 'active'
        ORDER BY started_at DESC
        LIMIT 1
    ");
    $stmt->execute([$instanceId]);
    $trial = $stmt->fetch(PDO::FETCH_ASSOC);


    if ($trial && !empty($trial['expires_on'])) {
        $trialExpiresOnStep3 = $trial['expires_on'];
    }
} catch (Throwable $e) {
    // ignore
}

// -----------------------------------------------------
// 2. LOAD OR CREATE VERIFICATION RECORD
// -----------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM gsc_domain_verifications WHERE instance_id = ?");
$stmt->execute([$instanceId]);
$domain = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$domain) {
    $stmt = $pdo->prepare("
        INSERT INTO gsc_domain_verifications 
            (instance_id, site_url, verification_status)
        VALUES (?, ?, 'pending')
    ");
    $stmt->execute([$instanceId, $normalizedSiteUrl]);

    $domain = [
        'instance_id'         => $instanceId,
        'site_url'            => $normalizedSiteUrl,
        'meta_token'          => null,
        'meta_tag'            => null,
        'verification_status' => 'pending'
    ];
}


$metaTag   = $domain['meta_tag'] ?? '';
$metaToken = $domain['meta_token'] ?? '';

// -----------------------------------------------------
// 3. SITEMAP ROW FOR THE CONNECTION OVERVIEW CARD
//
// SELECT * on purpose: discovered_pages and last_synced_at were added
// later, and selecting them by name would break this page on any install
// that has not run those migrations yet.
//
// This block renders hidden and is revealed by JS after a successful
// submit, so on a first submission there is no row yet — the card then
// shows the just-submitted state rather than blanks.
// -----------------------------------------------------
$sitemapRow = null;

try {
    $stmt = $pdo->prepare("
        SELECT * FROM sitemaps
        WHERE instance_id = ?
        ORDER BY last_submitted DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$instanceId]);
    $sitemapRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    // table or columns missing — the card falls back to its defaults
}

$ovSitemapUrl   = $sitemapRow['sitemap_url'] ?? $sitemapDefault;
$ovSubmittedOn  = $sitemapRow['last_submitted'] ?? null;
$ovLastSync     = $sitemapRow['last_synced_at'] ?? null;
$ovPages        = $sitemapRow['discovered_pages'] ?? null;
$ovErrors       = (int)($sitemapRow['errors'] ?? 0);

// Which screen opens.
//
// This used to be settled entirely in JS: the form rendered, a request went
// off to Google, and about a second later the success view replaced it. On
// a revisit that meant a flash of the wrong screen every time.
//
// The database already knows whether this instance has submitted a sitemap,
// so the correct screen can be chosen before anything is sent to the
// browser. The JS check still runs afterwards and corrects this if Google
// disagrees — for instance when the sitemap was removed in Search Console
// directly.
$alreadySubmitted = !empty($sitemapRow);


?>
<script>
    // What our own database thinks. Google decides which screen actually
    // opens, but this tells the JS whether a reveal is a first-time event
    // (worth the confetti) or a revisit.
    window.SITEMAP_ALREADY_SUBMITTED = <?= json_encode((bool)$alreadySubmitted) ?>;

    window.TRIAL_EXPIRES_AT_STEP3 = <?= json_encode(
                                        $trialExpiresOnStep3
                                            ? preg_replace('/\s/', 'T', $trialExpiresOnStep3) . 'Z'
                                            : null
                                    ); ?>;
</script>

<script>
    window.showAlert = function(message, type = 'success') {
        const colors = {
            success: '#16a34a',
            error: '#dc2626',
            warning: '#f59e0b'
        };

        const n = document.createElement('div');
        n.textContent = message;
        n.style.position = 'fixed';
        n.style.right = '12px';
        n.style.top = '12px';
        n.style.padding = '10px 14px';
        n.style.background = colors[type] || colors.success;
        n.style.color = '#fff';
        n.style.borderRadius = '8px';
        n.style.zIndex = 9999;
        document.body.appendChild(n);
        setTimeout(() => n.remove(), 3000);
    };
</script>



<?php include __DIR__ . '/partials/review-popup.php'; ?>
<style>
/* =======================================================================
   Step three, submission form + submitting overlay
   Scoped to .s3x / #sitemapSubmitOverlay so nothing leaks out.
   ======================================================================= */
.s3x {
    --x-ink:     #0f172a;
    --x-ink-2:   #475569;
    --x-ink-3:   #94a3b8;
    --x-line:    #e8ecf4;
    --x-blue:    #3b5bfd;
    --x-blue-2:  #6366f1;
    --x-surface: #ffffff;
    --x-mono: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
    text-align: left;
}
.dark .s3x {
    --x-ink:     #f1f5f9;
    --x-ink-2:   #cbd5e1;
    --x-line:    #3f3f46;
    --x-surface: #262626;
}

.s3x-shell {
    display: grid; grid-template-columns: minmax(0,0.8fr) minmax(0,1.2fr);
    max-width: 940px; margin: 0 auto;
    border: 1px solid var(--x-line); border-radius: 20px; overflow: hidden;
    background: var(--x-surface);
    box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 8px 28px rgba(15,23,42,.06);
}

/* ---------------- left: illustration ---------------- */
.s3x-art {
    position: relative; overflow: hidden;
    background: linear-gradient(165deg,#f5f8ff 0%,#eff3ff 60%,#f7f5ff 100%);
    border-right: 1px solid var(--x-line);
    padding: 26px 22px 22px;
    display: flex; flex-direction: column; justify-content: space-between;
}
.dark .s3x-art { background: linear-gradient(165deg,#1e2233 0%,#242a44 100%); }

.s3x-stage { position: relative; flex: 1; min-height: 150px;
             display: flex; align-items: center; justify-content: center; }

/* a page, drawn rather than shipped as an image */
.s3x-window {
    width: 148px; border-radius: 11px; background: #fff;
    box-shadow: 0 4px 14px rgba(15,23,42,.1), 0 12px 32px rgba(59,91,253,.1);
    overflow: hidden;
}
.dark .s3x-window { background: #2f3550; }
.s3x-bar { display: flex; gap: 4px; padding: 7px 9px; background: #f6f8fc;
           border-bottom: 1px solid #eceff6; }
.dark .s3x-bar { background: #363d5c; border-color: #414a6e; }
.s3x-bar i { width: 6px; height: 6px; border-radius: 50%; display: block; }
.s3x-lines { padding: 11px 10px; display: flex; flex-direction: column; gap: 6px; }
.s3x-lines span { height: 5px; border-radius: 3px; background: #e6eaf3; display: block; }
.dark .s3x-lines span { background: #414a6e; }

.s3x-code {
    position: absolute; left: 14%; bottom: 20%;
    width: 42px; height: 42px; border-radius: 12px;
    background: linear-gradient(135deg,var(--x-blue) 0%,var(--x-blue-2) 100%);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px rgba(59,91,253,.35);
}
.s3x-code iconify-icon { font-size: 22px; color: #fff; }

.s3x-plane {
    position: absolute; right: 12%; bottom: 24%;
    width: 40px; height: 40px; border-radius: 50%;
    background: #fff; display: flex; align-items: center; justify-content: center;
    box-shadow: 0 3px 12px rgba(15,23,42,.12);
}
.dark .s3x-plane { background: #2f3550; }
.s3x-plane iconify-icon { font-size: 19px; color: var(--x-blue); }

.s3x-dot { position: absolute; width: 5px; height: 5px; border-radius: 50%;
           background: #c7d2fe; }
.s3x-dot.d1 { top: 18%; left: 12%; }
.s3x-dot.d2 { top: 30%; right: 14%; background: #34d399; }
.s3x-dot.d3 { bottom: 14%; left: 42%; background: #fbbf24; }
.s3x-plus { position: absolute; color: #a5b4fc; font-size: 13px; font-weight: 700; }
.s3x-plus.p1 { top: 12%; left: 30%; }
.s3x-plus.p2 { top: 24%; right: 8%;  color: #34d399; }
.s3x-plus.p3 { bottom: 30%; left: 6%; }

.s3x-art-copy { margin-top: 18px; }
.s3x-art-copy h4 { margin: 0 0 5px; font-size: 13.5px; font-weight: 700; color: var(--x-ink); }
.s3x-art-copy p  { margin: 0; font-size: 11.5px; line-height: 1.55; color: var(--x-ink-3); }

/* ---------------- right: form ---------------- */
.s3x-body { padding: 26px 28px; }

.s3x-step {
    display: inline-block; padding: 4px 11px; border-radius: 999px;
    background: #eef2ff; color: var(--x-blue);
    font-size: 11px; font-weight: 650;
}
.dark .s3x-step { background: rgba(59,91,253,.18); color: #a5b4fc; }

.s3x-h2 { margin: 12px 0 0; font-size: 22px; font-weight: 700;
          letter-spacing: -.025em; color: var(--x-ink); }
.s3x-lead { margin: 6px 0 0; font-size: 13px; line-height: 1.55; color: var(--x-ink-3); }

.s3x-label { display: block; margin: 18px 0 7px;
             font-size: 12.5px; font-weight: 600; color: var(--x-ink-2); }

.s3x-input-wrap { position: relative; }
.s3x-input-ic {
    position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
    display: flex; align-items: center; justify-content: center; pointer-events: none;
}
.s3x-input-ic iconify-icon { font-size: 17px; color: var(--x-ink-3); }
.s3x-input {
    /* The monospace face was wide enough that a normal sitemap URL
       overflowed and the browser scrolled "https://" out of view. The
       regular face fits the whole thing, and the tighter icon gutter
       leaves more room again. */
    width: 100%; height: 46px; padding: 0 14px 0 40px;
    border: 1px solid #dbe1ee; border-radius: 12px;
    background: var(--x-surface); color: var(--x-ink);
    font-family: inherit; font-size: 13.5px; letter-spacing: -.005em;
    transition: all .16s;
}
.s3x-input:focus { outline: none; border-color: var(--x-blue-2);
                   box-shadow: 0 0 0 4px rgba(59,91,253,.1); }
.dark .s3x-input { border-color: #3f4666; }

/* locked domain + editable file name, joined into one field */
.s3x-combo {
    display: flex; align-items: stretch; height: 46px;
    border: 1px solid #dbe1ee; border-radius: 12px; overflow: hidden;
    background: var(--x-surface); transition: all .16s;
}
.s3x-combo:focus-within { border-color: var(--x-blue-2);
                          box-shadow: 0 0 0 4px rgba(59,91,253,.1); }
.dark .s3x-combo { border-color: #3f4666; }

.s3x-combo-ic {
    display: flex; align-items: center; padding: 0 0 0 13px; flex: none;
}
.s3x-combo-ic iconify-icon { font-size: 17px; color: var(--x-ink-3); }

.s3x-combo-prefix {
    display: flex; align-items: center; padding: 0 2px 0 9px;
    font-size: 13.5px; color: var(--x-ink-3); white-space: nowrap;
    user-select: none; flex: none; max-width: 58%;
    overflow: hidden; text-overflow: ellipsis;
}

.s3x-combo-input {
    flex: 1; min-width: 0; border: 0; outline: none; background: transparent;
    padding: 0 14px 0 0; font-family: inherit; font-size: 13.5px;
    color: var(--x-ink); letter-spacing: -.005em;
}
.s3x-combo-input::placeholder { color: var(--x-ink-3); }

.s3x-note {
    display: flex; gap: 10px; align-items: flex-start; margin-top: 12px;
    padding: 12px 14px; border-radius: 12px;
    background: #f4f7ff; border: 1px solid #dfe7fd;
}
.dark .s3x-note { background: #1f2438; border-color: #333a58; }
.s3x-note iconify-icon { font-size: 17px; color: var(--x-blue); flex: none; margin-top: 1px; }
.s3x-note p { margin: 0; font-size: 12.5px; line-height: 1.55; color: var(--x-ink-2); }

.s3x-cta {
    width: 100%; margin-top: 14px; height: 48px; border: 0; border-radius: 12px;
    cursor: pointer; font-family: inherit; font-size: 14.5px; font-weight: 650; color: #fff;
    background: linear-gradient(100deg,var(--x-blue) 0%,var(--x-blue-2) 55%,#7c6cf5 100%);
    background-size: 200% 100%;
    box-shadow: 0 2px 6px rgba(59,91,253,.28), 0 10px 24px rgba(59,91,253,.22);
    display: flex; align-items: center; justify-content: center; gap: 10px;
    transition: transform .16s cubic-bezier(.4,0,.2,1), box-shadow .16s, filter .16s;
}
.s3x-cta:hover { transform: translateY(-1px); filter: brightness(1.05);
                 box-shadow: 0 4px 10px rgba(59,91,253,.32), 0 16px 36px rgba(59,91,253,.28); }
.s3x-cta:active { transform: translateY(0); }
.s3x-cta:disabled { opacity: .65; cursor: wait; transform: none; }
.s3x-cta iconify-icon { font-size: 18px; }

.s3x-chips { display: grid; grid-template-columns: repeat(3,1fr); gap: 8px; margin-top: 14px; }
.s3x-chip {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    padding: 9px 8px; border-radius: 10px;
    background: #f7f9fd; border: 1px solid var(--x-line);
    font-size: 11px; font-weight: 600; color: var(--x-ink-2); text-align: center;
}
.dark .s3x-chip { background: #1e1e1e; }
.s3x-chip iconify-icon { font-size: 14px; color: var(--x-blue); flex: none; }

.s3x-reconnect {
    width: 100%; margin-top: 12px; display: flex; align-items: center;
    justify-content: center; gap: 10px;
    background: var(--x-surface); color: var(--x-ink-2);
    border: 1px solid #dbe1ee; border-radius: 12px;
    padding: 11px 18px; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all .16s;
}
.s3x-reconnect:hover { border-color: #b9c3da; box-shadow: 0 2px 8px rgba(15,23,42,.06); }
.dark .s3x-reconnect { background: #2f3550; border-color: #3f4666; color: #cbd5e1; }
.s3x-reconnect-hint { margin: 8px 0 0; font-size: 11.5px; line-height: 1.5;
                      color: var(--x-ink-3); text-align: center; }

@media (max-width: 900px) {
    .s3x-shell { grid-template-columns: 1fr; }
    .s3x-art { border-right: 0; border-bottom: 1px solid var(--x-line); }
    .s3x-body { padding: 22px 20px; }
    .s3x-chips { grid-template-columns: 1fr; }
}

/* ---------------- checking state ---------------- */
.s3x-check {
    max-width: 440px; margin: 0 auto; padding: 34px 34px 28px;
    background: var(--x-surface); border: 1px solid var(--x-line);
    border-radius: 22px;
    box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 12px 40px rgba(15,23,42,.08);
    animation: s3Rise .3s cubic-bezier(.22,1,.36,1);
}
.s3x-check-head { text-align: center; margin-bottom: 24px; }
.s3x-check h3 { margin: 0 0 7px; font-size: 18px; font-weight: 700;
                letter-spacing: -.025em; color: var(--x-ink); }
.s3x-check-head p { margin: 0 auto; font-size: 13px; line-height: 1.55;
                    color: var(--x-ink-3); max-width: 310px; }

/* concentric pulse behind the spinner */
.s3x-check-orb {
    position: relative; width: 62px; height: 62px; margin: 0 auto 18px;
    display: flex; align-items: center; justify-content: center;
}
.s3x-check-orb::before, .s3x-check-orb::after {
    content: ""; position: absolute; inset: 0; border-radius: 50%;
    background: rgba(59,91,253,.1);
    animation: s3Pulse 2.2s ease-out infinite;
}
.s3x-check-orb::after { animation-delay: 1.1s; }
@keyframes s3Pulse {
    0%   { transform: scale(.75); opacity: .85; }
    100% { transform: scale(1.25); opacity: 0; }
}
.s3x-check-orb i {
    position: relative; width: 26px; height: 26px; border-radius: 50%;
    border: 2.5px solid #dbe3ff; border-top-color: var(--x-blue);
    animation: s3Spin .75s linear infinite; display: block;
}

/* the steps read as a sequence, so they are joined by a rail */
.s3x-track { position: relative; padding-left: 4px; }
.s3x-track::before {
    content: ""; position: absolute; left: 13px; top: 14px; bottom: 14px;
    width: 2px; background: #eaeef7; border-radius: 2px;
}
.dark .s3x-track::before { background: #3f3f46; }

.s3x-tstep { position: relative; display: flex; align-items: center; gap: 14px;
             padding: 9px 0; }
.s3x-tstep-ic {
    position: relative; z-index: 1; width: 20px; height: 20px; flex: none;
    border-radius: 50%; border: 2px solid #dbe1ee; background: var(--x-surface);
    transition: all .25s cubic-bezier(.4,0,.2,1);
}
.dark .s3x-tstep-ic { border-color: #4b5169; }
.s3x-tstep-t { font-size: 13px; font-weight: 550; color: var(--x-ink-3);
               transition: color .25s; }

.s3x-tstep.is-active .s3x-tstep-ic {
    border-color: #c7d2fe; border-top-color: var(--x-blue);
    animation: s3Spin .75s linear infinite;
}
.s3x-tstep.is-active .s3x-tstep-t { color: var(--x-blue); font-weight: 650; }

.s3x-tstep.is-done .s3x-tstep-ic {
    border-color: var(--x-blue); background: var(--x-blue);
    animation: s3Tick .32s cubic-bezier(.34,1.56,.64,1);
}
.s3x-tstep.is-done .s3x-tstep-ic::after {
    content: ""; position: absolute; left: 5px; top: 1.5px;
    width: 4px; height: 8px; border: solid #fff;
    border-width: 0 2px 2px 0; transform: rotate(45deg);
}
.s3x-tstep.is-done .s3x-tstep-t { color: var(--x-ink); }
@keyframes s3Tick { from { transform: scale(.6); } to { transform: scale(1); } }

.s3x-check-foot {
    margin: 22px 0 0; padding-top: 16px; border-top: 1px solid var(--x-line);
    font-size: 12px; font-weight: 550; color: var(--x-ink-3); text-align: center;
}

/* ---------------- submitting overlay ---------------- */
.s3x-ov {
    position: fixed; inset: 0; z-index: 90;
    background: rgba(15,23,42,.42); backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center; padding: 20px;
    animation: s3Fade .2s ease-out;
}
.s3x-ov.hidden { display: none; }
@keyframes s3Fade { from { opacity: 0; } to { opacity: 1; } }

.s3x-ov-card {
    width: min(340px, 100%); background: #fff; border-radius: 20px;
    padding: 28px 24px 22px; text-align: center;
    box-shadow: 0 24px 60px rgba(15,23,42,.25);
    animation: s3Rise .28s cubic-bezier(.22,1,.36,1);
}
.dark .s3x-ov-card { background: #262626; }
@keyframes s3Rise { from { opacity: 0; transform: translateY(14px) scale(.97); }
                    to   { opacity: 1; transform: none; } }

.s3x-ov-spin {
    width: 52px; height: 52px; border-radius: 50%; margin: 0 auto 16px;
    background: #eef2ff; display: flex; align-items: center; justify-content: center;
}
.dark .s3x-ov-spin { background: #363d5c; }
.s3x-ov-spin span {
    width: 24px; height: 24px; border-radius: 50%;
    border: 2.5px solid #c7d2fe; border-top-color: #3b5bfd;
    animation: s3Spin .8s linear infinite; display: block;
}
@keyframes s3Spin { to { transform: rotate(360deg); } }

.s3x-ov-card h3 { margin: 0 0 6px; font-size: 17px; font-weight: 700;
                  letter-spacing: -.02em; color: #0f172a; }
.dark .s3x-ov-card h3 { color: #f1f5f9; }
.s3x-ov-card > p { margin: 0 auto 20px; font-size: 12.5px; line-height: 1.55;
                   color: #94a3b8; max-width: 260px; }

.s3x-steps { border: 1px solid #eceff6; border-radius: 14px; overflow: hidden; text-align: left; }
.dark .s3x-steps { border-color: #3f3f46; }
.s3x-stp { display: flex; align-items: center; gap: 11px; padding: 11px 14px;
           border-bottom: 1px solid #f2f4f9; }
.dark .s3x-stp { border-color: #3f3f46; }
.s3x-stp:last-child { border-bottom: 0; }

.s3x-stp-ic { width: 18px; height: 18px; border-radius: 50%; flex: none;
              border: 2px solid #dbe1ee; position: relative; }
.s3x-stp-t { font-size: 12.5px; font-weight: 550; color: #94a3b8; }

.s3x-stp.is-active .s3x-stp-ic {
    border-color: #c7d2fe; border-top-color: #3b5bfd;
    animation: s3Spin .8s linear infinite;
}
.s3x-stp.is-active .s3x-stp-t { color: #3b5bfd; font-weight: 650; }

.s3x-stp.is-done .s3x-stp-ic { border-color: #3b5bfd; background: #3b5bfd; }
.s3x-stp.is-done .s3x-stp-ic::after {
    content: ""; position: absolute; left: 4px; top: 1px;
    width: 4px; height: 8px; border: solid #fff;
    border-width: 0 2px 2px 0; transform: rotate(45deg);
}
.s3x-stp.is-done .s3x-stp-t { color: #0f172a; }
.dark .s3x-stp.is-done .s3x-stp-t { color: #f1f5f9; }

.s3x-ov-foot { margin: 16px 0 0; font-size: 12px; font-weight: 550; color: #3b5bfd; }

@media (prefers-reduced-motion: reduce) {
    .s3x-cta, .s3x-ov, .s3x-ov-card, .s3x-check { animation: none; transition: none; }
    .s3x-ov-spin span, .s3x-stp.is-active .s3x-stp-ic,
    .s3x-check-orb::before, .s3x-check-orb::after, .s3x-check-orb i,
    .s3x-tstep.is-active .s3x-tstep-ic, .s3x-tstep.is-done .s3x-tstep-ic {
        animation: none;
    }
}

    #confetti-container {
        z-index: 100;
        overflow: visible !important;
    }

    /* Fade-in animation for success section */
    #sitemap-success-section {
        opacity: 0;
        transform: translateY(10px);
        transition: opacity 0.7s ease-out, transform 0.7s ease-out;
    }

    #sitemap-success-section.show {
        opacity: 1;
        transform: translateY(0);
    }

    /* Confetti burst (subtle) */
    .confetti {
        position: absolute;
        top: 0;
        left: 50%;
        width: 6px;
        height: 6px;
        background: var(--cstm-primary, #22c55e);
        border-radius: 50%;
        animation: fall 2.5s linear infinite;
        z-index: 999;
    }

    @keyframes fall {
        0% {
            transform: translateY(0) rotate(0deg);
            opacity: 1;
        }

        100% {
            transform: translateY(400px) rotate(360deg);
            opacity: 0;
        }
    }
</style>

<div class="space-y-14 py-4">

    <!-- ===================== CHECKING STATE =====================
         This is what loads first. Google is the authority on whether a
         sitemap is already submitted, and answering that takes a round
         trip — so the page waits here rather than rendering the form and
         swapping it out a second later. That swap was not just a flicker:
         the submit button was live during it, so a sitemap already on the
         property could be submitted a second time. -->
    <div id="sitemapCheckState" class="s3x">
        <div class="s3x-check">

            <div class="s3x-check-head">
                <div class="s3x-check-orb"><i></i></div>
                <h3>Checking your setup</h3>
                <p>Looking up whether your sitemap is already submitted to Google Search Console.</p>
            </div>

            <div class="s3x-track">
                <div class="s3x-tstep" data-check="connect">
                    <span class="s3x-tstep-ic"></span>
                    <span class="s3x-tstep-t">Connecting to Google Search Console</span>
                </div>
                <div class="s3x-tstep" data-check="lookup">
                    <span class="s3x-tstep-ic"></span>
                    <span class="s3x-tstep-t">Looking for your sitemap</span>
                </div>
                <div class="s3x-tstep" data-check="ready">
                    <span class="s3x-tstep-ic"></span>
                    <span class="s3x-tstep-t">Preparing your setup</span>
                </div>
            </div>

            <p class="s3x-check-foot">This usually takes a moment</p>
        </div>
    </div>

    <div id="sitemap-submission-form" class="hidden">
        <div class="s3x">
            <div class="s3x-shell">

                <!-- ===================== LEFT: illustration ===================== -->
                <div class="s3x-art">
                    <div class="s3x-stage">
                        <!-- a page being read, and the sitemap being sent -->
                        <div class="s3x-window">
                            <div class="s3x-bar">
                                <i style="background:#ff5f57;"></i>
                                <i style="background:#febc2e;"></i>
                                <i style="background:#28c840;"></i>
                            </div>
                            <div class="s3x-lines">
                                <span style="width:82%;"></span>
                                <span style="width:64%;"></span>
                                <span style="width:74%;"></span>
                                <span style="width:48%;"></span>
                                <span style="width:68%;"></span>
                            </div>
                        </div>

                        <div class="s3x-code">
                            <iconify-icon icon="bx:bx-code-alt"></iconify-icon>
                        </div>

                        <div class="s3x-plane">
                            <iconify-icon icon="bx:bxs-send"></iconify-icon>
                        </div>

                        <span class="s3x-dot d1"></span>
                        <span class="s3x-dot d2"></span>
                        <span class="s3x-dot d3"></span>
                        <span class="s3x-plus p1">+</span>
                        <span class="s3x-plus p2">+</span>
                        <span class="s3x-plus p3">+</span>
                    </div>

                    <div class="s3x-art-copy">
                        <h4>Help Google Discover Your Pages</h4>
                        <p>Submitting a sitemap ensures that Google can find and index all of your important pages.</p>
                    </div>
                </div>

                <!-- ===================== RIGHT: form ===================== -->
                <div class="s3x-body">

                    <span class="s3x-step">Step 3 of 3</span>

                    <h2 class="s3x-h2">Submit Your Sitemap</h2>
                    <p class="s3x-lead">Enter your sitemap URL below to submit it to Google Search Console.</p>

                    <label for="<?= $siteUrl ? 'sitemapPath' : 'sitemapInput' ?>" class="s3x-label">Sitemap URL</label>

                    <?php if ($siteUrl): ?>
                        <!-- The domain is fixed. A sitemap has to live on the
                             verified property, so letting the whole URL be typed
                             only invites submissions Google will reject.
                             #sitemapInput stays as a hidden field holding the
                             composed URL, because that is what the wizard script
                             reads. -->
                        <div class="s3x-combo">
                            <span class="s3x-combo-ic"><iconify-icon icon="bx:bx-link"></iconify-icon></span>
                            <span class="s3x-combo-prefix" title="<?= htmlspecialchars($siteUrl) ?>"><?= htmlspecialchars(rtrim($siteUrl, '/')) ?>/</span>
                            <input type="text"
                                id="sitemapPath"
                                value="sitemap.xml"
                                placeholder="sitemap.xml"
                                spellcheck="false"
                                autocomplete="off"
                                class="s3x-combo-input">
                        </div>
                        <input type="hidden" id="sitemapInput" value="<?= htmlspecialchars($sitemapDefault) ?>">
                    <?php else: ?>
                        <!-- No verified property on file, so there is no prefix
                             to lock to — take the whole URL. -->
                        <div class="s3x-input-wrap">
                            <span class="s3x-input-ic"><iconify-icon icon="bx:bx-link"></iconify-icon></span>
                            <input type="text"
                                id="sitemapInput"
                                value=""
                                placeholder="https://example.com/sitemap.xml"
                                spellcheck="false"
                                class="s3x-input">
                        </div>
                    <?php endif; ?>

                    <div class="s3x-note">
                        <iconify-icon icon="bx:bx-info-circle"></iconify-icon>
                        <p>We will submit your sitemap to Google Search Console and start monitoring your website's performance.</p>
                    </div>

                    <div id="sitemapStatusBox"
                        class="hidden rounded-xl p-4 text-sm font-medium border mt-4"></div>

                    <!-- RECONNECT GOOGLE SECTION (hidden by default) -->
                    <div id="reconnectGoogleWrapper" class="hidden">
                        <button id="reconnectGoogleBtn" class="s3x-reconnect">
                            <img src="https://www.gstatic.com/images/branding/product/1x/googleg_48dp.png"
                                class="w-5 h-5" alt="Google">
                            <span>Reconnect / Try Another Google Account</span>
                        </button>

                        <p class="s3x-reconnect-hint">
                            If your connection expired or you skipped some permissions earlier, reconnect and ensure you allow all requested access.
                        </p>
                    </div>

                    <button id="submitSitemapBtn"
                        data-instance="<?= htmlspecialchars($instanceId) ?>"
                        class="s3x-cta">
                        <iconify-icon icon="bx:bxs-send"></iconify-icon>
                        Submit Sitemap to Google
                    </button>

                    <div class="s3x-chips">
                        <span class="s3x-chip">
                            <iconify-icon icon="bx:bx-shield-quarter"></iconify-icon>
                            Auto validation
                        </span>
                        <span class="s3x-chip">
                            <iconify-icon icon="bx:bx-copy-alt"></iconify-icon>
                            Checks duplicates
                        </span>
                        <span class="s3x-chip">
                            <iconify-icon icon="bx:bxs-zap"></iconify-icon>
                            Starts monitoring instantly
                        </span>
                    </div>

                    <div class="mt-10 hidden">
                        <h4 class="text-lg font-semibold mb-3 text-neutral-700 dark:text-neutral-200">
                            Submission History
                        </h4>

                        <div id="sitemapHistory" class="text-sm text-neutral-600 dark:text-neutral-300">
                            Loading...
                        </div>
                    </div>

            </div>
        </div>
    </div>
    </div>

    <!-- ===================== SUBMITTING OVERLAY =====================
         Shown while the submit request is in flight. Each step is driven by
         a real event, not a timer that pretends to make progress: the tick
         only appears once that stage has actually happened. -->
    <div id="sitemapSubmitOverlay" class="s3x-ov hidden">
        <div class="s3x-ov-card">

            <div class="s3x-ov-spin"><span></span></div>

            <h3>Submitting Sitemap</h3>
            <p>Please wait while we submit your sitemap to Google Search Console.</p>

            <div class="s3x-steps">
                <div class="s3x-stp" data-step="validate">
                    <span class="s3x-stp-ic"></span>
                    <span class="s3x-stp-t">Validating URL</span>
                </div>
                <div class="s3x-stp" data-step="connect">
                    <span class="s3x-stp-ic"></span>
                    <span class="s3x-stp-t">Connecting to Google</span>
                </div>
                <div class="s3x-stp" data-step="send">
                    <span class="s3x-stp-ic"></span>
                    <span class="s3x-stp-t">Sending Sitemap</span>
                </div>
                <div class="s3x-stp" data-step="process">
                    <span class="s3x-stp-ic"></span>
                    <span class="s3x-stp-t">Processing Response</span>
                </div>
            </div>

            <p class="s3x-ov-foot">This may take a few seconds...</p>
        </div>
    </div>

    <div id="sitemap-success-section" class="relative p-8 text-center hidden">
        <div id="confetti-container" class="absolute inset-0 pointer-events-none"></div>

        <div class="mx-auto rounded-2xl relative z-10 bg-white dark:bg-neutral-800 ">
            <div class="flex gap-4 text-start flex-col md:flex-row">
                <div class="max-w-[220px] block mx-auto">
                    <img src="./assets/images/setup-wizard-step-3-1.png" alt="" class="max-auto">
                </div>
                <div>
                    <h1 class="text-3xl font-extrabold text-green-600 mb-3 flex text-start gap-2">
                        Congratulations! 🎉
                    </h1>
                    <p class="text-lg text-start text-neutral-700 dark:text-neutral-300 mb-8">
                        Your sitemap has been <span class="text-success-main font-medium">successfully</span> submitted to Google Search Console.
                    </p>


                    <div class="mx-auto select-none">
                        <!-- Status Progress Bar Card -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 bg-white border-0 border-gray-200 rounded-xl">

                            <!-- Step 1: Domain Connected -->
                            <div class="flex items-center gap-4 lg:justify-center bg-success-50 border border-grau-200 rounded-lg p-4">
                                <!-- Icon with tiny check badge -->
                                <div class="relative flex-shrink-0 flex items-center justify-center w-10 h-10 bg-success-100 rounded-full">
                                    <iconify-icon icon="bx:bx-globe" class="text-success-main" width="18" height="18"></iconify-icon>
                                    <!-- Tiny check badge -->
                                    <span class="absolute bottom-0 right-0 flex items-center justify-center bg-white rounded-full p-0.5">
                                        <iconify-icon icon="bxs:check-circle" class="text-success-600" width="14" height="14"></iconify-icon>
                                    </span>
                                </div>
                                <!-- Label Details -->
                                <div>
                                    <h4 class="text-xs font-bold text-gray-800 leading-tight mb-0.5">Domain Connected</h4>
                                    <p class="text-start text-sm font-semibold text-success-600">Completed</p>
                                </div>
                            </div>

                            <!-- Step 2: Property Verified -->
                            <div class="flex items-center gap-4 lg:justify-center bg-success-50 border border-grau-200 rounded-lg p-4">
                                <!-- Icon with tiny check badge -->
                                <div class="relative flex-shrink-0 flex items-center justify-center w-10 h-10 bg-success-100 rounded-full">
                                    <iconify-icon icon="bx:bx-search" class="text-success-main" width="18" height="18"></iconify-icon>
                                    <!-- Tiny check badge -->
                                    <span class="absolute bottom-0 right-0 flex items-center justify-center bg-white rounded-full p-0.5">
                                        <iconify-icon icon="bxs:check-circle" class="text-success-600" width="14" height="14"></iconify-icon>
                                    </span>
                                </div>
                                <!-- Label Details -->
                                <div>
                                    <h4 class="text-xs font-bold text-gray-800 leading-tight mb-0.5">Property Verified</h4>
                                    <p class="text-start text-sm font-semibold text-success-600">Completed</p>
                                </div>
                            </div>

                            <!-- Step 3: Sitemap Submitted -->
                            <div class="flex items-center gap-4 lg:justify-center bg-success-50 border border-grau-200 rounded-lg p-4">
                                <!-- Icon with tiny check badge -->
                                <div class="relative flex-shrink-0 flex items-center justify-center w-10 h-10 bg-success-100 rounded-full">
                                    <iconify-icon icon="bx:bx-shield-quarter" class="text-success-main" width="18" height="18"></iconify-icon>
                                    <!-- Tiny check badge -->
                                    <span class="absolute bottom-0 right-0 flex items-center justify-center bg-white rounded-full p-0.5">
                                        <iconify-icon icon="bxs:check-circle" class="text-success-600" width="14" height="14"></iconify-icon>
                                    </span>
                                </div>
                                <!-- Label Details -->
                                <div>
                                    <h4 class="text-xs font-bold text-gray-800 leading-tight mb-0.5">Sitemap Submitted</h4>
                                    <p class="text-start text-sm font-semibold text-success-600">Completed</p>
                                </div>
                            </div>

                            <!-- Step 4: Monitoring Active -->
                            <div class="flex items-center gap-4 lg:justify-center bg-success-50 border border-grau-200 rounded-lg p-4">
                                <!-- Icon -->
                                <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 bg-success-100 rounded-full">
                                    <iconify-icon icon="bx:bx-bar-chart-alt-2" class="text-success-main" width="18" height="18"></iconify-icon>
                                </div>
                                <!-- Label Details -->
                                <div>
                                    <h4 class="text-xs font-bold text-gray-800 leading-tight mb-0.5">Monitoring Active</h4>
                                    <p class="text-start text-sm font-semibold text-success-600">Ready</p>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
            <div class="mx-auto py-6 select-none">
                <div class="grid grid-cols-12 gap-4 items-start">
                    
                    <!-- ==================== LEFT CARD: CONNECTION OVERVIEW ==================== -->
                    <div class="col-span-12 lg:col-span-7 sm:p-6 bg-white border border-gray-100 rounded-2xl shadow-sm h-full text-start">

                        <!-- Card Header -->
                        <div class="flex items-center gap-3 mb-5">
                            <div class="flex items-center justify-center w-11 h-11 bg-blue-50 rounded-xl flex-shrink-0">
                                <iconify-icon icon="bx:bx-line-chart" class="text-blue-600" width="21" height="21"></iconify-icon>
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900 leading-tight">Connection Overview</h2>
                                <p class="text-xs text-gray-500 font-medium mt-0.5">Here are the details of your connection</p>
                            </div>
                        </div>

                        <!-- Detail rows -->
                        <div class="border border-gray-100 rounded-xl overflow-hidden divide-y divide-gray-100">

                            <!-- Property / Domain -->
                            <div class="flex items-center gap-3 px-4 py-3.5">
                                <iconify-icon icon="bx:bx-globe" class="text-gray-400 flex-shrink-0" width="18" height="18"></iconify-icon>
                                <span class="text-sm font-medium text-gray-600 flex-shrink-0">Property / Domain</span>
                                <span class="flex-1"></span>
                                <?php if (!empty($siteUrl)): ?>
                                    <a href="<?= htmlspecialchars($siteUrl) ?>" target="_blank" rel="noopener"
                                       class="text-sm font-medium text-gray-800 truncate max-w-[240px] min-w-[120px] hover:text-blue-600 transition"
                                       title="<?= htmlspecialchars($siteUrl) ?>">
                                        <?= htmlspecialchars(rtrim($siteUrl, '/')) ?>
                                    </a>
                                    <iconify-icon icon="bx:bx-link-external" class="text-blue-500 flex-shrink-0" width="15" height="15"></iconify-icon>
                                <?php else: ?>
                                    <span class="text-sm text-gray-400">&mdash;</span>
                                <?php endif; ?>
                            </div>

                            <!-- Sitemap URL -->
                            <div class="flex items-center gap-3 px-4 py-3.5">
                                <iconify-icon icon="bx:bx-file" class="text-gray-400 flex-shrink-0" width="18" height="18"></iconify-icon>
                                <span class="text-sm font-medium text-gray-600 flex-shrink-0">Sitemap URL</span>
                                <span class="flex-1"></span>
                                <?php if (!empty($ovSitemapUrl)): ?>
                                    <a href="<?= htmlspecialchars($ovSitemapUrl) ?>" target="_blank" rel="noopener"
                                       class="text-sm font-medium text-gray-800 truncate max-w-[240px] min-w-[120px] hover:text-blue-600 transition"
                                       title="<?= htmlspecialchars($ovSitemapUrl) ?>">
                                        <?= htmlspecialchars($ovSitemapUrl) ?>
                                    </a>
                                    <iconify-icon icon="bx:bx-link-external" class="text-blue-500 flex-shrink-0" width="15" height="15"></iconify-icon>
                                <?php else: ?>
                                    <span class="text-sm text-gray-400">&mdash;</span>
                                <?php endif; ?>
                            </div>

                            <!-- Submitted On -->
                            <div class="flex items-center gap-3 px-4 py-3.5">
                                <iconify-icon icon="bx:bx-calendar" class="text-gray-400 flex-shrink-0" width="18" height="18"></iconify-icon>
                                <span class="text-sm font-medium text-gray-600 flex-shrink-0">Submitted On</span>
                                <span class="flex-1"></span>
                                <span class="text-sm font-medium text-gray-800">
                                    <?= $ovSubmittedOn
                                        ? htmlspecialchars(date('M j, Y, g:i A', strtotime($ovSubmittedOn)))
                                        : htmlspecialchars(date('M j, Y, g:i A')) ?>
                                </span>
                            </div>

                            <!-- Last Sync -->
                            <div class="flex items-center gap-3 px-4 py-3.5">
                                <iconify-icon icon="bx:bx-refresh" class="text-gray-400 flex-shrink-0" width="18" height="18"></iconify-icon>
                                <span class="text-sm font-medium text-gray-600 flex-shrink-0">Last Sync</span>
                                <span class="flex-1"></span>
                                <span class="text-sm font-medium text-gray-800">
                                    <?= $ovLastSync
                                        ? htmlspecialchars(date('M j, Y, g:i A', strtotime($ovLastSync)))
                                        : 'Just now' ?>
                                </span>
                                <span class="w-2 h-2 rounded-full bg-success-600 flex-shrink-0"></span>
                            </div>

                            <!-- Pages Found -->
                            <div class="flex items-center gap-3 px-4 py-3.5">
                                <iconify-icon icon="bx:bx-file-blank" class="text-gray-400 flex-shrink-0" width="18" height="18"></iconify-icon>
                                <span class="text-sm font-medium text-gray-600 flex-shrink-0">Pages Found</span>
                                <span class="flex-1"></span>
                                <?php if ($ovPages !== null): ?>
                                    <span class="text-sm font-semibold text-gray-800"><?= number_format((int)$ovPages) ?></span>
                                <?php else: ?>
                                    <!-- Google reports this only after it has read the sitemap, which
                                         does not happen at submit time. A dash plus "Processing" is
                                         the honest reading; a zero would say Google looked and found
                                         nothing. -->
                                    <span class="text-sm font-medium text-gray-400">&ndash; &ndash;</span>
                                    <span class="inline-flex items-center px-2.5 py-0.5 bg-purple-50 border border-purple-100 rounded-full text-xs font-semibold text-purple-600 flex-shrink-0">
                                        Processing
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Status -->
                            <div class="flex items-center gap-3 px-4 py-3.5">
                                <iconify-icon icon="bx:bx-pulse" class="text-gray-400 flex-shrink-0" width="18" height="18"></iconify-icon>
                                <span class="text-sm font-medium text-gray-600 flex-shrink-0">Status</span>
                                <span class="flex-1"></span>
                                <?php if ($ovErrors > 0): ?>
                                    <span class="inline-flex items-center px-3 py-1 bg-danger-50 border border-danger-100 rounded-full text-xs font-semibold text-danger-600">
                                        Needs Attention
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-3 py-1 bg-success-50 border border-success-100 rounded-full text-xs font-semibold text-success-600">
                                        Monitoring Active
                                    </span>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>

                    <!-- ==================== RIGHT CARD: WHAT'S HAPPENING NEXT ==================== -->
                    <div class="col-span-12 lg:col-span-5 p-6 bg-white border border-gray-100 rounded-2xl shadow-sm h-full text-start">

                        <!-- Card Header -->
                        <div class="flex items-center gap-3 mb-5">
                            <div class="flex items-center justify-center w-11 h-11 bg-blue-50 rounded-xl flex-shrink-0">
                                <iconify-icon icon="bx:bx-rocket" class="text-blue-600" width="21" height="21"></iconify-icon>
                            </div>
                            <div>
                                <h2 class="text-base font-semibold text-gray-900 leading-tight">What's Happening Next?</h2>
                                <p class="text-xs text-gray-500 font-medium mt-0.5">Here's what Google will do now</p>
                            </div>
                        </div>

                        <!-- Steps -->
                        <div class="divide-y divide-gray-100">

                            <div class="flex items-start gap-3 py-3.5">
                                <iconify-icon icon="bx:bx-search" class="text-blue-600 flex-shrink-0 mt-0.5" width="20" height="20"></iconify-icon>
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900 leading-tight">Google will fetch your sitemap</h4>
                                    <p class="text-xs text-gray-500 font-medium mt-1">We've submitted your sitemap to Google.</p>
                                </div>
                            </div>

                            <div class="flex items-start gap-3 py-3.5">
                                <iconify-icon icon="bx:bx-list-ul" class="text-blue-600 flex-shrink-0 mt-0.5" width="20" height="20"></iconify-icon>
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900 leading-tight">URLs will be discovered</h4>
                                    <p class="text-xs text-gray-500 font-medium mt-1">Google will crawl and discover all URLs.</p>
                                </div>
                            </div>

                            <div class="flex items-start gap-3 py-3.5">
                                <iconify-icon icon="bx:bx-time-five" class="text-blue-600 flex-shrink-0 mt-0.5" width="20" height="20"></iconify-icon>
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900 leading-tight">Indexing may take time</h4>
                                    <p class="text-xs text-gray-500 font-medium mt-1">It can take a few hours or days to reflect.</p>
                                </div>
                            </div>

                            <div class="flex items-start gap-3 py-3.5">
                                <iconify-icon icon="bx:bx-trending-up" class="text-blue-600 flex-shrink-0 mt-0.5" width="20" height="20"></iconify-icon>
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900 leading-tight">Reports will appear automatically</h4>
                                    <p class="text-xs text-gray-500 font-medium mt-1">You'll start seeing data in your dashboard.</p>
                                </div>
                            </div>

                        </div>

                        <!-- Bottom notice -->
                        <div class="flex items-start gap-3 p-4 mt-4 bg-blue-50/60 border border-blue-100 rounded-xl">
                            <iconify-icon icon="bx:bxs-info-circle" class="text-blue-600 flex-shrink-0 mt-0.5" width="20" height="20"></iconify-icon>
                            <div>
                                <p class="text-sm font-semibold text-gray-800 leading-snug">It may take some time for Google to process your sitemap.</p>
                                <p class="text-xs text-gray-500 font-medium mt-1">You can sync now or wait for the next automatic update.</p>
                            </div>
                        </div>

                    </div>

                </div>
                </div>


                <div class="flex flex-col sm:flex-row justify-center items-center gap-4 mt-4">
                    <a href="dashboard.php" id="finishBtn"
                        class="btn btn-cstm-primary flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg>
                        Go to Dashboard
                    </a>
                    <a href="sitemap.php" target="_blank"
                        class="btn btn-cstm-muted flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                        Sitemap Management
                    </a>
                </div>
        </div>
    </div>
</div>

<!-- Reconnect Google Modal -->
<div id="reconnect-modal-overlay"
    class="fixed inset-0 bg-cstm-black-40 backdrop-blur-sm z-50 hidden opacity-0 transition-opacity duration-300"></div>

<div id="reconnect-modal"
    class="fixed top-1/2 left-1/2 z-50 bg-white dark:bg-neutral-800 rounded-2xl shadow-xl hidden opacity-0 scale-95 transition-all duration-300 -translate-x-1/2 -translate-y-1/2 overflow-hidden border border-neutral-200 dark:border-neutral-600">

    <div class="p-5 text-center">
        <div class="mx-auto w-16 h-16 bg-success-100 dark:bg-success-900/30 text-success-600 rounded-full flex items-center justify-center mb-6">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>

        <h3 class="text-xl font-bold text-neutral-900 dark:text-white mb-3">
            Reconnect Your Account &amp; Grant Permissions
        </h3>
        <p class="text-neutral-500 dark:text-neutral-400 text-sm leading-relaxed mb-6">
            It looks like your account connection has expired, or some required permissions were not granted.<br><br>
            Please reconnect your account and ensure <strong>all requested permissions are allowed</strong>.
        </p>
        <p class="text-neutral-500 dark:text-neutral-400 text-sm leading-relaxed mb-6">
            To submit sitemaps, your Google account must have <strong>full access</strong> to this property in Google Search Console.<br><br>
        </p>

        <ul class="text-left text-sm text-neutral-600 dark:text-neutral-300 mb-6 space-y-2 max-w-md mx-auto">
            <li>Select the correct Google account that owns this website in Search Console.</li>
            <li>Click <strong>Allow</strong> for all requested permissions (do not uncheck anything).</li>
            <li>After the connection is successful, return here and submit your sitemap again.</li>
        </ul>

        <div class="flex items-center justify-center gap-3">
            <button id="cancelReconnect"
                class="btn btn-cstm-muted">
                Cancel
            </button>
            <button id="confirmReconnect"
                class="btn btn-cstm-primary flex items-center gap-2">
                <img src="https://www.gstatic.com/images/branding/product/1x/googleg_48dp.png"
                    class="w-5 h-5" alt="">
                <span>Continue to Google</span>
            </button>
        </div>
    </div>
</div>

 
<script>
// Keep the hidden #sitemapInput in step with what the user types.
//
// The domain half of the field is fixed, so only the file name is editable.
// The wizard script reads #sitemapInput.value for both the status check and
// the submit, so the composed URL is written back there rather than changing
// how that script works.
(function () {
    const pathInput = document.getElementById("sitemapPath");
    const urlInput  = document.getElementById("sitemapInput");
    const prefixEl  = document.querySelector(".s3x-combo-prefix");

    if (!pathInput || !urlInput || !prefixEl) return;   // full-URL fallback mode

    const prefix = prefixEl.textContent.trim().replace(/\/+$/, "");

    function sync() {
        let v = pathInput.value.trim();

        // Pasting a whole URL is the natural thing to do here, so reduce it
        // to the part that belongs in this field.
        //
        // The locked prefix can include a path — Wix sites often sit on one,
        // like /kush — so stripping only the origin would leave that segment
        // in place and compose ".../kush/kush/sitemap.xml". Anything that
        // already starts with the prefix therefore loses the whole prefix;
        // anything else loses just its origin.
        if (/^https?:\/\//i.test(v)) {
            if (v.toLowerCase().startsWith(prefix.toLowerCase())) {
                v = v.slice(prefix.length);
            } else {
                try {
                    v = new URL(v).pathname;
                } catch (e) {
                    v = v.replace(/^https?:\/\/[^\/]*/i, "");
                }
            }
            v = v.replace(/^\/+/, "");
            pathInput.value = v;
        }

        v = v.replace(/^\/+/, "");
        urlInput.value = v ? prefix + "/" + v : "";
    }

    pathInput.addEventListener("input", sync);
    pathInput.addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
            e.preventDefault();
            document.getElementById("submitSitemapBtn")?.click();
        }
    });

    sync();   // in case the rendered default and the field ever disagree
})();
</script>

<script>
document.querySelectorAll(".copyVerificationToken").forEach(button => {
    button.addEventListener("click", async function () {

        const container = this.closest("div");
        const target = container.querySelector(".verificationToken");

        const text =
            target.tagName === "TEXTAREA"
                ? target.value
                : target.textContent.trim();

        try {
            await navigator.clipboard.writeText(text);

            const old = this.innerHTML;

            this.innerHTML = `
                <iconify-icon icon="bxs:check-circle" width="16" height="16"></iconify-icon>
                Copied
            `;

            setTimeout(() => {
                this.innerHTML = old;
            }, 1800);

        } catch (err) {
            alert("Failed to copy.");
        }
    });
});


const technicalDetailsToggle = document.getElementById("technicalDetailsToggle");
const technicalDetailsContent = document.getElementById("technicalDetailsContent");
const technicalDetailsIcon = document.getElementById("technicalDetailsIcon");

technicalDetailsToggle?.addEventListener("click", () => {
    const isHidden = technicalDetailsContent.classList.contains("hidden");

    technicalDetailsContent.classList.toggle("hidden");

    technicalDetailsIcon.style.transform = isHidden
        ? "rotate(180deg)"
        : "rotate(0deg)";
});
</script>