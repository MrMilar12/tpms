<?php
// ============================================================
// process_school_upload.php – Bulk School Upload Handler
// ============================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
startSecureSession();
requireRole(['admin', 'hr']);
verifyCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['upload_file']['name'])) {
    flash('error', 'No file received.');
    redirect(APP_URL . '/schools.php');
}

$file     = $_FILES['upload_file'];
$maxBytes = 20 * 1024 * 1024;

if ($file['error'] !== UPLOAD_ERR_OK) {
    flash('error', 'Upload error code: ' . $file['error']);
    redirect(APP_URL . '/schools.php');
}
if ($file['size'] > $maxBytes) {
    flash('error', 'File too large. Max 20 MB.');
    redirect(APP_URL . '/schools.php');
}

$ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['xlsx', 'xls', 'csv'];
if (!in_array($ext, $allowed, true)) {
    flash('error', 'Invalid file type. Use .xlsx or .csv.');
    redirect(APP_URL . '/schools.php');
}

// MIME type validation (prevent malicious files)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
$allowedMimes = ['text/csv', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
if (!in_array($mime, $allowedMimes, true)) {
    flash('error', 'Invalid file MIME type. Accepted: CSV or Excel files.');
    redirect(APP_URL . '/schools.php');
}

$tmpDest = sys_get_temp_dir() . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $tmpDest)) {
    flash('error', 'Could not process the uploaded file.');
    redirect(APP_URL . '/schools.php');
}
// Ensure temp file is cleaned up
register_shutdown_function(function() use ($tmpDest) { @unlink($tmpDest); });

$rawRows = ($ext === 'csv') ? parseCSV($tmpDest) : parseXLSX($tmpDest);
@unlink($tmpDest);

if ($rawRows === false || count($rawRows) < 2) {
    flash('error', 'Could not read file or file is empty.');
    redirect(APP_URL . '/schools.php');
}

// ── Normalize header helper ──────────────────────────────────
function normSchoolHdr(string $h): string {
    $h = preg_replace('/^\xEF\xBB\xBF/u', '', $h); // strip UTF-8 BOM
    return strtolower(preg_replace('/\s+/', ' ', trim($h)));
}

// Normalize school code values from XLSX/CSV (e.g., 300001.0 -> 300001)
function normSchoolCode(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    if (preg_match('/^(\d+)\.0+$/', $v, $m)) return $m[1];
    return $v;
}

function normSchoolType(string $v): ?string {
    $v = strtolower(trim($v));
    return match ($v) {
        'public' => 'Public',
        'private' => 'Private',
        'als' => 'ALS',
        'elementary' => 'Elementary',
        'jhs', 'junior high school' => 'JHS',
        'shs', 'senior high school' => 'SHS',
        'es/jhs', 'es with jhs' => 'ES/JHS',
        'es/shs', 'es with shs' => 'ES/SHS',
        'jhs/shs', 'jhs with shs', 'jhs - shs', 'junior and senior high school' => 'JHS/SHS',
        'es/jhs/shs', 'es with jhs with shs', 'all offering' => 'ALL OFFERING',
        default => null,
    };
}

function normAlsSubtype(string $v): ?string {
    $v = strtolower(trim($v));
    if ($v === '') return null;
    foreach (ALS_SUBTYPES as $key => $norm) {
        if (strtolower($key) === $v) {
            return $norm;
        }
    }
    return null;
}

