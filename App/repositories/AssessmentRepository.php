<?php

/**
 * AssessmentRepository — assessments and mentees' attempts at them.
 */
class AssessmentRepository extends Repository
{
    /** How many assessments the mentee has submitted, ever. */
    public static function countSubmittedByMentee(mysqli $con, int $menteeId): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) FROM assessment_attempts
            WHERE mentee_id = ? AND status = 'submitted'
        ", 'i', [$menteeId]);
    }

    /**
     * How many the mentee submitted between $fromDaysAgo and $toDaysAgo days
     * ago. $toDaysAgo = 0 means "up to now", with no upper limit at all, as the
     * dashboard's "last 30 days" figure has always counted.
     */
    public static function countSubmittedByMenteeBetween(mysqli $con, int $menteeId, int $fromDaysAgo, int $toDaysAgo = 0): int
    {
        $sql = "
            SELECT COUNT(*) FROM assessment_attempts
            WHERE mentee_id = ? AND status = 'submitted'
              AND submitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        if ($toDaysAgo === 0) {
            return (int)self::value($con, $sql, 'ii', [$menteeId, $fromDaysAgo]);
        }
        return (int)self::value($con, $sql . " AND submitted_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            'iii', [$menteeId, $fromDaysAgo, $toDaysAgo]);
    }

    /**
     * Published assessments from mentors this mentee has an approved or
     * completed session with, each with the mentee's attempt if there is one.
     * Unsubmitted first, then newest published first.
     */
    public static function publishedForMentee(mysqli $con, int $menteeId): array
    {
        return self::rows($con, "
            SELECT a.assessment_id, a.title, a.topic,
                   u.firstname, u.lastname,
                   at.status AS attempt_status, at.score, at.total_points
            FROM assessments a
            JOIN users u ON u.user_id = a.mentor_id
            LEFT JOIN assessment_attempts at
                   ON at.assessment_id = a.assessment_id AND at.mentee_id = ?
            WHERE a.status = 'published'
              AND EXISTS (
                  SELECT 1 FROM session_requests sr
                   WHERE sr.mentor_id = a.mentor_id AND sr.mentee_id = ?
                     AND sr.status IN ('approved','completed')
              )
            ORDER BY (at.attempt_id IS NOT NULL AND at.status = 'submitted'), a.published_at DESC
        ", 'ii', [$menteeId, $menteeId]);
    }

    /** The mentee's average score across submitted attempts, as a whole percentage (0 when none). */
    public static function averageScorePercentForMentee(mysqli $con, int $menteeId): int
    {
        return (int)self::value($con, "
            SELECT COALESCE(ROUND(AVG(score / NULLIF(total_points,0) * 100)), 0)
            FROM assessment_attempts
            WHERE mentee_id = ? AND status = 'submitted'
        ", 'i', [$menteeId]);
    }
}
