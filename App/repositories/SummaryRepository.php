<?php

/**
 * SummaryRepository — the figures on Reports & Analytics → Overall summary
 * and in its CSV export, over a range of days.
 *
 * Every method takes the range as two 'Y-m-d' dates, $from and $to, both
 * included. Both the page and the export ask here, so the two cannot count
 * the same thing differently.
 *
 * Admin accounts are staff, not members: they are left out of the member
 * figures, sign-ins included, as the page says.
 */
class SummaryRepository extends Repository
{
    /** How the charts slice a range, by the unit rp_buckets() picked. */
    private const BUCKETS = [
        'day'   => 'DATE(%1$s)',
        'week'  => 'DATE(DATE_SUB(%1$s, INTERVAL WEEKDAY(%1$s) DAY))',
        'month' => "DATE_FORMAT(%1\$s, '%%Y-%%m-01')",
    ];

    /** A sign-in, in the activity log. */
    private const SIGN_IN = "activity LIKE '%login%'";

    /** The day after $to, for "before the end of $to" on a datetime column. */
    private static function dayAfter(string $to): string
    {
        return date('Y-m-d', strtotime($to . ' +1 day'));
    }

    private static function bucket(string $unit, string $column): string
    {
        if (!isset(self::BUCKETS[$unit])) {
            throw new InvalidArgumentException('Unknown bucket unit: ' . $unit);
        }
        return sprintf(self::BUCKETS[$unit], $column);
    }

