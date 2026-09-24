<?php

/**
 * session_request.php — the mentor Sessions page.
 *
 * This file is the container for the whole Sessions area: the Requests tab is
 * rendered here, and Upcoming / Completed / Group / History are the other
 * standalone pages inlined as tab panels (they are also routes of their own).
 *
 * The Requests tab follows the reference design — stat cards, a search /
 * subject / sort toolbar, a rich card per request, a detail panel and a
 * confirm step before accepting.
 *
 * Where the reference asked for something this data cannot support, it is left
 * out rather than faked, and the reason is noted at the spot:
 *
 *   - "Sent" tab — mentors do not send mentorship requests; mentees request
 *     mentors. There is no such row to list.
 *   - "Requested <date>" — session_requests has no created_at column, only the
 *     date of the session itself. Each card shows the session date, labelled
 *     as such, rather than passing it off as a request date.
 *   - Mentee availability — the `availability` table is keyed by mentor_id;
 *     mentees have none to show.
 *   - Trend lines under the stats ("↑2 from last week") — the same missing
 *     created_at means there is no honest week-over-week to compute.
 */

date_default_timezone_set('Asia/Manila');
session_start();
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/GoogleCalendarService.php';
require_once __DIR__ . '/includes/session_list.php';
// ongoing_session.php and upcoming_session.php below offer the call.
require_once __DIR__ . '/../includes/join_control.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mentor') {
    header("Location: " . url('welcomepage'));
    exit;
}

$mentor_id  = (int)$_SESSION['user_id'];
$self_url   = url('mentor-requests');

// SECURITY: approve/reject/bulk are state-changing — POST + CSRF only (was GET, forgeable via a link)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    if (!verify_csrf()) {
        http_response_code(403);
        exit('CSRF token mismatch.');
    }
    $id     = (int)$_POST['id'];
    $action = $_POST['action'];

    if ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            pc_flash('error', 'A reason is required before a request can be declined.');
            header("Location: " . $self_url);
            exit;
        }
        $changedOne = SessionRepository::rejectByMentor($con, $id, $mentor_id, $reason) > 0;
    } elseif ($action === 'approve') {
        $changedOne = SessionRepository::approveByMentor($con, $id, $mentor_id) > 0;
    } else {
        $changedOne = false;
    }

    if ($changedOne) {
        // Put the session into (or take it out of) any connected Google
        // Calendar. Never fatal — see GoogleCalendarService.
        GoogleCalendarService::pushSession($con, $id);

        $nr = SessionRepository::menteeAndMentorName($con, $id, $mentor_id);

        if ($nr) {
            // An approved session is on the sessions page itself. A declined
            // one is not — it is only in Session History, which opens on
            // ?status. Sending it to the plain page, or to My Requests, left
            // the mentee looking at a list their session was not in.
            if ($action === 'approve') {
                NotificationService::sessionApproved($con, (int)$nr['mentee_id'], $nr['mentor_name'],
                    url('mentee-sessions'));
            } elseif ($action === 'reject') {
                NotificationService::sessionRejected($con, (int)$nr['mentee_id'], $nr['mentor_name'],
                    url('mentee-sessions') . '?status=all');
            }
        }
    }

    if ($changedOne && $action === 'approve') {
        pc_flash('success', 'The session is confirmed and the mentee has been told.', 'Request accepted');
    } elseif ($changedOne && $action === 'reject') {
        pc_flash('success', 'The mentee has been told, along with your reason.', 'Request declined');
    } elseif ($action === 'approve' && SessionRepository::isPastRequestForMentor($con, $id, $mentor_id)) {
        pc_flash('warning', 'Its start time has already passed, so it can no longer be accepted. It will be removed and the mentee told.');
    } elseif ($action === 'approve' || $action === 'reject') {
        pc_flash('warning', 'That request was already handled, so nothing changed.');
    }

    header("Location: " . $self_url);
    exit;
}

/*
 * Removing a student from a group session.
 *
 * This handler lived in group_sessions.php, which was included as the Group
 * tab. That tab is gone — a group session is now a card in Upcoming and
 * Ongoing — so the handler moved here, to the page its form still posts to.
 * The behaviour is unchanged: cancel the booking, tell the student, and
 * refuse once the session has closed.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_request_id'])) {
    if (!verify_csrf()) {
        http_response_code(403);
        exit('CSRF token mismatch.');
    }
    $reqId  = (int)$_POST['remove_request_id'];
    $target = SessionRepository::findForMentor($con, $reqId, $mentor_id);

    $removed = false;
    if ($target) {
        // Only a session that has not closed yet can have a student removed.
        // Without the state guard a mentor could "remove" someone from a
        // session that already completed, which rewrote a finished session as
        // cancelled and moved the mentor's own completion score.
        $removed = SessionRepository::cancelByMentor($con, $reqId, $mentor_id) > 0;

        // Only tell the student if something actually changed. Notifying on a
        // refused removal would announce a cancellation that never happened.
        if ($removed) {
            $mentorName = UserRepository::names($con, $mentor_id);
            if ($mentorName) {
                NotificationService::sessionRejected(
                    $con,
                    (int)$target['mentee_id'],
                    trim($mentorName['firstname'] . ' ' . $mentorName['lastname']),
                    url('mentee-sessions') . '?status=all'
                );
            }
        }
    }

    if (!$target) {
        pc_flash('error', 'That reservation could not be found.');
    } elseif ($removed) {
        pc_flash('success', 'That student was removed from the group session and told.', 'Reservation removed');
    } else {
        pc_flash('warning', 'That reservation could not be removed — the session has already finished or been closed.');
    }

    header('Location: ' . $self_url . '?tab=upcoming');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk'], $_POST['ids'])) {
    if (!verify_csrf()) {
        http_response_code(403);
        exit('CSRF token mismatch.');
    }
    $ids    = explode(',', $_POST['ids']);
    $action = $_POST['bulk'];
    $done   = 0;
    if (in_array($action, ['approve', 'reject'], true)) {
        foreach ($ids as $id) {
            $id = (int)$id;
            $changed = ($action === 'approve'
                ? SessionRepository::approveByMentor($con, $id, $mentor_id)
                : SessionRepository::rejectByMentor($con, $id, $mentor_id)) > 0;
            if ($changed) {
                $done++;
                GoogleCalendarService::pushSession($con, $id);
            }
        }
        if ($done > 0) {
            pc_flash('success',
                $done . ' request' . ($done === 1 ? '' : 's') . ' ' . ($action === 'approve' ? 'accepted' : 'declined') . '.',
                $action === 'approve' ? 'Requests accepted' : 'Requests declined');
        } else {
            pc_flash('warning', 'Those requests had already been handled, so nothing changed.');
        }
    }
    header("Location: " . $self_url);
    exit;
}

// ── Stats ─────────────────────────────────────────────────────────────
// All four are plain counts of rows that exist. No trend lines: without a
// created_at there is no honest "vs last week" to put under them.
$statRow = SessionRepository::statsForMentor($con, $mentor_id);

/*
 * The tab counts come from the same rules the tabs themselves list by, so a
 * card and the tab under it cannot disagree. They used to be taken from
 * statsForMentor(), whose "upcoming" counts every approved session ahead
 * including group ones — which is how a mentor was shown "1 upcoming
 * session" above a list that filtered group sessions out.
 */
