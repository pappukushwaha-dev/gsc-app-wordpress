<?php
declare(strict_types=1);

// Encharge Webhook
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: text/plain; charset=utf-8');

// Always allow POST (Encharge uses POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(200);
    echo 'OK';
    exit;
}

$rawPayload = file_get_contents('php://input');

// Log payload
file_put_contents(
    __DIR__ . '/encharge_webhook_log.txt',
    "==== " . date('Y-m-d H:i:s') . " ====\n" . $rawPayload . "\n\n",
    FILE_APPEND
);

// Decode JSON
$data = json_decode($rawPayload, true);

if (!is_array($data)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

/* ==========================================================
   UNRESOLVED MERGE TAGS

   When a field does not exist on the person, Encharge posts the
   tag itself: the value arrives as the literal string
   "{{person.digest_clicks}}". Stored, that reads like data and
   renders in the admin preview as though the email really said
   it, which is worse than an empty value because nobody goes
   looking for it.
========================================================== */
foreach ($data as $k => $v) {
    if (is_string($v) && preg_match('/^\s*\{\{.*\}\}\s*$/', $v)) {
        $data[$k] = '';
    }
}

// Extract fields
$email       = $data['email'] ?? '';
$subject     = $data['subject'] ?? '';
$user_id     = (int)($data['user_id'] ?? 0);
$website_id  = (int)($data['website_id'] ?? 0);
$website_url =  $data['website_url'] ?? '';
$encharge_id = $data['person_id'] ?? '';
$flow_name   = $data['flow_name'] ?? '';

/* ==========================================================
   WHICH EMAIL THIS WAS

   email_type is a key set once per flow and never shown to a
   customer. The admin preview looks the template up by it.

   The subject cannot do that job: it is copy. On Wix the
   template is named "Weekly digest [only recurring email]"
   while the flow sends "Weekly digest", so matching on subject
   already failed there, and it would fail again the first time
   anyone reworded a subject line.

   Falling back to the subject keeps flows working that have not
   had the field added yet, lowercased and underscored so
   "First Report" and "first report" do not become two types.
========================================================== */
$email_type = trim((string)($data['email_type'] ?? ''));

if ($email_type === '' && $subject !== '') {
    $email_type = strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', $subject));
    $email_type = trim($email_type, '_');
}

/* ==========================================================
   THE DATA THE EMAIL USED

   Everything the flow posted that is not one of the fields
   above. Stored as JSON so a new field in Encharge needs no
   change here and no migration.

   Without this the log records that an email went out but not
   what it said, so the admin preview has nothing to fill the
   template with and every merge tag renders as its own literal
   text.

   Empty values are kept on purpose: a digest field that came
   through blank is information, and dropping it would make an
   empty section look like a missing one.
========================================================== */
$metaKeys = [
    'email', 'subject', 'email_type', 'user_id', 'website_id',
    'website_url', 'person_id', 'flow_name', 'instance_id', 'instanceid',
];

$payload = [];
foreach ($data as $k => $v) {
    if (!is_string($k) || in_array($k, $metaKeys, true)) {
        continue;
    }
    // Scalars only. A nested object would not survive the template anyway.
    $payload[$k] = is_scalar($v) || $v === null ? $v : json_encode($v);
}

$instanceId = trim((string)($data['instance_id'] ?? $data['instanceid'] ?? ''));

if ($instanceId !== '') {
    $payload['instance_id'] = $instanceId;
}

/* ==========================================================
   THE PAYLOAD IS BUILT LATER

   This endpoint stores what the flow sent and stops there.
   cron_payload_queue.php fills in the rest a few rows at a
   time.

   It is not done here because the weekly digest reaches around
   a thousand sites in one send window, and building each
   payload inside the request would spawn a thousand PHP
   processes on a box already running about a hundred cron jobs
   a minute. The queue does the same work at a pace that is
   chosen rather than whatever Encharge happens to do.

   Rows that already arrived complete are marked done, so a flow
   that does send every field needs no cron pass. The threshold
   is deliberately low: the smallest of these emails renders
   around a dozen fields, so anything under five is meta only.
========================================================== */
$payloadState = (count($payload) >= 5) ? 'yes' : 'no';

try {
    $stmt = $pdo->prepare("
        INSERT INTO encharge_email_logs
        (email, instance_id, subject, email_type, user_id, website_id, website_url,
         encharge_id, flow_name, payload, is_payload_generated, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $email,
        mb_substr($instanceId, 0, 191),
        $subject,
        mb_substr($email_type, 0, 64),
        $user_id,
        $website_id,
        $website_url,
        $encharge_id,
        $flow_name,
        $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        $payloadState,
    ]);
} catch (Throwable $e) {
    // Never fail webhook
    file_put_contents(
        __DIR__ . '/encharge_error_log.txt',
        date('Y-m-d H:i:s') . ' - ' . $e->getMessage() . "\n",
        FILE_APPEND
    );
}

http_response_code(200);
echo 'OK';
