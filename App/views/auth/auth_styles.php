<?php

/**
 * auth_styles.php — styling shared by the login and signup pages.
 * Kept in one file so the two screens cannot drift apart; every colour is a
 * design-system token, so both match the signed-in UI.
 */
?>
<style>
    .auth-body {
        background: var(--surface);
        min-height: 100vh;
    }

    .auth-split {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        /* The whole screen, once. Each panel scrolls on its own only if the
           window is genuinely too short for its content, so nothing is ever
           unreachable — but at normal sizes neither of them scrolls. */
        height: 100vh;
        overflow: hidden;
    }

    /* ── Story panel ── */
    .auth-aside {
        position: relative;
        overflow-y: auto;
        background: linear-gradient(160deg, var(--mint-faint) 0%, var(--gray-50) 60%, var(--surface) 100%);
        display: flex;
        align-items: center;
        padding: 20px 4%;
    }

    .auth-aside-inner {
        position: relative;
        z-index: 2;
        max-width: 520px;
        margin: 0 auto;
    }

    .auth-aside-h {
        font-size: clamp(24px, 2.5vw, 34px);
        line-height: 1.18;
        font-weight: 800;
        letter-spacing: -1px;
        color: var(--forest);
        margin: 0 0 12px;
    }

    .auth-aside-h em {
        font-style: normal;
        color: var(--mint-deep);
    }

    .auth-aside-p {
        font-size: 15px;
        line-height: 1.7;
        color: var(--gray-600);
        margin: 0 0 20px;
        max-width: 420px;
    }

    .auth-art {
        position: relative;
        margin: 4px auto 0;
        width: 100%;
        /* The artwork is 3:2, so capping the width in viewport-height units
           caps its height too — that keeps the whole column on one screen on
           a short window without letting it shrink on a tall one. */
        max-width: min(100%, 44vh);
    }

    .auth-aside-art {
        width: 100%;
        height: auto;
        display: block;
    }

    /* ── Floating cards, the same ones the landing page uses ── */
    .auth-float {
        position: absolute;
        z-index: 3;
        display: flex;
        align-items: center;
        gap: 10px;
        background: var(--surface);
        border-radius: 13px;
        padding: 10px 14px;
        box-shadow: 0 10px 28px rgba(16, 24, 40, .13);
        max-width: 205px;
        animation: auth-drift 6.5s ease-in-out infinite;
        will-change: transform;
    }

    .auth-float-ico {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        flex: 0 0 auto;
        display: grid;
        place-items: center;
    }

    .auth-float-ico svg {
        width: 18px;
        height: 18px;
    }

    .auth-float-t {
        display: block;
        font-size: 12.5px;
        font-weight: 700;
        color: var(--forest);
        line-height: 1.3;
    }

    .auth-float-s {
        display: block;
        font-size: 11px;
        color: var(--gray-500);
        line-height: 1.35;
        margin-top: 1px;
    }

    .auth-float-1 {
        top: -4%;
        left: -3%;
    }

    .auth-float-2 {
        top: 34%;
        right: -5%;
        animation-name: auth-drift-alt;
        animation-duration: 8.2s;
        animation-delay: -3.1s;
    }

    .auth-float-3 {
        bottom: 2%;
        left: -6%;
        animation-duration: 7.4s;
        animation-delay: -1.6s;
    }

    /* Two rhythms, out of phase, so the cards never pulse in unison. */
    @keyframes auth-drift {

        0%,
        100% {
            transform: translate3d(0, 0, 0) rotate(0deg);
        }

        50% {
            transform: translate3d(4px, -11px, 0) rotate(-.7deg);
        }
    }

    @keyframes auth-drift-alt {

        0%,
        100% {
            transform: translate3d(0, 0, 0) rotate(0deg);
        }

        50% {
            transform: translate3d(-5px, -9px, 0) rotate(.8deg);
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .auth-float {
            animation: none;
        }
    }

    /* Blobs sit deliberately outside the panel's edges. Given their own
       clipped layer they can do that without adding to the panel's scroll
       height, which was pushing the column past one screen. */
    .auth-deco {
        position: absolute;
        inset: 0;
        overflow: hidden;
        pointer-events: none;
        z-index: 0;
    }

    .auth-blob {
        position: absolute;
        z-index: 0;
    }

    .auth-blob-1 {
        width: 320px;
        height: 300px;
        background: var(--mint-soft);
        opacity: .35;
        top: -60px;
        left: -70px;
        border-radius: 60% 40% 55% 45% / 55% 50% 50% 45%;
    }

    .auth-blob-2 {
        width: 260px;
        height: 240px;
        background: var(--gold-light);
        bottom: -60px;
        right: -50px;
        border-radius: 45% 55% 40% 60% / 50% 45% 55% 50%;
    }

    .auth-dots {
        position: absolute;
        z-index: 1;
        width: 84px;
        height: 64px;
        background-image: radial-gradient(var(--mint-soft) 2px, transparent 2px);
        background-size: 14px 14px;
    }

    .auth-dots-1 {
        top: 12%;
        right: 12%;
    }

    .auth-dots-2 {
        bottom: 14%;
        left: 6%;
    }

    /* ── Form panel ── */
    .auth-panel {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px 5%;
        overflow-y: auto;
    }

    .auth-card {
        width: 100%;
        max-width: 460px;
    }

    /* Back control and logo share one row, so the button costs the column no
       extra height — the signup form is sized to fit a single screen. */
    .auth-top {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 14px;
    }

    .auth-back {
        width: 38px;
        height: 38px;
        flex: 0 0 38px;
        display: grid;
        place-items: center;
        border-radius: 50%;
        border: 1.5px solid var(--gray-200);
        background: var(--surface);
        color: var(--forest);
        text-decoration: none;
        transition: border-color .15s, background .15s, transform .12s;
    }

    .auth-back svg {
        width: 19px;
        height: 19px;
    }

    .auth-back:hover {
        border-color: var(--mint-deep);
        color: var(--mint-deep);
        background: var(--mint-faint);
        transform: translateX(-2px);
    }

    .auth-back:focus-visible {
        outline: 2px solid var(--mint-deep);
        outline-offset: 2px;
    }

    .auth-brand {
        display: inline-flex;
        align-items: center;
        gap: 11px;
        text-decoration: none;
        color: var(--forest);
        margin-bottom: 0;
    }

    .auth-brand-svg {
        width: 42px;
        height: 42px;
        color: var(--mint-deep);
        flex: 0 0 auto;
    }

    .auth-brand-txt {
        display: flex;
        flex-direction: column;
        line-height: 1.08;
    }

    .auth-brand-name {
        font-size: 20px;
        font-weight: 800;
        letter-spacing: .2px;
    }

    .auth-brand-name em {
        font-style: normal;
        color: var(--mint-deep);
    }

    .auth-brand-tag {
        font-size: 10.5px;
        color: var(--gray-500);
    }

    .auth-h1 {
        font-size: 25px;
        font-weight: 800;
        letter-spacing: -.7px;
        color: var(--forest);
        margin: 0 0 6px;
    }

    .auth-sub {
        font-size: 14px;
        color: var(--gray-500);
        margin: 0 0 14px;
    }

    .auth-alert {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        background: var(--danger-bg);
        color: var(--danger);
        border: 1px solid rgba(192, 57, 43, .25);
        border-radius: var(--radius);
        padding: 13px 15px;
        font-size: 14px;
        line-height: 1.5;
        margin-bottom: 20px;
    }

    .auth-alert svg {
        width: 19px;
        height: 19px;
        flex: 0 0 auto;
        margin-top: 1px;
    }

    .auth-ok {
        background: var(--success-bg);
        color: var(--success);
        border-color: rgba(31, 122, 92, .25);
    }

    .auth-field {
        margin-bottom: 14px;
    }

    .auth-field label {
        display: block;
        font-size: 13.5px;
        font-weight: 700;
        color: var(--forest);
        margin-bottom: 7px;
    }

    .auth-input {
        position: relative;
        display: flex;
        align-items: center;
    }

    .auth-input-ico {
        position: absolute;
        left: 14px;
        width: 19px;
        height: 19px;
        color: var(--gray-400);
        pointer-events: none;
    }

    .auth-input input {
        width: 100%;
        font: inherit;
        font-size: 14.5px;
        color: var(--gray-900);
        background: var(--surface);
        border: 1.5px solid var(--gray-200);
        border-radius: var(--radius);
        padding: 12px 14px 12px 42px;
        transition: border-color .15s, box-shadow .15s;
    }

    .auth-input input::placeholder {
        color: var(--gray-400);
    }

    .auth-input input:focus {
        outline: none;
        border-color: var(--mint-deep);
        box-shadow: 0 0 0 3px rgba(0, 83, 155, .12);
    }

    .auth-input:has(.auth-eye) input {
        padding-right: 46px;
    }

    .auth-eye {
        position: absolute;
        right: 8px;
        width: 32px;
        height: 32px;
        display: grid;
        place-items: center;
        border: 0;
        background: transparent;
        color: var(--gray-400);
        cursor: pointer;
        border-radius: 8px;
    }

    .auth-eye svg {
        width: 19px;
        height: 19px;
    }

    .auth-eye:hover,
    .auth-eye.is-on {
        color: var(--mint-deep);
    }

    .auth-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        margin: 4px 0 20px;
    }

    .auth-check {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        font-size: 14px;
        color: var(--gray-600);
        cursor: pointer;
    }

    .auth-check input {
        width: 17px;
        height: 17px;
        accent-color: var(--mint-deep);
        cursor: pointer;
        flex: 0 0 auto;
    }

    /* Sits opposite "Remember me" in .auth-row, which is already
       justify-content: space-between. */
    .auth-forgot {
        font-size: 13.5px;
        font-weight: 600;
        color: var(--mint-deep);
        text-decoration: none;
    }

    .auth-forgot:hover {
        text-decoration: underline;
    }

    .auth-forgot:focus-visible {
        outline: 2px solid var(--mint-deep);
        outline-offset: 3px;
        border-radius: 4px;
    }

    .auth-captcha {
        margin-bottom: 8px;
        /* The widget is a fixed 304px; let it shrink rather than overflow. */
        transform-origin: 0 0;
        overflow: hidden;
    }

    .auth-submit {
        width: 100%;
        font: inherit;
        font-size: 15.5px;
        font-weight: 600;
        color: #fff;
        background: var(--mint-deep);
        border: 0;
        border-radius: var(--radius);
        padding: 13px 20px;
        cursor: pointer;
        transition: background .15s, transform .12s, box-shadow .15s;
    }

    .auth-submit:hover {
        background: var(--forest);
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(0, 83, 155, .26);
    }

    .auth-submit:disabled {
        background: var(--gray-300);
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    /* Same shape as the submit button, but it is a link — the confirmation and
       expired-link screens have nothing to submit. */
    .auth-submit-link {
        display: block;
        text-align: center;
        text-decoration: none;
        box-sizing: border-box;
    }

    /* A second action under the main one, on screens that offer two — "send
       the link again" beneath "I have confirmed it". Same shape, quieter, so
       the primary choice stays the obvious one. */
    .auth-submit-ghost {
        color: var(--mint-deep);
        background: transparent;
        border: 1.5px solid var(--gray-300);
    }

    .auth-submit-ghost:hover {
        color: #fff;
        background: var(--mint-deep);
        border-color: var(--mint-deep);
    }

    /* The round mark above "Check your email". */
    .auth-icon-badge {
        display: grid;
        place-items: center;
        width: 54px;
        height: 54px;
        border-radius: 50%;
        background: var(--mint-faint);
        color: var(--mint-deep);
        margin-bottom: 16px;
    }

    .auth-icon-badge svg {
        width: 27px;
        height: 27px;
    }

    .auth-or {
        position: relative;
        text-align: center;
        margin: 10px 0;
    }

    .auth-or::before {
        content: "";
        position: absolute;
        top: 50%;
        left: 0;
        right: 0;
        border-top: 1px solid var(--gray-200);
    }

    .auth-or span {
        position: relative;
        background: var(--surface);
        padding: 0 14px;
        font-size: 12.5px;
        font-weight: 600;
        color: var(--gray-400);
        letter-spacing: .6px;
    }

    .auth-google {
        width: 100%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 11px;
        font: inherit;
        font-size: 14.5px;
        font-weight: 600;
        color: var(--gray-800);
        background: var(--surface);
        border: 1.5px solid var(--gray-200);
        border-radius: var(--radius);
        padding: 13px 18px;
        cursor: pointer;
        transition: border-color .15s, background .15s;
    }

    .auth-google:hover {
        border-color: var(--gray-300);
        background: var(--gray-50);
    }

    .auth-google svg {
        width: 19px;
        height: 19px;
    }

    .auth-switch {
        text-align: center;
        font-size: 13.5px;
        color: var(--gray-500);
        margin: 8px 0 0;
    }

    .auth-switch a {
        color: var(--mint-deep);
        font-weight: 700;
        text-decoration: none;
    }

    .auth-switch a:hover {
        text-decoration: underline;
    }

    .auth-legal {
        text-align: center;
        font-size: 12.5px;
        line-height: 1.6;
        color: var(--gray-400);
        margin: 12px 0 0;
    }

    .auth-link {
        border: 0;
        background: none;
        padding: 0;
        font: inherit;
        font-size: inherit;
        color: var(--mint-deep);
        text-decoration: underline;
        cursor: pointer;
    }

    /* ── Legal reader ── */
    .legal-overlay {
        position: fixed;
        inset: 0;
        z-index: 200;
        background: rgba(2, 5, 71, .45);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
    }

    .legal-overlay[hidden] {
        display: none;
    }

    .legal-card {
        background: var(--surface);
        border-radius: var(--radius-lg);
        width: 100%;
        max-width: 680px;
        max-height: 86vh;
        display: flex;
        flex-direction: column;
        box-shadow: var(--shadow-lg);
    }

    .legal-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 20px 24px 14px;
    }

    .legal-head h2 {
        font-size: 19px;
        font-weight: 800;
        color: var(--forest);
        margin: 0;
    }

    .legal-close {
        border: 0;
        background: none;
        font-size: 28px;
        line-height: 1;
        color: var(--gray-400);
        cursor: pointer;
        padding: 0 4px;
    }

    .legal-close:hover {
        color: var(--forest);
    }

    .legal-tabs {
        display: flex;
        gap: 4px;
        padding: 0 24px;
        border-bottom: 1px solid var(--gray-200);
    }

    .legal-tab {
        border: 0;
        background: none;
        font: inherit;
        font-size: 14px;
        font-weight: 600;
        color: var(--gray-500);
        padding: 10px 4px 12px;
        margin-right: 18px;
        cursor: pointer;
        border-bottom: 2px solid transparent;
    }

    .legal-tab.active {
        color: var(--mint-deep);
        border-bottom-color: var(--mint-deep);
    }

    .legal-body {
        overflow-y: auto;
        padding: 20px 24px 26px;
    }

    .legal-body h3 {
        font-size: 15px;
        font-weight: 700;
        color: var(--forest);
        margin: 22px 0 8px;
    }

    .legal-body h3:first-child {
        margin-top: 0;
    }

    .legal-body p {
        font-size: 14px;
        line-height: 1.75;
        color: var(--gray-600);
        margin: 0 0 12px;
    }

    .legal-eff {
        font-size: 12.5px !important;
        color: var(--gray-400) !important;
        margin-bottom: 18px !important;
    }

    .legal-empty {
        background: var(--gray-50);
        border: 1px dashed var(--gray-200);
        border-radius: var(--radius);
        padding: 22px;
    }

    .legal-empty p:last-child {
        margin-bottom: 0;
    }

    .legal-empty a {
        color: var(--mint-deep);
    }

    /* ── Responsive ── */
    @media (max-width: 980px) {

        /* The story panel is decoration; the form is the page. */
        .auth-split {
            grid-template-columns: minmax(0, 1fr);
            height: auto;
            overflow: visible;
        }

        .auth-panel,
        .auth-aside {
            overflow: visible;
        }

        /* Overlapping cards on a narrow column just collide with the artwork. */
        .auth-float {
            display: none;
        }

        .auth-aside {
            display: none;
        }

        .auth-panel {
            padding: 40px 24px;
            align-items: flex-start;
        }
    }

    @media (max-width: 460px) {
        .auth-panel {
            padding: 28px 18px;
        }

        .auth-h1 {
            font-size: 25px;
        }

        /* reCAPTCHA renders at a fixed 304px — scale it to fit small screens. */
        .auth-captcha {
            transform: scale(.88);
            margin-bottom: 6px;
        }
    }
</style>