// Column aliases → internal key
$schoolColAliases = [
    // School name variants
    'school name'          => 'school_name',
    'name of school'       => 'school_name',
    'school/learning center name' => 'school_name',
    'learning center name' => 'school_name',
    'school'               => 'school_name',
    'name'                 => 'school_name',

    // School ID code variants
    'school id code'       => 'school_id_code',
    'school id'            => 'school_id_code',
    'school id no.'        => 'school_id_code',
    'school id no'         => 'school_id_code',
    'school id number'     => 'school_id_code',
    'beis school id'       => 'school_id_code',
    'beis id no.'          => 'school_id_code',
    'id code'              => 'school_id_code',
    'beis id'              => 'school_id_code',

    // District variants
    'district name'        => 'district_name',
    'district'             => 'district_name',

    // Municipality/city variants (optional, currently ignored in DB)
    'municipality'         => 'municipality',
    'city/municipality'    => 'municipality',
    'city'                 => 'municipality',

    // Type/category variants (optional, currently ignored in DB)
    'school type'          => 'school_type',
    'classification'       => 'school_type',
    'type'                 => 'school_type',

    // ALS subtype variants (optional)
    'als subtype'          => 'als_subtype',
    'als type'             => 'als_subtype',
    'als category'         => 'als_subtype',

    // Learner count variants (optional)
    'learner count'        => 'learner_count',
    'learners count'       => 'learner_count',
    'number of learners'   => 'learner_count',
    'no. of learners'      => 'learner_count',
    'learners'             => 'learner_count',
    'total learners'       => 'learner_count',
    'school year'          => 'school_year',
    'total sections'       => 'total_sections',
    'number of sections'   => 'total_sections',
    'required classes'     => 'total_required_classes',
    'total required classes' => 'total_required_classes',
    'hours per class per week' => 'hours_per_class_week',
    'hours/class/week'     => 'hours_per_class_week',
];

// Detect header row (supports title rows before actual headers)
$headerIdx = 0;
$headerRow = [];
$colMap    = [];
$scanLimit = min(count($rawRows), 15);
for ($i = 0; $i < $scanLimit; $i++) {
    $candidate = array_map('trim', $rawRows[$i]);
    $candidateMap = [];
    foreach ($candidate as $idx => $rawHdr) {
        $norm = normSchoolHdr((string)$rawHdr);
        if (isset($schoolColAliases[$norm]) && !isset($candidateMap[$schoolColAliases[$norm]])) {
            $candidateMap[$schoolColAliases[$norm]] = $idx;
        }
    }
    if (isset($candidateMap['school_name']) && isset($candidateMap['school_id_code'])) {
        $headerIdx = $i;
        $headerRow = $candidate;
        $colMap = $candidateMap;
        break;
    }
}

if (!$headerRow) {
    $headerRow = array_map('trim', $rawRows[0]);
}

// Validate required columns
$required = ['school_name', 'school_id_code'];
$missing  = [];
foreach ($required as $req) {
    if (!isset($colMap[$req])) $missing[] = $req;
}
if ($missing) {
    flash('error', 'Missing required columns: ' . implode(', ', array_map('ucwords', str_replace('_', ' ', $missing))));
    redirect(APP_URL . '/schools.php');
}

$skipDupes   = !empty($_POST['skip_duplicates']);
$updateExist = !empty($_POST['update_existing']);

// Default behavior for re-upload is to update existing records unless
// user explicitly chooses skip-duplicates only.
if (!$skipDupes && !$updateExist) {
    $updateExist = true;
}

$dataRows  = array_slice($rawRows, $headerIdx + 1);
$totalRows = count($dataRows);
$imported  = 0;
$skipped   = 0;
$errors    = 0;
$errDetails = [];

$db = getDB();
ensureTeacherPlanningSchema($db);

$schoolCols = [];
foreach ($db->query('SHOW COLUMNS FROM schools')->fetchAll() as $colMeta) {
    $schoolCols[] = $colMeta['Field'];
}

$hasMunicipality = in_array('municipality', $schoolCols, true);
$hasSchoolType   = in_array('school_type', $schoolCols, true);
$hasAlsSubtype   = in_array('als_subtype', $schoolCols, true);
$hasLearnerCount = in_array('learner_count', $schoolCols, true);
$hasSchoolYear = in_array('school_year', $schoolCols, true);
$hasTotalSections = in_array('total_sections', $schoolCols, true);
$hasRequiredClasses = in_array('total_required_classes', $schoolCols, true);
$hasHoursPerClass = in_array('hours_per_class_week', $schoolCols, true);

// Preload districts: lower(district_name) → id
$districtMap = [];
foreach ($db->query('SELECT id, district_name FROM districts')->fetchAll() as $d) {
    $districtMap[strtolower(trim($d['district_name']))] = (int)$d['id'];
}

