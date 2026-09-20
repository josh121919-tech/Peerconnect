<?php

/**
 * NotificationRepository — reading a member's in-app notifications.
 *
 * Creating and emailing them stays in NotificationService, which decides who
 * is told what; this is only where they are read back.
 */
class NotificationRepository extends Repository
{
    /** The user's most recent notifications, newest first, each with the link it carries (null or '' when none). */
    public static function latestForUser(mysqli $con, int $userId, int $limit): array
    {
        return self::rows($con, "
            SELECT type, title, message, link, is_read, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ", 'ii', [$userId, $limit]);
    }

    /** The bell's list: the newest $limit notifications with their ids, values typed. */
    public static function recentForBell(mysqli $con, int $userId, int $limit): array
    {
        return self::typedRows($con, "
            SELECT notification_id, type, title, message, link, is_read, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ", 'ii', [$userId, $limit]);
    }

    /** The user's notifications for the admin bell: unread ones first, then newest first. */
    public static function unreadFirst(mysqli $con, int $userId, int $limit): array
    {
        return self::typedRows($con, "
            SELECT notification_id, title, message, link, is_read, created_at
            FROM notifications WHERE user_id = ?
            ORDER BY is_read ASC, created_at DESC LIMIT ?
        ", 'ii', [$userId, $limit]);
    }

    /** How many of the user's notifications are unread. */
    public static function countUnread(mysqli $con, int $userId): int
    {
        return (int)self::value($con, "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", 'i', [$userId]);
    }

    /** Marks one of the user's own notifications read. Someone else's is left alone. */
    public static function markRead(mysqli $con, int $notificationId, int $userId): void
    {
        self::execute($con, "UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?", 'ii', [$notificationId, $userId]);
    }

    /** Marks every one of the user's notifications read. */
    public static function markAllRead(mysqli $con, int $userId): void
    {
        self::execute($con, "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0", 'i', [$userId]);
    }

    // ── The admin Notifications page ────────────────────────────────────────

    /** The notification types admins send to members from the Notifications page. */
    public const ADMIN_NOTICE_TYPES = ['report_notice', 'report_update', 'admin_notice'];

    /**
     * Notices admins have sent to members, newest first, at most $limit, with
     * the recipient's name and role and whether they have read it.
     */
    public static function sentByAdmins(mysqli $con, int $limit): array
    {
        return self::typedRows($con, "
            SELECT n.notification_id, n.user_id, n.type, n.title, n.message, n.is_read, n.email_status, n.created_at,
                   TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) AS recipient_name, u.role AS recipient_role
            FROM notifications n
            JOIN users u ON u.user_id = n.user_id
            WHERE n.type IN (" . self::marks(self::ADMIN_NOTICE_TYPES) . ")
            ORDER BY n.created_at DESC, n.notification_id DESC
            LIMIT ?
        ", str_repeat('s', count(self::ADMIN_NOTICE_TYPES)) . 'i', array_merge(self::ADMIN_NOTICE_TYPES, [$limit]));
    }

    /** How many notices admins have sent to members in the last $days days. */
    public static function countSentByAdminsSince(mysqli $con, int $days): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) FROM notifications
            WHERE type IN (" . self::marks(self::ADMIN_NOTICE_TYPES) . ") AND created_at >= NOW() - INTERVAL ? DAY
        ", str_repeat('s', count(self::ADMIN_NOTICE_TYPES)) . 'i', array_merge(self::ADMIN_NOTICE_TYPES, [$days]));
    }

    /** One notification with its recipient's name and role, or null. */
    public static function withRecipient(mysqli $con, int $notificationId): ?array
    {
        return self::typedRow($con, "
            SELECT n.notification_id, n.user_id, n.type, n.title, n.message, n.link, n.is_read, n.email_status, n.created_at,
                   TRIM(CONCAT_WS(' ', u.firstname, u.lastname)) AS recipient_name, u.role AS recipient_role
            FROM notifications n
            JOIN users u ON u.user_id = n.user_id
            WHERE n.notification_id = ?
        ", 'i', [$notificationId]);
    }
}
