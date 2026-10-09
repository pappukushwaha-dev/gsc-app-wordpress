<?php
// core/UpdateGeneralSettings.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'SessionManager.php';
require_once 'AuthController.php';
require_once __DIR__ . '/../../includes/config.php';


SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
  header('Location: ' . APP_BASE . '/admin/sign-in.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $brandName = trim($_POST['brandName'] ?? '');
  $adminEmail = trim($_POST['adminEmail'] ?? '');
  $companyName = trim($_POST['companyName'] ?? '');
  $clarityId   = trim($_POST['clarity_id'] ?? '');   // 🔹 ADD THIS

    // IMPORTANT: Check for a valid file upload for both logo and favicon
  $logo_file = isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK ? $_FILES['logo'] : null;
    $favicon_file = isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK ? $_FILES['favicon'] : null;

$logo_dark_file = isset($_FILES['logo_dark']) && $_FILES['logo_dark']['error'] === UPLOAD_ERR_OK ? $_FILES['logo_dark'] : null;

  // Validate required fields
  if (empty($brandName) || empty($adminEmail)) {
    $_SESSION['message'] = ['type' => 'error', 'text' => 'Brand Name and Admin Email are required.'];
    header("Location: ../settings.php?tab=general-settings");
    exit;
  }

  try {
    $pdo->beginTransaction();

    $settingsData = [
      'brand_name' => $brandName,
      'admin_email' => $adminEmail,
      'company_name' => $companyName,
      'clarity_id'   => $clarityId,   // 🔹 SAVE CLARITY HERE
    ];

    // Function to handle file uploads
        function handleFileUpload($file, $uploadDir, $dbPath) {
            // Check for file and no upload error
            if ($file) {
                // Ensure directory exists
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $fileName = uniqid() . '_' . basename($file['name']);
                $filePath = rtrim($uploadDir, '/') . '/' . $fileName;

                if (move_uploaded_file($file['tmp_name'], $filePath)) {
                    return rtrim($dbPath, '/') . '/' . $fileName;
                } else {
                    throw new Exception("Failed to upload file.");
                }
            }
            return null;
        }

    // Handle logo upload
    $logo_upload_dir = __DIR__ . '/../../admin/assets/images/logo/';
    $logo_db_path = APP_BASE . '/admin/assets/images/logo/';
        $logo_path = handleFileUpload($logo_file, $logo_upload_dir, $logo_db_path);

        // Handle favicon upload
        $favicon_upload_dir = __DIR__ . '/../../admin/assets/images/favicon/';
    $favicon_db_path = APP_BASE . '/admin/assets/images/favicon/';
        $favicon_path = handleFileUpload($favicon_file, $favicon_upload_dir, $favicon_db_path);

// Handle logo_dark upload (same folder as logo)
$logo_dark_upload_dir = __DIR__ . '/../../admin/assets/images/logo/';
$logo_dark_db_path    = APP_BASE . '/admin/assets/images/logo/';
$logo_dark_path       = handleFileUpload($logo_dark_file, $logo_dark_upload_dir, $logo_dark_db_path);

if ($logo_dark_path) {
    $settingsData['logo_dark_path'] = $logo_dark_path;
}

        if ($logo_path) {
            $settingsData['logo_path'] = $logo_path;
        }

        if ($favicon_path) {
            $settingsData['favicon_path'] = $favicon_path;
        }
        
        // Prepare a single statement for all updates
        $stmt = $pdo->prepare("INSERT INTO admin_settings (key_name, value) VALUES (:key_name, :value) ON DUPLICATE KEY UPDATE value = :update_value");

        foreach ($settingsData as $key => $value) {
            $stmt->execute([
                ':key_name' => $key,
                ':value' => $value,
                ':update_value' => $value
            ]);
        }


    $pdo->commit();
    $_SESSION['message'] = ['type' => 'success', 'text' => 'General settings updated successfully.'];
    header("Location: ../settings.php?tab=general-settings");
    exit;

  } catch (Exception $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    $_SESSION['message'] = ['type' => 'error', 'text' => 'Database or file upload error: ' . $e->getMessage()];
    header("Location: ../settings.php?tab=general-settings");
    exit;
  }
} else {
  header("Location: ../settings.php?tab=general-settings");
  exit;
}
?>