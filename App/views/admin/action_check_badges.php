<?php
/**
 * action_check_badges.php
 * Runs all automatic badge-award rules against all active mentors.
 * Also refreshes mentor_scores table.
 */
session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/MentorScoreService.php';
require_admin();
require_post();

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); exit('CSRF mismatch.');
}

// Refresh all mentor scores first
MentorScoreService::refreshAll($con);

// Load all auto-awardable badges
$badges_q = $con->query("SELECT * FROM badges WHERE criteria_type != 'manual' AND is_active=1");
$badges   = $badges_q->fetch_all(MYSQLI_ASSOC);

// Load all active mentors with their stats
$mentors_q = $con->query("
    SELECT u.user_id,
           COALESCE(ms.total_sessions, 0) as completed,
           COALESCE(ms.avg_rating, 0)     as avg_rating
    FROM users u
    LEFT JOIN mentor_scores ms ON ms.mentor_id = u.user_id
    WHERE u.role='mentor' AND u.status='active'
");
$mentors = $mentors_q->fetch_all(MYSQLI_ASSOC);

$awarded = 0;

foreach ($mentors as $m) {
    $uid = (int)$m['user_id'];

    foreach ($badges as $b) {
        $bid = (int)$b['badge_id'];
        $qualifies = false;

        switch ($b['criteria_type']) {
            case 'sessions_completed':
                $qualifies = (int)$m['completed'] >= (int)$b['criteria_value'];
                break;
            case 'avg_rating':
                // criteria_value stored as int × 10 (e.g., 48 = 4.8)
                $threshold = (int)$b['criteria_value'] / 10.0;
                $qualifies = (float)$m['avg_rating'] >= $threshold && (int)$m['completed'] >= 5;
                break;
            case 'community':
                // community badges are manual-only; skip
                $qualifies = false;
                break;
        }

        if ($qualifies) {
            // INSERT IGNORE prevents duplicate awards
            $ins = $con->prepare("INSERT IGNORE INTO user_badges (user_id, badge_id, awarded_by, awarded_at) VALUES (?, ?, NULL, NOW())");
            $ins->bind_param("ii", $uid, $bid);
            $ins->execute();
            if ($ins->affected_rows > 0) {
                NotificationService::badgeAwarded($con, $uid, $b['name'], url('mentor-profile'));
                $awarded++;
            }
            $ins->close();
        }
    }
}

header('Location: ' . url('admin-badges') . '?msg=checked&awarded=' . $awarded);
exit;
