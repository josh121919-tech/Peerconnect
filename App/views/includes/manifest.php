<?php

/**
 * manifest.php — the web app manifest, built for this install.
 *
 * This used to be a static public/manifest.json with the /case/case folder and
 * two hashed route links written into it. Both break once the app is served
 * from another folder or with another ROUTE_SECRET_KEY, which a live site
 * should have. Served through the router, so every address comes from
 * BASE_URL and url(). Needs no database or session.
 */

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$pwa_icon = BASE_URL . '/public/icons/icon.svg';

echo json_encode([
    'name'             => 'PeerConnect — NEUST Mentorship',
    'short_name'       => 'PeerConnect',
    'description'      => 'Connect with mentors and mentees at NEUST. Book sessions, track progress, and grow together.',
    'start_url'        => BASE_URL . '/',
    'scope'            => BASE_URL . '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'theme_color'      => '#023047',
    'background_color' => '#EDEDED',
    'lang'             => 'en-PH',
    'categories'       => ['education', 'productivity'],
    'icons'            => [
        ['src' => $pwa_icon, 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
    ],
    'shortcuts'        => [
        [
            'name'       => 'Find a Mentor',
            'short_name' => 'Explore',
            'url'        => url('mentee-find'),
            'icons'      => [['src' => $pwa_icon, 'sizes' => 'any']],
        ],
        [
            'name'       => 'My Sessions',
            'short_name' => 'Sessions',
            'url'        => url('mentee-sessions'),
            'icons'      => [['src' => $pwa_icon, 'sizes' => 'any']],
        ],
    ],
    'prefer_related_applications' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
