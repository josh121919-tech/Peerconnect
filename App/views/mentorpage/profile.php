<?php
date_default_timezone_set('Asia/Manila');
session_start();
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/MentorScoreService.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mentor') {
    header("Location: " . url('welcomepage'));
    exit;
}


$user_id = (int)$_SESSION['user_id'];
$role    = $_SESSION['role'] ?? '';

// Mentors can skip the first-login questionnaire too. This page is where the
// offer to finish it lives, since skipping stops the login redirect for good.
require_once __DIR__ . '/../includes/onboarding_gate.php';
$onboarding = pc_onboarding_state($con, $user_id);

$verification = VerificationRepository::approvedDetails($con, $user_id);

$profile = ProfileRepository::fields($con, $user_id,
    ['full_name', 'student_id', 'course', 'year_level', 'club', 'profile_image', 'location', 'bio', 'visibility']);

if ($profile) {
    foreach (['full_name', 'student_id', 'course', 'year_level', 'club'] as $field) {
        if (!empty($profile[$field])) $verification[$field] = $profile[$field];
    }
}
$profile_image = $profile['profile_image'] ?? null;

/*
 * The profile header. `location`, `bio` and `visibility` are edited on the
 * Settings > Account tab (the Edit Profile modal here only covers the
 * verification fields), so every "add this" prompt below points there rather
 * than at a control that cannot set it.
 */
$location   = trim((string)($profile['location'] ?? ''));
$bio        = trim((string)($profile['bio'] ?? ''));
$visibility = $profile['visibility'] ?? 'everyone';
/*
 * Worded from what the setting actually does, not from the option names:
 * find_mentor.php excludes only 'private' from the mentor directory, while
 * home.view.php's landing-page faces require 'everyone'. So 'mentors' still
 * appears in search — it just isn't featured on the public homepage.
 */
$visibility_copy = [
    'everyone' => ['Public',      'Listed in mentor search, and can be featured on the public homepage.'],
    'mentors'  => ['Limited',     'Listed in mentor search, but never shown on the public homepage.'],
    'private'  => ['Private',     'Hidden from mentor search and from the public homepage.'],
];
[$visibility_label, $visibility_note] = $visibility_copy[$visibility] ?? $visibility_copy['everyone'];

$joined_at = UserRepository::joinedAt($con, $user_id);

/*
 * What this mentor can teach. `skill` and `learn` are the two tag types the
 * onboarding questionnaire writes for a mentor — the skills they can help
 * with and the subject areas they can mentor in. `interest` is their own
 * degree programme, which is shown in the header line instead.
 */
$expertise = [];
$programme = null;
foreach (ProfileRepository::tagsByType($con, $user_id) as $t) {
    if ($t['tag_type'] === 'interest') {
        $programme = $programme ?? $t['tag'];
    } else {
        $expertise[] = $t['tag'];
    }
}

/*
 * Header stats. Each is a real count with a real month-over-month change;
 * a trend line is only rendered when there is something to compare, so an
 * account with one month of history shows the figure and nothing else.
 */
$stat_mentees = (int)(SessionRepository::statsForMentor($con, $user_id)['mentees'] ?? 0);
// "New this month" = a mentee whose first session with this mentor started
// this month; anyone earlier is not new, however recently they last met.
$stat_mentees_new = SessionRepository::countNewMenteesThisMonthForMentor($con, $user_id);

$stat_sessions     = SessionRepository::countForMentorInStatuses($con, $user_id, ['completed']);
$stat_sessions_new = SessionRepository::countCompletedThisMonthForMentor($con, $user_id);

// Counted here rather than from $my_badges, which is fetched further down.
$stat_badges     = AchievementRepository::countActiveBadges($con, $user_id);
$stat_badges_new = AchievementRepository::countActiveBadges($con, $user_id, true);

// Open slots decide the "available for mentorship" line: a mentor with no
// future availability is not bookable, whatever the profile says.
$open_slots = AvailabilityRepository::countFromTodayForMentor($con, $user_id);

// Matching/ranking score — this already drives how mentees see this mentor
// ranked in Find a Mentor (MentorScoreService::getRankedMentors), but was
// never actually shown to the mentor themselves anywhere. Compute on the
// fly if it hasn't been calculated yet, so this is never stale/empty.
$mentor_score = MentorScoreRepository::forMentor($con, $user_id);
if (!$mentor_score) {
    MentorScoreService::compute($con, $user_id);
    $mentor_score = MentorScoreRepository::forMentor($con, $user_id);
}

$avg = FeedbackRepository::averagesForMentor($con, $user_id);

/*
 * Rating movement, month over month. Same rule as the Feedback page: it needs
 * a review in each of the two months to mean anything, so a mentor with only
 * one month of reviews sees the figure and no arrow.
 */
$trend_row = FeedbackRepository::monthTrendForMentor($con, $user_id);
$rating_delta = null;
if ($trend_row && $trend_row['this_avg'] !== null && $trend_row['prev_avg'] !== null) {
    $d = (float)$trend_row['this_avg'] - (float)$trend_row['prev_avg'];
    if (abs($d) >= 0.05) $rating_delta = $d;
}

// created_at and the session subject feed the Recent Reviews rows.
$reviews = FeedbackRepository::reviewsWithSubjectForMentor($con, $user_id);

// Badges earned
$my_badges = AchievementRepository::activeBadgesFor($con, $user_id);

// Certificates earned
$my_certs = AchievementRepository::certificatesFor($con, $user_id);

