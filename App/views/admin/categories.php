<?php


session_start();
include __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_admin();

$current_page = 'admin-categories';
$errors  = [];
$success = '';

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('CSRF mismatch.');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name  = trim(strip_tags($_POST['name'] ?? ''));
        $icon  = trim(strip_tags($_POST['icon'] ?? 'explore'));
        $order = (int)($_POST['sort_order'] ?? 99);
        if ($name === '') {
            $errors[] = 'Category name is required.';
        } else {
            $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)), '-');
            $st = $con->prepare("INSERT INTO categories (name, slug, icon, sort_order) VALUES (?,?,?,?)");
            $st->bind_param("sssi", $name, $slug, $icon, $order);
            $st->execute() ? $success = "Category {$name} added." : $errors[] = 'Name already exists.';
            $st->close();
        }
    } elseif ($action === 'edit') {
        $id    = (int)($_POST['category_id'] ?? 0);
        $name  = trim(strip_tags($_POST['name'] ?? ''));
        $icon  = trim(strip_tags($_POST['icon'] ?? 'explore'));
        $order = (int)($_POST['sort_order'] ?? 0);
        if (!$id || $name === '') {
            $errors[] = 'Invalid data.';
        } else {
            $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)), '-');
            $st = $con->prepare("UPDATE categories SET name=?, slug=?, icon=?, sort_order=? WHERE category_id=?");
            $st->bind_param("sssii", $name, $slug, $icon, $order, $id);
            $st->execute();
            $st->close();
            $success = "Category updated.";
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['category_id'] ?? 0);
        if ($id) {
            $st = $con->prepare("UPDATE categories SET is_active = IF(is_active=1,0,1) WHERE category_id=?");
            $st->bind_param("i", $id);
            $st->execute();
            $st->close();
            $success = "Visibility toggled.";
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['category_id'] ?? 0);
        if ($id <= 15) {
            $errors[] = 'Default categories cannot be deleted — hide them instead.';
        } elseif ($id) {
            $st = $con->prepare("DELETE FROM categories WHERE category_id=?");
            $st->bind_param("i", $id);
            $st->execute();
            $st->close();
            $success = "Category deleted.";
        }
    } elseif ($action === 'reorder') {
        $ids = json_decode($_POST['order'] ?? '[]', true);
        if (is_array($ids)) {
            $st = $con->prepare("UPDATE categories SET sort_order=? WHERE category_id=?");
            foreach ($ids as $i => $cid) {
                $ord = (int)$i;
                $cid = (int)$cid;
                $st->bind_param("ii", $ord, $cid);
                $st->execute();
            }
            $st->close();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
    }
}

// Load all categories
$cats = $con->query("SELECT * FROM categories ORDER BY sort_order ASC, category_id ASC")->fetch_all(MYSQLI_ASSOC);

$icon_options = [
    'home' => 'Home',
    'explore' => 'Compass',
    'partner' => 'Person',
    'bookings' => 'Calendar',
    'journal' => 'Edit',
    'messages' => 'Chat',
    'users' => 'Users',
    'verify' => 'Checkmark',
    'settings' => 'Settings',
];

include __DIR__ . '/layout.php';

