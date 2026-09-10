<?php
/**
 * onboarding_gate.php — sends a user who has never answered the first-login
 * questionnaire to it once, then gets out of the way.
 *
 * Included at the top of both dashboards (mentee and mentor), which is the
 * first authenticated page either role lands on after verification. It is
 * deliberately NOT in app_shell: gating every page would trap anyone who
 * skipped, and the questionnaire itself would be unreachable.
 *
 * Two stamps, two meanings:
 *   onboarded_at          — answered it; never prompted again
 *   onboarding_skipped_at — chose to skip; never redirected again, but the
 *                           Profile page keeps offering to finish it
 * A missing `profile` row counts as neither, because most accounts only get
 * one the first time they edit their profile.
 */

if (!function_exists('pc_onboarding_state')) {
    /**
     * @return array{done:bool, skipped:bool} — whether this user has answered
     *         the questionnaire, and whether they skipped past it.
     */
    function pc_onboarding_state(mysqli $con, int $user_id): array
    {
        $q = $con->prepare("SELECT onboarded_at, onboarding_skipped_at FROM profile WHERE user_id = ? LIMIT 1");
        $q->bind_param("i", $user_id);
        $q->execute();
        $row = $q->get_result()->fetch_assoc() ?: [];
        $q->close();

        return [
            'done'    => !empty($row['onboarded_at']),
            'skipped' => !empty($row['onboarding_skipped_at']),
        ];
    }
}

if (!function_exists('pc_onboarding_gate')) {
    /** Redirects to the questionnaire and exits, or returns and lets the page render. */
    function pc_onboarding_gate(mysqli $con, int $user_id, string $role): void
    {
        if (!in_array($role, ['mentee', 'mentor'], true)) {
            return;
        }

        // Only ever interrupt a plain page visit. Both dashboards handle their
        // own POSTs (the mentor's approve/reject quick-actions live at the top
        // of mentorpage/index.php), and redirecting one of those would discard
        // the action silently.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }

        $state = pc_onboarding_state($con, $user_id);
        if ($state['done'] || $state['skipped']) {
            return;
        }

        header("Location: " . url('onboarding'));
        exit;
    }
}
