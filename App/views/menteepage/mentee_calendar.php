<?php
// Mentorship calendar — month / week / day / list views over the mentee's
// own session_requests. Nothing here is decorative: every control moves the
// range, changes the view, or opens a session that really exists.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/GoogleCalendarService.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentee') {
    header("Location: " . url('welcomepage'));
    exit;
}

$mentee_id = (int)$_SESSION['user_id'];


// ── Google Calendar link ─────────────────────────────────────────────────
// Approved sessions are pushed to Google when they're approved; this is the
// safety net that catches anything that changed while nobody was looking.
$gcal_configured = GoogleCalendarService::isConfigured();
$gcal            = $gcal_configured ? GoogleCalendarService::linkFor($con, $mentee_id) : null;
if ($gcal) {
    GoogleCalendarService::syncIfStale($con, $mentee_id);
    $gcal = GoogleCalendarService::linkFor($con, $mentee_id);
}

// PHP's timezone on this server is not the one session_date is written in,
// so "today" has to be asked for explicitly rather than taken from date().
$appTz = new DateTimeZone('Asia/Manila');
$today = new DateTime('now', $appTz);
$todayKey = $today->format('Y-m-d');

// ── View + anchor date ───────────────────────────────────────────────────
$views = ['month', 'week', 'day', 'list'];
$view  = in_array($_GET['view'] ?? '', $views, true) ? $_GET['view'] : 'month';

$anchor = new DateTime($todayKey, $appTz);
if (!empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $parsed = DateTime::createFromFormat('Y-m-d', $_GET['date'], $appTz);
    if ($parsed) $anchor = new DateTime($parsed->format('Y-m-d'), $appTz);
}

// Range shown, the label above it, and what prev/next step by.
switch ($view) {
    case 'week':
        // Sun–Sat, to match the column headers. PHP's "sunday this week" is
        // ISO (Mon–Sun) and would jump forward for a mid-week anchor, so step
        // back by the day-of-week index instead.
        $rangeStart = (clone $anchor)->modify('-' . (int)$anchor->format('w') . ' days');
        $rangeEnd   = (clone $rangeStart)->modify('+6 days');
        $rangeLabel = $rangeStart->format('M j') . ' – ' . $rangeEnd->format('M j, Y');
        $prev = (clone $anchor)->modify('-7 days');
        $next = (clone $anchor)->modify('+7 days');
        break;
    case 'day':
        $rangeStart = clone $anchor;
        $rangeEnd   = clone $anchor;
        $rangeLabel = $anchor->format('l, F j, Y');
        $prev = (clone $anchor)->modify('-1 day');
        $next = (clone $anchor)->modify('+1 day');
        break;
    default: // month + list
        $rangeStart = new DateTime($anchor->format('Y-m-01'), $appTz);
        $rangeEnd   = new DateTime($rangeStart->format('Y-m-t'), $appTz);
        $rangeLabel = $anchor->format('F Y');
        $prev = (clone $rangeStart)->modify('-1 month');
        $next = (clone $rangeStart)->modify('+1 month');
        break;
}

$startKey = $rangeStart->format('Y-m-d');
$endKey   = $rangeEnd->format('Y-m-d');

// ── Sessions in range ────────────────────────────────────────────────────
$rows = SessionRepository::calendarForMentee($con, $mentee_id, $startKey, $endKey);

// Keyed by day so the grid can look each date up directly.
$byDay = [];
foreach ($rows as $r) {
    $byDay[substr($r['session_date'], 0, 10)][] = $r;
}

// ── Upcoming, for the side rail (not limited to the visible range) ───────
$upcoming = SessionRepository::openFromNowForMentee($con, $mentee_id, 5);

$approved_total = SessionRepository::countForMenteeInStatuses($con, $mentee_id, ['approved']);

// ── Helpers ──────────────────────────────────────────────────────────────
$statusMeta = [
    'approved'  => ['Approved',  'var(--mint)'],
    'pending'   => ['Pending',   'var(--warning)'],
    'completed' => ['Completed', 'var(--success)'],
];

/** Start/end clock for one session row. */
function cal_times(array $r, DateTimeZone $tz): array
{
    $s = new DateTime($r['session_date'], $tz);
    $e = (clone $s)->modify('+' . (int)$r['duration'] . ' minutes');
    return [$s, $e];
}

function cal_url(string $view, DateTime $d): string
{
    return '?view=' . $view . '&date=' . $d->format('Y-m-d');
}

