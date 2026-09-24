<?php

/**
 * admin/verification.php — a new administrator proves who they are.
 *
 * An admin key and an emailed code prove that somebody was invited and can
 * read the address they signed up with. Neither says who is holding them, and
 * an administrator account can read and change every member's data. So a new
 * one now submits the same two documents a mentor does and waits for one of
 * the existing administrators to approve it.
 *
 * Deliberately close to mentorpage/verification.php: same repository, same
 * table, same admin queue, same approve/reject. Only two things differ — there
 * is no expertise to declare, and the club is a post rather than a membership,
 * because the people running this are club officers.
 *
 * SECURITY: reachable only by a signed-in admin who is not yet verified. It is
 * on the gate's allow-list, which is what lets an unapproved admin reach this
 * one page and nothing else. Nothing here grants access: users.verified is set
 * by admin/action_verify.php and nowhere else.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/VerificationFiles.php';
require_once __DIR__ . '/../../services/AdminAlertService.php';
require_once __DIR__ . '/../../services/AdminReviewLink.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ' . url('admin-login'));
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$me      = UserRepository::signInState($con, $user_id);

// Already approved: there is nothing to apply for.
if ($me && !empty($me['verified'])) {
    header('Location: ' . url('admin-dashboard'));
    exit;
}

$existing = VerificationRepository::forUser($con, $user_id);
$errors   = [];
$csrf_ok  = $_SERVER['REQUEST_METHOD'] !== 'POST' || verify_csrf();
if (!$csrf_ok) {
    $errors[] = 'Your session expired. Please try again.';
}

/** The only posts these officers hold, and the clubs they hold them in. */
const ADM_POSITIONS = ['President', 'Vice President'];
const ADM_CLUBS = [
    'Mathematics Club',
    'Science Club',
    'English Club',
    'Social Studies Club',
    'Home Economics Club',
    'Industrial Education Club',
    'Physical Education Club',
    'Special Education Club',
    'Elementary Education Club',
];

/** "President" + "Mathematics Club" reads as one line in the queue. */
function adm_post_label(string $position, string $club): string
{
    return $position . ' of ' . $club;
}

/** Splits that line back apart so the form can be reopened with it filled in. */
function adm_post_parts(?string $stored): array
{
    $stored = trim((string)$stored);
    foreach (ADM_POSITIONS as $p) {
        $prefix = $p . ' of ';
        if (stripos($stored, $prefix) === 0) {
            return [$p, substr($stored, strlen($prefix))];
        }
    }
    return ['', $stored];
}

