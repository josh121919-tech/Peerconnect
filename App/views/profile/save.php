<?php
// Save the free-text parts of a profile: About Me, and the three tag sets
// (interests / skills I'm developing / areas I want to learn).
// Role-agnostic on purpose — the first-login questionnaire posts here too.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
header('Content-Type: application/json');

$role = $_SESSION['role'] ?? '';
// 'onboarding-role' is the one section a role-less account may post: it is how
// such an account sets its role in the first place (see onboarding/role_choice.php).
$claiming_role = ($_POST['section'] ?? '') === 'onboarding-role';
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

$user_id = (int)$_SESSION['user_id'];
$section = $_POST['section'] ?? '';   // which part of the profile is being saved

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

/** Replace one tag set for this user, inside a transaction. */
function pf_replace_tags(mysqli $con, int $user_id, string $type, array $tags): void
{
    $del = $con->prepare("DELETE FROM user_tags WHERE user_id = ? AND tag_type = ?");
    $del->bind_param("is", $user_id, $type);
    $del->execute();
    $del->close();

    if (!$tags) return;

    $ins = $con->prepare("INSERT IGNORE INTO user_tags (user_id, tag_type, tag) VALUES (?, ?, ?)");
    foreach ($tags as $t) {
        $ins->bind_param("iss", $user_id, $type, $t);
        $ins->execute();
    }
    $ins->close();
}

/**
 * Make sure this user has a profile row before we UPDATE columns on it.
 * Most accounts never had one — the row was only ever created the first time
 * they edited their profile, and onboarding now runs before that.
 */
function pf_ensure_profile_row(mysqli $con, int $user_id): void
{
    $ins = $con->prepare("
        INSERT INTO profile (user_id, full_name)
        SELECT ?, TRIM(CONCAT(COALESCE(firstname,''), ' ', COALESCE(lastname,'')))
        FROM users WHERE user_id = ?
        ON DUPLICATE KEY UPDATE profile_id = profile_id
    ");
    $ins->bind_param("ii", $user_id, $user_id);
    $ins->execute();
    $ins->close();
}

$con->begin_transaction();
try {
    // The questionnaire posts no bio field at all; only touch the bio when the
    // form that was submitted actually carries one, or skipping onboarding
    // would wipe a bio the user had already written.
    if (($section === 'about' || $section === 'onboarding') && array_key_exists('bio', $_POST)) {
        $bio = mb_substr(trim(strip_tags((string)($_POST['bio'] ?? ''))), 0, 500);
        $up = $con->prepare("
            INSERT INTO profile (user_id, bio) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE bio = VALUES(bio)
        ");
        $up->bind_param("is", $user_id, $bio);
        $up->execute();
        $up->close();
    }

    if ($section === 'about' || $section === 'interests') {
        pf_replace_tags($con, $user_id, 'interest', pf_tags((string)($_POST['interests'] ?? '')));
    }

    if ($section === 'skills') {
        pf_replace_tags($con, $user_id, 'skill', pf_tags((string)($_POST['skills'] ?? '')));
        pf_replace_tags($con, $user_id, 'learn', pf_tags((string)($_POST['learn'] ?? '')));
    }

    if ($section === 'onboarding' || $section === 'onboarding-skip') {
        // PC_ONB_MAX mirrors the questionnaire's "Select up to 5" — enforced
        // here too, since the browser cap is only a convenience.
        $sets = [
            'interest' => (string)($_POST['interests'] ?? ''),
            'skill'    => (string)($_POST['skills']    ?? ''),
            'learn'    => (string)($_POST['learn']     ?? ''),
        ];
        foreach ($sets as $type => $raw) {
            $tags = pf_tags($raw, PC_ONB_MAX);
            // Skipping saves whatever was already picked but must never clear
            // a set — a half-finished questionnaire is not an instruction to
            // delete tags the user entered on their Profile page earlier.
            if (!$tags && $section === 'onboarding-skip') continue;
            pf_replace_tags($con, $user_id, $type, $tags);
        }
    }

    // ── Claiming a role, for an account that has none ───────────────────
    // The role is read back from the database rather than trusted from the
    // session, and written only when it is genuinely empty. An account that
    // already has a role cannot change it here — that would be a privilege
    // escalation dressed up as onboarding. Admins repair roles from
    // admin/user_view.php instead.
    if ($section === 'onboarding-role') {
        $wanted = $_POST['role'] ?? '';
        if (!in_array($wanted, ['mentee', 'mentor'], true)) {
            throw new RuntimeException('bad role');
        }

        $cur = $con->prepare("SELECT role FROM users WHERE user_id = ? LIMIT 1");
        $cur->bind_param("i", $user_id);
        $cur->execute();
        $existing = (string)($cur->get_result()->fetch_row()[0] ?? '');
        $cur->close();

        if (in_array($existing, ['mentee', 'mentor', 'admin'], true)) {
            throw new RuntimeException('role already set');
        }

        $rw = $con->prepare("UPDATE users SET role = ? WHERE user_id = ? AND (role IS NULL OR role = '')");
        $rw->bind_param("si", $wanted, $user_id);
        $rw->execute();
        $changed = $rw->affected_rows;
        $rw->close();

        if ($changed < 1) {
            throw new RuntimeException('role not written');
        }
        $_SESSION['role'] = $wanted;
        logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'), "role set to {$wanted}");
    }

    // ── First-login questionnaire stamps ────────────────────────────────
    // NOW() rather than PHP's date(): PHP runs on Europe/Berlin here while
    // every other timestamp in this database is Asia/Manila.
    if ($section === 'onboarding' || $section === 'onboarding-skip') {
        pf_ensure_profile_row($con, $user_id);

        // Skipping only silences the login redirect. Answering clears the skip
        // stamp too, so a user who skips and later finishes ends up clean.
        $sql = $section === 'onboarding'
            ? "UPDATE profile SET onboarded_at = NOW(), onboarding_skipped_at = NULL WHERE user_id = ?"
            : "UPDATE profile SET onboarding_skipped_at = NOW() WHERE user_id = ? AND onboarded_at IS NULL";
        $st = $con->prepare($sql);
        $st->bind_param("i", $user_id);
        $st->execute();
        $st->close();
    }

    $con->commit();
} catch (Throwable $e) {
    $con->rollback();
    echo json_encode(['success' => false, 'message' => 'Could not save. Please try again.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Saved.']);
