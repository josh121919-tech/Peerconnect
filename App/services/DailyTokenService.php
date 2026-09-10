<?php

/**
 * DailyTokenService.php
 * Creates (or reuses) a Daily.co room and mints a short-lived meeting token
 * for one participant, via Daily's REST API. Replaces JitsiTokenService for
 * video calls — Daily's free tier is 10,000 participant-minutes/month with
 * no per-session time cutoff (unlike the public meet.jit.si fallback that
 * was previously in use, which caps embedded calls at 5 minutes).
 */
class DailyTokenService
{
    private const API = 'https://api.daily.co/v1';

    public static function isConfigured(): bool
    {
        return DAILY_API_KEY !== '';
    }

    /**
     * @param string $roomName   Deterministic, unguessable room name (no domain prefix)
     * @param int    $userId
     * @param string $displayName
     * @param bool   $isOwner    Grants moderator-style room controls
     * @param int    $expiresAt  Unix timestamp the room and token stop being valid
     * @return array{url:?string, token:?string, error:?string}
     */
    public static function build(string $roomName, int $userId, string $displayName, bool $isOwner, int $expiresAt): array
    {
        if (!self::isConfigured()) {
            return ['url' => null, 'token' => null, 'error' => 'Video calling is not configured.'];
        }

        $room = self::ensureRoom($roomName, $expiresAt);
        if ($room === null || empty($room['url'])) {
            return ['url' => null, 'token' => null, 'error' => 'Could not create the video room.'];
        }

        $token = self::mintToken($roomName, $userId, $displayName, $isOwner, $expiresAt);
        if ($token === null) {
            return ['url' => null, 'token' => null, 'error' => 'Could not create a meeting token.'];
        }

        return ['url' => $room['url'], 'token' => $token, 'error' => null];
    }

    /** Reuses the room if it already exists (e.g. other participant joined first); creates it otherwise. */
    private static function ensureRoom(string $roomName, int $expiresAt): ?array
    {
        $existing = self::request('GET', '/rooms/' . rawurlencode($roomName));
        if ($existing !== null && !empty($existing['url'])) {
            return $existing;
        }

        return self::request('POST', '/rooms', [
            'name'       => $roomName,
            'privacy'    => 'private',
            'properties' => [
                'exp'               => $expiresAt,
                'eject_at_room_exp' => true,
                'enable_prejoin_ui' => true,
            ],
        ]);
    }

    private static function mintToken(string $roomName, int $userId, string $displayName, bool $isOwner, int $expiresAt): ?string
    {
        $resp = self::request('POST', '/meeting-tokens', [
            'properties' => [
                'room_name' => $roomName,
                'user_name' => $displayName,
                'user_id'   => (string)$userId,
                'is_owner'  => $isOwner,
                'exp'       => $expiresAt,
            ],
        ]);
        return $resp['token'] ?? null;
    }

    private static function request(string $method, string $path, ?array $body = null): ?array
    {
        $ch = curl_init(self::API . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . DAILY_API_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 8,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
