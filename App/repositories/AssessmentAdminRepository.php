<?php

/**
 * AssessmentAdminRepository — what the three admin assessment screens read:
 * the assessment list, the question bank and the results.
 *
 * Admins watch assessments rather than write them, so everything here reads.
 * The one write an admin makes — publishing or taking one offline — belongs
 * to the assessment itself and lives in AssessmentRepository::setStatus().
 *
 * Each list takes its filters as an array and builds its own conditions, so a
 * page never assembles SQL. Searches match the characters typed: % and _ are
 * ordinary characters, not wildcards.
 */
class AssessmentAdminRepository extends Repository
{
    /** The columns every assessment row shows, with its live counts. */
    private const LIST_SELECT = "
        SELECT a.assessment_id, a.title, a.topic, a.instructions, a.status,
               a.time_limit_minutes, a.created_at, a.published_at, a.mentor_id,
               CONCAT_WS(' ', u.firstname, u.lastname) AS mentor_name,
               p.profile_image AS mentor_pic,
               (SELECT COUNT(*) FROM assessment_questions q WHERE q.assessment_id = a.assessment_id) AS questions,
               (SELECT COALESCE(SUM(q.points), 0) FROM assessment_questions q WHERE q.assessment_id = a.assessment_id) AS total_points,
               (SELECT COUNT(*) FROM assessment_attempts t WHERE t.assessment_id = a.assessment_id) AS attempts,
               (SELECT COUNT(*) FROM assessment_attempts t WHERE t.assessment_id = a.assessment_id AND t.status = 'submitted') AS submitted,
               (SELECT COUNT(*) FROM assessment_attempts t WHERE t.assessment_id = a.assessment_id AND t.status = 'in_progress') AS in_progress,
               (SELECT COUNT(DISTINCT t.mentee_id) FROM assessment_attempts t WHERE t.assessment_id = a.assessment_id AND t.status = 'submitted') AS mentees,
               (SELECT MAX(t.submitted_at) FROM assessment_attempts t WHERE t.assessment_id = a.assessment_id) AS last_submitted,
               -- Averaged as a percentage of each attempt's own total, so an
               -- assessment worth 14 points and one worth 100 compare properly.
               (SELECT AVG(t.score / NULLIF(t.total_points, 0) * 100)
                  FROM assessment_attempts t
                 WHERE t.assessment_id = a.assessment_id AND t.status = 'submitted') AS avg_pct
        FROM assessments a
        JOIN users u ON u.user_id = a.mentor_id
        LEFT JOIN profile p ON p.user_id = a.mentor_id";

    /** The answer counts the question bank needs, over submitted attempts only. */
    private const QUESTION_BASE = "
        FROM assessment_questions q
        JOIN assessments a ON a.assessment_id = q.assessment_id
        JOIN users u ON u.user_id = a.mentor_id
        LEFT JOIN (
            SELECT an.question_id,
                   COUNT(*) answered,
                   SUM(an.is_correct = 1) correct,
                   SUM(an.is_flagged = 1) flagged
            FROM assessment_answers an
            JOIN assessment_attempts t ON t.attempt_id = an.attempt_id AND t.status = 'submitted'
            GROUP BY an.question_id
        ) s ON s.question_id = q.question_id";

    /** A LIKE pattern matching exactly what was typed. */
    private static function like(string $text): string
    {
        return '%' . addcslashes($text, '%_\\') . '%';
    }

    /** ['sql' => conditions, 'types' => ..., 'args' => [...]] from a filter set. */
    private static function conditions(array $filters, array $map): array
    {
        $clauses = [];
        $types = '';
        $args = [];
        foreach ($map as $key => [$sql, $type, $prepare]) {
            $value = $filters[$key] ?? null;
            if ($value === null || $value === '' || $value === 0) {
                continue;
            }
            $clauses[] = $sql;
            $types .= $type;
            foreach ((array)$prepare($value) as $arg) {
                $args[] = $arg;
            }
        }
        return ['sql' => $clauses, 'types' => $types, 'args' => $args];
    }

