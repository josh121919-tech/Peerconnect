<?php

/**
 * matching.php — Mentor Matching.
 *
 * Matches on the three answer sets the mentee already gave on their Profile:
 * Areas I Want to Learn, Skills, and Programme/Interests (user_tags). There is
 * no preferences form here any more — it asked for the same information a
 * second time in different words, and the free-text "Topic" it collected was a
 * hard filter that emptied the list whenever the wording did not happen to
 * match a mentor's expertise text ("math" vs "Mathematics").
 *
 * The headline number is the share of the mentee's own answers a mentor
 * covers, weighted learn 3 / skill 2 / interest 1 (MentorScoreService::
 * TAG_WEIGHTS). It is not blended with rating or session count: those are real
 * facts about a mentor but they say nothing about whether that mentor can help
 * with *your* subjects, and folding them in produced the old page's "31%
 * Possible Match" for mentors with no subject overlap at all.
 *
 * A mentor sharing nothing is never dressed up as a match. Those are listed
 * separately, under their own heading, so the ranking stays truthful.
 *
 * ?fragment=1 returns just the results block, which is what the Refresh
 * button fetches. Without JavaScript that same button is an ordinary link
 * that reloads the page.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . "/../db.php";
require_once __DIR__ . '/../../services/MentorScoreService.php';
// NOTE: design_system.php is deliberately NOT required here. app_shell.php
// pulls it in, which is how sessions.php and find_mentor.php get it too. This
// page used to require it at the top, which emitted the whole ~100KB
// stylesheet before <!DOCTYPE> — and would have put a second copy of it inside
// every ?fragment=1 response the Refresh button fetches.

// PHP runs on the php.ini default here (Europe/Berlin), six hours behind the
// campus. Without this the "Updated" stamp on the results reads 6:02 am when
// it is midday locally. Same line as menteepage/index.php and sessions.php.
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mentee') {
    header("Location: " . url('welcomepage'));
    exit;
}

$mentee_id   = (int)$_SESSION['user_id'];
$active_page = 'matching';

// ── What we match on ──────────────────────────────────────────────────
$tags = ['learn' => [], 'skill' => [], 'interest' => []];
$tq = $con->prepare("SELECT tag_type, tag FROM user_tags WHERE user_id = ? ORDER BY tag_type, tag_id");
$tq->bind_param("i", $mentee_id);
$tq->execute();
$tr = $tq->get_result();
while ($row = $tr->fetch_assoc()) {
    $tags[$row['tag_type']][] = $row['tag'];
}
$tq->close();

$mentee_weight = MentorScoreService::menteeTagWeight($con, $mentee_id);
$has_answers   = $mentee_weight > 0;

// ── Rank ──────────────────────────────────────────────────────────────
// No $prefs: ranking is the questionnaire overlap, with the mentor's
// performance score breaking ties. getRankedMentors() attaches tag_percent
// and shared_tags whenever the mentee has answered anything.
$mentors = $has_answers
    ? MentorScoreService::getRankedMentors($con, [], 24, $mentee_id)
    : [];

$matches = [];   // share at least one answer
$others  = [];   // verified, but nothing in common yet
foreach ($mentors as $m) {
    if ((int)($m['tag_percent'] ?? 0) > 0) {
        $matches[] = $m;
    } else {
        $others[] = $m;
    }
}

/** Plain label for a coverage percentage. Never flattering about a zero. */
function pc_match_label(int $pct): array
{
    if ($pct >= 100) return ['Covers everything you asked for', 'excellent'];
    if ($pct >= 67)  return ['Strong match', 'strong'];
    if ($pct >= 34)  return ['Good match', 'good'];
    return ['Partial match', 'partial'];
}

/** The three answer groups, strongest signal first. */
$groups = [
    'learn'    => ['Areas I want to learn', 'These carry the most weight'],
    'skill'    => ['Skills',                'Weighted next'],
    'interest' => ['Programme',             'Weighted least'],
];

/**
 * The results block. Rendered inline below, and on its own for ?fragment=1
 * so the Refresh button can swap it without a full page load.
 */
