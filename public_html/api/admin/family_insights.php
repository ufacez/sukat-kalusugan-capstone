<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/ai_insights_helper.php';

header('Content-Type: application/json; charset=utf-8');

api_require_method(['GET', 'POST']);

// Same gate as the dashboard page itself (require_permission('dashboard.view')
// is access-level based, so nutritionists with view access can open it).
// City-wide aggregate counts only — safe for both staff roles.
api_require_staff_session(['admin', 'nutritionist']);

$conn = get_db_connection();

$payload = api_payload();
$forceRefresh = !empty($payload['force']) || !empty($_GET['force']);

$scopeKey = 'admin:city';
$cacheKey = 'family_v2';
$ttlSeconds = 6 * 60 * 60; // 6 hours

// ---- Read-through cache ----
if (!$forceRefresh) {
    $stmt = $conn->prepare(
        'SELECT payload_json, source, generated_at, expires_at
         FROM nutritionist_ai_insight_cache
         WHERE scope_key = ? AND cache_key = ? AND expires_at > NOW()
         LIMIT 1'
    );
    if ($stmt !== false) {
        $stmt->bind_param('ss', $scopeKey, $cacheKey);
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (is_array($cached)) {
            $decoded = json_decode((string)$cached['payload_json'], true);
            if (is_array($decoded) && isset($decoded['insights']) && is_array($decoded['insights'])) {
                // Never serve a poisoned/empty cache row: blanks don't count.
                $cachedInsights = array_values(array_filter(array_map(static function ($t): string {
                    return trim((string)$t);
                }, $decoded['insights']), static function (string $t): bool {
                    return $t !== '';
                }));
                if ($cachedInsights !== []) {
                    echo json_encode([
                        'success' => true,
                        'cached' => true,
                        'source' => $cached['source'],
                        'insights' => array_slice($cachedInsights, 0, 3),
                        'generated_at' => $cached['generated_at'],
                        'expires_at' => $cached['expires_at'],
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        }
    }
}

// ---- City-wide family + nutrition summary (MySQL is source of truth) ----
$totalParents = (int)admin_scalar("SELECT COUNT(*) FROM parents WHERE status = 'active'");
$totalChildren = (int)admin_scalar("SELECT COUNT(*) FROM children WHERE status = 'active'");

$measuredCount = (int)admin_scalar(
    "SELECT COUNT(DISTINCT m.child_id) FROM measurements m
     INNER JOIN children c ON c.id = m.child_id
     WHERE m.measurement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
);

$topFamilyBarangays = admin_fetch_all(
    "SELECT b.name AS name,
            COUNT(DISTINCT p.id) AS parents,
            COUNT(DISTINCT c.id) AS children,
            (COUNT(DISTINCT p.id) + COUNT(DISTINCT c.id)) AS total
     FROM barangays b
     LEFT JOIN parents p ON p.barangay_id = b.id AND p.status = 'active'
     LEFT JOIN children c ON c.barangay_id = b.id AND c.status = 'active'
     WHERE b.status = 'active'
     GROUP BY b.id, b.name
     HAVING total > 0
     ORDER BY total DESC, b.name ASC
     LIMIT 3"
);

$trendRows = admin_fetch_all(
    "SELECT COALESCE(nutritional_status, 'Unknown') AS status,
            SUM(CASE WHEN m.measurement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS last30,
            SUM(CASE WHEN m.measurement_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                      AND m.measurement_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS prev30
     FROM measurements m
     WHERE m.measurement_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
     GROUP BY nutritional_status"
);

$topSevereBarangay = admin_fetch_one(
    "SELECT bg.name AS name,
            COUNT(*) AS measured,
            SUM(CASE WHEN m.nutritional_status IN ('Severely Underweight','Severely Stunted','Severely Wasted') THEN 1 ELSE 0 END) AS severe
     FROM measurements m
     INNER JOIN children c ON c.id = m.child_id
     LEFT JOIN barangays bg ON bg.id = c.barangay_id
     WHERE m.measurement_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY c.barangay_id, bg.name
     HAVING measured > 0
     ORDER BY severe DESC, measured DESC
     LIMIT 1"
);

$stuntingTrendRows = admin_fetch_all(
    "SELECT DATE_FORMAT(m.measurement_date, '%Y-%m') AS month_key,
            SUM(CASE WHEN m.hfa_status IN ('St','MSt','SSt') THEN 1 ELSE 0 END) AS stunted,
            COUNT(*) AS total
     FROM measurements m
     WHERE m.measurement_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
     GROUP BY month_key
     ORDER BY month_key ASC"
);

$upcomingAppt = (int)admin_scalar(
    "SELECT COUNT(*) FROM appointments
     WHERE scheduled_at >= NOW() AND status IN ('pending','confirmed')"
);

$lines = [];
$lines[] = 'City-wide family scope: City of San Fernando, Pampanga (all barangays).';
$lines[] = "Registered families: {$totalParents} parents, {$totalChildren} children; measured in last 30 days: {$measuredCount}.";
$lines[] = '';
$lines[] = 'Top barangays by registered families:';
foreach ($topFamilyBarangays as $row) {
    $lines[] = sprintf('  - %s: %d parents, %d children', (string)$row['name'], (int)$row['parents'], (int)$row['children']);
}
$lines[] = '';
$lines[] = 'Status trend (last 30 days vs. previous 30 days):';
foreach ($trendRows as $row) {
    $lines[] = sprintf('  - %s: %d (prev: %d)', (string)$row['status'], (int)$row['last30'], (int)$row['prev30']);
}
$lines[] = '';
if ($topSevereBarangay !== null) {
    $measured = (int)$topSevereBarangay['measured'];
    $severe = (int)$topSevereBarangay['severe'];
    $pct = $measured > 0 ? round(($severe / $measured) * 100) : 0;
    $lines[] = sprintf(
        'Top barangay by severe cases: %s (%d severe of %d measured, %d%%)',
        (string)$topSevereBarangay['name'],
        $severe,
        $measured,
        $pct
    );
} else {
    $lines[] = 'No severe-case barangay data in the last 30 days.';
}
$lines[] = '';
$lines[] = 'Stunting trend (last 3 months):';
foreach ($stuntingTrendRows as $row) {
    $total = (int)$row['total'];
    $stunted = (int)$row['stunted'];
    $pct = $total > 0 ? round(($stunted / $total) * 100) : 0;
    $lines[] = sprintf('  - %s: %d stunted of %d measured (%d%%)', (string)$row['month_key'], $stunted, $total, $pct);
}
$lines[] = '';
$lines[] = "Upcoming appointments (pending/confirmed): {$upcomingAppt}";

$summaryText = implode("\n", $lines);

$systemMessage = 'You are a public-health analyst for a child nutrition monitoring system called Sukat Kalusugan. '
    . 'Analyze the following city-wide family summary and return exactly 3 short insight bullets in JSON format: '
    . '{"insights": ["insight 1", "insight 2", "insight 3"]}. '
    . 'Each insight must be 1-2 sentences max, plain language for a city administrator. '
    . 'Focus on: which barangays concentrate families, coverage gaps (unmeasured children), '
    . 'and severe/stunting signals with the single most-affected barangay. '
    . 'Do NOT diagnose individual children, do NOT predict future outcomes, do NOT use markdown. '
    . 'Return ONLY valid JSON.';

if (!function_exists('admin_family_rule_insights')) {
    /**
     * Deterministic rule-based city-family insights. Always returns 1-3
     * bullets so the dashboard never renders an empty panel, and doubles
     * as the backstop when the AI provider returns an empty list.
     */
    function admin_family_rule_insights(
        array $trendRows,
        array $topFamilyBarangays,
        ?array $topSevereBarangay,
        array $stuntingTrendRows,
        int $totalParents,
        int $totalChildren,
        int $measuredCount,
        int $upcomingAppt
    ): array {
        $insights = [];

        if (!empty($topFamilyBarangays)) {
            $top = $topFamilyBarangays[0];
            $insights[] = sprintf(
                '%s has the most registered families (%d parents, %d children).',
                (string)$top['name'],
                (int)$top['parents'],
                (int)$top['children']
            );
        }

        $unmeasured = max(0, $totalChildren - $measuredCount);
        if ($totalChildren > 0) {
            $pct = round(($unmeasured / $totalChildren) * 100);
            if ($unmeasured > 0) {
                $insights[] = "{$unmeasured} of {$totalChildren} children ({$pct}%) have no measurement in the last 30 days.";
            } else {
                $insights[] = "All {$totalChildren} registered children were measured in the last 30 days.";
            }
        } elseif ($totalParents > 0) {
            $insights[] = "{$totalParents} parents registered but no active children records yet.";
        }

        $severeLast = 0;
        $severePrev = 0;
        foreach ($trendRows as $row) {
            if (in_array((string)$row['status'], ['Severely Underweight', 'Severely Stunted', 'Severely Wasted'], true)) {
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
        } elseif ($topSevereBarangay !== null) {
            $insights[] = sprintf(
                '%s leads severe counts this month (%d of %d measured).',
                (string)$topSevereBarangay['name'],
                (int)$topSevereBarangay['severe'],
                (int)$topSevereBarangay['measured']
            );
        }

        if (count($insights) < 3 && !empty($stuntingTrendRows) && count($stuntingTrendRows) >= 2) {
            $first = $stuntingTrendRows[0];
            $last = $stuntingTrendRows[count($stuntingTrendRows) - 1];
            $firstPct = (int)$first['total'] > 0 ? round(((int)$first['stunted'] / (int)$first['total']) * 100) : 0;
            $lastPct = (int)$last['total'] > 0 ? round(((int)$last['stunted'] / (int)$last['total']) * 100) : 0;
            if ($lastPct > $firstPct) {
                $insights[] = "Stunting prevalence rose over the last 3 months ({$firstPct}% to {$lastPct}%).";
            } elseif ($lastPct < $firstPct) {
                $insights[] = "Stunting prevalence improved over the last 3 months ({$firstPct}% to {$lastPct}%).";
            }
        }

        if (count($insights) < 3 && $upcomingAppt === 0) {
            $insights[] = 'No upcoming appointments scheduled city-wide.';
        }

        if (empty($insights)) {
            $insights[] = 'No actionable family patterns in the current city data.';
        }

        return ['source' => 'rule_based', 'insights' => array_slice($insights, 0, 3)];
    }
}

$aiResult = ai_insights_generate([
    'summary_text' => $summaryText,
    'system_message' => $systemMessage,
    'feature_tag' => 'admin_family',
    'fallback' => function () use ($trendRows, $topFamilyBarangays, $topSevereBarangay, $stuntingTrendRows, $totalParents, $totalChildren, $measuredCount, $upcomingAppt) {
        return admin_family_rule_insights($trendRows, $topFamilyBarangays, $topSevereBarangay, $stuntingTrendRows, $totalParents, $totalChildren, $measuredCount, $upcomingAppt);
    },
]);

$aiInsights = array_values(array_filter(array_map(static function ($text): string {
    return trim((string)$text);
}, (array)($aiResult['insights'] ?? [])), static function (string $text): bool {
    return $text !== '';
}));

// Backstop: an AI success with an empty list must not reach the panel or
// the cache — fall back to deterministic rules so the card always shows
// real bullets (or the honest "no actionable patterns" line).
if ($aiInsights === []) {
    $ruleBackstop = admin_family_rule_insights($trendRows, $topFamilyBarangays, $topSevereBarangay, $stuntingTrendRows, $totalParents, $totalChildren, $measuredCount, $upcomingAppt);
    $aiResult['source'] = $ruleBackstop['source'];
    $aiInsights = $ruleBackstop['insights'];
}

$aiResult['insights'] = array_slice(array_values($aiInsights), 0, 3);
$aiResult['insights'] = array_map(static function ($text): string {
    $text = trim((string)$text);
    return strlen($text) > 140 ? substr($text, 0, 137) . '...' : $text;
}, $aiResult['insights']);

$expiresAt = (new DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');
$payloadJson = json_encode(['insights' => $aiResult['insights']], JSON_UNESCAPED_UNICODE);
$source = $aiResult['source'] === 'ai' ? 'ai' : 'rule_based';

$stmt = $conn->prepare(
    'INSERT INTO nutritionist_ai_insight_cache
        (scope_key, cache_key, payload_json, source, generated_at, expires_at)
     VALUES (?, ?, ?, ?, NOW(), ?)
     ON DUPLICATE KEY UPDATE
        payload_json = VALUES(payload_json),
        source = VALUES(source),
        generated_at = NOW(),
        expires_at = VALUES(expires_at)'
);
if ($stmt !== false) {
    $stmt->bind_param('sssss', $scopeKey, $cacheKey, $payloadJson, $source, $expiresAt);
    $stmt->execute();
    $stmt->close();
}

echo json_encode([
    'success' => true,
    'cached' => false,
    'source' => $aiResult['source'],
    'insights' => $aiResult['insights'],
    'generated_at' => date('Y-m-d H:i:s'),
    'expires_at' => $expiresAt,
], JSON_UNESCAPED_UNICODE);
