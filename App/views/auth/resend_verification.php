<?php

/**
 * resend_verification.php — sends the confirmation letter again.
 *
 * POST only and CSRF-checked: a GET that sends mail is a link anyone can put
 * in a page to make this server post letters at somebody. The throttle inside
 * EmailVerificationService is the second line — three per quarter of an hour,
 * counted per account — so a held-down button costs one letter, not thirty.
 *
 * Always comes back to email_pending.php with something to read. A button
 * that appears to do nothing gets pressed again.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../../services/EmailVerificationService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$back = url('email-pending');

$say = function (string $message, bool $ok) use ($back): void {
    $_SESSION['ev_notice']    = $message;
    $_SESSION['ev_notice_ok'] = $ok;
    header('Location: ' . $back);
    exit;
};

if (empty($_SESSION['user_id'])) {
    header('Location: ' . url('login'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    $say('Your session expired before that went through. Please try again.', false);
}

$user_id = (int)$_SESSION['user_id'];
$state   = UserRepository::signInState($con, $user_id);

if (!$state) {
    $_SESSION = [];
    header('Location: ' . url('login'));
    exit;
}

// Nothing to resend: confirmed already, or the stage does not apply here.
// email_pending.php will move them along on arrival.
if (!EmailVerificationService::pendingFor($con, (string)$state['role'], $state['email_verified_at'] ?? null)) {
    $say('That address is already confirmed.', true);
}

$result = EmailVerificationService::send($con, $user_id);

if ($result['sent']) {
    $address = (string)(UserRepository::email($con, $user_id) ?? '');
    $say('A new confirmation link is on its way to ' . $address
        . '. The previous link no longer works.', true);
}

if ($result['throttled']) {
    $say('We have already sent several links in the last few minutes. '
        . 'Please wait a little, then check your inbox and spam folder.', false);
}

if ($result['skipped']) {
    // Mail was switched off between the page rendering and this POST.
    $say('Email confirmation is switched off on this site, so there is nothing to send. '
        . 'Reload this page to continue.', true);
}

$say('We could not send the email just now. Please try again in a few minutes.', false);
