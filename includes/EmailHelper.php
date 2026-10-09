<?php

use Brevo\Client\Configuration;
use Brevo\Client\Api\TransactionalEmailsApi;
use Brevo\Client\Model\SendSmtpEmail;
use Brevo\Client\ApiException;
use GuzzleHttp\Client;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * IMPORTANT:
 * This key MUST be exactly the same as the one used
 * when you encrypt smtp_username / smtp_password.
 * If you already define ENCRYPTION_KEY in some common file,
 * you can remove this block and just require that file instead.
 */
if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', 'base64:mbZ7aAT6pVa5f4Ratw22WYBP99OAAVXU8Y2l+ZwUsCM=');
}

/**
 * AES-256-CBC encrypt/decrypt helpers
 * (same as in your settings page)
 */
function encrypt($data, $key)
{
    $iv_len   = openssl_cipher_iv_length('aes-256-cbc');
    $iv       = openssl_random_pseudo_bytes($iv_len);
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

function decrypt($data, $key)
{
    $data   = base64_decode($data);
    $iv_len = openssl_cipher_iv_length('aes-256-cbc');

    if (strlen($data) < $iv_len) {
        return false;
    }

    $iv             = substr($data, 0, $iv_len);
    $encrypted_data = substr($data, $iv_len);

    return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
}

/**
 * Helper: get a single setting value from admin_settings
 */
function getAdminSetting(PDO $pdo, string $keyName)
{
    $stmt = $pdo->prepare("
        SELECT value
        FROM admin_settings
        WHERE key_name = :key
        LIMIT 1
    ");
    $stmt->execute([':key' => $keyName]);
    return $stmt->fetchColumn();
}

/**
 * Decrypt value stored in admin_settings (smtp_username, smtp_password, etc.)
 */
function decryptAdminValue(?string $value): ?string
{
    if ($value === null || $value === '') {
        return $value;
    }

    $decrypted = decrypt($value, ENCRYPTION_KEY);

    // If decryption fails, return null (or original value if you prefer)
    if ($decrypted === false) {
        return null;
    }

    return $decrypted;
}

/**
 * Create and configure PHPMailer instance using SMTP settings from admin_settings
 */
function createSmtpMailerFromSettings(PDO $pdo): PHPMailer
{
    $mail = new PHPMailer(true);

    // Read from DB
    $host = getAdminSetting($pdo, 'smtp_host') ?: 'smtp-broadcasts.postmarkapp.com';
    $port = (int) (getAdminSetting($pdo, 'smtp_port') ?: 587);

    // Your settings page uses two flags: smtp_ssl and smtp_tls
    $smtpSslFlag = getAdminSetting($pdo, 'smtp_ssl'); // non-empty means SSL
    $smtpTlsFlag = getAdminSetting($pdo, 'smtp_tls'); // non-empty means TLS

    if (!empty($smtpTlsFlag)) {
        $sslType = 'tls';
    } elseif (!empty($smtpSslFlag)) {
        $sslType = 'ssl';
    } else {
        $sslType = '';
    }

    $userEnc = getAdminSetting($pdo, 'smtp_username');
    $passEnc = getAdminSetting($pdo, 'smtp_password');

    // 🔓 Decrypt username & password
    $username = decryptAdminValue($userEnc);
    $password = decryptAdminValue($passEnc);

    if (empty($host) || empty($username) || empty($password)) {
        throw new \Exception("SMTP configuration incomplete in admin_settings.");
    }

    // OPTIONAL for debugging:
    // $mail->SMTPDebug  = 2;
    // $mail->Debugoutput = 'error_log';

    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->SMTPAuth   = true;
    $mail->Username   = $username;
    $mail->Password   = $password;
    $mail->Port       = $port;
    $mail->CharSet    = 'UTF-8';
    $mail->isHTML(true);

    // Match your Postmark reference script:
    if ($sslType === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($sslType === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = false;
    }

    return $mail;
}

/**
 * Send email using DB template (SMTP if email_provider = 'smtp')
 */
function sendEmailUsingTemplate(
    PDO $pdo,
    string $templateName,
    string $toEmail,
    array $placeholders = [],
     array $attachments = [],
    string $senderEmail = 'noreply@example.com',
    string $senderName  = 'My App'
) {
    $emailProvider = getAdminSetting($pdo, 'email_provider') ?: 'brevo';

    $dbSenderEmail = getAdminSetting($pdo, 'sender_email');
    $dbSenderName  = getAdminSetting($pdo, 'sender_name');

    if (!empty($dbSenderEmail)) {
        $senderEmail = $dbSenderEmail;
    }
    if (!empty($dbSenderName)) {
        $senderName = $dbSenderName;
    }

    $stmt = $pdo->prepare("
        SELECT subject, body
        FROM email_templates
        WHERE name = :name
        LIMIT 1
    ");
    $stmt->execute([':name' => $templateName]);
    $template = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$template) {
        throw new \Exception("Email template '{$templateName}' not found in email_templates.");
    }

    $body    = strtr($template['body'], $placeholders);
$subject = strtr($template['subject'], $placeholders);

    // SMTP via PHPMailer
    if ($emailProvider === 'smtp') {
        try {
            $mail = createSmtpMailerFromSettings($pdo);

            $mail->setFrom($senderEmail, $senderName);
            $mail->addAddress($toEmail);

            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);
        // ✅ ADD ATTACHMENTS HERE
        foreach ($attachments as $filePath => $fileName) {
            if (file_exists($filePath)) {
                $mail->addAttachment($filePath, $fileName);
            }
        }

            $mail->send();
            
            return true;

        } catch (PHPMailerException $e) {
            throw new \Exception("sendEmailUsingTemplate(): SMTP (PHPMailer) error: " . $e->getMessage());
        }
    }

    // Brevo fallback (unchanged)
    $apiKey = getAdminSetting($pdo, 'brevo_api_key');
    if (!$apiKey) {
        throw new \Exception("Brevo API Key missing in admin_settings (key_name = 'brevo_api_key').");
    }

    $config      = Configuration::getDefaultConfiguration()->setApiKey('api-key', $apiKey);
    $apiInstance = new TransactionalEmailsApi(new Client(), $config);

    $email = new SendSmtpEmail([
        'to'          => [['email' => $toEmail]],
        'subject'     => $subject,
        'htmlContent' => $body,
        'sender'      => [
            'email' => $senderEmail,
            'name'  => $senderName,
        ],
    ]);

    try {
        return $apiInstance->sendTransacEmail($email);
    } catch (ApiException $e) {
        throw new \Exception("sendEmailUsingTemplate(): Brevo ApiException: " . $e->getMessage());
    }
}

/**
 * Send a raw HTML email for testing from editor
 */
function sendRawTestEmail(
    PDO $pdo,
    string $toEmail,
    string $subject,
    string $bodyHtml,
    array $placeholders = []
) {
    $emailProvider = getAdminSetting($pdo, 'email_provider') ?: 'brevo';

    $senderEmail = getAdminSetting($pdo, 'sender_email') ?: 'noreply@example.com';
    $senderName  = getAdminSetting($pdo, 'sender_name')  ?: 'My App';

    if (!empty($placeholders)) {
        $subject  = strtr($subject, $placeholders);
        $bodyHtml = strtr($bodyHtml, $placeholders);
    }

    if ($emailProvider === 'smtp') {
        try {
            $mail = createSmtpMailerFromSettings($pdo);

            $mail->setFrom($senderEmail, $senderName);
            $mail->addAddress($toEmail);

            $mail->Subject = $subject;
            $mail->Body    = $bodyHtml;
            $mail->AltBody = strip_tags($bodyHtml);

            $mail->send();
            return true;

        } catch (PHPMailerException $e) {
            throw new \Exception("sendRawTestEmail(): SMTP (PHPMailer) error: " . $e->getMessage());
        }
    }

    $apiKey = getAdminSetting($pdo, 'brevo_api_key');
    if (!$apiKey) {
        throw new \Exception("Brevo API Key missing in admin_settings (key_name = 'brevo_api_key').");
    }

    $config = Configuration::getDefaultConfiguration()->setApiKey('api-key', $apiKey);
    $api    = new TransactionalEmailsApi(new Client(), $config);

    $email = new SendSmtpEmail([
        'to'          => [['email' => $toEmail]],
        'subject'     => $subject,
        'htmlContent' => $bodyHtml,
        'sender'      => [
            'email' => $senderEmail,
            'name'  => $senderName,
        ],
    ]);

    try {
        return $api->sendTransacEmail($email);
    } catch (ApiException $e) {
        throw new \Exception("sendRawTestEmail(): Brevo ApiException: " . $e->getMessage());
    }
}
