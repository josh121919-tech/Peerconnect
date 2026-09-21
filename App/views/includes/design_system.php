<?php
if (defined('PEERCONNECT_DESIGN_SYSTEM_INCLUDED')) {
    return;
}
define('PEERCONNECT_DESIGN_SYSTEM_INCLUDED', true);

if (!function_exists('pc_logo')) {
    /** The PeerConnect mark on its own — shared by the sidebar and the landing page. */
    /**
     * The uploaded logo's path, or '' when none is set.
     *
     * Read once per request: the mark is drawn several times on a page (top
     * bar, sidebar, mobile header) and each call would otherwise re-query the
     * settings table. Falls back to '' — and so to the built-in mark — on any
     * page that has no database handle, which is the safe direction.
     */
    function pc_brand_logo_src(): string
    {
        static $src = null;
        if ($src !== null) {
            return $src;
        }
        $src = '';
        global $con;
        if (isset($con) && $con instanceof mysqli) {
            require_once __DIR__ . '/settings_store.php';
            $src = (string) (pc_settings($con)['brand_logo'] ?? '');
        }
        return $src;
    }

    function pc_brand_mark(string $class = 'brand-mark'): void
    {
        // A logo uploaded on Appearance replaces the built-in mark everywhere
        // this is drawn. Sized by CSS to the same slot height with the width
        // left free, so a wide wordmark is not squashed into a square.
        $pc_logo_src = pc_brand_logo_src();
        if ($pc_logo_src !== '') {
?>
        <img class="<?= htmlspecialchars($class) ?> is-custom" src="<?= htmlspecialchars($pc_logo_src) ?>" alt="" aria-hidden="true">
<?php
            return;
        }
?>
        <svg class="<?= htmlspecialchars($class) ?>" viewBox="0 0 48 48" fill="none" aria-hidden="true">
            <circle cx="24" cy="24" r="21" fill="currentColor" opacity=".14" />
            <circle cx="24" cy="24" r="12.5" stroke="currentColor" stroke-width="2.6" />
            <path d="M18 22.4c0-2.2 1.8-4 4-4 1.8 0 2.9.8 3.2 2 .3-1.2 1.4-2 3.2-2 2.2 0 4 1.8 4 4 0 3.6-5 6-7.2 7-2.2-1-7.2-3.4-7.2-7Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round" />
        </svg>
<?php
    }

    function pc_logo(string $href, string $label = 'PeerConnect'): void
    {
?>
        <a href="<?= htmlspecialchars($href) ?>" class="brand" aria-label="<?= htmlspecialchars($label) ?> — go to dashboard">
            <?php pc_brand_mark(); ?>
            <span><?= htmlspecialchars($label) ?></span>
        </a>
<?php
    }
}

