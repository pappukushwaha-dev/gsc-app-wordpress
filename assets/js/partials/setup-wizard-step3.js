document.addEventListener("DOMContentLoaded", () => {

    const formContainer = document.getElementById("sitemap-submission-form");
    const successSection = document.getElementById("sitemap-success-section");
    const btn = document.getElementById("submitSitemapBtn");
    const box = document.getElementById("sitemapStatusBox");
    const historyBox = document.getElementById("sitemapHistory");
    const confettiContainer = document.getElementById("confetti-container");

    // NEW: reconnect elements
    const reconnectWrapper = document.getElementById("reconnectGoogleWrapper");
    const reconnectBtn = document.getElementById("reconnectGoogleBtn");
    const reconnectModal = document.getElementById("reconnect-modal");
    const reconnectOverlay = document.getElementById("reconnect-modal-overlay");
    const cancelReconnectBtn = document.getElementById("cancelReconnect");
    const confirmReconnectBtn = document.getElementById("confirmReconnect");

    if (!btn) return;

    function setStatus({ type = "info", text = "", loading = false }) {

        box.classList.remove(
            "hidden",
            "border-success-200", "bg-success-100", "text-success-600",
            "border-danger-200", "bg-danger-100", "text-danger-600",
            "border-neutral-200", "bg-neutral-50", "text-neutral-700"
        );

        box.classList.add("rounded-xl", "p-4", "border");

        if (type === "success") {
            box.classList.add("border-success-200", "bg-success-100", "text-success-600");
        } else if (type === "error") {
            box.classList.add("border-danger-200", "bg-danger-100", "text-danger-600");
        } else {
            box.classList.add("border-neutral-200", "bg-neutral-50", "text-neutral-700");
        }

        box.innerHTML = loading
            ? `<span class="loader inline-block mr-2"></span> ${text}`
            : text;
    }

    // =================== Submitting overlay ===================
    //
    // Every stage below is tied to something that actually happened. A
    // submit is a single request, so "connecting" and "sending" both fall
    // inside the one in-flight window — they describe the same real phase
    // rather than inventing separate results. Nothing is marked done before
    // the event it reports.
    const submitOverlay = document.getElementById("sitemapSubmitOverlay");

    function ovSet(step, state) {
        if (!submitOverlay) return;
        const el = submitOverlay.querySelector(`[data-step="${step}"]`);
        if (!el) return;
        el.classList.remove("is-active", "is-done");
        if (state) el.classList.add(state);
    }

    function ovOpen() {
        if (!submitOverlay) return;
        ["validate", "connect", "send", "process"].forEach(k => ovSet(k, null));
        submitOverlay.classList.remove("hidden");
    }

    function ovClose() {
        if (submitOverlay) submitOverlay.classList.add("hidden");
    }

    // =================== Checking state ===================
    //
    // Shown while we ask Google whether a sitemap is already submitted.
    // Neither real section renders until that answer arrives, so the submit
    // button cannot be pressed against a property that already has one.
    const checkState = document.getElementById("sitemapCheckState");

    function ckSet(step, state) {
        if (!checkState) return;
        const el = checkState.querySelector(`[data-check="${step}"]`);
        if (!el) return;
        el.classList.remove("is-active", "is-done");
        if (state) el.classList.add(state);
    }

    function ckHide() {
        if (checkState) checkState.classList.add("hidden");
    }

    /** Reveal the form. Used when there is no sitemap yet, and as the
     *  fallback whenever the check cannot give a clear answer. */
    function showFormUI() {
        ckHide();
        if (successSection) successSection.classList.add("hidden");
        if (formContainer) formContainer.classList.remove("hidden");
    }

    async function postJson(url, data) {
        const res = await fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(data)
        });

        const t = await res.text();
        try { return JSON.parse(t); }
        catch { return { success: false, error: "Invalid JSON" }; }
    }

    async function loadHistory() {
        if (!historyBox) return;

        const res = await fetch(`${window.APP_BASE}/api/google/fetch_sitemaps.php?instanceId=${encodeURIComponent(btn.dataset.instance)}`);
        const t = await res.text();

        let json;
        try { json = JSON.parse(t); } catch (e) { historyBox.textContent = "Error loading history."; return; }

        if (!json.success) {
            historyBox.textContent = "No history available.";
            return;
        }

        if (json.logs.length === 0) {
            historyBox.textContent = "No submissions yet.";
            return;
        }

        let html = `<ul class="space-y-3">`;

        json.logs.forEach(log => {
            const statusColor = log.status === 'SUCCESS'
                ? 'text-green-600'
                : (log.status === 'ERROR' ? 'text-red-600' : 'text-neutral-600');

            const statusIcon = log.status === 'SUCCESS'
                ? '✅'
                : (log.status === 'ERROR' ? '❌' : 'ℹ️');

            html += `
                <li class="border p-3 rounded-xl bg-neutral-50 dark:bg-neutral-700/40">
                    <div><strong>${log.sitemap_url}</strong></div>
                    <div class="${statusColor}">Status: ${statusIcon} ${log.status}</div>
                    <div>HTTP: ${log.http_code}</div>
                    <div class="text-xs">At: ${log.submitted_at}</div>
                </li>
            `;
        });

        html += `</ul>`;
        historyBox.innerHTML = html;
    }

    // Build and show 24h trial popup (appended to body so it always appears on top)
    function showTrialPopupThenReview() {
        const overlay = document.createElement("div");
        overlay.id = "trialUpgradePopupStep3Js";
        overlay.setAttribute("role", "dialog");
        overlay.setAttribute("aria-modal", "true");
        overlay.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,0.4);backdrop-filter:blur(4px);z-index:2147483647;display:flex;align-items:center;justify-content:center;padding:1rem;pointer-events:auto;";
        overlay.innerHTML = `
            <div class="bg-white relative w-full max-w-2xl rounded-2xl border border-sky-200 dark:border-sky-800 bg-gradient-to-br from-sky-50 to-sky-100 dark:from-sky-900/40 dark:to-sky-800/30 dark:bg-neutral-800 p-6 shadow-xl" id="trialPopupContentStep3Js">
    <button type="button" id="closeTrialPopupStep3Js"
        class="absolute top-3 right-2 w-10 h-10 flex items-center justify-center rounded-full text-sky-600 dark:text-sky-400 hover:bg-sky-200/50 dark:hover:bg-sky-800/50"
        aria-label="Close">✕</button>
    <div class="flex items-center justify-center gap-3 mb-5">
                <h3 class="text-xl font-bold text-sky-900 dark:text-white">
                    24-Hour Preview Access
                </h3>
                <span
                    class="inline-flex items-center px-3 py-1
               rounded-full text-xs font-semibold
               bg-success-600 text-white uppercase">
                    Trial
                </span>
            </div>
    <p class="text-base text-sky-900 dark:text-sky-300 mb-4 text-center mb-4 max-w-max rounded-[24px] px-6 py-3 bg-success-100 mx-auto">
        Your Google Search Console is connected and your sitemap is live 🚀
    </p>
    <div class="flex justify-center items-center gap-4 mb-2">
        <span id="trialCountdownHoursStep3Js" class="text-[40px] font-semibold text-sky-700 dark:text-white text-cstm-primary ">--</span>
        <span class="text-3xl font-semibold text-sky-400">:</span>
        <span id="trialCountdownMinsStep3Js" class="text-[40px] font-semibold text-sky-700 dark:text-white text-cstm-primary">--</span>
    </div>
    <p class="text-center text-sm font-semibold text-neutral-600 dark:text-neutral-400 uppercase tracking-wider mb-4">
        remaining
    </p>
    <p class="text-sm text-sky-900 dark:text-sky-300 mb-4 text-center">
        You can explore SEO insights data and app features for the next <strong>24 hours.</strong><br> <strong>Start a
            free 7-day trial</strong> Now to keep your insights active and continue using the app.
    </p>
    <div class="flex justify-center items-center">
        <a href="pricing.php?upgrade=1" class="btn bg-success-600 text-white">Start 7 day free trial</a>
    </div>
</div>
        `;
        function closeAndOpenReview() {
            if (countdownTimer) clearInterval(countdownTimer);
            overlay.remove();
            window.dispatchEvent(new CustomEvent("trialPreviewPopupClosed"));
        }
        var closeBtn = overlay.querySelector("#closeTrialPopupStep3Js");
        var content = overlay.querySelector("#trialPopupContentStep3Js");
        var elHours = overlay.querySelector("#trialCountdownHoursStep3Js");
        var elMins = overlay.querySelector("#trialCountdownMinsStep3Js");
        var countdownTimer = null;

        function updateCountdown() {
            const expiresAt = window.TRIAL_EXPIRES_AT_STEP3;
            if (!expiresAt) {
                elHours.textContent = "--";
                elMins.textContent = "--";
                return;
            }

            const now = new Date();
            const end = new Date(expiresAt);

            if (end <= now) {
                elHours.textContent = "0H";
                elMins.textContent = "Expired";
                clearInterval(countdownTimer);
                return;
            }

            const diffMs = end - now;
            const totalMinutes = Math.floor(diffMs / (1000 * 60));
            const hours = Math.floor(totalMinutes / 60);
            const mins = totalMinutes % 60;

            elHours.textContent = hours + "H";
            elMins.textContent = (mins < 10 ? "0" : "") + mins + " MIN";
        }

        updateCountdown();
        countdownTimer = setInterval(updateCountdown, 60000); // every minute

        if (content) content.addEventListener("click", function (e) { e.stopPropagation(); });
        if (closeBtn) closeBtn.addEventListener("click", closeAndOpenReview);
        overlay.addEventListener("click", function (e) {
            if (e.target === overlay) closeAndOpenReview();
        });
        document.body.appendChild(overlay);
    }

    // Function to show the success state
    function showSuccessUI() {
        // The server may have opened straight onto this screen because the
        // database already had a sitemap. Re-running the reveal there would
        // replay the animation and fire the confetti on every revisit.
        const alreadyShown = successSection && !successSection.classList.contains('hidden');

        ckHide();
        if (formContainer) formContainer.classList.add('hidden');
        if (successSection) {
            successSection.classList.remove('hidden');
            setTimeout(() => successSection.classList.add("show"), 50);
        }

        if (alreadyShown) return;
        // Show 24h trial popup FIRST and immediately (so nothing else can appear on top)
        // showTrialPopupThenReview();

        if (confettiContainer) {
            for (let i = 0; i < 25; i++) {
                const conf = document.createElement("div");
                conf.classList.add("confetti");
                conf.style.left = `${Math.random() * 100}%`;
                conf.style.background = ["#22c55e", "#16a34a", "#86efac", "#15803d"][Math.floor(Math.random() * 4)];
                conf.style.animationDelay = `${Math.random() * 2}s`;
                confettiContainer.appendChild(conf);
            }
        }
    }

    // =================== Reconnect Modal Helpers ===================
    function openReconnectModal() {
        if (!reconnectModal || !reconnectOverlay) return;
        reconnectModal.classList.remove("hidden");
        reconnectOverlay.classList.remove("hidden");

        requestAnimationFrame(() => {
            reconnectModal.classList.remove("opacity-0", "scale-95");
            reconnectOverlay.classList.remove("opacity-0");
        });
    }

    function closeReconnectModal() {
        if (!reconnectModal || !reconnectOverlay) return;
        reconnectModal.classList.add("opacity-0", "scale-95");
        reconnectOverlay.classList.add("opacity-0");
        setTimeout(() => {
            reconnectModal.classList.add("hidden");
            reconnectOverlay.classList.add("hidden");
        }, 200);
    }

    if (reconnectBtn) {
        reconnectBtn.addEventListener("click", (e) => {
            e.preventDefault();
            openReconnectModal();
        });
    }
    if (cancelReconnectBtn) {
        cancelReconnectBtn.addEventListener("click", (e) => {
            e.preventDefault();
            closeReconnectModal();
        });
    }
    if (reconnectOverlay) {
        reconnectOverlay.addEventListener("click", closeReconnectModal);
    }
    if (confirmReconnectBtn) {
        confirmReconnectBtn.addEventListener("click", (e) => {
            e.preventDefault();
            // Redirect to your Google OAuth connect endpoint
            window.location.href = "/wix/googlesearchconsole/setup-wizard.php?step=1";
        });
    }

    // When user closes the trial popup (after sitemap success), show review popup after a short delay
    window.addEventListener("trialPreviewPopupClosed", () => {
        setTimeout(() => {
            if (typeof window.openReviewModal === "function") {
                window.openReviewModal();
            }
        }, 150);
    });

    // Load history on page load
    loadHistory();

    btn.addEventListener("click", async () => {
        const instanceId = btn.dataset.instance;
        const sitemapInput = document.getElementById("sitemapInput");
        const sitemapUrl = sitemapInput ? sitemapInput.value.trim() : "";

        if (!sitemapUrl) return;

        btn.disabled = true;
        setStatus({ type: "info", text: "Submitting sitemap...", loading: true });

        ovOpen();
        ovSet("validate", "is-active");

        // client-side check — this one really is done before anything is sent
        ovSet("validate", "is-done");
        ovSet("connect", "is-active");

        // the request is now in flight; "sending" covers the same window,
        // so it is marked shortly after rather than waiting on an event the
        // browser cannot observe
        const sendMark = setTimeout(() => {
            ovSet("connect", "is-done");
            ovSet("send", "is-active");
        }, 450);

        const res = await postJson(`${window.APP_BASE}/api/google/submit_sitemap.php`, {
            instanceId,
            sitemapUrl,
            debugListSites: true
        });

        // the response has arrived — everything up to this point genuinely happened
        clearTimeout(sendMark);
        ovSet("connect", "is-done");
        ovSet("send", "is-done");
        ovSet("process", "is-active");

        console.log("submit_sitemap response:", res);

        if (!res.success) {
            ovClose();
            let msg;

            if (res.error === 'insufficient_permission') {
                msg = `
                    Your Google account does not have enough permission for
                    <strong>${res.resolvedSite || 'this property'}</strong>.<br>
                    Please verify this site in Google Search Console or connect the correct Google account.
                `;
            } else if (res.error === 'account_needs_reconnect') {
                msg = `
                    Your Google connection has expired or been revoked.<br>
                    Please reconnect your Google account and make sure you grant all requested permissions.
                `;
            } else if (res.message) {
                msg = res.message;
            } else if (res.error) {
                msg = res.error;
            } else {
                msg = "Submission failed.";
            }

            if (res.http) {
                msg += ` (HTTP ${res.http})`;
            }

            setStatus({ type: "error", text: msg });

            // SHOW RECONNECT GOOGLE SECTION FOR AUTH / PERMISSION ERRORS
            if (
                reconnectWrapper &&
                (
                    res.error === 'account_needs_reconnect' ||
                    res.error === 'insufficient_permission' ||
                    res.error === 'forbidden' ||
                    res.http === 401 ||
                    res.http === 403
                )
            ) {
                reconnectWrapper.classList.remove("hidden");
            }

            btn.disabled = false;
            return;
        }

        ovSet("process", "is-done");
        setTimeout(ovClose, 400);

        setStatus({ type: "success", text: "Sitemap submitted successfully!" });
        loadHistory();
        

        const fd = new FormData();
        fd.append('step', '3');
        fetch('/wix/googlesearchconsole/api/update-step.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).catch(() => { });

        setTimeout(showSuccessUI, 1500);
        btn.disabled = false;
    });

    // const finishBtn = document.getElementById("finishBtn");
    // if (finishBtn) {
    //     finishBtn.setAttribute("href", "javascript:void(0);");

    //     finishBtn.addEventListener("click", (e) => {
    //         e.preventDefault();
    //         let openReviewBtn = document.getElementById("openReviewModal");
    //         console.log(openReviewBtn)
    //         if (!openReviewBtn) {
    //             openReviewBtn = document.createElement("button");
    //             openReviewBtn.id = "openReviewModal";
    //             document.body.appendChild(openReviewBtn);
    //         }
    //         openReviewBtn.setAttribute("data-redirect-after", "true");

    //         if (typeof window.openReviewModalGlobal === "function") {
    //             window.openReviewModalGlobal();
    //         }
    //     });
    // }


    // ---------- Auto-check: is a sitemap already submitted? ----------
    //
    // This runs before either section is shown. Each step below is tied to
    // something that really happens: the request going out, the answer
    // coming back, and the decision being made. Nothing is ticked ahead of
    // the event it reports.
    (async () => {
        const sitemapInput = document.getElementById("sitemapInput");
        const sitemapUrl = sitemapInput ? sitemapInput.value.trim() : "";

        ckSet("connect", "is-active");

        // the request is in flight; "looking" covers the same window, since
        // the browser cannot see the server's own lookup separately
        const lookupMark = setTimeout(() => {
            ckSet("connect", "is-done");
            ckSet("lookup", "is-active");
        }, 400);

        try {
            const res = await fetch(
                `${window.APP_BASE}/api/google/check_sitemap_status.php?instanceId=${encodeURIComponent(btn.dataset.instance)}&sitemapUrl=${encodeURIComponent(sitemapUrl)}`,
                { credentials: "same-origin" }
            );
            const data = await res.json();

            clearTimeout(lookupMark);
            ckSet("connect", "is-done");
            ckSet("lookup", "is-done");
            ckSet("ready", "is-active");

            if (data.success && data.submitted) {
                // Already on the property — there is nothing to submit, so the
                // form is never shown and the button is never reachable.
                setStatus({
                    type: "success",
                    text: `Sitemap already submitted to Google Search Console ✅
                           ${data.matched?.lastSubmitted ? '<br><span class="text-xs">Last submitted: ' + data.matched.lastSubmitted + '</span>' : ''}`
                });

                const fd = new FormData();
                fd.append('step', '3');
                fetch(`${window.APP_BASE}/api/update-step.php`, {
                    method: 'POST', body: fd, credentials: 'same-origin'
                }).catch(() => { });

                ckSet("ready", "is-done");
                setTimeout(showSuccessUI, 500);
                return;
            }

            // No sitemap on the property yet — show the form.
            ckSet("ready", "is-done");
            setTimeout(showFormUI, 350);

        } catch (e) {
            console.error("Sitemap status check failed:", e);

            // The check could not answer. Showing the form is the safe
            // fallback: at worst the user submits a sitemap that is already
            // there, which Google treats as a refresh. Leaving them on a
            // spinner with no way forward would be worse.
            clearTimeout(lookupMark);
            showFormUI();
        }
    })();


});