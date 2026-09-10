<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
// Role, not just "signed in": every query below runs as $mentee_id, so a
// mentor landing here was shown a mentee dashboard built from their own id —
// an incoherent page, and the onboarding gate ran with the wrong role.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentee') {
    header("Location: " . url('welcomepage'));
    exit;
}
date_default_timezone_set('Asia/Manila');
$mentee_id = $_SESSION['user_id'];
$appTz = new DateTimeZone('Asia/Manila');
$menteeSessionAlerts = [];
$now = new DateTime('now', $appTz);

$filter = $_GET['status'] ?? 'all';
$where = "WHERE sr.mentee_id = $mentee_id";
if ($filter !== 'all') {
    $safe = $con->real_escape_string($filter);
    $where .= " AND sr.status = '$safe'";
}

$sessions = $con->query("SELECT sr.*, u.firstname, u.lastname FROM session_requests sr JOIN users u ON sr.mentor_id = u.user_id $where ORDER BY sr.session_date DESC");

$allSessions = $con->query("SELECT sr.*, u.firstname, u.lastname FROM session_requests sr JOIN users u ON sr.mentor_id = u.user_id WHERE sr.mentee_id = $mentee_id");
$jsData = [];
while ($r = $allSessions->fetch_assoc()) {
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
$upcoming_count = (int)($con->query("
    SELECT COUNT(*) c FROM session_requests sr
    JOIN users u ON u.user_id = sr.mentor_id
    WHERE sr.mentee_id = $mentee_id AND sr.status = 'approved' AND sr.session_date >= NOW()
")->fetch_assoc()['c'] ?? 0);

$this_month_count = (int)($con->query("
    SELECT COUNT(*) c FROM session_requests
    WHERE mentee_id = $mentee_id AND session_date >= '$curMonthStart' AND session_date < '$curMonthEnd'
")->fetch_assoc()['c'] ?? 0);

$completed_count = (int)($con->query("
    SELECT COUNT(*) c FROM session_requests WHERE mentee_id = $mentee_id AND status = 'completed'
")->fetch_assoc()['c'] ?? 0);

$cancelled_count = (int)($con->query("
    SELECT COUNT(*) c FROM session_requests WHERE mentee_id = $mentee_id AND status IN ('rejected','cancelled')
")->fetch_assoc()['c'] ?? 0);

$total_count = (int)($con->query("
    SELECT COUNT(*) c FROM session_requests WHERE mentee_id = $mentee_id
")->fetch_assoc()['c'] ?? 0);

// ── Upcoming Sessions list ─────────────────────────────────────────────
$upcoming_res = $con->query("
    SELECT sr.request_id, sr.subject, sr.session_date, u.firstname, u.lastname,
           pr.profile_image, a.session_type, a.duration, a.topics
    FROM session_requests sr
    JOIN users u ON sr.mentor_id = u.user_id
    LEFT JOIN profile pr ON pr.user_id = u.user_id
    LEFT JOIN availability a ON a.mentor_id = sr.mentor_id AND a.subject = sr.subject
        AND DATE(a.date) = DATE(sr.session_date) AND TIME(a.start_time) = TIME(sr.session_date)
    WHERE sr.mentee_id = $mentee_id AND sr.status = 'approved' AND sr.session_date >= NOW()
    ORDER BY sr.session_date ASC
    LIMIT 5
");
$upcoming_list = [];
while ($row = $upcoming_res->fetch_assoc()) {
    $upcoming_list[] = $row;
}

// ── Past Sessions list (completed / rejected / cancelled / missed) ─────
// 'missed' is in this list because it is not in any other one: it is not
// 'approved', so the Upcoming list skips it too, and a missed session used to
// disappear from the mentee's view entirely.
$past_res = $con->query("
    SELECT sr.request_id, sr.subject, sr.session_date, sr.status, u.firstname, u.lastname,
           pr.profile_image, f.rating
    FROM session_requests sr
    JOIN users u ON sr.mentor_id = u.user_id
    LEFT JOIN profile pr ON pr.user_id = u.user_id
    LEFT JOIN feedback f ON f.session_id = sr.request_id AND f.mentee_id = sr.mentee_id
    WHERE sr.mentee_id = $mentee_id AND sr.status IN ('completed','rejected','cancelled','missed')
    ORDER BY sr.session_date DESC
    LIMIT 4
");
$past_list = [];
while ($row = $past_res->fetch_assoc()) {
    $past_list[] = $row;
}

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

$cal_dot_map = [];
$cal_res = $con->query("
    SELECT DATE(session_date) d, COUNT(*) c FROM session_requests
    WHERE mentee_id = $mentee_id AND session_date >= '$cal_start' AND session_date < '$cal_end'
    GROUP BY DATE(session_date)
");
while ($row = $cal_res->fetch_assoc()) {
    $cal_dot_map[$row['d']] = (int)$row['c'];
}

$today_str = date('Y-m-d');
$todays_sessions = (int)($con->query("
    SELECT COUNT(*) c FROM session_requests WHERE mentee_id = $mentee_id AND DATE(session_date) = '$today_str'
")->fetch_assoc()['c'] ?? 0);

$week_start = date('Y-m-d 00:00:00', strtotime('sunday this week', strtotime('-1 day')));
$week_end   = date('Y-m-d 00:00:00', strtotime($week_start . ' +7 days'));
$this_week_sessions = (int)($con->query("
    SELECT COUNT(*) c FROM session_requests
    WHERE mentee_id = $mentee_id AND session_date >= '$week_start' AND session_date < '$week_end'
")->fetch_assoc()['c'] ?? 0);

$calendar_url = url('mentee-calendar');
$active_page = 'sessions';
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

        .ss-filter-chips {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 16px;
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
            background: var(--forest);
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

        /* Five stat cards on this page, not the shared four — Total Sessions
           joined them when the duplicate Session Overview card was removed. */
        .stats-grid {
            grid-template-columns: repeat(5, minmax(0, 1fr));
        }

        @media (max-width: 1080px) {
            .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 860px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main fade-in">
            <div class="page-hd" style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h1>Sessions</h1>
                    <p>Manage your mentorship sessions, track your progress, and stay on top of your learning journey.</p>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-top:4px;">
                    <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('historyModal').classList.add('open')">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 3" /></svg>
                        History
                    </button>                </div>
            </div>

            <!-- Stat cards -->
            <div class="stats-grid">
                <div class="stat-card stat-card-icon">
                    <div class="stat-icon si-teal">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="16" rx="3" /><path stroke-linecap="round" d="M8 2v4M16 2v4M5 9h14" /></svg>
                    </div>
                    <div>
                        <div class="stat-val"><?= $upcoming_count ?></div>
                        <div class="stat-lbl">Upcoming Sessions</div>
                    </div>
                </div>
                <div class="stat-card stat-card-icon">
                    <div class="stat-icon si-purple">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 3" /></svg>
                    </div>
                    <div>
                        <div class="stat-val"><?= $this_month_count ?></div>
                        <div class="stat-lbl">Sessions This Month</div>
                    </div>
                </div>
                <div class="stat-card stat-card-icon">
                    <div class="stat-icon si-orange">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                    <div>
                        <div class="stat-val"><?= $completed_count ?></div>
                        <div class="stat-lbl">Completed Sessions</div>
                    </div>
                </div>
                <div class="stat-card stat-card-icon">
                    <div class="stat-icon si-blue">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" stroke-linejoin="round" d="m9.5 9.5 5 5m0-5-5 5" /></svg>
                    </div>
                    <div>
                        <div class="stat-val"><?= $cancelled_count ?></div>
                        <div class="stat-lbl">Cancelled Sessions</div>
                    </div>
                </div>
                <div class="stat-card stat-card-icon">
                    <div class="stat-icon si-teal">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2" /></svg>
                    </div>
                    <div>
                        <div class="stat-val"><?= $total_count ?></div>
                        <div class="stat-lbl">Total Sessions</div>
                    </div>
                </div>
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
                                $ini = strtoupper(substr($s['firstname'], 0, 1));
                                $sessionDT = new DateTime($s['session_date'], $appTz);
                                $dayLabel = $sessionDT->format('Y-m-d') === $now->format('Y-m-d') ? 'Today'
                                    : ($sessionDT->format('Y-m-d') === (clone $now)->modify('+1 day')->format('Y-m-d') ? 'Tomorrow' : $sessionDT->format('D'));
                                $openJoinDT = (clone $sessionDT)->modify('-10 minutes');
                                $isJoinable = $now >= $openJoinDT;
                                $duration = $s['duration'] ? (int)$s['duration'] : 60;
                                $typeLabel = $s['session_type'] === 'group' ? 'Group Mentoring' : ($s['topics'] ?: (($s['subject'] ?: 'General') . ' Mentoring'));
                            ?>
                                <div class="ss-session-row">
                                    <div class="ss-date-col">
                                        <div class="ss-date-day" style="color:var(--mint);"><?= htmlspecialchars($dayLabel) ?></div>
                                        <div class="ss-date-sub"><?= $sessionDT->format('M j') ?></div>
                                        <div class="ss-date-sub"><?= $sessionDT->format('g:i A') ?></div>
                                    </div>
                                    <div class="pc-avatar pc-avatar-md" style="flex-shrink:0;"><?= htmlspecialchars($ini) ?></div>
                                    <div class="ss-session-info">
                                        <div class="ss-session-title"><?= htmlspecialchars($s['subject'] ?: 'General Mentorship') ?></div>
                                        <div class="ss-session-with">with <?= htmlspecialchars($name) ?> <span class="ss-mentor-pill">Mentor</span></div>
                                        <div class="ss-session-sub"><?= htmlspecialchars($typeLabel) ?></div>
                                    </div>
                                    <div class="ss-meta-col">
                                        <span><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7v5l3 3" /></svg><?= $duration ?> min</span>
                                        <span><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L15 11.75M11 6h6a2 2 0 012 2v6M13 18H7a2 2 0 01-2-2V6" /></svg>Online</span>
                                    </div>
                                    <?php if ($isJoinable): ?>
                                        <a href="<?= url('video-join') ?>?session_id=<?= $sid ?>" class="btn btn-primary" style="font-size:12px;padding:8px 14px;flex-shrink:0;">
                                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.069A1 1 0 0121 8.867v6.266a1 1 0 01-1.447.9L15 14M3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z" /></svg>
                                            Join Session
                                        </a>
                                    <?php else: ?>
                                        <button class="view-btn btn btn-ghost" data-id="<?= $sid ?>" style="font-size:12px;padding:8px 14px;flex-shrink:0;">View Details</button>
                                    <?php endif; ?>
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
                                $ini = strtoupper(substr($s['firstname'], 0, 1));
                                $sessionDT = new DateTime($s['session_date'], $appTz);
                            ?>
                                <div class="ss-session-row">
                                    <div class="ss-date-col">
                                        <div class="ss-date-day"><?= $sessionDT->format('M j') ?></div>
                                        <div class="ss-date-sub"><?= $sessionDT->format('Y') ?></div>
                                        <div class="ss-date-sub"><?= $sessionDT->format('g:i A') ?></div>
                                    </div>
                                    <div class="pc-avatar pc-avatar-md" style="flex-shrink:0;"><?= htmlspecialchars($ini) ?></div>
                                    <div class="ss-session-info">
                                        <div class="ss-session-title"><?= htmlspecialchars($s['subject'] ?: 'General Mentorship') ?></div>
                                        <div class="ss-session-with">with <?= htmlspecialchars($name) ?> <span class="ss-mentor-pill">Mentor</span></div>
                                    </div>
                                    <div class="ss-meta-col">
                                        <span class="badge badge-<?= $s['status'] ?>"><?= ucfirst($s['status']) ?></span>
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
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <div style="font-size:15px;font-weight:700;color:var(--gray-900);">Session History</div>
                <button id="history-close" style="width:30px;height:30px;border-radius:8px;border:1px solid var(--gray-200);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="ss-filter-chips">
                <?php foreach (['all', 'approved', 'pending', 'completed', 'rejected'] as $st): ?>
                    <a href="?status=<?= $st ?>" class="chip <?= $filter === $st ? 'active' : '' ?>"><?= ucfirst($st) ?></a>
                <?php endforeach; ?>
            </div>
            <div style="overflow-x:auto;">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Mentor</th>
                            <th>Subject</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($sessions && $sessions->num_rows > 0): ?>
                            <?php while ($s = $sessions->fetch_assoc()):
                                $sid        = (int)$s['request_id'];
                                $sdate      = date('m-d-Y', strtotime($s['session_date']));
                                $stime      = date('g:i A', strtotime($s['session_date']));
                                $timeEnd    = !empty($s['session_end']) ? ' – ' . date('g:i A', strtotime($s['session_end'])) : '';
                                $status     = $s['status'];
                                $sessionDT  = new DateTime($s['session_date'], $appTz);
                                $openJoinDT = (clone $sessionDT)->modify('-10 minutes');
                                $isJoinable = $status === 'approved' && $now >= $openJoinDT;
                                $isUpcoming = $status === 'approved' && $now < $openJoinDT;
                                $minsToStart = (int)floor(($sessionDT->getTimestamp() - $now->getTimestamp()) / 60);
                                if ($status === 'approved' && $minsToStart >= 0 && $minsToStart <= 10) {
                                    $menteeSessionAlerts[] = ['key' => 'mentee-sessions-' . $sid, 'message' => 'Session with ' . $s['firstname'] . ' ' . $s['lastname'] . ' starts in ' . $minsToStart . ' minute(s).'];
                                }
                            ?>
                                <tr>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:10px;">
                                            <div class="tbl-avatar"><?= strtoupper(substr($s['firstname'], 0, 1)) ?></div>
                                            <span style="font-weight:600;color:var(--gray-800);"><?= htmlspecialchars($s['firstname'] . ' ' . $s['lastname']) ?></span>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($s['subject'] ?? '') ?></td>
                                    <td><?= $sdate ?></td>
                                    <td><?= $stime . $timeEnd ?></td>
                                    <td><span class="badge badge-<?= $status ?>"><?= ucfirst($status) ?></span></td>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:6px;">
                                            <?php if ($isJoinable): ?>
                                                <a href="/case/case/6b4396b7d830104eb41d706cefe6a991?session_id=<?= $sid ?>" class="btn btn-blue" style="font-size:12px;padding:6px 12px;">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.069A1 1 0 0121 8.867v6.266a1 1 0 01-1.447.9L15 14M3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z" />
                                                    </svg>
                                                    Join Video
                                                </a>
                                            <?php elseif ($isUpcoming): ?>
                                                <span style="font-size:12px;color:var(--gray-400);font-weight:500;">Upcoming</span>
                                            <?php else: ?>
                                                <button class="view-btn btn btn-ghost" data-id="<?= $sid ?>" style="font-size:12px;padding:5px 12px;">View Details</button>
                                            <?php endif; ?>
                                            <?php if ($status === 'approved'): ?>
                                                <a href="<?= url('session-ics') ?>?session_id=<?= $sid ?>" title="Add to calendar" style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:1px solid var(--border);border-radius:7px;color:var(--gray-500);flex-shrink:0;">
                                                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state" style="padding:40px 0;">
                                        <div class="empty-icon">📚</div>
                                        <p style="color:var(--gray-500);font-weight:600;">No sessions found</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
                alert(a.message);
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