if (!function_exists('pc_icon')) {
    function pc_icon(string $name): void
    {
        $icons = [
            'home' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.5 11.5 12 4l8.5 7.5V21a1 1 0 0 1-1 1h-5v-6h-5v6h-5a1 1 0 0 1-1-1v-9.5Z"/>',
            'partner' => '<rect x="8" y="3" width="8" height="18" rx="4"/><path stroke-linecap="round" d="M12 7v.01M12 12v.01M8 16h8"/>',
            'explore' => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="m15.5 8.5-2.2 5-4.8 2 2.2-5 4.8-2Z"/>',
            'journal' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 20 16.5 7.5l3 3L7 23H4v-3Z"/><path stroke-linecap="round" d="M13.5 10.5 16.5 13.5M5 6h5M5 10h3"/>',
            'megaphone' => '<path stroke-linecap="round" stroke-linejoin="round" d="M11 5.9 5 9H3a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h2l6 3.1V5.9ZM6 15v4a1 1 0 0 0 1 1h1.5a1 1 0 0 0 1-1v-3M16 9a4 4 0 0 1 0 6"/>',
            'feedback' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a8 8 0 0 1-8 8H8l-4 3v-4.6A8 8 0 0 1 13 4a8 8 0 0 1 8 8Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m12.6 8.3 1 2.1 2.3.3-1.7 1.6.4 2.3-2-1.1-2 1.1.4-2.3-1.7-1.6 2.3-.3 1-2.1Z"/>',
            'leaderboard' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7 4h10v5a5 5 0 0 1-10 0V4Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M7 6H4.5v1.5A3.5 3.5 0 0 0 8 11M17 6h2.5v1.5A3.5 3.5 0 0 1 16 11M12 14v4M8.5 21h7"/>',
            'messages' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H12l-5 4v-4h-.5A2.5 2.5 0 0 1 4 13.5v-7Z"/>',
            'bookings' => '<rect x="5" y="4" width="14" height="16" rx="3"/><path stroke-linecap="round" d="M8 2v4M16 2v4M5 9h14"/>',
            /* Sessions used the 'bookings' calendar too, so Sessions and
               Calendar were the same glyph in the sidebar and the menu. A
               session is a call with someone, so it gets a screen-and-play
               mark instead. */
            'sessions' => '<rect x="3" y="6" width="12.5" height="12" rx="2.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m15.5 10.5 5.5-3v9l-5.5-3"/>',
            'users' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 19c0-1.8-1.2-3.3-2.8-3.8M17 4.4a3 3 0 0 1 0 5.2"/>',
            'verify' => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.4 2.3 2.3 4.9-5.4"/>',
            'settings' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M4 12h2m12 0h2M12 4v2m0 12v2M6.3 6.3l1.4 1.4m8.6 8.6 1.4 1.4m0-11.4-1.4 1.4m-8.6 8.6-1.4 1.4"/>',
            'resources' => '<path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5M9 13h6M9 17h4"/>',
            'assessments' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1.2"/><path stroke-linecap="round" stroke-linejoin="round" d="m9 13 2 2 4-4"/>',
        ];
        echo '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">' . ($icons[$name] ?? $icons['home']) . '</svg>';
    }
}
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<?php
// The design system now ships as one cacheable file rather than an inline
// <style> block on every page. ?v= is the file's mtime, so a change to the
// stylesheet busts the cache and nothing else does.
$pc_css = asset('css/pc-app.css');
$pc_ver = @filemtime(PUBLIC_PATH . '/css/pc-app.css');
?>
<link rel="stylesheet" href="<?= htmlspecialchars($pc_css . ($pc_ver ? '?v=' . $pc_ver : '')) ?>">
<?php
// ── PWA: manifest link + service worker registration ─────────────
require_once __DIR__ . '/pwa.php';
?>
<script>
    // Global CSRF token — available to all AJAX calls as window.__PC_CSRF__
    window.__PC_CSRF__ = <?= json_encode(csrf_token()) ?>;
</script>


<?php
/*
 * Brand colours from System Settings → Appearance.
 *
 * Emitted after the design system's own :root block so it wins by cascade
 * order rather than by !important. Only written when an admin has actually
 * changed something — a default install emits nothing at all.
 *
 * --forest and --mint are the two tokens every component in this file is
 * drawn from, so overriding them here recolours the whole app.
 */
if (isset($con) && $con instanceof mysqli) {
    require_once __DIR__ . '/settings_store.php';
    $pc_brand = pc_settings($con);
    $pc_def   = pc_setting_defaults();

    $pc_primary = preg_match('/^#[0-9a-fA-F]{6}$/', $pc_brand['brand_primary'] ?? '') ? $pc_brand['brand_primary'] : $pc_def['brand_primary'];
    $pc_accent  = preg_match('/^#[0-9a-fA-F]{6}$/', $pc_brand['brand_accent'] ?? '')  ? $pc_brand['brand_accent']  : $pc_def['brand_accent'];

    if ($pc_primary !== $pc_def['brand_primary'] || $pc_accent !== $pc_def['brand_accent']) {
        echo '<style>:root{'
           . '--forest:' . $pc_primary . ';'
           . '--ink:' . $pc_primary . ';'
           . '--navy:' . $pc_primary . ';'
           . '--mint:' . $pc_accent . ';'
           . '--accent:' . $pc_accent . ';'
           . '--accent-2:' . $pc_accent . ';'
           . '--forest-2:' . $pc_accent . ';'
           . '}</style>';
    }

    // A custom favicon, if one was uploaded.
    if (($pc_brand['brand_favicon'] ?? '') !== '') {
        echo '<link rel="icon" href="' . htmlspecialchars($pc_brand['brand_favicon']) . '">';
    }
}
?>

<?php include __DIR__ . '/toasts.php'; ?>
<?php // ...and the dialog that asks before something irreversible happens.
include __DIR__ . '/dialog.php'; ?>

