<?php

/**
 * feedback_page.php — the shared look of the two "My Feedback" pages.
 *
 * Mentors see reviews their mentees left (`feedback`); mentees see reviews
 * their mentors left (`mentee_reviews`). The two tables hold different
 * columns and different aspect labels, so each page runs its own queries —
 * but the cards, bars, chips and review rows are the same design, and live
 * here so the two cannot drift apart.
 */

/** Five stars with the filled portion clipped to the exact decimal rating. */
function fbk_stars(float $r): string
{
    $pct = max(0, min(100, $r / 5 * 100));
    return '<span class="fbk-stars"><span class="fbk-stars-on" style="width:' . round($pct, 2) . '%">★★★★★</span>★★★★★</span>';
}

/** Small inline glyphs, kept local so the page carries only what it draws. */
function fbk_svg(string $name, string $attrs = 'width="16" height="16"'): string
{
    $p = [
        'star'     => '<path d="m12 3.6 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9L12 3.6Z"/>',
        'chat'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M20 12a7 7 0 0 1-7 7H8.5L5 21.5V18A7 7 0 0 1 12 5h1a7 7 0 0 1 7 7Z"/>',
        'users'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 19c0-1.8-1.2-3.3-2.8-3.8M17 4.4a3 3 0 0 1 0 5.2"/>',
        'trophy'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M7 4h10v5a5 5 0 0 1-10 0V4Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M7 6H4.5v1.5A3.5 3.5 0 0 0 8 11M17 6h2.5v1.5A3.5 3.5 0 0 1 16 11M12 14v4M8.5 21h7"/>',
        'chart'    => '<path stroke-linecap="round" d="M5 20V11M12 20V4M19 20v-6"/>',
        'heart'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 20s-7-4.3-7-9a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 4.7-7 9-7 9Z"/>',
        'doc'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5"/>',
        'tag'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12.5V5a2 2 0 0 1 2-2h7.5L21 11.5 12.5 20 3 12.5Z"/><circle cx="8" cy="8" r="1.3"/>',
        'sort'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M7 4v16m0 0-3-3m3 3 3-3M17 20V4m0 0-3 3m3-3 3 3"/>',
        'chevron'  => '<path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/>',
        'arrow-up' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5m0 0-6 6m6-6 6 6"/>',
        'kebab'    => '<circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/>',
        'copy'     => '<rect x="9" y="9" width="11" height="11" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 15V5a2 2 0 0 1 2-2h8"/>',
        'send'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 3 10.5 13.5M21 3l-6.5 18-4-8-8-4L21 3Z"/>',
    ];
    if (!isset($p[$name])) return '';
    return '<svg ' . $attrs . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">' . $p[$name] . '</svg>';
}

/** Stable pastel per mentee, so the same person keeps the same avatar tint. */
function fbk_avatar_tint(int $id): array
{
    $palette = [
        ['#EAF1FB', '#1A5C9A'],
        ['#EFEDFC', '#5B4FCF'],
        ['#E6F4EE', '#1F7A5C'],
        ['#FEF3E2', '#B87A10'],
        ['#FDECEA', '#C0392B'],
    ];
    return $palette[$id % count($palette)];
}

/**
 * Emit the stylesheet once. Both pages call this from <head>; the guard means
 * including the file twice in one request cannot duplicate the rules.
 */
