<?php
/**
 * import.php — reads a worksheet and hands back draft questions.
 *
 * Nothing is saved here. The questions go straight into the builder the mentor
 * is already looking at, where every field is editable, and they are written
 * to the database only when they press Save like any other question. That is
 * deliberate: recognising questions in arbitrary text is guesswork, and
 * guesswork must not reach a student without someone having looked at it.
 *
 * The shape returned is the builder's own client-side model, so the page can
 * append it and re-render with no translation in between — one description of
 * a question rather than two that can drift apart.
 *
 * SECURITY: mentors only, POST with CSRF, size and extension checked before
 * the file is opened, rate limited. The upload is read from its temporary path
 * and never moved anywhere servable.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentor') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only mentors can import questions.']);
    exit;
}
if (!verify_csrf()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

$mentor_id = (int)$_SESSION['user_id'];

// Reading a document is real work; this stops one account tying the box up.
if (!rate_limit('assessment_import_' . $mentor_id, 12, 300)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many imports in a short time. Please wait a moment.']);
    exit;
}

if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please choose a file to import.']);
    exit;
}
if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => in_array($_FILES['file']['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        ? 'That file is too large to upload.'
        : 'The file could not be uploaded. Please try again.']);
    exit;
}
if (!is_uploaded_file($_FILES['file']['tmp_name'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'That upload could not be read.']);
    exit;
}

$read = QuestionImportService::read($_FILES['file']['tmp_name'], (string)$_FILES['file']['name']);
if (!$read['ok']) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $read['error']]);
    exit;
}

$parsed = QuestionImportService::parse($read['text']);

/*
 * Into the builder's own shape. Points come across because they are written on
 * the worksheet; is_required is not something a worksheet expresses, so it
 * takes the same default a hand-made question gets.
 */
$out = [];
// hint is a VARCHAR(300). Given and Formula together can outrun that on a
// maths worksheet, and a formula cut off halfway is worse than no formula, so
// the mentor is told which ones to look at rather than left to notice.
$shortened = 0;
foreach ($parsed['questions'] as $q) {
    $options = [];
    foreach ($q['options'] as $o) {
        $options[] = [
            'option_text' => mb_substr($o['text'], 0, 400),
            'is_correct'  => $o['correct'] ? 1 : 0,
        ];
    }
    $hint = mb_substr($q['hint'], 0, 300);
    if ($hint !== $q['hint']) {
        $shortened++;
    }
    $out[] = [
        'question_type' => $q['type'],
        'question_text' => $q['text'],
        'hint'          => $hint,
        'solution'      => $q['solution'],
        'points'        => max(1, (int)$q['points']),
        'is_required'   => 1,
        'correct_text'  => mb_substr($q['correct_text'], 0, 300),
        'options'       => $options,
    ];
}

$warnings = $parsed['warnings'];
if ($shortened > 0) {
    $warnings[] = $shortened . ' hint' . ($shortened === 1 ? ' was' : 's were')
        . ' shortened to fit: a hint can hold 300 characters.';
}

echo json_encode([
    'ok'        => true,
    'questions' => $out,
    'warnings'  => $warnings,
    // So the page can say where a solution will and will not be kept.
    'solutions' => AssessmentRepository::solutionEnabled($con),
]);
