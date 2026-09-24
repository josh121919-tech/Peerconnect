<?php

/**
 * calendar.php — the mentor Calendar.
 *
 * Two tabs. "Set Availability" pairs a month/week/day calendar with the form
 * that creates slots; "Saved Availability" lists what has been created, with
 * search, sort, edit and delete.
 *
 * The calendar plots two different things, which the legend names separately:
 *
 *   Your Availability  rows in `availability` — slots you have opened
 *   Scheduled Session  approved rows in `session_requests` — actual bookings
 *
 * A slot and a booking are joined only by (mentor, subject, date, start_time):
 * session_requests has no availability_id. That is why editing the date or
 * time of a slot someone has already booked would silently orphan the booking,
 * and why such slots are marked Booked and refuse to be edited.
 */

date_default_timezone_set('Asia/Manila');
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/GoogleCalendarService.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mentor') {
    header("Location: " . url('welcomepage'));
    exit;
}

require_once __DIR__ . '/../../services/NotificationService.php';

$mentor_id = (int)$_SESSION['user_id'];
$self_url  = url('mentor-calendar');
$success   = false;
$error     = '';

// ── Reading the form ──────────────────────────────────────────────────
// A field that is not plain text (one sent as a list, say) counts as empty,
// so a tampered form gets the ordinary message instead of a PHP error.

/** A text field, trimmed. */
function cal_text(string $key): string
{
    return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
}

/** An id field; 0 when missing or not plain text. */
function cal_id(string $key): int
{
    return is_string($_POST[$key] ?? null) ? (int)$_POST[$key] : 0;
}

/** A list field such as start_time[], each entry trimmed; entries that are not text become ''. */
function cal_list(string $key): array
{
    $list = $_POST[$key] ?? [];
    return is_array($list) ? array_map(fn($v) => is_string($v) ? trim($v) : '', $list) : [];
}

/** True for a real calendar date written 'Y-m-d'. */
function cal_valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

/** A clock time ('9:00', '09:00' or '09:00:00') as seconds into the day, or null when it is not one. */
function cal_seconds(string $time): ?int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m)) return null;
    [$h, $i, $s] = [(int)$m[1], (int)$m[2], (int)($m[3] ?? 0)];
    return ($h > 23 || $i > 59 || $s > 59) ? null : $h * 3600 + $i * 60 + $s;
}

/** Seconds into the day as a clock time, e.g. '9:30 AM'. */
function cal_clock(int $seconds): string
{
    return gmdate('g:i A', $seconds);
}

/** Why the subject, topics or description cannot be saved as typed, or '' when they can. */
function cal_text_problem(string $subject, string $topics, string $about): string
{
    foreach ([$subject, $topics, $about] as $text) {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return "Some of the text could not be read. Please type it again.";
        }
    }
    // The column sizes: anything longer would be cut off without a word.
    if (mb_strlen($subject) > 100) return "Keep the subject to 100 characters or fewer.";
    if (mb_strlen($topics) > 255)  return "Keep the topics to 255 characters or fewer.";
    if (strlen($about) > 65535)    return "The description is too long. Please shorten it.";
    return '';
}

/**
 * The first slot in $slots that overlaps the time from $from to $to (seconds
 * into the day), or null. Each slot is ['from' => …, 'to' => …]. A slot that
 * ends exactly when another starts does not overlap it.
 */
function cal_overlap(array $slots, int $from, int $to): ?array
{
    foreach ($slots as $slot) {
        if ($slot['from'] < $to && $from < $slot['to']) return $slot;
    }
    return null;
}

/** The mentor's slots on $date as ['id', 'from', 'to'], leaving out $exceptId. A slot with no length counts as 60 minutes, as booking does. */
function cal_day_slots(mysqli $con, int $mentor_id, string $date, int $exceptId = 0): array
{
    $out = [];
    foreach (AvailabilityRepository::onDateForMentor($con, $mentor_id, $date) as $s) {
        if ((int)$s['availability_id'] === $exceptId) continue;
        $from  = cal_seconds((string)$s['start_time']) ?? 0;
        $out[] = ['id' => (int)$s['availability_id'], 'from' => $from, 'to' => $from + ((int)($s['duration'] ?? 60) ?: 60) * 60];
    }
    return $out;
}

// A save holds the mentor's calendar (see AvailabilityRepository::lockMentor);
// this lets it go however the request ends.
$cal_locked = false;
register_shutdown_function(function () use ($con, $mentor_id, &$cal_locked) {
    if (!$cal_locked) return;
    try {
        AvailabilityRepository::unlockMentor($con, $mentor_id);
    } catch (Throwable $e) {
        // The connection closing releases it regardless.
    }
});
const CAL_BUSY = "Your calendar is saving another change right now. Please try again in a moment.";

/*
 * The shortest slot a mentor may offer.
 *
 * Whether a session counts as completed is a share of its length — see
 * SessionRepository::MIN_ATTENDANCE_FRACTION — so on a five minute slot
 * "attended enough of it" would mean three and a half minutes. A floor here
 * is what stops that share from describing something too small to be a
 * session at all.
 *
 * Slots already saved keep whatever length they have; this is checked only
 * where a length is being set.
 */
const CAL_MIN_MINUTES = 30;
const CAL_TOO_SHORT = "A session has to be at least " . CAL_MIN_MINUTES . " minutes long.";

/*
 * How long before it starts a slot can still be called off. Later than this
 * the mentee may already be on their way, and a cancellation is no longer a
 * change of plan but a no-show with paperwork. The cut-off is the same
 * whether anyone has booked or not, so the button does not appear and
 * disappear depending on who happens to be in the slot.
 */
const CAL_CANCEL_LEAD_HOURS = 2;

