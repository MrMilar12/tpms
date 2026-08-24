<?php
// ============================================================
// process_upload.php – Bulk Teacher Upload Handler
// ============================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
startSecureSession();
requireRole(['admin', 'hr']);
verifyCsrf();

$redirectTarget = APP_URL . '/teachers.php';
$postedReturnTo = trim((string)($_POST['return_to'] ?? ''));
if ($postedReturnTo !== '') {
    $appUrlParts = parse_url(APP_URL);
    $retParts = parse_url($postedReturnTo);
    $sameHost = strtolower((string)($retParts['host'] ?? '')) === strtolower((string)($appUrlParts['host'] ?? ''));
    $samePort = (int)($retParts['port'] ?? 0) === (int)($appUrlParts['port'] ?? 0);
    $appPath = rtrim((string)($appUrlParts['path'] ?? ''), '/');
    $retPath = (string)($retParts['path'] ?? '');
    $pathOk = ($appPath === '') || str_starts_with($retPath, $appPath . '/') || $retPath === $appPath;

    if ($sameHost && $samePort && $pathOk) {
        $redirectTarget = $postedReturnTo;
    }
}

$failUpload = static function(string $message) use ($redirectTarget): void {
    flash('error', $message);
    redirect($redirectTarget);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['upload_file']['name'])) {
    $failUpload('No file received.');
}

$file     = $_FILES['upload_file'];
$maxBytes = 20 * 1024 * 1024; // 20 MB

if ($file['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'Upload failed: file exceeds server upload_max_filesize limit.',
        UPLOAD_ERR_FORM_SIZE  => 'Upload failed: file exceeds the allowed form size.',
        UPLOAD_ERR_PARTIAL    => 'Upload failed: file was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_FILE    => 'Upload failed: no file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Upload failed: missing server temp folder.',
        UPLOAD_ERR_CANT_WRITE => 'Upload failed: server could not write the uploaded file.',
        UPLOAD_ERR_EXTENSION  => 'Upload failed: blocked by a server extension.',
    ];
    $failUpload($uploadErrors[$file['error']] ?? ('Upload error: ' . $file['error']));
}
if ($file['size'] > $maxBytes) {
    $failUpload('File too large. Max 20 MB.');
}

$ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed  = ['xlsx', 'csv'];
if (!in_array($ext, $allowed, true)) {
    $failUpload('Invalid file type. Use .xlsx or .csv.');
}

// MIME type validation (prevent malicious files)
$mime = '';
if (function_exists('finfo_open') && function_exists('finfo_file')) {
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $detectedMime = @finfo_file($finfo, $file['tmp_name']);
        if (is_string($detectedMime)) {
            $mime = strtolower(trim($detectedMime));
        }
        finfo_close($finfo);
    }
}

if ($mime !== '') {
    $allowedMimes = [
        'text/csv',
        'text/plain',
        'text/comma-separated-values',
        'application/csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/octet-stream',
        'application/zip',
        'application/x-zip-compressed',
    ];

    $mimeAccepted = in_array($mime, $allowedMimes, true);
    if (!$mimeAccepted && $ext === 'csv' && str_starts_with($mime, 'text/')) {
        $mimeAccepted = true;
    }
    if (!$mimeAccepted && $ext === 'xlsx' && in_array($mime, ['application/octet-stream', 'application/zip', 'application/x-zip-compressed'], true)) {
        $mimeAccepted = true;
    }

    if (!$mimeAccepted) {
        $failUpload('Invalid file MIME type detected (' . $mime . '). Accepted: CSV or Excel files.');
    }
}

if ($ext === 'xlsx' && !class_exists('ZipArchive')) {
    $failUpload('Cannot read .xlsx files right now because PHP ZIP extension is disabled. Enable zip in php.ini or upload CSV.');
}

// Move to temp
$tmpDest = sys_get_temp_dir() . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $tmpDest)) {
    $failUpload('Could not process the uploaded file.');
}
// Ensure temp file is cleaned up
register_shutdown_function(function() use ($tmpDest) { @unlink($tmpDest); });

// Parse
$rawRows = ($ext === 'csv') ? parseCSV($tmpDest) : parseXLSX($tmpDest);
@unlink($tmpDest);

