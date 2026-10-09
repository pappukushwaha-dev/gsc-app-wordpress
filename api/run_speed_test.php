<?php
/**
 * api/run_speed_test.php
 *
 * Returns the cached PSI result for this instance, refreshing it first
 * when the cache is stale (or when force=1 is passed from the card's
 * refresh button - still subject to a 1-hour floor so the button cannot
 * burn quota).
 *
 * Response: { success, status: 'ok'|'error'|'running', result: {...} }
 */

declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/psi.php';

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


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new Exception("Database connection missing.");
    }

    $instanceId = $_SESSION['instance_id'] ?? $_SESSION['instanceid'] ?? ($_GET['instance_id'] ?? null);
    if ($instanceId === null || $instanceId === '') {
        echo json_encode(["success" => false, "error" => "instance_id missing"]);
        exit;
    }
    $instanceId = (string)$instanceId;

    /* Release the session lock before the slow part.
       PHP holds an exclusive lock on the session file from session_start()
       until the request ends. A PSI run takes 15-30 seconds, and every other
       request from the same browser - the insights feed above all - blocks
       on that lock and appears to hang. Nothing below writes to the session,
       so closing it here costs nothing and keeps the rest of the page alive. */
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    /* The URL to test: this instance's connected site */
    $domain = gsc_connected_site_url($pdo, $instanceId);
    if ($domain === '') {
        echo json_encode(["success" => false, "error" => "no site domain for this instance"]);
        exit;
    }
    $url = preg_match('#^https?://#i', $domain) ? $domain : 'https://' . $domain;

    $strategy = ($_GET['strategy'] ?? 'mobile') === 'desktop' ? 'desktop' : 'mobile';
    $force    = isset($_GET['force']) && $_GET['force'] === '1';

    $apiKey = psi_get_api_key($pdo);
    if ($apiKey === '') {
        echo json_encode(["success" => false, "status" => "error", "error" => "No PageSpeed API key."]);
        exit;
    }

    $cached = psi_get_cached($pdo, $instanceId, $strategy);

    $refresh = psi_needs_refresh($cached);
    if ($force && $cached && $cached['raw_status'] === 'ok') {
        /* manual refresh allowed at most once per hour */
        $ageHours = (time() - strtotime($cached['fetched_at'])) / 3600;
        if ($ageHours >= 1) {
            $refresh = true;
        }
    }

    if ($refresh) {
        $cached = psi_run_and_store($pdo, $instanceId, $url, $strategy, $apiKey);
    }

    if (!$cached || $cached['raw_status'] !== 'ok') {
        echo json_encode([
            "success" => false,
            "status"  => "error",
            "error"   => $cached['error_message'] ?? 'Speed test failed',
        ]);
        exit;
    }

    $cached['opportunities'] = json_decode((string)$cached['opportunities'], true) ?: [];

    echo json_encode([
        "success" => true,
        "status"  => "ok",
        "result"  => [
            "url"         => $cached['url'],
            "strategy"    => $cached['strategy'],
            "perf_score"  => $cached['perf_score'] !== null ? (int)$cached['perf_score'] : null,
            "a11y_score"  => isset($cached['a11y_score']) && $cached['a11y_score'] !== null ? (int)$cached['a11y_score'] : null,
            "bp_score"    => isset($cached['bp_score']) && $cached['bp_score'] !== null ? (int)$cached['bp_score'] : null,
            "seo_score"   => isset($cached['seo_score']) && $cached['seo_score'] !== null ? (int)$cached['seo_score'] : null,
            "lcp_ms"      => $cached['lcp_ms'] !== null ? (int)$cached['lcp_ms'] : null,
            "cls"         => $cached['cls'] !== null ? (float)$cached['cls'] : null,
            "inp_ms"      => $cached['inp_ms'] !== null ? (int)$cached['inp_ms'] : null,
            "tbt_ms"      => $cached['tbt_ms'] !== null ? (int)$cached['tbt_ms'] : null,
            "opportunities" => $cached['opportunities'],
            "fetched_at"  => $cached['fetched_at'],
        ],
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
