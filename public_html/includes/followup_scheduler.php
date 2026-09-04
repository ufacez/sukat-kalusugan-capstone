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
 *   QUARTERLY track (re-measure in the April / July / October rounds):
 *     - older children 24-59 months classified NORMAL on all axes.
 *       Q1 (January-March) is the annual OPT baseline round; normal
 *       children from that round are re-checked every quarter.
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

const FOLLOWUP_QUARTER_MONTHS = [4, 7, 10];
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

		if ($axis === 'wfa' && in_array($value, ['SUW', 'UW', 'MUW'], true)) {
			$codes[] = $value;
		} elseif ($axis === 'hfa' && in_array($value, ['SSt', 'St', 'MSt'], true)) {
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
 * @return array{track: ?string, category: string, reason: string}
 */
function followup_classify_child(array $child, ?DateTimeImmutable $asOf = null): array
{
	$ageNow = followup_age_months((string)$child['birthdate'], $asOf);

	if ($ageNow > 59) {
		return ['track' => null, 'category' => '', 'reason' => 'Over 59 months — graduated from eOPT coverage.'];
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
		];
	}

	if (!$hasMeasurement) {
		return [
			'track' => 'monthly',
			'category' => 'Needs baseline',
			'reason' => 'No OPT measurement on record — baseline weighing required.',
		];
	}

	if ($abnormal !== []) {
		$category = implode('+', $abnormal);

		return [
			'track' => 'monthly',
			'category' => $category,
			'reason' => 'Malnourished (' . followup_category_label($category) . ') — mandatory monthly re-measurement.',
		];
	}

	return [
		'track' => 'quarterly',
		'category' => 'Normal',
		'reason' => 'Normal — quarterly re-check (April / July / October rounds).',
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
function followup_next_due(?string $lastMeasuredDate, string $track, ?DateTimeImmutable $asOf = null): DateTimeImmutable
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
	?DateTimeImmutable $today = null
): array {
	$today ??= new DateTimeImmutable('today');
	$classif = followup_classify_child([
		'birthdate' => (string)$birthdate,
		'measurement_date' => $lastMeasuredDate,
		'wfa_status' => $wfaStatus,
		'hfa_status' => $hfaStatus,
		'wfh_status' => $wfhStatus,
	], $today);

	$idle = ['due' => null, 'state' => 'none', 'label' => 'Not in eOPT coverage', 'class' => 'is-muted'];

	if ($classif['track'] === null) {
		return $idle;
	}

	$due = followup_next_due($lastMeasuredDate, $classif['track'], $today);
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
			lm.wfh_status
		 FROM children c
		 LEFT JOIN measurements lm ON lm.id = (
			SELECT m.id FROM measurements m
			WHERE m.child_id = c.id
			ORDER BY m.measurement_date DESC, m.id DESC
			LIMIT 1
		 )
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
		$today
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

	$stmt = mysqli_prepare(
		$conn,
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