if ($rawRows === false) {
    $failUpload('Could not read the uploaded file. If this is Excel, re-save as .xlsx or CSV and try again.');
}

if (count($rawRows) < 2) {
    $failUpload('File was read but has no data rows. Please keep one header row and at least one teacher row.');
}

// ── Upload date parser ───────────────────────────────────────
// Handles plain date strings AND Excel serial numbers
function parseUploadDate(string $value): string {
    $value = trim($value);
    if ($value === '') return '';

    // Normalize repeated whitespace and remove ordinal suffixes (e.g., 1st, 2nd).
    $value = preg_replace('/\s+/', ' ', $value);
    $value = preg_replace('/\b(\d{1,2})(st|nd|rd|th)\b/i', '$1', $value);

    // Clean common spreadsheet wrappers: quotes, leading '=' formulas, apostrophe text markers.
    $value = trim($value, " \t\n\r\0\x0B\"'");
    $value = ltrim($value, "=\t ");
    $value = preg_replace('/\s*GMT[+\-]\d{1,2}:?\d{0,2}\s*$/i', '', $value);

    // Excel serial (integer or float with decimal time component)
    if (is_numeric($value)) {
        $serial = (float)$value;
        // Plausible Excel date range: ~1900 (1) to 2200 (109574)
        if ($serial >= 1 && $serial < 109574) {
            // 25569 = Excel serial for Unix epoch (Jan 1, 1970)
            $unix = (int)(($serial - 25569) * 86400);
            return date('Y-m-d', $unix);
        }
    }

    // Deterministic handling for numeric dates with separators.
    // For slash-separated dates, treat as DD/MM/YYYY (PH format).
    // Also supports optional trailing time (e.g., 18/02/1976 00:00:00).
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})(?:\s+.*)?$/', $value, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];
        if ($year < 100) {
            $year += ($year >= 70 ? 1900 : 2000);
        }
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    // If date is embedded in a larger string, extract and parse the first D/M/Y token.
    if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/', $value, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];
        if ($year < 100) {
            $year += ($year >= 70 ? 1900 : 2000);
        }
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    // Dash and dot numeric forms (DD-MM-YYYY / DD.MM.YYYY), optional time.
    if (preg_match('/^(\d{1,2})[-.](\d{1,2})[-.](\d{2,4})(?:\s+.*)?$/', $value, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];
        if ($year < 100) {
            $year += ($year >= 70 ? 1900 : 2000);
        }
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    if (preg_match('/\b(\d{1,2})[-.](\d{1,2})[-.](\d{2,4})\b/', $value, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];
        if ($year < 100) {
            $year += ($year >= 70 ? 1900 : 2000);
        }
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    // Explicitly handle common CSV/Excel date formats first, including day-first.
    $formats = [
        '!Y-m-d', '!Y/m/d',
        '!d/m/Y H:i:s', '!d/m/Y H:i', '!d/m/Y g:i:s A', '!d/m/Y h:i:s A', '!d/m/Y g:i A', '!d/m/Y h:i A',
        '!d/m/Y', '!d-m-Y', '!d.m.Y',
        '!m/d/Y', '!m-d-Y',
        '!d-m-Y H:i:s', '!d-m-Y H:i', '!d.m.Y H:i:s', '!d.m.Y H:i',
        '!d/m/y', '!d-m-y',
        '!m/d/y', '!m-d-y',
        '!M j, Y', '!F j, Y',
        '!M j Y', '!F j Y',
        '!M d, Y', '!F d, Y',
        '!M j, Y g:i:s A', '!F j, Y g:i:s A',
        '!M j, Y h:i:s A', '!F j, Y h:i:s A',
        '!M j, Y H:i:s', '!F j, Y H:i:s',
        '!Y-m-d H:i:s', '!Y/m/d H:i:s',
    ];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        $errors = DateTime::getLastErrors();
        $hasErrors = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
        if ($dt instanceof DateTime && !$hasErrors) {
            return $dt->format('Y-m-d');
        }
    }

    // Final fallback for other human-readable date strings.
    try { return (new DateTime($value))->format('Y-m-d'); }
    catch (Exception) { return ''; }
}

