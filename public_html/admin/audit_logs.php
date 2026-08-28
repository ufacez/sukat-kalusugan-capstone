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
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Info</div>
                <div class="admin-card-value"><?php echo (int)$levelCounts['info']; ?></div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Warnings</div>
                <div class="admin-card-value"><?php echo (int)$levelCounts['warning']; ?></div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-danger">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Critical</div>
                <div class="admin-card-value"><?php echo (int)$levelCounts['danger']; ?></div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-success">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Total</div>
                <div class="admin-card-value"><?php echo count($logs); ?></div>
            </div>
        </div>
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

