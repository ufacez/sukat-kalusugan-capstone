<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';

header('Content-Type: application/json; charset=utf-8');

start_secure_session();

$user = current_user();
if ($user === null || ($user['type'] ?? '') === 'parent') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$conn = get_db_connection();

$chartRows = admin_fetch_all(
    "SELECT DATE(created_at) AS day, COUNT(*) AS cnt
     FROM audit_logs
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY DATE(created_at)
     ORDER BY day ASC"
);

$chartData = [];
foreach ($chartRows as $row) {
    $chartData[] = ['date' => $row['day'], 'count' => (int)$row['cnt']];
}

$categoryRows = admin_fetch_all(
    "SELECT DATE(a.created_at) AS day,
            SUM(CASE WHEN a.action IN ('LOGIN','LOGOUT') THEN 1 ELSE 0 END) AS auth_count,
            SUM(CASE WHEN a.action IN ('CREATE_USER','CREATE_PARENT','CREATE_LOCAL_AREA','measurement.create') THEN 1 ELSE 0 END) AS create_count,
            SUM(CASE WHEN a.action IN ('EOPT_EXPORT','EOPT_LIST_EXPORT','FOLLOWUP_SYNC','PASSWORD_RESET_REQUEST','PASSWORD_RESET_COMPLETE') THEN 1 ELSE 0 END) AS read_count,
            SUM(CASE WHEN a.action IN ('UPDATE_CHILD','UPDATE_BARANGAY','UPDATE_DEVICE') THEN 1 ELSE 0 END) AS update_count,
            SUM(CASE WHEN a.action IN ('DELETE_USER','DELETE_PARENT','DELETE_CHILD') THEN 1 ELSE 0 END) AS delete_count
     FROM audit_logs a
     WHERE a.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY DATE(a.created_at)
     ORDER BY day ASC"
);

$categoryData = [];
foreach ($categoryRows as $row) {
    $categoryData[] = [
        'date' => $row['day'],
        'auth' => (int)$row['auth_count'],
        'create' => (int)$row['create_count'],
        'read' => (int)$row['read_count'],
        'update' => (int)$row['update_count'],
        'delete' => (int)$row['delete_count'],
    ];
}

