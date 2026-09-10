<?php
// Open session slots for one mentor, for the Request Mentorship modal.
// Time and duration are the mentor's own published values — a mentee can only
// request a slot the mentor actually opened, which is the same rule
// save_booking.php enforces on submit.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../../db.php";
header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentee') {
    http_response_code(401);
    echo json_encode(['error' => 'Please log in as a mentee.', 'slots' => []]);
    exit;
}

$mentor_id = (int)($_GET['mentor_id'] ?? 0);
if ($mentor_id <= 0) {
    echo json_encode(['slots' => []]);
    exit;
}

// Slots still in the future, with however many seats are already taken so a
// full 1v1 slot (or a full group) never shows up as bookable.
$stmt = $con->prepare("
    SELECT a.date, a.start_time, a.duration, a.session_type, a.subject,
           a.capacity, a.topics,
           (SELECT COUNT(*) FROM session_requests sr
             WHERE sr.mentor_id = a.mentor_id
               AND sr.session_date = CONCAT(a.date, ' ', a.start_time)
               AND sr.status IN ('pending','approved')) AS taken
    FROM availability a
    WHERE a.mentor_id = ?
      AND CONCAT(a.date, ' ', a.start_time) > NOW()
    ORDER BY a.date ASC, a.start_time ASC
    LIMIT 40
");
$stmt->bind_param("i", $mentor_id);
$stmt->execute();
$res = $stmt->get_result();

$slots = [];
while ($r = $res->fetch_assoc()) {
    $capacity = max(1, (int)$r['capacity']);
    $taken    = (int)$r['taken'];
    if ($taken >= $capacity) {
        continue; // already full
    }
    $duration = (int)$r['duration'] ?: 30;
    $start    = strtotime($r['date'] . ' ' . $r['start_time']);
    $slots[] = [
        'date'         => $r['date'],
        'time'         => date('H:i:s', $start),
        'time_label'   => date('g:i A', $start),
        'date_label'   => date('D, M j', $start),
        'end_label'    => date('g:i A', $start + $duration * 60),
        'duration'     => $duration,
        'session_type' => $r['session_type'],
        'subject'      => $r['subject'],
        'topics'       => $r['topics'],
        'seats_left'   => $capacity - $taken,
    ];
}
$stmt->close();

echo json_encode(['slots' => $slots]);
