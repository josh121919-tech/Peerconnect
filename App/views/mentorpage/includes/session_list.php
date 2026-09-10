<?php

/**
 * session_list.php — the shared card layout behind the mentor Sessions tabs.
 *
 * Upcoming, Completed, Declined and History all show the same thing: one
 * session, one mentee, a few facts and whatever actions that status allows.
 * They used to be four separate tables with four sets of markup; this is the
 * one place that design lives now, so a change lands on all of them.
 *
 * Every action a card renders is passed in by the caller, and each tab passes
 * only the ones its status can actually do — a completed session has no "Join",
 * an "Add to calendar" link only exists for approved ones (session_ics.php
 * refuses anything else), and so on. Nothing is rendered that would dead-end.
 *
 * Usage:
 *   mp_panel_open('cs', 'check', 'Completed Sessions', 'Sub-heading…');
 *   foreach ($rows as $r) mp_session_card([...]);
 *   mp_panel_close('cs', 'Great job!', 'You have completed 3 sessions.');
 */

if (!function_exists('mp_panel_open')) {

    /** Emitted once per page, however many panels use it. */
    function mp_list_styles(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
?>
        <style>
            .mp-hd {
                display: flex;
                align-items: center;
                gap: 14px;
                flex-wrap: wrap;
                margin-bottom: 18px;
            }

            .mp-hd-ico {
                flex: 0 0 46px;
                width: 46px;
                height: 46px;
                border-radius: 50%;
                display: grid;
                place-items: center;
                background: var(--mint-faint);
                color: var(--forest);
            }

            .mp-hd-ico svg {
                width: 22px;
                height: 22px;
            }

            .mp-hd-txt {
                flex: 1 1 240px;
                min-width: 0;
            }

            .mp-hd-txt h2 {
                margin: 0;
                font-size: 20px;
                font-weight: 700;
                color: var(--forest);
                letter-spacing: -.01em;
            }

            .mp-hd-txt p {
                margin: 3px 0 0;
                font-size: 13px;
                color: var(--gray-500);
            }

            .mp-tools {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }

            .mp-search {
                position: relative;
            }

            .mp-search svg {
                position: absolute;
                left: 12px;
                top: 50%;
                transform: translateY(-50%);
                width: 15px;
                height: 15px;
                color: var(--gray-400);
                pointer-events: none;
            }

            .mp-search input {
                font: inherit;
                font-size: 13px;
                padding: 10px 12px 10px 34px;
                width: 260px;
                max-width: 100%;
                border: 1px solid var(--gray-200);
                border-radius: var(--radius);
                background: var(--surface);
                color: var(--gray-900);
            }

            .mp-search input:focus {
                outline: none;
                border-color: var(--mint);
            }

            .mp-sort {
                display: inline-flex;
                align-items: center;
                gap: 7px;
                padding: 0 10px 0 12px;
                border: 1px solid var(--gray-200);
                border-radius: var(--radius);
                background: var(--surface);
                color: var(--gray-600);
            }

            .mp-sort svg {
                width: 15px;
                height: 15px;
                color: var(--gray-400);
            }

            .mp-sort select {
                font: inherit;
                font-size: 13px;
                font-weight: 600;
                padding: 10px 4px;
                border: 0;
                background: transparent;
                color: var(--gray-900);
                cursor: pointer;
            }

            .mp-sort select:focus {
                outline: none;
            }

            /* ── Card ── */
            .mp-list {
                display: flex;
                flex-direction: column;
                gap: 14px;
            }

            .mp-card {
                display: flex;
                align-items: stretch;
                gap: 0;
                padding: 0;
                overflow: hidden;
            }

            .mp-who {
                flex: 0 0 250px;
                display: flex;
                gap: 13px;
                padding: 20px;
                min-width: 0;
            }

            .mp-av {
                flex: 0 0 52px;
                width: 52px;
                height: 52px;
                border-radius: 14px;
                display: grid;
                place-items: center;
                font-size: 16px;
                font-weight: 700;
                letter-spacing: .01em;
            }

            .mp-av.t0 { background: #DCEBFF; color: #1D4ED8; }
            .mp-av.t1 { background: #EAE4FF; color: #6D48D7; }
            .mp-av.t2 { background: #DDF5E6; color: #12784A; }
            .mp-av.t3 { background: #FFE4EF; color: #C02670; }
            .mp-av.t4 { background: #FFF0D4; color: #A6690C; }

            .mp-who-txt {
                min-width: 0;
            }

            .mp-name {
                font-size: 14.5px;
                font-weight: 700;
                color: var(--gray-900);
                line-height: 1.3;
            }

            .mp-mail {
                font-size: 12px;
                color: var(--gray-500);
                margin-top: 2px;
                overflow-wrap: anywhere;
            }

            .mp-course {
                display: flex;
                align-items: flex-start;
                gap: 6px;
                font-size: 12.3px;
                color: var(--gray-600);
                line-height: 1.4;
                margin-top: 9px;
            }

            /* Real programme names run long ("Bachelor of Special Needs
               Education (BSNED) Major in Early Childhood Education"), which
               otherwise stretches the card to four lines. Two lines, then
               ellipsis; the full text stays in the title attribute. */
            .mp-course span {
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }

            .mp-course svg {
                width: 15px;
                height: 15px;
                color: var(--gray-400);
                flex-shrink: 0;
                margin-top: 1px;
            }

            .mp-facts {
                flex: 1 1 320px;
                border-left: 1px solid var(--border);
                padding: 20px;
                min-width: 0;
            }

            .mp-fact-row {
                display: flex;
                gap: 26px;
                flex-wrap: wrap;
            }

            .mp-fact {
                display: flex;
                gap: 9px;
                align-items: flex-start;
                min-width: 0;
            }

            .mp-fact svg {
                width: 17px;
                height: 17px;
                color: var(--gray-400);
                flex-shrink: 0;
                margin-top: 2px;
            }

            .mp-fact b {
                display: block;
                font-size: 11.5px;
                font-weight: 600;
                color: var(--gray-500);
                margin-bottom: 1px;
            }

            .mp-fact span {
                font-size: 14px;
                font-weight: 700;
                color: var(--gray-900);
            }

            .mp-note {
                display: flex;
                gap: 9px;
                align-items: flex-start;
                margin-top: 16px;
            }

            .mp-note svg {
                width: 17px;
                height: 17px;
                color: var(--gray-400);
                flex-shrink: 0;
                margin-top: 2px;
            }

            .mp-note b {
                display: block;
                font-size: 11.5px;
                font-weight: 600;
                color: var(--gray-500);
                margin-bottom: 2px;
            }

            .mp-note p {
                margin: 0;
                font-size: 13px;
                line-height: 1.55;
                color: var(--gray-600);
            }

            .mp-acts {
                flex: 0 0 210px;
                border-left: 1px solid var(--border);
                padding: 20px;
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .mp-acts .badge {
                align-self: center;
            }

            .mp-icon-row {
                display: flex;
                gap: 8px;
                justify-content: center;
            }

            .mp-icon-btn {
                width: 42px;
                height: 34px;
                border-radius: 9px;
                border: 0;
                background: var(--mint-faint);
                color: var(--forest);
                display: grid;
                place-items: center;
                cursor: pointer;
                text-decoration: none;
            }

            .mp-icon-btn:hover {
                background: var(--mint-soft);
            }

            .mp-icon-btn svg {
                width: 17px;
                height: 17px;
            }

            .mp-acts .btn {
                justify-content: center;
            }

            /* ── Footer banner ── */
            .mp-foot {
                display: flex;
                align-items: center;
                gap: 14px;
                flex-wrap: wrap;
                margin-top: 16px;
                padding: 18px 20px;
                background: linear-gradient(120deg, var(--mint-faint) 0%, var(--surface) 70%);
            }

            .mp-foot-ico {
                flex: 0 0 44px;
                width: 44px;
                height: 44px;
                border-radius: 50%;
                display: grid;
                place-items: center;
                background: var(--surface);
                color: var(--forest);
            }

            .mp-foot-ico svg {
                width: 21px;
                height: 21px;
            }

            .mp-foot-txt {
                flex: 1 1 260px;
                min-width: 0;
            }

            .mp-foot-txt b {
                display: block;
                font-size: 14px;
                color: var(--forest);
            }

            .mp-foot-txt span {
                font-size: 13px;
                color: var(--gray-600);
            }

            .mp-foot em {
                font-size: 13px;
                color: var(--gray-500);
                flex-shrink: 0;
            }

            .mp-none {
                font-size: 13px;
                color: var(--gray-400);
                padding: 10px 2px;
            }

            @media (max-width: 1080px) {
                .mp-card {
                    flex-wrap: wrap;
                }

                .mp-who {
                    flex: 1 1 100%;
                    border-bottom: 1px solid var(--border);
                }

                .mp-facts {
                    flex: 1 1 60%;
                    border-left: 0;
                }

                .mp-acts {
                    flex: 1 1 200px;
                }
            }

            @media (max-width: 700px) {
                .mp-facts,
                .mp-acts {
                    flex: 1 1 100%;
                    border-left: 0;
                }

                .mp-acts {
                    border-top: 1px solid var(--border);
                    flex-direction: row;
                    flex-wrap: wrap;
                    align-items: center;
                }

                .mp-acts .badge {
                    align-self: auto;
                }

                .mp-acts .btn {
                    flex: 1 1 140px;
                }

                .mp-search input {
                    width: 100%;
                }

                .mp-tools {
                    width: 100%;
                }

                .mp-search {
                    flex: 1 1 100%;
                }
            }
        </style>
    <?php
    }

    /** Icons used by the panel heads and cards, by name. */
    function mp_icon(string $name): string
    {
        $p = [
            'check'    => '<circle cx="12" cy="12" r="8.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.3 2.4 2.4 4.6-4.9"/>',
            'calendar' => '<rect x="4" y="5" width="16" height="15" rx="2.5"/><path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16"/>',
            'clock'    => '<circle cx="12" cy="12" r="8.5"/><path stroke-linecap="round" d="M12 7.5V12l3 1.8"/>',
            'x'        => '<circle cx="12" cy="12" r="8.5"/><path stroke-linecap="round" d="m9.5 9.5 5 5M14.5 9.5l-5 5"/>',
            'users'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.5 19c0-2.1-1.7-3.8-3.8-3.8h-.4C9.2 15.2 7.5 16.9 7.5 19"/><circle cx="11.5" cy="9" r="3.2"/><path stroke-linecap="round" d="M17.5 12.2a2.6 2.6 0 0 0 0-5M20.5 18c0-1.6-1-3-2.5-3.5"/>',
            'chat'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H12l-5 4v-4h-.5A2.5 2.5 0 0 1 4 13.5v-7Z"/>',
            'book'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.5C10.4 5.2 8.4 4.5 6 4.5H4v13h2c2.4 0 4.4.7 6 2 1.6-1.3 3.6-2 6-2h2v-13h-2c-2.4 0-4.4.7-6 2Z"/><path stroke-linecap="round" d="M12 6.5v13"/>',
            'doc'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5M9 13h6M9 17h4"/>',
            'cap'      => '<path stroke-linecap="round" stroke-linejoin="round" d="m12 4 9 4.5-9 4.5-9-4.5L12 4Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6.5 10.5V16c0 1.4 2.5 2.5 5.5 2.5s5.5-1.1 5.5-2.5v-5.5"/>',
            'eye'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3.1"/>',
            'chart'    => '<path stroke-linecap="round" d="M5 19V11M12 19V5M19 19v-5"/>',
            'video'    => '<rect x="3" y="6" width="12" height="12" rx="2.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m15 10.5 5-3v9l-5-3"/>',
            'search'   => '<circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.6-3.6"/>',
        ];
        return $p[$name] ?? $p['doc'];
    }

    function mp_svg(string $name, string $extra = ''): string
    {
        return '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true" ' . $extra . '>'
            . mp_icon($name) . '</svg>';
    }

    /**
     * Panel heading. $sorts is a label => value map; pass [] for no sort control,
     * and $search = false where there is nothing worth searching.
     */
    function mp_panel_open(string $id, string $icon, string $title, string $sub, bool $search = true, array $sorts = ['Newest first' => 'newest', 'Oldest first' => 'oldest', 'Student name' => 'name']): void
    {
        mp_panel_head($id, $icon, $title, $sub, $search, $sorts);
        echo '<div class="mp-list" id="' . htmlspecialchars($id) . 'List">';
    }

    /**
     * The heading on its own, for a panel whose body is not a list of session
     * cards — Group Sessions, where one row is a slot holding several students
     * rather than a single booking.
     */
    function mp_panel_head(string $id, string $icon, string $title, string $sub, bool $search = true, array $sorts = ['Newest first' => 'newest', 'Oldest first' => 'oldest', 'Student name' => 'name']): void
    {
        mp_list_styles();
    ?>
        <div class="mp-hd">
            <span class="mp-hd-ico"><?= mp_svg($icon) ?></span>
            <div class="mp-hd-txt">
                <h2><?= htmlspecialchars($title) ?></h2>
                <p><?= htmlspecialchars($sub) ?></p>
            </div>
            <?php if ($search || $sorts): ?>
                <div class="mp-tools">
                    <?php if ($search): ?>
                        <label class="mp-search">
                            <?= mp_svg('search') ?>
                            <input type="search" id="<?= htmlspecialchars($id) ?>Search"
                                placeholder="Search sessions, students, or subjects…"
                                aria-label="Search <?= htmlspecialchars(strtolower($title)) ?>">
                        </label>
                    <?php endif; ?>
                    <?php if ($sorts): ?>
                        <span class="mp-sort">
                            <?= mp_svg('calendar') ?>
                            <select id="<?= htmlspecialchars($id) ?>Sort" aria-label="Sort <?= htmlspecialchars(strtolower($title)) ?>">
                                <?php foreach ($sorts as $label => $value): ?>
                                    <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * One session card.
     *
     * $c keys: initials, tint (0-4), name, email, course, subject, date, time,
     *          note, note_label, badge, badge_class, actions[], search, sortkey.
     * Each action: ['kind'=>'icon'|'button', 'href', 'label', 'title', 'class'].
     */
    function mp_session_card(array $c): void
    {
        $e = fn($v) => htmlspecialchars((string)$v);
        ?>
        <article class="card mp-card"
            data-search="<?= $e(strtolower($c['search'] ?? '')) ?>"
            data-when="<?= (int)($c['sortkey'] ?? 0) ?>"
            data-name="<?= $e($c['name'] ?? '') ?>">

            <div class="mp-who">
                <span class="mp-av t<?= (int)($c['tint'] ?? 0) ?>"><?= $e($c['initials'] ?? '?') ?></span>
                <div class="mp-who-txt">
                    <div class="mp-name"><?= $e($c['name'] ?? '') ?></div>
                    <?php if (!empty($c['email'])): ?>
                        <div class="mp-mail"><?= $e($c['email']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($c['course'])): ?>
                        <div class="mp-course" title="<?= $e($c['course']) ?>">
                            <?= mp_svg('cap') ?><span><?= $e($c['course']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mp-facts">
                <div class="mp-fact-row">
                    <div class="mp-fact">
                        <?= mp_svg('book') ?>
                        <span><b>Subject</b><?= $e($c['subject'] ?? '—') ?></span>
                    </div>
                    <div class="mp-fact">
                        <?= mp_svg('calendar') ?>
                        <span><b>Date</b><?= $e($c['date'] ?? '') ?></span>
                    </div>
                    <div class="mp-fact">
                        <?= mp_svg('clock') ?>
                        <span><b>Time</b><?= $e($c['time'] ?? '') ?></span>
                    </div>
                </div>

                <?php if (!empty($c['note'])): ?>
                    <div class="mp-note">
                        <?= mp_svg('doc') ?>
                        <div>
                            <b><?= $e($c['note_label'] ?? 'Note') ?></b>
                            <p><?= $e($c['note']) ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mp-acts">
                <?php if (!empty($c['badge'])): ?>
                    <span class="badge <?= $e($c['badge_class'] ?? 'badge-gray') ?>"><?= $e($c['badge']) ?></span>
                <?php endif; ?>

                <?php
                $icons  = array_filter($c['actions'] ?? [], fn($a) => ($a['kind'] ?? '') === 'icon');
                $btns   = array_filter($c['actions'] ?? [], fn($a) => ($a['kind'] ?? '') !== 'icon');
                ?>
                <?php if ($icons): ?>
                    <div class="mp-icon-row">
                        <?php foreach ($icons as $a): ?>
                            <a class="mp-icon-btn" href="<?= $e($a['href']) ?>" title="<?= $e($a['title'] ?? $a['label'] ?? '') ?>"
                                aria-label="<?= $e($a['title'] ?? $a['label'] ?? '') ?>"><?= mp_svg($a['icon'] ?? 'eye') ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php foreach ($btns as $a): ?>
                    <a class="btn <?= $e($a['class'] ?? 'btn-outline') ?> btn-sm" href="<?= $e($a['href']) ?>"><?= $e($a['label']) ?></a>
                <?php endforeach; ?>

                <?php // Raw slot for a control the caller has to build itself —
                //     Upcoming's countdown, which swaps itself for a Start
                //     button at T-10. Callers are responsible for escaping it. ?>
                <?php if (!empty($c['actions_html'])): ?>
                    <?= $c['actions_html'] ?>
                <?php endif; ?>
            </div>
        </article>
    <?php
    }

    /** Closes the list and, when given text, adds the summary banner. */
    function mp_panel_close(string $id, string $title = '', string $text = '', string $tagline = 'Mentor today. Brighter tomorrows.'): void
    {
        echo '</div>';
        if ($title === '' && $text === '') return;
    ?>
        <div class="card mp-foot">
            <span class="mp-foot-ico"><?= mp_svg('chart') ?></span>
            <div class="mp-foot-txt">
                <b><?= htmlspecialchars($title) ?></b>
                <span><?= htmlspecialchars($text) ?></span>
            </div>
            <em><?= htmlspecialchars($tagline) ?></em>
        </div>
    <?php
    }

    /** Search + sort wiring for one panel. Ids match mp_panel_open()'s $id. */
    function mp_panel_script(string $id): void
    {
    ?>
        <script>
            (function() {
                var list = document.getElementById(<?= json_encode($id . 'List') ?>);
                var search = document.getElementById(<?= json_encode($id . 'Search') ?>);
                var sort = document.getElementById(<?= json_encode($id . 'Sort') ?>);
                if (!list) return;
                var cards = Array.prototype.slice.call(list.children);

                function apply() {
                    var q = search ? (search.value || '').trim().toLowerCase() : '';
                    cards.forEach(function(c) {
                        c.hidden = q !== '' && c.dataset.search.indexOf(q) === -1;
                    });
                    if (!sort) return;
                    var dir = sort.value;
                    cards.slice().sort(function(a, b) {
                        if (dir === 'name') return a.dataset.name.localeCompare(b.dataset.name);
                        var d = (+a.dataset.when) - (+b.dataset.when);
                        return dir === 'oldest' ? d : -d;
                    }).forEach(function(c) {
                        list.appendChild(c);
                    });
                }
                if (search) search.addEventListener('input', apply);
                if (sort) sort.addEventListener('change', apply);
                apply();
            })();
        </script>
<?php
    }
}
