<?php

/**
 * followup_scheduler.php
 *
 * Automatic EOPT follow-up engine.
 *
 * Monitoring rules implemented (DOH e-OPT Plus community protocol):
 *
 *   MONTHLY track (re-measure every month):
 *     - ALL children aged 0-23 months, regardless of status;
 *     - older children 24-59 months who are MALNOURISHED on any axis
 *       (SUW/UW underweight, St/SSt stunting, MW/SW wasting, OW/Ob);
 *     - older children 24-59 months with no measurement on record yet
 *       (they need a baseline OPT measurement before anything else).
 *
 *   QUARTERLY track (re-measure every 3 months in Jan / April / July / October rounds):
 *     - older children 24-59 months classified NORMAL on all axes.
 *       Re-checked every quarter on a rolling 3-month cycle snapped to
 *       the nearest official round month.
 *
 *   Children over 59 months have graduated from eOPT coverage.
 *
 * The engine materializes each child's next due visit as an
 * `appointment_type = 'followup'` row in the existing appointments table.
 * Those rows are MANDATORY re-measurements: they cannot be cancelled or
 * deleted, and they may only be completed once a newer measurement exists.
 */

require_once __DIR__ . '/admin_helpers.php';
require_once __DIR__ . '/audit_logger.php';

const FOLLOWUP_QUARTER_MONTHS = [1, 4, 7, 10];
const FOLLOWUP_GRACE_DAYS = 7;

/**
 * Age in completed months, the same convention DOH uses on eOPT forms.
 */
function followup_age_months(string $birthdate, ?DateTimeImmutable $asOf = null): int
{
	try {
		$birth = new DateTimeImmutable($birthdate);
	} catch (Exception) {
		return 0;
	}

	$asOf ??= new DateTimeImmutable('today');
	$diff = $birth->diff($asOf);

	return $diff->y * 12 + $diff->m;
}

/**
 * Adds months without date overflows (Jan 31 + 1 month = Feb 28/29).
 */
function followup_add_months(DateTimeImmutable $date, int $months): DateTimeImmutable
{
	$firstOfTarget = $date->modify('first day of ' . ($months >= 0 ? '+' : '') . $months . ' month');
	$targetDay = min((int)$date->format('j'), (int)$firstOfTarget->format('t'));

	return $firstOfTarget->setDate(
		(int)$firstOfTarget->format('Y'),
		(int)$firstOfTarget->format('n'),
		$targetDay
	);
}

/**
 * Human label for a status code combo like "SUW+SSt" or "SW".
 */
function followup_category_label(string $category): string
{
	$names = [
		'SUW' => 'Severely Underweight',
		'MUW' => 'Moderately Underweight',
		'SSt' => 'Severely Stunted',
		'MSt' => 'Moderately Stunted',
		'SW' => 'Severely Wasted',
		'MW' => 'Moderately Wasted',
		'OW' => 'Overweight',
		'Ob' => 'Obese',
	];

	if ($category === '' || $category === 'Normal') {
		return $category === 'Normal' ? 'Normal' : '';
	}

	$parts = explode('+', $category);
	$labels = [];

	foreach ($parts as $part) {
		$labels[] = $names[$part] ?? $part;
	}

	return implode(' + ', $labels);
}

/**
 * Collects the abnormal axis codes ("malnourished" definition used by the
 * monthly roster): underweight, stunting, wasting, overweight, obesity.
 */
function followup_abnormal_codes(?string $wfa, ?string $hfa, ?string $wfh): array
{
	$codes = [];

	foreach (['wfa' => $wfa, 'hfa' => $hfa, 'wfh' => $wfh] as $axis => $value) {
		$value = (string)$value;

		if ($axis === 'wfa' && in_array($value, ['SUW', 'MUW'], true)) {
			$codes[] = $value;
		} elseif ($axis === 'hfa' && in_array($value, ['SSt', 'MSt'], true)) {
			$codes[] = $value;
		} elseif ($axis === 'wfh' && in_array($value, ['SW', 'MW', 'OW', 'Ob'], true)) {
			$codes[] = $value;
		}
	}

	return array_values(array_unique($codes));
}

/**
 * Classifies a child into an EOPT monitoring track.
 *
 * Expects the child row to carry birthdate plus the LATEST measurement
 * fields (measurement_date, wfa_status, hfa_status, wfh_status) — the same
 * shape produced by the standard "latest measurement" LEFT JOIN used across
 * the nutritionist pages.
 *
 * If a child has a special monitoring status with a custom interval, that
 * interval overrides the default age-based schedule.
 *
 * @return array{track: ?string, category: string, reason: string, custom_interval_days: ?int}
 */
