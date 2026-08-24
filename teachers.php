<?php
$pageTitle = 'Teachers';
require_once __DIR__ . '/includes/header.php';

// Require user to have selected a role
requireRoleSelection();

$db = getDB();

// ── Filters ─────────────────────────────────────────────────
$search       = clean(trim($_GET['q']    ?? ''));
$filterDist   = trim($_GET['dist'] ?? '');
$filterPos    = trim($_GET['pos']  ?? '');
$filterSpec   = trim($_GET['spec'] ?? '');
$filterGender = trim($_GET['gen']  ?? '');
$filterGrade  = trim($_GET['grade'] ?? '');
$filterPwd    = strtolower(trim((string)($_GET['pwd'] ?? '')));
$filterRetire = strtolower(trim((string)($_GET['retire'] ?? '')));
$filterData   = strtolower(trim((string)($_GET['data'] ?? '')));
$filterSchoolRaw = trim((string)($_GET['school'] ?? ''));
$filterSchool = 0;

// Input length validation
if (strlen($search) > 500 || strlen($filterDist) > 255 || strlen($filterPos) > 255) {
    flash('error', 'Filter parameters are too long.');
    redirect(APP_URL . '/teachers.php');
}

$allowedRetireFilters = ['', 'possible'];
if (!in_array($filterRetire, $allowedRetireFilters, true)) {
    $filterRetire = '';
}

$allowedPwdFilters = ['', 'yes'];
if (!in_array($filterPwd, $allowedPwdFilters, true)) {
    $filterPwd = '';
}

$allowedDataFilters = ['', 'needs_update', 'birthdate_fix'];
if (!in_array($filterData, $allowedDataFilters, true)) {
    $filterData = '';
}

$missingDataSqlClause = "(
    TRIM(COALESCE(t.employee_number, '')) = ''
    OR TRIM(COALESCE(t.last_name, '')) = ''
    OR TRIM(COALESCE(t.first_name, '')) = ''
    OR t.birthdate IS NULL
    OR t.birthdate = '0000-00-00'
    OR TRIM(COALESCE(t.gender, '')) = ''
    OR TRIM(COALESCE(t.civil_status, '')) = ''
    OR TRIM(COALESCE(t.pwd_status, '')) = ''
    OR TRIM(COALESCE(t.contact_number, '')) = ''
    OR TRIM(COALESCE(t.email_address, '')) = ''
    OR TRIM(COALESCE(t.barangay, '')) = ''
    OR TRIM(COALESCE(t.municipality, '')) = ''
    OR TRIM(COALESCE(t.province, '')) = ''
    OR TRIM(COALESCE(t.position, '')) = ''
    OR TRIM(COALESCE(t.item_number, '')) = ''
    OR TRIM(COALESCE(t.salary_grade, '')) = ''
    OR TRIM(COALESCE(t.appointment_type, '')) = ''
    OR t.original_appointment_date IS NULL
    OR t.original_appointment_date = '0000-00-00'
    OR (COALESCE(t.school_id, 0) = 0 AND TRIM(COALESCE(t.school_name_raw, '')) = '')
    OR TRIM(COALESCE(NULLIF(t.district_raw, ''), d.district_name, '')) = ''
    OR TRIM(COALESCE(t.grade_level, '')) = ''
    OR TRIM(COALESCE(t.specialization, '')) = ''
    OR TRIM(COALESCE(t.subjects, '')) = ''
    OR TRIM(COALESCE(t.highest_education, '')) = ''
    OR TRIM(COALESCE(t.csee_eligibility, '')) = ''
    OR TRIM(COALESCE(t.data_privacy_consent, '')) = ''
    OR YEAR(t.birthdate) < 1900
    OR YEAR(t.birthdate) > YEAR(CURDATE())
)";

if ($filterSchoolRaw !== '') {
    // Accept legacy numeric school IDs and encrypted IDs.
    if (ctype_digit($filterSchoolRaw)) {
        $filterSchool = (int)$filterSchoolRaw;
    } else {
        $decryptedSchool = decryptId($filterSchoolRaw);
        if ($decryptedSchool !== false) {
            $filterSchool = (int)$decryptedSchool;
        } else {
            logActivity('DENY', 'teachers', null, 'Blocked invalid school filter parameter in URL.');
            flash('error', 'Invalid school filter parameter.');
            redirect(APP_URL . '/teachers.php');
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
            logActivity('DENY', 'teachers', null, 'Blocked non-existent or unauthorized school filter ID in URL.');
            flash('error', 'Requested school filter is not valid.');
            redirect(APP_URL . '/teachers.php');
        }
    }
}

$page         = max(1, (int)($_GET['page'] ?? 1));
$schoolCtxQuery = $filterSchool > 0 ? '&school=' . urlencode(encryptId($filterSchool)) : '';

$where  = ['1=1'];
$params = [];

// Add district filter for non-admin users
if (shouldFilterByDistrict()) {
    $selectedDistrict = getSessionDistrict();
    if ($selectedDistrict !== null) {
        $where[] = 't.school_id IN (SELECT id FROM schools WHERE district_id = ?)';
        $params[] = $selectedDistrict;
    }
}

if ($search !== '') {
    $where[]  = '(t.first_name LIKE ? OR t.last_name LIKE ? OR t.employee_number LIKE ? OR t.specialization LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if ($filterDist !== '') {
    $where[]  = 'COALESCE(NULLIF(t.district_raw, ""), d.district_name) = ?';
    $params[] = $filterDist;
}
if ($filterPos !== '') {
    $where[]  = 't.position LIKE ?';
    $params[] = '%' . $filterPos . '%';
}
if ($filterSpec !== '') {
    $where[]  = 't.specialization LIKE ?';
    $params[] = '%' . $filterSpec . '%';
}
if ($filterGender !== '') {
    if ($filterGender === 'Not Set') {
        $where[] = "(t.gender IS NULL OR TRIM(t.gender) = '' OR LOWER(TRIM(t.gender)) NOT IN ('male','female','m','f'))";
    } elseif ($filterGender === 'Male') {
        $where[] = "LOWER(TRIM(COALESCE(t.gender, ''))) IN ('male','m')";
    } elseif ($filterGender === 'Female') {
        $where[] = "LOWER(TRIM(COALESCE(t.gender, ''))) IN ('female','f')";
    } else {
        $where[]  = 't.gender = ?';
        $params[] = $filterGender;
    }
}
if ($filterSchool > 0) {
    $where[]  = 't.school_id = ?';
    $params[] = $filterSchool;
}
if ($filterGrade !== '') {
    $where[]  = 't.grade_level LIKE ?';
    $params[] = '%' . $filterGrade . '%';
}
if ($filterPwd === 'yes') {
    $where[] = "LOWER(TRIM(COALESCE(t.pwd_status, ''))) IN ('yes','pwd','1','true')";
}
if ($filterRetire === 'possible') {
    $where[] = "t.birthdate IS NOT NULL AND t.birthdate <> '0000-00-00' AND TIMESTAMPDIFF(YEAR, t.birthdate, CURDATE()) BETWEEN 59 AND 60";
}
if ($filterData === 'needs_update') {
    $where[] = $missingDataSqlClause;
} elseif ($filterData === 'birthdate_fix') {
    $where[] = "t.birthdate IS NOT NULL AND t.birthdate <> '0000-00-00' AND YEAR(t.birthdate) BETWEEN 1 AND 1899";
}

