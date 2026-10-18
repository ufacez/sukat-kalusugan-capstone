<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

// Archive / restore (same behavior as the admin endpoints, but scoped to
// the nutritionist's barangay and gated by parents.delete like admin).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $parentId = (int)($_POST['id'] ?? 0);

    if (($action === 'archive' || $action === 'restore') && $parentId > 0) {
        nutritionist_require_write('parents.delete');

        $target = admin_fetch_one('SELECT id, email, barangay_id, status FROM parents WHERE id = ? LIMIT 1', 'i', [$parentId]);

        if ($target === null) {
            admin_redirect('/nutritionist/parents.php', ['notice' => 'Parent not found.', 'type' => 'error']);
        }

        if (($user['role'] ?? '') !== 'admin' && (int)($target['barangay_id'] ?? 0) !== (int)($user['barangay_id'] ?? 0)) {
            admin_redirect('/nutritionist/parents.php', ['notice' => 'You can only manage parents within your assigned barangay.', 'type' => 'error']);
        }

        $newStatus = $action === 'archive' ? 'inactive' : 'active';

        if (($target['status'] ?? '') === $newStatus) {
            admin_redirect('/nutritionist/parents.php', ['notice' => $action === 'archive' ? 'Parent is already archived.' : 'Parent is already active.', 'type' => 'error']);
        }

        $ok = admin_execute('UPDATE parents SET status = ? WHERE id = ?', 'si', [$newStatus, $parentId]);
        $kids = $ok ? admin_cascade_parent_status($parentId, $newStatus) : 0;

        if ($ok) {
            $actor = current_user();
            $actionLabel = $newStatus === 'inactive' ? 'Archived' : 'Restored';
            log_action($actor['id'] ?? null, 'UPDATE_PARENT', 'warning', $actionLabel . ' parent #' . $parentId . ' with ' . $kids . ' child(ren)');
        }

        $backTab = $newStatus === 'inactive' ? '?tab=archived' : '';
        admin_redirect(
            '/nutritionist/parents.php' . $backTab,
            $ok
                ? ['notice' => 'Parent ' . ($newStatus === 'inactive' ? 'archived' : 'restored') . ' successfully' . ($kids > 0 ? ' with ' . $kids . ' child(ren).' : '.')]
                : ['notice' => 'Parent could not be updated.', 'type' => 'error']
        );
    }
}

$tab = (($_GET['tab'] ?? 'active') === 'archived') ? 'archived' : 'active';
$localAreaFilter = (int)($_GET['local_area_id'] ?? 0);

$childScopeParams = [];
$childScope = nutritionist_scope_fragment($user, 'c.barangay_id', $childScopeParams);
$parentScopeParams = [];
$parentScope = nutritionist_scope_fragment($user, 'p.barangay_id', $parentScopeParams);  // restrict parents to user's barangay
$parents = admin_fetch_all(
	"SELECT
		p.id,
		p.name,
		p.email,
		p.parent_code,
		p.parent_type,
		p.phone,
		p.address,
		p.barangay_id,
		bg.name AS barangay,
		p.local_area_id,
		la.area_name AS local_area,
		la.area_type AS local_area_type,
		p.status,
		p.household_id,
		h.household_code AS household_code,
		h.address AS household_address,
		h.lat AS household_lat,
		h.lng AS household_lng,
		COUNT(DISTINCT c.id) AS children_count
	 FROM parents p
	 LEFT JOIN barangays bg ON bg.id = p.barangay_id
	 LEFT JOIN local_areas la ON la.id = p.local_area_id
	 LEFT JOIN households h ON h.id = p.household_id AND h.status = 'active'
     LEFT JOIN children c ON c.parent_id = p.id AND {$childScope}
	 WHERE {$parentScope}" . ($localAreaFilter > 0 ? ' AND p.local_area_id = ?' : '') . "
	 GROUP BY p.id, p.name, p.email, p.parent_code, p.parent_type, p.phone, p.address, p.barangay_id, bg.name, p.local_area_id, la.area_name, la.area_type, p.status, p.household_id, h.household_code, h.address, h.lat, h.lng
	 ORDER BY p.id DESC",
	str_repeat('i', count($childScopeParams) + count($parentScopeParams) + ($localAreaFilter > 0 ? 1 : 0)),
	array_merge($childScopeParams, $parentScopeParams, $localAreaFilter > 0 ? [$localAreaFilter] : [])
);

