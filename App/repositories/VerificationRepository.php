<?php

/**
 * VerificationRepository — members' applications to be verified
 * (user_verifications): the details they gave, the two documents they
 * uploaded, and the admin's decision.
 *
 * A member has at most one application; resubmitting replaces it. The
 * document files themselves are handled by VerificationFiles.
 */
class VerificationRepository extends Repository
{
    /**
     * The member's application (every column), or null when they have not
     * applied. Values come back as strings, as the application pages read them.
     */
    /** One application by its id, whoever it belongs to. */
    public static function byId(mysqli $con, int $verificationId): ?array
    {
        return self::row($con, "SELECT * FROM user_verifications WHERE verification_id = ? LIMIT 1",
            'i', [$verificationId]);
    }

    public static function forUser(mysqli $con, int $userId): ?array
    {
        return self::row($con, "
            SELECT * FROM user_verifications WHERE user_id = ?
            ORDER BY verification_id DESC LIMIT 1
        ", 'i', [$userId]);
    }

    /**
     * What an admin approved about the member — 'full_name', 'student_id',
     * 'course', 'year_level', 'club' — or null when nothing is approved.
     */
    public static function approvedDetails(mysqli $con, int $userId): ?array
    {
        return self::typedRow($con, "
            SELECT full_name, student_id, course, year_level, club FROM user_verifications
            WHERE user_id = ? AND status = 'approved' LIMIT 1
        ", 'i', [$userId]);
    }

    /**
     * The account's 'role' with its application's 'status' (null when it has
     * not applied), for the page that waits for a decision. Null when there is
     * no such account.
     */
    public static function statusWithRole(mysqli $con, int $userId): ?array
    {
        return self::typedRow($con, "
            SELECT u.role, v.status
            FROM users u
            LEFT JOIN user_verifications v ON v.user_id = u.user_id
            WHERE u.user_id = ?
            LIMIT 1
        ", 'i', [$userId]);
    }

    /**
     * Files the member's application, or refiles it for a new review.
     * $details holds 'full_name', 'student_id', 'course', 'year_level',
     * 'club', 'id_image', 'credential_image', and 'expertise' for a mentor.
     */
    /**
     * Whether verification_name_parts.sql has been run.
     *
     * Checked once per request and remembered: submit() asks on every save,
     * and information_schema is not free.
     */
    public static function hasNameParts(mysqli $con): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        $has = (int) self::value($con, "
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'user_verifications'
              AND COLUMN_NAME IN ('firstname', 'middlename', 'lastname')
        ") === 3;
        return $has;
    }

    public static function submit(mysqli $con, int $userId, array $details, bool $replacing): void
    {
        $columns = ['full_name'];
        // The parts are written only once verification_name_parts.sql has been
        // run. Until then the form still works and full_name still carries the
        // whole name, so the site is never broken between deploy and migrate.
        if (self::hasNameParts($con)) {
            array_push($columns, 'firstname', 'middlename', 'lastname');
        }
        array_push($columns, 'student_id', 'course', 'year_level', 'club');
        if (array_key_exists('expertise', $details)) $columns[] = 'expertise';
        $columns[] = 'id_image';
        $columns[] = 'credential_image';
        $values = array_map(fn($c) => $details[$c] ?? null, $columns);

        if ($replacing) {
            self::execute($con, "
                UPDATE user_verifications
                SET " . implode(', ', array_map(fn($c) => "$c = ?", $columns)) . ",
                    status = 'pending', admin_notes = NULL, submitted_at = NOW(), reviewed_at = NULL
                WHERE user_id = ?
            ", str_repeat('s', count($columns)) . 'i', array_merge($values, [$userId]));
            return;
        }

        self::execute($con, "
            INSERT INTO user_verifications (user_id, " . implode(', ', $columns) . ", status, submitted_at)
            VALUES (?, " . self::marks($columns) . ", 'pending', NOW())
        ", 'i' . str_repeat('s', count($columns)), array_merge([$userId], $values));
    }

    // ── Admin decisions ─────────────────────────────────────────────────────

    /**
     * Records an admin's decision ('approved' or 'rejected') on an application
     * that is still waiting for one. Returns 1 when it was recorded; 0 when
     * the application does not exist or was already decided — by another
     * admin, or from a page left open.
     */
    public static function decide(mysqli $con, int $verificationId, string $status, string $notes): int
    {
        return self::execute($con, "
            UPDATE user_verifications SET status = ?, admin_notes = ?, reviewed_at = NOW()
            WHERE verification_id = ? AND status = 'pending'
        ", 'ssi', [$status, $notes, $verificationId]);
    }

    /** Whose application this is, or null when there is no such application. */
    public static function ownerOf(mysqli $con, int $verificationId): ?int
    {
        $id = self::value($con, "SELECT user_id FROM user_verifications WHERE verification_id = ?", 'i', [$verificationId]);
        return $id === null ? null : (int)$id;
    }

    // ── The documents ───────────────────────────────────────────────────────

    /** Whether $file is one of this member's own application documents. */
    public static function fileBelongsTo(mysqli $con, string $file, int $userId): bool
    {
        return self::value($con, "
            SELECT 1 FROM user_verifications
            WHERE user_id = ? AND (id_image = ? OR credential_image = ?) LIMIT 1
        ", 'iss', [$userId, $file, $file]) !== null;
    }

    /** Whether $file is a document on any application — what an admin may open. */
    public static function fileIsKnown(mysqli $con, string $file): bool
    {
        return self::value($con, "
            SELECT 1 FROM user_verifications WHERE id_image = ? OR credential_image = ? LIMIT 1
        ", 'ss', [$file, $file]) !== null;
    }

    /** Every document name any application refers to. */
    public static function allFileNames(mysqli $con): array
    {
        $names = [];
        foreach (self::rows($con, "SELECT id_image, credential_image FROM user_verifications") as $r) {
            foreach ($r as $name) {
                if ($name !== null && $name !== '') $names[$name] = true;
            }
        }
        return array_keys($names);
    }

    /** The member's document names (empty when they have none). */
    public static function filesOf(mysqli $con, int $userId): array
    {
        $names = [];
        foreach (self::rows($con, "SELECT id_image, credential_image FROM user_verifications WHERE user_id = ?", 'i', [$userId]) as $r) {
            foreach ($r as $name) {
                if ($name !== null && $name !== '') $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * An account deleted by its owner: the application stays, so an admin can
     * still see that one existed and how it was decided, but it no longer
     * says who the person was or points at their documents.
     */
    public static function scrubForDeletedAccount(mysqli $con, int $userId): void
    {
        self::execute($con, "
            UPDATE user_verifications
            SET full_name = 'Deleted User', student_id = NULL, course = NULL, year_level = NULL, club = NULL,
                expertise = NULL, id_image = NULL, credential_image = NULL
            WHERE user_id = ?
        ", 'i', [$userId]);
    }
}
