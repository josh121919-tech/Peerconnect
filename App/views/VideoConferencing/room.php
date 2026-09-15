<?php

/**
 * room.php  –  Video call room
 * Place at: /case/case/videoconferencing/room.php
 */
date_default_timezone_set('Asia/Manila');
session_start();
include __DIR__ . "/../db.php";
require_once __DIR__ . "/../../services/JitsiTokenService.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: " . url('welcomepage'));
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'mentee';
$sessions_url = $role === 'mentor' ? url('mentor-requests') : url('mentee-sessions');

$session_id = (int)($_GET['session_id'] ?? 0);
if (!$session_id) {
    header("Location: " . $sessions_url);
    exit;
}

// ── Fetch session ────────────────────────────────────────────
$session = SessionRepository::forParticipantWithLength($con, $session_id, $user_id, true);

if (!$session) {
    header("Location: " . $sessions_url);
    exit;
}

// ── Timing ───────────────────────────────────────────────────
$appTz        = new DateTimeZone('Asia/Manila');
$sessionStart = new DateTime($session['session_date'], $appTz);
$durationMins = (int)($session['duration'] ?? 30);
$sessionEnd   = (clone $sessionStart)->modify("+{$durationMins} minutes");
$sessionEndTs = $sessionEnd->getTimestamp();   // → JS

// SECURITY: re-enforce the same join window the lobby (join_check.php)
// already shows — without this, an approved participant could open the
// room URL directly at any time, bypassing the "15 minutes before start"
// rule shown in the UI.
$minutesUntilStart = ($sessionStart->getTimestamp() - time()) / 60;
if ($minutesUntilStart > 15 || time() > $sessionEndTs) {
    header("Location: " . url('video-join') . '?session_id=' . $session_id . (isset($_GET['type']) ? '&type=' . urlencode($_GET['type']) : ''));
    exit;
}

// ── Attendance ────────────────────────────────────────────────
// Everything above has confirmed this person belongs to this approved session
// and is inside its join window, so reaching this line means they opened the
// call. The missed-session detector reads this to tell who did not show up,
// instead of recording every missed session as missed by both people.
// First visit only: re-opening the call keeps the original join time.
// It records opening the call page — the call itself runs in an embedded
// frame the server cannot see, so a dropped camera or microphone is invisible.
$attendRole = ((int)$session['mentor_id'] === $user_id) ? 'mentor' : 'mentee';
SessionRepository::recordAttendance($con, $session_id, $user_id, $attendRole);

// ── Room name (private, deterministic) ────────────────────────
// Derived from the mentor+subject+slot, NOT request_id, so every
// approved participant booked into the same slot (1:1 or group)
// lands in the SAME room instead of each mentee getting an isolated one.
$slotDate = date('Y-m-d', strtotime($session['session_date']));
$slotTime = date('H:i:s', strtotime($session['session_date']));
$room_name = "neust-" . substr(hash('sha256', $session['mentor_id'] . '|' . $session['subject'] . '|' . $slotDate . '|' . $slotTime . '|neust_secret_salt'), 0, 28);

// ── Display name ─────────────────────────────────────────────
$display_name = $_SESSION['firstname'] ?? explode('@', $_SESSION['email'])[0];

// ── JaaS meeting descriptor (falls back to public meet.jit.si
//    with no token if JaaS credentials aren't configured) ─────
$meeting = JitsiTokenService::build(
    $room_name,
    $user_id,
    $display_name,
    $role === 'mentor',
    $sessionEndTs + 300 // 5 min grace so the closing overlay/redirect can finish
);

// ── URLs — role-specific redirect after session ends ─────────
$back_url    = $role === 'mentor'
    ? url('mentor-completed')
    : url('mentee-sessions');

// After session ends: mentor → completed page, mentee → submit feedback
// FIXED: mentee now goes to feedback form instead of sessions list
$history_url = $role === 'mentor'
    ? url('mentor-completed')
    : url('mentee-submit-feedback') . '?session_id=' . $session_id . '&mentor_id=' . ($session['mentor_id'] ?? 0);

