<?php

/**
 * verify_email.php — what the link in the confirmation letter opens.
 *
 * Deliberately takes no session. People open mail in whichever browser their
 * mail client hands them, often not the one they registered in, and a link
 * that only works in the original browser is a link that mostly does not
 * work. The token in the URL is the whole proof; nothing here reads or trusts
 * a session to decide whether the click counts.
 *
 * And it never signs anybody in. Confirming an address proves the address,
 * not the person at the keyboard — anyone forwarded the letter could click
 * it. The one exception is cosmetic: if the browser already holds a session
 * for this very account, the page sends them on rather than asking them to
 * log in again, which is a redirect, not a grant.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../../services/EmailVerificationService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$selector  = is_string($_GET['s'] ?? null) ? $_GET['s'] : '';
$validator = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';

$result = EmailVerificationService::confirm($con, $selector, $validator);
$reason = $result['reason'];

if ($result['ok'] && $reason === 'confirmed') {
    logMe((string)(UserRepository::email($con, (int)$result['user_id']) ?? ''), date('Y-m-d H:i:s'), 'email confirmed');
}

/*
 * Already holding a session for this same account? Then there is nothing to
 * ask: send them wherever they now belong. pc_verification_gate() has just
 * stopped applying the email stage to them, so destination() will pick the
 * identity form or their dashboard on its own.
 *
 * The id has to match. Confirming from a machine somebody else is signed in
 * on must not move that person anywhere.
 */
if ($result['ok'] && !empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$result['user_id']) {
    $state = UserRepository::signInState($con, (int)$result['user_id']);
    if ($state) {
        pc_flash('success', $reason === 'already'
            ? 'That address was already confirmed.'
            : 'Thanks — your email address is confirmed.', 'Email confirmed');
        header('Location: ' . SignInService::destination(
            (string)$state['role'],
            $state['status'],
            $state['verified'],
            $con,
            $state['email_verified_at'] ?? null
        ));
        exit;
    }
}

// ── What the page says, per outcome ────────────────────────────────────
// Each state gets its own way forward. A dead end with no next step is the
// thing this page most has to avoid: somebody stuck here has no account.
$states = [
    'confirmed' => [
        'ok'    => true,
        'h1'    => 'Email confirmed',
        'sub'   => 'Thanks — we know we can reach you. Sign in to submit your verification '
                 . 'documents, and an administrator will review them.',
        'cta'   => 'Log In',
        'href'  => url('login'),
        'note'  => '',
    ],
    'already' => [
        'ok'    => true,
        'h1'    => 'Already confirmed',
        'sub'   => 'This address was confirmed earlier, so there is nothing more to do here. '
                 . 'You can sign in as usual.',
        'cta'   => 'Log In',
        'href'  => url('login'),
        'note'  => 'Mail apps sometimes open links on your behalf, so a second click on the '
                 . 'same link is normal.',
    ],
    'expired' => [
        'ok'    => false,
        'h1'    => 'This link has expired',
        'sub'   => 'Confirmation links last ' . (int)round(EmailVerificationService::TTL_MINUTES / 60)
                 . ' hours. Sign in with the password you chose and we will send you a fresh one.',
        'cta'   => 'Log In to Resend',
        'href'  => url('login'),
        'note'  => 'Your account is still there — only the link ran out.',
    ],
    'unknown' => [
        'ok'    => false,
        'h1'    => 'This link is not valid',
        'sub'   => 'We do not recognise it. It may have been retyped or cut short by a mail '
                 . 'app — links wrapped across two lines often lose their ending.',
        'cta'   => 'Log In',
        'href'  => url('login'),
        'note'  => 'Copy the whole address from the email, or sign in and use Resend.',
    ],
    'blocked' => [
        'ok'    => false,
        'h1'    => 'This account is blocked',
        'sub'   => SignInService::BLOCKED_MESSAGE,
        'cta'   => 'Back to PeerConnect',
        'href'  => url('welcomepage'),
        'note'  => '',
    ],
    'unavailable' => [
        'ok'    => false,
        'h1'    => 'Email confirmation is not set up',
        'sub'   => 'This install has not finished setting up email confirmation, so this link '
                 . 'cannot be checked. Please sign in as usual.',
        'cta'   => 'Log In',
        'href'  => url('login'),
        'note'  => 'If you are the administrator: run email_verification.sql.',
    ],
];

$view = $states[$reason] ?? $states['unknown'];

$hero_art     = 'images/background.png';
$has_hero_art = is_file(PUBLIC_PATH . '/' . $hero_art);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($view['h1']) ?> — PeerConnect</title>
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
                <h2 class="auth-aside-h">Two steps and<br>you are <em>in</em>.</h2>
                <p class="auth-aside-p">
                    Confirm your email, then an administrator checks your student ID.
                    After that, PeerConnect is yours.
                </p>
                <?php if ($has_hero_art): ?>
                    <div class="auth-art">
                        <img class="auth-aside-art" src="<?= htmlspecialchars(asset($hero_art)) ?>" alt="" width="1536" height="1024">

                        <div class="auth-float auth-float-1">
                            <span class="auth-float-ico" style="background:var(--success-bg);color:var(--success);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8 12.3 2.7 2.7L16 9.7" />
                                </svg>
                            </span>
                            <span>
                                <span class="auth-float-t">Step One</span>
                                <span class="auth-float-s">Confirm your email address</span>
                            </span>
                        </div>

                        <div class="auth-float auth-float-2">
                            <span class="auth-float-ico" style="background:var(--info-bg);color:var(--info);">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="3.5" y="4.5" width="17" height="15" rx="2.4" />
                                    <path stroke-linecap="round" d="M7.5 9h5M7.5 12.5h9M7.5 16h7" />
                                </svg>
                            </span>
                            <span>
                                <span class="auth-float-t">Step Two</span>
                                <span class="auth-float-s">An admin checks your ID</span>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ── Result panel ── -->
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
                    <?php if ($view['ok']): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8 12.3 2.7 2.7L16 9.7" />
                        </svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" d="M12 7.5v5M12 16.2v.3" />
                        </svg>
                    <?php endif; ?>
                </span>

                <h1 class="auth-h1"><?= htmlspecialchars($view['h1']) ?></h1>
                <p class="auth-sub"><?= htmlspecialchars($view['sub']) ?></p>

                <?php if ($view['note'] !== ''): ?>
                    <div class="auth-alert <?= $view['ok'] ? 'auth-ok' : '' ?>" role="status">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" d="M12 11v5.2M12 7.6v.3" />
                        </svg>
                        <span><?= htmlspecialchars($view['note']) ?></span>
                    </div>
                <?php endif; ?>

                <a class="auth-submit auth-submit-link" href="<?= htmlspecialchars($view['href']) ?>">
                    <?= htmlspecialchars($view['cta']) ?>
                </a>

                <p class="auth-switch">
                    Need a hand?
                    <a href="<?= htmlspecialchars(url('welcomepage')) ?>">Back to PeerConnect</a>
                </p>
            </div>
        </section>
    </main>
</body>

</html>