?>
<style>
    /* Page styles scoped to categories page */
    .cats-page h1 {
        font-size: 20px;
        font-weight: 700;
        margin: 0 0 4px;
        font-family: 'DM Serif Display', serif;
        color: #111;
    }

    .cats-page p.sub {
        font-size: 13px;
        color: #6b7280;
        margin: 0;
    }

    .cats-hd {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .btn-green {
        background: #1a5c4a;
        color: white;
        border: none;
        border-radius: 8px;
        padding: 9px 18px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        font-family: inherit;
    }

    .btn-green:hover {
        background: #15803d;
    }

    .btn-ghost {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 8px 13px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        color: #374151;
        font-family: inherit;
    }

    .btn-ghost:hover {
        background: #f9fafb;
    }

    .btn-danger {
        background: white;
        border: 1px solid #fca5a5;
        border-radius: 8px;
        padding: 8px 13px;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        color: #dc2626;
        font-family: inherit;
    }

    .btn-danger:hover {
        background: #fee2e2;
    }

    .cats-table {
        width: 100%;
        border-collapse: collapse;
    }

    .cats-table th {
        padding: 10px 16px;
        font-size: 11px;
        font-weight: 700;
        color: #6b7280;
        text-align: left;
        text-transform: uppercase;
        letter-spacing: .06em;
        background: #f9fafb;
    }

    .cats-table td {
        padding: 11px 16px;
        font-size: 13px;
        border-top: 1px solid #e5e7eb;
        vertical-align: middle;
    }

    .icon-box {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background: #f0fdf4;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .icon-box svg {
        width: 18px;
        height: 18px;
        stroke: #1a5c4a;
        fill: none;
        stroke-width: 1.8;
    }

    .badge-vis {
        background: #dcfce7;
        color: #166534;
        padding: 2px 9px;
        border-radius: 20px;
        font-size: 11.5px;
        font-weight: 600;
    }

    .badge-hid {
        background: #fee2e2;
        color: #991b1b;
        padding: 2px 9px;
        border-radius: 20px;
        font-size: 11.5px;
        font-weight: 600;
    }

    .drag-handle {
        cursor: grab;
        color: #9ca3af;
        user-select: none;
        font-size: 16px;
    }

    .drag-handle:active {
        cursor: grabbing;
    }

    .cat-row.drag-over {
        border-top: 2px solid #1a5c4a;
    }

    .cat-row.dragging {
        opacity: .4;
    }

    .alert-ok {
        background: #dcfce7;
        color: #166534;
        border-radius: 8px;
        padding: 12px 16px;
        margin-bottom: 16px;
        font-weight: 600;
        font-size: 13px;
    }

    .alert-err {
        background: #fee2e2;
        color: #dc2626;
        border-radius: 8px;
        padding: 12px 16px;
        margin-bottom: 8px;
        font-weight: 600;
        font-size: 13px;
    }

    /* Modal */
    .cat-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 100;
        background: rgba(0, 0, 0, .35);
        align-items: center;
        justify-content: center;
    }

    .cat-modal.open {
        display: flex;
    }

    .cat-modal-box {
        background: white;
        border-radius: 16px;
        padding: 28px;
        width: 100%;
        max-width: 430px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, .15);
    }

    .cat-modal-box h2 {
        font-size: 16px;
        font-weight: 700;
        margin: 0 0 18px;
        font-family: 'DM Serif Display', serif;
        color: #111;
    }

    .m-group {
        margin-bottom: 14px;
    }

    .m-label {
        font-size: 11px;
        font-weight: 700;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: .04em;
        display: block;
        margin-bottom: 5px;
    }

    .m-input,
    .m-select {
        width: 100%;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 9px 12px;
        font-size: 13px;
        font-family: inherit;
        outline: none;
        box-sizing: border-box;
    }

    .m-input:focus,
    .m-select:focus {
        border-color: #1a5c4a;
        box-shadow: 0 0 0 2px rgba(26, 92, 74, .1);
    }

    .m-footer {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        margin-top: 20px;
    }

    .hint-text {
        font-size: 12px;
        color: #9ca3af;
        margin-top: 10px;
    }
</style>

<div class="cats-page">

    <?php if ($success): ?>
        <div class="alert-ok">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $e): ?>
        <div class="alert-err">⚠️ <?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <div class="cats-hd">
        <div>
            <h1>Categories</h1>
            <p class="sub">Manage the category pills shown on the Explore page. Drag rows to reorder.</p>
        </div>
        <button onclick="document.getElementById('addModal').classList.add('open')" class="btn-green">+ Add Category</button>
    </div>

    <!-- Categories table -->
    <div style="background:white;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;">
        <div style="overflow-x:auto;">
        <table class="cats-table">
            <thead>
                <tr>
                    <th style="width:36px;"></th>
                    <th>Icon</th>
                    <th>Name</th>
                    <th>Slug</th>
                    <th>Order</th>
                    <th>Status</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody id="catBody">
                <?php
                $iconSvgs = [
                    'home'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.5 11.5 12 4l8.5 7.5V21a1 1 0 0 1-1 1h-5v-6h-5v6h-5a1 1 0 0 1-1-1v-9.5Z"/>',
                    'explore'  => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="m15.5 8.5-2.2 5-4.8 2 2.2-5 4.8-2Z"/>',
                    'partner'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>',
                    'bookings' => '<rect x="5" y="4" width="14" height="16" rx="3"/><path stroke-linecap="round" d="M8 2v4M16 2v4M5 9h14"/>',
                    'journal'  => '<path stroke-linecap="round" d="M4 20 16.5 7.5l3 3L7 23H4v-3Z"/>',
                    'messages' => '<path stroke-linecap="round" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H12l-5 4v-4h-.5A2.5 2.5 0 0 1 4 13.5v-7Z"/>',
                    'users'    => '<path stroke-linecap="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/>',
                    'verify'   => '<circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="m8.5 12.4 2.3 2.3 4.9-5.4"/>',
                    'settings' => '<circle cx="12" cy="12" r="3"/><path stroke-linecap="round" d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
                ];
                foreach ($cats as $c):
                    $svg = $iconSvgs[$c['icon']] ?? $iconSvgs['explore'];
                ?>
                    <tr class="cat-row" data-id="<?= (int)$c['category_id'] ?>">
                        <td style="text-align:center;padding:12px 8px;">
                            <span class="drag-handle" title="Drag to reorder">⠿</span>
                        </td>
                        <td style="padding:12px 16px;">
                            <div class="icon-box">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><?= $svg ?></svg>
                            </div>
                        </td>
                        <td style="font-weight:600;color:#111;"><?= htmlspecialchars($c['name']) ?></td>
                        <td style="font-family:monospace;font-size:12px;color:#6b7280;"><?= htmlspecialchars($c['slug']) ?></td>
                        <td style="color:#374151;"><?= (int)$c['sort_order'] ?></td>
                        <td>
                            <?php if ($c['is_active']): ?>
                                <span class="badge-vis">Visible</span>
                            <?php else: ?>
                                <span class="badge-hid">Hidden</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <div style="display:flex;gap:6px;justify-content:flex-end;">
                                <button onclick='openEdit(<?= json_encode($c) ?>)' class="btn-ghost" title="Edit">✏️</button>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                                    <button type="submit" class="btn-ghost" title="Toggle"><?= $c['is_active'] ? '👁️' : '🚫' ?></button>
                                </form>
                                <?php if ((int)$c['category_id'] > 15): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this category?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                                        <button type="submit" class="btn-danger" title="Delete">🗑️</button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn-ghost" title="Default — hide instead" disabled style="opacity:.4;">🔒</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if (empty($cats)): ?>
            <div style="padding:32px;text-align:center;color:#9ca3af;font-size:13px;">No categories yet.</div>
        <?php endif; ?>
    </div>
    <p class="hint-text">🔒 Default categories (ID ≤ 15) can only be hidden, not deleted.</p>

