<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/google/get_account.php';
require_once __DIR__ . '/../../includes/google/token_manager.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ==========================================================
   LOGGING

   Every outcome is written, not only the successful one.

   The original recorded a row solely inside the 2xx branch: the network,
   401, 403 and fallback branches all exit before reaching it. So a user
   who tried four times and failed four times saw an empty Submission
   History and no reason why — the one screen that exists to answer
   "what happened to my sitemap?" answered nothing.

   Wrapped in try/catch because a logging failure must never turn a
   successful submit into a reported failure.
========================================================== */
function logSubmission(PDO $pdo, string $instanceId, string $sitemapUrl,
                       string $status, ?int $httpCode, string $response): void
{
    try {
        $pdo->prepare("
            INSERT INTO sitemap_submission_logs
                (instance_id, sitemap_url, status, http_code, google_response)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            $instanceId,
            $sitemapUrl,
            $status,
            $httpCode,
            mb_substr($response, 0, 60000),
        ]);
    } catch (Throwable $e) {
        error_log('submit_sitemap logSubmission failed: ' . $e->getMessage());
    }
}

/* ==========================================================
   READ INPUT
========================================================== */
$data = json_decode(file_get_contents('php://input'), true) ?: [];

$instanceId =
    $data['instance_id']
    ?? $data['instanceId']
    ?? $_SESSION['instance_id']
    ?? $_SESSION['instanceid']
    ?? null;

// Both spellings. The wizard JS sends snake_case, but the Sitemap Manager
// and anything ported from the other apps send sitemapUrl — and a mismatch
// here returns 'missing_instance_or_sitemap' with nothing to explain it.
$sitemapUrl = trim($data['sitemap_url'] ?? $data['sitemapUrl'] ?? '');

if (!$instanceId || !$sitemapUrl) {
    echo json_encode([
        'success' => false,
        'error'   => 'missing_instance_or_sitemap'
    ]);
    exit;
}

/* ==========================================================
   LOAD GOOGLE ACCOUNT (AUTHORITATIVE)
========================================================== */
$google = getGoogleAccountByInstance($instanceId);

if (!$google || empty($google['access_token'])) {
    echo json_encode([
        'success' => false,
        'error'   => 'We are unable to process your request. Please return to the previous steps, click on "Disconnect this account," and then reconnect your account.'
    ]);
    exit;
}




/* ==========================================================
   ENSURE ACCESS TOKEN (DO NOT FAIL EARLY)
========================================================== */
$accountId   = (int) $google['id'];
$tokenResult = ensureAccessToken($accountId, 120);

/*
 IMPORTANT:
 If refresh fails but access_token still exists,
 let Google API decide.
*/
$accessToken = $tokenResult['access_token'] ?? $google['access_token'];

if (empty($accessToken)) {
    echo json_encode([
        'success' => false,
        'error'   => 'We are unable to process your request. Please return to the previous steps, click on "Disconnect this account," and then reconnect your account.',
        'debug'   => 'access_token_empty_after_refresh'
    ]);
    exit;
}

/* ==========================================================
   LOAD VERIFIED SITE
========================================================== */
$stmt = $pdo->prepare("
    SELECT site_url
    FROM gsc_domain_verifications
    WHERE instance_id = ?
    LIMIT 1
");
$stmt->execute([$instanceId]);
$domain = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$domain || empty($domain['site_url'])) {
    echo json_encode([
        'success' => false,
        'error'   => 'domain_not_verified'
    ]);
    exit;
}

$siteUrl = trim($domain['site_url']);
/* ==========================================================
   USE VERIFIED PROPERTY EXACTLY AS STORED
========================================================== */
$siteForApi = $siteUrl; // EXACT string from DB — DO NOT TOUCH



