<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../../db.php";
/*
 * Two different questions, which used to share one answer:
 *   $is_logged_in — may this viewer book/report? Mentees only.
 *   $has_shell    — does this viewer get the app chrome? Anyone signed in.
 * Collapsing them meant a signed-in mentor previewing their own public
 * profile fell through to the signed-out header: no sidebar, no topbar
 * actions, and nothing to navigate back with.
 */
$is_logged_in = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'mentee';
$has_shell    = !empty($_SESSION['user_id']);
$mentor_id = (int) ($_GET['id'] ?? 0);
// The mentor's own profile page links here as "View Public Profile", so this
// page has to cope with someone looking at themselves: messaging and
// reporting yourself are not actions, they are bugs. Computed after
// $mentor_id, which it reads.
$is_self      = $has_shell && (int)$_SESSION['user_id'] === $mentor_id;

// pr.bio is the same column the mentor edits under Settings > Account and
// sees on their own profile page; without it this page always claimed the
// mentor had written nothing.
$mentor = UserRepository::mentorProfile($con, $mentor_id);

// Completed sessions, and how many different mentees they were with. There is
// no minutes figure: a session's length lives on its slot, and the slot is
// deleted once the session is completed, so a total could only be guessed.
$stats = SessionRepository::completedSummaryForMentor($con, $mentor_id);

$reviews = FeedbackRepository::reviewsForMentor($con, $mentor_id);

$avg = FeedbackRepository::averagesForMentor($con, $mentor_id);

/*
 * Expertise. `user_tags` (the questionnaire answers) is the live source — it
 * is what MentorScoreService matches on and what the mentor sees on their own
 * profile, so the public page has to read the same thing or the two disagree.
 * The legacy free-text `user_verifications.expertise` is kept as a fallback so
 * an older account that only has that still shows something.
 */
$skills = UserRepository::skillTags($con, $mentor_id);
if (!$skills) {
    $skills = array_values(array_filter(array_map('trim', explode(',', $mentor['expertise'] ?? ''))));
}

$mentor_bio = trim((string)($mentor['bio'] ?? ''));

$sessions_by_type = ['1v1' => [], 'group' => []];
foreach (AvailabilityRepository::upcomingForProfile($con, $mentor_id) as $row) {
    $sessions_by_type[$row['session_type'] === 'group' ? 'group' : '1v1'][] = $row;
}

// A mentor Find a Mentor would not list (blocked, restricted, unverified)
// cannot be booked, so their slots are not offered here either. The page says
// they aren't taking bookings and does not say why.
$is_bookable = UserRepository::isBookableMentor($con, $mentor_id);
$not_bookable_text = "This mentor isn't taking bookings right now.";
if (!$is_bookable) {
    $sessions_by_type = ['1v1' => [], 'group' => []];
}

$find_mentor_url = url('mentee-find');
$messages_url = url('messages');
$route_availability_url = url('get-availability');
$route_times_url = url('get-times');
$route_group_sessions_url = url('get-group-sessions');
$route_save_booking_url = url('save-booking');
$route_save_report_url = url('save-report');

// Fetch mentor's earned badges
$mentor_earned_badges = AchievementRepository::activeBadgesFor($con, $mentor_id);

// Fetch mentor's certificates
$mentor_certs = AchievementRepository::certificatesFor($con, $mentor_id);

// ── Derived, all from real rows above ─────────────────────────────────────
$mentor_name  = trim(($mentor['firstname'] ?? '') . ' ' . ($mentor['lastname'] ?? ''));
$mentor_first = $mentor['firstname'] ?? 'this mentor';
$accomplishments_count = count($mentor_earned_badges) + count($mentor_certs);
$open_slot_count = count($sessions_by_type['1v1']) + count($sessions_by_type['group']);
// "Available for sessions" is the mentor's real open-slot state, not a
// presence/online indicator — nothing in this app tracks who is online.
$is_available = $open_slot_count > 0;

// Anything real we can show under "Mentoring Information". Left empty when
// the mentor never filled their verification details in.
$mentoring_info = array_filter([
    'Course'     => $mentor['course'] ?? '',
    'Year level' => $mentor['year_level'] ?? '',
    'Club'       => $mentor['club'] ?? '',
    'Member since' => !empty($mentor['created_at']) ? date('M Y', strtotime($mentor['created_at'])) : '',
], fn($v) => trim((string)$v) !== '');

