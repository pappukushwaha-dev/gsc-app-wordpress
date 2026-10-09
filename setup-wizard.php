<?php

declare(strict_types=1);
$title    = 'Google Search Console Setup';
$subTitle = 'Setup';
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/config.php';

/* ==========================================================
   1. INSTANCE RESOLUTION (STANDARDIZED)
========================================================== */
$instanceId = $_SESSION['instance_id'] ?? $_GET['instanceId'] ?? null;


if (!$instanceId) {
    header("Location: sign-in.php");
    exit;
}
$subStmt = $pdo->prepare("
    SELECT plan_name, status
    FROM app_subscriptions
    WHERE instance_id = :iid
    LIMIT 1
");
$subStmt->execute([':iid' => $instanceId]);
$subscription = $subStmt->fetch(PDO::FETCH_ASSOC);
$hasActiveSubscription = ($subscription && $subscription['status'] === 'active');

/* ==========================================================
   3. STEP HANDLING
========================================================== */
$step = $_GET['step'] ?? '1';
if (!in_array($step, ['1', '2', '3'], true)) {
    $step = '1';
}
$steps = [
    '1' => ['label' => 'Step 1', 'title' => 'Connect Your Domain'],
    '2' => ['label' => 'Step 2', 'title' => 'Verify Domain or Add New'],
    '3' => ['label' => 'Step 3', 'title' => 'Submit Sitemap'],
];
$currentStep = (int)$step;
$prevStep    = max(1, $currentStep - 1);
$nextStep    = min(count($steps), $currentStep + 1);

/* ==========================================================
   4. FETCH SHOPIFY SITE DATA (NEW DB)
========================================================== */
$siteUrl = null;
$siteStmt = $pdo->prepare("
    SELECT domain
    FROM WpSite
    WHERE instance_id = :iid
      AND is_active = 1
    LIMIT 1
");
$siteStmt->execute([':iid' => $instanceId]);
$shopDomain = $siteStmt->fetchColumn();
if ($shopDomain) {
    $siteUrl = 'https://' . $shopDomain;
}

/* ==========================================================
   5. LAYOUT START
========================================================== */
include './partials/layouts/layoutTopnew.php';
include __DIR__ . '/partials/ticket-submit-popup.php';
?>
<style>
    .box-shadow-cstm {
        box-shadow: 0 0 0 4px #fff;
    }

    .dark .box-shadow-cstm {
        box-shadow: 0 0 0 4px #273142;
    }

    .muted-progress-line {
        left: 15%;
        right: 15%;
        margin-top: -6px;
        margin-inline: auto;
        top: 22px;
    }

    .progress-line {
        left: 15%;
        margin-top: -6px;
        top: 22px;
    }
</style>

<!-- ===================== HEADER CARD ===================== -->
<div class="border border-neutral-200 bg-white shadow-sm mb-6 rounded-lg"
    style="background:linear-gradient(135deg,#c8dcfa 0%,#e4ebf9 100%)">
    <div class="grid grid-cols-1 md:grid-cols-3 items-center gap-2 px-6 py-3">
        <div>
            <h4 class="text-xl font-semibold flex items-center gap-2">
                <iconify-icon icon="heroicons:globe-alt-solid" class="text-2xl text-cstm-primary"></iconify-icon>
                Website
            </h4>
            <p class="mt-2">
                <?php if ($siteUrl): ?>
                    <a href="<?= htmlspecialchars($siteUrl) ?>"
                        target="_blank"
                        class="px-2 py-1.5 border rounded-lg text-sm font-medium text-[#652ec3] hover:underline max-w-max truncate border-gray-300 block">
                        <?= htmlspecialchars(
                            parse_url($siteUrl, PHP_URL_HOST) .
                                (parse_url($siteUrl, PHP_URL_PATH) ?? '')
                        ) ?>
                    </a>
                <?php else: ?>
                    <span class="text-sm text-gray-500">Website not linked</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="text-center">
            <h4 class="text-4xl font-semibold">Setup Wizard</h4>
            <p class="text-sm text-gray-600 mt-1">
                Follow the steps to configure Google Search Console.
            </p>
        </div>
        <div class="flex justify-center sm:justify-end">
            <button id="support-ticket-btn" class="btn btn-cstm-primary flex items-center gap-2">
                <iconify-icon icon="bx:support" width="18"></iconify-icon>
                Support
            </button>
        </div>
    </div>
</div>

<!-- ===================== WIZARD ===================== -->
<div class="grid grid-cols-1 gap-6">
    <div class="card border-0 max-w-4xl">
        <div class="card-body">
            <div class="relative max-w-lg mx-auto mb-6">
                <div class="absolute inset-x-0  h-1 bg-gray-200 dark:bg-gray-500 muted-progress-line"></div>
                <div class="absolute inset-x-0  h-1 bg-cstm-primary transition-all duration-500 progress-line"
                    style="width: <?= (($currentStep - 1) / 2) * 68 ?>%;"></div>
                <div class="flex justify-between">
                    <?php
                    $i = 1;
                    foreach ($steps as $details):
                        $completed = $currentStep > $i;
                        $active    = $currentStep === $i;
                    ?>
                        <div class="flex-1 text-center relative px-2">
                            <div class="flex flex-col items-center justify-center">
                                <div class="w-10 h-10 rounded-full border-4 flex items-center justify-center
                                <?= $completed ? 'bg-cstm-primary border-cstm-primary text-white'
                                    : ($active ? 'bg-white border-cstm-primary text-cstm-primary shadow-lg dark:bg-gray-600 outline-cstm-primary outline outline-1 outline-offset-1 box-shadow-cstm'
                                        : 'bg-gray-200 border-gray-300 text-gray-500') ?>">
                                    <?= $i ?>
                                </div>
                                <span class="mt-2 text-sm <?= ($active || $completed) ? 'text-neutral-600' : 'text-gray-600' ?>">
                                    <?= $details['title'] ?>
                                </span>
                            </div>
                        </div>
                    <?php $i++;
                    endforeach; ?>
                </div>
            </div>
            <!-- Step Content -->
            <?php
            if ($step === '1') {
                include 'step-one.php';
            } elseif ($step === '2') {
                include 'step-two.php';
            } else {
                include 'step-three.php';
            }
            ?>

            <div class="flex justify-between items-center mt-5 border-0">
                <?php if ($currentStep > 1): ?>
                    <a href="?step=<?= $prevStep ?>" class="btn btn-light flex items-center gap-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-neutral-700 dark:text-gray-200 dark:hover:bg-neutral-600 transition-colors">
                        <iconify-icon icon="ic:round-arrow-back"></iconify-icon>
                        Previous
                    </a>
                <?php else: ?>
                    <div></div>
                <?php endif; ?>
                <?php if ($currentStep < count($steps)): ?>
                    <a href="?step=<?= $nextStep ?>" class="btn btn-cstm-primary flex items-center gap-2 hidden">
                        Next
                        <iconify-icon icon="ic:round-arrow-forward"></iconify-icon>
                    </a>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- ===================== JS ===================== -->
<script>
    window.APP_BASE = "<?= APP_BASE ?>";
</script>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const supportBtn = document.getElementById('support-ticket-btn');
        window.TicketPopup = window.TicketPopup || {};
        window.TicketPopup.onSuccess = function() {
            showAlert('Ticket created successfully!');
        };

        if (supportBtn && window.TicketPopup) {
            window.TicketPopup.setInstanceId(
                <?= json_encode($_SESSION['instance_id'] ?? null) ?>
            );

            // ✅ Owner email from DB
            window.TicketPopup.setOwnerEmail(
                <?= json_encode($ownerEmail ?? 'N/A') ?>
            );

            supportBtn.addEventListener('click', () => {
                window.TicketPopup.open();
            });
        }
    });
</script>
<?php if ($step === '1'): ?>
    <script src="<?= APP_BASE ?>/assets/js/partials/setup-wizard-step1.js"></script>
<?php elseif ($step === '2'): ?>
    <script src="<?= APP_BASE ?>/assets/js/partials/setup-wizard-step2.js"></script>
<?php else: ?>
    <script src="<?= APP_BASE ?>/assets/js/partials/setup-wizard-step3.js"></script>
<?php endif; ?>