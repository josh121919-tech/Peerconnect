<?php

/**
 * session_data.php — how the admin session screens present a session.
 *
 * All Sessions, Calendar View and Session Reports all show the same rows, and
 * they must not disagree: a session counted as "upcoming" on one page cannot
 * be "ongoing" on another. Every one of them derives its state from
 * ad_session_state() here, and the rows themselves come from
 * AdminSessionRepository, which is where the queries live.
 *
 * Duration, session type and topics are not columns on session_requests —
 * they belong to the availability slot the booking was made against. Where no
 * slot survives, duration falls back to an hour and the type is left unstated
 * rather than guessed at.
 */

if (!defined('AD_SESSION_FALLBACK_MINUTES')) {
    define('AD_SESSION_FALLBACK_MINUTES', AdminSessionRepository::FALLBACK_MINUTES);
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