$active_page = 'find_mentor';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Mentor – NEUST</title>
    <?php include __DIR__ . '/../includes/style.php'; ?>
    <style>
        /* ── Utility ── */
        .hidden {
            display: none !important;
        }

        /* ── Tailwind compat (used by existing JS) ── */
        .bg-blue-500 {
            background: var(--accent) !important;
        }

        .bg-blue-600 {
            background: #FF5A5F !important;
        }

        .bg-blue-800 {
            background: #1e40af !important;
        }

        .text-white {
            color: #fff !important;
        }

        .bg-gray-100 {
            background: var(--gray-100) !important;
        }

        .text-gray-400 {
            color: var(--gray-400) !important;
        }

        .text-gray-600 {
            color: var(--gray-600) !important;
        }

        .bg-gray-50 {
            background: var(--gray-50) !important;
        }

        .border-gray-100 {
            border-color: var(--gray-100) !important;
        }

        .border {
            border-style: solid;
            border-width: 1px;
        }

        /* ── Modal overlay ── */
        .m-overlay {
            position: fixed;
            inset: 0;
            background: rgba(31, 78, 69, .45);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 200;
        }

        .m-box {
            background: var(--surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            position: relative;
            animation: fadeSlideUp .2s ease;
        }

        /* ── Hero card ── */
        .hero-card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-xs);
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 22px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .hero-avatar {
            width: 84px;
            height: 84px;
            border-radius: 18px;
            overflow: hidden;
            background: linear-gradient(135deg, var(--info-bg), var(--gray-100));
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .hero-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .hero-avatar-fallback {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--accent);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 700;
        }

        /* ── Tabs — design system aligned ── */
        .top-tab {
            padding: 8px 18px;
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            font-weight: 500;
            cursor: pointer;
            transition: background .14s, color .14s, border-color .14s;
            border: 1px solid transparent;
            font-family: inherit;
        }

        .top-tab.t-active {
            background: var(--forest);
            border-color: var(--forest);
            color: #fff;
        }

        .top-tab.t-inactive {
            background: var(--surface);
            border-color: var(--border);
            color: var(--gray-600);
        }

        .top-tab.t-inactive:hover {
            background: var(--mint-faint);
            border-color: var(--mint-soft);
            color: var(--forest);
        }

        .session-tab {
            padding: 6px 14px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: background .14s, color .14s, border-color .14s;
            border: 1px solid transparent;
            font-family: inherit;
        }

        .session-tab.st-active {
            background: var(--forest);
            border-color: var(--forest);
            color: #fff;
        }

        .session-tab.st-inactive {
            background: var(--gray-50);
            border-color: var(--border);
            color: var(--gray-600);
        }

        .session-tab.st-inactive:hover {
            background: var(--mint-faint);
            border-color: var(--mint-soft);
            color: var(--forest);
        }

        /* ── Content card ── */
        .panel-card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 20px;
            margin-bottom: 14px;
        }

        .panel-card h3 {
            font-size: 14px;
            font-weight: 600;
            color: var(--forest);
            margin-bottom: 12px;
            font-family: inherit;
            letter-spacing: -0.01em;
        }

        /* ── Skill chip — design system ── */
        .chip {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            background: var(--mint-faint);
            color: var(--forest);
            border: 1px solid var(--mint-soft);
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 500;
        }

        /* ── Session row ── */
        .sess-row {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: border-color .14s, background .14s;
            margin-bottom: 8px;
        }

        .sess-row:hover {
            border-color: var(--accent);
        }

        .sess-tag {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 500;
            margin-top: 4px;
        }

        .sess-tag-1v1 {
            background: var(--info-bg);
            color: var(--info);
        }

        .sess-tag-group {
            background: #F5F3FF;
            color: var(--purple);
        }

        /* ── Review rating bar ── */
        .rating-bar-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12px;
            color: var(--gray-500);
        }

        .rating-bar-track {
            flex: 1;
            height: 6px;
            background: var(--gray-100);
            border-radius: 3px;
            overflow: hidden;
        }

        .rating-bar-fill {
            height: 100%;
            background: var(--accent);
            border-radius: 3px;
        }

        /* ── Right sidebar widgets ── */
        .widget {
            background: var(--surface);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-xs);
            padding: 18px;
            margin-bottom: 14px;
        }

        .widget h4 {
            font-size: 13px;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 12px;
            font-family: 'Inter', sans-serif;
        }

        .widget-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: var(--gray-500);
            margin-bottom: 8px;
        }

        .widget-row span:last-child {
            font-weight: 600;
            color: var(--gray-800);
            font-size: 13px;
        }

        /* ── Back button ── */
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            background: var(--surface);
            border: 1px solid var(--border);
            font-size: 13px;
            color: var(--gray-600);
            text-decoration: none;
            margin-bottom: 20px;
            transition: all .2s;
        }

        .back-btn:hover {
            border-color: var(--gray-300);
            color: var(--gray-800);
            box-shadow: var(--shadow-xs);
        }

        /* ── Message widget ── */
        .msg-widget {
            background: var(--info-bg);
            border: 1px solid rgba(59, 130, 246, .15);
            border-radius: var(--radius-lg);
            padding: 18px;
            margin-bottom: 14px;
        }

        /* ── Booking modal internals ── */
        .step {
            transition: all .3s ease;
        }

        .step.hidden-step {
            opacity: 0;
            transform: translateX(20px);
            position: absolute;
            pointer-events: none;
        }

        .step.active {
            opacity: 1;
            transform: translateX(0);
            position: relative;
        }

        .time-slot {
            padding: 7px 12px;
            border-radius: 8px;
            border: 1.5px solid var(--border);
            font-size: 11.5px;
            color: var(--gray-600);
            cursor: pointer;
            background: white;
            transition: all .15s;
        }

        .time-slot:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .time-slot.selected {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
        }

        /* ── Report modal ── */
        .report-drop {
            width: 100%;
            border: 1.5px dashed var(--border);
            border-radius: var(--radius);
            padding: 18px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            transition: all .2s;
        }

        .report-drop:hover {
            border-color: var(--accent);
            background: var(--info-bg);
        }

        /* ── Report menu ── */
        .dots-menu {
            position: absolute;
            right: 0;
            margin-top: 8px;
            width: 180px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-md);
        }

        /* ── Chat pulse dot ── */
        .chat-btn {
            position: relative;
        }

        .chat-btn::after {
            content: '';
            position: absolute;
            top: -2px;
            right: -2px;
            width: 10px;
            height: 10px;
            background: var(--success);
            border-radius: 50%;
            border: 2px solid white;
        }

        .mentor-profile-shell {
            max-width: 1640px;
        }

        .profile-cover {
            height: 104px;
            background:
                radial-gradient(circle at 11% 28%, #0a6811 0 72px, transparent 73px),
                radial-gradient(circle at 88% -40%, rgba(4, 74, 70, .72) 0 84px, transparent 86px),
                #237b78;
        }

        .profile-identity {
            min-height: 220px;
            display: grid;
            grid-template-columns: 230px 1fr auto;
            gap: 34px;
            align-items: center;
            padding: 0 0 34px;
            border-bottom: 1px solid var(--border);
        }

        .profile-avatar {
            width: 230px;
            height: 230px;
            margin: -58px 0 0;
            border: 10px solid #fff;
            border-radius: 50%;
            overflow: hidden;
            background: var(--gray-50);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-sm);
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-avatar-fallback {
            width: 86px;
            height: 86px;
            border-radius: 50%;
            background: var(--accent);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: 800;
        }

        .profile-heading h1 {
            margin: 0;
            color: var(--navy);
            font-size: 39px;
            line-height: 1.08;
            font-weight: 800;
        }

        .profile-heading p {
            margin: 8px 0 0;
            color: var(--navy);
            font-size: 24px;
            line-height: 1.35;
        }

        .profile-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-right: 24px;
        }

        .profile-action {
            width: 62px;
            height: 62px;
            border: 0;
            border-radius: 8px;
            background: #fff;
            color: var(--gray-700);
            box-shadow: var(--shadow-sm);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .profile-action svg {
            width: 26px;
            height: 26px;
        }

        .profile-tabs {
            display: flex;
            align-items: center;
            gap: 38px;
            margin: 34px 0 28px;
            border-bottom: 1px solid var(--border);
        }

        .profile-tabs .top-tab {
            min-height: 0;
            padding: 0 0 14px;
            border: 0;
            border-radius: 0;
            background: transparent;
            box-shadow: none;
            color: var(--gray-500);
            font-size: 15px;
            font-weight: 500;
            letter-spacing: -0.01em;
        }

        .profile-tabs .top-tab:hover {
            color: var(--forest);
            background: transparent;
            border: 0;
        }

        .profile-tabs .top-tab.t-active {
            color: var(--forest);
            font-weight: 600;
            border-bottom: 2px solid var(--forest);
        }

        .profile-content-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 580px;
            gap: 70px;
            align-items: start;
        }

        .profile-content-grid .panel-card {
            padding: 0 0 28px;
            border: 0;
            border-bottom: 1px solid var(--border);
            box-shadow: none;
        }

        .profile-content-grid .panel-card h3 {
            color: var(--navy);
            font-size: 24px;
            margin-bottom: 16px;
        }

        .profile-copy {
            max-width: 920px;
            color: var(--navy);
            font-size: 19px;
            line-height: 1.58;
        }

        .profile-stat-card,
        .profile-side-card {
            padding: 30px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            box-shadow: var(--shadow-sm);
        }

        .profile-stat-card h4,
        .profile-side-card h4 {
            margin: 0 0 22px;
            color: var(--navy);
            font-size: 26px;
        }

        .profile-stat-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
        }

        .profile-stat-item {
            display: grid;
            grid-template-columns: 54px 1fr;
            gap: 12px;
            align-items: center;
        }

        .profile-stat-icon {
            width: 54px;
            height: 54px;
            border-radius: 8px;
            background: var(--info-bg);
            color: var(--info);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .profile-stat-icon svg {
            width: 27px;
            height: 27px;
        }

        .mentor-tab-split {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 300px;
        }

        /* ── Profile hero ─────────────────────────────────────────────── */
        .vm-hero {
            position: relative;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 28px 32px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 28px;
            overflow: hidden;
        }

        .vm-hero-deco {
            position: absolute;
            inset: 0;
            pointer-events: none;
        }

        .vm-avatar {
            position: relative;
            width: 154px;
            height: 154px;
            border-radius: 50%;
            flex-shrink: 0;
            overflow: hidden;
            background: var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 46px;
            font-weight: 700;
            color: var(--forest);
        }

        .vm-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .vm-avatar-wrap {
            position: relative;
            flex-shrink: 0;
        }

        .vm-avatar-badge {
            position: absolute;
            right: 8px;
            bottom: 10px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: 3px solid var(--surface);
        }

        .vm-name-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .vm-name {
            font-size: 26px;
            font-weight: 700;
            color: var(--forest);
            letter-spacing: -0.02em;
            margin: 0;
        }

        .vm-role-pill {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--forest);
            background: var(--mint-faint);
            border-radius: 999px;
            padding: 3px 11px;
        }

        .vm-club {
            font-size: 14px;
            font-weight: 600;
            color: var(--mint);
            margin: 8px 0 0;
        }

        .vm-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
            color: var(--gray-500);
            margin-top: 10px;
        }

        .vm-status-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* ── Stats strip ──────────────────────────────────────────────── */
        .vm-stats {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 18px 8px;
            margin-bottom: 18px;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .vm-stat {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            border-right: 1px solid var(--border);
        }

        .vm-stat:last-child {
            border-right: none;
        }

        .vm-stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .vm-stat-icon svg {
            width: 19px;
            height: 19px;
        }

        .vm-stat-val {
            font-size: 22px;
            font-weight: 700;
            color: var(--forest);
            line-height: 1.1;
        }

        .vm-stat-lbl {
            font-size: 11.5px;
            color: var(--gray-500);
            margin-top: 2px;
        }

        /* ── Overview rows (content left, illustration right) ─────────── */
        .vm-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 24px;
            align-items: center;
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
        }

        .vm-row:last-child {
            border-bottom: none;
        }

        .vm-row-title {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 14.5px;
            font-weight: 700;
            color: var(--forest);
            margin-bottom: 10px;
        }

        .vm-row-title svg {
            width: 17px;
            height: 17px;
            color: var(--mint);
            flex-shrink: 0;
        }

        .vm-row-body {
            font-size: 13px;
            color: var(--gray-500);
            line-height: 1.65;
        }

        .vm-row-aside {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .vm-row-aside svg.vm-illus {
            width: 58px;
            height: 58px;
            flex-shrink: 0;
        }

        .vm-row-aside-text p {
            margin: 0;
            font-size: 13px;
            color: var(--gray-700);
            font-weight: 600;
        }

        .vm-row-aside-text span {
            display: block;
            margin-top: 3px;
            font-size: 12.5px;
            color: var(--gray-400);
            font-weight: 400;
            line-height: 1.5;
        }

        .vm-meta-grid {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .vm-meta-grid div {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 13px;
        }

        .vm-meta-grid dt {
            color: var(--gray-400);
        }

        .vm-meta-grid dd {
            margin: 0;
            color: var(--gray-800);
            font-weight: 600;
            text-align: right;
        }

        /* ── Sidebar widgets ──────────────────────────────────────────── */
        .vm-widget {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            margin-bottom: 14px;
        }

        .vm-widget-title {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 14px;
            font-weight: 700;
            color: var(--forest);
            margin-bottom: 14px;
        }

        .vm-widget-title svg {
            width: 17px;
            height: 17px;
            color: var(--mint);
        }

        .vm-widget-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 9px 0;
            font-size: 13px;
            color: var(--gray-600);
            border-bottom: 1px solid var(--border);
        }

        .vm-widget-row:last-of-type {
            border-bottom: none;
        }

        .vm-widget-row b {
            color: var(--forest);
            font-size: 14px;
        }

        .vm-widget-link {
            display: inline-block;
            margin-top: 12px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--mint);
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            font-family: inherit;
        }

        .vm-widget-link:hover {
            text-decoration: underline;
        }

        .vm-widget-soft {
            background: var(--mint-faint);
            border-color: var(--mint-soft);
        }

        .vm-chat-icon {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: var(--mint);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        @media (max-width: 900px) {
            .vm-hero {
                flex-direction: column;
                text-align: center;
                gap: 18px;
            }

            .vm-hero-actions {
                justify-content: center;
            }

            .vm-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                row-gap: 18px;
            }

            .vm-stat:nth-child(2) {
                border-right: none;
            }

            .vm-row {
                grid-template-columns: minmax(0, 1fr);
                gap: 14px;
            }
        }

        @media (max-width: 1180px) {
            .profile-identity {
                grid-template-columns: 180px 1fr;
            }

            .profile-actions {
                grid-column: 2;
                padding-right: 0;
            }

            .profile-avatar {
                width: 180px;
                height: 180px;
            }

            .profile-content-grid {
                grid-template-columns: 1fr;
                gap: 28px;
            }
        }

        @media (max-width: 760px) {
            .profile-identity {
                grid-template-columns: 1fr;
                gap: 18px;
                text-align: center;
            }

            .profile-avatar {
                margin: -52px auto 0;
            }

            .profile-actions {
                grid-column: auto;
                justify-content: center;
            }

            .profile-tabs {
                overflow-x: auto;
                gap: 24px;
            }

            .profile-stat-grid {
                grid-template-columns: 1fr;
            }

            .mentor-tab-split {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php if ($has_shell): ?>
            <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        <?php endif; ?>

        <main class="main fade-in">
            <?php if (!$has_shell): ?>
                <?php include __DIR__ . '/../includes/guest_header.php'; ?>
                <?php
                /*
                 * A signed-out visitor gets no app shell, and the shell is what
                 * pulls in toasts and the confirmation dialog. Without these two
                 * lines every message on this page falls back to the browser's
                 * own "localhost says" box for exactly the people least likely
                 * to forgive it. Both files guard against double inclusion, so
                 * this is safe even if the shell arrives another way.
                 */
                include __DIR__ . '/../../includes/toasts.php';
                include __DIR__ . '/../../includes/dialog.php';
                ?>
            <?php endif; ?>

            <!-- Back -->
            <a href="<?= htmlspecialchars($find_mentor_url) ?>" class="back-btn">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to Mentors
            </a>

            <!-- ── Hero Card ── -->
            <div class="vm-hero">
                <svg class="vm-hero-deco" viewBox="0 0 1200 260" preserveAspectRatio="none" aria-hidden="true">
                    <path d="M980 -40 C1120 40 1180 150 1210 300 L1210 -40 Z" fill="var(--mint-faint)" opacity=".75" />
                    <g fill="var(--mint-soft)">
                        <?php for ($r = 0; $r < 5; $r++): for ($c = 0; $c < 8; $c++): ?>
                                <circle cx="<?= 1000 + $c * 14 ?>" cy="<?= 60 + $r * 14 ?>" r="2.4" />
                        <?php endfor;
                        endfor; ?>
                    </g>
                </svg>

                <div class="vm-avatar-wrap">
                    <div class="vm-avatar">
                        <?php if (!empty($mentor['profile_image'])): ?>
                            <img src="<?= htmlspecialchars($mentor['profile_image']) ?>" alt="<?= htmlspecialchars($mentor_name) ?>">
                        <?php else: ?>
                            <?= strtoupper(substr($mentor['firstname'] ?? 'M', 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <span class="vm-avatar-badge" style="background:<?= $is_available ? 'var(--success)' : 'var(--gray-300)' ?>;"
                        title="<?= $is_available ? 'Has open session slots' : 'No open session slots' ?>"></span>
                </div>

                <div style="flex:1;min-width:0;position:relative;">
                    <div class="vm-name-row">
                        <h1 class="vm-name"><?= htmlspecialchars($mentor_name) ?></h1>
                        <span class="vm-role-pill">Mentor</span>
                    </div>
                    <?php if (!empty($mentor['club'])): ?>
                        <p class="vm-club"><?= htmlspecialchars($mentor['club']) ?></p>
                    <?php endif; ?>
                    <span class="vm-status">
                        <span class="vm-status-dot" style="background:<?= $is_available ? 'var(--success)' : 'var(--gray-300)' ?>;"></span>
                        <?= !$is_bookable
                            ? 'Not taking bookings right now'
                            : ($is_available
                                ? $open_slot_count . ' open session slot' . ($open_slot_count === 1 ? '' : 's')
                                : 'No open session slots right now') ?>
                    </span>
                </div>

                <div class="vm-hero-actions" style="display:flex;align-items:center;gap:8px;flex-shrink:0;position:relative;">
                    <?php if ($is_self): ?>
                        <span style="display:inline-flex;align-items:center;gap:7px;padding:8px 13px;border-radius:999px;background:var(--mint-faint);color:var(--mint-deep);font-size:12.5px;font-weight:600;">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            This is your public profile
                        </span>
                        <a href="<?= htmlspecialchars(url('mentor-profile')) ?>" class="btn btn-ghost">Edit profile</a>
                    <?php else: ?>
                    <a href="<?= htmlspecialchars($messages_url) ?>?chat=<?= $mentor_id ?>" class="chat-btn btn btn-blue">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                        </svg>
                        Message
                    </a>
                    <div style="position:relative;">
                        <button onclick="toggleReportMenu(this)" type="button" style="width:36px;height:36px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--gray-400);">
                            <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                                <circle cx="4" cy="10" r="1.5" />
                                <circle cx="10" cy="10" r="1.5" />
                                <circle cx="16" cy="10" r="1.5" />
                            </svg>
                        </button>
                        <div class="dots-menu hidden" data-menu>
                            <button onclick="openReportModal(<?= $mentor_id ?>); document.querySelectorAll('[data-menu]').forEach(m=>m.classList.add('hidden'));"
                                style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 14px;font-size:13px;color:var(--danger);background:none;border:none;cursor:pointer;border-radius:var(--radius);text-align:left;">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19H19a2 2 0 001.73-3L13.73 4a2 2 0 00-3.46 0L3.27 16A2 2 0 005.07 19z" />
                                </svg>
                                Report mentor
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Stats strip ── -->
            <div class="vm-stats">
                <div class="vm-stat">
                    <span class="vm-stat-icon" style="background:var(--mint-faint);color:var(--forest);">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="16" rx="3" /><path stroke-linecap="round" d="M8 2v4M16 2v4M5 9h14" /></svg>
                    </span>
                    <div>
                        <div class="vm-stat-val"><?= (int)($stats['total_sessions'] ?? 0) ?></div>
                        <div class="vm-stat-lbl">Sessions</div>
                    </div>
                </div>
                <div class="vm-stat">
                    <span class="vm-stat-icon" style="background:#EFEBFC;color:var(--purple);">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 19c0-1.8-1.2-3.3-2.8-3.8M17 4.4a3 3 0 0 1 0 5.2" /></svg>
                    </span>
                    <div>
                        <div class="vm-stat-val"><?= (int)($stats['mentees'] ?? 0) ?></div>
                        <div class="vm-stat-lbl">Mentees</div>
                    </div>
                </div>
                <div class="vm-stat">
                    <span class="vm-stat-icon" style="background:var(--gold-light);color:var(--warning);">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118L2.58 10.1c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>
                    </span>
                    <div>
                        <div class="vm-stat-val"><?= $avg['total_reviews'] ? number_format((float)$avg['avg_rating'], 1) : '—' ?></div>
                        <div class="vm-stat-lbl">Avg Rating</div>
                    </div>
                </div>
                <div class="vm-stat">
                    <span class="vm-stat-icon" style="background:var(--info-bg);color:var(--info);">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="9" r="6" /><path stroke-linecap="round" stroke-linejoin="round" d="M8.5 14L7 22l5-3 5 3-1.5-8" /></svg>
                    </span>
                    <div>
                        <div class="vm-stat-val"><?= $accomplishments_count ?></div>
                        <div class="vm-stat-lbl">Accomplishments</div>
                    </div>
                </div>
            </div>

            <!-- ── Tabs ── -->
            <div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;">
                <button id="top-tab-overview"        onclick="switchTopTab('overview')"        class="top-tab t-active"   type="button">Overview</button>
                <button id="top-tab-reviews"          onclick="switchTopTab('reviews')"          class="top-tab t-inactive" type="button">Reviews</button>
                <button id="top-tab-available"        onclick="switchTopTab('available')"        class="top-tab t-inactive" type="button">Available Sessions</button>
                <button id="top-tab-accomplishments"  onclick="switchTopTab('accomplishments')"  class="top-tab t-inactive" type="button">
                    🏅 Accomplishments
                    <?php if ($accomplishments_count > 0): ?>
                        <span style="margin-left:5px;background:var(--forest);color:white;border-radius:99px;padding:1px 7px;font-size:11px;">
                            <?= $accomplishments_count ?>
                        </span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- ── Two-column grid ── -->
            <div class="mentor-tab-split" style="gap:20px;align-items:start;">

                <!-- Left: Tab content -->
                <div>

                    <!-- OVERVIEW -->
                    <div id="content-overview" class="top-content">
                        <div class="panel-card" style="padding:0;">

                            <!-- About -->
                            <div class="vm-row">
                                <div>
                                    <div class="vm-row-title">
                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-4M12 8h.01" /></svg>
                                        About
                                    </div>
                                    <?php if ($mentor_bio !== ''): ?>
                                        <p class="vm-row-body"><?= nl2br(htmlspecialchars($mentor_bio)) ?></p>
                                    <?php else: ?>
                                        <p class="vm-row-body">No bio yet</p>
                                    <?php endif; ?>
                                </div>
                                <?php if ($mentor_bio === ''): ?>
                                    <div class="vm-row-aside">
                                        <svg class="vm-illus" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                                            <rect x="6" y="10" width="52" height="44" rx="7" fill="var(--mint-faint)" />
                                            <circle cx="24" cy="27" r="7" stroke="var(--mint)" stroke-width="2.5" />
                                            <path d="M15 44c1.6-6 4.9-9 9-9s7.4 3 9 9" stroke="var(--mint)" stroke-width="2.5" stroke-linecap="round" />
                                            <path d="M40 24h11M40 32h11M40 40h7" stroke="var(--mint-soft)" stroke-width="3" stroke-linecap="round" />
                                        </svg>
                                        <div class="vm-row-aside-text">
                                            <p>This mentor hasn't added a bio yet.</p>
                                            <span>Check back later to learn more about <?= htmlspecialchars($mentor_first) ?>.</span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Expertise -->
                            <div class="vm-row">
                                <div>
                                    <div class="vm-row-title">
                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 11V5a2 2 0 012-2h6l10 10-8 8L3 11Z" /><circle cx="8" cy="8" r="1.4" /></svg>
                                        Expertise
                                    </div>
                                    <?php if (!empty($skills)): ?>
                                        <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                            <?php foreach ($skills as $s): ?>
                                                <span class="chip"><?= htmlspecialchars($s) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="vm-row-body">No expertise listed</p>
                                    <?php endif; ?>
                                </div>
                                <?php if (empty($skills)): ?>
                                    <div class="vm-row-aside">
                                        <svg class="vm-illus" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                                            <circle cx="32" cy="32" r="22" fill="var(--mint-faint)" />
                                            <circle cx="32" cy="32" r="14" stroke="var(--mint)" stroke-width="2.5" />
                                            <circle cx="32" cy="32" r="6" stroke="var(--mint)" stroke-width="2.5" />
                                            <circle cx="32" cy="32" r="2" fill="var(--forest)" />
                                        </svg>
                                        <div class="vm-row-aside-text">
                                            <p>This mentor hasn't added expertise yet.</p>
                                            <span>Skills and expertise will appear here.</span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Mentoring Information — course / year / club / join date -->
                            <div class="vm-row">
                                <div>
                                    <div class="vm-row-title">
                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" /></svg>
                                        Mentoring Information
                                    </div>
                                    <?php if (!empty($mentoring_info)): ?>
                                        <dl class="vm-meta-grid">
                                            <?php foreach ($mentoring_info as $label => $value): ?>
                                                <div><dt><?= htmlspecialchars($label) ?></dt><dd><?= htmlspecialchars($value) ?></dd></div>
                                            <?php endforeach; ?>
                                        </dl>
                                    <?php else: ?>
                                        <p class="vm-row-body">No mentoring information available yet.</p>
                                    <?php endif; ?>
                                </div>
                                <?php if (empty($mentoring_info)): ?>
                                    <div class="vm-row-aside">
                                        <svg class="vm-illus" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                                            <circle cx="24" cy="24" r="9" fill="var(--mint-faint)" stroke="var(--mint)" stroke-width="2.5" />
                                            <circle cx="43" cy="28" r="7" fill="var(--mint-faint)" stroke="var(--mint-soft)" stroke-width="2.5" />
                                            <path d="M10 50c2-9 7.2-13.5 14-13.5S36 41 38 50" stroke="var(--mint)" stroke-width="2.5" stroke-linecap="round" />
                                            <path d="M43 50c1-6 3-9 8-9" stroke="var(--mint-soft)" stroke-width="2.5" stroke-linecap="round" />
                                        </svg>
                                        <div class="vm-row-aside-text">
                                            <p>More information about <?= htmlspecialchars($mentor_first) ?>'s mentoring</p>
                                            <span>approach and experience will be added soon.</span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Available Sessions summary -->
                            <div class="vm-row">
                                <div>
                                    <div class="vm-row-title">
                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="16" rx="3" /><path stroke-linecap="round" d="M8 2v4M16 2v4M5 9h14" /></svg>
                                        Available Sessions
                                    </div>
                                    <?php if ($open_slot_count > 0): ?>
                                        <p class="vm-row-body">
                                            <?= count($sessions_by_type['1v1']) ?> 1-on-1 &middot; <?= count($sessions_by_type['group']) ?> group slot<?= count($sessions_by_type['group']) === 1 ? '' : 's' ?> open.
                                        </p>
                                        <button type="button" class="vm-widget-link" onclick="switchTopTab('available')">View available sessions →</button>
                                    <?php else: ?>
                                        <p class="vm-row-body"><?= $is_bookable ? 'No available sessions at the moment.' : htmlspecialchars($not_bookable_text) ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php if ($open_slot_count === 0): ?>
                                    <div class="vm-row-aside">
                                        <svg class="vm-illus" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                                            <rect x="8" y="12" width="40" height="38" rx="7" fill="var(--mint-faint)" stroke="var(--mint)" stroke-width="2.5" />
                                            <path d="M8 24h40" stroke="var(--mint)" stroke-width="2.5" />
                                            <path d="M20 8v8M36 8v8" stroke="var(--mint)" stroke-width="2.5" stroke-linecap="round" />
                                            <circle cx="46" cy="44" r="11" fill="var(--surface)" stroke="var(--mint-soft)" stroke-width="2.5" />
                                            <path d="M46 39v5l3 3" stroke="var(--mint)" stroke-width="2.5" stroke-linecap="round" />
                                        </svg>
                                        <div class="vm-row-aside-text">
                                            <p><?= htmlspecialchars($mentor_first) ?> hasn't shared any upcoming</p>
                                            <span>sessions yet.</span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Accomplishments summary -->
                            <div class="vm-row">
                                <div>
                                    <div class="vm-row-title">
                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="9" r="6" /><path stroke-linecap="round" stroke-linejoin="round" d="M8.5 14L7 22l5-3 5 3-1.5-8" /></svg>
                                        Accomplishments
                                    </div>
                                    <?php if ($accomplishments_count > 0): ?>
                                        <p class="vm-row-body">
                                            <?= count($mentor_earned_badges) ?> badge<?= count($mentor_earned_badges) === 1 ? '' : 's' ?>
                                            &middot; <?= count($mentor_certs) ?> certificate<?= count($mentor_certs) === 1 ? '' : 's' ?>
                                        </p>
                                        <button type="button" class="vm-widget-link" onclick="switchTopTab('accomplishments')">View accomplishments →</button>
                                    <?php else: ?>
                                        <p class="vm-row-body">No accomplishments to showcase yet.</p>
                                    <?php endif; ?>
                                </div>
                                <?php if ($accomplishments_count === 0): ?>
                                    <div class="vm-row-aside">
                                        <svg class="vm-illus" viewBox="0 0 64 64" fill="none" aria-hidden="true">
                                            <path d="M20 10h24v12a12 12 0 0 1-24 0V10Z" fill="var(--mint-faint)" stroke="var(--mint)" stroke-width="2.5" />
                                            <path d="M20 14h-6v4a8 8 0 0 0 6 7.7M44 14h6v4a8 8 0 0 1-6 7.7" stroke="var(--mint-soft)" stroke-width="2.5" stroke-linecap="round" />
                                            <path d="M32 34v10M24 52h16l-2-8H26l-2 8Z" stroke="var(--mint)" stroke-width="2.5" stroke-linejoin="round" />
                                        </svg>
                                        <div class="vm-row-aside-text">
                                            <p>Accomplishments and achievements</p>
                                            <span>will appear here.</span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>

                    <!-- REVIEWS -->
                    <div id="content-reviews" class="top-content hidden">
                        <div class="panel-card" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
                            <div style="display:flex;align-items:center;gap:12px;">
                                <span style="font-size:32px;">⭐</span>
                                <div>
                                    <span style="font-size:30px;font-weight:700;color:var(--gray-900);"><?= number_format((float)($avg['avg_rating'] ?? 0), 2) ?></span>
                                </div>
                            </div>
                            <p style="font-size:12px;color:var(--gray-400);">Average of <?= (int)($avg['total_reviews'] ?? 0) ?> reviews</p>
                        </div>
                        <div class="panel-card">
                            <h3>Review Breakdown</h3>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                                <?php foreach (['Communication' => $avg['avg_comm'] ?? 0, 'Interaction & Engagement' => $avg['avg_eff'] ?? 0, 'Knowledge & Expertise' => $avg['avg_know'] ?? 0, 'Guidance & Support' => $avg['avg_skill'] ?? 0] as $label => $value):
                                    $pct = max(0, min(100, ((float)$value) * 20)); ?>
                                    <div>
                                        <div style="display:flex;justify-content:space-between;font-size:11.5px;color:var(--gray-500);margin-bottom:5px;">
                                            <span><?= $label ?></span><span><?= number_format((float)$value, 1) ?></span>
                                        </div>
                                        <div style="background:var(--gray-100);height:6px;border-radius:3px;overflow:hidden;">
                                            <div style="background:var(--accent);height:100%;width:<?= $pct ?>%;border-radius:3px;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="panel-card">
                            <h3>People Often Say</h3>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                <?php if (!empty($reviews)): foreach ($reviews as $rv): if (!empty(trim($rv['comment'] ?? ''))): ?>
                                            <span style="padding:6px 12px;background:var(--gray-50);border:1px solid var(--border);border-radius:var(--radius-sm);font-size:12px;color:var(--gray-600);"><?= htmlspecialchars($rv['comment']) ?></span>
                                    <?php endif;
                                    endforeach;
                                else: ?>
                                    <p style="font-size:13px;color:var(--gray-400);">No reviews yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="panel-card">
                            <h3>All Reviews</h3>
                            <?php if (!empty($reviews)): foreach ($reviews as $rv): ?>
                                    <div style="padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid var(--gray-100);">
                                        <p style="font-weight:600;color:var(--gray-800);font-size:13px;"><?= htmlspecialchars(($rv['firstname'] ?? '') . (' ') . $rv['lastname'] ?? '') ?></p>
                                        <p style="color:var(--gold);font-size:14px;"><?= str_repeat('★', (int)($rv['rating'] ?? 0)) ?></p>
                                        <p style="font-size:13px;color:var(--gray-500);margin-top:4px;"><?= htmlspecialchars($rv['comment'] ?? '') ?></p>
                                    </div>
                                <?php endforeach;
                            else: ?>
                                <p style="font-size:13px;color:var(--gray-400);">No reviews available yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- AVAILABLE SESSIONS -->
                    <div id="content-available" class="top-content hidden">
                        <div class="panel-card">
                            <h3>Available Sessions</h3>
                            <div style="display:flex;gap:8px;margin-bottom:18px;">
                                <button type="button" id="tab-1v1" onclick="switchSessionTab('1v1')" class="session-tab st-active">1v1</button>
                                <button type="button" id="tab-group" onclick="switchSessionTab('group')" class="session-tab st-inactive">Group</button>
                            </div>

                            <!-- 1v1 panel -->
                            <div id="panel-1v1" class="session-panel">
                                <?php if (!empty($sessions_by_type['1v1'])): foreach ($sessions_by_type['1v1'] as $session): ?>
                                        <div class="sess-row">
                                            <div>
                                                <p style="font-size:13px;font-weight:600;color:var(--gray-800);"><?= htmlspecialchars($session['subject']) ?></p>
                                                <p style="font-size:12px;color:var(--gray-400);margin-top:2px;"><?= date("h:i A", strtotime($session['start_time'])) ?> &bull; <?= (int)$session['duration'] ?> mins</p>
                                                <span class="sess-tag sess-tag-1v1">1v1</span>
                                            </div>
                                            <button onclick="openBookingModal(<?= $mentor_id ?>, <?= pc_js_arg($session['subject']) ?>, '1v1')" class="btn btn-blue" style="font-size:12px;padding:7px 16px;">Book</button>
                                        </div>
                                    <?php endforeach;
                                else: ?>
                                    <div class="empty-state" style="padding:30px 0;">
                                        <div class="empty-icon">📅</div>
                                        <p><?= $is_bookable ? 'No 1v1 sessions available' : htmlspecialchars($not_bookable_text) ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Group panel -->
                            <div id="panel-group" class="session-panel hidden">
                                <?php if (!empty($sessions_by_type['group'])): foreach ($sessions_by_type['group'] as $session): ?>
                                        <div class="sess-row">
                                            <div>
                                                <p style="font-size:13px;font-weight:600;color:var(--gray-800);"><?= htmlspecialchars($session['subject']) ?></p>
                                                <p style="font-size:12px;color:var(--gray-400);margin-top:2px;"><?= date("h:i A", strtotime($session['start_time'])) ?> &bull; <?= (int)$session['duration'] ?> mins</p>
                                                <span class="sess-tag sess-tag-group">Group</span>
                                            </div>
                                            <button onclick="openGroupReserveModal(<?= $mentor_id ?>, <?= pc_js_arg($session['subject']) ?>)" class="btn btn-blue" style="font-size:12px;padding:7px 16px;">Reserve Slot</button>
                                        </div>
                                    <?php endforeach;
                                else: ?>
                                    <div class="empty-state" style="padding:30px 0;">
                                        <div class="empty-icon">👥</div>
                                        <p><?= $is_bookable ? 'No group sessions available' : htmlspecialchars($not_bookable_text) ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ACCOMPLISHMENTS -->
                    <div id="content-accomplishments" class="top-content hidden">

                        <!-- Badges section -->
                        <div class="panel-card" style="margin-bottom:14px;">
                            <h3 style="font-size:14px;font-weight:700;color:var(--gray-900);margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                                🏅 Badges Earned
                                <span style="font-size:12px;font-weight:500;color:var(--gray-400);background:var(--gray-100);padding:2px 8px;border-radius:99px;">
                                    <?= count($mentor_earned_badges) ?>
                                </span>
                            </h3>

                            <?php if (empty($mentor_earned_badges)): ?>
                                <div style="text-align:center;padding:32px 0;color:var(--gray-400);">
                                    <div style="font-size:32px;margin-bottom:8px;">🏅</div>
                                    <p style="font-size:13px;">No badges earned yet.</p>
                                </div>
                            <?php else: ?>
                                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;">
                                    <?php foreach ($mentor_earned_badges as $b): ?>
                                        <div style="display:flex;align-items:flex-start;gap:12px;padding:14px;border:1px solid var(--border);border-radius:10px;background:var(--gray-50);">
                                            <div style="width:42px;height:42px;border-radius:50%;background:#fef9c3;border:2px solid #fde68a;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;">🏅</div>
                                            <div style="min-width:0;">
                                                <p style="font-size:13px;font-weight:700;color:var(--gray-900);margin:0 0 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($b['name']) ?></p>
                                                <p style="font-size:11.5px;color:var(--gray-500);margin:0 0 6px;line-height:1.4;"><?= htmlspecialchars($b['description']) ?></p>
                                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                                    <span style="font-size:10.5px;background:<?= $b['awarded_by'] ? '#dbeafe' : '#dcfce7' ?>;color:<?= $b['awarded_by'] ? '#1e40af' : '#166534' ?>;padding:2px 7px;border-radius:20px;font-weight:600;">
                                                        <?= $b['awarded_by'] ? '👤 Admin' : '🤖 Auto' ?>
                                                    </span>
                                                    <span style="font-size:10.5px;color:var(--gray-400);"><?= date('M j, Y', strtotime($b['awarded_at'])) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Certificates section -->
                        <div class="panel-card">
                            <h3 style="font-size:14px;font-weight:700;color:var(--gray-900);margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                                🎓 Certificates
                                <span style="font-size:12px;font-weight:500;color:var(--gray-400);background:var(--gray-100);padding:2px 8px;border-radius:99px;">
                                    <?= count($mentor_certs) ?>
                                </span>
                            </h3>

                            <?php if (empty($mentor_certs)): ?>
                                <div style="text-align:center;padding:32px 0;color:var(--gray-400);">
                                    <div style="font-size:32px;margin-bottom:8px;">🎓</div>
                                    <p style="font-size:13px;">No certificates yet.</p>
                                </div>
                            <?php else: ?>
                                <div style="overflow-x:auto;">
                                <table style="width:100%;border-collapse:collapse;">
                                    <thead style="background:var(--gray-50);">
                                        <tr>
                                            <th style="padding:10px 14px;font-size:11px;font-weight:700;color:var(--gray-500);text-align:left;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border);">Achievement</th>
                                            <th style="padding:10px 14px;font-size:11px;font-weight:700;color:var(--gray-500);text-align:left;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border);">Template</th>
                                            <th style="padding:10px 14px;font-size:11px;font-weight:700;color:var(--gray-500);text-align:left;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border);">Date Awarded</th>
                                            <th style="padding:10px 14px;font-size:11px;font-weight:700;color:var(--gray-500);text-align:left;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid var(--border);">Certificate</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mentor_certs as $c): ?>
                                        <tr style="border-bottom:1px solid var(--gray-100);">
                                            <td style="padding:12px 14px;font-size:13px;font-weight:600;color:var(--gray-900);">🎓 <?= htmlspecialchars($c['achievement']) ?></td>
                                            <td style="padding:12px 14px;font-size:13px;color:var(--gray-600);"><?= htmlspecialchars($c['template_name'] ?? 'Standard') ?></td>
                                            <td style="padding:12px 14px;font-size:13px;color:var(--gray-500);"><?= date('M j, Y', strtotime($c['awarded_at'])) ?></td>
                                            <td style="padding:12px 14px;">
                                                <?php if (!empty($c['generated_path'])): ?>
                                                    <a href="<?= htmlspecialchars($c['generated_path']) ?>" target="_blank"
                                                       style="display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--forest);font-weight:600;text-decoration:none;padding:4px 10px;border:1px solid var(--mint-soft);border-radius:6px;background:var(--mint-faint);">
                                                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                                        Download
                                                    </a>
                                                <?php else: ?>
                                                    <span style="font-size:12px;color:var(--gray-300);">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div><!-- /content-accomplishments -->

                </div><!-- /left col -->

                <!-- Right: widgets -->
                <div>
                    <div class="vm-widget">
                        <div class="vm-widget-title">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19h16M7 16V9m5 7V5m5 11v-4" /></svg>
                            Session Analytics
                        </div>
                        <div class="vm-widget-row"><span>Total Sessions</span><b><?= (int)($stats['total_sessions'] ?? 0) ?></b></div>
                        <div class="vm-widget-row"><span>Mentees</span><b><?= (int)($stats['mentees'] ?? 0) ?></b></div>
                        <div class="vm-widget-row">
                            <span>Avg Rating</span>
                            <b><?= $avg['total_reviews'] ? '⭐ ' . number_format((float)$avg['avg_rating'], 1) : '—' ?></b>
                        </div>
                        <!-- Opens the Reviews tab, which holds the real per-category
                             breakdown (communication / efficiency / knowledge / skill). -->
                        <button type="button" class="vm-widget-link" onclick="switchTopTab('reviews')">View detailed analytics →</button>
                    </div>

                    <?php if (!$is_self): // "Chat with this mentor" is you, on your own preview. ?>
                    <div class="vm-widget vm-widget-soft">
                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                            <span class="vm-chat-icon">
                                <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H12l-5 4v-4h-.5A2.5 2.5 0 0 1 4 13.5v-7Z" />
                                </svg>
                            </span>
                            <div>
                                <p style="font-size:13.5px;font-weight:700;color:var(--forest);margin:0;">Have a question?</p>
                                <p style="font-size:12.5px;color:var(--gray-500);margin:2px 0 0;line-height:1.45;">Chat directly with this mentor if you have any questions.</p>
                            </div>
                        </div>
                        <a href="<?= htmlspecialchars($messages_url) ?>?chat=<?= $mentor_id ?>" class="btn btn-primary" style="width:100%;justify-content:center;font-size:13px;">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H12l-5 4v-4h-.5A2.5 2.5 0 0 1 4 13.5v-7Z" />
                            </svg>
                            Open Chat
                        </a>
                    </div>
                    <?php endif; ?>

                    <div class="vm-widget vm-widget-soft">
                        <div class="vm-widget-title" style="margin-bottom:8px;">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 18h6M10 21h4M12 3a6 6 0 0 1 4 10.5V15H8v-1.5A6 6 0 0 1 12 3Z" /></svg>
                            Tip for mentees
                        </div>
                        <p style="font-size:12.5px;color:var(--gray-500);line-height:1.55;margin:0 0 12px;">
                            Send a message to introduce yourself and start building a connection.
                        </p>
                        <svg viewBox="0 0 200 90" fill="none" style="width:100%;height:auto;" aria-hidden="true">
                            <circle cx="60" cy="42" r="14" fill="var(--mint-soft)" />
                            <path d="M38 82c3-14 11-21 22-21s19 7 22 21" fill="var(--mint)" opacity=".55" />
                            <circle cx="140" cy="42" r="14" fill="var(--forest)" opacity=".85" />
                            <path d="M118 82c3-14 11-21 22-21s19 7 22 21" fill="var(--forest)" opacity=".55" />
                            <rect x="86" y="30" width="28" height="19" rx="6" fill="var(--surface)" stroke="var(--mint)" stroke-width="2" />
                            <path d="M94 55l4-6h-6l2 6Z" fill="var(--mint)" />
                        </svg>
                    </div>
                </div>
            </div><!-- /grid -->

        </main>
    </div><!-- /.app -->

    <!-- ════════════════════════ BOOKING MODAL ════════════════════════ -->
    <div id="bookingModal" class="m-overlay hidden">
        <div class="m-box" style="width:750px;max-height:90vh;padding:24px;display:flex;flex-direction:column;overflow:hidden;">
            <button onclick="closeModal()" type="button" style="position:absolute;right:16px;top:16px;width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--gray-400);">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 id="modalSubject" style="font-size:18px;font-weight:700;color:var(--gray-900);margin-bottom:2px;">Session</h2>
            <p style="font-size:13px;color:var(--gray-400);margin-bottom:16px;">
                <?= htmlspecialchars(($mentor['firstname'] ?? '') . ' ' . ($mentor['lastname'] ?? '')) ?> &bull; <?= htmlspecialchars($mentor['club'] ?? '') ?>
            </p>
            <hr style="border:none;border-top:1px solid var(--border);margin-bottom:20px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;flex:1;overflow:hidden;">
                <div>
                    <p style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-400);font-weight:700;margin-bottom:4px;">Duration</p>
                    <p id="sessionDuration" style="font-size:13px;font-weight:600;color:var(--gray-700);margin-bottom:14px;"></p>
                    <p style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-400);font-weight:700;margin-bottom:4px;">About</p>
                    <p id="aboutText" style="font-size:13px;color:var(--gray-600);margin-bottom:14px;line-height:1.6;"></p>
                    <p style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-400);font-weight:700;margin-bottom:6px;">Topics</p>
                    <div id="topics" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
                </div>
                <div style="overflow-y:auto;position:relative;">
                    <!-- Step 1 -->
                    <div id="step1" class="step active">
                        <p style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-400);font-weight:700;margin-bottom:4px;">Step 1 of 3</p>
                        <h3 style="font-size:13px;font-weight:600;color:var(--gray-800);margin-bottom:12px;">Select a date</h3>
                        <p id="monthLabel" style="font-size:13px;font-weight:500;color:var(--gray-700);margin-bottom:8px;"></p>
                        <div id="calendarGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:3px;text-align:center;font-size:11px;"></div>
                    </div>
                    <!-- Step 2 -->
                    <div id="step2" class="step hidden-step">
                        <p style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-400);font-weight:700;margin-bottom:4px;">Step 2 of 3</p>
                        <h3 style="font-size:13px;font-weight:600;color:var(--gray-800);margin-bottom:12px;">Select a time slot</h3>
                        <div id="timeSlots" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
                    </div>
                    <!-- Step 3 -->
                    <div id="step3" class="step hidden-step">
                        <p style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-400);font-weight:700;margin-bottom:4px;">Step 3 of 3</p>
                        <h3 style="font-size:13px;font-weight:600;color:var(--gray-800);margin-bottom:12px;">Confirm your booking</h3>
                        <hr style="border:none;border-top:1px solid var(--border);margin-bottom:14px;">
                        <div style="display:flex;flex-direction:column;gap:8px;font-size:13px;margin-bottom:16px;">
                            <div style="display:flex;justify-content:space-between;"><span style="color:var(--gray-400);">Type</span><span id="confirmSessionType" style="font-weight:600;color:var(--gray-800);">1v1</span></div>
                            <div style="display:flex;justify-content:space-between;"><span style="color:var(--gray-400);">Date</span><span id="confirmDate" style="font-weight:600;color:var(--gray-800);"></span></div>
                            <div style="display:flex;justify-content:space-between;"><span style="color:var(--gray-400);">Time</span><span id="confirmTime" style="font-weight:600;color:var(--gray-800);"></span></div>
                        </div>
                        <label style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-400);font-weight:700;display:block;margin-bottom:6px;">Notes</label>
                        <textarea id="note" class="form-input" rows="3" placeholder="e.g., I need help with this topic" style="resize:none;"></textarea>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:18px;padding-top:16px;border-top:1px solid var(--border);">
                <button id="backBtn" onclick="prevStep()" class="btn btn-ghost hidden" type="button">Back</button>
                <button id="continueBtn" onclick="nextStep()" class="btn" style="flex:1;justify-content:center;background:var(--gray-100);color:var(--gray-400);" type="button">Continue</button>
            </div>
        </div>
    </div>

    <!-- ════════════════════════ SUCCESS MODAL ════════════════════════ -->
    <div id="successModal" class="m-overlay hidden">
        <div class="m-box" style="width:360px;padding:36px 28px;text-align:center;">
            <div style="width:56px;height:56px;border-radius:50%;background:var(--success-bg);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <svg width="24" height="24" fill="none" stroke="var(--success)" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h2 style="font-size:18px;font-weight:700;color:var(--gray-900);margin-bottom:8px;">Request Sent!</h2>
            <p style="font-size:13px;color:var(--gray-400);margin-bottom:24px;">Your session request has been sent to the mentor.</p>
            <button onclick="closeSuccessModal()" class="btn btn-blue" style="width:100%;justify-content:center;" type="button">Done</button>
        </div>
    </div>

    <!-- ════════════════════════ GROUP MODAL ════════════════════════ -->
    <div id="groupModal" class="m-overlay hidden">
        <div class="m-box" style="width:500px;padding:24px;">
            <button onclick="closeGroupModal()" type="button" style="position:absolute;right:16px;top:16px;width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--gray-400);">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 style="font-size:18px;font-weight:700;color:var(--gray-900);margin-bottom:4px;">Reserve a Seat</h2>
            <p id="groupModalSubject" style="font-size:13px;color:var(--gray-400);margin-bottom:16px;"></p>
            <hr style="border:none;border-top:1px solid var(--border);margin-bottom:16px;">
            <div id="groupSessionList" style="display:flex;flex-direction:column;gap:10px;max-height:280px;overflow-y:auto;">
                <p style="color:var(--gray-400);font-size:13px;text-align:center;">Loading sessions...</p>
            </div>
            <div id="groupReserveConfirm" class="hidden" style="margin-top:16px;">
                <hr style="border:none;border-top:1px solid var(--border);margin-bottom:14px;">
                <p style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-400);font-weight:700;margin-bottom:4px;">Reserving seat for</p>
                <p id="groupConfirmDetails" style="font-size:13px;font-weight:600;color:var(--gray-800);margin-bottom:14px;"></p>
                <label style="font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-400);font-weight:700;display:block;margin-bottom:6px;">Notes</label>
                <textarea id="groupNote" class="form-input" rows="3" placeholder="Optional notes..." style="resize:none;"></textarea>
                <div style="display:flex;gap:8px;margin-top:14px;">
                    <button onclick="cancelGroupConfirm()" class="btn btn-ghost" type="button">Back</button>
                    <button onclick="confirmGroupReserve()" class="btn btn-blue" style="flex:1;justify-content:center;" type="button">Confirm Reserve</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════ REPORT MODAL ════════════════════════ -->
    <div id="reportModal" class="m-overlay hidden">
        <div class="m-box" style="width:500px;padding:24px;max-height:90vh;overflow-y:auto;">
            <button onclick="closeReportModal()" type="button" style="position:absolute;right:16px;top:16px;width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--gray-400);">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 style="font-size:18px;font-weight:700;color:var(--gray-900);margin-bottom:4px;">Report Mentor</h2>
            <p style="font-size:13px;color:var(--gray-500);margin-bottom:18px;">Reporting mentor ID: <span id="reportMentorId" style="font-weight:600;color:var(--gray-800);"></span></p>
            <form id="reportForm" style="display:flex;flex-direction:column;gap:14px;">
                <div>
                    <label class="form-label">Issue type</label>
                    <select id="reportIssueType" required class="form-input">
                        <option value="" disabled selected>Select an issue type</option>
                        <option value="no_show">No show</option>
                        <option value="communication">Communication</option>
                        <option value="session_quality">Session quality</option>
                        <option value="unprofessional_behavior">Unprofessional behavior</option>
                        <option value="scheduling_issue">Scheduling issue</option>
                        <option value="mismatch">Mismatch</option>
                        <option value="technical_issue">Technical issue</option>
                        <option value="policy_violation">Policy violation</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Proof <span style="font-weight:400;color:var(--gray-400);">(optional · max 5 MB)</span></label>
                    <input type="file" id="reportProof" accept="image/*" class="hidden" onchange="handleProofPreview(this)">
                    <div id="proofUploadArea" onclick="document.getElementById('reportProof').click()" class="report-drop">
                        <svg width="20" height="20" fill="none" stroke="var(--gray-300)" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4-4a3 3 0 014 0l4 4m-4-4l2-2a3 3 0 014 0l2 2M14 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <p style="font-size:12px;color:var(--gray-400);">Click to upload image</p>
                    </div>
                    <div id="proofPreview" class="hidden" style="margin-top:8px;position:relative;">
                        <img id="proofPreviewImg" src="" alt="proof preview" style="width:100%;max-height:160px;object-fit:cover;border-radius:var(--radius);border:1px solid var(--border);">
                        <button type="button" onclick="clearProof()" style="position:absolute;top:6px;right:6px;width:24px;height:24px;border-radius:50%;background:var(--surface);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--gray-400);">
                            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="form-label" for="reportReason">Description</label>
                    <textarea id="reportReason" class="form-input" rows="4" placeholder="Describe what happened..." required style="resize:none;"></textarea>
                </div>
                <button type="submit" class="btn" style="background:var(--danger);color:#fff;justify-content:center;padding:10px;">Submit Report</button>
            </form>
        </div>
    </div>

    <script>
        /* ── Guest gating ── */
        const IS_LOGGED_IN = <?= $is_logged_in ? 'true' : 'false' ?>;
        // IS_LOGGED_IN means "is a mentee, and may book/report". These two say
        // whether the viewer is signed in at all, and whether this is their own
        // profile — so a signed-in mentor is told why the action is unavailable
        // instead of being bounced to the signup page.
        const IS_SIGNED_IN = <?= $has_shell ? 'true' : 'false' ?>;
        const IS_SELF = <?= $is_self ? 'true' : 'false' ?>;
        const SIGNUP_URL = '<?= htmlspecialchars(url('signup'), ENT_QUOTES) ?>';

        function requireLogin(nextAction) {
            // Both branches below only run for someone signed in, which is
            // exactly when the shell — and so pcAlert — is on the page.
            if (IS_SELF) {
                pcAlert('This is a preview of your own public profile.',
                        'Booking and reporting are what a mentee would see here.');
                return;
            }
            if (IS_SIGNED_IN) {
                pcAlert('Only mentee accounts can book sessions.',
                        'Your account is not a mentee account, so booking is not available from here.');
                return;
            }
            const next = encodeURIComponent(window.location.pathname + window.location.search);
            window.location.href = SIGNUP_URL + '?next=' + next;
        }

        /* ── Sidebar & Profile ── */
        function toggleSidebar() {
            const sb = document.getElementById('sidebar');
            if (sb) sb.classList.toggle('collapsed');
        }

        function toggleProfileMenu() {
            document.getElementById('profileMenu').classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const m = document.getElementById('profileMenu');
            if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]')) m.classList.remove('open');
        });

        /* ── Top tabs ── */
        function switchTopTab(type) {
            document.querySelectorAll('.top-content').forEach(s => s.classList.add('hidden'));
            document.querySelectorAll('.top-tab').forEach(t => {
                t.classList.remove('t-active');
                t.classList.add('t-inactive');
            });
            document.getElementById('content-' + type).classList.remove('hidden');
            const activeTab = document.getElementById('top-tab-' + type);
            activeTab.classList.add('t-active');
            activeTab.classList.remove('t-inactive');
        }

        /* ── Session tabs ── */
        function switchSessionTab(type) {
            document.querySelectorAll('.session-panel').forEach(p => p.classList.add('hidden'));
            document.querySelectorAll('.session-tab').forEach(t => {
                t.classList.remove('st-active');
                t.classList.add('st-inactive');
            });
            document.getElementById('panel-' + type).classList.remove('hidden');
            const activeTab = document.getElementById('tab-' + type);
            activeTab.classList.add('st-active');
            activeTab.classList.remove('st-inactive');
        }

        /* ── Routed URLs (generated server-side) ── */
        const ROUTE_AVAILABILITY = '<?= htmlspecialchars($route_availability_url, ENT_QUOTES) ?>';
        const ROUTE_TIMES = '<?= htmlspecialchars($route_times_url, ENT_QUOTES) ?>';
        const ROUTE_GROUP_SESSIONS = '<?= htmlspecialchars($route_group_sessions_url, ENT_QUOTES) ?>';
        const ROUTE_SAVE_BOOKING = '<?= htmlspecialchars($route_save_booking_url, ENT_QUOTES) ?>';
        const ROUTE_SAVE_REPORT = '<?= htmlspecialchars($route_save_report_url, ENT_QUOTES) ?>';

        /* ── Booking Modal ── */
        let step = 1,
            selectedDate = '',
            selectedTime = '',
            subject = '',
            mentor_id = '',
            sessionType = '1v1';
        let availableDates = [],
            currentMonth = new Date().getMonth(),
            currentYear = new Date().getFullYear();

        function openBookingModal(mid, sub, sType) {
            if (!IS_LOGGED_IN) {
                requireLogin();
                return;
            }
            mentor_id = mid;
            subject = sub;
            sessionType = sType;
            document.getElementById('modalSubject').innerText = sub + ' · ' + (sType === 'group' ? 'Group' : '1v1');
            document.getElementById('confirmSessionType').innerText = sType === 'group' ? 'Group' : '1v1';
            if (sType === 'group') {
                openGroupReserveModal(mid, sub);
                return;
            }
            step = 1;
            showStep(1);
            updateButtons();
            loadAvailability();
            document.getElementById('bookingModal').classList.remove('hidden');
        }

        function showStep(n) {
            for (let i = 1; i <= 3; i++) {
                const el = document.getElementById('step' + i);
                el.classList.remove('active', 'hidden-step');
                el.classList.add(i === n ? 'active' : 'hidden-step');
            }
        }

        function nextStep() {
            if (step === 1 && !selectedDate) return;
            if (step === 2 && !selectedTime) return;
            if (step === 3) {
                bookSession();
                return;
            }
            step++;
            showStep(step);
            updateButtons();
            if (step === 2) loadTimeSlots();
            if (step === 3) {
                document.getElementById('confirmDate').innerText = selectedDate;
                document.getElementById('confirmTime').innerText = selectedTime;
            }
        }

        function loadAvailability() {
            fetch(`${ROUTE_AVAILABILITY}?mentor_id=${mentor_id}&subject=${encodeURIComponent(subject)}&session_type=${encodeURIComponent(sessionType)}`)
                .then(r => r.json()).then(data => {
                    availableDates = data;
                    renderCalendar(data);
                });
        }

        function renderCalendar(data) {
            const container = document.getElementById('calendarGrid');
            container.innerHTML = '';
            const monthName = new Date(currentYear, currentMonth, 1).toLocaleString('default', {
                month: 'long',
                year: 'numeric'
            });
            const target = document.getElementById('monthNavRow') || document.getElementById('monthLabel');
            target.outerHTML = `
        <div id="monthNavRow" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
            <button type="button" onclick="prevMonth()" style="width:28px;height:28px;border-radius:8px;border:1px solid var(--border);background:var(--surface);cursor:pointer;color:var(--gray-400);font-size:14px;display:flex;align-items:center;justify-content:center;">&#8249;</button>
            <p id="monthLabel" style="font-size:13px;font-weight:600;color:var(--gray-700);">${monthName}</p>
            <button type="button" onclick="nextMonth()" style="width:28px;height:28px;border-radius:8px;border:1px solid var(--border);background:var(--surface);cursor:pointer;color:var(--gray-400);font-size:14px;display:flex;align-items:center;justify-content:center;">&#8250;</button>
        </div>`;
            // The cells are built as elements, not HTML strings. The slot's
            // description and topics are mentor-written text: passed through an
            // inline onclick, an apostrophe ("I'll ...") ended the string early
            // and the date could not be picked. (innerHTML += would also rebuild
            // every cell and drop the click listeners attached below.)
            const cell = (text, style) => {
                const el = document.createElement('div');
                if (style) el.style.cssText = style;
                el.textContent = text;
                container.appendChild(el);
                return el;
            };
            ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].forEach(d => {
                cell(d, 'color:var(--gray-300);font-size:10px;padding:4px 2px;text-align:center;font-weight:700;');
            });
            const totalDays = new Date(currentYear, currentMonth + 1, 0).getDate();
            const firstDay = new Date(currentYear, currentMonth, 1).getDay();
            for (let i = 0; i < firstDay; i++) cell('', '');
            for (let i = 1; i <= totalDays; i++) {
                const fullDate = `${currentYear}-${String(currentMonth+1).padStart(2,'0')}-${String(i).padStart(2,'0')}`;
                const found = data.find(d => d.date === fullDate);
                if (found) {
                    const isSelected = selectedDate === fullDate;
                    const bg = isSelected ? 'var(--navy)' : 'var(--accent)';
                    const day = cell(String(i), `padding:5px 2px;border-radius:8px;cursor:pointer;font-size:11px;text-align:center;background:${bg};color:#fff;transition:opacity .15s;`);
                    day.addEventListener('mouseover', () => { day.style.opacity = '.85'; });
                    day.addEventListener('mouseout', () => { day.style.opacity = '1'; });
                    day.addEventListener('click', () => {
                        selectDate(fullDate, String(found.about ?? ''), String(found.topics ?? ''), String(found.duration));
                    });
                } else {
                    cell(String(i), 'padding:5px 2px;font-size:11px;text-align:center;color:var(--gray-200);');
                }
            }
        }

        function prevMonth() {
            currentMonth--;
            if (currentMonth < 0) {
                currentMonth = 11;
                currentYear--;
            }
            renderCalendar(availableDates);
        }

        function nextMonth() {
            currentMonth++;
            if (currentMonth > 11) {
                currentMonth = 0;
                currentYear++;
            }
            renderCalendar(availableDates);
        }

        function selectDate(date, about, topics, duration) {
            selectedDate = date;
            document.getElementById('aboutText').innerText = about;
            // Topics are mentor-written: shown as text, never parsed as HTML.
            const topicsBox = document.getElementById('topics');
            topicsBox.innerHTML = '';
            topics.split(',').forEach(t => {
                const tt = t.trim();
                if (!tt) return;
                const chip = document.createElement('span');
                chip.style.cssText = 'padding:4px 10px;background:var(--info-bg);color:var(--info);border-radius:6px;font-size:11px;';
                chip.textContent = tt;
                topicsBox.appendChild(chip);
            });
            const mins = parseInt(duration, 10) || 0;
            document.getElementById('sessionDuration').innerText = mins >= 60 ? `${mins/60} hr` : `${mins} mins`;
            renderCalendar(availableDates);
            const btn = document.getElementById('continueBtn');
            btn.style.background = 'var(--accent)';
            btn.style.color = '#fff';
        }

        function loadTimeSlots() {
            fetch(`${ROUTE_TIMES}?mentor_id=${mentor_id}&date=${selectedDate}&subject=${encodeURIComponent(subject)}&session_type=${encodeURIComponent(sessionType)}`)
                .then(r => r.json()).then(data => {
                    const container = document.getElementById('timeSlots');
                    container.innerHTML = '';
                    if (!data.length) {
                        container.innerHTML = '<p style="font-size:13px;color:var(--gray-400);">No available slots</p>';
                        return;
                    }
                    data.forEach(t => {
                        container.innerHTML += `<button onclick="selectTime('${t.time}', this)" class="time-slot">${t.time}</button>`;
                    });
                });
        }

        function selectTime(time, el) {
            selectedTime = time;
            document.querySelectorAll('#timeSlots button').forEach(b => b.classList.remove('selected'));
            el.classList.add('selected');
            const btn = document.getElementById('continueBtn');
            btn.style.background = 'var(--accent)';
            btn.style.color = '#fff';
        }

        function bookSession() {
            const confirmBtn = document.getElementById('continueBtn');
            const resetBtn   = typeof pcLoadingBtn === 'function'
                ? pcLoadingBtn(confirmBtn, 'Booking…')
                : null;

            fetch(ROUTE_SAVE_BOOKING, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    mentor_id,
                    subject,
                    session_type: sessionType,
                    date: selectedDate,
                    time: selectedTime,
                    // The Notes box used to be collected and thrown away —
                    // session_requests now has a message column for it.
                    message: (document.getElementById('note')?.value || '').trim(),
                    csrf_token: window.__PC_CSRF__ || ''
                })
            }).then(r => r.json()).then(res => {
                if (resetBtn) resetBtn();
                if (res.error) {
                    pcToast(res.error, 'error', 4000);
                    return;
                }
                document.getElementById('bookingModal').classList.add('hidden');
                document.getElementById('successModal').classList.remove('hidden');
                pcToast('Session booked successfully! 🎉', 'success');
            }).catch(() => {
                if (resetBtn) resetBtn();
                pcToast('Network error. Please try again.', 'error');
            });
        }

        function closeModal() {
            document.getElementById('bookingModal').classList.add('hidden');
            step = 1;
            showStep(1);
            selectedDate = '';
            selectedTime = '';
            subject = '';
            mentor_id = '';
            sessionType = '1v1';
            availableDates = [];
            document.getElementById('timeSlots').innerHTML = '';
            document.getElementById('calendarGrid').innerHTML = '';
            document.getElementById('aboutText').innerText = '';
            document.getElementById('topics').innerHTML = '';
            document.getElementById('sessionDuration').innerText = '';
            document.getElementById('confirmDate').innerText = '';
            document.getElementById('confirmTime').innerText = '';
            document.getElementById('confirmSessionType').innerText = '1v1';
            const btn = document.getElementById('continueBtn');
            btn.style.background = 'var(--gray-100)';
            btn.style.color = 'var(--gray-400)';
            btn.innerText = 'Continue';
            document.getElementById('backBtn').classList.add('hidden');
        }

        function closeSuccessModal() {
            document.getElementById('successModal').classList.add('hidden');
            location.reload();
        }

        function prevStep() {
            step--;
            showStep(step);
            updateButtons();
        }

        function updateButtons() {
            const backBtn = document.getElementById('backBtn'),
                nextBtn = document.getElementById('continueBtn');
            step > 1 ? backBtn.classList.remove('hidden') : backBtn.classList.add('hidden');
            if (step === 3) {
                nextBtn.innerText = 'Book Session';
                nextBtn.style.background = 'var(--accent)';
                nextBtn.style.color = '#fff';
            } else {
                nextBtn.innerText = 'Continue';
            }
        }

        setInterval(() => {
            if (step === 2 && selectedDate) loadTimeSlots();
        }, 5000);

        /* ── Group Modal ── */
        let selectedGroupSession = null;

        function openGroupReserveModal(mid, sub) {
            mentor_id = mid;
            selectedGroupSession = null;
            document.getElementById('groupModalSubject').innerText = sub;
            document.getElementById('groupReserveConfirm').classList.add('hidden');
            document.getElementById('groupSessionList').innerHTML = '<p style="color:var(--gray-400);font-size:13px;text-align:center;">Loading sessions...</p>';
            document.getElementById('groupModal').classList.remove('hidden');
            fetch(`${ROUTE_GROUP_SESSIONS}?mentor_id=${mid}&subject=${encodeURIComponent(sub)}`)
                .then(r => r.json()).then(data => {
                    const container = document.getElementById('groupSessionList');
                    container.innerHTML = '';
                    if (!data.length) {
                        container.innerHTML = '<p style="color:var(--gray-400);font-size:13px;text-align:center;">No group sessions available.</p>';
                        return;
                    }
                    // Built as elements: the subject is mentor-written text and
                    // was inserted as HTML. The Reserve click is attached directly
                    // rather than written into an onclick string.
                    const node = (tag, style, text) => {
                        const n = document.createElement(tag);
                        if (style) n.style.cssText = style;
                        if (text !== undefined) n.textContent = text;
                        return n;
                    };
                    data.forEach(session => {
                        const spotsLeft = session.capacity - session.reserved_count,
                            isFull = spotsLeft <= 0;
                        const row = node('div', 'border:1.5px solid var(--border);border-radius:var(--radius);padding:14px 16px;display:flex;justify-content:space-between;align-items:center;' + (isFull ? 'opacity:.55' : ''));
                        const info = node('div');
                        info.append(
                            node('p', 'font-size:13px;font-weight:600;color:var(--gray-800);', String(session.subject)),
                            node('p', 'font-size:12px;color:var(--gray-400);margin-top:2px;', `${session.session_date} • ${session.start_time}`),
                            node('p', 'font-size:11px;color:var(--gray-300);', `Capacity: ${session.capacity} seats`),
                            node('p', `font-size:12px;font-weight:600;margin-top:4px;color:${spotsLeft<=3?'var(--danger)':'var(--success)'};`,
                                isFull ? '🔴 Full' : `🟢 ${spotsLeft} of ${session.capacity} seats left`)
                        );
                        const reserve = node('button', 'font-size:12px;padding:7px 16px;', 'Reserve');
                        reserve.className = 'btn btn-blue';
                        reserve.disabled = isFull;
                        reserve.addEventListener('click', () => selectGroupSession(session));
                        row.append(info, reserve);
                        container.appendChild(row);
                    });
                });
        }

        function selectGroupSession(session) {
            selectedGroupSession = session;
            const spotsLeft = session.capacity - session.reserved_count;
            document.getElementById('groupConfirmDetails').innerText = `${session.subject} — ${session.session_date} at ${session.start_time} (${spotsLeft} seat${spotsLeft!==1?'s':''} left)`;
            document.getElementById('groupSessionList').classList.add('hidden');
            document.getElementById('groupReserveConfirm').classList.remove('hidden');
        }

        function cancelGroupConfirm() {
            selectedGroupSession = null;
            document.getElementById('groupSessionList').classList.remove('hidden');
            document.getElementById('groupReserveConfirm').classList.add('hidden');
        }

        function confirmGroupReserve() {
            const note = document.getElementById('groupNote').value;
            fetch(ROUTE_SAVE_BOOKING, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    mentor_id,
                    subject: selectedGroupSession.subject,
                    session_type: 'group',
                    availability_id: selectedGroupSession.availability_id,
                    date: selectedGroupSession.session_date,
                    time: selectedGroupSession.start_time,
                    // save_booking.php reads the note as "message", like the
                    // other two booking forms; sent as "note" it was dropped.
                    message: note.trim(),
                    csrf_token: window.__PC_CSRF__ || ''
                })
            }).then(r => r.json()).then(res => {
                if (res.error) {
                    pcToast(res.error, 'error', 4000);
                    return;
                }
                closeGroupModal();
                document.getElementById('successModal').classList.remove('hidden');
                pcToast('Group session reserved! 🎉', 'success');
            }).catch(() => {
                pcToast('Network error. Please try again.', 'error');
            });
        }

        function closeGroupModal() {
            document.getElementById('groupModal').classList.add('hidden');
            document.getElementById('groupSessionList').classList.remove('hidden');
            document.getElementById('groupReserveConfirm').classList.add('hidden');
            document.getElementById('groupNote').value = '';
            selectedGroupSession = null;
        }

        /* ── Report Modal ── */
        function toggleReportMenu(btn) {
            const menu = btn.nextElementSibling;
            document.querySelectorAll('[data-menu]').forEach(m => {
                if (m !== menu) m.classList.add('hidden');
            });
            menu.classList.toggle('hidden');
        }
        document.addEventListener('click', (e) => {
            if (!e.target.closest('[data-menu], button')) document.querySelectorAll('[data-menu]').forEach(m => m.classList.add('hidden'));
        });

        let currentMentorId = null;

        function openReportModal(mentorId) {
            if (!IS_LOGGED_IN) {
                requireLogin();
                return;
            }
            currentMentorId = mentorId;
            document.getElementById('reportMentorId').innerText = mentorId;
            document.getElementById('reportReason').value = '';
            document.getElementById('reportModal').classList.remove('hidden');
        }

        function closeReportModal() {
            document.getElementById('reportModal').classList.add('hidden');
            clearProof();
        }

        function handleProofPreview(input) {
            if (!input.files || !input.files[0]) return;
            const url = URL.createObjectURL(input.files[0]);
            document.getElementById('proofPreviewImg').src = url;
            document.getElementById('proofPreview').classList.remove('hidden');
            document.getElementById('proofUploadArea').classList.add('hidden');
        }

        function clearProof() {
            document.getElementById('reportProof').value = '';
            document.getElementById('proofPreviewImg').src = '';
            document.getElementById('proofPreview').classList.add('hidden');
            document.getElementById('proofUploadArea').classList.remove('hidden');
        }

        document.getElementById('reportForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const reason = document.getElementById('reportReason').value.trim();
            const issueType = document.getElementById('reportIssueType').value;
            const proofFile = document.getElementById('reportProof').files[0] ?? null;
            if (!issueType || !reason) {
                pcToast('Please fill in all required fields.', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('mentor_id', currentMentorId);
            fd.append('issue_type', issueType);
            fd.append('reason', reason);
            fd.append('csrf_token', window.__PC_CSRF__ || '');
            if (proofFile) fd.append('proof', proofFile);

            const reportBtn  = document.querySelector('#reportForm [type="submit"]');
            const resetReport = reportBtn && typeof pcLoadingBtn === 'function'
                ? pcLoadingBtn(reportBtn, 'Submitting…') : null;

            fetch(ROUTE_SAVE_REPORT, { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (resetReport) resetReport();
                    if (data.success) {
                        pcToast('Report submitted successfully.', 'success');
                        closeReportModal();
                        clearProof();
                    } else {
                        pcToast('Error: ' + (data.message ?? 'Unknown error.'), 'error', 4000);
                    }
                })
                .catch(() => {
                    if (resetReport) resetReport();
                    pcToast('An error occurred while submitting the report.', 'error');
                });
        });
    </script>
</body>

</html>