<?php
// My Profile (mentee). Everything shown is read from this account's own rows —
// verification details, real review averages, real sessions, real activity.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentee') {
    header("Location: " . url('welcomepage'));
    exit;
}

$user_id = $mentee_id = (int)$_SESSION['user_id'];

// ── Identity ─────────────────────────────────────────────────────────────
$account = UserRepository::accountBasics($con, $user_id);

// verification holds what an admin approved; profile holds later edits.
$info = VerificationRepository::approvedDetails($con, $user_id) ?: [];

$profile = ProfileRepository::fields($con, $user_id,
    ['full_name', 'student_id', 'course', 'year_level', 'section', 'club', 'location', 'bio', 'profile_image']) ?: [];

foreach (['full_name', 'student_id', 'course', 'year_level', 'club'] as $f) {
    if (!empty($profile[$f])) $info[$f] = $profile[$f];
}
$full_name     = trim($info['full_name'] ?? '') ?: trim($account['firstname'] . ' ' . $account['lastname']);
$profile_image = $profile['profile_image'] ?? null;
$bio           = trim((string)($profile['bio'] ?? ''));

// ── Tags ─────────────────────────────────────────────────────────────────
$tags = ['interest' => [], 'skill' => [], 'learn' => []];
foreach (ProfileRepository::tagsInOrderAdded($con, $user_id) as $row) {
    $tags[$row['tag_type']][] = $row['tag'];
}

// ── Reviews this mentee has given ────────────────────────────────────────
$reviews = FeedbackRepository::givenSummaryForMentee($con, $user_id);

// ── Next session ─────────────────────────────────────────────────────────
$next_session = SessionRepository::nextApprovedWithMentorForMentee($con, $user_id);

// ── Recent activity — assembled from what actually happened ──────────────
$activity = UserRepository::recentActivityForMentee($con, $user_id, 6);

// ── Milestones — derived from real counts, not a badges table ────────────
// (the badges table is mentor-only: Rising/Experienced/Master Mentor.)
$counts = UserRepository::menteeMilestoneCounts($con, $user_id);

// When they joined, for the banner. The mentor's profile shows the same.
$joined_at = UserRepository::joinedAt($con, $user_id);

// How they are scoring on the assessments their mentors set. NULL when
// nothing has been submitted — not 0, which is a mark someone can actually
// get and would read as having failed everything.
$avg_score = AssessmentRepository::averageScorePercentForMentee($con, $user_id);
if ((int)($counts['assessments'] ?? 0) === 0) {
    $avg_score = null;
}

$milestones = [
    ['First Mentorship', 'Complete your first mentoring session', (int)$counts['sessions'] >= 1, 'trophy'],
    ['Active Learner',   'Complete 5 mentoring sessions',          (int)$counts['sessions'] >= 5, 'star'],
    ['Helpful Voice',    'Review a mentor after a session',        (int)$counts['reviews'] >= 1,  'chat'],
    ['Well Assessed',    'Submit your first assessment',           (int)$counts['assessments'] >= 1, 'check'],
];
$earned = array_values(array_filter($milestones, fn($m) => $m[2]));

// ── Profile completion — each item is a real, checkable field ────────────
// The questionnaire is on the list because skipping it is allowed: this is
// where someone who skipped is offered it again, instead of being nagged at
// every login.
require_once __DIR__ . '/../includes/onboarding_gate.php';
$onboarding = pc_onboarding_state($con, $user_id);

$completion = [
    ['Basic information',   !empty($info['student_id']) && !empty($info['course'])],
    ['Add a profile photo', !empty($profile_image)],
    ['Write about you',     $bio !== ''],
    ['Answer the matching questionnaire', $onboarding['done']],
    ['Add your interests',  count($tags['interest']) > 0],
    ['Add your skills',     count($tags['skill']) > 0 || count($tags['learn']) > 0],
];
$done_count = count(array_filter($completion, fn($c) => $c[1]));
$pct        = (int)round($done_count / count($completion) * 100);

