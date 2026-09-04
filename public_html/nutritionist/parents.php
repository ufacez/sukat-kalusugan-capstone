<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');
	$parentId = (int)($_POST['id'] ?? 0);

	/*
	 * DELETE
	 */
	if ($action === 'delete' && $parentId > 0) {

		nutritionist_require_write('parents.delete');

		$target = admin_fetch_one('SELECT id, email, barangay_id FROM parents WHERE id = ? LIMIT 1', 'i', [$parentId]);

		if ($target === null) {
			admin_redirect('/nutritionist/parents.php', ['notice' => 'Parent not found.', 'type' => 'error']);
		}

		// Scope check: nutritionists can only archive parents in their assigned barangay
		$userBarangayId = $user['barangay_id'] ?? null;
		if (($user['role'] ?? '') !== 'admin' && $userBarangayId !== null && $userBarangayId !== '' && (int)$target['barangay_id'] !== (int)$userBarangayId) {
			admin_redirect('/nutritionist/parents.php', ['notice' => 'You can only manage parents within your assigned barangay.', 'type' => 'error']);
		}

		// Archive instead of hard delete
		$newStatus = ($target['status'] ?? 'active') === 'active' ? 'inactive' : 'active';
		$ok = admin_execute('UPDATE parents SET status = ? WHERE id = ?', 'si', [$newStatus, $parentId]);

		if ($ok) {
			$actor = current_user();
			$actionLabel = $newStatus === 'inactive' ? 'Archived' : 'Restored';
			log_action($actor['id'] ?? null, 'UPDATE_PARENT', 'warning', $actionLabel . ' parent ' . $target['email'] . ' (' . $parentId . ')');
		}

		admin_redirect('/nutritionist/parents.php', $ok ? ['notice' => 'Parent ' . ($newStatus === 'inactive' ? 'archived' : 'restored') . ' successfully.'] : ['notice' => 'Parent could not be updated.', 'type' => 'error']);
	}

}

$editId = (int)($_GET['edit'] ?? 0);

if ($editId > 0) {
    admin_redirect(
        '/nutritionist/parent_form.php?id=' . $editId
    );
}

$childScopeParams = [];
$childScope = nutritionist_scope_fragment($user, 'c.barangay_id', $childScopeParams);
$parentScopeParams = [];
$parentScope = nutritionist_scope_fragment($user, 'p.barangay_id', $parentScopeParams);  // restrict parents to user's barangay
$parents = admin_fetch_all(
	"SELECT
		p.id,
		p.name,
		p.email,
		p.parent_type,
		p.phone,
		p.address,
		p.barangay_id,
		bg.name AS barangay,
		p.status,
		p.household_id,
		h.household_code AS household_code,
		h.address AS household_address,
		h.lat AS household_lat,
		h.lng AS household_lng,
		COUNT(DISTINCT c.id) AS children_count,
		COUNT(DISTINCT a.id) AS appointment_count,
		SUM(CASE WHEN lm.nutritional_status IS NOT NULL AND lm.nutritional_status NOT IN ('Normal') THEN 1 ELSE 0 END) AS follow_up_count
	 FROM parents p
	 LEFT JOIN barangays bg ON bg.id = p.barangay_id
	 LEFT JOIN households h ON h.id = p.household_id AND h.status = 'active'
     LEFT JOIN children c ON c.parent_id = p.id AND {$childScope}
     LEFT JOIN appointments a ON a.parent_id = p.id
	 LEFT JOIN measurements lm ON lm.id = (
		SELECT m2.id
		FROM measurements m2
		WHERE m2.child_id = c.id
		ORDER BY m2.measurement_date DESC, m2.id DESC
		LIMIT 1
	 )
	 WHERE {$parentScope}
	 GROUP BY p.id, p.name, p.email, p.parent_type, p.phone, p.address, p.barangay_id, bg.name, p.status, p.household_id, h.household_code, h.address, h.lat, h.lng
	 ORDER BY p.name ASC",
	str_repeat('i', count($childScopeParams) + count($parentScopeParams)),
	array_merge($childScopeParams, $parentScopeParams)
);

$activeCount = count(array_filter($parents, static fn(array $parent): bool => (string)$parent['status'] === 'active'));
$totalChildren = array_sum(array_map(static fn(array $parent): int => (int)$parent['children_count'], $parents));
$totalAppointments = array_sum(array_map(static fn(array $parent): int => (int)$parent['appointment_count'], $parents));
$atRiskCount = count(array_filter($parents, static fn(array $parent): bool => (int)$parent['follow_up_count'] > 0));

$actions = nutritionist_can_write('parents.create')
	? '<a class="admin-btn" href="'
		. nutritionist_e(app_url('/nutritionist/parent_form.php'))
		. '">' . admin_action_icon('add') . ' Add parent</a>'
	: '';

nutritionist_layout_start('Parents', 'Linked guardians and household contact information.', 'parents', $actions);
?>
<section class="admin-grid-cards">
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Parents</div>
				<div class="admin-card-value"><?php echo count($parents); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend is-up"><?php echo $activeCount; ?> active accounts</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Children Linked</div>
				<div class="admin-card-value"><?php echo $totalChildren; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Households in scope</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Appointments</div>
				<div class="admin-card-value"><?php echo $totalAppointments; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Parent-requested and nutritionist-created visits</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-danger">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">At-Risk Links</div>
				<div class="admin-card-value"><?php echo $atRiskCount; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Parents with follow-up children</span>
				</div>
			</div>
		</div>
	</article>
</section>

