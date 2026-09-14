<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . "/../db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: " . url('welcomepage'));
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$errors  = [];

$existing    = $con->query("SELECT * FROM user_verifications WHERE user_id = $user_id")->fetch_assoc();
$status      = $existing['status']      ?? null;
$admin_notes = $existing['admin_notes'] ?? null;

// ── Pending screen ────────────────────────────────────────────
if ($status === 'pending'): ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
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
    $full_name  = trim($_POST['full_name']  ?? '');
    $student_id = trim($_POST['student_id'] ?? '');
    $course     = trim($_POST['course']     ?? '');
    $year_level = trim($_POST['year_level'] ?? '');
    $club       = trim($_POST['club']       ?? '');

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
    elseif (strlen($club) > 150)                          $errors[] = "Club name must be 150 characters or fewer.";

    $id_image         = $existing['id_image']         ?? null;
    $credential_image = $existing['credential_image'] ?? null;

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
            $id_image   = uniqid("id_") . "." . $id_ext;
            $upload_dir = PUBLIC_PATH . "/uploads/verification/";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            move_uploaded_file($_FILES['id_image']['tmp_name'], $upload_dir . $id_image);
        }
    } elseif (!$existing) {
        $errors[] = "Valid ID is required.";
    }

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
            $stmt = $con->prepare("UPDATE user_verifications SET full_name=?, student_id=?, course=?, year_level=?, club=?, id_image=?, credential_image=?, status='pending', admin_notes=NULL, submitted_at=NOW(), reviewed_at=NULL WHERE user_id=?");
            $stmt->bind_param("sssssssi", $full_name, $student_id, $course, $year_level, $club, $id_image, $credential_image, $user_id);
        } else {
            $stmt = $con->prepare("INSERT INTO user_verifications (user_id, full_name, student_id, course, year_level, club, id_image, credential_image, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->bind_param("isssssss", $user_id, $full_name, $student_id, $course, $year_level, $club, $id_image, $credential_image);
        }
        $stmt->execute();
        // NOTE: users.verified is intentionally NOT set here — it is granted only
        // by admin approval (see admin/verify.php), not on submission.
        pc_flash('success', 'An admin will review it and you will be notified either way.', 'Verification submitted');
        header("Location: " . url('mentee-verification'));
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
            max-width: 600px;
            animation: fadeIn .35s ease forwards;
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
            font-size: 22px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 4px;
        }

        .ver-sub {
            font-size: 13px;
            color: var(--gray-400);
            margin-bottom: 28px;
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

        .ver-section-title {
            font-family: 'Inter', sans-serif;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--gray-400);
            padding-bottom: 14px;
            border-bottom: 1px solid var(--gray-100);
            margin-bottom: 18px;
        }

        .ver-section-title span {
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .ver-section-title span::before {
            content: '';
            display: inline-block;
            width: 3px;
            height: 14px;
            background: var(--accent);
            border-radius: 2px;
        }

        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .col-2 {
            grid-column: 1/-1;
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
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(31, 78, 69, .12);
        }

        select.field-input {
            cursor: pointer;
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
            padding: 18px 24px;
            border-top: 1px solid var(--gray-100);
            background: var(--gray-50);
        }

        .ver-footer-note {
            font-size: 11.5px;
            color: var(--gray-400);
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
    </style>
</head>

<body>
    <div class="ver-wrap">

        <!-- Brand -->
        <div style="text-align:center; margin-bottom:20px;">
            <div class="ver-brand">
                <div class="ver-brand-mark">N</div>
                <span class="ver-brand-name">NEUST · PeerConnect</span>
            </div>
            <div class="ver-pill">🎓 Mentee Account</div>
            <h1 class="ver-heading">Account Verification</h1>
            <p class="ver-sub">Submit your student details for admin review before accessing the platform</p>
        </div>

        <!-- Rejected banner -->
        <?php if ($status === 'rejected'): ?>
            <div class="alert alert-red">
                <span class="alert-icon">❌</span>
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
                <div class="ver-section" style="padding-bottom:22px;">
                    <div class="ver-section-title"><span>Personal Information</span></div>
                    <div class="field-grid">

                        <div class="field-group col-2">
                            <label class="field-label">Full Name <sup>*</sup></label>
                            <input type="text" name="full_name" class="field-input"
                                value="<?= htmlspecialchars($existing['full_name'] ?? '') ?>"
                                placeholder="e.g. Juan dela Cruz">
                        </div>

                        <div class="field-group">
                            <label class="field-label">Student ID <sup>*</sup></label>
                            <input type="text" name="student_id" class="field-input"
                                value="<?= htmlspecialchars($existing['student_id'] ?? '') ?>"
                                placeholder="e.g. 2021-00123">
                        </div>

                        <div class="field-group">
                            <label class="field-label">Year Level <sup>*</sup></label>
                            <select name="year_level" class="field-input">
                                <option value="">Select year</option>
                                <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $y): ?>
                                    <option value="<?= $y ?>" <?= ($existing['year_level'] ?? '') === $y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field-group col-2">
                            <label class="field-label">Course <sup>*</sup></label>
                            <select name="course" class="field-input">
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
                        </div>

                        <div class="field-group col-2">
                            <label class="field-label">Club <sup>*</sup></label>
                            <select name="club" class="field-input">
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
                        </div>

                    </div>
                </div>

                <!-- Divider -->
                <div style="border-top:1px solid var(--gray-100);"></div>

                <!-- Upload section label -->
                <div class="ver-section" style="padding-bottom:0;">
                    <div class="ver-section-title" style="margin-bottom:0;"><span>Upload Documents</span></div>
                </div>

                <!-- Upload zones -->
                <div class="upload-grid">

                    <!-- Valid ID -->
                    <div>
                        <div class="field-label" style="margin-bottom:8px;">Valid ID <sup style="color:var(--danger)">*</sup></div>
                        <label class="upload-zone" id="id-zone">
                            <?php if (!empty($existing['id_image'])): ?>
                                <img src="<?= asset('uploads/verification/') ?><?= htmlspecialchars($existing['id_image']) ?>"
                                    class="upload-preview" id="id-preview">
                            <?php else: ?>
                                <img id="id-preview" class="upload-preview" style="display:none;">
                            <?php endif; ?>
                            <div class="upload-icon" id="id-icon" <?= !empty($existing['id_image']) ? 'style="display:none"' : '' ?>>
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                                </svg>
                            </div>
                            <div class="upload-title" id="id-label"><?= !empty($existing['id_image']) ? '📎 Change ID' : 'Upload Valid ID' ?></div>
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
                                <img src="<?= asset('uploads/verification/') ?><?= htmlspecialchars($existing['credential_image']) ?>"
                                    class="upload-preview" id="cor-preview">
                            <?php else: ?>
                                <img id="cor-preview" class="upload-preview" style="display:none;">
                            <?php endif; ?>
                            <div class="upload-icon" id="cor-icon" <?= !empty($existing['credential_image']) ? 'style="display:none"' : '' ?>>
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                </svg>
                            </div>
                            <div class="upload-title" id="cor-label"><?= !empty($existing['credential_image']) ? '📎 Change COR' : 'Upload COR' ?></div>
                            <div class="upload-hint">JPG, PNG · max 3MB</div>
                            <input type="file" name="credential_image" accept="image/*"
                                onchange="previewFile(this,'cor-preview','cor-label','cor-icon')">
                        </label>
                    </div>

                </div>

                <!-- Footer -->
                <div class="ver-footer">
                    <span class="ver-footer-note">All fields marked <sup style="color:var(--danger)">*</sup> are required</span>
                    <button type="submit" class="btn btn-blue" style="padding:10px 22px; font-size:13px;">
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        <?= $existing ? 'Resubmit for Review' : 'Submit for Verification' ?>
                    </button>
                </div>

            </div>
        </form>

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
                document.getElementById(labelId).textContent = '✅ ' + file.name;
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
