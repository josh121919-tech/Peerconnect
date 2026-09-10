<?php

/**
 * JitsiTokenService.php
 * Builds a signed JaaS (8x8.vc) meeting descriptor for one participant:
 * a room to join, a domain to embed, and (when JaaS credentials are
 * configured) a short-lived RS256 JWT that 8x8's edge validates before
 * letting the participant into that specific room.
 *
 * Without JaaS credentials configured (JAAS_APP_ID empty), falls back to
 * the public meet.jit.si room with no token — same behavior the app had
 * before this change, so local/dev environments keep working.
 */
class JitsiTokenService
{
    public static function isConfigured(): bool
    {
        return JAAS_APP_ID !== '' && JAAS_API_KEY_ID !== '' && JAAS_PRIVATE_KEY_PATH !== '' && is_readable(JAAS_PRIVATE_KEY_PATH);
    }

    /**
     * @param string $roomName   Deterministic, unguessable room name (no domain prefix)
     * @param int    $userId
     * @param string $displayName
     * @param bool   $isModerator
     * @param int    $expiresAt  Unix timestamp the token stops being valid
     * @return array{domain:string, room:string, token:?string}
     */
    public static function build(string $roomName, int $userId, string $displayName, bool $isModerator, int $expiresAt): array
    {
        if (!self::isConfigured()) {
            return [
                'domain' => 'meet.jit.si',
                'room'   => $roomName,
                'token'  => null,
            ];
        }

        $room = JAAS_APP_ID . '/' . $roomName;

        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => JAAS_API_KEY_ID];
        $payload = [
            'aud'     => 'jitsi',
            'iss'     => 'chat',
            'sub'     => JAAS_APP_ID,
            'room'    => $roomName,
            'exp'     => $expiresAt,
            'nbf'     => time() - 10,
            'context' => [
                'user' => [
                    'id'        => (string)$userId,
                    'name'      => $displayName,
                    'moderator' => $isModerator,
                ],
            ],
        ];

        return [
            'domain' => '8x8.vc',
            'room'   => $room,
            'token'  => self::sign($header, $payload),
        ];
    }

    private static function sign(array $header, array $payload): string
    {
        $segments = [
            self::base64UrlEncode(json_encode($header)),
            self::base64UrlEncode(json_encode($payload)),
        ];
        $signingInput = implode('.', $segments);

        $privateKey = openssl_pkey_get_private(file_get_contents(JAAS_PRIVATE_KEY_PATH));
        if ($privateKey === false) {
            throw new RuntimeException('JaaS private key could not be read');
        }

        $signature = '';
        openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $segments[] = self::base64UrlEncode($signature);
        return implode('.', $segments);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
