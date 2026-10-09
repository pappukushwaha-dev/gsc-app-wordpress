<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Load Google SDK + DB + decrypt helpers
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/credentials.php';
require_once __DIR__ . '/includes/google/save_token.php';

global $pdo;

/* ==========================================================
   1️⃣ Resolve INSTANCE ID from OAuth state (BigCommerce)
========================================================== */

$instanceId = $_GET['state'] ?? null;

if (!$instanceId) {
    die('Missing instance parameter.');
}

// Restore session safely
$_SESSION['instance_id'] = $instanceId;

/* ==========================================================
   2️⃣ Validate OAuth code
========================================================== */

if (!isset($_GET['code'])) {
    die("Missing 'code' parameter.");
}

$code = $_GET['code'];

/* ==========================================================
   3️⃣ Load Google credentials from DB
========================================================== */

$stmt = $pdo->prepare("SELECT client_id, client_secret, redirect_uri FROM google_settings LIMIT 1");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    die("Google credentials missing.");
}

$clientId        = $row['client_id'];
$clientSecretEnc = $row['client_secret'];
$redirectUri     = $row['redirect_uri'];

/* ==========================================================
   4️⃣ Decrypt client secret
========================================================== */

$clientSecret = null;

if (!empty($clientSecretEnc)) {

    if (!defined('ENCRYPTION_KEY')) {
        // Fallback to plain text
        $clientSecret = $clientSecretEnc;
    } else {
        $decrypted = decrypt($clientSecretEnc, ENCRYPTION_KEY);

        if ($decrypted !== false && $decrypted !== null && $decrypted !== '') {
            $clientSecret = $decrypted;
        } else {
            // Possibly stored as plain text
            if (strpos($clientSecretEnc, 'GOCSPX-') === 0 || strlen($clientSecretEnc) > 20) {
                $clientSecret = $clientSecretEnc;
            } else {
                die("Failed to decrypt client secret.");
            }
        }
    }
}

if (empty($clientSecret)) {
    die("Client secret is missing or invalid.");
}

/* ==========================================================
   5️⃣ Build Google Client
========================================================== */

$client = new Google_Client();
$client->setClientId($clientId);
$client->setClientSecret($clientSecret);
$client->setRedirectUri($redirectUri);

$client->setScopes([
    Google_Service_Webmasters::WEBMASTERS,
    Google_Service_SiteVerification::SITEVERIFICATION,
    'https://www.googleapis.com/auth/userinfo.email',
    'https://www.googleapis.com/auth/userinfo.profile'
]);

$client->setAccessType('offline');
$client->setPrompt('consent');
$client->setIncludeGrantedScopes(true);

/* ==========================================================
   6️⃣ Exchange code for token
========================================================== */

try {
    $token = $client->fetchAccessTokenWithAuthCode($code);
} catch (Exception $e) {
    die("Google OAuth Error: " . $e->getMessage());
}

if (isset($token['error'])) {
    $errorMsg = $token['error_description'] ?? $token['error'];

    if ($token['error'] === 'invalid_grant') {
        die("Authorization code expired. Please reconnect.");
    }

    if ($token['error'] === 'redirect_uri_mismatch') {
        die("Redirect URI mismatch. Check Google Cloud Console.");
    }

    die("Google OAuth Error: " . htmlspecialchars($errorMsg));
}

/* ==========================================================
   7️⃣ Get Google user info
========================================================== */

$oauth = new Google_Service_Oauth2($client);
$userInfo = $oauth->userinfo->get();

$userInfoArray = [
    'sub'   => $userInfo->id,
    'email' => $userInfo->email
];

/* ==========================================================
   8️⃣ Save token for this instance
========================================================== */

google_save_token($pdo, $instanceId, $token, $userInfoArray);

/* ==========================================================
   9️⃣ Redirect to Setup Step 2
========================================================== */
 $stepUrl = "https://makkpressapps.com" . APP_BASE . "/api/update-step.php";

        $postData = [
            'instanceId' => $instanceId,
            'step'       => 1
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
header("Location: " . APP_URL . "setup-wizard.php?instanceId=" . urlencode($instanceId) . "&step=2");
exit;
