<?php

/**
 * AvailabilityService.php
 * Once a booked session finishes, the mentor's posted availability slot for
 * that date/time has no further use — this removes it automatically instead
 * of leaving it for the mentor to delete by hand from calendar.php.
 *
 * Availability rows aren't foreign-keyed to session_requests (matched instead
 * by mentor_id + subject + date/time, same as get_times.php and
 * group_sessions.php already do), so a group slot with several mentees only
 * gets removed once none of them are still pending/approved — otherwise
 * completing one mentee's session would wipe the slot out from under the
 * others still waiting on theirs.
 */
class AvailabilityService
{
    public static function removeIfFullyCompleted(mysqli $con, int $session_id): void
    {
        $stmt = $con->prepare("SELECT mentor_id, subject, session_date FROM session_requests WHERE request_id = ? LIMIT 1");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$session) return;

        $mentor_id = (int)$session['mentor_id'];
        $subject   = $session['subject'];
        $sessDate  = $session['session_date'];

        $stmt = $con->prepare("
            SELECT availability_id FROM availability
            WHERE mentor_id = ? AND subject = ?
              AND DATE(date) = DATE(?) AND TIME(start_time) = TIME(?)
            LIMIT 1
        ");
        $stmt->bind_param("isss", $mentor_id, $subject, $sessDate, $sessDate);
        $stmt->execute();
        $slot = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$slot) return;

        $stmt = $con->prepare("
            SELECT COUNT(*) AS active
            FROM session_requests
            WHERE mentor_id = ? AND subject = ?
              AND DATE(session_date) = DATE(?) AND TIME(session_date) = TIME(?)
              AND status IN ('pending', 'approved')
        ");
        $stmt->bind_param("isss", $mentor_id, $subject, $sessDate, $sessDate);
        $stmt->execute();
        $active = (int)$stmt->get_result()->fetch_assoc()['active'];
        $stmt->close();
        if ($active > 0) return;

        $del = $con->prepare("DELETE FROM availability WHERE availability_id = ?");
        $del->bind_param("i", $slot['availability_id']);
        $del->execute();
        $del->close();
    }
}