$activeCount = count(array_filter($parents, static fn(array $parent): bool => (string)$parent['status'] === 'active'));
$totalChildren = array_sum(array_map(static fn(array $parent): int => (int)$parent['children_count'], $parents));

// Tab slice: active vs archived, all within the user's barangay scope.
$tabParents = array_values(array_filter($parents, static fn(array $parent): bool => $tab === 'archived' ? (string)$parent['status'] !== 'active' : (string)$parent['status'] === 'active'));
$countActive = $activeCount;
$countArchived = count($parents) - $activeCount;

// Local area list for the filter dropdown (same scope pattern as children.php).
$localAreaParams = [];
$localAreaScope = nutritionist_scope_fragment($user, 'la.barangay_id', $localAreaParams);
$localAreaList = admin_fetch_all(
	"SELECT la.id, la.area_name, la.area_type, la.barangay_id, bg.name AS barangay
	 FROM local_areas la
	 INNER JOIN barangays bg ON bg.id = la.barangay_id
	 WHERE la.is_active = 1 AND {$localAreaScope}
	 ORDER BY bg.name ASC, la.area_name ASC",
	str_repeat('i', count($localAreaParams)),
	$localAreaParams
);

/* ── Modal data: barangays, households, parent types ─────────────── */
$parentTypes = ['Father', 'Mother', 'Guardian', 'Grandparent', 'Other'];
$isScopedUser = ($user['role'] ?? '') !== 'admin';
$scopedBarangayId = $isScopedUser ? (int)($user['barangay_id'] ?? 0) : 0;
$editBarangays = admin_barangay_options($isScopedUser ? $user : null);
$lockedBarangayName = '';
if ($isScopedUser) {
	foreach ($editBarangays as $_b) {
		if ((int)$_b['id'] === $scopedBarangayId) { $lockedBarangayName = (string)$_b['name']; break; }
	}
}
$editHouseholds = [];
if ($scopedBarangayId > 0) {
	$editHouseholds = admin_fetch_all(
		"SELECT id, household_code, address FROM households WHERE barangay_id = ? AND status = 'active' ORDER BY household_code",
		'i', [$scopedBarangayId]
	);
}

function nutritionist_parents_url(string $tab, ?int $localAreaId = null): string
{
    global $localAreaFilter;
    if ($localAreaId === null) {
        $localAreaId = $localAreaFilter;
    }
    $base = app_url('/nutritionist/parents.php');
    $params = [];
    if ($tab !== '' && $tab !== 'active') {
        $params['tab'] = $tab;
    }
    if ($localAreaId > 0) {
        $params['local_area_id'] = $localAreaId;
    }
    return $params === [] ? $base : $base . '?' . http_build_query($params);
}

$actions = nutritionist_can_write('children.create')
	? '<a class="admin-btn" href="'
		. nutritionist_e(app_url('/nutritionist/family_form.php'))
		. '">' . admin_action_icon('add') . ' Add family</a>'
	: '';
$actions .= (nutritionist_can_write('parents.create') && nutritionist_can_write('children.create'))
	? ' <a class="admin-btn-secondary" href="'
		. nutritionist_e(app_url('/nutritionist/family_import.php'))
		. '">' . admin_action_icon('export') . ' Master-list import</a>'
	: '';

