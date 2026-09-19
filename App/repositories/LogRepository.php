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

    /** Records $activity under $email, timed by the database clock. */
    public static function add(mysqli $con, string $email, string $activity): void
    {
        self::execute($con, "INSERT INTO logs (email, activity, log_date) VALUES (?, ?, NOW())", 'ss', [$email, $activity]);
    }

    /** The newest entries under $email, as 'activity' and 'log_date'. */
    public static function recentFor(mysqli $con, string $email, int $limit): array
    {
        return self::typedRows($con, "SELECT activity, log_date FROM logs WHERE email = ? ORDER BY log_date DESC LIMIT ?", 'si', [$email, $limit]);
    }

    /** When anything was last recorded under $email, or null when nothing ever was. */
    public static function lastSeen(mysqli $con, string $email): ?string
    {
        return self::value($con, "SELECT log_date FROM logs WHERE email = ? ORDER BY log_date DESC LIMIT 1", 's', [$email]);
    }

    /** When any of $activities was last recorded under $email, or null when none ever was. */
    public static function lastTime(mysqli $con, string $email, array $activities): ?string
    {
        return self::value($con, "SELECT MAX(log_date) FROM logs WHERE email = ? AND activity IN (" . self::marks($activities) . ")",
            's' . str_repeat('s', count($activities)), array_merge([$email], array_values($activities)));
    }

    /**
     * Admin log entries whose text contains $needle, newest first, with the
     * admin's email ('email', 'activity', 'log_date'). % and _ in $needle
     * match only themselves.
     */
    public static function adminEntriesContaining(mysqli $con, string $needle, int $limit): array
    {
        return self::typedRows($con, "
            SELECT email, activity, log_date FROM logs
            WHERE activity LIKE 'admin %' AND activity LIKE ?
            ORDER BY log_date DESC LIMIT ?
        ", 'si', ['%' . addcslashes($needle, '%_\\') . '%', $limit]);
    }
}
