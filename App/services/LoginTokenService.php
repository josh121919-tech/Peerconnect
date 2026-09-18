<?php

/**
 * LoginTokenService.php — the short-lived session token in the `tokens` table.
 *
 * Every password login mints one of these and points the account's `passwords`
 * row at it. It is not the session itself (PHP handles that) and nothing
 * currently validates it per request — see the note at the bottom.
 *
 * This exists because the lifecycle used to be split across two files with no
 * owner for the middle of it:
 *
 *   - login.php inserted a row and repointed passwords.token_id at it, which
 *     silently orphaned whatever the previous login had left there;
 *   - logout.php deleted the row, but only when someone actually clicked Log
 *     Out. A closed tab, an expired session or a second login left the row
 *     behind for good.
 *
 * The result was a table that only ever grew: 110 rows against 13 accounts,
 * every one of them expired, the oldest from five months back. issue() now
 * retires the previous row instead of orphaning it, which bounds the table at
 * one row per account on its own; sweep() collects what is left when an
 * account is deleted.
 *
 * These tokens do NOT expire. Nothing times a signed-in person out — see the
 * session lifetime in Framework/bootstrap.php, which was raised to match.
 */
class LoginTokenService
{
    /**
     * Orphans younger than this are left alone.
     *
     * The sweep collects tokens that no `passwords` row points at. issue()
     * inserts the row and sets that pointer as two statements, so for a moment
     * a perfectly good new token looks like an orphan — a sweep running on a
     * different request in that window would delete it and the person would be
     * signed in holding a token that no longer exists. Ignoring anything
     * recent removes that race entirely; a day is far longer than the gap.
     */
    private const ORPHAN_GRACE_HOURS = 24;

    /**
     * Mint a token for this password row, replacing any it already had.
     *
     * The token does not expire. Nothing times a signed-in person out, so the
     * only things that end a session are logging out, the browser's own
     * session cookie ending, or a password reset.
     *
     * @return string the token to put in the session
     */
    public static function issue(mysqli $con, int $password_id): string
    {
        // Retire the row this account was pointing at. Without this, logging
        // in twice leaves the first row with nothing referencing it and no
        // code path that would ever remove it.
        self::revokeFor($con, $password_id);

        $token = bin2hex(random_bytes(32));

        // created_at is filled by MySQL's own clock, not PHP's. This install
        // runs PHP on Europe/Berlin and MySQL on Asia/Shanghai, six hours
        // apart, so a PHP-written timestamp compared against NOW() reads hours
        // out. Letting the column default keeps the written value and every
        // later comparison on one clock.
        $stmt = $con->prepare("INSERT INTO tokens (token) VALUES (?)");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $token_id = $stmt->insert_id;
        $stmt->close();

        $stmt = $con->prepare("UPDATE passwords SET token_id = ? WHERE password_id = ?");
        $stmt->bind_param("ii", $token_id, $password_id);
        $stmt->execute();
        $stmt->close();

        // Deleting an account leaves its token behind (the foreign key runs
        // the other way), so something has to collect those.
        self::sweep($con);

        return $token;
    }

    /**
     * Whether $token is still the one the password row points at. It stops
     * being so when the account signs in again elsewhere or resets its password.
     */
    public static function isCurrent(mysqli $con, string $token, int $password_id): bool
    {
        $stmt = $con->prepare("
            SELECT p.password_id
            FROM tokens t
            JOIN passwords p ON p.token_id = t.token_id
            WHERE t.token = ? AND p.password_id = ?
        ");
        $stmt->bind_param('si', $token, $password_id);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        return $found;
    }

    /**
     * Drop the token this session was using. Safe to call with either half
     * missing — a Google login, for instance, sets no password token at all.
     */
    public static function revoke(mysqli $con, ?int $password_id, ?string $token): void
    {
        if ($password_id !== null) {
            $stmt = $con->prepare("UPDATE passwords SET token_id = NULL WHERE password_id = ?");
            $stmt->bind_param("i", $password_id);
            $stmt->execute();
            $stmt->close();
        }

        if ($token !== null && $token !== '') {
            $stmt = $con->prepare("DELETE FROM tokens WHERE token = ?");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $stmt->close();
        }

        self::sweep($con);
    }

    /**
     * Collect tokens that nothing points at any more.
     *
     * Tokens no longer expire, so age alone is never a reason to delete one —
     * a person signed in since last month still holds a valid token. What can
     * be collected is a row with no `passwords` row referencing it, which no
     * session can ever present successfully. In practice those come from
     * deleted accounts.
     */
    public static function sweep(mysqli $con): int
    {
        $stmt = $con->prepare("
            DELETE t FROM tokens t
            LEFT JOIN passwords p ON p.token_id = t.token_id
            WHERE p.password_id IS NULL
              AND t.created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $grace = self::ORPHAN_GRACE_HOURS;
        $stmt->bind_param("i", $grace);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        return $deleted;
    }

    /**
     * Delete whichever token $password_id currently references.
     * Separate from revoke() because issue() knows the password row but not
     * the old token string.
     */
    private static function revokeFor(mysqli $con, int $password_id): void
    {
        $stmt = $con->prepare("SELECT token_id FROM passwords WHERE password_id = ? AND token_id IS NOT NULL");
        $stmt->bind_param("i", $password_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return;
        }

        // Clearing the pointer first keeps the row consistent even if the
        // delete below is rolled back by an outer transaction.
        $stmt = $con->prepare("UPDATE passwords SET token_id = NULL WHERE password_id = ?");
        $stmt->bind_param("i", $password_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $con->prepare("DELETE FROM tokens WHERE token_id = ?");
        $stmt->bind_param("i", $row['token_id']);
        $stmt->execute();
        $stmt->close();
    }
}

/*
 * NOTE on what this table is for.
 *
 * Nothing reads these rows on a normal request. session-check.php is the only
 * code that validates one, and no page includes it — it is registered as a
 * route and never called. So the token is written, stored and deleted without
 * ever gating anything.
 *
 * That is worth deciding on deliberately rather than leaving as it is. Either
 * wire session-check.php into the pages that need it, at which point these
 * rows start doing real work (and a password reset would then also kick other
 * devices), or drop the table and the column with it. Until one of those
 * happens this service just keeps the garbage from piling up.
 */
