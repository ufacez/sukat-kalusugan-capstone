<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
require_permission('users.view');

$canViewParents = has_permission('parents.view');
$canAddParent = has_permission('parents.create');
$canEditParent = has_permission('parents.update');
$canArchiveParent = has_permission('parents.delete');
$canChangeAccess = has_permission('roles_permissions.update');

$editId = (int)($_GET['edit'] ?? 0);

if ($editId > 0) {
    admin_redirect('/admin/user_form.php?id=' . $editId);
}

/*
|--------------------------------------------------------------------------
| Staff accounts (admins + nutritionists)
|--------------------------------------------------------------------------
*/

$staff = admin_fetch_all(
    'SELECT u.id, u.name, u.email, u.username, u.phone, u.barangay_id, b.name AS barangay,
            u.status, u.access_level, u.last_login, u.created_at, r.name AS role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     LEFT JOIN barangays b ON b.id = u.barangay_id
     WHERE u.status = \'active\'
     ORDER BY u.created_at DESC, u.id DESC'
);

$adminCount = 0;
$nutritionistCount = 0;

foreach ($staff as $s) {
    if (($s['role_name'] ?? '') === 'admin') {
        $adminCount++;
    } elseif (($s['role_name'] ?? '') === 'nutritionist') {
        $nutritionistCount++;
    }
}

$archivedStaffRow = admin_fetch_one("SELECT COUNT(*) AS cnt FROM users WHERE status = 'inactive'");
$archivedStaffCount = (int)($archivedStaffRow['cnt'] ?? 0);

/*
|--------------------------------------------------------------------------
| Parent / guardian accounts (separate table, merged here for display)
|--------------------------------------------------------------------------
*/

$parents = [];
$totalChildren = 0;
$archivedParentCount = 0;

if ($canViewParents) {
    $parents = admin_fetch_all(
        "SELECT
            p.id,
            p.name,
            p.email,
            p.phone,
            p.barangay_id,
            b.name AS barangay,
            p.status,
            p.created_at,
            COUNT(DISTINCT c.id) AS children_count
         FROM parents p
         LEFT JOIN barangays b ON b.id = p.barangay_id
         LEFT JOIN children c ON c.parent_id = p.id AND c.status = 'active'
         WHERE p.status = 'active'
         GROUP BY p.id, p.name, p.email, p.phone, p.barangay_id, b.name, p.status, p.created_at
         ORDER BY p.id DESC"
    );

    foreach ($parents as $parent) {
        $totalChildren += (int)$parent['children_count'];
    }

    $archivedParentRow = admin_fetch_one("SELECT COUNT(*) AS cnt FROM parents WHERE status = 'inactive'");
    $archivedParentCount = (int)($archivedParentRow['cnt'] ?? 0);
}

$accessLevels = [
    'full'     => ['label' => 'Full Access', 'pill' => 'is-success', 'dot' => '#16a34a'],
    'standard' => ['label' => 'Standard',    'pill' => 'is-success', 'dot' => '#22c55e'],
    'readonly' => ['label' => 'Read Only',   'pill' => 'is-muted',   'dot' => '#64748b'],
];

$actions = '';
if ($canAddParent) {
    $actions .= '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/parent_form.php')) . '">' . admin_action_icon('add') . ' Add parent</a>';
}
if (has_permission('users.create')) {
    $actions .= '<a class="admin-btn" href="' . admin_e(app_url('/admin/invitations.php')) . '">' . admin_action_icon('add') . ' Invite staff</a>';
}

