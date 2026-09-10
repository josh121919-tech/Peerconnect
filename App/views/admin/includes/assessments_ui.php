<?php

/**
 * assessments_ui.php — the look the three assessment screens share.
 *
 * Reuses the sessions styling for stat tiles, tabs, filters and pagination so
 * the two areas of the admin do not look like different products, and adds
 * only what assessments need on top.
 */

if (defined('AD_ASSESS_UI')) return;
define('AD_ASSESS_UI', true);

require_once __DIR__ . '/sessions_ui.php';

/** Icons specific to assessments. */
function as_icon(string $k): string
{
    $p = [
        'paper'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.6a1 1 0 0 1 .7.3l5.4 5.4a1 1 0 0 1 .3.7V19a2 2 0 0 1-2 2Z"/>',
        'quiz'   => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M9.6 9.4a2.5 2.5 0 1 1 3.3 2.4c-.6.2-.9.8-.9 1.4v.3m0 2.6h.01"/>',
        'pen'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 20 16.5 7.5l3 3L7 23H4v-3Z"/>',
        'target' => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1"/>',
        'flag'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M5 21V4m0 0h11l-2 3.5L16 11H5"/>',
        'user'   => '<circle cx="12" cy="8" r="3.4"/><path stroke-linecap="round" d="M5.5 20a6.5 6.5 0 0 1 13 0"/>',
    ];
    return '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">' . ($p[$k] ?? '') . '</svg>';
}
?>

<style>
    /* ── List rows ── */
    .as-list { display: flex; flex-direction: column; gap: 10px; }
    .as-row {
        display: flex; align-items: center; gap: 16px; padding: 14px 16px;
        background: #fff; border: 1px solid var(--gray-100); border-radius: 14px;
        box-shadow: 0 1px 2px rgba(16,24,40,.04);
    }
    .as-row.on { border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0,135,207,.12); }

    .as-ico { flex: none; width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; }
    .as-ico svg { width: 21px; height: 21px; }

    .as-what { flex: 1.6; min-width: 0; }
    .as-title { font-size: 14.5px; font-weight: 700; color: var(--forest); }
    .as-desc { font-size: 12.5px; color: var(--gray-500); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .as-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 7px; }
    .as-chip { padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }

    .as-num { flex: none; width: 92px; text-align: center; }
    .as-num b { display: block; font-size: 18px; font-weight: 700; color: var(--forest); font-variant-numeric: tabular-nums; }
    .as-num span { display: block; font-size: 11.5px; color: var(--gray-400); }

    .as-score { flex: none; width: 108px; display: flex; align-items: center; gap: 8px; }
    .as-score svg { width: 17px; height: 17px; color: #E5A800; flex: none; }
    .as-score b { display: block; font-size: 15px; font-weight: 700; color: var(--forest); }
    .as-score span { display: block; font-size: 11.5px; color: var(--gray-400); }

    .as-when { flex: none; width: 128px; font-size: 12.5px; color: var(--gray-600); }
    .as-when span { display: block; font-size: 11.5px; color: var(--gray-400); }

    .as-end { flex: none; width: 132px; display: flex; flex-direction: column; gap: 8px; }

    /* ── Question rows ── */
    .as-q { display: flex; align-items: center; gap: 14px; padding: 13px 16px; background: #fff; border: 1px solid var(--gray-100); border-radius: 13px; }
    .as-q-ico { flex: none; width: 38px; height: 38px; border-radius: 11px; display: grid; place-items: center; font-size: 13px; font-weight: 700; }
    .as-q-ico svg { width: 18px; height: 18px; }
    .as-q-main { flex: 1; min-width: 0; }
    .as-q-text { font-size: 13.5px; font-weight: 600; color: var(--gray-800); line-height: 1.45; }
    .as-q-meta { flex: none; width: 150px; font-size: 12px; color: var(--gray-500); }
    .as-q-meta b { color: var(--gray-800); font-weight: 600; }
    .as-q-rate { flex: none; width: 132px; }
    .as-q-rate b { font-size: 14px; font-weight: 700; color: var(--forest); }
    .as-q-rate span { font-size: 11.5px; color: var(--gray-400); display: block; }
    .as-q-bar { height: 6px; border-radius: 999px; background: var(--gray-100); overflow: hidden; margin-top: 5px; }
    .as-q-bar i { display: block; height: 100%; border-radius: 999px; }

    /* ── Side rail ── */
    .as-side { display: flex; flex-direction: column; gap: 14px; }
    .as-side .ss-card { padding: 16px 17px; }
    .as-rail { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 14px; align-items: start; }

    .as-legend { display: flex; flex-direction: column; gap: 9px; }
    .as-legend-row { display: flex; align-items: center; gap: 9px; font-size: 12.5px; color: var(--gray-600); }
    .as-legend-row i { width: 9px; height: 9px; border-radius: 50%; flex: none; }
    .as-legend-row b { margin-left: auto; font-weight: 700; color: var(--forest); font-variant-numeric: tabular-nums; }

    .as-act { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-top: 1px solid var(--gray-100); font-size: 12.5px; color: var(--gray-600); text-decoration: none; }
    .as-act:first-of-type { border-top: 0; }
    .as-act-i { width: 32px; height: 32px; flex: none; border-radius: 9px; display: grid; place-items: center; }
    .as-act-i svg { width: 16px; height: 16px; }
    .as-act b { display: block; font-size: 12.5px; font-weight: 600; color: var(--gray-800); }
    .as-act span { font-size: 11.5px; color: var(--gray-400); }

    /* ── Detail panel additions ── */
    .as-opt { display: flex; align-items: center; gap: 9px; padding: 7px 11px; border-radius: 9px; background: var(--gray-50, #F7F8FA); font-size: 12.5px; color: var(--gray-700); margin-top: 6px; }
    .as-opt.right { background: #E6F5EE; color: #17654B; font-weight: 600; }
    .as-opt-k { flex: none; width: 18px; height: 18px; border-radius: 50%; background: #fff; display: grid; place-items: center; font-size: 10px; font-weight: 700; color: var(--gray-500); }
    .as-opt.right .as-opt-k { color: #17654B; }

    @media (max-width: 1240px) { .as-rail { grid-template-columns: minmax(0, 1fr); } }
    @media (max-width: 1180px) { .as-score, .as-when { display: none; } }
    @media (max-width: 760px) {
        .as-row { flex-wrap: wrap; }
        .as-what { flex-basis: 100%; }
        .as-end { width: 100%; }
        .as-q { flex-wrap: wrap; }
        .as-q-main { flex-basis: 100%; }
        .as-q-meta, .as-q-rate { width: auto; flex: 1; }
    }
</style>
