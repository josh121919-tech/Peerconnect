<?php

/**
 * AvailabilityRepository — the slots mentors open for booking (the availability table).
 */
class AvailabilityRepository extends Repository
{
    /**
     * The mentor's group slots by date and start time, each with how many
     * pending or approved reservations it holds ('reserved_count'). A
     * reservation belongs to a slot by (mentor, subject, date, start time).
     */
    public static function groupSlotsForMentor(mysqli $con, int $mentorId): array
    {
        return self::rows($con, "
            SELECT
                a.availability_id, a.subject, a.topics, a.date, a.start_time, a.duration, a.capacity,
                COUNT(sr.request_id) AS reserved_count
            FROM availability a
            LEFT JOIN session_requests sr
                ON  sr.mentor_id   = a.mentor_id
                AND sr.subject     = a.subject
                AND DATE(sr.session_date) = DATE(a.date)
                AND TIME(sr.session_date) = a.start_time
                AND sr.status IN ('pending','approved')
            WHERE a.mentor_id = ? AND a.session_type = 'group'
            GROUP BY a.availability_id
            ORDER BY a.date, a.start_time
        ", 'i', [$mentorId]);
    }

    // ── A mentor's own slots (the Calendar page) ────────────────────────────
    // $date is 'Y-m-d' and $startTime 'H:i' or 'H:i:s' throughout.

    /** Every slot the mentor has opened, latest date and time first, with the slot id as 'id'. */
    public static function allForMentor(mysqli $con, int $mentorId): array
    {
        return self::rows($con, "
            SELECT availability_id AS id, date, start_time, duration, subject, about, topics, session_type, capacity
            FROM availability WHERE mentor_id = ?
            ORDER BY date DESC, start_time DESC
        ", 'i', [$mentorId]);
    }

    /** One of the mentor's slots ('date', 'start_time', 'subject', 'duration'), or null when it does not exist or is not theirs. */
    public static function findForMentor(mysqli $con, int $slotId, int $mentorId): ?array
    {
        return self::row($con, "
            SELECT date, start_time, subject, duration FROM availability WHERE availability_id = ? AND mentor_id = ?
        ", 'ii', [$slotId, $mentorId]);
    }

    /** The mentor's slots on one day, earliest first: 'availability_id', 'start_time', 'duration'. */
    public static function onDateForMentor(mysqli $con, int $mentorId, string $date): array
    {
        return self::rows($con, "
            SELECT availability_id, start_time, duration FROM availability
            WHERE mentor_id = ? AND date = ?
            ORDER BY start_time
        ", 'is', [$mentorId, $date]);
    }

    /** True when the mentor already has a slot with this date, start time, subject and session type. */
    public static function existsForMentor(mysqli $con, int $mentorId, string $date, string $startTime, string $subject, string $sessionType): bool
    {
        return self::value($con, "
            SELECT 1 FROM availability
            WHERE mentor_id = ? AND date = ? AND start_time = ? AND subject = ? AND session_type = ?
            LIMIT 1
        ", 'issss', [$mentorId, $date, $startTime, $subject, $sessionType]) !== null;
    }

    /** Opens a slot for the mentor. Returns 1 when it was added. */
    public static function createForMentor(mysqli $con, int $mentorId, string $date, string $startTime, int $duration,
                                           string $subject, string $about, string $topics, string $sessionType, int $capacity): int
    {
        return self::execute($con, "
            INSERT INTO availability (mentor_id, date, start_time, duration, subject, about, topics, session_type, capacity)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", 'ississssi', [$mentorId, $date, $startTime, $duration, $subject, $about, $topics, $sessionType, $capacity]);
    }

    /** Rewrites one of the mentor's slots. Returns 1 when something changed, 0 when nothing did or it is not theirs. */
    public static function updateForMentor(mysqli $con, int $slotId, int $mentorId, string $date, string $startTime, int $duration,
                                           string $subject, string $about, string $topics, string $sessionType, int $capacity): int
    {
        return self::execute($con, "
            UPDATE availability
               SET date = ?, start_time = ?, duration = ?, subject = ?, about = ?, topics = ?, session_type = ?, capacity = ?
             WHERE availability_id = ? AND mentor_id = ?
        ", 'ssissssiii', [$date, $startTime, $duration, $subject, $about, $topics, $sessionType, $capacity, $slotId, $mentorId]);
    }

    /** Removes one of the mentor's slots. Returns 1 when it was removed, 0 when it does not exist or is not theirs. */
    public static function deleteForMentor(mysqli $con, int $slotId, int $mentorId): int
    {
        return self::execute($con, "DELETE FROM availability WHERE availability_id = ? AND mentor_id = ?", 'ii', [$slotId, $mentorId]);
    }

    /**
     * Holds the mentor's calendar while a change is checked and saved, so two
     * saves at the same moment cannot both pass the overlap check. Waits up to
     * 5 seconds; false when it could not be had. Released by unlockMentor(),
     * or when the connection closes.
     */
    public static function lockMentor(mysqli $con, int $mentorId): bool
    {
        return self::value($con, "SELECT GET_LOCK(?, 5)", 's', ['pc_availability_mentor_' . $mentorId]) === '1';
    }

    public static function unlockMentor(mysqli $con, int $mentorId): void
    {
        self::value($con, "SELECT RELEASE_LOCK(?)", 's', ['pc_availability_mentor_' . $mentorId]);
    }
}
