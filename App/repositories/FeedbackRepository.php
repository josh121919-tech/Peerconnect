<?php

/**
 * FeedbackRepository — the ratings mentees give after a session (the feedback table).
 */
class FeedbackRepository extends Repository
{
    /**
     * The mentor's average rating to one decimal ('average', a string such as
     * "4.5", null when there are no reviews) and how many reviews it comes
     * from ('reviews').
     */
    public static function ratingSummaryForMentor(mysqli $con, int $mentorId): array
    {
        return self::row($con, "
            SELECT ROUND(AVG(rating),1) AS average, COUNT(*) AS reviews FROM feedback WHERE mentor_id = ?
        ", 'i', [$mentorId]) ?? ['average' => null, 'reviews' => '0'];
    }
}
