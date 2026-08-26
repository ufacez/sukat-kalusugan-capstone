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

$actions = '<a class="admin-btn" href="' . admin_e(app_url('/admin/user_form.php')) . '">Add user</a>';

admin_layout_start('User Management', 'Create, update, and remove staff accounts.', 'users', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-stat-label">Users</div>
        <div class="admin-stat-value"><?php echo count($users); ?></div>
        <div class="admin-stat-note"><?php echo $activeCount; ?> active accounts</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Admins</div>
        <div class="admin-stat-value"><?php echo $adminCount; ?></div>
        <div class="admin-stat-note">System administrators</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Nutritionists</div>
        <div class="admin-stat-value"><?php echo $nutritionistCount; ?></div>
        <div class="admin-stat-note">Field and clinic staff</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Last Sync</div>
        <div class="admin-stat-value">Live</div>
        <div class="admin-stat-note">Data is pulled from MySQL on every page load</div>
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

