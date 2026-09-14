<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
// Both are called by the cancel handler below. GoogleCalendarService was being
// used without ever being loaded — App/services has no autoloader, so every
// cancellation ended in a fatal after the status had already been written.
require_once __DIR__ . '/../../services/GoogleCalendarService.php';
require_once __DIR__ . '/../../services/NotificationService.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mentee') {
    header("Location: " . url('welcomepage'));
    exit;
}

$mentee_id = $_SESSION['user_id'];
$appTz     = new DateTimeZone('Asia/Manila');
$menteeAlerts = [];

// SECURITY: cancel/remove are state-changing — POST + CSRF only (was GET, forgeable via a link)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel' && isset($_POST['id'])) {
    $ajax = !empty($_POST['ajax']);
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        if ($ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Security token mismatch.']);
        } else {
            echo 'CSRF token mismatch.';
        }
        exit;
    }
    $id = (int)$_POST['id'];

    // Read the row before the update — afterwards there is no way to tell an
    // approved session (the mentor was expecting it) from one still pending.
    $before = $con->prepare("
        SELECT sr.mentor_id, sr.subject, sr.session_date, sr.status,
               CONCAT(u.firstname,' ',u.lastname) AS mentee_name
        FROM session_requests sr
        JOIN users u ON u.user_id = sr.mentee_id
        WHERE sr.request_id = ? AND sr.mentee_id = ?
    ");
    $before->bind_param("ii", $id, $mentee_id);
    $before->execute();
    $cancelled = $before->get_result()->fetch_assoc();
    $before->close();

    // Only a session that has not closed yet can be cancelled. Without the
    // state guard a completed session — one with feedback attached and counted
    // in the mentor's score — could be flipped to cancelled, which silently
    // moved the completion rate on three report pages and re-notified the
    // mentor about a session that already happened. The Cancel button is only
    // rendered while the status is 'pending', so this refuses nothing the
    // interface offers; it closes the same request arriving any other way.
    $stmt = $con->prepare("UPDATE session_requests SET status='cancelled' WHERE request_id=? AND mentee_id=? AND status IN ('pending','approved')");
    $stmt->bind_param("ii", $id, $mentee_id);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        // Take it back out of any connected Google Calendar. Never fatal.
        GoogleCalendarService::pushSession($con, $id);

        // Tell the mentor. They had this in their schedule; silently dropping
        // it means they only find out by re-checking the requests page.
        if ($cancelled) {
            $when = (new DateTime($cancelled['session_date'], $appTz))->format('M j, g:i A');
            NotificationService::send(
                $con,
                (int)$cancelled['mentor_id'],
                'session_cancelled',
                'Session Cancelled',
                $cancelled['mentee_name'] . ' cancelled the ' . $cancelled['subject'] . ' session on ' . $when . '.',
                url('mentor-requests')
            );
        }
    }
    $wasCancelled = $stmt->affected_rows > 0;
    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $wasCancelled ? 'Session cancelled — your mentor has been told.' : 'That session can no longer be cancelled.',
        ]);
        exit;
    }
    if ($wasCancelled) {
        pc_flash('success', 'Your mentor has been told.', 'Session cancelled');
    } else {
        pc_flash('warning', 'That session can no longer be cancelled — it has already finished or been closed.');
    }
    header("Location: " . url('mentee-request'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove' && isset($_POST['id'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        exit('CSRF token mismatch.');
    }
    $id = (int)$_POST['id'];
    $stmt = $con->prepare("DELETE FROM session_requests WHERE request_id=? AND mentee_id=?");
    $stmt->bind_param("ii", $id, $mentee_id);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        pc_flash('success', 'That request was removed from your list.', 'Request removed');
    }
    header("Location: " . url('mentee-request'));
    exit;
}

$status_filter = $_GET['status'] ?? '';
$search        = $_GET['search'] ?? '';
$where = "WHERE sr.mentee_id = $mentee_id AND sr.status != 'cancelled'";
if ($status_filter && $status_filter !== 'all') {
    $sf = $con->real_escape_string($status_filter);
    $where .= " AND sr.status = '$sf'";
}
if ($search) {
    $s = $con->real_escape_string($search);
    $where .= " AND (u.firstname LIKE '%$s%' OR u.lastname LIKE '%$s%')";
}

