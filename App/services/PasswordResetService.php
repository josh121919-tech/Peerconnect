<?php

require_once __DIR__ . '/EmailService.php';

/**
 * PasswordResetService.php — the "Forgot password?" flow.
 *
 * Same split-token shape as RememberService: the link carries
 * "selector:validator", only the selector is stored in the clear, and the
 * validator is kept as a SHA-256 hash and compared with hash_equals(). Read
 * access to the database is therefore not enough to forge a reset link.
 *
 * Three things this deliberately does NOT do:
 *
 *  - It never tells the caller whether an address has an account. request()
 *    returns the same shape either way; the page shows one message for both.
 *  - It never deletes a used or superseded row. A deleted row and an expired
 *    row look identical on lookup, and the throttle below counts history.
 *  - It never logs the validator, only the selector, so a leaked application
 *    log cannot be replayed either.
 */
class PasswordResetService
{
    /** How long a link stays usable. */
    private const TTL_MINUTES = 60;

    /** At most this many requests per account inside WINDOW_MINUTES. */
    private const MAX_REQUESTS   = 3;
    private const WINDOW_MINUTES = 15;

    /**
     * Start a reset for whoever owns $email, if anyone does.
     *
     * @return array{sent:bool, throttled:bool, error:?string}
     *   sent      a mail actually went out (never surfaced verbatim to the
     *             visitor — see forgot_password.php)
     *   throttled the account asked too many times just now
     *   error     a real delivery failure, for the server log only
     */
    public static function request(mysqli $con, string $email): array
    {
        $user = self::findUser($con, $email);

        // No account, or one that cannot sign in anyway. Nothing to send, and
        // nothing the caller may reveal.
        if (!$user || $user['status'] === 'blocked') {
            return ['sent' => false, 'throttled' => false, 'error' => null];
        }

        // Belt and braces. findUser() returns the matched address, so this
        // should be impossible — but a null recipient is a fatal TypeError in
        // EmailService::send(), and a reset request must never take the page
        // down. Fail the way an unknown address fails.
        $to = (string) ($user['email'] ?? '');
        if ($to === '') {
            error_log('Password reset: no usable address for user ' . $user['user_id']);
            return ['sent' => false, 'throttled' => false, 'error' => 'no address on file'];
        }

        $user_id = (int) $user['user_id'];

        if (self::recentRequests($con, $user_id) >= self::MAX_REQUESTS) {
            return ['sent' => false, 'throttled' => true, 'error' => null];
        }

        [$selector, $validator] = self::issue($con, $user_id);

        $link = pc_site_url(ltrim(url('reset-password'), '/'))
            . '?s=' . urlencode($selector) . '&t=' . urlencode($validator);

        $name = trim((string) ($user['firstname'] ?? ''));
        $result = EmailService::send(
            $to,
            'Reset your PeerConnect password',
            self::emailHtml($name, $link)
        );

        if (!$result['success']) {
            error_log('Password reset email failed for user ' . $user_id . ': ' . $result['error']);
            return ['sent' => false, 'throttled' => false, 'error' => $result['error']];
        }

        return ['sent' => true, 'throttled' => false, 'error' => null];
    }

    /**
     * The account a reset link belongs to, or null when the link is unknown,
     * already used, or past its hour.
     *
     * @return array{reset_id:int, user_id:int, email:string, firstname:string}|null
     */
    public static function lookup(mysqli $con, string $selector, string $validator): ?array
    {
        // Cheap shape check first, so a malformed query string never reaches
        // the database at all.
        if (strlen($selector) !== 32 || strlen($validator) !== 64
            || !ctype_xdigit($selector) || !ctype_xdigit($validator)) {
            return null;
        }

        $stmt = $con->prepare("
            SELECT pr.reset_id, pr.user_id, pr.validator, u.email, u.firstname, u.status
            FROM password_resets pr
            JOIN users u ON u.user_id = pr.user_id
            WHERE pr.selector = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->bind_param("s", $selector);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !hash_equals($row['validator'], hash('sha256', $validator))) {
            return null;
        }
        if ($row['status'] === 'blocked') {
            return null;
        }

        return [
            'reset_id'  => (int) $row['reset_id'],
            'user_id'   => (int) $row['user_id'],
            'email'     => (string) $row['email'],
            'firstname' => (string) $row['firstname'],
        ];
    }