$other_person = $role === 'mentor'
    ? $session['mentee_fname'] . ' ' . $session['mentee_lname']
    : $session['mentor_fname'] . ' ' . $session['mentor_lname'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Session – <?= htmlspecialchars($session['subject'] ?? 'Session') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'DM Sans', sans-serif;
        }

        body {
            background: #0f0f0f;
        }

        #jitsi-container {
            width: 100%;
            height: 100%;
            border-radius: 16px;
            overflow: hidden;
        }

        #loading-overlay {
            position: absolute;
            inset: 0;
            background: #0f0f0f;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
            z-index: 10;
            border-radius: 16px;
            transition: opacity 0.5s ease;
        }

        #loading-overlay.fade-out {
            opacity: 0;
            pointer-events: none;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(255, 255, 255, 0.1);
            border-top-color: var(--forest);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .info-bar {
            background: rgba(255, 255, 255, 0.04);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
        }

        /* ── End-of-session overlay ── */
        #end-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.88);
            backdrop-filter: blur(8px);
            z-index: 200;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 20px;
        }

        #end-overlay.show {
            display: flex;
        }

        /* timer turns red when < 5 min */
        .timer-warn {
            color: #f87171 !important;
        }
    </style>
</head>

<body class="h-screen flex flex-col overflow-hidden">

    <!-- ── Top Bar ─────────────────────────────────────────── -->
    <div class="info-bar flex items-center justify-between px-5 py-3 flex-shrink-0">
        <div class="flex items-center gap-4">
            <!-- Logo -->
            <div class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-full border-2 border-white/20 flex items-center justify-center">
                    <span style="font-family:'DM Serif Display',serif;font-size:9px;font-weight:700;color:rgba(255,255,255,.8)">P</span>
                </div>
                <span class="text-white/60 text-xs font-semibold" style="font-family:'DM Serif Display',serif;">NEUST</span>
            </div>
            <div class="w-px h-5 bg-white/10"></div>
            <!-- Session info -->
            <div>
                <p class="text-white text-sm font-semibold leading-tight">
                    <?= htmlspecialchars($session['subject'] ?? 'Mentoring Session') ?>
                </p>
                <p class="text-white/40 text-xs">with <?= htmlspecialchars($other_person) ?></p>
            </div>
            <!-- Live badge -->
            <div class="flex items-center gap-1.5 bg-red-500/10 border border-red-500/20 rounded-full px-3 py-1">
                <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"></span>
                <span class="text-red-400 text-xs font-medium">LIVE</span>
            </div>
        </div>

        <div class="flex items-center gap-5">
            <!-- Countdown timer -->
            <div class="text-right">
                <p class="text-white/30 text-xs">Time remaining</p>
                <p class="text-white text-sm font-semibold font-mono" id="timer-remaining">–</p>
            </div>
            <!-- End & Leave button -->
            <button id="end-btn"
                class="flex items-center gap-2 px-4 py-1.5 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs font-medium hover:bg-red-500/20 transition">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
                End & Leave
            </button>
        </div>
    </div>

    <!-- ── Jitsi iframe ───────────────────────────────────── -->
    <div class="flex-1 p-3 relative">
        <div id="loading-overlay">
            <div class="spinner"></div>
            <p class="text-white/50 text-sm">Connecting to your session...</p>
        </div>
        <?php
        $frame_fragment = "userInfo.displayName=" . urlencode($display_name)
            . "&config.prejoinPageEnabled=true&config.startWithAudioMuted=false&config.startWithVideoMuted=false"
            . "&config.disableDeepLinking=true&interfaceConfig.SHOW_JITSI_WATERMARK=false&interfaceConfig.SHOW_POWERED_BY=false";
        if ($meeting['token'] !== null) {
            $frame_fragment = "jwt=" . $meeting['token'] . "&" . $frame_fragment;
        }
        $frame_src = "https://" . $meeting['domain'] . "/" . $meeting['room'] . "#" . $frame_fragment;
        ?>
        <iframe
            id="jitsi-frame"
            src="<?= htmlspecialchars($frame_src) ?>"
            allow="camera; microphone; fullscreen; display-capture; autoplay"
            style="width:100%;height:100%;border:none;border-radius:16px;display:none;"></iframe>
    </div>

    <!-- ── End-of-session overlay ─────────────────────────── -->
    <div id="end-overlay">
        <div class="w-16 h-16 rounded-full bg-green-500/20 border border-green-500/30 flex items-center justify-center">
            <svg class="w-8 h-8 text-green-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        </div>
        <div class="text-center">
            <h2 class="text-white text-2xl" style="font-family:'DM Serif Display',serif;">Session Complete</h2>
            <p class="text-white/50 text-sm mt-1">Saving to your session history…</p>
        </div>
        <div class="flex items-center gap-2 text-white/30 text-xs mt-2">
            <div class="spinner" style="width:14px;height:14px;border-width:2px;"></div>
            Redirecting in <span id="redirect-count" class="text-white/50 font-semibold">3</span>s
        </div>
    </div>

    <script>
        const SESSION_ID = <?= $session_id ?>;
        const SESSION_END = <?= $sessionEndTs ?> * 1000; // ms
        const HISTORY_URL = <?= json_encode($history_url) ?>;
        const DURATION_MINS = <?= $durationMins ?>;
        const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

        let endCalled = false;

        // ── 1. Mark completed + redirect ─────────────────────────
        async function endSession() {
            if (endCalled) return;
            endCalled = true;

            document.getElementById('end-overlay').classList.add('show');

            let count = 3;
            const countEl = document.getElementById('redirect-count');
            const ticker = setInterval(() => {
                count--;
                if (countEl) countEl.textContent = count;
                if (count <= 0) clearInterval(ticker);
            }, 1000);

            try {
                await fetch('<?= url('video-end') ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        session_id: SESSION_ID,
                        csrf_token: CSRF_TOKEN
                    }),
                    keepalive: true,
                });
            } catch (_) {}

            setTimeout(() => {
                window.location.href = HISTORY_URL;
            }, 3000);
        }

        // ── 2. Auto-end when duration is up ──────────────────────
        function scheduleAutoEnd() {
            const ms = SESSION_END - Date.now();
            if (ms <= 0) {
                endSession();
                return;
            }
            setTimeout(() => endSession(), ms);
        }

        // ── 3. Countdown timer ───────────────────────────────────
        function updateTimer() {
            const diff = SESSION_END - Date.now();
            const el = document.getElementById('timer-remaining');
            if (diff <= 0) {
                if (el) el.textContent = '0:00';
                return;
            }
            const m = Math.floor(diff / 60000);
            const s = Math.floor((diff % 60000) / 1000);
            if (el) {
                el.textContent = m + ':' + String(s).padStart(2, '0');
                el.classList.toggle('timer-warn', m < 5);
            }
            setTimeout(updateTimer, 1000);
        }

        // ── 4. Show iframe after short delay (hides loading spinner) ──
        window.addEventListener('load', () => {
            const frame = document.getElementById('jitsi-frame');
            const overlay = document.getElementById('loading-overlay');

            // Show the iframe and hide the loading overlay after 3s
            // (Jitsi needs a moment to initialise its prejoin page)
            setTimeout(() => {
                frame.style.display = 'block';
                overlay.style.transition = 'opacity 0.5s';
                overlay.style.opacity = '0';
                setTimeout(() => overlay.remove(), 500);
            }, 3000);

            // Start timer and auto-end immediately — based on session duration
            updateTimer();
            scheduleAutoEnd();
        });

        // ── 5. End & Leave button / tab close ────────────────────
        document.getElementById('end-btn').addEventListener('click', endSession);

        window.addEventListener('beforeunload', () => {
            if (!endCalled) {
                navigator.sendBeacon(
                    '<?= url('video-end') ?>',
                    JSON.stringify({
                        session_id: SESSION_ID,
                        csrf_token: CSRF_TOKEN
                    })
                );
            }
        });
    </script>
</body>

</html>