/* ==========================================================
   SUBMIT SITEMAP TO GOOGLE
========================================================== */
// rawurlencode, not urlencode. These are path segments, and urlencode
// turns a space into '+', which is only correct in a query string — in a
// path Google reads the '+' literally and the sitemap URL no longer
// matches the one it holds.
$apiUrl = 'https://www.googleapis.com/webmasters/v3/sites/'
    . rawurlencode($siteForApi)
    . '/sitemaps/'
    . rawurlencode($sitemapUrl);

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => 'PUT',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$accessToken}",
        "Content-Type: application/json"
    ],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_USERAGENT      => 'Ecwid-GSC/1.0'
]);

$response = curl_exec($ch);
$http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

/* ==========================================================
   HARD FAIL ONLY ON NETWORK ERROR
========================================================== */
if ($curlErr) {
    logSubmission($pdo, $instanceId, $sitemapUrl, 'Failed', null, 'cURL: ' . $curlErr);

    echo json_encode([
        'success' => false,
        'error'   => 'network_error',
        'message' => $curlErr
    ]);
    exit;
}

/* ==========================================================
   PERMISSION ERROR → RECONNECT (REAL CASE)
========================================================== */
if ($http === 401) {
    logSubmission($pdo, $instanceId, $sitemapUrl, 'Failed', $http, (string)$response);

    echo json_encode([
        'success' => false,
        'error'   => 'We are unable to process your request. Please return to the previous steps, click on "Disconnect this account," and then reconnect your account.',
        'google_http' => $http
    ]);
    exit;
}

if ($http === 403) {
    logSubmission($pdo, $instanceId, $sitemapUrl, 'Failed', $http, (string)$response);

    echo json_encode([
        'success' => false,
        'error'   => 'insufficient_permissions',
        'google_http' => $http,
        'hint' => 'Property exists but access is denied to this resource'
    ]);
    exit;
}

/* ==========================================================
   SUCCESS (AUTHORITATIVE)
========================================================== */
if ($http >= 200 && $http < 300) {

    logSubmission($pdo, $instanceId, $sitemapUrl, 'Success', $http, (string)$response);

    try {
        // Fills the columns added by the migration.
        //
        // discovered_pages, last_downloaded, warnings and errors are
        // deliberately NOT written here. A submit returns an empty 204 —
        // Google accepted the request and said nothing else. Those four
        // exist only in sitemaps.list, and check_sitemap_status.php fills
        // them on the next page load. Writing a guess would put a number
        // on the Connection Overview card that Google never gave us.
        //
        // ON DUPLICATE KEY UPDATE only works now that the migration added
        // uniq_instance_sitemap. Before it, every submit inserted a fresh
        // row — which is why 16 rows held only 4 sitemaps.
        $pdo->prepare("
            INSERT INTO sitemaps
                (instance_id, domain, sitemap_url, status,
                 last_submitted_at, submission_count, last_status_message)
            VALUES (?, ?, ?, 'Success', NOW(), 1, ?)
            ON DUPLICATE KEY UPDATE
                domain              = VALUES(domain),
                status              = 'Success',
                last_submitted_at   = NOW(),
                submission_count    = submission_count + 1,
                last_status_message = VALUES(last_status_message),
                updated_at          = NOW()
        ")->execute([
            $instanceId,
            rtrim($siteUrl, '/') . '/',
            $sitemapUrl,
            mb_substr((string)$response, 0, 60000)
        ]);
    } catch (Throwable $e) {
        // The submit itself succeeded; a bookkeeping failure should not be
        // reported to the user as a failed submission.
        error_log('submit_sitemap sitemaps upsert failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Sitemap submitted successfully. Google may take time to process it.'
    ]);
    exit;
}

/* ==========================================================
   FALLBACK ERROR
========================================================== */
logSubmission($pdo, $instanceId, $sitemapUrl, 'Failed', $http, (string)$response);

echo json_encode([
    'success' => false,
    'error'   => 'google_api_error',
    'http'    => $http,
    'message' => substr((string) $response, 0, 200)
]);
exit;