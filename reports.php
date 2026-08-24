<?php
$pageTitle = 'Reports';
require_once __DIR__ . '/includes/header.php';

// Require user to have selected a role
requireRoleSelection();

$db = getDB();

// ── Filter State ─────────────────────────────────────────────
$search       = clean(trim($_GET['q']      ?? ''));
$filterDist   = trim($_GET['dist']   ?? '');
$filterSchoolRaw = trim((string)($_GET['school'] ?? ''));
$filterSchool = 0;

// Input length validation
if (strlen($search) > 500 || strlen($filterDist) > 255) {
    flash('error', 'Filter parameters are too long.');
    redirect(APP_URL . '/reports');
}
$filterSchoolParam = '';
if ($filterSchoolRaw !== '') {
    if (ctype_digit($filterSchoolRaw)) {
        $filterSchool = (int)$filterSchoolRaw;
    } else {
        $decodedSchool = decryptId($filterSchoolRaw);
        if ($decodedSchool !== false) {
            $filterSchool = (int)$decodedSchool;
        } else {
            logActivity('DENY', 'reports', null, 'Blocked invalid school filter in reports URL.');
            flash('error', 'Invalid school filter.');
            redirect(APP_URL . '/reports.php');
        }
    }

    if ($filterSchool > 0) {
        if (shouldFilterByDistrict()) {
            // For PSDS/SDC users, verify school belongs to their district
            $userDistrictId = (int)getSessionDistrict();
            $schoolCheck = $db->prepare('SELECT id FROM schools WHERE id = ? AND district_id = ? LIMIT 1');
            $schoolCheck->execute([$filterSchool, $userDistrictId]);
        } else {
            // For admins/hr, just verify school exists
            $schoolCheck = $db->prepare('SELECT id FROM schools WHERE id = ? LIMIT 1');
            $schoolCheck->execute([$filterSchool]);
        }
        if (!$schoolCheck->fetchColumn()) {
            logActivity('DENY', 'reports', null, 'Blocked non-existent or unauthorized school filter in reports URL.');
            flash('error', 'School filter is invalid.');
            redirect(APP_URL . '/reports.php');
        }
        $filterSchoolParam = encryptId($filterSchool);
    }
}
$filterPos    = trim($_GET['pos']    ?? '');
$filterGender = trim($_GET['gen']    ?? '');
$filterSpec   = trim($_GET['spec']   ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));

$allowedGenderFilters = ['', 'Male', 'Female', 'Not Set'];
if (!in_array($filterGender, $allowedGenderFilters, true)) {
    $filterGender = '';
}

$genderExpr = "CASE
    WHEN LOWER(TRIM(COALESCE(t.gender, ''))) IN ('male', 'm') THEN 'Male'
    WHEN LOWER(TRIM(COALESCE(t.gender, ''))) IN ('female', 'f') THEN 'Female'
    ELSE 'Not Set'
END";

$where  = ['1=1'];
$params = [];

// Auto-filter by district for PSDS, SDC, Unit Head users
if (shouldFilterByDistrict()) {
    $userDistrictId = (int)getSessionDistrict();
    if ($userDistrictId > 0) {
        $where[] = 's.district_id = ?';
        $params[] = $userDistrictId;
    }
}

if ($search !== '') {
    $where[] = '(t.employee_number LIKE ? OR t.last_name LIKE ? OR t.first_name LIKE ? OR t.middle_name LIKE ? OR t.position LIKE ? OR t.specialization LIKE ? OR s.school_name LIKE ? OR COALESCE(NULLIF(t.district_raw, ""), d.district_name) LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like]);
}
if ($filterDist && !shouldFilterByDistrict())   { $where[] = 'COALESCE(NULLIF(t.district_raw, ""), d.district_name) = ?'; $params[] = $filterDist; }
if ($filterSchool) { $where[] = 't.school_id = ?';   $params[] = $filterSchool; }
if ($filterPos)    {
    $where[] = 'REPLACE(LOWER(COALESCE(t.position, "")), " ", "") LIKE REPLACE(LOWER(?), " ", "")';
    $params[] = '%' . $filterPos . '%';
}
if ($filterGender) { $where[] = "$genderExpr = ?";  $params[] = $filterGender; }
if ($filterSpec)   { $where[] = 't.specialization LIKE ?'; $params[] = '%'.$filterSpec.'%'; }

$whereStr = implode(' AND ', $where);
$baseSQL  = "FROM teachers t
             LEFT JOIN schools s ON t.school_id = s.id
             LEFT JOIN districts d ON s.district_id = d.id
             WHERE $whereStr";

