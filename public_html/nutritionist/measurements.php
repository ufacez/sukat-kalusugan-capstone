<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';

$user = nutritionist_require_access();

$params = [];
$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $params);
$measurements = admin_fetch_all(
	"SELECT
		m.id,
		m.measurement_date,
		m.height_cm,
		m.weight_kg,
		m.age_months,
		m.source_type,
		m.waz,
		m.haz,
		m.whz,
		COALESCE(m.nutritional_status, CASE
			WHEN m.waz < -3 THEN 'Severely Underweight'
			WHEN m.haz < -3 THEN 'Severely Stunted'
			WHEN m.whz < -3 THEN 'Severely Wasted'
			WHEN m.waz < -2 THEN 'Moderately Underweight'
			WHEN m.haz < -2 THEN 'Moderately Stunted'
			WHEN m.whz < -2 THEN 'Moderately Wasted'
			WHEN m.whz > 3 THEN 'Obese'
			WHEN m.whz > 2 THEN 'Overweight'
			ELSE 'Normal'
		END) AS nutritional_status,
		COALESCE(m.wfa_status, CASE
			WHEN m.waz < -3 THEN 'SUW'
			WHEN m.waz < -2 THEN 'MUW'
			WHEN m.waz > 2 THEN 'OW'
			ELSE 'Normal'
		END) AS wfa_status,
		COALESCE(m.hfa_status, CASE
			WHEN m.haz < -3 THEN 'SSt'
			WHEN m.haz < -2 THEN 'MSt'
			WHEN m.haz > 2 THEN 'Tall'
			ELSE 'Normal'
		END) AS hfa_status,
		COALESCE(m.wfh_status, CASE
			WHEN m.whz < -3 THEN 'SW'
			WHEN m.whz < -2 THEN 'MW'
			WHEN m.whz > 3 THEN 'Ob'
			WHEN m.whz > 2 THEN 'OW'
			ELSE 'Normal'
		END) AS wfh_status,
		m.is_flagged,
		m.flag_reason,
		c.id AS child_id,
		c.child_code,
		c.first_name,
		c.last_name,
		bg.name AS barangay,
		p.name AS parent_name
	 FROM measurements m
	 INNER JOIN children c ON c.id = m.child_id
	 INNER JOIN parents p ON p.id = c.parent_id
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
	 WHERE {$scope}
	 ORDER BY m.measurement_date DESC, m.id DESC",
	str_repeat('i', count($params)),
	$params
);

$statusCounts = [
	'Normal' => 0,
	'Moderately Underweight' => 0,
	'Severely Underweight' => 0,
	'Moderately Stunted' => 0,
	'Severely Stunted' => 0,
	'Moderately Wasted' => 0,
	'Severely Wasted' => 0,
	'Overweight' => 0,
	'Obese' => 0,
];

foreach ($measurements as $measurement) {
	$status = (string)($measurement['nutritional_status'] ?? '');

	if (isset($statusCounts[$status])) {
		$statusCounts[$status]++;
	}
}

$atRiskCount = count(array_filter($measurements, static fn(array $measurement): bool => !in_array((string)($measurement['nutritional_status'] ?? 'Pending'), ['Normal'], true)));
$flaggedCount = count(array_filter($measurements, static fn(array $measurement): bool => !empty($measurement['is_flagged'])));
$actions = '<a class="admin-btn-secondary" href="' . nutritionist_e(app_url('/nutritionist/children.php')) . '">' . admin_action_icon('view') . ' View children</a>';

nutritionist_layout_start('Measurements', 'Latest height, weight, and WHO measurements in one view.', 'measurements', $actions);
?>
<section class="admin-grid-cards">
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 13.5 3.36-3.36a1.5 1.5 0 0 1 2.122 0l2.121 2.121M6.75 17.25l2.25-2.25 2.25 2.25 4.5-4.5M3.75 16.5l4.5-4.5"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Measurement Entries</div>
				<div class="admin-card-value"><?php echo count($measurements); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">All logged measurements</span>
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
				<div class="admin-card-label">At-Risk Results</div>
				<div class="admin-card-value"><?php echo $atRiskCount; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Needs follow-up</span>
				</div>
			</div>
		</div>
	</article>
	<article class="admin-card">
		<div class="admin-card-row">
			<div class="admin-card-icon is-success">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
			</div>
			<div class="admin-card-content">
				<div class="admin-card-label">Normal</div>
				<div class="admin-card-value"><?php echo (int)($statusCounts['Normal'] ?? 0); ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend is-up">Healthy classification</span>
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
				<div class="admin-card-label">Flagged for Review</div>
				<div class="admin-card-value" style="<?php echo $flaggedCount > 0 ? 'color:#E03131;' : ''; ?>"><?php echo $flaggedCount; ?></div>
				<div class="admin-card-meta">
					<span class="admin-card-trend">Biologically implausible values, likely data/device error</span>
				</div>
			</div>
		</div>
	</article>
</section>

