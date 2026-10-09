<?php
declare(strict_types=1);

/**
 * why-first-report.php  (WordPress)
 *
 * Where the banner's "View details" link goes.
 *
 * The banner has room for one sentence. This page is for the merchant who
 * read it and wants to know whether something is broken - which is the
 * real question behind "why is my data not here yet".
 *
 * It reads their own timestamps rather than describing the process in
 * general, because "verified on 12 Aug, expected by 15 Aug" answers the
 * question and "usually takes a few days" does not.
 */

$title    = 'Your first report';
$subTitle = 'Why it takes a few days';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/config.php';

if (!function_exists('h')) {
    function h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// Both spellings - this codebase writes the key under either name.
$instanceId = $_SESSION['instanceid']
    ?? $_SESSION['instance_id']
    ?? $_GET['instance_id']
    ?? $_GET['instanceId']
    ?? null;

$instanceId = is_string($instanceId) ? trim($instanceId) : null;

if (!$instanceId) {
    header('Location: sign-in.php');
    exit;
}

if (!defined('GSC_FIRST_REPORT_HOURS')) {
    define('GSC_FIRST_REPORT_HOURS', 72);
}

/* ==========================================================
   THEIR OWN DATES

   Wrapped: this page exists to reassure someone who is already
   wondering whether something is wrong. A query failing here
   must not hand them an error screen - it just means the page
   says the general thing instead of the specific one.
========================================================== */
$verifiedOn  = null;
$siteUrl     = null;
$expectedBy  = null;
$isOverdue   = false;
$hasData     = false;

try {
    $stmt = $pdo->prepare("
        SELECT site_url, verification_status, verification_verified_at, created_at
        FROM gsc_domain_verifications
        WHERE instance_id = ?
        LIMIT 1
    ");
    $stmt->execute([$instanceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $siteUrl = $row['site_url'] ?? null;

    $raw = $row['verification_verified_at'] ?: ($row['created_at'] ?? null);

    if ($raw) {
        $verifiedOn = new DateTimeImmutable((string)$raw);
        $expectedBy = $verifiedOn->modify('+' . GSC_FIRST_REPORT_HOURS . ' hours');
        $isOverdue  = ($expectedBy < new DateTimeImmutable('now'));
    }
} catch (Throwable $e) {
    error_log('why-first-report verification: ' . $e->getMessage());
}

// Has anything actually arrived? If it has, this page should say so rather
// than keep explaining a wait that is over.
try {
    $q = $pdo->prepare("
        SELECT COUNT(*) FROM gsc_query_daily WHERE instance_id = ?
    ");
    $q->execute([$instanceId]);
    $hasData = ((int)$q->fetchColumn() > 0);
} catch (Throwable $e) {
    // Table may not exist on an app without the Action Center yet.
    $hasData = false;
}

include './partials/layouts/layoutTop.php';
?>

<style>
/* Scoped to .wf- so a purged Tailwind build cannot drop any of it. */
.wf { max-width: 780px; margin: 0 auto; padding: 4px 0 56px; color: #475569; }
.wf * { box-sizing: border-box; }

.wf-head {
    display: flex; gap: 14px; align-items: flex-start;
    padding: 20px 22px; border-radius: 16px; margin-bottom: 18px;
    border: 1px solid #e2e8f0; background: #fff;
}
.wf-head-ic {
    flex: none; width: 42px; height: 42px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center; font-size: 21px;
}
.wf-head-ic.is-wait { background: #fef3c7; color: #b45309; }
.wf-head-ic.is-done { background: #f0fdf4; color: #15803d; }
.wf-h1 { margin: 0; font-size: 18px; font-weight: 700; color: #0f172a; line-height: 1.3; }
.wf-lede { margin: 5px 0 0; font-size: 13.5px; line-height: 1.65; color: #64748b; }

.wf-card {
    border: 1px solid #e2e8f0; border-radius: 16px; background: #fff;
    padding: 20px 22px; margin-bottom: 16px;
}
.wf-card h2 {
    margin: 0 0 14px; font-size: 11px; font-weight: 700;
    letter-spacing: .07em; text-transform: uppercase; color: #94a3b8;
}

.wf-facts { display: grid; grid-template-columns: 1fr; gap: 1px;
            background: #e2e8f0; border: 1px solid #e2e8f0;
            border-radius: 12px; overflow: hidden; }
@media (min-width: 620px) { .wf-facts { grid-template-columns: 1fr 1fr; } }
.wf-fact { background: #fff; padding: 13px 15px; }
.wf-fact-k { font-size: 10.5px; font-weight: 700; letter-spacing: .05em;
             text-transform: uppercase; color: #94a3b8; margin-bottom: 3px; }
.wf-fact-v { font-size: 14.5px; font-weight: 650; color: #0f172a; }
.wf-fact-v small { display: block; font-size: 12px; font-weight: 400; color: #64748b; margin-top: 2px; }

/* The timeline. Four stages, because the wait is not one delay but a
   sequence, and knowing which stage they are in is the difference between
   "it is working" and "something is stuck". */
.wf-steps { counter-reset: wf; margin: 0; padding: 0; list-style: none; }
.wf-step { position: relative; padding: 0 0 20px 40px; counter-increment: wf; }
.wf-step:last-child { padding-bottom: 0; }
.wf-step::before {
    content: counter(wf);
    position: absolute; left: 0; top: 0;
    width: 25px; height: 25px; border-radius: 50%;
    background: #f1f5f9; color: #64748b;
    font-size: 11.5px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}
.wf-step.is-done::before {
    content: '\\2713'; background: #dcfce7; color: #15803d; font-size: 13px;
}
.wf-step.is-now::before { background: var(--cstm-primary, #3b5bfd); color: #fff; }
.wf-step:not(:last-child)::after {
    content: ''; position: absolute; left: 12px; top: 29px; bottom: 4px;
    width: 1px; background: #e2e8f0;
}
.wf-step-t { font-size: 14px; font-weight: 650; color: #0f172a; margin-bottom: 3px; }
.wf-step-d { font-size: 13px; line-height: 1.65; color: #64748b; }

.wf-list { margin: 0; padding-left: 18px; }
.wf-list li { font-size: 13.5px; line-height: 1.8; color: #475569; }
.wf-list strong { color: #0f172a; }

.wf-note {
    display: flex; gap: 10px; align-items: flex-start;
    padding: 12px 14px; border-radius: 11px; margin-top: 14px;
    background: #eff6ff; border: 1px solid #dbeafe;
}
.wf-note p { margin: 0; font-size: 13px; line-height: 1.65; color: #334155; }
.wf-note iconify-icon { flex: none; margin-top: 2px; color: #2563eb; }

.wf-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
.wf-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 10px 18px; border-radius: 10px; border: 0;
    font-size: 13.5px; font-weight: 600; text-decoration: none; cursor: pointer;
}
.wf-btn-primary { background: var(--cstm-primary, #3b5bfd); color: #fff; }
.wf-btn-primary:hover { color: #fff; opacity: .93; }
.wf-btn-ghost { background: #fff; border: 1px solid #d1d5db; color: #334155; }
.wf-btn-ghost:hover { background: #f8fafc; color: #334155; }
</style>

<div class="wf">

    <?php if ($hasData): ?>

        <div class="wf-head">
            <span class="wf-head-ic is-done"><iconify-icon icon="solar:check-circle-bold"></iconify-icon></span>
            <div>
                <h1 class="wf-h1">Your data has arrived</h1>
                <p class="wf-lede">
                    Google is sending reports for your site, and the dashboard is
                    showing your own figures. Nothing further to wait for.
                </p>
            </div>
        </div>

    <?php else: ?>

        <div class="wf-head">
            <span class="wf-head-ic is-wait"><iconify-icon icon="solar:clock-circle-bold"></iconify-icon></span>
            <div>
                <h1 class="wf-h1">
                    <?= $isOverdue
                        ? 'Your first report is taking longer than usual'
                        : 'Your first report is on its way' ?>
                </h1>
                <p class="wf-lede">
                    Nothing is broken and there is nothing to set up. Google collects
                    a site&rsquo;s search data on its own schedule, and the first
                    report is the one that takes longest.
                </p>
            </div>
        </div>

    <?php endif; ?>


    <?php if ($verifiedOn): ?>
        <div class="wf-card">
            <h2>Your timings</h2>
            <div class="wf-facts">
                <div class="wf-fact">
                    <div class="wf-fact-k">Domain verified</div>
                    <div class="wf-fact-v">
                        <?= h($verifiedOn->format('j M Y')) ?>
                        <small><?= h($verifiedOn->format('g:i a')) ?></small>
                    </div>
                </div>

                <?php if (!$hasData && $expectedBy): ?>
                    <div class="wf-fact">
                        <div class="wf-fact-k">First report expected</div>
                        <div class="wf-fact-v">
                            <?= h($expectedBy->format('j M Y')) ?>
                            <small><?= $isOverdue ? 'This has passed &mdash; see below' : 'Around ' . h($expectedBy->format('g:i a')) ?></small>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($siteUrl): ?>
                    <div class="wf-fact">
                        <div class="wf-fact-k">Property</div>
                        <div class="wf-fact-v" style="font-size:13px;word-break:break-all;">
                            <?= h($siteUrl) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>


    <div class="wf-card">
        <h2>What happens between now and then</h2>

        <ol class="wf-steps">
            <li class="wf-step is-done">
                <div class="wf-step-t">Your domain is verified</div>
                <div class="wf-step-d">
                    Google now knows the site is yours and has started associating
                    search activity with it. This part is done.
                </div>
            </li>

            <li class="wf-step <?= $hasData ? 'is-done' : 'is-now' ?>">
                <div class="wf-step-t">Google collects a day of activity</div>
                <div class="wf-step-d">
                    Search Console works in whole days, in Pacific Time. A day of
                    data is only complete once that day has ended &mdash; so
                    verifying in the afternoon means the first full day starts the
                    following morning.
                </div>
            </li>

            <li class="wf-step <?= $hasData ? 'is-done' : '' ?>">
                <div class="wf-step-t">Google processes it</div>
                <div class="wf-step-d">
                    Raw activity is checked and aggregated before it is published.
                    This is where most of the wait sits, and it is the same for
                    every site &mdash; nothing about your setup makes it faster or
                    slower.
                </div>
            </li>

            <li class="wf-step <?= $hasData ? 'is-done' : '' ?>">
                <div class="wf-step-t">We fetch it and fill your dashboard</div>
                <div class="wf-step-d">
                    We check for new data several times a day. The moment Google has
                    something, the example figures are replaced with yours &mdash;
                    automatically, with nothing to click.
                </div>
            </li>
        </ol>
    </div>


    <?php if (!$hasData): ?>
        <div class="wf-card">
            <h2><?= $isOverdue ? 'Why it can take longer' : 'Why some sites take longer' ?></h2>

            <ul class="wf-list">
                <li>
                    <strong>Very little search traffic.</strong> Google needs a
                    minimum amount of activity before it will report on a query or a
                    page. A new or quiet site can pass the <?= (int)GSC_FIRST_REPORT_HOURS ?>-hour
                    mark with nothing to show yet &mdash; not because collection
                    failed, but because there was little to collect.
                </li>
                <li>
                    <strong>The site is new to Google.</strong> Pages have to be
                    crawled and indexed before they can appear in search at all, and
                    that is a separate queue from this one.
                </li>
                <li>
                    <strong>Recently launched or recently moved.</strong> A domain
                    that changed hosting, URL structure or HTTPS setup starts its
                    history again from the change.
                </li>
            </ul>

            <div class="wf-note">
                <iconify-icon icon="solar:info-circle-bold" style="font-size:15px;"></iconify-icon>
                <p>
                    Submitting your sitemap is the one thing that helps, and it helps
                    with indexing rather than with this wait. If you have not done it
                    yet, it is worth doing now.
                </p>
            </div>
        </div>
    <?php endif; ?>


    <div class="wf-card">
        <h2>What you can do meanwhile</h2>
        <ul class="wf-list">
            <li><strong>Nothing is required.</strong> The dashboard fills itself in.</li>
            <li><strong>The example figures are labelled.</strong> Anything marked
                &ldquo;Sample data&rdquo; is illustration, not your site &mdash; it
                is there so the reports are not empty boxes while you wait.</li>
            <li><strong>Your setup is already working.</strong> Verification,
                sitemap submission and indexing all run regardless of whether the
                performance report has landed.</li>
        </ul>

        <div class="wf-actions">
            <a href="dashboard.php?instance_id=<?= urlencode($instanceId) ?>" class="wf-btn wf-btn-primary">
                <iconify-icon icon="solar:home-2-outline" style="font-size:16px;"></iconify-icon>
                Back to dashboard
            </a>
            <a href="sitemap.php?instance_id=<?= urlencode($instanceId) ?>" class="wf-btn wf-btn-ghost">
                Sitemap manager
            </a>
        </div>
    </div>

</div>

<?php include './partials/layouts/layoutBottom.php'; ?>
