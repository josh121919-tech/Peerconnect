<?php

/**
 * report_data.php — the figures behind Reports & Analytics.
 *
 * The summary page and its CSV export have to agree to the last digit, so
 * every number is computed here once and both read the same array.
 *
 * The rule this file follows: a figure appears only if a table in this
 * database can answer it. There is no page-view tracking, no uptime monitor
 * and no session timer in this app, so "average time on site", "pages per
 * visit" and "uptime %" are not computed here under a plausible-looking
 * formula — they are absent, and the page says why. Everything below is a
 * count of rows that exist.
 */

if (defined('AD_REPORT_DATA')) return;
define('AD_REPORT_DATA', true);

/** Ranges the page offers. Keys are what appears in the query string. */
function rp_presets(): array
{
    return [
        '7d'    => 'Last 7 days',
        '30d'   => 'Last 30 days',
        '90d'   => 'Last 90 days',
        'month' => 'This month',
        'year'  => 'This year',
        'all'   => 'All time',
    ];
}

/**
 * Resolve the chosen range, plus the window immediately before it.
 *
 * The previous window is the same length and ends the day before this one
 * starts, which is what makes "vs the previous 30 days" an honest comparison
 * rather than a comparison against a month of a different size.
 */
function rp_range(mysqli $con, array $get): array
{
    $key = (string)($get['range'] ?? '30d');
    $cf  = trim((string)($get['from'] ?? ''));
    $ct  = trim((string)($get['to'] ?? ''));

    // A custom range wins, but only when both ends parse — a half-filled form
    // should fall back to a preset rather than to a silently open-ended query.
    if ($cf !== '' && $ct !== '' && strtotime($cf) && strtotime($ct)) {
        $from = date('Y-m-d', strtotime($cf));
        $to   = date('Y-m-d', strtotime($ct));
        if ($from > $to) { $t = $from; $from = $to; $to = $t; }
        $key  = 'custom';
    } else {
        if (!array_key_exists($key, rp_presets())) $key = '30d';
        switch ($key) {
            case '7d':    $from = date('Y-m-d', strtotime('-6 days'));  $to = date('Y-m-d'); break;
            case '90d':   $from = date('Y-m-d', strtotime('-89 days')); $to = date('Y-m-d'); break;
            case 'month': $from = date('Y-m-01');                       $to = date('Y-m-d'); break;
            case 'year':  $from = date('Y-01-01');                      $to = date('Y-m-d'); break;
            case 'all':
                // "All time" still needs real edges to draw a chart against.
                $r = $con->query("SELECT MIN(DATE(created_at)) FROM users");
                $v = $r ? $r->fetch_row() : null;
                $from = ($v && $v[0]) ? $v[0] : date('Y-m-d');
                $to   = date('Y-m-d');
                break;
            default:      $from = date('Y-m-d', strtotime('-29 days')); $to = date('Y-m-d'); $key = '30d';
        }
    }

    $days     = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
    $prevTo   = date('Y-m-d', strtotime($from . ' -1 day'));
    $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));

    $label = $key === 'all'
        ? 'All time'
        : date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));

    return [
        'key'       => $key,
        'from'      => $from,
        'to'        => $to,
        'days'      => $days,
        'label'     => $label,
        // All time has nothing before it, so nothing is compared against it.
        'prev'      => $key === 'all' ? null : [$prevFrom, $prevTo],
        'prevLabel' => 'vs previous ' . $days . ' day' . ($days === 1 ? '' : 's'),
    ];
}

/**
 * How to slice the range for the charts.
 *
 * A year of daily bars is unreadable and a week of monthly ones is a single
 * column, so the bucket follows the length of the range.
 */
function rp_buckets(string $from, string $to): array
{
    $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;

    if ($days <= 45) {
        $unit = 'day';
        $sql  = 'DATE(%1$s)';
    } elseif ($days <= 220) {
        $unit = 'week';
        $sql  = 'DATE(DATE_SUB(%1$s, INTERVAL WEEKDAY(%1$s) DAY))';
    } else {
        $unit = 'month';
        $sql  = "DATE_FORMAT(%1\$s, '%%Y-%%m-01')";
    }

    // Every bucket in the range, empty ones included, so a quiet week draws as
    // a gap rather than being skipped and shifting the line along.
    $keys = [];
    if ($unit === 'month')     $cur = date('Y-m-01', strtotime($from));
    elseif ($unit === 'week')  $cur = date('Y-m-d', strtotime('monday this week', strtotime($from)));
    else                       $cur = $from;

    $end  = strtotime($to);
    $step = ['day' => '+1 day', 'week' => '+1 week', 'month' => '+1 month'][$unit];

    while (strtotime($cur) <= $end && count($keys) < 400) {
        if ($unit === 'month')     $keys[$cur] = date('M Y', strtotime($cur));
        else                       $keys[$cur] = date('M j', strtotime($cur));
        $cur = date('Y-m-d', strtotime($cur . ' ' . $step));
    }

    return ['unit' => $unit, 'sql' => $sql, 'keys' => $keys];
}

