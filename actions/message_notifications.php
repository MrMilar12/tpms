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

$userId = (int)(currentUser()['id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized session.']);
    exit;
}

$db = getDB();
$unreadDirectMessages = 0;
$groupMessagesSinceLastLogin = 0;

try {
    ensureChatSystemSchema($db);

    $unreadDirectStmt = $db->prepare(
        'SELECT COUNT(*)
         FROM chat_messages
         WHERE recipient_id = ?
           AND group_id IS NULL
           AND is_read = 0'
    );
    $unreadDirectStmt->execute([$userId]);
    $unreadDirectMessages = (int)$unreadDirectStmt->fetchColumn();

    $lastLoginStmt = $db->prepare('SELECT last_login FROM users WHERE id = ? LIMIT 1');
    $lastLoginStmt->execute([$userId]);
    $lastLoginRaw = (string)$lastLoginStmt->fetchColumn();
    $groupSince = $lastLoginRaw !== ''
        ? date('Y-m-d H:i:s', strtotime($lastLoginRaw))
        : date('Y-m-d H:i:s', strtotime('-24 hours'));

    $groupMsgStmt = $db->prepare(
        'SELECT COUNT(DISTINCT m.group_id)
         FROM chat_messages m
         INNER JOIN chat_group_members gm ON gm.group_id = m.group_id
         INNER JOIN chat_groups g ON g.id = m.group_id
         WHERE gm.user_id = ?
           AND m.sender_id <> ?
           AND m.group_id IS NOT NULL
           AND m.created_at >= ?
           AND g.is_archived = 0'
    );
    $groupMsgStmt->execute([$userId, $userId, $groupSince]);
    $groupMessagesSinceLastLogin = (int)$groupMsgStmt->fetchColumn();
} catch (Throwable $e) {
    error_log('TPMS message_notifications warning: ' . $e->getMessage());
}

$totalUnreadMessages = $unreadDirectMessages + $groupMessagesSinceLastLogin;
$signature = $unreadDirectMessages . ':' . $groupMessagesSinceLastLogin . ':' . $totalUnreadMessages;

echo json_encode([
    'ok' => true,
    'unread_direct' => $unreadDirectMessages,
    'group_activity' => $groupMessagesSinceLastLogin,
    'total_unread' => $totalUnreadMessages,
    'signature' => $signature,
    'server_time' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
