<?php

/**
 * admin-review — the page the owner lands on from their email.
 *
 * No sign-in. The signature in the address is the authorisation, and it is
 * good for one application until that application is decided. What it shows is
 * everything needed to make the decision: who applied, what they claim, and
 * both documents.
 *
 * The decision itself is a POST from the form below, never this GET. Mail
 * providers and antivirus scanners fetch the links in a message to check them;
 * an approve-on-GET would hand out administrator access the moment the mail
 * arrived, with nobody having read it. See AdminReviewLink.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../../services/AdminReviewLink.php';

$check = AdminReviewLink::check($_GET);
$app   = null;
$who   = null;
$error = $check['ok'] ? '' : $check['error'];

if ($check['ok']) {
    $app = VerificationRepository::byId($con, $check['id']);
    if ($app === null) {
        $error = 'That application no longer exists.';
    } elseif (UserRepository::role($con, (int)$app['user_id']) !== 'admin') {
        // Members are reviewed on the site by somebody signed in.
        $error = 'That application is not an administrator application.';
    } elseif (($app['status'] ?? '') !== 'pending') {
        $error = 'That application has already been ' . htmlspecialchars((string)$app['status']) . '.';
    } else {
        $who = UserRepository::namesAndPhoto($con, (int)$app['user_id']);
    }
}

$expires  = (int)($_GET['e'] ?? 0);
$idUrl    = $app ? AdminReviewLink::url('admin-review-file', (int)$app['verification_id'], 'id', $expires) : '';
$corUrl   = $app ? AdminReviewLink::url('admin-review-file', (int)$app['verification_id'], 'cor', $expires) : '';
$platform = pc_setting($con, 'platform_name');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Administrator application — <?= htmlspecialchars($platform) ?></title>
    <?php require_once __DIR__ . '/../includes/design_system.php'; ?>
    <style>
        :root { color-scheme: light; }

        body {
            margin: 0;
            padding: 30px 18px 60px;
            background:
                radial-gradient(1000px 480px at 4% -6%, #E9F0FE 0%, rgba(233, 240, 254, 0) 60%),
                radial-gradient(900px 460px at 100% 102%, #E7EEFD 0%, rgba(231, 238, 253, 0) 58%),
                var(--bg);
        }

        .rv-wrap { max-width: 760px; margin: 0 auto; }

        .rv-card {
            background: var(--surface);
            border-radius: 20px;
            box-shadow: 0 24px 56px -32px rgba(16, 32, 68, .4);
            padding: 30px;
        }

        .rv-top { display: flex; gap: 16px; align-items: flex-start; }

        .rv-shield {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            background: var(--mint, #0868AD);
            color: #fff;
            display: grid;
            place-items: center;
            flex: none;
        }

        .rv-shield svg { width: 24px; height: 24px; }

        .rv-card h1 {
            margin: 0 0 6px;
            font-size: 26px;
            font-weight: 700;
            letter-spacing: -.02em;
            color: var(--navy, #0B2C63);
        }

        .rv-sub { margin: 0; font-size: 14px; line-height: 1.6; color: var(--gray-600); }

        .rv-rule { height: 1px; background: var(--gray-100); margin: 22px 0; }

        .rv-who { display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }

        .rv-av {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: #DCEAFE;
            color: var(--navy, #0B2C63);
            display: grid;
            place-items: center;
            font-weight: 700;
            font-size: 17px;
            overflow: hidden;
            flex: none;
        }

        .rv-av img { width: 100%; height: 100%; object-fit: cover; }
        .rv-who-n { font-size: 19px; font-weight: 700; color: var(--navy, #0B2C63); }
        .rv-who-m { font-size: 14px; color: var(--mint, #0868AD); word-break: break-all; }

        /* ── What they claim ────────────────────────────────────────────── */
        .rv-facts {
            background: #F5F8FE;
            border-radius: 15px;
            padding: 6px 20px;
            margin-bottom: 20px;
        }

        .rv-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 26px;
        }

        .rv-f {
            display: flex;
            gap: 13px;
            align-items: flex-start;
            padding: 16px 0;
            border-bottom: 1px solid #E5EDFA;
        }

        /* The last row spans both columns, so the rule under it would be the
           only one running the full width. Rows in the final band lose it. */
        .rv-f.wide { grid-column: 1 / -1; }
        .rv-f.last, .rv-f.wide { border-bottom: 0; }

        .rv-ico {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            background: #E3EDFD;
            color: var(--mint, #0868AD);
            display: grid;
            place-items: center;
            flex: none;
        }

        .rv-ico svg { width: 19px; height: 19px; }

        .rv-k {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #7D94BC;
            margin-bottom: 3px;
        }

        .rv-v { font-size: 15px; font-weight: 600; color: var(--navy, #0B2C63); line-height: 1.45; }

        /* ── The documents ──────────────────────────────────────────────── */
        .rv-docs { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px; }

        .rv-doc {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 18px;
            border: 1px solid #D5E3FA;
            border-radius: 14px;
            background: #FAFCFF;
            text-decoration: none;
            transition: background .15s, border-color .15s;
        }

        .rv-doc:hover { background: #F1F6FE; border-color: #B9D2F6; }

        .rv-doc-ico { color: var(--mint, #0868AD); flex: none; }
        .rv-doc-ico svg { width: 34px; height: 34px; }
        /* Block, or the title and its description sit on one line: spans are
           inline and these are two stacked lines. */
        .rv-doc-t { display: block; font-size: 15.5px; font-weight: 700; color: var(--navy, #0B2C63); }
        .rv-doc-d { display: block; margin-top: 2px; font-size: 12.5px; color: var(--gray-500); }
        .rv-doc-go { margin-left: auto; color: var(--mint, #0868AD); flex: none; }
        .rv-doc-go svg { width: 18px; height: 18px; }

        /* ── The decision ───────────────────────────────────────────────── */
        .rv-acts { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }

        .rv-btn {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 14px 24px;
            border: 0;
            border-radius: 11px;
            font: inherit;
            font-size: 15px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
        }

        .rv-btn svg { width: 18px; height: 18px; }
        .rv-btn.yes { background: var(--mint, #0868AD); }
        .rv-btn.yes:hover { background: #06527F; }
        .rv-btn.no { background: #E2574C; }
        .rv-btn.no:hover { background: #C9463C; }

        .rv-reason {
            width: 100%;
            box-sizing: border-box;
            font: inherit;
            font-size: 14px;
            padding: 13px 15px;
            border: 1px solid #D5E3FA;
            border-radius: 12px;
            background: #FAFCFF;
            resize: vertical;
            min-height: 82px;
            color: var(--ink);
        }

        .rv-reason::placeholder { color: #9FB0CC; }

        .rv-note {
            border-radius: 13px;
            padding: 15px 17px;
            font-size: 14px;
            line-height: 1.6;
            background: #FDECEA;
            color: #C0392B;
        }

        @media (max-width: 640px) {
            .rv-card { padding: 22px 18px; }
            .rv-card h1 { font-size: 21px; }
            .rv-grid, .rv-docs { grid-template-columns: 1fr; }
            .rv-f { border-bottom: 1px solid #E5EDFA; }
        }
    </style>
</head>

<body>
    <div class="rv-wrap">
        <div class="rv-card">
            <?php if ($error !== ''): ?>
                <div class="rv-top">
                    <span class="rv-shield" style="background:#E2574C;">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" d="M12 7.5v5M12 16h.01" />
                        </svg>
                    </span>
                    <div>
                        <h1>Nothing to review</h1>
                        <p class="rv-sub">This link cannot be used.</p>
                    </div>
                </div>
                <div class="rv-rule"></div>
                <div class="rv-note"><?= htmlspecialchars($error) ?></div>
            <?php else:
                $name = trim(($who['firstname'] ?? '') . ' ' . ($who['lastname'] ?? ''));
                $name = $name !== '' ? $name : (string)$app['full_name'];
            ?>
                <div class="rv-top">
                    <span class="rv-shield">
                        <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M12 3l7 3v5.5c0 4.2-2.9 7.6-7 8.5-4.1-.9-7-4.3-7-8.5V6l7-3Z" />
                            <circle cx="12" cy="10.5" r="2.1" />
                            <path stroke-linecap="round" d="M8.8 16.2a3.6 3.6 0 0 1 6.4 0" />
                        </svg>
                    </span>
                    <div>
                        <h1>Administrator application</h1>
                        <p class="rv-sub">
                            Approving this opens the full administrator dashboard to them, including
                            every member's information. Check both documents before you decide.
                        </p>
                    </div>
                </div>

                <div class="rv-rule"></div>

                <div class="rv-who">
                    <span class="rv-av"><?= pc_avatar($who['profile_image'] ?? '', $name) ?></span>
                    <div style="min-width:0;">
                        <div class="rv-who-n"><?= htmlspecialchars($name) ?></div>
                        <div class="rv-who-m"><?= htmlspecialchars((string)($who['email'] ?? '')) ?></div>
                    </div>
                </div>

                <div class="rv-facts">
                    <div class="rv-grid">
                        <div class="rv-f">
                            <span class="rv-ico">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <rect x="3" y="5" width="18" height="14" rx="2.5" />
                                    <circle cx="9" cy="11" r="2" />
                                    <path stroke-linecap="round" d="M14 10h4M14 13.5h4M5.8 15.6a3.6 3.6 0 0 1 6.4 0" />
                                </svg>
                            </span>
                            <div>
                                <div class="rv-k">Student ID</div>
                                <div class="rv-v"><?= htmlspecialchars((string)$app['student_id']) ?></div>
                            </div>
                        </div>

                        <div class="rv-f">
                            <span class="rv-ico">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <circle cx="12" cy="8" r="3.4" />
                                    <path stroke-linecap="round" d="M5.5 20a6.5 6.5 0 0 1 13 0" />
                                </svg>
                            </span>
                            <div>
                                <div class="rv-k">Post</div>
                                <div class="rv-v"><?= htmlspecialchars((string)$app['club']) ?></div>
                            </div>
                        </div>

                        <div class="rv-f">
                            <span class="rv-ico">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m12 5 9 4-9 4-9-4 9-4Z" />
                                    <path stroke-linecap="round" d="M6 11v4.2c0 1.2 2.7 2.8 6 2.8s6-1.6 6-2.8V11" />
                                </svg>
                            </span>
                            <div>
                                <div class="rv-k">Course</div>
                                <div class="rv-v"><?= htmlspecialchars((string)$app['course']) ?></div>
                            </div>
                        </div>

                        <div class="rv-f">
                            <span class="rv-ico">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <rect x="3.5" y="5" width="17" height="15" rx="2.5" />
                                    <path stroke-linecap="round" d="M8 3v4M16 3v4M3.5 10h17" />
                                </svg>
                            </span>
                            <div>
                                <div class="rv-k">Year level</div>
                                <div class="rv-v"><?= htmlspecialchars((string)$app['year_level']) ?></div>
                            </div>
                        </div>

                        <div class="rv-f wide">
                            <span class="rv-ico">
                                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="9" />
                                    <path stroke-linecap="round" d="M12 7.5V12l3 1.8" />
                                </svg>
                            </span>
                            <div>
                                <div class="rv-k">Submitted</div>
                                <div class="rv-v"><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$app['submitted_at']))) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php // New tabs: reading a document must not lose the page the
                      // decision is made from. ?>
                <div class="rv-docs">
                    <a class="rv-doc" href="<?= htmlspecialchars($idUrl) ?>" target="_blank" rel="noopener">
                        <span class="rv-doc-ico">
                            <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5M7 3h8l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" />
                                <circle cx="12.5" cy="14.5" r="2.2" />
                            </svg>
                        </span>
                        <span>
                            <span class="rv-doc-t">View ID</span>
                            <span class="rv-doc-d">Open student ID document</span>
                        </span>
                        <span class="rv-doc-go">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m0 0-5-5m5 5-5 5" />
                            </svg>
                        </span>
                    </a>

                    <a class="rv-doc" href="<?= htmlspecialchars($corUrl) ?>" target="_blank" rel="noopener">
                        <span class="rv-doc-ico">
                            <svg fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5M7 3h8l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" />
                                <path stroke-linecap="round" d="M9 13h6M9 16.5h4" />
                            </svg>
                        </span>
                        <span>
                            <span class="rv-doc-t">View COR</span>
                            <span class="rv-doc-d">Open Certificate of Registration</span>
                        </span>
                        <span class="rv-doc-go">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m0 0-5-5m5 5-5 5" />
                            </svg>
                        </span>
                    </a>
                </div>

                <?php /* One form, two submit buttons: each carries its own decision,
                        and the reason travels with whichever is pressed. The signed
                        query goes in the body — this page proved nothing the POST
                        can lean on, so admin-review-act checks it all again. */ ?>
                <form method="POST" action="<?= url('admin-review-act') ?>">
                    <input type="hidden" name="v" value="<?= (int)$app['verification_id'] ?>">
                    <input type="hidden" name="a" value="review">
                    <input type="hidden" name="e" value="<?= (int)$expires ?>">
                    <input type="hidden" name="s" value="<?= htmlspecialchars((string)$_GET['s']) ?>">

                    <div class="rv-acts">
                        <button class="rv-btn yes" type="submit" name="decision" value="approve">
                            <svg fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                            </svg>
                            Approve this administrator
                        </button>
                        <button class="rv-btn no" type="submit" name="decision" value="reject">
                            <svg fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6 6 18" />
                            </svg>
                            Reject
                        </button>
                    </div>

                    <label for="rv-reason" class="rv-k" style="display:block;margin-bottom:7px;">Reason, if rejecting</label>
                    <textarea id="rv-reason" class="rv-reason" name="notes" maxlength="500"
                              placeholder="They are told this, so say what was wrong."></textarea>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
