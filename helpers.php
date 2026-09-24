<?php

// The folder the app is served from, e.g. "/case/case", or "" at a domain's
// root. Taken from the path of APP_URL in .env, so moving the install means
// changing that one line; every link, redirect and asset goes through it.
// Without APP_URL it falls back to the local XAMPP folder.
if (!defined('BASE_URL')) {
    define('BASE_URL', ($_ENV['APP_URL'] ?? '') !== ''
        ? rtrim((string)parse_url($_ENV['APP_URL'], PHP_URL_PATH), '/')
        : '/case/case');
}

if (!defined('ROUTE_SECRET_KEY')) {
    // Salt for the hashed route tokens (see route_token() below). It is read
    // from .env so the value never enters version control. A checkout without
    // it still runs: the fallback is derived from this install's own path, so
    // it is stable on one machine and different on every other — never a
    // constant that anyone reading the repository could reuse.
    define('ROUTE_SECRET_KEY', ($_ENV['ROUTE_SECRET_KEY'] ?? '') !== ''
        ? $_ENV['ROUTE_SECRET_KEY']
        : hash('sha256', __FILE__));
}

// NOTE: the public meet.jit.si fallback (when these are empty) cuts off
// embedded/iframed calls at 5 minutes — it's a demo-only restriction 8x8
// enforces to push production traffic onto paid JaaS, confirmed live in
// this app on 2026-09-02. Do not clear these back to '' without also
// switching to a self-hosted Jitsi instance; otherwise real sessions break.
// NOTE: reads $_ENV, not getenv() — this app's Dotenv setup (see
// App/config/db.php) only populates $_ENV/$_SERVER, not the process
// environment, so getenv() here always silently returned nothing.
if (!defined('JAAS_APP_ID')) {
    define('JAAS_APP_ID', $_ENV['JAAS_APP_ID'] ?? 'vpaas-magic-cookie-07a0d466d8b6489788b37b274e53198d');
}
if (!defined('JAAS_API_KEY_ID')) {
    define('JAAS_API_KEY_ID', $_ENV['JAAS_API_KEY_ID'] ?? 'vpaas-magic-cookie-07a0d466d8b6489788b37b274e53198d/ca0dd2');
}
if (!defined('JAAS_PRIVATE_KEY_PATH')) {
    define('JAAS_PRIVATE_KEY_PATH', $_ENV['JAAS_PRIVATE_KEY_PATH'] ?? __DIR__ . '/App/config/jaas_private_key.pem');
}

if (!defined('DAILY_API_KEY')) {
    define('DAILY_API_KEY', $_ENV['DAILY_API_KEY'] ?? '');
}

// Web Push. Empty by default: PushService::isConfigured() checks these and
// does nothing when they are unset, so notifications stay in-app exactly as
// they are now rather than erroring. Generate a pair with
// `php scripts/vapid_keys.php` and paste it into .env — once, and the same
// pair on every environment, because the public key is baked into every
// subscription a browser has already handed out.
if (!defined('VAPID_PUBLIC_KEY')) {
    define('VAPID_PUBLIC_KEY', $_ENV['VAPID_PUBLIC_KEY'] ?? '');
}
if (!defined('VAPID_PRIVATE_KEY')) {
    define('VAPID_PRIVATE_KEY', $_ENV['VAPID_PRIVATE_KEY'] ?? '');
}
// Who the push service should contact about our notifications.
if (!defined('VAPID_SUBJECT')) {
    define('VAPID_SUBJECT', $_ENV['VAPID_SUBJECT'] ?? 'https://neustpeerconnect.org');
}

// SMTP for outbound email notifications (see EmailService). Empty by
// default — EmailService::isConfigured() checks these and skips sending
// (in-app notifications still work) rather than erroring when unset.
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? '');
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', $_ENV['SMTP_PORT'] ?? '587');
}
if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', $_ENV['SMTP_USERNAME'] ?? '');
}
if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', $_ENV['SMTP_PASSWORD'] ?? '');
}
if (!defined('MAIL_FROM')) {
    define('MAIL_FROM', $_ENV['MAIL_FROM'] ?? '');
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', $_ENV['MAIL_FROM_NAME'] ?? 'PeerConnect');
}

// The site's own address, scheme and host included. Only needed for links
// that leave the app — right now the password-reset link in an email, which
// cannot be relative.
//
// SECURITY: this is read from .env and NOT from $_SERVER['HTTP_HOST'], which
// a client controls. Building a reset link from the Host header lets an
// attacker request a reset for someone else's address and have the mail
// arrive carrying a link to the attacker's server. Set APP_URL for every
// environment; the fallback below is the local XAMPP install only.
if (!defined('APP_URL')) {
    define('APP_URL', rtrim($_ENV['APP_URL'] ?? 'http://localhost' . BASE_URL, '/'));
}

