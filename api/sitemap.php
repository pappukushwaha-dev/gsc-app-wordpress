<?php
/**
 * GoHighLevel Sitemap API
 * URL:
 * /api/sitemap.php?instance_id=XXXX
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// ===============================
// 1️⃣ Include DB Connection
// ===============================
// require_once __DIR__ . '/../../includes/config.php';
// require_once __DIR__ . '/../../includes/db.php';

// ===============================
// 2️⃣ Validate instance_id
// ===============================
if (!isset($_GET['instance_id']) || empty($_GET['instance_id'])) {
    echo json_encode([
        "status" => false,
        "message" => "instance_id is required"
    ]);
    exit;
}

$instance_id = trim($_GET['instance_id']);

// ===============================
// 3️⃣ Get Domain from Database
// ⚠️ CHANGE TABLE NAME IF NEEDED
// ===============================
try {

    $stmt = $pdo->prepare("SELECT domain FROM WixSite WHERE instance_id = ?");
    $stmt->execute([$instance_id]);
    $site = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$site) {
        echo json_encode([
            "status" => false,
            "message" => "Invalid instance_id"
        ]);
        exit;
    }

    $domain = rtrim($site['domain'], '/');

} catch (Exception $e) {
    echo json_encode([
        "status" => false,
        "message" => "Database error",
        "error" => $e->getMessage()
    ]);
    exit;
}

// ===============================
// 4️⃣ Fetch XML via cURL
// ===============================
function fetchXml($url)
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'GHL Sitemap API'
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        curl_close($ch);
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return false;
    }

    return $response;
}

// ===============================
// 5️⃣ Crawl Sitemap (Recursive)
// ===============================
function crawlSitemap($sitemapUrl, &$collectedUrls = [])
{
    $xmlContent = fetchXml($sitemapUrl);

    if (!$xmlContent) return;

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);

    if (!$xml) return;

    $rootName = $xml->getName();

    // Normal sitemap
    if ($rootName === 'urlset') {
        foreach ($xml->url as $url) {
            $loc = trim((string)$url->loc);
            if (!empty($loc)) {
                $collectedUrls[] = $loc;
            }
        }
    }

    // Sitemap index
    if ($rootName === 'sitemapindex') {
        foreach ($xml->sitemap as $sitemap) {
            $childSitemap = trim((string)$sitemap->loc);
            if (!empty($childSitemap)) {
                crawlSitemap($childSitemap, $collectedUrls);
            }
        }
    }
}

// ===============================
// 6️⃣ Start Crawling
// ===============================
$sitemapUrl = $domain . "/sitemap.xml";

$allUrls = [];
crawlSitemap($sitemapUrl, $allUrls);

$allUrls = array_values(array_unique($allUrls));

// ===============================
// 7️⃣ Return JSON Response
// ===============================
echo json_encode([
    "status" => true,
    "instance_id" => $instance_id,
    "domain" => $domain,
    "total_urls" => count($allUrls),
    "urls" => $allUrls
], JSON_PRETTY_PRINT);

exit;