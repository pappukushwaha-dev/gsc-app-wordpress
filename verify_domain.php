<?php
// verify_domain.php (fixed, complete)
declare(strict_types=1);

// ---- Session cookie must be iframe-safe (Wix embeds) ----
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

ini_set('session.cookie_samesite', 'None'); // allow cookies in iframes
ini_set('session.cookie_secure', '1');      // required with SameSite=None (serve over HTTPS)
ini_set('session.cookie_path', '/');

session_start();

require_once 'classes/User.php';
require_once 'classes/Database.php';
require_once 'classes/GoogleSearchConsole.php';

/* ------------------------------------------------------------------
   Instance auto-login (mirror of dashboard.php)
------------------------------------------------------------------ */
function forceLoginUser(User $user, int $userId): void
{
    if (method_exists($user, 'loginById')) {
        $user->loginById($userId);
        return;
    }
    if (method_exists($user, 'forceLogin')) {
        $user->forceLogin($userId);
        return;
    }
    $_SESSION['user_id'] = $userId; // fallback
}

$db   = new Database();
$user = new User();

// Accept instanceId from GET or reuse stored
$instanceId = trim((string)($_GET['instanceId'] ?? ($_SESSION['wix_instance_id'] ?? '')));
if ($instanceId !== '') {
    $_SESSION['wix_instance_id'] = $instanceId;

    try {
        $row = $db->query(
            "SELECT id FROM users WHERE wix_store_id = ? LIMIT 1",
            [$instanceId]
        )->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['id'])) {
            $current   = $user->getCurrentUser();
            $currentId = (is_array($current) && isset($current['id'])) ? (int)$current['id'] : 0;
            if ($currentId !== (int)$row['id']) {
                forceLoginUser($user, (int)$row['id']);
            }
        }
    } catch (Throwable $e) {
        error_log("verify_domain: instanceId map error: " . $e->getMessage());
    }
}

// Auth gate (preserve instanceId on redirect)
if (!$user->isLoggedIn() && empty($_SESSION['user_id'])) {
    $q = $instanceId ? ('?instanceId=' . urlencode($instanceId)) : '';
    header('Location: login.php' . $q);
    exit;
}

// Normalize current user + ID for render and queries
$currentUser = $user->getCurrentUser();
$userId = is_array($currentUser) && isset($currentUser['id'])
    ? (int)$currentUser['id']
    : (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    $q = $instanceId ? ('?instanceId=' . urlencode($instanceId)) : '';
    header('Location: login.php' . $q);
    exit;
}

