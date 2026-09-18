<?php

/**
 * ModerationRepository — blocks, restrictions and reports against accounts.
 */
class ModerationRepository extends Repository
{
    /** Records that the account was blocked, and why. */
    public static function recordBlock(mysqli $con, int $userId, string $reason): void
    {
        self::execute($con, "INSERT INTO blocks (user_id, reason, blocked_at) VALUES (?, ?, NOW())", 'is', [$userId, $reason]);
    }
}
