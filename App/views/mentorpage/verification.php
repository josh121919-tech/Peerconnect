<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . "/../db.php";

// user_verifications, including its expertise column, is part of the schema
// (database_migrations.sql). This page used to CREATE and ALTER it on every
// load, which needs table-changing rights the app's database account should
// not have.

if (!isset($_SESSION['user_id'])) {
    header("Location: " . url('welcomepage'));
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$errors  = [];

// Get existing verification row
$existing = VerificationRepository::forUser($con, $user_id);
$status      = $existing['status']      ?? null;
$admin_notes = $existing['admin_notes'] ?? null;

// ── Waiting / loading screen ─────────────────────────────────
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
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
        <style>
            * {
                font-family: 'Inter', sans-serif;
            }

            body {
                background: #f5f6fa;
            }

            @keyframes spin {
                to {
                    transform: rotate(360deg);
                }
            }

            @keyframes pulse-ring {
                0% {
                    transform: scale(1);
                    opacity: .6
                }

                100% {
                    transform: scale(1.6);
                    opacity: 0
                }
            }

            .spin {
                animation: spin 1s linear infinite;
            }

            .pulse-ring {
                animation: pulse-ring 1.5s ease-out infinite;
            }
        </style>
    </head>

    <body class="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 flex items-center justify-center px-4">
        <div class="text-center max-w-md w-full">

            <div class="relative w-24 h-24 mx-auto mb-8">
                <div class="pulse-ring absolute inset-0 rounded-full bg-blue-300"></div>
                <div class="pulse-ring absolute inset-0 rounded-full bg-blue-200" style="animation-delay:.5s"></div>
                <div class="relative w-24 h-24 rounded-full bg-white shadow-lg flex items-center justify-center">
                    <svg class="w-10 h-10 text-blue-500 spin" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                </div>
            </div>

            <h1 class="text-2xl font-bold text-gray-800 mb-2">Waiting for Verification</h1>
            <p class="text-gray-500 mb-6">Your documents have been submitted. An admin will review your mentor application shortly.</p>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6 text-left space-y-4">
                <div class="flex items-center gap-3">
                    <div class="w-7 h-7 rounded-full bg-green-100 text-green-600 flex items-center justify-center text-xs font-bold shrink-0">✓</div>
                    <div>
                        <p class="text-sm font-medium text-gray-800">Documents submitted</p>
                        <p class="text-xs text-gray-400">Your ID and COR have been uploaded</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="w-7 h-7 rounded-full bg-blue-100 text-blue-500 flex items-center justify-center shrink-0">
                        <svg class="w-3.5 h-3.5 spin" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-800">Admin review in progress</p>
                        <p class="text-xs text-gray-400">Usually completed within 24 hours</p>
                    </div>
                </div>
                <div class="flex items-center gap-3" id="step3">
                    <div class="w-7 h-7 rounded-full bg-gray-100 text-gray-400 flex items-center justify-center text-xs font-bold shrink-0" id="step3-icon">3</div>
                    <div>
                        <p class="text-sm font-medium text-gray-400" id="step3-title">Mentor access granted</p>
                        <p class="text-xs text-gray-300" id="step3-sub">You'll be redirected once approved</p>
                    </div>
                </div>
            </div>

            <p class="text-xs text-gray-400" id="status-note">This page checks your status every 20 seconds</p>

            <!--
              A way off this screen — see the matching note on the mentee copy.
              It signs out on the way, because while the session is live the
              verification gate sends this account straight back here from the
              login page, so a plain link to /login would bounce.
            -->
            <a href="<?= htmlspecialchars(url('logout') . '?to=login') ?>"
               class="mt-6 inline-flex items-center justify-center gap-2 rounded-lg border border-gray-200
                      bg-white px-4 py-2.5 text-sm font-semibold text-gray-500 no-underline transition
                      hover:border-gray-400 hover:text-gray-900 hover:shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24" aria-hidden="true">
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
                    const res = await fetch('<?= url('mentor-check-status') ?>');
                    if (!res.ok) return; // skip if blocked/error
                    const data = await res.json();

                    if (data.status === 'approved') {
                        clearInterval(polling);
                        document.getElementById('step3-icon').className =
                            'w-7 h-7 rounded-full bg-green-100 text-green-600 flex items-center justify-center text-xs font-bold shrink-0';
                        document.getElementById('step3-icon').textContent = '✓';
                        document.getElementById('step3-title').className = 'text-sm font-medium text-gray-800';
                        document.getElementById('step3-title').textContent = 'Mentor access granted!';
                        document.getElementById('step3-sub').className = 'text-xs text-green-500';
                        document.getElementById('step3-sub').textContent = 'Redirecting you now…';
                        document.getElementById('status-note').textContent = 'Approved! Redirecting in a moment…';
                        setTimeout(() => { window.location.href = data.redirect; }, 2000);

                    } else if (data.status === 'rejected') {
                        clearInterval(polling);
                        document.getElementById('status-note').textContent = 'Verification rejected. Reloading…';
                        setTimeout(() => { window.location.href = data.reject_redirect; }, 2000);
                    }
                } catch (e) { /* network error — try again next interval */ }
            }

            checkStatus();
            polling = setInterval(checkStatus, 10000);
        </script>
    </body>

    </html>
