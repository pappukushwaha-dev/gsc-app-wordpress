<?php
/**
 * ===================================================================
 * DEPLOY TO: /var/www/html/wordpress/googlesearchconsole/includes/psi.php
 * APP: Ecwid GSC
 * DATE: 3 Sep 2026
 *
 * WHAT CHANGED IN THIS VERSION (two fixes, both in this file only):
 *
 * 1. psi_needs_refresh() now cools off after a failure.
 *    It used to return true for ANY row that was not 'ok', so a site
 *    PSI cannot reach fired a fresh 15-30s API call on every single
 *    page load, forever. One broken store was enough to drain the
 *    daily quota shared by every other site on the project - which is
 *    what emptied it on 3 Sep. A failed run is now left alone for
 *    PSI_ERROR_COOLDOWN_MIN (60) minutes. Successful results keep the
 *    same 7-day cache as before.
 *
 * 2. An HTTP 429 now says "PageSpeed daily quota exhausted for this
 *    API key" instead of surfacing as a bare timeout, which read like
 *    a network fault and sent the last debugging session down the
 *    wrong path.
 *
 * Nothing else changed: same functions, same signatures, same table.
 * Safe to drop in over the previous copy.
 * ===================================================================
 */
/**
 * includes/psi.php
 *
 * PageSpeed Insights integration for the Action Center speed card.
 *
 * Design:
 *  - Results are CACHED in psi_results. The dashboard always reads the
 *    cache; a live PSI call takes 15-30 seconds and must never block a
 *    page load.
 *  - One row per instance per strategy (mobile/desktop), refreshed in
 *    place. api/run_speed_test.php decides WHEN to refresh.
 *
 * API key: stored encrypted in admin_settings (key_name = 'pagespeed_api_key'),
 * same pattern as the other apps. psi_get_api_key() reads and decrypts it.
 */

/* decrypt() lives in a different file depending on the app: some ship
   includes/encryption.php, others (BigCommerce) define it inside
   includes/credentials.php along with ENCRYPTION_KEY. Load the first one
   that exists and stop: both declare the same encrypt()/decrypt(), so
   loading both is a fatal redeclare. This server once carried a stray
   encryption.php copied from the HighLevel app, and with both present
   every page in this chain died mid-render. credentials.php comes first
   because it is the file this app actually ships; psi_get_api_key()
   falls back to config/env if neither is present. */
foreach (['/credentials.php', '/encryption.php'] as $psiHelper) {
    if (is_file(__DIR__ . $psiHelper)) {
        require_once __DIR__ . $psiHelper;
        break;
    }
}

const PSI_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
const PSI_CACHE_HOURS = 168; // 7 days - weekly freshness is enough for AC
const PSI_ERROR_COOLDOWN_MIN = 60;  // do not retry a failed run for an hour

/**
 * Cached result for an instance, or null when none exists.
 */
