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

gscwp_respond(true, [
    'instance_id'   => $site['instance_id'],
    'site_url'      => $site['site_url'],
    'verify_token'  => (string) ($site['verify_token'] ?? ''),
    'sitemap_url'   => (string) ($site['sitemap_url'] ?? ''),
    'is_verified'   => ($site['verify_token'] ?? '') !== '',
]);
