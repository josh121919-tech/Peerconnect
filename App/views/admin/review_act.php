<?php

/**
 * admin-review-act — carries out the decision the owner made from their email.
 *
 * POST only, and the signed query from the email travels in the form rather
 * than the address bar: a decision must never be something a link can cause.
 * Everything is re-checked here — the signature, the expiry, that the
 * applicant really is an administrator and that the application is still
 * pending. The page that posted here proved none of it on this request's
 * behalf.
 *
 * There is no session and no CSRF token, because there is no session to
 * protect: the signature is the whole of the authorisation. That is also why
 * it stops working the moment the application is decided.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../../services/AdminReviewLink.php';
require_once __DIR__ . '/../../services/NotificationService.php';

$done = function (string $title, string $message, bool $bad = false) use ($con): void {
    http_response_code($bad ? 400 : 200);
    $platform = pc_setting($con, 'platform_name');
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars($platform) ?></title>
        <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    </head>

    <body style="background:var(--bg);">
        <div style="max-width:560px;margin:0 auto;padding:48px 16px;">
            <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:28px;">
                <h1 style="margin:0 0 8px;font-size:19px;color:<?= $bad ? 'var(--danger)' : 'var(--navy)' ?>;">
                    <?= htmlspecialchars($title) ?>
                </h1>
                <p style="margin:0;font-size:13.5px;line-height:1.65;color:var(--gray-600);">
                    <?= htmlspecialchars($message) ?>
                </p>
            </div>
        </div>
    </body>

    </html>
    <?php
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $done('That did not work', 'This address only accepts a decision made from the review page.', true);
}

$check = AdminReviewLink::check($_POST);
if (!$check['ok']) {
    $done('That link cannot be used', $check['error'], true);
}

$decision = is_string($_POST['decision'] ?? null) ? $_POST['decision'] : '';
if (!in_array($decision, ['approve', 'reject'], true)) {
    $done('That did not work', 'No decision was given.', true);
}

$app = VerificationRepository::byId($con, $check['id']);
if ($app === null) {
    $done('Nothing to decide', 'That application no longer exists.', true);
}

$uid = (int)$app['user_id'];
if (UserRepository::role($con, $uid) !== 'admin') {
    $done('Nothing to decide', 'That application is not an administrator application.', true);
}
if (($app['status'] ?? '') !== 'pending') {
    $done('Already decided', 'That application was already ' . (string)$app['status'] . ', so nothing changed.', true);
}

$notes = is_string($_POST['notes'] ?? null) ? trim(mb_substr($_POST['notes'], 0, 500)) : '';
$status = $decision === 'approve' ? 'approved' : 'rejected';

// decide() writes only to a row that is still pending, so two clicks on the
// same email cannot approve and then reject the same person.
if (VerificationRepository::decide($con, $check['id'], $status, $notes) < 1) {
    $done('Already decided', 'Somebody got there first, so nothing changed.', true);
}

if ($decision === 'approve') {
    UserRepository::markVerified($con, $uid);
    NotificationService::verificationApproved($con, $uid, url('admin-dashboard'));
    // pc_admin_log() names the signed-in admin, and there is no session here
    // by design. The owner's address is who acted, so say that.
    logMe(pc_setting($con, 'owner_email'), date('Y-m-d H:i:s'),
        'admin approved the administrator application of ' . pc_user_name($con, $uid));
    $done(
        'Approved',
        pc_user_name($con, $uid) . ' can now sign in and use the administrator dashboard. '
            . 'The links in that email have stopped working.'
    );
}

NotificationService::verificationRejected($con, $uid, $notes, url('admin-verification'));
logMe(pc_setting($con, 'owner_email'), date('Y-m-d H:i:s'),
    'admin rejected the administrator application of ' . pc_user_name($con, $uid));
$done(
    'Rejected',
    pc_user_name($con, $uid) . ' has been told, with your reason if you gave one. They can correct '
        . 'their details and apply again. The links in that email have stopped working.'
);