function followup_classify_child(array $child, ?DateTimeImmutable $asOf = null): array
{
	$ageNow = followup_age_months((string)$child['birthdate'], $asOf);

	if ($ageNow > 59) {
		return ['track' => null, 'category' => '', 'reason' => 'Over 59 months — graduated from eOPT coverage.', 'custom_interval_days' => null];
	}

	$monitoringStatus = $child['monitoring_status'] ?? 'routine';
	$customInterval = isset($child['custom_interval_days']) ? (int)$child['custom_interval_days'] : null;

	// Special monitoring with custom interval — override age-based defaults
	if (in_array($monitoringStatus, ['special', 'sick', 'other'], true) && $customInterval !== null && $customInterval > 0) {
		$label = ucfirst($monitoringStatus);
		$reasonText = $child['monitoring_reason'] ?? '';
		return [
			'track' => 'custom',
			'category' => $label,
			'reason' => $label . ' monitoring — custom interval every ' . $customInterval . ' day' . ($customInterval !== 1 ? 's' : '') . ($reasonText !== '' ? ' (' . $reasonText . ')' : '.') . '.',
			'custom_interval_days' => $customInterval,
		];
	}

	$hasMeasurement = !empty($child['measurement_date']);
	$abnormal = $hasMeasurement
		? followup_abnormal_codes($child['wfa_status'] ?? null, $child['hfa_status'] ?? null, $child['wfh_status'] ?? null)
		: [];

	if ($ageNow <= 23) {
		return [
			'track' => 'monthly',
			'category' => '0-23 mo',
			'reason' => 'Mandatory monthly monitoring — all infants and toddlers 0-23 months.',
			'custom_interval_days' => null,
		];
	}

	if (!$hasMeasurement) {
		return [
			'track' => 'monthly',
			'category' => 'Needs baseline',
			'reason' => 'No OPT measurement on record — baseline weighing required.',
			'custom_interval_days' => null,
		];
	}

	if ($abnormal !== []) {
		$category = implode('+', $abnormal);

		return [
			'track' => 'monthly',
			'category' => $category,
			'reason' => 'Malnourished (' . followup_category_label($category) . ') — mandatory monthly re-measurement.',
			'custom_interval_days' => null,
		];
	}

	return [
		'track' => 'quarterly',
		'category' => 'Normal',
		'reason' => 'Normal — quarterly re-check (April / July / October rounds).',
		'custom_interval_days' => null,
	];
}

/**
 * Next due date for a follow-up cycle.
 *
 * Monthly track: anniversary of the last measurement (+1 month).
 * Quarterly track: last measurement + 3 months, snapped forward into the
 * nearest official round month (April / July / October).
 * Never-measured children are anchored to the next upcoming round.
 */
function followup_next_due(?string $lastMeasuredDate, string $track, ?DateTimeImmutable $asOf = null, ?int $customIntervalDays = null): DateTimeImmutable
{
	$asOf ??= new DateTimeImmutable('today');

	if ($lastMeasuredDate === null || $lastMeasuredDate === '') {
		if ($track === 'quarterly') {
			$roundStart = followup_next_round_start($asOf);

			return $roundStart->setDate((int)$roundStart->format('Y'), (int)$roundStart->format('n'), 15);
		}

		$nextMonth = $asOf->modify('first day of next month');

		return $nextMonth->setDate((int)$nextMonth->format('Y'), (int)$nextMonth->format('n'), 15);
	}

	try {
		$base = new DateTimeImmutable($lastMeasuredDate);
	} catch (Exception) {
		return $asOf->modify('+1 month');
	}

	if ($track === 'custom') {
		$intervalDays = (int)($customIntervalDays ?? 30);
		if ($intervalDays < 1) {
			$intervalDays = 30;
		}
		return $base->modify('+' . $intervalDays . ' days');
	}

	if ($track === 'quarterly') {
		$candidate = followup_add_months($base, 3);
		$cursor = $candidate->setDate((int)$candidate->format('Y'), (int)$candidate->format('n'), 1);

		for ($i = 0; $i <= 11; $i++) {
			if (in_array((int)$cursor->format('n'), FOLLOWUP_QUARTER_MONTHS, true)) {
				return $cursor->setDate(
					(int)$cursor->format('Y'),
					(int)$cursor->format('n'),
					min((int)$candidate->format('j'), (int)$cursor->format('t'))
				);
			}

			$cursor = $cursor->modify('first day of next month');
		}

		return $candidate;
	}

	return followup_add_months($base, 1);
}

/** First day of the next April/July/October strictly after $asOf's month. */
function followup_next_round_start(DateTimeImmutable $asOf): DateTimeImmutable
{
	$cursor = $asOf->setDate((int)$asOf->format('Y'), (int)$asOf->format('n'), 1);

	for ($i = 1; $i <= 12; $i++) {
		$cursor = $cursor->modify('first day of next month');

		if (in_array((int)$cursor->format('n'), FOLLOWUP_QUARTER_MONTHS, true)) {
			return $cursor;
		}
	}

	// Unreachable in practice — April always comes within 12 months.
	return $asOf->modify('first day of january next year')->setDate(
		(int)$asOf->format('Y') + 1,
		4,
		1
	);
}

/**
 * Schedule-state badge data for child cards / tables.
 *
 * @return array{due: ?string, state: string, label: string, class: string}
 */
