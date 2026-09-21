<?php

/**
 * upcoming_session.php — the Upcoming tab of the mentor Sessions page.
 * Its own route (`mentor-upcoming`) redirects to that page's Upcoming tab.
 *
 * Card layout is shared with Completed / Declined / History (see
 * includes/session_list.php). What is specific here is the join control: the
 * lobby only lets a mentor in from 10 minutes before the start until the end,
 * so until then the card shows a live countdown that replaces itself with the
 * Start Session button when the window opens. That behaviour is unchanged.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (($_SESSION['role'] ?? null) !== 'mentor') {
    header("Location: " . url('welcomepage'));
    exit;
}

if (!isset($con)) {
    include __DIR__ . "/../db.php";
}
require_once __DIR__ . '/includes/session_list.php';

// Served on its own route this file is a bare fragment with no page shell —
// the notification and end-of-call links that point here landed the mentor on
// unstyled markup. The content lives in the Sessions page's upcoming tab, so a
// direct GET goes there instead.
if (empty($sr_embedded) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: ' . url('mentor-requests') . '?tab=upcoming');
    exit;
}


$mentor_id_us    = (int)$_SESSION['user_id'];
$appTz_us        = new DateTimeZone('Asia/Manila');
$mentorAlerts_us = [];

$rows_us = SessionRepository::approvedOneToOneForMentor($con, $mentor_id_us);
?>

<div>
    <?php mp_panel_open('us', 'calendar', 'Upcoming Sessions', 'Sessions you have approved that are still ahead.'); ?>

    <?php if (!$rows_us): ?>
        <div class="card empty-state-lg">
            <span class="es-icon"><?= mp_svg('calendar') ?></span>
            <h3 class="es-title">No upcoming sessions</h3>
            <p class="es-body">Approved sessions appear here. Opening availability makes you easier to book.</p>
            <a class="btn btn-primary" href="<?= htmlspecialchars(url('mentor-calendar')) ?>">Manage availability</a>
        </div>
    <?php else: ?>
        <?php foreach ($rows_us as $r):
            $sessionStart_us = new DateTime($r['session_date'], $appTz_us);
            $durationMins_us = isset($r['duration']) ? (int)$r['duration'] : 30;
            $sessionEnd_us   = (clone $sessionStart_us)->modify("+{$durationMins_us} minutes");
            $nowDt_us        = new DateTime('now', $appTz_us);
            $openStartDt_us  = (clone $sessionStart_us)->modify('-10 minutes');
            $canStart_us     = $nowDt_us >= $openStartDt_us && $nowDt_us <= $sessionEnd_us;
            $minsToStart_us  = (int)floor(($sessionStart_us->getTimestamp() - $nowDt_us->getTimestamp()) / 60);
            $requestId_us    = (int)$r['request_id'];
            $joinUrl_us      = url('video-join') . '?session_id=' . $requestId_us;

            if ($minsToStart_us >= 0 && $minsToStart_us <= 10) {
                $mentorAlerts_us[] = [
                    'key'     => 'mentor-upcoming-' . $requestId_us,
                    'message' => 'Session with ' . $r['firstname'] . ' ' . $r['lastname'] .
                        ' starts in ' . $minsToStart_us . ' minute' . ($minsToStart_us === 1 ? '' : 's') . '.'
                ];
            }

            // Built here rather than passed as a plain link: before the window
            // opens this is a countdown, not a button.
            ob_start();
            if ($canStart_us) : ?>
                <a href="<?= htmlspecialchars($joinUrl_us) ?>" class="btn btn-success btn-sm" style="justify-content:center;">
                    <?= mp_svg('video', 'width="14" height="14"') ?> Start Session
                </a>
            <?php else: ?>
                <div id="action-<?= $requestId_us ?>" style="text-align:center;">
                    <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--gray-500);background:var(--gray-100);padding:7px 12px;border-radius:8px;">
                        <?= mp_svg('clock', 'width="13" height="13"') ?>
                        <span id="timer-<?= $requestId_us ?>">–</span>
                    </span>
                </div>
                <script>
                    (function() {
                        const rid = <?= $requestId_us ?>;
                        const openAt = <?= $openStartDt_us->getTimestamp() ?>;
                        const endAt = <?= $sessionEnd_us->getTimestamp() ?>;
                        const url = <?= json_encode($joinUrl_us) ?>;
                        const timerEl = document.getElementById('timer-' + rid);
                        const actionEl = document.getElementById('action-' + rid);
                        let replaced = false;

                        function showButton() {
                            if (replaced) return;
                            replaced = true;
                            const a = document.createElement('a');
                            a.href = url;
                            a.className = 'btn btn-success btn-sm';
                            a.style.justifyContent = 'center';
                            a.textContent = 'Start Session';
                            actionEl.replaceChildren(a);
                            if ('Notification' in window && Notification.permission === 'granted') {
                                new Notification('PeerConnect — session starting now', {
                                    body: 'Your session is ready. Click to join.'
                                });
                            }
                        }

                        function tick() {
                            const now = Math.floor(Date.now() / 1000);
                            if (now >= endAt) {
                                if (actionEl) actionEl.textContent = 'Ended';
                                return;
                            }
                            if (now >= openAt) return showButton();
                            const diff = openAt - now;
                            const h = Math.floor(diff / 3600);
                            const m = Math.floor((diff % 3600) / 60);
                            const s = diff % 60;
                            if (timerEl) timerEl.textContent = h > 0 ?
                                h + 'h ' + String(m).padStart(2, '0') + 'm' :
                                m + 'm ' + String(s).padStart(2, '0') + 's';
                            setTimeout(tick, 1000);
                        }
                        tick();
                        if ('Notification' in window && Notification.permission === 'default')
                            Notification.requestPermission();
                    })();
                </script>
            <?php endif;
            $actionHtml_us = ob_get_clean();

            mp_session_card([
                'initials' => strtoupper(substr($r['firstname'], 0, 1) . substr($r['lastname'], 0, 1)),
                'tint'     => (int)$r['mentee_id'] % 5,
                'name'     => trim($r['firstname'] . ' ' . $r['lastname']),
                'email'    => $r['email'] ?? '',
                'course'   => $r['course'] ?? '',
                'subject'  => $r['subject'] ?: '—',
                'date'     => date('M d, Y', strtotime($r['session_date'])),
                'time'     => date('g:i A', strtotime($r['session_date'])),
                'note'       => trim((string)$r['message']),
                'note_label' => "Mentee's note",
                'badge'       => 'Approved',
                'badge_class' => 'badge-approved',
                'search'   => $r['firstname'] . ' ' . $r['lastname'] . ' ' . $r['subject'] . ' ' . $r['message'],
                'sortkey'  => strtotime($r['session_date']),
                'actions'  => [
                    ['kind' => 'icon', 'icon' => 'chat', 'title' => 'Message this mentee',
                     'href' => url('messages') . '?chat=' . (int)$r['mentee_id']],
                    // session_ics.php only serves approved sessions, which is
                    // exactly what this tab lists.
                    ['kind' => 'icon', 'icon' => 'calendar', 'title' => 'Add to calendar',
                     'href' => url('session-ics') . '?session_id=' . $requestId_us],
                ],
                'actions_html' => $actionHtml_us,
            ]);
        endforeach; ?>
    <?php endif; ?>

    <?php
    $n_us = count($rows_us);
    mp_panel_close(
        'us',
        $n_us ? 'Coming up' : '',
        $n_us ? 'You have ' . $n_us . ' session' . ($n_us === 1 ? '' : 's') . ' approved and still ahead.' : ''
    );
    ?>
</div>
<?php mp_panel_script('us'); ?>

<?php if (!empty($mentorAlerts_us)): ?>
    <script>
        (function() {
            const _alerts = <?= json_encode($mentorAlerts_us) ?>;
            _alerts.forEach(function(a) {
                /* sessionStorage so the alert fires once per browser session. */
                const k = 'notify_' + a.key;
                if (!sessionStorage.getItem(k)) {
                    pcToast(a.message, 'warning', 10000, 'Starting soon');
                    sessionStorage.setItem(k, '1');
                }
            });
        })();
    </script>
<?php endif; ?>
