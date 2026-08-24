<?php
$pageTitle = 'Schools';
require_once __DIR__ . '/includes/header.php';

// Require user to have selected a role
requireRoleSelection();

$db     = getDB();
ensureTeacherPlanningSchema($db);
$search = clean(trim($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$districtFilter = trim($_GET['district'] ?? '');

// Input validation: enforce maximum lengths to prevent DoS
if (strlen($search) > 500) {
    flash('error', 'Search term is too long (max 500 characters).');
    redirect(APP_URL . '/schools');
}
if (strlen($districtFilter) > 255) {
    flash('error', 'District filter is too long.');
    redirect(APP_URL . '/schools');
}

$type = strtolower(trim($_GET['type'] ?? 'all'));
$allowedTypes = ['all', 'public', 'private', 'als', 'elementary', 'pure_elementary', 'jhs', 'shs', 'pure_shs', 'es/jhs', 'es/shs', 'jhs/shs', 'es/jhs/shs', 'all offering', 'untagged'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'all';
}
if ($type === 'es/jhs/shs') {
    $type = 'all offering';
}

$staffing = strtolower(trim($_GET['staffing'] ?? 'all'));
$allowedStaffing = ['all', 'no_teacher'];
if (!in_array($staffing, $allowedStaffing, true)) {
    $staffing = 'all';
}

$conditions = [];
$params = [];
$schoolTypeExpr = "LOWER(TRIM(COALESCE(s.school_type, '')))";
$schoolTypeExprCompact = "REPLACE(LOWER(TRIM(COALESCE(s.school_type, ''))), ' ', '')";
$typeCompact = str_replace(' ', '', $type);

// Add district filter for non-admin users
if (shouldFilterByDistrict()) {
    $selectedDistrict = getSessionDistrict();
    if ($selectedDistrict !== null) {
        $conditions[] = 's.district_id = ?';
        $params[] = $selectedDistrict;
    }
}

if ($search !== '') {
    $conditions[] = '(s.school_name LIKE ? OR d.district_name LIKE ? OR s.school_id_code LIKE ? OR s.municipality LIKE ? OR s.school_type LIKE ?)';
    $params = array_merge($params, array_fill(0, 5, '%' . $search . '%'));
}

if ($type === 'untagged') {
    $conditions[] = "(s.school_type IS NULL OR TRIM(s.school_type) = '' OR $schoolTypeExprCompact NOT IN ('elementary', 'es', 'jhs', 'shs', 'es/jhs', 'es/shs', 'jhs/shs', 'jhs-shs', 'juniorandseniorhighschool', 'es/jhs/shs', 'alloffering', 'als', 'public', 'private'))";
} elseif ($type === 'elementary') {
    $conditions[] = "$schoolTypeExprCompact IN ('elementary', 'es')";
} elseif ($type === 'pure_elementary') {
    $conditions[] = "$schoolTypeExprCompact IN ('elementary', 'es')";
} elseif ($type === 'jhs') {
    $conditions[] = "$schoolTypeExprCompact = 'jhs'";
} elseif ($type === 'pure_shs') {
    $conditions[] = "$schoolTypeExprCompact = 'shs'";
} elseif ($type === 'shs') {
    $conditions[] = "$schoolTypeExprCompact = 'shs'";
} elseif ($type === 'all offering') {
    // Treat legacy ES/JHS/SHS values as ALL OFFERING.
    $conditions[] = "$schoolTypeExprCompact IN ('alloffering', 'es/jhs/shs')";
} elseif ($type !== 'all') {
    $conditions[] = "$schoolTypeExprCompact = ?";
    $params[] = $typeCompact;
}

if ($staffing === 'no_teacher') {
    $conditions[] = 'NOT EXISTS (SELECT 1 FROM teachers t0 WHERE t0.school_id = s.id)';
}
if ($districtFilter !== '') {
    $conditions[] = 'd.district_name = ?';
    $params[] = $districtFilter;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$defaultLearnersPerTeacher = 35;
$staffingQuery = $staffing !== 'all' ? '&staffing=' . urlencode($staffing) : '';

$schoolCols = [];
foreach ($db->query('SHOW COLUMNS FROM schools')->fetchAll() as $colMeta) {
    $schoolCols[] = $colMeta['Field'];
}
$hasLearnersPerTeacher = in_array('learners_per_teacher', $schoolCols, true);

if (!in_array('school_head_teacher_id', $schoolCols, true)) {
    try {
        $db->exec('ALTER TABLE schools ADD COLUMN school_head_teacher_id INT NULL AFTER district_id');
        $schoolCols[] = 'school_head_teacher_id';
    } catch (Throwable $e) {
        error_log('TPMS school head migration skipped: ' . $e->getMessage());
    }
}

// Get school heads - filter by district for PSDS/SDC/Unit Head
$schoolHeadsParams = [];
$schoolHeadsWhere = 'WHERE first_name IS NOT NULL AND last_name IS NOT NULL';
if (shouldFilterByDistrict()) {
    $selectedDistrict = getSessionDistrict();
    if ($selectedDistrict !== null) {
        $schoolHeadsWhere = 'WHERE first_name IS NOT NULL AND last_name IS NOT NULL 
                             AND school_id IN (SELECT id FROM schools WHERE district_id = ?)';
        $schoolHeadsParams = [$selectedDistrict];
    }
}

$schoolHeadsStmt = $db->prepare(
    "SELECT id, first_name, last_name, position, school_id
     FROM teachers
     $schoolHeadsWhere
     ORDER BY last_name, first_name"
);
$schoolHeadsStmt->execute($schoolHeadsParams);
$schoolHeads = $schoolHeadsStmt->fetchAll();

$typeCounts = ['all' => 0, 'public' => 0, 'private' => 0, 'als' => 0, 'elementary' => 0, 'jhs' => 0, 'shs' => 0, 'untagged' => 0];
$exactTypeCounts = [
    'elementary' => 0,
    'jhs' => 0,
    'shs' => 0,
    'es/jhs' => 0,
    'es/shs' => 0,
    'jhs/shs' => 0,
    'es/jhs/shs' => 0,
    'all offering' => 0,
    'als' => 0,
];

// Apply district filter for type counts
$districtWhereClause = '';
$districtParams = [];
if (shouldFilterByDistrict()) {
    $selectedDistrict = getSessionDistrict();
    if ($selectedDistrict !== null) {
        $districtWhereClause = ' WHERE district_id = ?';
        $districtParams = [$selectedDistrict];
    }
}

$typeCountQuery = 'SELECT REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") AS t, COUNT(*) AS c FROM schools' . $districtWhereClause . ' GROUP BY t';
$typeCountStmt = $db->prepare($typeCountQuery);
$typeCountStmt->execute($districtParams);
foreach ($typeCountStmt->fetchAll() as $r) {
    $k = $r['t'];
    $count = (int)$r['c'];
    if ($k === 'jhs-shs' || $k === 'juniorandseniorhighschool') {
        $k = 'jhs/shs';
    } elseif ($k === 'es/jhs/shs') {
        $k = 'all offering';
    } elseif ($k === 'alloffering') {
        $k = 'all offering';
    }
    if (isset($exactTypeCounts[$k])) {
        $exactTypeCounts[$k] += $count;
    }
    if ($k === '') {
        $typeCounts['untagged'] = $count;
    } elseif ($k === 'jhs/shs') {
        $typeCounts['jhs'] += $count;
        $typeCounts['shs'] += $count;
    } elseif ($k === 'es/jhs') {
        $typeCounts['elementary'] += $count;
        $typeCounts['jhs'] += $count;
    } elseif ($k === 'es/shs') {
        $typeCounts['elementary'] += $count;
        $typeCounts['shs'] += $count;
    } elseif ($k === 'es/jhs/shs') {
        $typeCounts['elementary'] += $count;
        $typeCounts['jhs'] += $count;
        $typeCounts['shs'] += $count;
    } elseif ($k === 'all offering') {
        $typeCounts['elementary'] += $count;
        $typeCounts['jhs'] += $count;
        $typeCounts['shs'] += $count;
    } elseif (in_array($k, ['public', 'private', 'als', 'elementary', 'jhs', 'shs'], true)) {
        $typeCounts[$k] += $count;
    } elseif (!in_array($k, ['all', 'all offering'], true)) {
        // Unrecognized type, count it as "untagged"
        $typeCounts['untagged'] += $count;
    } elseif (isset($typeCounts[$k])) {
        $typeCounts[$k] = $count;
    }
    $typeCounts['all'] += $count;
}

$inclusiveTypeCounts = [
    'elementary' => ($exactTypeCounts['elementary'] ?? 0) + ($exactTypeCounts['es/jhs'] ?? 0) + ($exactTypeCounts['es/shs'] ?? 0) + ($exactTypeCounts['es/jhs/shs'] ?? 0) + ($exactTypeCounts['all offering'] ?? 0),
    'jhs' => ($exactTypeCounts['jhs'] ?? 0) + ($exactTypeCounts['jhs/shs'] ?? 0) + ($exactTypeCounts['es/jhs'] ?? 0) + ($exactTypeCounts['es/jhs/shs'] ?? 0) + ($exactTypeCounts['all offering'] ?? 0),
    'shs' => ($exactTypeCounts['shs'] ?? 0) + ($exactTypeCounts['jhs/shs'] ?? 0) + ($exactTypeCounts['es/shs'] ?? 0) + ($exactTypeCounts['es/jhs/shs'] ?? 0) + ($exactTypeCounts['all offering'] ?? 0),
];

$headerSchoolTypeCards = [
    ['type' => 'elementary', 'label' => 'Elementary', 'icon' => 'fa-school'],
    ['type' => 'jhs', 'label' => 'JHS', 'icon' => 'fa-graduation-cap'],
    ['type' => 'shs', 'label' => 'SHS', 'icon' => 'fa-user-graduate'],
    ['type' => 'es/jhs', 'label' => 'ES/JHS', 'icon' => 'fa-code-branch'],
    ['type' => 'jhs/shs', 'label' => 'JHS/SHS', 'icon' => 'fa-code-branch'],
    ['type' => 'es/jhs/shs', 'label' => 'ES/JHS/SHS', 'icon' => 'fa-layer-group'],
    ['type' => 'all offering', 'label' => 'ALL OFFERING', 'icon' => 'fa-building-columns'],
    ['type' => 'als', 'label' => 'ALS', 'icon' => 'fa-book-open-reader'],
];

// Apply district filter for no teacher count
$noTeacherParams = [];
$noTeacherWhere = 'WHERE NOT EXISTS (SELECT 1 FROM teachers t WHERE t.school_id = s.id)';
if (shouldFilterByDistrict()) {
    $selectedDistrict = getSessionDistrict();
    if ($selectedDistrict !== null) {
        $noTeacherWhere .= ' AND s.district_id = ?';
        $noTeacherParams = [$selectedDistrict];
    }
}
$noTeacherStmt = $db->prepare("SELECT COUNT(*) FROM schools s $noTeacherWhere");
$noTeacherStmt->execute($noTeacherParams);
$noTeacherCount = (int)$noTeacherStmt->fetchColumn();

// Calculate comprehensive stats
$statsDistrictWhere = '';
$statsDistrictParams = [];
if (shouldFilterByDistrict()) {
    $selectedDistrict = getSessionDistrict();
    if ($selectedDistrict !== null) {
        $statsDistrictWhere = ' WHERE district_id = ?';
        $statsDistrictParams = [$selectedDistrict];
    }
}

// Build stats query with district filter
if ($statsDistrictWhere) {
    $statsStmt = $db->prepare(
        'SELECT
            COUNT(*) AS total_schools,
            COALESCE(SUM(learner_count), 0) AS total_learners,
            (SELECT COUNT(*) FROM teachers t INNER JOIN schools s ON t.school_id = s.id WHERE s.district_id = ?) AS total_teachers,
            COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("elementary", "es", "es/jhs", "es/shs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS elementary_count,
            COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("jhs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/jhs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS jhs_count,
            COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("shs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/shs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS shs_count,
            COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") = "als" THEN 1 ELSE 0 END), 0) AS als_count,
            COALESCE(SUM(CASE WHEN school_type IS NULL OR TRIM(school_type) = "" OR REPLACE(LOWER(TRIM(school_type)), " ", "") NOT IN ("elementary", "es", "jhs", "shs", "es/jhs", "es/shs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/jhs/shs", "alloffering", "als", "public", "private") THEN 1 ELSE 0 END), 0) AS untagged_count
         FROM schools' . $statsDistrictWhere
    );
    $statsDistrictParams[] = $selectedDistrict; // Add second parameter for subquery
    $statsStmt->execute($statsDistrictParams);
} else {
    $statsStmt = $db->prepare(
        'SELECT
            COUNT(*) AS total_schools,
            COALESCE(SUM(learner_count), 0) AS total_learners,
            (SELECT COUNT(*) FROM teachers) AS total_teachers,
            COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("elementary", "es", "es/jhs", "es/shs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS elementary_count,
            COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("jhs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/jhs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS jhs_count,
            COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") IN ("shs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/shs", "es/jhs/shs", "alloffering") THEN 1 ELSE 0 END), 0) AS shs_count,
            COALESCE(SUM(CASE WHEN REPLACE(LOWER(TRIM(COALESCE(school_type, ""))), " ", "") = "als" THEN 1 ELSE 0 END), 0) AS als_count,
            COALESCE(SUM(CASE WHEN school_type IS NULL OR TRIM(school_type) = "" OR REPLACE(LOWER(TRIM(school_type)), " ", "") NOT IN ("elementary", "es", "jhs", "shs", "es/jhs", "es/shs", "jhs/shs", "jhs-shs", "juniorandseniorhighschool", "es/jhs/shs", "alloffering", "als", "public", "private") THEN 1 ELSE 0 END), 0) AS untagged_count
         FROM schools'
    );
    $statsStmt->execute($statsDistrictParams);
}
$statsData = $statsStmt->fetch();

$total  = $db->prepare("SELECT COUNT(*) FROM schools s LEFT JOIN districts d ON s.district_id = d.id $where");
$total->execute($params);
$total  = (int)$total->fetchColumn();
$pag    = paginate($total, $page);

$stmt = $db->prepare(
    "SELECT s.*, d.district_name AS district,
            CONCAT_WS(' ', sh.first_name, sh.last_name) AS school_head_name,
            (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id) AS teacher_count
     FROM schools s
     LEFT JOIN districts d ON s.district_id = d.id
     LEFT JOIN teachers sh ON s.school_head_teacher_id = sh.id
     $where ORDER BY s.school_name LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
$schools = $stmt->fetchAll();
$visibleSchoolHeadCount = 0;
foreach ($schools as $schoolRow) {
    if (trim((string)($schoolRow['school_head_name'] ?? '')) !== '') {
        $visibleSchoolHeadCount++;
    }
}
$schoolsPublishLabel = 'Jun 24, 2026';
$tableColspan = canEdit() ? 11 : 9;

$buildSchoolsUrl = static function(array $overrides = []) use ($type, $staffing, $search, $districtFilter): string {
    $query = [];
    if ($type !== 'all') {
        $query['type'] = $type;
    }
    if ($staffing !== 'all') {
        $query['staffing'] = $staffing;
    }
    if ($search !== '') {
        $query['q'] = $search;
    }
    if ($districtFilter !== '') {
        $query['district'] = $districtFilter;
    }
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
        } else {
            $query[$k] = $v;
        }
    }

    return APP_URL . '/schools.php' . ($query ? '?' . http_build_query($query) : '');
};

$districtSchools = [];
if ($districtFilter !== '') {
    $districtSchoolStmt = $db->prepare(
        'SELECT s.id, s.school_name, s.school_type,
                (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id) AS teacher_count
         FROM schools s
         LEFT JOIN districts d ON s.district_id = d.id
         WHERE d.district_name = ?
         ORDER BY s.school_name'
    );
    $districtSchoolStmt->execute([$districtFilter]);
    $districtSchools = $districtSchoolStmt->fetchAll();
}
?>
<style>
    .schools-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 12px;
        margin-top: 16px;
    }
    .school-stat-link {
        text-decoration: none;
        color: inherit;
        display: block;
    }
    .school-stat-card {
        border: 1px solid rgba(148, 163, 184, .24);
        border-radius: 16px;
        padding: 12px 13px;
        background:
            linear-gradient(165deg, rgba(255,255,255,.15) 0%, rgba(255,255,255,.04) 32%, rgba(255,255,255,0) 66%),
            linear-gradient(180deg, rgba(15, 23, 42, .85), rgba(15, 23, 42, .6));
        box-shadow: inset 0 1px 0 rgba(255,255,255,.12), 0 10px 20px rgba(2, 6, 23, .16);
        transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
    }
    .school-stat-link:hover .school-stat-card {
        transform: translateY(-3px);
        border-color: rgba(56, 189, 248, .42);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.18), 0 14px 28px rgba(2, 6, 23, .24);
    }
    .school-stat-card.is-active {
        border-color: rgba(56, 189, 248, .55);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.18), 0 0 0 1px rgba(56, 189, 248, .2), 0 14px 28px rgba(2, 6, 23, .22);
    }
    .school-stat-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 10px;
    }
    .school-stat-icon {
        width: 28px;
        height: 28px;
        border-radius: 9px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(56, 189, 248, .16);
        color: #67e8f9;
        font-size: .83rem;
        border: 1px solid rgba(56, 189, 248, .3);
    }
    .school-stat-value {
        color: #f8fafc;
        font-size: 1.35rem;
        font-weight: 700;
        line-height: 1;
    }
    .school-stat-label {
        color: #cbd5e1;
        font-size: .74rem;
        letter-spacing: .09em;
        text-transform: uppercase;
        line-height: 1.3;
    }
    @media (max-width: 640px) {
        .schools-stats-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .school-stat-card {
            padding: 11px 11px;
        }
        .school-stat-value {
            font-size: 1.15rem;
        }
        .school-stat-label {
            font-size: .68rem;
        }
    }
    /* Stat card tooltip */
    .school-stat-link {
        position: relative;
        z-index: 1;
    }
    .school-stat-link:hover {
        z-index: 60;
    }
    .school-stat-tooltip {
        position: absolute;
        top: calc(100% + 10px);
        left: 50%;
        transform: translateX(-50%) translateY(-4px);
        min-width: 190px;
        max-width: 250px;
        background: rgba(10, 16, 34, .98);
        border: 1px solid rgba(148, 163, 184, .3);
        border-radius: 12px;
        padding: 11px 14px;
        z-index: 999;
        pointer-events: none;
        box-shadow: 0 12px 32px rgba(2, 6, 23, .55), 0 0 0 1px rgba(56,189,248,.08);
        opacity: 0;
        visibility: hidden;
        transition: opacity .18s ease, transform .18s ease, visibility .18s ease;
        white-space: nowrap;
    }
    .school-stat-tooltip::after {
        content: '';
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        border: 7px solid transparent;
        border-bottom-color: rgba(10, 16, 34, .98);
    }
    .school-stat-link:hover .school-stat-tooltip {
        opacity: 1;
        visibility: visible;
        transform: translateX(-50%) translateY(0);
    }
    .stt-title {
        color: #64748b;
        font-size: .64rem;
        letter-spacing: .1em;
        text-transform: uppercase;
        margin-bottom: 7px;
        font-weight: 700;
        border-bottom: 1px solid rgba(148,163,184,.12);
        padding-bottom: 5px;
    }
    .stt-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        padding: 2.5px 0;
        font-size: .77rem;
        line-height: 1.4;
    }
    .stt-row .stt-k {
        color: #94a3b8;
    }
    .stt-row .stt-v {
        color: #e2e8f0;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        min-width: 28px;
        text-align: right;
    }
    .stt-divider {
        border: none;
        border-top: 1px solid rgba(148,163,184,.14);
        margin: 5px 0;
    }
    .schools-update-banner {
        margin-top: 12px;
        padding: 12px 14px;
        border: 1px solid rgba(56, 189, 248, .28);
        background:
            radial-gradient(circle at 10% 20%, rgba(56, 189, 248, .18), transparent 45%),
            linear-gradient(145deg, rgba(15, 23, 42, .84), rgba(30, 41, 59, .64));
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        border-radius: 14px;
    }
    .schools-update-title {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #e2e8f0;
        font-weight: 700;
        letter-spacing: .01em;
    }
    .schools-update-title i {
        color: #67e8f9;
    }
    .schools-update-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .school-head-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 9px;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, .26);
        background: rgba(30, 41, 59, .34);
        color: #e2e8f0;
        font-size: .78rem;
        max-width: 230px;
    }
    .school-head-chip i {
        color: #93c5fd;
    }
    .school-head-chip span {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .school-head-chip.is-empty {
        color: #94a3b8;
        background: rgba(30, 41, 59, .18);
    }
</style>

<div class="schools-stats-grid">
    <a href="<?= $buildSchoolsUrl(['type' => null, 'staffing' => null, 'page' => null]) ?>" class="school-stat-link">
        <div class="school-stat-card<?= ($type === 'all' && $staffing === 'all') ? ' is-active' : '' ?>">
            <div class="school-stat-head">
                <span class="school-stat-icon"><i class="fas fa-chart-pie"></i></span>
            </div>
            <div class="school-stat-value"><?= number_format($statsData['total_schools']) ?></div>
            <div class="school-stat-label">Total Schools</div>
        </div>
        <div class="school-stat-tooltip">
            <div class="stt-title">All Schools</div>
            <?php foreach ($exactTypeCounts as $ttKey => $ttVal): ?>
            <?php if ($ttVal > 0): ?>
            <div class="stt-row"><span class="stt-k"><?= clean(strtoupper($ttKey)) ?></span><span class="stt-v"><?= number_format($ttVal) ?></span></div>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($statsData['untagged_count'] > 0): ?>
            <hr class="stt-divider">
            <div class="stt-row"><span class="stt-k" style="color:#fb923c">Untagged</span><span class="stt-v" style="color:#fb923c"><?= number_format((int)$statsData['untagged_count']) ?></span></div>
            <?php endif; ?>
        </div>
    </a>
   
    <a href="<?= $buildSchoolsUrl(['type' => 'elementary', 'page' => null]) ?>" class="school-stat-link">
        <div class="school-stat-card<?= $type === 'elementary' ? ' is-active' : '' ?>">
            <div class="school-stat-head">
                <span class="school-stat-icon"><i class="fas fa-school"></i></span>
            </div>
            <div class="school-stat-value"><?= number_format((int)$statsData['elementary_count']) ?></div>
            <div class="school-stat-label">Elementary</div>
        </div>
        <div class="school-stat-tooltip">
            <div class="stt-title">Includes</div>
            <div class="stt-row"><span class="stt-k">Elementary</span><span class="stt-v"><?= number_format($exactTypeCounts['elementary']) ?></span></div>
            <div class="stt-row"><span class="stt-k">ES with JHS</span><span class="stt-v"><?= number_format($exactTypeCounts['es/jhs']) ?></span></div>
            <div class="stt-row"><span class="stt-k">ALL OFFERING</span><span class="stt-v"><?= number_format($exactTypeCounts['all offering']) ?></span></div>
            <hr class="stt-divider">
            <div class="stt-row"><span class="stt-k" style="font-weight:700;color:#f1f5f9">Total</span><span class="stt-v"><?= number_format((int)$statsData['elementary_count']) ?></span></div>
        </div>
    </a>
    <a href="<?= $buildSchoolsUrl(['type' => 'jhs', 'page' => null]) ?>" class="school-stat-link">
        <div class="school-stat-card<?= $type === 'jhs' ? ' is-active' : '' ?>">
            <div class="school-stat-head">
                <span class="school-stat-icon"><i class="fas fa-graduation-cap"></i></span>
            </div>
            <div class="school-stat-value"><?= number_format((int)$statsData['jhs_count']) ?></div>
            <div class="school-stat-label">Junior High School</div>
        </div>
        <div class="school-stat-tooltip">
            <div class="stt-title">Includes</div>
            <div class="stt-row"><span class="stt-k">JHS</span><span class="stt-v"><?= number_format($exactTypeCounts['jhs']) ?></span></div>
            <div class="stt-row"><span class="stt-k">ES with JHS</span><span class="stt-v"><?= number_format($exactTypeCounts['es/jhs']) ?></span></div>
            <div class="stt-row"><span class="stt-k">JHS with SHS</span><span class="stt-v"><?= number_format($exactTypeCounts['jhs/shs']) ?></span></div>
            <div class="stt-row"><span class="stt-k">ALL OFFERING</span><span class="stt-v"><?= number_format($exactTypeCounts['all offering']) ?></span></div>
            <hr class="stt-divider">
            <div class="stt-row"><span class="stt-k" style="font-weight:700;color:#f1f5f9">Total</span><span class="stt-v"><?= number_format((int)$statsData['jhs_count']) ?></span></div>
        </div>
    </a>
    <a href="<?= $buildSchoolsUrl(['type' => 'shs', 'page' => null]) ?>" class="school-stat-link">
        <div class="school-stat-card<?= $type === 'shs' ? ' is-active' : '' ?>">
            <div class="school-stat-head">
                <span class="school-stat-icon"><i class="fas fa-user-graduate"></i></span>
            </div>
            <div class="school-stat-value"><?= number_format((int)$statsData['shs_count']) ?></div>
            <div class="school-stat-label">Senior High School</div>
        </div>
        <div class="school-stat-tooltip">
            <div class="stt-title">Includes</div>
            <div class="stt-row"><span class="stt-k">SHS</span><span class="stt-v"><?= number_format($exactTypeCounts['shs']) ?></span></div>
            <div class="stt-row"><span class="stt-k">JHS with SHS</span><span class="stt-v"><?= number_format($exactTypeCounts['jhs/shs']) ?></span></div>
            <div class="stt-row"><span class="stt-k">ALL OFFERING</span><span class="stt-v"><?= number_format($exactTypeCounts['all offering']) ?></span></div>
            <hr class="stt-divider">
            <div class="stt-row"><span class="stt-k" style="font-weight:700;color:#f1f5f9">Total</span><span class="stt-v"><?= number_format((int)$statsData['shs_count']) ?></span></div>
        </div>
    </a>
    <a href="<?= $buildSchoolsUrl(['type' => 'als', 'page' => null]) ?>" class="school-stat-link">
        <div class="school-stat-card<?= $type === 'als' ? ' is-active' : '' ?>">
            <div class="school-stat-head">
                <span class="school-stat-icon"><i class="fas fa-book-open-reader"></i></span>
            </div>
            <div class="school-stat-value"><?= number_format((int)$statsData['als_count']) ?></div>
            <div class="school-stat-label">ALS</div>
        </div>
        <div class="school-stat-tooltip">
            <div class="stt-title">Breakdown</div>
            <div class="stt-row"><span class="stt-k">ALS</span><span class="stt-v"><?= number_format($exactTypeCounts['als']) ?></span></div>
        </div>
    </a>
    <a href="<?= $buildSchoolsUrl(['type' => 'untagged', 'page' => null]) ?>" class="school-stat-link">
        <div class="school-stat-card<?= $type === 'untagged' ? ' is-active is-active-warn' : '' ?>" style="<?= $statsData['untagged_count'] > 0 ? 'border-color:rgba(251,146,60,.35);' : '' ?>">
            <div class="school-stat-head">
                <span class="school-stat-icon" style="background:rgba(251,146,60,.16);color:#fb923c;border-color:rgba(251,146,60,.3);"><i class="fas fa-circle-exclamation"></i></span>
            </div>
            <div class="school-stat-value"><?= number_format((int)$statsData['untagged_count']) ?></div>
            <div class="school-stat-label">Untagged</div>
        </div>
        <div class="school-stat-tooltip">
            <div class="stt-title">Untagged Schools</div>
            <div class="stt-row"><span class="stt-k">No type assigned</span><span class="stt-v" style="color:#fb923c"><?= number_format((int)$statsData['untagged_count']) ?></span></div>
            <?php if ($statsData['untagged_count'] > 0): ?>
            <hr class="stt-divider">
            <div class="stt-row" style="font-size:.72rem;color:#94a3b8;"><span>Schools with NULL, empty, or unrecognized type</span></div>
            <?php endif; ?>
        </div>
    </a>
 <!-- #region --></div>



<div class="glass-card schools-actionbar" style="margin-top:12px;padding:12px 14px;border:1px solid rgba(148,163,184,.22);background:linear-gradient(160deg, rgba(15,23,42,.88), rgba(30,41,59,.64));display:grid;gap:10px;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span class="badge badge-blue" style="padding:6px 10px;"><i class="fas fa-school"></i> <?= number_format($total) ?> visible</span>
             <span class="badge badge-blue"><i class="fas fa-user-tie"></i> <?= number_format($visibleSchoolHeadCount) ?> with School Head</span>
       
            <span class="badge badge-green" style="padding:6px 10px;"><i class="fas fa-calculator"></i> 1:<?= (int)$defaultLearnersPerTeacher ?> teacher basis</span>
            <?php if ($districtFilter !== ''): ?>
            <span class="badge" style="padding:6px 10px;background:rgba(34,211,238,.14);border:1px solid rgba(34,211,238,.35);color:#a5f3fc;">
                <i class="fas fa-map-pin"></i> <?= clean($districtFilter) ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="filter-actions" style="gap:8px;">
            <a href="<?= APP_URL ?>/requirement_planning.php" class="btn btn-ghost btn-sm">
                <i class="fas fa-diagram-project"></i> Planning
            </a>
            <button type="button" class="btn btn-ghost btn-sm" id="schoolsViewListBtn">
                <i class="fas fa-list"></i> List
            </button>
            <button type="button" class="btn btn-ghost btn-sm" id="schoolsViewCardBtn">
                <i class="fas fa-th-large"></i> Card
            </button>
            <?php if (canEdit()): ?>
            <button type="button" class="btn btn-ghost btn-sm" id="bulkModeTagBtn">
                <i class="fas fa-tags"></i> Tag Mode
            </button>
            <button type="button" class="btn btn-ghost btn-sm" id="bulkModeDeleteBtn">
                <i class="fas fa-trash"></i> Delete Mode
            </button>
            <button type="button" class="btn btn-ghost btn-sm" id="bulkModeOffBtn" style="display:none;">
                <i class="fas fa-xmark"></i> Exit
            </button>
            <?php endif; ?>
        </div>
    </div>

</div>
<div class="filter-bar glass-card">
    <form method="GET" class="filter-form" id="schoolsFilterForm">
        <?php if ($staffing !== 'all'): ?>
        <input type="hidden" name="staffing" value="<?= clean($staffing) ?>">
        <?php endif; ?>
        <?php if ($districtFilter !== ''): ?>
        <input type="hidden" name="district" value="<?= clean($districtFilter) ?>">
        <?php endif; ?>
        <select name="type" id="schoolsTypeFilter" class="form-select" style="max-width:220px;">
            <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All Types</option>
            <option value="elementary" <?= $type === 'elementary' ? 'selected' : '' ?>>Elementary Only</option>
            <option value="pure_elementary" <?= $type === 'pure_elementary' ? 'selected' : '' ?>>Pure Elementary Only</option>
            <option value="jhs" <?= $type === 'jhs' ? 'selected' : '' ?>>JHS Only</option>
            <option value="shs" <?= $type === 'shs' ? 'selected' : '' ?>>SHS Only</option>
            <option value="es/jhs" <?= $type === 'es/jhs' ? 'selected' : '' ?>>ES with JHS</option>
            <option value="jhs/shs" <?= $type === 'jhs/shs' ? 'selected' : '' ?>>JHS with SHS</option>
            <option value="pure_shs" <?= $type === 'pure_shs' ? 'selected' : '' ?>>Pure SHS Only</option>
            <option value="all offering" <?= $type === 'all offering' ? 'selected' : '' ?>>All Offering (ES with JHS with SHS)</option>
            <option value="als" <?= $type === 'als' ? 'selected' : '' ?>>ALS</option>
            <option value="untagged" <?= $type === 'untagged' ? 'selected' : '' ?>>Untagged</option>
        </select>
        <div class="search-box">
            <i class="fas fa-search search-icon"></i>
            <input type="text" name="q" id="schoolsSearchInput" class="form-input" placeholder="Search schools…" value="<?= clean($search) ?>" width="100%" autocomplete="off">
        </div>
        <?php if ($search): ?>
        <a href="<?= $buildSchoolsUrl(['q' => null, 'page' => null]) ?>" class="btn btn-ghost btn-sm" title="Clear search"><i class="fas fa-times"></i></a>
        <?php endif; ?>
    </form>
    <script>
    (function () {
        var form   = document.getElementById('schoolsFilterForm');
        var select = document.getElementById('schoolsTypeFilter');
        var input  = document.getElementById('schoolsSearchInput');
        var timer;

        // Dropdown: submit immediately on change
        select.addEventListener('change', function () {
            form.submit();
        });

        // Search box: submit after 1000 ms of no typing; also allow Enter
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { form.submit(); }, 1000);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                clearTimeout(timer);
                form.submit();
            }
        });
    })();
    </script>
    <?php if (canEdit()): ?>
    <div class="filter-actions">
        <a href="<?= APP_URL ?>/requirement_planning.php" class="btn btn-ghost">
            <i class="fas fa-diagram-project"></i> Teacher Requirement Planning
        </a>
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('bulkUploadSchoolsModal').style.display='flex'">
            <i class="fas fa-file-upload"></i> Bulk Upload
        </button>
        <button class="btn btn-primary" onclick="openSchoolModal()">
            <i class="fas fa-plus"></i> Add School
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if ($districtFilter !== ''): ?>
<div class="glass-card schools-district-panel" style="margin-top:12px;padding:14px 16px;border:1px solid rgba(56,189,248,.28);background:linear-gradient(135deg, rgba(14,116,144,.18), rgba(30,64,175,.12));">
    <div class="schools-district-panel-title" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
        <div style="display:flex;align-items:center;gap:8px;">
            <i class="fas fa-map-location-dot" style="color:#67e8f9;"></i>
            <strong style="color:#e2e8f0;">District: <?= clean($districtFilter) ?></strong>
            <span class="badge badge-blue"><?= number_format(count($districtSchools)) ?> Schools</span>
        </div>
        <a href="<?= $buildSchoolsUrl(['district' => null, 'page' => null]) ?>" class="btn btn-ghost btn-sm">
            <i class="fas fa-xmark"></i> Clear District
        </a>
    </div>
    <?php if ($districtSchools): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
        <?php foreach ($districtSchools as $ds): ?>
        <a href="<?= APP_URL ?>/teachers.php?school=<?= urlencode(encryptId((int)$ds['id'])) ?>"
           class="schools-district-item"
           style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:10px 12px;border-radius:10px;border:1px solid rgba(148,163,184,.25);background:rgba(15,23,42,.35);text-decoration:none;">
            <span class="schools-district-item-name" style="color:#e2e8f0;font-weight:600;"><?= clean($ds['school_name']) ?></span>
            <span class="badge badge-green"><?= number_format((int)$ds['teacher_count']) ?> Teachers</span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="text-muted">No schools found in this district.</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- School Stats Cards -->