function followup_card_state(
	?string $birthdate,
	?string $lastMeasuredDate,
	?string $wfaStatus = null,
	?string $hfaStatus = null,
	?string $wfhStatus = null,
	?DateTimeImmutable $today = null,
	?string $monitoringStatus = null,
	?int $customIntervalDays = null
): array {
	$today ??= new DateTimeImmutable('today');
	$classif = followup_classify_child([
		'birthdate' => (string)$birthdate,
		'measurement_date' => $lastMeasuredDate,
		'wfa_status' => $wfaStatus,
		'hfa_status' => $hfaStatus,
		'wfh_status' => $wfhStatus,
		'monitoring_status' => $monitoringStatus ?? 'routine',
		'custom_interval_days' => $customIntervalDays,
	], $today);

	$idle = ['due' => null, 'state' => 'none', 'label' => 'Not in eOPT coverage', 'class' => 'is-muted'];

	if ($classif['track'] === null) {
		return $idle;
	}

	$due = followup_next_due($lastMeasuredDate, $classif['track'], $today, $classif['custom_interval_days'] ?? null);
	$dueLabel = $due->format('M j, Y');

	$daysUntilDue = (int)$today->diff($due->setTime(0, 0))->format('%r%a');

	if ($lastMeasuredDate === null || $lastMeasuredDate === '') {
		return ['due' => $dueLabel, 'state' => 'unmeasured', 'label' => 'Needs baseline', 'class' => 'is-warn'];
	}

	if ($daysUntilDue < 0) {
		return ['due' => $dueLabel, 'state' => 'overdue', 'label' => 'Overdue', 'class' => 'is-danger'];
	}

	if ($daysUntilDue <= FOLLOWUP_GRACE_DAYS) {
		return ['due' => $dueLabel, 'state' => 'due_soon', 'label' => 'Due soon', 'class' => 'is-warn'];
	}

	return ['due' => $dueLabel, 'state' => 'on_track', 'label' => 'On schedule', 'class' => 'is-success'];
}

/**
 * Runs one automatic synchronization pass for a single child — auto-completes
 * any satisfied follow-up, recategorizes any open follow-up whose stored
 * category has been improved by a new measurement, and books the next
 * mandatory cycle if no open follow-up remains.
 *
 * Designed for measurement-ingestion endpoints (kiosk + manual) where we
 * want to materialize a follow-up immediately for the child that was just
 * measured, without needing a session-authenticated nutritionist user.
 *
 * @return array{generated: int, completed: int, recategorized: int, track: ?string, category: string}
 */
