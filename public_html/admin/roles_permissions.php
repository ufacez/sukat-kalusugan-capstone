<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('roles_permissions.view');

$admins = admin_fetch_all(
    'SELECT u.id, u.name, u.email, u.status, u.access_level FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.name = "admin" ORDER BY u.name ASC'
);
$nutritionists = admin_fetch_all(
    'SELECT u.id, u.name, u.email, u.status, u.access_level FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.name = "nutritionist" ORDER BY u.name ASC'
);

$perPage = 5;

$adminPage = max(1, (int)($_GET['admin_page'] ?? 1));
$adminTotal = count($admins);
$adminPages = max(1, (int)ceil($adminTotal / $perPage));
$adminSlice = array_slice($admins, ($adminPage - 1) * $perPage, $perPage);

$nutriPage = max(1, (int)($_GET['nutri_page'] ?? 1));
$nutriTotal = count($nutritionists);
$nutriPages = max(1, (int)ceil($nutriTotal / $perPage));
$nutriSlice = array_slice($nutritionists, ($nutriPage - 1) * $perPage, $perPage);

$accessLevels = [
    'full'     => ['label' => 'Full Access', 'pill' => 'is-success', 'dot' => '#16a34a'],
    'standard' => ['label' => 'Standard',    'pill' => 'is-success', 'dot' => '#22c55e'],
    'readonly' => ['label' => 'Read Only',   'pill' => 'is-muted',   'dot' => '#64748b'],
];

$actions = '';
admin_layout_start('Roles & Permissions', 'Manage per-user access levels. Each user can be set independently.', 'roles_permissions', $actions);
?>

<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-danger">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Administrators</div>
                <div class="admin-card-value"><?php echo $adminTotal; ?></div>
                <div class="admin-card-meta"><span class="admin-card-trend is-up">staff accounts</span></div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon" style="background:rgba(99,102,241,.12);color:#6366f1;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Nutritionists</div>
                <div class="admin-card-value"><?php echo $nutriTotal; ?></div>
                <div class="admin-card-meta"><span class="admin-card-trend is-up">staff accounts</span></div>
            </div>
        </div>
    </article>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon" style="background:rgba(37,99,235,.12);color:#2563eb;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Access Levels</div>
                <div class="admin-card-value">3</div>
                <div class="admin-card-meta"><span class="admin-card-trend">Full &middot; Standard &middot; Read Only</span></div>
            </div>
        </div>
    </article>
</section>

