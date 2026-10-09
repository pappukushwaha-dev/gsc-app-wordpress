<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'SessionManager.php';
require_once 'AuthController.php';
require_once __DIR__ . '/../../includes/config.php';

SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
    header('Location: ../auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        header("Location: ../settings.php?tab=security&status=error&message=" . urlencode('All fields are required.'));
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        header("Location: ../settings.php?tab=security&status=error&message=" . urlencode('New passwords do not match.'));
        exit;
    }

    if (strlen($newPassword) < 8) {
        header("Location: ../settings.php?tab=security&status=error&message=" . urlencode('New password must be at least 8 characters long.'));
        exit;
    }

    try {
        // Change 'password_hash' to 'password'
        $stmt = $pdo->prepare("SELECT password FROM admin_users WHERE id = :id");
        $stmt->execute([':id' => $_SESSION['admin_id']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        // Change 'password_hash' to 'password'
        if ($admin && password_verify($currentPassword, $admin['password'])) {
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            // Change 'password_hash' to 'password'
            $stmtUpdate = $pdo->prepare("UPDATE admin_users SET password = :password_hash, updated_at = NOW() WHERE id = :id");
            $stmtUpdate->execute([
                ':password_hash' => $newPasswordHash,
                ':id' => $_SESSION['admin_id']
            ]);

            header("Location: ../settings.php?tab=security&status=success&message=" . urlencode('Password updated successfully.'));
            exit;
        } else {
            header("Location: ../settings.php?tab=security&status=error&message=" . urlencode('Incorrect current password.'));
            exit;
        }
    } catch (PDOException $e) {
        header("Location: ../settings.php?tab=security&status=error&message=" . urlencode('Database error: ' . $e->getMessage()));
        exit;
    }
} else {
    header("Location: ../settings.php?tab=security");
    exit;
}
?>