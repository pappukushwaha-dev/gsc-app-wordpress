document.addEventListener("DOMContentLoaded", () => {
  const verifyBtn = document.getElementById("verifyDomainBtn");
if (!verifyBtn) return;

let metaToken = verifyBtn.dataset.metatoken || null;

const generateWrapper = document.getElementById('generateTokenWrapper');
const verifyWrapper   = document.getElementById('verifyWrapper');
const propertyNotFoundContent = document.getElementById('propertyNotFoundContent');
const propertyFoundContent    = document.getElementById('propertyFoundContent');
const metaTagContainer        = document.getElementById('metaTagContainer');
const propertyNotFoundImg = document.getElementById('propertyNotFoundImg');
const propertyFoundImg    = document.getElementById('propertyFoundImg');
const verificationImg     = document.getElementById('verificationImg');
const propertyCard = document.getElementById("propertyCard");
const verificationCard = document.getElementById("verificationCard");
const methodCard = document.getElementById("methodCard");
const verifiedMethodIcon = document.getElementById("verifiedMethodIcon");




  const statusBox = document.getElementById("verifyStatusBox");
const verificationMessages = [
    "Connecting to Google Search Console…",
    "Submitting verification request…",
    "Waiting for Google response…",
    "Checking verification status…",
    "Still working on verification…",
    "Google is processing your request…",
    "Almost there, please wait…",
    "Re-checking domain ownership…",
    "Confirming meta-tag presence…",
    "Finalizing verification…",
    "One last check with Google…",
    "Completing verification process…"
  ];
  function setStatus({ type = "info", text = "", loading = false }) {
    if (!statusBox) return;

    statusBox.classList.remove(
      "hidden",
      "border-success-200",
      "border-danger-200",
      "bg-success-50",
      "bg-danger-50",
      "text-success-700",
      "text-danger-700",
      "bg-neutral-50",
      "text-neutral-700"
    );
    statusBox.classList.add(
      "block",
      "rounded-xl",
      "p-4",
      "text-sm",
      "font-medium",
      "border"
    );

    if (type === "success") {
      statusBox.classList.add("border-success-200", "bg-success-50", "text-success-700");
    } else if (type === "error") {
      statusBox.classList.add("border-danger-200", "bg-danger-50", "text-danger-700");
    } else {
      statusBox.classList.add("border-neutral-200", "bg-neutral-50", "text-neutral-700");
    }

    statusBox.innerHTML = loading
      ? `<div class="full-screen-loader flex items-center gap-4 justify-center text-lg flex-col"><span class="spinner inline-block"></span> ${text}</div>`
      : text;
  }





const propertyMissingBox = document.getElementById('propertyMissingBox');
const createPropertyBtn = document.getElementById('createPropertyBtn');

// active/inactive images - start
function showStepImage(step) {
    propertyNotFoundImg?.classList.add('hidden');
    propertyFoundImg?.classList.add('hidden');
    verificationImg?.classList.add('hidden');

    switch (step) {

        case 1:
            propertyNotFoundImg?.classList.remove('hidden');
            break;

        case 2:
            propertyFoundImg?.classList.remove('hidden');
            break;

        case 3:
            verificationImg?.classList.remove('hidden');
            break;
    }
}
showStepImage(1);
// active/inactive images - end


// active/inactive success icon - start

function setVerificationIcon(isVerified) {
    if (!verifiedMethodIcon) return;
    if (isVerified) {
        verifiedMethodIcon.classList.remove("text-neutral-400");
        verifiedMethodIcon.classList.add("text-success-600");
    } else {
        verifiedMethodIcon.classList.remove("text-success-600");
        verifiedMethodIcon.classList.add("text-neutral-400");
    }

}
setVerificationIcon(false);
// active/inactive success icon - end




// active/inactive card highlight - start
function updateWizardCards(step) {

    // Reset all cards
    propertyCard?.classList.remove("active");
    verificationCard?.classList.remove("active");
    methodCard?.classList.remove("active");

    if (step >= 1) {
        propertyCard?.classList.add("active");
    }

    if (step >= 2) {
        verificationCard?.classList.add("active");
    }

    if (step >= 3) {
        methodCard?.classList.add("active");
    }

}
updateWizardCards(1);
// active/inactive card highlight - end 




async function checkProperty() {
  if (!verifyBtn) return;

  const instanceId = verifyBtn.dataset.instance;
  const domain     = verifyBtn.dataset.domain;

  if (!instanceId || !domain) return;

  setStatus({ type: "info", text: "Checking Search Console property…", loading: true });

  let json;
  try {
    const res = await fetch(
      `${window.APP_BASE}/api/google/check_property.php?instanceId=${encodeURIComponent(instanceId)}&domain=${encodeURIComponent(domain)}`,
      { credentials: 'same-origin' }
    );
    json = await res.json();
  } catch {
    setStatus({ type: "error", text: "Failed to contact Google Search Console." });
    return;
  }

  if (!json.success) {
    setStatus({ type: "error", text: json.error || "Property check failed." });
    return;
  }
  console.log("checkProperty response:", json);
  // ❌ Property missing
  if (!json.exists) {
    propertyMissingBox?.classList.remove('hidden');
    verifyBtn.disabled = true;
    setStatus({ type: "info", text: "Property not found. Please create it to continue." });
    return;
  }

  // ⚠ Exists but not verified
  propertyMissingBox?.classList.add('hidden');
propertyNotFoundContent?.classList.add('hidden');

propertyFoundContent?.classList.remove('hidden');

generateWrapper?.classList.remove('hidden');
showStepImage(2);
updateWizardCards(2);
// Property exists
verifyBtn.disabled = false;

// Only show property message IF no token exists
if (!metaToken) {
  setStatus({ type: "info", text: "Property found. Generate verification token to continue." });
} else {
  statusBox.classList.add('hidden'); // Do not show anything
}
}

checkProperty();



createPropertyBtn?.addEventListener('click', async () => {
  const instanceId = verifyBtn.dataset.instance;
  const domain     = verifyBtn.dataset.domain;

  createPropertyBtn.disabled = true;
  createPropertyBtn.innerHTML = `<span class="loader mr-2"></span> Creating property…`;

  const res = await postJson(
    `${window.APP_BASE}/api/google/create_property.php`,
    { instanceId, domain }
  );


  if (!res.success) {
    setStatus({ type: "error", text: res.error || "Failed to create property." });
    createPropertyBtn.disabled = false;
    createPropertyBtn.innerText = "Create Search Console Property";
    return;
  }

  propertyNotFoundContent?.classList.add('hidden');
  propertyFoundContent?.classList.remove('hidden');
  generateWrapper?.classList.remove('hidden');
  showStepImage(2);
  updateWizardCards(2);

  propertyMissingBox?.classList.add("hidden");
  setStatus({ type: "success", text: "Property created. Please verify ownership." });

  // Re-check property after creation
  setTimeout(checkProperty, 2000);
});





  async function postJson(url, data) {
    const res = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json; charset=utf-8" },
      body: JSON.stringify(data),
      credentials: "same-origin"
    });
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch (e) {
      return { success: false, error: "Invalid server response" };
    }
  }

  // ---------- AUTO VERIFY FLOW (only if verify button exists) ----------
  if (verifyBtn) {
    const instanceId = verifyBtn.dataset.instance;
    const domain = verifyBtn.dataset.domain;



    // Poll verification status (calls backend endpoint that checks verification status using Google API/db)
    async function pollVerification(attempts = 12, intervalMs = 5000) {
      let i = 0;
      setStatus({ type: "info", text: "Waiting for Google verification. This may take a few seconds...", loading: true });

      while (i < attempts) {
        await new Promise(r => setTimeout(r, intervalMs));
        i++;

        try {
          const checkRes = await fetch(
            `${window.APP_BASE}/api/google/check_verification.php?instanceId=${encodeURIComponent(instanceId)}`,
            { method: "GET", credentials: "same-origin" }
          );
          const json = await (async () => {
            const txt = await checkRes.text();
            try { return JSON.parse(txt); } catch (e) { return { success: false, error: "Invalid JSON" }; }
          })();

          if (json.success && json.verified === true) {
            setStatus({ type: "success", text: "✔ Domain verified by Google!" });

            // Save Step 2 silently, then go to step 3
            setTimeout(() => {
              const fd = new FormData();
              fd.append('step', '2');
              fetch(`${window.APP_BASE}/api/update-step.php`, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
              })
                .catch(() => {
                  // ignore any error silently
                })
                .finally(() => {
                  window.location.href = 'setup-wizard.php?step=3';
                });
            }, 1200);

            return true;
          } else {
          setStatus({
            type: "info",
            text: verificationMessages[i - 1] || "Verifying your domain…",
            loading: true
          });
          }
        } catch (err) {
          console.error("poll error", err);
          setStatus({ type: "error", text: "Error checking verification status. Retrying..." });
        }
      }

      // If we reach here, polling exhausted
      setStatus({
        type: "error",
        text: "Verification timed out. Please make sure the verification token is added in your Ecwid Control Panel (Settings → General → SEO → Site Verification) and try again."
      });
      if (typeof openPopup === "function") openPopup();
      return false;
    }



