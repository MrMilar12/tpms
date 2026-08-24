<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();
requireLogin();

if (!canEdit()) {
    flash('error', 'Access denied. Export is restricted to Admin/HR accounts.');
    redirect(APP_URL . '/retirement_watch.php');
}

$db = getDB();

$format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
if (!in_array($format, ['csv', 'excel'], true)) {
    $format = 'csv';
}

$search = trim((string)($_GET['q'] ?? ''));
$filterDist = trim((string)($_GET['dist'] ?? ''));
$filterStatus = strtolower(trim((string)($_GET['status'] ?? 'all')));
$filterSchoolRaw = trim((string)($_GET['school'] ?? ''));

if (strlen($search) > 500 || strlen($filterDist) > 255) {
    flash('error', 'Filter parameters are too long.');
    redirect(APP_URL . '/retirement_watch.php');
}

$allowedStatus = ['all', 'due12', 'past60', 'to60'];
if (!in_array($filterStatus, $allowedStatus, true)) {
    $filterStatus = 'all';
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
        $st = $db->prepare('SELECT id FROM schools WHERE id = ? LIMIT 1');
        $st->execute([$schoolId]);
        if (!$st->fetchColumn()) {
            return [0, '__INVALID__'];
        }
    }
    return [$schoolId, $schoolId > 0 ? encryptId($schoolId) : ''];
};

[$filterSchool, $filterSchoolParam] = $resolveSchoolFilter($filterSchoolRaw, $db);
if ($filterSchoolParam === '__INVALID__') {
    flash('error', 'Invalid school filter.');
    redirect(APP_URL . '/retirement_watch.php');
}

