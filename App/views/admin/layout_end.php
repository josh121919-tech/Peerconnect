<?php

/**
 * layout_end.php
 *
 * Closes what layout.php opens. layout.php ends part-way through the page —
 * its last line opens <main class="flex-1 overflow-y-auto p-6"> and leaves it
 * open for the page to fill — but nothing ever closed it again, so every
 * admin page that used it shipped an unterminated document: <main>, the shell
 * <div>, <body> and <html> all left hanging.
 *
 * Browsers repair that, but not identically to how it was meant to nest, and
 * the app shell depends on the nesting: <body> is a flex row of fixed height
 * with overflow hidden, and <main> is the one element inside it allowed to
 * scroll. When the tail is left to the parser, content can end up outside the
 * element that was supposed to contain it and is drawn below the shell, which
 * is the band of empty page under the settings screen.
 *
 * admin_footer.php is not this: it carries the sidebar and profile-menu
 * scripts, and closes nothing.
 */
?>
        </main>
    </div>
</body>

</html>
