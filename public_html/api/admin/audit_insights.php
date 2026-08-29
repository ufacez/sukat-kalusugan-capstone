<?php

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/ai_insights_helper.php';

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

$aiInsights = ai_insights_generate([
    'summary_text' => $summaryText,
    'system_message' => 'You are a data analyst for a child nutrition monitoring system called Sukat Kalusugan. '
        . 'Analyze the following audit log summary and return exactly 4-6 short insight bullets in JSON format: '
        . '{"insights": ["insight 1", "insight 2", ...]}. '
        . 'Each insight should be 1-2 sentences max. Focus on: activity trends, security observations, '
        . 'user behavior patterns, and any anomalies. Be concise and actionable. '
        . 'Do NOT use markdown. Return ONLY valid JSON.',
    'fallback' => function () use ($totalLogs, $last7, $last30, $actionBreakdown, $userTypeBreakdown) {
        return generate_audit_rule_based_insights($totalLogs, $last7, $last30, $actionBreakdown, $userTypeBreakdown);
    },
]);

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


function generate_audit_rule_based_insights(
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