$whereStr = implode(' AND ', $where);
$baseSQL  = "FROM teachers t
             LEFT JOIN schools s ON t.school_id = s.id
             LEFT JOIN districts d ON s.district_id = d.id
             WHERE $whereStr";

// Total count
$totalStmt = $db->prepare("SELECT COUNT(*) $baseSQL");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

$pag    = paginate($total, $page);
$offset = $pag['offset'];
$limit  = $pag['per_page'];

$schoolCols = [];
foreach ($db->query('SHOW COLUMNS FROM schools')->fetchAll() as $colMeta) {
    $schoolCols[] = $colMeta['Field'];
}
$hasSchoolHeadTeacherId = in_array('school_head_teacher_id', $schoolCols, true);

$schoolHeadSelectSQL = $hasSchoolHeadTeacherId
    ? ",
        (SELECT COUNT(*) FROM schools s_head WHERE s_head.school_head_teacher_id = t.id) AS school_head_count,
        (SELECT GROUP_CONCAT(s_head.school_name ORDER BY s_head.school_name SEPARATOR ', ')
         FROM schools s_head
         WHERE s_head.school_head_teacher_id = t.id) AS school_head_schools"
    : ", 0 AS school_head_count, NULL AS school_head_schools";

$dataStmt = $db->prepare(
    "SELECT t.*, s.school_name, COALESCE(NULLIF(t.district_raw, ''), d.district_name) AS district$schoolHeadSelectSQL $baseSQL
     ORDER BY t.last_name, t.first_name
     LIMIT ? OFFSET ?"
);
$dataStmt->execute(array_merge($params, [$limit, $offset]));
$teachers = $dataStmt->fetchAll();

// Filter dropdowns
// Use full districts lookup so newly auto-added districts appear immediately
// even before any school is linked to them.
if (shouldFilterByDistrict()) {
    // For PSDS/SDC users, only show their assigned district
    $userDistrictId = (int)getSessionDistrict();
    $districts = $db->prepare(
        'SELECT district_name FROM districts WHERE id = ? ORDER BY district_name'
    );
    $districts->execute([$userDistrictId]);
    $districts = $districts->fetchAll(PDO::FETCH_COLUMN);
} else {
    // For admins/hr, show all districts
    $districts = $db->query(
        'SELECT district_name FROM districts ORDER BY district_name'
    )->fetchAll(PDO::FETCH_COLUMN);
}

// Filter schools by user's selected district for PSDS/SDC/Unit Head roles
if (shouldFilterByDistrict()) {
    $selectedDistrict = getSessionDistrict();
    if ($selectedDistrict !== null) {
        $schoolsStmt = $db->prepare('SELECT id, school_name FROM schools WHERE district_id = ? ORDER BY school_name');
        $schoolsStmt->execute([$selectedDistrict]);
        $schools = $schoolsStmt->fetchAll();
    } else {
        $schools = [];
    }
} else {
    $schools = $db->query('SELECT id, school_name FROM schools ORDER BY school_name')->fetchAll();
}

$selectedSchoolName = '';
if ($filterSchool > 0) {
    foreach ($schools as $sc) {
        if ((int)$sc['id'] === $filterSchool) {
            $selectedSchoolName = (string)$sc['school_name'];
            break;
        }
    }
}

$buildTeachersUrl = static function(array $overrides = []) use ($search, $filterDist, $filterPos, $filterSpec, $filterGender, $filterGrade, $filterPwd, $filterRetire, $filterSchool, $filterData): string {
    $query = [];
    if ($search !== '') {
        $query['q'] = $search;
    }
    if ($filterDist !== '') {
        $query['dist'] = $filterDist;
    }
    if ($filterPos !== '') {
        $query['pos'] = $filterPos;
    }
    if ($filterSpec !== '') {
        $query['spec'] = $filterSpec;
    }
    if ($filterGender !== '') {
        $query['gen'] = $filterGender;
    }
    if ($filterGrade !== '') {
        $query['grade'] = $filterGrade;
    }
    if ($filterPwd !== '') {
        $query['pwd'] = $filterPwd;
    }
    if ($filterRetire !== '') {
        $query['retire'] = $filterRetire;
    }
    if ($filterSchool > 0) {
        $query['school'] = encryptId($filterSchool);
    }
    if ($filterData !== '') {
        $query['data'] = $filterData;
    }

    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
        } else {
            $query[$k] = $v;
        }
    }

    return APP_URL . '/teachers.php' . ($query ? '?' . http_build_query($query) : '');
};

$uploadErrorReport = $_SESSION['upload_error_report'] ?? null;
if (is_array($uploadErrorReport) && ($uploadErrorReport['module'] ?? '') !== 'teachers') {
    $uploadErrorReport = null;
}
if ($uploadErrorReport) {
    unset($_SESSION['upload_error_report']);
    unset($_SESSION['upload_errors']);
}

$undoCandidates = [];
if (canEdit()) {
    try {
        $undoStmt = $db->prepare(
            'SELECT ul.id, ul.file_name, ul.created_at, ul.imported_rows
             FROM upload_logs ul
             INNER JOIN (
                SELECT DISTINCT upload_log_id FROM upload_teacher_changes
             ) uc ON uc.upload_log_id = ul.id
             WHERE ul.uploaded_by = ?
             ORDER BY ul.id DESC
             LIMIT 20'
        );
        $undoStmt->execute([(int)(currentUser()['id'] ?? 0)]);
        $undoCandidates = $undoStmt->fetchAll();
    } catch (Throwable $e) {
        // If undo tracking tables are unavailable, keep UI functional without selector.
        $undoCandidates = [];
    }
}

// Calculate grade level distribution from all teachers
$gradeLevelStats = [];
$totalKindergartenCount = 0; // Total count of ALL kindergarten variations
$gradeLevelRaw = $db->query('SELECT grade_level FROM teachers WHERE grade_level IS NOT NULL AND grade_level != ""')->fetchAll(PDO::FETCH_COLUMN);