if (!function_exists('pc_https_decision')) {
    /**
     * What to do about the connection's scheme, given APP_URL and the request.
     *
     * Only an https:// APP_URL turns anything on, so a local http:// install is
     * untouched. Then a plain-HTTP request is sent to the same path on APP_URL's
     * own host (never the Host header, which the client controls), and an HTTPS
     * response tells the browser to use HTTPS from now on (HSTS, one year).
     * A GET or HEAD is moved with 301; anything else with 308, which keeps the
     * method and body instead of turning a form post into a GET.
     *
     * @return array{0: string, 1: string}  ['none', ''], ['redirect', url], or ['hsts', header]
     */
    function pc_https_decision(string $appUrl, array $server): array
    {
        if (stripos($appUrl, 'https://') !== 0) {
            return ['none', ''];
        }
        if (pc_request_is_https($server)) {
            return ['hsts', 'Strict-Transport-Security: max-age=31536000'];
        }
        $origin = 'https://' . parse_url($appUrl, PHP_URL_HOST)
                . (parse_url($appUrl, PHP_URL_PORT) ? ':' . parse_url($appUrl, PHP_URL_PORT) : '');
        return ['redirect', $origin . ($server['REQUEST_URI'] ?? '/')];
    }
}

if (!function_exists('pc_enforce_https')) {
    /** Applies pc_https_decision() to the current web request. */
    function pc_enforce_https(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        [$action, $value] = pc_https_decision(APP_URL, $_SERVER);
        if ($action === 'redirect') {
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            header('Location: ' . $value, true, in_array($method, ['GET', 'HEAD'], true) ? 301 : 308);
            exit;
        }
        if ($action === 'hsts') {
            header($value);
        }
    }
}

if (!function_exists('base_url')) {
    function base_url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        return $path === '' ? BASE_URL : BASE_URL . '/' . $path;
    }
}

if (!function_exists('pc_site_url')) {
    /**
     * An absolute URL for $path, which is relative to the install root.
     * Pass a route token, e.g. pc_site_url(ltrim(url('login'), '/')).
     */
    function pc_site_url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        // url() already includes BASE_URL; strip it so it is not doubled.
        $prefix = ltrim(BASE_URL, '/');
        if ($prefix !== '' && strpos($path, $prefix . '/') === 0) {
            $path = substr($path, strlen($prefix) + 1);
        }
        return $path === '' ? APP_URL : APP_URL . '/' . $path;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return base_url('public/' . ltrim($path, '/'));
    }
}

if (!function_exists('pc_avatar')) {
    /**
     * What goes inside an avatar: the person's picture if they uploaded one,
     * their initials if they did not. The caller draws the circle; this fills
     * it, already escaped.
     *
     * Initials are not a placeholder to be designed away — most people here
     * have never uploaded a picture, so they are what most avatars will show.
     * The point of this is that the two cases are decided in one place rather
     * than in the forty-odd spots that were each deciding it for themselves,
     * about half of which had simply never been given a picture to show.
     */
    function pc_avatar(?string $image, string $name, int $letters = 2): string
    {
        $src = trim((string)$image);
        if ($src !== '') {
            return '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="">';
        }
        return htmlspecialchars(pc_initials($name, $letters));
    }
}

if (!function_exists('pc_initials')) {
    /**
     * Up to $letters initials from a name: "Ana Cruz" gives AC, "Ana" gives A.
     * mb_* throughout, because a name is not necessarily ASCII and substr()
     * would cut a multi-byte letter in half.
     */
    function pc_initials(string $name, int $letters = 2): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out   = '';
        foreach ($parts as $part) {
            if (mb_strlen($out) >= $letters) {
                break;
            }
            $out .= mb_substr($part, 0, 1);
        }
        return mb_strtoupper($out !== '' ? $out : '?');
    }
}

if (!function_exists('route_token')) {
    function route_token(string $page): string
    {
        return md5($page . ROUTE_SECRET_KEY);
    }
}

if (!function_exists('alert')) {
    function alert($message)
    {
        echo "<script>Swal.fire('$message')</script>";
    }
}

