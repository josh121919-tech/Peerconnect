<?php

/**
 * certificates/view.php — one certificate, full size and ready to print.
 *
 * This is what makes a certificate a real thing rather than a database row. It
 * is drawn from the design plus the recipient's name, achievement and issue
 * date at the moment it is opened — there is no stored file, so there is
 * nothing to go missing, and no PDF library has to be installed. Printing from
 * the browser is also how the recipient saves it as a PDF.
 *
 * Who may open it: the person it was issued to, and any admin. Nobody else —
 * a certificate carries someone's name, and the id in the URL is sequential,
 * so an unguarded page would let anyone walk the list.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../admin/includes/award_data.php';
require_auth();

date_default_timezone_set('Asia/Manila');

$certId = (int)($_GET['id'] ?? 0);
$me     = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

$st = $con->prepare("
    SELECT uc.cert_id, uc.user_id, uc.achievement, uc.awarded_at,
           t.name AS title, t.description, t.category, t.design,
           CONCAT_WS(' ', u.firstname, u.lastname) AS recipient,
           CONCAT_WS(' ', a.firstname, a.lastname) AS issuer
      FROM user_certificates uc
      JOIN certificate_templates t ON t.template_id = uc.template_id
      JOIN users u ON u.user_id = uc.user_id
      LEFT JOIN users a ON a.user_id = uc.awarded_by
     WHERE uc.cert_id = ?
");
$st->bind_param('i', $certId);
$st->execute();
$cert = $st->get_result()->fetch_assoc();
$st->close();

if (!$cert) {
    http_response_code(404);
    $notFound = true;
} elseif (!$isAdmin && (int)$cert['user_id'] !== $me) {
    http_response_code(403);
    $forbidden = true;
}

$title = $cert ? $cert['title'] : 'Certificate';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?><?= $cert ? ' — ' . htmlspecialchars($cert['recipient']) : '' ?></title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            background: #EEF0F4;
            font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
            color: #3D424D;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 28px 20px 48px;
        }

        .cv-bar {
            width: 100%;
            max-width: 760px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .cv-bar h1 { margin: 0; font-size: 17px; font-weight: 700; color: #071B4D; }
        .cv-bar p { margin: 2px 0 0; font-size: 12.5px; color: #7A8090; }

        .cv-acts { display: flex; gap: 9px; }

        .cv-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 16px; border: 1px solid #D8DCE4; border-radius: 10px;
            background: #fff; font-family: inherit; font-size: 13px; font-weight: 600;
            color: #3D424D; text-decoration: none; cursor: pointer;
        }
        .cv-btn:hover { border-color: #087FC1; color: #087FC1; }
        .cv-btn.primary { background: #071B4D; border-color: #071B4D; color: #fff; }
        .cv-btn.primary:hover { background: #0E2E6B; color: #fff; }
        .cv-btn svg { width: 15px; height: 15px; }

        .cv-sheet {
            /* The certificate is a fixed 720 × 510 at scale 1; on a narrow
               screen it is scaled down rather than clipped or reflowed, so it
               always looks like the document it is. */
            transform-origin: top center;
            box-shadow: 0 18px 44px -20px rgba(16, 24, 40, .5);
        }

        .cv-note {
            max-width: 720px;
            margin-top: 22px;
            font-size: 12px;
            color: #7A8090;
            line-height: 1.65;
            text-align: center;
        }

        .cv-msg {
            max-width: 460px; margin-top: 60px; text-align: center;
            background: #fff; border-radius: 16px; padding: 34px 30px;
            box-shadow: 0 12px 32px -18px rgba(16, 24, 40, .3);
        }
        .cv-msg h2 { margin: 0 0 8px; font-size: 19px; color: #071B4D; }
        .cv-msg p { margin: 0; font-size: 13.5px; line-height: 1.6; color: #565B66; }
        .cv-msg a { display: inline-block; margin-top: 18px; font-size: 13px; font-weight: 600; color: #087FC1; text-decoration: none; }

        @media print {
            /* Only the certificate is printed: no toolbar, no page background,
               and landscape so it fills the sheet the way it is designed. */
            @page { size: landscape; margin: 10mm; }
            body { background: #fff; padding: 0; display: block; }
            .cv-bar, .cv-note { display: none !important; }
            .cv-sheet { box-shadow: none; transform: none !important; }
        }
    </style>
</head>

<body>
    <?php if (!empty($notFound)): ?>
        <div class="cv-msg">
            <h2>Certificate not found</h2>
            <p>This certificate does not exist, or it has been withdrawn.</p>
            <a href="<?= url($isAdmin ? 'admin-certificates' : 'settings') ?>">Go back</a>
        </div>

    <?php elseif (!empty($forbidden)): ?>
        <div class="cv-msg">
            <h2>Not your certificate</h2>
            <p>Certificates can only be opened by the person they were issued to.</p>
            <a href="<?= url(($_SESSION['role'] ?? '') === 'mentor' ? 'mentor-profile' : 'mentee-profile') ?>">Go to your profile</a>
        </div>

    <?php else: ?>
        <div class="cv-bar">
            <div>
                <h1><?= htmlspecialchars($cert['title']) ?></h1>
                <p>
                    Issued to <?= htmlspecialchars($cert['recipient']) ?>
                    on <?= date('F j, Y', strtotime($cert['awarded_at'])) ?>
                    · <?= htmlspecialchars(aw_cert_ref((int)$cert['cert_id'], $cert['awarded_at'])) ?>
                </p>
            </div>
            <div class="cv-acts">
                <?php if ($isAdmin): ?>
                    <a class="cv-btn" href="<?= url('admin-certificates-issued') ?>">All certificates</a>
                <?php endif; ?>
                <button type="button" class="cv-btn primary" onclick="window.print()">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 9V4h10v5M7 19H5.5A1.5 1.5 0 0 1 4 17.5v-6A1.5 1.5 0 0 1 5.5 10h13a1.5 1.5 0 0 1 1.5 1.5v6a1.5 1.5 0 0 1-1.5 1.5H17M7 15h10v5H7v-5Z" /></svg>
                    Print or save as PDF
                </button>
            </div>
        </div>

        <div class="cv-sheet" id="sheet">
            <?= aw_cert_html([
                'title'       => $cert['title'],
                'recipient'   => $cert['recipient'],
                // What it was issued for wins; the design's default wording is
                // only a fallback for a row saved before that was required.
                'achievement' => trim((string)$cert['achievement']) !== '' ? $cert['achievement'] : (string)$cert['description'],
                'date'        => date('F j, Y', strtotime($cert['awarded_at'])),
                'issuer'      => trim((string)$cert['issuer']) !== '' ? $cert['issuer'] : 'PeerConnect Admin',
                'ref'         => aw_cert_ref((int)$cert['cert_id'], $cert['awarded_at']),
                'platform'    => 'PeerConnect',
            ], $cert['design'], 1.0, true) ?>
        </div>

        <p class="cv-note">
            Printing from your browser is also how you save this as a PDF — choose "Save as PDF" as the printer.
            Reference <?= htmlspecialchars(aw_cert_ref((int)$cert['cert_id'], $cert['awarded_at'])) ?>.
        </p>

        <script>
            /* Scale the certificate to fit a narrow window rather than letting
               it overflow. It has a fixed size on purpose — it is a document. */
            (function () {
                var sheet = document.getElementById('sheet');
                if (!sheet) return;

                function fit() {
                    var avail = Math.min(document.documentElement.clientWidth - 40, 900);
                    var scale = Math.min(1, avail / 720);
                    sheet.style.transform = scale < 1 ? 'scale(' + scale + ')' : '';
                    // A scaled element keeps its original box, so the page
                    // would otherwise keep the full-height gap underneath it.
                    sheet.style.marginBottom = scale < 1 ? (-(1 - scale) * 510) + 'px' : '';
                }
                fit();
                window.addEventListener('resize', fit);
            })();
        </script>
    <?php endif; ?>
</body>

</html>
