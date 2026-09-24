<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . "/../db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: " . url('welcomepage'));
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$errors  = [];

$existing    = VerificationRepository::forUser($con, $user_id);
$status      = $existing['status']      ?? null;
$admin_notes = $existing['admin_notes'] ?? null;

// ── Pending screen ────────────────────────────────────────────
if ($status === 'pending'): ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <?php // Without this a phone lays the page out at ~980px and scales the
              // whole thing down, so the waiting screen rendered as a postage
              // stamp in the middle of an empty page. The form below has always
              // had it; this screen was missed. ?>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Waiting for Verification – NEUST</title>
        <?php include __DIR__ . '/../mentorpage/includes/style.php'; ?>
        <style>
            body {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                background: var(--bg);
            }

            .pending-wrap {
                width: 100%;
                max-width: 460px;
                padding: 20px;
                animation: fadeIn .4s ease forwards;
            }

            .brand-row {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                margin-bottom: 32px;
            }

            .brand-mark {
                width: 36px;
                height: 36px;
                background: linear-gradient(135deg, var(--gold) 0%, #FDBC7D 100%);
                border-radius: 11px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: 'Inter', sans-serif;
                font-weight: 700;
                font-size: 15px;
                color: var(--navy);
                box-shadow: 0 4px 12px rgba(245, 180, 0, .25);
            }

            .brand-name {
                font-family: 'Inter', sans-serif;
                font-weight: 700;
                font-size: 15px;
                color: var(--gray-700);
            }

            /* Spinner */
            .spinner-ring {
                width: 88px;
                height: 88px;
                margin: 0 auto 28px;
                position: relative;
            }

            .spinner-ring::before,
            .spinner-ring::after {
                content: '';
                position: absolute;
                inset: 0;
                border-radius: 50%;
                background: rgba(31, 78, 69, .10);
                animation: pulseRing 2s ease-out infinite;
            }

            .spinner-ring::after {
                animation-delay: .7s;
                background: rgba(31, 78, 69, .06);
            }

            @keyframes pulseRing {
                0% {
                    transform: scale(1);
                    opacity: .8;
                }

                100% {
                    transform: scale(1.55);
                    opacity: 0;
                }
            }

            .spinner-core {
                position: absolute;
                inset: 14px;
                border-radius: 50%;
                background: var(--surface);
                border: 1px solid var(--border);
                box-shadow: var(--shadow-md);
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .spinner-core svg {
                width: 26px;
                height: 26px;
                color: var(--accent);
                animation: spin 1.1s linear infinite;
            }

            @keyframes spin {
                to {
                    transform: rotate(360deg);
                }
            }

            .pending-title {
                font-family: 'Inter', sans-serif;
                font-size: 22px;
                font-weight: 700;
                color: var(--gray-900);
                text-align: center;
                margin-bottom: 6px;
            }

            .pending-sub {
                text-align: center;
                color: var(--gray-400);
                font-size: 13px;
                line-height: 1.6;
                margin-bottom: 28px;
            }

            /* Steps card */
            .steps-card {
                background: var(--surface);
                border: 1px solid var(--border);
                border-radius: var(--radius-lg);
                box-shadow: var(--shadow-sm);
                padding: 6px 0;
                margin-bottom: 16px;
            }

            .step-row {
                display: flex;
                align-items: center;
                gap: 14px;
                padding: 14px 20px;
            }

            .step-row+.step-row {
                border-top: 1px solid var(--gray-100);
            }

            .step-dot {
                width: 32px;
                height: 32px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 12px;
                font-weight: 700;
                flex-shrink: 0;
            }

            .step-dot.done {
                background: var(--success-bg);
                color: var(--success);
            }

            .step-dot.spin {
                background: var(--info-bg);
                color: var(--info);
            }

            .step-dot.spin svg {
                animation: spin 1.1s linear infinite;
                width: 14px;
                height: 14px;
            }

            .step-dot.idle {
                background: var(--gray-100);
                color: var(--gray-400);
            }

            .step-dot.ready {
                background: var(--success-bg);
                color: var(--success);
            }

            .step-title {
                font-size: 13px;
                font-weight: 600;
                color: var(--gray-800);
                margin-bottom: 2px;
            }

            .step-sub {
                font-size: 11.5px;
                color: var(--gray-400);
            }

            .step-sub.green {
                color: var(--success);
            }

            .pulse-note {
                text-align: center;
                font-size: 11.5px;
                color: var(--gray-400);
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
            }

            .pulse-dot {
                width: 6px;
                height: 6px;
                border-radius: 50%;
                background: var(--accent);
                animation: blink 1.4s ease-in-out infinite;
            }

            /* The way off this screen. Quiet on purpose: the page is about
               waiting, and leaving is the secondary thing to do here. */
            .pending-exit {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                margin: 22px auto 0;
                padding: 11px 18px;
                width: fit-content;
                font-size: 13.5px;
                font-weight: 600;
                color: var(--gray-500, #6b7280);
                text-decoration: none;
                background: #fff;
                border: 1px solid var(--gray-200, #e5e7eb);
                border-radius: 10px;
                transition: color .15s, border-color .15s, box-shadow .15s;
            }

            .pending-exit:hover,
            .pending-exit:focus-visible {
                color: var(--ink, #111827);
                border-color: var(--gray-400, #9ca3af);
                box-shadow: 0 2px 10px rgba(17, 24, 39, .07);
            }

            .pending-exit svg {
                width: 16px;
                height: 16px;
            }

            @keyframes blink {

                0%,
                100% {
                    opacity: 1
                }

                50% {
                    opacity: .2
                }
            }
        </style>
    </head>

    <body>
        <div class="pending-wrap">

            <div class="brand-row">
                <div class="brand-mark">N</div>
                <span class="brand-name">NEUST · PeerConnect</span>
            </div>

            <div class="spinner-ring">
                <div class="spinner-core">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                </div>
            </div>

            <h1 class="pending-title">Waiting for Verification</h1>
            <p class="pending-sub">Your documents have been submitted.<br>An admin will review your application shortly.</p>

            <div class="steps-card">
                <div class="step-row">
                    <div class="step-dot done">✓</div>
                    <div>
                        <div class="step-title">Documents submitted</div>
                        <div class="step-sub">Your ID and COR have been uploaded</div>
                    </div>
                </div>
                <div class="step-row">
                    <div class="step-dot spin">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                        </svg>
                    </div>
                    <div>
                        <div class="step-title">Admin review in progress</div>
                        <div class="step-sub">Usually completed within 24 hours</div>
                    </div>
                </div>
                <div class="step-row">
                    <div class="step-dot idle" id="step3-icon">3</div>
                    <div>
                        <div class="step-title" id="step3-title" style="color:var(--gray-400)">Access granted</div>
                        <div class="step-sub" id="step3-sub">You'll be redirected once approved</div>
                    </div>
                </div>
            </div>

            <div class="pulse-note">
                <div class="pulse-dot"></div>
                <span id="status-note">Checking status every 15 seconds</span>
            </div>

            <!--
              A way off this screen. Waiting for an admin can take a day, and
              until now the only exits were the browser's Back button and
              closing the tab.

              It signs out on the way, which is what makes it work: while the
              session is live, pc_verification_gate() sends this account
              straight back here from the login page, so a plain link to
              /login would bounce and look broken.
            -->
            <a class="pending-exit" href="<?= htmlspecialchars(url('logout') . '?to=login') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 17.5 19.5 13 15 8.5M19 13H9" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5H6.5A1.5 1.5 0 0 0 5 6v14a1.5 1.5 0 0 0 1.5 1.5H12" />
                </svg>
                Back to log in
            </a>

        </div>
        <script>
            let polling = null;

            async function checkStatus() {
                try {
                    const res = await fetch('<?= url('mentee-check-status') ?>');
                    if (!res.ok) return;
                    const data = await res.json();

                    if (data.status === 'approved') {
                        clearInterval(polling);
                        document.getElementById('step3-icon').className = 'step-dot ready';
                        document.getElementById('step3-icon').textContent = '✓';
                        document.getElementById('step3-title').style.color = '';
                        document.getElementById('step3-title').textContent = 'Access granted!';
                        document.getElementById('step3-sub').className = 'step-sub green';
                        document.getElementById('step3-sub').textContent = 'Redirecting you now…';
                        document.getElementById('status-note').textContent = 'Approved! Redirecting…';
                        setTimeout(() => { window.location.href = data.redirect; }, 2000);

                    } else if (data.status === 'rejected') {
                        clearInterval(polling);
                        document.getElementById('status-note').textContent = 'Verification rejected. Reloading…';
                        setTimeout(() => { window.location.href = data.reject_redirect; }, 2000);
                    }
                } catch (e) { /* retry next interval */ }
            }

            checkStatus();
            polling = setInterval(checkStatus, 10000);
        </script>
    </body>

    </html>
<?php exit;
endif;

// ── Approved ──────────────────────────────────────────────────
if ($status === 'approved') {
    header("Location: " . url('mentee-dashboard'));
    exit;
}

// ── Handle POST ───────────────────────────────────────────────
// A cross-site page must not be able to submit an identity claim on a
// member's behalf. Surfaced as an ordinary form error rather than a bare 403,
// so a member whose session expired mid-form is told what to do about it.
$csrf_ok = $_SERVER['REQUEST_METHOD'] !== 'POST' || verify_csrf();
if (!$csrf_ok) {
    $errors[] = "Your session expired before this form was sent. Please re-attach your files and submit again.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrf_ok) {
    // A field sent as a list counts as missing.
    $field = fn(string $name): string => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';

    $firstname  = $field('firstname');
    $middlename = $field('middlename');
    $lastname   = $field('lastname');
    $student_id = $field('student_id');
    $course     = $field('course');
    $year_level = $field('year_level');
    $club       = $field('club');

    /*
     * Asked for in three parts now, and stored that way — full_name is still
     * written, because the admin queue and the notification text read it.
     *
     * A middle name is optional: it is optional at sign-up, and plenty of
     * people do not have one. Requiring it here would have made the form
     * unfillable for them.
     */
    $name_rule = '/^[A-Za-z ,.\'-]{2,50}$/';
    if (!$firstname)                                      $errors[] = "First name is required.";
    elseif (!preg_match($name_rule, $firstname))          $errors[] = "First name: letters only, 2–50 characters.";

    if ($middlename !== '' && !preg_match('/^[A-Za-z ,.\'-]{1,100}$/', $middlename)) {
        $errors[] = "Middle name: letters only, up to 100 characters.";
    }

    if (!$lastname)                                       $errors[] = "Surname is required.";
    elseif (!preg_match($name_rule, $lastname))           $errors[] = "Surname: letters only, 2–50 characters.";

    // Collapses the gap a missing middle name leaves behind.
    $full_name = trim(preg_replace('/\s+/', ' ', $firstname . ' ' . $middlename . ' ' . $lastname));

    if (!$student_id)                                     $errors[] = "Student ID is required.";
    elseif (!preg_match('/^[A-Z0-9\-]{3,20}$/i', $student_id)) $errors[] = "Student ID: letters, numbers and hyphens only, 3–20 characters.";

    if (!$course)                                         $errors[] = "Course is required.";
    elseif (strlen($course) > 100)                        $errors[] = "Course must be 100 characters or fewer.";

    $allowed_levels = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'];
    if (!$year_level)                                     $errors[] = "Year level is required.";
    elseif (!in_array($year_level, $allowed_levels, true)) $errors[] = "Please select a valid year level.";

    // The column holds 100 characters; a longer name used to be cut short
    // without a word.
    if (!$club)                                           $errors[] = "Club/Organization is required.";
    elseif (mb_strlen($club) > 100)                       $errors[] = "Club name must be 100 characters or fewer.";

    // Both documents are checked before either is kept, and neither is kept
    // unless the whole form is valid: a file used to be saved the moment it
    // passed its own check, and left behind when anything else failed.
    $id_file  = VerificationFiles::inspect($_FILES['id_image'] ?? null, 'Valid ID');
    $cor_file = VerificationFiles::inspect($_FILES['credential_image'] ?? null, 'COR');

    if (!empty($id_file['error']))                        $errors[] = $id_file['error'];
    elseif (!$id_file['present'] && !$existing)           $errors[] = "Valid ID is required.";

    if (!empty($cor_file['error']))                       $errors[] = $cor_file['error'];
    elseif (!$cor_file['present'] && !$existing)          $errors[] = "Certificate of Registration is required.";

    $old_id           = $existing['id_image']         ?? null;
    $old_cor          = $existing['credential_image'] ?? null;
    $id_image         = $old_id;
    $credential_image = $old_cor;
    $stored           = [];

    if (empty($errors) && !empty($id_file['ext'])) {
        $id_image = VerificationFiles::store($_FILES['id_image'], 'id', $id_file['ext']);
        if ($id_image === null) $errors[] = "Valid ID could not be saved. Please try again.";
        else                    $stored[] = $id_image;
    }
    if (empty($errors) && !empty($cor_file['ext'])) {
        $credential_image = VerificationFiles::store($_FILES['credential_image'], 'cor', $cor_file['ext']);
        if ($credential_image === null) $errors[] = "COR could not be saved. Please try again.";
        else                            $stored[] = $credential_image;
    }
    if (!empty($errors)) {
        // One document saved and the other did not: keep neither.
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
                'club'             => $club,
                'id_image'         => $id_image,
                'credential_image' => $credential_image,
            ], (bool)$existing);
        } catch (Throwable $e) {
            foreach ($stored as $f) VerificationFiles::remove($f);
            throw $e;
        }

        // The application points at the new documents now; the ones they
        // replaced are nobody's any more.
        if ($id_image !== $old_id)          VerificationFiles::remove($old_id);
        if ($credential_image !== $old_cor) VerificationFiles::remove($old_cor);
        // NOTE: users.verified is intentionally NOT set here — it is granted only
        // by admin approval (see admin/action_verify.php), not on submission.
        pc_flash('success', 'An admin will review it and you will be notified either way.', 'Verification submitted');
        header("Location: " . url('mentee-verification'));
        exit;
    }

    $existing = VerificationRepository::forUser($con, $user_id);
}

/*
 * What goes in the three name boxes, most specific first:
 *
 *   1. whatever was just typed, so a validation error does not wipe the form
 *   2. the application already on file, if it was submitted after the parts
 *      existed as columns
 *   3. the account itself — the names given at sign-up
 *
 * Prefilled but editable on purpose: the name on a student ID is not always
 * the name somebody signed up with.
 */
$account_name = UserRepository::nameParts($con, $user_id);
$name_value = function (string $part) use ($existing, $account_name): string {
    if (isset($_POST[$part]) && is_string($_POST[$part])) return trim($_POST[$part]);
    if (!empty($existing[$part]))                         return (string) $existing[$part];
    return $account_name[$part] ?? '';
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification – NEUST PeerConnect</title>
    <?php include __DIR__ . '/../mentorpage/includes/style.php'; ?>
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 40px 16px 60px;
        }

        .ver-wrap {
            width: 100%;
            max-width: 880px;
            animation: fadeIn .35s ease forwards;
            position: relative;
            z-index: 1;
        }

        /* Two soft washes behind the card, so the page is not a white slab.
           Decoration only: pointer-events off, and hidden from assistive tech
           because it carries no meaning. */
        .ver-bg {
            position: fixed;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
        }

        .ver-bg span {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: .5;
        }

        .ver-bg span:nth-child(1) {
            width: 420px;
            height: 420px;
            top: -140px;
            right: -110px;
            background: var(--info-bg);
        }

        .ver-bg span:nth-child(2) {
            width: 360px;
            height: 360px;
            bottom: -150px;
            left: -120px;
            background: var(--info-bg);
        }

        /* Header: back, title, and the assurance pill on the right */
        .ver-head {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 22px;
        }

        .ver-back {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            border-radius: 50%;
            background: var(--surface);
            border: 1px solid var(--border);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-600);
            box-shadow: var(--shadow-sm);
            transition: color .2s, border-color .2s, transform .2s;
        }

        .ver-back:hover {
            color: var(--navy);
            border-color: var(--navy);
            transform: translateX(-2px);
        }

        .ver-back svg {
            width: 17px;
            height: 17px;
        }

        .ver-head-text {
            flex: 1 1 auto;
            min-width: 0;
        }

        .ver-assure {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex: 0 0 auto;
            background: var(--mint-faint, #ECFDF5);
            border: 1px solid #A7F3D0;
            border-radius: 999px;
            padding: 6px 13px;
            font-size: 11.5px;
            font-weight: 600;
            color: #047857;
            margin-top: 4px;
        }

        .ver-assure svg {
            width: 13px;
            height: 13px;
        }

        @media (max-width: 640px) {
            .ver-assure span {
                display: none;
            }
        }

        /* Top brand bar */
        .ver-brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 8px;
        }

        .ver-brand-mark {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--gold) 0%, #FDBC7D 100%);
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
            font-weight: 700;
            font-size: 15px;
            color: var(--navy);
            box-shadow: 0 4px 12px rgba(245, 180, 0, .25);
        }

        .ver-brand-name {
            font-family: 'Inter', sans-serif;
            font-weight: 700;
            font-size: 15px;
            color: var(--gray-700);
        }

        .ver-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--info-bg);
            border: 1px solid #BFDBFE;
            border-radius: 999px;
            padding: 4px 12px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--info);
            margin-bottom: 10px;
        }

        .ver-heading {
            font-family: 'Inter', sans-serif;
            font-size: 27px;
            font-weight: 700;
            letter-spacing: -.02em;
            color: var(--navy);
            margin-bottom: 4px;
        }

        .ver-sub {
            font-size: 13.5px;
            color: var(--gray-500);
        }

        /* Main form card */
        .ver-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .ver-section {
            padding: 22px 24px 0;
        }

        /* Section header: an icon that says what the section is about, its
           name, and a line telling people what is wanted. The old version was
           a small uppercase label with a coloured tick beside it, which read
           as a divider rather than a heading. */
        .ver-sec-head {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 20px 24px;
            background: linear-gradient(180deg, var(--gray-50) 0%, var(--surface) 100%);
            border-bottom: 1px solid var(--gray-100);
        }

        .ver-sec-ico {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 13px;
            background: var(--navy);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            box-shadow: 0 6px 14px rgba(15, 42, 90, .18);
        }

        .ver-sec-ico svg {
            width: 20px;
            height: 20px;
        }

        .ver-sec-h {
            display: block;
            font-family: 'Inter', sans-serif;
            font-size: 16px;
            font-weight: 700;
            color: var(--navy);
            line-height: 1.25;
        }

        .ver-sec-s {
            display: block;
            font-size: 12.5px;
            color: var(--gray-500);
            margin-top: 2px;
        }

        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .col-2 {
            grid-column: 1/-1;
        }

        /* The three name boxes share one full-width row. They collapse to one
           per line well before the rest of the form does — "Middle Name" in a
           third of a phone screen is a label with no room for a name. */
        /* .field-group is declared below this and sets display:flex at the same
           specificity, so it used to win and the three boxes stacked one per
           line however wide the screen was. Qualifying the selector settles it. */
        .field-group.name-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 14px;
        }

        @media (max-width: 720px) {
            .field-group.name-row {
                grid-template-columns: 1fr;
            }
        }

        .field-group {
            display: flex;
            flex-direction: column;
        }

        .field-label {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--gray-500);
            margin-bottom: 6px;
        }

        .field-label sup {
            color: var(--danger);
        }

        .field-input {
            padding: 9px 13px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13px;
            color: var(--gray-800);
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
            background: var(--surface);
        }

        .field-input:focus {
            border-color: var(--navy);
            box-shadow: 0 0 0 3px var(--info-bg);
        }

        select.field-input {
            cursor: pointer;
        }

        /* The body of a section, now that its heading runs the full width of
           the card. */
        .ver-sec-body {
            padding: 20px 24px 22px;
        }

        /* An icon inside the box, so a glance tells you what each one wants.
           The input keeps its own padding-left so text never sits under it;
           the icon itself is not focusable and is hidden from screen readers,
           which have the label. */
        .field-wrap {
            position: relative;
            display: flex;
        }

        .field-wrap .field-ico {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            color: var(--gray-400);
            pointer-events: none;
        }

        .field-wrap .field-input {
            width: 100%;
            padding-left: 37px;
            height: 42px;
        }

        .field-wrap:focus-within .field-ico {
            color: var(--navy);
        }

        /* Native select arrows differ by browser and sit oddly next to a
           left-hand icon; one chevron, drawn by us, matches every box. */
        select.field-input {
            appearance: none;
            -webkit-appearance: none;
            padding-right: 34px;
        }

        .field-wrap .field-caret {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            width: 14px;
            height: 14px;
            color: var(--gray-400);
            pointer-events: none;
        }

        /* Upload zones */
        .upload-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            padding: 18px 24px 22px;
        }

        .upload-zone {
            border: 1.5px dashed var(--gray-200);
            border-radius: var(--radius);
            padding: 20px 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            position: relative;
            overflow: hidden;
            min-height: 130px;
            background: var(--gray-50);
        }

        .upload-zone:hover {
            border-color: var(--accent);
            background: var(--info-bg);
        }

        .upload-zone input[type=file] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }

        .upload-icon {
            width: 36px;
            height: 36px;
            background: var(--info-bg);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
        }

        .upload-icon svg {
            width: 18px;
            height: 18px;
        }

        .upload-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-700);
            text-align: center;
        }

        .upload-hint {
            font-size: 11px;
            color: var(--gray-400);
            text-align: center;
        }

        .upload-preview {
            position: absolute;
            inset: 0;
            object-fit: contain;
            padding: 6px;
            border-radius: var(--radius);
        }

        /* Footer row */
        .ver-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 24px 22px;
        }

        .ver-footer-note {
            display: flex;
            align-items: center;
            gap: 9px;
            flex: 1 1 auto;
            background: var(--gray-50);
            border: 1px solid var(--gray-100);
            border-radius: var(--radius);
            padding: 11px 14px;
            font-size: 12.5px;
            color: var(--gray-500);
        }

        .ver-footer-note svg {
            width: 15px;
            height: 15px;
            flex: 0 0 15px;
            color: var(--gray-400);
        }

        .ver-submit {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            flex: 0 0 auto;
            border: 0;
            cursor: pointer;
            padding: 13px 24px;
            border-radius: var(--radius);
            background: var(--navy);
            color: #fff;
            font-family: 'Inter', sans-serif;
            font-size: 13.5px;
            font-weight: 600;
            box-shadow: 0 8px 18px rgba(15, 42, 90, .22);
            transition: transform .18s, box-shadow .18s, opacity .18s;
        }

        .ver-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 11px 22px rgba(15, 42, 90, .28);
        }

        .ver-submit svg {
            width: 16px;
            height: 16px;
        }

        @media (max-width: 600px) {
            .ver-footer {
                flex-direction: column;
                align-items: stretch;
            }

            .ver-submit {
                justify-content: center;
            }
        }

        /* ── Phones ──────────────────────────────────────────────────────
           Two columns of form fields is 150px per box on a 390px screen,
           which is not enough for "e.g. 2021-00123" or a course name. One
           column below 640, and the upload tiles follow at 560 — side by
           side they were two thumbnails too small to check a photograph in. */
        @media (max-width: 640px) {
            body {
                padding: 20px 12px 40px;
            }

            .ver-heading {
                font-size: 21px;
            }

            .ver-sub {
                font-size: 12.5px;
            }

            .ver-head {
                gap: 10px;
                margin-bottom: 16px;
            }

            .field-grid {
                grid-template-columns: 1fr;
            }

            .ver-sec-head {
                padding: 16px;
                gap: 11px;
            }

            .ver-sec-ico {
                width: 36px;
                height: 36px;
                flex-basis: 36px;
                border-radius: 11px;
            }

            .ver-sec-ico svg {
                width: 17px;
                height: 17px;
            }

            .ver-sec-h {
                font-size: 14.5px;
            }

            .ver-sec-body {
                padding: 16px;
            }

            .upload-grid {
                padding: 14px 16px 18px;
            }

            .ver-footer {
                padding: 14px 16px 18px;
            }
        }

        @media (max-width: 560px) {
            .upload-grid {
                grid-template-columns: 1fr;
            }

            .upload-zone {
                min-height: 112px;
            }
        }

        /* Below this the back button, the heading and the assurance pill stop
           fitting on one line. The pill drops under the heading rather than
           squeezing the title into two words per line. */
        @media (max-width: 420px) {
            .ver-head {
                flex-wrap: wrap;
            }

            .ver-assure {
                order: 3;
                width: 100%;
                justify-content: center;
                margin-top: 10px;
            }

            .ver-assure span {
                display: inline;
            }
        }

        /* Alerts — aliased to pc-alert system */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 13px 16px;
            border-radius: var(--radius);
            border-left: 3px solid transparent;
            margin-bottom: 16px;
            font-size: 13.5px;
            line-height: 1.5;
        }
        .alert-icon { font-size: 15px; margin-top: 1px; flex-shrink: 0; }
        .alert-red {
            background: var(--danger-bg);
            border-left-color: var(--danger);
            color: var(--danger);
        }
        .alert-title { font-weight: 600; margin-bottom: 3px; font-size: 13.5px; }

        /* Submit overlay */
        #submit-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(31, 78, 69, .70);
            backdrop-filter: blur(8px);
            z-index: 1000;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
        }

        .overlay-spinner {
            width: 52px;
            height: 52px;
            border: 3px solid rgba(255, 255, 255, .15);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .8s linear infinite;
        }

        .overlay-text {
            color: #fff;
            font-weight: 600;
            font-size: 15px;
        }

        .overlay-sub {
            color: rgba(255, 255, 255, .45);
            font-size: 12px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* The way off this form. The waiting screen further up defines the
           same rule in its own <style>; the two screens are separate
           documents and neither one's CSS reaches the other. */
        .pending-exit {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-400);
            text-decoration: none;
            background: #fff;
            border: 1px solid var(--gray-200, #e5e7eb);
            border-radius: 10px;
            transition: color .15s, border-color .15s, box-shadow .15s;
        }

        .pending-exit:hover,
        .pending-exit:focus-visible {
            color: var(--navy);
            border-color: var(--gray-300);
            box-shadow: 0 2px 10px rgba(17, 24, 39, .07);
        }

        .pending-exit svg {
            width: 15px;
            height: 15px;
        }
    </style>
</head>

<body>
    <div class="ver-bg" aria-hidden="true"><span></span><span></span></div>

    <div class="ver-wrap">

        <!-- Header. The arrow is the same exit as the link at the foot of the
             page — it signs out on the way, because with the session live the
             verification gate sends this account straight back here. -->
        <div class="ver-head">
            <a class="ver-back" href="<?= htmlspecialchars(url('logout') . '?to=login') ?>" aria-label="Back to log in">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 5.5 8.5 12l6.5 6.5" />
                </svg>
            </a>

            <div class="ver-head-text">
                <h1 class="ver-heading">Account Verification</h1>
                <p class="ver-sub">Submit your student details for admin review before accessing the platform.</p>
            </div>

            <span class="ver-assure">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.2 5 6v5.4c0 4.3 2.9 8.2 7 9.4 4.1-1.2 7-5.1 7-9.4V6l-7-2.8Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="m9.2 12.2 2 2 3.6-3.9" />
                </svg>
                <span>Secure &amp; Verified</span>
            </span>
        </div>

        <!-- Rejected banner -->
        <?php if ($status === 'rejected'): ?>
            <div class="alert alert-red">
                <span class="alert-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;display:block;">
                        <circle cx="12" cy="12" r="9" />
                        <path stroke-linecap="round" d="m9.2 9.2 5.6 5.6M14.8 9.2l-5.6 5.6" />
                    </svg>
                </span>
                <div>
                    <div class="alert-title">Verification Rejected</div>
                    <?php if ($admin_notes): ?>
                        <div>Reason: <?= htmlspecialchars($admin_notes) ?></div>
                    <?php endif; ?>
                    <div style="margin-top:4px; opacity:.8;">Please correct your details and resubmit below.</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Errors -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-red" style="flex-direction:column; gap:4px;">
                <?php foreach ($errors as $e): ?>
                    <div>• <?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Form card -->
        <form method="POST" enctype="multipart/form-data" onsubmit="handleSubmit()">
            <?= csrf_field() ?>
            <div class="ver-card">

                <!-- Personal Info -->
                <div class="ver-sec-head">
                    <span class="ver-sec-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.5 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7.5L14.5 3Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 3.2V8h4.8M8.5 13h7M8.5 16.5h4.5" />
                        </svg>
                    </span>
                    <span>
                        <span class="ver-sec-h">Personal Information</span>
                        <span class="ver-sec-s">Please provide your accurate student details.</span>
                    </span>
                </div>

                <div class="ver-sec-body">
                    <div class="field-grid">

                        <?php // Three parts rather than one box, and each starts
                        //     with what the account already knows. ?>
                        <div class="field-group col-2 name-row">
                            <div class="field-group">
                                <label class="field-label" for="ver-first">First Name <sup>*</sup></label>
                                <span class="field-wrap">
                                    <svg class="field-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 12.5a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4.5 20.2a7.5 7.5 0 0 1 15 0" />
                                    </svg>
                                    <input type="text" id="ver-first" name="firstname" class="field-input"
                                        value="<?= htmlspecialchars($name_value('firstname')) ?>"
                                        maxlength="50" autocomplete="given-name" placeholder="e.g. Juan">
                                </span>
                            </div>
                            <div class="field-group">
                                <label class="field-label" for="ver-middle">Middle Name</label>
                                <span class="field-wrap">
                                    <svg class="field-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 12.5a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4.5 20.2a7.5 7.5 0 0 1 15 0" />
                                    </svg>
                                    <input type="text" id="ver-middle" name="middlename" class="field-input"
                                        value="<?= htmlspecialchars($name_value('middlename')) ?>"
                                        maxlength="100" autocomplete="additional-name" placeholder="optional">
                                </span>
                            </div>
                            <div class="field-group">
                                <label class="field-label" for="ver-last">Surname <sup>*</sup></label>
                                <span class="field-wrap">
                                    <svg class="field-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 12.5a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4.5 20.2a7.5 7.5 0 0 1 15 0" />
                                    </svg>
                                    <input type="text" id="ver-last" name="lastname" class="field-input"
                                        value="<?= htmlspecialchars($name_value('lastname')) ?>"
                                        maxlength="50" autocomplete="family-name" placeholder="e.g. dela Cruz">
                                </span>
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="ver-sid">Student ID <sup>*</sup></label>
                            <span class="field-wrap">
                                <svg class="field-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                                    <circle cx="8.8" cy="11.2" r="1.9" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.9 16.1a3.3 3.3 0 0 1 5.8 0M14.4 10.4h4.2M14.4 13.6h3" />
                                </svg>
                                <input type="text" id="ver-sid" name="student_id" class="field-input"
                                    value="<?= htmlspecialchars($existing['student_id'] ?? '') ?>"
                                    placeholder="e.g. 2021-00123">
                            </span>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="ver-year">Year Level <sup>*</sup></label>
                            <span class="field-wrap">
                                <svg class="field-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect x="3.5" y="5" width="17" height="15" rx="2.5" />
                                    <path stroke-linecap="round" d="M8 3.2v3.4M16 3.2v3.4M3.5 10h17" />
                                </svg>
                                <select id="ver-year" name="year_level" class="field-input">
                                    <option value="">Select year</option>
                                    <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $y): ?>
                                        <option value="<?= $y ?>" <?= ($existing['year_level'] ?? '') === $y ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <svg class="field-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9.5 6 6 6-6" />
                                </svg>
                            </span>
                        </div>

                        <div class="field-group col-2">
                            <label class="field-label" for="ver-course">Course <sup>*</sup></label>
                            <span class="field-wrap">
                                <svg class="field-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4 2.5 8.6 12 13.2l9.5-4.6L12 4Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 10.8v4.4c0 1.6 2.5 3 5.5 3s5.5-1.4 5.5-3v-4.4" />
                                </svg>
                                <select id="ver-course" name="course" class="field-input">
                                <option value="">Select course</option>
                                <?php foreach (
                                    [
                                        'Bachelor of Elementary Education',
                                        'Bachelor of Special Needs Education (BSNED) Major in Early Childhood Education',
                                        'Bachelor of Technology and Livelihood Education (BTLED) Major in Home Economics',
                                        'Bachelor of Secondary Education (BSEd) Major in Mathematics',
                                        'Bachelor of Secondary Education (BSEd) Major in English',
                                        'Bachelor of Secondary Education (BSEd) Major in Science',
                                        'Bachelor of Secondary Education (BSEd) Major in Social Studies',
                                        'Bachelor of Science in Industrial Education',
                                        'Physical Education',
                                    ] as $c
                                ): ?>
                                    <option value="<?= $c ?>" <?= ($existing['course'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option>
                                <?php endforeach; ?>
                                </select>
                                <svg class="field-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9.5 6 6 6-6" />
                                </svg>
                            </span>
                        </div>

                        <div class="field-group col-2">
                            <label class="field-label" for="ver-club">Club <sup>*</sup></label>
                            <span class="field-wrap">
                                <svg class="field-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.2 11.5a3.4 3.4 0 1 0 0-6.8 3.4 3.4 0 0 0 0 6.8ZM2.8 19.4a6.4 6.4 0 0 1 12.8 0" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.2 5.2a3.2 3.2 0 0 1 0 6.2M17.4 13.6a5.6 5.6 0 0 1 3.8 5.3" />
                                </svg>
                                <select id="ver-club" name="club" class="field-input">
                                <option value="">Select club</option>
                                <?php foreach (
                                    [
                                        'Mathematics Club',
                                        'Science Club',
                                        'English Club',
                                        'Social Studies Club',
                                        'Home Economics Club',
                                        'Industrial Education Club',
                                        'Physical Education Club',
                                        'Special Education Club',
                                        'Elementary Education Club',
                                    ] as $cl
                                ): ?>
                                    <option value="<?= $cl ?>" <?= ($existing['club'] ?? '') === $cl ? 'selected' : '' ?>><?= $cl ?></option>
                                <?php endforeach; ?>
                                </select>
                                <svg class="field-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9.5 6 6 6-6" />
                                </svg>
                            </span>
                        </div>

                    </div>
                </div>

                <!-- Divider -->
                <div style="border-top:1px solid var(--gray-100);"></div>

                <!-- Upload section label -->
                <div class="ver-sec-head">
                    <span class="ver-sec-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16.5v2.3A2.2 2.2 0 0 0 6.2 21h11.6a2.2 2.2 0 0 0 2.2-2.2v-2.3M12 3.5v12M12 3.5 7.8 7.7M12 3.5l4.2 4.2" />
                        </svg>
                    </span>
                    <span>
                        <span class="ver-sec-h">Upload Documents</span>
                        <span class="ver-sec-s">A valid ID and your Certificate of Registration. JPG or PNG, up to 3MB each.</span>
                    </span>
                </div>

                <!-- Upload zones -->
                <div class="upload-grid">

                    <!-- Valid ID -->
                    <div>
                        <div class="field-label" style="margin-bottom:8px;">Valid ID <sup style="color:var(--danger)">*</sup></div>
                        <label class="upload-zone" id="id-zone">
                            <?php if (!empty($existing['id_image'])): ?>
                                <img src="<?= htmlspecialchars(VerificationFiles::url($existing['id_image'])) ?>"
                                    class="upload-preview" id="id-preview">
                            <?php else: ?>
                                <img id="id-preview" class="upload-preview" style="display:none;">
                            <?php endif; ?>
                            <div class="upload-icon" id="id-icon" <?= !empty($existing['id_image']) ? 'style="display:none"' : '' ?>>
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                                </svg>
                            </div>
                            <div class="upload-title" id="id-label"><?= !empty($existing['id_image']) ? 'Change ID' : 'Upload Valid ID' ?></div>
                            <div class="upload-hint">JPG, PNG · max 3MB</div>
                            <input type="file" name="id_image" accept="image/*"
                                onchange="previewFile(this,'id-preview','id-label','id-icon')">
                        </label>
                    </div>

                    <!-- COR -->
                    <div>
                        <div class="field-label" style="margin-bottom:8px;">Certificate of Registration <sup style="color:var(--danger)">*</sup></div>
                        <label class="upload-zone" id="cor-zone">
                            <?php if (!empty($existing['credential_image'])): ?>
                                <img src="<?= htmlspecialchars(VerificationFiles::url($existing['credential_image'])) ?>"
                                    class="upload-preview" id="cor-preview">
                            <?php else: ?>
                                <img id="cor-preview" class="upload-preview" style="display:none;">
                            <?php endif; ?>
                            <div class="upload-icon" id="cor-icon" <?= !empty($existing['credential_image']) ? 'style="display:none"' : '' ?>>
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                </svg>
                            </div>
                            <div class="upload-title" id="cor-label"><?= !empty($existing['credential_image']) ? 'Change COR' : 'Upload COR' ?></div>
                            <div class="upload-hint">JPG, PNG · max 3MB</div>
                            <input type="file" name="credential_image" accept="image/*"
                                onchange="previewFile(this,'cor-preview','cor-label','cor-icon')">
                        </label>
                    </div>

                </div>

                <!-- Footer -->
                <div class="ver-footer">
                    <span class="ver-footer-note">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 11v5.2M12 7.9h.01" />
                        </svg>
                        All fields marked <sup style="color:var(--danger)">*</sup> are required.
                    </span>
                    <button type="submit" class="ver-submit">
                        <?= $existing ? 'Resubmit for Review' : 'Submit for Verification' ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h15M13.5 6.2 19.8 12l-6.3 5.8" />
                        </svg>
                    </button>
                </div>

            </div>
        </form>

        <!-- Same exit as the waiting screen. This form had no links on it at
             all, so anyone not ready to upload their documents today had
             nothing to click but the browser's Back button. -->
        <p style="text-align:center; margin-top:22px;">
            <a class="pending-exit" href="<?= htmlspecialchars(url('logout') . '?to=login') ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 17.5 19.5 13 15 8.5M19 13H9" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5H6.5A1.5 1.5 0 0 0 5 6v14a1.5 1.5 0 0 0 1.5 1.5H12" />
                </svg>
                Back to log in
            </a>
        </p>

        <p style="text-align:center; font-size:11.5px; color:var(--gray-300); margin-top:20px;">
            NEUST · PeerConnect · <?= date('Y') ?>
        </p>
    </div>

    <!-- Submit overlay -->
    <div id="submit-overlay">
        <div class="overlay-spinner"></div>
        <p class="overlay-text">Submitting your documents…</p>
        <p class="overlay-sub">Please wait, do not close this page</p>
    </div>

    <script>
        function previewFile(input, previewId, labelId, iconId) {
            const file = input.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = e => {
                const img = document.getElementById(previewId);
                img.src = e.target.result;
                img.style.display = '';
                document.getElementById(labelId).textContent = file.name;
                const icon = document.getElementById(iconId);
                if (icon) icon.style.display = 'none';
            };
            reader.readAsDataURL(file);
        }

        function handleSubmit() {
            document.getElementById('submit-overlay').style.display = 'flex';
            return true;
        }
    </script>
</body>

</html>
