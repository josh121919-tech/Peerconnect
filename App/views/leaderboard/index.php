<?php
// Top Mentors leaderboard.
// Every figure here is counted from session_requests / feedback / user_badges,
// and the default order uses the composite score MentorScoreService already
// computes (and which, until now, nothing in the app ever displayed).
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/MentorScoreService.php';

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($role, ['mentee', 'mentor'], true)) {
    header("Location: " . url('welcomepage'));
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$is_mentor = $role === 'mentor';

// ── Keep the stored scores honest ────────────────────────────────────────
// mentor_scores is written by a service nothing schedules, so it goes stale.
// Refresh it when the newest row is over an hour old — measured by MySQL,
// which is the clock those timestamps were written on.
$stale = $con->query("
    SELECT COALESCE(MAX(TIMESTAMPDIFF(MINUTE, last_calculated, NOW())), 99999) AS mins
    FROM mentor_scores
")->fetch_assoc()['mins'] ?? 99999;
if ((int)$stale > 60) {
    try {
        MentorScoreService::refreshAll($con);
    } catch (Throwable $e) {
        // A failed refresh just means slightly older scores — not a broken page.
    }
}

// ── Tabs ─────────────────────────────────────────────────────────────────
$tabs = [
    'all'       => 'All Mentors',
    'expertise' => 'By Expertise',
    'sessions'  => 'Most Sessions',
    'rated'     => 'Highest Rated',
    'rising'    => 'Rising Mentors',
];
$tab = isset($_GET['tab'], $tabs[$_GET['tab']]) ? $_GET['tab'] : 'all';

// ── The board ────────────────────────────────────────────────────────────
// One query, every column counted from source. `expertise` is the subject the
// mentor has posted most availability for, falling back to their club.
$board_sql = "
    SELECT u.user_id,
           CONCAT(u.firstname, ' ', u.lastname) AS name,
           p.profile_image,
           COALESCE(NULLIF((
               SELECT a.subject FROM availability a
                WHERE a.mentor_id = u.user_id AND a.subject <> ''
                GROUP BY a.subject ORDER BY COUNT(*) DESC LIMIT 1
           ), ''), NULLIF(p.club, ''), '') AS expertise,
           (SELECT COUNT(*) FROM session_requests s
             WHERE s.mentor_id = u.user_id AND s.status = 'completed') AS sessions_done,
           (SELECT COUNT(DISTINCT s.mentee_id) FROM session_requests s
             WHERE s.mentor_id = u.user_id AND s.status IN ('approved','completed')) AS mentees,
           (SELECT COUNT(*) FROM session_requests s
             WHERE s.mentor_id = u.user_id AND s.status = 'completed'
               AND s.session_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS recent_sessions,
           (SELECT COUNT(*) FROM user_badges b WHERE b.user_id = u.user_id) AS badges,
           COALESCE((SELECT ROUND(AVG(f.rating), 1) FROM feedback f WHERE f.mentor_id = u.user_id), 0) AS rating,
           (SELECT COUNT(*) FROM feedback f WHERE f.mentor_id = u.user_id) AS reviews,
           COALESCE(ms.recommendation_score, 0) AS score
    FROM users u
    LEFT JOIN profile p       ON p.user_id   = u.user_id
    LEFT JOIN mentor_scores ms ON ms.mentor_id = u.user_id
    WHERE u.role = 'mentor' AND u.status = 'active'
      -- Settings → Data Privacy → 'Show my activity publicly'. Opting out
      -- takes a mentor off this board entirely.
      AND COALESCE((SELECT ps.share_activity FROM privacy_settings ps WHERE ps.user_id = u.user_id), 1) = 1
";

switch ($tab) {
    case 'sessions':
        $order = "sessions_done DESC, mentees DESC, rating DESC";
        $blurb = 'Ranked by sessions actually completed.';
        break;
    case 'rated':
        // Reviews first: one 5-star review shouldn't outrank a steady 4.8.
        $order = "(reviews > 0) DESC, rating DESC, reviews DESC";
        $blurb = 'Ranked by average rating, mentors with reviews first.';
        break;
    case 'rising':
        $order = "recent_sessions DESC, sessions_done DESC";
        $blurb = 'Most sessions completed in the last 30 days.';
        break;
    case 'expertise':
        // LOWER() so "Math" and "math" — both real values in availability —
        // land in one group instead of two.
        $order = "expertise = '', LOWER(expertise) ASC, sessions_done DESC";
        $blurb = 'Grouped by the subject each mentor teaches most.';
        break;
    default:
        $order = "score DESC, sessions_done DESC";
        $blurb = 'Ranked by PeerConnect\'s mentor score — rating, completion rate, experience and reliability combined.';
        break;
}

$rows = $con->query($board_sql . " ORDER BY $order")->fetch_all(MYSQLI_ASSOC);

// A podium only means something for mentors who have actually mentored.
$ranked = array_values(array_filter($rows, fn($r) => (int)$r['sessions_done'] > 0));
$podium = array_slice($ranked, 0, 3);

// Where this person sits, if they're a mentor on the board.
$my_rank = null;
if ($is_mentor) {
    foreach ($rows as $i => $r) {
        if ((int)$r['user_id'] === $user_id) {
            $my_rank = ['pos' => $i + 1, 'of' => count($rows), 'row' => $r];
            break;
        }
    }
}

// A mentee has no place on a mentor board — show their own activity instead.
$my_activity = null;
if (!$is_mentor) {
    $my_activity = $con->query("
        SELECT (SELECT COUNT(*) FROM session_requests WHERE mentee_id = $user_id AND status = 'completed') AS done,
               (SELECT COUNT(DISTINCT mentor_id) FROM session_requests WHERE mentee_id = $user_id AND status IN ('approved','completed')) AS mentors,
               (SELECT COUNT(*) FROM feedback WHERE mentee_id = $user_id) AS reviews
    ")->fetch_assoc();
}

// ── Top expertise areas ──────────────────────────────────────────────────
// Grouped case-insensitively — "Math" and "math" are one subject, not two.
$areas = $con->query("
    SELECT MIN(subject) AS subject, COUNT(DISTINCT mentor_id) AS mentors
    FROM availability
    WHERE subject <> ''
    GROUP BY LOWER(subject)
    ORDER BY mentors DESC, subject ASC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ── Leaderboard updates — real movements, not filler ─────────────────────
$new_mentors = (int)($con->query("
    SELECT COUNT(*) c FROM users
    WHERE role = 'mentor' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
")->fetch_assoc()['c'] ?? 0);

$sessions_30d = (int)($con->query("
    SELECT COUNT(*) c FROM session_requests
    WHERE status = 'completed' AND session_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
")->fetch_assoc()['c'] ?? 0);

$last_calc = $con->query("
    SELECT MAX(last_calculated) t, TIMESTAMPDIFF(MINUTE, MAX(last_calculated), NOW()) mins
    FROM mentor_scores
")->fetch_assoc();

$view_mentor_url = url('mentee-view-mentor');
$active_page     = 'leaderboard';

/** Medal colours for the podium and the first three table rows. */
function lb_medal(int $pos): array
{
    return [
        1 => ['#E9A21B', '#FDF6E3'],
        2 => ['#8AA0B8', '#EEF2F7'],
        3 => ['#B87333', '#FBF0E7'],
    ][$pos] ?? ['var(--gray-400)', 'var(--gray-100)'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leaderboard — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        .lb-eyebrow {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--gray-400);
            margin-bottom: 6px;
        }

        .lb-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 320px;
            gap: 18px;
            align-items: start;
        }

        /* ── Quote banner ── */
        .lb-quote {
            background: linear-gradient(135deg, var(--mint-faint) 0%, #F4FAFE 100%);
            border: 1px solid var(--mint-soft);
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 0 1 520px;
            min-width: 280px;
        }

        .lb-quote-mark {
            font-size: 34px;
            line-height: 1;
            color: var(--mint);
            font-weight: 700;
            flex-shrink: 0;
        }

        /* ── Tabs ── */
        .lb-tabs {
            display: inline-flex;
            background: var(--gray-100);
            border-radius: var(--radius);
            padding: 4px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 2px;
        }

        .lb-tabs a {
            padding: 8px 18px;
            border-radius: var(--radius-sm);
            font-size: 12.5px;
            font-weight: 600;
            color: var(--gray-500);
            text-decoration: none;
            white-space: nowrap;
            transition: background .14s, color .14s;
        }

        .lb-tabs a.active {
            background: var(--mint-faint);
            color: var(--mint);
            box-shadow: inset 0 0 0 1px var(--mint-soft);
        }

        .lb-tabs a:not(.active):hover {
            color: var(--forest);
        }

        /* ── Podium ── */
        .lb-podium {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            align-items: end;
            margin-bottom: 20px;
        }

        .lb-podium.one {
            grid-template-columns: minmax(0, 320px);
            justify-content: center;
        }

        .lb-podium.two {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .lb-pod {
            position: relative;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 34px 18px 18px;
            text-align: center;
        }

        .lb-pod.first {
            border-color: #F0D08A;
            background: linear-gradient(180deg, #FFFDF6 0%, var(--surface) 60%);
            box-shadow: var(--shadow-md);
        }

        .lb-rank-badge {
            position: absolute;
            top: -15px;
            left: 50%;
            transform: translateX(-50%);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 800;
            color: #fff;
            border: 3px solid var(--surface);
        }

        .lb-pod-avatar {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 10px;
            background: var(--mint-faint);
            color: var(--forest);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 700;
        }

        .lb-pod.first .lb-pod-avatar {
            width: 80px;
            height: 80px;
            font-size: 28px;
        }

        .lb-pod-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--forest);
        }

        .lb-pod.first .lb-pod-name {
            font-size: 17px;
        }

        .lb-pod-role {
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 1px;
        }

        .lb-pod-rating {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--forest);
            margin-top: 7px;
        }

        .lb-pod-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 6px;
            margin-top: 14px;
            padding-top: 13px;
            border-top: 1px solid var(--border);
        }

        .lb-pod-stat b {
            display: block;
            font-size: 15px;
            color: var(--forest);
        }

        .lb-pod-stat span {
            font-size: 10.5px;
            color: var(--gray-500);
        }

        /* ── Table ── */
        .lb-rank-cell {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
        }

        .lb-mentor-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .lb-avatar-sm {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
            background: var(--mint-faint);
            color: var(--forest);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
        }

        .lb-group-row td {
            background: var(--gray-50);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--gray-500);
        }

        /* ── Side rail ── */
        .lb-me {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--mint-faint);
            border: 1px solid var(--mint-soft);
            border-radius: var(--radius);
            padding: 13px 15px;
        }

        .lb-rank-hero {
            display: flex;
            align-items: center;
            gap: 13px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 13px 15px;
            margin-top: 10px;
        }

        .lb-area-row {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 9px 0;
            border-bottom: 1px solid var(--border);
        }

        .lb-area-row:last-child {
            border-bottom: none;
        }

        .lb-area-n {
            width: 24px;
            height: 24px;
            border-radius: 7px;
            background: var(--mint-faint);
            color: var(--mint);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            flex-shrink: 0;
        }

        @media (max-width: 1180px) {
            .lb-layout {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 860px) {
            .lb-podium,
            .lb-podium.two {
                grid-template-columns: minmax(0, 1fr);
            }

            .lb-podium.one {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 700px) {
            .lb-tabs {
                width: 100%;
            }

            .lb-tabs a {
                flex: 1 1 auto;
                text-align: center;
                padding: 8px 10px;
                font-size: 11.5px;
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/../includes/app_shell.php'; ?>

        <main class="main fade-in">

            <div class="page-hd" style="display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap;">
                <div style="flex:1 1 380px;min-width:260px;">
                    <div class="lb-eyebrow">Leaderboard</div>
                    <h1>Top Mentors</h1>
                    <p>Meet our most active and impactful mentors. Based on sessions, ratings, and community contribution.</p>
                </div>
                <div class="lb-quote">
                    <span class="lb-quote-mark">&ldquo;</span>
                    <div>
                        <div style="font-size:15px;font-weight:700;color:var(--forest);line-height:1.4;">
                            Great mentors create greater futures.
                        </div>
                        <div style="font-size:12px;color:var(--gray-500);margin-top:4px;">— PeerConnect</div>
                    </div>
                </div>
            </div>

            <div class="lb-tabs">
                <?php foreach ($tabs as $key => $label): ?>
                    <a href="?tab=<?= $key ?>" class="<?= $key === $tab ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
                <?php endforeach; ?>
            </div>

            <div class="lb-layout">
                <div>
                    <?php if (!$ranked): ?>
                        <div class="pcard" style="margin-bottom:18px;">
                            <div class="pcard-body" style="text-align:center;padding:40px 20px;">
                                <div style="font-size:15px;font-weight:700;color:var(--forest);margin-bottom:6px;">No ranked mentors yet</div>
                                <p style="font-size:13px;color:var(--gray-500);max-width:430px;margin:0 auto;">
                                    Mentors join the board once they've completed their first session. The full list of
                                    <?= count($rows) ?> mentor<?= count($rows) === 1 ? '' : 's' ?> is below.
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php
                        // 1st in the middle when there are three, the way a podium reads.
                        $order_map = count($podium) === 3 ? [1, 0, 2] : array_keys($podium);
                        $podium_class = count($podium) === 1 ? 'one' : (count($podium) === 2 ? 'two' : '');
                        ?>
                        <div class="lb-podium <?= $podium_class ?>">
                            <?php foreach ($order_map as $idx):
                                $m   = $podium[$idx];
                                $pos = $idx + 1;
                                [$c, $bg] = lb_medal($pos);
                                $img = trim((string)$m['profile_image']);
                            ?>
                                <div class="lb-pod<?= $pos === 1 ? ' first' : '' ?>">
                                    <span class="lb-rank-badge" style="background:<?= $c ?>;"><?= $pos ?></span>
                                    <?php if ($img !== ''): ?>
                                        <img class="lb-pod-avatar" src="<?= htmlspecialchars($img) ?>" alt="">
                                    <?php else: ?>
                                        <div class="lb-pod-avatar"><?= htmlspecialchars(strtoupper(substr($m['name'], 0, 1))) ?></div>
                                    <?php endif; ?>
                                    <div class="lb-pod-name"><?= htmlspecialchars($m['name']) ?></div>
                                    <div class="lb-pod-role"><?= htmlspecialchars($m['expertise'] ?: 'No subject posted yet') ?></div>
                                    <div class="lb-pod-rating">
                                        <?php if ((int)$m['reviews'] > 0): ?>
                                            <span style="color:var(--gold);">★</span> <?= number_format((float)$m['rating'], 1) ?>
                                            <span style="color:var(--gray-500);font-weight:500;">(<?= (int)$m['reviews'] ?> review<?= (int)$m['reviews'] === 1 ? '' : 's' ?>)</span>
                                        <?php else: ?>
                                            <span style="color:var(--gray-400);font-weight:500;">No reviews yet</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="lb-pod-stats">
                                        <div class="lb-pod-stat"><b><?= (int)$m['sessions_done'] ?></b><span>Sessions</span></div>
                                        <div class="lb-pod-stat"><b><?= (int)$m['mentees'] ?></b><span>Mentees</span></div>
                                        <div class="lb-pod-stat"><b><?= (int)$m['badges'] ?></b><span>Badges</span></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="pcard" style="padding:0;overflow:hidden;">
                        <div class="pcard-hd" style="padding:16px 20px;">
                            <span class="pcard-title"><?= htmlspecialchars($tabs[$tab]) ?></span>
                            <span style="font-size:11.5px;color:var(--gray-500);"><?= htmlspecialchars($blurb) ?></span>
                        </div>
                        <div style="overflow-x:auto;">
                            <table class="tbl">
                                <thead>
                                    <tr>
                                        <th style="width:56px;">#</th>
                                        <th>Mentor</th>
                                        <th>Expertise</th>
                                        <th>Sessions</th>
                                        <th>Mentees</th>
                                        <th><?= $tab === 'rising' ? 'Last 30d' : 'Rating' ?></th>
                                        <th>Badges</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $pos = 0;
                                    $group = null;
                                    foreach ($rows as $m):
                                        $pos++;
                                        $img = trim((string)$m['profile_image']);

                                        if ($tab === 'expertise') {
                                            // Compare case-insensitively, but print the casing of the
                                            // busiest mentor in the group (rows arrive sorted that way).
                                            $g   = $m['expertise'] !== '' ? $m['expertise'] : 'No subject posted yet';
                                            $key = mb_strtolower($g);
                                            if ($key !== $group) {
                                                $group = $key;
                                                echo '<tr class="lb-group-row"><td colspan="8">' . htmlspecialchars($g) . '</td></tr>';
                                            }
                                        }

                                        [$rc, $rbg] = $pos <= 3 && $tab !== 'expertise' ? lb_medal($pos) : ['var(--gray-600)', 'var(--gray-100)'];
                                    ?>
                                        <tr<?= (int)$m['user_id'] === $user_id ? ' style="background:var(--mint-faint);"' : '' ?>>
                                            <td><span class="lb-rank-cell" style="background:<?= $rbg ?>;color:<?= $rc ?>;"><?= $pos ?></span></td>
                                            <td>
                                                <span class="lb-mentor-cell">
                                                    <?php if ($img !== ''): ?>
                                                        <img class="lb-avatar-sm" src="<?= htmlspecialchars($img) ?>" alt="">
                                                    <?php else: ?>
                                                        <span class="lb-avatar-sm"><?= htmlspecialchars(strtoupper(substr($m['name'], 0, 1))) ?></span>
                                                    <?php endif; ?>
                                                    <span style="font-weight:600;color:var(--forest);"><?= htmlspecialchars($m['name']) ?></span>
                                                    <?php if ((int)$m['user_id'] === $user_id): ?>
                                                        <span class="chip" style="color:var(--mint);">You</span>
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($m['expertise'] ?: '—') ?></td>
                                            <td><?= (int)$m['sessions_done'] ?></td>
                                            <td><?= (int)$m['mentees'] ?></td>
                                            <td style="white-space:nowrap;">
                                                <?php if ($tab === 'rising'): ?>
                                                    <?= (int)$m['recent_sessions'] ?>
                                                <?php elseif ((int)$m['reviews'] > 0): ?>
                                                    <span style="color:var(--gold);">★</span> <?= number_format((float)$m['rating'], 1) ?>
                                                <?php else: ?>
                                                    <span style="color:var(--gray-400);">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= (int)$m['badges'] ?></td>
                                            <td style="text-align:right;">
                                                <?php // Your own row goes to your editable profile, not the public one. ?>
                                                <?php if ((int)$m['user_id'] === $user_id): ?>
                                                    <a href="<?= htmlspecialchars(url('mentor-profile')) ?>" class="btn btn-ghost btn-sm">My Profile</a>
                                                <?php else: ?>
                                                    <a href="<?= htmlspecialchars($view_mentor_url) ?>?id=<?= (int)$m['user_id'] ?>" class="btn btn-ghost btn-sm">View Profile</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ═══ Side rail ═══ -->
                <div style="display:flex;flex-direction:column;gap:16px;">

                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title"><?= $is_mentor ? 'Your Ranking' : 'Your Activity' ?></span>
                        </div>
                        <div class="pcard-body">
                            <?php
                            $meName  = $display_name ?? 'You';
                            $meImg   = $avatar_image ?? null;
                            ?>
                            <div class="lb-me">
                                <?php if ($meImg): ?>
                                    <img src="<?= htmlspecialchars($meImg) ?>" alt="" style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                                <?php else: ?>
                                    <div class="lb-avatar-sm" style="width:44px;height:44px;font-size:17px;"><?= htmlspecialchars(strtoupper(substr($meName, 0, 1))) ?></div>
                                <?php endif; ?>
                                <div style="min-width:0;">
                                    <div style="font-size:13.5px;font-weight:700;color:var(--forest);"><?= htmlspecialchars($meName) ?></div>
                                    <div style="font-size:11.5px;color:var(--gray-500);"><?= $is_mentor ? 'Mentor' : 'Mentee' ?></div>
                                </div>
                            </div>

                            <?php if ($is_mentor && $my_rank): ?>
                                <div class="lb-rank-hero">
                                    <div style="width:40px;height:40px;border-radius:11px;background:var(--gold-light);color:var(--gold);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 4h10v5a5 5 0 0 1-10 0V4Z" /><path stroke-linecap="round" d="M7 6H4.5v1.5A3.5 3.5 0 0 0 8 11M17 6h2.5v1.5A3.5 3.5 0 0 1 16 11M12 14v4M8.5 21h7" /></svg>
                                    </div>
                                    <div>
                                        <div style="font-size:20px;font-weight:800;color:var(--forest);line-height:1.1;">#<?= $my_rank['pos'] ?></div>
                                        <div style="font-size:11.5px;color:var(--gray-500);">of <?= $my_rank['of'] ?> mentors · <?= htmlspecialchars($tabs[$tab]) ?></div>
                                    </div>
                                </div>
                                <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px;text-align:center;">
                                    <div><b style="display:block;font-size:15px;color:var(--forest);"><?= (int)$my_rank['row']['sessions_done'] ?></b><span style="font-size:10.5px;color:var(--gray-500);">Sessions</span></div>
                                    <div><b style="display:block;font-size:15px;color:var(--forest);"><?= (int)$my_rank['row']['mentees'] ?></b><span style="font-size:10.5px;color:var(--gray-500);">Mentees</span></div>
                                    <div><b style="display:block;font-size:15px;color:var(--forest);"><?= (int)$my_rank['row']['badges'] ?></b><span style="font-size:10.5px;color:var(--gray-500);">Badges</span></div>
                                </div>
                                <a href="<?= htmlspecialchars(url('mentor-profile')) ?>" class="pcard-link" style="display:inline-block;margin-top:12px;">View my profile →</a>

                            <?php elseif (!$is_mentor && $my_activity): ?>
                                <!-- A mentee doesn't hold a place on a mentor board, so this shows
                                     what they've actually done rather than inventing a rank. -->
                                <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px;text-align:center;">
                                    <div><b style="display:block;font-size:16px;color:var(--forest);"><?= (int)$my_activity['done'] ?></b><span style="font-size:10.5px;color:var(--gray-500);">Sessions</span></div>
                                    <div><b style="display:block;font-size:16px;color:var(--forest);"><?= (int)$my_activity['mentors'] ?></b><span style="font-size:10.5px;color:var(--gray-500);">Mentors</span></div>
                                    <div><b style="display:block;font-size:16px;color:var(--forest);"><?= (int)$my_activity['reviews'] ?></b><span style="font-size:10.5px;color:var(--gray-500);">Reviews</span></div>
                                </div>
                                <p style="font-size:12px;color:var(--gray-500);line-height:1.55;margin:12px 0 10px;">
                                    Keep learning and connecting — your reviews are part of what ranks these mentors.
                                </p>
                                <a href="<?= htmlspecialchars(url('mentee-find')) ?>" class="btn btn-primary btn-sm" style="width:100%;justify-content:center;">Find a mentor</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Top Expertise Areas</span>
                        </div>
                        <div class="pcard-body">
                            <?php if (!$areas): ?>
                                <div class="prow-empty">No subjects posted yet.</div>
                            <?php else: ?>
                                <?php foreach ($areas as $i => $a): ?>
                                    <div class="lb-area-row">
                                        <span class="lb-area-n"><?= $i + 1 ?></span>
                                        <span style="flex:1;min-width:0;font-size:12.5px;font-weight:600;color:var(--forest);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                            <?= htmlspecialchars($a['subject']) ?>
                                        </span>
                                        <span style="font-size:11.5px;color:var(--gray-500);flex-shrink:0;">
                                            <?= (int)$a['mentors'] ?> mentor<?= (int)$a['mentors'] === 1 ? '' : 's' ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pcard">
                        <div class="pcard-hd">
                            <span class="pcard-title">Leaderboard Updates</span>
                        </div>
                        <div class="pcard-body" style="font-size:12.5px;color:var(--gray-600);line-height:1.6;">
                            <div style="display:flex;gap:10px;margin-bottom:10px;">
                                <span style="color:var(--mint);flex-shrink:0;">●</span>
                                <span><b style="color:var(--forest);"><?= $new_mentors ?></b> mentor<?= $new_mentors === 1 ? '' : 's' ?> joined in the last 30 days.</span>
                            </div>
                            <div style="display:flex;gap:10px;margin-bottom:10px;">
                                <span style="color:var(--success);flex-shrink:0;">●</span>
                                <span><b style="color:var(--forest);"><?= $sessions_30d ?></b> session<?= $sessions_30d === 1 ? '' : 's' ?> completed across the platform in the last 30 days.</span>
                            </div>
                            <div style="display:flex;gap:10px;">
                                <span style="color:var(--gray-300);flex-shrink:0;">●</span>
                                <span>
                                    Scores recalculated
                                    <?php
                                    $mins = $last_calc['mins'] ?? null;
                                    if ($mins === null) {
                                        echo 'never yet';
                                    } elseif ((int)$mins < 1) {
                                        echo 'just now';
                                    } elseif ((int)$mins < 60) {
                                        echo (int)$mins . ' minutes ago';
                                    } else {
                                        echo floor((int)$mins / 60) . ' hours ago';
                                    }
                                    ?>.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        }

        function toggleProfileMenu() {
            document.getElementById('profileMenu').classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const m = document.getElementById('profileMenu');
            if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]'))
                m.classList.remove('open');
        });
    </script>
</body>

</html>