    // ── The assessment list ─────────────────────────────────────────────────

    private const ATTEMPT_EXISTS = "EXISTS (SELECT 1 FROM assessment_attempts t WHERE t.assessment_id = a.assessment_id)";

    private const LIST_VIEWS = [
        'published' => "a.status = 'published'",
        'draft'     => "a.status = 'draft'",
        'attempted' => self::ATTEMPT_EXISTS,
        'untouched' => "NOT " . self::ATTEMPT_EXISTS,
    ];

    private const LIST_SORTS = [
        'newest'   => 'a.created_at DESC, a.assessment_id DESC',
        'oldest'   => 'a.created_at ASC, a.assessment_id ASC',
        'attempts' => 'attempts DESC, a.created_at DESC',
        'score'    => 'avg_pct DESC, attempts DESC',
        'title'    => 'a.title ASC',
    ];

    /** Filters: 'q' (text), 'topic', 'mentor' (id). */
    private static function listConditions(array $filters): array
    {
        return self::conditions($filters, [
            'q'      => ["CONCAT_WS(' ', a.title, a.topic, u.firstname, u.lastname) LIKE ?", 's', fn($v) => self::like((string)$v)],
            'topic'  => ['a.topic = ?', 's', fn($v) => (string)$v],
            'mentor' => ['a.mentor_id = ?', 'i', fn($v) => (int)$v],
        ]);
    }

