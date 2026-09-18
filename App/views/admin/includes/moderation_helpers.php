<?php

/**
 * moderation_helpers.php - functions for rendering verification documents.
 *
 * Separate from moderation_ui.php because a page needs these while it is
 * building its body, but moderation_ui.php emits markup and so is included at
 * the very bottom. Both admin pages that show documents require this at the
 * top.
 */

if (!function_exists('um_doc_url')) {
    /**
     * The verification tables store only the file's name. The documents are
     * private, so a name becomes the verification-file route, which checks the
     * viewer is an admin (see VerificationFiles). Rows written by some other
     * path may already hold a full path or URL, so anything already absolute
     * is left alone.
     */
    function um_doc_url(?string $file): string
    {
        $file = trim((string)$file);
        if ($file === '') return '';
        if (preg_match('#^(https?:)?//#i', $file) || $file[0] === '/') return $file;
        return VerificationFiles::url($file);
    }
}

if (!function_exists('um_doc_tile')) {
    /**
     * One uploaded document, as a thumbnail that opens full size. An admin has
     * to actually look at an ID to decide on it, so the tile shows the image
     * rather than a link that promises one.
     */
    function um_doc_tile(?string $file, string $label, string $who): void
    {
        if (trim((string)$file) === '') return;
        $doc = um_doc_url($file);
        $cap = $label . ' — ' . $who;
        ?>
        <button type="button" class="um-vdoc"
            onclick="umDoc(<?= htmlspecialchars(json_encode($doc), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($cap), ENT_QUOTES) ?>)">
            <span class="um-vdoc-img">
                <?php /* The button reference has to be taken before the swap: replacing the
                        parent's innerHTML detaches this <img>, and closest() on a detached
                        node returns null. */ ?>
                <img src="<?= htmlspecialchars($doc) ?>" alt="<?= htmlspecialchars($cap) ?>" loading="lazy"
                    onerror="var b=this.closest('.um-vdoc');this.parentNode.innerHTML='&lt;span class=&quot;um-vdoc-missing&quot;&gt;File is missing from the server&lt;/span&gt;';if(b){b.disabled=true;b.style.cursor='default';}">
            </span>
            <span class="um-vdoc-cap">
                <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2.5" /><circle cx="9" cy="10" r="2" /><path stroke-linecap="round" d="m4 17 5-4 4 3 3-2 4 3" /></svg>
                <?= htmlspecialchars($label) ?>
            </span>
        </button>
        <?php
    }
}
