<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$instanceId = $_GET['instance_id'] ?? ($_SESSION['instanceid'] ?? null);
$instanceId = is_string($instanceId) ? trim($instanceId) : null;

if ($instanceId) {
    $_SESSION['instanceid'] = $instanceId;
}

$title = 'Purchase Success';
$subTitle = 'Thank You';
?>

<?php include './partials/head.php'; ?>

<div class="flex items-center justify-center px-4"
     style="min-height: calc(100vh - 80px);">

    <div
        class="card bg-white dark:bg-neutral-800 border border-neutral-200 dark:border-neutral-700 p-10 rounded-xl shadow-lg text-center"
        style="max-width: 600px; width: 100%;">

        <iconify-icon
            icon="tabler:circle-check"
            class="text-success-600 mx-auto mb-4 w-24 h-24 text-[70px]">
        </iconify-icon>

        <h1 class="text-3xl font-bold text-success-600 dark:text-success-400">
            🎉 Thank You for Your Purchase!
        </h1>

        <p class="text-lg text-neutral-700 dark:text-neutral-300">
            <br><strong>Your plan has been activated.</strong>
        </p>

       
        <p class="text-sm text-neutral-500 mt-4">
            You will be redirected automatically in 5 seconds.
        </p>
    </div>
</div>

<!-- ✅ WORKING SCRIPT (DO NOT MOVE ABOVE) -->
<script>
    const instanceId = "<?php echo $instanceId; ?>";
    let redirectUrl = null;

    fetch("api/setup-wizard-steps-check.php?instance_id=" + encodeURIComponent(instanceId))
        .then(res => res.json())
        .then(data => {
            if (data.success && data.redirect) {
                redirectUrl = data.redirect;

                const btn = document.getElementById('continueBtn');
                if (btn) btn.href = redirectUrl;

                setTimeout(() => {
                    window.location.href = redirectUrl;
                }, 5000);
            }
        })
        .catch(err => {
            console.error('Redirect check failed', err);
        });
</script>
