<?php
/**
 * api/run_schema_check.php
 *
 * AJAX endpoint for the Action Center drawer's structured data section.
 * Takes ?entity= exactly as it appears in the insight row (path, full
 * URL, or bare domain) and joins it with THIS instance's verified site
 * origin. Refuses anything that resolves to a different host, so the
 * endpoint can never be used to fetch arbitrary URLs.
 *
 * GET  entity  required - page path or URL from the insight
 *      force   optional - 1 to bypass the cache (1 hour floor)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/schema_check.php';

/**
 * The site this instance is connected to.
 *
 * The verified Search Console property is the truth here, so it is asked
 * first; WpSite is the fallback for an instance that has not verified
 * yet. Columns are read off the row rather than named in SQL, because the
 * site table differs between apps and a wrong column name would fail the
 * whole query. No JOIN anywhere - this database mixes collations and a
 * join across two of these tables raises error 1267.
 */
function gsc_connected_site_url(PDO $pdo, string $instanceId): string
{
    try {
        $s = $pdo->prepare("SELECT site_url FROM gsc_domain_verifications
                            WHERE instance_id = ? LIMIT 1");
        $s->execute([$instanceId]);
        $v = trim((string)($s->fetchColumn() ?: ''));
        if ($v !== '') {
            return strpos($v, 'sc-domain:') === 0 ? 'https://' . substr($v, 10) : $v;
        }
    } catch (Throwable $e) {
    }

    try {
        $s = $pdo->prepare("SELECT * FROM WpSite WHERE instance_id = ? LIMIT 1");
        $s->execute([$instanceId]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['domain', 'shop_domain', 'site_url', 'store_url', 'url'] as $col) {
            if (!empty($row[$col])) {
                return trim((string)$row[$col]);
            }
        }
    } catch (Throwable $e) {
    }

    return '';
}


// Both session spellings, same reason as everywhere else in this app.
$instanceId = $_SESSION['instanceid'] ?? $_SESSION['instance_id'] ?? null;
if (!$instanceId) {
    echo json_encode(['success' => false, 'error' => 'Not signed in']);
    exit;
}
$instanceId = trim((string)$instanceId);

/* Release the session lock before the slow part.
   PHP holds an exclusive lock on the session file from session_start()
   until the request ends. A PSI run takes 15-30 seconds, and every other
   request from the same browser - the insights feed above all - blocks on
   that lock and appears to hang. Nothing below writes to the session, so
   closing it here costs nothing and keeps the rest of the page alive. */
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$entity = trim((string)($_GET['entity'] ?? ''));
if ($entity === '') {
    echo json_encode(['success' => false, 'error' => 'Missing entity']);
    exit;
}

try {
    // The only host we will fetch: this instance's connected site.
    $siteUrl = gsc_connected_site_url($pdo, $instanceId);

    if ($siteUrl === '') {
        echo json_encode(['success' => false, 'error' => 'Site not connected yet']);
        exit;
    }

    if (strpos($siteUrl, 'sc-domain:') === 0) {
        $origin = 'https://' . substr($siteUrl, 10);
    } elseif (!preg_match('#^https?://#i', $siteUrl)) {
        $origin = 'https://' . $siteUrl;
    } else {
        $origin = $siteUrl;
    }
    $origin = rtrim($origin, '/');
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));

    // Resolve the entity against the origin.
    if (preg_match('#^https?://#i', $entity)) {
        $url = $entity;
    } elseif ($entity[0] === '/') {
        $url = $origin . $entity;
    } elseif (preg_match('#^[a-z0-9.-]+\.[a-z]{2,}(/|$)#i', $entity)) {
        $url = 'https://' . $entity;
    } else {
        echo json_encode(['success' => false, 'error' => 'Not a page']);
        exit;
    }

    // Host guard: same host or a subdomain of it (www vs bare, etc).
    $urlHost = strtolower((string)parse_url($url, PHP_URL_HOST));
    $bare = preg_replace('/^www\./', '', $originHost);
    $urlBare = preg_replace('/^www\./', '', $urlHost);
    if ($urlBare !== $bare && substr($urlBare, -strlen('.' . $bare)) !== '.' . $bare) {
        echo json_encode(['success' => false, 'error' => 'URL is not on your site']);
        exit;
    }

    // force with a 1 hour floor, same policy as the speed test.
    $force = !empty($_GET['force']);
    if ($force) {
        $s = $pdo->prepare("SELECT fetched_at FROM schema_live_results
                            WHERE instance_id = ? AND url_hash = ? LIMIT 1");
        $s->execute([$instanceId, sha1($url)]);
        $last = $s->fetchColumn();
        if ($last && (time() - strtotime($last)) < 3600) {
            $force = false;
        }
    }

    /* Live validation of the page as it is right now, Rich Results Test
       style: every JSON-LD item of a Google rich-result type checked
       against Google's required and recommended fields. */
    $result = schema_validate_url($pdo, $instanceId, $url, $force);
    echo json_encode(['success' => true, 'result' => $result]);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('run_schema_check: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Schema check failed']);
}