[$cur_position, $cur_club] = adm_post_parts($existing['club'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrf_ok) {
    $field = fn(string $name): string => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';

    $firstname  = $field('firstname');
    $middlename = $field('middlename');
    $lastname   = $field('lastname');
    $student_id = $field('student_id');
    $course     = $field('course');
    $year_level = $field('year_level');
    $position   = $field('position');
    $club       = $field('club');

    $cur_position = $position;
    $cur_club     = $club;

    $name_rule = '/^[A-Za-z ,.\'-]{2,50}$/';
    if (!$firstname)                             $errors[] = "First name is required.";
    elseif (!preg_match($name_rule, $firstname)) $errors[] = "First name: letters only, 2–50 characters.";

    if ($middlename !== '' && !preg_match('/^[A-Za-z ,.\'-]{1,100}$/', $middlename)) {
        $errors[] = "Middle name: letters only, up to 100 characters.";
    }

    if (!$lastname)                              $errors[] = "Surname is required.";
    elseif (!preg_match($name_rule, $lastname))  $errors[] = "Surname: letters only, 2–50 characters.";

    $full_name = trim(preg_replace('/\s+/', ' ', $firstname . ' ' . $middlename . ' ' . $lastname));

    if (!$student_id)                                          $errors[] = "Student ID is required.";
    elseif (!preg_match('/^[A-Z0-9\-]{3,20}$/i', $student_id)) $errors[] = "Student ID: letters, numbers and hyphens only, 3–20 characters.";

    if (!$course)                  $errors[] = "Course is required.";
    elseif (strlen($course) > 100) $errors[] = "Course must be 100 characters or fewer.";

    $allowed_levels = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'];
    if (!$year_level)                                     $errors[] = "Year level is required.";
    elseif (!in_array($year_level, $allowed_levels, true)) $errors[] = "Please select a valid year level.";

    if (!in_array($position, ADM_POSITIONS, true)) $errors[] = "Please select your post in the club.";
    if (!in_array($club, ADM_CLUBS, true))         $errors[] = "Please select your club.";

    // Stored as one line, because that column is one line and the queue reads
    // it as one. The form splits it back apart when it is reopened.
    $club_line = (empty($errors)) ? adm_post_label($position, $club) : '';
    if ($club_line !== '' && mb_strlen($club_line) > 100) {
        $errors[] = "That post and club are too long to store together.";
    }

    // Both documents are inspected before either is kept, so a half-valid
    // form never leaves one file behind.
    $id_file  = VerificationFiles::inspect($_FILES['id_image'] ?? null, 'Valid ID');
    $cor_file = VerificationFiles::inspect($_FILES['credential_image'] ?? null, 'COR');

    if (!empty($id_file['error']))                $errors[] = $id_file['error'];
    elseif (!$id_file['present'] && !$existing)   $errors[] = "Valid ID is required.";

    if (!empty($cor_file['error']))               $errors[] = $cor_file['error'];
    elseif (!$cor_file['present'] && !$existing)  $errors[] = "Certificate of Registration is required.";

    $old_id           = $existing['id_image']         ?? null;
    $old_cor          = $existing['credential_image'] ?? null;
    $id_image         = $old_id;
    $credential_image = $old_cor;
    $stored           = [];

    if (empty($errors) && !empty($id_file['ext'])) {
        $id_image = VerificationFiles::store($_FILES['id_image'], 'mid', $id_file['ext']);
        if ($id_image === null) $errors[] = "Valid ID could not be saved. Please try again.";
        else                    $stored[] = $id_image;
    }
    if (empty($errors) && !empty($cor_file['ext'])) {
        $credential_image = VerificationFiles::store($_FILES['credential_image'], 'cor', $cor_file['ext']);
        if ($credential_image === null) $errors[] = "COR could not be saved. Please try again.";
        else                            $stored[] = $credential_image;
    }
    if (!empty($errors)) {
        foreach ($stored as $f) VerificationFiles::remove($f);
    }

    if (empty($errors)) {
        try {
            VerificationRepository::submit($con, $user_id, [
                'full_name'        => $full_name,
                'firstname'        => $firstname,
                'middlename'       => $middlename !== '' ? $middlename : null,
                'lastname'         => $lastname,
                'student_id'       => $student_id,
                'course'           => $course,
                'year_level'       => $year_level,
                'club'             => $club_line,
                'id_image'         => $id_image,
                'credential_image' => $credential_image,
            ], (bool)$existing);
        } catch (Throwable $e) {
            foreach ($stored as $f) VerificationFiles::remove($f);
            throw $e;
        }

        if ($id_image !== $old_id)          VerificationFiles::remove($old_id);
        if ($credential_image !== $old_cor) VerificationFiles::remove($old_cor);

        /*
         * The existing administrators are told, because nothing else would
         * tell them: this queue is not somewhere anyone watches. The documents
         * are NOT attached — they are behind the verification-file route,
         * which checks the reader is an admin. An email attachment would not.
         *
         * A failure here is logged and swallowed: the application is in, and
         * saying otherwise because the mail server is down would be a lie.
         */
        try {
            $vid = (int)(VerificationRepository::forUser($con, $user_id)['verification_id'] ?? 0);
            if ($vid > 0) {
                /*
                 * Everything needed to decide, in the message: the claims, a
                 * link to each document, and one button per decision. The
                 * buttons open a page rather than acting themselves — a mail
                 * scanner fetching the links in this message would otherwise
                 * approve the applicant before anybody read it.
                 *
                 * The documents are linked, not attached: the files stay on
                 * this server, and the links stop working the moment the
                 * decision is made. An attachment would be a permanent copy of
                 * somebody's school ID sitting in an inbox.
                 */
                $expires = time() + AdminReviewLink::TTL_DAYS * 86400;
                $review  = AdminReviewLink::url('admin-review', $vid, 'review', $expires);
                $idLink  = AdminReviewLink::url('admin-review-file', $vid, 'id', $expires);
                $corLink = AdminReviewLink::url('admin-review-file', $vid, 'cor', $expires);
                $e       = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

                $body = '<div style="font:15px/1.6 system-ui,-apple-system,Segoe UI,sans-serif;color:#3D424D;">'
                    . '<h2 style="margin:0 0 4px;font-size:19px;color:#071B4D;">An administrator is waiting for review</h2>'
                    . '<p style="margin:0 0 18px;color:#565B66;">'
                    . $e($full_name) . ' has applied for an administrator account. Approving opens every '
                    . 'member\'s information to them, so check both documents first.</p>'
                    . '<table style="border-collapse:collapse;font-size:14px;margin-bottom:18px;">'
                    . '<tr><td style="padding:3px 16px 3px 0;color:#717680;">Post</td><td><b>' . $e($club_line) . '</b></td></tr>'
                    . '<tr><td style="padding:3px 16px 3px 0;color:#717680;">Student ID</td><td>' . $e($student_id) . '</td></tr>'
                    . '<tr><td style="padding:3px 16px 3px 0;color:#717680;">Course</td><td>' . $e($course) . '</td></tr>'
                    . '<tr><td style="padding:3px 16px 3px 0;color:#717680;">Year level</td><td>' . $e($year_level) . '</td></tr>'
                    . '<tr><td style="padding:3px 16px 3px 0;color:#717680;">Email</td><td>' . $e($_SESSION['email'] ?? '') . '</td></tr>'
                    . '</table>'
                    . '<p style="margin:0 0 8px;font-weight:600;color:#3D424D;">Their documents</p>'
                    . '<p style="margin:0 0 20px;">'
                    . '<a href="' . $e($idLink) . '" style="display:inline-block;padding:9px 16px;margin:0 8px 8px 0;'
                    . 'border:1px solid #C2C5CA;border-radius:8px;color:#0868AD;text-decoration:none;font-size:14px;">View ID</a>'
                    . '<a href="' . $e($corLink) . '" style="display:inline-block;padding:9px 16px;margin:0 8px 8px 0;'
                    . 'border:1px solid #C2C5CA;border-radius:8px;color:#0868AD;text-decoration:none;font-size:14px;">View COR</a>'
                    . '</p>'
                    . '<p style="margin:0 0 10px;">'
                    . '<a href="' . $e($review) . '" style="display:inline-block;padding:12px 22px;margin:0 8px 8px 0;'
                    . 'background:#1F7A5C;border-radius:8px;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;">'
                    . 'Approve</a>'
                    . '<a href="' . $e($review) . '" style="display:inline-block;padding:12px 22px;margin:0 8px 8px 0;'
                    . 'background:#C0392B;border-radius:8px;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;">'
                    . 'Reject</a>'
                    . '</p>'
                    . '<p style="margin:0;font-size:12.5px;color:#717680;">'
                    . 'Both buttons open the application, where you confirm the decision. The links stop '
                    . 'working once it is decided, and expire after ' . AdminReviewLink::TTL_DAYS . ' days.</p>'
                    . '</div>';

                AdminAlertService::send(
                    $con,
                    'An administrator is waiting for review — ' . pc_setting($con, 'platform_name'),
                    '', '', '', '', $user_id, $body
                );
            }
        } catch (Throwable $e) {
            error_log('Admin verification alert: ' . $e->getMessage());
        }

        // users.verified is deliberately NOT set here. Only action_verify.php
        // grants an administrator their access.
        pc_flash('success', 'The administrators have been told, and you will hear either way.', 'Application submitted');
        header('Location: ' . url('admin-verification'));
        exit;
    }
}

$existing   = VerificationRepository::forUser($con, $user_id) ?: $existing;
$status     = $existing['status'] ?? null;
$notes      = $existing['admin_notes'] ?? '';
$acct       = UserRepository::names($con, $user_id) ?: [];
$page_title = 'Administrator verification';

/*
 * ── Waiting ──────────────────────────────────────────────────────────────
 *
 * While the application is pending there is nothing to fill in, so the form
 * is not shown at all — the same shape mentorpage/verification.php uses. The
 * poller watches for the owner's decision and moves the page on by itself, so
 * somebody who leaves this open does not have to guess when to reload.
 */
if ($status === 'pending'):
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Waiting for verification — <?= htmlspecialchars(pc_setting($con, 'platform_name')) ?></title>
        <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
        <style>
            body {
                background: var(--bg);
                min-height: 100vh;
                display: grid;
                place-items: center;
                margin: 0;
                padding: 24px;
            }

            .aw-card {
                background: var(--surface);
                border: 1px solid var(--border);
                border-radius: var(--radius-lg);
                padding: 40px 32px;
                max-width: 480px;
                text-align: center;
            }

            .aw-ring {
                width: 62px;
                height: 62px;
                margin: 0 auto 20px;
                border-radius: 50%;
                border: 3px solid var(--accent-faint);
                border-top-color: var(--mint);
                animation: aw-spin 1s linear infinite;
            }

            @keyframes aw-spin { to { transform: rotate(360deg); } }

            @media (prefers-reduced-motion: reduce) {
                .aw-ring { animation: none; border-top-color: var(--accent-faint); }
            }

            .aw-card h1 {
                margin: 0 0 10px;
                font-size: 20px;
                font-weight: 700;
                color: var(--navy);
            }

            .aw-card p {
                margin: 0 0 8px;
                font-size: 13.5px;
                line-height: 1.65;
                color: var(--gray-600);
            }

            .aw-meta {
                margin-top: 22px;
                padding-top: 18px;
                border-top: 1px solid var(--border);
                font-size: 12.5px;
                color: var(--gray-500);
            }
        </style>
    </head>

    <body>
        <div class="aw-card">
            <div class="aw-ring" role="status" aria-label="Waiting for a decision"></div>
            <h1>Waiting for verification</h1>
            <p>
                Your ID and Certificate of Registration are with the site owner. They will approve or
                reject the account, and you will be told either way.
            </p>
            <p>You can close this page — signing in again brings you back here.</p>
            <div class="aw-meta">
                Submitted <?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$existing['submitted_at']))) ?>
                &nbsp;·&nbsp; <a href="<?= url('logout') ?>" style="color:var(--mint);">Sign out</a>
            </div>
        </div>

        <script>
            /* Polls for the decision rather than making somebody guess when to
               reload. Stops on the first answer that is not 'pending'. */
            (function () {
                const CHECK = <?= json_encode(url('admin-check-status')) ?>;
                let stopped = false;

                async function look() {
                    if (stopped) return;
                    try {
                        const res = await fetch(CHECK, { headers: { 'Accept': 'application/json' } });
                        if (res.ok) {
                            const d = await res.json();
                            if (d.status === 'approved' && d.redirect) {
                                stopped = true;
                                location.href = d.redirect;
                                return;
                            }
                            if (d.status === 'rejected' && d.reject_redirect) {
                                stopped = true;
                                location.href = d.reject_redirect;
                                return;
                            }
                        }
                    } catch (e) {
                        /* Offline or a blip: try again on the next tick. */
                    }
                    setTimeout(look, 15000);
                }
                setTimeout(look, 15000);
            })();
        </script>
    </body>

    </html>
