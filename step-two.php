<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/google/get_bigcommerce_site.php';
require_once __DIR__ . '/includes/google/get_account.php';
// /* ==========================================================
//    SESSION
// ========================================================== */
// if (session_status() === PHP_SESSION_NONE) {
//     session_start();
// }

// $instanceId = $_SESSION['instance_id']
//     ?? $_SESSION['instanceid']
//     ?? null;

// if (!$instanceId) {
//     die("Instance ID missing");
// }

// /* ==========================================================
//    1. GET SITE URL FROM WpSite
// ========================================================== */
// $stmt = $pdo->prepare("
//     SELECT domain
//     FROM WpSite
//     WHERE instance_id = ?
//       AND is_active = 1
//     LIMIT 1
// ");
// $stmt->execute([$instanceId]);
// $shopDomain = $stmt->fetchColumn();

// if (!$shopDomain) {
//     die("Shop not found or uninstalled");
// }

// $normalizedDomain = strtolower(trim($shopDomain));
// $siteUrl = 'https://' . rtrim($normalizedDomain, '/');

// // Detect Ecwid Instant Site (Ecwid-hosted) vs embedded storefront.
// // Instant Site domains end in .company.site, .ecwid.com, or .ecwid.site.
// $isInstantSite = (bool)preg_match('/\.(company\.site|ecwid\.com|ecwid\.site)$/i', $normalizedDomain);



// /* ==========================================================
//    2. LOAD OR CREATE VERIFICATION RECORD
// ========================================================== */
// $stmt = $pdo->prepare("
//     SELECT *
//     FROM gsc_domain_verifications
//     WHERE instance_id = ?
//     LIMIT 1
// ");
// $stmt->execute([$instanceId]);
// $domain = $stmt->fetch(PDO::FETCH_ASSOC);

// if (!$domain) {
//     $stmt = $pdo->prepare("
//         INSERT INTO gsc_domain_verifications
//             (instance_id, site_url, verification_status, verification_method)
//         VALUES (?, ?, 'pending', 'meta')
//     ");
//     $stmt->execute([
//         $instanceId,
//         $siteUrl
//     ]);

//     $domain = [
//         'instance_id'         => $instanceId,
//         'site_url'            => $siteUrl,
//         'verification_status' => 'pending',
//         'verification_method' => 'meta'
//     ];
// }


// /* ==========================================================
//    3. NORMALIZE VALUES FOR UI
// ========================================================== */
// $metaToken = $domain['meta_token'] ?? '';

// $status    = $domain['verification_status'] ?? 'pending';



// SESSION
$instanceId = $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;
if (!$instanceId) {
    die("Instance ID missing");
}





// Get Google account
$googleAccount = $instanceId ? getGoogleAccountByShop($instanceId) : null;
$isConnected = (
    $googleAccount &&
    $googleAccount['connected'] == 1 &&
    (
        !empty($googleAccount['access_token']) ||
        !empty($googleAccount['refresh_token'])
    )
);

// -----------------------------------------------------
// 1. GET SITE URL FROM WixSite TABLE
// -----------------------------------------------------
$wixSite = getWpSiteByInstance($instanceId);
$siteUrl = $wixSite['site_url'] ?? null;


$normalizedSiteUrl = null;

if ($siteUrl) {
    $normalizedSiteUrl = rtrim(trim($siteUrl), '/') . '/';
}

/* 🔒 HARD GUARD — ADD IT RIGHT HERE */
if (!$normalizedSiteUrl) {
    die("Invalid Wix site URL");
}

// $isInstantSite = !(bool)preg_match('/\.(company\.site|ecwid\.com|ecwid\.site)$/i', $normalizedSiteUrl);
$isInstantSite = true; //default

// -----------------------------------------------------
// 2. LOAD OR CREATE VERIFICATION RECORD
// -----------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM gsc_domain_verifications WHERE instance_id = ?");
$stmt->execute([$instanceId]);
$domain = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$domain) {
    $stmt = $pdo->prepare("
        INSERT INTO gsc_domain_verifications 
            (instance_id, site_url, verification_status)
        VALUES (?, ?, 'pending')
    ");
    $stmt->execute([$instanceId, $normalizedSiteUrl]);

    $domain = [
        'instance_id'         => $instanceId,
        'site_url'            => $normalizedSiteUrl,
        'meta_token'          => null,
        'meta_tag'            => null,
        'verification_status' => 'pending'
    ];
}


$metaTag   = $domain['meta_tag'] ?? '';
$metaToken = $domain['meta_token'] ?? '';

$status    = $domain['verification_status'];

// -----------------------------------------------------
// 3. SERVER-SIDE CHECK via EXISTING API (page render se pehle)
// check_property_status.php hi hit hogi — wahi property check
// karegi aur verified milne par DB update bhi wahi karegi.
// -----------------------------------------------------

if ($status !== 'verified') {
    try {
        $apiUrl = "https://makkpressapps.com" . APP_BASE . "/api/google/check_property_status.php"
            . "?instanceId=" . urlencode($instanceId);
        session_write_close();

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);

        $data = json_decode((string)$resp, true);

        // API ne verified bola = DB update ho chuka (API ke andar)
        if (!empty($data['success']) && !empty($data['verified'])) {
            $status = 'verified';
        }
    } catch (Throwable $e) {
        error_log('step-two api self-call: ' . $e->getMessage());
    }
}