// ── Cancel ────────────────────────────────────────────────────────────
/*
 * Removing the slot is the easy half. The half that matters is that people
 * may be booked into it, and they have to be told — by a person, with a
 * reason, not by their session quietly vanishing. So the reason is required
 * whenever anyone holds a booking, each of those sessions is cancelled
 * carrying it, and each mentee is notified before the slot goes.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id'])) {
    if (!verify_csrf()) {
        http_response_code(403);
        exit('CSRF token mismatch.');
    }

    $slotId = cal_id('cancel_id');
    $reason = cal_text('cancel_reason');
    $slot   = AvailabilityRepository::findForMentor($con, $slotId, $mentor_id);

    $bounce = function (string $kind, string $msg, string $title = '') use ($self_url) {
        pc_flash($kind, $msg, $title);
        header("Location: " . $self_url . '?tab=saved');
        exit;
    };

    if (!$slot) {
        $bounce('error', 'That slot could not be found, so nothing was cancelled.');
    }

    $booked  = SessionRepository::liveBookingsInSlot($con, $mentor_id, $slot['subject'], $slot['date'], $slot['start_time']);
    $startTs = strtotime($slot['date'] . ' ' . $slot['start_time']);

    // The same cut-off the button obeys. Checked here too: a hidden button
    // is a suggestion, not a rule.
    if ($startTs - time() < CAL_CANCEL_LEAD_HOURS * 3600) {
        $bounce('error', 'That slot starts in under ' . CAL_CANCEL_LEAD_HOURS
            . ' hours, so it can no longer be cancelled.'
            . ($booked ? ' Message the mentee instead.' : ''));
    }

    if ($booked && $reason === '') {
        $bounce('error', 'Please say why you are cancelling — everyone booked into this slot is told.');
    }
    if (mb_strlen($reason) > 500) {
        $bounce('error', 'That reason is too long (500 characters max).');
    }

    $me = trim(($_SESSION['firstname'] ?? '') . ' ' . ($_SESSION['lastname'] ?? ''));
    if ($me === '') {
        $me = 'Your mentor';
    }

    $told = 0;
    foreach ($booked as $b) {
        if (SessionRepository::cancelByMentor($con, (int)$b['request_id'], $mentor_id, $reason) < 1) {
            continue;   // it closed itself between the read and here
        }
        NotificationService::send(
            $con,
            (int)$b['mentee_id'],
            'session_cancelled',
            'Session Cancelled',
            $me . ' cancelled your ' . $slot['subject'] . ' session on '
                . date('M j, g:i A', strtotime($b['session_date'])) . '. Reason: ' . $reason,
            url('mentee-sessions')
        );
        $told++;
    }

    // The slot goes last: if anything above failed, it is still there to try
    // again rather than gone with its bookings left hanging.
    AvailabilityRepository::deleteForMentor($con, $slotId, $mentor_id);

    $bounce(
        'success',
        $told > 0
            ? 'The slot was cancelled and ' . $told . ' mentee' . ($told === 1 ? ' was' : 's were') . ' told why.'
            : 'That availability slot was cancelled. Nobody had booked it, so there was nobody to tell.',
        'Availability cancelled'
    );
}

// ── Edit ──────────────────────────────────────────────────────────────
// One row, so this takes a single start/end rather than the create form's list.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['edit_id'])) {
    if (!verify_csrf()) {
        http_response_code(403);
        exit('CSRF token mismatch.');
    }
    $editId  = cal_id('edit_id');
    $date    = cal_text('date');
    $subject = cal_text('subject');
    $about   = cal_text('about');
    $topics  = cal_text('topics');
    $stype   = cal_text('session_type');
    $start   = cal_list('start_time')[0] ?? '';
    $end     = cal_list('end_time')[0] ?? '';

    // Only slots this mentor owns, and only ones nobody has booked.
    $row = AvailabilityRepository::findForMentor($con, $editId, $mentor_id);

    $from    = cal_seconds($start);
    $to      = cal_seconds($end);
    $badTime = ($start !== '' && $from === null) || ($end !== '' && $to === null);
    $mins    = ($from !== null && $to !== null) ? (int)round(($to - $from) / 60) : 0;

    // A booking is joined to a slot only by (mentor, subject, date, start_time)
    // — session_requests carries no availability_id. On a slot someone has
    // booked, those have to stay exactly as they are or the booking is
    // orphaned. Topics, the blurb and the session type are not part of that
    // join, so they are safe to change: a booked slot used to refuse every
    // edit, which also blocked the fields that were never at risk.
    $liveBookings = $row ? SessionRepository::countLiveInSlot($con, $mentor_id, $row['subject'], $row['date'], $row['start_time']) : 0;
    $isBooked = $liveBookings > 0;
    if ($isBooked) {
        $date    = $row['date'];
        $start   = substr((string)$row['start_time'], 0, 5);
        $subject = $row['subject'];
        $mins    = max(1, (int)$row['duration']);
        $from    = cal_seconds($start);
        $badTime = false;
    }

    // Only a change to when the slot runs can make it clash with another slot
    // or put it in the past, so a slot that already does either can still
    // have its topics or description corrected.
    $moved = $row && !$isBooked && $from !== null
        && ($date !== $row['date'] || $from !== cal_seconds((string)$row['start_time']) || $mins !== (int)$row['duration']);

    if (!$row) {
        $error = "That slot could not be found.";
    } elseif ($isBooked && $stype === '1v1' && $liveBookings > 1) {
        // 1v1 drops capacity to 1, which would leave existing reservations
        // sitting above the limit.
        $error = "This slot has more than one booking, so it cannot be changed to 1v1. Remove the extra reservations first.";
    } elseif ($date === '' || $subject === '' || $topics === '' || !in_array($stype, ['1v1', 'group'], true)) {
        $error = "Date, subject, topics and session type are all required.";
    } elseif (!cal_valid_date($date)) {
        $error = "That date is not a real date. Pick a day on the calendar.";
    } elseif ($badTime) {
        $error = "Each time slot needs a valid start and end time.";
    } elseif ($mins <= 0) {
        $error = "The end time has to be after the start time.";
    } elseif ($mins < CAL_MIN_MINUTES && $mins !== (int)$row['duration']) {
        // Unless it is the length the slot already had. A slot saved before
        // this rule existed can still have its topics corrected; what is
        // refused is setting a new length below the floor. A booked slot has
        // its length forced from the row above, so it always takes this
        // exemption.
        $error = CAL_TOO_SHORT;
    } elseif (($problem = cal_text_problem($subject, $topics, $about)) !== '') {
        $error = $problem;
    } elseif ($date !== $row['date'] && $date < date('Y-m-d')) {
        // The same rule as a new slot: no moving one onto a day that has passed.
        $error = "Cannot set availability for past dates.";
    } elseif ($moved) {
        if (!AvailabilityRepository::lockMentor($con, $mentor_id)) {
            $error = CAL_BUSY;
        } else {
            $cal_locked = true;
            $clash = cal_overlap(cal_day_slots($con, $mentor_id, $date, $editId), $from, $from + $mins * 60);
            if ($clash) {
                $error = 'The new time, ' . cal_clock($from) . ' – ' . cal_clock($from + $mins * 60)
                    . ', overlaps another slot you have that day (' . cal_clock($clash['from']) . ' – ' . cal_clock($clash['to'])
                    . '), so nothing was changed. Pick a time that does not overlap.';
            }
        }
    }

    if ($error === '') {
        $capacity = $stype === 'group' ? 15 : 1;
        AvailabilityRepository::updateForMentor($con, $editId, $mentor_id, $date, $start, $mins, $subject, $about, $topics, $stype, $capacity);
    }
    if ($cal_locked) {
        AvailabilityRepository::unlockMentor($con, $mentor_id);
        $cal_locked = false;
    }
    if ($error === '') {
        pc_flash(
            'success',
            $isBooked
                ? 'Saved. The date, time and subject stayed as they were, because a mentee has booked this slot.'
                : 'Your changes to that slot were saved.',
            'Availability updated'
        );
        header("Location: " . $self_url . '?tab=saved');
        exit;
    }
}

// ── Create ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subject']) && empty($_POST['edit_id'])) {
    $date         = cal_text('date');
    $subject      = cal_text('subject');
    $about        = cal_text('about');
    $topics       = cal_text('topics');
    $session_type = cal_text('session_type');

    if (!verify_csrf()) {
        $error = "Security token mismatch. Please refresh and try again.";
    } elseif ($date === '' || $subject === '' || $topics === '' || !in_array($session_type, ['1v1', 'group'], true)) {
        // `about` is genuinely optional — the column is nullable and nothing
        // reads it as required.
        $error = "Date, subject, topics and session type are all required.";
    } elseif (!cal_valid_date($date)) {
        $error = "That date is not a real date. Pick a day on the calendar.";
    } elseif ($date < date("Y-m-d")) {
        $error = "Cannot set availability for past dates.";
    } elseif (($problem = cal_text_problem($subject, $topics, $about)) !== '') {
        $error = $problem;
    } else {
        $start_times  = cal_list('start_time');
        $end_times    = cal_list('end_time');
        $capacity     = $session_type === 'group' ? 15 : 1;
        $inserted     = 0;

        // Every row is checked before any is saved, so a clash anywhere in
        // the form leaves the calendar exactly as it was.
        $rows = [];
        foreach ($start_times as $i => $start) {
            $end = $end_times[$i] ?? '';
            if ($start === '' || $end === '') continue;

            $from = cal_seconds($start);
            $to   = cal_seconds($end);
            if ($from === null || $to === null) {
                $error = "Each time slot needs a valid start and end time.";
                break;
            }

            // Duration is derived from the pair the form collects, which is
            // what the mentor actually thinks in.
            $duration = (int)round(($to - $from) / 60);
            if ($duration <= 0) {
                $error = "Each time slot needs an end time later than its start.";
                break;
            }
            if ($duration < CAL_MIN_MINUTES) {
                $error = CAL_TOO_SHORT;
                break;
            }
            $rows[] = ['start' => $start, 'duration' => $duration, 'from' => $from, 'to' => $from + $duration * 60];
        }

        if ($error === '' && $rows) {
            if (!AvailabilityRepository::lockMentor($con, $mentor_id)) {
                $error = CAL_BUSY;
            } else {
                $cal_locked = true;
                $taken = cal_day_slots($con, $mentor_id, $date);
                $toAdd = [];
                foreach ($rows as $r) {
                    // Prevent duplicate slots from a double-click or resubmit of
                    // the same form, or the same time entered twice in it.
                    if (AvailabilityRepository::existsForMentor($con, $mentor_id, $date, $r['start'], $subject, $session_type)) continue;
                    foreach ($toAdd as $a) {
                        if ($a['from'] === $r['from']) continue 2;
                    }

                    if ($clash = cal_overlap($taken, $r['from'], $r['to'])) {
                        $error = 'The ' . cal_clock($r['from']) . ' – ' . cal_clock($r['to']) . ' slot overlaps a slot you already have that day ('
                            . cal_clock($clash['from']) . ' – ' . cal_clock($clash['to']) . '), so nothing was saved. Pick a time that does not overlap.';
                        break;
                    }
                    if ($clash = cal_overlap($toAdd, $r['from'], $r['to'])) {
                        $error = 'Two of the time slots overlap each other (' . cal_clock($clash['from']) . ' – ' . cal_clock($clash['to'])
                            . ' and ' . cal_clock($r['from']) . ' – ' . cal_clock($r['to']) . '), so nothing was saved. Pick times that do not overlap.';
                        break;
                    }
                    $toAdd[] = $r;
                }

                if ($error === '') {
                    foreach ($toAdd as $r) {
                        $inserted += AvailabilityRepository::createForMentor($con, $mentor_id, $date, $r['start'], $r['duration'],
                            $subject, $about, $topics, $session_type, $capacity);
                    }
                }
                AvailabilityRepository::unlockMentor($con, $mentor_id);
                $cal_locked = false;
            }
        }

        if ($error !== '') {
            // Reported below.
        } elseif ($inserted > 0) {
            $success = true;
            pc_flash('success',
                $inserted . ' time slot' . ($inserted === 1 ? '' : 's') . ' added for ' . date('F j', strtotime($date)) . '.',
                'Availability saved');
        } else {
            $error = "Please add at least one time slot.";
        }
    }
}

// ── Saved slots ───────────────────────────────────────────────────────
$saved = [];
foreach (AvailabilityRepository::allForMentor($con, $mentor_id) as $row) {
    // The count, not just the fact: the cancel dialog says how many people
    // it is about to write to, and "1 mentee" reads very differently from "6".
    $row['booked_n'] = SessionRepository::countLiveInSlot($con, $mentor_id, $row['subject'], $row['date'], $row['start_time']);
    $row['booked']   = $row['booked_n'] > 0;
    $saved[] = $row;
}

/*
 * Saved Availability is today and beyond; anything earlier has been and gone
 * and belongs to History. The line is the date rather than the end time, so a
 * slot at nine this morning stays on the Saved tab for the rest of the day —
 * the mentor is still working from today's list. Nothing has to be moved by
 * hand: the split is recomputed on every load, so a slot crosses over by
 * itself the moment the date does.
 */
