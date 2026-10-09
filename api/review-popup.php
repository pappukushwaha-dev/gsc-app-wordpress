<?php
$instanceId = $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;

// Pass appId to JavaScript
echo "<script>var appId = '" . $appId . "';</script>";

?>


<!-- Review Modal -->
<div id="reviewModal"
    class="fixed inset-0 flex bg-cstm-black-40 backdrop-blur-sm items-center justify-center z-99 shadow-lg hidden">

    <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-2xl py-8 px-10 w-full max-w-2xl border-b-6 border-cstm-primary relative">

        <!-- Alert Message -->
        <div id="alertContainer" class="mb-4 transition-all duration-300"></div>

        <!-- Close Button -->
        <button id="closeReviewModal"
            class="absolute right-4 top-4 text-gray-500 hover:text-gray-700 hidden">
            ✕
        </button>

        <h2 class="text-xl font-bold text-center text-neutral-800 mb-4">
            How likely are you to recommend Website Speedy for performance benefits?
        </h2>

        <!-- Ratings -->
        <div class="mb-2">
            <div id="ratingButtons" class="flex justify-between"></div>
        </div>

        <p class="text-sm text-center text-neutral-400 mb-4 flex justify-between font-medium">
            <span>Not at all likely</span>
            <i class="fa-solid fa-arrow-right-long"></i>
            <span>Extremely likely</span>
        </p>

        <!-- Comment box (only for <=7) -->
        <div id="commentBox" class="hidden mb-4">
            <label class="text-sm font-semibold text-neutral-700 dark:text-gray-400">
                Please share your comments:
            </label>

            <textarea id="reviewComment"
                class="w-full py-1 px-2 border border-gray-300 dark:border-neutral-600 rounded-lg transition duration-150 dark:bg-neutral-800"
                rows="3"
                placeholder="Tell us what we can improve..."></textarea>
        </div>

        <div class="flex justify-end gap-4 pt-6 border-t border-gray-100 dark:border-neutral-600">
            <button id="reviewRemindLater"
                class="btn btn-cstm-muted">
                Remind Me Later
            </button>

            <button id="reviewSubmit"
                class="btn btn-cstm-primary">
                Submit Feedback
            </button>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", () => {

        const modal = document.getElementById("reviewModal");
        const commentBox = document.getElementById("commentBox");
        const submitBtn = document.getElementById("reviewSubmit");
        const openBtn = document.getElementById("openReviewModal");
        const closeBtn = document.getElementById("closeReviewModal");
        const remindBtn = document.getElementById("reviewRemindLater");
        const alertContainer = document.getElementById("alertContainer");

        let selectedRating = null;

        // ========= Highlight Saved Rating When Already Submitted =========
        function highlightSubmittedRating(rating) {
            document.querySelectorAll(".review-numbers").forEach(btn => {
                if (Number(btn.dataset.value) === Number(rating)) {
                    btn.classList.add("review-numbers-selected");
                } else {
                    btn.classList.remove("review-numbers-selected");
                }
            });
        }

        // ========= FUNCTION: Already Submitted UI =========
        function applyAlreadySubmittedState() {
            alertContainer.innerHTML = `
            <div class="bg-success-100 text-success-700 border-l-4 border-success-600 p-3 rounded-md text-sm font-medium mb-4">
                <i class="fa-solid fa-check-circle"></i> You already submitted your review.
            </div>
        `;

            // disable rating buttons
            document.querySelectorAll(".review-numbers").forEach(btn => {
                btn.disabled = true;
                btn.classList.add("opacity-40", "cursor-not-allowed");
            });

            // disable comment box
            document.getElementById("reviewComment").disabled = true;

            // disable submit button
            submitBtn.textContent = "Already Submitted";
            submitBtn.disabled = true;
            submitBtn.classList.add("opacity-50", "cursor-not-allowed");

            // 🔥 Hide Remind Me Later
            remindBtn.classList.add("hidden");

            // 🔥 Show Close button
            closeBtn.classList.remove("hidden");
        }

        // ========= Inject Rating Buttons =========
        const ratingContainer = document.getElementById("ratingButtons");

        for (let i = 1; i <= 10; i++) {
            const btn = document.createElement("button");

            btn.textContent = i;
            btn.dataset.value = i;
            btn.className =
                `review-numbers w-10 h-10 flex items-center justify-center rounded-full text-white font-bold cursor-pointer transition bg-c${i}`;

            btn.addEventListener("click", () => {
                if (btn.disabled) return;

                selectedRating = i;

                [...ratingContainer.children].forEach(b =>
                    b.classList.remove("review-numbers-selected")
                );

                btn.classList.add("review-numbers-selected");

                commentBox.classList.toggle("hidden", i >= 8);
            });

            ratingContainer.appendChild(btn);
        }

        // ========= Open Modal With Check =========
        openBtn?.addEventListener("click", async () => {
            modal.classList.remove("hidden");
            modal.classList.add("flex");

            const res = await fetch("api/check-review.php?instance_id=<?php echo $instanceId; ?>");
            const data = await res.json();

            if (data.success && data.submitted) {
                applyAlreadySubmittedState();

                if (data.rating) {
                    highlightSubmittedRating(data.rating);
                }

            } else {
                // Reset UI for fresh review
                alertContainer.innerHTML = "";

                document.querySelectorAll(".review-numbers").forEach(btn => {
                    btn.disabled = false;
                    btn.classList.remove("opacity-40", "cursor-not-allowed", "review-numbers-selected");
                });

                document.getElementById("reviewComment").disabled = false;

                submitBtn.textContent = "Submit Feedback";
                submitBtn.disabled = false;
                submitBtn.classList.remove("opacity-50", "cursor-not-allowed");

                // 🔥 Show remind later, hide close button
                remindBtn.classList.remove("hidden");
                closeBtn.classList.add("hidden");

                selectedRating = null;
            }
        });

        closeBtn.addEventListener("click", () => modal.classList.add("hidden"));
        remindBtn.addEventListener("click", () => modal.classList.add("hidden"));

        // ========= Submit Feedback =========
        submitBtn.addEventListener("click", async () => {
            if (submitBtn.disabled) return;

            if (!selectedRating) {
                alertContainer.innerHTML = "";
                const warning = document.createElement("div");
                warning.className =
                    "bg-warning-100 text-warning-600 dark:bg-warning-600/20 border-l-4 border-warning-600 p-3 rounded-md text-sm font-medium mb-4 flex items-center gap-2";
                warning.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> Please select a rating first.`;
                alertContainer.appendChild(warning);

                setTimeout(() => warning.remove(), 3000);
                return;
            }

            const comment = document.getElementById("reviewComment").value.trim();

            const response = await fetch("api/save-review.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    instance_id: "<?php echo $instanceId; ?>",
                    rating: selectedRating,
                    comment: comment
                })
            });

            const result = await response.json();

            if (result.success) {
                showAlert(result.message || "Review successfully submitted!", "success");

                if (selectedRating >= 8) {
                    // TODO: replace with the real Ecwid App Market listing URL for this app
                    window.open(`https://www.ecwid.com/apps`, "_blank");
                }
            } else {
                showAlert(result.message || "Something went wrong!", "error");
            }

            modal.classList.add("hidden");
        });
    });
</script>