if (!function_exists('logMe')) {
    function logMe($username, $dateTime, $activity)
    {
        require_once __DIR__ . '/App/config/db.php';

        global $con;

        // Cut to the column sizes (email 100, activity 255). A strict-mode
        // MySQL rejects a longer value outright instead of trimming it.
        $username = mb_substr((string)$username, 0, 100);
        $activity = (string)$activity;
        if (mb_strlen($activity) > 255) {
            $activity = mb_substr($activity, 0, 254) . '…';
        }

        // By the time anything is logged the change it describes has already
        // been made, so a failed insert must not turn that page into an error.
        // It goes to the PHP error log instead.
        try {
            $stmt = mysqli_prepare($con, "INSERT INTO logs (email, log_date, activity) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'sss', $username, $dateTime, $activity);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        } catch (Throwable $e) {
            error_log('logMe could not record "' . $activity . '": ' . $e->getMessage());
        }
    }
}

if (!function_exists('pc_admin_log')) {
    /**
     * Record something an admin did, under the signed-in admin's email.
     *
     * Every entry starts with "admin " — the Activity Logs page files an entry
     * under Admin, and counts it, by that prefix. Avoid the words "login" and
     * "via google" in $what: the sign-in counts on Reports and Integrations
     * match on them.
     */
    function pc_admin_log(string $what): void
    {
        logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'), 'admin ' . $what);
    }
}

if (!function_exists('pc_user_name')) {
    /** A person as a log entry names them: "First Last (user #12)". */
    function pc_user_name(mysqli $con, int $user_id): string
    {
        $st = $con->prepare("SELECT TRIM(CONCAT_WS(' ', firstname, lastname)) AS name, email FROM users WHERE user_id = ?");
        $st->bind_param('i', $user_id);
        $st->execute();
        $u = $st->get_result()->fetch_assoc();
        $st->close();

        $name = $u ? ($u['name'] !== '' ? $u['name'] : (string)$u['email']) : '';
        return ($name !== '' ? $name . ' ' : '') . '(user #' . $user_id . ')';
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
    }
}

if (!function_exists('verify_csrf')) {
    /**
     * Whether this POST carries the session's security token. Every form and
     * AJAX endpoint that changes something checks this — once, here, rather
     * than each writing its own comparison. See verify_csrf_token().
     */
    function verify_csrf(): bool
    {
        return verify_csrf_token($_POST['csrf_token'] ?? null);
    }
}

if (!function_exists('verify_csrf_token')) {
    /**
     * Whether $submitted is the session's security token. On its own for JSON
     * endpoints, where the token travels in the request body instead of $_POST.
     *
     * $submitted is whatever arrived, so only a non-empty string can match. A
     * request can send the token as a list (csrf_token[]=x), or in JSON as a
     * number or an object, and hash_equals() throws on anything but a string —
     * which turned a refused request into a server error page. A session with no
     * token yet matches nothing: hash_equals('', '') is true, so an empty token
     * would otherwise pass.
     */
    function verify_csrf_token(mixed $submitted): bool
    {
        $expected = $_SESSION['csrf_token'] ?? '';
        return is_string($expected) && $expected !== ''
            && is_string($submitted) && $submitted !== ''
            && hash_equals($expected, $submitted);
    }
}

if (!function_exists('pc_enforce_account_status')) {
    /**
     * Sign out an account an admin has blocked, mid-session.
     *
     * `users.status` was only ever consulted by the login form, and sessions
     * here last 20 days — so blocking someone who was already signed in did
     * nothing at all until they happened to log out and back in. This runs on
     * every authenticated request instead (one primary-key lookup), so the
     * admin's Block button takes effect on that user's very next page load.
     *
     * 'restricted' is handled too: the restriction is lifted automatically
     * once it has run out, and while it lasts the account is read-only —
     * every POST is refused with an explanation, while GETs go through so
     * the person can still see their own data. The newest restriction is the
     * one in force (see ModerationService).
     */
    function pc_enforce_account_status(mysqli $con): void
    {
        if (empty($_SESSION['user_id'])) {
            return;
        }

        // Before anything else, including the lookup below: an idle session is
        // over, and there is nothing to enforce against an account whose
        // session has just ended. Running after the verification gate would
        // also send an idled, unapproved user to the verification screen
        // instead of to login.
        pc_idle_gate();
        pc_revoked_session_gate($con);

        $uid = (int) $_SESSION['user_id'];
        $row = UserRepository::signInState($con, $uid);

        $status = $row['status'] ?? '';

        /*
         * A restriction is a fixed-length penalty. It is lifted here, on the
         * restricted user's own next request, and by the maintenance job every
         * 30 minutes for anyone who stays away.
         */
        if ($status === 'restricted' && ModerationService::liftIfOver($con, $uid)) {
            $status = 'active';
        }

        /*
         * What a restriction actually withholds: the account stays readable —
         * they can sign in, see their sessions and read messages — but it
         * cannot write. One check here covers every form and AJAX endpoint in
         * the app, rather than sprinkling guards through ninety files.
         *
         * Admins are exempt so a restricted admin (which should not happen)
         * can still lift their own restriction rather than being stuck.
         */
        if ($status === 'restricted'
            && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && ($_SESSION['role'] ?? '') !== 'admin') {

            $wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
                || strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0
                || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

            /*
             * Say when it ends. Being told only that you are restricted, with
             * no date, leaves nothing to do but guess and try again — which is
             * exactly what produced a column of identical refusals.
             */
            $until = null;
            $liftsOn = ModerationService::liftsOn($con, $uid);
            if ($liftsOn !== null) {
                $until = date('F j, Y', strtotime($liftsOn));
            }

            $message = $until !== null
                ? "Your account is restricted until {$until}, so this change was not saved."
                    . ' You can still browse and read while it lasts.'
                : 'Your account is restricted, so this change was not saved.'
                    . ' You can still browse and read while it lasts.';

            if ($wantsJson) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode([
                    'success'     => false,
                    'error'       => $message,
                    'restricted'  => true,
                    'restricted_until' => $until,
                ]);
                exit;
            }

            pc_flash('warning', $message, 'Account restricted');
            $back = $_SERVER['HTTP_REFERER'] ?? url('welcomepage');
            header('Location: ' . $back);
            exit;
        }

        // A missing row means the account was deleted while signed in.
        $blocked = $row === null || $status === 'blocked';
        if (!$blocked) {
            // Still signed in and not blocked — so the remaining question is
            // how far through registration this account actually is.
            pc_verification_gate(
                $con,
                (string) ($row['role'] ?? ''),
                $row['verified'] ?? null,
                $row['email_verified_at'] ?? null
            );
            return;
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();

        header('Location: ' . url('login') . '?blocked=1');
        exit;
    }
}

if (!function_exists('pc_revoked_session_gate')) {
    /**
     * End a session whose account has had its password reset.
     *
     * This is the check that App/controllers/session-check.php was written to
     * perform and that nothing ever called — PasswordResetService says so in
     * its own comments: it clears the token rows "for tidiness, not security…
     * a browser with a live PHP session stays signed in". Resetting a password
     * did not, in fact, sign anybody else out.
     *
     * Deliberately narrower than session-check.php's version, which used
     * isCurrent(). There is one token per account, so isCurrent() is false for
     * every session but the newest, and wiring it in as written would have
     * turned the app single-session: signing in on a phone would drop the
     * laptop. clearedByReset() asks only whether any live token remains, which
     * a reset removes and an ordinary second login does not.
     *
     * The other thing session-check.php did — session_regenerate_id(true) on
     * every request — is deliberately NOT here. It deletes the old session
     * file immediately, and this app polls every 3 seconds in chat: a request
     * already in flight would come back holding an id that no longer exists
     * and be treated as signed out. Fixation is defended where it matters, at
     * sign-in, by login.php and RememberService.
     *
     * A Google login carries no password_id, so it is skipped.
     */
    function pc_revoked_session_gate(mysqli $con): void
    {
        if (empty($_SESSION['user_id']) || empty($_SESSION['password_id'])) {
            return;
        }
        if (!LoginTokenService::clearedByReset($con, (int) $_SESSION['password_id'])) {
            return;
        }

        $target = url('login');

        $wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
            || strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0
            || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        if ($wantsJson) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success'  => false,
                'error'    => 'Your session ended because this account\'s password was changed.',
                'idle'     => true,
                'redirect' => $target,
            ]);
            exit;
        }

        $_SESSION['login_notice'] = 'This account\'s password was changed, so you were signed out. '
            . 'Please sign in with the new password.';
        header('Location: ' . $target);
        exit;
    }
}

