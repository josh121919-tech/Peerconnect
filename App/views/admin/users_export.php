<?php

/**
 * users_export.php — the user list as a file.
 *
 * Exports exactly what the filters on screen select, so the file matches the
 * view it was taken from — same rows, same order, same search. It shares the
 * query with the list itself (AdminUserRepository::allMatching), because an
 * export that quietly disagrees with the screen is worse than no export.
 *
 * CSV by default; ?format=pdf renders the letterheaded document the browser
 * prints. See includes/report_print.php for why printing beats a PDF library
 * on an app whose charts are inline SVG.
 *
 * SECURITY: admin-only, prepared statements throughout the repository.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_admin();

date_default_timezone_set('Asia/Manila');

/* ── The same filters the list reads ──────────────────────────────────── */
// A value sent as a list (?q[]=x) counts as missing, exactly as on the page.
$get = fn(string $name): string => is_string($_GET[$name] ?? null) ? $_GET[$name] : '';

$VIEWS  = ['all', 'mentee', 'mentor', 'admin', 'restricted', 'blocked'];
$view   = in_array($get('tab'), $VIEWS, true) ? $get('tab') : 'all';
if (in_array($get('role'), AdminUserRepository::ROLE_VIEWS, true)) {
    $view = $get('role');
}
$q      = trim($get('q'));
$status = in_array($get('status'), ['active', 'restricted', 'blocked', 'unverified'], true) ? $get('status') : '';
$sort   = in_array($get('sort'), ['newest', 'oldest', 'name', 'role'], true) ? $get('sort') : 'newest';

$rows = AdminUserRepository::allMatching($con, $view, $q, $status, $sort);

/** What the filters add up to, for the subtitle. */
$scope = [
    'all'        => 'All users',
    'mentee'     => 'Mentees',
    'mentor'     => 'Mentors',
    'admin'      => 'Administrators',
    'restricted' => 'Restricted accounts',
    'blocked'    => 'Blocked accounts',
][$view] ?? 'All users';
if ($status !== '') $scope .= ' · ' . ucfirst($status);
if ($q !== '')      $scope .= ' · matching “' . $q . '”';

/** A member's standing, as one word. */
$standing = function (array $r): string {
    if ($r['status'] === 'blocked')     return 'Blocked';
    if ($r['status'] === 'restricted')  return 'Restricted';
    return empty($r['verified']) ? 'Unverified' : 'Active';
};

/** Their rating, from whichever side of the app they are rated on. */
$ratingOf = function (array $r): ?float {
    $v = $r['role'] === 'mentor' ? $r['rating_as_mentor'] : $r['rating_as_mentee'];
    return $v === null ? null : round((float)$v, 2);
};

/*
 * ── Asked for as a document rather than a spreadsheet ──────────────────────
 *
 * Returns before any CSV header is sent; the CSV export below is untouched.
 * The log line is written inside each branch, so the record says which of the
 * two actually happened.
 */
