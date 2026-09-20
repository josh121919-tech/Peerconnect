<?php

/**
 * AdminUserRepository — what User Management and the admin's view of one
 * account read: the headline counts, the filtered list, the verification
 * queue and one account's figures.
 *
 * Sessions and ratings come from the same tables the member's own pages use,
 * so a number here always matches what that person sees.
 */
class AdminUserRepository extends Repository
{
    /** The list tabs that filter on role; the other list tabs filter on status. */
    public const ROLE_VIEWS = ['mentee', 'mentor', 'admin'];

    /**
     * Every headline figure in one query: accounts in total, by role and by
     * status; applications waiting for review; open reports; and accounts
     * created since the first of this month, in total and by role.
     */
    public static function headlineCounts(mysqli $con): array
    {
        $row = self::row($con, "
            SELECT COUNT(*)                                          AS all_users,
                   COALESCE(SUM(role = 'mentee'), 0)                 AS mentee,
                   COALESCE(SUM(role = 'mentor'), 0)                 AS mentor,
                   COALESCE(SUM(role = 'admin'), 0)                  AS admin,
                   COALESCE(SUM(status = 'restricted'), 0)           AS restricted,
                   COALESCE(SUM(status = 'blocked'), 0)              AS blocked,
                   COALESCE(SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0)                     AS joined_all,
                   COALESCE(SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND role = 'mentee'), 0) AS joined_mentee,
                   COALESCE(SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND role = 'mentor'), 0) AS joined_mentor,
                   COALESCE(SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND role = 'admin'), 0)  AS joined_admin,
                   (SELECT COUNT(*) FROM user_verifications WHERE status = 'pending')                    AS pending,
                   (SELECT COUNT(*) FROM reports WHERE status IN ('pending','urgent'))                   AS reported
            FROM users
        ");
        return array_map('intval', $row);
    }

    /**
     * The WHERE clause for the list. $view is a role tab, 'restricted',
     * 'blocked' or 'all'; $search matches name, username or email (its % and _
     * match only themselves); $status is 'active', 'restricted', 'blocked',
     * 'unverified' or ''.
     */
    private static function listWhere(string $view, string $search, string $status): array
    {
        $clauses = [];
        $types   = '';
        $args    = [];

        if (in_array($view, self::ROLE_VIEWS, true)) {
            $clauses[] = 'u.role = ?';
            $types .= 's';
            $args[] = $view;
        } elseif ($view === 'restricted' || $view === 'blocked') {
            $clauses[] = 'u.status = ?';
            $types .= 's';
            $args[] = $view;
        }

        if ($search !== '') {
            $clauses[] = "CONCAT_WS(' ', u.firstname, u.lastname, u.username, u.email) LIKE ?";
            $types .= 's';
            $args[] = '%' . addcslashes($search, '%_\\') . '%';
        }

        if ($status === 'unverified') {
            $clauses[] = 'u.verified = 0';
        } elseif ($status !== '') {
            $clauses[] = 'u.status = ?';
            $types .= 's';
            $args[] = $status;
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $types, $args];
    }

    /** How many accounts the list holds for these filters (see listWhere()). */
    public static function countMatching(mysqli $con, string $view, string $search, string $status): int
    {
        [$where, $types, $args] = self::listWhere($view, $search, $status);
        return (int)self::value($con, "SELECT COUNT(*) FROM users u $where", $types, $args);
    }

    /**
     * One page of the list, sorted 'newest', 'oldest', 'name' or 'role'. Each
     * row has the account, its photo, course and club, completed sessions and
     * both average ratings ('rating_as_mentor', 'rating_as_mentee').
     */
    public static function page(mysqli $con, string $view, string $search, string $status, string $sort, int $limit, int $offset): array
    {
        [$where, $types, $args] = self::listWhere($view, $search, $status);
        $order = [
            'newest' => 'u.created_at DESC',
            'oldest' => 'u.created_at ASC',
            'name'   => 'u.firstname ASC, u.lastname ASC',
            'role'   => 'u.role ASC, u.created_at DESC',
        ][$sort] ?? 'u.created_at DESC';

        return self::typedRows($con, "
            SELECT u.user_id, u.firstname, u.lastname, u.role, u.status, u.verified, u.created_at,
                   u.email,
                   p.profile_image,
                   -- Course and club are collected twice: once on the verification
                   -- form, once on the member's own profile. The profile row wins
                   -- because the member can keep it current, but it is blank for
                   -- anyone who has only ever filled in the verification form, so
                   -- fall back to what they actually submitted rather than showing
                   -- a dash for a field they did fill in.
                   --
                   -- Subqueries rather than a join: user_verifications.user_id is
                   -- only a plain index, so a second row for one user would
                   -- otherwise duplicate them in this list and make it disagree
                   -- with the count.
                   COALESCE(NULLIF(p.course, ''), NULLIF((
                       SELECT vc.course FROM user_verifications vc
                        WHERE vc.user_id = u.user_id
                        ORDER BY vc.verification_id DESC LIMIT 1), '')) AS course,
                   COALESCE(NULLIF(p.club, ''), NULLIF((
                       SELECT vb.club FROM user_verifications vb
                        WHERE vb.user_id = u.user_id
                        ORDER BY vb.verification_id DESC LIMIT 1), '')) AS club,
                   (SELECT COUNT(*) FROM session_requests sr
                     WHERE sr.status = 'completed'
                       AND (sr.mentee_id = u.user_id OR sr.mentor_id = u.user_id)) AS sessions,
                   (SELECT AVG(f.rating) FROM feedback f WHERE f.mentor_id = u.user_id)      AS rating_as_mentor,
                   (SELECT AVG(m.rating) FROM mentee_reviews m WHERE m.mentee_id = u.user_id) AS rating_as_mentee
            FROM users u
            LEFT JOIN profile p ON p.user_id = u.user_id
            $where
            ORDER BY $order
            LIMIT ? OFFSET ?
        ", $types . 'ii', array_merge($args, [$limit, $offset]));
    }

    /** Applications waiting for review, oldest first, with the applicant's name, role, email and photo. */
    public static function verificationQueue(mysqli $con): array
    {
        return self::rows($con, "
            SELECT v.*, u.firstname, u.lastname, u.role, u.email, p.profile_image
            FROM user_verifications v
            JOIN users u        ON u.user_id = v.user_id
            LEFT JOIN profile p ON p.user_id = u.user_id
            WHERE v.status = 'pending'
            ORDER BY v.submitted_at ASC
        ");
    }

    /**
     * The two queues waiting on an admin, as ints: 'verifications' (pending
     * applications) and 'reports' (pending or urgent reports).
     */
    public static function queueCounts(mysqli $con): array
    {
        $row = self::row($con, "
            SELECT (SELECT COUNT(*) FROM user_verifications WHERE status = 'pending')     AS verifications,
                   (SELECT COUNT(*) FROM reports WHERE status IN ('pending','urgent'))    AS reports
        ");
        return ['verifications' => (int)$row['verifications'], 'reports' => (int)$row['reports']];
    }

    /** The oldest applications waiting, for the admin bell: verification_id, submitted_at, 'who', role. */
    public static function oldestPendingVerifications(mysqli $con, int $limit): array
    {
        return self::rows($con, "
            SELECT v.verification_id, v.submitted_at,
                   COALESCE(NULLIF(v.full_name, ''), CONCAT_WS(' ', u.firstname, u.lastname)) AS who,
                   u.role
            FROM user_verifications v
            JOIN users u ON u.user_id = v.user_id
            WHERE v.status = 'pending'
            ORDER BY v.submitted_at ASC
            LIMIT ?
        ", 'i', [$limit]);
    }

    // ── One account ─────────────────────────────────────────────────────────

    /** The account with its profile row, or null when there is no such account. */
    public static function account(mysqli $con, int $userId): ?array
    {
        return self::typedRow($con, "
            SELECT u.user_id, u.firstname, u.middlename, u.lastname, u.suffix, u.username,
                   u.role, u.status, u.verified, u.created_at,
                   u.email,
                   p.full_name, p.student_id, p.course, p.year_level, p.section, p.club,
                   p.profile_image, p.phone, p.location, p.bio, p.visibility, p.onboarded_at
            FROM users u
            LEFT JOIN profile p ON p.user_id = u.user_id
            WHERE u.user_id = ?
            LIMIT 1
        ", 'i', [$userId]);
    }

    /** The account's questionnaire tags ('tag_type', 'tag'), by type then tag. */
    public static function tags(mysqli $con, int $userId): array
    {
        return self::typedRows($con, "SELECT tag_type, tag FROM user_tags WHERE user_id = ? ORDER BY tag_type, tag", 'i', [$userId]);
    }

    /**
     * Sessions completed ('done') and booked in any state ('all'), and the
     * average rating and number of ratings the account has received — from
     * mentees when $asMentor, otherwise from mentors ('rating' is null when
     * there are none).
     */
    public static function activityFigures(mysqli $con, int $userId, bool $asMentor): array
    {
        $ratings = $asMentor
            ? "(SELECT AVG(rating) FROM feedback WHERE mentor_id = ?) AS rating,
               (SELECT COUNT(*) FROM feedback WHERE mentor_id = ?) AS rating_n"
            : "(SELECT AVG(rating) FROM mentee_reviews WHERE mentee_id = ?) AS rating,
               (SELECT COUNT(*) FROM mentee_reviews WHERE mentee_id = ?) AS rating_n";
        $row = self::typedRow($con, "
            SELECT (SELECT COUNT(*) FROM session_requests WHERE status = 'completed' AND (mentee_id = ? OR mentor_id = ?)) AS done,
                   (SELECT COUNT(*) FROM session_requests WHERE mentee_id = ? OR mentor_id = ?) AS all_sessions,
                   $ratings
        ", 'iiiiii', [$userId, $userId, $userId, $userId, $userId, $userId]);
        return [
            'done'     => (int)$row['done'],
            'all'      => (int)$row['all_sessions'],
            'rating'   => $row['rating'],
            'rating_n' => (int)$row['rating_n'],
        ];
    }

    /** The account's newest $limit sessions from either side, with the other person's name and role. */
    public static function recentSessions(mysqli $con, int $userId, int $limit): array
    {
        return self::typedRows($con, "
            SELECT sr.request_id, sr.subject, sr.session_date, sr.status,
                   sr.mentee_id, sr.mentor_id,
                   CONCAT_WS(' ', o.firstname, o.lastname) AS other_name, o.role AS other_role
            FROM session_requests sr
            JOIN users o ON o.user_id = CASE WHEN sr.mentee_id = ? THEN sr.mentor_id ELSE sr.mentee_id END
            WHERE sr.mentee_id = ? OR sr.mentor_id = ?
            ORDER BY sr.session_date DESC
            LIMIT ?
        ", 'iiii', [$userId, $userId, $userId, $limit]);
    }

    /**
     * The newest $limit ratings written about the account — by mentees when
     * $asMentor, otherwise by mentors — with the author's name.
     */
    public static function reviewsAbout(mysqli $con, int $userId, bool $asMentor, int $limit): array
    {
        return $asMentor
            ? self::typedRows($con, "
                SELECT f.rating, f.comment, f.created_at, CONCAT_WS(' ', w.firstname, w.lastname) AS author
                FROM feedback f LEFT JOIN users w ON w.user_id = f.mentee_id
                WHERE f.mentor_id = ? ORDER BY f.created_at DESC LIMIT ?
            ", 'ii', [$userId, $limit])
            : self::typedRows($con, "
                SELECT m.rating, m.comment, m.created_at, CONCAT_WS(' ', w.firstname, w.lastname) AS author
                FROM mentee_reviews m LEFT JOIN users w ON w.user_id = m.mentor_id
                WHERE m.mentee_id = ? ORDER BY m.created_at DESC LIMIT ?
            ", 'ii', [$userId, $limit]);
    }

    /** Every badge the account holds, newest first ('name', 'description', 'awarded_at'). */
    public static function badges(mysqli $con, int $userId): array
    {
        return self::typedRows($con, "
            SELECT b.name, b.description, ub.awarded_at
            FROM user_badges ub JOIN badges b ON b.badge_id = ub.badge_id
            WHERE ub.user_id = ? ORDER BY ub.awarded_at DESC
        ", 'i', [$userId]);
    }
}
