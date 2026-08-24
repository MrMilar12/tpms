<?php
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

$format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
if (!in_array($format, ['csv', 'excel'], true)) {
    $format = 'csv';
}

$db = getDB();

$search = trim((string)($_GET['q'] ?? ''));
$filterDist = trim((string)($_GET['dist'] ?? ''));
$filterSchoolRaw = trim((string)($_GET['school'] ?? ''));

if (strlen($search) > 500 || strlen($filterDist) > 255) {
    flash('error', 'Filter parameters are too long.');
    redirect(APP_URL . '/reports.php');
}

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

[$filterSchool, $filterSchoolParam] = $resolveSchoolFilter($filterSchoolRaw, $db);
if ($filterSchoolParam === '__INVALID__') {
    flash('error', 'Invalid school filter.');
    redirect(APP_URL . '/reports.php');
}

$retryQuery = http_build_query(array_filter([
    'format' => $format,
    'q' => $search,
    'dist' => $filterDist,
    'school' => $filterSchoolParam !== '' ? $filterSchoolParam : null,
], static fn($v) => $v !== ''));
$retryUrl = APP_URL . '/actions/export_school_heads.php' . ($retryQuery !== '' ? ('?' . $retryQuery) : '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $flash = getFlash();
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
            <h2 style="margin:0;"><i class="fas fa-lock"></i> Confirm School Head Export</h2>
            <p class="text-muted" style="margin:0;">For data security, please re-enter your account password before downloading this tagged school head report.</p>

            <?php if ($flash): ?>
            <div class="alert alert-<?= clean((string)$flash['type']) ?>" style="margin:0;">
                <?= clean((string)$flash['msg']) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="<?= APP_URL ?>/actions/export_school_heads.php" style="display:grid;gap:10px;max-width:420px;">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="format" value="<?= clean($format) ?>">
                <input type="hidden" name="q" value="<?= clean($search) ?>">
                <input type="hidden" name="dist" value="<?= clean($filterDist) ?>">
                <input type="hidden" name="school" value="<?= clean($filterSchoolParam) ?>">

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

$format = strtolower(trim((string)($_POST['format'] ?? $format)));
if (!in_array($format, ['csv', 'excel'], true)) {
    $format = 'csv';
}

$search = trim((string)($_POST['q'] ?? $search));
$filterDist = trim((string)($_POST['dist'] ?? $filterDist));
[$filterSchool, $filterSchoolParam] = $resolveSchoolFilter((string)($_POST['school'] ?? $filterSchoolParam), $db);
if ($filterSchoolParam === '__INVALID__') {
    flash('error', 'Invalid school filter.');
    redirect(APP_URL . '/reports.php');
}

$confirmPassword = (string)($_POST['confirm_password'] ?? '');
if ($confirmPassword === '') {
    flash('error', 'Password confirmation is required before export.');
    redirect($retryUrl);
}

$me = (int)(currentUser()['id'] ?? 0);
$pwStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$pwStmt->execute([$me]);
$passwordHash = (string)$pwStmt->fetchColumn();
if ($passwordHash === '' || !password_verify($confirmPassword, $passwordHash)) {
    flash('error', 'Invalid password. Export was not performed.');
    redirect($retryUrl);
}

$conditions = ['s.school_head_teacher_id IS NOT NULL'];
$params = [];

if ($search !== '') {
    $conditions[] = '(s.school_name LIKE ? OR s.school_id_code LIKE ? OR d.district_name LIKE ? OR CONCAT_WS(" ", sh.first_name, sh.last_name) LIKE ? OR sh.employee_number LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($filterDist !== '') {
    $conditions[] = 'd.district_name = ?';
    $params[] = $filterDist;
}
if ($filterSchool > 0) {
    $conditions[] = 's.id = ?';
    $params[] = $filterSchool;
}

$whereSql = ' WHERE ' . implode(' AND ', $conditions);

$sql = 'SELECT
            s.school_name,
            COALESCE(s.school_id_code, "") AS school_id_code,
            COALESCE(d.district_name, "") AS district,
            COALESCE(s.municipality, "") AS municipality,
            COALESCE(s.school_type, "") AS school_type,
            COALESCE(s.als_subtype, "") AS als_subtype,
            COALESCE(s.school_year, "") AS school_year,
            COALESCE(s.learner_count, 0) AS learners,
            s.school_head_teacher_id,
            COALESCE(sh.employee_number, "") AS school_head_employee_number,
            TRIM(CONCAT_WS(" ", COALESCE(sh.first_name, ""), COALESCE(sh.last_name, ""))) AS school_head_name,
            COALESCE(sh.position, "") AS school_head_position,
            COALESCE(sh.contact_number, "") AS school_head_contact_number,
            COALESCE(sh.email_address, "") AS school_head_email,
            CASE WHEN sh.id IS NULL THEN "Missing Teacher Record" ELSE "Tagged" END AS tagging_status
        FROM schools s
        LEFT JOIN districts d ON s.district_id = d.id
        LEFT JOIN teachers sh ON sh.id = s.school_head_teacher_id
        ' . $whereSql . '
        ORDER BY d.district_name, s.school_name';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

logActivity(
    'EXPORT',
    'reports',
    null,
    'Downloaded ' . strtoupper($format) . ' tagged school head extraction with filters: '
    . json_encode([
        'q' => $search,
        'dist' => $filterDist,
        'school' => $filterSchool,
        'rows' => count($rows),
    ], JSON_UNESCAPED_UNICODE)
);

$headers = [
    'School Name',
    'School ID Code',
    'District',
    'Municipality',
    'School Type',
    'ALS Subtype',
    'School Year',
    'Learners',
    'School Head Teacher ID',
    'School Head Employee Number',
    'School Head Name',
    'School Head Position',
    'School Head Contact Number',
    'School Head Email',
    'Tagging Status',
];

$colKeys = [
    'school_name',
    'school_id_code',
    'district',
    'municipality',
    'school_type',
    'als_subtype',
    'school_year',
    'learners',
    'school_head_teacher_id',
    'school_head_employee_number',
    'school_head_name',
    'school_head_position',
    'school_head_contact_number',
    'school_head_email',
    'tagging_status',
];

$filename = 'TPMS_Tagged_School_Heads_' . date('Ymd_His');

if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8"><style>th{background:#1e40af;color:#fff;padding:6px;font-weight:bold}td{padding:4px;border:1px solid #ccc}</style></head><body><table>';
    echo '<tr>';
    foreach ($headers as $h) {
        echo '<th>' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($colKeys as $k) {
            echo '<td>' . htmlspecialchars((string)($r[$k] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></body></html>';
} else {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: max-age=0');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        $line = [];
        foreach ($colKeys as $k) {
            $line[] = $r[$k] ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
}

exit;
