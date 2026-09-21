<?php

/**
 * moderation_ui.php — the parts of User Management that more than one admin
 * page needs: the block / restrict / reject dialogs, the uploaded-document
 * tiles and their full-size viewer, and the button styling all three share.
 *
 * The user list and the single-user view both moderate accounts and both show
 * verification documents. Keeping one copy here means the restriction wording
 * an admin reads in the dialog cannot drift between the two pages, which is
 * the kind of difference nobody notices until the two disagree about what
 * "restricted" does.
 *
 * Requires from the including page: $csrf. Optional: $um_return
 * ('notifications') sends Block and Restrict back to that page afterwards.
 * Provides to it: umOpen(), umClose(), umBlock(), umRestrict(), umReject(),
 * umDoc(), and um_doc_tile() for rendering a document thumbnail.
 *
 * Emits its markup once per request, so a page may include it defensively.
 */

require_once __DIR__ . '/moderation_helpers.php';

// Everything below is markup, so it may only be emitted once per page.
static $um_ui_done = false;
if ($um_ui_done) return;
$um_ui_done = true;
?>

<style>
    /* ── Uploaded documents ─────────────────────────────────────────────── */
    .um-vdocs { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
    .um-vdoc {
        display: block; width: 168px; padding: 0; border: 1px solid var(--gray-200);
        border-radius: 12px; overflow: hidden; background: #fff; text-align: left;
        font: inherit; color: var(--gray-700); text-decoration: none; cursor: zoom-in;
        transition: border-color .15s ease, box-shadow .15s ease;
    }
    .um-vdoc:hover { border-color: var(--mint); box-shadow: 0 4px 14px rgba(16, 24, 40, .08); }
    .um-vdoc:focus-visible { outline: 2px solid var(--mint); outline-offset: 2px; }
    .um-vdoc-img { position: relative; display: block; height: 104px; background: var(--gray-100, #F3F4F6); }
    .um-vdoc-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .um-vdoc-cap { display: flex; align-items: center; gap: 7px; padding: 8px 11px; font-size: 12.5px; font-weight: 600; }
    .um-vdoc-cap svg { width: 14px; height: 14px; flex: none; color: var(--gray-400); }
    /* The row still exists if the file is gone from disk; say so instead of
       showing a silently broken image. */
    .um-vdoc-missing {
        display: flex; align-items: center; justify-content: center; height: 104px;
        padding: 0 12px; font-size: 12px; color: var(--gray-400); text-align: center;
        background: var(--gray-100, #F3F4F6);
    }

    /* Full-size viewer, for reading the small print on an ID card. */
    .um-lb[hidden] { display: none; }
    .um-lb {
        position: fixed; inset: 0; z-index: 950; display: flex; flex-direction: column;
        align-items: center; justify-content: center; gap: 14px; padding: 26px;
        background: rgba(9, 22, 38, .82);
    }
    .um-lb img { max-width: min(1100px, 92vw); max-height: 76vh; border-radius: 12px; background: #fff; }
    .um-lb-bar { display: flex; align-items: center; gap: 10px; color: #fff; font-size: 13.5px; }
    .um-lb-bar b { font-weight: 600; }
    .um-lb-bar a, .um-lb-bar button {
        font: inherit; color: #fff; background: rgba(255, 255, 255, .16); border: 0;
        border-radius: 8px; padding: 7px 13px; cursor: pointer; text-decoration: none;
    }
    .um-lb-bar a:hover, .um-lb-bar button:hover { background: rgba(255, 255, 255, .28); }

    /* ── Shared buttons ─────────────────────────────────────────────────── */
    .um-btn { padding: 9px 18px; border-radius: 10px; border: none; font-family: inherit; font-size: 13.5px; font-weight: 600; cursor: pointer; }
    .um-ok { background: #17654B; color: #fff; }
    .um-ok:hover { background: #125A41; }
    .um-no { background: #fff; border: 1px solid #F3C9C0; color: #A6301F; }
    .um-no:hover { background: #FBE5E1; }

    /* ── Dialogs ────────────────────────────────────────────────────────── */
    .um-overlay { display: none; position: fixed; inset: 0; z-index: 900; background: rgba(2,5,71,.42); align-items: center; justify-content: center; padding: 20px; }
    .um-overlay.open { display: flex; }
    .um-modal { width: min(460px, 100%); background: #fff; border-radius: 16px; padding: 22px 24px; box-shadow: 0 24px 60px -20px rgba(16,24,40,.4); }
    /* The same icon badge the shared confirmation dialog uses
       (includes/dialog.php), so approving and rejecting an application look
       like two halves of one decision rather than two different screens. */
    .um-modal-ico {
        width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center;
        margin-bottom: 14px; background: var(--um-ico-soft); color: var(--um-ico);
    }
    .um-modal-ico svg { width: 22px; height: 22px; }
    .um-ico-danger  { --um-ico: #A6301F; --um-ico-soft: #FBE5E1; }
    .um-ico-warning { --um-ico: #9A7100; --um-ico-soft: #FBF0D4; }
    .um-modal h3 { margin: 0 0 6px; font-size: 18px; font-weight: 700; color: var(--forest); }
    .um-modal p { margin: 0 0 16px; font-size: 13.5px; color: var(--gray-500); }
    .um-modal label { display: block; font-size: 12.5px; font-weight: 600; color: var(--gray-700); margin-bottom: 5px; }
    .um-modal textarea, .um-modal input, .um-modal select {
        width: 100%; padding: 10px 12px; border: 1px solid var(--gray-200); border-radius: 10px;
        font-family: inherit; font-size: 13.5px; margin-bottom: 14px; outline: none; resize: vertical;
    }
    .um-modal textarea:focus, .um-modal input:focus, .um-modal select:focus { border-color: var(--mint); box-shadow: 0 0 0 3px rgba(0,135,207,.13); }
    .um-modal-foot { display: flex; gap: 10px; justify-content: flex-end; }
    .um-cancel { padding: 9px 18px; border-radius: 10px; border: 1px solid var(--gray-200); background: #fff; color: var(--gray-600); font-family: inherit; font-size: 13.5px; cursor: pointer; }
</style>

<div class="um-overlay" id="umBlockModal">
    <form class="um-modal" method="post" action="<?= url('admin-action-block') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="user_id" id="umBlockUser" value="0">
        <input type="hidden" name="report_id" id="umBlockReport" value="0">
        <?php if (!empty($um_return)): ?><input type="hidden" name="return_to" value="<?= htmlspecialchars($um_return) ?>"><?php endif; ?>
        <div class="um-modal-ico um-ico-danger" aria-hidden="true">
            <svg fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5.6 5.6l12.8 12.8M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
        </div>
        <h3>Block <span id="umBlockName">this account</span>?</h3>
        <p>They will be signed out on their next request and cannot sign in again until unblocked.</p>
        <label for="umBlockReason">Reason (kept on the account record)</label>
        <textarea id="umBlockReason" name="reason" rows="3" required maxlength="<?= ModerationService::REASON_MAX ?>" placeholder="e.g. Repeated no-shows after multiple warnings"></textarea>
        <div class="um-modal-foot">
            <button type="button" class="um-cancel" onclick="umClose()">Cancel</button>
            <button type="submit" class="um-btn um-no" style="border-color:#A6301F;background:#A6301F;color:#fff;">Block account</button>
        </div>
    </form>
</div>

<div class="um-overlay" id="umRestrictModal">
    <form class="um-modal" method="post" action="<?= url('admin-action-restrict') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="user_id" id="umRestrictUser" value="0">
        <input type="hidden" name="report_id" id="umRestrictReport" value="0">
        <?php if (!empty($um_return)): ?><input type="hidden" name="return_to" value="<?= htmlspecialchars($um_return) ?>"><?php endif; ?>
        <div class="um-modal-ico um-ico-warning" aria-hidden="true">
            <svg fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9.5v3.4m0 3h.01M10.3 4.2 2.9 17a2 2 0 0 0 1.7 3h14.8a2 2 0 0 0 1.7-3L13.7 4.2a2 2 0 0 0-3.4 0Z" />
            </svg>
        </div>
        <h3>Restrict <span id="umRestrictName">this account</span></h3>
        <p>They can still sign in and read, but cannot book, message or publish until the restriction expires. It lifts itself once the chosen number of days has passed. If they are already restricted, this replaces the current restriction.</p>
        <label for="umDays">Length</label>
        <select id="umDays" name="days">
            <option value="3">3 days</option>
            <option value="7" selected>7 days</option>
            <option value="14">14 days</option>
            <option value="30">30 days</option>
            <option value="90">90 days</option>
        </select>
        <label for="umRestrictReason">Reason (kept on the account record)</label>
        <textarea id="umRestrictReason" name="reason" rows="3" required maxlength="<?= ModerationService::REASON_MAX ?>" placeholder="e.g. Inappropriate language in session chat"></textarea>
        <div class="um-modal-foot">
            <button type="button" class="um-cancel" onclick="umClose()">Cancel</button>
            <button type="submit" class="um-btn" style="background:#9A7100;color:#fff;">Apply restriction</button>
        </div>
    </form>
</div>

<div class="um-overlay" id="umRejectModal">
    <form class="um-modal" method="post" action="<?= url('admin-action-verify') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="verification_id" id="umRejectId" value="0">
        <input type="hidden" name="action" value="reject">
        <div class="um-modal-ico um-ico-danger" aria-hidden="true">
            <svg fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="9" />
                <path stroke-linecap="round" d="m9.2 9.2 5.6 5.6M14.8 9.2l-5.6 5.6" />
            </svg>
        </div>
        <h3>Reject <span id="umRejectName">this application</span>?</h3>
        <p>They are told the application was not approved, along with your note.</p>
        <label for="umRejectNotes">Reason</label>
        <textarea id="umRejectNotes" name="admin_notes" rows="3" required placeholder="e.g. The ID photo was unreadable — please submit a clearer one."></textarea>
        <div class="um-modal-foot">
            <button type="button" class="um-cancel" onclick="umClose()">Cancel</button>
            <button type="submit" class="um-btn um-no" style="border-color:#A6301F;background:#A6301F;color:#fff;">Reject application</button>
        </div>
    </form>
</div>

<div class="um-lb" id="umLightbox" hidden>
    <img id="umLightboxImg" src="" alt="">
    <div class="um-lb-bar">
        <b id="umLightboxCap"></b>
        <a id="umLightboxOpen" href="#" target="_blank" rel="noopener">Open original</a>
        <button type="button" onclick="umDocClose()">Close</button>
    </div>
</div>

<script>
    function umOpen(id) {
        document.querySelectorAll('.um-overlay.open').forEach(o => o.classList.remove('open'));
        document.getElementById(id).classList.add('open');
    }
    function umClose() {
        document.querySelectorAll('.um-overlay.open').forEach(o => o.classList.remove('open'));
    }
    document.querySelectorAll('.um-overlay').forEach(o => {
        o.addEventListener('click', e => { if (e.target === o) umClose(); });
    });

    function umBlock(userId, reportId, name) {
        document.getElementById('umBlockUser').value = userId;
        document.getElementById('umBlockReport').value = reportId;
        document.getElementById('umBlockName').textContent = name;
        document.getElementById('umBlockReason').value = '';
        umOpen('umBlockModal');
    }
    function umRestrict(userId, reportId, name) {
        document.getElementById('umRestrictUser').value = userId;
        document.getElementById('umRestrictReport').value = reportId;
        document.getElementById('umRestrictName').textContent = name;
        document.getElementById('umRestrictReason').value = '';
        umOpen('umRestrictModal');
    }
    function umReject(verifId, name) {
        document.getElementById('umRejectId').value = verifId;
        document.getElementById('umRejectName').textContent = name;
        document.getElementById('umRejectNotes').value = '';
        umOpen('umRejectModal');
    }

    // Document viewer.
    function umDoc(src, caption) {
        const img = document.getElementById('umLightboxImg');
        img.src = src;
        img.alt = caption;
        document.getElementById('umLightboxCap').textContent = caption;
        document.getElementById('umLightboxOpen').href = src;
        document.getElementById('umLightbox').hidden = false;
    }
    function umDocClose() {
        document.getElementById('umLightbox').hidden = true;
        document.getElementById('umLightboxImg').src = '';
    }
    document.getElementById('umLightbox').addEventListener('click', e => {
        if (e.target.id === 'umLightbox') umDocClose();
    });

    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        umClose();
        umDocClose();
    });
</script>
