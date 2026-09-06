<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/nutritionist_helpers.php';
require_once __DIR__ . '/../../includes/ai_insights_helper.php';

header('Content-Type: application/json; charset=utf-8');

api_require_method(['GET', 'POST']);

$user = api_require_staff_session(['admin', 'nutritionist']);

$conn = get_db_connection();

$payload = api_payload();
$forceRefresh = !empty($payload['force']) || !empty($_GET['force']);

// ---- Scope ----
// The cache is keyed by the user's scope so an admin sees global insights
// and a barangay-scoped nutritionist sees local-area-aware insights
// without one stomping the other.
$scopeKey = 'all';
$barangayId = $user['barangay_id'] ?? null;
if (($user['role'] ?? '') !== 'admin' && $barangayId !== null && $barangayId !== '') {
    $scopeKey = 'brgy:' . (int)$barangayId;
}

$ttlSeconds = 6 * 60 * 60; // 6 hours

// ---- Read-through cache ----
$cached = null;
if (!$forceRefresh) {
    $stmt = $conn->prepare(
        'SELECT payload_json, source, generated_at, expires_at
         FROM nutritionist_ai_insight_cache
         WHERE scope_key = ? AND cache_key = ? AND expires_at > NOW()
         LIMIT 1'
    );
    $cacheKey = 'default';
    $stmt->bind_param('ss', $scopeKey, $cacheKey);
    $stmt->execute();
    $cached = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($cached !== null) {
    $decoded = json_decode((string)$cached['payload_json'], true);
    if (is_array($decoded) && isset($decoded['insights']) && is_array($decoded['insights'])) {
        echo json_encode([
            'success'      => true,
            'cached'       => true,
            'source'       => $cached['source'],
            'insights'     => $decoded['insights'],
            'generated_at' => $cached['generated_at'],
            'expires_at'   => $cached['expires_at'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ---- Build the summary text from already-computed WHO data ----
// All z-scores and statuses are deterministic outputs of the WHO calculator.
// The AI is only asked to summarize patterns in the data — never to
// diagnose individual children or predict outcomes.
$params = [];
$scopeSql = nutritionist_scope_fragment($user, 'c.barangay_id', $params);
$scopeTypes = str_repeat('i', count($params));

$totalChildren = (int)admin_scalar(
    "SELECT COUNT(*) FROM children c WHERE {$scopeSql}",
    $scopeTypes,
    $params
);

$params2 = [];
$scopeSql2 = nutritionist_scope_fragment($user, 'c.barangay_id', $params2);
$scopeTypes2 = str_repeat('i', count($params2));
$measuredCount = (int)admin_scalar(
    "SELECT COUNT(DISTINCT c.id) FROM children c
     INNER JOIN measurements m ON m.child_id = c.id
     WHERE {$scopeSql2}",
    $scopeTypes2,
    $params2
);

// Counts by status (last 30 days vs previous 30 days) for trend comparison.
$params3 = [];
$scopeSql3 = nutritionist_scope_fragment($user, 'c.barangay_id', $params3);
$scopeTypes3 = str_repeat('i', count($params3));

$trendSql = "SELECT
    COALESCE(nutritional_status, 'Unknown') AS status,
    SUM(CASE WHEN m.measurement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS last30,
    SUM(CASE WHEN m.measurement_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
              AND m.measurement_date <  DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS prev30
 FROM measurements m
 INNER JOIN children c ON c.id = m.child_id
 WHERE m.measurement_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
   AND {$scopeSql3}
 GROUP BY nutritional_status";

$trendRows = admin_fetch_all($trendSql, $scopeTypes3, $params3);

// HFA breakdown for the last 30 days. Derive the classification from the
// stored haz z-score so the breakdown stays in sync with the canonical
// `classify_hfa_status()` thresholds.
$hfaRows = admin_fetch_all(
    "SELECT CASE WHEN m.haz < -3 THEN 'SSt' WHEN m.haz < -2 THEN 'MSt' WHEN m.haz > 2 THEN 'Tall' ELSE 'Normal' END AS hfa, COUNT(*) AS cnt
     FROM measurements m
     INNER JOIN children c ON c.id = m.child_id
     WHERE m.measurement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
       AND {$scopeSql3}
     GROUP BY CASE WHEN m.haz < -3 THEN 'SSt' WHEN m.haz < -2 THEN 'MSt' WHEN m.haz > 2 THEN 'Tall' ELSE 'Normal' END",
    $scopeTypes3,
    $params3
);

// Top barangay by severe count.
$params4 = [];
$scopeSql4 = nutritionist_scope_fragment($user, 'c.barangay_id', $params4);
$scopeTypes4 = str_repeat('i', count($params4));
$topBarangay = admin_fetch_one(
    "SELECT bg.name AS name,
            COUNT(*) AS measured,
            SUM(CASE WHEN m.nutritional_status IN ('Severely Underweight','Severely Stunted','Severely Wasted') THEN 1 ELSE 0 END) AS severe
     FROM measurements m
     INNER JOIN children c ON c.id = m.child_id
     LEFT JOIN barangays bg ON bg.id = c.barangay_id
     WHERE m.measurement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
       AND {$scopeSql4}
     GROUP BY c.barangay_id, bg.name
     HAVING measured > 0
     ORDER BY severe DESC, measured DESC
     LIMIT 1",
    $scopeTypes4,
    $params4
);

// Stunting trend over last 3 months.
$params5 = [];
$scopeSql5 = nutritionist_scope_fragment($user, 'c.barangay_id', $params5);
$scopeTypes5 = str_repeat('i', count($params5));
$stuntingTrendRows = admin_fetch_all(
    "SELECT DATE_FORMAT(m.measurement_date, '%Y-%m') AS month_key,
            SUM(CASE WHEN m.hfa_status IN ('St','MSt','SSt') THEN 1 ELSE 0 END) AS stunted,
            COUNT(*) AS total
     FROM measurements m
     INNER JOIN children c ON c.id = m.child_id
     WHERE m.measurement_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
       AND {$scopeSql5}
     GROUP BY month_key
     ORDER BY month_key ASC",
    $scopeTypes5,
    $params5
);

// Upcoming appointments count.
$params6 = [];
$scopeSql6 = nutritionist_scope_fragment($user, 'c.barangay_id', $params6);
$scopeTypes6 = str_repeat('i', count($params6));
$upcomingAppt = (int)admin_scalar(
    "SELECT COUNT(*) FROM appointments a
     INNER JOIN children c ON c.id = a.child_id
     WHERE a.scheduled_at >= NOW() AND a.status IN ('pending','confirmed')
       AND {$scopeSql6}",
    $scopeTypes6,
    $params6
);

// ---- Build the summary text block ----
$lines = [];
$lines[] = 'Nutritionist dashboard scope: ' . $scopeKey;
$lines[] = "Total children in scope: {$totalChildren}; measured in last 30 days: {$measuredCount}";
$lines[] = '';
$lines[] = 'Status trend (last 30 days vs. previous 30 days):';
foreach ($trendRows as $row) {
    $lines[] = sprintf('  - %s: %d (prev: %d)', $row['status'], (int)$row['last30'], (int)$row['prev30']);
}
$lines[] = '';
$lines[] = 'HFA breakdown (last 30 days):';
foreach ($hfaRows as $row) {
    $lines[] = sprintf('  - %s: %d', $row['hfa'], (int)$row['cnt']);
}
$lines[] = '';
if ($topBarangay !== null) {
    $pct = (int)$topBarangay['measured'] > 0
        ? round(((int)$topBarangay['severe'] / (int)$topBarangay['measured']) * 100)
        : 0;
    $lines[] = sprintf('Top barangay by severe cases: %s (%d severe of %d measured, %d%%)',
        (string)$topBarangay['name'], (int)$topBarangay['severe'], (int)$topBarangay['measured'], $pct);
} else {
    $lines[] = 'No barangay data in scope.';
}
$lines[] = '';
$lines[] = 'Stunting trend (last 3 months):';
foreach ($stuntingTrendRows as $row) {
    $pct = (int)$row['total'] > 0 ? round(((int)$row['stunted'] / (int)$row['total']) * 100) : 0;
    $lines[] = sprintf('  - %s: %d stunted of %d measured (%d%%)', $row['month_key'], (int)$row['stunted'], (int)$row['total'], $pct);
}
$lines[] = '';
$lines[] = "Upcoming appointments (pending/confirmed): {$upcomingAppt}";

$summaryText = implode("\n", $lines);

// ---- Call the AI helper with a rule-based fallback ----
$systemMessage = 'You are a public-health analyst for a child nutrition monitoring system called Sukat Kalusugan. '
    . 'Analyze the following nutrition-scope summary and return exactly 3-5 short insight bullets in JSON format: '
    . '{"insights": ["insight 1", "insight 2", ...]}. '
    . 'Each insight should be 1-2 sentences max, written in plain language for a local nutritionist. '
    . 'Focus on: month-over-month change in severe cases, which barangay is driving that change, whether '
    . 'stunting is improving or worsening, the gap between measured vs. unmeasured children, and capacity '
    . 'signals (appointments vs. flagged children). '
    . 'Do NOT diagnose individual children, do NOT predict future outcomes, do NOT use markdown. '
    . 'Return ONLY valid JSON.';

$aiResult = ai_insights_generate([
    'summary_text'   => $summaryText,
    'system_message' => $systemMessage,
    'feature_tag'    => 'nutritionist_dashboard',
    'fallback'       => function () use ($trendRows, $topBarangay, $stuntingTrendRows, $totalChildren, $measuredCount, $upcomingAppt) {
        $insights = [];

        $severeLast = 0;
        $severePrev = 0;
        foreach ($trendRows as $row) {
            if (in_array($row['status'], ['Severely Underweight', 'Severely Stunted', 'Severely Wasted'], true)) {
                $severeLast += (int)$row['last30'];
                $severePrev += (int)$row['prev30'];
            }
        }
        if ($severeLast > 0 || $severePrev > 0) {
            if ($severeLast > $severePrev) {
                $insights[] = "Severe cases rose from {$severePrev} to {$severeLast} this month.";
            } elseif ($severeLast < $severePrev) {
                $insights[] = "Severe cases dropped from {$severePrev} to {$severeLast} this month.";
            } else {
                $insights[] = "Severe cases held steady at {$severeLast} this month.";
            }
        }

        if ($topBarangay !== null) {
            $pct = (int)$topBarangay['measured'] > 0
                ? round(((int)$topBarangay['severe'] / (int)$topBarangay['measured']) * 100)
                : 0;
            $insights[] = sprintf('%s has the highest severe count this month (%d of %d measured, %d%%).',
                (string)$topBarangay['name'], (int)$topBarangay['severe'], (int)$topBarangay['measured'], $pct);
        }

        $stuntingFirst = 0;
        $stuntingLast = 0;
        $stuntingTotal = count($stuntingTrendRows);
        if ($stuntingTotal > 0) {
            $first = $stuntingTrendRows[0];
            $last = $stuntingTrendRows[$stuntingTotal - 1];
            $stuntingFirst = (int)$first['total'] > 0 ? round(((int)$first['stunted'] / (int)$first['total']) * 100) : 0;
            $stuntingLast  = (int)$last['total']  > 0 ? round(((int)$last['stunted']  / (int)$last['total'])  * 100) : 0;
        }
        if ($stuntingTotal >= 2) {
            if ($stuntingLast > $stuntingFirst) {
                $insights[] = "Stunting prevalence has risen over the last {$stuntingTotal} months ({$stuntingFirst}% → {$stuntingLast}%).";
            } elseif ($stuntingLast < $stuntingFirst) {
                $insights[] = "Stunting prevalence has improved over the last {$stuntingTotal} months ({$stuntingFirst}% → {$stuntingLast}%).";
            } else {
                $insights[] = "Stunting prevalence has been flat at {$stuntingLast}% for {$stuntingTotal} months.";
            }
        }

        $unmeasured = $totalChildren - $measuredCount;
        if ($totalChildren > 0) {
            $unmeasuredPct = round(($unmeasured / $totalChildren) * 100);
            if ($unmeasuredPct >= 20) {
                $insights[] = "{$unmeasured} of {$totalChildren} children in scope ({$unmeasuredPct}%) have no recent measurement.";
            }
        }

        if ($upcomingAppt > 0 && $severeLast > 0 && $upcomingAppt < $severeLast) {
            $insights[] = "Only {$upcomingAppt} upcoming appointment(s) vs. {$severeLast} severe case(s) — consider scheduling more Oplan Timbang sessions.";
        } elseif ($upcomingAppt === 0) {
            $insights[] = "No upcoming appointments scheduled.";
        }

        if (empty($insights)) {
            $insights[] = "No actionable patterns in the current scope.";
        }

        return ['source' => 'rule_based', 'insights' => $insights];
    },
]);

$aiResult['insights'] = array_slice($aiResult['insights'], 0, 3);
$aiResult['insights'] = array_map(static function (string $text): string {
	$text = trim($text);
	return strlen($text) > 140 ? substr($text, 0, 137) . '...' : $text;
}, $aiResult['insights']);

// ---- Write the result back to the cache (upsert) ----
$expiresAt = (new DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');
$payloadJson = json_encode(['insights' => $aiResult['insights']], JSON_UNESCAPED_UNICODE);
$source = $aiResult['source'] === 'ai' ? 'ai' : 'rule_based';

$stmt = $conn->prepare(
    'INSERT INTO nutritionist_ai_insight_cache
        (scope_key, cache_key, payload_json, source, generated_at, expires_at)
     VALUES (?, ?, ?, ?, NOW(), ?)
     ON DUPLICATE KEY UPDATE
        payload_json = VALUES(payload_json),
        source       = VALUES(source),
        generated_at = NOW(),
        expires_at   = VALUES(expires_at)'
);
$cacheKey = 'default';
$stmt->bind_param('sssss', $scopeKey, $cacheKey, $payloadJson, $source, $expiresAt);
$stmt->execute();
$stmt->close();

echo json_encode([
    'success'      => true,
    'cached'       => false,
    'source'       => $aiResult['source'],
    'insights'     => $aiResult['insights'],
    'generated_at' => date('Y-m-d H:i:s'),
    'expires_at'   => $expiresAt,
    'scope'        => $scopeKey,
], JSON_UNESCAPED_UNICODE);
