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

// First-login questionnaire, once, before anything is rendered.
require_once __DIR__ . '/../includes/onboarding_gate.php';
pc_onboarding_gate($con, (int)$_SESSION['user_id'], $_SESSION['role'] ?? '');

$mentee_id = (int)$_SESSION['user_id'];

// Every figure on this page comes from App/repositories; this file only
// arranges it. See SessionRepository for what counts as an upcoming session.
$mentee_firstname = UserRepository::firstName($con, $mentee_id) ?? 'there';

// ── Stat cards (real counts, not placeholders) ──────────────────────────
$active_mentorships = SessionRepository::countMentorsForMentee($con, $mentee_id);
$upcoming_count     = SessionRepository::countUpcomingForMentee($con, $mentee_id);
$unread_messages    = MessageRepository::countUnread($con, $mentee_id);
$assessments_done   = AssessmentRepository::countSubmittedByMentee($con, $mentee_id);

// ── "vs last month" trend (pc_trend(), in helpers.php) — only shown where a
//    stat has a genuinely stable historical timestamp to compare against
//    (session_date / completed_at never change after the fact). Upcoming
//    Sessions and Unread Messages are live/mutable snapshots with no
//    faithful "as of a month ago" state, so those two cards get a real
//    supporting fact instead of a fabricated %. ─────────────────────────
$am_prior          = SessionRepository::countMentorsForMentee($con, $mentee_id, 30);
$trend_mentorships = pc_trend($active_mentorships, $am_prior);

$as_last30         = AssessmentRepository::countSubmittedByMenteeBetween($con, $mentee_id, 30);
$as_prior30        = AssessmentRepository::countSubmittedByMenteeBetween($con, $mentee_id, 60, 30);
$trend_assessments = pc_trend($as_last30, $as_prior30);

$msgs_last7 = MessageRepository::countReceivedInLastDays($con, $mentee_id, 7);

// ── Assessments Overview — published assessments from mentors this mentee
//    has actually worked with, split by where each one stands. ────────────
$assess_rows = AssessmentRepository::publishedForMentee($con, $mentee_id);

$assess_breakdown = ['completed' => 0, 'in_progress' => 0, 'not_started' => 0];
foreach ($assess_rows as $r) {
    $s = $r['attempt_status'] ?? null;
    if ($s === 'submitted')        $assess_breakdown['completed']++;
    elseif ($s === 'in_progress')  $assess_breakdown['in_progress']++;
    else                           $assess_breakdown['not_started']++;
}
$assess_total = count($assess_rows);
$assess_list  = array_slice($assess_rows, 0, 4);

// Average score across everything submitted, for the card's headline stat.
$assess_avg = AssessmentRepository::averageScorePercentForMentee($con, $mentee_id);

// ── Mentorship Progress — real completion rate + status mix from
//    session_requests, not fabricated "skill" percentages. ────────────────
$status_breakdown = ['completed' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0];
foreach (SessionRepository::statusCountsForMentee($con, $mentee_id) as $status => $count) {
    // The progress card shows a cancelled session under Rejected.
    $key = $status === 'cancelled' ? 'rejected' : $status;
    $status_breakdown[$key] = ($status_breakdown[$key] ?? 0) + $count;
}
$sessions_total = array_sum($status_breakdown);
$completion_rate = $sessions_total > 0 ? (int)round(($status_breakdown['completed'] / $sessions_total) * 100) : 0;
$status_rows = [
    ['label' => 'Completed', 'key' => 'completed', 'color' => 'var(--success)'],
    ['label' => 'Approved',  'key' => 'approved',  'color' => 'var(--info)'],
    ['label' => 'Pending',   'key' => 'pending',   'color' => 'var(--warning)'],
    ['label' => 'Rejected',  'key' => 'rejected',  'color' => 'var(--danger)'],
];

// ── Upcoming Sessions (up to 3) ───────────────────────────────────────────
$upcoming_list = SessionRepository::upcomingForMentee($con, $mentee_id, 3);