$totalStmt = $db->prepare("SELECT COUNT(*) $baseSQL");
$totalStmt->execute($params);
$total  = (int)$totalStmt->fetchColumn();
$pag    = paginate($total, $page);

$data = $db->prepare(
    "SELECT t.employee_number, t.last_name, t.first_name, t.middle_name,
            t.gender, t.birthdate, t.position, t.appointment_type,
            t.grade_level, t.specialization, t.highest_education,
            t.csee_eligibility, t.data_privacy_consent,
            t.house_street, t.barangay, t.municipality, t.province,
            s.school_name, COALESCE(NULLIF(t.district_raw, ''), d.district_name) AS district
     $baseSQL ORDER BY t.last_name, t.first_name LIMIT ? OFFSET ?"
);
$data->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
$rows = $data->fetchAll();

// Summary stats for this filter set
$sumGender = $db->prepare(
    "SELECT $genderExpr AS gender, COUNT(*) cnt $baseSQL GROUP BY gender ORDER BY cnt DESC"
);
$sumGender->execute($params);
$genderSummary = $sumGender->fetchAll();

$sumPos = $db->prepare(
    "SELECT t.position, COUNT(*) cnt $baseSQL GROUP BY t.position ORDER BY cnt DESC LIMIT 5"
);
$sumPos->execute($params);
$posSummary = $sumPos->fetchAll();

// Filter options
if (shouldFilterByDistrict()) {
    // For PSDS/SDC users, only show their assigned district
    $userDistrictId = (int)getSessionDistrict();
    $districts = $db->prepare(
        'SELECT district_name
         FROM districts
         WHERE id = ?
         ORDER BY district_name'
    );
    $districts->execute([$userDistrictId]);
    $districts = $districts->fetchAll(PDO::FETCH_COLUMN);
    
    // Only show schools from their assigned district
    $schools = $db->prepare('SELECT id, school_name FROM schools WHERE district_id = ? ORDER BY school_name');
    $schools->execute([$userDistrictId]);
    $schools = $schools->fetchAll();
} else {
    // For admins/hr, show all districts and schools
    $districts = $db->query(
        'SELECT district_name
         FROM (
            SELECT DISTINCT d.district_name AS district_name
            FROM schools s
            INNER JOIN districts d ON s.district_id = d.id
            UNION
            SELECT DISTINCT NULLIF(TRIM(t.district_raw), "") AS district_name
            FROM teachers t
            WHERE t.district_raw IS NOT NULL AND TRIM(t.district_raw) <> ""
         ) x
         WHERE district_name IS NOT NULL AND district_name <> ""
         ORDER BY district_name'
    )->fetchAll(PDO::FETCH_COLUMN);
    $schools = $db->query('SELECT id, school_name FROM schools ORDER BY school_name')->fetchAll();
}
$positions = $db->query('SELECT DISTINCT position FROM teachers WHERE position IS NOT NULL AND position != "" ORDER BY position')->fetchAll(PDO::FETCH_COLUMN);

// Build export query string
$exportParams = http_build_query(array_filter([
    'q'      => $search,
    'dist'   => $filterDist,
    'school' => $filterSchoolParam !== '' ? $filterSchoolParam : null,
    'pos'    => $filterPos,
    'gen'    => $filterGender,
    'spec'   => $filterSpec,
]));
$exportQuerySuffix = $exportParams !== '' ? '&' . $exportParams : '';

$schoolHeadExportParams = http_build_query(array_filter([
    'q'      => $search,
    'dist'   => $filterDist,
    'school' => $filterSchoolParam !== '' ? $filterSchoolParam : null,
]));
$schoolHeadExportQuerySuffix = $schoolHeadExportParams !== '' ? '&' . $schoolHeadExportParams : '';

$buildReportUrl = function(array $overrides = []) use ($search, $filterDist, $filterSchoolParam, $filterPos, $filterGender, $filterSpec) {
    $state = [
        'q'      => $search,
        'dist'   => $filterDist,
        'school' => $filterSchoolParam !== '' ? $filterSchoolParam : null,
        'pos'    => $filterPos,
        'gen'    => $filterGender,
        'spec'   => $filterSpec,
        'page'   => null,
    ];
    foreach ($overrides as $k => $v) {
        $state[$k] = $v;
    }
    $query = http_build_query(array_filter($state, static fn($v) => $v !== null && $v !== ''));
    return APP_URL . '/reports.php' . ($query !== '' ? '?' . $query : '');
};
?>

