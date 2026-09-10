<?php
// Where Google sends the user back after they approve (or refuse) access.
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

function gcal_back(string $type, string $message, string $back): void
{
    pc_flash($type, $message, 'Google Calendar');
    header("Location: " . $back);
    exit;
}

// The user can decline on Google's screen. "access_denied" also comes back when
// the Google project is still in Testing and this account isn't a test user, so
// the message names that cause rather than just saying "declined".
if (isset($_GET['error'])) {
    $reason = (string)$_GET['error'];
    $message = $reason === 'access_denied'
        ? 'Google Calendar was not connected. If Google said the app "has not completed the Google verification process", '
            . 'the account needs to be added under Test users on the OAuth consent screen in Google Cloud Console. '
            . 'Otherwise, access was simply declined — you can try again any time.'
        : 'Google Calendar was not connected (' . htmlspecialchars($reason) . ').';
    gcal_back('error', $message, $back);
}

$expected = $_SESSION['gcal_state'] ?? '';
unset($_SESSION['gcal_state']);
if ($expected === '' || !hash_equals($expected, (string)($_GET['state'] ?? ''))) {
    gcal_back('error', 'That Google response did not match this session. Please try connecting again.', $back);
}

if (empty($_GET['code'])) {
    gcal_back('error', 'Google did not return an authorisation code. Please try again.', $back);
}

$client = GoogleCalendarService::client();

try {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
} catch (Throwable $e) {
    gcal_back('error', 'Could not complete the Google connection: ' . $e->getMessage(), $back);
}

if (isset($token['error'])) {
    gcal_back('error', 'Google rejected the connection: ' . ($token['error_description'] ?? $token['error']), $back);
}

// Which Google account did they pick? Shown on the card so they can tell.
$email = null;
try {
    $client->setAccessToken($token);
    $oauth = new Google\Service\Oauth2($client);
    $email = $oauth->userinfo->get()->email ?? null;
} catch (Throwable $e) {
    // Not fatal — the connection works without knowing the address.
}

GoogleCalendarService::saveToken($con, $user_id, $token, $email);

// Push everything approved straight away, so the calendar is populated by the
// time they land back on the page.
$result = GoogleCalendarService::syncAll($con, $user_id);

if (!$result['ok']) {
    gcal_back('error', 'Connected, but the first sync failed: ' . ($result['error'] ?? 'unknown error'), $back);
}

gcal_back(
    'success',
    'Google Calendar connected' . ($email ? ' as ' . $email : '') . ' — ' .
        $result['synced'] . ' session' . ($result['synced'] === 1 ? '' : 's') . ' added to your calendar.',
    $back
);
