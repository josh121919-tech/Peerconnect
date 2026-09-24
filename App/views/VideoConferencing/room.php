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
if ($minutesUntilStart > SessionRepository::JOIN_WINDOW_MINUTES || time() > $sessionEndTs) {
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

/*
 * And how long they are here for, which is a different question from whether
 * they came at all. session_attendance is the roster; session_presence is one
 * row per visit, kept alive by the heartbeat below and closed when they leave.
 * Opening the page is the first beat.
 */
SessionRepository::openPresence($con, $session_id, $user_id, $attendRole);

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#071B4D">
    <title>Video Session – <?= htmlspecialchars($session['subject'] ?? 'Session') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <style>
        /*
         * The app's palette, declared here because this page stands outside the
         * shell and never loads pc-app.css — var(--forest) was being used below
         * with nothing to resolve it to, so the spinner drew in the wrong
         * colour. A call wants a dark room, so the surface is the sidebar navy
         * taken down rather than a flat black that belongs to nothing.
         */
        :root {
            --navy-deep: #040F2C;
            --navy: #071B4D;
            --primary: #0868AD;
            --mint: #087FC1;
            --gold: #F4C95D;
            --danger: #E2574C;
        }

        * { font-family: 'DM Sans', sans-serif; }

        html { height: 100%; }

        body {
            margin: 0;
            background: var(--navy-deep);
            color: #fff;
            /* dvh, not vh: on a phone the browser's own chrome makes 100vh
               taller than what you can see, which pushed the call's toolbar
               under the address bar. The vh line is the fallback. */
            height: 100vh;
            height: 100dvh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* ── Top bar ── */
        .vc-bar {
            flex: none;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 18px;
            padding-left: max(18px, env(safe-area-inset-left));
            padding-right: max(18px, env(safe-area-inset-right));
            background: rgba(7, 27, 77, .72);
            border-bottom: 1px solid rgba(255, 255, 255, .08);
            backdrop-filter: blur(10px);
        }

        .vc-brand { display: flex; align-items: center; gap: 8px; flex: none; }
        .vc-brand-b {
            width: 28px; height: 28px; border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, .22);
            display: grid; place-items: center;
            font-family: 'DM Serif Display', serif; font-size: 10px; font-weight: 700;
            color: rgba(255, 255, 255, .8);
        }
        .vc-brand-n { font-family: 'DM Serif Display', serif; font-size: 12.5px; color: rgba(255, 255, 255, .6); }
        .vc-sep { width: 1px; height: 20px; background: rgba(255, 255, 255, .12); flex: none; }

        .vc-meta { min-width: 0; flex: 1; }
        .vc-subject {
            font-size: 14px; font-weight: 600; color: #fff; line-height: 1.3;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .vc-with {
            font-size: 12px; color: rgba(255, 255, 255, .45);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .vc-live {
            display: inline-flex; align-items: center; gap: 6px; flex: none;
            padding: 4px 10px; border-radius: 999px;
            background: rgba(226, 87, 76, .14); border: 1px solid rgba(226, 87, 76, .3);
        }
        .vc-live i { width: 6px; height: 6px; border-radius: 50%; background: var(--danger); display: block; }
        .vc-live b { font-size: 11px; font-weight: 600; color: #F2A8A1; letter-spacing: .04em; }

        @media (prefers-reduced-motion: no-preference) {
            .vc-live i { animation: vcpulse 1.8s ease-in-out infinite; }
            @keyframes vcpulse { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }
        }

        .vc-right { display: flex; align-items: center; gap: 14px; flex: none; margin-left: auto; }
        .vc-timer-k { font-size: 10.5px; color: rgba(255, 255, 255, .4); letter-spacing: .04em; text-align: right; }
        .vc-timer-v {
            font-size: 14.5px; font-weight: 600; color: #fff; text-align: right;
            font-variant-numeric: tabular-nums; line-height: 1.2;
        }
        .vc-timer-v.warn { color: #F2A8A1; }

        .vc-end {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px; flex: none;
            padding: 9px 15px; border-radius: 11px; cursor: pointer;
            border: 1px solid rgba(226, 87, 76, .38); background: rgba(226, 87, 76, .16);
            color: #F7BDB7; font-family: inherit; font-size: 12.5px; font-weight: 600;
        }
        .vc-end:hover { background: var(--danger); border-color: var(--danger); color: #fff; }
        .vc-end:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }
        .vc-end svg { width: 15px; height: 15px; flex: none; }

        /* ── The call ── */
        .vc-stage {
            flex: 1; min-height: 0; position: relative; padding: 12px;
            padding-bottom: max(12px, env(safe-area-inset-bottom));
        }
        #call-host { width: 100%; height: 100%; border-radius: 16px; overflow: hidden; background: #000; }
        #call-host iframe { width: 100% !important; height: 100% !important; border: 0; display: block; }

        #loading-overlay {
            position: absolute; inset: 12px; border-radius: 16px;
            background: var(--navy-deep);
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 16px;
            z-index: 10; transition: opacity .5s ease; text-align: center; padding: 20px;
        }
        #loading-overlay p { color: rgba(255, 255, 255, .5); font-size: 13.5px; margin: 0; }

        .spinner {
            width: 40px; height: 40px; border-radius: 50%;
            border: 3px solid rgba(255, 255, 255, .12);
            border-top-color: var(--mint);
            animation: spin .8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── End-of-session overlay ── */
        #end-overlay {
            display: none; position: fixed; inset: 0; z-index: 200; padding: 24px;
            background: rgba(4, 15, 44, .92); backdrop-filter: blur(8px);
            flex-direction: column; align-items: center; justify-content: center; gap: 20px; text-align: center;
        }
        #end-overlay.show { display: flex; }
        .vc-done {
            width: 64px; height: 64px; border-radius: 50%;
            background: rgba(23, 101, 75, .28); border: 1px solid rgba(52, 168, 128, .4);
            display: grid; place-items: center;
        }
        .vc-done svg { width: 32px; height: 32px; color: #5BD3A6; }
        .vc-done-t { font-family: 'DM Serif Display', serif; font-size: 25px; color: #fff; margin: 0; }
        .vc-done-s { font-size: 13.5px; color: rgba(255, 255, 255, .5); margin: 4px 0 0; }
        .vc-done-r { display: flex; align-items: center; gap: 8px; font-size: 12px; color: rgba(255, 255, 255, .35); }

        /* ── Phone ── */
        @media (max-width: 640px) {
            .vc-bar { gap: 10px; padding: 8px 12px; }
            .vc-brand, .vc-sep, .vc-with { display: none; }
            .vc-subject { font-size: 13.5px; }
            .vc-live { padding: 6px; }
            .vc-live b { display: none; }
            .vc-right { gap: 8px; }
            .vc-timer-k { display: none; }
            .vc-timer-v {
                font-size: 13px; padding: 6px 10px; border-radius: 9px;
                background: rgba(255, 255, 255, .08);
            }
            .vc-end { padding: 9px 11px; }
            .vc-end span { display: none; }
            .vc-stage { padding: 6px; padding-bottom: max(6px, env(safe-area-inset-bottom)); }
            #call-host, #loading-overlay { border-radius: 12px; }
            #loading-overlay { inset: 6px; }
            .vc-done-t { font-size: 21px; }
        }
    </style>
</head>

<body>

    <!-- ── Top bar ─────────────────────────────────────────── -->
    <header class="vc-bar">
        <div class="vc-brand">
            <span class="vc-brand-b">P</span>
            <span class="vc-brand-n">NEUST</span>
        </div>
        <div class="vc-sep"></div>

        <div class="vc-meta">
            <div class="vc-subject"><?= htmlspecialchars($session['subject'] ?? 'Mentoring Session') ?></div>
            <div class="vc-with">with <?= htmlspecialchars($other_person) ?></div>
        </div>

        <span class="vc-live"><i></i><b>LIVE</b></span>

        <div class="vc-right">
            <div>
                <div class="vc-timer-k">Time remaining</div>
                <div class="vc-timer-v" id="timer-remaining">&ndash;</div>
            </div>
            <button id="end-btn" class="vc-end" type="button" title="End &amp; Leave">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
                <span>End &amp; Leave</span>
            </button>
        </div>
    </header>

    <!-- ── The call ────────────────────────────────────────── -->
    <main class="vc-stage">
        <div id="loading-overlay">
            <div class="spinner"></div>
            <p>Connecting to your session…</p>
        </div>
        <div id="call-host"></div>
    </main>

    <!-- ── End-of-session overlay ─────────────────────────── -->
    <div id="end-overlay">
        <div class="vc-done">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        </div>
        <div>
            <h2 class="vc-done-t">Session Complete</h2>
            <p class="vc-done-s">Saving to your session history…</p>
        </div>
        <div class="vc-done-r">
            <span class="spinner" style="width:14px;height:14px;border-width:2px;"></span>
            Redirecting in <span id="redirect-count" style="color:rgba(255,255,255,.55);font-weight:600;">3</span>s
        </div>
    </div>

    <!--
        The Jitsi external API. It is what lets this page hear the call: with a
        plain iframe the red hangup button inside the frame ended the meeting
        and told us nothing, so the session was never closed and nobody was
        redirected — the top button and the toolbar button did different
        things. If this script cannot be reached the page falls back to the
        old plain iframe, with the toolbar's own hangup removed so the two
        controls still cannot disagree.
    -->
    <script src="https://<?= htmlspecialchars($meeting['domain']) ?>/external_api.js"></script>

    <script>
        const SESSION_ID = <?= $session_id ?>;
        const SESSION_END = <?= $sessionEndTs ?> * 1000; // ms
        const HISTORY_URL = <?= json_encode($history_url) ?>;
        const DURATION_MINS = <?= $durationMins ?>;
        const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
        const PING_URL = <?= json_encode(url('video-ping')) ?>;
        const END_URL = <?= json_encode(url('video-end')) ?>;

        const JITSI_DOMAIN = <?= json_encode($meeting['domain']) ?>;
        const JITSI_ROOM = <?= json_encode($meeting['room']) ?>;
        const JITSI_JWT = <?= json_encode($meeting['token']) ?>;
        const DISPLAY_NAME = <?= json_encode($display_name) ?>;

        let endCalled = false;
        let pingTimer = null;
        let jitsi = null;

        // ── 1. Close the session + redirect ──────────────────────
        async function endSession() {
            if (endCalled) return;
            endCalled = true;
            if (pingTimer) { clearInterval(pingTimer); pingTimer = null; }

            // Leave the meeting too, when we are the ones ending it. Harmless
            // when this was *triggered* by leaving: the guard above means the
            // event it raises comes straight back out.
            if (jitsi) { try { jitsi.executeCommand('hangup'); } catch (_) {} }

            document.getElementById('end-overlay').classList.add('show');

            let count = 3;
            const countEl = document.getElementById('redirect-count');
            const ticker = setInterval(() => {
                count--;
                if (countEl) countEl.textContent = count;
                if (count <= 0) clearInterval(ticker);
            }, 1000);

            try {
                await fetch(END_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ session_id: SESSION_ID, csrf_token: CSRF_TOKEN }),
                    keepalive: true,
                });
            } catch (_) {}

            setTimeout(() => { window.location.href = HISTORY_URL; }, 3000);
        }

        /* ── 2. Heartbeat ───────────────────────────────────────
           The server cannot see the call — it runs in an embedded frame — so
           without this it only ever learns two things: that the page was
           opened, and, if the browser gets the chance to say so, that it was
           closed. A crash, a closed laptop or a dead connection says nothing
           at all, and the session would be credited to its scheduled end.

           Every beat says "still here" and moves the record forward. Miss two
           and the visit is closed at the last one, so silence costs the time
           it lasted rather than being paid for. Failures are swallowed: a beat
           that does not land is exactly what the next one is for, and there is
           nothing useful to tell somebody in the middle of a call. */
        const PING_MS = 30000;

        function startHeartbeat() {
            pingTimer = setInterval(() => {
                if (endCalled) return;
                fetch(PING_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ session_id: SESSION_ID, csrf_token: CSRF_TOKEN }),
                    keepalive: true,
                }).catch(() => {});
            }, PING_MS);
        }

        /* Started here rather than on window 'load', which waits for the Jitsi
           iframe. A frame that loads slowly, or never finishes, must not cost
           somebody the session: the server already opened their interval when
           it rendered this page, and something has to keep it alive. */
        startHeartbeat();

        // ── 3. Mount the call ────────────────────────────────────
        let revealed = false;
        function revealCall() {
            if (revealed) return;
            revealed = true;
            const overlay = document.getElementById('loading-overlay');
            if (!overlay) return;
            overlay.style.opacity = '0';
            setTimeout(() => overlay.remove(), 500);
        }

        function mountCall() {
            const host = document.getElementById('call-host');

            if (typeof JitsiMeetExternalAPI === 'function') {
                try {
                    jitsi = new JitsiMeetExternalAPI(JITSI_DOMAIN, {
                        roomName: JITSI_ROOM,
                        jwt: JITSI_JWT || undefined,
                        parentNode: host,
                        width: '100%',
                        height: '100%',
                        userInfo: { displayName: DISPLAY_NAME },
                        configOverwrite: {
                            prejoinPageEnabled: true,
                            startWithAudioMuted: false,
                            startWithVideoMuted: false,
                            disableDeepLinking: true,
                        },
                        interfaceConfigOverwrite: {
                            SHOW_JITSI_WATERMARK: false,
                            SHOW_POWERED_BY: false,
                        },
                    });

                    // Both ways out of the call, so the toolbar's red button
                    // does exactly what End & Leave does.
                    jitsi.addListener('readyToClose', endSession);
                    jitsi.addListener('videoConferenceLeft', endSession);
                    jitsi.addListener('videoConferenceJoined', revealCall);
                    return;
                } catch (_) {
                    jitsi = null;
                    host.innerHTML = '';
                }
            }

            /* Fallback: the plain iframe this page used to use. Its toolbar
               has no hangup button — we would never hear it — so the only way
               out is End & Leave, which works. */
            const cfg = [
                'userInfo.displayName=' + encodeURIComponent(DISPLAY_NAME),
                'config.prejoinPageEnabled=true',
                'config.startWithAudioMuted=false',
                'config.startWithVideoMuted=false',
                'config.disableDeepLinking=true',
                'interfaceConfig.SHOW_JITSI_WATERMARK=false',
                'interfaceConfig.SHOW_POWERED_BY=false',
                'interfaceConfig.TOOLBAR_BUTTONS=' + encodeURIComponent(JSON.stringify([
                    'microphone', 'camera', 'desktop', 'chat', 'raisehand',
                    'participants-pane', 'tileview', 'settings', 'fullscreen'
                ])),
            ].join('&');

            const frame = document.createElement('iframe');
            frame.id = 'jitsi-frame';
            frame.allow = 'camera; microphone; fullscreen; display-capture; autoplay';
            frame.src = 'https://' + JITSI_DOMAIN + '/' + JITSI_ROOM
                + '#' + (JITSI_JWT ? 'jwt=' + JITSI_JWT + '&' : '') + cfg;
            host.appendChild(frame);
        }

        // ── 4. Auto-end when the time is up ──────────────────────
        function scheduleAutoEnd() {
            const ms = SESSION_END - Date.now();
            if (ms <= 0) { endSession(); return; }
            setTimeout(() => endSession(), ms);
        }

        // ── 5. Countdown ─────────────────────────────────────────
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
                el.classList.toggle('warn', m < 5);
            }
            setTimeout(updateTimer, 1000);
        }

        mountCall();
        // The prejoin screen is a page in its own right, so there is no "joined"
        // event to wait for before showing it.
        setTimeout(revealCall, 2500);
        updateTimer();
        scheduleAutoEnd();

        // ── 6. End & Leave / tab close ───────────────────────────
        document.getElementById('end-btn').addEventListener('click', endSession);

        window.addEventListener('beforeunload', () => {
            if (!endCalled) {
                navigator.sendBeacon(END_URL,
                    JSON.stringify({ session_id: SESSION_ID, csrf_token: CSRF_TOKEN }));
            }
        });
    </script>
</body>

</html>
