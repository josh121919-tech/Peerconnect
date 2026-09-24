<?php

/**
 * admin/certificates.php — Content › Certificates.
 *
 * A template is a design plus the wording that goes on it; issuing one records
 * who received it and for what. The card previews are the real thing rendered
 * small — the same markup the recipient opens and prints, at a third of the
 * size — so what you pick is what they get.
 *
 * Before this rewrite a certificate was a database row and nothing else:
 * `generated_path` was written as an empty string, so the "view" link on a
 * mentor's profile never appeared and no document ever existed. Certificates
 * are now rendered on demand from the template and the recipient, which is why
 * there is no file to go missing.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/award_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$current_page = 'admin-certificates';
$csrf = csrf_token();

/* ── Filters ──────────────────────────────────────────────────────────── */
$cats    = aw_cert_categories();
$tab     = in_array($_GET['tab'] ?? '', $cats, true) ? $_GET['tab'] : 'all';
$q       = trim((string)($_GET['q'] ?? ''));
$status  = in_array($_GET['st'] ?? '', ['active', 'archived'], true) ? $_GET['st'] : '';
$perPage = aw_per_page((int)($_GET['per'] ?? 6), [6, 12, 24]);
$page    = max(1, (int)($_GET['p'] ?? 1));

$where = [];
$types = '';
$args  = [];
if ($tab !== 'all')       { $where[] = 't.category = ?'; $types .= 's'; $args[] = $tab; }
if ($q !== '')            { $where[] = '(t.name LIKE ? OR t.description LIKE ?)'; $types .= 'ss'; $args[] = "%$q%"; $args[] = "%$q%"; }
if ($status === 'active')   $where[] = 't.is_active = 1';
if ($status === 'archived') $where[] = 't.is_active = 0';
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*) FROM certificate_templates t $whereSql";
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

$listSql = "
    SELECT t.*,
           COUNT(uc.cert_id)              AS issued,
           COUNT(DISTINCT uc.user_id)     AS recipients
      FROM certificate_templates t
      LEFT JOIN user_certificates uc ON uc.template_id = t.template_id
    $whereSql
     GROUP BY t.template_id
     ORDER BY t.is_active DESC, issued DESC, t.template_id DESC
     LIMIT $perPage OFFSET $offset";

if ($types !== '') {
    $ls = $con->prepare($listSql);
    $ls->bind_param($types, ...$args);
    $ls->execute();
    $templates = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
    $ls->close();
} else {
    $templates = $con->query($listSql)->fetch_all(MYSQLI_ASSOC);
}

/* ── Figures ──────────────────────────────────────────────────────────── */
$one = function (string $sql) use ($con) {
    $r = $con->query($sql);
    return $r ? (int)$r->fetch_row()[0] : 0;
};

$tplTotal   = $one("SELECT COUNT(*) FROM certificate_templates");
$tplActive  = $one("SELECT COUNT(*) FROM certificate_templates WHERE is_active = 1");
$issued     = $one("SELECT COUNT(*) FROM user_certificates");
$recipients = $one("SELECT COUNT(DISTINCT user_id) FROM user_certificates");
$last30     = $one("SELECT COUNT(*) FROM user_certificates WHERE awarded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");

