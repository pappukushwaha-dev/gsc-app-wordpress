<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Page Meta
|--------------------------------------------------------------------------
*/
$title    = 'View Setting';
$subTitle = 'Setting';

/*
|--------------------------------------------------------------------------
| Bootstrap
|--------------------------------------------------------------------------
*/
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Resolve Instance
|--------------------------------------------------------------------------
*/
$instanceId = $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;;

if (!$instanceId) {
    header('Location: sign-in.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Fetch Store Data
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        shop_name,
        email,
        domain,
        shop_domain
    FROM WpSite
    WHERE instance_id = ?
    LIMIT 1
");
$stmt->execute([$instanceId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    header('Location: sign-in.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Normalize Data
|--------------------------------------------------------------------------
*/
$shopName = !empty($row['shop_name']) ? $row['shop_name'] : 'Shop';
$email    = !empty($row['email']) ? $row['email'] : 'N/A';

/*
|--------------------------------------------------------------------------
| Resolve Domain (Authoritative)
|--------------------------------------------------------------------------
| 1. Prefer custom domain
| 2. Fallback to shop_domain
|--------------------------------------------------------------------------
*/
$resolvedDomain = '';

if (!empty($row['domain'])) {
    $resolvedDomain = trim($row['domain']);
} elseif (!empty($row['shop_domain'])) {
    $resolvedDomain = trim($row['shop_domain']);
}

/*
|--------------------------------------------------------------------------
| Website URL Builder (Safe)
|--------------------------------------------------------------------------
*/
if ($resolvedDomain) {
    if (!preg_match('#^https?://#i', $resolvedDomain)) {
        $websiteUrl = 'https://' . $resolvedDomain;
    } else {
        $websiteUrl = $resolvedDomain;
    }
} else {
    $websiteUrl = '';
}

/*
|--------------------------------------------------------------------------
| Generate Initials
|--------------------------------------------------------------------------
*/
$sourceForInitials = $shopName !== 'Shop' ? $shopName : $email;

$words = preg_split('/[\s@._-]+/', $sourceForInitials);
$initials = '';

foreach ($words as $w) {
    if ($w !== '') {
        $initials .= strtoupper($w[0]);
    }
}

$initials = substr($initials ?: 'SH', 0, 2);
?>

<?php include './partials/layouts/layoutTop.php'; ?>

<!-- ======================= UI ======================= -->

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 card border-none p-6">

    <!-- Profile Card -->
    <div class="col-span-12 lg:col-span-5">
        <div class="border border-neutral-200 dark:border-neutral-600 rounded-2xl overflow-hidden h-full">

            <div class="h-40 sm:h-52 w-full overflow-hidden">
                <img src="assets/images/schema-template/bg.png"
                     alt=""
                     class="w-full h-full object-cover">
            </div>

            <div class="px-6 pb-8 -mt-[100px] text-center">
                <div class="relative mx-auto w-[200px] h-[200px] rounded-full
                            bg-cstm-primary text-white
                            flex items-center justify-center
                            text-[96px] font-bold border-4 border-white">
                    <?= htmlspecialchars($initials) ?>
                </div>

                <h6 class="mt-4 text-xl font-semibold">
                    <?= htmlspecialchars($shopName) ?>
                </h6>

                <span class="text-secondary-light text-base">
                    <?= htmlspecialchars($email) ?>
                </span>
            </div>

        </div>
    </div>

    <!-- Organization Info -->
    <div class="col-span-12 lg:col-span-7">
        <div class="h-full border border-neutral-200 dark:border-neutral-600 rounded-2xl p-6">

            <h6 class="text-xl font-semibold mb-6 text-neutral-900 dark:text-white">
                Organization Info
            </h6>

            <ul class="grid gap-5 text-sm bg-gray-50 dark:bg-gray-800 rounded-2xl p-6 overflow-x-auto">

                <li class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <span class="font-semibold">Store Name</span>
                    <span class="sm:col-span-2 text-neutral-500">
                        <?= htmlspecialchars($shopName) ?>
                    </span>
                </li>

                <li class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <span class="font-semibold">Email</span>
                    <span class="sm:col-span-2 text-neutral-500">
                        <?= htmlspecialchars($email) ?>
                    </span>
                </li>

                <li class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <span class="font-semibold">Domain</span>
                    <span class="sm:col-span-2 text-neutral-500">
                        <?= $resolvedDomain
                            ? htmlspecialchars($resolvedDomain)
                            : '<span class="text-neutral-400">Not Available</span>' ?>
                    </span>
                </li>

                <li class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <span class="font-semibold">Website</span>
                    <span class="sm:col-span-2">
                        <?php if ($websiteUrl): ?>
                            <a href="<?= htmlspecialchars($websiteUrl) ?>"
                               target="_blank"
                               class="text-cstm-primary hover:underline">
                                <?= htmlspecialchars($websiteUrl) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-neutral-400">Not Available</span>
                        <?php endif; ?>
                    </span>
                </li>

            </ul>

        </div>
    </div>

</div>

<?php include './partials/layouts/layoutBottom.php'; ?>