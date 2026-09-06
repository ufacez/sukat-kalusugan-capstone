<?php
/**
 * api/chatbot/conversations.php
 *
 * CRUD for AI assistant conversations.
 * GET  — list conversations for the current user
 * POST — create a new conversation
 * DELETE — delete a conversation
 * POST action=archive_all — archive the current user's conversations
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/auth_middleware.php';

start_secure_session();
$user = current_user();

if ($user === null) {
    api_error('Please sign in to continue.', 401);
}

$userId = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$db = get_db_connection();


/* -----------------------------------------------------------------------
 * GET — List conversations
 * ----------------------------------------------------------------------- */
if ($method === 'GET') {

    $conversationId = api_int($_GET['id'] ?? null);
    $childId = api_int($_GET['child_id'] ?? null);

    if ($conversationId > 0) {
        $sql = 'SELECT id, child_id, title
                FROM chat_conversations
                WHERE id = ? AND user_id = ? AND archived_at IS NULL
                LIMIT 1';
        $stmt = mysqli_prepare($db, $sql);
        mysqli_stmt_bind_param($stmt, 'ii', $conversationId, $userId);
        mysqli_stmt_execute($stmt);
        $conversation = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($conversation === null) {
            api_error('Conversation not found.', 404);
        }

        $sql = 'SELECT role, content, created_at
                FROM chat_messages
                WHERE conversation_id = ?
                ORDER BY id ASC';
        $stmt = mysqli_prepare($db, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $conversationId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $messages = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $messages[] = [
                'role' => (string)$row['role'],
                'content' => (string)$row['content'],
                'created_at' => (string)$row['created_at'],
            ];
        }
        mysqli_stmt_close($stmt);

        api_success([
            'conversation' => [
                'id' => (int)$conversation['id'],
                'child_id' => $conversation['child_id'] !== null ? (int)$conversation['child_id'] : null,
                'title' => (string)($conversation['title'] ?? 'New Conversation'),
            ],
            'messages' => $messages,
        ]);
    }

    $sql = 'SELECT c.id, c.title, c.child_id, c.created_at, c.updated_at,
                   ch.first_name, ch.last_name, ch.child_code
            FROM chat_conversations c
            LEFT JOIN children ch ON ch.id = c.child_id
            WHERE c.user_id = ? AND c.archived_at IS NULL
            ORDER BY c.updated_at DESC';

    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $conversations = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $conversations[] = [
            'id'         => (int)$row['id'],
            'title'      => $row['title'] ?? 'New Conversation',
            'child_id'   => $row['child_id'] !== null ? (int)$row['child_id'] : null,
            'child_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'child_code' => $row['child_code'] ?? null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    mysqli_stmt_close($stmt);

    api_success([
        'conversations' => $conversations,
        'count'         => count($conversations),
    ]);
}


/* -----------------------------------------------------------------------
 * POST — Create a new conversation
 * ----------------------------------------------------------------------- */
if ($method === 'POST') {

    $payload = api_payload();
    $action = trim((string)($payload['action'] ?? ''));

    if ($action === 'archive_all') {
        $sql = 'UPDATE chat_conversations
                SET archived_at = NOW()
                WHERE user_id = ? AND archived_at IS NULL';
        $stmt = mysqli_prepare($db, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $archivedCount = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        api_success(['archived' => $archivedCount], 'Conversations archived.');
    }

    $childId = api_int($payload['child_id'] ?? null);
    $title    = trim((string)($payload['title'] ?? ''));

    if ($title === '') {
        $title = 'New Conversation';
    }

    $hasChild = $childId > 0;
    $sql = $hasChild
        ? 'INSERT INTO chat_conversations (user_id, child_id, title, created_at, updated_at)
           VALUES (?, ?, ?, NOW(), NOW())'
        : 'INSERT INTO chat_conversations (user_id, child_id, title, created_at, updated_at)
           VALUES (?, NULL, ?, NOW(), NOW())';

    $stmt = mysqli_prepare($db, $sql);
    if ($hasChild) {
        mysqli_stmt_bind_param($stmt, 'iis', $userId, $childId, $title);
    } else {
        mysqli_stmt_bind_param($stmt, 'is', $userId, $title);
    }
    mysqli_stmt_execute($stmt);

    $convId = mysqli_insert_id($db);
    mysqli_stmt_close($stmt);

    api_success([
        'id'    => (int)$convId,
        'title' => $title,
    ]);
}


/* -----------------------------------------------------------------------
 * DELETE — Delete a conversation
 * ----------------------------------------------------------------------- */
if ($method === 'DELETE') {

    $convId = api_int($_GET['id'] ?? null);

    if ($convId === null || $convId <= 0) {
        api_error('Conversation ID is required.');
    }

    // Verify ownership
    $sql = 'SELECT id FROM chat_conversations WHERE id = ? AND user_id = ? AND archived_at IS NULL';
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $convId, $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if ($row === null) {
        api_error('Conversation not found.', 404);
    }

    // Delete conversation (messages cascade)
    $sql = 'DELETE FROM chat_conversations WHERE id = ? AND user_id = ?';
    $stmt = mysqli_prepare($db, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $convId, $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    api_success(['deleted' => true]);
}


api_error('Method not allowed.', 405);
