<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/credentials.php'; // ✅ USE GLOBAL ENCRYPTION
require_once 'SessionManager.php';
require_once 'AuthController.php';

SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
    header('Location: ../sign-in.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../settings.php');
    exit;
}

$data = [
    'stripe_api_key'           => trim($_POST['stripe_api_key'] ?? ''),
    'stripe_secret_api_key'    => trim($_POST['stripe_secret_api_key'] ?? ''),
    'stripe_webhook_secret'    => trim($_POST['stripe_webhook_secret'] ?? ''),
    'stripe_portal_config_key' => trim($_POST['stripe_portal_config_key'] ?? ''),
];

// validation
foreach ($data as $value) {
    if ($value === '') {
        header('Location: ../settings.php?tab=stripe-integration&status=error');
        exit;
    }
}

$sql = "
INSERT INTO admin_settings (key_name, value)
VALUES (:key_name, :value)
ON DUPLICATE KEY UPDATE value = VALUES(value)
";

$stmt = $pdo->prepare($sql);
$pdo->beginTransaction();

foreach ($data as $key => $value) {
    $stmt->execute([
        ':key_name' => $key,
        ':value'    => encrypt($value, ENCRYPTION_KEY) // ✅ SAME SYSTEM
    ]);
}

$pdo->commit();

header('Location: ../settings.php?tab=stripe-integration&status=success');
exit;