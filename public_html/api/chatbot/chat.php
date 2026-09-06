<?php
/**
 * api/chatbot/chat.php
 *
 * Send a message in a conversation and get the AI response.
 * Handles both child-specific and general queries.
 * Persists all messages to the database.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';
require_once __DIR__ . '/../../includes/who_calculator.php';
require_once __DIR__ . '/../../includes/chatbot_helper.php';

api_require_method(['POST']);

start_secure_session();
$user = current_user();

if ($user === null) {
    api_error('Please sign in to continue.', 401);
}

$userId = (int)$user['id'];
$db     = get_db_connection();
$payload = api_payload();

$conversationId = api_int($payload['conversation_id'] ?? null);
$message        = trim((string)($payload['message'] ?? ''));
$childId        = api_int($payload['child_id'] ?? null);

if ($message === '') {
    api_error('Message cannot be empty.');
}

if ($conversationId === null || $conversationId <= 0) {
    api_error('Conversation ID is required.');
}


/* -----------------------------------------------------------------------
 * Verify conversation ownership
 * ----------------------------------------------------------------------- */
$sql = 'SELECT id, child_id, title FROM chat_conversations WHERE id = ? AND user_id = ?';
$stmt = mysqli_prepare($db, $sql);
mysqli_stmt_bind_param($stmt, 'ii', $conversationId, $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$conv = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if ($conv === null) {
    api_error('Conversation not found.', 404);
}

// Use conversation's child_id if not overridden
if ($childId === null) {
    $childId = $conv['child_id'] !== null ? (int)$conv['child_id'] : null;
}


/* -----------------------------------------------------------------------
 * Save user message
 * ----------------------------------------------------------------------- */
$sql = 'INSERT INTO chat_messages (conversation_id, role, content, created_at)
        VALUES (?, ?, ?, NOW())';
$stmt = mysqli_prepare($db, $sql);
$role = 'user';
mysqli_stmt_bind_param($stmt, 'iss', $conversationId, $role, $message);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);


/* -----------------------------------------------------------------------
 * Build context for the AI
 * ----------------------------------------------------------------------- */
$child       = null;
$measurement = null;
$history     = [];

if ($childId <= 0) {
    $scopeCondition = '';
    $scopeParams = [];
    $scopeTypes = '';
    if (($user['role'] ?? '') === 'nutritionist' && ($user['barangay_id'] ?? '') !== '') {
        $scopeCondition = ' WHERE c.barangay_id = ?';
        $scopeTypes = 'i';
        $scopeParams[] = (int)$user['barangay_id'];
    }

    $summary = admin_fetch_one(
        'SELECT COUNT(DISTINCT c.id) AS children_count,
                COUNT(m.id) AS measurements_count,
                MAX(m.measurement_date) AS latest_measurement
         FROM children c
         LEFT JOIN measurements m ON m.child_id = c.id' . $scopeCondition,
        $scopeTypes,
        $scopeParams
    ) ?? [];

    $trend = admin_fetch_all(
        'SELECT DATE_FORMAT(m.measurement_date, "%Y-%m") AS month,
                COUNT(*) AS measurements,
                SUM(CASE WHEN m.wfa_status IN ("MUW", "SUW") THEN 1 ELSE 0 END) AS underweight,
                SUM(CASE WHEN m.hfa_status IN ("MSt", "SSt") THEN 1 ELSE 0 END) AS stunted,
                SUM(CASE WHEN m.wfh_status IN ("MW", "SW") THEN 1 ELSE 0 END) AS wasted,
                SUM(CASE WHEN m.wfh_status IN ("OW", "Ob") THEN 1 ELSE 0 END) AS overnutrition
         FROM measurements m
         INNER JOIN children c ON c.id = m.child_id' . $scopeCondition . '
         GROUP BY DATE_FORMAT(m.measurement_date, "%Y-%m")
         ORDER BY month DESC
         LIMIT 12',
        $scopeTypes,
        $scopeParams
    );

    $contextBlock = chatbot_build_nutritionist_overview_context($summary, $trend);
} 

