<?php

/**
 * AssessmentRepository — assessments, their questions and options, and the
 * attempts mentees make at them.
 *
 * An assessment is written once and can be taken as often as the mentor's
 * mentees need it: every attempt is kept, so "how did they do" and "how did
 * they do the first time" are both answerable. Figures that describe people
 * (how many mentees, how they average) therefore say which they count —
 * attempts or mentees — rather than leaving it to be guessed.
 */
class AssessmentRepository extends Repository
{
    /** A mentee may take a mentor's assessment once they have a session together. */
    private const HAS_SESSION = "
        EXISTS (SELECT 1 FROM session_requests sr
                 WHERE sr.mentor_id = a.mentor_id AND sr.mentee_id = ?
                   AND sr.status IN ('approved','completed'))";

    /**
     * The second half of "who can take this": the mentor may name particular
     * mentees, and then only those may take it. Naming nobody means all of
     * them, which is what every assessment written before the picker existed
     * meant — so an empty table needs no backfill to stay correct.
     *
     * This never replaces HAS_SESSION, it narrows it. A named mentee who has
     * no session with the mentor still cannot take the paper.
     */
    private const IN_AUDIENCE = "
        (NOT EXISTS (SELECT 1 FROM assessment_mentees am WHERE am.assessment_id = a.assessment_id)
         OR EXISTS (SELECT 1 FROM assessment_mentees am
                     WHERE am.assessment_id = a.assessment_id AND am.mentee_id = ?))";