const generateBtns = document.querySelectorAll('#generateTokenBtn, #generateTokenBtnTwo');

generateBtns.forEach(generateBtn => {
    generateBtn.addEventListener('click', async () => {
  generateBtn.disabled = true;
  setStatus({ type: "info", text: "Requesting token from Google…", loading: true });

  const res = await postJson(
    `${window.APP_BASE}/api/google/generate_meta.php`,
    {
      instanceId: verifyBtn.dataset.instance,
      domain: verifyBtn.dataset.domain
    }
  );

  if (!res.success) {
    setStatus({ type: "error", text: "Failed to generate verification token." });
    generateBtn.disabled = false;
    return;
  }

  // Show token
metaToken = res.metaToken;

propertyFoundContent?.classList.add('hidden');
metaTagContainer?.classList.remove('hidden');
generateWrapper?.classList.add('hidden');
verifyWrapper?.classList.remove('hidden');
showStepImage(3);
updateWizardCards(3);

// Fill textarea
const textarea = document.querySelector('#metaTagContainer textarea');
textarea.value = `<meta name="google-site-verification" content="${res.metaToken}">`;


// Sync token with verify button
verifyBtn.dataset.metatoken = res.metaToken;

// UI updates
setStatus({ type: "success", text: "Meta token generated. You can now verify." });

// Update UI state after token generation
updateVerificationUI(metaToken);


});
});


