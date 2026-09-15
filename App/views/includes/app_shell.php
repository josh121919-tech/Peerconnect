<?php
require_once __DIR__ . '/design_system.php';

if (defined('PEERCONNECT_APP_SHELL_INCLUDED')) {
    return;
}
define('PEERCONNECT_APP_SHELL_INCLUDED', true);

$active_page = $active_page ?? '';
$role = $_SESSION['role'] ?? '';
$isAdmin = $role === 'admin' || strpos((string)$active_page, 'admin') === 0;
$isMentor = $role === 'mentor';
$email = $_SESSION['email'] ?? ($isAdmin ? 'admin@peerconnect.local' : 'member@peerconnect.local');
$initial = strtoupper(substr($email, 0, 1));

// Real first/last name for the sidebar footer + topbar profile trigger —
// $_SESSION never actually gets populated with these (checked: every signup
// path leaves firstname/lastname unset in session), so this reads the DB
// directly rather than guessing from the email.
$display_name = $email;
$avatar_image = null;
if (isset($con) && !empty($_SESSION['user_id']) && !$isAdmin) {
    $uid = (int)$_SESSION['user_id'];
    $nameRow = $con->query("SELECT firstname, lastname FROM users WHERE user_id = $uid")->fetch_assoc();
    if ($nameRow) {
        $display_name = trim($nameRow['firstname'] . ' ' . $nameRow['lastname']);
    }
    $imgRow = $con->query("SELECT profile_image FROM profile WHERE user_id = $uid")->fetch_assoc();
    if (!empty($imgRow['profile_image'])) {
        $avatar_image = $imgRow['profile_image'];
    }
}

$homeUrl = $isAdmin ? url('admin-dashboard') : ($isMentor ? url('mentor-dashboard') : url('mentee-dashboard'));
$browseUrl = $isAdmin ? url('admin-users') : url('mentee-find');
$searchUrl = url('mentee-find');
$profileUrl = $isAdmin ? url('admin-dashboard') : ($isMentor ? url('mentor-profile') : url('mentee-profile'));
$settingsUrl = $isAdmin ? url('admin-dashboard') : ($isMentor ? url('mentor-settings') : url('mentee-settings'));
$logoutUrl = url('logout');
$ctaUrl = $isAdmin ? url('admin-verify') : ($isMentor ? url('mentor-calendar') : url('mentee-find'));
$ctaLabel = $isAdmin ? 'Review queue' : ($isMentor ? 'Create availability' : 'Book session');

if (!function_exists('pc_nav_link')) {
    /**
     * $barSlot is the 1-based position this link takes in the mobile bottom
     * bar, or null if it does not appear there. The sidebar lists the links
     * grouped under section headings, which is NOT the bottom bar's order, so
     * the bar can no longer be "the first five in the DOM" — the slot number
     * rides along as data-bar and CSS orders the bar from it.
     */
    function pc_nav_link(string $href, string $key, string $label, string $icon, string $activePage, ?int $barSlot = null): void
    {
        $active = $activePage === $key ? ' active' : '';
?>
        <a href="<?= htmlspecialchars($href) ?>" class="sb-link<?= $active ?>" <?= $barSlot ? ' data-bar="' . $barSlot . '"' : '' ?>>
            <?php pc_icon($icon); ?>
            <span class="menu-label"><?= htmlspecialchars($label) ?></span>
        </a>
<?php
    }
}