/** The bucket expression for one column, e.g. rp_bucket_expr($b, 'u.created_at'). */
function rp_bucket_expr(array $b, string $col): string
{
    return sprintf($b['sql'], $col);
}

/** One scalar, or null when the query could not run. */
function rp_one(mysqli $con, string $sql)
{
    $r = $con->query($sql);
    if (!$r) return null;
    $row = $r->fetch_row();
    return $row ? $row[0] : null;
}

function rp_int(mysqli $con, string $sql): int
{
    return (int)rp_one($con, $sql);
}

/**
 * Change between two periods.
 *
 * Returns 'new' rather than a percentage when the earlier window was empty —
 * a percentage off a base of zero is not a measurement.
 */
function rp_delta(int $now, ?int $before): ?array
{
    if ($before === null) return null;
    if ($before === 0)    return $now > 0 ? ['new', null] : ['flat', 0];
    $pct = ($now - $before) / $before * 100;
    if (abs($pct) < 0.5)  return ['flat', 0];
    return [$pct > 0 ? 'up' : 'down', (int)round($pct)];
}

/** How a delta reads on a tile. */
function rp_delta_text(?array $d, string $label): string
{
    if ($d === null)      return 'No earlier period to compare';
    if ($d[0] === 'new')  return 'None in the previous period';
    if ($d[0] === 'flat') return 'Unchanged ' . $label;
    return ($d[0] === 'up' ? '+' : '') . $d[1] . '% ' . $label;
}

/**
 * The distinct members who did anything at all in a window.
 *
 * "Active" here means they left a trace: signed in, sat in a session, sent a
 * message, submitted an assessment or wrote feedback. It is not a guess at
 * who opened the app.
 */
function rp_active_sql(string $from, string $to): string
{
    return "
        SELECT COUNT(*) FROM (
            SELECT u.user_id FROM users u
              JOIN logs l ON l.email = u.email
             WHERE l.activity LIKE '%login%' AND l.log_date >= '$from 00:00:00' AND l.log_date < '$to' + INTERVAL 1 DAY
            UNION
            SELECT mentee_id FROM session_requests WHERE DATE(session_date) BETWEEN '$from' AND '$to'
            UNION
            SELECT mentor_id FROM session_requests WHERE DATE(session_date) BETWEEN '$from' AND '$to'
            UNION
            SELECT sender_id FROM messages WHERE DATE(created_at) BETWEEN '$from' AND '$to'
            UNION
            SELECT mentee_id FROM assessment_attempts
             WHERE submitted_at IS NOT NULL AND DATE(submitted_at) BETWEEN '$from' AND '$to'
            UNION
            SELECT mentee_id FROM feedback WHERE DATE(created_at) BETWEEN '$from' AND '$to'
        ) t
        JOIN users mu ON mu.user_id = t.user_id
        -- Admins are staff, not members, and their sign-ins would inflate the
        -- figure on a small platform. The join also drops ids left behind by
        -- deleted accounts, so this counts accounts that still exist.
        WHERE mu.role <> 'admin'";
}

/**
 * Every headline figure, for one window.
 *
 * Kept separate so the previous window is measured by exactly the same
 * queries, rather than a second hand-written set that might count something
 * slightly differently and make the comparison meaningless.
 */