verifyBtn.addEventListener("click", async (e) => {
  e.preventDefault();

  if (!metaToken) {
    setStatus({ type: "error", text: "Please generate verification token first." });
    return;
  }

  verifyBtn.disabled = true;
  verifyBtn.innerHTML = `<span class="loader mr-2"></span> Verifying domain...`;

  setStatus({
    type: "info",
    text: "Requesting verification from Google Search Console...",
    loading: true
  });

  // 🔥 Directly call Google verification API (NO injection)
  const res = await postJson(
    `${window.APP_BASE}/api/google/verify_domain.php`,
    {
      instanceId: verifyBtn.dataset.instance,
      domain: verifyBtn.dataset.domain
    }
  );

  if (!res || !res.success) {
    setStatus({
      type: "error",
      text: res && res.error ? res.error : "Failed to initiate verification."
    });

    // Auto-open the instructions popup so the user knows the next step,
    // except when the failure is a network/auth issue (instructions won't help).
    const code = res && res.error_code;
    const popupHelpful = !code || (code !== "network_error" && code !== "google_auth_error");
    if (popupHelpful && typeof openPopup === "function") openPopup();

    verifyBtn.disabled = false;
    verifyBtn.innerText = "Verify Domain";
    return;
  }

  // Start polling Google verification status
  const ok = await pollVerification(12, 5000);

  if (!ok) {
    setStatus({
      type: "error",
      text: `Verification not completed yet. Make sure the verification token is added to your Ecwid site, then try again.`
    });
    if (typeof openPopup === "function") openPopup();

    verifyBtn.disabled = false;
    verifyBtn.innerText = "Try Verification Again";
  }
});
  }

  // Helper: escape for safe HTML display
  function escapeHtml(str) {
    if (!str) return "";
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  // ---------- Handle "Continue →" when domain is already verified ----------
  // const continueStep2Btn = document.getElementById('continueStep2Btn');
  // if (continueStep2Btn) {
  //   continueStep2Btn.addEventListener('click', function (e) {
  //     e.preventDefault();
  //     console.log("click in")

  //     continueStep2Btn.disabled = true;
  //     continueStep2Btn.classList.add('opacity-70', 'cursor-wait');

  //     const fd = new FormData();
  //     fd.append('step', '2');

  //     fetch(`${window.APP_BASE}/api/update-step.php`, {
  //       method: 'POST',
  //       body: fd,
  //       credentials: 'same-origin'
  //     })
  //       .catch(() => {
  //         // ignore any error silently
  //         console.log("hit api error")
  //       })
  //       .finally(() => {
  //         console.log("hit api su")
  //         window.location.href = 'setup-wizard.php?step=3';

  //       });
  //         console.log("click end")
  //   });
  // }

});

  /* ------------------------------------------------------------
     CONNECT GOOGLE ACCOUNT
     Shown on step 2 when no Google account is on file for this
     instance. Fetches the OAuth URL from the panel and jumps to
     Googles consent screen. The callback returns to the wizard.
  ------------------------------------------------------------ */
  const connectBtn = document.getElementById("connectGoogleBtn");
  if (connectBtn) {
    connectBtn.addEventListener("click", async () => {
      const label = document.getElementById("connectGoogleBtnText");
      connectBtn.disabled = true;
      if (label) label.textContent = "Connecting...";

      try {
        const res = await fetch(connectBtn.dataset.authUrl, {
          headers: { "Accept": "application/json" }
        });
        const data = await res.json();

        if (data.success && data.authUrl) {
          window.location.href = data.authUrl;
          return;
        }
        throw new Error(data.error || "Could not start the Google connection");
      } catch (err) {
        alert("Could not connect: " + err.message);
        connectBtn.disabled = false;
        if (label) label.textContent = "Connect Google Account";
      }
    });
  }
