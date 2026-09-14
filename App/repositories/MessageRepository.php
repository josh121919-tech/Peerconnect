<?php

/**
 * MessageRepository — direct messages (the messages table).
 */
class MessageRepository extends Repository
{
    /** Messages sent to this user that they have not opened. */
    public static function countUnread(mysqli $con, int $receiverId): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0
        ", 'i', [$receiverId]);
    }

    /** Messages sent to this user in the last $days days, read or not. */
    public static function countReceivedInLastDays(mysqli $con, int $receiverId, int $days): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) FROM messages
            WHERE receiver_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ", 'ii', [$receiverId, $days]);
    }
}
