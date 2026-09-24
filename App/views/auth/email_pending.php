<?php

/**
 * email_pending.php — where a signed-in account waits until it has confirmed
 * the address it registered with.
 *
 * pc_verification_gate() sends people here and keeps them here. The page's
 * job is to make sure nobody is stuck: it says which address the letter went
 * to, offers to send another, and links to the one place the address can be
 * corrected if it was typed wrong.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../../services/EmailVerificationService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ' . url('login'));
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$state   = UserRepository::signInState($con, $user_id);

if (!$state) {
    // The account went while the session lived on.
    $_SESSION = [];
    header('Location: ' . url('login'));
    exit;
}

$role = (string)$state['role'];

/*
 * Confirmed while this page was open — in another tab, or on the phone the
 * mail was read on — so there is nothing to wait for. Also covers an install
 * where the stage does not apply at all: pendingFor() answers false and the
 * visitor is moved along rather than parked on a page about a letter that was
 * never sent.
 */
if (!EmailVerificationService::pendingFor($con, $role, $state['email_verified_at'] ?? null)) {
    header('Location: ' . SignInService::destination(
        $role,
        $state['status'],
        $state['verified'],
        $con,
        $state['email_verified_at'] ?? null
    ));
    exit;
}

$address = (string)(UserRepository::email($con, $user_id) ?? ($_SESSION['email'] ?? ''));

// Set by resend_verification.php, which does the sending and comes back here.
$notice = '';
$notice_ok = true;
if (isset($_SESSION['ev_notice'])) {
    $notice    = (string)$_SESSION['ev_notice'];
    $notice_ok = !empty($_SESSION['ev_notice_ok']);
    unset($_SESSION['ev_notice'], $_SESSION['ev_notice_ok']);
}

$csrf         = csrf_token();
$hours        = (int)round(EmailVerificationService::TTL_MINUTES / 60);
$hero_art     = 'images/background.png';
$has_hero_art = is_file(PUBLIC_PATH . '/' . $hero_art);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm your email — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <?php require_once __DIR__ . '/auth_styles.php'; ?>
</head>

<body class="auth-body">
    <main class="auth-split">
        <!-- ── Story panel ── -->
        <section class="auth-aside" aria-hidden="true">
            <div class="auth-deco" aria-hidden="true">
                <span class="auth-blob auth-blob-1"></span>
                <span class="auth-blob auth-blob-2"></span>
                <span class="auth-dots auth-dots-1"></span>
                <span class="auth-dots auth-dots-2"></span>
            </div>

            <div class="auth-aside-inner">
                <h2 class="auth-aside-h">One letter away<br>from <em>getting started</em>.</h2>
                <p class="auth-aside-p">
                    We sent a confirmation link to the address you registered with.
                    Open it and you can carry on where you left off.
                </p>
                <?php if ($has_hero_art): ?>
                    <div class="auth-art">
                        <img class="auth-aside-art" src="<?= htmlspecialchars(asset($hero_art)) ?>" alt="" width="1536" height="1024">

                        <div class="auth-float auth-float-1">
                            <span class="auth-float-ico" style="background:var(--mint-faint);color:var(--mint-deep);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m3.8 7 8.2 6 8.2-6" />
                                </svg>
                            </span>
                            <span>
                                <span class="auth-float-t">Check Your Inbox</span>
                                <span class="auth-float-s">And your spam folder</span>
                            </span>
                        </div>

                        <div class="auth-float auth-float-2">
                            <span class="auth-float-ico" style="background:var(--info-bg);color:var(--info);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <circle cx="12" cy="12" r="8.5" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5V12l3 1.8" />
                                </svg>
                            </span>
                            <span>
                                <span class="auth-float-t">Valid for <?= $hours ?> Hours</span>
                                <span class="auth-float-s">Then ask for another</span>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ── Panel ── -->
        <section class="auth-panel">
            <div class="auth-card">
                <div class="auth-top">
                    <a class="auth-brand" href="<?= htmlspecialchars(url('welcomepage')) ?>">
                        <?php pc_brand_mark('auth-brand-svg'); ?>
                        <span class="auth-brand-txt">
                            <span class="auth-brand-name">PEER<em>CONNECT</em></span>
                            <span class="auth-brand-tag">Mentoring. Growing. Together.</span>
                        </span>
                    </a>
                </div>

                <span class="auth-icon-badge" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="m3.8 7 8.2 6 8.2-6" />
                    </svg>
                </span>

                <h1 class="auth-h1">Confirm your email</h1>
                <p class="auth-sub">
                    We sent a link to <strong><?= htmlspecialchars($address) ?></strong>.
                    Open it to finish setting up your account — then an administrator
                    reviews your verification documents before you get full access.
                </p>

                <?php if ($notice !== ''): ?>
                    <div class="auth-alert <?= $notice_ok ? 'auth-ok' : '' ?>" role="status">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8 12.3 2.7 2.7L16 9.7" />
                        </svg>
                        <span><?= htmlspecialchars($notice) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Already clicked it elsewhere? Reloading this page is enough:
                     the check at the top moves them on the moment it is done. -->
                <a class="auth-submit auth-submit-link" href="<?= htmlspecialchars(url('email-pending')) ?>">
                    I have confirmed it — continue
                </a>

                <form method="POST" action="<?= htmlspecialchars(url('resend-verification')) ?>" style="margin-top:12px;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <button class="auth-submit auth-submit-ghost" type="submit" name="resend" value="1">
                        Send the link again
                    </button>
                </form>

                <?php // Its own page, not Settings: an account that has not proved
                      // its address should not be inside the member shell. ?>
                <p class="auth-switch" style="margin-top:18px;">
                    Wrong address?
                    <a href="<?= htmlspecialchars(url('change-email')) ?>">Change Email Address</a>
                </p>

                <!-- Signs out on the way, so it actually reaches the login
                     form: with the session live the gate sends this account
                     straight back here. -->
                <p class="auth-switch">
                    <a href="<?= htmlspecialchars(url('logout') . '?to=login') ?>">Back to log in</a>
                </p>
            </div>
        </section>
    </main>
</body>

</html>
