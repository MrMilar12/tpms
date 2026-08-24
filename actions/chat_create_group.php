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

$me = (int)(currentUser()['id'] ?? 0);
$groupName = trim((string)($_POST['group_name'] ?? ''));
$rawMembers = $_POST['member_ids'] ?? [];

if ($me <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized session.']);
    exit;
}

if ($groupName === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Group name is required.']);
    exit;
}

if (mb_strlen($groupName) > 120) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Group name must be 120 characters or fewer.']);
    exit;
}

$memberIds = [];
if (is_array($rawMembers)) {
    foreach ($rawMembers as $raw) {
        $value = trim((string)$raw);
        if ($value !== '' && ctype_digit($value)) {
            $uid = (int)$value;
            if ($uid > 0 && $uid !== $me) {
                $memberIds[$uid] = true;
            }
        }
    }
}

if (!$memberIds) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Select at least one member for the group.']);
    exit;
}

$memberIdList = array_keys($memberIds);
$placeholders = implode(',', array_fill(0, count($memberIdList), '?'));
$checkSql = 'SELECT id FROM users WHERE is_active = 1 AND id IN (' . $placeholders . ')';
$checkStmt = $db->prepare($checkSql);
$checkStmt->execute($memberIdList);
$validMemberIds = array_map('intval', $checkStmt->fetchAll(PDO::FETCH_COLUMN));
$validMemberMap = [];
foreach ($validMemberIds as $uid) {
    $validMemberMap[$uid] = true;
}

if (count($validMemberMap) !== count($memberIdList)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'One or more selected users are invalid or inactive.']);
    exit;
}

try {
    $db->beginTransaction();

    $createGroup = $db->prepare('INSERT INTO chat_groups (group_name, created_by) VALUES (?, ?)');
    $createGroup->execute([$groupName, $me]);
    $groupId = (int)$db->lastInsertId();

    $insertMember = $db->prepare('INSERT INTO chat_group_members (group_id, user_id, member_role) VALUES (?, ?, ?)');
    $insertMember->execute([$groupId, $me, 'owner']);
    foreach (array_keys($validMemberMap) as $uid) {
        $insertMember->execute([$groupId, (int)$uid, 'member']);
    }

    $db->commit();

    logActivity('CREATE', 'chat', null, 'Created chat group #' . $groupId . ' (' . $groupName . ')');

    echo json_encode([
        'ok' => true,
        'group' => [
            'id' => $groupId,
            'name' => $groupName,
        ],
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('TPMS chat_create_group error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to create group chat right now.']);
}
