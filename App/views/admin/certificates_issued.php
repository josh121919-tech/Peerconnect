<?php

/**
 * admin/certificates_issued.php — every certificate issued.
 *
 * The Certificates page shows the five most recent; this is the whole list,
 * filterable by design and by recipient, with a link straight to the document
 * each row stands for. Taking one back lives here too — it is the only
 * destructive thing on the page and it is one row at a time.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/award_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$current_page = 'admin-certificates';
$csrf = csrf_token();

$tplId   = (int)($_GET['tpl'] ?? 0);
$q       = trim((string)($_GET['q'] ?? ''));
$perPage = aw_per_page((int)($_GET['per'] ?? 20), [20, 50]);
$page    = max(1, (int)($_GET['p'] ?? 1));

$where = [];
$types = '';
$args  = [];
if ($tplId > 0) { $where[] = 'uc.template_id = ?'; $types .= 'i'; $args[] = $tplId; }
if ($q !== '')  {
    $where[] = "(CONCAT_WS(' ', u.firstname, u.lastname) LIKE ? OR uc.achievement LIKE ?)";
    $types .= 'ss'; $args[] = "%$q%"; $args[] = "%$q%";
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$base = "
    FROM user_certificates uc
    JOIN certificate_templates t ON t.template_id = uc.template_id
    JOIN users u ON u.user_id = uc.user_id
    LEFT JOIN profile pr ON pr.user_id = u.user_id
    LEFT JOIN users a ON a.user_id = uc.awarded_by
    $whereSql";

$countSql = "SELECT COUNT(*) $base";
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
    SELECT uc.cert_id, uc.achievement, uc.awarded_at,
           t.template_id, t.name AS template_name, t.category, t.design,
           u.user_id, CONCAT_WS(' ', u.firstname, u.lastname) AS person, u.role,
           pr.profile_image,
           CONCAT_WS(' ', a.firstname, a.lastname) AS by_name
    $base
     ORDER BY uc.awarded_at DESC, uc.cert_id DESC
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

$allTemplates = $con->query("
    SELECT t.template_id, t.name, COUNT(uc.cert_id) n
      FROM certificate_templates t
      LEFT JOIN user_certificates uc ON uc.template_id = t.template_id
     GROUP BY t.template_id ORDER BY t.name
")->fetch_all(MYSQLI_ASSOC);

$chosen = null;
foreach ($allTemplates as $t) if ((int)$t['template_id'] === $tplId) $chosen = $t;

$catColors = [
    'Achievement' => '#B7791F', 'Participation' => '#1B6FD1', 'Appreciation' => '#17654B',
    'Completion'  => '#5A3E96', 'Milestone'     => '#0E6C77', 'Leadership'   => '#9B2C43',
];

function ci_url(array $over = []): string
{
    $qs = array_merge($_GET, $over);
    $qs = array_filter($qs, fn($v) => $v !== '' && $v !== null);
    return url('admin-certificates-issued') . ($qs ? '?' . http_build_query($qs) : '');
}

include 'layout.php';
require_once __DIR__ . '/includes/sessions_ui.php';
?>

<style>
    .ci-crumb { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--gray-400); margin-bottom: 6px; }
    .ci-crumb a { color: var(--gray-500); text-decoration: none; font-weight: 600; }
    .ci-crumb a:hover { color: var(--mint); }
    .ci-crumb b { color: var(--forest); font-weight: 600; }

    .ci-bar { display: flex; gap: 9px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
    .ci-bar select, .ci-bar input[type=search] {
        padding: 10px 12px; border: 1px solid var(--gray-200); border-radius: 10px; background: #fff;
        font-family: inherit; font-size: 13px; color: var(--gray-700); outline: none;
    }
    .ci-bar input[type=search] { flex: 1; min-width: 190px; }
    .ci-bar input:focus, .ci-bar select:focus { border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0,135,207,.13); }
    .ci-bar button { padding: 10px 16px; border: 1px solid var(--forest); border-radius: 10px; background: var(--forest);
                     color: #fff; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
    .ci-reset { padding: 10px 15px; border: 1px solid var(--gray-200); border-radius: 10px; background: #fff;
                font-size: 13px; font-weight: 600; color: var(--gray-600); text-decoration: none; }

    .ci-t { width: 100%; border-collapse: collapse; font-size: 13px; }
    .ci-t th { text-align: left; padding: 0 10px 10px 0; font-size: 10.5px; font-weight: 700;
               text-transform: uppercase; letter-spacing: .05em; color: var(--gray-400); white-space: nowrap; }
    .ci-t td { padding: 11px 10px 11px 0; border-top: 1px solid var(--gray-100); vertical-align: middle; }
    .ci-t th:last-child, .ci-t td:last-child { padding-right: 0; text-align: right; }
    .ci-t tbody tr:hover { background: #FAFBFC; }

    .ci-who { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .ci-av { width: 32px; height: 32px; flex: none; border-radius: 50%; overflow: hidden; background: var(--forest);
             color: #fff; display: grid; place-items: center; font-size: 12px; font-weight: 700; }
    .ci-av img { width: 100%; height: 100%; object-fit: cover; }
    .ci-who a { text-decoration: none; }
    .ci-who b { display: block; font-size: 13px; font-weight: 600; color: var(--gray-800); }
    .ci-who a:hover b { color: var(--mint); }
    .ci-who span { display: block; font-size: 11px; color: var(--gray-400); text-transform: capitalize; }

    .ci-chip { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .ci-ach { color: var(--gray-600); max-width: 260px; }
    .ci-ref { font-family: ui-monospace, monospace; font-size: 11.5px; color: var(--gray-400); white-space: nowrap; }

    .ci-open, .ci-take { padding: 7px 13px; border: 1px solid var(--gray-200); border-radius: 9px; background: #fff;
                         font-family: inherit; font-size: 12.5px; font-weight: 600; color: var(--gray-600);
                         cursor: pointer; text-decoration: none; display: inline-block; }
    .ci-open:hover { border-color: var(--mint); color: var(--mint); }
    .ci-take:hover { border-color: #A6301F; color: #A6301F; }

    .ci-pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
    .ci-pages { display: flex; gap: 5px; }
    .ci-pages a, .ci-pages span { min-width: 32px; height: 32px; padding: 0 9px; border: 1px solid var(--gray-200); border-radius: 9px;
                                  background: #fff; display: inline-flex; align-items: center; justify-content: center;
                                  font-size: 12.5px; font-weight: 600; color: var(--gray-600); text-decoration: none; }
    .ci-pages .on { background: var(--forest); border-color: var(--forest); color: #fff; }
    .ci-empty { padding: 40px 20px; text-align: center; color: var(--gray-400); font-size: 13px; }

    @media (max-width: 900px) { .ci-ach, .ci-ref { display: none; } }
</style>

<div class="ci-crumb">
    <a href="<?= url('admin-certificates') ?>">Certificates</a><span>›</span><b>Issuance history</b>
</div>

<div class="ss-hd">
    <div>
        <h1><?= $chosen ? htmlspecialchars($chosen['name']) . ' — who has it' : 'Issuance history' ?></h1>
        <p>Every certificate issued, newest first. Each one opens as the document the recipient sees.</p>
    </div>
    <div class="ss-hd-actions">
        <a class="ss-export" href="<?= url('admin-certificates') ?>">Back to designs</a>
    </div>
</div>

<div class="ss-card">
    <form class="ci-bar" method="get" action="<?= url('admin-certificates-issued') ?>">
        <select name="tpl">
            <option value="">Every design</option>
            <?php foreach ($allTemplates as $t): ?>
                <option value="<?= (int)$t['template_id'] ?>" <?= $tplId === (int)$t['template_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['name']) ?> (<?= (int)$t['n'] ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by name or what it was for…">
        <input type="hidden" name="per" value="<?= $perPage ?>">
        <button type="submit">Apply</button>
        <?php if ($tplId > 0 || $q !== ''): ?>
            <a class="ci-reset" href="<?= url('admin-certificates-issued') ?>">Reset</a>
        <?php endif; ?>
    </form>

    <?php if (!$rows): ?>
        <p class="ci-empty">
            <?= $total === 0 && $tplId === 0 && $q === ''
                ? 'No certificate has been issued yet.'
                : 'Nothing matches these filters.' ?>
        </p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="ci-t">
                <thead>
                    <tr><th>Recipient</th><th>Certificate</th><th class="ci-ach">For</th><th>Issued</th><th class="ci-ref">Reference</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $col = $catColors[$r['category']] ?? '#414A5C';
                        $ref = aw_cert_ref((int)$r['cert_id'], $r['awarded_at']); ?>
                        <tr>
                            <td>
                                <span class="ci-who">
                                    <span class="ci-av">
                                        <?php if (!empty($r['profile_image'])): ?><img src="<?= htmlspecialchars($r['profile_image']) ?>" alt="">
                                        <?php else: ?><?= htmlspecialchars(strtoupper(substr(trim($r['person']), 0, 1))) ?><?php endif; ?>
                                    </span>
                                    <span style="min-width:0;">
                                        <a href="<?= url('admin-user') ?>?id=<?= (int)$r['user_id'] ?>"><b><?= htmlspecialchars($r['person']) ?></b></a>
                                        <span><?= htmlspecialchars((string)$r['role']) ?></span>
                                    </span>
                                </span>
                            </td>
                            <td>
                                <div style="font-weight:600;color:var(--gray-800);margin-bottom:3px;"><?= htmlspecialchars($r['template_name']) ?></div>
                                <span class="ci-chip" style="background:<?= $col ?>1A;color:<?= $col ?>;"><?= htmlspecialchars($r['category']) ?></span>
                            </td>
                            <td class="ci-ach"><?= htmlspecialchars((string)$r['achievement']) ?></td>
                            <td style="color:var(--gray-500);white-space:nowrap;">
                                <?= date('M j, Y', strtotime($r['awarded_at'])) ?>
                                <?php if (trim((string)$r['by_name']) !== ''): ?>
                                    <span style="display:block;font-size:11px;color:var(--gray-400);">by <?= htmlspecialchars($r['by_name']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="ci-ref"><?= htmlspecialchars($ref) ?></td>
                            <td style="white-space:nowrap;">
                                <a class="ci-open" href="<?= url('certificate-view') ?>?id=<?= (int)$r['cert_id'] ?>" target="_blank" rel="noopener">Open</a>
                                <form method="post" action="<?= url('admin-action-certificate') ?>" style="margin:0;display:inline;"
                                      onsubmit="return confirm('Take this certificate back from <?= htmlspecialchars(addslashes($r['person'])) ?>?\n\nIt stops opening for them. They are not notified.');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="do" value="revoke">
                                    <input type="hidden" name="cert_id" value="<?= (int)$r['cert_id'] ?>">
                                    <input type="hidden" name="back" value="<?= htmlspecialchars(ci_url()) ?>">
                                    <button type="submit" class="ci-take">Revoke</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="ci-pager">
            <span style="font-size:12.5px;color:var(--gray-400);">
                Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $total) ?> of <?= $total ?> certificate<?= $total === 1 ? '' : 's' ?>
            </span>
            <?php if ($pages > 1): ?>
                <span class="ci-pages">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <?php if ($i === $page): ?><span class="on"><?= $i ?></span>
                        <?php else: ?><a href="<?= ci_url(['p' => $i]) ?>"><?= $i ?></a><?php endif; ?>
                    <?php endfor; ?>
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