if (($_GET['format'] ?? 'csv') === 'pdf') {
    pc_admin_log('viewed ' . count($rows) . ' user record' . (count($rows) === 1 ? '' : 's') . ' as a document');

    $byRole = $byStanding = $byClub = [];
    $sessionsTotal = 0;
    foreach ($rows as $r) {
        $byRole[ucfirst((string)$r['role'])] = ($byRole[ucfirst((string)$r['role'])] ?? 0) + 1;
        $st = $standing($r);
        $byStanding[$st] = ($byStanding[$st] ?? 0) + 1;
        $club = trim((string)($r['club'] ?? ''));
        if ($club !== '') $byClub[$club] = ($byClub[$club] ?? 0) + 1;
        $sessionsTotal += (int)$r['sessions'];
    }
    arsort($byRole);
    arsort($byStanding);
    arsort($byClub);

    $report_title    = 'User Management';
    $report_subtitle = $scope;
    $report_back     = url('admin-users');
    $report_meta     = [
        'Users'     => count($rows),
        'Generated' => date('j M Y, H:i') . ' (Asia/Manila)',
        'By'        => pc_user_name($con, (int)($_SESSION['user_id'] ?? 0)) ?: 'an administrator',
    ];

    $report_body = function () use ($rows, $byRole, $byStanding, $byClub, $sessionsTotal, $standing, $ratingOf) {
        $tiles = [['label' => 'Users', 'value' => number_format(count($rows)), 'hint' => 'in this export']];
        foreach (array_slice($byRole, 0, 3, true) as $role => $n) {
            $tiles[] = ['label' => $role . 's', 'value' => number_format($n)];
        }
        $tiles[] = ['label' => 'Completed sessions', 'value' => number_format($sessionsTotal), 'hint' => 'across these accounts'];
        rpt_tiles($tiles);

        if ($byRole) {
            rpt_section('By role', function () use ($byRole) {
                echo '<div class="rpt-chart">';
                rpt_bars($byRole, '#0868AD');
                echo '</div>';
            });
        }

        if ($byStanding) {
            rpt_section('By standing', function () use ($byStanding) {
                echo '<div class="rpt-chart">';
                rpt_bars($byStanding, '#17654B');
                echo '</div>';
            });
        }

        if ($byClub) {
            rpt_section('By club', function () use ($byClub) {
                echo '<div class="rpt-chart">';
                rpt_bars(array_slice($byClub, 0, 8, true), '#5B4FCF');
                echo '</div>';
            });
        }

        rpt_section('Accounts', function () use ($rows, $standing, $ratingOf) {
            $r = [];
            foreach ($rows as $row) {
                $rating = $ratingOf($row);
                $r[] = [
                    ['v' => (int)$row['user_id'], 'num' => true],
                    trim($row['firstname'] . ' ' . $row['lastname']),
                    $row['email'],
                    ucfirst((string)$row['role']),
                    $standing($row),
                    $row['course'] ?: '—',
                    $row['club'] ?: '—',
                    ['v' => number_format((int)$row['sessions']), 'num' => true],
                    ['v' => $rating === null ? '—' : number_format($rating, 2), 'num' => true],
                    date('j M Y', strtotime($row['created_at'])),
                ];
            }
            // Ten columns on A4: the widths are set because an equal split
            // would give the id number as much room as an email address.
            rpt_table(
                [['v' => '#', 'num' => true, 'w' => '5%'],
                 ['v' => 'Name',     'w' => '15%'],
                 ['v' => 'Email',    'w' => '20%'],
                 ['v' => 'Role',     'w' => '7%'],
                 ['v' => 'Standing', 'w' => '9%'],
                 ['v' => 'Course',   'w' => '13%'],
                 ['v' => 'Club',     'w' => '13%'],
                 ['v' => 'Sessions', 'num' => true, 'w' => '6%'],
                 ['v' => 'Rating',   'num' => true, 'w' => '6%'],
                 ['v' => 'Joined',   'w' => '6%']],
                $r,
                'No users match the current filter.'
            );
        });
    };

    require __DIR__ . '/includes/report_print.php';
    exit;
}

/* ── CSV ──────────────────────────────────────────────────────────────── */

// After the query, so the file describes the list rather than its own export.
pc_admin_log('exported ' . count($rows) . ' user record' . (count($rows) === 1 ? '' : 's'));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="peerconnect-users-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
// A byte-order mark, so Excel opens the accents in a name correctly.
fwrite($out, "\xEF\xBB\xBF");
CsvExport::row($out, ['User ID', 'First name', 'Last name', 'Email', 'Role', 'Standing',
                      'Verified', 'Course', 'Club', 'Completed sessions', 'Rating', 'Joined']);
foreach ($rows as $r) {
    $rating = $ratingOf($r);
    CsvExport::row($out, [
        (int)$r['user_id'],
        $r['firstname'],
        $r['lastname'],
        $r['email'],
        $r['role'],
        $standing($r),
        empty($r['verified']) ? 'No' : 'Yes',
        $r['course'] ?: '',
        $r['club'] ?: '',
        (int)$r['sessions'],
        $rating === null ? '' : number_format($rating, 2),
        date('Y-m-d', strtotime($r['created_at'])),
    ]);
}
fclose($out);
exit;