// ── Data Privacy Consent normalizer ─────────────────────────
function normalizeConsent(string $value): string {
    $lower = strtolower(trim($value));
    if ($lower === '' || $lower === 'no') return 'No';
    // Google Form: "I consent to the collection of my personal information."
    if (str_starts_with($lower, 'i consent') || str_starts_with($lower, 'i agree') || $lower === 'yes') {
        return 'Yes';
    }
    return 'No';
}

// ── PWD normalizer ───────────────────────────────────────────
function normalizePwd(string $value): string {
    $lower = strtolower(trim($value));
    return in_array($lower, ['yes', 'y', '1', 'true'], true) ? 'Yes' : 'No';
}

// ── Gender normalizer ──────────────────────────────────────
function normalizeGender(string $value): ?string {
    $v = strtolower(trim($value));
    if ($v === '') {
        return null;
    }

    if (in_array($v, ['m', 'male', 'man', 'boy'], true)) {
        return 'Male';
    }
    if (in_array($v, ['f', 'female', 'woman', 'girl'], true)) {
        return 'Female';
    }

    return null;
}

// ── Extract school code from "School Name (123456)" ──────────
function extractSchoolCode(string $value): string {
    $v = trim($value);
    if ($v === '') return '';
    if (ctype_digit($v)) return $v;
    // Parenthesised: "Name (300001)"
    if (preg_match('/\((\d{4,})\)/', $v, $m)) return $m[1];
    // Trailing number after dash/space: "Name - 300001"
    if (preg_match('/[-\s](\d{4,})\s*$/', $v, $m)) return $m[1];
    // Any standalone 4+ digit number
    if (preg_match('/\b(\d{4,})\b/', $v, $m)) return $m[1];
    return '';
}

// Try to infer district name from station/school text when the dedicated
// district column is not provided.
function inferDistrictName(string ...$candidates): string {
    foreach ($candidates as $raw) {
        $v = trim($raw);
        if ($v === '') {
            continue;
        }

        // Remove trailing school codes and normalize spacing.
        $v = preg_replace('/\(\d{4,}\)\s*$/', '', $v);
        $v = preg_replace('/\s+/', ' ', trim((string)$v));

        // Ignore obvious school-level names to avoid false district inserts.
        if (preg_match('/\b(ES|ELEM|ELEMENTARY|NHS|NATIONAL HIGH|SCHOOL)\b/i', $v)) {
            continue;
        }

        // Common district naming forms.
        if (preg_match('/^[A-Za-z][A-Za-z .\'\-]{2,}\s(?:NORTH|SOUTH|EAST|WEST|CENTRAL)$/i', $v)) {
            return $v;
        }
        if (preg_match('/^DISTRICT\s*\d+$/i', $v)) {
            return strtoupper($v);
        }
        if (preg_match('/\bDISTRICT\b/i', $v) && strlen($v) <= 100) {
            return $v;
        }
    }

    return '';
}

function normalizeDistrictKey(string $name): string {
    $v = strtolower(trim($name));
    if ($v === '') {
        return '';
    }
    // Treat hyphens/underscores as separators, then collapse spacing.
    $v = str_replace(['-', '_', '/'], ' ', $v);
    $v = preg_replace('/[^a-z0-9\s]+/i', ' ', $v);
    // Treat trailing/standalone "district" variants as equivalent labels.
    $v = preg_replace('/\b(district|dist)\.?\b/i', ' ', $v);
    $v = preg_replace('/\s+/', ' ', trim((string)$v));
    return $v;
}

function normalizeDistrictLabel(string $name): string {
    $v = trim($name);
    if ($v === '') {
        return '';
    }
    $v = str_replace(['_', '/'], ' ', $v);
    $v = str_replace('-', ' ', $v);
    // Canonicalize labels like "Maria Aurora West District" to "Maria Aurora West".
    $v = preg_replace('/\s+(district|dist)\.?\s*$/i', '', $v);
    $v = preg_replace('/\s+/', ' ', trim((string)$v));
    return $v;
}

// ── Normalize header for flexible matching ───────────────────
function normalizeHdr(string $h): string {
    $h = preg_replace('/^\xEF\xBB\xBF/u', '', (string)$h);
    // Strip common Google Form prefixes like "1. " and trailing required marker "*"
    $h = preg_replace('/^\s*\d+\s*[\.)-]\s*/', '', $h);
    $h = trim($h, " \t\n\r\0\x0B*");
    // Normalize separators so label variants can map to one alias
    $h = str_replace(['_', '/', '\\', '|'], ' ', $h);
    $h = preg_replace('/\s+/', ' ', $h);
    return strtolower(trim($h));
}

