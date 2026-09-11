<?php

require_once __DIR__ . '/../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../includes/followup_scheduler.php';

$user = nutritionist_require_access();
$childId = (int)($_GET['id'] ?? 0);

if ($childId <= 0) {
    header('Location: ' . app_url('/nutritionist/appointments.php'));
    exit;
}

$conn = get_db_connection();
$measuredToday = isset($_GET['measured']);

$childStmt = mysqli_prepare(
    $conn,
    "SELECT
        c.id,
        c.child_code,
        c.first_name,
        c.middle_name,
        c.last_name,
        c.birthdate,
        c.sex,
        c.barangay_id,
        c.parent_id,
        c.status,
        bg.name AS barangay_name,
        p.name AS parent_name,
        p.phone AS parent_phone
     FROM children c
     JOIN barangays bg ON bg.id = c.barangay_id
     LEFT JOIN parents p ON p.id = c.parent_id
     WHERE c.id = ?
     LIMIT 1"
);

if ($childStmt === false) {
    header('Location: ' . app_url('/nutritionist/appointments.php'));
    exit;
}

mysqli_stmt_bind_param($childStmt, 'i', $childId);
mysqli_stmt_execute($childStmt);
$childResult = mysqli_stmt_get_result($childStmt);
$child = $childResult instanceof mysqli_result ? mysqli_fetch_assoc($childResult) : null;
mysqli_stmt_close($childStmt);

if (!is_array($child) || ($child['status'] ?? '') === 'inactive') {
    header('Location: ' . app_url('/nutritionist/appointments.php'));
    exit;
}

$measStmt = mysqli_prepare(
    $conn,
    "SELECT
        id,
        measurement_date,
        height_cm,
        weight_kg,
        age_months,
        waz,
        haz,
        whz,
        nutritional_status,
        wfa_status,
        hfa_status,
        wfh_status,
        measurement_type,
        source_type,
        is_flagged,
        flag_reason,
        override_reason,
        override_authority,
        recorded_by
     FROM measurements
     WHERE child_id = ?
     ORDER BY measurement_date DESC, id DESC"
);

mysqli_stmt_bind_param($measStmt, 'i', $childId);
mysqli_stmt_execute($measStmt);
$measResult = mysqli_stmt_get_result($measStmt);
$recentMeasurements = [];
if ($measResult instanceof mysqli_result) {
    while ($mRow = mysqli_fetch_assoc($measResult)) {
        $recentMeasurements[] = $mRow;
    }
}
mysqli_stmt_close($measStmt);

// ── Measurement pagination ──
$measPage = max(1, (int)($_GET['mp'] ?? 1));
$measPerPage = 5;
$measTotal = count($recentMeasurements);
$measTotalPages = max(1, (int)ceil($measTotal / $measPerPage));
if ($measPage > $measTotalPages) $measPage = $measTotalPages;
$measOffset = ($measPage - 1) * $measPerPage;
$pageMeasurements = array_slice($recentMeasurements, $measOffset, $measPerPage);

$monitoringStatus = followup_get_monitoring_status($childId);
$today = new DateTimeImmutable('today');

$lastM = $recentMeasurements[0] ?? null;

$childEnriched = $child + [
    'measurement_date' => $lastM['measurement_date'] ?? null,
    'wfa_status'       => $lastM['wfa_status'] ?? null,
    'hfa_status'       => $lastM['hfa_status'] ?? null,
    'wfh_status'       => $lastM['wfh_status'] ?? null,
    'monitoring_status' => $monitoringStatus['monitoring_status'] ?? 'routine',
    'custom_interval_days' => $monitoringStatus['custom_interval_days'] ?? null,
    'monitoring_reason' => $monitoringStatus['monitoring_reason'] ?? null,
];
$classif = followup_classify_child($childEnriched);

$card = followup_card_state(
    $child['birthdate'],
    $lastM['measurement_date'] ?? null,
    $lastM['wfa_status'] ?? null,
    $lastM['hfa_status'] ?? null,
    $lastM['wfh_status'] ?? null,
    $today,
    $monitoringStatus['monitoring_status'] ?? null,
    $monitoringStatus['custom_interval_days'] ?? null
);
$visits = followup_fetch_visits($childId, '2026-01-01', '2026-12-31');

