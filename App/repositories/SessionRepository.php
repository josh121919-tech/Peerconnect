<?php

/**
 * SessionRepository — mentoring sessions (the session_requests table).
 */
class SessionRepository extends Repository
{
    /**
     * An upcoming session, as the member pages count it: approved and not yet
     * started. For use inside a query on session_requests aliased $alias.
     *
     * NOT YET SETTLED: the admin pages (admin/includes/session_data.php,
     * admin/sessions.php, admin/sessions_reports.php) use "session_date > NOW()"
     * rather than ">=", and neither version counts a session that has started
     * but not ended, even though its call is still open. Kept exactly as each
     * page had it until that is decided.
     */
    public static function upcomingCondition(string $alias = 'sr'): string
    {
        return "$alias.status = 'approved' AND $alias.session_date >= NOW()";
    }

    /**
     * How many different mentors this mentee has an approved or completed
     * session with. With $olderThanDays, only sessions dated at least that
     * many days ago count — the dashboard's "as of last month" comparison.
     */
    public static function countMentorsForMentee(mysqli $con, int $menteeId, ?int $olderThanDays = null): int
    {
        $sql = "
            SELECT COUNT(DISTINCT mentor_id) FROM session_requests
            WHERE mentee_id = ? AND status IN ('approved','completed')";
        if ($olderThanDays === null) {
            return (int)self::value($con, $sql, 'i', [$menteeId]);
        }
        return (int)self::value($con, $sql . " AND session_date <= DATE_SUB(NOW(), INTERVAL ? DAY)",
            'ii', [$menteeId, $olderThanDays]);
    }

    /**
     * How many upcoming sessions the mentee has. Joined to the mentor's account
     * so it matches upcomingForMentee(), which cannot show a session whose
     * mentor account no longer exists.
     */
    public static function countUpcomingForMentee(mysqli $con, int $menteeId): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentor_id
            WHERE sr.mentee_id = ? AND " . self::upcomingCondition('sr'),
            'i', [$menteeId]);
    }

    /** The mentee's next upcoming sessions, soonest first, with the mentor's name and photo. */
    public static function upcomingForMentee(mysqli $con, int $menteeId, int $limit): array
    {
        return self::rows($con, "
            SELECT sr.request_id, sr.subject, sr.session_date, u.firstname, u.lastname, pr.profile_image
            FROM session_requests sr
            JOIN users u ON sr.mentor_id = u.user_id
            LEFT JOIN profile pr ON pr.user_id = u.user_id
            WHERE sr.mentee_id = ? AND " . self::upcomingCondition('sr') . "
            ORDER BY sr.session_date ASC
            LIMIT ?
        ", 'ii', [$menteeId, $limit]);
    }

    /** How many of the mentee's sessions are in each status, e.g. ['completed' => 5, 'pending' => 1]. */
    public static function statusCountsForMentee(mysqli $con, int $menteeId): array
    {
        $counts = [];
        foreach (self::rows($con, "
            SELECT status, COUNT(*) AS c FROM session_requests
            WHERE mentee_id = ? GROUP BY status
        ", 'i', [$menteeId]) as $row) {
            $counts[$row['status']] = (int)$row['c'];
        }
        return $counts;
    }

    /** Every mentor the mentee has ever sent a request to, whatever became of it. */
    public static function mentorIdsForMentee(mysqli $con, int $menteeId): array
    {
        return array_map('intval', array_column(self::rows($con, "
            SELECT DISTINCT mentor_id FROM session_requests WHERE mentee_id = ?
        ", 'i', [$menteeId]), 'mentor_id'));
    }
}
