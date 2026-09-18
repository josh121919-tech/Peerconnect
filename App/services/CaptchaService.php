<?php

/**
 * CaptchaService — the Google reCAPTCHA on member sign-in and sign-up.
 *
 * Both forms always require it; there is no switch to turn it off. The keys
 * come from .env (RECAPTCHA_SITE_KEY, RECAPTCHA_SECRET_KEY). Without a secret
 * the check fails, so a missing key closes the forms rather than opening them.
 *
 * This used to be written out twice, once in each form, and sent the answer
 * to Google unencoded in the address, with the secret, and with no limit on
 * how long to wait.
 */
class CaptchaService
{
    private const VERIFY_URL      = 'https://www.google.com/recaptcha/api/siteverify';
    private const TIMEOUT_SECONDS = 10;

    /** The key the page's CAPTCHA box is drawn with, or '' when none is set. */
    public static function siteKey(): string
    {
        return (string)($_ENV['RECAPTCHA_SITE_KEY'] ?? '');
    }

    /** Whether both keys are in .env, which the forms need to work at all. */
    public static function isConfigured(): bool
    {
        return self::siteKey() !== '' && (string)($_ENV['RECAPTCHA_SECRET_KEY'] ?? '') !== '';
    }

    /**
     * Whether Google confirms the answer the browser sent (the form's
     * g-recaptcha-response field). Anything that is not a non-empty string
     * fails without asking Google.
     */
    public static function verify($response): bool
    {
        $secret = (string)($_ENV['RECAPTCHA_SECRET_KEY'] ?? '');
        if ($secret === '' || !is_string($response) || $response === '') {
            return false;
        }

        $context = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => http_build_query(['secret' => $secret, 'response' => $response]),
            'timeout'       => self::TIMEOUT_SECONDS,
            'ignore_errors' => true,
        ]]);
        $reply = @file_get_contents(self::VERIFY_URL, false, $context);
        $data  = $reply !== false ? json_decode($reply, true) : null;

        return is_array($data) && ($data['success'] ?? false) === true;
    }
}
