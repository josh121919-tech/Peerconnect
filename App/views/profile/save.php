<?php
// Save the free-text parts of a profile: About Me, and the three tag sets
// (interests / skills I'm developing / areas I want to learn).
// Role-agnostic on purpose — the first-login questionnaire posts here too.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
header('Content-Type: application/json');

/** A posted text field. A field sent as a list counts as missing, never as the text "Array". */
function pf_post(string $name): string
{
    return is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
}

$role = $_SESSION['role'] ?? '';
// 'onboarding-role' is the one section a role-less account may post: it is how
// such an account sets its role in the first place (see onboarding/role_choice.php).
$claiming_role = pf_post('section') === 'onboarding-role';
if (empty($_SESSION['user_id'])
    || (!in_array($role, ['mentee', 'mentor'], true) && !$claiming_role)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not signed in.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

// None of the forms send these as lists. Refuse such a request instead of
// reading the field as empty, which would clear a whole tag set.
foreach (['section', 'bio', 'interests', 'skills', 'learn', 'role'] as $name) {
    if (isset($_POST[$name]) && !is_string($_POST[$name])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'That request could not be read. Please refresh the page and try again.']);
        exit;
    }
}

$user_id = (int)$_SESSION['user_id'];
$section = pf_post('section');   // which part of the profile is being saved

// For PC_ONB_MAX — the questionnaire's per-step cap lives with its option lists.
require_once __DIR__ . '/../../config/onboarding_catalog.php';

/** Trim, drop blanks, de-duplicate case-insensitively, cap the count. */
function pf_norm_tags(array $raw, int $cap = 20): array
{
    $out = [];
    foreach ($raw as $t) {
        if (!is_string($t)) continue;
        $t = trim(preg_replace('/\s+/u', ' ', strip_tags($t)));
        if ($t === '') continue;
        $t = mb_substr($t, 0, 120);   // matches user_tags.tag
        // Case-insensitive de-dupe, first spelling wins.
        if (!in_array(mb_strtolower($t), array_map('mb_strtolower', $out), true)) {
            $out[] = $t;
        }
        if (count($out) >= $cap) break;
    }
    return $out;
}

/**
 * Read a posted tag field.
 *
 * A JSON array is the real format — catalog answers contain commas of their
 * own ("Science, Technology and Society (STS)"), and the older comma-separated
 * form silently split those into two tags. The comma form is still accepted so
 * an older cached page can't fail closed.
 */
function pf_tags(string $raw, int $cap = 20): array
{
    if (str_starts_with(ltrim($raw), '[')) {
        $list = json_decode($raw, true);
        if (is_array($list)) return pf_norm_tags($list, $cap);
    }
    return pf_norm_tags(explode(',', $raw), $cap);
}

$con->begin_transaction();
try {
    // The questionnaire posts no bio field at all; only touch the bio when the
    // form that was submitted actually carries one, or skipping onboarding
    // would wipe a bio the user had already written.
    if (($section === 'about' || $section === 'onboarding') && is_string($_POST['bio'] ?? null)) {
        $bio = mb_substr(trim(strip_tags(pf_post('bio'))), 0, 500);
        ProfileRepository::saveBio($con, $user_id, $bio);
    }

    // Not 'about' any more: that section is the bio, and the bio editor no
    // longer carries interests — they are picked in the questionnaire, where
    // they come from the catalogue. Left coupled, saving a bio with no
    // interests field would have wiped them.
    if ($section === 'interests') {
        ProfileRepository::replaceTags($con, $user_id, 'interest', pf_tags(pf_post('interests')));
    }

    if ($section === 'skills') {
        ProfileRepository::replaceTags($con, $user_id, 'skill', pf_tags(pf_post('skills')));
        ProfileRepository::replaceTags($con, $user_id, 'learn', pf_tags(pf_post('learn')));
    }

    if ($section === 'onboarding' || $section === 'onboarding-skip') {
        // PC_ONB_MAX mirrors the questionnaire's "Select up to 5" — enforced
        // here too, since the browser cap is only a convenience.
        $sets = [
            'interest' => pf_post('interests'),
            'skill'    => pf_post('skills'),
            'learn'    => pf_post('learn'),
        ];
        foreach ($sets as $type => $raw) {
            $tags = pf_tags($raw, PC_ONB_MAX);
            // Skipping saves whatever was already picked but must never clear
            // a set — a half-finished questionnaire is not an instruction to
            // delete tags the user entered on their Profile page earlier.
            if (!$tags && $section === 'onboarding-skip') continue;
            ProfileRepository::replaceTags($con, $user_id, $type, $tags);
        }
    }

    // ── Claiming a role, for an account that has none ───────────────────
    // The role is read back from the database rather than trusted from the
    // session, and written only when it is genuinely empty. An account that
    // already has a role cannot change it here — that would be a privilege
    // escalation dressed up as onboarding. Admins repair roles from
    // admin/user_view.php instead.
    if ($section === 'onboarding-role') {
        $wanted = pf_post('role');
        if (!in_array($wanted, ['mentee', 'mentor'], true)) {
            throw new RuntimeException('bad role');
        }

        $existing = (string)(UserRepository::role($con, $user_id) ?? '');

        if (in_array($existing, ['mentee', 'mentor', 'admin'], true)) {
            throw new RuntimeException('role already set');
        }

        if (UserRepository::claimRole($con, $user_id, $wanted) < 1) {
            throw new RuntimeException('role not written');
        }
        $_SESSION['role'] = $wanted;
        logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'), "role set to {$wanted}");
    }

    // ── First-login questionnaire stamps ────────────────────────────────
    // Stamped with the database's clock, like every other timestamp here.
    if ($section === 'onboarding' || $section === 'onboarding-skip') {
        ProfileRepository::ensureRow($con, $user_id);

        // Skipping only silences the login redirect. Answering clears the skip
        // stamp too, so a user who skips and later finishes ends up clean.
        if ($section === 'onboarding') {
            ProfileRepository::markOnboarded($con, $user_id);
        } else {
            ProfileRepository::markOnboardingSkipped($con, $user_id);
        }
    }

    $con->commit();
} catch (Throwable $e) {
    $con->rollback();
    echo json_encode(['success' => false, 'message' => 'Could not save. Please try again.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Saved.']);
