<?php

/**
 * partials/preview-popup.php
 *
 * 14-Day Premium Preview popup
 *
 * 2 tarike se khulta hai:
 *   1. AUTO  -> free user + trial kabhi use nahi kiya + cooldown off
 *   2. CLICK -> koi bhi element jispe class "openTrialModal" ho
 *
 * Cooldown: close karne ke baad 10 minute tak auto nahi khulega.
 *           (button se tab bhi khulega)
 *
 * Unlock hone par: popup turant band + full-screen loader, phir reload.
 */

$renderPreviewPopup = false;   // markup page pe print hoga ya nahi
$autoShowPreview    = false;   // apne aap khulega ya nahi

try {
    // $pvIid = $_SESSION['instanceid'] ?? null;

    // if ($pvIid && empty($hasPremiumAccess) && isset($pdo)) {
    //     // Free user hai -> markup hamesha render karo (click ke liye zaroori)
    //     $renderPreviewPopup = true;

    //     // Trial kabhi liya? record hai = liya hua hai
    //     $pvStmt = $pdo->prepare("SELECT id FROM app_free_trials WHERE instance_id = ? LIMIT 1");
    //     $pvStmt->execute([$pvIid]);
    //     $pvUsed = (bool)$pvStmt->fetch();

    //     // Auto sirf tab jab pehli baar ho
    //     $autoShowPreview = !$pvUsed;
    // }
    $pvIid = $_SESSION['instanceid'] ?? null;

    if ($pvIid && isset($pdo)) {

        // First check active subscription
        $subStmt = $pdo->prepare("
        SELECT id
        FROM app_subscriptions
        WHERE instance_id = ?
          AND status = 'active'
        LIMIT 1
    ");
        $subStmt->execute([$pvIid]);

        $hasActiveSubscription = (bool)$subStmt->fetch();

        // Paid user -> NEVER show trial popup
        if ($hasActiveSubscription) {
            $renderPreviewPopup = false;
            $autoShowPreview = false;
        } else {

            // Free user
            $renderPreviewPopup = true;

            // Trial already used?
            $pvStmt = $pdo->prepare("
            SELECT id
            FROM app_free_trials
            WHERE instance_id = ?
            LIMIT 1
        ");
            $pvStmt->execute([$pvIid]);

            $pvUsed = (bool)$pvStmt->fetch();

            // Auto show only if trial never used
            $autoShowPreview = !$pvUsed;
        }
    }
} catch (Throwable $e) {
    // DB fail ho jaye to bhi click wala flow chalta rahe
    $renderPreviewPopup = true;
    $autoShowPreview    = false;
}
?>

<style>
    #previewPopupOverlay {
        position: fixed;
        inset: 0;
        z-index: 50;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition:
            opacity 0.18s ease,
            visibility 0s linear 0.25s;
    }

    #previewPopupOverlay.preview-popup-open {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        transition:
            opacity 0.18s ease,
            visibility 0s;
        
    }

    #previewPopupOverlay>div {
        position: absolute;
        z-index: 70;
        opacity: 0;
        transform:  scale(6);
        transition: opacity 0.2s cubic-bezier(.22, 1, .36, 1), 
                transform 0.2s cubic-bezier(.22, 1, .36, 1);
        
    }

    #previewPopupOverlay.preview-popup-open>div {
        opacity: 1;
         transform:  scale(1);
    }

    @keyframes previewContentIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }


    @keyframes previewRocketFloat {

        0%,
        100% {
            transform: translateY(0) rotate(0);
        }

        50% {
            transform: translateY(6px) rotate(-1deg);
        }
    }

    #activatePreviewBtn {
        position: relative;
        overflow: hidden;
        isolation: isolate;
    }

    #activatePreviewBtn::before {
        content: "";

        position: absolute;
        inset: 0;

        background:
            linear-gradient(110deg,
                transparent 20%,
                rgba(255, 255, 255, 0.32) 45%,
                transparent 70%);

        transform: translateX(-120%);

        animation:
            previewButtonShine 3.5s ease-in-out infinite;

        pointer-events: none;
    }

    #activatePreviewBtn>* {
        position: relative;
        z-index: 1;
    }

    @keyframes previewButtonShine {

        0%,
        45% {
            transform: translateX(-120%);
        }

        65%,
        100% {
            transform: translateX(120%);
        }
    }

    #previewPopupOverlay .preview-celebration {
        position: fixed;
        inset: 0;
        width: 100vw;
        height: 100vh;
        z-index: 60;
        display: flex;
        align-items: center;
        justify-content: center;
        pointer-events: none;
        overflow: hidden;
        opacity: 1;
        visibility: visible;
    }


    #previewPopupOverlay .preview-celebration-svg {
        position: fixed;
        inset: 0;
        width: 100vw;
        height: 100vh;
        object-fit: cover;
        z-index: 60;
        pointer-events: none;
        opacity: 0;
        visibility: hidden;
    }

    #previewPopupOverlay.preview-celebration-active .preview-celebration-svg {
        opacity: 1;
        visibility: visible;
    }

    #previewPopupOverlay .preview-celebration img {
        display: block;
        width: 100%;
        height: 100%;
        max-width: none;
        max-height: none;
        object-fit: cover;
        pointer-events: none;
        opacity: 1;
    }

    #previewPopupOverlay .preview-celebration.is-hidden {
        opacity: 0;
        visibility: hidden;
    }

    @media (max-width: 640px) {

        #previewPopupOverlay>div {
            transform: scale(1.06);
        }

        #previewPopupOverlay.preview-popup-open>div {
            transform: scale(1);
        }

        #previewPopupOverlay .preview-celebration img {
            width: 100vw;
            height: 100vh;
            object-fit: cover;
        }
    }

    @media (prefers-reduced-motion: reduce) {

        #previewPopupOverlay .preview-celebration {
            display: none !important;
        }

        #previewPopupOverlay .preview-celebration img {
            display: none !important;
        }

        #previewPopupOverlay .,
        #previewPopupOverlay .preview-celebration,
        #previewPopupOverlay img[alt="14-Day Premium Preview"],
        #activatePreviewBtn::before {
            animation: none !important;
        }

        #previewPopupOverlay>div {
            transition:
                opacity 0.2s ease,
                transform 0.2s ease;
            transform: scale(1);
            filter: none;
        }
    }