$monStatus = $monitoringStatus['monitoring_status'] ?? 'routine';
if ($monStatus === 'sick') {
    $monitoringPillClass = 'is-danger';
    $monitoringPillLabel = 'Sick';
} elseif ($monStatus === 'special') {
    $monitoringPillClass = 'is-orange';
    $monitoringPillLabel = 'Special';
} elseif ($monStatus === 'other') {
    $monitoringPillClass = 'is-info';
    $monitoringPillLabel = 'Other';
} else {
    $monitoringPillClass = 'is-muted';
    $monitoringPillLabel = 'Routine';
}

$childFullName = $child['first_name'] . ' ' . $child['last_name'];

nutritionist_layout_start(
    $childFullName,
    'Follow-up timeline and monitoring schedule for ' . $child['child_code'] . '.',
    'appointments',
    '',
    $childFullName
);
?>

<style>
.fc-header{display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.fc-avatar{width:40px;height:40px;border-radius:10px;background:var(--admin-surface-alt);border:2px solid var(--admin-border);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px;font-weight:700;color:var(--admin-text)}
.fc-name{font-size:15px;font-weight:700;color:var(--admin-text)}
.fc-meta{font-size:11px;color:var(--admin-muted);margin-top:1px}
.fc-pills{display:flex;gap:4px;flex-wrap:wrap;margin-top:4px}

.fc-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
@media(max-width:900px){.fc-grid{grid-template-columns:1fr}}
.fc-full{grid-column:1/-1}

.fc-card{background:var(--admin-surface);border:1px solid var(--admin-border);border-radius:10px;padding:14px}
.fc-card-title{font-size:12px;font-weight:700;color:var(--admin-text);margin-bottom:8px;display:flex;align-items:center;gap:5px}
.fc-card-title svg{width:13px;height:13px;flex-shrink:0}
.fc-field{margin-bottom:6px}
.fc-field:last-child{margin-bottom:0}
.fc-field-label{font-size:10px;color:var(--admin-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.03em}
.fc-field-value{font-size:12px;color:var(--admin-text);margin-top:1px}

.fc-timeline{list-style:none;margin:0;padding:0;position:relative}
.fc-timeline::before{content:'';position:absolute;left:11px;top:0;bottom:0;width:2px;background:var(--admin-border)}
.fc-timeline-item{display:flex;gap:10px;padding:7px 0;position:relative}
.fc-timeline-dot{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;z-index:1;font-size:11px}
.fc-timeline-body{flex:1;min-width:0}
.fc-timeline-date{font-size:10px;color:var(--admin-muted)}
.fc-timeline-title{font-size:11px;font-weight:600;color:var(--admin-text);margin-top:1px}
.fc-timeline-sub{font-size:10px;color:var(--admin-muted);margin-top:1px}
</style>

<?php if ($measuredToday): ?>
    <div class="admin-flash">Measurement recorded successfully. Follow-up schedule updated.</div>
<?php endif; ?>

<!-- ============ CHILD HEADER ============ -->
<section class="fc-header">
    <div class="fc-avatar"><?php echo mb_strtoupper(mb_substr($child['first_name'], 0, 1) . mb_substr($child['last_name'], 0, 1)); ?></div>
    <div style="flex:1;min-width:0;">
        <div class="fc-name"><?php echo nutritionist_e($child['first_name'] . ' ' . $child['last_name']); ?></div>
        <div class="fc-meta">
            <?php echo nutritionist_e($child['child_code']); ?> · <?php echo $child['age_months'] ?? 0; ?> months old ·
            <?php echo nutritionist_e($child['barangay_name']); ?>
            <?php if ($child['parent_name']): ?>
                · Parent: <?php echo nutritionist_e($child['parent_name']); ?>
            <?php endif; ?>
        </div>
        <div class="fc-pills">
            <span class="admin-pill <?php echo $card['class']; ?>"><?php echo nutritionist_e($card['label']); ?></span>
            <span class="admin-pill <?php echo $monitoringPillClass; ?>"><?php echo $monitoringPillLabel; ?></span>
            <?php if ($classif['track'] === 'monthly'): ?>
                <span class="admin-pill is-muted">Monthly</span>
            <?php elseif ($classif['track'] === 'quarterly'): ?>
                <span class="admin-pill is-muted">Quarterly</span>
            <?php elseif ($classif['track'] === 'custom'): ?>
                <span class="admin-pill is-info">Custom (<?php echo $classif['custom_interval_days']; ?>d)</span>
            <?php endif; ?>
        </div>
    </div>
    <div>
        <a class="admin-btn admin-btn-primary" href="<?php echo nutritionist_e(app_url('/nutritionist/measurement_record.php?child_id=' . $childId)); ?>">Record Measurement</a>
    </div>
</section>

<!-- ============ FOLLOW-UP SCHEDULE + MONITORING STATUS ============ -->
<div class="fc-grid">
    <div class="fc-card">
        <div class="fc-card-title"><?php echo admin_action_icon('calendar'); ?> Follow-Up Schedule</div>
        <div class="fc-field">
            <div class="fc-field-label">Track</div>
            <div class="fc-field-value">
                <?php echo ucfirst($classif['track']); ?>
                <?php if ($classif['track'] === 'monthly'): ?>
                    (0–23 mo)
                <?php elseif ($classif['track'] === 'quarterly'): ?>
                    (24–60 mo)
                <?php elseif ($classif['track'] === 'custom' && $classif['custom_interval_days']): ?>
                    (Every <?php echo $classif['custom_interval_days']; ?>d)
                <?php endif; ?>
            </div>
        </div>
        <div class="fc-field">
            <div class="fc-field-label">Next Due</div>
            <div class="fc-field-value" style="font-size:14px;font-weight:700;color:<?php echo ($card['state'] === 'overdue') ? '#dc2626' : (($card['state'] === 'due_soon') ? '#d97706' : 'var(--admin-text)'); ?>;">
                <?php echo $card['due'] ?? 'Not scheduled'; ?>
            </div>
        </div>
        <div class="fc-field">
            <div class="fc-field-label">Category</div>
            <div class="fc-field-value"><?php echo followup_category_label($classif['category']); ?></div>
        </div>
        <div class="fc-field">
            <div class="fc-field-label">Reason</div>
            <div class="fc-field-value"><?php echo nutritionist_e($classif['reason']); ?></div>
        </div>
    </div>

    <div class="fc-card">
        <div class="fc-card-title"><?php echo admin_action_icon('view'); ?> Monitoring Status</div>
        <div class="fc-field">
            <div class="fc-field-label">Status</div>
            <div class="fc-field-value"><?php echo ucfirst($monStatus); ?></div>
        </div>
        <?php if ($monStatus !== 'routine'): ?>
        <div class="fc-field">
            <div class="fc-field-label">Custom Interval</div>
            <div class="fc-field-value"><?php echo ($monitoringStatus['custom_interval_days'] ?? null) ? ($monitoringStatus['custom_interval_days'] . ' days') : 'System default'; ?></div>
        </div>
        <?php endif; ?>
        <?php if ($monitoringStatus['reason'] ?? null): ?>
        <div class="fc-field">
            <div class="fc-field-label">Reason</div>
            <div class="fc-field-value"><?php echo nutritionist_e($monitoringStatus['reason']); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($monitoringStatus['set_by_name'] ?? null): ?>
        <div class="fc-field">
            <div class="fc-field-label">Set By</div>
            <div class="fc-field-value"><?php echo nutritionist_e($monitoringStatus['set_by_name']); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($monitoringStatus['set_at'] ?? null): ?>
        <div class="fc-field">
            <div class="fc-field-label">Set At</div>
            <div class="fc-field-value"><?php echo $monitoringStatus['set_at']; ?></div>
        </div>
        <?php endif; ?>
        <?php if (nutritionist_can_write()): ?>
        <div style="margin-top:8px;">
            <button type="button" class="admin-btn-secondary" onclick="document.getElementById('monitoringModal').style.display='flex'"><?php echo admin_action_icon('edit'); ?> Edit</button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============ RECENT MEASUREMENTS ============ -->
<div class="fc-card fc-full" style="margin-bottom:14px;">
    <div class="fc-card-title"><?php echo admin_action_icon('clipboard'); ?> Recent Measurements</div>
    <?php if (empty($recentMeasurements)): ?>
        <div style="text-align:center;padding:18px;color:var(--admin-muted);font-size:12px;">No measurements recorded yet.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <div style="display:grid;grid-template-columns:80px 1fr 1fr 1fr 1fr;gap:6px;padding:6px 0;border-bottom:2px solid var(--admin-border);font-size:10px;color:var(--admin-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.04em;min-width:520px;">
            <div>Date</div>
            <div>Weight (kg)</div>
            <div>Height (cm)</div>
            <div>WHO Status</div>
            <div>Type</div>
        </div>
        <?php foreach ($pageMeasurements as $m):
            $mTypePill = match ($m['measurement_type'] ?? 'ROUTINE') {
                'OVERRIDE' => '<span class="admin-pill is-delete">Override</span>',
                'KIOSK' => '<span class="admin-pill is-info">Kiosk</span>',
                default => '<span class="admin-pill is-muted">Routine</span>',
            };
            $nutStatus = $m['nutritional_status'] ?? '';
            $nutPillClass = nutritionist_status_class($nutStatus);
        ?>
        <div style="display:grid;grid-template-columns:80px 1fr 1fr 1fr 1fr;gap:6px;padding:6px 0;border-bottom:1px solid var(--admin-border);font-size:11px;align-items:center;min-width:520px;">
            <div><?php echo date('M j, Y', strtotime($m['measurement_date'])); ?></div>
            <div><strong><?php echo number_format((float)$m['weight_kg'], 1); ?></strong></div>
            <div><strong><?php echo number_format((float)$m['height_cm'], 1); ?></strong></div>
            <div><span class="admin-pill <?php echo $nutPillClass; ?>"><?php echo nutritionist_e($nutStatus ?: 'N/A'); ?></span></div>
            <div><?php echo $mTypePill; ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($measTotalPages > 1): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;font-size:11px;color:var(--admin-muted);">
        <span>Showing <?php echo $measOffset + 1; ?>–<?php echo min($measTotal, $measOffset + $measPerPage); ?> of <?php echo $measTotal; ?></span>
        <div style="display:flex;gap:4px;">
            <?php if ($measPage > 1): ?>
                <a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/followup_child.php?id=' . $childId . '&mp=' . ($measPage - 1))); ?>" style="padding:3px 8px;font-size:11px;">&#8249; Prev</a>
            <?php endif; ?>
            <?php if ($measPage < $measTotalPages): ?>
                <a class="admin-btn-secondary" href="<?php echo nutritionist_e(app_url('/nutritionist/followup_child.php?id=' . $childId . '&mp=' . ($measPage + 1))); ?>" style="padding:3px 8px;font-size:11px;">Next &#8250;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ============ FOLLOW-UP TIMELINE ============ -->
<div class="fc-card fc-full" style="margin-bottom:14px;">
    <div class="fc-card-title"><?php echo admin_action_icon('calendar'); ?> Follow-Up Timeline</div>
    <?php if (empty($visits)): ?>
        <div style="text-align:center;padding:18px;color:var(--admin-muted);font-size:12px;">No follow-up visits recorded yet.</div>
    <?php else: ?>
    <ul class="fc-timeline">
        <?php foreach ($visits as $v):
            $visitDate = new DateTimeImmutable($v['scheduled_at']);
            $isComplete = ($v['status'] === 'completed');
            $isToday = ($visitDate->format('Y-m-d') === $today->format('Y-m-d'));
            $isPast = ($visitDate < $today);
            $dotBg = $isComplete ? '#16a34a' : ($isToday ? '#d97706' : ($isPast ? '#dc2626' : '#64748b'));
            $trackLabel = match ($v['followup_track'] ?? '') {
                'monthly' => 'Monthly',
                'quarterly' => 'Quarterly',
                'special' => 'Special',
                'sick' => 'Sick',
                'other' => 'Other',
                default => ucfirst($v['followup_track'] ?? ''),
            };
            $catLabel = followup_category_label($v['followup_category'] ?? '');
        ?>
        <li class="fc-timeline-item">
            <div class="fc-timeline-dot" style="background:<?php echo $dotBg; ?>;color:#fff;">
                <?php echo $isComplete ? '&#10003;' : (($isToday) ? '&#9679;' : '&#9675;'); ?>
            </div>
            <div class="fc-timeline-body">
                <div class="fc-timeline-date"><?php echo $visitDate->format('M j, Y'); ?></div>
                <div class="fc-timeline-title">
                    <?php echo nutritionist_e($trackLabel); ?>
                    <?php if ($catLabel): ?>
                        — <?php echo nutritionist_e($catLabel); ?>
                    <?php endif; ?>
                </div>
                <div class="fc-timeline-sub">
                    <?php if ($isComplete): ?>
                        Measured <?php echo $v['completed_at'] ? date('M j, g:i A', strtotime($v['completed_at'])) : 'unknown time'; ?>
                        <?php if ($v['source_measurement_id']): ?>
                            · <?php echo $v['source_type'] ?? 'measurement'; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($isToday): ?>
                            <strong style="color:#d97706;">Due today</strong>
                        <?php elseif ($isPast): ?>
                            <strong style="color:#dc2626;">Overdue</strong>
                        <?php else: ?>
                            <?php echo ucfirst($v['status']); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<!-- ============ EDIT MONITORING STATUS MODAL ============ -->
<div id="monitoringModal" class="admin-modal-overlay" style="display:none;">
    <div class="admin-modal" style="max-width:440px;">
        <div class="admin-modal-head">
            <h3>Set Monitoring Status</h3>
            <button type="button" class="admin-modal-close" onclick="document.getElementById('monitoringModal').style.display='none'">&times;</button>
        </div>
        <div style="padding:16px 20px;">
            <form method="post" action="<?php echo nutritionist_e(app_url('/nutritionist/api/followup_monitoring_set_status.php')); ?>">
                <input type="hidden" name="child_id" value="<?php echo $childId; ?>">
                <div style="margin-bottom:10px;">
                    <label style="display:block;font-size:11px;font-weight:600;color:var(--admin-muted);margin-bottom:3px;" for="monitoring_status">Monitoring Status</label>
                    <select id="monitoring_status" name="monitoring_status" style="width:100%;padding:7px 10px;border:1px solid var(--admin-border);border-radius:8px;background:var(--admin-surface);color:var(--admin-text);font-size:12px;">
                        <option value="routine" <?php echo $monStatus === 'routine' ? 'selected' : ''; ?>>Routine (default schedule)</option>
                        <option value="special" <?php echo $monStatus === 'special' ? 'selected' : ''; ?>>Special Monitoring (custom interval)</option>
                        <option value="sick" <?php echo $monStatus === 'sick' ? 'selected' : ''; ?>>Sick (illness monitoring)</option>
                        <option value="other" <?php echo $monStatus === 'other' ? 'selected' : ''; ?>>Other (custom interval)</option>
                    </select>
                </div>
                <div id="custom_interval_field" style="margin-bottom:10px;<?php echo $monStatus !== 'routine' ? '' : 'display:none'; ?>">
                    <label style="display:block;font-size:11px;font-weight:600;color:var(--admin-muted);margin-bottom:3px;" for="custom_interval_days">Custom Interval (days)</label>
                    <input type="number" id="custom_interval_days" name="custom_interval_days" min="7" value="<?php echo $monitoringStatus['custom_interval_days'] ?? 30; ?>" style="width:100%;padding:7px 10px;border:1px solid var(--admin-border);border-radius:8px;background:var(--admin-surface);color:var(--admin-text);font-size:12px;">
                </div>
                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:11px;font-weight:600;color:var(--admin-muted);margin-bottom:3px;" for="custom_reason">Reason</label>
                    <textarea id="custom_reason" name="custom_reason" placeholder="Reason for special monitoring..." style="width:100%;padding:7px 10px;border:1px solid var(--admin-border);border-radius:8px;background:var(--admin-surface);color:var(--admin-text);font-size:12px;font-family:inherit;min-height:50px;resize:vertical;"><?php echo nutritionist_e($monitoringStatus['reason'] ?? ''); ?></textarea>
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="submit" class="admin-btn admin-btn-primary">Save</button>
                    <button type="button" class="admin-btn-secondary" onclick="document.getElementById('monitoringModal').style.display='none'">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('monitoring_status')?.addEventListener('change', function () {
    var f = document.getElementById('custom_interval_field');
    if (f) f.style.display = this.value === 'routine' ? 'none' : '';
});
document.getElementById('monitoringModal')?.addEventListener('click', function (e) {
    if (e.target === this) this.style.display = 'none';
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { var m = document.getElementById('monitoringModal'); if (m) m.style.display = 'none'; }
});
</script>

<?php
nutritionist_layout_end();
