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
require_once __DIR__ . '/../includes/pagination.php';

// Served on its own route this file is a bare fragment with no page shell —
// the notification and end-of-call links that point here landed the mentor on
// unstyled markup. The content lives in the Sessions page's complete tab, so a
// direct GET goes there instead.
if (empty($sr_embedded) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: ' . url('mentor-requests') . '?tab=complete');
    exit;
}


$mentor_id_cs = (int)$_SESSION['user_id'];

/*
 * 'cpage', not 'page'. The History modal on this same page has its own page
 * number; while both read 'page', stepping through one stepped through the
 * other.
 */
$perPage_cs = 20;
$page_cs    = max(1, (int)($_GET['cpage'] ?? 1));
$offset_cs  = ($page_cs - 1) * $perPage_cs;

$totalRows_cs  = SessionRepository::countForMentorInStatuses($con, $mentor_id_cs, ['completed']);
$totalPages_cs = max(1, (int)ceil($totalRows_cs / $perPage_cs));

$sessions_cs = SessionRepository::completedForMentor($con, $mentor_id_cs, $perPage_cs, $offset_cs);
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
                'time_end' => !empty($s['session_end']) ? date('g:i A', strtotime($s['session_end'])) : '',
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

    <?php
    // Paging carries ?tab=complete, or the next page opens on Received.
    pc_pagination(
        $page_cs,
        $totalPages_cs,
        fn(int $n) => url('mentor-requests') . '?' . http_build_query(['tab' => 'complete', 'cpage' => $n]),
        ['summary' => 'Showing ' . ($offset_cs + 1) . '–' . min($offset_cs + $perPage_cs, $totalRows_cs)
                    . ' of ' . $totalRows_cs, 'label' => 'Completed session pages']
    );
    ?>
</div>
<?php mp_panel_script('cs'); ?>
