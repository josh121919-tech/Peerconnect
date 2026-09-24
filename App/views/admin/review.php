<?php

/**
 * admin-review — the page the owner lands on from their email.
 *
 * No sign-in. The signature in the address is the authorisation, and it is
 * good for one application until that application is decided. What it shows is
 * everything needed to make the decision: who applied, what they claim, and
 * both documents.
 *
 * The decision itself is a POST from the form below, never this GET. Mail
 * providers and antivirus scanners fetch the links in a message to check them;
 * an approve-on-GET would hand out administrator access the moment the mail
 * arrived, with nobody having read it. See AdminReviewLink.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../../services/AdminReviewLink.php';

$check = AdminReviewLink::check($_GET);
$app   = null;
$who   = null;
$error = $check['ok'] ? '' : $check['error'];

if ($check['ok']) {
    $app = VerificationRepository::byId($con, $check['id']);
    if ($app === null) {
        $error = 'That application no longer exists.';
    } elseif (UserRepository::role($con, (int)$app['user_id']) !== 'admin') {
        // Members are reviewed on the site by somebody signed in.
        $error = 'That application is not an administrator application.';
    } elseif (($app['status'] ?? '') !== 'pending') {
        $error = 'That application has already been ' . htmlspecialchars((string)$app['status']) . '.';
    } else {
        $who = UserRepository::namesAndPhoto($con, (int)$app['user_id']);
    }
}

$expires  = (int)($_GET['e'] ?? 0);
$idUrl    = $app ? AdminReviewLink::url('admin-review-file', (int)$app['verification_id'], 'id', $expires) : '';
$corUrl   = $app ? AdminReviewLink::url('admin-review-file', (int)$app['verification_id'], 'cor', $expires) : '';
$platform = pc_setting($con, 'platform_name');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Administrator application — <?= htmlspecialchars($platform) ?></title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        body { background: var(--bg); }

        .rv-wrap { max-width: 640px; margin: 0 auto; padding: 36px 16px 64px; }

        .rv-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 26px;
        }

        .rv-card h1 {
            margin: 0 0 6px;
            font-size: 20px;
            font-weight: 700;
            color: var(--navy);
        }

        .rv-sub { margin: 0 0 20px; font-size: 13.5px; color: var(--gray-600); }

        .rv-who {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 16px;
        }

        .rv-av {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: var(--accent-faint);
            color: var(--navy);
            display: grid;
            place-items: center;
            font-weight: 700;
            font-size: 15px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .rv-av img { width: 100%; height: 100%; object-fit: cover; }

        .rv-rows { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }

        .rv-k {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--gray-500);
            margin-bottom: 3px;
        }

        .rv-v { font-size: 13.5px; color: var(--gray-800); word-break: break-word; }

        .rv-docs { display: flex; gap: 10px; flex-wrap: wrap; margin: 20px 0 4px; }

        .rv-note {
            border-radius: var(--radius);
            padding: 13px 15px;
            font-size: 13px;
            line-height: 1.6;
        }

        .rv-note.bad { background: var(--danger-bg); color: var(--danger); }

        .rv-acts {
            display: flex;
            gap: 10px;
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .rv-reason {
            width: 100%;
            font: inherit;
            font-size: 13.5px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            margin-top: 10px;
            resize: vertical;
            min-height: 74px;
        }

        .btn-danger {
            background: var(--danger);
            color: #fff;
            border-color: var(--danger);
        }

        @media (max-width: 560px) {
            .rv-rows { grid-template-columns: minmax(0, 1fr); }
        }
    </style>
</head>

<body>
    <div class="rv-wrap">
        <div class="rv-card">
            <?php if ($error !== ''): ?>
                <h1>Nothing to review</h1>
                <p class="rv-sub">This link cannot be used.</p>
                <div class="rv-note bad"><?= htmlspecialchars($error) ?></div>
            <?php else:
                $name = trim(($who['firstname'] ?? '') . ' ' . ($who['lastname'] ?? ''));
                $name = $name !== '' ? $name : (string)$app['full_name'];
            ?>
                <h1>Administrator application</h1>
                <p class="rv-sub">
                    Approving this opens the full administrator dashboard to them, including every
                    member's information. Check both documents before you decide.
                </p>

                <div class="rv-who">
                    <span class="rv-av"><?= pc_avatar($who['profile_image'] ?? '', $name) ?></span>
                    <div style="min-width:0;">
                        <div style="font-weight:700;color:var(--gray-900);"><?= htmlspecialchars($name) ?></div>
                        <div style="font-size:12.5px;color:var(--gray-500);"><?= htmlspecialchars((string)($who['email'] ?? '')) ?></div>
                    </div>
                </div>

                <div class="rv-rows">
                    <div>
                        <div class="rv-k">Student ID</div>
                        <div class="rv-v"><?= htmlspecialchars((string)$app['student_id']) ?></div>
                    </div>
                    <div>
                        <div class="rv-k">Post</div>
                        <div class="rv-v"><?= htmlspecialchars((string)$app['club']) ?></div>
                    </div>
                    <div>
                        <div class="rv-k">Course</div>
                        <div class="rv-v"><?= htmlspecialchars((string)$app['course']) ?></div>
                    </div>
                    <div>
                        <div class="rv-k">Year level</div>
                        <div class="rv-v"><?= htmlspecialchars((string)$app['year_level']) ?></div>
                    </div>
                    <div>
                        <div class="rv-k">Submitted</div>
                        <div class="rv-v"><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$app['submitted_at']))) ?></div>
                    </div>
                </div>

                <div class="rv-docs">
                    <a class="btn btn-ghost" href="<?= htmlspecialchars($idUrl) ?>" target="_blank" rel="noopener">View ID</a>
                    <a class="btn btn-ghost" href="<?= htmlspecialchars($corUrl) ?>" target="_blank" rel="noopener">View COR</a>
                </div>

                <?php // Two forms, so each button carries its own decision and the
                      // reason belongs to the rejection. The signed query travels
                      // with them: this page proved nothing that the POST can rely on. ?>
                <form method="POST" action="<?= url('admin-review-act') ?>">
                    <input type="hidden" name="v" value="<?= (int)$app['verification_id'] ?>">
                    <input type="hidden" name="a" value="review">
                    <input type="hidden" name="e" value="<?= (int)$expires ?>">
                    <input type="hidden" name="s" value="<?= htmlspecialchars((string)$_GET['s']) ?>">

                    <div class="rv-acts">
                        <button class="btn btn-primary" type="submit" name="decision" value="approve">
                            Approve this administrator
                        </button>
                        <button class="btn btn-danger" type="submit" name="decision" value="reject">
                            Reject
                        </button>
                    </div>

                    <label for="rv-reason" class="rv-k" style="display:block;margin-top:16px;">Reason, if rejecting</label>
                    <textarea id="rv-reason" class="rv-reason" name="notes" maxlength="500"
                              placeholder="They are told this, so say what was wrong."></textarea>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
