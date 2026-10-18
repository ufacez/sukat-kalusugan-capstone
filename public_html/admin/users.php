<?php

require_once __DIR__ . '/../includes/admin_helpers.php';

start_secure_session();
admin_require_access('users.view');

$canViewParents = has_permission('parents.view');
$canAddParent = has_permission('parents.create');
$canEditParent = has_permission('parents.update');
$canArchiveParent = has_permission('parents.delete');
$canChangeAccess = has_permission('roles_permissions.update');
$roles = admin_fetch_all('SELECT name FROM roles ORDER BY name ASC');
$barangays = admin_barangay_options();

$editId = (int)($_GET['edit'] ?? 0);

/*
|--------------------------------------------------------------------------
| Staff accounts (admins + nutritionists)
|--------------------------------------------------------------------------
*/

$staff = admin_fetch_all(
    'SELECT u.id, u.name, u.email, u.username, u.phone, u.address, u.barangay_id, b.name AS barangay,
            u.user_code,
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
            p.parent_code,
            p.barangay_id,
            b.name AS barangay,
            la.area_name AS local_area,
            p.status,
            p.created_at,
            COUNT(DISTINCT c.id) AS children_count
         FROM parents p
         LEFT JOIN barangays b ON b.id = p.barangay_id
         LEFT JOIN local_areas la ON la.id = p.local_area_id
         LEFT JOIN children c ON c.parent_id = p.id AND c.status = 'active'
         WHERE p.status = 'active'
         GROUP BY p.id, p.name, p.email, p.phone, p.parent_code, p.barangay_id, b.name, la.area_name, p.status, p.created_at
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

/*
|--------------------------------------------------------------------------
| New parent/guardian accounts are created through the unified Add Family
| form (/admin/family_form.php) together with their children, matching the
| nutritionist workflow. The old standalone "Add Parent" modal was removed.
|--------------------------------------------------------------------------
*/

$canAddFamily = $canAddParent && has_permission('children.create');

$actions = '';
if ($canAddFamily) {
    $actions .= '<a class="admin-btn-secondary" href="' . admin_e(app_url('/admin/family_form.php')) . '">' . admin_action_icon('add') . ' Add family</a>';
}
if (has_permission('users.create')) {
    $actions .= '<a class="admin-btn" href="' . admin_e(app_url('/admin/invitations.php?invite=open')) . '">' . admin_action_icon('add') . ' Invite staff</a>';
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
                    <span class="admin-card-trend"><a href="<?php echo admin_e(app_url('/admin/archive.php?tab=archived#archive-table-wrap')); ?>" style="color:var(--admin-primary);text-decoration:underline;">Go to Archive &rarr;</a></span>
                </div>
            </div>
        </div>
    </article>
</section>

<section class="admin-section">
    <div class="admin-section-head">
        <div>
            <h2 class="admin-section-title">User Directory</h2>
            <p class="admin-section-subtitle">Staff and parent accounts.</p>
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
                    <th>Code</th>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Access</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staff as $userIndex => $user): ?>
                    <?php
                    $al = $user['access_level'] ?? 'full';
                    $roleName = (string)($user['role_name'] ?? '');
                    $roleLabel = $roleName === 'admin' ? 'Admin' : 'Nutritionist';
                    ?>
                    <tr<?php echo admin_paged_row_attr($userIndex, 10); ?> data-role="<?php echo admin_e($roleName); ?>" data-filter-text="<?php echo admin_e(strtolower($user['name'] . ' ' . $user['email'] . ' ' . ($user['user_code'] ?? '') . ' ' . $roleLabel . ' ' . $al . ' ' . (string)($user['barangay'] ?? ''))); ?>">
                        <td style="font-family:monospace;"><?php echo admin_e((string)($user['user_code'] ?? '')); ?></td>
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
                            <span class="admin-pill <?php echo admin_e($accessLevels[$al]['pill'] ?? 'is-muted'); ?>"><?php echo admin_e(ucfirst($al)); ?></span>
                        </td>
                        <td><span class="admin-pill <?php echo $user['status'] === 'active' ? 'is-success' : 'is-muted'; ?>"><?php echo admin_e(ucfirst($user['status'])); ?></span></td>
                        <td>
                            <?php if (has_permission('users.update') || has_permission('users.delete')): ?>
                            <div class="admin-actions">
                                <?php if (has_permission('users.update')): ?>
                                <button class="admin-icon-btn" title="Edit" type="button"
                                    data-users-edit
                                    data-user-id="<?php echo (int)$user['id']; ?>"
                                    data-name="<?php echo admin_e($user['name']); ?>"
                                    data-email="<?php echo admin_e($user['email']); ?>"
                                    data-username="<?php echo admin_e($user['username']); ?>"
                                    data-phone="<?php echo admin_e($user['phone']); ?>"
                                    data-address="<?php echo admin_e($user['address'] ?? ''); ?>"
                                    data-role="<?php echo admin_e($roleName); ?>"
                                    data-barangay-id="<?php echo (int)($user['barangay_id'] ?? 0); ?>"
                                    data-status="<?php echo admin_e($user['status']); ?>"
                                    data-access-level="<?php echo admin_e($al); ?>"><?php echo admin_action_icon('edit'); ?></button>
                                <?php endif; ?>
                                <?php if (has_permission('users.delete')): ?>
                                <form method="post" action="<?php echo admin_e(app_url('/api/admin/users_archive.php')); ?>" data-admin-confirm="Archive <?php echo admin_e($user['name']); ?>?" style="display:inline;">
                                    <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
                                    <button class="admin-icon-btn admin-icon-btn-danger" title="Archive" type="submit"><?php echo admin_action_icon('archive'); ?></button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($parents as $parentIndex => $parent): ?>
                    <?php $kidCount = (int)$parent['children_count']; ?>
                    <tr<?php echo admin_paged_row_attr($parentIndex + count($staff), 10); ?> data-role="parent" data-filter-text="<?php echo admin_e(strtolower($parent['name'] . ' ' . $parent['email'] . ' ' . ($parent['parent_code'] ?? '') . ' parent ' . (string)($parent['barangay'] ?? '') . ' ' . (string)($parent['phone'] ?? ''))); ?>">
                        <td style="font-family:monospace;"><?php echo admin_e((string)($parent['parent_code'] ?? '')); ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span class="admin-avatar" style="background:var(--admin-primary);color:var(--admin-text-on-primary);width:32px;height:32px;font-size:0.7rem;"><?php echo admin_initials($parent['name']); ?></span>
                                <div>
                                    <div style="font-weight:700;"><?php echo admin_e($parent['name']); ?></div>
                                    <div class="admin-mini"><?php echo admin_e($parent['email']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="admin-pill is-info">Parent</span></td>
                        <td><span class="admin-mini">&mdash;</span></td>
                        <td><span class="admin-pill is-success"><?php echo admin_e(ucfirst($parent['status'])); ?></span></td>
                        <td>
                            <?php if ($canEditParent || $canArchiveParent): ?>
                            <div class="admin-actions">
                                <?php if ($canEditParent): ?>
                                <button class="admin-icon-btn" title="Edit" type="button" data-record-edit-url="<?php echo admin_e(app_url('/admin/parent_form.php?id=' . (int)$parent['id'])); ?>"><?php echo admin_action_icon('edit'); ?></button>
                                <?php endif; ?>

                                <?php if ($canArchiveParent): ?>
                                <form method="post" action="<?php echo admin_e(app_url('/api/admin/parents_archive.php')); ?>" data-admin-confirm="Archive <?php echo admin_e($parent['name']); ?>? Linked children are archived too." style="display:inline;">
                                    <input type="hidden" name="id" value="<?php echo (int)$parent['id']; ?>">
                                    <button class="admin-icon-btn admin-icon-btn-danger" title="Archive" type="submit"><?php echo admin_action_icon('archive'); ?></button>
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

<div class="admin-modal-overlay record-edit-frame-overlay" id="record-edit-frame-overlay" hidden>
    <div class="admin-modal record-edit-frame-modal" role="dialog" aria-modal="true" aria-label="Edit record">
        <div class="admin-modal-head">
            <div><h3>Edit record</h3><p class="users-edit-subtitle">Update the selected record.</p></div>
            <button class="admin-modal-close" type="button" data-record-edit-close aria-label="Close">&times;</button>
        </div>
        <iframe title="Edit record" data-record-edit-frame></iframe>
    </div>
</div>

<?php if (has_permission('users.update')): ?>
<div class="admin-modal-overlay" id="users-edit-overlay" hidden>
    <form class="admin-modal admin-modal--form users-edit-modal" method="post" data-validate-form data-role-barangay-form data-edit-user-form action="<?php echo admin_e(app_url('/api/admin/users_update.php')); ?>">
        <div class="admin-modal-head">
            <div>
                <h3>Edit user</h3>
                <p class="users-edit-subtitle">Update account details and role assignment.</p>
            </div>
            <button class="admin-modal-close" type="button" data-users-edit-close aria-label="Close">&times;</button>
        </div>
        <div class="users-edit-body">
            <input type="hidden" name="id">
            <div class="admin-flash is-error" data-validate-banner style="display:none;"></div>
            <section class="invite-form-card admin-field-wide">
                <h4 class="invite-form-card-title"><?php echo admin_sidebar_icon('users'); ?> Personal information</h4>
                <p>The name shown on records, reports and audit logs.</p>
                <div class="admin-field-row">
                    <label class="admin-field"><span>First name<span class="admin-required">*</span></span><input name="first_name" required data-validate="name" maxlength="60"><span class="admin-field-message"></span></label>
                    <label class="admin-field"><span>Middle name</span><input name="middle_name" data-validate="name" maxlength="60"><span class="admin-field-message"></span></label>
                    <label class="admin-field"><span>Surname<span class="admin-required">*</span></span><input name="last_name" required data-validate="name" maxlength="60"><span class="admin-field-message"></span></label>
                </div>
            </section>
            <section class="invite-form-card admin-field-wide">
                <h4 class="invite-form-card-title"><?php echo admin_sidebar_icon('clipboard'); ?> Account</h4>
                <p>How this person signs in and how they can be reached.</p>
                <div class="admin-field-row">
                    <label class="admin-field"><span>Email<span class="admin-required">*</span></span><input type="email" name="email" required data-validate="email"><span class="admin-field-message"></span></label>
                    <label class="admin-field"><span>Username<span class="admin-required">*</span></span><input name="username" required data-validate="username"><span class="admin-field-message"></span></label>
                </div>
                <label class="admin-field users-edit-phone-field"><span>Mobile number<span class="admin-required">*</span></span><input name="phone" required data-validate="phone-ph"><span class="admin-field-message"></span></label>
            </section>
            <section class="invite-form-card admin-field-wide">
                <h4 class="invite-form-card-title"><?php echo admin_sidebar_icon('key'); ?> Role &amp; access</h4>
                <p>What this person can do and where.</p>
                <div class="admin-field-row">
                    <label class="admin-field"><span>Role<span class="admin-required">*</span></span><select name="role" required><?php foreach ($roles as $role): ?><option value="<?php echo admin_e($role['name']); ?>"><?php echo admin_e(ucfirst($role['name'])); ?></option><?php endforeach; ?></select></label>
                    <label class="admin-field"><span>Barangay scope</span><select name="barangay_id"><option value="">-- All barangays (admin scope) --</option><?php foreach ($barangays as $barangay): ?><option value="<?php echo (int)$barangay['id']; ?>"><?php echo admin_e($barangay['name']); ?></option><?php endforeach; ?></select></label>
                </div>
                <?php if ($canChangeAccess): ?>
                <div class="admin-edit-access">
                    <span class="admin-edit-access-label">Access level<span class="admin-required">*</span></span>
                    <div class="admin-edit-access-options">
                        <?php foreach ($accessLevels as $accessKey => $accessOption): ?>
                        <label class="admin-edit-access-option"><input type="radio" name="access_level" value="<?php echo admin_e($accessKey); ?>" required><strong><?php echo admin_e($accessOption['label'] === 'Read Only' ? 'Read only' : $accessOption['label']); ?></strong><small><?php echo $accessKey === 'full' ? 'View, add, edit and archive records.' : ($accessKey === 'standard' ? 'View, add and edit records.' : 'View records only. No changes.'); ?></small></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <label class="admin-field admin-edit-short-field"><span>Status<span class="admin-required">*</span></span><select name="status" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
            </section>
            <section class="invite-form-card invite-address-card admin-field-wide">
                <h4 class="invite-form-card-title"><?php echo admin_sidebar_icon('barangays'); ?> Home address</h4>
                <div class="admin-address-picker" data-psgc-picker data-psgc-address-target="users_edit_address" data-psgc-street-target="users_edit_house" data-psgc-area-target="users_edit_area">
                    <label class="admin-field"><span>Province</span><select data-psgc="province"><option value="">Loading provinces…</option></select></label>
                    <label class="admin-field"><span>City / Municipality</span><select data-psgc="city" disabled><option value="">-- Select province first --</option></select></label>
                    <label class="admin-field"><span>Barangay</span><select data-psgc="barangay" disabled><option value="">-- Select city/municipality first --</option></select></label>
                    <label class="admin-field"><span>Local area / Purok<span class="admin-required">*</span></span><select id="users_edit_area" data-psgc="area" disabled><option value="">-- Select barangay first --</option></select></label>
                </div>
                <label class="admin-field invite-house-field" style="margin-top:10px;"><span>House no. / street<span class="admin-required">*</span></span><input id="users_edit_house" data-psgc="street" name="house_no" required placeholder="143 Mabini St."><span class="admin-field-message"></span></label>
                <div class="admin-address-status" data-psgc-status></div>
                <div class="admin-address-preview" role="status" aria-live="polite"><?php echo admin_sidebar_icon('barangays'); ?><div><span class="admin-address-preview-label">Full address</span><input id="users_edit_address" name="address" type="text" maxlength="255" readonly placeholder="Your complete address will appear here."></div></div>
            </section>
            <div class="admin-edit-actions"><button class="admin-btn-secondary" type="button" data-users-edit-close>Cancel</button><button class="admin-btn" type="submit" data-edit-user-save hidden><?php echo admin_action_icon('save'); ?> Save changes</button></div>
        </div>
    </form>
</div>
<?php endif; ?>

<style>
#users-table{min-width:680px}
#users-table th,#users-table td{vertical-align:middle}
#users-table td:nth-child(2),#users-table td:nth-child(3){white-space:nowrap}
#users-table th:last-child,#users-table td:last-child{text-align:center}
#users-table .admin-actions{justify-content:center}
body.admin-page .users-edit-modal{width:min(1120px,calc(100vw - 40px)) !important;max-width:min(1120px,calc(100vw - 40px)) !important;max-height:calc(100dvh - 32px);border-radius:18px;overflow:hidden}
.users-edit-modal .admin-modal-head{padding:20px 24px 14px;align-items:flex-start}
.users-edit-modal .admin-modal-head h3{font-size:1.35rem;letter-spacing:-.02em}
.users-edit-subtitle{margin:3px 0 0;color:var(--admin-muted);font-size:.82rem}
.users-edit-body{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-items:start;gap:10px;padding:12px 24px 16px;background:var(--admin-page-gradient-start);max-height:calc(94vh - 66px);overflow-y:auto}
.users-edit-body > section:nth-of-type(1),
.users-edit-body > section:nth-of-type(2){grid-column:auto}
.users-edit-body > section:nth-of-type(3),
.users-edit-body > section:nth-of-type(4),
.users-edit-body > .admin-edit-actions{grid-column:1 / -1}
.users-edit-body .invite-form-card{align-self:start;padding:12px 14px;border:1px solid var(--admin-border);border-radius:14px;background:var(--admin-surface)}
.users-edit-body .invite-form-card-title{margin:0 0 7px;line-height:1.15}
.users-edit-body .invite-form-card > p{margin:0 0 7px;color:var(--admin-muted);font-size:.66rem;line-height:1.2}
.users-edit-body .invite-form-card .admin-field-row{gap:8px}
.users-edit-body .invite-form-card .admin-field{gap:.25rem}
.users-edit-phone-field{max-width:none;margin-top:4px}
.users-edit-body .invite-form-card input,
.users-edit-body .invite-form-card select{height:46px;padding:.65rem .8rem}
.users-edit-body .invite-form-card input,
.users-edit-body .invite-form-card select{border-radius:10px}
.users-edit-body .invite-form-card-title svg{width:17px;height:17px;flex:0 0 17px}
.users-edit-body .admin-field-message{font-size:.66rem;line-height:1.15;min-height:.75em}
.users-edit-body .admin-edit-access{margin-top:6px}
.users-edit-body .admin-edit-access-options{gap:6px}
.users-edit-body .admin-edit-access-option{min-height:58px;padding:8px}
.users-edit-body .admin-edit-actions{margin:2px -24px -16px;padding:10px 24px 12px;border-top:1px solid var(--admin-border);background:var(--admin-surface)}
@media(max-width:620px){
 body.admin-page .users-edit-modal{width:calc(100vw - 24px);max-width:calc(100vw - 24px);max-height:calc(100dvh - 24px)}
 .users-edit-modal .admin-modal-head{padding:22px 18px 16px}
 .users-edit-body{grid-template-columns:minmax(0,1fr);padding:12px 18px 16px}
 .users-edit-body > section:nth-of-type(1),
 .users-edit-body > section:nth-of-type(2),
 .users-edit-body > section:nth-of-type(3),
 .users-edit-body > section:nth-of-type(4),
 .users-edit-body > .admin-edit-actions{grid-column:1}
 .users-edit-body .invite-form-card{padding:14px}
 .users-edit-phone-field{max-width:none}
 .users-edit-body .admin-edit-actions{margin-left:-18px;margin-right:-18px;margin-bottom:-16px;padding-left:18px;padding-right:18px}
}
.admin-section-head{flex-wrap:wrap}
.admin-section-head .admin-toolbar{flex:1 1 auto;justify-content:flex-end}
.admin-toolbar .admin-search{flex:1 1 200px;min-width:0}
.rp-role-pills{display:flex;gap:6px;flex-wrap:wrap}
.rp-role-pill{font-size:12px;font-weight:600;padding:7px 14px;border-radius:999px;border:1px solid var(--admin-border);background:var(--admin-surface);color:var(--admin-muted);cursor:pointer;transition:all .15s;white-space:nowrap}
.rp-role-pill:hover{border-color:rgba(11,110,79,.35);color:var(--admin-primary)}
.rp-role-pill.is-active{background:var(--admin-primary-soft);color:var(--admin-primary);border-color:rgba(11,110,79,.35)}
</style>

<script>
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

var usersEditOverlay = document.getElementById('users-edit-overlay');
var usersEditForm = usersEditOverlay ? usersEditOverlay.querySelector('form') : null;
function closeUsersEditModal() {
    if (!usersEditOverlay) return;
    usersEditOverlay.hidden = true;
    document.body.classList.remove('admin-modal-open');
}
function setUsersField(name, value) {
    var field = usersEditForm ? usersEditForm.querySelector('[name="' + name + '"]') : null;
    if (field) field.value = value || '';
}
document.querySelectorAll('[data-users-edit]').forEach(function(button) {
    button.addEventListener('click', function() {
        if (!usersEditForm) return;
        var fullName = (this.dataset.name || '').trim().split(/\s+/);
        setUsersField('id', this.dataset.userId);
        setUsersField('first_name', fullName.shift() || '');
        setUsersField('last_name', fullName.length ? fullName.pop() : '');
        setUsersField('middle_name', fullName.join(' '));
        setUsersField('email', this.dataset.email);
        setUsersField('username', this.dataset.username);
        setUsersField('phone', this.dataset.phone);
        setUsersField('address', this.dataset.address);
        setUsersField('role', this.dataset.role);
        setUsersField('barangay_id', this.dataset.barangayId === '0' ? '' : this.dataset.barangayId);
        setUsersField('status', this.dataset.status);
        usersEditForm.querySelectorAll('[name="access_level"]').forEach(function(radio) {
            radio.checked = radio.value === (button.dataset.accessLevel || 'full');
            var card = radio.closest('.admin-edit-access-option');
            if (card) card.classList.toggle('is-selected', radio.checked);
        });
        var picker = usersEditForm.querySelector('[data-psgc-picker]');
        if (picker) picker.dispatchEvent(new CustomEvent('psgc:hydrate-address', { bubbles: true }));
        usersEditOverlay.hidden = false;
        document.body.classList.add('admin-modal-open');
        var firstField = usersEditForm.querySelector('[name="first_name"]');
        if (firstField) firstField.focus();
    });
});
if (usersEditOverlay) {
    usersEditOverlay.querySelectorAll('[data-users-edit-close]').forEach(function(button) {
        button.addEventListener('click', closeUsersEditModal);
    });
    usersEditOverlay.addEventListener('click', function(event) {
        if (event.target === usersEditOverlay) closeUsersEditModal();
    });
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && !usersEditOverlay.hidden) closeUsersEditModal();
    });
}
var recordEditOverlay = document.getElementById('record-edit-frame-overlay');
var recordEditFrame = recordEditOverlay ? recordEditOverlay.querySelector('[data-record-edit-frame]') : null;
function closeRecordEditModal() {
    if (!recordEditOverlay) return;
    recordEditOverlay.hidden = true;
    if (recordEditFrame) recordEditFrame.src = 'about:blank';
    document.body.classList.remove('admin-modal-open');
}
document.querySelectorAll('[data-record-edit-url]').forEach(function(button) {
    button.addEventListener('click', function() {
        if (!recordEditOverlay || !recordEditFrame) return;
        recordEditFrame.src = this.getAttribute('data-record-edit-url') + '&modal=1';
        recordEditOverlay.hidden = false;
        document.body.classList.add('admin-modal-open');
    });
});
if (recordEditOverlay) {
    recordEditOverlay.querySelectorAll('[data-record-edit-close]').forEach(function(button) {
        button.addEventListener('click', closeRecordEditModal);
    });
    recordEditOverlay.addEventListener('click', function(event) {
        if (event.target === recordEditOverlay) closeRecordEditModal();
    });
}
</script>


<?php
admin_layout_end();
