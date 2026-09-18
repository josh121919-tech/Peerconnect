<?php

/**
 * GoalRepository — the goals a mentee sets with a mentor (the goals table).
 */
class GoalRepository extends Repository
{
    /** The goals each of $menteeIds has with this mentor ('mentee_id', 'title', 'status'), newest first. */
    public static function forMenteesWithMentor(mysqli $con, array $menteeIds, int $mentorId): array
    {
        if (!$menteeIds) {
            return [];
        }
        $ids = array_map('intval', array_values($menteeIds));
        return self::rows($con, "
            SELECT mentee_id, title, status FROM goals
            WHERE mentee_id IN (" . self::marks($ids) . ") AND mentor_id = ?
            ORDER BY created_at DESC
        ", str_repeat('i', count($ids)) . 'i', array_merge($ids, [$mentorId]));
    }

    /** The mentee sets themselves a goal. Returns its id. */
    public static function createForMentee(mysqli $con, int $menteeId, string $title): int
    {
        return self::insert($con, "INSERT INTO goals (mentee_id, title, created_by) VALUES (?, ?, ?)", 'isi', [$menteeId, $title, $menteeId]);
    }

    /** One of the mentee's own goals' status, or null when it is not theirs or does not exist. */
    public static function statusForMentee(mysqli $con, int $goalId, int $menteeId): ?string
    {
        return self::value($con, "SELECT status FROM goals WHERE goal_id = ? AND mentee_id = ? LIMIT 1", 'ii', [$goalId, $menteeId]);
    }

    /** Moves one of the mentee's goals to $status, stamping $completedAt (null unless completed). */
    public static function setStatusForMentee(mysqli $con, int $goalId, int $menteeId, string $status, ?string $completedAt): void
    {
        self::execute($con, "UPDATE goals SET status = ?, completed_at = ? WHERE goal_id = ? AND mentee_id = ?",
            'ssii', [$status, $completedAt, $goalId, $menteeId]);
    }
}