$requests = $con->query("
    SELECT sr.*, u.firstname, u.lastname, a.session_type, a.duration, CONCAT(u.firstname,' ',u.lastname) AS mentor_name
    FROM session_requests sr
    JOIN users u ON sr.mentor_id = u.user_id
    LEFT JOIN availability a ON a.mentor_id = sr.mentor_id AND a.subject = sr.subject AND DATE(a.date) = DATE(sr.session_date) AND TIME(a.start_time) = TIME(sr.session_date)
    $where ORDER BY sr.session_date DESC
");

$archived = $con->query("
    SELECT sr.*, u.firstname, u.lastname, sr.mentor_id, CONCAT(u.firstname,' ',u.lastname) AS mentor_name
    FROM session_requests sr
    JOIN users u ON sr.mentor_id = u.user_id
    WHERE sr.mentee_id = $mentee_id AND sr.status = 'cancelled'
    ORDER BY sr.session_date DESC
");

$request_url = url('mentee-request');
$find_mentor_url = url('mentee-find');
$view_mentor_url = url('mentee-view-mentor');
$active_page = 'request';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Requests – NEUST</title>
    <?php include __DIR__ . '/includes/style.php'; ?>
    <style>
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-wrap {
            position: relative;
            flex: 1;
            min-width: 200px;
            max-width: 340px;
        }

        .search-wrap svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            pointer-events: none;
        }

        .search-wrap .form-input {
            padding-left: 36px;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(31, 78, 69, .45);
            backdrop-filter: blur(4px);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: var(--surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            animation: fadeSlideUp .2s ease;
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main fade-in">
            <div class="page-hd" style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h1>My Requests</h1>
                    <p>Your mentoring session requests</p>
                </div>
                <div>
                    <button onclick="openArchive()" class="btn btn-ghost" style="flex-shrink:0;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                        </svg>
                        Archived
                    </button>                </div>
            </div>

            <!-- Filter -->
            <form method="GET" class="filter-bar">
                <div class="search-wrap">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search mentors…" class="form-input">
                </div>
                <select name="status" onchange="this.form.submit()" class="form-input" style="width:auto;padding:9px 13px;">
                    <option value="all" <?= !$status_filter || $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="pending" <?= $status_filter === 'pending'  ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
                <?php if ($search): ?><a href="<?= htmlspecialchars($request_url) ?>" style="font-size:13px;color:var(--gray-400);">Clear</a><?php endif; ?>
            </form>

            <!-- Table -->
            <div class="card" style="overflow:hidden;">
                <div style="overflow-x:auto;">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Mentor</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($requests && $requests->num_rows > 0): ?>
                            <?php while ($r = $requests->fetch_assoc()):
                                $status        = $r['status'];
                                $session_start = strtotime($r['session_date']);
                                $durationMins  = isset($r['duration']) ? (int)$r['duration'] : 30;
                                $session_end   = $session_start + ($durationMins * 60);
                                $sessionStartDt = new DateTime($r['session_date'], $appTz);
                                $sessionEndDt   = (clone $sessionStartDt)->modify("+{$durationMins} minutes");
                                $openJoinDt     = (clone $sessionStartDt)->modify('-10 minutes');
                                $nowDt          = new DateTime('now', $appTz);
                                $canJoin        = $status === 'approved' && $nowDt >= $openJoinDt && $nowDt <= $sessionEndDt;
                                $minsToStart    = (int)floor(($sessionStartDt->getTimestamp() - $nowDt->getTimestamp()) / 60);
                                if ($status === 'approved' && $minsToStart >= 0 && $minsToStart <= 10) {
                                    $menteeAlerts[] = ['key' => 'mentee-request-' . (int)$r['request_id'], 'message' => 'Session with ' . $r['mentor_name'] . ' starts in ' . $minsToStart . ' minute(s).'];
                                }
                            ?>
                                <tr>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:10px;">
                                            <div class="tbl-avatar"><?= strtoupper(substr($r['mentor_name'], 0, 1)) ?></div>
                                            <div>
                                                <div style="font-weight:600;color:var(--gray-800);"><?= htmlspecialchars($r['mentor_name']) ?></div>
                                                <div style="font-size:11px;color:var(--gray-400);"><?= htmlspecialchars($r['subject'] ?? '') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= date("m-d-Y", $session_start) ?></td>
                                    <td><?= date("g:i", $session_start) ?>–<?= date("g:i a", $session_end) ?></td>
                                    <td><span class="badge badge-<?= $status ?>"><?= ucfirst($status) ?></span></td>
                                    <td>
                                        <?php if ($status === 'pending'): ?>
                                            <button onclick="openCancelModal(<?= $r['request_id'] ?>, '<?= htmlspecialchars($r['mentor_name'], ENT_QUOTES) ?>')" class="btn btn-red" style="font-size:12px;padding:5px 12px;">Cancel</button>
                                        <?php elseif ($status === 'approved'): ?>
                                            <button onclick="openSession('<?= htmlspecialchars($r['mentor_name'], ENT_QUOTES) ?>','<?= htmlspecialchars($r['subject'] ?? '', ENT_QUOTES) ?>','<?= date('F j, Y', $session_start) ?>','<?= date('g:i A', $session_start) ?> – <?= date('g:i A', $session_end) ?>','<?= $durationMins ?> min','<?= htmlspecialchars($r['session_type'] ?? 'N/A', ENT_QUOTES) ?>',<?= $canJoin ? 'true' : 'false' ?>,'<?= htmlspecialchars($r['meet_link'] ?? '', ENT_QUOTES) ?>','<?= $status ?>')" class="btn btn-ghost" style="font-size:12px;padding:5px 12px;">View</button>
                                        <?php elseif ($status === 'rejected'): ?>
                                            <button onclick="removeRequest(<?= $r['request_id'] ?>)" class="btn btn-red" style="font-size:12px;padding:5px 12px;">Remove</button>
                                        <?php else: ?>
                                            <span style="color:var(--gray-300);">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state" style="padding:40px 0;">
                                        <div class="empty-icon">📋</div>
                                        <p style="color:var(--gray-500);font-weight:600;">No requests found</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </main>
    </div>

    <!-- ══════════════ CANCEL CONFIRMATION MODAL ══════════════ -->
    <div id="cancelModal" class="modal-overlay">
        <div class="modal-box" style="width:420px;max-width:95vw;">
            <div style="padding:18px 24px;border-bottom:1px solid var(--gray-100);display:flex;align-items:center;justify-content:space-between;">
                <div style="font-size:15px;font-weight:700;color:var(--gray-900);">Cancel Request</div>
                <button onclick="closeCancelModal()" style="width:30px;height:30px;border-radius:8px;border:1px solid var(--gray-200);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div style="padding:24px;">
                <div style="width:48px;height:48px;border-radius:50%;background:var(--warning-bg);display:flex;align-items:center;justify-content:center;margin-bottom:14px;">
                    <svg width="22" height="22" fill="none" stroke="var(--warning)" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                    </svg>
                </div>
                <p style="font-size:14px;font-weight:600;color:var(--gray-900);margin-bottom:6px;">Are you sure you want to cancel?</p>
                <p id="cancelModalDesc" style="font-size:13px;color:var(--gray-500);margin-bottom:20px;line-height:1.5;">This will cancel your session request. You can book again anytime.</p>
                <div style="display:flex;gap:10px;">
                    <button onclick="closeCancelModal()" class="btn btn-ghost" style="flex:1;justify-content:center;">Keep Request</button>
                    <button onclick="doCancel()" class="btn btn-red" style="flex:1;justify-content:center;">Yes, Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════ CANCELLATION SUCCESS MODAL ══════════════ -->
    <div id="cancelSuccessModal" class="modal-overlay">
        <div class="modal-box" style="width:360px;max-width:95vw;">
            <div style="padding:36px 28px;text-align:center;">
                <div style="width:56px;height:56px;border-radius:50%;background:var(--success-bg);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <svg width="26" height="26" fill="none" stroke="var(--success)" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <h2 style="font-size:18px;font-weight:700;color:var(--gray-900);margin-bottom:8px;">Request Cancelled</h2>
                <p style="font-size:13px;color:var(--gray-500);margin-bottom:24px;line-height:1.6;">Your session request has been successfully cancelled. You can book a new session anytime.</p>
                <div style="display:flex;gap:10px;">
                    <button onclick="closeCancelSuccess()" class="btn btn-ghost" style="flex:1;justify-content:center;">Close</button>
                    <a href="<?= htmlspecialchars($find_mentor_url) ?>" class="btn btn-blue" style="flex:1;justify-content:center;">Find a Mentor</a>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════ ARCHIVE MODAL ══════════════ -->
    <div id="archiveModal" class="modal-overlay">
        <div class="modal-box" style="width:620px;max-width:95vw;max-height:80vh;display:flex;flex-direction:column;">
            <div style="padding:18px 24px;border-bottom:1px solid var(--gray-100);display:flex;align-items:center;justify-content:space-between;">
                <div style="font-size:15px;font-weight:700;color:var(--gray-900);">Archived Requests</div>
                <button onclick="closeArchive()" style="width:30px;height:30px;border-radius:8px;border:1px solid var(--gray-200);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div style="overflow-y:auto;overflow-x:auto;flex:1;">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Mentor</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($archived && $archived->num_rows > 0): ?>
                            <?php while ($a = $archived->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:600;color:var(--gray-800);"><?= htmlspecialchars($a['mentor_name']) ?></div>
                                        <div style="font-size:11px;color:var(--gray-400);"><?= htmlspecialchars($a['subject'] ?? '') ?></div>
                                    </td>
                                    <td><?= date("m-d-Y", strtotime($a['session_date'])) ?></td>
                                    <td><span class="badge badge-cancelled">Cancelled</span></td>
                                    <td>
                                        <a href="<?= htmlspecialchars($view_mentor_url) ?>?id=<?= (int)$a['mentor_id'] ?>" class="btn btn-blue" style="font-size:11px;padding:5px 12px;">
                                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                            Book Again
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align:center;padding:30px;color:var(--gray-400);font-size:13px;">No archived requests</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ══════════════ SESSION DETAIL MODAL ══════════════ -->
    <div id="sessionModal" class="modal-overlay">
        <div class="modal-box" style="width:460px;max-width:95vw;">
            <div style="padding:18px 24px;border-bottom:1px solid var(--gray-100);display:flex;align-items:center;justify-content:space-between;">
                <div style="font-size:15px;font-weight:700;color:var(--gray-900);">Session Details</div>
                <button onclick="closeSession()" style="width:30px;height:30px;border-radius:8px;border:1px solid var(--gray-200);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div style="padding:20px 24px;">
                <div style="display:flex;align-items:center;gap:12px;padding-bottom:16px;border-bottom:1px solid var(--gray-100);margin-bottom:16px;">
                    <div id="sm-avatar" class="tbl-avatar" style="width:42px;height:42px;font-size:16px;">–</div>
                    <div>
                        <div id="sm-mentor" style="font-weight:700;color:var(--gray-900);font-size:14px;">–</div>
                        <div id="sm-subject" style="font-size:12px;color:var(--gray-400);">–</div>
                    </div>
                    <span id="sm-status-pill" class="badge" style="margin-left:auto;"></span>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;">
                    <div style="background:var(--gray-50);border-radius:10px;padding:12px;">
                        <div style="font-size:10px;color:var(--gray-400);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Date</div>
                        <div id="sm-date" style="font-size:13px;font-weight:600;color:var(--gray-800);">–</div>
                    </div>
                    <div style="background:var(--gray-50);border-radius:10px;padding:12px;">
                        <div style="font-size:10px;color:var(--gray-400);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Time</div>
                        <div id="sm-time" style="font-size:13px;font-weight:600;color:var(--gray-800);">–</div>
                    </div>
                    <div style="background:var(--gray-50);border-radius:10px;padding:12px;">
                        <div style="font-size:10px;color:var(--gray-400);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Duration</div>
                        <div id="sm-duration" style="font-size:13px;font-weight:600;color:var(--gray-800);">–</div>
                    </div>
                    <div style="background:var(--gray-50);border-radius:10px;padding:12px;">
                        <div style="font-size:10px;color:var(--gray-400);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Type</div>
                        <div id="sm-type" style="font-size:13px;font-weight:600;color:var(--gray-800);">–</div>
                    </div>
                </div>
                <div id="sm-join-wrap" style="display:none;">
                    <a id="sm-join-btn" href="#" target="_blank" class="btn btn-blue" style="width:100%;justify-content:center;font-size:13px;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.723v6.554a1 1 0 01-1.447.894L15 14M3 8a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z" />
                        </svg>
                        Join Session
                    </a>
                    <p style="font-size:11px;color:var(--success);text-align:center;margin-top:6px;">🟢 Session is live</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const alerts = <?php echo json_encode($menteeAlerts); ?>;
            alerts.forEach(function(a) {
                const k = 'notify_' + a.key;
                if (!localStorage.getItem(k)) {
                    alert(a.message);
                    localStorage.setItem(k, '1');
                }
            });
        })();

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        }

        function toggleProfileMenu() {
            document.getElementById('profileMenu').classList.toggle('open');
        }

        const CSRF_TOKEN = "<?= csrf_token() ?>";

        function removeRequest(id) {
            if (!confirm('Remove this request?')) return;
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=remove&id=${id}&csrf_token=${encodeURIComponent(CSRF_TOKEN)}`
            }).then(() => location.reload());
        }

        function openArchive() {
            document.getElementById('archiveModal').classList.add('open');
        }

        function closeArchive() {
            document.getElementById('archiveModal').classList.remove('open');
        }

        /* ── Cancel flow ── */
        let pendingCancelId = null;

        function openCancelModal(id, mentorName) {
            pendingCancelId = id;
            document.getElementById('cancelModalDesc').textContent =
                `This will cancel your session request with ${mentorName}. You can book again anytime.`;
            document.getElementById('cancelModal').classList.add('open');
        }

        function closeCancelModal() {
            document.getElementById('cancelModal').classList.remove('open');
            pendingCancelId = null;
        }

        async function doCancel() {
            if (!pendingCancelId) return;
            const btn = document.querySelector('#cancelModal .btn-red');
            btn.textContent = 'Cancelling…';
            btn.disabled = true;
            try {
                const res = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=cancel&id=${pendingCancelId}&ajax=1&csrf_token=${encodeURIComponent(CSRF_TOKEN)}`
                });
                const data = await res.json();
                if (data.success) {
                    closeCancelModal();
                    document.getElementById('cancelSuccessModal').classList.add('open');
                } else {
                    alert('Something went wrong. Please try again.');
                    btn.textContent = 'Yes, Cancel';
                    btn.disabled = false;
                }
            } catch (e) {
                alert('Network error. Please try again.');
                btn.textContent = 'Yes, Cancel';
                btn.disabled = false;
            }
        }

        function closeCancelSuccess() {
            document.getElementById('cancelSuccessModal').classList.remove('open');
            location.reload();
        }

        /* ── Session detail modal ── */
        function openSession(mentor, subject, date, time, duration, type, canJoin, link, status) {
            document.getElementById('sm-avatar').textContent = mentor.charAt(0).toUpperCase();
            document.getElementById('sm-mentor').textContent = mentor;
            document.getElementById('sm-subject').textContent = subject;
            document.getElementById('sm-date').textContent = date;
            document.getElementById('sm-time').textContent = time;
            document.getElementById('sm-duration').textContent = duration;
            document.getElementById('sm-type').textContent = type || 'N/A';
            const pill = document.getElementById('sm-status-pill');
            pill.textContent = status.charAt(0).toUpperCase() + status.slice(1);
            pill.className = 'badge badge-' + status;
            const joinWrap = document.getElementById('sm-join-wrap');
            if (canJoin && link) {
                document.getElementById('sm-join-btn').href = link;
                joinWrap.style.display = 'block';
            } else {
                joinWrap.style.display = 'none';
            }
            document.getElementById('sessionModal').classList.add('open');
        }

        function closeSession() {
            document.getElementById('sessionModal').classList.remove('open');
        }

        /* ── Outside click / Escape ── */
        document.addEventListener('click', function(e) {
            const m = document.getElementById('profileMenu');
            if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]')) m.classList.remove('open');
        });
        ['archiveModal', 'sessionModal', 'cancelModal', 'cancelSuccessModal'].forEach(id => {
            document.getElementById(id).addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('open');
            });
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape')['archiveModal', 'sessionModal', 'cancelModal', 'cancelSuccessModal'].forEach(id => document.getElementById(id).classList.remove('open'));
        });
    </script>
</body>

</html>