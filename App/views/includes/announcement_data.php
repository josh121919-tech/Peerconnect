<?php

/**
 * announcement_data.php — announcements, shared by the admin and member sides.
 *
 * Both sides have to agree on two things or the feature is a lie: which
 * announcements a given person is allowed to see, and what "reach" means.
 *
 * Reach is measured, never estimated. `announcement_reads` records one row the
 * first time a person actually opens an announcement, so "read by 12 of 40"
 * is a count of rows rather than a guess at how many people were online. The
 * reference design showed a "views" figure; this is that figure, honestly
 * sourced.
 *
 * Scheduling needs no cron. A row sits at status 'scheduled' with a future
 * publish_at; the moment that time passes, the next request that touches
 * announcements promotes it (pc_ann_release). That keeps the two states from
 * drifting apart the way restrictions once did.
 */

if (!function_exists('pc_ann_categories')) {
    /**
     * The categories an announcement can carry. A fixed list rather than free
     * text, so the admin's filter and the member's badge always agree, and so
     * a typo cannot create a category of one.
     */
    function pc_ann_categories(): array
    {
        return [
            'System'      => ['#A6301F', '#FBE5E1'],
            'Mentorship'  => ['#1A5C9A', '#EAF1FB'],
            'Event'       => ['#17654B', '#E6F5EE'],
            'Guidelines'  => ['#B7791F', '#FEF6DC'],
            'Community'   => ['#6B21A8', '#F3E8FF'],
            'Opportunity' => ['#087FC1', '#EAF6FC'],
            'General'     => ['#565B66', '#F3F4F6'],
        ];
    }
}

if (!function_exists('pc_ann_category_color')) {
    function pc_ann_category_color(string $c): array
    {
        $all = pc_ann_categories();
        return $all[$c] ?? $all['General'];
    }
}

if (!function_exists('pc_ann_audiences')) {
    function pc_ann_audiences(): array
    {
        return ['all' => 'Everyone', 'mentee' => 'Mentees only', 'mentor' => 'Mentors only'];
    }
}

if (!function_exists('pc_ann_club_enabled')) {
    /**
     * Whether announcement_club.sql has been run.
     *
     * Local and live are migrated at different moments, so every query that
     * touches audience_club asks this first. Without the column the feature
     * is simply absent and announcements behave exactly as they always did,
     * rather than every page dying on an unknown column. The same guard
     * AssessmentRepository::audienceEnabled() uses, for the same reason.
     *
     * Answered once per request: this is read on nearly every page load.
     */
    function pc_ann_club_enabled(mysqli $con): bool
    {
        static $has = null;
        if ($has === null) {
            $r = $con->query("SHOW COLUMNS FROM announcements LIKE 'audience_club'");
            $has = (bool)($r && $r->num_rows);
        }
        return $has;
    }
}

if (!function_exists('pc_ann_club_col')) {
    /**
     * ", a.audience_club" when the column exists, nothing when it does not.
     *
     * For the handful of queries that name their columns rather than using
     * a.* — naming a column that is not there is a fatal error, and this
     * feature has to be harmless on a database the migration has not reached.
     */
    function pc_ann_club_col(mysqli $con, string $alias = 'a'): string
    {
        return pc_ann_club_enabled($con) ? ", $alias.audience_club" : '';
    }
}

if (!function_exists('pc_ann_clubs')) {
    /**
     * The clubs an announcement can be aimed at: the school's nine.
     *
     * PC_CLUBS, the same fixed list the signup and verification forms offer
     * and that Find a Mentor and Resources filter by — so the taxonomy has one
     * definition instead of drifting copies. It was briefly the clubs people
     * were actually in, which meant a club with no members yet could not be
     * announced to at all: exactly when you would want to.
     *
     * $keep is added when it is not in the list, so editing an older
     * announcement cannot silently drop the club it was addressed to.
     */
    function pc_ann_clubs(mysqli $con, ?string $keep = null): array
    {
        $clubs = PC_CLUBS;
        if ($keep !== null && $keep !== '' && !in_array($keep, $clubs, true)) {
            $clubs[] = $keep;
        }
        sort($clubs);
        return $clubs;
    }
}

