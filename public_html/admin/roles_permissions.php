<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('roles_permissions.view');

$staff = admin_fetch_all(
    'SELECT u.id, u.name, u.email, u.status, u.access_level, r.name AS role_name FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.name IN ("admin", "nutritionist") ORDER BY r.name ASC, u.name ASC'
);
$adminTotal = 0;
$nutriTotal = 0;
foreach ($staff as $s) {
    if (($s['role_name'] ?? '') === 'admin') $adminTotal++;
    else $nutriTotal++;
}

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

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">Staff Accounts</h2>
            <p class="admin-section-subtitle"><?php echo $adminTotal + $nutriTotal; ?> account(s)</p>
        </div>
        <div class="admin-toolbar" style="margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <div class="rp-role-pills" role="tablist" aria-label="Filter by role">
                <button type="button" class="rp-role-pill is-active" data-role-pill="" role="tab" aria-selected="true">All</button>
                <button type="button" class="rp-role-pill" data-role-pill="admin" role="tab" aria-selected="false">Administrators</button>
                <button type="button" class="rp-role-pill" data-role-pill="nutritionist" role="tab" aria-selected="false">Nutritionists</button>
            </div>
            <input class="admin-search" type="search" placeholder="Search staff" data-admin-filter="#staff-roles-table">
        </div>
    </div>

    <div class="admin-table-wrap admin-table-wrap--with-pagination">
        <table class="admin-table" id="staff-roles-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Access</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staff)): ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--admin-muted);padding:24px;">No accounts found.</td></tr>
                <?php else: ?>
                    <?php foreach ($staff as $user): ?>
                        <?php
                        $al = $user['access_level'] ?? 'full';
                        $roleName = (string)($user['role_name'] ?? '');
                        $roleLabel = $roleName === 'admin' ? 'Admin' : 'Nutritionist';
                        ?>
                        <tr data-role="<?php echo admin_e($roleName); ?>" data-filter-text="<?php echo admin_e(strtolower($user['name'] . ' ' . $user['email'] . ' ' . $roleLabel . ' ' . $al)); ?>">
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <span class="admin-avatar" style="background:<?php echo admin_e(admin_avatar_color($user['name'])); ?>;width:32px;height:32px;font-size:0.7rem;"><?php echo admin_e(admin_initials($user['name'])); ?></span>
                                    <div>
                                        <div style="font-weight:700;"><?php echo admin_e($user['name']); ?></div>
                                        <div class="admin-mini"><?php echo admin_e($user['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="admin-pill <?php echo $roleName === 'admin' ? 'is-danger' : 'is-info'; ?>"><?php echo admin_e($roleLabel); ?></span></td>
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
    </div>
</section>

<style>
.rp-dropdown-menu{position:absolute;top:calc(100% + 4px);right:0;min-width:190px;background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:9999;padding:4px;opacity:0;visibility:hidden;transform:translateY(-4px);transition:all .15s}
.rp-dropdown-menu.is-open{opacity:1;visibility:visible;transform:translateY(0)}
.rp-dropdown-label{font-size:9px;font-weight:600;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.4px;padding:6px 10px 2px;user-select:none}
/* Single-row table alignment: pills sit mid-line with the name cell, pill
columns never wrap, and narrow screens get exactly one horizontal scroll. */
#staff-roles-table{min-width:640px}
#staff-roles-table th,#staff-roles-table td{vertical-align:middle}
#staff-roles-table td:nth-child(2),#staff-roles-table td:nth-child(3),#staff-roles-table td:nth-child(4){white-space:nowrap}
#staff-roles-table th:last-child,#staff-roles-table td:last-child{text-align:center}
#staff-roles-table .admin-actions{justify-content:center}
#staff-roles-table .rp-dropdown-menu.is-floating{position:fixed;top:auto;right:auto;transform:translateY(-4px)}
#staff-roles-table .rp-dropdown-menu.is-floating.is-open{transform:translateY(0)}
.admin-section-head{flex-wrap:wrap}
.admin-section-head .admin-toolbar{flex:1 1 auto;justify-content:flex-end}
.admin-toolbar .admin-search{flex:1 1 200px;min-width:0}
.rp-dropdown-item{display:flex;align-items:center;gap:8px;width:100%;padding:7px 10px;border:none;background:transparent;border-radius:8px;cursor:pointer;font-size:12px;font-weight:500;color:var(--admin-text);font-family:Inter,sans-serif;text-align:left;transition:background .1s}
.rp-dropdown-item:hover{background:var(--admin-surface-alt)}
.rp-dropdown-item.is-active{background:var(--admin-primary-soft);color:var(--admin-primary);font-weight:600}
.rp-dropdown-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.rp-role-pills{display:flex;gap:6px;flex-wrap:wrap}
.rp-role-pill{font-size:12px;font-weight:600;padding:7px 14px;border-radius:999px;border:1px solid var(--admin-border);background:var(--admin-surface);color:var(--admin-muted);cursor:pointer;transition:all .15s;white-space:nowrap}
.rp-role-pill:hover{border-color:rgba(11,110,79,.35);color:var(--admin-primary)}
.rp-role-pill.is-active{background:var(--admin-primary-soft);color:var(--admin-primary);border-color:rgba(11,110,79,.35)}
</style>

<script>
function rpCloseMenus() {
    document.querySelectorAll('.rp-dropdown-menu.is-open').forEach(function(m) {
        m.classList.remove('is-open', 'is-floating');
        m.style.top = '';
        m.style.left = '';
    });
}
document.addEventListener('click', function(e) {
    if (e.target && e.target.closest && e.target.closest('.rp-dropdown-menu')) return;
    document.querySelectorAll('.rp-dropdown-menu.is-open').forEach(function(m) {
        if (!m.parentElement.contains(e.target)) rpCloseMenus();
    });
});
// A fixed menu must not linger while the page or table moves under it.
document.addEventListener('scroll', function() { rpCloseMenus(); }, true);
window.addEventListener('resize', function() { rpCloseMenus(); });

// Role pills: gate the shared admin.js table filter (data-role-filter on
// the table + data-role on rows), then re-run it via the search box.
document.querySelectorAll('[data-role-pill]').forEach(function(pill) {
    pill.addEventListener('click', function() {
        document.querySelectorAll('[data-role-pill]').forEach(function(p) {
            p.classList.remove('is-active');
            p.setAttribute('aria-selected', 'false');
        });
        pill.classList.add('is-active');
        pill.setAttribute('aria-selected', 'true');
        var table = document.getElementById('staff-roles-table');
        var search = document.querySelector('[data-admin-filter="#staff-roles-table"]');
        if (table) {
            if (pill.getAttribute('data-role-pill')) table.setAttribute('data-role-filter', pill.getAttribute('data-role-pill'));
            else table.removeAttribute('data-role-filter');
        }
        if (search) search.dispatchEvent(new Event('input', { bubbles: true }));
    });
});

document.querySelectorAll('.rp-dropdown-trigger').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var cell = this.closest('td');
        var menu = cell ? cell.querySelector('.rp-dropdown-menu') : null;
        if (!menu) return;
        var wasOpen = menu.classList.contains('is-open');
        rpCloseMenus();
        if (wasOpen) return;
        // Float above the table scroll box so the menu is never clipped.
        menu.classList.add('is-floating', 'is-open');
        var rect = btn.getBoundingClientRect();
        var mw = menu.offsetWidth || 190;
        var mh = menu.offsetHeight || 150;
        var left = Math.round(rect.left + rect.width / 2 - mw / 2);
        left = Math.max(8, Math.min(left, window.innerWidth - mw - 8));
        var below = Math.round(rect.bottom + 4);
        // Flip upward when there is no room below the button.
        var top = (below + mh + 8 > window.innerHeight)
            ? Math.max(8, Math.round(rect.top - mh - 4))
            : below;
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
    });
});

document.querySelectorAll('.rp-dropdown-item').forEach(function(item) {
    item.addEventListener('click', function(e) {
        e.stopPropagation();
        rpCloseMenus();
        var userId = parseInt(this.dataset.userId);
        var level = this.dataset.level;
        if (!userId || !level) return;

        var levelLabel = level.charAt(0).toUpperCase() + level.slice(1);
        var proceed = window.SKConfirm
            ? window.SKConfirm('Change this user\'s access to ' + levelLabel + '?', { title: 'Change access', confirmLabel: 'Change access' })
            : Promise.resolve(confirm('Change this user\'s access to ' + levelLabel + '?'));
        proceed.then(function (ok) {
        if (!ok) return;

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
                AdminToast.error(data.message || 'Failed to update access level.');
            }
        })
        .catch(function() { AdminToast.error('Network error. Please try again.'); });
        });
    });
});
</script>

<?php
admin_layout_end();
