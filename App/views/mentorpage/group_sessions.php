<?php
/*
 * group_sessions.php
 * Included as a tab fragment inside session_request.php. Its own route
 * (mentor-groups) still serves the POST endpoint that removes a student, but
 * a GET redirects to the Sessions page's Group tab.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/session_list.php';


if (!isset($con)) {
    include __DIR__ . "/../db.php";
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mentor') {
    header("Location: " . url('welcomepage'));
    exit;
}

// Served on its own route this file is a bare fragment with no page shell —
// the notification and end-of-call links that point here landed the mentor on
// unstyled markup. The content lives in the Sessions page's group tab, so a
// direct GET goes there instead.
if (empty($sr_embedded) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: ' . url('mentor-requests') . '?tab=group');
    exit;
}

// Remove a reserved student from a group session slot. Scoped to requests
// owned by this mentor; sets status to 'cancelled' (same pattern the rest
// of the app uses for session_requests rather than hard-deleting rows).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_request_id'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        exit('CSRF token mismatch.');
    }
    require_once __DIR__ . '/../../services/NotificationService.php';
    $mentor_id_remove = (int)$_SESSION['user_id'];
    $reqId = (int)$_POST['remove_request_id'];

    $nq = $con->prepare("SELECT mentee_id, subject FROM session_requests WHERE request_id = ? AND mentor_id = ?");
    $nq->bind_param("ii", $reqId, $mentor_id_remove);
    $nq->execute();
    $target = $nq->get_result()->fetch_assoc();
    $nq->close();

    $removed = false;
    if ($target) {
        // Only a session that has not closed yet can have a student removed.
        // Without the state guard a mentor could "remove" someone from a
        // session that already completed, which rewrote a finished session as
        // cancelled and moved the mentor's own completion score.
        $upd = $con->prepare("UPDATE session_requests SET status = 'cancelled' WHERE request_id = ? AND mentor_id = ? AND status IN ('pending','approved')");
        $upd->bind_param("ii", $reqId, $mentor_id_remove);
        $upd->execute();
        $removed = $upd->affected_rows > 0;
        $upd->close();

        // Only tell the student if something actually changed. Notifying on a
        // refused removal would announce a cancellation that never happened.
        if ($removed) {
            $mn = $con->prepare("SELECT firstname, lastname FROM users WHERE user_id = ?");
            $mn->bind_param("i", $mentor_id_remove);
            $mn->execute();
            $mentorName = $mn->get_result()->fetch_assoc();
            $mn->close();
            if ($mentorName) {
                NotificationService::sessionRejected(
                    $con,
                    (int)$target['mentee_id'],
                    trim($mentorName['firstname'] . ' ' . $mentorName['lastname']),
                    url('mentee-request')
                );
            }
        }
    }

    if (!$target) {
        pc_flash('error', 'That reservation could not be found.');
    } elseif ($removed) {
        pc_flash('success', 'That student was removed from the group session and told.', 'Reservation removed');
    } else {
        pc_flash('warning', 'That reservation could not be removed — the session has already finished or been closed.');
    }

    header("Location: " . url('mentor-requests') . '?tab=group');
    exit;
}

$appTz_gs         = new DateTimeZone('Asia/Manila');
$groupAlerts_gs   = [];
$mentor_id_gs     = (int)$_SESSION['user_id'];

$groups_gs = $con->query("
    SELECT
        a.availability_id, a.subject, a.topics, a.date, a.start_time, a.duration, a.capacity,
        COUNT(sr.request_id) AS reserved_count
    FROM availability a
    LEFT JOIN session_requests sr
        ON  sr.mentor_id   = a.mentor_id
        AND sr.subject     = a.subject
        AND DATE(sr.session_date) = DATE(a.date)
        AND TIME(sr.session_date) = a.start_time
        AND sr.status IN ('pending','approved')
    WHERE a.mentor_id = $mentor_id_gs AND a.session_type = 'group'
    GROUP BY a.availability_id
    ORDER BY a.date, a.start_time
");
?>

<div>
    <?php mp_panel_head('gs', 'users', 'Group Sessions',
        'Slots where several mentees are booked together.', false, []); ?>

    <?php if ($groups_gs && $groups_gs->num_rows > 0): ?>
        <div style="display:flex;flex-direction:column;gap:16px;">
            <?php while ($g = $groups_gs->fetch_assoc()):
                $subject_gs   = htmlspecialchars($g['subject']);
                $availId_gs   = (int)$g['availability_id'];
                $reserved_gs  = $g['reserved_count'];
                $spotsLeft_gs = (int)$g['capacity'] - (int)$reserved_gs;
                $isFull_gs    = $spotsLeft_gs <= 0;

                $sessionStart_gs = new DateTime($g['date'] . ' ' . $g['start_time'], $appTz_gs);
                $durationMins_gs = (int)($g['duration'] ?? 30);
                $sessionEnd_gs   = (clone $sessionStart_gs)->modify("+{$durationMins_gs} minutes");
                $nowDt_gs        = new DateTime('now', $appTz_gs);
                $openStartDt_gs  = (clone $sessionStart_gs)->modify('-10 minutes');
                $canStart_gs     = $nowDt_gs >= $openStartDt_gs && $nowDt_gs <= $sessionEnd_gs;
                $minsToStart_gs  = (int)floor(($sessionStart_gs->getTimestamp() - $nowDt_gs->getTimestamp()) / 60);

                // First approved request for join_check.php
                $firstReqRow_gs = $con->query("
                SELECT request_id FROM session_requests
                WHERE mentor_id = $mentor_id_gs
                  AND subject    = '{$g['subject']}'
                  AND DATE(session_date) = '{$g['date']}'
                  AND TIME(session_date) = '{$g['start_time']}'
                  AND status = 'approved'
                ORDER BY request_id ASC LIMIT 1
            ");
                $firstReqId_gs = ($firstReqRow_gs && $firstReqRow_gs->num_rows > 0)
                    ? (int)$firstReqRow_gs->fetch_assoc()['request_id']
                    : 0;
                $joinUrl_gs = $firstReqId_gs > 0
                    ? url('video-join') . '?session_id=' . $firstReqId_gs . '&type=group'
                    : '#';

                // Reserved students list
                $students_gs = $con->query("
                SELECT u.firstname, u.lastname, sr.status, sr.session_date, sr.request_id
                FROM session_requests sr
                JOIN users u ON sr.mentee_id = u.user_id
                WHERE sr.mentor_id = $mentor_id_gs
                  AND sr.subject        = '{$g['subject']}'
                  AND DATE(sr.session_date) = '{$g['date']}'
                  AND TIME(sr.session_date) = '{$g['start_time']}'
                  AND sr.status IN ('pending','approved')
                ORDER BY sr.session_date ASC
            ");

                if ($minsToStart_gs >= 0 && $minsToStart_gs <= 10) {
                    $groupAlerts_gs[] = [
                        'key'     => 'mentor-group-' . $availId_gs,
                        'message' => 'Group session "' . $g['subject'] . '" starts in ' . $minsToStart_gs . ' minute(s).'
                    ];
                }
            ?>
                <!-- GROUP SESSION CARD -->
                <div class="card" style="overflow:hidden;">

                    <!-- Card header -->
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;padding:30px">

                        <!-- Left: subject + topics + meta -->
                        <div>
                            <div style="font-size:15px;font-weight:700;color:var(--gray-900);"><?= $subject_gs ?></div>

                            <?php if (!empty($g['topics'])): ?>
                                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">
                                    <?php foreach (explode(',', $g['topics']) as $topic_gs): ?>
                                        <span style="padding:2px 10px;border-radius:999px;font-size:11px;font-weight:500;background:var(--info-bg);color:var(--info);">
                                            <?= htmlspecialchars(trim($topic_gs)) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div style="font-size:12px;color:var(--gray-400);margin-top:6px;display:flex;align-items:center;gap:6px;">
                                <span><?= date("F d, Y", strtotime($g['date'])) ?></span>
                                <span style="color:var(--gray-200);">•</span>
                                <span><?= date("g:i A", strtotime($g['start_time'])) ?></span>
                                <span style="color:var(--gray-200);">•</span>
                                <span><?= $durationMins_gs ?> mins</span>
                            </div>
                        </div>

                        <!-- Right: seats + action button -->
                        <div style="text-align:right;">
                            <div style="font-size:12px;font-weight:600;color:<?= $isFull_gs ? 'var(--danger)' : 'var(--success)' ?>;">
                                <?= $isFull_gs ? '🔴 Full' : "🟢 {$spotsLeft_gs} of {$g['capacity']} seats left" ?>
                            </div>
                            <div style="font-size:11px;color:var(--gray-300);margin-top:2px;">Capacity: <?= (int)$g['capacity'] ?></div>

                            <div style="margin-top:10px;">
                                <?php if ($canStart_gs): ?>
                                    <?php if ($firstReqId_gs > 0): ?>
                                        <a href="<?= $joinUrl_gs ?>" class="btn btn-success" style="font-size:12px;padding:7px 16px;">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.069A1 1 0 0121 8.867v6.266a1 1 0 01-1.447.9L15 14M3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z" />
                                            </svg>
                                            Start Session
                                        </a>
                                    <?php else: ?>
                                        <span style="display:inline-flex;align-items:center;padding:7px 16px;background:var(--gray-100);color:var(--gray-400);border-radius:8px;font-size:12px;cursor:not-allowed;">
                                            No students approved
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div id="group-action-<?= $availId_gs ?>">
                                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--gray-400);background:var(--gray-100);padding:6px 12px;border-radius:8px;">
                                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span id="group-timer-<?= $availId_gs ?>">–</span>
                                        </span>
                                    </div>
                                    <script>
                                        (function() {
                                            const aid = <?= $availId_gs ?>;
                                            const openAt = <?= $openStartDt_gs->getTimestamp() ?>;
                                            const endAt = <?= $sessionEnd_gs->getTimestamp() ?>;
                                            const firstReqId = <?= $firstReqId_gs ?>;
                                            const url = firstReqId > 0 ?
                                                '<?= url('video-join') ?>?session_id=' + firstReqId + '&type=group' :
                                                null;
                                            const timerEl = document.getElementById('group-timer-' + aid);
                                            const actionEl = document.getElementById('group-action-' + aid);
                                            let replaced = false;

                                            function showButton() {
                                                if (replaced) return;
                                                replaced = true;
                                                if (!url) {
                                                    actionEl.innerHTML = '<span style="display:inline-flex;align-items:center;padding:7px 16px;background:var(--gray-100);color:var(--gray-400);border-radius:8px;font-size:12px;cursor:not-allowed;">No students approved</span>';
                                                    return;
                                                }
                                                actionEl.innerHTML =
                                                    '<a href="' + url + '" style="display:inline-flex;align-items:center;gap:6px;padding:7px 16px;background:var(--success);color:#fff;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;">' +
                                                    '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.069A1 1 0 0121 8.867v6.266a1 1 0 01-1.447.9L15 14M3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/></svg>' +
                                                    'Start Session</a>';
                                                if ('Notification' in window && Notification.permission === 'granted') {
                                                    new Notification('NEUST – Group Session starting now!', {
                                                        body: 'Your group session is ready. Click to join.',
                                                        icon: '<?= BASE_URL ?>/public/icons/icon.svg'
                                                    });
                                                }
                                            }

                                            function tick() {
                                                const now = Math.floor(Date.now() / 1000);
                                                if (now >= endAt) {
                                                    if (actionEl) actionEl.innerHTML = '<span style="font-size:12px;color:var(--gray-300);">Ended</span>';
                                                    return;
                                                }
                                                if (now >= openAt) {
                                                    showButton();
                                                    return;
                                                }
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
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Reserved students table -->
                    <div style="border-top:1px solid var(--gray-100);padding:20px;">
                        <div style="font-size:11px;color:var(--gray-400);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:10px;">Reserved Students</div>
                        <?php if ($students_gs && $students_gs->num_rows > 0): ?>
                            <div style="overflow-x:auto;">
                            <table class="tbl">
                                <thead>
                                    <tr>
                                        <th style="width:40px;">#</th>
                                        <th>Student</th>
                                        <th>Reserved On</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i_gs = 1;
                                    while ($st = $students_gs->fetch_assoc()):
                                        $initials_st = strtoupper(substr($st['firstname'], 0, 1) . substr($st['lastname'], 0, 1));
                                    ?>
                                        <tr>
                                            <td style="color:var(--gray-300);font-size:12px;"><?= $i_gs++ ?></td>
                                            <td>
                                                <div style="display:flex;align-items:center;gap:10px;">
                                                    <div class="tbl-avatar" style="background:var(--mint-soft);color:var(--forest);"><?= $initials_st ?></div>
                                                    <span style="font-weight:600;color:var(--gray-800);"><?= htmlspecialchars($st['firstname'] . ' ' . $st['lastname']) ?></span>
                                                </div>
                                            </td>
                                            <td style="font-size:12px;color:var(--gray-400);"><?= date("M d, Y g:i A", strtotime($st['session_date'])) ?></td>
                                            <td>
                                                <?php if ($st['status'] === 'approved'): ?>
                                                    <span class="badge badge-completed">Approved</span>
                                                <?php else: ?>
                                                    <span class="badge badge-pending">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:right;">
                                                <button type="button" onclick="removeGroupStudent(<?= (int)$st['request_id'] ?>, '<?= htmlspecialchars(addslashes($st['firstname'] . ' ' . $st['lastname'])) ?>')" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:12px;font-weight:600;">Remove</button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state" style="padding:20px 0;">
                                <div class="empty-icon">👥</div>
                                <p style="font-weight:600;color:var(--gray-600);margin-bottom:4px;">No students reserved yet</p>
                            </div>
                        <?php endif; ?>
                    </div>

                </div><!-- /card -->
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="empty-state">
                <div class="empty-icon">🎓</div>
                <p style="font-weight:600;color:var(--gray-600);margin-bottom:4px;">No group sessions found</p>
                <p>Group sessions you create in your availability will appear here.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($groupAlerts_gs)): ?>
    <script>
        (function() {
            const _alerts = <?= json_encode($groupAlerts_gs) ?>;
            _alerts.forEach(function(a) {
                const k = 'notify_' + a.key;
                if (!sessionStorage.getItem(k)) {
                    alert(a.message);
                    sessionStorage.setItem(k, '1');
                }
            });
        })();
    </script>
<?php endif; ?>

<script>
    function removeGroupStudent(requestId, studentName) {
        if (!confirm('Remove ' + studentName + ' from this group session?')) return;
        fetch('<?= url('mentor-groups') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'remove_request_id=' + requestId + '&csrf_token=' + encodeURIComponent('<?= csrf_token() ?>')
        }).then(() => { window.location.href = '<?= url('mentor-requests') ?>?tab=group'; });
    }
</script>