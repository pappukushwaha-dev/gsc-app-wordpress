<?php
require_once __DIR__ . '/../../includes/credentials.php';
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

/* ----------------------------------------
   Collect Form Data
-----------------------------------------*/
$clientId      = trim($_POST['ghl_client_id'] ?? '');
$clientSecret  = trim($_POST['ghl_client_secret'] ?? '');
$redirectUri   = trim($_POST['ghl_redirect_uri'] ?? '');

$errors = [];

if ($clientId === '')      $errors[] = "Client ID is required";
if ($clientSecret === '')  $errors[] = "Client Secret is required";
if ($redirectUri === '')   $errors[] = "Redirect URI is required";

if (!empty($errors)) {
    $_SESSION['flash_error'] = implode('<br>', $errors);
    header('Location: ' . APP_BASE . '/admin/settings.php#ghl-settings');
    exit;
}

try {

    // Encrypt client secret
    $encSecret = encrypt($clientSecret, ENCRYPTION_KEY);

    // Check if record exists
    $check  = $pdo->query("SELECT id FROM ecwid_settings LIMIT 1");
    $exists = $check->fetchColumn();

    if ($exists) {

        $stmt = $pdo->prepare("
            UPDATE ecwid_settings
            SET client_id     = :client_id,
                client_secret = :client_secret,
                redirect_uri  = :redirect_uri,
                updated_at    = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            ':client_id'     => $clientId,
            ':client_secret' => $encSecret,
            ':redirect_uri'  => $redirectUri,
            ':id'            => $exists
        ]);

    } else {

        $stmt = $pdo->prepare("
            INSERT INTO ecwid_settings (client_id, client_secret, redirect_uri)
            VALUES (:client_id, :client_secret, :redirect_uri)
        ");

        $stmt->execute([
            ':client_id'     => $clientId,
            ':client_secret' => $encSecret,
            ':redirect_uri'  => $redirectUri
        ]);
    }

    $_SESSION['flash_success'] = "Ecwid settings saved successfully!";
    header('Location: ' . APP_BASE . '/admin/settings.php#ghl-settings');
    exit;

} catch (Exception $e) {

    error_log("Ecwid Settings Save Error: " . $e->getMessage());

    $_SESSION['flash_error'] = "Error saving settings: " . $e->getMessage();
    header('Location: ' . APP_BASE . '/admin/settings.php#ghl-settings');
    exit;
}