$navItems = [];
// Mobile bottom bar shows only the first 4 items + a "More" overflow button;
// the desktop sidebar still lists everything (see `.sb-nav` below).
if ($isAdmin) {
    $navItems = [
        [url('admin-dashboard'),  'admin-dashboard',  'Home',      'home'],
        [url('admin-users'),      'admin-users',       'Users',     'users'],
        [url('admin-verify'),     'admin-verify',      'Verify',    'verify'],
        [url('admin-badges'),     'admin-badges',      'Badges',    'journal'],
        [url('admin-analytics'),  'admin-analytics',   'Analytics', 'bookings'],
    ];
} elseif ($isMentor) {
    $navItems = [
        [url('mentor-dashboard'), 'dashboard', 'Home', 'home'],
        [url('mentor-requests'), 'sessions', 'Sessions', 'sessions'],
        [url('mentor-calendar'), 'calendar', 'Calendar', 'bookings'],
        [url('messages'), 'messages', 'Messages', 'messages'],
        [url('assessments'), 'assessments', 'Assessments', 'assessments'],
        [url('resources'), 'resources', 'Resources', 'resources'],
        [url('leaderboard'), 'leaderboard', 'Leaderboard', 'leaderboard'],
        [url('mentor-feedback'), 'feedback', 'Feedback', 'feedback'],
        [url('announcements'), 'announcements', 'Announcements', 'megaphone'],
    ];
} else {
    // Order matches the reference IA's mobile bottom bar (first 4 = visible,
    // rest overflow into "More" — see array_slice below): Dashboard, Find
    // Mentor, Sessions, Messages, then the rest.
    $navItems = [
        [url('mentee-dashboard'), 'dashboard', 'Home', 'home'],
        [url('mentee-find'), 'find_mentor', 'Find Mentor', 'explore'],
        [url('mentee-sessions'), 'sessions', 'Sessions', 'sessions'],
        [url('messages'), 'messages', 'Messages', 'messages'],
        [url('assessments'), 'assessments', 'Assessments', 'assessments'],
        [url('resources'), 'resources', 'Resources', 'resources'],
        [url('mentee-matching'), 'matching', 'Matching', 'partner'],
        [url('mentee-calendar'), 'calendar', 'Calendar', 'bookings'],
        [url('leaderboard'), 'leaderboard', 'Leaderboard', 'leaderboard'],
        [url('mentee-feedback'), 'feedback', 'Feedback', 'feedback'],
        [url('announcements'), 'announcements', 'Announcements', 'megaphone'],
    ];
}
// The mobile bottom bar shows the first FIVE items; everything else lives in
// the slide-in menu the topbar button opens. There is no "More" sheet any
// more — the 5th slot is a real destination (Assessments) rather than a
// disclosure control.
$bottomBarCount = 5;

/**
 * The slide-in menu, grouped. Keys are the section headings; each entry is a
 * nav tuple already present in $navItems, matched by its page key, so a link
 * can never point somewhere the sidebar does not already go.
 */
$navByKey = [];
foreach ($navItems as $item) {
    $navByKey[$item[1]] = $item;
}
$drawerSections = $isAdmin
    ? [
        'Dashboard' => ['admin-dashboard'],
        'Manage'    => ['admin-users', 'admin-verify', 'admin-badges'],
        'Insights'  => ['admin-analytics'],
    ]
    : ($isMentor
        ? [
            'Dashboard' => ['dashboard'],
            'Mentoring' => ['sessions', 'calendar', 'messages'],
            'Teaching'  => ['assessments', 'resources'],
            'Community' => ['leaderboard', 'feedback', 'announcements'],
        ]
        : [
            'Dashboard' => ['dashboard'],
            'Learning'  => ['find_mentor', 'matching', 'sessions', 'calendar'],
            'Progress'  => ['assessments', 'resources'],
            'Community' => ['messages', 'leaderboard', 'feedback', 'announcements'],
        ]);

/**
 * The sidebar and the mobile bottom bar are the same <nav>, so the DOM can
 * only carry one order. The sidebar wants the grouped order (that is what the
 * section headings label); the bar wants its own five. $barSlots maps a page
 * key to its 1-based slot in the bar, and CSS reorders from there.
 */
$barSlots = [];
foreach (array_slice($navItems, 0, $bottomBarCount) as $i => $item) {
    $barSlots[$item[1]] = $i + 1;
}

/**
 * The sidebar in grouped order. Anything $drawerSections forgot is appended
 * ungrouped rather than dropped, so a new nav item can never vanish from the
 * sidebar just because nobody added it to a section.
 */