if (!function_exists('pc_idle_quiet_routes')) {
    /**
     * Routes that are served but do not count as activity.
     *
     * The app polls in the background: the shell asks for the notification
     * count every 30 seconds, chat every 3, and the two verification screens
     * every 10. All of them reach PHP, so a timer refreshed by "any request"
     * would never expire while a single tab was open anywhere — the feature
     * would look implemented and do nothing at all.
     *
     * Only automatic polls belong here. Opening the notification panel
     * (notifications-get) or marking one read is a person doing something,
     * and counts.
     */
    function pc_idle_quiet_routes(): array
    {
        return [
            'notifications-count',
            'messages-get',
            'mentor-check-status',
            'mentee-check-status',
            'session-check',
        ];
    }
}

if (!function_exists('pc_idle_gate')) {
    /**
     * Close a session that has sat untouched for PC_IDLE_HOURS.
     *
     * Deliberately an application check rather than session.gc_maxlifetime.
     * The 30-minute gc_maxlifetime this replaces let PHP's collector delete
     * the session file underneath somebody who was still using the app, which
     * is why it was removed (see Framework/bootstrap.php). This runs on the
     * user's own next request, so nothing disappears mid-use and the reason
     * can be shown.
     *
     * The session is emptied but a fresh one is kept, to carry the notice to
     * the login page. The "Remember me" cookie is left alone on purpose: its
     * rolling token signs the visitor straight back in when they return,
     * which is the whole point of having asked to be remembered.
     */
    function pc_idle_gate(): void
    {
        if (empty($_SESSION['user_id']) || !defined('PC_IDLE_HOURS')) {
            return;
        }

        $now  = time();
        $seen = $_SESSION['pc_last_seen'] ?? null;

        /*
         * A session from before this existed has no stamp. Start its clock
         * now rather than treating "never seen" as "idle forever" — otherwise
         * deploying this signs out every signed-in user at once.
         */
        if (!is_int($seen)) {
            $_SESSION['pc_last_seen'] = $now;
            return;
        }

        if ($now - $seen > PC_IDLE_HOURS * 3600) {
            $target = url('login');

            // Same reasoning as pc_verification_gate: a caller expecting JSON
            // must not be handed a login page to parse.
            $wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
                || strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0
                || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            if ($wantsJson) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode([
                    'success'  => false,
                    'error'    => 'Your session ended after a period of inactivity.',
                    'idle'     => true,
                    'redirect' => $target,
                ]);
                exit;
            }

            $_SESSION['login_notice'] = 'You were signed out after '
                . PC_IDLE_HOURS . ' hours of inactivity. Please sign in again.';
            header('Location: ' . $target);
            exit;
        }

        /*
         * An unrouted include cannot name itself, and the safe direction here
         * is the opposite of the verification gate's: unknown counts as real
         * activity. Failing to expire somebody is a much smaller harm than
         * expiring somebody who is sitting right there.
         */
        $route = defined('PC_ROUTE') ? PC_ROUTE : null;
        if ($route !== null && in_array($route, pc_idle_quiet_routes(), true)) {
            return;
        }

        $_SESSION['pc_last_seen'] = $now;
    }
}

