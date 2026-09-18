<?php

/**
 * verification-file — one document from a member's verification application
 * (their student ID or registration form), shown to that member and to
 * admins, and to nobody else.
 *
 * The documents live in storage/verification, which the web server will not
 * serve directly; see VerificationFiles. An address that names a document the
 * visitor may not see answers exactly as one that names nothing, so the
 * route cannot be used to test which documents exist.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../db.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$role   = (string)($_SESSION['role'] ?? '');
$name   = is_string($_GET['f'] ?? null) ? $_GET['f'] : '';

$refuse = function (int $code, string $text): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    exit($text);
};

if ($userId <= 0) {
    $refuse(403, 'Sign in to view this document.');
}

$allowed = $name !== '' && ($role === 'admin'
    ? VerificationRepository::fileIsKnown($con, $name)
    : VerificationRepository::fileBelongsTo($con, $name, $userId));
$path = $allowed ? VerificationFiles::path($name) : null;
$type = $path ? VerificationFiles::contentType($path) : null;

if (!$path || !$type) {
    $refuse(404, 'Document not found.');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="document.' . $ext . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if ($type !== 'application/pdf') {
    // An image needs nothing else; a browser's PDF viewer does.
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
}
readfile($path);
exit;
