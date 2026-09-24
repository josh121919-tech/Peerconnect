<?php

/**
 * admin/notifications.php — Notifications.
 *
 * Where an admin keeps members informed while a report is handled: the
 * reports themselves, the notices admins have sent to mentors and mentees,
 * and anything addressed to the admin (a member replying, for example).
 *
 * From a report an admin can tell the reported member it was received —
 * only its category, never who filed it or what they wrote — tell the
 * reporter it is being reviewed or was closed, message either of them, or
 * act on it. A notice of the admin's own words can go to any mentor or
 * mentee. Every notice lands in the member's bell, and by email when email
 * sending is on; who sent it is kept in the activity log.
 *
 * Every figure is a count of real rows. The design this follows also showed
 * login-activity alerts and High/Medium/Low priorities; nothing in the app
 * records either, so they are not shown. A report is Urgent, Open or
 * Resolved, which is what the reports table holds.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/moderation_helpers.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$me   = (int)($_SESSION['user_id'] ?? 0);
$csrf = csrf_token();

// A value sent as a list counts as missing.
$get = fn(string $name): string => is_string($_GET[$name] ?? null) ? trim($_GET[$name]) : '';
$num = fn(string $name): int => ctype_digit($get($name)) ? (int)$get($name) : 0;

$TABS = ['all' => 'All', 'reports' => 'Reports', 'foryou' => 'For you', 'sent' => 'Sent'];
$tab  = isset($TABS[$get('tab')]) ? $get('tab') : 'all';
$q    = $get('q');
$page = max(1, $num('page'));
$per  = 8;

// The newest this many of each kind are listed; older ones stay in the
// database and on each member's own page.
const NF_LIST_LIMIT = 200;

/* ── The items ────────────────────────────────────────────────────────── */
$issue = fn(?string $t) => ReportService::label($t);
$role  = fn(?string $r) => $r ? ucfirst($r) : 'Member';
$items = [];

foreach (ModerationRepository::reportsForInbox($con, NF_LIST_LIMIT) as $r) {
    $status = (string)$r['status'];
    $items[] = [
        'kind'  => 'report',
        'id'    => (int)$r['report_id'],
        'title' => $role($r['reported_role']) . ' reported',
        'text'  => ($r['reported_name'] ?: 'An account') . ' (' . $role($r['reported_role']) . ') was reported by '
                 . ($r['reporter_name'] ?: 'a removed account') . ($r['reporter_role'] ? ' (' . $role($r['reporter_role']) . ')' : '') . '.',
        'meta'  => 'Reason: ' . $issue($r['issue_type']),
        'when'  => (string)$r['created_at'],
        'chip'  => match ($status) {
            'urgent'   => ['Urgent', 'red'],
            'resolved' => ['Resolved', 'green'],
            'reviewed' => ['Reviewed', 'grey'],
            default    => ['Open', 'amber'],
        },
        'open'  => in_array($status, ['pending', 'urgent'], true),
    ];
}
foreach (NotificationRepository::recentForBell($con, $me, NF_LIST_LIMIT) as $n) {
    $items[] = [
        'kind'  => 'foryou',
        'id'    => (int)$n['notification_id'],
        'title' => (string)$n['title'],
        'text'  => (string)$n['message'],
        'meta'  => 'For you',
        'when'  => (string)$n['created_at'],
        'chip'  => (int)$n['is_read'] ? ['Read', 'grey'] : ['Unread', 'blue'],
        'open'  => !(int)$n['is_read'],
    ];
}
$NOTICE_KINDS = ['report_notice' => 'Report notice', 'report_update' => 'Update to reporter', 'admin_notice' => 'Your own notice'];
foreach (NotificationRepository::sentByAdmins($con, NF_LIST_LIMIT) as $n) {
    $items[] = [
        'kind'  => 'sent',
        'id'    => (int)$n['notification_id'],
        'title' => (string)$n['title'],
        'text'  => 'To ' . ($n['recipient_name'] ?: 'a member') . ' (' . $role($n['recipient_role']) . '): ' . $n['message'],
        'meta'  => $NOTICE_KINDS[$n['type']] ?? 'Notice',
        'when'  => (string)$n['created_at'],
        'chip'  => (int)$n['is_read'] ? ['Read', 'green'] : ['Not read yet', 'grey'],
        'open'  => false,
    ];
}

$counts = ['all' => count($items), 'reports' => 0, 'foryou' => 0, 'sent' => 0];
foreach ($items as $it) {
    $counts[$it['kind'] === 'report' ? 'reports' : $it['kind']]++;
}

