<?php

/**
 * credits_ui.php — the Credits & Developers page itself, drawn once.
 *
 * Both copies of this page are the same page: the administrator opens it from
 * System Settings, a mentor or mentee from their own Settings, and they see
 * the same thing. Only the picture controls differ, and only an administrator
 * gets those. Written here rather than twice so the two cannot drift.
 *
 * Expects, from whichever page included it:
 *   $team       from pc_credits_team()
 *   $project    from pc_credits_project()
 *   $backUrl    where the arrow goes — the Settings page it was opened from
 *   $canEdit    true for an administrator: draws the picture controls
 *   $uploadUrl  where those post to  (only read when $canEdit)
 *   $csrf       (only read when $canEdit)
 *
 * The tokens used below (--forest, --primary, --gray-*) are defined by both
 * stylesheets, so this renders identically inside the member shell and inside
 * the admin layout without either needing to know about the other.
 */

$cr_icon = function (string $name): string {
    $paths = [
        'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path stroke-linecap="round" d="M8 3v4M16 3v4M3.5 10h17"/>',
        'globe'    => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17"/><path d="M12 3.5c2.2 2.3 3.4 5.3 3.4 8.5S14.2 18.2 12 20.5c-2.2-2.3-3.4-5.3-3.4-8.5S9.8 5.8 12 3.5Z"/>',
        'school'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 4 3 8.5l9 4.5 9-4.5L12 4Z"/><path stroke-linecap="round" d="M6.5 11v5c0 1.4 2.5 2.5 5.5 2.5s5.5-1.1 5.5-2.5v-5M20 9v5"/>',
        'people'   => '<circle cx="9" cy="9.5" r="3"/><path stroke-linecap="round" d="M3.5 19c.6-2.8 2.8-4.3 5.5-4.3s4.9 1.5 5.5 4.3"/><path stroke-linecap="round" d="M16 7.1a3 3 0 0 1 0 5.8M17.5 19c-.2-1.2-.6-2.2-1.2-3"/>',
        'back'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M19 12H5m0 0 6-6m-6 6 6 6"/>',
        'camera'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 8.5h3l1.5-2h7L17 8.5h3a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13" r="3"/>',
        'code'     => '<path stroke-linecap="round" stroke-linejoin="round" d="m9 8-4 4 4 4m6-8 4 4-4 4"/>',
        'window'   => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path stroke-linecap="round" d="M3.5 9h17M7 6.8h.01M9.5 6.8h.01"/>',
        'server'   => '<rect x="3.5" y="4.5" width="17" height="6" rx="2"/><rect x="3.5" y="13.5" width="17" height="6" rx="2"/><path stroke-linecap="round" d="M7 7.5h.01M7 16.5h.01"/>',
        'database' => '<ellipse cx="12" cy="6.5" rx="7.5" ry="3"/><path d="M4.5 6.5v11c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-11"/><path d="M4.5 12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3"/>',
        'layers'   => '<path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8.5 4.5L12 12 3.5 7.5 12 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m3.5 12.5 8.5 4.5 8.5-4.5"/>',
        'box'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5 20 8v8l-8 4.5L4 16V8l8-4.5Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M4 8l8 4.5L20 8M12 12.5V20.5"/>',
        'plug'     => '<path stroke-linecap="round" d="M9 3v5M15 3v5"/><path stroke-linecap="round" stroke-linejoin="round" d="M6.5 8h11v3a5.5 5.5 0 0 1-11 0V8ZM12 16.5V21"/>',
        'cloud'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M7 18a4 4 0 0 1-.4-8A5.5 5.5 0 0 1 17.4 11 3.5 3.5 0 0 1 17 18H7Z"/>',
        'wrench'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.3a4 4 0 0 0 5.1 5.1l-8.4 8.4a2.3 2.3 0 0 1-3.2-3.2l8.4-8.4a4 4 0 0 0-1.9-1.9Z"/>',
    ];
    return '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">'
        . ($paths[$name] ?? '') . '</svg>';
};

