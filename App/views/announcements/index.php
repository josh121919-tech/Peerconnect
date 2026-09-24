<?php

/**
 * announcements/index.php — announcements, as a member sees them.
 *
 * One page for both roles. A mentor and a mentee see the same thing except
 * for what is addressed to them: an announcement aimed at mentors never
 * appears in a mentee's list, and the query enforces that rather than the
 * markup, so there is no way to reach a hidden one by guessing a URL.
 *
 * Opening one records a read. That single row is where the admin side's reach
 * figure comes from, which is why it is written here and not estimated there.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/announcement_data.php';

// Signed in AND holding a real role. isset() alone let the role-less account
// through, because an empty string is still "set" — see the onboarding gap.
// admin belongs in the list: it is redirected to the admin screen just below.
if (!isset($_SESSION['user_id'], $_SESSION['role'])
    || !in_array($_SESSION['role'], ['mentee', 'mentor', 'admin'], true)) {
    header('Location: ' . url('welcomepage'));
    exit;
}

date_default_timezone_set('Asia/Manila');

$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];

// An admin reaching this page is sent to the screen that manages them.
if ($role === 'admin') {
    header('Location: ' . url('admin-announcements'));
    exit;
}

// Anything scheduled whose time has come goes live now, and its audience is
// told — the same release the admin page performs, from whichever side of the
// app happens to be visited first.
foreach (pc_ann_release($con) as $rid) {
    $rs = $con->prepare("SELECT title, audience" . pc_ann_club_col($con, 'announcements')
                        . " FROM announcements WHERE announcement_id = ?");
    $rs->bind_param('i', $rid);
    $rs->execute();
    $row = $rs->get_result()->fetch_assoc();
    $rs->close();
    if ($row) pc_ann_send($con, $rid, $row['title'], $row['audience'], $row['audience_club'] ?? null);
}

$CATS = pc_ann_categories();

/*
 * The club this member is in, which decides whether a club-targeted
 * announcement reaches them. Worked out once: every query below asks the same
 * question, and pc_ann_visible() hands back the bindings with the clause so
 * none of them can bind a different answer.
 */
$myClub = pc_ann_club_of($con, $uid);
[$visSql, $visTypes, $visArgs] = pc_ann_visible($con, $role, $myClub, 'a');

/* ── Opening one marks it read ────────────────────────────────────────── */
$open = (int)($_GET['open'] ?? 0);
if ($open > 0) {
    // Only if it is genuinely visible to this person — a read must not be
    // recorded for something they are not allowed to see.
    $ck = $con->prepare("SELECT announcement_id FROM announcements a WHERE a.announcement_id = ? AND " . $visSql);
    $ck->bind_param('i' . $visTypes, $open, ...$visArgs);
    $ck->execute();
    $allowed = (bool)$ck->get_result()->fetch_row();
    $ck->close();

    if ($allowed) {
        $mk = $con->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, user_id, read_at) VALUES (?, ?, NOW())");
        $mk->bind_param('ii', $open, $uid);
        $mk->execute();
        $mk->close();
    } else {
        $open = 0;
    }
}

/* ── Mark everything read ─────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'read_all') {
    if (verify_csrf()) {
        $mk = $con->prepare("
            INSERT IGNORE INTO announcement_reads (announcement_id, user_id, read_at)
            SELECT a.announcement_id, ?, NOW() FROM announcements a
            WHERE " . $visSql
        );
        $mk->bind_param('i' . $visTypes, $uid, ...$visArgs);
        $mk->execute();
        $n = $mk->affected_rows;
        $mk->close();
        pc_flash('success', $n > 0 ? $n . ' marked as read.' : 'Everything was already read.', 'Announcements');
    } else {
        pc_flash('error', 'Security token mismatch. Please refresh and try again.');
    }
    header('Location: ' . url('announcements'));
    exit;
}

/* ── Filters ──────────────────────────────────────────────────────────── */
$VIEWS = ['all', 'unread'];
$view  = in_array($_GET['tab'] ?? '', $VIEWS, true) ? $_GET['tab'] : 'all';
$cat   = array_key_exists($_GET['cat'] ?? '', $CATS) ? $_GET['cat'] : '';
$q     = trim((string)($_GET['q'] ?? ''));

