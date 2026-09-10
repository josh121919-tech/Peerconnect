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
$assessment_id = (int)($_POST['assessment_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    pc_assessment_fail('Security token mismatch. Please refresh and try again.', $assessment_id);
}

$title        = trim(strip_tags($_POST['title'] ?? ''));
$topic        = trim(strip_tags($_POST['topic'] ?? ''));
$instructions = trim(strip_tags($_POST['instructions'] ?? ''));
$publish      = ($_POST['publish'] ?? '0') === '1';

$time_raw   = trim((string)($_POST['time_limit'] ?? ''));
$time_limit = $time_raw === '' ? null : max(1, min(480, (int)$time_raw));

if ($title === '') pc_assessment_fail('Please give the assessment a title.', $assessment_id);
if ($topic === '') pc_assessment_fail('Please choose a topic.', $assessment_id);

// ── Parse and validate the question set ──────────────────────────────────
$incoming  = json_decode((string)($_POST['questions_json'] ?? '[]'), true);
if (!is_array($incoming)) $incoming = [];

$valid_types = ['multiple_choice', 'true_false', 'short_answer'];
$questions   = [];

foreach ($incoming as $q) {
    $text = trim(strip_tags((string)($q['question_text'] ?? '')));
    if ($text === '') continue; // skip blank rows rather than saving empties

    $type = in_array($q['question_type'] ?? '', $valid_types, true) ? $q['question_type'] : 'multiple_choice';
    $row  = [
        'type'         => $type,
        'text'         => mb_substr($text, 0, 1000),
        'hint'         => mb_substr(trim(strip_tags((string)($q['hint'] ?? ''))), 0, 300),
        'points'       => max(1, min(100, (int)($q['points'] ?? 1))),
        'is_required'  => !empty($q['is_required']) ? 1 : 0,
        'correct_text' => mb_substr(trim(strip_tags((string)($q['correct_text'] ?? ''))), 0, 300),
        'options'      => [],
    ];

    if ($type !== 'short_answer') {
        $opts = is_array($q['options'] ?? null) ? $q['options'] : [];
        $has_correct = false;
        foreach ($opts as $o) {
            $otext = trim(strip_tags((string)($o['option_text'] ?? '')));
            if ($otext === '') continue;
            $is_correct = !empty($o['is_correct']) ? 1 : 0;
            if ($is_correct) $has_correct = true;
            $row['options'][] = ['text' => mb_substr($otext, 0, 400), 'is_correct' => $is_correct];
        }
        if (count($row['options']) < 2) continue;      // not a usable choice question
        if (!$has_correct) $row['options'][0]['is_correct'] = 1;
    }

    $questions[] = $row;
}

if ($publish && empty($questions)) {
    pc_assessment_fail('Add at least one complete question before publishing.', $assessment_id);
}

$status = $publish ? 'published' : 'draft';

$con->begin_transaction();
try {
    if ($assessment_id) {
        // Ownership check before any write.
        $own = $con->prepare("SELECT status FROM assessments WHERE assessment_id = ? AND mentor_id = ?");
        $own->bind_param("ii", $assessment_id, $mentor_id);
        $own->execute();
        $existing = $own->get_result()->fetch_assoc();
        $own->close();
        if (!$existing) {
            throw new RuntimeException('That assessment could not be found.');
        }

        $upd = $con->prepare("
            UPDATE assessments
               SET title = ?, topic = ?, instructions = ?, time_limit_minutes = ?, status = ?,
                   published_at = CASE WHEN ? = 'published' AND published_at IS NULL THEN NOW() ELSE published_at END
             WHERE assessment_id = ? AND mentor_id = ?
        ");
        $upd->bind_param("sssissii", $title, $topic, $instructions, $time_limit, $status, $status, $assessment_id, $mentor_id);
        $upd->execute();
        $upd->close();

        // Answers on record point at these question ids, so once anyone has
        // submitted, the question set is frozen — only the details above change.
        $submitted = (int)($con->query("
            SELECT COUNT(*) c FROM assessment_attempts
            WHERE assessment_id = $assessment_id AND status = 'submitted'
        ")->fetch_assoc()['c'] ?? 0);

        if ($submitted > 0) {
            $con->commit();
            pc_flash('success',
                '"' . $title . '" was updated. Its questions stay as they are — ' .
                    $submitted . ' mentee' . ($submitted === 1 ? ' has' : 's have') . ' already submitted answers to them.',
                'Assessment updated');
            header("Location: " . url('assessments'));
            exit;
        }

        // Replace the question set. Options cascade via the question ids.
        $old = $con->query("SELECT question_id FROM assessment_questions WHERE assessment_id = $assessment_id");
        while ($r = $old->fetch_assoc()) {
            $con->query("DELETE FROM assessment_options WHERE question_id = " . (int)$r['question_id']);
        }
        $con->query("DELETE FROM assessment_questions WHERE assessment_id = $assessment_id");
    } else {
        $ins = $con->prepare("
            INSERT INTO assessments (mentor_id, title, topic, instructions, time_limit_minutes, status, published_at)
            VALUES (?, ?, ?, ?, ?, ?, CASE WHEN ? = 'published' THEN NOW() ELSE NULL END)
        ");
        $ins->bind_param("isssiss", $mentor_id, $title, $topic, $instructions, $time_limit, $status, $status);
        $ins->execute();
        $assessment_id = (int)$con->insert_id;
        $ins->close();
    }

    $qStmt = $con->prepare("
        INSERT INTO assessment_questions
            (assessment_id, question_order, question_type, question_text, hint, points, is_required, correct_text)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $oStmt = $con->prepare("
        INSERT INTO assessment_options (question_id, option_order, option_text, is_correct)
        VALUES (?, ?, ?, ?)
    ");

    foreach ($questions as $i => $q) {
        $order = $i + 1;
        $qStmt->bind_param(
            "iissssis",
            $assessment_id,
            $order,
            $q['type'],
            $q['text'],
            $q['hint'],
            $q['points'],
            $q['is_required'],
            $q['correct_text']
        );
        $qStmt->execute();
        $question_id = (int)$con->insert_id;

        foreach ($q['options'] as $j => $o) {
            $oOrder = $j + 1;
            $oStmt->bind_param("iisi", $question_id, $oOrder, $o['text'], $o['is_correct']);
            $oStmt->execute();
        }
    }
    $qStmt->close();
    $oStmt->close();

    $con->commit();
} catch (Throwable $e) {
    $con->rollback();
    pc_assessment_fail('The assessment could not be saved. Please try again.', $assessment_id);
}

pc_flash('success',
    $publish
        ? '"' . $title . '" is published — your mentees can take it now.'
        : '"' . $title . '" was saved as a draft.',
    $publish ? 'Assessment published' : 'Draft saved');
header("Location: " . url('assessments'));
exit;
