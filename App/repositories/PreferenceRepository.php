<?php

/**
 * PreferenceRepository — a member's switches in Settings: which
 * notifications they get (notification_preferences) and the Data Privacy
 * options (privacy_settings). An account with no row yet has the defaults.
 */
class PreferenceRepository extends Repository
{
    public const NOTIFICATION_KEYS = ['session_requests', 'session_reminders', 'feedback_received', 'messages'];
    public const PRIVACY_KEYS      = ['personalized_recommendations', 'share_activity', 'third_party_integrations'];

    /** The four notification switches as 0/1, or null when the account has never changed one. */
    public static function notifications(mysqli $con, int $userId): ?array
    {
        return self::typedRow($con, "
            SELECT session_requests, session_reminders, feedback_received, messages
            FROM notification_preferences WHERE user_id = ?
        ", 'i', [$userId]);
    }

    /** Turns one notification switch on or off, creating the account's row with the defaults first. */
    public static function setNotification(mysqli $con, int $userId, string $key, bool $on): void
    {
        if (!in_array($key, self::NOTIFICATION_KEYS, true)) {
            throw new InvalidArgumentException("Unknown notification preference: $key");
        }
        self::execute($con, "INSERT IGNORE INTO notification_preferences (user_id) VALUES (?)", 'i', [$userId]);
        self::execute($con, "UPDATE notification_preferences SET `$key` = ? WHERE user_id = ?", 'ii', [$on ? 1 : 0, $userId]);
    }

    /** The three Data Privacy switches as 0/1, or null when the account has never changed one. */
    public static function privacy(mysqli $con, int $userId): ?array
    {
        return self::typedRow($con, "
            SELECT personalized_recommendations, share_activity, third_party_integrations
            FROM privacy_settings WHERE user_id = ?
        ", 'i', [$userId]);
    }

    /** Turns one Data Privacy switch on or off. */
    public static function setPrivacy(mysqli $con, int $userId, string $key, bool $on): void
    {
        if (!in_array($key, self::PRIVACY_KEYS, true)) {
            throw new InvalidArgumentException("Unknown privacy setting: $key");
        }
        self::execute($con, "
            INSERT INTO privacy_settings (user_id, `$key`) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE `$key` = VALUES(`$key`)
        ", 'ii', [$userId, $on ? 1 : 0]);
    }
}
