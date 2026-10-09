<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/EmailHelper.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];

// -------------------- Basic validation --------------------
$ticketId   = (int)($input['ticket_id'] ?? 0);
$message    = trim($input['message'] ?? '');
$ownerEmail = trim($input['owner_email'] ?? '');
$websiteUrl = trim($input['website_url'] ?? '');

if ($ticketId <= 0 || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

// -------------------- Build attachments (FROM DB) --------------------
$attachments = [];

$stmt = $pdo->prepare("
    SELECT stored_path, original_name
    FROM ticket_attachments
    WHERE ticket_id = :ticket_id
");
$stmt->execute([
    ':ticket_id' => $ticketId
]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!empty($row['stored_path']) && file_exists($row['stored_path'])) {
        $attachments[$row['stored_path']] = $row['original_name'];
    }
}


// -------------------- Placeholders --------------------
$placeholdersUser = [
    '{{ticket_number}}' => (string)$ticketId,
    '{{user_name}}'    => $ownerEmail,
];

$placeholdersAdmin = [
    '{{User_Email}}'                => $ownerEmail,
      '{{Website_URL}}' => $websiteUrl !== '' ? $websiteUrl : 'N/A',
    '{{User_Message_Body}}'         => nl2br(htmlspecialchars($message)),
    '{{Attachment_Link_or_Status}}' => !empty($attachments)
        ? 'Attached to this email'
        : 'No attachment'
];

// -------------------- Send CUSTOMER email --------------------
sendEmailUsingTemplate(
    $pdo,
    'Ticket Created',
    $ownerEmail,
 $placeholdersUser
);

// -------------------- Send ADMIN email --------------------
sendEmailUsingTemplate(
    $pdo,
    'Ticket Created for admin',
    'kajal.kesarwani@makkpress.com',
    $placeholdersAdmin,
    $attachments
);

echo json_encode(['success' => true]);
