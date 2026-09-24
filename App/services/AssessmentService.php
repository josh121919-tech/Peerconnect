<?php

/**
 * AssessmentService — the rules an assessment follows, in one place: what a
 * question set must look like to be saved, how an answer is marked, when the
 * time is up, and when a mentee may start another attempt.
 *
 * An assessment is reusable. A mentee can take it again once the attempt they
 * have is finished, and every attempt stays on the record — which is also why
 * the question set is frozen as soon as anyone has submitted: the answers
 * already stored point at those questions.
 */
class AssessmentService
{
    public const TITLE_MAX        = 200;
    public const TOPIC_MAX        = 120;
    public const INSTRUCTIONS_MAX = 2000;
    public const QUESTION_MAX     = 1000;
    public const HINT_MAX         = 300;
    public const OPTION_MAX       = 400;
    public const ANSWER_MAX       = 500;
    public const EXPECTED_MAX     = 300;

    public const POINTS_MIN = 1;
    public const POINTS_MAX = 100;
    public const TIME_MIN   = 1;
    public const TIME_MAX   = 480;

    public const TYPES = ['multiple_choice', 'true_false', 'short_answer'];

    /** A posted field as text, or '' when it arrived as a list or not at all. */
    public static function text($value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }
        // Kept as typed. Every screen escapes it when showing it, so "Is 3 < 5?"
        // stays a question rather than becoming "Is 3 ".
        return mb_substr(trim($value), 0, $max);
    }

    /** The minutes a mentee gets, or null for no limit. */
    public static function timeLimit($value): ?int
    {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') {
            return null;
        }
        return max(self::TIME_MIN, min(self::TIME_MAX, (int)$raw));
    }

    /**
     * The question set from the builder's JSON, as rows ready to store.
     * Anything unusable is left out rather than saved half-formed: a question
     * with no text, or a choice question with fewer than two options.
     */
    public static function parseQuestions($json): array
    {
        $incoming = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($incoming)) {
            return [];
        }

        $questions = [];
        foreach ($incoming as $q) {
            if (!is_array($q)) {
                continue;
            }
            $text = self::text($q['question_text'] ?? '', self::QUESTION_MAX);
            if ($text === '') {
                continue;
            }
            $type = in_array($q['question_type'] ?? '', self::TYPES, true) ? $q['question_type'] : 'multiple_choice';
            $row = [
                'type'         => $type,
                'text'         => $text,
                'hint'         => self::text($q['hint'] ?? '', self::HINT_MAX),
                'points'       => max(self::POINTS_MIN, min(self::POINTS_MAX, (int)($q['points'] ?? 1))),
                'is_required'  => !empty($q['is_required']) ? 1 : 0,
                'correct_text' => self::text($q['correct_text'] ?? '', self::EXPECTED_MAX),
                // Worked steps, for after an attempt is submitted. Not capped
                // like the hint is: the point of it is the working, and half a
                // proof is no use to anybody.
                'solution'     => trim((string)($q['solution'] ?? '')),
                'options'      => [],
            ];

            if ($type !== 'short_answer') {
                $hasCorrect = false;
                foreach (is_array($q['options'] ?? null) ? $q['options'] : [] as $o) {
                    if (!is_array($o)) {
                        continue;
                    }
                    $optionText = self::text($o['option_text'] ?? '', self::OPTION_MAX);
                    if ($optionText === '') {
                        continue;
                    }
                    $isCorrect = !empty($o['is_correct']) ? 1 : 0;
                    $hasCorrect = $hasCorrect || $isCorrect === 1;
                    $row['options'][] = ['text' => $optionText, 'is_correct' => $isCorrect];
                }
                if (count($row['options']) < 2) {
                    continue;
                }
                if (!$hasCorrect) {
                    $row['options'][0]['is_correct'] = 1;
                }
            }

            $questions[] = $row;
        }
        return $questions;
    }

    /**
     * A short answer, as it is compared: the same words, however they were
     * spaced or capitalised. "  Rizal " and "Rizal" are the same answer.
     */
    public static function normalise(string $answer): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $answer)));
    }

    /**
     * Marks one answer: ['is_correct' => 0|1, 'points' => n]. A short answer
     * with no expected answer on file is never marked right, and neither is a
     * choice question nobody answered.
     */
    public static function mark(array $question, ?array $option, ?string $text): array
    {
        $correct = 0;
        if ($question['question_type'] === 'short_answer') {
            $expected = (string)($question['correct_text'] ?? '');
            if (trim($expected) !== '' && $text !== null && trim($text) !== '') {
                $correct = self::normalise($text) === self::normalise($expected) ? 1 : 0;
            }
        } elseif ($option !== null) {
            $correct = (int)$option['is_correct'] === 1 ? 1 : 0;
        }
        return ['is_correct' => $correct, 'points' => $correct ? (int)$question['points'] : 0];
    }

    /** Whether an attempt that started $elapsed seconds ago has run out of time. */
    public static function timeIsUp(?int $limitMinutes, int $elapsed): bool
    {
        $limit = (int)$limitMinutes;
        return $limit > 0 && $elapsed > $limit * 60;
    }

    /** The seconds left of a timed attempt, or null when it is untimed. */
    public static function secondsLeft(?int $limitMinutes, int $elapsed): ?int
    {
        $limit = (int)$limitMinutes;
        return $limit > 0 ? max(0, $limit * 60 - $elapsed) : null;
    }

    /**
     * The attempt a mentee is working on, starting their first if they have
     * none.
     *
     * One attempt each, and one only. An assessment is reusable in the sense
     * that matters — a mentor sets the same paper for as many mentees as they
     * like — but no mentee gets a second go at the same paper, because a
     * second go measures how well they remember the first.
     *
     * So: resume whatever is open, show whatever was submitted, and only ever
     * create a row when there is nothing at all on record. Two tabs cannot
     * make two attempts — the lock holds the second until the first has its
     * row, and it then finds it.
     */
    public static function currentAttempt(mysqli $con, int $assessmentId, int $menteeId, bool $startIfNone = true): ?array
    {
        // Anything already on record — in progress or submitted — is THE
        // attempt. latestAttempt() rather than openAttempt(), so a submitted
        // one is found too and nothing starts a second.
        $existing = AssessmentRepository::latestAttempt($con, $assessmentId, $menteeId);
        if ($existing || !$startIfNone) {
            return $existing;
        }

        $lock = 'assessment_attempt_' . $assessmentId . '_' . $menteeId;
        if (!AssessmentRepository::acquireLock($con, $lock, 5)) {
            return AssessmentRepository::latestAttempt($con, $assessmentId, $menteeId);
        }
        try {
            $existing = AssessmentRepository::latestAttempt($con, $assessmentId, $menteeId);
            if ($existing) {
                return $existing;
            }
            $id = AssessmentRepository::startAttempt($con, $assessmentId, $menteeId, AssessmentRepository::totalPoints($con, $assessmentId));
            return AssessmentRepository::attemptOf($con, $id, $menteeId);
        } finally {
            AssessmentRepository::releaseLock($con, $lock);
        }
    }

    /** How an attempt reads in a list: "3rd attempt", and the like. */
    public static function attemptLabel(int $number): string
    {
        $suffix = match (true) {
            $number % 100 >= 11 && $number % 100 <= 13 => 'th',
            $number % 10 === 1 => 'st',
            $number % 10 === 2 => 'nd',
            $number % 10 === 3 => 'rd',
            default => 'th',
        };
        return $number . $suffix . ' attempt';
    }
}
