<?php
include __DIR__ . "/../../db.php";

$mentor_id    = (int) ($_GET['mentor_id']    ?? 0);
$subject      = $_GET['subject']             ?? '';
$session_type = $_GET['session_type']        ?? '';

if ($mentor_id <= 0 || $subject === '' || $session_type === '') {
    echo json_encode([]);
    exit;
}

// Only return slots whose date+time hasn't passed yet
$stmt = $con->prepare("
    SELECT date, about, topics, start_time, duration
    FROM availability
    WHERE mentor_id      = ?
      AND subject        = ?
      AND session_type   = ?
      AND CONCAT(date, ' ', start_time) > NOW()
    ORDER BY date, start_time
");
$stmt->bind_param("iss", $mentor_id, $subject, $session_type);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($r = $res->fetch_assoc()) {
    $data[] = $r;
}

echo json_encode($data);
