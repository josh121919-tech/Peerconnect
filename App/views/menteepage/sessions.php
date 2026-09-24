<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../includes/join_control.php';
// Role, not just "signed in": every query below runs as $mentee_id, so a
// mentor landing here was shown a mentee dashboard built from their own id —
// an incoherent page, and the onboarding gate ran with the wrong role.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentee') {
    header("Location: " . url('welcomepage'));
    exit;
}
date_default_timezone_set('Asia/Manila');
$mentee_id = (int)$_SESSION['user_id'];
$appTz = new DateTimeZone('Asia/Manila');
$menteeSessionAlerts = [];
$now = new DateTime('now', $appTz);
$liveSessionStatuses = ['approved', 'unfinished'];

// Every list and count on this page comes from SessionRepository.
$filter = $_GET['status'] ?? 'all';
if (!is_string($filter)) {
    $filter = 'all';   // ?status[]=… used to end in a fatal error
}

$sessions = SessionRepository::withMentorForMentee($con, $mentee_id, $filter !== 'all' ? $filter : null);

$jsData = [];
foreach (SessionRepository::withMentorForMentee($con, $mentee_id, null, false) as $r) {
    $rid = (int)$r['request_id'];
    $timeStr = date('g:i A', strtotime($r['session_date']));
    if (!empty($r['session_end'])) $timeStr .= ' - ' . date('g:i A', strtotime($r['session_end']));
    $jsData[$rid] = ['mentor' => $r['firstname'] . ' ' . $r['lastname'], 'status' => 'Status: ' . ucfirst($r['status']), 'date' => date('m-d-Y', strtotime($r['session_date'])), 'time' => $timeStr, 'subject' => $r['subject'] ?? '', 'topic' => $r['topic'] ?? '', 'feedbackLink' => 'feedback.php?session=' . $rid];
}
$jsDataEncoded = json_encode($jsData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// ── Stat cards — real counts (compact display, no trend sublines). ───────
$curMonthStart  = date('Y-m-01 00:00:00');
$curMonthEnd    = date('Y-m-01 00:00:00', strtotime('+1 month'));

// Joined to users to match the Upcoming list, which cannot render a session
// whose mentor account no longer exists.
$upcoming_count   = SessionRepository::countUpcomingForMentee($con, $mentee_id);
$this_month_count = SessionRepository::countForMenteeBetween($con, $mentee_id, $curMonthStart, $curMonthEnd);
$completed_count  = SessionRepository::countForMenteeInStatuses($con, $mentee_id, ['completed']);
$cancelled_count  = SessionRepository::countForMenteeInStatuses($con, $mentee_id, ['rejected', 'cancelled']);
$total_count      = SessionRepository::countForMentee($con, $mentee_id);

// ── Upcoming Sessions list ─────────────────────────────────────────────
$upcoming_list = SessionRepository::upcomingWithSlotForMentee($con, $mentee_id, 5);

// ── Past Sessions list (completed / rejected / cancelled / missed) ─────
// 'missed' is in this list because it is not in any other one: it is not
// 'approved', so the Upcoming list skips it too, and a missed session used to
// disappear from the mentee's view entirely.
$past_list = SessionRepository::pastForMentee($con, $mentee_id, 4);

// ── Upcoming Calendar (month grid + dots) ───────────────────────────────
$cal_param = $_GET['cal'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $cal_param)) $cal_param = date('Y-m');
$cal_ts = strtotime($cal_param . '-01');
$cal_year = date('Y', $cal_ts);
$cal_month = date('m', $cal_ts);
$cal_days_in_month = (int)date('t', $cal_ts);
$cal_first_dow = (int)date('w', $cal_ts);
$cal_prev = date('Y-m', strtotime('-1 month', $cal_ts));
$cal_next = date('Y-m', strtotime('+1 month', $cal_ts));
$cal_label = date('F Y', $cal_ts);
$cal_start = "$cal_year-$cal_month-01";
$cal_end = date('Y-m-d', strtotime('+1 month', $cal_ts));

$cal_dot_map = SessionRepository::countsPerDayForMentee($con, $mentee_id, $cal_start, $cal_end);

$today_str = date('Y-m-d');
$todays_sessions = SessionRepository::countForMenteeOnDay($con, $mentee_id, $today_str);

$week_start = date('Y-m-d 00:00:00', strtotime('sunday this week', strtotime('-1 day')));
$week_end   = date('Y-m-d 00:00:00', strtotime($week_start . ' +7 days'));
$this_week_sessions = SessionRepository::countForMenteeBetween($con, $mentee_id, $week_start, $week_end);