$tabCounts = SessionRepository::tabCountsForMentor($con, $mentor_id);

$stat_pending   = $tabCounts['received'];
$stat_upcoming  = $tabCounts['upcoming'];
$stat_completed = $tabCounts['completed'];
$stat_mentees   = (int)($statRow['mentees'] ?? 0);

// Kept for the tab badge and the partials included further down.
$cnt = $stat_pending;

// Requests for one status, with everything a card and the detail panel need.
$pending_rows  = SessionRepository::requestsForMentor($con, $mentor_id, 'pending');
$declined_rows = SessionRepository::requestsForMentor($con, $mentor_id, 'rejected');

// ── "Interested in" chips ─────────────────────────────────────────────
// The mentee's own questionnaire answers — the same rows matching runs on.
$tags_by_user = [];
$mentee_ids = array_unique(array_map(
    fn($r) => (int)$r['mentee_id'],
    array_merge($pending_rows, $declined_rows)
));
foreach (UserRepository::tagsFor($con, $mentee_ids) as $t) {
    $tags_by_user[(int)$t['user_id']][$t['tag_type']][] = $t['tag'];
}

// ── Goals, for the detail panel ───────────────────────────────────────
$goals_by_user = [];
foreach (GoalRepository::forMenteesWithMentor($con, $mentee_ids, $mentor_id) as $g) {
    $goals_by_user[(int)$g['mentee_id']][] = ['title' => $g['title'], 'status' => $g['status']];
}

// Addresses for the Declined cards.
$declined_email = [];
foreach (UserRepository::emailsFor($con, $mentee_ids) as $x) {
    $declined_email[(int)$x['user_id']] = (string)($x['email'] ?? '');
}

/** Distinct subjects across the listed requests — the filter chips. */
$subjects = [];
foreach (array_merge($pending_rows, $declined_rows) as $r) {
    $s = trim((string)$r['subject']);
    if ($s !== '' && !in_array($s, $subjects, true)) $subjects[] = $s;
}
sort($subjects);

/** One request as the shape the client-side detail panel expects. */
function sr_detail(array $r, array $tags_by_user, array $goals_by_user): array
{
    $mid  = (int)$r['mentee_id'];
    $tags = $tags_by_user[$mid] ?? [];
    return [
        'id'       => (int)$r['request_id'],
        'name'     => trim($r['firstname'] . ' ' . $r['lastname']),
        'initial'  => strtoupper(substr($r['firstname'], 0, 1)),
        'image'    => !empty($r['profile_image']) ? asset($r['profile_image']) : '',
        'headline' => trim(trim((string)$r['course']) . ' ' . trim((string)$r['year_level'])),
        'bio'      => trim((string)$r['bio']),
        'subject'  => (string)$r['subject'],
        'message'  => (string)$r['message'],
        'when'     => date('D, M j, Y · g:i a', strtotime($r['session_date'])),
        // Past its start, a request can only be declined (or left to lapse).
        'past'     => strtotime($r['session_date']) <= time(),
        'ts'       => strtotime($r['session_date']),
        'status'   => (string)$r['status'],
        'reason'   => (string)($r['rejection_reason'] ?? ''),
        'learn'    => $tags['learn']    ?? [],
        'skill'    => $tags['skill']    ?? [],
        'interest' => $tags['interest'] ?? [],
        'goals'    => $goals_by_user[$mid] ?? [],
    ];
}

$details = [];
foreach (array_merge($pending_rows, $declined_rows) as $r) {
    $details[(int)$r['request_id']] = sr_detail($r, $tags_by_user, $goals_by_user);
}

