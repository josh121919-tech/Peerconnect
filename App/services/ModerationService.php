<?php

/**
 * ModerationService — the rules for blocks and restrictions that more than
 * one place applies: the admin actions, the check every signed-in request
 * runs (pc_enforce_account_status) and the maintenance job.
 *
 * The newest restriction decides. "Change restriction" adds a row rather than
 * editing one, and while any running row counted, a restriction could be
 * lengthened but never shortened: the member stayed restricted until the
 * longest one ended, while the admin's screen and the member's notice showed
 * the new date.
 *
 * A restriction's end_date is its last restricted day, so it lifts itself on
 * the day after. A "7 day" restriction applied today runs through the sixth
 * day after today; it used to run a day longer than chosen.
 */
class ModerationService
{
    /** The longest reason an admin may give for a block or restriction. */
    public const REASON_MAX = 1000;

    /**
     * Whether the account was deleted by its owner. Deleting an account blocks
     * it and clears its email address (UserRepository::closeDeletedAccount);
     * no other account is left without one.
     */
    public static function isDeleted(array $account): bool
    {
        return ($account['status'] ?? '') === 'blocked' && ($account['email'] ?? null) === null;
    }

    /** The last restricted day ('Y-m-d') for a restriction of $days days that starts today. */
    public static function lastDayFor(int $days): string
    {
        return date('Y-m-d', strtotime('+' . ($days - 1) . ' days'));
    }

    /** The day a restriction whose last restricted day is $lastDay lifts itself ('Y-m-d'). */
    public static function liftDay(string $lastDay): string
    {
        return date('Y-m-d', strtotime($lastDay . ' +1 day'));
    }

    /**
     * The day the account's restriction lifts ('Y-m-d'), or null when its
     * newest restriction has run out or it never had one.
     */
    public static function liftsOn(mysqli $con, int $userId): ?string
    {
        $lastDay = ModerationRepository::runningRestrictionEnd($con, $userId);
        return $lastDay === null ? null : self::liftDay($lastDay);
    }

    /**
     * Ends the restriction on a restricted account whose newest restriction
     * has run out, and tells them. Returns true when the account is no longer
     * restricted. The member is told only by the request that actually lifted
     * it, so the notice goes out once.
     */
    public static function liftIfOver(mysqli $con, int $userId): bool
    {
        if (self::liftsOn($con, $userId) !== null) {
            return false;
        }
        self::lift($con, $userId);
        return true;
    }

    /** Sets a restricted account active and tells them. False when it was not restricted any more. */
    private static function lift(mysqli $con, int $userId): bool
    {
        if (UserRepository::changeStatus($con, $userId, 'restricted', 'active') < 1) {
            return false;
        }
        NotificationService::restrictionLifted($con, $userId);
        return true;
    }

    /**
     * Lifts every restriction that has run out. Returns the accounts lifted.
     * Without this a restriction ended only when the member next opened a page,
     * so someone who stayed away was listed as restricted indefinitely.
     */
    public static function liftAllOver(mysqli $con): array
    {
        $lifted = [];
        foreach (ModerationRepository::restrictedAccountsToLift($con) as $userId) {
            if (self::liftsOn($con, $userId) === null && self::lift($con, $userId)) {
                $lifted[] = $userId;
            }
        }
        return $lifted;
    }
}
