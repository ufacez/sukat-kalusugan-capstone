<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('users.view');

$editId = (int)($_GET['edit'] ?? 0);

if ($editId > 0) {
    admin_redirect('/admin/user_form.php?id=' . $editId);
}

$users = admin_fetch_all(
    'SELECT u.id, u.name, u.email, u.username, u.phone, u.barangay_id, b.name AS barangay, u.status, u.last_login, u.created_at, r.name AS role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     LEFT JOIN barangays b ON b.id = u.barangay_id
     ORDER BY u.created_at DESC, u.id DESC'
);

$adminCount = 0;
$nutritionistCount = 0;
$activeCount = 0;

foreach ($users as $user) {
    if ($user['role_name'] === 'admin') {
        $adminCount++;
    }

    if ($user['role_name'] === 'nutritionist') {
        $nutritionistCount++;
    }

    if ($user['status'] === 'active') {
        $activeCount++;
    }
}

$actions = '<a class="admin-btn" href="' . admin_e(app_url('/admin/user_form.php')) . '">' . admin_action_icon('add') . ' Add user</a>';

admin_layout_start('User Management', 'Create, update, and remove staff accounts.', 'users', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Users</div>
                <div class="admin-card-value"><?php echo count($users); ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up"><?php echo $activeCount; ?> active accounts</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-danger">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Admins</div>
                <div class="admin-card-value"><?php echo $adminCount; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">System administrators</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-success">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Nutritionists</div>
                <div class="admin-card-value"><?php echo $nutritionistCount; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">Field and clinic staff</span>
                </div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Last Sync</div>
                <div class="admin-card-value admin-card-value--text">Live</div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up">Data is pulled from MySQL on every page load</span>
                </div>
            </div>
        </div>
    </article>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">User Directory</h2>
            <p class="admin-section-subtitle">Filter and manage staff accounts directly from the database.</p>
        </div>
        <div class="admin-toolbar" style="margin:0;">
            <input class="admin-search" type="search" placeholder="Search users" data-admin-filter="#users-table">
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table" id="users-table">
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Barangay</th>
                    <th>Status</th>
                    <th>Last login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <?php
                    $statusClass = $user['status'] === 'active' ? 'is-success' : 'is-muted';
                    ?>
                    <tr data-filter-text="<?php echo admin_e(strtolower($user['name'] . ' ' . $user['email'] . ' ' . $user['username'] . ' ' . $user['role_name'])); ?>">
                        <td><span class="admin-pill <?php echo $user['role_name'] === 'admin' ? 'is-warn' : 'is-success'; ?>"><?php echo admin_e(ucfirst($user['role_name'])); ?></span></td>
                        <td><?php echo admin_e($user['name']); ?></td>
                        <td><?php echo admin_e($user['username'] ?? ''); ?></td>
                        <td><?php echo admin_e($user['email']); ?></td>
                        <td><?php echo admin_e($user['barangay'] ?? 'All barangays'); ?></td>
                        <td><span class="admin-pill <?php echo $statusClass; ?>"><?php echo admin_e(ucfirst($user['status'])); ?></span></td>
                        <td><?php echo admin_e((string)($user['last_login'] ?? 'n/a')); ?></td>
                        <td>
                            <div class="admin-actions">
                                <a class="admin-icon-btn" title="Edit" href="<?php echo admin_e(app_url('/admin/user_form.php?id=' . (int)$user['id'])); ?>"><?php echo admin_action_icon('edit'); ?></a>
                                <form method="post" action="<?php echo admin_e(app_url('/api/admin/users_delete.php')); ?>" onsubmit="return confirm('Delete <?php echo admin_e($user['name']); ?>?');" style="display:inline;">
                                    <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
                                    <button class="admin-icon-btn admin-icon-btn-danger" title="Delete" type="submit"><?php echo admin_action_icon('delete'); ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
admin_layout_end();

