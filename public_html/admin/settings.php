<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('settings.view');

$actor = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($name === '' || $email === '' || $username === '') {
        admin_redirect('/admin/settings.php', ['notice' => 'Name, email, and username are required.', 'type' => 'error']);
    }

    $current = admin_fetch_one('SELECT password_hash FROM users WHERE id = ? LIMIT 1', 'i', [(int)$actor['id']]);

    if ($current === null) {
        admin_redirect('/admin/settings.php', ['notice' => 'Your account could not be loaded.', 'type' => 'error']);
    }

    // Only setting a new password requires proving you know the current one —
    // editing name/email/username/phone doesn't. This stops someone with a
    // hijacked or left-open session from silently locking the real owner out.
    if ($password !== '') {
        if ($currentPassword === '' || !password_verify($currentPassword, (string)$current['password_hash'])) {
            admin_redirect('/admin/settings.php', ['notice' => 'Current password is incorrect.', 'type' => 'error']);
        }
    }

    $sql = 'UPDATE users SET name = ?, email = ?, username = ?, phone = ?';
    $params = [$name, $email, $username, $phone];
    $types = 'ssss';

    if ($password !== '') {
        $sql .= ', password_hash = ?';
        $params[] = password_hash($password, PASSWORD_DEFAULT);
        $types .= 's';
    }

    $sql .= ' WHERE id = ?';
    $params[] = (int)$actor['id'];
    $types .= 'i';

    $ok = admin_execute($sql, $types, $params);

    if ($ok) {
        $_SESSION['auth']['name'] = $name;
        $_SESSION['auth']['email'] = $email;
        $_SESSION['auth']['username'] = $username;
        log_action((int)$actor['id'], 'UPDATE_OWN_ACCOUNT', 'info', 'Admin updated their own account details');
    }

    admin_redirect('/admin/settings.php', $ok ? ['notice' => 'Account updated successfully.', 'type' => 'success'] : ['notice' => 'Account could not be updated. Check for a duplicate email or username.', 'type' => 'error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    require_permission('settings.update');

    foreach ((array)($_POST['setting'] ?? []) as $key => $value) {
        admin_execute('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?', 'ss', [trim((string)$value), (string)$key]);
    }

    log_action((current_user()['id'] ?? null), 'UPDATE_SETTINGS', 'info', 'Updated system settings');
    admin_redirect('/admin/settings.php', ['notice' => 'Settings saved successfully.', 'type' => 'success']);
}

$settings = admin_fetch_all('SELECT setting_key, setting_value, value_type, description FROM system_settings ORDER BY setting_key ASC');
$settingMap = [];

foreach ($settings as $setting) {
    $settingMap[$setting['setting_key']] = $setting;
}

$myAccount = admin_fetch_one('SELECT name, email, username, phone FROM users WHERE id = ? LIMIT 1', 'i', [(int)$actor['id']]);

$actions = '<div class="admin-muted-block">Changes persist in the system_settings table.</div>';

admin_layout_start('Settings', 'Adjust global app configuration and sync behavior.', 'settings', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-stat-label">Settings</div>
        <div class="admin-stat-value"><?php echo count($settings); ?></div>
        <div class="admin-stat-note">Stored configuration keys</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Mode</div>
        <div class="admin-stat-value"><?php echo (($settingMap['maintenance_mode']['setting_value'] ?? '0') === '1') ? 'Locked' : 'Open'; ?></div>
        <div class="admin-stat-note">Maintenance switch</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Sync</div>
        <div class="admin-stat-value"><?php echo admin_e((string)($settingMap['sync_interval_minutes']['setting_value'] ?? '15')); ?>m</div>
        <div class="admin-stat-note">Telemetry interval</div>
    </article>
    <article class="admin-card">
        <div class="admin-stat-label">Brand</div>
        <div class="admin-stat-value"><?php echo admin_e((string)($settingMap['app_name']['setting_value'] ?? 'App')); ?></div>
        <div class="admin-stat-note">Application name</div>
    </article>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">My Account</h2>
            <p class="admin-section-subtitle">Update your own profile and password.</p>
        </div>
    </div>

    <form class="admin-form-grid" method="post">
        <input type="hidden" name="update_account" value="1">
        <label class="admin-field">
            <span>Full name</span>
            <input name="name" required value="<?php echo admin_e($myAccount['name'] ?? ''); ?>">
        </label>
        <label class="admin-field">
            <span>Email</span>
            <input type="email" name="email" required value="<?php echo admin_e($myAccount['email'] ?? ''); ?>">
        </label>
        <label class="admin-field">
            <span>Username</span>
            <input name="username" required value="<?php echo admin_e($myAccount['username'] ?? ''); ?>">
        </label>
        <label class="admin-field">
            <span>Phone</span>
            <input name="phone" value="<?php echo admin_e($myAccount['phone'] ?? ''); ?>" placeholder="0917...">
        </label>
        <label class="admin-field">
            <span>Current Password</span>
            <input type="password" name="current_password" autocomplete="current-password" placeholder="Only needed if setting a new password below">
        </label>
        <label class="admin-field">
            <span>New Password</span>
            <input type="password" name="password" autocomplete="new-password" placeholder="Leave blank to keep current password">
        </label>
        <div class="admin-field" style="align-content:end;">
            <span>&nbsp;</span>
            <button class="admin-btn" type="submit">Save account</button>
        </div>
    </form>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">System Settings</h2>
            <p class="admin-section-subtitle">Update values and save them to the database.</p>
        </div>
    </div>

    <form method="post" class="admin-list">
        <input type="hidden" name="save_settings" value="1">
        <?php foreach ($settings as $setting): ?>
            <?php
            $isBoolean = $setting['value_type'] === 'boolean';
            $isNumber = $setting['value_type'] === 'number';
            ?>
            <div class="admin-check-card">
                <div class="admin-section-head" style="margin-bottom:10px;">
                    <div>
                        <h3 class="admin-section-title" style="font-size:1rem;"><?php echo admin_e($setting['setting_key']); ?></h3>
                        <p class="admin-section-subtitle"><?php echo admin_e($setting['description'] ?? ''); ?></p>
                    </div>
                    <span class="admin-pill is-muted"><?php echo admin_e($setting['value_type']); ?></span>
                </div>
                <label class="admin-field">
                    <span>Value</span>
                    <?php if ($isBoolean): ?>
                        <select name="setting[<?php echo admin_e($setting['setting_key']); ?>]">
                            <option value="1" <?php echo $setting['setting_value'] === '1' ? 'selected' : ''; ?>>Enabled</option>
                            <option value="0" <?php echo $setting['setting_value'] === '0' ? 'selected' : ''; ?>>Disabled</option>
                        </select>
                    <?php else: ?>
                        <input type="<?php echo $isNumber ? 'number' : 'text'; ?>" name="setting[<?php echo admin_e($setting['setting_key']); ?>]" value="<?php echo admin_e($setting['setting_value']); ?>">
                    <?php endif; ?>
                </label>
            </div>
        <?php endforeach; ?>
        <div>
            <button class="admin-btn" type="submit">Save settings</button>
        </div>
    </form>
</section>
<?php
admin_layout_end();