<?php
function rp_render_table(string $key, string $label, array $rows, int $currentPage, int $totalPages, int $total): void
{
    global $accessLevels;
?>
<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title"><?php echo admin_e($label); ?></h2>
            <p class="admin-section-subtitle"><?php echo $total; ?> account(s)</p>
        </div>
        <div class="admin-toolbar" style="margin:0;">
            <input class="admin-search" type="search" placeholder="Search <?php echo admin_e(strtolower($label)); ?>" data-admin-filter="#<?php echo admin_e($key); ?>-table">
        </div>
    </div>

    <div class="admin-table-wrap admin-table-wrap--with-pagination">
        <table class="admin-table" id="<?php echo admin_e($key); ?>-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Access</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="4" style="text-align:center;color:var(--admin-muted);padding:24px;">No accounts found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $user): ?>
                        <?php $al = $user['access_level'] ?? 'full'; ?>
                        <tr data-filter-text="<?php echo admin_e(strtolower($user['name'] . ' ' . $user['email'] . ' ' . $al)); ?>">
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <span class="admin-avatar" style="background:<?php echo admin_e(admin_avatar_color($user['name'])); ?>;width:32px;height:32px;font-size:0.7rem;"><?php echo admin_e(admin_initials($user['name'])); ?></span>
                                    <div>
                                        <div style="font-weight:700;"><?php echo admin_e($user['name']); ?></div>
                                        <div class="admin-mini"><?php echo admin_e($user['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="admin-pill <?php echo admin_e($accessLevels[$al]['pill'] ?? 'is-muted'); ?>" id="access-pill-<?php echo (int)$user['id']; ?>"><?php echo admin_e(ucfirst($al)); ?></span></td>
                            <td><span class="admin-pill is-<?php echo admin_e($user['status'] === 'active' ? 'success' : 'muted'); ?>"><?php echo admin_e(ucfirst($user['status'])); ?></span></td>
                            <td>
                                <div style="position:relative;">
                                    <div class="admin-actions">
                                        <button class="admin-icon-btn rp-dropdown-trigger" title="Change Access" type="button" data-user-id="<?php echo (int)$user['id']; ?>">
                                            <?php echo admin_action_icon('edit'); ?>
                                        </button>
                                    </div>
                                    <div class="rp-dropdown-menu">
                                        <div class="rp-dropdown-label">Access Level</div>
                                        <?php foreach ($accessLevels as $lvlKey => $lvl): ?>
                                            <button class="rp-dropdown-item<?php echo $al === $lvlKey ? ' is-active' : ''; ?>" type="button" data-user-id="<?php echo (int)$user['id']; ?>" data-level="<?php echo admin_e($lvlKey); ?>">
                                                <span class="rp-dropdown-dot" style="background:<?php echo admin_e($lvl['dot']); ?>"></span>
                                                <?php echo admin_e($lvl['label']); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
        <div class="admin-pagination">
            <span class="admin-pagination-status">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
            <div class="admin-pagination-actions">
                <?php
                $baseParam = $key === 'admin' ? 'admin_page' : 'nutri_page';
                $params = $_GET;
                unset($params[$baseParam]);
                $qs = http_build_query($params);
                $prefix = $qs ? $qs . '&' : '';
                ?>
                <a class="admin-icon-btn" href="?<?php echo admin_e($prefix . $baseParam . '=' . ($currentPage - 1)); ?>" <?php echo $currentPage <= 1 ? 'style="pointer-events:none;opacity:.4;"' : ''; ?>>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                </a>
                <div class="admin-pagination-numbers">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a class="admin-page-num<?php echo $i === $currentPage ? ' is-active' : ''; ?>" href="?<?php echo admin_e($prefix . $baseParam . '=' . $i); ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
                <a class="admin-icon-btn" href="?<?php echo admin_e($prefix . $baseParam . '=' . ($currentPage + 1)); ?>" <?php echo $currentPage >= $totalPages ? 'style="pointer-events:none;opacity:.4;"' : ''; ?>>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php
}

rp_render_table('admin', 'Administrators', $adminSlice, $adminPage, $adminPages, $adminTotal);
rp_render_table('nutritionist', 'Nutritionists', $nutriSlice, $nutriPage, $nutriPages, $nutriTotal);
?>

<style>
.rp-dropdown-menu{position:absolute;top:calc(100% + 4px);right:0;min-width:190px;background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:9999;padding:4px;opacity:0;visibility:hidden;transform:translateY(-4px);transition:all .15s}
.rp-dropdown-menu.is-open{opacity:1;visibility:visible;transform:translateY(0)}
.rp-dropdown-label{font-size:9px;font-weight:600;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.4px;padding:6px 10px 2px;user-select:none}
.rp-dropdown-item{display:flex;align-items:center;gap:8px;width:100%;padding:7px 10px;border:none;background:transparent;border-radius:8px;cursor:pointer;font-size:12px;font-weight:500;color:var(--admin-text);font-family:Inter,sans-serif;text-align:left;transition:background .1s}
.rp-dropdown-item:hover{background:var(--admin-surface-alt)}
.rp-dropdown-item.is-active{background:var(--admin-primary-soft);color:var(--admin-primary);font-weight:600}
.rp-dropdown-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
</style>

<script>
document.addEventListener('click', function(e) {
    document.querySelectorAll('.rp-dropdown-menu.is-open').forEach(function(m) {
        if (!m.parentElement.contains(e.target)) m.classList.remove('is-open');
    });
});

document.querySelectorAll('.rp-dropdown-trigger').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var menu = this.closest('div[style]').querySelector('.rp-dropdown-menu');
        var wasOpen = menu.classList.contains('is-open');
        document.querySelectorAll('.rp-dropdown-menu.is-open').forEach(function(m) { m.classList.remove('is-open'); });
        if (!wasOpen) menu.classList.add('is-open');
    });
});

document.querySelectorAll('.rp-dropdown-item').forEach(function(item) {
    item.addEventListener('click', function(e) {
        e.stopPropagation();
        var userId = parseInt(this.dataset.userId);
        var level = this.dataset.level;
        if (!userId || !level) return;

        if (!confirm('Change this user\'s access to ' + level.charAt(0).toUpperCase() + level.slice(1) + '?')) return;

        var apiUrl = '<?php echo admin_e(app_url("/api/admin/user_access_level.php")); ?>';
        fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ user_id: userId, access_level: level })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                window.location.reload();
            } else {
                alert(data.message || 'Failed to update access level.');
            }
        })
        .catch(function() { alert('Network error. Please try again.'); });
    });
});
</script>

<?php
admin_layout_end();
