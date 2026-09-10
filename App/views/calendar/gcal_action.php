<?php
// Sync now / disconnect. Both change state, so both are POST + CSRF.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/GoogleCalendarService.php';

if (empty($_SESSION['user_id'])) {
    header("Location: " . url('welcomepage'));
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$back    = ($_SESSION['role'] ?? '') === 'mentor' ? url('mentor-calendar') : url('mentee-calendar');

function gcal_done(string $type, string $message, string $back): void
{
    pc_flash($type, $message, 'Google Calendar');
    header("Location: " . $back);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    gcal_done('error', 'Security token mismatch. Please refresh and try again.', $back);
}

$action = $_POST['action'] ?? '';

if ($action === 'disconnect') {
    GoogleCalendarService::disconnect($con, $user_id);
    gcal_done('success', 'Google Calendar disconnected. Sessions already in your calendar were left alone.', $back);
}

if ($action === 'sync') {
    $result = GoogleCalendarService::syncAll($con, $user_id);
    if (!$result['ok']) {
        gcal_done('error', 'Sync failed: ' . ($result['error'] ?? 'unknown error'), $back);
    }
    $msg = $result['synced'] . ' session' . ($result['synced'] === 1 ? '' : 's') . ' up to date in Google Calendar';
    if ($result['removed'] > 0) {
        $msg .= ', ' . $result['removed'] . ' removed';
    }
    gcal_done('success', $msg . '.', $back);
}

gcal_done('error', 'Unknown calendar action.', $back);
