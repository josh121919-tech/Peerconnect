<?php

/**
 * ongoing_session.php — the Ongoing tab of the mentor Sessions page.
 *
 * Sessions whose call has opened and which have not been closed yet. This is
 * the only tab that offers a way into a call: Upcoming is everything before
 * the window opens, Completed and History are everything after it is settled.
 *
 * Two states live here:
 *
 *   still running   the call is open, so the card offers Join — or Rejoin
 *                   when somebody stepped out and the session is 'unfinished'
 *   ended           its time has passed but nothing has closed it yet
 *
 * The second is the awkward one. A session nobody left properly stays
 * 'approved' until the missed-session job rules on it, and that job waits an
 * hour past the end and runs every half hour — so there is a window of up to
 * ninety minutes where the session is over and its outcome is not yet known.
 * It could still turn out to be completed, so it is not filed under History
 * on a guess. It waits here instead, with no Join button and a plain label,
 * and moves to Completed or History the moment the job decides.
 *
 * Card layout is shared with the other tabs (includes/session_list.php).
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

// Served on its own route this file is a bare fragment with no page shell,
// so a direct GET goes to the Sessions page's Ongoing tab instead.
if (empty($sr_embedded) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: ' . url('mentor-requests') . '?tab=ongoing');
    exit;
}

$mentor_id_og    = (int)$_SESSION['user_id'];
$appTz_og        = new DateTimeZone('Asia/Manila');
$mentorAlerts_og = $mentorAlerts_og ?? [];

/*
 * One entry per session, not per booking — the same fold Upcoming and the
 * dashboard do. Only slots the availability record calls 'group' are folded;
 * a booking whose slot was edited or deleted joins on nothing and stays a
 * card of its own rather than being guessed into a group.
 */
$rows_raw_og = SessionRepository::ongoingForMentor($con, $mentor_id_og);
$rows_og     = [];

foreach ($rows_raw_og as $r) {
    $isGroup_og = ($r['session_type'] ?? null) === 'group';
    $key_og = $isGroup_og
        ? 'g|' . $r['subject'] . '|' . $r['session_date']
        : 'b|' . $r['request_id'];

    if (!isset($rows_og[$key_og])) {
        $r['is_group'] = $isGroup_og;
        $r['members']  = [];
        $rows_og[$key_og] = $r;
    }
    // Keyed by booking id, so a duplicated availability row cannot list the
    // same mentee twice through the LEFT JOIN. The whole record is kept
    // because the detail modal lists participants with their address.
    $rows_og[$key_og]['members'][(int)$r['request_id']] = [
        'id'     => (int)$r['request_id'],
        'name'   => trim($r['firstname'] . ' ' . $r['lastname']),
        'email'  => (string)($r['email'] ?? ''),
        'status' => (string)($r['status'] ?? ''),
    ];

    // A group slot is unfinished only when every booking in it is; one mentee
    // stepping out does not describe a session the others are still sitting in.
    if (($r['status'] ?? '') !== 'unfinished') {
        $rows_og[$key_og]['status'] = 'approved';
    }
}
$rows_og = array_values($rows_og);
?>

