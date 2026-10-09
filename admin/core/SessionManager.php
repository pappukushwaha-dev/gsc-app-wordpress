<?php

// SessionManager.php

// NOTE: This file should be included *before* session_start() in your entry points.

class SessionManager {
    
    /**
     * Configures PHP to use the database for session storage.
     */
    public static function startDatabaseSession(): void {
        global $pdo;
        
        // Check if session is already started
        if (session_status() === PHP_SESSION_NONE) {
            
            // Set up a custom session handler to use the database
            session_set_save_handler(
                function($path, $name) { return true; }, // open
                function() { return true; }, // close
                function($id) use ($pdo) { // read
                    $query = "SELECT data FROM sessions WHERE id = ? AND timestamp > ?";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([$id, time() - (int)ini_get('session.gc_maxlifetime')]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $row ? (string)$row['data'] : '';
                },
                function($id, $data) use ($pdo) { // write
                    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $timestamp = time();
                    
                    $query = "INSERT INTO sessions (id, ip_address, user_agent, data, timestamp) 
                              VALUES (?, ?, ?, ?, ?) 
                              ON DUPLICATE KEY UPDATE ip_address = ?, user_agent = ?, data = ?, timestamp = ?";
                    $stmt = $pdo->prepare($query);
                    return $stmt->execute([
                        $id, $ip_address, $user_agent, $data, $timestamp,
                        $ip_address, $user_agent, $data, $timestamp
                    ]);
                },
                function($id) use ($pdo) { // destroy
                    $query = "DELETE FROM sessions WHERE id = ?";
                    $stmt = $pdo->prepare($query);
                    return $stmt->execute([$id]);
                },
                function($lifetime) use ($pdo) { // gc (garbage collection)
                    $query = "DELETE FROM sessions WHERE timestamp < ?";
                    $stmt = $pdo->prepare($query);
                    return $stmt->execute([time() - (int)$lifetime]);
                }
            );
            
            // Start the session
            session_start();
        }
    }

    /**
     * Creates a new admin session after successful authentication.
     * * @param int $adminId The admin user's ID.
     * @param string $username The admin's username.
     */
    public static function createAdminSession(int $adminId, string $username): void {
        session_regenerate_id(true); // Prevent session fixation attack
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $adminId;
        $_SESSION['admin_username'] = $username;
        $_SESSION['session_start_time'] = time();
        $_SESSION['last_activity'] = time();
    }
    
    /**
     * Destroys the current admin session and logs the user out.
     */
    public static function destroyAdminSession(): void {
        global $pdo;
        
        // Get session ID before destroying
        $sessionId = session_id();
        
        // Clear all session variables
        $_SESSION = [];
        
        // Delete session from database if using database sessions
        if ($sessionId && $pdo) {
            try {
                $stmt = $pdo->prepare("DELETE FROM sessions WHERE id = ?");
                $stmt->execute([$sessionId]);
            } catch (Throwable $e) {
                error_log("Failed to delete session from database: " . $e->getMessage());
            }
        }
        
        // Clear session cookie
        $params = session_get_cookie_params();

setcookie(session_name(), '', [
    'expires'  => time() - 42000,
    'path'     => $params['path'],
    'domain'   => $params['domain'],
    'secure'   => $params['secure'],
    'httponly' => $params['httponly'],
    'samesite' => $params['samesite'] ?? 'Lax',
]);

        
        // Destroy the session
        session_destroy();
        
        // Start a new session to ensure clean state
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }
    
    /**
     * Checks if an admin is logged in. Includes session hijacking prevention.
     * * @return bool True if logged in, false otherwise.
     */
    public static function isAdminLoggedIn(): bool {
        if (session_status() === PHP_SESSION_NONE) {
            // Start the database session if it hasn't been started already.
            self::startDatabaseSession();
        }
        
        // Session variable check
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            return false;
        }

        // Security check: Check for session expiration (e.g., after 30 minutes of inactivity)
        $max_lifetime = 1800; // 30 minutes
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $max_lifetime)) {
            self::destroyAdminSession();
            return false;
        }
        
        // Update last activity timestamp on successful check
        $_SESSION['last_activity'] = time();
        
        // Additional security check: Validate against IP address and user agent
        // NOTE: This can cause issues with users behind proxies, but is good practice.
        /*
        if (
            (!isset($_SESSION['ip_address']) || $_SESSION['ip_address'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) ||
            (!isset($_SESSION['user_agent']) || $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? ''))
        ) {
            self::destroyAdminSession();
            return false;
        }
        */
        
        return true;
    }
}