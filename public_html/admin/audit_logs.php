<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('audit_logs.view');

$levelCounts = [
    'info' => admin_scalar("SELECT COUNT(*) FROM audit_logs WHERE level = 'info'"),
    'warning' => admin_scalar("SELECT COUNT(*) FROM audit_logs WHERE level = 'warning'"),
    'danger' => admin_scalar("SELECT COUNT(*) FROM audit_logs WHERE level = 'danger'"),
];

$logs = admin_fetch_all(
    'SELECT a.id, a.action, a.level, a.description, a.ip_address, a.created_at, COALESCE(u.email, "System") AS actor
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC, a.id DESC'
);

$actions = '<input class="admin-search" type="search" placeholder="Search logs" data-admin-filter="#audit-table">';

admin_layout_start('Audit Logs', 'Track account, sensor, and system events.', 'audit_logs', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-stat-label">Info</div>
        <div class="admin-stat-value"><?php echo (int)$levelCounts['info']; ?></div>
        <div class="admin-stat-note">General events</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Warnings</div>
        <div class="admin-stat-value"><?php echo (int)$levelCounts['warning']; ?></div>
        <div class="admin-stat-note">Needs review</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Critical</div>
        <div class="admin-stat-value"><?php echo (int)$levelCounts['danger']; ?></div>
        <div class="admin-stat-note">High priority</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Total</div>
        <div class="admin-stat-value"><?php echo count($logs); ?></div>
        <div class="admin-stat-note">Stored audit events</div>
    </article>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Event Log</h2>
            <p class="admin-section-subtitle">Searchable audit history from the database.</p>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="audit-table">
            <thead>
                <tr>
                    <th>Level</th>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>IP</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $pillClass = $log['level'] === 'danger' ? 'is-danger' : ($log['level'] === 'warning' ? 'is-warn' : 'is-success');
                    ?>
                    <tr data-filter-text="<?php echo admin_e(strtolower($log['actor'] . ' ' . $log['action'] . ' ' . ($log['description'] ?? ''))); ?>">
                        <td><span class="admin-pill <?php echo $pillClass; ?>"><?php echo admin_e($log['level']); ?></span></td>
                        <td><?php echo admin_e((string)$log['created_at']); ?></td>
                        <td><?php echo admin_e($log['actor']); ?></td>
                        <td><?php echo admin_e($log['action']); ?></td>
                        <td><?php echo admin_e((string)($log['ip_address'] ?? 'n/a')); ?></td>
                        <td><?php echo admin_e($log['description'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
admin_layout_end();

