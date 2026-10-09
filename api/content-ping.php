<?php
// api/content-ping.php
declare(strict_types=1);

/**
 * The plugin calls this when the site publishes or updates content.
 * The panel records it (content_pings, last_content_update) - the
 * reporting side reads these, and the panel's crons use them to keep
 * the GSC sitemap fresh for active sites.
 *
 * Body: { instance_id, secret, url }
 * The url is the published post's permalink, kept for the log.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/instance-auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    gscwp_respond(false, ['error' => 'POST required'], 405);
}

$site = gscwp_resolve_instance($pdo);

if (!$site) {
    gscwp_respond(false, ['error' => 'unauthorised'], 401);
}

$raw  = file_get_contents('php://input');
$data = json_decode((string) $raw, true) ?: [];
$url  = substr(trim((string) ($data['url'] ?? '')), 0, 255);

if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
    gscwp_respond(false, ['error' => 'invalid url'], 422);
}

/* A hard cap on the server side too: the plugin enforces the plan's
   daily limit, and this stops a misbehaving plugin from writing
   without end even if its cache is lost. */
$capStmt = $pdo->prepare("
    SELECT content_pings
    FROM WpSite
    WHERE id = :id
      AND last_content_update > DATE_SUB(NOW(), INTERVAL 1 DAY)
      AND content_pings >= 200
    LIMIT 1
");
$capStmt->execute([':id' => (int) $site['id']]);

if ($capStmt->fetchColumn()) {
    gscwp_respond(false, ['error' => 'daily ping limit reached'], 429);
}

try {
    $stmt = $pdo->prepare("
        UPDATE WpSite
        SET content_pings = content_pings + 1,
            last_content_update = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':id' => (int) $site['id']]);

    gscwp_respond(true, ['recorded' => true]);
} catch (Throwable $e) {
    error_log('content-ping: ' . $e->getMessage());
    gscwp_respond(false, ['error' => 'could not record the ping'], 500);
}
