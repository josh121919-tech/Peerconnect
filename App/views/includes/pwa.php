<?php
/**
 * pwa.php — PWA head tags + service worker registration.
 * Included once by design_system.php.
 * Safe to include on any page.
 */
if (defined('PWA_INCLUDED')) return;
define('PWA_INCLUDED', true);
?>
<!-- ── PWA Manifest & Meta ─────────────────────────────────────── -->
<link rel="manifest" href="<?= htmlspecialchars(url('pwa-manifest')) ?>">
<meta name="theme-color" content="#023047">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="PeerConnect">
<link rel="apple-touch-icon" href="<?= htmlspecialchars(BASE_URL) ?>/public/icons/icon.svg">
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars(BASE_URL) ?>/public/icons/icon.svg">

<!-- ── Service Worker Registration ───────────────────────────── -->
<script>
(function() {
    if (!('serviceWorker' in navigator)) return;

    window.addEventListener('load', function() {
        navigator.serviceWorker.register(<?= json_encode(BASE_URL . '/public/sw.js', JSON_UNESCAPED_SLASHES) ?>, {
            scope: <?= json_encode(BASE_URL . '/', JSON_UNESCAPED_SLASHES) ?>,
        }).then(function(reg) {
            // Check for updates every 60s
            setInterval(() => reg.update(), 60000);

            // Notify user when new version is available
            reg.addEventListener('updatefound', function() {
                const newWorker = reg.installing;
                newWorker.addEventListener('statechange', function() {
                    if (this.state === 'installed' && navigator.serviceWorker.controller) {
                        showUpdateBanner();
                    }
                });
            });
        }).catch(function(err) {
            console.warn('[PWA] SW registration failed:', err);
        });
    });

    function showUpdateBanner() {
        const banner = document.createElement('div');
        banner.id = 'pwa-update-banner';
        banner.innerHTML = `
            <span>🔄 A new version of PeerConnect is available.</span>
            <button onclick="window.location.reload()" style="
                margin-left:12px;background:white;color:#023047;
                border:none;border-radius:6px;padding:5px 12px;
                font-size:12px;font-weight:700;cursor:pointer;
            ">Update now</button>
            <button onclick="this.closest('#pwa-update-banner').remove()" style="
                margin-left:6px;background:transparent;border:none;
                color:rgba(255,255,255,.7);cursor:pointer;font-size:16px;
            ">×</button>
        `;
        Object.assign(banner.style, {
            position:'fixed', bottom:'16px', left:'50%',
            transform:'translateX(-50%)',
            background:'#023047', color:'white',
            padding:'12px 18px', borderRadius:'10px',
            fontSize:'13px', fontWeight:'500',
            boxShadow:'0 4px 20px rgba(0,0,0,.2)',
            zIndex:'9999', display:'flex', alignItems:'center',
            whiteSpace:'nowrap', maxWidth:'90vw',
        });
        document.body.appendChild(banner);
    }

    // ── Install prompt (Add to Home Screen) ──────────────────
    let deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        showInstallButton();
    });

    function showInstallButton() {
        // Only show if there's a #pwa-install-btn element on the page
        const btn = document.getElementById('pwa-install-btn');
        if (!btn) return;
        btn.style.display = 'flex';
        btn.addEventListener('click', function() {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function(result) {
                if (result.outcome === 'accepted') btn.remove();
                deferredPrompt = null;
            });
        });
    }

    window.addEventListener('appinstalled', function() {
        const btn = document.getElementById('pwa-install-btn');
        if (btn) btn.remove();
        deferredPrompt = null;
    });
})();
</script>
