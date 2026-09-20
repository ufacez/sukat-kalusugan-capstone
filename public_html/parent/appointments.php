<?php

require_once __DIR__ . '/../includes/parent_helpers.php';

$user = parent_require_access();

$children = admin_fetch_all(
	'SELECT id, first_name, last_name, child_code, sex
	 FROM children
	 WHERE parent_id = ?
	 ORDER BY last_name ASC, first_name ASC',
	'i',
	[(int)$user['id']]
);

$selectedChildId = (int)($_GET['child_id'] ?? 0);
$selectedChild = null;
foreach ($children as $child) {
	if ((int)$child['id'] === $selectedChildId) {
		$selectedChild = $child;
		break;
	}
}
if ($selectedChild === null && $children !== []) {
	$selectedChild = $children[0];
	$selectedChildId = (int)$selectedChild['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');
	$appointmentId = (int)($_POST['id'] ?? 0);
	// Keep the selected-child filter after the redirect.
	$backChildId = (int)($_POST['child_id'] ?? 0);
	$backUrl = '/parent/appointments.php' . ($backChildId > 0 ? '?child_id=' . $backChildId : '');

	if ($action === 'cancel' && $appointmentId > 0) {
		$ok = admin_execute(
			'UPDATE appointments
			 SET status = ?
			 WHERE id = ? AND parent_id = ? AND status IN (?, ?)',
			'siiss',
			['cancelled', $appointmentId, (int)$user['id'], 'pending', 'confirmed']
		);

		admin_redirect($backUrl, $ok ? ['notice' => 'Appointment cancelled.'] : ['notice' => 'Appointment could not be cancelled.', 'type' => 'error']);
	}

	if ($action === 'confirm' && $appointmentId > 0) {
		$ok = admin_execute(
			'UPDATE appointments
			 SET status = ?
			 WHERE id = ? AND parent_id = ? AND created_by = ? AND status = ?',
			'siiss',
			['confirmed', $appointmentId, (int)$user['id'], 'nutritionist', 'pending']
		);

		admin_redirect($backUrl, $ok ? ['notice' => 'Appointment confirmed.'] : ['notice' => 'Appointment could not be confirmed.', 'type' => 'error']);
	}
}

$appointments = admin_fetch_all(
	'SELECT
		a.id,
		a.child_id,
		a.scheduled_at,
		a.status,
		a.notes,
		a.recommendations,
		a.created_by,
		a.location,
		c.first_name,
		c.last_name,
		c.child_code,
		c.sex,
		u.name AS nutritionist_name,
		b.name AS nutritionist_barangay
	 FROM appointments a
	 INNER JOIN children c ON c.id = a.child_id
	 LEFT JOIN users u ON u.id = a.nutritionist_id
	 LEFT JOIN barangays b ON b.id = u.barangay_id
	 WHERE a.parent_id = ?
	 ORDER BY a.scheduled_at DESC, a.id DESC',
	'i',
	[(int)$user['id']]
);

$now = new DateTimeImmutable();
$myRequests = [];
$past = [];

foreach ($appointments as $appt) {
	$status = (string)$appt['status'];
	$isPast = in_array($status, ['completed', 'cancelled'], true);

	if ($isPast) {
		if ($selectedChildId === 0 || (int)$appt['child_id'] === $selectedChildId) $past[] = $appt;
	} else {
		if ($selectedChildId === 0 || (int)$appt['child_id'] === $selectedChildId) $myRequests[] = $appt;
	}
}

usort($myRequests, static fn(array $a, array $b): int => strcmp((string)$a['scheduled_at'], (string)$b['scheduled_at']));
usort($past, static fn(array $a, array $b): int => strcmp((string)$b['scheduled_at'], (string)$a['scheduled_at']));

// ── Pagination: 5 per list ──
$perPage = 5;
$pageUp = max(1, (int)($_GET['page_up'] ?? 1));
$pagePast = max(1, (int)($_GET['page_past'] ?? 1));
$totalUpPages = max(1, (int)ceil(count($myRequests) / $perPage));
$totalPastPages = max(1, (int)ceil(count($past) / $perPage));
if ($pageUp > $totalUpPages) $pageUp = $totalUpPages;
if ($pagePast > $totalPastPages) $pagePast = $totalPastPages;
$myRequestsPage = array_slice($myRequests, ($pageUp - 1) * $perPage, $perPage);
$pastPage = array_slice($past, ($pagePast - 1) * $perPage, $perPage);

