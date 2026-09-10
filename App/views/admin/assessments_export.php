<?php

/**
 * assessments_export.php — assessments, results or questions as a file.
 *
 * CSV rather than PDF: nothing in this install can render a PDF, and an
 * option that silently produces nothing is worse than one that is absent.
 *
 * SECURITY: admin-only, prepared statements.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/assessment_data.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$what = in_array($_GET['what'] ?? '', ['assessments', 'results', 'questions'], true) ? $_GET['what'] : 'results';
$from = ($_GET['from'] ?? '') !== '' && strtotime($_GET['from']) ? date('Y-m-d', strtotime($_GET['from'])) : null;
$to   = ($_GET['to'] ?? '')   !== '' && strtotime($_GET['to'])   ? date('Y-m-d', strtotime($_GET['to']))   : null;

$name = 'peerconnect-' . $what . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
// Excel reads a CSV as the system codepage unless the file says otherwise.
fwrite($out, "\xEF\xBB\xBF");

if ($what === 'assessments') {
    fputcsv($out, [
        'Assessment ID', 'Title', 'Topic', 'Mentor', 'Mentor ID', 'Status',
        'Questions', 'Total points', 'Time limit (minutes)',
        'Attempts', 'Submitted', 'In progress', 'Average score (%)',
        'Created', 'Published', 'Last submission',
    ]);
    $rows = $con->query(as_select() . " ORDER BY a.created_at DESC")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $a) {
        fputcsv($out, [
            (int)$a['assessment_id'], $a['title'], $a['topic'], $a['mentor_name'], (int)$a['mentor_id'], $a['status'],
            (int)$a['questions'], (int)$a['total_points'],
            (int)$a['time_limit_minutes'] > 0 ? (int)$a['time_limit_minutes'] : '',
            (int)$a['attempts'], (int)$a['submitted'], (int)$a['in_progress'],
            $a['avg_pct'] !== null ? round((float)$a['avg_pct'], 1) : '',
            $a['created_at'] ? date('Y-m-d H:i', strtotime($a['created_at'])) : '',
            $a['published_at'] ? date('Y-m-d H:i', strtotime($a['published_at'])) : '',
            $a['last_submitted'] ? date('Y-m-d H:i', strtotime($a['last_submitted'])) : '',
        ]);
    }

} elseif ($what === 'questions') {
    fputcsv($out, [
        'Question ID', 'Assessment ID', 'Assessment', 'Topic', 'Mentor', 'Order',
        'Type', 'Question', 'Hint', 'Points', 'Required', 'Options', 'Correct answer',
        'Answered', 'Marked', 'Correct', 'Correct rate (%)', 'Flagged',
    ]);
    $rows = $con->query("
        SELECT q.*, a.title AS assessment_title, a.topic,
               CONCAT_WS(' ', u.firstname, u.lastname) AS mentor_name,
               (SELECT COUNT(*) FROM assessment_options o WHERE o.question_id = q.question_id) options_n,
               (SELECT GROUP_CONCAT(o.option_text SEPARATOR ' | ')
                  FROM assessment_options o WHERE o.question_id = q.question_id AND o.is_correct = 1) correct_options
        FROM assessment_questions q
        JOIN assessments a ON a.assessment_id = q.assessment_id
        JOIN users u ON u.user_id = a.mentor_id
        ORDER BY a.assessment_id, q.question_order, q.question_id
    ")->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $q) {
        $st = as_question_stats($con, (int)$q['question_id']);
        fputcsv($out, [
            (int)$q['question_id'], (int)$q['assessment_id'], $q['assessment_title'], $q['topic'], $q['mentor_name'],
            (int)$q['question_order'], as_qtype_label($q['question_type']), $q['question_text'], $q['hint'],
            (int)$q['points'], (int)$q['is_required'] ? 'Yes' : 'No', (int)$q['options_n'],
            $q['correct_options'] ?: $q['correct_text'],
            $st['answered'], $st['graded'], $st['correct'],
            $st['pct'] !== null ? $st['pct'] : '',
            $st['flagged'],
        ]);
    }

} else {
    fputcsv($out, [
        'Attempt ID', 'Assessment ID', 'Assessment', 'Topic', 'Mentor',
        'Mentee', 'Mentee ID', 'Status', 'Score', 'Total points', 'Score (%)',
        'Started', 'Submitted', 'Minutes taken',
    ]);

    $sql = "
        SELECT t.*, a.title, a.topic, a.assessment_id,
               CONCAT_WS(' ', mo.firstname, mo.lastname) AS mentor_name,
               CONCAT_WS(' ', me.firstname, me.lastname) AS mentee_name
        FROM assessment_attempts t
        JOIN assessments a ON a.assessment_id = t.assessment_id
        JOIN users mo ON mo.user_id = a.mentor_id
        JOIN users me ON me.user_id = t.mentee_id
    ";
    if ($from && $to) {
        $sql .= " WHERE DATE(t.started_at) BETWEEN ? AND ? ";
        $sql .= " ORDER BY COALESCE(t.submitted_at, t.started_at) DESC";
        $st = $con->prepare($sql);
        $st->bind_param('ss', $from, $to);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    } else {
        $rows = $con->query($sql . " ORDER BY COALESCE(t.submitted_at, t.started_at) DESC")->fetch_all(MYSQLI_ASSOC);
    }

    foreach ($rows as $t) {
        $mins = ($t['submitted_at'] && $t['started_at'])
            ? (int)round((strtotime($t['submitted_at']) - strtotime($t['started_at'])) / 60)
            : '';
        $pct = ((int)$t['total_points'] > 0 && $t['status'] === 'submitted')
            ? round($t['score'] / $t['total_points'] * 100, 1)
            : '';
        fputcsv($out, [
            (int)$t['attempt_id'], (int)$t['assessment_id'], $t['title'], $t['topic'], $t['mentor_name'],
            $t['mentee_name'], (int)$t['mentee_id'],
            $t['status'] === 'submitted' ? 'Submitted' : 'Unfinished',
            $t['status'] === 'submitted' ? (int)$t['score'] : '',
            (int)$t['total_points'], $pct,
            $t['started_at'] ? date('Y-m-d H:i', strtotime($t['started_at'])) : '',
            $t['submitted_at'] ? date('Y-m-d H:i', strtotime($t['submitted_at'])) : '',
            $mins,
        ]);
    }
}

fclose($out);
exit;