if (!function_exists('pc_gate_open_routes')) {
    /**
     * The routes a member may reach before they have finished registering.
     *
     * Everything not named here is closed to an account that still owes us a
     * confirmed address or an admin's approval — deny by default, so a page
     * added later is protected by existing rather than by being remembered.
     *
     * @return array{email:string[], approval:string[]}
     *   email    reachable while the address is unconfirmed
     *   approval additionally reachable once it is confirmed, while an admin
     *            has yet to approve the identity documents
     */
    function pc_gate_open_routes(): array
    {
        // Signed-out pages and the confirmation flow itself. The gate's own
        // redirect targets have to be in here or the redirect loops.
        $email = [
            '', 'welcomepage', 'login', 'signup', 'logout',
            'forgot-password', 'reset-password', 'google-login', 'session-check',
            'verify-email', 'email-pending', 'resend-verification',
            'admin-login', 'admin-signup', 'pwa-manifest',
            // Reviewed from an inbox, by somebody who is not signed in at all.
            'admin-review', 'admin-review-act', 'admin-review-file',
            // The address may simply have a typo in it, so there has to be a
            // way to correct one from inside this stage. That used to be the
            // whole of Settings — which let an account that had not proved its
            // address into the member shell, and with it the Security tab,
            // Download My Data and Delete Account. It is now one page holding
            // one field, and the endpoint it posts to. Changing the address
            // re-arms this stage rather than satisfying it; see
            // settings/update_email.php.
            'change-email', 'account-update-email',
        ];

        // The identity form, what it posts to, and the poller the pending
        // screen uses to notice an admin's decision.
        $approval = [
            'mentee-verification', 'mentor-verification', 'verification-file',
            // The administrator's own form, and the poller its waiting
            // screen uses to notice the owner's decision.
            'admin-verification', 'admin-check-status',
            'mentee-check-status', 'mentor-check-status',
            // Deliberately NOT 'onboarding': the questionnaire comes after
            // approval, not before it. Once users.verified is 1 this gate
            // returns early and onboarding is reachable like any other page.
            'notifications-count', 'notifications-get',
            'notifications-read', 'notifications-read-all',
            'account-update-notif-pref', 'account-update-privacy', 'account-export-data',
        ];

        return ['email' => $email, 'approval' => $approval];
    }
}

