<?php
/**
 * cron/build_ctr_curve.php  (WordPress)
 *
 * Rebuilds each store's own expected-CTR-by-position curve from its own
 * 90 days of data. This is what makes "expected 4.5%" defensible when a
 * merchant challenges the number.
 *
 * Crontab — weekly, and it must run BEFORE insight_engine.php, which
 * reads what this writes:
 *
 *   0 4 * * 0 cd /var/www/html/wordpress/googlesearchconsole \
 *       && /usr/bin/php cron/build_ctr_curve.php >> /var/log/gsc_curve_ecwid.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
global $pdo;

const MIN_IMPR_PER_ROW = 5;    // ignore only very thin rows. These stores are small,
                               // so a floor of 30 left nothing to build a curve from.
const MIN_SAMPLE       = 10;   // rows needed before a position band is considered
const TRUST_SAMPLE     = 30;   // rows needed before a band's own CTR overrides the
                               // global fallback (noise below this inverts the curve)

$instances = $pdo->query("SELECT DISTINCT instance_id FROM gsc_page_query_daily")
                 ->fetchAll(PDO::FETCH_COLUMN);

foreach ($instances as $instanceId) {
    $brand = brandTerms($pdo, $instanceId);

    // Brand queries run at 30-40% CTR and would lift the whole curve,
    // which then makes every ordinary page look under-clicked. Exclude
    // them.
    $brandSql = '';
    $params   = [$instanceId, MIN_IMPR_PER_ROW];
    foreach ($brand as $b) {
        $brandSql .= " AND query NOT LIKE ?";
        $params[]  = '%' . $b . '%';
    }

    $sql = "
      SELECT ROUND(position) AS band,
             SUM(clicks) / SUM(impressions) AS ctr,
             COUNT(*) AS n
      FROM gsc_page_query_daily
      WHERE instance_id = ?
        AND date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        AND impressions >= ?
        AND position BETWEEN 1 AND 20
        {$brandSql}
      GROUP BY band
      HAVING n >= " . MIN_SAMPLE;

    $s = $pdo->prepare($sql);
    $s->execute($params);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "[curve] {$instanceId}: not enough data, using global fallback\n";
        continue;
    }

    $up = $pdo->prepare("INSERT INTO gsc_ctr_curve
                           (instance_id, position_band, expected_ctr, sample_size)
                         VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                           expected_ctr = VALUES(expected_ctr),
                           sample_size  = VALUES(sample_size)");

    // Only trust a band with a solid sample. On small samples the per-band
    // CTR is noisy enough to invert the curve — rank 4 appearing to
    // out-click rank 1, which cannot happen and would hide real gaps.
    // Weak bands are left unwritten so the detector falls back to the
    // global curve for them, which is already smooth and monotonic.
    $written = 0;
    foreach ($rows as $r) {
        if ((int)$r['n'] < TRUST_SAMPLE) continue;
        $up->execute([
            $instanceId,
            (int)$r['band'],
            round((float)$r['ctr'], 4),
            (int)$r['n'],
        ]);
        $written++;
    }

    echo "[curve] {$instanceId}: {$written} bands rebuilt"
       . " (" . (count($rows) - $written) . " too thin, using fallback)\n";
}

/**
 * Brand terms for a store.
 *
 * Brand queries run at 30-40% CTR. Left in, they lift the whole curve, and
 * every ordinary page then looks under-clicked against it — so the curve
 * has to be built with them excluded.
 *
 * Three sources, in order of how much they can be trusted:
 *
 *   1. gsc_domain_verifications.brand_name. This app has that column and
 *      the other two do not, so where the Shopify build had to infer a
 *      brand from the domain, this one can just read it.
 *   2. Any name-ish column WpSite carries — shop_name here. Checked at
 *      runtime rather than assumed: naming a column that does not exist
 *      would throw on every store, and this cron would fail into a log
 *      nobody reads.
 *   3. The registrable label of the store domain, as a last resort.
 *
 * Terms are used as `query NOT LIKE '%term%'`, so a term matching nothing
 * simply excludes nothing. A wrong guess costs nothing; missing the brand
 * costs a curve pulled upward by 35%-CTR queries.
 */
