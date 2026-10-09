<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';
//require_once __DIR__ . '/../includes/credentials.php';

require_once __DIR__ . '/../includes/credentials.php';

SessionManager::startDatabaseSession();


if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

/* ----------------------------------------------------
   GENERAL SETTINGS
---------------------------------------------------- */
$stmtGeneral = $pdo->prepare("
    SELECT key_name, value
    FROM admin_settings
    WHERE key_name IN (
        'brand_name',
        'admin_email',
        'company_name',
        'logo_path',
        'logo_dark_path',
        'favicon_path'
    )
");
$stmtGeneral->execute();
$generalSettings = $stmtGeneral->fetchAll(PDO::FETCH_KEY_PAIR);

/* ----------------------------------------------------
   EMAIL SETTINGS
---------------------------------------------------- */
$stmtEmail = $pdo->prepare("
    SELECT key_name, value
    FROM admin_settings
    WHERE key_name IN ('sender_name', 'sender_email', 'brevo_api_key')
");
$stmtEmail->execute();
$emailSettings = $stmtEmail->fetchAll(PDO::FETCH_KEY_PAIR);


/* ----------------------------------------------------
   GOOGLE SETTINGS
---------------------------------------------------- */
$stmtGoogle = $pdo->query("SELECT * FROM google_settings LIMIT 1");
$googleSettings = $stmtGoogle->fetch(PDO::FETCH_ASSOC) ?: [];

if (!empty($googleSettings['client_secret'])) {
    $decrypted = decrypt($googleSettings['client_secret'], ENCRYPTION_KEY);
    $googleSettings['client_secret'] = $decrypted !== false ? $decrypted : '';
}

$title = 'Settings';
$subTitle = 'Settings';


$settings = [];

$stmt = $pdo->query("
    SELECT key_name, value
    FROM admin_settings
    WHERE key_name LIKE 'stripe_%'
");


/* ----------------------------------------------------
   GOHIGHLEVEL SETTINGS
---------------------------------------------------- */
$stmtGhl = $pdo->query("SELECT * FROM ecwid_settings LIMIT 1");
$ghlSettings = $stmtGhl->fetch(PDO::FETCH_ASSOC) ?: [];

if (!empty($ghlSettings['client_secret'])) {
    $decrypted = decrypt($ghlSettings['client_secret'], ENCRYPTION_KEY);
    $ghlSettings['client_secret'] = $decrypted !== false ? $decrypted : '';
}
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
   $decrypted = decrypt($row['value'], ENCRYPTION_KEY);
$settings[$row['key_name']] = $decrypted !== false ? $decrypted : $row['value'];
}


$script = '<script>
 // ======================== Upload Image Start =====================
 function readURL(input, previewElementId) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            // Check if the preview element is an image tag
            if ($(previewElementId).is("img")) {
                $(previewElementId).attr("src", e.target.result).show();
            } else {
              
                $(previewElementId).css("background-image", "url(" + e.target.result + ")");
            }
            // Hide and show for a nice fade-in effect
            $(previewElementId).hide();
            $(previewElementId).fadeIn(650);
        }
        reader.readAsDataURL(input.files[0]);
    }
 }

 // Bind to both logo and favicon upload inputs with their respective preview element IDs
 $("#imageUpload").change(function() {
     readURL(this, "#imagePreview");
 });
 $("#faviconUpload").change(function() {
     readURL(this, "#faviconPreview");
 });
 $("#logoDarkUpload").change(function() {
    readURL(this, "#logoDarkPreview");
});

 // ======================== Upload Image End =====================

 // ================== Password Show Hide Js Start ==========
 function initializePasswordToggle(toggleSelector) {
    $(toggleSelector).on("click", function() {
        $(this).toggleClass("ri-eye-off-line");
        var input = $($(this).attr("data-toggle"));
        if (input.attr("type") === "password") {
            input.attr("type", "text");
        } else {
            input.attr("type", "password");
        }
    });
 }
 // Call the function
 initializePasswordToggle(".toggle-password");
 // ========================= Password Show Hide Js End ===========================
 </script>'; ?>

<?php include './partials/layouts/layoutTop.php' ?>

