<?php
/**
 * onboarding_catalog.php — the fixed option lists behind the first-login
 * questionnaire, and the single source of truth for tag matching.
 *
 * Both roles pick from these same lists, so two users who chose the same
 * option end up with byte-identical `user_tags.tag` values. That is what lets
 * mentor/mentee overlap be counted in SQL without any fuzzy matching — see
 * MentorScoreService::sharedTags().
 *
 * The three steps map onto the existing `user_tags.tag_type` enum. The axis is
 * shared by both roles; the role decides which direction it reads in:
 *   interest -> degree program        both: the program they study / teach
 *   skill    -> teaching skills       mentee: wants to develop
 *                                     mentor: can help others develop
 *   learn    -> Gen-Ed subject areas  mentee: wants to learn
 *                                     mentor: can mentor in
 *
 * `tag` is what gets stored and matched on; `label`/`sub` are display only.
 * Never re-word an existing `tag` — that orphans every row already saved
 * against it. Add a new entry instead.
 *
 * WORDING: each step carries `title_mentee`/`title_mentor` alongside
 * `sub_mentee`/`sub_mentor`, because the same list means opposite things to
 * the two roles — a mentee is saying "I want help with this", a mentor "I can
 * teach this". One shared heading ("Select up to 5 General Education areas")
 * said neither, so a mentor could reasonably read step 3 as subjects *they*
 * wanted to learn and tick the wrong ones. Everything a mentor ticks there is
 * offered to mentees as something that mentor can teach, so the direction has
 * to be unmistakable in the heading, not just the small print under it.
 *
 * `title` is kept as the fallback for any step that has no role-specific one.
 */

/** "Select up to 5" — the per-step cap, enforced in the browser and again on save. */
const PC_ONB_MAX = 5;

/** Pastel circle + stroke pairs, taken from the reference screens. */
const PC_ONB_TINTS = [
    'pink'   => ['#FFE4EF', '#E8368F'],
    'blue'   => ['#DCEBFF', '#087FC1'],
    'green'  => ['#DDF5E6', '#12A150'],
    'amber'  => ['#FFF0D4', '#E8A33D'],
    'purple' => ['#EAE4FF', '#7C5CE0'],
    'teal'   => ['#D6F3F0', '#0FA3A3'],
    'red'    => ['#FFE0DC', '#D9534F'],
];

