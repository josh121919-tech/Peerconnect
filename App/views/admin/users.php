<?php

/**
 * admin/users.php — User Management.
 *
 * One page for everything an admin does to an account: browse and filter,
 * approve or reject a verification, restrict, block, unblock, and work the
 * report queue. The verification screen used to live on its own route; it is
 * the "New User" queue here now, because approving a signup is the first
 * thing you do to a user and it belongs with the rest.
 *
 * Every figure is a live count. The reference design showed per-row session
 * counts and ratings, which are real, and a "university" field, which this
 * app does not store — the course and club it does store are shown instead.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/moderation_helpers.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$me = (int)($_SESSION['user_id'] ?? 0);

/* ── Filters ──────────────────────────────────────────────────────────── */
$VIEWS = ['all', 'mentee', 'mentor', 'admin', 'pending', 'reported', 'restricted', 'blocked'];
$view  = in_array($_GET['tab'] ?? '', $VIEWS, true) ? $_GET['tab'] : 'all';

// The sidebar links here with ?role=; treat it as the matching tab.
if (in_array($_GET['role'] ?? '', ['mentee', 'mentor', 'admin'], true)) {
    $view = $_GET['role'];
}

$q      = trim((string)($_GET['q'] ?? ''));
$status = in_array($_GET['status'] ?? '', ['active', 'restricted', 'blocked', 'unverified'], true) ? $_GET['status'] : '';
$sort   = in_array($_GET['sort'] ?? '', ['newest', 'oldest', 'name', 'role'], true) ? $_GET['sort'] : 'newest';

$perPage = 8;
$page    = max(1, (int)($_GET['page'] ?? 1));

/* ── Headline counts ──────────────────────────────────────────────────── */
$one = function (string $sql) use ($con): int {
    $r = $con->query($sql);
    return $r ? (int)$r->fetch_row()[0] : 0;
};
$c_all     = $one("SELECT COUNT(*) FROM users");
$c_mentee  = $one("SELECT COUNT(*) FROM users WHERE role = 'mentee'");
$c_mentor  = $one("SELECT COUNT(*) FROM users WHERE role = 'mentor'");
$c_admin   = $one("SELECT COUNT(*) FROM users WHERE role = 'admin'");
$c_pending = $one("SELECT COUNT(*) FROM user_verifications WHERE status = 'pending'");
$c_report  = $one("SELECT COUNT(*) FROM reports WHERE status IN ('pending','urgent')");
$c_restr   = $one("SELECT COUNT(*) FROM users WHERE status = 'restricted'");
$c_block   = $one("SELECT COUNT(*) FROM users WHERE status = 'blocked'");

$mstart = "DATE_FORMAT(CURDATE(), '%Y-%m-01')";
$lstart = "DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')";
function um_trend(int $now, int $prev): ?array
{
    if ($prev <= 0) return null;
    $pct = (int)round((($now - $prev) / $prev) * 100);
    return $pct === 0 ? null : ['up' => $pct > 0, 'label' => ($pct > 0 ? '+' : '') . $pct . '% from last month'];
}
$trend = [];
foreach (['all' => '', 'mentee' => " AND role='mentee'", 'mentor' => " AND role='mentor'", 'admin' => " AND role='admin'"] as $k => $clause) {
    $trend[$k] = um_trend(
        $one("SELECT COUNT(*) FROM users WHERE created_at >= $mstart" . $clause),
        $one("SELECT COUNT(*) FROM users WHERE created_at >= $lstart AND created_at < $mstart" . $clause)
    );
}

/* ── The list ─────────────────────────────────────────────────────────────
 * Sessions and rating come from the same tables the mentor and mentee pages
 * use, so a number here always matches what that person sees.
 */
$rows = [];
$total = 0;

