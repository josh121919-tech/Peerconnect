<?php

/**
 * NotificationRepository — reading a member's in-app notifications.
 *
 * Creating and emailing them stays in NotificationService, which decides who
 * is told what; this is only where they are read back.
 */
class NotificationRepository extends Repository
{
    /** The user's most recent notifications, newest first. */
    public static function latestForUser(mysqli $con, int $userId, int $limit): array
    {
        return self::rows($con, "
            SELECT type, title, message, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ", 'ii', [$userId, $limit]);
    }
}
