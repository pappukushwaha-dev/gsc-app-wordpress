document.addEventListener("DOMContentLoaded", () => {
    const btn = document.getElementById("connectGoogleBtn");

    // ---------------------------
    // CONNECT BUTTON
    // ---------------------------
    if (btn) {
btn.addEventListener("click", async () => {

    const websiteInput = document.getElementById("websiteUrlInput");
    const website = websiteInput ? websiteInput.value.trim() : "";

    if (!website) {
        alert("Please enter website URL");
        return;
    }

    // Save website first
    await fetch(window.APP_BASE + "/api/save-website.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "website=" + encodeURIComponent(website)
    });

    btn.disabled = true;
    btn.innerHTML = `<span class="loader mr-2"></span> Connecting...`;

    try {
        const url = window.APP_BASE + "/api/google/get_auth_url.php";
        const res = await fetch(url, { method: "GET" });
        const json = await res.json();

        if (!json.success) {
            btn.disabled = false;
            btn.innerText = "Connect Your Domain";
            return;
        }

        window.location.href = json.authUrl;

    } catch (error) {
        btn.disabled = false;
        btn.innerText = "Connect Your Domain";
    }
});
    }

    // ---------------------------
    // DISCONNECT MODAL ELEMENTS
    // ---------------------------
    const disconnectBtn = document.getElementById("disconnectGoogleBtn");
    const overlay       = document.getElementById("disconnect-modal-overlay");
    const modal         = document.getElementById("disconnect-modal");
    const cancelBtn     = document.getElementById("cancelDisconnect");
    const confirmBtn    = document.getElementById("confirmDisconnect");

    if (!disconnectBtn) return; // nothing to do

    // ---------------------------
    // SHOW MODAL
    // ---------------------------
    function openModal() {
        overlay.classList.remove("hidden");
        modal.classList.remove("hidden");

        requestAnimationFrame(() => {
            overlay.classList.add("opacity-100");
            modal.classList.add("opacity-100", "scale-100");
        });
    }

    // ---------------------------
    // HIDE MODAL
    // ---------------------------
    function closeModal() {
        overlay.classList.remove("opacity-100");
        modal.classList.remove("opacity-100", "scale-100");

        setTimeout(() => {
            overlay.classList.add("hidden");
            modal.classList.add("hidden");
        }, 250);
    }

    // ---------------------------
    // DISCONNECT BUTTON → SHOW MODAL
    // ---------------------------
    disconnectBtn.addEventListener("click", (e) => {
        e.preventDefault();
        openModal();
    });

    // Cancel / Overlay click
    cancelBtn.addEventListener("click", closeModal);
    overlay.addEventListener("click", closeModal);

    // ---------------------------
    // CONFIRM DISCONNECT
    // ---------------------------
    confirmBtn.addEventListener("click", async () => {
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = `<span class="loader mr-2"></span> Disconnecting...`;

        try {
            const res = await fetch(window.APP_BASE + "/includes/google/disconnect.php", {
                method: "POST"
            });

            if (res.ok) {
                window.location.reload();
            } else {
                confirmBtn.disabled = false;
                confirmBtn.innerText = "Disconnect";
            }
        } catch (e) {
            console.error(e);
            confirmBtn.disabled = false;
            confirmBtn.innerText = "Disconnect";
        }
    });
});
