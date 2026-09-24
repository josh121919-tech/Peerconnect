<?php

/**
 * admin/profile.php — an administrator's own profile.
 *
 * What an administrator proved when they were approved: their name, their
 * student ID, their course and year, and the post they hold in their club.
 * That application was read once by the owner and then never seen again;
 * this is where it lives afterwards.
 *
 * WHAT IS NOT HERE, AND WHY
 * The reference for this page showed a language picker, a time-zone picker
 * and a per-person email-notifications switch. None of those exist: the site
 * is in one language, its clock is set once for everybody in the application,
 * and email is switched on or off for the whole site in System Settings. They
 * are left out rather than drawn as controls that would do nothing.
 *
 * Everything shown is read from the database on this request. The four
 * figures are counts, not estimates.
 *
 * SECURITY: an approved administrator, and they see only their own profile.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$current_page = 'admin-profile';
$user_id      = (int)$_SESSION['user_id'];
$acct    = UserRepository::namesAndPhoto($con, $user_id) ?: [];
$account = UserRepository::accountRow($con, $user_id) ?: [];

// The approved application, if there is one. An administrator created before
// this review existed has none, and the section simply does not appear.
$app = VerificationRepository::forUser($con, $user_id);
$app = ($app && ($app['status'] ?? '') === 'approved') ? $app : null;

$name  = trim(($acct['firstname'] ?? '') . ' ' . ($acct['lastname'] ?? '')) ?: 'Administrator';
$photo = $acct['profile_image'] ?? '';

$figures = AdminUserRepository::profileFigures($con);

/*
 * The rest of the profile row, so the Edit form can send it back untouched.
 * saveAccountDetails() writes every one of these columns, so a form that
 * posted only the three fields on show would blank the others — the shape of
 * bug that once let saving a bio wipe somebody's interests.
 */
$profile = ProfileRepository::fields($con, $user_id,
    ['full_name', 'phone', 'location', 'birthdate', 'bio', 'visibility']) ?: [];

$page_title = 'Admin Profile';
require_once __DIR__ . '/layout.php';
?>

