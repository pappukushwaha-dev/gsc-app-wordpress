<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Basic setup
require_once __DIR__ . '/../includes/config.php'; // provides $pdo and session
// Ensure uploads dir is outside webroot and writable
// You can set UPLOAD_DIR in config.php; otherwise default below:
// $defaultUploadDir = __DIR__ . '/../uploads/ticket_attachments';
// $uploadDir = defined('UPLOAD_DIR') && UPLOAD_DIR ? UPLOAD_DIR : $defaultUploadDir;
$uploadDir = '/var/www/html/wix/googlesearchconsole/assets/uploads/ticket_attachments';

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create upload directory']);
        exit;
    }
}

// Allowed mime types (adjust as needed)
$ALLOWED_MIME = [
    'image/png','image/jpeg','image/gif','image/webp',
    'application/pdf','text/plain',
    'application/zip','application/x-zip-compressed',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/msword'
];

// Max size (bytes) - 8 MB default; change if required
$MAX_BYTES = 8 * 1024 * 1024;

// -------------------- Input validation --------------------
$instanceId = trim($_POST['instance_id'] ?? ($_SESSION['instanceid'] ?? ''));
$ticketId   = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
$messageId  = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;

// file field must be "file"
if (empty($instanceId) || $ticketId <= 0 || $messageId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing instance_id, ticket_id or message_id']);
    exit;
}

if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Upload error code: ' . $file['error']]);
    exit;
}

if ($file['size'] > $MAX_BYTES) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'File too large. Max ' . ($MAX_BYTES/1024/1024) . ' MB']);
    exit;
}

// use finfo to detect mime
$finfo = new finfo(FILEINFO_MIME_TYPE);
$detectedMime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
if (!in_array($detectedMime, $ALLOWED_MIME, true)) {
    http_response_code(415);
    echo json_encode(['success' => false, 'error' => 'File type not allowed: ' . $detectedMime]);
    exit;
}

// -------------------- Security checks: verify ticket belongs to instance --------------------
try {
    $stmt = $pdo->prepare("SELECT id FROM tickets WHERE id = :id AND instance_id = :i");
    $stmt->execute([':id' => $ticketId, ':i' => $instanceId]);
    $tk = $stmt->fetchColumn();
    if (!$tk) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Ticket not found for this instance']);
        exit;
    }

    // Also ensure message belongs to ticket & instance (defensive)
    $stmt = $pdo->prepare("SELECT id FROM ticket_messages WHERE id = :mid AND ticket_id = :tid AND instance_id = :i");
    $stmt->execute([':mid' => $messageId, ':tid' => $ticketId, ':i' => $instanceId]);
    $msgExists = $stmt->fetchColumn();
    if (!$msgExists) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Message not found for ticket/instance']);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    exit;
}

// -------------------- Move file to storage --------------------
$origName = basename($file['name']);
$ext = pathinfo($origName, PATHINFO_EXTENSION);

// generate safe random filename
try {
    $rand = bin2hex(random_bytes(16));
} catch (Exception $e) {
    $rand = uniqid('', true);
}
$storedName = $rand . ($ext ? '.' . $ext : '');
// $targetPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedName;
// Physical path for saving the file
$targetPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedName;

// ✅ Relative path for DB storage
$dbPath = 'assets/uploads/ticket_attachments/' . $storedName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file']);
    exit;
}

// set restrictive permissions
@chmod($targetPath, 0644);

// -------------------- Insert DB record --------------------
try {
    $ins = $pdo->prepare("
        INSERT INTO ticket_attachments
        (message_id, ticket_id, instance_id, original_name, stored_name, stored_path, mime_type, size_bytes)
        VALUES (:message_id, :ticket_id, :instance_id, :original_name, :stored_name, :stored_path, :mime_type, :size_bytes)
    ");
    $ins->execute([
        ':message_id' => $messageId,
        ':ticket_id' => $ticketId,
        ':instance_id' => $instanceId,
        ':original_name' => $origName,
        ':stored_name' => $storedName,
        // ':stored_path' => $targetPath,
        ':stored_path' => $dbPath,
        ':mime_type' => $detectedMime,
        ':size_bytes' => $file['size']
    ]);
    $attachmentId = (int)$pdo->lastInsertId();

    // Return a download URL that points to secure serve endpoint
    // e.g. api/serve_ticket_attachment.php?attachment_id=123&instance_id=...
    $serveUrl = sprintf('%s/api/serve_ticket_attachment.php?attachment_id=%d&instance_id=%s',
        rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''), '/'),
        $attachmentId,
        urlencode($instanceId)
    );

    echo json_encode([
        'success' => true,
        'attachment_id' => $attachmentId,
        'original_name' => $origName,
        'mime_type' => $detectedMime,
        'size_bytes' => $file['size'],
        'url' => $serveUrl
    ]);
    exit;
} catch (Throwable $e) {
    // cleanup file if DB insert failed
    @unlink($targetPath);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    exit;
}
