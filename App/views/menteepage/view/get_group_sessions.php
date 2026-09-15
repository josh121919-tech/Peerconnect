<?php
session_start();
include __DIR__ . "/../../db.php";
header('Content-Type: application/json');

$mentor_id = (int)($_GET['mentor_id'] ?? 0);
$subject   = $_GET['subject'] ?? '';

// A mentor who can't be booked (blocked, restricted, unverified) has no group sessions to offer.
if (!UserRepository::isBookableMentor($con, $mentor_id)) {
    echo json_encode([]);
    exit;
}

$stmt = $con->prepare("
    SELECT
        a.availability_id,
        a.subject,
        a.capacity,
        DATE(a.date)                              AS session_date,
        TIME_FORMAT(a.start_time, '%h:%i %p')    AS start_time,
        a.duration,
        COUNT(sr.request_id)                      AS reserved_count
    FROM availability a
    LEFT JOIN session_requests sr
        ON  sr.mentor_id    = a.mentor_id
        AND sr.subject      = a.subject
        AND DATE(sr.session_date) = DATE(a.date)
        AND TIME(sr.session_date) = a.start_time
        AND sr.status IN ('pending', 'approved')
    WHERE a.mentor_id    = ?
      AND a.subject      = ?
      AND a.session_type = 'group'
      AND CONCAT(a.date, ' ', a.start_time) > NOW()
    GROUP BY a.availability_id
    ORDER BY a.date, a.start_time
");

$stmt->bind_param("is", $mentor_id, $subject);
$stmt->execute();
$result = $stmt->get_result();

$sessions = [];
while ($row = $result->fetch_assoc()) {
    $sessions[] = $row;
}

echo json_encode($sessions);