$totalLogs = admin_scalar("SELECT COUNT(*) FROM audit_logs");
$last7 = admin_scalar("SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$last30 = admin_scalar("SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");

$actionBreakdown = admin_fetch_all(
    "SELECT action, COUNT(*) AS cnt FROM audit_logs GROUP BY action ORDER BY cnt DESC LIMIT 10"
);

$userTypeBreakdown = admin_fetch_all(
    "SELECT COALESCE(user_type, 'system') AS user_type, COUNT(*) AS cnt FROM audit_logs GROUP BY user_type ORDER BY cnt DESC"
);

$levelBreakdown = admin_fetch_all(
    "SELECT level, COUNT(*) AS cnt FROM audit_logs GROUP BY level ORDER BY cnt DESC"
);

$recentActivity = admin_fetch_all(
    "SELECT a.action, a.level, a.description, a.created_at, COALESCE(u.email, 'System') AS actor, COALESCE(a.user_type, 'system') AS user_type
     FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC LIMIT 15"
);

$summaryLines = [];
$summaryLines[] = "Audit Log Summary (last 30 days: {$last30} events, all time: {$totalLogs})";
$summaryLines[] = "";
$summaryLines[] = "Action breakdown:";
foreach ($actionBreakdown as $ab) {
    $summaryLines[] = "  - {$ab['action']}: {$ab['cnt']}";
}
$summaryLines[] = "";
$summaryLines[] = "User type breakdown:";
foreach ($userTypeBreakdown as $ub) {
    $summaryLines[] = "  - {$ub['user_type']}: {$ub['cnt']}";
}
$summaryLines[] = "";
$summaryLines[] = "Severity breakdown:";
foreach ($levelBreakdown as $lb) {
    $summaryLines[] = "  - {$lb['level']}: {$lb['cnt']}";
}
$summaryLines[] = "";
$summaryLines[] = "Recent 15 events:";
foreach ($recentActivity as $ra) {
    $ts = $ra['created_at'];
    $summaryLines[] = "  [{$ts}] {$ra['user_type']}/{$ra['actor']} {$ra['action']} ({$ra['level']}): {$ra['description']}";
}

$summaryText = implode("\n", $summaryLines);

$aiInsights = generate_ai_insights($summaryText, $totalLogs, $last7, $last30, $actionBreakdown, $userTypeBreakdown);

echo json_encode([
    'success' => true,
    'chart' => $chartData,
    'category_chart' => $categoryData,
    'stats' => ['total' => $totalLogs, 'last_7_days' => $last7, 'last_30_days' => $last30],
    'action_breakdown' => $actionBreakdown,
    'user_type_breakdown' => $userTypeBreakdown,
    'level_breakdown' => $levelBreakdown,
    'recent_activity' => $recentActivity,
    'ai_insights' => $aiInsights,
], JSON_UNESCAPED_UNICODE);


function generate_ai_insights(
    string $summaryText,
    int $total,
    int $last7,
    int $last30,
    array $actions,
    array $userTypes
): array {
    $apiKey = defined('CHATBOT_API_KEY') ? trim((string)CHATBOT_API_KEY) : '';
    $provider = defined('CHATBOT_PROVIDER') ? strtolower(trim((string)CHATBOT_PROVIDER)) : '';
    $model = defined('CHATBOT_MODEL') ? trim((string)CHATBOT_MODEL) : '';

    if ($apiKey !== '' && in_array($provider, ['gemini', 'openai'], true)) {
        try {
            return call_ai_provider($provider, $apiKey, $model, $summaryText);
        } catch (\Throwable $e) {
            error_log('[AuditLogs] AI provider error: ' . $e->getMessage());
        }
    }

    return generate_rule_based_insights($total, $last7, $last30, $actions, $userTypes);
}


function call_ai_provider(string $provider, string $apiKey, string $model, string $prompt): array
{
    $systemMessage = 'You are a data analyst for a child nutrition monitoring system called Sukat Kalusugan. '
        . 'Analyze the following audit log summary and return exactly 4-6 short insight bullets in JSON format: '
        . '{"insights": ["insight 1", "insight 2", ...]}. '
        . 'Each insight should be 1-2 sentences max. Focus on: activity trends, security observations, '
        . 'user behavior patterns, and any anomalies. Be concise and actionable. '
        . 'Do NOT use markdown. Return ONLY valid JSON.';

    if ($provider === 'gemini') {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . ($model ?: 'gemini-2.0-flash') . ':generateContent?key=' . $apiKey;
        $body = json_encode([
            'contents' => [['parts' => [['text' => $systemMessage . "\n\n" . $prompt]]]],
            'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 500],
        ]);
    } else {
        $url = 'https://api.openai.com/v1/chat/completions';
        $body = json_encode([
            'model' => $model ?: 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $systemMessage],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => 500,
        ]);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        throw new \RuntimeException("AI request failed: HTTP {$httpCode}");
    }

    $data = json_decode($response, true);
    if ($data === null) {
        throw new \RuntimeException('AI response decode failed');
    }

    $text = '';
    if ($provider === 'gemini') {
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    } else {
        $text = $data['choices'][0]['message']['content'] ?? '';
    }

    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/i', '', $text);

    $parsed = json_decode($text, true);
    if (is_array($parsed) && isset($parsed['insights']) && is_array($parsed['insights'])) {
        return ['source' => 'ai', 'insights' => array_slice($parsed['insights'], 0, 6)];
    }

    throw new \RuntimeException('AI response format invalid');
}


function generate_rule_based_insights(
    int $total,
    int $last7,
    int $last30,
    array $actions,
    array $userTypes
): array {
    $insights = [];

    if ($last30 > 0) {
        $dailyAvg = round($last7 / 7, 1);
        if ($last7 > $last30 * 0.4) {
            $insights[] = "Activity is elevated this week with {$last7} events (daily avg: {$dailyAvg}).";
        } else {
            $insights[] = "Steady activity: {$last7} events in the last 7 days (daily avg: {$dailyAvg}).";
        }
    }

    if (!empty($actions)) {
        $top = $actions[0];
        $pct = $total > 0 ? round(($top['cnt'] / $total) * 100) : 0;
        $insights[] = "Most common action: {$top['action']} ({$top['cnt']} times, {$pct}% of all logs).";
    }

    if (!empty($userTypes) && count($userTypes) > 1) {
        $topType = $userTypes[0];
        $insights[] = ucfirst($topType['user_type']) . " users account for {$topType['cnt']} events, "
            . "the largest share of system activity.";
    } else {
        $insights[] = "All logged activity is from a single user type. Consider adding parent/nutritionist logging for fuller visibility.";
    }

    $dangerCount = admin_scalar("SELECT COUNT(*) FROM audit_logs WHERE level = 'danger'");
    if ($dangerCount > 0) {
        $insights[] = "{$dangerCount} critical event(s) detected. Review these for potential security concerns.";
    } else {
        $insights[] = "No critical-level events recorded. System activity appears normal.";
    }

    $inactiveCount = admin_scalar("SELECT COUNT(*) FROM users WHERE status = 'inactive'");
    if ($inactiveCount > 0) {
        $insights[] = "{$inactiveCount} archived user account(s). Consider hard-deleting unused accounts for security hygiene.";
    }

    return ['source' => 'rule_based', 'insights' => $insights];
}
