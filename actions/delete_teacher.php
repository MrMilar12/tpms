<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
startSecureSession();
requireLogin();
verifyCsrf();

$id = (int)($_POST['id'] ?? 0);
if (!$id || !canEdit()) {
    flash('error', 'Invalid request.');
    redirect(APP_URL . '/teachers.php');
}

$confirmPassword = (string)($_POST['confirm_password'] ?? '');
if ($confirmPassword === '') {
    flash('error', 'Password confirmation is required to delete a teacher.');
    redirect(APP_URL . '/teachers.php');
}

$db   = getDB();
$me = (int)(currentUser()['id'] ?? 0);
$pwStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$pwStmt->execute([$me]);
$passwordHash = (string)$pwStmt->fetchColumn();
if ($passwordHash === '' || !password_verify($confirmPassword, $passwordHash)) {
    flash('error', 'Invalid password. Teacher was not deleted.');
    redirect(APP_URL . '/teachers.php');
}

$stmt = $db->prepare('SELECT profile_photo FROM teachers WHERE id = ?');
$stmt->execute([$id]);
$t = $stmt->fetch();

if (!$t) {
    flash('error', 'Teacher not found.');
    redirect(APP_URL . '/teachers.php');
}

// Remove photo file
if ($t['profile_photo']) {
    $path = UPLOAD_PATH . $t['profile_photo'];
    if (is_file($path)) unlink($path);
}

$db->prepare('DELETE FROM teachers WHERE id = ?')->execute([$id]);
logActivity('DELETE', 'teachers', $id, 'Deleted teacher ID: ' . $id);

flash('success', 'Teacher deleted successfully.');
redirect(APP_URL . '/teachers.php');