<?php if (canEdit()): ?>
<div class="filter-bar glass-card" id="bulkTagPanel" style="display:none;margin-top:10px;padding:10px 14px;justify-content:flex-start;gap:10px;flex-wrap:wrap">
    <span class="text-muted" style="font-size:12px">Bulk Tag Selected:</span>
    <select id="bulkSchoolTypeSelect" class="form-select" style="max-width:180px;">
        <option value="">Choose type</option>
        <option value="Elementary">Elementary</option>
        <option value="JHS">JHS</option>
        <option value="SHS">SHS</option>
        <option value="ES/JHS">ES with JHS</option>
        <option value="JHS/SHS">JHS with SHS</option>
        <option value="ALL OFFERING">ES with JHS with SHS (ALL OFFERING)</option>
        <option value="ALS">ALS</option>
    </select>
    <button type="button" class="btn btn-primary btn-sm" onclick="applyBulkSchoolType()">
        <i class="fas fa-tags"></i> Apply to Selected
    </button>
</div>

<div class="filter-bar glass-card" id="bulkDeletePanel" style="display:none;margin-top:10px;padding:10px 14px;justify-content:flex-start;gap:10px;flex-wrap:wrap">
    <span class="text-muted" style="font-size:12px">Bulk Delete Selected:</span>
    <button type="button" class="btn btn-danger btn-sm" onclick="applyBulkDeleteSchools()">
        <i class="fas fa-trash"></i> Delete Selected Schools
    </button>
