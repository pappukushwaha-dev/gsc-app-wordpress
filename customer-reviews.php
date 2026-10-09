<?php
// Start the session to access session variables
session_start();
require_once __DIR__ . '/includes/config.php';
$title = 'Reviews';
$subTitle = 'Reviews';
include './partials/layouts/layoutTop.php';
?>

<script>
window.addEventListener("load", () => {

    if (typeof openReviewModal === "function") {
        openReviewModal();
    }

    setTimeout(() => {

        const modal = document.getElementById("reviewModal");
        const closeBtn = document.getElementById("closeReviewModal");
        const remindBtn = document.getElementById("reviewRemindLater");
        const submitBtn = document.getElementById("reviewSubmit");
        const alreadyBtn = document.getElementById("alreadySubmittedBtn");

        if (closeBtn) {
            closeBtn.addEventListener("click", () => {
                window.location.href = "dashboard.php";
            });
        }

         if (remindBtn) {
            remindBtn.addEventListener("click", () => {
                window.location.href = "dashboard.php";
            });
        }

        if (submitBtn) {
            submitBtn.addEventListener("click", () => {
                setTimeout(() => {
                    window.location.href = "dashboard.php";
                }, 800);
            });
        }

    }, 300);

});

</script>

<?php include './partials/layouts/layoutBottom.php' ?>