if ($childId !== null && $childId > 0) {

    // Load child
    $sql = 'SELECT id, first_name, last_name, sex, birthdate, barangay_id
            FROM children WHERE id = ?';
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $childId);
    mysqli_stmt_execute($stmt);
    $child = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($child !== null) {

        // Load latest measurement
        $sql = 'SELECT * FROM measurements
                WHERE child_id = ?
                ORDER BY measurement_date DESC, id DESC
                LIMIT 1';
        $stmt = mysqli_prepare($db, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $childId);
        mysqli_stmt_execute($stmt);
        $measurement = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        // Load previous measurements
        $sql = 'SELECT measurement_date, height_cm, weight_kg,
                       nutritional_status, wfa_status, hfa_status, wfh_status
                FROM measurements
                WHERE child_id = ?
                ORDER BY measurement_date DESC, id DESC
                LIMIT 1 OFFSET 1';
        $stmt = mysqli_prepare($db, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $childId);
        mysqli_stmt_execute($stmt);
        $histResult = mysqli_stmt_get_result($stmt);
        while ($hRow = mysqli_fetch_assoc($histResult)) {
            $history[] = $hRow;
        }
        mysqli_stmt_close($stmt);
    }
}


/* -----------------------------------------------------------------------
 * Load conversation history for LLM context
 * ----------------------------------------------------------------------- */
$sql = 'SELECT role, content FROM chat_messages
        WHERE conversation_id = ?
        ORDER BY id ASC
        LIMIT 20';
$stmt = mysqli_prepare($db, $sql);
mysqli_stmt_bind_param($stmt, 'i', $conversationId);
mysqli_stmt_execute($stmt);
$histResult = mysqli_stmt_get_result($stmt);

$chatHistory = [];
while ($hRow = mysqli_fetch_assoc($histResult)) {
    $chatHistory[] = [
        'role'    => $hRow['role'],
        'content' => $hRow['content'],
    ];
}
mysqli_stmt_close($stmt);

// Convert to format expected by the LLM (exclude the last user message
// we just saved, as it's passed separately)
$llmHistory = [];
foreach ($chatHistory as $idx => $hMsg) {
    if ($idx < count($chatHistory) - 1) {
        $llmHistory[] = $hMsg;
    }
}


/* -----------------------------------------------------------------------
 * Call the AI
 * ----------------------------------------------------------------------- */
if ($childId > 0) {
    $contextBlock = chatbot_build_enhanced_context($child, $measurement, $history);
}
$llmResult    = chatbot_call_llm_enhanced($contextBlock, $message, $llmHistory);

if (!$llmResult['ok']) {
    // Save error as system message
    $errorMsg = 'Sorry, I encountered an error: ' . ($llmResult['error'] ?? 'Unknown error');
    $sql = 'INSERT INTO chat_messages (conversation_id, role, content, created_at)
            VALUES (?, ?, ?, NOW())';
    $stmt = mysqli_prepare($db, $sql);
    $sysRole = 'system';
    mysqli_stmt_bind_param($stmt, 'iss', $conversationId, $sysRole, $errorMsg);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    api_error($llmResult['error'] ?? 'AI assistant encountered an error.');
}


$reply = $llmResult['reply'];


/* -----------------------------------------------------------------------
 * Save assistant reply
 * ----------------------------------------------------------------------- */
$sql = 'INSERT INTO chat_messages (conversation_id, role, content, created_at)
        VALUES (?, ?, ?, NOW())';
$stmt = mysqli_prepare($db, $sql);
$asstRole = 'assistant';
mysqli_stmt_bind_param($stmt, 'iss', $conversationId, $asstRole, $reply);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);


/* -----------------------------------------------------------------------
 * Update conversation title if first message
 * ----------------------------------------------------------------------- */
if (count($chatHistory) <= 1) {
    // Auto-generate title from first user message
    $autoTitle = mb_substr($message, 0, 60);
    if (mb_strlen($message) > 60) {
        $autoTitle .= '...';
    }

    $sql = 'UPDATE chat_conversations SET title = ?, updated_at = NOW() WHERE id = ?';
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, 'si', $autoTitle, $conversationId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
} else {
    // Just update the timestamp
    $sql = 'UPDATE chat_conversations SET updated_at = NOW() WHERE id = ?';
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $conversationId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}


api_success([
    'reply' => $reply,
]);
