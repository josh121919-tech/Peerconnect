<?php

/**
 * admin/announcements.php — Announcements.
 *
 * Where an admin writes to the whole community. An announcement is aimed at
 * everyone, at mentees, or at mentors; it can go out now, be scheduled, or be
 * kept as a draft; and when it publishes, everyone in its audience is
 * notified, so it reaches people rather than waiting to be found.
 *
 * Reach is measured, not estimated: announcement_reads records who has
 * actually opened each one, so "read by 8 of 12" is a count of rows. The
 * reference design's "1.2K views" is that number, honestly sourced — which
 * on a platform this size means small figures, and they are shown as they are.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/announcement_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

// Anything whose scheduled time has arrived goes live before the page counts.
$justReleased = pc_ann_release($con);
if ($justReleased) {
    require_once __DIR__ . '/../../services/NotificationService.php';
    foreach ($justReleased as $rid) {
        $rs = $con->prepare("SELECT title, audience FROM announcements WHERE announcement_id = ?");
        $rs->bind_param('i', $rid);
        $rs->execute();
        $row = $rs->get_result()->fetch_assoc();
        $rs->close();
        if ($row) pc_ann_send($con, $rid, $row['title'], $row['audience']);
    }
}

$CATS = pc_ann_categories();
$AUDS = pc_ann_audiences();

/* ── Filters ──────────────────────────────────────────────────────────── */
$VIEWS = ['all', 'published', 'scheduled', 'draft', 'archived'];
$view  = in_array($_GET['tab'] ?? '', $VIEWS, true) ? $_GET['tab'] : 'all';

$q     = trim((string)($_GET['q'] ?? ''));
$cat   = array_key_exists($_GET['cat'] ?? '', $CATS) ? $_GET['cat'] : '';
$aud   = array_key_exists($_GET['aud'] ?? '', $AUDS) ? $_GET['aud'] : '';
$sort  = in_array($_GET['sort'] ?? '', ['newest', 'oldest', 'reach'], true) ? $_GET['sort'] : 'newest';
$edit  = (int)($_GET['edit'] ?? 0);

$perPage = 6;
$page    = max(1, (int)($_GET['page'] ?? 1));

$clauses = [];
$types   = '';
$args    = [];
if ($q !== '')   { $clauses[] = "CONCAT_WS(' ', a.title, a.body, a.category) LIKE ?"; $types .= 's'; $args[] = '%' . $q . '%'; }
if ($cat !== '') { $clauses[] = 'a.category = ?'; $types .= 's'; $args[] = $cat; }
if ($aud !== '') { $clauses[] = 'a.audience = ?'; $types .= 's'; $args[] = $aud; }
if ($view !== 'all') { $clauses[] = 'a.status = ?'; $types .= 's'; $args[] = $view; }
$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

/* ── Tab counts ───────────────────────────────────────────────────────── */
$fc = array_slice($clauses, 0, count($clauses) - ($view !== 'all' ? 1 : 0));
$ft = substr($types, 0, strlen($types) - ($view !== 'all' ? 1 : 0));
$fa = array_slice($args, 0, count($args) - ($view !== 'all' ? 1 : 0));
$fWhere = $fc ? 'WHERE ' . implode(' AND ', $fc) : '';

$cSql = "SELECT COUNT(*) all_c,
                SUM(a.status='published') published,
                SUM(a.status='scheduled') scheduled,
                SUM(a.status='draft') draft,
                SUM(a.status='archived') archived
         FROM announcements a $fWhere";
if ($ft !== '') {
    $cs = $con->prepare($cSql);
    $cs->bind_param($ft, ...$fa);
    $cs->execute();
    $counts = $cs->get_result()->fetch_assoc();
    $cs->close();
} else {
    $counts = $con->query($cSql)->fetch_assoc();
}
foreach ($counts as $k => $v) $counts[$k] = (int)$v;

