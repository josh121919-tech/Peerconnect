<?php

/**
 * admin/settings_logs.php — System Settings → Activity Logs.
 *
 * What the platform has actually recorded. Two sources, both real:
 *
 *   `logs`          — sign-ins, sign-outs, sign-ups and password resets,
 *                     written by the auth flows with an email and a timestamp.
 *   moderation      — blocks and restrictions, which carry who did it and why.
 *
 * The reference design showed an IP address column and a Success/Failed
 * status per row. Neither is stored: nothing in this app records the client
 * IP, and a failed sign-in writes no row at all. Inventing either would make
 * the log unusable for the one thing a log is for. Both are named as gaps
 * below instead, along with what it would take to close them.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/settings_store.php';
require_admin();

date_default_timezone_set('Asia/Manila');

/* ── Filters ──────────────────────────────────────────────────────────── */
$KINDS = ['all' => 'Everything', 'login' => 'Sign-ins', 'logout' => 'Sign-outs',
          'signup' => 'Sign-ups', 'password' => 'Password resets', 'admin' => 'Admin activity'];
$kind = array_key_exists($_GET['kind'] ?? '', $KINDS) ? $_GET['kind'] : 'all';
$q    = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));

$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));

$clauses = [];
$types   = '';
$args    = [];

$kindSql = [
    'login'    => "l.activity LIKE 'user login%'",
    'logout'   => "l.activity LIKE '%logout%'",
    'signup'   => "l.activity LIKE '%signup%'",
    'password' => "l.activity LIKE 'password reset%'",
    'admin'    => "l.activity LIKE 'admin%'",
][$kind] ?? '';
if ($kindSql !== '') $clauses[] = $kindSql;

if ($q !== '') {
    $clauses[] = "CONCAT_WS(' ', l.email, l.activity, u.firstname, u.lastname) LIKE ?";
    $types .= 's';
    $args[] = '%' . $q . '%';
}
if ($from !== '' && strtotime($from)) { $clauses[] = 'l.log_date >= ?'; $types .= 's'; $args[] = date('Y-m-d', strtotime($from)); }
if ($to !== '' && strtotime($to))     { $clauses[] = 'l.log_date < ? + INTERVAL 1 DAY'; $types .= 's'; $args[] = date('Y-m-d', strtotime($to)); }

$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

// The join to a person is best-effort: `logs` stores an email, and an account
// deleted since is a row with no user to attach.
$base = "
    FROM logs l
    LEFT JOIN users u  ON u.email = l.email
    LEFT JOIN profile p ON p.user_id = u.user_id
";

