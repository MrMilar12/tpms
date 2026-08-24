<?php
// ============================================================
// export.php – Export filtered teacher data as CSV or Excel
// ============================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
startSecureSession();
requireLogin();

if (!canEdit()) {
    flash('error', 'Access denied. Export is restricted to Admin/HR accounts.');
    redirect(APP_URL . '/reports.php');
}

$format = strtolower(trim($_GET['format'] ?? 'csv'));
if (!in_array($format, ['csv', 'excel'], true)) $format = 'csv';

$db = getDB();

$resolveSchoolFilter = static function(string $raw, PDO $db): array {
    $raw = trim($raw);
    if ($raw === '') {
        return [0, ''];
    }

    if (ctype_digit($raw)) {
        $schoolId = (int)$raw;
    } else {
        $decoded = decryptId($raw);
        if ($decoded === false) {
            return [0, '__INVALID__'];
        }
        $schoolId = (int)$decoded;
    }

    if ($schoolId > 0) {
        $schoolCheck = $db->prepare('SELECT id FROM schools WHERE id = ? LIMIT 1');
        $schoolCheck->execute([$schoolId]);
        if (!$schoolCheck->fetchColumn()) {
            return [0, '__INVALID__'];
        }
    }

    return [$schoolId, $schoolId > 0 ? encryptId($schoolId) : ''];
};

$search       = trim($_GET['q']      ?? '');
$filterDist   = trim($_GET['dist']   ?? '');
[$filterSchool, $filterSchoolParam] = $resolveSchoolFilter((string)($_GET['school'] ?? ''), $db);
if ($filterSchoolParam === '__INVALID__') {
    logActivity('DENY', 'reports', null, 'Blocked invalid school filter in export URL.');
    flash('error', 'Invalid school filter.');
    redirect(APP_URL . '/reports.php');
}
$filterPos    = trim($_GET['pos']    ?? '');
$filterGender = trim($_GET['gen']    ?? '');
$filterSpec   = trim($_GET['spec']   ?? '');
$filterGrade  = trim($_GET['grade']  ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $queryParams = [
        'format' => $format,
        'q' => $search,
        'dist' => $filterDist,
        'school' => $filterSchoolParam,
        'pos' => $filterPos,
        'gen' => $filterGender,
        'spec' => $filterSpec,
        'grade' => $filterGrade,
    ];
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Confirm Export Password</title>
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/fonts/inter/inter.css">
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    </head>
    <body>
    <div class="main-wrapper" style="margin:0 auto;max-width:700px;padding:28px 14px;">
        <div class="glass-card" style="padding:20px;display:grid;gap:14px;">
            <h2 style="margin:0;"><i class="fas fa-lock"></i> Confirm Export</h2>
            <p class="text-muted" style="margin:0;">For data security, please re-enter your account password before downloading this report.</p>

            <form method="POST" action="<?= APP_URL ?>/actions/export.php" style="display:grid;gap:10px;max-width:420px;">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <?php foreach ($queryParams as $k => $v): ?>
                <input type="hidden" name="<?= clean($k) ?>" value="<?= clean($v) ?>">
                <?php endforeach; ?>

                <label class="form-label required" for="confirm_password">Password</label>
                <input id="confirm_password" type="password" name="confirm_password" class="form-input" autocomplete="current-password" required>

                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-download"></i> Confirm & Download</button>
                    <a href="<?= APP_URL ?>/reports.php" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

verifyCsrf();

$format = strtolower(trim($_POST['format'] ?? $format));
if (!in_array($format, ['csv', 'excel'], true)) $format = 'csv';

$search       = trim($_POST['q']      ?? $search);
$filterDist   = trim($_POST['dist']   ?? $filterDist);
[$filterSchool, $filterSchoolParam] = $resolveSchoolFilter((string)($_POST['school'] ?? ($filterSchoolParam ?? '')), $db);
if ($filterSchoolParam === '__INVALID__') {
    logActivity('DENY', 'reports', null, 'Blocked invalid school filter in export request.');
    flash('error', 'Invalid school filter.');
    redirect(APP_URL . '/reports.php');
}
$filterPos    = trim($_POST['pos']    ?? $filterPos);
$filterGender = trim($_POST['gen']    ?? $filterGender);
$filterSpec   = trim($_POST['spec']   ?? $filterSpec);
$filterGrade  = trim($_POST['grade']  ?? $filterGrade);

$confirmPassword = (string)($_POST['confirm_password'] ?? '');
if ($confirmPassword === '') {
    flash('error', 'Password confirmation is required before export.');
    redirect(APP_URL . '/reports.php');
}

$me = (int)(currentUser()['id'] ?? 0);
$pwStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$pwStmt->execute([$me]);
$passwordHash = (string)$pwStmt->fetchColumn();
if ($passwordHash === '' || !password_verify($confirmPassword, $passwordHash)) {
    flash('error', 'Invalid password. Export was not performed.');
    redirect(APP_URL . '/reports.php');
}

$where  = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(t.employee_number LIKE ? OR t.last_name LIKE ? OR t.first_name LIKE ? OR t.middle_name LIKE ? OR t.position LIKE ? OR t.specialization LIKE ? OR s.school_name LIKE ? OR COALESCE(NULLIF(t.district_raw, ""), d.district_name) LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like]);
}
if ($filterDist)   { $where[] = 'COALESCE(NULLIF(t.district_raw, ""), d.district_name) = ?'; $params[] = $filterDist; }
if ($filterSchool) { $where[] = 't.school_id = ?';   $params[] = $filterSchool; }
if ($filterPos)    {
    $where[] = 'REPLACE(LOWER(COALESCE(t.position, "")), " ", "") LIKE REPLACE(LOWER(?), " ", "")';
    $params[] = '%' . $filterPos . '%';
}
if ($filterGender) { $where[] = 't.gender = ?';      $params[] = $filterGender; }
if ($filterSpec)   { $where[] = 't.specialization LIKE ?'; $params[] = '%'.$filterSpec.'%'; }
if ($filterGrade)  { $where[] = 't.grade_level LIKE ?'; $params[] = '%'.$filterGrade.'%'; }

