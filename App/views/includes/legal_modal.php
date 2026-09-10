<?php

/**
 * legal_modal.php — reads the documents in App/config/legal.php.
 *
 * Include once per page, then open it from anywhere with
 * openLegal('terms') / openLegal('privacy'). Both documents live in one
 * overlay with a tab each, so the signup checkbox's two links land in the
 * same place.
 *
 * Everything is escaped on output: the content file is plain text.
 */

$pc_legal = require __DIR__ . '/../../config/legal.php';
?>
<div id="legalModal" class="legal-overlay" role="dialog" aria-modal="true" aria-labelledby="legalTitle" hidden>
    <div class="legal-card">
        <div class="legal-head">
            <h2 id="legalTitle">Legal</h2>
            <button type="button" class="legal-close" onclick="closeLegal()" aria-label="Close">&times;</button>
        </div>

        <div class="legal-tabs" role="tablist">
            <?php foreach ($pc_legal as $key => $doc): ?>
                <button type="button" class="legal-tab" data-legal-tab="<?= htmlspecialchars($key) ?>"
                    role="tab" aria-selected="false" aria-controls="legal-<?= htmlspecialchars($key) ?>">
                    <?= htmlspecialchars($doc['title']) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="legal-body">
            <?php foreach ($pc_legal as $key => $doc): ?>
                <section id="legal-<?= htmlspecialchars($key) ?>" class="legal-doc" role="tabpanel" hidden>
                    <?php if (!empty($doc['effective'])): ?>
                        <p class="legal-eff">Effective <?= htmlspecialchars(date('F j, Y', strtotime($doc['effective']))) ?></p>
                    <?php endif; ?>

                    <?php if (empty($doc['sections'])): ?>
                        <?php // No invented legalese — say plainly that it isn't written yet. ?>
                        <div class="legal-empty">
                            <p><strong>This document is still being prepared.</strong></p>
                            <p>
                                The <?= htmlspecialchars(strtolower($doc['title'])) ?> for PeerConnect has not been
                                published yet. It will appear here once it has been written and approved — nothing
                                is being hidden from you in the meantime.
                            </p>
                            <p>
                                Questions before then?
                                <a href="mailto:<?= htmlspecialchars(MAIL_FROM) ?>"><?= htmlspecialchars(MAIL_FROM) ?></a>
                            </p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($doc['sections'] as $sec): ?>
                            <?php if (!empty($sec['heading'])): ?>
                                <h3><?= htmlspecialchars($sec['heading']) ?></h3>
                            <?php endif; ?>
                            <?php foreach ((array)($sec['body'] ?? []) as $para): ?>
                                <p><?= nl2br(htmlspecialchars($para)) ?></p>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
    (function() {
        const modal = document.getElementById('legalModal');
        const tabs = modal.querySelectorAll('[data-legal-tab]');
        const docs = modal.querySelectorAll('.legal-doc');
        const title = document.getElementById('legalTitle');
        let lastFocused = null;

        window.openLegal = function(which) {
            which = which || tabs[0].dataset.legalTab;
            lastFocused = document.activeElement;
            tabs.forEach(t => {
                const on = t.dataset.legalTab === which;
                t.classList.toggle('active', on);
                t.setAttribute('aria-selected', on);
                if (on) title.textContent = t.textContent.trim();
            });
            docs.forEach(d => d.hidden = d.id !== 'legal-' + which);
            modal.hidden = false;
            // The document can be long; always start people at the top.
            modal.querySelector('.legal-body').scrollTop = 0;
            modal.querySelector('.legal-close').focus();
            document.body.style.overflow = 'hidden';
        };

        window.closeLegal = function() {
            modal.hidden = true;
            document.body.style.overflow = '';
            if (lastFocused) lastFocused.focus();
        };

        tabs.forEach(t => t.addEventListener('click', () => openLegal(t.dataset.legalTab)));
        modal.addEventListener('click', e => {
            if (e.target === modal) closeLegal();
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && !modal.hidden) closeLegal();
        });
    })();
</script>