<div>
    <?php mp_panel_open('og', 'video', 'Ongoing Sessions', 'Sessions that have opened and are not closed yet.'); ?>

    <?php if (!$rows_og): ?>
        <div class="card empty-state-lg">
            <span class="es-icon"><?= mp_svg('video') ?></span>
            <h3 class="es-title">Nothing running right now</h3>
            <p class="es-body">
                A session moves here <?= SessionRepository::JOIN_WINDOW_MINUTES ?> minutes before it starts,
                and this is where you join it.
            </p>
        </div>
    <?php else: ?>
        <?php foreach ($rows_og as $r):
            $requestId_og  = (int)$r['request_id'];
            $hasEnded_og   = !empty($r['has_ended']);
            $isUnfin_og    = ($r['status'] ?? '') === 'unfinished';
            $isGroupCard_og = !empty($r['is_group']);
            $members_og    = array_values($r['members'] ?? []);
            $names_og      = array_column($members_og, 'name');
            $booked_og     = count($members_og);
            $capacity_og   = (int)($r['capacity'] ?? 0);
            $endedChip_og = '<span style="display:inline-flex;align-items:center;justify-content:center;gap:6px;'
                . 'font-size:12px;color:var(--gray-500);background:var(--gray-100);padding:7px 12px;border-radius:8px;">'
                . mp_svg('clock', 'width="13" height="13"') . ' Ended &mdash; closing shortly</span>';

            /*
             * The card stays on screen while the clock runs out, so the control
             * has to hand over to the chip by itself rather than waiting for a
             * reload that may never come.
             */
            ob_start();
            pc_join_control([
                'session_id' => $requestId_og,
                'starts_at'  => $r['session_date'],
                'ends_at'    => $r['ends_at'] ?? null,
                'status'     => $r['status'] ?? 'approved',
                'group'      => $isGroupCard_og,
                'class'      => 'btn btn-success btn-sm',
                'label'      => $isUnfin_og ? 'Rejoin Session' : 'Join Session',
                'icon'       => mp_svg('video', 'width="14" height="14"') . ' ',
                'block'      => true,
                'ended'      => $endedChip_og,
            ]);
            $actionHtml_og = ob_get_clean();

            $seats_og = $capacity_og > 0
                ? $booked_og . ' of ' . $capacity_og . ' booked'
                : $booked_og . ' booked';

            /*
             * What the detail modal shows for this card. Removing a
             * participant is offered only while their booking is still live:
             * once a session has ended or been closed, cancelByMentor refuses
             * it, and a button that is always refused is worse than none.
             */
            $detailKey_og = ($isGroupCard_og ? 'g' : 'b') . $requestId_og;
            $sr_session_details[$detailKey_og] = [
                'is_group'     => $isGroupCard_og,
                'subject'      => (string)($r['subject'] ?: 'Session'),
                'when'         => date('M d, Y', strtotime($r['session_date'])) . ' · '
                                  . date('g:i A', strtotime($r['session_date'])),
                'status_label' => $hasEnded_og
                    ? 'Ended — waiting to be closed'
                    : ($isUnfin_og ? 'Unfinished — can still be rejoined' : 'In progress'),
                'seats'        => $seats_og,
                'mentee'       => trim($r['firstname'] . ' ' . $r['lastname']),
                'email'        => (string)($r['email'] ?? ''),
                'course'       => (string)($r['course'] ?? ''),
                'note'         => trim((string)($r['message'] ?? '')),
                'participants' => array_map(fn($mem) => [
                    'id'        => $mem['id'],
                    'name'      => $mem['name'],
                    'email'     => $mem['email'],
                    'removable' => !$hasEnded_og && in_array($mem['status'], ['pending', 'approved'], true),
                ], $members_og),
            ];

            $detailBtn_og = '<button type="button" class="btn btn-outline btn-sm" style="justify-content:center;"'
                . ' onclick="openSessionDetail(' . htmlspecialchars(json_encode($detailKey_og), ENT_QUOTES) . ')">'
                . 'View details</button>';

            $cardActions_og = [];
            if (!$isGroupCard_og) {
                $cardActions_og[] = ['kind' => 'icon', 'icon' => 'chat', 'title' => 'Message this mentee',
                                     'href' => url('messages') . '?chat=' . (int)$r['mentee_id']];
            }

            if ($hasEnded_og) {
                $badge_og = 'Ended';
                $badgeCls_og = 'badge-gray';
            } elseif ($isUnfin_og) {
                $badge_og = 'Unfinished';
                $badgeCls_og = 'badge-orange';
            } else {
                $badge_og = 'In progress';
                $badgeCls_og = 'badge-approved';
            }

            mp_session_card([
                'initials' => $isGroupCard_og
                    ? strtoupper(substr((string)($r['subject'] ?: 'G'), 0, 2))
                    : strtoupper(substr($r['firstname'], 0, 1) . substr($r['lastname'], 0, 1)),
                'tint'     => (int)$r['mentee_id'] % 5,
                'name'     => $isGroupCard_og
                    ? 'Group session · ' . $seats_og
                    : trim($r['firstname'] . ' ' . $r['lastname']),
                'email'    => $isGroupCard_og ? '' : ($r['email'] ?? ''),
                'course'   => $isGroupCard_og ? '' : ($r['course'] ?? ''),
                'subject'  => $r['subject'] ?: '—',
                'date'     => date('M d, Y', strtotime($r['session_date'])),
                'time'     => date('g:i A', strtotime($r['session_date'])),
                'note'       => $isGroupCard_og ? implode(', ', $names_og) : trim((string)$r['message']),
                'note_label' => $isGroupCard_og ? 'Mentees' : "Mentee's note",
                'badge'       => $badge_og,
                'badge_class' => $badgeCls_og,
                'search'   => ($isGroupCard_og ? implode(' ', $names_og) : $r['firstname'] . ' ' . $r['lastname'])
                              . ' ' . $r['subject'],
                'sortkey'  => strtotime($r['session_date']),
                'actions'  => $cardActions_og,
                'actions_html' => $detailBtn_og . $actionHtml_og,
            ]);
        endforeach; ?>
    <?php endif; ?>

    <?php mp_panel_close('og', 'Ongoing', count($rows_og) . ' session' . (count($rows_og) === 1 ? '' : 's') . ' open right now.'); ?>
</div>
