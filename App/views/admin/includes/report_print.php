<?php

/**
 * report_print.php — the letterheaded, printable form of an admin export.
 *
 * Included instead of writing CSV when an export is asked for as ?format=pdf.
 *
 * It prints through the browser rather than through a PDF library, and that is
 * a deliberate choice rather than a shortcut. Every chart in this app is an
 * inline <svg> drawn in PHP (see admin/analytics.php), so printing keeps them
 * as vector — sharp at any zoom, and readable when the page is scaled. A PHP
 * PDF library would have meant rebuilding each chart as a bitmap to get it
 * into the file at all, and carrying ~15MB of vendor to do it.
 *
 * The letterhead repeats on EVERY printed page, not just the first: the header
 * and footer are position:fixed and sit in the margins that @page reserves for
 * them. Browsers redraw fixed elements once per page when printing, which is
 * the one case where that behaviour is what you want.
 *
 * Expects the including file to define:
 *   $report_title     string    e.g. "Reports & Analytics"
 *   $report_subtitle  string    what it covers, e.g. "1 Aug – 21 Sep 2026"
 *   $report_meta      array     label => value, for the strip under the title
 *   $report_body      callable  echoes the report itself
 *
 * Provides rpt_tiles(), rpt_table() and rpt_section() so the four exports lay
 * out the same way without repeating markup.
 */

