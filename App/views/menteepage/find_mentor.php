<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

// A value sent as a list counts as missing; strip_tags() of a list used to end
// the page on a server error. The search is bound as a parameter and printed
// escaped, so it is only trimmed.
$get     = fn(string $name): string => is_string($_GET[$name] ?? null) ? trim($_GET[$name]) : '';
$search  = $get('search');
$subject = $get('subject');
$club    = $get('club');

$sort         = in_array($get('sort'), ['relevant', 'rating', 'sessions'], true) ? $get('sort') : 'relevant';
$session_type = in_array($get('type'), ['1v1', 'group'], true) ? $get('type') : '';

$per_page = 12;
$page     = max(1, (int)($get('page') ?: 1));

// ── Real, fixed taxonomy — same lists the signup/verification form already
//    uses (App/views/menteepage/verification.php), not a freeform/dynamic
//    list that can drift or surface dirty data. Subject reuses the same 9
//    academic areas as Club (just without the word "Club"), since each club
//    maps 1:1 to a subject area — so both filters stay grounded in the one
//    real taxonomy this school actually uses, instead of the mentor-entered
//    free-text `expertise` field (which has values like "axaxa"). ─────────
//    PC_CLUBS itself now lives in helpers.php, shared with Resources.
$subject_options = array_map(fn($c) => str_replace(' Club', '', $c), PC_CLUBS);
// Real mentor-entered `expertise` text is inconsistent ("MATH", "Math",
// "Mathematics"…), so matching must key off a short stem rather than the
// full canonical label — "Mathematics" is not a substring of "MATH", but
// "Math" is a substring of all three forms.
$subject_stems = [
    'Mathematics'           => 'Math',
    'Science'               => 'Science',
    'English'               => 'English',
    'Social Studies'        => 'Social',
    'Home Economics'        => 'Home',
    'Industrial Education'  => 'Industrial',
    'Physical Education'    => 'Physical',
    'Special Education'     => 'Special',
    'Elementary Education'  => 'Elementary',
];

// Only values from the fixed lists above filter the list.
$filters = [
    'search'       => $search,
    'expertise'    => ($subject !== '' && in_array($subject, $subject_options, true)) ? $subject_stems[$subject] : '',
    'club'         => ($club !== '' && in_array($club, PC_CLUBS, true)) ? $club : '',
    'session_type' => $session_type,
];

// ── "Most Relevant" = overlap with this mentee's questionnaire answers ───
// Sorting by session count alone made "relevant" a synonym for "busiest",
// which is the leaderboard order again. When the viewer is a mentee who has
// answered the questionnaire, mentors who picked the same subjects and skills
// lead, and the old activity ordering breaks ties. Ordering happens in SQL so
// pagination still works.
require_once __DIR__ . '/../../services/MentorScoreService.php';
require_once __DIR__ . '/../includes/pagination.php';
$viewer_id     = (($_SESSION['role'] ?? '') === 'mentee') ? (int)($_SESSION['user_id'] ?? 0) : 0;
$viewer_weight = $viewer_id > 0 ? MentorScoreService::menteeTagWeight($con, $viewer_id) : 0;

$total_mentors = MentorDirectoryRepository::count($con, $filters);
$total_pages   = max(1, (int)ceil($total_mentors / $per_page));
$page          = min($page, $total_pages);
$offset        = ($page - 1) * $per_page;

$mentor_rows = MentorDirectoryRepository::page($con, $filters, $sort, $viewer_weight > 0 ? $viewer_id : 0, $per_page, $offset);

// Which of this mentee's answers each mentor on this page also picked —
// one query for the page, then rendered as highlighted chips on the cards.
$shared_tags = $viewer_weight > 0 && $mentor_rows
    ? MentorScoreService::sharedTags($con, $viewer_id, array_column($mentor_rows, 'user_id'))
    : [];

$find_mentor_url = url('mentee-find');
$view_mentor_url = url('mentee-view-mentor');
$active_page = 'find_mentor';
$is_logged_in = isset($_SESSION['user_id']) && isset($_SESSION['role']);
$has_filters = $search || $subject || $club || $session_type;