// Open reports first on the Reports tab (the query's order); newest first
// everywhere else.
$shown = array_values(array_filter($items, fn($it) => $tab === 'all' || $it['kind'] === ($tab === 'reports' ? 'report' : $tab)));
if ($tab !== 'reports') {
    usort($shown, fn($a, $b) => strcmp($b['when'], $a['when']));
}
if ($q !== '') {
    $shown = array_values(array_filter($shown, fn($it) =>
        mb_stripos($it['title'] . ' ' . $it['text'] . ' ' . $it['meta'], $q) !== false));
}
$total = count($shown);
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$list  = array_slice($shown, ($page - 1) * $per, $per);

/* ── The selected item ────────────────────────────────────────────────── */
$sel = null;
if ($num('report')) {
    $sel = ['kind' => 'report', 'id' => $num('report')];
} elseif ($num('note')) {
    $sel = ['kind' => 'foryou', 'id' => $num('note')];
} elseif ($num('sent')) {
    $sel = ['kind' => 'sent', 'id' => $num('sent')];
} elseif ($list) {
    $sel = ['kind' => $list[0]['kind'], 'id' => $list[0]['id']];
}

$report = $note = null;
$notices = [];
if ($sel && $sel['kind'] === 'report') {
    $report = ModerationRepository::reportDetail($con, $sel['id']);
    if ($report) {
        // Notices already sent about this report, from the activity log.
        foreach (LogRepository::adminEntriesContaining($con, 'about report #' . $report['report_id'] . ' to ', 20) as $l) {
            if (preg_match('/^admin sent the "(.+?)" notice about report #\d+ to (.+?) \(user #\d+\)$/', (string)$l['activity'], $m)) {
                $notices[] = ['title' => $m[1], 'to' => $m[2], 'when' => (string)$l['log_date'], 'by' => (string)$l['email']];
            }
        }
    }
} elseif ($sel) {
    $note = NotificationRepository::withRecipient($con, $sel['id']);
    $isMine = $note && (int)$note['user_id'] === $me;
    $isSent = $note && in_array($note['type'], NotificationRepository::ADMIN_NOTICE_TYPES, true);
    if (!$note || ($sel['kind'] === 'foryou' && !$isMine) || ($sel['kind'] === 'sent' && !$isSent)) {
        $note = null;
    } elseif ($isMine && !(int)$note['is_read']) {
        // Opening it is reading it.
        NotificationRepository::markRead($con, (int)$note['notification_id'], $me);
        $note['is_read'] = 1;
    }
}

$recipients = UserRepository::noticeRecipients($con);
$preset_to  = $num('to');

/* ── Figures ──────────────────────────────────────────────────────────── */
$c_open    = ModerationRepository::countOpenReports($con);
$c_verify  = AdminUserRepository::headlineCounts($con)['pending'];
$c_unread  = NotificationRepository::countUnread($con, $me);
$c_sent30  = NotificationRepository::countSentByAdminsSince($con, 30);

/* ── Helpers ──────────────────────────────────────────────────────────── */
$link = function (array $over = []) use ($tab, $q, $page): string {
    $p = array_merge(['tab' => $tab !== 'all' ? $tab : null, 'q' => $q !== '' ? $q : null, 'page' => $page > 1 ? $page : null], $over);
    return url('admin-notifications') . '?' . http_build_query(array_filter($p, fn($v) => $v !== null && $v !== ''));
};
$selKey = function (array $it): array {
    return match ($it['kind']) { 'report' => ['report' => $it['id']], 'foryou' => ['note' => $it['id']], default => ['sent' => $it['id']] };
};
function nf_when(?string $when): string
{
    if (!$when) return '';
    $t = strtotime($when);
    if (date('Y-m-d', $t) === date('Y-m-d')) return 'Today, ' . date('g:i A', $t);
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday, ' . date('g:i A', $t);
    return date('M j, Y g:i A', $t);
}
function nf_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    return strtoupper(substr($parts[0] ?? 'M', 0, 1) . substr($parts[1] ?? '', 0, 1)) ?: 'M';
}

// Refused Block/Restrict posts from this page come back with ?error=.
if ($get('error') === 'self') {
    pc_flash('error', 'You cannot apply that to your own account.');
} elseif ($get('error') !== '') {
    pc_flash('error', 'That action needs a reason before it can be applied.');
}

$ICONS = [
    'report' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 8v5m0 3.5h.01M12 3a9 9 0 1 1 0 18 9 9 0 0 1 0-18Z"/>',
    'foryou' => '<path stroke-linecap="round" stroke-linejoin="round" d="M20 12a7 7 0 0 1-7 7H8.5L5 21.5V18A7 7 0 0 1 12 5h1a7 7 0 0 1 7 7Z"/>',
    'sent'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 12 20 4l-6 16-2.5-6.5L4 12Z"/>',
];

