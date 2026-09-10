<?php

/**
 * action_certificate.php — certificate designs, and issuing them.
 *
 * One handler keyed by a `do` field:
 *   create / update / archive / delete   — the design
 *   issue / revoke                       — a certificate for one person
 *
 * SECURITY: admin-only, POST-only, CSRF-checked, prepared statements.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../../services/NotificationService.php';
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

/* `back` arrives in a form field, so it is untrusted: a path on this host only. */
$back = (string)($_POST['back'] ?? '');
if ($back === '' || !preg_match('#^/[A-Za-z0-9/_\-?=&.%]*$#', $back) || str_starts_with($back, '//')) {
    $back = url('admin-certificates');
}

$do = (string)($_POST['do'] ?? '');
$me = (int)($_SESSION['user_id'] ?? 0);
$id = (int)($_POST['template_id'] ?? 0);

function ct_get(mysqli $con, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $con->prepare("SELECT * FROM certificate_templates WHERE template_id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** The submitted design fields, validated against what the renderer can draw. */
function ct_fields(): array
{
    $name = trim(strip_tags((string)($_POST['name'] ?? '')));
    $desc = trim(strip_tags((string)($_POST['description'] ?? '')));

    $cat = (string)($_POST['category'] ?? 'Achievement');
    if (!in_array($cat, aw_cert_categories(), true)) $cat = 'Achievement';

    $design = (string)($_POST['design'] ?? 'classic');
    if (!array_key_exists($design, aw_cert_designs())) $design = 'classic';

    return [mb_substr($name, 0, 120), mb_substr($desc, 0, 400), $cat, $design];
}

switch ($do) {

    /* ── Create a design ── */
    case 'create': {
        [$name, $desc, $cat, $design] = ct_fields();
        if ($name === '') {
            pc_flash('error', 'A certificate needs a title.', 'Not created');
            break;
        }

        // template_path is written empty on purpose: certificates are rendered
        // from the design when opened, so there is no file for it to point at.
        $st = $con->prepare("
            INSERT INTO certificate_templates (name, description, category, design, template_path, is_active, created_by, created_at)
            VALUES (?, ?, ?, ?, '', 1, ?, NOW())
        ");
        $st->bind_param('ssssi', $name, $desc, $cat, $design, $me);
        $ok = $st->execute();
        $st->close();

        if ($ok) {
            logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'), 'admin created certificate design: ' . $name);
            pc_flash('success', '"' . $name . '" is ready to issue.', 'Design created');
        } else {
            pc_flash('error', 'The design could not be saved.', 'Not created');
        }
        break;
    }

    /* ── Edit a design ── */
    case 'update': {
        if (!ct_get($con, $id)) {
            pc_flash('error', 'That design no longer exists.', 'Not saved');
            break;
        }
        [$name, $desc, $cat, $design] = ct_fields();
        if ($name === '') {
            pc_flash('error', 'A certificate needs a title.', 'Not saved');
            break;
        }

        $st = $con->prepare("
            UPDATE certificate_templates
               SET name = ?, description = ?, category = ?, design = ?
             WHERE template_id = ?
        ");
        $st->bind_param('ssssi', $name, $desc, $cat, $design, $id);
        $ok = $st->execute();
        $st->close();

        if ($ok) {
            logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'), 'admin edited certificate design: ' . $name);
            pc_flash('success',
                '"' . $name . '" has been updated. Certificates already issued from it show the new design, '
                . 'because they are drawn when they are opened.',
                'Design saved');
        } else {
            pc_flash('error', 'The design could not be saved.', 'Not saved');
        }
        break;
    }

    /* ── Archive / restore ── */
    case 'archive': {
        $t = ct_get($con, $id);
        if (!$t) {
            pc_flash('error', 'That design no longer exists.', 'Nothing changed');
            break;
        }
        $now = (int)$t['is_active'] === 1 ? 0 : 1;
        $st = $con->prepare("UPDATE certificate_templates SET is_active = ? WHERE template_id = ?");
        $st->bind_param('ii', $now, $id);
        $st->execute();
        $st->close();

        logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'),
            'admin ' . ($now ? 'restored' : 'archived') . ' certificate design: ' . $t['name']);

        pc_flash('success',
            $now
                ? '"' . $t['name'] . '" can be issued again.'
                : '"' . $t['name'] . '" is archived and can no longer be issued. Certificates already given out still open and print.',
            $now ? 'Design restored' : 'Design archived');
        break;
    }

    /* ── Delete a design ── */
    case 'delete': {
        $t = ct_get($con, $id);
        if (!$t) {
            pc_flash('error', 'That design no longer exists.', 'Nothing deleted');
            break;
        }

        $c = $con->prepare("SELECT COUNT(*) FROM user_certificates WHERE template_id = ?");
        $c->bind_param('i', $id);
        $c->execute();
        $issued = (int)$c->get_result()->fetch_row()[0];
        $c->close();

        // The certificates go first: user_certificates has no cascade, and a
        // row pointing at a design that no longer exists cannot be rendered.
        $d1 = $con->prepare("DELETE FROM user_certificates WHERE template_id = ?");
        $d1->bind_param('i', $id);
        $d1->execute();
        $d1->close();

        $d2 = $con->prepare("DELETE FROM certificate_templates WHERE template_id = ?");
        $d2->bind_param('i', $id);
        $ok = $d2->execute();
        $d2->close();

        if ($ok) {
            logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'), 'admin deleted certificate design: ' . $t['name']);
            pc_flash('success',
                '"' . $t['name'] . '" is gone'
                . ($issued > 0 ? ', along with ' . $issued . ' certificate' . ($issued === 1 ? '' : 's') . ' issued from it.' : '.'),
                'Design deleted');
        } else {
            pc_flash('error', 'The design could not be deleted.', 'Nothing deleted');
        }
        break;
    }

    /* ── Issue one ── */
    case 'issue': {
        $uid = (int)($_POST['user_id'] ?? 0);
        $ach = trim(strip_tags((string)($_POST['achievement'] ?? '')));
        $ach = mb_substr($ach, 0, 255);

        if (!$uid || !$id || $ach === '') {
            pc_flash('error', 'Choose a recipient and a design, and say what the certificate is for.', 'Not issued');
            break;
        }

        $t = ct_get($con, $id);
        if (!$t || (int)$t['is_active'] !== 1) {
            pc_flash('error', 'That design is archived or no longer exists, so it cannot be issued.', 'Not issued');
            break;
        }

        $uq = $con->prepare("SELECT CONCAT_WS(' ', firstname, lastname) AS name FROM users WHERE user_id = ? AND status = 'active'");
        $uq->bind_param('i', $uid);
        $uq->execute();
        $person = $uq->get_result()->fetch_assoc();
        $uq->close();

        if (!$person) {
            pc_flash('error', 'That person is not an active member.', 'Not issued');
            break;
        }

        // generated_path stays empty: nothing is written to disk, so an empty
        // string here is the truth rather than a path to a file that is not
        // there. certificate-view renders from the row instead.
        $st = $con->prepare("
            INSERT INTO user_certificates (user_id, template_id, achievement, generated_path, awarded_by, awarded_at)
            VALUES (?, ?, ?, '', ?, NOW())
        ");
        $st->bind_param('iisi', $uid, $id, $ach, $me);
        $ok = $st->execute();
        $certId = (int)$con->insert_id;
        $st->close();

        if (!$ok) {
            pc_flash('error', 'The certificate could not be issued.', 'Not issued');
            break;
        }

        NotificationService::certificateAwarded($con, $uid, $ach, url('certificate-view') . '?id=' . $certId);

        logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'),
            'admin issued certificate "' . $t['name'] . '" to ' . $person['name']);

        pc_flash('success',
            $person['name'] . ' has been issued "' . $t['name'] . '" and notified. Reference '
            . aw_cert_ref($certId, date('Y-m-d')) . '.',
            'Certificate issued');
        break;
    }

    /* ── Take one back ── */
    case 'revoke': {
        $certId = (int)($_POST['cert_id'] ?? 0);

        $q = $con->prepare("
            SELECT uc.cert_id, t.name AS template_name,
                   CONCAT_WS(' ', u.firstname, u.lastname) AS person
              FROM user_certificates uc
              JOIN certificate_templates t ON t.template_id = uc.template_id
              JOIN users u ON u.user_id = uc.user_id
             WHERE uc.cert_id = ?
        ");
        $q->bind_param('i', $certId);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();

        if (!$row) {
            pc_flash('error', 'That certificate no longer exists.', 'Nothing changed');
            break;
        }

        $d = $con->prepare("DELETE FROM user_certificates WHERE cert_id = ?");
        $d->bind_param('i', $certId);
        $d->execute();
        $d->close();

        logMe($_SESSION['email'] ?? '', date('Y-m-d H:i:s'),
            'admin revoked certificate "' . $row['template_name'] . '" from ' . $row['person']);

        // No notification: telling someone their certificate has been taken
        // away is a message an admin should choose to send themselves.
        pc_flash('success',
            '"' . $row['template_name'] . '" has been taken back from ' . $row['person']
            . '. They have not been notified.',
            'Certificate revoked');
        break;
    }

    default:
        pc_flash('error', 'That action is not recognised.', 'Nothing happened');
}

header('Location: ' . $back);
exit;
