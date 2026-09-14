<?php
/**
 * __seed_sample.php — inserts a labelled sample cohort so the data flow
 * through PeerConnect can be traced end to end.
 *
 * Everything it writes is identifiable: every account uses an @example.com
 * address, which RFC 2606 reserves precisely so it can never be delivered to
 * a real inbox. __seed_teardown.php removes all of it.
 *
 * Run from the CLI:  php __seed_sample.php
 */

// CLI only. These files sit in the web root, where .htaccess serves any .php
// that actually exists — so without this a plain GET to the teardown URL would
// delete the whole sample cohort, and a GET to the seeder would duplicate it.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Asia/Manila');
$con = new mysqli('localhost', 'root', '', 'cs');
if ($con->connect_error) { exit("db: " . $con->connect_error . "\n"); }
$con->set_charset('utf8mb4');

const PW = 'SamplePass!2026';

function q(mysqli $con, string $sql, string $types = '', array $args = []): int
{
    $st = $con->prepare($sql);
    if (!$st) { throw new RuntimeException($con->error . " -- " . $sql); }
    if ($types !== '') { $st->bind_param($types, ...$args); }
    if (!$st->execute()) { throw new RuntimeException($st->error . " -- " . $sql); }
    $id = $con->insert_id;
    $st->close();
    return $id;
}

$con->begin_transaction();

