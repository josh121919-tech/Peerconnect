<?php

/**
 * join_check.php
 * A lobby/waiting room shown before entering the video call.
 * Placed at: /case/case/6b4396b7d830104eb41d706cefe6a991
 *
 * Usage:
 *   1v1:   join_check.php?session_id=123
 *   Group: join_check.php?session_id=123&type=group
 */
date_default_timezone_set('Asia/Manila');
session_start();
include __DIR__ . "/../db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: " . url('welcomepage'));
    exit;
}

$session_id  = (int)($_GET['session_id'] ?? 0);
$is_group    = isset($_GET['type']) && $_GET['type'] === 'group';
$user_id     = (int)$_SESSION['user_id'];
$role        = $_SESSION['role'] ?? 'mentee';
$sessions_url = $role === 'mentor' ? url('mentor-requests') : url('mentee-sessions');

if (!$session_id) {
    header("Location: " . $sessions_url);
    exit;
}

// Fetch the anchor session row (works for both 1v1 and group), with the
// length the call room uses, so the lobby and the room agree on when it ends.
$session = SessionRepository::forParticipantWithLength($con, $session_id, $user_id, false);

if (!$session) {
    header("Location: " . $sessions_url);
    exit;
}

$status  = $session['status'];
$live_statuses = ['approved', 'unfinished'];
$is_live_session = in_array($status, $live_statuses, true);
$appTz   = new DateTimeZone('Asia/Manila');

$sessionDt    = new DateTime($session['session_date'], $appTz);
$session_date = $sessionDt->getTimestamp();
$nowDt        = new DateTime('now', $appTz);
$now          = $nowDt->getTimestamp();
$minutes_until = ($session_date - $now) / 60;
$session_end  = $session_date + (int)$session['duration'] * 60;
// room.php turns people away once the session's time is over and sends them
// back here, so the lobby must not offer a Join that can only bounce.
$has_ended    = $is_live_session && $now > $session_end;

$can_join = $is_live_session && ($minutes_until <= SessionRepository::JOIN_WINDOW_MINUTES) && !$has_ended;

// What to say about a session that can no longer be joined, by what became of it.
$closed_text = [
    'completed' => 'This session has been completed.',
    'missed'    => 'This session was missed, so it can no longer be joined.',
    'cancelled' => 'This session was cancelled.',
    'rejected'  => 'The mentor declined this request.',
][$status] ?? null;
$back_url = $role === 'mentor'
    ? url('mentor-requests')
    : url('mentee-sessions');

// ── For group sessions: fetch all students in this slot ──────────────────────
$group_students = [];
if ($is_group) {
    $group_students = SessionRepository::openReservationsInSlotByName($con, (int)$session['mentor_id'], (string)$session['subject'], (string)$session['session_date']);
}

