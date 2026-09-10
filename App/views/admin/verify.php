<?php

/**
 * admin/verify.php — retired.
 *
 * Reviewing a new signup is the first thing an admin does to a user, so the
 * queue now lives inside User Management as its "New User" tab rather than on
 * a page of its own. This file stays only so old links, bookmarks and the
 * notification bell keep working.
 *
 * The full previous page is in git history if the standalone view is ever
 * wanted back.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_admin();

header('Location: ' . url('admin-users') . '?tab=pending', true, 301);
exit;
