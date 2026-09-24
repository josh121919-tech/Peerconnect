<?php

/**
 * report_data.php — the figures behind Reports & Analytics.
 *
 * The summary page and its CSV export have to agree to the last digit, so
 * both read their figures from SummaryRepository, and what is worked out from
 * those figures is worked out here, once.
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
    $str = fn(string $k) => is_string($get[$k] ?? null) ? trim($get[$k]) : '';
    $key = $str('range') !== '' ? $str('range') : '30d';
    $cf  = $str('from');
    $ct  = $str('to');

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
                $from = PlatformStatsRepository::firstAccountDate($con) ?? date('Y-m-d');
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
    } elseif ($days <= 220) {
        $unit = 'week';
    } else {
        $unit = 'month';
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

    return ['unit' => $unit, 'keys' => $keys];
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
 * Every headline figure, for one window.
 *
 * The previous window is measured by exactly the same queries, rather than a
 * second hand-written set that might count something slightly differently
 * and make the comparison meaningless.
 */
function rp_window(mysqli $con, string $from, string $to): array
{
    return SummaryRepository::window($con, $from, $to);
}

/**
 * What people actually did in the range, by area of the app.
 *
 * This counts records created, not screens visited. Nothing in this install
 * records a page view, so a "most used features" chart would be invented; a
 * count of the things people made is the closest measurement the data can
 * honestly support, and it is labelled as that.
 *
 * Each count is one the window already has (rp_window()), so this asks the
 * database nothing further.
 */
function rp_areas(array $window): array
{
    $areas = [
        ['Sessions booked',    $window['sessions'],    '#1B6FD1'],
        ['Messages sent',      $window['messages'],    '#17654B'],
        ['Feedback written',   $window['feedback'],    '#B7791F'],
        ['Assessments taken',  $window['assessments'], '#6B4FA8'],
        ['Resources uploaded', $window['resources'],   '#087FC1'],
        ['Members joined',     $window['joined'],      '#A6301F'],
    ];

    usort($areas, fn($a, $b) => $b[1] <=> $a[1]);
    return $areas;
}

/**
 * The recent activity feed: real rows from seven tables, newest first.
 * Sign-ins are deliberately left out: there are hundreds of them and they
 * would push everything else off the list. The full sign-in trail is the
 * Activity Logs page.
 */
function rp_feed(mysqli $con, string $from, string $to, int $limit = 12): array
{
    return SummaryRepository::feed($con, $from, $to, $limit);
}

/** How each feed row is labelled and coloured. */
function rp_feed_kinds(): array
{
    return [
        'member'       => ['Member joined',        '#EAF1FB', '#1A5C9A'],
        'staff'        => ['Admin account',        '#F1ECFA', '#5A3E96'],
        'session'      => ['Session',              '#E6F5EE', '#17654B'],
        'assessment'   => ['Assessment submitted', '#F1ECFA', '#5A3E96'],
        'message'      => ['Message',              '#EAF6FC', '#00679E'],
        'feedback'     => ['Feedback',             '#FEF6DC', '#8A6400'],
        'resource'     => ['Resource',             '#F3F4F6', '#4B5563'],
        'announcement' => ['Announcement',         '#FBE9E4', '#9A3412'],
    ];
}

/**
 * How many files the folders hold, and their size in bytes, subfolders
 * included: [files, bytes]. A folder that does not exist holds nothing.
 */
function rp_files_in(array $dirs): array
{
    $n = 0;
    $bytes = 0;
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) { $n++; $bytes += $f->getSize(); }
        }
    }
    return [$n, $bytes];
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
