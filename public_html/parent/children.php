<?php

require_once __DIR__ . '/../includes/parent_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

$user = parent_require_access();

$children = admin_fetch_all(
	"SELECT
		c.id,
		c.child_code,
		c.first_name,
		c.last_name,
		c.birthdate,
		c.sex,
		bg.name AS barangay,
		la.area_name AS local_area,
		la.area_type,
		p.name AS parent_name,
		p.parent_type,
		p.phone AS parent_phone,
		p.status AS parent_status,
		lm.measurement_date,
		lm.height_cm,
		lm.weight_kg,
		lm.waz,
		lm.haz,
		lm.whz,
		COALESCE(lm.nutritional_status, CASE
			WHEN lm.waz < -3 THEN 'Severely Underweight'
			WHEN lm.haz < -3 THEN 'Severely Stunted'
			WHEN lm.whz < -3 THEN 'Severely Wasted'
			WHEN lm.waz < -2 THEN 'Moderately Underweight'
			WHEN lm.haz < -2 THEN 'Moderately Stunted'
			WHEN lm.whz < -2 THEN 'Moderately Wasted'
			WHEN lm.whz > 3 THEN 'Obese'
			WHEN lm.whz > 2 THEN 'Overweight'
			ELSE 'Normal'
		END) AS nutritional_status,
		COALESCE(lm.wfa_status, CASE
			WHEN lm.waz < -3 THEN 'SUW'
			WHEN lm.waz < -2 THEN 'MUW'
			WHEN lm.waz > 2 THEN 'OW'
			ELSE 'Normal'
		END) AS wfa_status,
		COALESCE(lm.hfa_status, CASE
			WHEN lm.haz < -3 THEN 'SSt'
			WHEN lm.haz < -2 THEN 'MSt'
			WHEN lm.haz > 2 THEN 'Tall'
			ELSE 'Normal'
		END) AS hfa_status,
		COALESCE(lm.wfh_status, CASE
			WHEN lm.whz < -3 THEN 'SW'
			WHEN lm.whz < -2 THEN 'MW'
			WHEN lm.whz > 3 THEN 'Ob'
			WHEN lm.whz > 2 THEN 'OW'
			ELSE 'Normal'
		END) AS wfh_status
	 FROM children c
	 INNER JOIN parents p ON p.id = c.parent_id
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
	 LEFT JOIN local_areas la ON la.id = c.local_area_id
	 LEFT JOIN measurements lm ON lm.id = (
		SELECT m.id
		FROM measurements m
		WHERE m.child_id = c.id
		ORDER BY m.measurement_date DESC, m.id DESC
		LIMIT 1
	 )
	 WHERE c.parent_id = ?
	 ORDER BY c.last_name ASC, c.first_name ASC",
	'i',
	[(int)$user['id']]
);

$actions = '';

$measurementHistory = admin_fetch_all(
	"SELECT m.child_id, m.measurement_date, m.weight_kg, m.height_cm,
		COALESCE(m.nutritional_status, 'Normal') AS nutritional_status
	 FROM measurements m
	 INNER JOIN children c ON c.id = m.child_id
	 WHERE c.parent_id = ?
	 ORDER BY m.measurement_date DESC, m.id DESC",
	'i',
	[(int)$user['id']]
);

$historyByChild = [];
foreach ($measurementHistory as $measurement) {
	$historyByChild[(int)$measurement['child_id']][] = $measurement;
}

parent_layout_start('Children', 'All children linked to your parent account and their latest growth results.', 'children', $actions);
?>

<?php if ($children === []): ?>
	<section class="parent-panel parent-empty-state">
		<h2>No children linked yet</h2>
		<p>Your child's profile will appear here once connected to your account.</p>
	</section>
<?php else: ?>

