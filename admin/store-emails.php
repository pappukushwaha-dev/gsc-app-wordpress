<?php
// File: /admin/store-emails.php
declare(strict_types=1);

$title    = 'Store Emails';
$subTitle = 'Store Emails';

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';
require_once __DIR__ . '/../includes/liquid_lite.php';

SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * How many emails the list shows for one store.
 *
 * A store that has been sending for a year has a few hundred rows. This page
 * is for reading and checking, not for export, and the newest are the ones
 * that matter.
 */
const SE_LIMIT = 100;

/* ==========================================================
   WHICH STORE

   The instance id arrives in the URL rather than being taken
   from the session, so one admin can look at any store.
========================================================== */
$instanceId = trim((string)($_GET['instance_id'] ?? ''));

/* ==========================================================
   RAW EMAIL, FOR THE PREVIEW FRAME

   Emits the email and nothing else. This is what the frame on
   the email page loads.

   It is a separate request for two reasons. The email templates
   carry their own full-page CSS, including resets and table
   rules, so dropped straight into the admin page they restyle
   the sidebar and topbar around them. And a frame's own
   document can be sandboxed, which keeps any script in a
   template from running against the admin session.
========================================================== */
$rawId = isset($_GET['raw']) ? (int)$_GET['raw'] : 0;