// Up to three badges for each mentor shown. This used to read every badge of
// every member on each visit, then keep three per mentor.
$mentor_badges = AchievementRepository::badgeNamesFor($con, array_column($mentor_rows, 'user_id'), 3);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find a Mentor — PeerConnect</title>
    <?php include __DIR__ . '/includes/style.php'; ?>
    <style>
        /* .hero-banner/.hero-illustration/.pcard* live in the shared design
           system (see menteepage/index.php, which introduced them). */

        .fm-toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .fm-search {
            flex: 1 1 280px;
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            height: 44px;
            padding: 0 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--surface);
        }

        .fm-search svg {
            width: 17px;
            height: 17px;
            color: var(--gray-400);
            flex-shrink: 0;
        }

        .fm-search input {
            flex: 1;
            min-width: 0;
            border: 0;
            outline: 0;
            background: transparent;
            font-size: 13.5px;
            font-family: inherit;
            color: var(--gray-800);
        }

        .fm-filters-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 44px;
            padding: 0 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--surface);
            color: var(--gray-700);
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            flex-shrink: 0;
        }

        .fm-filters-btn:hover,
        .fm-filters-btn.active {
            background: var(--mint-faint);
            border-color: var(--mint-soft);
            color: var(--forest);
        }

        /* A chip the mentee also picked in the questionnaire. Two classes, so
           it wins regardless of where .mc-tag sits in this stylesheet. */
        .mc-tag.mc-tag-match {
            background: var(--mint-faint);
            color: var(--mint);
            border-color: var(--mint);
            font-weight: 600;
        }

        .fm-sort {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
            font-size: 12.5px;
            color: var(--gray-500);
            font-weight: 600;
        }

        .fm-sort select {
            height: 44px;
            min-height: 0;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 13px;
            padding: 0 10px;
        }

        .fm-view-toggle {
            display: flex;
            gap: 2px;
            background: var(--gray-50);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 2px;
            flex-shrink: 0;
        }

        .fm-view-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: var(--gray-400);
            cursor: pointer;
        }

        .fm-view-btn.active {
            background: var(--surface);
            color: var(--forest);
            box-shadow: var(--shadow-xs);
        }

        .fm-filter-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
        }

        .fm-filter-row select {
            min-width: 170px;
            flex: 1 1 170px;
            min-height: 42px;
            font-size: 13px;
        }

        .fm-clear {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--mint);
            text-decoration: none;
            white-space: nowrap;
        }

        .fm-clear:hover {
            text-decoration: underline;
        }

        .fm-count-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .fm-count-row span {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-600);
        }

        /* Mentor cards */
        .mc-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(280px, 1fr));
            gap: 18px;
        }

        .mc-grid.list-view {
            grid-template-columns: 1fr;
        }

        .mc-card {
            display: flex;
            flex-direction: column;
        }

        .mc-top {
            display: flex;
            align-items: flex-start;
            gap: 13px;
            padding: 20px 20px 0;
        }

        .mc-avatar {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            flex-shrink: 0;
            position: relative;
            overflow: hidden;
            background: var(--mint-faint);
            color: var(--forest);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
        }

        .mc-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .mc-verified {
            position: absolute;
            bottom: -1px;
            right: -1px;
            width: 17px;
            height: 17px;
            border-radius: 50%;
            background: var(--success);
            border: 2px solid var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .mc-verified svg {
            width: 9px;
            height: 9px;
            color: #fff;
        }

        .mc-name-row {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
        }

        .mc-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .mc-role-pill {
            font-size: 10.5px;
            font-weight: 700;
            color: var(--forest);
            background: var(--mint-faint);
            border-radius: 999px;
            padding: 2px 9px;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        .mc-title {
            font-size: 12.5px;
            color: var(--gray-500);
            margin-top: 3px;
        }

        .mc-rating {
            font-size: 12px;
            color: var(--warning);
            font-weight: 600;
            margin-top: 4px;
        }

        .mc-rating .n {
            color: var(--gray-400);
            font-weight: 500;
        }

        .mc-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding: 14px 20px 0;
        }

        .mc-tag {
            font-size: 11px;
            font-weight: 600;
            color: var(--gray-600);
            background: var(--gray-50);
            border: 1px solid var(--border);
            /* See .pf-tag — shared questionnaire answers wrap, so no full pill. */
            border-radius: 12px;
            padding: 4px 10px;
            max-width: 100%;
            line-height: 1.45;
        }

        .mc-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            padding: 10px 20px 0;
        }

        .mc-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            background: #fef9c3;
            border: 1px solid #fde68a;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 600;
            color: #92400e;
            white-space: nowrap;
        }

        .mc-stats {
            display: flex;
            gap: 18px;
            padding: 14px 20px;
            margin-top: 14px;
            border-top: 1px solid var(--border);
        }

        .mc-stat {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            color: var(--gray-500);
        }

        .mc-stat svg {
            width: 14px;
            height: 14px;
            color: var(--gray-400);
            flex-shrink: 0;
        }

        .mc-stat strong {
            color: var(--gray-700);
        }

        .mc-actions {
            display: flex;
            gap: 10px;
            padding: 0 20px 20px;
            margin-top: auto;
        }

        .mc-actions .btn {
            flex: 1;
            font-size: 12.5px;
        }

        .mc-grid.list-view .mc-card {
            flex-direction: row;
            align-items: center;
            flex-wrap: wrap;
        }

        .mc-grid.list-view .mc-top {
            flex: 1 1 260px;
            padding: 18px 20px;
        }

        .mc-grid.list-view .mc-tags,
        .mc-grid.list-view .mc-stats {
            flex: 1 1 200px;
            border-top: none;
            margin-top: 0;
            align-items: center;
        }

        .mc-grid.list-view .mc-actions {
            flex: 0 0 auto;
            padding: 18px 20px;
        }

        /* ── Request Mentorship modal ─────────────────────────────────── */
        .rq-box {
            background: var(--surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            width: 520px;
            max-width: 95vw;
            max-height: 90vh;
            overflow-y: auto;
            padding: 24px;
        }

        .rq-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }

        .rq-title {
            font-size: 19px;
            font-weight: 700;
            color: var(--forest);
            margin: 0;
        }

        .rq-sub {
            font-size: 13px;
            color: var(--gray-500);
            margin: 4px 0 0;
        }

        .rq-mentor {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--gray-50);
            margin-bottom: 18px;
        }

        .rq-avatar {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
            background: var(--mint-faint);
            color: var(--forest);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
        }

        .rq-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .rq-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 6px;
        }

        .rq-count {
            font-size: 11.5px;
            font-weight: 500;
            color: var(--gray-400);
        }

        .rq-textarea {
            width: 100%;
            font-size: 13px;
            font-family: inherit;
            padding: 11px 13px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            resize: vertical;
            min-height: 88px;
            margin-bottom: 16px;
        }

        .rq-textarea:focus {
            outline: none;
            border-color: var(--mint);
        }

        .rq-slots {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 190px;
            overflow-y: auto;
            margin-bottom: 8px;
        }

        .rq-slot {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            text-align: left;
            padding: 11px 13px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--surface);
            cursor: pointer;
            font-family: inherit;
        }

        .rq-slot:hover {
            border-color: var(--mint-soft);
            background: var(--mint-faint);
        }

        .rq-slot.selected {
            border-color: var(--mint);
            background: var(--mint-faint);
        }

        .rq-slot-when {
            font-size: 13px;
            font-weight: 700;
            color: var(--forest);
        }

        .rq-slot-meta {
            font-size: 11.5px;
            color: var(--gray-500);
            margin-top: 2px;
        }

        .rq-slot-type {
            margin-left: auto;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            border-radius: 999px;
            padding: 3px 9px;
            background: var(--info-bg);
            color: var(--info);
            flex-shrink: 0;
        }

        .rq-summary {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 14px;
        }

        .rq-summary>div {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
        }

        .rq-summary>div+div {
            border-left: 1px solid var(--border);
        }

        .rq-summary svg {
            width: 17px;
            height: 17px;
            color: var(--mint);
            flex-shrink: 0;
        }

        .rq-summary dt {
            font-size: 11px;
            color: var(--gray-400);
        }

        .rq-summary dd {
            margin: 2px 0 0;
            font-size: 13px;
            font-weight: 700;
            color: var(--forest);
        }

        .rq-tip {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--gray-500);
            margin-bottom: 18px;
        }

        .rq-tip svg {
            width: 15px;
            height: 15px;
            color: var(--mint);
            flex-shrink: 0;
        }

        .rq-actions {
            display: flex;
            gap: 10px;
        }

        .rq-actions .btn {
            flex: 1;
            justify-content: center;
        }

        .rq-error {
            background: var(--danger-bg);
            color: var(--danger);
            border-radius: var(--radius-sm);
            padding: 9px 12px;
            font-size: 12.5px;
            margin-bottom: 14px;
        }

        @media (max-width: 1180px) {
            .mc-grid {
                grid-template-columns: repeat(2, minmax(260px, 1fr));
            }
        }

        @media (max-width: 760px) {

            .mc-grid,
            .mc-grid.list-view {
                grid-template-columns: 1fr;
            }

            .mc-grid.list-view .mc-card {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php if ($is_logged_in): ?>
            <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <?php endif; ?>

        <main class="main fade-in">
            <?php if (!$is_logged_in): ?>
                <?php include __DIR__ . '/includes/guest_header.php'; ?>
            <?php endif; ?>


            <!-- Hero -->
            <div class="hero-banner">
                <div>
                    <h2>Find a Mentor</h2>
                    <p>Connect with experienced mentors who can help you learn, grow, and achieve your goals.</p>
                </div>
                <svg class="hero-illustration" viewBox="0 0 220 140" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle cx="60" cy="70" r="52" fill="var(--mint-faint)" />
                    <circle cx="168" cy="45" r="30" fill="var(--mint-soft)" opacity=".55" />
                    <rect x="24" y="76" width="66" height="48" rx="8" fill="var(--surface)" stroke="var(--border)" stroke-width="1.5" />
                    <circle cx="57" cy="96" r="11" fill="var(--forest)" opacity=".85" />
                    <path d="M40 123c2-9 8-14 17-14s15 5 17 14" stroke="var(--forest)" stroke-width="2" stroke-linecap="round" fill="none" opacity=".85" />
                    <rect x="118" y="56" width="72" height="54" rx="9" fill="var(--forest)" />
                    <circle cx="149" cy="77" r="11" fill="#fff" opacity=".9" />
                    <path d="M131 106c2-9 8-14 18-14s16 5 18 14" stroke="#fff" stroke-width="2" stroke-linecap="round" fill="none" opacity=".9" />
                    <circle cx="98" cy="60" r="15" fill="none" stroke="var(--mint)" stroke-width="3" />
                    <path d="M108 70l8 8" stroke="var(--mint)" stroke-width="3.2" stroke-linecap="round" />
                </svg>
            </div>

            <!-- Search / sort / view toolbar -->
            <form method="GET" id="fmForm">
                <div class="fm-toolbar">
                    <div class="fm-search">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="11" cy="11" r="7" />
                            <path stroke-linecap="round" d="m20 20-4-4" />
                        </svg>
                        <input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name, subject, or keyword…">
                    </div>
                    <button type="button" class="fm-filters-btn active" id="fmFiltersBtn" onclick="toggleFmFilters()" aria-expanded="true" aria-controls="fmFilterRow">
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" d="M4 7h5M15 7h5M4 17h9M19 17h1" />
                            <circle cx="12" cy="7" r="2" />
                            <circle cx="16" cy="17" r="2" />
                        </svg>
                        Filters
                    </button>
                    <label class="fm-sort">
                        Sort by:
                        <select name="sort" onchange="this.form.submit()">
                            <option value="relevant" <?= $sort === 'relevant' ? 'selected' : '' ?>>Most Relevant</option>
                            <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Highest Rated</option>
                            <option value="sessions" <?= $sort === 'sessions' ? 'selected' : '' ?>>Most Sessions</option>
                        </select>
                    </label>
                    <div class="fm-view-toggle" role="group" aria-label="Layout">
                        <button type="button" class="fm-view-btn active" data-view="grid" onclick="setMentorView('grid')" aria-label="Grid view">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <rect x="3" y="3" width="7" height="7" rx="1" />
                                <rect x="14" y="3" width="7" height="7" rx="1" />
                                <rect x="3" y="14" width="7" height="7" rx="1" />
                                <rect x="14" y="14" width="7" height="7" rx="1" />
                            </svg>
                        </button>
                        <button type="button" class="fm-view-btn" data-view="list" onclick="setMentorView('list')" aria-label="List view">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="fm-filter-row" id="fmFilterRow">
                    <select name="club" aria-label="Club">
                        <option value="">All Clubs</option>
                        <?php foreach (PC_CLUBS as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= $club === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="subject" aria-label="Subject">
                        <option value="">All Subjects</option>
                        <?php foreach ($subject_options as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $subject === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="type" aria-label="Mentoring type" onchange="this.form.submit()">
                        <option value="">Any Mentoring Type</option>
                        <option value="1v1" <?= $session_type === '1v1' ? 'selected' : '' ?>>1-on-1</option>
                        <option value="group" <?= $session_type === 'group' ? 'selected' : '' ?>>Group</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                    <?php if ($has_filters): ?>
                        <a href="<?= htmlspecialchars($find_mentor_url) ?>" class="fm-clear">Clear all</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($mentor_rows): ?>
                <div class="fm-count-row">
                    <span><?= $total_mentors ?> mentor<?= $total_mentors === 1 ? '' : 's' ?> found</span>
                </div>

                <div class="mc-grid" id="mentorGrid">
                    <?php foreach ($mentor_rows as $m):
                        $name = trim(($m['firstname'] ?? '') . ' ' . ($m['lastname'] ?? ''));
                        $ini  = strtoupper(substr($m['firstname'] ?? 'M', 0, 1));
                        $uid  = (int)$m['user_id'];

                        /*
                         * A count, not just a word. "This week" told a mentee
                         * nothing about whether there was one slot left or nine,
                         * which is the thing they are deciding on.
                         */
                        $slotsWeek = (int)($m['slots_week'] ?? 0);
                        $slotsOpen = (int)($m['slots_open'] ?? 0);

                        if ($slotsWeek > 0) {
                            $avail_label = $slotsWeek . ' slot' . ($slotsWeek === 1 ? '' : 's') . ' this week';
                        } elseif ($slotsOpen > 0) {
                            $avail_label = $slotsOpen . ' slot' . ($slotsOpen === 1 ? '' : 's')
                                . ' from ' . date('M j', strtotime($m['next_available']));
                        } else {
                            $avail_label = 'No open slots';
                        }
                    ?>
                        <div class="pcard mc-card">
                            <div class="mc-top">
                                <div class="mc-avatar">
                                    <?php if (!empty($m['profile_image'])): ?>
                                        <img src="<?= htmlspecialchars($m['profile_image']) ?>" alt="">
                                    <?php else: ?>
                                        <?= htmlspecialchars($ini) ?>
                                    <?php endif; ?>
                                    <?php if ((int)$m['verified'] === 1): ?>
                                        <span class="mc-verified" title="Verified mentor">
                                            <svg fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                                            </svg>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div style="min-width:0;flex:1;">
                                    <div class="mc-name-row">
                                        <span class="mc-name"><?= htmlspecialchars($name) ?></span>
                                        <span class="mc-role-pill">Mentor</span>
                                    </div>
                                    <div class="mc-title"><?= htmlspecialchars($m['expertise'] ?: 'General mentorship') ?></div>
                                    <div class="mc-rating">
                                        <?php if ((float)$m['avg_rating'] > 0): ?>
                                            &#9733; <?= number_format((float)$m['avg_rating'], 1) ?> <span class="n">(<?= (int)$m['total_reviews'] ?> reviews)</span>
                                        <?php else: ?>
                                            <span class="n">New mentor</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="mc-tags">
                                <?php
                                // Answers this mentor gave that match your own — the reason they
                                // rank where they do under "Most Relevant". Capped at three so a
                                // strong match doesn't stretch the card.
                                $matched = [];
                                foreach (($shared_tags[$uid] ?? []) as $group) {
                                    $matched = array_merge($matched, $group);
                                }
                                foreach (array_slice($matched, 0, 3) as $shared): ?>
                                    <span class="mc-tag mc-tag-match" title="Also one of your answers"><?= htmlspecialchars($shared) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($matched) > 3): ?>
                                    <span class="mc-tag mc-tag-match">+<?= count($matched) - 3 ?> more shared</span>
                                <?php endif; ?>
                                <?php if (!empty($m['expertise'])): ?><span class="mc-tag"><?= htmlspecialchars($m['expertise']) ?></span><?php endif; ?>
                                <?php if (!empty($m['club'])): ?><span class="mc-tag"><?= htmlspecialchars($m['club']) ?></span><?php endif; ?>
                                <?php if (!empty($m['course'])): ?><span class="mc-tag"><?= htmlspecialchars($m['course']) ?></span><?php endif; ?>
                            </div>

                            <?php if (!empty($mentor_badges[$uid])): ?>
                                <div class="mc-badges">
                                    <?php foreach ($mentor_badges[$uid] as $badge_name): ?>
                                        <span class="mc-badge" title="<?= htmlspecialchars($badge_name) ?>">🏅 <?= htmlspecialchars($badge_name) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="mc-stats">
                                <span class="mc-stat">
                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" />
                                    </svg>
                                    <strong><?= (int)$m['mentee_count'] ?></strong>&nbsp;mentees
                                </span>
                                <span class="mc-stat">
                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <rect x="5" y="4" width="14" height="16" rx="3" />
                                        <path stroke-linecap="round" d="M8 2v4M16 2v4M5 9h14" />
                                    </svg>
                                    Available <strong><?= htmlspecialchars($avail_label) ?></strong>
                                </span>
                            </div>

                            <div class="mc-actions">
                                <a href="<?= htmlspecialchars($view_mentor_url) ?>?id=<?= $uid ?>" class="btn btn-ghost">View Profile</a>
                                <button type="button" class="btn btn-primary"
                                    onclick="openRequestModal(this)"
                                    data-id="<?= $uid ?>"
                                    data-name="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                                    data-role="<?= htmlspecialchars($m['expertise'] ?: 'General mentorship', ENT_QUOTES) ?>"
                                    data-club="<?= htmlspecialchars($m['club'] ?? '', ENT_QUOTES) ?>"
                                    data-rating="<?= (float)$m['avg_rating'] > 0 ? number_format((float)$m['avg_rating'], 1) : '' ?>"
                                    data-reviews="<?= (int)$m['total_reviews'] ?>"
                                    data-photo="<?= htmlspecialchars($m['profile_image'] ?? '', ENT_QUOTES) ?>"
                                    data-initial="<?= htmlspecialchars($ini, ENT_QUOTES) ?>">
                                    Request Mentorship
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php
                // The search filters live in the query string; paging has to
                // carry them or page 2 comes back unfiltered.
                $page_qs = $_GET;
                unset($page_qs['page']);
                pc_pagination(
                    $page,
                    $total_pages,
                    fn(int $n) => '?' . http_build_query(array_merge($page_qs, ['page' => $n])),
                    ['label' => 'Mentor result pages']
                );
                ?>
            <?php else: ?>
                <div id="no-mentors-state" class="empty-state-lg" style="margin-top:44px;">
                    <div class="es-icon">
                        <svg viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none">
                            <circle cx="11" cy="11" r="7" />
                            <path stroke-linecap="round" d="m20 20-4-4" />
                        </svg>
                    </div>
                    <p class="es-title">No mentors found</p>
                    <p class="es-body">
                        <?php if ($has_filters): ?>
                            No mentors match your current filters. Try adjusting your search or clearing the filters.
                        <?php else: ?>
                            No verified mentors are available yet. Check back soon!
                        <?php endif; ?>
                    </p>
                    <?php if ($has_filters): ?>
                        <a href="<?= htmlspecialchars($find_mentor_url) ?>"
                            style="display:inline-flex;align-items:center;gap:6px;background:var(--primary);color:white;padding:10px 20px;border-radius:9px;text-decoration:none;font-size:13px;font-weight:600;margin-top:4px;">
                            Clear filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- ── Request Mentorship modal ────────────────────────────────────── -->
    <div id="requestModal" class="modal-overlay">
        <div class="rq-box">
            <div class="rq-head">
                <div>
                    <h2 class="rq-title">Request Mentorship</h2>
                    <p class="rq-sub">Send a mentorship request to <span id="rqMentorNameInline">this mentor</span></p>
                </div>
                <button type="button" onclick="closeRequestModal()" aria-label="Close"
                    style="width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="rq-mentor">
                <div class="rq-avatar" id="rqAvatar"></div>
                <div style="min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span id="rqMentorName" style="font-size:14.5px;font-weight:700;color:var(--gray-900);"></span>
                        <span class="mc-role-pill">Mentor</span>
                    </div>
                    <div id="rqMentorRole" style="font-size:12.5px;color:var(--gray-500);margin-top:2px;"></div>
                    <div id="rqMentorRating" style="font-size:12px;color:var(--warning);font-weight:600;margin-top:3px;"></div>
                </div>
            </div>

            <div id="rqError" class="rq-error" style="display:none;"></div>

            <div class="rq-label">
                <span>Add a personal message (optional)</span>
                <span class="rq-count"><span id="rqCount">0</span>/500</span>
            </div>
            <textarea id="rqMessage" class="rq-textarea" maxlength="500"
                placeholder="Introduce yourself and let this mentor know what you hope to learn from this mentorship…"></textarea>

            <div class="rq-label"><span>Choose a time <span style="color:var(--danger);">*</span></span></div>
            <div id="rqSlots" class="rq-slots">
                <p style="font-size:12.5px;color:var(--gray-400);margin:0;">Loading available times…</p>
            </div>

            <dl class="rq-summary">
                <div>
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" />
                    </svg>
                    <div>
                        <dt>Mentorship Type</dt>
                        <dd id="rqType">—</dd>
                    </div>
                </div>
                <div>
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 3" />
                    </svg>
                    <div>
                        <dt>Duration</dt>
                        <dd id="rqDuration">—</dd>
                    </div>
                </div>
            </dl>

            <div class="rq-tip">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 18h6M10 21h4M12 3a6 6 0 0 1 4 10.5V15H8v-1.5A6 6 0 0 1 12 3Z" />
                </svg>
                A thoughtful message increases your chances of getting accepted.
            </div>

            <div class="rq-actions">
                <button type="button" class="btn btn-ghost" onclick="closeRequestModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="rqSend" onclick="sendMentorshipRequest()">
                    Send Request
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <line x1="22" y1="2" x2="11" y2="13" />
                        <polygon points="22 2 15 22 11 13 2 9 22 2" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Success confirmation -->
    <div id="requestSentModal" class="modal-overlay">
        <div class="rq-box" style="width:380px;text-align:center;padding:34px 28px;">
            <div style="width:56px;height:56px;border-radius:50%;background:var(--success-bg);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <svg width="24" height="24" fill="none" stroke="var(--success)" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h2 style="font-size:18px;font-weight:700;color:var(--forest);margin:0 0 8px;">Request sent</h2>
            <p style="font-size:13px;color:var(--gray-500);margin:0 0 22px;">
                <span id="rqSentName">The mentor</span> will see it under their session requests. You can track it in Sessions.
            </p>
            <div style="display:flex;gap:10px;">
                <button type="button" class="btn btn-ghost" style="flex:1;justify-content:center;" onclick="document.getElementById('requestSentModal').classList.remove('open')">Keep browsing</button>
                <a href="<?= htmlspecialchars(url('mentee-sessions')) ?>" class="btn btn-primary" style="flex:1;justify-content:center;">View Sessions</a>
            </div>
        </div>
    </div>

    <script>
        // ── Grid/List view toggle — a per-viewer display preference. ──────
        function setMentorView(view) {
            const grid = document.getElementById('mentorGrid');
            if (!grid) return;
            grid.classList.toggle('list-view', view === 'list');
            document.querySelectorAll('.fm-view-btn').forEach(function(btn) {
                btn.classList.toggle('active', btn.dataset.view === view);
            });
            try {
                localStorage.setItem('mentorViewPref', view);
            } catch (e) {}
        }
        (function() {
            try {
                const saved = localStorage.getItem('mentorViewPref');
                if (saved === 'list') setMentorView('list');
            } catch (e) {}
        })();

        function toggleFmFilters() {
            const row = document.getElementById('fmFilterRow');
            const btn = document.getElementById('fmFiltersBtn');
            const open = row.style.display !== 'none';
            row.style.display = open ? 'none' : 'flex';
            btn.classList.toggle('active', !open);
            btn.setAttribute('aria-expanded', String(!open));
        }

        function toggleSidebar() {
            const sb = document.getElementById('sidebar');
            if (sb) sb.classList.toggle('collapsed');
        }

        function toggleProfileMenu() {
            const menu = document.getElementById('profileMenu');
            if (menu) menu.classList.toggle('open');
        }

        document.addEventListener('click', function(e) {
            const menu = document.getElementById('profileMenu');
            if (menu && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]')) {
                menu.classList.remove('open');
            }
        });

        // ── Search form: show skeleton while loading ──────────────────────
        (function() {
            const form = document.getElementById('fmForm');
            if (!form) return;
            form.addEventListener('submit', function() {
                const grid = document.querySelector('.mc-grid');
                if (grid && typeof pcShowSkeleton === 'function') {
                    pcShowSkeleton(grid, 9, 'mentor');
                }
            });
        })();

        /* ── Request Mentorship ───────────────────────────────────────────
           The time and duration offered here are the mentor's own published
           availability slots — the same ones the profile booking flow uses,
           and the same ones save_booking.php validates against on submit. */
        const RQ_CSRF = <?= json_encode(csrf_token()) ?>;
        const ROUTE_SLOTS = <?= json_encode(url('mentee-mentor-slots')) ?>;
        const ROUTE_BOOK = <?= json_encode(url('save-booking')) ?>;

        let rqMentor = null;
        let rqSlot = null;

        function openRequestModal(btn) {
            rqMentor = {
                ...btn.dataset
            };
            rqSlot = null;

            document.getElementById('rqMentorName').textContent = rqMentor.name;
            document.getElementById('rqMentorNameInline').textContent = rqMentor.name;
            document.getElementById('rqSentName').textContent = rqMentor.name;
            document.getElementById('rqMentorRole').textContent =
                rqMentor.club ? rqMentor.role + ' · ' + rqMentor.club : rqMentor.role;
            document.getElementById('rqMentorRating').textContent =
                rqMentor.rating ? '★ ' + rqMentor.rating + ' (' + rqMentor.reviews + ' reviews)' : 'New mentor';

            const av = document.getElementById('rqAvatar');
            av.innerHTML = '';
            if (rqMentor.photo) {
                const img = document.createElement('img');
                img.src = rqMentor.photo;
                img.alt = '';
                av.appendChild(img);
            } else {
                av.textContent = rqMentor.initial;
            }

            document.getElementById('rqMessage').value = '';
            document.getElementById('rqCount').textContent = '0';
            document.getElementById('rqType').textContent = '—';
            document.getElementById('rqDuration').textContent = '—';
            document.getElementById('rqError').style.display = 'none';
            document.getElementById('rqSend').disabled = false;
            document.getElementById('requestModal').classList.add('open');

            loadRequestSlots(rqMentor.id);
        }

        function closeRequestModal() {
            document.getElementById('requestModal').classList.remove('open');
        }

        document.getElementById('requestModal').addEventListener('click', function(e) {
            if (e.target === this) closeRequestModal();
        });

        document.getElementById('rqMessage').addEventListener('input', function() {
            document.getElementById('rqCount').textContent = this.value.length;
        });

        async function loadRequestSlots(mentorId) {
            const box = document.getElementById('rqSlots');
            box.innerHTML = '<p style="font-size:12.5px;color:var(--gray-400);margin:0;">Loading available times…</p>';
            try {
                const res = await fetch(ROUTE_SLOTS + '?mentor_id=' + encodeURIComponent(mentorId));
                const data = await res.json();
                const slots = data.slots || [];
                if (!slots.length) {
                    box.innerHTML = '<p style="font-size:12.5px;color:var(--gray-400);margin:0;">' +
                        'This mentor has no open slots right now. Try again once they publish new availability.</p>';
                    document.getElementById('rqSend').disabled = true;
                    return;
                }
                box.innerHTML = '';
                slots.forEach(function(slot, i) {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'rq-slot';

                    const left = document.createElement('div');
                    const when = document.createElement('div');
                    when.className = 'rq-slot-when';
                    when.textContent = slot.date_label + ' · ' + slot.time_label;
                    const meta = document.createElement('div');
                    meta.className = 'rq-slot-meta';
                    meta.textContent = slot.subject + ' · ' + slot.duration + ' min (ends ' + slot.end_label + ')' +
                        (slot.session_type === 'group' ? ' · ' + slot.seats_left + ' seats left' : '');
                    left.appendChild(when);
                    left.appendChild(meta);

                    const tag = document.createElement('span');
                    tag.className = 'rq-slot-type';
                    tag.textContent = slot.session_type === 'group' ? 'Group' : '1-on-1';

                    b.appendChild(left);
                    b.appendChild(tag);
                    b.addEventListener('click', function() {
                        selectRequestSlot(slot, b);
                    });
                    box.appendChild(b);
                    if (i === 0) selectRequestSlot(slot, b);
                });
            } catch (e) {
                box.innerHTML = '<p style="font-size:12.5px;color:var(--danger);margin:0;">Could not load available times.</p>';
                document.getElementById('rqSend').disabled = true;
            }
        }

        function selectRequestSlot(slot, el) {
            rqSlot = slot;
            document.querySelectorAll('.rq-slot').forEach(s => s.classList.remove('selected'));
            el.classList.add('selected');
            document.getElementById('rqType').textContent = slot.session_type === 'group' ? 'Group' : 'One-on-One';
            document.getElementById('rqDuration').textContent = slot.duration + ' minutes';
        }

        async function sendMentorshipRequest() {
            if (!rqMentor) return;
            const err = document.getElementById('rqError');
            if (!rqSlot) {
                err.textContent = 'Please choose a time for the session.';
                err.style.display = 'block';
                return;
            }
            const btn = document.getElementById('rqSend');
            btn.disabled = true;
            err.style.display = 'none';

            try {
                const res = await fetch(ROUTE_BOOK, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        csrf_token: RQ_CSRF,
                        mentor_id: rqMentor.id,
                        subject: rqSlot.subject,
                        session_type: rqSlot.session_type,
                        date: rqSlot.date,
                        time: rqSlot.time,
                        message: document.getElementById('rqMessage').value.trim()
                    })
                });
                const data = await res.json();
                if (data.success) {
                    closeRequestModal();
                    document.getElementById('requestSentModal').classList.add('open');
                } else {
                    err.textContent = data.error || 'Could not send the request. Please try again.';
                    err.style.display = 'block';
                    btn.disabled = false;
                }
            } catch (e) {
                err.textContent = 'Network error. Please try again.';
                err.style.display = 'block';
                btn.disabled = false;
            }
        }
    </script>
</body>

</html>