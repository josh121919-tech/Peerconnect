<?php

/**
 * AdminSessionRepository — the session queries behind the admin screens.
 *
 * All Sessions, Calendar View, Session Reports and the CSV export all ask
 * questions about the same rows, and they must not disagree: a session
 * counted as "upcoming" on one screen cannot be "ongoing" on another. Every
 * one of them is built on the select and the filters here.
 *
 * Duration, session type and topics are not columns on session_requests —
 * they belong to the availability slot the booking was made against. The join
 * used here (SessionRepository::slotJoin) is the same one the mentee and
 * mentor pages use, so a length shown to an admin matches the length shown to
 * the people in the session. Where no slot survives, duration falls back to
 * FALLBACK_MINUTES and the type is left unstated rather than guessed at.
 *
 * Rows come back with the native types a prepared statement gives, which is
 * what these pages were already reading: an int duration, a float average.
 */
class AdminSessionRepository extends Repository
{
    /** How long a session is taken to run for when its slot no longer exists. */
    public const FALLBACK_MINUTES = 60;

    /** How the list may be ordered. Anything else is treated as 'newest'. */
    private const SORTS = [
        'newest'  => 'sr.session_date DESC',
        'oldest'  => 'sr.session_date ASC',
        'subject' => 'sr.subject ASC, sr.session_date DESC',
    ];

    // ── The shape every screen reads ────────────────────────────────────────

    /**
     * The rows all four screens work over: a session, both people, and the
     * slot it was booked into.
     *
     * The two users joins are what the name search reads, so they belong to
     * every query the filters can touch — counting included.
     */
    private static function fromSql(): string
    {
        return "
        FROM session_requests sr
        JOIN users mo ON mo.user_id = sr.mentor_id
        JOIN users me ON me.user_id = sr.mentee_id
        LEFT JOIN profile po ON po.user_id = sr.mentor_id
        " . SessionRepository::slotJoin() . "
        ";
    }

    /** The one select every session screen is built on. */
    private static function selectSql(): string
    {
        return "
        SELECT sr.request_id, sr.subject, sr.message, sr.session_date, sr.status,
               sr.rejection_reason, sr.missed_by, sr.completed_at,
               sr.mentor_id, sr.mentee_id,
               CONCAT_WS(' ', mo.firstname, mo.lastname) AS mentor_name,
               CONCAT_WS(' ', me.firstname, me.lastname) AS mentee_name,
               po.profile_image AS mentor_pic, pe.profile_image AS mentee_pic,
               po.course AS mentor_course, pe.course AS mentee_course,
               a.session_type, a.duration, a.topics, a.capacity, a.about,
               (SELECT AVG(f.rating) FROM feedback f WHERE f.mentor_id = sr.mentor_id) AS mentor_rating,
               (SELECT AVG(r.rating) FROM mentee_reviews r WHERE r.mentee_id = sr.mentee_id) AS mentee_rating
        FROM session_requests sr
        JOIN users mo ON mo.user_id = sr.mentor_id
        JOIN users me ON me.user_id = sr.mentee_id
        LEFT JOIN profile po ON po.user_id = sr.mentor_id
        LEFT JOIN profile pe ON pe.user_id = sr.mentee_id
        " . SessionRepository::slotJoin() . "
        ";
    }

    /** When a session ends, in SQL: its slot's length, or the fallback hour. */
    private static function endExpr(): string
    {
        return "DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, " . self::FALLBACK_MINUTES . ") MINUTE)";
    }

    /**
     * The condition behind one tab, or '' for "everything".
     *
     * NOT YET SETTLED: 'upcoming' here is "session_date > NOW()", where the
     * member pages use ">=" (SessionRepository::upcomingCondition). Kept as
     * the admin screens had it until that is decided.
     */
    public static function stateCondition(string $view): string
    {
        $end = self::endExpr();

        switch ($view) {
            case 'pending':   return "sr.status = 'pending'";
            case 'upcoming':  return "sr.status = 'approved' AND sr.session_date > NOW()";
            // 'unfinished' inside the slot means somebody stepped out of a
            // session that can still be rejoined, so it is still running and
            // belongs here — the same call ad_session_state() makes for the
            // badge. Past its end it is an outcome, and drops out of Ongoing.
            case 'ongoing':   return "sr.status IN ('approved','unfinished') AND sr.session_date <= NOW() AND $end >= NOW()";
            // Not closed is for sessions still sitting open past their time.
            // An unfinished one has been closed; it just did not finish.
            case 'overdue':   return "sr.status = 'approved' AND $end < NOW()";
            case 'completed': return "sr.status = 'completed'";
            case 'cancelled': return "sr.status = 'cancelled'";
            case 'declined':  return "sr.status = 'rejected'";
            case 'missed':    return "sr.status = 'missed'";
            case 'unfinished': return "sr.status = 'unfinished' AND $end < NOW()";
            default:          return '';
        }
    }