$todayYmd  = date('Y-m-d');
$savedOpen = [];
$savedPast = [];
foreach ($saved as $row) {
    if ($row['date'] < $todayYmd) {
        $savedPast[] = $row;
    } else {
        $savedOpen[] = $row;
    }
}
// History reads best newest-first, which is already how they arrive.

// ── What the calendar plots ───────────────────────────────────────────
$calEvents = [];
foreach ($saved as $av) {
    $calEvents[] = [
        'date'    => $av['date'],
        'time'    => date('g:i A', strtotime($av['start_time'])),
        'sort'    => $av['start_time'],
        'label'   => 'Available',
        'kind'    => 'avail',
        'subject' => $av['subject'],
        'type'    => $av['session_type'],
    ];
}
foreach (SessionRepository::approvedWithMenteeForMentor($con, $mentor_id) as $s) {
    $calEvents[] = [
        'date'    => date('Y-m-d', strtotime($s['session_date'])),
        'time'    => date('g:i A', strtotime($s['session_date'])),
        'sort'    => date('H:i:s', strtotime($s['session_date'])),
        'label'   => 'Scheduled',
        'kind'    => 'session',
        'subject' => $s['subject'],
        'with'    => trim($s['firstname'] . ' ' . $s['lastname']),
    ];
}

$activeTab = in_array($_GET['tab'] ?? '', ['saved', 'history'], true) ? $_GET['tab'] : 'calendar';

// Whatever the handlers above rejected is shown as a toast, the same way a
// success is — one place for "what just happened", instead of a banner here
// and a toast elsewhere.
if ($error !== '') {
    pc_flash('error', $error);
}

// ── Google Calendar link ─────────────────────────────────────────────────
// Sessions are pushed as they're approved; this catches anything that moved
// while nobody was looking.
$gcal_configured = GoogleCalendarService::isConfigured();
$gcal            = $gcal_configured ? GoogleCalendarService::linkFor($con, $mentor_id) : null;
if ($gcal) {
    GoogleCalendarService::syncIfStale($con, $mentor_id);
    $gcal = GoogleCalendarService::linkFor($con, $mentor_id);
}

$approved_total = SessionRepository::countForMentorInStatuses($con, $mentor_id, ['approved']);