if (!function_exists('pc_ann_release')) {
    /**
     * Promote anything whose scheduled time has arrived.
     *
     * Called by every page that reads announcements, so a scheduled post goes
     * live on the first request after its time — no cron, and no window where
     * the admin list says "published" while members still cannot see it.
     *
     * Returns the ids it promoted, so the caller can notify their audience.
     */
    function pc_ann_release(mysqli $con): array
    {
        $due = [];
        $q = $con->query("
            SELECT announcement_id FROM announcements
            WHERE status = 'scheduled' AND publish_at IS NOT NULL AND publish_at <= NOW()
        ");
        if (!$q) return [];
        while ($r = $q->fetch_row()) $due[] = (int)$r[0];
        if (!$due) return [];

        $con->query("
            UPDATE announcements
               SET status = 'published', published_at = COALESCE(published_at, publish_at)
             WHERE status = 'scheduled' AND publish_at IS NOT NULL AND publish_at <= NOW()
        ");
        return $due;
    }
}

if (!function_exists('pc_ann_visible')) {
    /**
     * The condition for "this person may see it", with the values it binds.
     *
     * Published, aimed at everyone or at their role, and — when a club was
     * chosen — aimed at a club they are in. Drafts, scheduled and archived
     * posts never reach a member.
     *
     * Returns [sql, types, args] rather than just the SQL, because the clause
     * now binds a different number of values depending on whether the club
     * column exists. Six places ask this question, and the previous shape let
     * each one decide for itself what to bind: get that wrong and the query
     * either fails or, worse, silently matches the wrong rows. Handing back
     * the bindings with the clause makes them impossible to get out of step.
     *
     * A member with no club is treated as being in no club: club-targeted
     * announcements do not reach them, which is what "only this club" means.
     */
    function pc_ann_visible(mysqli $con, string $role, ?string $club, string $alias = 'a'): array
    {
        $sql   = "$alias.status = 'published' AND ($alias.audience = 'all' OR $alias.audience = ?)";
        $types = 's';
        $args  = [$role];

        if (pc_ann_club_enabled($con)) {
            // NULL means "no club filter", so it is visible to everyone the
            // audience already covers.
            $sql .= " AND ($alias.audience_club IS NULL OR $alias.audience_club = ?)";
            $types .= 's';
            $args[] = (string)($club ?? '');
        }

        return [$sql, $types, $args];
    }
}

if (!function_exists('pc_ann_club_of')) {
    /** The club this member is in, or null. Read once per request. */
    function pc_ann_club_of(mysqli $con, int $userId): ?string
    {
        static $cache = [];
        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }
        $st = $con->prepare("SELECT TRIM(club) AS club FROM profile WHERE user_id = ?");
        $st->bind_param('i', $userId);
        $st->execute();
        $club = $st->get_result()->fetch_assoc()['club'] ?? '';
        $st->close();
        return $cache[$userId] = ($club === '' ? null : $club);
    }
}

if (!function_exists('pc_ann_unread_count')) {
    /** How many published announcements this person has not opened. */
    function pc_ann_unread_count(mysqli $con, int $user_id, string $role): int
    {
        // The same visibility rule as the list. An unread badge counting
        // something the member cannot open is worse than no badge.
        [$vis, $vTypes, $vArgs] = pc_ann_visible($con, $role, pc_ann_club_of($con, $user_id), 'a');

        $st = $con->prepare("
            SELECT COUNT(*) c
            FROM announcements a
            LEFT JOIN announcement_reads r
                   ON r.announcement_id = a.announcement_id AND r.user_id = ?
            WHERE " . $vis . "
              AND r.user_id IS NULL
        ");
        if (!$st) return 0;
        $st->bind_param('i' . $vTypes, $user_id, ...$vArgs);
        $st->execute();
        $n = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
        $st->close();
        return $n;
    }
}

if (!function_exists('pc_ann_recipient_filter')) {
    /**
     * Who an announcement is addressed to, as a WHERE fragment over
     * `users u LEFT JOIN profile p`.
     *
     * One definition, used by both pc_ann_send() — who gets the notification —
     * and pc_ann_audience_size() — the denominator its reach is measured
     * against. Two copies of this would let an announcement be sent to one set
     * of people and scored against another, and the reach figure is the only
     * number on that page anybody trusts.
     *
     * Blocked accounts are excluded everywhere: they cannot sign in, so
     * counting them would make every announcement look unread.
     *
     * @return array [sql, types, args]
     */
    function pc_ann_recipient_filter(string $audience, ?string $club): array
    {
        $sql   = "u.status <> 'blocked'";
        $types = '';
        $args  = [];

        if ($audience === 'all') {
            $sql .= " AND u.role IN ('mentee','mentor')";
        } else {
            $sql .= " AND u.role = ?";
            $types .= 's';
            $args[] = $audience;
        }

        if ($club !== null && $club !== '') {
            $sql .= " AND TRIM(p.club) = ?";
            $types .= 's';
            $args[] = $club;
        }

        return [$sql, $types, $args];
    }
}

if (!function_exists('pc_ann_audience_size')) {
    /**
     * How many people an announcement is actually addressed to — the
     * denominator for its reach. Blocked accounts are excluded: they cannot
     * sign in, so counting them would make every announcement look unread.
     */
    function pc_ann_audience_size(mysqli $con, string $audience, ?string $club = null): int
    {
        // A club announcement is addressed to that club, so that is the
        // denominator. Reading it against everybody would make a post that
        // reached its whole club look like it had failed.
        [$where, $types, $args] = pc_ann_recipient_filter($audience, $club);

        $st = $con->prepare("
            SELECT COUNT(*) c
            FROM users u
            LEFT JOIN profile p ON p.user_id = u.user_id
            WHERE $where
        ");
        if (!$st) return 0;
        if ($types !== '') {
            $st->bind_param($types, ...$args);
        }
        $st->execute();
        $n = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
        $st->close();
        return $n;
    }
}

if (!function_exists('pc_ann_state')) {
    /** Label and colour for a row's state. */
    function pc_ann_state(array $a): array
    {
        switch ($a['status']) {
            case 'published': return ['Published', '#17654B', '#E6F5EE'];
            case 'scheduled': return ['Scheduled', '#B7791F', '#FEF6DC'];
            case 'archived':  return ['Archived',  '#565B66', '#F3F4F6'];
            default:          return ['Draft',     '#1A5C9A', '#EAF1FB'];
        }
    }
}

if (!function_exists('pc_ann_ago')) {
    function pc_ann_ago(?string $when): string
    {
        if (!$when) return '—';
        $d = time() - strtotime($when);
        if ($d < 0)      return 'in ' . (abs($d) < 3600 ? max(1, intdiv(abs($d), 60)) . 'm' : (abs($d) < 86400 ? intdiv(abs($d), 3600) . 'h' : intdiv(abs($d), 86400) . 'd'));
        if ($d < 60)     return 'Just now';
        if ($d < 3600)   return intdiv($d, 60) . 'm ago';
        if ($d < 86400)  return intdiv($d, 3600) . 'h ago';
        if ($d < 604800) return intdiv($d, 86400) . 'd ago';
        return date('M j, Y', strtotime($when));
    }
}

if (!function_exists('pc_ann_send')) {
    /**
     * Notify everyone an announcement is addressed to.
     *
     * Both paths to going live need this — an admin pressing Publish, and a
     * scheduled post releasing itself on whichever request arrives first — so
     * it lives here rather than in either one of them.
     *
     * Blocked accounts are skipped: they cannot sign in to read it.
     */
    function pc_ann_send(mysqli $con, int $id, string $title, string $audience, ?string $club = null): int
    {
        require_once __DIR__ . '/../../services/NotificationService.php';
        $link = url('announcements') . '?open=' . $id;

        // The same filter the reach figure counts against, so "sent to 12"
        // and "read by 5 of 12" are talking about the same twelve people.
        [$where, $types, $args] = pc_ann_recipient_filter($audience, $club);

        $st = $con->prepare("
            SELECT u.user_id
            FROM users u
            LEFT JOIN profile p ON p.user_id = u.user_id
            WHERE $where
        ");
        if (!$st) return 0;
        if ($types !== '') {
            $st->bind_param($types, ...$args);
        }
        $st->execute();
        $res = $st->get_result();

        $n = 0;
        while ($res && ($r = $res->fetch_assoc())) {
            NotificationService::send($con, (int)$r['user_id'], 'announcement', 'New Announcement', $title, $link);
            $n++;
        }
        return $n;
    }
}
