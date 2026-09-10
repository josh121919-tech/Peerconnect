<?php
// Save a review — either as a draft, or submitted for good.
// Works for both directions; the session itself decides who may write what.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/AvailabilityService.php';
require_once __DIR__ . '/../../services/MentorScoreService.php';

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['mentee', 'mentor'], true)) {
    header("Location: " . url('welcomepage'));
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$is_mentee = $role === 'mentee';
// Where to land after saving: the review form itself, so the flash and
// the ?session= hand-back still work. 'mentee-feedback' is now the
// mentee's My Feedback page, not this form.
$home      = $is_mentee ? url('mentee-review') : url('mentor-review');

function rv_back(string $type, string $message, string $home, int $session_id = 0): void
{
    pc_flash($type, $message, $type === 'success' ? 'Feedback sent' : '');
    header("Location: " . $home . ($session_id ? '?session=' . $session_id : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    rv_back('error', 'Security token mismatch. Please refresh and try again.', $home);
}

$session_id = (int)($_POST['session_id'] ?? 0);
$is_draft   = ($_POST['action'] ?? 'submit') === 'draft';

// ── The session must be one this person was actually in, and it must be over.
// Both facts are checked here, not just in the page that drew the form.
$mine_col  = $is_mentee ? 'sr.mentee_id' : 'sr.mentor_id';
$stmt = $con->prepare("
    SELECT sr.request_id, sr.mentee_id, sr.mentor_id, sr.subject,
           DATE_ADD(sr.session_date, INTERVAL COALESCE(a.duration, 60) MINUTE) <= NOW() AS has_ended
    FROM session_requests sr
    LEFT JOIN availability a
           ON a.mentor_id        = sr.mentor_id
          AND a.subject          = sr.subject
          AND DATE(a.date)       = DATE(sr.session_date)
          AND TIME(a.start_time) = TIME(sr.session_date)
    WHERE sr.request_id = ? AND $mine_col = ? AND sr.status IN ('approved','completed')
");
$stmt->bind_param("ii", $session_id, $user_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
    rv_back('error', 'You can only review sessions you took part in.', $home);
}
if (!(int)$session['has_ended']) {
    rv_back('error', 'This session hasn\'t finished yet — feedback opens once it ends.', $home, $session_id);
}

$mentee_id = (int)$session['mentee_id'];
$mentor_id = (int)$session['mentor_id'];

// ── Collect the five scores and their notes ──────────────────────────────
$keys = $is_mentee
    ? ['communication', 'efficiency', 'knowledge', 'skill', 'rating']
    : ['preparedness', 'participation', 'communication', 'receptiveness', 'rating'];

$scores = [];
$notes  = [];
foreach ($keys as $k) {
    $scores[$k] = min(5, max(0, (int)($_POST[$k] ?? 0)));
    $notes[$k]  = mb_substr(trim(strip_tags((string)($_POST['note_' . $k] ?? ''))), 0, 250);
}
$comment = mb_substr(trim(strip_tags((string)($_POST['comment'] ?? ''))), 0, 1000);

$direction    = $is_mentee ? 'mentee_to_mentor' : 'mentor_to_mentee';
$review_table = $is_mentee ? 'feedback' : 'mentee_reviews';
$author_col   = $is_mentee ? 'mentee_id' : 'mentor_id';

// Already submitted? Reviews are written once.
$dup = $con->prepare("SELECT 1 FROM $review_table WHERE session_id = ? AND $author_col = ?");
$dup->bind_param("ii", $session_id, $user_id);
$dup->execute();
$already = $dup->get_result()->num_rows > 0;
$dup->close();

if ($already) {
    rv_back('error', 'You have already reviewed this session.', $home, $session_id);
}

// ── Draft: stash the whole form and come back to it later ────────────────
if ($is_draft) {
    $payload = json_encode(array_merge(
        $scores,
        ['note_overall' => $notes['rating'], 'comment' => $comment],
        array_combine(array_map(fn($k) => 'note_' . $k, $keys), array_values($notes))
    ));

    $up = $con->prepare("
        INSERT INTO feedback_drafts (direction, session_id, author_id, payload, updated_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = NOW()
    ");
    $up->bind_param("siis", $direction, $session_id, $user_id, $payload);
    $up->execute();
    $up->close();

    rv_back('success', 'Draft saved. You can finish this review whenever you\'re ready.', $home, $session_id);
}

// ── Submit: every aspect needs a star ────────────────────────────────────
foreach ($keys as $k) {
    if ($scores[$k] < 1) {
        rv_back('error', 'Please rate all five aspects before submitting.', $home, $session_id);
    }
}

if ($is_mentee) {
    $ins = $con->prepare("
        INSERT INTO feedback
            (session_id, mentee_id, mentor_id, rating, comment,
             communication, efficiency, knowledge, skill,
             note_communication, note_efficiency, note_knowledge, note_skill, note_overall,
             created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $ins->bind_param(
        "iiidsiiiisssss",
        $session_id,
        $mentee_id,
        $mentor_id,
        $scores['rating'],
        $comment,
        $scores['communication'],
        $scores['efficiency'],
        $scores['knowledge'],
        $scores['skill'],
        $notes['communication'],
        $notes['efficiency'],
        $notes['knowledge'],
        $notes['skill'],
        $notes['rating']
    );
} else {
    $ins = $con->prepare("
        INSERT INTO mentee_reviews
            (session_id, mentor_id, mentee_id, rating, comment,
             preparedness, participation, communication, receptiveness,
             note_preparedness, note_participation, note_communication, note_receptiveness, note_overall,
             created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $ins->bind_param(
        "iiidsiiiisssss",
        $session_id,
        $mentor_id,
        $mentee_id,
        $scores['rating'],
        $comment,
        $scores['preparedness'],
        $scores['participation'],
        $scores['communication'],
        $scores['receptiveness'],
        $notes['preparedness'],
        $notes['participation'],
        $notes['communication'],
        $notes['receptiveness'],
        $notes['rating']
    );
}
$ins->execute();
$ins->close();

// The draft has served its purpose.
$del = $con->prepare("DELETE FROM feedback_drafts WHERE session_id = ? AND author_id = ? AND direction = ?");
$del->bind_param("iis", $session_id, $user_id, $direction);
$del->execute();
$del->close();

// A reviewed session is a finished session (mentee side keeps the original
// behaviour: it closes the request and frees the slot if both sides are done).
if ($is_mentee) {
    // NOW() stamps completed_at as well — it was left NULL on every genuine
    // completion, so only sessions the cron marked missed ever had one.
    // 'approved', not '<> completed': the negative form also matches
    // cancelled, missed and rejected. It is harmless today only because the
    // lookup at the top of this file already restricts to approved/completed
    // — state that far away is not worth relying on, so the intent is stated
    // where the write happens.
    $upd = $con->prepare("UPDATE session_requests SET status = 'completed', completed_at = NOW() WHERE request_id = ? AND mentee_id = ? AND status = 'approved'");
    $upd->bind_param("ii", $session_id, $mentee_id);
    $upd->execute();
    $upd->close();
    AvailabilityService::removeIfFullyCompleted($con, $session_id);
}

// A new rating changes the mentor's composite score, which is what the
// leaderboard ranks on and what Recommended for You / Find a Mentor order by.
// Nothing recomputed it here, so a review had no visible effect until the
// missed-session cron ran or the mentor happened to open their own profile.
if ($is_mentee) {
    try {
        MentorScoreService::compute($con, $mentor_id);
    } catch (Throwable $e) {
        // A stale score is recoverable; losing the review is not.
        error_log('MentorScoreService::compute failed after review: ' . $e->getMessage());
    }
}

// Tell the other person, using the notification service already in place.
try {
    $who_id = $is_mentee ? $mentee_id : $mentor_id;
    $nameQ  = $con->prepare("SELECT firstname, lastname FROM users WHERE user_id = ?");
    $nameQ->bind_param("i", $who_id);
    $nameQ->execute();
    $nameRow = $nameQ->get_result()->fetch_assoc();
    $nameQ->close();
    $who = $nameRow ? trim($nameRow['firstname'] . ' ' . $nameRow['lastname']) : ($is_mentee ? 'Your mentee' : 'Your mentor');

    if ($is_mentee) {
        NotificationService::feedbackReceived($con, $mentor_id, $who, url('mentor-feedback'));
    } else {
        NotificationService::send(
            $con,
            $mentee_id,
            'feedback_received',
            'New feedback from your mentor',
            $who . ' reviewed your session on "' . $session['subject'] . '".',
            url('mentee-review') . '?session=' . $session_id . '&tab=theirs'
        );
    }
} catch (Throwable $e) {
    // A failed notification must never fail the review itself.
}

rv_back('success', 'Thanks — your feedback has been submitted.', $home, $session_id);