<section class="nutritionist-panel">
	<div class="admin-section-title" style="margin-bottom:12px;">Status Breakdown</div>
		<?php foreach (['Normal', 'Moderately Underweight', 'Severely Underweight', 'Moderately Stunted', 'Severely Stunted', 'Moderately Wasted', 'Severely Wasted', 'Overweight', 'Obese'] as $status): ?>
			<?php
			$count = (int)($statusCounts[$status] ?? 0);
			$pct = count($measurements) > 0 ? (int)round(($count / count($measurements)) * 100) : 0;
			$barColor = match ($status) {
				'Normal' => 'var(--admin-primary)',
				'Moderately Underweight' => 'var(--admin-accent)',
				'Severely Underweight' => 'var(--admin-danger)',
				'Moderately Stunted' => '#7048E8',
				'Severely Stunted' => '#5f3dc4',
				'Moderately Wasted' => '#4a9fd5',
				'Severely Wasted' => '#c92a2a',
				'Obese' => '#e8590c',
				default => '#b08900',
			};
			?>
			<div style="margin-bottom:10px;">
				<div style="display:flex;justify-content:space-between;margin-bottom:4px;align-items:center;">
					<span class="admin-pill <?php echo nutritionist_status_class($status); ?>"><?php echo nutritionist_e($status); ?></span>
					<span class="admin-mini"><?php echo $count; ?> (<?php echo $pct; ?>%)</span>
				</div>
				<div style="height:7px;border-radius:999px;background:var(--admin-bg);overflow:hidden;">
					<div style="width:<?php echo max($pct, $count > 0 ? 3 : 0); ?>%;height:100%;border-radius:999px;background:<?php echo nutritionist_e($barColor); ?>;"></div>
				</div>
			</div>
		<?php endforeach; ?>
</section>

<section class="nutritionist-panel">
	<div class="nutritionist-table-head" style="margin-bottom:12px;">
		<div>
			<h2 class="admin-section-title" style="margin-bottom:2px;">Measurement Log</h2>
			<p class="admin-section-subtitle">Search, review, and route follow-ups from the table below.</p>
		</div>
		<input class="admin-search" data-admin-filter="#measurements-table" type="search" placeholder="Search measurements" style="min-width:260px;">
	</div>

	<div class="nutritionist-table-wrap">
		<table class="nutritionist-table" id="measurements-table">
			<thead>
				<tr>
					<th>Date</th>
					<th>Child</th>
					<th>Age</th>
					<th>Height</th>
					<th>Weight</th>
					<th>WAZ</th>
					<th>HAZ</th>
					<th>WHZ</th>
					<th>Status</th>
					<th>Source</th>
					<th>WFA</th>
					<th>HFA</th>
					<th>WFH</th>
					<th>Flag</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($measurements as $measurement): ?>
					<?php $isFlagged = !empty($measurement['is_flagged']); ?>
					<tr data-filter-text="<?php echo nutritionist_e(strtolower($measurement['measurement_date'] . ' ' . $measurement['first_name'] . ' ' . $measurement['last_name'] . ' ' . $measurement['child_code'] . ' ' . ($measurement['nutritional_status'] ?? '') . ' ' . ($measurement['source_type'] ?? 'kiosk'))); ?>" style="<?php echo $isFlagged ? 'background:rgba(224,49,49,0.06);' : ''; ?>">
						<td style="white-space:nowrap;"><?php echo nutritionist_e((string)$measurement['measurement_date']); ?></td>
						<td>
							<div style="font-weight:600;color:var(--admin-text);"><?php echo nutritionist_e($measurement['first_name'] . ' ' . $measurement['last_name']); ?></div>
							<div class="admin-mini"><?php echo nutritionist_e((string)$measurement['child_code']); ?> · <?php echo nutritionist_e((string)$measurement['parent_name']); ?></div>
						</td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)$measurement['age_months']); ?> mo</td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)$measurement['height_cm']); ?> cm</td>
						<td style="color:var(--admin-muted);"><?php echo nutritionist_e((string)$measurement['weight_kg']); ?> kg</td>
						<td style="color:var(--admin-primary);font-weight:600;"><?php echo ((float)$measurement['waz'] > 0 ? '+' : '') . nutritionist_e((string)$measurement['waz']); ?></td>
						<td style="color:#4a9fd5;font-weight:600;"><?php echo ((float)$measurement['haz'] > 0 ? '+' : '') . nutritionist_e((string)$measurement['haz']); ?></td>
						<td style="color:#0d8871;font-weight:600;"><?php echo ((float)$measurement['whz'] > 0 ? '+' : '') . nutritionist_e((string)$measurement['whz']); ?></td>
						<td><span class="admin-pill <?php echo nutritionist_status_class((string)$measurement['nutritional_status']); ?>"><?php echo nutritionist_e((string)$measurement['nutritional_status']); ?></span></td>
						<td style="white-space:nowrap;">
							<?php if (($measurement['source_type'] ?? 'kiosk') === 'manual'): ?>
								<span class="admin-pill is-muted" title="Recorded manually by staff">Manual</span>
							<?php else: ?>
								<span class="admin-pill is-success" title="Captured via kiosk device">Kiosk</span>
							<?php endif; ?>
						</td>
						<td style="color:var(--admin-muted);white-space:nowrap;"><?php echo nutritionist_e((string)($measurement['wfa_status'] ?? '—')); ?></td>
						<td style="color:var(--admin-muted);white-space:nowrap;"><?php echo nutritionist_e((string)($measurement['hfa_status'] ?? '—')); ?></td>
						<td style="color:var(--admin-muted);white-space:nowrap;"><?php echo nutritionist_e((string)($measurement['wfh_status'] ?? '—')); ?></td>
						<td>
							<?php if ($isFlagged): ?>
								<span class="admin-pill is-danger" title="<?php echo nutritionist_e((string)($measurement['flag_reason'] ?? '')); ?>">⚠ Review</span>
							<?php else: ?>
								<span style="color:var(--admin-muted);">—</span>
							<?php endif; ?>
						</td>
						<td><a class="admin-icon-btn" title="View child" href="<?php echo nutritionist_e(app_url('/nutritionist/children.php?view=' . (int)$measurement['child_id'])); ?>"><?php echo admin_action_icon('view'); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
<?php
nutritionist_layout_end();