</div>
<?php endif; ?>

<div class="table-card glass-card" id="schoolsListView">
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <?php if (canEdit()): ?>
                    <th class="text-center bulk-select-col" style="width:40px;display:none;">
                        <input type="checkbox" id="schoolsSelectAll" onclick="toggleAllSchoolSelections(this)">
                    </th>
                    <?php endif; ?>
                    <th>School Name</th>
                    <th>School ID</th>
                    <th>Municipality</th>
                    <th>Type</th>
                    <th>District</th>
                    <th>School Head</th>
                    <th class="text-center">Teachers</th>
                    <th class="text-center">Learners</th>
                    <th class="text-center">Teacher Need</th>
                    <?php if (canEdit()): ?><th class="text-center">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($schools as $s): ?>
            <?php
                $teacherCount = (int)$s['teacher_count'];
                $learnerCount = (int)$s['learner_count'];
                $currentType = strtolower(trim((string)($s['school_type'] ?? '')));
                $basis = $hasLearnersPerTeacher ? max(1, (int)($s['learners_per_teacher'] ?? $defaultLearnersPerTeacher)) : $defaultLearnersPerTeacher;
                $requiredTeachers = $learnerCount > 0 ? (int)ceil($learnerCount / $basis) : 0;
                $teacherGap = max(0, $requiredTeachers - $teacherCount);
            ?>
            
            <tr>
                <?php if (canEdit()): ?>
                <td class="text-center bulk-select-cell" style="display:none;">
                    <input type="checkbox" class="school-select-item" value="<?= (int)$s['id'] ?>">
                </td>
                <?php endif; ?>
                <td><strong><?= clean($s['school_name']) ?></strong></td>
                <td><?= clean($s['school_id_code'] ?? '—') ?></td>
                <td><?= clean($s['municipality'] ?? '—') ?></td>
                <td><?= clean($s['school_type'] ?? '—') ?></td>
                <td>
                    <?php if (!empty($s['district'])): ?>
                    <a href="<?= $buildSchoolsUrl(['district' => (string)$s['district'], 'page' => null]) ?>" class="badge badge-blue" title="View schools in this district">
                        <?= clean($s['district']) ?>
                    </a>
                    <?php else: ?>
                    —
                    <?php endif; ?>
                </td>

                <td>
                    <?php $schoolHeadName = trim((string)($s['school_head_name'] ?? '')); ?>
                    <span class="school-head-chip<?= $schoolHeadName === '' ? ' is-empty' : '' ?>" title="<?= clean($schoolHeadName !== '' ? $schoolHeadName : 'No school head assigned') ?>">
                        <i class="fas fa-user-tie"></i>
                        <span><?= clean($schoolHeadName !== '' ? $schoolHeadName : 'No School Head') ?></span>
                    </span>
                </td>
                <td class="text-center">
                    <a href="<?= APP_URL ?>/teachers.php?school=<?= urlencode(encryptId((int)$s['id'])) ?>" class="badge badge-blue">
                        <?= number_format((int)$s['teacher_count']) ?>
                    </a>
                </td>
                <td class="text-center"><?= number_format((int)$s['learner_count']) ?></td>
                <td class="text-center">
                    <span class="badge <?= $teacherGap > 0 ? 'badge-danger' : 'badge-green' ?>" title="Based on <?= $basis ?> learners per teacher">
                         <?= number_format($teacherGap) ?>
                    </span>
                </td>
                <?php if (canEdit()): ?>
                <td class="text-center">
                    <?php if (!in_array($currentType, ['elementary', 'es', 'jhs', 'shs', 'jhs/shs', 'es/jhs', 'es/shs', 'es/jhs/shs', 'all offering', 'als', 'public', 'private'], true)): ?>
                    <select class="form-select row-tag-control" style="max-width:120px;display:none;"
                            onchange="if(this.value){tagSchoolType(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(clean($s['school_name']), ENT_QUOTES, 'UTF-8') ?>', this.value); this.value='';}">
                        <option value="">Tag...</option>
                        <option value="Elementary">Elementary</option>
                        <option value="JHS">JHS</option>
                        <option value="SHS">SHS</option>
                        <option value="ES/JHS">ES with JHS</option>
                        <option value="JHS/SHS">JHS with SHS</option>
                        <option value="ALL OFFERING">ES with JHS with SHS (ALL OFFERING)</option>
                        <option value="ALS">ALS</option>
                    </select>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-primary" href="<?= APP_URL ?>/add_teacher.php?school=<?= urlencode(encryptId((int)$s['id'])) ?>" title="Add teacher for this school">
                        <i class="fas fa-user-plus"></i>
                    </a>
                        <button class="btn btn-sm btn-secondary"
                            onclick="editSchool(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(clean($s['school_name']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($s['school_id_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($s['municipality'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($s['school_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($s['als_subtype'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($s['district'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', <?= (int)$s['learner_count'] ?>, <?= $basis ?>, <?= (int)($s['school_head_teacher_id'] ?? 0) ?>, '<?= htmlspecialchars(clean($s['school_year'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', <?= (int)($s['total_sections'] ?? 0) ?>, <?= (int)($s['total_required_classes'] ?? 0) ?>, <?= (float)($s['hours_per_class_week'] ?? PLANNING_DEFAULT_HOURS_PER_CLASS_WEEK) ?>)">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-danger"
                            onclick="confirmDeleteSchool(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(clean($s['school_name']), ENT_QUOTES, 'UTF-8') ?>')">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (!$schools): ?>
            <tr><td colspan="<?= $tableColspan ?>" class="text-center text-muted">No schools found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="school-card-grid" id="schoolsCardView" style="display:none">
    <?php foreach ($schools as $s): ?>
    <?php
        $teacherCount = (int)$s['teacher_count'];
        $learnerCount = (int)$s['learner_count'];
        $basis = $hasLearnersPerTeacher ? max(1, (int)($s['learners_per_teacher'] ?? $defaultLearnersPerTeacher)) : $defaultLearnersPerTeacher;
        $requiredTeachers = $learnerCount > 0 ? (int)ceil($learnerCount / $basis) : 0;
        $teacherGap = max(0, $requiredTeachers - $teacherCount);
    ?>
    <div class="school-card glass-card">
        <div class="school-card-head">
            <h4><?= clean($s['school_name']) ?></h4>
            <span class="badge badge-blue"><?= number_format((int)$s['teacher_count']) ?> Teachers</span>
            <span class="badge badge-green"><?= number_format((int)$s['learner_count']) ?> Learners</span>
            <?php if ($teacherGap > 0): ?>
            <span class="badge badge-danger" title="Based on <?= $basis ?> learners per teacher">Need <?= number_format($teacherGap) ?> Teachers</span>
            <?php else: ?>
            <span class="badge badge-green" title="Based on <?= $basis ?> learners per teacher">Need 0 Teachers</span>
            <?php endif; ?>
        </div>
        <div class="school-card-meta">
            <span><i class="fas fa-id-card"></i> <?= clean($s['school_id_code'] ?? '—') ?></span>
            <span><i class="fas fa-city"></i> <?= clean($s['municipality'] ?? '—') ?></span>
            <span><i class="fas fa-tag"></i> <?= clean($s['school_type'] ?? '—') ?></span>
            <span><i class="fas fa-map-pin"></i>
                <?php if (!empty($s['district'])): ?>
                <a href="<?= $buildSchoolsUrl(['district' => (string)$s['district'], 'page' => null]) ?>" style="color:#93c5fd;text-decoration:none;font-weight:600;">
                    <?= clean($s['district']) ?>
                </a>
                <?php else: ?>
                —
                <?php endif; ?>
            </span>
            <?php $cardSchoolHead = trim((string)($s['school_head_name'] ?? '')); ?>
            <span class="school-head-chip<?= $cardSchoolHead === '' ? ' is-empty' : '' ?>" title="<?= clean($cardSchoolHead !== '' ? $cardSchoolHead : 'No school head assigned') ?>">
                <i class="fas fa-user-tie"></i>
                <span><?= clean($cardSchoolHead !== '' ? $cardSchoolHead : 'No School Head') ?></span>
            </span>
        </div>
        <div class="school-card-actions">
            <a href="<?= APP_URL ?>/teachers.php?school=<?= urlencode(encryptId((int)$s['id'])) ?>" class="btn btn-sm btn-ghost">
                <i class="fas fa-users"></i> View Teachers
            </a>
            <?php if (canEdit()): ?>
            <?php $cardCurrentType = strtolower(trim((string)($s['school_type'] ?? ''))); ?>
            <?php if (!in_array($cardCurrentType, ['elementary', 'es', 'jhs', 'shs', 'jhs/shs', 'es/jhs', 'es/shs', 'es/jhs/shs', 'all offering', 'als', 'public', 'private'], true)): ?>
            <select class="form-select" style="max-width:160px;"
                    onchange="if(this.value){tagSchoolType(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(clean($s['school_name']), ENT_QUOTES, 'UTF-8') ?>', this.value); this.value='';}">
                <option value="">Quick Tag...</option>
                <option value="Elementary">Elementary</option>
                <option value="JHS">JHS</option>
                <option value="SHS">SHS</option>
                <option value="ES/JHS">ES with JHS</option>
                <option value="JHS/SHS">JHS with SHS</option>
                <option value="ALL OFFERING">ES with JHS with SHS (ALL OFFERING)</option>
                <option value="ALS">ALS</option>
            </select>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/add_teacher.php?school=<?= urlencode(encryptId((int)$s['id'])) ?>" class="btn btn-sm btn-primary">
                <i class="fas fa-user-plus"></i> Add Teacher
            </a>
                <button class="btn btn-sm btn-secondary"
                    onclick="editSchool(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(clean($s['school_name']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($s['school_id_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($s['municipality'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($s['school_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($s['als_subtype'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($s['district'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', <?= (int)$s['learner_count'] ?>, <?= $basis ?>, <?= (int)($s['school_head_teacher_id'] ?? 0) ?>, '<?= htmlspecialchars(clean($s['school_year'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', <?= (int)($s['total_sections'] ?? 0) ?>, <?= (int)($s['total_required_classes'] ?? 0) ?>, <?= (float)($s['hours_per_class_week'] ?? PLANNING_DEFAULT_HOURS_PER_CLASS_WEEK) ?>)">
                <i class="fas fa-edit"></i>
            </button>
            <button class="btn btn-sm btn-danger"
                    onclick="confirmDeleteSchool(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(clean($s['school_name']), ENT_QUOTES, 'UTF-8') ?>')">
                <i class="fas fa-trash"></i>
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$schools): ?>
    <div class="empty-state glass-card">
        <i class="fas fa-school fa-3x"></i>
        <p>No schools found.</p>
    </div>
    <?php endif; ?>
