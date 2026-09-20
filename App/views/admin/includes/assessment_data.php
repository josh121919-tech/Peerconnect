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
    return AssessmentAdminRepository::questionStats($con, $question_id);
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