$um_return    = 'notifications';
$current_page = 'notifications';
include 'layout.php';
?>

<style>
    .nf-hd { display: flex; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .nf-hd-ico { flex: none; width: 52px; height: 52px; border-radius: 14px; display: grid; place-items: center; background: #EAF2FE; color: #1B4FB8; }
    .nf-hd-ico svg { width: 25px; height: 25px; }
    .nf-hd h1 { margin: 0; font-size: 25px; font-weight: 700; color: var(--forest); letter-spacing: -.02em; }
    .nf-hd p { margin: 3px 0 0; font-size: 13.5px; color: var(--gray-400); max-width: 62ch; }
    .nf-hd > div { flex: 1; min-width: 240px; }
    .nf-new { display: inline-flex; align-items: center; gap: 8px; padding: 11px 18px; border-radius: 11px; border: none; background: var(--primary); color: #fff; font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; }
    .nf-new:hover { background: var(--primary-2); }
    .nf-new svg { width: 16px; height: 16px; }

    .nf-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
    /* The shared figure tile — see .ss-stat in includes/sessions_ui.php. */
    .nf-stat { display: flex; flex-direction: column; align-items: flex-start; gap: 12px; padding: 18px; border-radius: var(--stat-radius); background: #fff; border: 1px solid var(--stat-border); text-decoration: none; color: inherit; box-shadow: var(--stat-shadow); transition: box-shadow .16s ease; }
    .nf-stat:hover { box-shadow: var(--stat-shadow-hover); }
    .nf-stat > span { display: flex; flex-direction: column; }
    .nf-stat-ico { flex: none; width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; }
    .nf-stat-ico svg { width: 19px; height: 19px; }
    .nf-stat-k { order: 2; font-size: 12px; color: var(--gray-500); font-weight: 500; text-transform: uppercase; letter-spacing: .05em; }
    .nf-stat-v { order: 1; font-size: 26px; line-height: 1.15; font-weight: 600; letter-spacing: -0.03em; color: var(--forest); font-variant-numeric: tabular-nums; }
    .nf-stat-s { order: 3; font-size: 11.5px; font-weight: 600; margin-top: 8px; }

    .nf-grid { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 18px; align-items: start; }
    .nf-card { background: #fff; border: 1px solid var(--gray-100); border-radius: 16px; box-shadow: 0 1px 2px rgba(16,24,40,.04); }

    .nf-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 6px 16px 0; border-bottom: 1px solid var(--gray-100); }
    .nf-tabs { display: flex; gap: 4px; flex-wrap: wrap; }
    .nf-tab { display: inline-flex; align-items: center; gap: 7px; padding: 13px 12px 12px; font-size: 13.5px; font-weight: 500; color: var(--gray-500); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; }
    .nf-tab:hover { color: var(--forest); }
    .nf-tab.on { color: #1B4FB8; font-weight: 600; border-bottom-color: #1B4FB8; }
    .nf-tab b { padding: 1px 7px; border-radius: 99px; background: var(--gray-100); color: var(--gray-600); font-size: 11.5px; }
    .nf-tab.on b { background: #EAF2FE; color: #1B4FB8; }
    .nf-search { display: flex; align-items: center; gap: 8px; padding: 8px 12px; margin: 6px 0; border: 1px solid var(--gray-200); border-radius: 10px; min-width: 230px; }
    .nf-search svg { width: 15px; height: 15px; color: var(--gray-400); flex: none; }
    .nf-search input { border: 0; outline: 0; font: inherit; font-size: 13px; width: 100%; background: transparent; }

    .nf-list { display: flex; flex-direction: column; }
    .nf-item { display: flex; align-items: flex-start; gap: 14px; padding: 16px 18px; border-bottom: 1px solid var(--gray-100); text-decoration: none; color: inherit; border-left: 3px solid transparent; }
    .nf-item:hover { background: #FAFBFD; }
    .nf-item.sel { background: #F3F7FE; border-left-color: #1B4FB8; }
    .nf-ico { flex: none; width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; }
    .nf-ico svg { width: 19px; height: 19px; }
    .nf-ico.report { background: #FDE8E6; color: #C2362A; }
    .nf-ico.foryou { background: #E6F0FD; color: #1B6FD1; }
    .nf-ico.sent   { background: #E6F5EE; color: #17654B; }
    .nf-body { flex: 1; min-width: 0; }
    .nf-t { font-size: 14px; font-weight: 700; color: var(--gray-900); }
    .nf-x { margin-top: 3px; font-size: 13px; color: var(--gray-600); overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow-wrap: anywhere; }
    .nf-m { margin-top: 3px; font-size: 12px; color: var(--gray-400); }
    .nf-side { flex: none; display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
    .nf-when { font-size: 12px; color: var(--gray-400); white-space: nowrap; }
    .nf-chip { padding: 3px 10px; border-radius: 99px; font-size: 11.5px; font-weight: 700; white-space: nowrap; }
    .nf-chip.red { background: #FDE8E6; color: #B42318; }
    .nf-chip.amber { background: #FBF0D4; color: #8A6400; }
    .nf-chip.green { background: #E6F5EE; color: #17654B; }
    .nf-chip.blue { background: #E6F0FD; color: #1B6FD1; }
    .nf-chip.grey { background: var(--gray-100); color: var(--gray-500); }
    .nf-empty { padding: 48px 20px; text-align: center; color: var(--gray-400); font-size: 13.5px; }

    .nf-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 14px 18px; font-size: 13px; color: var(--gray-500); }

    .nf-detail { padding: 20px; position: sticky; top: 16px; }
    .nf-d-hd { display: flex; align-items: flex-start; gap: 12px; }
    .nf-d-hd h2 { margin: 0; font-size: 17px; font-weight: 700; color: var(--gray-900); }
    .nf-d-sub { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 6px; font-size: 12.5px; color: var(--gray-400); }
    .nf-alert { margin: 16px 0 4px; padding: 12px 14px; border-radius: 12px; background: #FDF0EE; border: 1px solid #F6D3CE; color: #A6301F; font-size: 13px; line-height: 1.5; }
    .nf-sec { margin: 20px 0 10px; font-size: 14.5px; font-weight: 700; color: var(--gray-900); }
    .nf-kv { display: grid; grid-template-columns: 96px minmax(0, 1fr); gap: 14px 12px; font-size: 13px; }
    .nf-k { color: var(--gray-500); }
    .nf-v { color: var(--gray-800); overflow-wrap: anywhere; line-height: 1.55; }
    .nf-person { display: flex; align-items: center; gap: 10px; }
    .nf-av { flex: none; width: 36px; height: 36px; border-radius: 50%; overflow: hidden; display: grid; place-items: center; background: #E7F0FB; color: #1E4E86; font-size: 13px; font-weight: 700; }
    .nf-av img { width: 100%; height: 100%; object-fit: cover; }
    .nf-person b { display: block; font-size: 13.5px; color: var(--gray-900); }
    .nf-person span { font-size: 12px; color: var(--gray-500); }
    .nf-sent-list { display: flex; flex-direction: column; gap: 8px; }
    .nf-sent-row { padding: 9px 11px; border-radius: 10px; background: var(--gray-50, #F9FAFB); font-size: 12.5px; color: var(--gray-600); line-height: 1.5; }
    .nf-sent-row b { color: var(--gray-800); }
    .nf-acts { display: flex; flex-direction: column; gap: 9px; margin-top: 4px; }
    .nf-act { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 11px 14px; border-radius: 10px; border: 1px solid var(--gray-200); background: #fff; font: inherit; font-size: 13.5px; font-weight: 600; color: var(--gray-700); text-decoration: none; cursor: pointer; }
    .nf-act:hover { border-color: #9DB7E8; color: #1B4FB8; }
    .nf-act.primary { background: #1B4FB8; border-color: #1B4FB8; color: #fff; }
    .nf-act.primary:hover { background: #163F93; color: #fff; }
    .nf-act.danger { color: #A6301F; }
    .nf-act svg { width: 16px; height: 16px; }
    .nf-act-row { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; }
    .nf-act-row form { display: contents; }
    .nf-proof { padding: 0; border: 0; background: none; font: inherit; font-size: 13px; font-weight: 600; color: #1B4FB8; text-decoration: underline; cursor: pointer; }
    .nf-msg { margin: 14px 0 0; padding: 14px; border-radius: 12px; background: var(--gray-50, #F9FAFB); font-size: 13.5px; line-height: 1.6; color: var(--gray-700); white-space: pre-line; overflow-wrap: anywhere; }

    .nf-preview { margin-bottom: 14px; padding: 12px 14px; border-radius: 12px; background: #F3F7FE; border: 1px solid #DCE6F8; font-size: 13px; line-height: 1.55; color: var(--gray-700); }
    .nf-preview b { display: block; margin-bottom: 4px; color: var(--gray-900); }
    .nf-choice { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 10px; font-size: 13px; font-weight: 400 !important; }
    .nf-choice input { width: auto !important; margin: 3px 0 0 !important; }
    .nf-note { margin: -6px 0 14px; font-size: 12px; color: var(--gray-400); }

    @media (max-width: 1180px) {
        .nf-grid { grid-template-columns: 1fr; }
        .nf-detail { position: static; }
    }
    @media (max-width: 900px) {
        .nf-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 700px) {
        .nf-stat { flex-direction: row; align-items: center; gap: 10px; padding: 12px 14px; }
        .nf-stat-ico { width: 34px; height: 34px; }
        .nf-stat-ico svg { width: 15px; height: 15px; }
        .nf-stat-v { font-size: 18px; }
        .nf-stat-k { font-size: 10px; text-transform: none; letter-spacing: 0; line-height: 1.2; }
        .nf-stat-s { display: none; }
    }
</style>

<div class="nf-hd">
    <span class="nf-hd-ico">
        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9" /></svg>
    </span>
    <div>
        <h1>Notifications</h1>
        <p>Reports waiting for review, the notices admins send to mentors and mentees, and anything addressed to you.</p>
    </div>
    <button type="button" class="nf-new" onclick="umOpen('nfComposeModal')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
        Send a notice
    </button>
</div>

<div class="nf-stats">
    <?php foreach ([
        [$link(['tab' => 'reports', 'page' => null]), 'Open reports', $c_open, $c_open === 1 ? 'needs review' : 'need review', '#FDE8E6', '#C2362A', $ICONS['report']],
        [url('admin-users') . '?tab=pending', 'Verifications', $c_verify, 'waiting for review', '#E6F0FD', '#1B6FD1', '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
        [$link(['tab' => 'foryou', 'page' => null]), 'For you', $c_unread, 'unread', '#EEEAFB', '#5340A6', $ICONS['foryou']],
        [$link(['tab' => 'sent', 'page' => null]), 'Sent to members', $c_sent30, 'in the last 30 days', '#E6F5EE', '#17654B', $ICONS['sent']],
    ] as [$href, $k, $v, $s, $bg, $fg, $path]): ?>
        <a class="nf-stat" href="<?= htmlspecialchars($href) ?>">
            <span class="nf-stat-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;"><svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><?= $path ?></svg></span>
            <span>
                <span class="nf-stat-k"><?= $k ?></span>
                <span class="nf-stat-v" style="display:block;"><?= number_format($v) ?></span>
                <span class="nf-stat-s" style="color:<?= $fg ?>;"><?= $s ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</div>

<div class="nf-grid">
    <section class="nf-card">
        <div class="nf-bar">
            <nav class="nf-tabs" aria-label="Notification lists">
                <?php foreach ($TABS as $k => $label): ?>
                    <a class="nf-tab <?= $tab === $k ? 'on' : '' ?>" href="<?= htmlspecialchars($link(['tab' => $k !== 'all' ? $k : null, 'page' => null])) ?>">
                        <?= $label ?><b><?= $counts[$k] ?></b>
                    </a>
                <?php endforeach; ?>
            </nav>
            <form class="nf-search" method="get" action="<?= htmlspecialchars(url('admin-notifications')) ?>" role="search">
                <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>"><?php endif; ?>
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m20 20-4-4" /></svg>
                <label class="sr-only" for="nfQ" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Search notifications</label>
                <input id="nfQ" type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search notifications…">
            </form>
        </div>

        <div class="nf-list">
            <?php if (!$list): ?>
                <p class="nf-empty"><?= $q !== '' ? 'Nothing matches that search.' : ($tab === 'reports' ? 'No reports have been filed.' : ($tab === 'foryou' ? 'Nothing has been addressed to you yet.' : ($tab === 'sent' ? 'No notices have been sent to members yet.' : 'Nothing here yet.'))) ?></p>
            <?php endif; ?>
            <?php foreach ($list as $it):
                $isSel = $sel && $sel['kind'] === $it['kind'] && $sel['id'] === $it['id']; ?>
                <a class="nf-item <?= $isSel ? 'sel' : '' ?>" href="<?= htmlspecialchars($link($selKey($it))) ?>">
                    <span class="nf-ico <?= $it['kind'] ?>"><svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24" aria-hidden="true"><?= $ICONS[$it['kind']] ?></svg></span>
                    <span class="nf-body">
                        <span class="nf-t"><?= htmlspecialchars($it['title']) ?></span>
                        <span class="nf-x" style="display:-webkit-box;"><?= htmlspecialchars($it['text']) ?></span>
                        <span class="nf-m" style="display:block;"><?= htmlspecialchars($it['meta']) ?></span>
                    </span>
                    <span class="nf-side">
                        <span class="nf-when"><?= htmlspecialchars(nf_when($it['when'])) ?></span>
                        <span class="nf-chip <?= $it['chip'][1] ?>"><?= htmlspecialchars($it['chip'][0]) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="nf-foot">
            <span><?= $total === 0 ? 'No notifications' : 'Showing ' . (($page - 1) * $per + 1) . ' to ' . min($page * $per, $total) . ' of ' . $total ?></span>
            <?php pc_pagination($page, $pages, fn(int $n) => $link(['page' => $n]), ['label' => 'Notification pages']); ?>
        </div>
    </section>

    <aside class="nf-card nf-detail" aria-label="Details">
        <?php if ($report):
            $rid       = (int)$report['report_id'];
            $status    = (string)$report['status'];
            $isOpen    = in_array($status, ['pending', 'urgent'], true);
            $chip      = $status === 'urgent' ? ['Urgent', 'red'] : ($status === 'resolved' ? ['Resolved', 'green'] : ($isOpen ? ['Open', 'amber'] : ['Reviewed', 'grey']));
            $rName     = $report['reported_name'] ?: 'An account';
            $bName     = $report['reporter_name'] ?: 'A removed account';
            $rGone     = (int)$report['reported_deleted'] === 1;
            $bGone     = $report['reported_by'] === null || (int)$report['reporter_deleted'] === 1 || !in_array($report['reporter_role'], ['mentee', 'mentor'], true);
            $rBlocked  = $report['reported_status'] === 'blocked';
            $proof     = ReportService::proofUrl($report['proof']); ?>
            <div class="nf-d-hd">
                <span class="nf-ico report"><svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><?= $ICONS['report'] ?></svg></span>
                <div>
                    <h2><?= htmlspecialchars($role($report['reported_role']) . ' reported') ?></h2>
                    <div class="nf-d-sub"><span class="nf-chip <?= $chip[1] ?>"><?= $chip[0] ?></span><?= htmlspecialchars(nf_when($report['created_at'])) ?></div>
                </div>
            </div>
            <?php if ($isOpen): ?>
                <p class="nf-alert">This report is waiting for review. Nothing has been done about it yet.</p>
            <?php endif; ?>

            <p class="nf-sec">Report details</p>
            <div class="nf-kv">
                <span class="nf-k">Reported member</span>
                <div class="nf-person">
                    <span class="nf-av"><?php if (!empty($report['reported_photo'])): ?><img src="<?= htmlspecialchars($report['reported_photo']) ?>" alt=""><?php else: ?><?= htmlspecialchars(nf_initials($rName)) ?><?php endif; ?></span>
                    <span><b><?= htmlspecialchars($rName) ?></b><span><?= htmlspecialchars($role($report['reported_role'])) ?><?= $rGone ? ' · deleted their account' : ($report['reported_status'] !== 'active' ? ' · ' . htmlspecialchars((string)$report['reported_status']) : '') ?></span></span>
                </div>
                <span class="nf-k">Reporter</span>
                <div class="nf-person">
                    <span class="nf-av"><?php if (!empty($report['reporter_photo'])): ?><img src="<?= htmlspecialchars($report['reporter_photo']) ?>" alt=""><?php else: ?><?= htmlspecialchars(nf_initials($bName)) ?><?php endif; ?></span>
                    <span><b><?= htmlspecialchars($bName) ?></b><span><?= htmlspecialchars($role($report['reporter_role'])) ?></span></span>
                </div>
                <span class="nf-k">Reason</span>
                <span class="nf-v"><span class="nf-chip red"><?= htmlspecialchars($issue($report['issue_type'])) ?></span></span>
                <span class="nf-k">Description</span>
                <span class="nf-v"><?= nl2br(htmlspecialchars((string)$report['description'])) ?></span>
                <?php if ($proof !== ''): ?>
                    <span class="nf-k">Attached</span>
                    <span class="nf-v"><button type="button" class="nf-proof" onclick="umDoc(<?= htmlspecialchars(json_encode($proof), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode('Attached to the report about ' . $rName), ENT_QUOTES) ?>)">View attached image</button></span>
                <?php endif; ?>
                <span class="nf-k">Reported at</span>
                <span class="nf-v"><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$report['created_at']))) ?></span>
            </div>

            <p class="nf-sec">Notices sent about this report</p>
            <?php if (!$notices): ?>
                <p class="nf-v" style="font-size:13px;color:var(--gray-400);margin:0;">None yet.</p>
            <?php else: ?>
                <div class="nf-sent-list">
                    <?php foreach ($notices as $n): ?>
                        <div class="nf-sent-row"><b>“<?= htmlspecialchars($n['title']) ?>”</b> to <?= htmlspecialchars($n['to']) ?><br><?= htmlspecialchars(nf_when($n['when'])) ?> · <?= htmlspecialchars($n['by']) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p class="nf-sec">Actions</p>
            <div class="nf-acts">
                <a class="nf-act primary" href="<?= htmlspecialchars(url('admin-user') . '?id=' . (int)$report['reported_user_id']) ?>">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6" /><path stroke-linecap="round" d="M5 20c.7-3.5 3.5-5.4 7-5.4s6.3 1.9 7 5.4" /></svg>
                    View <?= htmlspecialchars($rName) ?>'s account
                </a>
                <?php if (!$rGone): ?>
                    <button type="button" class="nf-act" onclick="umOpen('nfNotifyReported')">
                        <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><?= $ICONS['sent'] ?></svg>
                        Notify the reported member
                    </button>
                <?php endif; ?>
                <?php if (!$bGone): ?>
                    <button type="button" class="nf-act" onclick="umOpen('nfNotifyReporter')">
                        <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><?= $ICONS['sent'] ?></svg>
                        Notify the reporter
                    </button>
                <?php endif; ?>
                <?php if ($isOpen && !$rGone && !$rBlocked): ?>
                    <div class="nf-act-row">
                        <button type="button" class="nf-act danger" onclick="umBlock(0, <?= $rid ?>, <?= htmlspecialchars(json_encode($rName), ENT_QUOTES) ?>)">Block</button>
                        <?php if ($report['reported_role'] !== 'admin'): ?>
                            <button type="button" class="nf-act" onclick="umRestrict(0, <?= $rid ?>, <?= htmlspecialchars(json_encode($rName), ENT_QUOTES) ?>)">Restrict</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($isOpen): ?>
                    <form method="post" action="<?= url('admin-action-resolve') ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="report_id" value="<?= $rid ?>">
                        <input type="hidden" name="return_to" value="notifications">
                        <button type="submit" class="nf-act" style="width:100%;">Dismiss report</button>
                    </form>
                <?php endif; ?>
                <div class="nf-act-row">
                    <?php if (!$rGone && !$rBlocked): ?>
                        <a class="nf-act" href="<?= htmlspecialchars(url('messages') . '?chat=' . (int)$report['reported_user_id']) ?>">Message member</a>
                    <?php endif; ?>
                    <?php if (!$bGone && $report['reporter_status'] !== 'blocked'): ?>
                        <a class="nf-act" href="<?= htmlspecialchars(url('messages') . '?chat=' . (int)$report['reported_by']) ?>">Message reporter</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php // The two report notices, worded here from ReportService so what the admin reads is what is sent. ?>
            <?php if (!$rGone): [$t1, $m1] = ReportService::noticeText('report_received', $report); ?>
                <div class="um-overlay" id="nfNotifyReported">
                    <form class="um-modal" method="post" action="<?= url('admin-action-notify') ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="report_id" value="<?= $rid ?>">
                        <input type="hidden" name="recipient_id" value="<?= (int)$report['reported_user_id'] ?>">
                        <input type="hidden" name="notice" value="report_received">
                        <h3>Notify <?= htmlspecialchars($rName) ?></h3>
                        <p>They are told a report was received and what it is about. They are not told who filed it or what was written.</p>
                        <div class="nf-preview"><b><?= htmlspecialchars($t1) ?></b><?= htmlspecialchars($m1) ?></div>
                        <div class="um-modal-foot">
                            <button type="button" class="um-cancel" onclick="umClose()">Cancel</button>
                            <button type="submit" class="um-btn um-ok">Send notice</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            <?php if (!$bGone): ?>
                <div class="um-overlay" id="nfNotifyReporter">
                    <form class="um-modal" method="post" action="<?= url('admin-action-notify') ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="report_id" value="<?= $rid ?>">
                        <input type="hidden" name="recipient_id" value="<?= (int)$report['reported_by'] ?>">
                        <h3>Notify <?= htmlspecialchars($bName) ?></h3>
                        <p>Neither notice says what was done about the other person.</p>
                        <?php foreach (['report_reviewing', 'report_closed'] as $i => $key): [$tt, $mm] = ReportService::noticeText($key, $report); ?>
                            <label class="nf-choice">
                                <input type="radio" name="notice" value="<?= $key ?>" <?= ($isOpen ? $i === 0 : $i === 1) ? 'checked' : '' ?> required>
                                <span class="nf-preview" style="margin:0;flex:1;"><b><?= htmlspecialchars($tt) ?></b><?= htmlspecialchars($mm) ?></span>
                            </label>
                        <?php endforeach; ?>
                        <div class="um-modal-foot">
                            <button type="button" class="um-cancel" onclick="umClose()">Cancel</button>
                            <button type="submit" class="um-btn um-ok">Send notice</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

        <?php elseif ($note): ?>
            <?php $mine = (int)$note['user_id'] === $me; ?>
            <div class="nf-d-hd">
                <span class="nf-ico <?= $mine ? 'foryou' : 'sent' ?>"><svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><?= $ICONS[$mine ? 'foryou' : 'sent'] ?></svg></span>
                <div>
                    <h2><?= htmlspecialchars((string)$note['title']) ?></h2>
                    <div class="nf-d-sub">
                        <?php if ($mine): ?>
                            <span class="nf-chip grey">For you</span>
                        <?php else: ?>
                            <span class="nf-chip <?= (int)$note['is_read'] ? 'green' : 'grey' ?>"><?= (int)$note['is_read'] ? 'Read' : 'Not read yet' ?></span>
                        <?php endif; ?>
                        <?= htmlspecialchars(nf_when($note['created_at'])) ?>
                    </div>
                </div>
            </div>
            <p class="nf-msg"><?= htmlspecialchars((string)$note['message']) ?></p>
            <?php if (!$mine): ?>
                <p class="nf-sec">Sent to</p>
                <div class="nf-person">
                    <span class="nf-av"><?= htmlspecialchars(nf_initials((string)$note['recipient_name'])) ?></span>
                    <span><b><?= htmlspecialchars($note['recipient_name'] ?: 'A member') ?></b><span><?= htmlspecialchars($role($note['recipient_role'])) ?> · <?= htmlspecialchars($NOTICE_KINDS[$note['type']] ?? 'Notice') ?><?= $note['email_status'] === 'sent' ? ' · also emailed' : '' ?></span></span>
                </div>
                <p class="nf-sec">Actions</p>
                <div class="nf-acts">
                    <a class="nf-act primary" href="<?= htmlspecialchars(url('admin-user') . '?id=' . (int)$note['user_id']) ?>">View their account</a>
                    <a class="nf-act" href="<?= htmlspecialchars($link(['to' => (int)$note['user_id']])) ?>#compose">Send them another notice</a>
                </div>
            <?php elseif (trim((string)$note['link']) !== ''): ?>
                <p class="nf-sec">Actions</p>
                <div class="nf-acts">
                    <a class="nf-act primary" href="<?= htmlspecialchars((string)$note['link']) ?>">Open</a>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <p class="nf-empty" style="padding:30px 10px;">Choose something on the left to see its details.</p>
        <?php endif; ?>
    </aside>
</div>

<!-- A notice in the admin's own words, to any mentor or mentee. -->
<div class="um-overlay" id="nfComposeModal">
    <form class="um-modal" method="post" action="<?= url('admin-action-notify') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="notice" value="custom">
        <h3>Send a notice</h3>
        <p>It appears in their notifications, and by email when email sending is on.</p>
        <label for="nfTo">To</label>
        <select id="nfTo" name="recipient_id" required>
            <option value="">Choose a mentor or mentee…</option>
            <?php foreach (['mentor' => 'Mentors', 'mentee' => 'Mentees'] as $r => $groupLabel): ?>
                <optgroup label="<?= $groupLabel ?>">
                    <?php foreach ($recipients as $p): if ($p['role'] !== $r) continue; ?>
                        <option value="<?= (int)$p['user_id'] ?>" <?= (int)$p['user_id'] === $preset_to ? 'selected' : '' ?>>
                            <?= htmlspecialchars(($p['name'] ?: 'Unnamed account') . ' — ' . ($p['email'] ?? '') . ($p['status'] !== 'active' ? ' (' . $p['status'] . ')' : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>
        <label for="nfTitle">Title</label>
        <input id="nfTitle" name="title" required maxlength="<?= NotificationService::ADMIN_TITLE_MAX ?>" placeholder="e.g. About your upcoming session">
        <label for="nfMessage">Message</label>
        <textarea id="nfMessage" name="message" rows="4" required maxlength="<?= NotificationService::ADMIN_MESSAGE_MAX ?>"></textarea>
        <p class="nf-note">Up to <?= number_format(NotificationService::ADMIN_MESSAGE_MAX) ?> characters.</p>
        <div class="um-modal-foot">
            <button type="button" class="um-cancel" onclick="umClose()">Cancel</button>
            <button type="submit" class="um-btn um-ok">Send notice</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/moderation_ui.php'; ?>

<script>
    // "Send them another notice" arrives with #compose and the person chosen.
    if (location.hash === '#compose') umOpen('nfComposeModal');
</script>

<?php include 'admin_footer.php'; ?>

<?php include __DIR__ . '/layout_end.php'; ?>
