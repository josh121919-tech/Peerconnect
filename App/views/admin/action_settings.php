<?php

/**
 * action_settings.php — saves every System Settings form.
 *
 * One handler, keyed by a `section` field, because the alternative is seven
 * near-identical files. Each section validates only its own keys, and
 * pc_setting_save() ignores anything not in the known list, so a crafted form
 * cannot write a setting the app will never read.
 *
 * SECURITY: admin-only, POST-only, CSRF-checked, prepared statements.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/settings_store.php';
require_once __DIR__ . '/../../services/EmailService.php';

require_admin();
require_post();

if (!verify_csrf()) {
    http_response_code(403);
    exit('CSRF token mismatch.');
}

date_default_timezone_set('Asia/Manila');

$me      = (int)($_SESSION['user_id'] ?? 0);
$section = $_POST['section'] ?? '';

/** Where each section lives, so a save returns to the page it came from. */
$home = [
    'platform'      => 'admin-settings',
    'behaviour'     => 'admin-settings',
    'maintenance'   => 'admin-settings',
    'security'      => 'admin-settings-security',
    'revoke_token'  => 'admin-settings-security',
    'email'         => 'admin-settings-email',
    'test_email'    => 'admin-settings-email',
    'brand_colors'  => 'admin-settings-appearance',
    'brand_logo'    => 'admin-settings-appearance',
    'brand_favicon' => 'admin-settings-appearance',
][$section] ?? 'admin-settings';
$back = url($home);

/** One image upload, checked by what the file is rather than what it is called. */
function ast_upload(array $f, int $maxBytes, array $allow, string $prefix): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK)  return [null, 'That file could not be uploaded.'];
    if ($f['size'] > $maxBytes)                return [null, 'That file is larger than ' . round($maxBytes / 1048576, 1) . ' MB.'];

    $ext = null;
    $info = @getimagesize($f['tmp_name']);
    if ($info !== false) {
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif',
                IMAGETYPE_WEBP => 'webp', IMAGETYPE_ICO => 'ico'][$info[2]] ?? null;
    } else {
        // SVG is not a bitmap, so getimagesize cannot see it. Accept it only
        // when the file really parses as XML with an <svg> root, and only when
        // the caller asked for it.
        $head = (string)@file_get_contents($f['tmp_name'], false, null, 0, 4096);
        if (in_array('svg', $allow, true) && stripos($head, '<svg') !== false && stripos($head, '<?xml') !== false) {
            // A script inside an SVG runs when the file is opened directly.
            if (preg_match('/<script|javascript:|onload\s*=/i', (string)@file_get_contents($f['tmp_name']))) {
                return [null, 'That SVG contains a script, so it was not accepted.'];
            }
            $ext = 'svg';
        }
    }

    if ($ext === null || !in_array($ext, $allow, true)) {
        return [null, 'That file type is not accepted here (' . implode(', ', $allow) . ').'];
    }

    $dir = PUBLIC_PATH . '/uploads/brand/';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $name = $prefix . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) {
        return [null, 'The file could not be saved. Please try again.'];
    }
    return [asset('uploads/brand/' . $name), null];
}

/**
 * What a save changed, in words, for the activity log — e.g.
 * ["CAPTCHA off", "sign-in attempts 5 → 3"]. Unchanged values are left out,
 * so re-saving a form without touching it records nothing. Free text is named
 * but not copied: a description or message can be long, and the setting row
 * keeps the value.
 */
