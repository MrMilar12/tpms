<?php
$pageTitle = 'Retirement Watch';
require_once __DIR__ . '/includes/header.php';

// Require user to have selected a role
requireRoleSelection();

$db = getDB();

$search = clean(trim((string)($_GET['q'] ?? '')));
$filterDist = trim((string)($_GET['dist'] ?? ''));
$filterSchoolRaw = trim((string)($_GET['school'] ?? ''));
$filterStatus = strtolower(trim((string)($_GET['status'] ?? 'all')));
$page = max(1, (int)($_GET['page'] ?? 1));

if (strlen($search) > 500 || strlen($filterDist) > 255) {
    flash('error', 'Filter parameters are too long.');
    redirect(APP_URL . '/retirement_watch.php');
}

$allowedStatus = ['all', 'due12', 'past65', 'to65'];
if (!in_array($filterStatus, $allowedStatus, true)) {
    $filterStatus = 'all';
}

$filterSchool = 0;
$filterSchoolParam = '';
if ($filterSchoolRaw !== '') {
    if (ctype_digit($filterSchoolRaw)) {
        $filterSchool = (int)$filterSchoolRaw;
    } else {
        $decoded = decryptId($filterSchoolRaw);
        if ($decoded === false) {
            flash('error', 'Invalid school filter.');
            redirect(APP_URL . '/retirement_watch.php');
        }
        $filterSchool = (int)$decoded;
    }

    if ($filterSchool > 0) {
        if (shouldFilterByDistrict()) {
            // For PSDS/SDC users, verify school belongs to their district
            $userDistrictId = (int)getSessionDistrict();
            $st = $db->prepare('SELECT id FROM schools WHERE id = ? AND district_id = ? LIMIT 1');
            $st->execute([$filterSchool, $userDistrictId]);
        } else {
            // For admins/hr, just verify school exists
            $st = $db->prepare('SELECT id FROM schools WHERE id = ? LIMIT 1');
            $st->execute([$filterSchool]);
        }
        if (!$st->fetchColumn()) {
            flash('error', 'School filter is invalid.');
            redirect(APP_URL . '/retirement_watch.php');
        }
        $filterSchoolParam = encryptId($filterSchool);
    }
}

$where = [
    "t.birthdate IS NOT NULL",
    "t.birthdate <> '0000-00-00'",
    "TIMESTAMPDIFF(YEAR, t.birthdate, CURDATE()) BETWEEN 59 AND 65",
];
$params = [];

