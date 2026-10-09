<?php
declare(strict_types=1);

// Require the core files for authentication and session management
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';

// Start the session to ensure the session handler is active before destruction
SessionManager::startDatabaseSession();

// Call the logout method from the AuthController
AuthController::logout();

// Ensure output is clean before redirect
if (ob_get_level()) {
    ob_end_clean();
}

// Redirect the user back to the login page
header('Location: sign-in.php');
exit;