if (!function_exists('pc_verification_gate')) {
    /**
     * Keeps a half-registered account out of the application.
     *
     * Registration here has two gates, and passing one says nothing about the
     * other:
     *
     *   1. the owner proves they can read the address they signed up with
     *      (users.email_verified_at — see EmailVerificationService);
     *   2. an admin approves the student ID and credential they uploaded
     *      (users.verified — see admin/action_verify.php).
     *
     * Only an account through both belongs on a dashboard. This runs from
     * pc_enforce_account_status(), which every authenticated request reaches
     * through App/config/db.php, so it holds for a typed URL, a refresh, a new
     * tab, the Back button and a remembered cookie alike — not just for the
     * redirect the login form happens to choose. That is the whole point: the
     * old code decided where to *send* people and then let them go anywhere.
     *
     * Admins are exempt. They are created by invitation through
     * admin/signup.php, already active and verified, and have no identity form
     * to submit.
     */
    function pc_verification_gate(mysqli $con, string $role, $verified, $confirmedAt): void
    {
        /*
         * An administrator awaiting approval is held on their own form the way
         * a mentor is held on theirs. This used to return for every admin,
         * which was right when an admin account was verified the moment it was
         * created and is not now.
         */
        if ($role === 'admin') {
            if (!empty($verified)) {
                return;
            }
            $route = defined('PC_ROUTE') ? PC_ROUTE : null;
            $open  = pc_gate_open_routes();
            if ($route !== null && in_array($route, array_merge($open['email'], $open['approval']), true)) {
                return;
            }
            header('Location: ' . url('admin-verification'));
            exit;
        }

        $emailPending = EmailVerificationService::pendingFor($con, $role, $confirmedAt);
        $needsAdmin   = in_array($role, ['mentee', 'mentor'], true) && empty($verified);

        if (!$emailPending && !$needsAdmin) {
            return;   // through both gates; nothing to do on any page
        }

        // Undefined means this file was reached without going through the
        // router. Treat it as an unknown route and close it: a page that
        // cannot name itself cannot be on the allow-list.
        $route = defined('PC_ROUTE') ? PC_ROUTE : null;
        $open  = pc_gate_open_routes();

        $allowed = $emailPending
            ? $open['email']
            : array_merge($open['email'], $open['approval']);

        if ($route !== null && in_array($route, $allowed, true)) {
            return;
        }

        $target = $emailPending
            ? url('email-pending')
            : url($role === 'mentor' ? 'mentor-verification' : 'mentee-verification');

        // An endpoint that asked for JSON gets JSON. Sending it a redirect
        // hands the caller a page of HTML where it expected an object, which
        // surfaces as an unexplained parse error in the console instead of a
        // message anyone can act on.
        $wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
            || strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0
            || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

        if ($wantsJson) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success'  => false,
                'error'    => $emailPending
                    ? 'Please confirm your email address to finish setting up your account.'
                    : 'Your account is waiting for an administrator to approve it.',
                'gated'    => true,
                'redirect' => $target,
            ]);
            exit;
        }

        header('Location: ' . $target);
        exit;
    }
}

if (!function_exists('pc_client_ip')) {
    /**
     * The address to throttle against. REMOTE_ADDR only: the X-Forwarded-For
     * header is attacker-controlled unless a trusted proxy sets it, and
     * trusting it here would hand an attacker an unlimited supply of
     * identities — exactly the hole the throttle exists to close.
     */
    function pc_client_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : 'unknown';
    }
}

if (!function_exists('pc_throttle_count')) {
    /** Hits recorded against $bucket inside the trailing window. */
    function pc_throttle_count(mysqli $con, string $bucket, int $windowSeconds): int
    {
        $st = $con->prepare(
            "SELECT COUNT(*) FROM auth_throttle
              WHERE bucket = ? AND hit_at > (NOW() - INTERVAL ? SECOND)"
        );
        if (!$st) { return 0; }
        $st->bind_param('si', $bucket, $windowSeconds);
        $st->execute();
        $n = (int)($st->get_result()->fetch_row()[0] ?? 0);
        $st->close();
        return $n;
    }
}

if (!function_exists('pc_throttle_hit')) {
    /** Records one hit. Old rows are swept occasionally, not on every call. */
    function pc_throttle_hit(mysqli $con, string $bucket): void
    {
        $st = $con->prepare("INSERT INTO auth_throttle (bucket, hit_at) VALUES (?, NOW())");
        if (!$st) { return; }
        $st->bind_param('s', $bucket);
        $st->execute();
        $st->close();

        // 1-in-50 sweep. A cron would be tidier, but this table is only
        // written on failure, so it stays small on its own.
        if (random_int(1, 50) === 1) {
            $con->query("DELETE FROM auth_throttle WHERE hit_at < (NOW() - INTERVAL 1 DAY)");
        }
    }
}

