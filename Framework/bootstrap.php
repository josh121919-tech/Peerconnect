<?php

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// SECURITY: never print a fatal to the browser. display_errors was on, so
// any uncaught error handed the visitor absolute server paths, the framework
// layout and a call stack. Errors still go to apache/logs/error.log exactly
// as before — only the browser stops seeing them.
//
// Flip PC_DEBUG to true to get the old behaviour back while working locally.
if (!defined('PC_DEBUG')) {
    define('PC_DEBUG', false);
}

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', PC_DEBUG ? '1' : '0');
ini_set('display_startup_errors', PC_DEBUG ? '1' : '0');

// Every date the app writes or shows is Philippine time, the same clock MySQL's
// NOW() runs on. PHP's own default comes from php.ini, which XAMPP ships as
// Europe/Berlin, and only some pages set Manila themselves — so sign-in entries
// in the activity log were stamped six hours behind the admin entries beside
// them. Set once here, for every request and every script.
date_default_timezone_set('Asia/Manila');

// With display off a fatal would otherwise render as a blank page, which
// tells the visitor nothing. Give them a plain sentence and a 500 instead.
if (!PC_DEBUG) {
    register_shutdown_function(function () {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        if (headers_sent()) {
            // The page already started rendering; a banner is all that is left.
            echo '<div style="margin:24px;padding:14px 16px;border:1px solid #d8d2c8;'
               . 'border-radius:8px;font:14px/1.5 system-ui,sans-serif;color:#5b544a">'
               . 'Something went wrong further down this page. It has been logged.'
               . '</div>';
            return;
        }
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Something went wrong</title></head>'
           . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;'
           . 'justify-content:center;background:#f7f5f1;'
           . 'font:16px/1.6 system-ui,-apple-system,Segoe UI,sans-serif;color:#2f2a24">'
           . '<div style="max-width:30rem;padding:32px;text-align:center">'
           . '<h1 style="margin:0 0 12px;font-size:20px">Something went wrong</h1>'
           . '<p style="margin:0 0 20px;color:#5b544a">This page could not be loaded. '
           . 'The problem has been recorded and nothing you submitted was lost.</p>'
           . '<a href="' . htmlspecialchars(defined('BASE_URL') && BASE_URL !== '' ? BASE_URL : '/') . '" '
           . 'style="color:#1f5c3d;font-weight:600">Back to PeerConnect</a>'
           . '</div></body></html>';
    });
}

if (!defined('APP_PATH')) {
    define('APP_PATH', BASE_PATH . '/App');
}

if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', BASE_PATH . '/public');
}

// SECURITY: session cookie hardening — must run before any session_start()
// call anywhere in the app. Bootstrap is the one file guaranteed to load
// before every request (see App/controllers/session-check.php, which
// documented these settings but was never actually included by any page).
// NOTE: SameSite=Strict (the originally-documented value) breaks the Google
// OAuth login flow — Google's redirect back to google-login.php is a
// cross-site top-level navigation, and Strict cookies are withheld on that,
// so the session holding google_oauth_state is invisible on the callback
// request. Lax still blocks the actual CSRF vectors (cross-site POST/fetch/
// XHR) while allowing top-level GET redirects like OAuth callbacks through —
// confirmed via a real failed Google login (savedState=null in the logs)
// before this change, and a successful login after it.
// How long a signed-in session lasts. Both the server-side session file and
// the browser cookie use this, and they must stay equal: a cookie outliving
// the session points at nothing, and a session outliving the cookie is a file
// no browser can ever present again. Change the one number.
if (!defined('PC_SESSION_DAYS')) {
    define('PC_SESSION_DAYS', 20);
}

/*
 * How long a session may sit untouched before it is closed.
 *
 * This is NOT session.gc_maxlifetime, and must never be moved there. That is
 * what the 30-minute setting below used to be, and PHP's collector deleting a
 * session file underneath a live user is precisely what signed people out
 * mid-lecture. The check this drives lives in pc_idle_gate() in helpers.php:
 * it decides deliberately, on the user's own next request, and can say why.
 *
 * Eight hours outlasts a teaching day, so a forgotten tab closes overnight
 * while nobody is turned out of a session they are still in the middle of.
 * Someone holding a "Remember me" cookie is signed straight back in.
 */
if (!defined('PC_IDLE_HOURS')) {
    define('PC_IDLE_HOURS', 8);
}

