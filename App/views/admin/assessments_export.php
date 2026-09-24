<?php

/**
 * assessments_export.php — assessments, results or questions as a file.
 *
 * Two formats. ?format=csv (the default) is the spreadsheet this has always
 * produced; ?format=pdf is a letterheaded document the browser prints, with
 * its own layout per selection because assessments, questions and results
 * share no columns. See includes/report_print.php.
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

/*
 * ── Asked for as a document rather than a spreadsheet ──────────────────────
 *
 * Returns before any CSV header is sent; the CSV export below is untouched.
 * Each of the three selections gets its own document, because "assessments",
 * "questions" and "results" answer different questions and share no columns.
 */
if (($_GET['format'] ?? 'csv') === 'pdf') {
    pc_admin_log('viewed assessment ' . $what . ' as a document');

    $report_back = url('admin-assessments');
    $report_meta = [
        'Generated' => date('j M Y, H:i') . ' (Asia/Manila)',
        'By'        => pc_user_name($con, (int)($_SESSION['user_id'] ?? 0)) ?: 'an administrator',
    ];

    if ($what === 'assessments') {
        $rows = AssessmentAdminRepository::all($con);
        $report_title    = 'Assessments';
        $report_subtitle = count($rows) . ' assessment' . (count($rows) === 1 ? '' : 's') . ' on the platform';
        $report_meta = ['Assessments' => count($rows)] + $report_meta;

        $report_body = function () use ($rows) {
            $published = 0; $attempts = 0; $submitted = 0;
            $byTopic = [];
            foreach ($rows as $a) {
                if (($a['status'] ?? '') === 'published') $published++;
                $attempts  += (int)$a['attempts'];
                $submitted += (int)$a['submitted'];
                $t = $a['topic'] ?: 'No topic';
                $byTopic[$t] = ($byTopic[$t] ?? 0) + 1;
            }
            arsort($byTopic);

            rpt_tiles([
                ['label' => 'Assessments', 'value' => number_format(count($rows))],
                ['label' => 'Published',   'value' => number_format($published), 'tone' => 'ok'],
                ['label' => 'Attempts',    'value' => number_format($attempts)],
                ['label' => 'Submitted',   'value' => number_format($submitted), 'hint' => 'completed papers'],
            ]);

            rpt_section('Assessments by topic', function () use ($byTopic) {
                echo '<div class="rpt-chart">';
                rpt_bars($byTopic);
                echo '</div>';
            });

            rpt_section('Every assessment', function () use ($rows) {
                $r = [];
                foreach ($rows as $a) {
                    $r[] = [
                        $a['title'], $a['topic'], $a['mentor_name'], ucfirst((string)$a['status']),
                        ['v' => (int)$a['questions'], 'num' => true],
                        ['v' => (int)$a['attempts'], 'num' => true],
                        ['v' => (int)$a['submitted'], 'num' => true],
                        ['v' => $a['avg_pct'] !== null ? round((float)$a['avg_pct'], 1) . '%' : '—', 'num' => true],
                    ];
                }
                rpt_table(['Title', 'Topic', 'Mentor', 'Status',
                    ['v' => 'Qs', 'num' => true], ['v' => 'Attempts', 'num' => true],
                    ['v' => 'Submitted', 'num' => true], ['v' => 'Avg', 'num' => true]],
                    $r, 'No assessments have been created yet.');
            });
        };
    } elseif ($what === 'questions') {
        $rows = AssessmentAdminRepository::allQuestions($con);
        $report_title    = 'Assessment Questions';
        $report_subtitle = count($rows) . ' question' . (count($rows) === 1 ? '' : 's') . ' across every assessment';
        $report_meta = ['Questions' => count($rows)] + $report_meta;

        $report_body = function () use ($rows) {
            $byType = [];
            foreach ($rows as $q) {
                $t = as_qtype_label($q['question_type']);
                $byType[$t] = ($byType[$t] ?? 0) + 1;
            }
            arsort($byType);

            rpt_tiles([
                ['label' => 'Questions', 'value' => number_format(count($rows))],
                ['label' => 'Types',     'value' => number_format(count($byType))],
            ]);

            rpt_section('Questions by type', function () use ($byType) {
                echo '<div class="rpt-chart">';
                rpt_bars($byType, '#087FC1');
                echo '</div>';
            });

            rpt_section('Every question', function () use ($rows) {
                $r = [];
                foreach ($rows as $q) {
                    $r[] = [
                        $q['assessment_title'], $q['mentor_name'],
                        ['v' => (int)$q['question_order'], 'num' => true],
                        as_qtype_label($q['question_type']),
                        mb_strimwidth((string)$q['question_text'], 0, 90, '…'),
                        ['v' => (int)$q['points'], 'num' => true],
                        (int)$q['is_required'] ? 'Yes' : 'No',
                    ];
                }
                rpt_table(['Assessment', 'Mentor', ['v' => '#', 'num' => true], 'Type', 'Question',
                    ['v' => 'Points', 'num' => true], 'Required'],
                    $r, 'No questions have been written yet.');
                echo '<p class="rpt-empty" style="margin-top:3mm;text-align:left;">'
                    . 'Hints, options, correct answers and per-question accuracy are in the CSV export.</p>';
            });
        };
    } else {
        $rows = AssessmentAdminRepository::allAttempts($con, ['from' => $from && $to ? $from : null, 'to' => $to]);
        $report_title    = 'Assessment Results';
        $report_subtitle = $from && $to
            ? date('j M Y', strtotime($from)) . ' to ' . date('j M Y', strtotime($to))
            : 'All results';
        $report_meta = ['Attempts' => count($rows)] + $report_meta;

        $report_body = function () use ($rows) {
            $submitted = 0; $pctSum = 0.0; $pctN = 0;
            $byAssessment = [];
            foreach ($rows as $a) {
                if (($a['status'] ?? '') === 'submitted') $submitted++;
                // attemptPage() returns score and total_points; the percentage
                // is worked out here, the same way the CSV does it.
                if ((int)$a['total_points'] > 0 && $a['score'] !== null) {
                    $pctSum += (float)$a['score'] / (int)$a['total_points'] * 100; $pctN++;
                }
                $t = $a['title'] ?: 'Untitled';
                $byAssessment[$t] = ($byAssessment[$t] ?? 0) + 1;
            }
            arsort($byAssessment);
            $avg = $pctN > 0 ? round($pctSum / $pctN, 1) : null;

            rpt_tiles([
                ['label' => 'Attempts',  'value' => number_format(count($rows))],
                ['label' => 'Submitted', 'value' => number_format($submitted), 'tone' => 'ok'],
                ['label' => 'Average',   'value' => $avg === null ? '—' : $avg . '%',
                 'hint' => $avg === null ? 'nothing scored yet' : 'across scored papers',
                 'tone' => $avg === null ? null : ($avg >= 75 ? 'ok' : ($avg >= 50 ? 'warn' : 'bad'))],
                ['label' => 'Papers',    'value' => number_format(count($byAssessment)), 'hint' => 'with attempts'],
            ]);

            rpt_section('Attempts per assessment', function () use ($byAssessment) {
                echo '<div class="rpt-chart">';
                rpt_bars($byAssessment, '#17654B');
                echo '</div>';
            });

            rpt_section('Every attempt', function () use ($rows) {
                $r = [];
                foreach ($rows as $a) {
                    $r[] = [
                        $a['title'], $a['mentee_name'], $a['mentor_name'],
                        ucfirst((string)$a['status']),
                        ['v' => $a['score'] !== null ? (int)$a['score'] . '/' . (int)$a['total_points'] : '—', 'num' => true],
                        ['v' => ((int)$a['total_points'] > 0 && $a['score'] !== null)
                            ? round((float)$a['score'] / (int)$a['total_points'] * 100, 1) . '%' : '—', 'num' => true],
                        $a['submitted_at'] ? date('j M Y, H:i', strtotime($a['submitted_at'])) : '—',
                    ];
                }
                rpt_table(['Assessment', 'Mentee', 'Mentor', 'Status',
                    ['v' => 'Score', 'num' => true], ['v' => '%', 'num' => true], 'Submitted'],
                    $r, 'No attempts in this range.');
            });
        };
    }

    require __DIR__ . '/includes/report_print.php';
    exit;
}

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
