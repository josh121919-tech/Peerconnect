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
     * NOT YET SETTLED: the admin screens (AdminSessionRepository) use
     * "session_date > NOW()" rather than ">=", and neither version counts a session that has started
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
     * Every session of the mentee with the mentor's first and last name, the
     * slot's type and length, and when the session ends. With $status, only
     * sessions in exactly that status (an unknown status simply matches
     * nothing). $newestFirst orders by session date; without it the rows come
     * in the database's own order.
     *
     * The slot columns were missing, so Session History could not say when a
     * session finished — the end time it tried to print was always blank —
     * and could not tell a session that was over from one still running, so
     * it offered to rejoin calls that had ended hours before.
     */
    public static function withMentorForMentee(mysqli $con, int $menteeId, ?string $status = null, bool $newestFirst = true): array
    {
        $sql   = "SELECT sr.*, u.firstname, u.lastname,
                         a.session_type, a.capacity,
                         COALESCE(a.duration, 60) AS duration,
                         " . self::endsAtExpr() . " AS session_end,
                         NOW() >= " . self::endsAtExpr() . " AS has_ended
                  FROM session_requests sr
                  JOIN users u ON sr.mentor_id = u.user_id
                  " . self::slotJoin('sr', 'a') . "
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
            SELECT sr.request_id, sr.subject, sr.session_date, sr.status, u.firstname, u.lastname,
                   pr.profile_image, a.session_type, a.duration, a.topics
            FROM session_requests sr
            JOIN users u ON sr.mentor_id = u.user_id
            LEFT JOIN profile pr ON pr.user_id = u.user_id
            " . self::slotJoin('sr', 'a') . "
            WHERE sr.mentee_id = ?
              AND sr.status IN ('approved','unfinished')
              AND DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) >= NOW()
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
            " . self::slotJoin('sr', 'a') . "
            LEFT JOIN feedback f ON f.session_id = sr.request_id AND f.mentee_id = sr.mentee_id
            WHERE sr.mentee_id = ?
              AND (
                  sr.status IN ('completed','rejected','cancelled','missed')
                  OR (sr.status = 'unfinished'
                      AND DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) < NOW())
              )
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
              AND sr.status IN ('pending', 'approved', 'unfinished', 'completed')
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
              AND sr.status IN ('pending', 'approved', 'unfinished')
              AND DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) >= NOW()
            ORDER BY sr.session_date ASC
            LIMIT ?
        ", 'ii', [$menteeId, $limit]);
    }

    // ── Booking ─────────────────────────────────────────────────────────────
    // $datetime is a session's start, 'Y-m-d H:i:s'. A live request is one that
    // is pending or approved.

    /** True when the mentee already has a live request with this mentor starting at $datetime. */
    public static function menteeHasLiveRequestAt(mysqli $con, int $mentorId, int $menteeId, string $datetime): bool
    {
        return self::value($con, "
            SELECT 1 FROM session_requests
            WHERE mentor_id = ?
            AND mentee_id = ?
            AND session_date = ?
            AND status IN ('pending','approved')
        ", 'iis', [$mentorId, $menteeId, $datetime]) !== null;
    }

    /**
     * The mentee's bookings dated within the last seven days or later, for the
     * weekly booking cap: pending, approved and completed ones count.
     */
    public static function countForWeeklyCap(mysqli $con, int $menteeId): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) c FROM session_requests
            WHERE mentee_id = ? AND status IN ('pending','approved','completed')
              AND session_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ", 'i', [$menteeId]);
    }

    /** True when $datetime is now or earlier, by the database's clock (the same one NOW() uses everywhere else). */
    public static function hasStarted(mysqli $con, string $datetime): bool
    {
        return (int)self::value($con, "SELECT ? <= NOW() AS is_past", 's', [$datetime]) === 1;
    }

    /**
     * The first of the mentee's live sessions on $date that overlaps a session
     * from $datetime lasting $minutes — 'session_date', 'subject', 'dur' and
     * 'mentor_name' — or null. An existing session lasts as long as its slot,
     * or 60 minutes when it has none.
     */
    public static function firstClashForMentee(mysqli $con, int $menteeId, string $date, string $datetime, int $minutes): ?array
    {
        return self::typedRow($con, "
            SELECT sr.session_date,
                   sr.subject,
                   COALESCE(a.duration, 60) AS dur,
                   CONCAT(u.firstname, ' ', u.lastname) AS mentor_name
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentor_id
            LEFT JOIN availability a
                   ON  a.mentor_id        = sr.mentor_id
                   AND LOWER(a.subject)   = LOWER(sr.subject)
                   AND a.date             = DATE(sr.session_date)
                   AND a.start_time       = TIME(sr.session_date)
            WHERE sr.mentee_id = ?
              AND sr.status IN ('pending','approved')
              AND DATE(sr.session_date) = ?
              AND sr.session_date < DATE_ADD(?, INTERVAL ? MINUTE)
              AND DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) > ?
            ORDER BY sr.session_date
            LIMIT 1
        ", 'issis', [$menteeId, $date, $datetime, $minutes, $datetime]);
    }

    /** True when the mentee already has a live reservation with this mentor, subject (letter case ignored) and start. */
    public static function menteeHasLiveReservation(mysqli $con, int $menteeId, int $mentorId, string $subject, string $datetime): bool
    {
        return self::value($con, "
            SELECT 1
            FROM session_requests
            WHERE mentee_id = ?
              AND mentor_id = ?
              AND LOWER(subject) = LOWER(?)
              AND session_date = ?
              AND status IN ('pending', 'approved')
            LIMIT 1
        ", 'iiss', [$menteeId, $mentorId, $subject, $datetime]) !== null;
    }

    /** How many live requests the mentor has starting at $datetime, whatever their subject — the seats taken. */
    public static function countLiveForMentorAt(mysqli $con, int $mentorId, string $datetime): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) as total
            FROM session_requests
            WHERE mentor_id = ?
            AND session_date = ?
            AND status IN ('pending','approved')
        ", 'is', [$mentorId, $datetime]);
    }

    /**
     * Saves a new pending request. Throws mysqli_sql_exception on failure,
     * including code 1062 on a database that still has the old
     * one-request-per-mentor-mentee-time key.
     */
    public static function createPendingRequest(mysqli $con, int $mentorId, int $menteeId, string $subject, string $datetime, string $message): int
    {
        return self::execute($con, "
            INSERT INTO session_requests (
                mentor_id, mentee_id, subject, session_date, message, status
            ) VALUES (
                ?, ?, ?, ?, ?, 'pending'
            )
        ", 'iisss', [$mentorId, $menteeId, $subject, $datetime, $message]);
    }

    /**
     * The mentor profile's session figures: 'total_sessions' (completed) and
     * 'mentees' (different mentees those sessions were with).
     */
    public static function completedSummaryForMentor(mysqli $con, int $mentorId): array
    {
        return self::row($con, "
            SELECT COUNT(*) AS total_sessions, COUNT(DISTINCT mentee_id) AS mentees
            FROM session_requests
            WHERE mentor_id = ? AND status = 'completed'
        ", 'i', [$mentorId]) ?? ['total_sessions' => '0', 'mentees' => '0'];
    }

    // ── Video calls and calendar export ─────────────────────────────────────
    // "Participant" means the session's mentor or its mentee; anyone else gets null.

    /**
     * One session for the lobby or the call room, with every session column,
     * 'mentor_fname', 'mentor_lname', 'mentee_fname', 'mentee_lname' and
     * 'duration': its slot's length, or 30 minutes when it has no slot (the
     * length the call room has always used). With $approvedOnly, null unless
     * the session is approved.
     */
    public static function forParticipantWithLength(mysqli $con, int $sessionId, int $userId, bool $approvedOnly): ?array
    {
        return self::typedRow($con, "
            SELECT sr.*,
                   mentor.firstname AS mentor_fname, mentor.lastname AS mentor_lname,
                   mentee.firstname AS mentee_fname, mentee.lastname AS mentee_lname,
                   COALESCE(a.duration, 30) AS duration
            FROM session_requests sr
            JOIN users mentor ON sr.mentor_id = mentor.user_id
            JOIN users mentee ON sr.mentee_id = mentee.user_id
            LEFT JOIN availability a
                ON  a.mentor_id   = sr.mentor_id
                AND a.subject     = sr.subject
                AND DATE(a.date)  = DATE(sr.session_date)
                AND TIME(a.start_time) = TIME(sr.session_date)
            WHERE sr.request_id = ?
              AND (sr.mentor_id = ? OR sr.mentee_id = ?)" . ($approvedOnly ? "
              AND sr.status IN ('approved','unfinished')" : ""), 'iii', [$sessionId, $userId, $userId]);
    }

    /**
     * The pending and approved reservations of the slot a session belongs to
     * (same mentor, subject, date and start as $sessionDate), by first name:
     * 'firstname', 'lastname', 'status', 'request_id'.
     */
    public static function openReservationsInSlotByName(mysqli $con, int $mentorId, string $subject, string $sessionDate): array
    {
        return self::typedRows($con, "
            SELECT u.firstname, u.lastname, sr.status, sr.request_id
            FROM session_requests sr
            JOIN users u ON sr.mentee_id = u.user_id
            WHERE sr.mentor_id = ?
              AND sr.subject   = ?
              AND DATE(sr.session_date) = DATE(?)
              AND TIME(sr.session_date) = TIME(?)
              AND sr.status IN ('pending', 'approved', 'unfinished')
            ORDER BY u.firstname ASC
        ", 'isss', [$mentorId, $subject, $sessionDate, $sessionDate]);
    }

    /**
     * Records that $userId opened the call for $sessionId.
     *
     * joined_at keeps the first visit, so re-opening does not rewrite when
     * they first arrived. left_at is cleared, because opening the room is
     * exactly what undoes having left it — that is what makes rejoining work.
     */
    public static function recordAttendance(mysqli $con, int $sessionId, int $userId, string $role): void
    {
        self::execute($con, "
            INSERT INTO session_attendance (session_id, user_id, role, joined_at, left_at)
            VALUES (?, ?, ?, NOW(), NULL)
            ON DUPLICATE KEY UPDATE left_at = NULL
        ", 'iis', [$sessionId, $userId, $role]);
    }


    // ── Presence ────────────────────────────────────────────────────────────
    // session_attendance answers "did you ever open this call". These answer
    // "when were you in it": one row per visit, so leaving and coming back
    // leaves two rows with a gap between them that earns nothing.

    /** Two missed heartbeats. Below this an open row is the same visit; above it the visit ended and nobody said so. */
    public const PRESENCE_STALE_SECONDS = 75;

    /**
     * "I am in this call right now" — both the arrival and every heartbeat
     * after it, because they are the same statement and one of them getting
     * its own method is how the two would come to disagree.
     *
     * A refresh or a second tab must not start a second interval, or the same
     * minutes are credited twice. But an interval whose heartbeat stopped is a
     * visit that ended without a goodbye — a closed laptop, a dropped line —
     * and the silence since must not be credited either. That one is closed at
     * its last heartbeat and a new interval begins here, which is what makes a
     * sleeping machine cost the sleeper the time it slept.
     */
    public static function openPresence(mysqli $con, int $sessionId, int $userId, string $role): void
    {
        $open = self::typedRow($con, "
            SELECT presence_id, TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS silent_for
            FROM session_presence
            WHERE session_id = ? AND user_id = ? AND left_at IS NULL
            ORDER BY presence_id DESC LIMIT 1
        ", 'ii', [$sessionId, $userId]);

        if ($open && (int)$open['silent_for'] <= self::PRESENCE_STALE_SECONDS) {
            self::execute($con, "UPDATE session_presence SET last_seen_at = NOW() WHERE presence_id = ?",
                'i', [(int)$open['presence_id']]);
            return;
        }

        if ($open) {
            self::execute($con, "UPDATE session_presence SET left_at = last_seen_at WHERE presence_id = ?",
                'i', [(int)$open['presence_id']]);
        }

        self::execute($con, "
            INSERT INTO session_presence (session_id, user_id, role, joined_at, last_seen_at, left_at)
            VALUES (?, ?, ?, NOW(), NOW(), NULL)
        ", 'iis', [$sessionId, $userId, $role]);
    }

    /** Closes the open interval. Nothing to do when there is not one. */
    public static function closePresence(mysqli $con, int $sessionId, int $userId): void
    {
        self::execute($con, "
            UPDATE session_presence SET left_at = NOW(), last_seen_at = NOW()
            WHERE session_id = ? AND user_id = ? AND left_at IS NULL
        ", 'ii', [$sessionId, $userId]);
    }

    /**
     * Records that $userId left the call for $sessionId, keeping the latest
     * departure. Does nothing when they were never recorded as joining: a
     * "left" with no "joined" is not something the call can produce.
     */
    public static function recordLeave(mysqli $con, int $sessionId, int $userId): void
    {
        self::execute($con, "UPDATE session_attendance SET left_at = NOW() WHERE session_id = ? AND user_id = ?",
            'ii', [$sessionId, $userId]);
    }

    /**
     * Whether both people of this booking are in the call right now — an
     * attendance row each, neither carrying a departure.
     *
     * In a group the mentor's attendance may sit on a different booking of
     * the same slot, because whichever one carried the link they opened is
     * where room.php recorded them. So the mentor is looked for across the
     * slot, exactly as settleAtEnd() and the missed-session job do.
     */
    public static function bothPresentNow(mysqli $con, int $sessionId): bool
    {
        return self::value($con, "
            SELECT EXISTS (
                       SELECT 1 FROM session_attendance att
                       JOIN session_requests s2 ON s2.request_id = att.session_id
                       WHERE att.user_id = sr.mentor_id
                         AND att.left_at IS NULL
                         AND s2.mentor_id    = sr.mentor_id
                         AND s2.subject      = sr.subject
                         AND s2.session_date = sr.session_date)
               AND EXISTS (
                       SELECT 1 FROM session_attendance att
                       WHERE att.session_id = sr.request_id
                         AND att.user_id    = sr.mentee_id
                         AND att.left_at IS NULL)
            FROM session_requests sr WHERE sr.request_id = ?
        ", 'i', [$sessionId]) === '1';
    }

    /**
     * Sets a running booking to 'approved' while both are in the call and
     * 'unfinished' while either is out, and returns the status it settled on.
     *
     * Only touches a booking that is already in one of those two states, so
     * it can never reopen something cancelled, completed or missed. The
     * caller decides when to ask; this only answers "who is in there now".
     */
    public static function refreshLiveStatus(mysqli $con, int $sessionId): string
    {
        $status = self::bothPresentNow($con, $sessionId) ? 'approved' : 'unfinished';

        self::execute($con, "
            UPDATE session_requests SET status = ?
            WHERE request_id = ? AND status IN ('approved','unfinished')
        ", 'si', [$status, $sessionId]);

        return $status;
    }


    /** The share of a session you have to be present for it to count as completed. */
    public const MIN_ATTENDANCE_FRACTION = 0.70;

    /**
     * How long each person was actually in a session, in seconds.
     *
     * The clock runs for you while you are in the room, the mentor is in the
     * room, and at least one mentee is too. That one sentence covers both
     * shapes: in a 1-on-1 "the mentor and a mentee" is simply both of you, so
     * sitting alone waiting earns nothing; in a group one mentee stepping out
     * does not stop anybody else's clock.
     *
     * Time before the scheduled start is not credited — the call opens early
     * on purpose, and arriving early is not attending. Time after the end is
     * not credited either.
     *
     * A pure function over intervals so it can be tested without a database:
     * every awkward case (leaving and coming back, the mentor dropping out,
     * a browser that died mid-call) is a list of intervals and nothing more.
     *
     * @param array $intervals each ['user' => int, 'role' => 'mentor'|'mentee',
     *                               'from' => timestamp, 'to' => timestamp]
     * @return array<int,int> user id => seconds credited
     */
    public static function creditPresence(array $intervals, int $startTs, int $endTs): array
    {
        /*
         * Everyone with an interval starts on zero, even one that fell wholly
         * outside the session. Callers read an empty result as "presence was
         * never recorded here, use the old rule" — so someone who turned up
         * and earned nothing has to come back as 0, not as missing, or an
         * ordinary no-show would take the fallback path instead.
         */
        $seconds = [];
        foreach ($intervals as $iv) {
            $seconds[(int)$iv['user']] = 0;
        }

        $events = [];
        foreach ($intervals as $iv) {
            $from = max((int)$iv['from'], $startTs);
            $to   = min((int)$iv['to'],   $endTs);
            if ($to <= $from) {
                continue;               // entirely outside the scheduled window
            }
            $events[] = ['at' => $from, 'delta' => 1,  'user' => (int)$iv['user'], 'role' => $iv['role']];
            $events[] = ['at' => $to,   'delta' => -1, 'user' => (int)$iv['user'], 'role' => $iv['role']];
        }
        if (!$events) {
            return $seconds;
        }

        usort($events, fn($a, $b) => $a['at'] <=> $b['at']);

        $inRoom  = [];   // user => how many open intervals they have
        $roleOf  = [];   // user => 'mentor' | 'mentee'
        $prev    = null;

        foreach ($events as $i => $e) {
            // Credit the stretch that just ended, before this event changes
            // who is in the room.
            if ($prev !== null && $e['at'] > $prev) {
                $mentors = 0;
                $mentees = 0;
                foreach ($inRoom as $uid => $n) {
                    if ($n <= 0) continue;
                    if ($roleOf[$uid] === 'mentor') { $mentors++; } else { $mentees++; }
                }
                if ($mentors > 0 && $mentees > 0) {
                    $span = $e['at'] - $prev;
                    foreach ($inRoom as $uid => $n) {
                        if ($n > 0) {
                            $seconds[$uid] += $span;
                        }
                    }
                }
            }

            // A counter rather than a flag: overlapping intervals for one
            // person (a second tab) must not have the first close to end them.
            $roleOf[$e['user']] = $e['role'];
            $inRoom[$e['user']] = ($inRoom[$e['user']] ?? 0) + $e['delta'];
            $prev = $e['at'];
        }

        return $seconds;
    }


    /**
     * Every booking of this slot, whatever became of each one.
     *
     * bookingIdsInSlotOf() deliberately returns only the live ones, which is
     * right for opening a call. It is wrong for reading presence: in a group
     * the bookings settle one at a time, and the mentor's intervals sit on
     * whichever booking's room link they opened. Once that one has settled,
     * a live-only lookup would drop the mentor from the sweep entirely and
     * every mentee still waiting would be credited nothing.
     */
    private static function everyBookingIdInSlotOf(mysqli $con, int $sessionId): array
    {
        $ids = [];
        foreach (self::typedRows($con, "
            SELECT s2.request_id
            FROM session_requests sr
            JOIN session_requests s2
              ON s2.mentor_id    = sr.mentor_id
             AND s2.subject      = sr.subject
             AND s2.session_date = sr.session_date
            WHERE sr.request_id = ?
        ", 'i', [$sessionId]) as $r) {
            $ids[] = (int)$r['request_id'];
        }
        return $ids;
    }

    /**
     * Seconds credited to each person across the whole slot this booking
     * belongs to — in a group the mentor and every mentee share one room, so
     * the whole slot has to be read to know who was in it together.
     *
     * Returns [] when nothing was recorded, which is how a session that ran
     * before presence logging existed is told apart from one where nobody
     * turned up. settleAtEnd() uses that to fall back rather than mark every
     * old session unfinished.
     */
    public static function presenceSecondsForSlotOf(mysqli $con, int $sessionId): array
    {
        $slot = self::typedRow($con, "
            SELECT sr.session_date,
                   " . self::endsAtExpr() . " AS ends_at
            FROM session_requests sr
            " . self::slotJoin('sr', 'a') . "
            WHERE sr.request_id = ?
        ", 'i', [$sessionId]);

        if (!$slot) {
            return [];
        }

        $ids = self::everyBookingIdInSlotOf($con, $sessionId);
        if (!$ids) {
            return [];
        }

        $rows = self::typedRows($con, "
            SELECT user_id, role,
                   UNIX_TIMESTAMP(joined_at) AS from_ts,
                   UNIX_TIMESTAMP(COALESCE(left_at, last_seen_at)) AS to_ts
            FROM session_presence
            WHERE session_id IN (" . self::marks($ids) . ")
        ", str_repeat('i', count($ids)), $ids);

        if (!$rows) {
            return [];
        }

        $intervals = array_map(fn($r) => [
            'user' => (int)$r['user_id'],
            'role' => $r['role'],
            'from' => (int)$r['from_ts'],
            'to'   => (int)$r['to_ts'],
        ], $rows);

        return self::creditPresence(
            $intervals,
            strtotime((string)$slot['session_date']),
            strtotime((string)$slot['ends_at'])
        );
    }

    /**
     * What a finished session amounts to, from who turned up and who was
     * still there at the end.
     *
     * Two callers close sessions — the call, when somebody leaves at or after
     * the end, and the 30-minute job, for the ones nobody left properly — and
     * they must not reach different verdicts about the same session. So the
     * rule is written once, here, and both read it.
     *
     * Returns 'completed', 'unfinished', or which side missed it entirely:
     * 'both', 'mentor' or 'mentee', keeping the vocabulary the missed-session
     * job and the missed_by column already use.
     *
     * $mentorStayed and $menteeStayed mean "was there long enough for this to
     * count", which outcomeForEnded() works out from recorded presence. This
     * only reads the four answers; it does not decide them.
     */
    public static function outcomeFrom(bool $mentorCame, bool $menteeCame, bool $mentorStayed, bool $menteeStayed): string
    {
        if (!$mentorCame && !$menteeCame) { return 'both'; }
        if (!$mentorCame)                 { return 'mentor'; }
        if (!$menteeCame)                 { return 'mentee'; }

        // Both turned up. Whether it finished is a different question.
        return ($mentorStayed && $menteeStayed) ? 'completed' : 'unfinished';
    }


    /**
     * What a session that has ended amounts to, for one booking.
     *
     * Two callers close sessions and they must not disagree: the call, when
     * somebody leaves at or after the end, and the 30-minute job, for the ones
     * nobody left properly. So the rule is written once, here.
     *
     * Where presence was recorded, "stayed" means present for at least
     * MIN_ATTENDANCE_FRACTION of the session — the clock having run only while
     * the mentor and a mentee were in the room together.
     *
     * Where it was not, the session ran before presence logging existed, and
     * the old rule is used instead: still in the call when the end came. The
     * alternative would be to mark every session that ever ran as unfinished
     * the moment this shipped.
     */
    public static function outcomeForEnded(
        mysqli $con,
        int $sessionId,
        int $mentorId,
        int $menteeId,
        int $durationMinutes,
        bool $mentorCame,
        bool $menteeCame,
        bool $legacyMentorStayed,
        bool $legacyMenteeStayed
    ): string {
        $seconds = self::presenceSecondsForSlotOf($con, $sessionId);

        if (!$seconds) {
            return self::outcomeFrom($mentorCame, $menteeCame, $legacyMentorStayed, $legacyMenteeStayed);
        }

        $needed = ($durationMinutes > 0 ? $durationMinutes : 60) * 60 * self::MIN_ATTENDANCE_FRACTION;

        return self::outcomeFrom(
            $mentorCame,
            $menteeCame,
            ($seconds[$mentorId] ?? 0) >= $needed,
            ($seconds[$menteeId] ?? 0) >= $needed
        );
    }

    /**
     * Closes a booking whose scheduled end has passed, and returns the status
     * it settled on — or null when the session has not ended yet, or is not
     * one this can close.
     *
     * There is one definition of the outcome and it lives here, because two
     * callers need it and they must not disagree: the call itself, when
     * somebody leaves at or after the end, and the 30-minute job, for the
     * sessions nobody bothered to leave properly.
     *
     *   nobody ever joined                  -> missed   (what it always meant)
     *   both present for enough of it       -> completed
     *   anything else                       -> unfinished
     *
     * "Enough of it" is MIN_ATTENDANCE_FRACTION of the slot, measured from
     * recorded presence — see outcomeForEnded(), which also holds the rule
     * for sessions that predate that recording.
     *
     * "Still in at the end" is left_at IS NULL — in the call, or never left —
     * or a departure at or after the scheduled end, which is simply leaving
     * when it finished. A booking is one mentee, so in a group each mentee
     * settles on their own; a 1-on-1 has the single row, so one person
     * walking out early leaves it unfinished for both.
     */
    public static function settleAtEnd(mysqli $con, int $sessionId): ?string
    {
        $row = self::typedRow($con, "
            SELECT sr.request_id, sr.mentor_id, sr.mentee_id, sr.subject, sr.session_date, sr.status,
                   COALESCE(a.duration, 60) AS duration,
                   DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) AS ends_at,
                   NOW() >= DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) AS is_over,
                   EXISTS (SELECT 1 FROM session_attendance att
                           JOIN session_requests s2 ON s2.request_id = att.session_id
                           WHERE att.user_id = sr.mentor_id
                             AND s2.mentor_id = sr.mentor_id AND s2.subject = sr.subject
                             AND s2.session_date = sr.session_date)                      AS mentor_came,
                   EXISTS (SELECT 1 FROM session_attendance att
                           WHERE att.session_id = sr.request_id AND att.user_id = sr.mentee_id) AS mentee_came,
                   EXISTS (SELECT 1 FROM session_attendance att
                           JOIN session_requests s2 ON s2.request_id = att.session_id
                           WHERE att.user_id = sr.mentor_id
                             AND s2.mentor_id = sr.mentor_id AND s2.subject = sr.subject
                             AND s2.session_date = sr.session_date
                             AND (att.left_at IS NULL
                                  OR att.left_at >= DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE)))
                                                                                          AS mentor_stayed,
                   EXISTS (SELECT 1 FROM session_attendance att
                           WHERE att.session_id = sr.request_id AND att.user_id = sr.mentee_id
                             AND (att.left_at IS NULL
                                  OR att.left_at >= DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE)))
                                                                                          AS mentee_stayed
            FROM session_requests sr
            " . self::slotJoin('sr', 'a') . "
            WHERE sr.request_id = ? AND sr.status IN ('approved','unfinished')
        ", 'i', [$sessionId]);

        if (!$row || !(int)$row['is_over']) {
            return null;
        }

        // The *_stayed columns are the old rule, kept as the fallback for
        // sessions that ran before presence was recorded; outcomeForEnded()
        // prefers recorded presence wherever there is any.
        $outcome = self::outcomeForEnded(
            $con,
            $sessionId,
            (int)$row['mentor_id'],
            (int)$row['mentee_id'],
            (int)($row['duration'] ?? 60),
            (bool)(int)$row['mentor_came'],   (bool)(int)$row['mentee_came'],
            (bool)(int)$row['mentor_stayed'], (bool)(int)$row['mentee_stayed']
        );

        // 'both', 'mentor' and 'mentee' all mean nobody or only one of them
        // turned up, which is the missed case; the name says which side.
        $status   = in_array($outcome, ['completed', 'unfinished'], true) ? $outcome : 'missed';
        $missedBy = $status === 'missed' ? $outcome : 'none';

        // completed_at is what the exports and the month-over-month figures
        // read, so it is stamped only on the status that means finished.
        self::execute($con, "
            UPDATE session_requests
               SET status = ?,
                   missed_by = ?,
                   completed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE completed_at END
             WHERE request_id = ? AND status IN ('approved','unfinished')
        ", 'sssi', [$status, $missedBy, $status, $sessionId]);

        return $status;
    }

    /** Every booking of the slot this booking belongs to, its own included. */
    public static function bookingIdsInSlotOf(mysqli $con, int $sessionId): array
    {
        $ids = [];
        foreach (self::typedRows($con, "
            SELECT s2.request_id
            FROM session_requests sr
            JOIN session_requests s2
              ON s2.mentor_id    = sr.mentor_id
             AND s2.subject      = sr.subject
             AND s2.session_date = sr.session_date
            WHERE sr.request_id = ? AND s2.status IN ('approved','unfinished')
        ", 'i', [$sessionId]) as $r) {
            $ids[] = (int)$r['request_id'];
        }
        return $ids;
    }

    /**
     * A session of this participant that its call can still act on: ids,
     * 'session_date', both names and the slot's 'duration' (60 when it has
     * no slot). Null otherwise.
     *
     * 'unfinished' counts as well as 'approved' — that status means somebody
     * walked out of a session that has not reached its end yet, and refusing
     * it here would make leaving irreversible, which is the opposite of what
     * it is for.
     */
    public static function approvedForParticipant(mysqli $con, int $sessionId, int $userId): ?array
    {
        return self::typedRow($con, "
            SELECT sr.request_id, sr.mentor_id, sr.mentee_id, sr.session_date, sr.subject, sr.status,
                   COALESCE(a.duration, 60) AS duration,
                   mentor.firstname AS mentor_fname, mentor.lastname AS mentor_lname,
                   mentee.firstname AS mentee_fname, mentee.lastname AS mentee_lname
            FROM session_requests sr
            JOIN users mentor ON sr.mentor_id = mentor.user_id
            JOIN users mentee ON sr.mentee_id = mentee.user_id
            " . self::slotJoin('sr', 'a') . "
            WHERE sr.request_id = ?
              AND (sr.mentor_id = ? OR sr.mentee_id = ?)
              AND sr.status IN ('approved','unfinished')
        ", 'iii', [$sessionId, $userId, $userId]);
    }



    /**
     * How many different people the user has had a session with that has
     * started: mentees when $asMentor, otherwise mentors. An approved session
     * still ahead does not count.
     */
    public static function countPartnersSoFar(mysqli $con, int $userId, bool $asMentor): int
    {
        $sql = $asMentor
            ? "SELECT COUNT(DISTINCT mentee_id) FROM session_requests WHERE mentor_id = ? AND status IN ('approved','completed') AND session_date <= NOW()"
            : "SELECT COUNT(DISTINCT mentor_id) FROM session_requests WHERE mentee_id = ? AND status IN ('approved','completed') AND session_date <= NOW()";
        return (int)self::value($con, $sql, 'i', [$userId]);
    }

    /*
     * completeByMentor() was here. It set a session completed the moment the
     * mentor left the call, with no reference to when the session was due to
     * end — which is how a session abandoned two minutes in was recorded as
     * finished. settleAtEnd() replaces it and will not close anything before
     * its time. Removed rather than left unused, because the next person to
     * need "mark this completed" would reach for it and reintroduce the bug.
     */

    /**
     * Approved sessions for a calendar file: one ($sessionId) or all of the
     * participant's, soonest first, each with both names and 'duration' — its
     * slot's length, or 60 minutes when it has no slot.
     */
    public static function approvedForExport(mysqli $con, int $userId, ?int $sessionId = null): array
    {
        $select = "
            SELECT sr.request_id, sr.subject, sr.session_date, sr.mentor_id, sr.mentee_id,
                   mentor.firstname AS mentor_fname, mentor.lastname AS mentor_lname,
                   mentee.firstname AS mentee_fname, mentee.lastname AS mentee_lname,
                   COALESCE(a.duration, 60) AS duration
            FROM session_requests sr
            JOIN users mentor ON sr.mentor_id = mentor.user_id
            JOIN users mentee ON sr.mentee_id = mentee.user_id
            LEFT JOIN availability a
                ON  a.mentor_id  = sr.mentor_id
                AND a.subject    = sr.subject
                AND DATE(a.date) = DATE(sr.session_date)
                AND TIME(a.start_time) = TIME(sr.session_date)
        ";
        if ($sessionId) {
            return self::typedRows($con, $select . "
                WHERE sr.request_id = ?
                  AND (sr.mentor_id = ? OR sr.mentee_id = ?)
                  AND sr.status = 'approved'
                LIMIT 1
            ", 'iii', [$sessionId, $userId, $userId]);
        }
        return self::typedRows($con, $select . "
            WHERE (sr.mentor_id = ? OR sr.mentee_id = ?)
              AND sr.status = 'approved'
            ORDER BY sr.session_date ASC
        ", 'ii', [$userId, $userId]);
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
     * accepted, 0 when it is not pending, not theirs, does not exist, or its
     * start time has already passed.
     *
     * A request accepted after its start could never take place: nobody would
     * be in the call, and the missed-session job would record it as missed by
     * both, against the mentor. The job removes such requests instead.
     */
    public static function approveByMentor(mysqli $con, int $sessionId, int $mentorId): int
    {
        return self::execute($con, "
            UPDATE session_requests SET status='approved'
            WHERE request_id=? AND mentor_id=? AND status='pending' AND session_date > NOW()
        ", 'ii', [$sessionId, $mentorId]);
    }

    /**
     * True when $sessionId is one of the mentor's requests, still unanswered,
     * whose start time has passed — the reason an accept was refused.
     */
    public static function isPastRequestForMentor(mysqli $con, int $sessionId, int $mentorId): bool
    {
        return self::value($con, "
            SELECT COUNT(*) FROM session_requests
            WHERE request_id = ? AND mentor_id = ? AND status = 'pending' AND session_date <= NOW()
        ", 'ii', [$sessionId, $mentorId]) !== '0';
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
     * group slot, or calls off the whole slot. Only a pending or approved
     * session can be cancelled; one that has closed is left alone, so a
     * finished session is never rewritten. Returns 1 when it was cancelled.
     *
     * $reason is what the mentee is told and is kept in rejection_reason,
     * which is where a declined request already keeps its explanation: the
     * column is "what the mentor said about this", and the status says which
     * of the two it was. Left null, nothing is written there — removing one
     * student from a group slot has never carried a note.
     */
    public static function cancelByMentor(mysqli $con, int $sessionId, int $mentorId, ?string $reason = null): int
    {
        if ($reason === null) {
            return self::execute($con, "
                UPDATE session_requests SET status = 'cancelled'
                WHERE request_id = ? AND mentor_id = ? AND status IN ('pending','approved')
            ", 'ii', [$sessionId, $mentorId]);
        }

        return self::execute($con, "
            UPDATE session_requests SET status = 'cancelled', rejection_reason = ?
            WHERE request_id = ? AND mentor_id = ? AND status IN ('pending','approved')
        ", 'sii', [$reason, $sessionId, $mentorId]);
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

    /**
     * The mentor's sessions still to run today, soonest first, with the
     * mentee's name and the slot each was booked into: 'session_type' and
     * 'capacity', both NULL when that slot has since been edited or deleted.
     * The dashboard uses them to show the mentees of one group slot as a
     * single session instead of one line per booking.
     *
     * Only what is still to be done. A finished session is not a thing on
     * today's schedule — it was filling the panel with sessions that had
     * already been taught, pushing the one the mentor still had to join
     * below them. Completed ones live on the Completed tab.
     *
     * 'unfinished' stays, because it means somebody stepped out of a session
     * that can still be rejoined — the one case where the mentor most needs
     * to see it.
     *
     * Once a session's time has passed it leaves this panel, whatever its
     * status. A session that ended sat here reading "Unfinished" with a Join
     * button beside it, which is neither today's schedule nor something that
     * can still be done. Its outcome belongs to Completed or History.
     */
    public static function todayForMentor(mysqli $con, int $mentorId): array
    {
        return self::typedRows($con, "
            SELECT sr.request_id, sr.session_date, sr.subject, sr.status,
                   u.user_id AS mentee_id, u.firstname, u.lastname,
                   a.session_type, a.capacity
            FROM session_requests sr JOIN users u ON u.user_id = sr.mentee_id
            " . self::slotJoin('sr', 'a') . "
            WHERE sr.mentor_id = ? AND DATE(sr.session_date) = CURDATE()
              AND sr.status IN ('approved','unfinished')
              AND NOW() < " . self::endsAtExpr() . "
            ORDER BY sr.session_date ASC
        ", 'i', [$mentorId]);
    }

    /**
     * Mentee Progress: up to $limit mentees with an approved or completed
     * session, most completed sessions first, each with 'done' (completed
     * sessions), a 'subject', and how they are scoring on this mentor's own
     * assessments — 'avg_pct', 'assess_count' and 'attempt_count'.
     *
     * 'avg_pct' is NULL, never 0, when there is nothing to average: no
     * submitted attempt, or none carrying any points. Zero is a score a
     * mentee can actually get, so it cannot double as "has not taken one".
     *
     * The average is over every submitted attempt, which is the same figure
     * averageScorePercentForMentee() and the admin analytics produce, so a
     * mentee's percentage here agrees with the one on the Assessments page.
     * Retakes are allowed, so 'attempt_count' can exceed 'assess_count'.
     */
    public static function menteeProgressForMentor(mysqli $con, int $mentorId, int $limit): array
    {
        // Submitted attempts by this mentee on assessments this mentor set.
        $mine = "FROM assessment_attempts t
                 JOIN assessments a ON a.assessment_id = t.assessment_id
                 WHERE t.mentee_id = u.user_id
                   AND t.status = 'submitted'
                   AND a.mentor_id = ?";

        return self::typedRows($con, "
            SELECT u.user_id, u.firstname, u.lastname,
                   SUM(sr.status = 'completed')                        AS done,
                   MAX(sr.subject)                                     AS subject,
                   (SELECT ROUND(AVG(t.score / NULLIF(t.total_points, 0) * 100))
                      $mine)                                           AS avg_pct,
                   (SELECT COUNT(DISTINCT t.assessment_id) $mine)      AS assess_count,
                   (SELECT COUNT(*) $mine)                             AS attempt_count
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentee_id
            WHERE sr.mentor_id = ? AND sr.status IN ('approved','completed')
            GROUP BY u.user_id, u.firstname, u.lastname
            ORDER BY done DESC, u.firstname ASC
            LIMIT ?
        ", 'iiiii', [$mentorId, $mentorId, $mentorId, $mentorId, $limit]);
    }

    /**
     * Active Mentees cards: up to $limit mentees with an approved or completed
     * session, those with something upcoming first, each with their photo,
     * 'since_on' (first session), 'done', 'upcoming' and 'their_rating'
     * (their average rating of this mentor, null when none).
     */
    public static function activeMenteesForMentor(mysqli $con, int $mentorId, int $limit): array
    {
        return self::typedRows($con, "
            SELECT u.user_id, u.firstname, u.lastname, pr.profile_image,
                   MIN(sr.session_date)                                   AS since_on,
                   SUM(sr.status = 'completed')                           AS done,
                   SUM(" . self::upcomingCondition('sr') . ") AS upcoming,
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
     * How long before a session starts its call opens, in minutes.
     *
     * room.php has always admitted people from 15 minutes before the start.
     * The mentor's group list used its own 10, so its Join button appeared
     * five minutes after the room would already have let them in. One
     * constant now, and it is also the line between Upcoming and Ongoing.
     */
    public const JOIN_WINDOW_MINUTES = 15;

    /** SQL for the moment a session's call opens. */
    private static function opensAtExpr(string $sr = 'sr'): string
    {
        return "DATE_SUB($sr.session_date, INTERVAL " . self::JOIN_WINDOW_MINUTES . " MINUTE)";
    }

    /** SQL for the moment a session is over. 60 minutes when it has no slot. */
    private static function endsAtExpr(string $sr = 'sr', string $a = 'a'): string
    {
        return "DATE_ADD($sr.session_date, INTERVAL COALESCE($a.duration, 60) MINUTE)";
    }

    /** Everything a mentor's session card and its detail panel need. */
    private static function mentorSessionSelect(): string
    {
        return "SELECT sr.*, u.firstname, u.lastname,
                       u.email,
                       p.course,
                       a.session_type, a.duration, a.capacity,
                       " . self::endsAtExpr() . " AS ends_at,
                       NOW() >= " . self::endsAtExpr() . " AS has_ended
                FROM session_requests sr
                JOIN users u        ON sr.mentee_id = u.user_id
                LEFT JOIN profile p ON p.user_id = u.user_id
                " . self::slotJoin('sr', 'a');
    }

    /**
     * The mentor Upcoming tab: approved sessions whose call has not opened
     * yet, soonest first.
     *
     * Group sessions are included. They used to be filtered out, on the
     * grounds that the Group tab covered them — but the Upcoming *count*
     * above the tabs never made that distinction, so a mentor whose only
     * booking was a group one was shown "1 upcoming session" over an empty
     * list. Rows are one per booking; the tab folds a group slot's bookings
     * into a single card.
     *
     * Once the call opens the session belongs to Ongoing, and once it is
     * closed it belongs to Completed or History. Nothing that has already
     * happened appears here.
     */
    public static function upcomingForMentor(mysqli $con, int $mentorId): array
    {
        return self::rows($con, self::mentorSessionSelect() . "
            WHERE sr.mentor_id = ?
              AND sr.status = 'approved'
              AND NOW() < " . self::opensAtExpr() . "
            GROUP BY sr.request_id
            ORDER BY sr.session_date ASC
        ", 'i', [$mentorId]);
    }

    /**
     * The mentor Ongoing tab: sessions whose call has opened and which have
     * not been closed yet, soonest first.
     *
     * The two statuses take different upper bounds, which is not obvious.
     *
     * 'approved' has none. A session nobody left properly stays approved
     * until the missed-session job closes it, and that job waits an hour past
     * the end and runs every half hour, so there is a gap of up to 90 minutes
     * where the session is over and its outcome is not yet decided. It could
     * still turn out to be 'completed', so it is not filed under History on a
     * guess; it waits here, and the card drops its Join button once
     * 'has_ended' is set.
     *
     * 'unfinished' does have one. That status is not "waiting to be judged" —
     * it is the judgement: somebody left and did not come back. While the
     * slot is still open that can be undone by rejoining, so it belongs here;
     * once the time has passed it is settled and belongs to History. Without
     * the bound a finished unfinished session appeared in both tabs at once,
     * one of them offering to rejoin a session that was over.
     */
    public static function ongoingForMentor(mysqli $con, int $mentorId): array
    {
        return self::rows($con, self::mentorSessionSelect() . "
            WHERE sr.mentor_id = ?
              AND NOW() >= " . self::opensAtExpr() . "
              AND (sr.status = 'approved'
                   OR (sr.status = 'unfinished' AND NOW() < " . self::endsAtExpr() . "))
            GROUP BY sr.request_id
            ORDER BY sr.session_date ASC
        ", 'i', [$mentorId]);
    }

    /** How many sessions are in each of the mentor's tabs, by the same rules. */
    public static function tabCountsForMentor(mysqli $con, int $mentorId): array
    {
        $row = self::row($con, "
            SELECT
                SUM(sr.status = 'pending')                                              AS received,
                SUM(sr.status = 'approved' AND NOW() <  " . self::opensAtExpr() . ")     AS upcoming,
                SUM(NOW() >= " . self::opensAtExpr() . "
                    AND (sr.status = 'approved'
                         OR (sr.status = 'unfinished' AND NOW() < " . self::endsAtExpr() . ")))  AS ongoing,
                SUM(sr.status = 'completed')                                            AS completed,
                SUM(sr.status IN ('rejected','cancelled','missed')
                    OR (sr.status = 'unfinished' AND NOW() >= " . self::endsAtExpr() . ")) AS history
            FROM session_requests sr
            " . self::slotJoin('sr', 'a') . "
            WHERE sr.mentor_id = ?
        ", 'i', [$mentorId]) ?? [];

        foreach (['received', 'upcoming', 'ongoing', 'completed', 'history'] as $k) {
            $row[$k] = (int)($row[$k] ?? 0);
        }

        // Lapsed empty group slots are History cards without a
        // session_requests row, so the query above cannot see them. Left out,
        // the button read 3 over a list of four.
        $row['history'] += count(self::lapsedEmptyGroupSlotsForMentor($con, $mentorId));

        return $row;
    }

    /**
     * Group slots that have been and gone with nobody booked into them.
     *
     * They have no session_requests row, because nobody ever requested one,
     * so they cannot be listed from that table and no row is invented for
     * them. The History list shows them as cancelled — a slot that was
     * offered and lapsed — reading from `availability` as it stands.
     */
    public static function lapsedEmptyGroupSlotsForMentor(mysqli $con, int $mentorId): array
    {
        return self::typedRows($con, "
            SELECT a.availability_id, a.subject, a.topics, a.capacity, a.duration,
                   TIMESTAMP(a.date, a.start_time) AS session_date
            FROM availability a
            WHERE a.mentor_id = ?
              AND a.session_type = 'group'
              AND DATE_ADD(TIMESTAMP(a.date, a.start_time), INTERVAL COALESCE(a.duration, 60) MINUTE) < NOW()
              AND NOT EXISTS (
                  SELECT 1 FROM session_requests sr
                  WHERE sr.mentor_id     = a.mentor_id
                    AND sr.subject       = a.subject
                    AND DATE(sr.session_date) = DATE(a.date)
                    AND TIME(sr.session_date) = TIME(a.start_time)
              )
            ORDER BY session_date DESC
        ", 'i', [$mentorId]);
    }

    /** One page of the mentor's completed sessions, newest first, with the mentee's name, email and course. */
    public static function completedForMentor(mysqli $con, int $mentorId, int $limit, int $offset): array
    {
        return self::typedRows($con, "
            SELECT sr.request_id, sr.mentee_id, sr.subject, sr.message, sr.session_date, sr.completed_at,
                   u.firstname, u.lastname,
                   u.email,
                   p.course,
                   COALESCE(a.duration, 60) AS duration,
                   " . self::endsAtExpr() . " AS session_end
            FROM session_requests sr
            JOIN users u        ON u.user_id = sr.mentee_id
            LEFT JOIN profile p ON p.user_id = u.user_id
            " . self::slotJoin('sr', 'a') . "
            WHERE sr.mentor_id = ?
              AND sr.status = 'completed'
            GROUP BY sr.request_id
            ORDER BY sr.session_date DESC
            LIMIT ? OFFSET ?
        ", 'iii', [$mentorId, $limit, $offset]);
    }

    /**
     * The mentor's History: one page of the sessions that will not be run —
     * declined, cancelled, missed or left unfinished — newest first, with the
     * mentee's name, email and course.
     *
     * 'unfinished' only once the session is actually over. Before that it
     * means somebody stepped out of a call that can still be rejoined, which
     * belongs in Ongoing — listing it here as well would have the same
     * session in two places, one of them claiming it is finished.
     */
    /** The statuses the mentor's History can be filtered to. */
    public const HISTORY_STATUSES = ['rejected', 'cancelled', 'missed', 'unfinished'];

    /** The WHERE fragment for History, optionally narrowed to one status. */
    private static function historyWhere(?string $status): string
    {
        $unfinishedOver = "(sr.status = 'unfinished' AND NOW() >= " . self::endsAtExpr() . ")";

        if ($status === 'unfinished') {
            return $unfinishedOver;
        }
        if ($status !== null && in_array($status, self::HISTORY_STATUSES, true)) {
            // Escaped by the allowlist above, not by interpolation: only the
            // four names this class defines can ever reach here.
            return "sr.status = '" . $status . "'";
        }
        return "(sr.status IN ('rejected','cancelled','missed') OR $unfinishedOver)";
    }

    public static function historyForMentor(mysqli $con, int $mentorId, int $limit, int $offset, ?string $status = null): array
    {
        return self::typedRows($con, "
            SELECT sr.*, u.firstname, u.lastname,
                   u.email,
                   p.course,
                   a.session_type, a.capacity,
                   COALESCE(a.duration, 60) AS duration,
                   " . self::endsAtExpr() . " AS session_end
            FROM session_requests sr
            JOIN users u        ON sr.mentee_id = u.user_id
            LEFT JOIN profile p ON p.user_id = u.user_id
            " . self::slotJoin('sr', 'a') . "
            WHERE sr.mentor_id = ?
              AND " . self::historyWhere($status) . "
            GROUP BY sr.request_id
            ORDER BY sr.session_date DESC
            LIMIT ? OFFSET ?
        ", 'iii', [$mentorId, $limit, $offset]);
    }

    /** How many History rows the mentor has, under the same filter. */
    public static function countHistoryForMentor(mysqli $con, int $mentorId, ?string $status = null): int
    {
        return (int)self::value($con, "
            SELECT COUNT(DISTINCT sr.request_id)
            FROM session_requests sr
            " . self::slotJoin('sr', 'a') . "
            WHERE sr.mentor_id = ?
              AND " . self::historyWhere($status) . "
        ", 'i', [$mentorId]);
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
              AND status IN ('approved','unfinished')
            ORDER BY FIELD(status, 'approved', 'unfinished'), request_id ASC LIMIT 1
        ", 'isss', [$mentorId, $subject, $date, $startTime]);
    }

    /**
     * Who is booked into the slot and still expecting it to happen, with what
     * is needed to tell them it is off: 'request_id', 'mentee_id',
     * 'mentee_name', 'session_date'.
     *
     * Completed sessions are left out, unlike countLiveInSlot(): one that has
     * already happened cannot be called off, and its mentee has nothing to be
     * warned about.
     */
    public static function liveBookingsInSlot(mysqli $con, int $mentorId, string $subject, string $date, string $startTime): array
    {
        return self::typedRows($con, "
            SELECT sr.request_id, sr.mentee_id, sr.session_date,
                   CONCAT(u.firstname, ' ', u.lastname) AS mentee_name
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentee_id
            WHERE sr.mentor_id = ? AND sr.subject = ?
              AND DATE(sr.session_date) = ? AND TIME(sr.session_date) = ?
              AND sr.status IN ('pending','approved')
            ORDER BY sr.request_id
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
        /*
         * Every booking of the slot, whatever became of it — not just the
         * live ones.
         *
         * This used to end in status IN ('pending','approved'), so the moment
         * a session closed its mentees stopped matching and the roster went
         * empty: a group the mentor had just taught showed "No students
         * reserved yet". They were never removed, only filtered out. Who sat
         * in a session is a record, and it has to survive the session.
         *
         * The seat count is a different question and is asked elsewhere
         * (AvailabilityRepository::groupSlotsForMentor), which still counts
         * only pending and approved — a finished slot must not read as full
         * forever, and a cancelled booking must not hold a seat.
         */
        return self::rows($con, "
            SELECT u.firstname, u.lastname, sr.status, sr.session_date, sr.request_id
            FROM session_requests sr
            JOIN users u ON sr.mentee_id = u.user_id
            WHERE sr.mentor_id = ?
              AND sr.subject        = ?
              AND DATE(sr.session_date) = ?
              AND TIME(sr.session_date) = ?
            ORDER BY FIELD(sr.status, 'approved','pending','unfinished','completed','missed','cancelled','rejected'),
                     u.firstname ASC
        ", 'isss', [$mentorId, $subject, $date, $startTime]);
    }

    // ── Admin actions ───────────────────────────────────────────────────────
    //
    // An admin can only cancel. Whether a session was completed or missed is
    // never set by hand: the mentor ending the call, the mentee's feedback and
    // the missed-session job below decide it.

    /**
     * One session with both people's names, for an admin action: 'request_id',
     * 'subject', 'session_date', 'status', 'mentor_id', 'mentee_id',
     * 'mentor_name', 'mentee_name'. Null when there is no such session.
     */
    public static function findWithNames(mysqli $con, int $sessionId): ?array
    {
        return self::typedRow($con, "
            SELECT sr.request_id, sr.subject, sr.session_date, sr.status, sr.mentor_id, sr.mentee_id,
                   CONCAT_WS(' ', mo.firstname, mo.lastname) AS mentor_name,
                   CONCAT_WS(' ', me.firstname, me.lastname) AS mentee_name
            FROM session_requests sr
            JOIN users mo ON mo.user_id = sr.mentor_id
            JOIN users me ON me.user_id = sr.mentee_id
            WHERE sr.request_id = ?
            LIMIT 1
        ", 'i', [$sessionId]);
    }

    /**
     * An admin calls off a pending or accepted session, keeping $reason on it.
     * Returns 1 when it changed; 0 when it was already closed.
     */
    public static function cancelByAdmin(mysqli $con, int $sessionId, string $reason): int
    {
        return self::execute($con, "
            UPDATE session_requests SET status = 'cancelled', rejection_reason = ?
            WHERE request_id = ? AND status IN ('pending','approved')
        ", 'si', [$reason, $sessionId]);
    }

    // ── The missed-session job ──────────────────────────────────────────────

    /**
     * Approved sessions that ended more than $graceMinutes before $now and were
     * never closed, with whether each person opened the call: 'request_id',
     * 'mentor_id', 'mentee_id', 'session_date', 'status', 'duration', 'session_end',
     * 'mentor_name', 'mentee_name', 'subject', 'mentor_joined',
     * 'mentee_joined' (1 or 0).
     *
     * The mentor's join is matched on the slot, not the request: a group
     * session is one call with one request per mentee, and the mentor opens it
     * from whichever of those requests they clicked.
     *
     * The availability join matches the exact slot (subject, date and start
     * time). Matching on the date alone returned one row per slot the mentor
     * offered that day, so a session was processed — and both people told —
     * once per slot. A session with no slot is taken to last 60 minutes.
     */
    public static function dueForMissedCheck(mysqli $con, string $now, int $graceMinutes): array
    {
        return self::typedRows($con, "
            SELECT
                sr.request_id, sr.mentor_id, sr.mentee_id, sr.session_date, sr.status,
                a.duration,
                DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) AS session_end,
                CONCAT(um.firstname,' ',um.lastname) AS mentor_name,
                CONCAT(ue.firstname,' ',ue.lastname) AS mentee_name,
                sr.subject,
                EXISTS (
                    SELECT 1 FROM session_attendance att
                    JOIN session_requests s2 ON s2.request_id = att.session_id
                    WHERE att.user_id = sr.mentor_id
                      AND s2.mentor_id = sr.mentor_id
                      AND s2.subject = sr.subject
                      AND s2.session_date = sr.session_date
                ) AS mentor_joined,
                EXISTS (
                    SELECT 1 FROM session_attendance att
                    WHERE att.session_id = sr.request_id AND att.user_id = sr.mentee_id
                ) AS mentee_joined,
                /*
                 * Whether each was still in the call when it was due to end.
                 * left_at IS NULL is in the call or never left; a departure
                 * at or after the end is just leaving when it finished.
                 */
                EXISTS (
                    SELECT 1 FROM session_attendance att
                    JOIN session_requests s2 ON s2.request_id = att.session_id
                    WHERE att.user_id = sr.mentor_id
                      AND s2.mentor_id = sr.mentor_id
                      AND s2.subject = sr.subject
                      AND s2.session_date = sr.session_date
                      AND (att.left_at IS NULL
                           OR att.left_at >= DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE))
                ) AS mentor_stayed,
                EXISTS (
                    SELECT 1 FROM session_attendance att
                    WHERE att.session_id = sr.request_id AND att.user_id = sr.mentee_id
                      AND (att.left_at IS NULL
                           OR att.left_at >= DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE))
                ) AS mentee_stayed
            FROM session_requests sr
            LEFT JOIN availability a
                ON a.mentor_id = sr.mentor_id
               AND a.subject   = sr.subject
               AND a.date      = DATE(sr.session_date)
               AND a.start_time = TIME(sr.session_date)
            JOIN users um ON um.user_id = sr.mentor_id
            JOIN users ue ON ue.user_id = sr.mentee_id
            WHERE sr.status IN ('approved','unfinished')
              AND DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) < DATE_SUB(?, INTERVAL ? MINUTE)
              AND NOT EXISTS (
                  SELECT 1 FROM missed_session_logs ml WHERE ml.session_id = sr.request_id
              )
        ", 'si', [$now, $graceMinutes]);
    }

    /**
     * Closes an accepted session as completed at $now because both people
     * joined. "AND status" guards against feedback closing it in the meantime.
     * Returns 1 when it changed, else 0.
     */
    public static function closeAsCompleted(mysqli $con, int $sessionId, string $now): int
    {
        return self::execute($con, "
            UPDATE session_requests SET status = 'completed', completed_at = ?, missed_by = 'none'
            WHERE request_id = ? AND status IN ('approved','unfinished')
        ", 'si', [$now, $sessionId]);
    }

    /**
     * Closes an accepted session as unfinished: both people came, at least
     * one left before the end and did not come back before the slot ran out.
     *
     * No completed_at — it did not complete — and missed_by stays 'none',
     * because nobody missed it. They were both there; it just stopped early.
     * That distinction is the point of the status: MentorScoreService counts
     * missed_by against a mentor, and this must not.
     */
    public static function closeAsUnfinished(mysqli $con, int $sessionId): int
    {
        return self::execute($con, "
            UPDATE session_requests SET status = 'unfinished', missed_by = 'none'
            WHERE request_id = ? AND status IN ('approved','unfinished')
        ", 'i', [$sessionId]);
    }

    /**
     * Closes an accepted session as missed by $missedBy at $now. Returns 1
     * when it changed, else 0.
     *
     * 'unfinished' is accepted as well as 'approved', as its two siblings
     * already were. Somebody walking out marks a session unfinished while it
     * is still running, and that is an interim judgement: once the slot has
     * passed, presence may well say one of them never arrived at all. Without
     * this the job could never say so — it would recompute the right answer
     * every half hour and fail to write it, forever.
     */
    public static function closeAsMissed(mysqli $con, int $sessionId, string $missedBy, string $now): int
    {
        return self::execute($con, "
            UPDATE session_requests SET status = 'missed', missed_by = ?, completed_at = ?
            WHERE request_id = ? AND status IN ('approved','unfinished')
        ", 'ssi', [$missedBy, $now, $sessionId]);
    }

    /**
     * Requests the mentor never answered whose start time is $now or earlier:
     * 'request_id', 'mentor_id', 'mentee_id', 'subject', 'session_date',
     * 'mentor_name', 'mentee_name', earliest first. They can no longer take
     * place as booked, so the job removes them.
     */
    public static function unansweredRequests(mysqli $con, string $now): array
    {
        return self::typedRows($con, "
            SELECT sr.request_id, sr.mentor_id, sr.mentee_id, sr.subject, sr.session_date,
                   CONCAT(um.firstname,' ',um.lastname) AS mentor_name,
                   CONCAT(ue.firstname,' ',ue.lastname) AS mentee_name
            FROM session_requests sr
            JOIN users um ON um.user_id = sr.mentor_id
            JOIN users ue ON ue.user_id = sr.mentee_id
            WHERE sr.status = 'pending' AND sr.session_date <= ?
            ORDER BY sr.session_date, sr.request_id
        ", 's', [$now]);
    }

    /**
     * Deletes an unanswered request whose start time is $now or earlier.
     * "AND status" guards against the mentor answering it in the meantime.
     * Returns 1 when it was deleted, else 0.
     */
    public static function deleteUnansweredRequest(mysqli $con, int $sessionId, string $now): int
    {
        return self::execute($con, "
            DELETE FROM session_requests
            WHERE request_id = ? AND status = 'pending' AND session_date <= ?
        ", 'is', [$sessionId, $now]);
    }

    /** Notes that the job closed $sessionId as missed by $missedBy. A second note for the same session is ignored. */
    public static function logMissed(mysqli $con, int $sessionId, string $missedBy): void
    {
        self::execute($con, "
            INSERT IGNORE INTO missed_session_logs (session_id, missed_by, detected_at) VALUES (?, ?, NOW())
        ", 'is', [$sessionId, $missedBy]);
    }

    // ── Profile pages ───────────────────────────────────────────────────────

    /**
     * The mentee's next accepted session that has not started, with the
     * mentor's 'mentor_name' and 'profile_image' and the slot's 'duration'
     * (60 when the slot is gone); null when there is none.
     */
    public static function nextApprovedWithMentorForMentee(mysqli $con, int $menteeId): ?array
    {
        return self::typedRow($con, "
            SELECT sr.request_id, sr.subject, sr.session_date,
                   CONCAT(u.firstname,' ',u.lastname) AS mentor_name, pr.profile_image,
                   COALESCE(a.duration, 60) AS duration
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentor_id
            LEFT JOIN profile pr ON pr.user_id = sr.mentor_id
            " . self::slotJoin() . "
            WHERE sr.mentee_id = ? AND sr.status = 'approved' AND sr.session_date >= NOW()
            ORDER BY sr.session_date ASC LIMIT 1
        ", 'i', [$menteeId]);
    }

    /** How many of the mentor's completed sessions are dated this month. */
    public static function countCompletedThisMonthForMentor(mysqli $con, int $mentorId): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) c FROM session_requests
            WHERE mentor_id = ? AND status = 'completed'
              AND session_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        ", 'i', [$mentorId]);
    }

    // ── An account that is closed ───────────────────────────────────────────

    /**
     * Every session the account takes part in that is still ahead — pending
     * requests and accepted sessions that have not started — with both
     * people's ids and names: 'request_id', 'status', 'subject',
     * 'session_date', 'mentor_id', 'mentee_id', 'mentor_name', 'mentee_name'.
     */
    public static function openAheadForAccount(mysqli $con, int $userId): array
    {
        return self::typedRows($con, "
            SELECT sr.request_id, sr.status, sr.subject, sr.session_date, sr.mentor_id, sr.mentee_id,
                   CONCAT_WS(' ', mo.firstname, mo.lastname) AS mentor_name,
                   CONCAT_WS(' ', me.firstname, me.lastname) AS mentee_name
            FROM session_requests sr
            JOIN users mo ON mo.user_id = sr.mentor_id
            JOIN users me ON me.user_id = sr.mentee_id
            WHERE (sr.mentor_id = ? OR sr.mentee_id = ?)
              AND sr.status IN ('pending','approved') AND sr.session_date > NOW()
            ORDER BY sr.session_date, sr.request_id
        ", 'ii', [$userId, $userId]);
    }

    /** Cancels a session that is still pending or accepted, keeping $reason on it. Returns 1 when it changed. */
    public static function cancelForClosedAccount(mysqli $con, int $sessionId, string $reason): int
    {
        return self::execute($con, "
            UPDATE session_requests SET status = 'cancelled', rejection_reason = ?
            WHERE request_id = ? AND status IN ('pending','approved')
        ", 'si', [$reason, $sessionId]);
    }
}
