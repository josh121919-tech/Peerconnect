<?php

/**
 * MentorScoreRepository — the stored mentor scores (mentor_scores) that
 * MentorScoreService calculates and Find a Mentor ranks by.
 */
class MentorScoreRepository extends Repository
{
    /**
     * The mentor's stored score — 'avg_rating', 'total_sessions',
     * 'completion_rate', 'effectiveness_score', 'recommendation_score',
     * 'last_calculated' — or null when it has never been calculated.
     */
    public static function forMentor(mysqli $con, int $mentorId): ?array
    {
        return self::typedRow($con, "
            SELECT avg_rating, total_sessions, completion_rate, effectiveness_score, recommendation_score, last_calculated
            FROM mentor_scores WHERE mentor_id = ?
        ", 'i', [$mentorId]);
    }
}