$retryQuery = http_build_query(array_filter([
    'format' => $format,
    'q' => $search,
    'dist' => $filterDist,
    'school' => $filterSchoolParam !== '' ? $filterSchoolParam : null,
    'status' => $filterStatus !== 'all' ? $filterStatus : null,
], static fn($v) => $v !== ''));
$retryUrl = APP_URL . '/actions/export_retirement_watch.php' . ($retryQuery !== '' ? ('?' . $retryQuery) : '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $flash = getFlash();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Confirm Retirement Export</title>
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/fonts/inter/inter.css">
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    </head>
    <body>
    <div class="main-wrapper" style="margin:0 auto;max-width:700px;padding:28px 14px;">
        <div class="glass-card" style="padding:20px;display:grid;gap:14px;">
            <h2 style="margin:0;"><i class="fas fa-lock"></i> Confirm Retirement Extraction</h2>
            <p class="text-muted" style="margin:0;">For data security, please re-enter your account password before downloading this retirement watch report.</p>

            <?php if ($flash): ?>
            <div class="alert alert-<?= clean((string)$flash['type']) ?>" style="margin:0;">
                <?= clean((string)$flash['msg']) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="<?= APP_URL ?>/actions/export_retirement_watch.php" style="display:grid;gap:10px;max-width:420px;">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="format" value="<?= clean($format) ?>">
                <input type="hidden" name="q" value="<?= clean($search) ?>">
                <input type="hidden" name="dist" value="<?= clean($filterDist) ?>">
                <input type="hidden" name="school" value="<?= clean($filterSchoolParam) ?>">
                <input type="hidden" name="status" value="<?= clean($filterStatus) ?>">

                <label class="form-label required" for="confirm_password">Password</label>
                <input id="confirm_password" type="password" name="confirm_password" class="form-input" autocomplete="current-password" required>

                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-download"></i> Confirm & Download</button>
                    <a href="<?= APP_URL ?>/retirement_watch.php" class="btn btn-ghost">Cancel</a>
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
$filterStatus = strtolower(trim((string)($_POST['status'] ?? $filterStatus)));
if (!in_array($filterStatus, $allowedStatus, true)) {
    $filterStatus = 'all';
}
[$filterSchool, $filterSchoolParam] = $resolveSchoolFilter((string)($_POST['school'] ?? $filterSchoolParam), $db);
if ($filterSchoolParam === '__INVALID__') {
    flash('error', 'Invalid school filter.');
    redirect(APP_URL . '/retirement_watch.php');
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

$where = [
    "t.birthdate IS NOT NULL",
    "t.birthdate <> '0000-00-00'",
    "TIMESTAMPDIFF(YEAR, t.birthdate, CURDATE()) BETWEEN 59 AND 60",
];
$params = [];

if ($search !== '') {
    $where[] = '(t.employee_number LIKE ? OR t.last_name LIKE ? OR t.first_name LIKE ? OR t.position LIKE ? OR COALESCE(s.school_name, t.school_name_raw) LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($filterDist !== '') {
    $where[] = 'COALESCE(NULLIF(t.district_raw, ""), d.district_name) = ?';
    $params[] = $filterDist;
}
if ($filterSchool > 0) {
    $where[] = 't.school_id = ?';
    $params[] = $filterSchool;
}
if ($filterStatus === 'due12') {
    $where[] = 'TIMESTAMPDIFF(MONTH, CURDATE(), DATE_ADD(t.birthdate, INTERVAL 60 YEAR)) BETWEEN 0 AND 12';
} elseif ($filterStatus === 'past60') {
    $where[] = 'TIMESTAMPDIFF(MONTH, CURDATE(), DATE_ADD(t.birthdate, INTERVAL 60 YEAR)) < 0';
} elseif ($filterStatus === 'to60') {
    $where[] = 'TIMESTAMPDIFF(MONTH, CURDATE(), DATE_ADD(t.birthdate, INTERVAL 60 YEAR)) >= 0';
}

$whereSql = implode(' AND ', $where);

$sql = "SELECT
            t.employee_number,
            t.last_name,
            t.first_name,
            t.birthdate,
            t.position,
            COALESCE(s.school_name, t.school_name_raw, 'Unassigned') AS school_name,
            COALESCE(NULLIF(t.district_raw, ''), d.district_name, 'N/A') AS district,
            TIMESTAMPDIFF(YEAR, t.birthdate, CURDATE()) AS age_years,
            TIMESTAMPDIFF(MONTH, CURDATE(), DATE_ADD(t.birthdate, INTERVAL 60 YEAR)) AS months_until_60
        FROM teachers t
        LEFT JOIN schools s ON t.school_id = s.id
        LEFT JOIN districts d ON s.district_id = d.id
        WHERE $whereSql
        ORDER BY months_until_60 ASC, t.last_name ASC, t.first_name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$formatMonths = static function(int $months): string {
    if ($months === 0) return 'Turns 60 this month';
    $abs = abs($months);
    $years = intdiv($abs, 12);
    $rem = $abs % 12;
    $parts = [];
    if ($years > 0) $parts[] = $years . ' year' . ($years !== 1 ? 's' : '');
    if ($rem > 0 || $years === 0) $parts[] = $rem . ' month' . ($rem !== 1 ? 's' : '');
    $txt = implode(' ', $parts);
    return $months > 0 ? ($txt . ' to age 60') : ('Past 60 by ' . $txt);
};

logActivity(
    'EXPORT',
    'reports',
    null,
    'Downloaded ' . strtoupper($format) . ' retirement watch extraction with filters: '
    . json_encode([
        'q' => $search,
        'dist' => $filterDist,
        'school' => $filterSchool,
        'status' => $filterStatus,
        'rows' => count($rows),
    ], JSON_UNESCAPED_UNICODE)
);

$headers = ['Employee No.', 'Last Name', 'First Name', 'School', 'District', 'Position', 'Birthdate', 'Age', 'Projection to 60'];

$filename = 'TPMS_Retirement_Watch_' . date('Ymd_His');

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
        echo '<td>' . htmlspecialchars((string)($r['employee_number'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string)($r['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string)($r['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string)($r['school_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string)($r['district'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string)($r['position'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string)($r['birthdate'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string)($r['age_years'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($formatMonths((int)($r['months_until_60'] ?? 0)), ENT_QUOTES, 'UTF-8') . '</td>';
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
        fputcsv($out, [
            $r['employee_number'] ?? '',
            $r['last_name'] ?? '',
            $r['first_name'] ?? '',
            $r['school_name'] ?? '',
            $r['district'] ?? '',
            $r['position'] ?? '',
            $r['birthdate'] ?? '',
            $r['age_years'] ?? '',
            $formatMonths((int)($r['months_until_60'] ?? 0)),
        ]);
    }
    fclose($out);
}

exit;
