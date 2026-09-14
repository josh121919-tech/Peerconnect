<?php

if (!defined('BASE_URL')) {
    define('BASE_URL', '/case/case');
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

        $stmt = mysqli_prepare($con, "INSERT INTO logs (email, log_date, activity) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'sss', $username, $dateTime, $activity);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
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
    function verify_csrf(): bool
    {
        $submitted = $_POST['csrf_token'] ?? '';
        $expected  = $_SESSION['csrf_token'] ?? '';
        return $expected !== '' && hash_equals($expected, $submitted);
    }
}

if (!function_exists('verify_csrf_token')) {
    // For JSON endpoints, where the token travels in the request body
    // instead of $_POST — verify_csrf() above only checks $_POST.
    function verify_csrf_token(?string $submitted): bool
    {
        $expected = $_SESSION['csrf_token'] ?? '';
        return $expected !== '' && $submitted !== null && hash_equals($expected, $submitted);
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
     * once its end_date has passed, and while it lasts the account is
     * read-only — every POST is refused with an explanation, while GETs go
     * through so the person can still see their own data.
     */
    function pc_enforce_account_status(mysqli $con): void
    {
        if (empty($_SESSION['user_id'])) {
            return;
        }

        $stmt = $con->prepare("SELECT status FROM users WHERE user_id = ? LIMIT 1");
        if (!$stmt) {
            return;
        }
        $uid = (int) $_SESSION['user_id'];
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $status = $row['status'] ?? '';

        /*
         * A restriction is a fixed-length penalty, and nothing was ever
         * lifting it: `restrictions.end_date` was written and then never read
         * again, so a "7 day" restriction lasted forever. Lift it here, on
         * the restricted user's own next request, so it needs no cron.
         */
        if ($status === 'restricted') {
            $chk = $con->prepare("
                SELECT COUNT(*) c FROM restrictions
                WHERE user_id = ? AND end_date >= CURDATE()
            ");
            if ($chk) {
                $chk->bind_param("i", $uid);
                $chk->execute();
                $stillRestricted = (int)($chk->get_result()->fetch_assoc()['c'] ?? 0) > 0;
                $chk->close();

                if (!$stillRestricted) {
                    $lift = $con->prepare("UPDATE users SET status = 'active' WHERE user_id = ? AND status = 'restricted'");
                    if ($lift) {
                        $lift->bind_param("i", $uid);
                        $lift->execute();
                        // Only tell them if this request is the one that
                        // actually lifted it, so the notice is sent once.
                        $lifted = $lift->affected_rows > 0;
                        $lift->close();

                        if ($lifted && is_file(__DIR__ . '/App/services/NotificationService.php')) {
                            require_once __DIR__ . '/App/services/NotificationService.php';
                            NotificationService::restrictionLifted($con, $uid);
                        }
                    }
                    $status = 'active';
                }
            }
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
            $ends = $con->prepare("
                SELECT MAX(end_date) d FROM restrictions
                WHERE user_id = ? AND end_date >= CURDATE()
            ");
            if ($ends) {
                $ends->bind_param("i", $uid);
                $ends->execute();
                $d = $ends->get_result()->fetch_assoc()['d'] ?? null;
                $ends->close();
                if ($d) {
                    $until = date('F j, Y', strtotime($d));
                }
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