$sidebarGroups = [];
$placed = [];
foreach ($drawerSections as $heading => $keys) {
    $group = [];
    foreach ($keys as $k) {
        if (isset($navByKey[$k])) {
            $group[] = $navByKey[$k];
            $placed[$k] = true;
        }
    }
    if ($group) {
        $sidebarGroups[$heading] = $group;
    }
}
$leftover = array_values(array_filter($navItems, fn($item) => !isset($placed[$item[1]])));
if ($leftover) {
    $sidebarGroups['More'] = $leftover;
}
?>
<header class="app-topbar">
    <div class="topbar-brand-mobile">
        <?php pc_logo($homeUrl); ?>
    </div>
    <div class="topbar-actions">
        <?php // The only sidebar toggle in the app. It used to be a button
        //     inside each page's header, which meant it did not exist on the
        //     small screens that need it most. 
        ?>
        <button class="icon-btn" type="button" id="pcNavBtn" onclick="pcToggleNav()"
            aria-label="Open menu" aria-haspopup="true" aria-expanded="false" aria-controls="pcDrawer">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>
        <a class="icon-btn" href="<?= htmlspecialchars($searchUrl) ?>" aria-label="Search mentors">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="11" cy="11" r="7" />
                <path stroke-linecap="round" d="m20 20-4-4" />
            </svg>
        </a>
        <button class="icon-btn soft" type="button" aria-label="Notifications" id="notif-btn" style="position:relative;" onclick="toggleNotifPanel()" aria-haspopup="true" aria-expanded="false">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0 1 18 14.158V11a6 6 0 0 0-5-5.917V4a1 1 0 0 0-2 0v1.083A6 6 0 0 0 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 0 1-6 0v-1m6 0H9" />
            </svg>
            <span id="notif-badge" style="display:none;position:absolute;top:4px;right:4px;width:8px;height:8px;background:var(--danger,#e53e3e);border-radius:50%;border:1.5px solid white;"></span>
        </button>
        <!-- Notification panel -->
        <div id="notif-panel" style="display:none;position:absolute;top:54px;right:16px;width:340px;background:white;border-radius:14px;box-shadow:0 8px 40px rgba(0,0,0,.14);z-index:500;overflow:hidden;border:1px solid var(--border,#e5e7eb);">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 10px;border-bottom:1px solid var(--border,#e5e7eb);">
                <span style="font-weight:600;font-size:14px;color:var(--gray-900,#111);">Notifications</span>
                <button onclick="markAllRead()" style="font-size:12px;color:var(--forest,#1a5c4a);background:none;border:none;cursor:pointer;font-weight:500;">Mark all read</button>
            </div>
            <div id="notif-list" style="max-height:360px;overflow-y:auto;"></div>
            <div id="notif-empty" style="display:none;padding:24px;text-align:center;color:var(--gray-400,#9ca3af);font-size:13px;">All caught up 🎉</div>
        </div>
        <script>
            (function() {
                const btn = document.getElementById('notif-btn');
                const panel = document.getElementById('notif-panel');
                const badge = document.getElementById('notif-badge');
                const list = document.getElementById('notif-list');
                const empty = document.getElementById('notif-empty');

                function toggleNotifPanel() {
                    const open = panel.style.display !== 'none';
                    panel.style.display = open ? 'none' : 'block';
                    btn.setAttribute('aria-expanded', String(!open));
                    if (!open) loadNotifications();
                }
                window.toggleNotifPanel = toggleNotifPanel;

                // Click outside to close
                document.addEventListener('click', function(e) {
                    if (!btn.contains(e.target) && !panel.contains(e.target)) {
                        panel.style.display = 'none';
                        btn.setAttribute('aria-expanded', 'false');
                    }
                });

                function loadNotifications() {
                    fetch('<?= url('notifications-get') ?>')
                        .then(r => r.json())
                        .then(data => {
                            list.innerHTML = '';
                            if (!data.notifications || data.notifications.length === 0) {
                                empty.style.display = 'block';
                                return;
                            }
                            empty.style.display = 'none';
                            data.notifications.forEach(n => {
                                const item = document.createElement('a');
                                item.href = n.link || '#';
                                item.style.cssText = 'display:block;padding:12px 16px;border-bottom:1px solid var(--border,#e5e7eb);text-decoration:none;' + (n.is_read == 0 ? 'background:var(--mint-faint,#f0faf6);' : '');
                                // SECURITY: build via textContent, not innerHTML — title/message can
                                // contain admin-authored free text (e.g. rejection notes) and must
                                // never be interpreted as HTML.
                                const titleEl = document.createElement('div');
                                titleEl.style.cssText = `font-size:13px;font-weight:${n.is_read == 0 ? '600' : '500'};color:var(--gray-900,#111);margin-bottom:2px;`;
                                titleEl.textContent = n.title;
                                const msgEl = document.createElement('div');
                                msgEl.style.cssText = 'font-size:12px;color:var(--gray-500,#6b7280);';
                                msgEl.textContent = n.message;
                                const timeEl = document.createElement('div');
                                timeEl.style.cssText = 'font-size:11px;color:var(--gray-400,#9ca3af);margin-top:4px;';
                                timeEl.textContent = n.time_ago;
                                item.appendChild(titleEl);
                                item.appendChild(msgEl);
                                item.appendChild(timeEl);
                                if (n.notification_id) {
                                    item.addEventListener('click', () => markRead(n.notification_id));
                                }
                                list.appendChild(item);
                            });
                        }).catch(() => {});
                }

                function markRead(id) {
                    fetch('<?= url('notifications-read') ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            // Says which reply this caller wants; the endpoint
                            // answers a plain form post with a redirect instead.
                            'Accept': 'application/json'
                        },
                        body: 'notification_id=' + id + '&csrf_token=<?= csrf_token() ?>'
                    });
                }

                window.markAllRead = function() {
                    fetch('<?= url('notifications-read-all') ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            // Says which reply this caller wants; the endpoint
                            // answers a plain form post with a redirect instead.
                            'Accept': 'application/json'
                        },
                        body: 'csrf_token=<?= csrf_token() ?>'
                    }).then(() => {
                        badge.style.display = 'none';
                        loadNotifications();
                    });
                };

                function pollCount() {
                    fetch('<?= url('notifications-count') ?>')
                        .then(r => r.json())
                        .then(d => {
                            badge.style.display = d.count > 0 ? 'block' : 'none';
                        }).catch(() => {});
                }

                pollCount();
                setInterval(pollCount, 30000); // poll every 30s
            })();
        </script>
        <a href="<?= htmlspecialchars($ctaUrl) ?>" class="btn btn-primary" style="font-size:13px;">
            <?= htmlspecialchars($ctaLabel) ?>
        </a>
        <button class="topbar-profile-btn" type="button" onclick="toggleTopbarProfileMenu()" aria-haspopup="true" aria-expanded="false" aria-label="Open profile menu">
            <span class="tp-avatar">
                <?php if ($avatar_image): ?>
                    <img src="<?= htmlspecialchars($avatar_image) ?>" alt="">
                <?php else: ?>
                    <?= htmlspecialchars($initial) ?>
                <?php endif; ?>
            </span>
            <span class="tp-name"><?= htmlspecialchars($display_name) ?></span>
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
            </svg>
        </button>
        <div id="topbarProfileMenu" role="menu" aria-label="User menu">
            <div class="pm-head">
                <div class="pm-avatar"><?= htmlspecialchars($initial) ?></div>
                <div style="min-width:0;">
                    <p style="margin:0;color:var(--gray-900);font-weight:600;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($display_name) ?></p>
                    <p style="margin:2px 0 0;color:var(--gray-500);font-size:11.5px;text-transform:capitalize;"><?= htmlspecialchars($isAdmin ? 'Admin' : ucfirst($role ?: 'member')) ?></p>
                </div>
            </div>
            <a href="<?= htmlspecialchars($profileUrl) ?>" class="pm-link" role="menuitem">
                <?php pc_icon('users'); ?>
                My profile
            </a>
            <a href="<?= htmlspecialchars($settingsUrl) ?>" class="pm-link" role="menuitem">
                <?php pc_icon('settings'); ?>
                Settings
            </a>
            <a href="<?= htmlspecialchars($logoutUrl) ?>" class="pm-link red" role="menuitem">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 17l5-5-5-5M21 12H9M13 21H6a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3h7" />
                </svg>
                Sign out
            </a>
        </div>
    </div>