<div class="card h-full border-0">
    <div class="card-body p-6 " style="min-height: calc(100vh - 72px - 68px - 60px - 48px);">

        <ul class="tab-style-gradient cstm-tab-style-gradient flex whitespace-nowrap overflow-x-auto text-sm font-medium text-center mb-5" id="default-tab" data-tabs-toggle="#default-tab-content" role="tablist">
            <li class="" role="presentation">
                <button class="py-2.5 px-4 border-b border-gray-200 dark:border-gray-600 font-semibold text-base inline-flex items-center gap-3 text-neutral-600" id="general-settings-tab" data-tabs-target="#general-settings" type="button" role="tab" aria-controls="general-settings" aria-selected="false">
                    General Settings
                </button>
            </li>
            <li class="" role="presentation">
                <button class="py-2.5 px-4 border-b font-semibold text-base inline-flex items-center gap-3 text-neutral-600 hover:text-gray-600 border-gray-200 dark:border-gray-600 dark:hover:text-gray-300" id="security-tab" data-tabs-target="#security" type="button" role="tab" aria-controls="security" aria-selected="false">
                    Security
                </button>
            </li>
            <li class="" role="presentation">
                <button class="py-2.5 px-4 border-b font-semibold text-base inline-flex items-center gap-3 text-neutral-600 hover:text-gray-600 border-gray-200 dark:border-gray-600 dark:hover:text-gray-300" id="email-settings-tab" data-tabs-target="#email-settings" type="button" role="tab" aria-controls="email-settings" aria-selected="false">
                    Email Settings
                </button>
            </li>
            <li class="" role="presentation">
                <button class="py-2.5 px-4 border-b font-semibold text-base inline-flex items-center gap-3 text-neutral-600 hover:text-gray-600 border-gray-200 dark:border-gray-600 dark:hover:text-gray-300" id="stripe-integration-tab" data-tabs-target="#stripe-integration" type="button" role="tab" aria-controls="stripe-integration" aria-selected="false">
                     Stripe Integration
                </button>
            </li>
            <li role="presentation">
                <button class="py-2.5 px-4 border-b font-semibold text-base inline-flex items-center"
                    id="google-settings-tab"
                    data-tabs-target="#google-settings"
                    type="button"
                    role="tab">
                    Google Integration
                </button>
            </li>

            <li role="presentation">
    <button class="py-2.5 px-4 border-b font-semibold text-base inline-flex items-center"
        id="ghl-settings-tab"
        data-tabs-target="#ghl-settings"
        type="button"
        role="tab">
        Ecwid  Integration
    </button>