    /** How many assessments each tab holds under the current filters. */
    public static function listTabCounts(mysqli $con, array $filters): array
    {
        $c = self::listConditions($filters);
        $where = $c['sql'] ? 'WHERE ' . implode(' AND ', $c['sql']) : '';
        $row = self::row($con, "
            SELECT COUNT(*) AS all_c,
                   SUM(a.status = 'published') AS published,
                   SUM(a.status = 'draft') AS draft,
                   SUM(" . self::ATTEMPT_EXISTS . ") AS attempted,
                   SUM(NOT " . self::ATTEMPT_EXISTS . ") AS untouched
            FROM assessments a JOIN users u ON u.user_id = a.mentor_id
            $where
        ", $c['types'], $c['args']);
        return array_map('intval', $row ?? []);
    }

    /** How many assessments match the filters and the chosen tab. */
    public static function countMatching(mysqli $con, array $filters, string $view): int
    {
        $c = self::listConditions($filters);
        $all = $c['sql'];
        if (isset(self::LIST_VIEWS[$view])) {
            $all[] = self::LIST_VIEWS[$view];
        }
        $where = $all ? 'WHERE ' . implode(' AND ', $all) : '';
        return (int)self::value($con, "
            SELECT COUNT(*) FROM assessments a JOIN users u ON u.user_id = a.mentor_id $where
        ", $c['types'], $c['args']);
    }

    /** One page of the assessment list. */
    public static function page(mysqli $con, array $filters, string $view, string $sort, int $limit, int $offset): array
    {
        $c = self::listConditions($filters);
        $all = $c['sql'];
        if (isset(self::LIST_VIEWS[$view])) {
            $all[] = self::LIST_VIEWS[$view];
        }
        $where = $all ? 'WHERE ' . implode(' AND ', $all) : '';
        $order = self::LIST_SORTS[$sort] ?? self::LIST_SORTS['newest'];
        return self::rows($con, self::LIST_SELECT . " $where ORDER BY $order LIMIT ? OFFSET ?",
            $c['types'] . 'ii', array_merge($c['args'], [$limit, $offset]));
    }

    /** One assessment with the same columns the list shows. */
    public static function detail(mysqli $con, int $assessmentId): ?array
    {
        return self::row($con, self::LIST_SELECT . " WHERE a.assessment_id = ? LIMIT 1", 'i', [$assessmentId]);
    }

    /** Every assessment with those columns, newest first — for the CSV. */
    public static function all(mysqli $con): array
    {
        return self::rows($con, self::LIST_SELECT . " ORDER BY a.created_at DESC, a.assessment_id DESC");
    }

    /** Assessments, attempts and the like, across the platform, as ints. */
    public static function headline(mysqli $con): array
    {
        $row = self::row($con, "
            SELECT (SELECT COUNT(*) FROM assessments)                                          AS assessments,
                   (SELECT COUNT(*) FROM assessments WHERE status = 'published')               AS published,
                   (SELECT COUNT(*) FROM assessments WHERE status = 'draft')                   AS draft,
                   (SELECT COUNT(*) FROM assessment_attempts WHERE status = 'submitted')       AS submitted,
                   (SELECT COUNT(*) FROM assessment_attempts WHERE status = 'in_progress')     AS in_progress,
                   (SELECT COUNT(DISTINCT mentee_id) FROM assessment_attempts)                 AS mentees
        ");
        return array_map('intval', $row ?? []);
    }

    /** The topics assessments actually carry. */
    public static function topics(mysqli $con): array
    {
        return array_column(self::rows($con, "SELECT DISTINCT topic FROM assessments WHERE topic <> '' ORDER BY topic"), 'topic');
    }

    /** The mentors who have written one, for the filter. */
    public static function mentors(mysqli $con): array
    {
        return self::rows($con, "
            SELECT DISTINCT u.user_id, CONCAT_WS(' ', u.firstname, u.lastname) nm
            FROM assessments a JOIN users u ON u.user_id = a.mentor_id ORDER BY nm
        ");
    }

    /** Assessments per topic, most first. */
    public static function byTopic(mysqli $con, int $limit): array
    {
        return self::rows($con, "
            SELECT COALESCE(NULLIF(topic, ''), 'No topic set') t, COUNT(*) c
            FROM assessments GROUP BY t ORDER BY c DESC, t LIMIT ?
        ", 'i', [$limit]);
    }

    /** The attempts touched most recently, whatever state they are in. */
    public static function recentAttempts(mysqli $con, int $limit): array
    {
        return self::rows($con, "
            SELECT t.attempt_id, t.submitted_at, t.started_at, t.status, t.score, t.total_points,
                   a.title, a.assessment_id,
                   CONCAT_WS(' ', u.firstname, u.lastname) AS mentee_name
            FROM assessment_attempts t
            JOIN assessments a ON a.assessment_id = t.assessment_id
            JOIN users u ON u.user_id = t.mentee_id
            ORDER BY COALESCE(t.submitted_at, t.started_at) DESC, t.attempt_id DESC
            LIMIT ?
        ", 'i', [$limit]);
    }

    // ── The question bank ───────────────────────────────────────────────────

    private const QUESTION_VIEWS = [
        'answered'   => 'COALESCE(s.answered, 0) > 0',
        'unanswered' => 'COALESCE(s.answered, 0) = 0',
        'hard'       => 'COALESCE(s.answered, 0) > 0 AND (s.correct / s.answered) < 0.6',
        'flagged'    => 'COALESCE(s.flagged, 0) > 0',
    ];

    /** Filters: 'q', 'qtype', 'topic', 'mentor'. */
    private static function questionConditions(array $filters): array
    {
        return self::conditions($filters, [
            'q'      => ["CONCAT_WS(' ', q.question_text, q.hint, a.title, a.topic) LIKE ?", 's', fn($v) => self::like((string)$v)],
            'qtype'  => ['q.question_type = ?', 's', fn($v) => (string)$v],
            'topic'  => ['a.topic = ?', 's', fn($v) => (string)$v],
            'mentor' => ['a.mentor_id = ?', 'i', fn($v) => (int)$v],
        ]);
    }

    public static function questionTabCounts(mysqli $con, array $filters): array
    {
        $c = self::questionConditions($filters);
        $where = $c['sql'] ? 'WHERE ' . implode(' AND ', $c['sql']) : '';
        $row = self::row($con, "
            SELECT COUNT(*) AS all_c,
                   SUM(COALESCE(s.answered, 0) > 0) AS answered,
                   SUM(COALESCE(s.answered, 0) = 0) AS unanswered,
                   SUM(COALESCE(s.answered, 0) > 0 AND (s.correct / s.answered) < 0.6) AS hard,
                   SUM(COALESCE(s.flagged, 0) > 0) AS flagged
            " . self::QUESTION_BASE . " $where
        ", $c['types'], $c['args']);
        return array_map('intval', $row ?? []);
    }

    public static function countQuestions(mysqli $con, array $filters, string $view): int
    {
        $c = self::questionConditions($filters);
        $all = $c['sql'];
        if (isset(self::QUESTION_VIEWS[$view])) {
            $all[] = self::QUESTION_VIEWS[$view];
        }
        $where = $all ? 'WHERE ' . implode(' AND ', $all) : '';
        return (int)self::value($con, "SELECT COUNT(*) " . self::QUESTION_BASE . " $where", $c['types'], $c['args']);
    }

    private const QUESTION_SORTS = [
        'newest'  => 'q.question_id DESC',
        'answers' => 'COALESCE(s.answered,0) DESC, q.question_id DESC',
        'hardest' => 'CASE WHEN COALESCE(s.answered,0) > 0 THEN s.correct / s.answered ELSE 2 END ASC, COALESCE(s.answered,0) DESC, q.question_id DESC',
        'points'  => 'q.points DESC, q.question_id DESC',
    ];

    /** One page of the question bank, with how each question has fared. */
    public static function questionPage(mysqli $con, array $filters, string $view, int $limit, int $offset, string $sort = 'bank'): array
    {
        $c = self::questionConditions($filters);
        $all = $c['sql'];
        if (isset(self::QUESTION_VIEWS[$view])) {
            $all[] = self::QUESTION_VIEWS[$view];
        }
        $where = $all ? 'WHERE ' . implode(' AND ', $all) : '';
        return self::rows($con, "
            SELECT q.question_id, q.assessment_id, q.question_type, q.question_text, q.hint,
                   q.points, q.is_required, q.correct_text, q.question_order,
                   a.title AS assessment_title, a.topic, a.status AS assessment_status,
                   a.mentor_id, CONCAT_WS(' ', u.firstname, u.lastname) AS mentor_name,
                   COALESCE(s.answered, 0) answered, COALESCE(s.correct, 0) correct,
                   COALESCE(s.flagged, 0) flagged,
                   (SELECT COUNT(*) FROM assessment_options o WHERE o.question_id = q.question_id) options_n
            " . self::QUESTION_BASE . " $where
            ORDER BY " . (self::QUESTION_SORTS[$sort] ?? 'a.created_at DESC, a.assessment_id DESC, q.question_order ASC, q.question_id ASC') . "
            LIMIT ? OFFSET ?
        ", $c['types'] . 'ii', array_merge($c['args'], [$limit, $offset]));
    }

    /** Every question with its options spelled out, for the CSV. */
    public static function allQuestions(mysqli $con): array
    {
        return self::rows($con, "
            SELECT q.*, a.title AS assessment_title, a.topic,
                   CONCAT_WS(' ', u.firstname, u.lastname) AS mentor_name,
                   (SELECT COUNT(*) FROM assessment_options o WHERE o.question_id = q.question_id) options_n,
                   (SELECT GROUP_CONCAT(o.option_text SEPARATOR ' | ')
                      FROM assessment_options o WHERE o.question_id = q.question_id AND o.is_correct = 1) correct_options
            FROM assessment_questions q
            JOIN assessments a ON a.assessment_id = q.assessment_id
            JOIN users u ON u.user_id = a.mentor_id
            ORDER BY a.assessment_id, q.question_order, q.question_id
        ");
    }

    /** Questions, the assessments and mentors behind them, and the average points. */
    public static function questionHeadline(mysqli $con): array
    {
        $row = self::row($con, "
            SELECT COUNT(*) AS questions,
                   COUNT(DISTINCT q.assessment_id) AS assessments,
                   COUNT(DISTINCT a.mentor_id) AS mentors,
                   AVG(q.points) AS avg_points
            FROM assessment_questions q JOIN assessments a ON a.assessment_id = q.assessment_id
        ");
        return [
            'questions'   => (int)($row['questions'] ?? 0),
            'assessments' => (int)($row['assessments'] ?? 0),
            'mentors'     => (int)($row['mentors'] ?? 0),
            'avg_points'  => $row['avg_points'] ?? null,
        ];
    }

    /** Answers given in submitted attempts, and how many were right. */
    public static function answerTotals(mysqli $con): array
    {
        $row = self::row($con, "
            SELECT COUNT(*) AS answered, COALESCE(SUM(an.is_correct = 1), 0) AS correct
            FROM assessment_answers an
            JOIN assessment_attempts t ON t.attempt_id = an.attempt_id AND t.status = 'submitted'
        ");
        return ['answered' => (int)($row['answered'] ?? 0), 'correct' => (int)($row['correct'] ?? 0)];
    }

    public static function questionTopics(mysqli $con): array
    {
        return array_column(self::rows($con, "
            SELECT DISTINCT a.topic FROM assessments a
            JOIN assessment_questions q ON q.assessment_id = a.assessment_id
            WHERE a.topic <> '' ORDER BY a.topic
        "), 'topic');
    }

    public static function questionMentors(mysqli $con): array
    {
        return self::rows($con, "
            SELECT DISTINCT u.user_id, CONCAT_WS(' ', u.firstname, u.lastname) nm
            FROM assessment_questions q
            JOIN assessments a ON a.assessment_id = q.assessment_id
            JOIN users u ON u.user_id = a.mentor_id ORDER BY nm
        ");
    }

    public static function questionsByType(mysqli $con): array
    {
        return self::rows($con, "SELECT question_type t, COUNT(*) c FROM assessment_questions GROUP BY question_type ORDER BY c DESC, t");
    }

    public static function questionsByTopic(mysqli $con, int $limit): array
    {
        return self::rows($con, "
            SELECT COALESCE(NULLIF(a.topic,''), 'No topic set') t, COUNT(*) c
            FROM assessment_questions q JOIN assessments a ON a.assessment_id = q.assessment_id
            GROUP BY t ORDER BY c DESC, t LIMIT ?
        ", 'i', [$limit]);
    }

    /** How one question has fared across submitted attempts. */
    public static function questionStats(mysqli $con, int $questionId): array
    {
        $row = self::row($con, "
            SELECT COUNT(*) AS answered,
                   COALESCE(SUM(an.is_correct = 1), 0) AS correct,
                   COALESCE(SUM(an.is_flagged = 1), 0) AS flagged
            FROM assessment_answers an
            JOIN assessment_attempts t ON t.attempt_id = an.attempt_id
            WHERE an.question_id = ? AND t.status = 'submitted'
        ", 'i', [$questionId]);
        $answered = (int)($row['answered'] ?? 0);
        $correct  = (int)($row['correct'] ?? 0);
        return [
            'answered' => $answered,
            'correct'  => $correct,
            'flagged'  => (int)($row['flagged'] ?? 0),
            'pct'      => $answered > 0 ? round($correct / $answered * 100) : null,
        ];
    }

    // ── Results ─────────────────────────────────────────────────────────────

    /** Attempts in a date range (by when they started), as ints, plus the average percentage. */
    public static function attemptFigures(mysqli $con, ?string $from, ?string $to): array
    {
        [$where, $types, $args] = self::rangeWhere($from, $to);
        $row = self::row($con, "
            SELECT COUNT(*) AS attempts,
                   COALESCE(SUM(t.status = 'submitted'), 0) AS submitted,
                   AVG(CASE WHEN t.status = 'submitted' THEN t.score / NULLIF(t.total_points,0) * 100 END) AS avg_pct,
                   COUNT(DISTINCT t.mentee_id) AS mentees
            FROM assessment_attempts t $where
        ", $types, $args);
        return [
            'attempts'  => (int)($row['attempts'] ?? 0),
            'submitted' => (int)($row['submitted'] ?? 0),
            'mentees'   => (int)($row['mentees'] ?? 0),
            'avg_pct'   => $row['avg_pct'] ?? null,
        ];
    }

    /** The percentage each submitted attempt scored, for the score bands. */
    public static function scorePercents(mysqli $con, ?string $from, ?string $to): array
    {
        [$where, $types, $args] = self::rangeWhere($from, $to, "t.status = 'submitted' AND t.total_points > 0");
        return array_map('intval', array_column(self::rows($con, "
            SELECT ROUND(t.score / NULLIF(t.total_points,0) * 100) pct FROM assessment_attempts t $where
        ", $types, $args), 'pct'));
    }

    /** Every assessment with how it has been attempted in the range. */
    public static function perAssessment(mysqli $con, ?string $from, ?string $to): array
    {
        $join = $from ? "AND DATE(t.started_at) BETWEEN ? AND ?" : '';
        return self::rows($con, "
            SELECT a.assessment_id, a.title, a.topic, a.status,
                   CONCAT_WS(' ', u.firstname, u.lastname) mentor_name,
                   COUNT(t.attempt_id) attempts,
                   COALESCE(SUM(t.status = 'submitted'), 0) submitted,
                   COUNT(DISTINCT CASE WHEN t.status = 'submitted' THEN t.mentee_id END) mentees,
                   AVG(CASE WHEN t.status = 'submitted' THEN t.score / NULLIF(t.total_points,0) * 100 END) avg_pct
            FROM assessments a
            JOIN users u ON u.user_id = a.mentor_id
            LEFT JOIN assessment_attempts t ON t.assessment_id = a.assessment_id $join
            GROUP BY a.assessment_id, a.title, a.topic, a.status, mentor_name
            ORDER BY attempts DESC, a.created_at DESC, a.assessment_id DESC
        ", $from ? 'ss' : '', $from ? [$from, $to] : []);
    }

    /** The questions people get wrong most often. */
    public static function hardestQuestions(mysqli $con, ?string $from, ?string $to, int $limit): array
    {
        $join = $from ? "AND DATE(t.started_at) BETWEEN ? AND ?" : '';
        $args = $from ? [$from, $to, $limit] : [$limit];
        return self::rows($con, "
            SELECT q.question_id, q.question_text, q.question_type, q.points,
                   a.assessment_id, a.title,
                   COUNT(an.answer_id) answered,
                   COALESCE(SUM(an.is_correct = 1), 0) correct
            FROM assessment_questions q
            JOIN assessments a ON a.assessment_id = q.assessment_id
            JOIN assessment_answers an ON an.question_id = q.question_id
            JOIN assessment_attempts t ON t.attempt_id = an.attempt_id AND t.status = 'submitted' $join
            GROUP BY q.question_id, q.question_text, q.question_type, q.points, a.assessment_id, a.title
            HAVING COUNT(an.answer_id) > 0
            -- MariaDB will not let ORDER BY reach an aggregate's alias, so the
            -- ratio is spelled out again rather than reusing `correct / answered`.
            ORDER BY (SUM(an.is_correct = 1) / COUNT(an.answer_id)) ASC, COUNT(an.answer_id) DESC, q.question_id
            LIMIT ?
        ", ($from ? 'ss' : '') . 'i', $args);
    }

    /** Filters for the attempt list: 'from'/'to', 'status', 'q' (person or assessment). */
    private static function attemptConditions(array $filters): array
    {
        $clauses = [];
        $types = '';
        $args = [];
        if (!empty($filters['from'])) {
            $clauses[] = 'DATE(t.started_at) BETWEEN ? AND ?';
            $types .= 'ss';
            $args[] = $filters['from'];
            $args[] = $filters['to'];
        }
        if (!empty($filters['assessment'])) {
            $clauses[] = 't.assessment_id = ?';
            $types .= 'i';
            $args[] = (int)$filters['assessment'];
        }
        if (!empty($filters['status'])) {
            $clauses[] = 't.status = ?';
            $types .= 's';
            $args[] = $filters['status'];
        }
        if (($filters['q'] ?? '') !== '') {
            $clauses[] = "CONCAT_WS(' ', u.firstname, u.lastname, a.title, a.topic) LIKE ?";
            $types .= 's';
            $args[] = self::like((string)$filters['q']);
        }
        return ['sql' => $clauses, 'types' => $types, 'args' => $args];
    }

    public static function countAttempts(mysqli $con, array $filters): int
    {
        $c = self::attemptConditions($filters);
        $where = $c['sql'] ? 'WHERE ' . implode(' AND ', $c['sql']) : '';
        return (int)self::value($con, "
            SELECT COUNT(*) FROM assessment_attempts t
            JOIN assessments a ON a.assessment_id = t.assessment_id
            JOIN users u ON u.user_id = t.mentee_id $where
        ", $c['types'], $c['args']);
    }

    /**
     * One page of attempts, newest activity first, each saying which attempt
     * of that mentee's at that assessment it was.
     */
    public static function attemptPage(mysqli $con, array $filters, int $limit, int $offset): array
    {
        $c = self::attemptConditions($filters);
        $where = $c['sql'] ? 'WHERE ' . implode(' AND ', $c['sql']) : '';
        return self::rows($con, "
            SELECT t.attempt_id, t.mentee_id, t.status, t.score, t.total_points, t.started_at, t.submitted_at,
                   a.assessment_id, a.title, a.topic, a.status AS assessment_status,
                   CONCAT_WS(' ', u.firstname, u.lastname) AS mentee_name, u.role,
                   CONCAT_WS(' ', m.firstname, m.lastname) AS mentor_name,
                   pr.profile_image,
                   (SELECT COUNT(*) FROM assessment_attempts e
                     WHERE e.assessment_id = t.assessment_id AND e.mentee_id = t.mentee_id
                       AND e.started_at <= t.started_at) AS attempt_no,
                   (SELECT COUNT(*) FROM assessment_attempts e2
                     WHERE e2.assessment_id = t.assessment_id AND e2.mentee_id = t.mentee_id) AS attempts_by_them
            FROM assessment_attempts t
            JOIN assessments a ON a.assessment_id = t.assessment_id
            JOIN users u ON u.user_id = t.mentee_id
            JOIN users m ON m.user_id = a.mentor_id
            LEFT JOIN profile pr ON pr.user_id = t.mentee_id
            $where
            ORDER BY COALESCE(t.submitted_at, t.started_at) DESC, t.attempt_id DESC
            LIMIT ? OFFSET ?
        ", $c['types'] . 'ii', array_merge($c['args'], [$limit, $offset]));
    }

    /** Every attempt under the filters, for the CSV. */
    public static function allAttempts(mysqli $con, array $filters): array
    {
        return self::attemptPage($con, $filters, 100000, 0);
    }

    /** How many mentees could take an assessment, and how many have tried one. */
    public static function menteeReach(mysqli $con): array
    {
        $row = self::row($con, "
            SELECT (SELECT COUNT(DISTINCT sr.mentee_id)
                      FROM session_requests sr
                      JOIN assessments a ON a.mentor_id = sr.mentor_id AND a.status = 'published'
                     WHERE sr.status IN ('approved','completed')) AS reachable,
                   (SELECT COUNT(DISTINCT mentee_id) FROM assessment_attempts) AS attempted
        ");
        return ['reachable' => (int)($row['reachable'] ?? 0), 'attempted' => (int)($row['attempted'] ?? 0)];
    }

    /** WHERE for a date range on t.started_at, with anything else already required. */
    private static function rangeWhere(?string $from, ?string $to, string $extra = ''): array
    {
        $clauses = [];
        $types = '';
        $args = [];
        if ($from) {
            $clauses[] = 'DATE(t.started_at) BETWEEN ? AND ?';
            $types .= 'ss';
            $args = [$from, $to];
        }
        if ($extra !== '') {
            $clauses[] = $extra;
        }
        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $types, $args];
    }
}