/** Icon paths for the option tiles. 24x24, stroke-based, no fill. */
const PC_ONB_ICONS = [
    'cap'       => '<path stroke-linecap="round" stroke-linejoin="round" d="m12 4 9 4.5-9 4.5-9-4.5L12 4Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6.5 10.5V16c0 1.4 2.5 2.5 5.5 2.5s5.5-1.1 5.5-2.5v-5.5"/>',
    'chat'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.5 6.5A2 2 0 0 1 5.5 4.5h7a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H8l-4.5 3v-3a2 2 0 0 1 0-4Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M17 9.5h1a2 2 0 0 1 2 2v4a2 2 0 0 1-1.5 1.9V21l-3-2.5h-3"/>',
    'globe'     => '<circle cx="12" cy="12" r="8.5"/><path stroke-linecap="round" d="M3.5 12h17"/><path stroke-linecap="round" d="M12 3.5c2.2 2.4 3.3 5.4 3.3 8.5s-1.1 6.1-3.3 8.5c-2.2-2.4-3.3-5.4-3.3-8.5S9.8 5.9 12 3.5Z"/>',
    'users'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.5 19c0-2.1-1.7-3.8-3.8-3.8h-.4C9.2 15.2 7.5 16.9 7.5 19"/><circle cx="11.5" cy="9" r="3.2"/><path stroke-linecap="round" stroke-linejoin="round" d="M17.5 12.2a2.6 2.6 0 0 0 0-5M20.5 18c0-1.6-1-3-2.5-3.5"/>',
    'leaf'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M19 5c0 8-4.6 12-9 12a4.6 4.6 0 0 1-4.6-4.6C5.4 8 10 5 19 5Z"/><path stroke-linecap="round" d="M15.5 8.5 6 19"/>',
    'atom'      => '<circle cx="12" cy="12" r="2.2"/><ellipse cx="12" cy="12" rx="9" ry="4" transform="rotate(45 12 12)"/><ellipse cx="12" cy="12" rx="9" ry="4" transform="rotate(-45 12 12)"/>',
    'chart'     => '<path stroke-linecap="round" d="M5 19V11M12 19V5M19 19v-5"/>',
    'heart'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 20s-7.5-4.4-7.5-9.3A4.2 4.2 0 0 1 12 8a4.2 4.2 0 0 1 7.5 2.7C19.5 15.6 12 20 12 20Z"/>',
    'doc'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5M9 13h6M9 17h4"/>',
    'laptop'    => '<rect x="4" y="5" width="16" height="11" rx="2"/><path stroke-linecap="round" d="M2.5 19.5h19"/>',
    'pencil'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.5 18 15 6.5l3 3L6.5 21h-3v-3Z"/><path stroke-linecap="round" d="m12.5 9 3 3"/>',
    'book'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.5C10.4 5.2 8.4 4.5 6 4.5H4v13h2c2.4 0 4.4.7 6 2 1.6-1.3 3.6-2 6-2h2v-13h-2c-2.4 0-4.4.7-6 2Z"/><path stroke-linecap="round" d="M12 6.5v13"/>',
    'bulb'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.5 17.5a5.5 5.5 0 1 1 5 0V19a1.5 1.5 0 0 1-1.5 1.5h-2A1.5 1.5 0 0 1 9.5 19v-1.5Z"/><path stroke-linecap="round" d="M9.5 17.5h5"/>',
    'puzzle'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M10 4.5a1.8 1.8 0 0 1 3.6 0V6H17a1 1 0 0 1 1 1v3.4h1.5a1.8 1.8 0 0 1 0 3.6H18V17a1 1 0 0 1-1 1h-3.4v1.5a1.8 1.8 0 0 1-3.6 0V18H7a1 1 0 0 1-1-1v-3.4H4.5a1.8 1.8 0 0 1 0-3.6H6V7a1 1 0 0 1 1-1h3V4.5Z"/>',
    'target'    => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1"/>',
    'board'     => '<rect x="3.5" y="4.5" width="17" height="11" rx="1.6"/><path stroke-linecap="round" d="M12 15.5V20M8.5 20h7"/><path stroke-linecap="round" stroke-linejoin="round" d="m7.5 12 2.5-3 2.5 2.5 4-4.5"/>',
    'briefcase' => '<rect x="3.5" y="7.5" width="17" height="12" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 7.5V6a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 6v1.5M3.5 12.5h17"/>',
    'dumbbell'  => '<path stroke-linecap="round" d="M6.5 8v8M4 10v4M17.5 8v8M20 10v4M6.5 12h11"/>',
    'dots'      => '<circle cx="7" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="17" cy="12" r="1.4"/>',
];

