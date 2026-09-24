<?php

/**
 * sessions_ui.php — the look the three session screens share.
 *
 * All Sessions, Calendar View and Session Reports are three views of one
 * thing, so a status pill, an avatar or a stat tile has to be identical
 * across them. Emitted once per request; each page includes it after the
 * layout.
 */

if (defined('AD_SESSIONS_UI')) return;
define('AD_SESSIONS_UI', true);

/** Small line icons, so the three pages draw the same shapes. */
function ss_icon(string $k): string
{
    $p = [
        'cal'   => '<rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path stroke-linecap="round" d="M8 3v4M16 3v4M3.5 10h17"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7.5V12l2.8 1.8"/>',
        'check' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.5l2.2 2.2L15.5 10M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
        'x'     => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="m9 9 6 6m0-6-6 6"/>',
        'one'   => '<circle cx="12" cy="8" r="3.4"/><path stroke-linecap="round" d="M5.5 20a6.5 6.5 0 0 1 13 0"/>',
        'group' => '<circle cx="9" cy="8.5" r="3"/><path stroke-linecap="round" d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path stroke-linecap="round" d="M16 5.6a3 3 0 0 1 0 5.8M17.5 14.4A5.5 5.5 0 0 1 20.5 20"/>',
        'swap'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 8h13m0 0-3.2-3.2M17 8l-3.2 3.2M20 16H7m0 0 3.2-3.2M7 16l3.2 3.2"/>',
        'chat'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M20 12a7 7 0 0 1-7 7H8.5L5 21.5V18A7 7 0 0 1 12 5h1a7 7 0 0 1 7 7Z"/>',
        'star'  => '<path d="m12 3.6 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9L12 3.6Z"/>',
        'chart' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 19V9m5 10V5m5 14v-7m5 7V8"/>',
        'file'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5M9 13h6M9 17h4"/>',
        'user'  => '<circle cx="12" cy="8" r="3.4"/><path stroke-linecap="round" d="M5.5 20a6.5 6.5 0 0 1 13 0"/>',
    ];
    return '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">' . ($p[$k] ?? '') . '</svg>';
}

/** One participant, as shown in a list row. */
function ss_person(?string $pic, string $name, string $role, $rating): string
{
    $ini = htmlspecialchars(strtoupper(substr(trim($name), 0, 2)));
    $av  = $pic ? '<img src="' . htmlspecialchars($pic) . '" alt="">' : $ini;
    $out = '<span class="ss-who"><span class="ss-av">' . $av . '</span><span style="min-width:0;">'
         . '<b>' . htmlspecialchars($name) . '</b>'
         . '<span class="ss-who-r">' . htmlspecialchars($role) . '</span>';
    if ($rating !== null) {
        $out .= '<span class="ss-who-s">★ ' . number_format((float)$rating, 1) . '</span>';
    }
    return $out . '</span></span>';
}
?>