if ($search !== '') {
    $where[] = '(t.employee_number LIKE ? OR t.last_name LIKE ? OR t.first_name LIKE ? OR t.position LIKE ? OR COALESCE(s.school_name, t.school_name_raw) LIKE ?)';
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

// Apply district filter
if (shouldFilterByDistrict()) {
    // Auto-filter by user's assigned district
    $userDistrictId = (int)getSessionDistrict();
    if ($userDistrictId > 0) {
        $where[] = 's.district_id = ?';
        $params[] = $userDistrictId;
    }
} elseif ($filterDist !== '') {
    // Manual filter by district name (for admins/hr)
    $where[] = 'COALESCE(NULLIF(t.district_raw, ""), d.district_name) = ?';
    $params[] = $filterDist;
}

if ($filterSchool > 0) {
    $where[] = 't.school_id = ?';
    $params[] = $filterSchool;
}
if ($filterStatus === 'due12') {
    $where[] = 'TIMESTAMPDIFF(MONTH, CURDATE(), DATE_ADD(t.birthdate, INTERVAL 65 YEAR)) BETWEEN 0 AND 12';
} elseif ($filterStatus === 'past65') {
    $where[] = 'TIMESTAMPDIFF(MONTH, CURDATE(), DATE_ADD(t.birthdate, INTERVAL 65 YEAR)) < 0';
} elseif ($filterStatus === 'to65') {
    $where[] = 'TIMESTAMPDIFF(MONTH, CURDATE(), DATE_ADD(t.birthdate, INTERVAL 65 YEAR)) >= 0';
}

$whereSql = implode(' AND ', $where);
$baseSql = "FROM teachers t
            LEFT JOIN schools s ON t.school_id = s.id
            LEFT JOIN districts d ON s.district_id = d.id
            WHERE $whereSql";

$totalStmt = $db->prepare("SELECT COUNT(*) $baseSql");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pag = paginate($total, $page);

$dataStmt = $db->prepare(
    "SELECT
        t.id,
        t.employee_number,
        t.first_name,
        t.last_name,
        t.profile_photo,
        t.birthdate,
        t.position,
        COALESCE(s.school_name, t.school_name_raw, 'Unassigned') AS school_name,
        COALESCE(NULLIF(t.district_raw, ''), d.district_name, 'N/A') AS district,
        TIMESTAMPDIFF(YEAR, t.birthdate, CURDATE()) AS age_years,
        TIMESTAMPDIFF(MONTH, CURDATE(), DATE_ADD(t.birthdate, INTERVAL 65 YEAR)) AS months_until_65
     $baseSql
     ORDER BY months_until_65 ASC, t.last_name ASC, t.first_name ASC
     LIMIT ? OFFSET ?"
);
$dataStmt->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
$rows = $dataStmt->fetchAll();

$summaryStmt = $db->prepare(
    "SELECT
        COUNT(*) AS total_watch,
        SUM(CASE WHEN TIMESTAMPDIFF(MONTH, CURDATE(), DATE_ADD(t.birthdate, INTERVAL 65 YEAR)) BETWEEN 0 AND 12 THEN 1 ELSE 0 END) AS due_12,
        SUM(CASE WHEN TIMESTAMPDIFF(MONTH, CURDATE(), DATE_ADD(t.birthdate, INTERVAL 65 YEAR)) < 0 THEN 1 ELSE 0 END) AS past_65
     $baseSql"
);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch() ?: ['total_watch' => 0, 'due_12' => 0, 'past_65' => 0];

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
         FROM districts
         WHERE district_name IS NOT NULL AND district_name <> ""
         ORDER BY district_name'
    )->fetchAll(PDO::FETCH_COLUMN);
    $schools = $db->query('SELECT id, school_name FROM schools ORDER BY school_name')->fetchAll();
}

$formatMonths = static function(int $months): string {
    if ($months === 0) {
        return 'Turns 65 this month';
    }
    $abs = abs($months);
    $years = intdiv($abs, 12);
    $rem = $abs % 12;
    $parts = [];
    if ($years > 0) {
        $parts[] = $years . ' year' . ($years !== 1 ? 's' : '');
    }
    if ($rem > 0 || $years === 0) {
        $parts[] = $rem . ' month' . ($rem !== 1 ? 's' : '');
    }
    $label = implode(' ', $parts);
    return $months > 0 ? ($label . ' to age 65') : ('Past 65 by ' . $label);
};

$exportParams = http_build_query(array_filter([
    'q' => $search,
    'dist' => $filterDist,
    'school' => $filterSchoolParam !== '' ? $filterSchoolParam : null,
    'status' => $filterStatus !== 'all' ? $filterStatus : null,
]));
$exportSuffix = $exportParams !== '' ? '&' . $exportParams : '';
?>

<style>
.retirement-watch-page {
    display: grid;
    gap: 12px;
}

.retirement-watch-page .retirement-topbar {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 10px;
    padding: 2px 2px 4px;
}

.retirement-watch-page .retirement-topbar h2 {
    margin: 0;
    font-size: 1.2rem;
    letter-spacing: 0.03em;
    color: #f8fafc;
}

.retirement-watch-page .retirement-topbar p {
    margin: 4px 0 0;
    color: #94a3b8;
    font-size: 0.86rem;
}

.retirement-watch-page .stats-grid {
    margin-top: 2px;
}

.retirement-watch-page .filter-bar {
    border: 1px solid rgba(148, 163, 184, 0.28);
    background: linear-gradient(155deg, rgba(15, 23, 42, 0.88), rgba(30, 41, 59, 0.62));
}