nutritionist_layout_start('Parents', 'Linked guardians and household contact information.', 'parents', $actions);
?>
<style>
.rp-tabs{display:flex;gap:0;border-bottom:2px solid var(--admin-border);margin:0 0 14px}
.rp-tab{display:inline-flex;align-items:center;gap:6px;padding:12px 20px;min-height:44px;font-size:14px;font-weight:600;color:var(--admin-muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s,background .15s;border-radius:8px 8px 0 0}
.rp-tab:hover{color:var(--admin-text);background:var(--admin-surface-alt)}
.rp-tab.is-active{color:var(--admin-primary);border-bottom-color:var(--admin-primary);background:transparent}
.rp-tab span{font-size:12px;opacity:.7}
.parents-toolbar{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.parents-toolbar .admin-search{flex:0 1 280px;max-width:280px;min-width:200px;min-height:44px;font-size:14px}
.parents-toolbar .admin-select{min-width:200px;max-width:260px;min-height:44px;font-size:14px}
#parents-table th:last-child,
#parents-table td:last-child{width:176px;min-width:176px;white-space:nowrap;text-align:center;padding-left:12px;padding-right:12px}
#parents-table td{font-weight:600}
#parents-table td:last-child .admin-actions{justify-content:center;gap:8px}
@media (max-width:560px){
.parents-toolbar{flex-direction:column;align-items:stretch}
.parents-toolbar .admin-search,.parents-toolbar .admin-select{max-width:100%;width:100%;flex:1}
}
</style>
<section class="nutritionist-panel" style="margin-top:0;">
	<div class="parents-toolbar">
		<input class="admin-search" data-admin-filter="#parents-table" type="search" placeholder="Search parents" aria-label="Search parents">
		<select class="admin-select" id="parent-local-area-filter" aria-label="Filter by local area" onchange="window.location.href=this.value">
			<option value="<?php echo nutritionist_e(nutritionist_parents_url($tab, 0)); ?>" <?php echo $localAreaFilter <= 0 ? 'selected' : ''; ?>>All local areas</option>
			<?php foreach ($localAreaList as $la): ?>
				<option value="<?php echo nutritionist_e(nutritionist_parents_url($tab, (int)$la['id'])); ?>" <?php echo $localAreaFilter === (int)$la['id'] ? 'selected' : ''; ?>><?php
					$label = ucfirst((string)$la['area_type']) . ': ' . $la['area_name'];
					if (($user['role'] ?? '') === 'admin' && !empty($la['barangay'])) {
						$label .= ' · ' . $la['barangay'];
					}
					echo nutritionist_e($label);
				?></option>
			<?php endforeach; ?>
		</select>
	</div>

	<div class="rp-tabs">
		<a class="rp-tab <?php echo $tab === 'active' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e(nutritionist_parents_url('active')); ?>">Active <span>(<?php echo (int)$countActive; ?>)</span></a>
		<a class="rp-tab <?php echo $tab === 'archived' ? 'is-active' : ''; ?>" href="<?php echo nutritionist_e(nutritionist_parents_url('archived')); ?>">Archived <span>(<?php echo (int)$countArchived; ?>)</span></a>
	</div>

	<div class="nutritionist-table-wrap">
		<table class="nutritionist-table" id="parents-table" data-page-size="5">
			<thead>
				<tr>
					<th>Name</th>
					<th>Type</th>
					<th>Email</th>
					<th>Phone</th>
					<th>Purok</th>
					<th>Children</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if ($tabParents === []): ?>
					<tr><td colspan="7" style="color:var(--admin-muted);text-align:center;padding:24px;"><?php echo $tab === 'archived' ? 'No archived parents in your scope.' : 'No active parents in your scope yet.'; ?></td></tr>
				<?php endif; ?>
				<?php foreach ($tabParents as $parentIndex => $parent): ?>
					<?php $_np = admin_split_full_name($parent['name']); ?>
					<tr<?php echo admin_paged_row_attr($parentIndex, 5); ?>
						data-filter-text="<?php echo nutritionist_e(strtolower($parent['name'] . ' ' . ($parent['parent_code'] ?? '') . ' ' . $parent['parent_type'] . ' ' . $parent['email'] . ' ' . $parent['phone'] . ' ' . $parent['address'] . ' ' . ($parent['barangay'] ?? '') . ' ' . ($parent['local_area'] ?? ''))); ?>"
						data-parent-id="<?php echo (int)$parent['id']; ?>"
						data-parent-code="<?php echo nutritionist_e((string)($parent['parent_code'] ?? '')); ?>"
						data-parent-name="<?php echo nutritionist_e($parent['name']); ?>"
						data-parent-first-name="<?php echo nutritionist_e($_np['first']); ?>"
						data-parent-middle-name="<?php echo nutritionist_e($_np['middle']); ?>"
						data-parent-last-name="<?php echo nutritionist_e($_np['last']); ?>"
						data-parent-type="<?php echo nutritionist_e($parent['parent_type']); ?>"
						data-parent-email="<?php echo nutritionist_e($parent['email']); ?>"
						data-parent-phone="<?php echo nutritionist_e((string)($parent['phone'] ?? '')); ?>"
						data-parent-address="<?php echo nutritionist_e((string)($parent['address'] ?? '')); ?>"
						data-parent-barangay="<?php echo nutritionist_e((string)($parent['barangay'] ?? '')); ?>"
						data-parent-barangay-id="<?php echo (int)($parent['barangay_id'] ?? 0); ?>"
						data-parent-local-area-id="<?php echo (int)($parent['local_area_id'] ?? 0); ?>"
						data-parent-status="<?php echo nutritionist_e($parent['status']); ?>"
						data-parent-children="<?php echo (int)$parent['children_count']; ?>"
						data-household-id="<?php echo (int)($parent['household_id'] ?? 0); ?>"
						data-household-code="<?php echo nutritionist_e((string)($parent['household_code'] ?? '')); ?>"
						data-household-address="<?php echo nutritionist_e((string)($parent['household_address'] ?? '')); ?>"
						data-household-lat="<?php echo $parent['household_lat'] !== null ? nutritionist_e((string)$parent['household_lat']) : ''; ?>"
						data-household-lng="<?php echo $parent['household_lng'] !== null ? nutritionist_e((string)$parent['household_lng']) : ''; ?>"
					>
						<td>
							<div class="parent-name-cell">
								<span class="parent-avatar"><?php echo nutritionist_e(admin_initials((string)$parent['name'])); ?></span>
								<div class="parent-name-text">
									<div class="parent-name"><?php echo nutritionist_e($parent['name']); ?></div>
									<div class="admin-mini" style="font-family:monospace;"><?php echo nutritionist_e((string)($parent['parent_code'] ?? '')); ?></div>
								</div>
							</div>
						</td>
						<td><span class="admin-pill is-muted"><?php echo nutritionist_e($parent['parent_type']); ?></span></td>
						<td><?php echo nutritionist_e($parent['email']); ?></td>
						<td><?php echo nutritionist_e((string)($parent['phone'] ?? '')); ?></td>
						<td><?php echo !empty($parent['local_area']) ? nutritionist_e((string)$parent['local_area']) : '—'; ?></td>
						<td><?php echo (int)$parent['children_count']; ?></td>
						<td>
							<div class="admin-actions">
								<button type="button" class="admin-icon-btn admin-icon-btn-primary" title="View parent card" data-view-parent-card="<?php echo (int)$parent['id']; ?>"><?php echo admin_action_icon('view'); ?></button>
								<?php if (nutritionist_can_write('parents.update')): ?>
								<button class="admin-icon-btn" title="Edit" type="button" data-edit-parent-btn><?php echo admin_action_icon('edit'); ?></button>
								<?php endif; ?>
								<?php if (nutritionist_can_write('parents.delete')): ?>
									<?php if ($tab === 'archived'): ?>
									<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/parents.php')); ?>" data-admin-confirm="Restore <?php echo nutritionist_e($parent['name']); ?> with all linked children?" style="display:inline;">
										<input type="hidden" name="action" value="restore">
										<input type="hidden" name="id" value="<?php echo (int)$parent['id']; ?>">
										<button class="admin-icon-btn" title="Restore with children" type="submit" style="color:var(--admin-primary,#0b6e4f);"><?php echo admin_action_icon('sync'); ?></button>
									</form>
									<?php else: ?>
									<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/parents.php')); ?>" data-admin-confirm="Archive <?php echo nutritionist_e($parent['name']); ?> with all linked children?" data-admin-confirm-danger style="display:inline;">
										<input type="hidden" name="action" value="archive">
										<input type="hidden" name="id" value="<?php echo (int)$parent['id']; ?>">
										<button class="admin-icon-btn admin-icon-btn-danger" title="Archive with children" type="submit"><?php echo admin_action_icon('archive'); ?></button>
									</form>
									<?php endif; ?>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>

<div class="pc-overlay" id="pc-overlay" aria-hidden="true" role="dialog" aria-modal="true">
	<div class="pc-modal">
		<div class="pc-head">
			<div class="avatar" id="pc-avatar">--</div>
			<div class="meta">
				<div class="name" id="pc-name">Parent name</div>
				<div class="sub" id="pc-sub">—</div>
			</div>
			<button type="button" class="close" id="pc-close" aria-label="Close">×</button>
		</div>
		<div class="pc-body">
			<div class="pc-cols">
				<div class="pc-card">
					<h3 class="pc-section">Contact information</h3>
					<div class="pc-row"><span class="label">Parent ID</span><span class="value" id="pc-code">—</span></div>
					<div class="pc-row"><span class="label">Email</span><span class="value" id="pc-email">—</span></div>
					<div class="pc-row"><span class="label">Phone</span><span class="value" id="pc-phone">—</span></div>
					<div class="pc-row"><span class="label">Type</span><span class="value" id="pc-type">—</span></div>
					<div class="pc-row"><span class="label">Status</span><span class="value" id="pc-status">—</span></div>
					<div class="pc-row"><span class="label">Linked children</span><span class="value" id="pc-children">—</span></div>
				</div>
				<div class="pc-card">
					<h3 class="pc-section">Address</h3>
					<div class="pc-row"><span class="label">Barangay</span><span class="value" id="pc-barangay">—</span></div>
					<div class="pc-row"><span class="label">Street address</span><span class="value" id="pc-address">—</span></div>
				</div>
				<div class="pc-card">
					<h3 class="pc-section">Household / Spot</h3>
					<div class="pc-row"><span class="label">Household code</span><span class="value" id="pc-household-code">—</span></div>
					<div class="pc-row"><span class="label">Address</span><span class="value" id="pc-household-address">—</span></div>
					<div class="pc-row"><span class="label">Coordinates</span><span class="value" id="pc-household-coords">—</span></div>
				</div>
			</div>
		</div>
		<div class="pc-foot">
			<a class="admin-btn-secondary" id="pc-edit" href="#">Edit parent</a>
			<button type="button" class="admin-btn" id="pc-close-2">Close</button>
		</div>
	</div>
</div>

<script>
(function () {
	var overlay = document.getElementById('pc-overlay');
	if (!overlay) return;

	function val(id) { return document.getElementById(id); }
	function text(id, v) { var el = val(id); if (el) el.textContent = (v === null || v === undefined || v === '') ? '—' : v; }

	function openParentCard(row) {
		if (!row) return;
		var get = function (k) { return row.getAttribute('data-parent-' + k) || ''; };
		var getH = function (k) { return row.getAttribute('data-household-' + k) || ''; };

		var name = get('name');
		var initials = (name.split(' ').filter(Boolean).slice(0, 2).map(function (s) { return s.charAt(0).toUpperCase(); }).join('')) || '--';
		val('pc-avatar').textContent = initials;
		val('pc-avatar').style.background = '';

		text('pc-name', name);
		text('pc-sub', get('type') + ' · ' + get('email'));
		text('pc-code', get('code'));

		text('pc-email', get('email'));
		text('pc-phone', get('phone'));
		text('pc-type', get('type'));
		text('pc-status', get('status').charAt(0).toUpperCase() + get('status').slice(1));
		text('pc-children', get('children'));

		text('pc-barangay', get('barangay'));
		text('pc-address', get('address'));

		var hhCode = getH('code');
		var hhAddress = getH('address');
		var hhLat = getH('lat');
		var hhLng = getH('lng');
		text('pc-household-code', hhCode ? ('HH-' + String(getH('id') || '0').padStart(4, '0') + ' · ' + hhCode) : '—');
		text('pc-household-address', hhAddress || '—');
		if (hhLat && hhLng) {
			text('pc-household-coords', parseFloat(hhLat).toFixed(7) + ', ' + parseFloat(hhLng).toFixed(7));
		} else {
			text('pc-household-coords', '—');
		}

		var editLink = val('pc-edit');
		if (editLink) {
			editLink.setAttribute('href', '<?php echo nutritionist_e(app_url('/nutritionist/parent_form.php')); ?>?id=' + (get('id') || ''));
		}

		overlay.classList.add('is-open');
		overlay.setAttribute('aria-hidden', 'false');
		document.body.style.overflow = 'hidden';
	}

	function closeParentCard() {
		overlay.classList.remove('is-open');
		overlay.setAttribute('aria-hidden', 'true');
		document.body.style.overflow = '';
	}

	document.querySelectorAll('[data-view-parent-card]').forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			var row = btn.closest('tr');
			openParentCard(row);
		});
	});

	document.querySelectorAll('#parents-table tr').forEach(function (row) {
		row.addEventListener('click', function (e) {
			if (e.target.closest('a, button, form')) return;
			openParentCard(row);
		});
	});

	['pc-close', 'pc-close-2'].forEach(function (id) {
		var el = document.getElementById(id);
		if (el) el.addEventListener('click', closeParentCard);
	});
	overlay.addEventListener('click', function (e) {
		if (e.target === overlay) closeParentCard();
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeParentCard();
	});
})();
</script>


