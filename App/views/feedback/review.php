<?php
// Rate & Review — one page, both directions.
//   mentee → rates the mentor  (writes `feedback`)
//   mentor → rates the mentee  (writes `mentee_reviews`)
// A session only becomes reviewable once it has actually finished, and only
// for the two people who were in it.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['mentee', 'mentor'], true)) {
    header("Location: " . url('welcomepage'));
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$is_mentee = $role === 'mentee';


// ── The five aspects, per direction ──────────────────────────────────────
// Mentee→mentor reuses the columns the feedback table has always had; only
// the labels changed, so every existing rating still means what it meant.
$aspects = $is_mentee
    ? [
        'communication' => ['Communication', 'How clearly and effectively did your mentor communicate?', 'chat'],
        'efficiency'    => ['Interaction & Engagement', 'How engaging and interactive was your mentor?', 'people'],
        'knowledge'     => ['Knowledge & Expertise', 'How knowledgeable was your mentor on the topic?', 'bulb'],
        'skill'         => ['Guidance & Support', 'How supportive and helpful was your mentor?', 'target'],
        'rating'        => ['Overall Experience', 'Overall, how would you rate your mentoring experience?', 'chart'],
    ]
    : [
        'preparedness'  => ['Preparedness', 'Did your mentee come to the session ready?', 'chat'],
        'participation' => ['Participation & Engagement', 'How actively did your mentee take part?', 'people'],
        'communication' => ['Communication', 'How clearly did your mentee explain what they needed?', 'bulb'],
        'receptiveness' => ['Openness to Guidance', 'How well did your mentee act on your guidance?', 'target'],
        'rating'        => ['Overall Experience', 'Overall, how would you rate this mentoring session?', 'chart'],
    ];

$rating_words = [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very Good', 5 => 'Excellent'];

// ── Sessions this person is allowed to review ────────────────────────────
// "Allowed" = they were in it, it was approved/completed, and its end time
// has already passed. The end time is worked out by MySQL rather than PHP:
// session_date is written on the database clock and PHP's timezone here is a
// different one, so comparing the two in PHP shifts the cut-off by hours.
$sessions = FeedbackRepository::reviewableSessions($con, $user_id, $is_mentee);

// Which of them this person has already reviewed.
$reviewed = FeedbackRepository::reviewsByAuthor($con, $user_id, $is_mentee, array_column($sessions, 'request_id'));

$pending = array_values(array_filter($sessions, fn($s) => !isset($reviewed[(int)$s['request_id']])));
$done    = array_values(array_filter($sessions, fn($s) => isset($reviewed[(int)$s['request_id']])));

// ── Which session are we looking at? ─────────────────────────────────────
// Accepts ?session= (this page) or ?session_id= (the post-video-call redirect).
$requested = $_GET['session'] ?? $_GET['session_id'] ?? 0;
$requested = is_string($requested) ? (int)$requested : 0;
$session   = null;
foreach ($sessions as $s) {
    if ((int)$s['request_id'] === $requested) {
        $session = $s;
        break;
    }
}
if (!$session) {
    $session = $pending[0] ?? ($done[0] ?? null);
}

$session_id   = $session ? (int)$session['request_id'] : 0;
$existing     = $session ? ($reviewed[$session_id] ?? null) : null;
$is_read_only = $existing !== null;

// A saved draft pre-fills the form until it is actually submitted.
$draft = null;
if ($session && !$is_read_only) {
    $direction = $is_mentee ? 'mentee_to_mentor' : 'mentor_to_mentee';
    $payload   = FeedbackRepository::draftPayload($con, $session_id, $user_id, $direction);
    if ($payload !== null) {
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) $draft = $decoded;
    }
}

// Starting values for each field.
$values = [];
foreach ($aspects as $key => $_) {
    $values[$key]         = (int)round((float)($existing[$key] ?? $draft[$key] ?? 0));
    $values['note_' . $key] = (string)($existing['note_' . $key] ?? $draft['note_' . $key] ?? '');
}
// The overall star column is `rating`; its per-aspect note is note_overall.
$values['note_rating'] = (string)($existing['note_overall'] ?? $draft['note_overall'] ?? $values['note_rating'] ?? '');
$values['comment']     = (string)($existing['comment'] ?? $draft['comment'] ?? '');

// ── The other side's review of this same session (the second tab) ────────
$counterpart = $session ? FeedbackRepository::counterpartReview($con, $session_id, (int)$session['other_id'], $is_mentee) : null;
$counter_aspects = $is_mentee
    ? [
        'preparedness'  => 'Preparedness',
        'participation' => 'Participation & Engagement',
        'communication' => 'Communication',
        'receptiveness' => 'Openness to Guidance',
        'rating'        => 'Overall Experience',
    ]
    : [
        'communication' => 'Communication',
        'efficiency'    => 'Interaction & Engagement',
        'knowledge'     => 'Knowledge & Expertise',
        'skill'         => 'Guidance & Support',
        'rating'        => 'Overall Experience',
    ];

