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
}