admin_layout_start('User Management', 'Staff and parent accounts in one directory.', 'users', $actions);
?>
<section class="admin-grid-cards">
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Active Users</div>
                <div class="admin-card-value"><?php echo count($staff) + count($parents); ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend is-up"><?php echo $adminCount; ?> admins &middot; <?php echo $nutritionistCount; ?> nutritionists<?php if ($canViewParents): ?> &middot; <?php echo count($parents); ?> parents<?php endif; ?></span>
                </div>
            </div>
        </div>
    </article>
    <?php if ($canViewParents): ?>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Children Linked</div>
                <div class="admin-card-value"><?php echo $totalChildren; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend">Across all households</span>
                </div>
            </div>
        </div>
    </article>
    <?php endif; ?>
    <article class="admin-card">
        <div class="admin-card-row">
            <div class="admin-card-icon is-muted">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m6 4.125l2.25 2.25m0 0l2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
            </div>
            <div class="admin-card-content">
                <div class="admin-card-label">Archived</div>
                <div class="admin-card-value"><?php echo $archivedStaffCount + $archivedParentCount; ?></div>
                <div class="admin-card-meta">
                    <span class="admin-card-trend"><a href="<?php echo admin_e(app_url('/admin/users_archived.php')); ?>" style="color:var(--admin-primary);text-decoration:underline;">Staff</a><?php if ($canViewParents): ?> &middot; <a href="<?php echo admin_e(app_url('/admin/parents_archived.php')); ?>" style="color:var(--admin-primary);text-decoration:underline;">Parents</a><?php endif; ?></span>
                </div>
            </div>
        </div>
    </article>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">User Directory</h2>
            <p class="admin-section-subtitle">Staff and parent accounts. Access levels change inline per staff row.</p>
        </div>
        <div class="admin-toolbar" style="margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <div class="rp-role-pills" role="tablist" aria-label="Filter by account type">
                <button type="button" class="rp-role-pill is-active" data-role-pill="" role="tab" aria-selected="true">All</button>
                <button type="button" class="rp-role-pill" data-role-pill="admin" role="tab" aria-selected="false">Admins</button>
                <button type="button" class="rp-role-pill" data-role-pill="nutritionist" role="tab" aria-selected="false">Nutritionists</button>
                <?php if ($canViewParents): ?>
                <button type="button" class="rp-role-pill" data-role-pill="parent" role="tab" aria-selected="false">Parents</button>
                <?php endif; ?>
            </div>
            <input class="admin-search" type="search" placeholder="Search users" data-admin-filter="#users-table">
        </div>
    </div>

    <div class="admin-table-wrap admin-table-wrap--with-pagination">
        <table class="admin-table" id="users-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Access</th>
                    <th>Detail</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staff as $user): ?>
                    <?php
                    $al = $user['access_level'] ?? 'full';
                    $roleName = (string)($user['role_name'] ?? '');
                    $roleLabel = $roleName === 'admin' ? 'Admin' : 'Nutritionist';
                    ?>
                    <tr data-role="<?php echo admin_e($roleName); ?>" data-filter-text="<?php echo admin_e(strtolower($user['name'] . ' ' . $user['email'] . ' ' . $roleLabel . ' ' . $al . ' ' . (string)($user['barangay'] ?? ''))); ?>">
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span class="admin-avatar" style="background:<?php echo admin_avatar_color($user['name']); ?>;width:32px;height:32px;font-size:0.7rem;"><?php echo admin_initials($user['name']); ?></span>
                                <div>
                                    <div style="font-weight:700;"><?php echo admin_e($user['name']); ?></div>
                                    <div class="admin-mini"><?php echo admin_e($user['email']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="admin-pill <?php echo $roleName === 'admin' ? 'is-warn' : 'is-success'; ?>"><?php echo admin_e($roleLabel); ?></span></td>
                        <td>
                            <div style="position:relative;display:inline-block;">
                                <span class="admin-pill <?php echo admin_e($accessLevels[$al]['pill'] ?? 'is-muted'); ?>"><?php echo admin_e(ucfirst($al)); ?></span>
                                <?php if ($canChangeAccess): ?>
                                <button class="admin-icon-btn rp-dropdown-trigger" title="Change access" type="button" data-user-id="<?php echo (int)$user['id']; ?>" style="width:26px;height:26px;margin-left:4px;vertical-align:middle;"><?php echo admin_action_icon('edit'); ?></button>
                                <div class="rp-dropdown-menu">
                                    <div class="rp-dropdown-label">Access Level</div>
                                    <?php foreach ($accessLevels as $lvlKey => $lvl): ?>
                                        <button class="rp-dropdown-item<?php echo $al === $lvlKey ? ' is-active' : ''; ?>" type="button" data-user-id="<?php echo (int)$user['id']; ?>" data-level="<?php echo admin_e($lvlKey); ?>">
                                            <span class="rp-dropdown-dot" style="background:<?php echo admin_e($lvl['dot']); ?>"></span>
                                            <?php echo admin_e($lvl['label']); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="color:var(--admin-muted);"><?php echo admin_e((string)($user['barangay'] ?? 'All barangays')); ?></td>
                        <td><span class="admin-pill <?php echo $user['status'] === 'active' ? 'is-success' : 'is-muted'; ?>"><?php echo admin_e(ucfirst($user['status'])); ?></span></td>
                        <td><?php
                            $d = (string)($user['created_at'] ?? '');
                            echo $d !== '' ? admin_e(date('M j Y', strtotime($d))) : 'n/a';
                        ?></td>
                        <td>
                            <?php if (has_permission('users.update') || has_permission('users.delete')): ?>
                            <div class="admin-actions">
                                <?php if (has_permission('users.update')): ?>
                                <a class="admin-icon-btn" title="Edit" href="<?php echo admin_e(app_url('/admin/user_form.php?id=' . (int)$user['id'])); ?>"><?php echo admin_action_icon('edit'); ?></a>
                                <?php endif; ?>
                                <?php if (has_permission('users.delete')): ?>
                                <form method="post" action="<?php echo admin_e(app_url('/api/admin/users_archive.php')); ?>" data-admin-confirm="Archive <?php echo admin_e($user['name']); ?>?" style="display:inline;">
                                    <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
                                    <button class="admin-icon-btn admin-icon-btn-danger" title="Archive" type="submit">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m6 4.125l2.25 2.25m0 0l2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($parents as $parent): ?>
                    <?php $kidCount = (int)$parent['children_count']; ?>
                    <tr data-role="parent" data-filter-text="<?php echo admin_e(strtolower($parent['name'] . ' ' . $parent['email'] . ' parent ' . (string)($parent['barangay'] ?? '') . ' ' . (string)($parent['phone'] ?? ''))); ?>">
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span class="admin-avatar" style="background:<?php echo admin_avatar_color($parent['name']); ?>;width:32px;height:32px;font-size:0.7rem;"><?php echo admin_initials($parent['name']); ?></span>
                                <div>
                                    <div style="font-weight:700;"><?php echo admin_e($parent['name']); ?></div>
                                    <div class="admin-mini"><?php echo admin_e($parent['email']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="admin-pill is-info">Parent</span></td>
                        <td><span class="admin-mini">&mdash;</span></td>
                        <td style="color:var(--admin-muted);"><?php echo admin_e((string)($parent['barangay'] ?? 'No barangay')); ?> &middot; <?php echo $kidCount; ?> child<?php echo $kidCount === 1 ? '' : 'ren'; ?></td>
                        <td><span class="admin-pill is-success"><?php echo admin_e(ucfirst($parent['status'])); ?></span></td>
                        <td><?php
                            $d = (string)($parent['created_at'] ?? '');
                            echo $d !== '' ? admin_e(date('M j Y', strtotime($d))) : 'n/a';
                        ?></td>
                        <td>
                            <?php if ($canEditParent || $canArchiveParent): ?>
                            <div class="admin-actions">
                                <?php if ($canEditParent): ?>
                                <a class="admin-icon-btn" title="Edit" href="<?php echo admin_e(app_url('/admin/parent_form.php?id=' . (int)$parent['id'])); ?>"><?php echo admin_action_icon('edit'); ?></a>
                                <?php endif; ?>
                                <?php if ($canArchiveParent): ?>
                                <form method="post" action="<?php echo admin_e(app_url('/api/admin/parents_archive.php')); ?>" data-admin-confirm="Archive <?php echo admin_e($parent['name']); ?>? Linked children are archived too." style="display:inline;">
                                    <input type="hidden" name="id" value="<?php echo (int)$parent['id']; ?>">
                                    <button class="admin-icon-btn admin-icon-btn-danger" title="Archive" type="submit">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m6 4.125l2.25 2.25m0 0l2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<style>
.rp-dropdown-menu{position:absolute;top:calc(100% + 4px);right:0;min-width:190px;background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:9999;padding:4px;opacity:0;visibility:hidden;transform:translateY(-4px);transition:all .15s}
.rp-dropdown-menu.is-open{opacity:1;visibility:visible;transform:translateY(0)}
.rp-dropdown-label{font-size:9px;font-weight:600;color:var(--admin-muted);text-transform:uppercase;letter-spacing:.4px;padding:6px 10px 2px;user-select:none}
#users-table{min-width:760px}
#users-table th,#users-table td{vertical-align:middle}
#users-table td:nth-child(2),#users-table td:nth-child(3),#users-table td:nth-child(5){white-space:nowrap}
#users-table th:last-child,#users-table td:last-child{text-align:center}
#users-table .admin-actions{justify-content:center}
#users-table .rp-dropdown-menu.is-floating{position:fixed;top:auto;right:auto;transform:translateY(-4px)}
#users-table .rp-dropdown-menu.is-floating.is-open{transform:translateY(0)}
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
document.addEventListener('scroll', function() { rpCloseMenus(); }, true);
window.addEventListener('resize', function() { rpCloseMenus(); });

// Type pills: gate the shared admin.js table filter (data-role-filter on
// the table + data-role on rows), then re-run it via the search box.
document.querySelectorAll('[data-role-pill]').forEach(function(pill) {
    pill.addEventListener('click', function() {
        document.querySelectorAll('[data-role-pill]').forEach(function(p) {
            p.classList.remove('is-active');
            p.setAttribute('aria-selected', 'false');
        });
        pill.classList.add('is-active');
        pill.setAttribute('aria-selected', 'true');
        var table = document.getElementById('users-table');
        var search = document.querySelector('[data-admin-filter="#users-table"]');
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
        var wrap = this.parentElement;
        var menu = wrap ? wrap.querySelector('.rp-dropdown-menu') : null;
        if (!menu) return;
        var wasOpen = menu.classList.contains('is-open');
        rpCloseMenus();
        if (wasOpen) return;
        menu.classList.add('is-floating', 'is-open');
        var rect = btn.getBoundingClientRect();
        var mw = menu.offsetWidth || 190;
        var mh = menu.offsetHeight || 150;
        var left = Math.round(rect.left + rect.width / 2 - mw / 2);
        left = Math.max(8, Math.min(left, window.innerWidth - mw - 8));
        var below = Math.round(rect.bottom + 4);
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
