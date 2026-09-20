<?php

/**
 * google-login.php — "Continue with Google", for signing in and for signing up.
 *
 * Without ?code it starts the round trip: what the visitor asked for (sign in
 * or sign up, the role, whether they came through the admin sign-in) is kept
 * in the session and they are sent to Google. Google sends them back here
 * with ?code, or with ?error when they pressed Cancel.
 */
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Back to the page the visitor started from, with $message shown on it.
 * Errors used to go to the landing page, which shows none of them, so the
 * message only surfaced the next time the visitor opened the sign-in page.
 */
function redirect_with_error(string $message, string $mode = 'login', bool $fromAdmin = false): void
{
    if ($fromAdmin) {
        $_SESSION['admin_login_error'] = $message;
        header("Location: " . url('admin-login'));
    } elseif ($mode === 'signup') {
        $_SESSION['signup_error'] = $message;
        header("Location: " . url('signup'));
    } else {
        $_SESSION['login_error'] = $message;
        header("Location: " . url('login'));
    }
    exit;
}

/** A query-string value, or '' when it is missing or was sent as a list. */
$query = fn(string $name): string => is_string($_GET[$name] ?? null) ? $_GET[$name] : '';

const PC_REGISTRATION_CLOSED = 'New registrations are closed at the moment. Please check back later.';

$clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
$clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
$redirectUri = $_ENV['GOOGLE_REDIRECT_URI'] ?? '';

if (
    $clientId === '' || $clientSecret === '' || $redirectUri === '' ||
    $clientId === 'your_google_client_id' || $clientSecret === 'your_google_client_secret'
) {
    redirect_with_error('Google sign-in is not configured yet. Please update GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env.', 'login');
}

$client = new Google\Client();
$client->setClientId($clientId);
$client->setClientSecret($clientSecret);
$client->setRedirectUri($redirectUri);
$client->setAccessType('online');
$client->setPrompt('select_account');
$client->setIncludeGrantedScopes(true);
$client->addScope('email');
$client->addScope('profile');

// Cancel on Google's screen comes back with ?error and no code. That used to
// count as a fresh start and send the visitor straight back to Google's
// account chooser, having forgotten whether they were signing up.
if ($query('code') === '' && isset($_GET['error'])) {
    $saved = $_SESSION['google_oauth_state'] ?? [];
    unset($_SESSION['google_oauth_state']);
    $mode = ($saved['mode'] ?? '') === 'signup' ? 'signup' : 'login';
    redirect_with_error($mode === 'signup' ? 'Google sign-up was cancelled.' : 'Google sign-in was cancelled.', $mode, !empty($saved['adm']));
}

if ($query('code') === '') {
    $mode = $query('mode') ?: 'login';
    $role = $query('role');

    if (!in_array($mode, ['login', 'signup'], true)) {
        $mode = 'login';
    }

    if ($mode === 'signup' && !in_array($role, ['mentee', 'mentor'], true)) {
        redirect_with_error('Please select a role before continuing with Google.', 'signup');
    }

    // Closing registration in System Settings closes it here too; Google
    // sign-up used to create accounts regardless.
    if ($mode === 'signup' && !pc_setting_bool($con, 'allow_registration')) {
        redirect_with_error(PC_REGISTRATION_CLOSED, 'signup');
    }

    $statePayload = [
        'mode' => $mode,
        'role' => $role,
        // Set by the admin login page's Google button. It travels in the
        // state payload rather than a plain query string on the callback so
        // it cannot be flipped by whoever lands back here.
        'adm'  => $query('from') === 'admin',
        'csrf' => bin2hex(random_bytes(16)),
    ];

    $_SESSION['google_oauth_state'] = $statePayload;
    $client->setState(base64_encode(json_encode($statePayload)));

    header('Location: ' . $client->createAuthUrl());
    exit;
}

$decodedState = json_decode((string)base64_decode($query('state'), true), true);
$savedState = $_SESSION['google_oauth_state'] ?? null;
unset($_SESSION['google_oauth_state']);

if (
    !is_array($decodedState) || !is_array($savedState) ||
    ($decodedState['csrf'] ?? '') !== ($savedState['csrf'] ?? '')
) {
    redirect_with_error('Invalid Google authentication state. Please try again.', 'login');
}

$mode = $savedState['mode'] ?? 'login';
$role = $savedState['role'] ?? '';
$cameFromAdmin = !empty($savedState['adm']);

$token = $client->fetchAccessTokenWithAuthCode($query('code'));

if (isset($token['error'])) {
    redirect_with_error('Google authentication failed. Please try again.', $mode, $cameFromAdmin);
}

$client->setAccessToken($token);
$oauth = new Google\Service\Oauth2($client);

try {
    $googleUser = $oauth->userinfo->get();
} catch (\Throwable $e) {
    redirect_with_error('Google authentication failed. Please try again.', $mode, $cameFromAdmin);
}

