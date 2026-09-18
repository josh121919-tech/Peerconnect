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
     * A mentor mentees can find and book: a mentor account that is active (not
     * blocked or restricted) and verified. Find a Mentor lists exactly these,
     * and every booking endpoint accepts only these. For use inside a query on
     * users aliased $alias.
     */
    public static function bookableMentorCondition(string $alias = 'u'): string
    {
        return "$alias.role = 'mentor' AND $alias.status = 'active' AND $alias.verified = 1";
    }

    /** Whether mentees may book this account right now (see bookableMentorCondition()). */
    public static function isBookableMentor(mysqli $con, int $userId): bool
    {
        return self::value($con, "
            SELECT 1 FROM users u WHERE u.user_id = ? AND " . self::bookableMentorCondition('u'),
            'i', [$userId]) !== null;
    }

    /** "Firstname Lastname", or null when there is no such account (or either name is missing). */
    public static function fullName(mysqli $con, int $userId): ?string
    {
        return self::value($con, "SELECT CONCAT(firstname,' ',lastname) AS name FROM users WHERE user_id = ?", 'i', [$userId]);
    }

    /**
     * Everything a mentor's profile page shows about the account: every users
     * column plus the verification details ('club', 'expertise', 'course',
     * 'year_level') and the profile's 'profile_image' and 'bio'. Null when
     * there is no such account.
     */
    public static function mentorProfile(mysqli $con, int $userId): ?array
    {
        return self::row($con, "
            SELECT u.*, p.club, p.expertise, p.course, p.year_level, pr.profile_image, pr.bio
            FROM users u
            LEFT JOIN user_verifications p ON u.user_id = p.user_id
            LEFT JOIN profile pr ON u.user_id = pr.user_id
            WHERE u.user_id = ?
        ", 'i', [$userId]);
    }

    /** The account's skill and learning tags from the questionnaire, as plain strings: skills first, each group alphabetical. */
    public static function skillTags(mysqli $con, int $userId): array
    {
        return array_column(self::rows($con, "
            SELECT tag FROM user_tags WHERE user_id = ? AND tag_type IN ('skill','learn') ORDER BY tag_type, tag
        ", 'i', [$userId]), 'tag');
    }

    /** The account's 'firstname' and 'lastname', or null when there is no such account. */
    public static function names(mysqli $con, int $userId): ?array
    {
        return self::row($con, "SELECT firstname, lastname FROM users WHERE user_id = ?", 'i', [$userId]);
    }

    /** The email address of each of $userIds that exists ('user_id', 'email'). */
    public static function emailsFor(mysqli $con, array $userIds): array
    {
        if (!$userIds) {
            return [];
        }
        $ids = array_map('intval', array_values($userIds));
        return self::rows($con, "
            SELECT u.user_id, u.email
            FROM users u
            WHERE u.user_id IN (" . self::marks($ids) . ")
        ", str_repeat('i', count($ids)), $ids);
    }

    /**
     * The questionnaire tags of each of $userIds ('user_id', 'tag_type', 'tag'):
     * what they want to learn, then their skills, then their interests, each
     * alphabetically. These are the rows mentor matching runs on.
     */
    public static function tagsFor(mysqli $con, array $userIds): array
    {
        if (!$userIds) {
            return [];
        }
        $ids = array_map('intval', array_values($userIds));
        return self::rows($con, "
            SELECT user_id, tag_type, tag FROM user_tags
            WHERE user_id IN (" . self::marks($ids) . ") ORDER BY FIELD(tag_type,'learn','skill','interest'), tag
        ", str_repeat('i', count($ids)), $ids);
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

    // ── A member's own account ──────────────────────────────────────────────

    /** The account's 'firstname', 'lastname', 'email' and 'created_at', or null when there is no such account. */
    public static function accountBasics(mysqli $con, int $userId): ?array
    {
        return self::typedRow($con, "SELECT firstname, lastname, email, created_at FROM users WHERE user_id = ?", 'i', [$userId]);
    }

    /** When the account was created, or null when there is no such account. */
    public static function joinedAt(mysqli $con, int $userId): ?string
    {
        return self::value($con, "SELECT created_at FROM users WHERE user_id = ? LIMIT 1", 'i', [$userId]);
    }

    /** The account's role ('mentee', 'mentor', 'admin', or empty), or null when there is no such account. */
    public static function role(mysqli $con, int $userId): ?string
    {
        return self::value($con, "SELECT role FROM users WHERE user_id = ? LIMIT 1", 'i', [$userId]);
    }

    /** The account's email address, or null when it has none or does not exist. */
    public static function email(mysqli $con, int $userId): ?string
    {
        return self::value($con, "SELECT email FROM users WHERE user_id = ?", 'i', [$userId]);
    }

    /**
     * Gives a role-less account the role it chose. Only an account whose role
     * is still empty is changed. Returns 1 when it was set, else 0.
     */
    public static function claimRole(mysqli $con, int $userId, string $role): int
    {
        return self::execute($con, "UPDATE users SET role = ? WHERE user_id = ? AND (role IS NULL OR role = '')", 'si', [$role, $userId]);
    }

    /**
     * Marks the account verified, after an admin approved its application.
     * Its status is left alone: approving an application is not a way to lift
     * a block or a restriction.
     */
    public static function markVerified(mysqli $con, int $userId): void
    {
        self::execute($con, "UPDATE users SET verified = 1 WHERE user_id = ?", 'i', [$userId]);
    }

    /**
     * An account its owner deleted: signed out for good the way a block does
     * it, with no email address and no name left on it. The row stays, because
     * other people's sessions and reviews refer to it; it reads as "Deleted User".
     */
    public static function closeDeletedAccount(mysqli $con, int $userId): void
    {
        self::execute($con, "
            UPDATE users
            SET status = 'blocked', email = NULL, username = NULL,
                firstname = 'Deleted', middlename = NULL, lastname = 'User', suffix = ''
            WHERE user_id = ?
        ", 'i', [$userId]);
    }

    // ── A mentee's profile page ─────────────────────────────────────────────

    /**
     * What the mentee has done lately, newest first: completed sessions,
     * reviews given, submitted assessments and shared resources, as 'kind',
     * 'ts' and 'text'.
     */
    public static function recentActivityForMentee(mysqli $con, int $menteeId, int $limit): array
    {
        return self::rows($con, "
            SELECT 'session' AS kind, sr.session_date AS ts,
                   CONCAT('Completed a session with ', u.firstname, ' ', u.lastname) AS text
              FROM session_requests sr JOIN users u ON u.user_id = sr.mentor_id
             WHERE sr.mentee_id = ? AND sr.status = 'completed'
            UNION ALL
            SELECT 'review', f.created_at,
                   CONCAT('Gave a ', FORMAT(f.rating,1), ' rating to ', u.firstname, ' ', u.lastname)
              FROM feedback f JOIN users u ON u.user_id = f.mentor_id
             WHERE f.mentee_id = ?
            UNION ALL
            SELECT 'assessment', at.submitted_at,
                   CONCAT('Completed the assessment \"', a.title, '\"')
              FROM assessment_attempts at JOIN assessments a ON a.assessment_id = at.assessment_id
             WHERE at.mentee_id = ? AND at.status = 'submitted'
            UNION ALL
            SELECT 'resource', r.created_at, CONCAT('Shared the resource \"', r.title, '\"')
              FROM resources r WHERE r.uploader_id = ? AND r.is_active = 1
            ORDER BY ts DESC LIMIT ?
        ", 'iiiii', [$menteeId, $menteeId, $menteeId, $menteeId, $limit]);
    }

    /**
     * The counts behind the mentee's milestones: completed 'sessions',
     * 'reviews' given, submitted 'assessments', and different 'mentors'
     * with an accepted or completed session.
     */
    public static function menteeMilestoneCounts(mysqli $con, int $menteeId): array
    {
        return self::row($con, "
            SELECT (SELECT COUNT(*) FROM session_requests WHERE mentee_id = ? AND status = 'completed') AS sessions,
                   (SELECT COUNT(*) FROM feedback WHERE mentee_id = ?) AS reviews,
                   (SELECT COUNT(*) FROM assessment_attempts WHERE mentee_id = ? AND status = 'submitted') AS assessments,
                   (SELECT COUNT(DISTINCT mentor_id) FROM session_requests WHERE mentee_id = ? AND status IN ('approved','completed')) AS mentors
        ", 'iiii', [$menteeId, $menteeId, $menteeId, $menteeId]) ?? [];
    }
}
