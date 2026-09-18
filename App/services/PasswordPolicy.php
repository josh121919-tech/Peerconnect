<?php

/**
 * PasswordPolicy — the one rule every password form checks: member sign-up,
 * admin sign-up, Settings → Change password and Reset password.
 *
 * Between the minimum length and 20 characters, with an upper-case letter, a
 * lower-case letter, a number and one of @#$%^&*!?, and no other characters.
 * The minimum is chosen in System Settings → Security. It used to reach
 * sign-up only: changing or resetting a password always took 8, and admin
 * sign-up took any 8 characters at all.
 */
class PasswordPolicy
{
    public const MAX     = 20;
    public const SYMBOLS = '@#$%^&*!?';

    /** The minimum length set in System Settings → Security (6 to 20, 8 by default). */
    public static function minLength(mysqli $con): int
    {
        return max(6, min(self::MAX, pc_setting_int($con, 'password_min_length', 8)));
    }

    /** What is wrong with $password, or null when it passes. */
    public static function problem(string $password, int $min): ?string
    {
        $ok = preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@#$%^&*!?])[A-Za-z\d@#$%^&*!?]{' . $min . ',' . self::MAX . '}$/', $password);
        return $ok ? null : self::describe($min);
    }

    /** The rule in words, as a sentence, for hints beside a password field. */
    public static function describe(int $min): string
    {
        return "Password must be {$min}–" . self::MAX . ' characters and include an uppercase letter, a lowercase letter, a number and a symbol (one of ' . self::SYMBOLS . ').';
    }
}
