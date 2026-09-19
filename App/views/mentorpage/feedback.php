<?php

/**
 * feedback.php — the mentor's Feedback page.
 *
 * Every number here is computed from the `feedback` rows this mentor has
 * actually received: the four stat cards, the per-category breakdown, the
 * insight lines and each review's category chip. Nothing is illustrative —
 * a mentor with no reviews gets the empty state, not zeros dressed up as
 * results, and a trend line only appears when there are two periods to
 * compare.
 */

session_start();
include __DIR__ . "/../db.php";
require_once __DIR__ . "/../includes/feedback_page.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mentor') {
    header("Location: " . url('welcomepage'));
    exit;
}

// MySQL stores these datetimes in the server's zone; the app reads them back
// as Manila time everywhere else, so match that here before formatting.
date_default_timezone_set('Asia/Manila');

$mentor_id = (int)$_SESSION['user_id'];

/*
 * The four rated dimensions. Column names are historical (`efficiency`,
 * `skill`); the labels are what the mentee actually sees on the review form.
 * This order drives the breakdown bars, the category filter, and the
 * tie-break when one review scores two dimensions equally.
 */
$CATS = [
    'communication' => ['label' => 'Communication',            'color' => 'var(--info)',    'bg' => 'var(--info-bg)'],
    'efficiency'    => ['label' => 'Interaction & Engagement', 'color' => 'var(--success)', 'bg' => 'var(--success-bg)'],
    'knowledge'     => ['label' => 'Knowledge & Expertise',    'color' => 'var(--warning)', 'bg' => 'var(--warning-bg)'],
    'skill'         => ['label' => 'Guidance & Support',       'color' => 'var(--purple)',  'bg' => 'var(--purple-bg)'],
];

/*
 * The reviews. `feedback.session_id` points at a session_request; duration and
 * session type live on the availability slot that request was booked against,
 * matched the way the rest of the app matches them (mentor + subject + exact
 * start). That slot can have been deleted since, so both are optional and the
 * session line renders only the parts that came back.
 */
$rows = FeedbackRepository::receivedWithSessions($con, $mentor_id, true);

$total = count($rows);

// ── Aggregates ────────────────────────────────────────────────────────────
// Averaged in PHP rather than SQL so the same $rows drive the cards, the bars
// and the list — one query, and the page can never show a stat that does not
// match the reviews printed under it.
$ratingSum  = 0.0;
$catSum     = array_fill_keys(array_keys($CATS), 0.0);
$catCount   = array_fill_keys(array_keys($CATS), 0);
$menteeIds  = [];
$fourPlus   = 0;
$monthStart = strtotime('first day of this month 00:00:00');
$prevStart  = strtotime('first day of last month 00:00:00');
$thisMonth  = [];
$prevMonth  = [];

foreach ($rows as $r) {
    $ratingSum += (float)$r['rating'];
    if ((float)$r['rating'] >= 4) $fourPlus++;
    $menteeIds[(int)$r['mentee_id']] = true;
    foreach ($CATS as $key => $_) {
        if ($r[$key] !== null && $r[$key] !== '') {
            $catSum[$key] += (float)$r[$key];
            $catCount[$key]++;
        }
    }
    $ts = strtotime((string)$r['created_at']);
    if ($ts >= $monthStart) {
        $thisMonth[] = (float)$r['rating'];
    } elseif ($ts >= $prevStart) {
        $prevMonth[] = (float)$r['rating'];
    }
}

$avgOverall = $total ? $ratingSum / $total : 0.0;
$catAvg     = [];
foreach ($CATS as $key => $_) {
    $catAvg[$key] = $catCount[$key] ? $catSum[$key] / $catCount[$key] : null;
}
$rated  = array_filter($catAvg, fn($v) => $v !== null);
$topKey = $rated ? array_search(max($rated), $catAvg, true) : null;
$lowKey = $rated ? array_search(min($rated), $catAvg, true) : null;

$uniqueMentees = count($menteeIds);

// Mentees whose session has already started. Approved sessions still ahead
// used to count, so "of N you've mentored" included people not yet met.
$mentoredCount = SessionRepository::countPartnersSoFar($con, $mentor_id, true);

