<?php
session_start();
include __DIR__ . "/../../db.php";
header('Content-Type: application/json');

$mentor_id = (int)($_GET['mentor_id'] ?? 0);
$subject   = is_string($_GET['subject'] ?? null) ? $_GET['subject'] : '';   // a list counts as missing

// A mentor who can't be booked (blocked, restricted, unverified) has no group sessions to offer.
if (!UserRepository::isBookableMentor($con, $mentor_id)) {
    echo json_encode([]);
    exit;
}

echo json_encode(AvailabilityRepository::upcomingGroupSlotsForSubject($con, $mentor_id, $subject));