function followup_sync_for_child(int $childId): array
{
	$conn = get_db_connection();

	$child = admin_fetch_one(
		'SELECT
			c.id,
			c.birthdate,
			c.parent_id,
			c.barangay_id,
			lm.id AS last_measurement_id,
			lm.measurement_date,
			lm.wfa_status,
			lm.hfa_status,
			lm.wfh_status,
			COALESCE(cms.monitoring_status, \'routine\') AS monitoring_status,
			cms.custom_interval_days,
			cms.reason AS monitoring_reason
		 FROM children c
		 LEFT JOIN measurements lm ON lm.id = (
			SELECT m.id FROM measurements m
			WHERE m.child_id = c.id
			ORDER BY m.measurement_date DESC, m.id DESC
			LIMIT 1
		 )
		 LEFT JOIN child_monitoring_status cms ON cms.child_id = c.id
		 WHERE c.id = ?
		 LIMIT 1',
		'i',
		[$childId]
	);

	if ($child === null) {
		return ['generated' => 0, 'completed' => 0, 'recategorized' => 0, 'track' => null, 'category' => ''];
	}

	$today = new DateTimeImmutable('today');

	$openFollowups = admin_fetch_all(
		"SELECT id, child_id, scheduled_at, followup_track, followup_category, source_measurement_id
		 FROM appointments
		 WHERE child_id = ?
		   AND appointment_type = 'followup'
		   AND status IN ('pending', 'confirmed')",
		'i',
		[$childId]
	);

	$completed = 0;
	foreach ($openFollowups as $followup) {
		try {
			$scheduledDate = new DateTimeImmutable((string)$followup['scheduled_at']);
			$satisfiedFrom = $scheduledDate->setTime(0, 0)->modify('-' . FOLLOWUP_GRACE_DAYS . ' days');
		} catch (Exception) {
			continue;
		}

		$measuredAt = $child['measurement_date'] ?? null;

		if (
			$measuredAt !== null
			&& $measuredAt !== ''
			&& new DateTimeImmutable((string)$measuredAt) >= $satisfiedFrom
		) {
			$ok = admin_execute(
				"UPDATE appointments
				 SET status = 'completed',
				     notes = CONCAT(COALESCE(notes, ''), ' - Re-measurement recorded ', ?, '. Auto-completed by EOPT scheduler.')
				 WHERE id = ?
				   AND status IN ('pending', 'confirmed')",
				'si',
				[(string)$measuredAt, (int)$followup['id']]
			);

			if ($ok) {
				$completed++;
			}
		}
	}

	/*
	 * Pass 1.5 — recategorize: if a re-measurement has IMPROVED the child's
	 * classification AND the existing follow-up is still >= 7 days away,
	 * cancel the old row and let Pass 2 regenerate a fresh one with the
	 * new (better) category. Worsening classifications leave the old
	 * appointment alone — a monthly follow-up is still appropriate.
	 */
	$recategorized = 0;
	$stillOpen = admin_fetch_all(
		"SELECT id, scheduled_at, followup_track, followup_category, source_measurement_id
		 FROM appointments
		 WHERE child_id = ?
		   AND appointment_type = 'followup'
		   AND status IN ('pending', 'confirmed')",
		'i',
		[$childId]
	);

	if ($stillOpen !== []) {
		$classif = followup_classify_child($child, $today);
		$newRank = followup_category_severity_rank($classif['category']);
		$newTrack = $classif['track'];
		$swapCutoff = $today->modify('+' . FOLLOWUP_GRACE_DAYS . ' days')->setTime(0, 0);

		foreach ($stillOpen as $openRow) {
			try {
				$openDate = new DateTimeImmutable((string)$openRow['scheduled_at']);
			} catch (Exception) {
				continue;
			}

			if ($openDate < $swapCutoff) {
				continue;
			}

			$oldCategory = (string)($openRow['followup_category'] ?? '');
			$oldRank = followup_category_severity_rank($oldCategory);

			if ($newRank >= $oldRank) {
				continue;
			}

			$newLabel = $newCategory = $classif['category'];
			$triggerMeasurementId = $child['last_measurement_id'] !== null ? (int)$child['last_measurement_id'] : null;
			$measuredAt = $child['measurement_date'] ?? date('Y-m-d');
			$noteFragment = sprintf(
				' - Reclassified %s → %s on %s (measurement #%d). New follow-up scheduled.',
				$oldCategory !== '' ? $oldCategory : 'unspecified',
				$newLabel !== '' ? $newLabel : 'Normal',
				(string)$measuredAt,
				$triggerMeasurementId ?? 0
			);

			$ok = admin_execute(
				"UPDATE appointments
				 SET status = 'cancelled',
				     notes = CONCAT(COALESCE(notes, ''), ?)
				 WHERE id = ?
				   AND status IN ('pending', 'confirmed')",
				'si',
				[$noteFragment, (int)$openRow['id']]
			);

			if ($ok) {
				$recategorized++;

				log_action(
					null,
					'FOLLOWUP_RECLASSIFIED',
					'info',
					sprintf(
						'Follow-up #%d for child #%d reclassified: %s → %s (track %s → %s) after measurement #%d on %s.',
						(int)$openRow['id'],
						$childId,
						$oldCategory !== '' ? $oldCategory : 'unspecified',
						$newLabel !== '' ? $newLabel : 'Normal',
						(string)($openRow['followup_track'] ?? ''),
						(string)($newTrack ?? ''),
						$triggerMeasurementId ?? 0,
						(string)$measuredAt
					)
				);
			}
		}
	}

	$remainingOpen = admin_fetch_all(
		"SELECT id FROM appointments
		 WHERE child_id = ?
		   AND appointment_type = 'followup'
		   AND status IN ('pending', 'confirmed')",
		'i',
		[$childId]
	);

	if ($remainingOpen !== []) {
		return ['generated' => 0, 'completed' => $completed, 'recategorized' => $recategorized, 'track' => null, 'category' => ''];
	}

	$classif = followup_classify_child($child, $today);

	if ($classif['track'] === null) {
		return ['generated' => 0, 'completed' => $completed, 'recategorized' => $recategorized, 'track' => null, 'category' => ''];
	}

	$due = followup_next_due(
		$child['measurement_date'] ?? null,
		$classif['track'],
		$today,
		$classif['custom_interval_days'] ?? null
	)->setTime(9, 0, 0);

	$nutritionistId = followup_pick_nutritionist_for_child($conn, (int)$child['barangay_id']);

	$generated = 0;
	if ($nutritionistId > 0) {
		$ok = admin_execute(
			"INSERT INTO appointments
				(child_id, parent_id, nutritionist_id, scheduled_at, status,
				 appointment_type, followup_track, followup_category,
				 source_measurement_id, notes, created_at)
			 VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, NOW())",
			'iiissssis',
			[
				$childId,
				(int)$child['parent_id'],
				$nutritionistId,
				$due->format('Y-m-d H:i:s'),
				'followup',
				$classif['track'],
				$classif['category'],
				$child['last_measurement_id'] !== null ? (int)$child['last_measurement_id'] : null,
				'[EOPT auto follow-up] ' . $classif['reason'],
			]
		);

		if ($ok) {
			$generated++;
		}
	}

	return [
		'generated' => $generated,
		'completed' => $completed,
		'recategorized' => $recategorized,
		'track' => $classif['track'],
		'category' => $classif['category'],
	];
}

/**
 * Severity rank used to compare two eOPT categories for the recategorize
 * pass. Higher rank = more severe. Multi-code categories (e.g. "SUW+St")
 * resolve to the maximum of their component codes.
 *
 *   0 = Normal / Needs baseline / 0-23 mo (no real classification)
 *   1 = OW
 *   2 = MUW / MSt / MW
 *   3 = SUW / SSt / SW / Ob
 */