    /**
     * The filters the screens share, as SQL. Every key is optional:
     *
     *   q        text typed into the search box — a name, a subject, or the
     *            digits of a reference (PC-2026-0123) or a bare id
     *   subject  exactly this subject
     *   club     the club the mentor belongs to
     *   type     '1v1' or 'group' (the slot's type)
     *   from/to  'Y-m-d', on or after / on or before
     *   mentor   this mentor's id
     *
     * Returns [clauses, types, args].
     */
    private static function filterParts(array $f): array
    {
        $clauses = [];
        $types   = '';
        $args    = [];

        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            // The reference numbers are PC-YYYY-0000; accept the digits from
            // one, or a bare id, so pasting a reference from an export finds
            // the session.
            $idHit = 0;
            if (preg_match('/(\d{1,10})\s*$/', $q, $m)) $idHit = (int)ltrim($m[1], '0');

            $clauses[] = "(CONCAT_WS(' ', mo.firstname, mo.lastname, me.firstname, me.lastname, sr.subject) LIKE ? OR sr.request_id = ?)";
            $types .= 'si';
            $args[] = '%' . $q . '%';
            $args[] = $idHit;
        }

        $subject = trim((string)($f['subject'] ?? ''));
        if ($subject !== '') { $clauses[] = 'sr.subject = ?';     $types .= 's'; $args[] = $subject; }

        // po is the mentor's profile, joined by both fromSql() and selectSql().
        $club = trim((string)($f['club'] ?? ''));
        if ($club !== '')    { $clauses[] = 'po.club = ?';        $types .= 's'; $args[] = $club; }

        $type = (string)($f['type'] ?? '');
        if ($type !== '')    { $clauses[] = 'a.session_type = ?'; $types .= 's'; $args[] = $type; }

        $from = (string)($f['from'] ?? '');
        if ($from !== '')    { $clauses[] = 'DATE(sr.session_date) >= ?'; $types .= 's'; $args[] = $from; }

        $to = (string)($f['to'] ?? '');
        if ($to !== '')      { $clauses[] = 'DATE(sr.session_date) <= ?'; $types .= 's'; $args[] = $to; }

        $mentor = (int)($f['mentor'] ?? 0);
        if ($mentor > 0)     { $clauses[] = 'sr.mentor_id = ?';   $types .= 'i'; $args[] = $mentor; }

