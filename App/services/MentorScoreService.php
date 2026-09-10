<?php
/**
 * MentorScoreService.php
 * Computes and persists the AI recommendation score for mentors.
 *
 * Score components (each normalized 0–100, then weighted):
 *   40% — Mentoring Effectiveness  (avg star rating × 20)
 *   25% — Session Completion Rate  (completed / total_approved × 100)
 *   15% — Experience               (log scale of completed sessions, capped at 100)
 *   10% — Engagement               (messages + feedback given per session)
 *   10% — Attendance Reliability   (1 - missed_rate × 100)
 */
class MentorScoreService
{
    public static function compute(mysqli $con, int $mentor_id): float
    {
        // ── 1. Rating ─────────────────────────────────────────────────────
        $q = $con->prepare("SELECT COALESCE(AVG(rating),0) as avg_r, COUNT(*) as cnt FROM feedback WHERE mentor_id=?");
        $q->bind_param("i", $mentor_id);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $q->close();
        $avg_rating   = (float)$r['avg_r'];
        $rating_count = (int)$r['cnt'];
        // Bayesian average: blend with platform avg (3.5) weighted by volume
        $bayesian_rating = ($avg_rating * $rating_count + 3.5 * 5) / ($rating_count + 5);
        $score_rating = ($bayesian_rating / 5.0) * 100; // 0–100

        // ── 2. Session Completion Rate ────────────────────────────────────
        // 'missed' belongs in the denominator — it was absent, so the missed
        // rate below was divided by a total that excluded the very sessions it
        // was counting. 'approved' is out: a session still ahead of us has not
        // closed, and counting it dragged the completion rate down.
        $q2 = $con->prepare("
            SELECT
                SUM(status='completed') as completed,
                SUM(status IN ('completed','rejected','cancelled','missed')) as total_closed
            FROM session_requests WHERE mentor_id=?
        ");
        $q2->bind_param("i", $mentor_id);
        $q2->execute();
        $r2 = $q2->get_result()->fetch_assoc();
        $q2->close();
        $completed   = (int)$r2['completed'];
        $total_closed = max(1, (int)$r2['total_closed']);
        $completion_rate = ($completed / $total_closed) * 100; // 0–100

        // ── 3. Experience (log scale) ─────────────────────────────────────
        // 0 sessions = 0, 5 sessions ≈ 35, 20 sessions ≈ 65, 50 sessions ≈ 100
        $score_experience = min(100, ($completed > 0 ? log($completed + 1, 1.1) : 0));

        // ── 4. Engagement ─────────────────────────────────────────────────
        // Average effectiveness sub-scores: communication, knowledge, skill, efficiency
        $q3 = $con->prepare("
            SELECT COALESCE(AVG((communication + efficiency + knowledge + skill) / 4.0), 0) as avg_eff
            FROM feedback WHERE mentor_id=? AND communication > 0
        ");
        $q3->bind_param("i", $mentor_id);
        $q3->execute();
        $r3 = $q3->get_result()->fetch_assoc();
        $q3->close();
        $score_engagement = (float)$r3['avg_eff'] * 20; // scale 0–5 → 0–100

        // ── 5. Attendance Reliability ────────────────────────────────────
        $q4 = $con->prepare("
            SELECT COUNT(*) as missed FROM session_requests
            WHERE mentor_id=? AND missed_by IN ('mentor','both')
        ");
        $q4->bind_param("i", $mentor_id);
        $q4->execute();
        $r4 = $q4->get_result()->fetch_assoc();
        $q4->close();
        $missed = (int)$r4['missed'];
        $missed_rate = $total_closed > 0 ? ($missed / $total_closed) : 0;
        $score_reliability = max(0, (1 - $missed_rate) * 100);

        // ── Weighted composite ────────────────────────────────────────────
        $final = (
            $score_rating      * 0.40 +
            $completion_rate   * 0.25 +
            $score_experience  * 0.15 +
            $score_engagement  * 0.10 +
            $score_reliability * 0.10
        );

        // ── Effectiveness score (stored separately for display) ───────────
        $effectiveness = (
            $score_rating    * 0.35 +
            $score_engagement * 0.35 +
            $completion_rate  * 0.30
        );

        // ── Upsert into mentor_scores ─────────────────────────────────────
        $stmt = $con->prepare("
            INSERT INTO mentor_scores
                (mentor_id, avg_rating, total_sessions, completion_rate, effectiveness_score, recommendation_score, last_calculated)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                avg_rating           = VALUES(avg_rating),
                total_sessions       = VALUES(total_sessions),
                completion_rate      = VALUES(completion_rate),
                effectiveness_score  = VALUES(effectiveness_score),
                recommendation_score = VALUES(recommendation_score),
                last_calculated      = NOW()
        ");
        $stmt->bind_param("iddddd",
            $mentor_id,
            $avg_rating,
            $completed,
            $completion_rate,
            $effectiveness,
            $final
        );
        $stmt->execute();
        $stmt->close();

        return round($final, 2);
    }

    /**
     * Recalculate scores for all active mentors.
     * Called by the cron endpoint or admin panel.
     */
    public static function refreshAll(mysqli $con): int
    {
        $result = $con->query("SELECT user_id FROM users WHERE role='mentor' AND status='active' AND verified=1");
        $count  = 0;
        while ($row = $result->fetch_assoc()) {
            self::compute($con, (int)$row['user_id']);
            $count++;
        }
        return $count;
    }

    /**
     * Relative weight of each questionnaire axis when matching a mentee to a
     * mentor. A Gen-Ed subject the mentee wants to learn and the mentor can
     * teach is a more direct fit than sharing a degree programme.
     *   learn    3 — subject the mentee wants / the mentor can mentor
     *   skill    2 — skill the mentee wants to develop / the mentor can coach
     *   interest 1 — same degree programme
     */
    public const TAG_WEIGHTS = ['learn' => 3, 'skill' => 2, 'interest' => 1];

    /** The above as a SQL CASE, so ordering can happen in the database. */
    private const TAG_WEIGHT_SQL =
        "CASE mt.tag_type WHEN 'learn' THEN 3 WHEN 'skill' THEN 2 ELSE 1 END";

    /**
     * The most a mentor could score against this mentee — every tag the mentee
     * chose, matched. 0 means the mentee has answered nothing yet, and callers
     * must read that as "no signal to match on", never as a 0% match.
     */
    public static function menteeTagWeight(mysqli $con, int $mentee_id): int
    {
        $q = $con->prepare("
            SELECT tag_type, COUNT(*) AS n FROM user_tags WHERE user_id = ? GROUP BY tag_type
        ");
        $q->bind_param("i", $mentee_id);
        $q->execute();
        $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        $total = 0;
        foreach ($rows as $r) {
            $total += (int)$r['n'] * (self::TAG_WEIGHTS[$r['tag_type']] ?? 1);
        }
        return $total;
    }

    /**
     * Which of a mentee's questionnaire answers each mentor also picked.
     *
     * Both roles choose from the same catalog (App/config/onboarding_catalog.php),
     * so an overlap is exact string equality — no fuzzy matching, and nothing
     * inferred. Returns [mentor_id => ['learn'=>[tag,…], 'skill'=>[…], …]],
     * with mentors who share nothing simply absent.
     *
     * @param int[] $mentor_ids Restrict to these mentors; empty = all mentors.
     */
    public static function sharedTags(mysqli $con, int $mentee_id, array $mentor_ids = []): array
    {
        $mentor_ids = array_values(array_unique(array_map('intval', $mentor_ids)));

        $sql = "
            SELECT mt.user_id, mt.tag_type, mt.tag
            FROM user_tags mt
            JOIN user_tags st
              ON st.user_id = ? AND st.tag_type = mt.tag_type AND st.tag = mt.tag
            JOIN users u ON u.user_id = mt.user_id AND u.role = 'mentor'
            WHERE mt.user_id <> ?
        ";
        // Ints already cast above, so this interpolation cannot carry input.
        if ($mentor_ids) {
            $sql .= " AND mt.user_id IN (" . implode(',', $mentor_ids) . ")";
        }
        $sql .= " ORDER BY mt.user_id, mt.tag_type, mt.tag";

        $q = $con->prepare($sql);
        $q->bind_param("ii", $mentee_id, $mentee_id);
        $q->execute();
        $res = $q->get_result();

        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[(int)$row['user_id']][$row['tag_type']][] = $row['tag'];
        }
        $q->close();

        return $out;
    }

    /**
     * 0–100: how much of what this mentee asked for one mentor covers.
     * 100 means the mentor matched every single answer the mentee gave.
     */
    public static function tagPercent(array $shared, int $menteeTagWeight): int
    {
        if ($menteeTagWeight <= 0) return 0;

        $matched = 0;
        foreach ($shared as $type => $tags) {
            $matched += count($tags) * (self::TAG_WEIGHTS[$type] ?? 1);
        }
        return (int)min(100, round($matched / $menteeTagWeight * 100));
    }

    /** Plain-language reason lines for the tags a mentee and mentor share. */
    public static function sharedTagReasons(array $shared): array
    {
        $labels = [
            'learn'    => 'Can mentor you in',
            'skill'    => 'Can help you develop',
            'interest' => 'Same programme',
        ];
        $out = [];
        foreach (['learn', 'skill', 'interest'] as $type) {
            if (empty($shared[$type])) continue;
            $out[] = $labels[$type] . ' ' . implode(', ', $shared[$type]);
        }
        return $out;
    }

    /**
     * Get ranked mentor list with AI scores, optionally filtered by preferences.
     * Returns array of mentor data sorted by recommendation_score DESC.
     */
    public static function getRankedMentors(
        mysqli $con,
        array  $prefs = [],
        int    $limit = 50,
        int    $mentee_id = 0
    ): array {
        $topicFilter  = $prefs['preferred_topic'] ?? '';
        $sessionType  = $prefs['session_type']    ?? 'any';
        $skillLevel   = $prefs['skill_level']     ?? '';

        $where = "u.role='mentor' AND u.status='active' AND u.verified=1";
        $types = '';
        $binds = [];

        // ── Questionnaire overlap ────────────────────────────────────────
        // Ranking on recommendation_score alone just replays the leaderboard,
        // so when this mentee has answered the questionnaire the overlap with
        // each mentor's answers leads and the performance score breaks ties.
        // Both halves are 0–100, so the blend is a straight weighted sum.
        $menteeWeight = $mentee_id > 0 ? self::menteeTagWeight($con, $mentee_id) : 0;
        $tagSelect    = "0 AS tag_weight";
        $order        = "rec_score DESC, avg_rating DESC, total_sessions DESC";

        if ($menteeWeight > 0) {
            $tagSelect = "COALESCE((
                    SELECT SUM(" . self::TAG_WEIGHT_SQL . ")
                    FROM user_tags mt
                    JOIN user_tags st
                      ON st.user_id = ? AND st.tag_type = mt.tag_type AND st.tag = mt.tag
                    WHERE mt.user_id = u.user_id
                ), 0) AS tag_weight";
            // LEAST() caps a mentor who somehow out-picks the mentee at 100%.
            $order = "(0.6 * LEAST(100, tag_weight * 100.0 / $menteeWeight) + 0.4 * rec_score) DESC,
                      rec_score DESC, avg_rating DESC";
            // This placeholder sits in the SELECT list, so it binds first.
            $types  .= 'i';
            $binds[] = &$mentee_id;
        }

        // preferred_topic is one free-text word typed on the Matching page, and
        // as a hard filter it empties the list whenever it doesn't happen to
        // appear in a mentor's expertise text ("math" vs "Mathematics"). Once
        // the mentee has answered the questionnaire, relevance is handled by
        // the ranking above, so the word only narrows the list for mentees who
        // have nothing else to match on.
        if ($topicFilter !== '' && $menteeWeight === 0) {
            $where  .= " AND (p.expertise LIKE ? OR p.club LIKE ?)";
            $types  .= 'ss';
            $like    = '%' . $topicFilter . '%';
            $binds[] = &$like;
            $binds[] = &$like;
        }

        if ($sessionType !== 'any' && $sessionType !== '') {
            // availability.session_type stores '1v1' (the same vocabulary Find a
            // Mentor filters on). '1-on-1' matched no row, so any mentee whose
            // preference was one_on_one silently got an empty list.
            $stype = ($sessionType === 'group') ? 'group' : '1v1';
            $where .= " AND EXISTS (SELECT 1 FROM availability a WHERE a.mentor_id=u.user_id AND a.session_type=?)";
            $types  .= 's';
            $binds[] = &$stype;
        }

        $sql = "
            SELECT
                u.user_id, u.firstname, u.lastname,
                p.expertise, p.club, p.course, p.year_level,
                pr.profile_image,
                COALESCE(ms.recommendation_score, 0) as rec_score,
                COALESCE(ms.avg_rating, 0)           as avg_rating,
                COALESCE(ms.total_sessions, 0)       as total_sessions,
                COALESCE(ms.completion_rate, 0)      as completion_rate,
                COALESCE(ms.effectiveness_score, 0)  as effectiveness_score,
                $tagSelect,
                (SELECT COUNT(*) FROM feedback f WHERE f.mentor_id=u.user_id) as review_count,
                (SELECT COUNT(*) FROM availability av WHERE av.mentor_id=u.user_id AND av.date >= CURDATE()) as available_slots
            FROM users u
            LEFT JOIN user_verifications p  ON p.user_id = u.user_id
            LEFT JOIN profile pr            ON pr.user_id = u.user_id
            LEFT JOIN mentor_scores ms      ON ms.mentor_id = u.user_id
            WHERE $where
            ORDER BY $order
            LIMIT ?
        ";

        $types  .= 'i';
        $binds[] = &$limit;

        $stmt = $con->prepare($sql);
        if ($types !== '') {
            array_unshift($binds, $types);
            call_user_func_array([$stmt, 'bind_param'], $binds);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Attach the actual overlapping answers, so callers can show *why* a
        // mentor is being recommended rather than asserting a bare number.
        if ($menteeWeight > 0 && $rows) {
            $shared = self::sharedTags($con, $mentee_id, array_column($rows, 'user_id'));
            foreach ($rows as &$row) {
                $mine = $shared[(int)$row['user_id']] ?? [];
                $row['shared_tags']    = $mine;
                $row['tag_percent']    = self::tagPercent($mine, $menteeWeight);
                $row['shared_reasons'] = self::sharedTagReasons($mine);
            }
            unset($row);
        }

        return $rows;
    }

    /**
     * Compute compatibility percentage between a mentee's preferences and a mentor.
     * Returns a 0–100 integer and an explanation string.
     */
    public static function computeCompatibility(array $mentor, array $prefs): array
    {
        $score = 0;
        $reasons = [];

        // Relevance half. When getRankedMentors() has attached questionnaire
        // overlap, that is the honest signal and it leads; otherwise fall back
        // to the older preferred_topic heuristic so mentees who have not
        // answered the questionnaire still get a sensible ranking.
        if (array_key_exists('tag_percent', $mentor)) {
            $score += (int)round(((int)$mentor['tag_percent'] / 100) * 55);
            foreach (($mentor['shared_reasons'] ?? []) as $r) {
                $reasons[] = $r;
            }
        } else {
            $expertise = strtolower($mentor['expertise'] ?? '');
            $topic     = strtolower($prefs['preferred_topic'] ?? '');
            if ($topic !== '' && str_contains($expertise, $topic)) {
                $score += 40;
                $reasons[] = "Expertise matches your interest in {$prefs['preferred_topic']}";
            } elseif ($topic !== '') {
                $score += 10; // partial credit for being in same domain
            } else {
                $score += 30; // no preference = neutral
            }
        }

        // Rating component
        $rating = (float)($mentor['avg_rating'] ?? 0);
        $score += (int)(($rating / 5.0) * 25);
        if ($rating >= 4.5) $reasons[] = "Highly rated by mentees ({$rating}/5)";

        // Session count component
        $sessions = (int)($mentor['total_sessions'] ?? 0);
        $expScore = min(20, (int)(log($sessions + 1, 1.1)));
        $score += $expScore;
        if ($sessions >= 10) $reasons[] = "{$sessions} sessions completed";

        // Availability
        $slots = (int)($mentor['available_slots'] ?? 0);
        if ($slots > 0) {
            $score += 15;
            $reasons[] = "Has {$slots} upcoming availability slot" . ($slots > 1 ? 's' : '');
        }

        $score = min(100, $score);
        if (empty($reasons)) {
            $reasons[] = array_key_exists('tag_percent', $mentor)
                ? 'Verified mentor — no overlap with your questionnaire answers yet'
                : 'Available and verified mentor';
        }

        return [
            'percent'  => $score,
            'reasons'  => $reasons,
            'label'    => self::compatibilityLabel($score),
        ];
    }

    private static function compatibilityLabel(int $score): string
    {
        if ($score >= 85) return 'Excellent Match';
        if ($score >= 70) return 'Strong Match';
        if ($score >= 50) return 'Good Match';
        if ($score >= 30) return 'Possible Match';
        return 'Fair Match';
    }
}