function pc_render_matches(array $matches, array $others, bool $has_answers, string $profile_url, string $find_url): void
{
    $stamp = date('g:i a');
?>
    <div class="mx-results-hd">
        <p class="mx-count">
            <?php if (!$has_answers): ?>
                Nothing to match on yet
            <?php elseif ($matches): ?>
                <strong><?= count($matches) ?></strong> mentor<?= count($matches) !== 1 ? 's' : '' ?>
                share<?= count($matches) === 1 ? 's' : '' ?> your answers
            <?php else: ?>
                No mentor shares your answers yet
            <?php endif; ?>
        </p>
        <span class="mx-stamp">Updated <?= $stamp ?></span>
    </div>

    <?php if (!$has_answers): ?>
        <div class="card empty-state-lg">
            <span class="es-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4 3 8.5 12 13l9-4.5L12 4Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 10.8V16c0 1.4 2.5 2.5 5.5 2.5s5.5-1.1 5.5-2.5v-5.2" />
                </svg>
            </span>
            <h3 class="es-title">Tell us what you want to learn</h3>
            <p class="es-body">
                Matching runs on three things from your profile: the areas you want to learn,
                the skills you want to build, and your programme. Add any of them and matches
                appear here straight away.
            </p>
            <a class="btn btn-primary" href="<?= htmlspecialchars($profile_url) ?>">Add my answers</a>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <?php if ($matches): ?>
        <div class="mx-grid">
            <?php foreach ($matches as $m) pc_render_card($m, true); ?>
        </div>
    <?php else: ?>
        <div class="card mx-none">
            <span class="mx-none-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                    <circle cx="11" cy="11" r="7" />
                    <path stroke-linecap="round" d="m20 20-4-4" />
                </svg>
            </span>
            <div>
                <h3>No mentor has listed your subjects yet</h3>
                <p>
                    Matching compares your answers with what each mentor put on their own
                    profile, word for word — so this is not a ranking problem, there is simply
                    no overlap on record right now. Two things help: widen your answers, or
                    browse every mentor and pick by rating and availability instead.
                </p>
                <div class="mx-none-actions">
                    <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($profile_url) ?>">Edit my answers</a>
                    <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars($find_url) ?>">Browse all mentors</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($others): ?>
        <div class="mx-other-hd">
            <h2>Other verified mentors</h2>
            <p>Nothing in common with your answers yet — listed by rating and experience.</p>
        </div>
        <div class="mx-grid">
            <?php foreach ($others as $m) pc_render_card($m, false); ?>
        </div>
    <?php endif; ?>
<?php
}

/** One mentor card. $is_match distinguishes a real overlap from a filler row. */
function pc_render_card(array $m, bool $is_match): void
{
    $pct       = (int)($m['tag_percent'] ?? 0);
    [$label, $tone] = pc_match_label($pct);
    $name      = trim($m['firstname'] . ' ' . $m['lastname']);
    $expertise = trim((string)($m['expertise'] ?? '')) ?: 'Mentor';
    $rating    = (float)$m['avg_rating'];
    $sessions  = (int)$m['total_sessions'];
    $reviews   = (int)$m['review_count'];
    $slots     = (int)($m['available_slots'] ?? 0);
    $img       = !empty($m['profile_image']) ? asset($m['profile_image']) : null;
    $initial   = strtoupper(substr($m['firstname'], 0, 1));
    $view      = url('mentee-view-mentor') . '?id=' . (int)$m['user_id'];
    $shared    = $m['shared_tags'] ?? [];
?>
    <a class="card card-interactive mx-card" href="<?= htmlspecialchars($view) ?>">
        <div class="mx-card-top">
            <?php if ($img): ?>
                <span class="pc-avatar pc-avatar-lg"><img src="<?= htmlspecialchars($img) ?>" alt=""></span>
            <?php else: ?>
                <span class="pc-avatar pc-avatar-lg"><?= htmlspecialchars($initial) ?></span>
            <?php endif; ?>
            <div class="mx-card-id">
                <span class="mx-name"><?= htmlspecialchars($name) ?></span>
                <span class="mx-expertise"><?= htmlspecialchars($expertise) ?></span>
            </div>
        </div>

        <?php if ($is_match): ?>
            <div class="mx-score">
                <div class="mx-score-hd">
                    <span class="mx-badge <?= $tone ?>"><?= htmlspecialchars($label) ?></span>
                    <span class="mx-pct"><?= $pct ?>%</span>
                </div>
                <div class="pc-progress">
                    <div class="pc-progress-fill" style="width:<?= $pct ?>%"></div>
                </div>
                <p class="mx-score-note">of what you asked for</p>
            </div>

            <div class="mx-shared">
                <?php
                $legend = [
                    'learn'    => 'Can mentor you in',
                    'skill'    => 'Can help with',
                    'interest' => 'Same programme',
                ];
                foreach (['learn', 'skill', 'interest'] as $type):
                    if (empty($shared[$type])) continue; ?>
                    <div class="mx-shared-row">
                        <span class="mx-shared-key"><?= $legend[$type] ?></span>
                        <span class="mx-shared-tags">
                            <?php foreach ($shared[$type] as $t): ?>
                                <span class="tag-pill"><?= htmlspecialchars($t) ?></span>
                            <?php endforeach; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="mx-nomatch">No overlap with your answers yet</p>
        <?php endif; ?>

        <div class="mx-stats">
            <span><strong><?= $rating > 0 ? number_format($rating, 1) : '—' ?></strong>Rating</span>
            <span><strong><?= $sessions ?></strong>Sessions</span>
            <span><strong><?= $reviews ?></strong>Reviews</span>
        </div>

        <?php if ($slots > 0): ?>
            <p class="mx-slots"><?= $slots ?> upcoming slot<?= $slots !== 1 ? 's' : '' ?></p>
        <?php endif; ?>
    </a>
<?php
}

