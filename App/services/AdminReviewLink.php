<?php

/**
 * AdminReviewLink — the signed links that let the owner review an
 * administrator application straight from their inbox.
 *
 * WHY SIGNED AND NOT STORED
 * A token in the database would need a table, a migration and a sweep for
 * expired rows. There is nothing here worth storing: the link says which
 * application, what it is for and when it stops working, and an HMAC over
 * exactly that proves nobody edited it. The secret is the app's own.
 *
 * WHY IT CANNOT BE REPLAYED AFTER A DECISION
 * Nothing in the link records that it was used, and it does not need to. Every
 * action checks the application is still 'pending' first, so once it has been
 * approved or rejected the same link does nothing. That also means a link
 * forwarded to somebody else is worth no more than the first decision.
 *
 * WHY THE EMAIL'S BUTTONS DO NOT ACT DIRECTLY
 * Mail providers and antivirus scanners fetch the links in a message to check
 * them. A GET that approves an administrator would therefore approve them the
 * moment the mail arrived, with nobody having clicked anything. The buttons
 * open a page instead; the decision is a POST from that page.
 */
class AdminReviewLink
{
    /** Long enough to act on at leisure, short enough that a stale inbox is not a key. */
    public const TTL_DAYS = 14;

    /** What a link may be for. */
    public const ACTIONS = ['review', 'id', 'cor'];

    /**
     * The secret these are signed with.
     *
     * ROUTE_SECRET_KEY is already required to be set and secret — every route
     * on the site is derived from it — so a separate one would be another
     * thing to configure and another thing to forget. The 'adminreview' label
     * keeps these signatures from being interchangeable with anything else
     * derived from the same key.
     */
    private static function secret(): string
    {
        return hash_hmac('sha256', 'adminreview', ROUTE_SECRET_KEY);
    }

    private static function sign(int $verificationId, string $action, int $expires): string
    {
        return hash_hmac('sha256', $verificationId . '|' . $action . '|' . $expires, self::secret());
    }

    /** An absolute URL for the owner's email. */
    public static function url(string $route, int $verificationId, string $action, ?int $expires = null): string
    {
        $expires = $expires ?? (time() + self::TTL_DAYS * 86400);
        // pc_site_url(), not base_url(): base_url() yields a path, and a
        // path in an email resolves against whatever the reader's client
        // guesses. pc_site_url() builds it from APP_URL in .env.
        return pc_site_url(ltrim(url($route), '/'))
            . '?v=' . $verificationId
            . '&a=' . rawurlencode($action)
            . '&e=' . $expires
            . '&s=' . self::sign($verificationId, $action, $expires);
    }

    /**
     * Checks a request's query against its signature.
     *
     * @return array{ok:bool, id:int, action:string, error:string}
     */
    public static function check(array $query): array
    {
        $bad = fn(string $why) => ['ok' => false, 'id' => 0, 'action' => '', 'error' => $why];

        $id      = (int)($query['v'] ?? 0);
        $action  = is_string($query['a'] ?? null) ? $query['a'] : '';
        $expires = (int)($query['e'] ?? 0);
        $sig     = is_string($query['s'] ?? null) ? $query['s'] : '';

        if ($id <= 0 || !in_array($action, self::ACTIONS, true) || $expires <= 0 || $sig === '') {
            return $bad('That link is not complete.');
        }
        // hash_equals, not ==: a timing difference on a signature comparison is
        // how a signature gets guessed a byte at a time.
        if (!hash_equals(self::sign($id, $action, $expires), $sig)) {
            return $bad('That link has been altered, so it cannot be used.');
        }
        if ($expires < time()) {
            return $bad('That link has expired. Open the site and review the application there.');
        }
        return ['ok' => true, 'id' => $id, 'action' => $action, 'error' => ''];
    }
}
