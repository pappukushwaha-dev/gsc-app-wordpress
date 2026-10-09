<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/google/get_account.php';
require_once __DIR__ . '/includes/google/get_bigcommerce_site.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* -----------------------------------------
   Bigcommerce SESSION GUARD
----------------------------------------- */
$instanceId = $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;
if (!$instanceId) {
    header("Location: sign-in.php");
    exit;
}

/* -----------------------------------------
   PAGE META
----------------------------------------- */
$title = "Domain Management";
$subTitle = "SEO Tools";

/* -----------------------------------------
   GOOGLE ACCOUNT
----------------------------------------- */
$google = getGoogleAccountByShop($instanceId);
$isGoogleConnected = (
    $google &&
    (
        !empty($google['access_token']) ||
        !empty($google['refresh_token'])
    )
);

/* -----------------------------------------
   Bigcommerce SITE
----------------------------------------- */
$site = getWpSiteByInstance($instanceId);
$siteUrl = null;
if (!empty($site['domain'])) {
    $siteUrl = 'https://' . trim($site['domain'], '/');
}

// -----------------------------------------
// HARD FALLBACK (DO NOT REMOVE)
// -----------------------------------------
if (!$siteUrl) {
    $stmt = $pdo->prepare("
        SELECT domain
        FROM WpSite
        WHERE instance_id = ?
        LIMIT 1
    ");
    $stmt->execute([$instanceId]);
    $shopDomain = $stmt->fetchColumn();
    if ($shopDomain) {
        $siteUrl = 'https://' . trim($shopDomain, '/');
    }
}

/* -----------------------------------------
   DOMAIN VERIFICATION
----------------------------------------- */
$stmt = $pdo->prepare("
    SELECT *
    FROM gsc_domain_verifications
    WHERE instance_id = ?
    LIMIT 1
");
$stmt->execute([$instanceId]);
$domainRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$verifyStatus = strtolower(trim($domainRow['verification_status'] ?? 'pending'));
$checkedAt    = $domainRow['verification_checked_at'] ?? null;
$verifiedAt   = $domainRow['verification_verified_at'] ?? null;
$metaTag      = $domainRow['meta_tag'] ?? '';
$metaToken    = $domainRow['meta_token'] ?? '';

/* -----------------------------------------
   SITEMAP
----------------------------------------- */
$primarySitemap = $siteUrl
    ? $siteUrl . '/sitemap.xml'
    : null;
include './partials/layouts/layoutTop.php';
?>

<script>
  window.APP_BASE = "/wordpress/googlesearchconsole";
</script>
<div class="card h-full rounded-lg border-0">
<div class="card-body p-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <?php if ($isGoogleConnected): ?>               
                <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 overflow-hidden">
                    <div class="px-6 py-4 border-b border-neutral-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-800 flex justify-between items-center">
                        <h3 class="text-base font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <span class="w-10 h-10 bg-cstm-primary rounded-lg  flex justify-center items-center">
                                <iconify-icon icon="gridicons:domains" class="text-2xl text-white"></iconify-icon>
                            </span>
                            Domain Overview
                        </h3>
                        <?php if (strtolower($verifyStatus) === 'verified'): ?>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-success-100 text-success-600 dark:bg-success-600/25 dark:text-success-400 border border-success-400 dark:border-success-600">
                                <span class="w-1.5 h-1.5 bg-success-500 rounded-full mr-1.5"></span> Verified
                            </span>
                        <?php elseif (strtolower($verifyStatus) === 'failed'): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-danger-100 text-danger-800 dark:bg-danger-900/30 dark:text-danger-400 border border-danger-200 dark:border-danger-800">
                                <span class="w-1.5 h-1.5 bg-danger-500 rounded-full mr-1.5"></span> Failed
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-warning-100 text-warning-800 dark:bg-warning-900/30 dark:text-warning-400 border border-warning-200 dark:border-warning-800">
                                <span class="w-1.5 h-1.5 bg-warning-500 rounded-full mr-1.5"></span> Pending
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="p-6">
                        <div class="mb-6">
                            <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1.5">Connected Website URL</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                    </svg>
                                </div>
                                <input type="text" class="block w-full pl-10 px-3 py-2 sm:text-sm bg-gray-50 dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-600 rounded-lg text-gray-500 dark:text-gray-400 cursor-not-allowed focus:ring-0"
                                    readonly value="<?= htmlspecialchars($siteUrl) ?>">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="p-3 bg-gray-50 dark:bg-neutral-700 rounded-lg border border-neutral-100 dark:border-neutral-600">
                                <span class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Last Checked</span>
                                <span class="block text-sm font-bold text-gray-900 dark:text-white"><?= $checkedAt ? date('M j, Y H:i', strtotime($checkedAt)) : 'Never' ?></span>
                            </div>
                            <div class="p-3 bg-gray-50 dark:bg-neutral-700 rounded-lg border border-neutral-100 dark:border-neutral-600">
                                <span class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Verified At</span>
                                <span class="block text-sm font-bold text-gray-900 dark:text-white"><?= $verifiedAt ? date('M j, Y', strtotime($verifiedAt)) : 'N/A' ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 overflow-hidden">
                    <div class="px-6 py-4 border-b border-neutral-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-800 flex justify-between items-center">
                        <h3 class="text-base font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <span class="w-10 h-10 bg-cstm-primary rounded-lg  flex justify-center items-center">
                                <iconify-icon icon="jam:code" class="text-2xl text-white"></iconify-icon>
                            </span>
                            HTML Tag Verification
                        </h3>
                    </div>
                    <div class="p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                            This meta tag is automatically Verify into your Bigcommerce site's header to prove ownership to Google.
                        </p>
                        <div class="relative group mb-6">
                            <div class="absolute top-2 right-2 text-[10px] text-gray-500 font-mono dark:text-gray-300">HTML</div>
                            <pre class="block w-full p-4 text-xs font-mono bg-gray-200 rounded-lg border border-gray-300 overflow-x-auto whitespace-pre-wrap dark:bg-neutral-800 dark:border-neutral-600"><?= htmlspecialchars($metaTag) ?></pre>
                        </div>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <button id="reinjectionBtn"
                                data-instance="<?= $instanceId ?>"
                                data-domain="<?= htmlspecialchars($siteUrl) ?>"
                                data-token="<?= htmlspecialchars($metaToken) ?>"
                                class="flex-1 inline-flex justify-center items-center btn btn-cstm-primary gap-2">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                                </svg>
                                Re-Verify
                            </button>
                            <button id="recheckBtn"
                                data-instance="<?= $instanceId ?>"
                                class="flex-1 inline-flex justify-center items-center btn btn-cstm-muted gap-2">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Check Status
                            </button>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="h-full flex flex-col items-center justify-center bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 p-12 text-center min-h-[400px]">
                    <div class="w-20 h-20 bg-neutral-100 dark:bg-neutral-700/50 rounded-full flex items-center justify-center mb-6">
                        <iconify-icon icon="solar:lock-keyhole-bold-duotone" class="text-4xl text-neutral-400 dark:text-neutral-500"></iconify-icon>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Domain Tools Locked</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto mb-8">
                        To access domain verification and SEO tools, you must first connect your Google Account.
                    </p>                    
                    <div class="lg:hidden">
                        <a href="setup-wizard.php?step=1" class="px-6 py-2.5 bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-lg transition-colors">
                            Connect Google Account
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        </div>
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 overflow-hidden">
                <div class="px-6 py-4 border-b border-neutral-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-800 flex justify-between items-center">
                    <h3 class="text-base font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <span class="w-10 h-10 bg-cstm-primary rounded-lg  flex justify-center items-center">
                            <iconify-icon icon="dashicons:google" class="text-2xl text-white"></iconify-icon>
                        </span>
                        Google Account
                    </h3>
                </div>

                <div class="p-6">
                    <?php if ($isGoogleConnected): ?>
                        <div class="bg-primary-50 drk-bg-cstm-primary-30 rounded-lg p-4 mb-4 border border-cstm-primary">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-800 flex items-center justify-center text-primary-600 dark:text-primary-300 font-bold text-xs border border-primary-200 dark:border-primary-700">
                                    <?= strtoupper(substr($google['email'], 0, 1)) ?>
                                </div>
                                <div class="overflow-hidden">
                                    <div class="text-sm font-bold text-gray-900 dark:text-white truncate"><?= htmlspecialchars($google['email']) ?></div>
                                    <div class="text-xs text-success-600 dark:text-success-400 flex items-center gap-1">
                                        <span class="w-1 h-1 bg-success-600 rounded-full"></span> Connected
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button id="disconnectDirectBtn" class="w-full flex items-center justify-center px-4 py-2.5 border border-danger-200 dark:border-danger-900/50 shadow-sm text-sm font-medium rounded-lg text-danger-700 dark:text-danger-400 bg-white dark:bg-neutral-800 hover:bg-danger-50 dark:hover:bg-danger-900/20 transition-colors">
                            Disconnect Account
                        </button>
                    <?php else: ?>
                        <div class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                            Connect your Google account to enable search console features.
                        </div>
                        <a href="setup-wizard.php?step=1" class="block w-full text-center px-4 py-2.5 border border-transparent text-sm font-medium rounded-lg shadow-sm text-white bg-primary-600 hover:bg-primary-700 transition-colors">
                            Connect Google
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white dark:bg-neutral-800 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-600 overflow-hidden <?= !$isGoogleConnected ? 'opacity-50 pointer-events-none grayscale' : '' ?>">
                <div class="px-6 py-4 border-b border-neutral-200 dark:border-neutral-600 bg-gray-50 dark:bg-neutral-800 flex justify-between items-center">
                    <h3 class="text-base font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <span class="w-10 h-10 bg-cstm-primary rounded-lg  flex justify-center items-center">
                            <iconify-icon icon="fa6-solid:sitemap" class="text-2xl text-white"></iconify-icon>
                        </span>
                        Sitemap Shortcut
                    </h3>
                </div>
                <div class="p-6">
                    <div class="mb-4">
                        <input type="text" class="block w-full px-3 py-2.5 text-sm bg-gray-50 dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-600 rounded-lg text-gray-500 dark:text-gray-400 focus:ring-0" readonly value="<?= htmlspecialchars($primarySitemap) ?>">
                    </div>
                   <a href="sitemap.php?instance_id=<?= urlencode($instanceId) ?>"
   class="flex items-center justify-center w-full px-4 py-2.5 border border-neutral-300 dark:border-neutral-600 shadow-sm text-sm font-medium rounded-lg text-gray-700 dark:text-gray-200 bg-white dark:bg-neutral-800 hover:bg-gray-50 dark:hover:bg-neutral-700 transition-colors">
    Manage Sitemaps
</a>
                </div>
            </div>
        </div>
        </div>
</div>

<div id="domain-disconnect-overlay" class="fixed inset-0 bg-neutral-900/60 backdrop-blur-sm z-[9998] hidden transition-opacity duration-300 opacity-0"></div>
<div id="domain-disconnect-modal" class="fixed top-1/2 left-1/2 z-[9999] w-full max-w-2xl bg-white dark:bg-neutral-800 rounded-2xl shadow-2xl p-6 hidden transition-all duration-300 opacity-0 scale-95 -translate-x-1/2 -translate-y-1/2 border border-neutral-200 dark:border-neutral-600">
    <div class="text-center">
        <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-danger-100 dark:bg-danger-900/30 mb-4">
            <svg class="h-6 w-6 text-danger-600 dark:text-danger-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        </div>
        <h3 class="text-lg font-bold text-gray-900 dark:text-white" id="modal-title">Disconnect Account?</h3>
        <div class="mt-2">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Are you sure you want to disconnect? This will stop data synchronization with Google Search Console.
            </p>
        </div>
    </div>
    <div class="mt-6 grid grid-cols-2 gap-3">
        <button id="domainCancelDisconnect" type="button" class="w-full inline-flex justify-center rounded-lg border border-neutral-300 dark:border-neutral-600 shadow-sm px-4 py-2.5 bg-white dark:bg-neutral-800 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-neutral-700 transition-colors">
            Cancel
        </button>
        <button id="domainConfirmDisconnect" type="button" class="w-full inline-flex justify-center rounded-lg border border-transparent shadow-sm px-4 py-2.5 bg-danger-600 text-sm font-medium text-white hover:bg-danger-700 transition-colors">
            Disconnect
        </button>
    </div>
</div>

<style>
    .loader {
        border: 2px solid rgba(255, 255, 255, 0.1);
        border-left-color: currentColor;
        border-radius: 50%;
        width: 1rem;
        height: 1rem;
        animation: spin 1s linear infinite;
        display: inline-block;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }
</style>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        const openBtn = document.getElementById("disconnectDirectBtn");
        const overlay = document.getElementById("domain-disconnect-overlay");
        const modal = document.getElementById("domain-disconnect-modal");
        const cancelBtn = document.getElementById("domainCancelDisconnect");
        const confirmBtn = document.getElementById("domainConfirmDisconnect");
        function openModal() {
            overlay.classList.remove("hidden");
            modal.classList.remove("hidden");
            setTimeout(() => {
                overlay.classList.remove("opacity-0");
                modal.classList.remove("opacity-0", "scale-95");
                modal.classList.add("opacity-100", "scale-100");
            }, 10);
        }
        function closeModal() {
            overlay.classList.remove("opacity-100");
            modal.classList.remove("opacity-100", "scale-100");
            modal.classList.add("opacity-0", "scale-95");
            setTimeout(() => {
                overlay.classList.add("hidden");
                modal.classList.add("hidden");
            }, 300);
        }

        if (openBtn) openBtn.addEventListener("click", openModal);
        if (overlay) overlay.addEventListener("click", closeModal);
        if (cancelBtn) cancelBtn.addEventListener("click", closeModal);
        if (confirmBtn) {
            confirmBtn.addEventListener("click", async () => {
                const originalText = confirmBtn.innerHTML;
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = `<span class="loader mr-2"></span> Processing...`;
                try {
                    const res = await fetch(window.APP_BASE + "/includes/google/disconnect.php", {
                        method: "POST"
                    });
                    if (res.ok) window.location.href = "setup-wizard.php?step=1";
                    else throw new Error("Failed to disconnect");
                } catch (err) {
                    alert("Error: " + err.message);
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = originalText;
                }
            });
        }

        // -- Reinject Logic --
        const reinjectionBtn = document.getElementById("reinjectionBtn");
        if (reinjectionBtn) {
            reinjectionBtn.addEventListener("click", async () => {
                const originalHTML = reinjectionBtn.innerHTML;
                reinjectionBtn.disabled = true;
                reinjectionBtn.innerHTML = `<span class="loader mr-2"></span> Injecting...`;
                try {
                    const res = await fetch(window.APP_BASE + "/api/google/verify_domain.php", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json"
                        },
                        body: JSON.stringify({
                            instanceId: reinjectionBtn.dataset.instance,
                            siteUrl: reinjectionBtn.dataset.domain,
                            metaToken: reinjectionBtn.dataset.token
                        })
                    });
                    const json = await res.json();
                    reinjectionBtn.disabled = false;
                    reinjectionBtn.innerHTML = originalHTML;
                    if (json.success) {
                        reinjectionBtn.classList.remove('bg-primary-600', 'hover:bg-primary-700');
                        reinjectionBtn.classList.add('bg-success-600', 'hover:bg-success-700');
                        reinjectionBtn.innerHTML = `<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> Verified!`;
                        setTimeout(() => {
                            reinjectionBtn.classList.add('bg-primary-600', 'hover:bg-primary-700');
                            reinjectionBtn.classList.remove('bg-success-600', 'hover:bg-success-700');
                            reinjectionBtn.innerHTML = originalHTML;
                        }, 2000);
                    } else {
                        alert(json.error || "Injection failed");
                    }
                } catch (e) {
                    reinjectionBtn.disabled = false;
                    reinjectionBtn.innerHTML = originalHTML;
                    alert("Network error");
                }
            });
        }

        // -- Recheck Logic --
        const recheckBtn = document.getElementById("recheckBtn");
        if (recheckBtn) {
            recheckBtn.addEventListener("click", async () => {
                const originalHTML = recheckBtn.innerHTML;
                recheckBtn.disabled = true;
                recheckBtn.innerHTML = `<span class="loader mr-2 border-gray-400"></span> Checking...`;
                try {
                    const res = await fetch(window.APP_BASE + "/api/google/check_verification.php?instanceId=" + recheckBtn.dataset.instance);
                    const json = await res.json();
                    if (!res.ok || !json.success) {
                        throw new Error(json.error || 'API request failed');
                    }
                    if (json.verified) {
                        location.reload();
                    } else {
                        recheckBtn.disabled = false;
                        recheckBtn.innerHTML = originalHTML;
                        alert("Status: Pending. Google hasn't verified the tag yet.");
                        setTimeout(() => {
                            location.reload();
                        }, 500);
                    }
                } catch (e) {
                    recheckBtn.disabled = false;
                    recheckBtn.innerHTML = originalHTML;
                    alert("Error checking status: " + (e.message || "Unknown error"));
                }
            });
        }
    });
</script>

<?php include './partials/layouts/layoutBottom.php'; ?>