// Average-rating movement. It only means something with a review in each of
// the two months, so with a single period the card falls back to the count.
$ratingDelta = null;
if ($thisMonth && $prevMonth) {
    $ratingDelta = (array_sum($thisMonth) / count($thisMonth)) - (array_sum($prevMonth) / count($prevMonth));
    if (abs($ratingDelta) < 0.05) $ratingDelta = null;
}
$newThisMonth = count($thisMonth);
$lastAt       = $total ? strtotime((string)$rows[0]['created_at']) : null;

// Finished sessions this mentor hasn't reviewed yet — drives the CTA badge.
$review_pending = FeedbackRepository::countAwaitingReview($con, $mentor_id, true);

/*
 * Insight lines. Each restates something already on the page as a sentence,
 * and each is skipped when its input is missing — so the card never claims
 * more than the reviews support.
 */
$insights = [];
if ($total > 0 && $topKey !== null) {
    $insights[] = ['icon' => 'chart', 'text' => 'Mentees rate your ' . $CATS[$topKey]['label'] . ' highest, at ' . number_format($catAvg[$topKey], 1) . '.'];
}
if ($total > 0 && $lowKey !== null && $lowKey !== $topKey) {
    $insights[] = ['icon' => 'chat', 'text' => $CATS[$lowKey]['label'] . ' scores lowest, at ' . number_format($catAvg[$lowKey], 1) . '.'];
}
if ($total > 0) {
    $insights[] = ['icon' => 'heart', 'text' => $fourPlus . ' of ' . $total . ' review' . ($total === 1 ? '' : 's') . ' rated 4 stars or better.'];
}

$active_page = 'feedback';
$messagesUrl = url('messages');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Feedback — PeerConnect Mentor</title>
    <?php include __DIR__ . '/includes/style.php'; ?>
    <?php fbk_styles(); ?>
</head>

