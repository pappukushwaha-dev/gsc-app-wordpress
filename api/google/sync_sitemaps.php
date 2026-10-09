<?php
// api/google/sync_sitemaps.php  (WordPress)
//
// Pulls every sitemap Search Console holds for this property and writes it
// into the local `sitemaps` table.
//
// This is what fills the columns a submit can never fill. The submit call
// returns an empty 204 body, so last_downloaded, is_index, warnings and
// errors have always stayed NULL/0. They only exist in sitemaps.list, which
// is what this file reads.
//
// TWO WAYS IN
//
//   1. As an HTTP endpoint, exactly as before. POST {instanceId, prune?}
//      or ?instanceId=. The response shape has not changed.
//
//   2. As a library. require_once this file and call gsc_sync_sitemaps().
//      check_sitemap_status.php uses this: it has already resolved the
//      property and fetched the list on every step-3 load, so it hands both
//      over and the sync costs nothing but the database writes.
//
// The previous version was top-level script only. Requiring it ran the whole
// endpoint and echoed a second JSON body into the caller's response, so the
// logic below now lives in functions and the endpoint is a thin wrapper that
// only fires when this file is requested directly.
//
// The unique key on (instance_id, sitemap_url) is no longer required. The
// write path checks for an existing row first, so it is correct whether or
// not 01-fix-duplicates.sql was ever run.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/google/get_account.php';
require_once __DIR__ . '/../../includes/google/token_manager.php';


// =========================================================================
// HELPERS
// =========================================================================

function gsc_sync_get(string $url, string $token, int $timeout = 15): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'Ecwid-GSC/1.0',
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return ['body' => is_string($body) ? $body : '', 'http' => $http, 'err' => $err];
}

