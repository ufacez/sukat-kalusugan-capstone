<?php
/**
 * api/chatbot/interpret.php
 *
 * Shared by the nutritionist and parent portals. Given a child_id and a
 * question, loads that child's latest (and a bit of prior) measurement
 * data from the database, and asks the LLM to explain it in plain
 * language. The model never sees anything beyond what this file hands
 * it — see includes/chatbot_helper.php for the scope restrictions.
 */

require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../includes/who_calculator.php';
require_once __DIR__ . '/../../includes/chatbot_helper.php';

api_require_method(['POST']);

$user = current_user();

if ($user === null) {
    api_error('Please sign in to continue.', 401);
}

$userType = (string)($user['type'] ?? '');

if ($userType === 'parent') {
    $user = api_require_parent_session();
} elseif ($userType === 'staff') {
    $user = api_require_staff_session(['admin', 'nutritionist']);
} else {
    api_error('You do not have permission to use the assistant.', 403);
}

$payload = api_payload();
$childId = api_int($payload['child_id'] ?? null);
$message = api_string($payload['message'] ?? '', '');

// Optional short rolling history the widget sends back so the model has
// conversational context. Capped hard so a request can't grow unbounded.
$rawHistory = is_array($payload['history'] ?? null) ? $payload['history'] : [];
$conversationHistory = [];

foreach (array_slice($rawHistory, -6) as $turn) {
    if (!is_array($turn)) {
        continue;
    }

    $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $content = api_string($turn['content'] ?? '', '');

    if ($content === '') {
        continue;
    }

    $conversationHistory[] = ['role' => $role, 'content' => mb_substr($content, 0, 1000)];
}

if ($childId <= 0) {
    api_error('Please choose a child first.');
}

if ($message === '') {
    api_error('Please type a question.');
}

if (mb_strlen($message) > 1000) {
    api_error('That message is too long. Please shorten your question.');
}

// Load the child and confirm the caller is allowed to see them.
if ($userType === 'parent') {
    $child = admin_fetch_one(
        'SELECT id, first_name, last_name, sex, birthdate, parent_id
         FROM children
         WHERE id = ? AND parent_id = ?
         LIMIT 1',
        'ii',
        [$childId, (int)$user['id']]
    );
} else {
    $child = admin_fetch_one(
        'SELECT id, first_name, last_name, sex, birthdate, parent_id
         FROM children
         WHERE id = ?
         LIMIT 1',
        'i',
        [$childId]
    );
}

if ($child === null) {
    api_error('That child could not be found.', 404);
}

$measurements = admin_fetch_all(
    'SELECT measurement_date, height_cm, weight_kg, waz, haz, whz,
            nutritional_status, wfa_status, hfa_status, wfh_status,
            is_flagged, flag_reason
     FROM measurements
     WHERE child_id = ?
     ORDER BY measurement_date DESC, id DESC
     LIMIT 6',
    'i',
    [$childId]
);

$latestMeasurement = $measurements[0] ?? null;
$history = array_slice($measurements, 1);

$contextBlock = chatbot_build_measurement_context($child, $latestMeasurement, $history);
$result = chatbot_call_llm($contextBlock, $message, $conversationHistory);

if (!$result['ok']) {
    api_error((string)$result['error'], 502);
}

api_success([
    'reply' => $result['reply'],
    'child' => [
        'id' => (int)$child['id'],
        'name' => trim((string)$child['first_name'] . ' ' . (string)$child['last_name']),
    ],
], 'OK');