// Compact key for forgiving comparisons, e.g. "Employee #" -> "employeenumber"
// once aliases are defined with equivalent compact tokens.
function compactHdr(string $h): string {
    return preg_replace('/[^a-z0-9]+/', '', normalizeHdr($h));
}

// Resolve a raw header to DB column using exact alias match first,
// then fallback to "contains" matching for long form question labels.
function resolveUploadDbColumn(string $rawHeader, array $headerAliases): ?string {
    $normalized = normalizeHdr($rawHeader);
    if ($normalized === '') {
        return null;
    }
    if (isset($headerAliases[$normalized])) {
        return $headerAliases[$normalized];
    }

    foreach ($headerAliases as $alias => $dbCol) {
        // Avoid overly short aliases causing accidental matches.
        if (strlen($alias) < 6) {
            continue;
        }
        if (str_contains($normalized, $alias) || str_contains($alias, $normalized)) {
            return $dbCol;
        }
    }

    // Final fallback: compare compact alphanumeric forms so headers like
    // "LastName", "FirstName", "Employee#" can still resolve.
    $compact = compactHdr($normalized);
    if ($compact !== '') {
        // Explicit shorthands commonly seen in spreadsheets.
        if (in_array($compact, ['employeenumber', 'employeeno', 'employeeid', 'employee#', 'employee'], true)) {
            return 'employee_number';
        }
        if (in_array($compact, ['lastname', 'surname', 'familyname'], true)) {
            return 'last_name';
        }
        if (in_array($compact, ['firstname', 'givenname'], true)) {
            return 'first_name';
        }

        foreach ($headerAliases as $alias => $dbCol) {
            if ($compact === compactHdr($alias)) {
                return $dbCol;
            }
        }
    }

    return null;
}

// Detect the most likely header row from the first few rows.
// This handles files that include title/notes rows before actual column headers.
function detectHeaderRowIndex(array $rawRows, array $headerAliases, array $requiredHeaders): int {
    $scanLimit = min(count($rawRows), 15);
    $bestIdx = 0;
    $bestScore = -1;
    $bestRequiredHits = -1;

    $requiredDbCols = [];
    foreach ($requiredHeaders as $req) {
        $dbCol = $headerAliases[normalizeHdr($req)] ?? null;
        if ($dbCol !== null) {
            $requiredDbCols[$dbCol] = true;
        }
    }

    for ($i = 0; $i < $scanLimit; $i++) {
        $row = $rawRows[$i] ?? [];
        $mapped = [];
        foreach ($row as $cell) {
            $dbCol = resolveUploadDbColumn((string)$cell, $headerAliases);
            if ($dbCol !== null) {
                $mapped[$dbCol] = true;
            }
        }

        $score = count($mapped);
        $requiredHits = 0;
        foreach ($requiredDbCols as $dbCol => $_) {
            if (isset($mapped[$dbCol])) {
                $requiredHits++;
            }
        }

        // Prefer rows with more required hits, then more total mapped columns.
        if ($requiredHits > $bestRequiredHits || ($requiredHits === $bestRequiredHits && $score > $bestScore)) {
            $bestRequiredHits = $requiredHits;
            $bestScore = $score;
            $bestIdx = $i;
        }
    }

    return $bestIdx;
}

// Build normalized alias lookup: normalized_header → db_col
$headerAliases = [];
foreach (UPLOAD_COLUMN_MAP as $displayHdr => $dbCol) {
    $key = normalizeHdr($displayHdr);
    if (!isset($headerAliases[$key])) {
        $headerAliases[$key] = $dbCol;
    }
}

// Header validation – flexible, case-insensitive matching
// Detect header row first (supports files with pre-header rows).
$headerIdx = detectHeaderRowIndex($rawRows, $headerAliases, REQUIRED_UPLOAD_HEADERS);
$headerRow = array_map('trim', $rawRows[$headerIdx] ?? []);
$headerRow = array_map(function($h) {
    return preg_replace('/^\xEF\xBB\xBF/u', '', (string)$h);
}, $headerRow);

