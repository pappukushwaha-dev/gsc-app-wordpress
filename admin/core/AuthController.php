<?php

// AuthController.php

require_once __DIR__ . '/../../includes/config.php';

require_once 'SessionManager.php';

class AuthController {
    
    /**
     * Attempts to log in an admin user.
     * @param string $email The submitted email.
     * @param string $password The submitted password.
     * @return array An array with a 'success' boolean and a 'message' string.
     */
    public static function login(string $email, string $password): array {
        global $pdo;

        // Find the user in the database by email
        $query = "SELECT id, username, password FROM admin_users WHERE email = ? AND status = 'active'";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check if user exists and verify password
        if ($user && password_verify($password, $user['password'])) {
            // Update last login timestamp
            $updateQuery = "UPDATE admin_users SET last_login = NOW() WHERE id = ?";
            $updateStmt = $pdo->prepare($updateQuery);
            $updateStmt->execute([$user['id']]);
            
            // Set session variables securely
            SessionManager::createAdminSession($user['id'], $user['username']);

            return ['success' => true, 'message' => 'Login successful. Redirecting...'];
        } else {
            // Log failed login attempt for security auditing
            error_log("Failed admin login attempt for email: {$email}");
            
            // Generic error message to prevent email enumeration attacks
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }
    }

    /**
     * Logs out the current admin user.
     */
    public static function logout(): void {
        SessionManager::destroyAdminSession();
    }
    
    /**
     * Checks if an admin user is currently authenticated.
     * @return bool True if authenticated, false otherwise.
     */
    public static function isAuthenticated(): bool {
        return SessionManager::isAdminLoggedIn();
    }
}