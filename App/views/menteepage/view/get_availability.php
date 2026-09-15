<?php
include __DIR__ . "/../../db.php";

$mentor_id    = (int) ($_GET['mentor_id']    ?? 0);
// A value sent as a list counts as missing.
$subject      = is_string($_GET['subject'] ?? null)      ? $_GET['subject']      : '';
$session_type = is_string($_GET['session_type'] ?? null) ? $_GET['session_type'] : '';

// A mentor who can't be booked (blocked, restricted, unverified) has no dates to offer.
if ($mentor_id <= 0 || $subject === '' || $session_type === '' || !UserRepository::isBookableMentor($con, $mentor_id)) {
    echo json_encode([]);
    exit;
}

// Only return slots whose date+time hasn't passed yet
echo json_encode(AvailabilityRepository::upcomingForSubjectAndType($con, $mentor_id, $subject, $session_type));