$whereStr = implode(' AND ', $where);

$stmt = $db->prepare(
    "SELECT t.employee_number, t.last_name, t.first_name, t.middle_name, t.extension_name,
            t.birthdate, t.gender, t.civil_status, t.pwd_status,
            t.contact_number, t.email_address,
            t.position, t.item_number, t.salary_grade, t.appointment_type,
            CASE
                WHEN t.original_appointment_date IS NULL THEN NULL
                ELSE CONCAT(
                    DATE_FORMAT(t.original_appointment_date, '%Y-%m-%d'),
                    ' (',
                    TIMESTAMPDIFF(YEAR, t.original_appointment_date, COALESCE(t.updated_at, CURDATE())),
                    ' yrs as of ',
                    DATE_FORMAT(COALESCE(t.updated_at, CURDATE()), '%Y-%m-%d'),
                    ')'
                )
            END AS original_appointment_date,
            t.plantilla_station, t.current_station,
            t.grade_level, t.specialization, t.subjects,
            t.highest_education, t.field_of_study, t.csee_eligibility,
            CONCAT_WS(', ',
                NULLIF(TRIM(COALESCE(t.house_street, '')), ''),
                NULLIF(TRIM(COALESCE(t.barangay, '')), ''),
                NULLIF(TRIM(COALESCE(t.municipality, '')), ''),
                NULLIF(TRIM(COALESCE(t.province, '')), '')
            ) AS address,
            t.data_privacy_consent,
                 COALESCE(s.school_name, t.school_name_raw) AS school_name,
                 COALESCE(s.school_id_code, t.school_id_code_raw) AS school_id_code,
                 COALESCE(d.district_name, t.district_raw) AS district
     FROM teachers t
     LEFT JOIN schools s ON t.school_id = s.id
     LEFT JOIN districts d ON s.district_id = d.id
     WHERE $whereStr ORDER BY t.last_name, t.first_name"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

logActivity(
    'EXPORT',
    'reports',
    null,
    'Downloaded ' . strtoupper($format) . ' report with filters: '
    . json_encode([
        'q' => $search,
        'dist' => $filterDist,
        'school' => $filterSchool,
        'pos' => $filterPos,
        'gen' => $filterGender,
        'spec' => $filterSpec,
        'grade' => $filterGrade,
        'rows' => count($rows),
    ], JSON_UNESCAPED_UNICODE)
);

$headers = [
    'School ID Code','Employee Number','Last Name','First Name','Middle Name','Extension Name',
    'Date of Birth','Gender','Civil Status','PWD Status',
    'Contact Number','Email Address',
    'Position','Item Number','Salary Grade','Appointment Type','Original Appointment Date',
    'School Name','Plantilla Station','Current Station','District','Address','Grade Level','Current Teaching','Specialization',
    'Highest Education','Field of Study / Course','CSEE / Eligibility',
    'Data Privacy Consent'
];

$colKeys = [
    'school_id_code','employee_number','last_name','first_name','middle_name','extension_name',
    'birthdate','gender','civil_status','pwd_status',
    'contact_number','email_address',
    'position','item_number','salary_grade','appointment_type','original_appointment_date',
    'school_name','plantilla_station','current_station','district','address','grade_level','subjects','specialization',
    'highest_education','field_of_study','csee_eligibility',
    'data_privacy_consent'
];

$filename = 'TPMS_Export_' . date('Ymd_His');

if ($format === 'excel') {
    // Simple XML-based Excel (no library)
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8"><style>th{background:#1e40af;color:#fff;padding:6px;font-weight:bold}td{padding:4px;border:1px solid #ccc}</style></head><body><table>';
    echo '<tr>';
    foreach ($headers as $h) echo '<th>' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</th>';
    echo '</tr>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($colKeys as $k) echo '<td>' . htmlspecialchars($r[$k] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
    }
    echo '</table></body></html>';
} else {
    // CSV
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: max-age=0');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        $line = [];
        foreach ($colKeys as $k) $line[] = $r[$k] ?? '';
        fputcsv($out, $line);
    }
    fclose($out);
}
exit;
