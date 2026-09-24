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
     * review count; their next open date ('next_available'); and how many
     * slots they have open from today ('slots_open') and within the coming
     * week ('slots_week').
     *
     * 'relevant' leads with the mentors who are actually free this week. A
     * perfect match on paper is no use to a mentee who cannot book them, and
     * the ranking used to bury someone with four slots tomorrow under
     * someone with none at all. Tag weight still decides the order within
     * each of those two groups, so the matching is not thrown away — it is
     * applied to the people who can actually take a session.
     *
     * A rolling seven days, not the calendar week: asked on a Saturday, "the
     * rest of this week" is an hour and a half and would rank almost
     * everybody as unavailable.
     */
    public static function page(mysqli $con, array $filters, string $sort, int $viewerId, int $limit, int $offset): array
    {
        [$where, $types, $args] = self::where($filters);

        $tagSelect = '0 AS tag_weight';
        // Bookable this week, then everything that already decided the order.
        $freeFirst = 'slots_week > 0 DESC, slots_open > 0 DESC';
        $relevant  = "$freeFirst, total_sessions DESC, avg_rating DESC, u.firstname ASC";
        if ($viewerId > 0) {
            $tagSelect = "COALESCE((
                    SELECT SUM(CASE mt.tag_type WHEN 'learn' THEN 3 WHEN 'skill' THEN 2 ELSE 1 END)
                    FROM user_tags mt
                    JOIN user_tags st
                      ON st.user_id = ? AND st.tag_type = mt.tag_type AND st.tag = mt.tag
                    WHERE mt.user_id = u.user_id
                ), 0) AS tag_weight";
            $relevant = "$freeFirst, tag_weight DESC, total_sessions DESC, avg_rating DESC, u.firstname ASC";
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
                   (SELECT COUNT(*) FROM availability av2 WHERE av2.mentor_id = u.user_id AND av2.date >= CURDATE()) AS slots_open,
                   (SELECT COUNT(*) FROM availability av3 WHERE av3.mentor_id = u.user_id
                      AND av3.date >= CURDATE() AND av3.date < CURDATE() + INTERVAL 7 DAY) AS slots_week,
                   $tagSelect
            " . self::FROM . "
            LEFT JOIN profile pr ON u.user_id = pr.user_id
            $where
            ORDER BY $order
            LIMIT ? OFFSET ?
        ", $types . 'ii', array_merge($args, [$limit, $offset]));
    }
}