<!-- ── Filters ─────────────────────────────────────────────── -->
<div class="reports-toolbar glass-card">
    <form method="GET" class="reports-filter-form" id="reportFilter">
        <div class="reports-filter-grid">
            <div class="reports-field reports-field-wide">
                <label class="form-label">Search</label>
                <div class="search-box">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" name="q" class="form-input" placeholder="Search employee, name, school, district..." value="<?= clean($search) ?>">
                </div>
            </div>

            <div class="reports-field">
                <label class="form-label">District</label>
                <select name="dist" class="form-select" <?= shouldFilterByDistrict() ? 'disabled' : '' ?>>
                    <?php if (!shouldFilterByDistrict()): ?>
                    <option value="">All Districts</option>
                    <?php endif; ?>
                    <?php foreach ($districts as $d): ?>
                    <option value="<?= clean($d) ?>" <?= $filterDist === $d ? 'selected' : '' ?>><?= clean($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="reports-field">
                <label class="form-label">School</label>
                <select name="school" class="form-select">
                    <option value="">All Schools</option>
                    <?php foreach ($schools as $sc): ?>
                    <option value="<?= urlencode(encryptId((int)$sc['id'])) ?>" <?= $filterSchool === (int)$sc['id'] ? 'selected' : '' ?>><?= clean($sc['school_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="reports-field">
                <label class="form-label">Position</label>
                <input type="text" name="pos" class="form-input" list="reportPositionOptions" placeholder="e.g. Master Teacher" value="<?= clean($filterPos) ?>">
                <datalist id="reportPositionOptions">
                    <?php foreach ($positions as $p): ?>
                    <option value="<?= clean($p) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="reports-field">
                <label class="form-label">Gender</label>
                <select name="gen" class="form-select">
                    <option value="">All Genders</option>
                    <option value="Male"   <?= $filterGender === 'Male'   ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= $filterGender === 'Female' ? 'selected' : '' ?>>Female</option>
                    <option value="Not Set" <?= $filterGender === 'Not Set' ? 'selected' : '' ?>>Not Set</option>
                </select>
            </div>

            <div class="reports-field">
                <label class="form-label">Specialization</label>
                <input type="text" name="spec" class="form-input" placeholder="e.g. Mathematics" value="<?= clean($filterSpec) ?>">
            </div>
        </div>

        <div class="reports-filter-actions-row">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sliders-h"></i> Apply Filters</button>
            <?php if ($search || $filterDist || $filterSchool || $filterPos || $filterGender || $filterSpec): ?>
            <a href="<?= APP_URL ?>/reports.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Clear Filters</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="reports-export-panel">
        <div class="reports-export-title"><i class="fas fa-file-export"></i> Export and Extraction</div>
        <div class="reports-export-grid">
            <a href="<?= APP_URL ?>/actions/export.php?format=csv<?= $exportQuerySuffix ?>" class="btn btn-ghost btn-sm reports-export-btn">
                <i class="fas fa-file-csv"></i> Export Teacher CSV
            </a>
            <a href="<?= APP_URL ?>/actions/export.php?format=excel<?= $exportQuerySuffix ?>" class="btn btn-ghost btn-sm reports-export-btn">
                <i class="fas fa-file-excel"></i> Export Teacher Excel
            </a>
            <a href="<?= APP_URL ?>/actions/export_school_heads.php?format=csv<?= $schoolHeadExportQuerySuffix ?>" class="btn btn-ghost btn-sm reports-export-btn reports-export-btn-heads">
                <i class="fas fa-user-tie"></i> Tagged School Heads CSV
            </a>
            <a href="<?= APP_URL ?>/actions/export_school_heads.php?format=excel<?= $schoolHeadExportQuerySuffix ?>" class="btn btn-ghost btn-sm reports-export-btn reports-export-btn-heads">
                <i class="fas fa-user-tie"></i> Tagged School Heads Excel
            </a>
        </div>
    </div>
</div>

<style>
.reports-toolbar {
    display: grid;
    gap: 12px;
    padding: 12px;
}
.reports-filter-form {
    display: grid;
    gap: 10px;
}
.reports-filter-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(180px, 1fr));
    gap: 10px;
}
.reports-field {
    display: grid;
    gap: 6px;
}
.reports-field-wide {
    grid-column: span 3;
}
.reports-filter-actions-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.reports-export-panel {
    border: 1px solid rgba(148,163,184,.2);
    border-radius: 12px;
    background: linear-gradient(160deg, rgba(30,41,59,.45), rgba(15,23,42,.3));
    padding: 10px;
    display: grid;
    gap: 8px;
}
.reports-export-title {
    font-weight: 600;
    color: #e2e8f0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.reports-export-title i {
    color: #38bdf8;
}
.reports-export-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 8px;
}
.reports-export-btn {
    justify-content: flex-start;
}
.reports-export-btn-heads {
    border-color: rgba(8,145,178,.45);
    background: rgba(8,145,178,.08);
}
.report-stat-link { display: block; text-decoration: none; color: inherit; }
.report-stat-link .stat-card { height: 100%; }
.report-stat-link.is-active .stat-card { border-color: rgba(56, 189, 248, .5); box-shadow: 0 0 0 1px rgba(56, 189, 248, .25) inset; }
@media (max-width: 900px) {
    .reports-filter-grid { grid-template-columns: 1fr; }
    .reports-field-wide { grid-column: span 1; }
}
</style>

