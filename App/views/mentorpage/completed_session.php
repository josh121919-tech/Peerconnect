<?php

/**
 * completed_session.php — the Completed tab of the mentor Sessions page.
 * Its own route (`mentor-completed`) redirects to that page's Completed tab.
 *
 * Card layout comes from includes/session_list.php, shared with Upcoming,
 * Declined and History.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($con)) {
    include __DIR__ . "/../db.php";
}
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? null) !== 'mentor')) {
    header("Location: " . url('welcomepage'));
    exit;
}
require_once __DIR__ . '/includes/session_list.php';

// Served on its own route this file is a bare fragment with no page shell —
// the notification and end-of-call links that point here landed the mentor on
// unstyled markup. The content lives in the Sessions page's complete tab, so a
// direct GET goes there instead.
if (empty($sr_embedded) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: ' . url('mentor-requests') . '?tab=complete');
    exit;
}


$mentor_id_cs = (int)$_SESSION['user_id'];

$perPage_cs = 20;
$page_cs    = max(1, (int)($_GET['page'] ?? 1));
$offset_cs  = ($page_cs - 1) * $perPage_cs;

$countStmt_cs = $con->prepare("SELECT COUNT(*) AS c FROM session_requests WHERE mentor_id = ? AND status = 'completed'");
$countStmt_cs->bind_param("i", $mentor_id_cs);
$countStmt_cs->execute();
$totalRows_cs = (int)$countStmt_cs->get_result()->fetch_assoc()['c'];
$countStmt_cs->close();
$totalPages_cs = max(1, (int)ceil($totalRows_cs / $perPage_cs));

$result_cs = $con->prepare("
    SELECT sr.request_id, sr.mentee_id, sr.subject, sr.message, sr.session_date, sr.completed_at,
           u.firstname, u.lastname,
           u.email,
           p.course
    FROM session_requests sr
    JOIN users u        ON u.user_id = sr.mentee_id
    LEFT JOIN profile p ON p.user_id = u.user_id
    WHERE sr.mentor_id = ?
      AND sr.status = 'completed'
    GROUP BY sr.request_id
    ORDER BY sr.session_date DESC
    LIMIT ? OFFSET ?
");
$result_cs->bind_param("iii", $mentor_id_cs, $perPage_cs, $offset_cs);
$result_cs->execute();
$sessions_cs = $result_cs->get_result()->fetch_all(MYSQLI_ASSOC);
$result_cs->close();
?>

<div>
    <?php mp_panel_open('cs', 'check', 'Completed Sessions', 'Here are the sessions you have successfully completed.'); ?>

    <?php if (!$sessions_cs): ?>
        <div class="card empty-state-lg">
            <span class="es-icon"><?= mp_svg('check') ?></span>
            <h3 class="es-title">No completed sessions yet</h3>
            <p class="es-body">Once you finish a session it is listed here with the mentee's notes.</p>
        </div>
    <?php else: ?>
        <?php foreach ($sessions_cs as $s):
            $ts = strtotime($s['session_date']);
            mp_session_card([
                'initials' => strtoupper(substr($s['firstname'], 0, 1) . substr($s['lastname'], 0, 1)),
                'tint'     => (int)$s['mentee_id'] % 5,
                'name'     => trim($s['firstname'] . ' ' . $s['lastname']),
                'email'    => $s['email'] ?? '',
                'course'   => $s['course'] ?? '',
                'subject'  => $s['subject'] ?: '—',
                'date'     => date('M d, Y', $ts),
                'time'     => date('g:i A', $ts),
                // session_requests has no mentor-written notes column; this is
                // the note the MENTEE sent with the booking, labelled as such.
                'note'       => trim((string)$s['message']),
                'note_label' => "Mentee's note",
                'badge'       => 'Completed',
                'badge_class' => 'badge-completed',
                'search'   => $s['firstname'] . ' ' . $s['lastname'] . ' ' . $s['subject'] . ' ' . $s['message'] . ' ' . date('M d Y', $ts),
                'sortkey'  => $ts,
                'actions'  => [
                    ['kind' => 'icon', 'icon' => 'chat', 'title' => 'Message this mentee',
                     'href' => url('messages') . '?chat=' . (int)$s['mentee_id']],
                    // The review page is where a completed session's feedback
                    // lives; it takes ?session=<id>.
                    ['kind' => 'button', 'label' => 'View Details', 'class' => 'btn-outline',
                     'href' => url('mentor-review') . '?session=' . (int)$s['request_id']],
                ],
            ]);
        endforeach; ?>
    <?php endif; ?>

    <?php
    mp_panel_close(
        'cs',
        $totalRows_cs > 0 ? 'Great job!' : '',
        $totalRows_cs > 0
            ? 'You have completed ' . $totalRows_cs . ' mentoring session' . ($totalRows_cs === 1 ? '' : 's') . '. Keep making an impact!'
            : ''
    );
    ?>

    <?php if ($totalPages_cs > 1): ?>
        <div class="card" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;margin-top:14px;">
            <span style="font-size:12px;color:var(--gray-400);">Page <?= $page_cs ?> of <?= $totalPages_cs ?> (<?= $totalRows_cs ?> total)</span>
            <div style="display:flex;gap:8px;">
                <?php if ($page_cs > 1): ?>
                    <a href="?page=<?= $page_cs - 1 ?>" class="btn btn-ghost btn-sm">← Prev</a>
                <?php endif; ?>
                <?php if ($page_cs < $totalPages_cs): ?>
                    <a href="?page=<?= $page_cs + 1 ?>" class="btn btn-ghost btn-sm">Next →</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php mp_panel_script('cs'); ?>
