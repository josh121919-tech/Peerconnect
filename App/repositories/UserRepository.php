<?php

/**
 * UserRepository — accounts and the per-user settings stored beside them.
 */
class UserRepository extends Repository
{
    /** The account's first name, or null when there is no such account. */
    public static function firstName(mysqli $con, int $userId): ?string
    {
        return self::value($con, "SELECT firstname FROM users WHERE user_id = ?", 'i', [$userId]);
    }

    /**
     * Settings → Data Privacy → "Personalized recommendations". On unless the
     * member has switched it off; a member with no privacy row has the default.
     */
    public static function wantsPersonalizedRecommendations(mysqli $con, int $userId): bool
    {
        $v = self::value($con, "
            SELECT COALESCE(MAX(personalized_recommendations), 1)
            FROM privacy_settings WHERE user_id = ?
        ", 'i', [$userId]);
        return (int)($v ?? 1) === 1;
    }

    /** A mentee's saved preferences (topic, session type, level), or [] when none are saved. */
    public static function menteePreferences(mysqli $con, int $menteeId): array
    {
        return self::row($con, "
            SELECT preferred_topic, session_type, skill_level
            FROM mentee_preferences WHERE mentee_id = ?
        ", 'i', [$menteeId]) ?? [];
    }
}