    /**
     * Set the new password and burn everything the old one could still open.
     *
     * Runs as one transaction: either the password changes and every session
     * key dies with it, or nothing moves.
     */
    public static function complete(mysqli $con, int $reset_id, int $user_id, string $plain): bool
    {
        $hash = password_hash($plain, PASSWORD_BCRYPT);

        $con->begin_transaction();
        try {
            // Re-check inside the transaction. Two tabs submitting the same
            // link at once must not both succeed.
            $stmt = $con->prepare("
                UPDATE password_resets SET used_at = NOW()
                WHERE reset_id = ? AND used_at IS NULL AND expires_at > NOW()
            ");
            $stmt->bind_param("i", $reset_id);
            $stmt->execute();
            $claimed = $stmt->affected_rows === 1;
            $stmt->close();

            if (!$claimed) {
                $con->rollback();
                return false;
            }

            // Every account has a passwords row (signup and Google sign-up both
            // create one), but an UPDATE that matched nothing would silently
            // leave the old password in place, so insert if it is ever missing.
            $stmt = $con->prepare("UPDATE passwords SET password_hash = ? WHERE user_id = ?");
            $stmt->bind_param("si", $hash, $user_id);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            if ($changed === 0) {
                $exists = $con->prepare("SELECT 1 FROM passwords WHERE user_id = ? LIMIT 1");
                $exists->bind_param("i", $user_id);
                $exists->execute();
                $exists->store_result();
                $has_row = $exists->num_rows > 0;
                $exists->close();

                if (!$has_row) {
                    $ins = $con->prepare("INSERT INTO passwords (user_id, password_hash) VALUES (?, ?)");
                    $ins->bind_param("is", $user_id, $hash);
                    $ins->execute();
                    $ins->close();
                }
                // affected_rows can also be 0 because the new hash equals the
                // stored one, which bcrypt's per-hash salt makes impossible in
                // practice. Either way the row now holds the new password.
            }

            // Anything else that could still open the account goes too: other
            // outstanding reset links and every "Remember me" cookie.
            //
            // NOTE: the `tokens` rows are cleared for tidiness, not security.
            // Nothing checks them per request — session-check.php is the only
            // reader and no page includes it — so a browser with a live PHP
            // session stays signed in until that session expires. Wire
            // session-check.php in if resets should kick other devices too.
            $stmt = $con->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $con->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $con->prepare("
                DELETE t FROM tokens t
                JOIN passwords p ON p.token_id = t.token_id
                WHERE p.user_id = ?
            ");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            $con->commit();
            return true;
        } catch (Throwable $e) {
            $con->rollback();
            error_log('Password reset failed for user ' . $user_id . ': ' . $e->getMessage());
            return false;
        }
    }

    /** Old rows have no owner to tidy them; sweep them opportunistically. */
    public static function purge(mysqli $con): void
    {
        $con->query("DELETE FROM password_resets WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    }

    // ── internals ──────────────────────────────────────────────────────

    /**
     * users.email is where addresses live now; the emails table is the
     * historic home. Login checks both, so this must too, or an older account
     * could sign in but never reset.
     */
    private static function findUser(mysqli $con, string $email): ?array
    {
        // Each branch returns the address it MATCHED on, not u.email. A genuine
        // legacy account has users.email NULL and its address only in `emails`;
        // selecting u.email in both branches returned NULL for exactly those
        // accounts, which then reached EmailService::send() as a null recipient.
        $stmt = $con->prepare("
            SELECT u.user_id, u.email AS email, u.firstname, u.status
            FROM users u WHERE u.email = ?
            UNION
            SELECT u.user_id, e.email AS email, u.firstname, u.status
            FROM emails e JOIN users u ON u.user_id = e.user_id WHERE e.email = ?
            LIMIT 1
        ");
        $stmt->bind_param("ss", $email, $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private static function recentRequests(mysqli $con, int $user_id): int
    {
        $stmt = $con->prepare("
            SELECT COUNT(*) c FROM password_resets
            WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $window = self::WINDOW_MINUTES;
        $stmt->bind_param("ii", $user_id, $window);
        $stmt->execute();
        $c = (int) $stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        return $c;
    }

    /** @return array{0:string, 1:string} selector, validator */
    private static function issue(mysqli $con, int $user_id): array
    {
        // Only the newest link may work, so retire the rest first.
        $stmt = $con->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $selector  = bin2hex(random_bytes(16));   // 32 chars
        $validator = bin2hex(random_bytes(32));   // 64 chars
        $hash      = hash('sha256', $validator);

        $stmt = $con->prepare("
            INSERT INTO password_resets (user_id, selector, validator, expires_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))
        ");
        $ttl = self::TTL_MINUTES;
        $stmt->bind_param("issi", $user_id, $selector, $hash, $ttl);
        $stmt->execute();
        $stmt->close();

        return [$selector, $validator];
    }

    /**
     * A reset mail, not a notification — the shared notification template is
     * headed "You have a new notification", which would be wrong here.
     */
    private static function emailHtml(string $firstname, string $link): string
    {
        $hello    = $firstname !== '' ? 'Hi ' . htmlspecialchars($firstname, ENT_QUOTES) . ',' : 'Hi,';
        $safeLink = htmlspecialchars($link, ENT_QUOTES);
        $minutes  = self::TTL_MINUTES;

        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 16px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;max-width:480px;width:100%;">'
            . '<tr><td style="background:#023047;padding:20px 28px;">'
            . '<span style="color:#ffffff;font-size:18px;font-weight:700;">PeerConnect</span>'
            . '</td></tr>'
            . '<tr><td style="padding:28px;">'
            . '<p style="margin:0 0 4px;color:#8a8f98;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">Password reset</p>'
            . '<h1 style="margin:0 0 14px;color:#023047;font-size:20px;">Choose a new password</h1>'
            . '<p style="margin:0 0 18px;color:#3f3f46;font-size:14px;line-height:1.6;">' . $hello . '<br><br>'
            . 'We received a request to reset the password for your PeerConnect account. '
            . 'Click the button below to choose a new one. This link expires in ' . $minutes . ' minutes '
            . 'and can only be used once.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="padding:4px 0 0;">'
            . '<a href="' . $safeLink . '" style="display:inline-block;background:#023047;color:#ffffff;'
            . 'text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px;">'
            . 'Reset my password</a>'
            . '</td></tr></table>'
            . '<p style="margin:18px 0 0;color:#8a8f98;font-size:12.5px;line-height:1.6;">'
            . 'If the button does not work, copy this address into your browser:<br>'
            . '<span style="color:#3f3f46;word-break:break-all;">' . $safeLink . '</span></p>'
            . '<p style="margin:18px 0 0;color:#3f3f46;font-size:13px;line-height:1.6;">'
            . '<strong>Did not request this?</strong> You can ignore this email &mdash; your password stays as it is, '
            . 'and the link above will expire on its own.</p>'
            . '</td></tr>'
            . '<tr><td style="padding:18px 28px;background:#f9fafb;border-top:1px solid #eceef1;">'
            . '<p style="margin:0;color:#9aa0a6;font-size:11.5px;">This is an automated message from PeerConnect. Please do not reply to this email.</p>'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