if ($rawId > 0) {
    header('Content-Type: text/html; charset=utf-8');

    $html = '';
    $note = 'Email not found.';

    try {
        $q = $pdo->prepare("SELECT * FROM encharge_email_logs WHERE id = ? AND instance_id = ? LIMIT 1");
        $q->execute([$rawId, $instanceId]);
        $email = $q->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($email) {
            $payload = [];
            if (!empty($email['payload'])) {
                $decoded = json_decode((string)$email['payload'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            if (!$payload) {
                $note = se_state_note($email);
            } else {
                $template = se_find_template($pdo, $email);

                if (!$template) {
                    $note = 'No template found for type "'
                          . (string)($email['email_type'] ?: 'not set') . '".';
                } else {
                    $payload += [
                        'email'           => (string)($email['email'] ?? ''),
                        'unsubscribeLink' => '#',
                    ];
                    $html = liquid_render((string)$template['body'], $payload);
                }
            }
        }
    } catch (Throwable $e) {
        $note = 'Could not build this email.';
    }

    /* A template body is usually a complete HTML document of its own. That is
       emitted as it is rather than wrapped: a second html/head/body around it
       is nested markup that browsers have to guess at. A doctype is added when
       the template has none, because without it the frame renders in quirks
       mode and the preview would not match the email that was sent. */
    $isFullDocument = (bool)preg_match('#<\s*(!doctype|html)\b#i', $html);

    if ($html !== '' && $isFullDocument) {
        if (!preg_match('#^\s*<!doctype#i', $html)) {
            echo '<!DOCTYPE html>';
        }
        echo $html;
        exit;
    }

    echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<style>body{margin:0;padding:0;background:#fff}'
       . '.se-note{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;'
       . 'font-size:14px;line-height:1.6;color:#4B5563;padding:28px}</style>'
       . '</head><body>';

    echo $html !== '' ? $html : '<div class="se-note">' . h($note) . '</div>';

    echo '</body></html>';
    exit;
}

/* ==========================================================
   WHICH EMAIL IS OPEN

   Absent means the list. Present means one email, on its own
   page inside the admin layout.
========================================================== */
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;

/* ==========================================================
   THE STORE
========================================================== */
$store = null;

if ($instanceId !== '') {
    try {
        $q = $pdo->prepare("
            SELECT instance_id, domain, shop_domain, shop_name, email
            FROM WpSite
            WHERE instance_id = ?
            LIMIT 1
        ");
        $q->execute([$instanceId]);
        $store = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $store = null;
    }
}

$storeName   = (string)($store['shop_name'] ?? '');
$storeDomain = (string)($store['domain'] ?? '');
if ($storeDomain === '') {
    $storeDomain = (string)($store['shop_domain'] ?? '');
}
$storeEmail  = (string)($store['email'] ?? '');

$stateLabels = [
    'yes'     => ['Built',   '#DCFCE7', '#15803D'],
    'no'      => ['Pending', '#FEF3C7', '#92400E'],
    'skipped' => ['Skipped', '#F3F4F6', '#6B7280'],
    'failed'  => ['Failed',  '#FEE2E2', '#B91C1C'],
];

/* ==========================================================
   ONE EMAIL, ON ITS OWN PAGE
========================================================== */
if ($store && $viewId > 0) {

    $email = null;

    try {
        $q = $pdo->prepare("SELECT * FROM encharge_email_logs WHERE id = ? AND instance_id = ? LIMIT 1");
        $q->execute([$viewId, $instanceId]);
        $email = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $email = null;
    }

    $emailSubject = '';
    $emailSnippet = '';
    $hasMail      = false;
    $note         = '';

    if ($email) {
        $payload = [];
        if (!empty($email['payload'])) {
            $decoded = json_decode((string)$email['payload'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if (!$payload) {
            $note = se_state_note($email);
        } else {
            $template = se_find_template($pdo, $email);

            if (!$template) {
                $note = 'No template found for type "'
                      . (string)($email['email_type'] ?: 'not set')
                      . '". Give the template an email_type, or copy it from another app.';
            } else {
                $payload += [
                    'email'           => (string)($email['email'] ?? ''),
                    'unsubscribeLink' => '#',
                ];
                $emailSubject = trim(liquid_render((string)$template['subject'], $payload));
                $emailSnippet = se_snippet(liquid_render((string)$template['body'], $payload));
                $hasMail      = true;
            }
        }
    }

    $backUrl = 'store-emails.php?instance_id=' . urlencode($instanceId);

    $title    = 'Email Preview';
    $subTitle = 'Email Preview';
    ?>
    <?php include './partials/layouts/layoutTop.php'; ?>

    <div class="grid grid-cols-12 gap-4">
        <div class="col-span-12">

            <div class="card h-full p-0 rounded-xl border-0 overflow-hidden">

                <div class="card-header border-b border-neutral-200 dark:border-neutral-600 bg-white dark:bg-neutral-700 py-4 px-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">

                        <div class="flex items-center gap-3 overflow-hidden">

                            <a href="<?= h($backUrl) ?>"
                               class="bg-neutral-100 dark:bg-neutral-600 hover:bg-cstm-primary hover:text-white text-secondary-light w-10 h-10 flex justify-center items-center rounded-full transition duration-300"
                               title="Back to the email list">
                                <iconify-icon icon="solar:arrow-left-linear" class="text-xl"></iconify-icon>
                            </a>

                            <div class="overflow-hidden">
                                <span class="block text-base font-semibold truncate">
                                    <?= h($emailSubject !== '' ? $emailSubject : '(no subject)') ?>
                                </span>
                                <span class="block text-xs text-secondary-light truncate">
                                    <?= h($storeName !== '' ? $storeName : $storeDomain) ?>
                                    &middot;
                                    <?= h((string)($email['email'] ?? '')) ?>
                                </span>
                            </div>

                        </div>

                        <div class="flex items-center gap-3 shrink-0">

                            <?php if ($email): ?>
                                <span class="text-xs text-secondary-light whitespace-nowrap">
                                    <?= h(date('j M Y, H:i', strtotime((string)$email['created_at']))) ?>
                                </span>

                                <?php
                                $state = (string)($email['is_payload_generated'] ?? 'no');
                                [$stateLabel, $stateBg, $stateFg] = $stateLabels[$state] ?? ['?', '#F3F4F6', '#6B7280'];
                                ?>
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-bold"
                                      style="background:<?= $stateBg ?>;color:<?= $stateFg ?>;">
                                    <?= h($stateLabel) ?>
                                </span>
                            <?php endif; ?>

                            <a href="<?= h($backUrl) ?>"
                               class="inline-flex items-center gap-2 bg-cstm-primary text-white rounded-lg px-5 py-2 font-semibold hover:bg-cstm-primary-30 transition duration-300">
                                <iconify-icon icon="solar:arrow-left-linear" class="text-lg"></iconify-icon>
                                Back
                            </a>

                        </div>

                    </div>
                </div>

                <div class="card-body p-6">

                    <?php if (!$email): ?>

                        <div class="flex flex-col items-center justify-center py-8 text-center">
                            <h3 class="text-lg font-bold text-danger-600 mb-2">Email Not Found</h3>
                            <p class="text-neutral-500 dark:text-neutral-400 mb-6">
                                This email is not in the log for this store.
                            </p>
                            <a href="<?= h($backUrl) ?>"
                               class="inline-flex items-center gap-2 bg-cstm-primary text-white rounded-lg px-6 py-3 font-semibold hover:bg-cstm-primary-30 transition duration-300">
                                <iconify-icon icon="solar:arrow-left-linear" class="text-lg"></iconify-icon>
                                Back to the email list
                            </a>
                        </div>

                    <?php elseif (!$hasMail): ?>

                        <div class="text-center py-8">
                            <p class="text-neutral-500 dark:text-neutral-400 mb-4"><?= h($note) ?></p>
                            <a href="<?= h($backUrl) ?>"
                               class="inline-flex items-center gap-2 bg-cstm-primary text-white rounded-lg px-6 py-3 font-semibold hover:bg-cstm-primary-30 transition duration-300">
                                <iconify-icon icon="solar:arrow-left-linear" class="text-lg"></iconify-icon>
                                Back to the email list
                            </a>
                        </div>

                    <?php else: ?>

                        <div class="grid grid-cols-12 gap-4 mb-4">
                            <div class="col-span-12">
                                <div class="text-xs text-secondary-light">Subject</div>
                                <div class="text-sm font-medium"><?= h($emailSubject) ?></div>
                            </div>
                        </div>

                        <?php if ($emailSnippet !== ''): ?>
                            <div class="text-xs text-secondary-light mb-4"><?= h($emailSnippet) ?></div>
                        <?php endif; ?>

                        <div class="border border-neutral-200 dark:border-neutral-600 rounded-lg overflow-hidden">
                            <?php
                            /* In a frame, not inline.

                               The email carries its own full-page CSS, so
                               dropped straight in it would restyle the admin
                               around it. srcdoc is not used here because the
                               rendered body can run to tens of kilobytes and
                               would have to be escaped into the attribute;
                               the frame loads its own request instead. The
                               sandbox keeps any script in a template from
                               running against the admin session. */
                            ?>
                            <iframe
                                src="store-emails.php?instance_id=<?= urlencode($instanceId) ?>&amp;raw=<?= (int)$email['id'] ?>"
                                sandbox=""
                                title="Email preview"
                                style="width:100%;height:75vh;border:0;display:block;background:#fff;"></iframe>
                        </div>

                    <?php endif; ?>

                </div>

            </div>
        </div>
    </div>

    <?php include './partials/layouts/layoutBottom.php'; ?>
    <?php
    exit;
}

/* ==========================================================
   THE EMAILS

   Selected by instance_id, which is what the webhook writes
   from the flow. It used to be the owner address on the older
   viewers, and that is wrong in a way that is hard to notice:
   one person can own two stores with the same address, and
   every email sent about either then appeared under both.

   The address match is kept only for rows written before
   instance_id existed. Those cannot be attributed any other
   way, and they are shown only when this address belongs to
   exactly one store. Where two stores share an address the row
   is ambiguous, and showing it under both is precisely the
   defect this leaves out - so it is shown under neither.

   No COLLATE is needed anywhere in this file: the log table and
   WpSite are never joined, and every comparison against the
   other table's value goes through a bound parameter, which
   takes the column's collation. That matters because the two
   tables do not agree - WpSite is utf8mb4_unicode_ci while
   encharge_email_logs is utf8mb4_0900_ai_ci, and a direct
   comparison or join between them fails with:

       General error: 1267 Illegal mix of collations

   If a join between the two is ever added here, both sides of
   it need an explicit COLLATE utf8mb4_unicode_ci.
========================================================== */
$ownerEmail       = '';
$emailIsAmbiguous = true;

try {
    if ($storeEmail !== '') {
        $q = $pdo->prepare("SELECT COUNT(*) FROM WpSite WHERE email = ?");
        $q->execute([$storeEmail]);
        /* One store on this address means a legacy row belongs to it. More
           than one means it cannot be attributed, so it is left out. */
        $emailIsAmbiguous = ((int)$q->fetchColumn() > 1);
    }
} catch (Throwable $e) {
    $ownerEmail = '';
}

$emails  = [];
$loadErr = null;

if ($store) {
    try {
        $q = $pdo->prepare("
            SELECT id, email, subject, email_type, flow_name, website_url,
                   payload, is_payload_generated, payload_attempts, payload_error,
                   created_at
            FROM encharge_email_logs
            WHERE (
                instance_id = :instance_id
                OR (
                    :legacy_ok = '1'
                    AND (instance_id IS NULL OR instance_id = '')
                    AND email = :owner_email
                )
            )
            ORDER BY id DESC
            LIMIT " . SE_LIMIT
        );
        $q->bindValue(':instance_id', $instanceId, PDO::PARAM_STR);
        $q->bindValue(':legacy_ok', ($storeEmail !== '' && !$emailIsAmbiguous) ? '1' : '0', PDO::PARAM_STR);
        $q->bindValue(':owner_email', $storeEmail, PDO::PARAM_STR);
        $q->execute();
        $emails = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $loadErr = $e->getMessage();
    }
}

/**
 * Templates are matched by email_type first.
 *
 * Matching on the subject is what the older page did, and it already failed:
 * the template is named "Weekly digest [only recurring email]" while the flow
 * sends "Weekly digest". The subject is copy and gets edited again, so the
 * type is the thing to match on. The name and prefix fallbacks are kept for
 * rows whose flow has not been given an email_type yet.
 */
function se_find_template(PDO $pdo, array $row): ?array
{
    $type = trim((string)($row['email_type'] ?? ''));

    if ($type !== '') {
        $q = $pdo->prepare("SELECT * FROM email_templates WHERE email_type = ? LIMIT 1");
        $q->execute([$type]);
        $t = $q->fetch(PDO::FETCH_ASSOC);
        if ($t) {
            return $t;
        }
    }

    $name = trim(rtrim(trim((string)($row['subject'] ?? '')), ','));

    if ($name === '') {
        return null;
    }

    $q = $pdo->prepare("SELECT * FROM email_templates WHERE TRIM(name) = ? LIMIT 1");
    $q->execute([$name]);
    $t = $q->fetch(PDO::FETCH_ASSOC);
    if ($t) {
        return $t;
    }

    /* The subject is often a prefix of the template name. Only used when
       nothing matched exactly, and only when it matches one row, so it cannot
       quietly pick the wrong template. */
    $q = $pdo->prepare("SELECT * FROM email_templates WHERE TRIM(name) LIKE CONCAT(?, '%') LIMIT 2");
    $q->execute([$name]);
    $hits = $q->fetchAll(PDO::FETCH_ASSOC);

    return count($hits) === 1 ? $hits[0] : null;
}

/**
 * The plain-text start of an email, for the list.
 *
 * The body is HTML with inline styles and table layout, so tags come off and
 * the whitespace is collapsed before it is cut. A raw substr would put markup
 * in the list.
 */
function se_snippet(string $html, int $chars = 140): string
{
    $text = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html) ?? '';
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    $text = trim($text);

    if (mb_strlen($text) <= $chars) {
        return $text;
    }

    return mb_substr($text, 0, $chars) . '...';
}

/**
 * Why an email has no content, said plainly.
 *
 * A row with no payload renders as an empty template, which reads like a
 * broken template rather than a row the queue has not reached. Those lead to
 * different places, so they are not allowed to look the same.
 */
function se_state_note(array $row): string
{
    $state = (string)($row['is_payload_generated'] ?? 'no');

    if ($state === 'no') {
        return 'The data for this email has not been built yet. The queue runs every 30 minutes, so its figures will appear here shortly.';
    }

    if ($state === 'skipped') {
        return 'This email was logged before its data was captured, so there is nothing to fill the template with. Emails sent from now on will show their real figures.';
    }

    if ($state === 'failed') {
        $err = trim((string)($row['payload_error'] ?? ''));
        return 'Building the data for this email failed after ' . (int)($row['payload_attempts'] ?? 0)
             . ' attempts.' . ($err !== '' ? ' ' . $err : '');
    }

    return 'There is nothing to show for this email.';
}

/* ==========================================================
   RENDER THE LIST

   Subject and snippet are rendered here, once, so the list shows
   what the customer read rather than the template tags. A row
   without a payload shows the reason instead of an empty
   subject.
========================================================== */
$list = [];

foreach ($emails as $row) {
    $payload = [];
    if (!empty($row['payload'])) {
        $decoded = json_decode((string)$row['payload'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $subject = '';
    $snippet = '';
    $hasMail = false;

    if ($payload) {
        $template = se_find_template($pdo, $row);

        if ($template) {
            $payload += [
                'email'           => (string)($row['email'] ?? ''),
                'unsubscribeLink' => '#',
            ];

            $subject = trim(liquid_render((string)$template['subject'], $payload));
            $snippet = se_snippet(liquid_render((string)$template['body'], $payload));
            $hasMail = true;
        }
    }

    $fallback = trim((string)($row['subject'] ?? ''));

    if ($subject === '') {
        $subject = $fallback !== '' ? $fallback : '(no subject)';
    }

    $list[] = $row + [
        'se_subject' => $subject,
        'se_snippet' => $hasMail ? $snippet : se_state_note($row),
        'se_hasmail' => $hasMail,
    ];
}
?>
<?php include './partials/layouts/layoutTop.php'; ?>

<?php if (!$store): ?>

    <div class="flex flex-col items-center justify-center py-8 text-center">
        <h3 class="text-2xl font-bold text-danger-600 mb-2">Store Not Found</h3>
        <p class="text-neutral-500 dark:text-neutral-400 mb-6">
            The requested store could not be found. Please check the link and try again.
        </p>
        <a href="stores.php"
           class="inline-flex items-center gap-2 bg-cstm-primary text-white rounded-lg px-6 py-3 font-semibold hover:bg-cstm-primary-30 transition duration-300">
            <iconify-icon icon="solar:arrow-left-linear" class="icon text-lg"></iconify-icon>
            Back to Stores
        </a>
    </div>

<?php else: ?>

    <div class="grid grid-cols-12 gap-4">

        <div class="col-span-12">
            <div class="card h-full p-0 rounded-xl border-0 overflow-hidden">

                <div class="card-header border-b border-neutral-200 dark:border-neutral-600 bg-white dark:bg-neutral-700 py-4 px-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">

                        <div class="flex items-center gap-3 overflow-hidden">
                            <a href="stores.php"
                               class="bg-neutral-100 dark:bg-neutral-600 hover:bg-cstm-primary hover:text-white text-secondary-light w-10 h-10 flex justify-center items-center rounded-full transition duration-300"
                               title="Back to Stores">
                                <iconify-icon icon="solar:arrow-left-linear" class="text-xl"></iconify-icon>
                            </a>

                            <div class="overflow-hidden">
                                <span class="block text-base font-semibold truncate">
                                    <?= h($storeName !== '' ? $storeName : $storeDomain) ?>
                                </span>
                                <span class="block text-xs text-secondary-light truncate">
                                    <?= h($storeDomain !== '' ? $storeDomain : $storeEmail) ?>
                                </span>
                            </div>
                        </div>

                        <span class="inline-block px-3 py-1 rounded-full text-xs font-medium bg-cstm-primary-10 text-cstm-primary">
                            <?= count($list) ?> <?= count($list) === 1 ? 'email' : 'emails' ?>
                        </span>

                    </div>
                </div>

                <div class="card-body p-6">

                    <?php if ($loadErr): ?>
                        <div class="text-center py-8 text-danger-600">
                            Could not read the email log table.
                        </div>
                    <?php elseif (!$list): ?>
                        <div class="text-center py-8 text-neutral-500 dark:text-neutral-400">
                            No emails have been sent to this store yet.
                        </div>
                    <?php else: ?>

                        <div class="cstm-scroll-sm overflow-x-auto">
                            <table class="table text-sm">
                                <thead>
                                <tr>
                                    <th>Website</th>
                                    <th>Subject</th>
                                    <th>Email</th>
                                    <th>Preview</th>
                                    <th>Sent</th>
                                    <th>Action</th>
                                </tr>
                                </thead>
                                <tbody>

                                <?php foreach ($list as $r):
                                    $state = (string)($r['is_payload_generated'] ?? 'no');
                                    [$stateLabel, $stateBg, $stateFg] = $stateLabels[$state] ?? ['?', '#F3F4F6', '#6B7280'];

                                    $site = (string)($r['website_url'] ?? '');
                                    if ($site === '') {
                                        $site = $storeDomain;
                                    }
                                    ?>

                                    <tr>
                                        <td>
                                            <span class="block font-medium"><?= h($site) ?></span>
                                            <?php if (!empty($r['email_type'])): ?>
                                                <span class="block text-xs text-secondary-light">
                                                    <?= h((string)$r['email_type']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <span class="block font-medium"><?= h($r['se_subject']) ?></span>
                                        </td>

                                        <td>
                                            <span class="block text-xs"><?= h((string)$r['email']) ?></span>
                                        </td>

                                        <td style="max-width:380px;">
                                            <span class="block text-xs text-secondary-light"><?= h($r['se_snippet']) ?></span>
                                        </td>

                                        <td>
                                            <span class="block text-xs whitespace-nowrap">
                                                <?= h(date('j M Y, H:i', strtotime((string)$r['created_at']))) ?>
                                            </span>
                                            <span class="inline-block mt-2 px-2 py-0.5 rounded-full text-xs font-bold"
                                                  style="background:<?= $stateBg ?>;color:<?= $stateFg ?>;">
                                                <?= h($stateLabel) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php if ($r['se_hasmail']): ?>
                                                <a href="store-emails.php?instance_id=<?= urlencode($instanceId) ?>&amp;view=<?= (int)$r['id'] ?>"
                                                   class="inline-flex items-center gap-1 bg-cstm-primary-10 hover:bg-cstm-primary text-cstm-primary hover:text-white px-4 py-2 rounded-lg text-xs font-semibold transition duration-300"
                                                   title="Open this email">
                                                    <iconify-icon icon="solar:eye-bold" class="text-lg"></iconify-icon>
                                                    View details
                                                </a>
                                            <?php else: ?>
                                                <span class="text-xs text-neutral-400">No data</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>

                                </tbody>
                            </table>
                        </div>

                        <?php if (count($list) >= SE_LIMIT): ?>
                            <p class="text-xs text-secondary-light mt-4">
                                Showing the newest <?= SE_LIMIT ?> emails for this store.
                            </p>
                        <?php endif; ?>

                    <?php endif; ?>

                </div>

            </div>
        </div>

    </div>

<?php endif; ?>

<?php include './partials/layouts/layoutBottom.php'; ?>
