<?php

/**
 * SignInService — what the password and Google sign-ins share: where a
 * signed-in account is sent, and which accounts may not sign in at all.
 */
class SignInService
{
    /** Shown to a blocked account that proved it is theirs, by password or by Google. */
    public const BLOCKED_MESSAGE = 'Your account has been blocked. Please contact the administrator.';

    /** Where a signed-in account belongs, given its role, status and verification. */
    public static function destination(string $role, ?string $status, $verified): string
    {
        // Admins have no verification step and their own dashboard. Falling
        // through the mentor/mentee choice sent them to the mentee dashboard,
        // which bounces them straight back out again.
        if ($role === 'admin') {
            return url('admin-dashboard');
        }
        if ($status !== 'active' || !$verified) {
            return url($role === 'mentor' ? 'mentor-verification' : 'mentee-verification');
        }
        return url($role === 'mentor' ? 'mentor-dashboard' : 'mentee-dashboard');
    }
}