<!-- ── Summary Mini Stats ──────────────────────────────────── -->
<div class="stats-grid" style="--cols:4">
    <a href="<?= clean($buildReportUrl(['gen' => null])) ?>" class="report-stat-link <?= $filterGender === '' ? 'is-active' : '' ?>">
        <div class="stat-card glass-card">
            <div class="stat-icon icon-blue"><i class="fas fa-users"></i></div>
            <div class="stat-body"><div class="stat-value"><?= number_format($total) ?></div><div class="stat-label">Filtered Total</div></div>
        </div>
    </a>
    <?php foreach ($genderSummary as $g): ?>
    <a href="<?= clean($buildReportUrl(['gen' => (string)$g['gender']])) ?>" class="report-stat-link <?= $filterGender === (string)$g['gender'] ? 'is-active' : '' ?>">
        <div class="stat-card glass-card">
            <div class="stat-icon <?= strtolower((string)$g['gender']) === 'female' ? 'icon-purple' : (strtolower((string)$g['gender']) === 'male' ? 'icon-cyan' : 'icon-orange') ?>">
                <i class="fas fa-<?= strtolower((string)$g['gender']) === 'female' ? 'venus' : (strtolower((string)$g['gender']) === 'male' ? 'mars' : 'user-slash') ?>"></i>
            </div>
            <div class="stat-body"><div class="stat-value"><?= number_format((int)$g['cnt']) ?></div><div class="stat-label"><?= clean($g['gender']) ?></div></div>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<!-- ── Data Table ──────────────────────────────────────────── -->
<div class="table-card glass-card">
    <div class="card-header">
        <h3><i class="fas fa-table"></i> Teacher Report</h3>
        <span class="text-muted small"><?= number_format($total) ?> records</span>
    </div>
    <div class="table-scroll">
        <table class="data-table" id="reportTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Employee No.</th>
                    <th>Last Name</th>
                    <th>First Name</th>
                    <th>Gender</th>
                    <th>Age</th>
                    <th>Position</th>
                    <th>Appt. Type</th>
                    <th>School</th>
                    <th>District</th>
                    <th>Address</th>
                    <th>Specialization</th>
                    <th>Education</th>
                    <th>Privacy</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td><?= $pag['offset'] + $i + 1 ?></td>
                <td><?= clean($r['employee_number'] ?? '—') ?></td>
                <td><?= clean($r['last_name']) ?></td>
                <td><?= clean($r['first_name']) ?> <?= clean($r['middle_name'] ?? '') ?></td>
                <td><?= clean($r['gender'] ?? '—') ?></td>
                <td><?= calcAge($r['birthdate'] ?? null) ?? '—' ?></td>
                <td><?= clean($r['position'] ?? '—') ?></td>
                <td><?= clean($r['appointment_type'] ?? '—') ?></td>
                <td><?= clean($r['school_name'] ?? '—') ?></td>
                <td><?= clean($r['district'] ?? '—') ?></td>
                <td>
                    <?php
                    $addrParts = array_filter([
                        $r['house_street'] ?? '',
                        $r['barangay'] ?? '',
                        $r['municipality'] ?? '',
                        $r['province'] ?? '',
                    ]);
                    echo clean($addrParts ? implode(', ', $addrParts) : '—');
                    ?>
                </td>
                <td><?= clean($r['specialization'] ?? '—') ?></td>
                <td><?= clean($r['highest_education'] ?? '—') ?></td>
                <td class="text-center">
                    <?php if (strtolower($r['data_privacy_consent'] ?? '') === 'yes'): ?>
                    <i class="fas fa-check-circle" style="color:#10b981" title="Consent given"></i>
                    <?php else: ?>
                    <i class="fas fa-minus-circle" style="color:#64748b" title="No consent"></i>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
            <tr><td colspan="14" class="text-center text-muted">No records match the selected filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= paginationLinks($pag, APP_URL . '/' . basename($_SERVER['PHP_SELF']) . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
