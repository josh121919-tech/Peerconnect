<?php
include __DIR__ . "/../../db.php";

$mentor_id = $_GET['mentor_id'] ?? '';
$date = $_GET['date'] ?? '';
$subject = $_GET['subject'] ?? '';
$session_type = $_GET['session_type'] ?? '';

if ($mentor_id === '' || $date === '' || $subject === '' || $session_type === '') {
    echo json_encode([]);
    exit;
}

$mentor_id = (int)$mentor_id;

$stmt = $con->prepare("
    SELECT
        a.start_time,
        a.capacity,
        COUNT(s.request_id) AS reserved_count
    FROM availability a
    LEFT JOIN session_requests s
        ON s.mentor_id = a.mentor_id
        AND DATE(s.session_date) = a.date
        AND TIME(s.session_date) = a.start_time
        AND s.status IN ('pending','approved')
    WHERE a.mentor_id = ?
      AND a.date = ?
      AND LOWER(a.subject) = LOWER(?)
      AND a.session_type = ?
      -- Same rule get_availability.php already applies: never offer a time
      -- that has already gone by.
      AND CONCAT(a.date, ' ', a.start_time) > NOW()
    GROUP BY a.availability_id, a.start_time, a.capacity
    HAVING (
        (? = '1v1' AND reserved_count = 0)
        OR
        (? = 'group' AND reserved_count < a.capacity)
    )
");
$stmt->bind_param("isssss", $mentor_id, $date, $subject, $session_type, $session_type, $session_type);
$stmt->execute();
$res = $stmt->get_result();

$data = [];

while ($r = $res->fetch_assoc()) {

    $data[] = [
        'time' => date("h:i A", strtotime($r['start_time']))
    ];
}

echo json_encode($data);
