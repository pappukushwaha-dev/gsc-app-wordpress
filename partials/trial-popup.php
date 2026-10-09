<?php
// partials/trial-activate-modal.php
$instanceId = $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;
?>

<div
    id="trialActivateModal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-cstm-black-30 backdrop-blur-sm">

    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 p-6 relative animate-scaleIn">

        <!-- Close -->
        <button
            id="closeTrialModal"
            class="absolute top-3 right-4 w-9 h-9 rounded-full flex items-center justify-center text-gray-500 hover:bg-gray-100">
            ✕
        </button>

        <!-- Header -->
        <h3 class="text-2xl font-semibold text-gray-800 mb-2">
            Start Your Free Trial
        </h3>
        <p class="text-sm text-gray-500 mb-4">
            Try all premium features free for 7 days. Cancel anytime.
        </p>

        <!-- Pricing Card -->
        <div class="border rounded-xl p-4 bg-gradient-to-br from-[#487FFF]/10 to-[#A8C5FF]/20 relative">
            <span class="bg-primary-900 text-white bg-cstm-secondary font-medium bg-opacity-25
                         rounded-se-[24px] rounded-es-[24px] py-2 px-6 text-sm absolute end-0 top-0">
                Organic Booster
            </span>

            <h3 class="text-xl font-semibold text-gray-800">
                7-Day Free Trial
            </h3>

            <p class="text-sm text-gray-500 mt-1">
                Full access to all Organic Booster features.
            </p>

            <ul class="mt-4 space-y-2 text-sm text-gray-600">
                <li>✔ No credit card required</li>
                <li>✔ Unlimited premium features</li>
                <li>✔ Cancel anytime</li>
            </ul>
        </div>

        <!-- Actions -->
        <div class="flex gap-3 mt-6">
            <button
                id="cancelTrialBtn"
                class="flex-1 rounded-lg px-4 py-2 border text-gray-700 hover:bg-gray-100">
                Cancel
            </button>

            <button
                id="startTrialBtn"
                class="flex-1 rounded-lg px-4 py-2 bg-primary-600 text-white hover:opacity-90">
                Activate Free Trial
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const modal = document.getElementById("trialActivateModal");

document.addEventListener("click", (e) => {
    const trigger = e.target.closest(".openTrialModal");
    if (!trigger) return;

    e.preventDefault();
    modal.classList.remove("hidden");
    modal.classList.add("flex");
});


    document.getElementById("closeTrialModal")?.addEventListener("click", () => {
        modal.classList.add("hidden");
        modal.classList.remove("flex");
    });

    document.getElementById("cancelTrialBtn")?.addEventListener("click", () => {
        modal.classList.add("hidden");
        modal.classList.remove("flex");
    });

    modal.addEventListener("click", e => {
        if (e.target === modal) {
            modal.classList.add("hidden");
            modal.classList.remove("flex");
        }
    });

    // Activate trial API call
    document.getElementById("startTrialBtn")?.addEventListener("click", async () => {
        const btn = document.getElementById("startTrialBtn");
        btn.disabled = true;
        btn.textContent = "Activating...";

        try {
            const res = await fetch("api/start_trial.php", {
                method: "POST",
                  headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    source: "trial_modal"
                })
            });


            const data = await res.json();

            if (data.success) {
                 showAlert("You have successfully activated your free trial 🎉", "success");

            // ✅ Reload after 500ms
            setTimeout(() => {
                window.location.reload();
            }, 1000);
            } else {
                showAlert(data.message || "Unable to activate trial.");
            }
        } catch (e) {
            showAlert("Something went wrong.");
        }

        btn.disabled = false;
        btn.textContent = "Activate Free Trial";
    });
});
</script>
