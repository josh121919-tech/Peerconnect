<?php
include __DIR__ . "/../../db.php";
require_once __DIR__ . "/../../../services/NotificationService.php";
session_start();
// This file includes db.php before starting its session, so the blocked-
// account gate there could not run. Call it now that the session exists.
pc_enforce_account_status($con);
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentee') {
    http_response_code(401);
    echo json_encode(["error" => "Please log in to book a session.", "auth_required" => true]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

// SECURITY: CSRF validation for JSON endpoint (token sent in request body)
$csrfSubmitted = $data['csrf_token'] ?? '';
$csrfExpected  = $_SESSION['csrf_token'] ?? '';
if ($csrfExpected === '' || !hash_equals($csrfExpected, $csrfSubmitted)) {
    http_response_code(403);
    echo json_encode(["error" => "Security token mismatch. Please refresh and try again."]);
    exit;
}

$mentor_id = (int)$data['mentor_id'];
$mentee_id = (int)$_SESSION['user_id'];
$subject = $data['subject'] ?? '';
$session_type = $data['session_type'] ?? '';
$date = $data['date'] ?? '';
$time = date("H:i:s", strtotime($data['time']));
// Optional note from the mentee — sent by the Request Mentorship modal on
// Find a Mentor and by the Notes box in the profile booking modal.
$message = trim(strip_tags((string)($data['message'] ?? '')));
if (mb_strlen($message) > 500) {
    $message = mb_substr($message, 0, 500);
}

$datetime = $date . " " . $time;

// =====================================
// 🔥 1. PREVENT DUPLICATE (same user)
// =====================================
$checkStmt = $con->prepare("
    SELECT 1 FROM session_requests
    WHERE mentor_id = ?
    AND mentee_id = ?
    AND session_date = ?
    AND status IN ('pending','approved')
");
$checkStmt->bind_param("iis", $mentor_id, $mentee_id, $datetime);
$checkStmt->execute();
$check = $checkStmt->get_result()->num_rows;
$checkStmt->close();

if ($check > 0) {
    echo json_encode(["error" => "You already booked this session."]);
    exit;
}

/*
 * The weekly booking cap from System Settings → General. 0 means no limit,
 * which is the default. Counted over live bookings in the last seven days —
 * a cancelled session should not use up someone's allowance.
 */
$weekCap = pc_setting_int($con, 'booking_limit_week', 0);
if ($weekCap > 0) {
    $wk = $con->prepare("
        SELECT COUNT(*) c FROM session_requests
        WHERE mentee_id = ? AND status IN ('pending','approved','completed')
          AND session_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $wk->bind_param("i", $mentee_id);
    $wk->execute();
    $thisWeek = (int)($wk->get_result()->fetch_assoc()['c'] ?? 0);
    $wk->close();

    if ($thisWeek >= $weekCap) {
        echo json_encode(["error" =>
            "You have reached the limit of {$weekCap} session" . ($weekCap === 1 ? '' : 's') .
            " a week. Try again once one of your current sessions has passed."]);
        exit;
    }
}

// =====================================
// 🔥 2. GET SLOT INFO (type + capacity)
// =====================================
$slotStmt = $con->prepare("
    SELECT session_type, capacity
    FROM availability
    WHERE mentor_id = ?
    AND date = ?
    AND start_time = ?
    AND LOWER(subject) = LOWER(?)
    AND session_type = ?
");
$slotStmt->bind_param("issss", $mentor_id, $date, $time, $subject, $session_type);
$slotStmt->execute();
$slot = $slotStmt->get_result()->fetch_assoc();
$slotStmt->close();

if (!$slot) {
    echo json_encode(["error" => "Slot not found"]);
    exit;
}

// =====================================
// 🔥 2b. REJECT SLOTS IN THE PAST
// =====================================
// get_availability.php hides past slots, but get_times.php did not and this
// endpoint never checked, so a booking could land on a date that had already
// gone by. The comparison runs in SQL because PHP's clock is on a different
// timezone to the database (Europe/Berlin vs Asia/Manila).
$pastStmt = $con->prepare("SELECT ? <= NOW() AS is_past");
$pastStmt->bind_param("s", $datetime);
$pastStmt->execute();
$isPast = (int)($pastStmt->get_result()->fetch_assoc()['is_past'] ?? 0);
$pastStmt->close();

if ($isPast) {
    echo json_encode(["error" => "That time has already passed. Please pick a later slot."]);
    exit;
}

// =====================================
// 🔥 2c. PREVENT A CLASH WITH THE MENTEE'S OWN DIARY
// =====================================
// The duplicate check above only catches the *same mentor* at the same time.
// Nothing stopped a mentee booking two different mentors for the same slot,
// so they could end up owing two people their attendance at once — and
// whichever one they missed counted against that mentor's score.
//
// Compared as real intervals rather than equal start times: a 60-minute
// session at 3:30 and a 30-minute one at 4:00 overlap without starting
// together. session_requests stores only the start, so the length of an
// existing booking comes from its slot, defaulting to 60 minutes when the
// slot has since been deleted (the same default feedback/save.php uses).
$newDur = max(1, (int)($slot['duration'] ?? 60));
$clashStmt = $con->prepare("
    SELECT sr.session_date,
           sr.subject,
           COALESCE(a.duration, 60) AS dur,
           CONCAT(u.firstname, ' ', u.lastname) AS mentor_name
    FROM session_requests sr
    JOIN users u ON u.user_id = sr.mentor_id
    LEFT JOIN availability a
           ON  a.mentor_id        = sr.mentor_id
           AND LOWER(a.subject)   = LOWER(sr.subject)
           AND a.date             = DATE(sr.session_date)
           AND a.start_time       = TIME(sr.session_date)
    WHERE sr.mentee_id = ?
      AND sr.status IN ('pending','approved')
      AND DATE(sr.session_date) = ?
      AND sr.session_date < DATE_ADD(?, INTERVAL ? MINUTE)
      AND DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) > ?
    ORDER BY sr.session_date
    LIMIT 1
");
$clashStmt->bind_param("issis", $mentee_id, $date, $datetime, $newDur, $datetime);
$clashStmt->execute();
$clash = $clashStmt->get_result()->fetch_assoc();
$clashStmt->close();

if ($clash) {
    $clashWhen = (new DateTime($clash['session_date'], new DateTimeZone('Asia/Manila')))->format('g:i A');
    echo json_encode(["error" =>
        "That clashes with your " . $clash['subject'] . " session with " . $clash['mentor_name'] .
        " at " . $clashWhen . " the same day. Cancel that one first, or pick another time."]);
    exit;
}

// =====================================
// 🔥 3. PREVENT DUPLICATE GROUP RESERVATION (same date+subject)
// =====================================
if ($session_type === 'group') {
    $groupStmt = $con->prepare("
        SELECT 1
        FROM session_requests
        WHERE mentee_id = ?
          AND mentor_id = ?
          AND LOWER(subject) = LOWER(?)
          AND session_date = ?
          AND status IN ('pending', 'approved')
        LIMIT 1
    ");
    $groupStmt->bind_param("iiss", $mentee_id, $mentor_id, $subject, $datetime);
    $groupStmt->execute();
    $groupCheck = $groupStmt->get_result()->num_rows;
    $groupStmt->close();

    if ($groupCheck > 0) {
        echo json_encode(['error' => 'You already have a reservation for this session.']);
        exit;
    }
}

// =====================================
// 🔥 4. COUNT CURRENT BOOKINGS
// =====================================
$countStmt = $con->prepare("
    SELECT COUNT(*) as total
    FROM session_requests
    WHERE mentor_id = ?
    AND session_date = ?
    AND status IN ('pending','approved')
");
$countStmt->bind_param("is", $mentor_id, $datetime);
$countStmt->execute();
$count = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

// =====================================
// 🔥 5. CHECK CAPACITY
// =====================================
if ($count >= $slot['capacity']) {
    echo json_encode(["error" => "This session is already full"]);
    exit;
}

// =====================================
// 🔥 6. INSERT BOOKING
// =====================================
$insertStmt = $con->prepare("
    INSERT INTO session_requests (
        mentor_id, mentee_id, subject, session_date, message, status
    ) VALUES (
        ?, ?, ?, ?, ?, 'pending'
    )
");
$insertStmt->bind_param("iisss", $mentor_id, $mentee_id, $subject, $datetime, $message);
$insertStmt->execute();
$insertStmt->close();

// =====================================
// 🔥 7. TELL THE MENTOR
// =====================================
// Every other transition in this flow notifies (approve, reject, cancel,
// missed). The request that starts it did not, so a mentor only discovered
// new bookings by opening the requests page.
$whoStmt = $con->prepare("SELECT CONCAT(firstname,' ',lastname) AS name FROM users WHERE user_id = ?");
$whoStmt->bind_param("i", $mentee_id);
$whoStmt->execute();
$menteeName = $whoStmt->get_result()->fetch_assoc()['name'] ?? 'A mentee';
$whoStmt->close();

$when = (new DateTime($datetime, new DateTimeZone('Asia/Manila')))->format('M j, g:i A');
NotificationService::send(
    $con,
    $mentor_id,
    'session_requested',
    'New Session Request',
    $menteeName . ' requested a ' . $subject . ' session on ' . $when . '.',
    url('mentor-requests')
);

echo json_encode(["success" => true]);