.retirement-watch-page .retirement-filter-form {
    display: grid;
    grid-template-columns: 1.4fr 1fr 1fr 1fr auto;
    gap: 8px;
    align-items: end;
}

.retirement-watch-page .retirement-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
}

.retirement-watch-page .retirement-actions-left,
.retirement-watch-page .retirement-actions-right {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.retirement-watch-page .retirement-view-toggle {
    display: inline-flex;
    border: 1px solid rgba(148, 163, 184, 0.34);
    border-radius: 10px;
    overflow: hidden;
}

.retirement-watch-page .retirement-view-btn {
    border: 0;
    border-radius: 0;
    min-width: 84px;
}

.retirement-watch-page .table-card,
.retirement-watch-page #retirementCardView .teacher-card {
    border: 1px solid rgba(148, 163, 184, 0.3);
    box-shadow: 0 14px 34px rgba(2, 6, 23, 0.32);
}

.retirement-watch-page .table-card .card-header {
    border-bottom: 1px solid rgba(148, 163, 184, 0.2);
    background: linear-gradient(180deg, rgba(30, 41, 59, 0.55), rgba(15, 23, 42, 0));
}

.retirement-watch-page #retirementCardView {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
    margin-top: 12px;
}

.retirement-watch-page .teacher-card {
    position: relative;
    padding: 0;
    border-radius: 14px;
    background:
        radial-gradient(circle at 12% 10%, rgba(59, 130, 246, 0.16), transparent 42%),
        radial-gradient(circle at 90% 88%, rgba(249, 115, 22, 0.14), transparent 44%),
        linear-gradient(165deg, rgba(15, 23, 42, 0.78), rgba(15, 23, 42, 0.5));
    overflow: hidden;
    transition: transform .18s ease, box-shadow .18s ease;
}

.retirement-watch-page .teacher-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: linear-gradient(180deg, #38bdf8, #f97316);
    opacity: .9;
}

.retirement-watch-page .teacher-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 18px 40px rgba(2, 6, 23, 0.4);
}

.retirement-watch-page .retirement-card-head,
.retirement-watch-page .retirement-card-body,
.retirement-watch-page .retirement-card-footer {
    position: relative;
    z-index: 1;
    padding: 12px 14px;
}

.retirement-watch-page .retirement-card-head {
    display: flex;
    align-items: center;
    gap: 10px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.22);
    background: linear-gradient(180deg, rgba(30, 41, 59, 0.5), rgba(15, 23, 42, 0.18));
}

.retirement-watch-page .retirement-card-body {
    display: grid;
    gap: 7px;
}

.retirement-watch-page .teacher-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 10px;
}

.retirement-watch-page .retirement-card-avatar {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    overflow: hidden;
    border: 2px solid rgba(148, 163, 184, 0.5);
    background: rgba(51, 65, 85, 0.65);
    flex: 0 0 52px;
    display: grid;
    place-items: center;
}

.retirement-watch-page .retirement-card-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.retirement-watch-page .retirement-card-avatar-placeholder {
    color: #cbd5e1;
    font-weight: 700;
    font-size: 1rem;
    text-transform: uppercase;
}

.retirement-watch-page .teacher-card-title {
    min-width: 0;
    flex: 1 1 auto;
}

.retirement-watch-page .teacher-card-header h4 {
    margin: 0;
    font-size: 1rem;
    letter-spacing: 0.01em;
    line-height: 1.3;
    color: #f8fafc;
    overflow-wrap: anywhere;
}

.retirement-watch-page .teacher-card-sub {
    margin-top: 3px;
    font-size: 0.75rem;
    color: #93c5fd;
    letter-spacing: 0.02em;
    text-transform: uppercase;
}

.retirement-watch-page .teacher-card-header .badge {
    flex: 0 0 auto;
    max-width: 155px;
    text-align: center;
    line-height: 1.2;
    white-space: normal;
}

.retirement-watch-page .teacher-card-details {
    display: grid;
    gap: 7px;
}

.retirement-watch-page .teacher-card-details p {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin: 0;
    font-size: 0.87rem;
    color: #dbe7f6;
}

