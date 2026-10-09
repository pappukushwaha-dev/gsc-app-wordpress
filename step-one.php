<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php'; // ✅ explicit DB include
require_once __DIR__ . '/includes/google/get_account.php';



if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* ==========================================================
   INSTANCE (SHOPIFY)
========================================================== */
$instanceId =
    $_SESSION['instance_id']
    ?? $_GET['instanceId']
    ?? null;
/* ==========================================================
   GOOGLE ACCOUNT (SHOPIFY)
========================================================== */
$googleAccount = null;
$isConnected   = false;

if ($instanceId) {
    $googleAccount = getGoogleAccountByShop($instanceId);

    $isConnected = (
        $googleAccount &&
        (
            !empty($googleAccount['access_token']) ||
            !empty($googleAccount['refresh_token'])
        )
    );
}


/* ==========================================================
   LOAD SITE URL (SHOPIFY — SINGLE SOURCE OF TRUTH)
========================================================== */
$siteUrl = null;

if ($instanceId) {
    $stmt = $pdo->prepare("
        SELECT domain
        FROM WpSite
        WHERE instance_id = ?
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$instanceId]);
    $shopDomain = $stmt->fetchColumn();

    if ($shopDomain) {
        $siteUrl = 'https://' . rtrim($shopDomain, '/') . '/';

        $siteDomain = $shopDomain; // clean domain without protocol

    }
    $siteDomain = $siteDomain ?? null;
}



?>

