<?php

/**
 * report-proof — the image a member attached to a report, shown to admins
 * and to nobody else.
 *
 * Proof images live in storage/reports, which the web server will not serve
 * directly; see ReportService. An address naming an image that is not a
 * report's proof answers exactly as one naming nothing.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../db.php';

$name = is_string($_GET['f'] ?? null) ? $_GET['f'] : '';

$refuse = function (int $code, string $text): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    exit($text);
};

if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['user_id'])) {
    $refuse(403, 'Only admins can view report evidence.');
}

$known = ReportService::proofName($name) === $name && ModerationRepository::proofIsKnown($con, $name);
$path  = $known ? ReportService::proofPath($name) : null;
$type  = $path ? ReportService::proofContentType($path) : null;
if (!$path || !$type) {
    $refuse(404, 'Image not found.');
}

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="report-proof.' . strtolower(pathinfo($path, PATHINFO_EXTENSION)) . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
readfile($path);
exit;
