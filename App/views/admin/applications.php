<?php

/**
 * admin-applications — administrator applications, for the owner only.
 *
 * NOT linked from any menu, and deliberately so. Administrator applications
 * were taken out of User Management because a control that grants
 * administrator access should not sit on a screen every administrator can
 * open. This is the way back in when the alert never arrived, SMTP is down, or
 * a signed link has expired — without which a lost email could leave an
 * account that can never be approved.
 *
 * SECURITY: the owner, and nobody else. Being an administrator is not enough:
 * the whole point is that administrators do not appoint each other. The owner
 * is whoever owner_email names, which is the same address the alerts go to.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../../services/AdminReviewLink.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ' . url('admin-login'));
    exit;
}

$me    = (int)$_SESSION['user_id'];
$owner = trim((string)pc_setting($con, 'owner_email'));
$mine  = trim((string)(UserRepository::emailOf($con, $me) ?? ''));

if ($owner === '' || strcasecmp($owner, $mine) !== 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Administrator applications are reviewed by the site owner.');
}

$apps     = AdminUserRepository::adminVerificationQueue($con);
$platform = pc_setting($con, 'platform_name');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Administrator applications — <?= htmlspecialchars($platform) ?></title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        body { background: var(--bg); }
        .aa-wrap { max-width: 720px; margin: 0 auto; padding: 36px 16px 64px; }

        .aa-head h1 {
            margin: 0 0 6px;
            font-size: 21px;
            font-weight: 700;
            color: var(--navy);
        }

        .aa-head p {
            margin: 0 0 22px;
            font-size: 13.5px;
            color: var(--gray-600);
            line-height: 1.6;
        }

        .aa-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 14px;
        }

        .aa-name { font-size: 15px; font-weight: 700; color: var(--gray-900); }
        .aa-mail { font-size: 12.5px; color: var(--gray-500); margin-bottom: 14px; }

        .aa-rows { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }

        .aa-k {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--gray-500);
        }

        .aa-v { font-size: 13.5px; color: var(--gray-800); }

        .aa-acts { margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap; }

        .aa-empty {
            background: var(--surface);
            border: 1px dashed var(--border);
            border-radius: var(--radius-lg);
            padding: 34px;
            text-align: center;
            color: var(--gray-500);
            font-size: 13.5px;
        }

        @media (max-width: 560px) { .aa-rows { grid-template-columns: minmax(0, 1fr); } }
    </style>
</head>

<body>
    <div class="aa-wrap">
        <div class="aa-head">
            <h1>Administrator applications</h1>
            <p>
                This page is not in the menu. Applications are normally reviewed from the email sent to
                <?= htmlspecialchars($owner) ?> — this is here for when one of those never arrives or its
                links have expired.
            </p>
        </div>

        <?php if (!$apps): ?>
            <div class="aa-empty">No administrator is waiting for review.</div>
        <?php else: foreach ($apps as $a):
            $vid  = (int)$a['verification_id'];
            $name = trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? '')) ?: (string)$a['full_name'];
        ?>
            <div class="aa-card">
                <div class="aa-name"><?= htmlspecialchars($name) ?></div>
                <div class="aa-mail"><?= htmlspecialchars((string)$a['email']) ?></div>

                <div class="aa-rows">
                    <div><div class="aa-k">Student ID</div><div class="aa-v"><?= htmlspecialchars((string)$a['student_id']) ?></div></div>
                    <div><div class="aa-k">Post</div><div class="aa-v"><?= htmlspecialchars((string)$a['club']) ?></div></div>
                    <div><div class="aa-k">Course</div><div class="aa-v"><?= htmlspecialchars((string)$a['course']) ?></div></div>
                    <div><div class="aa-k">Year level</div><div class="aa-v"><?= htmlspecialchars((string)$a['year_level']) ?></div></div>
                </div>

                <?php // A fresh signed link, so this page needs no decision logic
                      // of its own: it opens the same review page the email does. ?>
                <div class="aa-acts">
                    <a class="btn btn-primary" href="<?= htmlspecialchars(AdminReviewLink::url('admin-review', $vid, 'review')) ?>">
                        Open this application
                    </a>
                    <span style="align-self:center;font-size:12.5px;color:var(--gray-500);">
                        Submitted <?= htmlspecialchars(date('M j, Y', strtotime((string)$a['submitted_at']))) ?>
                    </span>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</body>

</html>