</div><!-- /cats-page -->

<!-- ADD Modal -->
<div class="cat-modal" id="addModal">
    <div class="cat-modal-box">
        <h2>Add Category</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="add">
            <div class="m-group">
                <label class="m-label">Category Name *</label>
                <input type="text" name="name" required placeholder="e.g. Machine Learning" class="m-input">
            </div>
            <div class="m-group">
                <label class="m-label">Icon</label>
                <select name="icon" class="m-select">
                    <?php foreach ($icon_options as $val => $label): ?>
                        <option value="<?= $val ?>"><?= $label ?> (<?= $val ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="m-group">
                <label class="m-label">Sort Order</label>
                <input type="number" name="sort_order" value="99" min="0" max="999" class="m-input">
            </div>
            <div class="m-footer">
                <button type="button" onclick="document.getElementById('addModal').classList.remove('open')" class="btn-ghost">Cancel</button>
                <button type="submit" class="btn-green">Add</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT Modal -->
<div class="cat-modal" id="editModal">
    <div class="cat-modal-box">
        <h2>Edit Category</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="category_id" id="editId">
            <div class="m-group">
                <label class="m-label">Name *</label>
                <input type="text" name="name" id="editName" required class="m-input">
            </div>
            <div class="m-group">
                <label class="m-label">Icon</label>
                <select name="icon" id="editIcon" class="m-select">
                    <?php foreach ($icon_options as $val => $label): ?>
                        <option value="<?= $val ?>"><?= $label ?> (<?= $val ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="m-group">
                <label class="m-label">Sort Order</label>
                <input type="number" name="sort_order" id="editOrder" min="0" max="999" class="m-input">
            </div>
            <div class="m-footer">
                <button type="button" onclick="document.getElementById('editModal').classList.remove('open')" class="btn-ghost">Cancel</button>
                <button type="submit" class="btn-green">Save</button>
            </div>
        </form>
    </div>
</div>

</main>
</div><!-- /flex-1 wrapper -->

<script>
    function openEdit(cat) {
        document.getElementById('editId').value = cat.category_id;
        document.getElementById('editName').value = cat.name;
        document.getElementById('editIcon').value = cat.icon;
        document.getElementById('editOrder').value = cat.sort_order;
        document.getElementById('editModal').classList.add('open');
    }
    // Close modals on backdrop click
    document.querySelectorAll('.cat-modal').forEach(m => {
        m.addEventListener('click', e => {
            if (e.target === m) m.classList.remove('open');
        });
    });
    // Sidebar + profile menu
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('collapsed');
    }

    function toggleProfileMenu() {
        document.getElementById('profileMenu').classList.toggle('open');
    }
    document.addEventListener('click', function(e) {
        const m = document.getElementById('profileMenu');
        if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]'))
            m.classList.remove('open');
    });
    // Drag-to-reorder
    (function() {
        const tbody = document.getElementById('catBody');
        if (!tbody) return;
        let dragged = null;
        tbody.querySelectorAll('.cat-row').forEach(row => {
            const handle = row.querySelector('.drag-handle');
            if (handle) handle.addEventListener('mousedown', () => row.setAttribute('draggable', 'true'));
            row.addEventListener('dragstart', () => {
                dragged = row;
                setTimeout(() => row.classList.add('dragging'), 0);
            });
            row.addEventListener('dragend', () => {
                row.classList.remove('dragging');
                row.removeAttribute('draggable');
                saveOrder();
            });
            row.addEventListener('dragover', e => {
                e.preventDefault();
                if (dragged !== row) row.classList.add('drag-over');
            });
            row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
            row.addEventListener('drop', e => {
                e.preventDefault();
                row.classList.remove('drag-over');
                if (dragged !== row) tbody.insertBefore(dragged, row);
            });
        });

        function saveOrder() {
            const ids = [...tbody.querySelectorAll('.cat-row')].map(r => r.dataset.id);
            const fd = new FormData();
            fd.append('action', 'reorder');
            fd.append('order', JSON.stringify(ids));
            fd.append('csrf_token', '<?= csrf_token() ?>');
            fetch(window.location.href, {
                method: 'POST',
                body: fd
            });
        }
    })();
</script>
</body>

</html>