<?php

/**
 * MessageRepository — direct messages (the messages table).
 *
 * Who may message whom is decided in MessageService; this only reads and
 * writes the rows.
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

    /**
     * Everyone the user has exchanged a message with, most recent first: their
     * name, role and photo, the last message and who sent it, when it was
     * sent ('last_time'), and how many from them are unread.
     */
    public static function conversationsFor(mysqli $con, int $userId): array
    {
        return self::typedRows($con, "
            SELECT
                u.user_id AS id,
                CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,'')) AS name,
                u.role,
                pr.profile_image,
                MAX(m.created_at) AS last_time,
                (SELECT content FROM messages
                 WHERE (sender_id = u.user_id AND receiver_id = ?)
                    OR (sender_id = ? AND receiver_id = u.user_id)
                 ORDER BY created_at DESC LIMIT 1) AS last_msg,
                (SELECT sender_id FROM messages
                 WHERE (sender_id = u.user_id AND receiver_id = ?)
                    OR (sender_id = ? AND receiver_id = u.user_id)
                 ORDER BY created_at DESC LIMIT 1) AS last_sender_id,
                SUM(CASE WHEN m.receiver_id = ? AND m.sender_id = u.user_id AND m.is_read = 0 THEN 1 ELSE 0 END) AS unread
            FROM users u
            LEFT JOIN profile pr ON pr.user_id = u.user_id
            JOIN messages m
              ON (m.sender_id = u.user_id AND m.receiver_id = ?)
              OR (m.sender_id = ? AND m.receiver_id = u.user_id)
            WHERE u.user_id != ?
            GROUP BY u.user_id, u.firstname, u.lastname, u.role, pr.profile_image
            ORDER BY last_time DESC
        ", 'iiiiiiii', [$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    }

    /**
     * The person on the other side of a chat: name, role, photo, and for the
     * header line their expertise and course from their latest verification
     * application. Null when there is no such account.
     */
    public static function chatPartner(mysqli $con, int $userId): ?array
    {
        return self::typedRow($con, "
            SELECT u.user_id AS id, CONCAT(COALESCE(u.firstname,''), ' ', COALESCE(u.lastname,'')) AS name,
                   u.role, pr.profile_image, uv.expertise, uv.course
            FROM users u
            LEFT JOIN profile pr ON pr.user_id = u.user_id
            LEFT JOIN user_verifications uv ON uv.verification_id = (
                SELECT MAX(v2.verification_id) FROM user_verifications v2 WHERE v2.user_id = u.user_id)
            WHERE u.user_id = ?
        ", 'i', [$userId]);
    }

    /** Marks read everything $senderId sent to $receiverId. */
    public static function markReadFrom(mysqli $con, int $senderId, int $receiverId): void
    {
        self::execute($con, "UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0",
            'ii', [$senderId, $receiverId]);
    }

    /** The newest $limit messages between the two, oldest first. */
    public static function latestBetween(mysqli $con, int $me, int $other, int $limit): array
    {
        return array_reverse(self::typedRows($con, "
            SELECT id, sender_id, content, is_read, created_at
            FROM messages
            WHERE (sender_id = ? AND receiver_id = ?)
               OR (sender_id = ? AND receiver_id = ?)
            ORDER BY created_at DESC LIMIT ?
        ", 'iiiii', [$me, $other, $other, $me, $limit]));
    }

    /** Messages between the two with an id above $lastId, oldest first, at most $limit. */
    public static function newerThan(mysqli $con, int $me, int $other, int $lastId, int $limit): array
    {
        return self::typedRows($con, "
            SELECT id, sender_id, content, created_at
            FROM messages
            WHERE id > ?
              AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
            ORDER BY id ASC
            LIMIT ?
        ", 'iiiiii', [$lastId, $me, $other, $other, $me, $limit]);
    }

    /** Whether $senderId has ever sent $receiverId a message. */
    public static function hasSent(mysqli $con, int $senderId, int $receiverId): bool
    {
        return self::value($con, "SELECT 1 FROM messages WHERE sender_id = ? AND receiver_id = ? LIMIT 1",
            'ii', [$senderId, $receiverId]) !== null;
    }

    /** Stores a message. Returns its id. */
    public static function send(mysqli $con, int $senderId, int $receiverId, string $content): int
    {
        return self::insert($con, "
            INSERT INTO messages (sender_id, receiver_id, content, is_read, created_at)
            VALUES (?, ?, ?, 0, NOW())
        ", 'iis', [$senderId, $receiverId, $content]);
    }

    /** When a message was stored, or null when there is no such message. */
    public static function createdAt(mysqli $con, int $messageId): ?string
    {
        return self::value($con, "SELECT created_at FROM messages WHERE id = ?", 'i', [$messageId]);
    }
}