$cs = $con->prepare("SELECT COUNT(*) c $base $where");
if ($types !== '') $cs->bind_param($types, ...$args);
$cs->execute();
$total = (int)$cs->get_result()->fetch_assoc()['c'];
$cs->close();

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$ls = $con->prepare("
    SELECT l.log_id, l.email, l.activity, l.log_date,
           CONCAT_WS(' ', u.firstname, u.lastname) AS name, u.role, u.user_id, p.profile_image
    $base $where
    ORDER BY l.log_id DESC
    LIMIT ? OFFSET ?
");
$ls->bind_param($types . 'ii', ...array_merge($args, [$perPage, $offset]));
$ls->execute();
$rows = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
$ls->close();

/* ── Figures ──────────────────────────────────────────────────────────── */
$one = function (string $sql) use ($con) {
    $r = $con->query($sql);
    return $r ? $r->fetch_row()[0] : null;
};
$allN     = (int)$one("SELECT COUNT(*) FROM logs");
$today    = (int)$one("SELECT COUNT(*) FROM logs WHERE log_date >= CURDATE() AND log_date < CURDATE() + INTERVAL 1 DAY");
$week     = (int)$one("SELECT COUNT(*) FROM logs WHERE log_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$people   = (int)$one("SELECT COUNT(DISTINCT email) FROM logs");
$adminN   = (int)$one("SELECT COUNT(*) FROM logs WHERE activity LIKE 'admin%'");

/* Moderation actions — the other half of "who did what". */
$moderation = $con->query("
    SELECT 'Restricted' AS what, r.restricted_at AS at_time, r.reason,
           CONCAT_WS(' ', t.firstname, t.lastname) AS target,
           CONCAT_WS(' ', a.firstname, a.lastname) AS actor
    FROM restrictions r
    JOIN users t ON t.user_id = r.user_id
    LEFT JOIN users a ON a.user_id = r.restricted_by
    UNION ALL
    SELECT 'Blocked', b.blocked_at, b.reason,
           CONCAT_WS(' ', t.firstname, t.lastname), NULL
    FROM blocks b JOIN users t ON t.user_id = b.user_id
    ORDER BY at_time DESC LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

/* Activity per day, for the chart. */
$days = [];
$cur = strtotime('-13 days');
while ($cur <= time()) { $days[date('Y-m-d', $cur)] = 0; $cur = strtotime('+1 day', $cur); }
$dq = $con->query("
    SELECT DATE(log_date) d, COUNT(*) c FROM logs
    WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY d
");
while ($r = $dq->fetch_assoc()) if (isset($days[$r['d']])) $days[$r['d']] = (int)$r['c'];

/* Who is busiest. */
$topUsers = $con->query("
    SELECT l.email, COUNT(*) n, CONCAT_WS(' ', u.firstname, u.lastname) AS name, u.role, u.user_id, p.profile_image
    FROM logs l
    LEFT JOIN users u ON u.email = l.email
    LEFT JOIN profile p ON p.user_id = u.user_id
    GROUP BY l.email, name, u.role, u.user_id, p.profile_image
    ORDER BY n DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

/** Colour and label for an activity string. */
function lg_kind(string $a): array
{
    if (str_contains($a, 'logout'))        return ['Sign-out', '#565B66', '#F3F4F6'];
    if (str_starts_with($a, 'admin'))      return ['Admin', '#6B21A8', '#F3E8FF'];
    if (str_contains($a, 'signup'))        return ['Sign-up', '#17654B', '#E6F5EE'];
    if (str_contains($a, 'password'))      return ['Password', '#B7791F', '#FEF6DC'];
    return ['Sign-in', '#1A5C9A', '#EAF1FB'];
}

function lg_url(array $over = []): string
{
    $p = array_merge([
        'kind' => $_GET['kind'] ?? null, 'q' => $_GET['q'] ?? null,
        'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null, 'page' => $_GET['page'] ?? null,
    ], $over);
    $p = array_filter($p, fn($v) => $v !== null && $v !== '');
    return url('admin-settings-logs') . ($p ? '?' . http_build_query($p) : '');
}

$hasFilter = ($q !== '' || $from !== '' || $to !== '');
$exportQs = http_build_query(array_filter(['kind' => $kind !== 'all' ? $kind : null, 'q' => $q ?: null,
    'from' => $from ?: null, 'to' => $to ?: null], fn($v) => $v !== null));

$current_page = 'settings-logs';
include 'layout.php';
include __DIR__ . '/includes/settings_ui.php';
?>

<?php ob_start(); ?>
<a class="ss-export" href="<?= url('admin-settings-logs-export') . ($exportQs ? '?' . $exportQs : '') ?>">
    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0 4-4m-4 4-4-4M4 19h16" /></svg>
    Export <?= number_format($total) ?> row<?= $total === 1 ? '' : 's' ?>
</a>
<a class="ss-export is-pdf" href="<?= url('admin-settings-logs-export') . ($exportQs ? '?' . $exportQs . '&amp;' : '?') ?>format=pdf">
    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6M6 18H4v-6h16v6h-2M8 14h8v7H8v-7Z" /></svg>
    Export PDF
</a>
<?php $actions = ob_get_clean(); ?>

<?php st_header('admin-settings-logs', 'Activity Logs',
    'Every sign-in, sign-out, sign-up and password reset the platform has recorded, and what admins changed or exported.', $actions); ?>

<div class="ss-stats">
    <?php foreach ([
        ['Entries', number_format($allN), 'Since the platform started', '#EAF1FB', '#1A5C9A', 'chart'],
        ['Today', number_format($today), $week . ' in the last 7 days', '#E6F5EE', '#17654B', 'clock'],
        ['People seen', number_format($people), 'Distinct email addresses', '#EAF6FC', '#087FC1', 'cal'],
        ['Admin activity', number_format($adminN), 'Admin sign-ins, changes and exports', '#F3E8FF', '#6B21A8', 'star'],
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

<div class="st-grid">
    <div class="st-stack">
        <div class="st-card">
            <form class="ss-filters" method="get" action="<?= url('admin-settings-logs') ?>" style="margin-bottom:14px;">
                <div class="ss-field ss-grow">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m20 20-4-4" /></svg>
                    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by name, email or action…">
                </div>
                <div class="ss-field">
                    <select name="kind" aria-label="Activity type">
                        <?php foreach ($KINDS as $k => $l): ?>
                            <option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ss-field"><label for="l-from">From</label><input id="l-from" type="date" name="from" value="<?= htmlspecialchars($from) ?>"></div>
                <div class="ss-field"><label for="l-to">To</label><input id="l-to" type="date" name="to" value="<?= htmlspecialchars($to) ?>"></div>
                <button type="submit" class="ss-apply">Apply</button>
                <?php if ($hasFilter || $kind !== 'all'): ?>
                    <a class="ss-clear" href="<?= url('admin-settings-logs') ?>">Reset</a>
                <?php endif; ?>
            </form>

            <?php if (!$rows): ?>
                <p class="ss-none">Nothing matches this view.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                        <thead>
                            <tr style="text-align:left;color:var(--gray-400);font-size:11px;text-transform:uppercase;letter-spacing:.05em;">
                                <th style="padding:8px 10px 8px 0;font-weight:700;">When</th>
                                <th style="padding:8px 10px;font-weight:700;">Who</th>
                                <th style="padding:8px 10px;font-weight:700;">Action</th>
                                <th style="padding:8px 0 8px 10px;font-weight:700;">Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r):
                                [$kl, $kf, $kb] = lg_kind($r['activity']); ?>
                                <tr style="border-top:1px solid var(--gray-100);">
                                    <td style="padding:11px 10px 11px 0;white-space:nowrap;color:var(--gray-600);">
                                        <?= date('M j, Y', strtotime($r['log_date'])) ?>
                                        <span style="display:block;font-size:11.5px;color:var(--gray-400);"><?= date('g:i A', strtotime($r['log_date'])) ?></span>
                                    </td>
                                    <td style="padding:11px 10px;">
                                        <div style="display:flex;align-items:center;gap:9px;">
                                            <span class="ss-av" style="width:30px;height:30px;font-size:10px;">
                                                <?= $r['profile_image'] ? '<img src="' . htmlspecialchars($r['profile_image']) . '" alt="">' : htmlspecialchars(strtoupper(substr($r['name'] ?: $r['email'], 0, 2))) ?>
                                            </span>
                                            <span style="min-width:0;">
                                                <?php if ($r['user_id']): ?>
                                                    <a href="<?= url('admin-user') ?>?id=<?= (int)$r['user_id'] ?>" style="font-weight:600;color:var(--gray-800);text-decoration:none;"><?= htmlspecialchars($r['name'] ?: $r['email']) ?></a>
                                                <?php else: ?>
                                                    <b style="font-weight:600;color:var(--gray-800);"><?= htmlspecialchars($r['email']) ?></b>
                                                <?php endif; ?>
                                                <span style="display:block;font-size:11.5px;color:var(--gray-400);"><?= htmlspecialchars($r['role'] ? ucfirst($r['role']) : 'No account on file') ?></span>
                                            </span>
                                        </div>
                                    </td>
                                    <td style="padding:11px 10px;color:var(--gray-700);"><?= htmlspecialchars(ucfirst($r['activity'])) ?></td>
                                    <td style="padding:11px 0 11px 10px;">
                                        <span class="st-badge" style="background:<?= $kb ?>;color:<?= $kf ?>;"><?= $kl ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="ss-foot">
                    <span>Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= number_format($total) ?></span>
                    <?php pc_pagination($page, $totalPages, fn(int $n) => lg_url(['page' => $n]), ['label' => 'Log pages']); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════ Side ══════════ -->
    <div class="st-stack">
        <div class="st-card">
            <h2>Last 14 days</h2>
            <?php
            $vals = array_values($days);
            $peak = max(1, max($vals));
            $W = 280; $H = 90; ?>
            <svg viewBox="0 0 <?= $W ?> <?= $H ?>" style="width:100%;height:90px;" role="img" aria-label="Log entries per day for the last 14 days">
                <?php foreach ($vals as $i => $v):
                    $bw = ($W - 8) / count($vals);
                    $bh = max(2, ($v / $peak) * ($H - 20));
                ?>
                    <rect x="<?= round($i * $bw + 3, 1) ?>" y="<?= round($H - 14 - $bh, 1) ?>" width="<?= round($bw - 4, 1) ?>" height="<?= round($bh, 1) ?>"
                          rx="2" fill="#1B6FD1" opacity="<?= $v > 0 ? '0.85' : '0.18' ?>"><title><?= array_keys($days)[$i] ?>: <?= $v ?></title></rect>
                <?php endforeach; ?>
                <text x="3" y="<?= $H - 2 ?>" font-size="9" fill="#9A9EA6"><?= date('M j', strtotime(array_key_first($days))) ?></text>
                <text x="<?= $W - 3 ?>" y="<?= $H - 2 ?>" text-anchor="end" font-size="9" fill="#9A9EA6">Today</text>
            </svg>
            <p class="ss-none">Peak day: <?= $peak ?> entr<?= $peak === 1 ? 'y' : 'ies' ?>.</p>
        </div>

        <div class="st-card">
            <h2>Busiest accounts</h2>
            <?php if (!$topUsers): ?>
                <p class="ss-none">Nothing logged yet.</p>
            <?php else: foreach ($topUsers as $i => $t): ?>
                <div class="st-row">
                    <span style="width:18px;flex:none;font-size:12px;font-weight:700;color:var(--gray-400);"><?= $i + 1 ?></span>
                    <span class="ss-av" style="width:30px;height:30px;font-size:10px;">
                        <?= $t['profile_image'] ? '<img src="' . htmlspecialchars($t['profile_image']) . '" alt="">' : htmlspecialchars(strtoupper(substr($t['name'] ?: $t['email'], 0, 2))) ?>
                    </span>
                    <span style="min-width:0;flex:1;">
                        <b><?= htmlspecialchars($t['name'] ?: $t['email']) ?></b>
                        <span class="h"><?= htmlspecialchars($t['role'] ? ucfirst($t['role']) : 'No account') ?></span>
                    </span>
                    <span style="font-size:12.5px;font-weight:700;color:var(--forest);"><?= (int)$t['n'] ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="st-card">
            <h2>Moderation actions</h2>
            <p class="sub">Blocks and restrictions, from their own tables.</p>
            <?php if (!$moderation): ?>
                <p class="ss-none">Nobody has been blocked or restricted.</p>
            <?php else: foreach ($moderation as $m): ?>
                <div class="st-row">
                    <span class="st-dot" style="background:<?= $m['what'] === 'Blocked' ? '#FBE5E1' : '#FEF6DC' ?>;color:<?= $m['what'] === 'Blocked' ? '#A6301F' : '#B7791F' ?>;">
                        <?= ss_icon('x') ?>
                    </span>
                    <span style="min-width:0;flex:1;">
                        <b><?= $m['what'] ?>: <?= htmlspecialchars($m['target']) ?></b>
                        <span class="h">
                            <?= $m['actor'] ? 'By ' . htmlspecialchars($m['actor']) . ' · ' : '' ?>
                            <?= $m['at_time'] ? date('M j, Y', strtotime($m['at_time'])) : '' ?>
                        </span>
                        <?php if (trim((string)$m['reason']) !== ''): ?>
                            <span class="h"><?= htmlspecialchars($m['reason']) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="st-card">
            <h2>What this log does not have</h2>
            <div class="st-note warn">
                <b>No IP address, and no failed attempts.</b>
                The reference design showed both. Nothing in this app records the client IP on a log row, and a
                failed sign-in writes no row at all — so every entry here would say "Success" and every IP would
                have to be made up. Recording them means adding columns to <code>logs</code> and writing a row
                on failure too; until that exists, the log says what it actually knows.
            </div>
            <div class="st-note">
                <b>Admin actions are partly covered.</b>
                Blocks and restrictions carry who did them. Approving a verification, cancelling a session or
                publishing an announcement do not yet write a log row — those actions notify the person affected
                instead.
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/layout_end.php'; ?>
