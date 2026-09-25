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

$ext   = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$isPdf = $type === 'application/pdf';

/*
 * Two things at one address.
 *
 * Without ?raw, a page that shows the document fitted to the window. A photo
 * of an ID card is a few thousand pixels wide, and a browser handed the file
 * on its own draws it at that size — the owner opened a link from their email
 * and got a corner of a document, with no way to see the rest but to scroll
 * around it.
 *
 * With ?raw=1, the bytes, which is what that page's <img> asks for. The
 * signature is checked the same either way, so the second address is worth no
 * more than the first.
 */
if (!isset($_GET['raw'])) {
    $rawUrl = htmlspecialchars(
        AdminReviewLink::url('admin-review-file', $check['id'], $check['action'],
            (int)($_GET['e'] ?? 0)) . '&raw=1',
        ENT_QUOTES
    );
    $label = $check['action'] === 'id' ? 'Valid ID' : 'Certificate of Registration';

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title><?= htmlspecialchars($label) ?> — <?= htmlspecialchars(pc_setting($con, 'platform_name')) ?></title>
        <style>
            :root { color-scheme: light; }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                background: #0E1626;
                font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif;
                color: #E7EDF7;
            }

            header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 14px;
                flex-wrap: wrap;
                padding: 12px 18px;
                background: rgba(255, 255, 255, .06);
            }

            header b { font-size: 14.5px; font-weight: 600; }

            header a {
                color: #BFD9F6;
                font-size: 13px;
                text-decoration: none;
                border: 1px solid rgba(255, 255, 255, .22);
                border-radius: 8px;
                padding: 7px 13px;
            }

            header a:hover { background: rgba(255, 255, 255, .1); }

            main {
                flex: 1;
                min-height: 0;
                display: grid;
                place-items: center;
                padding: 16px;
            }

            /* The whole document, inside the window, however large the file is. */
            img {
                max-width: 100%;
                max-height: calc(100vh - 92px);
                object-fit: contain;
                border-radius: 10px;
                background: #fff;
            }

            iframe {
                width: 100%;
                height: calc(100vh - 92px);
                border: 0;
                border-radius: 10px;
                background: #fff;
            }

            @media (max-width: 640px) {
                header { padding: 10px 12px; }
                main { padding: 10px; }
                img, iframe { max-height: calc(100vh - 104px); height: calc(100vh - 104px); }
                img { height: auto; }
            }
        </style>
    </head>

    <body>
        <header>
            <b><?= htmlspecialchars($label) ?></b>
            <a href="<?= $rawUrl ?>" target="_blank" rel="noopener">Open full size</a>
        </header>
        <main>
            <?php if ($isPdf): ?>
                <iframe src="<?= $rawUrl ?>" title="<?= htmlspecialchars($label) ?>"></iframe>
            <?php else: ?>
                <img src="<?= $rawUrl ?>" alt="<?= htmlspecialchars($label) ?>">
            <?php endif; ?>
        </main>
    </body>

    </html>
    <?php
    exit;
}

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="document.' . $ext . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
readfile($path);
