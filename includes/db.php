<?php
// includes/db.php  (phase 1 bootstrap)
declare(strict_types=1);

// Env first (containers), falls back to known local defaults.
$gscwpEnv = static function (string $key, string $default): string {
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === '') ? $default : (string) $v;
};

$gscwpHost = $gscwpEnv('DB_HOST', '127.0.0.1');
$gscwpPort = $gscwpEnv('DB_PORT', '3306');
$gscwpName = $gscwpEnv('DB_NAME', 'wordpress-googlesearchconsole');
$gscwpUser = $gscwpEnv('DB_USER', 'root');
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