$active_page = 'sessions';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sessions — PeerConnect Mentor</title>
    <?php include __DIR__ . '/includes/style.php'; ?>
    <style>
        /* ── Page header ──
           A badge beside the title, the way the reference opens the page. */
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
           Tinted rather than white-on-white, each one its own colour. The
           shared .stat-card classes are left alone: six other pages use them
           and this is meant to change two. */
        .sx-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
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

        .sx-stat-s {
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 4px;
        }

        /* Only rendered where it leads somewhere — see the markup. */
        .sx-stat-go {
            position: absolute;
            top: 18px;
            right: 18px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--gray-50);
            border: 1px solid var(--gray-100);
            border: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: inherit;
        }

        .sx-stat-go:hover {
            background: var(--mint-faint);
            border-color: var(--mint-soft);
        }

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

        @media (max-width: 1000px) {
            .sx-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

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
           four full-size cards stacked pushed everything below them off the
           first screen. Same approach as the onboarding grid. */
        @media (max-width: 560px) {
            .sx-stats { gap: 10px; }
            .sx-hd { gap: 12px; }
            .sx-hd-ico { width: 42px; height: 42px; border-radius: 13px; }
            .sx-hd-ico svg { width: 21px; height: 21px; }
        }

        /* Only at the very narrowest does a single column beat a cramped pair. */
        @media (max-width: 340px) {
            .sx-stats { grid-template-columns: minmax(0, 1fr); }
        }

        /* ── Empty state ──
           The shared .empty-state-lg, given the reference's larger, softer
           treatment on this page only: six other screens use those classes
           and are not part of this change. */
        .empty-state-lg .es-icon {
            width: 104px;
            height: 104px;
            background: var(--info-bg);
        }

        .empty-state-lg .es-icon svg {
            width: 42px;
            height: 42px;
            stroke: var(--info);
        }

        .empty-state-lg .es-title {
            font-size: 19px;
            font-weight: 800;
            color: var(--gray-900);
        }

        .empty-state-lg .es-body {
            max-width: 390px;
            line-height: 1.65;
        }

        /* ── Tabs ──
           A pill bar sitting on its own card rather than an underlined strip.
           The class names are the ones filterSessions() toggles, so this is
           the look changing and nothing else. */
        .tabs {
            display: flex;
            align-items: center;
            gap: 4px;
            background: var(--surface);
            border: 1px solid var(--gray-100);
            border-radius: 16px;
            padding: 7px;
            margin-bottom: 20px;
            overflow-x: auto;
            scrollbar-width: none;
        }

        .tabs::-webkit-scrollbar {
            display: none;
        }

        .tab-btn {
            padding: 10px 15px;
            border: 0;
            border-radius: 12px;
            background: transparent;
            font: inherit;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--gray-500);
            cursor: pointer;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background .15s, color .15s;
        }

        .tab-btn:hover:not(.active) {
            background: var(--gray-50);
            color: var(--gray-800);
        }

        .tab-btn.active {
            background: var(--primary);
            color: #fff;
        }

        .tab-btn .tab-n {
            display: inline-grid;
            place-items: center;
            min-width: 19px;
            height: 19px;
            padding: 0 5px;
            border-radius: 999px;
            background: var(--gray-100);
            color: var(--gray-600);
            font-size: 11px;
            font-weight: 700;
        }

        /* Translucent white rather than a fixed colour, so the count reads on
           the dark pill without needing a second tint per tab. */
        .tab-btn.active .tab-n {
            background: rgba(255, 255, 255, .22);
            color: #fff;
        }

        .session-section.hidden {
            display: none !important;
        }

        /* ── Toolbar ── */
        .sr-toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .sr-search {
            position: relative;
            flex: 0 1 260px;
        }

        .sr-search svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 15px;
            height: 15px;
            color: var(--gray-400);
            pointer-events: none;
        }

        .sr-search input {
            width: 100%;
            font: inherit;
            font-size: 13px;
            padding: 9px 12px 9px 34px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            background: var(--surface);
            color: var(--gray-900);
        }

        .sr-search input:focus {
            outline: none;
            border-color: var(--mint);
        }

        .sr-chips {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .sr-chip {
            padding: 7px 13px;
            border-radius: 999px;
            border: 1px solid var(--gray-200);
            background: var(--surface);
            font: inherit;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--gray-600);
            cursor: pointer;
        }

        .sr-chip:hover {
            border-color: var(--mint);
        }

        .sr-chip.active {
            background: var(--mint-faint);
            border-color: var(--mint);
            color: var(--forest);
        }

        .sr-sort {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
            color: var(--gray-500);
        }

        .sr-sort select {
            font: inherit;
            font-size: 12.5px;
            padding: 8px 10px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            background: var(--surface);
            color: var(--gray-900);
        }

        /* ── Request card ── */
        .sr-list {
            max-width: 300px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .sr-card {
            display: flex;
            gap: 16px;
            padding: 18px 20px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .sr-card.is-picked {
            border-color: var(--mint);
            box-shadow: 0 0 0 3px var(--mint-faint);
        }

        .sr-main {
            flex: 1 1 320px;
            min-width: 0;
        }

        .sr-who {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .sr-name {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--gray-900);
            line-height: 1.3;
        }

        .sr-headline {
            font-size: 12.3px;
            color: var(--gray-500);
            margin-top: 2px;
        }

        .sr-msg {
            background: var(--gray-50);
            border-radius: var(--radius);
            padding: 11px 13px;
            font-size: 12.8px;
            color: var(--gray-700);
            line-height: 1.55;
            margin: 11px 0 0;
        }

        .sr-tagrow {
            display: flex;
            align-items: baseline;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 11px;
        }

        .sr-tagrow>span:first-child {
            font-size: 11.5px;
            color: var(--gray-400);
            font-weight: 600;
        }

        .sr-side {
            flex: 0 1 240px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .sr-fact {
            display: flex;
            gap: 9px;
            align-items: flex-start;
            font-size: 12.5px;
            color: var(--gray-600);
        }

        .sr-fact svg {
            width: 15px;
            height: 15px;
            color: var(--gray-400);
            flex-shrink: 0;
            margin-top: 1px;
        }

        .sr-fact b {
            display: block;
            font-size: 11.3px;
            font-weight: 700;
            color: var(--gray-400);
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 1px;
        }

        .sr-actions {
            display: flex;
            gap: 8px;
            margin-top: auto;
            padding-top: 4px;
        }

        .sr-actions .btn {
            flex: 1;
            justify-content: center;
        }

        /* A request whose start time has passed without an answer. */
        .sr-lapsed {
            margin: 0;
            padding: 8px 10px;
            border-radius: 8px;
            background: var(--warning-bg, #FEF3E2);
            color: var(--warning, #B87A10);
            font-size: 12px;
            line-height: 1.45;
        }

        .sr-count {
            font-size: 12.5px;
            color: var(--gray-500);
            margin: 14px 0 0;
        }

        /* ── Detail panel ── */
        .sr-panel {
            position: fixed;
            top: 0;
            right: 0;
            width: min(370px, 100%);
            height: 100%;
            background: var(--surface);
            border-left: 1px solid var(--border);
            box-shadow: -8px 0 28px rgba(2, 5, 71, .10);
            z-index: 120;
            display: flex;
            flex-direction: column;
            transform: translateX(100%);
            transition: transform .22s ease;
        }

        .sr-panel.open {
            transform: translateX(0);
        }

        .sr-panel-hd {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 20px 20px 16px;
            border-bottom: 1px solid var(--border);
        }

        .sr-panel-close {
            margin-left: auto;
            border: 0;
            background: transparent;
            color: var(--gray-400);
            cursor: pointer;
            padding: 2px;
            line-height: 0;
        }

        .sr-panel-close:hover {
            color: var(--gray-700);
        }

        .sr-panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 18px 20px;
        }

        .sr-panel-body h4 {
            margin: 0 0 8px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--gray-400);
        }

        .sr-panel-sec+.sr-panel-sec {
            margin-top: 20px;
        }

        .sr-panel-sec p {
            margin: 0;
            font-size: 13px;
            line-height: 1.6;
            color: var(--gray-700);
        }

        .sr-goal {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            font-size: 13px;
            color: var(--gray-700);
            padding: 4px 0;
        }

        .sr-goal svg {
            width: 15px;
            height: 15px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .sr-muted {
            font-size: 12.5px;
            color: var(--gray-400);
            font-style: italic;
        }

        .sr-panel-ft {
            padding: 14px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .sr-panel-ft .btn {
            justify-content: center;
        }

        .sr-scrim {
            position: fixed;
            inset: 0;
            background: rgba(2, 5, 71, .32);
            z-index: 119;
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s;
        }

        .sr-scrim.open {
            opacity: 1;
            pointer-events: auto;
        }

        /* ── Modals ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(2, 5, 71, .42);
            z-index: 200;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: var(--surface);
            border-radius: var(--radius-lg);
            padding: 26px;
            width: 100%;
            max-width: 400px;
            text-align: center;
            box-shadow: var(--shadow-lg, 0 20px 50px rgba(2, 5, 71, .2));
        }

        .modal-ico {
            width: 54px;
            height: 54px;
            margin: 0 auto 14px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--mint-faint);
            color: var(--forest);
        }

        .modal-ico svg {
            width: 26px;
            height: 26px;
        }

        .modal-box h2 {
            font-size: 18px;
            margin: 0 0 8px;
            color: var(--forest);
        }

        .modal-box p {
            font-size: 13px;
            color: var(--gray-500);
            line-height: 1.55;
            margin: 0 0 18px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
        }

        .modal-actions .btn {
            flex: 1;
            justify-content: center;
        }

        @media (max-width: 900px) {
            .sr-sort {
                margin-left: 0;
            }
        }

        /* Six tabs do not fit a phone in one row. A scrolling strip hides the
           last three behind a gesture with nothing on screen to suggest it, so
           below this width they wrap into pills instead — every tab visible,
           nothing to discover. */
        @media (max-width: 780px) {
            .tabs {
                flex-wrap: wrap;
                gap: 7px;
                overflow-x: visible;
                border-bottom: 0;
                padding-bottom: 18px;
                margin-bottom: 6px;
                border-bottom: 1px solid var(--border);
            }

            .tab-btn {
                padding: 8px 14px;
                font-size: 12.5px;
                border: 1px solid var(--gray-200);
                border-radius: 999px;
                border-bottom-color: var(--gray-200);
                margin-bottom: 0;
                background: var(--surface);
            }

            .tab-btn.active {
                background: var(--primary);
                border-color: var(--primary);
                color: #fff;
            }

            .tab-btn.active .tab-n {
                background: rgba(255, 255, 255, .22);
                color: #fff;
            }
        }

        @media (max-width: 620px) {
            .sr-card {
                padding: 16px;
            }

            .sr-side {
                flex: 1 1 100%;
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main fade-in">

            <div class="sx-hd">
                <span class="sx-hd-ico">
                    <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 19c0-2.1-1.7-3.8-3.8-3.8h-.4C9.2 15.2 7.5 16.9 7.5 19" />
                        <circle cx="11.5" cy="9" r="3.2" />
                        <path stroke-linecap="round" d="M17.5 12.2a2.6 2.6 0 0 0 0-5M20.5 18c0-1.6-1-3-2.5-3.5" />
                    </svg>
                </span>
                <div class="sx-hd-text page-hd" style="margin:0;">
                    <h1>Mentorship Requests</h1>
                    <p>Review people who are interested in learning from you, and manage your sessions.</p>
                </div>
            </div>

            <?php
            /*
             * Stats: plain counts, no invented trends.
             *
             * The arrow on a card switches to the tab that card counts, using
             * the same filterSessions() the tabs themselves call. Total
             * Mentees has no arrow: there is no mentees tab on this page, and
             * a button that went nowhere would be worse than none.
             */
            $sx_cards = [
                ['sx-teal',   $stat_pending,   'Pending Requests',
                 $stat_pending ? 'Waiting on your reply' : 'Nothing to review', 'request',
                 '<path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM19 8v6M22 11h-6"/>'],
                ['sx-blue',   $stat_upcoming,  'Upcoming Sessions', 'Approved and still ahead', 'upcoming',
                 '<rect x="5" y="4" width="14" height="16" rx="3"/><path stroke-linecap="round" d="M8 2v4M16 2v4M5 9h14"/>'],
                ['sx-purple', $stat_completed, 'Completed', 'Sessions you have finished', 'complete',
                 '<circle cx="12" cy="12" r="8.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.3 2.4 2.4 4.6-4.9"/>'],
                ['sx-amber',  $stat_mentees,   'Total Mentees', 'People you have worked with', null,
                 '<path stroke-linecap="round" stroke-linejoin="round" d="M15.5 19c0-2.1-1.7-3.8-3.8-3.8h-.4C9.2 15.2 7.5 16.9 7.5 19M11.5 12.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4ZM17.5 12.2a2.6 2.6 0 0 0 0-5M20.5 18c0-1.6-1-3-2.5-3.5"/>'],
            ];
            ?>
            <div class="sx-stats">
                <?php foreach ($sx_cards as [$tint, $value, $label, $sub, $tab, $path]): ?>
                    <div class="sx-stat <?= $tint ?>">
                        <?php if ($tab !== null): ?>
                            <button type="button" class="sx-stat-go" onclick="filterSessions('<?= $tab ?>')"
                                    aria-label="Show <?= htmlspecialchars($label) ?>">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6" />
                                </svg>
                            </button>
                        <?php endif; ?>
                        <div class="sx-stat-ico">
                            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24" aria-hidden="true"><?= $path ?></svg>
                        </div>
                        <div class="sx-stat-v"><?= (int)$value ?></div>
                        <div class="sx-stat-k"><?= htmlspecialchars($label) ?></div>
                        <div class="sx-stat-s"><?= htmlspecialchars($sub) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php
            /*
             * Four tabs, one per stage a session passes through, and History
             * beside them as a button rather than a tab — the mentee's
             * Sessions page works the same way.
             *
             * Declined is gone: a declined request is not a stage, it is an
             * outcome, and it lives in History with the cancelled and missed
             * ones. Group is gone too: a group session is a session, so it
             * appears in Upcoming and Ongoing like any other, as one card for
             * the slot rather than one per booking.
             */
            ?>
            <div class="tabs" role="tablist">
                <button type="button" onclick="filterSessions('request')" id="tab-request" class="tab-btn active" role="tab">
                    <?= mp_svg('chat', 'width="15" height="15"') ?>
                    Received
                    <?php if ($tabCounts['received'] > 0): ?><span class="tab-n"><?= $tabCounts['received'] ?></span><?php endif; ?>
                </button>
                <button type="button" onclick="filterSessions('upcoming')" id="tab-upcoming" class="tab-btn" role="tab">
                    <?= mp_svg('calendar', 'width="15" height="15"') ?>
                    Upcoming
                    <?php if ($tabCounts['upcoming'] > 0): ?><span class="tab-n"><?= $tabCounts['upcoming'] ?></span><?php endif; ?>
                </button>
                <button type="button" onclick="filterSessions('ongoing')" id="tab-ongoing" class="tab-btn" role="tab">
                    <?= mp_svg('video', 'width="15" height="15"') ?>
                    Ongoing
                    <?php if ($tabCounts['ongoing'] > 0): ?><span class="tab-n"><?= $tabCounts['ongoing'] ?></span><?php endif; ?>
                </button>
                <button type="button" onclick="filterSessions('complete')" id="tab-complete" class="tab-btn" role="tab">
                    <?= mp_svg('check', 'width="15" height="15"') ?>
                    Completed
                    <?php if ($tabCounts['completed'] > 0): ?><span class="tab-n"><?= $tabCounts['completed'] ?></span><?php endif; ?>
                </button>

                <button type="button" id="sr-history-open" class="tab-btn sr-history-btn" style="margin-left:auto;">
                    <?= mp_svg('clock', 'width="15" height="15"') ?>
                    History
                    <?php if ($tabCounts['history'] > 0): ?><span class="tab-n"><?= $tabCounts['history'] ?></span><?php endif; ?>
                </button>
            </div>

            <!-- ── Received ── -->
            <div id="section-request" class="session-section">
                <?php if ($pending_rows): ?>
                    <div class="sr-toolbar">
                        <label class="sr-search">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="11" cy="11" r="7" />
                                <path stroke-linecap="round" d="m20 20-3.6-3.6" />
                            </svg>
                            <input type="search" id="srSearch" placeholder="Search requests…" aria-label="Search requests">
                        </label>

                        <?php if (count($subjects) > 1): ?>
                            <div class="sr-chips" id="srChips">
                                <button type="button" class="sr-chip active" data-subject="">All</button>
                                <?php foreach ($subjects as $s): ?>
                                    <button type="button" class="sr-chip" data-subject="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <label class="sr-sort">
                            Sort by:
                            <select id="srSort">
                                <option value="newest">Newest</option>
                                <option value="oldest">Oldest</option>
                                <option value="name">Name</option>
                            </select>
                        </label>
                    </div>

                    <div class="sr-list" id="srList">
                        <?php foreach ($pending_rows as $r):
                            $d = $details[(int)$r['request_id']];
                            $chips = array_merge($d['learn'], $d['skill']);
                        ?>
                            <article class="card sr-card"
                                data-id="<?= (int)$r['request_id'] ?>"
                                data-subject="<?= htmlspecialchars($r['subject']) ?>"
                                data-name="<?= htmlspecialchars($d['name']) ?>"
                                data-when="<?= (int)strtotime($r['session_date']) ?>"
                                data-search="<?= htmlspecialchars(strtolower($d['name'] . ' ' . $r['subject'] . ' ' . $r['message'] . ' ' . implode(' ', $chips))) ?>">

                                <div class="sr-main">
                                    <div class="sr-who">
                                        <span class="pc-avatar pc-avatar-lg">
                                            <?php if ($d['image']): ?>
                                                <img src="<?= htmlspecialchars($d['image']) ?>" alt="">
                                                <?php else: ?><?= htmlspecialchars($d['initial']) ?><?php endif; ?>
                                        </span>
                                        <div style="min-width:0;">
                                            <div class="sr-name"><?= htmlspecialchars($d['name']) ?></div>
                                            <?php if ($d['headline'] !== ''): ?>
                                                <div class="sr-headline"><?= htmlspecialchars($d['headline']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if (trim($d['message']) !== ''): ?>
                                        <p class="sr-msg"><?= htmlspecialchars($d['message']) ?></p>
                                    <?php endif; ?>

                                    <?php if ($chips): ?>
                                        <div class="sr-tagrow">
                                            <span>Interested in:</span>
                                            <?php foreach (array_slice($chips, 0, 4) as $c): ?>
                                                <span class="tag-pill"><?= htmlspecialchars($c) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="sr-side">
                                    <div class="sr-fact">
                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <rect x="4" y="5" width="16" height="15" rx="2.5" />
                                            <path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16" />
                                        </svg>
                                        <span>
                                            <?php // session_requests has no created_at, so this is the date of
                                            //     the session itself — labelled as such, not as "requested on". 
                                            ?>
                                            <b>Session on</b>
                                            <?= htmlspecialchars(date('M j, Y · g:i a', strtotime($r['session_date']))) ?>
                                        </span>
                                    </div>
                                    <div class="sr-fact">
                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.5C10.4 5.2 8.4 4.5 6 4.5H4v13h2c2.4 0 4.4.7 6 2 1.6-1.3 3.6-2 6-2h2v-13h-2c-2.4 0-4.4.7-6 2Z" />
                                        </svg>
                                        <span>
                                            <b>Subject</b>
                                            <?= htmlspecialchars($r['subject'] ?: 'Not given') ?>
                                        </span>
                                    </div>

                                    <div class="sr-actions">
                                        <button type="button" class="btn btn-outline btn-sm" onclick="openRejectModal(<?= (int)$r['request_id'] ?>)">Decline</button>
                                        <?php if (strtotime($r['session_date']) > time()): ?>
                                            <button type="button" class="btn btn-primary btn-sm" onclick="confirmAccept(<?= (int)$r['request_id'] ?>)">Accept</button>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (strtotime($r['session_date']) <= time()): ?>
                                        <p class="sr-lapsed">Its time has passed, so it can't be accepted. It will be removed automatically and the mentee told.</p>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-ghost btn-sm" style="justify-content:center;" onclick="openPanel(<?= (int)$r['request_id'] ?>)">View details</button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <p class="sr-count" id="srCount"></p>
                <?php else: ?>
                    <div class="card empty-state-lg">
                        <span class="es-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m3.8 7 8.2 6 8.2-6" />
                            </svg>
                        </span>
                        <h3 class="es-title">No pending mentorship requests</h3>
                        <p class="es-body">
                            New requests from mentees will appear here. Opening more availability
                            on your calendar makes you easier to book.
                        </p>
                        <a class="btn btn-primary" href="<?= htmlspecialchars(url('mentor-calendar')) ?>">Manage availability</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php // Tells the four tab bodies they are embedded here. On their
            //     own routes they redirect back to this page instead of
            //     serving a bare, unstyled fragment. ?>
            <?php
            $sr_embedded = true;
            /*
             * Filled by the Upcoming and Ongoing tabs as they render, and
             * emitted as JSON further down for the detail modal. They are
             * included above the script block, so anything they add here is
             * in place by the time it runs.
             */
            $sr_session_details = [];
            ?>
            <div id="section-upcoming" class="session-section hidden"><?php include "upcoming_session.php"; ?></div>
            <div id="section-ongoing" class="session-section hidden"><?php include "ongoing_session.php"; ?></div>
            <div id="section-complete" class="session-section hidden"><?php include "completed_session.php"; ?></div>
        </main>
    </div>

    <?php
    /*
     * History, behind a button rather than in a tab — the same shape the
     * mentee's Sessions page uses. It holds the outcomes: declined,
     * cancelled, missed, and sessions left unfinished once their time has
     * passed. Nothing in here can still be run.
     */
    ?>
    <?php
    /*
     * Session details, opened by clicking View details on an Upcoming or
     * Ongoing card. A one-to-one shows the mentee and their note; a group
     * shows every participant with the control to remove one, which is where
     * that moved to when the Group tab was folded away.
     *
     * Separate from the Received tab's panel on purpose: that one is built
     * around a mentee's bio, interests and goals, which a group session has
     * no single version of.
     */
    ?>
    <div class="modal-overlay" id="sesDetailModal" role="dialog" aria-modal="true" aria-labelledby="sesDetailTitle">
        <div class="modal-box" style="max-width:560px;width:95vw;">
            <div class="modal-hd">
                <h2 class="modal-hd-title" id="sesDetailTitle">Session details</h2>
                <button type="button" class="modal-close" id="ses-detail-close" aria-label="Close">&times;</button>
            </div>
            <div style="padding:18px 20px;max-height:74vh;overflow-y:auto;">
                <div id="sdKind" style="font-size:11.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--gray-400);"></div>
                <div id="sdSubject" style="font-size:18px;font-weight:800;color:var(--gray-900);margin:4px 0 14px;"></div>

                <div class="ss-kv" style="display:grid;gap:10px;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;gap:14px;font-size:13px;">
                        <span style="color:var(--gray-500);">When</span><span id="sdWhen" style="font-weight:600;text-align:right;"></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;gap:14px;font-size:13px;">
                        <span style="color:var(--gray-500);">Status</span><span id="sdStatus" style="font-weight:600;text-align:right;"></span>
                    </div>
                    <div id="sdSeatsRow" style="display:none;justify-content:space-between;gap:14px;font-size:13px;">
                        <span style="color:var(--gray-500);">Seats</span><span id="sdSeats" style="font-weight:600;text-align:right;"></span>
                    </div>
                </div>

                <div id="sdSoloWrap" style="display:none;">
                    <div style="font-size:11.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--gray-400);margin-bottom:8px;">Mentee</div>
                    <div id="sdMentee" style="font-size:14px;font-weight:700;color:var(--gray-900);"></div>
                    <div id="sdMenteeMail" style="font-size:12.5px;color:var(--gray-500);margin-top:2px;"></div>
                    <div id="sdMenteeCourse" style="font-size:12.5px;color:var(--gray-500);margin-top:2px;"></div>
                    <div id="sdNoteWrap" style="margin-top:14px;display:none;">
                        <div style="font-size:11.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--gray-400);margin-bottom:6px;">Mentee's note</div>
                        <p id="sdNote" style="font-size:13px;color:var(--gray-700);line-height:1.6;margin:0;"></p>
                    </div>
                </div>

                <div id="sdGroupWrap" style="display:none;">
                    <div style="font-size:11.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--gray-400);margin-bottom:8px;">Participants</div>
                    <div id="sdParticipants"></div>
                    <p id="sdNobody" style="font-size:13px;color:var(--gray-400);margin:0;display:none;">Nobody has booked this slot yet.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="srHistoryModal" role="dialog" aria-modal="true" aria-labelledby="srHistoryTitle">
        <div class="modal-box" style="max-width:940px;width:95vw;">
            <div class="modal-hd">
                <h2 class="modal-hd-title" id="srHistoryTitle">Session History</h2>
                <button type="button" class="modal-close" id="sr-history-close" aria-label="Close">&times;</button>
            </div>
            <div style="padding:18px 20px;max-height:74vh;overflow-y:auto;">
                <?php include "session_history.php"; ?>
            </div>
        </div>
    </div>

    <!-- Detail panel -->
    <div class="sr-scrim" id="srScrim" onclick="closePanel()"></div>
    <aside class="sr-panel" id="srPanel" aria-hidden="true" aria-label="Request details">
        <div class="sr-panel-hd">
            <span class="pc-avatar pc-avatar-lg" id="pnAvatar"></span>
            <div style="min-width:0;">
                <div class="sr-name" id="pnName"></div>
                <span class="badge badge-pending" style="margin-top:5px;">Mentee applicant</span>
            </div>
            <button type="button" class="sr-panel-close" onclick="closePanel()" aria-label="Close">
                <svg width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" />
                </svg>
            </button>
        </div>

        <div class="sr-panel-body">
            <div class="sr-panel-sec">
                <h4>About</h4>
                <p id="pnBio"></p>
            </div>
            <div class="sr-panel-sec">
                <h4>What they asked for</h4>
                <p id="pnMsg"></p>
            </div>
            <div class="sr-panel-sec">
                <h4>Session</h4>
                <p id="pnWhen"></p>
            </div>
            <div class="sr-panel-sec">
                <h4>Goals with you</h4>
                <div id="pnGoals"></div>
            </div>
            <div class="sr-panel-sec">
                <h4>Wants to learn</h4>
                <div class="sr-chips" id="pnLearn"></div>
            </div>
            <div class="sr-panel-sec">
                <h4>Skills to build</h4>
                <div class="sr-chips" id="pnSkill"></div>
            </div>
        </div>

        <div class="sr-panel-ft">
            <button type="button" class="btn btn-primary" id="pnAccept">Accept Request</button>
            <button type="button" class="btn btn-outline" id="pnDecline">Decline Request</button>
        </div>
    </aside>

    <!-- Confirm accept -->
    <div id="confirmModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-ico">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="9" />
                    <path stroke-linecap="round" d="M12 16.5v.3M12 13.2V13a2.2 2.2 0 1 0-2.2-2.2" />
                </svg>
            </div>
            <h2>Accept this request?</h2>
            <p id="confirmText"></p>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeConfirm()">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmGo">Accept Request</button>
            </div>
        </div>
    </div>

    <!-- Accepted -->
    <div id="approveModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-ico" style="background:var(--success-bg);color:var(--success);">
                <svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="9" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.3 2.4 2.4 4.6-4.9" />
                </svg>
            </div>
            <h2>Request accepted</h2>
            <p>The mentee has been notified and the session is now in Upcoming.</p>
            <button type="button" class="btn btn-primary" style="width:100%;justify-content:center;" onclick="location.reload()">Done</button>
        </div>
    </div>

    <!-- Decline, with the reason the mentee will see -->
    <div id="rejectModal" class="modal-overlay">
        <div class="modal-box" style="text-align:left;">
            <h2>Decline request</h2>
            <p>Give a short reason so the mentee knows why. They will see this.</p>
            <textarea id="rejectReason" placeholder="e.g. Schedule conflict, topic outside my subjects…" class="form-input" rows="4" style="resize:none;margin-bottom:16px;width:100%;"></textarea>
            <p id="rejectErr" class="sr-muted" style="color:var(--danger);display:none;margin:-10px 0 12px;">Please give a reason.</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeRejectModal()">Cancel</button>
                <button type="button" class="btn btn-primary" style="background:var(--danger);border-color:var(--danger);" onclick="submitReject()">Decline</button>
            </div>
        </div>
    </div>

    <script>
        const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
        const SR_DETAILS = <?= json_encode($details, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const SELF_URL = <?= json_encode($self_url) ?>;

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        }

        /* ── Tabs ── */
        function filterSessions(type) {
            document.querySelectorAll('.session-section').forEach(s => s.classList.add('hidden'));
            const sec = document.getElementById('section-' + type);
            if (sec) sec.classList.remove('hidden');
            document.querySelectorAll('.tab-btn').forEach(t => t.classList.remove('active'));
            const tab = document.getElementById('tab-' + type);
            if (tab) tab.classList.add('active');
            closePanel();
        }

        /* ── Search / filter / sort over the rendered cards ── */
        (function() {
            const list = document.getElementById('srList');
            if (!list) return;
            const search = document.getElementById('srSearch');
            const sort = document.getElementById('srSort');
            const chips = document.getElementById('srChips');
            const count = document.getElementById('srCount');
            const cards = Array.from(list.children);
            let subject = '';

            function apply() {
                const q = (search.value || '').trim().toLowerCase();
                let shown = 0;
                cards.forEach(c => {
                    const okQ = q === '' || c.dataset.search.includes(q);
                    const okS = subject === '' || c.dataset.subject === subject;
                    const show = okQ && okS;
                    c.hidden = !show;
                    if (show) shown++;
                });

                const dir = sort.value;
                const ordered = cards.slice().sort((a, b) => {
                    if (dir === 'name') return a.dataset.name.localeCompare(b.dataset.name);
                    const d = (+a.dataset.when) - (+b.dataset.when);
                    return dir === 'oldest' ? d : -d;
                });
                ordered.forEach(c => list.appendChild(c));

                count.textContent = shown === cards.length ?
                    `Showing all ${cards.length} request${cards.length === 1 ? '' : 's'}` :
                    `Showing ${shown} of ${cards.length} requests`;
            }

            search.addEventListener('input', apply);
            sort.addEventListener('change', apply);
            if (chips) {
                chips.addEventListener('click', e => {
                    const b = e.target.closest('.sr-chip');
                    if (!b) return;
                    chips.querySelectorAll('.sr-chip').forEach(x => x.classList.remove('active'));
                    b.classList.add('active');
                    subject = b.dataset.subject;
                    apply();
                });
            }
            apply();
        })();

        /* ── Detail panel ── */
        let panelId = null;

        function chipHtml(list) {
            if (!list || !list.length) return '<span class="sr-muted">Nothing listed yet.</span>';
            return list.map(t => '<span class="tag-pill"></span>')
                .map((h, i) => {
                    const s = document.createElement('span');
                    s.className = 'tag-pill';
                    s.textContent = list[i];
                    return s.outerHTML;
                }).join('');
        }

        function openPanel(id) {
            const d = SR_DETAILS[id];
            if (!d) return;
            panelId = id;

            const av = document.getElementById('pnAvatar');
            av.textContent = '';
            if (d.image) {
                const img = document.createElement('img');
                img.src = d.image;
                img.alt = '';
                av.appendChild(img);
            } else {
                av.textContent = d.initial;
            }

            document.getElementById('pnName').textContent = d.name;
            document.getElementById('pnBio').textContent = d.bio || 'This mentee has not written an About yet.';
            document.getElementById('pnMsg').textContent = d.message || 'No message was sent with the request.';
            document.getElementById('pnWhen').textContent = (d.subject || 'Session') + ' — ' + d.when;

            const g = document.getElementById('pnGoals');
            g.textContent = '';
            if (!d.goals.length) {
                const s = document.createElement('span');
                s.className = 'sr-muted';
                s.textContent = 'No goals set with you yet.';
                g.appendChild(s);
            } else {
                d.goals.forEach(x => {
                    const row = document.createElement('div');
                    row.className = 'sr-goal';
                    row.innerHTML = '<svg fill="none" stroke="' +
                        (x.status === 'completed' ? 'var(--success)' : 'var(--gray-400)') +
                        '" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.3 2.4 2.4 4.6-4.9"/></svg>';
                    const t = document.createElement('span');
                    t.textContent = x.title;
                    row.appendChild(t);
                    g.appendChild(row);
                });
            }

            document.getElementById('pnLearn').innerHTML = chipHtml(d.learn);
            document.getElementById('pnSkill').innerHTML = chipHtml(d.skill);

            document.getElementById('pnAccept').onclick = () => confirmAccept(id);
            document.getElementById('pnAccept').style.display = d.past ? 'none' : '';
            document.getElementById('pnDecline').onclick = () => {
                closePanel();
                openRejectModal(id);
            };

            document.querySelectorAll('.sr-card').forEach(c => c.classList.toggle('is-picked', +c.dataset.id === id));
            document.getElementById('srPanel').classList.add('open');
            document.getElementById('srPanel').setAttribute('aria-hidden', 'false');
            document.getElementById('srScrim').classList.add('open');
        }

        function closePanel() {
            panelId = null;
            const p = document.getElementById('srPanel');
            if (!p) return;
            p.classList.remove('open');
            p.setAttribute('aria-hidden', 'true');
            document.getElementById('srScrim').classList.remove('open');
            document.querySelectorAll('.sr-card').forEach(c => c.classList.remove('is-picked'));
        }

        /* ── Accept, behind a confirm step ── */
        function confirmAccept(id) {
            const d = SR_DETAILS[id];
            if (!d) return;
            if (d.ts * 1000 <= Date.now()) {
                location.reload();
                return;
            }
            document.getElementById('confirmText').textContent =
                'You are about to accept ' + d.name + ' for ' + (d.subject || 'a session') + ' on ' + d.when + '.';
            document.getElementById('confirmGo').onclick = () => doAccept(id);
            document.getElementById('confirmModal').classList.add('open');
        }

        function closeConfirm() {
            document.getElementById('confirmModal').classList.remove('open');
        }

        function doAccept(id) {
            closeConfirm();
            closePanel();
            fetch(SELF_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'action=approve&id=' + encodeURIComponent(id) +
                        '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
                })
                .then(r => {
                    if (!r.ok) throw new Error(r.status);
                    document.getElementById('approveModal').classList.add('open');
                    setTimeout(() => location.reload(), 1400);
                })
                .catch(() => location.reload());
        }

        /* ── Decline, reason required ── */
        let rejectId = null;

        function openRejectModal(id) {
            rejectId = id;
            document.getElementById('rejectReason').value = '';
            document.getElementById('rejectErr').style.display = 'none';
            document.getElementById('rejectModal').classList.add('open');
            document.getElementById('rejectReason').focus();
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('open');
        }

        function submitReject() {
            const reason = document.getElementById('rejectReason').value.trim();
            if (reason === '') {
                // The server drops a reasonless decline, so stop it here and say why.
                document.getElementById('rejectErr').style.display = 'block';
                return;
            }
            const f = document.createElement('form');
            f.method = 'post';
            f.action = SELF_URL;
            f.innerHTML = '<input type="hidden" name="action" value="reject">' +
                '<input type="hidden" name="id">' +
                '<input type="hidden" name="reason">' +
                '<input type="hidden" name="csrf_token">';
            f.elements.id.value = rejectId;
            f.elements.reason.value = reason;
            f.elements.csrf_token.value = CSRF_TOKEN;
            document.body.appendChild(f);
            f.submit();
        }

        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            closeConfirm();
            closeRejectModal();
            closePanel();
        });

        /* ── Session details, for an Upcoming or Ongoing card ── */
        const SES_DETAILS = <?= json_encode($sr_session_details, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const SES_POST_URL = <?= json_encode(url('mentor-requests')) ?>;

        function openSessionDetail(key) {
            const d = SES_DETAILS[key];
            if (!d) return;

            document.getElementById('sdKind').textContent = d.is_group ? 'Group session' : 'One-to-one session';
            document.getElementById('sdSubject').textContent = d.subject || 'Session';
            document.getElementById('sdWhen').textContent = d.when;
            document.getElementById('sdStatus').textContent = d.status_label;

            const seatsRow = document.getElementById('sdSeatsRow');
            seatsRow.style.display = d.is_group ? 'flex' : 'none';
            if (d.is_group) document.getElementById('sdSeats').textContent = d.seats;

            document.getElementById('sdSoloWrap').style.display  = d.is_group ? 'none' : 'block';
            document.getElementById('sdGroupWrap').style.display = d.is_group ? 'block' : 'none';

            if (!d.is_group) {
                document.getElementById('sdMentee').textContent = d.mentee;
                document.getElementById('sdMenteeMail').textContent = d.email || '';
                document.getElementById('sdMenteeCourse').textContent = d.course || '';
                const noteWrap = document.getElementById('sdNoteWrap');
                noteWrap.style.display = d.note ? 'block' : 'none';
                if (d.note) document.getElementById('sdNote').textContent = d.note;
            } else {
                const list = document.getElementById('sdParticipants');
                list.replaceChildren();
                document.getElementById('sdNobody').style.display = d.participants.length ? 'none' : 'block';

                d.participants.forEach(function (p) {
                    const row = document.createElement('div');
                    row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid var(--gray-100);';

                    const who = document.createElement('div');
                    who.style.cssText = 'min-width:0;';
                    const nm = document.createElement('div');
                    nm.style.cssText = 'font-size:13.5px;font-weight:600;color:var(--gray-900);';
                    nm.textContent = p.name;
                    who.appendChild(nm);
                    if (p.email) {
                        const em = document.createElement('div');
                        em.style.cssText = 'font-size:12px;color:var(--gray-500);';
                        em.textContent = p.email;
                        who.appendChild(em);
                    }
                    row.appendChild(who);

                    /* Removing cancels that mentee's booking. Offered only
                       while the booking is still live — the server refuses it
                       on a closed session anyway, and a button that is always
                       refused is worse than none. */
                    if (p.removable) {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'btn btn-ghost btn-sm';
                        btn.style.color = 'var(--danger)';
                        btn.textContent = 'Remove';
                        btn.addEventListener('click', function () { removeParticipant(p.id, p.name); });
                        row.appendChild(btn);
                    }
                    list.appendChild(row);
                });
            }

            document.getElementById('sesDetailModal').classList.add('open');
        }

        function removeParticipant(requestId, name) {
            // pcConfirm takes { title, body, tone, ok, cancel } and resolves
            // to a boolean — the same dialog the rest of the page uses.
            pcConfirm({
                title: 'Remove ' + name + '?',
                body: 'Their booking is cancelled and they are told. This cannot be undone.',
                tone: 'danger',
                ok: 'Remove',
                cancel: 'Keep them'
            }).then(function (yes) {
                if (!yes) return;
                const f = document.createElement('form');
                f.method = 'POST';
                f.action = SES_POST_URL;
                f.style.display = 'none';
                [['csrf_token', CSRF_TOKEN], ['remove_request_id', String(requestId)]].forEach(function (pair) {
                    const i = document.createElement('input');
                    i.type = 'hidden';
                    i.name = pair[0];
                    i.value = pair[1];
                    f.appendChild(i);
                });
                document.body.appendChild(f);
                f.submit();
            });
        }

        (function () {
            const m = document.getElementById('sesDetailModal');
            const c = document.getElementById('ses-detail-close');
            if (!m) return;
            if (c) c.addEventListener('click', function () { m.classList.remove('open'); });
            m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('open'); });
        })();

        /* ── History, behind a button rather than a tab ── */
        (function () {
            var modal = document.getElementById('srHistoryModal');
            var open  = document.getElementById('sr-history-open');
            var close = document.getElementById('sr-history-close');
            if (!modal || !open) return;

            open.addEventListener('click', function () { modal.classList.add('open'); });
            if (close) close.addEventListener('click', function () { modal.classList.remove('open'); });
            // Clicking the backdrop closes it; clicking the panel does not.
            modal.addEventListener('click', function (e) {
                if (e.target === modal) modal.classList.remove('open');
            });
        })();

        /* Deep links. Notifications, the video-call end screen and the
           group "remove student" flow all send the mentor back here as
           ?tab=upcoming / ?tab=ongoing / ..., and the standalone tab routes
           redirect here the same way. Nothing read the parameter, so every
           one of those links dropped the mentor on Requests instead.

           'history' and 'group' are no longer sections: History is a modal
           and group sessions live in Upcoming and Ongoing. Old links to
           either still have somewhere sensible to land rather than being
           silently ignored. */
        (function () {
            var qs   = new URLSearchParams(location.search);
            var want = qs.get('tab');

            /* History is a modal, so its own links — the filter chips and
               the pager inside it — carry hstatus/hpage rather than a tab.
               Without this the mentor picked "Missed", the page reloaded
               filtered correctly, and the modal was shut. */
            if (qs.has('hstatus') || qs.has('hpage')) {
                var hm = document.getElementById('srHistoryModal');
                if (hm) hm.classList.add('open');
                if (!want) return;
            }

            if (!want) return;

            // Declined sessions are in History now, so an old ?tab=declined
            // opens it there rather than landing on a tab that no longer
            // exists. Group sessions are in Upcoming.
            if (want === 'history' || want === 'declined') {
                var m = document.getElementById('srHistoryModal');
                if (m) m.classList.add('open');
                return;
            }
            if (want === 'group') { want = 'upcoming'; }
            if (document.getElementById('section-' + want)) filterSessions(want);
        })();
    </script>
</body>

</html>