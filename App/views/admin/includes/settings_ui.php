<?php

/**
 * settings_ui.php — the shell every System Settings page sits in.
 *
 * Seven screens that are all "read some state, change some of it", so the
 * sub-nav, the card, the field and the toggle are defined once here rather
 * than seven times.
 */

if (defined('AD_SETTINGS_UI')) return;
define('AD_SETTINGS_UI', true);

require_once __DIR__ . '/sessions_ui.php';

/** The sub-navigation, and which route each tab is. */
function st_tabs(): array
{
    return [
        'admin-settings'            => 'General',
        'admin-settings-security'   => 'Security',
        'admin-settings-email'      => 'Email & Notifications',
        'admin-settings-appearance' => 'Appearance',
        'admin-settings-integrations' => 'Integrations',
        'admin-settings-backup'     => 'Backup & Restore',
        'admin-settings-logs'       => 'Activity Logs',
    ];
}

/** Header, breadcrumb and sub-nav. Call once per settings page. */
function st_header(string $route, string $title, string $blurb, string $actions = ''): void
{
    ?>
    <div class="st-crumb">
        <a href="<?= url('admin-settings') ?>">System Settings</a>
        <?php if ($route !== 'admin-settings'): ?>
            <span>›</span><b><?= htmlspecialchars(st_tabs()[$route] ?? '') ?></b>
        <?php endif; ?>
    </div>
    <div class="ss-hd">
        <div>
            <h1><?= htmlspecialchars($title) ?></h1>
            <p><?= htmlspecialchars($blurb) ?></p>
        </div>
        <?php if ($actions !== ''): ?><div class="ss-hd-actions"><?= $actions ?></div><?php endif; ?>
    </div>
    <div class="st-tabs">
        <?php foreach (st_tabs() as $r => $label): ?>
            <a class="st-tab <?= $r === $route ? 'on' : '' ?>" href="<?= url($r) ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
    </div>
    <?php
}

/** A labelled switch that posts a 0 when off — checkboxes send nothing. */
function st_toggle(string $name, string $label, string $help, bool $on, bool $disabled = false, string $note = ''): void
{
    $id = 'sw_' . $name;
    ?>
    <div class="st-sw<?= $disabled ? ' off' : '' ?>">
        <div style="min-width:0;flex:1;">
            <label for="<?= $id ?>"><?= htmlspecialchars($label) ?></label>
            <p><?= htmlspecialchars($help) ?><?= $note !== '' ? ' <em>' . htmlspecialchars($note) . '</em>' : '' ?></p>
        </div>
        <?php if (!$disabled): ?><input type="hidden" name="<?= htmlspecialchars($name) ?>" value="0"><?php endif; ?>
        <input class="st-sw-in" id="<?= $id ?>" type="checkbox" name="<?= htmlspecialchars($name) ?>" value="1"
               <?= $on ? 'checked' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
        <label class="st-sw-track" for="<?= $id ?>" aria-hidden="true"></label>
    </div>
    <?php
}
?>