$active_page = 'profile';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile — PeerConnect Mentor</title>
    <?php include __DIR__ . '/includes/style.php'; ?>
    <style>
        /* .profile-grid comes from the shared design system (design_system.php),
           which already collapses it to 1 column on mobile/tablet — redefining
           it here without a media query would silently override that and
           break the responsive collapse, so it's intentionally not redeclared. */

        /* ── Card base override ── */
        .card {
            border-radius: 14px !important;
            border: 1px solid var(--gray-100);
            box-shadow: 0 1px 4px rgba(0, 0, 0, .06);
        }

        /* ── Hero ── */
        .pf-hero {
            display: flex;
            align-items: flex-start;
            gap: 24px;
            flex-wrap: wrap;
            padding: 24px;
            margin-bottom: 20px;
        }

        .pf-avatar-wrap {
            position: relative;
            flex: 0 0 auto;
        }

        .pf-avatar,
        .pf-avatar-ph {
            width: 104px;
            height: 104px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--surface);
            box-shadow: 0 2px 12px rgba(0, 0, 0, .12);
        }

        .pf-avatar-ph {
            display: grid;
            place-items: center;
            background: var(--mint-faint);
            color: var(--mint-deep);
            font-size: 36px;
            font-weight: 700;
        }

        /* Green when the mentor has future slots, grey when they have none —
           the same signal the meta row spells out in words. */
        .pf-dot {
            position: absolute;
            right: 6px;
            bottom: 6px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 3px solid var(--surface);
        }

        .pf-hero-body {
            flex: 1 1 300px;
            min-width: 0;
        }

        .pf-name {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin: 0;
            font-family: 'Poppins', sans-serif;
            font-size: 25px;
            font-weight: 700;
            color: var(--forest);
            line-height: 1.2;
        }

        .pf-role {
            padding: 3px 11px;
            border-radius: 999px;
            background: var(--mint-faint);
            color: var(--mint-deep);
            font-size: 11.5px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .pf-headline {
            margin: 8px 0 0;
            font-size: 14px;
            font-weight: 500;
            color: var(--gray-700);
        }

        .pf-quote {
            margin: 8px 0 0;
            font-size: 13.5px;
            font-style: italic;
            color: var(--gray-500);
            line-height: 1.55;
        }

        .pf-meta {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 14px;
            font-size: 13px;
            color: var(--gray-600);
        }

        .pf-meta span {
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .pf-meta svg {
            width: 15px;
            height: 15px;
            color: var(--gray-400);
        }

        .pf-meta a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
        }

        .pf-meta a:hover {
            text-decoration: underline;
        }

        .pf-live {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex: 0 0 8px;
        }

        .pf-hero-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 0 0 auto;
            margin-left: auto;
        }

        .pf-hero-actions .btn {
            justify-content: center;
            white-space: nowrap;
        }

        /* ── Stat cards ── */
        .pf-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .pf-stat {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 18px;
        }

        .pf-stat-ico {
            flex: 0 0 46px;
            width: 46px;
            height: 46px;
            border-radius: 14px;
            display: grid;
            place-items: center;
        }

        .pf-stat-v {
            font-family: 'Poppins', sans-serif;
            font-size: 26px;
            font-weight: 700;
            line-height: 1.15;
            color: var(--gray-900);
        }

        .pf-stat-k {
            margin-top: 2px;
            font-size: 12.5px;
            color: var(--gray-500);
        }

        .pf-stat-sub {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 7px;
            font-size: 12px;
            color: var(--gray-400);
        }

        .pf-stat-sub.is-up {
            color: var(--success);
            font-weight: 600;
        }

        .pf-stat-sub.is-down {
            color: var(--danger);
            font-weight: 600;
        }

        .pf-stat-sub svg {
            width: 13px;
            height: 13px;
        }

        .pf-stat-sub.is-down svg {
            transform: rotate(180deg);
        }

        /* ── Generic card head ── */
        .pf-ct {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 0 0 14px;
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            font-weight: 600;
            color: var(--forest);
        }

        .pf-ct svg {
            width: 17px;
            height: 17px;
            color: var(--gray-400);
            flex: 0 0 17px;
        }

        .pf-ct .pf-ct-link {
            margin-left: auto;
            font-family: inherit;
            font-size: 12.5px;
            font-weight: 500;
            color: var(--accent);
            text-decoration: none;
        }

        .pf-ct .pf-ct-link:hover {
            text-decoration: underline;
        }

        .pf-body {
            margin: 0;
            font-size: 13.5px;
            line-height: 1.65;
            color: var(--gray-600);
        }

        .pf-muted {
            margin: 0;
            font-size: 13px;
            line-height: 1.6;
            color: var(--gray-400);
        }

        .pf-muted a {
            color: var(--accent);
            font-weight: 500;
        }

        /* ── Three-up row ── */
        .pf-trio {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
            align-items: start;
        }

        .pf-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .pf-chip {
            padding: 6px 12px;
            border-radius: 999px;
            background: var(--gray-50);
            border: 1px solid var(--gray-100);
            font-size: 12.5px;
            font-weight: 500;
            color: var(--gray-700);
        }

        .pf-vis {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--gray-800);
        }

        /* ── Mentoring information strip ── */
        .pf-facts {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 2px;
        }

        .pf-fact {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 6px 16px 6px 0;
        }

        .pf-fact+.pf-fact {
            border-left: 1px solid var(--gray-100);
            padding-left: 16px;
        }

        .pf-fact-ico {
            flex: 0 0 34px;
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: grid;
            place-items: center;
        }

        .pf-fact-v {
            font-size: 19px;
            font-weight: 700;
            color: var(--gray-900);
            line-height: 1.2;
        }

        .pf-fact-v.is-text {
            font-size: 13.5px;
            font-weight: 600;
        }

        .pf-fact-k {
            font-size: 11.5px;
            color: var(--gray-400);
            margin-top: 1px;
        }

        /* ── Two-column body ── */
        .pf-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 330px);
            gap: 20px;
            align-items: start;
        }

        .pf-col {
            display: flex;
            flex-direction: column;
            gap: 20px;
            min-width: 0;
        }

        /* ── Badge tiles ── */
        .pf-badges {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 12px;
        }

        .pf-badge {
            padding: 14px 10px;
            border: 1px solid var(--gray-100);
            border-radius: 12px;
            text-align: center;
            background: var(--surface);
        }

        .pf-badge-ico {
            width: 46px;
            height: 46px;
            margin: 0 auto 9px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--gold-light);
            color: var(--warning);
        }

        .pf-badge-n {
            margin: 0;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--gray-800);
            line-height: 1.35;
        }

        .pf-badge-d {
            margin: 3px 0 0;
            font-size: 11px;
            color: var(--gray-400);
        }

        /* ── Info rows ── */
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 11px 0;
            border-bottom: 1px solid var(--gray-100);
            font-size: 13px;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: var(--gray-400);
            flex-shrink: 0;
        }

        .info-value {
            color: var(--gray-900);
            font-weight: 600;
            text-align: right;
        }

        /* ── Rating bar ── */
        .rating-bar-wrap {
            margin-bottom: 14px;
        }

        .rating-bar-wrap:last-child {
            margin-bottom: 0;
        }

        .rating-bar-track {
            height: 7px;
            background: var(--gray-100);
            border-radius: 999px;
            overflow: hidden;
            margin-top: 7px;
        }

        .rating-bar-fill {
            height: 100%;
            border-radius: 999px;
            background: var(--accent);
        }

        .stars-on {
            color: var(--gold);
            letter-spacing: 1px;
        }

        .stars-off {
            color: var(--gray-200);
            letter-spacing: 1px;
        }

        .tag-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 11px;
            border-radius: 999px;
            background: var(--mint-faint);
            color: var(--mint-deep);
            font-size: 12px;
            font-weight: 600;
        }

        /* ── Reviews ── */
        .pf-rev {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 16px 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .pf-rev:first-child {
            padding-top: 0;
        }

        .pf-rev:last-child {
            padding-bottom: 0;
            border-bottom: none;
        }

        .pf-rev-av {
            flex: 0 0 42px;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--info-bg);
            color: var(--info);
            font-size: 13px;
            font-weight: 700;
        }

        .pf-rev-b {
            flex: 1 1 200px;
            min-width: 0;
        }

        .pf-rev-n {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-900);
        }

        .pf-rev-s {
            margin: 2px 0 0;
            font-size: 12px;
            color: var(--gray-400);
        }

        .pf-rev-c {
            margin: 8px 0 0;
            font-size: 13.5px;
            line-height: 1.6;
            color: var(--gray-600);
            overflow-wrap: anywhere;
        }

        .pf-rev-r {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            flex-shrink: 0;
        }

        .pf-rev-d {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--gray-400);
            flex-shrink: 0;
        }

        .pf-rev-d svg {
            width: 14px;
            height: 14px;
        }

        .pf-empty {
            padding: 34px 20px;
            text-align: center;
            color: var(--gray-400);
        }

        .pf-empty svg {
            width: 30px;
            height: 30px;
            margin-bottom: 9px;
            color: var(--gray-300);
        }

        .pf-empty p {
            margin: 0;
            font-size: 13px;
        }

        /* ── Modal ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(2, 5, 71, .45);
            z-index: 900;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: var(--surface);
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
            max-height: 92vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--gray-100);
        }

        .modal-body {
            padding: 20px 22px;
        }

        .modal-footer {
            padding: 16px 22px;
            border-top: 1px solid var(--gray-100);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .avatar-upload-ring {
            border: 3px dashed var(--gray-200);
            transition: border-color .15s;
        }

        .avatar-upload-ring:hover {
            border-color: var(--accent);
        }

        /* ── Responsive ── */
        @media (max-width: 1080px) {
            .pf-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 860px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }

            .pf-fact+.pf-fact {
                border-left: none;
                padding-left: 0;
            }
        }

        @media (max-width: 560px) {
            /* Same reasoning as the Feedback page's stat row: one card per
               line below this width buries the figures under a lot of
               scrolling, so the four go 2x2 with the icon stacked above. */
            .pf-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .pf-stat {
                flex-direction: column;
                gap: 10px;
                padding: 14px;
            }

            .pf-stat-ico {
                flex: 0 0 38px;
                width: 38px;
                height: 38px;
                border-radius: 11px;
            }

            .pf-stat-ico svg {
                width: 19px;
                height: 19px;
            }

            .pf-stat-v {
                font-size: 22px;
            }

            .pf-hero {
                gap: 16px;
            }

            .pf-hero-actions {
                width: 100%;
                margin-left: 0;
            }

            .pf-name {
                font-size: 21px;
            }

            .pf-avatar,
            .pf-avatar-ph {
                width: 84px;
                height: 84px;
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main fade-in">
            <?php if (!$onboarding['done']): ?>
                <!-- Unanswered questionnaire: mentees are matched to mentors on
                     these answers, so a mentor who skipped is invisible to that
                     ranking until they finish it. -->
                <div class="pcard" style="margin-bottom:20px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:16px 18px;">
                    <div style="flex:1 1 260px;min-width:0;">
                        <div style="font-size:14px;font-weight:700;color:var(--gray-900);">Finish your matching questionnaire</div>
                        <div style="font-size:12.5px;color:var(--gray-500);margin-top:3px;line-height:1.5;">
                            Tell us the subjects you can mentor and the skills you can help with, so mentees looking for exactly that find you.
                        </div>
                    </div>
                    <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars(url('onboarding')) ?>">Answer now →</a>
                </div>
            <?php endif; ?>

            <?php
            // Small inline glyphs, local to this page.
            $pf_icon = function (string $n, string $a = 'width="16" height="16"'): string {
                $p = [
                    'pin'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-5.7 7-11a7 7 0 1 0-14 0c0 5.3 7 11 7 11Z"/><circle cx="12" cy="10" r="2.6"/>',
                    'cal'      => '<rect x="4" y="5" width="16" height="16" rx="3"/><path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16"/>',
                    'users'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 19c0-1.8-1.2-3.3-2.8-3.8M17 4.4a3 3 0 0 1 0 5.2"/>',
                    'star'     => '<path d="m12 3.6 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9L12 3.6Z"/>',
                    'badge'    => '<circle cx="12" cy="9" r="5.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 13.5-1 7 4.5-2.4 4.5 2.4-1-7"/>',
                    'check'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.5 9 17l10.5-10"/>',
                    'clock'    => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7.5V12l3 2"/>',
                    'globe'    => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M3.2 9h17.6M3.2 15h17.6"/><path stroke-linecap="round" d="M12 3c2.5 2.4 3.8 5.4 3.8 9s-1.3 6.6-3.8 9c-2.5-2.4-3.8-5.4-3.8-9S9.5 5.4 12 3Z"/>',
                    'person'   => '<circle cx="12" cy="8" r="3.6"/><path stroke-linecap="round" d="M5 20c.7-3.5 3.5-5.4 7-5.4s6.3 1.9 7 5.4"/>',
                    'tag'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12.5V5a2 2 0 0 1 2-2h7.5L21 11.5 12.5 20 3 12.5Z"/><circle cx="8" cy="8" r="1.3"/>',
                    'info'     => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 11v5M12 8v.01"/>',
                    'chart'    => '<path stroke-linecap="round" d="M5 20V11M12 20V4M19 20v-6"/>',
                    'chat'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M20 12a7 7 0 0 1-7 7H8.5L5 21.5V18A7 7 0 0 1 12 5h1a7 7 0 0 1 7 7Z"/>',
                    'cert'     => '<rect x="4" y="4" width="16" height="12" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 20h6M12 16v4"/>',
                    'download' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14"/>',
                    'edit'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.2 5.2l3.6 3.6M16.8 3.6a2.5 2.5 0 1 1 3.6 3.6L6.5 21H3v-3.5L16.8 3.6Z"/>',
                    'external' => '<path stroke-linecap="round" stroke-linejoin="round" d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
                    'up'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5m0 0-6 6m6-6 6 6"/>',
                ];
                if (!isset($p[$n])) return '';
                return '<svg ' . $a . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">' . $p[$n] . '</svg>';
            };

            $settings_url  = url('mentor-settings') . '?tab=account';
            // $public_url was here, feeding a "View Public Profile" button in
            // the banner. That button was removed from the live site by hand
            // and nothing else reads the value, so keeping it would leave a
            // URL built on every page load for no one. Mentees still reach
            // this profile through mentee-view-mentor from the directory.
            $feedback_url  = url('mentor-feedback');
            $calendar_url  = url('mentor-calendar');
            $avg_rating    = (float)($avg['avg_rating'] ?? 0);
            $review_count  = (int)($avg['total_reviews'] ?? 0);

            // The header line under the name: what they teach and where they
            // study. Only the parts that exist are joined, so a half-filled
            // profile never renders stray separators.
            // The verified course wins over the questionnaire's programme tag:
            // the tag drives matching, but the verification record is what the
            // university actually confirmed.
            $headline_bits = array_values(array_filter([
                ($verification['course'] ?? '') ?: $programme,
                $verification['year_level'] ?? '',
                $verification['club'] ?? '',
            ], fn($v) => trim((string)$v) !== ''));
            ?>

            
            <?php if ($verification): ?>

                <!-- ══════════ HERO ══════════ -->
                <div class="card pf-hero">
                    <div class="pf-avatar-wrap">
                        <?php if ($profile_image): ?>
                            <img src="<?= htmlspecialchars($profile_image) ?>" alt="" class="pf-avatar">
                        <?php else: ?>
                            <div class="pf-avatar-ph"><?= htmlspecialchars(strtoupper(substr($verification['full_name'], 0, 1))) ?></div>
                        <?php endif; ?>
                        <span class="pf-dot" style="background:<?= $open_slots > 0 ? 'var(--success)' : 'var(--gray-300)' ?>;"
                            title="<?= $open_slots > 0 ? 'Open slots available' : 'No open slots' ?>"></span>
                    </div>

                    <div class="pf-hero-body">
                        <h2 class="pf-name">
                            <?= htmlspecialchars($verification['full_name']) ?>
                            <span class="pf-role"><?= htmlspecialchars($role) ?></span>
                        </h2>

                        <?php if ($headline_bits): ?>
                            <p class="pf-headline"><?= htmlspecialchars(implode(' · ', $headline_bits)) ?></p>
                        <?php endif; ?>

                        <?php if ($bio !== ''): ?>
                            <p class="pf-quote">&ldquo;<?= htmlspecialchars($bio) ?>&rdquo;</p>
                        <?php endif; ?>

                        <div class="pf-meta">
                            <span>
                                <span class="pf-live" style="background:<?= $open_slots > 0 ? 'var(--success)' : 'var(--gray-300)' ?>;"></span>
                                <?php if ($open_slots > 0): ?>
                                    Available for mentorship &middot; <?= $open_slots ?> open slot<?= $open_slots === 1 ? '' : 's' ?>
                                <?php else: ?>
                                    No open slots &mdash; <a href="<?= htmlspecialchars($calendar_url) ?>">add availability</a>
                                <?php endif; ?>
                            </span>
                            <span>
                                <?= $pf_icon('pin') ?>
                                <?php if ($location !== ''): ?>
                                    <?= htmlspecialchars($location) ?>
                                <?php else: ?>
                                    <a href="<?= htmlspecialchars($settings_url) ?>">Add your location</a>
                                <?php endif; ?>
                            </span>
                            <?php if ($joined_at): ?>
                                <span><?= $pf_icon('cal') ?>Joined <?= date('F Y', strtotime($joined_at)) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pf-hero-actions">
                        <button type="button" class="btn btn-primary btn-sm" onclick="openEditModal()">
                            <?= $pf_icon('edit', 'width="14" height="14"') ?> Edit Profile
                        </button>
                        <?php // Account settings, matching the mentee's profile. This is where
                              // "View Public Profile" was; it was taken off the live site by
                              // hand, so removing it here as well stops the next upload from
                              // putting it back. Visibility is still managed from the Public
                              // Profile card further down. ?>
                        <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($settings_url) ?>">Account settings</a>
                    </div>
                </div>

                <!-- ══════════ STATS ══════════ -->
                <div class="pf-stats">
                    <div class="card pf-stat">
                        <div class="pf-stat-ico" style="background:var(--mint-faint);color:var(--mint-deep);"><?= $pf_icon('users', 'width="21" height="21"') ?></div>
                        <div>
                            <div class="pf-stat-v"><?= $stat_mentees ?></div>
                            <div class="pf-stat-k">Mentee<?= $stat_mentees === 1 ? '' : 's' ?></div>
                            <?php if ($stat_mentees_new > 0): ?>
                                <span class="pf-stat-sub is-up"><?= $pf_icon('up') ?><?= $stat_mentees_new ?> new this month</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card pf-stat">
                        <div class="pf-stat-ico" style="background:var(--purple-bg);color:var(--purple);"><?= $pf_icon('cal', 'width="21" height="21"') ?></div>
                        <div>
                            <div class="pf-stat-v"><?= $stat_sessions ?></div>
                            <div class="pf-stat-k">Session<?= $stat_sessions === 1 ? '' : 's' ?> completed</div>
                            <?php if ($stat_sessions_new > 0): ?>
                                <span class="pf-stat-sub is-up"><?= $pf_icon('up') ?><?= $stat_sessions_new ?> this month</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card pf-stat">
                        <div class="pf-stat-ico" style="background:var(--gold-light);color:var(--gold);"><?= $pf_icon('star', 'width="21" height="21"') ?></div>
                        <div>
                            <div class="pf-stat-v"><?= $review_count ? number_format($avg_rating, 1) : '—' ?></div>
                            <div class="pf-stat-k">Rating</div>
                            <?php if ($rating_delta !== null): ?>
                                <span class="pf-stat-sub <?= $rating_delta > 0 ? 'is-up' : 'is-down' ?>">
                                    <?= $pf_icon('up') ?><?= ($rating_delta > 0 ? '+' : '−') . number_format(abs($rating_delta), 1) ?> from last month
                                </span>
                            <?php elseif ($review_count > 0): ?>
                                <span class="pf-stat-sub">From <?= $review_count ?> review<?= $review_count === 1 ? '' : 's' ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card pf-stat">
                        <div class="pf-stat-ico" style="background:var(--info-bg);color:var(--info);"><?= $pf_icon('badge', 'width="21" height="21"') ?></div>
                        <div>
                            <div class="pf-stat-v"><?= $stat_badges ?></div>
                            <div class="pf-stat-k">Badge<?= $stat_badges === 1 ? '' : 's' ?></div>
                            <?php if ($stat_badges_new > 0): ?>
                                <span class="pf-stat-sub is-up"><?= $pf_icon('up') ?><?= $stat_badges_new ?> new this month</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ══════════ ABOUT / EXPERTISE / VISIBILITY ══════════ -->
                <div class="pf-trio">
                    <div class="card card-p">
                        <h3 class="pf-ct"><?= $pf_icon('person') ?>About Me</h3>
                        <?php if ($bio !== ''): ?>
                            <p class="pf-body"><?= nl2br(htmlspecialchars($bio)) ?></p>
                        <?php else: ?>
                            <p class="pf-muted">
                                No bio yet. <a href="<?= htmlspecialchars($settings_url) ?>">Write a short intro</a>
                                so mentees know what you can help with.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="card card-p">
                        <h3 class="pf-ct"><?= $pf_icon('tag') ?>Expertise</h3>
                        <?php if ($expertise): ?>
                            <div class="pf-chips">
                                <?php foreach ($expertise as $tag): ?>
                                    <span class="pf-chip"><?= htmlspecialchars($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="pf-muted">
                                Nothing selected yet. Your answers to the
                                <a href="<?= htmlspecialchars(url('onboarding')) ?>">matching questionnaire</a>
                                appear here and decide which searches you show up in.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="card card-p">
                        <h3 class="pf-ct"><?= $pf_icon('globe') ?>Public Profile</h3>
                        <div class="pf-vis">
                            <span class="pf-live" style="background:<?= $visibility === 'private' ? 'var(--gray-300)' : 'var(--success)' ?>;"></span>
                            <?= htmlspecialchars($visibility_label) ?>
                        </div>
                        <p class="pf-muted" style="margin-bottom:14px;"><?= htmlspecialchars($visibility_note) ?></p>
                        <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars($settings_url) ?>">Manage visibility</a>
                    </div>
                </div>

                <!-- ══════════ MENTORING INFORMATION ══════════ -->
                <div class="card card-p" style="margin-bottom:20px;">
                    <h3 class="pf-ct"><?= $pf_icon('info') ?>Mentoring Information</h3>
                    <div class="pf-facts">
                        <div class="pf-fact">
                            <span class="pf-fact-ico" style="background:var(--mint-faint);color:var(--mint-deep);"><?= $pf_icon('users', 'width="17" height="17"') ?></span>
                            <div>
                                <div class="pf-fact-v"><?= $stat_mentees ?></div>
                                <div class="pf-fact-k">Mentees helped</div>
                            </div>
                        </div>
                        <div class="pf-fact">
                            <span class="pf-fact-ico" style="background:var(--success-bg);color:var(--success);"><?= $pf_icon('check', 'width="17" height="17"') ?></span>
                            <div>
                                <div class="pf-fact-v"><?= $stat_sessions ?></div>
                                <div class="pf-fact-k">Sessions completed</div>
                            </div>
                        </div>
                        <div class="pf-fact">
                            <span class="pf-fact-ico" style="background:var(--gold-light);color:var(--gold);"><?= $pf_icon('star', 'width="17" height="17"') ?></span>
                            <div>
                                <div class="pf-fact-v"><?= $review_count ? number_format($avg_rating, 1) : '—' ?></div>
                                <div class="pf-fact-k">Average rating</div>
                            </div>
                        </div>
                        <div class="pf-fact">
                            <span class="pf-fact-ico" style="background:var(--info-bg);color:var(--info);"><?= $pf_icon('badge', 'width="17" height="17"') ?></span>
                            <div>
                                <div class="pf-fact-v"><?= $stat_badges ?></div>
                                <div class="pf-fact-k">Badges earned</div>
                            </div>
                        </div>
                        <div class="pf-fact">
                            <span class="pf-fact-ico" style="background:<?= $open_slots > 0 ? 'var(--success-bg)' : 'var(--gray-50)' ?>;color:<?= $open_slots > 0 ? 'var(--success)' : 'var(--gray-400)' ?>;"><?= $pf_icon('clock', 'width="17" height="17"') ?></span>
                            <div>
                                <div class="pf-fact-v is-text"><?= $open_slots > 0 ? 'Available' : 'No open slots' ?></div>
                                <div class="pf-fact-k">For mentorship</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════ MAIN GRID ══════════ -->
                <div class="pf-grid">
                    <div class="pf-col">

                        <!-- Matching score -->
                        <div class="card card-p">
                            <h3 class="pf-ct">
                                <?= $pf_icon('chart') ?>Your Matching Score
                                <span style="margin-left:auto;font-size:24px;font-weight:800;color:var(--forest);"><?= number_format((float)($mentor_score['recommendation_score'] ?? 0), 0) ?></span>
                            </h3>
                            <p class="pf-muted" style="margin-bottom:16px;">This is what ranks you in mentees' search results — it updates automatically as you complete sessions and receive feedback.</p>
                            <?php
                            $score_bars = [
                                'Rating'          => min(100, ((float)($mentor_score['avg_rating'] ?? 0) / 5) * 100),
                                'Completion rate' => (float)($mentor_score['completion_rate'] ?? 0),
                                'Effectiveness'   => (float)($mentor_score['effectiveness_score'] ?? 0),
                            ];
                            foreach ($score_bars as $label => $val):
                                $pct = max(0, min(100, $val));
                            ?>
                                <div class="rating-bar-wrap">
                                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;">
                                        <span style="color:var(--gray-600);"><?= $label ?></span>
                                        <span style="font-weight:700;color:var(--gray-900);"><?= number_format($pct, 0) ?>%</span>
                                    </div>
                                    <div class="rating-bar-track">
                                        <div class="rating-bar-fill" style="width:<?= $pct ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;font-size:11.5px;color:var(--gray-400);margin-top:14px;padding-top:12px;border-top:1px solid var(--gray-100);">
                                <span><?= (int)($mentor_score['total_sessions'] ?? 0) ?> sessions counted</span>
                                <span>Updated <?= !empty($mentor_score['last_calculated']) ? date('M j, g:i A', strtotime($mentor_score['last_calculated'])) : 'just now' ?></span>
                            </div>
                        </div>

                        <!-- Review breakdown -->
                        <div class="card card-p">
                            <h3 class="pf-ct">
                                <?= $pf_icon('star') ?>Review Breakdown
                                <a class="pf-ct-link" href="<?= htmlspecialchars($feedback_url) ?>">View all reviews</a>
                            </h3>
                            <?php if ($review_count > 0): ?>
                                <?php
                                $bars = [
                                    'Communication'            => $avg['avg_comm']  ?? 0,
                                    'Interaction & Engagement' => $avg['avg_eff']   ?? 0,
                                    'Knowledge & Expertise'    => $avg['avg_know']  ?? 0,
                                    'Guidance & Support'       => $avg['avg_skill'] ?? 0,
                                ];
                                foreach ($bars as $label => $val):
                                    $pct = max(0, min(100, ((float)$val) * 20));
                                ?>
                                    <div class="rating-bar-wrap">
                                        <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;">
                                            <span style="color:var(--gray-600);"><?= $label ?></span>
                                            <span style="font-weight:700;color:var(--gray-900);"><?= number_format((float)$val, 1) ?><span style="font-weight:400;color:var(--gray-300);font-size:11px;"> / 5</span></span>
                                        </div>
                                        <div class="rating-bar-track">
                                            <div class="rating-bar-fill" style="width:<?= $pct ?>%;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="pf-muted">No ratings yet — the breakdown appears once a mentee reviews a session.</p>
                            <?php endif; ?>
                        </div>

                        <!-- Recent reviews -->
                        <div class="card card-p">
                            <h3 class="pf-ct">
                                <?= $pf_icon('chat') ?>Recent Reviews
                                <?php if ($reviews): ?>
                                    <a class="pf-ct-link" href="<?= htmlspecialchars($feedback_url) ?>">View all reviews</a>
                                <?php endif; ?>
                            </h3>
                            <?php if ($reviews): ?>
                                <?php foreach (array_slice($reviews, 0, 3) as $review):
                                    $r_rating = (float)($review['rating'] ?? 0);
                                    $r_name   = trim(($review['firstname'] ?? '') . ' ' . ($review['lastname'] ?? ''));
                                    $r_ini    = strtoupper(substr($review['firstname'] ?? '', 0, 1) . substr($review['lastname'] ?? '', 0, 1));
                                    $r_when   = !empty($review['created_at']) ? strtotime($review['created_at']) : null;
                                    $r_body   = trim((string)($review['comment'] ?? ''));
                                ?>
                                    <div class="pf-rev">
                                        <div class="pf-rev-av"><?= htmlspecialchars($r_ini) ?></div>
                                        <div class="pf-rev-b">
                                            <p class="pf-rev-n"><?= htmlspecialchars($r_name) ?></p>
                                            <p class="pf-rev-s">Mentee<?= !empty($review['subject']) ? ' · ' . htmlspecialchars($review['subject']) : '' ?></p>
                                            <?php if ($r_body !== ''): ?>
                                                <p class="pf-rev-c"><?= htmlspecialchars($r_body) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <span class="pf-rev-r">
                                            <span class="stars-on"><?= str_repeat('★', (int)round($r_rating)) ?></span><span class="stars-off"><?= str_repeat('★', 5 - (int)round($r_rating)) ?></span>
                                            <span style="font-weight:600;color:var(--gray-700);"><?= number_format($r_rating, 1) ?></span>
                                        </span>
                                        <?php if ($r_when): ?>
                                            <span class="pf-rev-d"><?= $pf_icon('cal', 'width="14" height="14"') ?><?= date('M j, Y', $r_when) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="pf-empty">
                                    <?= $pf_icon('chat', 'width="30" height="30"') ?>
                                    <p>No reviews yet.</p>
                                    <p style="margin-top:4px;font-size:12px;">Feedback from mentees will appear here.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ── Side column ── -->
                    <div class="pf-col">

                        <!-- Student info -->
                        <div class="card card-p">
                            <h3 class="pf-ct">
                                <?= $pf_icon('person') ?>Student Info
                                <button type="button" class="pf-ct-link" onclick="openEditModal()"
                                    style="border:none;background:none;cursor:pointer;padding:0;">Edit</button>
                            </h3>
                            <div class="info-row">
                                <span class="info-label">Student ID</span>
                                <span class="info-value"><?= htmlspecialchars($verification['student_id'] ?? '—') ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Course</span>
                                <span class="info-value" style="max-width:170px;"><?= htmlspecialchars($verification['course'] ?? '—') ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Year Level</span>
                                <span class="info-value"><?= htmlspecialchars($verification['year_level'] ?? '—') ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Club</span>
                                <span class="info-value"><?= htmlspecialchars($verification['club'] ?: '—') ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Email</span>
                                <span class="info-value" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($_SESSION['email'] ?? '—') ?></span>
                            </div>
                        </div>

                        <!-- Badges -->
                        <div class="card card-p">
                            <h3 class="pf-ct">
                                <?= $pf_icon('badge') ?>Badges
                                <?php if ($my_badges): ?>
                                    <span class="pf-ct-link" style="color:var(--gray-400);"><?= count($my_badges) ?></span>
                                <?php endif; ?>
                            </h3>
                            <?php if ($my_badges): ?>
                                <div class="pf-badges">
                                    <?php foreach ($my_badges as $b): ?>
                                        <div class="pf-badge" title="<?= htmlspecialchars($b['description'] ?? '') ?>">
                                            <span class="pf-badge-ico"><?= $pf_icon('badge', 'width="22" height="22"') ?></span>
                                            <p class="pf-badge-n"><?= htmlspecialchars($b['name']) ?></p>
                                            <p class="pf-badge-d"><?= date('M Y', strtotime($b['awarded_at'])) ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="pf-empty">
                                    <?= $pf_icon('badge', 'width="30" height="30"') ?>
                                    <p>No badges yet.</p>
                                    <p style="margin-top:4px;font-size:12px;">They're awarded as you complete sessions.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Certificates -->
                        <div class="card card-p">
                            <h3 class="pf-ct"><?= $pf_icon('cert') ?>Certificates</h3>
                            <?php if ($my_certs): ?>
                                <div style="display:flex;flex-direction:column;gap:10px;">
                                    <?php foreach ($my_certs as $c): ?>
                                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;border:1px solid var(--gray-100);border-radius:11px;">
                                            <div style="min-width:0;">
                                                <p style="margin:0 0 2px;font-size:13px;font-weight:600;color:var(--gray-900);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($c['achievement']) ?></p>
                                                <p style="margin:0;font-size:11.5px;color:var(--gray-400);"><?= htmlspecialchars($c['template_name'] ?? 'Certificate') ?> &middot; <?= date('M j, Y', strtotime($c['awarded_at'])) ?></p>
                                            </div>
                                            <?php /* Certificates are rendered on demand, not stored as files:
                                                     generated_path is always empty, so this used to show no link
                                                     at all and the certificate could never actually be seen. */ ?>
                                            <a class="btn btn-ghost btn-sm" href="<?= url('certificate-view') ?>?id=<?= (int)$c['cert_id'] ?>" target="_blank" rel="noopener" style="flex-shrink:0;">
                                                <?= $pf_icon('download', 'width="14" height="14"') ?> View
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="pf-empty">
                                    <?= $pf_icon('cert', 'width="30" height="30"') ?>
                                    <p>No certificates yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="card" style="text-align:center;padding:70px 40px;max-width:440px;margin:40px auto;">
                    <?= $pf_icon('person', 'width="40" height="40"') ?>
                    <p style="font-weight:600;color:var(--gray-600);margin:12px 0 6px;font-size:15px;">No approved verification found</p>
                    <p style="font-size:12px;color:var(--gray-400);">Your profile will appear here once your verification is approved.</p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- ════════ EDIT PROFILE MODAL ════════ -->
    <div id="editModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <div class="modal-box" style="max-width:560px;width:min(96vw,560px);">

            <!-- Header -->
            <div class="modal-hd">
                <h2 class="modal-hd-title" id="editModalTitle">Edit Profile</h2>
                <button class="modal-close" onclick="closeEditModal()" aria-label="Close edit profile">&times;</button>
            </div>

            <!-- Body -->
            <div class="modal-body">

                <!-- Avatar upload -->
                <div style="display:flex;flex-direction:column;align-items:center;gap:8px;margin-bottom:22px;">
                    <div style="position:relative;cursor:pointer;" onclick="document.getElementById('profilePic').click()" role="button" tabindex="0" aria-label="Change profile photo" onkeydown="if(event.key==='Enter')this.click();">
                        <div id="avatarDisplay" class="pc-avatar pc-avatar-xl" style="overflow:hidden;">
                            <?php if ($profile_image): ?>
                                <img id="avatarImg" src="<?= htmlspecialchars($profile_image) ?>" alt="Current profile photo" style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <span id="avatarInitial"><?= strtoupper(substr($verification['full_name'] ?? 'U', 0, 1)) ?></span>
                                <img id="avatarImg" src="" alt="" style="width:100%;height:100%;object-fit:cover;display:none;">
                            <?php endif; ?>
                        </div>
                        <span style="position:absolute;bottom:-2px;right:-2px;width:22px;height:22px;background:var(--forest);border-radius:50%;border:2px solid var(--surface);display:flex;align-items:center;justify-content:center;pointer-events:none;" aria-hidden="true">
                            <svg width="10" height="10" fill="none" stroke="white" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </span>
                    </div>
                    <span style="font-size:12px;color:var(--gray-400);">Click to change photo · JPG, PNG or WebP</span>
                    <input type="file" id="profilePic" accept="image/*" style="display:none;" aria-label="Upload profile photo">
                </div>

                <!-- Form fields -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">

                    <div class="form-group" style="grid-column:span 2;">
                        <label class="form-label" for="fullName">Full name <span class="required">*</span></label>
                        <input type="text" id="fullName" class="form-input" placeholder="e.g. Juan Dela Cruz" autocomplete="name">
                        <span class="form-hint">Enter your name as it appears on your student ID.</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="studentId">Student ID <span class="required">*</span></label>
                        <input type="text" id="studentId" class="form-input" placeholder="e.g. 2021-00001">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="yearLevel">Year level <span class="required">*</span></label>
                        <select id="yearLevel" class="form-input">
                            <option value="">Select year…</option>
                            <option>1st Year</option>
                            <option>2nd Year</option>
                            <option>3rd Year</option>
                            <option>4th Year</option>
                            <option>5th Year</option>
                            <option>Graduate</option>
                        </select>
                    </div>

                    <div class="form-group" style="grid-column:span 2;">
                        <label class="form-label" for="course">Course / Program <span class="required">*</span></label>
                        <input type="text" id="course" class="form-input" placeholder="e.g. BS Computer Science">
                    </div>

                    <div class="form-group" style="grid-column:span 2;">
                        <label class="form-label" for="club">Club / Organization <span style="color:var(--gray-400);font-weight:400;">(optional)</span></label>
                        <input type="text" id="club" class="form-input" placeholder="e.g. ACM Student Chapter">
                    </div>

                </div>

                <!-- Error/success messages -->
                <div id="formError" class="pc-alert pc-alert-danger" role="alert" style="display:none;margin-top:14px;">
                    <svg class="pc-alert-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" />
                        <path stroke-linecap="round" d="M12 8v4m0 4h.01" />
                    </svg>
                    <div class="pc-alert-body" id="formErrorText"></div>
                </div>
                <div id="formSuccess" class="pc-alert pc-alert-success" role="alert" style="display:none;margin-top:14px;">
                    <svg class="pc-alert-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div class="pc-alert-body">Profile updated successfully.</div>
                </div>

            </div><!-- /modal-body -->

            <!-- Footer -->
            <div class="modal-ft">
                <button onclick="closeEditModal()" class="btn btn-ghost">Cancel</button>
                <button id="saveBtn" onclick="submitEditForm()" class="btn btn-primary" aria-live="polite">
                    <span id="saveBtnText">Save changes</span>
                </button>
            </div>

        </div>
    </div><!-- /editModal -->

    <script>
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

        function openEditModal() {
            document.getElementById('fullName').value = '<?= htmlspecialchars(addslashes($verification['full_name']  ?? '')) ?>';
            document.getElementById('studentId').value = '<?= htmlspecialchars(addslashes($verification['student_id'] ?? '')) ?>';
            document.getElementById('course').value = '<?= htmlspecialchars(addslashes($verification['course']     ?? '')) ?>';
            document.getElementById('yearLevel').value = '<?= htmlspecialchars(addslashes($verification['year_level'] ?? '')) ?>';
            document.getElementById('club').value = '<?= htmlspecialchars(addslashes($verification['club']       ?? '')) ?>';
            document.getElementById('formError').style.display = 'none';
            document.getElementById('editModal').classList.add('open');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('open');
        }

        document.getElementById('profilePic').addEventListener('change', function() {
            const file = this.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.getElementById('avatarImg');
                const initial = document.getElementById('avatarInitial');
                img.src = e.target.result;
                img.style.display = 'block';
                if (initial) initial.style.display = 'none';
            };
            reader.readAsDataURL(file);
        });

        function submitEditForm() {
            const fullName = document.getElementById('fullName').value.trim();
            const studentId = document.getElementById('studentId').value.trim();
            const course = document.getElementById('course').value.trim();
            const yearLevel = document.getElementById('yearLevel').value.trim();
            const club = document.getElementById('club').value.trim();
            const errEl = document.getElementById('formError');
            const errTxt = document.getElementById('formErrorText');
            const successEl = document.getElementById('formSuccess');

            if (!fullName || !studentId || !course || !yearLevel) {
                errTxt.textContent = 'Please fill in all required fields.';
                errEl.style.display = 'flex';
                successEl.style.display = 'none';
                return;
            }
            errEl.style.display = 'none';

            const btn = document.getElementById('saveBtn');
            const btnText = document.getElementById('saveBtnText');
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btnText.textContent = 'Saving…';

            const formData = new FormData();
            formData.append('full_name', fullName);
            formData.append('student_id', studentId);
            formData.append('course', course);
            formData.append('year_level', yearLevel);
            formData.append('club', club);
            const picFile = document.getElementById('profilePic').files[0];
            if (picFile) formData.append('profile_image', picFile);
            formData.append('csrf_token', '<?= csrf_token() ?>');

            fetch('<?= url('mentor-update-profile') ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        closeEditModal();
                        location.reload();
                    } else {
                        errTxt.textContent = data.message || 'Failed to update profile.';
                        errEl.style.display = 'flex';
                    }
                })
                .catch(() => {
                    errTxt.textContent = 'Network error. Please try again.';
                    errEl.style.display = 'flex';
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.removeAttribute('aria-busy');
                    btnText.textContent = 'Save changes';
                });
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeEditModal();
        });
    </script>
</body>

</html>