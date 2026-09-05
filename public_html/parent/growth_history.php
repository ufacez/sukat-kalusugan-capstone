<?php

require_once __DIR__ . '/../includes/parent_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

$user = parent_require_access();

$children = admin_fetch_all(
	'SELECT id, child_code, first_name, last_name, birthdate, sex
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

$childMeasurements = [];
$chartWeight = [];
$chartHeight = [];
$chartDateLabels = [];
$chartHoverDates = [];
$latestWeight = null;
$latestHeight = null;
$latestDate = null;
$prevWeight = null;
$prevHeight = null;
$recentRows = [];
$validMeasurements = [];

if ($selectedChild !== null) {
	$childMeasurements = admin_fetch_all(
		' SELECT measurement_date, height_cm, weight_kg, nutritional_status,
			wfa_status, hfa_status, wfh_status
		 FROM measurements
		 WHERE child_id = ?
		 ORDER BY measurement_date ASC, id ASC',
		'i',
		[$selectedChildId]
	);

	foreach ($childMeasurements as $m) {
		if ($m['weight_kg'] === null || $m['height_cm'] === null || $m['measurement_date'] === null) {
			continue;
		}
		$validMeasurements[] = $m;
		$dt = new DateTimeImmutable((string)$m['measurement_date']);
		$chartDateLabels[] = $dt->format('M');
		$chartHoverDates[] = $dt->format('M j, Y');
		$chartWeight[] = (float)$m['weight_kg'];
		$chartHeight[] = (float)$m['height_cm'];
	}

	$measurementCount = count($chartWeight);
	if ($measurementCount > 0) {
		$latestWeight = $chartWeight[$measurementCount - 1];
		$latestHeight = $chartHeight[$measurementCount - 1];
		$latestDate = $chartHoverDates[$measurementCount - 1];
		if ($measurementCount >= 2) {
			$prevWeight = $chartWeight[$measurementCount - 2];
			$prevHeight = $chartHeight[$measurementCount - 2];
		}
	}

	$recentRows = array_reverse($validMeasurements);
	$recentRows = array_slice($recentRows, 0, 5);
	if ($validMeasurements !== []) {
		$latestMeasurement = $validMeasurements[count($validMeasurements) - 1];
		$selectedChild['nutritional_status'] = (string)($latestMeasurement['nutritional_status'] ?? 'Normal');
	}
}

$actions = '';

parent_layout_start('Growth Progress', 'Track your child\'s growth over time.', 'growth_history', $actions);
?>

<?php if ($selectedChild === null): ?>
	<section class="parent-panel parent-empty-state">
		<h2>No children linked yet</h2>
		<p>Your child's measurements will appear here once their profile is connected to your account.</p>
		<a class="admin-btn" href="<?php echo parent_e(app_url('/parent/children.php')); ?>">View children</a>
	</section>
<?php else: ?>

<?php
	$selectedAge = doh_age((string)$selectedChild['birthdate']);
	$selectedAgeMonths = $selectedAge !== null ? (int)$selectedAge['months'] : 0;
	$selectedAgeLabel = $selectedAge !== null
		? intdiv($selectedAgeMonths, 12) . ' years, ' . ($selectedAgeMonths % 12) . ' months'
		: 'Age unavailable';
	$ns = strtolower(trim((string)($selectedChild['nutritional_status'] ?? '')));
	$isNormal = ($ns === 'normal' || $ns === '');
	$overweightLabels = ['overweight', 'ow'];
	$obeseLabels = ['obese', 'ob'];
	if ($isNormal) { $simpleStatus = 'Normal'; $simpleClass = 'is-success'; }
	elseif (in_array($ns, $overweightLabels, true)) { $simpleStatus = 'Overweight'; $simpleClass = 'is-orange'; }
	elseif (in_array($ns, $obeseLabels, true)) { $simpleStatus = 'Obese'; $simpleClass = 'is-orange'; }
	else { $simpleStatus = 'At Risk'; $simpleClass = 'is-warn'; }

	$weightDelta = ($latestWeight !== null && $prevWeight !== null) ? $latestWeight - $prevWeight : null;
	$heightDelta = ($latestHeight !== null && $prevHeight !== null) ? $latestHeight - $prevHeight : null;
?>

<section class="parent-panel parent-profile-card">
	<button type="button" class="parent-child-trigger" data-child-picker-open aria-haspopup="dialog" aria-controls="gh-child-picker">
		<div class="parent-child-banner">
			<div class="parent-child-avatar" aria-hidden="true"><?php echo parent_e(strtoupper(substr((string)$selectedChild['first_name'], 0, 1))); ?></div>
			<div class="parent-child-info">
				<div class="parent-child-name-row">
					<div class="parent-child-name"><?php echo parent_e($selectedChild['first_name'] . ' ' . $selectedChild['last_name']); ?></div>
					<span class="admin-pill <?php echo $simpleClass; ?> parent-child-status"><?php echo parent_e($simpleStatus); ?></span>
				</div>
				<div class="parent-child-sub"><?php echo parent_e($selectedAgeLabel); ?> · <?php echo parent_e((string)$selectedChild['sex']); ?></div>
			</div>
		</div>
	</button>
	<div class="parent-child-trigger-hint">Tap to change child</div>
</section>

<div class="parent-child-picker" id="gh-child-picker" role="dialog" aria-modal="true" aria-labelledby="gh-picker-title" hidden>
	<div class="parent-child-picker-backdrop" data-child-picker-close></div>
	<div class="parent-child-picker-sheet">
		<div class="parent-child-picker-header">
			<div><h2 id="gh-picker-title">Choose a child</h2><p>View growth history for a different child.</p></div>
			<button type="button" class="parent-child-picker-close" data-child-picker-close aria-label="Close child picker">&times;</button>
		</div>
		<div class="parent-child-picker-list">
			<?php foreach ($children as $pickerChild): ?>
				<?php
				$pcAge = doh_age((string)$pickerChild['birthdate']);
				$pcMonths = $pcAge !== null ? (int)$pcAge['months'] : 0;
				$pcAgeLabel = $pcAge !== null ? intdiv($pcMonths, 12) . ' years, ' . ($pcMonths % 12) . ' months' : 'Age unavailable';
				?>
				<a class="parent-child-option <?php echo (int)$pickerChild['id'] === $selectedChildId ? 'is-selected' : ''; ?>" href="<?php echo parent_e(app_url('/parent/growth_history.php?child_id=' . (int)$pickerChild['id'])); ?>">
					<span class="parent-child-option-avatar" aria-hidden="true"><?php echo parent_e(strtoupper(substr((string)$pickerChild['first_name'], 0, 1))); ?></span>
					<span class="parent-child-option-copy"><strong><?php echo parent_e($pickerChild['first_name'] . ' ' . $pickerChild['last_name']); ?></strong><small><?php echo parent_e($pcAgeLabel); ?> · <?php echo parent_e((string)$pickerChild['sex']); ?></small></span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<?php if ($latestWeight !== null): ?>
<section class="parent-panel parent-growth-overview">
	<div class="parent-panel-heading">
		<div>
			<div class="parent-panel-eyebrow">Latest measurement</div>
		</div>
	</div>
	<div class="parent-growth-values">
		<div class="parent-growth-val">
			<div class="parent-growth-val-label">Weight</div>
			<div class="parent-growth-val-num"><?php echo number_format($latestWeight, 1); ?> <small>kg</small></div>
			<?php if ($weightDelta !== null): ?>
				<div class="parent-growth-change <?php echo $weightDelta >= 0 ? 'is-up' : 'is-down'; ?>">
					<?php echo $weightDelta >= 0 ? '↑ +' . number_format($weightDelta, 1) : '↓ ' . number_format(abs($weightDelta), 1); ?> kg
				</div>
			<?php endif; ?>
		</div>
		<div class="parent-growth-divider"></div>
		<div class="parent-growth-val">
			<div class="parent-growth-val-label">Height</div>
			<div class="parent-growth-val-num"><?php echo number_format($latestHeight, 1); ?> <small>cm</small></div>
			<?php if ($heightDelta !== null): ?>
				<div class="parent-growth-change <?php echo $heightDelta >= 0 ? 'is-up' : 'is-down'; ?>">
					<?php echo $heightDelta >= 0 ? '↑ +' . number_format($heightDelta, 1) : '↓ ' . number_format(abs($heightDelta), 1); ?> cm
				</div>
			<?php endif; ?>
		</div>
	</div>
	<div class="parent-growth-last">Last measured <strong><?php echo parent_e($latestDate); ?></strong></div>
</section>
<?php endif; ?>

<section class="parent-panel parent-growth-panel">
	<div class="parent-panel-heading" style="margin-bottom:10px;">
		<div>
			<div class="parent-panel-eyebrow">Growth Trend</div>
		</div>
		<div class="parent-chart-tabs" id="ghTabs">
			<button class="parent-chart-tab is-active" data-metric="weight">Weight (kg)</button>
			<button class="parent-chart-tab" data-metric="height">Height (cm)</button>
		</div>
	</div>

	<?php if (empty($chartWeight)): ?>
		<div class="parent-empty-chart">No growth measurements have been recorded yet for this child.</div>
	<?php else: ?>
		<div class="parent-chart-body">
			<div class="parent-chart-y-axis" id="gh-y-axis"></div>
			<canvas id="ghChart" class="parent-chart-canvas"></canvas>
			<div class="parent-chart-tooltip" id="gh-tooltip"></div>
		</div>
		<div class="parent-chart-x-axis" id="gh-x-axis"></div>
	<?php endif; ?>
</section>

<?php if (!empty($recentRows)): ?>
<section class="parent-panel parent-recent-panel">
	<div class="parent-panel-heading">
		<div class="parent-panel-eyebrow">Recent Measurements</div>
		<button type="button" class="parent-mini" id="viewAllBtn">View all <span aria-hidden="true">→</span></button>
	</div>
	<div class="parent-recent-list">
		<?php foreach ($recentRows as $row): ?>
			<div class="parent-recent-row">
				<span class="parent-recent-date"><?php echo parent_e((new DateTimeImmutable((string)$row['measurement_date']))->format('M j, Y')); ?></span>
				<span class="parent-recent-weight"><?php echo parent_e(number_format((float)$row['weight_kg'], 1)); ?> kg</span>
				<span class="parent-recent-height"><?php echo parent_e(number_format((float)$row['height_cm'], 1)); ?> cm</span>
				<span class="admin-pill <?php echo parent_status_class((string)($row['nutritional_status'] ?? 'Normal')); ?> parent-recent-status"><?php echo parent_e((string)($row['nutritional_status'] ?? 'Normal')); ?></span>
			</div>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<div class="admin-modal-overlay" id="allMeasurementsModal">
	<div class="admin-modal" style="max-width:600px;">
		<div class="admin-modal-head">
			<h3>All Measurements</h3>
			<button class="admin-modal-close" id="allModalClose" type="button">&times;</button>
		</div>
		<div style="padding:16px 20px;overflow-y:auto;max-height:60vh;">
			<table class="parent-table" style="width:100%;">
				<thead>
					<tr>
						<th>Date</th>
						<th>Weight</th>
						<th>Height</th>
						<th>Status</th>
					</tr>
				</thead>
				<tbody id="allMeasurementsBody">
				</tbody>
			</table>
		</div>
	</div>
</div>

<script>
(function () {
	var picker = document.getElementById('gh-child-picker');
	var openButton = document.querySelector('[data-child-picker-open]');
	if (!picker || !openButton) return;
	var closeButtons = picker.querySelectorAll('[data-child-picker-close]');
	var closePicker = function () {
		picker.hidden = true;
		document.body.classList.remove('parent-picker-open');
		openButton.focus();
	};
	openButton.addEventListener('click', function () {
		picker.hidden = false;
		document.body.classList.add('parent-picker-open');
		var firstOption = picker.querySelector('.parent-child-option');
		if (firstOption) firstOption.focus();
	});
	closeButtons.forEach(function (button) { button.addEventListener('click', closePicker); });
	document.addEventListener('keydown', function (e) {
		if (!picker.hidden && e.key === 'Escape') closePicker();
	});
})();
</script>

<script>
(function () {
	var canvas = document.getElementById('ghChart');
	if (!canvas) return;

	var ctx = canvas.getContext('2d');
	var tooltip = document.getElementById('gh-tooltip');
	var yAxisEl = document.getElementById('gh-y-axis');
	var xAxisEl = document.getElementById('gh-x-axis');
	var tabsContainer = document.getElementById('ghTabs');

	var weights = <?php echo json_encode($chartWeight); ?>;
	var heights = <?php echo json_encode($chartHeight); ?>;
	var dateLabels = <?php echo json_encode($chartDateLabels); ?>;
	var hoverDates = <?php echo json_encode($chartHoverDates); ?>;

	var activeMetric = 'weight';
	var padL = 44, padR = 16, padT = 18, padB = 28;
	var W, H, cW, cH, dpr, maxVal, minVal, hoverIndex = -1;

	function resolveColor(v) {
		if (typeof v !== 'string') return v;
		if (v.indexOf('var(') !== 0) return v;
		var n = v.slice(4, -1).split(',')[0].trim();
		return getComputedStyle(document.documentElement).getPropertyValue(n).trim() || '#94a3b8';
	}
	function parseRgb(v) {
		if (!v) return {r:148,g:163,b:184};
		v = v.trim();
		if (v[0] === '#') {
			var h = v.slice(1);
			if (h.length === 3) h = h.split('').map(function(c){return c+c;}).join('');
			if (h.length >= 6) return {r:parseInt(h.slice(0,2),16),g:parseInt(h.slice(2,4),16),b:parseInt(h.slice(4,6),16)};
		}
		var m = v.match(/rgba?\(([^)]+)\)/i);
		if (m) { var p = m[1].split(',').map(function(s){return parseFloat(s.trim());}); return {r:p[0]||0,g:p[1]||0,b:p[2]||0}; }
		return {r:148,g:163,b:184};
	}
	function rgba(name, a) {
		var c = parseRgb(resolveColor(name));
		return 'rgba('+c.r+','+c.g+','+c.b+','+a+')';
	}

	function getData() {
		if (activeMetric === 'weight') {
			return { values: weights, unit: 'kg', color: resolveColor('var(--admin-primary)'), label: 'Weight' };
		}
		return { values: heights, unit: 'cm', color: resolveColor('var(--admin-accent)'), label: 'Height' };
	}

	function catmullRom(p0,p1,p2,p3,t) {
		var t2=t*t, t3=t2*t;
		return 0.5*((2*p1)+(-p0+p2)*t+(2*p0-5*p1+4*p2-p3)*t2+(-p0+3*p1-3*p2+p3)*t3);
	}

	function sizeCanvas() {
		dpr = window.devicePixelRatio || 1;
		var pw = canvas.parentElement ? canvas.parentElement.getBoundingClientRect().width : 420;
		W = Math.max(pw, 240);
		H = window.matchMedia && window.matchMedia('(max-width:700px)').matches ? 240 : 280;
		canvas.width = Math.round(W * dpr);
		canvas.height = Math.round(H * dpr);
		canvas.style.width = W + 'px';
		canvas.style.height = H + 'px';
		ctx.setTransform(1,0,0,1,0,0);
		ctx.scale(dpr, dpr);
		cW = W - padL - padR;
		cH = H - padT - padB;
	}

	function computeRange(d) {
		var all = d.values.filter(function(v){return v>0;});
		if (all.length === 0) { minVal = 0; maxVal = 10; return; }
		var lo = Math.min.apply(null, all);
		var hi = Math.max.apply(null, all);
		var margin = (hi - lo) * 0.15;
		minVal = Math.max(0, Math.floor((lo - margin) * 2) / 2);
		maxVal = Math.ceil((hi + margin) * 2) / 2;
		if (maxVal <= minVal) maxVal = minVal + 5;
	}

	function yPos(v) {
		return padT + cH - ((v - minVal) / (maxVal - minVal)) * cH;
	}

	function xPos(i) {
		var n = d.values.length;
		return padL + (i / Math.max(n - 1, 1)) * cW;
	}

	var d;
	function buildChildPts() {
		return d.values.map(function(v, i) {
			return { x: xPos(i), y: yPos(v) };
		});
	}

	function drawSmooth(pts, color, fill, lw) {
		if (pts.length < 2) return;
		ctx.beginPath();
		ctx.moveTo(pts[0].x, yPos(minVal));
		ctx.lineTo(pts[0].x, pts[0].y);
		for (var i = 0; i < pts.length - 1; i++) {
			var p0=pts[Math.max(i-1,0)], p1=pts[i], p2=pts[i+1], p3=pts[Math.min(i+2,pts.length-1)];
			for (var t=0;t<=1;t+=0.05) ctx.lineTo(catmullRom(p0.x,p1.x,p2.x,p3.x,t), catmullRom(p0.y,p1.y,p2.y,p3.y,t));
		}
		ctx.lineTo(pts[pts.length-1].x, yPos(minVal));
		ctx.closePath();
		if (fill) { ctx.fillStyle = fill; ctx.fill(); }
		ctx.beginPath();
		ctx.moveTo(pts[0].x, pts[0].y);
		for (var j=0;j<pts.length-1;j++) {
			var q0=pts[Math.max(j-1,0)], q1=pts[j], q2=pts[j+1], q3=pts[Math.min(j+2,pts.length-1)];
			for (var tt=0;tt<=1;tt+=0.05) ctx.lineTo(catmullRom(q0.x,q1.x,q2.x,q3.x,tt), catmullRom(q0.y,q1.y,q2.y,q3.y,tt));
		}
		ctx.strokeStyle = color;
		ctx.lineWidth = lw || 2;
		ctx.lineCap = 'round';
		ctx.lineJoin = 'round';
		ctx.stroke();
	}

	function drawAxes() {
		var brd = parseRgb(resolveColor('var(--admin-border)'));
		ctx.strokeStyle = 'rgba('+brd.r+','+brd.g+','+brd.b+',0.5)';
		ctx.lineWidth = 0.5;
		for (var g=0;g<=5;g++) {
			var gy = padT + cH * (g / 5);
			ctx.beginPath(); ctx.moveTo(padL, gy); ctx.lineTo(W - padR, gy); ctx.stroke();
		}

		if (yAxisEl) {
			yAxisEl.innerHTML = '';
			for (var i=5;i>=0;i--) {
				var lbl = document.createElement('div');
				lbl.className = 'parent-chart-y-label';
				lbl.textContent = Math.round((minVal + (maxVal - minVal) * i / 5) * 10) / 10;
				yAxisEl.appendChild(lbl);
			}
		}

		if (xAxisEl) {
			xAxisEl.innerHTML = '';
			var step = Math.max(1, Math.ceil(dateLabels.length / 6));
			dateLabels.forEach(function(m, i) {
				if (i === 0 || i === dateLabels.length - 1 || i % step === 0) {
					var lbl = document.createElement('div');
					lbl.className = 'parent-chart-x-label';
					lbl.textContent = m;
					xAxisEl.appendChild(lbl);
				}
			});
		}
	}

	function drawChart() {
		d = getData();
		computeRange(d);
		ctx.clearRect(0, 0, W, H);
		drawAxes();

		var childPts = buildChildPts();
		drawSmooth(childPts, d.color, rgba('--admin-primary', 0.10), 2.5);

		var dotCenter = resolveColor('var(--admin-surface)');
		childPts.forEach(function(p) {
			ctx.beginPath();
			ctx.arc(p.x, p.y, 4.5, 0, Math.PI * 2);
			ctx.fillStyle = d.color;
			ctx.fill();
			ctx.beginPath();
			ctx.arc(p.x, p.y, 1.8, 0, Math.PI * 2);
			ctx.fillStyle = dotCenter;
			ctx.fill();
		});

		if (hoverIndex >= 0 && hoverIndex < childPts.length) {
			var xPosH = childPts[hoverIndex].x;
			ctx.beginPath();
			ctx.setLineDash([3,3]);
			ctx.moveTo(xPosH, padT);
			ctx.lineTo(xPosH, padT + cH);
			ctx.strokeStyle = rgba('--admin-muted', 0.4);
			ctx.lineWidth = 1;
			ctx.stroke();
			ctx.setLineDash([]);
		}
	}

	function showTooltip(idx) {
		if (!tooltip) return;
		if (idx < 0 || idx >= d.values.length) { tooltip.style.opacity = '0'; return; }
		var dark = resolveColor('var(--admin-surface)');
		var parts = ['<strong style="color:'+dark+';">'+hoverDates[idx]+'</strong>'];
		parts.push('<span style="color:'+d.color+'">●</span> '+d.label+': '+d.values[idx].toFixed(1)+' '+d.unit);
		tooltip.innerHTML = parts.join(' &nbsp; ');
		tooltip.style.left = xPos(idx) + 'px';
		tooltip.style.top = (padT + 4) + 'px';
		tooltip.style.opacity = '1';
	}

	function findClosest(mx) {
		var best = -1, bestD = Infinity;
		var cp = buildChildPts();
		for (var i=0;i<cp.length;i++) {
			var dist = Math.abs(cp[i].x - mx);
			if (dist < bestD) { bestD = dist; best = i; }
		}
		return bestD < 40 ? best : -1;
	}

	function onMove(e) {
		var rect = canvas.getBoundingClientRect();
		var mx = e.clientX - rect.left;
		hoverIndex = findClosest(mx);
		showTooltip(hoverIndex);
		drawChart();
	}
	function onLeave() {
		hoverIndex = -1;
		tooltip.style.opacity = '0';
		drawChart();
	}

	if (tabsContainer) {
		tabsContainer.addEventListener('click', function(e) {
			var btn = e.target.closest('.parent-chart-tab');
			if (!btn) return;
			var metric = btn.getAttribute('data-metric');
			if (metric === activeMetric) return;
			activeMetric = metric;
			var btns = tabsContainer.querySelectorAll('.parent-chart-tab');
			for (var i=0;i<btns.length;i++) btns[i].classList.remove('is-active');
			btn.classList.add('is-active');
			hoverIndex = -1;
			if (tooltip) tooltip.style.opacity = '0';
			drawChart();
		});
	}

	function init() {
		d = getData();
		computeRange(d);
		sizeCanvas();
		drawChart();
		canvas.addEventListener('mousemove', onMove);
		canvas.addEventListener('mouseleave', onLeave);
		canvas.addEventListener('touchstart', function(e){if(e.touches&&e.touches[0])onMove(e.touches[0]);},{passive:true});
		canvas.addEventListener('touchmove', function(e){if(e.touches&&e.touches[0])onMove(e.touches[0]);},{passive:true});
		canvas.addEventListener('touchend', onLeave, {passive:true});
		var resizeTimer = null;
		window.addEventListener('resize', function(){
			if(resizeTimer) clearTimeout(resizeTimer);
			resizeTimer = setTimeout(function(){ sizeCanvas(); drawChart(); }, 100);
		});
	}

	if (weights.length > 0 || heights.length > 0) init();
})();
</script>

