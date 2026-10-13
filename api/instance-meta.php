<?php
// api/instance-meta.php
declare(strict_types=1);

/**
 * The plugin pulls what it needs for its on-site work:
 * the GSC verification token (when the wizard has issued one) and the
 * sitemap state. Authenticated with instance_id + secret.
 *
 * GET with query params works too, so the plugin can call it from any
 * context (wp_remote_get keeps it simple). POST JSON is equally fine.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/instance-auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$site = gscwp_resolve_instance($pdo);

if (!$site) {
    $dbg = gscwp_instance_credentials();
    error_log('instance-meta AUTH FAIL: iid=' . substr($dbg['instance_id'], 0, 13)
        . ' secret_len=' . strlen($dbg['secret'])
        . ' method=' . ($_SERVER['REQUEST_METHOD'] ?? '?'));
    gscwp_respond(false, ['error' => 'unauthorised'], 401);
}

/* The plugin may report its sitemap while pulling - one round trip
   for both. Accepted only when it belongs to the same host as the
   registered site, so a plugin cannot point us at someone else's
   sitemap. */
$raw  = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (is_array($data) && !empty($data['sitemap_url'])) {
    $sitemapUrl = substr(trim((string) $data['sitemap_url']), 0, 255);
    $siteHost   = strtolower((string) parse_url($site['site_url'], PHP_URL_HOST));
    $mapHost    = strtolower((string) parse_url($sitemapUrl, PHP_URL_HOST));

    if ($sitemapUrl !== '' && $mapHost !== '' && $mapHost === $siteHost
        && $sitemapUrl !== (string) ($site['sitemap_url'] ?? '')) {
        try {
            $upd = $pdo->prepare("UPDATE WpSite SET sitemap_url = :s WHERE id = :id");
            $upd->execute([':s' => $sitemapUrl, ':id' => (int) $site['id']]);
            $site['sitemap_url'] = $sitemapUrl;
        } catch (Throwable $e) {
            error_log('instance-meta sitemap store: ' . $e->getMessage());
        }
    }
}

// ensure the site has a sync secret for the instant-sync REST route
$syncSecret = (string) ($site['sync_secret'] ?? '');
if ($syncSecret === '') {
    $syncSecret = bin2hex(random_bytes(16));
    try {
        $g = $pdo->prepare("UPDATE WpSite SET sync_secret = :s WHERE id = :id");
        $g->execute([':s' => $syncSecret, ':id' => (int) $site['id']]);
    } catch (Throwable $e) {
        error_log('instance-meta sync_secret: ' . $e->getMessage());
    }
}

/* The site's plan: paid while an active Stripe subscription exists.
   The 'limited' rows are the free-plan marker (billing.php writes
   them when the user picks the free plan). */
$isPaid = false;
try {
    $p = $pdo->prepare("
        SELECT status, billing_period, expires_on
        FROM app_subscriptions
        WHERE instance_id = :iid
        ORDER BY id DESC
        LIMIT 1
    ");
    $p->execute([':iid' => $site['instance_id']]);
    $sub = $p->fetch(PDO::FETCH_ASSOC);

    if ($sub) {
        $notExpired = empty($sub['expires_on']) || strtotime((string) $sub['expires_on']) > time();
        $isPaid = strtolower((string) $sub['status']) === 'active'
            && strtolower((string) $sub['billing_period']) !== 'limited'
            && ($notExpired || empty($sub['expires_on']));
    }
} catch (Throwable $e) {
    error_log('instance-meta plan: ' . $e->getMessage());
}

/* Google connection state: the dashboard has its own disconnect
   action, and the plugin should reflect it rather than claiming a
   live Google link that no longer exists. */
$googleConnected = false;
try {
    $g = $pdo->prepare("
        SELECT connected, access_token, refresh_token
        FROM google_accounts
        WHERE instance_id = :iid
        LIMIT 1
    ");
    $g->execute([':iid' => $site['instance_id']]);
    $ga = $g->fetch(PDO::FETCH_ASSOC);
    $googleConnected = $ga
        && (int) $ga['connected'] === 1
        && (!empty($ga['access_token']) || !empty($ga['refresh_token']));
} catch (Throwable $e) {
    error_log('instance-meta google state: ' . $e->getMessage());
}

/* Fallback: some flows write the token only to gsc_domain_verifications
   (the wizard's copy step runs on page render). When WpSite has no
   token but a verification record does, serve that one and heal the
   canonical column so every later pull is straight. */
$verifyToken = (string) ($site['verify_token'] ?? '');
if ($verifyToken === '') {
    try {
        $v = $pdo->prepare("
            SELECT meta_token
            FROM gsc_domain_verifications
            WHERE instance_id = :iid AND meta_token IS NOT NULL
            ORDER BY updated_at DESC
            LIMIT 1
        ");
        $v->execute([':iid' => $site['instance_id']]);
        $fallback = (string) $v->fetchColumn();
        if ($fallback !== '') {
            $verifyToken = $fallback;
            $u = $pdo->prepare("
                UPDATE WpSite
                SET verify_token = :t
                WHERE id = :id AND (verify_token IS NULL OR verify_token = '')
            ");
            $u->execute([':t' => $fallback, ':id' => (int) $site['id']]);
        }
    } catch (Throwable $e) {
        error_log('instance-meta token fallback: ' . $e->getMessage());
    }
}

gscwp_respond(true, [
    'instance_id'     => $site['instance_id'],
    'site_url'        => $site['site_url'],
    'verify_token'    => $verifyToken,
    'sitemap_url'     => (string) ($site['sitemap_url'] ?? ''),
    'is_verified'     => $verifyToken !== '',
    'sync_secret'     => $syncSecret,
    'is_paid'         => $isPaid,
    'google_connected'=> $googleConnected,
]);