<?php
	$dohLabels = [
		'SUW' => 'Severely underweight', 'MUW' => 'Moderately underweight', 'Normal' => 'Normal',
		'SSt' => 'Severely stunted', 'MSt' => 'Moderately stunted', 'Tall' => 'Tall for age',
		'SW' => 'Severe wasting (SAM)', 'MW' => 'Moderate wasting (MAM)', 'OW' => 'Overweight', 'Ob' => 'Obese',
	];

	$childrenJson = [];
	foreach ($children as $child) {
		$birthDate = new DateTimeImmutable((string)$child['birthdate']);
		$ageDifference = $birthDate->diff(new DateTimeImmutable('today'));
		$age = doh_age((string)$child['birthdate']) ?? ['days' => 0, 'months' => 0];
		$ageMonths = (int)$age['months'];
		$ageLabel = intdiv($ageMonths, 12) . ' years, ' . ($ageMonths % 12) . ' months';

		$ns = strtolower(trim((string)($child['nutritional_status'] ?? '')));
		$isNormal = ($ns === 'normal' || $ns === '');
		$overweightLabels = ['overweight', 'ow'];
		$obeseLabels = ['obese', 'ob'];
		if ($isNormal) { $simpleStatus = 'Normal'; $simpleClass = 'is-success'; }
		elseif (in_array($ns, $overweightLabels, true)) { $simpleStatus = 'Overweight'; $simpleClass = 'is-orange'; }
		elseif (in_array($ns, $obeseLabels, true)) { $simpleStatus = 'Obese'; $simpleClass = 'is-orange'; }
		else { $simpleStatus = 'At Risk'; $simpleClass = 'is-warn'; }

		$wfaLabel = $dohLabels[$child['wfa_status'] ?? ''] ?? null;
		$hfaLabel = $dohLabels[$child['hfa_status'] ?? ''] ?? null;
		$wfhLabel = $dohLabels[$child['wfh_status'] ?? ''] ?? null;

		$childrenJson[] = [
			'id' => (int)$child['id'],
			'first_name' => (string)$child['first_name'],
			'last_name' => (string)$child['last_name'],
			'child_code' => (string)$child['child_code'],
			'sex' => (string)$child['sex'],
			'birthdate' => (string)$child['birthdate'],
			'birthdate_label' => (new DateTimeImmutable((string)$child['birthdate']))->format('F j, Y'),
			'age_label' => $ageLabel,
			'age_precise' => $ageDifference->invert === 1 ? 'Age unavailable' : $ageDifference->y . ' years, ' . $ageDifference->m . ' months, ' . $ageDifference->d . ' days',
			'barangay' => (string)($child['barangay'] ?? ''),
			'local_area' => (string)($child['local_area'] ?? ''),
			'area_type' => (string)($child['area_type'] ?? ''),
			'parent_type' => (string)($child['parent_type'] ?? 'Guardian'),
			'parent_phone' => (string)($child['parent_phone'] ?? ''),
			'measurement_date' => (string)($child['measurement_date'] ?? ''),
			'measurement_date_label' => !empty($child['measurement_date']) ? (new DateTimeImmutable((string)$child['measurement_date']))->format('F j, Y') : '',
			'weight_kg' => $child['weight_kg'] !== null ? number_format((float)$child['weight_kg'], 1) : '',
			'height_cm' => $child['height_cm'] !== null ? number_format((float)$child['height_cm'], 1) : '',
			'status' => $simpleStatus,
			'status_class' => $simpleClass,
			'wfa' => $wfaLabel,
			'wfa_class' => parent_status_class((string)($child['wfa_status'] ?? 'Pending')),
			'hfa' => $hfaLabel,
			'hfa_class' => parent_status_class((string)($child['hfa_status'] ?? 'Pending')),
			'wfh' => $wfhLabel,
			'wfh_class' => parent_status_class((string)($child['wfh_status'] ?? 'Pending')),
			'history' => array_map(static function (array $measurement): array {
				$status = (string)($measurement['nutritional_status'] ?? 'Normal');
				return [
					'date' => (new DateTimeImmutable((string)$measurement['measurement_date']))->format('M d, Y'),
					'weight' => $measurement['weight_kg'] !== null ? number_format((float)$measurement['weight_kg'], 1) . ' kg' : '—',
					'height' => $measurement['height_cm'] !== null ? number_format((float)$measurement['height_cm'], 1) . ' cm' : '—',
					'status' => $status,
					'status_class' => parent_status_class($status),
				];
			}, array_slice($historyByChild[(int)$child['id']] ?? [], 0, 5)),
		];
	}
?>

<section class="parent-child-grid">
	<?php foreach ($childrenJson as $c): ?>
		<button class="parent-child-card" type="button" data-child-id="<?php echo $c['id']; ?>">
			<div class="parent-child-card-top">
				<div class="parent-child-avatar" aria-hidden="true"><?php echo parent_e(strtoupper(substr($c['first_name'], 0, 1))); ?></div>
				<div class="parent-child-card-info">
					<div class="parent-child-card-name"><?php echo parent_e($c['first_name'] . ' ' . $c['last_name']); ?></div>
					<div class="parent-child-card-sub"><?php echo parent_e($c['age_label']); ?> · <?php echo parent_e($c['sex']); ?></div>
				</div>
			</div>
			<div class="parent-child-card-footer">
				<?php if ($c['weight_kg'] !== ''): ?>
					<span class="parent-child-card-reading"><?php echo parent_e($c['weight_kg']); ?> kg · <?php echo parent_e($c['height_cm']); ?> cm</span>
				<?php else: ?>
					<span class="parent-child-card-reading parent-child-card-reading--empty">No measurements yet</span>
				<?php endif; ?>
				<span class="admin-pill <?php echo parent_e($c['status_class']); ?>" style="font-size:0.65rem;padding:3px 7px;"><?php echo parent_e($c['status']); ?></span>
			</div>
		</button>
	<?php endforeach; ?>
