<?php

require_once __DIR__ . '/../config/db.php';

// Session flags are set in Framework/bootstrap.php, which db.php above has
// already loaded — including the lifetime. They used to be repeated here with
// different values (SameSite=Strict, which breaks the Google OAuth callback,
// and a 30-minute lifetime), so wiring this file in would have quietly
// reimposed the timeout that was deliberately removed.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper: destroy session fully and redirect to login
function force_logout(string $redirect): void {
    // Clean up DB token if exists
    global $con;
    if (isset($_SESSION['password_id']) && isset($_SESSION['token'])) {
        $stmt = $con->prepare("UPDATE passwords SET token_id = NULL WHERE password_id = ?");
        $stmt->bind_param("i", $_SESSION['password_id']);
        $stmt->execute();
        $stmt->close();

        $stmt = $con->prepare("DELETE FROM tokens WHERE token = ?");
        $stmt->bind_param("s", $_SESSION['token']);
        $stmt->execute();
        $stmt->close();
    }

    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header("Location: " . $redirect);
    exit;
}

$login_url = url('welcomepage');

// Redirect unauthenticated users
if (!isset($_SESSION['email']) || !isset($_SESSION['user_id'])) {
    force_logout($login_url);
}

// Rotate session ID on every authenticated request (prevents fixation)
session_regenerate_id(true);

// Login tokens no longer expire, so there is no timeout to enforce here. What
// remains is a revocation check: the token this session is holding must still
// be the one the account's password row points at. A password reset deletes
// those rows, so this is what would end other sessions after one — the only
// reason left to sign somebody out against their will.
// Gated on password_id, NOT on $_SESSION['token']. A Google login sets a
// random 'token' too (google-login.php) but never writes a tokens row for it,
// so keying off 'token' would find nothing and sign every Google user out —
// which the version of this check that lived here before would have done.
// Only login.php sets password_id, and only it mints a real token.
if (isset($_SESSION['password_id'], $_SESSION['token'])) {
    $stmt = $con->prepare("
        SELECT p.password_id
        FROM tokens t
        JOIN passwords p ON p.token_id = t.token_id
        WHERE t.token = ? AND p.password_id = ?
    ");
    $stmt->bind_param('si', $_SESSION['token'], $_SESSION['password_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row === null) {
        // Revoked: signed out elsewhere, or a password reset cleared it.
        force_logout($login_url);
    }
}