// ── Recent Activity — reuses the existing notifications table ────────────
$recent_activity = NotificationRepository::latestForUser($con, $mentee_id, 4);
$activity_icons = [
    'session_approved'       => ['bg' => 'var(--mint-faint)', 'color' => 'var(--forest)', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>'],
    'session_ended'          => ['bg' => 'var(--info-bg)', 'color' => 'var(--info)', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
    'feedback_received'      => ['bg' => 'var(--gold-light)', 'color' => 'var(--warning)', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>'],
    'badge_awarded'          => ['bg' => 'var(--gold-light)', 'color' => 'var(--warning)', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 15a6 6 0 100-12 6 6 0 000 12zM8.5 14L7 22l5-3 5 3-1.5-8"/>'],
    'certificate_awarded'    => ['bg' => 'var(--info-bg)', 'color' => 'var(--info)', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m8-6H5a2 2 0 00-2 2v14l4-3h12a2 2 0 002-2V8a2 2 0 00-2-2z"/>'],
    'verification_approved'  => ['bg' => 'var(--success-bg)', 'color' => 'var(--success)', 'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
    '_default'                => ['bg' => 'var(--gray-100)', 'color' => 'var(--gray-500)', 'svg' => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v4l3 3"/>'],
];

// ── Recommended for You — reuses the existing MentorScoreService /
//    matching engine, not a new recommendation algorithm. Excludes mentors
//    the mentee already has a request/relationship with. ─────────────────
// Settings → Data Privacy → "Personalized recommendations". Off means we rank
// mentors on their public score alone and ignore this mentee's preferences.
$personalized = UserRepository::wantsPersonalizedRecommendations($con, $mentee_id);

$mentee_prefs        = $personalized ? UserRepository::menteePreferences($con, $mentee_id) : [];
$existing_mentor_ids = SessionRepository::mentorIdsForMentee($con, $mentee_id);
// Passing $mentee_id lets the service rank on this mentee's questionnaire
// answers first and the mentor's performance score second. Withheld when
// personalized recommendations are switched off in Settings → Data Privacy,
// which then leaves the ranking on the public score alone.
$recommended = array_values(array_filter(
    MentorScoreService::getRankedMentors($con, $mentee_prefs, 10, $personalized ? $mentee_id : 0),
    fn($m) => !in_array((int)$m['user_id'], $existing_mentor_ids, true)
));
$recommended = array_slice($recommended, 0, 3);

$find_mentor_url = url('mentee-find');
$sessions_url = url('mentee-sessions');
$feedback_url = url('mentee-feedback');
$active_page = 'dashboard';

// Donut/ring geometry (precomputed here so the markup below stays simple)
$ring_r = 52;
$ring_circumference = 2 * M_PI * $ring_r;
$ring_offset = $ring_circumference * (1 - $completion_rate / 100);

$assess_r = 46;
$assess_circumference = 2 * M_PI * $assess_r;
$a_completed_len  = $assess_total > 0 ? $assess_circumference * ($assess_breakdown['completed'] / $assess_total) : 0;
$a_inprogress_len = $assess_total > 0 ? $assess_circumference * ($assess_breakdown['in_progress'] / $assess_total) : 0;
$a_notstarted_len = $assess_total > 0 ? $assess_circumference * ($assess_breakdown['not_started'] / $assess_total) : 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Dashboard — PeerConnect</title>
    <?php include __DIR__ . '/includes/style.php'; ?>
    <style>
        /* ── Mentee Dashboard ── */
        /* .dash-grid/.stats-grid come from the shared design system, which
           already collapses them responsively — not redeclared here. */

        /* .pcard/.pcard-hd/.pcard-title/.pcard-link/.pcard-body/.prow/
           .prow-empty/.hero-banner/.hero-illustration/.ring-* now live in
           the shared design system (also used by Find a Mentor). */

        /* .stat-icon, .si-teal|blue|purple|orange, .stat-trend, .stat-fact and
           .stat-card-icon now live in the shared design system (also used by
           Sessions), including the 700px "compact on phones only" behavior. */

        .status-bar-track {
            height: 6px;
            border-radius: 999px;
            background: var(--gray-100);
            overflow: hidden;
        }

        .status-bar-fill {
            height: 100%;
            border-radius: 999px;
        }

        /* minmax(0, 1fr), not plain 1fr — a plain 1fr track takes its minimum
           from content, so one wide row (a long session title, a nowrap chip)
           pushes the column past the viewport and scrolls the whole page
           sideways on phones. */
        @media (max-width: 960px) {
            .dash-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main fade-in">

            <!-- Page header -->
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">

                <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-top:4px;">
                    <!-- PWA Install — shown by JS only when browser supports beforeinstallprompt -->
                    <button id="pwa-install-btn" style="display:none;align-items:center;gap:6px;background:var(--forest);color:white;border:none;border-radius:9px;padding:8px 14px;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit;" title="Install PeerConnect as an app">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Install App
                    </button>
                </div>
            </div>

            <!-- Welcome hero -->
            <div class="hero-banner">
                <div>
                    <h2>Welcome, <?= htmlspecialchars($mentee_firstname) ?>! 👋</h2>
                    <p>Ready to learn, connect, and grow today?</p>
                </div>
                <svg class="hero-illustration" viewBox="0 0 220 140" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle cx="60" cy="70" r="52" fill="var(--mint-faint)" />
                    <circle cx="168" cy="45" r="30" fill="var(--mint-soft)" opacity=".55" />
                    <rect x="26" y="78" width="64" height="46" rx="8" fill="var(--surface)" stroke="var(--border)" stroke-width="1.5" />
                    <circle cx="58" cy="96" r="11" fill="var(--forest)" opacity=".85" />
                    <path d="M42 122c2-9 8-14 16-14s14 5 16 14" stroke="var(--forest)" stroke-width="2" stroke-linecap="round" fill="none" opacity=".85" />
                    <rect x="120" y="58" width="70" height="52" rx="9" fill="var(--forest)" />
                    <circle cx="150" cy="78" r="11" fill="#fff" opacity=".9" />
                    <path d="M133 106c2-9 8-14 17-14s15 5 17 14" stroke="#fff" stroke-width="2" stroke-linecap="round" fill="none" opacity=".9" />
                    <circle cx="188" cy="30" r="13" fill="var(--success)" />
                    <path d="M182 30l4 4 8-8" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" fill="none" />
                    <path d="M96 60h20M96 66h14" stroke="var(--mint)" stroke-width="2.5" stroke-linecap="round" />
                </svg>
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
                        <div class="stat-val"><?= $active_mentorships ?></div>
                        <div class="stat-lbl">Active Mentorships</div>
                        <?php if ($trend_mentorships): ?>
                            <div class="stat-trend trend-<?= $trend_mentorships['dir'] ?>">
                                <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="<?= $trend_mentorships['dir'] === 'up' ? 'M5 15l7-7 7 7' : 'M5 9l7 7 7-7' ?>" />
                                </svg>
                                <?= htmlspecialchars($trend_mentorships['label']) ?>
                            </div>
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
                        <div class="stat-fact">
                            <?= $upcoming_list ? 'Next: ' . date('M j', strtotime($upcoming_list[0]['session_date'])) : 'None scheduled' ?>
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
                        <div class="stat-fact"><?= $msgs_last7 ? $msgs_last7 . ' received this week' : 'All caught up' ?></div>
                    </div>
                </div>
                <div class="stat-card stat-card-icon">
                    <div class="stat-icon si-orange">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" />
                            <rect x="9" y="3" width="6" height="4" rx="1.2" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 13 2 2 4-4" />
                        </svg>
                    </div>
                    <div>
                        <div class="stat-val"><?= $assessments_done ?></div>
                        <div class="stat-lbl">Assessments Taken</div>
                        <?php if ($trend_assessments): ?>
                            <div class="stat-trend trend-<?= $trend_assessments['dir'] ?>">
                                <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="<?= $trend_assessments['dir'] === 'up' ? 'M5 15l7-7 7 7' : 'M5 9l7 7 7-7' ?>" />
                                </svg>
                                <?= htmlspecialchars($trend_assessments['label']) ?>
                            </div>
                        <?php elseif ($assessments_done > 0): ?>
                            <div class="stat-fact">Avg score <?= $assess_avg ?>%</div>
                        <?php endif; ?>
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
                            <a href="<?= htmlspecialchars($sessions_url) ?>" class="pcard-link">View all →</a>
                        </div>
                        <?php if ($upcoming_list): ?>
                            <?php foreach ($upcoming_list as $s):
                                $name = trim($s['firstname'] . ' ' . $s['lastname']);
                                $ini = strtoupper(substr($s['firstname'], 0, 1) . substr($s['lastname'], 0, 1));
                                $ts = strtotime($s['session_date']);
                                $joinUrl = url('video-join') . '?session_id=' . (int)$s['request_id'];
                            ?>
                                <div class="prow">
                                    <?php if (!empty($s['profile_image'])): ?>
                                        <img src="<?= htmlspecialchars($s['profile_image']) ?>" alt="" style="width:42px;height:42px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                                    <?php else: ?>
                                        <div class="pc-avatar pc-avatar-md" style="flex-shrink:0;"><?= $ini ?></div>
                                    <?php endif; ?>
                                    <div style="min-width:0;flex:1;">
                                        <div style="font-size:13.5px;font-weight:700;color:var(--gray-900);"><?= htmlspecialchars($name) ?></div>
                                        <div style="font-size:12px;color:var(--gray-400);"><?= htmlspecialchars($s['subject'] ?: 'General mentorship') ?></div>
                                    </div>
                                    <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end;flex-shrink:0;">
                                        <span style="font-size:11.5px;color:var(--gray-500);display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">
                                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                            <?= date('M j, Y', $ts) ?>
                                        </span>
                                        <span style="font-size:11.5px;color:var(--gray-500);display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">
                                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <?= date('g:i A', $ts) ?>
                                        </span>
                                    </div>
                                    <a href="<?= htmlspecialchars($joinUrl) ?>" class="btn btn-ghost" style="font-size:12px;padding:7px 14px;flex-shrink:0;border-color:var(--mint-soft);color:var(--forest);">Join Session</a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="prow-empty">
                                <p style="margin:0 0 12px;">No upcoming sessions. Book a session with a mentor to get started.</p>
                                <a href="<?= htmlspecialchars($find_mentor_url) ?>" class="btn btn-primary btn-sm">Find a mentor →</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Recommended for You -->
                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Recommended for You</span>
                            <a href="<?= htmlspecialchars($find_mentor_url) ?>" class="pcard-link">View all →</a>
                        </div>
                        <?php if (!empty($recommended)): ?>
                            <?php foreach ($recommended as $rm):
                                $rmName = trim($rm['firstname'] . ' ' . $rm['lastname']);
                                $rmIni  = strtoupper(substr($rm['firstname'], 0, 1) . substr($rm['lastname'], 0, 1));
                                $rmRating = (float)$rm['avg_rating'];
                                $rmSessions = (int)$rm['total_sessions'];
                            ?>
                                <div class="prow">
                                    <?php if (!empty($rm['profile_image'])): ?>
                                        <img src="<?= htmlspecialchars($rm['profile_image']) ?>" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                                    <?php else: ?>
                                        <div class="pc-avatar pc-avatar-md" style="flex-shrink:0;"><?= $rmIni ?></div>
                                    <?php endif; ?>
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-size:13px;font-weight:700;color:var(--gray-900);"><?= htmlspecialchars($rmName) ?></div>
                                        <div style="font-size:11.5px;color:var(--gray-400);"><?= htmlspecialchars($rm['expertise'] ?: 'General mentorship') ?></div>
                                        <?php if ($rmRating > 0): ?>
                                            <div style="font-size:11px;color:var(--gray-500);margin-top:2px;">★ <?= number_format($rmRating, 1) ?><?= $rmSessions ? ' (' . $rmSessions . ' sessions)' : '' ?></div>
                                        <?php endif; ?>
                                        <?php // Why this mentor, in their own answers — not a bare score.
                                        if (!empty($rm['shared_reasons'])): ?>
                                            <div style="font-size:11px;color:var(--mint);margin-top:3px;font-weight:600;">
                                                <?= htmlspecialchars($rm['shared_reasons'][0]) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <a href="<?= htmlspecialchars(url('mentee-view-mentor') . '?id=' . (int)$rm['user_id']) ?>" class="btn btn-ghost" style="font-size:11.5px;padding:6px 12px;flex-shrink:0;">View Profile</a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="prow-empty">
                                <?= $personalized
                                    ? 'No recommendations yet — answer the matching questionnaire to get matched.'
                                    : 'Personalized recommendations are off in Settings → Data Privacy.' ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Recent Activity — reuses the notifications table -->
                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Recent Activity</span>
                            <button type="button" class="pcard-link" onclick="if (typeof toggleNotifPanel==='function') toggleNotifPanel();">View all →</button>
                        </div>
                        <?php if ($recent_activity): ?>
                            <?php foreach ($recent_activity as $a):
                                $ic = $activity_icons[$a['type']] ?? $activity_icons['_default'];
                                $diff = time() - strtotime($a['created_at']);
                                if ($diff < 3600) $ago = max(1, (int)($diff / 60)) . 'm ago';
                                elseif ($diff < 86400) $ago = (int)($diff / 3600) . 'h ago';
                                else $ago = date('M j', strtotime($a['created_at']));
                            ?>
                                <div class="prow">
                                    <div style="width:36px;height:36px;border-radius:50%;background:<?= $ic['bg'] ?>;color:<?= $ic['color'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><?= $ic['svg'] ?></svg>
                                    </div>
                                    <div style="flex:1;min-width:0;font-size:13px;color:var(--gray-700);"><?= htmlspecialchars($a['title']) ?></div>
                                    <div style="font-size:11.5px;color:var(--gray-400);white-space:nowrap;flex-shrink:0;"><?= $ago ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="prow-empty">No recent activity yet.</div>
                        <?php endif; ?>
                    </div>



                </div><!-- /left -->

                <!-- RIGHT column -->
                <div style="display:flex;flex-direction:column;gap:16px;">

                    <!-- Mentorship Progress -->
                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Mentorship Progress</span>
                            <a href="<?= htmlspecialchars($sessions_url) ?>" class="pcard-link">View all →</a>
                        </div>
                        <div class="pcard-body" style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
                            <div class="ring-wrap" style="width:104px;height:104px;">
                                <svg viewBox="0 0 120 120" width="104" height="104">
                                    <circle cx="60" cy="60" r="<?= $ring_r ?>" fill="none" stroke="var(--gray-100)" stroke-width="11" />
                                    <?php if ($sessions_total > 0): ?>
                                        <circle cx="60" cy="60" r="<?= $ring_r ?>" fill="none" stroke="var(--forest)" stroke-width="11"
                                            stroke-dasharray="<?= $ring_circumference ?>" stroke-dashoffset="<?= $ring_offset ?>"
                                            stroke-linecap="round" transform="rotate(-90 60 60)" />
                                    <?php endif; ?>
                                </svg>
                                <div class="ring-center">
                                    <span class="ring-pct"><?= $completion_rate ?>%</span>
                                    <span class="ring-lbl">Overall<br>Progress</span>
                                </div>
                            </div>
                            <div style="flex:1;min-width:170px;display:flex;flex-direction:column;gap:11px;">
                                <?php if ($sessions_total > 0): ?>
                                    <?php foreach ($status_rows as $row):
                                        $count = $status_breakdown[$row['key']];
                                        $pct = $sessions_total > 0 ? (int)round($count / $sessions_total * 100) : 0;
                                    ?>
                                        <div>
                                            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--gray-600);margin-bottom:5px;">
                                                <span><?= $row['label'] ?></span><span style="font-weight:600;"><?= $pct ?>%</span>
                                            </div>
                                            <div class="status-bar-track">
                                                <div class="status-bar-fill" style="width:<?= $pct ?>%;background:<?= $row['color'] ?>;"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p style="font-size:12.5px;color:var(--gray-400);margin:0;">No sessions yet — book your first mentor session to start tracking progress.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Assessments Overview -->
                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Assessments</span>
                            <a href="<?= htmlspecialchars(url('assessments')) ?>" class="pcard-link">View all →</a>
                        </div>
                        <div class="pcard-body">
                            <?php if ($assess_total > 0): ?>
                                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:16px;">
                                    <div class="ring-wrap" style="width:96px;height:96px;">
                                        <svg viewBox="0 0 100 100" width="96" height="96">
                                            <circle cx="50" cy="50" r="<?= $assess_r ?>" fill="none" stroke="var(--gray-100)" stroke-width="10" />
                                            <?php $off = 0; ?>
                                            <?php if ($a_completed_len > 0): ?>
                                                <circle cx="50" cy="50" r="<?= $assess_r ?>" fill="none" stroke="var(--success)" stroke-width="10"
                                                    stroke-dasharray="<?= $a_completed_len ?> <?= $assess_circumference ?>" stroke-dashoffset="<?= -$off ?>"
                                                    transform="rotate(-90 50 50)" />
                                            <?php $off += $a_completed_len;
                                            endif; ?>
                                            <?php if ($a_inprogress_len > 0): ?>
                                                <circle cx="50" cy="50" r="<?= $assess_r ?>" fill="none" stroke="var(--info)" stroke-width="10"
                                                    stroke-dasharray="<?= $a_inprogress_len ?> <?= $assess_circumference ?>" stroke-dashoffset="<?= -$off ?>"
                                                    transform="rotate(-90 50 50)" />
                                            <?php $off += $a_inprogress_len;
                                            endif; ?>
                                            <?php if ($a_notstarted_len > 0): ?>
                                                <circle cx="50" cy="50" r="<?= $assess_r ?>" fill="none" stroke="var(--gray-300)" stroke-width="10"
                                                    stroke-dasharray="<?= $a_notstarted_len ?> <?= $assess_circumference ?>" stroke-dashoffset="<?= -$off ?>"
                                                    transform="rotate(-90 50 50)" />
                                            <?php endif; ?>
                                        </svg>
                                        <div class="ring-center">
                                            <span class="ring-pct" style="font-size:17px;"><?= $assess_total ?></span>
                                            <span class="ring-lbl">Total</span>
                                        </div>
                                    </div>
                                    <div style="flex:1;min-width:140px;display:flex;flex-direction:column;gap:8px;font-size:12.5px;">
                                        <div style="display:flex;align-items:center;justify-content:space-between;">
                                            <span style="display:flex;align-items:center;gap:6px;color:var(--gray-600);"><span style="width:8px;height:8px;border-radius:50%;background:var(--success);display:inline-block;"></span>Completed</span>
                                            <span style="font-weight:600;color:var(--gray-800);"><?= $assess_breakdown['completed'] ?></span>
                                        </div>
                                        <div style="display:flex;align-items:center;justify-content:space-between;">
                                            <span style="display:flex;align-items:center;gap:6px;color:var(--gray-600);"><span style="width:8px;height:8px;border-radius:50%;background:var(--info);display:inline-block;"></span>In Progress</span>
                                            <span style="font-weight:600;color:var(--gray-800);"><?= $assess_breakdown['in_progress'] ?></span>
                                        </div>
                                        <div style="display:flex;align-items:center;justify-content:space-between;">
                                            <span style="display:flex;align-items:center;gap:6px;color:var(--gray-600);"><span style="width:8px;height:8px;border-radius:50%;background:var(--gray-300);display:inline-block;"></span>Not Started</span>
                                            <span style="font-weight:600;color:var(--gray-800);"><?= $assess_breakdown['not_started'] ?></span>
                                        </div>
                                        <?php if ($assessments_done > 0): ?>
                                            <div style="display:flex;align-items:center;justify-content:space-between;padding-top:6px;border-top:1px solid var(--border);">
                                                <span style="color:var(--gray-600);">Average score</span>
                                                <span style="font-weight:700;color:var(--forest);"><?= $assess_avg ?>%</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div style="display:flex;flex-direction:column;gap:6px;">
                                    <?php foreach ($assess_list as $a):
                                        $st = $a['attempt_status'] ?? null;
                                        $label = $st === 'submitted' ? 'Completed' : ($st === 'in_progress' ? 'In progress' : 'Not started');
                                        $color = $st === 'submitted' ? 'var(--success)' : ($st === 'in_progress' ? 'var(--info)' : 'var(--gray-300)');
                                    ?>
                                        <a href="<?= htmlspecialchars(url('assessment-take')) ?>?id=<?= (int)$a['assessment_id'] ?>"
                                            style="display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px 12px;text-decoration:none;">
                                            <span style="font-size:12.5px;color:var(--gray-700);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($a['title']) ?></span>
                                            <span style="font-size:11px;font-weight:600;color:<?= $color ?>;white-space:nowrap;flex-shrink:0;"><?= $label ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p style="font-size:12.5px;color:var(--gray-400);margin:0;">
                                    No assessments yet — once a mentor you've had a session with publishes one, it will appear here.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>



                </div><!-- /right -->
            </div><!-- /dash-grid -->
        </main>
    </div>

    <script>
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
    </script>
</body>

</html>