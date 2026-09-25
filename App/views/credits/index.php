<?php

/**
 * credits/index.php — Credits & Developers, for a mentor or a mentee.
 *
 * Opened from their Settings page. Read-only: the pictures are changed by an
 * administrator on the admin copy of this page, so there is nothing to submit
 * here and nothing is drawn that would suggest otherwise.
 *
 * The page itself lives in App/views/includes/credits_ui.php, shared with the
 * administrator's copy so the two cannot drift apart.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/credits_data.php';

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['mentee', 'mentor'], true)) {
    header('Location: ' . url('welcomepage'));
    exit;
}

$team    = pc_credits_team($con);
$project = pc_credits_project($con);
$backUrl = url($role === 'mentor' ? 'mentor-settings' : 'mentee-settings');
$canEdit = false;

// The sidebar keeps Settings lit: this page is part of that section, and
// there is no menu item of its own to highlight.
$active_page = 'settings';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credits &amp; Developers — <?= htmlspecialchars($project['name']) ?></title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/../includes/app_shell.php'; ?>

        <main class="main fade-in">
            <?php include __DIR__ . '/../includes/credits_ui.php'; ?>
        </main>
    </div>
</body>

</html>