$active_page = 'calendar';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar — PeerConnect Mentor</title>
    <?php include __DIR__ . '/includes/style.php'; ?>
    <?php
    // Borrowed from the Sessions tabs: the panel heading, search box and
    // sort control. mp_list_styles() has to be called explicitly here —
    // this page uses those classes without opening a card panel, and the
    // stylesheet is otherwise only emitted by mp_panel_open().
    require_once __DIR__ . '/includes/session_list.php';
    mp_list_styles();
    ?>
    <style>
        .page-hd {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        /* ── Google Calendar card ── */
        .gcal-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 16px;
            flex: 0 1 560px;
            flex-wrap: wrap;
        }

        .gcal-logo {
            flex: 0 0 42px;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: #fff;
            border: 1px solid var(--gray-200);
            font-size: 15px;
            font-weight: 700;
            color: #1a73e8;
        }

        .gcal-btn {
            font: inherit;
            font-size: 12.5px;
            font-weight: 600;
            padding: 8px 14px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--gray-200);
            background: var(--surface);
            color: var(--forest);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .gcal-btn:hover {
            border-color: var(--mint);
        }

        .gcal-chip {
            display: inline-block;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            background: var(--gray-100);
            color: var(--gray-600);
        }

        /* ── Tabs ── */
        .cal-tabs {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .cal-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font: inherit;
            font-size: 13.5px;
            font-weight: 600;
            padding: 11px 18px;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            background: var(--surface);
            color: var(--gray-600);
            cursor: pointer;
        }

        .cal-tab-btn svg {
            width: 16px;
            height: 16px;
        }

        .cal-tab-btn.active {
            background: var(--primary);
            border-color: var(--forest);
            color: #fff;
        }

        .cal-tab-btn .n {
            display: inline-grid;
            place-items: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            border-radius: 999px;
            background: var(--mint-faint);
            color: var(--forest);
            font-size: 11px;
            font-weight: 700;
        }

        .cal-tab-btn.active .n {
            background: rgba(255, 255, 255, .22);
            color: #fff;
        }

        .cal-tip {
            margin-left: auto;
            font-size: 12.5px;
            color: var(--gray-500);
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        /* ── Layout ── */
        .avail-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 380px;
            gap: 18px;
            align-items: start;
        }

        /* ── Calendar ── */
        .cal-top {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .cal-nav {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            border: 1px solid var(--gray-200);
            background: var(--surface);
            display: grid;
            place-items: center;
            cursor: pointer;
            color: var(--gray-600);
        }

        .cal-nav:hover {
            border-color: var(--mint);
            color: var(--forest);
        }

        .cal-month {
            flex: 1;
            text-align: center;
            font-size: 17px;
            font-weight: 700;
            color: var(--forest);
            min-width: 150px;
        }

        .cal-views {
            display: inline-flex;
            gap: 2px;
            padding: 3px;
            border-radius: var(--radius);
            background: var(--gray-100);
        }

        .cal-view {
            font: inherit;
            font-size: 12.5px;
            font-weight: 600;
            padding: 6px 14px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: var(--gray-600);
            cursor: pointer;
        }

        .cal-view.active {
            background: var(--primary);
            color: #fff;
        }

        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 1px;
            background: var(--border);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .cal-day-hdr {
            background: var(--gray-50);
            padding: 9px 4px;
            text-align: center;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--gray-500);
        }

        .cal-cell {
            background: var(--surface);
            min-height: 92px;
            padding: 6px 7px;
            cursor: pointer;
            position: relative;
        }

        .cal-cell.out {
            background: var(--gray-50);
            cursor: default;
        }

        .cal-cell.out .cal-num {
            color: var(--gray-300);
        }

        .cal-cell:not(.out):hover {
            background: var(--mint-faint);
        }

        .cal-cell.sel {
            outline: 2px solid var(--mint);
            outline-offset: -2px;
            background: var(--mint-faint);
        }

        .cal-num {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--gray-700);
            display: inline-grid;
            place-items: center;
            width: 24px;
            height: 24px;
            border-radius: 50%;
        }

        .cal-cell.today .cal-num {
            background: var(--primary);
            color: #fff;
        }

        .cal-ev {
            display: block;
            margin-top: 3px;
            padding: 3px 6px;
            border-radius: 6px;
            font-size: 10.5px;
            font-weight: 600;
            line-height: 1.3;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .cal-ev.avail {
            background: #DCEBFF;
            color: #1D4ED8;
        }

        .cal-ev.session {
            background: #DDF5E6;
            color: #12784A;
        }

        .cal-ev .dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            margin-right: 4px;
            vertical-align: middle;
        }

        .cal-ev.avail .dot { background: #1D4ED8; }
        .cal-ev.session .dot { background: #12784A; }

        .cal-more {
            font-size: 10px;
            color: var(--gray-400);
            margin-top: 2px;
        }

        /* Week / day list */
        .cal-list {
            display: flex;
            flex-direction: column;
            gap: 1px;
            background: var(--border);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .cal-listrow {
            background: var(--surface);
            padding: 11px 14px;
            display: flex;
            gap: 14px;
            align-items: flex-start;
            cursor: pointer;
        }

        .cal-listrow:hover {
            background: var(--mint-faint);
        }

        .cal-listrow.today {
            background: var(--mint-faint);
        }

        .cal-listday {
            flex: 0 0 96px;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--forest);
        }

        .cal-listday span {
            display: block;
            font-size: 11px;
            font-weight: 500;
            color: var(--gray-400);
        }

        .cal-listev {
            flex: 1;
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .cal-foot {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .cal-key {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 12px;
            color: var(--gray-500);
        }

        .cal-key i {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }

        /* ── Form ── */
        .set-hd {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 18px;
        }

        .set-hd-ico {
            flex: 0 0 42px;
            width: 42px;
            height: 42px;
            border-radius: 11px;
            display: grid;
            place-items: center;
            background: var(--mint-faint);
            color: var(--forest);
        }

        .set-hd b {
            display: block;
            font-size: 15.5px;
            color: var(--forest);
        }

        .set-hd span {
            font-size: 12.5px;
            color: var(--gray-500);
        }

        .fld {
            margin-bottom: 15px;
        }

        .fld > label {
            display: block;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--forest);
            margin-bottom: 6px;
        }

        .fld .hint {
            font-size: 11.5px;
            color: var(--gray-400);
            margin-top: 5px;
        }

        .slot-row {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 8px;
        }

        .slot-row .form-input {
            flex: 1;
            min-width: 0;
        }

        .slot-dash {
            color: var(--gray-400);
            flex-shrink: 0;
        }

        .slot-del {
            flex: 0 0 38px;
            width: 38px;
            height: 38px;
            border-radius: 9px;
            border: 1px solid var(--danger-bg);
            background: var(--danger-bg);
            color: var(--danger);
            display: grid;
            place-items: center;
            cursor: pointer;
        }

        .slot-add {
            width: 100%;
            font: inherit;
            font-size: 12.5px;
            font-weight: 600;
            padding: 10px;
            border: 1px dashed var(--gray-200);
            border-radius: var(--radius);
            background: transparent;
            color: var(--gray-600);
            cursor: pointer;
        }

        .slot-add:hover {
            border-color: var(--mint);
            color: var(--forest);
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .form-actions .btn {
            flex: 1;
            justify-content: center;
        }

        /* ── Saved cards ── */
        .sv-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(430px, 1fr));
            gap: 16px;
        }

        .sv-card {
            display: flex;
            padding: 0;
            overflow: hidden;
        }

        .sv-date {
            flex: 0 0 108px;
            padding: 18px 10px;
            text-align: center;
            background: #EEF4FF;
        }

        .sv-card.is-past .sv-date { background: var(--gray-50); }
        .sv-card.is-booked .sv-date { background: #DDF5E6; }

        .sv-date svg {
            width: 22px;
            height: 22px;
            color: #1D4ED8;
        }

        .sv-card.is-past .sv-date svg { color: var(--gray-400); }
        .sv-card.is-booked .sv-date svg { color: #12784A; }

        .sv-mon {
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: .06em;
            color: var(--gray-500);
            margin-top: 8px;
        }

        .sv-dd {
            font-size: 30px;
            font-weight: 700;
            color: var(--forest);
            line-height: 1.05;
        }

        .sv-yy {
            font-size: 12px;
            color: var(--gray-500);
        }

        .sv-body {
            flex: 1;
            padding: 16px 18px;
            min-width: 0;
        }

        .sv-facts {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px 16px;
        }

        .sv-fact {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            min-width: 0;
        }

        .sv-fact svg {
            width: 16px;
            height: 16px;
            color: var(--gray-400);
            flex-shrink: 0;
            margin-top: 2px;
        }

        .sv-fact b {
            display: block;
            font-size: 11.5px;
            font-weight: 500;
            color: var(--gray-500);
        }

        .sv-fact span {
            font-size: 14px;
            font-weight: 700;
            color: var(--gray-900);
            overflow-wrap: anywhere;
        }

        .sv-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 14px;
            padding-top: 13px;
            border-top: 1px solid var(--border);
        }

        .sv-row .sv-fact {
            flex: 1 1 auto;
        }

        .sv-acts {
            display: flex;
            gap: 8px;
            margin-left: auto;
        }

        .sv-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font: inherit;
            font-size: 12.5px;
            font-weight: 700;
            padding: 9px 15px;
            border-radius: 9px;
            border: 0;
            cursor: pointer;
        }

        .sv-btn svg { width: 15px; height: 15px; }
        .sv-edit { background: #EEF4FF; color: #1D4ED8; }
        .sv-edit:hover { background: #DCEBFF; }
        .sv-del { background: var(--danger-bg); color: var(--danger); }
        .sv-del:hover { filter: brightness(.97); }
        .sv-btn[disabled] { opacity: .45; cursor: not-allowed; }

        /* Past the point of no return: shown, not hidden, so the mentor can
           see the rule rather than wonder where the control went. */
        .sv-shut { background: var(--gray-100); color: var(--gray-400); cursor: default; }

        /* History: the date range */
        .hs-range { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .hs-range label {
            display: inline-flex; align-items: center; gap: 7px; height: 38px; padding: 0 12px;
            border: 1px solid var(--border); border-radius: 10px; background: var(--surface);
            font-size: 12.5px; color: var(--gray-400);
        }
        .hs-range label:focus-within { border-color: var(--mint); }
        .hs-range input { border: 0; outline: 0; background: none; font-family: inherit; font-size: 13px; color: var(--gray-800); }
        .hs-clear { border: 0; background: none; font-family: inherit; font-size: 12.5px; font-weight: 600; color: var(--gray-500); cursor: pointer; }
        .hs-clear:hover { color: var(--danger); }

        /* Cancel dialog */
        .cx-back { display: none; position: fixed; inset: 0; z-index: 400; background: rgba(7,27,77,.45); align-items: center; justify-content: center; padding: 20px; }
        .cx-back.open { display: flex; }
        .cx-card { background: var(--surface); border-radius: 16px; padding: 24px; width: 470px; max-width: 95vw; box-shadow: 0 18px 50px rgba(16,40,70,.22); }
        .cx-card h3 { margin: 0 0 6px; font-size: 17px; font-weight: 700; color: var(--forest); }
        .cx-when { font-size: 13px; color: var(--gray-500); margin: 0 0 14px; }
        .cx-warn { display: flex; gap: 10px; padding: 12px 14px; border-radius: 11px; background: var(--danger-bg); color: var(--danger); font-size: 12.5px; line-height: 1.5; margin-bottom: 14px; }
        .cx-warn svg { width: 17px; height: 17px; flex: none; margin-top: 1px; }
        .cx-card label { display: block; font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; }
        .cx-card textarea { width: 100%; font-family: inherit; font-size: 13px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; resize: vertical; }
        .cx-hint { font-size: 11.5px; color: var(--gray-400); margin: 6px 0 0; }
        .cx-acts { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }

        /* Pinned while a booking exists — readonly, and it should look it. */
        .form-input.is-locked,
        input.is-locked {
            background: #F1F3F7;
            color: #6B7280;
            cursor: not-allowed;
        }

        .sv-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            background: #DCEBFF;
            color: #1D4ED8;
        }

        .sv-pill.group { background: var(--purple-bg); color: var(--purple); }

        .sv-side {
            flex: 0 0 96px;
            padding: 18px 10px;
            text-align: center;
            border-left: 1px solid var(--border);
        }

        .sv-cap {
            font-size: 11.5px;
            color: var(--gray-500);
            margin-top: 10px;
        }

        .sv-cap b {
            display: block;
            font-size: 17px;
            color: var(--gray-900);
        }

        @media (max-width: 1180px) {
            .avail-grid { grid-template-columns: minmax(0, 1fr); }
        }

        @media (max-width: 620px) {
            /* A month cell is ~44px wide on a phone, so a labelled chip just
               renders as an ellipsis. Show each event as a coloured dot
               instead — the legend says what the colours mean, and the title
               attribute still carries the detail. */
            .cal-ev {
                display: inline-block;
                width: 8px;
                height: 8px;
                padding: 0;
                margin: 4px 3px 0 0;
                border-radius: 50%;
                font-size: 0;
                overflow: hidden;
            }

            .cal-ev .dot { display: none; }
            .cal-ev.avail { background: #1D4ED8; }
            .cal-ev.session { background: #12784A; }
            .cal-more { font-size: 9px; }

            .sv-list { grid-template-columns: minmax(0, 1fr); }
            .sv-card { flex-wrap: wrap; }
            .sv-date { flex: 1 1 100%; display: flex; align-items: center; justify-content: center; gap: 10px; padding: 12px; }
            .sv-mon, .sv-dd, .sv-yy { margin: 0; }
            .sv-side { flex: 1 1 100%; border-left: 0; border-top: 1px solid var(--border); }
            .cal-cell { min-height: 66px; }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <main class="main fade-in">

            <div class="page-hd">
                <div>
                    <h1>Calendar</h1>
                    <p>Set your availability for mentoring sessions</p>
                </div>

                <!-- Google Calendar link -->
                <div class="card gcal-card">
                    <div class="gcal-logo" aria-hidden="true">31</div>

                    <div style="flex:1;min-width:160px;">
                        <?php if ($gcal): ?>
                            <div style="font-size:13.5px;font-weight:700;color:var(--gray-900);display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
                                Google Calendar connected
                                <?php if (!empty($gcal['last_error'])): ?>
                                    <span class="gcal-chip" style="color:var(--danger);background:var(--danger-bg);">Needs attention</span>
                                <?php else: ?>
                                    <span class="gcal-chip" style="color:var(--success);background:var(--success-bg);">Syncing</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:12px;color:var(--gray-500);line-height:1.5;">
                                <?= $gcal['google_email'] ? htmlspecialchars($gcal['google_email']) . ' · ' : '' ?>
                                <?= $approved_total ?> approved session<?= $approved_total === 1 ? '' : 's' ?> kept in sync<?php
                                                                                                                            if ($gcal['since_sync_secs'] !== null):
                                                                                                                                $mins = (int)floor((int)$gcal['since_sync_secs'] / 60);
                                                                                                                                echo ' · synced ' . ($mins < 1 ? 'just now' : ($mins < 60 ? $mins . 'm ago' : floor($mins / 60) . 'h ago'));
                                                                                                                            endif; ?>
                            </div>
                            <?php if (!empty($gcal['last_error'])): ?>
                                <div style="font-size:11.5px;color:var(--danger);margin-top:4px;"><?= htmlspecialchars($gcal['last_error']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div style="font-size:13.5px;font-weight:700;color:var(--gray-900);">Connect Google Calendar</div>
                            <div style="font-size:12px;color:var(--gray-500);line-height:1.5;">
                                Approved sessions go straight into your Google Calendar, so they show up on your phone
                                alongside everything else.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="flex-shrink:0;display:flex;gap:8px;align-items:center;">
                        <?php if ($gcal): ?>
                            <form method="POST" action="<?= htmlspecialchars(url('gcal-action')) ?>" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="action" value="sync">
                                <button type="submit" class="gcal-btn">Sync now</button>
                            </form>
                            <form method="POST" action="<?= htmlspecialchars(url('gcal-action')) ?>" style="display:inline;"
                                data-pc-confirm="Disconnect Google Calendar?&#10;Sessions already added stay in your calendar, but new ones will stop syncing."
                                data-pc-tone="danger" data-pc-ok="Disconnect">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="action" value="disconnect">
                                <button type="submit" class="gcal-btn" style="color:var(--danger);">Disconnect</button>
                            </form>
                        <?php elseif ($gcal_configured): ?>
                            <a href="<?= htmlspecialchars(url('gcal-connect')) ?>" class="gcal-btn">Connect</a>
                        <?php else: ?>
                            <span class="gcal-chip" title="GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET are missing from .env">Not configured</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>


            <!-- Tabs -->
            <div class="cal-tabs">
                <button type="button" class="cal-tab-btn <?= $activeTab === 'calendar' ? 'active' : '' ?>" id="ctab-calendar" onclick="switchTab('calendar')">
                    <?= mp_svg('calendar') ?> Set Availability
                </button>
                <button type="button" class="cal-tab-btn <?= $activeTab === 'saved' ? 'active' : '' ?>" id="ctab-saved" onclick="switchTab('saved')">
                    <?= mp_svg('book') ?> Saved Availability
                    <?php if (count($savedOpen) > 0): ?><span class="n"><?= count($savedOpen) ?></span><?php endif; ?>
                </button>
                <button type="button" class="cal-tab-btn <?= $activeTab === 'history' ? 'active' : '' ?>" id="ctab-history" onclick="switchTab('history')">
                    <?= mp_svg('clock') ?> History
                    <?php if (count($savedPast) > 0): ?><span class="n"><?= count($savedPast) ?></span><?php endif; ?>
                </button>
                <span class="cal-tip">Set your regular availability so mentees can book sessions with you.</span>
            </div>

            <!-- ══ TAB: SET AVAILABILITY ══ -->
            <div id="tab-calendar" <?= $activeTab !== 'calendar' ? 'hidden' : '' ?>>
                <div class="avail-grid">

                    <div class="card" style="padding:20px;">
                        <div class="cal-top">
                            <button class="cal-nav" type="button" onclick="shiftPeriod(-1)" aria-label="Previous">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>
                            <button class="cal-nav" type="button" onclick="shiftPeriod(1)" aria-label="Next" style="width:34px;">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                            <button class="gcal-btn" type="button" onclick="goToday()">Today</button>
                            <span class="cal-month" id="calLabel"></span>
                            <span class="cal-views">
                                <button type="button" class="cal-view active" data-view="month" onclick="setView('month')">Month</button>
                                <button type="button" class="cal-view" data-view="week" onclick="setView('week')">Week</button>
                                <button type="button" class="cal-view" data-view="day" onclick="setView('day')">Day</button>
                            </span>
                        </div>

                        <div id="calMonth">
                            <div class="cal-grid" style="border-bottom:0;border-bottom-left-radius:0;border-bottom-right-radius:0;">
                                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d): ?>
                                    <div class="cal-day-hdr"><?= $d ?></div>
                                <?php endforeach; ?>
                            </div>
                            <div id="calCells" class="cal-grid" style="border-top:0;border-top-left-radius:0;border-top-right-radius:0;"></div>
                        </div>

                        <div id="calListWrap" class="cal-list" hidden></div>

                        <div class="cal-foot">
                            <span class="cal-key"><i style="background:#12784A;"></i> Scheduled Session</span>
                            <span class="cal-key"><i style="background:#1D4ED8;"></i> Your Availability</span>
                            <span class="cal-key"><i style="background:var(--primary);"></i> Today</span>
                            <span class="cal-key"><i style="background:var(--mint-faint);border:1px solid var(--mint);"></i> Selected date</span>
                            <button class="gcal-btn" type="button" onclick="goToday()" style="margin-left:auto;">Go to Today</button>
                        </div>
                    </div>

                    <!-- Form -->
                    <div class="card" style="padding:20px;">
                        <div class="set-hd">
                            <span class="set-hd-ico"><?= mp_svg('calendar') ?></span>
                            <div>
                                <b id="formTitle">Set Availability</b>
                                <span id="formSub">Add a new time slot for mentoring sessions</span>
                            </div>
                        </div>

                        <form method="POST" id="availForm" action="<?= htmlspecialchars($self_url) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="date" id="dateInput">
                            <input type="hidden" name="edit_id" id="editId" value="">

                            <div class="fld">
                                <label for="dateShown">Select a date</label>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <input type="text" id="dateShown" class="form-input" readonly placeholder="Pick a day on the calendar" style="flex:1;">
                                    <button type="button" class="slot-del" onclick="clearDate()" title="Clear date" aria-label="Clear date">
                                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="fld">
                                <label for="sessionType">Session Type</label>
                                <select id="sessionType" name="session_type" required class="form-input" onchange="paintCapacity()">
                                    <option value="">Select type</option>
                                    <option value="1v1">1-on-1 Session</option>
                                    <option value="group">Group Session</option>
                                </select>
                                <p class="hint" id="capacityNote">Capacity: 1 for 1-on-1 · 15 for group session</p>
                            </div>

                            <div class="fld">
                                <label for="subjectIn">Subject</label>
                                <input type="text" id="subjectIn" name="subject" required maxlength="100" class="form-input" placeholder="e.g. Mathematics, Teaching Strategies">
                            </div>

                            <div class="fld">
                                <label for="topicsIn">Topics</label>
                                <input type="text" id="topicsIn" name="topics" required maxlength="255" class="form-input" placeholder="e.g. Algebra, Equations">
                            </div>

                            <div class="fld">
                                <label for="aboutIn">About <span style="font-weight:400;color:var(--gray-400);">(optional)</span></label>
                                <textarea id="aboutIn" name="about" class="form-input" rows="2" placeholder="Brief description of this session…" style="resize:vertical;"></textarea>
                            </div>

                            <div class="fld">
                                <label>Time Slots</label>
                                <div id="slots"></div>
                                <button type="button" class="slot-add" onclick="addSlot()">+ Add Another Time Slot</button>
                                <p class="hint">Each slot has to be at least <?= CAL_MIN_MINUTES ?> minutes long.</p>
                            </div>

                            <div class="form-actions">
                                <button type="button" class="btn btn-outline" onclick="resetForm()">Cancel</button>
                                <button type="submit" class="btn btn-primary" id="saveBtn">Save Availability</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ══ TAB: SAVED ══ -->
            <div id="tab-saved" <?= $activeTab !== 'saved' ? 'hidden' : '' ?>>
                <?php if (!$savedOpen): ?>
                    <div class="card empty-state-lg">
                        <span class="es-icon"><?= mp_svg('calendar') ?></span>
                        <h3 class="es-title">No availability saved yet</h3>
                        <p class="es-body">Pick a date on the calendar and add a time slot — mentees can only book the times you open.</p>
                        <button type="button" class="btn btn-primary" onclick="switchTab('calendar')">Set availability</button>
                    </div>
                <?php else: ?>
                    <div class="mp-hd">
                        <span class="mp-hd-ico"><?= mp_svg('book') ?></span>
                        <div class="mp-hd-txt">
                            <h2>Saved Availability</h2>
                            <p>Today and everything ahead. Booked slots cannot be edited — the booking is matched on the date and time. Past slots move to History on their own.</p>
                        </div>
                        <div class="mp-tools">
                            <label class="mp-search">
                                <?= mp_svg('search') ?>
                                <input type="search" id="svSearch" placeholder="Search saved availability…" aria-label="Search saved availability">
                            </label>
                            <span class="mp-sort">
                                <?= mp_svg('calendar') ?>
                                <select id="svSort" aria-label="Sort saved availability">
                                    <option value="newest">Newest first</option>
                                    <option value="oldest">Oldest first</option>
                                    <option value="subject">Subject</option>
                                </select>
                            </span>
                        </div>
                    </div>

                    <div class="sv-list" id="svList">
                        <?php foreach ($savedOpen as $av):
                            $ts     = strtotime($av['date'] . ' ' . $av['start_time']);
                            $isPast = $av['date'] < date('Y-m-d');
                            $booked = !empty($av['booked']);
                            $endTs  = $ts + ((int)$av['duration'] * 60);
                            // Late enough and the mentee may already be on their way.
                            $canCancel = ($ts - time()) >= CAL_CANCEL_LEAD_HOURS * 3600;
                        ?>
                            <article class="card sv-card <?= $isPast ? 'is-past' : '' ?> <?= $booked ? 'is-booked' : '' ?>"
                                data-search="<?= htmlspecialchars(strtolower($av['subject'] . ' ' . $av['topics'] . ' ' . $av['session_type'] . ' ' . date('M d Y', $ts))) ?>"
                                data-when="<?= $ts ?>"
                                data-subject="<?= htmlspecialchars($av['subject']) ?>">

                                <div class="sv-date">
                                    <?= mp_svg('calendar') ?>
                                    <div>
                                        <div class="sv-mon"><?= strtoupper(date('M', $ts)) ?></div>
                                        <div class="sv-dd"><?= date('d', $ts) ?></div>
                                        <div class="sv-yy"><?= date('Y', $ts) ?></div>
                                    </div>
                                    <div style="margin-top:8px;">
                                        <?php if ($booked): ?>
                                            <span class="badge badge-approved">Booked</span>
                                        <?php elseif ($isPast): ?>
                                            <span class="badge badge-gray">Past</span>
                                        <?php else: ?>
                                            <span class="badge badge-blue">Open</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="sv-body">
                                    <div class="sv-facts">
                                        <div class="sv-fact">
                                            <?= mp_svg('clock') ?>
                                            <span><b>Time</b><?= date('g:i A', $ts) ?> – <?= date('g:i A', $endTs) ?></span>
                                        </div>
                                        <div class="sv-fact">
                                            <?= mp_svg('clock') ?>
                                            <span><b>Duration</b><?= (int)$av['duration'] ?> mins</span>
                                        </div>
                                        <div class="sv-fact">
                                            <?= mp_svg('book') ?>
                                            <span><b>Subject</b><?= htmlspecialchars($av['subject']) ?></span>
                                        </div>
                                        <div class="sv-fact">
                                            <?= mp_svg('doc') ?>
                                            <span><b>Topics</b><?= htmlspecialchars($av['topics'] ?: '—') ?></span>
                                        </div>
                                    </div>

                                    <div class="sv-row">
                                        <div class="sv-fact">
                                            <?= mp_svg('doc') ?>
                                            <span><b>Session Type</b>
                                                <span class="sv-pill <?= $av['session_type'] === 'group' ? 'group' : '' ?>">
                                                    <?= mp_svg('users', 'width="13" height="13"') ?>
                                                    <?= $av['session_type'] === 'group' ? 'Group' : '1v1' ?>
                                                </span>
                                            </span>
                                        </div>
                                        <div class="sv-acts">
                                            <button type="button" class="sv-btn sv-edit"
                                                <?= $booked ? 'title="Booked — you can still change the topics, description and session type. The date, time and subject are fixed."' : '' ?>
                                                onclick='startEdit(<?= json_encode([
                                                                        "id"       => (int)$av["id"],
                                                                        "date"     => $av["date"],
                                                                        "start"    => substr($av["start_time"], 0, 5),
                                                                        "end"      => date("H:i", $endTs),
                                                                        "subject"  => $av["subject"],
                                                                        "topics"   => $av["topics"],
                                                                        "about"    => $av["about"],
                                                                        "type"     => $av["session_type"],
                                                                        "booked"   => (bool)$booked,
                                                                    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) ?>)'>
                                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 18.5 15 7.5l3 3L7 21.5H4v-3Z" />
                                                    <path stroke-linecap="round" d="m13 9.5 3 3" />
                                                </svg>
                                                Edit
                                            </button>
                                            <?php if ($canCancel): ?>
                                                <button type="button" class="sv-btn sv-del" onclick='openCancel(<?= json_encode([
                                                    "id"     => (int)$av["id"],
                                                    "when"   => date("l j F, g:i A", $ts),
                                                    "subject" => $av["subject"],
                                                    "booked" => (int)$av["booked_n"],
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) ?>)'>
                                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <circle cx="12" cy="12" r="9" />
                                                        <path stroke-linecap="round" d="m9 9 6 6m0-6-6 6" />
                                                    </svg>
                                                    Cancel
                                                </button>
                                            <?php else: ?>
                                                <span class="sv-btn sv-shut" title="A slot can only be cancelled more than <?= CAL_CANCEL_LEAD_HOURS ?> hours before it starts. Message your mentee instead.">
                                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <rect x="5" y="11" width="14" height="9" rx="2" />
                                                        <path stroke-linecap="round" d="M8 11V8a4 4 0 0 1 8 0v3" />
                                                    </svg>
                                                    Too late to cancel
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="sv-side">
                                    <span class="sv-pill <?= $av['session_type'] === 'group' ? 'group' : '' ?>">
                                        <?= mp_svg('users', 'width="13" height="13"') ?>
                                        <?= $av['session_type'] === 'group' ? 'Group' : '1v1' ?>
                                    </span>
                                    <div class="sv-cap">Capacity<b><?= (int)$av['capacity'] ?></b></div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <p class="mp-none" id="svCount"></p>
                <?php endif; ?>
            </div>

            <!-- ══ TAB: HISTORY ══ -->
            <div id="tab-history" <?= $activeTab !== 'history' ? 'hidden' : '' ?>>
                <?php if (!$savedPast): ?>
                    <div class="card empty-state-lg">
                        <span class="es-icon"><?= mp_svg('clock') ?></span>
                        <h3 class="es-title">Nothing here yet</h3>
                        <p class="es-body">Slots move here by themselves once their date has passed. Everything you have opened is still ahead.</p>
                    </div>
                <?php else: ?>
                    <div class="mp-hd">
                        <span class="mp-hd-ico"><?= mp_svg('clock') ?></span>
                        <div class="mp-hd-txt">
                            <h2>History</h2>
                            <p>Availability whose date has passed. Kept as a record — these cannot be edited or cancelled.</p>
                        </div>
                        <div class="mp-tools">
                            <span class="hs-range">
                                <label>From <input type="date" id="hsFrom" aria-label="From date"></label>
                                <label>To <input type="date" id="hsTo" aria-label="To date"></label>
                                <button type="button" class="hs-clear" id="hsClear" hidden>Clear</button>
                            </span>
                        </div>
                    </div>

                    <div class="sv-list" id="hsList">
                        <?php foreach ($savedPast as $av):
                            $hts    = strtotime($av['date'] . ' ' . $av['start_time']);
                            $hEndTs = $hts + ((int)$av['duration'] * 60);
                        ?>
                            <article class="card sv-card is-past" data-date="<?= htmlspecialchars($av['date']) ?>">
                                <div class="sv-date">
                                    <?= mp_svg('calendar') ?>
                                    <div>
                                        <div class="sv-mon"><?= strtoupper(date('M', $hts)) ?></div>
                                        <div class="sv-dd"><?= date('d', $hts) ?></div>
                                        <div class="sv-yy"><?= date('Y', $hts) ?></div>
                                    </div>
                                    <div style="margin-top:8px;">
                                        <?php if (!empty($av['booked'])): ?>
                                            <span class="badge badge-approved">Was booked</span>
                                        <?php else: ?>
                                            <span class="badge badge-gray">Nobody booked</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="sv-body">
                                    <div class="sv-facts">
                                        <div class="sv-fact">
                                            <?= mp_svg('clock') ?>
                                            <span><b>Time</b><?= date('g:i A', $hts) ?> &ndash; <?= date('g:i A', $hEndTs) ?></span>
                                        </div>
                                        <div class="sv-fact">
                                            <?= mp_svg('clock') ?>
                                            <span><b>Duration</b><?= (int)$av['duration'] ?> mins</span>
                                        </div>
                                        <div class="sv-fact">
                                            <?= mp_svg('book') ?>
                                            <span><b>Subject</b><?= htmlspecialchars($av['subject']) ?></span>
                                        </div>
                                        <div class="sv-fact">
                                            <?= mp_svg('doc') ?>
                                            <span><b>Topics</b><?= htmlspecialchars($av['topics'] ?: '—') ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="sv-side">
                                    <span class="sv-pill <?= $av['session_type'] === 'group' ? 'group' : '' ?>">
                                        <?= mp_svg('users', 'width="13" height="13"') ?>
                                        <?= $av['session_type'] === 'group' ? 'Group' : '1v1' ?>
                                    </span>
                                    <div class="sv-cap">Capacity<b><?= (int)$av['capacity'] ?></b></div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <p class="mp-none" id="hsCount"></p>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!--
        Cancelling a slot people have booked is not a delete with a scarier
        label: their sessions end, and they are owed a reason. So the reason
        is part of the dialog rather than an afterthought, and the server
        refuses without one whenever anybody is booked.
    -->
    <div class="cx-back" id="cxBack">
        <form method="POST" class="cx-card" action="<?= htmlspecialchars($self_url) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="cancel_id" id="cxId">

            <h3 id="cxTitle">Cancel this slot?</h3>
            <p class="cx-when" id="cxWhen"></p>

            <div class="cx-warn" id="cxWarn" hidden>
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 2.5 17.4A2 2 0 0 0 4.2 20.4h15.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
                </svg>
                <span id="cxWarnTxt"></span>
            </div>

            <label for="cxReason">Reason <span id="cxReq" style="color:var(--danger);" hidden>*</span></label>
            <textarea id="cxReason" name="cancel_reason" rows="3" maxlength="500"
                      placeholder="Why are you cancelling? This is sent to everyone booked."></textarea>
            <p class="cx-hint" id="cxHint"></p>

            <div class="cx-acts">
                <button type="button" class="btn btn-outline" onclick="closeCancel()">Keep the slot</button>
                <button type="submit" class="btn btn-red" id="cxGo">Cancel slot</button>
            </div>
        </form>
    </div>

    <script>
        const CAL_EVENTS = <?= json_encode($calEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const TODAY_ISO = <?= json_encode(date('Y-m-d')) ?>;

        /* Events keyed by ISO date, each day sorted by start time. */
        const BY_DAY = {};
        CAL_EVENTS.forEach(function(e) {
            (BY_DAY[e.date] = BY_DAY[e.date] || []).push(e);
        });
        Object.keys(BY_DAY).forEach(function(d) {
            BY_DAY[d].sort(function(a, b) {
                return String(a.sort).localeCompare(String(b.sort));
            });
        });

        let cursor = new Date();
        let view = 'month';
        let selected = null; // ISO date
        let editing = false;
        // True while editing a slot a mentee has already booked: its date,
        // time and subject are pinned because the booking is matched on them.
        let lockedByBooking = false;

        const iso = d => d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
        const parseIso = s => {
            const p = s.split('-');
            return new Date(+p[0], +p[1] - 1, +p[2]);
        };

        /* ── Tabs ── */
        function switchTab(tab) {
            ['calendar', 'saved', 'history'].forEach(function (t) {
                document.getElementById('tab-' + t).hidden = tab !== t;
                document.getElementById('ctab-' + t).classList.toggle('active', tab === t);
            });
            window.history.replaceState(null, '', '?tab=' + tab);
        }

        /* ── Calendar ── */
        function setView(v) {
            view = v;
            document.querySelectorAll('.cal-view').forEach(function(b) {
                b.classList.toggle('active', b.dataset.view === v);
            });
            render();
        }

        function shiftPeriod(n) {
            if (view === 'month') cursor.setMonth(cursor.getMonth() + n);
            else if (view === 'week') cursor.setDate(cursor.getDate() + 7 * n);
            else cursor.setDate(cursor.getDate() + n);
            render();
        }

        function goToday() {
            cursor = new Date();
            render();
        }

        function eventChip(e) {
            const s = document.createElement('span');
            s.className = 'cal-ev ' + (e.kind === 'session' ? 'session' : 'avail');
            const dot = document.createElement('span');
            dot.className = 'dot';
            s.appendChild(dot);
            s.appendChild(document.createTextNode(e.time + ' ' + e.label));
            s.title = (e.kind === 'session' ? 'Session with ' + (e.with || '') : 'Your availability') +
                ' — ' + e.subject + ' at ' + e.time;
            return s;
        }

        function pickDate(isoStr, isPast) {
            // The server refuses new slots in the past, so do not let the form
            // be filled with one; an existing slot being edited keeps its date.
            if (isPast && !editing) return;
            // A booked slot's date is pinned — startEdit sets it once, and a
            // click on another day must not move it.
            if (lockedByBooking && document.getElementById('dateInput').value) return;
            selected = isoStr;
            document.getElementById('dateInput').value = isoStr;
            const d = parseIso(isoStr);
            document.getElementById('dateShown').value = d.toLocaleDateString(undefined, {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            render();
        }

        function clearDate() {
            selected = null;
            document.getElementById('dateInput').value = '';
            document.getElementById('dateShown').value = '';
            render();
        }

        function renderMonth() {
            const label = document.getElementById('calLabel');
            const cells = document.getElementById('calCells');
            cells.textContent = '';
            const y = cursor.getFullYear(),
                m = cursor.getMonth();
            label.textContent = cursor.toLocaleDateString(undefined, {
                month: 'long',
                year: 'numeric'
            });

            const first = new Date(y, m, 1);
            const start = new Date(first);
            start.setDate(1 - first.getDay()); // back to the Sunday

            for (let i = 0; i < 42; i++) {
                const d = new Date(start);
                d.setDate(start.getDate() + i);
                const key = iso(d);
                const outside = d.getMonth() !== m;
                const past = key < TODAY_ISO;

                const cell = document.createElement('div');
                cell.className = 'cal-cell' +
                    (outside ? ' out' : '') +
                    (key === TODAY_ISO ? ' today' : '') +
                    (key === selected ? ' sel' : '');
                if (!outside) {
                    cell.setAttribute('role', 'button');
                    cell.tabIndex = 0;
                    cell.addEventListener('click', function() {
                        pickDate(key, past);
                    });
                    cell.addEventListener('keydown', function(ev) {
                        if (ev.key === 'Enter' || ev.key === ' ') {
                            ev.preventDefault();
                            pickDate(key, past);
                        }
                    });
                }

                const num = document.createElement('span');
                num.className = 'cal-num';
                num.textContent = d.getDate();
                cell.appendChild(num);

                const evs = BY_DAY[key] || [];
                evs.slice(0, 2).forEach(function(e) {
                    cell.appendChild(eventChip(e));
                });
                if (evs.length > 2) {
                    const more = document.createElement('div');
                    more.className = 'cal-more';
                    more.textContent = '+' + (evs.length - 2) + ' more';
                    cell.appendChild(more);
                }
                cells.appendChild(cell);
            }
        }

        function renderList(days, labelText) {
            const wrap = document.getElementById('calListWrap');
            wrap.textContent = '';
            document.getElementById('calLabel').textContent = labelText;

            days.forEach(function(d) {
                const key = iso(d);
                const past = key < TODAY_ISO;
                const row = document.createElement('div');
                row.className = 'cal-listrow' + (key === TODAY_ISO ? ' today' : '');
                row.addEventListener('click', function() {
                    pickDate(key, past);
                });

                const day = document.createElement('div');
                day.className = 'cal-listday';
                day.textContent = d.toLocaleDateString(undefined, {
                    weekday: 'short',
                    day: 'numeric'
                });
                const sub = document.createElement('span');
                sub.textContent = d.toLocaleDateString(undefined, {
                    month: 'short',
                    year: 'numeric'
                });
                day.appendChild(sub);
                row.appendChild(day);

                const evs = document.createElement('div');
                evs.className = 'cal-listev';
                const list = BY_DAY[key] || [];
                if (!list.length) {
                    const none = document.createElement('span');
                    none.style.cssText = 'font-size:12px;color:var(--gray-400);';
                    none.textContent = past ? 'Nothing' : 'Nothing yet — click to add';
                    evs.appendChild(none);
                } else {
                    list.forEach(function(e) {
                        evs.appendChild(eventChip(e));
                    });
                }
                row.appendChild(evs);
                wrap.appendChild(row);
            });
        }

        function render() {
            const isMonth = view === 'month';
            document.getElementById('calMonth').hidden = !isMonth;
            document.getElementById('calListWrap').hidden = isMonth;

            if (isMonth) return renderMonth();

            if (view === 'week') {
                const start = new Date(cursor);
                start.setDate(cursor.getDate() - cursor.getDay());
                const days = [];
                for (let i = 0; i < 7; i++) {
                    const d = new Date(start);
                    d.setDate(start.getDate() + i);
                    days.push(d);
                }
                const end = days[6];
                renderList(days, start.toLocaleDateString(undefined, {
                        month: 'short',
                        day: 'numeric'
                    }) + ' – ' +
                    end.toLocaleDateString(undefined, {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric'
                    }));
            } else {
                renderList([new Date(cursor)], cursor.toLocaleDateString(undefined, {
                    weekday: 'long',
                    month: 'long',
                    day: 'numeric',
                    year: 'numeric'
                }));
            }
        }

        /* ── Form ── */
        function paintCapacity() {
            const t = document.getElementById('sessionType').value;
            document.getElementById('capacityNote').textContent =
                t === 'group' ? 'Capacity: 15 for a group session' :
                t === '1v1' ? 'Capacity: 1 for a 1-on-1 session' :
                'Capacity: 1 for 1-on-1 · 15 for group session';
        }

        function slotRow(start, end, removable) {
            const row = document.createElement('div');
            row.className = 'slot-row';

            const a = document.createElement('input');
            a.type = 'time';
            a.name = 'start_time[]';
            a.required = true;
            a.className = 'form-input';
            a.value = start || '';

            const dash = document.createElement('span');
            dash.className = 'slot-dash';
            dash.textContent = '–';

            const b = document.createElement('input');
            b.type = 'time';
            b.name = 'end_time[]';
            b.required = true;
            b.className = 'form-input';
            b.value = end || '';

            row.append(a, dash, b);

            if (removable) {
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'slot-del';
                del.title = 'Remove this slot';
                del.setAttribute('aria-label', 'Remove this slot');
                del.innerHTML = '<svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">' +
                    '<path stroke-linecap="round" stroke-linejoin="round" d="M5 7h14M10 7V5h4v2M6.5 7l.8 12a1.5 1.5 0 0 0 1.5 1.4h6.4a1.5 1.5 0 0 0 1.5-1.4l.8-12"/></svg>';
                del.addEventListener('click', function() {
                    row.remove();
                });
                row.appendChild(del);
            }
            return row;
        }

        function addSlot() {
            document.getElementById('slots').appendChild(slotRow('', '', true));
        }

        function resetForm() {
            editing = false;
            lockedByBooking = false;
            document.querySelectorAll('#slots input, #subjectIn').forEach(function (el) {
                el.readOnly = false;
                el.classList.remove('is-locked');
            });
            document.getElementById('availForm').reset();
            document.getElementById('editId').value = '';
            document.getElementById('slots').textContent = '';
            document.getElementById('slots').appendChild(slotRow('', '', false));
            document.querySelector('.slot-add').hidden = false;
            document.getElementById('formTitle').textContent = 'Set Availability';
            document.getElementById('formSub').textContent = 'Add a new time slot for mentoring sessions';
            document.getElementById('saveBtn').textContent = 'Save Availability';
            clearDate();
            paintCapacity();
        }

        /* Loads a saved slot into the form. One row only — an edit updates a
           single availability row, so the "add another" control is hidden. */
        function startEdit(a) {
            editing = true;
            switchTab('calendar');
            document.getElementById('editId').value = a.id;
            document.getElementById('sessionType').value = a.type;
            document.getElementById('subjectIn').value = a.subject || '';
            document.getElementById('topicsIn').value = a.topics || '';
            document.getElementById('aboutIn').value = a.about || '';

            const slots = document.getElementById('slots');
            slots.textContent = '';
            slots.appendChild(slotRow(a.start, a.end, false));
            document.querySelector('.slot-add').hidden = true;

            document.getElementById('formTitle').textContent = 'Edit Availability';
            document.getElementById('saveBtn').textContent = 'Update Availability';

            // A mentee's booking is matched to this slot on its date, time and
            // subject, so those three are fixed while a booking exists — the
            // server pins them either way, and locking the inputs means the
            // mentor is not invited to type a change that would be discarded.
            lockedByBooking = !!a.booked;
            const lockFields = document.querySelectorAll('#slots input, #subjectIn');
            lockFields.forEach(function (el) {
                el.readOnly = lockedByBooking;
                el.classList.toggle('is-locked', lockedByBooking);
            });
            document.getElementById('formSub').textContent = lockedByBooking
                ? 'A mentee has booked this slot — you can change the topics, description and session type.'
                : 'Change this slot and save';

            cursor = parseIso(a.date);
            pickDate(a.date, false);
            paintCapacity();
            document.getElementById('availForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // async because pcConfirm returns a promise where confirm() blocked.
        // Nothing reads the return value — both callers are onclick handlers.
        /* ── Cancelling a slot ──
           A booked slot is not a delete with a scarier label: people lose a
           session and are owed a reason, so the dialog asks for one and the
           server refuses without it. The counts are spelled out because
           "6 mentees" should give you more pause than "1". */
        function openCancel(slot) {
            const back = document.getElementById('cxBack');
            const reason = document.getElementById('cxReason');
            const warn = document.getElementById('cxWarn');
            const req = document.getElementById('cxReq');
            const hint = document.getElementById('cxHint');
            const one = slot.booked === 1;

            document.getElementById('cxId').value = slot.id;
            document.getElementById('cxWhen').textContent = slot.subject + ' · ' + slot.when;
            reason.value = '';

            if (slot.booked > 0) {
                document.getElementById('cxTitle').textContent =
                    one ? 'Cancel this slot and the session in it?' : 'Cancel this slot and the ' + slot.booked + ' sessions in it?';
                document.getElementById('cxWarnTxt').textContent =
                    slot.booked + (one ? ' mentee has' : ' mentees have') + ' booked this slot. Cancelling ends ' +
                    (one ? 'their session' : 'their sessions') + ' and sends ' + (one ? 'them' : 'each of them') + ' your reason.';
                warn.hidden = false;
                req.hidden = false;
                reason.required = true;
                hint.textContent = 'Required — this is what they will read.';
            } else {
                document.getElementById('cxTitle').textContent = 'Cancel this availability slot?';
                warn.hidden = true;
                req.hidden = true;
                reason.required = false;
                hint.textContent = 'Nobody has booked this slot, so there is nobody to tell. A note is optional.';
            }

            back.classList.add('open');
            reason.focus();
        }

        function closeCancel() {
            document.getElementById('cxBack').classList.remove('open');
        }

        document.getElementById('cxBack').addEventListener('click', function (e) {
            if (e.target === this) closeCancel();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeCancel();
        });

        document.getElementById('availForm').addEventListener('submit', function(e) {
            if (!document.getElementById('dateInput').value) {
                e.preventDefault();
                pcToast('Pick a date on the calendar first.', 'error');
                return;
            }
            // Mirror the server's checks so a bad range does not cost a round
            // trip. The server applies both again — this is only the faster
            // way to hear about it.
            const MIN_MINUTES = <?= CAL_MIN_MINUTES ?>;
            const mins = (v) => { const [h, m] = v.split(':').map(Number); return h * 60 + m; };

            const starts = [...document.querySelectorAll('input[name="start_time[]"]')];
            const ends = [...document.querySelectorAll('input[name="end_time[]"]')];
            for (let i = 0; i < starts.length; i++) {
                if (!starts[i].value || !ends[i].value) continue;
                if (ends[i].value <= starts[i].value) {
                    e.preventDefault();
                    pcToast('Each slot needs an end time later than its start.', 'error');
                    ends[i].focus();
                    return;
                }
                if (mins(ends[i].value) - mins(starts[i].value) < MIN_MINUTES) {
                    e.preventDefault();
                    pcToast(<?= json_encode(CAL_TOO_SHORT) ?>, 'error');
                    ends[i].focus();
                    return;
                }
            }
        });

        /* ── Saved: search + sort ── */
        (function() {
            const list = document.getElementById('svList');
            if (!list) return;
            const search = document.getElementById('svSearch');
            const sort = document.getElementById('svSort');
            const count = document.getElementById('svCount');
            const cards = Array.from(list.children);

            function apply() {
                const q = (search.value || '').trim().toLowerCase();
                let shown = 0;
                cards.forEach(function(c) {
                    const ok = q === '' || c.dataset.search.indexOf(q) !== -1;
                    c.hidden = !ok;
                    if (ok) shown++;
                });
                const dir = sort.value;
                cards.slice().sort(function(a, b) {
                    if (dir === 'subject') return a.dataset.subject.localeCompare(b.dataset.subject);
                    const d = (+a.dataset.when) - (+b.dataset.when);
                    return dir === 'oldest' ? d : -d;
                }).forEach(function(c) {
                    list.appendChild(c);
                });
                count.textContent = shown === cards.length ?
                    'Showing all ' + cards.length + ' slot' + (cards.length === 1 ? '' : 's') :
                    'Showing ' + shown + ' of ' + cards.length + ' slots';
            }
            search.addEventListener('input', apply);
            sort.addEventListener('change', apply);
            apply();
        })();

        /* ── History: filter by date range ──
           ISO dates, so a plain string compare is the right compare. */
        (function () {
            const list = document.getElementById('hsList');
            if (!list) return;
            const from = document.getElementById('hsFrom');
            const to = document.getElementById('hsTo');
            const clear = document.getElementById('hsClear');
            const count = document.getElementById('hsCount');
            const cards = Array.from(list.children);

            function apply() {
                const a = from.value;
                const b = to.value;
                let shown = 0;
                cards.forEach(function (c) {
                    const dt = c.dataset.date;
                    const ok = (!a || dt >= a) && (!b || dt <= b);
                    c.hidden = !ok;
                    if (ok) shown++;
                });
                clear.hidden = !a && !b;
                if (a && b && a > b) {
                    count.textContent = 'That range ends before it starts.';
                } else if (shown === cards.length) {
                    count.textContent = 'Showing all ' + cards.length + ' past slot' + (cards.length === 1 ? '' : 's');
                } else {
                    count.textContent = 'Showing ' + shown + ' of ' + cards.length + ' past slots';
                }
            }

            from.addEventListener('change', apply);
            to.addEventListener('change', apply);
            clear.addEventListener('click', function () {
                from.value = '';
                to.value = '';
                apply();
            });
            apply();
        })();

        resetForm();
        render();
    </script>
</body>

</html>