$pageLink = function (string $key, int $p) use ($selectedChildId, $pageUp, $pagePast): string {
	$params = [];
	if ($selectedChildId > 0) $params['child_id'] = $selectedChildId;
	$up = $key === 'up' ? $p : $pageUp;
	$pp = $key === 'past' ? $p : $pagePast;
	if ($up > 1) $params['page_up'] = $up;
	if ($pp > 1) $params['page_past'] = $pp;
	return app_url('/parent/appointments.php' . ($params === [] ? '' : '?' . http_build_query($params)));
};

$allJson = [];
foreach ($appointments as $appt) {
	$dt = new DateTimeImmutable((string)$appt['scheduled_at']);
	$fromNutritionist = ($appt['created_by'] ?? '') === 'nutritionist';
	$allJson[(int)$appt['id']] = [
		'id' => (int)$appt['id'],
		'date' => $dt->format('F j, Y'),
		'time' => $dt->format('g:i A'),
		'requested_by' => $fromNutritionist ? ('Nutritionist (' . (string)($appt['nutritionist_name'] ?? '') . ')') : 'You',
		'child' => parent_e($appt['first_name'] . ' ' . $appt['last_name']),
		'child_avatar' => strtoupper(substr((string)$appt['first_name'], 0, 1)),
		'sex' => (string)$appt['sex'],
		'location' => parent_e((string)($appt['location'] ?? 'Barangay Health Center')),
		'nutritionist' => parent_e((string)($appt['nutritionist_name'] ?? 'Unassigned nutritionist')),
		'barangay' => parent_e((string)($appt['nutritionist_barangay'] ?? '')),
		'status' => ucfirst((string)$appt['status']),
		'status_class' => parent_status_class((string)$appt['status']),
		'notes' => parent_e((string)($appt['notes'] ?? '')),
		'recommendations' => parent_e((string)($appt['recommendations'] ?? '')),
		'can_confirm' => $fromNutritionist && (string)$appt['status'] === 'pending',
		'can_cancel' => in_array((string)$appt['status'], ['pending', 'confirmed'], true),
	];
}

$actions = '<a class="admin-btn" href="' . parent_e(app_url('/parent/appointment_form.php')) . '">' . admin_action_icon('add') . ' Request appointment</a>';

parent_layout_start('Appointments', 'Keep track of your child\'s scheduled visits.', 'appointments', $actions);
?>
<style>
.parent-appt-pagination{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:12px 2px 0;font-size:12px;color:var(--admin-muted);flex-wrap:wrap}
.parent-appt-pages{display:flex;align-items:center;gap:8px}
</style>


<section class="parent-appointments-intro">
	<button type="button" class="parent-appointment-child-card" data-appointment-child-open aria-haspopup="dialog" aria-controls="appointment-child-picker">
		<span class="parent-child-avatar" style="background:<?php echo parent_e(child_avatar_color($selectedChild !== null ? (string)($selectedChild['sex'] ?? '') : '')); ?>;" aria-hidden="true"><?php echo $selectedChild !== null ? parent_e(strtoupper(substr((string)$selectedChild['first_name'], 0, 1))) : '?'; ?></span>
		<span class="parent-appointment-child-name"><?php echo $selectedChild !== null ? parent_e($selectedChild['first_name'] . ' ' . $selectedChild['last_name']) : 'All children'; ?></span>
		<span class="parent-appointment-child-arrow" aria-hidden="true">&#9662;</span>
	</button>
	<div class="parent-appointment-tabs" role="tablist" aria-label="Appointment status">
		<button type="button" class="is-active" data-appointment-tab="upcoming">My Requests</button>
		<button type="button" data-appointment-tab="past">Past</button>
	</div>
</section>