</section>

<div class="admin-modal-overlay" id="childModal">
	<div class="admin-modal" style="max-width:500px;">
		<div class="admin-modal-head">
			<div style="display:flex;align-items:center;gap:10px;">
				<div class="parent-child-avatar" id="modalAvatar" aria-hidden="true"></div>
				<div>
					<h3 id="modalName" style="margin:0;"></h3>
					<div class="admin-mini" id="modalSub"></div>
				</div>
					<span class="admin-pill" id="modalStatus"></span>
			</div>
			<button class="admin-modal-close" id="modalClose" type="button">&times;</button>
		</div>
		<nav class="parent-child-modal-tabs" role="tablist" aria-label="Child details sections">
			<button type="button" class="is-active" role="tab" aria-selected="true" data-modal-tab="profile">Profile</button>
			<button type="button" role="tab" aria-selected="false" data-modal-tab="measurements">Measurements</button>
			<button type="button" role="tab" aria-selected="false" data-modal-tab="history">History</button>
		</nav>
		<div class="parent-child-modal-body">
			<section class="parent-modal-panel is-active" data-modal-panel="profile">
			<section class="parent-modal-section">
				<h4>Basic information</h4>
				<div class="parent-modal-details">
					<div><span>Child code</span><strong id="modalCode"></strong></div>
					<div><span>Sex</span><strong id="modalSex"></strong></div>
					<div><span>Birthdate</span><strong id="modalBirthdate"></strong></div>
					<div><span>Age</span><strong id="modalAge"></strong></div>
				</div>
			</section>
			<section class="parent-modal-section">
				<h4>Location</h4>
				<div class="parent-modal-details">
					<div><span>Barangay</span><strong id="modalBarangay"></strong></div>
					<div id="modalAreaRow"><span>Local area</span><strong id="modalArea"></strong></div>
				</div>
			</section>
			<section class="parent-modal-section">
				<h4>Guardian</h4>
				<div class="parent-modal-details">
					<div><span>Parent</span><strong id="modalParentType"></strong></div>
					<div><span>Contact</span><strong id="modalPhone"></strong></div>
				</div>
			</section>
			</section>
			<section class="parent-modal-panel" data-modal-panel="measurements">
			<section class="parent-modal-section">
				<h4>Latest measurement</h4>
				<div class="parent-modal-details">
					<div><span>Measurement date</span><strong id="modalMeasDate"></strong></div>
					<div><span>Weight</span><strong id="modalWeight"></strong></div>
					<div><span>Height</span><strong id="modalHeight"></strong></div>
				</div>
			</section>
			<section class="parent-modal-section" id="modalGrowthSection">
				<h4>WHO growth assessment</h4>
				<div class="parent-growth-pill-grid">
					<div id="modalWfaRow"><span>WFA</span><strong class="admin-pill modal-growth-pill" id="modalWfa"></strong></div>
					<div id="modalHfaRow"><span>HFA</span><strong class="admin-pill modal-growth-pill" id="modalHfa"></strong></div>
					<div id="modalWfhRow"><span>WFL/H</span><strong class="admin-pill modal-growth-pill" id="modalWfh"></strong></div>
				</div>
			</section>
			</section>
			<section class="parent-modal-panel" data-modal-panel="history">
			<section class="parent-modal-section parent-modal-history-section">
				<div class="parent-modal-section-heading"><h4>Recent history</h4><span>Last 5 readings</span></div>
				<div class="parent-modal-history" id="modalHistory"></div>
			</section>
			</section>
		</div>
		<div class="parent-child-modal-actions">
			<a class="admin-btn-secondary" id="modalGrowthLink" href="#">View growth history</a>
			<button class="admin-btn" id="modalCloseAction" type="button">Close</button>
		</div>
	</div>
</div>

