<?php
// includes/db.php  (WordPress platform)
declare(strict_types=1);

// Loads a .env file from the app root when present (KEY=VALUE lines,
// # comments, optional quotes). Existing environment entries are never
// overwritten, so real environment variables still win.
$gscwpEnvFile = dirname(__DIR__) . '/.env';
if (is_file($gscwpEnvFile)) {
    foreach (file($gscwpEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        $len = strlen($val);
        if ($len >= 2 && (($val[0] === '"' && $val[$len - 1] === '"') || ($val[0] === "'" && $val[$len - 1] === "'"))) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

// Env first (containers), falls back to known local defaults.
$gscwpEnv = static function (string $key, string $default): string {
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === '') ? $default : (string) $v;
};

$gscwpHost = $gscwpEnv('DB_HOST', '127.0.0.1');
$gscwpPort = $gscwpEnv('DB_PORT', '3306');
$gscwpName = $gscwpEnv('DB_NAME', 'wordpress-googlesearchconsole');
$gscwpUser = $gscwpEnv('DB_USER', 'gscwp_user');
$gscwpPass = $gscwpEnv('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host={$gscwpHost};port={$gscwpPort};dbname={$gscwpName};charset=utf8mb4",
        $gscwpUser,
        $gscwpPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log('gsc-app-wordpress db: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database unavailable']);
    exit;
}
