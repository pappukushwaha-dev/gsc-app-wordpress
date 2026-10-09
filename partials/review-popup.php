<?php
$instanceId = $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;;

?>


<!-- Review Modal -->
<div id="reviewModal"
    class="fixed inset-0 flex bg-cstm-black-40 backdrop-blur-sm items-center justify-center z-30 shadow-lg hidden">

    <div
        class="bg-white dark:bg-neutral-800 rounded-xl shadow-2xl py-6 px-2 sm:px-8 w-full max-w-2xl border-b-6 border-cstm-primary relative">

        <!-- Alert Message -->
        <div id="alertContainer" class="mb-4 transition-all duration-300"></div>

        <!-- Close Button -->
        <button id="closeReviewModal" class="absolute z-10 right-4 top-4 text-gray-500 hover:text-gray-700">
            ✕
        </button>

        <h2 class="text-xl font-bold text-center text-neutral-800 mb-4">
            How likely are you to recommend Google Search Console for performance benefits?
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
                rows="3" placeholder="Tell us what we can improve..."></textarea>
        </div>

        <div class="flex justify-center gap-4 pt-6 border-t border-gray-200 dark:border-neutral-600">
            <button id="reviewRemindLater" class="btn btn-cstm-muted">
                Remind Me Later
            </button>

            <button id="reviewSubmit" class="btn btn-cstm-primary">
                Submit Feedback
            </button>
        </div>
    </div>
</div>




<script>
    document.addEventListener("DOMContentLoaded", () => {

        const modal =
            document.getElementById("reviewModal");

        const commentBox =
            document.getElementById("commentBox");

        const submitBtn =
            document.getElementById("reviewSubmit");

        const openBtn =
            document.getElementById("openReviewModal");

        const closeBtn =
            document.getElementById("closeReviewModal");

        const remindBtn =
            document.getElementById("reviewRemindLater");

        const alertContainer =
            document.getElementById("alertContainer");

        let selectedRating = null;


        function openReviewModalHandler() {

            modal.classList.remove("hidden");

            modal.classList.add("flex");

            alertContainer.innerHTML = "";

            selectedRating = null;

            commentBox.classList.add("hidden");

            document.getElementById(
                "reviewComment"
            ).value = "";

            document.querySelectorAll(
                ".review-numbers"
            ).forEach(btn => {

                btn.classList.remove(
                    "review-numbers-selected"
                );
            });

            submitBtn.disabled = false;

            submitBtn.textContent =
                "Submit Feedback";

            remindBtn.classList.remove(
                "hidden"
            );

            closeBtn.classList.remove(
                "hidden"
            );
        }

        /*
        ============================================
        | RATING BUTTONS
        ============================================
        */

        const ratingContainer =
            document.getElementById(
                "ratingButtons"
            );

        ratingContainer.innerHTML = "";

        for (let i = 1; i <= 10; i++) {

            const btn =
                document.createElement("button");

            btn.textContent = i;

            btn.dataset.value = i;

            btn.className =
                `review-numbers w-10 aspect-[1] flex items-center justify-center rounded-full text-white font-bold cursor-pointer transition bg-c${i}`;
            btn.addEventListener("click", async () => {

                selectedRating = i;

                [...ratingContainer.children]
                    .forEach(b => {

                        b.classList.remove(
                            "review-numbers-selected"
                        );
                    });

                btn.classList.add(
                    "review-numbers-selected"
                );

                /*
                ====================================
                | LOW RATINGS → SHOW COMMENT BOX
                ====================================
                */

                commentBox.classList.remove("hidden");

                selectedRating = i;

                return;
                //  HIGH RATINGS → AUTO SAVE + REDIRECT
                commentBox.classList.add("hidden");

                try {

                    const response = await fetch(
                        "api/save-review.php", {
                        method: "POST",

                        headers: {
                            "Content-Type": "application/json"
                        },

                        body: JSON.stringify({

                            instance_id: "<?php echo $instanceId; ?>",

                            rating: i,

                            comment: "app review"
                        })
                    }
                    );

                    const result =
                        await response.json();

                    if (result.success) {

                        modal.classList.add(
                            "hidden"
                        );





                    } else {

                        console.log(
                            result.message ||
                            "Failed to save review"
                        );
                    }

                } catch (error) {

                    console.error(error);
                }
            });
            ratingContainer.appendChild(btn);
        }
        window.openReviewModal = openReviewModalHandler;

        /*
        ============================================
        | OPEN MODAL
        ============================================
        */

        openBtn?.addEventListener(
            "click",
            openReviewModalHandler
        );

        const urlParams =
            new URLSearchParams(
                window.location.search
            );

        const shouldAutoOpen =
            sessionStorage.getItem(
                "autoOpenReviewModal"
            ) === "true" ||
            urlParams.get("auto_review") === "1";

        if (shouldAutoOpen) {

            sessionStorage.removeItem(
                "autoOpenReviewModal"
            );

            if (
                urlParams.get("auto_review")
            ) {

                const cleanUrl =
                    window.location.pathname +
                    window.location.search
                        .replace(
                            /([?&])auto_review=1/,
                            ''
                        )
                        .replace(
                            /[?&]$/,
                            ''
                        );

                window.history.replaceState({},
                    '',
                    cleanUrl
                );
            }

            setTimeout(() => {

                openReviewModalHandler();

            }, 1200);
        }

        /*
        ============================================
        | CLOSE MODAL
        ============================================
        */

        closeBtn?.addEventListener(
            "click",
            () => {

                modal.classList.add("hidden");

                modal.classList.remove("flex");

                const triggerBtn =
                    document.getElementById(
                        'openReviewModal'
                    );

                const shouldRedirect =
                    triggerBtn?.getAttribute(
                        'data-redirect-after'
                    ) === 'true';

                if (shouldRedirect) {

                    window.location.href =
                        "index.php";
                }
            }
        );
        /*
        ============================================
        | REMIND LATER
        ============================================
        */

        remindBtn.addEventListener(
            "click",
            () => {

                modal.classList.add("hidden");

                const triggerBtn =
                    document.getElementById(
                        'openReviewModal'
                    );

                const shouldRedirect =
                    triggerBtn?.getAttribute(
                        'data-redirect-after'
                    ) === 'true';

                if (shouldRedirect) {

                    window.location.href =
                        "index.php";
                }
            }
        );

        /*
        ============================================
        | SUBMIT FEEDBACK
        ============================================
        */

        submitBtn.addEventListener("click", async () => {

            const triggerBtn = document.getElementById("openReviewModal");

            const shouldRedirect =
                triggerBtn?.getAttribute("data-redirect-after") === "true";

            if (selectedRating === null) {

                showAlert("Please select a rating first.", "error");
                return;
            }

            const comment = document
                .getElementById("reviewComment")
                .value
                .trim();

            submitBtn.disabled = true;
            submitBtn.textContent = "Submitting...";

            try {

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

                    showAlert(
                        result.message || "Review submitted successfully!",
                        "success"
                    );

                    modal.classList.add("hidden");
                    modal.classList.remove("flex");

                    if (shouldRedirect) {

                        setTimeout(() => {
                            window.location.href = "index.php";
                        }, 800);

                    }

                } else {

                    showAlert(
                        result.message || "Something went wrong!",
                        "error"
                    );

                    submitBtn.disabled = false;
                    submitBtn.textContent = "Submit Feedback";
                }

            } catch (error) {

                console.error(error);

                showAlert(
                    "Something went wrong!",
                    "error"
                );

                submitBtn.disabled = false;
                submitBtn.textContent = "Submit Feedback";
            }

        });

    });
</script>