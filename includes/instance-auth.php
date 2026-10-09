<?php
// includes/instance-auth.php
declare(strict_types=1);

/**
 * Shared authentication for the plugin -> panel endpoints
 * (instance-meta, content-ping, push-verify-token).
 *
 * The plugin proves itself with instance_id + secret. The secret was
 * handed out once at registration and the panel only ever stored its
 * hash. Every helper here uses prepared statements.
 */

require_once __DIR__ . '/config.php';

/** Reads instance_id + secret from a JSON body or query string. */
function gscwp_instance_credentials(): array
{
    $raw  = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        $data = $_GET;
    }

    return [
        'instance_id' => substr(trim((string) ($data['instance_id'] ?? '')), 0, 64),
        'secret'      => trim((string) ($data['secret'] ?? '')),
    ];
}

/**
 * Resolves the WpSite row when the credentials are valid.
 *
 * @return array|null the site row, or null when anything does not match
 */
function gscwp_resolve_instance(PDO $pdo): ?array
{
    ['instance_id' => $instanceId, 'secret' => $secret] = gscwp_instance_credentials();

    if ($instanceId === '' || $secret === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, instance_id, site_url, email, secret_hash,
               verify_token, sitemap_url, is_active
        FROM WpSite
        WHERE instance_id = :iid
        LIMIT 1
    ");
    $stmt->execute([':iid' => $instanceId]);
    $site = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$site || (int) $site['is_active'] !== 1 || (string) $site['secret_hash'] === '') {
        return null;
    }

    if (!hash_equals((string) $site['secret_hash'], hash('sha256', $secret))) {
        return null;
    }

    unset($site['secret_hash']);
    return $site;
}

function gscwp_respond(bool $success, array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload + ['success' => $success]);
    exit;
}