<script>
    (function() {
        // ── Button loading state ─────────────────────────────────────
        // Usage: const reset = pcLoadingBtn(btn, 'Saving…');  later: reset();
        window.pcLoadingBtn = function(btn, loadingText) {
            const originalHTML = btn.innerHTML;
            const originalDisabled = btn.disabled;
            const spinner = '<span class="btn-spinner" style="display:inline-block;"></span>';
            btn.innerHTML = spinner + ' ' + (loadingText || 'Loading…');
            btn.disabled = true;
            btn.classList.add('btn-loading');
            return function reset() {
                btn.innerHTML = originalHTML;
                btn.disabled = originalDisabled;
                btn.classList.remove('btn-loading');
            };
        };

        // ── Skeleton loader ──────────────────────────────────────────
        // Usage: pcShowSkeleton(container, 4);  // show 4 skeleton cards
        window.pcShowSkeleton = function(container, count, type) {
            count = count || 3;
            type = type || 'card';
            container.innerHTML = '';
            for (let i = 0; i < count; i++) {
                if (type === 'mentor') {
                    container.innerHTML += `
                    <div class="skeleton-mentor-card">
                        <div class="skeleton skeleton-mentor-photo"></div>
                        <div class="skeleton-mentor-body">
                            <div class="skeleton skeleton-title"></div>
                            <div class="skeleton skeleton-text w-75"></div>
                            <div class="skeleton skeleton-text w-50"></div>
                        </div>
                    </div>`;
                } else if (type === 'row') {
                    container.innerHTML += `
                    <div style="display:flex;align-items:center;gap:12px;padding:14px 0;border-bottom:1px solid var(--gray-100);">
                        <div class="skeleton skeleton-avatar"></div>
                        <div style="flex:1;">
                            <div class="skeleton skeleton-text w-50" style="margin-bottom:6px;"></div>
                            <div class="skeleton skeleton-text w-75"></div>
                        </div>
                    </div>`;
                } else {
                    container.innerHTML += `<div class="skeleton skeleton-card"></div>`;
                }
            }
        };

        // ── Empty state helper ───────────────────────────────────────
        // Usage: pcShowEmpty(container, 'No mentors found', 'Try broadening your search.', 'explore');
        window.pcShowEmpty = function(container, title, body, icon, actionHtml) {
            const svgPaths = {
                explore: '<circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-4-4"/>',
                calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
                messages: '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H12l-5 4v-4"/>',
                users: '<path d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/>',
                badge: '<path d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138"/>',
                feedback: '<path d="M4 6.5A2.5 2.5 0 016.5 4h11A2.5 2.5 0 0120 6.5v7A2.5 2.5 0 0117.5 16H12l-5 4v-4"/>',
                default: '<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>',
            };
            const path = svgPaths[icon] || svgPaths.default;
            container.innerHTML = `
            <div class="empty-state-lg">
                <div class="es-icon">
                    <svg viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none">
                        ${path}
                    </svg>
                </div>
                <p class="es-title">${title}</p>
                ${body ? `<p class="es-body">${body}</p>` : ''}
                ${actionHtml || ''}
            </div>`;
        };

        // ── AJAX form helper with loading + toast ────────────────────
        // Usage: pcAjaxForm(form, successMsg) → auto-manages loading state
        window.pcAjaxForm = function(form, successMsg, onSuccess) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const btn = form.querySelector('[type="submit"]');
                const reset = btn ? pcLoadingBtn(btn) : function() {};
                const fd = new FormData(form);

                fetch(form.action || window.location.href, {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(data => {
                        reset();
                        if (data.success || data.ok) {
                            pcToast(successMsg || data.message || 'Saved!', 'success');
                            if (onSuccess) onSuccess(data);
                        } else {
                            pcToast(data.message || data.error || 'Something went wrong.', 'error');
                        }
                    })
                    .catch(() => {
                        reset();
                        pcToast('Network error. Please try again.', 'error');
                    });
            });
        };

        // ── Escape-key closes any open modal ─────────────────────────
        // Every modal across the app uses the same .modal-overlay + .open
        // toggle convention (via openModal(id)/closeModal(id)), but Escape
        // support was implemented ad hoc per-page and inconsistent. This
        // covers all of them from one place instead of duplicating it.
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            document.querySelectorAll('.modal-overlay.open').forEach(function(overlay) {
                overlay.classList.remove('open');
            });
        });
    })();
</script>