function fbk_styles(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
?>
    <style>
        /* ── Page header ── */
        .fbk-hd {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .fbk-review-cta {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface);
            color: var(--gray-700);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
            transition: border-color .15s, box-shadow .15s;
        }

        .fbk-review-cta:hover {
            border-color: var(--accent);
            box-shadow: var(--shadow-sm);
        }

        .fbk-cta-ico {
            color: var(--forest);
            display: flex;
        }

        .fbk-cta-n {
            min-width: 22px;
            padding: 1px 7px;
            border-radius: 999px;
            background: var(--mint-faint);
            color: var(--mint-deep);
            font-size: 12px;
            font-weight: 700;
            text-align: center;
        }

        .fbk-cta-go {
            color: var(--gray-400);
            display: flex;
        }

        /* ── Stat cards ── */
        .fbk-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .fbk-stat {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 18px;
        }

        .fbk-stat-ico {
            flex: 0 0 46px;
            width: 46px;
            height: 46px;
            border-radius: 14px;
            display: grid;
            place-items: center;
        }

        .fbk-stat-body {
            min-width: 0;
        }

        .fbk-stat-v {
            font-family: 'Poppins', sans-serif;
            font-size: 26px;
            font-weight: 700;
            line-height: 1.15;
            color: var(--gray-900);
        }

        .fbk-stat-v.is-text {
            font-size: 17px;
            line-height: 1.3;
        }

        .fbk-stat-k {
            margin-top: 3px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--gray-400);
        }

        .fbk-stat-sub {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 7px;
            font-size: 12px;
            color: var(--gray-500);
        }

        .fbk-stat-sub.is-up {
            color: var(--success);
            font-weight: 600;
        }

        .fbk-stat-sub.is-down {
            color: var(--danger);
            font-weight: 600;
        }

        .fbk-stat-sub svg {
            width: 13px;
            height: 13px;
        }

        .fbk-stat-sub.is-down svg {
            transform: rotate(180deg);
        }

        .fbk-pill {
            display: inline-block;
            margin-top: 8px;
            padding: 3px 10px;
            border-radius: 999px;
            background: var(--gold-light);
            color: var(--warning);
            font-size: 11.5px;
            font-weight: 600;
        }

        /* ── Two-column body ── */
        .fbk-grid {
            display: grid;
            grid-template-columns: minmax(0, 320px) minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }

        .fbk-side {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .fbk-card-t {
            margin: 0 0 16px;
            font-family: 'Poppins', sans-serif;
            font-size: 16px;
            font-weight: 600;
            color: var(--forest);
        }

        /* ── Rating breakdown ── */
        .fbk-bar+.fbk-bar {
            margin-top: 16px;
        }

        .fbk-bar-hd {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 10px;
            margin-bottom: 6px;
            font-size: 13px;
            color: var(--gray-600);
        }

        .fbk-bar-v {
            font-weight: 700;
            color: var(--gray-900);
        }

        .fbk-bar-track {
            height: 7px;
            border-radius: 999px;
            background: var(--gray-100);
            overflow: hidden;
        }

        .fbk-bar-fill {
            height: 100%;
            border-radius: 999px;
        }

        /* ── Insights ── */
        .fbk-ins {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            font-size: 13px;
            line-height: 1.5;
            color: var(--gray-600);
        }

        .fbk-ins+.fbk-ins {
            margin-top: 14px;
        }

        .fbk-ins-ico {
            flex: 0 0 30px;
            width: 30px;
            height: 30px;
            border-radius: 9px;
            display: grid;
            place-items: center;
        }

        .fbk-quote {
            margin: 18px 0 0;
            padding: 14px 16px;
            border-radius: 12px;
            background: var(--mint-faint);
            color: var(--mint-deep);
            font-size: 13.5px;
            font-style: italic;
            text-align: center;
            line-height: 1.5;
        }

        /* ── Reviews toolbar ── */
        .fbk-list-hd {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .fbk-filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .fbk-filter {
            position: relative;
            display: inline-flex;
            align-items: center;
        }

        .fbk-filter>svg:first-child {
            position: absolute;
            left: 12px;
            width: 15px;
            height: 15px;
            color: var(--gray-500);
            pointer-events: none;
        }

        .fbk-filter::after {
            content: "";
            position: absolute;
            right: 13px;
            width: 7px;
            height: 7px;
            border-right: 1.6px solid var(--gray-400);
            border-bottom: 1.6px solid var(--gray-400);
            transform: translateY(-2px) rotate(45deg);
            pointer-events: none;
        }

        .fbk-filter select {
            appearance: none;
            -webkit-appearance: none;
            padding: 9px 32px 9px 33px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface);
            color: var(--gray-700);
            font-family: inherit;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
        }

        .fbk-filter select:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 1px;
        }

        /* ── Review card ── */
        .fbk-reviews {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .fbk-rev {
            padding: 18px;
        }

        .fbk-rev-hd {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
        }

        .fbk-av {
            flex: 0 0 44px;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-weight: 700;
            font-size: 14px;
        }

        .fbk-who {
            flex: 1 1 140px;
            min-width: 0;
        }

        .fbk-who-n {
            margin: 0;
            font-size: 14.5px;
            font-weight: 600;
            color: var(--gray-900);
        }

        .fbk-who-d {
            margin: 2px 0 0;
            font-size: 12.5px;
            color: var(--gray-400);
        }

        .fbk-rate {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .fbk-stars {
            position: relative;
            display: inline-block;
            color: var(--gray-200);
            font-size: 15px;
            letter-spacing: 1.5px;
            line-height: 1;
            white-space: nowrap;
        }

        .fbk-stars-on {
            position: absolute;
            top: 0;
            left: 0;
            overflow: hidden;
            color: var(--gold);
            white-space: nowrap;
        }

        .fbk-num {
            padding: 2px 8px;
            border-radius: 8px;
            background: var(--gray-50);
            color: var(--gray-700);
            font-size: 12.5px;
            font-weight: 600;
        }

        .fbk-chip {
            padding: 4px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .fbk-comment {
            margin: 14px 0 0;
            font-size: 14px;
            line-height: 1.6;
            color: var(--gray-700);
            overflow-wrap: anywhere;
        }

        .fbk-sess {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 14px;
            padding: 11px 13px;
            border-radius: 11px;
            background: var(--gray-50);
            font-size: 12.5px;
            color: var(--gray-500);
        }

        .fbk-sess svg {
            flex: 0 0 15px;
            width: 15px;
            height: 15px;
            color: var(--gray-400);
        }

        .fbk-sess b {
            color: var(--gray-700);
            font-weight: 600;
        }

        .fbk-sess-sep {
            color: var(--gray-300);
        }

        /* ── Row menu ── */
        .fbk-more {
            position: relative;
            margin-left: auto;
        }

        .fbk-more-btn {
            width: 30px;
            height: 30px;
            display: grid;
            place-items: center;
            border: none;
            border-radius: 8px;
            background: none;
            color: var(--gray-400);
            cursor: pointer;
        }

        .fbk-more-btn:hover {
            background: var(--gray-50);
            color: var(--gray-700);
        }

        .fbk-menu {
            display: none;
            position: absolute;
            top: 34px;
            right: 0;
            z-index: 40;
            min-width: 190px;
            padding: 6px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface);
            box-shadow: var(--shadow-md);
        }

        .fbk-more.open .fbk-menu {
            display: block;
        }

        .fbk-menu a,
        .fbk-menu button {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 9px 10px;
            border: none;
            border-radius: 8px;
            background: none;
            color: var(--gray-700);
            font-family: inherit;
            font-size: 13px;
            text-align: left;
            text-decoration: none;
            cursor: pointer;
        }

        .fbk-menu a:hover,
        .fbk-menu button:hover {
            background: var(--mint-faint);
            color: var(--mint-deep);
        }

        .fbk-menu svg {
            flex: 0 0 15px;
            width: 15px;
            height: 15px;
        }

        /* ── Empty states ── */
        .fbk-empty {
            padding: 46px 24px;
            text-align: center;
            color: var(--gray-400);
        }

        .fbk-empty svg {
            width: 34px;
            height: 34px;
            margin-bottom: 10px;
            color: var(--gray-300);
        }

        .fbk-empty p {
            margin: 0;
            font-size: 13.5px;
        }

        .fbk-count {
            font-size: 13px;
            color: var(--gray-500);
        }

        @media (max-width: 1080px) {
            .fbk-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 560px) {
            /* Below this the 210px minimum would drop the row to one card per
               line, which turns four small numbers into four screens of
               scrolling. Force a 2x2 block instead and stack each card so the
               icon, figure and label still fit in half a phone width. */
            .fbk-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .fbk-stat {
                flex-direction: column;
                gap: 10px;
                padding: 14px;
            }

            .fbk-stat-ico {
                flex: 0 0 38px;
                width: 38px;
                height: 38px;
                border-radius: 11px;
            }

            .fbk-stat-ico svg {
                width: 19px;
                height: 19px;
            }

            .fbk-stat-v {
                font-size: 22px;
            }

            .fbk-stat-v.is-text {
                font-size: 14px;
            }

            .fbk-stat-sub {
                margin-top: 5px;
            }

            .fbk-review-cta {
                width: 100%;
            }

            .fbk-filters {
                width: 100%;
            }

            .fbk-filter {
                flex: 1 1 140px;
            }

            .fbk-filter select {
                width: 100%;
            }
        }
    </style>
<?php
}