function psi_get_cached(PDO $pdo, string $instanceId, string $strategy = 'mobile'): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM psi_results WHERE instance_id = :i AND strategy = :s LIMIT 1");
    $stmt->execute([':i' => $instanceId, ':s' => $strategy]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * True when the cached row is missing, errored, or older than the cache window.
 */
function psi_needs_refresh(?array $cached): bool
{
    if (!$cached) {
        return true;
    }

    $age = time() - strtotime($cached['fetched_at']);

    /* A failed run must cool off before it is tried again. Retrying an
       error on every page load turns one broken site - a store PSI cannot
       reach, or a bad key - into a request storm that burns the daily
       quota for every other site sharing the project, which is exactly
       how the quota was exhausted. */
    if ($cached['raw_status'] !== 'ok') {
        return $age > PSI_ERROR_COOLDOWN_MIN * 60;
    }

    return $age > PSI_CACHE_HOURS * 3600;
}

/**
 * Run PSI for a URL and upsert the result. Returns the stored row-shaped
 * array, with raw_status = 'ok' or 'error'.
 *
 * SLOW (15-30s). Call only from api/run_speed_test.php or a cron,
 * never inline in a page render.
 */
function psi_get_api_key(PDO $pdo): string
{
    /* Preferred source: admin_settings, encrypted, same as the other apps.
       Every step is guarded because this app may have no admin_settings
       table, no decrypt() helper, or a plaintext value - in any of those
       cases we fall through to config/env rather than fataling. */
    try {
        $st = $pdo->prepare("SELECT value FROM admin_settings WHERE key_name = 'pagespeed_api_key' LIMIT 1");
        $st->execute();
        $enc = (string)($st->fetchColumn() ?: '');
        if ($enc !== '') {
            if (function_exists('decrypt') && defined('ENCRYPTION_KEY')) {
                $plain = (string)decrypt($enc, ENCRYPTION_KEY);
                if ($plain !== '') {
                    return $plain;
                }
            }
            /* Stored in the clear: PSI keys start with AIza and never
               contain the base64 padding an encrypted blob would. */
            if (strpos($enc, 'AIza') === 0) {
                return $enc;
            }
        }
    } catch (Throwable $e) {
        // table missing on this install - try the fallbacks below
    }

    if (defined('PSI_API_KEY') && PSI_API_KEY !== '') {
        return (string)PSI_API_KEY;
    }
    $env = getenv('PAGESPEED_API_KEY');
    if ($env === false || $env === '') {
        $env = $_ENV['PAGESPEED_API_KEY'] ?? '';
    }
    return (string)$env;
}

function psi_run_and_store(PDO $pdo, string $instanceId, string $url, string $strategy = 'mobile', string $apiKey = ''): array
{
    $params = [
        'url'      => $url,
        'strategy' => $strategy,
    ];
    if ($apiKey !== '') {
        $params['key'] = $apiKey;
    }

    /* PSI wants the category param repeated, which http_build_query cannot
       express from a plain array - append the four by hand. */
    $qs = http_build_query($params)
        . '&category=performance&category=accessibility'
        . '&category=best-practices&category=seo';

    $ch = curl_init(PSI_ENDPOINT . '?' . $qs);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,   // must expire before the caller's cap
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($body === false || $httpCode !== 200) {
        $msg = $curlErr !== '' ? $curlErr : ('HTTP ' . $httpCode);
        if ($httpCode === 429) {
            $msg = 'PageSpeed daily quota exhausted for this API key.';
        }
        $json = json_decode((string)$body, true);
        if (isset($json['error']['message'])) {
            $msg = $json['error']['message'];
        }
        return psi_store_error($pdo, $instanceId, $url, $strategy, substr($msg, 0, 500));
    }

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['lighthouseResult'])) {
        return psi_store_error($pdo, $instanceId, $url, $strategy, 'Unexpected PSI response');
    }

    $lh    = $data['lighthouseResult'];
    $audit = $lh['audits'] ?? [];

    $cat = function (string $key) use ($lh): ?int {
        return isset($lh['categories'][$key]['score'])
            ? (int)round($lh['categories'][$key]['score'] * 100)
            : null;
    };
    $score = $cat('performance');
    $a11y  = $cat('accessibility');
    $bp    = $cat('best-practices');
    $seo   = $cat('seo');

    $metric = function (string $key) use ($audit): ?float {
        return isset($audit[$key]['numericValue']) ? (float)$audit[$key]['numericValue'] : null;
    };

    $lcp = $metric('largest-contentful-paint');
    $fcp = $metric('first-contentful-paint');
    $tbt = $metric('total-blocking-time');
    $cls = $metric('cumulative-layout-shift');

    /* INP comes from field data (CrUX) when Google has it for this site */
    $inp = null;
    if (isset($data['loadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'])) {
        $inp = (float)$data['loadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'];
    }

    /* Top opportunities: audits with savings, biggest first, keep 3 */
    $opps = [];
    foreach ($audit as $a) {
        if (isset($a['details']['type']) && $a['details']['type'] === 'opportunity'
            && isset($a['details']['overallSavingsMs']) && $a['details']['overallSavingsMs'] > 100) {
            $opps[] = [
                'title'      => (string)($a['title'] ?? ''),
                'savings_ms' => (int)$a['details']['overallSavingsMs'],
            ];
        }
    }
    usort($opps, function ($x, $y) { return $y['savings_ms'] <=> $x['savings_ms']; });
    $opps = array_slice($opps, 0, 3);

    $stmt = $pdo->prepare("
        INSERT INTO psi_results
            (instance_id, url, strategy, perf_score, a11y_score, bp_score, seo_score,
             lcp_ms, cls, inp_ms, fcp_ms, tbt_ms,
             opportunities, raw_status, error_message, fetched_at)
        VALUES
            (:i, :u, :s, :score, :a11y, :bp, :seo, :lcp, :cls, :inp, :fcp, :tbt, :opps, 'ok', NULL, NOW())
        ON DUPLICATE KEY UPDATE
            url = VALUES(url),
            perf_score = VALUES(perf_score),
            a11y_score = VALUES(a11y_score),
            bp_score = VALUES(bp_score),
            seo_score = VALUES(seo_score),
            lcp_ms = VALUES(lcp_ms),
            cls = VALUES(cls),
            inp_ms = VALUES(inp_ms),
            fcp_ms = VALUES(fcp_ms),
            tbt_ms = VALUES(tbt_ms),
            opportunities = VALUES(opportunities),
            raw_status = 'ok',
            error_message = NULL,
            fetched_at = NOW()
    ");
    $stmt->execute([
        ':i' => $instanceId, ':u' => $url, ':s' => $strategy,
        ':score' => $score,
        ':a11y' => $a11y,
        ':bp' => $bp,
        ':seo' => $seo,
        ':lcp' => $lcp !== null ? (int)round($lcp) : null,
        ':cls' => $cls !== null ? round($cls, 3) : null,
        ':inp' => $inp !== null ? (int)round($inp) : null,
        ':fcp' => $fcp !== null ? (int)round($fcp) : null,
        ':tbt' => $tbt !== null ? (int)round($tbt) : null,
        ':opps' => json_encode($opps, JSON_UNESCAPED_SLASHES),
    ]);

    return psi_get_cached($pdo, $instanceId, $strategy);
}

function psi_store_error(PDO $pdo, string $instanceId, string $url, string $strategy, string $msg): array
{
    $stmt = $pdo->prepare("
        INSERT INTO psi_results
            (instance_id, url, strategy, raw_status, error_message, fetched_at)
        VALUES (:i, :u, :s, 'error', :m, NOW())
        ON DUPLICATE KEY UPDATE
            url = VALUES(url), raw_status = 'error',
            error_message = VALUES(error_message), fetched_at = NOW()
    ");
    $stmt->execute([':i' => $instanceId, ':u' => $url, ':s' => $strategy, ':m' => $msg]);
    return psi_get_cached($pdo, $instanceId, $strategy) ?? [
        'raw_status' => 'error', 'error_message' => $msg,
    ];
}
