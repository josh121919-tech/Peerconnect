<?php

/**
 * index.php — the mentor Dashboard.
 *
 * Every figure on this page is counted from the database on load; nothing is
 * seeded or illustrative. Where the reference design showed a control this app
 * has no page for (a mentee profile view, an "all activity" screen), the
 * control is left out rather than rendered dead — see the notes at each spot.
 *
 * Layout, top to bottom:
 *   welcome banner · four stat cards · pending requests (only when there are
 *   any, with the approve/reject actions that already lived here) · today's
 *   schedule / mentee progress / recent activity · active mentees.
 */

date_default_timezone_set('Asia/Manila');
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/GoogleCalendarService.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mentor') {
    header("Location: " . url('welcomepage'));
    exit;
}

// First-login questionnaire, once, before anything is rendered.
require_once __DIR__ . '/../includes/onboarding_gate.php';
pc_onboarding_gate($con, (int)$_SESSION['user_id'], $_SESSION['role']);

$mentor_id = (int)$_SESSION['user_id'];

// SECURITY: Quick-action approve/reject via POST only (was GET — state-changing via GET is insecure)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    // CSRF check
    if (!verify_csrf()) {
        // 403, not 419. 419 is a Laravel convention, not a registered HTTP
        // status: PHP will set it, but Apache does not recognise it and sends
        // 500 instead — so a correctly-rejected request reported itself as a
        // server fault. The rest of the app still does this in ~30 places.
        http_response_code(403);
        exit('CSRF token mismatch.');
    }
    $id     = (int)$_POST['id'];
    $action = $_POST['action'];
    if (in_array($action, ['approve', 'reject'], true)) {
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $con->prepare("UPDATE session_requests SET status=? WHERE request_id=? AND mentor_id=? AND status='pending'");
        $stmt->bind_param("sii", $newStatus, $id, $mentor_id);
        $stmt->execute();
        $notifyMentee = $stmt->affected_rows > 0;
        $stmt->close();

        if ($notifyMentee) {
            // Add to (or drop from) any connected Google Calendar. Never fatal.
            GoogleCalendarService::pushSession($con, $id);
        }

        // Notify mentee
        $nq = $con->prepare("
            SELECT sr.mentee_id, CONCAT(u.firstname,' ',u.lastname) as mentor_name
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentor_id
            WHERE sr.request_id = ? AND sr.mentor_id = ?
            LIMIT 1
        ");
        $nq->bind_param("ii", $id, $mentor_id);
        $nq->execute();
        $nr = $nq->get_result()->fetch_assoc();
        $nq->close();

        if ($notifyMentee && $nr) {
            $link = url('mentee-sessions');
            if ($action === 'approve') {
                NotificationService::sessionApproved($con, (int)$nr['mentee_id'], $nr['mentor_name'], $link);
            } else {
                NotificationService::sessionRejected($con, (int)$nr['mentee_id'], $nr['mentor_name'], $link);
            }
        }
    }
    header("Location: " . url('mentor-dashboard'));
    exit;
}

