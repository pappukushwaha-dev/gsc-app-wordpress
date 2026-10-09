<?php
// api/push-verify-token.php
declare(strict_types=1);

/**
 * Stores the GSC verification token for an instance, so the plugin can
 * pick it up and inject the meta tag.
 *
 * Two callers:
 *   - the setup wizard (phase 3), after api/google/generate_meta.php
 *     creates the token for the connected site;
 *   - direct, authenticated with the instance secret - which is how
 *     this endpoint is exercised before the wizard exists.
 *
 * Body: { instance_id, secret, token }
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
$token = substr(trim((string) ($data['token'] ?? '')), 0, 255);

if ($token === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
    gscwp_respond(false, ['error' => 'invalid token'], 422);
}

try {
    $stmt = $pdo->prepare("UPDATE WpSite SET verify_token = :token WHERE id = :id");
    $stmt->execute([':token' => $token, ':id' => (int) $site['id']]);

    gscwp_respond(true, ['stored' => true]);
} catch (Throwable $e) {
    error_log('push-verify-token: ' . $e->getMessage());
    gscwp_respond(false, ['error' => 'could not store the token'], 500);
}