<div class="parent-child-picker" id="appointment-child-picker" role="dialog" aria-modal="true" aria-labelledby="appointment-child-picker-title" hidden>
	<div class="parent-child-picker-backdrop" data-appointment-child-close></div>
	<div class="parent-child-picker-sheet">
		<div class="parent-child-picker-header"><div><h2 id="appointment-child-picker-title">Choose a child</h2><p>Show appointments for this child.</p></div><button type="button" class="parent-child-picker-close" data-appointment-child-close aria-label="Close child picker">&times;</button></div>
		<div class="parent-child-picker-list">
			<?php foreach ($children as $child): ?>
				<a class="parent-child-option <?php echo (int)$child['id'] === $selectedChildId ? 'is-selected' : ''; ?>" href="<?php echo parent_e(app_url('/parent/appointments.php?child_id=' . (int)$child['id'])); ?>"><span class="parent-child-option-avatar" style="background:<?php echo parent_e(child_avatar_color((string)($child['sex'] ?? ''))); ?>;" aria-hidden="true"><?php echo parent_e(strtoupper(substr((string)$child['first_name'], 0, 1))); ?></span><span class="parent-child-option-copy"><strong><?php echo parent_e($child['first_name'] . ' ' . $child['last_name']); ?></strong><small><?php echo parent_e($child['child_code']); ?></small></span></a>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<div class="parent-appt-tab-panel is-active" data-appointment-panel="upcoming">
<?php if (!empty($myRequests)): ?>
<div class="parent-appt-list">
	<?php foreach ($myRequestsPage as $appt):
		$dt = new DateTimeImmutable((string)$appt['scheduled_at']);
		$statusClass = parent_status_class((string)$appt['status']);
		$statusLabel = ucfirst((string)$appt['status']);
		$status = (string)$appt['status'];
		$fromNutritionist = ($appt['created_by'] ?? '') === 'nutritionist';
	?>
	<div class="parent-appt-row" data-appointment-id="<?php echo (int)$appt['id']; ?>">
		<div class="parent-appt-row-icon <?php echo $status === 'confirmed' ? 'is-done' : ''; ?>">
			<?php if ($status === 'confirmed'): ?>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
			<?php elseif ($status === 'cancelled'): ?>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/></svg>
			<?php else: ?>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
			<?php endif; ?>
		</div>
		<div class="parent-appt-row-info">
			<div class="parent-appt-row-date"><?php echo parent_e($dt->format('F j, Y')); ?> · <?php echo parent_e($dt->format('g:i A')); ?></div>
			<div class="parent-appt-row-type"><?php echo $fromNutritionist ? 'Requested by nutritionist' : 'Requested by you'; ?></div>
			<div class="parent-appt-row-child"><?php echo parent_e($appt['first_name'] . ' ' . $appt['last_name']); ?></div>
		</div>
		<div class="parent-appt-row-status">
			<span class="admin-pill <?php echo $statusClass; ?>"><?php echo parent_e($statusLabel); ?></span>
		</div>
	</div>
	<?php endforeach; ?>
</div>
<?php if ($totalUpPages > 1): ?>
<div class="parent-appt-pagination">
	<span>Showing <?php echo (($pageUp - 1) * $perPage + 1); ?>–<?php echo min($pageUp * $perPage, count($myRequests)); ?> of <?php echo count($myRequests); ?></span>
	<div class="parent-appt-pages">
		<a class="admin-btn-secondary" href="<?php echo parent_e($pageLink('up', $pageUp - 1)); ?>" <?php echo $pageUp <= 1 ? 'style="pointer-events:none;opacity:.4;"' : ''; ?>>Prev</a>
		<span>Page <?php echo $pageUp; ?> of <?php echo $totalUpPages; ?></span>
		<a class="admin-btn-secondary" href="<?php echo parent_e($pageLink('up', $pageUp + 1)); ?>" <?php echo $pageUp >= $totalUpPages ? 'style="pointer-events:none;opacity:.4;"' : ''; ?>>Next</a>
	</div>
</div>
<?php endif; ?>
<?php else: ?>
	<div class="parent-appt-empty">No requests yet. Tap "Request appointment" to get started.</div>
<?php endif; ?>
</div>

<div class="parent-appt-tab-panel" data-appointment-panel="past">
<?php if (!empty($past)): ?>
<div class="parent-appt-divider"><span>Past Appointments</span></div>

