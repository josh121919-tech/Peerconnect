<?php

require_once __DIR__ . '/EmailService.php';

/**
 * EmailVerificationService — the email-confirmation stage of signup.
 *
 * Signing up proves somebody can type an address. It does not prove they can
 * read it. This is the step that does: a link goes to the address, and the
 * account stays out of the app until somebody follows it.
 *
 * Same split-token shape as PasswordResetService: the link carries
 * "selector:validator", only the selector is stored in the clear, the
 * validator is kept as a SHA-256 hash and compared with hash_equals(). Read
 * access to the database is not enough to forge a confirmation link.
 *
 * This is the FIRST of two gates. Confirming the address gets the member to
 * the identity form; an admin approving that form (users.verified) is what
 * opens the dashboard. Neither one implies the other.
 */
class EmailVerificationService
{
    /**
     * How long a link stays usable.
     *
     * A day, not the hour a password reset gets. A reset is something you
     * asked for and are waiting on; a signup confirmation competes with a
     * spam folder and a student who registered between classes.
     */
    public const TTL_MINUTES = 1440;

    /** At most this many links per account inside WINDOW_MINUTES. */
    private const MAX_REQUESTS   = 3;
    private const WINDOW_MINUTES = 15;

    /**
     * Whether this install can ask anybody to confirm an address.
     *
     * False in two cases, and in both the stage stands aside rather than
     * trapping people behind a letter that will never arrive:
     *
     *   - email_verification.sql has not been run, so there is nowhere to
     *     record the answer;
     *   - outbound mail is off (System Settings → Email) or SMTP was never
     *     configured, so the link cannot be delivered.
     *
     * Standing aside is the safe direction because it only skips the address
     * check. Admin approval is a separate gate and is never skipped, so an
     * install with mail switched off still has a human between registration
     * and the dashboard.
     */
    public static function available(mysqli $con): bool
    {
        return EmailVerificationRepository::migrated($con)
            && pc_setting_bool($con, 'email_enable')
            && EmailService::isConfigured();
    }

    /**
     * Whether this account still owes us a confirmed address.
     *
     * Admins never do: they are created by invitation through admin/signup.php,
     * which already proves the address by requiring the invite key.
     */
    public static function isPending(mysqli $con, int $userId, string $role): bool
    {
        if ($role === 'admin' || !self::available($con)) {
            return false;
        }
        return EmailVerificationRepository::confirmedAt($con, $userId) === null;
    }

    /**
     * The same question for a caller that already holds the stamp.
     *
     * pc_enforce_account_status() runs on every authenticated request and has
     * email_verified_at in the row it already read, so it asks this way rather
     * than paying for a second lookup ninety times a page-load.
     */
    public static function pendingFor(mysqli $con, string $role, $confirmedAt): bool
    {
        if ($role === 'admin' || !self::available($con)) {
            return false;
        }
        return $confirmedAt === null || $confirmedAt === '';
    }

    /**
     * Sends a confirmation link to whoever owns $userId.
     *
     * @return array{sent:bool, throttled:bool, skipped:bool, error:?string}
     *   sent       a mail actually went out
     *   throttled  this account asked too many times just now
     *   skipped    the stage is not available on this install (see available())
     *   error      a real delivery failure, for the server log only
     */
    public static function send(mysqli $con, int $userId): array
    {
        $out = ['sent' => false, 'throttled' => false, 'skipped' => false, 'error' => null];

        if (!self::available($con)) {
            $out['skipped'] = true;
            return $out;
        }

        $user = EmailVerificationRepository::recipient($con, $userId);
        $to   = (string) ($user['email'] ?? '');

        // A null recipient is a fatal TypeError inside EmailService::send(),
        // and a signup must never take the page down. Fail quietly the way an
        // unknown address fails.
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log('Email verification: no usable address for user ' . $userId);
            $out['error'] = 'no address on file';
            return $out;
        }

        if (EmailVerificationRepository::recentRequests($con, $userId, self::WINDOW_MINUTES) >= self::MAX_REQUESTS) {
            $out['throttled'] = true;
            return $out;
        }

        [$selector, $validator] = self::issue($con, $userId);

        $link = pc_site_url(ltrim(url('verify-email'), '/'))
            . '?s=' . urlencode($selector) . '&t=' . urlencode($validator);

        $result = EmailService::send(
            $to,
            'Confirm your email address — PeerConnect',
            self::emailHtml(trim((string) ($user['firstname'] ?? '')), $link)
        );

        if (!$result['success']) {
            error_log('Verification email failed for user ' . $userId . ': ' . $result['error']);
            $out['error'] = $result['error'];
            return $out;
        }

