<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * EmailService.php
 * Thin PHPMailer wrapper for outbound notification emails. Configured via
 * SMTP_HOST/PORT/USERNAME/PASSWORD/MAIL_FROM in .env (see helpers.php) —
 * that's the account that SENDS mail, not any recipient's address, which
 * always comes from the caller (NotificationService), never from here.
 *
 * Never throws: send() always returns a result array so a mail outage can
 * never take down the request that triggered it.
 */
class EmailService
{
    public static function isConfigured(): bool
    {
        return SMTP_HOST !== '' && SMTP_USERNAME !== '' && SMTP_PASSWORD !== '' && MAIL_FROM !== '';
    }

    /**
     * @return array{success:bool, error:?string}
     */
    public static function send(string $to, string $subject, string $html): array
    {
        if (!self::isConfigured()) {
            return ['success' => false, 'error' => 'SMTP not configured (SMTP_* / MAIL_FROM empty in .env)'];
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid recipient address'];
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 10;

            $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html)));

            $mail->send();
            return ['success' => true, 'error' => null];
        } catch (PHPMailerException $e) {
            return ['success' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Shared HTML layout for every notification email so the 11 existing
     * trigger sites don't each need their own markup.
     */
    public static function notificationTemplate(string $title, string $message, string $actionUrl = '', string $actionLabel = ''): string
    {
        $safeTitle   = htmlspecialchars($title, ENT_QUOTES);
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES));

        $button = '';
        if ($actionUrl !== '' && $actionLabel !== '') {
            $safeUrl   = htmlspecialchars($actionUrl, ENT_QUOTES);
            $safeLabel = htmlspecialchars($actionLabel, ENT_QUOTES);
            $button = '<tr><td style="padding:10px 0 0;">'
                . '<a href="' . $safeUrl . '" style="display:inline-block;background:#071B4D;color:#ffffff;'
                . 'text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px;">'
                . $safeLabel . '</a></td></tr>';
        }

        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 16px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;max-width:480px;width:100%;">'
            . '<tr><td style="background:#071B4D;padding:20px 28px;">'
            . '<span style="color:#ffffff;font-size:18px;font-weight:700;">PeerConnect</span>'
            . '</td></tr>'
            . '<tr><td style="padding:28px;">'
            . '<p style="margin:0 0 4px;color:#8a8f98;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">You have a new notification</p>'
            . '<h1 style="margin:0 0 14px;color:#071B4D;font-size:20px;">' . $safeTitle . '</h1>'
            . '<p style="margin:0 0 18px;color:#3f3f46;font-size:14px;line-height:1.6;">' . $safeMessage . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0">' . $button . '</table>'
            . '</td></tr>'
            . '<tr><td style="padding:18px 28px;background:#f9fafb;border-top:1px solid #eceef1;">'
            . '<p style="margin:0;color:#9aa0a6;font-size:11.5px;">This is an automated notification from PeerConnect. Please do not reply to this email.</p>'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
