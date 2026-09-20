<?php

/**
 * PlatformStatsRepository — the platform-wide figures on the admin dashboard
 * and on Platform analytics: accounts, sessions, ratings, subjects, mentor
 * scores and badges, counted across everyone rather than for one member.
 *
 * "This month" and "last month" are calendar months by the database's clock,
 * as the dashboard has always measured them. Each figures method answers in
 * one query what the pages used to ask one count at a time.
 */
class PlatformStatsRepository extends Repository
{
    /** The first day of this month and of last month, for a CROSS JOIN. */
    private const MONTHS = "(SELECT DATE_FORMAT(CURDATE(), '%Y-%m-01') AS m0,
                                    DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01') AS m1) mb";

    // ── Accounts ────────────────────────────────────────────────────────────

    /**
     * Every account count the two pages show, as ints:
     *   total, mentees, mentors, admins, members (everyone but admins);
     *   joined_now / joined_prev, and mentee_ / mentor_ the same, for this
     *   calendar month and last;
     *   status_active (active and verified), status_pending (active, not
     *   verified), status_restricted, status_blocked, all roles;
     *   active_mentors (active and verified), active_mentees (active),
     *   verified_mentors;
     *   member_active / member_restricted / member_blocked, member_verified
     *   and member_unverified, admins left out.
     */
    public static function accountFigures(mysqli $con): array
    {
        $row = self::row($con, "
            SELECT COUNT(*) AS total,
                   SUM(u.role = 'mentee') AS mentees,
                   SUM(u.role = 'mentor') AS mentors,
                   SUM(u.role = 'admin')  AS admins,
                   SUM(u.role <> 'admin') AS members,
                   SUM(u.created_at >= mb.m0) AS joined_now,
                   SUM(u.created_at >= mb.m1 AND u.created_at < mb.m0) AS joined_prev,
                   SUM(u.role = 'mentee' AND u.created_at >= mb.m0) AS mentee_now,
                   SUM(u.role = 'mentee' AND u.created_at >= mb.m1 AND u.created_at < mb.m0) AS mentee_prev,
                   SUM(u.role = 'mentor' AND u.created_at >= mb.m0) AS mentor_now,
                   SUM(u.role = 'mentor' AND u.created_at >= mb.m1 AND u.created_at < mb.m0) AS mentor_prev,
                   SUM(u.status = 'active' AND u.verified = 1) AS status_active,
                   SUM(u.status = 'active' AND u.verified = 0) AS status_pending,
                   SUM(u.status = 'restricted') AS status_restricted,
                   SUM(u.status = 'blocked')    AS status_blocked,
                   SUM(u.role = 'mentor' AND u.status = 'active' AND u.verified = 1) AS active_mentors,
                   SUM(u.role = 'mentee' AND u.status = 'active') AS active_mentees,
                   SUM(u.role = 'mentor' AND u.verified = 1) AS verified_mentors,
                   SUM(u.status = 'active'     AND u.role <> 'admin') AS member_active,
                   SUM(u.status = 'restricted' AND u.role <> 'admin') AS member_restricted,
                   SUM(u.status = 'blocked'    AND u.role <> 'admin') AS member_blocked,
                   SUM(u.verified = 1 AND u.role <> 'admin') AS member_verified,
                   SUM((u.verified = 0 OR u.verified IS NULL) AND u.role <> 'admin') AS member_unverified
            FROM users u CROSS JOIN " . self::MONTHS);
        return array_map('intval', $row ?? []);
    }

    /** The day the first account was created ('Y-m-d'), or null when there are none. */
    public static function firstAccountDate(mysqli $con): ?string
    {
        return self::value($con, "SELECT MIN(DATE(created_at)) FROM users");
    }

    /** Accounts created per day from $fromDate on: 'd', 'role', 'c'. */
    public static function joinsPerDay(mysqli $con, string $fromDate): array
    {
        return self::rows($con, "
            SELECT DATE(created_at) d, role, COUNT(*) c
            FROM users
            WHERE created_at >= ?
            GROUP BY d, role
        ", 's', [$fromDate]);
    }

    /** Members (not admins) who joined each month from $fromDate on, by 'Y-m'. */
    public static function joinsPerMonth(mysqli $con, string $fromDate): array
    {
        $out = [];
        foreach (self::rows($con, "
            SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) n
            FROM users
            WHERE role <> 'admin' AND created_at >= ?
            GROUP BY ym
        ", 's', [$fromDate]) as $r) {
            $out[$r['ym']] = (int)$r['n'];
        }
        return $out;
    }

    /** The newest accounts, with email and photo. */
    public static function recentAccounts(mysqli $con, int $limit): array
    {
        return self::rows($con, "
            SELECT u.user_id, u.firstname, u.lastname, u.role, u.created_at,
                   u.email, p.profile_image
            FROM users u
            LEFT JOIN profile p ON p.user_id = u.user_id
            ORDER BY u.created_at DESC
            LIMIT ?
        ", 'i', [$limit]);
    }

    // ── Sessions ────────────────────────────────────────────────────────────

    /**
     * Session counts by status, as ints: total, completed, completed_now and
     * completed_prev (held this calendar month and last), pending, missed,
     * cancelled, rejected, closed (completed, missed or cancelled); and
     * 'today' and 'this_week', approved or completed ones held today and in
     * this Monday-to-Sunday week, however far ahead they were booked.
     */
    public static function sessionFigures(mysqli $con): array
    {
        $today         = date('Y-m-d');
        $weekStart     = date('Y-m-d', strtotime('monday this week'));
        $nextWeekStart = date('Y-m-d', strtotime($weekStart . ' +7 days'));
        $row = self::row($con, "
            SELECT COUNT(*) AS total,
                   SUM(sr.status = 'completed') AS completed,
                   SUM(sr.status = 'completed' AND sr.session_date >= mb.m0) AS completed_now,
                   SUM(sr.status = 'completed' AND sr.session_date >= mb.m1 AND sr.session_date < mb.m0) AS completed_prev,
                   SUM(sr.status = 'pending')   AS pending,
                   SUM(sr.status = 'missed')    AS missed,
                   SUM(sr.status = 'cancelled') AS cancelled,
                   SUM(sr.status = 'rejected')  AS rejected,
                   SUM(sr.status IN ('completed','missed','cancelled')) AS closed,
                   SUM(DATE(sr.session_date) = ? AND sr.status IN ('approved','completed')) AS today,
                   SUM(sr.session_date >= ? AND sr.session_date < ? AND sr.status IN ('approved','completed')) AS this_week
            FROM session_requests sr CROSS JOIN " . self::MONTHS,
            'sss', [$today, $weekStart, $nextWeekStart]);
        return array_map('intval', $row ?? []);
    }

    /** Sessions completed each month from $fromDate on, by 'Y-m' of when they were held. */
    public static function completedPerMonth(mysqli $con, string $fromDate): array
    {
        $out = [];
        foreach (self::rows($con, "
            SELECT DATE_FORMAT(session_date, '%Y-%m') ym, COUNT(*) n
            FROM session_requests
            WHERE status = 'completed' AND session_date >= ?
            GROUP BY ym
        ", 's', [$fromDate]) as $r) {
            $out[$r['ym']] = (int)$r['n'];
        }
        return $out;
    }

    /**
     * Approved and completed sessions by the type of the slot they were booked
     * against ('t': '1v1', 'group', or '' when the slot has since been
     * deleted), with a count 'c'.
     */
    public static function sessionTypes(mysqli $con): array
    {
        return self::rows($con, "
            SELECT COALESCE(a.session_type, '') t, COUNT(*) c
            FROM session_requests sr
            LEFT JOIN availability a
                   ON a.mentor_id = sr.mentor_id AND a.subject = sr.subject
                  AND DATE(a.date) = DATE(sr.session_date)
                  AND TIME(a.start_time) = TIME(sr.session_date)
            WHERE sr.status IN ('approved','completed')
            GROUP BY t
        ");
    }

    /** The soonest pending or approved sessions still ahead, with both names, slot type and length. */
    public static function upcomingSessions(mysqli $con, int $limit): array
    {
        return self::rows($con, "
            SELECT sr.request_id, sr.subject, sr.session_date,
                   CONCAT(mu.firstname,' ',mu.lastname) AS mentor_name,
                   CONCAT(eu.firstname,' ',eu.lastname) AS mentee_name,
                   COALESCE(a.session_type,'') AS stype,
                   COALESCE(a.duration, 60)    AS duration
            FROM session_requests sr
            JOIN users mu ON mu.user_id = sr.mentor_id
            JOIN users eu ON eu.user_id = sr.mentee_id
            LEFT JOIN availability a
                   ON a.mentor_id = sr.mentor_id AND a.subject = sr.subject
                  AND DATE(a.date) = DATE(sr.session_date)
                  AND TIME(a.start_time) = TIME(sr.session_date)
            WHERE sr.session_date >= NOW() AND sr.status IN ('pending','approved')
            ORDER BY sr.session_date ASC
            LIMIT ?
        ", 'i', [$limit]);
    }

    /** Subjects by how many sessions were ever booked for them: 'subject', 'n'. Ties in name order, so the list holds still. */
    public static function subjectCounts(mysqli $con, int $limit): array
    {
        return self::rows($con, "
            SELECT subject, COUNT(*) n
            FROM session_requests
            WHERE subject IS NOT NULL AND subject <> ''
            GROUP BY subject
            ORDER BY n DESC, subject
            LIMIT ?
        ", 'i', [$limit]);
    }

    /** How many sessions were ever booked with a subject. */
    public static function sessionsWithSubject(mysqli $con): int
    {
        return (int)self::value($con, "SELECT COUNT(*) FROM session_requests WHERE subject IS NOT NULL AND subject <> ''");
    }

    /** Mentors with the most sessions marked missed, whoever missed them: user_id, names, 'cnt'. */
    public static function mostMissedByMentor(mysqli $con, int $limit): array
    {
        return self::rows($con, "
            SELECT u.user_id, u.firstname, u.lastname, COUNT(*) as cnt
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentor_id
            WHERE sr.status = 'missed'
            GROUP BY sr.mentor_id
            ORDER BY cnt DESC, sr.mentor_id
            LIMIT ?
        ", 'i', [$limit]);
    }

    /** Missed sessions by who was absent ('mentor', 'mentee', 'both', or 'none' when unrecorded). */
    public static function missedByWho(mysqli $con): array
    {
        $out = [];
        foreach (self::rows($con, "SELECT missed_by, COUNT(*) n FROM session_requests WHERE status = 'missed' GROUP BY missed_by") as $r) {
            $out[$r['missed_by'] ?: 'none'] = (int)$r['n'];
        }
        return $out;
    }

    // ── Ratings ─────────────────────────────────────────────────────────────

    /**
     * Mentee ratings: 'n' (int), 'avg' and 'avg2' (the average, and rounded
     * to two places, as strings, null when there are none), 'avg_now' and
     * 'avg_prev' for this calendar month and last (null when either had
     * none), and 'stars', [5 => n, 4 => n, ... 1 => n] by rounded rating.
     */
    public static function ratingFigures(mysqli $con): array
    {
        $row = self::row($con, "
            SELECT COUNT(*) AS n,
                   AVG(f.rating) AS avg,
                   ROUND(AVG(f.rating), 2) AS avg2,
                   AVG(CASE WHEN f.created_at >= mb.m0 THEN f.rating END) AS avg_now,
                   AVG(CASE WHEN f.created_at >= mb.m1 AND f.created_at < mb.m0 THEN f.rating END) AS avg_prev,
                   SUM(ROUND(f.rating) = 5) AS s5, SUM(ROUND(f.rating) = 4) AS s4,
                   SUM(ROUND(f.rating) = 3) AS s3, SUM(ROUND(f.rating) = 2) AS s2,
                   SUM(ROUND(f.rating) = 1) AS s1
            FROM feedback f CROSS JOIN " . self::MONTHS . "
            WHERE f.rating > 0");
        $stars = [];
        foreach ([5, 4, 3, 2, 1] as $k) {
            $stars[$k] = (int)($row['s' . $k] ?? 0);
        }
        return [
            'n'        => (int)($row['n'] ?? 0),
            'avg'      => $row['avg'] ?? null,
            'avg2'     => $row['avg2'] ?? null,
            'avg_now'  => $row['avg_now'] ?? null,
            'avg_prev' => $row['avg_prev'] ?? null,
            'stars'    => $stars,
        ];
    }

    // ── Recent events, for the dashboard's activity list ────────────────────

    /** The newest accounts: names, role, created_at. */
    public static function recentJoins(mysqli $con, int $limit): array
    {
        return self::rows($con, "SELECT firstname, lastname, role, created_at FROM users ORDER BY created_at DESC LIMIT ?", 'i', [$limit]);
    }

    /** The sessions completed most recently: subject, completed_at, the mentee as 'who'. */
    public static function recentCompletions(mysqli $con, int $limit): array
    {
        return self::rows($con, "
            SELECT sr.subject, sr.completed_at, CONCAT(eu.firstname,' ',eu.lastname) AS who
            FROM session_requests sr JOIN users eu ON eu.user_id = sr.mentee_id
            WHERE sr.status = 'completed' AND sr.completed_at IS NOT NULL
            ORDER BY sr.completed_at DESC LIMIT ?
        ", 'i', [$limit]);
    }

    /** The newest reviews: rating, created_at, the mentee as 'who'. */
    public static function recentReviews(mysqli $con, int $limit): array
    {
        return self::rows($con, "
            SELECT f.rating, f.created_at, CONCAT(u.firstname,' ',u.lastname) AS who
            FROM feedback f JOIN users u ON u.user_id = f.mentee_id
            ORDER BY f.created_at DESC LIMIT ?
        ", 'i', [$limit]);
    }

    /** The badges awarded most recently: badge 'name', awarded_at, the holder as 'who'. */
    public static function recentBadges(mysqli $con, int $limit): array
    {
        return self::rows($con, "
            SELECT b.name, ub.awarded_at, CONCAT(u.firstname,' ',u.lastname) AS who
            FROM user_badges ub JOIN badges b ON b.badge_id = ub.badge_id JOIN users u ON u.user_id = ub.user_id
            ORDER BY ub.awarded_at DESC LIMIT ?
        ", 'i', [$limit]);
    }

    // ── Mentor scores and badges (Platform analytics) ───────────────────────

    /** Active mentors by recommendation score, with their score inputs and review count. */
    public static function topMentors(mysqli $con, int $limit): array
    {
        return self::rows($con, "
            SELECT
                u.user_id, u.firstname, u.lastname,
                pr.profile_image,
                ms.last_calculated,
                COALESCE(ms.avg_rating, 0)           AS avg_rating,
                COALESCE(ms.total_sessions, 0)       AS total_sessions,
                COALESCE(ms.completion_rate, 0)      AS completion_rate,
                COALESCE(ms.effectiveness_score, 0)  AS effectiveness_score,
                COALESCE(ms.recommendation_score, 0) AS rec_score,
                (SELECT COUNT(*) FROM feedback f WHERE f.mentor_id = u.user_id) AS review_count
            FROM users u
            LEFT JOIN mentor_scores ms ON ms.mentor_id = u.user_id
            LEFT JOIN profile pr       ON pr.user_id   = u.user_id
            WHERE u.role = 'mentor' AND u.status = 'active'
            ORDER BY rec_score DESC, avg_rating DESC, u.user_id
            LIMIT ?
        ", 'i', [$limit]);
    }

    /** When mentor scores were last worked out, or null when never. */
    public static function scoresUpdatedAt(mysqli $con): ?string
    {
        return self::value($con, "SELECT MAX(last_calculated) FROM mentor_scores");
    }

    /** Active, verified mentors who have no score yet. */
    public static function unscoredMentors(mysqli $con): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) FROM users u
             WHERE u.role = 'mentor' AND u.status = 'active' AND u.verified = 1
               AND NOT EXISTS (SELECT 1 FROM mentor_scores ms WHERE ms.mentor_id = u.user_id)");
    }

    /** Badges awarded, as ints: 'total', and 'automatic' (awarded by no admin). */
    public static function badgeFigures(mysqli $con): array
    {
        $row = self::row($con, "SELECT COUNT(*) AS total, SUM(awarded_by IS NULL) AS automatic FROM user_badges");
        return ['total' => (int)($row['total'] ?? 0), 'automatic' => (int)($row['automatic'] ?? 0)];
    }

    /** Every badge with how many members hold it ('cnt'), most held first. */
    public static function badgesByHolders(mysqli $con): array
    {
        return self::rows($con, "
            SELECT b.badge_id, b.name, b.criteria_type, b.criteria_value, b.is_active,
                   COUNT(ub.user_badge_id) AS cnt
            FROM badges b
            LEFT JOIN user_badges ub ON ub.badge_id = b.badge_id
            GROUP BY b.badge_id
            ORDER BY cnt DESC, b.badge_id
        ");
    }
}
