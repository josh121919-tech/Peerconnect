<?php

/**
 * GoogleCalendarService.php
 * Pushes a user's approved PeerConnect sessions into their real Google
 * Calendar, so they show up in whatever calendar app their phone/desktop is
 * signed into — no file to download, no manual import.
 *
 * Event ids are derived from the session and the user ("pc<session>u<user>"),
 * which Google accepts as a client-supplied id. That makes every write
 * idempotent: the same session always maps to the same event, so re-syncing
 * updates rather than duplicating, and cancelling deletes the right one.
 * (Google requires ids in base32hex — a-v and 0-9 — which "pc…u…" satisfies.)
 *
 * Nothing here may ever be fatal to the caller: a Google outage must not stop
 * a mentor approving a session. Every public entry point swallows its errors
 * and records them on the link row instead.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

class GoogleCalendarService
{
    /** How long a page view may go before it triggers a background re-sync. */
    private const SYNC_EVERY_MINUTES = 30;

    public static function isConfigured(): bool
    {
        $id     = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        $secret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
        return $id !== '' && $secret !== ''
            && $id !== 'your_google_client_id' && $secret !== 'your_google_client_secret';
    }

    /** The OAuth client, pointed at our own calendar callback. */
    public static function client(): Google\Client
    {
        $client = new Google\Client();
        $client->setClientId($_ENV['GOOGLE_CLIENT_ID'] ?? '');
        $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
        $client->setRedirectUri(self::redirectUri());
        // Offline + consent so we actually receive a refresh token; without it
        // the link would silently die an hour after connecting.
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);
        $client->addScope(Google\Service\Calendar::CALENDAR_EVENTS);
        $client->addScope('email');
        return $client;
    }

    /**
     * Must match a URI registered on the OAuth client in Google Cloud Console.
     * Deliberately the stable /gcal-callback.php alias (see .htaccess) rather
     * than the hashed route, so rotating ROUTE_SECRET_KEY can't break it.
     */
    public static function redirectUri(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . rtrim(BASE_URL, '/') . '/gcal-callback.php';
    }

    // ── Link record ──────────────────────────────────────────────────────

    /**
     * The link row, plus two clock-derived fields. Both are worked out by
     * MySQL: every timestamp in this table is written on the database clock,
     * and PHP's timezone here is a different one, so comparing them with
     * time()/strtotime() would be hours out.
     */
    public static function linkFor(mysqli $con, int $user_id): ?array
    {
        $stmt = $con->prepare("
            SELECT *,
                   TIMESTAMPDIFF(SECOND, NOW(), expires_at)    AS expires_in_secs,
                   TIMESTAMPDIFF(SECOND, last_synced_at, NOW()) AS since_sync_secs
            FROM google_calendar_links WHERE user_id = ?
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public static function isConnected(mysqli $con, int $user_id): bool
    {
        return self::linkFor($con, $user_id) !== null;
    }

    public static function saveToken(mysqli $con, int $user_id, array $token, ?string $email): void
    {
        $access  = $token['access_token']  ?? '';
        $refresh = $token['refresh_token'] ?? '';
        $ttl     = (int)($token['expires_in'] ?? 3600);

        $existing = self::linkFor($con, $user_id);
        // Google only returns a refresh token on the first consent — keep the
        // stored one when a later grant omits it.
        if ($refresh === '' && $existing) {
            $refresh = (string)$existing['refresh_token'];
        }

        $stmt = $con->prepare("
            INSERT INTO google_calendar_links (user_id, google_email, access_token, refresh_token, expires_at)
            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
            ON DUPLICATE KEY UPDATE
                google_email  = COALESCE(VALUES(google_email), google_email),
                access_token  = VALUES(access_token),
                refresh_token = VALUES(refresh_token),
                expires_at    = VALUES(expires_at),
                last_error    = NULL
        ");
        $stmt->bind_param("isssi", $user_id, $email, $access, $refresh, $ttl);
        $stmt->execute();
        $stmt->close();
    }

    public static function disconnect(mysqli $con, int $user_id): void
    {
        $link = self::linkFor($con, $user_id);
        if ($link) {
            try {
                $client = self::client();
                $client->revokeToken($link['refresh_token'] ?: $link['access_token']);
            } catch (Throwable $e) {
                // Revocation is best-effort; the local link goes either way.
            }
        }
        $stmt = $con->prepare("DELETE FROM google_calendar_links WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }

    /** An authorised client for this user, or null if we can't get one. */
    private static function authedClient(mysqli $con, int $user_id, ?array $link = null): ?Google\Client
    {
        $link = $link ?? self::linkFor($con, $user_id);
        if (!$link || !self::isConfigured()) return null;

        $client = self::client();
        $client->setAccessToken([
            'access_token'  => $link['access_token'],
            'refresh_token' => $link['refresh_token'],
            'expires_in'    => max(0, (int)($link['expires_in_secs'] ?? 0)),
            'created'       => time(),
        ]);

        if ($client->isAccessTokenExpired()) {
            if (empty($link['refresh_token'])) return null;
            try {
                $fresh = $client->fetchAccessTokenWithRefreshToken($link['refresh_token']);
            } catch (Throwable $e) {
                self::noteError($con, $user_id, self::humanError($e->getMessage()));
                return null;
            }
            if (isset($fresh['error'])) {
                self::noteError($con, $user_id, 'Google refused to refresh the connection. Please reconnect.');
                return null;
            }
            self::saveToken($con, $user_id, $fresh, null);
        }

        return $client;
    }

    /** Google's raw JSON is no use to a student — say what to do instead. */
    public static function humanError(string $raw): string
    {
        if (stripos($raw, '401') !== false || stripos($raw, 'invalid authentication') !== false
            || stripos($raw, 'invalid_grant') !== false || stripos($raw, 'Invalid Credentials') !== false) {
            return 'Your Google Calendar connection has expired. Please disconnect and connect again.';
        }
        if (stripos($raw, '403') !== false || stripos($raw, 'insufficient') !== false
            || stripos($raw, 'has not been used') !== false || stripos($raw, 'accessNotConfigured') !== false) {
            return 'Google refused access to the calendar. Check that the Google Calendar API is enabled for this app.';
        }
        if (stripos($raw, 'Could not resolve host') !== false || stripos($raw, 'cURL error') !== false) {
            return 'Could not reach Google. Check your internet connection and try again.';
        }
        return 'Google Calendar sync failed. Please try again, or reconnect if it keeps happening.';
    }

    private static function noteError(mysqli $con, int $user_id, string $message): void
    {
        $msg  = mb_substr($message, 0, 255);
        $stmt = $con->prepare("UPDATE google_calendar_links SET last_error = ? WHERE user_id = ?");
        $stmt->bind_param("si", $msg, $user_id);
        $stmt->execute();
        $stmt->close();
    }

    // ── Sync ─────────────────────────────────────────────────────────────

    private static function eventId(int $session_id, int $user_id): string
    {
        return 'pc' . $session_id . 'u' . $user_id;
    }

    /** Every session of this user's that belongs in a calendar. */
    private static function sessionsFor(mysqli $con, int $user_id): array
    {
        $stmt = $con->prepare("
            SELECT sr.request_id, sr.subject, sr.session_date, sr.status,
                   sr.mentor_id, sr.mentee_id,
                   CONCAT(mentor.firstname, ' ', mentor.lastname) AS mentor_name,
                   CONCAT(mentee.firstname, ' ', mentee.lastname) AS mentee_name,
                   COALESCE(a.duration, 60) AS duration
            FROM session_requests sr
            JOIN users mentor ON mentor.user_id = sr.mentor_id
            JOIN users mentee ON mentee.user_id = sr.mentee_id
            LEFT JOIN availability a
                   ON a.mentor_id        = sr.mentor_id
                  AND a.subject          = sr.subject
                  AND DATE(a.date)       = DATE(sr.session_date)
                  AND TIME(a.start_time) = TIME(sr.session_date)
            WHERE (sr.mentor_id = ? OR sr.mentee_id = ?)
              AND sr.session_date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
            ORDER BY sr.session_date ASC
        ");
        $stmt->bind_param("ii", $user_id, $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private static function buildEvent(array $s, int $user_id): Google\Service\Calendar\Event
    {
        $tz    = new DateTimeZone('Asia/Manila');
        $start = new DateTime($s['session_date'], $tz);
        $end   = (clone $start)->modify('+' . (int)$s['duration'] . ' minutes');

        $isMentor  = ((int)$s['mentor_id'] === $user_id);
        $otherName = $isMentor ? $s['mentee_name'] : $s['mentor_name'];
        $otherRole = $isMentor ? 'mentee' : 'mentor';

        $event = new Google\Service\Calendar\Event();
        $event->setId(self::eventId((int)$s['request_id'], $user_id));
        $event->setSummary('Mentoring session: ' . $s['subject']);
        $event->setDescription(
            'Session with your ' . $otherRole . ', ' . $otherName . ', via PeerConnect.'
        );
        // The scheme was hardcoded http://, so on an HTTPS install the "source"
        // link Google shows on the event pointed at the plain-text address —
        // and from the command line, with no host header, at no host at all.
        // pc_site_url() takes both from APP_URL.
        $event->setSource(new Google\Service\Calendar\EventSource([
            'title' => 'PeerConnect',
            'url'   => pc_site_url(ltrim(url('mentee-calendar'), '/')),
        ]));

        $sdt = new Google\Service\Calendar\EventDateTime();
        $sdt->setDateTime($start->format(DateTime::RFC3339));
        $sdt->setTimeZone('Asia/Manila');
        $event->setStart($sdt);

        $edt = new Google\Service\Calendar\EventDateTime();
        $edt->setDateTime($end->format(DateTime::RFC3339));
        $edt->setTimeZone('Asia/Manila');
        $event->setEnd($edt);

        return $event;
    }

    /**
     * Push every approved session into Google and remove the ones that are no
     * longer approved. Returns a small tally for the UI.
     */
    public static function syncAll(mysqli $con, int $user_id): array
    {
        $result = ['synced' => 0, 'removed' => 0, 'failed' => 0, 'ok' => false, 'error' => null];

        $link = self::linkFor($con, $user_id);
        if (!$link) {
            $result['error'] = 'Not connected.';
            return $result;
        }

        $client = self::authedClient($con, $user_id, $link);
        if (!$client) {
            $result['error'] = 'Google connection needs to be re-authorised.';
            return $result;
        }

        $calendarId = $link['calendar_id'] ?: 'primary';

        $lastError = null;

        try {
            $service = new Google\Service\Calendar($client);

            foreach (self::sessionsFor($con, $user_id) as $s) {
                $eventId = self::eventId((int)$s['request_id'], $user_id);

                if ($s['status'] === 'approved') {
                    $event = self::buildEvent($s, $user_id);
                    try {
                        // Same id every time, so this overwrites rather than duplicates.
                        $service->events->update($calendarId, $eventId, $event);
                    } catch (Throwable $e) {
                        try {
                            $service->events->insert($calendarId, $event);
                        } catch (Throwable $inner) {
                            // One bad row shouldn't abort the run, but it must
                            // not be reported as a success either.
                            $result['failed']++;
                            $lastError = $inner->getMessage();
                            continue;
                        }
                    }
                    $result['synced']++;
                } else {
                    // Cancelled / rejected / completed sessions come back out.
                    try {
                        $service->events->delete($calendarId, $eventId);
                        $result['removed']++;
                    } catch (Throwable $e) {
                        // Not there — nothing to remove.
                    }
                }
            }

            // Nothing landed but Google was asked to take something — that's a
            // failed sync, not an empty one. Reporting it as success is how a
            // dead connection stays invisible.
            if ($result['failed'] > 0 && $result['synced'] === 0) {
                $result['error'] = self::humanError((string)$lastError);
                self::noteError($con, $user_id, $result['error']);
                return $result;
            }

            $stmt = $con->prepare("UPDATE google_calendar_links SET last_synced_at = NOW(), last_error = ? WHERE user_id = ?");
            $partial = $result['failed'] > 0
                ? $result['failed'] . ' session(s) could not be synced.'
                : null;
            $stmt->bind_param("si", $partial, $user_id);
            $stmt->execute();
            $stmt->close();

            $result['ok'] = true;
        } catch (Throwable $e) {
            $result['error'] = self::humanError($e->getMessage());
            self::noteError($con, $user_id, $result['error']);
        }

        return $result;
    }

    /**
     * Push (or withdraw) one session for everyone on it who has connected.
     * Called from the approve/reject/cancel paths — never throws.
     */
    public static function pushSession(mysqli $con, int $session_id): void
    {
        try {
            if (!self::isConfigured()) return;

            $stmt = $con->prepare("SELECT mentor_id, mentee_id FROM session_requests WHERE request_id = ?");
            $stmt->bind_param("i", $session_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) return;

            foreach ([(int)$row['mentor_id'], (int)$row['mentee_id']] as $uid) {
                $link = self::linkFor($con, $uid);
                if (!$link) continue;

                $client = self::authedClient($con, $uid, $link);
                if (!$client) continue;

                $sessions = array_filter(
                    self::sessionsFor($con, $uid),
                    fn($s) => (int)$s['request_id'] === $session_id
                );
                $s = reset($sessions);
                if (!$s) continue;

                $service    = new Google\Service\Calendar($client);
                $calendarId = $link['calendar_id'] ?: 'primary';
                $eventId    = self::eventId($session_id, $uid);

                if ($s['status'] === 'approved') {
                    $event = self::buildEvent($s, $uid);
                    try {
                        $service->events->update($calendarId, $eventId, $event);
                    } catch (Throwable $e) {
                        try {
                            $service->events->insert($calendarId, $event);
                        } catch (Throwable $inner) {
                        }
                    }
                } else {
                    try {
                        $service->events->delete($calendarId, $eventId);
                    } catch (Throwable $e) {
                    }
                }
            }
        } catch (Throwable $e) {
            // Calendar sync must never break the action that triggered it.
        }
    }

    /**
     * Called on calendar page views: re-syncs quietly if the last one is old.
     * Returns true when a sync actually ran.
     */
    public static function syncIfStale(mysqli $con, int $user_id): bool
    {
        $link = self::linkFor($con, $user_id);
        if (!$link) return false;

        $since = $link['since_sync_secs'];
        if ($since !== null && (int)$since < self::SYNC_EVERY_MINUTES * 60) {
            return false;
        }

        try {
            self::syncAll($con, $user_id);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
