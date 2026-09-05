<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('barangays.manage');

$barangayId = (int)($_GET['barangay_id'] ?? 0);

if ($barangayId <= 0) {
    admin_redirect('/admin/barangays.php', [
        'notice' => 'Please select a barangay first.',
        'type' => 'error'
    ]);
}

$barangay = admin_fetch_one(
    "SELECT id, name, city_municipality FROM barangays WHERE id = ? LIMIT 1",
    'i',
    [$barangayId]
);

if (!$barangay) {
    admin_redirect('/admin/barangays.php', [
        'notice' => 'Barangay not found.',
        'type' => 'error'
    ]);
}

$areas = admin_fetch_all(
    "SELECT id, area_code, area_name, area_type, description, is_active, created_at,
            (SELECT COUNT(*) FROM children WHERE local_area_id = local_areas.id) AS children_count
     FROM local_areas
     WHERE barangay_id = ?
     ORDER BY area_type ASC, area_name ASC",
    'i',
    [$barangayId]
);

$actions = '<a class="admin-btn-secondary" href="'
    . admin_e(app_url('/admin/barangays.php'))
    . '">' . admin_action_icon('back') . ' Barangays</a>';

admin_layout_start(
    'Local Areas — ' . $barangay['name'],
    'Manage puroks, sitios, subdivisions, and other local areas within this barangay.',
    'barangays',
    $actions,
    'Local Areas'
);

$flash = admin_flash_message();
?>
<?php if ($flash): ?>
    <div class="admin-alert admin-alert-<?php echo $flash['type'] === 'error' ? 'error' : 'success'; ?>" style="margin-bottom:20px;">
        <?php echo admin_e($flash['message']); ?>
    </div>
<?php endif; ?>

<section class="admin-grid-cards" style="margin-bottom:20px;">
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Barangay</div>
                <div class="admin-card-value admin-card-value--text"><?php echo admin_e($barangay['name']); ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up"><?php echo admin_e((string)($barangay['city_municipality'] ?? '')); ?></span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6Zm0 9.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Local Areas</div>
                <div class="admin-card-value"><?php echo count($areas); ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up"><?php echo count(array_filter($areas, fn($a) => (int)$a['is_active'] === 1)); ?> active</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Children Linked</div>
                <div class="admin-card-value"><?php echo array_sum(array_map(fn($a) => (int)$a['children_count'], $areas)); ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">Across all local areas</span>
                </div>
            </div>
        </div>
    </article>
</section>


