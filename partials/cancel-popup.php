<?php
// Try to detect instance_id from context (parent page should ideally set $instanceId)
if (!isset($instanceId)) {
    if (session_status() === PHP_SESSION_NONE) {
        // Parent page should normally handle session_start(), but this is a safe fallback.
        @session_start();
    }
    $instanceId = $_SESSION['instance_id'] ?? ($_GET['instanceId'] ?? '');
}
?>
<div id="schema-cancel-overlay"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-cstm-black-50 backdrop-blur-sm transition-opacity">
    <div id="schema-cancel-modal"
        class="relative max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white dark:bg-neutral-800 p-6 shadow-xl transition-all duration-200">

        <button type="button"
            class="absolute right-4 top-4 rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
            onclick="CancellationFlow.close()">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
                viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>

        <input type="hidden" id="schema-cancel-instance-id"
            value="<?= htmlspecialchars($instanceId ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <div id="schema-cancel-step-1" class="schema-cancel-step space-y-6">
            <div class="mb-4">
                <h2 class="text-2xl font-bold text-gray-900 text-center">
                    Before you go, what made you install this Google Search Console App?
                </h2>
                <p class="mt-1 text-base text-center text-gray-500">
                    This helps us understand what you were hoping to achieve with Google Search Console and indexing.
                </p>
            </div>

            <div id="schema-join-reasons" class="space-y-3 max-h-[54vh] overflow-y-auto">
                <button type="button" data-value="seo"
                    class="schema-card w-full rounded-xl border border-gray-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-900 p-4 text-start hover:bg-cstm-primary-10 transition-colors">
                    <p class="font-medium text-gray-900">Improve SEO & visibility</p>
                    <p class="mt-1 text-sm text-gray-500">
                        I wanted my site to be indexed faster and show up better in Google Search.
                    </p>
                </button>

                <button type="button" data-value="fix-errors"
                    class="schema-card w-full rounded-xl border border-gray-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-900 p-4 text-start hover:bg-cstm-primary-10 transition-colors">
                    <p class="font-medium text-gray-900">
                        Connect and verify my site in Google Search Console
                    </p>
                    <p class="mt-1 text-sm text-gray-500">
                        I wanted an easier way to get my site verified and connected without manual setup.
                    </p>
                </button>

                <button type="button" data-value="product-indexing"
                    class="schema-card w-full rounded-xl border border-gray-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-900 p-4 text-start hover:bg-cstm-primary-10 transition-colors">
                    <p class="font-medium text-gray-900">Get my product pages indexed faster</p>
                    <p class="mt-1 text-sm text-gray-500">
                        I wanted new and updated product pages to appear in Google Search more quickly.
                    </p>
                </button>

                <button type="button" data-value="content-indexing"
                    class="schema-card w-full rounded-xl border border-gray-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-900 p-4 text-start hover:bg-cstm-primary-10 transition-colors">
                    <p class="font-medium text-gray-900">Get blogs and pages indexed faster</p>
                    <p class="mt-1 text-sm text-gray-500">
                        I wanted my blog posts, collections, and other pages to be discovered sooner in Google Search.
                    </p>
                </button>

                <button type="button" data-value="auto-indexing"
                    class="schema-card w-full rounded-xl border border-gray-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-900 p-4 text-start hover:bg-cstm-primary-10 transition-colors">
                    <p class="font-medium text-gray-900">Automatically notify Google about updates</p>
                    <p class="mt-1 text-sm text-gray-500">
                        I wanted the app to inform Google when my products and content are updated.
                    </p>
                </button>

                <button type="button" data-value="simple-setup"
                    class="schema-card w-full rounded-xl border border-gray-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-900 p-4 text-start hover:bg-cstm-primary-10 transition-colors">
                    <p class="font-medium text-gray-900">Keep my indexing and Search Console setup simple</p>
                    <p class="mt-1 text-sm text-gray-500">
                        I wanted a clean, central way to handle verification and indexing without juggling multiple tools.
                    </p>
                </button>

                <div class="space-y-2">
                    <button type="button" data-value="other"
                        class="schema-card w-full rounded-xl border border-gray-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-900 p-4 text-start hover:bg-cstm-primary-10 transition-colors">
                        <p class="font-medium text-gray-900">Something else</p>
                        <p class="mt-1 text-sm text-gray-500">
                            I had a different reason.
                        </p>
                    </button>
                    <textarea id="schema-join-other-text"
                        class="mt-1 hidden w-full rounded-lg border dark:bg-neutral-900 border-gray-300 p-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                        placeholder="Tell us a bit more (optional)..."></textarea>
                </div>
            </div>

            <p id="schema-step-1-error" class="hidden text-sm text-danger-600 dark:text-danger-400 font-medium">
                Please select at least one option to continue.
            </p>

            <div class="flex justify-between gap-3">
                <button type="button"
                    class="btn-cstm-primary rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 transition-colors"
                    onclick="CancellationFlow.keepSubscription()">
                    Keep the app
                </button>
                <button type="button"
                    class="btn-cstm-primary rounded-lg px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition-colors"
                    onclick="CancellationFlow.goToStep(2)">
                    Next
                </button>
            </div>
        </div>

        <div id="schema-cancel-step-2" class="schema-cancel-step hidden space-y-4 max-w-2xl">

            <div class="p-4 bg-warning-50 dark:bg-warning-600/10 border border-warning-300 dark:border-yellow-600 rounded-lg shadow-sm mt-6">
                <div class="flex items-start gap-2 flex-col justify-center text-center  items-center">
                    <i class="fa-solid fa-triangle-exclamation text-3xl text-warning-600"></i>
                    <div class="ml-3 text-left">
                        <h2 id="schema-mirror-heading" class="text-xl font-semibold text-gray-900 dark:text-white">
                            Let’s make sure you don’t lose what you’ve already set up
                        </h2>
                        <p id="schema-mirror-subtitle" class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Based on why you installed the app, here’s what cancelling means.
                        </p>
                    </div>
                </div>
            </div>

            <div id="schema-mirror-body" class="space-y-4 text-base text-gray-700 dark:text-gray-200 leading-relaxed min-h-[100px]">
            </div>

            <div class="flex justify-between gap-3">
                <button type="button"
                    class="btn-cstm-primary rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 transition-colors"
                    onclick="CancellationFlow.goToStep(1)">
                    Back
                </button>
                <div class="flex gap-2">
                    <button type="button"
                        class="btn-cstm-primary rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 transition-colors"
                        onclick="CancellationFlow.keepSubscription()">
                        Keep the app
                    </button>
                    <button type="button"
                        class="btn-cstm-primary rounded-lg btn-cstm-primary px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition-colors"
                        onclick="CancellationFlow.goToStep(3)">
                        Next
                    </button>
                </div>
            </div>
        </div>

        <div id="schema-cancel-step-3" class="schema-cancel-step hidden space-y-6">
            <div class="mb-4">
                <h2 class="text-2xl font-bold text-gray-900 text-center">
                    What’s the main reason you want to cancel?
                </h2>
                <p class="mt-1 text-base text-center text-gray-500">
                    This helps us improve the app and better support future users.
                </p>
            </div>

            <div id="schema-leave-reasons" class="space-y-3 max-h-[54vh] overflow-y-auto">
                <button type="button" data-value="not-working"
                    class="schema-card w-full rounded-xl border border-gray-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-900 p-4 text-start hover:bg-cstm-primary-10 transition-colors">
                    <p class="font-medium text-gray-900">The app doesn’t seem to work / no visible effect</p>
                    <p class="mt-1 text-sm text-gray-500">
                        I don’t see any change in indexing or performance in Google Search.
                    </p>
                </button>

                <button type="button" data-value="gsc-errors"
                    class="schema-card w-full rounded-xl border border-gray-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-900 p-4 text-start hover:bg-cstm-primary-10 transition-colors">
                    <p class="font-medium text-gray-900">Google Search Console still shows issues</p>
                    <p class="mt-1 text-sm text-gray-500">
                        I expected fewer indexing or coverage issues, but they are still there.
                    </p>
                </button>

                <button type="button" data-value="manual-search-console"
                    class="schema-card w-full rounded-xl border border-gray-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-900 p-4 text-start hover:bg-cstm-primary-10 transition-colors">
                    <p class="font-medium text-gray-900">I’m already managing Search Console manually</p>
                    <p class="mt-1 text-sm text-gray-500">
                        I prefer to submit sitemaps and request indexing directly in Google Search Console.
                    </p>
                </button>

                <button type="button" data-value="platform-handles-indexing"
                    class="schema-card w-full rounded-xl border border-gray-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-900 p-4 text-start hover:bg-cstm-primary-10 transition-colors">
                    <p class="font-medium text-gray-900">My platform already handles indexing / sitemaps</p>
                    <p class="mt-1 text-sm text-gray-500">
                        My theme or another built-in integration already connects to Google Search Console.
                    </p>
                </button>

                <button type="button" data-value="other-app"
                    class="schema-card w-full rounded-xl border border-gray-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-900 p-4 text-start hover:bg-cstm-primary-10 transition-colors">
                    <p class="font-medium text-gray-900">I switched to another SEO / indexing app</p>
                    <p class="mt-1 text-sm text-gray-500">
                        I found another solution that fits my workflow better.
                    </p>
                </button>

                <button type="button" data-value="too-complex"
                    class="schema-card w-full rounded-xl border border-gray-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-900 p-4 text-start hover:bg-cstm-primary-10 transition-colors">
                    <p class="font-medium text-gray-900">Too technical or confusing to use</p>
                    <p class="mt-1 text-sm text-gray-500">
                        I’m not sure how to use the app together with Google Search Console.
                    </p>
                </button>

                <div class="space-y-2">
                    <button type="button" data-value="other-reason"
                        class="schema-card w-full rounded-xl border border-gray-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-900 p-4 text-start hover:bg-cstm-primary-10 transition-colors">
                        <p class="font-medium text-gray-900">Something else</p>
                        <p class="mt-1 text-sm text-gray-500">
                            Another reason not listed here.
                        </p>
                    </button>
                    <textarea id="schema-leave-other-text"
                        class="mt-1 hidden w-full rounded-lg dark:bg-neutral-900 border border-gray-300 p-3 text-sm focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
                        placeholder="Please share a bit more (optional, but very helpful)..."></textarea>
                </div>
            </div>

            <p id="schema-step-3-error" class="hidden text-sm text-danger-600 dark:text-danger-600 font-medium">
                Please select a reason to continue.
            </p>

            <div class="flex justify-between gap-3">
                <button type="button"
                    class="btn-cstm-primary rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 transition-colors"
                    onclick="CancellationFlow.goToStep(2)">
                    Back
                </button>
                <button type="button"
                    class="btn-cstm-primary rounded-lg px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition-colors"
                    onclick="CancellationFlow.goToStep(4)">
                    Next
                </button>
            </div>
        </div>

        <div id="schema-cancel-step-4" class="schema-cancel-step hidden space-y-6 max-w-2xl">

            <div class="p-4 bg-warning-50 dark:bg-warning-600/10 border border-warning-300 dark:border-yellow-600 rounded-lg shadow-sm mt-6 mb-6">
                <div class="flex items-start gap-2 flex-col justify-center text-center  items-center">
                    <i class="fa-solid fa-triangle-exclamation text-3xl text-warning-600 mt-2"></i>
                    <div class="ml-3 text-left">
                        <h2 id="schema-offer-heading" class="text-xl font-semibold text-gray-900 dark:text-white">
                            Let’s make sure you don’t lose what you’ve already set up
                        </h2>
                        <p id="schema-offer-subtitle" class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Based on why you installed the app, here’s what cancelling means.
                        </p>
                    </div>
                </div>
            </div>

            <div id="schema-offer-body" class="space-y-4 text-base text-gray-700 dark:text-gray-200 leading-relaxed min-h-[100px]">
            </div>

            <div class="flex justify-between gap-3">
                <button type="button"
                    class="btn-cstm-primary rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 transition-colors"
                    onclick="CancellationFlow.goToStep(3)">
                    Back
                </button>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button"
                        class="btn-cstm-primary rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 transition-colors"
                        onclick="CancellationFlow.keepSubscription()">
                        Keep the app
                    </button>
                    <button type="button"
                        id="schema-offer-accept-btn"
                        class="btn-cstm-primary hidden rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 transition-colors"
                        onclick="CancellationFlow.acceptOffer()">
                        Yes, help me with this
                    </button>
                    <button type="button"
                        class="btn-cstm-primary rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 transition-colors"
                        onclick="CancellationFlow.goToStep(5)">
                        Next
                    </button>
                </div>
            </div>
        </div>

        <div id="schema-cancel-step-5" class="schema-cancel-step hidden space-y-6 max-w-2xl">

            <div class="p-4 bg-warning-50 dark:bg-warning-600/10 border border-warning-300 dark:border-yellow-600 rounded-lg shadow-sm mt-6 mb-6">
                <div class="flex items-start gap-2 flex-col justify-center text-center  items-center">
                    <i class="fa-solid fa-triangle-exclamation text-3xl text-warning-600 mt-2"></i>
                    <div class="ml-3 text-left">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                            Review before cancelling
                        </h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            Here’s what will happen if you cancel the Google Search Console App.
                        </p>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 dark:border-neutral-600 p-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <!-- Card 1 -->
                    <div class="flex items-start gap-4">
                        <iconify-icon icon="ph:shield-warning" class="text-red-500 text-3xl mt-1"></iconify-icon>
                        <p class="text-sm text-gray-700">
                            Automatic site verification and Search Console integration managed by this app may stop working.
                        </p>
                    </div>

                    <!-- Card 2 -->
                    <div class="flex items-start gap-4">
                        <iconify-icon icon="ph:arrows-clockwise" class="text-blue-500 text-3xl mt-1"></iconify-icon>
                        <p class="text-sm text-gray-700">
                            New or updated products, collections, blog posts, and pages will no longer automatically trigger update signals to Google.
                        </p>
                    </div>

                    <!-- Card 3 -->
                    <div class="flex items-start gap-4">
                        <iconify-icon icon="ph:list-checks" class="text-green-500 text-3xl mt-1"></iconify-icon>
                        <p class="text-sm text-gray-700">
                            You will need to manage sitemaps, URL inspection, and indexing requests manually in Google Search Console.
                        </p>
                    </div>

                    <!-- Card 4 -->
                    <div class="flex items-start gap-4">
                        <iconify-icon icon="ph:archive" class="text-purple-500 text-3xl mt-1"></iconify-icon>
                        <p class="text-sm text-gray-700">
                            Your existing Search Console property will remain, but any automation provided by this app will be removed.
                        </p>
                    </div>

                </div>
            </div>


            <div class="flex justify-between gap-3">
                <button type="button"
                    class="btn-cstm-primary rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 transition-colors"
                    onclick="CancellationFlow.goToStep(4)">
                    Back
                </button>
                <div class="flex gap-2">
                    <button type="button"
                        class="btn-cstm-primary rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 transition-colors"
                        onclick="CancellationFlow.keepSubscription()">
                        Keep the app
                    </button>
                    <button type="button"
                        class="btn-cstm-primary rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 transition-colors"
                        onclick="CancellationFlow.confirmCancel()">
                        Confirm cancellation
                    </button>
                </div>
            </div>
        </div>

        <div id="schema-cancel-step-6" class="schema-cancel-step hidden space-y-4 text-center max-w-2xl">
            <div class="bg-cstm-primary-10 drk-bg-cstm-primary-20 flex inline-flex items-center justify-center mx-auto rounded-full">
                <i class="fa-regular fa-face-frown text-[96px] text-cstm-primary p-2"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-900">
                Your Google Search Console App has been cancelled
            </h2>
            <p class="mx-auto max-w-md text-sm text-gray-600">
                You can always reinstall the app later if you decide to use automated Search Console connection and indexing again.
            </p>
            <div class="pt-4">
                <button type="button"
                    class="btn btn-cstm-primary px-4 py-2"
                    onclick="CancellationFlow.finish()">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // Lightweight frontend logic for the Google Search Console App cancellation flow.
    // Relies only on vanilla JS + Tailwind utility classes.

    (function() {
        const overlay = document.getElementById('schema-cancel-overlay');
        const steps = {
            1: document.getElementById('schema-cancel-step-1'),
            2: document.getElementById('schema-cancel-step-2'),
            3: document.getElementById('schema-cancel-step-3'),
            4: document.getElementById('schema-cancel-step-4'),
            5: document.getElementById('schema-cancel-step-5'),
            6: document.getElementById('schema-cancel-step-6'),
        };

        const instanceInput = document.getElementById('schema-cancel-instance-id');

        const joinCardsWrap = document.getElementById('schema-join-reasons');
        const joinOtherText = document.getElementById('schema-join-other-text');
        const joinError = document.getElementById('schema-step-1-error');

        const leaveCardsWrap = document.getElementById('schema-leave-reasons');
        const leaveOtherText = document.getElementById('schema-leave-other-text');
        const leaveError = document.getElementById('schema-step-3-error');

        const mirrorHeading = document.getElementById('schema-mirror-heading');
        const mirrorBody = document.getElementById('schema-mirror-body');

        const offerHeading = document.getElementById('schema-offer-heading');
        const offerSubtitle = document.getElementById('schema-offer-subtitle');
        const offerBody = document.getElementById('schema-offer-body');
        const offerAcceptBtn = document.getElementById('schema-offer-accept-btn');

        let state = {
            instanceId: instanceInput ? instanceInput.value : '',
            currentStep: 1,

            joinReason: null,
            joinExtra: '',
            mirrorShown: false,

            leaveReason: null,
            leaveExtra: '',

            offerType: null,
            offerSelected: null,
            callScheduled: false,

            reviewShown: false
        };

        function showStep(stepNumber) {
            Object.keys(steps).forEach(k => steps[k].classList.add('hidden'));
            steps[stepNumber].classList.remove('hidden');
            state.currentStep = stepNumber;

            if (stepNumber === 2) state.mirrorShown = true;
            if (stepNumber === 4 && state.offerType) state.reviewShown = true;

            saveProgress();
        }

        function openModal() {
            overlay.classList.remove('hidden');
            overlay.classList.add('flex');
            showStep(1);
        }

        function closeModal() {
            overlay.classList.add('hidden');
            overlay.classList.remove('flex');
            if (state.currentStep < 6) {
                saveProgress('abandoned');
            }
        }

        function keepSubscription() {
            saveProgress('kept_subscription');
            closeModal();
        }

        function finishFlow() {
            closeModal();
        }

        function goToStep(stepNumber) {
            if (stepNumber === 2) {
                if (!state.joinReason) {
                    joinError.classList.remove('hidden');
                    return;
                }
                joinError.classList.add('hidden');
                updateMirrorScreen();
            }

            if (stepNumber === 4) {
                if (!state.leaveReason) {
                    leaveError.textContent = 'Please select a reason to continue.';
                    leaveError.classList.remove('hidden');
                    return;
                }
                if (state.leaveReason === 'other-reason' && !leaveOtherText.value.trim()) {
                    leaveError.textContent = 'Please provide a bit more detail.';
                    leaveError.classList.remove('hidden');
                    return;
                }
                leaveError.classList.add('hidden');
                updateOfferScreen();
            }

            showStep(stepNumber);
        }

        function confirmCancel() {
            saveProgress('cancelled', function() {
                fetch('/wix/googlesearchconsole/api/cancellation-final.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            instance_id: state.instanceId,
                            final_note: state.leaveExtra
                        })
                    })
                    .then(function(res) {
                        if (!res.ok) throw new Error('Network response was not ok');
                        return res.json();
                    })
                    .then(function(json) {
                        console.log('Cancellation final response:', json);
                        showStep(6);
                    })
                    .catch(function(err) {
                        console.error('Cancellation final error:', err);
                        showStep(6);
                    });
            });
        }

        function acceptOffer() {
            state.offerSelected = 'accept_help';
            saveProgress('scheduled_call', function() {
                closeModal();
            });
        }

        function updateMirrorScreen() {
            let html = '';
            switch (state.joinReason) {
                case 'seo':
                    mirrorHeading.textContent = 'Indexing is a key part of your SEO foundation.';
                    html = `
                    <div class="first-block italic border border-gray-200 dark:border-neutral-600 dark:bg-neutral-800 rounded-lg p-6 text-center">
                        <p>
                            You installed this app to help Google discover and understand your content faster.
                            Removing automated indexing support may slow down how quickly changes are reflected in search results.
                        </p>
                    </div>
                    <div class="second-block text-center p-6 pt-0">
                        <p>
                            If results don’t match what you expected, we can review your setup and explain how indexing currently works for your store.
                        </p>
                    </div>
                `;
                    break;
                case 'fix-errors':
                    mirrorHeading.textContent = 'You wanted to connect and verify your site in Google Search Console.';
                    html = `
                        <div class="first-block italic border border-gray-200 dark:border-neutral-600 dark:bg-neutral-800 rounded-lg p-6 text-center">
                                <p>
                                    This app was added to simplify site verification and connection to Google Search Console.
                                    Cancelling means any future property setup or verification steps will have to be done manually.
                                </p>
                            </div>
                            <div class="second-block text-center p-6 pt-0">
                                <p>
                                    If you’re unsure whether verification completed correctly, we can help you confirm the status inside Search Console.
                                </p>
                            </div>
                `;
                    break;
                case 'product-indexing':
                    mirrorHeading.textContent = 'Faster indexing helps your product pages stay up to date.';
                    html = `
                     <div class="first-block italic border border-gray-200 dark:border-neutral-600 dark:bg-neutral-800 rounded-lg p-6 text-center">
                        <p>
                            The app helps inform Google when your product pages are created or updated,
                            so changes like price or availability can be discovered more quickly.
                        </p>
                    </div>
                    <div class="second-block text-center p-6 pt-0">
                        <p>
                            Cancelling means new products and updates may rely only on Google’s normal crawl schedule.
                        </p>
                    </div>
                `;
                    break;
                case 'content-indexing':
                    mirrorHeading.textContent = 'Keeping blogs and pages indexed helps customers find fresh content.';
                    html = `
                       <div class="first-block italic border border-gray-200 dark:border-neutral-600 dark:bg-neutral-800 rounded-lg p-6 text-center">
                            <p>
                                The app can assist in making sure important content like blogs, collections,
                                and landing pages are surfaced to Google more reliably.
                            </p>
                        </div>
                        <div class="second-block text-center p-6 pt-0">
                            <p>
                                Without it, you may need to manually request indexing for key URLs when you make changes.
                            </p>
                        </div>
                `;
                    break;
                case 'auto-indexing':
                    mirrorHeading.textContent = 'You chose automation so you don’t have to manage indexing manually.';
                    html = `
                    <div class="first-block italic border border-gray-200 dark:border-neutral-600 dark:bg-neutral-800 rounded-lg p-6 text-center">
                        <p>
                            Without the app, Google won’t be automatically notified when your products or content change.
                            You’ll need to rely more on manual sitemap submissions and the URL Inspection tool.
                        </p>
                    </div>
                `;
                    break;
                case 'simple-setup':
                    mirrorHeading.textContent = 'You wanted a simpler, cleaner Search Console and indexing setup.';
                    html = `
                    <div class="first-block italic border border-gray-200 dark:border-neutral-600 dark:bg-neutral-800 rounded-lg p-6 text-center">
                     <p>
                        This app centralises important tasks like verification and indexing requests.
                        Cancelling may bring you back to juggling multiple tools or purely manual workflows.
                      </p>
                    </div>
                `;
                    break;
                case 'other':
                    mirrorHeading.textContent = 'Thanks for sharing your reason.';
                    html = `
                    <div class="first-block italic border border-gray-200 dark:border-neutral-600 dark:bg-neutral-800 rounded-lg p-6 text-center">
                            <p> 
                            We really appreciate you taking the time to tell us why you installed the app.
                            If something didn’t match your expectations, we’d be happy to review your setup with you.
                        </p>
                    </div>
                `;
                    break;
            }
            mirrorBody.innerHTML = html;
        }

        function updateOfferScreen() {
            let html = '';
            let showAcceptButton = false;
            let subtitle = 'We might be able to resolve this quickly without you needing to cancel.';

            switch (state.leaveReason) {
                case 'not-working':
                    offerHeading.textContent = 'The app doesn’t seem to work? We can check it for you.';
                    html = `
                  <div class="rounded-xl border border-gray-200 dark:border-neutral-600 p-8 text-center p-4">

                    <h2 class="text-lg font-semibold mb-6">
                      We can run a quick check on your site and see
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                        <!-- Card 1 -->
                        <div class="flex flex-col items-center text-center">
                            <iconify-icon icon="solar:shield-check-outline" class="text-green-500 text-4xl mb-3"></iconify-icon>
                            <h3 class="font-semibold text-base mb-2">Domain Verification</h3>
                            <p class="text-sm text-gray-600">
                                whether your site is correctly verified in Google Search Console.
                            </p>
                        </div>

                        <!-- Card 2 -->
                        <div class="flex flex-col items-center text-center">
                            <iconify-icon icon="solar:document-text-outline" class="text-red-500 text-4xl mb-3"></iconify-icon>
                            <h3 class="font-semibold text-base mb-2">Sitemap</h3>
                            <p class="text-sm text-gray-600">
                                Whether sitemaps are submitted and accessible.
                            </p>
                        </div>

                        <!-- Card 3 -->
                        <div class="flex flex-col items-center text-center">
                            <iconify-icon icon="solar:refresh-circle-outline" class="text-blue-500 text-4xl mb-3"></iconify-icon>
                            <h3 class="font-semibold text-base mb-2">Google Account</h3>
                            <p class="text-sm text-gray-600">
                               If Google is receiving update signals from your store as expected.
                            </p>
                        </div>

                    </div>
                </div>

                `;
                    showAcceptButton = true;
                    state.offerType = 'schema_check';
                    break;

                case 'gsc-errors':
                    offerHeading.textContent = 'Still seeing issues in Google Search Console?';
                    html = `
                   <div class="rounded-xl border border-gray-200 dark:border-neutral-600 p-4 text-center">
                        <h2 class="text-lg font-semibold mb-6">
                            Not all indexing or coverage issues in Search Console are caused by the app.  
                            We can:
                        </h2>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                            <!-- Card 1 -->
                            <div class="flex flex-col items-center text-center">
                                <iconify-icon icon="material-symbols:manage-search-rounded" class="text-green-500 text-4xl mb-3"></iconify-icon>
                                <p class="text-sm text-gray-600">
                                    Review which parts of your setup depend on this app.
                                </p>
                            </div>

                            <!-- Card 2 -->
                            <div class="flex flex-col items-center text-center">
                                <iconify-icon icon="solar:danger-triangle-outline" class="text-red-500 text-4xl mb-3"></iconify-icon>
                                <p class="text-sm text-gray-600">
                                    Explain which warnings are normal and which need attention.
                                </p>
                            </div>

                            <!-- Card 3 -->
                            <div class="flex flex-col items-center text-center">
                                <iconify-icon icon="solar:compass-outline" class="text-blue-500 text-4xl mb-3"></iconify-icon>
                                <p class="text-sm text-gray-600">
                                    Suggest next steps to improve how your site is crawled and indexed.
                                </p>
                            </div>

                        </div>
                    </div>
                `;
                    showAcceptButton = true;
                    state.offerType = 'gsc_review';
                    break;

                case 'manual-search-console':
                    offerHeading.textContent = 'Already managing things manually? We can help you decide what to keep.';
                    html = `
                  <div class="rounded-xl border border-gray-200 dark:border-neutral-600 p-4 text-center">
                    <h2 class="text-lg font-semibold mb-6">
                        It’s totally fine to manage Search Console directly, but the app can still save you time.
                        We can help you:
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                        <!-- Card 1 -->
                        <div class="flex flex-col items-center text-center">
                              <iconify-icon icon="ph:clock" class="text-green-500 text-4xl mb-3"></iconify-icon>
                            <p class="text-sm text-gray-600">
                                Compare your current manual workflow with what the app automates.
                            </p>
                        </div>

                        <!-- Card 2 -->
                        <div class="flex flex-col items-center text-center">
                           <iconify-icon icon="ph:list-checks" class="text-blue-500 text-4xl mb-3"></iconify-icon>
                            <p class="text-sm text-gray-600">
                                Check if there are important tasks you might be missing.
                            </p>
                        </div>

                        <!-- Card 3 -->
                        <div class="flex flex-col items-center text-center">
                        <iconify-icon icon="ph:circles-three-plus" class="text-purple-500 text-4xl mb-3"></iconify-icon>
                            <p class="text-sm text-gray-600">
                                Decide whether a hybrid or fully automated setup works best.
                            </p>
                        </div>

                    </div>
                </div>

                `;
                    showAcceptButton = true;
                    state.offerType = 'conflict_help';
                    break;

                case 'platform-handles-indexing':
                    offerHeading.textContent = 'Your platform may cover basics, but not everything.';
                    html = `
                   <div class="rounded-xl border border-gray-200 dark:border-neutral-600 p-4 text-center">
                    <h2 class="text-lg font-semibold mb-6">
                        Some themes and platforms handle basic sitemaps or verification,
                        but they may not:
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">

                        <!-- Card 1 -->
                        <div class="flex flex-col items-center text-center">
                            <iconify-icon icon="ph:magnifying-glass" class="text-green-500 text-4xl mb-3"></iconify-icon>
                            <p class="text-sm text-gray-600">
                                Make it easy to resubmit or inspect specific URLs.
                            </p>
                        </div>

                        <!-- Card 2 -->
                        <div class="flex flex-col items-center text-center">
                            <iconify-icon icon="ph:bell-simple" class="text-blue-500 text-4xl mb-3"></iconify-icon>
                            <p class="text-sm text-gray-600">
                                Notify Google when key store content changes.
                            </p>
                        </div>

                        <!-- Card 3 -->
                        <div class="flex flex-col items-center text-center">
                            <iconify-icon icon="ph:arrow-square-out" class="text-purple-500 text-4xl mb-3"></iconify-icon>
                            <p class="text-sm text-gray-600">
                                Give you a simple flow from your store into Search Console tools.
                            </p>
                        </div>

                    </div>

                    <p class="mt-6 text-sm text-gray-700">
                        If you’d like, we can quickly review what your platform already does and whether
                        this app still adds value.
                    </p>
                </div>
                `;
                    showAcceptButton = true;
                    state.offerType = 'theme_review';
                    break;

                case 'other-app':
                    offerHeading.textContent = 'Thanks for giving us a try.';
                    subtitle = 'We’d love to know what worked better for you in the other app.';
                    html = `
                    <div class="rounded-xl border border-gray-200 dark:border-neutral-600 p-4 text-center">
                        <div class="flex flex-col items-center">
                            <iconify-icon icon="ph:chat-circle-text" class="text-blue-500 text-4xl mb-3"></iconify-icon>
                            <!-- Original Content (unchanged) -->
                            <p class="text-sm text-gray-600 max-w-md mx-auto">
                                Totally okay to switch tools - it helps us learn what to improve.
                                If you have 10 seconds, reply to any of our emails with the name of the app you chose
                                and what you liked about it.
                            </p>
                        </div>
                    </div>
                `;
                    showAcceptButton = false;
                    state.offerType = 'none';
                    break;

                case 'too-complex':
                    offerHeading.textContent = 'Search Console can be technical — we can walk you through it.';
                    html = `
                    <div class="rounded-xl border border-gray-200 dark:border-neutral-600 p-4">
                    <!-- Title -->
                    <h2 class="text-lg font-semibold text-center mb-6">If things felt confusing, we can:</h2>

                    <!-- Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

                        <!-- Card 1 -->
                        <div class="flex flex-col items-center text-center">
                            <iconify-icon icon="ph:info" class="text-blue-500 text-4xl mb-3"></iconify-icon>
                            <p class="text-sm text-gray-600">
                                Explain in simple terms what the app connects and automates.
                            </p>
                        </div>

                        <!-- Card 2 -->
                        <div class="flex flex-col items-center text-center">
                            <iconify-icon icon="ph:check-circle" class="text-green-500 text-4xl mb-3"></iconify-icon>
                            <p class="text-sm text-gray-600">
                                Help you verify your site and submit your sitemap correctly.
                            </p>
                        </div>

                        <!-- Card 3 -->
                        <div class="flex flex-col items-center text-center">
                            <iconify-icon icon="ph:globe" class="text-purple-500 text-4xl mb-3"></iconify-icon>
                            <p class="text-sm text-gray-600">
                                Show you where to see results inside Google Search Console.
                            </p>
                        </div>

                    </div>

                </div>
                `;
                    showAcceptButton = true;
                    state.offerType = 'onboarding_help';
                    break;

                case 'other-reason':
                    offerHeading.textContent = 'Thanks for the feedback.';
                    html = `
                    <div class="rounded-xl border border-gray-200 dark:border-neutral-600 p-4 text-center">
                            <div class="flex flex-col items-center">
                                <iconify-icon icon="ph:smiley" class="text-yellow-500 text-4xl mb-3"></iconify-icon>
                                <h3 class="font-semibold text-base mb-2">Thanks for the feedback.</h3>
                                <p class="text-sm text-gray-600 max-w-md mx-auto">
                                    We appreciate you sharing why you’re cancelling. We keep this feedback anonymous,
                                    but it strongly influences what we improve next.
                                </p>
                            </div>
                        </div>
                `;
                    showAcceptButton = false;
                    state.offerType = 'none';
                    break;
            }

            offerSubtitle.textContent = subtitle;
            offerBody.innerHTML = html;
            if (showAcceptButton) {
                offerAcceptBtn.classList.remove('hidden');
            } else {
                offerAcceptBtn.classList.add('hidden');
            }
        }

        function saveProgress(finalAction, callback) {
            const payload = {
                instance_id: state.instanceId,
                current_step: state.currentStep,

                join_reason: state.joinReason,
                join_extra: state.joinExtra,
                mirror_screen_shown: state.mirrorShown ? 1 : 0,

                leave_reason: state.leaveReason,
                leave_extra: state.leaveExtra,

                offer_type: state.offerType,
                offer_selected: state.offerSelected,
                call_scheduled: state.callScheduled ? 1 : 0,

                review_shown: state.reviewShown ? 1 : 0,

                final_action: finalAction || null
            };

            fetch('/wix/googlesearchconsole/api/cancellation-track.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            }).then(() => {
                if (typeof callback === "function") callback();
            });
        }

        // Handle join reason selection
        if (joinCardsWrap) {
            joinCardsWrap.addEventListener('click', function(e) {
                const btn = e.target.closest('.schema-card');
                if (!btn) return;
                const value = btn.getAttribute('data-value');
                if (!value) return;

                joinCardsWrap.querySelectorAll('.schema-card').forEach(function(el) {
                    el.classList.remove('border-cstm-primary', 'bg-cstm-primary-20', 'drk-bg-cstm-primary-10');
                });
                btn.classList.add('border-cstm-primary', 'bg-cstm-primary-20', 'drk-bg-cstm-primary-10');

                state.joinReason = value;
                joinError.classList.add('hidden');

                if (value === 'other') {
                    joinOtherText.classList.remove('hidden');
                } else {
                    joinOtherText.classList.add('hidden');
                    state.joinExtra = '';
                }
            });

            if (joinOtherText) {
                joinOtherText.addEventListener('input', function() {
                    if (state.joinReason === 'other') {
                        state.joinExtra = this.value;
                        saveProgress();
                    }
                });
            }
        }

        // Handle leave reason selection
        if (leaveCardsWrap) {
            leaveCardsWrap.addEventListener('click', function(e) {
                const btn = e.target.closest('.schema-card');
                if (!btn) return;
                const value = btn.getAttribute('data-value');
                if (!value) return;

                leaveCardsWrap.querySelectorAll('.schema-card').forEach(function(el) {
                    el.classList.remove('border-cstm-primary', 'bg-cstm-primary-10', 'drk-bg-cstm-primary-10');
                });
                btn.classList.add('border-cstm-primary', 'bg-cstm-primary-10', 'drk-bg-cstm-primary-10');

                state.leaveReason = value;
                leaveError.classList.add('hidden');

                if (value === 'other-reason') {
                    leaveOtherText.classList.remove('hidden');
                } else {
                    leaveOtherText.classList.add('hidden');
                    state.leaveExtra = '';
                }
            });

            if (leaveOtherText) {
                leaveOtherText.addEventListener('input', function() {
                    if (state.leaveReason === 'other-reason') {
                        state.leaveExtra = this.value;
                        saveProgress();
                    }
                });
            }
        }

        window.CancellationFlow = {
            open: openModal,
            close: closeModal,
            goToStep: goToStep,
            keepSubscription: keepSubscription,
            confirmCancel: confirmCancel,
            acceptOffer: acceptOffer,
            finish: finishFlow
        };
    })();
</script>