if (!function_exists('rpt_tiles')) {
    /**
     * The headline figures. Each tile is ['label' => , 'value' => , 'hint' => ,
     * 'tone' => ok|warn|bad|null]; hint and tone are optional.
     */
    function rpt_tiles(array $tiles): void
    {
        if (!$tiles) return;
        echo '<div class="rpt-tiles">';
        foreach ($tiles as $t) {
            $tone = isset($t['tone']) ? ' is-' . htmlspecialchars((string)$t['tone']) : '';
            echo '<div class="rpt-tile' . $tone . '">';
            echo '<div class="rpt-tile-l">' . htmlspecialchars((string)($t['label'] ?? '')) . '</div>';
            echo '<div class="rpt-tile-v">' . htmlspecialchars((string)($t['value'] ?? '')) . '</div>';
            if (($t['hint'] ?? '') !== '') {
                echo '<div class="rpt-tile-h">' . htmlspecialchars((string)$t['hint']) . '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('rpt_table')) {
    /**
     * $rows is a list of lists. A cell may be a plain value, or
     * ['v' => value, 'num' => true] to right-align a figure.
     *
     * A header may carry 'w' => '18%' to set its column's share. The table
     * lays out fixed (see the CSS), so without any widths the columns divide
     * the page equally — which puts a "#" column on the same width as an
     * email address. Give widths to the tables where that matters.
     *
     * Prints an explicit "Nothing to show" rather than an empty frame: a table
     * with a head and no body reads as something that failed to load.
     */
    function rpt_table(array $headers, array $rows, string $empty = 'Nothing recorded for this period.'): void
    {
        if (!$rows) {
            echo '<p class="rpt-empty">' . htmlspecialchars($empty) . '</p>';
            return;
        }
        echo '<table class="rpt-table"><thead><tr>';
        foreach ($headers as $h) {
            $num   = is_array($h) && !empty($h['num']);
            $width = is_array($h) && !empty($h['w']) ? ' style="width:' . htmlspecialchars((string)$h['w'], ENT_QUOTES) . '"' : '';
            echo '<th' . ($num ? ' class="num"' : '') . $width . '>'
               . htmlspecialchars((string)(is_array($h) ? $h['v'] : $h)) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                $num = is_array($cell) && !empty($cell['num']);
                $val = is_array($cell) ? ($cell['v'] ?? '') : $cell;
                echo '<td' . ($num ? ' class="num"' : '') . '>' . htmlspecialchars((string)$val) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}

if (!function_exists('rpt_bars')) {
    /**
     * A horizontal bar chart, drawn as SVG so it prints as vector.
     *
     * $data is label => number. Nothing is scaled against a guessed maximum:
     * the longest bar is the largest value, and every bar is a true fraction
     * of it, so two bars side by side can be compared by eye honestly.
     */
    function rpt_bars(array $data, string $colour = '#0b2d6b'): void
    {
        $data = array_filter($data, fn($v) => is_numeric($v));
        if (!$data) {
            echo '<p class="rpt-empty">Nothing to chart for this period.</p>';
            return;
        }
        $max = max(1, max(array_map('abs', array_values($data))));
        $rowH = 20;
        $labelW = 150;
        $barW = 330;
        $W = $labelW + $barW + 46;
        $H = count($data) * $rowH + 8;

        echo '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Bar chart">';
        $y = 4;
        foreach ($data as $label => $value) {
            $w = (int) round(abs((float)$value) / $max * $barW);
            $label = (string)$label;
            $short = mb_strlen($label) > 26 ? mb_substr($label, 0, 25) . '…' : $label;
            echo '<text x="' . ($labelW - 6) . '" y="' . ($y + 12) . '" text-anchor="end" font-size="9" fill="#5b6273">'
                . htmlspecialchars($short) . '</text>';
            echo '<rect x="' . $labelW . '" y="' . ($y + 3) . '" width="' . max($w, 1) . '" height="12" rx="2" fill="' . $colour . '"/>';
            echo '<text x="' . ($labelW + max($w, 1) + 5) . '" y="' . ($y + 12) . '" font-size="9" fill="#1a1f2b">'
                . htmlspecialchars((string)$value) . '</text>';
            $y += $rowH;
        }
        echo '</svg>';
    }
}

if (!function_exists('rpt_columns')) {
    /**
     * Grouped columns over time. $rows is bucketLabel => [seriesName => n].
     * $series is seriesName => colour, and fixes the order.
     *
     * Every bucket is drawn even when it is empty — a gap in activity is a
     * finding, and dropping the empty ones would quietly redraw the timeline.
     */
    function rpt_columns(array $rows, array $series): void
    {
        if (!$rows) {
            echo '<p class="rpt-empty">Nothing to chart for this period.</p>';
            return;
        }
        $max = 1;
        foreach ($rows as $vals) {
            foreach ($series as $k => $_) { $max = max($max, (int)($vals[$k] ?? 0)); }
        }

        $W = 540; $H = 190;
        $padL = 30; $padR = 8; $padT = 10; $padB = 44;
        $plotW = $W - $padL - $padR;
        $plotH = $H - $padT - $padB;
        $n = count($rows);
        $slot = $plotW / max(1, $n);
        $barW = max(2, ($slot - 6) / max(1, count($series)));

        echo '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Activity over time">';
        // Three gridlines and their values, so a bar height can be read off.
        for ($i = 0; $i <= 2; $i++) {
            $v = (int) round($max * $i / 2);
            $y = $padT + $plotH - ($plotH * $i / 2);
            echo '<line x1="' . $padL . '" y1="' . $y . '" x2="' . ($W - $padR) . '" y2="' . $y . '" stroke="#e3e8f0"/>';
            echo '<text x="' . ($padL - 5) . '" y="' . ($y + 3) . '" text-anchor="end" font-size="8" fill="#8b93a5">' . $v . '</text>';
        }
        /*
         * Label roughly every eighth bucket, never all of them.
         *
         * A month of daily buckets drew thirty dates on top of each other and
         * came out as a black smear along the axis — worse than no axis, since
         * it looks like something rendered wrong. A date every few columns is
         * still enough to place a bar in time.
         */
        $every = max(1, (int) ceil($n / 8));
        $x = $padL;
        $i = 0;
        foreach ($rows as $label => $vals) {
            $bx = $x + 3;
            foreach ($series as $k => $colour) {
                $val = (int)($vals[$k] ?? 0);
                $h = (int) round($val / $max * $plotH);
                echo '<rect x="' . round($bx, 1) . '" y="' . round($padT + $plotH - $h, 1) . '" width="' . round($barW, 1)
                    . '" height="' . max($h, 0) . '" fill="' . $colour . '"/>';
                $bx += $barW;
            }
            // The last bucket is always worth labelling, but not when the
            // regular tick before it is close enough to overlap — two dates
            // printed on top of each other read as neither.
            $isTick = $i % $every === 0;
            $isLast = $i === $n - 1 && ($n - 1) % $every > ($every / 2);
            if ($isTick || $isLast) {
                // A bucket key is a date like 2026-08-23; the year repeats on
                // every tick and earns none of the room it takes.
                $label = (string)$label;
                $short = preg_match('~^\d{4}-(\d{2}-\d{2})$~', $label, $m) ? $m[1]
                    : (mb_strlen($label) > 7 ? mb_substr($label, -7) : $label);
                echo '<text x="' . round($x + $slot / 2, 1) . '" y="' . ($padT + $plotH + 12)
                    . '" text-anchor="middle" font-size="7.5" fill="#8b93a5">' . htmlspecialchars($short) . '</text>';
            }
            $x += $slot;
            $i++;
        }
        // Legend, or the colours mean nothing.
        $lx = $padL;
        $ly = $H - 12;
        foreach ($series as $k => $colour) {
            echo '<rect x="' . $lx . '" y="' . ($ly - 7) . '" width="8" height="8" rx="1.5" fill="' . $colour . '"/>';
            echo '<text x="' . ($lx + 12) . '" y="' . $ly . '" font-size="8" fill="#5b6273">' . htmlspecialchars((string)$k) . '</text>';
            $lx += 14 + mb_strlen((string)$k) * 4.6 + 12;
        }
        echo '</svg>';
    }
}

if (!function_exists('rpt_section')) {
    /** A titled block. Kept off a page break from its heading where it fits. */
    function rpt_section(string $title, callable $inner): void
    {
        echo '<section class="rpt-section"><h2>' . htmlspecialchars($title) . '</h2>';
        $inner();
        echo '</section>';
    }
}

$rpt_title    = $report_title    ?? 'Report';
$rpt_subtitle = $report_subtitle ?? '';
$rpt_meta     = $report_meta     ?? [];
$rpt_body     = $report_body     ?? function () {};

$rpt_header = asset('images/letterhead-header.png');
$rpt_footer = asset('images/letterhead-footer.jpg');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($rpt_title) ?> — PeerConnect</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <style>
        /*
         * Page geometry.
         *
         * The letterhead repeats through a wrapping table: browsers put a
         * <thead> at the top and a <tfoot> at the bottom of EVERY printed page
         * of that table, and reserve the space for them, so nothing can be
         * printed underneath either one.
         *
         * The first attempt used position:fixed with negative offsets into the
         * @page margins, which is the technique usually quoted for this. It
         * does not survive real pagination: the engine placed both banners at
         * their document position instead of repeating them, so the header
         * landed in the middle of page one on top of the session table and the
         * footer halfway down page two. A header that lands on the content is
         * worse than no header, so this does not go back to fixed positioning.
         */
        @page {
            size: A4 portrait;
            margin: 10mm 12mm;
        }

        :root {
            --navy: #0b2d6b;
            --ink: #1a1f2b;
            --muted: #5b6273;
            --line: #d8dde6;
            --tint: #f3f6fb;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'DM Sans', Arial, Helvetica, sans-serif;
            font-size: 10.5pt;
            line-height: 1.45;
            color: var(--ink);
            background: #e9edf3;
        }

        /* The repeating frame. Its cells carry the padding so the banners sit
           flush to the page edges the @page margin allows. */
        /*
         * table-layout: fixed, or the frame grows to whatever its widest
         * content wants and takes the letterhead with it: a ten-column report
         * measured 835px inside a 794px sheet, and the banner hung off the
         * right edge of the page. Fixed layout makes the frame the page's
         * width and nothing else's.
         */
        .rpt-page {
            width: 100%;
            max-width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
        }

        .rpt-page > thead { display: table-header-group; }
        .rpt-page > tfoot { display: table-footer-group; }
        .rpt-page > thead > tr > td,
        .rpt-page > tfoot > tr > td,
        .rpt-page > tbody > tr > td { padding: 0; border: 0; }

        /* Space between the banners and the report, so a table row never
           finishes hard against the letterhead. */
        .rpt-head { padding-bottom: 5mm; }
        .rpt-foot { padding-top: 5mm; }

        .rpt-head img,
        .rpt-foot img {
            display: block;
            width: 100%;
            height: auto;
        }

        /* ── Screen: show an A4 sheet, so what you see is what prints ── */
        .rpt-sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 18px auto 40px;
            padding: 12mm;
            background: #fff;
            box-shadow: 0 10px 30px rgba(16, 24, 40, .16);
        }

        .rpt-bar {
            position: sticky;
            top: 0;
            z-index: 5;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 18px;
            background: var(--navy);
            color: #fff;
        }

        .rpt-bar b { font-size: 14px; font-weight: 600; margin-right: auto; }

        .rpt-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border: 1px solid rgba(255, 255, 255, .35);
            border-radius: 9px;
            background: transparent;
            color: #fff;
            font: inherit;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .rpt-btn.primary { background: #fff; color: var(--navy); border-color: #fff; }
        .rpt-btn:hover { background: rgba(255, 255, 255, .16); }
        .rpt-btn.primary:hover { background: #eaf0fb; }
        .rpt-btn svg { width: 15px; height: 15px; }

        /* ── The report itself ── */
        .rpt-title {
            margin: 6mm 0 0;
            font-size: 17pt;
            font-weight: 700;
            color: var(--navy);
            text-align: center;
            letter-spacing: -.2px;
        }

        .rpt-sub {
            margin: 2mm 0 0;
            font-size: 10.5pt;
            color: var(--muted);
            text-align: center;
        }

        .rpt-meta {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 4mm 8mm;
            margin: 5mm 0 0;
            padding: 3mm 4mm;
            border-top: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
            background: var(--tint);
            font-size: 9pt;
        }

        .rpt-meta span { color: var(--muted); }
        .rpt-meta b { color: var(--ink); font-weight: 600; }

        .rpt-section { margin-top: 7mm; }

        .rpt-section h2 {
            margin: 0 0 3mm;
            padding-bottom: 1.5mm;
            border-bottom: 2px solid var(--navy);
            font-size: 11.5pt;
            font-weight: 700;
            color: var(--navy);
        }

        .rpt-tiles {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 3mm;
        }

        .rpt-tile {
            padding: 3mm;
            border: 1px solid var(--line);
            border-top: 3px solid var(--navy);
            border-radius: 2mm;
            background: var(--tint);
        }

        .rpt-tile.is-ok  { border-top-color: #17654B; }
        .rpt-tile.is-warn { border-top-color: #9A7100; }
        .rpt-tile.is-bad { border-top-color: #A6301F; }

        .rpt-tile-l { font-size: 8pt; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); font-weight: 600; }
        .rpt-tile-v { font-size: 16pt; font-weight: 700; color: var(--navy); line-height: 1.2; }
        .rpt-tile.is-ok .rpt-tile-v { color: #17654B; }
        .rpt-tile.is-warn .rpt-tile-v { color: #9A7100; }
        .rpt-tile.is-bad .rpt-tile-v { color: #A6301F; }
        .rpt-tile-h { font-size: 8pt; color: var(--muted); }

        /*
         * Also fixed: width:100% on a table is a floor, not a ceiling — long
         * unbroken content such as an email address pushes it past the page
         * and there is no sideways scroll on paper. The columns share what
         * there is, and anything too long for its share wraps.
         */
        .rpt-table {
            width: 100%;
            max-width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            font-size: 9pt;
        }

        .rpt-table th,
        .rpt-table td {
            padding: 2mm 2.5mm;
            border: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
            overflow-wrap: anywhere;
        }

        .rpt-table th {
            background: var(--navy);
            color: #fff;
            font-weight: 600;
            font-size: 8.5pt;
        }

        .rpt-table tbody tr:nth-child(even) { background: var(--tint); }
        .rpt-table .num { text-align: right; font-variant-numeric: tabular-nums; }

        .rpt-empty {
            margin: 0;
            padding: 4mm;
            border: 1px dashed var(--line);
            border-radius: 2mm;
            color: var(--muted);
            font-size: 9.5pt;
            text-align: center;
        }

        .rpt-charts {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4mm;
        }

        .rpt-chart {
            padding: 3mm;
            border: 1px solid var(--line);
            border-radius: 2mm;
        }

        .rpt-chart h3 {
            margin: 0 0 2mm;
            font-size: 9.5pt;
            font-weight: 600;
            color: var(--ink);
        }

        .rpt-chart svg { width: 100%; height: auto; display: block; }

        .rpt-note {
            margin-top: 7mm;
            padding-top: 2mm;
            border-top: 1px solid var(--line);
            font-size: 8pt;
            color: var(--muted);
            text-align: center;
        }

        /* ── Print ────────────────────────────────────────────────────── */
        @media print {
            body { background: #fff; }
            .rpt-bar { display: none; }

            .rpt-sheet {
                width: auto;
                min-height: 0;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }

            /* Neither banner may ever be split across two sheets. */
            .rpt-head,
            .rpt-foot,
            .rpt-page > thead > tr > td,
            .rpt-page > tfoot > tr > td {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            /* A heading stranded at the foot of a page, with its table over
               the fold, is the classic printed-report annoyance. */
            .rpt-section { break-inside: auto; }
            .rpt-section h2 { break-after: avoid; }
            .rpt-chart, .rpt-tile { break-inside: avoid; }
            .rpt-table thead { display: table-header-group; }
            .rpt-table tr { break-inside: avoid; }

            /* Chrome drops backgrounds when printing unless asked twice. */
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>

<body>
    <div class="rpt-bar">
        <b><?= htmlspecialchars($rpt_title) ?></b>
        <button type="button" class="rpt-btn primary" onclick="window.print()">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6M6 18H4v-6h16v6h-2M8 14h8v7H8v-7Z" />
            </svg>
            Save as PDF
        </button>
        <a class="rpt-btn" href="<?= htmlspecialchars($report_back ?? url('admin-dashboard')) ?>">Back</a>
    </div>

    <div class="rpt-sheet">
        <?php // thead and tfoot are what make the letterhead repeat per page. ?>
        <table class="rpt-page">
            <thead>
                <tr>
                    <td>
                        <header class="rpt-head">
                            <img src="<?= htmlspecialchars($rpt_header) ?>" alt="Republic of the Philippines — Nueva Ecija University of Science and Technology, College of Information and Communications Technology">
                        </header>
                    </td>
                </tr>
            </thead>

            <tfoot>
                <tr>
                    <td>
                        <footer class="rpt-foot">
                            <img src="<?= htmlspecialchars($rpt_footer) ?>" alt="NEUST vision, mission and accreditation">
                        </footer>
                    </td>
                </tr>
            </tfoot>

            <tbody>
                <tr>
                    <td>
                        <h1 class="rpt-title"><?= htmlspecialchars($rpt_title) ?></h1>
                        <?php if ($rpt_subtitle !== ''): ?>
                            <p class="rpt-sub"><?= htmlspecialchars($rpt_subtitle) ?></p>
                        <?php endif; ?>

                        <?php if ($rpt_meta): ?>
                            <div class="rpt-meta">
                                <?php foreach ($rpt_meta as $label => $value): ?>
                                    <div><span><?= htmlspecialchars((string)$label) ?>:</span> <b><?= htmlspecialchars((string)$value) ?></b></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php $rpt_body(); ?>

                        <p class="rpt-note">
                            Generated from PeerConnect. Every figure in this report is counted from the
                            system's own records for the period stated above.
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <script>
        // Offered as a convenience, not a requirement — the report is readable
        // on screen and the browser's own print command does the same thing.
        if (new URLSearchParams(location.search).get('print') === '1') {
            window.addEventListener('load', () => setTimeout(() => window.print(), 300));
        }
    </script>
</body>

</html>
