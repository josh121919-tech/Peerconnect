<?php

/**
 * AdminAlertService — emails the people who run the site.
 *
 * Two things happen here that nobody should have to remember at each call
 * site.
 *
 * It writes to one address. Not every administrator — an administrator is
 * exactly what these alerts are about, and telling a room full of them that
 * one of them is awaiting approval is both noise and, for the one awaiting
 * approval, an invitation. The address is the owner_email setting, so it can
 * be changed or handed on without touching this file.
 *
 * And it honours email_enable. EmailService::send() deliberately does not —
 * it is the transport, and the test-email button on the Settings page has to
 * work even when dispatch is switched off. Everything that sends unprompted
 * has to check the setting itself; NotificationService does, and so does
 * this. Without it, turning email off in Settings would silently fail to turn
 * these off.
 *
 * Failure is never fatal. An alert that cannot be sent must not take down the
 * thing it was reporting on: an application still submits and a setting still
 * saves when the mail server is unreachable.
 */
class AdminAlertService
{
    /**
     * The one address these go to, or '' when it is unset or unusable.
     *
     * $exceptUserId leaves the owner out of an alert about something they did
     * themselves: they were there, and a message telling them what they just
     * did trains them to ignore the ones that matter.
     */
    public static function recipient(mysqli $con, ?int $exceptUserId = null): string
    {
        $to = trim((string)pc_setting($con, 'owner_email'));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        if ($exceptUserId !== null) {
            $actor = UserRepository::emailOf($con, $exceptUserId);
            if ($actor !== null && strcasecmp(trim($actor), $to) === 0) {
                return '';
            }
        }
        return $to;
    }

    /**
     * Emails the owner. Returns 1 when a message went out, 0 when email is
     * switched off, SMTP is not configured, there is no usable owner address,
     * or the only person to tell is the one who caused it.
     */
    public static function send(
        mysqli $con,
        string $subject,
        string $title,
        string $message,
        string $actionUrl = '',
        string $actionLabel = '',
        ?int $exceptUserId = null,
        string $bodyHtml = ''
    ): int {
        if (!pc_setting_bool($con, 'email_enable') || !EmailService::isConfigured()) {
            return 0;
        }

        $to = self::recipient($con, $exceptUserId);
        if ($to === '') {
            return 0;
        }

        try {
            $html = $bodyHtml !== '' ? $bodyHtml
                : EmailService::notificationTemplate($title, $message, $actionUrl, $actionLabel);
            $r = EmailService::send($to, $subject, $html);
            if (!empty($r['success'])) {
                return 1;
            }
            error_log('AdminAlertService: ' . (string)($r['error'] ?? 'send failed'));
        } catch (Throwable $e) {
            error_log('AdminAlertService: ' . $e->getMessage());
        }
        return 0;
    }
}
