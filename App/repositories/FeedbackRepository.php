<?php

/**
 * FeedbackRepository — what people say about each other after a session.
 *
 * Two tables, one per direction: `feedback` is the mentee rating the mentor,
 * `mentee_reviews` is the mentor rating the mentee. Both are written once per
 * session, and where a session somehow carries more than one, the newest row
 * is the one that counts.
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

    // ── Over a date range, for the reports ──────────────────────────────────

    /**
     * The ratings mentees left between $from and $to, by the date they left
     * them (null dates mean all time): 'average' as a float, null when nobody
     * rated anything, and 'reviews', how many there were.
     */
    public static function ratingSummaryBetween(mysqli $con, ?string $from, ?string $to): array
    {
        [$where, $types, $args] = self::createdBetween($from, $to);

        $row = self::typedRow($con, "SELECT AVG(f.rating) AS average, COUNT(*) AS reviews FROM feedback f $where", $types, $args) ?? [];

        return [
            'average' => ($row['average'] ?? null) !== null ? (float)$row['average'] : null,
            'reviews' => (int)($row['reviews'] ?? 0),
        ];
    }

    /**
     * How those ratings were spread across the five stars, as [1 => n, ... 5 => n].
     * A half star rounds to the nearest whole one.
     */
    public static function ratingDistributionBetween(mysqli $con, ?string $from, ?string $to): array
    {
        [$where, $types, $args] = self::createdBetween($from, $to);

        $dist = array_fill(1, 5, 0);
        foreach (self::typedRows($con, "SELECT ROUND(f.rating) r, COUNT(*) c FROM feedback f $where GROUP BY ROUND(f.rating)", $types, $args) as $row) {
            $star = max(1, min(5, (int)$row['r']));
            $dist[$star] += (int)$row['c'];
        }
        return $dist;
    }

    /** The "left between these dates" condition, or nothing at all. Returns [where, types, args]. */
    private static function createdBetween(?string $from, ?string $to): array
    {
        if ($from === null || $to === null) return ['', '', []];

        return ['WHERE DATE(f.created_at) BETWEEN ? AND ?', 'ss', [$from, $to]];
    }

    // ── One session ─────────────────────────────────────────────────────────

    /**
     * What the mentee said about the mentor for one session: 'rating',
     * 'comment', 'communication', 'knowledge', 'efficiency', 'skill' and
     * 'created_at'. An empty array when they said nothing.
     */
    public static function forSession(mysqli $con, int $sessionId): array
    {
        return self::typedRow($con, "
            SELECT rating, comment, communication, knowledge, efficiency, skill, created_at
            FROM feedback WHERE session_id = ? ORDER BY feedback_id DESC LIMIT 1
        ", 'i', [$sessionId]) ?? [];
    }

    /**
     * And what the mentor said about the mentee: 'rating', 'comment',
     * 'preparedness', 'participation', 'communication', 'receptiveness' and
     * 'created_at'. Null when they said nothing.
     */
    public static function menteeReviewForSession(mysqli $con, int $sessionId): ?array
    {
        return self::typedRow($con, "
            SELECT rating, comment, preparedness, participation, communication, receptiveness, created_at
            FROM mentee_reviews WHERE session_id = ? ORDER BY review_id DESC LIMIT 1
        ", 'i', [$sessionId]);
    }

    /** The rating each of $sessionIds got from its mentee, as [session_id => rating]. */
    public static function ratingsBySession(mysqli $con, array $sessionIds): array
    {
        return self::latestRatings($con, 'feedback', 'feedback_id', $sessionIds);
    }

    /** The rating each of $sessionIds got from its mentor, as [session_id => rating]. */
    public static function menteeReviewRatingsBySession(mysqli $con, array $sessionIds): array
    {
        return self::latestRatings($con, 'mentee_reviews', 'review_id', $sessionIds);
    }

    /**
     * [session_id => rating] for a whole list at once, which is what an export
     * of a hundred sessions needs instead of a hundred round trips. Sessions
     * nobody rated are simply absent.
     *
     * Rows arrive oldest first, so where a session has more than one the last
     * one written wins — the same row ORDER BY ... DESC LIMIT 1 picks.
     */
    private static function latestRatings(mysqli $con, string $table, string $idColumn, array $sessionIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $sessionIds)));
        if (!$ids) return [];

        $rows = self::rows($con, "
            SELECT session_id, rating FROM $table
            WHERE session_id IN (" . self::marks($ids) . ")
            ORDER BY $idColumn ASC
        ", str_repeat('i', count($ids)), $ids);

        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['session_id']] = $r['rating'];
        }
        return $out;
    }

    // ── Profile pages ───────────────────────────────────────────────────────

    /** The ratings this mentee has given: 'avg_rating' (to one decimal, null when none) and 'total'. */
    public static function givenSummaryForMentee(mysqli $con, int $menteeId): ?array
    {
        return self::typedRow($con, "SELECT ROUND(AVG(rating),1) avg_rating, COUNT(*) total FROM feedback WHERE mentee_id = ?", 'i', [$menteeId]);
    }

    /**
     * The mentor's average rating from reviews left this month ('this_avg')
     * and last month ('prev_avg'); each null when that month has none.
     */
    public static function monthTrendForMentor(mysqli $con, int $mentorId): ?array
    {
        return self::typedRow($con, "
            SELECT AVG(CASE WHEN created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN rating END) AS this_avg,
                   AVG(CASE WHEN created_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                             AND created_at <  DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN rating END) AS prev_avg
            FROM feedback WHERE mentor_id = ?
        ", 'i', [$mentorId]);
    }

    /**
     * Every review of the mentor, newest first, with the reviewer's name and
     * the session's subject: 'comment', 'tags', 'rating', 'created_at',
     * 'firstname', 'lastname', 'subject' (null when the session is gone).
     */
    public static function reviewsWithSubjectForMentor(mysqli $con, int $mentorId): array
    {
        return self::typedRows($con, "
            SELECT f.comment, f.tags, f.rating, f.created_at, u.firstname, u.lastname, sr.subject
            FROM feedback f
            JOIN users u ON u.user_id = f.mentee_id
            LEFT JOIN session_requests sr ON sr.request_id = f.session_id
            WHERE f.mentor_id = ?
            ORDER BY f.created_at DESC
        ", 'i', [$mentorId]);
    }
}