/* ---------------- Helpers ---------------- */
function normalizeToken(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    if (stripos($raw, '<meta') !== false) {
        if (preg_match('/content=["\']?([^"\'>]+)["\']?/i', $raw, $m)) return trim($m[1]);
        return trim(strip_tags($raw));
    }
    return trim(strip_tags($raw));
}
function isServiceDisabled(string $msg): bool
{
    return (strpos($msg, 'SERVICE_DISABLED') !== false) || (strpos($msg, 'accessNotConfigured') !== false);
}
function ensureWixSitesTable(Database $db): void
{
    $sql = "
    CREATE TABLE IF NOT EXISTS wix_sites (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id         BIGINT UNSIGNED NOT NULL,
        meta_site_id    VARCHAR(64)     NOT NULL,
        host            VARCHAR(255)    DEFAULT NULL,
        gsc_meta_token  VARCHAR(255)    DEFAULT NULL,
        created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_meta_host (meta_site_id, host),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $db->query($sql);
}
/**
 * Persist Google verification meta token for this Wix site + host.
 * (Used by a separate embed step or just to keep linkage.)
 */
function saveWixMetaMapping(
    Database $db,
    int $userId,
    int $domainId,
    string $domain,
    string $method,
    string $token
): void {
    if ($method !== 'meta' || $token === '') return;

    // metaSiteId from session → fallback to domain row → fallback to users row
    $metaSiteId = $_SESSION['meta_site_id'] ?? '';
    if ($metaSiteId === '') {
        $r = $db->query("SELECT wix_site_id FROM domains WHERE id=? AND user_id=?", [$domainId, $userId])->fetch(PDO::FETCH_ASSOC);
        $metaSiteId = trim((string)($r['wix_site_id'] ?? ''));
        if ($metaSiteId === '') {
            $u = $db->query("SELECT wix_site_id FROM users WHERE id=?", [$userId])->fetch(PDO::FETCH_ASSOC);
            $metaSiteId = trim((string)($u['wix_site_id'] ?? ''));
        }
    }
    if ($metaSiteId === '') return;

    $host = $_SERVER['HTTP_HOST'] ?? $domain;
    $host = strtolower(preg_replace('/^www\./i', '', $host));

    ensureWixSitesTable($db);

    $db->query(
        "INSERT INTO wix_sites (user_id, meta_site_id, host, gsc_meta_token, created_at, updated_at)
         VALUES (?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           gsc_meta_token = VALUES(gsc_meta_token),
           updated_at     = NOW()",
        [$userId, $metaSiteId, $host, $token]
    );

    $db->query(
        "UPDATE domains SET wix_site_id = ? WHERE id = ? AND user_id = ?",
        [$metaSiteId, $domainId, $userId]
    );
}
/* ---------------------------------------- */
// Domain row + linked Google account
$domainId = (int)($_GET['id'] ?? 0);
if ($domainId <= 0) {
    $_SESSION['error'] = 'Invalid domain.';
    $q = $instanceId ? ('?instanceId=' . urlencode($instanceId)) : '';
    header('Location: dashboard.php' . $q);
    exit;
}

$row = $db->query(
    "SELECT d.*, ga.access_token
     FROM domains d
     LEFT JOIN google_accounts ga ON ga.id = d.google_account_id
     WHERE d.id = ? AND d.user_id = ?",
    [$domainId, $userId]
)->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    $_SESSION['error'] = 'Domain not found.';
    $q = $instanceId ? ('?instanceId=' . urlencode($instanceId)) : '';
    header('Location: dashboard.php' . $q);
    exit;
}

/* Fallback for meta_site_id into session to avoid “missing site id” UX */
if (empty($_SESSION['meta_site_id'])) {
    $fallbackMeta = trim((string)($row['wix_site_id'] ?? ''));
    if ($fallbackMeta === '') {
        $u = $db->query("SELECT wix_site_id FROM users WHERE id=?", [$userId])->fetch(PDO::FETCH_ASSOC);
        $fallbackMeta = trim((string)($u['wix_site_id'] ?? ''));
    }
    if ($fallbackMeta !== '') {
        $_SESSION['meta_site_id'] = $fallbackMeta;
    }
}

$domain  = (string)$row['domain'];
$method  = strtolower((string)($row['verification_method'] ?? 'meta'));
$token   = normalizeToken((string)($row['verification_token'] ?? ''));
$success = $error = null;

// Need a connected Google account
if (empty($row['access_token'])) {
    $_SESSION['error'] = 'No connected Google account for this domain.';
    $q = $instanceId ? ('?instanceId=' . urlencode($instanceId)) : '';
    header('Location: dashboard.php' . $q);
    exit;
}

$gsc = new GoogleSearchConsole($userId, (string)$row['access_token']);

// Build full URL for SITE verification (meta/file)
$fullUrl = 'https://' . preg_replace('/^www\./i', '', $domain) . '/';

/* 1) Change method + regenerate token (save method even if token call fails) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_method'])) {
    $newMethod = in_array($_POST['verification_method'] ?? 'meta', ['meta', 'file', 'dns'], true)
        ? $_POST['verification_method'] : 'meta';

    try {
        $db->query(
            "UPDATE domains SET verification_method = ?, updated_at = NOW() WHERE id = ? AND user_id = ?",
            [$newMethod, $domainId, $userId]
        );
        $method = $newMethod;

        try {
            $newToken = $gsc->getVerificationToken($domain, $newMethod, $fullUrl);
            $newToken = normalizeToken((string)$newToken);
            if ($newToken !== '') {
                $db->query(
                    "UPDATE domains SET verification_token = ? WHERE id = ? AND user_id = ?",
                    [$newToken, $domainId, $userId]
                );
                $token   = $newToken;
                $success = "Method updated to {$newMethod}. Token generated and saved.";

                // Save mapping so a Wix embed step can push it
                saveWixMetaMapping($db, $userId, $domainId, $domain, $method, $token);
            } else {
                $error = "Method updated to {$newMethod}, but token could not be fetched automatically.";
            }
        } catch (\Google\Service\Exception $e) {
            $msg = $e->getMessage();
            $error = isServiceDisabled($msg)
                ? 'Site Verification API is disabled for your Google Cloud project. Enable it and retry.'
                : 'Token fetch failed: ' . $msg;
        }
    } catch (Throwable $e) {
        $error = 'Failed to update method: ' . $e->getMessage();
    }
}

/* 2) If token is missing, try once to fetch it (non-blocking) */
if ($token === '') {
    try {
        $fetched = $gsc->getVerificationToken($domain, $method, $fullUrl);
        $fetched = normalizeToken((string)$fetched);
        if ($fetched !== '') {
            $db->query(
                "UPDATE domains SET verification_token = ? WHERE id = ? AND user_id = ?",
                [$fetched, $domainId, $userId]
            );
            $token = $fetched;

            saveWixMetaMapping($db, $userId, $domainId, $domain, $method, $token);
        }
    } catch (\Google\Service\Exception $e) {
        if (isServiceDisabled($e->getMessage())) {
            $error = 'Site Verification API is disabled for your Google Cloud project. Enable it and retry.';
        }
    } catch (Throwable $e) { /* ignore */
    }
}

/* 3) Verify (insert ownership via Google API) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify'])) {
    saveWixMetaMapping($db, $userId, $domainId, $domain, $method, $token);

    try {
        $verified = $gsc->insertOwnership($domain, $method, $fullUrl);

        if ($verified) {
            $db->query("UPDATE domains SET is_verified = 1 WHERE id = ? AND user_id = ?", [$domainId, $userId]);
            $_SESSION['success'] = '✅ Domain verified successfully via Google API!';
            $q = $instanceId ? ('?instanceId=' . urlencode($instanceId)) : '';
            header('Location: dashboard.php' . $q);
            exit;
        } else {
            $error =`<i class="fas fa-exclamation-circle"></i> Please make sure your Wix site is published (live). Verification will not work on a draft site. Once your site is live, try again.`;
        }
    } catch (\Google\Service\Exception $e) {
        $msg = $e->getMessage();
        $error = '<i class="fas fa-exclamation-circle"></i> Google API Error: ' . htmlspecialchars($msg, ENT_QUOTES);
    } catch (Throwable $e) {
        $error = '<i class="fas fa-exclamation-circle"></i> Verification failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
    }
}


/* ---- Data for potential auto-embed block ---- */
$userWix        = $db->query("SELECT wix_site_id, wix_api_key FROM users WHERE id = ? LIMIT 1", [$userId])->fetch(PDO::FETCH_ASSOC);
$wixSiteIdUser  = trim((string)($userWix['wix_site_id'] ?? ''));
$wixConnected   = !empty($userWix['wix_api_key']);
$wixSiteIdDomain = trim((string)($row['wix_site_id'] ?? ''));
$needsSiteId    = ($wixSiteIdDomain === '' && $wixSiteIdUser === '');
$canAutoInsert  = ($token !== '' && $wixConnected);

/* ---------------- UI ---------------- */
$domainEsc = htmlspecialchars($domain, ENT_QUOTES, 'UTF-8');
$methodEsc = htmlspecialchars(strtoupper($method), ENT_QUOTES, 'UTF-8');
$tokenEsc  = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

if ($method === 'dns') {
    $instructionsTitle = "DNS TXT Verification";
    $instructionsBody  = "Create a DNS TXT record for your domain with the value below. After propagation, click Verify.";
    $displayCode       = $token !== '' ? "google-site-verification={$tokenEsc}" : '(no token yet)';
} elseif ($method === 'file') {
    $instructionsTitle = "HTML File Verification";
    $instructionsBody  = "Upload the Google verification HTML file to your site root (filename/content per Google). Then click Verify.";
    $displayCode       = $token !== '' ? $tokenEsc : '(no token yet)';
} else {
    $instructionsTitle = "Meta Tag Verification";
    $instructionsBody  = "Add this meta tag inside the <code>&lt;head&gt;</code> of your homepage, deploy, and then click Verify.";
    $displayCode       = $token !== '' ? '&lt;meta name="google-site-verification" content="' . $tokenEsc . '" /&gt;' : '(no token yet)';
}

// build instanceId query string for links
$iidQS = '';
if (!empty($instanceId)) {
    $iidQS = '?instanceId=' . urlencode($instanceId);
} elseif (!empty($_SESSION['wix_instance_id'])) {
    $iidQS = '?instanceId=' . urlencode($_SESSION['wix_instance_id']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Verify Domain</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="./assets/css/custom-styles.css">
    <style>
        .wrap {
            max-width: 900px;
            margin: 40px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .06);
            padding: 24px 28px
        }

        h1 {
            margin: 0 0 8px;
            font-size: 24px
        }

        .muted {
            color: #6b7280;
            margin-bottom: 20px
        }


        .card {
            margin-top: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 0px 2px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .card-body {
            padding: 1.5rem;
        }


        pre {
            background: #f3f3f3;
            color: #686868;
            padding: 8px 10px;
            border-radius: 8px;
            overflow: auto;
            font-size: 13px;
            border: 1px solid #d4d4d4;
        }

        .row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center
        }

        select,
        input {
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 8px
        }

        a.link {
            text-decoration: none;
            color: #374151;
            font-weight: 600
        }

        .note {
            font-size: 12px;
            color: #6b7280;
            margin-top: 6px
        }

        /* Header bits (matching your dashboard styles) */
        .main-content {
            margin-left: 250px;
            min-height: 100vh;
        }

        .header {
            background: linear-gradient(135deg, #66acea 0%, #4845a2 100%);
            color: #fff;
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .25);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .user-type-badge {
            padding: .35rem 1rem;
            border-radius: 20px;
            font-size: .85rem;
            font-weight: 600;
            margin-right: .5rem;
            background: #eef2ff;
            color: #3730a3;
        }

        .logout-btn {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: #fff;
            padding: .5rem .9rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
        }

        .copy-btn {
            position: absolute;
            top: 3px;
            right: 8px;
            border: none;
            cursor: pointer;
            padding: 6px;
            line-height: 0;
            border-radius: 8px;
        }

        .copy-btn:hover {
            opacity: .85;
        }

        .copy-btn:focus {
            outline-offset: 2px;
        }

        .copy-toast {
            position: absolute;
            top: 8px;
            right: 40px;
            font-size: 12px;
            background: #111827;
            color: #fff;
            padding: 2px 6px;
            border-radius: 6px;
            display: none;
        }


        .inst-hidden {
            display: none
        }

        #inst-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            z-index: 2147483646
        }

        #inst-modal {
            position: fixed;
            left: 50%;
            top: 8%;
            transform: translateX(-50%);
            max-width: 720px;
            width: 92%;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
            z-index: 2147483647
        }

        #inst-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid #eee
        }

        #inst-body {
            padding: 18px 20px;
            max-height: 65vh;
            overflow: auto;
            line-height: 1.55
        }

        #inst-foot {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 14px 20px;
            border-top: 1px solid #eee
        }

        .inst-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 14px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #fff;
            cursor: pointer
        }

        .inst-btn-primary {
            background: #0ea5e9;
            color: #fff;
            border-color: #0ea5e9
        }

        .inst-list {
            counter-reset: step;
            margin: 0;
            padding: 0
        }

        .inst-list li {
            list-style: none;
            counter-increment: step;
            margin: 10px 0;
            padding-left: 34px;
            position: relative
        }

        .inst-list li::before {
            content: counter(step);
            position: absolute;
            left: 0;
            top: 0;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #0ea5e9;
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px
        }

        @media (max-width:480px) {
            #inst-modal {
                top: 5%
            }
        }
    </style>
