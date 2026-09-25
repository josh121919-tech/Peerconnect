<?php

/**
 * admin/credits.php — Credits & Developers, for an administrator.
 *
 * The same page a mentor or mentee sees, plus the one thing only an
 * administrator may do: replace somebody's picture. The upload posts to
 * admin/credits_photo.php and comes straight back here.
 *
 * The page itself lives in App/views/includes/credits_ui.php, shared with the
 * member copy so the two cannot drift apart.
 *
 * SECURITY: approved administrators, through require_admin().
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/credits_data.php';
require_admin();

$team      = pc_credits_team($con);
$project   = pc_credits_project($con);
$backUrl   = url('admin-settings');
$canEdit   = true;
$uploadUrl = url('admin-credits-photo');
$csrf      = csrf_token();

// System Settings stays lit in the sidebar: this page hangs off it.
$current_page = 'settings';
include 'layout.php';
?>

<?php include __DIR__ . '/../includes/credits_ui.php'; ?>

<?php include __DIR__ . '/layout_end.php'; ?>
