<?php
/**
 * First-login questionnaire — three steps, one POST.
 *
 * Deliberately renders WITHOUT app_shell: this is a gate, and a sidebar full
 * of links the user is being asked not to take yet would undo it. The only
 * ways out are Skip and Finish, and both of them write to the database.
 *
 * Everything offered here comes from App/config/onboarding_catalog.php, and
 * everything already selected comes from this account's own user_tags rows.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
include __DIR__ . "/../db.php";

$role = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id'])) {
    header("Location: " . url('welcomepage'));
    exit;
}

// An account with no role cannot answer what follows — every step below is
// phrased for a mentee or a mentor. Ask which they are first. Such accounts
// exist because two of the three sign-up paths could create one, and until
// now nothing anywhere let them choose: each dashboard bounced them back to
// the front door and this page turned them away too.
if (!in_array($role, ['mentee', 'mentor'], true)) {
    if ($role === 'admin') {
        header("Location: " . url('admin-dashboard'));
        exit;
    }
    require __DIR__ . '/role_choice.php';
    exit;
}

$user_id     = (int)$_SESSION['user_id'];
$catalog     = require __DIR__ . '/../../config/onboarding_catalog.php';
$dashboard   = url($role === 'mentor' ? 'mentor-dashboard' : 'mentee-dashboard');
$profile_url = url($role === 'mentor' ? 'mentor-profile' : 'mentee-profile');

// Steps run in the order the tag types are stored in, which is also the order
// the Profile page lists them: programme, then skills, then subjects.
$steps = ['interest', 'skill', 'learn'];

// Already-answered? Then this is someone re-taking it from their Profile, so
// pre-select what they have rather than making them start over.
$existing = ['interest' => [], 'skill' => [], 'learn' => []];
foreach (ProfileRepository::tagsInOrderAdded($con, $user_id) as $row) {
    $existing[$row['tag_type']][] = $row['tag'];
}

// Tags this account already has that aren't in the catalog — free text typed
// on the Profile page, or an earlier "Other". They're rendered as extra
// selected tiles so finishing the questionnaire can't silently delete them.
$extras = [];
foreach ($steps as $type) {
    $known = array_column($catalog[$type]['items'], 'tag');
    $extras[$type] = array_values(array_diff($existing[$type], $known));
}

/** Renders one option tile. $extra marks a preserved free-text tag. */
function onb_tile(array $item, bool $selected, bool $extra = false): void
{
    [$bg, $fg] = PC_ONB_TINTS[$item['tint']] ?? PC_ONB_TINTS['blue'];
    $icon = PC_ONB_ICONS[$item['icon']] ?? PC_ONB_ICONS['dots'];
    ?>
    <button type="button" class="onb-tile<?= $selected ? ' is-on' : '' ?>"
            data-tag="<?= e($item['tag']) ?>"<?= $extra ? ' data-extra="1"' : '' ?>
            aria-pressed="<?= $selected ? 'true' : 'false' ?>">
        <span class="onb-tile-ico" style="background:<?= e($bg) ?>;color:<?= e($fg) ?>;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><?= $icon ?></svg>
        </span>
        <span class="onb-tile-txt">
            <span class="onb-tile-label"><?= e($item['label']) ?></span>
            <?php if ($item['sub'] !== ''): ?><span class="onb-tile-sub"><?= e($item['sub']) ?></span><?php endif; ?>
        </span>
        <span class="onb-tile-check" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4.5 4.5L19 7"/></svg>
        </span>
    </button>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set up your profile — PeerConnect</title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        body {
            background: #F8F9FE;
        }

        .onb-wrap {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 22px 20px 40px;
        }

        .onb-inner {
            width: 100%;
            max-width: 780px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        /* ── Header: back · step dots · skip ── */
        .onb-head {
            display: grid;
            grid-template-columns: 44px minmax(0, 1fr) auto;
            align-items: center;
            gap: 10px;
            margin-bottom: 26px;
        }

        .onb-back {
            width: 40px;
            height: 40px;
            display: grid;
            place-items: center;
            border: 0;
            background: transparent;
            border-radius: 50%;
            color: var(--forest);
            cursor: pointer;
            transition: background .15s;
        }

        .onb-back:hover {
            background: rgba(2, 5, 71, .06);
        }

        /* Step 1 has nothing to go back to, but the button must keep its grid
           cell — dropping it out of flow shifts the step dots off centre. */
        .onb-back[hidden] {
            display: grid;
            visibility: hidden;
            pointer-events: none;
        }

        .onb-dots {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
        }

        .onb-dot {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-size: 15px;
            font-weight: 700;
            background: #E9ECF5;
            color: #97A0BC;
            transition: background .2s, color .2s;
        }

        .onb-dot.is-on {
            background: var(--mint);
            color: #fff;
        }

        .onb-dot.is-done {
            background: var(--mint-faint);
            color: var(--mint);
        }

        .onb-bar {
            width: 84px;
            max-width: 22vw;
            height: 2px;
            background: #E3E7F1;
        }

        .onb-bar.is-done {
            background: var(--mint);
        }

        .onb-skip {
            border: 0;
            background: transparent;
            font: inherit;
            font-size: 16px;
            font-weight: 500;
            color: var(--gray-500);
            padding: 8px 6px;
            cursor: pointer;
            border-radius: 8px;
        }

        .onb-skip:hover {
            color: var(--forest);
            text-decoration: underline;
        }

        /* ── Step body ── */
        .onb-step[hidden] {
            display: none;
        }

        .onb-hero-ico {
            width: 64px;
            height: 64px;
            margin: 6px auto 18px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--mint-faint);
            color: var(--mint);
        }

        .onb-hero-ico svg {
            width: 30px;
            height: 30px;
        }

        .onb-title {
            font-size: 30px;
            line-height: 1.18;
            font-weight: 800;
            color: var(--forest);
            text-align: center;
            margin: 0 0 12px;
            letter-spacing: -.4px;
        }

        .onb-sub {
            font-size: 16px;
            line-height: 1.5;
            color: var(--gray-500);
            text-align: center;
            margin: 0 auto 26px;
            max-width: 560px;
        }

        .onb-grid {
            display: grid;
            gap: 12px;
        }

        .onb-grid[data-cols="3"] {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .onb-grid[data-cols="2"] {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        /* ── Option tile ── */
        .onb-tile {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            text-align: left;
            padding: 12px 14px;
            background: #fff;
            border: 1.5px solid #ECEFF7;
            border-radius: 999px;
            cursor: pointer;
            font: inherit;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
            transition: border-color .15s, box-shadow .15s, background .15s;
        }

        .onb-tile:hover {
            border-color: #D5DCEC;
            box-shadow: 0 3px 10px rgba(16, 24, 40, .07);
        }

        .onb-tile.is-on {
            border-color: var(--mint);
            background: var(--mint-faint);
        }

        .onb-tile:focus-visible {
            outline: 2px solid var(--mint);
            outline-offset: 2px;
        }

        /* Cap reached: unselected tiles stop inviting a click. */
        .onb-grid.is-full .onb-tile:not(.is-on) {
            opacity: .5;
        }

        .onb-tile-ico {
            flex: 0 0 42px;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: grid;
            place-items: center;
        }

        .onb-tile-ico svg {
            width: 21px;
            height: 21px;
        }

        .onb-tile-txt {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 1px;
        }

        .onb-tile-label {
            font-size: 14.5px;
            font-weight: 600;
            color: var(--forest);
            line-height: 1.25;
        }

        .onb-tile-sub {
            font-size: 13.5px;
            color: var(--gray-500);
            line-height: 1.25;
        }

        .onb-tile-check {
            margin-left: auto;
            flex: 0 0 20px;
            width: 20px;
            height: 20px;
            color: var(--mint);
            opacity: 0;
            transform: scale(.7);
            transition: opacity .15s, transform .15s;
        }

        .onb-tile.is-on .onb-tile-check {
            opacity: 1;
            transform: scale(1);
        }

        /* ── "Other (Specify)" ── */
        .onb-other-form {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }

        .onb-other-form[hidden] {
            display: none;
        }

        .onb-other-form input {
            flex: 1 1 auto;
            min-width: 0;
            padding: 12px 16px;
            border: 1.5px solid #E3E7F1;
            border-radius: 999px;
            font: inherit;
            font-size: 14.5px;
            color: var(--forest);
            background: #fff;
        }

        .onb-other-form input:focus {
            outline: none;
            border-color: var(--mint);
        }

        /* ── Footer ── */
        .onb-foot {
            margin-top: 26px;
            text-align: center;
        }

        .onb-count {
            font-size: 16px;
            color: var(--gray-500);
            margin-bottom: 14px;
        }

        .onb-count b {
            color: var(--mint);
            font-weight: 700;
        }

        .onb-next {
            width: 100%;
            padding: 17px 20px;
            border: 0;
            border-radius: 999px;
            font: inherit;
            font-size: 17px;
            font-weight: 600;
            color: #fff;
            background: var(--mint);
            cursor: pointer;
            transition: background .15s, opacity .15s;
        }

        .onb-next:hover:not(:disabled) {
            background: var(--forest-2);
        }

        .onb-next:disabled {
            background: #D7DBE6;
            color: #8B93AC;
            cursor: not-allowed;
        }

        .onb-err {
            margin-top: 12px;
            font-size: 14px;
            color: var(--danger);
            min-height: 18px;
        }

        @media (max-width: 860px) {
            .onb-title {
                font-size: 25px;
            }

            .onb-sub {
                font-size: 15px;
            }

            .onb-grid[data-cols="3"] {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 560px) {
            .onb-wrap {
                padding: 14px 14px 32px;
            }

            .onb-title {
                font-size: 22px;
            }

            .onb-grid[data-cols="3"],
            .onb-grid[data-cols="2"] {
                grid-template-columns: minmax(0, 1fr);
            }

            .onb-bar {
                width: 48px;
            }

            .onb-skip {
                font-size: 15px;
            }
        }
    </style>
</head>

<body>
    <div class="onb-wrap">
        <div class="onb-inner">
            <div class="onb-head">
                <button type="button" class="onb-back" id="onb-back" hidden aria-label="Previous step">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.5 5-7 7 7 7" />
                    </svg>
                </button>
                <div class="onb-dots">
                    <?php foreach ($steps as $i => $type): ?>
                        <?php if ($i > 0): ?><span class="onb-bar" data-bar="<?= $i ?>"></span><?php endif; ?>
                        <span class="onb-dot<?= $i === 0 ? ' is-on' : '' ?>" data-dot="<?= $i ?>"><?= $i + 1 ?></span>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="onb-skip" id="onb-skip">Skip</button>
            </div>

            <?php foreach ($steps as $i => $type):
                $step = $catalog[$type];
                // The same option list means opposite things to the two roles,
                // so the heading is role-specific too — not just the sub-line.
                // Falls back to the shared title if a step has no role variant.
                $titleKey = $role === 'mentor' ? 'title_mentor' : 'title_mentee';
                $title = $step[$titleKey] ?? $step['title'];
                $sub  = $role === 'mentor' ? $step['sub_mentor'] : $step['sub_mentee'];
                $sel  = $existing[$type];
            ?>
                <section class="onb-step" data-step="<?= $i ?>" data-type="<?= e($type) ?>" <?= $i === 0 ? '' : 'hidden' ?>>
                    <div class="onb-hero-ico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><?= PC_ONB_ICONS[$step['icon']] ?></svg>
                    </div>
                    <h1 class="onb-title"><?= e($title) ?></h1>
                    <p class="onb-sub"><?= e($sub) ?></p>

                    <div class="onb-grid" data-cols="<?= (int)$step['columns'] ?>">
                        <?php foreach ($step['items'] as $item): ?>
                            <?php onb_tile($item, in_array($item['tag'], $sel, true)); ?>
                        <?php endforeach; ?>

                        <?php // Free-text tags this account already had — selected, and removable.
                        foreach ($extras[$type] as $tag): ?>
                            <?php onb_tile(['tag' => $tag, 'label' => $tag, 'sub' => '', 'icon' => 'dots', 'tint' => 'teal'], true, true); ?>
                        <?php endforeach; ?>

                        <?php if (!empty($step['other'])): ?>
                            <button type="button" class="onb-tile onb-other-btn">
                                <span class="onb-tile-ico" style="background:<?= e(PC_ONB_TINTS['green'][0]) ?>;color:<?= e(PC_ONB_TINTS['green'][1]) ?>;">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><?= PC_ONB_ICONS['dots'] ?></svg>
                                </span>
                                <span class="onb-tile-txt">
                                    <span class="onb-tile-label">Other</span>
                                    <span class="onb-tile-sub">(Specify)</span>
                                </span>
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($step['other'])): ?>
                        <div class="onb-other-form" hidden>
                            <input type="text" maxlength="120" placeholder="Type a subject and press Add" aria-label="Other subject">
                            <button type="button" class="btn btn-primary onb-other-add">Add</button>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <div class="onb-foot">
                <p class="onb-count"><b id="onb-n">0</b>/<?= PC_ONB_MAX ?> selected</p>
                <button type="button" class="onb-next" id="onb-next" disabled>Continue</button>
                <p class="onb-err" id="onb-err" role="status" aria-live="polite"></p>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const MAX = <?= PC_ONB_MAX ?>;
            const SAVE_URL = <?= json_encode(url('profile-save')) ?>;
            const DONE_URL = <?= json_encode($dashboard) ?>;
            const CSRF = <?= json_encode(csrf_token()) ?>;
            // interest / skill / learn, in the order the steps are rendered.
            const FIELD = {
                interest: 'interests',
                skill: 'skills',
                learn: 'learn'
            };

            const steps = Array.from(document.querySelectorAll('.onb-step'));
            const backBtn = document.getElementById('onb-back');
            const skipBtn = document.getElementById('onb-skip');
            const nextBtn = document.getElementById('onb-next');
            const countEl = document.getElementById('onb-n');
            const errEl = document.getElementById('onb-err');
            let idx = 0;
            let busy = false;

            const grid = s => s.querySelector('.onb-grid');
            const chosen = s => Array.from(grid(s).querySelectorAll('.onb-tile.is-on'));

            function paintStep() {
                const s = steps[idx];
                const n = chosen(s).length;
                countEl.textContent = n;
                grid(s).classList.toggle('is-full', n >= MAX);
                nextBtn.disabled = n === 0;
                nextBtn.textContent = idx === steps.length - 1 ? 'Finish' : 'Continue';
                backBtn.hidden = idx === 0;
                document.querySelectorAll('[data-dot]').forEach(d => {
                    const i = +d.dataset.dot;
                    d.classList.toggle('is-on', i === idx);
                    d.classList.toggle('is-done', i < idx);
                });
                document.querySelectorAll('[data-bar]').forEach(b => {
                    b.classList.toggle('is-done', +b.dataset.bar <= idx);
                });
            }

            function show(i) {
                idx = i;
                steps.forEach((s, k) => s.hidden = k !== i);
                errEl.textContent = '';
                paintStep();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            // Tile toggling, and the "Other" affordance, are delegated so the
            // handler also covers tiles added at runtime.
            document.addEventListener('click', e => {
                const otherBtn = e.target.closest('.onb-other-btn');
                if (otherBtn) {
                    const form = otherBtn.closest('.onb-step').querySelector('.onb-other-form');
                    form.hidden = !form.hidden;
                    if (!form.hidden) form.querySelector('input').focus();
                    return;
                }
                const addBtn = e.target.closest('.onb-other-add');
                if (addBtn) {
                    addOther(addBtn.closest('.onb-step'));
                    return;
                }
                const tile = e.target.closest('.onb-tile');
                if (!tile || tile.classList.contains('onb-other-btn')) return;

                const s = tile.closest('.onb-step');
                if (!tile.classList.contains('is-on') && chosen(s).length >= MAX) {
                    errEl.textContent = 'You can pick up to ' + MAX + '. Deselect one first.';
                    return;
                }
                tile.classList.toggle('is-on');
                tile.setAttribute('aria-pressed', tile.classList.contains('is-on'));
                // A tile the user typed themselves disappears when unpicked —
                // it has no place in the list otherwise.
                if (tile.dataset.extra && !tile.classList.contains('is-on')) tile.remove();
                errEl.textContent = '';
                paintStep();
            });

            document.addEventListener('keydown', e => {
                if (e.key === 'Enter' && e.target.matches('.onb-other-form input')) {
                    e.preventDefault();
                    addOther(e.target.closest('.onb-step'));
                }
            });

            function addOther(step) {
                const input = step.querySelector('.onb-other-form input');
                const value = input.value.trim().replace(/\s+/g, ' ');
                if (!value) return;
                const existing = Array.from(grid(step).querySelectorAll('.onb-tile[data-tag]'))
                    .find(t => t.dataset.tag.toLowerCase() === value.toLowerCase());
                if (existing) {
                    if (!existing.classList.contains('is-on')) existing.click();
                    input.value = '';
                    return;
                }
                if (chosen(step).length >= MAX) {
                    errEl.textContent = 'You can pick up to ' + MAX + '. Deselect one first.';
                    return;
                }
                const tpl = grid(step).querySelector('.onb-tile[data-extra]') ||
                    grid(step).querySelector('.onb-tile[data-tag]');
                const tile = tpl.cloneNode(true);
                tile.dataset.tag = value;
                tile.dataset.extra = '1';
                tile.classList.add('is-on');
                tile.setAttribute('aria-pressed', 'true');
                tile.querySelector('.onb-tile-label').textContent = value;
                const sub = tile.querySelector('.onb-tile-sub');
                if (sub) sub.remove();
                const ico = tile.querySelector('.onb-tile-ico');
                ico.style.background = <?= json_encode(PC_ONB_TINTS['teal'][0]) ?>;
                ico.style.color = <?= json_encode(PC_ONB_TINTS['teal'][1]) ?>;
                ico.querySelector('svg').innerHTML = <?= json_encode(PC_ONB_ICONS['dots']) ?>;
                grid(step).insertBefore(tile, grid(step).querySelector('.onb-other-btn'));
                input.value = '';
                errEl.textContent = '';
                paintStep();
            }

            /** Everything picked across all three steps, ready to POST. */
            function payload() {
                const body = new URLSearchParams();
                body.set('csrf_token', CSRF);
                steps.forEach(s => {
                    // JSON, not a comma-joined string: several catalog labels
                    // contain commas ("Science, Technology and Society (STS)").
                    body.set(FIELD[s.dataset.type], JSON.stringify(chosen(s).map(t => t.dataset.tag)));
                });
                return body;
            }

            async function post(section) {
                if (busy) return;
                busy = true;
                nextBtn.disabled = true;
                const body = payload();
                body.set('section', section);
                try {
                    const res = await fetch(SAVE_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body
                    });
                    const data = await res.json();
                    if (!data.success) throw new Error(data.message || 'Could not save.');
                    window.location.href = DONE_URL;
                } catch (err) {
                    busy = false;
                    errEl.textContent = err.message || 'Could not save. Please try again.';
                    paintStep();
                }
            }

            backBtn.addEventListener('click', () => show(idx - 1));
            nextBtn.addEventListener('click', () => {
                if (chosen(steps[idx]).length === 0) return;
                if (idx < steps.length - 1) show(idx + 1);
                else post('onboarding');
            });
            skipBtn.addEventListener('click', () => post('onboarding-skip'));

            paintStep();
        })();
    </script>
</body>

</html>
