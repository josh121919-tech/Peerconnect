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
require_once __DIR__ . '/../includes/pagination.php';

// Served on its own route this file is a bare fragment with no page shell —
// the notification and end-of-call links that point here landed the mentor on
// unstyled markup. The content lives in the Sessions page's history tab, so a
// direct GET goes there instead.
if (empty($sr_embedded) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: ' . url('mentor-requests') . '?tab=history');
    exit;
}


$mentor_id_hs = (int)$_SESSION['user_id'];

/*
 * 'hpage', not 'page'. Completed is a tab on this same page and reads its
 * own page number from the query string too — while both said 'page',
 * stepping through History quietly stepped through Completed as well.
 */
$perPage_hist = 20;
$page_hist    = max(1, (int)($_GET['hpage'] ?? 1));
$offset_hist  = ($page_hist - 1) * $perPage_hist;

/*
 * The filter. Only the four statuses History can hold are accepted; anything
 * else falls back to all of them, so a hand-edited URL cannot narrow this to
 * something it should not show.
 */
$filter_hs = $_GET['hstatus'] ?? 'all';
if (!is_string($filter_hs) || !in_array($filter_hs, SessionRepository::HISTORY_STATUSES, true)) {
    $filter_hs = 'all';
}
$filterArg_hs = $filter_hs === 'all' ? null : $filter_hs;

$totalRows_hist  = SessionRepository::countHistoryForMentor($con, $mentor_id_hs, $filterArg_hs);
$totalPages_hist = max(1, (int)ceil($totalRows_hist / $perPage_hist));

$rows_hs = SessionRepository::historyForMentor($con, $mentor_id_hs, $perPage_hist, $offset_hist, $filterArg_hs);

/*
 * Group slots that came and went with nobody in them.
 *
 * They have no session_requests row — nobody ever booked one — so they cannot
 * come from that query, and none is invented for them. They are read from
 * `availability` as it stands and shown as cancelled: a slot that was offered
 * and lapsed.
 *
 * Only on the first page. They are not part of the paged count, and paging
 * them alongside rows from a different table would either repeat them on
 * every page or drop them off the end of the last one.
 *
 * They are labelled Cancelled, so they appear under All and under Cancelled
 * and nowhere else — showing one under "Missed" would be filing it as
 * something it is not.
 */
$allLapsed_hs = SessionRepository::lapsedEmptyGroupSlotsForMentor($con, $mentor_id_hs);
$lapsed_hs = ($page_hist === 1 && in_array($filter_hs, ['all', 'cancelled'], true))
    ? $allLapsed_hs
    : [];