        return [$clauses, $types, $args];
    }

    /** The filters and the tab together, as a WHERE clause. Returns [where, types, args]. */
    private static function whereParts(array $filters, string $view = ''): array
    {
        [$clauses, $types, $args] = self::filterParts($filters);

        $state = self::stateCondition($view);
        if ($state !== '') $clauses[] = $state;

        return [self::whereOf($clauses), $types, $args];
    }

    /** A date range as SQL, for the report figures. Returns [clauses, types, args]. */
    private static function rangeParts(?string $from, ?string $to, string $column = 'sr.session_date'): array
    {
        if ($from === null || $to === null) return [[], '', []];

        return [["DATE($column) BETWEEN ? AND ?"], 'ss', [$from, $to]];
    }

    /** "WHERE a AND b", or '' when there is nothing to say. */
    private static function whereOf(array $clauses): string
    {
        return $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
    }

    // ── All Sessions, Calendar View and the export ──────────────────────────

    /**
     * How many sessions each tab holds under $filters, in one pass rather
     * than eight queries: 'all_c', 'pending', 'upcoming', 'ongoing',
     * 'overdue', 'completed', 'cancelled', 'declined', 'missed'.
     */
    public static function tabCounts(mysqli $con, array $filters = []): array
    {
        [$where, $types, $args] = self::whereParts($filters);
        $end = self::endExpr();

        $row = self::typedRow($con, "
            SELECT
              COUNT(*) AS all_c,
              SUM(sr.status = 'pending')   AS pending,
              SUM(sr.status = 'approved' AND sr.session_date > NOW()) AS upcoming,
              SUM(sr.status = 'approved' AND sr.session_date <= NOW() AND $end >= NOW()) AS ongoing,
              SUM(sr.status = 'approved' AND $end < NOW()) AS overdue,
              SUM(sr.status = 'completed') AS completed,
              SUM(sr.status = 'cancelled') AS cancelled,
              SUM(sr.status = 'rejected')  AS declined,
              SUM(sr.status = 'missed')    AS missed
            " . self::fromSql() . "
            $where
        ", $types, $args) ?? [];

        foreach ($row as $k => $v) $row[$k] = (int)$v;
        return $row;
    }

    /** How many sessions match the filters and the tab. */
    public static function countMatching(mysqli $con, array $filters = [], string $view = ''): int
    {
        [$where, $types, $args] = self::whereParts($filters, $view);

        return (int)self::value($con, "SELECT COUNT(*) c " . self::fromSql() . " $where", $types, $args);
    }

    /** One page of the list, ordered by $sort ('newest', 'oldest' or 'subject'). */
    public static function page(mysqli $con, array $filters, string $view, string $sort, int $limit, int $offset): array
    {
        [$where, $types, $args] = self::whereParts($filters, $view);
        $order = self::SORTS[$sort] ?? self::SORTS['newest'];

        return self::typedRows(
            $con,
            self::selectSql() . " $where ORDER BY $order LIMIT ? OFFSET ?",
            $types . 'ii',
            array_merge($args, [$limit, $offset])
        );
    }

    /** Every session matching the filters and the tab, newest first — what the export writes. */
    public static function allMatching(mysqli $con, array $filters = [], string $view = ''): array
    {
        [$where, $types, $args] = self::whereParts($filters, $view);

        return self::typedRows($con, self::selectSql() . " $where ORDER BY sr.session_date DESC", $types, $args);
    }

    /** Every session dated between $from and $to inclusive, earliest first — the calendar's window. */
    public static function inRange(mysqli $con, string $from, string $to, array $filters = [], string $view = ''): array
    {
        $filters['from'] = $from;
        $filters['to']   = $to;
        [$where, $types, $args] = self::whereParts($filters, $view);

        return self::typedRows($con, self::selectSql() . " $where ORDER BY sr.session_date ASC", $types, $args);
    }

    /** One session for the detail panel, or null when there is no such session. */
    public static function find(mysqli $con, int $sessionId): ?array
    {
        return self::typedRow($con, self::selectSql() . " WHERE sr.request_id = ? LIMIT 1", 'i', [$sessionId]);
    }

    /**
     * The clubs sessions are actually run by — the club on the mentor's
     * profile. Only clubs with a session behind them are listed: a filter
     * offering a club that selects nothing is a dead end.
     */
    public static function clubs(mysqli $con): array
    {
        return array_column(self::rows($con, "
            SELECT DISTINCT p.club
            FROM session_requests sr
            JOIN profile p ON p.user_id = sr.mentor_id
            WHERE p.club IS NOT NULL AND p.club <> ''
            ORDER BY p.club
        "), 'club');
    }

    /** Every subject any session has ever been about, for the filter menus. */
    public static function subjects(mysqli $con): array
    {
        $out = [];
        foreach (self::rows($con, "SELECT DISTINCT subject FROM session_requests WHERE subject <> '' ORDER BY subject") as $r) {
            $out[] = $r['subject'];
        }
        return $out;
    }

    /** Every mentor who has a session, as 'user_id' and 'nm', for the calendar's filter. */
    public static function mentorsWithSessions(mysqli $con): array
    {
        return self::typedRows($con, "
            SELECT DISTINCT u.user_id, CONCAT_WS(' ', u.firstname, u.lastname) nm
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentor_id
            ORDER BY nm
        ");
    }

    /**
     * How many seats a group session has taken. A group session is one
     * availability slot with several bookings against it; the count is of
     * live bookings only — a cancelled reservation is not a seat taken, and
     * counting it would overstate how full a session is.
     */
    public static function groupSize(mysqli $con, array $s): int
    {
        if (($s['session_type'] ?? '') !== 'group') return 1;

        $n = (int)self::value($con, "
            SELECT COUNT(*) c FROM session_requests
            WHERE mentor_id = ? AND subject = ? AND session_date = ?
              AND status IN ('pending','approved','completed')
        ", 'iss', [(int)$s['mentor_id'], $s['subject'], $s['session_date']]);

        return max(1, $n);
    }

    /**
     * How many accepted sessions ended more than $minutes ago and are still
     * open. Nobody closes a session by hand: the missed-session job closes it
     * PC_MISSED_GRACE_MINUTES after it ends and runs every 30 minutes, so one
     * older than that means the job is not running. Its end is worked out the
     * way the job works it out (the slot's length, or an hour).
     */
    public static function countUnclosedOlderThan(mysqli $con, int $minutes): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*)
            FROM session_requests sr
            " . SessionRepository::slotJoin() . "
            WHERE sr.status = 'approved'
              AND " . self::endExpr() . " < NOW() - INTERVAL ? MINUTE
        ", 'i', [$minutes]);
    }

    // ── The figures on All Sessions ─────────────────────────────────────────

    /**
     * The tiles at the top of All Sessions, over every session there has ever
     * been: 'total', 'completed', 'cancelled' (cancelled and declined
     * together), 'missed', 'upcoming', 'today', and the same-month and
     * previous-month counts the trends compare ('month_*' and 'prev_*').
     *
     * "vs last month" means sessions SCHEDULED in each month: session_requests
     * has no created_at, so when a session was booked cannot be told.
     */
    public static function headlineFigures(mysqli $con): array
    {
        $thisMonth = "DATE_FORMAT(sr.session_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
        $lastMonth = "DATE_FORMAT(sr.session_date, '%Y-%m') = DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m')";
        $closed    = "sr.status IN ('cancelled','rejected')";

        $row = self::typedRow($con, "
            SELECT
              COUNT(*) AS total,
              SUM(sr.status = 'completed') AS completed,
              SUM($closed)                 AS cancelled,
              SUM(sr.status = 'missed')    AS missed,
              SUM(sr.status = 'approved' AND sr.session_date > NOW()) AS upcoming,
              SUM(DATE(sr.session_date) = CURDATE() AND sr.status IN ('approved','completed')) AS today,
              SUM($thisMonth) AS month_total,
              SUM($lastMonth) AS prev_total,
              SUM(sr.status = 'completed' AND $thisMonth) AS month_completed,
              SUM(sr.status = 'completed' AND $lastMonth) AS prev_completed,
              SUM($closed AND $thisMonth)  AS month_cancelled,
              SUM($closed AND $lastMonth)  AS prev_cancelled
            FROM session_requests sr
        ") ?? [];

        foreach ($row as $k => $v) $row[$k] = (int)$v;
        return $row;
    }

    // ── Session Reports ─────────────────────────────────────────────────────

    /**
     * The headline figures for a date range (null dates mean all time):
     * 'total', 'completed', 'cancelled' (cancelled and declined), 'missed',
     * 'upcoming', 'not_closed' (accepted, its time gone, nobody closed it),
     * 'pending', and how many missed sessions each side did not turn up to
     * ('mentor_missed', 'mentee_missed').
     */
    public static function rangeFigures(mysqli $con, ?string $from, ?string $to): array
    {
        [$clauses, $types, $args] = self::rangeParts($from, $to);
        $where = self::whereOf($clauses);

        $row = self::typedRow($con, "
            SELECT
              COUNT(*) AS total,
              SUM(sr.status = 'completed') AS completed,
              SUM(sr.status IN ('cancelled','rejected')) AS cancelled,
              SUM(sr.status = 'missed')    AS missed,
              SUM(sr.status = 'approved' AND sr.session_date > NOW())  AS upcoming,
              SUM(sr.status = 'approved' AND sr.session_date <= NOW()) AS not_closed,
              SUM(sr.status = 'pending')   AS pending,
              SUM(sr.status = 'missed' AND sr.missed_by IN ('mentor','both')) AS mentor_missed,
              SUM(sr.status = 'missed' AND sr.missed_by IN ('mentee','both')) AS mentee_missed
            FROM session_requests sr
            $where
        ", $types, $args) ?? [];

        foreach ($row as $k => $v) $row[$k] = (int)$v;
        return $row;
    }

    /**
     * Average session length in the range, as 'minutes' (null when nothing
     * can be measured) and 'sessions', how many it was measured over.
     *
     * Sessions whose slot has since been deleted are left out rather than
     * counted as the fallback hour, which would drag the average.
     */
    public static function averageMinutes(mysqli $con, ?string $from, ?string $to): array
    {
        [$clauses, $types, $args] = self::rangeParts($from, $to);
        $clauses[] = 'a.duration > 0';

        $row = self::typedRow($con, "
            SELECT AVG(a.duration) AS minutes, COUNT(*) AS sessions
            FROM session_requests sr
            JOIN availability a
                   ON a.mentor_id        = sr.mentor_id
                  AND a.subject          = sr.subject
                  AND DATE(a.date)       = DATE(sr.session_date)
                  AND TIME(a.start_time) = TIME(sr.session_date)
            " . self::whereOf($clauses) . "
        ", $types, $args) ?? [];

        return [
            'minutes'  => ($row['minutes'] ?? null) !== null ? (float)$row['minutes'] : null,
            'sessions' => (int)($row['sessions'] ?? 0),
        ];
    }

    /** How many sessions in each status fall on each day between $first and $last: rows of 'd', 'status', 'c'. */
    public static function dailyStatusCounts(mysqli $con, string $first, string $last): array
    {
        return self::typedRows($con, "
            SELECT DATE(sr.session_date) d, sr.status, COUNT(*) c
            FROM session_requests sr
            WHERE DATE(sr.session_date) BETWEEN ? AND ?
            GROUP BY d, sr.status
        ", 'ss', [$first, $last]);
    }

    /**
     * The busiest subjects in the range, as 'subject' and 'c', most first.
     *
     * Subjects level on count are ordered by name, so the six that make the
     * cut are the same six every time the page is opened. Without that the
     * database is free to return any of the tied subjects, and the chart
     * quietly reshuffles between one refresh and the next.
     */
    public static function subjectTotals(mysqli $con, ?string $from, ?string $to, int $limit): array
    {
        [$clauses, $types, $args] = self::rangeParts($from, $to);
        $clauses[] = "sr.subject <> ''";

        return self::typedRows($con, "
            SELECT sr.subject, COUNT(*) c
            FROM session_requests sr
            " . self::whereOf($clauses) . "
            GROUP BY sr.subject
            ORDER BY c DESC, sr.subject ASC
            LIMIT ?
        ", $types . 'i', array_merge($args, [$limit]));
    }

    /**
     * The mentors with the most completed sessions in the range: 'user_id',
     * 'nm', 'profile_image', 'sessions' and their overall 'rating'.
     *
     * Mentors level on both are ordered by name, for the same reason the
     * subjects above are: so the five shown do not change between refreshes.
     */
    public static function topMentors(mysqli $con, ?string $from, ?string $to, int $limit): array
    {
        [$clauses, $types, $args] = self::rangeParts($from, $to);
        $clauses[] = "sr.status = 'completed'";

        return self::typedRows($con, "
            SELECT u.user_id, CONCAT_WS(' ', u.firstname, u.lastname) nm, p.profile_image,
                   COUNT(*) sessions,
                   (SELECT AVG(f.rating) FROM feedback f WHERE f.mentor_id = u.user_id) rating
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentor_id
            LEFT JOIN profile p ON p.user_id = u.user_id
            " . self::whereOf($clauses) . "
            GROUP BY u.user_id, nm, p.profile_image
            ORDER BY sessions DESC, rating DESC, nm ASC
            LIMIT ?
        ", $types . 'i', array_merge($args, [$limit]));
    }

    /** The most recent sessions in the range, newest first. */
    public static function recent(mysqli $con, ?string $from, ?string $to, int $limit): array
    {
        [$clauses, $types, $args] = self::rangeParts($from, $to);

        return self::typedRows(
            $con,
            self::selectSql() . ' ' . self::whereOf($clauses) . " ORDER BY sr.session_date DESC LIMIT ?",
            $types . 'i',
            array_merge($args, [$limit])
        );
    }
}