</header>

<aside id="sidebar" aria-label="Main navigation">
    <div class="sb-brand">
        <?php pc_brand_mark('sb-brand-mark'); ?>
        <div>
            <div class="sb-brand-word"><span class="w-peer">PEER</span><span class="w-connect">CONNECT</span></div>
            <div class="sb-brand-tagline">Mentoring. Growing. Together.</div>
        </div>
    </div>
    <nav class="sb-nav">
        <?php foreach ($sidebarGroups as $heading => $group): ?>
            <p class="sb-section-label"><?= htmlspecialchars($heading) ?></p>
            <?php foreach ($group as $item): ?>
                <?php pc_nav_link($item[0], $item[1], $item[2], $item[3], $active_page, $barSlots[$item[1]] ?? null); ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sb-promo">
        <span class="sb-promo-icon">
            <?php pc_icon('journal'); ?>
        </span>
        <div class="sb-promo-title">Grow together.</div>
        <div class="sb-promo-body">Every session brings you closer to your goals. Keep going.</div>
    </div>
    <div class="sb-footer">
        <button onclick="toggleProfileMenu()" class="sb-link" type="button" aria-label="Open profile menu" aria-haspopup="true">
            <span class="sb-user"><?= htmlspecialchars($initial) ?></span>
            <span class="menu-label" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;"><?= htmlspecialchars($email) ?></span>
        </button>
        <div id="profileMenu" role="menu" aria-label="User menu">
            <div class="pm-head">
                <div class="pm-avatar"><?= htmlspecialchars($initial) ?></div>
                <div style="min-width:0;">
                    <p style="margin:0;color:var(--gray-900);font-weight:600;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($email) ?></p>
                    <p style="margin:2px 0 0;color:var(--gray-500);font-size:11.5px;text-transform:capitalize;"><?= htmlspecialchars($isAdmin ? 'Admin' : ucfirst($role ?: 'member')) ?></p>
                </div>
            </div>
            <a href="<?= htmlspecialchars($profileUrl) ?>" class="pm-link" role="menuitem">
                <?php pc_icon('users'); ?>
                My profile
            </a>
            <a href="<?= htmlspecialchars($settingsUrl) ?>" class="pm-link" role="menuitem">
                <?php pc_icon('settings'); ?>
                Settings
            </a>
            <a href="<?= htmlspecialchars($logoutUrl) ?>" class="pm-link red" role="menuitem">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 17l5-5-5-5M21 12H9M13 21H6a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3h7" />
                </svg>
                Sign out
            </a>
        </div>
    </div>
