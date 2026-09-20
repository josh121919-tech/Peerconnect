<?php
// Create or update an assessment and rewrite its question set.
// Questions arrive as one JSON blob from the builder; they are replaced
// wholesale inside a transaction so a half-written set is never persisted.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentor') {
    header("Location: " . url('welcomepage'));
    exit;
}

function pc_assessment_fail(string $msg, int $id = 0): void
{
    pc_flash('error', $msg);
    header("Location: " . url('assessment-create') . ($id ? '?id=' . $id : ''));
    exit;
}

$mentor_id     = (int)$_SESSION['user_id'];
$assessment_id = is_scalar($_POST['assessment_id'] ?? null) ? (int)$_POST['assessment_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    pc_assessment_fail('Security token mismatch. Please refresh and try again.', $assessment_id);
}

// Fields are kept as typed — every screen escapes them — and a field sent as
// a list reads as empty rather than stopping the save with an error.
$title        = AssessmentService::text($_POST['title'] ?? '', AssessmentService::TITLE_MAX);
$topic        = AssessmentService::text($_POST['topic'] ?? '', AssessmentService::TOPIC_MAX);
$instructions = AssessmentService::text($_POST['instructions'] ?? '', AssessmentService::INSTRUCTIONS_MAX);
$publish      = (is_scalar($_POST['publish'] ?? null) ? (string)$_POST['publish'] : '0') === '1';
$time_limit   = AssessmentService::timeLimit($_POST['time_limit'] ?? '');

if ($title === '') pc_assessment_fail('Please give the assessment a title.', $assessment_id);
if ($topic === '') pc_assessment_fail('Please choose a topic.', $assessment_id);

$questions = AssessmentService::parseQuestions($_POST['questions_json'] ?? '[]');

if ($publish && empty($questions)) {
    pc_assessment_fail('Add at least one complete question before publishing.', $assessment_id);
}

$status  = $publish ? 'published' : 'draft';
$started = 0;   // attempts under way whose answers the rewrite clears

/*
 * Who the paper is for.
 *
 * Every posted id is checked against this mentor's own mentees and anything
 * else is dropped, so hand-posting a stranger's id cannot put them on the
 * list. Even if one slipped through it would not grant access — the access
 * rule still requires a session — but a list naming people who can never take
 * it would be a lie on the mentor's own screen.
 *
 * An empty list is meaningful, not missing: it means every mentee.
 */
$audience = [];
if (AssessmentRepository::audienceEnabled($con)) {
    $posted = is_array($_POST['mentees'] ?? null) ? $_POST['mentees'] : [];
    $wanted = [];
    foreach ($posted as $one) {
        if (is_scalar($one) && (int)$one > 0) {
            $wanted[(int)$one] = true;
        }
    }
    if ($wanted) {
        foreach (AssessmentRepository::candidateMentees($con, $mentor_id) as $m) {
            if (isset($wanted[(int)$m['user_id']])) {
                $audience[] = (int)$m['user_id'];
            }
        }
    }
}

$con->begin_transaction();
try {
    if ($assessment_id) {
        // Ownership check before any write.
        if (!AssessmentRepository::ownedBy($con, $assessment_id, $mentor_id)) {
            throw new RuntimeException('That assessment could not be found.');
        }

        AssessmentRepository::update($con, $assessment_id, $mentor_id, $title, $topic, $instructions, $time_limit, $status);

        // Answers on record point at these question ids, so once anyone has
        // submitted, the question set is frozen — only the details above
        // change. That is also what keeps earlier attempts readable when the
        // assessment is used again.
        $submitted = AssessmentRepository::countSubmitted($con, $assessment_id);

        if ($submitted > 0) {
            // Who it is for stays editable even once answers are in — a mentor
            // may want to set the same paper for one more mentee. Only the
            // question set is frozen.
            AssessmentRepository::setAudience($con, $assessment_id, $audience);
            $con->commit();
            pc_flash('success',
                '"' . $title . '" was updated. Its questions stay as they are — ' .
                    $submitted . ' attempt' . ($submitted === 1 ? ' has' : 's have') . ' already been submitted against them.',
                'Assessment updated');
            header("Location: " . url('assessments'));
            exit;
        }

        // Nobody has finished, but someone may be part-way through: the
        // rewrite clears what they have answered, so the mentor is told.
        $started = AssessmentRepository::countInProgress($con, $assessment_id);

        AssessmentRepository::replaceQuestions($con, $assessment_id, $questions);
    } else {
        $assessment_id = AssessmentRepository::create($con, $mentor_id, $title, $topic, $instructions, $time_limit, $status);
        AssessmentRepository::replaceQuestions($con, $assessment_id, $questions);
    }

    AssessmentRepository::setAudience($con, $assessment_id, $audience);

    $con->commit();
} catch (Throwable $e) {
    $con->rollback();
    pc_assessment_fail('The assessment could not be saved. Please try again.', $assessment_id);
}

$cleared = $started > 0
    ? ' ' . $started . ' mentee' . ($started === 1 ? ' had' : 's had') . ' started it, so the answers they had entered were cleared.'
    : '';

pc_flash('success',
    ($publish
        ? '"' . $title . '" is published — your mentees can take it now.'
        : '"' . $title . '" was saved as a draft.') . $cleared,
    $publish ? 'Assessment published' : 'Draft saved');
header("Location: " . url('assessments'));
exit;
