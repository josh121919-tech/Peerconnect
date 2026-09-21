<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Landing-page figures ─────────────────────────────────────────────────
// Every number on this page is counted from this database. The reference
// design also asked for "Countries Worldwide"; nothing in the schema records
// a country, so that card shows the subject taxonomy instead rather than a
// number nobody could stand behind.
$stat_members = (int)($con->query("
    SELECT COUNT(*) c FROM users
    WHERE status = 'active' AND role IN ('mentee','mentor')
")->fetch_assoc()['c'] ?? 0);

// A mentorship is a relationship, not a meeting: count distinct mentee/mentor
// pairs that have finished at least one session together.
$stat_mentorships = (int)($con->query("
    SELECT COUNT(*) c FROM (
        SELECT DISTINCT mentee_id, mentor_id FROM session_requests WHERE status = 'completed'
    ) pairs
")->fetch_assoc()['c'] ?? 0);

$rating_row     = $con->query("SELECT ROUND(AVG(rating),1) a, COUNT(*) n FROM feedback")->fetch_assoc() ?: [];
$stat_rating    = (float)($rating_row['a'] ?? 0);
$stat_reviews   = (int)($rating_row['n'] ?? 0);
$stat_subjects  = count(PC_CLUBS);

// ── Faces for the hero cluster ───────────────────────────────────────────
// Real accounts only, and only those whose profile visibility is "everyone"
// — the same switch that decides whether they appear in the mentor
// directory. Anyone set to "mentors" or "private" is left out, and a member
// with no photo is drawn as initials rather than a stock face.
$hero_faces = [];
$faces_q = $con->query("
    SELECT u.firstname, u.lastname, pr.profile_image
    FROM users u
    LEFT JOIN profile pr ON pr.user_id = u.user_id
    WHERE u.status = 'active' AND u.role IN ('mentee','mentor')
      AND COALESCE(pr.visibility, 'everyone') = 'everyone'
    ORDER BY u.created_at DESC
    LIMIT 5
");
if ($faces_q) {
    while ($row = $faces_q->fetch_assoc()) {
        $hero_faces[] = [
            'image'    => $row['profile_image'] ?: null,
            'initials' => strtoupper(mb_substr($row['firstname'] ?? 'P', 0, 1) . mb_substr($row['lastname'] ?? 'C', 0, 1)),
        ];
    }
}

// ── Testimonials ─────────────────────────────────────────────────────────
// Real mentee reviews, nothing else. There is deliberately no sample-copy
// fallback: an invented quote attributed to an invented student is worse
// than no section at all, so the section below hides itself when nothing
// qualifies. The bar is a rating of 4+, a comment with something actually in
// it, and an author who has not switched off "share my activity".
$testimonials = [];
$fb_q = $con->query("
    SELECT f.rating, f.comment, sr.subject,
           u.firstname, u.lastname, u.role
    FROM feedback f
    JOIN users u             ON u.user_id  = f.mentee_id
    JOIN session_requests sr ON sr.request_id = f.session_id
    LEFT JOIN privacy_settings ps ON ps.user_id = f.mentee_id
    LEFT JOIN profile pr          ON pr.user_id = f.mentee_id
    WHERE f.rating >= 4
      AND CHAR_LENGTH(TRIM(COALESCE(f.comment,''))) >= 25
      AND COALESCE(ps.share_activity, 1) = 1
      AND COALESCE(pr.visibility, 'everyone') <> 'private'
    ORDER BY f.created_at DESC
    LIMIT 3
");
if ($fb_q) {
    while ($row = $fb_q->fetch_assoc()) {
        $fn = trim((string)$row['firstname']);
        $ln = trim((string)$row['lastname']);
        $testimonials[] = [
            'text'     => $row['comment'],
            // Surname reduced to an initial: this page is public.
            'name'     => trim($fn . ' ' . ($ln !== '' ? mb_strtoupper(mb_substr($ln, 0, 1)) . '.' : '')),
            'role'     => $row['subject'] ? ($row['subject'] . ' mentee') : 'Mentee',
            'initials' => strtoupper(mb_substr($fn ?: 'P', 0, 1) . mb_substr($ln ?: 'C', 0, 1)),
            'stars'    => max(1, min(5, (int)round((float)$row['rating']))),
        ];
    }
}

// The hero illustration is a designer-supplied file. Checked rather than
// assumed so a missing asset leaves a clean layout instead of a broken image.
$hero_art = 'images/background.png';
$has_hero_art = is_file(PUBLIC_PATH . '/' . $hero_art);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PeerConnect — Free student mentorship</title>
    <?php require_once __DIR__ . '/includes/design_system.php'; ?>
    <style>
        /* ═══════════════════════════════════════════════════════════
           HOME PAGE — landing design
           Colours come from the shared design system, so a visitor who
           signs up lands in an app that looks like the page that sold it.
           ═══════════════════════════════════════════════════════════ */

        body {
            background: var(--surface);
        }

        .lp-wrap {
            max-width: 1520px;
            margin: 0 auto;
            padding: 0 44px;
        }

        /* ── Nav ── */
        .lp-nav {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(255, 255, 255, .92);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid transparent;
            transition: border-color .2s, box-shadow .2s;
        }

        .lp-nav.stuck {
            border-bottom-color: var(--border);
            box-shadow: 0 2px 14px rgba(16, 24, 40, .05);
        }

        .lp-nav-inner {
            display: flex;
            align-items: center;
            gap: 28px;
            height: 76px;
        }

        .lp-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: var(--forest);
            flex-shrink: 0;
        }

        .lp-brand .lp-brand-svg {
            width: 40px;
            height: 40px;
            color: var(--mint-deep);
            flex: 0 0 auto;
        }

        .lp-brand-txt {
            display: flex;
            flex-direction: column;
            line-height: 1.05;
        }

        .lp-brand-name {
            font-size: 19px;
            font-weight: 800;
            letter-spacing: -.3px;
        }

        .lp-brand-name em {
            font-style: normal;
            color: var(--mint-deep);
        }

        .lp-brand-tag {
            font-size: 10px;
            color: var(--gray-500);
            letter-spacing: .1px;
        }

        .lp-nav-links {
            display: flex;
            align-items: center;
            gap: 26px;
            margin-left: auto;
        }

        .lp-nav-links a.lp-navlink {
            font-size: 14.5px;
            font-weight: 500;
            color: var(--gray-600);
            text-decoration: none;
            padding: 6px 0;
            border-bottom: 2px solid transparent;
            transition: color .15s, border-color .15s;
        }

        .lp-nav-links a.lp-navlink:hover,
        .lp-nav-links a.lp-navlink.active {
            color: var(--mint-deep);
            border-bottom-color: var(--mint-deep);
        }

        .lp-nav-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .lp-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            font: inherit;
            font-size: 14.5px;
            font-weight: 600;
            border-radius: 10px;
            padding: 11px 22px;
            cursor: pointer;
            border: 1.5px solid transparent;
            text-decoration: none;
            transition: background .15s, color .15s, border-color .15s, transform .12s, box-shadow .15s;
        }

        .lp-btn svg {
            width: 17px;
            height: 17px;
            flex-shrink: 0;
        }

        .lp-btn-primary {
            background: var(--mint-deep);
            color: #fff;
        }

        .lp-btn-primary:hover {
            background: var(--forest-2);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(0, 83, 155, .30);
        }

        .lp-btn-outline {
            background: var(--surface);
            color: var(--forest);
            border-color: var(--border);
        }

        .lp-btn-outline:hover {
            border-color: var(--mint-deep);
            color: var(--mint-deep);
        }

        .lp-btn-lg {
            padding: 15px 30px;
            font-size: 15.5px;
            border-radius: 12px;
        }

        .lp-burger {
            display: none;
            width: 42px;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            color: var(--forest);
            cursor: pointer;
            align-items: center;
            justify-content: center;
        }

        /* ── Hero ── */
        .lp-hero {
            position: relative;
            overflow: hidden;
            padding: 58px 0 84px;
        }

        .lp-hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1.06fr);
            gap: 48px;
            align-items: center;
        }

        .lp-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--mint-faint);
            color: var(--mint-deep);
            font-size: 13px;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 999px;
            margin-bottom: 22px;
        }

        .lp-pill svg {
            width: 15px;
            height: 15px;
        }

        .lp-h1 {
            font-size: clamp(34px, 4.4vw, 56px);
            line-height: 1.08;
            font-weight: 800;
            letter-spacing: -1.4px;
            color: var(--forest);
            margin: 0 0 20px;
        }

        .lp-h1 em {
            font-style: normal;
            color: var(--mint-deep);
        }

        .lp-lead {
            font-size: 17px;
            line-height: 1.75;
            color: var(--gray-600);
            margin: 0 0 30px;
            /* The shell is wide; the paragraph shouldn't be. */
            max-width: 520px;
        }

        .lp-hero-cta {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 34px;
        }

        /* Member cluster — real accounts, initials when there's no photo. */
        .lp-social {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .lp-faces {
            display: flex;
        }

        .lp-face {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 2.5px solid var(--surface);
            margin-left: -11px;
            object-fit: cover;
            display: grid;
            place-items: center;
            font-size: 13px;
            font-weight: 700;
            background: var(--mint-faint);
            color: var(--mint-deep);
            box-shadow: 0 1px 4px rgba(16, 24, 40, .12);
        }

        .lp-face:first-child {
            margin-left: 0;
        }

        .lp-face-count {
            background: var(--forest);
            color: #fff;
            font-size: 12.5px;
        }

        .lp-social-txt {
            font-size: 13.5px;
            line-height: 1.5;
            color: var(--gray-500);
            max-width: 210px;
        }

        /* ── Hero illustration + floating cards ── */
        .lp-hero-art {
            position: relative;
            min-height: 380px;
            display: grid;
            place-items: center;
        }

        .lp-hero-art img {
            width: 100%;
            max-width: 700px;
            height: auto;
            display: block;
            position: relative;
            z-index: 1;
        }

        .lp-blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(2px);
            z-index: 0;
        }

        .lp-blob-1 {
            width: 340px;
            height: 300px;
            background: var(--mint-faint);
            top: 4%;
            left: 6%;
            border-radius: 60% 40% 55% 45% / 55% 50% 50% 45%;
        }

        .lp-blob-2 {
            width: 280px;
            height: 260px;
            background: var(--mint-soft);
            bottom: 6%;
            right: 4%;
            border-radius: 45% 55% 40% 60% / 50% 45% 55% 50%;
        }

        .lp-blob-3 {
            width: 190px;
            height: 180px;
            background: var(--gold-light);
            bottom: 12%;
            right: 16%;
            border-radius: 55% 45% 60% 40% / 45% 55% 45% 55%;
        }

        .lp-dots {
            position: absolute;
            width: 86px;
            height: 66px;
            z-index: 0;
            background-image: radial-gradient(var(--mint-deep) 1.6px, transparent 1.6px);
            background-size: 13px 13px;
            opacity: .5;
        }

        .lp-dots-1 {
            top: 12%;
            right: 6%;
        }

        .lp-dots-2 {
            bottom: 26%;
            left: 2%;
        }

        .lp-float {
            position: absolute;
            z-index: 3;
            display: flex;
            align-items: center;
            gap: 11px;
            background: var(--surface);
            border-radius: 14px;
            padding: 12px 16px;
            box-shadow: 0 10px 30px rgba(16, 24, 40, .13);
            max-width: 215px;
        }

        .lp-float-ico {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
        }

        .lp-float-ico svg {
            width: 20px;
            height: 20px;
        }

        /* Both are spans, so they need to be told to stack. */
        .lp-float-t {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: var(--forest);
            line-height: 1.3;
        }

        .lp-float-s {
            display: block;
            font-size: 11.5px;
            color: var(--gray-500);
            line-height: 1.35;
            margin-top: 2px;
        }

        /* A slow, uneven drift. Three different durations and negative
           delays so the cards never line up into a single pulse, and a hair
           of rotation so the motion reads as floating rather than sliding. */
        @keyframes lp-drift {

            0%,
            100% {
                transform: translate3d(0, 0, 0) rotate(0deg);
            }

            50% {
                transform: translate3d(4px, -13px, 0) rotate(-.7deg);
            }
        }

        @keyframes lp-drift-alt {

            0%,
            100% {
                transform: translate3d(0, 0, 0) rotate(0deg);
            }

            50% {
                transform: translate3d(-5px, -10px, 0) rotate(.8deg);
            }
        }

        .lp-float {
            animation: lp-drift 6.5s ease-in-out infinite;
            will-change: transform;
        }

        .lp-float-1 {
            top: 2%;
            left: 2%;
        }

        .lp-float-2 {
            top: 34%;
            right: -2%;
            animation-name: lp-drift-alt;
            animation-duration: 8.2s;
            animation-delay: -3.1s;
        }

        .lp-float-3 {
            bottom: 6%;
            left: -4%;
            animation-duration: 7.4s;
            animation-delay: -1.6s;
        }

        @media (prefers-reduced-motion: reduce) {
            .lp-float {
                animation: none;
            }
        }

        /* ── Generic section furniture ── */
        .lp-section {
            padding: 82px 0;
        }

        /* The nav is sticky, so anchored sections need to clear it. */
        #top,
        #about,
        #features,
        #how,
        #testimonials,
        #contact {
            scroll-margin-top: 84px;
        }

        html {
            scroll-behavior: smooth;
        }

        @media (prefers-reduced-motion: reduce) {
            html {
                scroll-behavior: auto;
            }
        }

        .lp-section-tint {
            background: var(--mint-faint);
        }

        .lp-eyebrow {
            text-align: center;
            font-size: 12.5px;
            font-weight: 700;
            letter-spacing: 1.6px;
            text-transform: uppercase;
            color: var(--mint-deep);
            margin-bottom: 12px;
        }

        .lp-h2 {
            text-align: center;
            font-size: clamp(25px, 3vw, 34px);
            font-weight: 800;
            letter-spacing: -.7px;
            color: var(--forest);
            margin: 0 0 14px;
        }

        .lp-sub {
            text-align: center;
            font-size: 16px;
            line-height: 1.7;
            color: var(--gray-500);
            max-width: 640px;
            margin: 0 auto 52px;
        }

        /* ── About ── */
        .lp-about {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 22px;
        }

        .lp-about-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 28px 26px;
        }

        .lp-about-card h3 {
            font-size: 16.5px;
            font-weight: 700;
            color: var(--forest);
            margin: 14px 0 8px;
        }

        .lp-about-card p {
            font-size: 14px;
            line-height: 1.7;
            color: var(--gray-600);
            margin: 0;
        }

        .lp-about-ico {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: var(--mint-faint);
            color: var(--mint-deep);
        }

        .lp-about-ico svg {
            width: 23px;
            height: 23px;
        }

        /* ── How it works ── */
        .lp-steps {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 20px;
            position: relative;
        }

        .lp-step {
            text-align: center;
            position: relative;
        }

        /* Dashed connector, drawn between circles only. */
        .lp-step:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 44px;
            left: calc(50% + 62px);
            right: calc(-50% + 62px);
            border-top: 2px dashed var(--mint-soft);
        }

        .lp-step-ico {
            width: 88px;
            height: 88px;
            margin: 0 auto 26px;
            border-radius: 50%;
            background: var(--mint-faint);
            color: var(--mint-deep);
            display: grid;
            place-items: center;
            position: relative;
        }

        .lp-step-ico svg {
            width: 36px;
            height: 36px;
        }

        .lp-step-n {
            position: absolute;
            bottom: -14px;
            left: 50%;
            transform: translateX(-50%);
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--mint-deep);
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            display: grid;
            place-items: center;
            border: 3px solid var(--surface);
        }

        .lp-step h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--forest);
            margin: 0 0 9px;
        }

        .lp-step p {
            font-size: 14px;
            line-height: 1.65;
            color: var(--gray-500);
            margin: 0;
        }

        /* ── Stat band ── */
        .lp-stats {
            margin-top: 62px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 4px 20px rgba(16, 24, 40, .05);
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .lp-stat {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 30px 26px;
            border-right: 1px solid var(--border);
        }

        .lp-stat:last-child {
            border-right: 0;
        }

        .lp-stat-ico {
            width: 44px;
            height: 44px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            color: var(--mint-deep);
        }

        .lp-stat-ico svg {
            width: 30px;
            height: 30px;
        }

        .lp-stat-val {
            display: block;
            font-size: 26px;
            font-weight: 800;
            color: var(--forest);
            line-height: 1.1;
            letter-spacing: -.6px;
        }

        .lp-stat-lbl {
            display: block;
            font-size: 13px;
            color: var(--gray-500);
            margin-top: 3px;
        }

        /* ── Features ── */
        .lp-features {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 22px;
        }

        .lp-feature {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 26px;
            transition: border-color .15s, box-shadow .15s, transform .15s;
        }

        .lp-feature:hover {
            border-color: var(--mint-deep);
            box-shadow: 0 8px 24px rgba(16, 24, 40, .07);
            transform: translateY(-2px);
        }

        .lp-feature-ico {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            margin-bottom: 15px;
        }

        .lp-feature-ico svg {
            width: 22px;
            height: 22px;
        }

        .lp-feature h3 {
            font-size: 15.5px;
            font-weight: 700;
            color: var(--forest);
            margin: 0 0 7px;
        }

        .lp-feature p {
            font-size: 13.8px;
            line-height: 1.65;
            color: var(--gray-600);
            margin: 0;
        }

        /* ── Testimonials ── */
        .lp-quotes {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 22px;
        }

        .lp-quote {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 26px;
            display: flex;
            flex-direction: column;
        }

        .lp-quote-mark {
            font-family: Georgia, serif;
            font-size: 42px;
            line-height: .8;
            color: var(--mint-deep);
            margin-bottom: 12px;
        }

        .lp-quote-txt {
            font-size: 14.5px;
            line-height: 1.75;
            color: var(--gray-700);
            margin: 0 0 22px;
            flex: 1;
        }

        .lp-quote-who {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .lp-quote-av {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--mint-faint);
            color: var(--mint-deep);
            font-size: 13px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .lp-quote-n {
            font-size: 14px;
            font-weight: 700;
            color: var(--forest);
        }

        .lp-quote-r {
            font-size: 12.5px;
            color: var(--gray-500);
        }

        .lp-quote-stars {
            color: var(--gold);
            font-size: 13px;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        /* Shown instead of the cards while no review clears the bar. */
        .lp-quotes-empty {
            max-width: 560px;
            margin: 0 auto;
            text-align: center;
            background: var(--surface);
            border: 1px dashed var(--border);
            border-radius: 16px;
            padding: 38px 30px;
        }

        .lp-quotes-empty p {
            font-size: 14.5px;
            line-height: 1.7;
            color: var(--gray-500);
            margin: 0 0 18px;
        }

        /* ── CTA band ── */
        .lp-cta {
            position: relative;
            overflow: hidden;
            border-radius: 20px;
            background: linear-gradient(100deg, var(--forest) 0%, var(--navy-2) 60%, var(--mint-deep) 130%);
            color: #fff;
            padding: 46px 48px;
            display: flex;
            align-items: center;
            gap: 30px;
            flex-wrap: wrap;
        }

        .lp-cta::after {
            content: "";
            position: absolute;
            right: -70px;
            bottom: -110px;
            width: 300px;
            height: 300px;
            background: var(--gold);
            opacity: .9;
            border-radius: 46% 54% 60% 40% / 52% 45% 55% 48%;
        }

        .lp-cta-txt {
            flex: 1 1 340px;
            position: relative;
            z-index: 1;
        }

        .lp-cta-txt h2 {
            font-size: clamp(22px, 2.6vw, 30px);
            font-weight: 800;
            letter-spacing: -.6px;
            margin: 0 0 8px;
        }

        .lp-cta-txt p {
            font-size: 15px;
            line-height: 1.6;
            opacity: .88;
            margin: 0;
        }

        .lp-cta .lp-btn {
            position: relative;
            z-index: 1;
            background: var(--surface);
            color: var(--forest);
        }

        .lp-cta .lp-btn:hover {
            background: var(--gold-light);
            transform: translateY(-1px);
        }

        /* ── Footer ── */
        .lp-footer {
            border-top: 1px solid var(--border);
            padding: 56px 0 28px;
            background: var(--surface);
        }

        .lp-footer-grid {
            display: grid;
            grid-template-columns: 1.6fr repeat(3, minmax(0, 1fr));
            gap: 34px;
        }

        .lp-footer h4 {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--forest);
            margin: 0 0 15px;
        }

        .lp-footer ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .lp-footer a {
            font-size: 13.5px;
            color: var(--gray-500);
            text-decoration: none;
        }

        .lp-footer a:hover {
            color: var(--mint-deep);
        }

        .lp-footer-blurb {
            font-size: 13.5px;
            line-height: 1.7;
            color: var(--gray-500);
            margin: 14px 0 0;
            max-width: 280px;
        }

        .lp-footer-bottom {
            margin-top: 40px;
            padding-top: 22px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            font-size: 12.5px;
            color: var(--gray-400);
        }

        /* ── Reveal on scroll ── */
        .reveal {
            opacity: 0;
            transform: translateY(16px);
            transition: opacity .55s ease, transform .55s ease;
        }

        .reveal.visible {
            opacity: 1;
            transform: none;
        }

        @media (prefers-reduced-motion: reduce) {
            .reveal {
                opacity: 1;
                transform: none;
                transition: none;
            }
        }

        /* ── Responsive ── */
        @media (max-width: 1080px) {
            .lp-hero-grid {
                grid-template-columns: minmax(0, 1fr);
                gap: 40px;
            }

            .lp-lead {
                max-width: none;
            }

            .lp-float-2 {
                right: 2%;
            }

            .lp-float-3 {
                left: 2%;
            }

            .lp-steps {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                row-gap: 44px;
            }

            /* The connector only makes sense on a single row. */
            .lp-step:not(:last-child)::after {
                display: none;
            }

            .lp-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .lp-stat:nth-child(2) {
                border-right: 0;
            }

            .lp-stat:nth-child(1),
            .lp-stat:nth-child(2) {
                border-bottom: 1px solid var(--border);
            }

            .lp-about,
            .lp-features,
            .lp-quotes {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .lp-footer-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 860px) {
            .lp-nav-links {
                display: none;
            }

            .lp-nav-links.open {
                display: flex;
                position: absolute;
                top: 76px;
                left: 0;
                right: 0;
                flex-direction: column;
                align-items: stretch;
                gap: 0;
                background: #fff;
                border-bottom: 1px solid var(--border);
                box-shadow: 0 10px 24px rgba(16, 24, 40, .08);
                padding: 8px 28px 16px;
            }

            .lp-nav-links.open a.lp-navlink {
                padding: 13px 0;
                border-bottom: 1px solid var(--border);
            }

            .lp-nav-links.open a.lp-navlink:last-child {
                border-bottom: 0;
            }

            .lp-burger {
                display: flex;
                order: 3;
            }

            .lp-nav-actions {
                margin-left: auto;
            }
        }

        @media (max-width: 700px) {
            .lp-wrap {
                padding: 0 18px;
            }

            .lp-hero {
                padding: 34px 0 56px;
            }

            .lp-section {
                padding: 56px 0;
            }

            .lp-sub {
                margin-bottom: 36px;
            }

            /* Two up rather than one tall stack of full-width cards. These
               all hold a short label, so they read fine at half width. */
            .lp-steps,
            .lp-about,
            .lp-features,
            .lp-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            /* A pull quote needs a reading width; at half a phone screen it
               would be three words per line. */
            .lp-quotes,
            .lp-footer-grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .lp-stat {
                padding: 20px 16px;
                gap: 12px;
            }

            /* Borders become a grid, not a single column rule. */
            .lp-stat:nth-child(odd) {
                border-right: 1px solid var(--border);
            }

            .lp-stat:nth-child(even) {
                border-right: 0;
            }

            .lp-stat:nth-child(1),
            .lp-stat:nth-child(2) {
                border-bottom: 1px solid var(--border);
            }

            .lp-stat-ico {
                width: 34px;
                height: 34px;
            }

            .lp-stat-ico svg {
                width: 24px;
                height: 24px;
            }

            .lp-stat-val {
                font-size: 21px;
            }

            .lp-stat-lbl {
                font-size: 12px;
            }

            /* Half-width cards need their own scale, or they just look like
               big cards squeezed. */
            .lp-about-card,
            .lp-feature {
                padding: 18px 16px;
            }

            .lp-about-card h3,
            .lp-feature h3 {
                font-size: 14.5px;
            }

            .lp-about-card p,
            .lp-feature p {
                font-size: 12.8px;
                line-height: 1.6;
            }

            .lp-step-ico {
                width: 68px;
                height: 68px;
                margin-bottom: 22px;
            }

            .lp-step-ico svg {
                width: 28px;
                height: 28px;
            }

            .lp-step p {
                font-size: 13px;
            }

            /* Floating cards overlap badly at this width — the illustration
               carries the section on its own. */
            .lp-float {
                display: none;
            }

            .lp-hero-art {
                min-height: 0;
            }

            .lp-cta {
                padding: 34px 26px;
            }

            .lp-hero-cta .lp-btn {
                flex: 1 1 100%;
            }

            .lp-nav-actions .lp-btn {
                padding: 10px 16px;
                font-size: 13.5px;
            }
        }

        /* The dropdown item is only for the widths where the bar drops the
           Log In button; everywhere else the button itself is the entry. */
        .lp-navlink-auth {
            display: none;
        }

        @media (max-width: 430px) {

            /* At 360px the brand plus both buttons need ~400px of a 324px bar.
               Log In moves into the menu — it is still one tap away — and the
               brand shrinks, leaving Sign Up as the single visible action. */
            .lp-nav-actions .lp-btn-outline {
                display: none;
            }

            .lp-nav-links.open .lp-navlink-auth {
                display: block;
            }

            .lp-brand-tag {
                display: none;
            }

            .lp-brand svg,
            .lp-brand .lp-brand-svg {
                width: 32px;
                height: 32px;
            }

            .lp-brand-name {
                font-size: 16px;
            }

            .lp-nav-inner {
                gap: 10px;
                height: 64px;
            }

            .lp-nav-actions {
                gap: 8px;
            }

            .lp-nav-actions .lp-btn {
                padding: 9px 13px;
                font-size: 13px;
            }

            .lp-burger {
                width: 38px;
                height: 38px;
            }

            .lp-nav-links.open {
                top: 64px;
                padding: 8px 18px 14px;
            }

            /* Two columns of text-heavy cards get too narrow here. */
            .lp-about,
            .lp-features {
                grid-template-columns: minmax(0, 1fr);
            }

            /* Half a phone leaves ~120px beside the icon, which breaks the
               labels into three ragged lines. Stack instead. */
            .lp-stat {
                flex-direction: column;
                text-align: center;
                gap: 8px;
                padding: 18px 10px;
            }

            .lp-h1 {
                font-size: clamp(28px, 8.5vw, 34px);
            }
        }
    </style>
</head>

<body>
    <!-- ══ NAV ══ -->
    <header class="lp-nav" id="lpNav">
        <div class="lp-wrap lp-nav-inner">
            <a class="lp-brand" href="#top">
                <?php pc_brand_mark('lp-brand-svg'); ?>
                <span class="lp-brand-txt">
                    <span class="lp-brand-name">PEER<em>CONNECT</em></span>
                    <span class="lp-brand-tag">Mentoring. Growing. Together.</span>
                </span>
            </a>

            <nav class="lp-nav-links" id="lpNavLinks" aria-label="Primary">
                <a class="lp-navlink active" href="#top">Home</a>
                <a class="lp-navlink" href="#about">About</a>
                <a class="lp-navlink" href="#how">How It Works</a>
                <a class="lp-navlink" href="#features">Features</a>
                <a class="lp-navlink" href="#testimonials">Testimonials</a>
                <a class="lp-navlink" href="#contact">Contact</a>
                <?php // Only rendered into the dropdown on narrow screens, where the
                //     Log In button is dropped from the bar for room. 
                ?>
                <a class="lp-navlink lp-navlink-auth" href="<?= htmlspecialchars(url('login')) ?>">Log In</a>
            </nav>

            <div class="lp-nav-actions">
                <a class="lp-btn lp-btn-outline" href="<?= htmlspecialchars(url('login')) ?>">Log In</a>
                <a class="lp-btn lp-btn-primary" href="<?= htmlspecialchars(url('signup')) ?>">Sign Up</a>
                <button class="lp-burger" type="button" id="lpBurger" aria-label="Menu" aria-expanded="false">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <!-- ══ HERO ══ -->
    <section class="lp-hero" id="top">
        <div class="lp-wrap lp-hero-grid">
            <div>
                <span class="lp-pill">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 19c0-2.1-1.7-3.8-3.8-3.8h-.4C9.2 15.2 7.5 16.9 7.5 19" />
                        <circle cx="11.5" cy="9" r="3.2" />
                        <path stroke-linecap="round" d="M17.5 12.2a2.6 2.6 0 0 0 0-5M20.5 18c0-1.6-1-3-2.5-3.5" />
                    </svg>
                    Grow through mentorship
                </span>

                <h1 class="lp-h1">Mentoring today,<br><em>better</em> tomorrow.</h1>

                <p class="lp-lead">
                    PeerConnect connects learners and mentors in meaningful ways to share
                    knowledge, build skills, and achieve goals together.
                </p>

                <div class="lp-hero-cta">
                    <a class="lp-btn lp-btn-primary lp-btn-lg" href="<?= htmlspecialchars(url('signup')) ?>">
                        Get Started
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
                        </svg>
                    </a>
                    <a class="lp-btn lp-btn-outline lp-btn-lg" href="#how">
                        Learn More
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path d="M10 8.5v7l6-3.5-6-3.5Z" fill="currentColor" stroke="none" />
                        </svg>
                    </a>
                </div>

                <?php if ($hero_faces): ?>
                    <div class="lp-social">
                        <div class="lp-faces">
                            <?php foreach ($hero_faces as $face): ?>
                                <?php if (!empty($face['image'])): ?>
                                    <img class="lp-face" src="<?= htmlspecialchars($face['image']) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="lp-face"><?= htmlspecialchars($face['initials']) ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <span class="lp-face lp-face-count"><?= number_format($stat_members) ?></span>
                        </div>
                        <p class="lp-social-txt">
                            <?= $stat_members === 1 ? 'member' : 'members' ?> learning and mentoring on PeerConnect
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="lp-hero-art">
                <?php if ($has_hero_art): ?>
                    <img src="<?= htmlspecialchars(asset($hero_art)) ?>" alt="Two students working together on a laptop" width="1536" height="1024" fetchpriority="high">
                <?php else: ?>
                    <?php // The illustration carries its own blobs, dots and circles. These
                    //     stand in only when the file is missing, so the column never
                    //     collapses — drawing both would double every shape up.
                    ?>
                    <span class="lp-blob lp-blob-1" aria-hidden="true"></span>
                    <span class="lp-blob lp-blob-2" aria-hidden="true"></span>
                    <span class="lp-blob lp-blob-3" aria-hidden="true"></span>
                    <span class="lp-dots lp-dots-1" aria-hidden="true"></span>
                    <span class="lp-dots lp-dots-2" aria-hidden="true"></span>
                <?php endif; ?>

                <div class="lp-float lp-float-1">
                    <span class="lp-float-ico" style="background:var(--mint-faint);color:var(--mint-deep);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.5C10.4 5.2 8.4 4.5 6 4.5H4v13h2c2.4 0 4.4.7 6 2 1.6-1.3 3.6-2 6-2h2v-13h-2c-2.4 0-4.4.7-6 2Z" />
                            <path stroke-linecap="round" d="M12 6.5v13" />
                        </svg>
                    </span>
                    <span>
                        <span class="lp-float-t">Share Knowledge</span>
                        <span class="lp-float-s">Learn and grow together</span>
                    </span>
                </div>

                <div class="lp-float lp-float-2">
                    <span class="lp-float-ico" style="background:var(--info-bg);color:var(--info);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <circle cx="12" cy="12" r="8.5" />
                            <circle cx="12" cy="12" r="4.5" />
                            <circle cx="12" cy="12" r="1" />
                        </svg>
                    </span>
                    <span>
                        <span class="lp-float-t">Achieve Goals</span>
                        <span class="lp-float-s">Stay focused and reach your goals</span>
                    </span>
                </div>

                <div class="lp-float lp-float-3">
                    <span class="lp-float-ico" style="background:var(--success-bg);color:var(--success);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 19c0-2.1-1.7-3.8-3.8-3.8h-.4C9.2 15.2 7.5 16.9 7.5 19" />
                            <circle cx="11.5" cy="9" r="3.2" />
                            <path stroke-linecap="round" d="M17.5 12.2a2.6 2.6 0 0 0 0-5M20.5 18c0-1.6-1-3-2.5-3.5" />
                        </svg>
                    </span>
                    <span>
                        <span class="lp-float-t">Build Connections</span>
                        <span class="lp-float-s">Connect with the right people</span>
                    </span>
                </div>
            </div>
        </div>
    </section>

    <!-- ══ ABOUT ══ -->
    <section class="lp-section lp-section-tint" id="about">
        <div class="lp-wrap">
            <p class="lp-eyebrow reveal">About PeerConnect</p>
            <h2 class="lp-h2 reveal">Mentorship, run by students</h2>
            <p class="lp-sub reveal">
                PeerConnect is a peer mentoring platform built for our campus. Students who have
                already cleared a subject volunteer as mentors, and anyone who needs a hand can
                book time with them — at no cost.
            </p>

            <div class="lp-about">
                <div class="lp-about-card reveal">
                    <span class="lp-about-ico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m12 4 9 4.5-9 4.5-9-4.5L12 4Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 10.5V16c0 1.4 2.5 2.5 5.5 2.5s5.5-1.1 5.5-2.5v-5.5" />
                        </svg>
                    </span>
                    <h3>Peer to peer</h3>
                    <p>Mentors are fellow students who have taken the same subjects, so the help you get is grounded in the coursework you're actually facing.</p>
                </div>
                <div class="lp-about-card reveal">
                    <span class="lp-about-ico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.4 2.3 2.3 4.9-5.4" />
                        </svg>
                    </span>
                    <h3>Verified mentors</h3>
                    <p>Every mentor submits their student ID and credentials for review. Only approved accounts appear in the directory and can accept bookings.</p>
                </div>
                <div class="lp-about-card reveal">
                    <span class="lp-about-ico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 20s-7.5-4.4-7.5-9.3A4.2 4.2 0 0 1 12 8a4.2 4.2 0 0 1 7.5 2.7C19.5 15.6 12 20 12 20Z" />
                        </svg>
                    </span>
                    <h3>Free, always</h3>
                    <p>There is no payment anywhere in PeerConnect. Mentors volunteer their time and earn recognition through feedback and the leaderboard instead.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ══ HOW IT WORKS ══ -->
    <section class="lp-section" id="how">
        <div class="lp-wrap">
            <p class="lp-eyebrow reveal">How it works</p>
            <h2 class="lp-h2 reveal">Simple steps to start your journey</h2>
            <p class="lp-sub reveal">Four steps from signing up to your first session.</p>

            <div class="lp-steps">
                <div class="lp-step reveal">
                    <span class="lp-step-ico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                            <circle cx="10" cy="8.5" r="3.6" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.6 19.5c0-3 2.9-5.4 6.4-5.4M17 13.5v6M20 16.5h-6" />
                        </svg>
                        <span class="lp-step-n">1</span>
                    </span>
                    <h3>Create an Account</h3>
                    <p>Sign up as a mentee or mentor and set up your profile.</p>
                </div>

                <div class="lp-step reveal">
                    <span class="lp-step-ico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                            <circle cx="11" cy="11" r="7" />
                            <path stroke-linecap="round" d="m20 20-4.2-4.2" />
                        </svg>
                        <span class="lp-step-n">2</span>
                    </span>
                    <h3>Find the Right Match</h3>
                    <p>Discover and connect with mentors or mentees who align with your goals.</p>
                </div>

                <div class="lp-step reveal">
                    <span class="lp-step-ico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                            <rect x="4" y="5" width="16" height="16" rx="3" />
                            <path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16" />
                            <circle cx="9" cy="14" r="1" fill="currentColor" stroke="none" />
                            <circle cx="13" cy="14" r="1" fill="currentColor" stroke="none" />
                            <circle cx="9" cy="17.5" r="1" fill="currentColor" stroke="none" />
                        </svg>
                        <span class="lp-step-n">3</span>
                    </span>
                    <h3>Book a Session</h3>
                    <p>Schedule sessions and start your personalized mentorship journey.</p>
                </div>

                <div class="lp-step reveal">
                    <span class="lp-step-ico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" d="M4 19V5M4 19h16" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m7.5 15 3.5-4 3 2.4L19 8" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 8H19v3.5" />
                        </svg>
                        <span class="lp-step-n">4</span>
                    </span>
                    <h3>Learn &amp; Grow</h3>
                    <p>Share knowledge, track progress, and achieve your goals together.</p>
                </div>
            </div>

            <!-- Live counts from this database — see the queries at the top of this file. -->
            <div class="lp-stats reveal">
                <div class="lp-stat">
                    <span class="lp-stat-ico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 19c0-2.1-1.7-3.8-3.8-3.8h-.4C9.2 15.2 7.5 16.9 7.5 19" />
                            <circle cx="11.5" cy="9" r="3.2" />
                            <path stroke-linecap="round" d="M17.5 12.2a2.6 2.6 0 0 0 0-5M20.5 18c0-1.6-1-3-2.5-3.5" />
                        </svg>
                    </span>
                    <span>
                        <span class="lp-stat-val"><?= number_format($stat_members) ?></span>
                        <span class="lp-stat-lbl">Active members</span>
                    </span>
                </div>

                <div class="lp-stat">
                    <span class="lp-stat-ico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12.5 5 15.5a2 2 0 0 0 2.8 2.8l1-1 1.6 1.6a1.8 1.8 0 0 0 2.6-2.6l1 1a1.9 1.9 0 0 0 2.7-2.7l1 1a1.9 1.9 0 0 0 2.7-2.7L15 6.5l-3 1-3-1-6 5" />
                        </svg>
                    </span>
                    <span>
                        <span class="lp-stat-val"><?= number_format($stat_mentorships) ?></span>
                        <span class="lp-stat-lbl">Mentorships completed</span>
                    </span>
                </div>

                <div class="lp-stat">
                    <span class="lp-stat-ico" style="color:var(--warning);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m12 3.6 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.8l5.9-.9L12 3.6Z" />
                        </svg>
                    </span>
                    <span>
                        <span class="lp-stat-val"><?= $stat_reviews > 0 ? number_format($stat_rating, 1) . '/5' : '—' ?></span>
                        <span class="lp-stat-lbl">
                            <?= $stat_reviews > 0
                                ? 'Average rating from ' . number_format($stat_reviews) . ' review' . ($stat_reviews === 1 ? '' : 's')
                                : 'No ratings yet' ?>
                        </span>
                    </span>
                </div>

                <div class="lp-stat">
                    <span class="lp-stat-ico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5M9 13h6M9 17h4" />
                        </svg>
                    </span>
                    <span>
                        <span class="lp-stat-val"><?= number_format($stat_subjects) ?></span>
                        <span class="lp-stat-lbl">Subject areas covered</span>
                    </span>
                </div>
            </div>
        </div>
    </section>

    <!-- ══ FEATURES ══ -->
    <section class="lp-section lp-section-tint" id="features">
        <div class="lp-wrap">
            <p class="lp-eyebrow reveal">Features</p>
            <h2 class="lp-h2 reveal">Everything a mentorship needs</h2>
            <p class="lp-sub reveal">Each of these is live in the app today — no waitlists, no paid tiers.</p>

            <div class="lp-features">
                <div class="lp-feature reveal">
                    <span class="lp-feature-ico" style="background:var(--mint-faint);color:var(--mint-deep);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <circle cx="11" cy="11" r="7" />
                            <path stroke-linecap="round" d="m20 20-4.2-4.2" />
                        </svg>
                    </span>
                    <h3>Mentor matching</h3>
                    <p>Answer a short questionnaire and get ranked matches based on the subjects and skills you and a mentor actually share.</p>
                </div>

                <div class="lp-feature reveal">
                    <span class="lp-feature-ico" style="background:var(--info-bg);color:var(--info);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <rect x="4" y="5" width="16" height="16" rx="3" />
                            <path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16" />
                        </svg>
                    </span>
                    <h3>Booking &amp; calendar</h3>
                    <p>Pick a slot from a mentor's real availability. Approved sessions land on your in-app calendar and sync to Google Calendar.</p>
                </div>

                <div class="lp-feature reveal">
                    <span class="lp-feature-ico" style="background:var(--success-bg);color:var(--success);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <rect x="3" y="6" width="13" height="12" rx="2.4" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m16 10.5 5-2.6v8.2l-5-2.6" />
                        </svg>
                    </span>
                    <h3>Built-in video sessions</h3>
                    <p>Meet in the browser. The room opens shortly before the start time and closes when the session is marked finished.</p>
                </div>

                <div class="lp-feature reveal">
                    <span class="lp-feature-ico" style="background:var(--warning-bg);color:var(--warning);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" />
                            <rect x="9" y="3" width="6" height="4" rx="1.2" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 13 2 2 4-4" />
                        </svg>
                    </span>
                    <h3>Assessments</h3>
                    <p>Mentors build short quizzes, mentees take them, and results are scored automatically so both sides can see progress.</p>
                </div>

                <div class="lp-feature reveal">
                    <span class="lp-feature-ico" style="background:var(--purple-bg);color:var(--purple);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5M9 13h6M9 17h4" />
                        </svg>
                    </span>
                    <h3>Shared resources</h3>
                    <p>Reviewers, handouts and notes uploaded by mentors, organised by club so you only see what's relevant to your subject.</p>
                </div>

                <div class="lp-feature reveal">
                    <span class="lp-feature-ico" style="background:var(--gold-light);color:var(--warning);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 4h10v5a5 5 0 0 1-10 0V4Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 6H4.5v1.5A3.5 3.5 0 0 0 8 11M17 6h2.5v1.5A3.5 3.5 0 0 1 16 11M12 14v4M8.5 21h7" />
                        </svg>
                    </span>
                    <h3>Feedback &amp; leaderboard</h3>
                    <p>Both sides review each session. Those ratings feed a public leaderboard that recognises the mentors putting in the work.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ══ TESTIMONIALS ══ -->
    <section class="lp-section" id="testimonials">
        <div class="lp-wrap">
            <p class="lp-eyebrow reveal">What our users say</p>
            <h2 class="lp-h2 reveal">Real stories from our community</h2>
            <p class="lp-sub reveal">Written by mentees after their own sessions — nothing here is scripted.</p>

            <?php if ($testimonials): ?>
                <div class="lp-quotes">
                    <?php foreach ($testimonials as $t): ?>
                        <figure class="lp-quote reveal">
                            <span class="lp-quote-mark" aria-hidden="true">&ldquo;</span>
                            <div class="lp-quote-stars" aria-label="<?= (int)$t['stars'] ?> out of 5">
                                <?= str_repeat('&#9733;', (int)$t['stars']) . str_repeat('&#9734;', 5 - (int)$t['stars']) ?>
                            </div>
                            <blockquote class="lp-quote-txt"><?= htmlspecialchars($t['text']) ?></blockquote>
                            <figcaption class="lp-quote-who">
                                <span class="lp-quote-av"><?= htmlspecialchars($t['initials']) ?></span>
                                <span>
                                    <span class="lp-quote-n"><?= htmlspecialchars($t['name']) ?></span><br>
                                    <span class="lp-quote-r"><?= htmlspecialchars($t['role']) ?></span>
                                </span>
                            </figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <!-- No qualifying review yet. An invented quote from an invented
                     student would be worse than saying so plainly. -->
                <div class="lp-quotes-empty reveal">
                    <p>
                        No reviews to show yet. Every quote on this page is written by a real
                        mentee after a real session, so this space stays empty until the first
                        one comes in.
                    </p>
                    <a class="lp-btn lp-btn-primary" href="<?= htmlspecialchars(url('signup')) ?>">Be the first — join free</a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ══ CTA ══ -->
    <section class="lp-wrap" style="padding-bottom:82px;">
        <div class="lp-cta reveal">
            <div class="lp-cta-txt">
                <h2>Ready to grow together?</h2>
                <p>Join PeerConnect today and start your mentorship journey.</p>
            </div>
            <a class="lp-btn lp-btn-lg" href="<?= htmlspecialchars(url('signup')) ?>">
                Sign Up Now
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
                </svg>
            </a>
        </div>
    </section>

    <!-- ══ FOOTER ══ -->
    <footer class="lp-footer" id="contact">
        <div class="lp-wrap">
            <div class="lp-footer-grid">
                <div>
                    <a class="lp-brand" href="#top">
                        <?php pc_brand_mark('lp-brand-svg'); ?>
                        <span class="lp-brand-txt">
                            <span class="lp-brand-name">PEER<em>CONNECT</em></span>
                            <span class="lp-brand-tag">Mentoring. Growing. Together.</span>
                        </span>
                    </a>
                    <p class="lp-footer-blurb">
                        A free peer mentoring platform built for students, run by students.
                    </p>
                </div>

                <div>
                    <h4>Platform</h4>
                    <ul>
                        <li><a href="#about">About</a></li>
                        <li><a href="#features">Features</a></li>
                        <li><a href="#how">How it works</a></li>
                        <li><a href="#testimonials">Testimonials</a></li>
                    </ul>
                </div>

                <div>
                    <h4>Get started</h4>
                    <ul>
                        <li><a href="<?= htmlspecialchars(url('signup')) ?>">Create an account</a></li>
                        <li><a href="<?= htmlspecialchars(url('login')) ?>">Log in</a></li>
                    </ul>
                </div>

                <div>
                    <h4>Contact</h4>
                    <ul>
                        <li><a href="mailto:<?= htmlspecialchars(MAIL_FROM) ?>"><?= htmlspecialchars(MAIL_FROM) ?></a></li>
                        <li><a href="mailto:<?= htmlspecialchars(MAIL_FROM) ?>?subject=PeerConnect%20support">Report a problem</a></li>
                    </ul>
                </div>
            </div>

            <div class="lp-footer-bottom">
                <span>&copy; <?= date('Y') ?> PeerConnect. All rights reserved.</span>
                <span>Free forever — no hidden fees.</span>
            </div>
        </div>
    </footer>

    <script>
        (function() {
            // Shadow under the nav only once the page has actually scrolled.
            const nav = document.getElementById('lpNav');
            const onScroll = () => nav.classList.toggle('stuck', window.scrollY > 8);
            onScroll();
            window.addEventListener('scroll', onScroll, {
                passive: true
            });

            // Mobile menu.
            const burger = document.getElementById('lpBurger');
            const links = document.getElementById('lpNavLinks');
            burger.addEventListener('click', () => {
                const open = links.classList.toggle('open');
                burger.setAttribute('aria-expanded', open);
            });
            links.addEventListener('click', e => {
                if (e.target.closest('a')) {
                    links.classList.remove('open');
                    burger.setAttribute('aria-expanded', 'false');
                }
            });

            // Fade sections in as they arrive.
            //
            // Deliberately not an IntersectionObserver: the nav jumps straight
            // to #how or #contact, and anything the jump skips over never
            // intersects, so it would sit at opacity 0 for good. Testing
            // "is this element's top above the bottom of the viewport" covers
            // both cases — arriving from below, and already scrolled past.
            let pending = false;
            let items = Array.from(document.querySelectorAll('.reveal'));
            items.forEach((el, i) => el.style.transitionDelay = (i % 4) * 70 + 'ms');

            function sweep() {
                pending = false;
                // Some embedded/preview browsers report innerHeight as 0. Left
                // unguarded that makes the limit 0, nothing ever qualifies, and
                // the whole page stays invisible — fail open instead.
                const vh = window.innerHeight || document.documentElement.clientHeight || 0;
                if (vh <= 0) {
                    items.forEach(el => el.classList.add('visible'));
                    items = [];
                    return;
                }
                const limit = vh * 0.92;
                items = items.filter(el => {
                    if (el.getBoundingClientRect().top >= limit) return true;
                    el.classList.add('visible');
                    return false;
                });
                if (!items.length) {
                    window.removeEventListener('scroll', queue);
                    window.removeEventListener('resize', queue);
                }
            }

            function queue() {
                if (pending) return;
                pending = true;
                requestAnimationFrame(sweep);
            }

            window.addEventListener('scroll', queue, {
                passive: true
            });
            window.addEventListener('resize', queue);
            sweep();

            // Underline whichever section is on screen.
            /*
             * In-page anchors only.
             *
             * The mobile "Log In" link carries .lp-navlink as well, and its
             * href is a route path — document.querySelector('/case/case/3ce…')
             * is not a valid selector, so it threw SyntaxError and took the
             * rest of this block with it, observer included. That is why the
             * bar went on saying "Home" however far down the page you read:
             * the highlighting was written, but never reached.
             */
            const navLinks = Array.from(document.querySelectorAll('.lp-navlink[href^="#"]'));
            const sections = navLinks
                .map(a => document.querySelector(a.getAttribute('href')))
                .filter(Boolean);
            if (sections.length && 'IntersectionObserver' in window) {
                const spy = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (!entry.isIntersecting) return;
                        navLinks.forEach(a => a.classList.toggle(
                            'active', a.getAttribute('href') === '#' + entry.target.id
                        ));
                    });
                }, {
                    rootMargin: '-45% 0px -50% 0px'
                });
                sections.forEach(sec => spy.observe(sec));
            }
        })();
    </script>

    <?php require __DIR__ . '/includes/page_transition.php'; ?>
</body>

</html>