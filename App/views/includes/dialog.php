<?php

/**
 * dialog.php — the one place the app asks "are you sure?".
 *
 * Replaces window.confirm() and window.alert(), which render as an OS box
 * titled "localhost says": unstyled, unbranded, and unable to say whether the
 * button you are about to press deletes something or saves it.
 *
 * Two ways in:
 *
 *   • Declarative, for a form that posts to a handler:
 *
 *         <form method="post" action="..."
 *               data-pc-confirm="Delete this slot?"
 *               data-pc-tone="danger"
 *               data-pc-ok="Delete slot">
 *
 *     Nothing else changes. The submit is held, the dialog opens, and on OK
 *     the form is submitted again through requestSubmit() so that HTML5
 *     validation still runs and the pressed button's name/value still reach
 *     the server — both of which a bare form.submit() would throw away.
 *
 *   • From JavaScript, for anything that answers over fetch:
 *
 *         if (!await pcConfirm({ title: 'Remove this request?',
 *                                tone: 'danger', ok: 'Remove' })) return;
 *         await pcAlert('Time is up.', 'Your answers will be submitted.');
 *
 * The difference that matters against the native box: confirm() blocks the
 * thread and returns a boolean, this returns a Promise. Every call site has
 * to await it rather than branch on it inline.
 *
 * Included next to toasts.php by the member shell (design_system.php) and by
 * the admin layout, which between them is every signed-in page in the app.
 *
 * A passing notice — "Network error, try again" — is not this. That is
 * pcToast(), which does not interrupt.
 */

if (defined('PC_DIALOG_RENDERED')) {
    return;
}
define('PC_DIALOG_RENDERED', true);
?>

