<?php

/**
 * MessageService — who may message whom. The Messages page and the send
 * endpoint both ask here, so the page never offers a message box that the
 * endpoint will refuse.
 *
 *   Mentors and mentees message each other.
 *   Admins may message any mentor or mentee; a member can reply once an
 *   admin has written to them, not start a conversation with one.
 *   A blocked or deleted account receives nothing. A restricted one still
 *   does: a restriction stops them sending, not reading.
 */
class MessageService
{
    public const MAX_LENGTH = 2000;

    /** Why $fromId may not message $toId, or null when they may. */
    public static function refusal(mysqli $con, int $fromId, int $toId): ?string
    {
        if ($toId === $fromId) {
            return "You can't message yourself.";
        }
        $them = UserRepository::moderationTarget($con, $toId);
        if (!$them || (string)$them['role'] === '' || ModerationService::isDeleted($them)) {
            return 'That person could not be found.';
        }
        if ($them['status'] === 'blocked') {
            return 'That account is not available for messages.';
        }

        $me      = UserRepository::moderationTarget($con, $fromId);
        $myRole  = (string)($me['role'] ?? '');
        $members = ['mentee', 'mentor'];

        if ($myRole === 'admin') {
            return in_array($them['role'], $members, true) ? null : 'Admins can message mentors and mentees.';
        }
        if (!in_array($myRole, $members, true)) {
            return 'You can only message your mentor or mentee.';
        }
        if ($them['role'] === 'admin') {
            return MessageRepository::hasSent($con, $toId, $fromId)
                ? null
                : 'You can reply to an admin once they have written to you.';
        }
        if (!in_array($them['role'], $members, true) || $myRole === $them['role']) {
            return 'You can only message your mentor or mentee.';
        }
        return null;
    }
}
