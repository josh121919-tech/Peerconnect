<?php

/**
 * MentorDirectoryRepository — the Find a Mentor list: bookable mentors,
 * filtered and sorted, a page at a time.
 *
 * A mentor's club, course and expertise come from their latest verification
 * application. Joining every application listed a mentor who had applied
 * twice twice, and counted them twice.
 */
class MentorDirectoryRepository extends Repository
{
    private const FROM = "
        FROM users u
        LEFT JOIN user_verifications p ON p.verification_id = (
            SELECT MAX(v2.verification_id) FROM user_verifications v2 WHERE v2.user_id = u.user_id)";

    /**
     * The WHERE clause. $filters: 'search' (name or expertise; % and _ match
     * only themselves), 'expertise' (a stem the expertise text must contain),
     * 'club', 'session_type' ('1v1' or 'group'). Empty values are ignored.
     */
    private static function where(array $filters): array
    {
        $clauses = [
            // Active, verified mentors — the same rule the booking endpoints
            // apply, so nobody listed here is refused on booking and nobody
            // hidden can be booked.
            UserRepository::bookableMentorCondition('u'),
            // Settings → Account → Profile Visibility. A mentor set to
            // "private" is not listed; mentees they already work with keep
            // their sessions.
            "COALESCE((SELECT pf.visibility FROM profile pf WHERE pf.user_id = u.user_id), 'everyone') <> 'private'",
        ];
        $types = '';
        $args  = [];

        if (($filters['search'] ?? '') !== '') {
            // The full name as well as each part: searching "Ana Cruz" found
            // nobody when first and last name were checked one at a time.
            $clauses[] = "(CONCAT_WS(' ', u.firstname, u.lastname) LIKE ? OR p.expertise LIKE ?)";
            $like = '%' . addcslashes($filters['search'], '%_\\') . '%';
            $types .= 'ss';
            array_push($args, $like, $like);
        }
        if (($filters['expertise'] ?? '') !== '') {
            $clauses[] = 'p.expertise LIKE ?';
            $types .= 's';
            $args[] = '%' . $filters['expertise'] . '%';
        }
        if (($filters['club'] ?? '') !== '') {
            $clauses[] = 'p.club = ?';
            $types .= 's';
            $args[] = $filters['club'];
        }
        if (($filters['session_type'] ?? '') !== '') {
            $clauses[] = 'EXISTS (SELECT 1 FROM availability av WHERE av.mentor_id = u.user_id AND av.session_type = ?)';
            $types .= 's';
            $args[] = $filters['session_type'];
        }
        return ['WHERE ' . implode(' AND ', $clauses), $types, $args];
    }

    /** How many mentors match $filters (see where()). */
    public static function count(mysqli $con, array $filters): int
    {
        [$where, $types, $args] = self::where($filters);
        return (int)self::value($con, "SELECT COUNT(*) AS total " . self::FROM . " $where", $types, $args);
    }

    /**
     * One page of mentors. $sort is 'relevant', 'rating' or 'sessions'. With a
     * $viewerId (a mentee who has answered the questionnaire), 'relevant'
     * puts mentors who share their answers first, weighted learn 3 / skill 2
     * / interest 1 ('tag_weight'); otherwise tag_weight is 0.
     *
     * Each row: the mentor, their club, expertise, course and photo; completed
     * sessions ('total_sessions'); mentees they have completed a session with
     * ('mentee_count'), the rule their profile page uses; average rating and
     * review count; their next open date ('next_available').
     */
    public static function page(mysqli $con, array $filters, string $sort, int $viewerId, int $limit, int $offset): array
    {
        [$where, $types, $args] = self::where($filters);

        $tagSelect = '0 AS tag_weight';
        $relevant  = 'total_sessions DESC, avg_rating DESC, u.firstname ASC';
        if ($viewerId > 0) {
            $tagSelect = "COALESCE((
                    SELECT SUM(CASE mt.tag_type WHEN 'learn' THEN 3 WHEN 'skill' THEN 2 ELSE 1 END)
                    FROM user_tags mt
                    JOIN user_tags st
                      ON st.user_id = ? AND st.tag_type = mt.tag_type AND st.tag = mt.tag
                    WHERE mt.user_id = u.user_id
                ), 0) AS tag_weight";
            $relevant = "tag_weight DESC, $relevant";
            $types = 'i' . $types;
            array_unshift($args, $viewerId);
        }
        $order = [
            'relevant' => $relevant,
            'rating'   => 'avg_rating DESC, total_reviews DESC, u.firstname ASC',
            'sessions' => 'total_sessions DESC, u.firstname ASC',
        ][$sort] ?? $relevant;

        return self::typedRows($con, "
            SELECT u.user_id, u.firstname, u.lastname, u.verified, u.status,
                   p.club, p.expertise, p.course, pr.profile_image,
                   (SELECT COUNT(*) FROM session_requests sr WHERE sr.mentor_id = u.user_id AND sr.status = 'completed') AS total_sessions,
                   (SELECT COUNT(DISTINCT sr2.mentee_id) FROM session_requests sr2 WHERE sr2.mentor_id = u.user_id AND sr2.status = 'completed') AS mentee_count,
                   (SELECT ROUND(AVG(f.rating),1) FROM feedback f WHERE f.mentor_id = u.user_id) AS avg_rating,
                   (SELECT COUNT(*) FROM feedback f2 WHERE f2.mentor_id = u.user_id) AS total_reviews,
                   (SELECT MIN(av.date) FROM availability av WHERE av.mentor_id = u.user_id AND av.date >= CURDATE()) AS next_available,
                   $tagSelect
            " . self::FROM . "
            LEFT JOIN profile pr ON u.user_id = pr.user_id
            $where
            ORDER BY $order
            LIMIT ? OFFSET ?
        ", $types . 'ii', array_merge($args, [$limit, $offset]));
    }
}
