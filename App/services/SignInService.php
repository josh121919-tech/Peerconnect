<?php

/**
 * SignInService — what the password and Google sign-ins share: where a
 * signed-in account is sent, and which accounts may not sign in at all.
 */
class SignInService
{
    /** Shown to a blocked account that proved it is theirs, by password or by Google. */
    public const BLOCKED_MESSAGE = 'Your account has been blocked. Please contact the administrator.';

    /**
     * Where a signed-in account belongs, given how far through registration
     * it is: address confirmed, then approved by an admin, then the dashboard.
     *
     * This decides where to *send* somebody. It does not decide what they may
     * reach — pc_verification_gate() does that, on every request. Keeping the
     * two in step matters: when this function alone was the check, typing the
     * dashboard URL walked straight past it.
     *
     * @param mysqli|null $con          needed only to answer the email stage;
     *                                  callers without one get the old
     *                                  status/verified answer, which is never
     *                                  more permissive.
     * @param mixed       $confirmedAt  users.email_verified_at, or null
     */
    /**
     * Said to someone whose password is right but whose account is an admin's.
     *
     * Only shown after the password has been verified, so it tells the owner
     * of the account something they already know and tells a stranger nothing
     * — the same rule the blocked-account message follows.
     */
    public const ADMIN_DOOR_MESSAGE =
        'That is an administrator account. Please sign in through the administrator page.';

    /**
     * Why this role may not come in through the member door, or null if it may.
     *
     * The member sign-in reads accounts with signInByEmail(), which does not
     * filter on the role, so administrator credentials worked there and
     * destination() then forwarded them to the admin dashboard. The admin page
     * has always been the other way round — adminSignInByEmail() joins on
     * role = 'admin' — and the Google controller already refused a member who
     * arrived through the admin side. This is the missing half of that pair.
     *
     * A function rather than an inline check because three doors share it:
     * the password form, the remembered cookie and the Google callback. It is
     * also the only part of this that can be tested directly — the form itself
     * is behind a CAPTCHA, as it should be.
     */
    public static function memberDoorRefusal(?string $role): ?string
    {
        return $role === 'admin' ? self::ADMIN_DOOR_MESSAGE : null;
    }

    public static function destination(
        string $role,
        ?string $status,
        $verified,
        ?mysqli $con = null,
        $confirmedAt = null
    ): string {
        // Admins have no verification step and their own dashboard. Falling
        // through the mentor/mentee choice sent them to the mentee dashboard,
        // which bounces them straight back out again.
        if ($role === 'admin') {
            // An administrator has no email stage, but does now have a review:
            // the key and the emailed code prove the invitation, not who is
            // holding it. Until somebody approves them they get the form and
            // nothing else. See pc_verification_gate().
            return $verified ? url('admin-dashboard') : url('admin-verification');
        }
        if ($con !== null && EmailVerificationService::pendingFor($con, $role, $confirmedAt)) {
            return url('email-pending');
        }
        if ($status !== 'active' || !$verified) {
            return url($role === 'mentor' ? 'mentor-verification' : 'mentee-verification');
        }
        return url($role === 'mentor' ? 'mentor-dashboard' : 'mentee-dashboard');
    }
}
