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
// A mentor who can't be booked (blocked, restricted, unverified) offers no
// slots, the same answer save_booking.php would give on submit.
if ($mentor_id <= 0 || !UserRepository::isBookableMentor($con, $mentor_id)) {
    echo json_encode(['slots' => []]);
    exit;
}

// Slots still in the future, with however many seats are already taken so a
// full 1v1 slot (or a full group) never shows up as bookable.
$slots = [];
foreach (AvailabilityRepository::upcomingWithSeatsTaken($con, $mentor_id, 40) as $r) {
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

echo json_encode(['slots' => $slots]);
