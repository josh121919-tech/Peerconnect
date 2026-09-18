<?php
// "Download My Data" — everything PeerConnect holds about this account, as a
// JSON file they can keep. Read-only; scoped to the signed-in user throughout.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Not signed in.');
}

$user_id = (int)$_SESSION['user_id'];

$data = DataExportRepository::forUser($con, $user_id);

if ($data === null) {
    http_response_code(404);
    exit('Account not found.');
}

$export = [
    'exported_at' => date('c'),
    'about'       => 'Everything PeerConnect stores about your account. Generated from Settings → Data Privacy. '
                   . 'Passwords and sign-in tokens are never included.',
] + $data;

$json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="peerconnect-my-data-' . $user_id . '.json"');
header('Content-Length: ' . strlen($json));
echo $json;
