<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/RememberService.php';
require_once __DIR__ . '/../services/LoginTokenService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Logging out has to drop the "Remember me" token too, or the next visit to
// the login page would sign the person straight back in.
RememberService::forget($con);

// Drop this session's login token. Called unconditionally now: it used to be
// guarded on BOTH session keys being present, so a Google login (which sets
// a token but no password_id) skipped the sweep entirely. revoke() handles
// either half being absent and collects expired rows on the way past.
LoginTokenService::revoke(
    $con,
    isset($_SESSION['password_id']) ? (int)$_SESSION['password_id'] : null,
    $_SESSION['token'] ?? null
);

// Where to send them afterwards, read BEFORE the session is destroyed.
// Admins sign in on their own page, so dropping them on the public landing
// page left them with no obvious way back in.
$was_admin = ($_SESSION['role'] ?? '') === 'admin';

/*
 * ?to= lets a page choose where signing out lands, for the screens where the
 * landing page is the wrong answer — the "waiting for verification" screen
 * sends people to the login form, because that is what they came to find.
 *
 * Only these names are honoured, and each is turned into a URL by url() here.
 * Taking a path or a URL from the query string instead would make this an
 * open redirect: a link to our own logout that quietly lands on somebody
 * else's site, wearing our domain.
 */
$allowed_to = ['login', 'welcomepage', 'admin-login'];
$to         = is_string($_GET['to'] ?? null) ? $_GET['to'] : '';
$after      = in_array($to, $allowed_to, true)
    ? $to
    : ($was_admin ? 'admin-login' : 'welcomepage');

// Log the logout event before destroying session
if (isset($_SESSION['email'])) {
    logMe($_SESSION['email'], date('Y-m-d H:i:s'), $was_admin ? 'admin logout' : 'user logout');
}

// Fully destroy the session
$_SESSION = [];

// Expire the session cookie immediately
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Start a fresh empty session so the welcome page works cleanly
session_start();
session_regenerate_id(true);

header("Location: " . url($after));
exit;