<style>
    /* =======================================================================
   Setup wizard, step one
   Everything is scoped to .s1x so it cannot reach the rest of the app.
   ======================================================================= */
    .s1x {
        --s1-ink: #0f172a;
        --s1-ink-2: #475569;
        --s1-ink-3: #94a3b8;
        --s1-line: #e6eaf2;
        --s1-blue: #3b5bfd;
        --s1-blue-2: #6366f1;
        --s1-green: #16a34a;
        --s1-surface: #ffffff;
        --s1-mono: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
    }

    .dark .s1x {
        --s1-ink: #f1f5f9;
        --s1-ink-2: #cbd5e1;
        --s1-ink-3: #94a3b8;
        --s1-line: #3f3f46;
        --s1-surface: #262626;
    }

    .s1x-shell {
        display: grid;
        grid-template-columns: minmax(0, 0.82fr) minmax(0, 1.18fr);
        max-width: 940px;
        margin: 0 auto;
        border: 1px solid var(--s1-line);
        border-radius: 20px;
        overflow: hidden;
        background: var(--s1-surface);
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 8px 28px rgba(15, 23, 42, .06);
    }

    /* ---------------- left: illustration ---------------- */
    .s1x-art {
        position: relative;
        overflow: hidden;
        background: linear-gradient(160deg, #f4f7ff 0%, #eef2ff 55%, #f7f5ff 100%);
        border-right: 1px solid var(--s1-line);
        padding: 20px 20px 18px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 0;
    }

    .dark .s1x-art {
        background: linear-gradient(160deg, #1e2233 0%, #242a44 100%);
    }

    .s1x-stage {
        position: relative;
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* concentric rings behind the mark */
    .s1x-ring {
        position: absolute;
        border-radius: 50%;
        border: 1px solid rgba(99, 102, 241, .16);
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
    }

    .s1x-ring.r1 {
        width: 150px;
        height: 150px;
        background: rgba(99, 102, 241, .05);
    }

    .s1x-ring.r2 {
        width: 114px;
        height: 114px;
        background: rgba(99, 102, 241, .07);
    }

    .s1x-ring.r3 {
        width: 82px;
        height: 82px;
        background: rgba(99, 102, 241, .09);
    }

    .s1x-mark {
        position: relative;
        width: 66px;
        height: 66px;
        border-radius: 50%;
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 16px rgba(15, 23, 42, .1), 0 12px 36px rgba(59, 91, 253, .14);
    }

    .dark .s1x-mark {
        background: #2f3550;
    }

    .s1x-mark img {
        width: 33px;
        height: 33px;
    }

    /* small floating capability cards */
    .s1x-chip {
        position: absolute;
        width: 34px;
        height: 34px;
        border-radius: 10px;
        background: #fff;
        border: 1px solid rgba(15, 23, 42, .06);
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 8px rgba(15, 23, 42, .07);
    }

    .dark .s1x-chip {
        background: #2f3550;
        border-color: #3f4666;
    }

    .s1x-chip iconify-icon {
        font-size: 16px;
    }

    .s1x-chip.c1 {
        top: 12%;
        left: 6%;
    }

    .s1x-chip.c2 {
        top: 16%;
        right: 8%;
    }

    .s1x-chip.c3 {
        bottom: 16%;
        left: 2%;
    }

    .s1x-chip.c4 {
        bottom: 20%;
        right: 6%;
    }

    /* sparkles */
    .s1x-spark {
        position: absolute;
        color: #c7d2fe;
        font-size: 12px;
    }

    .s1x-spark.s1 {
        top: 26%;
        left: 26%;
    }

    .s1x-spark.s2 {
        top: 46%;
        right: 22%;
        font-size: 9px;
    }

    .s1x-spark.s3 {
        bottom: 30%;
        left: 34%;
        font-size: 10px;
    }

    .s1x-trust {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        background: rgba(255, 255, 255, .85);
        border: 1px solid rgba(15, 23, 42, .06);
        border-radius: 12px;
        padding: 10px 12px;
        backdrop-filter: blur(6px);
    }

    .dark .s1x-trust {
        background: rgba(47, 53, 80, .7);
        border-color: #3f4666;
    }

    .s1x-trust-ic {
        width: 26px;
        height: 26px;
        border-radius: 8px;
        flex: none;
        background: #e0e7ff;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .s1x-trust-ic iconify-icon {
        font-size: 16px;
    }

    .s1x-trust h5 {
        margin: 0 0 2px;
        font-size: 11.5px;
        font-weight: 700;
    }

    .s1x-trust p {
        margin: 0;
        font-size: 10.5px;
        line-height: 1.45;
        color: var(--s1-ink-2);
    }


    /* =======================================================================
   Motion
   Everything here is decorative. The whole block is switched off under
   prefers-reduced-motion at the bottom, so nothing depends on it.
   ======================================================================= */

    /* rings drift and breathe at different speeds so they never line up */
    .s1x-ring.r1 {
        animation: s1Breathe 7s ease-in-out infinite;
    }

    .s1x-ring.r2 {
        animation: s1Breathe 5.5s ease-in-out infinite .4s;
    }

    .s1x-ring.r3 {
        animation: s1Breathe 4.5s ease-in-out infinite .8s;
    }

    @keyframes s1Breathe {

        0%,
        100% {
            transform: translate(-50%, -50%) scale(1);
            opacity: 1;
        }

        50% {
            transform: translate(-50%, -50%) scale(1.08);
            opacity: .72;
        }
    }

    /* a sweep of light travelling around the outer ring */
    .s1x-stage::before {
        content: "";
        position: absolute;
        left: 50%;
        top: 50%;
        width: 150px;
        height: 150px;
        margin: -75px 0 0 -75px;
        border-radius: 50%;
        background: conic-gradient(from 0deg,
                rgba(99, 102, 241, 0) 0deg,
                rgba(99, 102, 241, .35) 40deg,
                rgba(99, 102, 241, 0) 110deg);
        animation: s1Spin 4.5s linear infinite;
        pointer-events: none;
    }

    @keyframes s1Spin {
        to {
            transform: rotate(360deg);
        }
    }

    /* one dot riding the ring */
    .s1x-orbit {
        position: absolute;
        left: 50%;
        top: 50%;
        width: 150px;
        height: 150px;
        margin: -75px 0 0 -75px;
        animation: s1Spin 6s linear infinite;
        pointer-events: none;
    }

    .s1x-orbit i {
        position: absolute;
        top: -4px;
        left: 50%;
        margin-left: -4px;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #6366f1;
        box-shadow: 0 0 10px rgba(99, 102, 241, .8);
        display: block;
    }

    /* the mark lifts gently and its shadow pulses with it */
    .s1x-mark {
        animation: s1Float 4s ease-in-out infinite;
    }

    @keyframes s1Float {

        0%,
        100% {
            transform: translateY(0);
            box-shadow: 0 4px 16px rgba(15, 23, 42, .1), 0 12px 36px rgba(59, 91, 253, .14);
        }

        50% {
            transform: translateY(-7px);
            box-shadow: 0 10px 26px rgba(15, 23, 42, .13), 0 22px 48px rgba(59, 91, 253, .22);
        }
    }

    /* each chip bobs on its own clock, so the group never marches in step */
    .s1x-chip {
        animation: s1Bob 5s ease-in-out infinite;
    }

    .s1x-chip.c1 {
        animation-duration: 4.4s;
        animation-delay: 0s;
    }

    .s1x-chip.c2 {
        animation-duration: 5.6s;
        animation-delay: .6s;
    }

    .s1x-chip.c3 {
        animation-duration: 5.0s;
        animation-delay: 1.1s;
    }

    .s1x-chip.c4 {
        animation-duration: 4.8s;
        animation-delay: 1.7s;
    }

    @keyframes s1Bob {

        0%,
        100% {
            transform: translateY(0) rotate(0deg);
        }

        33% {
            transform: translateY(-8px) rotate(-3deg);
        }

        66% {
            transform: translateY(4px) rotate(2deg);
        }
    }

    .s1x-chip:hover {
        transform: scale(1.15) !important;
        animation-play-state: paused;
        box-shadow: 0 6px 18px rgba(15, 23, 42, .16);
        transition: transform .18s, box-shadow .18s;
    }

    /* sparkles twinkle out of phase */
    .s1x-spark {
        animation: s1Twinkle 3s ease-in-out infinite;
    }

    .s1x-spark.s2 {
        animation-duration: 2.4s;
        animation-delay: .7s;
    }

    .s1x-spark.s3 {
        animation-duration: 3.6s;
        animation-delay: 1.3s;
    }

    @keyframes s1Twinkle {

        0%,
        100% {
            opacity: .25;
            transform: scale(.8) rotate(0deg);
        }

        50% {
            opacity: 1;
            transform: scale(1.3) rotate(90deg);
        }
    }

    /* the right column arrives one piece at a time */
    .s1x-body>* {
        animation: s1Enter .5s cubic-bezier(.22, 1, .36, 1) backwards;
    }

    .s1x-body>*:nth-child(1) {
        animation-delay: .05s;
    }

    .s1x-body>*:nth-child(2) {
        animation-delay: .12s;
    }

    .s1x-body>*:nth-child(3) {
        animation-delay: .19s;
    }

    .s1x-body>*:nth-child(4) {
        animation-delay: .26s;
    }

    .s1x-body>*:nth-child(5) {
        animation-delay: .33s;
    }

    .s1x-body>*:nth-child(6) {
        animation-delay: .40s;
    }

    .s1x-body>*:nth-child(7) {
        animation-delay: .47s;
    }

    .s1x-body>*:nth-child(8) {
        animation-delay: .54s;
    }

    @keyframes s1Enter {
        from {
            opacity: 0;
            transform: translateY(14px);
        }

        to {
            opacity: 1;
            transform: none;
        }
    }

    /* the art panel slides in from the left */
    .s1x-art {
        animation: s1SlideIn .6s cubic-bezier(.22, 1, .36, 1) backwards;
    }

    @keyframes s1SlideIn {
        from {
            opacity: 0;
            transform: translateX(-18px);
        }

        to {
            opacity: 1;
            transform: none;
        }
    }

    /* the gradient behind the button drifts, and a highlight sweeps across it */
    .s1x-cta {
        background-size: 200% 100%;
        animation: s1Gradient 6s ease infinite;
        overflow: hidden;
    }

    @keyframes s1Gradient {

        0%,
        100% {
            background-position: 0% 50%;
        }

        50% {
            background-position: 100% 50%;
        }
    }

    .s1x-cta::before {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(105deg, transparent 35%, rgba(255, 255, 255, .32) 50%, transparent 65%);
        transform: translateX(-120%);
        animation: s1Shine 3.4s ease-in-out infinite;
        pointer-events: none;
    }

    @keyframes s1Shine {

        0%,
        62% {
            transform: translateX(-120%);
        }

        100% {
            transform: translateX(120%);
        }
    }

    /* the green tick pops once the URL is resolved */
    .s1x-field-ok {
        animation: s1Pop .5s cubic-bezier(.34, 1.56, .64, 1) .6s backwards;
    }

    @keyframes s1Pop {
        from {
            opacity: 0;
            transform: scale(.4) rotate(-25deg);
        }

        to {
            opacity: 1;
            transform: none;
        }
    }

    /* the connected-state tick draws attention once, then settles */
    .s1x-avatar-tick {
        animation: s1Pop .55s cubic-bezier(.34, 1.56, .64, 1) .35s backwards;
    }

    .s1x-avatar-wrap::after {
        content: "";
        position: absolute;
        inset: -6px;
        border-radius: 50%;
        border: 2px solid rgba(22, 163, 74, .4);
        animation: s1Halo 2.4s ease-out infinite;
        pointer-events: none;
    }

    @keyframes s1Halo {
        0% {
            transform: scale(.9);
            opacity: .7;
        }

        100% {
            transform: scale(1.25);
            opacity: 0;
        }
    }

    /* the trust card eases up last */
    .s1x-trust {
        animation: s1Enter .55s cubic-bezier(.22, 1, .36, 1) .5s backwards;
    }

    @media (max-width:900px) {
        .s1x-shell {
            grid-template-columns: 1fr;
        }

        .s1x-art {
            min-height: 210px;
            border-right: 0;
            border-bottom: 1px solid var(--s1-line);
        }

        .s1x-body {
            padding: 28px 22px;
        }

        .s1x-h1 {
            font-size: 20px;
        }
    }

    /* Every animation above is decoration. Someone who has asked the system to
   reduce motion gets the same layout with none of it. */
    @media (prefers-reduced-motion: reduce) {

        .s1x-ring,
        .s1x-mark,
        .s1x-chip,
        .s1x-spark,
        .s1x-cta,
        .s1x-cta::before,
        .s1x-body>*,
        .s1x-art,
        .s1x-trust,
        .s1x-field-ok,
        .s1x-avatar-tick,
        .s1x-avatar-wrap::after,
        .s1x-stage::before,
        .s1x-orbit {
            animation: none !important;
        }

        .s1x-cta,
        .s1x-cta .arrow,
        .s1x-chip {
            transition: none;
        }
    }
</style>

<div id="step1-container" class="space-y-12">

    <!-- Main Card -->
    <div class="mx-auto rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 relative overflow-hidden grid grid-cols-12" style="max-width: 940px;">
        <!-- Top gradient -->
        <div class="absolute top-0 left-0 right-0 h-1 bg-cstm-primary drk-bg-cstm-primary-gradient-dark"></div>
        <div class="col-span-12 lg:col-span-5">
            <div class="s1x-art h-full">
                <div class="s1x-stage">
                    <span class="s1x-ring r1"></span>
                    <span class="s1x-ring r2"></span>
                    <span class="s1x-ring r3"></span>
                    <span class="s1x-orbit"><i></i></span>

                    <div class="s1x-mark">
                        <img src="https://www.gstatic.com/images/branding/product/1x/googleg_48dp.png" alt="Google">
                    </div>

                    <!-- what Search Console actually gives you, as small marks -->
                    <span class="s1x-chip c1"><iconify-icon icon="solar:graph-up-bold" style="color:#3b5bfd;"></iconify-icon></span>
                    <span class="s1x-chip c2"><iconify-icon icon="solar:magnifer-bold" style="color:#16a34a;"></iconify-icon></span>
                    <span class="s1x-chip c3"><iconify-icon icon="solar:pie-chart-2-bold" style="color:#d97706;"></iconify-icon></span>
                    <span class="s1x-chip c4"><iconify-icon icon="solar:chart-square-bold" style="color:#7c3aed;"></iconify-icon></span>

                    <iconify-icon class="s1x-spark s1" icon="solar:star-bold"></iconify-icon>
                    <iconify-icon class="s1x-spark s2" icon="solar:star-bold"></iconify-icon>
                    <iconify-icon class="s1x-spark s3" icon="solar:star-bold"></iconify-icon>
                </div>

                <div class="s1x-trust">
                    <div class="s1x-trust-ic"><iconify-icon icon="solar:shield-check-bold" class="text-cstm-primary"></iconify-icon></div>
                    <div>
                        <h5 class="text-cstm-primary">Secure &amp; Trusted</h5>
                        <p>We use official Google authentication to keep your data safe.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-span-12 lg:col-span-7 p-3 md:p-8 flex flex-col items-start">

            <?php if (!$isConnected): ?>
                <!-- ======================= NOT CONNECTED ======================= -->
                <span class="inline-flex items-center self-start gap-2 px-3 py-1 rounded-full bg-success-100 border border-success-200 text-xs font-semibold text-success-main"><iconify-icon icon="solar:shield-check-bold" class="text-sm"></iconify-icon>Google Official Integration</span>
                <div class="relative mb-6 group mt-0">
                    <div class="absolute inset-0 bg-blue-100 dark:bg-blue-900/30 rounded-full scale-125 blur-xl opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
                    <div class="hidden relative bg-white dark:bg-neutral-700 p-5 rounded-full shadow-md border border-neutral-100 dark:border-neutral-600 ring-4 ring-neutral-50 dark:ring-neutral-700/50 inline-flex mb-6">
                        <img src="https://www.gstatic.com/images/branding/product/1x/googleg_48dp.png"
                            class="w-12 h-12"
                            alt="Google">
                    </div>
                    <div>
                       <h2 class="text-2xl font-bold mt-2">Connect <span class="text-cstm-primary">Google</span> Search Console</h2>
                        <p class="text-sm text-gray-500 mt-2">Get complete visibility into your website's search performance, keywords, and indexing data.</p>
                    </div>
                </div>

                <!-- Website URL display (step 1) -->
                <div class="w-full max-w-md mx-auto mb-6 text-left">
                    <label class="block text-xs font-semibold text-neutral-500 dark:text-neutral-400 mb-1">
                        Website URL
                    </label>
                    <div class="dashed boder-gray-200 rounded-md border px-3 py-2 flex items-center gap-2 mb-2 w-full">
                        <span class="rounded-md bg-cstm-primary-10 text-cstm-primary text-sm w-6 h-6 flex justify-center items-center"><iconify-icon icon="solar:global-outline"></iconify-icon></span>
                    <input
                        type="text"
                        id="websiteUrlInput"
                        value="<?= htmlspecialchars($siteDomain ?? '') ?>"
                        placeholder="example.com or https://example.com"
                        class="p-0 w-full border-0 pointer-events-none border-neutral-200 dark:border-neutral-600 focus:ring-2 focus:ring-cstm-primary focus:outline-none" readonly />
                    </div>
                </div>

                <button id="connectGoogleBtn"
                    class="w-full shadow-md btn btn-cstm-primary flex justify-center items-center gap-2 relative">
                    <iconify-icon icon="solar:link-circle-bold" class="text-base"></iconify-icon>
                    <span class="text-lg">Connect Your Domain</span>
                    <iconify-icon class="arrow absolute top-1\2 right-3 text-base" icon="solar:arrow-right-linear"></iconify-icon>
                </button>

                <div class="mt-2 flex items-center justify-center gap-2 text-center text-neutral-400 text-xs w-full">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    <span>Secure, encrypted Google authentication</span>
                </div>

            <?php else: ?>
                <!-- ======================= CONNECTED VIEW ======================= -->

                <!-- Avatar / Status -->
                <div class="relative mb-4">
                    <div class="absolute inset-0 bg-success-100 dark:bg-success-600/25 rounded-full blur-lg animate-pulse"></div>
                    <div class="s1x-avatar-wrap relative bg-white dark:bg-neutral-800 p-2 rounded-full border-2 border-success-100 dark:border-success-600 shadow-sm inline-block">
                        <?php if (!empty($googleAccount['picture'])): ?>
                            <img src="<?= htmlspecialchars($googleAccount['picture']) ?>" alt="User" class="w-20 h-20 rounded-full object-cover">
                        <?php else: ?>
                            <div class="w-20 h-20 rounded-full bg-success-100 dark:bg-success-600/25 text-success-600 flex items-center justify-center text-3xl font-bold">
                                <?= strtoupper(substr($googleAccount['email'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>

                        <div class="s1x-avatar-tick absolute top-1 right-1 bg-success-600 text-white p-1 rounded-full border-4 border-white dark:border-neutral-600">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <h3 class="text-xl font-bold text-neutral-900 dark:text-white">
                    Account Connected!
                </h3>
                <p class="text-gray-400 mt-2 text-xs">Your Google account is linked. Next, verify which property this site belongs to.</p>

                <div class="mt-3 mb-4 inline-flex items-center gap-2 px-4 py-1.5 bg-neutral-50 dark:bg-neutral-700 rounded-lg border border-neutral-300 dark:border-neutral-600 text-sm">
                    <img src="https://www.gstatic.com/images/branding/product/1x/googleg_48dp.png" class="w-4 h-4" alt="">
                    <span class="font-medium text-neutral-700 dark:text-neutral-200">
                        <?= htmlspecialchars($googleAccount['email']) ?>
                    </span>
                </div>

                <!-- Show website URL here as well -->
                <div class="w-full text-left mb-4">
                    <label class="block text-xs font-semibold text-neutral-700 dark:text-neutral-400 mb-1">
                        Website URL
                    </label>
                    <div class="w-full px-4 py-2 text-sm rounded-lg dark:bg-neutral-800 text-neutral-700 dark:text-neutral-200 border border-neutral-200 dark:border-neutral-600 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 w-full">
                            <span class="w-6 h-6 shrink-0 rounded-md bg-gray-100 flex justify-center items-center text-sm text-cstm-primary"><iconify-icon icon="solar:global-outline"></iconify-icon></span>
                            <input
                                type="text"
                                id="websiteUrlInput"
                                value="<?= htmlspecialchars($siteDomain ?? '') ?>"
                                class="p-0 border-0 border-neutral-200 dark:border-neutral-600 focus:ring-2 focus:ring-cstm-primary focus:outline-none pointer-events-none w-full" readonly />
                        </div>
                        <iconify-icon class="s1x-field-ok text-sm text-success-main" icon="solar:check-circle-bold"></iconify-icon>
                    </div>
                </div>
                <div class="w-full space-y-3">

                    <a href="?step=2"
                        id="continueSetupBtn"
                        class="btn btn-cstm-primary w-full text-lg font-semibold shadow-lg transition-all flex items-center justify-center gap-2 group">
                        <span>Continue Setup</span>
                        <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                        </svg>
                    </a>

                    <button id="disconnectGoogleBtn"
                        class="btn border border-danger-400 text-danger-600 w-full text-sm font-medium flex items-center justify-center transition-colors">
                        Disconnect this account
                    </button>
                </div>

            <?php endif; ?>
        </div>
    </div>


    <!-- Disconnect Confirmation Modal -->
    <div id="disconnect-modal-overlay" class="fixed inset-0 bg-cstm-black-40 backdrop-blur-sm z-50 hidden opacity-0 transition-opacity duration-300"></div>

    <div id="disconnect-modal" class="fixed top-1/2 left-1/2 z-99 w-full max-w-2xl bg-white dark:bg-neutral-800 rounded-2xl shadow-xl p-0 hidden opacity-0 scale-95 transition-all duration-300 -translate-x-1/2 -translate-y-1/2 overflow-hidden border border-neutral-200 dark:border-neutral-600 p-10">

        <div class="p-8 text-center">
            <div class="mx-auto w-16 h-16 bg-danger-100 dark:bg-danger-900/30 text-danger-600 rounded-full flex items-center justify-center mb-6">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
            </div>

            <h3 class="text-xl font-bold text-neutral-900 dark:text-white mb-2">Disconnect Account?</h3>

            <p class="text-neutral-500 dark:text-neutral-400 text-sm leading-relaxed mb-8">
                This will remove your Google Search Console connection. You'll need to reconnect to verify your domain.
            </p>

            <div class="flex items-center justify-center gap-3">
                <button id="cancelDisconnect" class="btn btn-cstm-muted">
                    Cancel
                </button>
                <button id="confirmDisconnect" class="btn btn-cstm-danger">
                    Disconnect
                </button>
            </div>
        </div>
    </div>

</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Continue Setup (already working)
        var continueBtn = document.getElementById('continueSetupBtn');
        if (continueBtn) {
            continueBtn.addEventListener('click', function(e) {
                e.preventDefault();

                continueBtn.disabled = true;
                continueBtn.classList.add('opacity-70', 'cursor-wait');

                var formData = new FormData();
                formData.append('step', '1'); // Mark step 1 as completed

                fetch(window.APP_BASE + "/api/update-step.php", {
                        method: 'POST',
                        body: formData
                    })
                    .catch(function() {})
                    .finally(function() {
                        window.location.href = '?step=2';
                    });
            });
        }

        // ===== Disconnect Flow =====
        var disconnectBtn = document.getElementById('disconnectGoogleBtn');
        var modal = document.getElementById('disconnect-modal');
        var overlay = document.getElementById('disconnect-modal-overlay');
        var cancelDisconnect = document.getElementById('cancelDisconnect');
        var confirmDisconnect = document.getElementById('confirmDisconnect');

        if (disconnectBtn && modal && overlay && cancelDisconnect && confirmDisconnect) {
            // Open modal
            disconnectBtn.addEventListener('click', function() {
                modal.classList.remove('hidden');
                overlay.classList.remove('hidden');

                requestAnimationFrame(function() {
                    modal.classList.remove('opacity-0', 'scale-95');
                    overlay.classList.remove('opacity-0');
                });
            });

            // Close modal
            cancelDisconnect.addEventListener('click', function() {
                modal.classList.add('opacity-0', 'scale-95');
                overlay.classList.add('opacity-0');
                setTimeout(function() {
                    modal.classList.add('hidden');
                    overlay.classList.add('hidden');
                }, 200);
            });

            // Confirm disconnect
            confirmDisconnect.addEventListener('click', function() {
                confirmDisconnect.disabled = true;

                fetch(window.APP_BASE + "/includes/google/disconnect.php", {
                        method: 'POST',
                        credentials: 'same-origin'
                    })
                    .then(function(res) {
                        return res.json();
                    })
                    .then(function() {
                        window.location.href = window.APP_BASE + "/setup-wizard.php?step=1";
                    })
                    .catch(function() {
                        window.location.href = window.APP_BASE + "/setup-wizard.php?step=1";
                    });
            });
        }
    });
</script>