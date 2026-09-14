<?php

/**
 * session_history.php — the History tab of the mentor Sessions page.
 * Its own route (`mentor-history`) redirects to that page's History tab.
 *
 * Everything that did not happen: bookings that were declined, cancelled, or
 * missed. Missed sessions were being written by the missed-session detector
 * and by the admin Sessions screen but shown to the mentor nowhere at all —
 * a session simply vanished from Upcoming with no record of it anywhere. They
 * belong here, with the other outcomes that were not a completed session.
 *
 * Card layout is shared with Upcoming / Completed / Declined
 * (includes/session_list.php).
 */

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($con)) {
    include __DIR__ . "/../db.php";
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mentor') {
    header("Location: " . url('welcomepage'));
    exit;
}
require_once __DIR__ . '/includes/session_list.php';

// Served on its own route this file is a bare fragment with no page shell —
// the notification and end-of-call links that point here landed the mentor on
// unstyled markup. The content lives in the Sessions page's history tab, so a
// direct GET goes there instead.
if (empty($sr_embedded) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: ' . url('mentor-requests') . '?tab=history');
    exit;
}


$mentor_id_hs = (int)$_SESSION['user_id'];

$perPage_hist = 20;
$page_hist    = max(1, (int)($_GET['page'] ?? 1));
$offset_hist  = ($page_hist - 1) * $perPage_hist;

$totalStmt = $con->prepare("SELECT COUNT(*) AS c FROM session_requests WHERE mentor_id = ? AND status IN ('rejected','cancelled','missed')");
$totalStmt->bind_param("i", $mentor_id_hs);
$totalStmt->execute();
$totalRows_hist = (int)$totalStmt->get_result()->fetch_assoc()['c'];
$totalStmt->close();
$totalPages_hist = max(1, (int)ceil($totalRows_hist / $perPage_hist));

$historyStmt = $con->prepare("
    SELECT sr.*, u.firstname, u.lastname,
           u.email,
           p.course
    FROM session_requests sr
    JOIN users u        ON sr.mentee_id = u.user_id
    LEFT JOIN profile p ON p.user_id = u.user_id
    WHERE sr.mentor_id = ?
      AND sr.status IN ('rejected','cancelled','missed')
    GROUP BY sr.request_id
    ORDER BY sr.session_date DESC
    LIMIT ? OFFSET ?
");
$historyStmt->bind_param("iii", $mentor_id_hs, $perPage_hist, $offset_hist);
$historyStmt->execute();
$rows_hs = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$historyStmt->close();
?>
<div>
    <?php mp_panel_open('hs', 'clock', 'Session History', 'Bookings that were declined, cancelled or missed.'); ?>

    <?php if (!$rows_hs): ?>
        <div class="card empty-state-lg">
            <span class="es-icon"><?= mp_svg('clock') ?></span>
            <h3 class="es-title">Nothing here yet</h3>
            <p class="es-body">Declined, cancelled and missed bookings are kept here for your records.</p>
        </div>
    <?php else: ?>
        <?php foreach ($rows_hs as $r):
            $ts = strtotime($r['session_date']);
            $isRejected = $r['status'] === 'rejected';
            $isMissed   = $r['status'] === 'missed';

            /*
             * Each outcome has a different thing worth saying.
             *
             * Declined carries the reason the mentor gave. Missed carries who
             * did not turn up, which is the only part of a missed session the
             * mentor cannot work out from the card — and naming it matters,
             * because "missed" against your own name reads very differently
             * from "the mentee did not attend". Cancelled has no reason of its
             * own, so it falls back to the mentee's note.
             */
            if ($isMissed) {
                $note = match ($r['missed_by'] ?? 'both') {
                    'mentor' => 'Recorded as missed on your side — the mentee was marked as attending.',
                    'mentee' => 'The mentee did not attend. This does not count against you.',
                    default  => 'Neither of you was recorded as attending.',
                };
                $noteLabel = 'What happened';
            } elseif ($isRejected && trim((string)$r['rejection_reason']) !== '') {
                $note = trim((string)$r['rejection_reason']);
                $noteLabel = 'Reason you gave';
            } else {
                // A cancelled booking has no reason of its own; the mentee's
                // original note is the most useful thing left on the row.
                $note = trim((string)$r['message']);
                $noteLabel = "Mentee's note";
            }

            $badgeClass = $isRejected ? 'badge-rejected' : ($isMissed ? 'badge-missed' : 'badge-cancelled');

            mp_session_card([
                'initials' => strtoupper(substr($r['firstname'], 0, 1) . substr($r['lastname'], 0, 1)),
                'tint'     => (int)$r['mentee_id'] % 5,
                'name'     => trim($r['firstname'] . ' ' . $r['lastname']),
                'email'    => $r['email'] ?? '',
                'course'   => $r['course'] ?? '',
                'subject'  => $r['subject'] ?: '—',
                'date'     => date('M d, Y', $ts),
                'time'     => date('g:i A', $ts),
                'note'       => $note,
                'note_label' => $noteLabel,
                'badge'       => ucfirst($r['status']),
                'badge_class' => $badgeClass,
                'search'   => $r['firstname'] . ' ' . $r['lastname'] . ' ' . $r['subject'] . ' ' . $note . ' ' . $r['status'],
                'sortkey'  => $ts,
                'actions'  => [
                    ['kind' => 'icon', 'icon' => 'chat', 'title' => 'Message this mentee',
                     'href' => url('messages') . '?chat=' . (int)$r['mentee_id']],
                ],
            ]);
        endforeach; ?>
    <?php endif; ?>

    <?php mp_panel_close('hs'); ?>

    <?php if ($totalPages_hist > 1): ?>
        <div class="card" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;margin-top:14px;">
            <span style="font-size:12px;color:var(--gray-400);">Page <?= $page_hist ?> of <?= $totalPages_hist ?> (<?= $totalRows_hist ?> total)</span>
            <div style="display:flex;gap:8px;">
                <?php if ($page_hist > 1): ?>
                    <a href="?page=<?= $page_hist - 1 ?>" class="btn btn-ghost btn-sm">← Prev</a>
                <?php endif; ?>
                <?php if ($page_hist < $totalPages_hist): ?>
                    <a href="?page=<?= $page_hist + 1 ?>" class="btn btn-ghost btn-sm">Next →</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php mp_panel_script('hs'); ?>
