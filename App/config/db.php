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

// Pin the connection's clock to PHP's own, which bootstrap.php fixes at
// Asia/Manila.
//
// MySQL's time_zone here is SYSTEM, meaning it follows whatever the operating
// system says. On the XAMPP laptop that is Manila and the two agree to the
// second, which is why nothing ever showed. A shared host runs UTC, and then
// NOW() and date() are eight hours apart — in the same columns, because this
// app writes both: 160 uses of NOW()/CURDATE()/CURRENT_TIMESTAMP() across 39
// files sit alongside PHP-formatted dates. Sessions would be booked into the
// wrong hour, the missed-session detector would close them early or late, and
// the activity log would interleave two clocks, which is the bug already fixed
// once in bootstrap.php.
//
// The offset form ('+08:00') is used rather than a name, because named zones
// need MySQL's timezone tables loaded and a shared host may not have them.
$pc_tz_offset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
mysqli_query($con, "SET time_zone = '" . mysqli_real_escape_string($con, $pc_tz_offset) . "'");
unset($pc_tz_offset);

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
