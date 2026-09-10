<?php

/**
 * toasts.php — the one place a message to the user is shown.
 *
 * Two things feed it:
 *   • pc_flash() from a PHP handler, queued in the session before a redirect
 *     and rendered here on the next page load;
 *   • pcToast() from JavaScript, for anything that answers over fetch and so
 *     never reloads the page.
 *
 * Both end up as the same object in the same corner, so an action feels the
 * same whether the code behind it redirects or not. Included by the member
 * shell (design_system.php) and by the admin layout, which is every page in
 * the app that a signed-in person can reach.
 *
 * The container sits bottom-right and is `pointer-events: none` so it can
 * never swallow a click meant for the page; each toast turns pointer events
 * back on for its own dismiss button.
 */

if (defined('PC_TOASTS_RENDERED')) {
    return;
}
define('PC_TOASTS_RENDERED', true);

$pc_flashes = function_exists('pc_flash_take') ? pc_flash_take() : [];
?>

<style>
    #pc-toast-container {
        position: fixed;
        right: 20px;
        bottom: 20px;
        left: auto;
        transform: none;
        z-index: 9000;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 10px;
        width: min(380px, calc(100vw - 32px));
        pointer-events: none;
    }

    .pc-toast {
        display: flex;
        align-items: flex-start;
        gap: 11px;
        width: 100%;
        padding: 13px 14px;
        border: 1px solid var(--gray-200, #E5E7EB);
        border-left: 3px solid var(--pc-toast-accent, #4B5563);
        border-radius: 12px;
        background: #fff;
        color: var(--gray-700, #374151);
        font-size: 13.5px;
        font-weight: 400;
        line-height: 1.5;
        text-align: left;
        box-shadow: 0 12px 32px -10px rgba(16, 24, 40, .28);
        pointer-events: auto;
        animation: pcToastIn .26s cubic-bezier(.2, .7, .3, 1);
    }

    .pc-toast .pc-toast-ico {
        flex: none;
        width: 18px;
        height: 18px;
        margin-top: 1px;
        color: var(--pc-toast-accent, #4B5563);
    }

    .pc-toast .pc-toast-body {
        flex: 1;
        min-width: 0;
    }

    .pc-toast b {
        display: block;
        margin-bottom: 2px;
        font-size: 13.5px;
        font-weight: 700;
        color: var(--gray-900, #111827);
    }

    .pc-toast .pc-toast-x {
        flex: none;
        width: 22px;
        height: 22px;
        margin: -2px -3px 0 0;
        display: grid;
        place-items: center;
        border: 0;
        border-radius: 6px;
        background: none;
        color: var(--gray-400, #9CA3AF);
        font-size: 15px;
        line-height: 1;
        cursor: pointer;
    }

    .pc-toast .pc-toast-x:hover {
        background: var(--gray-100, #F3F4F6);
        color: var(--gray-700, #374151);
    }

    .pc-toast.success { --pc-toast-accent: #17654B; }
    .pc-toast.error   { --pc-toast-accent: #C0392B; }
    .pc-toast.warning { --pc-toast-accent: #B7791F; }
    .pc-toast.info,
    .pc-toast.default { --pc-toast-accent: #1B6FD1; }

    .pc-toast.fade-out {
        opacity: 0;
        transform: translateX(12px);
        transition: opacity .28s ease, transform .28s ease;
    }

    @keyframes pcToastIn {
        from { opacity: 0; transform: translateX(18px) scale(.98); }
        to   { opacity: 1; transform: none; }
    }

    /* On a phone the bottom bar owns the bottom of the screen, so sit above
       it and use the full width rather than a floating card in the corner. */
    @media (max-width: 640px) {
        #pc-toast-container {
            right: 12px;
            left: 12px;
            bottom: 84px;
            width: auto;
            align-items: stretch;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .pc-toast { animation: none; }
        .pc-toast.fade-out { transition: none; }
    }
</style>

<div id="pc-toast-container" aria-live="polite" aria-atomic="false"></div>

<script>
    (function () {
        var ICONS = {
            success: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.5l2.2 2.2L15.5 10M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
            error:   '<path stroke-linecap="round" stroke-linejoin="round" d="M12 8.5v4m0 3.2h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
            warning: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9.5v3.2m0 3h.01M10.3 4.2 2.9 17a2 2 0 0 0 1.7 3h14.8a2 2 0 0 0 1.7-3L13.7 4.2a2 2 0 0 0-3.4 0Z"/>',
            info:    '<path stroke-linecap="round" stroke-linejoin="round" d="M12 11v5m0-8.2h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'
        };

        /**
         * pcToast(message, type, duration, title)
         *
         * Kept compatible with the calls already in the codebase, which pass
         * (msg, 'error', 4000) and expect the message to simply appear.
         */
        window.pcToast = function (msg, type, duration, title) {
            type = (type && ICONS[type]) ? type : (type === 'default' ? 'info' : (type || 'info'));
            if (!ICONS[type]) type = 'info';
            duration = duration || 4000;

            var container = document.getElementById('pc-toast-container');
            if (!container) return;

            /*
             * Pressing a button that keeps failing for the same reason should
             * restate the reason, not build a column of identical copies up
             * the screen. An identical message already showing is nudged
             * instead: its timer restarts and it pulses, so it is clear the
             * second attempt was heard.
             */
            var already = container.querySelector('.pc-toast[data-key="' + cssEscape(type + '|' + msg) + '"]');
            if (already && !already.dataset.going) {
                clearTimeout(+already.dataset.timer);
                already.dataset.timer = setTimeout(function () { dismiss(already); }, duration);
                already.style.animation = 'none';
                void already.offsetWidth;
                already.style.animation = '';
                return already;
            }

            var toast = document.createElement('div');
            toast.dataset.key = type + '|' + msg;
            toast.className = 'pc-toast ' + type;
            toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

            var svg = '<svg class="pc-toast-ico" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">' + ICONS[type] + '</svg>';
            var body = document.createElement('div');
            body.className = 'pc-toast-body';
            if (title) {
                var b = document.createElement('b');
                b.textContent = title;
                body.appendChild(b);
            }
            // textContent, never innerHTML: a message can carry a reason typed
            // by an admin or a name typed by a member.
            body.appendChild(document.createTextNode(msg));

            toast.insertAdjacentHTML('afterbegin', svg);
            toast.appendChild(body);

            var x = document.createElement('button');
            x.type = 'button';
            x.className = 'pc-toast-x';
            x.setAttribute('aria-label', 'Dismiss');
            x.innerHTML = '&times;';
            x.addEventListener('click', function () { dismiss(toast); });
            toast.appendChild(x);

            container.appendChild(toast);

            toast.dataset.timer = setTimeout(function () { dismiss(toast); }, duration);
            // Reading a message should not race a timer.
            toast.addEventListener('mouseenter', function () { clearTimeout(+toast.dataset.timer); });
            toast.addEventListener('mouseleave', function () {
                toast.dataset.timer = setTimeout(function () { dismiss(toast); }, 1600);
            });

            return toast;
        };

        // A message is arbitrary text, so it cannot go into a selector raw.
        function cssEscape(v) {
            if (window.CSS && CSS.escape) return CSS.escape(v);
            return String(v).replace(/["\\\]]/g, '\\$&');
        }

        function dismiss(toast) {
            if (!toast || toast.dataset.going) return;
            toast.dataset.going = '1';
            toast.classList.add('fade-out');
            setTimeout(function () { toast.remove(); }, 300);
        }

        // Messages queued server-side by pc_flash(), shown once on arrival.
        var queued = <?= json_encode(array_values($pc_flashes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        if (queued.length) {
            queued.forEach(function (f, i) {
                setTimeout(function () {
                    pcToast(f.message, f.type, f.type === 'error' ? 6000 : 4000, f.title || '');
                }, i * 140);
            });
        }
    })();
</script>