</div>
<?= paginationLinks($pag, APP_URL . '/' . basename($_SERVER['PHP_SELF']) . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>

<!-- Bulk Upload Schools Modal -->
<div class="modal-overlay" id="bulkUploadSchoolsModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-header">
            <h3 class="modal-title">Bulk Upload Schools</h3>
            <button class="modal-close" onclick="document.getElementById('bulkUploadSchoolsModal').style.display='none'">×</button>
        </div>
        <form method="POST" action="<?= APP_URL ?>/actions/process_school_upload.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="form-group" style="margin-bottom:8px">
                <a href="<?= APP_URL ?>/assets/templates/school_upload_template.csv" class="btn btn-ghost btn-sm" download>
                    <i class="fas fa-download"></i> Download Sample CSV
                </a>
            </div>
            <div class="form-group" style="font-size:13px;color:var(--text-muted)">
                Required headers: <strong>School Name</strong> and <strong>School ID Code</strong>.
                Optional but supported: <strong>District</strong>, <strong>Municipality</strong>, <strong>School Type</strong>, and <strong>ALS Subtype</strong>.
            </div>
            <div class="form-group">
                <label class="form-label required">Upload File (.xlsx, .xls, .csv)</label>
                <input type="file" name="upload_file" class="form-input" accept=".xlsx,.xls,.csv" required>
            </div>
            <div class="form-group" style="display:flex;gap:12px;flex-wrap:wrap">
                <label><input type="checkbox" name="skip_duplicates" value="1" checked> Skip duplicates</label>
                <label><input type="checkbox" name="update_existing" value="1"> Update existing</label>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('bulkUploadSchoolsModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- Add/Edit School Modal -->
<div class="modal-overlay" id="addSchoolModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-header">
            <h3 class="modal-title" id="schoolModalTitle">Add School</h3>
            <button class="modal-close" onclick="closeSchoolModal()">×</button>
        </div>
        <form method="POST" action="<?= APP_URL ?>/actions/save_school.php" id="schoolForm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="id" id="schoolId" value="">
            <div class="form-group">
                <label class="form-label required">School Name</label>
                <input type="text" name="school_name" id="schoolName" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">School ID Code</label>
                <input type="text" name="school_id_code" id="schoolIdCode" class="form-input" placeholder="e.g. 123456">
            </div>
            <div class="form-group">
                <label class="form-label">Municipality</label>
                <input type="text" name="municipality" id="schoolMunicipality" class="form-input" placeholder="e.g. Baler">
            </div>
            <div class="form-group">
                <label class="form-label">School Type</label>
                <select name="school_type" id="schoolType" class="form-input" onchange="toggleAlsSubtypeField()">
                    <option value="">Select type</option>
                    <option value="Public">Public</option>
                    <option value="Private">Private</option>
                    <option value="Elementary">Elementary</option>
                    <option value="JHS">JHS</option>
                    <option value="SHS">SHS</option>
                    <option value="ES/JHS">ES with JHS</option>
                    <option value="JHS/SHS">JHS with SHS</option>
                    <option value="ALL OFFERING">ES with JHS with SHS (ALL OFFERING)</option>
                    <option value="ALS">ALS</option>
                </select>
            </div>
            <div class="form-group" id="alsSubtypeField" style="display:none">
                <label class="form-label">ALS Subtype</label>
                <select name="als_subtype" id="schoolAlsSubtype" class="form-input">
                    <option value="">Select ALS subtype</option>
                    <option value="CBLC">CBLC - Community-Based Community Learning Centers</option>
                    <option value="SBLC">SBLC - School-Based Learning Centers</option>
                    <option value="ALS-SHS">ALS-SHS - ALS Senior High School</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">District</label>
                <input type="text" name="district" id="schoolDistrict" class="form-input" placeholder="e.g. District I">
            </div>
            <div class="form-group">
                <label class="form-label">School Head</label>
                <input type="hidden" name="school_head_teacher_id" id="schoolHeadTeacherId" value="">
                <div id="schoolHeadDropdown" style="position:relative;">
                    <button type="button" id="schoolHeadTrigger" class="form-input"
                            style="width:100%;display:flex;justify-content:space-between;align-items:center;text-align:left;color:#0f172a;background:#ffffff;border:1px solid #94a3b8;"
                            onclick="toggleSchoolHeadDropdown()">
                        <span id="schoolHeadTriggerText">Select school head</span>
                        <i class="fas fa-chevron-down" style="font-size:12px;color:#64748b;"></i>
                    </button>
                    <div id="schoolHeadMenu" style="display:none;position:absolute;z-index:1200;left:0;right:0;top:calc(100% + 6px);background:#ffffff;border:1px solid #94a3b8;border-radius:10px;box-shadow:0 12px 28px rgba(15,23,42,.18);padding:8px;">
                        <input type="text" id="schoolHeadSearch" class="form-input" placeholder="Search teacher name or position..." oninput="renderSchoolHeadOptions()" style="margin-bottom:8px;color:#0f172a;background:#ffffff;border:1px solid #cbd5e1;">
                        <div id="schoolHeadOptions" style="max-height:220px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;"></div>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Learner Count</label>
                <input type="number" name="learner_count" id="schoolLearnerCount" class="form-input" min="0" placeholder="0" value="0">
            </div>
            <div class="form-group">
                <label class="form-label">Learners Per Teacher Basis</label>
                <input type="number" name="learners_per_teacher" id="schoolLearnersPerTeacher" class="form-input" min="1" max="200" placeholder="35" value="<?= (int)$defaultLearnersPerTeacher ?>">
                <small class="text-muted">Used to compute teacher need for this school.</small>
            </div>
            <div class="form-group">
                <label class="form-label">School Year</label>
                <input type="text" name="school_year" id="schoolYear" class="form-input" placeholder="e.g. 2026-2027">
            </div>
            <div class="form-group">
                <label class="form-label">Total Sections</label>
                <input type="number" name="total_sections" id="schoolTotalSections" class="form-input" min="0" value="0">
            </div>
            <div class="form-group">
                <label class="form-label">Total Required Classes</label>
                <input type="number" name="total_required_classes" id="schoolRequiredClasses" class="form-input" min="0" value="0">
            </div>
            <div class="form-group">
                <label class="form-label">Hours per Class per Week</label>
                <input type="number" name="hours_per_class_week" id="schoolHoursPerClassWeek" class="form-input" min="0.5" max="20" step="0.5" value="<?= (float)PLANNING_DEFAULT_HOURS_PER_CLASS_WEEK ?>">
            </div>
            <input type="hidden" name="confirm_password" id="schoolConfirmPassword" value="">
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeSchoolModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save School</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirm -->
<div class="modal-overlay" id="deleteSchoolModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 class="modal-title">Delete School</h3>
        <p class="modal-body">Are you sure you want to delete <strong id="deleteSchoolName"></strong>? Teachers assigned to this school will be unassigned.</p>
        <div class="modal-actions">
            <button onclick="document.getElementById('deleteSchoolModal').style.display='none'" class="btn btn-ghost">Cancel</button>
            <form method="POST" action="<?= APP_URL ?>/actions/delete_school.php">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="id" id="deleteSchoolId">
                <input type="hidden" name="confirm_password" id="deleteSchoolConfirmPassword">
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    </div>
</div>

<form id="tagSchoolTypeForm" method="POST" action="<?= APP_URL ?>/actions/tag_school_type.php" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="id" id="tagSchoolId" value="">
    <input type="hidden" name="school_type" id="tagSchoolTypeValue" value="">
    <input type="hidden" name="confirm_password" id="tagSchoolConfirmPassword" value="">
    <input type="hidden" name="return_query" value="<?= clean((string)($_SERVER['QUERY_STRING'] ?? '')) ?>">
    <div id="bulkSchoolIdsContainer"></div>
</form>

<form id="deleteSchoolBulkForm" method="POST" action="<?= APP_URL ?>/actions/delete_school.php" style="display:none">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="confirm_password" id="bulkDeleteConfirmPassword" value="">
    <input type="hidden" name="return_query" value="<?= clean((string)($_SERVER['QUERY_STRING'] ?? '')) ?>">
    <div id="bulkDeleteSchoolIdsContainer"></div>
</form>


<script>
const schoolHeadOptionsSeed = <?= json_encode(array_map(static function(array $head): array {
    $headName = trim(((string)$head['last_name']) . ', ' . ((string)$head['first_name']));
    $headLabel = $headName . (!empty($head['position']) ? ' (' . (string)$head['position'] . ')' : '');
    return [
        'value' => (string)((int)$head['id']),
        'text' => $headLabel,
    ];
}, $schoolHeads), JSON_UNESCAPED_UNICODE) ?>;

function toggleAlsSubtypeField() {
    const schoolType = document.getElementById('schoolType').value;
    const alsSubtypeField = document.getElementById('alsSubtypeField');
    if (schoolType === 'ALS') {
        alsSubtypeField.style.display = 'block';
    } else {
        alsSubtypeField.style.display = 'none';
    }
}

function openSchoolModal() {
    document.getElementById('schoolModalTitle').textContent = 'Add School';
    document.getElementById('schoolForm').reset();
    document.getElementById('schoolId').value = '';
    document.getElementById('schoolHeadSearch').value = '';
    setSchoolHeadValue('');
    renderSchoolHeadOptions();
    closeSchoolHeadDropdown();
    document.getElementById('schoolConfirmPassword').value = '';
    toggleAlsSubtypeField();
    document.getElementById('addSchoolModal').style.display = 'flex';
}

function editSchool(id, name, code, municipality, schoolType, alsSubtype, district, learnerCount, learnersPerTeacher, schoolHeadTeacherId, schoolYear, totalSections, totalRequiredClasses, hoursPerClassWeek) {
    document.getElementById('schoolModalTitle').textContent = 'Edit School';
    document.getElementById('schoolId').value       = id;
    document.getElementById('schoolName').value     = name;
    document.getElementById('schoolIdCode').value   = code;
    document.getElementById('schoolMunicipality').value = municipality;
    document.getElementById('schoolType').value = schoolType;
    document.getElementById('schoolAlsSubtype').value = alsSubtype;
    document.getElementById('schoolDistrict').value = district;
    document.getElementById('schoolLearnerCount').value = learnerCount || 0;
    document.getElementById('schoolLearnersPerTeacher').value = learnersPerTeacher || <?= (int)$defaultLearnersPerTeacher ?>;
    document.getElementById('schoolYear').value = schoolYear || '';
    document.getElementById('schoolTotalSections').value = totalSections || 0;
    document.getElementById('schoolRequiredClasses').value = totalRequiredClasses || 0;
    document.getElementById('schoolHoursPerClassWeek').value = hoursPerClassWeek || <?= (float)PLANNING_DEFAULT_HOURS_PER_CLASS_WEEK ?>;
    document.getElementById('schoolHeadSearch').value = '';
    setSchoolHeadValue(schoolHeadTeacherId || '');
    renderSchoolHeadOptions();
    closeSchoolHeadDropdown();
    document.getElementById('schoolConfirmPassword').value = '';
    toggleAlsSubtypeField();
    document.getElementById('addSchoolModal').style.display = 'flex';
}
function closeSchoolModal() {
    document.getElementById('addSchoolModal').style.display = 'none';
    document.getElementById('schoolForm').reset();
    document.getElementById('schoolId').value = '';
    document.getElementById('schoolModalTitle').textContent = 'Add School';
    document.getElementById('schoolHeadSearch').value = '';
    setSchoolHeadValue('');
    renderSchoolHeadOptions();
    closeSchoolHeadDropdown();
    document.getElementById('schoolConfirmPassword').value = '';
    toggleAlsSubtypeField();
}
function confirmDeleteSchool(id, name) {
    document.getElementById('deleteSchoolName').textContent = name;
    document.getElementById('deleteSchoolId').value = id;
    document.getElementById('deleteSchoolModal').style.display = 'flex';
}

function toggleAllSchoolSelections(source) {
    document.querySelectorAll('.school-select-item').forEach((el) => {
        el.checked = source.checked;
    });
}

function normalizeSchoolHeadText(value) {
    return String(value || '').toLowerCase().trim();
}

const schoolHeadOptionsCache = Array.isArray(schoolHeadOptionsSeed)
    ? schoolHeadOptionsSeed.map((opt) => ({ value: String(opt.value || ''), text: String(opt.text || '') }))
    : [];

function getSchoolHeadOptionByValue(value) {
    const v = String(value || '');
    return schoolHeadOptionsCache.find((opt) => opt.value === v) || null;
}

function setSchoolHeadValue(value) {
    const selectInput = document.getElementById('schoolHeadTeacherId');
    const triggerText = document.getElementById('schoolHeadTriggerText');
    if (!selectInput || !triggerText) return;

    const v = String(value || '');
    selectInput.value = v;
    const option = getSchoolHeadOptionByValue(v);
    triggerText.textContent = option ? option.text : 'Select school head';
}

function toggleSchoolHeadDropdown() {
    const menu = document.getElementById('schoolHeadMenu');
    if (!menu) return;
    if (menu.style.display === 'none' || menu.style.display === '') {
        menu.style.display = 'block';
        document.getElementById('schoolHeadSearch')?.focus();
        renderSchoolHeadOptions();
    } else {
        closeSchoolHeadDropdown();
    }
}

function closeSchoolHeadDropdown() {
    const menu = document.getElementById('schoolHeadMenu');
    if (menu) menu.style.display = 'none';
}

function chooseSchoolHead(value) {
    setSchoolHeadValue(value);
    closeSchoolHeadDropdown();
}

function renderSchoolHeadOptions() {
    const searchInput = document.getElementById('schoolHeadSearch');
    const wrap = document.getElementById('schoolHeadOptions');
    const selectedInput = document.getElementById('schoolHeadTeacherId');
    if (!searchInput || !wrap || !selectedInput) return;

    const query = normalizeSchoolHeadText(searchInput.value);
    const selectedValue = String(selectedInput.value || '');
    const filtered = schoolHeadOptionsCache.filter((opt) => query === '' || normalizeSchoolHeadText(opt.text).includes(query));

    let html = '<button type="button" class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;border-radius:0;" onclick="chooseSchoolHead(\'\')">No School Head</button>';
    if (filtered.length === 0) {
        html += '<div style="padding:10px 12px;color:#64748b;font-size:13px;">No matching teacher found.</div>';
    } else {
        html += filtered.map((opt) => {
            const active = opt.value === selectedValue;
            return '<button type="button" class="btn btn-ghost btn-sm" style="width:100%;justify-content:flex-start;border-radius:0;' + (active ? 'background:rgba(14,165,233,.12);font-weight:700;color:#0c4a6e;' : '') + '" onclick="chooseSchoolHead(' + "'" + opt.value.replace(/'/g, "\\'") + "'" + ')">' +
                opt.text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') +
            '</button>';
        }).join('');
    }
    wrap.innerHTML = html;
}

document.addEventListener('click', function (event) {
    const dropdown = document.getElementById('schoolHeadDropdown');
    if (!dropdown) return;
    if (!dropdown.contains(event.target)) {
        closeSchoolHeadDropdown();
    }
});

function setSchoolsBulkMode(mode) {
    const tagPanel = document.getElementById('bulkTagPanel');
    const deletePanel = document.getElementById('bulkDeletePanel');
    const selectCols = document.querySelectorAll('.bulk-select-col, .bulk-select-cell');
    const rowTagControls = document.querySelectorAll('.row-tag-control');
    const selectAll = document.getElementById('schoolsSelectAll');
    const tagBtn = document.getElementById('bulkModeTagBtn');
    const deleteBtn = document.getElementById('bulkModeDeleteBtn');
    const offBtn = document.getElementById('bulkModeOffBtn');

    const isTag = mode === 'tag';
    const isDelete = mode === 'delete';
    const showSelectors = isTag || isDelete;

    if (tagPanel) tagPanel.style.display = isTag ? 'flex' : 'none';
    if (deletePanel) deletePanel.style.display = isDelete ? 'flex' : 'none';
    selectCols.forEach((el) => { el.style.display = showSelectors ? '' : 'none'; });
    rowTagControls.forEach((el) => { el.style.display = isTag ? 'inline-block' : 'none'; });

    if (!showSelectors) {
        if (selectAll) selectAll.checked = false;
        document.querySelectorAll('.school-select-item').forEach((el) => { el.checked = false; });
    }

    if (tagBtn) {
        tagBtn.classList.toggle('btn-primary', isTag);
        tagBtn.classList.toggle('btn-ghost', !isTag);
    }
    if (deleteBtn) {
        deleteBtn.classList.toggle('btn-danger', isDelete);
        deleteBtn.classList.toggle('btn-ghost', !isDelete);
    }
    if (offBtn) offBtn.style.display = showSelectors ? '' : 'none';
}

async function tagSchoolType(id, name, schoolType) {
    const pwd = await promptSchoolPassword('Enter your password to tag "' + name + '" as ' + schoolType + ':');
    if (!pwd) return;
    document.getElementById('tagSchoolId').value = id;
    document.getElementById('tagSchoolTypeValue').value = schoolType;
    document.getElementById('tagSchoolConfirmPassword').value = pwd;
    document.getElementById('bulkSchoolIdsContainer').innerHTML = '';
    document.getElementById('tagSchoolTypeForm').submit();
}

async function applyBulkSchoolType() {
    const selectEl = document.getElementById('bulkSchoolTypeSelect');
    const schoolType = selectEl ? selectEl.value : '';
    
    if (!schoolType) {
        if (typeof Swal !== 'undefined') {
            await Swal.fire({ icon: 'warning', title: 'Select a type', text: 'Choose Elementary, JHS, SHS, or ALS first.' });
        } else {
            alert('Choose a school type first.');
        }
        return;
    }

    const selected = Array.from(document.querySelectorAll('.school-select-item:checked')).map((el) => el.value);
    if (selected.length === 0) {
        if (typeof Swal !== 'undefined') {
            await Swal.fire({ icon: 'warning', title: 'No schools selected', text: 'Select at least one school or use Select All.' });
        } else {
            alert('Select at least one school.');
        }
        return;
    }

    const pwd = await promptSchoolPassword('Enter your password to tag ' + selected.length + ' selected school(s) as ' + schoolType + ':');
    if (!pwd) return;

    const form = document.getElementById('tagSchoolTypeForm');
    if (!form) {
        console.error('tagSchoolTypeForm not found');
        alert('Form error. Please refresh and try again.');
        return;
    }

    const idsWrap = document.getElementById('bulkSchoolIdsContainer');
    if (!idsWrap) {
        console.error('bulkSchoolIdsContainer not found');
        alert('Form container error. Please refresh and try again.');
        return;
    }

    idsWrap.innerHTML = '';
    selected.forEach((id) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'school_ids[]';
        input.value = id;
        idsWrap.appendChild(input);
    });

    const tagIdEl = document.getElementById('tagSchoolId');
    const tagTypeEl = document.getElementById('tagSchoolTypeValue');
    const tagPwdEl = document.getElementById('tagSchoolConfirmPassword');

    if (tagIdEl) tagIdEl.value = '';
    if (tagTypeEl) tagTypeEl.value = schoolType;
    if (tagPwdEl) tagPwdEl.value = pwd;

    // Use setTimeout to ensure DOM updates before submit
    setTimeout(() => {
        form.submit();
    }, 100);
}

async function applyBulkDeleteSchools() {
    const selected = Array.from(document.querySelectorAll('.school-select-item:checked')).map((el) => el.value);
    if (selected.length === 0) {
        if (typeof Swal !== 'undefined') {
            await Swal.fire({ icon: 'warning', title: 'No schools selected', text: 'Select at least one school or use Select All.' });
        } else {
            alert('Select at least one school.');
        }
        return;
    }

    const pwd = await promptSchoolPassword('Enter your password to delete ' + selected.length + ' selected school(s):');
    if (!pwd) return;

    const form = document.getElementById('deleteSchoolBulkForm');
    if (!form) {
        console.error('deleteSchoolBulkForm not found');
        alert('Form error. Please refresh and try again.');
        return;
    }

    const idsWrap = document.getElementById('bulkDeleteSchoolIdsContainer');
    if (!idsWrap) {
        console.error('bulkDeleteSchoolIdsContainer not found');
        alert('Form container error. Please refresh and try again.');
        return;
    }

    idsWrap.innerHTML = '';
    selected.forEach((id) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'school_ids[]';
        input.value = id;
        idsWrap.appendChild(input);
    });

    const pwdEl = document.getElementById('bulkDeleteConfirmPassword');
    if (pwdEl) pwdEl.value = pwd;

    // Use setTimeout to ensure DOM updates before submit
    setTimeout(() => {
        form.submit();
    }, 100);
}