<style>
    /* ── Shell ── */
    .ss-wrap { display: flex; gap: 16px; align-items: flex-start; }
    .ss-main { flex: 1; min-width: 0; }

    .ss-hd { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .ss-hd h1 { margin: 0; font-size: 25px; font-weight: 700; color: var(--forest); letter-spacing: -.02em; }
    .ss-hd p { margin: 3px 0 0; font-size: 13.5px; color: var(--gray-400); }

    /* ── Figures ── */
    /* Figure tiles. The same component as the mentee and mentor dashboards'
       .stat-card — icon above the number, one surface, one shadow — drawn
       from the shared --stat-* tokens rather than its own set. The text is
       reordered here rather than in the markup: ten admin pages emit these
       in label / value / sub order, and `order` puts the number first in
       all of them at once. */
    .ss-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
    .ss-stat {
        display: flex; flex-direction: column; align-items: flex-start; gap: 12px; padding: 18px;
        background: #fff; border: 1px solid var(--stat-border); border-radius: var(--stat-radius);
        box-shadow: var(--stat-shadow); transition: box-shadow .16s ease;
    }
    .ss-stat:hover { box-shadow: var(--stat-shadow-hover); }
    .ss-stat > div { display: flex; flex-direction: column; }
    .ss-stat-ico { flex: none; width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; }
    .ss-stat-ico svg { width: 19px; height: 19px; }
    .ss-stat-k { order: 2; font-size: 12px; font-weight: 500; color: var(--gray-500); text-transform: uppercase; letter-spacing: .05em; }
    .ss-stat-v { order: 1; font-size: 26px; font-weight: 600; color: var(--forest); line-height: 1.15; letter-spacing: -0.03em; font-variant-numeric: tabular-nums; }
    .ss-stat-s { order: 3; font-size: 11.5px; color: var(--gray-400); margin-top: 8px; }
    .ss-trend.up { color: #17654B; font-weight: 600; }
    .ss-trend.down { color: #A6301F; font-weight: 600; }

    /* ── Tabs ── */
    .ss-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
    .ss-tab {
        display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px;
        border: 1px solid var(--gray-200); border-radius: 10px; background: #fff;
        font-size: 13px; font-weight: 600; color: var(--gray-600); text-decoration: none;
    }
    .ss-tab:hover { border-color: var(--mint); color: var(--mint-deep, #00539B); }
    .ss-tab.on { background: var(--primary); border-color: var(--primary); color: #fff; }
    .ss-tab-n { padding: 1px 7px; border-radius: 999px; background: var(--gray-100); color: var(--gray-600); font-size: 11.5px; }
    .ss-tab.on .ss-tab-n { background: rgba(255,255,255,.22); color: #fff; }

    /* ── Filters ── */
    .ss-filters { display: flex; gap: 9px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
    .ss-field { display: flex; align-items: center; gap: 7px; padding: 0 11px; height: 38px; background: #fff; border: 1px solid var(--gray-200); border-radius: 10px; }
    .ss-field.ss-grow { flex: 1; min-width: 220px; }
    .ss-field:focus-within { border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0,135,207,.13); }
    .ss-field svg { width: 15px; height: 15px; color: var(--gray-400); flex: none; }
    .ss-field label { font-size: 12px; color: var(--gray-400); font-weight: 600; }
    .ss-field input, .ss-field select { border: 0; outline: 0; background: none; font-family: inherit; font-size: 13px; color: var(--gray-700); min-width: 0; }
    .ss-field input[type="search"] { width: 100%; }
    .ss-apply { padding: 0 18px; height: 38px; border: 0; border-radius: 10px; background: var(--mint); color: #fff; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
    .ss-apply:hover { background: #0868AD; }
    .ss-clear { font-size: 12.5px; font-weight: 600; color: var(--gray-500); text-decoration: none; }
    .ss-clear:hover { color: #A6301F; }

    /* ── List ── */
    .ss-list { display: flex; flex-direction: column; gap: 10px; }
    .ss-row {
        display: flex; align-items: center; gap: 16px; padding: 14px 16px;
        background: #fff; border: 1px solid var(--gray-100); border-radius: 14px;
        box-shadow: 0 1px 2px rgba(16,24,40,.04);
    }
    .ss-row.on { border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0,135,207,.12); }

    .ss-date { flex: none; width: 60px; text-align: center; padding: 8px 0; border-radius: 11px; background: var(--gray-50, #F7F8FA); }
    .ss-date span { display: block; font-size: 10.5px; font-weight: 700; letter-spacing: .06em; color: var(--gray-400); }
    .ss-date b { display: block; font-size: 20px; font-weight: 700; color: var(--forest); line-height: 1.1; }

    .ss-what { flex: 1.3; min-width: 0; }
    .ss-title { font-size: 14px; font-weight: 700; color: var(--forest); }
    .ss-ref { font-size: 11.5px; color: var(--gray-400); font-variant-numeric: tabular-nums; margin-top: 1px; }
    .ss-tags { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 6px; }
    .ss-tag { padding: 2px 9px; border-radius: 999px; background: #EAF1FB; color: #1A5C9A; font-size: 11px; font-weight: 600; }
    .ss-meta { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 6px; font-size: 11.5px; color: var(--gray-500); }
    .ss-meta span { display: inline-flex; align-items: center; gap: 5px; }
    .ss-meta svg { width: 13px; height: 13px; color: var(--gray-400); }

    .ss-people { flex: 1.4; min-width: 0; display: flex; align-items: center; gap: 10px; }
    .ss-who { display: flex; align-items: center; gap: 9px; min-width: 0; flex: 1; }
    .ss-who b { display: block; font-size: 13px; font-weight: 600; color: var(--gray-800); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ss-who-r { display: block; font-size: 11.5px; color: var(--gray-400); }
    .ss-who-s { font-size: 11.5px; color: #B7791F; font-weight: 600; }
    .ss-swap { flex: none; color: var(--gray-300); }
    .ss-swap svg { width: 16px; height: 16px; }
    .ss-av {
        flex: none; width: 34px; height: 34px; border-radius: 50%; overflow: hidden;
        display: grid; place-items: center; background: #E7F0FB; color: #1E4E86;
        font-size: 11.5px; font-weight: 700;
    }
    .ss-av img { width: 100%; height: 100%; object-fit: cover; }

    .ss-when { flex: none; width: 170px; display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; color: var(--gray-600); }
    .ss-when span { display: inline-flex; align-items: center; gap: 6px; }
    .ss-when svg { width: 13px; height: 13px; color: var(--gray-400); flex: none; }
    .ss-dur { font-size: 11.5px; color: var(--gray-400); padding-left: 19px; }

    .ss-end { flex: none; width: 132px; display: flex; flex-direction: column; align-items: stretch; gap: 8px; }
    .ss-pill { display: inline-block; text-align: center; padding: 4px 11px; border-radius: 999px; font-size: 11.5px; font-weight: 700; }
    .ss-view {
        display: block; text-align: center; padding: 8px 12px; border: 1px solid var(--gray-200);
        border-radius: 9px; font-size: 12.5px; font-weight: 600; color: var(--gray-700); text-decoration: none;
    }
    .ss-view:hover { border-color: var(--mint); color: var(--mint-deep, #00539B); }

    .ss-foot { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin-top: 14px; font-size: 12.5px; color: var(--gray-500); }

    .ss-empty { padding: 54px 20px; text-align: center; background: #fff; border: 1px solid var(--gray-100); border-radius: 14px; }
    .ss-empty svg { width: 40px; height: 40px; color: var(--gray-300); }
    .ss-empty p { margin: 10px 0 0; font-size: 13.5px; color: var(--gray-400); }

    /* ── Detail panel ── */
    .ss-panel {
        flex: none; width: 380px; position: sticky; top: 0; max-height: calc(100vh - 96px);
        display: flex; flex-direction: column; background: #fff;
        border: 1px solid var(--gray-100); border-radius: 16px; box-shadow: 0 8px 28px -14px rgba(16,24,40,.24);
    }
    .ss-panel-hd { display: flex; align-items: center; justify-content: space-between; padding: 15px 18px; border-bottom: 1px solid var(--gray-100); }
    .ss-panel-hd b { font-size: 15px; font-weight: 700; color: var(--forest); }
    .ss-x { font-size: 22px; line-height: 1; color: var(--gray-400); text-decoration: none; }
    .ss-x:hover { color: var(--gray-800); }
    .ss-panel-body { padding: 16px 18px 20px; overflow-y: auto; }
    .ss-panel-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 9px; }
    .ss-panel-title { margin: 0; font-size: 19px; font-weight: 700; color: var(--forest); letter-spacing: -.01em; }
    .ss-panel-sub { margin: 3px 0 0; font-size: 13px; color: var(--gray-400); }

    .ss-kv { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 14px; margin: 16px 0; padding: 14px 0; border-top: 1px solid var(--gray-100); border-bottom: 1px solid var(--gray-100); }
    .ss-k { display: block; font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; font-weight: 700; color: var(--gray-400); }
    .ss-v { display: block; font-size: 13px; color: var(--gray-800); margin-top: 2px; }

    .ss-note { padding: 11px 13px; border-radius: 11px; background: var(--gray-50, #F7F8FA); font-size: 13px; color: var(--gray-600); line-height: 1.55; margin-bottom: 14px; }
    .ss-note b { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--gray-400); margin-bottom: 3px; }

    .ss-h3 { margin: 18px 0 10px; font-size: 13.5px; font-weight: 700; color: var(--forest); }

    /* Sessions the missed-session job should have closed by now. */
    .ss-stale { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap;
                margin: -4px 0 16px; padding: 13px 15px; border: 1px solid #EBD9A6; border-radius: 12px; background: #FBF0D4; }
    .ss-stale-txt { flex: 1 1 320px; min-width: 0; font-size: 13px; line-height: 1.55; color: #6B4F00; }
    .ss-stale-txt b { display: block; font-size: 13.5px; color: #5A4200; margin-bottom: 2px; }
    .ss-stale-txt a { color: #5A4200; font-weight: 600; }
    .ss-stale form { margin: 0; }
    .ss-stale .ss-act { background: #fff; width: auto; }
    .ss-none { margin: 0; font-size: 12.5px; color: var(--gray-400); }

    .ss-parts { display: flex; flex-direction: column; gap: 9px; }
    .ss-part { display: flex; align-items: center; gap: 11px; padding: 11px 13px; border: 1px solid var(--gray-100); border-radius: 12px; text-decoration: none; }
    .ss-part:hover { border-color: var(--mint); }
    .ss-part b { display: block; font-size: 13px; font-weight: 600; color: var(--gray-800); }
    .ss-part-r { font-size: 11.5px; color: var(--gray-400); }
    .ss-part-s { font-size: 11.5px; color: #B7791F; font-weight: 600; margin-left: 7px; }
    .ss-part-c { display: block; font-size: 11.5px; color: var(--gray-400); margin-top: 2px; }

    .ss-fb { padding: 13px 14px; border: 1px solid var(--gray-100); border-radius: 12px; margin-bottom: 10px; }
    .ss-fb-hd { display: flex; justify-content: space-between; gap: 8px; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; font-weight: 700; color: var(--gray-400); }
    .ss-fb-when { text-transform: none; letter-spacing: 0; font-weight: 500; }
    .ss-fb-score { margin: 6px 0 8px; font-size: 13px; color: var(--gray-500); }
    .ss-fb-score b { font-size: 20px; font-weight: 700; color: var(--forest); }
    .ss-stars { color: #E5A800; margin-left: 6px; letter-spacing: 1px; }
    .ss-fb-txt { margin: 0 0 10px; font-size: 13px; line-height: 1.55; color: var(--gray-700); }
    .ss-fb-by { margin-top: 8px; font-size: 11.5px; color: var(--gray-400); }
    .ss-bar { display: flex; align-items: center; gap: 9px; font-size: 12px; color: var(--gray-600); margin-bottom: 5px; }
    .ss-bar > span:first-child { width: 92px; flex: none; }
    .ss-bar-t { flex: 1; height: 6px; border-radius: 999px; background: var(--gray-100); overflow: hidden; }
    .ss-bar-t i { display: block; height: 100%; background: var(--mint); }
    .ss-bar-n { flex: none; font-variant-numeric: tabular-nums; color: var(--gray-400); }

    .ss-acts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
    .ss-acts form { margin: 0; }
    .ss-act {
        width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 7px;
        padding: 10px 12px; border: 1px solid var(--gray-200); border-radius: 10px; background: #fff;
        font-family: inherit; font-size: 12.5px; font-weight: 600; color: var(--gray-700);
        text-decoration: none; cursor: pointer;
    }
    .ss-act:hover { border-color: var(--mint); color: var(--mint-deep, #00539B); }
    .ss-act svg { width: 15px; height: 15px; flex: none; }
    .ss-act.ok { color: #17654B; border-color: #C4E5D6; }
    .ss-act.ok:hover { background: #E6F5EE; }
    .ss-act.warn { color: #9A7100; border-color: #EBD9A6; }
    .ss-act.warn:hover { background: #FBF0D4; }
    .ss-act.danger { color: #A6301F; border-color: #F3C9C0; }
    .ss-act.danger:hover { background: #FBE5E1; }
    .ss-act.solid { color: #fff; }
    .ss-act.ok.solid { background: #17654B; border-color: #17654B; }
    .ss-act.warn.solid { background: #9A7100; border-color: #9A7100; }
    .ss-act.danger.solid { background: #A6301F; border-color: #A6301F; }

    /* ── Dialogs ── */
    .ss-overlay { display: none; position: fixed; inset: 0; z-index: 900; background: rgba(2,5,71,.42); align-items: center; justify-content: center; padding: 20px; }
    .ss-overlay.open { display: flex; }
    .ss-modal { width: min(440px, 100%); background: #fff; border-radius: 16px; padding: 22px 24px; box-shadow: 0 24px 60px -20px rgba(16,24,40,.4); }
    .ss-modal h3 { margin: 0 0 6px; font-size: 18px; font-weight: 700; color: var(--forest); }
    .ss-modal p { margin: 0 0 16px; font-size: 13.5px; color: var(--gray-500); }
    .ss-modal label { display: block; font-size: 12.5px; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; }
    .ss-modal textarea, .ss-modal input, .ss-modal select {
        width: 100%; padding: 10px 12px; border: 1px solid var(--gray-200); border-radius: 10px;
        font-family: inherit; font-size: 13.5px; margin-bottom: 14px; outline: none; resize: vertical;
    }
    .ss-modal textarea:focus, .ss-modal input:focus, .ss-modal select:focus { border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0,135,207,.13); }
    .ss-modal-foot { display: flex; gap: 10px; justify-content: flex-end; }
    .ss-modal-foot .ss-act { width: auto; }
    .ss-cancel { padding: 10px 18px; border-radius: 10px; border: 1px solid var(--gray-200); background: #fff; color: var(--gray-600); font-family: inherit; font-size: 13px; cursor: pointer; }

    /* ── Cards used by the reports screen ── */
    .ss-card { background: #fff; border: 1px solid var(--gray-100); border-radius: 14px; padding: 16px 18px; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
    .ss-card h2 { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 0 0 14px; font-size: 14.5px; font-weight: 700; color: var(--forest); }
    .ss-card h2 a { font-size: 12.5px; font-weight: 600; color: var(--mint); text-decoration: none; }

    @media (max-width: 1400px) {
        .ss-wrap.has-panel { flex-direction: column; }
        .ss-wrap.has-panel .ss-panel { width: 100%; position: static; max-height: none; }
    }
    @media (max-width: 1180px) {
        .ss-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .ss-people, .ss-when { display: none; }
    }
    @media (max-width: 700px) {
        .ss-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .ss-stat { flex-direction: row; align-items: center; gap: 10px; padding: 12px 14px; }
        .ss-stat-ico { width: 34px; height: 34px; }
        .ss-stat-ico svg { width: 15px; height: 15px; }
        .ss-stat-v { font-size: 18px; }
        .ss-stat-k { font-size: 10px; text-transform: none; letter-spacing: 0; line-height: 1.2; }
        .ss-stat-s { display: none; }
        .ss-what { flex-basis: 100%; }
        .ss-row { flex-wrap: wrap; }
        .ss-end { width: 100%; flex-direction: row; }
        .ss-end .ss-view { flex: 1; }
        .ss-acts { grid-template-columns: 1fr; }
    }
</style>
