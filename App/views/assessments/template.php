<?php
/**
 * template.php — hands the mentor the worksheet template to start from.
 *
 * Built on the way out rather than kept as a file on disk, so it cannot drift
 * from the layout the importer actually reads. Nothing is stored and nothing
 * is written anywhere servable.
 *
 * SECURITY: mentors only, same as the import it belongs to. It is a download
 * rather than a page, so the filename is quoted and the length declared.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentor') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Only mentors can download the template.';
    exit;
}

// Zipping a few kilobytes is cheap, but it is still work done on request.
if (!rate_limit('assessment_template_' . (int)$_SESSION['user_id'], 30, 300)) {
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Too many downloads in a short time. Please wait a moment.';
    exit;
}

try {
    $bytes = WorksheetTemplate::docx();
} catch (Throwable $e) {
    error_log('WorksheetTemplate: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The template could not be built. Please try again.';
    exit;
}

header('Content-Type: ' . WorksheetTemplate::MIME);
header('Content-Disposition: attachment; filename="' . WorksheetTemplate::FILENAME . '"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $bytes;
