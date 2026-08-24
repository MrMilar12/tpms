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

$db = getDB();
$schoolId = (int)($_POST['school_id'] ?? 0);
$returnQuery = trim((string)($_POST['return_query'] ?? ''));

$baseSql = 'SELECT id, item_number, salary_grade FROM teachers WHERE (position IS NULL OR TRIM(position) = "")';
$params = [];
if ($schoolId > 0) {
    $baseSql .= ' AND school_id = ?';
    $params[] = $schoolId;
}

$selectStmt = $db->prepare($baseSql . ' ORDER BY id');
$selectStmt->execute($params);
$rows = $selectStmt->fetchAll();

if (!$rows) {
    flash('info', 'No teacher records with blank position were found.');
    $suffix = $returnQuery !== '' ? ('?' . $returnQuery) : '';
    redirect(APP_URL . '/teachers.php' . $suffix);
}

$teacherCols = [];
foreach ($db->query('SHOW COLUMNS FROM teachers')->fetchAll() as $colMeta) {
    $teacherCols[] = $colMeta['Field'];
}
$hasUpdatedAt = in_array('updated_at', $teacherCols, true);

$setSql = 'position = ?' . ($hasUpdatedAt ? ', updated_at = NOW()' : '');
$updateStmt = $db->prepare('UPDATE teachers SET ' . $setSql . ' WHERE id = ?');

$generatedCount = 0;
$skippedCount = 0;

foreach ($rows as $row) {
    $derivedPosition = deriveTeacherPosition($row['item_number'] ?? '', $row['salary_grade'] ?? '');
    if ($derivedPosition === null) {
        $skippedCount++;
        continue;
    }

    $updateStmt->execute([$derivedPosition, (int)$row['id']]);
    $generatedCount++;
}

logActivity(
    'UPDATE',
    'teachers',
    null,
    'Generated teacher positions: ' . $generatedCount . ' updated, ' . $skippedCount . ' skipped'
);

if ($generatedCount > 0) {
    flash('success', 'Generated positions for ' . number_format($generatedCount) . ' teacher(s). Skipped ' . number_format($skippedCount) . '.');
} else {
    flash('info', 'No positions were generated. ' . number_format($skippedCount) . ' teacher(s) were skipped because item number/salary grade could not be mapped.');
}

$suffix = $returnQuery !== '' ? ('?' . $returnQuery) : '';
redirect(APP_URL . '/teachers.php' . $suffix);
