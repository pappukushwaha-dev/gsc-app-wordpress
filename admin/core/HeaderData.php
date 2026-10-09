<?php
// Include the configuration file to access the database
require_once __DIR__ . '/../../includes/config.php';

/**
 * Fetches application-wide settings and the main admin's role from the database.
 *
 * @param PDO $pdo The PDO database connection object.
 * @return array An associative array containing the fetched settings, or an error message.
 */
function get_app_settings(PDO $pdo): array {
    $settings = [];

    try {
        // Define the keys to fetch from the admin_settings table.
        $settingKeys = ['brand_name', 'admin_email', 'logo_path', 'logo_dark_path','favicon_path'];
        
        // Use a prepared statement to prevent SQL injection, even with static keys.
        $placeholders = implode(', ', array_fill(0, count($settingKeys), '?'));
        $sql = "SELECT key_name, value FROM admin_settings WHERE key_name IN ({$placeholders})";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($settingKeys);

        // Fetch all settings into an associative array.
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['key_name']] = $row['value'];
        }

        // Use the email that actually exists in the admin_users table to fetch the role.
        $admin_user_email = 'admin@makkpress.com'; 
        $role = 'N/A'; // Default value if the role is not found.
        
        // Fetch the role from the admin_users table using the correct email.
        $sqlRole = "SELECT role FROM admin_users WHERE email = :email LIMIT 1";
        $stmtRole = $pdo->prepare($sqlRole);
        $stmtRole->execute([':email' => $admin_user_email]);
        $user = $stmtRole->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $role = $user['role'];
        }
        
        // Add the retrieved role to the settings array.
        $settings['role'] = $role;

    } catch (PDOException $e) {
        // Log the error and return a user-friendly error message.
        error_log("Database error: " . $e->getMessage());
        return ['error' => 'Failed to retrieve application settings.'];
    }

    return $settings;
}

// Fetch the combined settings and user details
$app_settings = get_app_settings($pdo);

// Check if an error occurred during the database fetch.
if (isset($app_settings['error'])) {
    echo $app_settings['error'];
} 
?>