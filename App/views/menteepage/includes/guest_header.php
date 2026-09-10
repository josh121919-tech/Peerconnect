<?php
$homeUrl = url('welcomepage');
$findMentorUrl = url('mentee-find');
?>
<header class="guest-topbar">
    <?php if (function_exists('pc_logo')) {
        pc_logo($homeUrl);
    } ?>
    <div class="topbar-center">
        <a class="topbar-browse" href="<?= htmlspecialchars($findMentorUrl) ?>">
            Browse
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
            </svg>
        </a>
        <form class="topbar-search" method="get" action="<?= htmlspecialchars($findMentorUrl) ?>">
            <svg width="28" height="28" fill="none" stroke="var(--accent)" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="11" cy="11" r="7" />
                <path stroke-linecap="round" d="m20 20-4-4" />
            </svg>
            <input type="search" name="search" placeholder="Search mentors">
        </form>
    </div>
    <?php if (empty($_SESSION['user_id'])): ?>
        <div class="topbar-actions">
            <a href="<?= htmlspecialchars(url('login')) ?>" class="btn btn-ghost">Log in</a>
            <a href="<?= htmlspecialchars(url('signup')) ?>" class="btn btn-primary">Get started today</a>
        </div>
    <?php endif; ?>
</header>