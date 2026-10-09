<?php
declare(strict_types=1);

// Do not set JSON header — this returns the file bytes.
require_once __DIR__ . '/../includes/config.php';

$attachmentId = isset($_GET['attachment_id']) ? (int) $_GET['attachment_id'] : 0;
$instanceId = trim($_GET['instance_id'] ?? ($_SESSION['instanceid'] ?? ''));

if ($attachmentId <= 0 || $instanceId === '') {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, message_id, ticket_id, instance_id, original_name, stored_name, stored_path, mime_type, size_bytes FROM ticket_attachments WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $attachmentId]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$att) {
        http_response_code(404);
        echo 'Attachment not found';
        exit;
    }

    // instance check
    if ($att['instance_id'] !== $instanceId) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    // $path = $att['stored_path'];
    $path = '/var/www/html/wix/json-ld/' . ltrim($att['stored_path'], '/');
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }

    // Serve the file securely
    $mime = $att['mime_type'] ?: 'application/octet-stream';
    $size = (int) $att['size_bytes'];

    // set headers
    header_remove(); // remove any pre-set headers
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    $disposition = 'attachment'; // force download
    // sanitize original filename for header
    $orig = str_replace(["\r", "\n"], '', $att['original_name']);
    header("Content-Disposition: {$disposition}; filename=\"" . rawurlencode($orig) . "\"");
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    // readfile in chunks to avoid memory issues
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        http_response_code(500);
        echo 'Unable to open file';
        exit;
    }
    while (!feof($fp)) {
        echo fread($fp, 8192);
        flush();
        if (connection_aborted()) break;
    }
    fclose($fp);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Server error';
    exit;
}
