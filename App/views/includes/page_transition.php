<?php

/**
 * page_transition.php — a short crossfade between the public pages.
 *
 * Include once, just before </body>, on any page that should take part.
 * Currently the landing page, login and signup, so moving between them feels
 * like one flow rather than three separate documents.
 *
 * Why it is built the way it is:
 *
 *  - The entrance is a CSS *transition* toggled by a class, not a keyframe
 *    animation. A keyframe starting at opacity 0 renders as opacity 0 for as
 *    long as it fails to advance, so anything that stops animations running
 *    (a throttled background tab, a browser deferring work) would leave the
 *    page blank. With a transition the resting state is visible: the hidden
 *    state only ever exists while JavaScript is actively holding it, and
 *    three separate things release it.
 *
 *  - The fade is opacity only. Animating a transform on <body> would make it
 *    the containing block for position:fixed children, which would break the
 *    legal modal on the auth pages while the animation ran.
 *
 *  - Only same-origin left-clicks that actually change page are intercepted,
 *    so in-page anchors (#about), new-tab clicks, downloads, mailto links and
 *    modified clicks all keep their normal behaviour.
 *
 *  - A hard timeout navigates anyway if the fade never reports finishing, so
 *    a link can never end up doing nothing.
 */
?>
<style>
    @media (prefers-reduced-motion: no-preference) {
        body {
            transition: opacity .26s ease-out;
        }

        /* Only ever set by the script below, and always removed again. */
        body.pt-enter {
            opacity: 0;
            transition: none;
        }

        body.pt-leaving {
            opacity: 0;
            transition: opacity .17s ease-in;
        }

        .auth-card,
        .auth-aside-inner {
            transition: opacity .42s cubic-bezier(.2, .7, .3, 1), transform .42s cubic-bezier(.2, .7, .3, 1);
        }

        /* The card lifts as it arrives. Safe to transform: it is not an
           ancestor of anything position:fixed. */
        body.pt-enter .auth-card,
        body.pt-enter .auth-aside-inner {
            opacity: 0;
            transform: translateY(12px);
            transition: none;
        }
    }
</style>

<script>
    (function() {
        var body = document.body;
        var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');

        function reveal() {
            body.classList.remove('pt-enter');
        }

        if (!reduced.matches) {
            body.classList.add('pt-enter');
            // Normal path: next frame, so the browser paints the hidden state
            // once and then transitions out of it.
            requestAnimationFrame(function() {
                requestAnimationFrame(reveal);
            });
            // Safety nets. A background tab may never run the frames above;
            // whichever of these fires first wins, and reveal() is idempotent.
            setTimeout(reveal, 400);
            document.addEventListener('visibilitychange', reveal, { once: true });
            window.addEventListener('load', reveal, { once: true });
        }

        // Coming back via the back button can restore the page mid-fade from
        // the bfcache. Without this the visitor lands on a blank screen.
        window.addEventListener('pageshow', function() {
            body.classList.remove('pt-leaving');
            reveal();
        });

        document.addEventListener('click', function(e) {
            if (reduced.matches) return;
            if (e.defaultPrevented || e.button !== 0) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

            var a = e.target.closest('a[href]');
            if (!a) return;
            if (a.target && a.target !== '_self') return;
            if (a.hasAttribute('download')) return;

            var raw = a.getAttribute('href') || '';
            if (raw.startsWith('mailto:') || raw.startsWith('tel:')) return;

            var url;
            try {
                url = new URL(a.href, location.href);
            } catch (err) {
                return;
            }
            if (url.origin !== location.origin) return;
            // Same document — that is an in-page anchor, leave it alone.
            if (url.pathname === location.pathname && url.search === location.search) return;

            e.preventDefault();

            var done = false;
            var go = function() {
                if (done) return;
                done = true;
                window.location.href = a.href;
            };

            body.classList.add('pt-leaving');
            body.addEventListener('transitionend', go, { once: true });
            // Never let a missed transitionend strand the visitor on this page.
            setTimeout(go, 260);
        });
    })();
</script>