function followup_category_severity_rank(?string $category): int
{
	$category = (string)$category;

	if ($category === '' || strcasecmp($category, 'Normal') === 0) {
		return 0;
	}

	$codeRanks = [
		'SUW' => 3, 'SSt' => 3, 'SW' => 3, 'Ob' => 3,
		'MUW' => 2, 'MSt' => 2, 'MW' => 2,
		'OW' => 1,
	];

	$parts = explode('+', $category);
	$max = 0;

	foreach ($parts as $part) {
		$max = max($max, $codeRanks[$part] ?? 0);
	}

	return $max;
}

/**
 * Pick an active nutritionist assigned to the given barangay. Returns 0
 * when no active nutritionist is assigned (caller should skip insert).
 */
function followup_pick_nutritionist_for_child(mysqli $conn, int $barangayId): int
{
	if ($barangayId <= 0) {
		return 0;
	}

	$stmt = mysqli_prepare(
		$conn,
		"SELECT u.id
		 FROM users u
		 INNER JOIN roles r ON r.id = u.role_id
		 WHERE r.name = 'nutritionist'
		   AND u.status = 'active'
		   AND u.barangay_id = ?
		 ORDER BY u.id ASC
		 LIMIT 1"
	);

	if ($stmt === false) {
		return 0;
	}

	mysqli_stmt_bind_param($stmt, 'i', $barangayId);
	mysqli_stmt_execute($stmt);
	$result = mysqli_stmt_get_result($stmt);
	$row = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
	mysqli_stmt_close($stmt);

	return $row !== null ? (int)$row['id'] : 0;
}

/**
 * Runs one automatic synchronization pass over every child inside the
 * current user's barangay scope. Delegates per-child to
 * followup_sync_for_child() so the scope pass and the measurement-time
 * pass share identical classification, recategorization, and generation
 * rules.
 *
 * Called automatically on the Appointments page load; safe to call often.
 *
 * @return array{generated: int, completed: int, recategorized: int}
 */
function followup_sync_for_scope(array $user): array
{
	$scopeParams = [];
	$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $scopeParams);

	$children = admin_fetch_all(
		"SELECT c.id
		 FROM children c
		 WHERE {$scope}",
		str_repeat('i', count($scopeParams)),
		$scopeParams
	);

	$generated = 0;
	$completed = 0;
	$recategorized = 0;

	foreach ($children as $child) {
		$result = followup_sync_for_child((int)$child['id']);
		$generated += (int)$result['generated'];
		$completed += (int)$result['completed'];
		$recategorized += (int)($result['recategorized'] ?? 0);
	}

	if ($generated > 0 || $completed > 0 || $recategorized > 0) {
		log_action(
			(int)($user['id'] ?? 0) ?: null,
			'FOLLOWUP_SYNC',
			'info',
			sprintf(
				'EOPT follow-up sync: %d generated, %d auto-completed, %d reclassified.',
				$generated,
				$completed,
				$recategorized
			)
		);
	}

	return ['generated' => $generated, 'completed' => $completed, 'recategorized' => $recategorized];
}

/**
 * Fetches up to $limit follow-up appointments for a child within a date
 * range, joined to their linked measurement for nutritional status.
 * Returns an array of [scheduled_at, intervention_type, intervention_notes,
 * appt_status, nutritional_status].
 */
function followup_fetch_visits(int $childId, string $fromDate, string $toDate, int $limit = 6): array
{
	$conn = get_db_connection();

	// Use @ to suppress mysqli warnings if columns don't exist in the
	// appointments table (some deployments use a schema without these columns).
	// The try/catch is the real guard: on PHP 8 + mysqlnd, prepare() throws
	// mysqli_sql_exception instead of returning false, which @ cannot stop.
	try {
		$stmt = @$conn->prepare(
			"SELECT a.scheduled_at, a.intervention_type, a.intervention_notes,
			        a.status AS appt_status,
			        m.nutritional_status
			 FROM appointments a
			 LEFT JOIN measurements m ON m.id = a.source_measurement_id
			 WHERE a.child_id = ?
			   AND a.appointment_type = 'followup'
			   AND a.scheduled_at BETWEEN ? AND ?
			 ORDER BY a.scheduled_at ASC
			 LIMIT ?"
		);
	} catch (Throwable $e) {
		error_log('followup_fetch_visits prepare failed: ' . $e->getMessage());
		return [];
	}

	if ($stmt === false) {
		return [];
	}

	$limitInt = (int)$limit;
	mysqli_stmt_bind_param($stmt, 'issi', $childId, $fromDate, $toDate, $limitInt);
	mysqli_stmt_execute($stmt);
	$result = mysqli_stmt_get_result($stmt);
	$rows = $result instanceof mysqli_result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
	mysqli_stmt_close($stmt);

	return $rows;
}

/**
 * Batched sequence map for EOPT monitoring lists (List_0-23 Month# columns).
 *
 * Month#N = the Nth follow-up appointment of that child in chronological
 * order (scheduled_at ASC, id ASC), so Month#1 is the child's first
 * follow-up ever. Cancelled rows are excluded. Returns
 * [child_id => [0 => ['scheduled_at'=>..., 'status'=>...], ...]] with at
 * most $maxVisits entries per child (index 0 = Month#1).
 */
