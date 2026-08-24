<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
startSecureSession();
requireLogin();
verifyCsrf();

if (!canEdit()) {
    logActivity('DENY', 'schools', null, 'Blocked school type tag attempt without permission.');
    flash('error', 'Permission denied.');
    redirect(APP_URL . '/schools.php');
}

$id = (int)($_POST['id'] ?? 0);
$schoolIdsRaw = $_POST['school_ids'] ?? [];
$schoolTypeRaw = trim((string)($_POST['school_type'] ?? ''));
$returnQuery = trim((string)($_POST['return_query'] ?? ''));
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

if ($returnQuery !== '' && !preg_match('/^[a-zA-Z0-9_\-=&%\.]*$/', $returnQuery)) {
    $returnQuery = '';
}

if ($confirmPassword === '') {
    logActivity('DENY', 'schools', $id > 0 ? $id : null, 'Blocked school type tag: missing password confirmation.');
    flash('error', 'Password confirmation is required to tag school type.');
    $redirectUrl = APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');
    redirect($redirectUrl);
}

$typeMap = [
    'public' => 'Public',
    'private' => 'Private',
    'als' => 'ALS',
    'elementary' => 'Elementary',
    'es' => 'Elementary',
    'jhs' => 'JHS',
    'shs' => 'SHS',
    'es/jhs' => 'ES/JHS',
    'es/shs' => 'ES/SHS',
    'jhs/shs' => 'JHS/SHS',
    'jhs - shs' => 'JHS/SHS',
    'junior and senior high school' => 'JHS/SHS',
    'es/jhs/shs' => 'ALL OFFERING',
    'all offering' => 'ALL OFFERING',
];
$schoolType = $typeMap[strtolower($schoolTypeRaw)] ?? null;
if ($schoolType === null) {
    logActivity('DENY', 'schools', $id > 0 ? $id : null, 'Blocked school type tag: unsupported type "' . $schoolTypeRaw . '".');
    flash('error', 'Unsupported school type.');
    $redirectUrl = APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');
    redirect($redirectUrl);
}

$db = getDB();
$me = (int)(currentUser()['id'] ?? 0);
$pwStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$pwStmt->execute([$me]);
$passwordHash = (string)$pwStmt->fetchColumn();
if ($passwordHash === '' || !password_verify($confirmPassword, $passwordHash)) {
    logActivity('DENY', 'schools', $id > 0 ? $id : null, 'Blocked school type tag: invalid password.');
    flash('error', 'Invalid password. School type was not updated.');
    $redirectUrl = APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');
    redirect($redirectUrl);
}

// Accept either a single school ID or a bulk list from the UI.
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

if (empty($schoolIds)) {
    logActivity('DENY', 'schools', null, 'Blocked school type tag: no school selected.');
    flash('error', 'Select at least one school.');
    $redirectUrl = APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');
    redirect($redirectUrl);
}

if (count($schoolIds) > 500) {
    logActivity('DENY', 'schools', null, 'Blocked school type tag: too many schools in one request (' . count($schoolIds) . ').');
    flash('error', 'Too many selected schools. Please tag in smaller batches.');
    $redirectUrl = APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');
    redirect($redirectUrl);
}

$ph = implode(',', array_fill(0, count($schoolIds), '?'));
$checkStmt = $db->prepare('SELECT id, school_name FROM schools WHERE id IN (' . $ph . ')');
$checkStmt->execute($schoolIds);
$existing = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

if (count($existing) !== count($schoolIds)) {
    logActivity('DENY', 'schools', null, 'Blocked school type tag: one or more school IDs not found.');
    flash('error', 'School not found.');
    $redirectUrl = APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');
    redirect($redirectUrl);
}

$updateSql = 'UPDATE schools
              SET school_type = ?,
                  als_subtype = CASE WHEN ? = "ALS" THEN als_subtype ELSE NULL END,
                  updated_at = NOW()
              WHERE id IN (' . $ph . ')';

$db->beginTransaction();
try {
    $updateStmt = $db->prepare($updateSql);
    $updateStmt->execute(array_merge([$schoolType, $schoolType], $schoolIds));
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    logActivity('DENY', 'schools', null, 'School type tag failed due to exception: ' . $e->getMessage());
    flash('error', 'Could not update school type right now.');
    $redirectUrl = APP_URL . '/schools.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');
    redirect($redirectUrl);
}

$updatedCount = count($schoolIds);
$sampleNames = array_slice(array_map(static fn($r) => (string)$r['school_name'], $existing), 0, 5);
$details = 'Tagged ' . $updatedCount . ' school(s) as ' . $schoolType
    . '; IDs=' . implode(',', $schoolIds)
    . '; sample=' . implode(' | ', $sampleNames);
logActivity('UPDATE', 'schools', $schoolIds[0] ?? null, $details);
flash('success', $updatedCount . ' school(s) tagged as ' . $schoolType . '.');

$redirectUrl = APP_URL . '/schools.php';
if ($returnQuery !== '') {
    $redirectUrl .= '?' . $returnQuery;
}
redirect($redirectUrl);