<!-- ═══ Edit Parent Modal ═══ -->
<div class="admin-edit-modal-overlay" id="edit-parent-overlay" hidden>
<form class="admin-modal admin-modal--form users-edit-modal admin-edit-modal nutritionist-edit-parent-modal" method="post" data-validate-form action="<?php echo nutritionist_e(app_url('/nutritionist/parent_form.php')); ?>" role="dialog" aria-modal="true" aria-labelledby="edit-parent-modal-title">
	<div class="admin-modal-head">
		<div>
			<h3 id="edit-parent-modal-title">Edit parent</h3>
			<p class="admin-edit-modal-subtitle">Update the selected parent/guardian record.</p>
		</div>
		<button class="admin-modal-close" type="button" data-edit-parent-close aria-label="Close">&times;</button>
	</div>
	<div class="admin-edit-modal-body users-edit-body">
		<input type="hidden" name="action" value="update">
		<input type="hidden" name="id" id="ep-id" value="">

		<div class="admin-field-wide admin-flash is-error" data-validate-banner style="display:none;"></div>

		<!-- ── Personal information ── -->
		<section class="admin-edit-card">
			<h3><?php echo admin_sidebar_icon('children'); ?> Personal information</h3>
			<p>The name shown on records and reports.</p>
			<div class="admin-field-row">
				<label class="admin-field">
					<span>First name<span class="admin-required">*</span></span>
					<input id="ep-first-name" name="first_name" required maxlength="60" data-validate="name" data-label="First name" placeholder="Juan">
					<span class="admin-field-message"></span>
				</label>
				<label class="admin-field">
					<span>Middle name</span>
					<input id="ep-middle-name" name="middle_name" maxlength="60" data-validate="name" data-label="Middle name" placeholder="Reyes">
					<span class="admin-field-message"></span>
				</label>
				<label class="admin-field">
					<span>Surname<span class="admin-required">*</span></span>
					<input id="ep-last-name" name="last_name" required maxlength="60" data-validate="name" data-label="Surname" placeholder="Dela Cruz">
					<span class="admin-field-message"></span>
				</label>
			</div>
		</section>

		<!-- ── Contact & type ── -->
		<section class="admin-edit-card">
			<h3><?php echo admin_sidebar_icon('email'); ?> Contact &amp; type</h3>
			<p>How this parent can be reached.</p>
			<div class="admin-field-row">
				<label class="admin-field">
					<span>Email<span class="admin-required">*</span></span>
					<input id="ep-email" type="email" name="email" required data-validate="email" placeholder="juan@example.com">
					<span class="admin-field-message"></span>
				</label>
				<label class="admin-field">
					<span>Mobile number<span class="admin-required">*</span></span>
					<input id="ep-phone" name="phone" required data-validate="phone-ph" placeholder="09171234567">
					<span class="admin-field-message"></span>
				</label>
			</div>
			<div class="admin-field-row" style="margin-top:10px;">
				<label class="admin-field">
					<span>Parent type<span class="admin-required">*</span></span>
					<select id="ep-parent-type" name="parent_type" required data-label="Parent type">
						<?php foreach ($parentTypes as $type): ?>
							<option value="<?php echo nutritionist_e($type); ?>"><?php echo nutritionist_e($type); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="admin-field-message"></span>
				</label>
				<label class="admin-field">
					<span>Status<span class="admin-required">*</span></span>
					<select id="ep-status" name="status" required>
						<option value="active">Active</option>
						<option value="inactive">Inactive</option>
					</select>
				</label>
			</div>
		</section>

		<!-- ── Assignment ── -->
		<section class="admin-edit-card parent-assignment-card">
			<h3><?php echo admin_sidebar_icon('barangays'); ?> Assignment</h3>
			<input type="hidden" name="barangay_id" id="ep-barangay" value="<?php echo (int)$scopedBarangayId; ?>">
			<label class="admin-field">
				<span>Household / Spot</span>
				<select name="household_id" id="ep-household">
					<option value="">-- None --</option>
					<?php foreach ($editHouseholds as $_hh): ?>
						<option value="<?php echo (int)$_hh['id']; ?>"><?php echo nutritionist_e($_hh['household_code']); ?><?php echo $_hh['address'] !== null && $_hh['address'] !== '' ? ' · ' . nutritionist_e($_hh['address']) : ''; ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<input type="hidden" name="local_area_id" id="ep-local-area" value="">
		</section>

		<!-- ── Home address ── -->
		<section class="admin-edit-card">
			<h3><?php echo admin_sidebar_icon('barangays'); ?> Home address</h3>
			<p>Used for the full address on records.</p>
			<div class="admin-address-picker" data-psgc-picker data-psgc-address-target="ep-address">
				<div class="admin-field-row">
					<label class="admin-field">
						<span>Province</span>
						<select data-psgc="province"><option value="">Loading provinces…</option></select>
					</label>
					<label class="admin-field">
						<span>City / Municipality</span>
						<select data-psgc="city" disabled><option value="">-- Select province first --</option></select>
					</label>
					<label class="admin-field">
						<span>Barangay</span>
						<select data-psgc="barangay" disabled><option value="">-- Select city/municipality first --</option></select>
					</label>
					<label class="admin-field">
						<span>Local area / Purok<span class="admin-required">*</span></span>
						<select data-psgc="area" disabled><option value="">-- Select barangay first --</option></select>
					</label>
				</div>
				<label class="admin-field" style="margin-top:10px;">
					<span>House no. / street<span class="admin-required">*</span></span>
					<input data-psgc="street" placeholder="143 Mabini St.">
				</label>
				<div class="admin-address-status" data-psgc-status></div>
				<div class="admin-address-preview" role="status" aria-live="polite">
					<?php echo admin_sidebar_icon('barangays'); ?>
					<div>
						<span class="admin-address-preview-label">Full address</span>
						<textarea id="ep-address" name="address" readonly></textarea>
					</div>
				</div>
			</div>
		</section>

		<!-- ── Actions ── -->
		<div class="admin-edit-actions">
			<button class="admin-btn-secondary" type="button" data-edit-parent-close>Cancel</button>
			<button class="admin-btn" type="submit"><?php echo admin_action_icon('save'); ?> Save changes</button>
		</div>
	</div>