// Parse comma-separated grade levels and count each one
foreach ($gradeLevelRaw as $levelString) {
    $levels = array_map('trim', explode(',', $levelString));
    foreach ($levels as $level) {
        if (!empty($level)) {
            $gradeLevelStats[$level] = ($gradeLevelStats[$level] ?? 0) + 1;
            
            // Count all kindergarten variations: Kindergarten, KINDERGARTEN, Kinder, KINDER, Kindergarten Program, etc.
            $levelLower = strtolower($level);
            if (strpos($levelLower, 'kinder') === 0 || strpos($levelLower, 'kinder') !== false) {
                $totalKindergartenCount++;
            }
        }
    }
}

// Sort by count descending
arsort($gradeLevelStats);

?>

<style>
.teachers-toolbar-shell {
    padding: 14px;
}

.teachers-toolbar-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
}

.teachers-toolbar-copy h2 {
    margin: 0;
    font-size: 1rem;
}

.teachers-toolbar-copy p {
    margin: 4px 0 0;
    color: var(--text-muted);
    font-size: 0.88rem;
}

.teachers-toolbar-copy h2,
.teachers-toolbar-copy p {
    max-width: 60ch;
}

.teachers-toolbar-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 7px 10px;
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.24);
    background: rgba(255, 255, 255, 0.06);
    color: var(--text-muted);
    font-size: 12px;
    white-space: nowrap;
}

.teachers-toolbar-body {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
    align-items: start;
}

.teachers-toolbar-section {
    min-width: 0;
}

