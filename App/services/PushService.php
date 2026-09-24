<?php

/**
 * PushService — sends a notification to the member's devices.
 *
 * The bell in the corner of the page only works while the page is open, which
 * is not when a session is about to start. This posts the same notice to the
 * browsers they have allowed, so it arrives on the phone in their pocket.
 *
 * It is deliberately impossible for this to break anything. Every entry point
 * returns early when push is not set up, and the send itself cannot throw:
 * a notification that failed to reach a device is still in the database and
 * still on the page, and a member should never see an error because a push
 * service was slow. Failures are swallowed on purpose, not by accident.
 *
 * SECURITY: the payload is encrypted by the library with keys the browser
 * generated, so the push service relaying it cannot read the contents. The
 * VAPID private key never leaves the server and is never sent to the browser —
 * only the public key is, which is what identifies us to the push service.
 */
class PushService
{
    /**
     * Whether push can be used at all: the library is installed, both VAPID
     * keys are set, and the table exists.
     */
    public static function isConfigured(mysqli $con): bool
    {
        return class_exists(\Minishlink\WebPush\WebPush::class)
            && defined('VAPID_PUBLIC_KEY') && VAPID_PUBLIC_KEY !== ''
            && defined('VAPID_PRIVATE_KEY') && VAPID_PRIVATE_KEY !== ''
            && PushSubscriptionRepository::enabled($con);
    }

    /**
     * Sends one notice to every browser this member has allowed.
     *
     * Returns how many were accepted. Callers are not expected to check it:
     * there is nothing useful to do about a device that did not answer, and
     * the notice is on their page regardless.
     *
     * Subscriptions the push service reports as gone are deleted. Without that
     * a member who reinstalls their browser accumulates dead endpoints and
     * every notification spends time posting to nothing.
     */
    public static function sendToUser(mysqli $con, int $userId, string $title, string $body, string $url = ''): int
    {
        if (!self::isConfigured($con)) {
            return 0;
        }

        $subs = PushSubscriptionRepository::forUser($con, $userId);
        if (!$subs) {
            return 0;
        }

        $sent = 0;
        try {
            $push = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject'    => defined('VAPID_SUBJECT') && VAPID_SUBJECT !== '' ? VAPID_SUBJECT : url('welcomepage'),
                    'publicKey'  => VAPID_PUBLIC_KEY,
                    'privateKey' => VAPID_PRIVATE_KEY,
                ],
            ]);
            // One VAPID header for the whole batch rather than one per device.
            $push->setReuseVAPIDHeaders(true);

            $payload = json_encode([
                'title' => $title,
                'body'  => $body,
                'url'   => $url,
                'tag'   => 'pc-' . $userId,
            ], JSON_UNESCAPED_UNICODE);

            foreach ($subs as $s) {
                $push->queueNotification(
                    \Minishlink\WebPush\Subscription::create([
                        'endpoint'        => $s['endpoint'],
                        'publicKey'       => $s['p256dh'],
                        'authToken'       => $s['auth'],
                        'contentEncoding' => 'aes128gcm',
                    ]),
                    $payload
                );
            }

            $touched = [];
            foreach ($push->flush() as $report) {
                $endpoint = $report->getEndpoint();
                if ($report->isSuccess()) {
                    $sent++;
                    $touched[] = $endpoint;
                } elseif ($report->isSubscriptionExpired()) {
                    PushSubscriptionRepository::forgetExpired($con, $endpoint);
                }
            }
            PushSubscriptionRepository::touch($con, $touched);
        } catch (\Throwable $e) {
            // A push that did not go out is not worth failing a page over. The
            // notification itself is already saved and already on their screen.
            error_log('PushService: ' . $e->getMessage());
            return $sent;
        }

        return $sent;
    }
}