if (in_array($view, ['all', 'mentee', 'mentor', 'admin', 'restricted', 'blocked'], true)) {
    $clauses = [];
    $types   = '';
    $args    = [];

    if (in_array($view, ['mentee', 'mentor', 'admin'], true)) {
        $clauses[] = 'u.role = ?';
        $types .= 's';
        $args[] = $view;
    } elseif ($view === 'restricted' || $view === 'blocked') {
        $clauses[] = 'u.status = ?';
        $types .= 's';
        $args[] = $view;
    }

    if ($q !== '') {
        $clauses[] = "CONCAT_WS(' ', u.firstname, u.lastname, u.username, e.email) LIKE ?";
        $types .= 's';
        $args[] = '%' . $q . '%';
    }

    if ($status === 'unverified') {
        $clauses[] = 'u.verified = 0';
    } elseif ($status !== '') {
        $clauses[] = 'u.status = ?';
        $types .= 's';
        $args[] = $status;
    }

    $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
    $order = [
        'newest' => 'u.created_at DESC',
        'oldest' => 'u.created_at ASC',
        'name'   => 'u.firstname ASC, u.lastname ASC',
        'role'   => 'u.role ASC, u.created_at DESC',
    ][$sort];

    $cs = $con->prepare("SELECT COUNT(*) c FROM users u LEFT JOIN emails e ON e.user_id = u.user_id $where");
    if ($types !== '') $cs->bind_param($types, ...$args);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_assoc()['c'];
    $cs->close();

    $offset = ($page - 1) * $perPage;
    $ls = $con->prepare("
        SELECT u.user_id, u.firstname, u.lastname, u.role, u.status, u.verified, u.created_at,
               COALESCE(u.email, e.email) AS email,
               p.profile_image,
               -- Course and club are collected twice: once on the verification
               -- form, once on the member's own profile. The profile row wins
               -- because the member can keep it current, but it is blank for
               -- anyone who has only ever filled in the verification form, so
               -- fall back to what they actually submitted rather than showing
               -- a dash for a field they did fill in.
               --
               -- Subqueries rather than a join: user_verifications.user_id is
               -- only a plain index, so a second row for one user would
               -- otherwise duplicate them in this list and make it disagree
               -- with the count query above.
               COALESCE(NULLIF(p.course, ''), NULLIF((
                   SELECT vc.course FROM user_verifications vc
                    WHERE vc.user_id = u.user_id
                    ORDER BY vc.verification_id DESC LIMIT 1), '')) AS course,
               COALESCE(NULLIF(p.club, ''), NULLIF((
                   SELECT vb.club FROM user_verifications vb
                    WHERE vb.user_id = u.user_id
                    ORDER BY vb.verification_id DESC LIMIT 1), '')) AS club,
               (SELECT COUNT(*) FROM session_requests sr
                 WHERE sr.status = 'completed'
                   AND (sr.mentee_id = u.user_id OR sr.mentor_id = u.user_id)) AS sessions,
               (SELECT AVG(f.rating) FROM feedback f WHERE f.mentor_id = u.user_id)      AS rating_as_mentor,
               (SELECT AVG(m.rating) FROM mentee_reviews m WHERE m.mentee_id = u.user_id) AS rating_as_mentee
        FROM users u
        LEFT JOIN emails e  ON e.user_id = u.user_id
        LEFT JOIN profile p ON p.user_id = u.user_id
        $where
        ORDER BY $order
        LIMIT ? OFFSET ?
    ");
    $ls->bind_param($types . 'ii', ...array_merge($args, [$perPage, $offset]));
    $ls->execute();
    $rows = $ls->get_result()->fetch_all(MYSQLI_ASSOC);
    $ls->close();
}

/* Verification queue — the "New User" tab. */
$verif = [];
if ($view === 'pending') {
    $verif = $con->query("
        SELECT v.*, u.firstname, u.lastname, u.role, COALESCE(u.email, e.email) AS email, p.profile_image
        FROM user_verifications v
        JOIN users u        ON u.user_id = v.user_id
        LEFT JOIN emails e  ON e.user_id = u.user_id
        LEFT JOIN profile p ON p.user_id = u.user_id
        WHERE v.status = 'pending'
        ORDER BY v.submitted_at ASC
    ")->fetch_all(MYSQLI_ASSOC);
    $total = count($verif);
}

/* Report queue. */
$reports = [];
if ($view === 'reported') {
    $reports = $con->query("
        SELECT r.*,
               CONCAT(ru.firstname,' ',ru.lastname) AS reported_name, ru.role AS reported_role,
               ru.status AS reported_status, rp.profile_image,
               CONCAT(bu.firstname,' ',bu.lastname) AS reporter_name
        FROM reports r
        JOIN users ru        ON ru.user_id = r.reported_user_id
        LEFT JOIN users bu   ON bu.user_id = r.reported_by
        LEFT JOIN profile rp ON rp.user_id = r.reported_user_id
        WHERE r.status IN ('pending','urgent')
        ORDER BY r.created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
    $total = count($reports);
}

$totalPages = max(1, (int)ceil($total / $perPage));

/** Preserve the current filters when building a link. */
function um_url(array $over = []): string
{
    $base = [
        'tab' => $_GET['tab'] ?? null,
        'q' => $_GET['q'] ?? null,
        'status' => $_GET['status'] ?? null,
        'sort' => $_GET['sort'] ?? null,
        'page' => $_GET['page'] ?? null
    ];
    $p = array_filter(array_merge($base, $over), fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($p);
}

function um_ago(?string $when): string
{
    if (!$when) return '—';
    return date('M j, Y', strtotime($when));
}


// Handlers speak through pc_flash(), which the toast renderer picks up.
// The only thing still arriving in the URL is an ?error= code from a refused
// action, so translate that into the same queue rather than a second style
// of banner.
$err = $_GET['error'] ?? '';
if ($err === 'self') {
    pc_flash('error', 'You cannot apply that to your own account.');
} elseif ($err !== '') {
    pc_flash('error', 'That action needs a reason before it can be applied.');
}

$csrf = csrf_token();
$current_page = 'users';
include 'layout.php';
?>

<style>
    .um-hd {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }

    .um-hd h1 {
        font-size: 25px;
        font-weight: 700;
        color: var(--forest);
        letter-spacing: -.02em;
        margin: 0;
    }

    .um-hd p {
        margin: 3px 0 0;
        font-size: 13.5px;
        color: var(--gray-400);
    }

    .um-new {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        padding: 11px 18px;
        border-radius: 11px;
        background: var(--forest);
        color: #fff;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        border: none;
        cursor: pointer;
    }

    .um-new:hover {
        background: #16265C;
    }

    .um-new svg {
        width: 17px;
        height: 17px;
    }

    .um-new .um-new-n {
        min-width: 20px;
        padding: 1px 7px;
        border-radius: 99px;
        background: rgba(255, 255, 255, .22);
        font-size: 12px;
        font-weight: 700;
    }

    /* ── Stat cards ── */
    .um-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 18px;
    }

    .um-stat {
        display: flex;
        align-items: center;
        gap: 13px;
        padding: 15px 16px;
        border-radius: 14px;
        border: 1px solid transparent;
    }

    .um-stat-ico {
        flex: 0 0 44px;
        width: 44px;
        height: 44px;
        border-radius: 13px;
        background: #fff;
        display: grid;
        place-items: center;
    }

    .um-stat-ico svg {
        width: 21px;
        height: 21px;
    }

    .um-stat-k {
        font-size: 13px;
        color: var(--gray-500);
        font-weight: 500;
    }

    .um-stat-v {
        font-family: 'DM Serif Display', serif;
        font-size: 27px;
        line-height: 1.1;
        color: var(--gray-900);
    }

    .um-stat-t {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-top: 2px;
        font-size: 11.5px;
    }

    .um-stat-t svg {
        width: 11px;
        height: 11px;
    }

    .um-up {
        color: #17654B;
    }

    .um-down {
        color: #A6301F;
    }

    .um-flat {
        color: var(--gray-400);
    }

    /* ── Toolbar ── */
    .um-bar {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }

    .um-tabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .um-tab {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 9px 15px;
        border-radius: 10px;
        border: 1px solid var(--gray-200);
        background: #fff;
        color: var(--gray-600);
        font-size: 13.5px;
        font-weight: 500;
        text-decoration: none;
    }

    .um-tab:hover {
        border-color: var(--mint-soft);
        color: var(--forest);
    }

    .um-tab.active {
        background: var(--forest);
        border-color: var(--forest);
        color: #fff;
        font-weight: 600;
    }

    .um-tab b {
        padding: 1px 7px;
        border-radius: 99px;
        background: var(--gray-100);
        color: var(--gray-600);
        font-size: 11.5px;
        font-weight: 700;
    }

    .um-tab.active b {
        background: rgba(255, 255, 255, .22);
        color: #fff;
    }

    .um-tab.warn b {
        background: #FBE5E1;
        color: #A6301F;
    }

    .um-tab.active.warn b {
        background: rgba(255, 255, 255, .22);
        color: #fff;
    }

    .um-tools {
        display: flex;
        gap: 10px;
        margin-left: auto;
        flex-wrap: wrap;
    }

    .um-field {
        position: relative;
        display: inline-flex;
        align-items: center;
    }

    .um-field>svg:first-child {
        position: absolute;
        left: 12px;
        width: 15px;
        height: 15px;
        color: var(--gray-400);
        pointer-events: none;
    }

    .um-field input,
    .um-field select {
        height: 40px;
        padding: 0 14px 0 34px;
        border: 1px solid var(--gray-200);
        border-radius: 10px;
        background: #fff;
        font-family: inherit;
        font-size: 13.5px;
        color: var(--gray-700);
        outline: none;
    }

    .um-field select {
        appearance: none;
        -webkit-appearance: none;
        padding-right: 30px;
        cursor: pointer;
    }

    .um-field input:focus,
    .um-field select:focus {
        border-color: var(--mint);
        box-shadow: 0 0 0 3px rgba(0, 135, 207, .13);
    }

    /* ── Rows ── */
    .um-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .um-row {
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        background: #fff;
        border: 1px solid var(--gray-100);
        border-radius: 14px;
        padding: 14px 16px;
        box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
    }

    .um-av {
        flex: 0 0 46px;
        width: 46px;
        height: 46px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        background: var(--mint-faint);
        color: #00539B;
        font-weight: 700;
        font-size: 14px;
        overflow: hidden;
    }

    .um-av img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .um-id {
        flex: 1 1 220px;
        min-width: 0;
    }

    .um-name {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .um-name b {
        font-size: 15px;
        font-weight: 600;
        color: var(--gray-900);
    }

    .um-mail {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-top: 3px;
        font-size: 12.5px;
        color: var(--gray-400);
    }

    .um-mail svg {
        width: 13px;
        height: 13px;
        flex-shrink: 0;
    }

    .um-mail span {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .um-col {
        flex: 0 1 170px;
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 9px;
        border-left: 1px solid var(--gray-100);
        padding-left: 14px;
    }

    .um-col svg {
        width: 16px;
        height: 16px;
        color: var(--gray-300);
        flex-shrink: 0;
    }

    .um-col-t {
        font-size: 13px;
        color: var(--gray-800);
        font-weight: 500;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .um-col-s {
        font-size: 11.5px;
        color: var(--gray-400);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .um-chip {
        padding: 3px 10px;
        border-radius: 99px;
        font-size: 11.5px;
        font-weight: 600;
        white-space: nowrap;
    }

    .um-mentee {
        background: var(--mint-faint);
        color: #00539B;
    }

    .um-mentor {
        background: #E6F5EE;
        color: #17654B;
    }

    .um-admin {
        background: #EFEDFC;
        color: #4A3FB8;
    }

    .um-active {
        background: #E6F5EE;
        color: #17654B;
    }

    .um-restricted {
        background: #FBF0D4;
        color: #9A7100;
    }

    .um-blocked {
        background: #FBE5E1;
        color: #A6301F;
    }

    .um-unverified {
        background: var(--gray-100);
        color: var(--gray-500);
    }

    .um-actions {
        margin-left: auto;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }

    .um-view {
        padding: 9px 16px;
        border-radius: 10px;
        border: 1px solid var(--gray-200);
        background: #fff;
        color: var(--gray-700);
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
    }

    .um-view:hover {
        border-color: var(--mint);
        color: var(--mint-deep, #00539B);
    }

    .um-menu-wrap {
        position: relative;
    }

    .um-kebab {
        width: 34px;
        height: 34px;
        border: none;
        background: none;
        border-radius: 9px;
        color: var(--gray-400);
        cursor: pointer;
        display: grid;
        place-items: center;
    }

    .um-kebab:hover {
        background: var(--gray-50);
        color: var(--gray-700);
    }

    .um-kebab svg {
        width: 17px;
        height: 17px;
    }

    .um-menu {
        display: none;
        position: absolute;
        right: 0;
        top: 38px;
        z-index: 60;
        min-width: 190px;
        padding: 6px;
        background: #fff;
        border: 1px solid var(--gray-200);
        border-radius: 12px;
        box-shadow: 0 12px 32px -12px rgba(16, 24, 40, .3);
    }

    .um-menu-wrap.open .um-menu {
        display: block;
    }

    .um-menu button,
    .um-menu a {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 9px 10px;
        border: none;
        background: none;
        border-radius: 8px;
        font-family: inherit;
        font-size: 13px;
        color: var(--gray-700);
        text-align: left;
        text-decoration: none;
        cursor: pointer;
    }

    .um-menu button:hover,
    .um-menu a:hover {
        background: var(--gray-50);
    }

    .um-menu .danger {
        color: #A6301F;
    }

    .um-menu .danger:hover {
        background: #FBE5E1;
    }

    .um-menu svg {
        width: 15px;
        height: 15px;
        flex-shrink: 0;
    }

    .um-menu hr {
        border: none;
        border-top: 1px solid var(--gray-100);
        margin: 5px 0;
    }

    /* ── Verification cards ── */
    .um-verif {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .um-vcard {
        background: #fff;
        border: 1px solid var(--gray-100);
        border-radius: 14px;
        padding: 16px 18px;
        box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
    }

    .um-vhead {
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
    }

    .um-vgrid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 12px 18px;
        margin: 14px 0;
        padding: 13px 0;
        border-top: 1px solid var(--gray-100);
        border-bottom: 1px solid var(--gray-100);
    }

    .um-vk {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: var(--gray-400);
        font-weight: 600;
    }

    .um-vv {
        font-size: 13.5px;
        color: var(--gray-800);
        margin-top: 2px;
        word-break: break-word;
    }

    /* Document tiles, the full-size viewer and the shared buttons live in
       includes/moderation_ui.php, which both this page and the single-user
       view include. */

    /* ── Report cards ── */
    .um-rcard {
        background: #fff;
        border: 1px solid var(--gray-100);
        border-left: 3px solid #A6301F;
        border-radius: 14px;
        padding: 16px 18px;
    }

    .um-rmeta {
        font-size: 12.5px;
        color: var(--gray-500);
        margin: 8px 0 0;
        line-height: 1.6;
    }

    .um-rmeta b {
        color: var(--gray-800);
    }

    /* ── Empty + pagination ── */
    .um-empty {
        background: #fff;
        border: 1px dashed var(--gray-200);
        border-radius: 14px;
        padding: 54px 20px;
        text-align: center;
        color: var(--gray-400);
    }

    .um-empty svg {
        width: 34px;
        height: 34px;
        color: var(--gray-300);
        margin-bottom: 10px;
    }

    .um-empty p {
        margin: 0;
        font-size: 14px;
    }

    .um-foot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
        margin-top: 16px;
    }

    .um-count {
        font-size: 13px;
        color: var(--gray-500);
    }

    .um-pages {
        display: flex;
        gap: 6px;
    }

    .um-pages a,
    .um-pages span {
        min-width: 36px;
        height: 36px;
        padding: 0 10px;
        border-radius: 9px;
        border: 1px solid var(--gray-200);
        background: #fff;
        color: var(--gray-600);
        font-size: 13px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }

    .um-pages a:hover {
        border-color: var(--mint);
        color: var(--mint-deep, #00539B);
    }

    .um-pages .on {
        background: var(--forest);
        border-color: var(--forest);
        color: #fff;
        font-weight: 600;
    }

    .um-pages .off {
        opacity: .45;
    }

    /* ── Flash ── */

    /* ── Modal ── */

    @media (max-width: 1100px) {
        .um-col {
            display: none;
        }

        .um-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 620px) {
        .um-tools {
            margin-left: 0;
            width: 100%;
        }

        .um-field,
        .um-field input,
        .um-field select {
            width: 100%;
        }

        .um-actions {
            width: 100%;
            justify-content: flex-end;
        }

        .um-stats {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="um-hd">
    <div>
        <h1>User Management</h1>
        <p>View and manage all users in the PeerConnect platform.</p>
    </div>
    <a class="um-new" href="<?= um_url(['tab' => 'pending', 'page' => null]) ?>">

        Verify User
        <?php if ($c_pending > 0): ?><span class="um-new-n"><?= $c_pending ?></span><?php endif; ?>
    </a>
</div>

<!-- ══════════ Stats ══════════ -->
<div class="um-stats">
    <?php
    $arrow = '<svg fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5m0 0-6 6m6-6 6 6"/></svg>';
    $statCards = [
        [
            'Total Users',
            $c_all,
            $trend['all'],
            '#EAF2FE',
            '#CBDFF8',
            '#1B6FD1',
            '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m9-4a4 4 0 11-8 0 4 4 0 018 0z"/>'
        ],
        [
            'Mentees',
            $c_mentee,
            $trend['mentee'],
            '#E6F5EE',
            '#BFE2D1',
            '#17654B',
            '<path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/>'
        ],
        [
            'Mentors',
            $c_mentor,
            $trend['mentor'],
            '#EFEDFC',
            '#D6D0F5',
            '#4A3FB8',
            '<path stroke-linecap="round" stroke-linejoin="round" d="m12 4 9 5-9 5-9-5 9-5Z"/><path stroke-linecap="round" d="M7 11.5V16c0 1.4 2.2 2.5 5 2.5s5-1.1 5-2.5v-4.5"/>'
        ],
        [
            'Admins',
            $c_admin,
            $trend['admin'],
            '#FBF0D4',
            '#F0DDA4',
            '#9A7100',
            '<rect x="3.5" y="7" width="17" height="12" rx="2.5"/><path stroke-linecap="round" d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7"/>'
        ],
    ];
    foreach ($statCards as [$label, $value, $t, $bg, $bd, $fg, $path]): ?>
        <div class="um-stat" style="background:<?= $bg ?>;border-color:<?= $bd ?>;">
            <span class="um-stat-ico" style="color:<?= $fg ?>;">
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><?= $path ?></svg>
            </span>
            <div>
                <div class="um-stat-k"><?= $label ?></div>
                <div class="um-stat-v"><?= number_format($value) ?></div>
                <?php if ($t): ?>
                    <span class="um-stat-t <?= $t['up'] ? 'um-up' : 'um-down' ?>">
                        <span style="display:inline-flex;<?= $t['up'] ? '' : 'transform:rotate(180deg);' ?>"><?= $arrow ?></span><?= htmlspecialchars($t['label']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ══════════ Toolbar ══════════ -->
<div class="um-bar">
    <div class="um-tabs">
        <?php
        $tabs = [
            ['all', 'All Users', $c_all, false],
            ['mentee', 'Mentees', $c_mentee, false],
            ['mentor', 'Mentors', $c_mentor, false],
            ['admin', 'Admins', $c_admin, false],
            ['pending', 'Waiting for Verification', $c_pending, true],
            ['reported', 'Reported', $c_report, true],
            ['restricted', 'Restricted', $c_restr, false],
            ['blocked', 'Blocked', $c_block, false],
        ];
        foreach ($tabs as [$k, $label, $n, $warn]): ?>
            <a class="um-tab <?= $view === $k ? 'active' : '' ?> <?= $warn && $n > 0 ? 'warn' : '' ?>"
                href="<?= um_url(['tab' => $k, 'page' => null, 'status' => null]) ?>">
                <?= $label ?><b><?= $n ?></b>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (in_array($view, ['all', 'mentee', 'mentor', 'admin', 'restricted', 'blocked'], true)): ?>
        <form class="um-tools" method="get">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($view) ?>">
            <label class="um-field">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <circle cx="11" cy="11" r="7" />
                    <path stroke-linecap="round" d="m20 20-4-4" />
                </svg>
                <span class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Search users</span>
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search users…" style="width:190px;">
            </label>
            <label class="um-field">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 5h16l-6 7v6l-4 2v-8L4 5Z" />
                </svg>
                <span class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Filter by status</span>
                <select name="status" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="restricted" <?= $status === 'restricted' ? 'selected' : '' ?>>Restricted</option>
                    <option value="blocked" <?= $status === 'blocked' ? 'selected' : '' ?>>Blocked</option>
                    <option value="unverified" <?= $status === 'unverified' ? 'selected' : '' ?>>Unverified</option>
                </select>
            </label>
            <label class="um-field">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 4v16m0 0-3-3m3 3 3-3M17 20V4m0 0-3 3m3-3 3 3" />
                </svg>
                <span class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Sort</span>
                <select name="sort" onchange="this.form.submit()">
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
                    <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
                    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name A–Z</option>
                    <option value="role" <?= $sort === 'role' ? 'selected' : '' ?>>Role</option>
                </select>
            </label>
        </form>
    <?php endif; ?>
</div>

<!-- ══════════ Body ══════════ -->
<?php if ($view === 'pending'): ?>

    <div class="um-verif">
        <?php if (!$verif): ?>
            <div class="um-empty">
                <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p>No new users waiting for review.</p>
            </div>
            <?php else: foreach ($verif as $v):
                $nm = trim($v['firstname'] . ' ' . $v['lastname']); ?>
                <div class="um-vcard">
                    <div class="um-vhead">
                        <span class="um-av">
                            <?php if (!empty($v['profile_image'])): ?><img src="<?= htmlspecialchars($v['profile_image']) ?>" alt="">
                                <?php else: ?><?= htmlspecialchars(strtoupper(substr($v['firstname'], 0, 1) . substr($v['lastname'], 0, 1))) ?><?php endif; ?>
                        </span>
                        <div style="flex:1;min-width:0;">
                            <div class="um-name">
                                <b><?= htmlspecialchars($v['full_name'] ?: $nm) ?></b>
                                <span class="um-chip um-<?= htmlspecialchars($v['role'] ?: 'mentee') ?>"><?= htmlspecialchars(ucfirst($v['role'] ?: 'mentee')) ?></span>
                            </div>
                            <div class="um-mail">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                                    <path stroke-linecap="round" d="m4 7 8 6 8-6" />
                                </svg>
                                <span><?= htmlspecialchars($v['email'] ?? '—') ?></span>
                            </div>
                        </div>
                        <span style="font-size:12.5px;color:var(--gray-400);">Submitted <?= um_ago($v['submitted_at']) ?></span>
                    </div>

                    <div class="um-vgrid">
                        <?php foreach (
                            [
                                'Student ID' => $v['student_id'],
                                'Course' => $v['course'],
                                'Year level' => $v['year_level'],
                                'Club' => $v['club'],
                                'Expertise'  => $v['expertise'],
                            ] as $k => $val
                        ): ?>
                            <?php if (trim((string)$val) !== ''): ?>
                                <div>
                                    <div class="um-vk"><?= $k ?></div>
                                    <div class="um-vv"><?= htmlspecialchars($val) ?></div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($v['id_image']) || !empty($v['credential_image'])): ?>
                        <div class="um-vdocs">
                            <?php foreach (['id_image' => 'School ID', 'credential_image' => 'Credential'] as $col => $lbl): ?>
                                <?php um_doc_tile($v[$col] ?? '', $lbl, $v['full_name'] ?: $nm); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="um-vact">
                        <form method="post" action="<?= url('admin-action-verify') ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="verification_id" value="<?= (int)$v['verification_id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="um-btn um-ok">Approve</button>
                        </form>
                        <button type="button" class="um-btn um-no"
                            onclick="umReject(<?= (int)$v['verification_id'] ?>, <?= htmlspecialchars(json_encode($v['full_name'] ?: $nm), ENT_QUOTES) ?>)">Reject</button>
                    </div>
                </div>
        <?php endforeach;
        endif; ?>
    </div>

<?php elseif ($view === 'reported'): ?>

    <div class="um-verif">
        <?php if (!$reports): ?>
            <div class="um-empty">
                <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 4h.01M5.07 19H19a2 2 0 001.73-3L13.73 4a2 2 0 00-3.46 0L3.27 16A2 2 0 005.07 19z" />
                </svg>
                <p>No open reports.</p>
            </div>
            <?php else: foreach ($reports as $r): ?>
                <div class="um-rcard">
                    <div class="um-vhead">
                        <span class="um-av">
                            <?php if (!empty($r['profile_image'])): ?><img src="<?= htmlspecialchars($r['profile_image']) ?>" alt="">
                                <?php else: ?><?= htmlspecialchars(strtoupper(substr($r['reported_name'], 0, 2))) ?><?php endif; ?>
                        </span>
                        <div style="flex:1;min-width:0;">
                            <div class="um-name">
                                <b><?= htmlspecialchars($r['reported_name']) ?></b>
                                <span class="um-chip um-<?= htmlspecialchars($r['reported_role'] ?: 'mentee') ?>"><?= htmlspecialchars(ucfirst($r['reported_role'] ?: '—')) ?></span>
                                <span class="um-chip um-<?= htmlspecialchars($r['reported_status']) ?>"><?= htmlspecialchars(ucfirst($r['reported_status'])) ?></span>
                            </div>
                            <p class="um-rmeta">
                                <b><?= htmlspecialchars($r['issue_type']) ?></b> &middot;
                                reported by <?= htmlspecialchars($r['reporter_name'] ?? 'someone') ?>
                                on <?= um_ago($r['created_at']) ?><br>
                                <?= nl2br(htmlspecialchars($r['description'] ?? '')) ?>
                            </p>
                        </div>
                    </div>
                    <div class="um-vact" style="margin-top:14px;">
                        <button type="button" class="um-btn um-no"
                            onclick="umBlock(0, <?= (int)$r['report_id'] ?>, <?= htmlspecialchars(json_encode($r['reported_name']), ENT_QUOTES) ?>)">Block user</button>
                        <button type="button" class="um-btn" style="background:#FBF0D4;color:#9A7100;"
                            onclick="umRestrict(0, <?= (int)$r['report_id'] ?>, <?= htmlspecialchars(json_encode($r['reported_name']), ENT_QUOTES) ?>)">Restrict</button>
                        <form method="post" action="<?= url('admin-action-resolve') ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="report_id" value="<?= (int)$r['report_id'] ?>">
                            <button type="submit" class="um-btn" style="background:#E6F5EE;color:#17654B;">Dismiss report</button>
                        </form>
                    </div>
                </div>
        <?php endforeach;
        endif; ?>
    </div>

<?php else: ?>

    <div class="um-list">
        <?php if (!$rows): ?>
            <div class="um-empty">
                <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                    <circle cx="12" cy="8" r="3.6" />
                    <path stroke-linecap="round" d="M5 20c.7-3.5 3.5-5.4 7-5.4s6.3 1.9 7 5.4" />
                </svg>
                <p>No users match these filters.</p>
            </div>
            <?php else: foreach ($rows as $u):
                $uid    = (int)$u['user_id'];
                $nm     = trim($u['firstname'] . ' ' . $u['lastname']);
                $role   = $u['role'] ?: 'member';
                $ustat  = $u['status'];
                $isMe   = $uid === $me;
                $rating = $role === 'mentor' ? $u['rating_as_mentor'] : $u['rating_as_mentee'];
                $rlabel = $role === 'mentor' ? 'from mentees' : 'from mentors';
            ?>
                <div class="um-row">
                    <span class="um-av">
                        <?php if (!empty($u['profile_image'])): ?><img src="<?= htmlspecialchars($u['profile_image']) ?>" alt="">
                            <?php else: ?><?= htmlspecialchars(strtoupper(substr($u['firstname'], 0, 1) . substr($u['lastname'], 0, 1))) ?><?php endif; ?>
                    </span>

                    <div class="um-id">
                        <div class="um-name">
                            <b><?= htmlspecialchars($nm) ?></b>
                            <span class="um-chip um-<?= htmlspecialchars($role) ?>"><?= htmlspecialchars(ucfirst($role)) ?></span>
                            <span class="um-chip um-<?= htmlspecialchars($ustat) ?>"><?= htmlspecialchars(ucfirst($ustat)) ?></span>
                            <?php if (!$u['verified']): ?><span class="um-chip um-unverified">Unverified</span><?php endif; ?>
                            <?php if ($isMe): ?><span class="um-chip um-unverified">You</span><?php endif; ?>
                        </div>
                        <div class="um-mail">
                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <rect x="3" y="5.5" width="18" height="13" rx="2.5" />
                                <path stroke-linecap="round" d="m4 7 8 6 8-6" />
                            </svg>
                            <span><?= htmlspecialchars($u['email'] ?? '—') ?></span>
                            <span style="color:var(--gray-300);">&middot;</span>
                            <span>Joined <?= um_ago($u['created_at']) ?></span>
                        </div>
                    </div>

                    <div class="um-col">
                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m12 4 9 5-9 5-9-5 9-5Z" />
                            <path stroke-linecap="round" d="M7 11.5V16c0 1.4 2.2 2.5 5 2.5s5-1.1 5-2.5v-4.5" />
                        </svg>
                        <div style="min-width:0;">
                            <div class="um-col-t"><?= htmlspecialchars($u['course'] ?: '—') ?></div>
                            <div class="um-col-s"><?= htmlspecialchars($u['club'] ?: 'No club listed') ?></div>
                        </div>
                    </div>

                    <div class="um-col" style="flex-basis:120px;">
                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" d="M12 7.5V12l3 2" />
                        </svg>
                        <div style="min-width:0;">
                            <div class="um-col-t"><?= (int)$u['sessions'] ?></div>
                            <div class="um-col-s">Session<?= (int)$u['sessions'] === 1 ? '' : 's' ?> completed</div>
                        </div>
                    </div>

                    <div class="um-col" style="flex-basis:130px;">
                        <svg fill="none" stroke="#E5A800" stroke-width="1.6" viewBox="0 0 24 24" style="color:#E5A800;">
                            <path fill="#E5A800" d="m12 3.6 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9L12 3.6Z" />
                        </svg>
                        <div style="min-width:0;">
                            <div class="um-col-t"><?= $rating !== null ? number_format((float)$rating, 1) : '—' ?></div>
                            <div class="um-col-s"><?= $rating !== null ? 'Rating ' . $rlabel : 'No ratings yet' ?></div>
                        </div>
                    </div>

                    <div class="um-actions">
                        <?php // Goes to the admin's own view of the account, which exists for
                        //     every role. Mentors additionally have a public profile page,
                        //     linked from there. 
                        ?>
                        <a class="um-view" href="<?= htmlspecialchars(url('admin-user') . '?id=' . $uid) ?>">View Profile</a>

                        <div class="um-menu-wrap">
                            <button type="button" class="um-kebab" onclick="umMenu(this)" aria-haspopup="true" aria-expanded="false"
                                aria-label="Actions for <?= htmlspecialchars($nm) ?>">
                                <svg fill="currentColor" viewBox="0 0 24 24">
                                    <circle cx="12" cy="5" r="1.7" />
                                    <circle cx="12" cy="12" r="1.7" />
                                    <circle cx="12" cy="19" r="1.7" />
                                </svg>
                            </button>
                            <div class="um-menu" role="menu">
                                <?php if ($isMe): ?>
                                    <button type="button" disabled style="opacity:.5;cursor:default;">This is your account</button>
                                <?php else: ?>
                                    <?php if ($ustat === 'blocked'): ?>
                                        <form method="post" action="<?= url('admin-action-unblock') ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <button type="submit" role="menuitem">
                                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                    <rect x="4.5" y="11" width="15" height="9" rx="2" />
                                                    <path stroke-linecap="round" d="M8 11V7.5a4 4 0 0 1 7.7-1.5" />
                                                </svg>
                                                Unblock account
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" role="menuitem"
                                            onclick="umRestrict(<?= $uid ?>, 0, <?= htmlspecialchars(json_encode($nm), ENT_QUOTES) ?>)">
                                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <circle cx="12" cy="12" r="9" />
                                                <path stroke-linecap="round" d="M12 7.5V12l3 2" />
                                            </svg>
                                            <?= $ustat === 'restricted' ? 'Change restriction' : 'Restrict for a period' ?>
                                        </button>
                                        <button type="button" role="menuitem" class="danger"
                                            onclick="umBlock(<?= $uid ?>, 0, <?= htmlspecialchars(json_encode($nm), ENT_QUOTES) ?>)">
                                            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <circle cx="12" cy="12" r="9" />
                                                <path stroke-linecap="round" d="m6.5 6.5 11 11" />
                                            </svg>
                                            Block account
                                        </button>
                                    <?php endif; ?>
                                    <hr>
                                <?php endif; ?>
                                <a role="menuitem" href="<?= htmlspecialchars(url('messages') . '?chat=' . $uid) ?>">
                                    <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 12a7 7 0 0 1-7 7H8.5L5 21.5V18A7 7 0 0 1 12 5h1a7 7 0 0 1 7 7Z" />
                                    </svg>
                                    Message
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
        <?php endforeach;
        endif; ?>
    </div>

    <div class="um-foot">
        <span class="um-count">
            <?php if ($total === 0): ?>No users<?php else: ?>
            Showing <?= (($page - 1) * $perPage) + 1 ?>&ndash;<?= min($page * $perPage, $total) ?> of <?= $total ?> user<?= $total === 1 ? '' : 's' ?>
        <?php endif; ?>
        </span>
        <?php if ($totalPages > 1): ?>
            <div class="um-pages">
                <?php if ($page > 1): ?><a href="<?= um_url(['page' => $page - 1]) ?>">&lsaquo;</a><?php else: ?><span class="off">&lsaquo;</span><?php endif; ?>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i === $page): ?><span class="on"><?= $i ?></span>
                    <?php else: ?><a href="<?= um_url(['page' => $i]) ?>"><?= $i ?></a><?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?><a href="<?= um_url(['page' => $page + 1]) ?>">&rsaquo;</a><?php else: ?><span class="off">&rsaquo;</span><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/includes/moderation_ui.php'; ?>

<script>
    function umMenu(btn) {
        const wrap = btn.closest('.um-menu-wrap');
        const open = !wrap.classList.contains('open');
        document.querySelectorAll('.um-menu-wrap.open').forEach(w => {
            w.classList.remove('open');
            w.querySelector('.um-kebab').setAttribute('aria-expanded', 'false');
        });
        wrap.classList.toggle('open', open);
        btn.setAttribute('aria-expanded', String(open));
    }
    document.addEventListener('click', e => {
        if (!e.target.closest('.um-menu-wrap')) {
            document.querySelectorAll('.um-menu-wrap.open').forEach(w => {
                w.classList.remove('open');
                w.querySelector('.um-kebab').setAttribute('aria-expanded', 'false');
            });
        }
    });
</script>

<?php include 'admin_footer.php'; ?>