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

// Results name each mentee and their score, so who took a copy is worth a record.
pc_admin_log('exported assessment ' . $what . ' (' . $name . ')');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
// Excel reads a CSV as the system codepage unless the file says otherwise.
fwrite($out, "\xEF\xBB\xBF");

if ($what === 'assessments') {
    CsvExport::row($out, [
        'Assessment ID', 'Title', 'Topic', 'Mentor', 'Mentor ID', 'Status',
        'Questions', 'Total points', 'Time limit (minutes)',
        'Attempts', 'Submitted', 'In progress', 'Average score (%)',
        'Created', 'Published', 'Last submission',
    ]);
    $rows = AssessmentAdminRepository::all($con);
    foreach ($rows as $a) {
        CsvExport::row($out, [
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
    CsvExport::row($out, [
        'Question ID', 'Assessment ID', 'Assessment', 'Topic', 'Mentor', 'Order',
        'Type', 'Question', 'Hint', 'Points', 'Required', 'Options', 'Correct answer',
        'Answered', 'Correct', 'Correct rate (%)', 'Flagged',
    ]);
    $rows = AssessmentAdminRepository::allQuestions($con);

    foreach ($rows as $q) {
        $st = as_question_stats($con, (int)$q['question_id']);
        CsvExport::row($out, [
            (int)$q['question_id'], (int)$q['assessment_id'], $q['assessment_title'], $q['topic'], $q['mentor_name'],
            (int)$q['question_order'], as_qtype_label($q['question_type']), $q['question_text'], $q['hint'],
            (int)$q['points'], (int)$q['is_required'] ? 'Yes' : 'No', (int)$q['options_n'],
            $q['correct_options'] ?: $q['correct_text'],
            $st['answered'], $st['correct'],
            $st['pct'] !== null ? $st['pct'] : '',
            $st['flagged'],
        ]);
    }

} else {
    CsvExport::row($out, [
        'Attempt ID', 'Assessment ID', 'Assessment', 'Topic', 'Mentor',
        'Mentee', 'Mentee ID', 'Attempt', 'Attempts by them', 'Status', 'Score', 'Total points', 'Score (%)',
        'Started', 'Submitted', 'Minutes taken',
    ]);

    $rows = AssessmentAdminRepository::allAttempts($con, ['from' => $from && $to ? $from : null, 'to' => $to]);

    foreach ($rows as $t) {
        $mins = ($t['submitted_at'] && $t['started_at'])
            ? (int)round((strtotime($t['submitted_at']) - strtotime($t['started_at'])) / 60)
            : '';
        $pct = ((int)$t['total_points'] > 0 && $t['status'] === 'submitted')
            ? round($t['score'] / $t['total_points'] * 100, 1)
            : '';
        CsvExport::row($out, [
            (int)$t['attempt_id'], (int)$t['assessment_id'], $t['title'], $t['topic'], $t['mentor_name'],
            $t['mentee_name'], (int)$t['mentee_id'], (int)$t['attempt_no'], (int)$t['attempts_by_them'],
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
