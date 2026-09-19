<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// Only set $current_page from filename if the including page hasn't already defined it.
// Pages like badges.php set $current_page = 'admin-badges' BEFORE
// including layout.php so their sidebar link is highlighted correctly.
if (empty($current_page)) {
    $current_page = basename($_SERVER['PHP_SELF'], '.php');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NEUST Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <?php
    // One cacheable file rather than ~57 KB inlined into every admin page.
    $adm_css = asset('css/pc-admin.css');
    $adm_ver = @filemtime(PUBLIC_PATH . '/css/pc-admin.css');
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($adm_css . ($adm_ver ? '?v=' . $adm_ver : '')) ?>">

    <?php
    /*
     * Brand colours from System Settings → Appearance.
     *
     * The admin shell keeps its own copy of the design tokens (see the note at
     * the top of this file), so the override has to be emitted here as well as
     * in design_system.php — otherwise the member pages would recolour and the
     * admin panel would not. Emitted after the block above so it wins on
     * cascade order rather than by !important.
     */
    require_once __DIR__ . '/../includes/settings_store.php';
    $adm_brand   = pc_settings($con);
    $adm_def     = pc_setting_defaults();
    $adm_primary = preg_match('/^#[0-9a-fA-F]{6}$/', $adm_brand['brand_primary'] ?? '') ? $adm_brand['brand_primary'] : $adm_def['brand_primary'];
    $adm_accent  = preg_match('/^#[0-9a-fA-F]{6}$/', $adm_brand['brand_accent'] ?? '')  ? $adm_brand['brand_accent']  : $adm_def['brand_accent'];

    if ($adm_primary !== $adm_def['brand_primary'] || $adm_accent !== $adm_def['brand_accent']) {
        // The rail is a hand-written gradient rather than a token, so it has
        // to be restated for the sidebar to follow the brand too.
        echo '<style>:root{--forest:' . $adm_primary . ';--ink:' . $adm_primary . ';--navy:' . $adm_primary
           . ';--mint:' . $adm_accent . ';--accent:' . $adm_accent . ';--accent-2:' . $adm_accent
           . ';--forest-2:' . $adm_accent . ';}'
           . '#sidebar{background:linear-gradient(178deg,' . $adm_primary . ' 0%,' . $adm_primary . ' 62%,' . $adm_accent . ' 240%)!important;}'
           . '</style>';
    }
    if (($adm_brand['brand_favicon'] ?? '') !== '') {
        echo '<link rel="icon" href="' . htmlspecialchars($adm_brand['brand_favicon']) . '">';
    }
    ?>
</head>

<body class="flex h-screen overflow-hidden">

    <!-- SIDEBAR -->
    <aside id="sidebar" class="bg-white border-r border-gray-100 flex flex-col py-5 gap-1 overflow-hidden">
        <div id="sidebar-header" class="flex items-center justify-between mb-3">
            <a class="adm-brand" href="<?= url('admin-dashboard') ?>">
                <?php if (($adm_brand['brand_logo'] ?? '') !== ''): ?>
                    <img class="adm-brand-logo" src="<?= htmlspecialchars($adm_brand['brand_logo']) ?>" alt="" aria-hidden="true">
                <?php else: ?>
                    <svg viewBox="0 0 48 48" fill="none" aria-hidden="true">
                        <circle cx="24" cy="24" r="21" fill="#fff" opacity=".12" />
                        <circle cx="24" cy="24" r="12.5" stroke="#fff" stroke-width="2.6" />
                        <path d="M18 22.4c0-2.2 1.8-4 4-4 1.8 0 2.9.8 3.2 2 .3-1.2 1.4-2 3.2-2 2.2 0 4 1.8 4 4 0 3.6-5 6-7.2 7-2.2-1-7.2-3.4-7.2-7Z" stroke="#8FC4F5" stroke-width="2" stroke-linejoin="round" />
                    </svg>
                <?php endif; ?>
                <span class="min-w-0">
                    <span class="adm-brand-name">PEER<em>CONNECT</em></span><br>
                    <span class="adm-brand-tag">Mentoring. Growing. Together.</span>
                </span>
            </a>
            <button id="sidebar-toggle" onclick="toggleSidebar()" type="button" aria-label="Collapse sidebar">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
        </div>

        <?php
        /*
         * Grouped like the reference design. Every entry is a page that
         * exists — the reference also showed Sessions, Assessments, Messages
         * and Settings, which have no admin screen behind them, and a nav
         * item that goes nowhere is worse than one that is absent.
         */
        $adm_ico = [
            'home'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>',
            'users' => '<path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m9-4a4 4 0 11-8 0 4 4 0 018 0z"/>',
            'check' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            'cal'   => '<rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path stroke-linecap="round" d="M8 3v4M16 3v4M3.5 10h17"/>',
            'quiz'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h4m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.6a1 1 0 0 1 .7.3l5.4 5.4a1 1 0 0 1 .3.7V19a2 2 0 0 1-2 2Z"/>',
            'mega'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M11 5.9 5 9H3a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h2l6 3.1V5.9ZM16 9a4 4 0 0 1 0 6M19 6.5a8 8 0 0 1 0 11"/>',
            'cog'   => '<circle cx="12" cy="12" r="3.2"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 9 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 9a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1Z"/>',
            'bell'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9"/>',
            'list'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>',
            'badge' => '<circle cx="12" cy="9" r="5.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 13.5-1 7 4.5-2.4 4.5 2.4-1-7"/>',
            'cert'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
            'chart' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
        ];
        $adm_nav = [
            ''         => [['admin-dashboard', 'index', 'home', 'Dashboard']],
            /*
             * Verification is a tab of User Management, not a page of its own,
             * so it sits with the other views of that page rather than beside
             * it. The keys are the query string each view is reached by, which
             * is also what decides which one is highlighted.
             */
            'Users'    => [
                ['admin-users', 'users', 'users', 'All Users', [
                    ''             => 'Everyone',
                    '?role=mentee' => 'Mentees',
                    '?role=mentor' => 'Mentors',
                    '?role=admin'  => 'Admins',
                    '?tab=pending' => 'Verification',
                ]],
            ],
            /*
             * Sessions is three views of one set of rows, so it groups the
             * same way Users does. It replaced Categories in this nav: the
             * category list fed nothing on the member side, while sessions
             * are the thing the platform is actually for.
             */
            'Sessions' => [
                ['admin-sessions', 'sessions', 'cal', 'Sessions', [
                    ''                        => 'All sessions',
                    '@admin-sessions-calendar' => 'Calendar view',
                    '@admin-sessions-reports'  => 'Session reports',
                ]],
            ],
            'Assessments' => [
                ['admin-assessments', 'assessments', 'quiz', 'Assessments', [
                    ''                                 => 'All assessments',
                    '@admin-assessments-results'       => 'Results & analytics',
                    '@admin-assessments-questions'     => 'Question bank',
                ]],
            ],
            'Content'  => [
                ['admin-announcements', 'announcements',     'mega',  'Announcements'],
                ['admin-badges',       'admin-badges',       'badge', 'Badges'],
                ['admin-certificates', 'admin-certificates', 'cert',  'Certificates'],
            ],
            'Reports'  => [
                ['admin-reports', 'reports', 'chart', 'Reports & Analytics', [
                    ''                   => 'Overall summary',
                    '@admin-analytics'   => 'Platform analytics',
                ]],
            ],
            'System'   => [
                ['admin-settings', 'settings', 'cog', 'System Settings', [
                    ''                              => 'General',
                    '@admin-settings-security'      => 'Security',
                    '@admin-settings-email'         => 'Email & Notifications',
                    '@admin-settings-appearance'    => 'Appearance',
                    '@admin-settings-integrations'  => 'Integrations',
                    '@admin-settings-backup'        => 'Backup & Restore',
                    '@admin-settings-logs'          => 'Activity Logs',
                ]],
                // Reports, the notices admins send members, and anything
                // addressed to the admin.
                ['admin-notifications', 'notifications', 'bell', 'Notifications'],
            ],
        ];
        ?>
        <nav class="flex flex-col gap-1 px-2 flex-1 overflow-y-auto">
            <?php foreach ($adm_nav as $group => $items): ?>
                <?php if ($group !== ''): ?>
                    <p class="menu-label sidebar-group"><?= htmlspecialchars($group) ?></p>
                <?php endif; ?>
                <?php foreach ($items as $item):
                    [$route, $key, $icon, $label] = $item;
                    $subs  = $item[4] ?? null;
                    $query = $item[5] ?? '';
                    /*
                     * A group's children are keyed by how they are reached: a
                     * query string on the group's own page ('?tab=pending'), or
                     * '@route-name' for a child that is a page of its own. Both
                     * shapes appear — Users is one page under several filters,
                     * Sessions is three pages — so the match handles both.
                     */
                    $subRoutes = [];
                    foreach (($subs ?? []) as $sq => $_l) {
                        if ($sq !== '' && $sq[0] === '@') $subRoutes[] = substr($sq, 1);
                    }
                    // Pages of this group, by the $current_page each one sets.
                    $groupPages = array_merge([$key], array_map(
                        fn($r) => str_replace('admin-', '', $r), $subRoutes
                    ));
                    $onPage = $query !== ''
                        ? ($current_page === 'users' && ('?' . http_build_query($_GET)) === $query)
                        : in_array($current_page, $groupPages, true);
                    $activeRole = $onPage ? ($_GET['role'] ?? '') : '';
                ?>
                    <?php if ($subs): ?>
                        <?php
                        // Which child is showing: its own page, or the query the
                        // group's page was opened with.
                        $activeSub = null;
                        if ($onPage) {
                            if ($current_page !== $key) {
                                foreach ($subs as $sq => $_l) {
                                    if ($sq !== '' && $sq[0] === '@'
                                        && str_replace('admin-', '', substr($sq, 1)) === $current_page) {
                                        $activeSub = $sq;
                                        break;
                                    }
                                }
                            } else {
                                // Prefixed because layout.php is included
                                // partway down each admin page, so a bare
                                // $role here overwrites the one the caller is
                                // already holding for the account on screen.
                                // user_view.php lost its role chip and its
                                // Public profile link to exactly that.
                                // A value sent as a list counts as none.
                                $adm_nav_role = is_string($_GET['role'] ?? null) ? $_GET['role'] : '';
                                $adm_nav_tab  = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : '';
                                if ($adm_nav_role !== '')      $activeSub = '?role=' . $adm_nav_role;
                                elseif ($adm_nav_tab !== '')   $activeSub = '?tab=' . $adm_nav_tab;
                                else                   $activeSub = '';
                                // A view with no entry of its own (Reported,
                                // Blocked…) still belongs to this section, but
                                // highlights none of the children rather than
                                // the wrong one.
                                if (!array_key_exists($activeSub, $subs)) $activeSub = null;
                            }
                        }
                        // Open when you are on this page, so the view you are using
                        // is visible rather than hidden behind a click.
                        ?>
                        <details<?= $onPage ? ' open' : '' ?>>
                            <?php /* The parent is a section heading, not a destination: when one
                                   of its children is selected, the child carries the highlight
                                   and the parent takes the quieter "you are in here" treatment,
                                   so two things are not lit at once. Collapsed, the children are
                                   hidden, so it takes the highlight itself. */ ?>
                            <summary class="sidebar-link<?= $onPage ? ' in-section' : '' ?>">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><?= $adm_ico[$icon] ?></svg>
                                <span class="menu-label"><?= htmlspecialchars($label) ?></span>
                                <svg class="sidebar-caret menu-label" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" />
                                </svg>
                            </summary>
                            <?php foreach ($subs as $sq => $sl):
                                $href = ($sq !== '' && $sq[0] === '@') ? url(substr($sq, 1)) : url($route) . $sq; ?>
                                <a href="<?= $href ?>" class="sidebar-link sidebar-sub menu-label <?= $activeSub === $sq ? 'active' : '' ?>">
                                    <i></i><?= htmlspecialchars($sl) ?>
                                </a>
                            <?php endforeach; ?>
                            </details>
                        <?php else: ?>
                            <a href="<?= url($route) . $query ?>" class="sidebar-link <?= $onPage ? 'active' : '' ?>">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><?= $adm_ico[$icon] ?></svg>
                                <span class="menu-label"><?= htmlspecialchars($label) ?></span>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <div class="adm-promo menu-label">
                    <span class="adm-promo-ico">
                        <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 20 16.5 7.5l3 3L7 23H4v-3Z" />
                        </svg>
                    </span>
                    <b>Building a better learning community.</b>
                    <span>Support. Monitor. Improve.</span>
                </div>
        </nav>

        <?php
        // The signed-in admin, rather than a hardcoded "Admin". Read here so
        // both the rail footer and the topbar chip show the same person.
        $adm_me_id   = (int)($_SESSION['user_id'] ?? 0);
        $adm_me_name = 'Admin';
        $adm_me_mail = $_SESSION['email'] ?? '';
        if ($adm_me_id) {
            $meq = $con->prepare("
                SELECT u.firstname, u.lastname, u.email, p.profile_image
                FROM users u
                LEFT JOIN profile p ON p.user_id = u.user_id
                WHERE u.user_id = ? LIMIT 1
            ");
            $meq->bind_param("i", $adm_me_id);
            $meq->execute();
            $adm_me = $meq->get_result()->fetch_assoc() ?: [];
            $meq->close();
            $n = trim(($adm_me['firstname'] ?? '') . ' ' . ($adm_me['lastname'] ?? ''));
            if ($n !== '') $adm_me_name = $n;
            if (!empty($adm_me['email'])) $adm_me_mail = $adm_me['email'];
        }
        $adm_me_ini = strtoupper(substr($adm_me_name, 0, 1)) ?: 'A';
        $adm_me_pic = $adm_me['profile_image'] ?? '';
        ?>
        <div class="px-2 sidebar-foot pt-3 mt-2 relative">
            <button onclick="toggleProfileMenu()" class="sidebar-link w-full" type="button">
                <div class="adm-who-av" style="width:28px;height:28px;font-size:11px;">
                    <?php if ($adm_me_pic): ?><img src="<?= htmlspecialchars($adm_me_pic) ?>" alt=""><?php else: ?><?= htmlspecialchars($adm_me_ini) ?><?php endif; ?>
                </div>
                <span class="menu-label truncate" style="font-size:13px;"><?= htmlspecialchars($adm_me_name) ?></span>
            </button>
            <div id="profileMenu">
                <div class="flex items-center gap-3 mb-3">
                    <div class="adm-who-av">
                        <?php if ($adm_me_pic): ?><img src="<?= htmlspecialchars($adm_me_pic) ?>" alt=""><?php else: ?><?= htmlspecialchars($adm_me_ini) ?><?php endif; ?>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($adm_me_name) ?></p>
                        <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($adm_me_mail ?: 'System Administrator') ?></p>
                    </div>
                </div>
                <hr class="mb-3">
                <a href="<?= url('logout') ?>" class="flex items-center gap-2 p-2 rounded-lg hover:bg-gray-50 text-sm text-red-500">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    Log out
                </a>
            </div>
        </div>
    </aside>

    <!-- MAIN WRAPPER -->
    <div class="flex-1 flex flex-col min-h-0">
        <!-- TOP BAR -->
        <header class="bg-white border-b border-gray-100 flex items-center justify-between px-6 py-3 shrink-0">
            <div class="flex items-center gap-3">
                <?php // Was an input wired to nothing on every admin page. It now
                //     searches accounts by name, username or email. 
                ?>
                <form method="get" action="<?= url('admin-users') ?>" class="adm-top-search">
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="8" />
                        <path d="M21 21l-4.35-4.35" />
                    </svg>
                    <label for="adm-q" class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Search accounts</label>
                    <input id="adm-q" type="search" name="q" maxlength="80"
                        value="<?= htmlspecialchars(is_string($_GET['q'] ?? null) ? $_GET['q'] : '') ?>"
                        placeholder="Search users by name, username or email"
                        class="bg-transparent text-sm outline-none w-full text-gray-700 placeholder-gray-400">
                </form>
            </div>
            <div class="flex items-center gap-3">
                <?php
                /*
                 * The bell opens a panel, the way the member-facing one does.
                 *
                 * What it lists is deliberately not the `notifications` table
                 * alone: nothing in this app has ever addressed a notification
                 * to an admin, so a panel reading only that table would be
                 * permanently empty. What an admin actually needs to be told
                 * about is work waiting on them — verifications to review and
                 * open reports — which is read live here, so an item disappears
                 * the moment it is handled rather than lingering as a stale
                 * "new report" for something already resolved.
                 *
                 * Real notification rows are shown too, below the queue, so
                 * anything later addressed to an admin turns up without this
                 * needing to change.
                 */
                $adm_verif_rows = $con->query("
                    SELECT v.verification_id, v.submitted_at,
                           COALESCE(NULLIF(v.full_name, ''), CONCAT_WS(' ', u.firstname, u.lastname)) AS who,
                           u.role
                    FROM user_verifications v
                    JOIN users u ON u.user_id = v.user_id
                    WHERE v.status = 'pending'
                    ORDER BY v.submitted_at ASC
                    LIMIT 6
                ")->fetch_all(MYSQLI_ASSOC);

                $adm_report_rows = $con->query("
                    SELECT r.report_id, r.issue_type, r.created_at, r.status,
                           CONCAT_WS(' ', t.firstname, t.lastname) AS target
                    FROM reports r
                    LEFT JOIN users t ON t.user_id = r.reported_user_id
                    WHERE r.status IN ('pending', 'urgent')
                    ORDER BY r.created_at DESC
                    LIMIT 6
                ")->fetch_all(MYSQLI_ASSOC);

                $adm_pending_verif = (int)($con->query("SELECT COUNT(*) c FROM user_verifications WHERE status = 'pending'")->fetch_assoc()['c'] ?? 0);
                $adm_open_reports  = (int)($con->query("SELECT COUNT(*) c FROM reports WHERE status IN ('pending','urgent')")->fetch_assoc()['c'] ?? 0);

                $adm_notif_rows = [];
                $adm_unread     = 0;
                if (!empty($_SESSION['user_id'])) {
                    $ns = $con->prepare("
                        SELECT notification_id, title, message, link, is_read, created_at
                        FROM notifications WHERE user_id = ?
                        ORDER BY is_read ASC, created_at DESC LIMIT 8
                    ");
                    $ns->bind_param('i', $_SESSION['user_id']);
                    $ns->execute();
                    $adm_notif_rows = $ns->get_result()->fetch_all(MYSQLI_ASSOC);
                    $ns->close();

                    $nc = $con->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND is_read = 0");
                    $nc->bind_param('i', $_SESSION['user_id']);
                    $nc->execute();
                    $adm_unread = (int)($nc->get_result()->fetch_assoc()['c'] ?? 0);
                    $nc->close();
                }

                $adm_queue = $adm_pending_verif + $adm_open_reports;
                $adm_badge = $adm_queue + $adm_unread;
                $adm_queue_title = $adm_badge === 0
                    ? 'Nothing waiting for review'
                    : $adm_pending_verif . ' verification' . ($adm_pending_verif === 1 ? '' : 's')
                    . ' and ' . $adm_open_reports . ' report' . ($adm_open_reports === 1 ? '' : 's') . ' waiting';

                $adm_ago = function (?string $when): string {
                    if (!$when) return '';
                    $d = time() - strtotime($when);
                    if ($d < 60)    return 'Just now';
                    if ($d < 3600)  return (int)($d / 60) . 'm ago';
                    if ($d < 86400) return (int)($d / 3600) . 'h ago';
                    if ($d < 604800) return (int)($d / 86400) . 'd ago';
                    return date('M j', strtotime($when));
                };
                ?>
                <div class="adm-bell-wrap">
                    <button type="button" id="admBell" onclick="admToggleBell()"
                        aria-haspopup="true" aria-expanded="false"
                        title="<?= htmlspecialchars($adm_queue_title) ?>" aria-label="<?= htmlspecialchars($adm_queue_title) ?>"
                        class="relative w-9 h-9 rounded-full hover:bg-gray-100 flex items-center justify-center text-gray-500">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                        <?php if ($adm_badge > 0): ?>
                            <span class="absolute -top-0.5 -right-0.5 min-w-4 h-4 px-1 bg-red-500 text-white rounded-full text-[10px] font-bold flex items-center justify-center"><?= $adm_badge > 9 ? '9+' : $adm_badge ?></span>
                        <?php endif; ?>
                    </button>

                    <div class="adm-bell-panel" id="admBellPanel" hidden>
                        <div class="adm-bell-hd">
                            <b>Notifications</b>
                            <?php if ($adm_unread > 0): ?>
                                <form method="post" action="<?= url('notifications-read-all') ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <button type="submit">Mark all read</button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <div class="adm-bell-body">
                            <?php if ($adm_badge === 0): ?>
                                <p class="adm-bell-empty">Nothing waiting for review.</p>
                            <?php endif; ?>

                            <?php if ($adm_verif_rows): ?>
                                <p class="adm-bell-sec">Verifications to review</p>
                                <?php foreach ($adm_verif_rows as $n): ?>
                                    <a class="adm-bell-item" href="<?= url('admin-users') ?>?tab=pending">
                                        <span class="adm-bell-dot bell-blue"></span>
                                        <span class="adm-bell-txt">
                                            <b><?= htmlspecialchars($n['who'] ?: 'A member') ?></b>
                                            submitted a <?= htmlspecialchars($n['role'] ?: 'mentee') ?> verification.
                                            <span class="adm-bell-when"><?= htmlspecialchars($adm_ago($n['submitted_at'])) ?></span>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                                <?php if ($adm_pending_verif > count($adm_verif_rows)): ?>
                                    <a class="adm-bell-more" href="<?= url('admin-users') ?>?tab=pending"><?= $adm_pending_verif - count($adm_verif_rows) ?> more waiting</a>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($adm_report_rows): ?>
                                <p class="adm-bell-sec">Open reports</p>
                                <?php foreach ($adm_report_rows as $n): ?>
                                    <a class="adm-bell-item" href="<?= url('admin-notifications') ?>?report=<?= (int)$n['report_id'] ?>">
                                        <span class="adm-bell-dot bell-red"></span>
                                        <span class="adm-bell-txt">
                                            <b><?= htmlspecialchars(ReportService::label($n['issue_type'])) ?></b>
                                            against <?= htmlspecialchars(trim((string)$n['target']) ?: 'a removed account') ?>.
                                            <span class="adm-bell-when"><?= htmlspecialchars($adm_ago($n['created_at'])) ?></span>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                                <?php if ($adm_open_reports > count($adm_report_rows)): ?>
                                    <a class="adm-bell-more" href="<?= url('admin-notifications') ?>?tab=reports"><?= $adm_open_reports - count($adm_report_rows) ?> more open</a>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($adm_notif_rows): ?>
                                <p class="adm-bell-sec">For you</p>
                                <?php foreach ($adm_notif_rows as $n): ?>
                                    <?php $href = trim((string)$n['link']); ?>
                                    <a class="adm-bell-item<?= (int)$n['is_read'] ? '' : ' unread' ?>" href="<?= $href !== '' ? htmlspecialchars($href) : '#' ?>">
                                        <span class="adm-bell-dot <?= (int)$n['is_read'] ? 'bell-grey' : 'bell-blue' ?>"></span>
                                        <span class="adm-bell-txt">
                                            <b><?= htmlspecialchars($n['title']) ?></b>
                                            <?= htmlspecialchars($n['message']) ?>
                                            <span class="adm-bell-when"><?= htmlspecialchars($adm_ago($n['created_at'])) ?></span>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <a class="adm-bell-more" href="<?= url('admin-notifications') ?>" style="display:block;text-align:center;border-top:1px solid var(--gray-100);padding:10px;">See all notifications</a>
                    </div>
                </div>

                <?php // The reference's profile chip. It opens the same menu the
                //     rail footer does, so there is one place to sign out. 
                ?>
                <button type="button" class="adm-who" onclick="toggleProfileMenu()" aria-haspopup="true">
                    <span class="adm-who-av">
                        <?php if ($adm_me_pic): ?><img src="<?= htmlspecialchars($adm_me_pic) ?>" alt=""><?php else: ?><?= htmlspecialchars($adm_me_ini) ?><?php endif; ?>
                    </span>
                    <span class="adm-who-txt text-left">
                        <span class="adm-who-n block"><?= htmlspecialchars($adm_me_name) ?></span>
                        <span class="adm-who-r block">System Administrator</span>
                    </span>
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                    </svg>
                </button>
            </div>
        </header>

        <script>
            /*
             * The rail's collapse button and the profile menu are drawn by this
             * layout, so their handlers belong here too. They used to be defined
             * per page (admin_footer.php, or inline), which meant every page that
             * did neither — Sessions, Assessments, Settings, Reports — rendered
             * both buttons dead. Pages that still define their own identical
             * copies simply override these with the same behaviour.
             */
            function toggleSidebar() {
                var el = document.getElementById('sidebar');
                if (el) el.classList.toggle('collapsed');
            }

            function toggleProfileMenu() {
                var m = document.getElementById('profileMenu');
                if (m) m.classList.toggle('open');
            }
            document.addEventListener('click', function(e) {
                var m = document.getElementById('profileMenu');
                if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]')) {
                    m.classList.remove('open');
                }
            });

            function admToggleBell() {
                var panel = document.getElementById('admBellPanel');
                var bell = document.getElementById('admBell');
                var open = panel.hidden;
                panel.hidden = !open;
                bell.setAttribute('aria-expanded', String(open));
            }
            // A click anywhere else closes it, as does Escape.
            document.addEventListener('click', function(e) {
                if (e.target.closest('.adm-bell-wrap')) return;
                var panel = document.getElementById('admBellPanel');
                if (panel && !panel.hidden) {
                    panel.hidden = true;
                    document.getElementById('admBell').setAttribute('aria-expanded', 'false');
                }
            });
            document.addEventListener('keydown', function(e) {
                if (e.key !== 'Escape') return;
                var panel = document.getElementById('admBellPanel');
                if (panel && !panel.hidden) {
                    panel.hidden = true;
                    document.getElementById('admBell').setAttribute('aria-expanded', 'false');
                }
            });

            // Escape closes any open modal — consistent across every admin page
            // regardless of whether it also loads includes/design_system.php.
            document.addEventListener('keydown', function(e) {
                if (e.key !== 'Escape') return;
                document.querySelectorAll('.modal-overlay.open').forEach(function(overlay) {
                    overlay.classList.remove('open');
                });
            });
        </script>

        <?php // The same toasts the member pages use, so an admin action
        //     confirms itself exactly the way a mentee's does.
        include __DIR__ . '/../includes/toasts.php'; ?>

        <!-- PAGE CONTENT -->
        <main class="flex-1 overflow-y-auto p-6">