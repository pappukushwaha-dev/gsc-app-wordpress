<?php
/**
 * includes/site_health.php
 *
 * One place that answers two questions about a connected site:
 * how fast is it, and is the structured data Google needs present.
 *
 * Both answers come from caches other features already fill - the PSI
 * speed card writes psi_results, the schema check writes
 * schema_live_results - so this file never calls an external API and is
 * safe to run on every page load, including the navbar bell.
 *
 * Used by action-center.php (full two-column block inside each insight
 * card) and partials/action-center-bell.php (one compact line each).
 *
 * When porting to another platform, only the two links below change to
 * that platform's app store listing.
 */

if (!defined('GSC_WS_APP_LINK')) {
    define('GSC_WS_APP_LINK', 'https://websitespeedy.com');
}
if (!defined('GSC_SCHEMA_APP_LINK')) {
    define('GSC_SCHEMA_APP_LINK', 'https://jsonschemaapp.com');
}

if (!function_exists('gsc_health_app_links')) {

    /**
     * Where the "fix this" links point.
     *
     * The apps we cross-sell are already listed in the other_apps table
     * (the same rows the sidebar's "Our Other Apps" block renders), so the
     * button_link stored there is the single source of truth - change the
     * URL once in that table and every insight, panel and bell follows.
     * The constants above are only a fallback for an install whose table
     * is empty or missing.
     *
     * @return array{speed:string, schema:string}
     */
    function gsc_health_app_links(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $links = ['speed' => GSC_WS_APP_LINK, 'schema' => GSC_SCHEMA_APP_LINK];

        try {
            $rows = $pdo->query("SELECT title, button_link FROM other_apps
                                 WHERE is_index = 'enable'
                                 ORDER BY sort_order ASC, created_at DESC")
                        ->fetchAll(PDO::FETCH_ASSOC);

            $speedSet  = false;
            $schemaSet = false;
            foreach ($rows as $row) {
                $title = strtolower((string)($row['title'] ?? ''));
                $link  = trim((string)($row['button_link'] ?? ''));
                if ($link === '') { continue; }

                if (!$speedSet && (strpos($title, 'speedy') !== false || strpos($title, 'speed') !== false)) {
                    $links['speed'] = $link;
                    $speedSet = true;
                    continue;
                }
                if (!$schemaSet && (strpos($title, 'schema') !== false || strpos($title, 'snippet') !== false)) {
                    $links['schema'] = $link;
                    $schemaSet = true;
                }
            }
        } catch (Throwable $e) {
            // no other_apps table on this install - constants stand
        }

        $cache = $links;
        return $cache;
    }
}

if (!function_exists('gsc_site_health')) {

    /**
     * @return array{
     *   speed: int|null,
     *   schema: array{checked:bool, missing:array, invalid:int, site_type:string, want:array},
     *   links: array{speed:string, schema:string}
     * }
     */
    function gsc_site_health(PDO $pdo, string $instanceId): array
    {
        $out = [
            'links'  => gsc_health_app_links($pdo),
            'speed'  => null,
            'schema' => [
                'checked'   => false,
                'missing'   => [],
                'invalid'   => 0,
                'site_type' => 'general',
                'want'      => [],
            ],
        ];

        if ($instanceId === '') {
            return $out;
        }

        /* ---------- speed: latest cached mobile PSI result ---------- */
        try {
            if (!function_exists('psi_get_cached')) {
                $psiPath = __DIR__ . '/psi.php';
                if (is_file($psiPath)) {
                    require_once $psiPath;
                }
            }
            if (function_exists('psi_get_cached')) {
                $psiRow = psi_get_cached($pdo, $instanceId, 'mobile');
                if ($psiRow && ($psiRow['raw_status'] ?? '') === 'ok' && $psiRow['perf_score'] !== null) {
                    $out['speed'] = (int)$psiRow['perf_score'];
                }
            }
        } catch (Throwable $e) {
            $out['speed'] = null;
        }

        /* ---------- schema: site type, then the gaps for that type ---------- */
        try {
            $siteType = 'general';
            $tp = $pdo->prepare("SELECT page FROM gsc_page_query_daily
                                 WHERE instance_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)
                                 GROUP BY page ORDER BY SUM(impressions) DESC LIMIT 30");
            $tp->execute([$instanceId]);
            $ecN = 0;
            $blN = 0;
            foreach ($tp->fetchAll(PDO::FETCH_COLUMN) as $pg) {
                $pg = strtolower((string)$pg);
                if (preg_match('#/(product|products|shop|store|collection|collections|cart)(/|$)#', $pg)) { $ecN++; }
                if (preg_match('#/(blog|post|posts|article|articles|news)(/|$)#', $pg)) { $blN++; }
            }
            if ($ecN >= 2 && $ecN >= $blN) { $siteType = 'ecommerce'; }
            elseif ($blN >= 2)             { $siteType = 'blog'; }

            $want = ['Organization', 'WebSite', 'BreadcrumbList'];
            if ($siteType === 'ecommerce')  { $want = array_merge(['Product', 'Review'], $want); }
            elseif ($siteType === 'blog')   { $want = array_merge(['Article'], $want); }

            $found    = [];
            $invalid  = 0;
            $rowsSeen = 0;
            $q = $pdo->prepare("SELECT payload FROM schema_live_results
                                WHERE instance_id = ? ORDER BY fetched_at DESC LIMIT 8");
            $q->execute([$instanceId]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $pl) {
                $p = json_decode((string)$pl, true);
                if (!is_array($p)) { continue; }
                $rowsSeen++;
                foreach (($p['items'] ?? []) as $it) {
                    $found[(string)$it['type']] = true;
                    if (empty($it['valid'])) { $invalid++; }
                }
                foreach (($p['other_types'] ?? []) as $t) { $found[(string)$t] = true; }
            }

            $missing = [];
            foreach ($want as $w) {
                $hit = isset($found[$w]);
                if ($w === 'Article' && (isset($found['BlogPosting']) || isset($found['NewsArticle']))) { $hit = true; }
                if (!$hit) { $missing[] = $w; }
            }

            $out['schema'] = [
                'checked'   => $rowsSeen > 0,
                'missing'   => $missing,
                'invalid'   => $invalid,
                'site_type' => $siteType,
                'want'      => $want,
            ];
        } catch (Throwable $e) {
            // schema_live_results missing: the Action Center page runs the
            // first check itself, so leaving 'checked' false is correct.
        }

        return $out;
    }
}