</head>

<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="header">
            <h1>Google Search Console</h1>
            <div class="user-info">
                <?php
                $userType = htmlspecialchars(str_replace('_', '-', (string)($currentUser['user_type'] ?? 'store_owner')), ENT_QUOTES, 'UTF-8');
                $userTypeLabel = htmlspecialchars(ucwords(str_replace('_', ' ', (string)($currentUser['user_type'] ?? 'store_owner'))), ENT_QUOTES, 'UTF-8');
                $firstName = htmlspecialchars((string)($currentUser['first_name'] ?? 'User'), ENT_QUOTES, 'UTF-8');
                ?>
                
                <span>Welcome, <?= $firstName ?>!</span>
                <div class="user-avatar">
                    <?= htmlspecialchars(strtoupper(substr($firstName, 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <button id="open-instructions" class="btn btn-secondary" type="button" title="How it works">
                    <i class="fa-regular fa-circle-question"></i> Instructions
                </button>
                <a href="logout.php">
                    <button class="btn danger">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
                    </button>
                </a>
            </div>
        </div>

        <div class="content">

            <div class="card">

                <div class="card-header">
                    <h1>Verify: <?= $domainEsc ?></h1>
                    <p class="muted">Method: <strong><?= $methodEsc ?></strong></p>

                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-error">
                            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <!-- Change method + regenerate token -->
                    <form method="POST" class="row">
                        <input type="hidden" name="set_method" value="1">
                        <label for="verification_method">Method:</label>
                        <select name="verification_method" id="verification_method">
                            <option value="meta" <?= $method === 'meta' ? 'selected' : ''; ?>>Meta tag</option>

                        </select>
                        <button class="btn primary" type="submit">Save Method &amp; Generate Token</button>
                        <a href="dashboard.php<?= $iidQS ?>">
                            <button class="btn btn-secondary">Back</button>
                        </a>
                    </form>
                </div>

                <div class="card-body" style="display: flex; flex-direction: column; gap: 10px;">
                    <div>
                        <h3><?= htmlspecialchars($instructionsTitle, ENT_QUOTES, 'UTF-8') ?></h3>
                        <p><?= $instructionsBody ?></p>
                    </div>

                    <div>
                        <div style="position: relative;">
                            <pre style="margin:0;"><code id="verifyToken"><?= $displayCode ?></code></pre>
                            <button type="button" class="copy-btn" onclick="copyToken()" title="Copy" aria-label="Copy">
                                <!-- Two overlapping squares (inline SVG) -->
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="9" y="9" width="10" height="10" rx="2" ry="2"></rect>
                                    <rect x="5" y="5" width="10" height="10" rx="2" ry="2"></rect>
                                </svg>
                            </button>
                            <span id="copyMsg" class="copy-toast">Copied!</span>
                        </div>
                        <?php if ($token === ''): ?>
                            <p class="muted">Tip: If token didn’t load, you can still add the meta tag via Google Search Console and return to Verify.</p>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="row" id="verifyForm">
                        <button type="button" id="autoInjectBtn" class="btn btn-secondary">
                            <i class="fa-solid fa-bolt"></i> Auto-Inject Meta Tag
                        </button>
                        <button type="submit" name="verify" class="btn btn-google">
                            <i class="fa-solid fa-circle-check"></i> Verify via Google API
                        </button>
                        <a href="dashboard.php<?= $iidQS ?>">
                            <button class="btn btn-secondary">
                                Cancel
                            </button>
                        </a>
                    </form>

                    <!-- Result alert area -->
                    <div id="autoInjectMsg" class="alert inst-hidden" style="margin-top:12px;"></div>
                </div>

            </div>

        </div>
    </div>


    <!-- 👇 Popup markup -->
    <div id="inst-overlay" class="inst-hidden"></div>
    <div id="inst-modal" class="inst-hidden" role="dialog" aria-modal="true" aria-labelledby="inst-title">
        <div id="inst-head">
            <div id="inst-title"><i class="fa-regular fa-circle-question"></i> Getting Started</div>
            <button class="inst-btn" type="button" data-close>✕</button>
        </div>
        <div id="inst-body">
            <ol class="inst-list">
                <li>Connect your Google account (or click “Connect Google Account”).</li>
                <li>Add &amp; verify your domain (Meta tag method recommended).</li>
                <li>Submit your <code>sitemap.xml</code> for faster discovery.</li>
                <li>Track clicks, impressions &amp; coverage; fix issues as they appear.</li>
                <li>Use Re-index when you publish important changes.</li>
            </ol>
        </div>
        <div id="inst-foot">
            <button class="inst-btn" type="button" data-close>Close</button>
            <button class="inst-btn inst-btn-primary" type="button" data-close>Got it</button>
        </div>
    </div>
    <script>
        (function() {
            const openBtn = document.getElementById('open-instructions');
            const overlay = document.getElementById('inst-overlay');
            const modal = document.getElementById('inst-modal');

            if (!openBtn || !overlay || !modal) return;

            const show = () => {
                overlay.classList.remove('inst-hidden');
                modal.classList.remove('inst-hidden');
            };
            const hide = () => {
                overlay.classList.add('inst-hidden');
                modal.classList.add('inst-hidden');
            };

            openBtn.addEventListener('click', show);
            overlay.addEventListener('click', hide);
            modal.querySelectorAll('[data-close]').forEach(el => el.addEventListener('click', hide));
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') hide();
            });
        })();
    </script>
    <script>
        function copyToken() {
            const code = document.getElementById('verifyToken').innerText;
            navigator.clipboard.writeText(code).then(() => {
                const msg = document.getElementById('copyMsg');
                msg.style.display = 'inline';
                setTimeout(() => {
                    msg.style.display = 'none';
                }, 1200);
            });
        }
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const autoBtn = document.getElementById('autoInjectBtn');
            const msgBox = document.getElementById('autoInjectMsg');
            if (!autoBtn || !msgBox) return;

            autoBtn.addEventListener('click', async function() {
                autoBtn.disabled = true;
                msgBox.classList.remove('alert-success', 'alert-error');
                msgBox.textContent = 'Injecting verification tag...';
                msgBox.classList.remove('inst-hidden');
                msgBox.classList.add('alert');

                try {
                    const payload = {
                        action: "auto_inject", // ✅ REQUIRED
                        instanceId: <?= json_encode($instanceId) ?>,
                        domain: <?= json_encode($domain) ?>,
                        token: <?= json_encode($token) ?>,
                        method: <?= json_encode($method) ?>
                    };

                    const res = await fetch('api/inject-wix-verification.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(payload)
                    });

                    const data = await res.json();
                    console.log('[AutoInject]', data);

                    if (data.success) {
                        const platform = data.platform || 'wix';
                        let label = '';

                        // prettier feedback based on platform
                        if (platform === 'embed')
                            label = 'via Embed Script API';
                        else if (platform === 'wix')
                            label = 'directly on Wix-hosted site';
                        else
                            label = '(method unknown)';

                        msgBox.classList.add('alert-success');
                        msgBox.innerHTML =
                            `<i class="fas fa-check-circle"></i> Meta tag successfully injected <strong>${label}</strong>!`;
                    } else {
                        msgBox.classList.add('alert-error');
                        msgBox.textContent =
                            '<i class="fas fa-exclamation-circle"></i> Injection failed: ' + (data.error || 'Unknown error.');
                    }
                } catch (err) {
                    console.error('Auto-inject error:', err);
                    msgBox.classList.add('alert-error');
                    msgBox.textContent = '<i class="fas fa-exclamation-circle"></i> Injection failed: ' + err.message;
                } finally {
                    autoBtn.disabled = false;
                }
            });
        });
    </script>


</body>

</html>