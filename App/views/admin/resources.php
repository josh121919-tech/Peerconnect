<?php

/**
 * admin/resources.php — what is in the resource library.
 *
 * Mostly a monitoring screen: the library is filled by mentors from their own
 * Resources page, and this is where an admin sees what arrived, who posted it,
 * and how much it is being used. It carries two actions — adding a resource,
 * which admins are permitted to do (resources/upload.php accepts their role)
 * and had no route to from their own navigation, and taking one down, which is
 * the thing an admin needs that a mentor cannot do for somebody else's file.
 *
 * Figures are counts, never estimates. There is no "downloads this week"
 * anywhere on this page because the schema keeps a running total rather than
 * one row per download, so that number does not exist to be shown.
 *
 * SECURITY: admin-only, prepared statements.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$get = fn(string $n): string => is_string($_GET[$n] ?? null) ? $_GET[$n] : '';

$q    = trim($get('q'));
$club = in_array($get('club'), PC_CLUBS, true) ? $get('club') : '';
$type = in_array($get('type'), ['pdf', 'docx'], true) ? $get('type') : '';
$sort = in_array($get('sort'), ['recent', 'downloads', 'title'], true) ? $get('sort') : 'recent';
$view = $get('view') === 'list' ? 'list' : 'grid';

$perPage = 12;
$page    = max(1, (int)$get('page'));

/* ── The list ─────────────────────────────────────────────────────────── */
$where = ['r.is_active = 1'];
$types = '';
$args  = [];

if ($q !== '') {
    $where[] = "CONCAT_WS(' ', r.title, r.description, r.original_name, u.firstname, u.lastname) LIKE ?";
    $types  .= 's';
    $args[]  = '%' . $q . '%';
}
if ($club !== '') { $where[] = 'r.club = ?';      $types .= 's'; $args[] = $club; }
if ($type !== '') { $where[] = 'r.file_type = ?'; $types .= 's'; $args[] = $type; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);
$orderSQL = [
    'recent'    => 'r.created_at DESC',
    'downloads' => 'r.download_count DESC, r.created_at DESC',
    'title'     => 'r.title ASC',
][$sort];

$total      = (int)ResourceAdminRepository::countMatching($con, $whereSQL, $types, $args);
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$rows       = ResourceAdminRepository::page($con, $whereSQL, $orderSQL, $types, $args, $perPage, ($page - 1) * $perPage);

/* ── Headline figures, over the whole library rather than this page ───── */
$stats      = ResourceAdminRepository::figures($con);
$clubCounts = ResourceAdminRepository::clubCounts($con);

$filtering = $q !== '' || $club !== '' || $type !== '';

/** A link to this page with the current filters, and $over applied. */
$filters = ['q' => $q, 'club' => $club, 'type' => $type,
            'sort' => $get('sort') !== '' ? $sort : null,
            'view' => $view === 'list' ? 'list' : null,
            'page' => $get('page') !== '' ? $page : null];
$rs_url = function (array $over = []) use ($filters): string {
    $p = array_filter(array_merge($filters, $over), fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($p);
};

/** Bytes, as something readable. */
function rsa_size(int $b): string
{
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)    return round($b / 1024) . ' KB';
    return $b . ' B';
}

/** "3 new this week", or a plain note when nothing arrived. */
function rsa_trend(int $n, string $noun): string
{
    if ($n <= 0) {
        return '<span style="color:var(--gray-400);">None this week</span>';
    }
    return '<span class="ss-trend up">&uarr; ' . number_format($n) . ' new ' . $noun . ' this week</span>';
}

$csrf = csrf_token();
$current_page = 'admin-resources';
include 'layout.php';
// ss_icon(), .ss-stats, .ss-filters and the rest of the list chrome every
// other admin list uses. Included after layout.php, as the others do.
require_once __DIR__ . '/includes/sessions_ui.php';
?>