$email = trim((string) ($googleUser->email ?? ''));
// Google's names are cut to the 50 characters the account keeps, rather
// than left for the database to cut short.
$firstname = mb_substr(trim((string) ($googleUser->givenName ?? '')), 0, UserRepository::NAME_MAX);
$middlename = '';
$lastname = mb_substr(trim((string) ($googleUser->familyName ?? '')), 0, UserRepository::NAME_MAX);

if ($email === '') {
    redirect_with_error('Google did not return an email address.', $mode, $cameFromAdmin);
}

// Google only hands over addresses it has verified, but it says so in the
// answer, and an address it has not verified proves nothing about who owns it.
if (($googleUser->verifiedEmail ?? null) === false) {
    redirect_with_error('Google has not verified the email address on that account, so it cannot be used to sign in here.', $mode, $cameFromAdmin);
}

$account = UserRepository::signInByEmail($con, $email);

if ($account !== null) {
    $user_id      = (int)$account['user_id'];
    $existingRole = (string)$account['role'];

    // If user is trying to SIGN UP but the email is already registered, block it
    if ($mode === 'signup') {
        redirect_with_error('This Google account is already registered. Please log in instead.', 'signup');
    }

    // A blocked account is turned away here, as the password sign-in does.
    // It used to be signed in, recorded as a sign-in, and only thrown out on
    // the next page it opened.
    if ($account['status'] === 'blocked') {
        redirect_with_error(SignInService::BLOCKED_MESSAGE, 'login', $cameFromAdmin);
    }

    // Arrived through the admin door? Then only an admin may pass, and a
    // member is turned away before being signed in rather than after.
    if ($cameFromAdmin && $existingRole !== 'admin') {
        redirect_with_error('That Google account is not an administrator.', 'login', true);
    }

    // SECURITY: Regenerate session ID after login to prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user_id;
    $_SESSION['role'] = $existingRole;
    $_SESSION['email'] = $email;
    $_SESSION['token'] = bin2hex(random_bytes(32));

    // Google has just proved the owner can read this address (an unverified
    // one was turned away above), so an account that signed up with a password
    // and never opened its confirmation letter is confirmed by this sign-in.
    // Asking them to go and find that letter now would prove nothing further.
    if (EmailVerificationRepository::migrated($con) && empty($account['email_verified_at'])) {
        EmailVerificationRepository::markConfirmed($con, $user_id);
        EmailVerificationRepository::retireOutstanding($con, $user_id);
        $account['email_verified_at'] = date('Y-m-d H:i:s');
    }

    logMe($email, date('Y-m-d H:i:s'), "user login via google");

    header("Location: " . SignInService::destination(
        $existingRole,
        $account['status'],
        $account['verified'],
        $con,
        $account['email_verified_at'] ?? null
    ));
    exit;
}

if ($cameFromAdmin) {
    redirect_with_error('No administrator account uses that Google email.', 'login', true);
}

if ($mode !== 'signup') {
    redirect_with_error('No account found for this Google email. Please sign up first.', 'login');
}

if (!in_array($role, ['mentee', 'mentor'], true)) {
    redirect_with_error('Please select a role before continuing with Google.', 'signup');
}

// Checked again on the way back: registration may have closed while the
// visitor was on Google's screen.
if (!pc_setting_bool($con, 'allow_registration')) {
    redirect_with_error(PC_REGISTRATION_CLOSED, 'signup');
}

if ($firstname === '') {
    $firstname = 'Google';
}

if ($lastname === '') {
    $lastname = 'User';
}

// The account gets no password. It used to get a random one nobody knew,
// which made Settings say a password was set and ask for it before an email
// change. The owner can add a real one through Forgot password.
try {
    $user_id = UserRepository::createMember($con, $firstname, $middlename, $lastname, $role, $email);
    // Google only hands over addresses it has verified, and one it has not was
    // turned away further up — so this address is already proved. A password
    // signup has to confirm by letter; this one has nothing left to confirm.
    EmailVerificationRepository::markConfirmedAtSignup($con, $user_id);
} catch (Throwable $e) {
    redirect_with_error('Google sign-up failed. Please try again.', 'signup');
}

// SECURITY: Regenerate session ID after signup to prevent session fixation
session_regenerate_id(true);

$_SESSION['user_id'] = $user_id;
$_SESSION['role'] = $role;
$_SESSION['email'] = $email;
$_SESSION['token'] = bin2hex(random_bytes(32));

logMe($email, date('Y-m-d H:i:s'), "user signup via google");

// New signups always go to verification — they're never verified yet
if ($role === 'mentor') {
    header("Location: " . url('mentor-verification'));
} else {
    header("Location: " . url('mentee-verification'));
}
exit;
