<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Load DB, env
require_once __DIR__ . '/config.php';

// Load encryption key from .env
$rawKey = $_ENV['ENCRYPTION_SECRET'] ?? getenv('ENCRYPTION_SECRET') ?: 'your-32-character-secret-key-here!!';

// If key is base64:xxxx, decode it
if (str_starts_with($rawKey, 'base64:')) {
    $decodedKey = base64_decode(substr($rawKey, 7));
    if ($decodedKey === false) {
        error_log("Warning: Failed to decode base64 ENCRYPTION_SECRET. Using raw value.");
        $finalKey = $rawKey;
    } else {
        $finalKey = $decodedKey;
    }
} else {
    $finalKey = $rawKey;
}

// Ensure key is at least 32 bytes for AES-256 (pad if necessary)
if (strlen($finalKey) < 32) {
    error_log("Warning: ENCRYPTION_SECRET is less than 32 bytes. Padding to 32 bytes.");
    $finalKey = str_pad($finalKey, 32, "\0");
} elseif (strlen($finalKey) > 32) {
    // Truncate to 32 bytes if longer
    $finalKey = substr($finalKey, 0, 32);
}

// Define the encryption key
if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', $finalKey);
}

// -------------------------
// Encrypt & Decrypt helpers
// -------------------------
function encrypt($data, $key) {
    $iv_len = openssl_cipher_iv_length('aes-256-cbc');
    $iv = openssl_random_pseudo_bytes($iv_len);
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

function decrypt($cipher, $key) {
    if (empty($cipher) || empty($key)) {
        return false;
    }
    
    // If the cipher doesn't look like base64, it might be plain text
    if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $cipher)) {
        return false; // Not base64 encoded
    }
    
    $decoded = @base64_decode($cipher, true);
    if ($decoded === false) {
        return false; // Invalid base64
    }
    
    $iv_len = openssl_cipher_iv_length('aes-256-cbc');
    if ($iv_len === false) {
        return false; // Cipher not available
    }

    if (strlen($decoded) < $iv_len) {
        return false; // Cipher too short
    }

    $iv = substr($decoded, 0, $iv_len);
    $raw = substr($decoded, $iv_len);

    $result = @openssl_decrypt($raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    
    // If decryption fails, return false (not empty string)
    return ($result !== false && $result !== '') ? $result : false;
}
function get_stripe_credentials(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT key_name, value
        FROM admin_settings
        WHERE key_name IN (
            'stripe_api_key',
            'stripe_secret_api_key',
            'stripe_webhook_secret',
            'stripe_portal_config_key'
        )
    ");
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Decrypt each value
    $publishable = isset($rows['stripe_api_key'])
        ? decrypt($rows['stripe_api_key'], ENCRYPTION_KEY)
        : null;

    $secret = isset($rows['stripe_secret_api_key'])
        ? decrypt($rows['stripe_secret_api_key'], ENCRYPTION_KEY)
        : null;

    $webhook = isset($rows['stripe_webhook_secret'])
        ? decrypt($rows['stripe_webhook_secret'], ENCRYPTION_KEY)
        : null;

    $portal = isset($rows['stripe_portal_config_key'])
        ? decrypt($rows['stripe_portal_config_key'], ENCRYPTION_KEY)
        : null;

    if (!$secret) {
        throw new RuntimeException('Stripe secret key missing or failed to decrypt');
    }

    return [
        'publishable_key' => $publishable,
        'secret_key'      => $secret,
        'webhook_secret'  => $webhook,
        'portal_key'      => $portal,
    ];
}
// -------------------------
// Load Google OAuth credentials
// -------------------------
try {
    $stmtGoogle = $pdo->query("SELECT client_id, client_secret, redirect_uri FROM google_settings LIMIT 1");
    $googleSettings = $stmtGoogle->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $googleSettings = [];
}

$googleClientId        = $googleSettings['client_id'] ?? null;
$googleClientSecretEnc = $googleSettings['client_secret'] ?? null;
$googleRedirectUri     = $googleSettings['redirect_uri'] ?? null;

// DECRYPT Google secret correctly using AES-256
$googleClientSecret = $googleClientSecretEnc ? decrypt($googleClientSecretEnc, ENCRYPTION_KEY) : null;

// -------------------------
// Load Ecwid OAuth credentials
// -------------------------
try {
    $stmtEcwid = $pdo->query("SELECT client_id, client_secret, redirect_uri FROM ecwid_settings LIMIT 1");
    $ecwidSettings = $stmtEcwid->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $ecwidSettings = [];
}

$ecwidClientId        = $ecwidSettings['client_id'] ?? null;
$ecwidClientSecretEnc = $ecwidSettings['client_secret'] ?? null;
$ecwidRedirectUri     = $ecwidSettings['redirect_uri'] ?? null;

// Decrypt Ecwid secret
$ecwidClientSecret = $ecwidClientSecretEnc 
    ? decrypt($ecwidClientSecretEnc, ENCRYPTION_KEY) 
    : null;