<style>
    .pc-dlg-back {
        position: fixed;
        inset: 0;
        z-index: 9200;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(2, 5, 71, .42);
    }

    .pc-dlg-back.open {
        display: flex;
    }

    .pc-dlg {
        width: min(440px, 100%);
        max-height: calc(100vh - 40px);
        overflow-y: auto;
        background: #fff;
        border-radius: 16px;
        padding: 24px;
        text-align: left;
        box-shadow: 0 24px 60px -20px rgba(16, 24, 40, .4);
        animation: pcDlgIn .18s cubic-bezier(.2, .7, .3, 1);
    }

    @keyframes pcDlgIn {
        from { opacity: 0; transform: translateY(8px) scale(.98); }
        to   { opacity: 1; transform: none; }
    }

    .pc-dlg-ico {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: grid;
        place-items: center;
        margin-bottom: 14px;
        background: var(--pc-dlg-soft);
        color: var(--pc-dlg-tone);
    }

    .pc-dlg-ico svg {
        width: 22px;
        height: 22px;
    }

    .pc-dlg h3 {
        margin: 0 0 6px;
        font-family: inherit;
        font-size: 17.5px;
        font-weight: 700;
        line-height: 1.35;
        color: var(--forest, #071B4D);
    }

    .pc-dlg p {
        margin: 0;
        font-size: 13.5px;
        line-height: 1.6;
        color: var(--gray-500, #717680);
        /* Several of the messages this replaces carried their own line
           breaks; keep them rather than running the text together. */
        white-space: pre-line;
    }

    .pc-dlg-foot {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        flex-wrap: wrap;
        margin-top: 22px;
    }

    .pc-dlg-btn {
        padding: 10px 18px;
        border-radius: 10px;
        border: 1px solid transparent;
        font-family: inherit;
        font-size: 13.5px;
        font-weight: 600;
        line-height: 1.2;
        cursor: pointer;
    }

    .pc-dlg-cancel {
        border-color: var(--gray-200, #DADCDF);
        background: #fff;
        color: var(--gray-600, #565B66);
    }

    .pc-dlg-cancel:hover {
        background: var(--gray-50, #F5F5F5);
    }

    .pc-dlg-go {
        background: var(--pc-dlg-tone);
        color: #fff;
    }

    .pc-dlg-go:hover {
        filter: brightness(.92);
    }

    .pc-dlg-btn:focus-visible {
        outline: 2px solid var(--mint, #087FC1);
        outline-offset: 2px;
    }

    /* The tone colours the icon badge and the action button together, so the
       button that deletes never looks like the button that saves. */
    .pc-dlg-back[data-tone="danger"]  { --pc-dlg-tone: #A6301F; --pc-dlg-soft: #FBE5E1; }
    .pc-dlg-back[data-tone="success"] { --pc-dlg-tone: #17654B; --pc-dlg-soft: #E6F5EE; }
    .pc-dlg-back[data-tone="warning"] { --pc-dlg-tone: #9A7100; --pc-dlg-soft: #FBF0D4; }
    .pc-dlg-back[data-tone="primary"] { --pc-dlg-tone: var(--forest, #071B4D); --pc-dlg-soft: var(--mint-faint, #EAF6FC); }

    @media (prefers-reduced-motion: reduce) {
        .pc-dlg { animation: none; }
    }

    @media (max-width: 480px) {
        .pc-dlg-foot { flex-direction: column-reverse; }
        .pc-dlg-btn { width: 100%; }
    }
</style>

<div class="pc-dlg-back" id="pc-dlg-back" data-tone="danger" role="dialog" aria-modal="true" aria-labelledby="pc-dlg-title" hidden>
    <div class="pc-dlg" role="document">
        <div class="pc-dlg-ico" id="pc-dlg-ico" aria-hidden="true"></div>
        <h3 id="pc-dlg-title"></h3>
        <p id="pc-dlg-body"></p>
        <div class="pc-dlg-foot">
            <button type="button" class="pc-dlg-btn pc-dlg-cancel" id="pc-dlg-cancel">Cancel</button>
            <button type="button" class="pc-dlg-btn pc-dlg-go" id="pc-dlg-go">Confirm</button>
        </div>
    </div>
</div>

<script>
    (function () {
        var ICONS = {
            danger:  '<path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.2V5a1.8 1.8 0 0 0-1.8-1.8h-1.8A1.8 1.8 0 0 0 9.3 5v1.2M4.5 6.2h15M17.8 6.2 17.2 18a1.8 1.8 0 0 1-1.8 1.7H8.6A1.8 1.8 0 0 1 6.8 18L6.2 6.2M10.2 10v5.6M13.8 10v5.6"/>',
            success: '<path stroke-linecap="round" stroke-linejoin="round" d="M8.6 12.3l2.4 2.4 4.6-4.9M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
            warning: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9.5v3.4m0 3h.01M10.3 4.2 2.9 17a2 2 0 0 0 1.7 3h14.8a2 2 0 0 0 1.7-3L13.7 4.2a2 2 0 0 0-3.4 0Z"/>',
            primary: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 8.6v4.2m0 3.1h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'
        };

        var back   = document.getElementById('pc-dlg-back');
        var elIco  = document.getElementById('pc-dlg-ico');
        var elTtl  = document.getElementById('pc-dlg-title');
        var elBody = document.getElementById('pc-dlg-body');
        var btnGo  = document.getElementById('pc-dlg-go');
        var btnNo  = document.getElementById('pc-dlg-cancel');

        var settle = null;      // resolver for the dialog on screen
        var queue  = [];        // calls that arrived while one was open
        var lastFocus = null;

        /*
         * A message may carry its own paragraph break, as the confirm() texts
         * this replaces did. The first line is the question and belongs in the
         * heading; the rest is the explanation underneath.
         */
        function split(title, body) {
            if (body || typeof title !== 'string') return [title, body || ''];
            var at = title.indexOf('\n');
            if (at === -1) return [title, ''];
            return [title.slice(0, at).trim(), title.slice(at + 1).trim()];
        }

        function paint(o) {
            var tone = ICONS[o.tone] ? o.tone : 'danger';
            var parts = split(o.title, o.body);

            back.dataset.tone = tone;
            elIco.innerHTML = '<svg fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">' + ICONS[tone] + '</svg>';
            elTtl.textContent = parts[0];
            elBody.textContent = parts[1];
            elBody.hidden = parts[1] === '';
            btnGo.textContent = o.ok || 'Confirm';
            btnNo.textContent = o.cancel || 'Cancel';
            // An alert has nothing to decline — it is told, not asked.
            btnNo.hidden = !!o.alert;

            lastFocus = document.activeElement;
            back.hidden = false;
            back.classList.add('open');
            // Focus the safe button, so Enter on a dialog nobody has read
            // cannot delete anything.
            (o.alert ? btnGo : btnNo).focus();
        }

        function open(o) {
            return new Promise(function (resolve) {
                if (settle) { queue.push([o, resolve]); return; }
                settle = resolve;
                paint(o);
            });
        }

        function close(answer) {
            if (!settle) return;
            var resolve = settle;
            settle = null;
            back.classList.remove('open');
            back.hidden = true;
            if (lastFocus && typeof lastFocus.focus === 'function') {
                try { lastFocus.focus(); } catch (e) {}
            }
            lastFocus = null;
            resolve(answer);
            if (queue.length) {
                var next = queue.shift();
                settle = next[1];
                paint(next[0]);
            }
        }

        btnGo.addEventListener('click', function () { close(true); });
        btnNo.addEventListener('click', function () { close(false); });
        back.addEventListener('click', function (e) { if (e.target === back) close(false); });

        // Capture, so Escape closes this dialog and stops there rather than
        // also closing an admin overlay behind it — both layouts listen for
        // Escape on document.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !settle) return;
            e.stopPropagation();
            close(false);
        }, true);

        // Keep Tab inside the dialog while it is open.
        back.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab') return;
            var can = [btnNo, btnGo].filter(function (b) { return !b.hidden; });
            if (!can.length) return;
            var first = can[0], last = can[can.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });

        /**
         * pcConfirm('Delete this?')  or
         * pcConfirm({ title, body, tone, ok, cancel })  →  Promise<boolean>
         */
        window.pcConfirm = function (opts) {
            return open(typeof opts === 'string' ? { title: opts } : (opts || {}));
        };

        /** pcAlert(title, body) → Promise, resolved when it is dismissed. */
        window.pcAlert = function (title, body) {
            return open({ title: title, body: body, tone: 'primary', ok: 'OK', alert: true });
        };

        /*
         * Forms carrying data-pc-confirm.
         *
         * Captured, so this runs before any handler the page attached to the
         * same form. On OK the form is submitted again with a flag set, which
         * sends this handler straight back out of the way — without the flag
         * it would ask again forever.
         */
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.matches || !form.matches('form[data-pc-confirm]')) return;
            if (form.dataset.pcConfirmed === '1') { delete form.dataset.pcConfirmed; return; }

            e.preventDefault();
            e.stopPropagation();

            // Whichever button was pressed: its name and value are part of the
            // submission for several handlers, so hand it back to requestSubmit.
            var by = e.submitter || null;

            window.pcConfirm({
                title:  form.dataset.pcConfirm,
                tone:   form.dataset.pcTone || 'danger',
                ok:     form.dataset.pcOk || 'Confirm',
                cancel: form.dataset.pcCancel || 'Cancel'
            }).then(function (ok) {
                if (!ok) return;
                form.dataset.pcConfirmed = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(by && by.form === form ? by : undefined);
                } else if (by && typeof by.click === 'function') {
                    by.click();
                } else {
                    form.submit();
                }
            });
        }, true);
    })();
</script>
