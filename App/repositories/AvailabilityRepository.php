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
}