<body>
    <div class="app">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <main class="main">

            <div class="page-hd fbk-hd">
                <div>
                    <h1>My Feedback</h1>
                    <p>Ratings and comments from your mentees</p>
                </div>
                <a href="<?= htmlspecialchars(url('mentor-review')) ?>" class="fbk-review-cta">
                    <span class="fbk-cta-ico"><?= fbk_svg('users', 'width="19" height="19"') ?></span>
                    Review your mentees
                    <?php if ($review_pending > 0): ?>
                        <span class="fbk-cta-n"><?= $review_pending ?></span>
                    <?php endif; ?>
                    <span class="fbk-cta-go"><?= fbk_svg('chevron', 'width="16" height="16"') ?></span>
                </a>
            </div>

            <!-- ── Stats ─────────────────────────────────────────────── -->
            <div class="fbk-stats">
                <div class="card fbk-stat">
                    <div class="fbk-stat-ico" style="background:var(--gold-light);color:var(--gold);"><?= fbk_svg('star', 'width="22" height="22"') ?></div>
                    <div class="fbk-stat-body">
                        <div class="fbk-stat-v"><?= $total ? number_format($avgOverall, 2) : '—' ?></div>
                        <div class="fbk-stat-k">Average Rating</div>
                        <?php if ($ratingDelta !== null): ?>
                            <span class="fbk-stat-sub <?= $ratingDelta > 0 ? 'is-up' : 'is-down' ?>">
                                <?= fbk_svg('arrow-up') ?><?= ($ratingDelta > 0 ? '+' : '−') . number_format(abs($ratingDelta), 1) ?> this month
                            </span>
                        <?php elseif ($total > 0): ?>
                            <span class="fbk-stat-sub">From <?= $total ?> review<?= $total === 1 ? '' : 's' ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card fbk-stat">
                    <div class="fbk-stat-ico" style="background:var(--info-bg);color:var(--info);"><?= fbk_svg('chat', 'width="22" height="22"') ?></div>
                    <div class="fbk-stat-body">
                        <div class="fbk-stat-v"><?= $total ?></div>
                        <div class="fbk-stat-k">Total Reviews</div>
                        <?php if ($newThisMonth > 0): ?>
                            <span class="fbk-stat-sub is-up"><?= fbk_svg('arrow-up') ?>+<?= $newThisMonth ?> this month</span>
                        <?php elseif ($lastAt): ?>
                            <span class="fbk-stat-sub">Latest <?= date('M j, Y', $lastAt) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card fbk-stat">
                    <div class="fbk-stat-ico" style="background:var(--mint-faint);color:var(--mint-deep);"><?= fbk_svg('users', 'width="22" height="22"') ?></div>
                    <div class="fbk-stat-body">
                        <div class="fbk-stat-v"><?= $uniqueMentees ?></div>
                        <div class="fbk-stat-k">Unique Mentees</div>
                        <?php if ($mentoredCount > 0): ?>
                            <span class="fbk-stat-sub">of <?= $mentoredCount ?> you've mentored</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card fbk-stat">
                    <div class="fbk-stat-ico" style="background:var(--purple-bg);color:var(--purple);"><?= fbk_svg('trophy', 'width="22" height="22"') ?></div>
                    <div class="fbk-stat-body">
                        <?php if ($topKey !== null): ?>
                            <div class="fbk-stat-v is-text"><?= htmlspecialchars($CATS[$topKey]['label']) ?></div>
                            <div class="fbk-stat-k">Top Category</div>
                            <span class="fbk-pill"><?= number_format($catAvg[$topKey], 1) ?> average</span>
                        <?php else: ?>
                            <div class="fbk-stat-v is-text">—</div>
                            <div class="fbk-stat-k">Top Category</div>
                            <span class="fbk-stat-sub">Not rated yet</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── Breakdown + reviews ───────────────────────────────── -->
            <div class="fbk-grid">
                <div class="fbk-side">
                    <div class="card card-p">
                        <h2 class="fbk-card-t">Rating Breakdown</h2>
                        <?php if ($rated): ?>
                            <?php foreach ($CATS as $key => $cat): ?>
                                <?php if ($catAvg[$key] === null) continue; ?>
                                <div class="fbk-bar">
                                    <div class="fbk-bar-hd">
                                        <span><?= htmlspecialchars($cat['label']) ?></span>
                                        <span class="fbk-bar-v"><?= number_format($catAvg[$key], 1) ?></span>
                                    </div>
                                    <div class="fbk-bar-track">
                                        <div class="fbk-bar-fill" style="width:<?= round($catAvg[$key] / 5 * 100, 2) ?>%;background:<?= $cat['color'] ?>;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="margin:0;font-size:13px;color:var(--gray-400);">No category scores yet — they appear once a mentee rates a session.</p>
                        <?php endif; ?>
                    </div>

                    <?php if ($insights): ?>
                        <div class="card card-p">
                            <h2 class="fbk-card-t">Recent Feedback Insights</h2>
                            <?php
                            $insTint = [
                                'chart' => ['var(--success-bg)', 'var(--success)'],
                                'chat'  => ['var(--info-bg)',    'var(--info)'],
                                'heart' => ['var(--danger-bg)',  'var(--danger)'],
                            ];
                            foreach ($insights as $ins):
                                [$bg, $fg] = $insTint[$ins['icon']];
                            ?>
                                <div class="fbk-ins">
                                    <span class="fbk-ins-ico" style="background:<?= $bg ?>;color:<?= $fg ?>;"><?= fbk_svg($ins['icon'], 'width="16" height="16"') ?></span>
                                    <span><?= htmlspecialchars($ins['text']) ?></span>
                                </div>
                            <?php endforeach; ?>
                            <p class="fbk-quote">&ldquo;Great mentors create brighter futures.&rdquo;</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <div class="fbk-list-hd">
                        <div>
                            <h2 class="fbk-card-t" style="margin:0;">All Reviews</h2>
                            <span class="fbk-count" id="fbkCount"></span>
                        </div>
                        <?php if ($total > 0): ?>
                            <div class="fbk-filters">
                                <label class="fbk-filter">
                                    <?= fbk_svg('star') ?>
                                    <span class="sr-only">Filter by rating</span>
                                    <select id="fbkRating">
                                        <option value="">All Ratings</option>
                                        <option value="5">5 stars</option>
                                        <option value="4">4 stars</option>
                                        <option value="3">3 stars</option>
                                        <option value="2">2 stars</option>
                                        <option value="1">1 star</option>
                                    </select>
                                </label>
                                <label class="fbk-filter">
                                    <?= fbk_svg('tag') ?>
                                    <span class="sr-only">Filter by category</span>
                                    <select id="fbkCat">
                                        <option value="">All Categories</option>
                                        <?php foreach ($CATS as $key => $cat): ?>
                                            <option value="<?= $key ?>"><?= htmlspecialchars($cat['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="fbk-filter">
                                    <?= fbk_svg('sort') ?>
                                    <span class="sr-only">Sort reviews</span>
                                    <select id="fbkSort">
                                        <option value="new">Newest first</option>
                                        <option value="old">Oldest first</option>
                                        <option value="high">Highest rated</option>
                                        <option value="low">Lowest rated</option>
                                    </select>
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="fbk-reviews" id="fbkList">
                        <?php foreach ($rows as $r):
                            $rating = (float)$r['rating'];
                            $name   = trim($r['firstname'] . ' ' . $r['lastname']);
                            $ini    = strtoupper(substr($r['firstname'], 0, 1) . substr($r['lastname'], 0, 1));
                            [$avBg, $avFg] = fbk_avatar_tint((int)$r['mentee_id']);
                            $ts = strtotime((string)$r['created_at']);

                            // The chip names the dimension this mentee scored
                            // highest; $CATS order breaks a tie.
                            $best = null;
                            foreach ($CATS as $key => $_) {
                                if ($r[$key] === null || $r[$key] === '') continue;
                                if ($best === null || (float)$r[$key] > (float)$r[$best]) $best = $key;
                            }
                            $comment = trim((string)$r['comment']);
                        ?>
                            <article class="card fbk-rev"
                                data-rating="<?= round($rating) ?>"
                                data-cat="<?= $best ?? '' ?>"
                                data-score="<?= $rating ?>"
                                data-time="<?= $ts ?: 0 ?>">
                                <div class="fbk-rev-hd">
                                    <div class="fbk-av" style="background:<?= $avBg ?>;color:<?= $avFg ?>;"><?= htmlspecialchars($ini) ?></div>
                                    <div class="fbk-who">
                                        <p class="fbk-who-n"><?= htmlspecialchars($name) ?></p>
                                        <p class="fbk-who-d"><?= $ts ? date('F j, Y \a\t g:i A', $ts) : '' ?></p>
                                    </div>
                                    <span class="fbk-rate">
                                        <?= fbk_stars($rating) ?>
                                        <span class="fbk-num"><?= number_format($rating, 1) ?></span>
                                    </span>
                                    <?php if ($best !== null): ?>
                                        <span class="fbk-chip" style="background:<?= $CATS[$best]['bg'] ?>;color:<?= $CATS[$best]['color'] ?>;"><?= htmlspecialchars($CATS[$best]['label']) ?></span>
                                    <?php endif; ?>
                                    <div class="fbk-more">
                                        <button type="button" class="fbk-more-btn" aria-haspopup="true" aria-expanded="false"
                                            onclick="fbkMenu(this)" aria-label="Actions for this review">
                                            <?= fbk_svg('kebab') ?>
                                        </button>
                                        <div class="fbk-menu" role="menu">
                                            <a role="menuitem" href="<?= htmlspecialchars($messagesUrl . '?chat=' . (int)$r['mentee_id']) ?>">
                                                <?= fbk_svg('send') ?><span>Message <?= htmlspecialchars($r['firstname']) ?></span>
                                            </a>
                                            <?php if ($comment !== ''): ?>
                                                <button type="button" role="menuitem" onclick="fbkCopy(this)" data-text="<?= htmlspecialchars($comment, ENT_QUOTES) ?>">
                                                    <?= fbk_svg('copy') ?><span>Copy review</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($comment !== ''): ?>
                                    <p class="fbk-comment"><?= htmlspecialchars($comment) ?></p>
                                <?php endif; ?>

                                <?php if (!empty($r['s_subject'])): ?>
                                    <div class="fbk-sess">
                                        <?= fbk_svg('doc') ?>
                                        <span>Session: <b><?= htmlspecialchars($r['s_subject']) ?></b></span>
                                        <?php if (!empty($r['s_date'])): ?>
                                            <span class="fbk-sess-sep">|</span>
                                            <span><?= date('M j, Y', strtotime((string)$r['s_date'])) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($r['s_duration'])): ?>
                                            <span class="fbk-sess-sep">&bull;</span>
                                            <span><?= (int)$r['s_duration'] ?> mins</span>
                                        <?php endif; ?>
                                        <?php if (!empty($r['s_type'])): ?>
                                            <span class="fbk-sess-sep">&bull;</span>
                                            <span><?= htmlspecialchars($r['s_type']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($total === 0): ?>
                        <div class="card fbk-empty">
                            <?= fbk_svg('chat', 'width="34" height="34"') ?>
                            <p>No feedback received yet.</p>
                            <p style="margin-top:4px;font-size:12.5px;">Reviews appear here once a mentee rates a session you ran.</p>
                        </div>
                    <?php else: ?>
                        <div class="card fbk-empty" id="fbkNone" hidden>
                            <?= fbk_svg('chat', 'width="34" height="34"') ?>
                            <p>No reviews match these filters.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleProfileMenu() {
            document.getElementById('profileMenu').classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const m = document.getElementById('profileMenu');
            if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]')) {
                m.classList.remove('open');
            }
        });

        /* ── Per-review menu ── */
        function fbkCloseMenus(except) {
            document.querySelectorAll('.fbk-more.open').forEach(w => {
                if (w === except) return;
                w.classList.remove('open');
                const b = w.querySelector('.fbk-more-btn');
                if (b) b.setAttribute('aria-expanded', 'false');
            });
        }

        function fbkMenu(btn) {
            const wrap = btn.closest('.fbk-more');
            const open = !wrap.classList.contains('open');
            fbkCloseMenus(wrap);
            wrap.classList.toggle('open', open);
            btn.setAttribute('aria-expanded', String(open));
        }
        document.addEventListener('click', e => {
            if (!e.target.closest('.fbk-more')) fbkCloseMenus(null);
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') fbkCloseMenus(null);
        });

        function fbkCopy(btn) {
            const text = btn.dataset.text || '';
            const label = btn.querySelector('span');
            const done = () => {
                if (!label) return;
                const was = label.textContent;
                label.textContent = 'Copied';
                setTimeout(() => {
                    label.textContent = was;
                }, 1200);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done).catch(() => fbkCopyFallback(text, done));
            } else {
                // http://localhost is a secure context in Chrome but not in
                // every browser, so keep the execCommand path around.
                fbkCopyFallback(text, done);
            }
        }

        function fbkCopyFallback(text, done) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.cssText = 'position:fixed;top:-1000px;opacity:0;';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                done();
            } catch (err) {
                /* nothing more we can do — the menu just stays as it was */
            }
            ta.remove();
        }

        /* ── Filter + sort over the rendered cards ── */
        (function() {
            const list = document.getElementById('fbkList');
            if (!list) return;
            const rating = document.getElementById('fbkRating');
            const cat = document.getElementById('fbkCat');
            const sort = document.getElementById('fbkSort');
            const count = document.getElementById('fbkCount');
            const none = document.getElementById('fbkNone');
            const cards = Array.from(list.children);

            function apply() {
                const r = rating ? rating.value : '';
                const c = cat ? cat.value : '';
                let shown = 0;
                cards.forEach(card => {
                    const ok = (r === '' || card.dataset.rating === r) &&
                        (c === '' || card.dataset.cat === c);
                    card.hidden = !ok;
                    if (ok) shown++;
                });

                const dir = sort ? sort.value : 'new';
                const ordered = cards.slice().sort((a, b) => {
                    if (dir === 'new') return b.dataset.time - a.dataset.time;
                    if (dir === 'old') return a.dataset.time - b.dataset.time;
                    if (dir === 'high') return b.dataset.score - a.dataset.score || b.dataset.time - a.dataset.time;
                    return a.dataset.score - b.dataset.score || b.dataset.time - a.dataset.time;
                });
                ordered.forEach(card => list.appendChild(card));

                if (none) none.hidden = shown !== 0;
                if (count) {
                    count.textContent = shown === cards.length ?
                        cards.length + (cards.length === 1 ? ' review' : ' reviews') :
                        shown + ' of ' + cards.length + ' reviews';
                }
            }
            [rating, cat, sort].forEach(el => el && el.addEventListener('change', apply));
            apply();
        })();
    </script>
</body>

</html>
