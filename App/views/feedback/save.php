<?php
// Save a review — either as a draft, or submitted for good.
// Works for both directions; the session itself decides who may write what.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/NotificationService.php';
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

// A field sent as a list counts as missing: (int) of a list is 1, which saved
// a one-star rating, and (string) of one is the word "Array".
$field = fn(string $name): string => is_string($_POST[$name] ?? null) ? $_POST[$name] : '';

$session_id = ctype_digit($field('session_id')) ? (int)$field('session_id') : 0;
$is_draft   = ($field('action') ?: 'submit') === 'draft';

// ── The session must be one this person was actually in, and it must be over.
// Both facts are checked here, not just in the page that drew the form.
$session = FeedbackRepository::sessionToReview($con, $session_id, $user_id, $is_mentee);

if (!$session) {
    rv_back('error', 'You can only review sessions you took part in.', $home);
}
if (!(int)$session['has_ended']) {
    rv_back('error', 'This session hasn\'t finished yet — feedback opens once it ends.', $home, $session_id);
}

$mentee_id = (int)$session['mentee_id'];
$mentor_id = (int)$session['mentor_id'];

// ── Collect the five scores and their notes ──────────────────────────────
// Text is kept as typed. strip_tags() used to cut it off at the first "<",
// so "Great <3 would book again" was saved as "Great ". Every page that shows
// a review escapes it.
$keys = $is_mentee
    ? ['communication', 'efficiency', 'knowledge', 'skill', 'rating']
    : ['preparedness', 'participation', 'communication', 'receptiveness', 'rating'];

$scores = [];
$notes  = [];
foreach ($keys as $k) {
    $score      = $field($k);
    $scores[$k] = is_numeric($score) ? min(5, max(0, (int)$score)) : 0;
    $notes[$k]  = mb_substr(trim($field('note_' . $k)), 0, 250);
}
$comment = mb_substr(trim($field('comment')), 0, 1000);

$direction = $is_mentee ? 'mentee_to_mentor' : 'mentor_to_mentee';

// Already submitted? Reviews are written once.
if (FeedbackRepository::hasReviewed($con, $session_id, $user_id, $is_mentee)) {
    rv_back('error', 'You have already reviewed this session.', $home, $session_id);
}

// ── Draft: stash the whole form and come back to it later ────────────────
if ($is_draft) {
    $payload = json_encode(array_merge(
        $scores,
        ['note_overall' => $notes['rating'], 'comment' => $comment],
        array_combine(array_map(fn($k) => 'note_' . $k, $keys), array_values($notes))
    ));

    FeedbackRepository::saveDraft($con, $direction, $session_id, $user_id, $payload);

    rv_back('success', 'Draft saved. You can finish this review whenever you\'re ready.', $home, $session_id);
}

// ── Submit: every aspect needs a star ────────────────────────────────────
foreach ($keys as $k) {
    if ($scores[$k] < 1) {
        rv_back('error', 'Please rate all five aspects before submitting.', $home, $session_id);
    }
}

// The database allows one review per person per session. Two submits at once
// (a double-click) both pass the check above; the second used to end on a
// server error page instead of this message.
try {
    if ($is_mentee) {
        FeedbackRepository::addMenteeReview($con, $session_id, $mentee_id, $mentor_id, $scores, $notes, $comment);
    } else {
        FeedbackRepository::addMentorReview($con, $session_id, $mentor_id, $mentee_id, $scores, $notes, $comment);
    }
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1062) {
        rv_back('error', 'You have already reviewed this session.', $home, $session_id);
    }
    throw $e;
}

// The draft has served its purpose.
FeedbackRepository::deleteDraft($con, $session_id, $user_id, $direction);

// A reviewed session is closed as completed straight away only when both
// people joined the call — the same test the missed-session job applies an
// hour after the end. A mentee's review used to complete it whether or not
// the mentor ever came, so "they never showed up" counted as a completed
// session for that mentor. Otherwise the job decides, as for any session.
// 'approved', not '<> completed': the write states its own intent. The slot
// is kept: it holds the session's length.
if ($is_mentee && SessionRepository::bothJoined($con, $session_id)) {
    SessionRepository::completeAfterReview($con, $session_id, $mentee_id);
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
    $who_id  = $is_mentee ? $mentee_id : $mentor_id;
    $nameRow = UserRepository::names($con, $who_id);
    $who     = $nameRow ? trim($nameRow['firstname'] . ' ' . $nameRow['lastname']) : ($is_mentee ? 'Your mentee' : 'Your mentor');

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
