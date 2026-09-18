<?php

/**
 * AchievementRepository — badges and certificates members have been awarded.
 */
class AchievementRepository extends Repository
{
    /**
     * The user's badges whose badge is still active, newest award first:
     * 'name', 'description', 'criteria_type', 'criteria_value', 'awarded_at', 'awarded_by'.
     */
    public static function activeBadgesFor(mysqli $con, int $userId): array
    {
        return self::typedRows($con, "
            SELECT b.name, b.description, b.criteria_type, b.criteria_value, ub.awarded_at, ub.awarded_by
            FROM user_badges ub
            JOIN badges b ON b.badge_id = ub.badge_id
            WHERE ub.user_id = ? AND b.is_active = 1
            ORDER BY ub.awarded_at DESC
        ", 'i', [$userId]);
    }

    /** The user's certificates, newest first: 'cert_id', 'achievement', 'awarded_at', 'generated_path', 'template_name' (null when the template is gone). */
    public static function certificatesFor(mysqli $con, int $userId): array
    {
        return self::typedRows($con, "
            SELECT uc.cert_id, uc.achievement, uc.awarded_at, uc.generated_path, ct.name as template_name
            FROM user_certificates uc
            LEFT JOIN certificate_templates ct ON ct.template_id = uc.template_id
            WHERE uc.user_id = ?
            ORDER BY uc.awarded_at DESC
        ", 'i', [$userId]);
    }

    /** How many of the user's badges are still active; with $thisMonth, only those awarded this month. */
    public static function countActiveBadges(mysqli $con, int $userId, bool $thisMonth = false): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) c FROM user_badges ub
            JOIN badges b ON b.badge_id = ub.badge_id
            WHERE ub.user_id = ? AND b.is_active = 1"
            . ($thisMonth ? " AND ub.awarded_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')" : ''),
            'i', [$userId]);
    }
}
