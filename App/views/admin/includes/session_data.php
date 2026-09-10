<?php

/**
 * session_data.php — the session queries the three admin session screens share.
 *
 * All Sessions, Calendar View and Session Reports all answer questions about
 * the same rows, and they must not disagree: a session counted as "upcoming"
 * on one page cannot be "ongoing" on another. Every one of them derives its
 * state from ad_session_state() here.
 *
 * Duration, session type and topics are not columns on session_requests —
 * they belong to the availability slot the booking was made against. The join
 * used here (mentor + subject + date + start time) is the same one the mentee
 * and mentor pages use, so a length shown to an admin matches the length shown
 * to the people in the session. Where no slot survives, duration falls back to
 * an hour and the type is left unstated rather than guessed at.
 */

if (!defined('AD_SESSION_FALLBACK_MINUTES')) {
    define('AD_SESSION_FALLBACK_MINUTES', 60);
}

/** The one join every session query is built on. */
function ad_session_select(): string
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
        LEFT JOIN availability a
               ON a.mentor_id = sr.mentor_id
              AND a.subject   = sr.subject
              AND DATE(a.date) = DATE(sr.session_date)
              AND TIME(a.start_time) = TIME(sr.session_date)
    ";
}

/**
 * What a row actually is, right now.
 *
 * `status` alone cannot answer this: 'approved' covers a session next week, a
 * session happening this minute, and one whose time has passed but which
 * nobody has closed off. Reading the clock here is what separates them, and
 * doing it in one place is what keeps the three pages consistent.
 *
 * Returns one of: pending, ongoing, upcoming, overdue, completed, cancelled,
 * declined, missed.
 */
function ad_session_state(array $s, ?int $now = null): string
{
    $now = $now ?? time();
    $status = $s['status'] ?? '';

    if ($status === 'completed') return 'completed';
    if ($status === 'cancelled') return 'cancelled';
    if ($status === 'rejected')  return 'declined';
    if ($status === 'missed')    return 'missed';
    if ($status === 'pending')   return 'pending';

    // Approved: the clock decides.
    $start = strtotime($s['session_date'] ?? '');
    if (!$start) return 'upcoming';
    $end = $start + (int)($s['duration'] ?: AD_SESSION_FALLBACK_MINUTES) * 60;

    if ($now < $start) return 'upcoming';
    if ($now <= $end)  return 'ongoing';

    // Its time has been and gone and nobody closed it. Saying "upcoming"
    // here is how a stale session hides from the person who should chase it.
    return 'overdue';
}

/** Label and colour for each state, used by all three screens. */
function ad_session_states(): array
{
    return [
        'pending'   => ['Pending',   '#8A6400', '#FBF0D4'],
        'upcoming'  => ['Upcoming',  '#1A5C9A', '#E7F0FB'],
        'ongoing'   => ['Ongoing',   '#17654B', '#E6F5EE'],
        'overdue'   => ['Not closed', '#9A4A00', '#FBEDDD'],
        'completed' => ['Completed', '#17654B', '#E6F5EE'],
        'cancelled' => ['Cancelled', '#A6301F', '#FBE5E1'],
        'declined'  => ['Declined',  '#A6301F', '#FBE5E1'],
        'missed'    => ['Missed',    '#6B21A8', '#F3E8FF'],
    ];
}

/** The SQL condition for a tab, or '' for "everything". */
function ad_state_sql(string $view): string
{
    $mins = AD_SESSION_FALLBACK_MINUTES;
    $endExpr = "DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, $mins) MINUTE)";

    switch ($view) {
        case 'pending':   return "sr.status = 'pending'";
        case 'upcoming':  return "sr.status = 'approved' AND sr.session_date > NOW()";
        case 'ongoing':   return "sr.status = 'approved' AND sr.session_date <= NOW() AND $endExpr >= NOW()";
        case 'overdue':   return "sr.status = 'approved' AND $endExpr < NOW()";
        case 'completed': return "sr.status = 'completed'";
        case 'cancelled': return "sr.status = 'cancelled'";
        case 'declined':  return "sr.status = 'rejected'";
        case 'missed':    return "sr.status = 'missed'";
        default:          return '';
    }
}

/** Counts for every tab, in one pass rather than eight queries. */
function ad_session_counts(mysqli $con, string $extraWhere = '', array $bind = []): array
{
    $mins = AD_SESSION_FALLBACK_MINUTES;
    $end  = "DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, $mins) MINUTE)";
    $where = $extraWhere !== '' ? "WHERE $extraWhere" : '';

    $sql = "
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
        FROM session_requests sr
        LEFT JOIN availability a
               ON a.mentor_id = sr.mentor_id AND a.subject = sr.subject
              AND DATE(a.date) = DATE(sr.session_date)
              AND TIME(a.start_time) = TIME(sr.session_date)
        $where
    ";

    if ($bind) {
        $st = $con->prepare($sql);
        $st->bind_param(str_repeat('s', count($bind)), ...$bind);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
    } else {
        $row = $con->query($sql)->fetch_assoc();
    }

    foreach ($row as $k => $v) $row[$k] = (int)$v;
    return $row;
}

/** A session's reference, shown to admins and used in exports. */
function ad_session_ref(int $id, ?string $when = null): string
{
    $year = $when ? date('Y', strtotime($when)) : date('Y');
    return 'PC-' . $year . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

/** Minutes a session runs for, from its slot where one survives. */
function ad_session_minutes(array $s): int
{
    $d = (int)($s['duration'] ?? 0);
    return $d > 0 ? $d : AD_SESSION_FALLBACK_MINUTES;
}

/** "45 minutes", "1 hour", "1 hour 30 minutes". */
function ad_duration_label(int $mins): string
{
    if ($mins < 60) return $mins . ' minute' . ($mins === 1 ? '' : 's');
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    $out = $h . ' hour' . ($h === 1 ? '' : 's');
    return $m ? $out . ' ' . $m . ' minute' . ($m === 1 ? '' : 's') : $out;
}

/** "3:00 PM – 3:45 PM" for a row. */
function ad_time_range(array $s): string
{
    $start = strtotime($s['session_date']);
    if (!$start) return '—';
    $end = $start + ad_session_minutes($s) * 60;
    return date('g:i A', $start) . ' – ' . date('g:i A', $end);
}

/**
 * Group sessions are one availability slot with several bookings against it.
 * The count is of live bookings only — a cancelled reservation is not a seat
 * taken, and counting it would overstate how full a session is.
 */
function ad_group_size(mysqli $con, array $s): int
{
    if (($s['session_type'] ?? '') !== 'group') return 1;
    $st = $con->prepare("
        SELECT COUNT(*) c FROM session_requests
        WHERE mentor_id = ? AND subject = ? AND session_date = ?
          AND status IN ('pending','approved','completed')
    ");
    $st->bind_param('iss', $s['mentor_id'], $s['subject'], $s['session_date']);
    $st->execute();
    $n = (int)($st->get_result()->fetch_assoc()['c'] ?? 1);
    $st->close();
    return max(1, $n);
}
