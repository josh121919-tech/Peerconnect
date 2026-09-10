<?php
// "Download My Data" — everything PeerConnect holds about this account, as a
// JSON file they can keep. Read-only; scoped to the signed-in user throughout.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Not signed in.');
}

$user_id = (int)$_SESSION['user_id'];

/** Run a user-scoped query and return its rows. */
function ex_rows(mysqli $con, string $sql, int $id, string $types = 'i', array $extra = []): array
{
    $stmt = $con->prepare($sql);
    $params = array_merge([$id], $extra);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$account = ex_rows($con, "
    SELECT user_id, firstname, middlename, lastname, suffix, email, username, role, verified, status, created_at
    FROM users WHERE user_id = ?
", $user_id)[0] ?? null;

if (!$account) {
    http_response_code(404);
    exit('Account not found.');
}

$export = [
    'exported_at' => date('c'),
    'about'       => 'Everything PeerConnect stores about your account. Generated from Settings → Data Privacy.',
    'account'     => $account,
    'profile'     => ex_rows($con, "SELECT * FROM profile WHERE user_id = ?", $user_id)[0] ?? null,
    'verification' => ex_rows($con, "
        SELECT verification_id, full_name, student_id, course, year_level, club, expertise, status, submitted_at, reviewed_at
        FROM user_verifications WHERE user_id = ?
    ", $user_id),
    'sessions' => ex_rows($con, "
        SELECT sr.request_id, sr.subject, sr.session_date, sr.status, sr.message, sr.rejection_reason, sr.completed_at,
               CONCAT(mentor.firstname,' ',mentor.lastname) AS mentor,
               CONCAT(mentee.firstname,' ',mentee.lastname) AS mentee
        FROM session_requests sr
        JOIN users mentor ON mentor.user_id = sr.mentor_id
        JOIN users mentee ON mentee.user_id = sr.mentee_id
        WHERE sr.mentor_id = ? OR sr.mentee_id = ?
        ORDER BY sr.session_date DESC
    ", $user_id, 'ii', [$user_id]),
    'feedback_given' => ex_rows($con, "
        SELECT feedback_id, session_id, rating, comment, communication, efficiency, knowledge, skill, created_at
        FROM feedback WHERE mentee_id = ? ORDER BY created_at DESC
    ", $user_id),
    'feedback_received' => ex_rows($con, "
        SELECT feedback_id, session_id, rating, comment, created_at
        FROM feedback WHERE mentor_id = ? ORDER BY created_at DESC
    ", $user_id),
    'messages' => ex_rows($con, "
        SELECT id AS message_id, sender_id, receiver_id, content, is_read, created_at
        FROM messages WHERE sender_id = ? OR receiver_id = ?
        ORDER BY created_at DESC
    ", $user_id, 'ii', [$user_id]),
    'assessment_attempts' => ex_rows($con, "
        SELECT at.attempt_id, a.title, at.score, at.total_points, at.status, at.started_at, at.submitted_at
        FROM assessment_attempts at JOIN assessments a ON a.assessment_id = at.assessment_id
        WHERE at.mentee_id = ?
    ", $user_id),
    'resources_uploaded' => ex_rows($con, "
        SELECT resource_id, club, title, description, original_name, file_size, download_count, created_at
        FROM resources WHERE uploader_id = ?
    ", $user_id),
    'notification_preferences' => ex_rows($con, "SELECT * FROM notification_preferences WHERE user_id = ?", $user_id)[0] ?? null,
    'privacy_settings'         => ex_rows($con, "SELECT * FROM privacy_settings WHERE user_id = ?", $user_id)[0] ?? null,
    'activity_log' => ex_rows($con, "
        SELECT activity, log_date FROM logs WHERE email = (SELECT email FROM users WHERE user_id = ?)
        ORDER BY log_date DESC LIMIT 200
    ", $user_id),
];

$json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="peerconnect-my-data-' . $user_id . '.json"');
header('Content-Length: ' . strlen($json));
echo $json;
