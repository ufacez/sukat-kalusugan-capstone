<?php

/**
 * monitoring_periods.php
 *
 * Period-based eOPT monitoring engine (replaces followup_scheduler.php).
 *
 * Rules (DOH eOPT Plus community protocol, calendar periods):
 *
 *   MONTHLY roster — measured at any time within the calendar month:
 *     - ALL children aged 0-23 months, regardless of status;
 *     - children 24-59 months with an abnormal WHO indicator on any axis
 *       (SUW/MUW underweight, SSt/MSt stunting, SW/MW wasting, OW/Ob).
 *
 *   QUARTERLY roster — measured at any time within the calendar quarter:
 *     - Q1 Jan-Mar, Q2 Apr-Jun, Q3 Jul-Sep, Q4 Oct-Dec;
 *     - children 24-59 months classified NORMAL on all axes.
 *
 * There are no exact due dates, no grace windows, no overdue carry-over,
 * and no baseline category: a child counts as measured for a period when a
 * weighing (ROUTINE, OVERRIDE, or RECHECK) falls inside that period.
 * A recheck carries the newest verified values, so the roster's current
 * status/values follow it — the same-month rule keeps the recheck inside
 * the verified month, so it confirms rather than disrupts coverage.
 * The 0-23 vs 24-59 split uses age at the END of the period, so a child
 * who turns 24 mid-period automatically moves to quarterly.
 * Children over 59 months (age at the END of the period) have graduated
 * from eOPT coverage. Children with no
 * scheduled measurement on record yet are NOT listed at all — registered
 * but never-measured children only join their roster after their first
 * weighing.
 *
 * Also hosts the shared DOH-report helpers that survived the rebuild:
 *   - MONITORING_REPORT_ROUNDS — legacy April/July/October round months
 *     used by the eOPT Reports period selectors (unchanged behavior);
 *   - monitoring_abnormal_codes() / monitoring_category_label();
 *   - eopt_fetch_followup_sequence_map() / eopt_followup_cell() — the
 *     0-23 list Month#N columns, now resolved from the child's Nth
 *     scheduled (ROUTINE/OVERRIDE) measurement instead of deleted
 *     follow-up appointment rows.
 */

declare(strict_types=1);

require_once __DIR__ . '/nutritionist_helpers.php';

/** DOH report round months for the eOPT Reports selectors (legacy). */
const MONITORING_REPORT_ROUNDS = [4, 7, 10];

/**
 * Calendar-quarter window: Q1 Jan-Mar, Q2 Apr-Jun, Q3 Jul-Sep, Q4 Oct-Dec.
 *
 * @return array{start: string, end: string, label: string, months: int[]}
 */
function monitoring_quarter_range(int $year, int $quarter): array
{
    $quarter = max(1, min(4, $quarter));
    $firstMonth = ($quarter - 1) * 3 + 1;
    $months = [$firstMonth, $firstMonth + 1, $firstMonth + 2];

    try {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $firstMonth));
        $end = $start->modify('+2 months')->modify('last day of this month');
    } catch (Exception) {
        $start = new DateTimeImmutable('first day of january this year');
        $end = new DateTimeImmutable('last day of march this year');
    }

    $names = array_map(
        static fn(int $m): string => DateTimeImmutable::createFromFormat('!n', (string)$m)->format('M'),
        $months
    );

    return [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'label' => sprintf('Q%d (%s)', $quarter, implode('-', $names)),
        'months' => $months,
    ];
}

/**
 * Calendar-month window.
 *
 * @return array{start: string, end: string, label: string}
 */
function monitoring_month_range(int $year, int $month): array
{
    $month = max(1, min(12, $month));

    try {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('last day of this month');
    } catch (Exception) {
        $start = new DateTimeImmutable('first day of this month');
        $end = new DateTimeImmutable('last day of this month');
    }

    return [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'label' => $start->format('F Y'),
    ];
}

/**
 * Age in completed months, the same convention DOH uses on eOPT forms.
 */
function monitoring_age_months(string $birthdate, ?DateTimeImmutable $asOf = null): int
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
 * Human label for a status code combo like "SUW+SSt" or "SW".
 */