// How long after an approved session ends the missed-session detector waits
// before closing it. By then the call has shut (the room stops admitting
// people once the end has passed), and session_attendance records who opened
// it: both people → completed, only one → missed by the other, nobody →
// missed by both. Each person is told what was recorded. Change the one
// number.
//
// It was an hour, from before presence was recorded: the only signal was
// whether somebody had left, so the job waited in case a call had simply
// overrun. It cannot now — the room refuses entry at the end and ping.php
// rejects a heartbeat past it, so every input the outcome is computed from
// is frozen the moment the session ends. Fifteen minutes is not a wait for
// better data; it clears the five-minute tail the room keeps for its
// closing redirect, so nobody is told they missed a session while their own
// browser is still closing it properly.
//
// The job runs every 30 minutes, so this is when a session becomes
// eligible, not when it is actually closed.
if (!defined('PC_MISSED_GRACE_MINUTES')) {
    define('PC_MISSED_GRACE_MINUTES', 15);
}

if (!function_exists('pc_missed_grace_label')) {
    /**
     * The grace as a phrase: "15 minutes", "1 hour", "2 hours".
     *
     * Four screens quote this number at somebody, and each used to spell out
     * its own pluralised hours. Changing the unit meant changing all four,
     * which is exactly the kind of edit one of them gets left out of.
     */
    function pc_missed_grace_label(): string
    {
        $m = (int)PC_MISSED_GRACE_MINUTES;
        if ($m >= 60 && $m % 60 === 0) {
            $h = intdiv($m, 60);
            return $h . ' hour' . ($h === 1 ? '' : 's');
        }
        return $m . ' minute' . ($m === 1 ? '' : 's');
    }
}

if (!function_exists('pc_request_is_https')) {
    /**
     * Whether this request arrived over HTTPS. X-Forwarded-Proto covers a host
     * that ends TLS at a proxy in front of PHP. Trusting it is safe for the one
     * thing it decides here, the Secure cookie flag: a client that lies about it
     * only stops its own cookie coming back over plain HTTP.
     */
    function pc_request_is_https(?array $server = null): bool
    {
        $server = $server ?? $_SERVER;
        return (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off')
            || strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}

// The PHP version is nobody's business; it only helps someone pick an exploit.
if (PHP_SAPI !== 'cli') {
    header_remove('X-Powered-By');
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    // Over HTTPS the session cookie must never be sent back over plain HTTP,
    // where anyone on the network could read the session id. Off on a local
    // http:// install, where a Secure cookie would never come back at all.
    ini_set('session.cookie_secure', pc_request_is_https() ? '1' : '0');

    $pc_session_seconds = PC_SESSION_DAYS * 24 * 60 * 60;

    // No idle timeout. This was 1800 (30 minutes), which is what actually
    // signed people out mid-use: leave a tab alone through a lecture and PHP's
    // collector was free to delete the session file underneath them.
    //
    // PHP's collector keys off the session file's mtime, which every request
    // touches, so this side of it behaves as 20 days of INACTIVITY.
    ini_set('session.gc_maxlifetime', (string) $pc_session_seconds);

    // A persistent cookie, so closing the browser no longer signs you out.
    //
    // Unlike gc_maxlifetime above, this does NOT slide: the browser stamps the
    // expiry when the cookie is issued at login, so it is 20 days from signing
    // in, not 20 days from last use. There is no single hook that runs after
    // session_start() on every page here to re-stamp it, and adding one would
    // mean editing every entry point. The rolling case is already covered by
    // "Remember me", whose token rotates on each use — see RememberService.
    ini_set('session.cookie_lifetime', (string) $pc_session_seconds);
}

if (!defined('APP_BOOTSTRAPPED')) {
    $autoload = BASE_PATH . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    // The app's own classes: App/repositories (where pages get their data) and
    // App/services (business rules). A page can call SessionRepository::... or
    // NotificationService::... without requiring the file first. The explicit
    // require_once lines that already exist still work alongside this.
    spl_autoload_register(function (string $class): void {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $class)) {
            return;   // namespaced classes belong to Composer's loader above
        }
        foreach (['repositories', 'services'] as $dir) {
            $file = BASE_PATH . '/App/' . $dir . '/' . $class . '.php';
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    });

    // .env must load before helpers.php below — helpers.php reads getenv()
    // to fill in its define()'d constants (DAILY_API_KEY, JAAS_*, etc.), and
    // those reads only see .env values if Dotenv has already run. Loading
    // helpers.php first (the previous order) meant every getenv() call in it
    // silently saw nothing, so .env could never actually override those
    // constants — only their hardcoded fallbacks ever took effect.
    if (class_exists('Dotenv\\Dotenv') && file_exists(BASE_PATH . '/.env')) {
        Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
    }

    define('APP_BOOTSTRAPPED', true);
}

require_once BASE_PATH . '/helpers.php';

// An https:// APP_URL moves plain-HTTP visitors to HTTPS and sends HSTS.
// Nothing happens for an http:// APP_URL. See pc_https_decision().
pc_enforce_https();