async function promptSchoolPassword(message) {
    if (typeof Swal !== 'undefined') {
        const res = await Swal.fire({
            title: 'Confirm Password',
            text: message,
            input: 'password',
            inputPlaceholder: 'Current password',
            inputAttributes: { autocomplete: 'current-password', autocapitalize: 'off', autocorrect: 'off' },
            showCancelButton: true,
            confirmButtonText: 'Continue',
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

document.getElementById('schoolForm')?.addEventListener('submit', async function(e) {
    if (this.dataset.confirmed === '1') return;
    const schoolId = document.getElementById('schoolId').value;
    if (!schoolId) return;
    e.preventDefault();
    const pwd = await promptSchoolPassword('Enter your password to save changes to this school:');
    if (!pwd) return;
    document.getElementById('schoolConfirmPassword').value = pwd;
    this.dataset.confirmed = '1';
    this.submit();
});

document.getElementById('deleteSchoolModal')?.closest('body');
document.querySelector('#deleteSchoolModal form')?.addEventListener('submit', async function(e) {
    if (this.dataset.confirmed === '1') return;
    e.preventDefault();
    const pwd = await promptSchoolPassword('Enter your password to delete this school:');
    if (!pwd) return;
    document.getElementById('deleteSchoolConfirmPassword').value = pwd;
    this.dataset.confirmed = '1';
    this.submit();
});

function setSchoolsView(mode) {
    const listWrap = document.getElementById('schoolsListView');
    const cardWrap = document.getElementById('schoolsCardView');
    const listBtn  = document.getElementById('schoolsViewListBtn');
    const cardBtn  = document.getElementById('schoolsViewCardBtn');

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
    localStorage.setItem('schoolsViewMode', mode);
}

document.getElementById('schoolsViewListBtn').addEventListener('click', () => setSchoolsView('list'));
document.getElementById('schoolsViewCardBtn').addEventListener('click', () => setSchoolsView('card'));
document.getElementById('bulkModeTagBtn')?.addEventListener('click', () => setSchoolsBulkMode('tag'));
document.getElementById('bulkModeDeleteBtn')?.addEventListener('click', () => setSchoolsBulkMode('delete'));
document.getElementById('bulkModeOffBtn')?.addEventListener('click', () => setSchoolsBulkMode('none'));

const savedSchoolsViewMode = localStorage.getItem('schoolsViewMode') || 'list';
const initialSchoolsViewMode = window.matchMedia('(max-width: 640px)').matches ? 'card' : savedSchoolsViewMode;
setSchoolsView(initialSchoolsViewMode);
setSchoolsBulkMode('none');
setSchoolHeadValue(document.getElementById('schoolHeadTeacherId')?.value || '');
renderSchoolHeadOptions();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
