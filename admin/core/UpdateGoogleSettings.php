<?php
require_once __DIR__ . '/../../includes/credentials.php';  // <-- THE FIX
require_once __DIR__ . '/SessionManager.php';
require_once __DIR__ . '/AuthController.php';

SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_BASE . '/admin/settings.php');
    exit;
}

// Collect form data
$clientId     = trim($_POST['client_id'] ?? '');
$clientSecret = trim($_POST['client_secret'] ?? '');
$redirectUri  = trim($_POST['redirect_uri'] ?? '');

$errors = [];
if ($clientId === '')     $errors[] = "Client ID is required";
if ($clientSecret === '') $errors[] = "Client Secret is required";
if ($redirectUri === '')  $errors[] = "Redirect URI is required";

if (!empty($errors)) {
    $_SESSION['flash_error'] = implode('<br>', $errors);
    header('Location: ' . APP_BASE . '/admin/settings.php#google-settings');
    exit;
}

try {
    // Encrypt using encrypt() from credentials.php
    $encSecret = encrypt($clientSecret, ENCRYPTION_KEY);

    // Check if a row exists
    $check = $pdo->query("SELECT id FROM google_settings LIMIT 1");
    $exists = $check->fetchColumn();

    if ($exists) {
        $stmt = $pdo->prepare("
            UPDATE google_settings
            SET client_id = :client_id,
                client_secret = :client_secret,
                redirect_uri = :redirect_uri,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':client_id' => $clientId,
            ':client_secret' => $encSecret,
            ':redirect_uri' => $redirectUri,
            ':id' => $exists
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO google_settings (client_id, client_secret, redirect_uri)
            VALUES (:client_id, :client_secret, :redirect_uri)
        ");
        $stmt->execute([
            ':client_id' => $clientId,
            ':client_secret' => $encSecret,
            ':redirect_uri' => $redirectUri
        ]);
    }

    $_SESSION['flash_success'] = "Google settings saved successfully!";
    header('Location: ' . APP_BASE . '/admin/settings.php#google-settings');
    exit;

} catch (Exception $e) {
    error_log("Google Settings Save Error: " . $e->getMessage());
    $_SESSION['flash_error'] = "Error saving Google settings: " . $e->getMessage();
    header('Location: ' . APP_BASE . '/admin/settings.php#google-settings');
    exit;
}