<script>
(function () {
	var children = <?php echo json_encode($childrenJson); ?>;
	var map = {};
	for (var i = 0; i < children.length; i++) map[children[i].id] = children[i];

	var overlay = document.getElementById('childModal');
	if (!overlay) return;
	var closeBtn = document.getElementById('modalClose');
	var closeAction = document.getElementById('modalCloseAction');
	var modalTabs = overlay.querySelectorAll('[data-modal-tab]');
	var modalPanels = overlay.querySelectorAll('[data-modal-panel]');
	function escapeHtml(value) {
		return String(value ?? '').replace(/[&<>'"]/g, function (character) {
			return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[character];
		});
	}

	function open(childId) {
		var c = map[childId];
		if (!c) return;
		modalTabs.forEach(function (tab) {
			var active = tab.getAttribute('data-modal-tab') === 'profile';
			tab.classList.toggle('is-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
		});
		modalPanels.forEach(function (panel) {
			panel.classList.toggle('is-active', panel.getAttribute('data-modal-panel') === 'profile');
		});

		document.getElementById('modalAvatar').textContent = c.first_name.charAt(0).toUpperCase();
		document.getElementById('modalName').textContent = c.first_name + ' ' + c.last_name;
		document.getElementById('modalSub').textContent = c.age_label + ' · ' + c.sex;
		document.getElementById('modalStatus').textContent = c.status;
		document.getElementById('modalStatus').className = 'admin-pill ' + c.status_class;
		document.getElementById('modalCode').textContent = c.child_code;
		document.getElementById('modalSex').textContent = c.sex;
		document.getElementById('modalBirthdate').textContent = c.birthdate_label || '—';
		document.getElementById('modalAge').textContent = c.age_precise || c.age_label || '—';
		document.getElementById('modalBarangay').textContent = c.barangay || '—';
		document.getElementById('modalPhone').textContent = c.parent_phone || '—';
		document.getElementById('modalParentType').textContent = c.parent_type;

		var areaRow = document.getElementById('modalAreaRow');
		if (c.local_area) {
			areaRow.style.display = '';
			document.getElementById('modalArea').textContent = c.area_type.charAt(0).toUpperCase() + c.area_type.slice(1) + ': ' + c.local_area;
		} else {
			areaRow.style.display = 'none';
		}

		document.getElementById('modalMeasDate').textContent = c.measurement_date_label || 'No measurement yet';
		document.getElementById('modalWeight').textContent = c.weight_kg ? c.weight_kg + ' kg' : '—';
		document.getElementById('modalHeight').textContent = c.height_cm ? c.height_cm + ' cm' : '—';

		var growthSection = document.getElementById('modalGrowthSection');
		var hasGrowth = c.wfa || c.hfa || c.wfh;
		growthSection.style.display = hasGrowth ? '' : 'none';

		setGrowthRow('modalWfaRow', 'modalWfa', c.wfa, c.wfa_class);
		setGrowthRow('modalHfaRow', 'modalHfa', c.hfa, c.hfa_class);
		setGrowthRow('modalWfhRow', 'modalWfh', c.wfh, c.wfh_class);

		document.getElementById('modalGrowthLink').href = 'growth_history.php?child_id=' + c.id;
		document.getElementById('modalHistory').innerHTML = (c.history || []).map(function (item) {
			return '<div class="parent-modal-history-row"><span>' + escapeHtml(item.date) + '</span><strong>' + escapeHtml(item.weight) + '</strong><strong>' + escapeHtml(item.height) + '</strong><span class="admin-pill ' + escapeHtml(item.status_class) + '">' + escapeHtml(item.status) + '</span></div>';
		}).join('') || '<div class="parent-modal-history-empty">No recent measurements yet.</div>';

		overlay.classList.add('is-open');
		document.body.style.overflow = 'hidden';
	}

	function setGrowthRow(rowId, valId, val, statusClass) {
		var row = document.getElementById(rowId);
		var el = document.getElementById(valId);
		if (val) {
			row.style.display = '';
			el.textContent = val;
			el.className = 'admin-pill modal-growth-pill ' + (statusClass || 'is-muted');
		}
		else { row.style.display = 'none'; }
	}

	function close() {
		overlay.classList.remove('is-open');
		document.body.style.overflow = '';
	}

	modalTabs.forEach(function (tab) {
		tab.addEventListener('click', function () {
			var target = tab.getAttribute('data-modal-tab');
			modalTabs.forEach(function (item) {
				var active = item === tab;
				item.classList.toggle('is-active', active);
				item.setAttribute('aria-selected', active ? 'true' : 'false');
			});
			modalPanels.forEach(function (panel) {
				panel.classList.toggle('is-active', panel.getAttribute('data-modal-panel') === target);
			});
		});
	});

	var cards = document.querySelectorAll('.parent-child-card[data-child-id]');
	for (var i = 0; i < cards.length; i++) {
		cards[i].addEventListener('click', function () {
			open(parseInt(this.getAttribute('data-child-id'), 10));
		});
	}

	closeBtn.addEventListener('click', close);
	closeAction.addEventListener('click', close);
	overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>

<?php endif; ?>
<?php
parent_layout_end();