// For 1v1: show the other person's name as before
$other_person = $role === 'mentor'
    ? ($session['mentee_fname'] . ' ' . $session['mentee_lname'])
    : ($session['mentor_fname'] . ' ' . $session['mentor_lname']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Session – NEUST</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'DM Sans', sans-serif;
        }

        h1,
        h2,
        h3 {
            font-family: 'DM Serif Display', serif;
        }

        /*
         * The app's palette. This page is outside the shell and does not load
         * pc-app.css, so it was drawing itself in Tailwind's stock blue and a
         * warm grey that appear nowhere else in PeerConnect.
         */
        :root {
            --bg: #F7F9FC;
            --primary: #0868AD;
            --primary-2: #06527F;
            --navy: #071B4D;
            --navy-2: #0A2A6E;
            --mint-faint: #EAF6FC;
        }

        body {
            background: var(--bg);
        }

        /* A group call is the same screen in the deeper of the two brand
           blues, so the two are told apart without leaving the palette. */
        .vc-head { background: var(--primary); }
        .vc-head.group { background: var(--navy); }

        .vc-cta { background: var(--primary); }
        .vc-cta:hover { background: var(--primary-2); }
        .vc-cta.group { background: var(--navy); }
        .vc-cta.group:hover { background: var(--navy-2); }

        .vc-wait { background: var(--mint-faint); border-color: #CBE4F5; }
        .vc-wait-k { color: var(--primary); }
        .vc-wait-v { color: var(--primary); }
        .vc-wait-s { color: #4C7FA3; }
    </style>
</head>

<body class="min-h-screen flex items-center justify-center p-6">

    <div class="w-full max-w-md">

        <!-- Card -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">

            <!-- Header strip -->
            <div class="vc-head<?= $is_group ? ' group' : '' ?> px-6 py-5">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center">
                        <?php if ($is_group): ?>
                            <!-- Group icon -->
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6 5.87v-2a4 4 0 00-2-3.46M15 11a4 4 0 10-8 0 4 4 0 008 0zm6 0a3 3 0 10-6 0 3 3 0 006 0zM3 11a3 3 0 106 0 3 3 0 00-6 0z" />
                            </svg>
                        <?php else: ?>
                            <!-- Video icon -->
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.069A1 1 0 0121 8.867v6.266a1 1 0 01-1.447.9L15 14M3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z" />
                            </svg>
                        <?php endif; ?>
                    </div>
                    <div>
                        <p class="text-white/70 text-xs">
                            <?= $is_group ? 'Group Session' : 'Video Session' ?>
                        </p>
                        <h2 class="text-white text-lg leading-tight">
                            <?= htmlspecialchars($session['subject'] ?? 'Mentoring Session') ?>
                        </h2>
                    </div>
                </div>
            </div>

            <!-- Body -->
            <div class="p-6">

                <!-- Session details -->
                <div class="space-y-3 mb-6">

                    <!-- Participant(s) row -->
                    <div class="flex items-start gap-3 text-sm">
                        <div class="w-8 h-8 rounded-xl bg-gray-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <div class="flex-1">
                            <?php if ($is_group && $role === 'mentor'): ?>
                                <!-- Mentor sees student list -->
                                <p class="text-xs text-gray-400 mb-1">
                                    Students (<?= count($group_students) ?>)
                                </p>
                                <?php if (!empty($group_students)): ?>
                                    <div class="flex flex-wrap gap-1.5">
                                        <?php foreach ($group_students as $st): ?>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-xs font-medium
                                                <?= $st['status'] === 'approved' ? 'bg-green-50 text-green-700' : 'bg-yellow-50 text-yellow-700' ?>">
                                                <?= htmlspecialchars($st['firstname'] . ' ' . $st['lastname']) ?>
                                                <span class="opacity-60">(<?= ucfirst($st['status']) ?>)</span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-sm text-gray-400">No students reserved yet.</p>
                                <?php endif; ?>
                            <?php elseif ($is_group && $role === 'mentee'): ?>
                                <!-- Mentee sees the mentor name -->
                                <p class="text-xs text-gray-400">Mentor</p>
                                <p class="font-medium text-gray-800"><?= htmlspecialchars($other_person) ?></p>
                                <p class="text-xs text-gray-400 mt-1">This is a group session — other students will also be present.</p>
                            <?php else: ?>
                                <!-- 1v1 -->
                                <p class="text-xs text-gray-400"><?= $role === 'mentor' ? 'Mentee' : 'Mentor' ?></p>
                                <p class="font-medium text-gray-800"><?= htmlspecialchars($other_person) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Date & Time -->
                    <div class="flex items-center gap-3 text-sm">
                        <div class="w-8 h-8 rounded-xl bg-gray-50 flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400">Date & Time</p>
                            <p class="font-medium text-gray-800">
                                <?= date("F d, Y", $session_date) ?> at <?= date("h:i A", $session_date) ?>
                            </p>
                        </div>
                    </div>

                    <!-- Status -->
                    <div class="flex items-center gap-3 text-sm">
                        <div class="w-8 h-8 rounded-xl bg-gray-50 flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400">Status</p>
                            <p class="font-medium <?= $is_live_session ? 'text-green-600' : 'text-yellow-600' ?> capitalize">
                                <?= htmlspecialchars($status) ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Status / action banner -->
                <?php if ($closed_text !== null): ?>
                    <div class="bg-gray-50 border border-gray-100 rounded-xl p-4 mb-4 text-sm text-gray-600">
                        <?= htmlspecialchars($closed_text) ?>
                    </div>
                <?php elseif ($has_ended): ?>
                    <div class="bg-gray-50 border border-gray-100 rounded-xl p-4 mb-4 text-sm text-gray-600">
                        This session's time is over, so the room is closed.
                    </div>
                <?php elseif ($status === 'unfinished'): ?>
                    <div class="bg-green-50 border border-green-100 rounded-xl p-4 mb-4 text-sm text-green-700">
                        Someone left before the session ended. The room is still open, so you can rejoin until the scheduled end time.
                    </div>
                <?php elseif (!$is_live_session): ?>
                    <div class="bg-yellow-50 border border-yellow-100 rounded-xl p-4 mb-4 text-sm text-yellow-700">
                        This session is not yet approved. You can only join once the mentor approves the request.
                    </div>
                <?php elseif ($minutes_until > SessionRepository::JOIN_WINDOW_MINUTES): ?>
                    <div class="vc-wait border rounded-xl p-4 mb-4">
                        <p class="vc-wait-k text-sm font-medium mb-1">Session starts in</p>
                        <p class="vc-wait-v text-2xl font-bold" id="countdown">–</p>
                        <p class="vc-wait-s text-xs mt-1">You can join <?= SessionRepository::JOIN_WINDOW_MINUTES ?> minutes before the session starts.</p>
                    </div>
                <?php else: ?>
                    <div class="bg-green-50 border border-green-100 rounded-xl p-4 mb-4 flex items-center gap-3">
                        <span class="w-2.5 h-2.5 rounded-full bg-green-500 animate-pulse flex-shrink-0"></span>
                        <p class="text-sm text-green-700 font-medium">
                            <?= $is_group ? 'Group room is ready — you can start now!' : 'Room is ready — you can join now!' ?>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Buttons -->
                <div class="flex flex-col gap-2">
                    <?php
                    // Build the room URL — pass type=group through so room.php also knows
                    $room_url = url('video-room') . '?session_id=' . $session_id
                        . ($is_group ? '&type=group' : '');
                    ?>
                    <?php if ($can_join): ?>
                        <a href="<?= $room_url ?>"
                            class="vc-cta<?= $is_group ? ' group' : '' ?> flex items-center justify-center gap-2 w-full py-3 rounded-xl text-white text-sm font-medium transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.069A1 1 0 0121 8.867v6.266a1 1 0 01-1.447.9L15 14M3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z" />
                            </svg>
                            <?= $status === 'unfinished' ? 'Rejoin Video Session' : ($is_group ? 'Start Group Session' : 'Join Video Session') ?>
                        </a>
                    <?php else: ?>
                        <button disabled
                            class="flex items-center justify-center gap-2 w-full py-3 rounded-xl bg-gray-100 text-gray-400 text-sm font-medium cursor-not-allowed">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.069A1 1 0 0121 8.867v6.266a1 1 0 01-1.447.9L15 14M3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z" />
                            </svg>
                            <?= ($closed_text !== null || $has_ended) ? "No Longer Available\n" : "Not Available Yet\n" ?>
                        </button>
                    <?php endif; ?>

                    <a href="<?= $back_url ?>"
                        class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-gray-50 border border-gray-100 text-gray-500 text-sm hover:bg-gray-100 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back to Sessions
                    </a>
                </div>
            </div>
        </div>

        <!-- Branding -->
        <p class="text-center text-xs text-gray-400 mt-4">
            NEUST Mentoring Platform &bull; Powered by Jitsi Meet
        </p>
    </div>

    <?php if ($is_live_session && $minutes_until > 0): ?>
        <script>
            const sessionDate = <?= $session_date * 1000 ?>;

            function updateCountdown() {
                const diff = sessionDate - Date.now();
                if (diff <= 0) {
                    location.reload();
                    return;
                }
                const h = Math.floor(diff / 3600000);
                const m = Math.floor((diff % 3600000) / 60000);
                const s = Math.floor((diff % 60000) / 1000);
                const el = document.getElementById('countdown');
                if (el) el.textContent =
                    (h > 0 ? h + 'h ' : '') + m + 'm ' + s + 's';
            }
            updateCountdown();
            setInterval(updateCountdown, 1000);
        </script>
    <?php endif; ?>

</body>

</html>