<?php exit;
endif;

// ── Approved → redirect ──────────────────────────────────────
if ($status === 'approved') {
    header("Location: " . url('mentor-dashboard'));
    exit;
}

// ── Handle POST ──────────────────────────────────────────────
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
    $expertise  = $field('expertise');

    /*
     * Three parts, matching the mentee form. full_name is still written,
     * because the admin queue and the notification text read it. A middle
     * name is optional, as it is at sign-up.
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
    if (!$expertise)  $errors[] = "At least one area of expertise is required.";

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
                'expertise'        => $expertise,
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

        // NOTE: users.verified/role are intentionally NOT set here — mentor access
        // is granted only by admin approval (see admin/action_verify.php), not on submission.

        pc_flash('success', 'An admin will review it and you will be notified either way.', 'Application submitted');
        header("Location: " . url('mentor-verification'));
        exit;
    }

    $existing = VerificationRepository::forUser($con, $user_id);
}

/*
 * What goes in the three name boxes, most specific first: what was just
 * typed, then the application already on file, then the account's own names
 * from sign-up. Prefilled but editable — the name on a student ID is not
 * always the name somebody signed up with. Mirrors the mentee form.
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
    <title>Mentor Verification – NEUST</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }

        body {
            background: #f5f6fa;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .spin-sm {
            animation: spin .7s linear infinite;
        }

        /* ── Tag chip input ── */
        #expertise-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: .375rem;
            align-items: center;
            min-height: 42px;
            border: 1px solid #e5e7eb;
            border-radius: .75rem;
            padding: .35rem .75rem;
            background: #fff;
            cursor: text;
            transition: box-shadow .15s, border-color .15s;
        }

        #expertise-wrap:focus-within {
            border-color: #93c5fd;
            box-shadow: 0 0 0 3px rgba(147, 197, 253, .3);
        }

        #expertise-wrap input {
            border: none;
            outline: none;
            flex: 1;
            min-width: 100px;
            font-size: .875rem;
            background: transparent;
            padding: .1rem 0;
            color: #111827;
        }

        .tag {
            display: inline-flex;
            align-items: center;
            gap: .25rem;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            border-radius: 999px;
            padding: .18rem .6rem;
            font-size: .72rem;
            font-weight: 500;
            white-space: nowrap;
        }

        .tag-x {
            background: none;
            border: none;
            cursor: pointer;
            color: #93c5fd;
            font-size: .85rem;
            line-height: 1;
            padding: 0 0 0 .1rem;
            display: flex;
            align-items: center;
        }

        .tag-x:hover {
            color: #1d4ed8;
        }
    </style>
</head>

