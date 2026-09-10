<?php

/**
 * admin/badge_holders.php — every badge award, in order.
 *
 * The Badges page shows the six most recent awards; this is the whole list,
 * filterable by badge and by whether the platform awarded it or an admin did.
 * It is also where "Who holds it" from a badge's ⋮ menu lands.
 *
 * Read-only apart from taking an award back, which is the one thing an admin
 * sometimes has to do and had no way to do before.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/award_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$current_page = 'admin-badges';
$csrf = csrf_token();

$badgeId = (int)($_GET['id'] ?? 0);
$source  = in_array($_GET['src'] ?? '', ['auto', 'manual'], true) ? $_GET['src'] : '';
$perPage = aw_per_page((int)($_GET['per'] ?? 20), [20, 50]);
$page    = max(1, (int)($_GET['p'] ?? 1));

$where = [];
$types = '';
$args  = [];
if ($badgeId > 0) { $where[] = 'ub.badge_id = ?'; $types .= 'i'; $args[] = $badgeId; }
if ($source === 'auto')   $where[] = 'ub.awarded_by IS NULL';
if ($source === 'manual') $where[] = 'ub.awarded_by IS NOT NULL';
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*) FROM user_badges ub $whereSql";
if ($types !== '') {
    $cs = $con->prepare($countSql);
    $cs->bind_param($types, ...$args);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_row()[0];
    $cs->close();
} else {
    $total = (int)$con->query($countSql)->fetch_row()[0];
}

$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$sql = "
    SELECT ub.user_badge_id, ub.awarded_at, ub.awarded_by,
           b.badge_id, b.name AS badge_name, b.icon, b.color, b.is_active,
           u.user_id, CONCAT_WS(' ', u.firstname, u.lastname) AS person, u.role,
           pr.profile_image,
           CONCAT_WS(' ', a.firstname, a.lastname) AS by_name
      FROM user_badges ub
      JOIN badges b ON b.badge_id = ub.badge_id
      JOIN users  u ON u.user_id  = ub.user_id
      LEFT JOIN profile pr ON pr.user_id = u.user_id
      LEFT JOIN users a    ON a.user_id  = ub.awarded_by
    $whereSql
     ORDER BY ub.awarded_at DESC, ub.user_badge_id DESC
     LIMIT $perPage OFFSET $offset";

if ($types !== '') {
    $ls = $con->prepare($sql);
    $ls->bind_param($types, ...$args);
    $ls->execute();
    $rows = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
    $ls->close();
} else {
    $rows = $con->query($sql)->fetch_all(MYSQLI_ASSOC);
}

$allBadges = $con->query("
    SELECT b.badge_id, b.name, COUNT(ub.user_badge_id) n
      FROM badges b LEFT JOIN user_badges ub ON ub.badge_id = b.badge_id
     GROUP BY b.badge_id ORDER BY b.name
")->fetch_all(MYSQLI_ASSOC);

$chosen = null;
foreach ($allBadges as $b) if ((int)$b['badge_id'] === $badgeId) $chosen = $b;

$autoN   = (int)$con->query("SELECT COUNT(*) FROM user_badges WHERE awarded_by IS NULL")->fetch_row()[0];
$manualN = (int)$con->query("SELECT COUNT(*) FROM user_badges WHERE awarded_by IS NOT NULL")->fetch_row()[0];

function bh_url(array $over = []): string
{
    $qs = array_merge($_GET, $over);
    $qs = array_filter($qs, fn($v) => $v !== '' && $v !== null);
    return url('admin-badge-holders') . ($qs ? '?' . http_build_query($qs) : '');
}

include 'layout.php';
require_once __DIR__ . '/includes/sessions_ui.php';
?>

<style>
    .bh-crumb { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--gray-400); margin-bottom: 6px; }
    .bh-crumb a { color: var(--gray-500); text-decoration: none; font-weight: 600; }
    .bh-crumb a:hover { color: var(--mint); }
    .bh-crumb b { color: var(--forest); font-weight: 600; }

    .bh-bar { display: flex; gap: 9px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
    .bh-bar select { padding: 10px 12px; border: 1px solid var(--gray-200); border-radius: 10px; background: #fff;
                     font-family: inherit; font-size: 13px; color: var(--gray-700); outline: none; }
    .bh-bar button { padding: 10px 16px; border: 1px solid var(--forest); border-radius: 10px; background: var(--forest);
                     color: #fff; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
    .bh-reset { padding: 10px 15px; border: 1px solid var(--gray-200); border-radius: 10px; background: #fff;
                font-size: 13px; font-weight: 600; color: var(--gray-600); text-decoration: none; }

    .bh-t { width: 100%; border-collapse: collapse; font-size: 13px; }
    .bh-t th { text-align: left; padding: 0 10px 10px 0; font-size: 10.5px; font-weight: 700;
               text-transform: uppercase; letter-spacing: .05em; color: var(--gray-400); white-space: nowrap; }
    .bh-t td { padding: 11px 10px 11px 0; border-top: 1px solid var(--gray-100); vertical-align: middle; }
    .bh-t th:last-child, .bh-t td:last-child { padding-right: 0; text-align: right; }
    .bh-t tbody tr:hover { background: #FAFBFC; }

    .bh-who { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .bh-av { width: 32px; height: 32px; flex: none; border-radius: 50%; overflow: hidden; background: var(--forest);
             color: #fff; display: grid; place-items: center; font-size: 12px; font-weight: 700; }
    .bh-av img { width: 100%; height: 100%; object-fit: cover; }
    .bh-who a { text-decoration: none; }
    .bh-who b { display: block; font-size: 13px; font-weight: 600; color: var(--gray-800); }
    .bh-who a:hover b { color: var(--mint); }
    .bh-role { display: block; font-size: 11px; color: var(--gray-400); text-transform: capitalize; }

    .bh-badge { display: inline-flex; align-items: center; gap: 8px; padding: 4px 11px 4px 5px; border-radius: 999px; font-size: 12px; font-weight: 600; }
    .bh-badge svg { width: 15px; height: 15px; flex: none; }

    .bh-src { padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .bh-src.auto { background: #E4EEFB; color: #1A5C9A; }
    .bh-src.hand { background: #EDEFF3; color: #414A5C; }

    .bh-take { padding: 7px 13px; border: 1px solid var(--gray-200); border-radius: 9px; background: #fff;
               font-family: inherit; font-size: 12.5px; font-weight: 600; color: var(--gray-600); cursor: pointer; }
    .bh-take:hover { border-color: #A6301F; color: #A6301F; }

    .bh-pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
    .bh-pages { display: flex; gap: 5px; }
    .bh-pages a, .bh-pages span { min-width: 32px; height: 32px; padding: 0 9px; border: 1px solid var(--gray-200); border-radius: 9px;
                                  background: #fff; display: inline-flex; align-items: center; justify-content: center;
                                  font-size: 12.5px; font-weight: 600; color: var(--gray-600); text-decoration: none; }
    .bh-pages .on { background: var(--forest); border-color: var(--forest); color: #fff; }
    .bh-empty { padding: 40px 20px; text-align: center; color: var(--gray-400); font-size: 13px; }
</style>

<div class="bh-crumb">
    <a href="<?= url('admin-badges') ?>">Badges &amp; Achievements</a><span>›</span><b>Award history</b>
</div>

<div class="ss-hd">
    <div>
        <h1><?= $chosen ? htmlspecialchars($chosen['name']) . ' — who holds it' : 'Award history' ?></h1>
        <p>Every badge ever given out, newest first. <?= $autoN ?> awarded automatically, <?= $manualN ?> by hand.</p>
    </div>
    <div class="ss-hd-actions">
        <a class="ss-export" href="<?= url('admin-badges') ?>">Back to badges</a>
    </div>
</div>

<div class="ss-card">
    <form class="bh-bar" method="get" action="<?= url('admin-badge-holders') ?>">
        <select name="id">
            <option value="">Every badge</option>
            <?php foreach ($allBadges as $b): ?>
                <option value="<?= (int)$b['badge_id'] ?>" <?= $badgeId === (int)$b['badge_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($b['name']) ?> (<?= (int)$b['n'] ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <select name="src">
            <option value="">Awarded by anyone</option>
            <option value="auto"   <?= $source === 'auto' ? 'selected' : '' ?>>By the platform (<?= $autoN ?>)</option>
            <option value="manual" <?= $source === 'manual' ? 'selected' : '' ?>>By an admin (<?= $manualN ?>)</option>
        </select>
        <input type="hidden" name="per" value="<?= $perPage ?>">
        <button type="submit">Apply</button>
        <?php if ($badgeId > 0 || $source !== ''): ?>
            <a class="bh-reset" href="<?= url('admin-badge-holders') ?>">Reset</a>
        <?php endif; ?>
    </form>

    <?php if (!$rows): ?>
        <p class="bh-empty"><?= $total === 0 && $badgeId === 0 && $source === '' ? 'No badge has been awarded yet.' : 'Nothing matches these filters.' ?></p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="bh-t">
                <thead>
                    <tr><th>Mentor</th><th>Badge</th><th>Awarded</th><th>By</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        [$tint, $ink] = aw_color($r['color']); ?>
                        <tr>
                            <td>
                                <span class="bh-who">
                                    <span class="bh-av">
                                        <?php if (!empty($r['profile_image'])): ?><img src="<?= htmlspecialchars($r['profile_image']) ?>" alt="">
                                        <?php else: ?><?= htmlspecialchars(strtoupper(substr(trim($r['person']), 0, 1))) ?><?php endif; ?>
                                    </span>
                                    <span style="min-width:0;">
                                        <a href="<?= url('admin-user') ?>?id=<?= (int)$r['user_id'] ?>"><b><?= htmlspecialchars($r['person']) ?></b></a>
                                        <span class="bh-role"><?= htmlspecialchars((string)$r['role']) ?></span>
                                    </span>
                                </span>
                            </td>
                            <td>
                                <span class="bh-badge" style="background:<?= $tint ?>;color:<?= $ink ?>;">
                                    <?= aw_icon($r['icon']) ?><?= htmlspecialchars($r['badge_name']) ?>
                                </span>
                                <?php if (!(int)$r['is_active']): ?>
                                    <span style="font-size:11px;color:var(--gray-400);"> · switched off</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--gray-500);white-space:nowrap;"><?= date('M j, Y', strtotime($r['awarded_at'])) ?></td>
                            <td>
                                <?php if ($r['awarded_by']): ?>
                                    <span class="bh-src hand"><?= htmlspecialchars(trim((string)$r['by_name']) !== '' ? $r['by_name'] : 'An admin') ?></span>
                                <?php else: ?>
                                    <span class="bh-src auto">Automatic</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?= url('admin-award-badge') ?>" style="margin:0;display:inline;"
                                      onsubmit="return confirm('Take the <?= htmlspecialchars(addslashes($r['badge_name'])) ?> badge back from <?= htmlspecialchars(addslashes($r['person'])) ?>?\n\nIt disappears from their profile. If the badge is automatic, the next award check will give it back when they still qualify.');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="do" value="revoke">
                                    <input type="hidden" name="user_badge_id" value="<?= (int)$r['user_badge_id'] ?>">
                                    <input type="hidden" name="back" value="<?= htmlspecialchars(bh_url()) ?>">
                                    <button type="submit" class="bh-take">Take back</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="bh-pager">
            <span style="font-size:12.5px;color:var(--gray-400);">
                Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $total) ?> of <?= $total ?> award<?= $total === 1 ? '' : 's' ?>
            </span>
            <?php if ($pages > 1): ?>
                <span class="bh-pages">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <?php if ($i === $page): ?><span class="on"><?= $i ?></span>
                        <?php else: ?><a href="<?= bh_url(['p' => $i]) ?>"><?= $i ?></a><?php endif; ?>
                    <?php endfor; ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
