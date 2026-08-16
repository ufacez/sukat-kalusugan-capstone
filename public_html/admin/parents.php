<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('parents.view');

$parents = admin_fetch_all(
    'SELECT
        p.id,
        p.name,
        p.email,
        p.parent_type,
        p.phone,
        p.address,
        p.status,
        p.created_at,
        COUNT(DISTINCT c.id) AS children_count,
        COUNT(DISTINCT a.id) AS appointment_count,
        MAX(m.measurement_date) AS latest_measurement
     FROM parents p
     LEFT JOIN children c ON c.parent_id = p.id
     LEFT JOIN appointments a ON a.parent_id = p.id
     LEFT JOIN measurements m ON m.child_id = c.id
     GROUP BY p.id, p.name, p.email, p.parent_type, p.phone, p.address, p.status, p.created_at
     ORDER BY p.created_at DESC, p.id DESC'
);

$activeCount = count(array_filter($parents, static fn(array $parent): bool => (string)$parent['status'] === 'active'));
$totalChildren = array_sum(array_map(static fn(array $parent): int => (int)$parent['children_count'], $parents));
$totalAppointments = array_sum(array_map(static fn(array $parent): int => (int)$parent['appointment_count'], $parents));

$actions = '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/users.php')) . '">Users</a>';

admin_layout_start('Parents', 'Parent accounts and linked child records.', 'parents', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-stat-label">Parents</div>
        <div class="admin-stat-value"><?php echo count($parents); ?></div>
        <div class="admin-stat-note"><?php echo $activeCount; ?> active accounts</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Children linked</div>
        <div class="admin-stat-value"><?php echo $totalChildren; ?></div>
        <div class="admin-stat-note">All household children</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Appointments</div>
        <div class="admin-stat-value"><?php echo $totalAppointments; ?></div>
        <div class="admin-stat-note">Parent-requested and nutritionist-created visits</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Latest sync</div>
        <div class="admin-stat-value">Live</div>
        <div class="admin-stat-note">Data is read directly from MySQL</div>
    </article>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Parent Directory</h2>
            <p class="admin-section-subtitle">View parent accounts and the child records linked to each account.</p>
        </div>
        <input class="admin-search" data-admin-filter="#parents-table" type="search" placeholder="Search parents">
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="parents-table">
            <thead>
                <tr>
                    <th>Parent</th>
                    <th>Type</th>
                    <th>Email</th>
                    <th>Children</th>
                    <th>Appointments</th>
                    <th>Latest measurement</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($parents as $parent): ?>
                    <tr data-filter-text="<?php echo admin_e(strtolower($parent['name'] . ' ' . $parent['email'] . ' ' . $parent['parent_type'] . ' ' . $parent['address'])); ?>">
                        <td>
                            <div style="font-weight:700;color:var(--admin-text);"><?php echo admin_e($parent['name']); ?></div>
                            <div class="admin-mini"><?php echo admin_e((string)($parent['phone'] ?? '')); ?> · <?php echo admin_e((string)($parent['address'] ?? '')); ?></div>
                        </td>
                        <td><span class="admin-pill is-muted"><?php echo admin_e((string)$parent['parent_type']); ?></span></td>
                        <td><?php echo admin_e((string)$parent['email']); ?></td>
                        <td><?php echo (int)$parent['children_count']; ?></td>
                        <td><?php echo (int)$parent['appointment_count']; ?></td>
                        <td><?php echo admin_e((string)($parent['latest_measurement'] ?? 'n/a')); ?></td>
                        <td><span class="admin-pill <?php echo (string)$parent['status'] === 'active' ? 'is-success' : 'is-muted'; ?>"><?php echo admin_e(ucfirst((string)$parent['status'])); ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
admin_layout_end();