<style>
    .st-crumb { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--gray-400); margin-bottom: 6px; }
    .st-crumb a { color: var(--gray-500); text-decoration: none; font-weight: 600; }
    .st-crumb a:hover { color: var(--mint); }
    .st-crumb b { color: var(--forest); font-weight: 600; }

    .st-tabs { display: flex; gap: 4px; flex-wrap: wrap; border-bottom: 1px solid var(--gray-200); margin-bottom: 18px; }
    .st-tab { padding: 10px 15px; font-size: 13px; font-weight: 600; color: var(--gray-500);
              text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; }
    .st-tab:hover { color: var(--forest); }
    .st-tab.on { color: var(--mint); border-bottom-color: var(--mint); }

    .st-grid { display: grid; grid-template-columns: minmax(0, 1fr) 330px; gap: 14px; align-items: start; }
    .st-cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; align-items: start; }
    .st-stack { display: flex; flex-direction: column; gap: 14px; }

    .st-card { background: #fff; border: 1px solid var(--gray-100); border-radius: 14px; padding: 18px 20px;
               box-shadow: 0 1px 2px rgba(16,24,40,.04); }
    .st-card h2 { margin: 0 0 3px; font-size: 15px; font-weight: 700; color: var(--forest); }
    .st-card > p.sub { margin: 0 0 16px; font-size: 12.5px; color: var(--gray-400); }

    .st-f { margin-bottom: 13px; }
    .st-f label { display: block; font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; }
    .st-f input[type=text], .st-f input[type=email], .st-f input[type=url], .st-f input[type=number],
    .st-f input[type=color], .st-f select, .st-f textarea {
        width: 100%; padding: 10px 12px; border: 1px solid var(--gray-200); border-radius: 10px;
        font-family: inherit; font-size: 13.5px; color: var(--gray-800); background: #fff; outline: none; resize: vertical;
    }
    .st-f input:focus, .st-f select:focus, .st-f textarea:focus { border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0,135,207,.13); }
    .st-f input[type=color] { padding: 4px; height: 40px; cursor: pointer; }
    .st-f small { display: block; margin-top: 4px; font-size: 11.5px; color: var(--gray-400); }
    .st-f-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }

    /* Switches */
    .st-sw { display: flex; align-items: center; gap: 14px; padding: 12px 0; border-top: 1px solid var(--gray-100); }
    .st-sw:first-of-type { border-top: 0; padding-top: 0; }
    .st-sw label:first-child, .st-sw > div > label { display: block; font-size: 13.5px; font-weight: 600; color: var(--gray-800); cursor: pointer; }
    .st-sw p { margin: 2px 0 0; font-size: 12px; color: var(--gray-400); line-height: 1.5; }
    .st-sw p em { font-style: normal; color: #B7791F; }
    .st-sw.off { opacity: .6; }
    .st-sw-in { position: absolute; opacity: 0; pointer-events: none; }
    .st-sw-track { flex: none; width: 42px; height: 24px; border-radius: 999px; background: var(--gray-200);
                   position: relative; cursor: pointer; transition: background .16s; }
    .st-sw-track::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px;
                          border-radius: 50%; background: #fff; transition: transform .16s; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
    .st-sw-in:checked + .st-sw-track { background: #1B6FD1; }
    .st-sw-in:checked + .st-sw-track::after { transform: translateX(18px); }
    .st-sw-in:disabled + .st-sw-track { cursor: not-allowed; opacity: .55; }
    .st-sw-in:focus-visible + .st-sw-track { outline: 2px solid var(--mint); outline-offset: 2px; }

    .st-save { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border: 0; border-radius: 11px;
               background: var(--forest); color: #fff; font-family: inherit; font-size: 13.5px; font-weight: 600; cursor: pointer; }
    .st-save:hover { background: #0B1440; }
    .st-save svg { width: 15px; height: 15px; }
    .st-foot { display: flex; justify-content: flex-end; gap: 9px; margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--gray-100); }

    /* Status rows used by Integrations / Health */
    .st-row { display: flex; align-items: center; gap: 11px; padding: 11px 0; border-top: 1px solid var(--gray-100); font-size: 13px; }
    .st-row:first-of-type { border-top: 0; }
    .st-row b { display: block; font-size: 13px; font-weight: 600; color: var(--gray-800); }
    .st-row span.h { display: block; font-size: 11.5px; color: var(--gray-400); }
    .st-row .st-badge { margin-left: auto; }
    .st-badge { padding: 3px 11px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .st-dot { width: 34px; height: 34px; flex: none; border-radius: 10px; display: grid; place-items: center; }
    .st-dot svg { width: 17px; height: 17px; }

    .st-note { padding: 12px 14px; border-radius: 11px; background: #EAF1FB; color: #1A5C9A;
               font-size: 12.5px; line-height: 1.6; margin-bottom: 14px; }
    .st-note.warn { background: #FEF6DC; color: #7A5A00; }
    .st-note.bad { background: #FBE5E1; color: #7C2417; }
    .st-note b { display: block; margin-bottom: 2px; }

    @media (max-width: 1240px) { .st-grid { grid-template-columns: minmax(0, 1fr); } }
    @media (max-width: 860px) { .st-cols, .st-f-row { grid-template-columns: minmax(0, 1fr); } }
</style>