</li>

        </ul>

        <div id="default-tab-content">

            <!--  General =============================================================== -->
            <div class="hidden" id="general-settings" role="tabpanel" aria-labelledby="general-settings-tab">
                <form action="./core/UpdateGeneralSettings.php" method="POST" class="space-y-6" enctype="multipart/form-data">


                    <div class="flex flex-col gap-6">
                        <div class="grid grid-cols-1 sm:grid-cols-2 2xl:grid-cols-4 gap-6">
                            <div>
                                <label for="brandName" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Brand Name <span class="text-danger-600 dark:text-danger-600">*</span></label>
                                <input type="text" class="form-control rounded-lg" id="brandName" name="brandName" value="<?= htmlspecialchars($generalSettings['brand_name'] ?? '') ?>" placeholder="Enter Brand Name">
                            </div>
                            <div>
                                <label for="adminEmail" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Admin Email <span class="text-danger-600 dark:text-danger-600">*</span></label>
                                <input type="email" class="form-control rounded-lg" id="adminEmail" name="adminEmail" value="<?= htmlspecialchars($generalSettings['admin_email'] ?? '') ?>" placeholder="Enter Admin Email">
                            </div>
                            <div>
                                <label for="companyName" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Company Name</label>
                                <input type="text" class="form-control rounded-lg" id="companyName" name="companyName" value="<?= htmlspecialchars($generalSettings['company_name'] ?? '') ?>" placeholder="Enter Company Name">
                            </div>
                            <div>
                                <label for="clarity_id" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Clarity ID</label>
                                <input type="text" class="form-control rounded-lg" id="clarity_id" name="clarity_id" value="<?= htmlspecialchars($generalSettings['clarity_id'] ?? '') ?>" placeholder="Enter Company Name">
                            </div>
                        </div>


                        <div class="flex flex-wrap gap-6">

                            <!-- Favicon Upload -->
                            <div class="group w-full sm:w-auto">
                                <label for="faviconUpload" class="block text-sm font-semibold text-gray-900 dark:text-neutral-200 mb-3">
                                    Favicon
                                </label>
                                <div class="relative w-fit mx-auto">
                                    <div class="flex items-start gap-4 flex-col">
                                        <!-- Preview Box -->
                                        <div class="border-2 border-dashed border-gray-300 dark:border-neutral-600 flex h-48 items-center justify-center overflow-hidden relative rounded-lg transition-all w-48">
                                            <?php if (!empty($generalSettings['favicon_path'])): ?>
                                                <img id="faviconPreview" src="<?= htmlspecialchars($generalSettings['favicon_path']) ?>" alt="Favicon Preview" class="object-contain h-full w-full p-2">
                                            <?php else: ?>
                                                <div id="faviconPreviewPlaceholder" class="flex flex-col items-center justify-center text-gray-400 dark:text-neutral-500">
                                                    <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                    </svg>
                                                    <span class="text-[10px] font-medium">Icon</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Upload Input -->
                                        <div class="flex-1 w-48">
                                            <label for="faviconUpload" class="relative block cursor-pointer">
                                                <div class="bg-white border-2 border-gray-300 dark:bg-neutral-800 dark:border-neutral-600 dark:hover:bg-neutral-750 px-4 py-3 rounded-lg">
                                                    <div class="flex flex-col items-center justify-between">
                                                        <div class="">
                                                            <p class="text-sm font-medium text-gray-700 dark:text-neutral-200">Upload Favicon</p>
                                                        </div>
                                                        <span class="text-xs font-medium text-cstm-primary">Browse</span>
                                                    </div>
                                                </div>
                                                <input
                                                    type="file"
                                                    id="faviconUpload"
                                                    name="favicon"
                                                    accept="image/*"
                                                    class="sr-only">
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Logo Upload -->
                            <div class="group w-full sm:w-auto">
                                <label for="imageUpload" class="block text-sm font-semibold text-gray-900 dark:text-neutral-200 mb-3">
                                    Logo (Light Mode)
                                </label>
                                <div class="relative w-fit mx-auto">
                                    <div class="flex items-start gap-4 flex-col">
                                        <!-- Preview Box -->
                                        <div class="border-2 border-dashed border-gray-300 dark:border-neutral-600 flex h-48 items-center justify-center overflow-hidden relative rounded-lg transition-all w-48">
                                            <?php if (!empty($generalSettings['logo_path'])): ?>
                                                <img id="imagePreview" src="<?= htmlspecialchars($generalSettings['logo_path']) ?>" alt="Logo Preview" class="object-contain h-full w-full p-2">
                                            <?php else: ?>
                                                <div class="flex flex-col items-center justify-center text-gray-400 dark:text-neutral-500">
                                                    <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                    </svg>
                                                    <span class="text-[10px] font-medium">Logo</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Upload Input -->
                                        <div class="flex-1 w-48">
                                            <label for="imageUpload" class="relative block cursor-pointer">
                                                <div class="bg-white border-2 border-gray-300 dark:bg-neutral-800 dark:border-neutral-600 dark:hover:bg-neutral-750 px-4 py-3 rounded-lg   ">
                                                    <div class="flex flex-col items-center justify-between">
                                                        <p class="text-sm font-medium text-gray-700 dark:text-neutral-200">Upload Logo</p>
                                                        <span class="text-xs font-medium text-cstm-primary">Browse</span>
                                                    </div>
                                                </div>
                                                <input
                                                    type="file"
                                                    id="imageUpload"
                                                    name="logo"
                                                    accept="image/*"
                                                    class="sr-only">
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Dark Logo Upload -->
                            <div class="group w-full sm:w-auto">
                                <label for="logoDarkUpload" class="block text-sm font-semibold text-gray-900 dark:text-neutral-200 mb-3">
                                    Logo (Dark Mode)
                                </label>
                                <div class="relative w-fit mx-auto">
                                    <div class="flex items-start gap-4 flex-col">
                                        <!-- Preview Box with dark background indicator -->
                                        <div class="border-2 border-dashed border-gray-300 dark:border-neutral-600 flex h-48 items-center justify-center overflow-hidden relative rounded-lg transition-all w-48">
                                            <?php if (!empty($generalSettings['logo_dark_path'])): ?>
                                                <img id="logoDarkPreview" src="<?= htmlspecialchars($generalSettings['logo_dark_path']) ?>" alt="Dark Logo Preview" class="object-contain h-full w-full p-2">
                                            <?php else: ?>
                                                <div id="logoDarkPreviewPlaceholder" class="flex flex-col items-center justify-center text-gray-400">
                                                    <svg class="w-6 h-6 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                    </svg>
                                                    <span class="text-[10px] font-medium">Logo</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Upload Input -->
                                        <div class="flex-1 w-48">
                                            <label for="logoDarkUpload" class="relative block cursor-pointer">
                                                <div class="bg-white border-2 border-gray-300 dark:bg-neutral-800 dark:border-neutral-600 dark:hover:bg-neutral-750 px-4 py-3 rounded-lg">
                                                    <div class="flex flex-col items-center justify-between">
                                                        <p class="text-sm font-medium text-gray-700 dark:text-neutral-200">Upload Dark Logo</p>
                                                        <span class="text-xs font-medium text-cstm-primary">Browse</span>
                                                    </div>
                                                </div>
                                                <input
                                                    type="file"
                                                    id="logoDarkUpload"
                                                    name="logo_dark"
                                                    accept="image/*"
                                                    class="sr-only">
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>

                    </div>


                    <div class="flex items-center justify-center gap-3 mt-8">
                        <button type="button" class="btn btn-cstm-muted">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-cstm-primary">
                            Save
                        </button>
                    </div>
                </form>
            </div>

            <!--  Security =============================================================== -->
            <div class="hidden" id="security" role="tabpanel" aria-labelledby="security-tab">
                <form action="./core/ChangePassword.php" method="POST" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-y-2 gap-x-6 mb-2">
                        <div class="">
                            <label for="current-password" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Current Password <span class="text-danger-600 dark:text-danger-600">*</span></label>
                            <div class="relative">
                                <input type="password" class="form-control rounded-lg" id="current-password" name="current_password" placeholder="Enter Current Password*">
                                <span class="toggle-password ri-eye-line cursor-pointer absolute end-0 top-1/2 -translate-y-1/2 me-4 text-secondary-light" data-toggle="#current-password"></span>
                            </div>
                        </div>
                        <div class="">
                            <label for="new-password" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">New Password <span class="text-danger-600 dark:text-danger-600">*</span></label>
                            <div class="relative">
                                <input type="password" class="form-control rounded-lg" id="new-password" name="new_password" placeholder="Enter New Password*">
                                <span class="toggle-password ri-eye-line cursor-pointer absolute end-0 top-1/2 -translate-y-1/2 me-4 text-secondary-light" data-toggle="#new-password"></span>
                            </div>
                        </div>
                        <div class="">
                            <label for="confirm-password" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Confirmed Password <span class="text-danger-600 dark:text-danger-600">*</span></label>
                            <div class="relative">
                                <input type="password" class="form-control rounded-lg" id="confirm-password" name="confirm_password" placeholder="Confirm Password*">
                                <span class="toggle-password ri-eye-line cursor-pointer absolute end-0 top-1/2 -translate-y-1/2 me-4 text-secondary-light" data-toggle="#confirm-password"></span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-center gap-3 mt-8">
                        <button type="button" class="btn btn-cstm-muted">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-cstm-primary">
                            Save
                        </button>
                    </div>
                </form>
            </div>

            <!-- Email Setting ========================================================================== -->
            <div class="hidden" id="email-settings" role="tabpanel" aria-labelledby="email-settings-tab">
                <form action="./core/UpdateEmailSettings.php" class="grid grid-cols-1 lg:grid-cols-2 gap-x-6 gap-y-2" method="POST">
                    <div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 mb-2">
                            <div>
                                <label for="senderName" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Sender Name <span class="text-danger-600 dark:text-danger-600">*</span></label>
                                <input type="text" class="form-control rounded-lg" id="senderName" name="senderName" value="<?= htmlspecialchars($emailSettings['sender_name'] ?? '') ?>" placeholder="Enter Sender Name">
                            </div>
                            <div>
                                <label for="senderEmail" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Sender Email <span class="text-danger-600 dark:text-danger-600">*</span></label>
                                <input type="email" class="form-control rounded-lg" id="senderEmail" name="senderEmail" value="<?= htmlspecialchars($emailSettings['sender_email'] ?? '') ?>" placeholder="Enter Sender Email">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 mb-2">
                            <div class="col-span-2">
                                <label for="brevoApiKey" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Brevo API Key <span class="text-danger-600 dark:text-danger-600">*</span></label>
                                <input type="text" class="form-control rounded-lg" id="brevoApiKey" name="brevoApiKey" value="<?= htmlspecialchars($emailSettings['brevo_api_key'] ?? '') ?>" placeholder="Enter Brevo API Key">
                            </div>
                        </div>
                        <div class="flex items-center justify-center gap-3 mt-8">
                            <button type="button" class="btn btn-cstm-muted">
                                Cancel
                            </button>
                            <button type="submit" name="action" value="save" class="btn btn-cstm-primary">
                                Save
                            </button>
                        </div>
                    </div>

                    <div class="lg:border-l border-gray-200 lg:pl-5 dark:border-gray-600">
                        <label for="testEmail" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Send Test Email</label>
                        <div class="flex flex-col items-center gap-3">
                            <input type="email" class="form-control rounded-lg" id="testEmail" name="testEmail" placeholder="Enter email address">
                            <button type="submit" name="action" value="test" class="btn btn-cstm-primary">
                                Send
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Wix Integration ========================================================================= -->
           <div class="hidden cstm-stripe-integration" id="stripe-integration" role="tabpanel" aria-labelledby="stripe-integration-tab">
                <form action="<?= APP_BASE ?>/admin/core/Update_stripe_Integration.php" method="POST">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">

                        <div>
                            <label class="font-semibold text-sm">Stripe API Key </label>
                            <input type="text" name="stripe_api_key"
                                class="form-control"
                                value="<?= htmlspecialchars($settings['stripe_api_key'] ?? '') ?>">
                        </div>

                        <div>
                            <label class="font-semibold text-sm">Stripe Secret Api Key </label>
                            <input type="text" name="stripe_secret_api_key"
                                class="form-control"
                                value="<?= htmlspecialchars($settings['stripe_secret_api_key'] ?? '') ?>">
                        </div>

                        
                        <div>
                            <label class="font-semibold text-sm">Webhook Endpoint Secret </label>
                            <input type="text" name="stripe_webhook_secret"
                                class="form-control"
                                value="<?= htmlspecialchars($settings['stripe_webhook_secret'] ?? '') ?>">
                        </div>

                        <div>
                            <label class="font-semibold text-sm">Billing Portal Config Key</label>
                            <input type="text" name="stripe_portal_config_key"
                                class="form-control"
                                value="<?= htmlspecialchars($settings['stripe_portal_config_key'] ?? '') ?>">
                        </div>

                        

                    </div>

                    <div class="flex justify-center gap-4">
                        <button type="submit" class="btn btn-cstm-primary">Save</button>
                    </div>

                </form>

            </div>

            <div class="hidden" id="google-settings" role="tabpanel" aria-labelledby="google-settings-tab">
                <form action="<?= APP_BASE ?>/admin/core/UpdateGoogleSettings.php" method="POST">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="font-semibold text-sm">Client ID <span class="text-danger-600">*</span></label>
                            <input type="text" name="client_id" class="form-control rounded-lg"
                                value="<?= htmlspecialchars($googleSettings['client_id'] ?? '') ?>"
                                placeholder="Enter Google Client ID">
                        </div>

                        <div>
                            <label class="font-semibold text-sm">Client Secret <span class="text-danger-600">*</span></label>
                            <input type="text" name="client_secret" class="form-control rounded-lg"
                                value="<?= htmlspecialchars($googleSettings['client_secret'] ?? '') ?>"
                                placeholder="Enter Google Client Secret">
                        </div>

                        <div>
                            <label class="font-semibold text-sm">Redirect URI <span class="text-danger-600">*</span></label>
                            <input type="text" name="redirect_uri" class="form-control rounded-lg"
                                value="<?= htmlspecialchars($googleSettings['redirect_uri'] ?? '') ?>"
                                placeholder="Enter Redirect URI">
                        </div>
                    </div>

                    <div class="flex items-center justify-center gap-3 mt-8">
                        <button type="button" class="btn btn-cstm-muted">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-cstm-primary">
                            Save
                        </button>
                    </div>

                </form>
            </div>

            <div class="hidden" id="ghl-settings" role="tabpanel" aria-labelledby="ghl-settings-tab">
    <form action="<?= APP_BASE ?>/admin/core/UpdateGhlSettings.php" method="POST">

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">

    <div>
        <label class="font-semibold text-sm">
            Client ID <span class="text-danger-600">*</span>
        </label>
        <input type="text"
               name="ghl_client_id"
               class="form-control rounded-lg"
               value="<?= htmlspecialchars($ghlSettings['client_id'] ?? '') ?>">
    </div>

    <div>
        <label class="font-semibold text-sm">
            Client Secret <span class="text-danger-600">*</span>
        </label>
        <input type="text"
               name="ghl_client_secret"
               class="form-control rounded-lg"
               value="<?= htmlspecialchars($ghlSettings['client_secret'] ?? '') ?>">
    </div>

    <div>
    <label class="font-semibold text-sm">
        Redirect URI <span class="text-danger-600">*</span>
    </label>
    <input type="text"
           name="ghl_redirect_uri"
           class="form-control rounded-lg"
           value="<?= htmlspecialchars($ghlSettings['redirect_uri'] ?? '') ?>">
</div>

</div>

        <div class="flex items-center justify-center gap-3 mt-8">
            <button type="button" class="btn btn-cstm-muted">
                Cancel
            </button>
            <button type="submit" class="btn btn-cstm-primary">
                Save
            </button>
        </div>

    </form>
</div>

        </div>
    </div>
</div>

<?php include './partials/layouts/layoutBottom.php' ?>