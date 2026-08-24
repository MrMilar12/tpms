<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
startSecureSession();
requireLogin();
verifyCsrf();

if (!canEdit()) {
    flash('error', 'Permission denied.');
    redirect(APP_URL . '/schools.php');
}

$id       = (int)($_POST['id'] ?? 0);
$name     = trim($_POST['school_name']   ?? '');
$code     = trim($_POST['school_id_code'] ?? '');
$municipality = trim($_POST['municipality'] ?? '');
$schoolType   = trim($_POST['school_type'] ?? '');
$alsSubtype   = trim($_POST['als_subtype'] ?? '');
$district = trim($_POST['district']      ?? '');
$schoolHeadTeacherId = (int)($_POST['school_head_teacher_id'] ?? 0);
$learnerCount = max(0, (int)($_POST['learner_count'] ?? 0));
$learnersPerTeacher = max(1, min(200, (int)($_POST['learners_per_teacher'] ?? 35)));
$schoolYear = trim((string)($_POST['school_year'] ?? ''));
$totalSections = max(0, (int)($_POST['total_sections'] ?? 0));
$totalRequiredClasses = max(0, (int)($_POST['total_required_classes'] ?? 0));
$hoursPerClassWeek = max(0.5, min(20, (float)($_POST['hours_per_class_week'] ?? PLANNING_DEFAULT_HOURS_PER_CLASS_WEEK)));

$typeMap = [
    'public' => 'Public',
    'private' => 'Private',
    'als' => 'ALS',
    'elementary' => 'Elementary',
    'jhs' => 'JHS',
    'junior high school' => 'JHS',
    'shs' => 'SHS',
        'es/jhs' => 'ES/JHS',
        'es/shs' => 'ES/SHS',
    'jhs/shs' => 'JHS/SHS',
    'jhs - shs' => 'JHS/SHS',
    'junior and senior high school' => 'JHS/SHS',
        'es/jhs/shs' => 'ALL OFFERING',
    'senior high school' => 'SHS',
];
$schoolType = $schoolType === '' ? '' : ($typeMap[strtolower($schoolType)] ?? '');

// Normalize ALS subtype if provided
if ($alsSubtype !== '' && strtolower($schoolType) === 'als') {
    $alsSubtype = isset(ALS_SUBTYPES[ucfirst(strtolower($alsSubtype))]) 
        ? ALS_SUBTYPES[ucfirst(strtolower($alsSubtype))]
        : (isset(ALS_SUBTYPES[$alsSubtype]) ? ALS_SUBTYPES[$alsSubtype] : $alsSubtype);
} else {
    $alsSubtype = null;
}

if ($name === '') {
    flash('error', 'School name is required.');
    redirect(APP_URL . '/schools.php');
}

$db = getDB();
ensureTeacherPlanningSchema($db);

$schoolCols = [];
foreach ($db->query('SHOW COLUMNS FROM schools')->fetchAll() as $colMeta) {
    $schoolCols[] = $colMeta['Field'];
}
$hasLearnersPerTeacher = in_array('learners_per_teacher', $schoolCols, true);

if ($schoolHeadTeacherId > 0) {
    $teacherExists = $db->prepare('SELECT id FROM teachers WHERE id = ? LIMIT 1');
    $teacherExists->execute([$schoolHeadTeacherId]);
    if (!$teacherExists->fetchColumn()) {
        $schoolHeadTeacherId = 0;
    }
}

if ($id > 0) {
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    if ($confirmPassword === '') {
        flash('error', 'Password confirmation is required to edit school records.');
        redirect(APP_URL . '/schools.php');
    }

    $me = (int)(currentUser()['id'] ?? 0);
    $pwStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $pwStmt->execute([$me]);
    $passwordHash = (string)$pwStmt->fetchColumn();
    if ($passwordHash === '' || !password_verify($confirmPassword, $passwordHash)) {
        flash('error', 'Invalid password. School update was not performed.');
        redirect(APP_URL . '/schools.php');
    }
}

// Resolve or create the district only after edit authorization succeeds.
// This prevents an invalid school-edit attempt from creating district data.
$districtId = null;
if ($district !== '') {
    $dStmt = $db->prepare('SELECT id FROM districts WHERE district_name = ?');
    $dStmt->execute([$district]);
    $dRow = $dStmt->fetch();
    if ($dRow) {
        $districtId = (int)$dRow['id'];
    } else {
        $db->prepare('INSERT INTO districts (district_name) VALUES (?)')->execute([$district]);
        $districtId = (int)$db->lastInsertId();
    }
}

if ($id > 0) {
    $updateFields = [
        'school_name' => $name,
        'school_id_code' => $code ?: null,
        'municipality' => $municipality ?: null,
        'school_type' => $schoolType ?: null,
        'als_subtype' => $alsSubtype,
        'district_id' => $districtId,
        'learner_count' => $learnerCount,
    ];
    if (in_array('school_head_teacher_id', $schoolCols, true)) $updateFields['school_head_teacher_id'] = $schoolHeadTeacherId ?: null;
    if ($hasLearnersPerTeacher) $updateFields['learners_per_teacher'] = $learnersPerTeacher;
    if (in_array('school_year', $schoolCols, true)) $updateFields['school_year'] = $schoolYear !== '' ? $schoolYear : null;
    if (in_array('total_sections', $schoolCols, true)) $updateFields['total_sections'] = $totalSections;
    if (in_array('total_required_classes', $schoolCols, true)) $updateFields['total_required_classes'] = $totalRequiredClasses;
    if (in_array('hours_per_class_week', $schoolCols, true)) $updateFields['hours_per_class_week'] = $hoursPerClassWeek;

    $setSql = implode(', ', array_map(static fn($c) => $c . ' = ?', array_keys($updateFields)));
    $values = array_values($updateFields);
    $values[] = $id;

    $db->prepare('UPDATE schools SET ' . $setSql . ', updated_at = NOW() WHERE id = ?')->execute($values);
    logActivity('UPDATE', 'schools', $id, "Updated school: $name");
    flash('success', 'School updated.');
} else {
    $insertFields = [
        'school_name' => $name,
        'school_id_code' => $code ?: null,
        'municipality' => $municipality ?: null,
        'school_type' => $schoolType ?: null,
        'als_subtype' => $alsSubtype,
        'district_id' => $districtId,
        'learner_count' => $learnerCount,
    ];
    if (in_array('school_head_teacher_id', $schoolCols, true)) $insertFields['school_head_teacher_id'] = $schoolHeadTeacherId ?: null;
    if ($hasLearnersPerTeacher) $insertFields['learners_per_teacher'] = $learnersPerTeacher;
    if (in_array('school_year', $schoolCols, true)) $insertFields['school_year'] = $schoolYear !== '' ? $schoolYear : null;
    if (in_array('total_sections', $schoolCols, true)) $insertFields['total_sections'] = $totalSections;
    if (in_array('total_required_classes', $schoolCols, true)) $insertFields['total_required_classes'] = $totalRequiredClasses;
    if (in_array('hours_per_class_week', $schoolCols, true)) $insertFields['hours_per_class_week'] = $hoursPerClassWeek;

    $insertCols = implode(', ', array_keys($insertFields));
    $insertPh = implode(', ', array_fill(0, count($insertFields), '?'));
    $db->prepare('INSERT INTO schools (' . $insertCols . ') VALUES (' . $insertPh . ')')->execute(array_values($insertFields));
    logActivity('CREATE', 'schools', (int)$db->lastInsertId(), "Added school: $name");
    flash('success', 'School added.');
}
redirect(APP_URL . '/schools.php');