/** The icon that goes with each technology group. */
$cr_group_icon = function (string $group): string {
    return [
        'Frontend'     => 'window',
        'Backend'      => 'server',
        'Database'     => 'database',
        'Framework'    => 'layers',
        'Libraries'    => 'box',
        'Integrations' => 'plug',
        'Platform'     => 'cloud',
        'Tools'        => 'wrench',
    ][$group] ?? 'code';
};
?>

<style>
    /* No padding of its own: both shells already pad their content area —
       the member <main> with 36px/48px, the admin one with Tailwind's p-6 —
       and adding more here would sit this page further in than every other. */
    .cr-wrap {
        max-width: 960px;
        margin: 0 auto;
    }

    .cr-hd {
        display: flex;
        align-items: flex-start;
        gap: 12px;
    }

    .cr-back {
        flex: none;
        display: grid;
        place-items: center;
        width: 34px;
        height: 34px;
        margin-top: 4px;
        border-radius: 9px;
        color: var(--forest);
        text-decoration: none;
        transition: background .15s;
    }

    .cr-back svg { width: 21px; height: 21px; }
    .cr-back:hover { background: rgba(8, 104, 173, .09); }

    .cr-hd h1 {
        margin: 0;
        font-size: 29px;
        font-weight: 800;
        line-height: 1.15;
        color: var(--forest);
        text-wrap: balance;
    }

    .cr-hd p {
        margin: 5px 0 0;
        font-size: 13.5px;
        color: var(--gray-600);
    }

    .cr-rule {
        height: 1px;
        margin: 20px 0 22px;
        background: var(--gray-100);
    }

    /* The small uppercase label above each band. */
    .cr-label {
        margin: 0;
        font-size: 11.5px;
        font-weight: 700;
        letter-spacing: .09em;
        text-transform: uppercase;
        color: var(--gray-600);
    }

    .cr-label+p {
        margin: 6px 0 14px;
        font-size: 13.5px;
        color: var(--gray-600);
    }

    .cr-band { margin-bottom: 28px; }

    /* ── The people ─────────────────────────────────────────────────── */

    .cr-team {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 16px;
    }

    .cr-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 24px 16px 20px;
        background: #fff;
        border: 1px solid var(--gray-100);
        border-radius: 14px;
    }

    .cr-face {
        display: grid;
        place-items: center;
        width: 104px;
        height: 104px;
        margin-bottom: 15px;
        border-radius: 50%;
        overflow: hidden;
        background: #EAF1FB;
        color: #1A5C9A;
        font-size: 30px;
        font-weight: 700;
        letter-spacing: .02em;
    }

    .cr-face img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .cr-name {
        font-size: 15.5px;
        font-weight: 700;
        color: var(--forest);
        line-height: 1.3;
    }

    .cr-role {
        margin-top: 5px;
        font-size: 13px;
        color: var(--gray-600);
    }

    .cr-tags {
        margin-top: 11px;
        font-size: 12.5px;
        color: var(--primary);
    }

    .cr-tags span+span::before {
        content: "•";
        margin: 0 7px;
        color: var(--gray-400);
    }

    /* ── Changing a picture (administrators only) ───────────────────── */

    /*
     * Stacked, and pinned to the bottom of the card.
     *
     * Side by side, the file box was down to about forty pixels in a
     * four-across row and showed "N...en" instead of a filename. And without
     * margin-top:auto the row sat directly under the tags, so the one card
     * whose tags wrap to two lines had its button lower than the other three.
     */
    .cr-upload {
        display: flex;
        flex-direction: column;
        gap: 8px;
        width: 100%;
        margin-top: auto;
        padding-top: 14px;
        border-top: 1px solid var(--gray-100);
    }

    .cr-file {
        min-width: 0;
        font-size: 11.5px;
        color: var(--gray-600);
        text-align: left;
    }

    .cr-file::file-selector-button {
        margin-right: 8px;
        padding: 6px 10px;
        border: 1px solid var(--gray-100);
        border-radius: 8px;
        background: #F7F9FC;
        color: var(--forest);
        font: inherit;
        font-size: 11.5px;
        cursor: pointer;
    }

    .cr-file::file-selector-button:hover { background: #EAF1FB; }

    .cr-save {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        padding: 8px 12px;
        border: 0;
        border-radius: 8px;
        background: var(--primary);
        color: #fff;
        font: inherit;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
    }

    .cr-save svg { width: 14px; height: 14px; }
    .cr-save:hover { background: var(--forest); }

    .cr-note {
        margin: 10px 0 0;
        font-size: 12px;
        color: var(--gray-600);
    }

    /* ── The project ────────────────────────────────────────────────── */

    .cr-project {
        display: flex;
        align-items: flex-start;
        gap: 18px;
        flex-wrap: wrap;
        padding: 20px;
        background: #fff;
        border: 1px solid var(--gray-100);
        border-radius: 14px;
    }

    .cr-mark {
        flex: none;
        display: grid;
        place-items: center;
        width: 54px;
        height: 54px;
        border-radius: 50%;
        background: var(--primary);
        color: #fff;
    }

    .cr-mark svg { width: 27px; height: 27px; }

    .cr-about {
        flex: 1 1 260px;
        min-width: 0;
    }

    .cr-about h2 {
        margin: 0;
        font-size: 21px;
        font-weight: 800;
        color: var(--forest);
    }

    .cr-about .cr-tagline {
        margin: 3px 0 0;
        font-size: 13.5px;
        color: var(--gray-600);
    }

    .cr-about .cr-blurb {
        margin: 12px 0 0;
        font-size: 13px;
        line-height: 1.55;
        color: var(--gray-600);
        max-width: 42ch;
    }

    .cr-facts {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
        flex: 1 1 320px;
    }

    .cr-fact {
        padding: 12px 6px;
        text-align: center;
        background: #EAF1FB;
        border-radius: 11px;
    }

    .cr-fact svg {
        width: 19px;
        height: 19px;
        color: #1A5C9A;
    }

    .cr-fact b {
        display: block;
        margin-top: 6px;
        font-size: 13.5px;
        font-weight: 700;
        color: var(--forest);
    }

    .cr-fact span {
        display: block;
        margin-top: 2px;
        font-size: 11.5px;
        color: var(--gray-600);
    }

    /* ── Technologies ───────────────────────────────────────────────── */

    /* Two columns of groups on a wide screen, one on a narrow one — a single
       column of eight short rows left most of the page empty. */
    .cr-stack {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 14px;
    }

    .cr-group {
        padding: 14px 16px 16px;
        background: #fff;
        border: 1px solid var(--gray-100);
        border-radius: 12px;
    }

    .cr-group-name {
        display: flex;
        align-items: center;
        gap: 7px;
        margin: 0 0 11px;
        font-size: 12.5px;
        font-weight: 700;
        color: var(--forest);
    }

    .cr-group-name svg { width: 16px; height: 16px; color: var(--primary); }

    .cr-tech {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
    }

    .cr-chip {
        display: inline-flex;
        align-items: center;
        padding: 6px 11px;
        background: #F7F9FC;
        border: 1px solid var(--gray-100);
        border-radius: 8px;
        font-size: 12px;
        font-weight: 600;
        color: var(--forest);
    }

    /* ── Foot ───────────────────────────────────────────────────────── */

    .cr-foot {
        margin-top: 30px;
        padding-top: 18px;
        border-top: 1px solid var(--gray-100);
        text-align: center;
        font-size: 12.5px;
        color: var(--gray-600);
    }

    .cr-foot b { color: var(--forest); }

    @media (max-width: 640px) {
        .cr-hd h1 { font-size: 23px; }
        .cr-team { grid-template-columns: 1fr 1fr; gap: 12px; }
        .cr-card { padding: 18px 10px 16px; }
        .cr-face { width: 78px; height: 78px; font-size: 23px; }
        .cr-facts { grid-template-columns: 1fr 1fr; }
    }

    @media (max-width: 380px) {
        .cr-team { grid-template-columns: 1fr; }
    }
</style>

<div class="cr-wrap">

    <div class="cr-hd">
        <a class="cr-back" href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" aria-label="Back to Settings">
            <?= $cr_icon('back') ?>
        </a>
        <div>
            <h1>Credits &amp; Developers</h1>
            <p>Meet the team behind <?= htmlspecialchars($project['name']) ?>.</p>
        </div>
    </div>

    <div class="cr-rule"></div>

    <div class="cr-band">
        <p class="cr-label">The Team</p>
        <p>The people behind the design and development of <?= htmlspecialchars($project['name']) ?>.</p>

        <div class="cr-team">
            <?php foreach ($team as $person): ?>
                <div class="cr-card">
                    <div class="cr-face"><?= pc_avatar($person['photo'], $person['name']) ?></div>
                    <div class="cr-name"><?= htmlspecialchars($person['name']) ?></div>
                    <div class="cr-role"><?= htmlspecialchars($person['role']) ?></div>
                    <?php if ($person['tags']): ?>
                        <div class="cr-tags">
                            <?php foreach ($person['tags'] as $t): ?>
                                <span><?= htmlspecialchars($t) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($canEdit): ?>
                        <form class="cr-upload" method="post" enctype="multipart/form-data"
                              action="<?= htmlspecialchars($uploadUrl, ENT_QUOTES) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                            <input type="hidden" name="slug" value="<?= htmlspecialchars($person['slug'], ENT_QUOTES) ?>">
                            <input class="cr-file" type="file" name="photo" required
                                   accept="image/jpeg,image/png,image/gif,image/webp"
                                   aria-label="Picture for <?= htmlspecialchars($person['name'], ENT_QUOTES) ?>">
                            <button class="cr-save" type="submit"><?= $cr_icon('camera') ?> Save</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($canEdit): ?>
            <p class="cr-note">
                JPG, PNG, GIF or WEBP, up to 5&nbsp;MB. A square picture keeps its whole face
                inside the circle. Until one is uploaded the card shows initials.
            </p>
        <?php endif; ?>
    </div>

    <div class="cr-band">
        <p class="cr-label">Project</p>
        <div class="cr-project">
            <div class="cr-mark"><?= $cr_icon('people') ?></div>
            <div class="cr-about">
                <h2><?= htmlspecialchars($project['name']) ?></h2>
                <p class="cr-tagline"><?= htmlspecialchars($project['tagline']) ?></p>
                <p class="cr-blurb"><?= htmlspecialchars($project['about']) ?></p>
            </div>
            <div class="cr-facts">
                <?php foreach ($project['facts'] as $f): ?>
                    <div class="cr-fact">
                        <?= $cr_icon($f['icon']) ?>
                        <b><?= htmlspecialchars($f['value']) ?></b>
                        <span><?= htmlspecialchars($f['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="cr-band">
        <p class="cr-label">Technologies</p>
        <p>What each part of <?= htmlspecialchars($project['name']) ?> is built with.</p>

        <div class="cr-stack">
            <?php foreach ($project['tech'] as $group => $items): ?>
                <div class="cr-group">
                    <p class="cr-group-name"><?= $cr_icon($cr_group_icon($group)) ?><?= htmlspecialchars($group) ?></p>
                    <div class="cr-tech">
                        <?php foreach ($items as $t): ?>
                            <span class="cr-chip"><?= htmlspecialchars($t) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="cr-foot">
        <b><?= htmlspecialchars($project['name']) ?></b> &middot; <?= htmlspecialchars($project['tagline']) ?><br>
        &copy; <?= date('Y') ?>
    </div>

</div>
