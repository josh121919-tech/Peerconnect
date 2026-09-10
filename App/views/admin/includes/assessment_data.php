<?php

/**
 * assessment_data.php — the assessment queries the three admin screens share.
 *
 * Every assessment in this app is written by a mentor for their mentees.
 * There is no admin-authored assessment and no separate question library:
 * `assessments` belongs to a mentor, `assessment_questions` belongs to an
 * assessment, and an attempt belongs to a mentee. So these screens monitor
 * what mentors have made — they do not create anything, which is why there is
 * no Create Assessment or Add Question anywhere in them.
 *
 * All three pages derive their figures here so they cannot disagree about
 * what "completed" or "average score" means.
 */

/** The columns every assessment list needs, with its live counts. */
function as_select(): string
{
    return "
        SELECT a.assessment_id, a.title, a.topic, a.instructions, a.status,
               a.time_limit_minutes, a.created_at, a.published_at, a.mentor_id,
               CONCAT_WS(' ', u.firstname, u.lastname) AS mentor_name,
               p.profile_image AS mentor_pic,
               (SELECT COUNT(*) FROM assessment_questions q WHERE q.assessment_id = a.assessment_id) AS questions,
               (SELECT COALESCE(SUM(q.points), 0) FROM assessment_questions q WHERE q.assessment_id = a.assessment_id) AS total_points,
               (SELECT COUNT(*) FROM assessment_attempts t WHERE t.assessment_id = a.assessment_id) AS attempts,
               (SELECT COUNT(*) FROM assessment_attempts t WHERE t.assessment_id = a.assessment_id AND t.status = 'submitted') AS submitted,
               (SELECT COUNT(*) FROM assessment_attempts t WHERE t.assessment_id = a.assessment_id AND t.status = 'in_progress') AS in_progress,
               (SELECT MAX(t.submitted_at) FROM assessment_attempts t WHERE t.assessment_id = a.assessment_id) AS last_submitted,
               -- Averaged as a percentage of each attempt's own total, so an
               -- assessment worth 14 points and one worth 100 compare properly.
               (SELECT AVG(t.score / NULLIF(t.total_points, 0) * 100)
                  FROM assessment_attempts t
                 WHERE t.assessment_id = a.assessment_id AND t.status = 'submitted') AS avg_pct
        FROM assessments a
        JOIN users u ON u.user_id = a.mentor_id
        LEFT JOIN profile p ON p.user_id = a.mentor_id
    ";
}

/** Label and colour for an assessment's state. */
function as_status_chip(string $status): array
{
    return $status === 'published'
        ? ['Published', '#17654B', '#E6F5EE']
        : ['Draft', '#8A6400', '#FBF0D4'];
}

/** Human names for the question types the schema allows. */
function as_qtype_label(string $t): string
{
    return [
        'multiple_choice' => 'Multiple choice',
        'true_false'      => 'True or false',
        'short_answer'    => 'Short answer',
    ][$t] ?? ucfirst(str_replace('_', ' ', $t));
}

/** A colour per question type, used consistently on all three screens. */
function as_qtype_color(string $t): array
{
    return [
        'multiple_choice' => ['#1A5C9A', '#EAF1FB'],
        'true_false'      => ['#17654B', '#E6F5EE'],
        'short_answer'    => ['#6B21A8', '#F3E8FF'],
    ][$t] ?? ['#565B66', '#F3F4F6'];
}

/**
 * How a question has actually performed.
 *
 * Short answers are stored with is_correct left NULL when nobody has marked
 * them, so they are counted as answered but excluded from the correct rate —
 * treating an unmarked answer as wrong would make every written question look
 * like the hardest on the paper.
 */
function as_question_stats(mysqli $con, int $question_id): array
{
    $st = $con->prepare("
        SELECT COUNT(*) AS answered,
               SUM(an.is_correct IS NOT NULL) AS graded,
               SUM(an.is_correct = 1) AS correct,
               SUM(an.is_flagged = 1) AS flagged
        FROM assessment_answers an
        JOIN assessment_attempts t ON t.attempt_id = an.attempt_id
        WHERE an.question_id = ? AND t.status = 'submitted'
    ");
    $st->bind_param('i', $question_id);
    $st->execute();
    $r = $st->get_result()->fetch_assoc() ?: [];
    $st->close();

    $answered = (int)($r['answered'] ?? 0);
    $graded   = (int)($r['graded'] ?? 0);
    $correct  = (int)($r['correct'] ?? 0);

    return [
        'answered' => $answered,
        'graded'   => $graded,
        'correct'  => $correct,
        'flagged'  => (int)($r['flagged'] ?? 0),
        'pct'      => $graded > 0 ? round($correct / $graded * 100) : null,
    ];
}

/** "45 min", or an honest blank when the mentor set no limit. */
function as_limit_label(?int $mins): string
{
    $mins = (int)$mins;
    if ($mins <= 0) return 'No time limit';
    if ($mins < 60) return $mins . ' min';
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    return $m ? $h . 'h ' . $m . 'm' : $h . ' hour' . ($h === 1 ? '' : 's');
}

/** Relative time, matching the wording used elsewhere in the admin. */
function as_ago(?string $when): string
{
    if (!$when) return '—';
    $d = time() - strtotime($when);
    if ($d < 60)     return 'Just now';
    if ($d < 3600)   return intdiv($d, 60) . 'm ago';
    if ($d < 86400)  return intdiv($d, 3600) . 'h ago';
    if ($d < 604800) return intdiv($d, 86400) . 'd ago';
    return date('M j, Y', strtotime($when));
}