.retirement-watch-page .teacher-card-details .detail-label {
    min-width: 88px;
    color: #93c5fd;
    font-weight: 600;
}

.retirement-watch-page .teacher-card-details .detail-value {
    flex: 1;
    min-width: 0;
    color: #e2e8f0;
    overflow-wrap: anywhere;
}

.retirement-watch-page .teacher-card-actions {
    margin-top: 12px;
    display: flex;
    justify-content: flex-end;
}

.retirement-watch-page .retirement-card-footer {
    border-top: 1px solid rgba(148, 163, 184, 0.24);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    background: linear-gradient(180deg, rgba(15, 23, 42, 0.14), rgba(15, 23, 42, 0.42));
}

.retirement-watch-page .retirement-card-footer .badge {
    max-width: none;
}

@media (max-width: 980px) {
    .retirement-watch-page .retirement-filter-form {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 640px) {
    .retirement-watch-page .retirement-filter-form {
        grid-template-columns: 1fr;
    }

    .retirement-watch-page .retirement-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .retirement-watch-page .retirement-actions-left,
    .retirement-watch-page .retirement-actions-right {
        width: 100%;
    }
}

/* Align retirement cards with compact teachers-card layout */
.retirement-watch-page #retirementCardView .teacher-card {
    padding: 12px;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 10px;
}

.retirement-watch-page #retirementCardView .tc-photo {
    margin-top: 2px;
}

.retirement-watch-page #retirementCardView .tc-photo img {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    object-fit: cover;
    display: block;
    border: 2px solid rgba(148, 163, 184, 0.45);
}

.retirement-watch-page #retirementCardView .retirement-card-footer {
    grid-column: 1 / -1;
    margin-top: 6px;
    padding-top: 10px;
}
</style>

<div class="retirement-watch-page">

<div class="retirement-topbar">
    <div>
        <h2>RETIREMENT WATCH</h2>
        <p>Teachers aged 59-60, with upcoming and past-60 progression at a glance.</p>
    </div>
</div>

<div class="stats-grid" style="--cols:3">
    <div class="stat-card glass-card">
        <div class="stat-icon icon-blue"><i class="fas fa-user-clock"></i></div>
        <div class="stat-body"><div class="stat-value"><?= number_format((int)$summary['total_watch']) ?></div><div class="stat-label">Retirement Watch (Age 59-60)</div></div>
    </div>
    <div class="stat-card glass-card">
        <div class="stat-icon icon-orange"><i class="fas fa-hourglass-half"></i></div>
        <div class="stat-body"><div class="stat-value"><?= number_format((int)$summary['due_12']) ?></div><div class="stat-label">Reaching 65 in 12 Months</div></div>
    </div>
    <div class="stat-card glass-card">
        <div class="stat-icon icon-red"><i class="fas fa-user-check"></i></div>
        <div class="stat-body"><div class="stat-value"><?= number_format((int)$summary['past_65']) ?></div><div class="stat-label">Already 65+</div></div>
    </div>
</div>