<section class="nutritionist-panel">
	<div class="nutritionist-table-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Parent Directory</h2>
			<p class="admin-section-subtitle">Search, update, and review household records.</p>
		</div>
		<input class="admin-search" data-admin-filter="#parents-table" type="search" placeholder="Search parents" style="min-width:240px;">
	</div>

	<div class="nutritionist-table-wrap">
		<table class="nutritionist-table" id="parents-table">
			<thead>
				<tr>
					<th>Name</th>
					<th>Type</th>
					<th>Email</th>
					<th>Phone</th>
					<th>Barangay</th>
					<th>Children</th>
					<th>Appointments</th>
					<th>Follow-up</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($parents as $parent): ?>
					<tr
						data-filter-text="<?php echo nutritionist_e(strtolower($parent['name'] . ' ' . $parent['parent_type'] . ' ' . $parent['email'] . ' ' . $parent['phone'] . ' ' . $parent['address'])); ?>"
						data-parent-id="<?php echo (int)$parent['id']; ?>"
						data-parent-name="<?php echo nutritionist_e($parent['name']); ?>"
						data-parent-type="<?php echo nutritionist_e($parent['parent_type']); ?>"
						data-parent-email="<?php echo nutritionist_e($parent['email']); ?>"
						data-parent-phone="<?php echo nutritionist_e((string)($parent['phone'] ?? '')); ?>"
						data-parent-address="<?php echo nutritionist_e((string)($parent['address'] ?? '')); ?>"
						data-parent-barangay="<?php echo nutritionist_e((string)($parent['barangay'] ?? '')); ?>"
						data-parent-status="<?php echo nutritionist_e($parent['status']); ?>"
						data-parent-children="<?php echo (int)$parent['children_count']; ?>"
						data-household-id="<?php echo (int)($parent['household_id'] ?? 0); ?>"
						data-household-code="<?php echo nutritionist_e((string)($parent['household_code'] ?? '')); ?>"
						data-household-address="<?php echo nutritionist_e((string)($parent['household_address'] ?? '')); ?>"
						data-household-lat="<?php echo $parent['household_lat'] !== null ? nutritionist_e((string)$parent['household_lat']) : ''; ?>"
						data-household-lng="<?php echo $parent['household_lng'] !== null ? nutritionist_e((string)$parent['household_lng']) : ''; ?>"
					>
						<td>
							<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e($parent['name']); ?></div>
							<div class="admin-mini"><?php echo nutritionist_e((string)($parent['address'] ?? '')); ?></div>
						</td>
						<td style="color:var(--admin-muted);"><span class="admin-pill is-muted"><?php echo nutritionist_e($parent['parent_type']); ?></span></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e($parent['email']); ?></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)($parent['phone'] ?? '')); ?></td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)($parent['barangay'] ?? '')); ?></td>
<td style="color:var(--admin-muted);"><?php echo (int)$parent['children_count']; ?></td>
						<td style="color:var(--admin-muted);"><?php echo (int)$parent['appointment_count']; ?></td>
						<td style="color:var(--admin-muted);"><?php echo (int)$parent['follow_up_count'] ?? 0; ?></td>
						<td>
							<div class="admin-actions">
								<button type="button" class="admin-icon-btn admin-icon-btn-primary" title="View parent card" data-view-parent-card="<?php echo (int)$parent['id']; ?>"><?php echo admin_action_icon('view'); ?></button>
								<?php if (nutritionist_can_write('parents.update')): ?>
								<a class="admin-icon-btn" title="Edit" href="<?php echo nutritionist_e(app_url('/nutritionist/parent_form.php?id=' . (int)$parent['id'])); ?>"><?php echo admin_action_icon('edit'); ?></a>
								<?php endif; ?>
								<?php if (nutritionist_can_write('parents.delete')): ?>
								<form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/parents.php')); ?>" onsubmit="return confirm('Delete <?php echo nutritionist_e($parent['name']); ?>?');" style="display:inline;">
									<input type="hidden" name="action" value="delete">
									<input type="hidden" name="id" value="<?php echo (int)$parent['id']; ?>">
									<button class="admin-icon-btn admin-icon-btn-danger" title="Delete" type="submit"><?php echo admin_action_icon('delete'); ?></button>
								</form>
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
			<div class="pc-section">Contact information</div>
			<div class="pc-row"><span class="label">Email</span><span class="value" id="pc-email">—</span></div>
			<div class="pc-row"><span class="label">Phone</span><span class="value" id="pc-phone">—</span></div>
			<div class="pc-row"><span class="label">Type</span><span class="value" id="pc-type">—</span></div>
			<div class="pc-row"><span class="label">Status</span><span class="value" id="pc-status">—</span></div>
			<div class="pc-row"><span class="label">Linked children</span><span class="value" id="pc-children">—</span></div>

			<div class="pc-section">Address</div>
			<div class="pc-row"><span class="label">Barangay</span><span class="value" id="pc-barangay">—</span></div>
			<div class="pc-row"><span class="label">Street address</span><span class="value" id="pc-address">—</span></div>

			<div class="pc-section">Household / Spot</div>
			<div class="pc-row"><span class="label">Household code</span><span class="value" id="pc-household-code">—</span></div>
			<div class="pc-row"><span class="label">Address</span><span class="value" id="pc-household-address">—</span></div>
			<div class="pc-row"><span class="label">Coordinates</span><span class="value" id="pc-household-coords" style="font-family:monospace;font-size:11px;">—</span></div>
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
		val('pc-avatar').style.background = '#6366f1';

		text('pc-name', name);
		text('pc-sub', get('type') + ' · ' + get('email'));

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

<?php
nutritionist_layout_end();
