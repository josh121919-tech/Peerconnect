<?php
/**
 * Role picker — shown to a signed-in account that has no role yet.
 *
 * Two of the three sign-up paths (admin/signup.php, google-login.php) could
 * historically leave users.role empty, and nothing in the app offered a way to
 * set it afterwards: every dashboard bounced the account back to the front
 * door, and the questionnaire itself only admitted 'mentee' and 'mentor'. This
 * is the way out. It posts to profile-save, which writes the role only when
 * the account genuinely has none.
 *
 * Included by onboarding/index.php — not routed on its own.
 */
if (empty($_SESSION['user_id'])) { exit; }
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose your role — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        body { background: var(--gray-50, #f7f8fa); }
        .rc-wrap { min-height: 100vh; display: grid; place-items: center; padding: 32px 20px; }
        .rc-card { width: 100%; max-width: 640px; background: #fff; border: 1px solid var(--gray-200, #e5e7eb);
                   border-radius: 16px; padding: 40px; box-shadow: 0 1px 3px rgba(16,24,40,.06); }
        .rc-card h1 { margin: 0 0 8px; font-size: 24px; line-height: 1.25; }
        .rc-card p.rc-sub { margin: 0 0 28px; color: var(--gray-600, #4b5563); font-size: 14px; }
        .rc-opts { display: grid; gap: 14px; grid-template-columns: 1fr 1fr; }
        @media (max-width: 560px) { .rc-opts { grid-template-columns: 1fr; } }
        .rc-opt { text-align: left; padding: 20px; border: 2px solid var(--gray-200, #e5e7eb); border-radius: 12px;
                  background: #fff; cursor: pointer; font: inherit; transition: border-color .12s, background .12s; }
        .rc-opt:hover { border-color: var(--gray-400, #9ca3af); }
        .rc-opt.is-on { border-color: var(--forest, #1f7a4d); background: rgba(31,122,77,.05); }
        .rc-opt:focus-visible { outline: 2px solid var(--forest, #1f7a4d); outline-offset: 2px; }
        .rc-opt strong { display: block; font-size: 16px; margin-bottom: 5px; }
        .rc-opt span { display: block; font-size: 13px; color: var(--gray-600, #4b5563); line-height: 1.45; }
        .rc-actions { margin-top: 28px; display: flex; align-items: center; gap: 14px; }
        .rc-msg { font-size: 13px; color: var(--red-600, #b42318); min-height: 18px; }
        .rc-go[disabled] { opacity: .45; cursor: not-allowed; }
    </style>
</head>

<body>
    <div class="rc-wrap">
        <div class="rc-card">
            <h1>How will you use PeerConnect?</h1>
            <p class="rc-sub">Your account was created without this set, so pick the one that fits.
                It decides which dashboard you land on. Ask an administrator if you need it changed later.</p>

            <div class="rc-opts" role="radiogroup" aria-label="Choose your role">
                <button type="button" class="rc-opt" data-role="mentee" role="radio" aria-checked="false">
                    <strong>I'm here to learn</strong>
                    <span>Find mentors, book sessions and track your goals. This is the mentee account.</span>
                </button>
                <button type="button" class="rc-opt" data-role="mentor" role="radio" aria-checked="false">
                    <strong>I'm here to mentor</strong>
                    <span>Publish your availability, take session requests and give feedback. This is the mentor account.</span>
                </button>
            </div>

            <div class="rc-actions">
                <button type="button" class="btn btn-primary rc-go" disabled>Continue</button>
                <span class="rc-msg" role="status" aria-live="polite"></span>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const SAVE_URL = <?= json_encode(url('profile-save')) ?>;
            const CSRF     = <?= json_encode(csrf_token()) ?>;
            const opts = Array.from(document.querySelectorAll('.rc-opt'));
            const go   = document.querySelector('.rc-go');
            const msg  = document.querySelector('.rc-msg');
            let chosen = '';

            opts.forEach(function (b) {
                b.addEventListener('click', function () {
                    opts.forEach(function (o) { o.classList.remove('is-on'); o.setAttribute('aria-checked', 'false'); });
                    b.classList.add('is-on');
                    b.setAttribute('aria-checked', 'true');
                    chosen = b.dataset.role;
                    go.disabled = false;
                    msg.textContent = '';
                });
            });

            go.addEventListener('click', async function () {
                if (!chosen) { return; }
                go.disabled = true;
                msg.textContent = 'Saving…';
                try {
                    const body = new URLSearchParams();
                    body.set('section', 'onboarding-role');
                    body.set('role', chosen);
                    body.set('csrf_token', CSRF);
                    const res  = await fetch(SAVE_URL, { method: 'POST', body: body, credentials: 'same-origin' });
                    const data = await res.json();
                    if (data && data.success) {
                        // Reload straight back into the questionnaire, which
                        // now has a role to phrase its questions for.
                        window.location.reload();
                        return;
                    }
                    msg.textContent = (data && data.message) ? data.message : 'Could not save. Please try again.';
                } catch (e) {
                    msg.textContent = 'Could not reach the server. Please try again.';
                }
                go.disabled = false;
            });
        })();
    </script>
</body>

</html>
