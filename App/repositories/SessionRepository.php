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

    /**
     * The availability slot a session was booked into: same mentor, subject,
     * date and start time. A LEFT JOIN, so a session whose slot was since
     * edited or deleted still appears, with every $a column NULL.
     */
    public static function slotJoin(string $sr = 'sr', string $a = 'a'): string
    {
        return "LEFT JOIN availability $a
                   ON $a.mentor_id        = $sr.mentor_id
                  AND $a.subject          = $sr.subject
                  AND DATE($a.date)       = DATE($sr.session_date)
                  AND TIME($a.start_time) = TIME($sr.session_date)";
    }

    // ── A mentee's sessions ─────────────────────────────────────────────────

    /**
     * Every session of the mentee with the mentor's first and last name. With
     * $status, only sessions in exactly that status (an unknown status simply
     * matches nothing). $newestFirst orders by session date; without it the
     * rows come in the database's own order.
     */
    public static function withMentorForMentee(mysqli $con, int $menteeId, ?string $status = null, bool $newestFirst = true): array
    {
        $sql   = "SELECT sr.*, u.firstname, u.lastname FROM session_requests sr
                  JOIN users u ON sr.mentor_id = u.user_id
                  WHERE sr.mentee_id = ?";
        $types = 'i';
        $args  = [$menteeId];
        if ($status !== null) {
            $sql .= " AND sr.status = ?";
            $types .= 's';
            $args[] = $status;
        }
        if ($newestFirst) {
            $sql .= " ORDER BY sr.session_date DESC";
        }
        return self::rows($con, $sql, $types, $args);
    }

    /** How many sessions the mentee has in total. */
    public static function countForMentee(mysqli $con, int $menteeId): int
    {
        return (int)self::value($con, "SELECT COUNT(*) FROM session_requests WHERE mentee_id = ?", 'i', [$menteeId]);
    }

    /** How many of the mentee's sessions are in any of $statuses. */
    public static function countForMenteeInStatuses(mysqli $con, int $menteeId, array $statuses): int
    {
        if (!$statuses) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($statuses), '?'));
        return (int)self::value($con, "
            SELECT COUNT(*) FROM session_requests WHERE mentee_id = ? AND status IN ($marks)
        ", 'i' . str_repeat('s', count($statuses)), array_merge([$menteeId], array_values($statuses)));
    }

    /** How many of the mentee's sessions are dated from $from up to, but not including, $to ('Y-m-d H:i:s'). */
    public static function countForMenteeBetween(mysqli $con, int $menteeId, string $from, string $to): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) FROM session_requests
            WHERE mentee_id = ? AND session_date >= ? AND session_date < ?
        ", 'iss', [$menteeId, $from, $to]);
    }

    /** How many of the mentee's sessions fall on the day $day ('Y-m-d'). */
    public static function countForMenteeOnDay(mysqli $con, int $menteeId, string $day): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) FROM session_requests WHERE mentee_id = ? AND DATE(session_date) = ?
        ", 'is', [$menteeId, $day]);
    }

    /** Sessions per day between $from and $to (exclusive), as ['2026-09-15' => 2, ...]. */
    public static function countsPerDayForMentee(mysqli $con, int $menteeId, string $from, string $to): array
    {
        $out = [];
        foreach (self::rows($con, "
            SELECT DATE(session_date) AS d, COUNT(*) AS c FROM session_requests
            WHERE mentee_id = ? AND session_date >= ? AND session_date < ?
            GROUP BY DATE(session_date)
        ", 'iss', [$menteeId, $from, $to]) as $row) {
            $out[$row['d']] = (int)$row['c'];
        }
        return $out;
    }

    /** The mentee's upcoming sessions with the mentor's name and photo and the slot's type, length and topics. */
    public static function upcomingWithSlotForMentee(mysqli $con, int $menteeId, int $limit): array
    {
        return self::rows($con, "
            SELECT sr.request_id, sr.subject, sr.session_date, u.firstname, u.lastname,
                   pr.profile_image, a.session_type, a.duration, a.topics
            FROM session_requests sr
            JOIN users u ON sr.mentor_id = u.user_id
            LEFT JOIN profile pr ON pr.user_id = u.user_id
            " . self::slotJoin('sr', 'a') . "
            WHERE sr.mentee_id = ? AND " . self::upcomingCondition('sr') . "
            ORDER BY sr.session_date ASC
            LIMIT ?
        ", 'ii', [$menteeId, $limit]);
    }

    /**
     * The mentee's closed sessions, newest first: completed, rejected, cancelled
     * and missed, with the mentor's name and photo and the mentee's rating.
     */
    public static function pastForMentee(mysqli $con, int $menteeId, int $limit): array
    {
        return self::rows($con, "
            SELECT sr.request_id, sr.subject, sr.session_date, sr.status, u.firstname, u.lastname,
                   pr.profile_image, f.rating
            FROM session_requests sr
            JOIN users u ON sr.mentor_id = u.user_id
            LEFT JOIN profile pr ON pr.user_id = u.user_id
            LEFT JOIN feedback f ON f.session_id = sr.request_id AND f.mentee_id = sr.mentee_id
            WHERE sr.mentee_id = ? AND sr.status IN ('completed','rejected','cancelled','missed')
            ORDER BY sr.session_date DESC
            LIMIT ?
        ", 'ii', [$menteeId, $limit]);
    }

    /**
     * The mentee's requests page list: everything except cancelled, newest
     * first, with the slot's type and length and the mentor's name. $status
     * narrows to one status; $search matches the mentor's first or last name
     * anywhere (% and _ keep their LIKE meaning, as they always have here).
     */
    public static function requestsForMentee(mysqli $con, int $menteeId, ?string $status = null, ?string $search = null): array
    {
        $sql = "
            SELECT sr.*, u.firstname, u.lastname, a.session_type, a.duration, CONCAT(u.firstname,' ',u.lastname) AS mentor_name
            FROM session_requests sr
            JOIN users u ON sr.mentor_id = u.user_id
            " . self::slotJoin('sr', 'a') . "
            WHERE sr.mentee_id = ? AND sr.status != 'cancelled'";
        $types = 'i';
        $args  = [$menteeId];
        if ($status !== null) {
            $sql .= " AND sr.status = ?";
            $types .= 's';
            $args[] = $status;
        }
        if ($search !== null) {
            $sql .= " AND (u.firstname LIKE ? OR u.lastname LIKE ?)";
            $types .= 'ss';
            $args[] = '%' . $search . '%';
            $args[] = '%' . $search . '%';
        }
        return self::rows($con, $sql . " ORDER BY sr.session_date DESC", $types, $args);
    }

    /** The mentee's cancelled requests, newest first, with the mentor's name. */
    public static function cancelledForMentee(mysqli $con, int $menteeId): array
    {
        return self::rows($con, "
            SELECT sr.*, u.firstname, u.lastname, sr.mentor_id, CONCAT(u.firstname,' ',u.lastname) AS mentor_name
            FROM session_requests sr
            JOIN users u ON sr.mentor_id = u.user_id
            WHERE sr.mentee_id = ? AND sr.status = 'cancelled'
            ORDER BY sr.session_date DESC
        ", 'i', [$menteeId]);
    }

    /**
     * One of the mentee's sessions with the mentee's own full name, or null
     * when it does not exist or belongs to someone else.
     */
    public static function findForMentee(mysqli $con, int $sessionId, int $menteeId): ?array
    {
        return self::row($con, "
            SELECT sr.mentor_id, sr.subject, sr.session_date, sr.status,
                   CONCAT(u.firstname,' ',u.lastname) AS mentee_name
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentee_id
            WHERE sr.request_id = ? AND sr.mentee_id = ?
        ", 'ii', [$sessionId, $menteeId]);
    }

    /**
     * The mentee cancels one of their own sessions. Only a pending or approved
     * session can be cancelled; a closed one (completed, missed, rejected,
     * already cancelled) is left alone. Returns 1 when it was cancelled, else 0.
     */
    public static function cancelByMentee(mysqli $con, int $sessionId, int $menteeId): int
    {
        return self::execute($con, "
            UPDATE session_requests SET status='cancelled'
            WHERE request_id=? AND mentee_id=? AND status IN ('pending','approved')
        ", 'ii', [$sessionId, $menteeId]);
    }

    /** The mentee deletes one of their own requests from their list. Returns 1 when a row was deleted, else 0. */
    public static function deleteForMentee(mysqli $con, int $sessionId, int $menteeId): int
    {
        return self::execute($con, "DELETE FROM session_requests WHERE request_id=? AND mentee_id=?", 'ii', [$sessionId, $menteeId]);
    }

    // ── The mentee calendar (native types: the page passes rows to JavaScript) ──

    /**
     * The mentee's pending, approved and completed sessions dated from $startDay
     * to $endDay inclusive ('Y-m-d'), with the mentor's name, photo and club and
     * the slot's length (60 when there is no slot) and type ('1v1' when none).
     */
    public static function calendarForMentee(mysqli $con, int $menteeId, string $startDay, string $endDay): array
    {
        return self::typedRows($con, "
            SELECT sr.request_id, sr.subject, sr.session_date, sr.status, sr.mentor_id,
                   CONCAT(u.firstname, ' ', u.lastname) AS mentor_name,
                   p.profile_image, p.club,
                   COALESCE(a.duration, 60)        AS duration,
                   COALESCE(a.session_type, '1v1') AS session_type
            FROM session_requests sr
            JOIN users u        ON u.user_id = sr.mentor_id
            LEFT JOIN profile p ON p.user_id = sr.mentor_id
            " . self::slotJoin('sr', 'a') . "
            WHERE sr.mentee_id = ?
              AND sr.status IN ('pending', 'approved', 'completed')
              AND DATE(sr.session_date) BETWEEN ? AND ?
            ORDER BY sr.session_date ASC
        ", 'iss', [$menteeId, $startDay, $endDay]);
    }

    /**
     * The calendar's side rail: pending and approved sessions from now on,
     * soonest first, with the slot's length (60 when there is no slot). Unlike
     * upcomingCondition(), pending requests are included here.
     */
    public static function openFromNowForMentee(mysqli $con, int $menteeId, int $limit): array
    {
        return self::typedRows($con, "
            SELECT sr.request_id, sr.subject, sr.session_date, sr.status, sr.mentor_id,
                   CONCAT(u.firstname, ' ', u.lastname) AS mentor_name,
                   COALESCE(a.duration, 60) AS duration
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentor_id
            " . self::slotJoin('sr', 'a') . "
            WHERE sr.mentee_id = ?
              AND sr.status IN ('pending', 'approved')
              AND sr.session_date >= NOW()
            ORDER BY sr.session_date ASC
            LIMIT ?
        ", 'ii', [$menteeId, $limit]);
    }

    /** Every mentor the mentee has ever sent a request to, whatever became of it. */
    public static function mentorIdsForMentee(mysqli $con, int $menteeId): array
    {
        return array_map('intval', array_column(self::rows($con, "
            SELECT DISTINCT mentor_id FROM session_requests WHERE mentee_id = ?
        ", 'i', [$menteeId]), 'mentor_id'));
    }

    // ── A mentor's requests ─────────────────────────────────────────────────

    /**
     * The mentor accepts one of their pending requests. Returns 1 when it was
     * accepted, 0 when it is not pending, not theirs, or does not exist.
     */
    public static function approveByMentor(mysqli $con, int $sessionId, int $mentorId): int
    {
        return self::execute($con, "
            UPDATE session_requests SET status='approved'
            WHERE request_id=? AND mentor_id=? AND status='pending'
        ", 'ii', [$sessionId, $mentorId]);
    }

    /**
     * The mentor declines one of their pending requests. With $reason it is
     * saved for the mentee; without one the reason column is left untouched.
     * Returns 1 when it was declined, 0 when it is not pending, not theirs, or
     * does not exist.
     */
    public static function rejectByMentor(mysqli $con, int $sessionId, int $mentorId, ?string $reason = null): int
    {
        if ($reason === null) {
            return self::execute($con, "
                UPDATE session_requests SET status='rejected'
                WHERE request_id=? AND mentor_id=? AND status='pending'
            ", 'ii', [$sessionId, $mentorId]);
        }
        return self::execute($con, "
            UPDATE session_requests SET status='rejected', rejection_reason=?
            WHERE request_id=? AND mentor_id=? AND status='pending'
        ", 'sii', [$reason, $sessionId, $mentorId]);
    }

    /**
     * Who to tell about a decision on one of the mentor's sessions: the
     * mentee's id and the mentor's full name ('mentee_id', 'mentor_name'), or
     * null when the session is not theirs.
     */
    public static function menteeAndMentorName(mysqli $con, int $sessionId, int $mentorId): ?array
    {
        return self::row($con, "
            SELECT sr.mentee_id, CONCAT(u.firstname,' ',u.lastname) AS mentor_name
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentor_id
            WHERE sr.request_id = ? AND sr.mentor_id = ?
            LIMIT 1
        ", 'ii', [$sessionId, $mentorId]);
    }

    /** One of the mentor's sessions ('mentee_id', 'subject'), or null when it does not exist or is not theirs. */
    public static function findForMentor(mysqli $con, int $sessionId, int $mentorId): ?array
    {
        return self::row($con, "
            SELECT mentee_id, subject FROM session_requests WHERE request_id = ? AND mentor_id = ?
        ", 'ii', [$sessionId, $mentorId]);
    }

    /**
     * The mentor cancels one of their sessions, e.g. removes a student from a
     * group slot. Only a pending or approved session can be cancelled; one that
     * has closed is left alone, so a finished session is never rewritten.
     * Returns 1 when it was cancelled, else 0.
     */
    public static function cancelByMentor(mysqli $con, int $sessionId, int $mentorId): int
    {
        return self::execute($con, "
            UPDATE session_requests SET status = 'cancelled'
            WHERE request_id = ? AND mentor_id = ? AND status IN ('pending','approved')
        ", 'ii', [$sessionId, $mentorId]);
    }

    /**
     * The mentor Sessions page's counts: 'pending', 'upcoming', 'completed',
     * 'declined', and 'mentees' — how many different mentees have an approved
     * or completed session. Only sessions whose mentee account still exists
     * are counted, so the numbers agree with the lists under them.
     */
    public static function statsForMentor(mysqli $con, int $mentorId): array
    {
        return self::row($con, "
            SELECT
                SUM(sr.status = 'pending')                                        AS pending,
                SUM(" . self::upcomingCondition('sr') . ")          AS upcoming,
                SUM(sr.status = 'completed')                                      AS completed,
                SUM(sr.status = 'rejected')                                       AS declined,
                COUNT(DISTINCT CASE WHEN sr.status IN ('approved','completed')
                                    THEN sr.mentee_id END)                        AS mentees
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentee_id
            WHERE sr.mentor_id = ?
        ", 'i', [$mentorId]) ?? [];
    }

    /**
     * The mentor's requests in one status, newest first, with everything a
     * request card and its detail panel show: the mentee's name, photo, bio,
     * course and year. Joined to users because session_requests has no foreign
     * key — rows pointing at deleted accounts are still in the table, and an
     * unjoined count once put a pending badge over an empty list.
     */
    public static function requestsForMentor(mysqli $con, int $mentorId, string $status): array
    {
        return self::typedRows($con, "
            SELECT sr.request_id, sr.mentee_id, sr.subject, sr.message, sr.session_date,
                   sr.status, sr.rejection_reason,
                   u.firstname, u.lastname,
                   p.profile_image, p.bio, p.course, p.year_level
            FROM session_requests sr
            JOIN users u   ON u.user_id = sr.mentee_id
            LEFT JOIN profile p ON p.user_id = u.user_id
            WHERE sr.mentor_id = ? AND sr.status = ?
            ORDER BY sr.session_date DESC
        ", 'is', [$mentorId, $status]);
    }

    // ── The mentor dashboard (native types: the page used prepared statements) ──

    /**
     * How many different mentees had their first approved or completed session
     * with the mentor this calendar month. Only mentees whose account still
     * exists count, as in statsForMentor().
     */
    public static function countNewMenteesThisMonthForMentor(mysqli $con, int $mentorId): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) FROM (
                SELECT sr.mentee_id, MIN(sr.session_date) AS first_on
                FROM session_requests sr
                JOIN users u ON u.user_id = sr.mentee_id
                WHERE sr.mentor_id = ? AND sr.status IN ('approved','completed')
                GROUP BY sr.mentee_id
            ) f
            WHERE f.first_on >= DATE_FORMAT(NOW(), '%Y-%m-01')
        ", 'i', [$mentorId]);
    }

    /** The mentor's next upcoming session with the mentee's name, or null when there is none. */
    public static function nextUpcomingForMentor(mysqli $con, int $mentorId): ?array
    {
        return self::row($con, "
            SELECT sr.request_id, sr.session_date, sr.subject, u.firstname, u.lastname
            FROM session_requests sr JOIN users u ON u.user_id = sr.mentee_id
            WHERE sr.mentor_id = ? AND " . self::upcomingCondition('sr') . "
            ORDER BY sr.session_date ASC LIMIT 1
        ", 'i', [$mentorId]);
    }

    /** The mentor's pending requests, soonest first, with the mentee's first and last name. */
    public static function pendingSoonestForMentor(mysqli $con, int $mentorId): array
    {
        return self::typedRows($con, "
            SELECT sr.*, u.firstname, u.lastname
            FROM session_requests sr JOIN users u ON sr.mentee_id = u.user_id
            WHERE sr.mentor_id = ? AND sr.status = 'pending'
            ORDER BY sr.session_date ASC
        ", 'i', [$mentorId]);
    }

    /** The mentor's approved and completed sessions dated today, earliest first, with the mentee's name. */
    public static function todayForMentor(mysqli $con, int $mentorId): array
    {
        return self::typedRows($con, "
            SELECT sr.request_id, sr.session_date, sr.subject, sr.status,
                   u.user_id AS mentee_id, u.firstname, u.lastname
            FROM session_requests sr JOIN users u ON u.user_id = sr.mentee_id
            WHERE sr.mentor_id = ? AND DATE(sr.session_date) = CURDATE()
              AND sr.status IN ('approved','completed')
            ORDER BY sr.session_date ASC
        ", 'i', [$mentorId]);
    }

    /**
     * Mentee Progress: up to $limit mentees with an approved or completed
     * session, most completed sessions first, each with 'done' (completed
     * sessions), a 'subject', and 'goals_total' / 'goals_done' for this pair.
     */
    public static function menteeProgressForMentor(mysqli $con, int $mentorId, int $limit): array
    {
        return self::typedRows($con, "
            SELECT u.user_id, u.firstname, u.lastname,
                   SUM(sr.status = 'completed')                        AS done,
                   MAX(sr.subject)                                     AS subject,
                   (SELECT COUNT(*) FROM goals g
                     WHERE g.mentee_id = u.user_id AND g.mentor_id = ?) AS goals_total,
                   (SELECT COUNT(*) FROM goals g
                     WHERE g.mentee_id = u.user_id AND g.mentor_id = ?
                       AND g.status = 'completed')                      AS goals_done
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentee_id
            WHERE sr.mentor_id = ? AND sr.status IN ('approved','completed')
            GROUP BY u.user_id, u.firstname, u.lastname
            ORDER BY done DESC, u.firstname ASC
            LIMIT ?
        ", 'iiii', [$mentorId, $mentorId, $mentorId, $limit]);
    }

    /**
     * Active Mentees cards: up to $limit mentees with an approved or completed
     * session, those with something upcoming first, each with their photo,
     * 'since_on' (first session), 'done', 'upcoming', 'next_id' (the upcoming
     * session with the lowest id) and 'their_rating' (their average rating of
     * this mentor, null when none).
     */
    public static function activeMenteesForMentor(mysqli $con, int $mentorId, int $limit): array
    {
        return self::typedRows($con, "
            SELECT u.user_id, u.firstname, u.lastname, pr.profile_image,
                   MIN(sr.session_date)                                   AS since_on,
                   SUM(sr.status = 'completed')                           AS done,
                   SUM(" . self::upcomingCondition('sr') . ") AS upcoming,
                   (SELECT MIN(sr2.request_id) FROM session_requests sr2
                     WHERE sr2.mentor_id = sr.mentor_id AND sr2.mentee_id = u.user_id
                       AND " . self::upcomingCondition('sr2') . ") AS next_id,
                   (SELECT ROUND(AVG(f.rating),1) FROM feedback f
                     WHERE f.mentor_id = sr.mentor_id AND f.mentee_id = u.user_id)  AS their_rating
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentee_id
            LEFT JOIN profile pr ON pr.user_id = u.user_id
            WHERE sr.mentor_id = ? AND sr.status IN ('approved','completed')
            GROUP BY u.user_id, u.firstname, u.lastname, pr.profile_image, sr.mentor_id
            ORDER BY upcoming DESC, since_on DESC
            LIMIT ?
        ", 'ii', [$mentorId, $limit]);
    }

    // ── The mentor calendar ─────────────────────────────────────────────────

    /** Every approved session of the mentor with the mentee's first and last name, in no particular order. */
    public static function approvedWithMenteeForMentor(mysqli $con, int $mentorId): array
    {
        return self::rows($con, "
            SELECT sr.session_date, sr.subject, u.firstname, u.lastname
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentee_id
            WHERE sr.mentor_id = ? AND sr.status = 'approved'
        ", 'i', [$mentorId]);
    }

    // ── A mentor's session lists ────────────────────────────────────────────

    /** How many of the mentor's sessions are in any of $statuses. */
    public static function countForMentorInStatuses(mysqli $con, int $mentorId, array $statuses): int
    {
        if (!$statuses) {
            return 0;
        }
        return (int)self::value($con, "
            SELECT COUNT(*) FROM session_requests WHERE mentor_id = ? AND status IN (" . self::marks($statuses) . ")
        ", 'i' . str_repeat('s', count($statuses)), array_merge([$mentorId], array_values($statuses)));
    }

    /**
     * The mentor Upcoming tab: every approved one-to-one session, soonest
     * first, with the mentee's name, email and course and the slot's type and
     * length. A session with no matching slot counts as one-to-one. There is no
     * date limit — an approved session stays listed after its start time until
     * the missed-session job closes it, so its call can still be joined.
     */
    public static function approvedOneToOneForMentor(mysqli $con, int $mentorId): array
    {
        return self::rows($con, "
            SELECT sr.*, u.firstname, u.lastname,
                   u.email,
                   p.course,
                   a.session_type, a.duration
            FROM session_requests sr
            JOIN users u        ON sr.mentee_id = u.user_id
            LEFT JOIN profile p ON p.user_id = u.user_id
            " . self::slotJoin('sr', 'a') . "
            WHERE sr.mentor_id = ?
              AND sr.status    = 'approved'
              AND (a.session_type = '1v1' OR a.session_type IS NULL)
            GROUP BY sr.request_id
            ORDER BY sr.session_date ASC
        ", 'i', [$mentorId]);
    }

    /** One page of the mentor's completed sessions, newest first, with the mentee's name, email and course. */
    public static function completedForMentor(mysqli $con, int $mentorId, int $limit, int $offset): array
    {
        return self::typedRows($con, "
            SELECT sr.request_id, sr.mentee_id, sr.subject, sr.message, sr.session_date, sr.completed_at,
                   u.firstname, u.lastname,
                   u.email,
                   p.course
            FROM session_requests sr
            JOIN users u        ON u.user_id = sr.mentee_id
            LEFT JOIN profile p ON p.user_id = u.user_id
            WHERE sr.mentor_id = ?
              AND sr.status = 'completed'
            GROUP BY sr.request_id
            ORDER BY sr.session_date DESC
            LIMIT ? OFFSET ?
        ", 'iii', [$mentorId, $limit, $offset]);
    }

    /**
     * One page of the mentor History tab — sessions that did not happen
     * (declined, cancelled or missed) — newest first, with the mentee's name,
     * email and course.
     */
    public static function historyForMentor(mysqli $con, int $mentorId, int $limit, int $offset): array
    {
        return self::typedRows($con, "
            SELECT sr.*, u.firstname, u.lastname,
                   u.email,
                   p.course
            FROM session_requests sr
            JOIN users u        ON sr.mentee_id = u.user_id
            LEFT JOIN profile p ON p.user_id = u.user_id
            WHERE sr.mentor_id = ?
              AND sr.status IN ('rejected','cancelled','missed')
            GROUP BY sr.request_id
            ORDER BY sr.session_date DESC
            LIMIT ? OFFSET ?
        ", 'iii', [$mentorId, $limit, $offset]);
    }

    // ── A group slot's reservations ─────────────────────────────────────────
    // A reservation belongs to a slot by (mentor, subject, date, start time);
    // session_requests has no availability_id. $date is 'Y-m-d', $startTime 'H:i:s'.

    /** The lowest request id among the slot's approved reservations — the one the group call is opened with — or 0 when none is approved. */
    public static function firstApprovedIdInSlot(mysqli $con, int $mentorId, string $subject, string $date, string $startTime): int
    {
        return (int)self::value($con, "
            SELECT request_id FROM session_requests
            WHERE mentor_id = ?
              AND subject    = ?
              AND DATE(session_date) = ?
              AND TIME(session_date) = ?
              AND status = 'approved'
            ORDER BY request_id ASC LIMIT 1
        ", 'isss', [$mentorId, $subject, $date, $startTime]);
    }

    /**
     * How many pending, approved or completed sessions belong to the slot. A
     * slot with any is booked: its date, time and subject have to stay as they
     * are, or those sessions would no longer match it.
     */
    public static function countLiveInSlot(mysqli $con, int $mentorId, string $subject, string $date, string $startTime): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) FROM session_requests
            WHERE mentor_id = ? AND DATE(session_date) = ? AND TIME(session_date) = ?
              AND subject = ? AND status IN ('pending','approved','completed')
        ", 'isss', [$mentorId, $date, $startTime, $subject]);
    }

    /** The slot's pending and approved reservations with each mentee's name. */
    public static function openReservationsInSlot(mysqli $con, int $mentorId, string $subject, string $date, string $startTime): array
    {
        return self::rows($con, "
            SELECT u.firstname, u.lastname, sr.status, sr.session_date, sr.request_id
            FROM session_requests sr
            JOIN users u ON sr.mentee_id = u.user_id
            WHERE sr.mentor_id = ?
              AND sr.subject        = ?
              AND DATE(sr.session_date) = ?
              AND TIME(sr.session_date) = ?
              AND sr.status IN ('pending','approved')
            ORDER BY sr.session_date ASC
        ", 'isss', [$mentorId, $subject, $date, $startTime]);
    }
}