function eopt_fetch_followup_sequence_map(array $childIds, int $maxVisits = 6): array
{
	$ids = [];
	foreach ($childIds as $id) {
		$id = (int)$id;
		if ($id > 0) {
			$ids[$id] = true;
		}
	}
	$ids = array_keys($ids);
	$map = [];
	foreach ($ids as $id) {
		$map[$id] = [];
	}
	if ($ids === []) {
		return $map;
	}
	$maxVisits = max(1, min(12, (int)$maxVisits));
	$placeholders = implode(',', array_fill(0, count($ids), '?'));
	$rows = admin_fetch_all(
		"SELECT child_id, scheduled_at, status
		 FROM appointments
		 WHERE child_id IN ({$placeholders})
		   AND appointment_type = 'followup'
		   AND status != 'cancelled'
		 ORDER BY child_id ASC, scheduled_at ASC, id ASC",
		str_repeat('i', count($ids)),
		$ids
	);
	foreach ($rows as $row) {
		$cid = (int)($row['child_id'] ?? 0);
		if (!isset($map[$cid]) || count($map[$cid]) >= $maxVisits) {
			continue;
		}
		$map[$cid][] = [
			'scheduled_at' => (string)($row['scheduled_at'] ?? ''),
			'status' => (string)($row['status'] ?? ''),
		];
	}
	return $map;
}

/**
 * Date-only label + tooltip for a Month# sequence cell.
 * Returns [label, title]. Label is '—' when $visit is null.
 */
function eopt_followup_cell(array $visit = null): array
{
	if ($visit === null || ($visit['scheduled_at'] ?? '') === '') {
		return ['—', 'No follow-up visit'];
	}
	try {
		$dt = new DateTimeImmutable((string)$visit['scheduled_at']);
		return [$dt->format('M j, Y'), $dt->format('M j, Y g:i A')];
	} catch (Exception) {
		return ['—', 'No follow-up visit'];
	}
}

// ============================================================
// MONITORING STATUS FUNCTIONS
// ============================================================

/**
 * Get the monitoring status for a child. Returns default 'routine' if no
 * custom status has been set.
 *
 * @return array{monitoring_status: string, custom_interval_days: ?int, reason: ?string, set_by: ?int}
 */
function followup_get_monitoring_status(int $childId): array
{
	$conn = get_db_connection();

	$row = admin_fetch_one(
		"SELECT monitoring_status, custom_interval_days, reason, set_by
		 FROM child_monitoring_status
		 WHERE child_id = ?
		 LIMIT 1",
		'i',
		[$childId]
	);

	if ($row === null) {
		return [
			'monitoring_status' => 'routine',
			'custom_interval_days' => null,
			'reason' => null,
			'set_by' => null,
		];
	}

	return [
		'monitoring_status' => (string)$row['monitoring_status'],
		'custom_interval_days' => $row['custom_interval_days'] !== null ? (int)$row['custom_interval_days'] : null,
		'reason' => $row['reason'] ?? null,
		'set_by' => $row['set_by'] !== null ? (int)$row['set_by'] : null,
	];
}

/**
 * Set or update the monitoring status for a child.
 *
 * @param string $status One of 'routine', 'special', 'sick', 'other'
 * @param int $staffUserId The nutritionist/admin setting this status
 * @param int|null $customIntervalDays Custom interval in days (null = use age-based default)
 * @param string|null $reason Free-text reason for the override
 * @return bool Success
 */
function followup_set_monitoring_status(
	int $childId,
	string $status,
	int $staffUserId,
	?int $customIntervalDays = null,
	?string $reason = null
): bool {
	$conn = get_db_connection();

	if (!in_array($status, ['routine', 'special', 'sick', 'other'], true)) {
		return false;
	}

	// If routine, remove any custom status row
	if ($status === 'routine') {
		admin_execute(
			"DELETE FROM child_monitoring_status WHERE child_id = ?",
			'i',
			[$childId]
		);

		log_action(
			$staffUserId,
			'MONITORING_STATUS_REMOVED',
			'info',
			sprintf('Child #%d monitoring status reset to routine.', $childId)
		);

		return true;
	}

	// Upsert the monitoring status
	$existing = admin_fetch_one(
		"SELECT id FROM child_monitoring_status WHERE child_id = ? LIMIT 1",
		'i',
		[$childId]
	);

	if ($existing !== null) {
		admin_execute(
			"UPDATE child_monitoring_status
			 SET monitoring_status = ?,
			     custom_interval_days = ?,
			     reason = ?,
			     set_by = ?,
			     updated_at = NOW()
			 WHERE child_id = ?",
			'sisii',
			[$status, $customIntervalDays, $reason, $staffUserId, $childId]
		);
	} else {
		admin_execute(
			"INSERT INTO child_monitoring_status
			 (child_id, monitoring_status, custom_interval_days, reason, set_by, created_at, updated_at)
			 VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
			'isisi',
			[$childId, $status, $customIntervalDays, $reason, $staffUserId]
		);
	}

	log_action(
		$staffUserId,
		'MONITORING_STATUS_CHANGED',
		'info',
		sprintf(
			'Child #%d monitoring status set to "%s"%s%s.',
			$childId,
			$status,
			$customIntervalDays !== null ? ' (interval: ' . $customIntervalDays . ' days)' : '',
			$reason !== '' && $reason !== null ? ' — Reason: ' . $reason : ''
		)
	);

	return true;
}