<div class="parent-appt-list">
	<?php foreach ($pastPage as $appt):
		$dt = new DateTimeImmutable((string)$appt['scheduled_at']);
		$statusClass = parent_status_class((string)$appt['status']);
		$statusLabel = ucfirst((string)$appt['status']);
		$isCompleted = (string)$appt['status'] === 'completed';
		$fromNutritionist = ($appt['created_by'] ?? '') === 'nutritionist';
	?>
	<div class="parent-appt-row" data-appointment-id="<?php echo (int)$appt['id']; ?>">
		<div class="parent-appt-row-icon <?php echo $isCompleted ? 'is-done' : ''; ?>">
			<?php if ($isCompleted): ?>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
			<?php else: ?>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
			<?php endif; ?>
		</div>
		<div class="parent-appt-row-info">
			<div class="parent-appt-row-date"><?php echo parent_e($dt->format('F j, Y')); ?></div>
			<div class="parent-appt-row-type"><?php echo $fromNutritionist ? 'Requested by nutritionist' : 'Requested by you'; ?></div>
			<div class="parent-appt-row-child"><?php echo parent_e($appt['first_name'] . ' ' . $appt['last_name']); ?></div>
		</div>
		<div class="parent-appt-row-status">
			<span class="admin-pill <?php echo $statusClass; ?>"><?php echo parent_e($statusLabel); ?></span>
		</div>
	</div>
	<?php endforeach; ?>
</div>
<?php if ($totalPastPages > 1): ?>
<div class="parent-appt-pagination">
	<span>Showing <?php echo (($pagePast - 1) * $perPage + 1); ?>–<?php echo min($pagePast * $perPage, count($past)); ?> of <?php echo count($past); ?></span>
	<div class="parent-appt-pages">
		<a class="admin-btn-secondary" href="<?php echo parent_e($pageLink('past', $pagePast - 1)); ?>" <?php echo $pagePast <= 1 ? 'style="pointer-events:none;opacity:.4;"' : ''; ?>>Prev</a>
		<span>Page <?php echo $pagePast; ?> of <?php echo $totalPastPages; ?></span>
		<a class="admin-btn-secondary" href="<?php echo parent_e($pageLink('past', $pagePast + 1)); ?>" <?php echo $pagePast >= $totalPastPages ? 'style="pointer-events:none;opacity:.4;"' : ''; ?>>Next</a>
	</div>
</div>
<?php endif; ?>
<?php else: ?>
<div class="parent-appt-empty">No past appointments yet.</div>
<?php endif; ?>
</div>

<div class="admin-modal-overlay" id="apptModal">
	<div class="admin-modal" style="max-width:480px;">
		<div class="admin-modal-head">
			<h3>Appointment Details</h3>
			<button class="admin-modal-close" id="apptModalClose" type="button">&times;</button>
		</div>
		<div class="appt-modal-body">
			<div class="appt-modal-header">
				<div class="appt-modal-date" id="modalDate"></div>
				<div class="appt-modal-time" id="modalTime"></div>
			</div>
			<div class="appt-modal-type" id="modalType"></div>
			<div class="appt-modal-rows">
				<div class="appt-modal-row">
					<span class="appt-modal-label">Child</span>
					<span class="appt-modal-value" id="modalChild"></span>
				</div>
				<div class="appt-modal-row">
					<span class="appt-modal-label">Location</span>
					<span class="appt-modal-value" id="modalLocation"></span>
				</div>
				<div class="appt-modal-row">
					<span class="appt-modal-label">Nutritionist</span>
					<span class="appt-modal-value" id="modalNutritionist"></span>
				</div>
				<div class="appt-modal-row" id="modalBarangayRow">
					<span class="appt-modal-label">Barangay</span>
					<span class="appt-modal-value" id="modalBarangay"></span>
				</div>
				<div class="appt-modal-row">
					<span class="appt-modal-label">Status</span>
					<span class="appt-modal-value"><span class="admin-pill" id="modalStatus"></span></span>
				</div>
			</div>
		<div class="appt-modal-notes" id="modalNotesSection">
			<div class="appt-modal-notes-label">Appointment Notes</div>
			<p class="appt-modal-notes-text" id="modalNotes"></p>
		</div>
		<div class="appt-modal-notes" id="modalRecsSection">
			<div class="appt-modal-notes-label">Recommendations</div>
			<p class="appt-modal-notes-text" id="modalRecs"></p>
		</div>
		<div class="appt-modal-cancel" id="modalConfirmSection">
			<form method="post" action="<?php echo parent_e(app_url('/parent/appointments.php')); ?>">
				<input type="hidden" name="action" value="confirm">
				<input type="hidden" name="id" id="modalConfirmId">
				<input type="hidden" name="child_id" value="<?php echo (int)$selectedChildId; ?>">
				<button class="admin-btn appt-modal-cancel-btn" type="submit" style="width:100%;">Confirm Appointment</button>
			</form>
		</div>
		<div class="appt-modal-cancel" id="modalCancelSection">
			<form method="post" action="<?php echo parent_e(app_url('/parent/appointments.php')); ?>" data-admin-confirm="Cancel this appointment?">
				<input type="hidden" name="action" value="cancel">
				<input type="hidden" name="id" id="modalCancelId">
				<input type="hidden" name="child_id" value="<?php echo (int)$selectedChildId; ?>">
				<button class="admin-btn-secondary appt-modal-cancel-btn" type="submit">Cancel Appointment</button>
			</form>
		</div>
		</div>
	</div>
