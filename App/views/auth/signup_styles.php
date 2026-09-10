<?php

/**
 * signup_styles.php — the bits unique to the signup page.
 * Everything shared with login lives in auth_styles.php.
 */
?>
<style>
    /* Form on the left, story on the right — the mirror of the login page. */
    .su-split .auth-panel {
        order: 1;
        align-items: center;
        padding: 6px 3.5%;
    }

    .su-split .su-aside {
        order: 2;
    }

    .su-card {
        max-width: 640px;
    }

    /* Signup fills the column, so its header row sits tighter than login's. */
    .su-split .auth-top {
        margin-bottom: 6px;
    }

    .auth-h1 .su-em {
        font-style: normal;
        color: var(--mint-deep);
    }

    /* ── Fields ── */
    .su-two {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }

    /* First / Middle / Last, matching the three columns in `users`. */
    .su-names {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 14px;
    }

    .su-opt {
        font-weight: 500;
        color: var(--gray-400);
    }

    .su-nomargin {
        margin-bottom: 0;
    }

    .su-pwmeta {
        margin: 4px 0 8px;
    }

    .auth-input:not(:has(.auth-input-ico)) input {
        padding-left: 14px;
    }

    .su-tick {
        position: absolute;
        right: 13px;
        width: 19px;
        height: 19px;
        color: var(--success);
        opacity: 0;
        transform: scale(.7);
        transition: opacity .15s, transform .15s;
        pointer-events: none;
    }

    .auth-input.is-valid .su-tick {
        opacity: 1;
        transform: scale(1);
    }

    .su-hint {
        font-size: 12px;
        color: var(--gray-400);
        margin: 6px 0 0;
    }

    .su-err {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 12.5px;
        font-weight: 500;
        color: var(--danger);
        margin: 7px 0 0;
    }

    .su-err[hidden] {
        display: none;
    }

    .su-err svg {
        width: 15px;
        height: 15px;
        flex: 0 0 auto;
    }

    /* ── Password strength ── */
    .su-meter {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 5px;
        margin: 0 0 5px;
    }

    .su-meter span {
        height: 5px;
        border-radius: 3px;
        background: var(--gray-200);
        transition: background .2s;
    }

    .su-meter span.on {
        background: var(--warning);
    }

    .su-meter span.full {
        background: var(--success);
    }

    .su-strength {
        text-align: right;
        font-size: 12px;
        font-weight: 700;
        margin: 0 0 6px;
        min-height: 14px;
    }

    .su-strength.is-strong {
        color: var(--success);
    }

    .su-strength.is-mid {
        color: var(--warning);
    }

    .su-strength.is-weak {
        color: var(--danger);
    }

    .su-rules {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-wrap: wrap;
        gap: 5px 14px;
    }

    .su-rules li {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 11.8px;
        color: var(--gray-500);
    }

    .su-rule-i {
        width: 15px;
        height: 15px;
        flex: 0 0 15px;
        border-radius: 50%;
        border: 1.6px solid var(--gray-300);
        position: relative;
        transition: border-color .15s, background .15s;
    }

    .su-rules li.ok {
        color: var(--success);
    }

    .su-rules li.ok .su-rule-i {
        background: var(--success);
        border-color: var(--success);
    }

    /* Tick drawn in CSS so the list needs no inline SVG per rule. */
    .su-rules li.ok .su-rule-i::after {
        content: "";
        position: absolute;
        left: 4.4px;
        top: 1.6px;
        width: 4px;
        height: 8px;
        border: solid #fff;
        border-width: 0 2px 2px 0;
        transform: rotate(45deg);
    }

    /* ── Role cards ── */
    .su-roles {
        border: 0;
        margin: 2px 0 11px;
        padding: 0;
    }

    .su-roles legend {
        font-size: 14px;
        font-weight: 700;
        color: var(--forest);
        padding: 0;
        margin-bottom: 9px;
    }

    .su-role-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 13px;
    }

    .su-role {
        position: relative;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 12px 38px 12px 13px;
        border: 1.6px solid var(--gray-200);
        border-radius: var(--radius-lg);
        cursor: pointer;
        background: var(--surface);
        transition: border-color .15s, background .15s, box-shadow .15s;
    }

    .su-role:hover {
        border-color: var(--mint-soft);
    }

    .su-role input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .su-role:has(input:checked) {
        border-color: var(--mint-deep);
        background: var(--mint-faint);
        box-shadow: 0 0 0 3px rgba(0, 83, 155, .10);
    }

    .su-role:has(input:focus-visible) {
        outline: 2px solid var(--mint-deep);
        outline-offset: 2px;
    }

    .su-role-ico {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        border-radius: 50%;
        display: grid;
        place-items: center;
    }

    .su-role-ico svg {
        width: 18px;
        height: 18px;
    }

    .su-role-txt {
        display: flex;
        flex-direction: column;
        gap: 3px;
        min-width: 0;
    }

    .su-role-t {
        font-size: 13.5px;
        font-weight: 800;
        letter-spacing: .3px;
        color: var(--forest);
    }

    .su-role-d {
        font-size: 12.2px;
        line-height: 1.5;
        color: var(--gray-500);
    }

    .su-role-dot {
        position: absolute;
        top: 17px;
        right: 15px;
        width: 19px;
        height: 19px;
        border-radius: 50%;
        border: 2px solid var(--gray-300);
        transition: border-color .15s;
    }

    .su-role:has(input:checked) .su-role-dot {
        border-color: var(--mint-deep);
        background:
            radial-gradient(circle at center, var(--mint-deep) 0 4.5px, transparent 4.6px);
    }

    /* ── Terms ── */
    .su-terms {
        align-items: flex-start;
        gap: 10px;
        margin-bottom: 10px;
        font-size: 13px;
        line-height: 1.55;
    }

    .su-terms input {
        margin-top: 2px;
    }

    .su-google-hint {
        text-align: center;
        font-size: 12px;
        color: var(--gray-400);
        margin: 3px 0 0;
    }

    .su-google-hint[hidden] {
        display: none;
    }

    .su-google-hint.is-warn {
        color: var(--danger);
        font-weight: 600;
    }

    /* ── Story panel extras ── */
    .su-aside-p {
        font-size: 15.5px;
        font-weight: 600;
        color: var(--gray-500);
    }

    .su-sell {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 14px 14px;
        margin-top: 14px;
        box-shadow: var(--shadow-xs);
    }

    .su-sell-item h3 {
        font-size: 13.5px;
        font-weight: 700;
        color: var(--forest);
        margin: 11px 0 6px;
    }

    .su-sell-item p {
        font-size: 11.6px;
        line-height: 1.6;
        color: var(--gray-500);
        margin: 0;
    }

    .su-sell-ico {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: grid;
        place-items: center;
    }

    .su-sell-ico svg {
        width: 20px;
        height: 20px;
    }

    .su-quote {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        margin: 12px 0 0;
        font-size: 13px;
        line-height: 1.65;
        color: var(--gray-600);
    }

    .su-quote strong {
        color: var(--mint-deep);
    }

    .su-quote-mark {
        font-family: Georgia, serif;
        font-size: 34px;
        line-height: .9;
        color: var(--mint-deep);
        flex: 0 0 auto;
    }

    /* ── Short screens ───────────────────────────────────────────────
       A 1366x768 laptop is ~70px short of the full form. Rather than make
       the column scroll, tighten the rhythm just for those viewports; the
       spacing above is unchanged on a normal desktop. Nothing is hidden. */
    @media (min-width: 981px) and (max-height: 840px) {
        .su-split .auth-panel {
            padding: 4px 3.5%;
        }

        .su-split .auth-top {
            margin-bottom: 2px;
        }

        .su-split .auth-back {
            width: 32px;
            height: 32px;
            flex: 0 0 32px;
        }

        /* The mark still reads at this size; nothing is removed. */
        .su-split .auth-brand-svg {
            width: 32px;
            height: 32px;
        }

        .su-split .auth-brand-name {
            font-size: 17px;
        }

        .su-split .auth-brand-tag {
            font-size: 9.5px;
        }

        .su-rules li {
            font-size: 11px;
        }

        .su-split .auth-switch,
        .su-google-hint {
            font-size: 12.5px;
        }

        .su-split .auth-h1 {
            font-size: 21px;
            margin-bottom: 3px;
        }

        .su-split .auth-sub {
            font-size: 13px;
            margin-bottom: 9px;
        }

        .su-split .auth-field {
            margin-bottom: 7px;
        }

        .su-split .auth-field label {
            margin-bottom: 4px;
        }

        .su-split .auth-input input {
            padding-top: 10px;
            padding-bottom: 10px;
        }

        .su-names,
        .su-two {
            gap: 8px;
        }

        .su-pwmeta {
            margin: 2px 0 4px;
        }

        .su-roles {
            margin: 0 0 8px;
        }

        .su-roles legend {
            margin-bottom: 6px;
        }

        .su-role {
            padding: 7px 34px 7px 11px;
        }

        .su-role-ico {
            width: 30px;
            height: 30px;
            flex: 0 0 30px;
        }

        .su-role-dot {
            top: 13px;
        }

        .su-terms {
            margin-bottom: 5px;
        }

        .su-split .auth-captcha {
            margin-bottom: 5px;
        }

        .su-split .auth-submit {
            padding: 11px 20px;
        }

        .su-split .auth-or {
            margin: 5px 0;
        }

        .su-split .auth-google {
            padding: 9px 18px;
        }

        .su-split .auth-switch {
            margin-top: 6px;
        }
    }

    /* ── Responsive ── */
    @media (max-width: 1200px) {
        .su-sell {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 980px) {

        /* Below the split there is only the form. The story panel is scene
           setting, and on a phone it just pushed the first field off screen —
           same rule the login page already follows. */
        .su-split .su-aside {
            display: none;
        }

        .su-split .auth-panel {
            padding: 32px 24px 44px;
        }

        .su-card {
            max-width: 620px;
            margin: 0 auto;
        }
    }

    @media (max-width: 760px) {
        .su-names {
            grid-template-columns: minmax(0, 1fr);
        }
    }

    @media (max-width: 620px) {
        .su-two,
        .su-role-grid {
            grid-template-columns: minmax(0, 1fr);
        }

        .su-rules {
            gap: 5px 14px;
        }
    }

</style>
