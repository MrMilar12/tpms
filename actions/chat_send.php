<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();
sendSecurityHeaders();
requireLogin();
requireRoleSelection();
verifyCsrf();

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

$db = getDB();
ensureChatSystemSchema($db);

$senderId = (int)(currentUser()['id'] ?? 0);
$mode = strtolower(trim((string)($_POST['mode'] ?? 'dm')));
$rawRecipientId = trim((string)($_POST['recipient_id'] ?? '0'));
$recipientId = ctype_digit($rawRecipientId) ? (int)$rawRecipientId : 0;
$rawGroupId = trim((string)($_POST['group_id'] ?? '0'));
$groupId = ctype_digit($rawGroupId) ? (int)$rawGroupId : 0;
$message = trim((string)($_POST['message'] ?? ''));

if ($senderId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized session.']);
    exit;
}

if ($message === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Message cannot be empty.']);
    exit;
}

if (mb_strlen($message) > 1000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Message is too long (max 1000 characters).']);
    exit;
}

$allowedModes = ['dm', 'group'];
if (!in_array($mode, $allowedModes, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Invalid chat mode.']);
    exit;
}

if ($mode === 'dm') {
    if ($recipientId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Please select a direct message recipient.']);
        exit;
    }
    if ($recipientId === $senderId) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'You cannot send a direct message to yourself.']);
        exit;
    }

    $checkUser = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $checkUser->execute([$recipientId]);
    if (!$checkUser->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Recipient was not found or is inactive.']);
        exit;
    }

    $ins = $db->prepare('INSERT INTO chat_messages (sender_id, recipient_id, group_id, message_text) VALUES (?, ?, NULL, ?)');
    $ins->execute([$senderId, $recipientId, $message]);

    logActivity(
        'CREATE',
        'chat',
        null,
        'Sent direct chat message to user #' . $recipientId
    );
} else {
    if ($groupId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Please select a group chat.']);
        exit;
    }

    $memberCheck = $db->prepare(
        'SELECT gm.id
         FROM chat_group_members gm
         INNER JOIN chat_groups g ON g.id = gm.group_id
         WHERE gm.group_id = ?
           AND gm.user_id = ?
           AND g.is_archived = 0
         LIMIT 1'
    );
    $memberCheck->execute([$groupId, $senderId]);
    if (!$memberCheck->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'You are not a member of this group chat.']);
        exit;
    }

    $ins = $db->prepare('INSERT INTO chat_messages (sender_id, recipient_id, group_id, message_text) VALUES (?, NULL, ?, ?)');
    $ins->execute([$senderId, $groupId, $message]);

    logActivity(
        'CREATE',
        'chat',
        null,
        'Sent group chat message to group #' . $groupId
    );
}

echo json_encode([
    'ok' => true,
    'message_id' => (int)$db->lastInsertId(),
]);
