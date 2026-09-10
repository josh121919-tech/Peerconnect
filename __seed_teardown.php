<?php
/**
 * __seed_teardown.php — removes everything __seed_sample.php inserted.
 *
 * The sample cohort is identified by its @example.com addresses, which no
 * real account uses. Children are deleted before parents so the foreign keys
 * are satisfied; nothing outside the cohort is touched.
 *
 * Run from the CLI:  php __seed_teardown.php
 * Add --dry to list what would go without deleting anything.
 */

// CLI only. These files sit in the web root, where .htaccess serves any .php
// that actually exists — so without this a plain GET to the teardown URL would
// delete the whole sample cohort, and a GET to the seeder would duplicate it.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dry = in_array('--dry', $argv, true);
$con = new mysqli('localhost', 'root', '', 'cs');
if ($con->connect_error) { exit("db: " . $con->connect_error . "\n"); }

$ids = [];
$r = $con->query("SELECT user_id FROM users WHERE email LIKE '%@example.com'");
while ($x = $r->fetch_row()) { $ids[] = (int)$x[0]; }

if (!$ids) { exit("No sample accounts found — nothing to remove.\n"); }
$in = implode(',', $ids);
echo "Sample accounts: $in\n\n";

// Children first. session_requests has to outlive feedback and mentee_reviews,
// and users has to outlive everything.
$steps = [
    "DELETE FROM feedback         WHERE mentee_id IN ($in) OR mentor_id IN ($in)",
    "DELETE FROM mentee_reviews   WHERE mentee_id IN ($in) OR mentor_id IN ($in)",
    "DELETE FROM feedback_drafts  WHERE author_id IN ($in)",
    "DELETE FROM missed_session_logs WHERE session_id IN (SELECT request_id FROM session_requests WHERE mentee_id IN ($in) OR mentor_id IN ($in))",
    "DELETE FROM session_requests WHERE mentee_id IN ($in) OR mentor_id IN ($in)",
    "DELETE FROM availability     WHERE mentor_id IN ($in)",
    "DELETE FROM goals            WHERE mentee_id IN ($in) OR mentor_id IN ($in)",
    "DELETE FROM messages         WHERE sender_id IN ($in) OR receiver_id IN ($in)",
    "DELETE FROM notifications    WHERE user_id IN ($in)",
    "DELETE FROM announcement_reads WHERE user_id IN ($in)",
    "DELETE FROM user_tags        WHERE user_id IN ($in)",
    "DELETE FROM user_badges      WHERE user_id IN ($in)",
    "DELETE FROM user_certificates WHERE user_id IN ($in)",
    "DELETE FROM mentee_preferences WHERE mentee_id IN ($in)",
    "DELETE FROM notification_preferences WHERE user_id IN ($in)",
    "DELETE FROM privacy_settings WHERE user_id IN ($in)",
    "DELETE FROM user_verifications WHERE user_id IN ($in)",
    "DELETE FROM profile          WHERE user_id IN ($in)",
    "DELETE FROM mentor_scores    WHERE mentor_id IN ($in)",
    "DELETE FROM password_resets  WHERE user_id IN ($in)",
    "DELETE FROM remember_tokens  WHERE user_id IN ($in)",
    "DELETE FROM reports          WHERE reported_user_id IN ($in) OR reported_by IN ($in)",
    "DELETE FROM blocks           WHERE user_id IN ($in)",
    "DELETE FROM restrictions     WHERE user_id IN ($in)",
    "DELETE FROM google_calendar_links WHERE user_id IN ($in)",
    "DELETE FROM passwords        WHERE user_id IN ($in)",
    "DELETE FROM emails           WHERE user_id IN ($in)",
    "DELETE FROM users            WHERE user_id IN ($in)",
];

$total = 0;
foreach ($steps as $sql) {
    preg_match('/DELETE FROM (\w+)/', $sql, $m);
    if ($dry) {
        $count = $con->query(str_replace('DELETE FROM', 'SELECT COUNT(*) FROM', preg_replace('/^DELETE FROM (\w+)\s+/', 'SELECT COUNT(*) FROM $1 ', $sql)));
        $n = $count ? (int)$count->fetch_row()[0] : 0;
    } else {
        $con->query($sql);
        $n = $con->affected_rows;
    }
    $total += $n;
    if ($n > 0) { printf("  %-24s %d\n", $m[1], $n); }
}

echo "\n" . ($dry ? "Would remove" : "Removed") . " $total row(s).\n";
if (!$dry) {
    echo "Recompute mentor scores afterwards if you had any real mentors sharing a session with these accounts.\n";
}
