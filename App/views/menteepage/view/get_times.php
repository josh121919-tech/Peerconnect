<?php
include __DIR__ . "/../../db.php";

// A value sent as a list counts as missing.
$mentor_id = $_GET['mentor_id'] ?? '';
$date = is_string($_GET['date'] ?? null) ? $_GET['date'] : '';
$subject = is_string($_GET['subject'] ?? null) ? $_GET['subject'] : '';
$session_type = is_string($_GET['session_type'] ?? null) ? $_GET['session_type'] : '';

if ($mentor_id === '' || $date === '' || $subject === '' || $session_type === '') {
    echo json_encode([]);
    exit;
}

$mentor_id = (int)$mentor_id;

// A mentor who can't be booked (blocked, restricted, unverified) has no times to offer.
if (!UserRepository::isBookableMentor($con, $mentor_id)) {
    echo json_encode([]);
    exit;
}

// Never offers a time that has already gone by, the same rule get_availability.php applies.
$data = [];
foreach (AvailabilityRepository::openStartTimesOn($con, $mentor_id, $date, $subject, $session_type) as $start) {
    $data[] = [
        'time' => date("h:i A", strtotime($start))
    ];
}

echo json_encode($data);
