<?php
/**
 * scripts/vapid_keys.php — generates the VAPID key pair device notifications need.
 *
 *     php scripts/vapid_keys.php
 *
 * Prints two values to paste into .env. It writes nothing: the private key is
 * a credential, and a script that quietly dropped one into a file on disk is
 * how credentials end up in a backup, a deploy archive, or a repository.
 *
 * Run it ONCE and use the same pair everywhere. The public key is baked into
 * every browser subscription that has been handed out, so regenerating the
 * pair silently invalidates every device already signed up — they keep their
 * permission and simply stop receiving anything, which is a hard fault to
 * spot. If you ever do have to rotate, empty push_subscriptions at the same
 * time so devices are asked to subscribe again.
 *
 * WHAT VAPID IS FOR
 * It identifies this server to the browser vendors' push services, so they can
 * tell our notifications from anyone else's and contact us about them. It is
 * not what encrypts the payload — that uses keys the browser itself generates
 * and stores against each subscription.
 */

if (PHP_SAPI !== 'cli') {
    // A key pair should never be reachable over the web. .htaccess blocks
    // scripts/ as well; this is the second lock.
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

/*
 * XAMPP's PHP does not know where its openssl.cnf is, and EC key generation is
 * one of the few things that needs it — openssl_pkey_new() fails with "no such
 * file" until it is pointed at one. A Linux host finds it without help, so
 * this only ever applies while developing on Windows. Generating the key here
 * rather than through the library's own helper is what lets us pass it.
 */
$args = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
foreach (['C:/xampp/apache/conf/openssl.cnf', 'C:/xampp/php/extras/ssl/openssl.cnf'] as $cnf) {
    if (is_file($cnf)) {
        $args['config'] = $cnf;
        break;
    }
}

$key = openssl_pkey_new($args);
if ($key === false) {
    fwrite(STDERR, "Could not generate a key. OpenSSL said:\n");
    while ($e = openssl_error_string()) {
        fwrite(STDERR, "  $e\n");
    }
    fwrite(STDERR, "\nOn Windows this is almost always a missing openssl.cnf; set OPENSSL_CONF or\n"
        . "edit the paths tried at the top of this script.\n");
    exit(1);
}

$d = openssl_pkey_get_details($key)['ec'];

/** base64url, as the Push API and .env both want it: no padding, URL-safe. */
$b64 = fn(string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
/** Left-pad to 32 bytes: a coordinate with leading zeroes comes back short. */
$pad = fn(string $bin): string => str_pad($bin, 32, "\0", STR_PAD_LEFT);

// The public key is the uncompressed point: 0x04 followed by X and Y.
$public  = $b64("\x04" . $pad($d['x']) . $pad($d['y']));
$private = $b64($pad($d['d']));

echo "\nVAPID key pair generated. Paste these into .env, then restart PHP:\n\n";
echo "VAPID_PUBLIC_KEY=$public\n";
echo "VAPID_PRIVATE_KEY=$private\n";
echo "VAPID_SUBJECT=https://neustpeerconnect.org\n";
echo "\nKeep the private key off the repository — .env is already ignored.\n";
echo "The same pair has to be in .env on the live server; generating a second\n";
echo "pair there would cut off every device that subscribed against this one.\n\n";