/* ── Reach across everything published ────────────────────────────────── */
$reachRows = $con->query("
    SELECT a.announcement_id, a.audience,
           (SELECT COUNT(*) FROM announcement_reads r WHERE r.announcement_id = a.announcement_id) reads_n
    FROM announcements a WHERE a.status = 'published'
")->fetch_all(MYSQLI_ASSOC);

$reachRead = 0;
$reachOf   = 0;
foreach ($reachRows as $r) {
    $reachRead += (int)$r['reads_n'];
    $reachOf   += pc_ann_audience_size($con, $r['audience']);
}
$reachPct = $reachOf > 0 ? round($reachRead / $reachOf * 100) : null;

/* ── The list ─────────────────────────────────────────────────────────── */
$cs2 = $con->prepare("SELECT COUNT(*) c FROM announcements a $where");
if ($types !== '') $cs2->bind_param($types, ...$args);
$cs2->execute();
$total = (int)$cs2->get_result()->fetch_assoc()['c'];
$cs2->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$order = [
    'newest' => 'a.is_pinned DESC, COALESCE(a.published_at, a.publish_at, a.created_at) DESC',
    'oldest' => 'a.is_pinned DESC, COALESCE(a.published_at, a.publish_at, a.created_at) ASC',
    'reach'  => 'a.is_pinned DESC, reads_n DESC',
][$sort];

$ls = $con->prepare("
    SELECT a.*, CONCAT_WS(' ', u.firstname, u.lastname) author, p.profile_image author_pic,
           (SELECT COUNT(*) FROM announcement_reads r WHERE r.announcement_id = a.announcement_id) reads_n
    FROM announcements a
    JOIN users u ON u.user_id = a.created_by
    LEFT JOIN profile p ON p.user_id = a.created_by
    $where
    ORDER BY $order
    LIMIT ? OFFSET ?
");
$ls->bind_param($types . 'ii', ...array_merge($args, [$perPage, $offset]));
$ls->execute();
$rows = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
$ls->close();

/* ── Category breakdown ───────────────────────────────────────────────── */
$byCat = $con->query("SELECT category, COUNT(*) c FROM announcements GROUP BY category ORDER BY c DESC")->fetch_all(MYSQLI_ASSOC);
$catTotal = array_sum(array_column($byCat, 'c'));

/* ── Recent activity ──────────────────────────────────────────────────── */
$activity = $con->query("
    SELECT a.announcement_id, a.title, a.status, a.created_at, a.published_at, a.archived_at, a.updated_at,
           CONCAT_WS(' ', u.firstname, u.lastname) author
    FROM announcements a JOIN users u ON u.user_id = a.created_by
    ORDER BY COALESCE(a.archived_at, a.published_at, a.updated_at, a.created_at) DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

/* ── The one being edited ─────────────────────────────────────────────── */
$editing = null;
if ($edit > 0) {
    $es = $con->prepare("SELECT * FROM announcements WHERE announcement_id = ? LIMIT 1");
    $es->bind_param('i', $edit);
    $es->execute();
    $editing = $es->get_result()->fetch_assoc() ?: null;
    $es->close();
}

function an_url(array $over = []): string
{
    $p = array_merge([
        'tab' => $_GET['tab'] ?? null, 'q' => $_GET['q'] ?? null, 'cat' => $_GET['cat'] ?? null,
        'aud' => $_GET['aud'] ?? null, 'sort' => $_GET['sort'] ?? null, 'page' => $_GET['page'] ?? null,
    ], $over);
    $p = array_filter($p, fn($v) => $v !== null && $v !== '');
    return url('admin-announcements') . ($p ? '?' . http_build_query($p) : '');
}

$hasFilter = ($q !== '' || $cat !== '' || $aud !== '');
$csrf = csrf_token();
$backHere = an_url();

$current_page = 'announcements';
include 'layout.php';
include __DIR__ . '/includes/sessions_ui.php';
?>

<style>
    .an-rail { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 14px; align-items: start; }
    .an-side { display: flex; flex-direction: column; gap: 14px; }
    .an-side .ss-card { padding: 16px 17px; }

    .an-row { display: flex; align-items: flex-start; gap: 15px; padding: 15px 16px; background: #fff;
              border: 1px solid var(--gray-100); border-radius: 14px; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
    .an-row.pinned { border-color: var(--mint-soft); }
    .an-thumb { flex: none; width: 84px; height: 66px; border-radius: 11px; display: grid; place-items: center; overflow: hidden; }
    .an-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .an-thumb svg { width: 24px; height: 24px; }
    .an-body { flex: 1.6; min-width: 0; }
    .an-t { font-size: 14.5px; font-weight: 700; color: var(--forest); }
    .an-x { font-size: 12.5px; color: var(--gray-500); margin-top: 3px; line-height: 1.5;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .an-tags { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
    .an-tag { padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    .an-meta { flex: none; width: 150px; font-size: 12.5px; color: var(--gray-600); }
    .an-meta span { display: block; font-size: 11.5px; color: var(--gray-400); }
    .an-meta .who { display: flex; align-items: center; gap: 7px; margin-bottom: 6px; }
    .an-end { flex: none; width: 128px; display: flex; flex-direction: column; gap: 8px; }
    .an-end form { margin: 0; }
    .an-btn { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
              padding: 8px 10px; border: 1px solid var(--gray-200); border-radius: 9px; background: #fff;
              font-family: inherit; font-size: 12.5px; font-weight: 600; color: var(--gray-700); text-decoration: none; cursor: pointer; }
    .an-btn:hover { border-color: var(--mint); color: var(--mint-deep, #00539B); }
    .an-btn svg { width: 14px; height: 14px; }
    .an-btn.solid { background: var(--mint); border-color: var(--mint); color: #fff; }
    .an-btn.solid:hover { background: #0077B6; color: #fff; }
    .an-btn.danger { color: #A6301F; border-color: #F3C9C0; }

    .an-form label { display: block; font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; margin-top: 12px; }
    .an-form label:first-of-type { margin-top: 0; }
    .an-form input, .an-form select, .an-form textarea {
        width: 100%; padding: 10px 12px; border: 1px solid var(--gray-200); border-radius: 10px;
        font-family: inherit; font-size: 13.5px; color: var(--gray-800); outline: none; resize: vertical; background: #fff;
    }
    .an-form input:focus, .an-form select:focus, .an-form textarea:focus { border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0,135,207,.13); }
    .an-form-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .an-form-foot { display: flex; gap: 9px; flex-wrap: wrap; margin-top: 16px; }
    .an-check { display: flex; align-items: center; gap: 8px; margin-top: 12px; font-size: 12.5px; color: var(--gray-600); }
    .an-check input { width: auto; }

    .an-reach { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
    .an-reach-l { flex: 1; min-width: 130px; display: flex; flex-direction: column; gap: 8px; font-size: 12.5px; color: var(--gray-600); }
    .an-reach-l div { display: flex; align-items: center; gap: 8px; }
    .an-reach-l i { width: 9px; height: 9px; border-radius: 50%; flex: none; }
    .an-reach-l b { margin-left: auto; font-weight: 700; color: var(--forest); font-variant-numeric: tabular-nums; }

    @media (max-width: 1240px) { .an-rail { grid-template-columns: minmax(0, 1fr); } }
    @media (max-width: 900px) { .an-meta { display: none; } .an-form-row { grid-template-columns: 1fr; } }
    @media (max-width: 640px) { .an-row { flex-wrap: wrap; } .an-body { flex-basis: 100%; } .an-end { width: 100%; } }
</style>

<div class="ss-hd">
    <div>
        <h1>Announcements</h1>
        <p>Write to the whole community, or to mentors and mentees separately.</p>
    </div>
    <div class="ss-hd-actions">
        <a class="ss-export" href="#compose" onclick="document.getElementById('an-title').focus()">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
            <?= $editing ? 'Editing' : 'Create announcement' ?>
        </a>
    </div>
</div>

<!-- ══════════ Figures ══════════ -->
<div class="ss-stats">
    <?php
    $allC = (int)$con->query("SELECT COUNT(*) c FROM announcements")->fetch_assoc()['c'];
    $pubC = (int)$con->query("SELECT COUNT(*) c FROM announcements WHERE status='published'")->fetch_assoc()['c'];
    $schC = (int)$con->query("SELECT COUNT(*) c FROM announcements WHERE status='scheduled'")->fetch_assoc()['c'];
    $arcC = (int)$con->query("SELECT COUNT(*) c FROM announcements WHERE status='archived'")->fetch_assoc()['c'];
    $nextUp = $con->query("SELECT title, publish_at FROM announcements WHERE status='scheduled' ORDER BY publish_at ASC LIMIT 1")->fetch_assoc();
    foreach ([
        ['Announcements', number_format($allC), 'Written so far', '#EAF1FB', '#1A5C9A', 'cal'],
        ['Published', number_format($pubC), $pubC > 0 ? 'Live for their audience' : 'Nothing live yet', '#E6F5EE', '#17654B', 'check'],
        ['Scheduled', number_format($schC), $nextUp ? 'Next: ' . date('M j, g:i A', strtotime($nextUp['publish_at'])) : 'None queued', '#FEF6DC', '#B7791F', 'clock'],
        ['Read rate', $reachPct !== null ? $reachPct . '%' : '—', $reachOf > 0 ? $reachRead . ' of ' . $reachOf . ' possible reads' : 'Nothing published yet', '#EAF6FB', '#0087CF', 'star'],
    ] as [$k, $v, $s, $bg, $fg, $ico]): ?>
        <div class="ss-stat">
            <span class="ss-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= ss_icon($ico) ?></span>
            <div style="min-width:0;">
                <div class="ss-stat-k"><?= $k ?></div>
                <div class="ss-stat-v"><?= $v ?></div>
                <div class="ss-stat-s"><?= htmlspecialchars($s) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="an-rail">
    <div>
        <!-- ══════════ Compose ══════════ -->
        <div class="ss-card" id="compose" style="margin-bottom:14px;">
            <h2>
                <?= $editing ? 'Edit announcement' : 'New announcement' ?>
                <?php if ($editing): ?><a href="<?= an_url(['edit' => null]) ?>#compose">Cancel and write a new one →</a><?php endif; ?>
            </h2>
            <form class="an-form" method="post" action="<?= url('admin-action-announcement') ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="back" value="<?= htmlspecialchars($backHere) ?>">
                <?php if ($editing): ?><input type="hidden" name="announcement_id" value="<?= (int)$editing['announcement_id'] ?>"><?php endif; ?>

                <label for="an-title">Title</label>
                <input id="an-title" type="text" name="title" maxlength="200" required
                       value="<?= htmlspecialchars($editing['title'] ?? '') ?>" placeholder="e.g. Scheduled maintenance this Saturday">

                <label for="an-body">Message</label>
                <textarea id="an-body" name="body" rows="5" required placeholder="What do you want the community to know?"><?= htmlspecialchars($editing['body'] ?? '') ?></textarea>

                <div class="an-form-row">
                    <div>
                        <label for="an-cat">Category</label>
                        <select id="an-cat" name="category">
                            <?php foreach ($CATS as $c => $_x): ?>
                                <option value="<?= $c ?>" <?= ($editing['category'] ?? 'General') === $c ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="an-aud">Who sees it</label>
                        <select id="an-aud" name="audience">
                            <?php foreach ($AUDS as $k => $l): ?>
                                <option value="<?= $k ?>" <?= ($editing['audience'] ?? 'all') === $k ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="an-form-row">
                    <div>
                        <label for="an-when">Publish at <span style="font-weight:400;color:var(--gray-400);">(leave empty to send now)</span></label>
                        <input id="an-when" type="datetime-local" name="publish_at"
                               value="<?= $editing && $editing['publish_at'] ? date('Y-m-d\TH:i', strtotime($editing['publish_at'])) : '' ?>">
                    </div>
                    <div>
                        <label for="an-img">Banner image <span style="font-weight:400;color:var(--gray-400);">(optional)</span></label>
                        <input id="an-img" type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif">
                    </div>
                </div>

                <label class="an-check">
                    <input type="checkbox" name="is_pinned" value="1" <?= !empty($editing['is_pinned']) ? 'checked' : '' ?>>
                    Pin to the top of everyone’s list
                </label>

                <div class="an-form-foot">
                    <button type="submit" name="action" value="publish" class="an-btn solid" style="width:auto;padding:10px 20px;">
                        <?= $editing && $editing['status'] === 'published' ? 'Save changes' : 'Publish' ?>
                    </button>
                    <button type="submit" name="action" value="schedule" class="an-btn" style="width:auto;padding:10px 20px;">Schedule</button>
                    <button type="submit" name="action" value="draft" class="an-btn" style="width:auto;padding:10px 20px;">Save as draft</button>
                </div>
                <p class="ss-none" style="margin-top:9px;">
                    Publishing notifies everyone in the audience straight away. Scheduling needs a date and time — it goes out on its own.
                </p>
            </form>
        </div>

        <!-- ══════════ Tabs ══════════ -->
        <div class="ss-tabs">
            <?php foreach ([
                ['all', 'All', $counts['all_c']],
                ['published', 'Published', $counts['published']],
                ['scheduled', 'Scheduled', $counts['scheduled']],
                ['draft', 'Drafts', $counts['draft']],
                ['archived', 'Archived', $counts['archived']],
            ] as [$k, $label, $n]): ?>
                <a class="ss-tab <?= $view === $k ? 'on' : '' ?>" href="<?= an_url(['tab' => $k === 'all' ? null : $k, 'page' => null]) ?>">
                    <?= $label ?><span class="ss-tab-n"><?= (int)$n ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ══════════ Filters ══════════ -->
        <form class="ss-filters" method="get" action="<?= url('admin-announcements') ?>">
            <?php if ($view !== 'all'): ?><input type="hidden" name="tab" value="<?= htmlspecialchars($view) ?>"><?php endif; ?>
            <div class="ss-field ss-grow">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m20 20-4-4" /></svg>
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search title or message…">
            </div>
            <div class="ss-field">
                <select name="aud" aria-label="Audience">
                    <option value="">Everyone and each role</option>
                    <?php foreach ($AUDS as $k => $l): ?>
                        <option value="<?= $k ?>" <?= $aud === $k ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ss-field">
                <select name="cat" aria-label="Category">
                    <option value="">All categories</option>
                    <?php foreach ($CATS as $c => $_x): ?>
                        <option value="<?= $c ?>" <?= $cat === $c ? 'selected' : '' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ss-field">
                <select name="sort" aria-label="Sort">
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
                    <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
                    <option value="reach" <?= $sort === 'reach' ? 'selected' : '' ?>>Most read</option>
                </select>
            </div>
            <button type="submit" class="ss-apply">Apply</button>
            <?php if ($hasFilter): ?>
                <a class="ss-clear" href="<?= url('admin-announcements') . ($view !== 'all' ? '?tab=' . $view : '') ?>">Clear filters</a>
            <?php endif; ?>
        </form>

        <!-- ══════════ List ══════════ -->
        <?php if (!$rows): ?>
            <div class="ss-empty">
                <?= ss_icon('cal') ?>
                <p><?= $hasFilter || $view !== 'all' ? 'No announcements match this view.' : 'Nothing has been announced yet. Write the first one above.' ?></p>
            </div>
        <?php else: ?>
            <div class="as-list">
                <?php foreach ($rows as $a):
                    [$sl, $sfg, $sbg] = pc_ann_state($a);
                    [$cfg, $cbg] = pc_ann_category_color($a['category']);
                    $id = (int)$a['announcement_id'];
                    $size = pc_ann_audience_size($con, $a['audience']);
                    $pct = ($a['status'] === 'published' && $size > 0) ? round($a['reads_n'] / $size * 100) : null;
                ?>
                    <div class="an-row<?= (int)$a['is_pinned'] ? ' pinned' : '' ?>">
                        <span class="an-thumb" style="background:<?= $cbg ?>;color:<?= $cfg ?>;">
                            <?php if (!empty($a['image_path'])): ?>
                                <img src="<?= htmlspecialchars($a['image_path']) ?>" alt="">
                            <?php else: ?>
                                <?= ss_icon('cal') ?>
                            <?php endif; ?>
                        </span>

                        <div class="an-body">
                            <div class="an-t">
                                <?php if ((int)$a['is_pinned']): ?><span title="Pinned" style="color:var(--mint);">📌</span> <?php endif; ?>
                                <?= htmlspecialchars($a['title']) ?>
                            </div>
                            <div class="an-x"><?= htmlspecialchars($a['body']) ?></div>
                            <div class="an-tags">
                                <span class="an-tag" style="background:<?= $cbg ?>;color:<?= $cfg ?>;"><?= htmlspecialchars($a['category']) ?></span>
                                <span class="an-tag" style="background:var(--gray-100);color:var(--gray-600);"><?= $AUDS[$a['audience']] ?></span>
                                <?php if ($a['status'] === 'published'): ?>
                                    <span class="an-tag" style="background:var(--gray-100);color:var(--gray-600);">
                                        Read by <?= (int)$a['reads_n'] ?> of <?= $size ?><?= $pct !== null ? ' · ' . $pct . '%' : '' ?>
                                    </span>
                                <?php elseif ($a['status'] === 'scheduled' && $a['publish_at']): ?>
                                    <span class="an-tag" style="background:#FEF6DC;color:#B7791F;">Goes out <?= date('M j, g:i A', strtotime($a['publish_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="an-meta">
                            <span class="who">
                                <span class="ss-av" style="width:26px;height:26px;font-size:10px;">
                                    <?= $a['author_pic'] ? '<img src="' . htmlspecialchars($a['author_pic']) . '" alt="">' : htmlspecialchars(strtoupper(substr($a['author'], 0, 2))) ?>
                                </span>
                                <?= htmlspecialchars($a['author']) ?>
                            </span>
                            <?= date('M j, Y', strtotime($a['published_at'] ?: ($a['publish_at'] ?: $a['created_at']))) ?>
                            <span><?= pc_ann_ago($a['published_at'] ?: ($a['publish_at'] ?: $a['created_at'])) ?></span>
                        </div>

                        <div class="an-end">
                            <span class="ss-pill" style="color:<?= $sfg ?>;background:<?= $sbg ?>;"><?= $sl ?></span>
                            <a class="an-btn" href="<?= an_url(['edit' => $id]) ?>#compose">Edit</a>
                            <form method="post" action="<?= url('admin-action-announcement') ?>"
                                  onsubmit="return confirm('<?= $a['status'] === 'archived' ? 'Restore this announcement as a draft?' : 'Archive this announcement? It stops being visible to members.' ?>');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="announcement_id" value="<?= $id ?>">
                                <input type="hidden" name="back" value="<?= htmlspecialchars($backHere) ?>">
                                <input type="hidden" name="action" value="<?= $a['status'] === 'archived' ? 'restore' : 'archive' ?>">
                                <button type="submit" class="an-btn<?= $a['status'] === 'archived' ? '' : ' danger' ?>"><?= $a['status'] === 'archived' ? 'Restore' : 'Archive' ?></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="ss-foot">
                <span>Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= number_format($total) ?> announcement<?= $total === 1 ? '' : 's' ?></span>
                <?php if ($totalPages > 1): ?>
                    <div class="ss-pages">
                        <?php if ($page > 1): ?><a href="<?= an_url(['page' => $page - 1]) ?>">‹</a><?php else: ?><span class="off">‹</span><?php endif; ?>
                        <?php $lo = max(1, $page - 2); $hi = min($totalPages, $lo + 4); $lo = max(1, $hi - 4);
                        for ($i = $lo; $i <= $hi; $i++): ?>
                            <?php if ($i === $page): ?><span class="on"><?= $i ?></span><?php else: ?><a href="<?= an_url(['page' => $i]) ?>"><?= $i ?></a><?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?><a href="<?= an_url(['page' => $page + 1]) ?>">›</a><?php else: ?><span class="off">›</span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ══════════ Side ══════════ -->
    <div class="an-side">
        <div class="ss-card">
            <h2>Reach</h2>
            <?php if ($reachPct === null): ?>
                <p class="ss-none">Nothing has been published yet, so there is no reach to measure.</p>
            <?php else:
                $R = 44; $C = 2 * M_PI * $R; $len = $C * ($reachPct / 100); ?>
                <div class="an-reach">
                    <div style="position:relative;flex:none;">
                        <svg width="116" height="116" viewBox="0 0 116 116" role="img" aria-label="<?= $reachPct ?> percent of possible reads">
                            <circle cx="58" cy="58" r="<?= $R ?>" fill="none" stroke="#EDEDED" stroke-width="12" />
                            <circle cx="58" cy="58" r="<?= $R ?>" fill="none" stroke="#1B6FD1" stroke-width="12" stroke-linecap="round"
                                    stroke-dasharray="<?= round($len, 2) ?> <?= round($C - $len, 2) ?>" transform="rotate(-90 58 58)" />
                            <text x="58" y="58" text-anchor="middle" font-size="20" font-weight="700" fill="#020547"><?= $reachPct ?>%</text>
                            <text x="58" y="72" text-anchor="middle" font-size="9" fill="#9A9EA6">read</text>
                        </svg>
                    </div>
                    <div class="an-reach-l">
                        <div><i style="background:#1B6FD1"></i>Opened<b><?= number_format($reachRead) ?></b></div>
                        <div><i style="background:#EDEDED"></i>Not yet<b><?= number_format(max(0, $reachOf - $reachRead)) ?></b></div>
                        <div><i style="background:transparent"></i>Possible reads<b><?= number_format($reachOf) ?></b></div>
                    </div>
                </div>
                <p class="ss-none" style="margin-top:10px;">Counted from who has actually opened each announcement, summed across everything published.</p>
            <?php endif; ?>
        </div>

        <div class="ss-card">
            <h2>Categories</h2>
            <?php if (!$byCat): ?>
                <p class="ss-none">Nothing written yet.</p>
            <?php else: foreach ($byCat as $c):
                [$cfg, ] = pc_ann_category_color($c['category']);
                $pct = $catTotal > 0 ? round($c['c'] / $catTotal * 100) : 0; ?>
                <div class="ss-bar" style="margin-bottom:9px;">
                    <span style="width:88px;"><?= htmlspecialchars($c['category']) ?></span>
                    <span class="ss-bar-t"><i style="width:<?= $pct ?>%;background:<?= $cfg ?>"></i></span>
                    <span class="ss-bar-n"><?= $pct ?>%</span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="ss-card">
            <h2>Recent activity</h2>
            <?php if (!$activity): ?>
                <p class="ss-none">Nothing has happened yet.</p>
            <?php else: foreach ($activity as $r):
                $what = $r['status'] === 'archived' ? ['archived', $r['archived_at']]
                    : ($r['status'] === 'published' ? ['published', $r['published_at']]
                    : ($r['status'] === 'scheduled' ? ['scheduled', $r['updated_at'] ?: $r['created_at']]
                    : ['drafted', $r['updated_at'] ?: $r['created_at']]));
                [$cfg, $cbg] = pc_ann_category_color('General'); ?>
                <a class="as-act" href="<?= an_url(['edit' => (int)$r['announcement_id']]) ?>#compose" style="display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-top:1px solid var(--gray-100);text-decoration:none;">
                    <span style="min-width:0;">
                        <b style="display:block;font-size:12.5px;font-weight:600;color:var(--gray-800);"><?= htmlspecialchars($r['author']) ?> <?= $what[0] ?> “<?= htmlspecialchars($r['title']) ?>”</b>
                        <span style="font-size:11.5px;color:var(--gray-400);"><?= pc_ann_ago($what[1]) ?></span>
                    </span>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
