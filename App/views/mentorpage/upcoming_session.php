<?php

/**
 * upcoming_session.php — the Upcoming tab of the mentor Sessions page.
 * Its own route (`mentor-upcoming`) redirects to that page's Upcoming tab.
 *
 * Card layout is shared with Ongoing / Completed / History (see
 * includes/session_list.php). What is specific here is that nothing on this
 * tab can be joined yet: it lists only sessions whose call has not opened,
 * so each card carries a live countdown to that moment and then points at
 * Ongoing, which is the tab holding the Join control.
 *
 * Group sessions appear here as one card per slot, not one per booking.
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

/*
 * One entry per session, not per booking — the same fold the dashboard does.
 * A group slot four mentees booked is one session the mentor runs once, so
 * it gets one card naming who is in it rather than four identical ones.
 *
 * Only slots the availability record actually calls 'group' are folded.
 * A booking whose slot was edited or deleted joins on nothing, and those stay
 * one card each rather than being guessed into a group.
 */
$rows_raw_us = SessionRepository::upcomingForMentor($con, $mentor_id_us);
$rows_us     = [];

foreach ($rows_raw_us as $r) {
    $isGroup_us = ($r['session_type'] ?? null) === 'group';
    $key_us = $isGroup_us
        ? 'g|' . $r['subject'] . '|' . $r['session_date']
        : 'b|' . $r['request_id'];

    if (!isset($rows_us[$key_us])) {
        $r['is_group']  = $isGroup_us;
        $r['members']   = [];
        $rows_us[$key_us] = $r;
    }
    // Keyed by booking id, so a duplicated availability row cannot list the
    // same mentee twice through the LEFT JOIN. The whole record is kept, not
    // just the name, because the detail modal lists participants with their
    // address and needs the booking id to remove one.
    $rows_us[$key_us]['members'][(int)$r['request_id']] = [
        'id'     => (int)$r['request_id'],
        'name'   => trim($r['firstname'] . ' ' . $r['lastname']),
        'email'  => (string)($r['email'] ?? ''),
        'status' => (string)($r['status'] ?? ''),
    ];
}
$rows_us = array_values($rows_us);
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
            $openStartDt_us  = (clone $sessionStart_us)->modify('-' . SessionRepository::JOIN_WINDOW_MINUTES . ' minutes');
            $minsToStart_us  = (int)floor(($sessionStart_us->getTimestamp() - $nowDt_us->getTimestamp()) / 60);
            $requestId_us    = (int)$r['request_id'];

            if ($minsToStart_us >= 0 && $minsToStart_us <= SessionRepository::JOIN_WINDOW_MINUTES) {
                $who_us = !empty($r['is_group'])
                    ? 'Group session in ' . $r['subject']
                    : 'Session with ' . $r['firstname'] . ' ' . $r['lastname'];
                $mentorAlerts_us[] = [
                    'key'     => 'mentor-upcoming-' . $requestId_us,
                    'message' => $who_us . ' starts in ' . $minsToStart_us . ' minute' . ($minsToStart_us === 1 ? '' : 's') . '.'
                ];
            }

            /*
             * A countdown, never a Start button. This tab only holds sessions
             * whose call has not opened yet — the moment it does they belong
             * to Ongoing, which is where the Join control lives. When the
             * countdown runs out the card points there rather than growing a
             * button on a tab it is about to leave.
             */
            ob_start(); ?>
                <div id="action-<?= $requestId_us ?>" style="text-align:center;">
                    <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--gray-500);background:var(--gray-100);padding:7px 12px;border-radius:8px;">
                        <?= mp_svg('clock', 'width="13" height="13"') ?>
                        <span id="timer-<?= $requestId_us ?>">&ndash;</span>
                    </span>
                </div>
                <script>
                    (function() {
                        const rid = <?= $requestId_us ?>;
                        const openAt = <?= $openStartDt_us->getTimestamp() ?>;
                        const goTo = <?= json_encode(url('mentor-requests') . '?tab=ongoing') ?>;
                        const timerEl = document.getElementById('timer-' + rid);
                        const actionEl = document.getElementById('action-' + rid);
                        let replaced = false;

                        function showOngoing() {
                            if (replaced || !actionEl) return;
                            replaced = true;
                            const a = document.createElement('a');
                            a.href = goTo;
                            a.className = 'btn btn-success btn-sm';
                            a.style.justifyContent = 'center';
                            a.textContent = 'Starting now — open Ongoing';
                            actionEl.replaceChildren(a);
                            if ('Notification' in window && Notification.permission === 'granted') {
                                new Notification('PeerConnect — session starting now', {
                                    body: 'Your session is ready to join.'
                                });
                            }
                        }

                        function tick() {
                            const now = Math.floor(Date.now() / 1000);
                            if (now >= openAt) return showOngoing();
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
            <?php
            $actionHtml_us = ob_get_clean();

            $isGroupCard_us = !empty($r['is_group']);
            $members_us     = array_values($r['members'] ?? []);
            $names_us       = array_column($members_us, 'name');
            $booked_us      = count($members_us);
            $capacity_us    = (int)($r['capacity'] ?? 0);

            // A group has no single mentee, so the people line names the
            // session and the mentees go in the note block, which is already
            // a labelled free-text area. A 1-on-1 is unchanged.
            $seats_us = $capacity_us > 0
                ? $booked_us . ' of ' . $capacity_us . ' booked'
                : $booked_us . ' booked';

            /*
             * What the detail modal shows for this card. Nothing here is
             * rendered now; session_request.php emits the whole lot as JSON
             * once the tabs have run.
             */
            $detailKey_us = ($isGroupCard_us ? 'g' : 'b') . $requestId_us;
            $sr_session_details[$detailKey_us] = [
                'is_group'     => $isGroupCard_us,
                'subject'      => (string)($r['subject'] ?: 'Session'),
                'when'         => date('M d, Y', strtotime($r['session_date'])) . ' · '
                                  . date('g:i A', strtotime($r['session_date'])),
                'status_label' => 'Approved — not started yet',
                'seats'        => $seats_us,
                'mentee'       => trim($r['firstname'] . ' ' . $r['lastname']),
                'email'        => (string)($r['email'] ?? ''),
                'course'       => (string)($r['course'] ?? ''),
                'note'         => trim((string)($r['message'] ?? '')),
                // Nothing on this tab has started, so every booking can still
                // be withdrawn. cancelByMentor refuses anything else anyway.
                'participants' => array_map(fn($mem) => [
                    'id'        => $mem['id'],
                    'name'      => $mem['name'],
                    'email'     => $mem['email'],
                    'removable' => in_array($mem['status'], ['pending', 'approved'], true),
                ], $members_us),
            ];

            $detailBtn_us = '<button type="button" class="btn btn-outline btn-sm" style="justify-content:center;"'
                . ' onclick="openSessionDetail(' . htmlspecialchars(json_encode($detailKey_us), ENT_QUOTES) . ')">'
                . 'View details</button>';

            $cardActions_us = [];
            if (!$isGroupCard_us) {
                $cardActions_us[] = ['kind' => 'icon', 'icon' => 'chat', 'title' => 'Message this mentee',
                                     'href' => url('messages') . '?chat=' . (int)$r['mentee_id']];
            }
            // session_ics.php only serves approved sessions, which is exactly
            // what this tab lists. Any booking of a group slot describes the
            // same session, so the one carrying the card will do.
            $cardActions_us[] = ['kind' => 'icon', 'icon' => 'calendar', 'title' => 'Add to calendar',
                                 'href' => url('session-ics') . '?session_id=' . $requestId_us];

            $statusLabel_us = ($r['status'] ?? 'approved') === 'unfinished' ? 'Unfinished' : 'Approved';
            $statusClass_us = $statusLabel_us === 'Unfinished' ? 'badge-orange' : 'badge-approved';

            mp_session_card([
                // As in Ongoing: a group card is not one person's face.
                'avatar'   => $isGroupCard_us ? '' : ($r['profile_image'] ?? ''),
                'initials' => $isGroupCard_us
                    ? strtoupper(substr((string)($r['subject'] ?: 'G'), 0, 2))
                    : strtoupper(substr($r['firstname'], 0, 1) . substr($r['lastname'], 0, 1)),
                'tint'     => (int)$r['mentee_id'] % 5,
                'name'     => $isGroupCard_us
                    ? 'Group session · ' . $seats_us
                    : trim($r['firstname'] . ' ' . $r['lastname']),
                'email'    => $isGroupCard_us ? '' : ($r['email'] ?? ''),
                'course'   => $isGroupCard_us ? '' : ($r['course'] ?? ''),
                'subject'  => $r['subject'] ?: '—',
                'date'     => date('M d, Y', strtotime($r['session_date'])),
                'time'     => date('g:i A', strtotime($r['session_date'])),
                'note'       => $isGroupCard_us ? implode(', ', $names_us) : trim((string)$r['message']),
                'note_label' => $isGroupCard_us ? 'Mentees' : "Mentee's note",
                'badge'       => $statusLabel_us,
                'badge_class' => $statusClass_us,
                'search'   => ($isGroupCard_us ? implode(' ', $names_us) : $r['firstname'] . ' ' . $r['lastname'])
                              . ' ' . $r['subject'] . ' ' . $r['message'],
                'sortkey'  => strtotime($r['session_date']),
                'actions'  => $cardActions_us,
                'actions_html' => $detailBtn_us . $actionHtml_us,
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
