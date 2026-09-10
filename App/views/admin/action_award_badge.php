<?php

/**
 * action_award_badge.php — give a badge to a mentor, or take one back.
 *
 * Separate from action_badge.php, which edits the badge itself: this one
 * touches `user_badges` and notifies the person, so it is the handler with a
 * side effect on someone other than the admin.
 *
 * SECURITY: admin-only, POST-only, CSRF-checked, prepared statements.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_admin();
require_post();

$expected = $_SESSION['csrf_token'] ?? '';
$given    = $_POST['csrf_token'] ?? '';
if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    exit('CSRF token mismatch.');
}

date_default_timezone_set('Asia/Manila');

/* `back` comes from a form field, so it is untrusted: only a path on this host. */
$back = (string)($_POST['back'] ?? '');
if ($back === '' || !preg_match('#^/[A-Za-z0-9/_\-?=&.%]*$#', $back) || str_starts_with($back, '//')) {
    $back = url('admin-badges');
}

$do       = (string)($_POST['do'] ?? 'award');
$admin_id = (int)($_SESSION['user_id'] ?? 0);

/* ── Take a badge back ─────────────────────────────────────────────────── */
if ($do === 'revoke') {
    $awardId = (int)($_POST['user_badge_id'] ?? 0);

    $q = $con->prepare("
        SELECT ub.user_badge_id, b.name AS badge_name,
               CONCAT_WS(' ', u.firstname, u.lastname) AS person
          FROM user_badges ub
          JOIN badges b ON b.badge_id = ub.badge_id
          JOIN users  u ON u.user_id  = ub.user_id
         WHERE ub.user_badge_id = ?
    ");
    $q->bind_param('i', $awardId);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$row) {
        pc_flash('error', 'That award no longer exists.', 'Nothing changed');
        header('Location: ' . $back);
        exit;
    }

    $d = $con->prepare("DELETE FROM user_badges WHERE user_badge_id = ?");
    $d->bind_param('i', $awardId);
    $d->execute();
    $d->close();

    logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'),
        'admin revoked badge "' . $row['badge_name'] . '" from ' . $row['person']);

    // Deliberately silent toward the recipient: telling someone a badge has
    // been taken away is a message an admin should choose to send, not one
    // this handler fires off on its own.
    pc_flash('success',
        'The ' . $row['badge_name'] . ' badge has been taken back from ' . $row['person']
        . '. They have not been notified.',
        'Award removed');

    header('Location: ' . $back);
    exit;
}

/* ── Award ─────────────────────────────────────────────────────────────── */
$user_id  = (int)($_POST['user_id']  ?? 0);
$badge_id = (int)($_POST['badge_id'] ?? 0);

if (!$user_id || !$badge_id) {
    pc_flash('error', 'Choose both a mentor and a badge.', 'Not awarded');
    header('Location: ' . $back);
    exit;
}

$bq = $con->prepare("SELECT name, is_active FROM badges WHERE badge_id = ?");
$bq->bind_param('i', $badge_id);
$bq->execute();
$badge = $bq->get_result()->fetch_assoc();
$bq->close();

$uq = $con->prepare("SELECT CONCAT_WS(' ', firstname, lastname) AS name FROM users WHERE user_id = ?");
$uq->bind_param('i', $user_id);
$uq->execute();
$person = $uq->get_result()->fetch_assoc();
$uq->close();

if (!$badge || !$person) {
    pc_flash('error', 'That mentor or badge no longer exists.', 'Not awarded');
    header('Location: ' . $back);
    exit;
}

// INSERT IGNORE means a second award is a no-op rather than a duplicate row —
// but then affected_rows is what tells us whether to notify. Sending "you
// earned a badge" to someone who has held it for a month is worse than saying
// nothing.
$stmt = $con->prepare("INSERT IGNORE INTO user_badges (user_id, badge_id, awarded_by, awarded_at) VALUES (?, ?, ?, NOW())");
$stmt->bind_param('iii', $user_id, $badge_id, $admin_id);
$stmt->execute();
$inserted = $stmt->affected_rows > 0;
$stmt->close();

if (!$inserted) {
    pc_flash('info', $person['name'] . ' already holds the ' . $badge['name'] . ' badge, so nothing changed.', 'Already awarded');
    header('Location: ' . $back);
    exit;
}

NotificationService::badgeAwarded($con, $user_id, $badge['name'], url('mentor-profile'));

logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'),
    'admin awarded badge "' . $badge['name'] . '" to ' . $person['name']);

pc_flash('success',
    $person['name'] . ' has been given the ' . $badge['name'] . ' badge and notified.'
    . ((int)$badge['is_active'] === 0 ? ' Note this badge is switched off, so it will not show on their profile until you switch it back on.' : ''),
    'Badge awarded');

header('Location: ' . $back);
exit;
