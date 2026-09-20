<?php

/**
 * EmailVerificationRepository — the rows behind the email-confirmation stage
 * of signup: the links that were issued, and the stamp on the account once
 * one of them was followed.
 *
 * The link itself is "selector:validator". Only the selector is stored in the
 * clear; the validator is kept as a SHA-256 hash and compared with
 * hash_equals() by the service. Read access to this table is therefore not
 * enough to forge a confirmation link — the same shape password_resets and
 * remember_tokens already use.
 */
class EmailVerificationRepository extends Repository
{
    /**
     * Whether the migration has been run.
     *
     * email_verification.sql adds users.email_verified_at. Until somebody runs
     * it as root the column is absent, and the whole stage has to stand aside
     * rather than fail — see EmailVerificationService::available(). Asked once
     * per request and remembered, because it cannot change mid-request.
     */
    public static function migrated(mysqli $con): bool
    {
        static $known = null;
        if ($known !== null) {
            return $known;
        }
        try {
            $known = self::value($con, "
                SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'users'
                  AND COLUMN_NAME = 'email_verified_at'
                LIMIT 1
            ") !== null;
        } catch (Throwable $e) {
            $known = false;
        }
        return $known;
    }

    /** When this account confirmed its address, or null if it has not. */
    public static function confirmedAt(mysqli $con, int $userId): ?string
    {
        if (!self::migrated($con)) {
            return null;
        }
        return self::value($con, "SELECT email_verified_at FROM users WHERE user_id = ? LIMIT 1", 'i', [$userId]);
    }

    /**
     * Stamps the account as confirmed, once.
     *
     * The `IS NULL` guard makes a second click on the same link a no-op rather
     * than a fresh timestamp, so the stamp always says when they first proved
     * it. Returns the rows changed: 1 the first time, 0 after.
     */
    public static function markConfirmed(mysqli $con, int $userId): int
    {
        return self::execute(
            $con,
            "UPDATE users SET email_verified_at = NOW() WHERE user_id = ? AND email_verified_at IS NULL",
            'i',
            [$userId]
        );
    }

    /**
     * Stamps a brand-new account as confirmed at creation.
     *
     * Used for Google sign-up, where Google has already proved the address —
     * asking the owner to confirm an address they just authenticated with is
     * a step that proves nothing.
     */
    public static function markConfirmedAtSignup(mysqli $con, int $userId): void
    {
        if (!self::migrated($con)) {
            return;
        }
        self::execute($con, "UPDATE users SET email_verified_at = NOW() WHERE user_id = ?", 'i', [$userId]);
    }

    /**
     * Who a confirmation link is for: the address it goes to and the name it
     * greets. UserRepository::names() stops at the names, and namesAndPhoto()
     * joins `profile` for a picture no email needs.
     */
    public static function recipient(mysqli $con, int $userId): ?array
    {
        return self::typedRow($con, "SELECT firstname, email FROM users WHERE user_id = ? LIMIT 1", 'i', [$userId]);
    }

    /** Retires every link this account is still holding. */
    public static function retireOutstanding(mysqli $con, int $userId): int
    {
        return self::execute(
            $con,
            "UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL",
            'i',
            [$userId]
        );
    }

    /** Records one issued link. $validator is already hashed. */
    public static function issue(mysqli $con, int $userId, string $selector, string $validator, int $ttlMinutes): int
    {
        return self::insert($con, "
            INSERT INTO email_verifications (user_id, selector, validator, expires_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))
        ", 'issi', [$userId, $selector, $validator, $ttlMinutes]);
    }

    /**
     * The unused, unexpired link with this selector, with enough of its owner
     * to finish the job. The validator comes back for the service to compare;
     * nothing here decides whether it matched.
     */
    public static function bySelector(mysqli $con, string $selector): ?array
    {
        return self::typedRow($con, "
            SELECT ev.verification_id, ev.user_id, ev.validator,
                   u.email, u.firstname, u.role, u.status, u.email_verified_at
            FROM email_verifications ev
            JOIN users u ON u.user_id = ev.user_id
            WHERE ev.selector = ? AND ev.used_at IS NULL AND ev.expires_at > NOW()
            LIMIT 1
        ", 's', [$selector]);
    }

    /**
     * Whether a selector exists at all, ignoring whether it is still usable.
     *
     * Lets the page tell "this link has expired, here is a new one" apart from
     * "this link was never ours", which are different problems for the person
     * holding it.
     */
    public static function selectorKnown(mysqli $con, string $selector): bool
    {
        return self::value($con, "SELECT 1 FROM email_verifications WHERE selector = ? LIMIT 1", 's', [$selector]) !== null;
    }

    /** Claims a link, so two tabs cannot both spend it. 1 when this call won. */
    public static function claim(mysqli $con, int $verificationId): int
    {
        return self::execute($con, "
            UPDATE email_verifications SET used_at = NOW()
            WHERE verification_id = ? AND used_at IS NULL AND expires_at > NOW()
        ", 'i', [$verificationId]);
    }

    /** How many links this account has asked for inside the trailing window. */
    public static function recentRequests(mysqli $con, int $userId, int $windowMinutes): int
    {
        return (int) self::value($con, "
            SELECT COUNT(*) FROM email_verifications
            WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ", 'ii', [$userId, $windowMinutes]);
    }

    /** Old rows have no owner to tidy them; swept opportunistically. */
    public static function purge(mysqli $con): void
    {
        self::execute($con, "DELETE FROM email_verifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    }
}