$calendar_url = url('mentee-calendar');
$active_page = 'sessions';

/**
 * The badge class for a session status.
 *
 * "badge-{$status}" was being built by hand, which quietly produced
 * badge-unfinished — a class the stylesheet has never had, so the newest
 * status rendered as unstyled text. Named statuses map to the classes that
 * exist, and anything unrecognised falls back to a grey badge rather than
 * to nothing at all.
 */
function ss_badge_class(string $status): string
{
    return [
        'pending'    => 'badge-pending',
        'approved'   => 'badge-approved',
        'completed'  => 'badge-completed',
        'rejected'   => 'badge-rejected',
        'cancelled'  => 'badge-cancelled',
        'missed'     => 'badge-missed',
        'unfinished' => 'badge-orange',
    ][$status] ?? 'badge-gray';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sessions — PeerConnect</title>
    <?php include __DIR__ . '/includes/style.php'; ?>
    <style>
        /* .hero-banner, .pcard, .ring-* live in the shared design system. */

        /* .stats-grid, .stat-icon, .si-teal|blue|purple|orange and
           .stat-card-icon (icon-kit stat cards, full-size here and everywhere
           ≥700px, compact only on phones) now live in the shared design
           system, same as the Dashboard. */

        /* ── Page header ── */
        .sx-hd {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 22px;
        }

        .sx-hd-ico {
            width: 54px;
            height: 54px;
            border-radius: 18px;
            background: var(--info-bg);
            color: var(--info);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .sx-hd-text {
            flex: 1 1 auto;
            min-width: 0;
        }

        /* ── Stat cards ──
           auto-fit rather than a fixed four, because this page has five real
           counts and dropping one to match a four-up reference would be
           losing information to fit a layout. */
        .sx-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(185px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .sx-stat {
            position: relative;
            background: #fff;
            border: 1px solid var(--stat-border);
            border-radius: var(--stat-radius);
            box-shadow: var(--stat-shadow);
            transition: box-shadow .16s ease;
            padding: 18px;
            min-width: 0;
            overflow: hidden;
        }

        .sx-stat:hover {
            box-shadow: var(--stat-shadow-hover);
        }

        .sx-stat-ico {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
        }

        .sx-stat-ico svg {
            width: 19px;
            height: 19px;
        }

        .sx-stat-v {
            font-size: 26px;
            font-weight: 600;
            line-height: 1.15;
            letter-spacing: -0.03em;
            font-variant-numeric: tabular-nums;
            color: var(--forest);
        }

        .sx-stat-k {
            font-size: 12px;
            font-weight: 500;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--gray-500);
            margin-top: 4px;
        }

        .sx-stat-go {
            position: absolute;
            top: 18px;
            right: 18px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--gray-50);
            border: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: center;
            color: inherit;
        }

        .sx-stat-go:hover { background: var(--mint-faint); border-color: var(--mint-soft); }

        /* The tint is the icon's, not the card's — the card is white like
           every other figure tile in the product. */
        .sx-teal   .sx-stat-ico { background: #D3EDE1; color: #17654B; }
        .sx-teal   .sx-stat-go  { color: #17654B; }

        .sx-blue   .sx-stat-ico { background: #DBE7FD; color: #1A5C9A; }
        .sx-blue   .sx-stat-go  { color: #1A5C9A; }
        .sx-purple .sx-stat-ico { background: #E3DDFB; color: #5B4FCF; }
        .sx-purple .sx-stat-go  { color: #5B4FCF; }
        .sx-amber  .sx-stat-ico { background: #F8E7C4; color: #8A6400; }
        .sx-amber  .sx-stat-go  { color: #8A6400; }

        .sx-rose   .sx-stat-ico { background: #F8DADF; color: #A6301F; }
        .sx-rose   .sx-stat-go  { color: #A6301F; }

        /* Phones: the tile goes icon-beside-number, the same compaction the
           dashboards' .stat-card-icon uses at this width, so the two pages
           do not disagree about what a figure tile looks like on a phone.
           Grid rather than flex because the children are flat — icon, value,
           label — with no wrapper to make a column of. */
        @media (max-width: 700px) {
            .sx-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }

            .sx-stat {
                display: grid;
                grid-template-columns: auto minmax(0, 1fr);
                grid-template-areas: "ico val" "ico lbl";
                align-items: center;
                column-gap: 10px;
                padding: 12px 32px 12px 14px;
                border-radius: 12px;
            }

            .sx-stat-ico {
                grid-area: ico;
                width: 34px;
                height: 34px;
                margin-bottom: 0;
            }

            .sx-stat-ico svg { width: 16px; height: 16px; }

            .sx-stat-v {
                grid-area: val;
                font-size: 18px;
                align-self: end;
            }

            .sx-stat-k {
                grid-area: lbl;
                font-size: 10px;
                text-transform: none;
                letter-spacing: 0;
                line-height: 1.25;
                margin-top: 1px;
                align-self: start;
            }

            .sx-stat-s { display: none; }

            .sx-stat-go {
                top: 50%;
                right: 7px;
                bottom: auto;
                transform: translateY(-50%);
                width: 22px;
                height: 22px;
            }

            .sx-stat-go svg { width: 11px; height: 11px; }
        }

        /* Two across on a phone, scaled down to fit, rather than one per row:
           five full-size cards stacked pushed the session lists off the first
           screen entirely. Same approach as the onboarding grid. */
        @media (max-width: 560px) {
            .sx-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .sx-hd { gap: 12px; }
            .sx-hd-ico { width: 42px; height: 42px; border-radius: 13px; }
            .sx-hd-ico svg { width: 21px; height: 21px; }
        }

        @media (max-width: 340px) {
            .sx-stats { grid-template-columns: minmax(0, 1fr); }
        }

        .ss-filter-chips {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        /* ── Session History cards ──
           A table of six columns could not be read on a phone without
           scrolling it sideways, and the row was mostly labels. Each session
           is one card instead, two across where there is room. */
        .sh-head {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 16px;
        }

        .sh-head-ico {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            background: var(--info-bg);
            color: var(--info);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .sh-head-t {
            font-size: 16px;
            font-weight: 800;
            color: var(--gray-900);
            line-height: 1.2;
        }

        .sh-head-s {
            font-size: 12.5px;
            color: var(--gray-500);
            margin-top: 2px;
        }

        .sh-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .sh-card {
            border: 1px solid var(--gray-100);
            border-radius: 14px;
            padding: 15px 16px;
            background: var(--surface);
            min-width: 0;
        }

        .sh-card-top {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 10px;
        }

        .sh-av {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            flex-shrink: 0;
            background: var(--mint-faint);
            color: var(--forest);
        }

        .sh-who {
            flex: 1 1 auto;
            min-width: 0;
        }

        .sh-name {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
            font-size: 13.5px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .sh-role {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--info);
            background: var(--info-bg);
            border-radius: 999px;
            padding: 2px 8px;
        }

        .sh-facts {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 16px;
            font-size: 12px;
            color: var(--gray-500);
        }

        .sh-facts span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-width: 0;
        }

        .sh-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--gray-100);
        }

        .sh-foot-r {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--gray-400);
            font-weight: 500;
        }

        @media (max-width: 760px) {
            .sh-grid { grid-template-columns: minmax(0, 1fr); }
        }

        .ss-session-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
        }

        .ss-session-row:last-child {
            border-bottom: none;
        }

        .ss-date-col {
            width: 70px;
            flex-shrink: 0;
            text-align: left;
        }

        .ss-date-day {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--forest);
        }

        .ss-date-sub {
            font-size: 11px;
            color: var(--gray-400);
            margin-top: 1px;
        }

        .ss-session-info {
            flex: 1;
            min-width: 0;
        }

        .ss-session-title {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .ss-session-with {
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .ss-mentor-pill {
            font-size: 10px;
            font-weight: 700;
            color: var(--forest);
            background: var(--mint-faint);
            border-radius: 999px;
            padding: 1px 8px;
            text-transform: uppercase;
        }

        .ss-session-sub {
            font-size: 11.5px;
            color: var(--gray-400);
            margin-top: 2px;
        }

        .ss-meta-col {
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 11.5px;
            color: var(--gray-500);
            min-width: 90px;
        }

        .ss-meta-col span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .ss-meta-col svg {
            width: 12px;
            height: 12px;
            flex-shrink: 0;
        }

        .ss-rating {
            font-size: 12px;
            color: var(--warning);
            font-weight: 600;
        }

        /* Below ~640px the row's fixed-width columns (date/meta/button) no
           longer fit on one line — wrap them instead of letting the row
           force the whole page wider than the viewport. */
        @media (max-width: 640px) {
            .ss-session-row {
                flex-wrap: wrap;
                row-gap: 10px;
            }

            .ss-session-info {
                flex-basis: 100%;
                order: 1;
            }

            .ss-meta-col {
                flex-direction: row;
                gap: 12px;
                min-width: 0;
                order: 2;
            }

            .ss-session-row > .btn {
                order: 3;
                margin-left: auto;
            }
        }

        /* Calendar widget */
        .cal-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 4px 0 12px;
        }

        .cal-nav-btn {
            width: 26px;
            height: 26px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--gray-500);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .cal-nav-btn:hover {
            background: var(--mint-faint);
            color: var(--forest);
        }

        .cal-nav-label {
            font-size: 13px;
            font-weight: 700;
            color: var(--forest);
        }

        .cal-grid2 {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 3px;
        }

        .cal-hdr2 {
            font-size: 10px;
            font-weight: 600;
            color: var(--gray-400);
            text-align: center;
            padding: 2px 0 6px;
        }

        .cal-day2 {
            aspect-ratio: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 2px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            color: var(--gray-600);
        }

        .cal-day2.today {
            background: var(--primary);
            color: #fff;
            font-weight: 700;
        }

        .cal-dot {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: var(--mint);
        }

        .cal-day2.today .cal-dot {
            background: #fff;
        }

        .ss-mini-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 14px;
        }

        .ss-mini-stat {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 10px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ss-mini-stat svg {
            width: 15px;
            height: 15px;
            color: var(--mint);
            flex-shrink: 0;
        }

        .ss-mini-stat .n {
            font-size: 16px;
            font-weight: 700;
            color: var(--forest);
        }

        .ss-mini-stat .l {
            font-size: 10.5px;
            color: var(--gray-400);
        }

        /* The .stats-grid column overrides that were here are gone with the
           markup they sized: the five cards are .sx-stats now, which fits
           them with auto-fit instead of a breakpoint per width. */
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main fade-in">
            <div class="sx-hd">
                <span class="sx-hd-ico">
                    <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="4" y="5" width="16" height="15" rx="2.5" />
                        <path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16" />
                    </svg>
                </span>
                <div class="sx-hd-text page-hd" style="margin:0;">
                    <h1>Sessions</h1>
                    <p>Manage your mentorship sessions, track your progress, and stay on top of your learning journey.</p>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-top:4px;">
                    <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('historyModal').classList.add('open')">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 3" /></svg>
                        History
                    </button>
                </div>
            </div>

            <?php
            /*
             * Stat cards. The arrow opens Session History filtered to exactly
             * what the card counts — ?status is what the modal reads, and the
             * query takes any status, not just the ones with a chip.
             *
             * "This Month" has no arrow: it is a date range, not a status,
             * and there is nothing for the filter to show.
             */
            $sx_cards = [
                ['sx-blue',   $upcoming_count,   'Upcoming Sessions',  'approved',
                 '<rect x="5" y="4" width="14" height="16" rx="3"/><path stroke-linecap="round" d="M8 2v4M16 2v4M5 9h14"/>'],
                ['sx-purple', $this_month_count, 'Sessions This Month', null,
                 '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 3"/>'],
                ['sx-teal',   $completed_count,  'Completed Sessions', 'completed',
                 '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.3 2.4 2.4 4.6-4.9"/>'],
                ['sx-rose',   $cancelled_count,  'Cancelled Sessions', 'cancelled',
                 '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="m9.5 9.5 5 5m0-5-5 5"/>'],
                ['sx-amber',  $total_count,      'Total Sessions',     'all',
                 '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2"/>'],
            ];
            ?>
            <div class="sx-stats">
                <?php foreach ($sx_cards as [$tint, $value, $label, $status, $path]): ?>
                    <div class="sx-stat <?= $tint ?>">
                        <?php if ($status !== null): ?>
                            <a class="sx-stat-go" href="?status=<?= htmlspecialchars($status) ?>"
                               aria-label="Show <?= htmlspecialchars($label) ?> in Session History">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6" />
                                </svg>
                            </a>
                        <?php endif; ?>
                        <div class="sx-stat-ico">
                            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24" aria-hidden="true"><?= $path ?></svg>
                        </div>
                        <div class="sx-stat-v"><?= (int)$value ?></div>
                        <div class="sx-stat-k"><?= htmlspecialchars($label) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="dash-grid">
                <!-- LEFT column -->
                <div style="display:flex;flex-direction:column;gap:16px;">

                    <!-- Upcoming Sessions -->
                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Upcoming Sessions</span>
                            <a href="?status=approved" class="pcard-link">View all →</a>
                        </div>
                        <?php if ($upcoming_list): ?>
                            <?php foreach ($upcoming_list as $s):
                                $sid = (int)$s['request_id'];
                                $name = trim($s['firstname'] . ' ' . $s['lastname']);
                                $av  = pc_avatar($s['profile_image'] ?? '', $name, 1);
                                $sessionDT = new DateTime($s['session_date'], $appTz);
                                $dayLabel = $sessionDT->format('Y-m-d') === $now->format('Y-m-d') ? 'Today'
                                    : ($sessionDT->format('Y-m-d') === (clone $now)->modify('+1 day')->format('Y-m-d') ? 'Tomorrow' : $sessionDT->format('D'));
                                $openJoinDT = (clone $sessionDT)->modify('-' . SessionRepository::JOIN_WINDOW_MINUTES . ' minutes');
                                $duration = $s['duration'] ? (int)$s['duration'] : 60;
                                $sessionEndDT = (clone $sessionDT)->modify("+{$duration} minutes");
                                $status = $s['status'] ?? 'approved';
                                $typeLabel = $s['session_type'] === 'group' ? 'Group Mentoring' : ($s['topics'] ?: (($s['subject'] ?: 'General') . ' Mentoring'));
                            ?>
                                <div class="ss-session-row">
                                    <div class="ss-date-col">
                                        <div class="ss-date-day" style="color:var(--mint);"><?= htmlspecialchars($dayLabel) ?></div>
                                        <div class="ss-date-sub"><?= $sessionDT->format('M j') ?></div>
                                        <div class="ss-date-sub"><?= $sessionDT->format('g:i A') ?></div>
                                    </div>
                                    <div class="pc-avatar pc-avatar-md" style="flex-shrink:0;"><?= $av ?></div>
                                    <div class="ss-session-info">
                                        <div class="ss-session-title"><?= htmlspecialchars($s['subject'] ?: 'General Mentorship') ?></div>
                                        <div class="ss-session-with">with <?= htmlspecialchars($name) ?> <span class="ss-mentor-pill">Mentor</span></div>
                                        <div class="ss-session-sub"><?= htmlspecialchars($typeLabel) ?></div>
                                    </div>
                                    <div class="ss-meta-col">
                                        <span><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7v5l3 3" /></svg><?= $duration ?> min</span>
                                        <span><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L15 11.75M11 6h6a2 2 0 012 2v6M13 18H7a2 2 0 01-2-2V6" /></svg>Online</span>
                                    </div>
                                    <?php
                                    /*
                                     * One control, minding both edges itself: a countdown
                                     * before the call opens, the button while it is open,
                                     * and View Details once it is over. This page gets left
                                     * sitting open, and the button used to outlive the
                                     * session it belonged to.
                                     */
                                    $detailsBtn = '<button class="view-btn btn btn-ghost" data-id="' . $sid
                                        . '" style="font-size:12px;padding:8px 14px;flex-shrink:0;">View Details</button>';
                                    if (in_array($status, $liveSessionStatuses, true)):
                                        pc_join_control([
                                            'session_id' => $sid,
                                            'starts_at'  => $sessionDT->getTimestamp(),
                                            'ends_at'    => $sessionEndDT->getTimestamp(),
                                            'status'     => $status,
                                            'class'      => 'btn btn-primary',
                                            'label'      => $status === 'unfinished' ? 'Rejoin Session' : 'Join Session',
                                            'icon'       => '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:5px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.069A1 1 0 0121 8.867v6.266a1 1 0 01-1.447.9L15 14M3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z" /></svg>',
                                            'ended'      => $detailsBtn,
                                        ]);
                                    else:
                                        echo $detailsBtn;
                                    endif;
                                    ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="prow-empty">
                                <p style="margin:0 0 12px;">No upcoming sessions. Book a session with a mentor to get started.</p>
                                <a href="<?= htmlspecialchars(url('mentee-find')) ?>" class="btn btn-primary btn-sm">Find a mentor →</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Past Sessions -->
                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Past Sessions</span>
                            <a href="?status=completed" class="pcard-link">View all →</a>
                        </div>
                        <?php if ($past_list): ?>
                            <?php foreach ($past_list as $s):
                                $sid = (int)$s['request_id'];
                                $name = trim($s['firstname'] . ' ' . $s['lastname']);
                                $av  = pc_avatar($s['profile_image'] ?? '', $name, 1);
                                $sessionDT = new DateTime($s['session_date'], $appTz);
                            ?>
                                <div class="ss-session-row">
                                    <div class="ss-date-col">
                                        <div class="ss-date-day"><?= $sessionDT->format('M j') ?></div>
                                        <div class="ss-date-sub"><?= $sessionDT->format('Y') ?></div>
                                        <div class="ss-date-sub"><?= $sessionDT->format('g:i A') ?></div>
                                    </div>
                                    <div class="pc-avatar pc-avatar-md" style="flex-shrink:0;"><?= $av ?></div>
                                    <div class="ss-session-info">
                                        <div class="ss-session-title"><?= htmlspecialchars($s['subject'] ?: 'General Mentorship') ?></div>
                                        <div class="ss-session-with">with <?= htmlspecialchars($name) ?> <span class="ss-mentor-pill">Mentor</span></div>
                                    </div>
                                    <div class="ss-meta-col">
                                        <span class="badge <?= ss_badge_class($s['status']) ?>"><?= ucfirst($s['status']) ?></span>
                                        <?php if ($s['rating']): ?>
                                            <span class="ss-rating">&#9733; <?= number_format((float)$s['rating'], 1) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <button class="view-btn btn btn-ghost" data-id="<?= $sid ?>" style="font-size:12px;padding:8px 14px;flex-shrink:0;">View Details</button>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="prow-empty">No past sessions yet.</div>
                        <?php endif; ?>
                    </div>

                </div><!-- /left -->

                <!-- RIGHT column -->
                <div style="display:flex;flex-direction:column;gap:16px;">

                    <!-- Upcoming Calendar -->
                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Upcoming Calendar</span>
                            <a href="<?= htmlspecialchars($calendar_url) ?>" class="pcard-link">View calendar →</a>
                        </div>
                        <div class="pcard-body">
                            <div class="cal-nav">
                                <a class="cal-nav-btn" href="?cal=<?= $cal_prev ?>" aria-label="Previous month">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7" /></svg>
                                </a>
                                <span class="cal-nav-label"><?= $cal_label ?></span>
                                <a class="cal-nav-btn" href="?cal=<?= $cal_next ?>" aria-label="Next month">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" /></svg>
                                </a>
                            </div>
                            <div class="cal-grid2" style="margin-bottom:2px;">
                                <?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $d): ?>
                                    <div class="cal-hdr2"><?= $d ?></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="cal-grid2">
                                <?php
                                for ($e = 0; $e < $cal_first_dow; $e++) echo "<div></div>";
                                for ($i = 1; $i <= $cal_days_in_month; $i++):
                                    $dstr = "$cal_year-$cal_month-" . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
                                    $isToday = $dstr === $today_str;
                                    $hasSession = isset($cal_dot_map[$dstr]);
                                ?>
                                    <div class="cal-day2<?= $isToday ? ' today' : '' ?>">
                                        <?= $i ?>
                                        <?php if ($hasSession): ?><span class="cal-dot"></span><?php endif; ?>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <div class="ss-mini-stats">
                                <div class="ss-mini-stat">
                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="16" rx="3" /><path stroke-linecap="round" d="M8 2v4M16 2v4M5 9h14" /></svg>
                                    <div>
                                        <div class="n"><?= $todays_sessions ?></div>
                                        <div class="l">Today's Sessions</div>
                                    </div>
                                </div>
                                <div class="ss-mini-stat">
                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="16" rx="3" /><path stroke-linecap="round" d="M8 2v4M16 2v4M5 9h14" /></svg>
                                    <div>
                                        <div class="n"><?= $this_week_sessions ?></div>
                                        <div class="l">This Week</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Session Overview used to live here. Every figure in it
                         (upcoming / completed / cancelled) already sat in the
                         stat cards at the top of the page, so it was a second
                         copy of the same numbers a scroll further down. Its one
                         unique figure, Total Sessions, is now the fifth stat
                         card instead. -->

                </div><!-- /right -->
            </div><!-- /dash-grid -->
        </main>
    </div>

    <!-- Session History modal — the full filterable table, tucked behind
         the "History" button instead of always taking up page space. -->
    <div id="historyModal" class="modal-overlay<?= isset($_GET['status']) ? ' open' : '' ?>">
        <div style="background:var(--surface);border-radius:var(--radius-lg);padding:24px;width:820px;max-width:95vw;max-height:85vh;overflow-y:auto;position:relative;box-shadow:var(--shadow-lg);">
            <div class="sh-head">
                <span class="sh-head-ico">
                    <svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="8.5" /><path stroke-linecap="round" d="M12 8v4l2.5 1.5" />
                    </svg>
                </span>
                <div style="flex:1 1 auto;min-width:0;">
                    <div class="sh-head-t">Session History</div>
                    <div class="sh-head-s">View all your past and upcoming mentoring sessions.</div>
                </div>
                <button id="history-close" style="width:30px;height:30px;border-radius:8px;border:1px solid var(--gray-200);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="ss-filter-chips">
                <?php
                /*
                 * Every status a session can end up in, not just the five it
                 * used to offer. Cancelled, missed and unfinished sessions
                 * were all in this list with no way to filter down to them,
                 * which is most of what History is for.
                 */
                foreach (['all', 'approved', 'pending', 'completed', 'unfinished', 'missed', 'cancelled', 'rejected'] as $st): ?>
                    <a href="?status=<?= $st ?>" class="chip <?= $filter === $st ? 'active' : '' ?>"><?= ucfirst($st) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="sh-grid">
                        <?php if ($sessions): ?>
                            <?php foreach ($sessions as $s):
                                $sid        = (int)$s['request_id'];
                                $sdate      = date('m-d-Y', strtotime($s['session_date']));
                                $stime      = date('g:i A', strtotime($s['session_date']));
                                $timeEnd    = !empty($s['session_end']) ? ' – ' . date('g:i A', strtotime($s['session_end'])) : '';
                                $status     = $s['status'];
                                $sessionDT  = new DateTime($s['session_date'], $appTz);
                                $openJoinDT = (clone $sessionDT)->modify('-' . SessionRepository::JOIN_WINDOW_MINUTES . ' minutes');
                                /*
                                 * The end matters as much as the start. Without
                                 * the upper bound this offered "Rejoin Video" on
                                 * a session that finished hours ago — the lobby
                                 * refuses anything past its end, so the button
                                 * could only ever bounce the mentee straight
                                 * back. The list on this same page already
                                 * bounded it; History did not.
                                 */
                                $durationHs   = !empty($s['duration']) ? (int)$s['duration'] : 60;
                                $sessionEndDT = (clone $sessionDT)->modify("+{$durationHs} minutes");
                                $isUpcoming = $status === 'approved' && $now < $openJoinDT;
                                $minsToStart = (int)floor(($sessionDT->getTimestamp() - $now->getTimestamp()) / 60);
                                if ($status === 'approved' && $minsToStart >= 0 && $minsToStart <= 10) {
                                    $menteeSessionAlerts[] = ['key' => 'mentee-sessions-' . $sid, 'message' => 'Session with ' . $s['firstname'] . ' ' . $s['lastname'] . ' starts in ' . $minsToStart . ' minute' . ($minsToStart === 1 ? '' : 's') . '.'];
                                }
                            ?>
                                <div class="sh-card">
                                    <div class="sh-card-top">
                                        <span class="sh-av"><?= pc_avatar($s['profile_image'] ?? '', trim($s['firstname'] . ' ' . $s['lastname']), 1) ?></span>
                                        <div class="sh-who">
                                            <div class="sh-name">
                                                <?= htmlspecialchars($s['firstname'] . ' ' . $s['lastname']) ?>
                                                <span class="sh-role">Mentor</span>
                                            </div>
                                        </div>
                                        <span class="badge <?= ss_badge_class($status) ?>" style="flex-shrink:0;"><?= ucfirst($status) ?></span>
                                    </div>

                                    <div class="sh-facts">
                                        <?php if (trim((string)($s['subject'] ?? '')) !== ''): ?>
                                            <span>
                                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5 3 9l9 4 9-4-9-4Z" /><path stroke-linecap="round" d="M7 11v4c0 1 2.2 2 5 2s5-1 5-2v-4" /></svg>
                                                <?= htmlspecialchars($s['subject']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <span>
                                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2.5" /><path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16" /></svg>
                                            <?= $sdate ?>
                                        </span>
                                        <span>
                                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5" /><path stroke-linecap="round" d="M12 8v4l2.5 1.5" /></svg>
                                            <?= $stime . $timeEnd ?>
                                        </span>
                                    </div>

                                    <div class="sh-foot">
                                        <?php // View Details on every card now. It used to appear only
                                              // when there was nothing else to offer, so the sessions a
                                              // mentee most wanted to look into — the ones still ahead —
                                              // were the ones with no way to open them. ?>
                                        <button class="view-btn btn btn-ghost" data-id="<?= $sid ?>" style="font-size:12px;padding:5px 12px;">
                                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24" style="margin-right:5px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" /><circle cx="12" cy="12" r="2.6" /></svg>
                                            View Details
                                        </button>

                                        <span class="sh-foot-r">
                                            <?php if (in_array($status, $liveSessionStatuses, true) && $now <= $sessionEndDT): ?>
                                                <?php pc_join_control([
                                                    'session_id' => $sid,
                                                    'starts_at'  => $sessionDT->getTimestamp(),
                                                    'ends_at'    => $sessionEndDT->getTimestamp(),
                                                    'status'     => $status,
                                                    'class'      => 'btn btn-blue',
                                                    'label'      => $status === 'unfinished' ? 'Rejoin Video' : 'Join Video',
                                                    'icon'       => '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:5px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.069A1 1 0 0121 8.867v6.266a1 1 0 01-1.447.9L15 14M3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z" /></svg>',
                                                ]); ?>
                                            <?php elseif ($isUpcoming): ?>
                                                <span style="display:inline-flex;align-items:center;gap:6px;color:var(--info);">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2.5" /><path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16" /></svg>
                                                    Upcoming
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($status === 'approved'): ?>
                                                <a href="<?= url('session-ics') ?>?session_id=<?= $sid ?>" title="Add to calendar" style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:1px solid var(--border);border-radius:7px;color:var(--gray-500);flex-shrink:0;">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                                </a>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state" style="padding:40px 0;grid-column:1 / -1;">
                                <div class="empty-icon">📚</div>
                                <p style="color:var(--gray-500);font-weight:600;">No sessions found</p>
                            </div>
                        <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Detail Modal -->
    <div id="modal-overlay" class="modal-overlay">
        <div style="background:var(--surface);border-radius:var(--radius-lg);padding:28px;width:440px;max-width:95vw;position:relative;box-shadow:var(--shadow-lg);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <div style="font-size:15px;font-weight:700;color:var(--gray-900);">Session Details</div>
                <button id="modal-close" style="width:30px;height:30px;border-radius:8px;border:1px solid var(--gray-200);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                <span id="modal-mentor" style="font-size:15px;font-weight:600;color:var(--gray-900);"></span>
                <span style="font-size:11px;color:var(--accent);background:var(--info-bg);padding:2px 8px;border-radius:20px;">Mentor</span>
            </div>
            <p id="modal-status" style="font-size:12px;color:var(--gray-400);margin-bottom:14px;"></p>
            <hr style="border:none;border-top:1px solid var(--gray-100);margin-bottom:14px;">
            <p style="font-size:13px;color:var(--gray-700);margin-bottom:8px;"><strong>Date:</strong> <span id="modal-date"></span></p>
            <p style="font-size:13px;color:var(--gray-700);margin-bottom:8px;"><strong>Time:</strong> <span id="modal-time"></span></p>
            <p style="font-size:13px;color:var(--gray-700);margin-bottom:8px;"><strong>Subject:</strong> <span id="modal-subject"></span></p>
            <p style="font-size:13px;color:var(--gray-700);margin-bottom:16px;"><strong>Topic:</strong> <span id="modal-topic"></span></p>
            <div style="display:flex;justify-content:flex-end;">
                <a id="modal-feedback-link" href="#" class="btn btn-ghost" style="font-size:13px;">View Feedback</a>
            </div>
        </div>
    </div>

    <script>
        const sessionData = <?php echo $jsDataEncoded; ?>;
        const alerts = <?php echo json_encode($menteeSessionAlerts); ?>;
        alerts.forEach(function(a) {
            const k = 'notify_' + a.key;
            if (!localStorage.getItem(k)) {
                pcToast(a.message, 'warning', 10000, 'Starting soon');
                localStorage.setItem(k, '1');
            }
        });
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.view-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id = parseInt(this.getAttribute('data-id'));
                    var d = sessionData[id];
                    if (!d) return;
                    document.getElementById('modal-mentor').textContent = d.mentor;
                    document.getElementById('modal-status').textContent = d.status;
                    document.getElementById('modal-date').textContent = d.date;
                    document.getElementById('modal-time').textContent = d.time;
                    document.getElementById('modal-subject').textContent = d.subject;
                    document.getElementById('modal-topic').textContent = d.topic;
                    document.getElementById('modal-feedback-link').href = d.feedbackLink;
                    document.getElementById('modal-overlay').classList.add('open');
                });
            });
            document.getElementById('modal-close').addEventListener('click', function() {
                document.getElementById('modal-overlay').classList.remove('open');
            });
            document.getElementById('modal-overlay').addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('open');
            });

            document.getElementById('history-close').addEventListener('click', function() {
                document.getElementById('historyModal').classList.remove('open');
            });
            document.getElementById('historyModal').addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('open');
            });
        });

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        }

        function toggleProfileMenu() {
            document.getElementById('profileMenu').classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const m = document.getElementById('profileMenu');
            if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]')) m.classList.remove('open');
        });
    </script>
</body>

</html>