function rp_window(mysqli $con, string $from, string $to): array
{
    return [
        // Admins are staff. Counting a new admin account as a new member
        // would make the headline figure disagree with the growth chart and
        // with the member total on the storage card.
        'joined'      => rp_int($con, "SELECT COUNT(*) FROM users WHERE role <> 'admin' AND DATE(created_at) BETWEEN '$from' AND '$to'"),
        'active'      => rp_int($con, rp_active_sql($from, $to)),
        'sessions'    => rp_int($con, "SELECT COUNT(*) FROM session_requests WHERE DATE(session_date) BETWEEN '$from' AND '$to'"),
        'completed'   => rp_int($con, "SELECT COUNT(*) FROM session_requests WHERE status='completed' AND DATE(session_date) BETWEEN '$from' AND '$to'"),
        'assessments' => rp_int($con, "SELECT COUNT(*) FROM assessment_attempts WHERE status='submitted' AND DATE(submitted_at) BETWEEN '$from' AND '$to'"),
        'messages'    => rp_int($con, "SELECT COUNT(*) FROM messages WHERE DATE(created_at) BETWEEN '$from' AND '$to'"),
        'feedback'    => rp_int($con, "SELECT COUNT(*) FROM feedback WHERE DATE(created_at) BETWEEN '$from' AND '$to'"),
        'resources'   => rp_int($con, "SELECT COUNT(*) FROM resources WHERE DATE(created_at) BETWEEN '$from' AND '$to'"),
        'signins'     => rp_int($con, "SELECT COUNT(*) FROM logs WHERE activity LIKE '%login%' AND log_date >= '$from 00:00:00' AND log_date < '$to' + INTERVAL 1 DAY"),
    ];
}

/**
 * What people actually did in the range, by area of the app.
 *
 * This counts records created, not screens visited. Nothing in this install
 * records a page view, so a "most used features" chart would be invented; a
 * count of the things people made is the closest measurement the data can
 * honestly support, and it is labelled as that.
 */
function rp_areas(mysqli $con, string $from, string $to): array
{
    $w = fn(string $t, string $c, string $extra = '') =>
        rp_int($con, "SELECT COUNT(*) FROM `$t` WHERE DATE(`$c`) BETWEEN '$from' AND '$to'" . ($extra !== '' ? " AND $extra" : ''));

    $areas = [
        ['Sessions booked',    $w('session_requests', 'session_date'),                        '#1B6FD1'],
        ['Messages sent',      $w('messages', 'created_at'),                                  '#17654B'],
        ['Feedback written',   $w('feedback', 'created_at'),                                  '#B7791F'],
        ['Assessments taken',  $w('assessment_attempts', 'submitted_at', "status='submitted'"), '#6B4FA8'],
        ['Resources uploaded', $w('resources', 'created_at'),                                 '#0087CF'],
        ['Members joined',     $w('users', 'created_at', "role <> 'admin'"),                 '#A6301F'],
    ];

    usort($areas, fn($a, $b) => $b[1] <=> $a[1]);
    return $areas;
}

/**
 * The recent activity feed.
 *
 * Real rows from six tables, unioned on the timestamp each one already
 * carries. Sign-ins are deliberately left out: there are hundreds of them and
 * they would push everything else off the list — the full sign-in trail is
 * the Activity Logs page.
 */