</form>
</div>

<script>
(function () {
	var overlay = document.getElementById('edit-parent-overlay');
	if (!overlay) return;

	var areaApiBase = '<?php echo app_url("/api/admin/local_areas.php"); ?>';
	var scopedBgy = <?php echo (int)$scopedBarangayId; ?>;

	function setVal(id, v) { var el = document.getElementById(id); if (el) el.value = v || ''; }

	function loadLocalAreas(barangayId, selectedId) {
		var sel = document.getElementById('ep-local-area');
		if (!sel) return;
		if (sel.tagName === 'INPUT') return;
		sel.innerHTML = '<option value="">-- Select local area --</option>';
		if (!barangayId || barangayId <= 0) return;
		sel.innerHTML += '<option value="" disabled>Loading…</option>';
		fetch(areaApiBase + '?barangay_id=' + barangayId)
			.then(function (r) { return r.json(); })
			.then(function (res) {
				sel.innerHTML = '<option value="">-- Select local area --</option>';
				if (!res.success || !res.data || res.data.length === 0) {
					sel.innerHTML += '<option value="" disabled>No local areas registered</option>';
					return;
				}
				res.data.forEach(function (area) {
					if (parseInt(area.is_active, 10) !== 1 && parseInt(area.id, 10) !== selectedId) return;
					var opt = document.createElement('option');
					opt.value = area.id;
					opt.textContent = area.area_type.charAt(0).toUpperCase() + area.area_type.slice(1) + ': ' + area.area_name;
					if (selectedId && parseInt(opt.value, 10) === selectedId) opt.selected = true;
					sel.appendChild(opt);
				});
			})
			.catch(function () {
				sel.innerHTML = '<option value="">-- Select local area --</option><option value="" disabled>Failed to load</option>';
			});
	}

	function openEditParent(row) {
		if (!row) return;
		var d = row.dataset;

		setVal('ep-id', d.parentId);
		setVal('ep-first-name', d.parentFirstName);
		setVal('ep-middle-name', d.parentMiddleName);
		setVal('ep-last-name', d.parentLastName);
		setVal('ep-email', d.parentEmail);
		setVal('ep-phone', d.parentPhone);
		setVal('ep-address', d.parentAddress);

		var ptSel = document.getElementById('ep-parent-type');
		if (ptSel) ptSel.value = d.parentType || 'Guardian';

		var stSel = document.getElementById('ep-status');
		if (stSel) stSel.value = d.parentStatus || 'active';

		var hhSel = document.getElementById('ep-household');
		if (hhSel) hhSel.value = d.householdId || '';

		var bgySel = document.getElementById('ep-barangay');
		if (bgySel) bgySel.value = d.parentBarangayId || '';

		var laId = parseInt(d.parentLocalAreaId || '0', 10);
		setVal('ep-barangay', d.parentBarangayId || scopedBgy || '0');
		setVal('ep-local-area', laId);
		var picker = overlay.querySelector('[data-psgc-picker]');
		if (picker) {
			picker.dispatchEvent(new CustomEvent('psgc:hydrate-address', { bubbles: true }));
		}

		overlay.hidden = false;
		document.body.classList.add('admin-modal-open');
		var first = document.getElementById('ep-first-name');
		if (first) first.focus();
	}

	function closeEditParent() {
		overlay.hidden = true;
		document.body.classList.remove('admin-modal-open');
		overlay.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
		overlay.querySelectorAll('.admin-field-message').forEach(function (el) { el.textContent = ''; });
		var banner = overlay.querySelector('[data-validate-banner]');
		if (banner) banner.style.display = 'none';
	}

	// Edit button handlers
	document.querySelectorAll('[data-edit-parent-btn]').forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			openEditParent(btn.closest('tr'));
		});
	});

	// Close handlers
	overlay.querySelectorAll('[data-edit-parent-close]').forEach(function (el) {
		el.addEventListener('click', closeEditParent);
	});
	overlay.addEventListener('click', function (e) {
		if (e.target === overlay) closeEditParent();
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && !overlay.hidden) closeEditParent();
	});

})();
</script>

<?php
nutritionist_layout_end();