// Map actual file headers → DB column (first occurrence only)
$colMap = [];
foreach ($headerRow as $idx => $rawHeader) {
    $dbCol = resolveUploadDbColumn($rawHeader, $headerAliases);
    if ($dbCol !== null) {
        if (!isset($colMap[$dbCol])) { // keep first occurrence
            $colMap[$dbCol] = $idx;
        }
    }
}

// Check required headers
$missing = [];
foreach (REQUIRED_UPLOAD_HEADERS as $req) {
    $reqNorm = normalizeHdr($req);
    $dbCol   = $headerAliases[$reqNorm] ?? null;
    if (!$dbCol || !isset($colMap[$dbCol])) {
        $missing[] = $req;
    }
}
if ($missing) {
    flash('error', 'Missing required columns: ' . implode(', ', $missing));
    redirect(APP_URL . '/teachers.php');
}

// Parse data rows
$skipDupes    = !empty($_POST['skip_duplicates']);
$updateExist  = !empty($_POST['update_existing']);

// Default behavior for re-upload is to update existing records unless
// user explicitly chooses skip-duplicates only.
if (!$skipDupes && !$updateExist) {
    $updateExist = true;
}

$dataRows     = array_slice($rawRows, $headerIdx + 1);
$totalRows    = count($dataRows);
$imported     = 0;
$skipped      = 0;
$errors       = 0;
$errorDetails = [];

$db = getDB();
ensureTeacherPlanningSchema($db);