$profile_url = url('mentee-profile');
$find_url    = url('mentee-find');

// ── Refresh endpoint ──────────────────────────────────────────────────
if (isset($_GET['fragment'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    pc_render_matches($matches, $others, $has_answers, $profile_url, $find_url);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Matching — PeerConnect</title>
    <link rel="stylesheet" href="<?= asset('css/design-system.css') ?>">
    <style>
        /* ── What we match on ── */
        .mx-answers {
            padding: 22px 24px;
            margin-bottom: 26px;
        }

        .mx-answers-hd {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .mx-answers-hd h2 {
            margin: 0 0 4px;
            font-size: 15px;
            font-weight: 700;
            color: var(--forest);
        }

        .mx-answers-hd p {
            margin: 0;
            font-size: 13px;
            color: var(--gray-500);
            max-width: 62ch;
            line-height: 1.55;
        }

        .mx-groups {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 16px;
        }

        .mx-group-key {
            display: flex;
            align-items: baseline;
            gap: 8px;
            margin-bottom: 8px;
        }

        .mx-group-key strong {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--forest);
        }

        .mx-group-key span {
            font-size: 11px;
            color: var(--gray-400);
        }

        .mx-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .mx-chips .tag-pill {
            white-space: normal;
        }

        .mx-empty-group {
            font-size: 12.5px;
            color: var(--gray-400);
        }

        .mx-answers-ft {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }

        .mx-answers-ft .mx-hint {
            font-size: 12.5px;
            color: var(--gray-400);
        }

        /* ── Results ── */
        .mx-results-hd {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .mx-count {
            margin: 0;
            font-size: 13px;
            color: var(--gray-600);
        }

        .mx-count strong {
            color: var(--forest);
        }

        .mx-stamp {
            font-size: 12px;
            color: var(--gray-400);
        }

        .mx-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }

        .mx-card {
            display: flex;
            flex-direction: column;
            gap: 14px;
            padding: 18px;
            text-decoration: none;
            color: inherit;
        }

        .mx-card-top {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .mx-card-id {
            min-width: 0;
        }

        .mx-name {
            display: block;
            font-size: 14.5px;
            font-weight: 700;
            color: var(--gray-900);
            line-height: 1.3;
        }

        .mx-expertise {
            display: block;
            font-size: 12.5px;
            color: var(--gray-500);
            margin-top: 2px;
        }

        .mx-score-hd {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 7px;
        }

        .mx-badge {
            font-size: 11.5px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 999px;
            background: var(--mint-faint);
            color: var(--forest);
            border: 1px solid var(--mint-soft);
        }

        .mx-badge.excellent {
            background: var(--success-bg);
            color: var(--success);
            border-color: rgba(31, 122, 92, .3);
        }

        .mx-badge.partial {
            background: var(--gray-100);
            color: var(--gray-600);
            border-color: var(--border);
        }

        .mx-pct {
            font-size: 15px;
            font-weight: 700;
            color: var(--forest);
        }

        .mx-score-note {
            margin: 6px 0 0;
            font-size: 11.5px;
            color: var(--gray-400);
        }

        .mx-shared {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }

        .mx-shared-key {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--gray-400);
            margin-bottom: 5px;
        }

        .mx-shared-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .mx-shared-tags .tag-pill {
            white-space: normal;
            font-size: 11.5px;
        }

        .mx-nomatch {
            margin: 0;
            font-size: 12.5px;
            color: var(--gray-400);
            font-style: italic;
        }

        .mx-stats {
            display: flex;
            gap: 8px;
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        .mx-stats span {
            flex: 1;
            text-align: center;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--gray-400);
        }

        .mx-stats strong {
            display: block;
            font-size: 15px;
            font-weight: 700;
            color: var(--gray-900);
            letter-spacing: 0;
            text-transform: none;
            margin-bottom: 1px;
        }

        .mx-slots {
            margin: 0;
            font-size: 11.5px;
            color: var(--success);
            font-weight: 600;
        }

        /* ── No overlap ── */
        .mx-none {
            display: flex;
            gap: 16px;
            padding: 22px 24px;
        }

        .mx-none-ico {
            flex: 0 0 40px;
            width: 40px;
            height: 40px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: var(--mint-faint);
            color: var(--forest);
        }

        .mx-none-ico svg {
            width: 20px;
            height: 20px;
        }

        .mx-none h3 {
            margin: 0 0 6px;
            font-size: 14.5px;
            font-weight: 700;
            color: var(--forest);
        }

        .mx-none p {
            margin: 0;
            font-size: 13px;
            line-height: 1.6;
            color: var(--gray-600);
            max-width: 70ch;
        }

        .mx-none-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .mx-other-hd {
            margin: 30px 0 14px;
        }

        .mx-other-hd h2 {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: var(--gray-700);
        }

        .mx-other-hd p {
            margin: 3px 0 0;
            font-size: 12.5px;
            color: var(--gray-400);
        }

        /* Dimmer, so a filler row never reads as a result. */
        .mx-grid .mx-card:has(.mx-nomatch) {
            background: var(--gray-50);
        }

        #mxResults.is-busy {
            opacity: .5;
            pointer-events: none;
            transition: opacity .12s;
        }

        @media (max-width: 560px) {
            .mx-answers {
                padding: 18px 16px;
            }

            .mx-none {
                flex-direction: column;
                padding: 18px 16px;
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/../includes/app_shell.php'; ?>

        <main class="main">
            <div class="page-hd" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <div>
                    <h1>Your Mentor Matches</h1>
                    <p>Ranked by how much of what you asked for each mentor covers &mdash; not by who is most popular.</p>
                </div>
                <a class="btn btn-outline" id="mxRefresh" href="<?= htmlspecialchars(url('mentee-matching')) ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 11a8 8 0 1 0-2.3 5.7M20 5v6h-6" />
                    </svg>
                    Refresh matches
                </a>
            </div>

            <!-- What we match on -->
            <section class="card mx-answers">
                <div class="mx-answers-hd">
                    <div>
                        <h2>We match on your answers</h2>
                        <p>
                            These come from your profile. A mentor scores by how many of them they
                            also listed &mdash; areas you want to learn count most, then skills, then programme.
                        </p>
                    </div>
                </div>

                <div class="mx-groups">
                    <?php foreach ($groups as $type => [$title, $note]): ?>
                        <div>
                            <div class="mx-group-key">
                                <strong><?= htmlspecialchars($title) ?></strong>
                                <span><?= htmlspecialchars($note) ?></span>
                            </div>
                            <?php if ($tags[$type]): ?>
                                <div class="mx-chips">
                                    <?php foreach ($tags[$type] as $t): ?>
                                        <span class="tag-pill"><?= htmlspecialchars($t) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="mx-empty-group">Nothing added yet.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mx-answers-ft">
                    <a class="btn btn-primary" href="<?= htmlspecialchars($profile_url) ?>">
                        <?= $has_answers ? 'Update my answers' : 'Add my answers' ?>
                    </a>
                    <span class="mx-hint">Changes here re-rank your matches the next time you refresh.</span>
                </div>
            </section>

            <!-- Results -->
            <div id="mxResults">
                <?php pc_render_matches($matches, $others, $has_answers, $profile_url, $find_url); ?>
            </div>
        </main>
    </div>

    <script>
        (function() {
            var btn = document.getElementById('mxRefresh');
            var box = document.getElementById('mxResults');
            if (!btn || !box || !window.fetch) return; // link still works without JS

            var url = <?= json_encode(url('mentee-matching') . '?fragment=1') ?>;
            var busy = false;

            btn.addEventListener('click', function(e) {
                e.preventDefault(); // only now that we know we can do this ourselves
                if (busy) return;
                busy = true;
                btn.setAttribute('aria-busy', 'true');
                box.classList.add('is-busy');

                fetch(url + '&t=' + Date.now(), {
                        credentials: 'same-origin',
                        headers: { 'Accept': 'text/html' }
                    })
                    .then(function(r) {
                        if (!r.ok) throw new Error(r.status);
                        return r.text();
                    })
                    .then(function(html) {
                        box.innerHTML = html;
                    })
                    .catch(function() {
                        // Never leave the visitor on a stale, greyed-out list.
                        window.location.href = btn.href;
                    })
                    .then(function() {
                        busy = false;
                        btn.removeAttribute('aria-busy');
                        box.classList.remove('is-busy');
                    });
            });
        })();
    </script>
</body>

</html>