<style>
    /* ── Header ── */
    .rsa-hd { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; flex-wrap: wrap; margin-bottom: 20px; }
    .rsa-hd-l { display: flex; align-items: flex-start; gap: 14px; min-width: 0; }

    .rsa-hd-ico {
        flex: none; width: 52px; height: 52px; border-radius: 15px;
        display: grid; place-items: center;
        background: var(--mint-faint); color: var(--mint);
    }
    .rsa-hd-ico svg { width: 25px; height: 25px; }

    .rsa-hd h1 { margin: 0; font-size: 25px; font-weight: 700; color: var(--forest); letter-spacing: -.02em; }
    .rsa-hd p  { margin: 4px 0 0; font-size: 13.5px; color: var(--gray-400); max-width: 62ch; line-height: 1.5; }

    .rsa-add {
        display: inline-flex; align-items: center; gap: 8px; flex: none;
        padding: 0 18px; height: 42px; border: 0; border-radius: 11px;
        background: var(--primary); color: #fff; cursor: pointer;
        font-family: inherit; font-size: 13.5px; font-weight: 600;
    }
    .rsa-add:hover { background: var(--primary-2, #06527F); }

    /* ── Layout: the club rail beside the list ── */
    .rsa-layout { display: grid; grid-template-columns: 230px minmax(0, 1fr); gap: 16px; align-items: start; }

    .rsa-rail {
        background: #fff; border: 1px solid var(--stat-border); border-radius: var(--stat-radius);
        box-shadow: var(--stat-shadow); padding: 14px 12px;
    }
    .rsa-rail-t {
        font-size: 11px; font-weight: 700; color: var(--gray-400);
        text-transform: uppercase; letter-spacing: .08em; padding: 0 8px 10px;
    }
    .rsa-club {
        display: flex; align-items: center; gap: 9px; padding: 9px 10px; border-radius: 9px;
        font-size: 13px; color: var(--gray-600); text-decoration: none; margin-bottom: 1px;
    }
    .rsa-club svg { width: 16px; height: 16px; flex: none; color: var(--gray-400); }
    .rsa-club span.n { margin-left: auto; font-size: 11.5px; font-variant-numeric: tabular-nums; color: var(--gray-400); }
    .rsa-club b { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rsa-club:hover { background: var(--gray-50); color: var(--gray-800); }
    .rsa-club.on { background: var(--mint-faint); color: var(--mint-deep, #00539B); font-weight: 600; }
    .rsa-club.on svg, .rsa-club.on span.n { color: inherit; }

    /* ── Cards ── */
    .rsa-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 14px; }

    .rsa-card {
        display: flex; flex-direction: column; overflow: hidden;
        background: #fff; border: 1px solid var(--stat-border);
        border-radius: var(--stat-radius); box-shadow: var(--stat-shadow);
        transition: box-shadow .16s ease;
    }
    .rsa-card:hover { box-shadow: var(--stat-shadow-hover); }

    .rsa-thumb { position: relative; height: 84px; display: grid; place-items: center; }
    .rsa-thumb.pdf  { background: linear-gradient(135deg, #FDF0ED 0%, #FBE5E1 100%); }
    .rsa-thumb.docx { background: linear-gradient(135deg, #EDF3FC 0%, #E4EEFB 100%); }
    .rsa-thumb-b { width: 42px; height: 42px; border-radius: 12px; background: #fff; display: grid; place-items: center; box-shadow: 0 1px 3px rgba(16,40,70,.10); }
    .rsa-thumb-b svg { width: 21px; height: 21px; }
    .rsa-thumb.pdf  .rsa-thumb-b { color: #A6301F; }
    .rsa-thumb.docx .rsa-thumb-b { color: #1A5C9A; }

    .rsa-body { display: flex; flex-direction: column; gap: 7px; padding: 13px 15px 15px; flex: 1; }

    .rsa-chip {
        align-self: flex-start; padding: 3px 9px; border-radius: 7px;
        font-size: 11px; font-weight: 600; letter-spacing: .02em;
    }
    .rsa-chip.pdf  { background: #FBE5E1; color: #A6301F; }
    .rsa-chip.docx { background: #E4EEFB; color: #1A5C9A; }

    .rsa-name { font-size: 14px; font-weight: 600; color: var(--gray-900); line-height: 1.35; overflow-wrap: anywhere; }
    .rsa-by   { font-size: 12px; color: var(--gray-500); overflow-wrap: anywhere; }
    .rsa-desc { font-size: 12.5px; color: var(--gray-600); line-height: 1.5; overflow-wrap: anywhere; }

    .rsa-foot {
        display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        margin-top: auto; padding-top: 11px; border-top: 1px solid var(--gray-100);
        font-size: 11.5px; color: var(--gray-500);
    }
    .rsa-foot span { display: inline-flex; align-items: center; gap: 5px; }
    .rsa-foot svg { width: 13px; height: 13px; color: var(--gray-400); }

    /* ── The card's "…" menu ── */
    .rsa-menu { position: absolute; top: 9px; right: 9px; }
    .rsa-menu-b {
        width: 28px; height: 28px; border-radius: 8px; cursor: pointer;
        border: 1px solid rgba(255,255,255,.7); background: rgba(255,255,255,.82);
        color: var(--gray-600); display: grid; place-items: center; font-family: inherit;
    }
    .rsa-menu-b:hover { background: #fff; color: var(--gray-900); }
    .rsa-menu-b svg { width: 15px; height: 15px; }
    .rsa-pop {
        display: none; position: absolute; top: 32px; right: 0; z-index: 20; min-width: 150px;
        background: #fff; border: 1px solid var(--gray-200); border-radius: 11px;
        box-shadow: 0 8px 24px rgba(16,40,70,.14); padding: 5px; text-align: left;
    }
    .rsa-menu.open .rsa-pop { display: block; }
    .rsa-pop a, .rsa-pop button {
        display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px;
        border: 0; border-radius: 8px; background: none; cursor: pointer;
        font-family: inherit; font-size: 12.5px; font-weight: 500; color: var(--gray-700);
        text-decoration: none; text-align: left;
    }
    .rsa-pop a:hover { background: var(--gray-50); }
    .rsa-pop button:hover { background: #FDECEA; color: #A6301F; }
    .rsa-pop svg { width: 14px; height: 14px; flex: none; }
    .rsa-pop form { margin: 0; }

    /* ── View toggle ── */
    .rsa-views { display: inline-flex; gap: 3px; padding: 3px; background: var(--gray-100); border-radius: 10px; }
    .rsa-view {
        width: 34px; height: 32px; border-radius: 8px; display: grid; place-items: center;
        color: var(--gray-500); text-decoration: none;
    }
    .rsa-view svg { width: 16px; height: 16px; }
    .rsa-view.on { background: #fff; color: var(--primary); box-shadow: 0 1px 2px rgba(16,40,70,.10); }

    /* ── List view ── */
    .rsa-table { width: 100%; max-width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--stat-border); border-radius: var(--stat-radius); box-shadow: var(--stat-shadow); overflow: hidden; }
    .rsa-table th {
        text-align: left; padding: 11px 14px; font-size: 11px; font-weight: 700; color: var(--gray-400);
        text-transform: uppercase; letter-spacing: .06em; background: var(--gray-50); white-space: nowrap;
    }
    .rsa-table td { padding: 12px 14px; font-size: 12.5px; color: var(--gray-600); border-top: 1px solid var(--gray-100); vertical-align: middle; }
    .rsa-table tbody tr:hover { background: var(--mint-faint); }
    .rsa-table .rsa-t-name { font-size: 13px; font-weight: 600; color: var(--gray-900); overflow-wrap: anywhere; }
    .rsa-t-acts { display: flex; gap: 7px; justify-content: flex-end; }
    .rsa-t-act {
        padding: 5px 11px; border-radius: 8px; border: 1px solid var(--gray-200); background: #fff;
        font-family: inherit; font-size: 12px; font-weight: 600; color: var(--gray-600);
        text-decoration: none; cursor: pointer; white-space: nowrap;
    }
    .rsa-t-act:hover { border-color: var(--mint); color: var(--mint-deep, #00539B); }
    .rsa-t-act.bad:hover { border-color: #A6301F; color: #A6301F; background: #FDECEA; }
    .rsa-scroll { overflow-x: auto; }

    .rsa-empty { padding: 54px 20px; text-align: center; background: #fff; border: 1px solid var(--gray-100); border-radius: 14px; }
    .rsa-empty svg { width: 34px; height: 34px; color: var(--gray-300); }
    .rsa-empty p { margin: 10px 0 0; font-size: 13.5px; color: var(--gray-400); }

    /* ── The upload dialog ── */
    .rsa-modal { background: #fff; border-radius: 16px; padding: 24px; width: 520px; max-width: 95vw; max-height: 88vh; overflow-y: auto; box-shadow: 0 18px 50px rgba(16,40,70,.22); }
    .rsa-modal h2 { margin: 0; font-size: 17px; font-weight: 700; color: var(--forest); }
    .rsa-modal .rsa-lead { margin: 4px 0 18px; font-size: 12.5px; color: var(--gray-500); }
    .rsa-modal label { display: block; font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; }
    .rsa-modal input[type="text"], .rsa-modal select, .rsa-modal textarea, .rsa-modal input[type="file"] {
        width: 100%; font-family: inherit; font-size: 13px; padding: 10px 12px;
        border: 1px solid var(--gray-200); border-radius: 9px; margin-bottom: 14px; background: #fff; color: var(--gray-800);
    }
    .rsa-modal textarea { resize: vertical; }
    .rsa-modal input[type="file"] { border-style: dashed; background: var(--gray-50); padding: 10px; }
    .rsa-modal .req { color: #A6301F; }
    .rsa-modal-x { width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--gray-200); background: #fff; display: grid; place-items: center; cursor: pointer; }
    .rsa-modal-acts { display: flex; gap: 10px; justify-content: flex-end; }

    @media (max-width: 1000px) {
        .rsa-layout { grid-template-columns: 1fr; }
        .rsa-rail { display: flex; gap: 6px; overflow-x: auto; padding: 10px; }
        .rsa-rail-t { display: none; }
        .rsa-club { flex: none; margin: 0; }
        .rsa-club span.n { margin-left: 4px; }
    }

    @media (max-width: 700px) {
        .rsa-grid { grid-template-columns: 1fr; }
        .rsa-hd-ico { display: none; }
        .rsa-add { width: 100%; justify-content: center; }
    }
</style>

<!-- ══════════ Header ══════════ -->
<div class="rsa-hd">
    <div class="rsa-hd-l">
        <span class="rsa-hd-ico"><?= ss_icon('file') ?></span>
        <div style="min-width:0;">
            <h1>Resources Management</h1>
            <p>Every reviewer and handout shared with the clubs — who posted it, which club it is for, and how much it is being used.</p>
        </div>
    </div>
    <button type="button" class="rsa-add" onclick="document.getElementById('rsaUpload').classList.add('open')">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
        Add Resource
    </button>
</div>

<!-- ══════════ Figures ══════════ -->
<div class="ss-stats">
    <?php foreach ([
        ['Total Resources', number_format($stats['total']),
            $stats['total'] > 0 ? rsa_trend($stats['new_week'], $stats['new_week'] === 1 ? 'resource' : 'resources') : 'Nothing shared yet',
            '#EAF6FC', '#087FC1', 'file'],
        ['Downloads', number_format($stats['downloads']),
            $stats['total'] > 0 ? 'Across every file' : 'None yet',
            '#E6F5EE', '#17654B', 'check'],
        ['Contributors', number_format($stats['uploaders']),
            $stats['uploaders'] > 0 ? rsa_trend($stats['uploaders_week'], $stats['uploaders_week'] === 1 ? 'poster' : 'posters') : 'Nobody has posted yet',
            '#EFEDFC', '#4A3FB8', 'user'],
        ['Clubs covered', number_format($stats['clubs']) . ' of ' . count(PC_CLUBS),
            $stats['clubs'] < count(PC_CLUBS) ? 'Some have nothing yet' : 'Every club has something',
            '#FEF3D6', '#8A6400', 'star'],
    ] as [$k, $v, $s, $bg, $fg, $ico]): ?>
        <div class="ss-stat">
            <span class="ss-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= ss_icon($ico) ?></span>
            <div style="min-width:0;">
                <div class="ss-stat-k"><?= $k ?></div>
                <div class="ss-stat-v"><?= $v ?></div>
                <div class="ss-stat-s"><?= $s ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ══════════ Filters ══════════ -->
<form method="GET" class="ss-filters">
    <?php if ($view === 'list'): ?><input type="hidden" name="view" value="list"><?php endif; ?>
    <div class="ss-field ss-grow">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m20 20-4-4" /></svg>
        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by title, description or uploader…">
    </div>
    <div class="ss-field">
        <label for="rsa-club">Club</label>
        <select name="club" id="rsa-club">
            <option value="">All</option>
            <?php foreach (PC_CLUBS as $c): ?>
                <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>" <?= $club === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="ss-field">
        <label for="rsa-type">Type</label>
        <select name="type" id="rsa-type">
            <option value="">All</option>
            <option value="pdf"  <?= $type === 'pdf'  ? 'selected' : '' ?>>PDF</option>
            <option value="docx" <?= $type === 'docx' ? 'selected' : '' ?>>Word</option>
        </select>
    </div>
    <div class="ss-field">
        <label for="rsa-sort">Sort</label>
        <select name="sort" id="rsa-sort">
            <option value="recent"    <?= $sort === 'recent'    ? 'selected' : '' ?>>Newest first</option>
            <option value="downloads" <?= $sort === 'downloads' ? 'selected' : '' ?>>Most downloaded</option>
            <option value="title"     <?= $sort === 'title'     ? 'selected' : '' ?>>By title</option>
        </select>
    </div>
    <button type="submit" class="ss-apply">Apply</button>
    <?php if ($filtering): ?>
        <a class="ss-clear" href="<?= url('admin-resources') ?>">Clear</a>
    <?php endif; ?>

    <span class="rsa-views" style="margin-left:auto;">
        <a class="rsa-view <?= $view === 'grid' ? 'on' : '' ?>" href="<?= htmlspecialchars($rs_url(['view' => null, 'page' => null])) ?>" aria-label="Grid view" title="Grid view">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="1.6" /><rect x="13" y="4" width="7" height="7" rx="1.6" /><rect x="4" y="13" width="7" height="7" rx="1.6" /><rect x="13" y="13" width="7" height="7" rx="1.6" /></svg>
        </a>
        <a class="rsa-view <?= $view === 'list' ? 'on' : '' ?>" href="<?= htmlspecialchars($rs_url(['view' => 'list', 'page' => null])) ?>" aria-label="List view" title="List view">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
        </a>
    </span>
</form>

<div class="rsa-layout">
    <!-- ══════════ Clubs ══════════ -->
    <aside class="rsa-rail">
        <div class="rsa-rail-t">Clubs</div>
        <a class="rsa-club <?= $club === '' ? 'on' : '' ?>" href="<?= htmlspecialchars($rs_url(['club' => null, 'page' => null])) ?>">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="1.6" /><rect x="13" y="4" width="7" height="7" rx="1.6" /><rect x="4" y="13" width="7" height="7" rx="1.6" /><rect x="13" y="13" width="7" height="7" rx="1.6" /></svg>
            <b>All resources</b>
            <span class="n"><?= number_format($stats['total']) ?></span>
        </a>
        <?php foreach (PC_CLUBS as $c): ?>
            <a class="rsa-club <?= $club === $c ? 'on' : '' ?>" href="<?= htmlspecialchars($rs_url(['club' => $c, 'page' => null])) ?>" title="<?= htmlspecialchars($c, ENT_QUOTES) ?>">
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5 3 9l9 4 9-4-9-4Z" /><path stroke-linecap="round" d="M7 11v4c0 1 2.2 2 5 2s5-1 5-2v-4" /></svg>
                <b><?= htmlspecialchars($c) ?></b>
                <span class="n"><?= number_format($clubCounts[$c] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </aside>

    <div style="min-width:0;">
        <?php if (!$rows): ?>
            <div class="rsa-empty">
                <?= ss_icon('file') ?>
                <p><?= $filtering
                    ? 'No resources match those filters.'
                    : 'Nothing has been shared yet. Mentors post reviewers and handouts from their own Resources page, and you can add one here.' ?></p>
            </div>

        <?php elseif ($view === 'list'): ?>
            <div class="rsa-scroll">
                <table class="rsa-table">
                    <thead>
                        <tr>
                            <th style="width:34%;">Resource</th>
                            <th>Club</th>
                            <th>Type</th>
                            <th>Uploaded by</th>
                            <th>Size</th>
                            <th>Downloads</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r):
                            $rid = (int)$r['resource_id'];
                            $who = trim($r['firstname'] . ' ' . $r['lastname']);
                        ?>
                            <tr>
                                <td>
                                    <div class="rsa-t-name"><?= htmlspecialchars($r['title']) ?></div>
                                    <div style="font-size:11.5px;color:var(--gray-400);margin-top:2px;"><?= date('j M Y', strtotime($r['created_at'])) ?></div>
                                </td>
                                <td><?= htmlspecialchars($r['club']) ?></td>
                                <td><span class="rsa-chip <?= $r['file_type'] === 'pdf' ? 'pdf' : 'docx' ?>"><?= $r['file_type'] === 'pdf' ? 'PDF' : 'Word' ?></span></td>
                                <td>
                                    <?= htmlspecialchars($who) ?><?= $r['uploader_role'] === 'admin' ? ' · Admin' : '' ?>
                                </td>
                                <td style="font-variant-numeric:tabular-nums;"><?= rsa_size((int)$r['file_size']) ?></td>
                                <td style="font-variant-numeric:tabular-nums;"><?= number_format((int)$r['download_count']) ?></td>
                                <td>
                                    <div class="rsa-t-acts">
                                        <a class="rsa-t-act" href="<?= url('resource-download') ?>?id=<?= $rid ?>">Open</a>
                                        <form method="POST" action="<?= url('admin-resource-remove') ?>" style="margin:0;"
                                              onsubmit="return confirm('Take &ldquo;<?= htmlspecialchars(addslashes($r['title']), ENT_QUOTES) ?>&rdquo; out of the library? The file is deleted.');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="resource_id" value="<?= $rid ?>">
                                            <input type="hidden" name="back" value="<?= htmlspecialchars($rs_url(), ENT_QUOTES) ?>">
                                            <button type="submit" class="rsa-t-act bad">Take down</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <div class="rsa-grid">
                <?php foreach ($rows as $r):
                    $rid  = (int)$r['resource_id'];
                    $who  = trim($r['firstname'] . ' ' . $r['lastname']);
                    $kind = $r['file_type'] === 'pdf' ? 'pdf' : 'docx';
                ?>
                    <article class="rsa-card">
                        <div class="rsa-thumb <?= $kind ?>">
                            <span class="rsa-thumb-b"><?= ss_icon('file') ?></span>

                            <div class="rsa-menu">
                                <button type="button" class="rsa-menu-b" aria-label="Actions for <?= htmlspecialchars($r['title'], ENT_QUOTES) ?>" aria-haspopup="true" aria-expanded="false">
                                    <svg fill="currentColor" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.7" /><circle cx="12" cy="12" r="1.7" /><circle cx="19" cy="12" r="1.7" /></svg>
                                </button>
                                <div class="rsa-pop" role="menu">
                                    <a href="<?= url('resource-download') ?>?id=<?= $rid ?>" role="menuitem">
                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M5 19h14" /></svg>
                                        Open
                                    </a>
                                    <form method="POST" action="<?= url('admin-resource-remove') ?>"
                                          onsubmit="return confirm('Take &ldquo;<?= htmlspecialchars(addslashes($r['title']), ENT_QUOTES) ?>&rdquo; out of the library? The file is deleted.');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="resource_id" value="<?= $rid ?>">
                                        <input type="hidden" name="back" value="<?= htmlspecialchars($rs_url(), ENT_QUOTES) ?>">
                                        <button type="submit" role="menuitem">
                                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M10 7V5h4v2m-6 0 .7 12h6.6L16 7" /></svg>
                                            Take down
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="rsa-body">
                            <span class="rsa-chip <?= $kind ?>"><?= $kind === 'pdf' ? 'PDF' : 'Word' ?></span>
                            <div class="rsa-name"><?= htmlspecialchars($r['title']) ?></div>
                            <div class="rsa-by">
                                <?= htmlspecialchars($who) ?><?= $r['uploader_role'] === 'admin' ? ' · Admin' : '' ?>
                                · <?= htmlspecialchars($r['club']) ?>
                            </div>

                            <?php if (trim((string)$r['description']) !== ''): ?>
                                <p class="rsa-desc"><?= htmlspecialchars($r['description']) ?></p>
                            <?php endif; ?>

                            <div class="rsa-foot">
                                <span>
                                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2.5" /><path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16" /></svg>
                                    <?= date('j M Y', strtotime($r['created_at'])) ?>
                                </span>
                                <span>
                                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" /><path stroke-linecap="round" d="M14 3v5h5" /></svg>
                                    <?= rsa_size((int)$r['file_size']) ?>
                                </span>
                                <span>
                                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M5 19h14" /></svg>
                                    <?= number_format((int)$r['download_count']) ?>
                                </span>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($rows): ?>
            <div class="ss-foot">
                <span>Showing <?= (($page - 1) * $perPage) + 1 ?>&ndash;<?= min($page * $perPage, $total) ?> of <?= number_format($total) ?> resource<?= $total === 1 ? '' : 's' ?></span>
                <?php pc_pagination($page, $totalPages, fn(int $n) => $rs_url(['page' => $n]), ['label' => 'Resource pages']); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════ Add Resource ══════════ -->
<!--
    The same endpoint mentors post to. 'back' tells it to return here rather
    than to the member Resources page, which is where it sends everybody else.
-->
<div id="rsaUpload" class="modal-overlay">
    <div class="rsa-modal">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
            <div>
                <h2>Add a resource</h2>
                <p class="rsa-lead">PDF or Word, up to 10 MB. Everyone in the club can see and download it.</p>
            </div>
            <button type="button" class="rsa-modal-x" onclick="document.getElementById('rsaUpload').classList.remove('open')" aria-label="Close">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>

        <form method="POST" action="<?= htmlspecialchars(url('resource-upload')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="back" value="admin">

            <label for="rsa-title">Title <span class="req">*</span></label>
            <input type="text" id="rsa-title" name="title" required maxlength="200" placeholder="e.g. Algebra Midterm Reviewer">

            <label for="rsa-upclub">Club <span class="req">*</span></label>
            <select id="rsa-upclub" name="club" required>
                <option value="">Select a club</option>
                <?php foreach (PC_CLUBS as $c): ?>
                    <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>" <?= $club === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="rsa-desc">Description</label>
            <textarea id="rsa-desc" name="description" rows="3" maxlength="1000" placeholder="What does this cover?"></textarea>

            <label for="rsa-file">File <span class="req">*</span></label>
            <input type="file" id="rsa-file" name="file" required
                   accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document">

            <div class="rsa-modal-acts">
                <button type="button" class="rsa-t-act" onclick="document.getElementById('rsaUpload').classList.remove('open')">Cancel</button>
                <button type="submit" class="rsa-add" style="height:38px;">Upload resource</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        var modal = document.getElementById('rsaUpload');
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === this) this.classList.remove('open');
            });
        }

        // One listener for every card menu, so paging does not leave stale ones.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.rsa-menu-b');
            var open = document.querySelector('.rsa-menu.open');

            if (open && (!btn || open !== btn.parentNode)) {
                open.classList.remove('open');
                open.querySelector('.rsa-menu-b').setAttribute('aria-expanded', 'false');
            }
            if (btn) {
                var menu = btn.parentNode;
                var now = !menu.classList.contains('open');
                menu.classList.toggle('open', now);
                btn.setAttribute('aria-expanded', now ? 'true' : 'false');
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (modal) modal.classList.remove('open');
            var open = document.querySelector('.rsa-menu.open');
            if (open) {
                open.classList.remove('open');
                open.querySelector('.rsa-menu-b').setAttribute('aria-expanded', 'false');
            }
        });
    })();
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