?>
<div>
    <?php mp_panel_open('hs', 'clock', 'Session History', 'Sessions that were declined, cancelled, missed or left unfinished.'); ?>

    <?php
    /*
     * The filter chips. Each carries its own count, so a mentor can see there
     * is nothing under a heading without opening it. Lapsed empty group slots
     * show under All and under Cancelled, which is how they are labelled.
     */
    $hsChips = ['all' => 'All'];
    foreach (SessionRepository::HISTORY_STATUSES as $st_hs) {
        $hsChips[$st_hs] = ucfirst($st_hs);
    }
    ?>
    <div class="ss-filter-chips" style="display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px;">
        <?php foreach ($hsChips as $val_hs => $label_hs):
            $n_hs = $val_hs === 'all'
                ? SessionRepository::countHistoryForMentor($con, $mentor_id_hs)
                : SessionRepository::countHistoryForMentor($con, $mentor_id_hs, $val_hs);
            // Lapsed empty group slots are cards but not session_requests
            // rows, so the count has to add them or "Cancelled" reads 0 with
            // a cancelled card sitting underneath it.
            if ($val_hs === 'all' || $val_hs === 'cancelled') {
                $n_hs += count($allLapsed_hs);
            }
        ?>
            <a href="<?= htmlspecialchars(url('mentor-requests') . '?hstatus=' . $val_hs) ?>"
               class="btn btn-sm <?= $filter_hs === $val_hs ? 'btn-primary' : 'btn-ghost' ?>"
               style="font-size:12px;padding:6px 13px;">
                <?= htmlspecialchars($label_hs) ?>
                <?php if ($n_hs > 0): ?>
                    <span style="opacity:.7;margin-left:5px;"><?= $n_hs ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (!$rows_hs && !$lapsed_hs): ?>
        <div class="card empty-state-lg">
            <span class="es-icon"><?= mp_svg('clock') ?></span>
            <h3 class="es-title">Nothing here yet</h3>
            <p class="es-body">Declined, cancelled, missed and unfinished sessions are kept here for your records.</p>
        </div>
    <?php else: ?>
        <?php foreach ($lapsed_hs as $slot_hs):
            $lts = strtotime($slot_hs['session_date']);
            mp_session_card([
                'initials' => strtoupper(substr((string)($slot_hs['subject'] ?: 'G'), 0, 2)),
                'tint'     => (int)$slot_hs['availability_id'] % 5,
                'name'     => 'Group session · nobody booked',
                'email'    => '',
                'course'   => '',
                'subject'  => $slot_hs['subject'] ?: '—',
                'date'     => date('M d, Y', $lts),
                'time'     => date('g:i A', $lts),
                'time_end' => date('g:i A', $lts + ((int)($slot_hs['duration'] ?: 60) * 60)),
                'note'       => 'This slot was open for '
                                . ((int)$slot_hs['capacity'] > 0 ? (int)$slot_hs['capacity'] . ' students' : 'bookings')
                                . ' and its time passed with none taken.',
                'note_label' => 'What happened',
                'badge'       => 'Cancelled',
                'badge_class' => 'badge-cancelled',
                'search'   => ($slot_hs['subject'] ?? '') . ' group cancelled nobody booked',
                'sortkey'  => $lts,
                'actions'  => [],
            ]);
        endforeach; ?>
    <?php endif; ?>

    <?php if ($rows_hs): ?>
        <?php foreach ($rows_hs as $r):
            $ts = strtotime($r['session_date']);
            $isRejected   = $r['status'] === 'rejected';
            $isMissed     = $r['status'] === 'missed';
            $isUnfinished = $r['status'] === 'unfinished';

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
            if ($isUnfinished) {
                // Both of them were there. It stopped before its time and
                // nobody came back while the slot was still open — which is
                // not a no-show, and is counted against neither of them.
                $note = 'Both of you joined, but the session ended before its scheduled finish '
                      . 'and was not rejoined. This does not count against either of you.';
                $noteLabel = 'What happened';
            } elseif ($isMissed) {
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

            $badgeClass = $isRejected   ? 'badge-rejected'
                        : ($isMissed    ? 'badge-missed'
                        : ($isUnfinished ? 'badge-orange' : 'badge-cancelled'));

            mp_session_card([
                'initials' => strtoupper(substr($r['firstname'], 0, 1) . substr($r['lastname'], 0, 1)),
                'tint'     => (int)$r['mentee_id'] % 5,
                'name'     => trim($r['firstname'] . ' ' . $r['lastname']),
                'email'    => $r['email'] ?? '',
                'course'   => $r['course'] ?? '',
                'subject'  => $r['subject'] ?: '—',
                'date'     => date('M d, Y', $ts),
                'time'     => date('g:i A', $ts),
                'time_end' => !empty($r['session_end']) ? date('g:i A', strtotime($r['session_end'])) : '',
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

    <?php
    /*
     * Paging carries the filter and the parameter that reopens this modal.
     * Without them page 2 of "Missed" came back as page 2 of everything,
     * behind the Received tab.
     */
    pc_pagination(
        $page_hist,
        $totalPages_hist,
        fn(int $n) => url('mentor-requests') . '?' . http_build_query(
            ['hstatus' => $filter_hs, 'hpage' => $n]
        ),
        ['summary' => 'Showing ' . ($offset_hist + 1) . '–' . min($offset_hist + $perPage_hist, $totalRows_hist)
                    . ' of ' . $totalRows_hist, 'label' => 'Session history pages']
    );
    ?>
</div>
<?php mp_panel_script('hs'); ?>
