<?php

/**
 * RememberService.php — the "Remember me" checkbox on the login page.
 *
 * The cookie holds "selector:validator". Only the selector is stored in the
 * clear; the validator is kept as a SHA-256 hash and checked with
 * hash_equals(), so read access to the database is not enough to forge a
 * login. Every successful use rotates the pair, which means a stolen cookie
 * stops working the moment the real owner comes back.
 *
 * Deliberately NOT hooked into every page: it is consulted on the login page
 * and the landing page, the two places an unauthenticated visitor is sent to.
 * A hook in db.php would run on all ~40 pages for a feature that only matters
 * at the front door.
 */
class RememberService
{
    public const COOKIE = 'pc_remember';
    private const DAYS  = 30;

    /** Cookie flags, shared by the set and clear paths so they cannot drift. */
    private static function cookieOptions(int $expires): array
    {
        return [
            'expires'  => $expires,
            'path'     => '/',
            // Off in local XAMPP (plain http); on automatically once the site
            // is served over TLS, where the cookie must never travel in clear.
            // Same test as the session cookie (Framework/bootstrap.php).
            'secure'   => pc_request_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /** Issue a fresh token for this user and put it in the browser. */
    public static function remember(mysqli $con, int $user_id): void
    {
        self::forget($con);

        $selector  = bin2hex(random_bytes(16));   // 32 chars
        $validator = bin2hex(random_bytes(32));   // 64 chars
        $hash      = hash('sha256', $validator);

        $stmt = $con->prepare("
            INSERT INTO remember_tokens (user_id, selector, validator, expires_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))
        ");
        $days = self::DAYS;
        $stmt->bind_param("issi", $user_id, $selector, $hash, $days);
        $stmt->execute();
        $stmt->close();

        setcookie(self::COOKIE, $selector . ':' . $validator, self::cookieOptions(time() + self::DAYS * 86400));
    }

    /**
     * Log the visitor back in from their cookie, if it checks out.
     * Returns the user_id on success, 0 otherwise. Safe to call on any page:
     * it does nothing when there is no cookie or a session is already open.
     */
    public static function attempt(mysqli $con): int
    {
        if (!empty($_SESSION['user_id']) || empty($_COOKIE[self::COOKIE])) {
            return 0;
        }

        $parts = explode(':', (string)$_COOKIE[self::COOKIE], 2);
        if (count($parts) !== 2 || strlen($parts[0]) !== 32 || strlen($parts[1]) !== 64) {
            self::forget($con);
            return 0;
        }
        [$selector, $validator] = $parts;

        // Expired rows are excluded here and swept below, so a stale cookie
        // can never resurrect an account.
        $stmt = $con->prepare("
            SELECT rt.token_id, rt.user_id, rt.validator, u.role, u.email, u.status, u.verified
            FROM remember_tokens rt
            JOIN users u ON u.user_id = rt.user_id
            WHERE rt.selector = ? AND rt.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->bind_param("s", $selector);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // hash_equals, not ==, so the comparison cannot be timed.
        if (!$row || !hash_equals($row['validator'], hash('sha256', $validator))) {
            self::forget($con);
            return 0;
        }

        // A blocked account must not be able to walk back in on an old cookie.
        if (($row['status'] ?? '') !== 'active') {
            self::forget($con);
            return 0;
        }

        $user_id = (int)$row['user_id'];

        // Only regenerate when there is a session to regenerate — the call
        // warns otherwise, and this runs before session_start() in CLI checks.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $user_id;
        $_SESSION['role']    = $row['role'];
        $_SESSION['email']   = $row['email'];

        // Rotate: the used pair is replaced, so the cookie just presented is
        // now worthless to anyone who copied it.
        $del = $con->prepare("DELETE FROM remember_tokens WHERE token_id = ?");
        $del->bind_param("i", $row['token_id']);
        $del->execute();
        $del->close();
        self::remember($con, $user_id);

        return $user_id;
    }

    /** Drop this browser's token, and clear the cookie. Called on logout too. */
    public static function forget(mysqli $con): void
    {
        if (!empty($_COOKIE[self::COOKIE])) {
            $selector = explode(':', (string)$_COOKIE[self::COOKIE], 2)[0];
            if ($selector !== '') {
                $stmt = $con->prepare("DELETE FROM remember_tokens WHERE selector = ?");
                $stmt->bind_param("s", $selector);
                $stmt->execute();
                $stmt->close();
            }
            unset($_COOKIE[self::COOKIE]);
        }
        // Expired rows have no owner to clean them up, so do it opportunistically.
        $con->query("DELETE FROM remember_tokens WHERE expires_at < NOW()");

        if (!headers_sent()) {
            setcookie(self::COOKIE, '', self::cookieOptions(time() - 42000));
        }
    }
}