$appTz       = new DateTimeZone('Asia/Manila');
$active_page = 'profile';
$csrf        = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        .pf-layout {
            display: grid;
            grid-template-columns: 290px minmax(0, 1fr) 320px;
            gap: 18px;
            align-items: start;
        }

        .pf-col {
            display: flex;
            flex-direction: column;
            gap: 16px;
            min-width: 0;
        }

        /* ── Identity banner ──
           Across the top rather than down the left, matching the mentor's
           profile: the name, what they study and the actions sit on one line
           instead of stacking in a narrow column. */
        .pf-hero {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            padding: 22px 24px;
            margin-bottom: 16px;
        }

        .pf-avatar-wrap {
            position: relative;
            width: 92px;
            flex-shrink: 0;
        }

        .pf-avatar {
            width: 92px;
            height: 92px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--gray-100);
            background: var(--mint-faint);
            color: var(--forest);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            font-weight: 700;
        }

        .pf-cam {
            position: absolute;
            right: 0;
            bottom: 0;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--mint);
            color: #fff;
            border: 3px solid #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .pf-hero-body {
            flex: 1 1 auto;
            min-width: 0;
        }

        .pf-name {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 21px;
            font-weight: 800;
            color: var(--gray-900);
            margin: 0;
        }

        .pf-role {
            font-size: 11px;
            font-weight: 700;
            background: var(--mint-faint);
            color: var(--mint-deep);
            border-radius: 999px;
            padding: 3px 11px;
        }

        .pf-headline {
            font-size: 13px;
            color: var(--gray-600);
            margin: 6px 0 0;
        }

        .pf-quote {
            font-size: 12.5px;
            font-style: italic;
            color: var(--gray-500);
            margin: 6px 0 0;
            line-height: 1.5;
        }

        .pf-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 12px;
            font-size: 12px;
            color: var(--gray-500);
        }

        .pf-meta span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .pf-meta a {
            color: var(--info);
            font-weight: 600;
        }

        .pf-live {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .pf-hero-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex-shrink: 0;
        }

        /* ── Stat row ── */
        .pf-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 18px;
        }

        .pf-stat {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 18px 20px;
        }

        .pf-stat-ico {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .pf-stat-v {
            font-size: 26px;
            font-weight: 800;
            color: var(--gray-900);
            line-height: 1.1;
        }

        .pf-stat-k {
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 2px;
        }

        @media (max-width: 900px) {
            .pf-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 640px) {
            .pf-hero {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            .pf-name,
            .pf-meta { justify-content: center; }
            .pf-hero-actions {
                flex-direction: row;
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 420px) {
            .pf-stats { grid-template-columns: minmax(0, 1fr); }
        }

        /* ── Info rows ── */
        .pf-info-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            padding: 11px 0;
            border-bottom: 1px solid var(--border);
            font-size: 12.5px;
        }

        .pf-info-row:last-child {
            border-bottom: none;
        }

        .pf-info-row span:first-child {
            color: var(--gray-500);
            flex-shrink: 0;
        }

        .pf-info-row span:last-child {
            color: var(--forest);
            font-weight: 600;
            text-align: right;
        }

        /* ── Tag chips ── */
        .pf-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .pf-tag {
            font-size: 12px;
            font-weight: 600;
            /* Not 999px: questionnaire answers are long enough to wrap, and a
               full pill radius turns a two-line chip into a stretched lozenge.
               At single-line height this still renders as a pill. */
            border-radius: 14px;
            padding: 5px 13px;
            background: var(--mint-faint);
            color: var(--mint);
            max-width: 100%;
            line-height: 1.45;
        }

        .pf-tag.t1 {
            background: #E9F7F0;
            color: #1F7A5C;
        }

        .pf-tag.t2 {
            background: #EAF2FE;
            color: #2563C9;
        }

        .pf-tag.t3 {
            background: #F1EDFD;
            color: #5B4FCF;
        }

        .pf-tag.t4 {
            background: #FDEEF2;
            color: #C2416A;
        }

        .pf-tag.t5 {
            background: #FEF6E3;
            color: #9A6B10;
        }

        /* ── Activity ── */
        .pf-act {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 0;
            border-bottom: 1px solid var(--border);
        }

        .pf-act:last-child {
            border-bottom: none;
        }

        .pf-act-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /* ── Completion ring ── */
        .pf-ring {
            position: relative;
            width: 96px;
            height: 96px;
            flex-shrink: 0;
        }

        .pf-ring-txt {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            font-weight: 800;
            color: var(--forest);
        }

        .pf-check {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 12.5px;
            padding: 6px 0;
        }

        /* ── Milestones ── */
        .pf-mile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 0;
            border-bottom: 1px solid var(--border);
        }

        .pf-mile:last-child {
            border-bottom: none;
        }

        .pf-mile-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /* Tag editor. Chips rather than one comma-separated field, because a
           questionnaire answer can contain a comma of its own ("Science,
           Technology and Society (STS)") and a comma-joined input would split
           it in two the next time this form was saved. */
        .pf-edit-box {
            border-top: 1px solid var(--border);
            padding-top: 14px;
            margin-top: 14px;
        }

        .pf-edit-box textarea,
        .pf-edit-box input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 10px 13px;
            font-family: inherit;
            font-size: 13px;
            color: var(--gray-800);
        }

        .pf-edit-box textarea:focus,
        .pf-edit-box input:focus {
            outline: none;
            border-color: var(--mint-soft);
        }

        @media (max-width: 1240px) {
            .pf-layout {
                grid-template-columns: 270px minmax(0, 1fr);
            }
        }

        @media (max-width: 900px) {
            .pf-layout {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main fade-in">

            <div id="pfAlert" style="display:none;border-radius:var(--radius);padding:10px 14px;font-size:12.5px;margin-bottom:14px;"></div>

            <?php
            // Course, year and club on one line, in that order. Only the parts
            // that exist are joined, so a half-filled profile never renders a
            // stray separator — the same rule the mentor's banner follows.
            $headline_bits = array_values(array_filter([
                $info['course'] ?? '',
                $info['year_level'] ?? '',
                $info['club'] ?? '',
            ], fn($v) => trim((string)$v) !== ''));
            $location = trim((string)($profile['location'] ?? ''));
            ?>

            <!-- ══════════ BANNER ══════════ -->
            <div class="card pf-hero">
                <?php
                /*
                 * The identity fields travel with the photo because the
                 * endpoint validates them before it looks at the file: this
                 * form used to send the token and the image alone, so every
                 * upload was refused with "All required fields must be
                 * filled" and, being a plain form post to a JSON endpoint,
                 * printed that reply as raw JSON over the page. Sending the
                 * values already on the record changes nothing about them and
                 * lets the upload through.
                 */
                ?>
                <form id="photoForm" enctype="multipart/form-data" style="display:none;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="full_name"  value="<?= htmlspecialchars($full_name) ?>">
                    <input type="hidden" name="student_id" value="<?= htmlspecialchars($info['student_id'] ?? '') ?>">
                    <input type="hidden" name="course"     value="<?= htmlspecialchars($info['course'] ?? '') ?>">
                    <input type="hidden" name="year_level" value="<?= htmlspecialchars($info['year_level'] ?? '') ?>">
                    <input type="hidden" name="club"       value="<?= htmlspecialchars($info['club'] ?? '') ?>">
                    <input type="file" name="profile_image" id="photoInput" accept="image/*" onchange="uploadPhoto()">
                </form>
                <div class="pf-avatar-wrap">
                    <?php if ($profile_image): ?>
                        <img class="pf-avatar" src="<?= htmlspecialchars($profile_image) ?>" alt="">
                    <?php else: ?>
                        <div class="pf-avatar"><?= htmlspecialchars(strtoupper(substr($full_name, 0, 1))) ?></div>
                    <?php endif; ?>
                    <button type="button" class="pf-cam" onclick="document.getElementById('photoInput').click()" aria-label="Change profile photo">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8h3l1.5-2h7L17 8h3v11H4V8Z" /><circle cx="12" cy="13" r="3" /></svg>
                    </button>
                </div>

                <div class="pf-hero-body">
                    <h2 class="pf-name">
                        <?= htmlspecialchars($full_name) ?>
                        <span class="pf-role">Mentee</span>
                    </h2>

                    <?php if ($headline_bits): ?>
                        <p class="pf-headline"><?= htmlspecialchars(implode(' · ', $headline_bits)) ?></p>
                    <?php endif; ?>

                    <?php if ($bio !== ''): ?>
                        <p class="pf-quote">&ldquo;<?= htmlspecialchars(mb_strimwidth($bio, 0, 120, '…')) ?>&rdquo;</p>
                    <?php endif; ?>

                    <div class="pf-meta">
                        <?php // The mentor's banner shows open slots here. A mentee has none
                              // to offer, so this says whether one is booked instead. ?>
                        <span>
                            <span class="pf-live" style="background:<?= $next_session ? 'var(--success)' : 'var(--gray-300)' ?>;"></span>
                            <?php if ($next_session): ?>
                                Next session <?= htmlspecialchars(date('M j, g:i A', strtotime($next_session['session_date']))) ?>
                            <?php else: ?>
                                No session booked &mdash; <a href="<?= htmlspecialchars(url('mentee-find')) ?>">find a mentor</a>
                            <?php endif; ?>
                        </span>
                        <span>
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11Z" /><circle cx="12" cy="10" r="2.5" /></svg>
                            <?php if ($location !== ''): ?>
                                <?= htmlspecialchars($location) ?>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars(url('mentee-settings')) ?>">Add your location</a>
                            <?php endif; ?>
                        </span>
                        <?php if ($joined_at): ?>
                            <span>
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2.5" /><path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16" /></svg>
                                Joined <?= htmlspecialchars(date('F Y', strtotime($joined_at))) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pf-hero-actions">
                    <button type="button" class="btn btn-primary btn-sm" onclick="openEditModal()">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20 16.5 7.5l3 3L7 23H4v-3Z" /></svg>
                        Edit Profile
                    </button>
                    <a class="btn btn-ghost btn-sm" href="<?= htmlspecialchars(url('mentee-settings')) ?>">Account settings</a>
                </div>
            </div>

            <!-- ══════════ STATS ══════════ -->
            <?php
            // Every one of these is a real count from menteeMilestoneCounts.
            // A mentee has no rating and no badges — the badges table is
            // mentor-only — so those two tiles of the mentor's row are
            // replaced rather than filled with something invented.
            $pf_tiles = [
                ['mentors',     'Mentor' . ((int)($counts['mentors'] ?? 0) === 1 ? '' : 's'),
                 'var(--mint-faint)', 'var(--mint-deep)', 'users'],
                ['sessions',    'Session' . ((int)($counts['sessions'] ?? 0) === 1 ? '' : 's') . ' completed',
                 '#EEF2FF', '#4338CA', 'cal'],
                ['assessments', 'Assessment' . ((int)($counts['assessments'] ?? 0) === 1 ? '' : 's') . ' taken',
                 '#FEF3C7', '#92400E', 'check'],
                ['reviews',     'Review' . ((int)($counts['reviews'] ?? 0) === 1 ? '' : 's') . ' given',
                 '#FCE7F3', '#9D174D', 'star'],
            ];
            $pf_paths = [
                'users' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/>',
                'cal'   => '<rect x="4" y="5" width="16" height="15" rx="2.5"/><path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16"/>',
                'check' => '<circle cx="12" cy="12" r="8.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.3 2.4 2.4 4.6-4.9"/>',
                'star'  => '<path stroke-linecap="round" stroke-linejoin="round" d="m12 4 2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4L4.2 9.7l5.4-.8L12 4Z"/>',
            ];
            ?>
            <div class="pf-stats">
                <?php foreach ($pf_tiles as [$key, $label, $bg, $fg, $ico]): ?>
                    <div class="card pf-stat">
                        <div class="pf-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;">
                            <svg width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><?= $pf_paths[$ico] ?></svg>
                        </div>
                        <div>
                            <div class="pf-stat-v"><?= (int)($counts[$key] ?? 0) ?></div>
                            <div class="pf-stat-k"><?= htmlspecialchars($label) ?></div>
                            <?php if ($key === 'assessments' && $avg_score !== null): ?>
                                <div class="pf-stat-k" style="color:var(--forest);font-weight:600;"><?= (int)$avg_score ?>% average</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pf-layout">
                <!-- ═══ Left ═══ -->
                <div class="pf-col">
                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Student Info</span>
                            <?php // Edit lives in Account Settings, so this points there rather than duplicating the form.
                            ?>
                            <a href="<?= htmlspecialchars(url('mentee-settings')) ?>" class="pcard-link">Manage account →</a>
                        </div>
                        <div class="pcard-body">
                            <div class="pf-info-row"><span>Student ID</span><span><?= htmlspecialchars($info['student_id'] ?? '—') ?></span></div>
                            <div class="pf-info-row"><span>Course</span><span><?= htmlspecialchars($info['course'] ?? '—') ?></span></div>
                            <div class="pf-info-row"><span>Year Level</span><span><?= htmlspecialchars($info['year_level'] ?? '—') ?></span></div>
                            <div class="pf-info-row"><span>Section</span><span><?= htmlspecialchars($profile['section'] ?? '—') ?></span></div>
                            <div class="pf-info-row"><span>Club</span><span><?= htmlspecialchars($info['club'] ?? '—') ?></span></div>
                            <div class="pf-info-row"><span>Location</span><span><?= htmlspecialchars($profile['location'] ?? '—') ?></span></div>
                        </div>
                    </div>

                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Your Reviews Given</span>
                        </div>
                        <div class="pcard-body">
                            <?php if ((int)$reviews['total'] > 0): ?>
                                <div style="display:flex;align-items:center;gap:14px;">
                                    <div style="font-size:34px;font-weight:800;color:var(--forest);line-height:1;"><?= number_format((float)$reviews['avg_rating'], 1) ?></div>
                                    <div>
                                        <div style="display:flex;gap:2px;">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="<?= $i <= round((float)$reviews['avg_rating']) ? 'var(--gold)' : 'var(--gray-200)' ?>"><path d="M12 2.6l2.9 6 6.6.9-4.8 4.6 1.2 6.5-5.9-3.2-5.9 3.2 1.2-6.5L2.5 9.5l6.6-.9 2.9-6Z" /></svg>
                                            <?php endfor; ?>
                                        </div>
                                        <div style="font-size:11.5px;color:var(--gray-500);margin-top:3px;">
                                            Average across <?= (int)$reviews['total'] ?> review<?= (int)$reviews['total'] === 1 ? '' : 's' ?> you've given
                                        </div>
                                    </div>
                                </div>
                                <a href="<?= htmlspecialchars(url('mentee-feedback')) ?>" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center;margin-top:14px;">View My Reviews</a>
                            <?php else: ?>
                                <div class="prow-empty">You haven't reviewed a mentor yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ═══ Middle ═══ -->
                <div class="pf-col">
                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title" style="display:flex;align-items:center;gap:8px;">
                                <svg width="16" height="16" fill="none" stroke="var(--mint)" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6" /><path stroke-linecap="round" d="M5 20c.7-3.5 3.5-5.4 7-5.4s6.3 1.9 7 5.4" /></svg>
                                About Me
                            </span>
                            <button type="button" class="pcard-link" style="border:none;background:none;cursor:pointer;" onclick="toggleBox('aboutEdit')">Edit</button>
                        </div>
                        <div class="pcard-body">
                            <p style="font-size:13px;color:var(--gray-700);line-height:1.65;margin:0;">
                                <?= $bio !== '' ? nl2br(htmlspecialchars($bio)) : '<span style="color:var(--gray-400);">Tell mentors about yourself — what you\'re studying and what you want to get out of mentoring.</span>' ?>
                            </p>

                            <div style="font-size:13px;font-weight:700;color:var(--forest);margin:18px 0 10px;">My Interests</div>
                            <?php if ($tags['interest']): ?>
                                <div class="pf-tags">
                                    <?php foreach ($tags['interest'] as $i => $t): ?>
                                        <span class="pf-tag t<?= ($i % 5) + 1 ?>"><?= htmlspecialchars($t) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div style="font-size:12.5px;color:var(--gray-400);">No interests added yet.</div>
                            <?php endif; ?>
                            <?php /* Picked, not typed: matching compares these strings exactly,
                                     so a hand-typed interest matches nobody. */ ?>
                            <a href="<?= htmlspecialchars(url('onboarding')) ?>" class="pcard-link" style="display:inline-block;margin-top:10px;font-size:12.5px;">
                                <?= $tags['interest'] ? 'Change your interests' : 'Choose your interests' ?> &rarr;
                            </a>

                            <div id="aboutEdit" class="pf-edit-box" hidden>
                                <label style="display:block;font-size:12.5px;font-weight:600;color:var(--gray-700);margin-bottom:6px;">About you</label>
                                <textarea id="inBio" rows="4" maxlength="500" placeholder="A few sentences about yourself…"><?= htmlspecialchars($bio) ?></textarea>
                                <div style="display:flex;gap:10px;margin-top:12px;">
                                    <button class="btn btn-primary btn-sm" type="button" onclick="saveAbout()">Save</button>
                                    <button class="btn btn-ghost btn-sm" type="button" onclick="toggleBox('aboutEdit')">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title" style="display:flex;align-items:center;gap:8px;">
                                <svg width="16" height="16" fill="none" stroke="var(--mint)" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 20h16" /><path stroke-linecap="round" d="M7 20v-5M12 20V8M17 20v-9" /></svg>
                                Skills &amp; Interests
                            </span>
                            <a href="<?= htmlspecialchars(url('onboarding')) ?>" class="pcard-link">Edit</a>
                        </div>
                        <div class="pcard-body">
                            <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;">
                                <div>
                                    <div style="font-size:12.5px;font-weight:700;color:var(--forest);margin-bottom:9px;">Skills I'm Developing</div>
                                    <?php if ($tags['skill']): ?>
                                        <div class="pf-tags">
                                            <?php foreach ($tags['skill'] as $i => $t): ?>
                                                <span class="pf-tag t<?= ($i % 5) + 1 ?>"><?= htmlspecialchars($t) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size:12px;color:var(--gray-400);">Nothing added yet.</div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div style="font-size:12.5px;font-weight:700;color:var(--forest);margin-bottom:9px;">Areas I Want to Learn</div>
                                    <?php if ($tags['learn']): ?>
                                        <div class="pf-tags">
                                            <?php foreach ($tags['learn'] as $i => $t): ?>
                                                <span class="pf-tag t<?= (($i + 2) % 5) + 1 ?>"><?= htmlspecialchars($t) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size:12px;color:var(--gray-400);">Nothing added yet.</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php /* The questionnaire, not a text box: these two lists are
                                     what mentor matching actually compares, and it compares
                                     them exactly. Anything typed here matched nobody. The
                                     questionnaire arrives pre-filled and keeps whatever was
                                     typed before as removable tiles, so nothing is lost. */ ?>
                            <a href="<?= htmlspecialchars(url('onboarding')) ?>" class="pcard-link" style="display:inline-block;margin-top:14px;font-size:12.5px;">
                                <?= ($tags['skill'] || $tags['learn']) ? 'Change these' : 'Choose yours' ?> &rarr;
                            </a>
                        </div>
                    </div>

                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title" style="display:flex;align-items:center;gap:8px;">
                                <svg width="16" height="16" fill="none" stroke="var(--mint)" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7v5l3 2" /></svg>
                                Recent Activity
                            </span>
                            <a href="<?= htmlspecialchars(url('mentee-sessions')) ?>" class="pcard-link">View all →</a>
                        </div>
                        <div class="pcard-body">
                            <?php if (!$activity): ?>
                                <div class="prow-empty">Nothing here yet — your sessions and reviews will show up as you go.</div>
                            <?php else: ?>
                                <?php
                                $actStyle = [
                                    'session'    => ['#E9F7F0', 'var(--success)'],
                                    'review'     => ['var(--gold-light)', 'var(--gold)'],
                                    'assessment' => ['var(--mint-faint)', 'var(--mint)'],
                                    'resource'   => ['#F1EDFD', 'var(--purple, #5B4FCF)'],
                                ];
                                foreach ($activity as $a):
                                    [$bg, $fg] = $actStyle[$a['kind']] ?? ['var(--gray-100)', 'var(--gray-500)'];
                                ?>
                                    <div class="pf-act">
                                        <span class="pf-act-icon" style="background:<?= $bg ?>;color:<?= $fg ?>;">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 5 5L19 7" /></svg>
                                        </span>
                                        <span style="flex:1;min-width:0;font-size:12.5px;color:var(--gray-700);"><?= htmlspecialchars($a['text']) ?></span>
                                        <span style="font-size:11.5px;color:var(--gray-400);flex-shrink:0;"><?= htmlspecialchars(date('M j, Y', strtotime($a['ts']))) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ═══ Right ═══ -->
                <div class="pf-col">
                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Profile Completion</span>
                        </div>
                        <div class="pcard-body">
                            <div style="display:flex;align-items:center;gap:16px;margin-bottom:14px;">
                                <?php $r = 42;
                                $circ = 2 * M_PI * $r; ?>
                                <div class="pf-ring">
                                    <svg viewBox="0 0 100 100" width="96" height="96">
                                        <circle cx="50" cy="50" r="<?= $r ?>" fill="none" stroke="var(--gray-100)" stroke-width="11" />
                                        <circle cx="50" cy="50" r="<?= $r ?>" fill="none" stroke="var(--mint)" stroke-width="11" stroke-linecap="round"
                                            stroke-dasharray="<?= $circ * $pct / 100 ?> <?= $circ ?>" transform="rotate(-90 50 50)" />
                                    </svg>
                                    <div class="pf-ring-txt"><?= $pct ?>%</div>
                                </div>
                                <div>
                                    <div style="font-size:13.5px;font-weight:700;color:<?= $pct === 100 ? 'var(--success)' : 'var(--mint)' ?>;">
                                        <?= $pct === 100 ? 'All done!' : ($pct >= 60 ? 'Almost there!' : 'Getting started') ?>
                                    </div>
                                    <div style="font-size:12px;color:var(--gray-500);line-height:1.5;margin-top:2px;">
                                        <?= $pct === 100
                                            ? 'Your profile gives mentors everything they need.'
                                            : 'Complete your profile to help mentors understand your goals and interests.' ?>
                                    </div>
                                </div>
                            </div>

                            <?php foreach ($completion as [$label, $ok]): ?>
                                <div class="pf-check">
                                    <?php if ($ok): ?>
                                        <svg width="17" height="17" fill="none" stroke="var(--success)" stroke-width="2.4" viewBox="0 0 24 24" style="flex-shrink:0;"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12 2.5 2.5 4.5-5" /></svg>
                                        <span style="color:var(--gray-700);"><?= htmlspecialchars($label) ?></span>
                                    <?php else: ?>
                                        <svg width="17" height="17" fill="none" stroke="var(--gray-300)" stroke-width="2.4" viewBox="0 0 24 24" style="flex-shrink:0;"><circle cx="12" cy="12" r="9" /></svg>
                                        <span style="color:var(--gray-400);"><?= htmlspecialchars($label) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <?php if ($pct < 100): ?>
                                <button type="button" class="btn btn-primary btn-sm" style="width:100%;justify-content:center;margin-top:14px;" onclick="startCompletion()">
                                    Complete Profile →
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Upcoming Session</span>
                            <a href="<?= htmlspecialchars(url('mentee-sessions')) ?>" class="pcard-link">View all →</a>
                        </div>
                        <div class="pcard-body">
                            <?php if (!$next_session): ?>
                                <div class="prow-empty">No upcoming session booked.</div>
                                <a href="<?= htmlspecialchars(url('mentee-find')) ?>" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center;margin-top:10px;">Find a mentor</a>
                            <?php else: ?>
                                <?php
                                $s = new DateTime($next_session['session_date'], $appTz);
                                $e = (clone $s)->modify('+' . (int)$next_session['duration'] . ' minutes');
                                $mimg = trim((string)$next_session['profile_image']);
                                ?>
                                <div style="display:flex;gap:12px;align-items:center;">
                                    <?php if ($mimg !== ''): ?>
                                        <img src="<?= htmlspecialchars($mimg) ?>" alt="" style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                                    <?php else: ?>
                                        <div class="pf-avatar" style="width:44px;height:44px;font-size:17px;border:none;"><?= htmlspecialchars(strtoupper(substr($next_session['mentor_name'], 0, 1))) ?></div>
                                    <?php endif; ?>
                                    <div style="min-width:0;">
                                        <div style="font-size:13.5px;font-weight:700;color:var(--forest);"><?= htmlspecialchars($next_session['subject']) ?></div>
                                        <div style="font-size:11.5px;color:var(--gray-500);"><?= htmlspecialchars($next_session['mentor_name']) ?> · Mentor</div>
                                    </div>
                                </div>
                                <div style="font-size:12px;color:var(--gray-600);margin-top:12px;line-height:1.8;">
                                    <div>📅 <?= $s->format('F j, Y') ?></div>
                                    <div>🕒 <?= $s->format('g:i A') ?> – <?= $e->format('g:i A') ?></div>
                                </div>
                                <a href="<?= htmlspecialchars(url('video-join')) ?>?session_id=<?= (int)$next_session['request_id'] ?>" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center;margin-top:12px;">View Session</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">My Achievements</span>
                            <span style="font-size:11.5px;color:var(--gray-500);"><?= count($earned) ?>/<?= count($milestones) ?></span>
                        </div>
                        <div class="pcard-body">
                            <?php foreach ($milestones as [$name, $desc, $ok, $ico]): ?>
                                <div class="pf-mile">
                                    <span class="pf-mile-icon" style="background:<?= $ok ? 'var(--gold-light)' : 'var(--gray-100)' ?>;color:<?= $ok ? 'var(--gold)' : 'var(--gray-300)' ?>;">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 4h10v5a5 5 0 0 1-10 0V4Z" /><path stroke-linecap="round" d="M7 6H4.5v1.5A3.5 3.5 0 0 0 8 11M17 6h2.5v1.5A3.5 3.5 0 0 1 16 11M12 14v4M8.5 21h7" /></svg>
                                    </span>
                                    <div style="min-width:0;">
                                        <div style="font-size:12.5px;font-weight:700;color:<?= $ok ? 'var(--forest)' : 'var(--gray-400)' ?>;"><?= htmlspecialchars($name) ?></div>
                                        <div style="font-size:11px;color:var(--gray-500);"><?= htmlspecialchars($desc) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <?php
    /*
     * Edit Profile, the same five fields the mentor's modal offers and the
     * same endpoint they have always posted to — mentee-update-profile
     * already accepts full_name, student_id, course, year_level and club.
     * Nothing new is saved here; it is the form that moved, out of Settings
     * and onto the page the values are shown on.
     */
    ?>
    <div class="modal-overlay" id="editModal" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <div class="modal-box" style="max-width:520px;">
            <div class="modal-hd">
                <h2 class="modal-hd-title" id="editModalTitle">Edit Profile</h2>
                <button type="button" class="modal-close" onclick="closeEditModal()" aria-label="Close">&times;</button>
            </div>
            <form id="pfEditForm" onsubmit="saveProfile(event)">
                <div style="padding:18px 20px;display:flex;flex-direction:column;gap:14px;">
                    <div>
                        <label class="form-label" for="pfFullName">Full name <span style="color:var(--danger);">*</span></label>
                        <input class="form-input" type="text" id="pfFullName" name="full_name" maxlength="120" required
                               value="<?= htmlspecialchars($full_name) ?>">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                        <div>
                            <label class="form-label" for="pfStudentId">Student ID <span style="color:var(--danger);">*</span></label>
                            <input class="form-input" type="text" id="pfStudentId" name="student_id" maxlength="40" required
                                   value="<?= htmlspecialchars($info['student_id'] ?? '') ?>">
                        </div>
                        <div>
                            <label class="form-label" for="pfYearLevel">Year level <span style="color:var(--danger);">*</span></label>
                            <input class="form-input" type="text" id="pfYearLevel" name="year_level" maxlength="40" required
                                   value="<?= htmlspecialchars($info['year_level'] ?? '') ?>">
                        </div>
                    </div>
                    <div>
                        <label class="form-label" for="pfCourse">Course / Program <span style="color:var(--danger);">*</span></label>
                        <input class="form-input" type="text" id="pfCourse" name="course" maxlength="160" required
                               value="<?= htmlspecialchars($info['course'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="form-label" for="pfClub">Club / Organization <span style="color:var(--gray-400);font-weight:400;">(optional)</span></label>
                        <input class="form-input" type="text" id="pfClub" name="club" maxlength="120"
                               value="<?= htmlspecialchars($info['club'] ?? '') ?>">
                    </div>
                    <div id="pfEditErr" style="display:none;font-size:12.5px;color:var(--danger);"></div>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:10px;padding:0 20px 18px;">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="pfEditSave">Save changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const PF_CSRF = <?= json_encode($csrf) ?>;
        const PF_UPDATE_URL = <?= json_encode(url('mentee-update-profile')) ?>;

        function openEditModal() {
            document.getElementById('editModal').classList.add('open');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('open');
            document.getElementById('pfEditErr').style.display = 'none';
        }

        /* The endpoint answers JSON either way, so a failure has to be read
           out of the reply rather than assumed from the request going through. */
        function saveProfile(e) {
            e.preventDefault();
            const btn = document.getElementById('pfEditSave');
            const err = document.getElementById('pfEditErr');
            const fd  = new FormData(document.getElementById('pfEditForm'));
            fd.append('csrf_token', PF_CSRF);

            btn.disabled = true;
            btn.textContent = 'Saving…';
            err.style.display = 'none';

            fetch(PF_UPDATE_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (d.success) { location.reload(); return; }
                    err.textContent = d.message || 'Could not save your changes.';
                    err.style.display = '';
                    btn.disabled = false;
                    btn.textContent = 'Save changes';
                })
                .catch(() => {
                    err.textContent = 'Could not reach the server. Check your connection and try again.';
                    err.style.display = '';
                    btn.disabled = false;
                    btn.textContent = 'Save changes';
                });
        }

        /* The photo goes through the same endpoint, carrying the identity
           fields it validates before it will look at the file. */
        function uploadPhoto() {
            const form = document.getElementById('photoForm');
            fetch(PF_UPDATE_URL, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (d.success) { location.reload(); return; }
                    // flash() is this page's own banner helper, declared below.
                    flash(d.message || 'Could not update your photo.', false);
                })
                .catch(() => flash('Could not reach the server. Check your connection and try again.', false));
        }

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

        function toggleBox(id) {
            const el = document.getElementById(id);
            el.hidden = !el.hidden;
            if (!el.hidden) el.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }

        function flash(msg, ok) {
            const el = document.getElementById('pfAlert');
            el.textContent = msg;
            el.style.display = 'block';
            el.style.background = ok ? 'var(--success-bg, #E8F5EF)' : 'var(--danger-bg)';
            el.style.color = ok ? 'var(--success)' : 'var(--danger)';
        }

        async function pfSave(fields) {
            const body = new URLSearchParams(Object.assign({
                csrf_token: PF_CSRF
            }, fields));
            const res = await fetch('<?= url('profile-save') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: body.toString()
            });
            return res.json();
        }

        async function saveAbout() {
            try {
                const d = await pfSave({
                    section: 'about',
                    bio: document.getElementById('inBio').value
                });
                flash(d.success ? 'About Me updated.' : d.message, !!d.success);
                if (d.success) setTimeout(() => location.reload(), 700);
            } catch (e) {
                flash('Network error. Please try again.', false);
            }
        }

        // Opens whichever step is still missing, rather than being a dead button.
        function startCompletion() {
            // The questionnaire fills interests, skills and subjects in one
            // pass, so it comes before the individual editors.
            const needsQuestionnaire = <?= $onboarding['done'] ? 'false' : 'true' ?>;
            if (needsQuestionnaire) {
                window.location.href = <?= json_encode(url('onboarding')) ?>;
                return;
            }
            // Tags are picked in the questionnaire now, so anything missing
            // there is sent back to it rather than to an editor that can no
            // longer set them.
            const needsTags = <?= (!$tags['interest'] || (!$tags['skill'] && !$tags['learn'])) ? 'true' : 'false' ?>;
            const needsBio = <?= $bio === '' ? 'true' : 'false' ?>;
            const needsPhoto = <?= empty($profile_image) ? 'true' : 'false' ?>;
            if (needsTags) {
                window.location.href = <?= json_encode(url('onboarding')) ?>;
                return;
            }
            if (needsBio) return toggleBox('aboutEdit');
            if (needsPhoto) return document.getElementById('photoInput').click();
            window.location.href = <?= json_encode(url('mentee-settings')) ?>;
        }
    </script>
</body>

</html>