if (!function_exists('pc_throttle_clear')) {
    /** Forgets a bucket — called after a success, so one good sign-in resets it. */
    function pc_throttle_clear(mysqli $con, string $bucket): void
    {
        $st = $con->prepare("DELETE FROM auth_throttle WHERE bucket = ?");
        if (!$st) { return; }
        $st->bind_param('s', $bucket);
        $st->execute();
        $st->close();
    }
}

if (!function_exists('pc_throttle_retry_after')) {
    /**
     * Seconds until $bucket drops back under $maxHits, or 0 if it already is.
     *
     * The window slides: once the oldest recorded failure ages out, one
     * attempt is handed back. That is a little kinder than a flat lockout and
     * a lot simpler than storing an explicit locked-until stamp.
     */
    function pc_throttle_retry_after(mysqli $con, string $bucket, int $windowSeconds, int $maxHits): int
    {
        if (pc_throttle_count($con, $bucket, $windowSeconds) < $maxHits) {
            return 0;
        }
        $st = $con->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, NOW(), hit_at + INTERVAL ? SECOND)
               FROM auth_throttle
              WHERE bucket = ? AND hit_at > (NOW() - INTERVAL ? SECOND)
              ORDER BY hit_at ASC LIMIT 1"
        );
        if (!$st) { return 0; }
        $st->bind_param('isi', $windowSeconds, $bucket, $windowSeconds);
        $st->execute();
        $secs = (int)($st->get_result()->fetch_row()[0] ?? 0);
        $st->close();
        return max(0, $secs);
    }
}

if (!function_exists('rate_limit')) {
    /**
     * Sliding-window limiter backed by the auth_throttle table.
     *
     * It used to keep its buckets in $_SESSION, which meant anyone who threw
     * the session cookie away between requests was never limited at all — so
     * it only ever slowed down honest users. The store is now the database,
     * keyed on the caller's address as well as $key, so discarding cookies
     * changes nothing.
     *
     * Falls back to the old session buckets only when no database handle is
     * reachable, so a caller that runs before db.php is included still gets
     * some limiting rather than none.
     */
    function rate_limit(string $key, int $maxHits, int $windowSeconds): bool
    {
        global $con;
        $bucket = substr($key . '|' . pc_client_ip(), 0, 190);

        if ($con instanceof mysqli) {
            if (pc_throttle_count($con, $bucket, $windowSeconds) >= $maxHits) {
                return false;
            }
            pc_throttle_hit($con, $bucket);
            return true;
        }

        $now  = time();
        $hits = $_SESSION['_rate_limit'][$key] ?? [];
        $hits = array_values(array_filter($hits, fn($ts) => $ts > $now - $windowSeconds));
        if (count($hits) >= $maxHits) {
            $_SESSION['_rate_limit'][$key] = $hits;
            return false;
        }
        $hits[] = $now;
        $_SESSION['_rate_limit'][$key] = $hits;
        return true;
    }
}

