<?php

/**
 * join_control.php — the one control that offers a video session.
 *
 * A session's call opens SessionRepository::JOIN_WINDOW_MINUTES before it
 * starts and closes at its scheduled end. The lobby and the room both enforce
 * that, so a button offered outside the window can only bounce the person
 * straight back — which is what the mentor's dashboard and the mentee's did:
 * a live Join beside a session still an hour away.
 *
 * The rule was written out by hand in six places and had three different
 * answers: 15 minutes in the constant and in the mentor's tabs, 10 minutes on
 * the mentee's request list and half of their sessions page, and no window at
 * all on both dashboards and the mentee calendar. This is the one that reads
 * the constant, so there is nothing left to disagree with.
 *
 * Both edges are live, because a page is rendered once and then sat on. Before
 * the window opens it shows a countdown that turns itself into the real button
 * at the moment the call opens; when the end passes the button turns itself
 * into whatever the caller wants shown afterwards, or removes itself. Without
 * that second half a dashboard left open at nine still offered "Rejoin" on a
 * session that had finished at ten.
 */

if (!function_exists('pc_join_control')) {

    /**
     * @param array $o  'session_id'  int, required
     *                  'starts_at'   datetime string or timestamp, required
     *                  'ends_at'     datetime string or timestamp; without it
     *                                the button has no expiry, so pass it
     *                                unless the caller has already excluded
     *                                sessions that are over
     *                  'status'      'approved' | 'unfinished' — picks Join vs Rejoin
     *                  'group'       bool, adds &type=group
     *                  'class'       classes for the button, default 'btn btn-primary btn-sm'
     *                  'label'       overrides the Join wording
     *                  'icon'        trusted HTML placed before the label
     *                  'block'       bool, stretch to the container's width
     *                  'ended'       trusted HTML to show once the session is
     *                                over; nothing is shown when it is omitted
     */
    function pc_join_control(array $o): void
    {
        $id = (int)($o['session_id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $ts = fn($v) => is_numeric($v) ? (int)$v : (int)strtotime((string)$v);

        $startTs = $ts($o['starts_at'] ?? 0);
        if ($startTs <= 0) {
            return;
        }
        $opensTs = $startTs - (SessionRepository::JOIN_WINDOW_MINUTES * 60);
        $endsTs  = isset($o['ends_at']) ? $ts($o['ends_at']) : null;
        $now     = time();

        $label = $o['label'] ?? (($o['status'] ?? '') === 'unfinished' ? 'Rejoin' : 'Join');
        $class = $o['class'] ?? 'btn btn-primary btn-sm';
        $href  = url('video-join') . '?session_id=' . $id . (!empty($o['group']) ? '&type=group' : '');
        $block = !empty($o['block']);
        $style = 'flex-shrink:0;' . ($block ? 'width:100%;justify-content:center;' : '');
        $icon  = (string)($o['icon'] ?? '');
        $ended = (string)($o['ended'] ?? '');

        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        // Over already. The lobby refuses it, so there is nothing to offer.
        if ($endsTs !== null && $now > $endsTs) {
            echo $ended;
            return;
        }

        /*
         * uniqid, not the session id: a session can appear twice on one page
         * (a list and a panel), and two elements sharing an id would leave the
         * second control driving the first one's node.
         */
        $uid  = 'jc' . substr(str_replace('.', '', uniqid('', true)), -10);
        $open = $now >= $opensTs;

        if ($open) {
            echo '<a id="' . $uid . '" class="' . $e($class) . '" style="' . $e($style) . '" href="' . $e($href) . '">'
               . $icon . $e($label) . '</a>';
        } else {
            // A countdown rather than a disabled button, because "in 1h 15m"
            // answers the question the button was being clicked to ask.
            ?>
            <span id="<?= $uid ?>" class="pc-join-wait" style="<?= $e($block ? 'width:100%;' : 'flex-shrink:0;') ?>display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:12px;color:var(--gray-500);background:var(--gray-100);padding:7px 12px;border-radius:8px;white-space:nowrap;">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" />
                    <path stroke-linecap="round" d="M12 7v5l3 2" />
                </svg>
                <span data-t>&ndash;</span>
            </span>
            <?php
        }

        // Nothing left to watch for: it is already open and never expires.
        if ($open && $endsTs === null) {
            return;
        }
        ?>
        <script>
            (function () {
                var box = document.getElementById(<?= json_encode($uid) ?>);
                if (!box) return;

                var opensAt = <?= $opensTs ?> * 1000;
                var endsAt = <?= $endsTs === null ? 'null' : $endsTs * 1000 ?>;
                var href = <?= json_encode($href) ?>;
                var cls = <?= json_encode($class) ?>;
                var css = <?= json_encode($style) ?>;
                var inner = <?= json_encode($icon . $e($label)) ?>;
                var endedHtml = <?= json_encode($ended) ?>;
                var timer = null;

                /* The window has opened: become the real button. */
                function toButton() {
                    var a = document.createElement('a');
                    a.id = box.id;
                    a.className = cls;
                    a.style.cssText = css;
                    a.href = href;
                    a.innerHTML = inner;
                    box.replaceWith(a);
                    box = a;
                }

                /* The session is over: the lobby would refuse it, so stop
                   offering it. Whatever the caller wants in its place, or
                   nothing at all. */
                function toEnded() {
                    if (timer) { clearTimeout(timer); timer = null; }
                    if (!endedHtml) { box.remove(); return; }
                    var span = document.createElement('span');
                    span.innerHTML = endedHtml;
                    box.replaceWith(span.childNodes.length === 1 ? span.firstChild : span);
                }

                function tick() {
                    var now = Date.now();

                    if (endsAt !== null && now > endsAt) { toEnded(); return; }

                    if (now >= opensAt) {
                        if (box.tagName !== 'A') toButton();
                        if (endsAt === null) return;
                        // Land on the end rather than up to 30s after it.
                        timer = setTimeout(tick, Math.min(endsAt - now + 500, 30000));
                        return;
                    }

                    var left = opensAt - now;
                    var out = box.querySelector('[data-t]');
                    if (out) {
                        var m = Math.floor(left / 60000);
                        var h = Math.floor(m / 60);
                        out.textContent = h > 0 ? h + 'h ' + (m % 60) + 'm' : (m + 1) + 'm';
                    }
                    // A minute is plenty: the label only ever changes that
                    // often, and the last one lands within a minute of the
                    // call opening.
                    timer = setTimeout(tick, left < 60000 ? left + 500 : 30000);
                }
                tick();
            })();
        </script>
        <?php
    }
}