// Best-effort support for "Undo Latest Upload". If table creation fails due
// to DB permissions, uploads still proceed without undo tracking.
$canTrackUndo = true;
try {
    $db->exec("CREATE TABLE IF NOT EXISTS upload_teacher_changes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        upload_log_id INT UNSIGNED NOT NULL,
        sequence_no INT UNSIGNED NOT NULL,
        teacher_id INT UNSIGNED DEFAULT NULL,
        employee_number VARCHAR(50) NOT NULL,
        action_type ENUM('insert','update') NOT NULL,
        previous_data JSON DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_upload_log (upload_log_id),
        INDEX idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    $canTrackUndo = false;
    error_log('TPMS Undo tracking table unavailable: ' . $e->getMessage());
}

// Preload school lookup: school_id_code (lower) → id
$schoolMap  = [];
$schoolNameMap = []; // lower school_name → id
$scRows = $db->query('SELECT id, school_id_code, school_name FROM schools')->fetchAll();
foreach ($scRows as $sc) {
    if ($sc['school_id_code'] !== null && $sc['school_id_code'] !== '') {
        $schoolMap[strtolower(trim($sc['school_id_code']))] = (int)$sc['id'];
    }
    $schoolNameMap[strtolower(trim($sc['school_name']))] = (int)$sc['id'];
}

// Preload districts (lower district_name → id) so uploads can auto-add new ones.
$districtMap = [];
try {
    $distRows = $db->query('SELECT id, district_name FROM districts')->fetchAll();
    foreach ($distRows as $dr) {
        $name = normalizeDistrictLabel((string)($dr['district_name'] ?? ''));
        $key = normalizeDistrictKey($name);
        if ($key !== '' && !isset($districtMap[$key])) {
            $districtMap[$key] = (int)$dr['id'];
        }
    }
} catch (Throwable $e) {
    // Keep upload working even if districts table is unavailable.
    error_log('TPMS District preload warning: ' . $e->getMessage());
}
$insertDistrictStmt = $db->prepare('INSERT INTO districts (district_name) VALUES (?)');
$findDistrictStmt = $db->prepare('SELECT id FROM districts WHERE district_name = ? LIMIT 1');

// All insertable columns
$insertCols = [
    'employee_number','last_name','first_name','middle_name','extension_name',
    'house_street','barangay','municipality','province',
    'birthdate','gender','civil_status','pwd_status','contact_number','email_address',
    'position','item_number','salary_grade','appointment_type','original_appointment_date',
    'school_id','school_id_code_raw','school_name_raw','district_raw','plantilla_station','current_station','grade_level','specialization','subjects','highest_education',
    'max_teaching_load_hours','current_teaching_load_hours','classes_handled','max_classes','advisory_class',
    'field_of_study','csee_eligibility','data_privacy_consent'
];

// Keep only columns that actually exist in the current teachers table schema
$teacherCols = [];
foreach ($db->query('SHOW COLUMNS FROM teachers')->fetchAll() as $colMeta) {
    $teacherCols[] = $colMeta['Field'];
}
$insertCols = array_values(array_filter($insertCols, fn($c) => in_array($c, $teacherCols, true)));

if (!in_array('employee_number', $insertCols, true)
    || !in_array('last_name', $insertCols, true)
    || !in_array('first_name', $insertCols, true)) {
    $failUpload('Upload failed: teachers table is missing required columns.');
}

$hasCreatedBy = in_array('created_by', $teacherCols, true);
$hasUpdatedAt = in_array('updated_at', $teacherCols, true);

$insertColsSql = $insertCols;
if ($hasCreatedBy) {
    $insertColsSql[] = 'created_by';
}
$insertSql = 'INSERT INTO teachers (' . implode(', ', $insertColsSql) . ') VALUES ('
           . implode(', ', array_fill(0, count($insertColsSql), '?')) . ')';

$updateSetParts = array_map(fn($c) => "$c = ?", array_filter($insertCols, fn($c) => $c !== 'employee_number'));
if ($hasUpdatedAt) {
    $updateSetParts[] = 'updated_at = NOW()';
}
$updateSql = 'UPDATE teachers SET ' . implode(', ', $updateSetParts) . ' WHERE employee_number = ?';

$insertStmt = $db->prepare($insertSql);
$updateStmt = $db->prepare($updateSql);
$checkStmt  = $db->prepare('SELECT * FROM teachers WHERE employee_number = ? LIMIT 1');

$changeEvents = [];
$changeSeq = 0;

$db->beginTransaction();
try {
    foreach ($dataRows as $rowNum => $row) {
        $lineNo = $headerIdx + $rowNum + 2; // +1 zero index, +1 converts to 1-based line
        // Extract raw value from column map
        $get = function(string $dbCol) use ($row, $colMap): string {
            return isset($colMap[$dbCol]) ? trim($row[$colMap[$dbCol]] ?? '') : '';
        };

        $empNo = $get('employee_number');
        // Normalize hidden spaces from Excel/CSV so re-upload matches existing records.
        $empNo = preg_replace('/[\x{00A0}\x{200B}\s]+/u', '', $empNo);
        if ($empNo === '') { $errors++; $errorDetails[] = "Row {$lineNo}: Missing employee number."; continue; }

        // Required per-row values for teachers table constraints
        $lastName  = $get('last_name');
        $firstName = $get('first_name');
        if ($lastName === '' || $firstName === '') {
            $errors++;
            $errorDetails[] = "Row {$lineNo}: Missing required name fields (Last Name / First Name).";
            continue;
        }

        // ── Resolve school_id ────────────────────────────────
        $schoolId   = null;
        $codeRaw    = $get('school_id_code_upload');
        $nameRaw    = $get('school_name_upload');
        $districtRaw = $get('district_upload');
        $plantillaRaw = $get('plantilla_station');
        $currentRaw   = $get('current_station');

        if ($districtRaw === '') {
            $districtRaw = inferDistrictName($currentRaw, $plantillaRaw, $nameRaw);
        }

        // Auto-create district if upload provides a new one.
        if ($districtRaw !== '') {
            $districtRaw = normalizeDistrictLabel($districtRaw);
            $districtKey = normalizeDistrictKey($districtRaw);
            if ($districtKey !== '' && !isset($districtMap[$districtKey])) {
                try {
                    $insertDistrictStmt->execute([$districtRaw]);
                    $newDistrictId = (int)$db->lastInsertId();
                    if ($newDistrictId > 0) {
                        $districtMap[$districtKey] = $newDistrictId;
                    }
                } catch (Throwable $distEx) {
                    // Likely unique collision or collation variant: resolve by lookup.
                    try {
                        $findDistrictStmt->execute([$districtRaw]);
                        $existingDistrictId = (int)$findDistrictStmt->fetchColumn();
                        if ($existingDistrictId > 0) {
                            $districtMap[$districtKey] = $existingDistrictId;
                        }
                    } catch (Throwable $lookupEx) {
                        error_log('TPMS District auto-add warning (row ' . $lineNo . '): ' . $lookupEx->getMessage());
                    }
                }
            }
        }

        // Fallbacks: if School Name is not supplied, try station fields for matching.
        if ($nameRaw === '' && $plantillaRaw !== '') {
            $nameRaw = $plantillaRaw;
        }
        if ($nameRaw === '' && $currentRaw !== '') {
            $nameRaw = $currentRaw;
        }

        if ($codeRaw !== '') {
            $extracted = extractSchoolCode($codeRaw);
            if ($extracted !== '') {
                $schoolId = $schoolMap[strtolower($extracted)] ?? null;
            }
            // If still null, try treating the whole value as a name
            if ($schoolId === null) {
                $schoolId = $schoolNameMap[strtolower($codeRaw)] ?? null;
            }
        }
        if ($schoolId === null && $nameRaw !== '') {
            $schoolId = $schoolNameMap[strtolower($nameRaw)] ?? null;
            // Partial match fallback
            if ($schoolId === null) {
                foreach ($schoolNameMap as $sName => $sId) {
                    if (str_contains($sName, strtolower($nameRaw)) || str_contains(strtolower($nameRaw), $sName)) {
                        $schoolId = $sId;
                        break;
                    }
                }
            }
        }

        // ── Parse dates ──────────────────────────────────────
        $dob      = parseUploadDate($get('birthdate'));
        $apptDate = parseUploadDate($get('original_appointment_date'));

        // ── Normalize special fields ─────────────────────────
        $pwd     = normalizePwd($get('pwd_status'));
        $consent = normalizeConsent($get('data_privacy_consent'));
        $gender  = normalizeGender($get('gender'));

        $checkStmt->execute([$empNo]);
        $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            if ($skipDupes && !$updateExist) { $skipped++; continue; }
            if ($updateExist) {
                try {
                    $beforeData = [];
                    foreach ($insertCols as $c) {
                        $beforeData[$c] = $exists[$c] ?? null;
                    }

                    $updateVals = [];
                    foreach (array_filter($insertCols, fn($c) => $c !== 'employee_number') as $c) {
                        $updateVals[] = match ($c) {
                            'school_id'                 => $schoolId,
                            'school_id_code_raw'        => $codeRaw !== '' ? $codeRaw : null,
                            'school_name_raw'           => $nameRaw !== '' ? $nameRaw : null,
                            'district_raw'              => $districtRaw !== '' ? $districtRaw : null,
                            'birthdate'                 => $dob ?: null,
                            'original_appointment_date' => $apptDate ?: null,
                            'gender'                    => $gender,
                            'pwd_status'                => $pwd,
                            'data_privacy_consent'      => $consent,
                            'max_teaching_load_hours'   => ($raw = $get('max_teaching_load_hours')) !== '' && is_numeric($raw) ? (float)$raw : null,
                            'current_teaching_load_hours' => ($raw = $get('current_teaching_load_hours')) !== '' && is_numeric($raw) ? (float)$raw : null,
                            'classes_handled'           => ($raw = $get('classes_handled')) !== '' && is_numeric($raw) ? max(0, (int)$raw) : null,
                            'max_classes'               => ($raw = $get('max_classes')) !== '' && is_numeric($raw) ? max(0, (int)$raw) : null,
                            default                     => ($raw = $get($c)) !== '' ? $raw : null,
                        };
                    }
                    $updateVals[] = $empNo;
                    $updateStmt->execute($updateVals);

                    if ($canTrackUndo) {
                        $changeSeq++;
                        $changeEvents[] = [
                            'sequence_no' => $changeSeq,
                            'teacher_id' => (int)($exists['id'] ?? 0),
                            'employee_number' => $empNo,
                            'action_type' => 'update',
                            'previous_data' => json_encode($beforeData, JSON_UNESCAPED_UNICODE),
                        ];
                    }

                    $imported++;
                } catch (Exception $rowEx) {
                    $errors++;
                    $errorDetails[] = "Row {$lineNo}: " . $rowEx->getMessage();
                }
                continue;
            }

            // Existing record and no duplicate handling option selected
            $skipped++;
            $errorDetails[] = "Row {$lineNo}: Employee number {$empNo} already exists (enable Skip Duplicates or Update Existing).";
            continue;
        }

        // ── Build INSERT values ───────────────────────────────
        try {
            $insertVals = [];
            foreach ($insertCols as $c) {
                $insertVals[] = match ($c) {
                    'employee_number'           => $empNo,
                    'last_name'                 => $lastName,
                    'first_name'                => $firstName,
                    'school_id'                 => $schoolId,
                    'school_id_code_raw'        => $codeRaw !== '' ? $codeRaw : null,
                    'school_name_raw'           => $nameRaw !== '' ? $nameRaw : null,
                    'district_raw'              => $districtRaw !== '' ? $districtRaw : null,
                    'birthdate'                 => $dob ?: null,
                    'original_appointment_date' => $apptDate ?: null,
                    'gender'                    => $gender,
                    'pwd_status'                => $pwd,
                    'data_privacy_consent'      => $consent,
                    'max_teaching_load_hours'   => ($raw = $get('max_teaching_load_hours')) !== '' && is_numeric($raw) ? (float)$raw : null,
                    'current_teaching_load_hours' => ($raw = $get('current_teaching_load_hours')) !== '' && is_numeric($raw) ? (float)$raw : null,
                    'classes_handled'           => ($raw = $get('classes_handled')) !== '' && is_numeric($raw) ? max(0, (int)$raw) : null,
                    'max_classes'               => ($raw = $get('max_classes')) !== '' && is_numeric($raw) ? max(0, (int)$raw) : null,
                    default                     => ($raw = $get($c)) !== '' ? $raw : null,
                };
            }
            if ($hasCreatedBy) {
                $insertVals[] = currentUser()['id'];
            }
            $insertStmt->execute($insertVals);

            if ($canTrackUndo) {
                $changeSeq++;
                $changeEvents[] = [
                    'sequence_no' => $changeSeq,
                    'teacher_id' => (int)$db->lastInsertId(),
                    'employee_number' => $empNo,
                    'action_type' => 'insert',
                    'previous_data' => null,
                ];
            }

            $imported++;
        } catch (Exception $rowEx) {
            $errors++;
            $errorDetails[] = "Row {$lineNo}: " . $rowEx->getMessage();
            continue;
        }
    }
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log('TPMS Upload Error: ' . $e->getMessage());
    flash('error', 'Upload failed during database save: ' . $e->getMessage());
    redirect(APP_URL . '/teachers.php');
}

