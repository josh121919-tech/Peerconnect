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
            'Opportunity' => ['#0087CF', '#EAF6FB'],
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

if (!function_exists('pc_ann_visible_sql')) {
    /**
     * The condition for "this person may see it": published, and aimed at
     * everyone or at their role. Drafts, scheduled and archived posts never
     * reach a member.
     */
    function pc_ann_visible_sql(string $alias = 'a'): string
    {
        return "$alias.status = 'published' AND ($alias.audience = 'all' OR $alias.audience = ?)";
    }
}

if (!function_exists('pc_ann_unread_count')) {
    /** How many published announcements this person has not opened. */
    function pc_ann_unread_count(mysqli $con, int $user_id, string $role): int
    {
        $st = $con->prepare("
            SELECT COUNT(*) c
            FROM announcements a
            LEFT JOIN announcement_reads r
                   ON r.announcement_id = a.announcement_id AND r.user_id = ?
            WHERE " . pc_ann_visible_sql('a') . "
              AND r.user_id IS NULL
        ");
        if (!$st) return 0;
        $st->bind_param('is', $user_id, $role);
        $st->execute();
        $n = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
        $st->close();
        return $n;
    }
}

if (!function_exists('pc_ann_audience_size')) {
    /**
     * How many people an announcement is actually addressed to — the
     * denominator for its reach. Blocked accounts are excluded: they cannot
     * sign in, so counting them would make every announcement look unread.
     */
    function pc_ann_audience_size(mysqli $con, string $audience): int
    {
        if ($audience === 'all') {
            $sql = "SELECT COUNT(*) c FROM users WHERE role IN ('mentee','mentor') AND status <> 'blocked'";
            $r = $con->query($sql);
            return $r ? (int)$r->fetch_assoc()['c'] : 0;
        }
        $st = $con->prepare("SELECT COUNT(*) c FROM users WHERE role = ? AND status <> 'blocked'");
        $st->bind_param('s', $audience);
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
    function pc_ann_send(mysqli $con, int $id, string $title, string $audience): int
    {
        require_once __DIR__ . '/../../services/NotificationService.php';
        $link = url('announcements') . '?open=' . $id;

        if ($audience === 'all') {
            $res = $con->query("SELECT user_id FROM users WHERE role IN ('mentee','mentor') AND status <> 'blocked'");
        } else {
            $st = $con->prepare("SELECT user_id FROM users WHERE role = ? AND status <> 'blocked'");
            $st->bind_param('s', $audience);
            $st->execute();
            $res = $st->get_result();
        }

        $n = 0;
        while ($res && ($r = $res->fetch_assoc())) {
            NotificationService::send($con, (int)$r['user_id'], 'announcement', 'New Announcement', $title, $link);
            $n++;
        }
        return $n;
    }
}
