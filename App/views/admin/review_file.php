<?php

/**
 * admin-review-file — one document from an administrator application, opened
 * from the owner's email without signing in.
 *
 * The signature in the address is the authorisation: it names the application
 * and the document, and it was produced with the app's secret. See
 * AdminReviewLink. The application must still be pending, so the link stops
 * working the moment the decision is made — a forwarded email cannot be used
 * to read somebody's ID a month later.
 *
 * A signed-in administrator has their own route for this (verification-file);
 * this one exists only because the owner is reading their inbox, not the site.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../../services/AdminReviewLink.php';
require_once __DIR__ . '/../../services/VerificationFiles.php';

$refuse = function (int $code, string $text): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    exit($text);
};

$check = AdminReviewLink::check($_GET);
if (!$check['ok'] || !in_array($check['action'], ['id', 'cor'], true)) {
    $refuse(403, $check['error'] !== '' ? $check['error'] : 'That link cannot be used.');
}

$app = VerificationRepository::byId($con, $check['id']);
if ($app === null || ($app['status'] ?? '') !== 'pending') {
    $refuse(404, 'That application has already been decided, so its documents are closed.');
}

// Only an administrator's documents are reachable this way. A member's
// application is reviewed on the site, by somebody signed in.
if (UserRepository::role($con, (int)$app['user_id']) !== 'admin') {
    $refuse(404, 'Document not found.');
}

$name = $check['action'] === 'id' ? ($app['id_image'] ?? '') : ($app['credential_image'] ?? '');
$path = $name !== '' ? VerificationFiles::path($name) : null;
$type = $path ? VerificationFiles::contentType($path) : null;

if (!$path || !$type) {
    $refuse(404, 'That document is not on the server.');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="document.' . $ext . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
readfile($path);