if (!empty($status) && $status == 'verified') {
    // Step update API call
    try {

        $stepUrl = "https://makkpressapps.com" . APP_BASE . "/api/update-step.php";

        $postData = [
            'instanceId' => $instanceId,
            'step'       => 2
        ];

        $ch = curl_init($stepUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_TIMEOUT        => 10,
        ]);

        $resp = curl_exec($ch);
        curl_close($ch);

        $data = json_decode((string)$resp, true);
    } catch (Throwable $e) {
        error_log('update-step self-call: ' . $e->getMessage());
    }
}


?>

<style>
    .wizard-card {
        opacity: .45;
        transition: all .3s ease;
        filter: grayscale(100%);
    }

    .wizard-card.active {
        opacity: 1;
        filter: none;
        transform: translateY(-2px);
    }
</style>

<div id="step2-container" class="space-y-14">
    <div class="mx-auto my-4">
        <!-- Grid container: stacks on mobile, goes 2-columns on tablets, 3 columns on large screens -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

            <!-- Card 1: Property Status -->
            <div id="propertyCard" class="wizard-card <?= $status === 'verified' ? 'active' : '' ?> relative flex items-start gap-4 p-4 bg-cstm-primary-5 border border-blue-200 rounded-xl shadow-sm">
                <!-- Left Icon Container -->
                <div class="flex-shrink-0 flex items-center justify-center w-12 h-12 bg-gray-200 rounded-full border border-blue-100">
                    <iconify-icon icon="logos:google-icon" class="text-blue-600" width="24" height="24"></iconify-icon>
                </div>
                <!-- Content -->
                <div class="flex-1 grid pr-6">
                    <span class="text-xs font-semibold text-blue-600 tracking-wide block mb-1">Google Account</span>
                    <h3 class="text-base font-semibold text-slate-800 mb-1">Connected</h3>
                    <p class="truncate text-sm"> <?= htmlspecialchars($googleAccount['email']) ?></p>

                </div>
                <!-- Status Badge -->
                <div class="absolute top-3 right-3 flex-shrink-0">
                    <iconify-icon icon="bxs:check-circle" class="active-card-icon text-2xl text-success-600"></iconify-icon>
                </div>
            </div>

            <!-- Card 2: Domain Verification -->
            <div id="verificationCard" class="wizard-card <?= $status === 'verified' ? 'active' : '' ?> relative flex items-start gap-4 p-4 bg-cstm-primary-5 border border-blue-200 rounded-xl shadow-sm">
                <!-- Left Icon Container -->
                <div class="flex-shrink-0 flex items-center justify-center w-12 h-12 bg-gray-200 rounded-full border border-purple-100">
                    <iconify-icon icon="material-symbols:domain-verification-rounded" class="text-purple-600" width="24" height="24"></iconify-icon>
                </div>
                <!-- Content -->
                <div class="flex-1 pr-6 grid">
                    <span class="text-xs font-semibold text-purple-600 tracking-wide block mb-1">Property Status</span>
                    <h3 class="text-base font-semibold text-slate-800 mb-1">Found</h3>
                    <p class="truncate text-sm"> <?= htmlspecialchars($siteUrl) ?></p>
                </div>
                <!-- Status Badge -->
                <div class="absolute top-3 right-3 flex-shrink-0">
                    <iconify-icon icon="bxs:check-circle" class="active-card-icon text-2xl text-success-600"></iconify-icon>
                </div>
            </div>

            <!-- Card 3: Verification Method -->
            <div id="methodCard" class="wizard-card <?= $status === 'verified' ? 'active' : '' ?> relative flex items-start gap-4 p-4 bg-cstm-primary-5 border border-blue-200 rounded-xl shadow-sm">
                <!-- Left Icon Container -->
                <div class="flex-shrink-0 flex items-center justify-center w-12 h-12 bg-gray-200 rounded-full border border-orange-100">
                    <!-- Rotated container to build the orange diamond shape -->
                    <div class="w-8 h-8 bg-orange-600 rotate-45 rounded flex items-center justify-center shadow-sm">
                        <!-- Counter rotation so the code icon itself stays upright -->
                        <div class="-rotate-45 flex items-center justify-center">
                            <iconify-icon icon="material-symbols:verified" class="text-purple-600" width="24" height="24"></iconify-icon>
                        </div>
                    </div>
                </div>
                <!-- Content -->
                <div class="flex-1">
                    <span class="text-xs font-semibold text-purple-600 tracking-wide block mb-1">Domain Verification</span>
                    <h3 id="verificationStatusTitle" class="text-base font-semibold text-slate-800 mb-1"><?= $status === 'verified' ? 'Verified' : 'Not Verified' ?></h3>
                    <p id="verificationStatusText" class="text-sm text-slate-500 leading-relaxed">
                        <?= $status === 'verified' ? 'Domain successfully verified' : 'Verification required' ?>
                    </p>
                </div>
                <!-- Status Badge -->
                <div class="absolute top-3 right-3 flex-shrink-0">
                    <iconify-icon id="verifiedMethodIcon" icon="bxs:check-circle" class="active-card-icon text-2xl text-success-600"></iconify-icon>
                </div>
            </div>

        </div>
    </div>
    <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 relative overflow-hidden mt-4">
        <div class="absolute top-0 left-0 right-0 h-1 bg-cstm-primary drk-bg-cstm-primary-gradient-dark"></div>
        <div class="grid grid-cols-12 py-4 items-center">

            <div class="col-span-12 md:col-span-8 border-r border-gray-300 px-6 flex gap-2 items-center">
                <div class="hidden xl:block max-w-[220px]">
                    <img id="propertyNotFoundImg"
                        src="./assets/images/setup-wizard-step-2-1.png"
                        alt="Property not found">

                    <img id="propertyFoundImg"
                        src="./assets/images/setup-wizard-step-2-2.png"
                        alt="Property found"
                        class="hidden">

                    <img id="verificationImg"
                        src="./assets/images/setup-wizard-step-2-3.png"
                        alt="Property found"
                        class="hidden">
                </div>
                <div class="space-y-4 mt-2 w-full">
                    <?php if ($status === 'verified'): ?>
                        <div class="px-4 py-2 select-none">
                            <div class="flex items-center gap-3 mb-3">
                                <div class="flex-shrink-0 flex items-center justify-center w-8 h-8 bg-success-600 rounded-full shadow-sm">
                                    <iconify-icon icon="bx:bx-check" class="text-white font-bold" width="20" height="20"></iconify-icon>
                                </div>
                                <h2 class="text-lg font-bold text-gray-900 tracking-tight">Current Status</h2>
                            </div>

                            <p class="text-base font-bold text-success-600 mb-3">
                                Your domain is already verified in Google Search Console.
                            </p>

                            <p class="text-sm text-gray-500 max-w-3xl leading-relaxed mb-6">
                                Your website is successfully connected and verified. You can access all Google Search Console data and features.
                            </p>

                            <div class="flex items-start gap-3 p-4 bg-success-50/20 border border-success-200/60 rounded-xl max-w-3xl">
                                <!-- Mini Info Circle Icon -->
                                <div class="flex-shrink-0 pt-0.5">
                                    <iconify-icon icon="bx:bx-info-circle" class="text-success-600" width="18" height="18"></iconify-icon>
                                </div>
                                <div class="flex-1">
                                    <p class="text-xs sm:text-sm text-success-700 leading-relaxed font-medium">
                                        A new verification token is not required. However, you can generate a new token if you want to replace the existing verification setup.
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>

                        <!-- step-1 -left -->
                        <div id="propertyNotFoundContent">
                            <h2 class="text-2xl font-bold text-slate-900 tracking-tight leading-tight mb-1">
                                Search Console Property Not Found
                            </h2>
                            <p class="text-sm font-medium text-gray-600 leading-relaxed mb-4">
                                We couldn't find a Search Console property for your website.
                            </p>
                            <p class="text-sm font-medium text-gray-500 leading-relaxed mb-6">
                                No worries! We can automatically create a new Search Console property and verify your domain using your connected Google account.
                            </p>
                            <div class="flex items-start gap-4 p-5 bg-gray-100 border border-primary-400 rounded-lg max-w-2xl mb-6">
                                <!-- Automated Sparks Icon -->
                                <div class="flex-shrink-0 pt-0.5">
                                    <iconify-icon icon="mdi:creation" class="text-blue-600" width="22" height="22"></iconify-icon>
                                </div>

                                <div class="space-y-1">
                                    <h4 class="text-base font-bold text-primary-600">Fully Automated</h4>
                                    <p class="text-sm text-gray-800 leading-relaxed">
                                        We'll create the property, generate verification token, add meta tag to your Ecwid site and verify automatically.
                                    </p>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-neutral-700 dark:text-neutral-200 mb-2">
                                    Website URL
                                </label>
                                <input type="text" value="<?= htmlspecialchars($siteUrl) ?>" readonly class="w-full px-4 py-3 rounded-xl bg-neutral-100 dark:bg-neutral-700 text-neutral-600 dark:text-neutral-200 border border-neutral-300 dark:border-neutral-600 cursor-not-allowed">
                            </div>
                        </div>
                        <!-- step-2 -left -->
                        <div id="propertyFoundContent" class="hidden mb-6">
                            <div class="inline-flex items-center gap-1.5 px-3 py-1 bg-success-100 border border-success-200 rounded-full mb-4">
                                <span class="text-xs font-semibold text-success-main">Property Found Successfully! 🎉</span>
                            </div>

                            <h2 class="text-2xl font-bold text-slate-900 tracking-tight mb-3">
                                Your Search Console property is ready.
                            </h2>
                            <p class="text-sm font-medium text-gray-600 max-w-3xl leading-relaxed mb-6">
                                We found your property in Google Search Console. To continue, we just need to verify your domain ownership.
                            </p>

                            <!-- 3. Why Verification is Required - Info Box -->
                            <div class="flex items-start gap-4 p-5 bg-cstm-primary-5 border border-priamry-400 rounded-xl max-w-4xl">
                                <!-- Icon Container -->
                                <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 bg-cstm-primary-10 rounded-full border border-blue-100">
                                    <iconify-icon icon="bx:shield-quarter" class="text-primary-600" width="20" height="20"></iconify-icon>
                                </div>

                                <!-- Box Content -->
                                <div class="space-y-2">
                                    <h4 class="text-sm font-semibold text-primary-600">Why verification is required?</h4>
                                    <p class="text-sm text-gray-600 leading-relaxed">
                                        Verification proves that you own this domain and unlocks important data like performance, indexing status, and search insights.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Meta Tag -->
                        <div id="metaTagContainer" class="hidden">
                            <div class="inline-flex items-center gap-1.5 px-3 py-1 bg-success-100 border border-success-200 rounded-full mb-4">
                                <span class="text-xs font-semibold text-success-main">Great You're almost done. 🎉</span>
                            </div>
                            <h2 class="text-2xl font-bold text-slate-900 tracking-tight mb-3">
                                Verification Token Generated
                            </h2>
                            <p class="text-sm font-medium text-gray-600 max-w-3xl leading-relaxed mb-6">
                                We've generated a Google Verification Token for your website. We will now automatically add the verification tag to your Shopify site and verify your domain ownership.
                            </p>
                            <div class="flex justify-between items-center mt-3 mb-2">
                                <span class="text-xs text-neutral-500">
                                    Add this tag inside your Site &lt;head&gt;
                                </span>

                                <button
                                    type="button"
                                    onclick="openPopup()"
                                    class="text-sm font-medium text-blue-600 hover:underline">
                                    View Installation Instructions
                                </button>
                            </div>

                            <div class="p-4 border border-gray-200 rounded-lg">
                                <label class="text-base font-semibold text-primary-600 mb-2">
                                    Google Verification Meta Tag
                                </label>
                                <div class="p-2 border border-gray-200 rounded-lg relative bg-neutral-100 mt-2">
                                    <button
                                        type="button"
                                        id="copyVerificationToken"
                                        class="absolute top-3 right-3 inline-flex items-center gap-2 px-3 py-1.5 text-xs font-medium rounded-lg border border-neutral-300 bg-white hover:bg-neutral-100 transition">
                                        <iconify-icon icon="solar:copy-outline" width="14" height="14"></iconify-icon>
                                        Copy
                                    </button>

                                    <textarea readonly id="verificationToken" class="resize-none w-full rounded-xl bg-neutral-100 dark:bg-neutral-700 text-neutral-600 dark:text-neutral-200 border-0 border-neutral-300 dark:border-neutral-600 h-24 overflow-y-auto min-h-[80px]">
                                        <?php if ($metaToken): ?>
                                        <meta name="google-site-verification" content="<?= htmlspecialchars($metaToken) ?>">
                                        <?php endif; ?>
                                    </textarea>
                                </div>
                            </div>
                        </div>

                    <?php endif; ?>
                </div>

            </div>


            <div class="col-span-12 md:col-span-4 px-6">
                <?php if ($status === 'verified'): ?>
                    <div class="text-center mt-10">
                        <div class="rounded-lg pb-4 flex gap-3 text-start">
                            <span class="bg-cstm-primary-20 text-primary-600 flex items-center justify-center rounded-lg p-3 text-xl h-fit"><iconify-icon icon="lucide:refresh-cw"></iconify-icon></span>
                            <div class="text-start">
                                <p class="text-base font-semibold"> Would you like to generate a new verification token?</p>
                                <p class="text-start text-sm">This will replace your existing verification meta tag with a new one.</p>
                            </div>
                        </div>
                        <a href="setup-wizard.php?step=3" id="continueStep2Btn"
                            class="btn btn-cstm-primary light w-full flex justify-center items-center gap-2">
                            Continue
                            <iconify-icon icon="ic:round-arrow-forward" class="text-base"></iconify-icon>
                        </a>
                        <button id="generateTokenBtnTwo"
                            class="btn btn-cstm-primary w-full flex justify-center items-center gap-2 mt-2">
                            <iconify-icon icon="bx:key" class="text-lg"></iconify-icon>
                            Generate New Verification Token
                        </button>
                    </div>
                <?php else: ?>
                    <!-- PROPERTY MISSING -->
                    <div id="propertyMissingBox" class="hidden mb-6">
                        <div class="">
                            <div class="flex items-center gap-4 mb-8">
                                <div class="relative flex items-center justify-center rounded-full">
                                    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" id="circuit" viewBox="0 0 224 196.1778" enable-background="new 0 0 224 196.1778" xml:space="preserve" width="60" height="60">
                                        <g>
                                            <path fill="#D0E8FF" d="M24,96.1778c-8.8223,0-16,7.1777-16,16s7.1777,16,16,16s16-7.1777,16-16S32.8223,96.1778,24,96.1778z"></path>
                                            <path fill="#D0E8FF" d="M52,32.1778c-8.8223,0-16,7.1777-16,16s7.1777,16,16,16s16-7.1777,16-16S60.8223,32.1778,52,32.1778z"></path>
                                            <path fill="#D0E8FF" d="M200,116.1778c-8.8223,0-16,7.1777-16,16s7.1777,16,16,16s16-7.1777,16-16S208.8223,116.1778,200,116.1778z   "></path>
                                            <path fill="#5CB0FF" d="M200,92.1778c8.8223,0,16-7.1777,16-16s-7.1777-16-16-16s-16,7.1777-16,16S191.1777,92.1778,200,92.1778z"></path>
                                            <path fill="#1C71DA" d="M200,108.1778c-11.8689,0-21.7253,8.668-23.6387,20H160c-13.2344,0-24,10.7598-24,23.9863v44.0137h8   v-44.0137c0-8.8145,7.1777-15.9863,16-15.9863h16.3613c1.9133,11.332,11.7698,20,23.6387,20c13.2344,0,24-10.7656,24-24   S213.2344,108.1778,200,108.1778z M200,148.1778c-8.8223,0-16-7.1777-16-16s7.1777-16,16-16s16,7.1777,16,16   S208.8223,148.1778,200,148.1778z"></path>
                                            <path fill="#1C71DA" d="M92,48.1778H76c0-13.2344-10.7656-24-24-24s-24,10.7656-24,24s10.7656,24,24,24   c10.426,0,19.2947-6.6934,22.5999-16H92c8.8223,0,16,7.1875,16,16.0234v123.9766h8V72.2012   C116,58.9551,105.2344,48.1778,92,48.1778z M52,64.1778c-8.8223,0-16-7.1777-16-16s7.1777-16,16-16s16,7.1777,16,16   S60.8223,64.1778,52,64.1778z"></path>
                                            <path fill="#1C71DA" d="M64,108.1778H47.6387c-1.9133-11.332-11.7698-20-23.6387-20c-13.2344,0-24,10.7656-24,24s10.7656,24,24,24   c11.8689,0,21.7253-8.668,23.6387-20H64c8.8223,0,16,7.1836,16,16.0137v63.9863h8v-63.9863   C88,118.9512,77.2344,108.1778,64,108.1778z M24,128.1778c-8.8223,0-16-7.1777-16-16s7.1777-16,16-16s16,7.1777,16,16   S32.8223,128.1778,24,128.1778z"></path>
                                            <path fill="#1C71DA" d="M163.4609,80.1778h12.9004c1.9133,11.332,11.7698,20,23.6387,20c13.2344,0,24-10.7656,24-24   s-10.7656-24-24-24c-11.8689,0-21.7253,8.668-23.6387,20h-12.9004c-6.2969,0-11.4199-5.125-11.4199-11.4258V0.1778h-8V60.752   C144.041,71.463,152.752,80.1778,163.4609,80.1778z M200,60.1778c8.8223,0,16,7.1777,16,16s-7.1777,16-16,16s-16-7.1777-16-16   S191.1777,60.1778,200,60.1778z"></path>
                                        </g>
                                        <path fill="#FF5D5D" d="M136.2524,116.002c-1.0239,0-2.0474-0.3904-2.8286-1.1716c-1.562-1.562-1.562-4.0947,0-5.6567  l14.1421-14.1416c1.5635-1.5623,4.0957-1.562,5.6572,0c1.562,1.562,1.562,4.0947,0,5.6567l-14.1421,14.1416  C138.2998,115.6114,137.2759,116.002,136.2524,116.002z"></path>
                                        <path fill="#FF5D5D" d="M150.3945,116.0001c-1.0239,0-2.0474-0.3904-2.8286-1.1716l-14.1421-14.1426  c-1.562-1.562-1.562-4.0947,0-5.6567c1.5635-1.5623,4.0957-1.562,5.6572,0l14.1421,14.1426c1.562,1.562,1.562,4.0947,0,5.6567  C152.4419,115.6094,151.418,116.0001,150.3945,116.0001z"></path>
                                        <path fill="#00D40B" d="M38.2524,192.002c-7.7197,0-14-6.2803-14-14s6.2803-14,14-14s14,6.2803,14,14  S45.9722,192.002,38.2524,192.002z M38.2524,172.002c-3.3086,0-6,2.6917-6,6s2.6914,6,6,6s6-2.6917,6-6  S41.561,172.002,38.2524,172.002z"></path>
                                        <path fill="#FFC504" d="M123.5659,30.627c-1.0239,0-2.0474-0.3904-2.8286-1.1716l-11.3135-11.3135  c-1.562-1.562-1.562-4.0947,0-5.6567l11.3135-11.3135c1.5625-1.562,4.0952-1.5625,5.6567,0l11.314,11.3135  c0.7505,0.75,1.1719,1.7676,1.1719,2.8284s-0.4214,2.0784-1.1719,2.8284l-11.314,11.3135  C125.6133,30.2364,124.5894,30.627,123.5659,30.627z M117.9092,15.3135l5.6567,5.6567l5.6572-5.6567l-5.6572-5.6567  L117.9092,15.3135z"></path>
                                    </svg>
                                </div>

                                <div class="px-4 py-1.5 bg-cstm-primary-10 rounded-full">
                                    <span class="text-sm font-bold text-primary-600 tracking-wide">Powered by Automation</span>
                                </div>
                            </div>

                            <!-- 2. Features List -->
                            <div class="space-y-2 mb-6">

                                <!-- Feature 1: No Manual Steps -->
                                <div class="flex items-start gap-4">
                                    <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 bg-cstm-primary-10 rounded-full">
                                        <iconify-icon icon="bx:bxs-bolt" class="text-blue-600 text-lg"></iconify-icon>
                                    </div>
                                    <div>
                                        <h3 class="text-sm font-semibold text-gray-800 mb-0.5">No Manual Steps</h3>
                                        <p class="text-sm text-gray-500 ">Everything will be configured automatically using Google APIs.</p>
                                    </div>
                                </div>

                                <!-- Feature 2: Secure & Safe -->
                                <div class="flex items-start gap-4">
                                    <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 bg-cstm-primary-10 rounded-full">
                                        <iconify-icon icon="bx:bxs-shield-alt-2" class="text-orange-500 text-lg"></iconify-icon>
                                    </div>
                                    <div>
                                        <h3 class="text-sm font-semibold text-gray-800 mb-0.5">Secure &amp; Safe</h3>
                                        <p class="text-sm text-gray-500 ">We only access what's required to create and verify your property.</p>
                                    </div>
                                </div>

                                <!-- Feature 3: Saves Time -->
                                <div class="flex items-start gap-4">
                                    <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 bg-cstm-primary-10 rounded-full">
                                        <iconify-icon icon="bx:bx-time-five" class="text-purple-600 text-lg"></iconify-icon>
                                    </div>
                                    <div>
                                        <h3 class="text-sm font-semibold text-gray-800 mb-0.5">Saves Time</h3>
                                        <p class="text-sm text-gray-500 ">Setup completed in just a few seconds.</p>
                                    </div>
                                </div>

                            </div>
                        </div>
                        <button
                            id="createPropertyBtn"
                            class="btn btn-primary flex items-center gap-2 w-full justify-center">
                            <iconify-icon icon="mdi:creation" class="text-base"></iconify-icon>
                            Create Search Console Property
                        </button>
                    </div>

                    <!-- GENERATE TOKEN BUTTON -->
                    <div id="generateTokenWrapper" class="hidden">
                        <div class="mb-6">
                            <span class="text-xs font-semibold text-gray-500 tracking-wide block mb-4">
                                Next Step
                            </span>
                            <div class="flex items-center justify-center w-16 h-16 bg-success-50 rounded-full border border-success-400 mb-4">
                                <iconify-icon icon="bx:shield-quarter" class="text-success-600" width="26" height="26"></iconify-icon>
                            </div>
                            <h2 class="text-base font-bold text-neutral-900 mb-2">
                                Generate Verification Token
                            </h2>
                            <p class="text-sm text-gray-600">
                                We will generate a Google Verification Token and add it to your Ecwid site automatically.
                            </p>
                        </div>
                        <button id="generateTokenBtn"
                            class="btn btn-cstm-primary w-full flex justify-center items-center">
                            Generate Google Verification Token
                        </button>
                        <p class="text-xs text-success-main dark:text-neutral-400 mt-2 text-center flex items-center gap-1">
                            <iconify-icon icon="bxs:lock-alt" class="text-sm"></iconify-icon> Secure & Safe. We never access your credentials.
                        </p>
                    </div>

                    <!-- VERIFY BUTTON -->
                    <div id="verifyWrapper" class="hidden">
                        <div class="space-y-4 mb-6 select-none ">
                            <div class="flex items-center gap-4 mb-2">
                                <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 bg-cstm-primary-10 rounded-full">
                                    <iconify-icon icon="bx:bx-rocket" class="text-blue-600 text-lg"></iconify-icon>
                                </div>
                                <div>
                                    <h2 class="text-base font-bold text-gray-900">What happens next?</h2>
                                </div>
                            </div>

                            <!-- Step 1: Meta Tag Added -->
                            <div class="flex items-start gap-4">
                                <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 bg-cstm-primary-10 rounded-full">
                                    <iconify-icon icon="bx:bx-code-alt" class="text-emerald-600 text-lg"></iconify-icon>
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-800 mb-0.5">1. Meta Tag Added</h3>
                                    <p class="text-sm text-gray-500">We will add the verification meta tag to your Shopify website automatically.</p>
                                </div>
                            </div>

                            <!-- Step 2: Google Verification -->
                            <div class="flex items-start gap-4">
                                <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 bg-cstm-primary-10 rounded-full">
                                    <iconify-icon icon="bx:bx-search-alt" class="text-blue-600 text-lg"></iconify-icon>
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-800 mb-0.5">2. Google Verification</h3>
                                    <p class="text-sm text-gray-500">Google will verify your domain ownership.</p>
                                </div>
                            </div>

                            <!-- Step 3: Access Enabled -->
                            <div class="flex items-start gap-4">
                                <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 bg-cstm-primary-10 rounded-full">
                                    <iconify-icon icon="bx:bx-line-chart" class="text-purple-600 text-lg"></iconify-icon>
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-800 mb-0.5">3. Access Enabled</h3>
                                    <p class="text-sm text-gray-500">Your site will be connected with Google Search Console.</p>
                                </div>
                            </div>

                            <!-- Step 4: Submit Sitemap -->
                            <div class="flex items-start gap-4">
                                <div class="flex-shrink-0 flex items-center justify-center w-10 h-10 bg-cstm-primary-10 rounded-full">
                                    <iconify-icon icon="bx:bx-sitemap" class="text-orange-500 text-lg"></iconify-icon>
                                </div>
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-800 mb-0.5">4. Submit Sitemap</h3>
                                    <p class="text-sm text-gray-500">You'll be able to submit your sitemap and start tracking performance.</p>
                                </div>
                            </div>

                        </div>
                        <button id="verifyDomainBtn"
                            data-instance="<?= $instanceId ?>"
                            data-domain="<?= htmlspecialchars($normalizedSiteUrl) ?>"
                            data-metatoken="<?= htmlspecialchars($metaToken) ?>"
                            class="btn bg-success-600 text-white py-3 text-lg rounded-lg flex items-center justify-center gap-3 w-full mt-2 hover:bg-success-700">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M5 13l4 4L19 7" />
                            </svg>

                            Verify Domain
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<!-- POPUP OVERLAY -->
<!-- META TAG POPUP -->
<div id="metaPopup"
    class="fixed inset-0 bg-cstm-black-40 backdrop-blur-sm flex items-center justify-center z-50 hidden">

    <div class="bg-white dark:bg-neutral-900 rounded-2xl shadow-xl max-w-2xl w-full p-6 relative max-h-[90vh] overflow-y-auto">

        <!-- HEADER -->
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-semibold text-neutral-800 dark:text-neutral-100">
                Google Verification Meta Tag
            </h2>

            <button onclick="closePopup()" class="text-xl leading-none">
                ✕
            </button>
        </div>

        <?php if (!empty($metaToken)): ?>
            <label class="block text-sm font-semibold text-neutral-700 dark:text-neutral-200 mb-1">
                Verification Meta Tag
            </label>
            <p class="text-xs text-neutral-500 mb-2">
                <?php if ($isInstantSite): ?>
                    Paste this tag into Ecwid &rarr; <b>Website &rarr; SEO &rarr; Header meta tags and site verification</b>.
                <?php else: ?>
                    Paste this tag inside the <?= $isInstantSite ?> <code>&lt;head&gt;</code> of your website. Exact steps vary by platform &mdash; see the guide below.
                <?php endif; ?>
            </p>
            <div class="relative">
                <button onclick="copyMetaTag('popupMetaTagText')"
                    class="absolute top-3 right-3 text-xs px-3 py-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white z-10">
                    Copy
                </button>
                <textarea id="popupMetaTagText" readonly
                    class="w-full px-4 py-3 pr-20 rounded-xl bg-neutral-100 dark:bg-neutral-700 text-neutral-700 dark:text-neutral-200 border border-neutral-300 dark:border-neutral-600 h-24 resize-none font-mono text-sm"><meta name="google-site-verification" content="<?= htmlspecialchars($metaToken) ?>"></textarea>
            </div>

            <span id="copySuccess"
                class="hidden absolute -top-3 right-0 text-xs bg-success-100 text-success-600 px-3 py-1 rounded-lg">
                Copied!
            </span>
        <?php endif; ?>

        <div class="rounded-xl p-4 text-sm font-medium border block border-neutral-200 bg-neutral-50 text-neutral-700 mt-4">
            <h2 class="text-lg font-semibold text-neutral-800 dark:text-neutral-100 mb-2">
                <?php if ($isInstantSite): ?>
                    How to add this in Ecwid Instant Site
                <?php else: ?>
                    How to add the meta tag to your website
                <?php endif; ?>
            </h2>

            <?php if ($isInstantSite): ?>
                <ul class="space-y-2">
                    <li class="flex gap-2"><span class="font-bold">1.</span> Open your <b>Ecwid Control Panel</b>.</li>
                    <li class="flex gap-2"><span class="font-bold">2.</span> In the left sidebar, open <b>Website &rarr; SEO</b>.</li>
                    <li class="flex gap-2"><span class="font-bold">3.</span> Scroll to <b>SEO settings &rarr; Header meta tags and site verification</b>.</li>
                    <li class="flex gap-2"><span class="font-bold">4.</span> Click <b>Edit</b>.</li>
                    <li class="flex gap-2"><span class="font-bold">5.</span> Paste the full meta tag (above) into the textarea, then <b>Save</b>.</li>
                    <li class="flex gap-2"><span class="font-bold">6.</span> Return here and click <b>Verify Domain</b>.</li>
                </ul>
            <?php else: ?>
                <div class="rounded-lg border border-blue-200 bg-blue-50 text-blue-900 p-3 mb-3 text-xs leading-relaxed">
                    <p class="font-semibold mb-1">Heads up &mdash; steps depend on your website builder.</p>
                    <p>
                        Ecwid can be embedded in almost any platform (Wix, WordPress, Squarespace, Shopify, Webflow, custom HTML, etc.),
                        so the exact place to edit your site&rsquo;s <code>&lt;head&gt;</code> isn&rsquo;t the same for everyone.
                        If you&rsquo;re not sure which builder hosts your store, check Ecwid&rsquo;s guide:
                        <a href="https://support.ecwid.com/hc/en-us/articles/115004678945"
                           target="_blank" rel="noopener"
                           class="underline font-medium">
                            How to add Ecwid to your website
                        </a>.
                    </p>
                </div>

                <ul class="space-y-2">
                    <li class="flex gap-2"><span class="font-bold">1.</span> Copy the <b>Verification Meta Tag</b> above.</li>
                    <li class="flex gap-2"><span class="font-bold">2.</span> Open the editor for whichever platform hosts your website (Wix, WordPress, Squarespace, custom HTML, etc.).</li>
                    <li class="flex gap-2"><span class="font-bold">3.</span> Find where you can edit the <b><code>&lt;head&gt;</code></b> section of every page. A few common examples (your platform may differ):
                        <ul class="ml-4 mt-1 list-disc text-xs text-neutral-600 space-y-1">
                            <li><b>Wix:</b> Settings &rarr; Custom Code &rarr; Add Custom Code &rarr; Head.</li>
                            <li><b>WordPress:</b> use a Header/Footer plugin or your theme&rsquo;s <code>header.php</code>.</li>
                            <li><b>Squarespace:</b> Settings &rarr; Advanced &rarr; Code Injection &rarr; Header.</li>
                        </ul>
                        If your platform isn&rsquo;t listed, check its own documentation for &ldquo;custom code in head&rdquo; or &ldquo;site verification&rdquo;.
                    </li>
                    <li class="flex gap-2"><span class="font-bold">4.</span> Paste the meta tag inside the <code>&lt;head&gt;</code> and save/publish.</li>
                    <li class="flex gap-2"><span class="font-bold">5.</span> Return here and click <b>Verify Domain</b>.</li>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function openPopup() {
        document.getElementById('metaPopup').classList.remove('hidden');
    }

    function closePopup() {
        document.getElementById('metaPopup').classList.add('hidden');
    }

    function copyMetaTag(targetId) {
        const id = targetId || 'popupMetaTagText';
        const el = document.getElementById(id);
        if (!el) return;
        navigator.clipboard.writeText(el.value);

        const success = document.getElementById('copySuccess');
        if (success) {
            success.classList.remove('hidden');
            setTimeout(() => success.classList.add('hidden'), 1500);
        }
    }

     const continueStep2Btn = document.getElementById('continueStep2Btn');
  if (continueStep2Btn) {
    continueStep2Btn.addEventListener('click', function (e) {
      e.preventDefault();
      console.log("click in")

      continueStep2Btn.disabled = true;
      continueStep2Btn.classList.add('opacity-70', 'cursor-wait');

      const fd = new FormData();
      fd.append('step', '2');

      fetch(`${window.APP_BASE}/api/update-step.php`, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      })
        .catch(() => {
          // ignore any error silently
          console.log("hit api error")
        })
        .finally(() => {
          console.log("hit api su")
          window.location.href = 'setup-wizard.php?step=3';

        });
          console.log("click end")
    });
  }
</script>

<script>
    document.getElementById("copyVerificationToken")?.addEventListener("click", async function() {
        const textarea = document.getElementById("verificationToken");
        try {
            await navigator.clipboard.writeText(textarea.value);
            this.innerHTML = `
            <iconify-icon icon="bxs:check-circle" width="16" height="16"></iconify-icon>
            Copied
        `;
            setTimeout(() => {
                this.innerHTML = `
                <iconify-icon icon="solar:copy-outline" width="16" height="16"></iconify-icon>
                Copy
            `;
            }, 1800);
        } catch (err) {
            alert("Failed to copy.");
        }
    });
</script>