function ast_changes(array $before, array $pairs): array
{
    $labels = [
        'platform_name'         => ['platform name', 'text'],
        'platform_tagline'      => ['tagline', 'text'],
        'platform_description'  => ['description', 'text'],
        'site_url'              => ['site URL', 'text'],
        'support_email'         => ['support email', 'text'],
        'allow_registration'    => ['registration', 'switch'],
        'auto_approve_mentors'  => ['auto-approve mentors', 'switch'],
        'require_verification'  => ['require verification', 'switch'],
        'default_role'          => ['default role', 'value'],
        'booking_limit_week'    => ['weekly booking limit', 'value'],
        'maintenance_mode'      => ['maintenance mode', 'switch'],
        'maintenance_message'   => ['maintenance message', 'text'],
        'login_lockout_enable'  => ['sign-in lockout', 'switch'],
        'login_max_attempts'    => ['sign-in attempts', 'value'],
        'login_lockout_mins'    => ['lockout minutes', 'value'],
        'captcha_enable'        => ['CAPTCHA', 'switch'],
        'password_min_length'   => ['minimum password length', 'value'],
        'email_enable'          => ['email', 'switch'],
        'email_on_registration' => ['registration emails', 'switch'],
        'email_on_booking'      => ['booking emails', 'switch'],
        'email_on_reminder'     => ['reminder emails', 'switch'],
        'email_on_message'      => ['message emails', 'switch'],
        'email_on_assessment'   => ['assessment emails', 'switch'],
        'email_on_announcement' => ['announcement emails', 'switch'],
        'brand_primary'         => ['primary colour', 'value'],
        'brand_accent'          => ['accent colour', 'value'],
        'brand_logo'            => ['logo', 'file'],
        'brand_favicon'         => ['favicon', 'file'],
    ];

    $out = [];
    foreach ($pairs as $key => $new) {
        $old = (string)($before[$key] ?? '');
        $new = (string)$new;
        if ($old === $new || !isset($labels[$key])) continue;
        [$label, $kind] = $labels[$key];
        switch ($kind) {
            case 'switch': $out[] = $label . ($new === '1' ? ' on' : ' off'); break;
            case 'value':  $out[] = $label . ' ' . ($old === '' ? '(unset)' : $old) . ' → ' . $new; break;
            case 'file':   $out[] = $label . ($new === '' ? ' removed' : ($old === '' ? ' added' : ' replaced')); break;
            default:       $out[] = $label;
        }
    }
    return $out;
}

/** Log a settings save, if it changed anything. */
function ast_log(array $before, array $pairs, string $what): void
{
    $changes = ast_changes($before, $pairs);
    if ($changes) {
        pc_admin_log('changed ' . $what . ': ' . implode(', ', $changes));
    }
}

// Read before any save below overwrites it, so each log entry can say what changed.
$before = pc_settings($con);

