<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();
sendSecurityHeaders();
requireLogin();
requireRoleSelection();

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

$db = getDB();
ensureChatSystemSchema($db);

$me = (int)(currentUser()['id'] ?? 0);
$mode = strtolower(trim((string)($_GET['mode'] ?? 'dm')));
$rawRecipientId = trim((string)($_GET['recipient_id'] ?? '0'));
$recipientId = ctype_digit($rawRecipientId) ? (int)$rawRecipientId : 0;
$rawGroupId = trim((string)($_GET['group_id'] ?? '0'));
$groupId = ctype_digit($rawGroupId) ? (int)$rawGroupId : 0;
$rawSinceId = trim((string)($_GET['since_id'] ?? '0'));
$sinceId = ctype_digit($rawSinceId) ? (int)$rawSinceId : 0;

if ($me <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized session.']);
    exit;
}

$allowedModes = ['dm', 'group'];
if (!in_array($mode, $allowedModes, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Invalid chat mode.']);
    exit;
}

$params = [];
if ($mode === 'dm') {
    if ($recipientId <= 0 || $recipientId === $me) {
        echo json_encode(['ok' => true, 'messages' => [], 'last_id' => $sinceId]);
        exit;
    }

    $checkUser = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $checkUser->execute([$recipientId]);
    if (!$checkUser->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Recipient was not found or is inactive.']);
        exit;
    }

    $sql = 'SELECT m.id, m.sender_id, m.recipient_id, m.group_id, m.message_text, m.created_at,
                   u.full_name AS sender_name
            FROM chat_messages m
            INNER JOIN users u ON u.id = m.sender_id
            WHERE ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))
              AND m.group_id IS NULL
              AND m.id > ?
            ORDER BY m.id ASC
            LIMIT 200';
    $params = [$me, $recipientId, $recipientId, $me, $sinceId];

    $markRead = $db->prepare('UPDATE chat_messages SET is_read = 1 WHERE sender_id = ? AND recipient_id = ? AND group_id IS NULL AND is_read = 0');
    $markRead->execute([$recipientId, $me]);
} else {
    if ($groupId <= 0) {
        echo json_encode(['ok' => true, 'messages' => [], 'last_id' => $sinceId]);
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
    $memberCheck->execute([$groupId, $me]);
    if (!$memberCheck->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'You are not a member of this group chat.']);
        exit;
    }

    $sql = 'SELECT m.id, m.sender_id, m.recipient_id, m.group_id, m.message_text, m.created_at,
                   u.full_name AS sender_name
            FROM chat_messages m
            INNER JOIN users u ON u.id = m.sender_id
            WHERE m.group_id = ?
              AND m.id > ?
            ORDER BY m.id ASC
            LIMIT 200';
    $params = [$groupId, $sinceId];
}

$st = $db->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$messages = [];
$lastId = $sinceId;
foreach ($rows as $row) {
    $id = (int)($row['id'] ?? 0);
    if ($id > $lastId) {
        $lastId = $id;
    }

    $createdAt = (string)($row['created_at'] ?? '');
    $createdLabel = $createdAt !== '' ? date('M j, Y g:i A', strtotime($createdAt)) : '';

    $messages[] = [
        'id' => $id,
        'sender_id' => (int)($row['sender_id'] ?? 0),
        'sender_name' => (string)($row['sender_name'] ?? 'Unknown User'),
        'recipient_id' => isset($row['recipient_id']) ? (int)$row['recipient_id'] : null,
        'group_id' => isset($row['group_id']) ? (int)$row['group_id'] : null,
        'message_text' => (string)($row['message_text'] ?? ''),
        'created_at' => $createdAt,
        'created_label' => $createdLabel,
        'is_mine' => (int)($row['sender_id'] ?? 0) === $me,
    ];
}

echo json_encode([
    'ok' => true,
    'messages' => $messages,
    'last_id' => $lastId,
]);
