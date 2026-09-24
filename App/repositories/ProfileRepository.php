<?php

/**
 * ProfileRepository — what members say about themselves: the profile row
 * (photo, bio, contact details, questionnaire stamps) and the interest,
 * skill and learning tags (user_tags).
 *
 * A profile row is created lazily, the first time something is saved to it,
 * so every read here allows for it not existing.
 */
class ProfileRepository extends Repository
{
    /** The profile columns a page may ask for by name. */
    private const COLUMNS = [
        'profile_id', 'full_name', 'student_id', 'course', 'year_level', 'section', 'club',
        'profile_image', 'phone', 'location', 'birthdate', 'bio', 'visibility',
        'onboarded_at', 'onboarding_skipped_at',
    ];

    /**
     * The named columns of the user's profile row, or null when they have no
     * row yet. Only the columns listed in COLUMNS can be asked for.
     */
    public static function fields(mysqli $con, int $userId, array $columns): ?array
    {
        foreach ($columns as $c) {
            if (!in_array($c, self::COLUMNS, true)) {
                throw new InvalidArgumentException("Unknown profile column: $c");
            }
        }
        return self::typedRow($con, "SELECT " . implode(', ', $columns) . " FROM profile WHERE user_id = ? LIMIT 1", 'i', [$userId]);
    }

    // ── Tags ────────────────────────────────────────────────────────────────

    /** The user's tags as 'tag_type' and 'tag', in the order they were added. */
    public static function tagsInOrderAdded(mysqli $con, int $userId): array
    {
        return self::typedRows($con, "SELECT tag_type, tag FROM user_tags WHERE user_id = ? ORDER BY tag_id ASC", 'i', [$userId]);
    }

    /** The user's tags as 'tag_type' and 'tag', grouped by type and alphabetical within it. */
    public static function tagsByType(mysqli $con, int $userId): array
    {
        return self::typedRows($con, "SELECT tag_type, tag FROM user_tags WHERE user_id = ? ORDER BY tag_type, tag", 'i', [$userId]);
    }

    /** Replaces the user's tags of one type ('interest', 'skill' or 'learn') with $tags. A repeated tag is kept once. */
    public static function replaceTags(mysqli $con, int $userId, string $type, array $tags): void
    {
        self::execute($con, "DELETE FROM user_tags WHERE user_id = ? AND tag_type = ?", 'is', [$userId, $type]);
        foreach ($tags as $tag) {
            self::execute($con, "INSERT IGNORE INTO user_tags (user_id, tag_type, tag) VALUES (?, ?, ?)", 'iss', [$userId, $type, $tag]);
        }
    }

    // ── Saving ──────────────────────────────────────────────────────────────

    /** Saves the About text, creating the profile row if there is none. */
    /**
     * Just the picture. The member pages change it inside a much larger save
     * of the whole profile; an administrator has no such form, and rewriting
     * their other columns to set one would be a good way to blank them.
     */
    public static function savePhoto(mysqli $con, int $userId, string $path): void
    {
        self::execute($con, "UPDATE profile SET profile_image = ? WHERE user_id = ?", 'si', [$path, $userId]);
    }

    public static function saveBio(mysqli $con, int $userId, string $bio): void
    {
        self::execute($con, "
            INSERT INTO profile (user_id, bio) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE bio = VALUES(bio)
        ", 'is', [$userId, $bio]);
    }

    /**
     * Makes sure the user has a profile row, named after the account, before
     * columns on it are updated. Most accounts only get one the first time
     * they edit their profile, and onboarding now runs before that.
     */
    public static function ensureRow(mysqli $con, int $userId): void
    {
        self::execute($con, "
            INSERT INTO profile (user_id, full_name)
            SELECT ?, TRIM(CONCAT(COALESCE(firstname,''), ' ', COALESCE(lastname,'')))
            FROM users WHERE user_id = ?
            ON DUPLICATE KEY UPDATE profile_id = profile_id
        ", 'ii', [$userId, $userId]);
    }

    /** The questionnaire was answered: stamps it, and clears any earlier skip. */
    public static function markOnboarded(mysqli $con, int $userId): void
    {
        self::execute($con, "UPDATE profile SET onboarded_at = NOW(), onboarding_skipped_at = NULL WHERE user_id = ?", 'i', [$userId]);
    }

    /** The questionnaire was skipped: stamps the skip, unless it was already answered. */
    public static function markOnboardingSkipped(mysqli $con, int $userId): void
    {
        self::execute($con, "UPDATE profile SET onboarding_skipped_at = NOW() WHERE user_id = ? AND onboarded_at IS NULL", 'i', [$userId]);
    }

    /** Updates the details the Edit Profile form covers. $image is the photo to keep. */
    public static function updateDetails(mysqli $con, int $userId, string $fullName, string $studentId, string $course,
                                         string $yearLevel, string $club, ?string $image): void
    {
        self::execute($con, "
            UPDATE profile
            SET full_name = ?, student_id = ?, course = ?, year_level = ?, club = ?, profile_image = ?
            WHERE user_id = ?
        ", 'ssssssi', [$fullName, $studentId, $course, $yearLevel, $club, $image, $userId]);
    }

    /** Creates the profile row with the Edit Profile form's details. */
    public static function createWithDetails(mysqli $con, int $userId, string $fullName, string $studentId, string $course,
                                             string $yearLevel, string $club, ?string $image): void
    {
        self::execute($con, "
            INSERT INTO profile (user_id, full_name, student_id, course, year_level, club, profile_image)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", 'issssss', [$userId, $fullName, $studentId, $course, $yearLevel, $club, $image]);
    }

    /** Saves Settings → Account's profile fields, creating the row for an account that has none yet. */
    public static function saveAccountDetails(mysqli $con, int $userId, string $fullName, string $phone, string $location,
                                              ?string $birthdate, string $bio, string $visibility): void
    {
        self::execute($con, "
            INSERT INTO profile (user_id, full_name, phone, location, birthdate, bio, visibility)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                full_name  = VALUES(full_name),
                phone      = VALUES(phone),
                location   = VALUES(location),
                birthdate  = VALUES(birthdate),
                bio        = VALUES(bio),
                visibility = VALUES(visibility)
        ", 'issssss', [$userId, $fullName, $phone, $location, $birthdate, $bio, $visibility]);
    }

    /**
     * An account deleted by its owner: the profile keeps its row (other
     * people's history refers to the account) but loses everything that
     * identifies or describes the person.
     */
    public static function scrubForDeletedAccount(mysqli $con, int $userId): void
    {
        self::execute($con, "
            UPDATE profile
            SET full_name = 'Deleted User', student_id = NULL, course = NULL, year_level = NULL, section = NULL,
                club = NULL, profile_image = NULL, phone = NULL, location = NULL, birthdate = NULL, bio = NULL
            WHERE user_id = ?
        ", 'i', [$userId]);
    }
}
