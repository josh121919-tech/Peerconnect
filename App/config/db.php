<?php

require_once __DIR__ . '/../../Framework/bootstrap.php';

$db_host = $_ENV['DB_HOST'] ?? 'localhost';
$db_user = $_ENV['DB_USER'] ?? 'root';
$db_pass = $_ENV['DB_PASS'] ?? '';
$db_name = $_ENV['DB_NAME'] ?? 'cs';

$con = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$con) {
    die("Service temporarily unavailable.");
}

mysqli_set_charset($con, 'utf8mb4');

// Every authenticated page reaches this file, and by the time it does its
// session is already started (the handful that include it first are the
// signed-out auth pages). Enforcing here means one call site instead of
// ninety-odd, and no page can forget it.
require_once BASE_PATH . '/App/views/includes/settings_store.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    pc_enforce_account_status($con);
}

// Maintenance mode, from System Settings. Admins and the auth pages are
// exempt, so turning it on cannot lock out the person who has to turn it off.
pc_maintenance_gate($con);