        $out['sent'] = true;
        return $out;
    }

    /**
     * Follows a link: confirms the address it belongs to.
     *
     * @return array{ok:bool, reason:string, user_id:?int, role:?string}
     *   reason is one of 'confirmed', 'already', 'expired', 'unknown',
     *   'blocked' or 'unavailable' — what the page tells the person.
     */
    public static function confirm(mysqli $con, string $selector, string $validator): array
    {
        $no = fn(string $why): array => ['ok' => false, 'reason' => $why, 'user_id' => null, 'role' => null];

        if (!EmailVerificationRepository::migrated($con)) {
            return $no('unavailable');
        }

        // Cheap shape check first, so a malformed query string never reaches
        // the database at all.
        if (strlen($selector) !== 32 || strlen($validator) !== 64
            || !ctype_xdigit($selector) || !ctype_xdigit($validator)) {
            return $no('unknown');
        }

        $row = EmailVerificationRepository::bySelector($con, $selector);

        if (!$row) {
            return $no('unknown');
        }

        // Compare in constant time, and before saying anything at all about
        // the account. A plain === here leaks the validator one byte at a time
        // to anyone willing to measure, and answering ahead of this check
        // would tell the holder of a bare selector whose account it is.
        if (!hash_equals((string) $row['validator'], hash('sha256', $validator))) {
            return $no('unknown');
        }

        if (($row['status'] ?? '') === 'blocked') {
            return $no('blocked');
        }

        $userId = (int) $row['user_id'];
        $role   = (string) ($row['role'] ?? '');

        /*
         * Already confirmed. Checked BEFORE the link's own state, because a
         * spent link on a confirmed account is the ordinary case: the person
         * clicked it, it worked, and then they — or their mail client, which
         * fetches links by itself — opened it again. Reading used_at first
         * told them the link had expired, on an account that was perfectly
         * fine, which is alarming and wrong.
         */
        if (!empty($row['email_verified_at'])) {
            return ['ok' => true, 'reason' => 'already', 'user_id' => $userId, 'role' => $role];
        }

        // Not confirmed, so the link's own state decides. Spent-but-unconfirmed
        // means a newer link retired this one; both cases want another letter.
        if ($row['used_at'] !== null || !$row['still_fresh']) {
            return $no('expired');
        }

        // Claim it before stamping the account, so two tabs cannot both spend
        // it. The loser is told 'already', which is true by the time it reads.
        if (EmailVerificationRepository::claim($con, (int) $row['verification_id']) < 1) {
            return ['ok' => true, 'reason' => 'already', 'user_id' => $userId, 'role' => $role];
        }

        EmailVerificationRepository::markConfirmed($con, $userId);
        EmailVerificationRepository::retireOutstanding($con, $userId);

        // One in fifty callers pays for the tidy-up. There is no cron for this
        // table and it is only written at signup, so it stays small anyway.
        if (random_int(1, 50) === 1) {
            EmailVerificationRepository::purge($con);
        }

        return ['ok' => true, 'reason' => 'confirmed', 'user_id' => $userId, 'role' => $role];
    }

    // ── internals ──────────────────────────────────────────────────────

    /** @return array{0:string, 1:string} selector, validator */
    private static function issue(mysqli $con, int $userId): array
    {
        // Only the newest link may work, so retire the rest first. Somebody
        // who clicks Resend twice should not find the older letter still live.
        EmailVerificationRepository::retireOutstanding($con, $userId);

        $selector  = bin2hex(random_bytes(16));   // 32 chars
        $validator = bin2hex(random_bytes(32));   // 64 chars

        EmailVerificationRepository::issue($con, $userId, $selector, hash('sha256', $validator), self::TTL_MINUTES);

        return [$selector, $validator];
    }

    /**
     * A confirmation mail, not a notification — the shared notification
     * template is headed "You have a new notification", which would be wrong
     * for the first letter an account ever receives.
     */
    private static function emailHtml(string $firstname, string $link): string
    {
        $hello    = $firstname !== '' ? 'Hi ' . htmlspecialchars($firstname, ENT_QUOTES) . ',' : 'Hi,';
        $safeLink = htmlspecialchars($link, ENT_QUOTES);
        $hours    = (int) round(self::TTL_MINUTES / 60);

        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 16px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;max-width:480px;width:100%;">'
            . '<tr><td style="background:#071B4D;padding:20px 28px;">'
            . '<span style="color:#ffffff;font-size:18px;font-weight:700;">PeerConnect</span>'
            . '</td></tr>'
            . '<tr><td style="padding:28px;">'
            . '<p style="margin:0 0 4px;color:#8a8f98;font-size:12px;text-transform:uppercase;letter-spacing:.06em;">Confirm your email</p>'
            . '<h1 style="margin:0 0 14px;color:#071B4D;font-size:20px;">One click and your account is live</h1>'
            . '<p style="margin:0 0 18px;color:#3f3f46;font-size:14px;line-height:1.6;">' . $hello . '<br><br>'
            . 'Thanks for registering with PeerConnect. Confirm this address so we know we can reach you. '
            . 'The link below expires in ' . $hours . ' hours.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="padding:4px 0 0;">'
            . '<a href="' . $safeLink . '" style="display:inline-block;background:#071B4D;color:#ffffff;'
            . 'text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px;">'
            . 'Confirm my email</a>'
            . '</td></tr></table>'
            . '<p style="margin:18px 0 0;color:#8a8f98;font-size:12.5px;line-height:1.6;">'
            . 'If the button does not work, copy this address into your browser:<br>'
            . '<span style="color:#3f3f46;word-break:break-all;">' . $safeLink . '</span></p>'
            . '<p style="margin:18px 0 0;color:#3f3f46;font-size:13px;line-height:1.6;">'
            . '<strong>What happens next.</strong> After you confirm, submit your student ID and credential for '
            . 'verification. An administrator reviews it and you will be notified either way.</p>'
            . '<p style="margin:14px 0 0;color:#3f3f46;font-size:13px;line-height:1.6;">'
            . '<strong>Did not register?</strong> You can ignore this email &mdash; the account stays locked '
            . 'and the link above will expire on its own.</p>'
            . '</td></tr>'
            . '<tr><td style="padding:18px 28px;background:#f9fafb;border-top:1px solid #eceef1;">'
            . '<p style="margin:0;color:#9aa0a6;font-size:11.5px;">This is an automated message from PeerConnect. Please do not reply to this email.</p>'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
