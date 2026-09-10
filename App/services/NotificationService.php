<?php
require_once __DIR__ . '/EmailService.php';

/**
 * NotificationService.php
 * Centralized service for creating in-app notifications.
 * All action files (verify, approve, reject, block, etc.) call this.
 *
 * Usage:
 *   NotificationService::send($con, $user_id, 'session_approved', 'Session Approved', 'Your session has been approved.', '/link');
 *
 * Every call also attempts an email to the recipient's registered address
 * (see resolveEmail()) via EmailService — one integration point covers every
 * existing notification type without touching any of their call sites.
 */
class NotificationService
{
    // Maps a notification type to the Settings → Notifications toggle that
    // gates it. Types not listed here (verification, badges, certificates,
    // account actions) are administrative/important and always send.
    private const PREFERENCE_MAP = [
        'session_requested' => 'session_requests',
        'session_cancelled' => 'session_requests',
        'session_approved'  => 'session_requests',
        'session_rejected'  => 'session_requests',
        'missed_session'    => 'session_requests',
        'session_reminder'  => 'session_reminders',
        'feedback_received' => 'feedback_received',
        'new_message'       => 'messages',
    ];

    /**
     * The platform-wide email switches, from System Settings.
     *
     * PREFERENCE_MAP above is the member's own choice; this is the admin's.
     * Both have to allow a mail before it is sent, and the admin's is checked
     * second because it is the one that can silence a whole category.
     */
    private const EMAIL_SETTING_MAP = [
        'welcome'               => 'email_on_registration',
        'session_requested'     => 'email_on_booking',
        'session_approved'      => 'email_on_booking',
        'session_rejected'      => 'email_on_booking',
        'session_cancelled'     => 'email_on_booking',
        'session_reminder'      => 'email_on_reminder',
        'new_message'           => 'email_on_message',
        'assessment_submitted'  => 'email_on_assessment',
        'feedback_received'     => 'email_on_assessment',
        'announcement'          => 'email_on_announcement',
    ];

    private static function emailAllowed(mysqli $con, int $notification_id): bool
    {
        if (!function_exists('pc_setting_bool')) return true;
        if (!pc_setting_bool($con, 'email_enable')) return false;

        $st = $con->prepare("SELECT type FROM notifications WHERE notification_id = ?");
        if (!$st) return true;
        $st->bind_param('i', $notification_id);
        $st->execute();
        $type = $st->get_result()->fetch_assoc()['type'] ?? '';
        $st->close();

        $key = self::EMAIL_SETTING_MAP[$type] ?? null;
        // Anything not listed is administrative (verification, account
        // actions) and always sends, the same rule PREFERENCE_MAP uses.
        return $key === null ? true : pc_setting_bool($con, $key);
    }

