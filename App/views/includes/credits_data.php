<?php

/**
 * credits_data.php — who made PeerConnect, and the picture each of them has.
 *
 * The four people and what they did are fixed: this is a record of who built
 * the thing, not a directory that changes as accounts come and go. Nobody on
 * this list needs an account on the site, and having one would not put them
 * here.
 *
 * The pictures are the one part that changes, so they are the one part kept
 * in the database — one settings row per person, holding the path the upload
 * wrote. No new table and no migration: `settings` already exists everywhere,
 * including on the live server, which is the whole reason for putting them
 * there rather than in a table of their own.
 *
 * See App/views/admin/credits_photo.php for the upload, and
 * App/views/includes/credits_ui.php for the page these feed.
 */

if (!defined('PC_CREDITS_TEAM')) {
    /**
     * Surname-first, as the college writes a name, and the contribution each
     * person actually made. Keep the slug stable — it is half of the settings
     * key a picture is stored under, so changing one orphans that picture.
     */
    define('PC_CREDITS_TEAM', [
        ['slug' => 'palad',    'name' => 'Palad, Josh Emil D.',    'role' => 'Full-Stack Developer',  'tags' => ['UI/UX Designer', 'System Developer']],
        ['slug' => 'maglanoc', 'name' => 'Maglanoc, Hazel Kim A.', 'role' => 'Research Paper',        'tags' => ['Documentation']],
        ['slug' => 'domingo',  'name' => 'Domingo, Miko D.',       'role' => 'Research Paper',        'tags' => ['Documentation']],
        ['slug' => 'venus',    'name' => 'Venus, Areown Dapne E.', 'role' => 'Research Paper',        'tags' => ['Documentation']],
    ]);
}

if (!function_exists('pc_credit_photo_key')) {
    /** The settings key one person's picture is stored under. */
    function pc_credit_photo_key(string $slug): string
    {
        return 'credits_photo_' . $slug;
    }
}

if (!function_exists('pc_credits_team')) {
    /**
     * The team, each with their picture if one has been uploaded.
     *
     * A person with no picture yet gets an empty string, which is what
     * pc_avatar() wants — it falls back to their initials, exactly as the
     * rest of the site does for a member who has not set one.
     */
    function pc_credits_team(mysqli $con): array
    {
        require_once __DIR__ . '/settings_store.php';

        $out = [];
        foreach (PC_CREDITS_TEAM as $person) {
            $person['photo'] = pc_setting($con, pc_credit_photo_key($person['slug']));
            $out[] = $person;
        }
        return $out;
    }
}

if (!function_exists('pc_credits_project')) {
    /**
     * The four facts about the project itself.
     *
     * The name and the tagline come from System Settings rather than being
     * written in here, so renaming the platform renames it on this page too
     * instead of leaving one screen still calling it something else.
     */
    function pc_credits_project(mysqli $con): array
    {
        require_once __DIR__ . '/settings_store.php';

        return [
            'name'    => pc_setting($con, 'platform_name', 'PeerConnect'),
            'tagline' => pc_setting($con, 'platform_tagline', 'Mentoring. Growing. Together.'),
            'about'   => 'A peer mentoring platform designed for the NEUST College of Education.',
            'facts'   => [
                ['icon' => 'calendar', 'value' => '2026',  'label' => 'Project'],
                ['icon' => 'globe',    'value' => 'Web',   'label' => 'Platform'],
                ['icon' => 'school',   'value' => 'NEUST', 'label' => 'COEd'],
                ['icon' => 'people',   'value' => 'Peer',  'label' => 'Mentoring'],
            ],
            /*
             * What the site is actually built with, grouped by the job each
             * thing does. Not a wish list: every entry was read off the
             * codebase — composer.json for the libraries, the page shells for
             * the front-end, App/views/admin/settings_integrations.php for
             * the services, and the server it runs on for the rest.
             *
             * Deliberately absent: a charting library (the graphs are drawn
             * by hand in SVG and CSS) and a PDF library (an export is a
             * letterheaded page the browser prints). Naming either would be
             * claiming something this project does not use.
             */
            'tech'    => [
                'Frontend'     => ['HTML5', 'CSS3', 'JavaScript', 'Tailwind CSS', 'Google Fonts'],
                'Backend'      => ['PHP 8.2', 'Composer'],
                'Database'     => ['MariaDB / MySQL', 'mysqli'],
                'Framework'    => ['Custom PHP router', 'Repositories & services'],
                'Libraries'    => ['PHPMailer', 'Google API Client', 'phpdotenv', 'web-push', 'PDFParser'],
                'Integrations' => ['Google Sign-In', 'Google Calendar', 'reCAPTCHA', 'Jitsi (JaaS)', 'SMTP'],
                'Platform'     => ['Apache', 'LiteSpeed', 'PWA', 'Service Worker', 'Web Push'],
                'Tools'        => ['Git', 'XAMPP', 'cPanel'],
            ],
        ];
    }
}