<body class="min-h-screen flex items-start justify-center py-10 px-4">
    <div class="w-full max-w-2xl">

        <!-- Header -->
        <div class="text-center mb-6">
            <div class="inline-flex items-center gap-2 mb-3">
                <div class="w-8 h-8 rounded-full border-2 border-blue-200 flex items-center justify-center font-bold text-blue-600 text-xs">N</div>
                <span class="font-semibold text-gray-700">NEUST · PeerConnect</span>
            </div>
            <div class="inline-flex items-center gap-1.5 bg-blue-50 border border-blue-100 rounded-full px-3 py-1 text-xs font-semibold text-blue-600 mb-2">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4 2.5 8.6 12 13.2l9.5-4.6L12 4Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 10.8v4.4c0 1.6 2.5 3 5.5 3s5.5-1.4 5.5-3v-4.4" />
                </svg>
                Mentor Account
            </div>
            <h1 class="text-xl font-semibold text-gray-800">Mentor Verification</h1>
            <p class="text-sm text-gray-400 mt-1">Submit your student details and areas of expertise for admin review</p>
        </div>

        <!-- Rejected banner -->
        <?php if ($status === 'rejected'): ?>
            <div class="bg-red-50 border border-red-200 rounded-xl px-5 py-4 mb-5 flex items-start gap-3">
                <span class="text-red-500 mt-0.5" aria-hidden="true">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="9" />
                        <path stroke-linecap="round" d="m9.2 9.2 5.6 5.6M14.8 9.2l-5.6 5.6" />
                    </svg>
                </span>
                <div>
                    <p class="font-medium text-red-700 text-sm">Verification Rejected</p>
                    <?php if ($admin_notes): ?>
                        <p class="text-red-600 text-xs mt-1">Reason: <?= htmlspecialchars($admin_notes) ?></p>
                    <?php endif; ?>
                    <p class="text-red-500 text-xs mt-1">Please correct your details and resubmit below.</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Errors -->
        <?php if (!empty($errors)): ?>
            <div class="bg-red-50 border border-red-200 rounded-xl px-5 py-3 mb-5">
                <?php foreach ($errors as $e): ?>
                    <p class="text-red-600 text-sm">• <?= htmlspecialchars($e) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" enctype="multipart/form-data" onsubmit="return handleSubmit()">
            <?= csrf_field() ?>

            <!-- Hidden field: comma-separated expertise list -->
            <input type="hidden" name="expertise" id="expertise-hidden"
                value="<?= htmlspecialchars($existing['expertise'] ?? '') ?>">

            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 space-y-5">

                <!-- ── Personal Information ── -->
                <h2 class="text-sm font-semibold text-gray-700 border-b border-gray-100 pb-3">Personal Information</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                    <?php // Three parts rather than one box, each starting from
                    //     what the account already knows. Matches the mentee form. ?>
                    <div class="col-span-2 grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label for="ver-first" class="text-xs text-gray-400 mb-1 block">First Name <span class="text-red-400">*</span></label>
                            <input type="text" id="ver-first" name="firstname" maxlength="50" autocomplete="given-name"
                                value="<?= htmlspecialchars($name_value('firstname')) ?>"
                                placeholder="e.g. Juan"
                                class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                        </div>
                        <div>
                            <label for="ver-middle" class="text-xs text-gray-400 mb-1 block">Middle Name</label>
                            <input type="text" id="ver-middle" name="middlename" maxlength="100" autocomplete="additional-name"
                                value="<?= htmlspecialchars($name_value('middlename')) ?>"
                                placeholder="optional"
                                class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                        </div>
                        <div>
                            <label for="ver-last" class="text-xs text-gray-400 mb-1 block">Surname <span class="text-red-400">*</span></label>
                            <input type="text" id="ver-last" name="lastname" maxlength="50" autocomplete="family-name"
                                value="<?= htmlspecialchars($name_value('lastname')) ?>"
                                placeholder="e.g. dela Cruz"
                                class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                        </div>
                    </div>

                    <div>
                        <label class="text-xs text-gray-400 mb-1 block">Student ID <span class="text-red-400">*</span></label>
                        <input type="text" name="student_id"
                            value="<?= htmlspecialchars($existing['student_id'] ?? '') ?>"
                            placeholder="e.g. 2021-00123"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                    </div>

                    <div>
                        <label class="text-xs text-gray-400 mb-1 block">Year Level <span class="text-red-400">*</span></label>
                        <select name="year_level" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                            <option value="">Select year</option>
                            <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $y): ?>
                                <option value="<?= $y ?>" <?= ($existing['year_level'] ?? '') === $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-span-2">
                        <label class="text-xs text-gray-400 mb-1 block">Course <span class="text-red-400">*</span></label>
                        <select name="course" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                            <option value="">Select course</option>
                            <?php
                            $courses = [
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
                            foreach ($courses as $c): ?>
                                <option value="<?= $c ?>" <?= ($existing['course'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-span-2">
                        <label class="text-xs text-gray-400 mb-1 block">Club <span class="text-red-400">*</span></label>
                        <select name="club" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                            <option value="">Select club</option>
                            <?php
                            $clubs = [
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
                            foreach ($clubs as $cl): ?>
                                <option value="<?= $cl ?>" <?= ($existing['club'] ?? '') === $cl ? 'selected' : '' ?>><?= $cl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>

                <!-- ── Mentor Details ── -->
                <h2 class="text-sm font-semibold text-gray-700 border-b border-gray-100 pb-3 pt-2">Mentor Details</h2>

                <div>
                    <?php // The hint used to sit on the same line as the label and
                          // wrapped into it on a phone, so "Areas of Expertise *"
                          // and the instruction ran together as one paragraph.
                          // Its own line, and only one of the two hints is shown
                          // on a narrow screen. ?>
                    <label class="text-xs text-gray-400 mb-1 block">
                        Areas of Expertise <span class="text-red-400">*</span>
                        <span class="font-normal text-gray-300 ml-1 hidden sm:inline">— type then press Enter or comma to add each</span>
                    </label>
                    <div id="expertise-wrap" onclick="document.getElementById('expertise-text').focus()">
                        <!-- chips injected by JS -->
                        <input id="expertise-text" type="text" placeholder="e.g. Algebra, Trigonometry…" autocomplete="off">
                    </div>
                    <p class="text-xs text-gray-300 mt-1">Type a topic, then press Enter or comma to add it.</p>
                </div>

                <!-- ── Upload Documents ── -->
                <h2 class="text-sm font-semibold text-gray-700 border-b border-gray-100 pb-3 pt-2">Upload Documents</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                    <!-- Valid ID -->
                    <div>
                        <label class="text-xs text-gray-400 mb-2 block">Valid ID <span class="text-red-400">*</span></label>
                        <label class="w-full h-36 bg-gray-50 border-2 border-dashed border-gray-200 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-blue-300 hover:bg-blue-50 transition relative overflow-hidden">
                            <?php if (!empty($existing['id_image'])): ?>
                                <img src="<?= htmlspecialchars(VerificationFiles::url($existing['id_image'])) ?>"
                                    class="absolute inset-0 w-full h-full object-contain p-1" id="id-preview">
                            <?php else: ?>
                                <img id="id-preview" class="absolute inset-0 w-full h-full object-contain p-1 hidden">
                            <?php endif; ?>
                            <span class="text-xs text-gray-400 z-10" id="id-label">
                                <?= !empty($existing['id_image']) ? 'Change ID' : 'Upload ID' ?>
                            </span>
                            <span class="text-xs text-gray-300 z-10">JPG, PNG · max 3MB</span>
                            <input type="file" name="id_image" accept="image/*"
                                class="absolute inset-0 opacity-0 cursor-pointer"
                                onchange="previewFile(this,'id-preview','id-label')">
                        </label>
                    </div>

                    <!-- COR -->
                    <div>
                        <label class="text-xs text-gray-400 mb-2 block">Certificate of Registration <span class="text-red-400">*</span></label>
                        <label class="w-full h-36 bg-gray-50 border-2 border-dashed border-gray-200 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-blue-300 hover:bg-blue-50 transition relative overflow-hidden">
                            <?php if (!empty($existing['credential_image'])): ?>
                                <img src="<?= htmlspecialchars(VerificationFiles::url($existing['credential_image'])) ?>"
                                    class="absolute inset-0 w-full h-full object-contain p-1" id="cor-preview">
                            <?php else: ?>
                                <img id="cor-preview" class="absolute inset-0 w-full h-full object-contain p-1 hidden">
                            <?php endif; ?>
                            <span class="text-xs text-gray-400 z-10" id="cor-label">
                                <?= !empty($existing['credential_image']) ? 'Change COR' : 'Upload COR' ?>
                            </span>
                            <span class="text-xs text-gray-300 z-10">JPG, PNG · max 3MB</span>
                            <input type="file" name="credential_image" accept="image/*"
                                class="absolute inset-0 opacity-0 cursor-pointer"
                                onchange="previewFile(this,'cor-preview','cor-label')">
                        </label>
                    </div>

                </div>

                <div class="flex justify-end pt-2">
                    <button type="submit"
                        class="px-6 py-2.5 rounded-xl bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 active:scale-95 transition">
                        <?= $existing ? 'Resubmit for Review' : 'Submit for Verification' ?>
                    </button>
                </div>

            </div>
        </form>

        <p class="text-center text-xs text-gray-300 mt-6">NEUST · PeerConnect · <?= date('Y') ?></p>
    </div>

    <!-- Submit loading overlay -->
    <div id="submit-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(6px);z-index:100;flex-direction:column;align-items:center;justify-content:center;gap:14px;">
        <div style="width:48px;height:48px;border:4px solid rgba(255,255,255,.2);border-top-color:#fff;border-radius:50%;" class="spin-sm"></div>
        <p style="color:#fff;font-weight:500;font-size:15px;">Submitting your documents…</p>
        <p style="color:rgba(255,255,255,.5);font-size:12px;">Please wait, do not close this page</p>
    </div>

    <script>
        // ── File preview ─────────────────────────────────────────
        function previewFile(input, previewId, labelId) {
            const file = input.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = e => {
                const img = document.getElementById(previewId);
                img.src = e.target.result;
                img.classList.remove('hidden');
                document.getElementById(labelId).textContent = file.name;
            };
            reader.readAsDataURL(file);
        }

        // ── Expertise tag input ──────────────────────────────────
        const wrap = document.getElementById('expertise-wrap');
        const tInput = document.getElementById('expertise-text');
        const hidden = document.getElementById('expertise-hidden');

        // Seed from existing PHP value
        let tags = hidden.value ?
            hidden.value.split(',').map(t => t.trim()).filter(Boolean) : [];

        function syncHidden() {
            hidden.value = tags.join(', ');
        }

        function renderTags() {
            wrap.querySelectorAll('.tag').forEach(el => el.remove());
            tags.forEach((tag, i) => {
                const chip = document.createElement('span');
                chip.className = 'tag';
                chip.innerHTML =
                    `${tag} <button type="button" class="tag-x" onclick="removeTag(${i})">×</button>`;
                wrap.insertBefore(chip, tInput);
            });
            syncHidden();
        }

        function addTag(raw) {
            const val = raw.replace(/,/g, '').trim();
            if (val && !tags.includes(val)) {
                tags.push(val);
                renderTags();
            }
            tInput.value = '';
        }

        function removeTag(i) {
            tags.splice(i, 1);
            renderTags();
        }

        tInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                addTag(tInput.value);
            }
            if (e.key === 'Backspace' && tInput.value === '' && tags.length) {
                tags.pop();
                renderTags();
            }
        });

        tInput.addEventListener('input', () => {
            if (tInput.value.includes(',')) {
                tInput.value.split(',').forEach(p => {
                    if (p.trim()) addTag(p);
                });
                tInput.value = '';
            }
        });
        async function checkStatus() {
            try {
                const res = await fetch('<?= url('mentor-check-status') ?>');
                if (!res.ok) return;
                const data = await res.json();
                if (data.status === 'approved') {
                    setTimeout(() => { window.location.href = data.redirect; }, 2000);
                } else if (data.status === 'rejected') {
                    setTimeout(() => { window.location.href = data.reject_redirect; }, 2000);
                }
            } catch (err) { /* retry next interval */ }
        }
        // Initial render for edit mode
        renderTags();

        // ── Submit overlay ───────────────────────────────────────
        function handleSubmit() {
            if (tInput.value.trim()) addTag(tInput.value);
            syncHidden();
            document.getElementById('submit-overlay').style.display = 'flex';
            return true;
        }
    </script>
</body>

</html>