return [
    // ── Step 1 ───────────────────────────────────────────────────────────
    'interest' => [
        'title'        => 'Select up to 5 education interests',
        'title_mentee' => 'Which degree programmes interest you?',
        'title_mentor' => 'Which degree programmes can you speak to?',
        'sub_mentee'   => 'Pick up to 5. A mentor from the same programme has already taken the subjects you are taking, so they know the coursework. This is the lightest of the three signals — the next two matter more.',
        'sub_mentor'   => 'Pick up to 5 you studied or teach. Mentees in the same programme are matched to you a little more strongly. This is the lightest of the three signals — the next two matter more.',
        'icon'       => 'cap',
        'columns'    => 2,
        'other'      => false,
        'items'      => [
            ['tag' => 'Bachelor of Elementary Education',                                                'label' => 'Bachelor of Elementary Education',                         'sub' => '',                                  'icon' => 'cap', 'tint' => 'blue'],
            ['tag' => 'Bachelor of Special Needs Education (BSNED), Major in Early Childhood Education',  'label' => 'Bachelor of Special Needs Education (BSNED)',               'sub' => 'Major in Early Childhood Education', 'icon' => 'cap', 'tint' => 'pink'],
            ['tag' => 'Bachelor of Technology and Livelihood Education (BTLED), Major in Home Economics', 'label' => 'Bachelor of Technology and Livelihood Education (BTLED)',   'sub' => 'Major in Home Economics',            'icon' => 'cap', 'tint' => 'green'],
            ['tag' => 'Bachelor of Secondary Education (BSEd), Major in Mathematics',                     'label' => 'Bachelor of Secondary Education (BSEd)',                    'sub' => 'Major in Mathematics',               'icon' => 'cap', 'tint' => 'purple'],
            ['tag' => 'Bachelor of Secondary Education (BSEd), Major in English',                         'label' => 'Bachelor of Secondary Education (BSEd)',                    'sub' => 'Major in English',                   'icon' => 'cap', 'tint' => 'amber'],
            ['tag' => 'Bachelor of Secondary Education (BSEd), Major in Science',                         'label' => 'Bachelor of Secondary Education (BSEd)',                    'sub' => 'Major in Science',                   'icon' => 'cap', 'tint' => 'red'],
            ['tag' => 'Bachelor of Secondary Education (BSEd), Major in Social Studies',                  'label' => 'Bachelor of Secondary Education (BSEd)',                    'sub' => 'Major in Social Studies',            'icon' => 'cap', 'tint' => 'teal'],
            ['tag' => 'Bachelor of Science in Industrial Education',                                      'label' => 'Bachelor of Science in Industrial Education',               'sub' => '',                                  'icon' => 'cap', 'tint' => 'blue'],
            ['tag' => 'Physical Education',                                                              'label' => 'Physical Education',                                        'sub' => '',                                  'icon' => 'cap', 'tint' => 'purple'],
        ],
    ],

    // ── Step 2 ───────────────────────────────────────────────────────────
    'skill' => [
        'title'        => 'Select up to 5 skills',
        'title_mentee' => 'Which skills do you want to build?',
        'title_mentor' => 'Which skills can you coach someone in?',
        'sub_mentee'   => 'Pick up to 5 you want to get better at. We look for mentors who said they can coach these exact skills, so choose what you actually want help with rather than what sounds good.',
        'sub_mentor'   => 'Pick up to 5 you could genuinely help a mentee improve — not everything you have heard of. These are compared word for word with what mentees ask for, so an honest list is what gets you the right students.',
        'icon'       => 'board',
        'columns'    => 3,
        'other'      => false,
        'items'      => [
            ['tag' => 'Lesson Planning',                'label' => 'Lesson Planning',                'sub' => '', 'icon' => 'book',      'tint' => 'blue'],
            ['tag' => 'Classroom Management',           'label' => 'Classroom Management',           'sub' => '', 'icon' => 'users',     'tint' => 'pink'],
            ['tag' => 'Communication Skills',           'label' => 'Communication Skills',           'sub' => '', 'icon' => 'chat',      'tint' => 'green'],
            ['tag' => 'Educational Technology',         'label' => 'Educational Technology',         'sub' => '', 'icon' => 'laptop',    'tint' => 'purple'],
            ['tag' => 'Assessment and Evaluation',      'label' => 'Assessment and Evaluation',      'sub' => '', 'icon' => 'chart',     'tint' => 'amber'],
            ['tag' => 'Special Needs Strategies',       'label' => 'Special Needs Strategies',       'sub' => '', 'icon' => 'puzzle',    'tint' => 'teal'],
            ['tag' => 'Child Development',              'label' => 'Child Development',              'sub' => '', 'icon' => 'heart',     'tint' => 'pink'],
            ['tag' => 'Creative Teaching Strategies',   'label' => 'Creative Teaching Strategies',   'sub' => '', 'icon' => 'bulb',      'tint' => 'green'],
            ['tag' => 'Student Engagement',             'label' => 'Student Engagement',             'sub' => '', 'icon' => 'users',     'tint' => 'purple'],
            ['tag' => 'Research Skills',                'label' => 'Research Skills',                'sub' => '', 'icon' => 'doc',       'tint' => 'blue'],
            ['tag' => 'Curriculum Development',         'label' => 'Curriculum Development',         'sub' => '', 'icon' => 'cap',       'tint' => 'pink'],
            ['tag' => 'Problem Solving',                'label' => 'Problem Solving',                'sub' => '', 'icon' => 'target',    'tint' => 'amber'],
            ['tag' => 'Leadership',                     'label' => 'Leadership',                     'sub' => '', 'icon' => 'board',     'tint' => 'teal'],
            ['tag' => 'Professional Development',       'label' => 'Professional Development',       'sub' => '', 'icon' => 'briefcase', 'tint' => 'purple'],
            ['tag' => 'Collaboration',                  'label' => 'Collaboration',                  'sub' => '', 'icon' => 'users',     'tint' => 'pink'],
            ['tag' => 'Inclusive Education',            'label' => 'Inclusive Education',            'sub' => '', 'icon' => 'globe',     'tint' => 'amber'],
            ['tag' => 'Physical Education Instruction', 'label' => 'Physical Education Instruction', 'sub' => '', 'icon' => 'dumbbell',  'tint' => 'blue'],
        ],
    ],

    // ── Step 3 ───────────────────────────────────────────────────────────
    'learn' => [
        'title'        => 'Select up to 5 General Education areas',
        'title_mentee' => 'Which subjects do you want help with?',
        'title_mentor' => 'Which subjects can you mentor?',
        'sub_mentee'   => 'Pick up to 5 you are finding hard or want to go deeper in. This is the strongest signal in matching: a mentor who teaches one of these is put ahead of everyone else on your Matching page.',
        'sub_mentor'   => 'Pick up to 5 you are confident teaching. This is the strongest signal in matching: a mentee asking for one of these sees you first, so only tick what you could sit down and explain.',
        'icon'       => 'book',
        'columns'    => 3,
        'other'      => true,
        'items'      => [
            ['tag' => 'Mathematics in the Modern World (MMW)',             'label' => 'Mathematics',             'sub' => 'in the Modern World (MMW)',  'icon' => 'chat',   'tint' => 'pink'],
            ['tag' => 'Purposive Communication (PCOM)',                    'label' => 'Purposive',               'sub' => 'Communication (PCOM)',       'icon' => 'globe',  'tint' => 'blue'],
            ['tag' => 'Understanding the Self (UTS)',                      'label' => 'Understanding',           'sub' => 'the Self (UTS)',             'icon' => 'users',  'tint' => 'green'],
            ['tag' => 'Readings in Philippine History (RPH)',              'label' => 'Readings in',             'sub' => 'Philippine History (RPH)',   'icon' => 'users',  'tint' => 'amber'],
            ['tag' => 'Life Science (LSCI)',                               'label' => 'Life Science',            'sub' => '(LSCI)',                     'icon' => 'leaf',   'tint' => 'purple'],
            ['tag' => 'Physical Science (PSCI)',                           'label' => 'Physical Science',        'sub' => '(PSCI)',                     'icon' => 'atom',   'tint' => 'pink'],
            ['tag' => 'Art Appreciation (ARTS)',                           'label' => 'Art Appreciation',        'sub' => '(ARTS)',                     'icon' => 'chart',  'tint' => 'green'],
            ['tag' => 'Ethics (ETHICS)',                                   'label' => 'Ethics',                  'sub' => '(ETHICS)',                   'icon' => 'heart',  'tint' => 'blue'],
            ['tag' => 'The Contemporary World (TCW)',                      'label' => 'The Contemporary',        'sub' => 'World (TCW)',                'icon' => 'doc',    'tint' => 'amber'],
            ['tag' => 'Information Management (IM)',                       'label' => 'Information',             'sub' => 'Management (IM)',            'icon' => 'laptop', 'tint' => 'pink'],
            ['tag' => 'Science, Technology and Society (STS)',             'label' => 'Science, Technology',     'sub' => 'and Society (STS)',          'icon' => 'leaf',   'tint' => 'blue'],
            ['tag' => 'Filipino sa Kontekstong Pangkomunikasyon (FILKOM)', 'label' => 'Filipino sa Kontekstong', 'sub' => 'Pangkomunikasyon (FILKOM)',  'icon' => 'pencil', 'tint' => 'green'],
            ['tag' => 'The Life and Works of Rizal (RIZAL)',               'label' => 'The Life and Works',      'sub' => 'of Rizal (RIZAL)',           'icon' => 'book',   'tint' => 'amber'],
            ['tag' => 'Gender and Society (GES)',                          'label' => 'Gender and Society',      'sub' => '(GES)',                      'icon' => 'heart',  'tint' => 'pink'],
            ['tag' => 'Peace Education (PEACE)',                           'label' => 'Peace Education',         'sub' => '(PEACE)',                    'icon' => 'bulb',   'tint' => 'purple'],
            ['tag' => 'Environmental Science (ENSCI)',                     'label' => 'Environmental',           'sub' => 'Science (ENSCI)',            'icon' => 'globe',  'tint' => 'blue'],
        ],
    ],
];
