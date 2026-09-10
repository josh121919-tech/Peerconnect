<?php

/**
 * auth_transition.php — the move between the admin sign-in and sign-up pages.
 *
 * The two pages are the same composition: one navy panel with the same
 * headline furniture on the left, one white card on the right. Only the card
 * actually changes. Fading the whole document between them — which is what
 * the public pages' page_transition.php does — would blink the identical
 * panel and photo out and back in, drawing the eye to the half that did not
 * change.
 *
 * So this uses a cross-document view transition instead. The panel is named,
 * which makes the browser treat it as the same element across the two
 * documents and leave it alone; the card is named separately and slides. The
 * effect is one screen where the card is swapped, not two page loads.
 *
 * Both documents have to opt in for a cross-document transition to run, so it
 * only ever fires between these two pages — "Back to Home" and every other
 * link is an ordinary navigation, which is the right answer for a link that
 * leaves this composition entirely.
 *
 * Browsers without cross-document view transitions (Firefox and Safari today)
 * get the second layer below instead: the same card-only movement, driven by
 * a class on <body>. It cannot hide the flash between two documents the way a
 * real view transition does, but the card still slides out and back in, so
 * the pair still reads as one screen rather than two.
 *
 * The two layers never both run: the script checks for `pagereveal`, which
 * shipped alongside cross-document view transitions, and stands down when it
 * is present. Nothing is ever hidden behind either animation — the resting
 * state is visible, and every path that hides the card also has a timeout
 * that releases it — so a link can never end up doing nothing.
 *
 * $pcAuthDir sets which way the card travels, so the pair reads as one axis:
 *   'back'    (sign in)  — its card leaves left  and arrives from the left
 *   'forward' (sign up)  — its card leaves right and arrives from the right
 * Together that means sign-in → sign-up slides the old card out to the left
 * while the new one comes in from the right, and the reverse coming back.
 */

$pcAuthDir = ($pcAuthDir ?? 'back') === 'forward' ? 'forward' : 'back';
$pcOut     = $pcAuthDir === 'forward' ? '4%'  : '-4%';
$pcIn      = $pcAuthDir === 'forward' ? '4%'  : '-4%';
?>
<style>
    @view-transition {
        navigation: auto;
    }

    /* Named parts. The panel keeps its identity across the navigation, so the
       browser holds it still instead of cross-fading it with a copy of
       itself. */
    .pc-auth-panel {
        view-transition-name: pcAuthPanel;
    }

    .pc-auth-card {
        view-transition-name: pcAuthCard;
    }

    /* The panel is visually identical on both pages; there is nothing to
       animate, and cross-fading it only introduces a flicker. */
    ::view-transition-old(pcAuthPanel),
    ::view-transition-new(pcAuthPanel) {
        animation: none;
        mix-blend-mode: normal;
    }

    /* The card is the part that actually changes, so it gets the movement.
       The two halves are deliberately not symmetrical: the outgoing card
       leaves a little faster than the incoming one arrives, which reads as
       the new card having weight rather than the two simply swapping. */
    ::view-transition-old(pcAuthCard) {
        animation: pcCardOut .2s cubic-bezier(.4, 0, 1, 1) both;
    }

    ::view-transition-new(pcAuthCard) {
        animation: pcCardIn .34s cubic-bezier(.2, .7, .3, 1) both;
    }

    @keyframes pcCardOut {
        to {
            opacity: 0;
            transform: translateX(<?= $pcOut ?>) scale(.985);
        }
    }

    @keyframes pcCardIn {
        from {
            opacity: 0;
            transform: translateX(<?= $pcIn ?>) scale(.985);
        }
    }

    /* Everything that is not one of the two named parts — the "Back to Home"
       link, the quote card — just cross-fades, which is the browser default
       and is quiet enough at this duration. */
    ::view-transition-group(root) {
        animation-duration: .26s;
    }

    @media (prefers-reduced-motion: reduce) {

        ::view-transition-old(pcAuthCard),
        ::view-transition-new(pcAuthCard),
        ::view-transition-old(root),
        ::view-transition-new(root) {
            animation: none;
        }
    }
</style>

<style>
    /* ── Fallback layer, for browsers with no cross-document view transition.
       Enabled by the script below adding .pc-vt-fallback to <html>, so it is
       inert in Chromium where the real transition does the work. ── */
    @media (prefers-reduced-motion: no-preference) {

        .pc-vt-fallback .pc-auth-card {
            transition: opacity .34s cubic-bezier(.2, .7, .3, 1),
                        transform .34s cubic-bezier(.2, .7, .3, 1);
        }

        /* Arriving. Held only while the script is actively holding it. */
        .pc-vt-fallback.pc-card-enter .pc-auth-card {
            opacity: 0;
            transform: translateX(<?= $pcIn ?>) scale(.985);
            transition: none;
        }

        /* Leaving, towards the sibling page. */
        .pc-vt-fallback.pc-card-leave .pc-auth-card {
            opacity: 0;
            transform: translateX(<?= $pcOut ?>) scale(.985);
            transition: opacity .2s cubic-bezier(.4, 0, 1, 1),
                        transform .2s cubic-bezier(.4, 0, 1, 1);
        }
    }
</style>

<script>
    (function () {
        var root = document.documentElement;

        // Cross-document view transitions and the pagereveal event shipped
        // together, so this is the honest test for "the layer above will
        // handle it". Where it does, this layer stays out of the way.
        if ('onpagereveal' in window) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        root.classList.add('pc-vt-fallback');

        // ── Arriving ─────────────────────────────────────────────────────
        // A transition out of a held state, not a keyframe from opacity 0:
        // if anything stops animations running, the resting state is the
        // visible one and three separate things release the hold.
        var released = false;
        function release() {
            if (released) return;
            released = true;
            root.classList.remove('pc-card-enter');
        }

        root.classList.add('pc-card-enter');
        requestAnimationFrame(function () { requestAnimationFrame(release); });
        setTimeout(release, 400);
        window.addEventListener('load', release, { once: true });
        document.addEventListener('visibilitychange', release, { once: true });

        // Restored from the back/forward cache mid-animation? Reset both.
        window.addEventListener('pageshow', function () {
            root.classList.remove('pc-card-leave');
            release();
        });

        // ── Leaving ──────────────────────────────────────────────────────
        // Only for the link to the sibling auth page. Anything else — Back to
        // Home, the legal modal buttons, a new-tab click — navigates normally.
        var sibling = <?= json_encode($pcAuthDir === 'forward' ? url('admin-login') : url('admin-signup')) ?>;

        document.addEventListener('click', function (e) {
            if (e.defaultPrevented || e.button !== 0) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

            var a = e.target.closest('a[href]');
            if (!a || (a.target && a.target !== '_self')) return;

            var url;
            try { url = new URL(a.href, location.href); } catch (err) { return; }
            if (url.origin !== location.origin || url.pathname !== sibling) return;

            e.preventDefault();

            var gone = false;
            function go() {
                if (gone) return;
                gone = true;
                location.href = a.href;
            }

            root.classList.add('pc-card-leave');
            var card = document.querySelector('.pc-auth-card');
            if (card) card.addEventListener('transitionend', go, { once: true });
            setTimeout(go, 260);
        });
    })();
</script>