.teachers-section-label {
    display: block;
    margin-bottom: 8px;
    color: var(--text-muted);
    font-size: 11px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.teachers-filter-panel .teachers-filter-row {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.teachers-filter-panel .form-input,
.teachers-filter-panel .form-select,
.teachers-export-form .form-select {
    min-height: 40px;
    border-radius: 10px;
}

.teachers-actions-stack {
    display: grid;
    gap: 10px;
}

.teachers-actions-grid {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}

.teachers-export-form {
    flex: 0 0 180px;
}

.teachers-export-form .form-select {
    width: 100%;
}

.teachers-primary-actions {
    flex: 1 1 0;
    justify-content: flex-start;
}

.teachers-view-toggle {
    width: fit-content;
    margin-left: 0;
}

.teachers-data-filters {
    padding-top: 8px;
    margin-top: 2px;
    border-top: 1px solid rgba(148, 163, 184, 0.12);
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

@media (max-width: 1100px) {
    .teachers-actions-grid {
        flex-direction: column;
        align-items: stretch;
    }

    .teachers-export-form {
        flex: 1 1 auto;
    }
}

@media (max-width: 780px) {
    .teachers-toolbar-head {
        flex-direction: column;
        align-items: flex-start;
    }

    .teachers-filter-panel .teachers-filter-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .teachers-toolbar-shell {
        padding: 10px;
    }

    .teachers-filter-panel .teachers-filter-row {
        grid-template-columns: 1fr;
    }

    .teachers-primary-actions,
    .teachers-view-toggle,
    .teachers-primary-actions .btn,
    .teachers-view-toggle .btn {
        width: 100%;
    }

    .teachers-primary-actions {
        flex-direction: column;
    }

    .teachers-export-form {
        width: 100%;
    }
}
</style>

<!-- ── Filters Bar ─────────────────────────────────────────── -->
<div class="filter-bar glass-card teachers-actionbar teachers-toolbar-shell">
    <div class="teachers-toolbar-head">
        <div class="teachers-toolbar-copy">
            <h2>Teachers</h2>
            <p>Search and filter records, then use tools only when needed.</p>
        </div>
        <div class="teachers-toolbar-badge">
            <i class="fas fa-users"></i>
            <?= number_format($total) ?> teachers
        </div>
    </div>

    <div class="teachers-toolbar-body">
        <div class="teachers-toolbar-section teachers-filter-panel">
            <span class="teachers-section-label">Search & Filters</span>
            <form method="GET" action="" id="filterForm" class="filter-form teachers-filter-form">
                <div class="filter-group teachers-filter-item teachers-search-wrap">
                    <div class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" name="q" class="form-input" placeholder="Search name, employee no., specialization..."
                               value="<?= clean($search) ?>" id="searchInput">
                    </div>
                </div>
                <div class="teachers-filter-row">
                    <div class="filter-group teachers-filter-item" style="display: none;">
                        <select name="dist" class="form-select" onchange="this.form.submit()">
                            <option value="">All Districts</option>
                            <?php foreach ($districts as $d): ?>
                            <option value="<?= clean($d) ?>" <?= $filterDist === $d ? 'selected' : '' ?>><?= clean($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group teachers-filter-item">
                        <select name="gen" class="form-select" onchange="this.form.submit()">
                            <option value="">All Genders</option>
                            <option value="Male"   <?= $filterGender === 'Male'   ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= $filterGender === 'Female' ? 'selected' : '' ?>>Female</option>
                            <option value="Not Set" <?= $filterGender === 'Not Set' ? 'selected' : '' ?>>No Gender</option>
                        </select>
                    </div>
                    <div class="filter-group teachers-filter-item">
                        <select name="school" class="form-select" onchange="this.form.submit()">
                            <option value="">All Schools</option>
                            <?php foreach ($schools as $sc): ?>
                            <option value="<?= urlencode(encryptId((int)$sc['id'])) ?>" <?= $filterSchool === (int)$sc['id'] ? 'selected' : '' ?>><?= clean($sc['school_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group teachers-filter-item">
                        <input type="text" name="pos" class="form-input" placeholder="Position..."
                               value="<?= clean($filterPos) ?>" onchange="this.form.submit()">
                    </div>
                    <div class="filter-group teachers-filter-item">
                        <input type="text" name="grade" class="form-input" placeholder="Grade level..."
                               value="<?= clean($filterGrade) ?>" onchange="this.form.submit()">
                    </div>
                </div>
            </form>
        </div>

        <?php if (canEdit()): ?>
        <div class="teachers-toolbar-section teachers-actions-panel">
            <span class="teachers-section-label">Tools</span>
            <div class="teachers-actions-stack">
                <div class="teachers-actions-grid">
                    <form id="exportTeachersForm" method="GET" action="<?= APP_URL ?>/actions/export.php" class="teachers-export-form">
                        <input type="hidden" name="format" id="exportFormat" value="csv">
                        <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="dist" value="<?= htmlspecialchars($filterDist, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="pos" value="<?= htmlspecialchars($filterPos, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="spec" value="<?= htmlspecialchars($filterSpec, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="gen" value="<?= htmlspecialchars($filterGender, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="grade" value="<?= htmlspecialchars($filterGrade, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="pwd" value="<?= htmlspecialchars($filterPwd, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="retire" value="<?= htmlspecialchars($filterRetire, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="data" value="<?= htmlspecialchars($filterData, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="school" value="<?= $filterSchool > 0 ? htmlspecialchars(urlencode(encryptId($filterSchool)), ENT_QUOTES, 'UTF-8') : '' ?>">
                        <select class="form-select" onchange="if(this.value){document.getElementById('exportFormat').value = this.value; document.getElementById('exportTeachersForm').submit();}">
                            <option value="">📥 Export...</option>
                            <option value="csv">CSV</option>
                            <option value="excel">Excel</option>
                        </select>
                    </form>

                    <div class="teachers-primary-actions">
                        <a href="<?= APP_URL ?>/requirement_planning.php<?= $filterSchool > 0 ? '?school=' . urlencode(encryptId($filterSchool)) : '' ?>" class="btn btn-ghost teachers-generate-btn">
                            <i class="fas fa-diagram-project"></i> Requirement Planning
                        </a>
                        <button type="button" id="undoUploadBtn" class="btn btn-danger teachers-undo-btn" <?= empty($undoCandidates) ? 'disabled' : '' ?>>
                            <i class="fas fa-rotate-left"></i> Undo Upload
                        </button>
                        <button type="button" class="btn btn-secondary teachers-bulk-btn" onclick="document.getElementById('bulkUploadTeachersModal').style.display='flex'">
                            <i class="fas fa-file-upload"></i> Bulk Upload
                        </button>
                        <a href="<?= APP_URL ?>/add_teacher.php<?= $filterSchool > 0 ? '?school=' . urlencode(encryptId($filterSchool)) : '' ?>" class="btn btn-primary teachers-add-btn">
                            <i class="fas fa-plus"></i> Add Teacher
                        </a>
                    </div>
                </div>

            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="filter-actions teachers-data-filters">
        <span class="teachers-section-label" style="margin:0 8px 0 0;"><i class="fas fa-bolt"></i> Quick Filters</span>
        <a href="<?= $buildTeachersUrl(['data' => 'needs_update', 'page' => null]) ?>" class="btn btn-sm <?= $filterData === 'needs_update' ? 'btn-primary' : 'btn-ghost' ?>">
            <i class="fas fa-triangle-exclamation"></i> Needs Update
        </a>
        <a href="<?= $buildTeachersUrl(['data' => 'birthdate_fix', 'page' => null]) ?>" class="btn btn-sm <?= $filterData === 'birthdate_fix' ? 'btn-primary' : 'btn-ghost' ?>">
            <i class="fas fa-calendar-xmark"></i> Birthdate Year Fix
        </a>
        <div class="teachers-view-toggle" role="group" aria-label="Choose teachers view">
            <button type="button" class="btn btn-ghost btn-sm" id="teachersViewListBtn">
                <i class="fas fa-list"></i> List
            </button>
            <button type="button" class="btn btn-ghost btn-sm" id="teachersViewCardBtn">
                <i class="fas fa-th-large"></i> Card
            </button>
        </div>
        <?php if ($filterData !== ''): ?>
        <a href="<?= $buildTeachersUrl(['data' => null, 'page' => null]) ?>" class="btn btn-ghost btn-sm">
            <i class="fas fa-xmark"></i> Clear Data Filter
        </a>
        <?php endif; ?>
        <?php if ($search || $filterDist || $filterPos || $filterGender || $filterSchool || $filterSpec || $filterGrade || $filterPwd || $filterRetire || $filterData): ?>
        <a href="<?= APP_URL ?>/teachers.php" class="btn btn-ghost btn-sm teachers-clear-btn">
            <i class="fas fa-times"></i> Clear Filters
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (canEdit()): ?>
<form id="undoUploadForm" method="POST" action="<?= APP_URL ?>/actions/undo_latest_upload.php" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="upload_log_id" id="undoUploadIdInput" value="">
    <input type="hidden" name="confirm_password" id="undoPasswordInput" value="">
</form>

<form id="transferTeacherForm" method="POST" action="<?= APP_URL ?>/actions/transfer_teacher.php" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="teacher_id" id="transferTeacherId" value="">
    <input type="hidden" name="school_id" id="transferSchoolId" value="">
    <input type="hidden" name="confirm_password" id="transferPasswordInput" value="">
</form>

<form id="generatePositionsForm" method="POST" action="<?= APP_URL ?>/actions/generate_teacher_positions.php" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="school_id" value="<?= (int)$filterSchool ?>">
    <input type="hidden" name="return_query" value="<?= clean((string)($_SERVER['QUERY_STRING'] ?? '')) ?>">
</form>
<?php endif; ?>

<?php if ($filterSchool > 0): ?>
<div class="filter-bar glass-card" style="margin-top:10px;align-items:center;justify-content:space-between">
    <div>
        <strong>School Connected:</strong> <?= clean($selectedSchoolName ?: ('School #' . $filterSchool)) ?>
    </div>
    <a href="<?= APP_URL ?>/schools.php" class="btn btn-ghost btn-sm">
        <i class="fas fa-school"></i> Back to Schools
    </a>
</div>
<?php endif; ?>

<?php if ($uploadErrorReport): ?>
<div class="glass-card" style="margin-top:12px;border:1px solid rgba(239,68,68,.25);background:linear-gradient(180deg, rgba(127,29,29,.24), rgba(15,23,42,.72));">
    <div class="card-header">
        <h3><i class="fas fa-triangle-exclamation"></i> Upload Error Report</h3>
    </div>
    <div style="display:grid;gap:10px">
        <div class="results-info" style="margin:0">
            File <strong><?= clean($uploadErrorReport['file_name'] ?? '—') ?></strong>:
            <?= number_format((int)($uploadErrorReport['imported'] ?? 0)) ?> imported,
            <?= number_format((int)($uploadErrorReport['skipped'] ?? 0)) ?> skipped,
            <?= number_format((int)($uploadErrorReport['errors'] ?? 0)) ?> errors.
        </div>
        <div class="table-scroll" style="max-height:340px;border:1px solid rgba(148,163,184,.18);border-radius:12px">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="text-center">#</th>
                        <th>Specific Error</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (($uploadErrorReport['details'] ?? []) as $i => $err): ?>
                <tr>
                    <td class="text-center"><?= (int)($i + 1) ?></td>
                    <td><?= clean($err) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($uploadErrorReport['details'])): ?>
                <tr><td colspan="2" class="text-center text-muted">No error details captured.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a href="<?= APP_URL ?>/upload.php" class="btn btn-secondary btn-sm"><i class="fas fa-upload"></i> Open Upload Page</a>
            <a href="<?= APP_URL ?>/teachers.php" class="btn btn-ghost btn-sm"><i class="fas fa-rotate-right"></i> Dismiss Report</a>
        </div>
    </div>
</div>
<?php endif; ?>



<!-- ── Results Count ──────────────────────────────────────── -->
<div class="results-info">
    Showing <strong><?= number_format($pag['offset'] + 1) ?>–<?= number_format(min($pag['offset'] + $pag['per_page'], $total)) ?></strong>
    of <strong><?= number_format($total) ?></strong> teachers
</div>

<!-- ── Teachers List View ─────────────────────────────────── -->
<div class="table-card glass-card" id="teachersListView" style="display:none">
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Employee No.</th>
                    <th>Position</th>
                    <th>School</th>
                    <th>District</th>
                    <th>Gender</th>
                    <?php if (canEdit()): ?><th class="text-center">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($teachers as $t): ?>
            <tr>
                <?php
                    $addrParts = array_filter([
                        $t['house_street'] ?? '',
                        $t['barangay'] ?? '',
                        $t['municipality'] ?? '',
                        $t['province'] ?? '',
                    ]);
                    $fullAddress = $addrParts ? implode(', ', $addrParts) : '—';
                ?>
                <td>
                    <strong><?= clean($t['last_name']) ?>, <?= clean($t['first_name']) ?></strong>
                    <?php if (!empty($t['middle_name']) || !empty($t['extension_name'])): ?>
                    <div style="font-size:12px;color:var(--text-muted)"><?= clean(($t['middle_name'] ?? '') . ' ' . ($t['extension_name'] ?? '')) ?></div>
                    <?php endif; ?>
                    <?php if ((int)($t['school_head_count'] ?? 0) > 0): ?>
                    <div class="teacher-head-tag">
                        <i class="fas fa-user-tie"></i>
                        School Head<?= (int)$t['school_head_count'] > 1 ? ' (' . (int)$t['school_head_count'] . ' schools)' : '' ?>
                    </div>
                    <?php if (!empty($t['school_head_schools'])): ?>
                    <div class="teacher-head-schools"><i class="fas fa-school"></i> <?= clean($t['school_head_schools']) ?></div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <div style="font-size:12px;color:var(--text-muted)"><i class="fas fa-map-marker-alt"></i> <?= clean($fullAddress) ?></div>
                </td>
                <td><?= clean($t['employee_number'] ?? '—') ?></td>
                <td><?= clean($t['position'] ?? '—') ?></td>
                <td><?= clean($t['school_name'] ?? '—') ?></td>
                <td><?= clean($t['district'] ?? '—') ?></td>
                <td><?= clean($t['gender'] ?? '—') ?></td>
                <?php if (canEdit()): ?>
                <td class="text-center">
                    <a href="<?= APP_URL ?>/view_teacher.php?id=<?= encryptId((int)$t['id']) ?><?= $schoolCtxQuery ?>" class="btn btn-sm btn-ghost" title="View">
                        <i class="fas fa-eye"></i>
                    </a>
                    <a href="<?= APP_URL ?>/edit_teacher.php?id=<?= encryptId((int)$t['id']) ?><?= $schoolCtxQuery ?>" class="btn btn-sm btn-secondary" title="Edit">
                        <i class="fas fa-edit"></i>
                    </a>
                    <button class="btn btn-sm btn-primary" title="Transfer School"
                            onclick="openTransferSchoolModal(<?= (int)$t['id'] ?>, '<?= htmlspecialchars(clean($t['last_name'].', '.$t['first_name']), ENT_QUOTES, 'UTF-8') ?>', <?= (int)($t['school_id'] ?? 0) ?>)">
                        <i class="fas fa-right-left"></i>
                    </button>
                    <button class="btn btn-sm btn-danger" title="Delete"
                            onclick="confirmDelete(<?= (int)$t['id'] ?>, '<?= clean($t['last_name'].', '.$t['first_name']) ?>')">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (!$teachers): ?>
            <tr><td colspan="<?= canEdit() ? 7 : 6 ?>" class="text-center text-muted">No teachers found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Teachers Grid ──────────────────────────────────────── -->
<div class="teacher-grid" id="teachersCardView">
<?php foreach ($teachers as $t): ?>
<div class="teacher-card glass-card<?= (int)($t['school_head_count'] ?? 0) > 0 ? ' teacher-card-head' : '' ?>">
    <?php
        $addrParts = array_filter([
            $t['house_street'] ?? '',
            $t['barangay'] ?? '',
            $t['municipality'] ?? '',
            $t['province'] ?? '',
        ]);
        $fullAddress = $addrParts ? implode(', ', $addrParts) : '—';
        $schoolName = trim((string)($t['school_name'] ?? ''));
        $districtName = trim((string)($t['district'] ?? ''));
        $education = trim((string)($t['highest_education'] ?? ''));
        $course = trim((string)($t['field_of_study'] ?? ''));
        $educationLine = trim($education . ($course !== '' ? ' - ' . $course : ''));
        $dataIssues = [];
        $birthdateRaw = trim((string)($t['birthdate'] ?? ''));

        $requiredFieldMap = [
            'Employee number' => (string)($t['employee_number'] ?? ''),
            'Last name' => (string)($t['last_name'] ?? ''),
            'First name' => (string)($t['first_name'] ?? ''),
            'Gender' => (string)($t['gender'] ?? ''),
            'Civil status' => (string)($t['civil_status'] ?? ''),
            'PWD status' => (string)($t['pwd_status'] ?? ''),
            'Contact number' => (string)($t['contact_number'] ?? ''),
            'Email address' => (string)($t['email_address'] ?? ''),
            'Barangay' => (string)($t['barangay'] ?? ''),
            'Municipality' => (string)($t['municipality'] ?? ''),
            'Province' => (string)($t['province'] ?? ''),
            'Position' => (string)($t['position'] ?? ''),
            'Item number' => (string)($t['item_number'] ?? ''),
            'Salary grade' => (string)($t['salary_grade'] ?? ''),
            'Appointment type' => (string)($t['appointment_type'] ?? ''),
            'District' => (string)($t['district'] ?? ''),
            'Grade level' => (string)($t['grade_level'] ?? ''),
            'Specialization' => (string)($t['specialization'] ?? ''),
            'Subjects' => (string)($t['subjects'] ?? ''),
            'Highest education' => (string)($t['highest_education'] ?? ''),
            'CSEE eligibility' => (string)($t['csee_eligibility'] ?? ''),
            'Data privacy consent' => (string)($t['data_privacy_consent'] ?? ''),
        ];

        foreach ($requiredFieldMap as $label => $value) {
            if (trim($value) === '') {
                $dataIssues[] = 'Missing ' . $label;
            }
        }

        $schoolLinked = ((int)($t['school_id'] ?? 0) > 0) || trim((string)($t['school_name_raw'] ?? '')) !== '';
        if (!$schoolLinked) {
            $dataIssues[] = 'Missing school assignment';
        }

        $appointmentDateRaw = trim((string)($t['original_appointment_date'] ?? ''));
        $appointmentDateLabel = ($appointmentDateRaw !== '' && $appointmentDateRaw !== '0000-00-00')
            ? (string)formatDate($appointmentDateRaw)
            : 'Missing';
        if ($appointmentDateRaw === '' || $appointmentDateRaw === '0000-00-00') {
            $dataIssues[] = 'Missing original appointment date';
        }

        $birthdateSuggestion = '';
        if ($birthdateRaw === '' || $birthdateRaw === '0000-00-00') {
            $dataIssues[] = 'Missing birthdate';
        } else {
            $parts = explode('-', $birthdateRaw);
            if (count($parts) === 3 && ctype_digit($parts[0]) && ctype_digit($parts[1]) && ctype_digit($parts[2])) {
                $year = (int)$parts[0];
                $month = (int)$parts[1];
                $day = (int)$parts[2];
                $currentYear = (int)date('Y');

                if ($year > $currentYear) {
                    $dataIssues[] = 'Birthdate year is in the future';
                }
                if ($year > 0 && $year < 1900) {
                    $suggestedYear = $year;
                    if ($year < 1000) {
                        $suggestedYear = $year + 1900;
                    }
                    if ($suggestedYear <= $currentYear && checkdate($month, $day, $suggestedYear)) {
                        $birthdateSuggestion = sprintf('%04d-%02d-%02d', $suggestedYear, $month, $day);
                    }
                    $dataIssues[] = 'Suspicious birthdate year (' . $year . ')' . ($birthdateSuggestion !== '' ? ' - suggested: ' . $birthdateSuggestion : '');
                }
            }
        }

        $visibleIssues = $dataIssues;
        if (count($visibleIssues) > 8) {
            $remaining = count($visibleIssues) - 8;
            $visibleIssues = array_slice($visibleIssues, 0, 8);
            $visibleIssues[] = '+' . $remaining . ' more missing fields';
        }
    ?>
    <div class="tc-photo">
        <?php if ($t['profile_photo']): ?>
        
        <?php else: ?>
        <div class="tc-avatar-placeholder">
            <?= strtoupper(substr($t['last_name'], 0, 1)) ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="tc-body">
        <div class="tc-name"><?= clean($t['last_name']) ?>, <?= clean($t['first_name']) ?> <?= clean($t['middle_name'] ?? '') ?><?= $t['extension_name'] ? ' '.$t['extension_name'] : '' ?></div>
        <div class="tc-sub">
            <span class="tc-badge"><?= clean($t['position'] ?? '—') ?></span>
            <?php if (!empty($t['gender'])): ?>
            <span class="tc-badge"><?= clean($t['gender']) ?></span>
            <?php endif; ?>
        </div>
        <div class="tc-info">
            <?php if ($schoolName !== ''): ?>
            <span class="tc-info-row tc-school-line" title="School"><i class="fas fa-school"></i><span class="tc-key">School</span><span class="tc-value"><?= clean($schoolName) ?></span></span>
            <?php endif; ?>
            <?php if ($districtName !== ''): ?>
            <span class="tc-info-row" title="District"><i class="fas fa-map"></i><span class="tc-key">District</span><span class="tc-value"><?= clean($districtName) ?></span></span>
            <?php endif; ?>
            <span class="tc-info-row" title="Appointment Date"><i class="fas fa-calendar-check"></i><span class="tc-key">Appointment Date</span><span class="tc-value"><?= clean($appointmentDateLabel) ?></span></span>
            <?php if (!empty($t['school_head_schools'])): ?>
            <span class="tc-info-row tc-info-head" title="Headed School"><i class="fas fa-user-tie"></i><span class="tc-key">Head Of</span><span class="tc-value"><?= clean($t['school_head_schools']) ?></span></span>
            <?php endif; ?>
            <?php if ($fullAddress !== '—'): ?>
            <span class="tc-info-row" title="Address"><i class="fas fa-map-marker-alt"></i><span class="tc-key">Address</span><span class="tc-value"><?= clean($fullAddress) ?></span></span>
            <?php endif; ?>
            <?php if ($educationLine !== ''): ?>
            <span class="tc-info-row" title="Education"><i class="fas fa-graduation-cap"></i><span class="tc-key">Education</span><span class="tc-value"><?= clean($educationLine) ?></span></span>
            <?php endif; ?>
            <?php if ($t['specialization']): ?>
            <span class="tc-info-row" title="Specialization"><i class="fas fa-star"></i><span class="tc-key">Specialization</span><span class="tc-value"><?= clean($t['specialization']) ?></span></span>
            <?php endif; ?>
        </div>

        <?php if ($dataIssues): ?>
        <div style="margin-top:10px;padding:10px 12px;border-radius:10px;border:1px solid rgba(251,146,60,.38);background:rgba(251,146,60,.12);">
            <div style="font-size:12px;font-weight:700;color:#fed7aa;text-transform:uppercase;letter-spacing:.04em;">
                <i class="fas fa-triangle-exclamation"></i> Needs Re-Update
            </div>
            <div style="margin-top:6px;font-size:12px;color:#ffedd5;line-height:1.4;">
                <?= clean(implode(' | ', $visibleIssues)) ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="tc-actions">
        <a href="<?= APP_URL ?>/view_teacher.php?id=<?= encryptId((int)$t['id']) ?><?= $schoolCtxQuery ?>" class="btn btn-sm btn-ghost" title="View">
            <i class="fas fa-eye"></i>
        </a>
        <?php if (canEdit()): ?>
        <a href="<?= APP_URL ?>/edit_teacher.php?id=<?= encryptId((int)$t['id']) ?><?= $schoolCtxQuery ?>" class="btn btn-sm btn-secondary" title="Edit">
            <i class="fas fa-edit"></i>
        </a>
        <button class="btn btn-sm btn-primary" title="Transfer School"
                onclick="openTransferSchoolModal(<?= (int)$t['id'] ?>, '<?= htmlspecialchars(clean($t['last_name'].', '.$t['first_name']), ENT_QUOTES, 'UTF-8') ?>', <?= (int)($t['school_id'] ?? 0) ?>)">
            <i class="fas fa-right-left"></i>
        </button>
        <button class="btn btn-sm btn-danger" title="Delete"
                onclick="confirmDelete(<?= (int)$t['id'] ?>, '<?= clean($t['last_name'].', '.$t['first_name']) ?>')">
            <i class="fas fa-trash"></i>
        </button>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php if (!$teachers): ?>
<div class="empty-state glass-card">
    <i class="fas fa-user-slash fa-3x"></i>
    <p>No teachers found<?= $search ? ' for "<strong>'.clean($search).'</strong>"' : '' ?>.</p>
    <?php if (canEdit()): ?>
        <a href="<?= APP_URL ?>/add_teacher.php<?= $filterSchool > 0 ? '?school=' . urlencode(encryptId($filterSchool)) : '' ?>" class="btn btn-primary">Add First Teacher</a>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>

<!-- ── Pagination ─────────────────────────────────────────── -->
<?= paginationLinks($pag, APP_URL . '/' . basename($_SERVER['PHP_SELF']) . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>

<!-- Bulk Upload Teachers Modal -->
<div class="modal-overlay" id="bulkUploadTeachersModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-header">
            <h3 class="modal-title">Bulk Upload Teachers</h3>
            <button class="modal-close" onclick="document.getElementById('bulkUploadTeachersModal').style.display='none'">×</button>
        </div>
        <form method="POST" action="<?= APP_URL ?>/actions/process_upload.php" enctype="multipart/form-data" id="bulkUploadTeachersForm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="return_to" value="<?= APP_URL . '/teachers.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . clean((string)$_SERVER['QUERY_STRING']) : '') ?>">
            <div class="form-group" style="margin-bottom:8px">
                <a href="<?= APP_URL ?>/assets/templates/upload_template.csv" class="btn btn-ghost btn-sm" download>
                    <i class="fas fa-download"></i> Download Sample CSV
                </a>
            </div>
            <div class="form-group" style="font-size:13px;color:var(--text-muted)">
                Required headers: <strong>School ID Code</strong>, <strong>Employee Number</strong>, <strong>Last Name</strong>, <strong>First Name</strong>.
                Include <strong>Current Teaching</strong> for subjects assigned to the teacher.
            </div>
            <div class="form-group">
                <label class="form-label required">Upload File (.xlsx, .csv)</label>
                <input type="file" name="upload_file" class="form-input" accept=".xlsx,.csv" required>
            </div>
            <div class="form-group" style="display:flex;gap:12px;flex-wrap:wrap">
                <label><input type="checkbox" name="skip_duplicates" value="1"> Skip duplicates</label>
                <label><input type="checkbox" name="update_existing" value="1" checked> Update existing</label>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('bulkUploadTeachersModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary" id="bulkUploadTeachersSubmitBtn">Upload</button>
            </div>
        </form>
    </div>
</div>

<?php if (canEdit()): ?>
<!-- Transfer School Modal -->
<div class="modal-overlay" id="transferSchoolModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-header">
            <h3 class="modal-title">Transfer to Other School</h3>
            <button class="modal-close" onclick="document.getElementById('transferSchoolModal').style.display='none'">×</button>
        </div>
        <div class="modal-body">
            <p class="text-muted">Select a new school for <strong id="transferTeacherName"></strong>.</p>
            <div class="form-group" style="margin-top:10px">
                <label class="form-label required">New School</label>
                <select id="transferSchoolSelect" class="form-select">
                    <option value="">Select school...</option>
                    <?php foreach ($schools as $sc): ?>
                    <option value="<?= (int)$sc['id'] ?>"><?= clean($sc['school_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('transferSchoolModal').style.display='none'">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirmTransferBtn" onclick="submitTeacherTransfer()">
                <i class="fas fa-right-left"></i> Transfer
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Delete Confirm Modal -->
<div class="modal-overlay" id="deleteModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 class="modal-title">Delete Teacher</h3>
        <p class="modal-body">Are you sure you want to delete <strong id="deleteName"></strong>? This cannot be undone.</p>
        <div class="modal-actions">
            <button onclick="document.getElementById('deleteModal').style.display='none'" class="btn btn-ghost">Cancel</button>
            <form method="POST" action="<?= APP_URL ?>/actions/delete_teacher.php" id="deleteForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="id" id="deleteId">
                <input type="hidden" name="confirm_password" id="deleteConfirmPassword">
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, name) {
    document.getElementById('deleteName').textContent = name;
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteModal').style.display = 'flex';
}

async function promptTeacherDeletePassword(message) {
    if (typeof Swal !== 'undefined') {
        const res = await Swal.fire({
            title: 'Confirm Password',
            text: message,
            input: 'password',
            inputPlaceholder: 'Current password',
            inputAttributes: { autocomplete: 'current-password', autocapitalize: 'off', autocorrect: 'off' },
            showCancelButton: true,
            confirmButtonText: 'Delete',
            cancelButtonText: 'Cancel',
            preConfirm: (value) => {
                if (!value) {
                    Swal.showValidationMessage('Password is required.');
                    return false;
                }
                return value;
            }
        });
        return res.isConfirmed ? res.value : '';
    }

    return prompt(message) || '';
}

document.getElementById('deleteForm')?.addEventListener('submit', async function(e) {
    if (this.dataset.confirmed === '1') return;
    e.preventDefault();
    const pwd = await promptTeacherDeletePassword('Enter your password to delete this teacher:');
    if (!pwd) return;
    const confirmField = document.getElementById('deleteConfirmPassword');
    if (confirmField) confirmField.value = pwd;
    this.dataset.confirmed = '1';
    this.submit();
});

function openTransferSchoolModal(id, name, currentSchoolId) {
    const modal = document.getElementById('transferSchoolModal');
    const teacherIdInput = document.getElementById('transferTeacherId');
    const teacherNameEl = document.getElementById('transferTeacherName');
    const schoolSelect = document.getElementById('transferSchoolSelect');
    if (!modal || !teacherIdInput || !teacherNameEl || !schoolSelect) return;

    teacherIdInput.value = id;
    teacherNameEl.textContent = name;
    schoolSelect.value = currentSchoolId > 0 ? String(currentSchoolId) : '';
    modal.style.display = 'flex';
}

async function submitTeacherTransfer() {
    const schoolSelect = document.getElementById('transferSchoolSelect');
    const schoolIdInput = document.getElementById('transferSchoolId');
    const passwordInput = document.getElementById('transferPasswordInput');
    const form = document.getElementById('transferTeacherForm');
    if (!schoolSelect || !schoolIdInput || !passwordInput || !form) return;

    if (!schoolSelect.value) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'warning', title: 'School Required', text: 'Please select a school to continue.' });
        } else {
            alert('Please select a school to continue.');
        }
        return;
    }

    schoolIdInput.value = schoolSelect.value;
    passwordInput.value = '';

    if (typeof Swal !== 'undefined') {
        const passwordRes = await Swal.fire({
            title: 'Confirm Password',
            input: 'password',
            inputPlaceholder: 'Enter your password',
            inputAttributes: {
                autocapitalize: 'off',
                autocorrect: 'off',
                autocomplete: 'current-password',
            },
            showCancelButton: true,
            confirmButtonText: 'Confirm Transfer',
            cancelButtonText: 'Cancel',
            preConfirm: (value) => {
                if (!value) {
                    Swal.showValidationMessage('Password is required.');
                    return false;
                }
                return value;
            },
        });

        if (!passwordRes.isConfirmed) {
            return;
        }

        passwordInput.value = passwordRes.value;
    } else {
        const pwd = prompt('Enter your password to confirm transfer:');
        if (!pwd) {
            return;
        }
        passwordInput.value = pwd;
    }

    form.submit();
}

function setTeachersView(mode) {
    const listWrap = document.getElementById('teachersListView');
    const cardWrap = document.getElementById('teachersCardView');
    const listBtn  = document.getElementById('teachersViewListBtn');
    const cardBtn  = document.getElementById('teachersViewCardBtn');

    if (!listWrap || !cardWrap || !listBtn || !cardBtn) return;

    if (mode === 'card') {
        listWrap.style.display = 'none';
        cardWrap.style.display = 'grid';
        listBtn.classList.remove('btn-primary');
        listBtn.classList.add('btn-ghost');
        cardBtn.classList.remove('btn-ghost');
        cardBtn.classList.add('btn-primary');
    } else {
        listWrap.style.display = '';
        cardWrap.style.display = 'none';
        cardBtn.classList.remove('btn-primary');
        cardBtn.classList.add('btn-ghost');
        listBtn.classList.remove('btn-ghost');
        listBtn.classList.add('btn-primary');
    }
    localStorage.setItem('teachersViewMode', mode);
}

function createDebounce(fn, delay) {
    if (typeof window.debounce === 'function') {
        return window.debounce(fn, delay);
    }
    let timer;
    return function(...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}

document.addEventListener('DOMContentLoaded', function() {
    const listBtnEl = document.getElementById('teachersViewListBtn');
    const cardBtnEl = document.getElementById('teachersViewCardBtn');
    if (listBtnEl) listBtnEl.addEventListener('click', () => setTeachersView('list'));
    if (cardBtnEl) cardBtnEl.addEventListener('click', () => setTeachersView('card'));
    setTeachersView(localStorage.getItem('teachersViewMode') || 'card');

    const searchInputEl = document.getElementById('searchInput');
    if (searchInputEl) {
        searchInputEl.addEventListener('input', createDebounce(function() {
            this.form.submit();
        }, 600));
    }

    const bulkUploadFormEl = document.getElementById('bulkUploadTeachersForm');
    if (bulkUploadFormEl) {
        bulkUploadFormEl.addEventListener('submit', function() {
            if (this.dataset.submitting === '1') {
                return;
            }
            this.dataset.submitting = '1';

            const submitBtn = document.getElementById('bulkUploadTeachersSubmitBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            }

            const cancelBtn = this.querySelector('.modal-actions .btn-ghost');
            if (cancelBtn) {
                cancelBtn.disabled = true;
            }

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Uploading Teachers',
                    text: 'Please wait while we process your file. This may take a moment.',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
            }
        });
    }

    <?php if (canEdit()): ?>
    const undoCandidates = <?= json_encode(array_map(static fn($u) => [
        'id' => (int)($u['id'] ?? 0),
        'file_name' => (string)($u['file_name'] ?? 'Upload'),
        'imported_rows' => (int)($u['imported_rows'] ?? 0),
    ], $undoCandidates), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    async function launchUndoUploadFlow() {
        if (!undoCandidates.length) {
            if (typeof Swal !== 'undefined') {
                await Swal.fire({ icon: 'info', title: 'No Undoable Upload', text: 'No tracked upload is currently available for undo.' });
            } else {
                alert('No tracked upload is currently available for undo.');
            }
            return;
        }

        if (typeof Swal === 'undefined') {
            const selected = prompt('Enter upload ID to undo:');
            if (!selected) return;
            const pwd = prompt('Enter your password to confirm undo:');
            if (!pwd) return;
            document.getElementById('undoUploadIdInput').value = selected;
            document.getElementById('undoPasswordInput').value = pwd;
            document.getElementById('undoUploadForm').submit();
            return;
        }

        const confirmRes = await Swal.fire({
            icon: 'warning',
            title: 'Undo Upload?',
            text: 'This will revert inserted and updated rows from the selected upload batch.',
            showCancelButton: true,
            confirmButtonText: 'Continue',
            cancelButtonText: 'Cancel',
        });
        if (!confirmRes.isConfirmed) return;

        const selectOptions = {};
        for (const u of undoCandidates) {
            selectOptions[String(u.id)] = `#${u.id} - ${u.file_name} (${u.imported_rows} imported)`;
        }

        const selectRes = await Swal.fire({
            title: 'Select Upload Batch',
            input: 'select',
            inputOptions: selectOptions,
            inputPlaceholder: 'Choose upload to undo',
            inputValue: String(undoCandidates[0].id),
            showCancelButton: true,
            confirmButtonText: 'Next',
            cancelButtonText: 'Cancel',
            inputValidator: (value) => !value ? 'Please choose an upload batch.' : null,
        });
        if (!selectRes.isConfirmed) return;

        const passwordRes = await Swal.fire({
            title: 'Confirm Password',
            input: 'password',
            inputPlaceholder: 'Enter your password',
            inputAttributes: {
                autocapitalize: 'off',
                autocorrect: 'off',
                autocomplete: 'current-password',
            },
            showCancelButton: true,
            confirmButtonText: 'Undo Upload',
            cancelButtonText: 'Cancel',
            preConfirm: (value) => {
                if (!value) {
                    Swal.showValidationMessage('Password is required.');
                    return false;
                }
                return value;
            },
        });
        if (!passwordRes.isConfirmed) return;

        document.getElementById('undoUploadIdInput').value = selectRes.value;
        document.getElementById('undoPasswordInput').value = passwordRes.value;
        document.getElementById('undoUploadForm').submit();
    }

    const undoBtnEl = document.getElementById('undoUploadBtn');
    if (undoBtnEl) undoBtnEl.addEventListener('click', launchUndoUploadFlow);

    const generateBtnEl = document.getElementById('generatePositionsBtn');
    const generateFormEl = document.getElementById('generatePositionsForm');
    if (generateBtnEl && generateFormEl) {
        generateBtnEl.addEventListener('click', async function() {
            if (typeof Swal !== 'undefined') {
                const res = await Swal.fire({
                    icon: 'question',
                    title: 'Generate Teacher Positions?',
                    text: 'This fills blank Position fields using Item Number or Salary Grade.',
                    showCancelButton: true,
                    confirmButtonText: 'Generate',
                    cancelButtonText: 'Cancel',
                });
                if (!res.isConfirmed) {
                    return;
                }
            } else if (!confirm('Generate blank teacher positions using Item Number/Salary Grade?')) {
                return;
            }
            generateFormEl.submit();
        });
    }
    <?php endif; ?>
});

<?php if (canEdit()): ?>
// Safety: if button is clicked before DOMContentLoaded listeners run.
window.launchUndoUploadFlow = window.launchUndoUploadFlow || null;
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
