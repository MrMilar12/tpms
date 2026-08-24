<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
startSecureSession();
requireLogin();
verifyCsrf();

if (!canEdit()) {
    flash('error', 'Access denied.');
    redirect(APP_URL . '/teachers.php');
}

$teacherId = (int)($_POST['teacher_id'] ?? 0);
$schoolId = (int)($_POST['school_id'] ?? 0);
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

if ($teacherId <= 0 || $schoolId <= 0) {
    flash('error', 'Invalid transfer request.');
    redirect(APP_URL . '/teachers.php');
}

if ($confirmPassword === '') {
    flash('error', 'Password confirmation is required to transfer teacher.');
    redirect(APP_URL . '/teachers.php');
}

$db = getDB();

$me = (int)(currentUser()['id'] ?? 0);
$pwStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$pwStmt->execute([$me]);
$passwordHash = (string)$pwStmt->fetchColumn();
if ($passwordHash === '' || !password_verify($confirmPassword, $passwordHash)) {
    flash('error', 'Invalid password. Transfer was not performed.');
    redirect(APP_URL . '/teachers.php');
}

$teacherStmt = $db->prepare('SELECT id, school_id, first_name, last_name FROM teachers WHERE id = ? LIMIT 1');
$teacherStmt->execute([$teacherId]);
$teacher = $teacherStmt->fetch();
if (!$teacher) {
    flash('error', 'Teacher not found.');
    redirect(APP_URL . '/teachers.php');
}

$schoolStmt = $db->prepare(
    'SELECT s.id, s.school_name, s.school_id_code, d.district_name
     FROM schools s
     LEFT JOIN districts d ON s.district_id = d.id
     WHERE s.id = ?
     LIMIT 1'
);
$schoolStmt->execute([$schoolId]);
$school = $schoolStmt->fetch();
if (!$school) {
    flash('error', 'Target school not found.');
    redirect(APP_URL . '/teachers.php');
}

if ((int)($teacher['school_id'] ?? 0) === $schoolId) {
    flash('error', 'Teacher is already assigned to the selected school.');
    redirect(APP_URL . '/teachers.php');
}

$teacherCols = [];
foreach ($db->query('SHOW COLUMNS FROM teachers')->fetchAll() as $colMeta) {
    $teacherCols[] = $colMeta['Field'];
}
$hasUpdatedAt = in_array('updated_at', $teacherCols, true);

$setParts = [
    'school_id = ?',
    'school_name_raw = ?',
    'school_id_code_raw = ?',
    'district_raw = ?',
];
if ($hasUpdatedAt) {
    $setParts[] = 'updated_at = NOW()';
}

$updateSql = 'UPDATE teachers SET ' . implode(', ', $setParts) . ' WHERE id = ?';
$params = [
    (int)$school['id'],
    (string)($school['school_name'] ?? ''),
    (string)($school['school_id_code'] ?? ''),
    (string)($school['district_name'] ?? ''),
    $teacherId,
];

$db->prepare($updateSql)->execute($params);

$teacherName = trim((string)($teacher['first_name'] ?? '') . ' ' . (string)($teacher['last_name'] ?? ''));
logActivity(
    'UPDATE',
    'teachers',
    $teacherId,
    'Transferred teacher ' . $teacherName . ' to school: ' . (string)($school['school_name'] ?? '')
);

flash('success', 'Teacher transferred successfully to ' . (string)($school['school_name'] ?? '') . '.');
redirect(APP_URL . '/teachers.php');
