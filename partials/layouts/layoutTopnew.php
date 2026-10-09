<!-- meta tags and other links -->
<!DOCTYPE html>
<html lang="en">

<?php
/* ==========================================================
   CONFIG
========================================================== */
require_once __DIR__ . '/../../includes/config.php';

/* ==========================================================
   SAFE APP_BASE
========================================================== */
if (!defined('APP_BASE')) {
    define('APP_BASE', '/wordpress/googlesearchconsole');
}

/* ==========================================================
   SESSION
========================================================== */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ==========================================================
   INSTANCE (SHOPIFY ONLY)
========================================================== */
$instanceId =
    $_SESSION['instance_id']
    ?? $_GET['instanceId']
    ?? $_SESSION['instanceid']
    ?? null;

if (!$instanceId) {
    header("Location: " . APP_BASE . "/sign-in.php");
    exit();
}

/* Standardize session key */
$_SESSION['instance_id'] = $instanceId;

/* ==========================================================
   VALIDATE ACTIVE SHOP (SAFE)
========================================================== */
$shopDomain = null;

try {
    $stmt = $pdo->prepare("
        SELECT shop_domain
        FROM WpSite
        WHERE instance_id = ?
        AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$instanceId]);
    $shopDomain = $stmt->fetchColumn();
} catch (Exception $e) {
    // prevent fatal crash
}

/* ==========================================================
   PAGE CHECK
========================================================== */
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$isSetupWizard = ($currentPage === 'setup-wizard.php');

/*
   IMPORTANT:
   Allow setup wizard to render
   even if shop not validated yet
*/
if (!$shopDomain && !$isSetupWizard) {
    session_destroy();
    header("Location: " . APP_BASE . "/sign-in.php");
    exit();
}

/* ==========================================================
   SUBSCRIPTION GUARD
========================================================== */
if (!$isSetupWizard) {

    $subscription = null;

    try {
        $subStmt = $pdo->prepare("
            SELECT status
            FROM app_subscriptions
            WHERE instance_id = ?
            LIMIT 1
        ");
        $subStmt->execute([$instanceId]);
        $subscription = $subStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // prevent crash
    }

    if (!$subscription || strtolower($subscription['status'] ?? '') !== 'active') {
        header("Location: " . APP_BASE . "/setup-wizard.php");
        exit();
    }
}?>


<?php include './partials/head.php'; ?>

<body class="dark:bg-neutral-800 bg-neutral-100 dark:text-white">

<main class="max-w-7xl mx-auto">
    <div id="resetConfirm"
        class="fixed inset-0 z-99 hidden flex items-center justify-center">
        <!-- backdrop -->
        <div class="absolute inset-0 bg-cstm-black-50"></div>
        <!-- card -->
        <div id="resetModalBackdrop" class="flex items-center justify-center p-4"
             aria-modal="true" role="dialog" aria-labelledby="modalTitle">

            <div class="relative bg-white dark:bg-neutral-800 rounded-xl shadow-2xl w-full max-w-md mx-auto transform transition-all p-6 border border-neutral-100 dark:border-neutral-600">

                <div class="flex items-start space-x-4 mb-4">
                    <div>
                        <h3 id="modalTitle" class="text-xl font-bold text-neutral-900 dark:text-white">
                            Schema Reset
                        </h3>
                    </div>
                </div>

                <p class="text-neutral-600 dark:text-neutral-300 mb-6 pl-14 -mt-1 text-center">
                    ⚠️ This action will <strong>permanently delete</strong> all existing schema configurations.
                    <span class="block">Are you sure you want to proceed?</span>
                </p>

                <div class="flex gap-3 justify-end pt-4 border-t border-neutral-100 dark:border-neutral-600">
                    <button type="button" id="resetCancel" class="btn btn-cstm-secondary">
                        Cancel
                    </button>

                    <button type="button" id="resetOK" class="text-white bg-red-600 btn">
                        Yes, Reset Schemas
                    </button>
                </div>

            </div>
        </div>
    </div>

    <script>
        (function() {
            const open = document.getElementById('resetOpenConfirm');
            const modal = document.getElementById('resetConfirm');
            const okBtn = document.getElementById('resetOK');
            const noBtn = document.getElementById('resetCancel');

            const show = () => modal.classList.remove('hidden');
            const hide = () => modal.classList.add('hidden');

            open?.addEventListener('click', show);
            noBtn?.addEventListener('click', hide);
            modal?.addEventListener('click', (e) => {
                if (e.target === modal) hide();
            });

            okBtn?.addEventListener('click', () => {
                window.location.href = 'reset-apply.php';
            });
        })();

        // document.addEventListener("DOMContentLoaded", async () => {
        //     try {
        //         const res = await fetch("api/setup-wizard-status.php");
        //         const data = await res.json();

        //         if (data.success && data.redirect) {
        //             if (!window.location.href.includes(data.redirect)) {
        //                 window.location.href = data.redirect;
        //             }
        //         }
        //     } catch (err) {
        //         console.error("Setup check failed:", err);
        //     }
        // });
    </script>

    <div class="dashboard-main-body">