<?php
    exit;
endif;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator verification — <?= htmlspecialchars(pc_setting($con, 'platform_name')) ?></title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        body { background: var(--bg); }

        .av-wrap {
            max-width: 720px;
            margin: 0 auto;
            padding: 32px 16px 64px;
        }

        .av-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 26px;
        }

        .av-head { margin-bottom: 18px; }

        .av-head h1 {
            margin: 0 0 6px;
            font-size: 20px;
            font-weight: 700;
            color: var(--navy);
        }

        .av-head p {
            margin: 0;
            font-size: 13.5px;
            color: var(--gray-600);
            line-height: 1.6;
        }

        .av-note {
            border-radius: var(--radius);
            padding: 13px 15px;
            font-size: 13px;
            line-height: 1.6;
            margin-bottom: 18px;
        }

        .av-note.wait { background: var(--gold-light); color: var(--gray-700); }
        .av-note.no   { background: var(--danger-bg); color: var(--danger); }
        .av-note.bad  { background: var(--danger-bg); color: var(--danger); }

        .av-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .av-field { display: flex; flex-direction: column; gap: 6px; }
        .av-field.wide { grid-column: 1 / -1; }

        .av-field label {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--gray-700);
        }

        .av-field input,
        .av-field select {
            width: 100%;
            font: inherit;
            font-size: 13.5px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--surface);
            color: var(--ink);
        }

        .av-field input[type="file"] { padding: 8px; background: var(--gray-50); }

        .av-hint { font-size: 11.5px; color: var(--gray-500); }

        .av-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        @media (max-width: 640px) {
            .av-grid { grid-template-columns: minmax(0, 1fr); }
        }
    </style>
