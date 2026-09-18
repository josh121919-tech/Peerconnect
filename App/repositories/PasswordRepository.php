<?php

/**
 * PasswordRepository — the passwords table: one bcrypt hash per account that
 * has a password. Accounts made with Google have none until the member sets
 * one through Forgot password.
 */
class PasswordRepository extends Repository
{
    /** The account's 'password_id' and 'password_hash', or null when it has no password. */
    public static function forUser(mysqli $con, int $userId): ?array
    {
        return self::typedRow($con, "SELECT password_id, password_hash FROM passwords WHERE user_id = ? LIMIT 1", 'i', [$userId]);
    }

    public static function exists(mysqli $con, int $userId): bool
    {
        return self::value($con, "SELECT 1 FROM passwords WHERE user_id = ? LIMIT 1", 'i', [$userId]) !== null;
    }

    public static function create(mysqli $con, int $userId, string $hash): void
    {
        self::execute($con, "INSERT INTO passwords (user_id, password_hash) VALUES (?, ?)", 'is', [$userId, $hash]);
    }

    public static function setHash(mysqli $con, int $passwordId, string $hash): void
    {
        self::execute($con, "UPDATE passwords SET password_hash = ? WHERE password_id = ?", 'si', [$hash, $passwordId]);
    }
}
