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
 * Runs one automatic synchronization pass over every child inside the
 * current user's barangay scope:
 *
 *   1. AUTO-COMPLETE any open follow-up already satisfied by a newer
 *      measurement (taken within the grace window before/at the due date);
 *   2. GENERATE the missing next-cycle follow-up appointment per child.
 *
 * Called automatically on the Appointments page load; safe to call often.
 *
 * @return array{generated: int, completed: int}
 */
function followup_sync_for_scope(array $user): array
{
	$scopeParams = [];
	$scope = nutritionist_scope_fragment($user, 'c.barangay_id', $scopeParams);

	$children = admin_fetch_all(
		"SELECT
			c.id,
			c.child_code,
			c.first_name,
			c.last_name,
			c.birthdate,
			c.parent_id,
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
		 WHERE {$scope}",
		str_repeat('i', count($scopeParams)),
		$scopeParams
	);

	$openFollowups = admin_fetch_all(
		"SELECT id, child_id, scheduled_at
		 FROM appointments
		 WHERE appointment_type = 'followup'
		   AND status IN ('pending', 'confirmed')"
	);

	$openByChild = [];
	foreach ($openFollowups as $row) {
		$openByChild[(int)$row['child_id']][] = $row;
	}

	$today = new DateTimeImmutable('today');
	$generated = 0;
	$completed = 0;

	foreach ($children as $child) {
		$childId = (int)$child['id'];

		/*
		 | Pass 1 — auto-completion: the follow-up cycle is satisfied when a
		 | measurement exists dated on/after (scheduled date - grace days),
		 | i.e. the family showed up for the mandatory re-measurement.
		 */
		foreach ($openByChild[$childId] ?? [] as $followup) {
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
					unset($openByChild[$childId]);
				}
			}
		}

		/*
		 | Pass 2 — generation: exactly ONE open follow-up per child at a
		 | time. When none remains, book the next mandatory cycle.
		 */
		if (($openByChild[$childId] ?? []) !== []) {
			continue;
		}

		$classif = followup_classify_child($child, $today);

		if ($classif['track'] === null) {
			continue;
		}

		$due = followup_next_due(
			$child['measurement_date'] ?? null,
			$classif['track'],
			$today
		)->setTime(9, 0, 0);

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
				(int)$user['id'],
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

	if ($generated > 0 || $completed > 0) {
		log_action(
			(int)($user['id'] ?? 0) ?: null,
			'FOLLOWUP_SYNC',
			'info',
			sprintf(
				'EOPT follow-up sync: %d generated, %d auto-completed.',
				$generated,
				$completed
			)
		);
	}

	return ['generated' => $generated, 'completed' => $completed];
}