function rp_feed(mysqli $con, string $from, string $to, int $limit = 12): array
{
    $limit = max(1, min(50, $limit));
    $sql = "
        SELECT * FROM (
            -- An admin account being created is real activity, but it is not
            -- a new member: the headline figure excludes staff, so the feed
            -- labels them apart rather than contradicting the tile.
            SELECT IF(u.role = 'admin', 'staff', 'member') AS kind, u.created_at AS ts,
                   CONCAT_WS(' ', u.firstname, u.lastname) AS who,
                   CONCAT('Joined as ', IF(u.role = '', 'no role yet', u.role)) AS detail
              FROM users u
             WHERE DATE(u.created_at) BETWEEN '$from' AND '$to'

            UNION ALL
            SELECT 'session', sr.session_date,
                   CONCAT_WS(' ', me.firstname, me.lastname),
                   CONCAT(sr.subject, ' with ', COALESCE(mo.firstname, 'a mentor'), ' — ', sr.status)
              FROM session_requests sr
              LEFT JOIN users me ON me.user_id = sr.mentee_id
              LEFT JOIN users mo ON mo.user_id = sr.mentor_id
             WHERE DATE(sr.session_date) BETWEEN '$from' AND '$to'
               -- A session booked for next week has not happened yet, so it is
               -- not activity. Upcoming ones belong on the Sessions calendar.
               AND sr.session_date <= NOW()

            UNION ALL
            SELECT 'assessment', a.submitted_at,
                   CONCAT_WS(' ', me.firstname, me.lastname),
                   CONCAT(COALESCE(asm.title, 'An assessment'), ' — ',
                          COALESCE(a.score, 0), '/', COALESCE(a.total_points, 0))
              FROM assessment_attempts a
              LEFT JOIN users me        ON me.user_id = a.mentee_id
              LEFT JOIN assessments asm ON asm.assessment_id = a.assessment_id
             WHERE a.status = 'submitted' AND DATE(a.submitted_at) BETWEEN '$from' AND '$to'

            UNION ALL
            SELECT 'message', m.created_at,
                   CONCAT_WS(' ', s.firstname, s.lastname),
                   CONCAT('Sent to ', COALESCE(r.firstname, 'a member'))
              FROM messages m
              LEFT JOIN users s ON s.user_id = m.sender_id
              LEFT JOIN users r ON r.user_id = m.receiver_id
             WHERE DATE(m.created_at) BETWEEN '$from' AND '$to'

            UNION ALL
            SELECT 'feedback', f.created_at,
                   CONCAT_WS(' ', me.firstname, me.lastname),
                   CONCAT('Rated ', COALESCE(mo.firstname, 'a mentor'), ' ', f.rating, ' out of 5')
              FROM feedback f
              LEFT JOIN users me ON me.user_id = f.mentee_id
              LEFT JOIN users mo ON mo.user_id = f.mentor_id
             WHERE DATE(f.created_at) BETWEEN '$from' AND '$to'

            UNION ALL
            SELECT 'resource', rs.created_at,
                   CONCAT_WS(' ', up.firstname, up.lastname),
                   rs.title
              FROM resources rs
              LEFT JOIN users up ON up.user_id = rs.uploader_id
             WHERE DATE(rs.created_at) BETWEEN '$from' AND '$to'

            UNION ALL
            SELECT 'announcement', an.published_at,
                   COALESCE(CONCAT_WS(' ', ad.firstname, ad.lastname), 'Admin'),
                   an.title
              FROM announcements an
              LEFT JOIN users ad ON ad.user_id = an.created_by
             WHERE an.published_at IS NOT NULL
               AND DATE(an.published_at) BETWEEN '$from' AND '$to'
        ) feed
        WHERE ts IS NOT NULL
        ORDER BY ts DESC
        LIMIT $limit";

    $r = $con->query($sql);
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

/** How each feed row is labelled and coloured. */
function rp_feed_kinds(): array
{
    return [
        'member'       => ['Member joined',        '#EAF1FB', '#1A5C9A'],
        'staff'        => ['Admin account',        '#F1ECFA', '#5A3E96'],
        'session'      => ['Session',              '#E6F5EE', '#17654B'],
        'assessment'   => ['Assessment submitted', '#F1ECFA', '#5A3E96'],
        'message'      => ['Message',              '#EAF6FB', '#00679E'],
        'feedback'     => ['Feedback',             '#FEF6DC', '#8A6400'],
        'resource'     => ['Resource',             '#F3F4F6', '#4B5563'],
        'announcement' => ['Announcement',         '#FBE9E4', '#9A3412'],
    ];
}

/** Bytes, as something a person reads. */
function rp_size(int $b): string
{
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)    return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)       return round($b / 1024) . ' KB';
    return $b . ' B';
}

/** Icons this section needs that the sessions set does not carry. */
function rp_icon(string $k): string
{
    $p = [
        'users'  => '<circle cx="9" cy="8.5" r="3"/><path stroke-linecap="round" d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path stroke-linecap="round" d="M16 5.6a3 3 0 0 1 0 5.8M17.5 14.4A5.5 5.5 0 0 1 20.5 20"/>',
        'pulse'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12h3.5l2-5 3.5 10 2.5-5H21"/>',
        'quiz'   => '<rect x="4" y="3.5" width="16" height="17" rx="2.5"/><path stroke-linecap="round" d="M8 9h8M8 13h8M8 17h4"/>',
        'chat'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M20 12a7 7 0 0 1-7 7H8.5L5 21.5V18A7 7 0 0 1 12 5h1a7 7 0 0 1 7 7Z"/>',
        'cal'    => '<rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path stroke-linecap="round" d="M8 3v4M16 3v4M3.5 10h17"/>',
        'db'     => '<ellipse cx="12" cy="6" rx="7.5" ry="3"/><path stroke-linecap="round" d="M4.5 6v12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6M4.5 12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3"/>',
        'mail'   => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m3.8 7 8.2 6 8.2-6"/>',
        'flag'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M5 21V4m0 0h11l-2 3.5L16 11H5"/>',
        'star'   => '<path d="m12 3.6 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9L12 3.6Z"/>',
        'file'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M14 3.5H7.5A1.5 1.5 0 0 0 6 5v14a1.5 1.5 0 0 0 1.5 1.5h9A1.5 1.5 0 0 0 18 19V7.5L14 3.5Zm0 0V8h4"/>',
    ];
    return '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">' . ($p[$k] ?? '') . '</svg>';
}
