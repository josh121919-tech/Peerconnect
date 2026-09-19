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

    // ── Writing reviews (feedback/review.php and feedback/save.php) ─────────
    //
    // $asMentee picks the direction: true is the mentee rating the mentor
    // (`feedback`), false the mentor rating the mentee (`mentee_reviews`).

    /** The review table for a direction, and the column that holds its author. */
    private static function reviewTable(bool $asMentee): array
    {
        return $asMentee ? ['feedback', 'mentee_id'] : ['mentee_reviews', 'mentor_id'];
    }

    /**
     * The slot a session was booked against (mentor + subject + exact start),
     * which holds its length. A session with no slot is taken to last 60
     * minutes, and MySQL works out the end, on its own clock.
     */
    private const SLOT_JOIN = "
        LEFT JOIN availability a
               ON a.mentor_id        = sr.mentor_id
              AND a.subject          = sr.subject
              AND DATE(a.date)       = DATE(sr.session_date)
              AND TIME(a.start_time) = TIME(sr.session_date)";

    /**
     * Sessions the user may review: they were in it, it is approved or
     * completed, and it has ended. Newest first, with the other person's
     * name, club and photo and the session's length and type.
     */
    public static function reviewableSessions(mysqli $con, int $userId, bool $asMentee): array
    {
        [$mine, $other] = $asMentee ? ['sr.mentee_id', 'sr.mentor_id'] : ['sr.mentor_id', 'sr.mentee_id'];
        return self::rows($con, "
            SELECT sr.request_id, sr.session_date, sr.subject, sr.status,
                   $other AS other_id,
                   CONCAT(u.firstname, ' ', u.lastname) AS other_name,
                   p.club, p.profile_image,
                   COALESCE(a.duration, 60)        AS duration,
                   COALESCE(a.session_type, '1v1') AS session_type,
                   DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) AS session_end
            FROM session_requests sr
            JOIN users u   ON u.user_id = $other
            LEFT JOIN profile p ON p.user_id = $other
            " . self::SLOT_JOIN . "
            WHERE $mine = ?
              AND sr.status IN ('approved', 'completed')
              AND DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) <= NOW()
            ORDER BY sr.session_date DESC
        ", 'i', [$userId]);
    }

    /** The user's own reviews of $sessionIds, every column, keyed by session id. */
    public static function reviewsByAuthor(mysqli $con, int $userId, bool $asMentee, array $sessionIds): array
    {
        if (!$sessionIds) {
            return [];
        }
        [$table, $author] = self::reviewTable($asMentee);
        $ids = array_map('intval', array_values($sessionIds));
        $out = [];
        foreach (self::rows($con, "SELECT * FROM $table WHERE $author = ? AND session_id IN (" . self::marks($ids) . ")",
            'i' . str_repeat('i', count($ids)), array_merge([$userId], $ids)) as $r) {
            $out[(int)$r['session_id']] = $r;
        }
        return $out;
    }

    /** The other person's review of the session (every column), or null when they have not written one. */
    public static function counterpartReview(mysqli $con, int $sessionId, int $otherId, bool $asMentee): ?array
    {
        [$table, $author] = self::reviewTable(!$asMentee);
        return self::typedRow($con, "SELECT * FROM $table WHERE session_id = ? AND $author = ?", 'ii', [$sessionId, $otherId]);
    }

    /**
     * A session the user was in and may review: 'request_id', 'mentee_id',
     * 'mentor_id', 'subject' and 'has_ended' (1 once it is over). Null when
     * they were not in it or it is not approved or completed.
     */
    public static function sessionToReview(mysqli $con, int $sessionId, int $userId, bool $asMentee): ?array
    {
        $mine = $asMentee ? 'sr.mentee_id' : 'sr.mentor_id';
        return self::typedRow($con, "
            SELECT sr.request_id, sr.mentee_id, sr.mentor_id, sr.subject,
                   DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) <= NOW() AS has_ended
            FROM session_requests sr
            " . self::SLOT_JOIN . "
            WHERE sr.request_id = ? AND $mine = ? AND sr.status IN ('approved','completed')
        ", 'ii', [$sessionId, $userId]);
    }

    /** Whether the user has already reviewed the session. */
    public static function hasReviewed(mysqli $con, int $sessionId, int $userId, bool $asMentee): bool
    {
        [$table, $author] = self::reviewTable($asMentee);
        return self::value($con, "SELECT 1 FROM $table WHERE session_id = ? AND $author = ?", 'ii', [$sessionId, $userId]) !== null;
    }

    /** A saved draft's JSON, or null when there is none. $direction is 'mentee_to_mentor' or 'mentor_to_mentee'. */
    public static function draftPayload(mysqli $con, int $sessionId, int $authorId, string $direction): ?string
    {
        return self::value($con, "SELECT payload FROM feedback_drafts WHERE session_id = ? AND author_id = ? AND direction = ?",
            'iis', [$sessionId, $authorId, $direction]);
    }

    /** Saves (or replaces) a draft. */
    public static function saveDraft(mysqli $con, string $direction, int $sessionId, int $authorId, string $payload): void
    {
        self::execute($con, "
            INSERT INTO feedback_drafts (direction, session_id, author_id, payload, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = NOW()
        ", 'siis', [$direction, $sessionId, $authorId, $payload]);
    }

    /** Deletes a draft once its review is submitted. */
    public static function deleteDraft(mysqli $con, int $sessionId, int $authorId, string $direction): void
    {
        self::execute($con, "DELETE FROM feedback_drafts WHERE session_id = ? AND author_id = ? AND direction = ?",
            'iis', [$sessionId, $authorId, $direction]);
    }

    /**
     * Stores a mentee's review of their mentor. $scores and $notes are keyed
     * 'communication', 'efficiency', 'knowledge', 'skill' and 'rating'. A
     * second review of one session is refused by the database
     * (ux_session_mentee), which throws.
     */
    public static function addMenteeReview(mysqli $con, int $sessionId, int $menteeId, int $mentorId, array $scores, array $notes, string $comment): void
    {
        self::execute($con, "
            INSERT INTO feedback
                (session_id, mentee_id, mentor_id, rating, comment,
                 communication, efficiency, knowledge, skill,
                 note_communication, note_efficiency, note_knowledge, note_skill, note_overall,
                 created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ", 'iiidsiiiisssss', [
            $sessionId, $menteeId, $mentorId, $scores['rating'], $comment,
            $scores['communication'], $scores['efficiency'], $scores['knowledge'], $scores['skill'],
            $notes['communication'], $notes['efficiency'], $notes['knowledge'], $notes['skill'], $notes['rating'],
        ]);
    }

    /**
     * Stores a mentor's review of their mentee. $scores and $notes are keyed
     * 'preparedness', 'participation', 'communication', 'receptiveness' and
     * 'rating'. A second review of one session is refused by the database
     * (uniq_mentor_session), which throws.
     */
    public static function addMentorReview(mysqli $con, int $sessionId, int $mentorId, int $menteeId, array $scores, array $notes, string $comment): void
    {
        self::execute($con, "
            INSERT INTO mentee_reviews
                (session_id, mentor_id, mentee_id, rating, comment,
                 preparedness, participation, communication, receptiveness,
                 note_preparedness, note_participation, note_communication, note_receptiveness, note_overall,
                 created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ", 'iiidsiiiisssss', [
            $sessionId, $mentorId, $menteeId, $scores['rating'], $comment,
            $scores['preparedness'], $scores['participation'], $scores['communication'], $scores['receptiveness'],
            $notes['preparedness'], $notes['participation'], $notes['communication'], $notes['receptiveness'], $notes['rating'],
        ]);
    }

    // ── My Feedback pages ───────────────────────────────────────────────────

    /**
     * Every review the user has received, newest first, with the author's
     * name and the session's subject, date, length and type (the last two
     * null when the session had no slot): from mentees when $asMentor,
     * otherwise from mentors.
     */
    public static function receivedWithSessions(mysqli $con, int $userId, bool $asMentor): array
    {
        $sql = $asMentor
            ? "SELECT f.feedback_id, f.mentee_id, f.rating, f.comment, f.created_at,
                      f.communication, f.knowledge, f.efficiency, f.skill,
                      u.firstname, u.lastname,
                      sr.subject      AS s_subject,
                      sr.session_date AS s_date,
                      a.duration      AS s_duration,
                      a.session_type  AS s_type
               FROM feedback f
               JOIN users u ON u.user_id = f.mentee_id
               LEFT JOIN session_requests sr ON sr.request_id = f.session_id
               " . self::SLOT_JOIN . "
               WHERE f.mentor_id = ?
               ORDER BY f.created_at DESC"
            : "SELECT mr.review_id, mr.mentor_id, mr.rating, mr.comment, mr.created_at,
                      mr.preparedness, mr.participation, mr.communication, mr.receptiveness,
                      u.firstname, u.lastname,
                      sr.subject      AS s_subject,
                      sr.session_date AS s_date,
                      a.duration      AS s_duration,
                      a.session_type  AS s_type
               FROM mentee_reviews mr
               JOIN users u ON u.user_id = mr.mentor_id
               LEFT JOIN session_requests sr ON sr.request_id = mr.session_id
               " . self::SLOT_JOIN . "
               WHERE mr.mentee_id = ?
               ORDER BY mr.created_at DESC";
        return self::typedRows($con, $sql, 'i', [$userId]);
    }

    /** Sessions that have ended which the user has not reviewed yet. */
    public static function countAwaitingReview(mysqli $con, int $userId, bool $asMentor): int
    {
        $sql = $asMentor
            ? "SELECT COUNT(*) FROM session_requests sr " . self::SLOT_JOIN . "
               LEFT JOIN mentee_reviews mr ON mr.session_id = sr.request_id AND mr.mentor_id = sr.mentor_id
               WHERE sr.mentor_id = ? AND sr.status IN ('approved','completed')
                 AND DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) <= NOW()
                 AND mr.review_id IS NULL"
            : "SELECT COUNT(*) FROM session_requests sr " . self::SLOT_JOIN . "
               LEFT JOIN feedback f ON f.session_id = sr.request_id AND f.mentee_id = sr.mentee_id
               WHERE sr.mentee_id = ? AND sr.status IN ('approved','completed')
                 AND DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) <= NOW()
                 AND f.feedback_id IS NULL";
        return (int)self::value($con, $sql, 'i', [$userId]);
    }
}