/** Small helper: run a prepared single-row query with one int parameter. */
$one = function (string $sql) use ($con, $mentor_id) {
    $q = $con->prepare($sql);
    $q->bind_param("i", $mentor_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc() ?: [];
    $q->close();
    return $row;
};

// ── Stat 1: active mentees ────────────────────────────────────────────
// A mentee counts as active once a session with them is approved or done.
$active_mentees = (int)($one("
    SELECT COUNT(DISTINCT mentee_id) c FROM session_requests
    WHERE mentor_id = ? AND status IN ('approved','completed')
")['c'] ?? 0);

// "New this month" is measured on the first session ever booked with each
// mentee, which is a fixed point in the past — the condition pc_trend() needs.
$new_this_month = (int)($one("
    SELECT COUNT(*) c FROM (
        SELECT mentee_id, MIN(session_date) first_on
        FROM session_requests
        WHERE mentor_id = ? AND status IN ('approved','completed')
        GROUP BY mentee_id
    ) f
    WHERE f.first_on >= DATE_FORMAT(NOW(), '%Y-%m-01')
")['c'] ?? 0);

// ── Stat 2: upcoming sessions ─────────────────────────────────────────
$upcoming_count = (int)($one("
    SELECT COUNT(*) c FROM session_requests
    WHERE mentor_id = ? AND status = 'approved' AND session_date >= NOW()
")['c'] ?? 0);

$next_session = $one("
    SELECT sr.request_id, sr.session_date, sr.subject, u.firstname, u.lastname
    FROM session_requests sr JOIN users u ON u.user_id = sr.mentee_id
    WHERE sr.mentor_id = ? AND sr.status = 'approved' AND sr.session_date >= NOW()
    ORDER BY sr.session_date ASC LIMIT 1
");

// ── Stat 3: messages ──────────────────────────────────────────────────
$unread_messages = (int)($one("
    SELECT COUNT(*) c FROM messages WHERE receiver_id = ? AND is_read = 0
")['c'] ?? 0);
$messages_week = (int)($one("
    SELECT COUNT(*) c FROM messages
    WHERE receiver_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
")['c'] ?? 0);

// ── Stat 4: rating ────────────────────────────────────────────────────
$rrow         = $one("SELECT ROUND(AVG(rating),1) r, COUNT(*) cnt FROM feedback WHERE mentor_id = ?");
$avg_rating   = $rrow['r'] ?? null;
$rating_count = (int)($rrow['cnt'] ?? 0);

// ── Pending requests (the actions that already lived on this page) ────
$rq = $con->prepare("
    SELECT sr.*, u.firstname, u.lastname
    FROM session_requests sr JOIN users u ON sr.mentee_id = u.user_id
    WHERE sr.mentor_id = ? AND sr.status = 'pending'
    ORDER BY sr.session_date ASC
");
$rq->bind_param("i", $mentor_id);
$rq->execute();
$pending_rows = $rq->get_result()->fetch_all(MYSQLI_ASSOC);
$rq->close();
$pending = count($pending_rows);

// ── Today's schedule ──────────────────────────────────────────────────
$tq = $con->prepare("
    SELECT sr.request_id, sr.session_date, sr.subject, sr.status,
           u.user_id AS mentee_id, u.firstname, u.lastname
    FROM session_requests sr JOIN users u ON u.user_id = sr.mentee_id
    WHERE sr.mentor_id = ? AND DATE(sr.session_date) = CURDATE()
      AND sr.status IN ('approved','completed')
    ORDER BY sr.session_date ASC
");
$tq->bind_param("i", $mentor_id);
$tq->execute();
$today_rows = $tq->get_result()->fetch_all(MYSQLI_ASSOC);
$tq->close();

// ── Mentee progress ───────────────────────────────────────────────────
// `goals` is the table that actually models progress (one row per goal, with
// a status), so the bar is goals completed over goals set. Most pairs have no
// goals yet and only a mentee can create one, so those rows show their session
// count instead of an invented percentage.
$pq = $con->prepare("
    SELECT u.user_id, u.firstname, u.lastname,
           SUM(sr.status = 'completed')                        AS done,
           MAX(sr.subject)                                     AS subject,
           (SELECT COUNT(*) FROM goals g
             WHERE g.mentee_id = u.user_id AND g.mentor_id = ?) AS goals_total,
           (SELECT COUNT(*) FROM goals g
             WHERE g.mentee_id = u.user_id AND g.mentor_id = ?
               AND g.status = 'completed')                      AS goals_done
    FROM session_requests sr
    JOIN users u ON u.user_id = sr.mentee_id
    WHERE sr.mentor_id = ? AND sr.status IN ('approved','completed')
    GROUP BY u.user_id, u.firstname, u.lastname
    ORDER BY done DESC, u.firstname ASC
    LIMIT 5
");
$pq->bind_param("iii", $mentor_id, $mentor_id, $mentor_id);
$pq->execute();
$progress_rows = $pq->get_result()->fetch_all(MYSQLI_ASSOC);
$pq->close();

// ── Recent activity ───────────────────────────────────────────────────
// Straight from this mentor's notifications, each with the link the
// notification itself carries, so every row goes somewhere real.
$aq = $con->prepare("
    SELECT type, title, message, link, is_read, created_at
    FROM notifications WHERE user_id = ?
    ORDER BY created_at DESC LIMIT 5
");
$aq->bind_param("i", $mentor_id);
$aq->execute();
$activity_rows = $aq->get_result()->fetch_all(MYSQLI_ASSOC);
$aq->close();

// ── Active mentees ────────────────────────────────────────────────────
$mq = $con->prepare("
    SELECT u.user_id, u.firstname, u.lastname, pr.profile_image,
           MIN(sr.session_date)                                   AS since_on,
           SUM(sr.status = 'completed')                           AS done,
           SUM(sr.status = 'approved' AND sr.session_date >= NOW()) AS upcoming,
           (SELECT MIN(sr2.request_id) FROM session_requests sr2
             WHERE sr2.mentor_id = sr.mentor_id AND sr2.mentee_id = u.user_id
               AND sr2.status = 'approved' AND sr2.session_date >= NOW()) AS next_id,
           (SELECT ROUND(AVG(f.rating),1) FROM feedback f
             WHERE f.mentor_id = sr.mentor_id AND f.mentee_id = u.user_id)  AS their_rating
    FROM session_requests sr
    JOIN users u ON u.user_id = sr.mentee_id
    LEFT JOIN profile pr ON pr.user_id = u.user_id
    WHERE sr.mentor_id = ? AND sr.status IN ('approved','completed')
    GROUP BY u.user_id, u.firstname, u.lastname, pr.profile_image, sr.mentor_id
    ORDER BY upcoming DESC, since_on DESC
    LIMIT 6
");
$mq->bind_param("i", $mentor_id);
$mq->execute();
$mentee_rows = $mq->get_result()->fetch_all(MYSQLI_ASSOC);
$mq->close();

$mentor_name = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? '')) ?: 'Mentor';
$first_name  = $_SESSION['firstname'] ?? 'Mentor';

/** Icon + tint for an activity row, by notification type. */
function md_activity_icon(string $type): array
{
    $map = [
        'new_message'          => ['chat',  'si-purple'],
        'session_request'      => ['users', 'si-teal'],
        'session_approved'     => ['check', 'si-teal'],
        'session_rejected'     => ['cal',   'si-orange'],
        'session_cancelled'    => ['cal',   'si-orange'],
        'session_completed'    => ['check', 'si-teal'],
        'feedback_received'    => ['star',  'si-orange'],
        'assessment_submitted' => ['doc',   'si-blue'],
    ];
    return $map[$type] ?? ['bell', 'si-blue'];
}

$active_page = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — PeerConnect Mentor</title>
    <?php include __DIR__ . '/includes/style.php'; ?>
    <style>
        /* ── Pending requests band ── */
        .md-pending {
            margin-bottom: 22px;
            padding: 0;
            overflow: hidden;
        }

        .md-pending-hd {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--warning-bg);
        }

        .md-pending-hd h2 {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: var(--forest);
        }

        .md-pending-hd .md-count {
            margin-left: auto;
            font-size: 12.5px;
            color: var(--gray-600);
        }

        .md-req {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 20px;
            flex-wrap: wrap;
        }

        .md-req + .md-req {
            border-top: 1px solid var(--border);
        }

        .md-req-main {
            flex: 1 1 240px;
            min-width: 0;
        }

        .md-req-name {
            font-size: 14px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .md-req-meta {
            font-size: 12.5px;
            color: var(--gray-500);
            margin-top: 2px;
        }

        .md-req-msg {
            font-size: 12.5px;
            color: var(--gray-600);
            margin-top: 6px;
            line-height: 1.5;
        }

        .md-req-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        /* ── Three-column row ── */
        .md-row {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }

        .md-panel {
            display: flex;
            flex-direction: column;
            padding: 0;
        }

        .md-panel-hd {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 16px 18px 12px;
        }

        .md-panel-hd h2 {
            margin: 0;
            font-size: 14.5px;
            font-weight: 700;
            color: var(--forest);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .md-panel-hd h2 svg {
            width: 17px;
            height: 17px;
            color: var(--mint);
        }

        .md-panel-hd a {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--mint);
            text-decoration: none;
            flex-shrink: 0;
        }

        .md-panel-hd a:hover {
            text-decoration: underline;
        }

        .md-panel-body {
            padding: 0 18px 16px;
            flex: 1;
        }

        .md-panel-ft {
            padding: 12px 18px;
            border-top: 1px solid var(--border);
            text-align: center;
        }

        .md-panel-ft a {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--mint);
            text-decoration: none;
        }

        .md-panel-ft a:hover {
            text-decoration: underline;
        }

        /* ── Today's schedule ── */
        .md-slot {
            display: flex;
            gap: 12px;
            padding: 12px 0;
            align-items: flex-start;
        }

        .md-slot + .md-slot {
            border-top: 1px solid var(--border);
        }

        .md-time {
            flex: 0 0 52px;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--forest);
            line-height: 1.35;
            padding-top: 1px;
        }

        .md-time span {
            display: block;
            font-size: 10.5px;
            font-weight: 600;
            color: var(--gray-400);
        }

        .md-slot-main {
            flex: 1;
            min-width: 0;
        }

        .md-slot-title {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--gray-900);
            line-height: 1.35;
        }

        .md-slot-who {
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 2px;
        }

        .md-slot-badges {
            display: flex;
            gap: 6px;
            margin-top: 7px;
            flex-wrap: wrap;
        }

        /* ── Mentee progress ── */
        .md-prog {
            display: flex;
            gap: 11px;
            padding: 12px 0;
            align-items: center;
        }

        .md-prog + .md-prog {
            border-top: 1px solid var(--border);
        }

        .md-prog-main {
            flex: 1;
            min-width: 0;
        }

        .md-prog-top {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 8px;
        }

        .md-prog-name {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--gray-900);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .md-prog-pct {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--forest);
            flex-shrink: 0;
        }

        .md-prog-sub {
            font-size: 11.5px;
            color: var(--gray-500);
            margin: 2px 0 7px;
        }

        .md-prog-fact {
            font-size: 12px;
            color: var(--gray-500);
        }

        /* ── Recent activity ── */
        .md-act {
            display: flex;
            gap: 11px;
            padding: 12px 0;
            align-items: flex-start;
        }

        .md-act + .md-act {
            border-top: 1px solid var(--border);
        }

        .md-act-ico {
            flex: 0 0 30px;
            width: 30px;
            height: 30px;
            border-radius: 9px;
            display: grid;
            place-items: center;
        }

        .md-act-ico svg {
            width: 15px;
            height: 15px;
        }

        .md-act-main {
            flex: 1;
            min-width: 0;
        }

        .md-act-title {
            font-size: 12.8px;
            font-weight: 600;
            color: var(--gray-900);
            line-height: 1.4;
        }

        .md-act-sub {
            font-size: 11.8px;
            color: var(--gray-500);
            margin-top: 2px;
            line-height: 1.45;
        }

        .md-act-time {
            font-size: 11px;
            color: var(--gray-400);
            flex-shrink: 0;
            white-space: nowrap;
            padding-top: 1px;
        }

        a.md-act {
            text-decoration: none;
            color: inherit;
        }

        a.md-act:hover .md-act-title {
            color: var(--mint);
        }

        /* ── Active mentees ── */
        .md-mentees {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 14px;
        }

        .md-mentee {
            padding: 16px 18px;
        }

        .md-mentee-top {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .md-mentee-id {
            flex: 1;
            min-width: 0;
        }

        .md-mentee-name {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 14px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .md-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--success);
            flex-shrink: 0;
        }

        .md-mentee-sub {
            font-size: 12.3px;
            color: var(--gray-500);
            margin-top: 3px;
        }

        .md-mentee-actions {
            display: flex;
            flex-direction: column;
            gap: 7px;
            flex-shrink: 0;
        }

        .md-mentee-ft {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-top: 14px;
            padding-top: 11px;
            border-top: 1px solid var(--border);
            font-size: 11.8px;
            color: var(--gray-500);
        }

        .md-mentee-ft span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .md-mentee-ft svg {
            width: 13px;
            height: 13px;
            color: var(--gray-400);
        }

        .md-empty {
            padding: 26px 4px;
            text-align: center;
            font-size: 12.8px;
            color: var(--gray-400);
            line-height: 1.6;
        }

        .md-empty a {
            color: var(--mint);
            font-weight: 600;
            text-decoration: none;
        }

        @media (max-width: 1100px) {
            .md-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .md-row {
                grid-template-columns: minmax(0, 1fr);
            }

            .md-req-actions {
                width: 100%;
            }

            .md-req-actions form {
                flex: 1;
            }

            .md-req-actions .btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main fade-in">
            

            <!-- Welcome banner -->
            <div class="hero-banner">
                <div>
                    <h2>Welcome back, <?= htmlspecialchars($first_name) ?>! 👋</h2>
                    <p>Make an impact. Inspire growth.</p>
                </div>
                <div class="hero-illustration" aria-hidden="true">
                    <svg viewBox="0 0 220 110" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="14" y="74" width="86" height="5" rx="2.5" fill="var(--mint-soft)" />
                        <rect x="120" y="74" width="86" height="5" rx="2.5" fill="var(--mint-soft)" />
                        <rect x="30" y="52" width="54" height="22" rx="3" fill="var(--surface)" stroke="var(--mint)" stroke-width="2" />
                        <rect x="136" y="52" width="54" height="22" rx="3" fill="var(--surface)" stroke="var(--mint)" stroke-width="2" />
                        <circle cx="57" cy="30" r="12" fill="var(--mint-soft)" />
                        <path d="M43 52c0-7.7 6.3-14 14-14s14 6.3 14 14" fill="var(--mint)" opacity=".5" />
                        <circle cx="163" cy="30" r="12" fill="var(--mint-soft)" />
                        <path d="M149 52c0-7.7 6.3-14 14-14s14 6.3 14 14" fill="var(--purple)" opacity=".35" />
                        <rect x="86" y="16" width="34" height="20" rx="7" fill="var(--forest)" />
                        <circle cx="96" cy="26" r="2" fill="#fff" />
                        <circle cx="103" cy="26" r="2" fill="#fff" />
                        <circle cx="110" cy="26" r="2" fill="#fff" />
                        <path d="M96 36l6 7 2-7z" fill="var(--forest)" />
                        <path d="M196 60c0-6 4-10 4-10s4 4 4 10-4 8-4 8-4-2-4-8Z" fill="var(--success)" opacity=".45" />
                    </svg>
                </div>
            </div>

            <!-- Stat cards -->
            <div class="stats-grid">
                <div class="stat-card stat-card-icon">
                    <div class="stat-icon si-teal">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 19c0-1.8-1.2-3.3-2.8-3.8M17 4.4a3 3 0 0 1 0 5.2" />
                        </svg>
                    </div>
                    <div>
                        <div class="stat-val"><?= $active_mentees ?></div>
                        <div class="stat-lbl">Active Mentees</div>
                        <?php if ($new_this_month > 0): ?>
                            <div class="stat-trend trend-up">
                                <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" />
                                </svg>
                                <?= $new_this_month ?> new this month
                            </div>
                        <?php else: ?>
                            <div class="stat-fact">None new this month</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stat-card stat-card-icon">
                    <div class="stat-icon si-blue">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="5" y="4" width="14" height="16" rx="3" />
                            <path stroke-linecap="round" d="M8 2v4M16 2v4M5 9h14" />
                        </svg>
                    </div>
                    <div>
                        <div class="stat-val"><?= $upcoming_count ?></div>
                        <div class="stat-lbl">Upcoming Sessions</div>
                        <?php // A forward-looking count has no honest "vs last week" to compare
                        //     against, so this states the next one instead. ?>
                        <div class="stat-fact">
                            <?= $next_session
                                ? 'Next: ' . htmlspecialchars(date('M j, g:i a', strtotime($next_session['session_date'])))
                                : 'None scheduled' ?>
                        </div>
                    </div>
                </div>

                <div class="stat-card stat-card-icon">
                    <div class="stat-icon si-purple">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H12l-5 4v-4h-.5A2.5 2.5 0 0 1 4 13.5v-7Z" />
                        </svg>
                    </div>
                    <div>
                        <div class="stat-val"><?= $unread_messages ?></div>
                        <div class="stat-lbl">Unread Messages</div>
                        <div class="stat-fact"><?= $messages_week ? $messages_week . ' received this week' : 'All caught up' ?></div>
                    </div>
                </div>

                <div class="stat-card stat-card-icon">
                    <div class="stat-icon si-orange">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m12 4 2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4L4.2 9.7l5.4-.8L12 4Z" />
                        </svg>
                    </div>
                    <div>
                        <div class="stat-val"><?= $rating_count > 0 ? htmlspecialchars((string)$avg_rating) : '—' ?></div>
                        <div class="stat-lbl">Average Rating</div>
                        <div class="stat-fact">
                            <?= $rating_count > 0
                                ? 'From ' . $rating_count . ' review' . ($rating_count === 1 ? '' : 's')
                                : 'No reviews yet' ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending requests: only rendered when there are some to act on. -->
            <?php if ($pending > 0): ?>
                <section class="card md-pending">
                    <div class="md-pending-hd">
                        <svg width="17" height="17" fill="none" stroke="var(--warning)" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" d="M12 7.5V12l3 1.8" />
                        </svg>
                        <h2>Session requests waiting on you</h2>
                        <span class="md-count"><?= $pending ?> pending</span>
                    </div>

                    <?php foreach ($pending_rows as $r): ?>
                        <div class="md-req">
                            <div class="md-req-main">
                                <div class="md-req-name"><?= htmlspecialchars($r['firstname'] . ' ' . $r['lastname']) ?></div>
                                <div class="md-req-meta">
                                    <?= htmlspecialchars($r['subject'] ?: 'Session') ?>
                                    &middot; <?= htmlspecialchars(date('D, M j · g:i a', strtotime($r['session_date']))) ?>
                                </div>
                                <?php if (!empty($r['message'])): ?>
                                    <div class="md-req-msg"><?= htmlspecialchars($r['message']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="md-req-actions">
                                <form method="post" action="<?= htmlspecialchars(url('mentor-dashboard')) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$r['request_id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button class="btn btn-primary btn-sm" type="submit">Approve</button>
                                </form>
                                <form method="post" action="<?= htmlspecialchars(url('mentor-dashboard')) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$r['request_id'] ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button class="btn btn-outline btn-sm" type="submit">Decline</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <!-- Schedule · progress · activity -->
            <div class="md-row">

                <!-- Today's schedule -->
                <section class="card md-panel">
                    <div class="md-panel-hd">
                        <h2>
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <rect x="4" y="5" width="16" height="15" rx="2.5" />
                                <path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16" />
                            </svg>
                            Today's Schedule
                        </h2>
                        <a href="<?= htmlspecialchars(url('mentor-calendar')) ?>">View Calendar</a>
                    </div>

                    <div class="md-panel-body">
                        <?php if (!$today_rows): ?>
                            <p class="md-empty">
                                Nothing scheduled for today.<br>
                                <?= $next_session
                                    ? 'Next session ' . htmlspecialchars(date('D, M j', strtotime($next_session['session_date'])))
                                    : 'Open some availability so mentees can book you.' ?>
                            </p>
                        <?php else: ?>
                            <?php foreach ($today_rows as $s):
                                $ts = strtotime($s['session_date']);
                                $isDone = $s['status'] === 'completed';
                                // The lobby checks the clock and the session's own
                                // window, so it is safe to offer at any time today.
                                $joinUrl = url('video-join') . '?session_id=' . (int)$s['request_id'];
                            ?>
                                <div class="md-slot">
                                    <div class="md-time">
                                        <?= htmlspecialchars(date('g:i', $ts)) ?>
                                        <span><?= htmlspecialchars(date('A', $ts)) ?></span>
                                    </div>
                                    <div class="md-slot-main">
                                        <div class="md-slot-title"><?= htmlspecialchars($s['subject'] ?: 'Session') ?></div>
                                        <div class="md-slot-who">with <?= htmlspecialchars($s['firstname'] . ' ' . $s['lastname']) ?></div>
                                        <div class="md-slot-badges">
                                            <span class="badge <?= $isDone ? 'badge-completed' : 'badge-approved' ?>">
                                                <?= $isDone ? 'Completed' : 'Approved' ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if (!$isDone): ?>
                                        <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars($joinUrl) ?>" style="flex-shrink:0;">Join</a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="md-panel-ft">
                        <a href="<?= htmlspecialchars(url('mentor-requests')) ?>">View All Sessions</a>
                    </div>
                </section>

                <!-- Mentee progress -->
                <section class="card md-panel">
                    <div class="md-panel-hd">
                        <h2>
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" d="M5 19V11M12 19V5M19 19v-5" />
                            </svg>
                            Mentee Progress
                        </h2>
                    </div>

                    <div class="md-panel-body">
                        <?php if (!$progress_rows): ?>
                            <p class="md-empty">No mentees yet.<br>Progress appears once a session is approved.</p>
                        <?php else: ?>
                            <?php foreach ($progress_rows as $p):
                                $gTotal = (int)$p['goals_total'];
                                $gDone  = (int)$p['goals_done'];
                                $pct    = $gTotal > 0 ? (int)round($gDone / $gTotal * 100) : null;
                                $done   = (int)$p['done'];
                            ?>
                                <div class="md-prog">
                                    <span class="pc-avatar"><?= htmlspecialchars(strtoupper(substr($p['firstname'], 0, 1))) ?></span>
                                    <div class="md-prog-main">
                                        <div class="md-prog-top">
                                            <span class="md-prog-name"><?= htmlspecialchars($p['firstname'] . ' ' . $p['lastname']) ?></span>
                                            <?php if ($pct !== null): ?>
                                                <span class="md-prog-pct"><?= $pct ?>%</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="md-prog-sub"><?= htmlspecialchars($p['subject'] ?: 'Mentoring') ?></div>

                                        <?php if ($pct !== null): ?>
                                            <div class="pc-progress">
                                                <div class="pc-progress-fill" style="width:<?= $pct ?>%"></div>
                                            </div>
                                            <div class="md-prog-fact" style="margin-top:5px;">
                                                <?= $gDone ?> of <?= $gTotal ?> goal<?= $gTotal === 1 ? '' : 's' ?> completed
                                            </div>
                                        <?php else: ?>
                                            <?php // No goals on record for this pair, and only the mentee can
                                            //     create one — so state the sessions instead of inventing a bar. ?>
                                            <div class="md-prog-fact">
                                                <?= $done ?> session<?= $done === 1 ? '' : 's' ?> completed &middot; no goals set yet
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Recent activity -->
                <section class="card md-panel">
                    <div class="md-panel-hd">
                        <h2>
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l2.5 1.5" />
                                <circle cx="12" cy="12" r="8.5" />
                            </svg>
                            Recent Activity
                        </h2>
                    </div>

                    <div class="md-panel-body">
                        <?php if (!$activity_rows): ?>
                            <p class="md-empty">Nothing yet.<br>Requests, messages and ratings show up here.</p>
                        <?php else: ?>
                            <?php foreach ($activity_rows as $a):
                                [$ico, $tint] = md_activity_icon((string)$a['type']);
                                $when = strtotime($a['created_at']);
                                $mins = max(0, (int)floor((time() - $when) / 60));
                                if ($mins < 60)          $ago = $mins . 'm ago';
                                elseif ($mins < 1440)    $ago = floor($mins / 60) . 'h ago';
                                elseif ($mins < 2880)    $ago = 'Yesterday';
                                else                     $ago = floor($mins / 1440) . 'd ago';
                                // Only wrap in a link when the notification carries one.
                                $href = trim((string)($a['link'] ?? ''));
                                $tag  = $href !== '' ? 'a' : 'div';
                            ?>
                                <<?= $tag ?> class="md-act" <?= $href !== '' ? 'href="' . htmlspecialchars($href) . '"' : '' ?>>
                                    <span class="md-act-ico stat-icon <?= $tint ?>">
                                        <?php if ($ico === 'chat'): ?>
                                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H12l-5 4v-4h-.5A2.5 2.5 0 0 1 4 13.5v-7Z" /></svg>
                                        <?php elseif ($ico === 'check'): ?>
                                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5" /><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.3 2.4 2.4 4.6-4.9" /></svg>
                                        <?php elseif ($ico === 'star'): ?>
                                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m12 4 2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4L4.2 9.7l5.4-.8L12 4Z" /></svg>
                                        <?php elseif ($ico === 'doc'): ?>
                                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5M9 13h6M9 17h4" /></svg>
                                        <?php elseif ($ico === 'users'): ?>
                                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.5 19c0-2.1-1.7-3.8-3.8-3.8h-.4C9.2 15.2 7.5 16.9 7.5 19" /><circle cx="11.5" cy="9" r="3.2" /><path stroke-linecap="round" d="M17.5 12.2a2.6 2.6 0 0 0 0-5M20.5 18c0-1.6-1-3-2.5-3.5" /></svg>
                                        <?php elseif ($ico === 'cal'): ?>
                                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2.5" /><path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16" /></svg>
                                        <?php else: ?>
                                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 15V10a6 6 0 1 0-12 0v5l-1.5 2.5h15L18 15Z" /><path stroke-linecap="round" d="M10 20h4" /></svg>
                                        <?php endif; ?>
                                    </span>
                                    <div class="md-act-main">
                                        <div class="md-act-title"><?= htmlspecialchars($a['title']) ?></div>
                                        <div class="md-act-sub"><?= htmlspecialchars($a['message']) ?></div>
                                    </div>
                                    <span class="md-act-time"><?= htmlspecialchars($ago) ?></span>
                                </<?= $tag ?>>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <!-- Active mentees -->
            <section class="card" style="padding:18px 20px;">
                <div class="md-panel-hd" style="padding:0 0 14px;">
                    <h2>
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" />
                        </svg>
                        Active Mentees
                    </h2>
                </div>

                <?php if (!$mentee_rows): ?>
                    <p class="md-empty">
                        No active mentees yet.<br>
                        Mentees appear here once you approve one of their session requests.
                    </p>
                <?php else: ?>
                    <div class="md-mentees">
                        <?php foreach ($mentee_rows as $m):
                            $img   = !empty($m['profile_image']) ? asset($m['profile_image']) : null;
                            $upc   = (int)$m['upcoming'];
                            $done  = (int)$m['done'];
                            $rate  = $m['their_rating'];
                            $chat  = url('messages') . '?chat=' . (int)$m['user_id'];
                        ?>
                            <div class="card md-mentee">
                                <div class="md-mentee-top">
                                    <span class="pc-avatar pc-avatar-lg">
                                        <?php if ($img): ?>
                                            <img src="<?= htmlspecialchars($img) ?>" alt="">
                                        <?php else: ?>
                                            <?= htmlspecialchars(strtoupper(substr($m['firstname'], 0, 1))) ?>
                                        <?php endif; ?>
                                    </span>
                                    <div class="md-mentee-id">
                                        <div class="md-mentee-name">
                                            <?= htmlspecialchars($m['firstname'] . ' ' . $m['lastname']) ?>
                                            <?php if ($upc > 0): ?><span class="md-dot" title="Has an upcoming session"></span><?php endif; ?>
                                        </div>
                                        <div class="md-mentee-sub">
                                            Since <?= htmlspecialchars(date('M j, Y', strtotime($m['since_on']))) ?>
                                        </div>
                                    </div>
                                    <div class="md-mentee-actions">
                                        <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($chat) ?>">Message</a>
                                        <?php if ($upc > 0 && !empty($m['next_id'])): ?>
                                            <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars(url('video-join') . '?session_id=' . (int)$m['next_id']) ?>">Session</a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="md-mentee-ft">
                                    <span>
                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <rect x="4" y="5" width="16" height="15" rx="2.5" />
                                            <path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16" />
                                        </svg>
                                        <?= $upc ?> upcoming
                                    </span>
                                    <span>
                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <circle cx="12" cy="12" r="8.5" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.3 2.4 2.4 4.6-4.9" />
                                        </svg>
                                        <?= $done ?> completed
                                    </span>
                                    <?php if ($rate !== null): ?>
                                        <span title="How this mentee rated you">
                                            <svg fill="none" stroke="var(--warning)" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m12 4 2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4L4.2 9.7l5.4-.8L12 4Z" />
                                            </svg>
                                            <?= htmlspecialchars(number_format((float)$rate, 1)) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>

</html>
