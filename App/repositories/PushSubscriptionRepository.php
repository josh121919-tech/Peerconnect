<?php

/**
 * PushSubscriptionRepository — the browsers a member has allowed notifications on.
 *
 * Every method is a no-op when the table is absent, so the app runs unchanged
 * on a database where push_subscriptions.sql has not been run. Local and live
 * are migrated at different times and the code has to work in between; this is
 * the same guarded pattern the announcement club column uses.
 */
class PushSubscriptionRepository extends Repository
{
    /** Cached per request: this is asked on every notification that is sent. */
    private static ?bool $enabled = null;

    /** Whether the table exists, so callers can skip the work entirely. */
    public static function enabled(mysqli $con): bool
    {
        if (self::$enabled === null) {
            self::$enabled = (bool)self::value($con, "
                SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = 'push_subscriptions'
            ");
        }
        return self::$enabled;
    }

    /**
     * Records a browser, or refreshes the one already on file.
     *
     * Re-subscribing returns the same endpoint, so a member who reopens the app
     * every morning would otherwise collect a row a day and be told everything
     * that many times. The endpoint is unique and this updates in place.
     */
    public static function save(mysqli $con, int $userId, string $endpoint, string $p256dh, string $auth, string $agent): bool
    {
        if (!self::enabled($con)) {
            return false;
        }
        self::execute($con, "
            INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                user_id    = VALUES(user_id),
                p256dh     = VALUES(p256dh),
                auth       = VALUES(auth),
                user_agent = VALUES(user_agent)
        ", 'issss', [$userId, $endpoint, $p256dh, $auth, $agent]);
        return true;
    }

    /** Every browser this member has allowed. */
    public static function forUser(mysqli $con, int $userId): array
    {
        if (!self::enabled($con)) {
            return [];
        }
        return self::typedRows($con, "
            SELECT subscription_id, endpoint, p256dh, auth
            FROM push_subscriptions WHERE user_id = ?
        ", 'i', [$userId]);
    }

    /** How many browsers this member has allowed, for the settings screen. */
    public static function countForUser(mysqli $con, int $userId): int
    {
        if (!self::enabled($con)) {
            return 0;
        }
        return (int)self::value($con, "SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?", 'i', [$userId]);
    }

    /**
     * Forgets one browser. Scoped to the member so a stolen endpoint cannot be
     * used to unsubscribe somebody else.
     */
    public static function forget(mysqli $con, int $userId, string $endpoint): int
    {
        if (!self::enabled($con)) {
            return 0;
        }
        return self::execute($con, "
            DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?
        ", 'is', [$userId, $endpoint]);
    }

    /**
     * Drops a subscription the push service has told us is dead — the browser
     * was uninstalled, or the permission was revoked. Not scoped to a user:
     * the push service is the authority here, and an endpoint it has retired
     * is of no use to whoever it belonged to.
     */
    public static function forgetExpired(mysqli $con, string $endpoint): int
    {
        if (!self::enabled($con)) {
            return 0;
        }
        return self::execute($con, "DELETE FROM push_subscriptions WHERE endpoint = ?", 's', [$endpoint]);
    }

    /** Marks the browsers that were just posted to, so dead ones are visible. */
    public static function touch(mysqli $con, array $endpoints): void
    {
        if (!$endpoints || !self::enabled($con)) {
            return;
        }
        self::execute(
            $con,
            "UPDATE push_subscriptions SET last_used_at = NOW() WHERE endpoint IN (" . self::marks($endpoints) . ")",
            str_repeat('s', count($endpoints)),
            array_values($endpoints)
        );
    }
}