<script>
(function () {
	var allData = <?php echo json_encode(array_map(function ($m) {
		$ns = strtolower(trim((string)($m['nutritional_status'] ?? '')));
		$isNormal = ($ns === 'normal' || $ns === '');
		$overweightLabels = ['overweight', 'ow'];
		$obeseLabels = ['obese', 'ob'];
		if ($isNormal) { $simpleStatus = 'Normal'; $simpleClass = 'is-success'; }
		elseif (in_array($ns, $overweightLabels, true)) { $simpleStatus = 'Overweight'; $simpleClass = 'is-orange'; }
		elseif (in_array($ns, $obeseLabels, true)) { $simpleStatus = 'Obese'; $simpleClass = 'is-orange'; }
		else { $simpleStatus = 'At Risk'; $simpleClass = 'is-warn'; }
		return [
			'date' => (new DateTimeImmutable((string)$m['measurement_date']))->format('M j, Y'),
			'weight' => number_format((float)$m['weight_kg'], 1),
			'height' => number_format((float)$m['height_cm'], 1),
			'status' => $simpleStatus,
			'status_class' => $simpleClass,
		];
	}, array_reverse($childMeasurements))); ?>;

	var overlay = document.getElementById('allMeasurementsModal');
	var body = document.getElementById('allMeasurementsBody');
	var viewAllBtn = document.getElementById('viewAllBtn');
	var closeBtn = document.getElementById('allModalClose');

	function openModal() {
		body.innerHTML = '';
		allData.forEach(function (r) {
			var tr = document.createElement('tr');
			tr.innerHTML = '<td>' + r.date + '</td><td>' + r.weight + ' kg</td><td>' + r.height + ' cm</td><td><span class="admin-pill ' + r.status_class + '">' + r.status + '</span></td>';
			body.appendChild(tr);
		});
		overlay.classList.add('is-open');
		document.body.style.overflow = 'hidden';
	}

	function closeModal() {
		overlay.classList.remove('is-open');
		document.body.style.overflow = '';
	}

	if (viewAllBtn) viewAllBtn.addEventListener('click', openModal);
	if (closeBtn) closeBtn.addEventListener('click', closeModal);
	if (overlay) overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal(); });
})();
</script>

<?php endif; ?>
<?php
parent_layout_end();