switch ($section) {

    /* ── General ─────────────────────────────────────────────────────── */
    case 'platform': {
        $name = trim((string)($_POST['platform_name'] ?? ''));
        if ($name === '') {
            pc_flash('error', 'The platform needs a name.');
            break;
        }
        $url = trim((string)($_POST['site_url'] ?? ''));
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            pc_flash('error', 'That site URL does not look like a web address.');
            break;
        }
        $mail = trim((string)($_POST['support_email'] ?? ''));
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            pc_flash('error', 'That support email does not look right.');
            break;
        }
        $pairs = [
            'platform_name'        => mb_substr($name, 0, 60),
            'platform_tagline'     => mb_substr(trim((string)($_POST['platform_tagline'] ?? '')), 0, 120),
            'platform_description' => mb_substr(trim((string)($_POST['platform_description'] ?? '')), 0, 500),
            'site_url'             => $url,
            'support_email'        => $mail,
        ];
        pc_setting_save($con, $pairs, $me);
        ast_log($before, $pairs, 'platform details');
        pc_flash('success', 'Platform details saved.', 'Settings updated');
        break;
    }

    case 'behaviour': {
        $role = in_array($_POST['default_role'] ?? '', ['mentee', 'mentor'], true) ? $_POST['default_role'] : 'mentee';
        $cap  = max(0, min(50, (int)($_POST['booking_limit_week'] ?? 0)));
        $open = ($_POST['allow_registration'] ?? '0') === '1';

        $pairs = [
            'allow_registration'   => $open ? '1' : '0',
            'auto_approve_mentors' => ($_POST['auto_approve_mentors'] ?? '0') === '1' ? '1' : '0',
            'require_verification' => ($_POST['require_verification'] ?? '0') === '1' ? '1' : '0',
            'default_role'         => $role,
            'booking_limit_week'   => (string)$cap,
        ];
        pc_setting_save($con, $pairs, $me);
        ast_log($before, $pairs, 'platform preferences');

        pc_flash('success',
            $open ? 'Preferences saved.' : 'Preferences saved — new registrations are now closed.',
            'Settings updated');
        break;
    }

    case 'maintenance': {
        $on = ($_POST['maintenance_mode'] ?? '0') === '1';
        $pairs = [
            'maintenance_mode'    => $on ? '1' : '0',
            'maintenance_message' => mb_substr(trim((string)($_POST['maintenance_message'] ?? '')), 0, 300),
        ];
        pc_setting_save($con, $pairs, $me);
        ast_log($before, $pairs, 'maintenance settings');
        pc_flash($on ? 'warning' : 'success',
            $on
                ? 'Members now see the maintenance page. Admins are unaffected, and the sign-in pages stay open.'
                : 'Maintenance mode is off. The platform is open again.',
            $on ? 'Maintenance on' : 'Back online');
        break;
    }

    /* ── Security ────────────────────────────────────────────────────── */
    case 'security': {
        $tries = max(1, min(20, (int)($_POST['login_max_attempts'] ?? 5)));
        $mins  = max(1, min(1440, (int)($_POST['login_lockout_mins'] ?? 5)));
        $pwLen = max(6, min(20, (int)($_POST['password_min_length'] ?? 8)));
        $captcha = ($_POST['captcha_enable'] ?? '0') === '1';

        $pairs = [
            'login_lockout_enable' => ($_POST['login_lockout_enable'] ?? '0') === '1' ? '1' : '0',
            'login_max_attempts'   => (string)$tries,
            'login_lockout_mins'   => (string)$mins,
            'captcha_enable'       => $captcha ? '1' : '0',
            'password_min_length'  => (string)$pwLen,
        ];
        pc_setting_save($con, $pairs, $me);
        ast_log($before, $pairs, 'security settings');

        if (!$captcha) {
            pc_flash('warning', 'Saved, but CAPTCHA is now off — the sign-up form has nothing stopping automated accounts.', 'Security updated');
        } else {
            pc_flash('success', 'Security settings saved.', 'Settings updated');
        }
        break;
    }

    case 'revoke_token': {
        $tid = (int)($_POST['token_id'] ?? 0);
        if (!$tid) { pc_flash('error', 'No device was given.'); break; }
        // Whose device, read before the row is gone, for the activity log.
        $own = $con->prepare("SELECT user_id FROM remember_tokens WHERE token_id = ?");
        $own->bind_param('i', $tid);
        $own->execute();
        $owner = $own->get_result()->fetch_row();
        $own->close();
        $del = $con->prepare("DELETE FROM remember_tokens WHERE token_id = ?");
        $del->bind_param('i', $tid);
        $del->execute();
        $gone = $del->affected_rows > 0;
        $del->close();
        if ($gone && $owner) {
            pc_admin_log('revoked a remembered device of ' . pc_user_name($con, (int)$owner[0]));
        }
        pc_flash($gone ? 'success' : 'warning',
            $gone ? 'That device has to sign in with a password again.' : 'That device was already revoked.',
            $gone ? 'Device revoked' : '');
        break;
    }

    /* ── Email ───────────────────────────────────────────────────────── */
    case 'email': {
        $pairs = ['email_enable' => ($_POST['email_enable'] ?? '0') === '1' ? '1' : '0'];
        foreach (['email_on_registration', 'email_on_booking', 'email_on_reminder',
                  'email_on_message', 'email_on_assessment', 'email_on_announcement'] as $k) {
            $pairs[$k] = ($_POST[$k] ?? '0') === '1' ? '1' : '0';
        }
        pc_setting_save($con, $pairs, $me);
        ast_log($before, $pairs, 'email settings');
        pc_flash('success',
            $pairs['email_enable'] === '1'
                ? 'Email settings saved. In-app notifications are unaffected.'
                : 'Email is off. Members still get notifications inside the app.',
            'Settings updated');
        break;
    }

    case 'test_email': {
        $to = trim((string)($_POST['to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            pc_flash('error', 'That does not look like an email address.');
            break;
        }
        if (!EmailService::isConfigured()) {
            pc_flash('error', 'SMTP is not configured in .env, so nothing can be sent.');
            break;
        }
        // Rate limited: this reaches a real mail server and a real inbox.
        if (!rate_limit('settings_test_email', 5, 600)) {
            pc_flash('error', 'That is a lot of test emails. Try again in a few minutes.');
            break;
        }

        $html = EmailService::notificationTemplate(
            'Test email from ' . pc_setting($con, 'platform_name'),
            'If you are reading this, your SMTP settings work. Sent from System Settings → Email at '
                . date('M j, Y \a\t g:i A') . '.',
            APP_URL, 'Open PeerConnect'
        );
        $r = EmailService::send($to, 'PeerConnect test email', $html);
        if ($r['success']) {
            pc_admin_log('sent a test email to ' . $to);
            pc_flash('success', 'Sent to ' . $to . '. If it does not arrive, check the spam folder.', 'Test email sent');
        } else {
            pc_flash('error', 'It did not send: ' . ($r['error'] ?? 'unknown error'));
        }
        break;
    }

    /* ── Appearance ──────────────────────────────────────────────────── */
    case 'brand_colors': {
        if (!empty($_POST['reset'])) {
            $d = pc_setting_defaults();
            $pairs = ['brand_primary' => $d['brand_primary'], 'brand_accent' => $d['brand_accent']];
            pc_setting_save($con, $pairs, $me);
            ast_log($before, $pairs, 'brand colours back to the defaults');
            pc_flash('success', 'Brand colours are back to the PeerConnect defaults.', 'Reset');
            break;
        }
        // The text box and the swatch are two views of one value; whichever
        // the admin touched last is the one that arrived valid.
        $primary = trim((string)($_POST['brand_primary_text'] ?? $_POST['brand_primary'] ?? ''));
        $accent  = trim((string)($_POST['brand_accent_text'] ?? $_POST['brand_accent'] ?? ''));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) $primary = (string)($_POST['brand_primary'] ?? '');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent))  $accent  = (string)($_POST['brand_accent'] ?? '');

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary) || !preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            pc_flash('error', 'Colours have to be a six-digit hex value, like #0087CF.');
            break;
        }
        $pairs = ['brand_primary' => strtoupper($primary), 'brand_accent' => strtoupper($accent)];
        pc_setting_save($con, $pairs, $me);
        ast_log($before, $pairs, 'brand colours');
        pc_flash('success', 'Brand colours applied across the whole platform.', 'Appearance updated');
        break;
    }

    case 'brand_logo':
    case 'brand_favicon': {
        $isLogo = $section === 'brand_logo';
        $key    = $isLogo ? 'brand_logo' : 'brand_favicon';
        $label  = $isLogo ? 'Logo' : 'Favicon';

        if (!empty($_POST['remove'])) {
            pc_setting_save($con, [$key => ''], $me);
            ast_log($before, [$key => ''], 'appearance');
            pc_flash('success', $label . ' removed. The built-in mark is used again.', 'Appearance updated');
            break;
        }

        $field = $isLogo ? 'logo' : 'favicon';
        if (empty($_FILES[$field]['name'])) {
            pc_flash('error', 'Choose a file first.');
            break;
        }
        [$path, $err] = ast_upload(
            $_FILES[$field],
            $isLogo ? 2 * 1024 * 1024 : 1024 * 1024,
            $isLogo ? ['png', 'jpg', 'webp', 'svg'] : ['png', 'ico', 'svg', 'webp'],
            $isLogo ? 'logo' : 'favicon'
        );
        if ($err !== null) {
            pc_flash('error', $err);
            break;
        }
        pc_setting_save($con, [$key => $path], $me);
        ast_log($before, [$key => $path], 'appearance');
        pc_flash('success', $label . ' updated.', 'Appearance updated');
        break;
    }

    default:
        pc_flash('error', 'That form could not be saved.');
}

header('Location: ' . $back);
exit;
