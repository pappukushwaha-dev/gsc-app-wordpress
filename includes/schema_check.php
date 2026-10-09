<?php
/**
 * includes/schema_check.php
 *
 * Fetches a page on the USER'S site and reports which structured data
 * (JSON-LD) types it carries. Results are cached in schema_results per
 * instance+URL, so the Action Center drawer can call this freely.
 *
 * No Google API involved - this is a plain page fetch + parse.
 */

if (!defined('SCHEMA_CACHE_DAYS')) define('SCHEMA_CACHE_DAYS', 7);
if (!defined('SCHEMA_FETCH_TIMEOUT')) define('SCHEMA_FETCH_TIMEOUT', 12);
if (!defined('SCHEMA_MAX_BYTES')) define('SCHEMA_MAX_BYTES', 2097152); // 2 MB guard

/**
 * Main entry. Returns the cached row when fresh, otherwise fetches,
 * parses and stores. Shape:
 * ['url','found_types'=>[],'block_count','invalid_blocks','fetched_at']
 * Throws RuntimeException on fetch failure with a user-safe message.
 */
function schema_check_url(PDO $pdo, $instanceId, $url, $force = false)
{
    $hash = sha1($url);

    $s = $pdo->prepare("SELECT url, found_types, block_count, invalid_blocks, fetched_at
                        FROM schema_results
                        WHERE instance_id = ? AND url_hash = ? LIMIT 1");
    $s->execute([$instanceId, $hash]);
    $row = $s->fetch(PDO::FETCH_ASSOC);

    if ($row && !$force) {
        $ageDays = (time() - strtotime($row['fetched_at'])) / 86400;
        if ($ageDays < SCHEMA_CACHE_DAYS) {
            $row['found_types'] = json_decode($row['found_types'] ?: '[]', true) ?: [];
            $row['block_count'] = (int)$row['block_count'];
            $row['invalid_blocks'] = (int)$row['invalid_blocks'];
            return $row;
        }
    }

    $html = schema_fetch_page($url);
    $parsed = schema_parse_jsonld($html);

    $up = $pdo->prepare("INSERT INTO schema_results
            (instance_id, url_hash, url, found_types, block_count, invalid_blocks, fetched_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            url = VALUES(url),
            found_types = VALUES(found_types),
            block_count = VALUES(block_count),
            invalid_blocks = VALUES(invalid_blocks),
            fetched_at = NOW()");
    $up->execute([
        $instanceId, $hash, $url,
        json_encode(array_values($parsed['types'])),
        $parsed['blocks'], $parsed['invalid'],
    ]);

    return [
        'url' => $url,
        'found_types' => array_values($parsed['types']),
        'block_count' => $parsed['blocks'],
        'invalid_blocks' => $parsed['invalid'],
        'fetched_at' => date('Y-m-d H:i:s'),
    ];
}

/** Plain GET with redirects, timeout and a size guard. */
function schema_fetch_page($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => SCHEMA_FETCH_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MakkPressApps-SchemaCheck/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) {
            return ($dlNow > SCHEMA_MAX_BYTES) ? 1 : 0; // abort oversized pages
        },
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('The page could not be fetched (' . ($err ?: 'network error') . ')');
    }
    if ($code >= 400) {
        throw new RuntimeException('The page returned HTTP ' . $code);
    }
    return $body;
}

/**
 * Pulls every <script type="application/ld+json"> block and collects
 * @type values, including inside @graph and top-level arrays.
 * Returns ['types' => unique list, 'blocks' => n, 'invalid' => n].
 */
function schema_parse_jsonld($html)
{
    $types = [];
    $blocks = 0;
    $invalid = 0;

    if (!preg_match_all(
        '#<script[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
        $html, $m
    )) {
        return ['types' => [], 'blocks' => 0, 'invalid' => 0];
    }

    foreach ($m[1] as $raw) {
        $blocks++;
        $json = json_decode(trim($raw), true);
        if ($json === null) { $invalid++; continue; }
        schema_collect_types($json, $types);
    }

    return ['types' => array_values(array_unique($types)), 'blocks' => $blocks, 'invalid' => $invalid];
}

/** Walks one decoded JSON-LD document for @type values. */
function schema_collect_types($node, array &$types)
{
    if (!is_array($node)) return;

    // top-level array of entities
    if (array_keys($node) === range(0, count($node) - 1)) {
        foreach ($node as $item) schema_collect_types($item, $types);
        return;
    }

    if (isset($node['@type'])) {
        foreach ((array)$node['@type'] as $t) {
            if (is_string($t) && $t !== '') $types[] = $t;
        }
    }
    if (isset($node['@graph']) && is_array($node['@graph'])) {
        foreach ($node['@graph'] as $item) schema_collect_types($item, $types);
    }
}


/* =====================================================================
 * Live rich results validation, Rich Results Test style.
 *
 * Fetches the live page, parses every JSON-LD block (including @graph and
 * arrays), and validates each item of a Google rich-result type against
 * Google's documented required and recommended fields. Required missing =
 * error (item invalid), recommended missing = warning. Types Google does
 * not make rich results from are listed separately, not validated.
 *
 * One-time schema:
 *   CREATE TABLE IF NOT EXISTS schema_live_results (
 *     instance_id VARCHAR(191) NOT NULL,
 *     url_hash    CHAR(40) NOT NULL,
 *     url         VARCHAR(768) NOT NULL,
 *     payload     MEDIUMTEXT NOT NULL,
 *     fetched_at  DATETIME NOT NULL,
 *     PRIMARY KEY (instance_id, url_hash)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
 * ===================================================================== */

/**
 * Google's rich results field rules. req = required (error when absent),
 * rec = recommended (warning when absent). one_of = at least one of the
 * listed fields is required. Kept to Google's search gallery docs.
 */
function schema_rr_rules(): array
{
    return [
        'Product' => [
            'req' => ['name'],
            'one_of' => [['offers', 'review', 'aggregateRating']],
            'rec' => ['image', 'description', 'sku', 'brand', 'aggregateRating', 'review', 'offers'],
        ],
        'Offer' => [
            'req' => ['price', 'priceCurrency'],
            'rec' => ['availability', 'url', 'priceValidUntil', 'itemCondition'],
        ],
        'AggregateOffer' => [
            'req' => ['lowPrice', 'priceCurrency'],
            'rec' => ['highPrice', 'offerCount'],
        ],
        'FAQPage' => [
            'req' => ['mainEntity'],
            'rec' => [],
        ],
        'Question' => [
            'req' => ['name', 'acceptedAnswer'],
            'rec' => [],
        ],
        'Answer' => [
            'req' => ['text'],
            'rec' => [],
        ],
        'BreadcrumbList' => [
            'req' => ['itemListElement'],
            'rec' => [],
        ],
        'ListItem' => [
            'req' => ['position'],
            'one_of' => [['name', 'item']],
            'rec' => [],
        ],
        'Organization' => [
            'req' => ['name'],
            'rec' => ['url', 'logo', 'sameAs', 'contactPoint', 'address'],
        ],
        'LocalBusiness' => [
            'req' => ['name', 'address'],
            'rec' => ['telephone', 'openingHoursSpecification', 'geo', 'priceRange', 'image', 'url'],
        ],
        'WebSite' => [
            'req' => ['name', 'url'],
            'rec' => ['potentialAction'],
        ],
        'Article' => [
            'req' => ['headline'],
            'rec' => ['image', 'datePublished', 'dateModified', 'author', 'publisher'],
        ],
        'BlogPosting' => ['alias' => 'Article'],
        'NewsArticle' => ['alias' => 'Article'],
        'Event' => [
            'req' => ['name', 'startDate', 'location'],
            'rec' => ['image', 'endDate', 'description', 'offers', 'performer', 'organizer', 'eventStatus', 'eventAttendanceMode'],
        ],
        'Recipe' => [
            'req' => ['name', 'image'],
            'rec' => ['author', 'datePublished', 'description', 'prepTime', 'cookTime', 'totalTime',
                      'recipeIngredient', 'recipeInstructions', 'aggregateRating', 'video', 'keywords',
                      'recipeYield', 'recipeCategory', 'recipeCuisine', 'nutrition'],
        ],
        'VideoObject' => [
            'req' => ['name', 'thumbnailUrl', 'uploadDate'],
            'rec' => ['description', 'duration', 'contentUrl', 'embedUrl', 'interactionStatistic', 'expires'],
        ],
        'JobPosting' => [
            'req' => ['title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation'],
            'rec' => ['baseSalary', 'employmentType', 'validThrough', 'identifier'],
        ],
        'Review' => [
            'req' => ['itemReviewed', 'reviewRating', 'author'],
            'rec' => ['datePublished'],
        ],
        'AggregateRating' => [
            'req' => ['ratingValue'],
            'one_of' => [['reviewCount', 'ratingCount']],
            'rec' => [],
        ],
        'HowTo' => [
            'req' => ['name', 'step'],
            'rec' => ['image', 'totalTime', 'estimatedCost', 'supply', 'tool', 'video'],
        ],
        'SoftwareApplication' => [
            'req' => ['name', 'offers', 'aggregateRating'],
            'rec' => ['applicationCategory', 'operatingSystem'],
        ],
        'ItemList' => [
            'req' => ['itemListElement'],
            'rec' => [],
        ],
    ];
}

/** Field present and non-empty on a JSON-LD node. */
function schema_rr_has(array $node, string $field): bool
{
    if (!array_key_exists($field, $node)) {
        return false;
    }
    $v = $node[$field];
    if ($v === null || $v === '' || $v === []) {
        return false;
    }
    return true;
}

/**
 * Validate one node of a known type. Recurses into the sub-structures
 * Google validates as part of the parent (offers, FAQ questions,
 * breadcrumb elements, ratings, reviews).
 */
function schema_rr_validate_node(array $node, string $type, array $rules): array
{
    $errors = [];
    $warnings = [];

    $rule = $rules[$type] ?? null;
    if ($rule && isset($rule['alias'])) {
        $rule = $rules[$rule['alias']] ?? null;
    }
    if (!$rule) {
        return ['errors' => [], 'warnings' => []];
    }

    foreach ($rule['req'] ?? [] as $f) {
        if (!schema_rr_has($node, $f)) {
            $errors[] = "Missing field \"{$f}\"";
        }
    }
    foreach ($rule['one_of'] ?? [] as $group) {
        $ok = false;
        foreach ($group as $f) {
            if (schema_rr_has($node, $f)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            $errors[] = 'Missing one of "' . implode('" or "', $group) . '"';
        }
    }
    foreach ($rule['rec'] ?? [] as $f) {
        if (!schema_rr_has($node, $f)) {
            $warnings[] = "Missing field \"{$f}\" (optional)";
        }
    }

    /* ---- sub-structure checks, prefixed so the user sees where ---- */
    $subChecks = [
        'Product'        => [['offers', ['Offer', 'AggregateOffer']], ['aggregateRating', ['AggregateRating']], ['review', ['Review']]],
        'FAQPage'        => [['mainEntity', ['Question']]],
        'Question'       => [['acceptedAnswer', ['Answer']]],
        'BreadcrumbList' => [['itemListElement', ['ListItem']]],
        'Recipe'         => [['aggregateRating', ['AggregateRating']]],
        'Article'        => [],
    ];
    foreach ($subChecks[$type] ?? [] as [$field, $childTypes]) {
        if (!schema_rr_has($node, $field)) {
            continue;
        }
        $children = $node[$field];
        if (isset($children['@type']) || !is_array($children) || (is_array($children) && array_keys($children) !== range(0, count($children) - 1))) {
            $children = [$children];
        }
        $i = 0;
        foreach ($children as $child) {
            $i++;
            if (!is_array($child)) {
                continue;
            }
            $ct = $child['@type'] ?? $childTypes[0];
            $ct = is_array($ct) ? (string)reset($ct) : (string)$ct;
            if (!in_array($ct, $childTypes, true)) {
                $ct = $childTypes[0];
            }
            $sub = schema_rr_validate_node($child, $ct, $rules);
            $label = $field . (count($children) > 1 ? " #{$i}" : '');
            foreach ($sub['errors'] as $e) {
                $errors[] = "{$label}: {$e}";
            }
            foreach ($sub['warnings'] as $w) {
                $warnings[] = "{$label}: {$w}";
            }
        }
    }

    return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
}

/** Flatten a decoded JSON-LD block into top-level typed nodes. */
function schema_rr_collect_nodes($json, array &$out): void
{
    if (!is_array($json)) {
        return;
    }
    // plain array of things
    if (array_keys($json) === range(0, count($json) - 1)) {
        foreach ($json as $j) {
            schema_rr_collect_nodes($j, $out);
        }
        return;
    }
    if (isset($json['@graph']) && is_array($json['@graph'])) {
        foreach ($json['@graph'] as $j) {
            schema_rr_collect_nodes($j, $out);
        }
    }
    if (isset($json['@type'])) {
        $out[] = $json;
    }
}

/**
 * Main entry, Rich Results Test style. Shape:
 *  source 'live', url, verdict PASS|PARTIAL|FAIL|NEUTRAL,
 *  valid_count, invalid_count,
 *  items: [{type, name, valid, errors[], warnings[]}],
 *  other_types: [types with no rich result treatment],
 *  block_count, invalid_blocks, checked_at, cached
 */
function schema_validate_url(PDO $pdo, $instanceId, $url, $force = false)
{
    $hash = sha1($url);

    if (!$force) {
        $s = $pdo->prepare("SELECT payload, fetched_at FROM schema_live_results
                            WHERE instance_id = ? AND url_hash = ? LIMIT 1");
        $s->execute([$instanceId, $hash]);
        if ($row = $s->fetch(PDO::FETCH_ASSOC)) {
            if ((time() - strtotime((string)$row['fetched_at'])) < SCHEMA_CACHE_DAYS * 86400) {
                $cached = json_decode((string)$row['payload'], true);
                if (is_array($cached)) {
                    $cached['cached'] = true;
                    return $cached;
                }
            }
        }
    }

    $html = schema_fetch_page($url);

    $blocks = 0;
    $invalid = 0;
    $nodes = [];
    if (preg_match_all(
        '#<script[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
        $html, $m
    )) {
        foreach ($m[1] as $raw) {
            $blocks++;
            $json = json_decode(trim($raw), true);
            if ($json === null && trim($raw) !== 'null') {
                $invalid++;
                continue;
            }
            schema_rr_collect_nodes($json, $nodes);
        }
    }

    $rules = schema_rr_rules();
    /* Sub-types validated only inside their parents, not as page items,
       matching how the Rich Results Test groups things. */
    $subOnly = ['Offer', 'AggregateOffer', 'Question', 'Answer', 'ListItem', 'AggregateRating'];

    $items = [];
    $other = [];
    foreach ($nodes as $node) {
        $t = $node['@type'];
        $t = is_array($t) ? (string)reset($t) : (string)$t;
        $ruleKey = isset($rules[$t]) ? $t : null;
        if ($ruleKey && isset($rules[$ruleKey]['alias'])) {
            // validated under the alias rule, displayed under its own name
        }
        if ($ruleKey === null || in_array($t, $subOnly, true)) {
            if (!in_array($t, $other, true) && !in_array($t, $subOnly, true)) {
                $other[] = $t;
            }
            continue;
        }
        $r = schema_rr_validate_node($node, $t, $rules);
        $name = '';
        foreach (['name', 'headline', 'title'] as $nf) {
            if (schema_rr_has($node, $nf) && is_string($node[$nf])) {
                $name = mb_substr($node[$nf], 0, 80);
                break;
            }
        }
        $items[] = [
            'type'     => $t,
            'name'     => $name,
            'valid'    => count($r['errors']) === 0,
            'errors'   => $r['errors'],
            'warnings' => $r['warnings'],
        ];
    }

    $validCount   = count(array_filter($items, fn($i) => $i['valid']));
    $invalidCount = count($items) - $validCount;
    if (!count($items)) {
        $verdict = 'NEUTRAL';
    } elseif ($invalidCount === 0) {
        $verdict = count(array_filter($items, fn($i) => $i['warnings'])) ? 'PARTIAL' : 'PASS';
    } elseif ($validCount === 0) {
        $verdict = 'FAIL';
    } else {
        $verdict = 'PARTIAL';
    }

    $payload = [
        'source'         => 'live',
        'url'            => $url,
        'verdict'        => $verdict,
        'valid_count'    => $validCount,
        'invalid_count'  => $invalidCount,
        'items'          => $items,
        'other_types'    => $other,
        'block_count'    => $blocks,
        'invalid_blocks' => $invalid,
        'checked_at'     => date(DATE_ATOM),
        'cached'         => false,
    ];

    $w = $pdo->prepare("INSERT INTO schema_live_results (instance_id, url_hash, url, payload, fetched_at)
                        VALUES (?, ?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()");
    $w->execute([$instanceId, $hash, mb_substr($url, 0, 760), json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

    return $payload;
}
