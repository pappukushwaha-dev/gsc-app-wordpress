<?php
// api/register-site.php
declare(strict_types=1);

/**
 * The WordPress plugin's answer to a marketplace install.
 *
 * The plugin calls this once (and on every reinstall): "here is my
 * site_url, give me my instance". The endpoint is idempotent on
 * site_url - the same site always gets the same instance_id back, so
 * a deleted-and-reinstalled plugin finds its history intact.
 *
 * Security posture:
 *   - JSON body only, strict validation of every field
 *   - prepared statements only
 *   - per-IP rate limit (a flood of fake registrations cannot fill the
 *     table; the hash of the IP is stored, never the IP itself)
 *   - errors are logged server-side; the client sees a short message
 */

require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

/* POST + JSON only. A GET or a form-encoded body has no business
   here, and refusing it early keeps every later check meaningful. */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, ['error' => 'POST required'], 405);
}
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false) {
    respond(false, ['error' => 'JSON required'], 415);
}

/* A body beyond 4 KB is abuse: a valid registration is a few hundred
   bytes. */
$raw = file_get_contents('php://input');
if (strlen((string) $raw) > 4096) {
    respond(false, ['error' => 'body too large'], 413);
}

function respond(bool $success, array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload + ['success' => $success]);
    exit;
}

/* ---------- input ---------- */

$raw  = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    respond(false, ['error' => 'invalid body'], 400);
}

$siteUrl       = trim((string) ($data['site_url'] ?? ''));
$wpVersion     = substr(trim((string) ($data['wp_version'] ?? '')), 0, 20);
$pluginVersion = substr(trim((string) ($data['plugin_version'] ?? '')), 0, 20);

$host   = parse_url($siteUrl, PHP_URL_HOST);
$scheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));

if ($siteUrl === '' || !$host || !in_array($scheme, ['http', 'https'], true)) {
    respond(false, ['error' => 'invalid site_url'], 422);
}

try {

    /* ---------- rate limit: 30 attempts per IP hash per hour ---------- */

    $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|gscwp-salt');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gscwp_rate_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_hash CHAR(64) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ip_time (ip_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $rateStmt = $pdo->prepare("
        SELECT COUNT(*) FROM gscwp_rate_log
        WHERE ip_hash = :ip_hash
          AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $rateStmt->execute([':ip_hash' => $ipHash]);

    if ((int) $rateStmt->fetchColumn() >= 30) {
        respond(false, ['error' => 'too many attempts, try later'], 429);
    }

    $logStmt = $pdo->prepare("INSERT INTO gscwp_rate_log (ip_hash) VALUES (:ip_hash)");
    $logStmt->execute([':ip_hash' => $ipHash]);

    /* ---------- upsert on site_url ---------- */

    $find = $pdo->prepare("SELECT id, instance_id FROM WpSite WHERE site_url = :site_url LIMIT 1");
    $find->execute([':site_url' => $siteUrl]);
    $existing = $find->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $upd = $pdo->prepare("
            UPDATE WpSite
            SET wp_version = :wp_version,
                plugin_version = :plugin_version,
                is_active = 1,
                last_seen = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            ':wp_version'     => $wpVersion,
            ':plugin_version' => $pluginVersion,
            ':id'             => (int) $existing['id'],
        ]);

        respond(true, ['instance_id' => (string) $existing['instance_id']]);
    }

    /* ---------- new registration ---------- */

    // UUID v4-shaped instance id (same feel as the marketplace ids).
    $instanceId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(
        bin2hex(random_bytes(16)),
        4
    ));

    $ins = $pdo->prepare("
        INSERT INTO WpSite
            (instance_id, site_url, wp_version, plugin_version, email, is_active, last_seen)
        VALUES
            (:instance_id, :site_url, :wp_version, :plugin_version, '', 1, NOW())
    ");
    $ins->execute([
        ':instance_id'    => $instanceId,
        ':site_url'       => $siteUrl,
        ':wp_version'     => $wpVersion,
        ':plugin_version' => $pluginVersion,
    ]);

    respond(true, ['instance_id' => $instanceId]);

} catch (Throwable $e) {
    error_log('register-site: ' . $e->getMessage());
    respond(false, ['error' => 'could not register the site'], 500);
}