try {
    $hash = password_hash(PW, PASSWORD_DEFAULT);

    // ── 1. Accounts ───────────────────────────────────────────────────
    // firstname, lastname, email, role, course, year, club, bio
    $people = [
        ['Elena',  'Ramos',   'elena.ramos@example.com',   'mentor',
         'Bachelor of Secondary Education (BSED) Major in Mathematics', '4th Year', 'Mathematics Club',
         'Fourth-year maths student. I tutor algebra and trigonometry, and I like starting from what you already know.'],
        ['Tomas',  'Reyes',   'tomas.reyes@example.com',   'mentor',
         'Bachelor of Secondary Education (BSED) Major in Science', '3rd Year', 'Science Club',
         'Science major focused on general chemistry and physics problem sets.'],
        ['Ana',    'Cruz',    'ana.cruz@example.com',      'mentee',
         'Bachelor of Elementary Education (BEED)', '2nd Year', 'Elementary Education Club', ''],
        ['Ben',    'Santos',  'ben.santos@example.com',    'mentee',
         'Bachelor of Secondary Education (BSED) Major in Mathematics', '1st Year', 'Mathematics Club', ''],
        ['Carla',  'Lim',     'carla.lim@example.com',     'mentee',
         'Bachelor of Secondary Education (BSED) Major in Science', '2nd Year', 'Science Club', ''],
        ['Diego',  'Flores',  'diego.flores@example.com',  'mentee',
         'Bachelor of Technology and Livelihood Education (BTLED)', '3rd Year', 'Industrial Education Club', ''],
    ];

    $id = [];   // firstname => user_id
    foreach ($people as [$fn, $ln, $em, $role, $course, $year, $club, $bio]) {
        $uid = q($con,
            "INSERT INTO users (firstname, lastname, email, role, verified, status, created_at)
             VALUES (?, ?, ?, ?, 1, 'active', ?)",
            'sssss', [$fn, $ln, $em, $role, date('Y-m-d H:i:s', strtotime('-40 days'))]);
        $id[$fn] = $uid;

        q($con, "INSERT INTO passwords (user_id, password_hash) VALUES (?, ?)", 'is', [$uid, $hash]);

        // onboarded_at set: both dashboards send a member to the questionnaire
        // until this exists, so without it none of these accounts could reach
        // the pages this data is meant to illustrate.
        q($con,
            "INSERT INTO profile (user_id, full_name, student_id, course, year_level, club, bio, visibility, onboarded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'everyone', ?)",
            'isssssss',
            [$uid, "$fn $ln", '2026-' . (1000 + $uid), $course, $year, $club, $bio,
             date('Y-m-d H:i:s', strtotime('-39 days'))]);

        q($con,
            "INSERT INTO user_verifications (user_id, full_name, student_id, course, year_level, club, status, submitted_at, reviewed_at)
             VALUES (?, ?, ?, ?, ?, ?, 'approved', ?, ?)",
            'isssssss',
            [$uid, "$fn $ln", '2026-' . (1000 + $uid), $course, $year, $club,
             date('Y-m-d H:i:s', strtotime('-39 days')), date('Y-m-d H:i:s', strtotime('-38 days'))]);

        q($con, "INSERT INTO privacy_settings (user_id, personalized_recommendations, share_activity, third_party_integrations) VALUES (?, 1, 1, 0)", 'i', [$uid]);
        q($con, "INSERT INTO notification_preferences (user_id, session_requests, session_reminders, feedback_received, messages) VALUES (?, 1, 1, 1, 1)", 'i', [$uid]);
    }

    // Expertise tags — Find a Mentor and Recommended for You read these.
    $tags = [
        ['Elena', 'skill', 'Algebra'], ['Elena', 'skill', 'Trigonometry'], ['Elena', 'skill', 'Statistics'],
        ['Tomas', 'skill', 'General Chemistry'], ['Tomas', 'skill', 'Physics'],
        ['Ana', 'learn', 'Algebra'], ['Ana', 'interest', 'Lesson planning'],
        ['Ben', 'learn', 'Algebra'], ['Ben', 'learn', 'Trigonometry'],
        ['Carla', 'learn', 'General Chemistry'],
        ['Diego', 'learn', 'Physics'], ['Diego', 'interest', 'Workshop safety'],
    ];
    foreach ($tags as [$who, $type, $tag]) {
        q($con, "INSERT INTO user_tags (user_id, tag_type, tag, created_at) VALUES (?, ?, ?, NOW())",
           'iss', [$id[$who], $type, $tag]);
    }

    // What each mentee says they want — drives matching on Find a Mentor.
    $prefs = [
        ['Ana',   'Algebra',           'Pass the midterm and stop freezing on word problems.', 'beginner',     'Weekday afternoons', 'structured', 'one_on_one'],
        ['Ben',   'Trigonometry',      'Understand the unit circle well enough to teach it.',  'beginner',     'Weekends',           'casual',     'any'],
        ['Carla', 'General Chemistry', 'Get comfortable with stoichiometry.',                  'intermediate', 'Weekday evenings',   'mixed',      'one_on_one'],
        ['Diego', 'Physics',           'Work through kinematics problems without guessing.',   'beginner',     'Weekday afternoons', 'structured', 'group'],
    ];
    foreach ($prefs as [$who, $topic, $goal, $lvl, $sched, $style, $stype]) {
        q($con,
            "INSERT INTO mentee_preferences (mentee_id, preferred_topic, learning_goal, skill_level, preferred_schedule, communication_style, session_type, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            'issssss', [$id[$who], $topic, $goal, $lvl, $sched, $style, $stype]);
    }

    // ── 2. Availability ───────────────────────────────────────────────
    // subject, days offset, time, duration, capacity, type, topics
    $slots = [
        ['Elena', 'Mathematics', -28, '14:00:00', 60, 1,  '1v1',   'Algebra'],
        ['Elena', 'Mathematics', -21, '14:00:00', 60, 1,  '1v1',   'Algebra'],
        ['Elena', 'Mathematics', -14, '15:30:00', 60, 1,  '1v1',   'Trigonometry'],
        ['Elena', 'Mathematics',  -7, '14:00:00', 60, 1,  '1v1',   'Trigonometry'],
        ['Elena', 'Mathematics',  -3, '10:00:00', 90, 15, 'group', 'Exam review'],
        ['Elena', 'Mathematics',   4, '14:00:00', 60, 1,  '1v1',   'Statistics'],
        ['Elena', 'Mathematics',  11, '09:00:00', 90, 15, 'group', 'Problem-solving clinic'],
        ['Tomas', 'Science',     -20, '13:00:00', 60, 1,  '1v1',   'Stoichiometry'],
        ['Tomas', 'Science',     -10, '13:00:00', 60, 1,  '1v1',   'Kinematics'],
        ['Tomas', 'Science',      -5, '16:00:00', 30, 1,  '1v1',   'Lab report writing'],
        ['Tomas', 'Science',       6, '13:00:00', 60, 1,  '1v1',   'Stoichiometry'],
    ];
    $slotAt = [];   // "who|offset" => 'Y-m-d H:i:s'
    foreach ($slots as [$who, $subj, $off, $time, $dur, $cap, $type, $topics]) {
        $date = date('Y-m-d', strtotime("$off days"));
        q($con,
            "INSERT INTO availability (mentor_id, subject, date, start_time, duration, capacity, session_type, topics, about, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, '', ?)",
            'isssiisss',
            [$id[$who], $subj, $date, $time, $dur, $cap, $type, $topics,
             date('Y-m-d H:i:s', strtotime("$off days -5 days"))]);
        $slotAt["$who|$off"] = "$date $time";
    }

    // ── 3. Sessions — every status the column allows ───────────────────
    // mentor, mentee, slot key, status, missed_by
    $sessions = [
        ['Elena', 'Ana',   -28, 'completed', 'none'],
        ['Elena', 'Ben',   -21, 'completed', 'none'],
        ['Elena', 'Ana',   -14, 'completed', 'none'],
        ['Elena', 'Ben',    -7, 'missed',    'mentee'],
        ['Elena', 'Ana',    -3, 'completed', 'none'],
        ['Elena', 'Carla',  -3, 'completed', 'none'],
        ['Elena', 'Ben',     4, 'approved',  'none'],
        ['Elena', 'Diego',  11, 'pending',   'none'],
        ['Tomas', 'Carla', -20, 'completed', 'none'],
        ['Tomas', 'Diego', -10, 'cancelled', 'none'],
        ['Tomas', 'Carla',  -5, 'rejected',  'none'],
        ['Tomas', 'Diego',   6, 'approved',  'none'],
    ];
    $notes = [
        'Hoping to go over the practice set before Friday.',
        'I get lost once there are two variables.',
        '',
        'Following up on last week.',
        '',
        'Joining the review if there is still a slot.',
        '',
        'Can I still join the clinic?',
        'Struggling with mole ratios.',
        '',
        'Need help before the lab report is due.',
        '',
    ];
    $sid = [];
    foreach ($sessions as $i => [$mentor, $mentee, $off, $status, $missed]) {
        $when = $slotAt["$mentor|$off"];
        $subject = $mentor === 'Elena' ? 'Mathematics' : 'Science';
        $completedAt = $status === 'completed' ? date('Y-m-d H:i:s', strtotime($when . ' +1 hour')) : null;
        $reason = $status === 'rejected' ? 'Clashes with a class I have to attend — try the slot on the 6th.' : null;

        $st = $con->prepare(
            "INSERT INTO session_requests (mentee_id, mentor_id, subject, message, session_date, status, rejection_reason, missed_by, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->bind_param('iisssssss', $id[$mentee], $id[$mentor], $subject, $notes[$i], $when, $status, $reason, $missed, $completedAt);
        $st->execute();
        $sid[] = ['id' => $con->insert_id, 'mentor' => $mentor, 'mentee' => $mentee, 'status' => $status, 'when' => $when];
        $st->close();
    }

    // ── 4. Feedback, both directions, on the completed sessions ────────
    // mentee -> mentor
    $fb = [
        [0, 5.0, 'She started from what I already knew instead of the top of the chapter. First time algebra has made sense.', 5, 5, 5, 5],
        [1, 4.0, 'Clear explanations. I would have liked a couple more practice problems to take away.',                      4, 5, 4, 4],
        [2, 5.0, 'We worked through the unit circle until I could draw it from memory.',                                      5, 5, 5, 5],
        [4, 5.0, 'The group review was better than I expected — hearing other people ask questions helped.',                  5, 4, 5, 5],
        [5, 4.0, 'Good session. Ran a little short because we started late.',                                                 4, 4, 3, 4],
        [8, 5.0, 'Mole ratios finally clicked. He drew it out rather than just giving the formula.',                          5, 5, 5, 5],
    ];
    foreach ($fb as [$k, $rating, $comment, $comm, $know, $eff, $skill]) {
        $s = $sid[$k];
        q($con,
            "INSERT INTO feedback (mentee_id, mentor_id, rating, comment, session_id, communication, knowledge, efficiency, skill, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'iidsiiiiis',
            [$id[$s['mentee']], $id[$s['mentor']], $rating, $comment, $s['id'],
             $comm, $know, $eff, $skill, date('Y-m-d H:i:s', strtotime($s['when'] . ' +2 hours'))]);
    }

    // mentor -> mentee
    $mr = [
        [0, 5.0, 'Came with her own worked attempts, which made the hour go a long way.', 5, 5, 5, 5],
        [2, 5.0, 'Asks precise questions. Easy to teach.',                                 5, 5, 5, 5],
        [8, 4.0, 'Engaged throughout; would benefit from reading the chapter first.',      3, 5, 4, 5],
    ];
    foreach ($mr as [$k, $rating, $comment, $prep, $part, $comm, $recep]) {
        $s = $sid[$k];
        q($con,
            "INSERT INTO mentee_reviews (session_id, mentor_id, mentee_id, rating, preparedness, participation, communication, receptiveness, comment, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'iiidiiiiss',
            [$s['id'], $id[$s['mentor']], $id[$s['mentee']], $rating, $prep, $part, $comm, $recep, $comment,
             date('Y-m-d H:i:s', strtotime($s['when'] . ' +3 hours'))]);
    }

    // ── 5. Goals ──────────────────────────────────────────────────────
    $goals = [
        ['Ana',   'Elena', 'Solve two-variable word problems unaided', 'completed',   -25],
        ['Ana',   'Elena', 'Sketch the unit circle from memory',       'completed',   -12],
        ['Ben',   'Elena', 'Finish the trigonometry problem set',      'in_progress', -18],
        ['Carla', 'Tomas', 'Balance equations without a reference',    'in_progress', -15],
        ['Diego', 'Tomas', 'Work three kinematics problems in a row',  'not_started',  -8],
    ];
    foreach ($goals as [$mentee, $mentor, $title, $status, $off]) {
        $created = date('Y-m-d H:i:s', strtotime("$off days"));
        $done = $status === 'completed' ? date('Y-m-d H:i:s', strtotime("$off days +6 days")) : null;
        q($con,
            "INSERT INTO goals (mentee_id, mentor_id, title, status, created_by, created_at, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            'iississ', [$id[$mentee], $id[$mentor], $title, $status, $id[$mentee], $created, $done]);
    }

    // ── 6. Messages ───────────────────────────────────────────────────
    $msgs = [
        ['Ana',   'Elena', 'Hi! Are you free for the algebra slot on the 12th?',          -29, 1],
        ['Elena', 'Ana',   'Yes, I have opened it. Bring the practice set you mentioned.', -29, 1],
        ['Ana',   'Elena', 'Perfect, thank you.',                                          -29, 1],
        ['Ben',   'Elena', 'Sorry I missed last week, I had a family emergency.',           -6, 1],
        ['Elena', 'Ben',   'No problem at all. I have a slot open on the 13th if that helps.', -6, 1],
        ['Carla', 'Tomas', 'Could we go over stoichiometry again before the lab?',          -4, 1],
        ['Tomas', 'Carla', 'Of course. The 15th at 1pm is free.',                           -4, 0],
        ['Diego', 'Tomas', 'Is the kinematics session still going ahead?',                  -2, 0],
    ];
    foreach ($msgs as [$from, $to, $body, $off, $read]) {
        q($con,
            "INSERT INTO messages (sender_id, receiver_id, content, is_read, created_at) VALUES (?, ?, ?, ?, ?)",
            'iisis', [$id[$from], $id[$to], $body, $read, date('Y-m-d H:i:s', strtotime("$off days"))]);
    }

    // ── 7. Notifications ──────────────────────────────────────────────
    // Written directly rather than through NotificationService, so seeding
    // cannot trigger a real email. email_status records that plainly.
    $notifs = [
        ['Elena', 'session_requested', 'New Session Request',  'Diego Flores requested a Mathematics session.', -1, 0],
        ['Ben',   'session_approved',  'Session Approved',     'Your session with Elena Ramos has been approved.', -2, 0],
        ['Ana',   'feedback_received', 'New Feedback Received','Elena Ramos left feedback on your session.',     -3, 1],
        ['Elena', 'feedback_received', 'New Feedback Received','Ana Cruz left feedback on your session.',        -3, 1],
        ['Carla', 'session_rejected',  'Session Not Available','Tomas Reyes could not take that slot.',          -5, 1],
        ['Diego', 'session_cancelled', 'Session Cancelled',    'Your Science session was cancelled.',           -10, 1],
    ];
    foreach ($notifs as [$who, $type, $title, $msg, $off, $read]) {
        q($con,
            "INSERT INTO notifications (user_id, type, title, message, link, is_read, email_status, created_at)
             VALUES (?, ?, ?, ?, '', ?, 'skipped', ?)",
            'isssis', [$id[$who], $type, $title, $msg, $read, date('Y-m-d H:i:s', strtotime("$off days"))]);
    }

    $con->commit();

    echo "Seeded.\n\n";
    foreach ($id as $name => $uid) {
        printf("  %-6s user_id %-4d\n", $name, $uid);
    }
    echo "\n  sessions inserted: " . count($sid) . "\n";
    echo "  password for every sample account: " . PW . "\n";

} catch (Throwable $e) {
    $con->rollback();
    echo "ROLLED BACK — nothing was written.\n" . $e->getMessage() . "\n";
    exit(1);
}
