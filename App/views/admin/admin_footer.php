<?php

/**
 * admin_footer.php
 * Shared closing scripts for admin pages that include layout.php separately.
 * (admin/index.php inlines these; badges.php and other standalone pages use this.)
 */
?>
<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('collapsed');
    }

    function toggleProfileMenu() {
        document.getElementById('profileMenu').classList.toggle('open');
    }
    document.addEventListener('click', function(e) {
        const m = document.getElementById('profileMenu');
        if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]'))
            m.classList.remove('open');
    });
</script>