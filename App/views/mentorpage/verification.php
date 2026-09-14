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
$existing = $con->query("SELECT * FROM user_verifications WHERE user_id = $user_id")->fetch_assoc();
$status      = $existing['status']      ?? null;
$admin_notes = $existing['admin_notes'] ?? null;

// ── Waiting / loading screen ─────────────────────────────────
if ($status === 'pending'): ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
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
    $full_name  = trim($_POST['full_name']  ?? '');
    $student_id = trim($_POST['student_id'] ?? '');
    $course     = trim($_POST['course']     ?? '');
    $year_level = trim($_POST['year_level'] ?? '');
    $club       = trim($_POST['club']       ?? '');
    $expertise  = trim($_POST['expertise']  ?? '');

    if (!$full_name)                                      $errors[] = "Full name is required.";
    elseif (!preg_match('/^[A-Za-z ,.\'-]{2,100}$/', $full_name)) $errors[] = "Full name: letters only, 2–100 characters.";

    if (!$student_id)                                     $errors[] = "Student ID is required.";
    elseif (!preg_match('/^[A-Z0-9\-]{3,20}$/i', $student_id)) $errors[] = "Student ID: letters, numbers and hyphens only, 3–20 characters.";

    if (!$course)                                         $errors[] = "Course is required.";
    elseif (strlen($course) > 100)                        $errors[] = "Course must be 100 characters or fewer.";

    $allowed_levels = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'];
    if (!$year_level)                                     $errors[] = "Year level is required.";
    elseif (!in_array($year_level, $allowed_levels, true)) $errors[] = "Please select a valid year level.";

    if (!$club)                                           $errors[] = "Club/Organization is required.";
    elseif (strlen($club) > 100)                          $errors[] = "Club name must be 150 characters or fewer.";
    if (!$expertise)  $errors[] = "At least one area of expertise is required.";

    $id_image         = $existing['id_image']         ?? null;
    $credential_image = $existing['credential_image'] ?? null;

    // Upload ID
    if (!empty($_FILES['id_image']['name'])) {
        $allowed_exts  = ['jpg','jpeg','png','gif','webp','pdf'];
        $allowed_mimes = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
        $id_ext  = strtolower(pathinfo($_FILES['id_image']['name'], PATHINFO_EXTENSION));
        $id_mime = mime_content_type($_FILES['id_image']['tmp_name']);
        if ($_FILES['id_image']['size'] > 3 * 1024 * 1024) {
            $errors[] = "Valid ID must be less than 3MB.";
        } elseif (!in_array($id_ext, $allowed_exts) || !in_array($id_mime, $allowed_mimes)) {
            $errors[] = "Valid ID must be a JPG, PNG, GIF, WEBP, or PDF file.";
        } else {
            $id_image   = uniqid("mid_") . "." . $id_ext;
            $upload_dir = PUBLIC_PATH . "/uploads/verification/";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            move_uploaded_file($_FILES['id_image']['tmp_name'], $upload_dir . $id_image);
        }
    } elseif (!$existing) {
        $errors[] = "Valid ID is required.";
    }

    // Upload COR
    if (!empty($_FILES['credential_image']['name'])) {
        $allowed_exts  = ['jpg','jpeg','png','gif','webp','pdf'];
        $allowed_mimes = ['image/jpeg','image/png','image/gif','image/webp','application/pdf'];
        $cor_ext  = strtolower(pathinfo($_FILES['credential_image']['name'], PATHINFO_EXTENSION));
        $cor_mime = mime_content_type($_FILES['credential_image']['tmp_name']);
        if ($_FILES['credential_image']['size'] > 3 * 1024 * 1024) {
            $errors[] = "COR must be less than 3MB.";
        } elseif (!in_array($cor_ext, $allowed_exts) || !in_array($cor_mime, $allowed_mimes)) {
            $errors[] = "COR must be a JPG, PNG, GIF, WEBP, or PDF file.";
        } else {
            $credential_image = uniqid("cor_") . "." . $cor_ext;
            $upload_dir = PUBLIC_PATH . "/uploads/verification/";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            move_uploaded_file($_FILES['credential_image']['tmp_name'], $upload_dir . $credential_image);
        }
    } elseif (!$existing) {
        $errors[] = "Certificate of Registration is required.";
    }

    if (empty($errors)) {
        if ($existing) {
            $stmt = $con->prepare("
                UPDATE user_verifications
                SET full_name=?, student_id=?, course=?, year_level=?, club=?,
                    expertise=?, id_image=?, credential_image=?,
                    status='pending', admin_notes=NULL, submitted_at=NOW(), reviewed_at=NULL
                WHERE user_id=?
            ");
            $stmt->bind_param(
                "ssssssssi",
                $full_name,
                $student_id,
                $course,
                $year_level,
                $club,
                $expertise,
                $id_image,
                $credential_image,
                $user_id
            );
        } else {
            $stmt = $con->prepare("
                INSERT INTO user_verifications
                    (user_id, full_name, student_id, course, year_level, club,
                     expertise, id_image, credential_image, status, submitted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->bind_param(
                "issssssss",
                $user_id,
                $full_name,
                $student_id,
                $course,
                $year_level,
                $club,
                $expertise,
                $id_image,
                $credential_image
            );
        }
        $stmt->execute();

        // NOTE: users.verified/role are intentionally NOT set here — mentor access
        // is granted only by admin approval (see admin/verify.php), not on submission.

        pc_flash('success', 'An admin will review it and you will be notified either way.', 'Application submitted');
        header("Location: " . url('mentor-verification'));
        exit;
    }

    $existing = $con->query("SELECT * FROM user_verifications WHERE user_id=$user_id")->fetch_assoc();
}
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
                🎓 Mentor Account
            </div>
            <h1 class="text-xl font-semibold text-gray-800">Mentor Verification</h1>
            <p class="text-sm text-gray-400 mt-1">Submit your student details and areas of expertise for admin review</p>
        </div>

        <!-- Rejected banner -->
        <?php if ($status === 'rejected'): ?>
            <div class="bg-red-50 border border-red-200 rounded-xl px-5 py-4 mb-5 flex items-start gap-3">
                <span class="text-red-500 text-lg mt-0.5">❌</span>
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

                <div class="grid grid-cols-2 gap-4">

                    <div class="col-span-2">
                        <label class="text-xs text-gray-400 mb-1 block">Full Name <span class="text-red-400">*</span></label>
                        <input type="text" name="full_name"
                            value="<?= htmlspecialchars($existing['full_name'] ?? '') ?>"
                            placeholder="e.g. Juan dela Cruz"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
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
                    <label class="text-xs text-gray-400 mb-1 block">
                        Areas of Expertise <span class="text-red-400">*</span>
                        <span class="font-normal text-gray-300 ml-1">— type then press Enter or comma to add each</span>
                    </label>
                    <div id="expertise-wrap" onclick="document.getElementById('expertise-text').focus()">
                        <!-- chips injected by JS -->
                        <input id="expertise-text" type="text" placeholder="e.g. Algebra, Trigonometry…" autocomplete="off">
                    </div>
                    <p class="text-xs text-gray-300 mt-1">You can add multiple topics separated by comma</p>
                </div>

                <!-- ── Upload Documents ── -->
                <h2 class="text-sm font-semibold text-gray-700 border-b border-gray-100 pb-3 pt-2">Upload Documents</h2>

                <div class="grid grid-cols-2 gap-4">

                    <!-- Valid ID -->
                    <div>
                        <label class="text-xs text-gray-400 mb-2 block">Valid ID <span class="text-red-400">*</span></label>
                        <label class="w-full h-36 bg-gray-50 border-2 border-dashed border-gray-200 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-blue-300 hover:bg-blue-50 transition relative overflow-hidden">
                            <?php if (!empty($existing['id_image'])): ?>
                                <img src="<?= asset('uploads/verification/') ?><?= htmlspecialchars($existing['id_image']) ?>"
                                    class="absolute inset-0 w-full h-full object-contain p-1" id="id-preview">
                            <?php else: ?>
                                <img id="id-preview" class="absolute inset-0 w-full h-full object-contain p-1 hidden">
                            <?php endif; ?>
                            <span class="text-xs text-gray-400 z-10" id="id-label">
                                <?= !empty($existing['id_image']) ? '📎 Change ID' : '📎 Upload ID' ?>
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
                                <img src="<?= asset('uploads/verification/') ?><?= htmlspecialchars($existing['credential_image']) ?>"
                                    class="absolute inset-0 w-full h-full object-contain p-1" id="cor-preview">
                            <?php else: ?>
                                <img id="cor-preview" class="absolute inset-0 w-full h-full object-contain p-1 hidden">
                            <?php endif; ?>
                            <span class="text-xs text-gray-400 z-10" id="cor-label">
                                <?= !empty($existing['credential_image']) ? '📎 Change COR' : '📎 Upload COR' ?>
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
                document.getElementById(labelId).textContent = '✅ ' + file.name;
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
