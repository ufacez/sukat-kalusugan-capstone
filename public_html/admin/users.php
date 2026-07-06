<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('users.view');

$roles = admin_fetch_all('SELECT name FROM roles ORDER BY name ASC');
$editingId = (int)($_GET['edit'] ?? 0);
$editingUser = $editingId > 0 ? admin_fetch_one(
    'SELECT u.id, u.name, u.email, u.username, u.phone, u.barangay, u.status, r.name AS role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.id = ?
     LIMIT 1',
    'i',
    [$editingId]
) : null;

$users = admin_fetch_all(
    'SELECT u.id, u.name, u.email, u.username, u.phone, u.barangay, u.status, u.last_login, u.created_at, r.name AS role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
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

$actions = '<a class="admin-btn-secondary" href="#user-form">Add user</a>';

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

<section class="admin-section" id="user-form">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title"><?php echo $editingUser ? 'Edit User' : 'Add User'; ?></h2>
            <p class="admin-section-subtitle"><?php echo $editingUser ? 'Update account details and role assignment.' : 'Create a new staff account backed by the users table.'; ?></p>
        </div>
    </div>

    <form class="admin-form-grid" method="post" action="<?php echo admin_e(app_url($editingUser ? '/api/admin/users_update.php' : '/api/admin/users_create.php')); ?>">
        <?php if ($editingUser): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editingUser['id']; ?>">
        <?php endif; ?>
        <label class="admin-field">
            <span>Full name</span>
            <input name="name" required value="<?php echo admin_e($editingUser['name'] ?? ''); ?>" placeholder="Jane Doe">
        </label>
        <label class="admin-field">
            <span>Email</span>
            <input type="email" name="email" required value="<?php echo admin_e($editingUser['email'] ?? ''); ?>" placeholder="jane@example.com">
        </label>
        <label class="admin-field">
            <span>Username</span>
            <input name="username" required value="<?php echo admin_e($editingUser['username'] ?? ''); ?>" placeholder="janedoe">
        </label>
        <label class="admin-field">
            <span>Phone</span>
            <input name="phone" value="<?php echo admin_e($editingUser['phone'] ?? ''); ?>" placeholder="0917...">
        </label>
        <label class="admin-field">
            <span>Role</span>
            <select name="role" required>
                <?php foreach ($roles as $role): ?>
                    <option value="<?php echo admin_e($role['name']); ?>" <?php echo (($editingUser['role_name'] ?? 'nutritionist') === $role['name']) ? 'selected' : ''; ?>><?php echo admin_e(ucfirst($role['name'])); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="admin-field">
            <span>Barangay</span>
            <input name="barangay" value="<?php echo admin_e($editingUser['barangay'] ?? ''); ?>" placeholder="All or assigned barangay">
        </label>
        <label class="admin-field">
            <span>Status</span>
            <select name="status" required>
                <option value="active" <?php echo (($editingUser['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo (($editingUser['status'] ?? 'active') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </label>
        <label class="admin-field">
            <span><?php echo $editingUser ? 'New password (optional)' : 'Password'; ?></span>
            <input type="password" name="password" <?php echo $editingUser ? '' : 'required'; ?> placeholder="<?php echo $editingUser ? 'Leave blank to keep current password' : 'Create a strong password'; ?>">
        </label>
        <div class="admin-field" style="align-content:end;">
            <span>&nbsp;</span>
            <button class="admin-btn" type="submit"><?php echo $editingUser ? 'Save changes' : 'Create user'; ?></button>
        </div>
    </form>
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
                        <td><?php echo admin_e($user['barangay'] ?? 'All'); ?></td>
                        <td><span class="admin-pill <?php echo $statusClass; ?>"><?php echo admin_e(ucfirst($user['status'])); ?></span></td>
                        <td><?php echo admin_e((string)($user['last_login'] ?? 'n/a')); ?></td>
                        <td>
                            <div class="admin-actions">
                                <a class="admin-btn-secondary" href="<?php echo admin_e(app_url('/admin/users.php?edit=' . (int)$user['id'])); ?>">Edit</a>
                                <form method="post" action="<?php echo admin_e(app_url('/api/admin/users_delete.php')); ?>" onsubmit="return confirm('Delete <?php echo admin_e($user['name']); ?>?');">
                                    <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
                                    <button class="admin-btn-danger" type="submit">Delete</button>
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