function monitoring_category_label(string $category): string
{
    $names = [
        'SUW' => 'Severely Underweight',
        'MUW' => 'Moderately Underweight',
        'SSt' => 'Severely Stunted',
        'MSt' => 'Moderately Stunted',
        'SW' => 'Severely Wasted / SAM',
        'SW/SAM' => 'Severely Wasted / SAM',
        'MW' => 'Moderately Wasted / MAM',
        'MW/MAM' => 'Moderately Wasted / MAM',
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
function monitoring_abnormal_codes(?string $wfa, ?string $hfa, ?string $wfh): array
{
    $codes = [];

    foreach (['wfa' => $wfa, 'hfa' => $hfa, 'wfh' => $wfh] as $axis => $value) {
        $value = (string)$value;

        if ($axis === 'wfa' && in_array($value, ['SUW', 'MUW'], true)) {
            $codes[] = $value;
        } elseif ($axis === 'hfa' && in_array($value, ['SSt', 'MSt'], true)) {
            $codes[] = $value;
        } elseif ($axis === 'wfh' && in_array($value, ['SW', 'SW/SAM', 'MW', 'MW/MAM', 'OW', 'Ob'], true)) {
            // Normalize display variants back to stored codes so downstream
            // category logic keeps working on SW/MW.
            if ($value === 'SW/SAM') $value = 'SW';
            if ($value === 'MW/MAM') $value = 'MW';
            $codes[] = $value;
        }
    }

    return array_values(array_unique($codes));
}

/** @deprecated Use monitoring_abnormal_codes(). Kept for surviving callers. */
function followup_abnormal_codes(?string $wfa, ?string $hfa, ?string $wfh): array
{
    return monitoring_abnormal_codes($wfa, $hfa, $wfh);
}

/** @deprecated Use monitoring_category_label(). Kept for surviving callers. */
function followup_category_label(string $category): string
{
    return monitoring_category_label($category);
}

/**
 * Fetches one monitoring roster for a calendar period.
 *
 * Membership is evaluated at the END of the period (age + latest scheduled
 * measurement on or before period end). Completion is period coverage: any
 * weighing (ROUTINE, OVERRIDE, or RECHECK) dated inside [start, end] counts.
 *
 * @param array $user Current user (for barangay scope)
 * @param string $kind 'monthly' or 'quarterly'
 * @return array List rows with measured_in_period bool
 */
function monitoring_fetch_list(array $user, string $kind, string $periodStart, string $periodEnd): array
{
    $params = [];
    $scope = nutritionist_scope_fragment($user, 'c.barangay_id', $params);
    $scopeTypes = str_repeat('i', count($params));

    // NOTE: bind order follows SQL text order — the five period-date
    // placeholders come first, then the scope param(s), then the anchor.
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
            p.name AS parent_name,
            p.phone AS parent_phone,
            lm.measurement_date AS last_measurement_date,
            lm.weight_kg AS last_weight,
            lm.height_cm AS last_height,
            lm.wfa_status,
            lm.hfa_status,
            lm.wfh_status,
            lm.nutritional_status,
            EXISTS (
                SELECT 1 FROM measurements m
                WHERE m.child_id = c.id
                  AND m.measurement_type IN ('ROUTINE','OVERRIDE','RECHECK')
                  AND m.measurement_date BETWEEN ? AND ?
            ) AS measured_in_period,
            (
                SELECT m2.measurement_date FROM measurements m2
                WHERE m2.child_id = c.id
                  AND m2.measurement_type IN ('ROUTINE','OVERRIDE','RECHECK')
                  AND m2.measurement_date BETWEEN ? AND ?
                ORDER BY m2.measurement_date DESC, m2.id DESC
                LIMIT 1
            ) AS period_measurement_date
         FROM children c
         LEFT JOIN barangays bg ON bg.id = c.barangay_id
         LEFT JOIN parents p ON p.id = c.parent_id
         LEFT JOIN measurements lm ON lm.id = (
             SELECT m3.id FROM measurements m3
            WHERE m3.child_id = c.id
              AND m3.measurement_type IN ('ROUTINE','OVERRIDE','RECHECK')
              AND m3.measurement_date <= ?
            ORDER BY m3.measurement_date DESC, m3.id DESC
            LIMIT 1
         )
         WHERE {$scope}
           AND c.status = 'active'
           AND TIMESTAMPDIFF(MONTH, c.birthdate, ?) BETWEEN 0 AND 59
         ORDER BY c.child_code DESC",
        'sssss' . $scopeTypes . 's',
        array_merge([$periodStart, $periodEnd, $periodStart, $periodEnd, $periodEnd], $params, [$periodEnd])
    );

    $endAnchor = new DateTimeImmutable($periodEnd);
    $result = [];

    foreach ($rows as $row) {
        $ageMonths = monitoring_age_months((string)$row['birthdate'], $endAnchor);
        if ($ageMonths > 59) {
            continue;
        }

        $abnormal = monitoring_abnormal_codes(
            $row['wfa_status'] ?? null,
            $row['hfa_status'] ?? null,
            $row['wfh_status'] ?? null
        );
        $hasMeasurement = !empty($row['last_measurement_date']);

        // Registered but never-measured children stay off the roster
        // until their first weighing.
        if (!$hasMeasurement) {
            continue;
        }

        if ($kind === 'monthly') {
            // 0-23 always monthly; 24-59 only when abnormal.
            // Age is evaluated at the END of the period, so a child who
            // turns 24 mid-period automatically moves to quarterly.
            if ($ageMonths >= 24 && $abnormal === []) {
                continue;
            }
        } else {
            // Quarterly is strictly 24-59 normal, age at the END of the
            // period — turning 24 mid-period moves the child here automatically.
            if ($ageMonths <= 23) {
                continue;
            }
            if ($abnormal !== []) {
                continue;
            }
        }

        $category = $abnormal !== [] ? implode('+', $abnormal) : 'Normal';

        $result[] = [
            'id' => (int)$row['id'],
            'child_code' => (string)$row['child_code'],
            'first_name' => (string)$row['first_name'],
            'last_name' => (string)$row['last_name'],
            'birthdate' => (string)$row['birthdate'],
            'sex' => (string)($row['sex'] ?? ''),
            'age_months' => $ageMonths,
            'barangay_id' => (int)$row['barangay_id'],
            'barangay_name' => (string)($row['barangay_name'] ?? ''),
            'parent_name' => (string)($row['parent_name'] ?? ''),
            'parent_phone' => (string)($row['parent_phone'] ?? ''),
            'last_measurement_date' => $row['last_measurement_date'] ?? null,
            'has_measurement' => $hasMeasurement,
            'last_weight' => $row['last_weight'] !== null ? (float)$row['last_weight'] : null,
            'last_height' => $row['last_height'] !== null ? (float)$row['last_height'] : null,
            'wfa_status' => $row['wfa_status'] ?? null,
            'hfa_status' => $row['hfa_status'] ?? null,
            'wfh_status' => $row['wfh_status'] ?? null,
            'nutritional_status' => $row['nutritional_status'] ?? null,
            'category' => $category,
            'measured_in_period' => ((int)($row['measured_in_period'] ?? 0)) === 1,
            'period_measurement_date' => $row['period_measurement_date'] ?? null,
        ];
    }

    // Newest child code first (CHD-0017 before CHD-0001).
    usort($result, static function (array $a, array $b): int {
        return strnatcmp((string)$b['child_code'], (string)$a['child_code']);
    });

    return $result;
}

/**
 * Measurement sequence map for the 0-23 list Month# columns.
 *
 * Month#N = the Nth scheduled (ROUTINE/OVERRIDE) measurement of that child
 * in chronological order, so Month#1 is the child's first weighing ever.
 * Returns [child_id => [0 => ['scheduled_at'=>..., 'status'=>...], ...]]
 * with at most $maxVisits entries per child (index 0 = Month#1).
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
        "SELECT child_id, measurement_date AS scheduled_at, 'completed' AS status
         FROM measurements
         WHERE child_id IN ({$placeholders})
           AND measurement_type IN ('ROUTINE','OVERRIDE')
         ORDER BY child_id ASC, measurement_date ASC, id ASC",
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
function eopt_followup_cell(?array $visit = null): array
{
    if ($visit === null || ($visit['scheduled_at'] ?? '') === '') {
        return ['—', 'No measurement visit'];
    }
    try {
        $dt = new DateTimeImmutable((string)$visit['scheduled_at']);
        return [$dt->format('M j, Y'), $dt->format('M j, Y g:i A')];
    } catch (Exception) {
        return ['—', 'No measurement visit'];
    }
}