function brandTerms(PDO $pdo, string $instanceId): array
{
    $out = [];

    // ---- 1. the explicit brand name, if one was captured ----
    try {
        $s = $pdo->prepare("SELECT brand_name FROM gsc_domain_verifications
                            WHERE instance_id = ? LIMIT 1");
        $s->execute([$instanceId]);
        $bn = trim((string)$s->fetchColumn());
        if ($bn !== '') {
            $out[] = $bn;
        }
    } catch (Throwable $e) {
        // column or table absent — fall through
    }

    // ---- 2. a name column on WpSite, if there is one ----
    static $nameCols = null;

    if ($nameCols === null) {
        $nameCols = [];
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM WpSite")->fetchAll(PDO::FETCH_COLUMN, 0);
            foreach (['shop_name', 'name', 'store_name', 'company_name', 'business_name', 'title'] as $c) {
                if (in_array($c, $cols, true)) {
                    $nameCols[] = $c;
                }
            }
        } catch (Throwable $e) {
            // no such table on this install — the domain alone will do
        }
    }

    $domain = null;

    try {
        $select = $nameCols
            ? '`' . implode('`, `', $nameCols) . '`, domain'
            : 'domain';

        $s = $pdo->prepare("SELECT {$select} FROM WpSite WHERE instance_id = ? LIMIT 1");
        $s->execute([$instanceId]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach ($nameCols as $c) {
            if (!empty($row[$c])) {
                $out[] = $row[$c];
            }
        }

        $domain = $row['domain'] ?? null;

    } catch (Throwable $e) {
        // leave $out as it is
    }

    // ---- 3. the registrable label of the domain ----
    if ($domain) {
        $host = strtolower(trim((string)$domain));
        $host = preg_replace('#^https?://#i', '', $host);
        $host = preg_replace('#^www\.#i', '', $host);
        $host = explode('/', $host)[0];

        // Take the registrable label, not the first one. Naively taking the
        // first turns dev.example.com into "dev", and '%dev%' then excludes
        // every query containing "device" or "developer" from the curve.
        $labels = explode('.', $host);
        array_pop($labels);   // the TLD

        // Second-level suffixes: brand.co.uk, brand.com.au. After popping
        // 'uk' the last label is 'co', which is not a brand either.
        $secondLevel = ['co', 'com', 'net', 'org', 'ac', 'gov', 'edu'];
        if (count($labels) > 1 && in_array(end($labels), $secondLevel, true)) {
            array_pop($labels);
        }

        $handle = $labels ? (string)end($labels) : '';

        // Platform-hosted domains put the store name in the FIRST label and
        // the platform's own name in the registrable one. An Ecwid Instant
        // Site is <name>.company.site, so the registrable label is
        // "company" for every store on the platform — excluding
        // '%company%' would gut the curve for all of them at once.
        //
        // The same shape shows up for the other platforms whose stores turn
        // up in this database, so they are handled together.
        $platformLabels = ['company', 'mybigcommerce', 'myshopify', 'wixsite', 'ecwid'];

        if (in_array($handle, $platformLabels, true) && count($labels) > 1) {
            $handle = (string)$labels[count($labels) - 2];
        }

        $generic = array_merge(
            ['www', 'dev', 'shop', 'store', 'test', 'staging', 'app', 'my', 'admin'],
            $platformLabels
        );

        if ($handle !== '' && !in_array($handle, $generic, true)) {
            $out[] = $handle;

            // A hyphenated handle is usually words: "petal-and-stone".
            // Joining them catches the domain spelling, which is what
            // people type when they mean the brand.
            if (strpos($handle, '-') !== false) {
                $out[] = str_replace('-', '', $handle);
            }
        }
    }

    // ---- normalise ----
    $terms = [];
    foreach ($out as $t) {
        $t = trim(strtolower((string)$t));
        // Under three characters a term matches far too much.
        if (strlen($t) >= 3) {
            $terms[] = $t;
        }
    }

    return array_slice(array_values(array_unique($terms)), 0, 5);
}