</style>
<?php if ($renderPreviewPopup): ?>
    <!-- 14-Day Premium Preview POPUP -->

    <div id="previewPopupOverlay" data-autoshow="<?= $autoShowPreview ? '1' : '0' ?>" class="fixed inset-0 z-50 flex items-center justify-center bg-cstm-black-40 px-4 py-6 overflow-y-auto hidden">
        <img class="preview-celebration-svg"
            data-src="./assets/images/animated-celebration.svg"
            src=""
            alt="Celebration">
        <div class="absolute bg-white dark:bg-neutral-900 rounded-2xl shadow-xl w-full my-auto overflow-y-auto border border-neutral-100 dark:border-neutral-800 max-h-[90vh]" style="max-width: 720px;">
            <div>
            <img src="./assets/images/rocket-cloud-asset.gif" alt="14-Day Premium Preview" class="absolute top-0 left-0 w-full h-auto object-contain drop-shadow-md max-w-[280px] ">
            <div class="relative py-4">
                <!-- Top Header (Centered) -->
                <div class="text-center mb-5 px-6  ">
                    <div class="inline-flex items-center justify-center gap-2.5 flex-col sm:flex-row">
                        <!-- Gradient Crown Icon -->
                        <span class="inline-flex text-2xl sm:text-base bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 bg-clip-text text-transparent">
                            <iconify-icon icon="solar:crown-bold" class="text-cstm-primary"></iconify-icon>
                        </span>
                        <h2 class="text-lg sm:text-lg font-extrabold tracking-tight text-neutral-900 dark:text-white">
                            14-Day Premium Preview
                        </h2>
                        <!-- FREE Pill Badge -->
                        <span class="inline-flex items-center gap-1 bg-success-main text-white text-[10px] font-semibold px-3 py-1 rounded-full shadow-sm shadow-green-500/20">
                            <iconify-icon icon="tabler:sparkles-filled" class="text-sm"></iconify-icon>FREE
                        </span>
                    </div>
                    <p class="text-neutral-500 dark:text-neutral-400 text-xs mt-1 font-medium">
                        Unlock powerful insights. See the real value before you buy.
                    </p>
                    <!-- Subtle centered accent line -->
                    <div class="w-12 h-1 bg-cstm-primary rounded-full mx-auto mt-2 opacity-80"></div>
                </div>

                <!-- Two Column Layout -->
                <div class="grid grid-cols-12">

                    <!-- Left Column: Rocket Illustration & Overlay 14-Days Badge -->
                    <div class="col-span-12 md:col-span-4 relative">
                        <div class="absolute right-1/2 translate-x-1/2 hidden md:block" style="bottom: 2rem;">
                            <div class="w-full max-w-[280px] select-none pointer-events-none">
                            </div>

                            <!-- Floating 14-Days Badge -->
                            <div class="w-[200px] bg-gray-50 dark:bg-neutral-800/95 backdrop-blur-md border border-neutral-200/80 dark:border-neutral-700 rounded-2xl py-3 px-4  flex items-center gap-4 -mt-6 z-10 mx-auto">
                                <div class="shrink-0 text-cstm-primary">
                                    <iconify-icon icon="solar:calendar-bold-duotone" class="text-4xl block"></iconify-icon>
                                </div>
                                <div class="leading-tight">
                                    <p class="font-extrabold text-neutral-900 dark:text-white text-lg">14 Days</p>
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400 font-medium">Premium Access</p>
                                    <p class="text-xs font-bold text-success-600">Absolutely Free</p>
                                </div>
                            </div>

                            <!-- Decorative Curved Directional Arrow -->
                            <span class="block absolute -bottom-4 max-w-[100px]" style="right: -40px;">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 90" width="100%" height="100%">
                                    <defs>
                                        <!-- Gradient for fading lavender tail to vibrant purple arrow head -->
                                        <linearGradient id="arrowGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                            <stop offset="0%" stop-color="#7028FF" stop-opacity="0.05" />
                                            <stop offset="20%" stop-color="#6720F5" stop-opacity="0.35" />
                                            <stop offset="55%" stop-color="#5512EE" stop-opacity="0.85" />
                                            <stop offset="100%" stop-color="#4904E0" stop-opacity="1" />
                                        </linearGradient>
                                    </defs>

                                    <!-- Curved sweeping path -->
                                    <path d="M 10,14 C 35,58 120,86 242,32"
                                        fill="none"
                                        stroke="url(#arrowGradient)"
                                        stroke-width="7.5"
                                        stroke-linecap="round" />

                                    <!-- Arrowhead pointing top-right -->
                                    <path d="M 220,18 L 254,26 L 238,55"
                                        fill="none"
                                        stroke="#4904E0"
                                        stroke-width="7.5"
                                        stroke-linecap="round"
                                        stroke-linejoin="round" />
                                </svg>
                            </span>
                        </div>
                    </div>

                    <!-- Right Column: Features, Alert & Action Button -->
                    <div class="col-span-12 md:col-span-8 px-6 ">
                        <div class="flex flex-col space-y-4">

                            <!-- Feature Cards (Single border around the entire group) -->
                            <div class="border border-neutral-100 dark:border-neutral-800/80 rounded-2xl px-3 space-y-1 bg-neutral-50/50 dark:bg-neutral-800/20 review-animate " style="background: #FFF; background: linear-gradient(83deg, rgba(255, 255, 255, 1) 0%, rgba(246, 249, 254, 1) 50%);">
                                <?php
                                $features = [
                                    [
                                        'icon'  => 'tabler:car',
                                        'title' => 'Full Traffic Performance charts',
                                        'desc'  => 'See how your keywords and pages perform',
                                    ],
                                    [
                                        'icon'  => 'tabler:search',
                                        'title' => 'All Keyword Intelligence reports',
                                        'desc'  => 'Discover high-value keywords & opportunities',
                                    ],
                                    [
                                        'icon'  => 'tabler:tag',
                                        'title' => 'Brand & Non-Brand keyword data',
                                        'desc'  => 'Know what drives traffic to your site',
                                    ],
                                    [
                                        'icon'  => 'tabler:world',
                                        'title' => 'Pages, Countries & Devices reports',
                                        'desc'  => 'Deep insights for smarter SEO decisions',
                                    ],
                                ];
                                foreach ($features as $feature): ?>
                                    <div class="flex items-center gap-3 px-3 py-2 rounded-xl transition-colors">
                                        <div class="w-6 h-6 rounded-lg bg-cstm-primary-10 dark:bg-blue-950/60 border-0 border-blue-100/60 dark:border-blue-900/40 flex items-center justify-center shrink-0">
                                            <iconify-icon icon="<?= htmlspecialchars($feature['icon']) ?>" class="text-cstm-primary dark:text-blue-400 text-base"></iconify-icon>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <h5 class="font-semibold text-neutral-900 dark:text-white text-xs leading-tight">
                                                <?= htmlspecialchars($feature['title']) ?>
                                            </h5>
                                            <h6 class="text-[10px] text-neutral-500 dark:text-neutral-400 leading-snug mt-0.5">
                                                <?= htmlspecialchars($feature['desc']) ?>
                                            </h6>
                                        </div>
                                        <iconify-icon icon="solar:check-circle-bold" class="text-success-main text-xl shrink-0"></iconify-icon>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Green Banner -->
                            <div class="relative overflow-hidden flex items-center gap-3 border-l-4 border-success-600 bg-success-100 rounded-md px-4 py-3  ">
                                <div class="text-success-600 shrink-0 flex items-center">
                                    <iconify-icon icon="solar:bolt-bold" class="text-2xl"></iconify-icon>
                                </div>
                                <div class="text-[10px] leading-snug flex flex-col">
                                    <h4 class="font-semibold text-xs text-neutral-900 dark:text-white">Explore SEO insights data and app features free for 14 Days.</h4>
                                    <h5 class=" text-[10px]">
                                        <span class="text-success-600 font-semibold">No card required</span>
                                        <span class="text-neutral-500 dark:text-neutral-400"> - activate instantly.</span>
                                    </h5>
                                </div>
                            </div>

                            <!-- Action Button & Maybe Later -->
                            <div class="space-y-2.5 pt-1  ">
                                <button type="button" id="activatePreviewBtn"
                                    class="w-full flex items-center justify-center gap-2.5 hover:opacity-95 active:scale-[0.99] transition-all text-white font-semibold text-sm py-2 rounded-md shadow-lg shadow-blue-500/25" style="background: linear-gradient( 95deg, #5f2bf6 0%, #4a3ff7 18%, #2563eb 55%, #0ea5e9 88%, #38bdf8 100% );">
                                    <iconify-icon icon="mdi:rocket-launch" class="text-xl"></iconify-icon>
                                    Unlock Premium for 14 Days
                                    <iconify-icon icon="tabler:arrow-right" class="text-lg"></iconify-icon>
                                </button>

                                <!-- Directly under the button, where the hesitation is.

                                     The same reassurance sits higher up in the feature
                                     list, but that is read before anyone has decided to
                                     click. The moment the pointer is on a button marked
                                     "Unlock Premium" is when "am I about to be charged"
                                     occurs to someone, and it is the one objection that
                                     stops a free trial. -->
                                <p class="text-center text-[11px] text-neutral-500 dark:text-neutral-400 flex items-center justify-center gap-1 mt-1">
                                    <iconify-icon icon="mdi:shield-check-outline" class="text-success-600 text-sm"></iconify-icon>
                                    <span class="text-sm"><span class="text-success-600 font-semibold">No card required</span> - cancel any time</span>
                                </p>

                                <div class="text-center">
                                    <a href="javascript:void(0)" id="skipPreview" class="underline underline-offset-4 hover:text-neutral-800 dark:hover:text-neutral-200 transition-colors mt-2">
                                        <h6 class="text-xs font-semibold text-neutral-500 dark:text-neutral-400"> Maybe later </h6> 
                                    </a>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

               

            </div>
            </div>
        </div>
    </div>

    <script>
        (function() {
            var overlay = document.getElementById('previewPopupOverlay');
            if (!overlay) return;

            var KEY = 'previewPopupNextShow';

            // Close karne ke baad kitni der auto na khule (milliseconds)
            // 10 minute = 10 * 60 * 1000
            // Launch ke waqt isse badha dena, e.g. 3 * 60 * 60 * 1000 (3 ghante)
            var COOLDOWN_MS = 10 * 60 * 1000;

            var celebrationTimer;
            var celebrationSvg = overlay.querySelector('.preview-celebration-svg');
            var celebrationBaseUrl = celebrationSvg ? (celebrationSvg.dataset.src || celebrationSvg.src) : '';

            function startCelebration() {
                if (!celebrationSvg || !celebrationBaseUrl) return;

                clearTimeout(celebrationTimer);

                // 1. Clear source immediately to reset rendering
                celebrationSvg.src = '';

                // 2. Add timestamp parameter to force browser to restart animation timeline
                var freshUrl = celebrationBaseUrl.split('?')[0] + '?t=' + Date.now();
                celebrationSvg.src = freshUrl;

                // 3. Show celebration overlay
                overlay.classList.add('preview-celebration-active');

                // 4. Hide after duration and clear src to stop background execution
                celebrationTimer = setTimeout(function() {
                    overlay.classList.remove('preview-celebration-active');
                    celebrationSvg.src = '';
                }, 5500);
            }

            function stopCelebration() {
                clearTimeout(celebrationTimer);

                if (celebrationSvg) {
                    overlay.classList.remove('preview-celebration-active');
                }
            }

            function showPreviewPopup() {

                overlay.classList.remove('hidden');
                overlay.classList.add('flex');

                stopCelebration();

                void overlay.offsetWidth;

                requestAnimationFrame(function() {

                    overlay.classList.add('preview-popup-open');

                    setTimeout(function() {
                        startCelebration();
                    }, 300);

                });
            }

            function hidePreviewPopup() {

                stopCelebration();

                overlay.classList.remove('preview-popup-open');
                overlay.classList.remove('preview-celebration-active');

                setTimeout(function() {
                    if (!overlay.classList.contains('preview-popup-open')) {
                        overlay.classList.add('hidden');
                        overlay.classList.remove('flex');
                    }
                }, 400);
            }

            // Full-screen loader - reload hone tak dikhta hai
            function showUnlockLoader() {
                if (document.getElementById('previewUnlockLoader')) return;

                var el = document.createElement('div');
                el.id = 'previewUnlockLoader';
                el.style.cssText =
                    'position:fixed;inset:0;z-index:9999;display:flex;' +
                    'align-items:center;justify-content:center;flex-direction:column;gap:14px;' +
                    'background:rgba(255,255,255,.92);backdrop-filter:blur(4px);' +
                    'font-family:inherit;color:#0c4a6e;';
                el.innerHTML =
                    '<div style="width:42px;height:42px;border:4px solid #bae6fd;' +
                    'border-top-color:#0284c7;border-radius:50%;' +
                    'animation:pvspin .7s linear infinite"></div>' +
                    '<div style="font-size:15px;font-weight:600">Premium Unlocked </div>' +
                    '<div style="font-size:13px;opacity:.7">Loading your dashboard...</div>' +
                    '<style>@keyframes pvspin{to{transform:rotate(360deg)}}</style>';
                document.body.appendChild(el);
            }

            function isReviewOpen() {
                var rm = document.getElementById('reviewModal');
                return rm && !rm.classList.contains('hidden');
            }

            // -----------------------------------------------------
            // DISMISS (&times; / Maybe later / backdrop) -> cooldown set
            // -----------------------------------------------------
            function dismiss() {
                localStorage.setItem(KEY, String(Date.now() + COOLDOWN_MS));
                hidePreviewPopup();
            }

            document.getElementById('closePreviewPopup')?.addEventListener('click', dismiss);
            document.getElementById('skipPreview')?.addEventListener('click', dismiss);

            // -----------------------------------------------------
            // ACTIVATE -> start_trial.php -> reload
            // -----------------------------------------------------
            var btn = document.getElementById('activatePreviewBtn');
            btn?.addEventListener('click', async function() {
                btn.disabled = true;
                btn.textContent = 'Unlocking...';
                try {
                    var res = await fetch('api/start_trial.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            source: 'dashboard_preview_popup',
                            preview: true
                        })
                    });
                    var data = await res.json();

                    if (data.success && !data.skipped) {
                        localStorage.removeItem(KEY);

                        // Popup turant band -> user ko atka hua na lage
                        hidePreviewPopup();
                        showUnlockLoader();

                        window.location.reload();
                    } else if (data.reason === 'trial_already_used') {
                        btn.textContent = 'Preview already used on this site';
                        setTimeout(dismiss, 1500);
                    } else if (data.reason === 'instance_has_paid_plan') {
                        btn.textContent = 'You already have Premium ';
                        setTimeout(dismiss, 1500);
                    } else {
                        btn.textContent = 'Something went wrong - try again';
                        btn.disabled = false;
                    }
                } catch (e) {
                    btn.textContent = 'Network error - try again';
                    btn.disabled = false;
                }
            });

            // -----------------------------------------------------
            // 1) CLICK TRIGGER - sidebar ka "Activate free trial" link
            //    Cooldown ignore, kyunki user ne khud click kiya hai
            // -----------------------------------------------------
            document.addEventListener('click', function(e) {
                var trigger = e.target.closest('.openTrialModal');
                if (!trigger) return;
                e.preventDefault();

                // Button reset (agar pehle error aaya tha)
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Unlock Premium for 14 Days';
                }
                showPreviewPopup();
            });

            // -----------------------------------------------------
            // 2) AUTO SHOW - login/page load ke baad
            // -----------------------------------------------------
            if (overlay.dataset.autoshow !== '1') return;

            var next = parseInt(localStorage.getItem(KEY) || '0', 10);
            if (Date.now() < next) return;

            function startAutoShow() {
                setTimeout(function() {
                    if (!isReviewOpen()) {
                        showPreviewPopup();
                        return;
                    }
                    // Review popup khula hai -> band hone ka wait karo
                    var watcher = setInterval(function() {
                        if (!isReviewOpen()) {
                            clearInterval(watcher);
                            setTimeout(showPreviewPopup, 600);
                        }
                    }, 500);
                }, 2500);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', startAutoShow);
            } else {
                startAutoShow();
            }
        })();
    </script>
<?php endif; ?>