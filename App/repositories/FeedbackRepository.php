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

    /** Every review of the mentor with the reviewer's first and last name, in no particular order: all feedback columns plus 'firstname', 'lastname'. */
    public static function reviewsForMentor(mysqli $con, int $mentorId): array
    {
        return self::rows($con, "
            SELECT f.*, u.firstname, u.lastname
            FROM feedback f
            JOIN users u ON f.mentee_id = u.user_id
            WHERE f.mentor_id = ?
        ", 'i', [$mentorId]);
    }

    /**
     * The mentor's rating averages for their profile page, unrounded:
     * 'avg_rating', 'total_reviews', and per category 'avg_comm', 'avg_know',
     * 'avg_eff', 'avg_skill' (null when there are no reviews).
     */
    public static function averagesForMentor(mysqli $con, int $mentorId): array
    {
        return self::row($con, "
            SELECT AVG(rating) AS avg_rating, COUNT(*) AS total_reviews,
                   AVG(communication) AS avg_comm, AVG(knowledge) AS avg_know,
                   AVG(efficiency) AS avg_eff, AVG(skill) AS avg_skill
            FROM feedback WHERE mentor_id = ?
        ", 'i', [$mentorId]) ?? [];
    }
}