/**
 * Check whether a child is due for measurement today (or within the grace window).
 *
 * This is the backend authority for whether a kiosk or manual measurement
 * is allowed. It checks:
 *   1. Is the child in eOPT coverage (age <= 59 months)?
 *   2. Has a measurement already been recorded today?
 *   3. Is today within the grace window of the next scheduled follow-up?
 *
 * @return array{is_due: bool, next_due: ?string, reason: string, monitoring_status: string}
 */
function followup_is_due_today(int $childId, ?DateTimeImmutable $asOf = null): array
{
	$conn = get_db_connection();
	$asOf ??= new DateTimeImmutable('today');
	$todayStr = $asOf->format('Y-m-d');

	// Fetch child with latest measurement and monitoring status
	$child = admin_fetch_one(
		'SELECT
			c.id,
			c.birthdate,
			c.sex,
			lm.measurement_date,
			lm.wfa_status,
			lm.hfa_status,
			lm.wfh_status,
			COALESCE(cms.monitoring_status, \'routine\') AS monitoring_status,
			cms.custom_interval_days,
			cms.reason AS monitoring_reason
		 FROM children c
		 LEFT JOIN measurements lm ON lm.id = (
			SELECT m.id FROM measurements m
			WHERE m.child_id = c.id
			ORDER BY m.measurement_date DESC, m.id DESC
			LIMIT 1
		 )
		 LEFT JOIN child_monitoring_status cms ON cms.child_id = c.id
		 WHERE c.id = ?
		 LIMIT 1',
		'i',
		[$childId]
	);

	if ($child === null) {
		return [
			'is_due' => false,
			'next_due' => null,
			'reason' => 'Child not found.',
			'monitoring_status' => 'routine',
		];
	}

	$ageMonths = followup_age_months((string)$child['birthdate'], $asOf);

	if ($ageMonths > 59) {
		return [
			'is_due' => false,
			'next_due' => null,
			'reason' => 'Child is ' . $ageMonths . ' months old — aged out of eOPT coverage (maximum 59 months).',
			'monitoring_status' => 'routine',
		];
	}

	// Check if a measurement already exists today
	$existingToday = admin_fetch_one(
		"SELECT id FROM measurements WHERE child_id = ? AND measurement_date = ? LIMIT 1",
		'is',
		[$childId, $todayStr]
	);

	if ($existingToday !== null) {
		return [
			'is_due' => false,
			'next_due' => null,
			'reason' => 'A measurement has already been recorded for this child today.',
			'monitoring_status' => (string)$child['monitoring_status'],
		];
	}

	// Classify child and compute next due date
	$classif = followup_classify_child($child, $asOf);

	if ($classif['track'] === null) {
		return [
			'is_due' => false,
			'next_due' => null,
			'reason' => 'Child is not in eOPT coverage.',
			'monitoring_status' => (string)$child['monitoring_status'],
		];
	}

	$nextDue = followup_next_due(
		$child['measurement_date'] ?? null,
		$classif['track'],
		$asOf,
		$classif['custom_interval_days'] ?? null
	);

	$nextDueStr = $nextDue->format('Y-m-d');

	// If no previous measurement, child is always due (needs baseline)
	if (empty($child['measurement_date'])) {
		return [
			'is_due' => true,
			'next_due' => $nextDueStr,
			'reason' => 'No measurement on record — baseline measurement required.',
			'monitoring_status' => (string)$child['monitoring_status'],
		];
	}

	// Check if today is within the grace window of the due date
	$daysUntilDue = (int)$asOf->diff($nextDue->setTime(0, 0))->format('%r%a');

	if ($daysUntilDue <= 0) {
		// Due today or overdue
		$reason = $daysUntilDue < 0
			? 'Overdue — measurement was due on ' . $nextDueStr . '.'
			: 'Measurement is due today.';

		return [
			'is_due' => true,
			'next_due' => $nextDueStr,
			'reason' => $reason,
			'monitoring_status' => (string)$child['monitoring_status'],
		];
	}

	if ($daysUntilDue <= FOLLOWUP_GRACE_DAYS) {
		return [
			'is_due' => true,
			'next_due' => $nextDueStr,
			'reason' => 'Within the ' . FOLLOWUP_GRACE_DAYS . '-day grace window (due in ' . $daysUntilDue . ' day' . ($daysUntilDue !== 1 ? 's' : '') . ').',
			'monitoring_status' => (string)$child['monitoring_status'],
		];
	}

	return [
		'is_due' => false,
		'next_due' => $nextDueStr,
		'reason' => 'Not due yet. Next scheduled measurement: ' . $nextDueStr . ' (in ' . $daysUntilDue . ' days).',
		'monitoring_status' => (string)$child['monitoring_status'],
	];
}

/**
 * Fetch children with their follow-up status for the monitoring dashboard.
 *
 * Returns an array of children with computed due-date status, suitable for
 * the follow-up monitoring table.
 *
 * @param array $user Current user (for barangay scope)
 * @param array $filters Optional filters: status, barangay_id, age_group, schedule, from, to, q
 * @return array
 */
function followup_fetch_monitoring_list(array $user, array $filters = []): array
{
	$conn = get_db_connection();

	$params = [];
	$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $params);

	$ageJoin = '';
	$ageFilter = '';

	// Build the main query with latest measurement + monitoring status
	$rows = admin_fetch_all(
		"SELECT
			c.id,
			c.child_code,
			c.first_name,
			c.last_name,
			c.birthdate,
			c.sex,
			c.barangay_id,
			bg.name AS barangay_name,
			lm.id AS last_measurement_id,
			lm.measurement_date,
			lm.height_cm AS last_height,
			lm.weight_kg AS last_weight,
			lm.wfa_status,
			lm.hfa_status,
			lm.wfh_status,
			lm.nutritional_status,
			COALESCE(cms.monitoring_status, 'routine') AS monitoring_status,
			cms.custom_interval_days,
			cms.reason AS monitoring_reason
		 FROM children c
		 LEFT JOIN barangays bg ON bg.id = c.barangay_id
		 LEFT JOIN measurements lm ON lm.id = (
			SELECT m.id FROM measurements m
			WHERE m.child_id = c.id
			ORDER BY m.measurement_date DESC, m.id DESC
			LIMIT 1
		 )
		 LEFT JOIN child_monitoring_status cms ON cms.child_id = c.id
		 WHERE {$scope}
		   AND c.status = 'active'
		 ORDER BY c.last_name ASC, c.first_name ASC",
		str_repeat('i', count($params)),
		$params
	);

	$today = new DateTimeImmutable('today');
	$result = [];

	foreach ($rows as $row) {
		$ageMonths = followup_age_months((string)$row['birthdate'], $today);

		if ($ageMonths > 59) {
			continue;
		}

		$classif = followup_classify_child($row, $today);
		$nextDue = followup_next_due(
			$row['measurement_date'] ?? null,
			$classif['track'],
			$today,
			$classif['custom_interval_days'] ?? null
		);

		$nextDueStr = $nextDue->format('Y-m-d');
		$daysUntilDue = (int)$today->diff($nextDue->setTime(0, 0))->format('%r%a');

		// Determine status
		$status = 'upcoming';
		if ((string)$row['monitoring_status'] !== 'routine') {
			$status = 'special_monitoring';
		} elseif (empty($row['measurement_date'])) {
			$status = 'due_today';
		} elseif ($daysUntilDue < 0) {
			$status = 'overdue';
		} elseif ($daysUntilDue === 0) {
			$status = 'due_today';
		} elseif ($daysUntilDue <= FOLLOWUP_GRACE_DAYS) {
			$status = 'due_soon';
		}

		// Check if measured today
		$measuredToday = ($row['measurement_date'] === $today->format('Y-m-d'));
		if ($measuredToday) {
			$status = 'completed';
		}

		// Apply filters
		if (isset($filters['status']) && $filters['status'] !== '' && $status !== $filters['status']) {
			continue;
		}
		if (isset($filters['barangay_id']) && $filters['barangay_id'] > 0 && (int)$row['barangay_id'] !== (int)$filters['barangay_id']) {
			continue;
		}
		if (isset($filters['schedule']) && $filters['schedule'] !== '' && ($classif['track'] ?? '') !== $filters['schedule']) {
			continue;
		}
		if (isset($filters['q']) && $filters['q'] !== '') {
			$haystack = strtolower($row['first_name'] . ' ' . $row['last_name'] . ' ' . $row['child_code']);
			if (strpos($haystack, strtolower($filters['q'])) === false) {
				continue;
			}
		}
		if (isset($filters['from']) && $filters['from'] !== '' && $nextDueStr < $filters['from']) {
			continue;
		}
		if (isset($filters['to']) && $filters['to'] !== '' && $nextDueStr > $filters['to']) {
			continue;
		}

		$result[] = [
			'id' => (int)$row['id'],
			'child_code' => (string)$row['child_code'],
			'first_name' => (string)$row['first_name'],
			'last_name' => (string)$row['last_name'],
			'birthdate' => (string)$row['birthdate'],
			'age_months' => $ageMonths,
			'barangay_id' => (int)$row['barangay_id'],
			'barangay_name' => (string)$row['barangay_name'],
			'last_measurement_date' => $row['measurement_date'] ?? null,
			'last_weight' => $row['last_weight'] !== null ? (float)$row['last_weight'] : null,
			'last_height' => $row['last_height'] !== null ? (float)$row['last_height'] : null,
			'nutritional_status' => $row['nutritional_status'] ?? null,
			'schedule_type' => $classif['track'] ?? null,
			'schedule_category' => $classif['category'] ?? '',
			'next_due' => $nextDueStr,
			'days_until_due' => $daysUntilDue,
			'status' => $status,
			'monitoring_status' => (string)$row['monitoring_status'],
			'custom_interval_days' => $row['custom_interval_days'] !== null ? (int)$row['custom_interval_days'] : null,
			'monitoring_reason' => $row['monitoring_reason'] ?? null,
			'measured_today' => $measuredToday,
		];
	}

	return $result;
}