</head>

<body>
    <div class="av-wrap">
        <div class="av-card">
            <div class="av-head">
                <h1>Administrator verification</h1>
                <p>
                    An administrator can see and change every member's information, so the account is
                    reviewed by an existing administrator before it is opened. Submit your ID and your
                    Certificate of Registration, and you will be told either way.
                </p>
            </div>

            <?php if ($errors): ?>
                <div class="av-note bad" role="alert">
                    <?php foreach ($errors as $e): ?>
                        <div><?= htmlspecialchars($e) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($status === 'pending'): ?>
                <div class="av-note wait" role="status">
                    <b>Submitted.</b> The administrators have been told and are reviewing it. You can
                    send corrected details below if something was wrong.
                </div>
            <?php elseif ($status === 'rejected'): ?>
                <div class="av-note no" role="status">
                    <b>Not approved.</b>
                    <?= $notes !== '' ? htmlspecialchars($notes) : 'No reason was given.' ?>
                    You can correct the details and submit again.
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                <div class="av-grid">
                    <div class="av-field">
                        <label for="av-first">First name</label>
                        <input id="av-first" name="firstname" maxlength="50" required
                               value="<?= htmlspecialchars($_POST['firstname'] ?? $existing['firstname'] ?? $acct['firstname'] ?? '') ?>">
                    </div>

                    <div class="av-field">
                        <label for="av-middle">Middle name <span class="av-hint">(optional)</span></label>
                        <input id="av-middle" name="middlename" maxlength="100"
                               value="<?= htmlspecialchars($_POST['middlename'] ?? $existing['middlename'] ?? '') ?>">
                    </div>

                    <div class="av-field">
                        <label for="av-last">Surname</label>
                        <input id="av-last" name="lastname" maxlength="50" required
                               value="<?= htmlspecialchars($_POST['lastname'] ?? $existing['lastname'] ?? $acct['lastname'] ?? '') ?>">
                    </div>

                    <div class="av-field">
                        <label for="av-sid">Student ID</label>
                        <input id="av-sid" name="student_id" maxlength="20" required
                               value="<?= htmlspecialchars($_POST['student_id'] ?? $existing['student_id'] ?? '') ?>">
                    </div>

                    <div class="av-field">
                        <label for="av-course">Course</label>
                        <input id="av-course" name="course" maxlength="100" required
                               value="<?= htmlspecialchars($_POST['course'] ?? $existing['course'] ?? '') ?>">
                    </div>

                    <div class="av-field">
                        <label for="av-year">Year level</label>
                        <select id="av-year" name="year_level" required>
                            <option value="">Select year level</option>
                            <?php
                            $yl = $_POST['year_level'] ?? $existing['year_level'] ?? '';
                            foreach (['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'] as $lvl): ?>
                                <option value="<?= $lvl ?>" <?= $yl === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php // Post and club are two fields and one stored line: "President of Mathematics Club". ?>
                    <div class="av-field">
                        <label for="av-pos">Post</label>
                        <select id="av-pos" name="position" required>
                            <option value="">Select post</option>
                            <?php foreach (ADM_POSITIONS as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>" <?= $cur_position === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="av-hint">Only club presidents and vice presidents administer this site.</span>
                    </div>

                    <div class="av-field">
                        <label for="av-club">Club</label>
                        <select id="av-club" name="club" required>
                            <option value="">Select club</option>
                            <?php foreach (ADM_CLUBS as $cl): ?>
                                <option value="<?= htmlspecialchars($cl) ?>" <?= $cur_club === $cl ? 'selected' : '' ?>><?= htmlspecialchars($cl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="av-field wide">
                        <label for="av-id">Valid ID</label>
                        <input id="av-id" type="file" name="id_image" accept=".jpg,.jpeg,.png,.pdf">
                        <span class="av-hint">
                            <?= !empty($existing['id_image'])
                                ? 'A file is already on the application. Choose another only to replace it.'
                                : 'JPG, PNG or PDF.' ?>
                        </span>
                    </div>

                    <div class="av-field wide">
                        <label for="av-cor">Certificate of Registration</label>
                        <input id="av-cor" type="file" name="credential_image" accept=".jpg,.jpeg,.png,.pdf">
                        <span class="av-hint">
                            <?= !empty($existing['credential_image'])
                                ? 'A file is already on the application. Choose another only to replace it.'
                                : 'JPG, PNG or PDF.' ?>
                        </span>
                    </div>
                </div>

                <div class="av-actions">
                    <a class="btn btn-ghost" href="<?= url('logout') ?>">Sign out</a>
                    <button class="btn btn-primary" type="submit">
                        <?= $status === null ? 'Submit for review' : 'Send updated details' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>

</html>