/** Google sends RFC 3339 timestamps; MySQL DATETIME wants Y-m-d H:i:s. */
function gsc_sync_to_mysql_date(?string $iso): ?string
{
    if (!$iso) {
        return null;
    }
    $ts = strtotime($iso);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/**
 * Reduce a URL to "host-without-www + path" so that http/https and www
 * differences do not cause a mismatch.
 */
function gsc_sync_norm_key(string $url): string
{
    $url   = preg_replace('#^https?://#i', '', trim($url));
    $parts = explode('/', (string)$url, 2);
    $host  = preg_replace('#^www\.#i', '', strtolower($parts[0]));
    $path  = isset($parts[1]) ? rtrim('/' . $parts[1], '/') : '';
    return $host . $path;
}


// =========================================================================
// PROPERTY RESOLUTION
// =========================================================================

/**
 * Returns [property, error]. Exactly one is non-null.
 */
function gsc_sync_resolve_property(string $storedSite, string $accessToken): array
{
    $res = gsc_sync_get('https://www.googleapis.com/webmasters/v3/sites', $accessToken, 12);

    if ($res['http'] === 401 || $res['http'] === 403) {
        return [null, ['error' => 'account_needs_reconnect']];
    }

    $entries  = json_decode($res['body'], true)['siteEntry'] ?? [];
    $want     = gsc_sync_norm_key(rtrim($storedSite, '/'));
    $wantHost = explode('/', $want, 2)[0];

    // 1) URL-prefix property matching on host + path
    foreach ($entries as $e) {
        $sv = (string)($e['siteUrl'] ?? '');
        if ($sv === '' || stripos($sv, 'sc-domain:') === 0) continue;
        if (gsc_sync_norm_key($sv) === $want) return [$sv, null];
    }

    // 2) domain property covering this host
    foreach ($entries as $e) {
        $sv = (string)($e['siteUrl'] ?? '');
        if (stripos($sv, 'sc-domain:') !== 0) continue;
        if (strtolower(preg_replace('#^www\.#i', '', substr($sv, 10))) === $wantHost) {
            return [$sv, null];
        }
    }

    // 3) only one property on the account — it must be this one
    if (count($entries) === 1) {
        return [(string)($entries[0]['siteUrl'] ?? ''), null];
    }

    return [null, [
        'error'   => 'property_not_found',
        'message' => 'No Search Console property on this account matches the saved site URL.',
    ]];
}


// =========================================================================
// WRITING THE ROWS
// =========================================================================

/**
 * Writes Google's sitemap list into the table. Returns the summary that
 * ends up in the response.
 *
 * There is no ON DUPLICATE KEY UPDATE here any more. That needed a unique
 * key on (instance_id, sitemap_url), and without it every sync silently
 * inserted another copy of every row. Checking for the row first is one
 * extra query per sitemap — a handful per property — and is correct on any
 * schema, with or without the key.
 */
function gsc_sync_write_rows(
    PDO $pdo,
    string $instanceId,
    string $domainForDb,
    array $sitemaps,
    bool $prune = false
): array {
    $exists = $pdo->prepare(
        "SELECT COUNT(*) FROM sitemaps WHERE instance_id = ? AND sitemap_url = ?"
    );

    // submission_count is absent from both statements on purpose. It counts
    // how many times this app submitted the sitemap, and a sync is not a
    // submission — bumping it would make the number meaningless. New rows
    // take the column default.
    $insert = $pdo->prepare("
        INSERT INTO sitemaps
            (instance_id, domain, sitemap_url, last_submitted_at, last_downloaded,
             status, is_index, warnings, errors, discovered_pages,
             last_synced_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
    ");

    // last_synced_at is stamped on every row touched. It is what lets the
    // page tell "we never asked Google" apart from "we asked and Google has
    // not read the file yet" — both of which leave last_downloaded NULL.
    $update = $pdo->prepare("
        UPDATE sitemaps SET
            domain           = ?,
            last_submitted_at = ?,
            last_downloaded  = ?,
            status           = ?,
            is_index         = ?,
            warnings         = ?,
            errors           = ?,
            discovered_pages = ?,
            last_synced_at   = NOW(),
            updated_at       = NOW()
        WHERE instance_id = ? AND sitemap_url = ?
    ");

    $synced   = [];
    $seenUrls = [];
    $inserted = 0;
    $updated  = 0;

    foreach ($sitemaps as $sm) {
        $path = (string)($sm['path'] ?? '');
        if ($path === '') continue;

        $errors   = (int)($sm['errors'] ?? 0);
        $warnings = (int)($sm['warnings'] ?? 0);
        $pending  = !empty($sm['isPending']);

        $status = $errors > 0 ? 'Error' : ($pending ? 'Pending' : 'Success');

        // contents[] is split by type. A product sitemap carrying one image
        // per product returns web=12 AND image=12, so summing every entry
        // reported 24 pages for a file holding 12. Search Console counts
        // only the web entries as "discovered pages".
        //
        // contents[].indexed is deliberately ignored: Google stopped
        // populating it and it always comes back as 0, which would read as
        // "nothing is indexed" and alarm people for no reason.
        $submittedUrls = 0;
        foreach (($sm['contents'] ?? []) as $c) {
            $type = strtolower((string)($c['type'] ?? ''));
            if ($type === 'image' || $type === 'video') continue;
            $submittedUrls += (int)($c['submitted'] ?? 0);
        }

        $lastSubmitted  = gsc_sync_to_mysql_date($sm['lastSubmitted']  ?? null);
        $lastDownloaded = gsc_sync_to_mysql_date($sm['lastDownloaded'] ?? null);
        $isIndex        = !empty($sm['isSitemapsIndex']) ? 1 : 0;

        try {
            $exists->execute([$instanceId, $path]);
            $isNew = ((int)$exists->fetchColumn() === 0);

            if ($isNew) {
                $insert->execute([
                    $instanceId, $domainForDb, $path,
                    $lastSubmitted, $lastDownloaded, $status,
                    $isIndex, $warnings, $errors, $submittedUrls,
                ]);
                $inserted++;
            } else {
                $update->execute([
                    $domainForDb,
                    $lastSubmitted, $lastDownloaded, $status,
                    $isIndex, $warnings, $errors, $submittedUrls,
                    $instanceId, $path,
                ]);
                $updated++;
            }
        } catch (Throwable $e) {
            error_log('sync_sitemaps.php write failed for ' . $path . ': ' . $e->getMessage());
            continue;
        }

        $seenUrls[] = $path;
        $synced[]   = [
            'path'           => $path,
            'lastSubmitted'  => $sm['lastSubmitted']  ?? null,
            'lastDownloaded' => $sm['lastDownloaded'] ?? null,
            'status'         => $status,
            'isIndex'        => !empty($sm['isSitemapsIndex']),
            'type'           => $sm['type'] ?? null,
            'warnings'       => $warnings,
            'errors'         => $errors,
            'submittedUrls'  => $submittedUrls,
        ];
    }

    // -----------------------------------------------------------------
    // OPTIONAL PRUNE
    //
    // Off by default: a sitemap can be missing from the list for a moment
    // after submitting, and a row the user just created should not be
    // deleted because of that.
    // -----------------------------------------------------------------
    $pruned = 0;

    if ($prune) {
        try {
            if ($seenUrls) {
                $ph   = implode(',', array_fill(0, count($seenUrls), '?'));
                $stmt = $pdo->prepare(
                    "DELETE FROM sitemaps WHERE instance_id = ? AND sitemap_url NOT IN ({$ph})"
                );
                $stmt->execute(array_merge([$instanceId], $seenUrls));
            } else {
                // Google lists nothing for this property, so nothing should remain
                $stmt = $pdo->prepare("DELETE FROM sitemaps WHERE instance_id = ?");
                $stmt->execute([$instanceId]);
            }
            $pruned = $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('sync_sitemaps.php prune failed: ' . $e->getMessage());
        }
    }

    return [
        'total'    => count($synced),
        'inserted' => $inserted,
        'updated'  => $updated,
        'pruned'   => $pruned,
        'sitemaps' => $synced,
    ];
}


// =========================================================================
// THE SYNC
// =========================================================================

/**
 * @param array $opt
 *   accessToken  string  skip the token lookup
 *   property     string  skip property resolution
 *   sitemaps     array   Google's raw sitemap[] array, if already fetched
 *   siteUrl      string  the saved site_url, if already read
 *   prune        bool    delete local rows Google no longer lists
 *
 * Passing property + sitemaps together means no request goes to Google at
 * all — the caller already made them. That is how check_sitemap_status.php
 * uses this, and it is why a sync on every step-3 load costs nothing extra.
 */
function gsc_sync_sitemaps(PDO $pdo, string $instanceId, array $opt = []): array
{
    $prune    = !empty($opt['prune']);
    $property = $opt['property'] ?? null;
    $sitemaps = $opt['sitemaps'] ?? null;
    $siteUrl  = $opt['siteUrl']  ?? null;

    // ---- saved site URL ----
    if ($siteUrl === null) {
        $stmt = $pdo->prepare("SELECT site_url FROM gsc_domain_verifications WHERE instance_id = ? LIMIT 1");
        $stmt->execute([$instanceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['site_url'])) {
            return ['success' => false, 'error' => 'Domain not found for this instance'];
        }
        $siteUrl = (string)$row['site_url'];
    }

    $storedSite  = trim($siteUrl);
    $domainForDb = rtrim($storedSite, '/') . '/';

    // Everything already supplied — go straight to the writes.
    if ($property !== null && $sitemaps !== null) {
        return ['success' => true, 'property' => $property, 'source' => 'caller']
             + gsc_sync_write_rows($pdo, $instanceId, $domainForDb, $sitemaps, $prune);
    }

    // ---- token ----
    $accessToken = $opt['accessToken'] ?? null;

    if ($accessToken === null) {
        $google = getGoogleAccountByInstance($instanceId);
        if (!$google) {
            return ['success' => false, 'error' => 'Google account not connected'];
        }

        $tokenResult = ensureAccessToken((int)$google['id'], 120);
        if (empty($tokenResult['success'])) {
            $code = $tokenResult['error'] ?? 'refresh_failed';
            return [
                'success' => false,
                'error'   => ($code === 'invalid_grant' || $code === 'account_disconnected')
                             ? 'account_needs_reconnect' : 'token_failed',
                'code'    => $code,
            ];
        }
        $accessToken = $tokenResult['access_token'];
    }

    // ---- property ----
    if ($property === null) {
        [$property, $propErr] = gsc_sync_resolve_property($storedSite, $accessToken);
        if ($propErr) {
            return ['success' => false] + $propErr;
        }
    }

    // ---- the list ----
    if ($sitemaps === null) {
        $res = gsc_sync_get(
            'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/sitemaps',
            $accessToken
        );

        if ($res['err']) {
            error_log("sync_sitemaps.php cURL error for {$instanceId}: {$res['err']}");
            return ['success' => false, 'error' => 'network_error', 'debug' => $res['err']];
        }
        if ($res['http'] === 401 || $res['http'] === 403) {
            return ['success' => false, 'error' => 'account_needs_reconnect', 'http' => $res['http']];
        }
        if ($res['http'] < 200 || $res['http'] >= 300) {
            return [
                'success'  => false,
                'error'    => 'google_error',
                'http'     => $res['http'],
                'response' => json_decode($res['body'], true) ?: $res['body'],
            ];
        }

        $sitemaps = json_decode($res['body'], true)['sitemap'] ?? [];
    }

    return ['success' => true, 'property' => $property, 'source' => 'google']
         + gsc_sync_write_rows($pdo, $instanceId, $domainForDb, $sitemaps, $prune);
}


// =========================================================================
// HTTP ENTRY POINT
//
// Only fires when this file is requested directly. Being required from
// check_sitemap_status.php defines the functions above and nothing else.
// =========================================================================

if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {

    header('Content-Type: application/json; charset=utf-8');

    $raw  = file_get_contents('php://input');
    $data = json_decode((string)$raw, true) ?: [];

    $instanceId = $data['instanceId'] ?? ($_GET['instanceId'] ?? null);

    if (!$instanceId) {
        echo json_encode(['success' => false, 'error' => 'Missing instanceId']);
        exit;
    }

    try {
        $result = gsc_sync_sitemaps($pdo, (string)$instanceId, [
            'prune' => !empty($data['prune']),
        ]);
    } catch (Throwable $e) {
        error_log('sync_sitemaps.php failed for ' . $instanceId . ': ' . $e->getMessage());
        http_response_code(500);
        $result = ['success' => false, 'error' => 'sync_failed'];
    }

    echo json_encode($result, JSON_UNESCAPED_SLASHES);
}
