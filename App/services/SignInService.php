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
            return url('admin-dashboard');
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