<div class="filter-bar glass-card" style="margin-top:12px;display:grid;gap:10px;">
    <form method="GET" class="filter-form retirement-filter-form">
        <div class="form-group">
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-input" placeholder="Name, employee no., school..." value="<?= clean($search) ?>">
        </div>
        <div class="form-group">
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
        <div class="form-group">
            <label class="form-label">School</label>
            <select name="school" class="form-select">
                <option value="">All Schools</option>
                <?php foreach ($schools as $sc): ?>
                <option value="<?= urlencode(encryptId((int)$sc['id'])) ?>" <?= $filterSchool === (int)$sc['id'] ? 'selected' : '' ?>><?= clean($sc['school_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All</option>
                <option value="due12" <?= $filterStatus === 'due12' ? 'selected' : '' ?>>Within 12 Months</option>
                <option value="to65" <?= $filterStatus === 'to65' ? 'selected' : '' ?>>Not Yet 65</option>
                <option value="past65" <?= $filterStatus === 'past65' ? 'selected' : '' ?>>Past 65</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
    </form>

    <div class="filter-actions retirement-actions">
        <div class="retirement-actions-left">
            <?php if ($search || $filterDist || $filterSchool || $filterStatus !== 'all'): ?>
            <a href="<?= APP_URL ?>/retirement_watch.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Clear Filters</a>
            <?php endif; ?>
            <div class="retirement-view-toggle" role="group" aria-label="Retirement view mode">
                <button type="button" class="btn btn-ghost btn-sm retirement-view-btn" id="retirementViewListBtn">
                    <i class="fas fa-list"></i> List
                </button>
                <button type="button" class="btn btn-ghost btn-sm retirement-view-btn" id="retirementViewCardBtn">
                    <i class="fas fa-th-large"></i> Card
                </button>
            </div>
        </div>
        <?php if (canEdit()): ?>
        <div class="retirement-actions-right">
            <a href="<?= APP_URL ?>/actions/export_retirement_watch.php?format=csv<?= $exportSuffix ?>" class="btn btn-ghost btn-sm"><i class="fas fa-file-csv"></i> Extract CSV</a>
            <a href="<?= APP_URL ?>/actions/export_retirement_watch.php?format=excel<?= $exportSuffix ?>" class="btn btn-ghost btn-sm"><i class="fas fa-file-excel"></i> Extract Excel</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="table-card glass-card" id="retirementListView" style="margin-top:12px;">
    <div class="card-header">
        <h3><i class="fas fa-table"></i> Retirement Watch List</h3>
        <span class="text-muted small"><?= number_format($total) ?> records</span>
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Employee No.</th>
                    <th>Teacher</th>
                    <th>School</th>
                    <th>District</th>
                    <th>Position</th>
                    <th>Birthdate</th>
                    <th>Age</th>
                    <th>Projection to 60</th>
                    <?php if (canEdit()): ?>
                    <th>Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $r): ?>
                <?php
                    $months = (int)($r['months_until_65'] ?? 0);
                    $badge = $months < 0 ? 'badge-danger' : ($months <= 12 ? 'badge-orange' : 'badge-blue');
                    $ageYears = (int)($r['age_years'] ?? 0);
                    $ageLooksInvalid = $ageYears > 100;
                ?>
                <tr>
                    <td><?= $pag['offset'] + $i + 1 ?></td>
                    <td><?= clean((string)($r['employee_number'] ?? '—')) ?></td>
                    <td><?= clean((string)($r['last_name'] ?? '') . ', ' . (string)($r['first_name'] ?? '')) ?></td>
                    <td><?= clean((string)($r['school_name'] ?? '—')) ?></td>
                    <td><?= clean((string)($r['district'] ?? '—')) ?></td>
                    <td><?= clean((string)($r['position'] ?? '—')) ?></td>
                    <td><?= clean(formatDate((string)($r['birthdate'] ?? null))) ?></td>
                    <td>
                        <?= number_format($ageYears) ?>
                        <?php if ($ageLooksInvalid): ?>
                        <span class="badge badge-danger" style="margin-left:6px;">Check DOB</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $badge ?>"><?= clean($formatMonths($months)) ?></span></td>
                    <?php if (canEdit()): ?>
                    <td>
                        <a href="<?= APP_URL ?>/edit_teacher.php?id=<?= urlencode(encryptId((int)$r['id'])) ?>" class="btn btn-sm btn-secondary">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="<?= canEdit() ? '10' : '9' ?>" class="text-center text-muted">No records for retirement watch criteria.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="teacher-card-grid" id="retirementCardView" style="display:none;">
    <?php foreach ($rows as $r): ?>
    <?php
        $months = (int)($r['months_until_65'] ?? 0);
        $badge = $months < 0 ? 'badge-danger' : ($months <= 12 ? 'badge-orange' : 'badge-blue');
        $ageYears = (int)($r['age_years'] ?? 0);
        $ageLooksInvalid = $ageYears > 100;
        $nameLabel = trim((string)($r['last_name'] ?? '') . ', ' . (string)($r['first_name'] ?? ''));
    ?>
    <div class="teacher-card glass-card">
        <div class="tc-photo">
            <?php if (!empty($r['profile_photo'])): ?>
            <img src="<?= UPLOAD_URL . rawurlencode((string)$r['profile_photo']) ?>" alt="Teacher photo">
            <?php else: ?>
            <div class="tc-avatar-placeholder">
                <?= clean(strtoupper(substr((string)($r['last_name'] ?? 'T'), 0, 1))) ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="tc-body">
            <div class="tc-name"><?= clean($nameLabel) ?></div>
            <div class="tc-sub">
                <span class="tc-badge">Employee No: <?= clean((string)($r['employee_number'] ?? '—')) ?></span>
                <span class="tc-badge">Age: <?= number_format($ageYears) ?></span>
                <?php if ($ageLooksInvalid): ?>
                <span class="tc-badge" style="background:rgba(239,68,68,.22);border-color:rgba(239,68,68,.4);color:#fecaca;">Check DOB</span>
                <?php endif; ?>
            </div>
            <div class="tc-info">
                <span class="tc-info-row" title="School"><i class="fas fa-school"></i><span class="tc-key">School</span><span class="tc-value"><?= clean((string)($r['school_name'] ?? '—')) ?></span></span>
                <span class="tc-info-row" title="District"><i class="fas fa-map"></i><span class="tc-key">District</span><span class="tc-value"><?= clean((string)($r['district'] ?? '—')) ?></span></span>
                <span class="tc-info-row" title="Position"><i class="fas fa-briefcase"></i><span class="tc-key">Position</span><span class="tc-value"><?= clean((string)($r['position'] ?? '—')) ?></span></span>
                <span class="tc-info-row" title="Birthdate"><i class="fas fa-calendar-day"></i><span class="tc-key">Birthdate</span><span class="tc-value"><?= clean(formatDate((string)($r['birthdate'] ?? null))) ?></span></span>
            </div>
        </div>
        <div class="retirement-card-footer">
            <span class="badge <?= $badge ?>"><i class="fas fa-bell"></i> <?= clean($formatMonths($months)) ?></span>
            <?php if (canEdit()): ?>
            <a href="<?= APP_URL ?>/edit_teacher.php?id=<?= urlencode(encryptId((int)$r['id'])) ?>" class="btn btn-sm btn-secondary">
                <i class="fas fa-edit"></i> Edit
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
    <div class="empty-state glass-card">
        <i class="fas fa-user-clock fa-3x"></i>
        <p>No records for retirement watch criteria.</p>
    </div>
    <?php endif; ?>
</div>

<?= paginationLinks($pag, APP_URL . '/retirement_watch.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>

</div>

<script>
(function () {
    var listBtn = document.getElementById('retirementViewListBtn');
    var cardBtn = document.getElementById('retirementViewCardBtn');
    var listView = document.getElementById('retirementListView');
    var cardView = document.getElementById('retirementCardView');
    var storageKey = 'retirementWatchView';

    if (!listBtn || !cardBtn || !listView || !cardView) {
        return;
    }

    function activate(mode) {
        var isCard = mode === 'card';
        listView.style.display = isCard ? 'none' : 'block';
        cardView.style.display = isCard ? 'grid' : 'none';
        listBtn.classList.toggle('btn-primary', !isCard);
        listBtn.classList.toggle('btn-ghost', isCard);
        cardBtn.classList.toggle('btn-primary', isCard);
        cardBtn.classList.toggle('btn-ghost', !isCard);
        try {
            localStorage.setItem(storageKey, isCard ? 'card' : 'list');
        } catch (e) {
            // Ignore storage errors.
        }
    }

    listBtn.addEventListener('click', function () { activate('list'); });
    cardBtn.addEventListener('click', function () { activate('card'); });

    var preferred = 'list';
    try {
        preferred = localStorage.getItem(storageKey) || 'list';
    } catch (e) {
        preferred = 'list';
    }
    activate(preferred === 'card' ? 'card' : 'list');
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php';
