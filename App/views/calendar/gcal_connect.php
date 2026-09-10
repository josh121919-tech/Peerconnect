<?php
// Start the Google Calendar authorisation flow.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/GoogleCalendarService.php';

if (empty($_SESSION['user_id'])) {
    header("Location: " . url('welcomepage'));
    exit;
}

$back = ($_SESSION['role'] ?? '') === 'mentor' ? url('mentor-calendar') : url('mentee-calendar');

// Settings → Data Privacy → "Allow third-party integrations". Off means no
// Google connection may be made.
$allowed = (int)($con->query("
    SELECT COALESCE(MAX(third_party_integrations), 1) v FROM privacy_settings
    WHERE user_id = " . (int)$_SESSION['user_id']
)->fetch_assoc()['v'] ?? 1) === 1;

if (!$allowed) {
    pc_flash('error', 'Third-party integrations are turned off in Settings → Data Privacy. Turn them back on to connect Google Calendar.', 'Google Calendar');
    header("Location: " . $back);
    exit;
}

if (!GoogleCalendarService::isConfigured()) {
    pc_flash('error', 'Google Calendar is not configured yet — GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET are missing from .env.', 'Google Calendar');
    header("Location: " . $back);
    exit;
}

// State ties the callback back to this session, so someone else's redirect
// can't attach their Google account to this login.
$state = bin2hex(random_bytes(16));
$_SESSION['gcal_state'] = $state;

$client = GoogleCalendarService::client();
$client->setState($state);

header("Location: " . $client->createAuthUrl());
exit;