</aside>
<script>
    (function() {
        // New topbar profile trigger — separate from the existing sidebar-footer
        // toggleProfileMenu()/#profileMenu (defined per-page, left untouched) since
        // #profileMenu is positioned relative to .sb-footer and would not line up
        // if reused for a button living in the topbar instead.
        function toggleTopbarProfileMenu() {
            const m = document.getElementById('topbarProfileMenu');
            if (m) m.classList.toggle('open');
        }
        window.toggleTopbarProfileMenu = toggleTopbarProfileMenu;

        document.addEventListener('click', function(e) {
            const m = document.getElementById('topbarProfileMenu');
            if (m && !e.target.closest('#topbarProfileMenu') && !e.target.closest('.topbar-profile-btn')) {
                m.classList.remove('open');
            }
        });
    })();
</script>
<!-- ══ Slide-in menu ══
     Opened by the topbar button. On a wide screen that button collapses the
     sidebar to its icon rail (what the old in-page toggle did); below the
     bottom-bar breakpoint the sidebar IS the bottom bar, so there is nothing
     to collapse and this panel opens instead. It carries every destination,
     including the ones the five-slot bottom bar cannot show. -->
<div class="pc-drawer-scrim" id="pcDrawerScrim" onclick="pcCloseNav()"></div>
<aside class="pc-drawer" id="pcDrawer" aria-hidden="true" aria-label="All pages">
    <div class="pc-drawer-hd">
        <?php pc_brand_mark('pc-drawer-mark'); ?>
        <div style="min-width:0;">
            <div class="pc-drawer-word"><span class="w-peer">PEER</span><span class="w-connect">CONNECT</span></div>
            <div class="pc-drawer-sub"><?= htmlspecialchars($isAdmin ? 'Admin' : ucfirst($role ?: 'member')) ?></div>
        </div>
        <button type="button" class="pc-drawer-x" onclick="pcCloseNav()" aria-label="Close menu">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" />
            </svg>
        </button>
    </div>

    <nav class="pc-drawer-nav">
        <?php foreach ($drawerSections as $heading => $keys): ?>
            <?php
            $items = array_values(array_filter(array_map(fn($k) => $navByKey[$k] ?? null, $keys)));
            if (!$items) continue;
            ?>
            <div class="pc-drawer-label"><?= htmlspecialchars($heading) ?></div>
            <?php foreach ($items as $item): ?>
                <?php pc_nav_link($item[0], $item[1], $item[2], $item[3], $active_page); ?>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="pc-drawer-label">Account</div>
        <a href="<?= htmlspecialchars($profileUrl) ?>" class="sb-link">
            <?php pc_icon('users'); ?>
            <span class="menu-label">My profile</span>
        </a>
        <a href="<?= htmlspecialchars($settingsUrl) ?>" class="sb-link">
            <?php pc_icon('settings'); ?>
            <span class="menu-label">Settings</span>
        </a>
        <a href="<?= htmlspecialchars($logoutUrl) ?>" class="sb-link" style="color:var(--danger);">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16 17l5-5-5-5M21 12H9M13 21H6a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3h7" />
            </svg>
            <span class="menu-label">Sign out</span>
        </a>
    </nav>
