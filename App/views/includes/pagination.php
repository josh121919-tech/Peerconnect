<?php

/**
 * pagination.php — the one pager used by every paged list in the app.
 *
 * Before this there were seven of them: .ss-pages, .um-pages, .nf-pages,
 * .aw-pager, .bh-pager, .ci-pager and a pair of ←Prev / Next→ buttons on the
 * mentor's own lists. Same job, seven sets of markup and seven sets of CSS,
 * each drifting a little further from the others — one showed every page
 * number however many there were, one showed a window of five, one showed
 * nothing but two arrows.
 *
 * The styling lives in public/css/pc-app.css and, because the admin panel
 * keeps its own stylesheet, again in public/css/pc-admin.css. Both are the
 * same rules; edit them together.
 */

if (!function_exists('pc_pagination')) {

    /**
     * Render a pager.
     *
     * @param int      $page       the page being shown, 1-based
     * @param int      $totalPages how many there are
     * @param callable $href       fn(int $page): string — the URL for a page.
     *                             The caller builds it, because every list
     *                             carries different filters that paging must
     *                             not drop.
     * @param array    $opt        'summary' — text shown beside the pager
     *                             ("Showing 1–20 of 84"); given one, the pager
     *                             is wrapped in a row with it. Without one,
     *                             only the pager itself is emitted, so a page
     *                             that already has its own footer can drop it
     *                             straight in.
     *                             'label' — the nav's accessible name.
     */
    function pc_pagination(int $page, int $totalPages, callable $href, array $opt = []): void
    {
        if ($totalPages < 2) {
            return;
        }

        $page = max(1, min($page, $totalPages));
        $e    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        /*
         * Three numbers around the current page, plus the first and last
         * however far away they are. A list of forty pages is unusable as
         * forty buttons, and the two a reader actually wants from the far
         * end — back to the start, on to the end — are the ones a plain
         * window drops.
         */
        $win = 3;
        $lo  = max(1, min($page - 1, $totalPages - $win + 1));
        $hi  = min($totalPages, $lo + $win - 1);
        $lo  = max(1, $hi - $win + 1);

        $items = [];
        if ($lo > 1) {
            $items[] = 1;
            if ($lo > 2) $items[] = '…';
        }
        for ($i = $lo; $i <= $hi; $i++) $items[] = $i;
        if ($hi < $totalPages) {
            if ($hi < $totalPages - 1) $items[] = '…';
            $items[] = $totalPages;
        }

        $summary = trim((string)($opt['summary'] ?? ''));
        $label   = $opt['label'] ?? 'Pagination';

        // A step that would go off either end is drawn, but as a dead
        // control — the pager keeps the same width on every page instead of
        // shifting under the cursor as the arrows come and go.
        $step = function (int $target, string $glyph, string $name) use ($page, $totalPages, $href, $e) {
            $dead = $target < 1 || $target > $totalPages || $target === $page;
            if ($dead) {
                echo '<span class="pc-page-btn is-off" aria-hidden="true">' . $glyph . '</span>';
                return;
            }
            echo '<a class="pc-page-btn" href="' . $e($href($target)) . '" aria-label="' . $e($name) . '">' . $glyph . '</a>';
        };

        if ($summary !== '') {
            echo '<div class="pc-pagebar">';
            echo '<span class="pc-pagebar-note">' . $e($summary) . '</span>';
        }
        ?>
        <nav class="pc-pagination" aria-label="<?= $e($label) ?>">
            <?php
            $step(1,         '&laquo;',  'First page');
            $step($page - 1, '&lsaquo;', 'Previous page');

            foreach ($items as $it) {
                if ($it === '…') {
                    echo '<span class="pc-page-ellipsis" aria-hidden="true">&hellip;</span>';
                    continue;
                }
                if ($it === $page) {
                    echo '<span class="pc-page-btn is-active" aria-current="page">' . $it . '</span>';
                    continue;
                }
                echo '<a class="pc-page-btn" href="' . $e($href($it)) . '" aria-label="Page ' . $it . '">' . $it . '</a>';
            }

            $step($page + 1,  '&rsaquo;', 'Next page');
            $step($totalPages, '&raquo;', 'Last page');
            ?>
        </nav>
        <?php
        if ($summary !== '') {
            echo '</div>';
        }
    }
}