if (!function_exists('e')) {
    function e(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('url')) {
    function url(string $page): string
    {
        return base_url(route_token($page));
    }
}

// The school's 9 clubs — the same fixed list the signup/verification form
// offers (App/views/menteepage/verification.php). Used by Find a Mentor's
// Club/Subject filters and by Resources, so the taxonomy has one definition
// instead of drifting copies.
if (!defined('PC_CLUBS')) {
    define('PC_CLUBS', [
        'Mathematics Club',
        'Science Club',
        'English Club',
        'Social Studies Club',
        'Home Economics Club',
        'Industrial Education Club',
        'Physical Education Club',
        'Special Education Club',
        'Elementary Education Club',
    ]);
}

if (!function_exists('pc_trend')) {
    // Shared "vs last month" trend badge used by stat cards (mentee
    // Dashboard, Sessions, …) — only ever call this with two counts that
    // come from a genuinely stable historical timestamp (created_at,
    // completed_at, or a calendar-month bucket of session_date); it has no
    // way to tell a real comparison from a meaningless one.
    function pc_trend(int $now, int $prior): ?array
    {
        if ($prior <= 0) {
            return $now > 0 ? ['dir' => 'up', 'label' => 'New'] : null;
        }
        $pct = (int)round((($now - $prior) / $prior) * 100);
        if ($pct === 0) return null;
        return [
            'dir'   => $pct > 0 ? 'up' : 'down',
            'label' => ($pct > 0 ? '+' : '') . $pct . '% vs last month',
        ];
    }
}

if (!function_exists('pc_flash')) {
    /**
     * Queue a message to show the person after the next page load.
     *
     * Almost every write in this app answers with a redirect, which throws
     * away anything the handler wanted to say — so an action either said
     * nothing at all or the page had to invent its own `?saved=1` convention,
     * and no two pages used the same one. This is the one convention: the
     * handler calls pc_flash() before redirecting, and the toast renderer
     * (App/views/includes/toasts.php, included by both the member shell and
     * the admin layout) shows it once and clears it.
     *
     * $type is 'success', 'error', 'warning' or 'info' — anything else is
     * treated as 'info' rather than emitting a class nothing styles.
     *
     * Messages are held in the session, so they survive the redirect and only
     * ever reach the person they were queued for.
     */
    function pc_flash(string $type, string $message, string $title = ''): void
    {
        $message = trim($message);
        if ($message === '' || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';

        if (!isset($_SESSION['pc_flash']) || !is_array($_SESSION['pc_flash'])) {
            $_SESSION['pc_flash'] = [];
        }

        // A person who submits the same blocked form four times should not be
        // met with four identical toasts stacked up the screen.
        foreach ($_SESSION['pc_flash'] as $existing) {
            if (($existing['message'] ?? '') === $message && ($existing['type'] ?? '') === $type) {
                return;
            }
        }

        // A hard cap, so a redirect loop cannot fill the session with these.
        if (count($_SESSION['pc_flash']) >= 5) {
            array_shift($_SESSION['pc_flash']);
        }

        $_SESSION['pc_flash'][] = [
            'type'    => $type,
            'title'   => trim($title),
            'message' => $message,
        ];
    }
}

if (!function_exists('pc_flash_take')) {
    /** Return every queued message and clear the queue. Read exactly once. */
    function pc_flash_take(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $out = $_SESSION['pc_flash'] ?? [];
        unset($_SESSION['pc_flash']);
        return is_array($out) ? $out : [];
    }
}

if (!function_exists('pc_back_url')) {
    /**
     * Where a "Back" control should point.
     *
     * The page the visitor actually came from, when that was a page of this
     * site and not this same page — otherwise the landing page. Resolved here
     * rather than with history.back() so the control still works with no
     * JavaScript, and so it can never lead somewhere off-site or nowhere at
     * all when the page was opened directly from a bookmark.
     */
    function pc_back_url(string $fallback_route = 'welcomepage'): string
    {
        $fallback = url($fallback_route);
        $referer  = $_SERVER['HTTP_REFERER'] ?? '';
        if ($referer === '') {
            return $fallback;
        }

        $parts = parse_url($referer);
        if (empty($parts['host']) || $parts['host'] !== ($_SERVER['HTTP_HOST'] ?? '')) {
            return $fallback;   // another site sent them here
        }

        $path = $parts['path'] ?? '';
        // Must sit inside this install — never an arbitrary path on the host.
        if ($path === '' || strpos($path, BASE_URL . '/') !== 0) {
            return $fallback;
        }

        // Coming "back" to the page you are already on is not going back.
        $here = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        if ($path === $here) {
            return $fallback;
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}

if (!function_exists('pc_js_arg')) {
    /**
     * A PHP value written as a JavaScript argument inside an HTML attribute:
     *
     *   onclick="openBookingModal(12, <?= pc_js_arg($subject) ?>)"
     *
     * htmlspecialchars() inside '...' is not enough there. The browser turns
     * &#039; back into ' before the handler runs, so a subject such as
     * "Writer's Workshop" ended the string early and the button did nothing —
     * and text written to look like code would have run. JSON makes a quoted,
     * escaped JavaScript value; escaping that for HTML keeps it intact in the
     * attribute, whatever the text contains. Write it without surrounding quotes.
     */
    function pc_js_arg($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return htmlspecialchars($json === false ? 'null' : $json, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pc_backup_dir')) {
    /**
     * Where scripts/backup.php writes, where scripts/maintenance.php logs, and
     * where the admin Backup page looks to report the last backup.
     *
     * BACKUP_DIR in .env, else C:\PeerConnectBackups. Outside the web root on
     * purpose: the dumps hold password hashes and the upload archives hold
     * scanned student IDs, and .htaccess only protects folders inside htdocs.
     */
    function pc_backup_dir(): string
    {
        $dir = trim((string)($_ENV['BACKUP_DIR'] ?? getenv('BACKUP_DIR') ?: ''));
        return rtrim($dir !== '' ? $dir : 'C:\\PeerConnectBackups', '\\/');
    }
}