$clauses = [$visSql];
$types   = 'i' . $visTypes;    // read-join user id, then whatever visibility binds
$args    = array_merge([$uid], $visArgs);

if ($cat !== '') { $clauses[] = 'a.category = ?'; $types .= 's'; $args[] = $cat; }
if ($q !== '')   { $clauses[] = "CONCAT_WS(' ', a.title, a.body) LIKE ?"; $types .= 's'; $args[] = '%' . $q . '%'; }
if ($view === 'unread') $clauses[] = 'r.user_id IS NULL';

$where = 'WHERE ' . implode(' AND ', $clauses);

$rows = [];
$ls = $con->prepare("
    SELECT a.announcement_id, a.title, a.body, a.category, a.audience, a.image_path,
           a.is_pinned, a.published_at, a.created_at,
           CONCAT_WS(' ', u.firstname, u.lastname) author, p.profile_image author_pic,
           r.read_at
    FROM announcements a
    JOIN users u ON u.user_id = a.created_by
    LEFT JOIN profile p ON p.user_id = a.created_by
    LEFT JOIN announcement_reads r ON r.announcement_id = a.announcement_id AND r.user_id = ?
    $where
    ORDER BY a.is_pinned DESC, COALESCE(a.published_at, a.created_at) DESC
");
$ls->bind_param($types, ...$args);
$ls->execute();
$rows = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
$ls->close();

$unread = pc_ann_unread_count($con, $uid, $role);

// Counts for the two tabs, unaffected by the search box.
$cs = $con->prepare("
    SELECT COUNT(*) all_c, SUM(r.user_id IS NULL) unread_c
    FROM announcements a
    LEFT JOIN announcement_reads r ON r.announcement_id = a.announcement_id AND r.user_id = ?
    WHERE " . $visSql
);
$cs->bind_param('i' . $visTypes, $uid, ...$visArgs);
$cs->execute();
$tabCounts = $cs->get_result()->fetch_assoc();
$cs->close();

$byCat = $con->prepare("
    SELECT a.category, COUNT(*) c FROM announcements a
    WHERE " . $visSql . " GROUP BY a.category ORDER BY c DESC
");
$byCat->bind_param($visTypes, ...$visArgs);
$byCat->execute();
$cats = $byCat->get_result()->fetch_all(MYSQLI_ASSOC);
$byCat->close();

function ma_url(array $over = []): string
{
    $p = array_merge(['tab' => $_GET['tab'] ?? null, 'cat' => $_GET['cat'] ?? null, 'q' => $_GET['q'] ?? null], $over);
    $p = array_filter($p, fn($v) => $v !== null && $v !== '');
    return url('announcements') . ($p ? '?' . http_build_query($p) : '');
}

$active_page = 'announcements';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
    .ma-wrap { max-width: 1080px; }
    .ma-hd { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .ma-hd h1 { margin: 0; font-size: 24px; font-weight: 700; color: var(--forest); letter-spacing: -.02em; }
    .ma-hd p { margin: 3px 0 0; font-size: 13.5px; color: var(--gray-400); }
    .ma-readall { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border: 1px solid var(--gray-200);
                  border-radius: 10px; background: #fff; font-family: inherit; font-size: 13px; font-weight: 600;
                  color: var(--gray-700); cursor: pointer; }
    .ma-readall:hover { border-color: var(--mint); color: var(--mint-deep, #00539B); }

    .ma-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
    .ma-tab { display: inline-flex; align-items: center; gap: 8px; padding: 8px 15px; border: 1px solid var(--gray-200);
              border-radius: 10px; background: #fff; font-size: 13px; font-weight: 600; color: var(--gray-600); text-decoration: none; }
    .ma-tab.on { background: var(--primary); border-color: var(--primary); color: #fff; }
    .ma-tab-n { padding: 1px 7px; border-radius: 999px; background: var(--gray-100); color: var(--gray-600); font-size: 11.5px; }
    .ma-tab.on .ma-tab-n { background: rgba(255,255,255,.22); color: #fff; }

    .ma-tools { display: flex; gap: 9px; flex-wrap: wrap; margin-bottom: 16px; }
    .ma-field { display: flex; align-items: center; gap: 7px; padding: 0 12px; height: 38px; background: #fff;
                border: 1px solid var(--gray-200); border-radius: 10px; }
    .ma-field.grow { flex: 1; min-width: 200px; }
    .ma-field:focus-within { border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0,135,207,.13); }
    .ma-field svg { width: 15px; height: 15px; color: var(--gray-400); flex: none; }
    .ma-field input, .ma-field select { border: 0; outline: 0; background: none; font-family: inherit; font-size: 13px; color: var(--gray-700); width: 100%; }
    .ma-go { padding: 0 18px; height: 38px; border: 0; border-radius: 10px; background: var(--mint); color: #fff;
             font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }

    .ma-list { display: flex; flex-direction: column; gap: 12px; }
    .ma-card { background: #fff; border: 1px solid var(--gray-100); border-radius: 14px; overflow: hidden;
               box-shadow: 0 1px 2px rgba(16,24,40,.04); }
    .ma-card.unread { border-left: 3px solid var(--mint); }
    .ma-card.pinned { border-color: var(--mint-soft); }
    .ma-banner { width: 100%; max-height: 200px; object-fit: cover; display: block; }
    .ma-in { padding: 16px 18px; }
    .ma-top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
    .ma-cat { padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
    .ma-new { padding: 2px 9px; border-radius: 999px; background: var(--mint); color: #fff; font-size: 10.5px; font-weight: 700; }
    .ma-when { margin-left: auto; font-size: 12px; color: var(--gray-400); }
    .ma-t { margin: 0 0 6px; font-size: 16.5px; font-weight: 700; color: var(--forest); letter-spacing: -.01em; }
    .ma-b { margin: 0; font-size: 13.5px; line-height: 1.65; color: var(--gray-700); white-space: pre-wrap; }
    .ma-b.clip { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    .ma-foot { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--gray-100); }
    .ma-by { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--gray-500); }
    .ma-av { width: 26px; height: 26px; border-radius: 50%; overflow: hidden; background: #E7F0FB; color: #1E4E86;
             display: grid; place-items: center; font-size: 10px; font-weight: 700; flex: none; }
    .ma-av img { width: 100%; height: 100%; object-fit: cover; }
    .ma-more { margin-left: auto; font-size: 12.5px; font-weight: 600; color: var(--mint); text-decoration: none; }
    .ma-more:hover { text-decoration: underline; }

    .ma-empty { padding: 60px 20px; text-align: center; background: #fff; border: 1px solid var(--gray-100); border-radius: 14px; }
    .ma-empty svg { width: 42px; height: 42px; color: var(--gray-300); }
    .ma-empty h3 { margin: 12px 0 4px; font-size: 15px; font-weight: 700; color: var(--forest); }
    .ma-empty p { margin: 0; font-size: 13.5px; color: var(--gray-400); }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/../includes/app_shell.php'; ?>

        <main class="main fade-in">

<div class="ma-wrap">
    <div class="ma-hd">
        <div>
            <h1>Announcements</h1>
            <p><?= $unread > 0
                ? $unread . ' new ' . ($unread === 1 ? 'announcement' : 'announcements') . ' for you.'
                : 'News and updates from the PeerConnect team.' ?></p>
        </div>
        <?php if ($unread > 0): ?>
            <form method="post" action="<?= url('announcements') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="read_all">
                <button type="submit" class="ma-readall">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m4 12.5 4.5 4.5L20 6" /></svg>
                    Mark all read
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="ma-tabs">
        <a class="ma-tab <?= $view === 'all' ? 'on' : '' ?>" href="<?= ma_url(['tab' => null]) ?>">
            All<span class="ma-tab-n"><?= (int)$tabCounts['all_c'] ?></span>
        </a>
        <a class="ma-tab <?= $view === 'unread' ? 'on' : '' ?>" href="<?= ma_url(['tab' => 'unread']) ?>">
            Unread<span class="ma-tab-n"><?= (int)$tabCounts['unread_c'] ?></span>
        </a>
    </div>

    <?php if ($rows || $q !== '' || $cat !== ''): ?>
        <form class="ma-tools" method="get" action="<?= url('announcements') ?>">
            <?php if ($view !== 'all'): ?><input type="hidden" name="tab" value="<?= htmlspecialchars($view) ?>"><?php endif; ?>
            <div class="ma-field grow">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m20 20-4-4" /></svg>
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search announcements…">
            </div>
            <div class="ma-field">
                <select name="cat" aria-label="Category">
                    <option value="">All categories</option>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= htmlspecialchars($c['category']) ?>" <?= $cat === $c['category'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['category']) ?> (<?= (int)$c['c'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="ma-go">Search</button>
        </form>
    <?php endif; ?>

    <?php if (!$rows): ?>
        <div class="ma-empty">
            <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5.9 5 9H3a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h2l6 3.1V5.9ZM16 9a4 4 0 0 1 0 6M19 6.5a8 8 0 0 1 0 11" /></svg>
            <h3><?= ($q !== '' || $cat !== '' || $view === 'unread') ? 'Nothing here' : 'No announcements yet' ?></h3>
            <p><?= $view === 'unread' ? 'You have read everything.' : (($q !== '' || $cat !== '') ? 'Nothing matches that search.' : 'When the team posts news, it will appear here.') ?></p>
        </div>
    <?php else: ?>
        <div class="ma-list">
            <?php foreach ($rows as $a):
                [$cfg, $cbg] = pc_ann_category_color($a['category']);
                $id = (int)$a['announcement_id'];
                $isNew = $a['read_at'] === null;
                // Long messages are clipped until opened; opening is also what
                // records the read, so the two go together.
                $long = mb_strlen($a['body']) > 240;
                $isOpen = ($open === $id) || !$long;
            ?>
                <article class="ma-card<?= $isNew ? ' unread' : '' ?><?= (int)$a['is_pinned'] ? ' pinned' : '' ?>" id="a<?= $id ?>">
                    <?php if (!empty($a['image_path'])): ?>
                        <img class="ma-banner" src="<?= htmlspecialchars($a['image_path']) ?>" alt="">
                    <?php endif; ?>
                    <div class="ma-in">
                        <div class="ma-top">
                            <?php if ((int)$a['is_pinned']): ?><span title="Pinned">📌</span><?php endif; ?>
                            <span class="ma-cat" style="background:<?= $cbg ?>;color:<?= $cfg ?>;"><?= htmlspecialchars($a['category']) ?></span>
                            <?php if ($isNew): ?><span class="ma-new">NEW</span><?php endif; ?>
                            <?php if ($a['audience'] !== 'all'): ?>
                                <span class="ma-cat" style="background:var(--gray-100);color:var(--gray-600);">
                                    For <?= $a['audience'] === 'mentor' ? 'mentors' : 'mentees' ?>
                                </span>
                            <?php endif; ?>
                            <span class="ma-when"><?= pc_ann_ago($a['published_at'] ?: $a['created_at']) ?></span>
                        </div>

                        <h2 class="ma-t"><?= htmlspecialchars($a['title']) ?></h2>
                        <p class="ma-b<?= $isOpen ? '' : ' clip' ?>"><?= htmlspecialchars($a['body']) ?></p>

                        <div class="ma-foot">
                            <span class="ma-by">
                                <span class="ma-av">
                                    <?= $a['author_pic'] ? '<img src="' . htmlspecialchars($a['author_pic']) . '" alt="">' : htmlspecialchars(strtoupper(substr($a['author'], 0, 2))) ?>
                                </span>
                                <?= htmlspecialchars($a['author']) ?>
                                &middot; <?= date('M j, Y', strtotime($a['published_at'] ?: $a['created_at'])) ?>
                            </span>
                            <?php if ($long && !$isOpen): ?>
                                <a class="ma-more" href="<?= ma_url(['open' => $id]) ?>#a<?= $id ?>">Read more →</a>
                            <?php elseif ($isNew): ?>
                                <a class="ma-more" href="<?= ma_url(['open' => $id]) ?>#a<?= $id ?>">Mark as read</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

        </main>
    </div>
</body>

</html>
