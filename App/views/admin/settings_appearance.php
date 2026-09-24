<?php

/**
 * admin/settings_appearance.php — System Settings → Appearance.
 *
 * No Theme Settings. Light/dark/system was on the reference design and is
 * deliberately left out: PeerConnect has one carefully built light theme, and
 * a switch offering a dark one that does not exist would break every page it
 * touched. That was an explicit decision, not an omission.
 *
 * What is here is what genuinely applies. The brand colours below overwrite
 * the two design tokens the whole app is drawn from (--forest and --mint,
 * emitted by includes/design_system.php), and the logo and favicon are used
 * by the shells. Everything on this page changes what a member sees.
 */

session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/settings_store.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$S = pc_settings($con, true);
$csrf = csrf_token();

$defaults = pc_setting_defaults();
$isDefault = $S['brand_primary'] === $defaults['brand_primary']
          && $S['brand_accent']  === $defaults['brand_accent'];

$current_page = 'settings-appearance';
include 'layout.php';
include __DIR__ . '/includes/settings_ui.php';
?>

<?php st_header('admin-settings-appearance', 'Appearance',
    'The brand marks and colours the platform is drawn with.'); ?>

<div class="st-grid">
    <div class="st-stack">
        <!-- ── Colours ── -->
        <form class="st-card" method="post" action="<?= url('admin-action-settings') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="section" value="brand_colors">
            <h2>Brand colours</h2>
            <p class="sub">Two colours the whole platform is built from. Everything else is derived.</p>

            <div class="st-note">
                <b>These two are the design system.</b>
                Deep is the navy behind headings, the sidebar and primary buttons; Accent is the blue on links,
                active states and focus rings. Changing them here rewrites the <code>--forest</code> and
                <code>--mint</code> tokens for every page in the app, member and admin alike.
            </div>

            <div class="st-f-row">
                <div class="st-f">
                    <label for="a-primary">Deep (headings, sidebar, primary buttons)</label>
                    <input id="a-primary" type="color" name="brand_primary" value="<?= htmlspecialchars($S['brand_primary']) ?>"
                           oninput="document.getElementById('a-primary-t').value=this.value;apPreview()">
                    <input id="a-primary-t" type="text" name="brand_primary_text" maxlength="7" value="<?= htmlspecialchars($S['brand_primary']) ?>"
                           style="margin-top:6px;font-family:ui-monospace,monospace;"
                           oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value)){document.getElementById('a-primary').value=this.value;apPreview()}">
                </div>
                <div class="st-f">
                    <label for="a-accent">Accent (links, active states, focus)</label>
                    <input id="a-accent" type="color" name="brand_accent" value="<?= htmlspecialchars($S['brand_accent']) ?>"
                           oninput="document.getElementById('a-accent-t').value=this.value;apPreview()">
                    <input id="a-accent-t" type="text" name="brand_accent_text" maxlength="7" value="<?= htmlspecialchars($S['brand_accent']) ?>"
                           style="margin-top:6px;font-family:ui-monospace,monospace;"
                           oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value)){document.getElementById('a-accent').value=this.value;apPreview()}">
                </div>
            </div>

            <div class="st-foot">
                <?php if (!$isDefault): ?>
                    <button type="submit" name="reset" value="1" class="st-save"
                            style="background:#fff;color:var(--gray-700);border:1px solid var(--gray-200);">Reset to default</button>
                <?php endif; ?>
                <button type="submit" class="st-save">
                    <svg fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12.5 10 17 19 7" /></svg>
                    Save colours
                </button>
            </div>
        </form>

        <!-- ── Logo and favicon ── -->
        <div class="st-cols">
            <form class="st-card" method="post" action="<?= url('admin-action-settings') ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="section" value="brand_logo">
                <h2>Logo</h2>
                <p class="sub">Shown in the admin sidebar, in the member top bar and on the sign-in pages. Not in emails: most mail clients drop SVG, and an image served from this server is unreachable from an inbox, so those keep the text wordmark.</p>

                <div style="display:grid;place-items:center;padding:20px;background:var(--gray-50,#F7F8FA);border-radius:12px;margin-bottom:13px;min-height:92px;">
                    <?php if ($S['brand_logo'] !== ''): ?>
                        <img src="<?= htmlspecialchars($S['brand_logo']) ?>" alt="Current logo" style="max-width:100%;max-height:64px;">
                    <?php else: ?>
                        <span style="font-size:12.5px;color:var(--gray-400);">No custom logo — the built-in mark is used.</span>
                    <?php endif; ?>
                </div>

                <div class="st-f">
                    <label for="a-logo">Upload a new one</label>
                    <input id="a-logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml"
                           style="width:100%;padding:9px;border:1px solid var(--gray-200);border-radius:10px;font-size:13px;">
                    <small>PNG, JPG, WEBP or SVG, up to 2 MB. Around 300 × 100 px works well.</small>
                </div>

                <div class="st-foot">
                    <?php if ($S['brand_logo'] !== ''): ?>
                        <button type="submit" name="remove" value="1" class="st-save"
                                style="background:#fff;color:#A6301F;border:1px solid #F3C9C0;">Remove</button>
                    <?php endif; ?>
                    <button type="submit" class="st-save">Save logo</button>
                </div>
            </form>

            <form class="st-card" method="post" action="<?= url('admin-action-settings') ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="section" value="brand_favicon">
                <h2>Favicon</h2>
                <p class="sub">The small icon in a browser tab.</p>

                <div style="display:grid;place-items:center;padding:20px;background:var(--gray-50,#F7F8FA);border-radius:12px;margin-bottom:13px;min-height:92px;">
                    <?php if ($S['brand_favicon'] !== ''): ?>
                        <img src="<?= htmlspecialchars($S['brand_favicon']) ?>" alt="Current favicon" style="width:48px;height:48px;object-fit:contain;">
                    <?php else: ?>
                        <span style="font-size:12.5px;color:var(--gray-400);">No custom favicon.</span>
                    <?php endif; ?>
                </div>

                <div class="st-f">
                    <label for="a-fav">Upload a new one</label>
                    <input id="a-fav" type="file" name="favicon" accept="image/png,image/x-icon,image/svg+xml,image/webp"
                           style="width:100%;padding:9px;border:1px solid var(--gray-200);border-radius:10px;font-size:13px;">
                    <small>PNG, ICO, SVG or WEBP, up to 1 MB. 32 × 32 px is the usual size.</small>
                </div>

                <div class="st-foot">
                    <?php if ($S['brand_favicon'] !== ''): ?>
                        <button type="submit" name="remove" value="1" class="st-save"
                                style="background:#fff;color:#A6301F;border:1px solid #F3C9C0;">Remove</button>
                    <?php endif; ?>
                    <button type="submit" class="st-save">Save favicon</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════ Side ══════════ -->
    <div class="st-stack">
        <div class="st-card">
            <h2>Preview</h2>
            <p class="sub">Updates as you pick a colour. Save to apply it everywhere.</p>
            <div id="ap-prev" style="border:1px solid var(--gray-200);border-radius:12px;overflow:hidden;">
                <div style="display:flex;min-height:190px;">
                    <div id="ap-rail" style="width:76px;flex:none;padding:12px 8px;background:<?= htmlspecialchars($S['brand_primary']) ?>;">
                        <?php for ($i = 0; $i < 5; $i++): ?>
                            <div class="ap-item" style="height:20px;margin-bottom:7px;border-radius:6px;background:<?= $i === 1 ? htmlspecialchars($S['brand_accent']) : 'rgba(255,255,255,.14)' ?>;"></div>
                        <?php endfor; ?>
                    </div>
                    <div style="flex:1;padding:14px;background:#fff;">
                        <div id="ap-h" style="height:11px;width:60%;border-radius:4px;background:<?= htmlspecialchars($S['brand_primary']) ?>;margin-bottom:10px;"></div>
                        <div style="height:8px;width:85%;border-radius:4px;background:var(--gray-100);margin-bottom:6px;"></div>
                        <div style="height:8px;width:70%;border-radius:4px;background:var(--gray-100);margin-bottom:14px;"></div>
                        <div style="display:flex;gap:8px;">
                            <div id="ap-btn" style="padding:7px 16px;border-radius:8px;background:<?= htmlspecialchars($S['brand_primary']) ?>;color:#fff;font-size:11px;font-weight:600;">Primary</div>
                            <div id="ap-link" style="padding:7px 14px;border-radius:8px;border:1px solid currentColor;color:<?= htmlspecialchars($S['brand_accent']) ?>;font-size:11px;font-weight:600;">Accent</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="st-card">
            <h2>Theme settings</h2>
            <div class="st-note">
                <b>Deliberately not here.</b>
                PeerConnect has one light theme, built and tested end to end. A light/dark/system switch would
                need a second full palette across every page, and offering one that does not exist would leave
                members reading dark text on a dark background. Brand colours above are the part that can be
                changed safely.
            </div>
        </div>

        <div class="st-card">
            <h2>Also not here</h2>
            <p class="sub">On the reference design, but nothing in this app reads them.</p>
            <?php foreach ([
                'Font family and size' => 'Type is set by the design system; changing it per-install would break the layouts built around it.',
                'Custom CSS box' => 'Arbitrary CSS from a form is a way to break every page, and to inject content.',
                'Sidebar position' => 'The layout is built left-rail; there is no right-rail variant to switch to.',
            ] as $t => $d): ?>
                <div class="st-row">
                    <span class="st-dot" style="background:#F3F4F6;color:#9A9EA6;"><?= ss_icon('x') ?></span>
                    <span style="min-width:0;flex:1;"><b style="color:var(--gray-500);"><?= $t ?></b><span class="h"><?= $d ?></span></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
    function apPreview() {
        var p = document.getElementById('a-primary').value;
        var a = document.getElementById('a-accent').value;
        document.getElementById('ap-rail').style.background = p;
        document.getElementById('ap-h').style.background = p;
        document.getElementById('ap-btn').style.background = p;
        document.getElementById('ap-link').style.color = a;
        document.querySelectorAll('#ap-rail .ap-item')[1].style.background = a;
    }
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
