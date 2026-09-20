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

    /** The account's names, email and profile photo, or null when there is no such account. */
    public static function namesAndPhoto(mysqli $con, int $userId): ?array
    {
        return self::typedRow($con, "
            SELECT u.firstname, u.lastname, u.email, p.profile_image
            FROM users u
            LEFT JOIN profile p ON p.user_id = u.user_id
            WHERE u.user_id = ? LIMIT 1
        ", 'i', [$userId]);
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

    /** The user's questionnaire tags ('tag_type', 'tag'), by type and then in the order they were added. */
    public static function tagsInOrderAdded(mysqli $con, int $userId): array
    {
        return self::typedRows($con, "SELECT tag_type, tag FROM user_tags WHERE user_id = ? ORDER BY tag_type, tag_id", 'i', [$userId]);
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
     * Forgets that this account's address was ever confirmed.
     *
     * Called when the owner changes it: the new address is unproved, whatever
     * the old one was. Harmless before email_verification.sql has been run,
     * because the guard that reads the column stands aside then anyway.
     */
    public static function clearEmailConfirmation(mysqli $con, int $userId): void
    {
        if (!EmailVerificationRepository::migrated($con)) {
            return;
        }
        self::execute($con, "UPDATE users SET email_verified_at = NULL WHERE user_id = ?", 'i', [$userId]);
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

    /** 'firstname', 'lastname', 'email', 'username', 'role', 'verified', 'status' and 'created_at', for Settings. */
    public static function settingsAccount(mysqli $con, int $userId): ?array
    {
        return self::typedRow($con, "
            SELECT firstname, lastname, email, username, role, verified, status, created_at
            FROM users WHERE user_id = ?
        ", 'i', [$userId]);
    }

    /** Saves the name and username from Settings → Account. A null username clears it. */
    public static function saveNamesAndUsername(mysqli $con, int $userId, string $firstname, string $lastname, ?string $username): void
    {
        self::execute($con, "UPDATE users SET firstname = ?, lastname = ?, username = ? WHERE user_id = ?",
            'sssi', [$firstname, $lastname, $username, $userId]);
    }

    public static function setEmail(mysqli $con, int $userId, string $email): void
    {
        self::execute($con, "UPDATE users SET email = ? WHERE user_id = ?", 'si', [$email, $userId]);
    }

    // ── Signing in and creating accounts ────────────────────────────────────

    /** How long a first, middle or last name may be: the columns hold 50 characters. */
    public const NAME_MAX = 50;

    /**
     * The account using $email, as 'user_id', 'role', 'status', 'verified' and
     * 'email_verified_at', or null. See signInState() for why the last column
     * is selected the way it is.
     */
    public static function signInByEmail(mysqli $con, string $email): ?array
    {
        $confirmed = EmailVerificationRepository::migrated($con)
            ? 'email_verified_at'
            : 'NULL AS email_verified_at';

        return self::typedRow(
            $con,
            "SELECT user_id, role, status, verified, $confirmed FROM users WHERE email = ? LIMIT 1",
            's',
            [$email]
        );
    }

    /**
     * The admin account using $email, as 'user_id', 'firstname', 'status' and
     * 'password_hash', or null when there is none or it has no password.
     */
    public static function adminSignInByEmail(mysqli $con, string $email): ?array
    {
        return self::typedRow($con, "
            SELECT u.user_id, u.firstname, u.status, p.password_hash
            FROM users u
            JOIN passwords p ON p.user_id = u.user_id
            WHERE u.email = ? AND u.role = 'admin'
            LIMIT 1
        ", 's', [$email]);
    }

    // ── Moderation ──────────────────────────────────────────────────────────

    /**
     * 'user_id', 'role', 'status' and 'email' of an account an admin is about
     * to act on, or null when there is no such account. With $lock, the row
     * stays locked until the surrounding transaction ends, so two admins acting
     * on one account at once take turns instead of both reading the old status.
     */
    public static function moderationTarget(mysqli $con, int $userId, bool $lock = false): ?array
    {
        return self::typedRow($con, "SELECT user_id, role, status, email FROM users WHERE user_id = ?" . ($lock ? ' FOR UPDATE' : ''),
            'i', [$userId]);
    }

    /** Sets the account's status ('active', 'restricted' or 'blocked'). */
    public static function setStatus(mysqli $con, int $userId, string $status): void
    {
        self::execute($con, "UPDATE users SET status = ? WHERE user_id = ?", 'si', [$status, $userId]);
    }

    /** Sets the status to $to only while it is still $from. Returns 1 when it changed, else 0. */
    public static function changeStatus(mysqli $con, int $userId, string $from, string $to): int
    {
        return self::execute($con, "UPDATE users SET status = ? WHERE user_id = ? AND status = ?", 'sis', [$to, $userId, $from]);
    }

    /**
     * 'role', 'status', 'verified' and 'email_verified_at': what decides where
     * a signed-in account is sent, and what pc_enforce_account_status() reads
     * on every authenticated request.
     *
     * The last column only exists once email_verification.sql has been run, so
     * it is selected as a literal NULL until then — one query either way, and
     * nothing higher up has to know which install it is talking to. The column
     * name is chosen here, never taken from a caller.
     */
    public static function signInState(mysqli $con, int $userId): ?array
    {
        $confirmed = EmailVerificationRepository::migrated($con)
            ? 'email_verified_at'
            : 'NULL AS email_verified_at';

        return self::typedRow(
            $con,
            "SELECT role, status, verified, $confirmed FROM users WHERE user_id = ? LIMIT 1",
            'i',
            [$userId]
        );
    }

    /** Whether another account (not $exceptUserId) already uses $email. */
    public static function emailTaken(mysqli $con, string $email, int $exceptUserId = 0): bool
    {
        return self::value($con, "SELECT 1 FROM users WHERE email = ? AND user_id <> ? LIMIT 1", 'si', [$email, $exceptUserId]) !== null;
    }

    /** Whether another account (not $exceptUserId) already uses $username. */
    public static function usernameTaken(mysqli $con, string $username, int $exceptUserId = 0): bool
    {
        return self::value($con, "SELECT 1 FROM users WHERE username = ? AND user_id <> ? LIMIT 1", 'si', [$username, $exceptUserId]) !== null;
    }

    /** A new mentee or mentor account, unverified. Returns its id. */
    public static function createMember(mysqli $con, string $firstname, string $middlename, string $lastname, string $role, string $email): int
    {
        return self::insert($con, "INSERT INTO users (firstname, middlename, lastname, role, email) VALUES (?, ?, ?, ?, ?)",
            'sssss', [$firstname, $middlename, $lastname, $role, $email]);
    }

    /** A new admin account, active and verified. Returns its id. */
    public static function createAdmin(mysqli $con, string $firstname, string $lastname, string $username, string $email): int
    {
        return self::insert($con, "INSERT INTO users (firstname, lastname, username, role, status, verified, email) VALUES (?, ?, ?, 'admin', 'active', 1, ?)",
            'ssss', [$firstname, $lastname, $username, $email]);
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

    /**
     * Mentors and mentees an admin can send a notice to: every one not
     * deleted by its owner, by role then name ('user_id', 'name', 'role',
     * 'status', 'email').
     */
    public static function noticeRecipients(mysqli $con): array
    {
        return self::typedRows($con, "
            SELECT user_id, TRIM(CONCAT_WS(' ', firstname, lastname)) AS name, role, status, email
            FROM users
            WHERE role IN ('mentor', 'mentee') AND NOT (status = 'blocked' AND email IS NULL)
            ORDER BY role, firstname, lastname
        ");
    }
}