    /**
     * The headline figures for one range, as ints: joined, active, sessions,
     * completed, assessments, messages, feedback, resources, signins.
     *
     * "Active" means a member left a trace: signed in, had a session, sent a
     * message, submitted an assessment or wrote feedback. It is counted over
     * accounts that still exist, admins left out.
     */
    public static function window(mysqli $con, string $from, string $to): array
    {
        $start = $from . ' 00:00:00';
        $end   = self::dayAfter($to);
        $between = fn(string $sql) => (int)self::value($con, $sql, 'ss', [$from, $to]);

        return [
            'joined'      => $between("SELECT COUNT(*) FROM users WHERE role <> 'admin' AND DATE(created_at) BETWEEN ? AND ?"),
            'active'      => (int)self::value($con, "
                SELECT COUNT(*) FROM (
                    SELECT u.user_id FROM users u
                      JOIN logs l ON l.email = u.email
                     WHERE l." . self::SIGN_IN . " AND l.log_date >= ? AND l.log_date < ?
                    UNION
                    SELECT mentee_id FROM session_requests WHERE DATE(session_date) BETWEEN ? AND ?
                    UNION
                    SELECT mentor_id FROM session_requests WHERE DATE(session_date) BETWEEN ? AND ?
                    UNION
                    SELECT sender_id FROM messages WHERE DATE(created_at) BETWEEN ? AND ?
                    UNION
                    SELECT mentee_id FROM assessment_attempts
                     WHERE submitted_at IS NOT NULL AND DATE(submitted_at) BETWEEN ? AND ?
                    UNION
                    SELECT mentee_id FROM feedback WHERE DATE(created_at) BETWEEN ? AND ?
                ) t
                JOIN users mu ON mu.user_id = t.user_id
                WHERE mu.role <> 'admin'
            ", 'ssssssssssss', [$start, $end, $from, $to, $from, $to, $from, $to, $from, $to, $from, $to]),
            'sessions'    => $between("SELECT COUNT(*) FROM session_requests WHERE DATE(session_date) BETWEEN ? AND ?"),
            'completed'   => $between("SELECT COUNT(*) FROM session_requests WHERE status = 'completed' AND DATE(session_date) BETWEEN ? AND ?"),
            'assessments' => $between("SELECT COUNT(*) FROM assessment_attempts WHERE status = 'submitted' AND DATE(submitted_at) BETWEEN ? AND ?"),
            'messages'    => $between("SELECT COUNT(*) FROM messages WHERE DATE(created_at) BETWEEN ? AND ?"),
            'feedback'    => $between("SELECT COUNT(*) FROM feedback WHERE DATE(created_at) BETWEEN ? AND ?"),
            'resources'   => $between("SELECT COUNT(*) FROM resources WHERE DATE(created_at) BETWEEN ? AND ?"),
            // Sign-ins by admin accounts are left out. Those by accounts since
            // deleted are kept: they were members when they signed in.
            'signins'     => (int)self::value($con, "
                SELECT COUNT(*) FROM logs l
                 WHERE l." . self::SIGN_IN . " AND l.log_date >= ? AND l.log_date < ?
                   AND NOT EXISTS (SELECT 1 FROM users a WHERE a.email = l.email AND a.role = 'admin')
            ", 'ss', [$start, $end]),
        ];
    }

    /**
     * Members (not admins) who signed in during the range, as ints: 'people',
     * and 'returned', those who signed in on more than one day.
     */
    public static function signInPeople(mysqli $con, string $from, string $to): array
    {
        $row = self::row($con, "
            SELECT COUNT(*) AS people, SUM(days > 1) AS returned FROM (
                SELECT u.user_id, COUNT(DISTINCT DATE(l.log_date)) AS days
                  FROM users u JOIN logs l ON l.email = u.email
                 WHERE u.role <> 'admin' AND l." . self::SIGN_IN . "
                   AND l.log_date >= ? AND l.log_date < ?
                 GROUP BY u.user_id
            ) t
        ", 'ss', [$from . ' 00:00:00', self::dayAfter($to)]);
        return ['people' => (int)($row['people'] ?? 0), 'returned' => (int)($row['returned'] ?? 0)];
    }

    /** Mentees and mentors who joined in each bucket: 'k', 'role', 'n'. */
    public static function joinsPerBucket(mysqli $con, string $unit, string $from, string $to): array
    {
        return self::rows($con, "
            SELECT " . self::bucket($unit, 'created_at') . " AS k, role, COUNT(*) n
              FROM users
             WHERE role IN ('mentee','mentor') AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY k, role
        ", 'ss', [$from, $to]);
    }

    /** Mentees and mentors registered before $from, by role. */
    public static function membersBefore(mysqli $con, string $from): array
    {
        $out = ['mentee' => 0, 'mentor' => 0];
        foreach (self::rows($con, "
            SELECT role, COUNT(*) n FROM users
             WHERE role IN ('mentee','mentor') AND DATE(created_at) < ?
             GROUP BY role
        ", 's', [$from]) as $r) {
            $out[$r['role']] = (int)$r['n'];
        }
        return $out;
    }

    /** Sessions scheduled in each bucket: 'k', 'n', 'done' (completed), 'rest' (any other status). */
    public static function sessionsPerBucket(mysqli $con, string $unit, string $from, string $to): array
    {
        return self::rows($con, "
            SELECT " . self::bucket($unit, 'session_date') . " AS k,
                   COUNT(*) AS n,
                   SUM(status = 'completed') AS done,
                   SUM(status <> 'completed') AS rest
              FROM session_requests
             WHERE DATE(session_date) BETWEEN ? AND ?
             GROUP BY k
        ", 'ss', [$from, $to]);
    }

    /** Subjects of the sessions scheduled in the range, busiest first: 'subject', 'n'. All of them when $limit is 0. */
    public static function subjects(mysqli $con, string $from, string $to, int $limit = 0): array
    {
        $sql = "
            SELECT subject, COUNT(*) n
              FROM session_requests
             WHERE subject IS NOT NULL AND subject <> ''
               AND DATE(session_date) BETWEEN ? AND ?
             GROUP BY subject ORDER BY n DESC, subject";
        return $limit > 0
            ? self::rows($con, $sql . " LIMIT ?", 'ssi', [$from, $to, $limit])
            : self::rows($con, $sql, 'ss', [$from, $to]);
    }

    /** Mentees with at least one session scheduled in the range. */
    public static function bookingMentees(mysqli $con, string $from, string $to): int
    {
        return (int)self::value($con, "SELECT COUNT(DISTINCT mentee_id) FROM session_requests WHERE DATE(session_date) BETWEEN ? AND ?", 'ss', [$from, $to]);
    }

    /** Reviews written in the range: 'a' (the average rating, a string, or null) and 'n' (a string). */
    public static function ratings(mysqli $con, string $from, string $to): array
    {
        return self::row($con, "
            SELECT AVG(rating) a, COUNT(*) n FROM feedback
             WHERE rating > 0 AND DATE(created_at) BETWEEN ? AND ?
        ", 'ss', [$from, $to]) ?? ['a' => null, 'n' => '0'];
    }

    /** Sessions scheduled in the range that have concluded: completed, cancelled, declined or missed. */
    public static function concluded(mysqli $con, string $from, string $to): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) FROM session_requests
             WHERE status IN ('completed','cancelled','rejected','missed') AND DATE(session_date) BETWEEN ? AND ?
        ", 'ss', [$from, $to]);
    }

    /** Notifications raised in the range, by what happened to their email ('sent', 'failed', ...). */
    public static function emailStatuses(mysqli $con, string $from, string $to): array
    {
        $out = [];
        foreach (self::rows($con, "
            SELECT email_status, COUNT(*) n FROM notifications
             WHERE DATE(created_at) BETWEEN ? AND ?
             GROUP BY email_status
        ", 'ss', [$from, $to]) as $r) {
            $out[$r['email_status']] = (int)$r['n'];
        }
        return $out;
    }

    /** Every account by role, most first; an empty or missing role is 'unassigned'. */
    public static function roleMix(mysqli $con): array
    {
        $out = [];
        foreach (self::rows($con, "
            SELECT IF(role = '' OR role IS NULL, 'unassigned', role) AS r, COUNT(*) n
              FROM users GROUP BY r ORDER BY n DESC, r
        ") as $row) {
            $out[$row['r']] = (int)$row['n'];
        }
        return $out;
    }

    /** How many bytes the database's tables and indexes take. */
    public static function databaseBytes(mysqli $con): int
    {
        return (int)self::value($con, "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()");
    }

    /**
     * The last things that happened in the range, newest first: 'kind'
     * ('member', 'staff', 'session', 'assessment', 'message', 'feedback',
     * 'resource', 'announcement'), 'ts', 'who' and 'detail'. Sign-ins are
     * left out: there are hundreds and they have a page of their own.
     */
    public static function feed(mysqli $con, string $from, string $to, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        return self::rows($con, "
            SELECT * FROM (
                -- An admin account being created is real activity, but it is
                -- not a new member: the headline figure excludes staff, so the
                -- feed labels them apart rather than contradicting the tile.
                SELECT IF(u.role = 'admin', 'staff', 'member') AS kind, u.created_at AS ts,
                       CONCAT_WS(' ', u.firstname, u.lastname) AS who,
                       CONCAT('Joined as ', IF(u.role = '', 'no role yet', u.role)) AS detail
                  FROM users u
                 WHERE DATE(u.created_at) BETWEEN ? AND ?

                UNION ALL
                SELECT 'session', sr.session_date,
                       CONCAT_WS(' ', me.firstname, me.lastname),
                       CONCAT(sr.subject, ' with ', COALESCE(mo.firstname, 'a mentor'), ' — ', sr.status)
                  FROM session_requests sr
                  LEFT JOIN users me ON me.user_id = sr.mentee_id
                  LEFT JOIN users mo ON mo.user_id = sr.mentor_id
                 WHERE DATE(sr.session_date) BETWEEN ? AND ?
                   -- A session booked for next week has not happened yet, so
                   -- it is not activity. Upcoming ones are on the calendar.
                   AND sr.session_date <= NOW()

                UNION ALL
                SELECT 'assessment', a.submitted_at,
                       CONCAT_WS(' ', me.firstname, me.lastname),
                       CONCAT(COALESCE(asm.title, 'An assessment'), ' — ',
                              COALESCE(a.score, 0), '/', COALESCE(a.total_points, 0))
                  FROM assessment_attempts a
                  LEFT JOIN users me        ON me.user_id = a.mentee_id
                  LEFT JOIN assessments asm ON asm.assessment_id = a.assessment_id
                 WHERE a.status = 'submitted' AND DATE(a.submitted_at) BETWEEN ? AND ?

                UNION ALL
                SELECT 'message', m.created_at,
                       CONCAT_WS(' ', s.firstname, s.lastname),
                       CONCAT('Sent to ', COALESCE(r.firstname, 'a member'))
                  FROM messages m
                  LEFT JOIN users s ON s.user_id = m.sender_id
                  LEFT JOIN users r ON r.user_id = m.receiver_id
                 WHERE DATE(m.created_at) BETWEEN ? AND ?

                UNION ALL
                SELECT 'feedback', f.created_at,
                       CONCAT_WS(' ', me.firstname, me.lastname),
                       CONCAT('Rated ', COALESCE(mo.firstname, 'a mentor'), ' ', f.rating, ' out of 5')
                  FROM feedback f
                  LEFT JOIN users me ON me.user_id = f.mentee_id
                  LEFT JOIN users mo ON mo.user_id = f.mentor_id
                 WHERE DATE(f.created_at) BETWEEN ? AND ?

                UNION ALL
                SELECT 'resource', rs.created_at,
                       CONCAT_WS(' ', up.firstname, up.lastname),
                       rs.title
                  FROM resources rs
                  LEFT JOIN users up ON up.user_id = rs.uploader_id
                 WHERE DATE(rs.created_at) BETWEEN ? AND ?

                UNION ALL
                SELECT 'announcement', an.published_at,
                       COALESCE(CONCAT_WS(' ', ad.firstname, ad.lastname), 'Admin'),
                       an.title
                  FROM announcements an
                  LEFT JOIN users ad ON ad.user_id = an.created_by
                 WHERE an.published_at IS NOT NULL
                   AND DATE(an.published_at) BETWEEN ? AND ?
            ) feed
            WHERE ts IS NOT NULL
            ORDER BY ts DESC
            LIMIT ?
        ", str_repeat('ss', 7) . 'i', [$from, $to, $from, $to, $from, $to, $from, $to, $from, $to, $from, $to, $from, $to, $limit]);
    }
}
