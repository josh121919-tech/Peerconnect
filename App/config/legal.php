<?php

/**
 * legal.php — the Terms of Service and Privacy Policy shown by the reader in
 * App/views/includes/legal_modal.php.
 *
 * ── HOW TO FILL THIS IN ──────────────────────────────────────────────────
 * Paste the real text into 'sections' below. Each entry is
 * ['heading' => '...', 'body' => ['paragraph', 'paragraph', ...]].
 * Plain text only — it is escaped on output, so nothing here can inject
 * markup into the page. Set 'effective' to the date the document takes
 * effect, then the reader stops showing its "not published yet" notice on
 * its own; no code change is needed.
 *
 * Until then 'sections' is deliberately left empty. Placeholder legalese
 * would read as though the school had agreed to terms nobody wrote, which
 * is worse than plainly saying the document is still being prepared.
 */

return [
    'terms' => [
        'title'     => 'Terms of Service',
        'effective' => '',   // e.g. '2026-09-30'
        'sections'  => [
            // ['heading' => 'Using PeerConnect', 'body' => ['...']],
        ],
    ],

    'privacy' => [
        'title'     => 'Privacy Policy',
        'effective' => '',
        'sections'  => [
            // ['heading' => 'What we collect', 'body' => ['...']],
        ],
    ],
];