    /**
     * True unless the user has an explicit, saved "off" preference for the
     * Settings toggle this notification type maps to. No row / unmapped type
     * = always allowed (matches the toggles' own defaults).
     */
    private static function isAllowed(mysqli $con, int $user_id, string $type): bool
    {
        $column = self::PREFERENCE_MAP[$type] ?? null;
        if ($column === null) {
            return true;
        }
        $stmt = $con->prepare("SELECT `$column` FROM notification_preferences WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row === null || (int)$row[$column] === 1;
    }

    /**
     * Insert a notification for one user, unless they've turned this
     * category off in Settings.
     */
    public static function send(
        mysqli $con,
        int    $user_id,
        string $type,
        string $title,
        string $message,
        string $link = ''
    ): bool {
        if (!self::isAllowed($con, $user_id, $type)) {
            return false;
        }

        $stmt = $con->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->bind_param("issss", $user_id, $type, $title, $message, $link);
        $ok = $stmt->execute();
        $notification_id = $stmt->insert_id;
        $stmt->close();

        if ($ok) {
            self::dispatchEmail($con, $notification_id, $user_id, $title, $message, $link);
        }

        return $ok;
    }

    /**
     * Looks up the recipient's registered email and, if SMTP is configured,
     * sends the same notification there. Never throws — a mail outage only
     * ever shows up as an 'email_status' of 'failed' on the notification
     * row, the in-app notification above is already committed regardless.
     */
    private static function dispatchEmail(
        mysqli $con,
        int    $notification_id,
        int    $user_id,
        string $title,
        string $message,
        string $link
    ): void {
        $status = 'skipped';
        $error  = null;

        /*
         * System Settings → Email can switch email off entirely, or per
         * category. The in-app notification is already committed either way —
         * turning email off must not stop people being told inside the app.
         */
        if (!self::emailAllowed($con, $notification_id)) {
            $con->query("UPDATE notifications SET email_status = 'skipped' WHERE notification_id = " . (int)$notification_id);
            return;
        }

        if (EmailService::isConfigured()) {
            $email = self::resolveEmail($con, $user_id);
            if ($email === null) {
                $status = 'failed';
                $error  = 'No registered email on file for this user';
            } else {
                $actionUrl = self::absoluteUrl($link);
                $html = EmailService::notificationTemplate(
                    $title,
                    $message,
                    $actionUrl,
                    $actionUrl !== '' ? 'View in PeerConnect' : ''
                );
                $result = EmailService::send($email, $title, $html);
                $status = $result['success'] ? 'sent' : 'failed';
                $error  = $result['error'];
                if (!$result['success']) {
                    error_log("[NotificationService] Email send failed for user_id={$user_id}, notification_id={$notification_id}: {$result['error']}");
                }
            }
        }

        $upd = $con->prepare("UPDATE notifications SET email_status = ?, email_error = ? WHERE notification_id = ?");
        $upd->bind_param("ssi", $status, $error, $notification_id);
        $upd->execute();
        $upd->close();
    }

    /**
     * users.email is canonical for regular signups, but Google-OAuth
     * signups never populate it (google-login.php only writes to `emails`)
     * — same gap the login query in home.view.php already works around.
     * Mirrors that exact fallback rather than inventing a new one.
     */
    private static function resolveEmail(mysqli $con, int $user_id): ?string
    {
        $stmt = $con->prepare("
            SELECT email FROM users WHERE user_id = ? AND email IS NOT NULL AND email != ''
            UNION
            SELECT email FROM emails WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $user_id, $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['email'] ?? null;
    }

    /** Notification links are stored as absolute paths (see helpers.php's
     *  url()), which don't work as email links without a scheme + host. */
    private static function absoluteUrl(string $link): string
    {
        if ($link === '') {
            return '';
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . $link;
    }

    /**
     * Send a notification to multiple users at once.
     */
    public static function sendBulk(
        mysqli $con,
        array  $user_ids,
        string $type,
        string $title,
        string $message,
        string $link = ''
    ): void {
        foreach ($user_ids as $uid) {
            self::send($con, (int)$uid, $type, $title, $message, $link);
        }
    }

    // ── Named helpers for each notification type ──────────────────────────

    public static function sessionApproved(mysqli $con, int $mentee_id, string $mentor_name, string $link = ''): void
    {
        self::send($con, $mentee_id, 'session_approved',
            'Session Approved ✅',
            "Your session with {$mentor_name} has been approved.",
            $link
        );
    }

    public static function sessionRejected(mysqli $con, int $mentee_id, string $mentor_name, string $link = ''): void
    {
        self::send($con, $mentee_id, 'session_rejected',
            'Session Not Available',
            "Your session request with {$mentor_name} was declined.",
            $link
        );
    }

    public static function sessionReminder(mysqli $con, int $user_id, string $other_name, string $date, string $link = ''): void
    {
        self::send($con, $user_id, 'session_reminder',
            'Session Reminder ⏰',
            "Your session with {$other_name} is scheduled for {$date}.",
            $link
        );
    }

    public static function feedbackReceived(mysqli $con, int $mentor_id, string $mentee_name, string $link = ''): void
    {
        self::send($con, $mentor_id, 'feedback_received',
            'New Feedback Received ⭐',
            "{$mentee_name} left you a review.",
            $link
        );
    }

    public static function newMessage(mysqli $con, int $recipient_id, string $sender_name, string $link = ''): void
    {
        self::send($con, $recipient_id, 'new_message',
            'New Message',
            "{$sender_name} sent you a message.",
            $link
        );
    }

    public static function verificationApproved(mysqli $con, int $user_id, string $link = ''): void
    {
        self::send($con, $user_id, 'verification_approved',
            'Account Verified 🎉',
            'Your account has been verified. You can now start using PeerConnect!',
            $link
        );
    }

    public static function verificationRejected(mysqli $con, int $user_id, string $notes = '', string $link = ''): void
    {
        $msg = 'Your verification was not approved.' . ($notes ? " Reason: {$notes}" : ' Please review and resubmit.');
        self::send($con, $user_id, 'verification_rejected',
            'Verification Needs Attention',
            $msg,
            $link
        );
    }

    public static function missedSession(mysqli $con, int $user_id, string $other_name, string $link = ''): void
    {
        self::send($con, $user_id, 'missed_session',
            'Missed Session',
            "A session with {$other_name} was marked as missed.",
            $link
        );
    }

    public static function badgeAwarded(mysqli $con, int $user_id, string $badge_name, string $link = ''): void
    {
        self::send($con, $user_id, 'badge_awarded',
            "Badge Earned: {$badge_name} 🏅",
            "Congratulations! You've earned the {$badge_name} badge.",
            $link
        );
    }

    public static function certificateAwarded(mysqli $con, int $user_id, string $achievement, string $link = ''): void
    {
        self::send($con, $user_id, 'certificate_awarded',
            'Certificate Awarded 🎓',
            "You've received a certificate for: {$achievement}.",
            $link
        );
    }

    /**
     * The wording is built separately from the sending so it can be read and
     * checked without a database row or an outgoing email.
     */
    public static function blockMessage(string $reason = ''): string
    {
        $msg = 'Your account has been blocked and you can no longer sign in.';
        if (trim($reason) !== '') {
            $msg .= ' Reason: ' . trim($reason) . '.';
        }
        return $msg . ' Contact support if you believe this is a mistake.';
    }

    public static function accountBlocked(mysqli $con, int $user_id, string $reason = ''): void
    {
        self::send($con, $user_id, 'account_blocked', 'Account Blocked', self::blockMessage($reason));
    }

    /**
     * A restriction has a length and a reason, and the person serving it is
     * the one who most needs both. Telling them only that they are "temporarily
     * restricted" leaves them with no way to know when it ends or what to do
     * differently, so both are spelled out along with what they can still do.
     *
     * $end_date is 'Y-m-d'; omit it and the message simply drops the date
     * rather than inventing one.
     */
    public static function restrictionMessage(string $end_date = '', int $days = 0, string $reason = ''): string
    {
        $msg = 'Your account is restricted';

        $stamp = $end_date !== '' ? strtotime($end_date) : false;
        if ($stamp) {
            $msg .= ' until ' . date('F j, Y', $stamp);
            if ($days > 0) {
                $msg .= ' (' . $days . ' day' . ($days === 1 ? '' : 's') . ')';
            }
        } elseif ($days > 0) {
            $msg .= ' for ' . $days . ' day' . ($days === 1 ? '' : 's');
        }

        $msg .= '. You can still sign in and read your sessions and messages,'
              . ' but you cannot book sessions, send messages or post until it ends.';

        if (trim($reason) !== '') {
            $msg .= ' Reason: ' . trim($reason) . '.';
        }

        return $msg . ' The restriction lifts itself on the end date.';
    }

    public static function accountRestricted(
        mysqli $con,
        int    $user_id,
        string $end_date = '',
        int    $days = 0,
        string $reason = ''
    ): void {
        self::send($con, $user_id, 'account_restricted', 'Account Restricted',
            self::restrictionMessage($end_date, $days, $reason));
    }

    /**
     * The counterpart to blocking. Without this an unblocked member is left to
     * discover on their own that they can sign in again.
     */
    public static function accountUnblocked(mysqli $con, int $user_id, string $link = ''): void
    {
        self::send($con, $user_id, 'account_unblocked',
            'Account Restored',
            'Your account has been restored. You can sign in and use PeerConnect again.',
            $link
        );
    }

    /**
     * A restriction that has run its course. Sent when the gate lifts it, so
     * the person is told the moment it ends rather than having to test what
     * still fails.
     */
    public static function restrictionLifted(mysqli $con, int $user_id): void
    {
        self::send($con, $user_id, 'restriction_lifted',
            'Restriction Ended',
            'Your restriction has ended. You can book sessions, send messages and post again.'
        );
    }
}
