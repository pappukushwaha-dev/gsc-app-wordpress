<?php
// core/UpdateEmailSettings.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'SessionManager.php';
require_once 'AuthController.php';
require_once __DIR__ . '/../../includes/config.php';

SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
    header('Location: ../sign-in.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senderName = trim($_POST['senderName'] ?? '');
    $senderEmail = trim($_POST['senderEmail'] ?? '');
    $brevoApiKey = trim($_POST['brevoApiKey'] ?? '');
    $testEmail = trim($_POST['testEmail'] ?? '');
    $action = $_POST['action'] ?? '';

    // Validate required fields
    if ($action === 'save' && (empty($senderName) || empty($senderEmail) || empty($brevoApiKey))) {
        header("Location: ../settings.php?tab=email-settings&status=error&message=" . urlencode('All fields are required.'));
        exit;
    }

    try {
        if ($action === 'save') {
            $pdo->beginTransaction();

            $settingsData = [
                'sender_name' => $senderName,
                'sender_email' => $senderEmail,
                'brevo_api_key' => $brevoApiKey,
            ];

            // Corrected SQL statement with new parameter for update
            $stmt = $pdo->prepare("INSERT INTO admin_settings (key_name, value) VALUES (:key_name, :value) ON DUPLICATE KEY UPDATE value = :update_value");
            
            foreach ($settingsData as $key => $value) {
                // Corrected: Bind the value a second time
                $stmt->execute([
                    ':key_name' => $key,
                    ':value' => $value,
                    ':update_value' => $value
                ]);
            }

            $pdo->commit();
            header("Location: ../settings.php?tab=email-settings&status=success&message=" . urlencode('Email settings updated successfully.'));
            exit;

        } elseif ($action === 'test' && !empty($testEmail)) {
            // Include Brevo's library for sending email
            require_once __DIR__ . '/../../vendor/autoload.php';

            // Fetch the API key from the database for the test
            $stmt = $pdo->prepare("SELECT value FROM admin_settings WHERE key_name = 'brevo_api_key'");
            $stmt->execute();
            $brevoApiKey = $stmt->fetchColumn();

            if (!$brevoApiKey) {
                throw new Exception('Brevo API Key not found in settings.');
            }

            $config = Brevo\Client\Configuration::getDefaultConfiguration()->setApiKey('api-key', $brevoApiKey);
            $apiInstance = new Brevo\Client\Api\TransactionalEmailsApi(new GuzzleHttp\Client(), $config);
            $sendSmtpEmail = new \Brevo\Client\Model\SendSmtpEmail([
                'to' => [['email' => $testEmail, 'name' => 'Test User']],
                'subject' => 'Test Email from Your Application',
                'htmlContent' => 'This is a test email sent from the admin panel.',
                'sender' => ['email' => $senderEmail, 'name' => $senderName]
            ]);

            $apiInstance->sendTransacEmail($sendSmtpEmail);
            header("Location: ../settings.php?tab=email-settings&status=success&message=" . urlencode('Test email sent successfully to ' . $testEmail));
            exit;

        } else {
            throw new Exception('Invalid action or missing email for test.');
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: ../settings.php?tab=email-settings&status=error&message=" . urlencode('Error: ' . $e->getMessage()));
        exit;
    }
} else {
    header("Location: ../settings.php?tab=email-settings");
    exit;
}
?>