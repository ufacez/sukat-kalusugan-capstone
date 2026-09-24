<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

$editId = (int)($_GET['edit'] ?? 0);

if ($editId > 0) {
    admin_redirect(
        '/nutritionist/parent_form.php?id=' . $editId
    );
}

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
		COUNT(DISTINCT c.id) AS children_count,
		COUNT(DISTINCT a.id) AS appointment_count,
		SUM(CASE WHEN lm.nutritional_status IS NOT NULL AND lm.nutritional_status NOT IN ('Normal') THEN 1 ELSE 0 END) AS follow_up_count
	 FROM parents p
	 LEFT JOIN barangays bg ON bg.id = p.barangay_id
	 LEFT JOIN local_areas la ON la.id = p.local_area_id
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
	 WHERE {$parentScope}" . ($localAreaFilter > 0 ? ' AND p.local_area_id = ?' : '') . "
	 GROUP BY p.id, p.name, p.email, p.parent_type, p.phone, p.address, p.barangay_id, bg.name, p.local_area_id, la.area_name, la.area_type, p.status, p.household_id, h.household_code, h.address, h.lat, h.lng
	 ORDER BY p.id DESC",
	str_repeat('i', count($childScopeParams) + count($parentScopeParams) + ($localAreaFilter > 0 ? 1 : 0)),
	array_merge($childScopeParams, $parentScopeParams, $localAreaFilter > 0 ? [$localAreaFilter] : [])
);

$activeCount = count(array_filter($parents, static fn(array $parent): bool => (string)$parent['status'] === 'active'));
$totalChildren = array_sum(array_map(static fn(array $parent): int => (int)$parent['children_count'], $parents));
$totalAppointments = array_sum(array_map(static fn(array $parent): int => (int)$parent['appointment_count'], $parents));
$atRiskCount = count(array_filter($parents, static fn(array $parent): bool => (int)$parent['follow_up_count'] > 0));

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
					<th>Barangay</th>
					<th>Children</th>
					<th>Appointments</th>
					<th>Follow-up</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if ($tabParents === []): ?>
					<tr><td colspan="9" style="color:var(--admin-muted);text-align:center;padding:24px;"><?php echo $tab === 'archived' ? 'No archived parents in your scope.' : 'No active parents in your scope yet.'; ?></td></tr>
				<?php endif; ?>
				<?php foreach ($tabParents as $parentIndex => $parent): ?>
					<tr<?php echo admin_paged_row_attr($parentIndex, 5); ?>
						data-filter-text="<?php echo nutritionist_e(strtolower($parent['name'] . ' ' . $parent['parent_type'] . ' ' . $parent['email'] . ' ' . $parent['phone'] . ' ' . $parent['address'] . ' ' . ($parent['barangay'] ?? '') . ' ' . ($parent['local_area'] ?? ''))); ?>"
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
