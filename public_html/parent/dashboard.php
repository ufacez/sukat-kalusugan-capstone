<?php

require_once __DIR__ . '/../includes/parent_helpers.php';

$user = parent_require_access();
$parent = admin_fetch_one(
	'SELECT id, name, email, parent_type, phone, address, status, created_at
	 FROM parents
	 WHERE id = ?
	 LIMIT 1',
	'i',
	[(int)$user['id']]
);

if ($parent === null) {
	deny_access('Parent profile could not be loaded.', 404);
}

$children = admin_fetch_all(
	"SELECT
		c.id,
		c.child_code,
		c.first_name,
		c.last_name,
		c.birthdate,
		c.sex,
		bg.name AS barangay,
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
			WHEN lm.waz > 2 THEN 'Refer to WFL/H'
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
	 LEFT JOIN barangays bg ON bg.id = c.barangay_id
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

$childrenCount = count($children);

// Selected child for the growth chart and the assessment panel.
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

// Build chart data for the selected child's measurement history.
$chartDateLabels = [];
$chartHoverDates = [];
$chartWeight = [];
$chartHeight = [];
$chartLatestWeight = null;
$chartLatestHeight = null;
$measurementCount = 0;

if ($selectedChild !== null) {
	$childMeasurements = admin_fetch_all(
		'SELECT measurement_date, height_cm, weight_kg
		 FROM measurements
		 WHERE child_id = ?
		 ORDER BY measurement_date ASC, id ASC',
		'i',
		[$selectedChildId]
	);

	foreach ($childMeasurements as $m) {
		$measurementDate = new DateTimeImmutable((string)$m['measurement_date']);
		$chartDateLabels[] = $measurementDate->format('M');
		$chartHoverDates[] = $measurementDate->format('M j, Y');
		$chartWeight[] = (float)$m['weight_kg'];
		$chartHeight[] = (float)$m['height_cm'];
	}
	$measurementCount = count($chartWeight);
	if ($chartWeight !== []) {
		$chartLatestWeight = end($chartWeight);
		$chartLatestHeight = end($chartHeight);
	}
}

$actions = implode(' ', [
	'<a class="admin-btn-secondary" href="' . parent_e(app_url('/parent/children.php')) . '">' . admin_action_icon('children') . ' View children</a>',
	'<a class="admin-btn" href="' . parent_e(app_url('/parent/appointment_form.php')) . '">' . admin_action_icon('calendar') . ' Book appointment</a>',
]);

parent_layout_start('Dashboard', 'Track your child records, follow-up visits, and recent growth updates.', 'dashboard', $actions);
?>

<?php if ($selectedChild === null): ?>
	<section class="parent-panel parent-empty-state">
		<h2>No children linked yet</h2>
		<p>Your child's measurements will appear here once their profile is connected to your account.</p>
		<a class="admin-btn" href="<?php echo parent_e(app_url('/parent/children.php')); ?>">View children</a>
	</section>
<?php else: ?>

<?php
	$hour = (int) date('H');
	$greeting = ($hour < 12) ? 'Good Morning' : (($hour < 18) ? 'Good Afternoon' : 'Good Evening');
	$parentName = trim((string)($parent['name'] ?? ''));
	$displayName = $parentName !== '' ? $parentName : 'Parent';
?>
<section class="parent-greeting">
	<h2><?php echo parent_e($greeting . ', ' . $displayName); ?></h2>
	<p>Here's your child's latest health information</p>
</section>

<?php
	$selectedDateLabel = !empty($selectedChild['measurement_date'])
		? (new DateTimeImmutable((string)$selectedChild['measurement_date']))->format('M j, Y')
		: 'No measurement yet';
	$selectedAge = doh_age((string)$selectedChild['birthdate']);
	$selectedAgeMonths = $selectedAge !== null ? (int)$selectedAge['months'] : 0;
	$selectedAgeLabel = $selectedAge !== null
		? intdiv($selectedAgeMonths, 12) . ' years, ' . ($selectedAgeMonths % 12) . ' months'
		: 'Age unavailable';
	$ns = strtolower(trim((string)($selectedChild['nutritional_status'] ?? '')));
	$isNormal = ($ns === 'normal' || $ns === '');
	$overweightLabels = ['overweight', 'ow'];
	$obeseLabels = ['obese', 'ob'];
	if ($isNormal) {
		$simpleStatus = 'Normal';
		$simpleClass = 'is-success';
	} elseif (in_array($ns, $overweightLabels, true)) {
		$simpleStatus = 'Overweight';
		$simpleClass = 'is-orange';
	} elseif (in_array($ns, $obeseLabels, true)) {
		$simpleStatus = 'Obese';
		$simpleClass = 'is-orange';
	} else {
		$simpleStatus = 'At Risk';
		$simpleClass = 'is-warn';
	}
?>

<section class="parent-dashboard-top">
	<article class="parent-panel parent-profile-card">
		<button type="button" class="parent-child-trigger" data-child-picker-open aria-haspopup="dialog" aria-controls="parent-child-picker">
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
	</article>

	<article class="parent-panel parent-assessment-card">
		<div class="parent-panel-heading">
			<div>
				<div class="parent-panel-eyebrow">Latest measurement</div>
				<p class="parent-panel-sub"><?php echo parent_e($selectedDateLabel); ?></p>
			</div>
			<a class="parent-mini" href="<?php echo parent_e(app_url('/parent/growth_history.php?child_id=' . $selectedChildId)); ?>">View results <span aria-hidden="true">→</span></a>
		</div>
		<?php if ($chartLatestWeight !== null): ?>
		<div class="parent-assessment-values">
			<div class="parent-assessment-value"><span class="parent-assessment-value-num"><?php echo number_format($chartLatestWeight, 1); ?></span><span class="parent-assessment-value-unit">kg</span><span class="parent-assessment-value-label">Weight</span></div>
			<div class="parent-assessment-divider"></div>
			<div class="parent-assessment-value"><span class="parent-assessment-value-num"><?php echo number_format($chartLatestHeight, 1); ?></span><span class="parent-assessment-value-unit">cm</span><span class="parent-assessment-value-label">Height</span></div>
		</div>
		<?php else: ?>
		<div class="parent-empty-chart">No measurement recorded yet.</div>
		<?php endif; ?>
	</article>
</section>

<div class="parent-child-picker" id="parent-child-picker" role="dialog" aria-modal="true" aria-labelledby="parent-child-picker-title" hidden>
	<div class="parent-child-picker-backdrop" data-child-picker-close></div>
	<div class="parent-child-picker-sheet">
		<div class="parent-child-picker-header">
			<div><h2 id="parent-child-picker-title">Choose a child</h2><p>View measurements for a different child.</p></div>
			<button type="button" class="parent-child-picker-close" data-child-picker-close aria-label="Close child picker">&times;</button>
		</div>
		<div class="parent-child-picker-list">
			<?php foreach ($children as $child): ?>
				<?php
				$childPickerStatus = trim((string)($child['nutritional_status'] ?? '')) ?: 'Pending';
				$childPickerAge = doh_age((string)$child['birthdate']);
				$childPickerMonths = $childPickerAge !== null ? (int)$childPickerAge['months'] : 0;
				$childPickerAgeLabel = $childPickerAge !== null ? intdiv($childPickerMonths, 12) . ' years, ' . ($childPickerMonths % 12) . ' months' : 'Age unavailable';
				?>
				<a class="parent-child-option <?php echo (int)$child['id'] === $selectedChildId ? 'is-selected' : ''; ?>" href="<?php echo parent_e(app_url('/parent/dashboard.php?child_id=' . (int)$child['id'])); ?>">
					<span class="parent-child-option-avatar" aria-hidden="true"><?php echo parent_e(strtoupper(substr((string)$child['first_name'], 0, 1))); ?></span>
					<span class="parent-child-option-copy"><strong><?php echo parent_e($child['first_name'] . ' ' . $child['last_name']); ?></strong><small><?php echo parent_e($childPickerAgeLabel); ?> · <?php echo parent_e((string)$child['sex']); ?></small></span>
					<span class="admin-pill <?php echo parent_status_class($childPickerStatus); ?> parent-child-option-status"><?php echo parent_e($childPickerStatus); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<section class="parent-panel parent-growth-panel">
	<div class="parent-panel-heading" style="margin-bottom:10px;">
		<div>
			<div class="parent-panel-eyebrow">Growth progress</div>
			<p class="parent-panel-sub">Weight & height over time</p>
		</div>
		<div class="parent-chart-tabs" id="growthTabs">
			<button class="parent-chart-tab is-active" data-metric="weight">Weight (kg)</button>
			<button class="parent-chart-tab" data-metric="height">Height (cm)</button>
		</div>
	</div>

	<?php if ($measurementCount === 0): ?>
		<div class="parent-empty-chart">No growth measurements have been recorded yet for this child.</div>
	<?php else: ?>
		<div class="parent-chart-body">
			<div class="parent-chart-y-axis" id="growth-y-axis"></div>
			<canvas id="growthChart" class="parent-chart-canvas"></canvas>
			<div class="parent-chart-tooltip" id="growth-tooltip"></div>
		</div>
		<div class="parent-chart-x-axis" id="growth-x-axis"></div>
		<div style="text-align:right;margin-top:8px;">
			<a class="parent-mini" href="<?php echo parent_e(app_url('/parent/growth_history.php?child_id=' . $selectedChildId)); ?>">View full history <span aria-hidden="true">→</span></a>
		</div>
	<?php endif; ?>
</section>

<?php endif; ?>

<script>
(function () {
	var picker = document.getElementById('parent-child-picker');
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
	document.addEventListener('keydown', function (event) {
		if (!picker.hidden && event.key === 'Escape') closePicker();
	});
})();
</script>

<script>
(function () {
	var canvas = document.getElementById('growthChart');
	if (!canvas) return;

	var ctx = canvas.getContext('2d');
	var tooltip = document.getElementById('growth-tooltip');
	var yAxisEl = document.getElementById('growth-y-axis');
	var xAxisEl = document.getElementById('growth-x-axis');
	var tabsContainer = document.getElementById('growthTabs');

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

<?php
parent_layout_end();
