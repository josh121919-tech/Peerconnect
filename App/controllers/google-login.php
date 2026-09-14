<?php
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function redirect_with_error(string $message, string $mode = 'login'): void
{
    if ($mode === 'signup') {
        $_SESSION['signup_error'] = $message;
    } else {
        $_SESSION['login_error'] = $message;
    }

    header("Location: /case/case/edf9af138e2da896fcfd1a892f49a4b9");
    exit;
}

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

if (!isset($_GET['code'])) {
    $mode = $_GET['mode'] ?? 'login';
    $role = $_GET['role'] ?? '';

    if (!in_array($mode, ['login', 'signup'], true)) {
        $mode = 'login';
    }

    if ($mode === 'signup' && !in_array($role, ['mentee', 'mentor'], true)) {
        redirect_with_error('Please select a role before continuing with Google.', 'signup');
    }

    $statePayload = [
        'mode' => $mode,
        'role' => $role,
        // Set by the admin login page's Google button. It travels in the
        // state payload rather than a plain query string on the callback so
        // it cannot be flipped by whoever lands back here.
        'adm'  => ($_GET['from'] ?? '') === 'admin',
        'csrf' => bin2hex(random_bytes(16)),
    ];

    $_SESSION['google_oauth_state'] = $statePayload;
    $client->setState(base64_encode(json_encode($statePayload)));

    header('Location: ' . $client->createAuthUrl());
    exit;
}

$state = $_GET['state'] ?? '';
$decodedState = json_decode(base64_decode($state, true), true);
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

$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

if (isset($token['error'])) {
    redirect_with_error('Google authentication failed. Please try again.', $mode);
}

$client->setAccessToken($token);
$oauth = new Google\Service\Oauth2($client);

try {
    $googleUser = $oauth->userinfo->get();
} catch (\Throwable $e) {
    redirect_with_error('Google authentication failed. Please try again.', $mode);
}

$email = trim((string) ($googleUser->email ?? ''));
$firstname = trim((string) ($googleUser->givenName ?? ''));
$middlename = '';
$lastname = trim((string) ($googleUser->familyName ?? ''));

if ($email === '') {
    redirect_with_error('Google did not return an email address.', $mode);
}

$stmt = $con->prepare("
    SELECT u.user_id, u.role
    FROM users u
    WHERE u.email = ?
");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 1) {
    $stmt->bind_result($user_id, $existingRole);
    $stmt->fetch();
    $stmt->close();

    // If user is trying to SIGN UP but the email is already registered, block it
    if ($mode === 'signup') {
        redirect_with_error('This Google account is already registered. Please log in instead.', 'signup');
    }

    // SECURITY: Regenerate session ID after login to prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user_id;
    $_SESSION['role'] = $existingRole;
    $_SESSION['email'] = $email;
    $_SESSION['token'] = bin2hex(random_bytes(32));

    logMe($email, date('Y-m-d H:i:s'), "user login via google");

    // Check verification status before redirecting
    $row = $con->query("SELECT verified, status FROM users WHERE user_id = $user_id")->fetch_assoc();

    // Arrived through the admin door? Then only an admin may pass. Without
    // this, a mentee's Google account signed in fine and was dropped on a
    // dashboard they cannot open.
    if ($cameFromAdmin && $existingRole !== 'admin') {
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['admin_login_error'] = 'That Google account is not an administrator.';
        header("Location: " . url('admin-login'));
        exit;
    }

    // Admins have no verification step and their own dashboard; they used to
    // fall through to the mentee side of both branches below.
    if ($existingRole === 'admin') {
        header("Location: " . url('admin-dashboard'));
        exit;
    }

    if ($row['status'] !== 'active' || !$row['verified']) {
        if ($existingRole === 'mentor') {
            header("Location: /case/case/8002a71bf36398e654ce5d3ba3494d8d");
        } else {
            header("Location: /case/case/140204565c663d348ed7ae748f5a5802");
        }
        exit;
    }

    if ($existingRole === 'mentor') {
        header("Location: /case/case/140e248d98c42c91deb10e1064e644ec");
    } else {
        header("Location: /case/case/10efedb5f85d976aecf4f27238f7ec3b");
    }
    exit;
}

$stmt->close();

if ($cameFromAdmin) {
    $_SESSION['admin_login_error'] = 'No administrator account uses that Google email.';
    header("Location: " . url('admin-login'));
    exit;
}

if ($mode !== 'signup') {
    redirect_with_error('No account found for this Google email. Please sign up first.', 'login');
}

if (!in_array($role, ['mentee', 'mentor'], true)) {
    redirect_with_error('Please select a role before continuing with Google.', 'signup');
}

if ($firstname === '') {
    $firstname = 'Google';
}

if ($lastname === '') {
    $lastname = 'User';
}

$con->begin_transaction();

try {
    // users.email is the one place an address is stored.
    $stmt1 = $con->prepare("INSERT INTO users (firstname, middlename, lastname, role, email) VALUES (?, ?, ?, ?, ?)");
    $stmt1->bind_param("sssss", $firstname, $middlename, $lastname, $role, $email);
    $stmt1->execute();
    $user_id = $stmt1->insert_id;
    $stmt1->close();

    $randomPasswordHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
    $stmt3 = $con->prepare("INSERT INTO passwords (user_id, password_hash) VALUES (?, ?)");
    $stmt3->bind_param("is", $user_id, $randomPasswordHash);
    $stmt3->execute();
    $stmt3->close();

    $con->commit();
} catch (Exception $e) {
    $con->rollback();
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
    header("Location: /case/case/8002a71bf36398e654ce5d3ba3494d8d");
} else {
    header("Location: /case/case/140204565c663d348ed7ae748f5a5802");
}
exit;