    /**
     * Whether assessment_audience.sql has been run.
     *
     * Until it has, the table is absent and every query naming it would fail,
     * so the clause is left out entirely and the audience is what it always
     * was. Asked once per request and remembered.
     */
    /**
     * Whether assessment_solution.sql has been run.
     *
     * Until it has, the column is absent and naming it in an INSERT would
     * fail, so it is left out and questions carry no solution — which is
     * exactly how they behaved before. Asked once per request.
     */
    public static function solutionEnabled(mysqli $con): bool
    {
        static $known = null;
        if ($known !== null) {
            return $known;
        }
        try {
            $known = self::value($con, "
                SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'assessment_questions'
                  AND COLUMN_NAME = 'solution' LIMIT 1
            ") !== null;
        } catch (Throwable $e) {
            $known = false;
        }
        return $known;
    }

    public static function audienceEnabled(mysqli $con): bool
    {
        static $known = null;
        if ($known !== null) {
            return $known;
        }
        try {
            $known = self::value($con, "
                SELECT 1 FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_mentees' LIMIT 1
            ") !== null;
        } catch (Throwable $e) {
            $known = false;
        }
        return $known;
    }

    /**
     * The audience clause and the extra binding it needs, or nothing at all.
     *
     * HAS_SESSION is the last placeholder in every query that uses it, so this
     * appends cleanly onto the end of both the SQL and the argument list.
     *
     * @return array{0:string, 1:string, 2:array} sql, extra types, extra args
     */
    private static function audience(mysqli $con, int $menteeId): array
    {
        return self::audienceEnabled($con)
            ? [' AND ' . self::IN_AUDIENCE, 'i', [$menteeId]]
            : ['', '', []];
    }

    /** Counts every assessment list shows, as correlated subqueries on `a`. */
    private const COUNTS = "
        (SELECT COUNT(*) FROM assessment_questions q WHERE q.assessment_id = a.assessment_id) AS question_count,
        (SELECT COALESCE(SUM(q.points), 0) FROM assessment_questions q WHERE q.assessment_id = a.assessment_id) AS total_points,
        (SELECT COUNT(*) FROM assessment_attempts t WHERE t.assessment_id = a.assessment_id) AS attempts,
        (SELECT COUNT(*) FROM assessment_attempts t WHERE t.assessment_id = a.assessment_id AND t.status = 'submitted') AS submissions,
        (SELECT COUNT(DISTINCT t.mentee_id) FROM assessment_attempts t WHERE t.assessment_id = a.assessment_id AND t.status = 'submitted') AS mentees_done,
        (SELECT ROUND(AVG(t.score / NULLIF(t.total_points,0) * 100))
           FROM assessment_attempts t
          WHERE t.assessment_id = a.assessment_id AND t.status = 'submitted') AS avg_percent";

    // ── One assessment ──────────────────────────────────────────────────────

    /** The assessment, whoever wrote it. */
    public static function byId(mysqli $con, int $assessmentId): ?array
    {
        return self::row($con, "SELECT * FROM assessments WHERE assessment_id = ?", 'i', [$assessmentId]);
    }

    /** The assessment with its author's name, whatever its status — for reading an attempt back. */
    public static function withMentor(mysqli $con, int $assessmentId): ?array
    {
        return self::row($con, "
            SELECT a.*, u.firstname, u.lastname, u.user_id AS mentor_user_id
            FROM assessments a JOIN users u ON u.user_id = a.mentor_id
            WHERE a.assessment_id = ?
        ", 'i', [$assessmentId]);
    }

    /** The assessment, only when this mentor wrote it. */
    public static function ownedBy(mysqli $con, int $assessmentId, int $mentorId): ?array
    {
        return self::row($con, "SELECT * FROM assessments WHERE assessment_id = ? AND mentor_id = ?", 'ii', [$assessmentId, $mentorId]);
    }

    /**
     * The assessment as a mentee may take it: published, by a mentor they have
     * a session with. Null when any of that is untrue.
     */
    public static function openToMentee(mysqli $con, int $assessmentId, int $menteeId): ?array
    {
        [$aud, $audTypes, $audArgs] = self::audience($con, $menteeId);

        return self::row($con, "
            SELECT a.*, u.firstname, u.lastname, u.user_id AS mentor_user_id
            FROM assessments a
            JOIN users u ON u.user_id = a.mentor_id
            WHERE a.assessment_id = ? AND a.status = 'published' AND " . self::HAS_SESSION . $aud,
            'ii' . $audTypes, array_merge([$assessmentId, $menteeId], $audArgs));
    }

    /**
     * The mentees a mentor may send an assessment to, most recent session
     * first — the same people HAS_SESSION lets in, which is why the picker can
     * never offer somebody the access rule would then refuse.
     *
     * `last_session` is what the list is ordered and labelled by: a mentor
     * choosing who to set a paper for thinks in terms of who they last saw.
     */
    public static function candidateMentees(mysqli $con, int $mentorId): array
    {
        return self::typedRows($con, "
            SELECT u.user_id, u.firstname, u.lastname, pr.profile_image,
                   MAX(sr.session_date)            AS last_session,
                   SUM(sr.status = 'completed')    AS sessions_done
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentee_id
            LEFT JOIN profile pr ON pr.user_id = u.user_id
            WHERE sr.mentor_id = ? AND sr.status IN ('approved','completed')
            GROUP BY u.user_id, u.firstname, u.lastname, pr.profile_image
            ORDER BY last_session DESC, u.firstname ASC, u.user_id ASC
        ", 'i', [$mentorId]);
    }

    /**
     * The mentor's recent sessions, newest first, each with who was in it.
     *
     * A session is a slot: one mentor, one subject, one exact date and time.
     * That is how the rest of the app groups them (see
     * SessionRepository::openReservationsInSlot) — several rows sharing a slot
     * are one group session, a single row is a one-to-one. Both are returned,
     * and `mentees` says which it was.
     *
     * This is what the "everyone from that session" filter is built on: a
     * mentor setting work after a session thinks of it as "the people who were
     * there", not as a list of names to tick one by one.
     */
    public static function recentSessionsForMentor(mysqli $con, int $mentorId, int $limit = 10): array
    {
        $rows = self::typedRows($con, "
            SELECT sr.subject, sr.session_date, sr.status,
                   COUNT(*)                                   AS mentees,
                   GROUP_CONCAT(sr.mentee_id ORDER BY sr.mentee_id)            AS mentee_ids,
                   GROUP_CONCAT(CONCAT(u.firstname, ' ', u.lastname)
                                ORDER BY u.firstname SEPARATOR ', ')           AS who
            FROM session_requests sr
            JOIN users u ON u.user_id = sr.mentee_id
            WHERE sr.mentor_id = ? AND sr.status IN ('approved', 'completed')
            GROUP BY sr.subject, sr.session_date, sr.status
            ORDER BY sr.session_date DESC
            LIMIT ?
        ", 'ii', [$mentorId, $limit]);

        foreach ($rows as &$r) {
            $r['mentee_ids'] = array_map('intval', explode(',', (string)$r['mentee_ids']));
        }
        return $rows;
    }

    /** The mentee ids this assessment was set for. Empty means all of them. */
    public static function audienceFor(mysqli $con, int $assessmentId): array
    {
        if (!self::audienceEnabled($con)) {
            return [];
        }
        return array_map('intval', array_column(
            self::rows($con, "SELECT mentee_id FROM assessment_mentees WHERE assessment_id = ?", 'i', [$assessmentId]),
            'mentee_id'
        ));
    }

    /**
     * Replaces who an assessment is for.
     *
     * An empty list clears the rows, which puts the paper back to "all my
     * mentees" — the same meaning it had before anyone was named. Callers
     * must have checked the ids belong to this mentor; this only stores them.
     */
    public static function setAudience(mysqli $con, int $assessmentId, array $menteeIds): void
    {
        if (!self::audienceEnabled($con)) {
            return;
        }

        self::execute($con, "DELETE FROM assessment_mentees WHERE assessment_id = ?", 'i', [$assessmentId]);

        $ids = array_values(array_unique(array_filter(array_map('intval', $menteeIds), fn($id) => $id > 0)));
        if (!$ids) {
            return;
        }

        $values = implode(', ', array_fill(0, count($ids), '(?, ?)'));
        $args   = [];
        foreach ($ids as $id) {
            $args[] = $assessmentId;
            $args[] = $id;
        }
        self::execute($con, "INSERT INTO assessment_mentees (assessment_id, mentee_id) VALUES $values",
            str_repeat('ii', count($ids)), $args);
    }

    /** The assessment with the counts a card shows, for its author. */
    public static function forMentor(mysqli $con, int $mentorId): array
    {
        return self::rows($con, "
            SELECT a.*, " . self::COUNTS . "
            FROM assessments a
            WHERE a.mentor_id = ?
            ORDER BY a.created_at DESC, a.assessment_id DESC
        ", 'i', [$mentorId]);
    }

    /**
     * Published assessments from the mentors this mentee works with, each with
     * their latest attempt ('attempt_id', 'attempt_status', 'score',
     * 'scored_out_of', 'submitted_at') and how many attempts they have made.
     */
    public static function forMentee(mysqli $con, int $menteeId): array
    {
        [$aud, $audTypes, $audArgs] = self::audience($con, $menteeId);

        return self::rows($con, "
            SELECT a.*, u.firstname, u.lastname, " . self::COUNTS . ",
                   last.attempt_id, last.status AS attempt_status, last.score,
                   last.total_points AS scored_out_of, last.submitted_at, last.started_at,
                   (SELECT COUNT(*) FROM assessment_attempts t2
                     WHERE t2.assessment_id = a.assessment_id AND t2.mentee_id = ?) AS my_attempts
            FROM assessments a
            JOIN users u ON u.user_id = a.mentor_id
            LEFT JOIN assessment_attempts last
                   ON last.attempt_id = (SELECT t3.attempt_id FROM assessment_attempts t3
                                          WHERE t3.assessment_id = a.assessment_id AND t3.mentee_id = ?
                                          ORDER BY t3.started_at DESC, t3.attempt_id DESC LIMIT 1)
            WHERE a.status = 'published' AND " . self::HAS_SESSION . $aud . "
            ORDER BY (last.attempt_id IS NOT NULL AND last.status = 'submitted'), a.published_at DESC, a.assessment_id DESC
        ", 'iii' . $audTypes, array_merge([$menteeId, $menteeId, $menteeId], $audArgs));
    }

    // ── A mentee's own figures (their dashboard) ────────────────────────────

    /** How many assessments the mentee has submitted, ever. */
    public static function countSubmittedByMentee(mysqli $con, int $menteeId): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) FROM assessment_attempts
            WHERE mentee_id = ? AND status = 'submitted'
        ", 'i', [$menteeId]);
    }

    /**
     * How many the mentee submitted between $fromDaysAgo and $toDaysAgo days
     * ago. $toDaysAgo = 0 means "up to now", with no upper limit at all, as the
     * dashboard's "last 30 days" figure has always counted.
     */
    public static function countSubmittedByMenteeBetween(mysqli $con, int $menteeId, int $fromDaysAgo, int $toDaysAgo = 0): int
    {
        $sql = "
            SELECT COUNT(*) FROM assessment_attempts
            WHERE mentee_id = ? AND status = 'submitted'
              AND submitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        if ($toDaysAgo === 0) {
            return (int)self::value($con, $sql, 'ii', [$menteeId, $fromDaysAgo]);
        }
        return (int)self::value($con, $sql . " AND submitted_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            'iii', [$menteeId, $fromDaysAgo, $toDaysAgo]);
    }

    /**
     * Published assessments from mentors this mentee has an approved or
     * completed session with, each with their latest attempt if there is one.
     * Unsubmitted first, then newest published first.
     */
    public static function publishedForMentee(mysqli $con, int $menteeId): array
    {
        [$aud, $audTypes, $audArgs] = self::audience($con, $menteeId);

        return self::rows($con, "
            SELECT a.assessment_id, a.title, a.topic,
                   u.firstname, u.lastname,
                   last.status AS attempt_status, last.score, last.total_points
            FROM assessments a
            JOIN users u ON u.user_id = a.mentor_id
            LEFT JOIN assessment_attempts last
                   ON last.attempt_id = (SELECT t.attempt_id FROM assessment_attempts t
                                          WHERE t.assessment_id = a.assessment_id AND t.mentee_id = ?
                                          ORDER BY t.started_at DESC, t.attempt_id DESC LIMIT 1)
            WHERE a.status = 'published' AND " . self::HAS_SESSION . $aud . "
            ORDER BY (last.attempt_id IS NOT NULL AND last.status = 'submitted'), a.published_at DESC
        ", 'ii' . $audTypes, array_merge([$menteeId, $menteeId], $audArgs));
    }

    /** The mentee's average score across submitted attempts, as a whole percentage (0 when none). */
    public static function averageScorePercentForMentee(mysqli $con, int $menteeId): int
    {
        return (int)self::value($con, "
            SELECT COALESCE(ROUND(AVG(score / NULLIF(total_points,0) * 100)), 0)
            FROM assessment_attempts
            WHERE mentee_id = ? AND status = 'submitted'
        ", 'i', [$menteeId]);
    }

    // ── Writing an assessment ───────────────────────────────────────────────

    /** Stores a new assessment and returns its id. */
    public static function create(mysqli $con, int $mentorId, string $title, string $topic, string $instructions, ?int $timeLimit, string $status): int
    {
        return self::insert($con, "
            INSERT INTO assessments (mentor_id, title, topic, instructions, time_limit_minutes, status, published_at)
            VALUES (?, ?, ?, ?, ?, ?, CASE WHEN ? = 'published' THEN NOW() ELSE NULL END)
        ", 'isssiss', [$mentorId, $title, $topic, $instructions, $timeLimit, $status, $status]);
    }

    /** Updates the details of an assessment this mentor wrote. Publishing stamps the date once. */
    public static function update(mysqli $con, int $assessmentId, int $mentorId, string $title, string $topic, string $instructions, ?int $timeLimit, string $status): int
    {
        return self::execute($con, "
            UPDATE assessments
               SET title = ?, topic = ?, instructions = ?, time_limit_minutes = ?, status = ?,
                   published_at = CASE WHEN ? = 'published' AND published_at IS NULL THEN NOW() ELSE published_at END
             WHERE assessment_id = ? AND mentor_id = ?
        ", 'sssissii', [$title, $topic, $instructions, $timeLimit, $status, $status, $assessmentId, $mentorId]);
    }

    /** Publishes or unpublishes, whoever asked (an admin may). Returns rows changed. */
    public static function setStatus(mysqli $con, int $assessmentId, string $status): int
    {
        return $status === 'published'
            ? self::execute($con, "UPDATE assessments SET status = 'published', published_at = COALESCE(published_at, NOW()) WHERE assessment_id = ? AND status <> 'published'", 'i', [$assessmentId])
            : self::execute($con, "UPDATE assessments SET status = 'draft' WHERE assessment_id = ? AND status <> 'draft'", 'i', [$assessmentId]);
    }

    /**
     * Deletes an assessment this mentor wrote. Questions, options, attempts and
     * answers go with it: the database cascades from this one row.
     */
    public static function deleteOwned(mysqli $con, int $assessmentId, int $mentorId): int
    {
        return self::execute($con, "DELETE FROM assessments WHERE assessment_id = ? AND mentor_id = ?", 'ii', [$assessmentId, $mentorId]);
    }

    // ── Questions and options ───────────────────────────────────────────────

    /** Every question of an assessment, in order, each with its options under 'options'. */
    public static function questionsWithOptions(mysqli $con, int $assessmentId): array
    {
        $questions = self::rows($con, "
            SELECT * FROM assessment_questions WHERE assessment_id = ?
            ORDER BY question_order ASC, question_id ASC
        ", 'i', [$assessmentId]);
        if (!$questions) {
            return [];
        }
        $options = self::rows($con, "
            SELECT o.* FROM assessment_options o
            JOIN assessment_questions q ON q.question_id = o.question_id
            WHERE q.assessment_id = ?
            ORDER BY o.option_order ASC, o.option_id ASC
        ", 'i', [$assessmentId]);
        $byQuestion = [];
        foreach ($options as $o) {
            $byQuestion[(int)$o['question_id']][] = $o;
        }
        foreach ($questions as $i => $q) {
            $questions[$i]['options'] = $byQuestion[(int)$q['question_id']] ?? [];
        }
        return $questions;
    }

    /** One question, only when it belongs to that assessment. */
    public static function question(mysqli $con, int $questionId, int $assessmentId): ?array
    {
        return self::row($con, "
            SELECT question_id, question_type, points, correct_text
            FROM assessment_questions WHERE question_id = ? AND assessment_id = ?
        ", 'ii', [$questionId, $assessmentId]);
    }

    /** Whether an option belongs to a question, and whether it is the right one. */
    public static function option(mysqli $con, int $optionId, int $questionId): ?array
    {
        return self::row($con, "SELECT is_correct FROM assessment_options WHERE option_id = ? AND question_id = ?", 'ii', [$optionId, $questionId]);
    }

    /** What the assessment is worth now, as the sum of its questions' points. */
    public static function totalPoints(mysqli $con, int $assessmentId): int
    {
        return (int)self::value($con, "SELECT COALESCE(SUM(points), 0) FROM assessment_questions WHERE assessment_id = ?", 'i', [$assessmentId]);
    }

    /**
     * Replaces the whole question set with $questions (each 'type', 'text',
     * 'hint', 'points', 'is_required', 'correct_text', 'options' and an
     * optional 'solution'). Run inside the caller's transaction: the old
     * questions go first, and the database clears the answers that pointed
     * at them.
     *
     * The solution column is only named when it exists, so this works either
     * side of assessment_solution.sql. Where it does not exist the working is
     * dropped rather than the save failing — the question itself is the part
     * that matters, and the caller warns about it.
     */
    public static function replaceQuestions(mysqli $con, int $assessmentId, array $questions): void
    {
        $withSolution = self::solutionEnabled($con);

        self::execute($con, "DELETE FROM assessment_questions WHERE assessment_id = ?", 'i', [$assessmentId]);
        foreach (array_values($questions) as $i => $q) {
            $cols = [$assessmentId, $i + 1, $q['type'], $q['text'], $q['hint'], $q['points'], $q['is_required'], $q['correct_text']];
            $sql  = "INSERT INTO assessment_questions
                        (assessment_id, question_order, question_type, question_text, hint, points, is_required, correct_text"
                . ($withSolution ? ', solution' : '') . ")
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?" . ($withSolution ? ', ?' : '') . ')';
            $types = 'iisssiis';
            if ($withSolution) {
                $types .= 's';
                $cols[] = (string)($q['solution'] ?? '');
            }
            $questionId = self::insert($con, $sql, $types, $cols);
            foreach (array_values($q['options']) as $j => $o) {
                self::insert($con, "
                    INSERT INTO assessment_options (question_id, option_order, option_text, is_correct)
                    VALUES (?, ?, ?, ?)
                ", 'iisi', [$questionId, $j + 1, $o['text'], $o['is_correct']]);
            }
        }
    }

    /** The right answer to each question: 'text' for a short answer, or the option. */
    public static function correctAnswers(mysqli $con, int $assessmentId): array
    {
        $map = [];
        foreach (self::rows($con, "
            SELECT q.question_id, q.correct_text, o.option_id, o.option_text, o.is_correct
            FROM assessment_questions q
            LEFT JOIN assessment_options o ON o.question_id = q.question_id
            WHERE q.assessment_id = ?
            ORDER BY q.question_id, o.option_order
        ", 'i', [$assessmentId]) as $r) {
            $qid = (int)$r['question_id'];
            $map[$qid] ??= ['text' => $r['correct_text'], 'option_id' => null, 'option_text' => null];
            if ((int)$r['is_correct'] === 1 && $r['option_id'] !== null) {
                $map[$qid]['option_id']   = (int)$r['option_id'];
                $map[$qid]['option_text'] = $r['option_text'];
            }
        }
        return $map;
    }

    // ── Attempts ────────────────────────────────────────────────────────────

    /** The mentee's attempt that is still open on this assessment, if any. */
    public static function openAttempt(mysqli $con, int $assessmentId, int $menteeId): ?array
    {
        return self::row($con, "
            SELECT * FROM assessment_attempts
            WHERE assessment_id = ? AND mentee_id = ? AND status = 'in_progress'
            ORDER BY started_at DESC, attempt_id DESC LIMIT 1
        ", 'ii', [$assessmentId, $menteeId]);
    }

    /** The mentee's most recent attempt at this assessment, open or finished. */
    public static function latestAttempt(mysqli $con, int $assessmentId, int $menteeId): ?array
    {
        return self::row($con, "
            SELECT * FROM assessment_attempts
            WHERE assessment_id = ? AND mentee_id = ?
            ORDER BY started_at DESC, attempt_id DESC LIMIT 1
        ", 'ii', [$assessmentId, $menteeId]);
    }

    /** Every attempt this mentee has made at this assessment, newest first. */
    public static function attemptHistory(mysqli $con, int $assessmentId, int $menteeId): array
    {
        return self::rows($con, "
            SELECT * FROM assessment_attempts
            WHERE assessment_id = ? AND mentee_id = ?
            ORDER BY started_at DESC, attempt_id DESC
        ", 'ii', [$assessmentId, $menteeId]);
    }

    /** One of the mentee's own attempts, or null. */
    public static function attemptOf(mysqli $con, int $attemptId, int $menteeId): ?array
    {
        return self::row($con, "SELECT * FROM assessment_attempts WHERE attempt_id = ? AND mentee_id = ?", 'ii', [$attemptId, $menteeId]);
    }

    /** An open attempt of this mentee's, with what it is an attempt at. */
    public static function openAttemptWithAssessment(mysqli $con, int $attemptId, int $menteeId): ?array
    {
        return self::row($con, "
            SELECT t.attempt_id, t.assessment_id, a.title, a.mentor_id, a.time_limit_minutes,
                   TIMESTAMPDIFF(SECOND, t.started_at, NOW()) AS elapsed
            FROM assessment_attempts t
            JOIN assessments a ON a.assessment_id = t.assessment_id
            WHERE t.attempt_id = ? AND t.mentee_id = ? AND t.status = 'in_progress'
        ", 'ii', [$attemptId, $menteeId]);
    }

    /** Starts an attempt and returns its id. */
    public static function startAttempt(mysqli $con, int $assessmentId, int $menteeId, int $totalPoints): int
    {
        return self::insert($con, "
            INSERT INTO assessment_attempts (assessment_id, mentee_id, total_points) VALUES (?, ?, ?)
        ", 'iii', [$assessmentId, $menteeId, $totalPoints]);
    }

    /** Seconds since an attempt started, by the database's clock. */
    public static function elapsedSeconds(mysqli $con, int $attemptId): int
    {
        return (int)self::value($con, "SELECT TIMESTAMPDIFF(SECOND, started_at, NOW()) FROM assessment_attempts WHERE attempt_id = ?", 'i', [$attemptId]);
    }

    /** The answers stored for an attempt, keyed by question id. */
    public static function answersFor(mysqli $con, int $attemptId): array
    {
        $out = [];
        foreach (self::rows($con, "SELECT * FROM assessment_answers WHERE attempt_id = ?", 'i', [$attemptId]) as $r) {
            $out[(int)$r['question_id']] = $r;
        }
        return $out;
    }

    /** Stores one answer, replacing any earlier answer to the same question. */
    public static function saveAnswer(mysqli $con, int $attemptId, int $questionId, ?int $optionId, ?string $text, int $isCorrect, int $points, int $isFlagged): void
    {
        self::execute($con, "
            INSERT INTO assessment_answers
                (attempt_id, question_id, selected_option_id, answer_text, is_correct, points_earned, is_flagged)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                selected_option_id = VALUES(selected_option_id),
                answer_text        = VALUES(answer_text),
                is_correct         = VALUES(is_correct),
                points_earned      = VALUES(points_earned),
                is_flagged         = VALUES(is_flagged),
                answered_at        = NOW()
        ", 'iiisiii', [$attemptId, $questionId, $optionId, $text, $isCorrect, $points, $isFlagged]);
    }

    /** What an attempt has earned, and what the assessment is worth, in one look. */
    public static function attemptTotals(mysqli $con, int $attemptId, int $assessmentId): array
    {
        $row = self::row($con, "
            SELECT (SELECT COALESCE(SUM(points_earned), 0) FROM assessment_answers WHERE attempt_id = ?) AS score,
                   (SELECT COALESCE(SUM(points), 0) FROM assessment_questions WHERE assessment_id = ?) AS total
        ", 'ii', [$attemptId, $assessmentId]);
        return ['score' => (int)($row['score'] ?? 0), 'total' => (int)($row['total'] ?? 0)];
    }

    /** Closes an attempt with its score. Zero when it was already closed. */
    public static function submitAttempt(mysqli $con, int $attemptId, int $menteeId, int $score, int $total): int
    {
        return self::execute($con, "
            UPDATE assessment_attempts
               SET status = 'submitted', submitted_at = NOW(), score = ?, total_points = ?
             WHERE attempt_id = ? AND mentee_id = ? AND status = 'in_progress'
        ", 'iiii', [$score, $total, $attemptId, $menteeId]);
    }

    /** How many attempts at this assessment have been submitted. */
    public static function countSubmitted(mysqli $con, int $assessmentId): int
    {
        return (int)self::value($con, "SELECT COUNT(*) FROM assessment_attempts WHERE assessment_id = ? AND status = 'submitted'", 'i', [$assessmentId]);
    }

    /** How many attempts this mentee has submitted at this assessment. */
    public static function countSubmittedBy(mysqli $con, int $assessmentId, int $menteeId): int
    {
        return (int)self::value($con, "
            SELECT COUNT(*) FROM assessment_attempts
            WHERE assessment_id = ? AND mentee_id = ? AND status = 'submitted'
        ", 'ii', [$assessmentId, $menteeId]);
    }

    /** How many mentees have an attempt open on this assessment right now. */
    public static function countInProgress(mysqli $con, int $assessmentId): int
    {
        return (int)self::value($con, "SELECT COUNT(*) FROM assessment_attempts WHERE assessment_id = ? AND status = 'in_progress'", 'i', [$assessmentId]);
    }

    // ── Results, for the mentor ─────────────────────────────────────────────

    /**
     * Every submitted attempt at this assessment, newest first, with who made
     * it and which attempt of theirs it was ('attempt_no' of 'attempts_by_them').
     */
    public static function submittedAttempts(mysqli $con, int $assessmentId): array
    {
        return self::rows($con, "
            SELECT t.*, u.firstname, u.lastname,
                   (SELECT COUNT(*) FROM assessment_attempts e
                     WHERE e.assessment_id = t.assessment_id AND e.mentee_id = t.mentee_id
                       AND e.status = 'submitted' AND e.submitted_at <= t.submitted_at) AS attempt_no,
                   (SELECT COUNT(*) FROM assessment_attempts e2
                     WHERE e2.assessment_id = t.assessment_id AND e2.mentee_id = t.mentee_id
                       AND e2.status = 'submitted') AS attempts_by_them
            FROM assessment_attempts t
            JOIN users u ON u.user_id = t.mentee_id
            WHERE t.assessment_id = ? AND t.status = 'submitted'
            ORDER BY t.submitted_at DESC, t.attempt_id DESC
        ", 'i', [$assessmentId]);
    }

    /** How each question fared across every submitted attempt. */
    public static function questionResults(mysqli $con, int $assessmentId): array
    {
        return self::rows($con, "
            SELECT q.question_id, q.question_order, q.question_text, q.points,
                   COUNT(ans.answer_id) AS answered,
                   COALESCE(SUM(ans.is_correct), 0) AS correct
            FROM assessment_questions q
            LEFT JOIN assessment_answers ans
                   ON ans.question_id = q.question_id
                  AND ans.attempt_id IN (SELECT attempt_id FROM assessment_attempts
                                          WHERE assessment_id = ? AND status = 'submitted')
            WHERE q.assessment_id = ?
            GROUP BY q.question_id, q.question_order, q.question_text, q.points
            ORDER BY q.question_order ASC, q.question_id ASC
        ", 'ii', [$assessmentId, $assessmentId]);
    }
}
