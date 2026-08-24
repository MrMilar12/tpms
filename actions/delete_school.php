<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
startSecureSession();
requireLogin();
verifyCsrf();

// Only admins can delete schools (destructive operation)
if (!isAdmin()) {
    flash('error', 'Permission denied. Only administrators can delete schools.');
    redirect(APP_URL . '/schools.php');
}

if (!canEdit()) {
    flash('error', 'Permission denied.');
    redirect(APP_URL . '/schools.php');
}

$id = (int)($_POST['id'] ?? 0);
$schoolIdsRaw = $_POST['school_ids'] ?? [];
$returnQuery = trim((string)($_POST['return_query'] ?? ''));

if ($returnQuery !== '' && !preg_match('/^[a-zA-Z0-9_\-=&%\.]*$/', $returnQuery)) {
    $returnQuery = '';
}

$schoolIds = [];
if ($id > 0) {
    $schoolIds[] = $id;
}
if (is_array($schoolIdsRaw)) {
    foreach ($schoolIdsRaw as $sid) {
        $sid = (int)$sid;
        if ($sid > 0) {
            $schoolIds[] = $sid;
        }
    }
}
$schoolIds = array_values(array_unique($schoolIds));

if (!$schoolIds) {
    flash('error', 'Invalid request.');
    redirect(APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

$confirmPassword = (string)($_POST['confirm_password'] ?? '');
if ($confirmPassword === '') {
    flash('error', 'Password confirmation is required to delete a school.');
    redirect(APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

$db = getDB();
$me = (int)(currentUser()['id'] ?? 0);
$pwStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$pwStmt->execute([$me]);
$passwordHash = (string)$pwStmt->fetchColumn();
if ($passwordHash === '' || !password_verify($confirmPassword, $passwordHash)) {
    flash('error', 'Invalid password. School was not deleted.');
    logActivity('DENY', 'schools', null, 'Blocked school delete due to invalid password.');
    redirect(APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

if (count($schoolIds) > 500) {
    flash('error', 'Too many schools selected. Delete in smaller batches.');
    logActivity('DENY', 'schools', null, 'Blocked bulk school delete: too many IDs (' . count($schoolIds) . ').');
    redirect(APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

$ph = implode(',', array_fill(0, count($schoolIds), '?'));
$checkStmt = $db->prepare('SELECT id FROM schools WHERE id IN (' . $ph . ')');
$checkStmt->execute($schoolIds);
$existingIds = array_map('intval', $checkStmt->fetchAll(PDO::FETCH_COLUMN));

if (count($existingIds) !== count($schoolIds)) {
    flash('error', 'One or more selected schools were not found.');
    logActivity('DENY', 'schools', null, 'Blocked school delete: one or more IDs not found.');
    redirect(APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

$db->beginTransaction();
try {
    $db->prepare('UPDATE teachers SET school_id = NULL WHERE school_id IN (' . $ph . ')')->execute($schoolIds);
    $db->prepare('DELETE FROM schools WHERE id IN (' . $ph . ')')->execute($schoolIds);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    flash('error', 'Could not delete selected schools right now.');
    logActivity('DENY', 'schools', null, 'Bulk school delete failed: ' . $e->getMessage());
    redirect(APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

logActivity('DELETE', 'schools', $schoolIds[0] ?? null, 'Deleted ' . count($schoolIds) . ' school(s); IDs=' . implode(',', $schoolIds));
flash('success', count($schoolIds) . ' school(s) deleted. Assigned teachers were unlinked.');
redirect(APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