// ── Presentation helpers ─────────────────────────────────────────────────
$tz = new DateTimeZone('Asia/Manila');
function rv_range(?array $s, DateTimeZone $tz): array
{
    if (!$s) return ['', '', ''];
    $start = new DateTime($s['session_date'], $tz);
    $end   = (clone $start)->modify('+' . (int)$s['duration'] . ' minutes');
    return [$start->format('F j, Y'), $start->format('g:i A') . ' - ' . $end->format('g:i A'), (int)$s['duration']];
}
[$s_date, $s_time, $s_dur] = rv_range($session, $tz);

$other_label   = $is_mentee ? 'Mentor' : 'Mentee';
$counter_label = $is_mentee ? 'Mentor assessing you' : 'Mentee assessing you';
$page_title    = $is_mentee ? 'Rate & Review Your Mentor' : 'Rate & Review Your Mentee';
$page_sub      = $is_mentee
    ? 'Share your honest feedback to help your mentor grow and improve.'
    : 'Share your honest feedback to help your mentee grow and improve.';
$back_url      = $is_mentee ? url('mentee-sessions') : url('mentor-requests');

$icon_paths = [
    'chat'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M20 12a7 7 0 0 1-7 7H9l-4 3v-4.3A7 7 0 0 1 13 5a7 7 0 0 1 7 7Z"/>',
    'people' => '<circle cx="9" cy="9" r="3"/><circle cx="17" cy="10" r="2.4"/><path stroke-linecap="round" d="M3.5 19c.6-2.7 2.8-4.2 5.5-4.2s4.9 1.5 5.5 4.2M16 15.2c2.1.2 3.6 1.5 4 3.3"/>',
    'bulb'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.5 18h5M10.5 21h3M12 3a6 6 0 0 1 4 10.5V15H8v-1.5A6 6 0 0 1 12 3Z"/>',
    'target' => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4"/><circle cx="12" cy="12" r="1"/>',
    'chart'  => '<path stroke-linecap="round" d="M4 20h16"/><path stroke-linecap="round" d="M7 20v-5M12 20V8M17 20v-9"/>',
];