$checkStmt  = $db->prepare('SELECT id FROM schools WHERE school_id_code = ? LIMIT 1');

$insertCols = ['school_name', 'school_id_code'];
if ($hasMunicipality) {
    $insertCols[] = 'municipality';
}
if ($hasSchoolType) {
    $insertCols[] = 'school_type';
}
if ($hasAlsSubtype) {
    $insertCols[] = 'als_subtype';
}
if ($hasLearnerCount) {
    $insertCols[] = 'learner_count';
}
if ($hasSchoolYear) {
    $insertCols[] = 'school_year';
}
if ($hasTotalSections) {
    $insertCols[] = 'total_sections';
}
if ($hasRequiredClasses) {
    $insertCols[] = 'total_required_classes';
}
if ($hasHoursPerClass) {
    $insertCols[] = 'hours_per_class_week';
}
$insertCols[] = 'district_id';
$insertStmt = $db->prepare(
    'INSERT INTO schools (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', array_fill(0, count($insertCols), '?')) . ')'
);

$updateParts = ['school_name = ?'];
if ($hasMunicipality) {
    $updateParts[] = 'municipality = ?';
}
if ($hasSchoolType) {
    $updateParts[] = 'school_type = ?';
}
if ($hasAlsSubtype) {
    $updateParts[] = 'als_subtype = ?';
}
if ($hasLearnerCount) {
    $updateParts[] = 'learner_count = ?';
}
if ($hasSchoolYear) {
    $updateParts[] = 'school_year = ?';
}
if ($hasTotalSections) {
    $updateParts[] = 'total_sections = ?';
}
if ($hasRequiredClasses) {
    $updateParts[] = 'total_required_classes = ?';
}
if ($hasHoursPerClass) {
    $updateParts[] = 'hours_per_class_week = ?';
}
$updateParts[] = 'district_id = ?';
$updateParts[] = 'updated_at = NOW()';
$updateStmt = $db->prepare('UPDATE schools SET ' . implode(', ', $updateParts) . ' WHERE school_id_code = ?');
$insertDist = $db->prepare('INSERT INTO districts (district_name) VALUES (?)');

