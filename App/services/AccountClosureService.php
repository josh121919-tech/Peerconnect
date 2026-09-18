<?php

/**
 * AccountClosureService — what has to happen to the rest of the platform when
 * an account stops being usable: its owner deleted it, or an admin blocked it.
 *
 * Its sessions still ahead are called off, and the other person in each is
 * told. Left alone, an accepted session stayed "upcoming" on the other
 * person's calendar until the missed-session job recorded it as missed by
 * both — which counts against the mentor even when the mentor was the one
 * left waiting.
 */
class AccountClosureService
{
    /** Kept on each cancelled session, and shown to the other person. */
    public const REASON = "The other person's account is no longer active.";

    /**
     * The account's sessions still ahead, read before the account changes so
     * the notices can still name the person. Pass the result to
     * cancelSessions() once the account change is saved.
     */
    public static function sessionsAhead(mysqli $con, int $userId): array
    {
        return SessionRepository::openAheadForAccount($con, $userId);
    }

    /**
     * Cancels those sessions and tells the other person in each, by the same
     * notice (and email setting) an admin cancellation uses, and takes them out
     * of any connected Google Calendar. Returns how many were cancelled.
     */
    public static function cancelSessions(mysqli $con, int $userId, array $sessions): int
    {
        $cancelled = 0;
        foreach ($sessions as $s) {
            $id = (int)$s['request_id'];
            if (SessionRepository::cancelForClosedAccount($con, $id, self::REASON) < 1) {
                continue;   // closed some other way in the meantime
            }
            $cancelled++;

            $closedIsMentor = (int)$s['mentor_id'] === $userId;
            $otherId   = (int)($closedIsMentor ? $s['mentee_id'] : $s['mentor_id']);
            $otherLink = url($closedIsMentor ? 'mentee-sessions' : 'mentor-requests');
            $name      = trim((string)($closedIsMentor ? $s['mentor_name'] : $s['mentee_name']));
            $subject   = ($s['subject'] ?? '') !== '' ? $s['subject'] : 'mentoring';
            $when      = date('M j, g:i A', strtotime($s['session_date']));
            $what      = $s['status'] === 'pending' ? 'request' : 'session';

            NotificationService::send($con, $otherId, 'session_cancelled', 'Session Cancelled',
                "Your {$subject} {$what} with {$name} on {$when} was cancelled because {$name}'s account is no longer active.",
                $otherLink);

            if ($s['status'] === 'approved') {
                GoogleCalendarService::pushSession($con, $id);
            }
        }
        return $cancelled;
    }
}
