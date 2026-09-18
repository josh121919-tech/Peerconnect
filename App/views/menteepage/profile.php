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

        /* ── Identity card ── */
        .pf-hero {
            background: linear-gradient(160deg, var(--forest) 0%, #0A1560 100%);
            border-radius: var(--radius-lg);
            padding: 26px 20px 22px;
            text-align: center;
            color: #fff;
        }

        .pf-avatar-wrap {
            position: relative;
            width: 104px;
            margin: 0 auto 14px;
        }

        .pf-avatar {
            width: 104px;
            height: 104px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255, 255, 255, .18);
            background: var(--mint-faint);
            color: var(--forest);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            font-weight: 700;
        }

        .pf-cam {
            position: absolute;
            right: 2px;
            bottom: 2px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--mint);
            color: #fff;
            border: 3px solid var(--forest);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .pf-hero-name {
            font-size: 18px;
            font-weight: 700;
        }

        .pf-hero-chip {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            background: rgba(255, 255, 255, .16);
            border-radius: 999px;
            padding: 3px 11px;
            margin: 7px 0 6px;
        }

        .pf-hero-mail {
            font-size: 12px;
            color: rgba(255, 255, 255, .6);
        }

        .pf-hero-quote {
            font-size: 12.5px;
            font-style: italic;
            color: rgba(255, 255, 255, .85);
            margin-top: 10px;
            line-height: 1.5;
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
        .pf-chipin {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 8px;
            background: var(--surface);
        }

        .pf-chipin:focus-within {
            border-color: var(--mint-soft);
        }

        .pf-chipin-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .pf-chipin-list:not(:empty) {
            margin-bottom: 8px;
        }

        .pf-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            max-width: 100%;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.45;
            background: var(--mint-faint);
            color: var(--mint);
            border-radius: 12px;
            padding: 4px 5px 4px 11px;
        }

        .pf-chip-x {
            border: 0;
            background: transparent;
            color: inherit;
            cursor: pointer;
            font-size: 15px;
            line-height: 1;
            padding: 1px 5px;
            border-radius: 50%;
        }

        .pf-chip-x:hover {
            background: rgba(0, 135, 207, .16);
        }

        .pf-edit-box .pf-chipin input {
            border: 0;
            border-radius: 0;
            padding: 4px 3px;
        }

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

            <div class="page-hd" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <div>
                    <h1>My Profile</h1>
                    <p>Your account information and feedback overview</p>
                </div>
                <a href="<?= htmlspecialchars(url('mentee-settings')) ?>" class="btn btn-ghost btn-sm" style="margin-top:4px;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20 16.5 7.5l3 3L7 23H4v-3Z" /></svg>
                    Edit Profile
                </a>
            </div>

            <div id="pfAlert" style="display:none;border-radius:var(--radius);padding:10px 14px;font-size:12.5px;margin-bottom:14px;"></div>

            <div class="pf-layout">
                <!-- ═══ Left ═══ -->
                <div class="pf-col">
                    <div class="pf-hero">
                        <form id="photoForm" method="POST" action="<?= htmlspecialchars(url('mentee-update-profile')) ?>" enctype="multipart/form-data" style="display:none;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="file" name="profile_image" id="photoInput" accept="image/*" onchange="document.getElementById('photoForm').submit()">
                        </form>
                        <div class="pf-avatar-wrap">
                            <?php if ($profile_image): ?>
                                <img class="pf-avatar" src="<?= htmlspecialchars($profile_image) ?>" alt="">
                            <?php else: ?>
                                <div class="pf-avatar"><?= htmlspecialchars(strtoupper(substr($full_name, 0, 1))) ?></div>
                            <?php endif; ?>
                            <button type="button" class="pf-cam" onclick="document.getElementById('photoInput').click()" aria-label="Change profile photo">
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8h3l1.5-2h7L17 8h3v11H4V8Z" /><circle cx="12" cy="13" r="3" /></svg>
                            </button>
                        </div>
                        <div class="pf-hero-name"><?= htmlspecialchars($full_name) ?></div>
                        <div class="pf-hero-chip">Mentee</div>
                        <div class="pf-hero-mail"><?= htmlspecialchars($account['email']) ?></div>
                        <?php if ($bio !== ''): ?>
                            <div class="pf-hero-quote">“<?= htmlspecialchars(mb_strimwidth($bio, 0, 90, '…')) ?>”</div>
                        <?php endif; ?>
                    </div>

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

                            <div id="aboutEdit" class="pf-edit-box" hidden>
                                <label style="display:block;font-size:12.5px;font-weight:600;color:var(--gray-700);margin-bottom:6px;">About you</label>
                                <textarea id="inBio" rows="4" maxlength="500" placeholder="A few sentences about yourself…"><?= htmlspecialchars($bio) ?></textarea>
                                <label style="display:block;font-size:12.5px;font-weight:600;color:var(--gray-700);margin:12px 0 6px;">Interests <span style="font-weight:400;color:var(--gray-400);">— press Enter to add</span></label>
                                <div class="pf-chipin" id="inInterests">
                                    <div class="pf-chipin-list">
                                        <?php foreach ($tags['interest'] as $t): ?>
                                            <span class="pf-chip" data-tag="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?><button type="button" class="pf-chip-x" aria-label="Remove <?= htmlspecialchars($t) ?>">&times;</button></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="text" maxlength="120" placeholder="Type an interest and press Enter">
                                </div>
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
                            <button type="button" class="pcard-link" style="border:none;background:none;cursor:pointer;" onclick="toggleBox('skillsEdit')">Edit</button>
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

                            <div id="skillsEdit" class="pf-edit-box" hidden>
                                <label style="display:block;font-size:12.5px;font-weight:600;color:var(--gray-700);margin:0 0 6px;">Skills I'm developing <span style="font-weight:400;color:var(--gray-400);">— press Enter to add</span></label>
                                <div class="pf-chipin" id="inSkills">
                                    <div class="pf-chipin-list">
                                        <?php foreach ($tags['skill'] as $t): ?>
                                            <span class="pf-chip" data-tag="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?><button type="button" class="pf-chip-x" aria-label="Remove <?= htmlspecialchars($t) ?>">&times;</button></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="text" maxlength="120" placeholder="Type a skill and press Enter">
                                </div>
                                <label style="display:block;font-size:12.5px;font-weight:600;color:var(--gray-700);margin:12px 0 6px;">Areas I want to learn <span style="font-weight:400;color:var(--gray-400);">— press Enter to add</span></label>
                                <div class="pf-chipin" id="inLearn">
                                    <div class="pf-chipin-list">
                                        <?php foreach ($tags['learn'] as $t): ?>
                                            <span class="pf-chip" data-tag="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?><button type="button" class="pf-chip-x" aria-label="Remove <?= htmlspecialchars($t) ?>">&times;</button></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="text" maxlength="120" placeholder="Type a subject and press Enter">
                                </div>
                                <div style="display:flex;gap:10px;margin-top:12px;">
                                    <button class="btn btn-primary btn-sm" type="button" onclick="saveSkills()">Save</button>
                                    <button class="btn btn-ghost btn-sm" type="button" onclick="toggleBox('skillsEdit')">Cancel</button>
                                </div>
                            </div>
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

    <script>
        const PF_CSRF = <?= json_encode($csrf) ?>;

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

        // ── Chip editors ────────────────────────────────────────────────
        // Tags are held as chips, never as one comma-joined string: several
        // questionnaire answers contain a comma, and re-saving a joined string
        // would split them into two tags.
        function pfChips(boxId) {
            return Array.from(document.querySelectorAll('#' + boxId + ' .pf-chip'))
                .map(c => c.dataset.tag);
        }

        function pfAddChip(box, value) {
            const tag = value.trim().replace(/\s+/g, ' ').slice(0, 120);
            if (!tag) return;
            const list = box.querySelector('.pf-chipin-list');
            const dupe = Array.from(list.querySelectorAll('.pf-chip'))
                .some(c => c.dataset.tag.toLowerCase() === tag.toLowerCase());
            if (dupe) return;

            const chip = document.createElement('span');
            chip.className = 'pf-chip';
            chip.dataset.tag = tag;
            chip.textContent = tag;
            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'pf-chip-x';
            x.setAttribute('aria-label', 'Remove ' + tag);
            x.innerHTML = '&times;';
            chip.appendChild(x);
            list.appendChild(chip);
        }

        document.addEventListener('click', e => {
            const x = e.target.closest('.pf-chip-x');
            if (x) x.closest('.pf-chip').remove();
        });

        document.addEventListener('keydown', e => {
            if (!e.target.matches('.pf-chipin input')) return;
            // Comma commits too, so old muscle memory still works.
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                pfAddChip(e.target.closest('.pf-chipin'), e.target.value);
                e.target.value = '';
            } else if (e.key === 'Backspace' && e.target.value === '') {
                const chips = e.target.closest('.pf-chipin').querySelectorAll('.pf-chip');
                if (chips.length) chips[chips.length - 1].remove();
            }
        });

        // Text left in the box when Save is pressed still counts.
        function pfCommitPending(boxId) {
            const box = document.getElementById(boxId);
            const input = box.querySelector('input');
            if (input.value.trim()) {
                pfAddChip(box, input.value);
                input.value = '';
            }
            return JSON.stringify(pfChips(boxId));
        }

        async function saveAbout() {
            try {
                const d = await pfSave({
                    section: 'about',
                    bio: document.getElementById('inBio').value,
                    interests: pfCommitPending('inInterests')
                });
                flash(d.success ? 'About Me updated.' : d.message, !!d.success);
                if (d.success) setTimeout(() => location.reload(), 700);
            } catch (e) {
                flash('Network error. Please try again.', false);
            }
        }

        async function saveSkills() {
            try {
                const d = await pfSave({
                    section: 'skills',
                    skills: pfCommitPending('inSkills'),
                    learn: pfCommitPending('inLearn')
                });
                flash(d.success ? 'Skills updated.' : d.message, !!d.success);
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
            const needsAbout = <?= ($bio === '' || !$tags['interest']) ? 'true' : 'false' ?>;
            const needsSkills = <?= (!$tags['skill'] && !$tags['learn']) ? 'true' : 'false' ?>;
            const needsPhoto = <?= empty($profile_image) ? 'true' : 'false' ?>;
            if (needsAbout) return toggleBox('aboutEdit');
            if (needsSkills) return toggleBox('skillsEdit');
            if (needsPhoto) return document.getElementById('photoInput').click();
            window.location.href = <?= json_encode(url('mentee-settings')) ?>;
        }
    </script>
</body>

</html>