<style>
    .ap-head h1 {
        margin: 0 0 4px;
        font-size: 25px;
        font-weight: 700;
        letter-spacing: -.02em;
        color: var(--navy);
    }

    .ap-head p { margin: 0 0 20px; font-size: 13.5px; color: var(--gray-500); }

    .ap-card {
        background: var(--surface);
        border: 1px solid var(--gray-100);
        border-radius: 16px;
        padding: 22px;
        margin-bottom: 16px;
    }

    /* ── Hero ───────────────────────────────────────────────────────────── */
    .ap-hero { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }

    .ap-av-wrap { position: relative; flex: none; }

    .ap-av {
        width: 74px;
        height: 74px;
        border-radius: 50%;
        background: var(--mint);
        color: #fff;
        display: grid;
        place-items: center;
        font-size: 23px;
        font-weight: 700;
        overflow: hidden;
    }

    .ap-av img { width: 100%; height: 100%; object-fit: cover; }

    .ap-cam {
        position: absolute;
        right: -2px;
        bottom: -2px;
        width: 25px;
        height: 25px;
        border-radius: 50%;
        background: var(--navy);
        color: #fff;
        border: 2.5px solid var(--surface);
        display: grid;
        place-items: center;
    }

    .ap-cam svg { width: 12px; height: 12px; }

    .ap-name { font-size: 22px; font-weight: 700; color: var(--navy); }

    .ap-badge {
        display: inline-block;
        margin-left: 9px;
        padding: 3px 11px;
        border-radius: 999px;
        background: var(--accent-faint);
        color: var(--mint-deep);
        font-size: 11.5px;
        font-weight: 600;
        vertical-align: middle;
    }

    .ap-meta {
        display: flex;
        gap: 18px;
        flex-wrap: wrap;
        margin-top: 8px;
        font-size: 13px;
        color: var(--gray-500);
    }

    .ap-meta span { display: inline-flex; align-items: center; gap: 7px; }
    .ap-meta svg { width: 15px; height: 15px; color: var(--gray-400); }

    /* ── Figures ────────────────────────────────────────────────────────── */
    .ap-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 16px;
    }

    .ap-stat {
        display: flex;
        align-items: center;
        gap: 13px;
        background: var(--surface);
        border: 1px solid var(--gray-100);
        border-radius: 16px;
        padding: 18px;
    }

    .ap-stat-ico {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        display: grid;
        place-items: center;
        flex: none;
    }

    .ap-stat-ico svg { width: 20px; height: 20px; }
    .ap-stat b { display: block; font-size: 22px; line-height: 1.15; color: var(--navy); }
    .ap-stat span { font-size: 12px; color: var(--gray-500); }
    .ap-stat small { display: block; font-size: 11px; color: var(--gray-400); }

    /* ── Two columns ────────────────────────────────────────────────────── */
    .ap-cols {
        display: grid;
        grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr);
        gap: 16px;
        align-items: start;
    }

    .ap-h {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 18px;
    }

    .ap-h-t { display: flex; align-items: center; gap: 10px; font-size: 15px; font-weight: 700; color: var(--navy); }
    .ap-h-t svg { width: 18px; height: 18px; color: var(--mint); }

    .ap-field { margin-bottom: 14px; }

    .ap-k {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: var(--gray-500);
        margin-bottom: 6px;
    }

    .ap-v, .ap-input {
        width: 100%;
        box-sizing: border-box;
        font: inherit;
        font-size: 13.5px;
        padding: 11px 13px;
        border: 1px solid var(--gray-100);
        border-radius: 10px;
        background: var(--gray-50);
        color: var(--gray-800);
    }

    .ap-input { background: var(--surface); border-color: var(--border); }
    .ap-input:focus { outline: 2px solid var(--accent-soft); outline-offset: 0; border-color: var(--mint); }
    .ap-v { word-break: break-word; }

    .ap-pw { position: relative; }
    .ap-pw .ap-input { padding-right: 42px; }

    .ap-eye {
        position: absolute;
        right: 6px;
        top: 50%;
        transform: translateY(-50%);
        width: 30px;
        height: 30px;
        border: 0;
        background: none;
        color: var(--gray-400);
        cursor: pointer;
        display: grid;
        place-items: center;
    }

    .ap-eye svg { width: 17px; height: 17px; }

    .ap-shot { text-align: center; }
    .ap-shot .ap-av-wrap { display: inline-block; margin-bottom: 14px; }
    .ap-shot-t { font-size: 13.5px; font-weight: 600; color: var(--gray-700); }
    .ap-shot-d { font-size: 11.5px; color: var(--gray-500); margin: 4px 0 14px; }

    .ap-file { display: none; }

    .ap-msg { font-size: 12.5px; margin-top: 10px; display: none; }
    .ap-msg.ok { color: var(--success); display: block; }
    .ap-msg.no { color: var(--danger); display: block; }

    /*
     * pc-admin.css, which is what the admin layout loads, has no .btn — that
     * lives in pc-app.css on the member side. Every button on this page
     * rendered as bare text. Defined here rather than added to the admin
     * stylesheet, so nothing else moves.
     */
    .ap-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 10px 18px;
        border: 1px solid transparent;
        border-radius: 10px;
        font: inherit;
        font-size: 13.5px;
        font-weight: 600;
        cursor: pointer;
        transition: background .14s, border-color .14s, color .14s;
    }

    .ap-btn.primary { background: var(--mint); color: #fff; }
    .ap-btn.primary:hover { background: var(--mint-deep); }

    .ap-btn.ghost {
        background: var(--surface);
        border-color: var(--border);
        color: var(--gray-700);
    }

    .ap-btn.ghost:hover { background: var(--gray-50); }

    .ap-btn.small { padding: 7px 14px; font-size: 12.5px; }
    .ap-btn.wide { width: 100%; }

    .ap-secure {
        display: flex;
        align-items: flex-start;
        gap: 13px;
        background: var(--accent-faint);
        border-radius: 14px;
        padding: 16px 18px;
        margin-top: 4px;
    }

    .ap-secure svg { width: 20px; height: 20px; color: var(--mint); flex: none; }
    .ap-secure b { display: block; font-size: 13.5px; color: var(--navy); }
    .ap-secure span { font-size: 12.5px; color: var(--gray-600); }

    @media (max-width: 1050px) {
        .ap-cols { grid-template-columns: minmax(0, 1fr); }
        .ap-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 560px) {
        .ap-stats { grid-template-columns: minmax(0, 1fr); }
    }
</style>

<div class="ap-head">
    <h1>Admin Profile</h1>
    <p>Your account, what you were verified for, and the figures for the site you run.</p>
</div>

<div class="ap-card ap-hero">
    <span class="ap-av-wrap">
        <span class="ap-av" id="apAvatar"><?= pc_avatar($photo, $name) ?></span>
        <span class="ap-cam">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 8h3l1.5-2h7L17 8h3v11H4V8Z" />
                <circle cx="12" cy="13" r="3.2" />
            </svg>
        </span>
    </span>
    <div style="min-width:0;flex:1;">
        <div class="ap-name">
            <?= htmlspecialchars($name) ?><span class="ap-badge">Administrator</span>
        </div>
        <div class="ap-meta">
            <span>
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <rect x="3" y="5" width="18" height="14" rx="2.5" />
                    <path stroke-linecap="round" d="m3.6 6.6 8.4 6 8.4-6" />
                </svg>
                <?= htmlspecialchars((string)($acct['email'] ?? '')) ?>
            </span>
            <?php if (!empty($account['created_at'])): ?>
                <span>
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <rect x="3.5" y="5" width="17" height="15" rx="2.5" />
                        <path stroke-linecap="round" d="M8 3v4M16 3v4M3.5 10h17" />
                    </svg>
                    Joined on <?= htmlspecialchars(date('F j, Y', strtotime((string)$account['created_at']))) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php // Counts, every one of them. Nothing here is "up 12% this month",
      // because nothing records what these were last month. ?>
<div class="ap-stats">
    <div class="ap-stat">
        <span class="ap-stat-ico" style="background:var(--accent-faint);color:var(--mint);">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <circle cx="9" cy="8" r="3.2" /><path stroke-linecap="round" d="M3 19a6 6 0 0 1 12 0" />
                <path stroke-linecap="round" d="M16 5.5a3.2 3.2 0 0 1 0 5M18 19a5.5 5.5 0 0 0-2-4.3" />
            </svg>
        </span>
        <span>
            <b><?= number_format($figures['members']) ?></b>
            <span>Mentors &amp; mentees</span>
        </span>
    </div>

    <div class="ap-stat">
        <span class="ap-stat-ico" style="background:#EDE9FE;color:#6D4AFF;">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <rect x="3.5" y="5" width="17" height="15" rx="2.5" />
                <path stroke-linecap="round" d="M8 3v4M16 3v4M3.5 10h17" />
            </svg>
        </span>
        <span>
            <b><?= number_format($figures['sessions']) ?></b>
            <span>Sessions booked</span>
        </span>
    </div>

    <div class="ap-stat">
        <span class="ap-stat-ico" style="background:var(--gold-light);color:#B98900;">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5M7 3h8l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" />
                <path stroke-linecap="round" d="M9 13h6M9 16.5h4" />
            </svg>
        </span>
        <span>
            <b><?= number_format($figures['reports']) ?></b>
            <span>Reports awaiting review</span>
        </span>
    </div>

    <div class="ap-stat">
        <span class="ap-stat-ico" style="background:var(--success-bg);color:var(--success);">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5.5c0 4.2-2.9 7.6-7 8.5-4.1-.9-7-4.3-7-8.5V6l7-3Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" />
            </svg>
        </span>
        <span>
            <b style="color:<?= $figures['maintenance'] ? 'var(--gold)' : 'var(--success)' ?>;">
                <?= $figures['maintenance'] ? 'Maintenance' : 'Live' ?>
            </b>
            <span><?= $figures['maintenance'] ? 'Members are locked out' : 'All systems operational' ?></span>
        </span>
    </div>
</div>

<div class="ap-cols">
    <div>
        <div class="ap-card">
            <div class="ap-h">
                <span class="ap-h-t">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <circle cx="12" cy="8" r="3.4" /><path stroke-linecap="round" d="M5.5 20a6.5 6.5 0 0 1 13 0" />
                    </svg>
                    Personal information
                </span>
                <button class="ap-btn ghost small" type="button" id="apEditBtn">Edit</button>
            </div>

            <form id="apAccount">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <?php /* Sent back as they are: this endpoint writes the whole profile
                        row, so leaving them out would empty them. */ ?>
                <input type="hidden" name="location"   value="<?= htmlspecialchars((string)($profile['location'] ?? '')) ?>">
                <input type="hidden" name="birthdate"  value="<?= htmlspecialchars((string)($profile['birthdate'] ?? '')) ?>">
                <input type="hidden" name="bio"        value="<?= htmlspecialchars((string)($profile['bio'] ?? '')) ?>">
                <input type="hidden" name="visibility" value="<?= htmlspecialchars((string)($profile['visibility'] ?? 'everyone')) ?>">

                <div class="ap-field">
                    <span class="ap-k">Full name</span>
                    <input class="ap-input" name="full_name" maxlength="100" disabled
                           value="<?= htmlspecialchars($name) ?>">
                </div>
                <div class="ap-field">
                    <span class="ap-k">Username</span>
                    <input class="ap-input" name="username" maxlength="30" disabled
                           value="<?= htmlspecialchars((string)($account['username'] ?? '')) ?>">
                </div>
                <div class="ap-field">
                    <span class="ap-k">Phone number</span>
                    <input class="ap-input" name="phone" maxlength="25" disabled
                           value="<?= htmlspecialchars((string)($profile['phone'] ?? '')) ?>">
                </div>

                <?php /* Read-only on purpose. Changing an address is its own flow,
                        with a confirmation to the new one; a box here that quietly
                        rewrote it would skip that. */ ?>
                <div class="ap-field">
                    <span class="ap-k">Email address</span>
                    <div class="ap-v"><?= htmlspecialchars((string)($acct['email'] ?? '')) ?></div>
                </div>
                <div class="ap-field">
                    <span class="ap-k">Role</span>
                    <div class="ap-v">Administrator</div>
                </div>
                <div class="ap-field" style="margin-bottom:0;">
                    <span class="ap-k">Access level</span>
                    <div class="ap-v">Full access — every member, every setting</div>
                </div>

                <div id="apAccountActions" style="display:none;margin-top:16px;gap:10px;flex-wrap:wrap;">
                    <button class="ap-btn primary" type="submit">Save changes</button>
                    <button class="ap-btn ghost" type="button" id="apCancel">Cancel</button>
                </div>
                <div class="ap-msg" id="apAccountMsg" role="status"></div>
            </form>
        </div>

        <?php if ($app !== null): ?>
            <div class="ap-card">
                <div class="ap-h">
                    <span class="ap-h-t">
                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5.5c0 4.2-2.9 7.6-7 8.5-4.1-.9-7-4.3-7-8.5V6l7-3Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" />
                        </svg>
                        Verified details
                    </span>
                </div>
                <p style="margin:-10px 0 16px;font-size:12.5px;color:var(--gray-500);line-height:1.6;">
                    Checked against your ID and Certificate of Registration when this account was approved.
                </p>
                <div class="ap-field">
                    <span class="ap-k">Post</span>
                    <div class="ap-v"><?= htmlspecialchars((string)$app['club']) ?></div>
                </div>
                <div class="ap-field">
                    <span class="ap-k">Student ID</span>
                    <div class="ap-v"><?= htmlspecialchars((string)$app['student_id']) ?></div>
                </div>
                <div class="ap-field">
                    <span class="ap-k">Course</span>
                    <div class="ap-v"><?= htmlspecialchars((string)$app['course']) ?></div>
                </div>
                <div class="ap-field" style="margin-bottom:0;">
                    <span class="ap-k">Year level</span>
                    <div class="ap-v"><?= htmlspecialchars((string)$app['year_level']) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php /* The reference put a language picker, a time-zone picker and an
                email-notifications switch here. The site is in one language, its
                clock is set once for everybody in the application, and email is
                switched on or off for the whole site in System Settings — so
                these are stated, not offered as controls that would do nothing. */ ?>
        <div class="ap-card">
            <div class="ap-h">
                <span class="ap-h-t">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="3.2" />
                        <path stroke-linecap="round" d="M12 3.5v2.2M12 18.3v2.2M20.5 12h-2.2M5.7 12H3.5M18 6l-1.6 1.6M7.6 16.4 6 18M18 18l-1.6-1.6M7.6 7.6 6 6" />
                    </svg>
                    Site settings
                </span>
            </div>
            <div class="ap-field">
                <span class="ap-k">Language</span>
                <div class="ap-v">English</div>
            </div>
            <div class="ap-field">
                <span class="ap-k">Time zone</span>
                <div class="ap-v">Philippine Standard Time (UTC+08:00)</div>
            </div>
            <div class="ap-field" style="margin-bottom:0;">
                <span class="ap-k">Email notifications</span>
                <div class="ap-v">
                    <?= pc_setting_bool($con, 'email_enable') ? 'On for the whole site' : 'Off for the whole site' ?>
                    — <a href="<?= url('admin-settings') ?>" style="color:var(--mint);">change in System Settings</a>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="ap-card ap-shot">
            <div class="ap-h" style="justify-content:flex-start;">
                <span class="ap-h-t">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 8h3l1.5-2h7L17 8h3v11H4V8Z" />
                        <circle cx="12" cy="13" r="3.2" />
                    </svg>
                    Profile picture
                </span>
            </div>

            <form id="apPhoto" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <span class="ap-av-wrap">
                    <span class="ap-av" id="apAvatar2"><?= pc_avatar($photo, $name) ?></span>
                    <span class="ap-cam">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 8h3l1.5-2h7L17 8h3v11H4V8Z" />
                            <circle cx="12" cy="13" r="3.2" />
                        </svg>
                    </span>
                </span>
                <div class="ap-shot-t">Upload a new profile picture</div>
                <div class="ap-shot-d">JPG, PNG, GIF or WEBP — up to 5 MB</div>

                <input class="ap-file" type="file" id="apFile" name="profile_image"
                       accept="image/jpeg,image/png,image/gif,image/webp">
                <button class="ap-btn primary" type="button" id="apChoose">Choose file</button>
                <div class="ap-msg" id="apPhotoMsg" role="status"></div>
            </form>
        </div>

        <div class="ap-card">
            <div class="ap-h">
                <span class="ap-h-t">
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <rect x="4.5" y="10" width="15" height="10" rx="2.5" />
                        <path stroke-linecap="round" d="M8.5 10V7.5a3.5 3.5 0 0 1 7 0V10" />
                    </svg>
                    Change password
                </span>
            </div>

            <form id="apPw">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <?php foreach ([
                    ['current_password', 'Current password', 'current-password'],
                    ['new_password',     'New password',     'new-password'],
                    ['confirm_password', 'Confirm new password', 'new-password'],
                ] as [$field, $label, $auto]): ?>
                    <div class="ap-field">
                        <span class="ap-k"><?= htmlspecialchars($label) ?></span>
                        <span class="ap-pw">
                            <input class="ap-input" type="password" name="<?= $field ?>" required autocomplete="<?= $auto ?>">
                            <button class="ap-eye" type="button" aria-label="Show password">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </button>
                        </span>
                    </div>
                <?php endforeach; ?>

                <button class="ap-btn primary wide" type="submit">Update password</button>
                <div class="ap-msg" id="apPwMsg" role="status"></div>
            </form>
        </div>
    </div>
</div>

<div class="ap-secure">
    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5.5c0 4.2-2.9 7.6-7 8.5-4.1-.9-7-4.3-7-8.5V6l7-3Z" />
    </svg>
    <span>
        <b>Keep your account secure</b>
        <span>An administrator can see and change every member's information. Use a password you use nowhere else, and never share it.</span>
    </span>
</div>

<script>
    function apSay(el, ok, text) {
        el.className = 'ap-msg ' + (ok ? 'ok' : 'no');
        el.textContent = text;
    }

    /* ── Personal information ─────────────────────────────────────────────
       The fields are disabled until Edit is pressed, so the page reads as a
       profile rather than a form somebody is halfway through filling in. */
    (function () {
        const form   = document.getElementById('apAccount');
        const acts   = document.getElementById('apAccountActions');
        const edit   = document.getElementById('apEditBtn');
        const cancel = document.getElementById('apCancel');
        const msg    = document.getElementById('apAccountMsg');
        const inputs = form.querySelectorAll('input.ap-input');
        const was    = {};

        inputs.forEach(i => { was[i.name] = i.value; });

        function editing(on) {
            inputs.forEach(i => { i.disabled = !on; });
            acts.style.display = on ? 'flex' : 'none';
            edit.style.display = on ? 'none' : '';
            if (on) { inputs[0].focus(); }
        }

        edit.addEventListener('click', () => { msg.className = 'ap-msg'; editing(true); });
        cancel.addEventListener('click', () => {
            inputs.forEach(i => { i.value = was[i.name]; });
            msg.className = 'ap-msg';
            editing(false);
        });

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            apSay(msg, true, 'Saving…');
            try {
                /* Disabled fields are not submitted, so they are enabled for the
                   length of the request and put back afterwards. */
                inputs.forEach(i => { i.disabled = false; });
                const body = new FormData(this);
                const res  = await fetch(<?= json_encode(url('account-update-account')) ?>, { method: 'POST', body });
                const d    = await res.json();
                apSay(msg, !!d.success, d.message || (d.success ? 'Saved.' : 'That did not work.'));
                if (d.success) {
                    inputs.forEach(i => { was[i.name] = i.value; });
                    editing(false);
                } else {
                    inputs.forEach(i => { i.disabled = false; });
                }
            } catch (err) {
                apSay(msg, false, 'That did not work. Please try again.');
            }
        });
    })();

    /* ── Picture ──────────────────────────────────────────────────────── */
    (function () {
        const pick = document.getElementById('apChoose');
        const file = document.getElementById('apFile');
        const form = document.getElementById('apPhoto');
        const msg  = document.getElementById('apPhotoMsg');

        pick.addEventListener('click', () => file.click());

        /* Uploads on choosing: a separate Save would be one more press for a
           control with exactly one thing to do. */
        file.addEventListener('change', async function () {
            if (!this.files || !this.files.length) return;
            apSay(msg, true, 'Uploading…');
            try {
                const res = await fetch(<?= json_encode(url('admin-update-photo')) ?>, {
                    method: 'POST', body: new FormData(form)
                });
                const d = await res.json();
                apSay(msg, !!d.success, d.success ? 'Picture updated.' : (d.message || 'That did not work.'));
                if (d.success && d.image) {
                    const img = '<img src="' + d.image.replace(/"/g, '&quot;') + '" alt="">';
                    document.getElementById('apAvatar').innerHTML  = img;
                    document.getElementById('apAvatar2').innerHTML = img;
                }
            } catch (err) {
                apSay(msg, false, 'That did not work. Please try again.');
            }
            this.value = '';
        });
    })();

    /* ── Password ─────────────────────────────────────────────────────── */
    (function () {
        document.querySelectorAll('.ap-eye').forEach(btn => {
            btn.addEventListener('click', function () {
                const input = this.parentNode.querySelector('input');
                const show  = input.type === 'password';
                input.type  = show ? 'text' : 'password';
                this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            });
        });

        const form = document.getElementById('apPw');
        const msg  = document.getElementById('apPwMsg');

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            apSay(msg, true, 'Updating…');
            try {
                const res = await fetch(<?= json_encode(url('account-update-password')) ?>, {
                    method: 'POST', body: new FormData(this)
                });
                const d = await res.json();
                apSay(msg, !!d.success, d.message || (d.success ? 'Password updated.' : 'That did not work.'));
                if (d.success) { this.reset(); }
            } catch (err) {
                apSay(msg, false, 'That did not work. Please try again.');
            }
        });
    })();
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
