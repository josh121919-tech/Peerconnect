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

/**
 * The PDO handle, opened on first use.
 *
 * Two connections used to be opened on every one of the ~130 routes, but only
 * two files ever touch PDO (Messages/get_messages.php and send_message.php).
 * Every other page paid for a connection it never used — invisible at one
 * user, double the connection pressure on MySQL at a hundred.
 */
if (!function_exists('pc_pdo')) {
    function pc_pdo(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $user = $_ENV['DB_USER'] ?? 'root';
        $pass = $_ENV['DB_PASS'] ?? '';
        $name = $_ENV['DB_NAME'] ?? 'cs';

        try {
            $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log("PDO connection failed: " . $e->getMessage());
            die("Service temporarily unavailable.");
        }

        return $pdo;
    }
}

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
