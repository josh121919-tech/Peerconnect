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
/** The same list the member verification form offers. */
const ADM_COURSES = [
    'Bachelor of Elementary Education',
    'Bachelor of Special Needs Education (BSNED) Major in Early Childhood Education',
    'Bachelor of Technology and Livelihood Education (BTLED) Major in Home Economics',
    'Bachelor of Secondary Education (BSEd) Major in Mathematics',
    'Bachelor of Secondary Education (BSEd) Major in English',
    'Bachelor of Secondary Education (BSEd) Major in Science',
    'Bachelor of Secondary Education (BSEd) Major in Social Studies',
    'Bachelor of Science in Industrial Education',
    'Physical Education',
];

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

    // A list, not free text: the queue and the profile read this back, and a
    // course somebody typed their own way matches nothing.
    if (!in_array($course, ADM_COURSES, true)) $errors[] = "Please select your course.";

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
                    /*
                     * One button, not an Approve and a Reject. Both went to the
                     * same address and neither decided anything — the decision is
                     * made on the page they open, because a link that approved an
                     * administrator would be triggered by the scanners that fetch
                     * the links in a message. Two buttons that do the same thing
                     * only promise a choice this email cannot carry out.
                     */
                    . '<p style="margin:0 0 10px;">'
                    . '<a href="' . $e($review) . '" style="display:inline-block;padding:13px 26px;'
                    . 'background:#0868AD;border-radius:9px;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;">'
                    . 'Review this application</a>'
                    . '</p>'
                    . '<p style="margin:0;font-size:12.5px;color:#717680;">'
                    . 'Approve or reject it on the page this opens. The links stop working once it is '
                    . 'decided, and expire after ' . AdminReviewLink::TTL_DAYS . ' days.</p>'
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
            :root { color-scheme: light; }

            body {
                background:
                    radial-gradient(1100px 520px at 8% -8%, #E8F0FE 0%, rgba(232, 240, 254, 0) 62%),
                    radial-gradient(900px 480px at 104% 104%, #E6EEFD 0%, rgba(230, 238, 253, 0) 60%),
                    var(--bg);
                min-height: 100vh;
                display: grid;
                place-items: center;
                margin: 0;
                padding: 28px 18px;
            }

            .aw-card {
                background: var(--surface);
                border-radius: 22px;
                box-shadow: 0 26px 60px -34px rgba(16, 32, 68, .42);
                padding: 40px 36px 28px;
                width: min(620px, 100%);
                text-align: center;
            }

            /* ── The illustration ──────────────────────────────────────────
               An ID card with a tick, and a ring turning beside it. Drawn
               rather than fetched: one more image to ship, cache and get wrong
               on a slow connection, for a picture this simple. */
            .aw-art {
                position: relative;
                width: 190px;
                height: 132px;
                margin: 0 auto 22px;
            }

            .aw-blob {
                position: absolute;
                inset: 8px 0 0;
                background: #EAF1FE;
                border-radius: 46% 54% 52% 48% / 58% 44% 56% 42%;
            }

            .aw-doc {
                position: absolute;
                left: 40px;
                top: 26px;
                width: 116px;
                height: 78px;
                background: #fff;
                border: 2px solid #C9DBFB;
                border-radius: 12px;
                box-shadow: 0 10px 22px -14px rgba(16, 32, 68, .5);
            }

            .aw-doc::before {
                content: "";
                position: absolute;
                left: 12px;
                top: 16px;
                width: 26px;
                height: 26px;
                border-radius: 50%;
                background: #BFD5FA;
            }

            .aw-line {
                position: absolute;
                left: 48px;
                height: 6px;
                border-radius: 3px;
                background: #D8E5FC;
            }

            .aw-line.l1 { top: 18px; width: 52px; }
            .aw-line.l2 { top: 30px; width: 40px; }
            .aw-line.l3 { top: 50px; width: 64px; left: 12px; }

            .aw-tick {
                position: absolute;
                right: 22px;
                bottom: 16px;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: var(--mint, #0868AD);
                color: #fff;
                display: grid;
                place-items: center;
                box-shadow: 0 10px 20px -10px rgba(8, 104, 173, .8);
            }

            .aw-tick svg { width: 21px; height: 21px; }

            /* The live part: it turns for as long as the page is open. */
            .aw-ring {
                position: absolute;
                left: 12px;
                bottom: 22px;
                width: 38px;
                height: 38px;
                border-radius: 50%;
                border: 4px solid #D3E2FB;
                border-top-color: var(--mint, #0868AD);
                animation: aw-spin 900ms linear infinite;
            }

            @keyframes aw-spin { to { transform: rotate(360deg); } }

            @media (prefers-reduced-motion: reduce) {
                .aw-ring { animation-duration: 3s; }
            }

            .aw-card h1 {
                margin: 0 0 12px;
                font-size: 30px;
                font-weight: 700;
                letter-spacing: -.02em;
                color: var(--navy, #0B2C63);
            }

            .aw-lead {
                margin: 0 auto 22px;
                max-width: 46ch;
                font-size: 15px;
                line-height: 1.65;
                color: var(--gray-600);
            }

            .aw-note {
                display: flex;
                gap: 12px;
                text-align: left;
                background: #EEF4FE;
                border-radius: 14px;
                padding: 15px 17px;
                margin-bottom: 26px;
                font-size: 13.5px;
                line-height: 1.6;
                color: var(--navy, #0B2C63);
            }

            .aw-note svg { width: 22px; height: 22px; flex: none; color: var(--mint, #0868AD); }

            /* ── The three steps ───────────────────────────────────────── */
            .aw-steps {
                display: grid;
                grid-template-columns: 1fr 1fr 1fr;
                align-items: start;
                margin-bottom: 8px;
            }

            .aw-step { position: relative; padding: 0 6px; }

            /* The joining line sits behind the dots, drawn from each step to
               the one before it rather than as a bar underneath the lot — that
               way it cannot end up the wrong length at a different width. */
            .aw-step + .aw-step::before {
                content: "";
                position: absolute;
                top: 23px;
                right: 50%;
                width: 100%;
                height: 2px;
                background: #DCE7F9;
            }

            .aw-dot {
                position: relative;
                z-index: 1;
                width: 46px;
                height: 46px;
                margin: 0 auto 10px;
                border-radius: 50%;
                display: grid;
                place-items: center;
                background: #EEF4FE;
                color: #9FB6DC;
            }

            .aw-dot svg { width: 21px; height: 21px; }

            .aw-step.done .aw-dot { background: #DCEAFE; color: var(--mint, #0868AD); }

            .aw-step.now .aw-dot {
                background: #D3E4FE;
                color: var(--mint, #0868AD);
                box-shadow: 0 0 0 5px rgba(8, 104, 173, .12);
            }

            .aw-step b {
                display: block;
                font-size: 13.5px;
                font-weight: 700;
                color: var(--gray-500);
            }

            .aw-step.done b, .aw-step.now b { color: var(--navy, #0B2C63); }
            .aw-step.now b { color: var(--mint, #0868AD); }

            .aw-step span {
                display: block;
                margin-top: 3px;
                font-size: 11.5px;
                color: var(--gray-500);
            }

            .aw-foot {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 14px;
                flex-wrap: wrap;
                margin-top: 26px;
                padding-top: 18px;
                border-top: 1px solid var(--gray-100);
                font-size: 12.5px;
                color: var(--gray-500);
            }

            .aw-foot-when { display: flex; align-items: center; gap: 8px; }
            .aw-foot-when svg { width: 16px; height: 16px; color: var(--gray-400); }

            .aw-out {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 9px 16px;
                border: 1px solid var(--border);
                border-radius: 10px;
                background: var(--surface);
                color: var(--gray-700);
                font-size: 13px;
                font-weight: 600;
                text-decoration: none;
            }

            .aw-out:hover { background: var(--gray-50); }
            .aw-out svg { width: 15px; height: 15px; }

            @media (max-width: 560px) {
                .aw-card { padding: 30px 20px 22px; }
                .aw-card h1 { font-size: 24px; }
                .aw-steps { grid-template-columns: 1fr; gap: 18px; }
                .aw-step + .aw-step::before { display: none; }
            }
        </style>
    </head>

    <body>
        <?php $submitted = strtotime((string)$existing['submitted_at']); ?>
        <div class="aw-card">
            <div class="aw-art" aria-hidden="true">
                <span class="aw-blob"></span>
                <span class="aw-doc">
                    <span class="aw-line l1"></span>
                    <span class="aw-line l2"></span>
                    <span class="aw-line l3"></span>
                </span>
                <span class="aw-ring"></span>
                <span class="aw-tick">
                    <svg fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                    </svg>
                </span>
            </div>

            <h1>Waiting for verification</h1>
            <p class="aw-lead">
                Your ID and Certificate of Registration are with the site owner. They will approve or
                reject the account, and you will be told either way.
            </p>

            <div class="aw-note">
                <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="9" />
                    <path stroke-linecap="round" d="M12 11v5M12 8h.01" />
                </svg>
                <span>
                    You will get a notification the moment it is reviewed, and this page moves on by
                    itself — there is no need to keep reloading it.
                </span>
            </div>

            <?php /* Three steps, and the middle one is where this account is. The
                     last has no date on it because it has not happened. */ ?>
            <div class="aw-steps">
                <div class="aw-step done">
                    <span class="aw-dot">
                        <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M12 16V4m0 0L8 8m4-4 4 4M5 20h14" />
                        </svg>
                    </span>
                    <b>Submitted</b>
                    <span><?= htmlspecialchars(date('M j, Y · g:i A', $submitted)) ?></span>
                </div>

                <div class="aw-step now">
                    <span class="aw-dot">
                        <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
                            <circle cx="10" cy="8" r="3.4" />
                            <path stroke-linecap="round" d="M4 19a6 6 0 0 1 12 0" />
                            <circle cx="18" cy="17" r="3.2" />
                        </svg>
                    </span>
                    <b>Under review</b>
                    <span>By the site owner</span>
                </div>

                <div class="aw-step">
                    <span class="aw-dot">
                        <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12 2.5 2.5 4.5-5" />
                        </svg>
                    </span>
                    <b>Approved or rejected</b>
                    <span>You will be notified</span>
                </div>
            </div>

            <div class="aw-foot">
                <span class="aw-foot-when">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <rect x="3.5" y="5" width="17" height="15" rx="2.5" />
                        <path stroke-linecap="round" d="M8 3v4M16 3v4M3.5 10h17" />
                    </svg>
                    Submitted <?= htmlspecialchars(date('M j, Y · g:i A', $submitted)) ?>
                </span>
                <a class="aw-out" href="<?= url('logout') ?>">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h4a3 3 0 0 1 3 3v1" />
                    </svg>
                    Sign out
                </a>
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
        :root { color-scheme: light; }

        body {
            margin: 0;
            padding: 30px 18px 56px;
            background:
                radial-gradient(1000px 500px at 4% -6%, #E9F0FE 0%, rgba(233, 240, 254, 0) 60%),
                radial-gradient(900px 470px at 102% 102%, #EDE9FE 0%, rgba(237, 233, 254, 0) 58%),
                var(--bg);
        }

        .av-wrap { max-width: 900px; margin: 0 auto; }

        .av-card {
            background: var(--surface);
            border-radius: 20px;
            box-shadow: 0 24px 58px -34px rgba(16, 32, 68, .4);
            padding: 30px;
        }

        /* ── Header ─────────────────────────────────────────────────────── */
        .av-top { display: flex; gap: 18px; align-items: flex-start; }

        .av-shield {
            width: 58px;
            height: 58px;
            border-radius: 16px;
            background: #E3EDFD;
            color: var(--mint);
            display: grid;
            place-items: center;
            flex: none;
        }

        .av-shield svg { width: 28px; height: 28px; }

        .av-card h1 {
            margin: 0 0 8px;
            font-size: 27px;
            font-weight: 700;
            letter-spacing: -.02em;
            color: var(--navy);
        }

        .av-sub { margin: 0; font-size: 14.5px; line-height: 1.6; color: var(--gray-600); max-width: 62ch; }

        /* ── Notes ──────────────────────────────────────────────────────── */
        .av-note {
            display: flex;
            gap: 14px;
            border-radius: 14px;
            padding: 16px 18px;
            margin: 22px 0;
            font-size: 14px;
            line-height: 1.6;
        }

        .av-note svg { width: 24px; height: 24px; flex: none; }
        .av-note b { display: block; margin-bottom: 2px; }

        .av-note.info { background: #EEF4FE; color: var(--gray-600); }
        .av-note.info svg, .av-note.info b { color: var(--mint); }
        .av-note.info b { color: var(--navy); }

        .av-note.bad { background: var(--danger-bg); color: var(--danger); }
        .av-note.bad svg { color: var(--danger); }

        .av-note.no { background: var(--danger-bg); color: var(--danger); }
        .av-note.no svg { color: var(--danger); }

        /* ── Fields ─────────────────────────────────────────────────────── */
        .av-panel {
            border: 1px solid var(--gray-100);
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 18px;
        }

        .av-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 22px; }

        .av-field { display: flex; flex-direction: column; gap: 7px; min-width: 0; }

        .av-field > label {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--navy);
        }

        .av-req { color: #E2574C; }
        .av-opt { font-weight: 400; color: var(--gray-500); }

        /* The icon sits inside the control's box rather than beside it, so a
           long value cannot push it out of line. */
        .av-in { position: relative; display: block; }

        .av-in > svg {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            width: 19px;
            height: 19px;
            color: var(--gray-400);
            pointer-events: none;
        }

        .av-in input, .av-in select {
            width: 100%;
            box-sizing: border-box;
            font: inherit;
            font-size: 14px;
            padding: 14px 15px 14px 48px;
            border: 1px solid var(--gray-100);
            border-radius: 12px;
            background: #FAFCFF;
            color: var(--ink);
            appearance: none;
        }

        .av-in select { padding-right: 42px; cursor: pointer; }
        .av-in input::placeholder { color: #9FB0CC; }

        .av-in input:focus, .av-in select:focus {
            outline: 0;
            border-color: var(--mint);
            background: var(--surface);
            box-shadow: 0 0 0 3px rgba(8, 127, 193, .14);
        }

        .av-caret {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            width: 17px;
            height: 17px;
            color: var(--gray-400);
            pointer-events: none;
        }

        .av-hint {
            display: flex;
            align-items: center;
            gap: 8px;
            grid-column: 1 / -1;
            font-size: 12.5px;
            color: var(--gray-500);
        }

        .av-hint svg { width: 16px; height: 16px; color: var(--gray-400); flex: none; }

        /* ── Uploads ────────────────────────────────────────────────────── */
        .av-ups { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 20px; }

        .av-up {
            border-radius: 16px;
            padding: 20px;
            border: 1px solid transparent;
        }

        .av-up.id  { background: #F3F8FF; border-color: #DCEAFE; }
        .av-up.cor { background: #F7F5FF; border-color: #E4DEFB; }

        .av-up-h { display: flex; gap: 13px; align-items: flex-start; margin-bottom: 14px; }

        .av-up-ico {
            width: 44px;
            height: 44px;
            border-radius: 13px;
            display: grid;
            place-items: center;
            flex: none;
        }

        .av-up.id  .av-up-ico { background: #DCEAFE; color: var(--mint); }
        .av-up.cor .av-up-ico { background: #E6DEFC; color: #6D4AFF; }
        .av-up-ico svg { width: 21px; height: 21px; }

        .av-up-t { font-size: 15.5px; font-weight: 700; color: var(--navy); }
        .av-up-d { font-size: 12.5px; color: var(--gray-600); margin-top: 3px; }
        .av-up-s { font-size: 11.5px; color: var(--gray-500); margin-top: 5px; }

        .av-drop {
            border: 1.5px dashed #BFD3F2;
            border-radius: 13px;
            padding: 24px 16px;
            text-align: center;
            background: rgba(255, 255, 255, .6);
            transition: background .15s, border-color .15s;
        }

        .av-drop.over { border-color: var(--mint); background: #EAF4FF; }
        .av-drop.has  { border-style: solid; border-color: var(--success); background: var(--success-bg); }

        .av-drop-ico { color: var(--mint); }
        .av-up.cor .av-drop-ico { color: #6D4AFF; }
        .av-drop-ico svg { width: 30px; height: 30px; }

        .av-drop p { margin: 8px 0 12px; font-size: 13px; color: var(--gray-600); }
        .av-drop-or { font-size: 11.5px; color: var(--gray-400); margin: 0 0 12px; }

        .av-pick {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 11px 20px;
            border: 0;
            border-radius: 10px;
            font: inherit;
            font-size: 13.5px;
            font-weight: 600;
            color: #fff;
            cursor: pointer;
        }

        .av-up.id  .av-pick { background: var(--mint); }
        .av-up.cor .av-pick { background: #6D4AFF; }
        .av-pick svg { width: 16px; height: 16px; }

        .av-file { display: none; }

        .av-chosen {
            margin-top: 10px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--success);
            word-break: break-all;
        }

        /* ── Footer ─────────────────────────────────────────────────────── */
        .av-foot {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            align-items: center;
            flex-wrap: wrap;
            padding-top: 20px;
            border-top: 1px solid var(--gray-100);
        }

        .av-btn {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 13px 24px;
            border: 1px solid transparent;
            border-radius: 11px;
            font: inherit;
            font-size: 14.5px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .av-btn svg { width: 17px; height: 17px; }

        .av-btn.out {
            background: var(--surface);
            border-color: var(--border);
            color: var(--gray-700);
        }

        .av-btn.out:hover { background: var(--gray-50); }
        .av-btn.go { background: var(--mint); color: #fff; }
        .av-btn.go:hover { background: var(--mint-deep); }

        @media (max-width: 760px) {
            .av-card { padding: 22px 18px; }
            .av-card h1 { font-size: 22px; }
            .av-grid, .av-ups { grid-template-columns: 1fr; }
            .av-foot { justify-content: stretch; }
            .av-btn { flex: 1; justify-content: center; }
        }
    </style>
</head>

<body>
    <div class="av-wrap">
        <div class="av-card">
            <div class="av-top">
                <span class="av-shield">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 3l7 3v5.5c0 4.2-2.9 7.6-7 8.5-4.1-.9-7-4.3-7-8.5V6l7-3Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" />
                    </svg>
                </span>
                <div>
                    <h1>Administrator verification</h1>
                    <p class="av-sub">
                        An administrator can see and change every member's information, so the account is
                        reviewed by the site owner before it is opened. Submit your ID and your Certificate
                        of Registration, and you will be told either way.
                    </p>
                </div>
            </div>

            <?php if ($errors): ?>
                <div class="av-note bad" role="alert">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9" />
                        <path stroke-linecap="round" d="M12 7.5v5M12 16h.01" />
                    </svg>
                    <span>
                        <b>That could not be submitted.</b>
                        <?php foreach ($errors as $e): ?>
                            <span style="display:block;"><?= htmlspecialchars($e) ?></span>
                        <?php endforeach; ?>
                    </span>
                </div>
            <?php elseif ($status === 'rejected'): ?>
                <div class="av-note no" role="status">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9" />
                        <path stroke-linecap="round" d="M9 9l6 6M15 9l-6 6" />
                    </svg>
                    <span>
                        <b>Not approved.</b>
                        <?= $notes !== '' ? htmlspecialchars($notes) : 'No reason was given.' ?>
                        Correct the details below and submit again.
                    </span>
                </div>
            <?php else: ?>
                <div class="av-note info">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9" />
                        <path stroke-linecap="round" d="M12 11v5M12 8h.01" />
                    </svg>
                    <span>
                        <b>Make sure everything matches your documents.</b>
                        A detail that does not match what you have uploaded is the usual reason an
                        application comes back.
                    </span>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" novalidate id="avForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                <div class="av-panel">
                    <div class="av-grid">
                        <div class="av-field">
                            <label for="av-first">First name <span class="av-req">*</span></label>
                            <span class="av-in">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <circle cx="12" cy="8" r="3.4" /><path stroke-linecap="round" d="M5.5 20a6.5 6.5 0 0 1 13 0" />
                                </svg>
                                <input id="av-first" name="firstname" maxlength="50" placeholder="Enter first name"
                                       value="<?= htmlspecialchars($_POST['firstname'] ?? $existing['firstname'] ?? $acct['firstname'] ?? '') ?>">
                            </span>
                        </div>

                        <div class="av-field">
                            <label for="av-middle">Middle name <span class="av-opt">(optional)</span></label>
                            <span class="av-in">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <circle cx="12" cy="8" r="3.4" /><path stroke-linecap="round" d="M5.5 20a6.5 6.5 0 0 1 13 0" />
                                </svg>
                                <input id="av-middle" name="middlename" maxlength="100" placeholder="Enter middle name"
                                       value="<?= htmlspecialchars($_POST['middlename'] ?? $existing['middlename'] ?? '') ?>">
                            </span>
                        </div>

                        <div class="av-field">
                            <label for="av-last">Surname <span class="av-req">*</span></label>
                            <span class="av-in">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <circle cx="12" cy="8" r="3.4" /><path stroke-linecap="round" d="M5.5 20a6.5 6.5 0 0 1 13 0" />
                                </svg>
                                <input id="av-last" name="lastname" maxlength="50" placeholder="Enter surname"
                                       value="<?= htmlspecialchars($_POST['lastname'] ?? $existing['lastname'] ?? $acct['lastname'] ?? '') ?>">
                            </span>
                        </div>

                        <div class="av-field">
                            <label for="av-sid">Student ID <span class="av-req">*</span></label>
                            <span class="av-in">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <rect x="3" y="5" width="18" height="14" rx="2.5" />
                                    <circle cx="9" cy="11" r="2" />
                                    <path stroke-linecap="round" d="M14 10h4M14 13.5h4M5.8 15.6a3.6 3.6 0 0 1 6.4 0" />
                                </svg>
                                <input id="av-sid" name="student_id" maxlength="20" placeholder="Enter student ID"
                                       value="<?= htmlspecialchars($_POST['student_id'] ?? $existing['student_id'] ?? '') ?>">
                            </span>
                        </div>

                        <div class="av-field">
                            <label for="av-course">Course <span class="av-req">*</span></label>
                            <span class="av-in">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m12 5 9 4-9 4-9-4 9-4Z" />
                                    <path stroke-linecap="round" d="M6 11v4.2c0 1.2 2.7 2.8 6 2.8s6-1.6 6-2.8V11" />
                                </svg>
                                <select id="av-course" name="course">
                                    <option value="">Select course</option>
                                    <?php $cc = $_POST['course'] ?? $existing['course'] ?? '';
                                    foreach (ADM_COURSES as $c): ?>
                                        <option value="<?= htmlspecialchars($c) ?>" <?= $cc === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <svg class="av-caret" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9.5 6 6 6-6" />
                                </svg>
                            </span>
                        </div>

                        <div class="av-field">
                            <label for="av-year">Year level <span class="av-req">*</span></label>
                            <span class="av-in">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <rect x="3.5" y="5" width="17" height="15" rx="2.5" />
                                    <path stroke-linecap="round" d="M8 3v4M16 3v4M3.5 10h17" />
                                </svg>
                                <select id="av-year" name="year_level">
                                    <option value="">Select year level</option>
                                    <?php $yl = $_POST['year_level'] ?? $existing['year_level'] ?? '';
                                    foreach (['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'] as $lvl): ?>
                                        <option value="<?= $lvl ?>" <?= $yl === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <svg class="av-caret" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9.5 6 6 6-6" />
                                </svg>
                            </span>
                        </div>

                        <div class="av-field">
                            <label for="av-pos">Post <span class="av-req">*</span></label>
                            <span class="av-in">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11Z" />
                                    <circle cx="12" cy="10" r="2.6" />
                                </svg>
                                <select id="av-pos" name="position">
                                    <option value="">Select post</option>
                                    <?php foreach (ADM_POSITIONS as $p): ?>
                                        <option value="<?= htmlspecialchars($p) ?>" <?= $cur_position === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <svg class="av-caret" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9.5 6 6 6-6" />
                                </svg>
                            </span>
                        </div>

                        <div class="av-field">
                            <label for="av-club">Club <span class="av-req">*</span></label>
                            <span class="av-in">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <circle cx="9" cy="8" r="3.2" /><path stroke-linecap="round" d="M3 19a6 6 0 0 1 12 0" />
                                    <path stroke-linecap="round" d="M16 5.5a3.2 3.2 0 0 1 0 5M18 19a5.5 5.5 0 0 0-2-4.3" />
                                </svg>
                                <select id="av-club" name="club">
                                    <option value="">Select club</option>
                                    <?php foreach (ADM_CLUBS as $cl): ?>
                                        <option value="<?= htmlspecialchars($cl) ?>" <?= $cur_club === $cl ? 'selected' : '' ?>><?= htmlspecialchars($cl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <svg class="av-caret" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9.5 6 6 6-6" />
                                </svg>
                            </span>
                        </div>

                        <p class="av-hint">
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <circle cx="9" cy="8" r="3.2" /><path stroke-linecap="round" d="M3 19a6 6 0 0 1 12 0" />
                            </svg>
                            Only club presidents and vice presidents administer this site.
                        </p>
                    </div>
                </div>

                <?php
                /*
                 * The two uploads. Drag and drop is added by the script below and
                 * is an extra way in, never the only one: the file input and its
                 * button work with the script disabled, which is what the form
                 * has always relied on.
                 */
                $uploads = [
                    ['id',  'id_image',         'Valid ID', 'Upload a clear photo of your valid school ID.',  $existing['id_image'] ?? ''],
                    ['cor', 'credential_image', 'Certificate of Registration', 'Upload your latest Certificate of Registration (COR).', $existing['credential_image'] ?? ''],
                ];
                ?>
                <div class="av-ups">
                    <?php foreach ($uploads as [$kind, $field, $title, $desc, $have]): ?>
                        <div class="av-up <?= $kind ?>">
                            <div class="av-up-h">
                                <span class="av-up-ico">
                                    <?php if ($kind === 'id'): ?>
                                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                            <rect x="3" y="5" width="18" height="14" rx="2.5" />
                                            <circle cx="9" cy="11" r="2" />
                                            <path stroke-linecap="round" d="M14 10h4M14 13.5h4M5.8 15.6a3.6 3.6 0 0 1 6.4 0" />
                                        </svg>
                                    <?php else: ?>
                                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5M7 3h8l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" />
                                            <path stroke-linecap="round" d="M9 13h6M9 16.5h4" />
                                        </svg>
                                    <?php endif; ?>
                                </span>
                                <div>
                                    <div class="av-up-t"><?= htmlspecialchars($title) ?> <span class="av-req">*</span></div>
                                    <div class="av-up-d"><?= htmlspecialchars($desc) ?></div>
                                    <div class="av-up-s">JPG, PNG or PDF — up to 5 MB</div>
                                </div>
                            </div>

                            <div class="av-drop<?= $have !== '' ? ' has' : '' ?>" data-for="<?= $field ?>">
                                <span class="av-drop-ico">
                                    <svg fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M7 17a4 4 0 0 1 .6-7.96A5.5 5.5 0 0 1 18 9.5a3.75 3.75 0 0 1 .3 7.48" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 12v8m0-8-2.6 2.6M12 12l2.6 2.6" />
                                    </svg>
                                </span>
                                <p>Drag and drop your file here</p>
                                <p class="av-drop-or">or</p>
                                <input class="av-file" type="file" id="av-<?= $field ?>" name="<?= $field ?>" accept=".jpg,.jpeg,.png,.pdf">
                                <button class="av-pick" type="button" data-pick="<?= $field ?>">
                                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5M7 3h8l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" />
                                    </svg>
                                    Choose file
                                </button>
                                <div class="av-chosen" data-name="<?= $field ?>">
                                    <?= $have !== '' ? 'A file is already on the application — choose another only to replace it.' : '' ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="av-foot">
                    <a class="av-btn out" href="<?= url('logout') ?>">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6 6 18" />
                        </svg>
                        Sign out
                    </a>
                    <button class="av-btn go" type="submit">
                        <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 3 10.5 13.5M21 3l-6.8 18-3.7-7.5L3 9.8 21 3Z" />
                        </svg>
                        <?= $status === null ? 'Submit for review' : 'Send updated details' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        /*
         * Drag and drop, and the file name once one is chosen. The input and its
         * button work without any of this — everything here is an addition to a
         * form that already submits.
         */
        (function () {
            document.querySelectorAll('.av-pick').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById('av-' + btn.dataset.pick).click();
                });
            });

            document.querySelectorAll('.av-drop').forEach(drop => {
                const input = document.getElementById('av-' + drop.dataset.for);
                const label = drop.querySelector('[data-name]');

                function chosen() {
                    if (input.files && input.files.length) {
                        drop.classList.add('has');
                        label.textContent = input.files[0].name;
                    }
                }

                input.addEventListener('change', chosen);

                ['dragenter', 'dragover'].forEach(e => drop.addEventListener(e, ev => {
                    ev.preventDefault();
                    drop.classList.add('over');
                }));

                ['dragleave', 'drop'].forEach(e => drop.addEventListener(e, ev => {
                    ev.preventDefault();
                    drop.classList.remove('over');
                }));

                drop.addEventListener('drop', ev => {
                    if (!ev.dataTransfer || !ev.dataTransfer.files.length) return;
                    /* Put the dropped file into the input itself, so the form
                       submits it the ordinary way and the server sees no
                       difference between dropping and choosing. */
                    input.files = ev.dataTransfer.files;
                    chosen();
                });
            });
        })();
    </script>
</body>

</html>
