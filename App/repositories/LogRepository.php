<?php

/**
 * LogRepository — the activity log (logs): sign-ins, password changes and
 * admin actions. Rows are filed under the account's email address, which is
 * how Settings and the admin pages find a member's history.
 */
class LogRepository extends Repository
{
    /**
     * Refiles every entry under $from as $to, when an account changes its
     * address, so its history moves with it rather than staying behind under
     * an address it no longer has (and that someone else could later take).
     * Returns how many entries moved.
     */
    public static function moveToEmail(mysqli $con, string $from, string $to): int
    {
        return self::execute($con, "UPDATE logs SET email = ? WHERE email = ?", 'ss', [$to, $from]);
    }
}