$find_mentor_url = url('mentee-find');
$ics_url         = url('session-ics');
$active_page     = 'calendar';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        .cal-eyebrow {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--gray-400);
            margin-bottom: 6px;
        }

        .cal-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 330px;
            gap: 18px;
            align-items: start;
        }

        /* ── Toolbar ── */
        .cal-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
        }

        .cal-nav {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .cal-arrow {
            width: 34px;
            height: 34px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--gray-600);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: background .14s, color .14s, border-color .14s;
        }

        .cal-arrow:hover {
            background: var(--mint-faint);
            border-color: var(--mint-soft);
            color: var(--mint);
        }

        .cal-range {
            font-size: 17px;
            font-weight: 700;
            color: var(--forest);
            white-space: nowrap;
        }

        .cal-views {
            display: inline-flex;
            background: var(--gray-100);
            border-radius: var(--radius);
            padding: 3px;
        }

        .cal-views a {
            padding: 7px 16px;
            border-radius: var(--radius-sm);
            font-size: 12.5px;
            font-weight: 600;
            color: var(--gray-500);
            text-decoration: none;
            transition: background .14s, color .14s;
        }

        .cal-views a.active {
            background: var(--mint);
            color: #fff;
        }

        .cal-views a:not(.active):hover {
            color: var(--forest);
        }

        /* ── Month grid ── */
        .cal-dow {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            border-bottom: 1px solid var(--border);
        }

        .cal-dow span {
            padding: 11px 8px;
            text-align: center;
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-500);
        }

        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
        }

        .cal-cell {
            min-height: 118px;
            border-right: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            padding: 8px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .cal-cell:nth-child(7n) {
            border-right: none;
        }

        .cal-grid>.cal-cell:nth-last-child(-n+7) {
            border-bottom: none;
        }

        .cal-daynum {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--gray-600);
            text-decoration: none;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .cal-daynum:hover {
            background: var(--gray-100);
            color: var(--forest);
        }

        .cal-cell.out .cal-daynum {
            color: var(--gray-300);
        }

        .cal-cell.today {
            background: var(--mint-faint);
        }

        .cal-cell.today .cal-daynum {
            background: var(--mint);
            color: #fff;
        }

        .cal-evs {
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-width: 0;
        }

        /* ── Event chip ── */
        .cal-ev {
            display: block;
            border-radius: var(--radius-sm);
            padding: 6px 8px;
            text-align: left;
            border: none;
            border-left: 3px solid var(--ev);
            background: var(--ev-bg);
            cursor: pointer;
            font-family: inherit;
            width: 100%;
            min-width: 0;
            overflow: hidden;
            transition: filter .14s;
        }

        .cal-ev:hover {
            filter: brightness(.97);
        }

        .cal-ev-time {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 10.5px;
            font-weight: 600;
            color: var(--gray-600);
        }

        .cal-ev-time::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--ev);
            flex-shrink: 0;
        }

        .cal-ev-title {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--forest);
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .cal-ev-who {
            display: block;
            font-size: 10.5px;
            color: var(--gray-500);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ── Week / day / list ── */
        .cal-week {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
        }

        .cal-week-col {
            border-right: 1px solid var(--border);
            min-height: 320px;
            padding: 10px 8px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .cal-week-col:last-child {
            border-right: none;
        }

        .cal-week-hd {
            text-align: center;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 4px;
        }

        .cal-week-dow {
            font-size: 11px;
            font-weight: 600;
            color: var(--gray-500);
        }

        .cal-week-num {
            font-size: 16px;
            font-weight: 700;
            color: var(--forest);
        }

        .cal-week-col.today .cal-week-num {
            color: var(--mint);
        }

        .cal-agenda-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 13px 18px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            width: 100%;
            background: none;
            border-left: none;
            border-right: none;
            border-top: none;
            cursor: pointer;
            font-family: inherit;
            transition: background .14s;
        }

        .cal-agenda-row:hover {
            background: var(--gray-50);
        }

        .cal-agenda-time {
            width: 132px;
            flex-shrink: 0;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--gray-600);
        }

        .cal-bar {
            width: 4px;
            align-self: stretch;
            border-radius: 999px;
            background: var(--ev);
            flex-shrink: 0;
        }

        .cal-empty {
            padding: 46px 20px;
            text-align: center;
            color: var(--gray-400);
            font-size: 13px;
        }

        /* ── Side rail ── */
        .cal-up {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 11px 0;
            border-bottom: 1px solid var(--border);
            text-decoration: none;
            width: 100%;
            background: none;
            border-left: none;
            border-right: none;
            border-top: none;
            cursor: pointer;
            font-family: inherit;
            text-align: left;
        }

        .cal-up:last-child {
            border-bottom: none;
        }

        .cal-up-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-top: 5px;
            flex-shrink: 0;
        }

        .cal-qa {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 0;
            text-decoration: none;
            border-bottom: 1px solid var(--border);
        }

        .cal-qa:last-child {
            border-bottom: none;
        }

        .cal-qa-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: var(--mint-faint);
            color: var(--mint);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .cal-qa-chev {
            margin-left: auto;
            color: var(--gray-300);
            flex-shrink: 0;
        }

        .cal-qa:hover .cal-qa-chev {
            color: var(--mint);
        }

        /* Google's calendar mark, drawn rather than hotlinked (the app ships
           no third-party logo assets). */
        .cal-gcal-logo {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #fff;
            border: 1px solid var(--border);
            border-top: 7px solid #4285F4;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 700;
            color: #4285F4;
            line-height: 1;
            padding-top: 4px;
            flex-shrink: 0;
        }

        .cal-flash {
            border-radius: var(--radius);
            padding: 11px 16px;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .cal-flash-success {
            background: var(--success-bg, #E8F5EF);
            color: var(--success);
        }

        .cal-flash-error {
            background: var(--danger-bg);
            color: var(--danger);
        }

        .cal-tip {
            background: var(--mint-faint);
            border: 1px solid var(--mint-soft);
            border-radius: var(--radius-lg);
            padding: 16px 18px;
            display: flex;
            gap: 12px;
            position: relative;
        }

        .cal-tip-x {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 24px;
            height: 24px;
            border: none;
            background: none;
            color: var(--gray-400);
            cursor: pointer;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .cal-tip-x:hover {
            background: rgba(0, 0, 0, .05);
            color: var(--forest);
        }

        @media (max-width: 1180px) {
            .cal-layout {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 860px) {
            .cal-cell {
                min-height: 92px;
                padding: 5px;
            }

            .cal-ev-who {
                display: none;
            }

            .cal-week {
                grid-template-columns: minmax(0, 1fr);
            }

            .cal-week-col {
                min-height: 0;
                border-right: none;
                border-bottom: 1px solid var(--border);
            }
        }

        @media (max-width: 700px) {
            .cal-toolbar {
                justify-content: flex-start;
            }

            .cal-views {
                width: 100%;
            }

            .cal-views a {
                flex: 1 1 0;
                text-align: center;
                padding: 7px 6px;
            }

            /* A month grid at phone width can't hold readable chips, so each
               session becomes a tap-target dot; the day number opens the day
               view and the dot opens the session itself. */
            .cal-grid .cal-cell {
                min-height: 62px;
                align-items: center;
                gap: 4px;
            }

            .cal-grid .cal-evs {
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: center;
                gap: 4px;
            }

            .cal-grid .cal-ev {
                width: 10px;
                height: 10px;
                padding: 0;
                border: none;
                border-radius: 50%;
                background: var(--ev);
            }

            .cal-grid .cal-ev-time,
            .cal-grid .cal-ev-title,
            .cal-grid .cal-ev-who {
                display: none;
            }

            .cal-agenda-time {
                width: 96px;
                font-size: 11.5px;
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main fade-in">

            <div class="page-hd" style="display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap;">
                <div>
                    <div class="cal-eyebrow">Calendar</div>
                    <h1>Your Mentorship Calendar</h1>
                    <p>Manage your sessions, never miss a meeting, and stay on track with your goals.</p>
                </div>

                <div class="pcard" style="margin:0;flex:0 1 470px;min-width:280px;">
                    <div class="pcard-body" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                        <div class="cal-gcal-logo" aria-hidden="true">31</div>

                        <div style="flex:1;min-width:160px;">
                            <?php if ($gcal): ?>
                                <div style="font-size:13.5px;font-weight:700;color:var(--forest);display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
                                    Google Calendar connected
                                    <?php if (!empty($gcal['last_error'])): ?>
                                        <span class="chip" style="color:var(--danger);background:var(--danger-bg);">Needs attention</span>
                                    <?php else: ?>
                                        <span class="chip" style="color:var(--success);background:var(--success-bg, #E8F5EF);">Syncing</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:12px;color:var(--gray-500);line-height:1.5;">
                                    <?= $gcal['google_email'] ? htmlspecialchars($gcal['google_email']) . ' · ' : '' ?>
                                    <?= $approved_total ?> approved session<?= $approved_total === 1 ? '' : 's' ?> kept in sync<?php
                                    if ($gcal['since_sync_secs'] !== null):
                                        $mins = (int)floor((int)$gcal['since_sync_secs'] / 60);
                                        echo ' · synced ' . ($mins < 1 ? 'just now' : ($mins < 60 ? $mins . 'm ago' : floor($mins / 60) . 'h ago'));
                                    endif; ?>
                                </div>
                                <?php if (!empty($gcal['last_error'])): ?>
                                    <div style="font-size:11.5px;color:var(--danger);margin-top:4px;"><?= htmlspecialchars($gcal['last_error']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div style="font-size:13.5px;font-weight:700;color:var(--forest);">Connect Google Calendar</div>
                                <div style="font-size:12px;color:var(--gray-500);line-height:1.5;">
                                    Sync your sessions with Google Calendar for a more organized schedule — they appear on
                                    your phone and laptop automatically.
                                </div>
                            <?php endif; ?>
                        </div>

                        <div style="flex-shrink:0;display:flex;gap:8px;align-items:center;">
                            <?php if ($gcal): ?>
                                <form method="POST" action="<?= htmlspecialchars(url('gcal-action')) ?>" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="sync">
                                    <button type="submit" class="btn btn-ghost btn-sm">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 11a8 8 0 0 0-14-4.5L4 9m0-5v5h5m-5 4a8 8 0 0 0 14 4.5l2-2.5m0 5v-5h-5" /></svg>
                                        Sync now
                                    </button>
                                </form>
                                <form method="POST" action="<?= htmlspecialchars(url('gcal-action')) ?>" style="display:inline;"
                                    data-pc-confirm="Disconnect Google Calendar?&#10;Sessions already added stay in your calendar, but new ones will stop syncing."
                                    data-pc-tone="danger" data-pc-ok="Disconnect">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="disconnect">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);">Disconnect</button>
                                </form>
                            <?php elseif ($gcal_configured): ?>
                                <a href="<?= htmlspecialchars(url('gcal-connect')) ?>" class="btn btn-ghost btn-sm">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1-1" /></svg>
                                    Connect
                                </a>
                            <?php else: ?>
                                <span class="chip" title="GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET are missing from .env">Not configured</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>


            <div class="cal-layout">
                <!-- ═══ Calendar ═══ -->
                <div class="pcard" style="padding:0;overflow:hidden;">
                    <div class="cal-toolbar">
                        <div class="cal-nav">
                            <a class="cal-arrow" href="<?= cal_url($view, $prev) ?>" aria-label="Previous">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m14 6-6 6 6 6" /></svg>
                            </a>
                            <a class="cal-arrow" href="<?= cal_url($view, $next) ?>" aria-label="Next">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m10 6 6 6-6 6" /></svg>
                            </a>
                            <a class="btn btn-ghost btn-sm" href="<?= cal_url($view, $today) ?>">Today</a>
                            <span class="cal-range"><?= htmlspecialchars($rangeLabel) ?></span>
                        </div>

                        <div class="cal-views">
                            <?php foreach ($views as $v): ?>
                                <a href="<?= cal_url($v, $anchor) ?>" class="<?= $v === $view ? 'active' : '' ?>"><?= ucfirst($v) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php
                    // One renderer for the event chips, used by month and week.
                    $chip = function (array $r) use ($statusMeta, $appTz) {
                        [$s, $e] = cal_times($r, $appTz);
                        [$label, $color] = $statusMeta[$r['status']] ?? ['', 'var(--gray-400)'];
                        $bg = 'color-mix(in srgb, ' . $color . ' 12%, transparent)';
                    ?>
                        <button type="button" class="cal-ev"
                            style="--ev: <?= $color ?>; --ev-bg: <?= $bg ?>;"
                            data-id="<?= (int)$r['request_id'] ?>"
                            data-subject="<?= htmlspecialchars($r['subject'], ENT_QUOTES) ?>"
                            data-mentor="<?= htmlspecialchars($r['mentor_name'], ENT_QUOTES) ?>"
                            data-mentor-id="<?= (int)$r['mentor_id'] ?>"
                            data-club="<?= htmlspecialchars((string)$r['club'], ENT_QUOTES) ?>"
                            data-photo="<?= htmlspecialchars((string)$r['profile_image'], ENT_QUOTES) ?>"
                            data-date="<?= $s->format('l, F j, Y') ?>"
                            data-time="<?= $s->format('g:i A') . ' – ' . $e->format('g:i A') ?>"
                            data-duration="<?= (int)$r['duration'] ?>"
                            data-type="<?= $r['session_type'] === 'group' ? 'Group session' : '1-on-1 session' ?>"
                            data-status="<?= htmlspecialchars($r['status']) ?>"
                            data-status-label="<?= htmlspecialchars($label) ?>"
                            data-color="<?= $color ?>"
                            data-ended="<?= $e < new DateTime('now', $appTz) ? '1' : '0' ?>">
                            <span class="cal-ev-time"><?= $s->format('g:i A') ?></span>
                            <span class="cal-ev-title"><?= htmlspecialchars($r['subject']) ?></span>
                            <span class="cal-ev-who">with <?= htmlspecialchars($r['mentor_name']) ?></span>
                        </button>
                    <?php }; ?>

                    <?php if ($view === 'month'): ?>
                        <div class="cal-dow">
                            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d): ?>
                                <span><?= $d ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="cal-grid">
                            <?php
                            $gridStart = (clone $rangeStart)->modify('-' . (int)$rangeStart->format('w') . ' days');
                            $cells     = 42;
                            $cursor    = clone $gridStart;
                            for ($i = 0; $i < $cells; $i++):
                                $key   = $cursor->format('Y-m-d');
                                $isOut = $cursor->format('m') !== $rangeStart->format('m');
                                $isToday = $key === $todayKey;
                                // Trailing all-outside week adds nothing — stop early.
                                if ($i >= 35 && $isOut) break;
                            ?>
                                <div class="cal-cell<?= $isOut ? ' out' : '' ?><?= $isToday ? ' today' : '' ?>">
                                    <a class="cal-daynum" href="<?= cal_url('day', clone $cursor) ?>" title="Open <?= $cursor->format('M j') ?>">
                                        <?= $cursor->format('j') ?>
                                    </a>
                                    <div class="cal-evs">
                                        <?php foreach ($byDay[$key] ?? [] as $r) $chip($r); ?>
                                    </div>
                                </div>
                            <?php
                                $cursor->modify('+1 day');
                            endfor;
                            ?>
                        </div>

                    <?php elseif ($view === 'week'): ?>
                        <div class="cal-week">
                            <?php $cursor = clone $rangeStart;
                            for ($i = 0; $i < 7; $i++):
                                $key = $cursor->format('Y-m-d');
                                $isToday = $key === $todayKey;
                            ?>
                                <div class="cal-week-col<?= $isToday ? ' today' : '' ?>">
                                    <a class="cal-week-hd" href="<?= cal_url('day', clone $cursor) ?>" style="text-decoration:none;display:block;">
                                        <div class="cal-week-dow"><?= $cursor->format('D') ?></div>
                                        <div class="cal-week-num"><?= $cursor->format('j') ?></div>
                                    </a>
                                    <?php if (empty($byDay[$key])): ?>
                                        <div style="font-size:11px;color:var(--gray-300);text-align:center;padding-top:6px;">—</div>
                                    <?php else: ?>
                                        <?php foreach ($byDay[$key] as $r) $chip($r); ?>
                                    <?php endif; ?>
                                </div>
                            <?php $cursor->modify('+1 day');
                            endfor; ?>
                        </div>

                    <?php else: /* day + list share the agenda rows */ ?>
                        <?php if (empty($rows)): ?>
                            <div class="cal-empty">
                                <?= $view === 'day'
                                    ? 'Nothing scheduled on this day.'
                                    : 'No sessions in ' . htmlspecialchars($rangeLabel) . '.' ?>
                            </div>
                        <?php else: ?>
                            <?php foreach ($rows as $r):
                                [$s, $e] = cal_times($r, $appTz);
                                [$label, $color] = $statusMeta[$r['status']] ?? ['', 'var(--gray-400)'];
                            ?>
                                <button type="button" class="cal-agenda-row cal-ev" style="--ev: <?= $color ?>; --ev-bg: transparent; border-left:none;"
                                    data-id="<?= (int)$r['request_id'] ?>"
                                    data-subject="<?= htmlspecialchars($r['subject'], ENT_QUOTES) ?>"
                                    data-mentor="<?= htmlspecialchars($r['mentor_name'], ENT_QUOTES) ?>"
                                    data-mentor-id="<?= (int)$r['mentor_id'] ?>"
                                    data-club="<?= htmlspecialchars((string)$r['club'], ENT_QUOTES) ?>"
                                    data-photo="<?= htmlspecialchars((string)$r['profile_image'], ENT_QUOTES) ?>"
                                    data-date="<?= $s->format('l, F j, Y') ?>"
                                    data-time="<?= $s->format('g:i A') . ' – ' . $e->format('g:i A') ?>"
                                    data-duration="<?= (int)$r['duration'] ?>"
                                    data-type="<?= $r['session_type'] === 'group' ? 'Group session' : '1-on-1 session' ?>"
                                    data-status="<?= htmlspecialchars($r['status']) ?>"
                                    data-status-label="<?= htmlspecialchars($label) ?>"
                                    data-color="<?= $color ?>"
                                    data-ended="<?= $e < new DateTime('now', $appTz) ? '1' : '0' ?>">
                                    <span class="cal-bar"></span>
                                    <span class="cal-agenda-time">
                                        <?php if ($view === 'list'): ?>
                                            <?= $s->format('D, M j') ?><br>
                                        <?php endif; ?>
                                        <?= $s->format('g:i A') ?> – <?= $e->format('g:i A') ?>
                                    </span>
                                    <span style="min-width:0;flex:1;">
                                        <span style="display:block;font-size:13px;font-weight:700;color:var(--forest);"><?= htmlspecialchars($r['subject']) ?></span>
                                        <span style="display:block;font-size:11.5px;color:var(--gray-500);">with <?= htmlspecialchars($r['mentor_name']) ?></span>
                                    </span>
                                    <span class="chip" style="flex-shrink:0;color:<?= $color ?>;"><?= htmlspecialchars($label) ?></span>
                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- ═══ Side rail ═══ -->
                <div style="display:flex;flex-direction:column;gap:16px;">

                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Upcoming Sessions</span>
                            <a href="<?= htmlspecialchars($find_mentor_url) ?>" class="btn btn-primary btn-sm" style="font-size:11.5px;padding:5px 11px;">
                                + Book session
                            </a>
                        </div>
                        <div class="pcard-body">
                            <?php if (!$upcoming): ?>
                                <div class="prow-empty">No upcoming sessions — book one to fill your calendar.</div>
                            <?php else: ?>
                                <?php foreach ($upcoming as $r):
                                    [$s, $e] = cal_times($r, $appTz);
                                    [$label, $color] = $statusMeta[$r['status']] ?? ['', 'var(--gray-400)'];
                                ?>
                                    <a class="cal-up" href="<?= cal_url('day', new DateTime($s->format('Y-m-d'), $appTz)) ?>">
                                        <span style="display:flex;gap:9px;min-width:0;">
                                            <span class="cal-up-dot" style="background:<?= $color ?>;"></span>
                                            <span style="min-width:0;">
                                                <span style="display:block;font-size:12.5px;font-weight:700;color:var(--forest);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($r['subject']) ?></span>
                                                <span style="display:block;font-size:11.5px;color:var(--gray-500);">with <?= htmlspecialchars($r['mentor_name']) ?></span>
                                            </span>
                                        </span>
                                        <span style="text-align:right;flex-shrink:0;">
                                            <span style="display:block;font-size:11.5px;font-weight:600;color:var(--forest);"><?= $s->format('M j, Y') ?></span>
                                            <span style="display:block;font-size:11px;color:var(--gray-500);"><?= $s->format('g:i A') ?> – <?= $e->format('g:i A') ?></span>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Quick Actions</span>
                        </div>
                        <div class="pcard-body">
                            <a class="cal-qa" href="<?= htmlspecialchars($find_mentor_url) ?>">
                                <span class="cal-qa-icon">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2" /><path stroke-linecap="round" d="M3 10h18M8 3v4M16 3v4M12 13v5M9.5 15.5h5" /></svg>
                                </span>
                                <span>
                                    <span style="display:block;font-size:13px;font-weight:700;color:var(--forest);">Book a session</span>
                                    <span style="display:block;font-size:11.5px;color:var(--gray-500);">Find a mentor and pick a time slot</span>
                                </span>
                                <svg class="cal-qa-chev" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" /></svg>
                            </a>

                            <?php if (!$gcal && $gcal_configured): ?>
                                <a class="cal-qa" href="<?= htmlspecialchars(url('gcal-connect')) ?>">
                                    <span class="cal-qa-icon">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1-1" /></svg>
                                    </span>
                                    <span>
                                        <span style="display:block;font-size:13px;font-weight:700;color:var(--forest);">Connect Google Calendar</span>
                                        <span style="display:block;font-size:11.5px;color:var(--gray-500);">Sync your sessions automatically</span>
                                    </span>
                                    <svg class="cal-qa-chev" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" /></svg>
                                </a>
                            <?php elseif ($approved_total > 0): ?>
                                <a class="cal-qa" href="<?= htmlspecialchars($ics_url) ?>">
                                    <span class="cal-qa-icon">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M5 19h14" /></svg>
                                    </span>
                                    <span>
                                        <span style="display:block;font-size:13px;font-weight:700;color:var(--forest);">Export as a file</span>
                                        <span style="display:block;font-size:11.5px;color:var(--gray-500);">
                                            <?= $approved_total ?> session<?= $approved_total === 1 ? '' : 's' ?> as .ics — for Apple Calendar or Outlook
                                        </span>
                                    </span>
                                    <svg class="cal-qa-chev" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" /></svg>
                                </a>
                            <?php endif; ?>

                            <a class="cal-qa" href="<?= htmlspecialchars(url('mentee-sessions')) ?>">
                                <span class="cal-qa-icon">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h10" /></svg>
                                </span>
                                <span>
                                    <span style="display:block;font-size:13px;font-weight:700;color:var(--forest);">Manage all sessions</span>
                                    <span style="display:block;font-size:11.5px;color:var(--gray-500);">Join upcoming sessions or review your history</span>
                                </span>
                                <svg class="cal-qa-chev" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" /></svg>
                            </a>
                        </div>
                    </div>

                    <div class="cal-tip" id="calTip" hidden>
                        <button class="cal-tip-x" type="button" id="calTipClose" aria-label="Dismiss tip">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                        <svg width="20" height="20" style="flex-shrink:0;color:var(--mint);" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.5 18h5M10.5 21h3M12 3a6 6 0 0 1 4 10.5V15H8v-1.5A6 6 0 0 1 12 3Z" /></svg>
                        <div>
                            <div style="font-size:13px;font-weight:700;color:var(--forest);margin-bottom:3px;">Tip</div>
                            <div style="font-size:12px;color:var(--gray-600);line-height:1.55;">
                                <?= $gcal
                                    ? 'Approved sessions go straight into your Google Calendar, so they show up on your phone without you doing anything.'
                                    : 'Connect your Google Calendar to keep all your mentorship sessions in one place, on every device.' ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Session detail -->
    <div id="evModal" class="modal-overlay">
        <div style="background:var(--surface);border-radius:var(--radius-lg);padding:24px;width:440px;max-width:94vw;max-height:88vh;overflow-y:auto;position:relative;box-shadow:var(--shadow-lg);">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px;">
                <div style="min-width:0;">
                    <div id="evStatus" class="chip" style="margin-bottom:7px;"></div>
                    <div id="evSubject" style="font-size:17px;font-weight:700;color:var(--forest);"></div>
                </div>
                <button id="evClose" style="width:30px;height:30px;border-radius:8px;border:1px solid var(--gray-200);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <div style="display:flex;align-items:center;gap:11px;padding:12px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border);margin-bottom:14px;">
                <div id="evAvatar" style="width:40px;height:40px;border-radius:50%;background:var(--mint-faint);color:var(--forest);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;background-size:cover;background-position:center;flex-shrink:0;"></div>
                <div style="min-width:0;">
                    <div id="evMentor" style="font-size:13.5px;font-weight:700;color:var(--forest);"></div>
                    <div id="evClub" style="font-size:11.5px;color:var(--gray-500);"></div>
                </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:9px;font-size:12.5px;margin-bottom:18px;">
                <div style="display:flex;justify-content:space-between;gap:14px;"><span style="color:var(--gray-500);">Date</span><span id="evDate" style="font-weight:600;color:var(--forest);text-align:right;"></span></div>
                <div style="display:flex;justify-content:space-between;gap:14px;"><span style="color:var(--gray-500);">Time</span><span id="evTime" style="font-weight:600;color:var(--forest);text-align:right;"></span></div>
                <div style="display:flex;justify-content:space-between;gap:14px;"><span style="color:var(--gray-500);">Duration</span><span id="evDuration" style="font-weight:600;color:var(--forest);"></span></div>
                <div style="display:flex;justify-content:space-between;gap:14px;"><span style="color:var(--gray-500);">Type</span><span id="evType" style="font-weight:600;color:var(--forest);"></span></div>
            </div>

            <div id="evActions" style="display:flex;flex-direction:column;gap:9px;"></div>
        </div>
    </div>

    <script>
        const ROUTE_JOIN = <?= json_encode(url('video-join')) ?>;
        const ROUTE_MESSAGES = <?= json_encode(url('messages')) ?>;
        const ROUTE_ICS = <?= json_encode(url('session-ics')) ?>;
        // The review form ('Rate this session'), not the My Feedback page.
        const ROUTE_FEEDBACK = <?= json_encode(url('mentee-review')) ?>;

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        }

        function toggleProfileMenu() {
            document.getElementById('profileMenu').classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const m = document.getElementById('profileMenu');
            if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]'))
                m.classList.remove('open');
        });

        // ── Session detail modal ─────────────────────────────────────────
        const evModal = document.getElementById('evModal');

        function closeEv() {
            evModal.classList.remove('open');
        }
        document.getElementById('evClose').addEventListener('click', closeEv);
        evModal.addEventListener('click', e => {
            if (e.target === evModal) closeEv();
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeEv();
        });

        function actionBtn(href, label, primary, icon) {
            const a = document.createElement('a');
            a.href = href;
            a.className = 'btn ' + (primary ? 'btn-primary' : 'btn-ghost');
            a.style.justifyContent = 'center';
            a.innerHTML = (icon || '') + '<span>' + label + '</span>';
            return a;
        }

        document.querySelectorAll('.cal-ev').forEach(el => {
            el.addEventListener('click', () => {
                const d = el.dataset;

                document.getElementById('evSubject').textContent = d.subject;
                document.getElementById('evMentor').textContent = d.mentor;
                document.getElementById('evClub').textContent = d.club || 'No club listed';
                document.getElementById('evDate').textContent = d.date;
                document.getElementById('evTime').textContent = d.time;
                document.getElementById('evDuration').textContent = d.duration + ' minutes';
                document.getElementById('evType').textContent = d.type;

                const badge = document.getElementById('evStatus');
                badge.textContent = d.statusLabel;
                badge.style.color = d.color;

                const av = document.getElementById('evAvatar');
                if (d.photo) {
                    av.style.backgroundImage = 'url("' + d.photo + '")';
                    av.textContent = '';
                } else {
                    av.style.backgroundImage = '';
                    av.textContent = (d.mentor || '?').charAt(0).toUpperCase();
                }

                // Only offer what this session can actually do right now.
                const actions = document.getElementById('evActions');
                actions.innerHTML = '';

                if (d.status === 'approved' && d.ended === '0') {
                    actions.appendChild(actionBtn(ROUTE_JOIN + '?session_id=' + d.id, 'Join session', true,
                        '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="6" width="12" height="12" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="m15 10 6-3v10l-6-3"/></svg>'));
                }
                if (d.status === 'approved') {
                    actions.appendChild(actionBtn(ROUTE_ICS + '?session_id=' + d.id, 'Add to calendar', false,
                        '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M5 19h14"/></svg>'));
                }
                if (d.ended === '1' && (d.status === 'completed' || d.status === 'approved')) {
                    actions.appendChild(actionBtn(ROUTE_FEEDBACK + '?session=' + d.id, 'Rate this session', false,
                        '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5 14.6 9l5.9.8-4.3 4.1 1.1 5.8L12 16.8l-5.3 2.9 1.1-5.8L3.5 9.8 9.4 9 12 3.5Z"/></svg>'));
                }
                actions.appendChild(actionBtn(ROUTE_MESSAGES + '?chat=' + d.mentorId, 'Message ' + d.mentor.split(' ')[0], false,
                    '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12a7 7 0 0 1-7 7H9l-4 3v-4.3A7 7 0 0 1 13 5a7 7 0 0 1 7 7Z"/></svg>'));

                evModal.classList.add('open');
            });
        });

        // ── Tip (per-browser dismissal) ──────────────────────────────────
        (function() {
            const tip = document.getElementById('calTip');
            let dismissed = false;
            try {
                dismissed = localStorage.getItem('pc_cal_tip_dismissed') === '1';
            } catch (e) {}
            if (!dismissed) tip.hidden = false;

            document.getElementById('calTipClose').addEventListener('click', () => {
                tip.hidden = true;
                try {
                    localStorage.setItem('pc_cal_tip_dismissed', '1');
                } catch (e) {}
            });
        })();
    </script>
</body>

</html>
