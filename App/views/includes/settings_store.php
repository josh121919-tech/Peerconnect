<?php

/**
 * settings_store.php — the platform's own settings.
 *
 * Every key here is one an admin can change AND that something in the app
 * actually reads. A settings screen full of switches that change nothing is
 * worse than no settings screen at all, so the defaults below are the
 * contract: if a key is listed, some code path consults it.
 *
 * Credentials are deliberately NOT here. SMTP passwords, OAuth secrets and
 * reCAPTCHA keys stay in .env, where they are not in the database, not in a
 * backup of it, and not editable through a web form. The settings screens
 * report whether those are configured; they never store them.
 *
 * Values are strings. The typed accessors (pc_setting_bool, pc_setting_int)
 * are what callers should use.
 */

if (!function_exists('pc_setting_defaults')) {
    function pc_setting_defaults(): array
    {
        return [
            /* ── General ── */
            'platform_name'        => 'PeerConnect',
            'platform_tagline'     => 'Mentoring. Growing. Together.',
            'platform_description' => '',
            'support_email'        => '',
            'site_url'             => '',

            'allow_registration'   => '1',   // auth/signup.php
            'require_verification' => '1',   // shown on the member dashboards
            'auto_approve_mentors' => '0',   // mentorpage/verification.php
            'default_role'         => 'mentee',
            'booking_limit_week'   => '0',   // 0 = no limit; save_booking.php
            'maintenance_mode'     => '0',   // helpers.php gate
            'maintenance_message'  => 'PeerConnect is briefly down for maintenance. Please try again shortly.',

            /* ── Security ── */
            'login_lockout_enable' => '1',   // auth/login.php + admin/login.php
            'login_max_attempts'   => '5',
            'login_lockout_mins'   => '5',
            // No CAPTCHA switch: it is always on (App/services/CaptchaService.php).
            'password_min_length'  => '8',   // PasswordPolicy: sign-up, admin sign-up, reset, change password
            'notify_new_login'     => '0',

            /* ── Email & notifications ── */
            // Where the alerts that need a person go: a new administrator
            // waiting for review, and a change to the site's identity. One
            // address, not every administrator — an alert that goes to
            // everybody is an alert nobody owns. A setting rather than a
            // constant so it can be handed over without a deploy.
            'owner_email'          => 'Peerconnect.neust@gmail.com',
            'email_enable'         => '1',   // NotificationService dispatch
            'email_on_registration' => '1',
            'email_on_booking'     => '1',
            'email_on_reminder'    => '1',
            'email_on_message'     => '1',
            'email_on_assessment'  => '1',
            'email_on_announcement' => '1',

            /* ── Appearance ── */
            'brand_primary'        => '#071B4D',
            'brand_accent'         => '#087FC1',
            'brand_logo'           => '',
            'brand_favicon'        => '',
        ];
    }
}

if (!function_exists('pc_settings')) {
    /**
     * Every setting, defaults merged with whatever is stored.
     *
     * Read once per request and cached: the shell asks for a couple of these
     * on every page, and a settings table lookup per question would be a
     * query per widget.
     */
    function pc_settings(mysqli $con, bool $fresh = false): array
    {
        static $cache = null;
        if ($cache !== null && !$fresh) return $cache;

        $out = pc_setting_defaults();
        $r = @$con->query("SELECT setting_key, setting_value FROM settings");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                // Only keys the app knows about — a stale row from an older
                // version should not appear as a setting nothing reads.
                if (array_key_exists($row['setting_key'], $out)) {
                    $out[$row['setting_key']] = (string)$row['setting_value'];
                }
            }
        }
        $cache = $out;
        return $out;
    }
}

if (!function_exists('pc_setting')) {
    function pc_setting(mysqli $con, string $key, ?string $fallback = null): string
    {
        $all = pc_settings($con);
        return $all[$key] ?? ($fallback ?? '');
    }
}

if (!function_exists('pc_setting_bool')) {
    function pc_setting_bool(mysqli $con, string $key): bool
    {
        return pc_setting($con, $key) === '1';
    }
}

if (!function_exists('pc_setting_int')) {
    function pc_setting_int(mysqli $con, string $key, int $fallback = 0): int
    {
        $v = pc_setting($con, $key);
        return $v === '' ? $fallback : (int)$v;
    }
}

if (!function_exists('pc_setting_save')) {
    /**
     * Write a batch of settings. Unknown keys are ignored rather than stored,
     * so a crafted form cannot add rows the app will never read.
     */
    function pc_setting_save(mysqli $con, array $pairs, ?int $by = null): int
    {
        $known = pc_setting_defaults();
        $st = $con->prepare("
            INSERT INTO settings (setting_key, setting_value, updated_by, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                    updated_by = VALUES(updated_by),
                                    updated_at = NOW()
        ");
        if (!$st) return 0;

        $n = 0;
        foreach ($pairs as $k => $v) {
            if (!array_key_exists($k, $known)) continue;
            $v = (string)$v;
            $st->bind_param('ssi', $k, $v, $by);
            $st->execute();
            $n++;
        }
        $st->close();
        pc_settings($con, true);   // the cache is now stale
        return $n;
    }
}

if (!function_exists('pc_maintenance_gate')) {
    /**
     * Maintenance mode, enforced.
     *
     * Admins keep working — otherwise turning it on would lock the person who
     * turned it on out of the switch. Everyone else gets a plain page saying
     * what is happening. The auth pages stay reachable so an admin can still
     * sign in to turn it off.
     */
    function pc_maintenance_gate(mysqli $con): void
    {
        /*
         * Web requests only. A CLI script — the missed-session detector, a
         * migration, anything run from a shell — has no browser to show a
         * maintenance page to, and printing one into its output corrupts
         * whatever it was doing.
         */
        if (PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_URI'])) return;

        if (!pc_setting_bool($con, 'maintenance_mode')) return;
        if (($_SESSION['role'] ?? '') === 'admin') return;

        /*
         * What stays open while the site is down, and nothing else.
         *
         * The landing page, the member sign-in and the Google callback used to
         * be open too, so anyone with the address could read the site and sign
         * in during maintenance — which is the opposite of what turning it on
         * is for. An administrator still needs a way back in, so their own
         * sign-in stays, and so does logout: leaving someone unable to end
         * their session would be its own trap.
         */
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        foreach (['admin-login', 'logout'] as $open) {
            if ($path === url($open)) return;
        }

        $msg  = pc_setting($con, 'maintenance_message');
        $name = pc_setting($con, 'platform_name');

        http_response_code(503);
        header('Retry-After: 3600');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>' . htmlspecialchars($name) . ' — maintenance</title>'
           . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#F5F5F5;'
           . 'font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#3D424D;padding:24px}'
           . '.c{max-width:440px;text-align:center;background:#fff;border:1px solid #EDEDED;border-radius:16px;padding:36px 32px;'
           . 'box-shadow:0 12px 32px -18px rgba(16,24,40,.3)}h1{margin:0 0 10px;font-size:21px;color:#071B4D}'
           . 'p{margin:0;font-size:14px;line-height:1.6;color:#565B66}a{display:inline-block;margin-top:18px;'
           . 'font-size:13px;font-weight:600;color:#087FC1;text-decoration:none}</style></head><body><div class="c">'
           . '<h1>' . htmlspecialchars($name) . ' is under maintenance</h1>'
           . '<p>' . nl2br(htmlspecialchars($msg)) . '</p>'
           // No sign-in link: the member one now shows this same page, and the
           // administrator one does not belong on a page anybody can reach.
           . '</div></body></html>';
        exit;
    }
}
