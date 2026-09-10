<?php

/**
 * action_badge.php — create, edit, switch off and delete a badge.
 *
 * One handler keyed by a `do` field, the same shape action_settings.php uses.
 * Awarding a badge to a person is a different thing and stays in
 * action_award_badge.php, which also sends the notification.
 *
 * SECURITY: admin-only, POST-only, CSRF-checked, prepared statements.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/award_data.php';
require_admin();
require_post();

$expected = $_SESSION['csrf_token'] ?? '';
$given    = $_POST['csrf_token'] ?? '';
if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    exit('CSRF token mismatch.');
}

date_default_timezone_set('Asia/Manila');

/* Only ever redirect back inside this app: `back` arrives in a form field, so
   it is treated as untrusted and must be a path on this host. */
$back = (string)($_POST['back'] ?? '');
if ($back === '' || !preg_match('#^/[A-Za-z0-9/_\-?=&.%]*$#', $back) || str_starts_with($back, '//')) {
    $back = url('admin-badges');
}

$do   = (string)($_POST['do'] ?? '');
$id   = (int)($_POST['badge_id'] ?? 0);
$me   = (int)($_SESSION['user_id'] ?? 0);

/** Read a badge, or null. */
function bd_get(mysqli $con, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $con->prepare("SELECT * FROM badges WHERE badge_id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** The submitted fields, validated against what the app can actually use. */
function bd_fields(): array
{
    $name = trim(strip_tags((string)($_POST['name'] ?? '')));
    $desc = trim(strip_tags((string)($_POST['description'] ?? '')));

    $crit = (string)($_POST['criteria_type'] ?? 'manual');
    if (!array_key_exists($crit, aw_criteria_types())) $crit = 'manual';

    // A threshold only means something for the two rules that read one; for the
    // others it is stored as 0 rather than as a number nothing consults.
    $val = in_array($crit, ['sessions_completed', 'avg_rating'], true)
        ? max(0, min(10000, (int)($_POST['criteria_value'] ?? 0)))
        : 0;

    $icon = (string)($_POST['icon'] ?? 'medal');
    if (!array_key_exists($icon, aw_badge_icons())) $icon = 'medal';

    $color = (string)($_POST['color'] ?? 'amber');
    if (!array_key_exists($color, aw_colors())) $color = 'amber';

    return [$name, mb_substr($desc, 0, 400), $crit, $val, $icon, $color];
}

switch ($do) {

    /* ── Create ── */
    case 'create': {
        [$name, $desc, $crit, $val, $icon, $color] = bd_fields();
        if ($name === '') {
            pc_flash('error', 'A badge needs a name.', 'Not created');
            break;
        }

        $dupe = $con->prepare("SELECT badge_id FROM badges WHERE name = ? LIMIT 1");
        $dupe->bind_param('s', $name);
        $dupe->execute();
        $hit = $dupe->get_result()->fetch_row();
        $dupe->close();
        if ($hit) {
            pc_flash('error', 'A badge called "' . $name . '" already exists.', 'Not created');
            break;
        }

        $st = $con->prepare("
            INSERT INTO badges (name, description, icon_path, icon, color, criteria_type, criteria_value, is_active, created_by, created_at)
            VALUES (?, ?, '', ?, ?, ?, ?, 1, ?, NOW())
        ");
        $st->bind_param('sssssii', $name, $desc, $icon, $color, $crit, $val, $me);
        $ok = $st->execute();
        $st->close();

        if ($ok) {
            logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'), 'admin created badge: ' . $name);
            pc_flash('success', '"' . $name . '" is ready. It is active, so the award check will hand it out if it is automatic.', 'Badge created');
        } else {
            pc_flash('error', 'The badge could not be saved.', 'Not created');
        }
        break;
    }

    /* ── Edit ── */
    case 'update': {
        $badge = bd_get($con, $id);
        if (!$badge) {
            pc_flash('error', 'That badge no longer exists.', 'Not saved');
            break;
        }
        [$name, $desc, $crit, $val, $icon, $color] = bd_fields();
        if ($name === '') {
            pc_flash('error', 'A badge needs a name.', 'Not saved');
            break;
        }

        $dupe = $con->prepare("SELECT badge_id FROM badges WHERE name = ? AND badge_id <> ? LIMIT 1");
        $dupe->bind_param('si', $name, $id);
        $dupe->execute();
        $hit = $dupe->get_result()->fetch_row();
        $dupe->close();
        if ($hit) {
            pc_flash('error', 'Another badge is already called "' . $name . '".', 'Not saved');
            break;
        }

        $st = $con->prepare("
            UPDATE badges
               SET name = ?, description = ?, icon = ?, color = ?, criteria_type = ?, criteria_value = ?
             WHERE badge_id = ?
        ");
        $st->bind_param('sssssii', $name, $desc, $icon, $color, $crit, $val, $id);
        $ok = $st->execute();
        $st->close();

        if ($ok) {
            logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'), 'admin edited badge: ' . $name);
            pc_flash('success', '"' . $name . '" has been updated everywhere it appears.', 'Badge saved');
        } else {
            pc_flash('error', 'The badge could not be saved.', 'Not saved');
        }
        break;
    }

    /* ── Switch on / off ── */
    case 'toggle': {
        $badge = bd_get($con, $id);
        if (!$badge) {
            pc_flash('error', 'That badge no longer exists.', 'Nothing changed');
            break;
        }
        $now = (int)$badge['is_active'] === 1 ? 0 : 1;
        $st = $con->prepare("UPDATE badges SET is_active = ? WHERE badge_id = ?");
        $st->bind_param('ii', $now, $id);
        $st->execute();
        $st->close();

        logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'),
            'admin ' . ($now ? 'activated' : 'deactivated') . ' badge: ' . $badge['name']);

        pc_flash('success',
            $now
                ? '"' . $badge['name'] . '" is active again and will show on profiles.'
                : '"' . $badge['name'] . '" is switched off. It stops being awarded and is hidden from profiles; nobody loses the record.',
            $now ? 'Badge on' : 'Badge off');
        break;
    }

    /* ── Delete ── */
    case 'delete': {
        $badge = bd_get($con, $id);
        if (!$badge) {
            pc_flash('error', 'That badge no longer exists.', 'Nothing deleted');
            break;
        }

        $c = $con->prepare("SELECT COUNT(*) FROM user_badges WHERE badge_id = ?");
        $c->bind_param('i', $id);
        $c->execute();
        $held = (int)$c->get_result()->fetch_row()[0];
        $c->close();

        // The awards go first: user_badges has no cascade, so leaving them
        // behind would leave rows pointing at a badge that no longer exists.
        $d1 = $con->prepare("DELETE FROM user_badges WHERE badge_id = ?");
        $d1->bind_param('i', $id);
        $d1->execute();
        $d1->close();

        $d2 = $con->prepare("DELETE FROM badges WHERE badge_id = ?");
        $d2->bind_param('i', $id);
        $ok = $d2->execute();
        $d2->close();

        if ($ok) {
            logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'), 'admin deleted badge: ' . $badge['name']);
            pc_flash('success',
                '"' . $badge['name'] . '" is gone'
                . ($held > 0 ? ', along with ' . $held . ' award' . ($held === 1 ? '' : 's') . ' of it.' : '.'),
                'Badge deleted');
        } else {
            pc_flash('error', 'The badge could not be deleted.', 'Nothing deleted');
        }
        break;
    }

    default:
        pc_flash('error', 'That action is not recognised.', 'Nothing happened');
}

header('Location: ' . $back);
exit;