$active_page = 'feedback';
$csrf        = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        .rv-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 288px;
            gap: 18px;
            align-items: start;
        }

        /* ── Header actions: History + direction toggle ── */
        .rv-hd-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 4px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .rv-toggle {
            display: inline-flex;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--surface);
            overflow: hidden;
            flex-shrink: 0;
        }

        .rv-toggle a {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 16px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--gray-500);
            text-decoration: none;
            white-space: nowrap;
            transition: background .14s, color .14s;
        }

        .rv-toggle a.active {
            background: var(--mint-faint);
            color: var(--mint);
            box-shadow: inset 0 0 0 1px var(--mint-soft);
        }

        .rv-toggle a:hover:not(.active) {
            background: var(--gray-50);
            color: var(--forest);
        }

        /* ── Session identity strip ── */
        .rv-who {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-bottom: 16px;
        }

        .rv-avatar {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--mint-faint);
            color: var(--forest);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .rv-who-facts {
            display: flex;
            align-items: center;
            gap: 26px;
            flex-wrap: wrap;
            padding-left: 22px;
            border-left: 1px solid var(--border);
        }

        .rv-fact-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            color: var(--gray-500);
            margin-bottom: 3px;
        }

        .rv-fact-value {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--forest);
            max-width: 150px;
        }

        .rv-chip {
            display: inline-block;
            font-size: 10.5px;
            font-weight: 700;
            color: var(--gray-600);
            background: var(--gray-100);
            border-radius: 999px;
            padding: 3px 9px;
        }

        /* ── Aspect rows ── */
        .rv-aspect {
            display: grid;
            grid-template-columns: 250px 150px minmax(0, 1fr);
            gap: 18px;
            align-items: start;
            padding: 18px 0;
            border-bottom: 1px solid var(--border);
        }

        .rv-aspect:last-child {
            border-bottom: none;
            padding-bottom: 4px;
        }

        .rv-aspect-head {
            display: flex;
            gap: 12px;
        }

        .rv-aspect-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: var(--mint-faint);
            color: var(--mint);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .rv-aspect-icon svg {
            width: 17px;
            height: 17px;
        }

        .rv-aspect-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--forest);
            margin-bottom: 2px;
        }

        .rv-aspect-desc {
            font-size: 11.5px;
            color: var(--gray-500);
            line-height: 1.45;
        }

        /* ── Stars ── */
        .rv-stars {
            display: flex;
            gap: 4px;
        }

        .rv-star {
            border: none;
            background: none;
            padding: 0;
            cursor: pointer;
            line-height: 1;
            color: var(--gray-200);
            transition: color .12s, transform .12s;
        }

        .rv-star svg {
            width: 21px;
            height: 21px;
            display: block;
        }

        .rv-star.on {
            color: var(--gold);
        }

        .rv-star:hover:not(:disabled) {
            transform: scale(1.12);
        }

        .rv-star:disabled {
            cursor: default;
        }

        .rv-star-word {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--success);
            margin-top: 7px;
            min-height: 14px;
        }

        .rv-note {
            width: 100%;
            min-height: 62px;
            resize: vertical;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 10px 12px;
            font-family: inherit;
            font-size: 12.5px;
            color: var(--gray-800);
            background: var(--surface);
        }

        .rv-note:focus {
            outline: none;
            border-color: var(--mint-soft);
        }

        .rv-note:disabled {
            background: var(--gray-50);
            color: var(--gray-600);
        }

        .rv-count {
            text-align: right;
            font-size: 10.5px;
            color: var(--gray-400);
            margin-top: 3px;
        }

        /* ── Overall block ── */
        .rv-overall {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 240px;
            gap: 22px;
            align-items: start;
        }

        .rv-overall textarea {
            width: 100%;
            min-height: 96px;
            resize: vertical;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 12px 14px;
            font-family: inherit;
            font-size: 13px;
            color: var(--gray-800);
            background: var(--surface);
        }

        .rv-overall textarea:focus {
            outline: none;
            border-color: var(--mint-soft);
        }

        .rv-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .rv-submit {
            width: 100%;
            justify-content: center;
            background: var(--success);
            border-color: var(--success);
            color: #fff;
        }

        .rv-submit:hover {
            filter: brightness(.94);
        }

        .rv-private {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            color: var(--gray-400);
            margin-top: 12px;
        }

        /* ── Right rail ── */
        .rv-summary-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            font-size: 12.5px;
            padding: 7px 0;
        }

        .rv-summary-row span:first-child {
            color: var(--gray-500);
        }

        .rv-summary-row span:last-child {
            color: var(--forest);
            font-weight: 600;
            text-align: right;
        }

        .rv-guide-row {
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
        }

        .rv-guide-row:last-child {
            border-bottom: none;
        }

        .rv-guide-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .rv-guide-name {
            font-size: 12px;
            font-weight: 700;
            color: var(--success);
        }

        .rv-guide-desc {
            font-size: 11px;
            color: var(--gray-500);
            margin-top: 1px;
        }

        .rv-flash {
            border-radius: var(--radius);
            padding: 11px 16px;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .rv-flash-success {
            background: var(--success-bg, #E8F5EF);
            color: var(--success);
        }

        .rv-flash-error {
            background: var(--danger-bg);
            color: var(--danger);
        }

        @media (max-width: 1180px) {
            .rv-layout {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 860px) {
            .rv-aspect {
                grid-template-columns: minmax(0, 1fr);
                gap: 10px;
            }

            .rv-overall {
                grid-template-columns: minmax(0, 1fr);
            }

            .rv-who-facts {
                padding-left: 0;
                border-left: none;
                gap: 18px;
            }
        }

        /* The two toggle labels are long; at phone width they get the full
           row and share it evenly instead of pushing the page sideways. */
        @media (max-width: 700px) {
            .rv-hd-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .rv-toggle {
                display: flex;
                width: 100%;
                flex-shrink: 1;
            }

            .rv-toggle a {
                flex: 1 1 0;
                min-width: 0;
                justify-content: center;
                padding: 10px 8px;
                font-size: 11.5px;
                white-space: normal;
                text-align: center;
                line-height: 1.3;
            }

            .rv-toggle a svg {
                flex-shrink: 0;
            }

            #rvSessionPicker {
                width: 100%;
                max-width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/../includes/app_shell.php'; ?>

        <main class="main fade-in">

            <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-ghost btn-sm" style="margin-bottom:16px;">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 12H5m0 0 5 5m-5-5 5-5" /></svg>
                Back to Sessions
            </a>

            <div class="page-hd" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <div>
                    <h1><?= htmlspecialchars($page_title) ?></h1>
                    <p><?= htmlspecialchars($page_sub) ?></p>
                </div>
                <?php if ($session): ?>
                    <?php $tab = ($_GET['tab'] ?? 'mine') === 'theirs' ? 'theirs' : 'mine'; ?>
                    <div class="rv-hd-actions">
                        <?php if ($done): ?>
                            <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('historyModal').classList.add('open')">
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 3" /></svg>
                                History
                                <span class="rv-chip" style="margin-left:2px;"><?= count($done) ?></span>
                            </button>
                        <?php endif; ?>
                        <div class="rv-toggle">
                            <a href="?session=<?= $session_id ?>" class="<?= $tab === 'mine' ? 'active' : '' ?>">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.4" /><path stroke-linecap="round" d="M5 20c.7-3.4 3.5-5.2 7-5.2s6.3 1.8 7 5.2" /></svg>
                                You're assessing your <?= strtolower($other_label) ?>
                            </a>
                            <a href="?session=<?= $session_id ?>&tab=theirs" class="<?= $tab === 'theirs' ? 'active' : '' ?>">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="9" cy="9" r="3" /><circle cx="17" cy="10" r="2.4" /><path stroke-linecap="round" d="M3.5 19c.6-2.7 2.8-4.2 5.5-4.2s4.9 1.5 5.5 4.2" /></svg>
                                <?= htmlspecialchars($counter_label) ?>
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php $tab = 'mine'; ?>
                <?php endif; ?>
            </div>


            <?php if (!$session): ?>

                <div class="pcard">
                    <div class="pcard-body" style="text-align:center;padding:44px 20px;">
                        <div style="font-size:15px;font-weight:700;color:var(--forest);margin-bottom:6px;">
                            No sessions to review yet
                        </div>
                        <p style="font-size:13px;color:var(--gray-500);max-width:430px;margin:0 auto 18px;">
                            <?= $is_mentee
                                ? 'Feedback opens once one of your sessions has finished. Book a session and come back here afterwards.'
                                : 'Feedback opens once one of your sessions has finished. Approved sessions will appear here after they end.' ?>
                        </p>
                        <a href="<?= htmlspecialchars($is_mentee ? url('mentee-find') : url('mentor-calendar')) ?>" class="btn btn-primary btn-sm">
                            <?= $is_mentee ? 'Find a mentor' : 'Open your calendar' ?>
                        </a>
                    </div>
                </div>

            <?php else: ?>

                <!-- Who / when -->
                <div class="rv-who">
                    <?php
                    $img = trim((string)($session['profile_image'] ?? ''));
                    $initial = strtoupper(substr($session['other_name'], 0, 1));
                    ?>
                    <?php if ($img !== ''): ?>
                        <img class="rv-avatar" src="<?= htmlspecialchars($img) ?>" alt="">
                    <?php else: ?>
                        <div class="rv-avatar"><?= htmlspecialchars($initial) ?></div>
                    <?php endif; ?>

                    <div style="min-width:150px;">
                        <div style="display:flex;align-items:center;gap:9px;margin-bottom:3px;">
                            <span style="font-size:17px;font-weight:700;color:var(--forest);"><?= htmlspecialchars($session['other_name']) ?></span>
                            <span class="rv-chip"><?= htmlspecialchars($other_label) ?></span>
                        </div>
                        <div style="font-size:12.5px;color:var(--gray-500);"><?= htmlspecialchars($session['club'] ?: 'No club listed') ?></div>
                    </div>

                    <div class="rv-who-facts">
                        <div>
                            <div class="rv-fact-label">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 8v4l2.5 2" /></svg>
                                Session Topic
                            </div>
                            <div class="rv-fact-value"><?= htmlspecialchars($session['subject']) ?></div>
                        </div>
                        <div>
                            <div class="rv-fact-label">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15" rx="2" /><path stroke-linecap="round" d="M3.5 9.5h17M8 3v4M16 3v4" /></svg>
                                Date
                            </div>
                            <div class="rv-fact-value"><?= htmlspecialchars($s_date) ?></div>
                        </div>
                        <div>
                            <div class="rv-fact-label">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7v5l3 2" /></svg>
                                Time
                            </div>
                            <div class="rv-fact-value"><?= htmlspecialchars($s_time) ?></div>
                        </div>
                        <div>
                            <div class="rv-fact-label">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="12" rx="2" /><path stroke-linecap="round" d="M8 21h8" /></svg>
                                Type
                            </div>
                            <div class="rv-fact-value"><?= $session['session_type'] === 'group' ? 'Group Session' : '1-on-1 Session' ?></div>
                        </div>
                    </div>
                </div>

                <?php if (count($sessions) > 1): ?>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
                        <label for="rvSessionPicker" style="font-size:12px;color:var(--gray-500);">Reviewing</label>
                        <select id="rvSessionPicker" class="ac-select" style="max-width:420px;"
                            onchange="location.href='?session=' + this.value<?= $tab === 'theirs' ? " + '&tab=theirs'" : '' ?>;">
                            <?php foreach ($sessions as $s):
                                $sid = (int)$s['request_id'];
                                [$d, , ] = rv_range($s, $tz);
                            ?>
                                <option value="<?= $sid ?>" <?= $sid === $session_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['other_name'] . ' · ' . $s['subject'] . ' · ' . $d) ?>
                                    <?= isset($reviewed[$sid]) ? ' (reviewed)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($pending): ?>
                            <span class="rv-chip" style="background:var(--mint-faint);color:var(--mint);">
                                <?= count($pending) ?> awaiting your feedback
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="rv-layout">
                    <div>
                        <?php if ($tab === 'theirs'): ?>

                            <!-- ══ The other side's review of this session ══ -->
                            <div class="pcard">
                                <div class="pcard-hd">
                                    <span class="pcard-title"><?= htmlspecialchars($counter_label) ?></span>
                                </div>
                                <div class="pcard-body">
                                    <?php if (!$counterpart): ?>
                                        <p style="font-size:13px;color:var(--gray-500);margin:0;">
                                            <?= htmlspecialchars($session['other_name']) ?> hasn't submitted a review of this
                                            session yet. When they do, it will appear here.
                                        </p>
                                    <?php else: ?>
                                        <div style="display:flex;align-items:center;gap:14px;margin-bottom:18px;">
                                            <div style="font-size:30px;font-weight:700;color:var(--forest);line-height:1;">
                                                <?= number_format((float)$counterpart['rating'], 1) ?>
                                            </div>
                                            <div>
                                                <div style="display:flex;gap:2px;margin-bottom:3px;">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="<?= $i <= round((float)$counterpart['rating']) ? 'var(--gold)' : 'var(--gray-200)' ?>"><path d="M12 2.6l2.9 6 6.6.9-4.8 4.6 1.2 6.5-5.9-3.2-5.9 3.2 1.2-6.5L2.5 9.5l6.6-.9 2.9-6Z" /></svg>
                                                    <?php endfor; ?>
                                                </div>
                                                <div style="font-size:11.5px;color:var(--gray-500);">
                                                    Submitted <?= date('M j, Y', strtotime($counterpart['created_at'])) ?>
                                                </div>
                                            </div>
                                        </div>

                                        <?php foreach ($counter_aspects as $key => $label):
                                            $v = (int)round((float)($counterpart[$key] ?? 0));
                                            $n = trim((string)($counterpart[$key === 'rating' ? 'note_overall' : 'note_' . $key] ?? ''));
                                        ?>
                                            <div style="padding:10px 0;border-bottom:1px solid var(--border);">
                                                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                                                    <span style="font-size:12.5px;font-weight:600;color:var(--forest);"><?= htmlspecialchars($label) ?></span>
                                                    <span style="display:flex;align-items:center;gap:8px;">
                                                        <span style="display:flex;gap:2px;">
                                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="<?= $i <= $v ? 'var(--gold)' : 'var(--gray-200)' ?>"><path d="M12 2.6l2.9 6 6.6.9-4.8 4.6 1.2 6.5-5.9-3.2-5.9 3.2 1.2-6.5L2.5 9.5l6.6-.9 2.9-6Z" /></svg>
                                                            <?php endfor; ?>
                                                        </span>
                                                        <span style="font-size:11.5px;font-weight:700;color:var(--success);min-width:58px;text-align:right;">
                                                            <?= $v ? htmlspecialchars($rating_words[$v]) : '—' ?>
                                                        </span>
                                                    </span>
                                                </div>
                                                <?php if ($n !== ''): ?>
                                                    <div style="font-size:12px;color:var(--gray-600);margin-top:5px;line-height:1.55;"><?= htmlspecialchars($n) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>

                                        <?php if (trim((string)$counterpart['comment']) !== ''): ?>
                                            <div style="margin-top:16px;">
                                                <div style="font-size:12px;font-weight:700;color:var(--forest);margin-bottom:5px;">Overall feedback</div>
                                                <p style="font-size:13px;color:var(--gray-700);line-height:1.6;margin:0;"><?= nl2br(htmlspecialchars($counterpart['comment'])) ?></p>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                        <?php else: ?>

                            <!-- ══ The form ══ -->
                            <form method="POST" action="<?= htmlspecialchars(url('feedback-save')) ?>" id="rvForm">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="session_id" value="<?= $session_id ?>">
                                <input type="hidden" name="action" id="rvAction" value="submit">

                                <div class="pcard" style="margin-bottom:16px;">
                                    <div class="pcard-body">
                                        <div style="font-size:15px;font-weight:700;color:var(--forest);margin-bottom:2px;">
                                            How was your experience with your <?= strtolower($other_label) ?>?
                                        </div>
                                        <div style="font-size:12.5px;color:var(--gray-500);margin-bottom:4px;">
                                            Rate the following aspects based on your session.
                                        </div>

                                        <?php foreach ($aspects as $key => [$label, $desc, $ico]): ?>
                                            <div class="rv-aspect">
                                                <div class="rv-aspect-head">
                                                    <div class="rv-aspect-icon">
                                                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><?= $icon_paths[$ico] ?></svg>
                                                    </div>
                                                    <div>
                                                        <div class="rv-aspect-name"><?= htmlspecialchars($label) ?></div>
                                                        <div class="rv-aspect-desc"><?= htmlspecialchars($desc) ?></div>
                                                    </div>
                                                </div>

                                                <div>
                                                    <input type="hidden" name="<?= $key ?>" id="rv-val-<?= $key ?>" value="<?= $values[$key] ?>">
                                                    <div class="rv-stars" data-key="<?= $key ?>" role="radiogroup" aria-label="<?= htmlspecialchars($label) ?> rating">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <button type="button" class="rv-star <?= $i <= $values[$key] ? 'on' : '' ?>"
                                                                data-val="<?= $i ?>" <?= $is_read_only ? 'disabled' : '' ?>
                                                                aria-label="<?= $i ?> of 5 — <?= htmlspecialchars($rating_words[$i]) ?>">
                                                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.6l2.9 6 6.6.9-4.8 4.6 1.2 6.5-5.9-3.2-5.9 3.2 1.2-6.5L2.5 9.5l6.6-.9 2.9-6Z" /></svg>
                                                            </button>
                                                        <?php endfor; ?>
                                                    </div>
                                                    <div class="rv-star-word" id="rv-word-<?= $key ?>">
                                                        <?= $values[$key] ? htmlspecialchars($rating_words[$values[$key]]) : '' ?>
                                                    </div>
                                                </div>

                                                <div>
                                                    <textarea class="rv-note" name="note_<?= $key ?>" maxlength="250"
                                                        data-counter="rv-count-<?= $key ?>"
                                                        placeholder="Add your comments (optional)..."
                                                        <?= $is_read_only ? 'disabled' : '' ?>><?= htmlspecialchars($values['note_' . $key]) ?></textarea>
                                                    <div class="rv-count"><span id="rv-count-<?= $key ?>"><?= mb_strlen($values['note_' . $key]) ?></span> / 250</div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="pcard">
                                    <div class="pcard-body">
                                        <div class="rv-overall">
                                            <div>
                                                <div style="font-size:13.5px;font-weight:700;color:var(--forest);margin-bottom:2px;">Overall Feedback</div>
                                                <div style="font-size:12px;color:var(--gray-500);margin-bottom:10px;">Share your overall thoughts and suggestions.</div>
                                                <textarea name="comment" maxlength="1000" data-counter="rv-count-comment"
                                                    placeholder="Write your overall feedback here..."
                                                    <?= $is_read_only ? 'disabled' : '' ?>><?= htmlspecialchars($values['comment']) ?></textarea>
                                                <div class="rv-count"><span id="rv-count-comment"><?= mb_strlen($values['comment']) ?></span> / 1000</div>
                                            </div>

                                            <div class="rv-actions">
                                                <?php if ($is_read_only): ?>
                                                    <div style="background:var(--mint-faint);border:1px solid var(--mint-soft);border-radius:var(--radius);padding:12px 14px;font-size:12.5px;color:var(--forest);line-height:1.55;">
                                                        You submitted this review on
                                                        <b><?= date('M j, Y', strtotime($existing['created_at'])) ?></b>.
                                                        Reviews can't be changed once they're in.
                                                    </div>
                                                    <?php if ($pending): ?>
                                                        <a href="?session=<?= (int)$pending[0]['request_id'] ?>" class="btn btn-ghost" style="justify-content:center;">
                                                            Review your next session
                                                        </a>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <button type="submit" class="btn rv-submit" id="rvSubmitBtn">
                                                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m21 3-9.5 9.5M21 3l-6.5 18-3.9-8.6L2 8.5 21 3Z" /></svg>
                                                        Submit Assessment
                                                    </button>
                                                    <button type="submit" class="btn btn-ghost" style="justify-content:center;"
                                                        onclick="document.getElementById('rvAction').value='draft';">
                                                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12v18l-6-4.2L6 21V3Z" /></svg>
                                                        Save as Draft
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="rv-private">
                                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="4.5" y="10" width="15" height="10" rx="2" /><path stroke-linecap="round" d="M8 10V7.5a4 4 0 0 1 8 0V10" /></svg>
                                            Your feedback is private and will only be shared with your <?= strtolower($other_label) ?>.
                                        </div>
                                    </div>
                                </div>
                            </form>

                        <?php endif; ?>
                    </div>

                    <!-- ══ Right rail ══ -->
                    <div style="display:flex;flex-direction:column;gap:16px;">

                        <div class="pcard">
                            <div class="pcard-hd">
                                <span class="pcard-title" style="display:flex;align-items:center;gap:7px;">
                                    <svg width="15" height="15" fill="none" stroke="var(--mint)" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" /><path stroke-linecap="round" d="M14 3v5h5M9 13h6M9 17h4" /></svg>
                                    Session Summary
                                </span>
                            </div>
                            <div class="pcard-body">
                                <div class="rv-summary-row"><span>Date</span><span><?= htmlspecialchars($s_date) ?></span></div>
                                <div class="rv-summary-row"><span>Time</span><span><?= htmlspecialchars($s_time) ?></span></div>
                                <div class="rv-summary-row"><span>Duration</span><span><?= $s_dur ?> minutes</span></div>
                                <div class="rv-summary-row"><span>Topic</span><span><?= htmlspecialchars($session['subject']) ?></span></div>
                                <div class="rv-summary-row" style="border-top:1px solid var(--border);margin-top:8px;padding-top:12px;">
                                    <span><?= htmlspecialchars($other_label) ?></span><span></span>
                                </div>
                                <div style="display:flex;align-items:center;gap:10px;margin-top:2px;">
                                    <?php if ($img !== ''): ?>
                                        <img src="<?= htmlspecialchars($img) ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">
                                    <?php else: ?>
                                        <div class="rv-avatar" style="width:36px;height:36px;font-size:13px;"><?= htmlspecialchars($initial) ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-size:12.5px;font-weight:700;color:var(--forest);"><?= htmlspecialchars($session['other_name']) ?></div>
                                        <div style="font-size:11px;color:var(--gray-500);"><?= htmlspecialchars($session['club'] ?: 'No club listed') ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="pcard">
                            <div class="pcard-hd">
                                <span class="pcard-title" style="display:flex;align-items:center;gap:7px;">
                                    <svg width="15" height="15" fill="none" stroke="var(--mint)" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5l2.6 5.4 5.9.8-4.3 4.1 1.1 5.8L12 16.8l-5.3 2.8 1.1-5.8-4.3-4.1 5.9-.8L12 3.5Z" /></svg>
                                    Rating Guide
                                </span>
                            </div>
                            <div class="pcard-body">
                                <?php foreach (array_reverse($rating_words, true) as $n => $word):
                                    $blurb = [
                                        5 => 'Outstanding. Exceeded expectations.',
                                        4 => 'Above average experience.',
                                        3 => 'Satisfactory experience.',
                                        2 => 'Needs improvement.',
                                        1 => 'Unsatisfactory experience.',
                                    ][$n];
                                ?>
                                    <div class="rv-guide-row">
                                        <div class="rv-guide-top">
                                            <span class="rv-guide-name"><?= htmlspecialchars($word) ?> (<?= $n ?>)</span>
                                            <span style="display:flex;gap:1px;">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="<?= $i <= $n ? 'var(--gold)' : 'var(--gray-200)' ?>"><path d="M12 2.6l2.9 6 6.6.9-4.8 4.6 1.2 6.5-5.9-3.2-5.9 3.2 1.2-6.5L2.5 9.5l6.6-.9 2.9-6Z" /></svg>
                                                <?php endfor; ?>
                                            </span>
                                        </div>
                                        <div class="rv-guide-desc"><?= htmlspecialchars($blurb) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="pcard" style="background:var(--mint-faint);border-color:var(--mint-soft);">
                            <div class="pcard-body" style="display:flex;gap:11px;">
                                <svg width="18" height="18" style="flex-shrink:0;margin-top:1px;" fill="none" stroke="var(--mint)" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7.5 3.2v5.1c0 4.3-3 8.2-7.5 9.7-4.5-1.5-7.5-5.4-7.5-9.7V6.2L12 3Z" /><path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" /></svg>
                                <div>
                                    <div style="font-size:13px;font-weight:700;color:var(--forest);margin-bottom:3px;">Your feedback matters</div>
                                    <div style="font-size:12px;color:var(--gray-600);line-height:1.55;">
                                        Your honest review helps <?= $is_mentee ? 'mentors' : 'mentees' ?> improve and supports a
                                        stronger mentorship community.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
        </main>
    </div>

    <?php if ($session && $done): ?>
        <!-- Review history — behind the "History" button rather than sitting
             at the bottom of every visit. -->
        <div id="historyModal" class="modal-overlay">
            <div style="background:var(--surface);border-radius:var(--radius-lg);padding:24px;width:940px;max-width:95vw;max-height:85vh;overflow-y:auto;position:relative;box-shadow:var(--shadow-lg);">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:4px;">
                    <div style="font-size:15px;font-weight:700;color:var(--forest);">Review History</div>
                    <button id="history-close" style="width:30px;height:30px;border-radius:8px;border:1px solid var(--gray-200);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div style="font-size:12.5px;color:var(--gray-500);margin-bottom:16px;">
                    Every review you've submitted, newest first. Reviews can't be changed once they're in.
                </div>

                <div style="overflow-x:auto;">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars($other_label) ?></th>
                                <th>Topic</th>
                                <th>Session date</th>
                                <th>Breakdown</th>
                                <th>Overall</th>
                                <th>Submitted</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($done as $s):
                                $sid = (int)$s['request_id'];
                                $r   = $reviewed[$sid];
                            ?>
                                <tr>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:9px;">
                                            <?php $hImg = trim((string)($s['profile_image'] ?? '')); ?>
                                            <?php if ($hImg !== ''): ?>
                                                <img src="<?= htmlspecialchars($hImg) ?>" alt="" style="width:30px;height:30px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                                            <?php else: ?>
                                                <div class="tbl-avatar" style="width:30px;height:30px;font-size:12px;"><?= htmlspecialchars(strtoupper(substr($s['other_name'], 0, 1))) ?></div>
                                            <?php endif; ?>
                                            <span style="font-weight:600;color:var(--forest);"><?= htmlspecialchars($s['other_name']) ?></span>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($s['subject']) ?></td>
                                    <td style="white-space:nowrap;"><?= date('M j, Y', strtotime($s['session_date'])) ?></td>
                                    <td style="min-width:210px;">
                                        <div style="display:grid;grid-template-columns:repeat(2, minmax(0, 1fr));gap:3px 12px;font-size:11px;color:var(--gray-500);">
                                            <?php foreach ($aspects as $aKey => [$aLabel, , ]):
                                                if ($aKey === 'rating') continue;
                                                // First word of each aspect keeps the column narrow.
                                                $short = strtok($aLabel, ' &');
                                            ?>
                                                <span title="<?= htmlspecialchars($aLabel) ?>">
                                                    <?= htmlspecialchars($short) ?>
                                                    <b style="color:var(--forest);"><?= (int)round((float)($r[$aKey] ?? 0)) ?></b>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:7px;white-space:nowrap;">
                                            <span style="display:flex;gap:1px;">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="<?= $i <= round((float)$r['rating']) ? 'var(--gold)' : 'var(--gray-200)' ?>"><path d="M12 2.6l2.9 6 6.6.9-4.8 4.6 1.2 6.5-5.9-3.2-5.9 3.2 1.2-6.5L2.5 9.5l6.6-.9 2.9-6Z" /></svg>
                                                <?php endfor; ?>
                                            </span>
                                            <b style="color:var(--forest);"><?= number_format((float)$r['rating'], 1) ?></b>
                                        </div>
                                    </td>
                                    <td style="white-space:nowrap;color:var(--gray-500);"><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
                                    <td style="text-align:right;">
                                        <a href="?session=<?= $sid ?>" class="btn btn-ghost btn-sm">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        const RATING_WORDS = <?= json_encode(array_values($rating_words)) ?>;

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

        // ── History modal ────────────────────────────────────────────────
        const historyModal = document.getElementById('historyModal');
        if (historyModal) {
            document.getElementById('history-close').addEventListener('click', () => historyModal.classList.remove('open'));
            historyModal.addEventListener('click', e => {
                if (e.target === historyModal) historyModal.classList.remove('open');
            });
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') historyModal.classList.remove('open');
            });
        }

        // ── Stars ────────────────────────────────────────────────────────
        document.querySelectorAll('.rv-stars').forEach(row => {
            const key = row.dataset.key;
            const input = document.getElementById('rv-val-' + key);
            const word = document.getElementById('rv-word-' + key);
            const stars = Array.from(row.querySelectorAll('.rv-star'));

            const paint = n => stars.forEach((s, i) => s.classList.toggle('on', i < n));

            stars.forEach(star => {
                if (star.disabled) return;
                const val = Number(star.dataset.val);
                star.addEventListener('click', () => {
                    input.value = val;
                    paint(val);
                    word.textContent = RATING_WORDS[val - 1];
                });
                star.addEventListener('mouseenter', () => paint(val));
            });

            row.addEventListener('mouseleave', () => paint(Number(input.value)));
        });

        // ── Character counters ───────────────────────────────────────────
        document.querySelectorAll('textarea[data-counter]').forEach(t => {
            const out = document.getElementById(t.dataset.counter);
            if (!out) return;
            t.addEventListener('input', () => out.textContent = t.value.length);
        });

        // ── Submitting requires every aspect to be rated ─────────────────
        const rvForm = document.getElementById('rvForm');
        if (rvForm) {
            rvForm.addEventListener('submit', function(e) {
                if (document.getElementById('rvAction').value === 'draft') return;
                const missing = Array.from(document.querySelectorAll('.rv-stars'))
                    .filter(r => Number(document.getElementById('rv-val-' + r.dataset.key).value) < 1);
                if (missing.length) {
                    e.preventDefault();
                    missing[0].scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    pcToast('Please rate all five aspects before submitting.', 'error');
                }
            });
        }
    </script>
</body>

</html>
