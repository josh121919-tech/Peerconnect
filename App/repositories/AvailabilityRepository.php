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
        return self::acquireLock($con, 'pc_availability_mentor_' . $mentorId, 5);
    }

    public static function unlockMentor(mysqli $con, int $mentorId): void
    {
        self::releaseLock($con, 'pc_availability_mentor_' . $mentorId);
    }

    // ── Booking (mentee side) ───────────────────────────────────────────────
    // Only times that have not started yet are ever offered.

    /**
     * The slot a booking asks for: the mentor's slot on $date at $startTime
     * ('H:i:s') with that subject (letter case and trailing spaces ignored) and
     * session type, with its own 'date', 'start_time', 'subject', 'session_type',
     * 'capacity' and 'duration'. Null when there is none.
     */
    public static function findForBooking(mysqli $con, int $mentorId, string $date, string $startTime, string $subject, string $sessionType): ?array
    {
        return self::typedRow($con, "
            SELECT date, start_time, subject, session_type, capacity, duration
            FROM availability
            WHERE mentor_id = ?
            AND date = ?
            AND start_time = ?
            AND LOWER(subject) = LOWER(?)
            AND session_type = ?
        ", 'issss', [$mentorId, $date, $startTime, $subject, $sessionType]);
    }

    /** The mentor's future slots of one subject and type, soonest first: 'date', 'about', 'topics', 'start_time', 'duration'. */
    public static function upcomingForSubjectAndType(mysqli $con, int $mentorId, string $subject, string $sessionType): array
    {
        return self::typedRows($con, "
            SELECT date, about, topics, start_time, duration
            FROM availability
            WHERE mentor_id      = ?
              AND subject        = ?
              AND session_type   = ?
              AND CONCAT(date, ' ', start_time) > NOW()
            ORDER BY date, start_time
        ", 'iss', [$mentorId, $subject, $sessionType]);
    }

    /**
     * The start times still bookable on one day for a subject and type: a 1v1
     * slot with no pending or approved request at that time, or a group slot
     * with a seat left. Seats are counted over every request at that time,
     * whatever its subject.
     */
    public static function openStartTimesOn(mysqli $con, int $mentorId, string $date, string $subject, string $sessionType): array
    {
        return array_column(self::typedRows($con, "
            SELECT
                a.start_time,
                a.capacity,
                COUNT(s.request_id) AS reserved_count
            FROM availability a
            LEFT JOIN session_requests s
                ON s.mentor_id = a.mentor_id
                AND DATE(s.session_date) = a.date
                AND TIME(s.session_date) = a.start_time
                AND s.status IN ('pending','approved')
            WHERE a.mentor_id = ?
              AND a.date = ?
              AND LOWER(a.subject) = LOWER(?)
              AND a.session_type = ?
              AND CONCAT(a.date, ' ', a.start_time) > NOW()
            GROUP BY a.availability_id, a.start_time, a.capacity
            HAVING (
                (? = '1v1' AND reserved_count = 0)
                OR
                (? = 'group' AND reserved_count < a.capacity)
            )
        ", 'isssss', [$mentorId, $date, $subject, $sessionType, $sessionType, $sessionType]), 'start_time');
    }

    /**
     * The mentor's future group slots of one subject, soonest first, each with
     * 'session_date', 'start_time' as '09:00 AM', 'duration', 'capacity' and
     * 'reserved_count' (pending and approved reservations of that subject).
     */
    public static function upcomingGroupSlotsForSubject(mysqli $con, int $mentorId, string $subject): array
    {
        return self::typedRows($con, "
            SELECT
                a.availability_id,
                a.subject,
                a.capacity,
                DATE(a.date)                              AS session_date,
                TIME_FORMAT(a.start_time, '%h:%i %p')    AS start_time,
                a.duration,
                COUNT(sr.request_id)                      AS reserved_count
            FROM availability a
            LEFT JOIN session_requests sr
                ON  sr.mentor_id    = a.mentor_id
                AND sr.subject      = a.subject
                AND DATE(sr.session_date) = DATE(a.date)
                AND TIME(sr.session_date) = a.start_time
                AND sr.status IN ('pending', 'approved')
            WHERE a.mentor_id    = ?
              AND a.subject      = ?
              AND a.session_type = 'group'
              AND CONCAT(a.date, ' ', a.start_time) > NOW()
            GROUP BY a.availability_id
            ORDER BY a.date, a.start_time
        ", 'is', [$mentorId, $subject]);
    }

    /**
     * Up to $limit of the mentor's future slots, soonest first, for the Request
     * Mentorship modal, each with 'taken': pending and approved requests at that
     * time, whatever their subject.
     */
    public static function upcomingWithSeatsTaken(mysqli $con, int $mentorId, int $limit): array
    {
        return self::typedRows($con, "
            SELECT a.date, a.start_time, a.duration, a.session_type, a.subject,
                   a.capacity, a.topics,
                   (SELECT COUNT(*) FROM session_requests sr
                     WHERE sr.mentor_id = a.mentor_id
                       AND sr.session_date = CONCAT(a.date, ' ', a.start_time)
                       AND sr.status IN ('pending','approved')) AS taken
            FROM availability a
            WHERE a.mentor_id = ?
              AND CONCAT(a.date, ' ', a.start_time) > NOW()
            ORDER BY a.date ASC, a.start_time ASC
            LIMIT ?
        ", 'ii', [$mentorId, $limit]);
    }

    /** The mentor's future slots for their profile page, 1v1 first then group, each by date and time: 'subject', 'start_time', 'duration', 'session_type'. */
    public static function upcomingForProfile(mysqli $con, int $mentorId): array
    {
        return self::rows($con, "
            SELECT subject, start_time, duration, session_type
            FROM availability
            WHERE mentor_id = ?
              AND CONCAT(date, ' ', start_time) > NOW()
            ORDER BY CASE WHEN session_type = '1v1' THEN 1 ELSE 2 END, date, start_time
        ", 'i', [$mentorId]);
    }

    /** How many of the mentor's slots are dated today or later. */
    public static function countFromTodayForMentor(mysqli $con, int $mentorId): int
    {
        return (int)self::value($con, "SELECT COUNT(*) c FROM availability WHERE mentor_id = ? AND date >= CURDATE()", 'i', [$mentorId]);
    }
}