// Log upload
$db->prepare(
    'INSERT INTO upload_logs (file_name, total_rows, imported_rows, skipped_rows, error_rows, uploaded_by)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([
    basename($file['name']),
    $totalRows,
    $imported,
    $skipped,
    $errors,
    currentUser()['id']
]);

$uploadLogId = (int)$db->lastInsertId();

if ($canTrackUndo && $uploadLogId > 0 && $changeEvents) {
    try {
        $chgStmt = $db->prepare(
            'INSERT INTO upload_teacher_changes (upload_log_id, sequence_no, teacher_id, employee_number, action_type, previous_data)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($changeEvents as $ev) {
            $chgStmt->execute([
                $uploadLogId,
                $ev['sequence_no'],
                $ev['teacher_id'] ?: null,
                $ev['employee_number'],
                $ev['action_type'],
                $ev['previous_data'],
            ]);
        }
    } catch (Throwable $e) {
        error_log('TPMS Undo tracking save failed: ' . $e->getMessage());
    }
}

logActivity('UPLOAD', 'teachers', null,
    "Bulk upload: {$file['name']} – $imported imported, $skipped skipped, $errors errors.");

// Store error details in session for display
if ($errorDetails) {
    $_SESSION['upload_error_report'] = [
        'module'     => 'teachers',
        'file_name'  => basename($file['name']),
        'total_rows' => $totalRows,
        'imported'   => $imported,
        'skipped'    => $skipped,
        'errors'     => $errors,
        'details'    => $errorDetails,
    ];
    $_SESSION['upload_errors'] = $errorDetails;
}

flash('success',
    "Upload complete: $imported imported, $skipped skipped, $errors errors.");
redirect(APP_URL . '/teachers.php');
