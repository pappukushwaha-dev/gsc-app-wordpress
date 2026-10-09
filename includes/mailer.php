<?php
declare(strict_types=1);

/*
 Self-contained mailer for admin/staff notifications.

 SMTP: Postmark (smtp-broadcasts.postmarkapp.com:587 STARTTLS).
 The credentials below are the Postmark broadcast stream username/password
 supplied by the project owner. If they need to be rotated, change them
 here or move to .env and read via $_ENV.
*/

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!defined('MAILER_SMTP_HOST'))   define('MAILER_SMTP_HOST',   'smtp-broadcasts.postmarkapp.com');
if (!defined('MAILER_SMTP_PORT'))   define('MAILER_SMTP_PORT',   587);
if (!defined('MAILER_SMTP_USER'))   define('MAILER_SMTP_USER',   '5d6fd937-08c5-41e5-982c-84269ac2b273');
if (!defined('MAILER_SMTP_PASS'))   define('MAILER_SMTP_PASS',   '5d6fd937-08c5-41e5-982c-84269ac2b273');
if (!defined('MAILER_FROM_EMAIL'))  define('MAILER_FROM_EMAIL',  'support@makkpressapps.com');
if (!defined('MAILER_FROM_NAME'))   define('MAILER_FROM_NAME',   'Google Search Console by MP');
if (!defined('MAILER_SUPPORT_TO'))  define('MAILER_SUPPORT_TO',  'support@makkpressapps.com');

/**
 * Build a configured PHPMailer instance.
 * Throws on construction failure so callers can catch & log.
 */
function build_mailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = MAILER_SMTP_HOST;
    $mail->Port       = MAILER_SMTP_PORT;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAILER_SMTP_USER;
    $mail->Password   = MAILER_SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 15;

    $mail->setFrom(MAILER_FROM_EMAIL, MAILER_FROM_NAME);
    return $mail;
}

/**
 * Notify support of a brand-new ticket.
 *
 * Pulls store/customer info from WpSite using instance_id, formats an HTML
 * email and sends it to MAILER_SUPPORT_TO. The customer's email is set as
 * Reply-To so replying from the inbox goes straight to the customer.
 *
 * Returns true on send, false on any failure. Failures only log; never throw.
 */
function notify_new_ticket(PDO $pdo, int $ticketId): bool
{
    try {
        /* ---------- Ticket + first message ---------- */
        $tStmt = $pdo->prepare("
            SELECT id, instance_id, ticket_number, subject, status, priority, created_at
            FROM tickets
            WHERE id = :id
            LIMIT 1
        ");
        $tStmt->execute([':id' => $ticketId]);
        $ticket = $tStmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) {
            error_log("notify_new_ticket: ticket {$ticketId} not found");
            return false;
        }

        $mStmt = $pdo->prepare("
            SELECT message, created_at
            FROM ticket_messages
            WHERE ticket_id = :tid
            ORDER BY id ASC
            LIMIT 1
        ");
        $mStmt->execute([':tid' => $ticketId]);
        $firstMessage = $mStmt->fetch(PDO::FETCH_ASSOC);
        $firstMessageBody = $firstMessage['message'] ?? '';

        /* ---------- Store + customer ---------- */
        $sStmt = $pdo->prepare("
            SELECT shop_name, shop_domain, domain, email
            FROM WpSite
            WHERE instance_id = :i
            LIMIT 1
        ");
        $sStmt->execute([':i' => $ticket['instance_id']]);
        $site = $sStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $shopName    = $site['shop_name']   ?? 'Unknown store';
        $customerEmail = $site['email']     ?? '';
        $siteUrl     = $site['domain']      ?? $site['shop_domain'] ?? '';
        if ($siteUrl && !preg_match('#^https?://#i', $siteUrl)) {
            $siteUrl = 'https://' . $siteUrl;
        }

        /* ---------- Compose ---------- */
        $mail = build_mailer();
        $mail->addAddress(MAILER_SUPPORT_TO);
        if (filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($customerEmail, $shopName);
        }

        $mail->Subject = sprintf(
            '[New Ticket %s] %s',
            $ticket['ticket_number'],
            $ticket['subject']
        );

        $bodyText = $firstMessageBody !== ''
            ? nl2br(htmlspecialchars($firstMessageBody, ENT_QUOTES, 'UTF-8'))
            : '<em>No initial message provided.</em>';

        $rows = [
            ['Ticket #',    htmlspecialchars($ticket['ticket_number'], ENT_QUOTES, 'UTF-8')],
            ['Subject',     htmlspecialchars($ticket['subject'],       ENT_QUOTES, 'UTF-8')],
            ['Priority',    htmlspecialchars(ucfirst((string)$ticket['priority']), ENT_QUOTES, 'UTF-8')],
            ['Status',      htmlspecialchars(ucfirst((string)$ticket['status']),   ENT_QUOTES, 'UTF-8')],
            ['Shop name',   htmlspecialchars($shopName,                ENT_QUOTES, 'UTF-8')],
            ['Customer email', htmlspecialchars($customerEmail,        ENT_QUOTES, 'UTF-8')],
            ['Site URL',    $siteUrl !== ''
                ? '<a href="' . htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') . '">'
                  . htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8') . '</a>'
                : '-'],
            ['Instance ID', htmlspecialchars($ticket['instance_id'],   ENT_QUOTES, 'UTF-8')],
            ['Created at',  htmlspecialchars((string)$ticket['created_at'], ENT_QUOTES, 'UTF-8')],
        ];

        $rowsHtml = '';
        foreach ($rows as [$k, $v]) {
            $rowsHtml .= '<tr>'
                . '<td style="padding:6px 12px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:600;white-space:nowrap;">' . $k . '</td>'
                . '<td style="padding:6px 12px;border:1px solid #e5e7eb;">' . $v . '</td>'
                . '</tr>';
        }

        $html = '<div style="font-family:Inter,Arial,sans-serif;color:#111;max-width:640px;">'
            . '<h2 style="margin:0 0 16px;color:#1f2937;">New support ticket</h2>'
            . '<p style="margin:0 0 16px;color:#374151;">A merchant has just submitted a new ticket through the Google Search Console app.</p>'
            . '<table style="border-collapse:collapse;width:100%;font-size:14px;margin-bottom:24px;">'
            . $rowsHtml
            . '</table>'
            . '<h3 style="margin:0 0 8px;color:#1f2937;">Message</h3>'
            . '<div style="padding:12px 16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:14px;line-height:1.6;">'
            . $bodyText
            . '</div>'
            . '<p style="margin:24px 0 0;color:#6b7280;font-size:12px;">Reply directly to this email to respond to the customer.</p>'
            . '</div>';

        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = strip_tags(str_replace('<br>', "\n", $bodyText));

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('notify_new_ticket PHPMailer error: ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        error_log('notify_new_ticket error: ' . $e->getMessage());
        return false;
    }
}