</aside>

<script>
    (function() {
        var MOBILE = '(max-width: 860px)';

        function drawerOpen() {
            return document.getElementById('pcDrawer').classList.contains('open');
        }

        function pcOpenNav() {
            document.getElementById('pcDrawer').classList.add('open');
            document.getElementById('pcDrawer').setAttribute('aria-hidden', 'false');
            document.getElementById('pcDrawerScrim').classList.add('open');
            var b = document.getElementById('pcNavBtn');
            if (b) b.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }

        function pcCloseNav() {
            document.getElementById('pcDrawer').classList.remove('open');
            document.getElementById('pcDrawer').setAttribute('aria-hidden', 'true');
            document.getElementById('pcDrawerScrim').classList.remove('open');
            var b = document.getElementById('pcNavBtn');
            if (b) b.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }

        // Deliberately NOT called toggleSidebar(): a dozen pages define their
        // own function by that name in their own <script>, which would shadow
        // this one and lose the drawer behaviour on exactly those pages.
        function pcToggleNav() {
            var sidebar = document.getElementById('sidebar');
            if (window.matchMedia(MOBILE).matches || !sidebar) {
                return drawerOpen() ? pcCloseNav() : pcOpenNav();
            }
            sidebar.classList.toggle('collapsed');
        }

        window.pcToggleNav = pcToggleNav;
        window.pcCloseNav = pcCloseNav;

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && drawerOpen()) pcCloseNav();
        });

        // Leaving the small breakpoint with the panel open would strand a
        // fixed overlay over the desktop layout.
        window.matchMedia(MOBILE).addEventListener('change', function(ev) {
            if (!ev.matches && drawerOpen()) pcCloseNav();
        });
    })();
</script>