<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Local Areas</h2>
            <p class="admin-section-subtitle">Puroks, sitios, subdivisions, and other sub-barangay areas.</p>
        </div>
        <button class="admin-btn" data-add-area-btn type="button">
            <?php echo admin_action_icon('add'); ?> Add Local Area
        </button>
    </div>

    <?php if (empty($areas)): ?>
        <div class="admin-empty">
            <p>No local areas have been registered for this barangay yet.</p>
        </div>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table" id="local-areas-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Area Name</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Children</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($areas as $area): ?>
                        <tr data-filter-text="<?php echo admin_e(strtolower($area['area_name'] . ' ' . $area['area_type'] . ' ' . (string)($area['area_code'] ?? ''))); ?>">
                            <td style="color:var(--admin-muted);font-family:monospace;font-size:12px;"><?php echo admin_e((string)($area['area_code'] ?? '—')); ?></td>
                            <td style="font-weight:700;color:var(--admin-text);"><?php echo admin_e($area['area_name']); ?></td>
                            <td>
                                <span class="admin-pill is-info">
                                    <?php echo admin_e(ucfirst($area['area_type'])); ?>
                                </span>
                            </td>
                            <td style="color:var(--admin-muted);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo admin_e((string)($area['description'] ?? '—')); ?></td>
                            <td><?php echo (int)$area['children_count']; ?></td>
                            <td>
                                <span class="admin-pill <?php echo (int)$area['is_active'] === 1 ? 'is-success' : 'is-muted'; ?>">
                                    <?php echo (int)$area['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="admin-actions">
                                    <button class="admin-icon-btn" title="Edit" type="button"
                                        data-edit-area-btn
                                        data-id="<?php echo (int)$area['id']; ?>"
                                        data-name="<?php echo admin_e($area['area_name']); ?>"
                                        data-type="<?php echo admin_e($area['area_type']); ?>"
                                        data-code="<?php echo admin_e((string)($area['area_code'] ?? '')); ?>"
                                        data-description="<?php echo admin_e((string)($area['description'] ?? '')); ?>"
                                        data-active="<?php echo (int)$area['is_active']; ?>"
                                    ><?php echo admin_action_icon('edit'); ?></button>
                                    <?php if ((int)$area['children_count'] === 0): ?>
                                        <form method="post" action="<?php echo admin_e(app_url('/api/admin/local_areas.php')); ?>" onsubmit="return confirm('Delete <?php echo admin_e($area['area_name']); ?>? This cannot be undone.');" style="display:inline;">
                                            <input type="hidden" name="id" value="<?php echo (int)$area['id']; ?>">
                                            <input type="hidden" name="_method" value="DELETE">
                                            <button class="admin-icon-btn admin-icon-btn-danger" title="Delete" type="submit"><?php echo admin_action_icon('delete'); ?></button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?php echo admin_e(app_url('/api/admin/local_areas.php')); ?>" onsubmit="return confirm('Deactivate <?php echo admin_e($area['area_name']); ?>? It will no longer appear for new registrations.');" style="display:inline;">
                                            <input type="hidden" name="id" value="<?php echo (int)$area['id']; ?>">
                                            <input type="hidden" name="is_active" value="0">
                                            <input type="hidden" name="_method" value="PATCH">
                                            <button class="admin-icon-btn admin-icon-btn-danger" title="Deactivate" type="submit">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>


<div class="admin-modal-overlay" data-area-modal-overlay style="display:none;">
    <div class="admin-modal" style="max-width:480px;">
        <div class="admin-modal-head">
            <h3 data-area-modal-title>Add Local Area</h3>
            <button class="admin-modal-close" data-area-modal-close type="button">&times;</button>
        </div>
        <form id="area-form" class="admin-form-grid" style="padding:0;">
            <input type="hidden" name="barangay_id" value="<?php echo (int)$barangayId; ?>">
            <input type="hidden" name="area_id" value="" data-area-id-field>

            <label class="admin-field">
                <span>Type <span class="admin-required">*</span></span>
                <select name="area_type" required>
                    <option value="purok">Purok</option>
                    <option value="sitio">Sitio</option>
                    <option value="subdivision">Subdivision</option>
                    <option value="village">Village</option>
                    <option value="zone">Zone</option>
                    <option value="phase">Phase</option>
                    <option value="other">Other</option>
                </select>
            </label>

            <label class="admin-field">
                <span>Name <span class="admin-required">*</span></span>
                <input type="text" name="area_name" required maxlength="150" placeholder="e.g. Purok 3, Villa Maria Subdivision">
            </label>

            <label class="admin-field">
                <span>Area Code <small style="color:var(--admin-muted);font-weight:400;">(optional)</small></span>
                <input type="text" name="area_code" maxlength="30" placeholder="e.g. DPN-P001">
            </label>

            <label class="admin-field">
                <span>Description <small style="color:var(--admin-muted);font-weight:400;">(optional)</small></span>
                <input type="text" name="description" maxlength="255" placeholder="Brief note about this area">
            </label>

            <div class="admin-field-wide" style="display:flex;gap:10px;justify-content:flex-end;">
                <button class="admin-btn-secondary" data-area-modal-close type="button">Cancel</button>
                <button class="admin-btn" type="submit" data-area-modal-submit>Create</button>
            </div>
        </form>
    </div>
</div>


<script>
(function() {
    const overlay = document.querySelector('[data-area-modal-overlay]');
    const form = document.getElementById('area-form');
    const title = document.querySelector('[data-area-modal-title]');
    const submitBtn = document.querySelector('[data-area-modal-submit]');
    const idField = document.querySelector('[data-area-id-field]');
    const addBtn = document.querySelector('[data-add-area-btn]');

    function openModal() {
        overlay.style.display = 'flex';
    }

    function closeModal() {
        overlay.style.display = 'none';
        form.reset();
        idField.value = '';
        title.textContent = 'Add Local Area';
        submitBtn.textContent = 'Create';
    }

    addBtn.addEventListener('click', function() {
        openModal();
    });

    document.querySelectorAll('[data-area-modal-close]').forEach(function(btn) {
        btn.addEventListener('click', closeModal);
    });

    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeModal();
    });

    document.querySelectorAll('[data-edit-area-btn]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            idField.value = btn.dataset.id;
            form.area_type.value = btn.dataset.type;
            form.area_name.value = btn.dataset.name;
            form.area_code.value = btn.dataset.code;
            form.description.value = btn.dataset.description;
            title.textContent = 'Edit Local Area';
            submitBtn.textContent = 'Save Changes';
            openModal();
        });
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const areaId = idField.value;
        const isEdit = areaId !== '';
        const url = '<?php echo admin_e(app_url("/api/admin/local_areas.php")); ?>';

        const body = new FormData(form);
        if (isEdit) {
            body.set('id', areaId);
            body.set('_method', 'PUT');
        }

        fetch(url, {
            method: isEdit ? 'PUT' : 'POST',
            body: body
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                location.reload();
            } else {
                alert(res.message || 'Something went wrong.');
            }
        })
        .catch(function() {
            alert('Network error. Please try again.');
        });
    });
})();
</script>

<?php
admin_layout_end();