/* Issued certificates by the category of the template they came from. */
$usage = [];
$uq = $con->query("
    SELECT t.category AS k, COUNT(uc.cert_id) n
      FROM user_certificates uc
      JOIN certificate_templates t ON t.template_id = uc.template_id
     GROUP BY t.category ORDER BY n DESC");
while ($row = $uq->fetch_assoc()) $usage[$row['k']] = (int)$row['n'];

$catColors = [
    'Achievement'   => '#B7791F',
    'Participation' => '#1B6FD1',
    'Appreciation'  => '#17654B',
    'Completion'    => '#5A3E96',
    'Milestone'     => '#0E6C77',
    'Leadership'    => '#9B2C43',
];

/* ── Recently issued ──────────────────────────────────────────────────── */
$recent = $con->query("
    SELECT uc.cert_id, uc.achievement, uc.awarded_at,
           t.name AS template_name, t.category, t.design,
           u.user_id, CONCAT_WS(' ', u.firstname, u.lastname) AS person,
           pr.profile_image
      FROM user_certificates uc
      JOIN certificate_templates t ON t.template_id = uc.template_id
      JOIN users u ON u.user_id = uc.user_id
      LEFT JOIN profile pr ON pr.user_id = u.user_id
     ORDER BY uc.awarded_at DESC, uc.cert_id DESC
     LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

/* Certificates recognise contribution, so both roles can receive one. */
$people = $con->query("
    SELECT user_id, CONCAT_WS(' ', firstname, lastname) AS name, role
      FROM users WHERE role IN ('mentor','mentee') AND status = 'active'
     ORDER BY role, firstname, lastname
")->fetch_all(MYSQLI_ASSOC);

$activeTemplates = $con->query("
    SELECT template_id, name, category, design FROM certificate_templates
     WHERE is_active = 1 ORDER BY name
")->fetch_all(MYSQLI_ASSOC);

$platformName = 'PeerConnect';
$adminName    = trim((string)($_SESSION['firstname'] ?? '')) !== ''
    ? trim($_SESSION['firstname'] . ' ' . ($_SESSION['lastname'] ?? ''))
    : 'PeerConnect Admin';

function ct_url(array $over = []): string
{
    $qs = array_merge($_GET, $over);
    $qs = array_filter($qs, fn($v) => $v !== '' && $v !== null);
    return url('admin-certificates') . ($qs ? '?' . http_build_query($qs) : '');
}

include 'layout.php';
require_once __DIR__ . '/includes/sessions_ui.php';
?>

<style>
    .aw-crumb { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--gray-400); margin-bottom: 6px; }
    .aw-crumb b { color: var(--forest); font-weight: 600; }

    .aw-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 14px; align-items: start; }
    .aw-stack { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
    .aw-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }

    .aw-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--gray-200); margin-bottom: 14px; flex-wrap: wrap; }
    .aw-tab { padding: 10px 14px; font-size: 13px; font-weight: 600; color: var(--gray-500);
              text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; }
    .aw-tab:hover { color: var(--forest); }
    .aw-tab.on { color: var(--mint); border-bottom-color: var(--mint); }
    .aw-tab span { color: var(--gray-400); font-weight: 500; }

    .aw-bar { display: flex; gap: 9px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
    .aw-search { position: relative; flex: 1; min-width: 200px; }
    .aw-search svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--gray-400); }
    .aw-search input { width: 100%; padding: 10px 12px 10px 34px; border: 1px solid var(--gray-200); border-radius: 10px;
                       font-family: inherit; font-size: 13px; color: var(--gray-800); outline: none; background: #fff; }
    .aw-search input:focus { border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0,135,207,.13); }
    .aw-bar select { padding: 10px 12px; border: 1px solid var(--gray-200); border-radius: 10px; background: #fff;
                     font-family: inherit; font-size: 13px; color: var(--gray-700); outline: none; }
    .aw-bar button.go { padding: 10px 16px; border: 1px solid var(--primary); border-radius: 10px; background: var(--primary);
                        color: #fff; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
    .aw-reset { display: inline-flex; align-items: center; padding: 10px 15px; border: 1px solid var(--gray-200);
                border-radius: 10px; background: #fff; font-size: 13px; font-weight: 600; color: var(--gray-600); text-decoration: none; }
    .aw-reset:hover { border-color: var(--mint); color: var(--mint); }

    /* Template cards */
    .ct-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
    .ct-card { background: #fff; border: 1px solid var(--gray-100); border-radius: 14px; overflow: hidden;
               box-shadow: 0 1px 2px rgba(16,24,40,.04); display: flex; flex-direction: column; }
    .ct-card.off { opacity: .74; }
    .ct-shot { position: relative; background: var(--gray-50, #F5F6F8); border-bottom: 1px solid var(--gray-100);
               height: 178px; display: grid; place-items: center; overflow: hidden; }
    .ct-shot .cert-paper { box-shadow: 0 4px 14px -6px rgba(16,24,40,.4); }
    .ct-body { padding: 14px 16px 12px; display: flex; flex-direction: column; flex: 1; }
    .ct-body h3 { margin: 0 0 4px; font-size: 14px; font-weight: 700; color: var(--forest); }
    .ct-body p { margin: 0 0 10px; font-size: 12px; color: var(--gray-500); line-height: 1.55; flex: 1; }
    .ct-chip { display: inline-block; padding: 3px 11px; border-radius: 999px; font-size: 11px; font-weight: 700; margin-bottom: 10px; }
    .ct-meta { display: flex; align-items: center; gap: 14px; font-size: 12px; color: var(--gray-500); }
    .ct-meta span { display: inline-flex; align-items: center; gap: 6px; }
    .ct-meta svg { width: 14px; height: 14px; color: var(--gray-400); }
    .ct-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px;
               border-top: 1px solid var(--gray-100); padding: 11px 16px; }
    .ct-issue { padding: 7px 14px; border: 1px solid var(--primary); border-radius: 9px; background: var(--primary);
                color: #fff; font-family: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; }
    .ct-issue:hover { background: var(--primary-2); }
    .ct-off-tag { position: absolute; top: 10px; right: 10px; padding: 3px 10px; border-radius: 999px;
                  background: rgba(9,14,38,.78); color: #fff; font-size: 10.5px; font-weight: 700; }

    .bd-menu { position: relative; }
    .bd-menu > button { width: 30px; height: 30px; border: 1px solid var(--gray-200); border-radius: 9px; background: #fff;
                        color: var(--gray-500); cursor: pointer; display: grid; place-items: center; padding: 0; }
    .bd-menu > button:hover { border-color: var(--mint); color: var(--mint); }
    .bd-menu > button svg { width: 15px; height: 15px; }
    .bd-pop { position: absolute; right: 0; bottom: calc(100% + 6px); min-width: 176px; background: #fff;
              border: 1px solid var(--gray-200); border-radius: 11px; box-shadow: 0 12px 28px -12px rgba(16,24,40,.34);
              padding: 5px; z-index: 40; text-align: left; }
    .bd-pop button, .bd-pop a { display: flex; align-items: center; gap: 9px; width: 100%; padding: 8px 10px; border: 0;
                                border-radius: 8px; background: none; font-family: inherit; font-size: 12.5px; font-weight: 600;
                                color: var(--gray-700); cursor: pointer; text-align: left; text-decoration: none; }
    .bd-pop button:hover, .bd-pop a:hover { background: #F7F8FA; color: var(--mint); }
    .bd-pop button.bad:hover { background: #FDECEA; color: #A6301F; }
    .bd-pop svg { width: 14px; height: 14px; flex: none; }
    .bd-pop hr { border: 0; border-top: 1px solid var(--gray-100); margin: 4px 2px; }

    .aw-pager { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 16px; }

    /* Side */
    .aw-donut { display: flex; align-items: center; gap: 16px; }
    .aw-keys { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 7px; }
    .aw-key { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--gray-600); }
    .aw-key i { width: 9px; height: 9px; border-radius: 3px; flex: none; }
    .aw-key b { margin-left: auto; font-weight: 600; color: var(--gray-800); font-variant-numeric: tabular-nums; }
    .aw-mid { position: absolute; inset: 0; display: grid; place-items: center; text-align: center; }
    .aw-mid b { display: block; font-size: 19px; font-weight: 700; color: var(--forest); line-height: 1.1; }
    .aw-mid span { font-size: 9.5px; color: var(--gray-400); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }

    .aw-feed { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-top: 1px solid var(--gray-100); }
    .aw-feed:first-of-type { border-top: 0; padding-top: 0; }
    .aw-av { width: 32px; height: 32px; flex: none; border-radius: 50%; overflow: hidden; background: var(--forest);
             color: #fff; display: grid; place-items: center; font-size: 12px; font-weight: 700; }
    .aw-av img { width: 100%; height: 100%; object-fit: cover; }
    .aw-feed-t { flex: 1; min-width: 0; }
    .aw-feed-t b { display: block; font-size: 12.5px; font-weight: 600; color: var(--gray-800); }
    .aw-feed-t span { display: block; font-size: 11.5px; color: var(--gray-400); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .aw-feed a { text-decoration: none; color: inherit; }
    .aw-feed a:hover b { color: var(--mint); }
    .aw-feed-w { flex: none; font-size: 11px; color: var(--gray-400); white-space: nowrap; }

    .aw-acts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; }
    .aw-acts button, .aw-acts a { display: inline-flex; align-items: center; gap: 8px; padding: 11px 12px; border: 1px solid var(--gray-200);
                                  border-radius: 10px; background: #fff; font-family: inherit; font-size: 12.5px; font-weight: 600;
                                  color: var(--gray-700); cursor: pointer; text-decoration: none; }
    .aw-acts button:hover, .aw-acts a:hover { border-color: var(--mint); color: var(--mint); }
    .aw-acts svg { width: 15px; height: 15px; flex: none; }

    .aw-note { padding: 12px 14px; border-radius: 11px; background: #EAF1FB; color: #1A5C9A; font-size: 12.5px; line-height: 1.6; }
    .aw-note b { display: block; margin-bottom: 2px; }
    .aw-empty { padding: 40px 20px; text-align: center; color: var(--gray-400); font-size: 13px; line-height: 1.7; }

    /* Dialogs */
    .aw-ov { position: fixed; inset: 0; background: rgba(9,14,38,.5); display: none; place-items: center; z-index: 90; padding: 20px; }
    .aw-ov.open { display: grid; }
    .aw-modal { background: #fff; border-radius: 16px; width: min(560px, 100%); max-height: 88vh; overflow-y: auto;
                box-shadow: 0 24px 60px -20px rgba(16,24,40,.5); }
    .aw-modal.wide { width: min(820px, 100%); }
    .aw-modal-hd { padding: 18px 22px 14px; border-bottom: 1px solid var(--gray-100); }
    .aw-modal-hd h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--forest); }
    .aw-modal-hd p { margin: 3px 0 0; font-size: 12.5px; color: var(--gray-400); }
    .aw-modal-bd { padding: 18px 22px; }
    .aw-modal-ft { padding: 14px 22px 18px; display: flex; justify-content: flex-end; gap: 9px; }
    .aw-f { margin-bottom: 14px; }
    .aw-f label { display: block; font-size: 12px; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; }
    .aw-f input[type=text], .aw-f select, .aw-f textarea {
        width: 100%; padding: 10px 12px; border: 1px solid var(--gray-200); border-radius: 10px;
        font-family: inherit; font-size: 13.5px; color: var(--gray-800); background: #fff; outline: none; resize: vertical; box-sizing: border-box;
    }
    .aw-f input:focus, .aw-f select:focus, .aw-f textarea:focus { border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0,135,207,.13); }
    .aw-f small { display: block; margin-top: 4px; font-size: 11.5px; color: var(--gray-400); line-height: 1.5; }
    .aw-f-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }

    .ct-designs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 9px; }
    .ct-designs input { position: absolute; opacity: 0; pointer-events: none; }
    .ct-designs label { display: block; border: 1px solid var(--gray-200); border-radius: 10px; padding: 7px;
                        cursor: pointer; margin: 0; text-align: center; overflow: hidden; }
    .ct-designs input:checked + label { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary); }
    .ct-designs label i { display: block; height: 34px; border-radius: 6px; margin-bottom: 6px; }
    .ct-designs label span { font-size: 11px; font-weight: 600; color: var(--gray-600); }

    .ct-preview { background: var(--gray-50, #F5F6F8); border: 1px solid var(--gray-100); border-radius: 12px;
                  padding: 16px; display: grid; place-items: center; overflow-x: auto; }

    .aw-btn { padding: 10px 18px; border-radius: 10px; border: 1px solid var(--gray-200); background: #fff;
              font-family: inherit; font-size: 13.5px; font-weight: 600; color: var(--gray-700); cursor: pointer; }
    .aw-btn.primary { background: var(--primary); border-color: var(--primary); color: #fff; }
    .aw-btn.danger { background: #A6301F; border-color: #A6301F; color: #fff; }

    @media (max-width: 1240px) { .aw-grid { grid-template-columns: minmax(0, 1fr); } }
    @media (max-width: 1180px) { .aw-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 620px)  { .aw-stats { grid-template-columns: minmax(0, 1fr); } .aw-f-row { grid-template-columns: minmax(0, 1fr); } }
</style>

<div class="aw-crumb">Content<span>›</span><b>Certificates</b></div>

<div class="ss-hd">
    <div>
        <h1>Certificates</h1>
        <p>Designs you can issue to recognise a mentor or mentee. Each one renders as a real page they can open and print.</p>
    </div>
    <div class="ss-hd-actions">
        <button type="button" class="ss-export" style="border:0;cursor:pointer;font-family:inherit;" onclick="ctIssue(0)">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
            Issue certificate
        </button>
    </div>
</div>

<!-- ══════════ Figures ══════════ -->
<div class="aw-stats">
    <?php foreach ([
        ['Designs', number_format($tplTotal), $tplActive . ' available · ' . ($tplTotal - $tplActive) . ' archived', '#FEF3D6', '#8A6400', 'file'],
        ['Issued', number_format($issued), $last30 . ' in the last 30 days', '#E4EEFB', '#1A5C9A', 'check'],
        ['People holding one', number_format($recipients), 'Mentors and mentees', '#E1F3EA', '#17654B', 'one'],
        ['Most used', $usage ? array_key_first($usage) : '—', $usage ? reset($usage) . ' issued in that category' : 'Nothing issued yet', '#EEE9F8', '#5A3E96', 'star'],
    ] as [$k, $v, $s, $bg, $fg, $ico]): ?>
        <div class="ss-stat">
            <span class="ss-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;">
                <?php if ($ico === 'file'): ?>
                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3.5H7.5A1.5 1.5 0 0 0 6 5v14a1.5 1.5 0 0 0 1.5 1.5h9A1.5 1.5 0 0 0 18 19V7.5L14 3.5Zm0 0V8h4" /></svg>
                <?php else: ?>
                    <?= ss_icon($ico) ?>
                <?php endif; ?>
            </span>
            <div style="min-width:0;">
                <div class="ss-stat-k"><?= $k ?></div>
                <div class="ss-stat-v" style="<?= is_string($v) && !ctype_digit(str_replace(',', '', $v)) ? 'font-size:19px;' : '' ?>"><?= htmlspecialchars((string)$v) ?></div>
                <div class="ss-stat-s"><?= htmlspecialchars((string)$s) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="aw-grid">
    <div class="aw-stack">
        <div class="ss-card">
            <div class="aw-tabs">
                <a class="aw-tab <?= $tab === 'all' ? 'on' : '' ?>" href="<?= ct_url(['tab' => null, 'p' => null]) ?>">All designs <span><?= $tplTotal ?></span></a>
                <?php
                $catCounts = [];
                $cc = $con->query("SELECT category, COUNT(*) n FROM certificate_templates GROUP BY category");
                while ($row = $cc->fetch_assoc()) $catCounts[$row['category']] = (int)$row['n'];
                foreach ($cats as $c):
                    if (($catCounts[$c] ?? 0) === 0 && $tab !== $c) continue; ?>
                    <a class="aw-tab <?= $tab === $c ? 'on' : '' ?>" href="<?= ct_url(['tab' => $c, 'p' => null]) ?>">
                        <?= $c ?> <span><?= $catCounts[$c] ?? 0 ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <form class="aw-bar" method="get" action="<?= url('admin-certificates') ?>">
                <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>"><?php endif; ?>
                <input type="hidden" name="per" value="<?= $perPage ?>">
                <span class="aw-search">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m16.5 16.5 4 4" /></svg>
                    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search designs…">
                </span>
                <select name="st">
                    <option value="">Any status</option>
                    <option value="active"   <?= $status === 'active' ? 'selected' : '' ?>>Available</option>
                    <option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
                <button type="submit" class="go">Apply</button>
                <?php if ($q !== '' || $status !== '' || $tab !== 'all'): ?>
                    <a class="aw-reset" href="<?= url('admin-certificates') ?>">Reset</a>
                <?php endif; ?>
            </form>

            <?php if (!$templates): ?>
                <p class="aw-empty">
                    <?php if ($tplTotal === 0): ?>
                        No certificate designs yet.<br>
                        <button type="button" class="aw-btn primary" style="margin-top:14px;" onclick="ctNew()">Create the first one</button>
                    <?php else: ?>
                        No design matches these filters.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <div class="ct-grid">
                    <?php foreach ($templates as $t):
                        $id  = (int)$t['template_id'];
                        $col = $catColors[$t['category']] ?? '#414A5C';
                    ?>
                        <div class="ct-card <?= (int)$t['is_active'] ? '' : 'off' ?>">
                            <div class="ct-shot">
                                <?php // The real certificate, drawn at a third size. Not a stock image. ?>
                                <?= aw_cert_html([
                                    'title'       => $t['name'],
                                    'achievement' => (string)$t['description'],
                                    'date'        => date('F j, Y'),
                                    'issuer'      => $adminName,
                                    'platform'    => $platformName,
                                ], $t['design'], 0.325) ?>
                                <?php if (!(int)$t['is_active']): ?><span class="ct-off-tag">Archived</span><?php endif; ?>
                            </div>
                            <div class="ct-body">
                                <h3><?= htmlspecialchars($t['name']) ?></h3>
                                <div><span class="ct-chip" style="background:<?= $col ?>1A;color:<?= $col ?>;"><?= htmlspecialchars($t['category']) ?></span></div>
                                <p><?= htmlspecialchars((string)$t['description']) ?></p>
                                <div class="ct-meta">
                                    <span>
                                        <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.5l2.2 2.2L15.5 10M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                        Issued <?= (int)$t['issued'] ?>
                                    </span>
                                    <span>
                                        <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="9" cy="8.5" r="3" /><path stroke-linecap="round" d="M3.5 20a5.5 5.5 0 0 1 11 0M16 5.6a3 3 0 0 1 0 5.8M17.5 14.4A5.5 5.5 0 0 1 20.5 20" /></svg>
                                        <?= (int)$t['recipients'] ?> recipient<?= (int)$t['recipients'] === 1 ? '' : 's' ?>
                                    </span>
                                </div>
                            </div>
                            <div class="ct-foot">
                                <?php if ((int)$t['is_active']): ?>
                                    <button type="button" class="ct-issue" onclick="ctIssue(<?= $id ?>)">Issue</button>
                                <?php else: ?>
                                    <span style="font-size:12px;color:var(--gray-400);">Archived — cannot be issued</span>
                                <?php endif; ?>
                                <span class="bd-menu">
                                    <button type="button" onclick="ctMenu(this)" aria-label="More actions for <?= htmlspecialchars($t['name']) ?>">
                                        <svg fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.7" /><circle cx="12" cy="12" r="1.7" /><circle cx="12" cy="19" r="1.7" /></svg>
                                    </button>
                                    <span class="bd-pop" hidden>
                                        <button type="button" onclick='ctEdit(<?= json_encode([
                                            "id" => $id, "name" => $t["name"], "description" => (string)$t["description"],
                                            "category" => $t["category"], "design" => $t["design"],
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20h4L19 9l-4-4L4 16v4Z" /></svg>
                                            Edit design
                                        </button>
                                        <a href="<?= url('admin-certificates-issued') ?>?tpl=<?= $id ?>">
                                            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7.5V12l2.8 1.8" /></svg>
                                            Who has received it
                                        </a>
                                        <form method="post" action="<?= url('admin-action-certificate') ?>" style="margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="do" value="archive">
                                            <input type="hidden" name="template_id" value="<?= $id ?>">
                                            <input type="hidden" name="back" value="<?= htmlspecialchars(ct_url()) ?>">
                                            <button type="submit">
                                                <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><rect x="3.5" y="4.5" width="17" height="4" rx="1.5" /><path stroke-linecap="round" d="M5 8.5V19a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8.5M10 12h4" /></svg>
                                                <?= (int)$t['is_active'] ? 'Archive design' : 'Restore design' ?>
                                            </button>
                                        </form>
                                        <hr>
                                        <?php /* Single-quoted, with JSON_HEX_QUOT — json_encode() wraps a string
                                                 in double quotes of its own, which ends a double-quoted attribute
                                                 at the first one and leaves the handler unparseable. */ ?>
                                        <button type="button" class="bad" onclick='ctDelete(<?= $id ?>, <?= json_encode($t['name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= (int)$t['issued'] ?>)'>
                                            <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 7h14M10 7V5h4v2m-7 0 1 13h8l1-13" /></svg>
                                            Delete design
                                        </button>
                                    </span>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="aw-pager">
                    <span style="font-size:12.5px;color:var(--gray-400);">
                        Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $total) ?> of <?= $total ?> design<?= $total === 1 ? '' : 's' ?>
                    </span>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <?php pc_pagination($page, $pages, fn(int $n) => ct_url(['p' => $n]), ['label' => 'Certificate design pages']); ?>
                        <form method="get" action="<?= url('admin-certificates') ?>" style="margin:0;">
                            <?php foreach (['tab' => $tab === 'all' ? '' : $tab, 'q' => $q, 'st' => $status] as $k => $v): ?>
                                <?php if ($v !== '') : ?><input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>"><?php endif; ?>
                            <?php endforeach; ?>
                            <select name="per" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--gray-200);border-radius:9px;font-family:inherit;font-size:12.5px;color:var(--gray-600);background:#fff;">
                                <?php foreach ([6, 12, 24] as $n): ?>
                                    <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?> per page</option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════ Side ══════════ -->
    <div class="aw-stack">
        <div class="ss-card">
            <h2>What has been issued</h2>
            <p style="margin:-9px 0 14px;font-size:12px;color:var(--gray-400);line-height:1.5;">Certificates given out, by the category of the design they came from.</p>
            <?php if (!$usage): ?>
                <p class="aw-empty" style="padding:18px 0;">Nothing has been issued yet.</p>
            <?php else:
                $uTotal = array_sum($usage); $R = 34; $SW = 15; $C = 2 * M_PI * $R; $off = 0; ?>
                <div class="aw-donut">
                    <div style="position:relative;flex:none;width:92px;height:92px;">
                        <svg width="92" height="92" viewBox="0 0 92 92" role="img" aria-label="Certificates issued, by category">
                            <circle cx="46" cy="46" r="<?= $R ?>" fill="none" stroke="#EDEDED" stroke-width="<?= $SW ?>" />
                            <?php foreach ($usage as $k => $n):
                                $len = $C * $n / max(1, $uTotal); ?>
                                <circle cx="46" cy="46" r="<?= $R ?>" fill="none" stroke="<?= $catColors[$k] ?? '#9CA3AF' ?>" stroke-width="<?= $SW ?>"
                                        stroke-dasharray="<?= round($len, 2) ?> <?= round($C - $len, 2) ?>"
                                        stroke-dashoffset="<?= round(-$off, 2) ?>" transform="rotate(-90 46 46)" />
                                <?php $off += $len; ?>
                            <?php endforeach; ?>
                        </svg>
                        <div class="aw-mid"><div><b><?= $uTotal ?></b><span>issued</span></div></div>
                    </div>
                    <div class="aw-keys">
                        <?php foreach ($usage as $k => $n): ?>
                            <span class="aw-key"><i style="background:<?= $catColors[$k] ?? '#9CA3AF' ?>"></i><?= htmlspecialchars($k) ?><b><?= $n ?></b></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="ss-card">
            <h2>Recently issued<?php if ($issued > 5): ?><a href="<?= url('admin-certificates-issued') ?>">View all</a><?php endif; ?></h2>
            <?php if (!$recent): ?>
                <p class="aw-empty" style="padding:18px 0;">Nothing has been issued yet.</p>
            <?php else: foreach ($recent as $r):
                $col = $catColors[$r['category']] ?? '#414A5C'; ?>
                <div class="aw-feed">
                    <span class="aw-av">
                        <?php if (!empty($r['profile_image'])): ?><img src="<?= htmlspecialchars($r['profile_image']) ?>" alt="">
                        <?php else: ?><?= htmlspecialchars(strtoupper(substr(trim($r['person']), 0, 1))) ?><?php endif; ?>
                    </span>
                    <a class="aw-feed-t" href="<?= url('certificate-view') ?>?id=<?= (int)$r['cert_id'] ?>" target="_blank" rel="noopener">
                        <b><?= htmlspecialchars($r['person']) ?></b>
                        <span style="color:<?= $col ?>;"><?= htmlspecialchars($r['template_name']) ?></span>
                    </a>
                    <span class="aw-feed-w"><?= aw_ago($r['awarded_at']) ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="ss-card">
            <h2>Quick actions</h2>
            <div class="aw-acts">
                <button type="button" onclick="ctNew()">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                    New design
                </button>
                <button type="button" onclick="ctIssue(0)">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.5l2.2 2.2L15.5 10M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    Issue one
                </button>
                <a href="<?= url('admin-certificates-issued') ?>">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M12 7.5V12l2.8 1.8" /></svg>
                    Issuance history
                </a>
                <a href="<?= url('admin-badges') ?>">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="12" cy="9" r="5.5" /><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 13.5-1 7 4.5-2.4 4.5 2.4-1-7" /></svg>
                    Badges
                </a>
            </div>
        </div>

        <div class="ss-card">
            <h2>How these are produced</h2>
            <div class="aw-note">
                <b>Rendered, not stored as files.</b>
                A certificate is drawn from its design plus the recipient's name and the wording you enter, at the
                moment someone opens it. There is no image to go missing and no PDF library to install — the
                recipient prints it from their browser, which is also how they save it as a PDF.
            </div>
        </div>
    </div>
</div>

<!-- ══════════ Create / edit design ══════════ -->
<div class="aw-ov" id="ctForm" role="dialog" aria-modal="true" aria-labelledby="ctFormTitle">
    <div class="aw-modal">
        <form method="post" action="<?= url('admin-action-certificate') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="do" id="ctDo" value="create">
            <input type="hidden" name="template_id" id="ctId" value="0">
            <input type="hidden" name="back" value="<?= htmlspecialchars(ct_url()) ?>">

            <div class="aw-modal-hd">
                <h3 id="ctFormTitle">New certificate design</h3>
                <p id="ctFormSub">The title and wording appear on the certificate itself.</p>
            </div>
            <div class="aw-modal-bd">
                <div class="aw-f">
                    <label for="ctName">Title on the certificate</label>
                    <input type="text" id="ctName" name="name" maxlength="120" required placeholder="e.g. Certificate of Appreciation">
                </div>
                <div class="aw-f">
                    <label for="ctDesc">Wording</label>
                    <textarea id="ctDesc" name="description" rows="2" maxlength="400"
                              placeholder="e.g. In recognition of valuable time, guidance and commitment to mentoring."></textarea>
                    <small>Printed under the recipient's name. When you issue one you can replace this for that person.</small>
                </div>
                <div class="aw-f">
                    <label for="ctCat">Category</label>
                    <select id="ctCat" name="category">
                        <?php foreach ($cats as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="aw-f" style="margin-bottom:0;">
                    <label>Design</label>
                    <div class="ct-designs">
                        <?php foreach (aw_cert_designs() as $k => [$label, $paper, $ink, $accent, $deep]): ?>
                            <input type="radio" name="design" id="dz_<?= $k ?>" value="<?= $k ?>" <?= $k === 'classic' ? 'checked' : '' ?>>
                            <label for="dz_<?= $k ?>">
                                <i style="background:<?= $paper ?>;border:2px solid <?= $accent ?>;"></i>
                                <span><?= $label ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="aw-modal-ft">
                <button type="button" class="aw-btn" onclick="awClose('ctForm')">Cancel</button>
                <button type="submit" class="aw-btn primary" id="ctSubmit">Create design</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════ Issue ══════════ -->
<div class="aw-ov" id="ctIssueM" role="dialog" aria-modal="true" aria-labelledby="ctIssueTitle">
    <div class="aw-modal wide">
        <form method="post" action="<?= url('admin-action-certificate') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="do" value="issue">
            <input type="hidden" name="back" value="<?= htmlspecialchars(ct_url()) ?>">

            <div class="aw-modal-hd">
                <h3 id="ctIssueTitle">Issue a certificate</h3>
                <p>The recipient is notified, and by email if notifications are on.</p>
            </div>
            <div class="aw-modal-bd">
                <?php if (!$activeTemplates): ?>
                    <p style="margin:0;font-size:13px;color:var(--gray-500);line-height:1.6;">
                        There are no available designs yet. Create one first — an archived design cannot be issued.
                    </p>
                <?php elseif (!$people): ?>
                    <p style="margin:0;font-size:13px;color:var(--gray-500);">There are no active members to issue a certificate to.</p>
                <?php else: ?>
                    <div class="aw-f-row">
                        <div class="aw-f">
                            <label for="isWho">Recipient</label>
                            <select id="isWho" name="user_id" required onchange="ctPreview()">
                                <option value="">Choose a person…</option>
                                <?php
                                $lastRole = '';
                                foreach ($people as $p):
                                    if ($p['role'] !== $lastRole) {
                                        if ($lastRole !== '') echo '</optgroup>';
                                        echo '<optgroup label="' . htmlspecialchars(ucfirst($p['role']) . 's') . '">';
                                        $lastRole = $p['role'];
                                    } ?>
                                    <option value="<?= (int)$p['user_id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach;
                                if ($lastRole !== '') echo '</optgroup>'; ?>
                            </select>
                        </div>
                        <div class="aw-f">
                            <label for="isTpl">Design</label>
                            <select id="isTpl" name="template_id" required onchange="ctPreview()">
                                <option value="">Choose a design…</option>
                                <?php foreach ($activeTemplates as $t): ?>
                                    <option value="<?= (int)$t['template_id'] ?>"
                                            data-design="<?= htmlspecialchars($t['design']) ?>"
                                            data-name="<?= htmlspecialchars($t['name']) ?>">
                                        <?= htmlspecialchars($t['name']) ?> · <?= htmlspecialchars($t['category']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="aw-f">
                        <label for="isAch">What it is for</label>
                        <input type="text" id="isAch" name="achievement" maxlength="255" required
                               oninput="ctPreview()" placeholder="e.g. Completing 25 mentoring sessions">
                        <small>Printed on the certificate under the recipient's name, in place of the design's default wording.</small>
                    </div>

                    <div class="ct-preview" id="ctPrevBox">
                        <div id="ctPrevInner"></div>
                    </div>
                    <p style="margin:9px 0 0;font-size:11.5px;color:var(--gray-400);line-height:1.5;">
                        This is the certificate itself, shown small. The reference number is assigned when you issue it.
                    </p>
                <?php endif; ?>
            </div>
            <div class="aw-modal-ft">
                <button type="button" class="aw-btn" onclick="awClose('ctIssueM')">Cancel</button>
                <?php if ($activeTemplates && $people): ?>
                    <button type="submit" class="aw-btn primary">Issue certificate</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ══════════ Delete ══════════ -->
<div class="aw-ov" id="ctDel" role="dialog" aria-modal="true" aria-labelledby="ctDelTitle">
    <div class="aw-modal" style="width:min(440px,100%);">
        <form method="post" action="<?= url('admin-action-certificate') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="template_id" id="ctDelId" value="0">
            <input type="hidden" name="back" value="<?= htmlspecialchars(ct_url()) ?>">
            <div class="aw-modal-hd"><h3 id="ctDelTitle">Delete this design?</h3></div>
            <div class="aw-modal-bd">
                <p style="margin:0 0 12px;font-size:13.5px;color:var(--gray-700);line-height:1.6;" id="ctDelText"></p>
                <div class="aw-note" style="background:#FEF6DC;color:#7A5A00;">
                    <b>Archiving is usually what you want.</b>
                    An archived design cannot be issued any more, but every certificate already given out still
                    opens and prints. Deleting removes the design and every certificate issued from it.
                </div>
            </div>
            <div class="aw-modal-ft">
                <button type="button" class="aw-btn" onclick="awClose('ctDel')">Cancel</button>
                <button type="submit" class="aw-btn danger">Delete permanently</button>
            </div>
        </form>
    </div>
</div>

<script>
    function awOpen(id) {
        document.getElementById(id).classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function awClose(id) {
        document.getElementById(id).classList.remove('open');
        document.body.style.overflow = '';
    }
    document.querySelectorAll('.aw-ov').forEach(function (ov) {
        ov.addEventListener('click', function (e) { if (e.target === ov) awClose(ov.id); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.aw-ov.open').forEach(function (ov) { awClose(ov.id); });
        ctCloseMenus();
    });

    function ctCloseMenus(except) {
        document.querySelectorAll('.bd-pop').forEach(function (p) { if (p !== except) p.hidden = true; });
    }

    function ctMenu(btn) {
        var pop = btn.parentNode.querySelector('.bd-pop');
        var wasOpen = !pop.hidden;
        ctCloseMenus(pop);
        pop.hidden = wasOpen;
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.bd-menu')) ctCloseMenus();
    });

    function ctNew() {
        ctCloseMenus();
        var f = document.getElementById('ctForm');
        f.querySelector('form').reset();
        document.getElementById('ctDo').value = 'create';
        document.getElementById('ctId').value = '0';
        document.getElementById('ctFormTitle').textContent = 'New certificate design';
        document.getElementById('ctFormSub').textContent = 'The title and wording appear on the certificate itself.';
        document.getElementById('ctSubmit').textContent = 'Create design';
        awOpen('ctForm');
        document.getElementById('ctName').focus();
    }

    function ctEdit(t) {
        ctCloseMenus();
        document.getElementById('ctDo').value = 'update';
        document.getElementById('ctId').value = t.id;
        document.getElementById('ctName').value = t.name;
        document.getElementById('ctDesc').value = t.description;
        document.getElementById('ctCat').value = t.category;
        var d = document.getElementById('dz_' + t.design);
        if (d) d.checked = true;
        document.getElementById('ctFormTitle').textContent = 'Edit certificate design';
        document.getElementById('ctFormSub').textContent = 'Changes apply to certificates already issued from this design too, because they are rendered when opened.';
        document.getElementById('ctSubmit').textContent = 'Save changes';
        awOpen('ctForm');
    }

    function ctDelete(id, name, issued) {
        ctCloseMenus();
        document.getElementById('ctDelId').value = id;
        document.getElementById('ctDelText').textContent =
            issued > 0
                ? '"' + name + '" has been issued ' + issued + ' time' + (issued === 1 ? '' : 's') + '. Deleting it takes those certificates away from their holders.'
                : '"' + name + '" has never been issued.';
        awOpen('ctDel');
    }

    /* ── Issue dialog, with a live preview of the real certificate ── */
    var CT_DESIGNS = <?= json_encode(aw_cert_designs()) ?>;
    var CT_ISSUER  = <?= json_encode($adminName) ?>;
    var CT_PLATFORM = <?= json_encode($platformName) ?>;

    function ctIssue(templateId) {
        ctCloseMenus();
        var sel = document.getElementById('isTpl');
        if (sel) sel.value = templateId ? String(templateId) : '';
        awOpen('ctIssueM');
        ctPreview();
    }

    function ctEsc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Mirrors aw_cert_html() in award_data.php. Kept deliberately simple: it
    // draws the same frame, the same order of lines and the same colours, so
    // what the dialog shows is what the server will render.
    function ctPreview() {
        var box = document.getElementById('ctPrevInner');
        if (!box) return;

        var tplSel = document.getElementById('isTpl');
        var whoSel = document.getElementById('isWho');
        var opt = tplSel && tplSel.selectedIndex > 0 ? tplSel.options[tplSel.selectedIndex] : null;

        if (!opt) {
            box.innerHTML = '<p style="margin:0;font-size:12.5px;color:#9A9EA6;padding:36px 0;text-align:center;">Choose a design to see the certificate.</p>';
            return;
        }

        var key = opt.getAttribute('data-design');
        var d = CT_DESIGNS[key] || CT_DESIGNS.classic;
        var paper = d[1], ink = d[2], accent = d[3], deep = d[4];

        var who = whoSel && whoSel.selectedIndex > 0 ? whoSel.options[whoSel.selectedIndex].text : '';
        var sample = who === '';
        if (sample) who = 'Recipient name';

        var ach = (document.getElementById('isAch') || {}).value || '';
        var s = 0.62;
        var px = function (n) { return (n * s) + 'px'; };
        var today = new Date().toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });

        box.innerHTML =
            '<div style="width:' + px(720) + ';height:' + px(510) + ';background:' + paper + ';color:' + ink +
            ';border:1px solid ' + accent + '33;position:relative;overflow:hidden;box-sizing:border-box;font-family:Georgia,serif;">' +
            '<div style="position:absolute;inset:' + px(16) + ';border:' + px(2) + ' solid ' + accent + ';"></div>' +
            '<div style="position:absolute;inset:' + px(23) + ';border:1px solid ' + accent + '66;"></div>' +
            '<div style="position:absolute;inset:' + px(23) + ';display:flex;flex-direction:column;align-items:center;justify-content:center;' +
            'text-align:center;padding:' + px(26) + ' ' + px(44) + ';box-sizing:border-box;">' +
            '<div style="font-family:system-ui,sans-serif;font-size:' + px(11) + ';letter-spacing:' + px(2.4) +
            ';text-transform:uppercase;font-weight:700;color:' + deep + ';">' + ctEsc(CT_PLATFORM) + '</div>' +
            '<div style="width:' + px(46) + ';height:' + px(2) + ';background:' + accent + ';margin:' + px(12) + ' 0 ' + px(14) + ';"></div>' +
            '<div style="font-size:' + px(30) + ';letter-spacing:' + px(3) + ';text-transform:uppercase;line-height:1.15;">' +
            ctEsc(opt.getAttribute('data-name')) + '</div>' +
            '<div style="font-family:system-ui,sans-serif;font-size:' + px(11.5) + ';color:' + deep + 'CC;margin-top:' + px(14) +
            ';">This certificate is awarded to</div>' +
            '<div style="font-size:' + px(38) + ';font-style:italic;color:' + deep + ';margin:' + px(8) + ' 0 ' + px(6) +
            ';line-height:1.2;' + (sample ? 'opacity:.42;' : '') + '">' + ctEsc(who) + '</div>' +
            '<div style="width:' + px(280) + ';height:1px;background:' + accent + '88;margin-bottom:' + px(14) + ';"></div>' +
            '<div style="font-family:system-ui,sans-serif;font-size:' + px(12.5) + ';line-height:1.65;max-width:' + px(500) +
            ';color:' + ink + 'CC;">' + ctEsc(ach) + '</div>' +
            '<div style="position:absolute;left:' + px(44) + ';right:' + px(44) + ';bottom:' + px(30) +
            ';display:flex;justify-content:space-between;font-family:system-ui,sans-serif;">' +
            '<div style="text-align:center;min-width:' + px(150) + ';"><div style="height:1px;background:' + ink + '55;margin-bottom:' + px(5) +
            ';"></div><div style="font-size:' + px(11) + ';font-weight:600;">' + ctEsc(today) + '</div>' +
            '<div style="font-size:' + px(9.5) + ';color:' + ink + '99;text-transform:uppercase;">Date</div></div>' +
            '<div style="text-align:center;min-width:' + px(150) + ';"><div style="height:1px;background:' + ink + '55;margin-bottom:' + px(5) +
            ';"></div><div style="font-size:' + px(11) + ';font-weight:600;">' + ctEsc(CT_ISSUER) + '</div>' +
            '<div style="font-size:' + px(9.5) + ';color:' + ink + '99;text-transform:uppercase;">Issued by</div></div>' +
            '</div></div></div>';
    }
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
