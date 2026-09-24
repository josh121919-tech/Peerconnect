<?php
// Resources — shared study material (reviewers, handouts) organised by club.
// One page for every role. Mentors and admins add to it; mentees read,
// download and bookmark. See $can_upload below.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

$role = $_SESSION['role'] ?? '';

/*
 * Who may add to the library. Mentors and admins: it is teaching material, and
 * a mentee uploading into it was the one way a study file could arrive without
 * anyone having vouched for it. Mentees still search, download and bookmark
 * everything.
 *
 * resources/upload.php refuses the same roles. This only decides whether to
 * offer the control — a hidden button is a suggestion, not a rule.
 */
$can_upload = in_array($role, ['mentor', 'admin'], true);
if (empty($_SESSION['user_id']) || !in_array($role, ['mentee', 'mentor'], true)) {
    header("Location: " . url('welcomepage'));
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// The club this user belongs to — used to preselect the upload form.
$my_club = $con->query("
    SELECT club FROM user_verifications WHERE user_id = $user_id
")->fetch_assoc()['club'] ?? '';

// ── Filters (all validated against fixed lists, never interpolated raw) ──
$search    = trim(strip_tags($_GET['q'] ?? ''));
$club      = in_array($_GET['club'] ?? '', PC_CLUBS, true) ? $_GET['club'] : '';
$type      = in_array($_GET['type'] ?? '', ['pdf', 'docx'], true) ? $_GET['type'] : '';
$sort      = in_array($_GET['sort'] ?? '', ['recent', 'downloads', 'title'], true) ? $_GET['sort'] : 'recent';
$saved_only = isset($_GET['saved']);

$where  = ['r.is_active = 1'];
$types  = '';
$params = [];

if ($search !== '') {
    $where[]  = '(r.title LIKE ? OR r.description LIKE ?)';
    $like     = '%' . $search . '%';
    $types   .= 'ss';
    $params[] = &$like;
    $params[] = &$like;
}
if ($club !== '') {
    $where[]  = 'r.club = ?';
    $types   .= 's';
    $params[] = &$club;
}
if ($type !== '') {
    $where[]  = 'r.file_type = ?';
    $types   .= 's';
    $params[] = &$type;
}
if ($saved_only) {
    $where[] = 'bm.resource_id IS NOT NULL';
}

$whereSQL = implode(' AND ', $where);
$orderSQL = [
    'recent'    => 'r.created_at DESC',
    'downloads' => 'r.download_count DESC, r.created_at DESC',
    'title'     => 'r.title ASC',
][$sort];

$sql = "
    SELECT r.*, u.firstname, u.lastname, u.role AS uploader_role,
           (bm.resource_id IS NOT NULL) AS is_saved
    FROM resources r
    JOIN users u ON u.user_id = r.uploader_id
    LEFT JOIN resource_bookmarks bm ON bm.resource_id = r.resource_id AND bm.user_id = ?
    WHERE $whereSQL
    ORDER BY $orderSQL
";
$stmt = $con->prepare($sql);
$bindTypes = 'i' . $types;
$bindArgs  = array_merge([$bindTypes], [&$user_id], $params);
call_user_func_array([$stmt, 'bind_param'], $bindArgs);
$stmt->execute();
$resources = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Spotlight: the most-downloaded resource(s), a real signal rather than a
//    hand-picked "featured" flag nobody can set. ──────────────────────────
$spotlight = $con->query("
    SELECT r.*, u.firstname, u.lastname
    FROM resources r
    JOIN users u ON u.user_id = r.uploader_id
    WHERE r.is_active = 1 AND r.download_count > 0
    ORDER BY r.download_count DESC, r.created_at DESC
    LIMIT 3
")->fetch_all(MYSQLI_ASSOC);

// ── Saved list + per-club counts for the sidebar ────────────────────────
$saved_stmt = $con->prepare("
    SELECT r.resource_id, r.title, r.club, r.file_type, r.download_count
    FROM resource_bookmarks bm
    JOIN resources r ON r.resource_id = bm.resource_id AND r.is_active = 1
    WHERE bm.user_id = ?
    ORDER BY bm.created_at DESC
    LIMIT 5
");
$saved_stmt->bind_param("i", $user_id);
$saved_stmt->execute();
$saved_resources = $saved_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$saved_stmt->close();

$saved_total = (int)($con->query("
    SELECT COUNT(*) c FROM resource_bookmarks bm
    JOIN resources r ON r.resource_id = bm.resource_id AND r.is_active = 1
    WHERE bm.user_id = $user_id
")->fetch_assoc()['c'] ?? 0);

$club_counts = [];
$cc = $con->query("SELECT club, COUNT(*) c FROM resources WHERE is_active = 1 GROUP BY club");
while ($row = $cc->fetch_assoc()) {
    $club_counts[$row['club']] = (int)$row['c'];
}
$total_resources = array_sum($club_counts);


$resources_url = url('resources');
$active_page   = 'resources';

function pc_filesize(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resources — PeerConnect</title>
    <?php require_once __DIR__ . '/design_system.php'; ?>
    <style>
        .rs-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 300px;
            gap: 18px;
            align-items: start;
        }

        .rs-toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .rs-search {
            flex: 1 1 260px;
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            height: 44px;
            padding: 0 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--surface);
        }

        .rs-search svg {
            width: 17px;
            height: 17px;
            color: var(--gray-400);
            flex-shrink: 0;
        }

        .rs-search input {
            flex: 1;
            min-width: 0;
            border: 0;
            outline: 0;
            background: transparent;
            font-size: 13.5px;
            font-family: inherit;
            color: var(--gray-800);
        }

        .rs-toolbar select {
            height: 44px;
            min-height: 0;
            width: auto;
            flex: 0 1 auto;
            font-size: 13px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 0 10px;
            min-width: 150px;
        }

        /* Spotlight */
        .rs-spotlight {
            background: linear-gradient(120deg, var(--mint-faint) 0%, var(--surface) 70%);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px 22px;
            margin-bottom: 18px;
        }

        .rs-spot-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .rs-spot-body {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .rs-spot-thumb {
            width: 160px;
            height: 116px;
            border-radius: var(--radius);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .rs-tag {
            display: inline-block;
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--forest);
            background: var(--mint-soft);
            border-radius: 999px;
            padding: 3px 10px;
            margin-bottom: 8px;
        }

        /* Cards */
        .rs-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .rs-grid.list-view {
            grid-template-columns: 1fr;
        }

        .rs-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 18px;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .rs-file-icon {
            width: 54px;
            height: 54px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .03em;
            margin-bottom: 14px;
        }

        .rs-file-pdf {
            background: #FDECEA;
            color: var(--danger);
        }

        .rs-file-docx {
            background: var(--info-bg);
            color: var(--info);
        }

        .rs-card-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--forest);
            line-height: 1.35;
            margin-bottom: 6px;
        }

        .rs-card-desc {
            font-size: 12.5px;
            color: var(--gray-500);
            line-height: 1.55;
            margin-bottom: 12px;
        }

        .rs-meta {
            font-size: 11.5px;
            color: var(--gray-400);
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .rs-club-chip {
            font-size: 11px;
            font-weight: 600;
            color: var(--forest);
            background: var(--mint-faint);
            border-radius: 999px;
            padding: 3px 10px;
        }

        .rs-card-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        .rs-stat {
            font-size: 11.5px;
            color: var(--gray-500);
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .rs-stat svg {
            width: 13px;
            height: 13px;
            flex-shrink: 0;
        }

        .rs-bookmark {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 30px;
            height: 30px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--gray-400);
            padding: 0;
        }

        .rs-bookmark.saved {
            color: var(--mint);
            border-color: var(--mint-soft);
            background: var(--mint-faint);
        }

        .rs-bookmark svg {
            width: 15px;
            height: 15px;
        }

        /* Sidebar */
        .rs-side-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            margin-bottom: 16px;
            overflow: hidden;
        }

        .rs-side-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 18px 12px;
            border-bottom: 1px solid var(--border);
        }

        .rs-side-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--forest);
        }

        .rs-saved-row {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 18px;
            border-bottom: 1px solid var(--border);
        }

        .rs-saved-row:last-child {
            border-bottom: none;
        }

        .rs-club-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 9px 18px;
            font-size: 13px;
            color: var(--gray-600);
            text-decoration: none;
        }

        .rs-club-row:hover {
            background: var(--mint-faint);
            color: var(--forest);
        }

        .rs-club-row.active {
            color: var(--mint);
            font-weight: 700;
        }

        .rs-club-count {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--gray-500);
            background: var(--gray-50);
            border-radius: 999px;
            padding: 2px 9px;
        }

        .rs-view-toggle {
            display: flex;
            gap: 2px;
            background: var(--gray-50);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 2px;
        }

        .rs-view-btn {
            width: 36px;
            height: 32px;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: var(--gray-400);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .rs-view-btn.active {
            background: var(--surface);
            color: var(--forest);
            box-shadow: var(--shadow-xs);
        }

        .rs-flash {
            border-radius: var(--radius);
            padding: 11px 16px;
            font-size: 13px;
            margin-bottom: 16px;
        }

        .rs-flash-success {
            background: var(--success-bg);
            color: var(--success);
        }

        .rs-flash-error {
            background: var(--danger-bg);
            color: var(--danger);
        }

        @media (max-width: 1100px) {
            .rs-layout {
                grid-template-columns: minmax(0, 1fr);
            }

            .rs-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {

            .rs-grid,
            .rs-grid.list-view {
                grid-template-columns: 1fr;
            }

            .rs-spot-body {
                flex-direction: column;
                align-items: flex-start;
            }

            .rs-spot-thumb {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/app_shell.php'; ?>

        <main class="main fade-in">

            <div class="page-hd" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
                <div>
                    <h1>Resources</h1>
                    <p>Share reviewers and handouts with your club, and download what others have shared.</p>
                </div>
                <?php if ($can_upload): ?>
                    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-top:4px;">
                        <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('uploadModal').classList.add('open')">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4 4 4M4 17v1a3 3 0 003 3h10a3 3 0 003-3v-1" /></svg>
                            Upload Resource
                        </button>
                    </div>
                <?php endif; ?>
            </div>


            <!-- Filters -->
            <form method="GET" class="rs-toolbar">
                <div class="rs-search">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m20 20-4-4" /></svg>
                    <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search resources by title or description…">
                </div>
                <select name="club" onchange="this.form.submit()" aria-label="Club">
                    <option value="">All Clubs</option>
                    <?php foreach (PC_CLUBS as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $club === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="type" onchange="this.form.submit()" aria-label="File type">
                    <option value="">All Types</option>
                    <option value="pdf" <?= $type === 'pdf' ? 'selected' : '' ?>>PDF</option>
                    <option value="docx" <?= $type === 'docx' ? 'selected' : '' ?>>DOCX</option>
                </select>
                <select name="sort" onchange="this.form.submit()" aria-label="Sort by">
                    <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>Most Recent</option>
                    <option value="downloads" <?= $sort === 'downloads' ? 'selected' : '' ?>>Most Downloaded</option>
                    <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title A–Z</option>
                </select>
                <?php if ($saved_only): ?><input type="hidden" name="saved" value="1"><?php endif; ?>
                <button type="submit" class="btn btn-ghost btn-sm">Apply</button>
            </form>

            <div class="rs-layout">
                <div>
                    <!-- Most downloaded spotlight -->
                    <?php if (!empty($spotlight)): ?>
                        <div class="rs-spotlight">
                            <div class="rs-spot-head">
                                <span class="rs-side-title">Most downloaded</span>
                                <?php if (count($spotlight) > 1): ?>
                                    <div style="display:flex;gap:6px;">
                                        <button type="button" class="btn-icon" onclick="spotStep(-1)" aria-label="Previous">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m15 19-7-7 7-7" /></svg>
                                        </button>
                                        <button type="button" class="btn-icon" onclick="spotStep(1)" aria-label="Next">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" /></svg>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php foreach ($spotlight as $i => $s): ?>
                                <div class="rs-spot-body spot-slide" data-index="<?= $i ?>" style="<?= $i === 0 ? '' : 'display:none;' ?>">
                                    <div class="rs-spot-thumb <?= $s['file_type'] === 'pdf' ? 'rs-file-pdf' : 'rs-file-docx' ?>">
                                        <svg width="46" height="46" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5" /></svg>
                                    </div>
                                    <div style="min-width:0;flex:1;">
                                        <span class="rs-tag"><?= strtoupper($s['file_type']) ?> · <?= (int)$s['download_count'] ?> downloads</span>
                                        <h3 style="font-size:17px;font-weight:700;color:var(--forest);margin:0 0 6px;"><?= htmlspecialchars($s['title']) ?></h3>
                                        <p style="font-size:13px;color:var(--gray-500);line-height:1.6;margin:0 0 12px;"><?= htmlspecialchars($s['description'] ?: 'No description provided.') ?></p>
                                        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                                            <span class="rs-club-chip"><?= htmlspecialchars($s['club']) ?></span>
                                            <span class="rs-stat">By <?= htmlspecialchars(trim($s['firstname'] . ' ' . $s['lastname'])) ?></span>
                                            <a href="<?= htmlspecialchars(url('resource-download')) ?>?id=<?= (int)$s['resource_id'] ?>" class="btn btn-primary btn-sm">Download</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;">
                        <span style="font-size:14px;font-weight:700;color:var(--forest);">
                            <?= $saved_only ? 'Saved Resources' : ($club !== '' ? htmlspecialchars($club) : 'All Resources') ?>
                            <span style="font-weight:500;color:var(--gray-400);font-size:12.5px;">(<?= count($resources) ?>)</span>
                        </span>
                        <div class="rs-view-toggle">
                            <button type="button" class="rs-view-btn active" data-view="grid" onclick="setResourceView('grid')" aria-label="Grid view">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /></svg>
                            </button>
                            <button type="button" class="rs-view-btn" data-view="list" onclick="setResourceView('list')" aria-label="List view">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
                            </button>
                        </div>
                    </div>

                    <?php if (empty($resources)): ?>
                        <div class="pcard">
                            <div class="prow-empty">
                                <p style="margin:0 0 12px;">
                                    <?php if ($search !== '' || $club !== '' || $type !== '' || $saved_only): ?>
                                        No resources match your filters yet.
                                    <?php elseif ($can_upload): ?>
                                        No resources have been shared yet. Be the first to upload a reviewer for your club.
                                    <?php else: ?>
                                        <?php // Nothing to do about it from here, so do not suggest there is. ?>
                                        No resources have been shared yet. Your mentors post reviewers and handouts here.
                                    <?php endif; ?>
                                </p>
                                <?php if ($can_upload): ?>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('uploadModal').classList.add('open')">Upload a resource</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="rs-grid" id="resourceGrid">
                            <?php foreach ($resources as $r):
                                $uploader = trim($r['firstname'] . ' ' . $r['lastname']);
                                $is_mine  = (int)$r['uploader_id'] === $user_id;
                            ?>
                                <div class="rs-card">
                                    <button type="button" class="rs-bookmark<?= $r['is_saved'] ? ' saved' : '' ?>"
                                        data-id="<?= (int)$r['resource_id'] ?>" onclick="toggleBookmark(this)"
                                        aria-label="<?= $r['is_saved'] ? 'Remove from saved' : 'Save resource' ?>">
                                        <svg fill="<?= $r['is_saved'] ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4.5A1.5 1.5 0 016.5 3h11A1.5 1.5 0 0119 4.5V21l-7-4.5L5 21V4.5Z" /></svg>
                                    </button>

                                    <div class="rs-file-icon <?= $r['file_type'] === 'pdf' ? 'rs-file-pdf' : 'rs-file-docx' ?>">
                                        <?= strtoupper($r['file_type']) ?>
                                    </div>

                                    <div class="rs-card-title"><?= htmlspecialchars($r['title']) ?></div>
                                    <?php if (!empty($r['description'])): ?>
                                        <div class="rs-card-desc"><?= htmlspecialchars($r['description']) ?></div>
                                    <?php endif; ?>

                                    <div class="rs-meta">
                                        <span class="rs-club-chip"><?= htmlspecialchars($r['club']) ?></span>
                                        <span><?= pc_filesize((int)$r['file_size']) ?></span>
                                    </div>

                                    <div class="rs-meta" style="margin-bottom:0;">
                                        <span class="rs-stat">
                                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" /></svg>
                                            <?= htmlspecialchars($uploader) ?><?= $is_mine ? ' (you)' : '' ?>
                                        </span>
                                        <span>·</span>
                                        <span><?= date('M j, Y', strtotime($r['created_at'])) ?></span>
                                    </div>

                                    <div class="rs-card-foot">
                                        <span class="rs-stat">
                                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0-4-4m4 4 4-4M4 17v1a3 3 0 003 3h10a3 3 0 003-3v-1" /></svg>
                                            <?= (int)$r['download_count'] ?> download<?= (int)$r['download_count'] === 1 ? '' : 's' ?>
                                        </span>
                                        <div style="display:flex;gap:6px;">
                                            <?php if ($is_mine): ?>
                                                <form method="POST" action="<?= htmlspecialchars(url('resource-delete')) ?>" data-pc-confirm="Remove this resource for everyone?" data-pc-tone="danger" data-pc-ok="Remove" style="display:inline;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="resource_id" value="<?= (int)$r['resource_id'] ?>">
                                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);">Remove</button>
                                                </form>
                                            <?php endif; ?>
                                            <a href="<?= htmlspecialchars(url('resource-download')) ?>?id=<?= (int)$r['resource_id'] ?>" class="btn btn-primary btn-sm">Download</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <div>
                    <div class="rs-side-card">
                        <div class="rs-side-head">
                            <span class="rs-side-title">Saved Resources</span>
                            <?php if ($saved_total > 0): ?>
                                <a href="?saved=1" class="pcard-link">View all</a>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($saved_resources)): ?>
                            <div class="prow-empty" style="padding:24px 18px;">Nothing saved yet — tap the bookmark on any resource.</div>
                        <?php else: ?>
                            <?php foreach ($saved_resources as $s): ?>
                                <div class="rs-saved-row">
                                    <span class="rs-file-icon <?= $s['file_type'] === 'pdf' ? 'rs-file-pdf' : 'rs-file-docx' ?>"
                                        style="width:36px;height:36px;margin:0;font-size:9.5px;flex-shrink:0;">
                                        <?= strtoupper($s['file_type']) ?>
                                    </span>
                                    <div style="min-width:0;flex:1;">
                                        <div style="font-size:12.5px;font-weight:700;color:var(--gray-900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($s['title']) ?></div>
                                        <div style="font-size:11px;color:var(--gray-400);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($s['club']) ?></div>
                                    </div>
                                    <a href="<?= htmlspecialchars(url('resource-download')) ?>?id=<?= (int)$s['resource_id'] ?>" class="btn-icon" aria-label="Download <?= htmlspecialchars($s['title']) ?>">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0-4-4m4 4 4-4M4 17v1a3 3 0 003 3h10a3 3 0 003-3v-1" /></svg>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="rs-side-card">
                        <div class="rs-side-head">
                            <span class="rs-side-title">Clubs</span>
                        </div>
                        <a href="?" class="rs-club-row<?= $club === '' && !$saved_only ? ' active' : '' ?>">
                            All Clubs <span class="rs-club-count"><?= $total_resources ?></span>
                        </a>
                        <?php foreach (PC_CLUBS as $c): ?>
                            <a href="?club=<?= urlencode($c) ?>" class="rs-club-row<?= $club === $c ? ' active' : '' ?>">
                                <?= htmlspecialchars($c) ?>
                                <span class="rs-club-count"><?= $club_counts[$c] ?? 0 ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Upload modal — only rendered for the roles that may post. -->
    <?php if ($can_upload): ?>
    <div id="uploadModal" class="modal-overlay">
        <div style="background:var(--surface);border-radius:var(--radius-lg);padding:26px;width:520px;max-width:95vw;max-height:88vh;overflow-y:auto;box-shadow:var(--shadow-lg);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                <div style="font-size:16px;font-weight:700;color:var(--forest);">Share a resource</div>
                <button type="button" onclick="document.getElementById('uploadModal').classList.remove('open')"
                    style="width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;cursor:pointer;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
            <p style="font-size:12.5px;color:var(--gray-500);margin:0 0 18px;">PDF or DOCX only, up to 10 MB. Everyone can see and download what you share.</p>

            <form method="POST" action="<?= htmlspecialchars(url('resource-upload')) ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>

                <label style="display:block;font-size:12px;font-weight:600;color:var(--gray-700);margin-bottom:5px;">Title <span style="color:var(--danger);">*</span></label>
                <input type="text" name="title" required maxlength="200" placeholder="e.g. Algebra Midterm Reviewer"
                    style="width:100%;font-size:13px;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-family:inherit;margin-bottom:14px;">

                <label style="display:block;font-size:12px;font-weight:600;color:var(--gray-700);margin-bottom:5px;">Club <span style="color:var(--danger);">*</span></label>
                <select name="club" required style="width:100%;font-size:13px;margin-bottom:14px;">
                    <option value="">Select a club</option>
                    <?php foreach (PC_CLUBS as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $my_club === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>

                <label style="display:block;font-size:12px;font-weight:600;color:var(--gray-700);margin-bottom:5px;">Description</label>
                <textarea name="description" rows="3" maxlength="1000" placeholder="What does this cover?"
                    style="width:100%;font-size:13px;padding:10px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);font-family:inherit;resize:vertical;margin-bottom:14px;"></textarea>

                <label style="display:block;font-size:12px;font-weight:600;color:var(--gray-700);margin-bottom:5px;">File <span style="color:var(--danger);">*</span></label>
                <input type="file" name="file" required accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                    style="width:100%;font-size:13px;padding:10px;border:1px dashed var(--border);border-radius:var(--radius-sm);background:var(--gray-50);margin-bottom:20px;">

                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('uploadModal').classList.remove('open')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload resource</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        const RESOURCE_CSRF = <?= json_encode(csrf_token()) ?>;
        const ROUTE_BOOKMARK = <?= json_encode(url('resource-bookmark')) ?>;

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        }

        function toggleProfileMenu() {
            const m = document.getElementById('profileMenu');
            if (m) m.classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const m = document.getElementById('profileMenu');
            if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]')) {
                m.classList.remove('open');
            }
        });

        // Close the upload modal when clicking the backdrop.
        // Only rendered for the roles that may post, so a mentee has no
        // such element — without this guard the TypeError stopped the rest
        // of this script, taking the view toggle and spotlight with it.
        const upModal = document.getElementById('uploadModal');
        if (upModal) upModal.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('open');
        });

        function setResourceView(view) {
            const grid = document.getElementById('resourceGrid');
            if (!grid) return;
            grid.classList.toggle('list-view', view === 'list');
            document.querySelectorAll('.rs-view-btn').forEach(b => b.classList.toggle('active', b.dataset.view === view));
            try { localStorage.setItem('resourceViewPref', view); } catch (e) {}
        }
        (function() {
            try {
                if (localStorage.getItem('resourceViewPref') === 'list') setResourceView('list');
            } catch (e) {}
        })();

        let spotIndex = 0;
        function spotStep(dir) {
            const slides = document.querySelectorAll('.spot-slide');
            if (!slides.length) return;
            slides[spotIndex].style.display = 'none';
            spotIndex = (spotIndex + dir + slides.length) % slides.length;
            slides[spotIndex].style.display = '';
        }

        async function toggleBookmark(btn) {
            btn.disabled = true;
            try {
                const res = await fetch(ROUTE_BOOKMARK, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'resource_id=' + encodeURIComponent(btn.dataset.id) + '&csrf_token=' + encodeURIComponent(RESOURCE_CSRF)
                });
                const data = await res.json();
                if (data.success) {
                    btn.classList.toggle('saved', data.saved);
                    btn.querySelector('svg').setAttribute('fill', data.saved ? 'currentColor' : 'none');
                    btn.setAttribute('aria-label', data.saved ? 'Remove from saved' : 'Save resource');
                } else if (typeof pcToast === 'function') {
                    pcToast(data.error || 'Could not update saved resources.', 'error');
                }
            } catch (e) {
                if (typeof pcToast === 'function') pcToast('Network error. Please try again.', 'error');
            } finally {
                btn.disabled = false;
            }
        }
    </script>
</body>

</html>