$db->beginTransaction();
try {
    foreach ($dataRows as $rowNum => $row) {
        $lineNo = $headerIdx + $rowNum + 2;
        $schoolName = trim((string)($row[$colMap['school_name']] ?? ''));
        $schoolCode = normSchoolCode((string)($row[$colMap['school_id_code']] ?? ''));
        $municipality = isset($colMap['municipality'])
            ? trim((string)($row[$colMap['municipality']] ?? ''))
            : '';
        $schoolType = isset($colMap['school_type'])
            ? trim((string)($row[$colMap['school_type']] ?? ''))
            : '';
        $schoolTypeNorm = normSchoolType($schoolType);
        $alsSubtype = isset($colMap['als_subtype'])
            ? trim((string)($row[$colMap['als_subtype']] ?? ''))
            : '';
        $alsSubtypeNorm = normAlsSubtype($alsSubtype);
        $learnerCount = isset($colMap['learner_count'])
            ? max(0, (int)($row[$colMap['learner_count']] ?? 0))
            : 0;
        $schoolYear = isset($colMap['school_year'])
            ? trim((string)($row[$colMap['school_year']] ?? ''))
            : '';
        $totalSections = isset($colMap['total_sections'])
            ? max(0, (int)($row[$colMap['total_sections']] ?? 0))
            : 0;
        $totalRequiredClasses = isset($colMap['total_required_classes'])
            ? max(0, (int)($row[$colMap['total_required_classes']] ?? 0))
            : 0;
        $hoursPerClassWeek = isset($colMap['hours_per_class_week']) && is_numeric((string)($row[$colMap['hours_per_class_week']] ?? ''))
            ? max(0.5, min(20, (float)$row[$colMap['hours_per_class_week']]))
            : (float)PLANNING_DEFAULT_HOURS_PER_CLASS_WEEK;

        if ($schoolName === '' || $schoolCode === '') {
            $errors++;
            $errDetails[] = 'Row ' . $lineNo . ': Missing school name or ID code.';
            continue;
        }

        // ── Resolve district ─────────────────────────────────
        $districtId = null;
        if (isset($colMap['district_name'])) {
            $districtName = trim((string)($row[$colMap['district_name']] ?? ''));
            if ($districtName !== '') {
                $distKey = strtolower($districtName);
                if (isset($districtMap[$distKey])) {
                    $districtId = $districtMap[$distKey];
                } else {
                    // Auto-create district
                    $insertDist->execute([$districtName]);
                    $districtId = (int)$db->lastInsertId();
                    $districtMap[$distKey] = $districtId;
                }
            }
        }

        // ── Check duplicate ───────────────────────────────────
        $checkStmt->execute([$schoolCode]);
        $exists = $checkStmt->fetch();

        if ($exists) {
            if ($skipDupes && !$updateExist) { $skipped++; continue; }
            if ($updateExist) {
                $updateVals = [$schoolName];
                if ($hasMunicipality) {
                    $updateVals[] = $municipality !== '' ? $municipality : null;
                }
                if ($hasSchoolType) {
                    $updateVals[] = $schoolTypeNorm;
                }
                if ($hasAlsSubtype) {
                    $updateVals[] = $alsSubtypeNorm;
                }
                if ($hasLearnerCount) {
                    $updateVals[] = $learnerCount;
                }
                if ($hasSchoolYear) {
                    $updateVals[] = $schoolYear !== '' ? $schoolYear : null;
                }
                if ($hasTotalSections) {
                    $updateVals[] = $totalSections;
                }
                if ($hasRequiredClasses) {
                    $updateVals[] = $totalRequiredClasses;
                }
                if ($hasHoursPerClass) {
                    $updateVals[] = $hoursPerClassWeek;
                }
                $updateVals[] = $districtId;
                $updateVals[] = $schoolCode;
                $updateStmt->execute($updateVals);
                $imported++;
                continue;
            }
            // Neither option selected — skip by default
            $skipped++;
            continue;
        }
        $insertVals = [$schoolName, $schoolCode];
        if ($hasMunicipality) {
            $insertVals[] = $municipality !== '' ? $municipality : null;
        }
        if ($hasSchoolType) {
            $insertVals[] = $schoolTypeNorm;
        }
        if ($hasAlsSubtype) {
            $insertVals[] = $alsSubtypeNorm;
        }
        if ($hasLearnerCount) {
            $insertVals[] = $learnerCount;
        }
        if ($hasSchoolYear) {
            $insertVals[] = $schoolYear !== '' ? $schoolYear : null;
        }
        if ($hasTotalSections) {
            $insertVals[] = $totalSections;
        }
        if ($hasRequiredClasses) {
            $insertVals[] = $totalRequiredClasses;
        }
        if ($hasHoursPerClass) {
            $insertVals[] = $hoursPerClassWeek;
        }
        $insertVals[] = $districtId;
        $insertStmt->execute($insertVals);
        $imported++;
    }
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('TPMS School Upload Error: ' . $e->getMessage());
    flash('error', 'Upload failed: ' . $e->getMessage());
    redirect(APP_URL . '/schools.php');
}

// Log to upload_logs
$db->prepare(
    'INSERT INTO upload_logs (file_name, total_rows, imported_rows, skipped_rows, error_rows, uploaded_by)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([
    '[Schools] ' . basename($file['name']),
    $totalRows,
    $imported,
    $skipped,
    $errors,
    currentUser()['id'],
]);

logActivity('UPLOAD', 'schools', null,
    "School bulk upload: {$file['name']} – $imported imported, $skipped skipped, $errors errors.");

if ($errDetails) {
    $_SESSION['upload_error_report'] = [
        'module'     => 'schools',
        'file_name'  => basename($file['name']),
        'total_rows' => $totalRows,
        'imported'   => $imported,
        'skipped'    => $skipped,
        'errors'     => $errors,
        'details'    => $errDetails,
    ];
    $_SESSION['upload_errors'] = $errDetails;
}

flash('success', "School upload complete: $imported imported, $skipped skipped, $errors errors.");
redirect(APP_URL . '/schools.php');