</div>

<script>
(function () {
	var childPicker = document.getElementById('appointment-child-picker');
	var childOpen = document.querySelector('[data-appointment-child-open]');
	if (childPicker && childOpen) {
		var closePicker = function () { childPicker.hidden = true; document.body.classList.remove('parent-picker-open'); childOpen.focus(); };
		childOpen.addEventListener('click', function () { childPicker.hidden = false; document.body.classList.add('parent-picker-open'); });
		childPicker.querySelectorAll('[data-appointment-child-close]').forEach(function (button) { button.addEventListener('click', closePicker); });
		document.addEventListener('keydown', function (event) { if (!childPicker.hidden && event.key === 'Escape') closePicker(); });
	}

	var appointmentTabs = document.querySelectorAll('[data-appointment-tab]');
	var appointmentPanels = document.querySelectorAll('[data-appointment-panel]');
	appointmentTabs.forEach(function (tab) {
		tab.addEventListener('click', function () {
			var target = tab.getAttribute('data-appointment-tab');
			appointmentTabs.forEach(function (item) { item.classList.toggle('is-active', item === tab); });
			appointmentPanels.forEach(function (panel) { panel.classList.toggle('is-active', panel.getAttribute('data-appointment-panel') === target); });
		});
	});

	var data = <?php echo json_encode($allJson); ?>;
	var overlay = document.getElementById('apptModal');
	var closeBtn = document.getElementById('apptModalClose');

	function openModal(id) {
		var a = data[id];
		if (!a) return;
		document.getElementById('modalDate').textContent = a.date;
		document.getElementById('modalTime').textContent = a.time;
		document.getElementById('modalType').textContent = a.requested_by;
		document.getElementById('modalChild').textContent = a.child;
		document.getElementById('modalLocation').textContent = a.location;
		document.getElementById('modalNutritionist').textContent = a.nutritionist;
		var barangayRow = document.getElementById('modalBarangayRow');
		var barangayEl = document.getElementById('modalBarangay');
		if (a.barangay) { barangayRow.style.display = ''; barangayEl.textContent = a.barangay; }
		else { barangayRow.style.display = 'none'; }
		document.getElementById('modalStatus').textContent = a.status;
		document.getElementById('modalStatus').className = 'admin-pill ' + a.status_class;
	var notesSection = document.getElementById('modalNotesSection');
	var notesEl = document.getElementById('modalNotes');
	if (a.notes) { notesSection.style.display = ''; notesEl.textContent = a.notes; }
	else { notesSection.style.display = 'none'; }
	var recsSection = document.getElementById('modalRecsSection');
	var recsEl = document.getElementById('modalRecs');
	if (a.recommendations) { recsSection.style.display = ''; recsEl.textContent = a.recommendations; }
	else { recsSection.style.display = 'none'; }
		var confirmSection = document.getElementById('modalConfirmSection');
		if (a.can_confirm) {
			confirmSection.style.display = '';
			document.getElementById('modalConfirmId').value = a.id;
		} else {
			confirmSection.style.display = 'none';
		}
		var cancelSection = document.getElementById('modalCancelSection');
		if (a.can_cancel) {
			cancelSection.style.display = '';
			document.getElementById('modalCancelId').value = a.id;
		} else {
			cancelSection.style.display = 'none';
		}
		overlay.classList.add('is-open');
		document.body.style.overflow = 'hidden';
	}

	function closeModal() {
		overlay.classList.remove('is-open');
		document.body.style.overflow = '';
	}

	document.querySelectorAll('[data-appointment-id]').forEach(function (el) {
		el.addEventListener('click', function (e) {
			if (e.target.closest('form') || e.target.closest('button[type="submit"]')) return;
			openModal(parseInt(this.getAttribute('data-appointment-id'), 10));
		});
	});

	if (closeBtn) closeBtn.addEventListener('click', closeModal);
	if (overlay) overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal(); });
})();
</script>

<?php
parent_layout_end();
