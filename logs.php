<?php
$pageTitle = 'Activity Logs';
require_once __DIR__ . '/includes/header.php';
requireRole(['admin']);

$db     = getDB();
$module = trim((string)($_GET['module'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));
$userId = max(0, (int)($_GET['user'] ?? 0));
$page   = max(1, (int)($_GET['page'] ?? 1));

$normalizeDate = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt) {
        return '';
    }
    return $dt->format('Y-m-d') === $value ? $value : '';
};

$dateFrom = $normalizeDate($_GET['date_from'] ?? '');
$dateTo   = $normalizeDate($_GET['date_to'] ?? '');

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$baseWhere  = ['1=1'];
$baseParams = [];
if ($module !== '') { $baseWhere[] = 'module = ?';  $baseParams[] = $module; }
if ($action !== '') { $baseWhere[] = 'action = ?';  $baseParams[] = $action; }
if ($userId > 0)    { $baseWhere[] = 'user_id = ?'; $baseParams[] = $userId; }

$detailWhere  = $baseWhere;
$detailParams = $baseParams;
if ($dateFrom !== '') { $detailWhere[] = 'DATE(created_at) >= ?'; $detailParams[] = $dateFrom; }
if ($dateTo !== '')   { $detailWhere[] = 'DATE(created_at) <= ?'; $detailParams[] = $dateTo; }

$detailWhereStr = implode(' AND ', $detailWhere);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM activity_logs WHERE $detailWhereStr");
$totalStmt->execute($detailParams);
$total = (int)$totalStmt->fetchColumn();
$pag   = paginate($total, $page, 50);

$stmt = $db->prepare(
    "SELECT * FROM activity_logs WHERE $detailWhereStr ORDER BY created_at DESC LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($detailParams, [$pag['per_page'], $pag['offset']]));
$logs = $stmt->fetchAll();

// Summary report defaults to today when no day filter is supplied.
$reportDateFrom = $dateFrom !== '' ? $dateFrom : date('Y-m-d');
$reportDateTo   = $dateTo !== '' ? $dateTo : $reportDateFrom;

$summaryWhere  = $baseWhere;
$summaryParams = $baseParams;
$summaryWhere[] = 'DATE(created_at) >= ?';
$summaryWhere[] = 'DATE(created_at) <= ?';
$summaryParams[] = $reportDateFrom;
$summaryParams[] = $reportDateTo;
$summaryWhereStr = implode(' AND ', $summaryWhere);

$kpiStmt = $db->prepare(
    "SELECT
        COUNT(*) AS total_events,
        COUNT(DISTINCT COALESCE(user_id, 0)) AS users_involved,
        COUNT(DISTINCT action) AS action_types,
        COUNT(DISTINCT module) AS modules_touched,
        MIN(created_at) AS first_event,
        MAX(created_at) AS last_event
     FROM activity_logs
     WHERE $summaryWhereStr"
);
$kpiStmt->execute($summaryParams);
$kpis = $kpiStmt->fetch() ?: [];

$byDayStmt = $db->prepare(
    "SELECT
        DATE(created_at) AS log_day,
        COUNT(*) AS total_events,
        COUNT(DISTINCT COALESCE(user_id, 0)) AS users_involved,
        COUNT(DISTINCT action) AS action_types,
        COUNT(DISTINCT module) AS modules_touched
     FROM activity_logs
     WHERE $summaryWhereStr
     GROUP BY DATE(created_at)
     ORDER BY log_day DESC"
);
$byDayStmt->execute($summaryParams);
$dailySummary = $byDayStmt->fetchAll();

$byActionStmt = $db->prepare(
    "SELECT action, COUNT(*) AS total
     FROM activity_logs
     WHERE $summaryWhereStr
     GROUP BY action
     ORDER BY total DESC, action ASC"
);
$byActionStmt->execute($summaryParams);
$actionSummary = $byActionStmt->fetchAll();

$byModuleStmt = $db->prepare(
    "SELECT module, COUNT(*) AS total
     FROM activity_logs
     WHERE $summaryWhereStr
     GROUP BY module
     ORDER BY total DESC, module ASC"
);
$byModuleStmt->execute($summaryParams);
$moduleSummary = $byModuleStmt->fetchAll();

$byUserStmt = $db->prepare(
    "SELECT COALESCE(NULLIF(user_name, ''), 'Unknown User') AS user_name, COUNT(*) AS total
     FROM activity_logs
     WHERE $summaryWhereStr
     GROUP BY user_id, user_name
     ORDER BY total DESC, user_name ASC
     LIMIT 15"
);
$byUserStmt->execute($summaryParams);
$userSummary = $byUserStmt->fetchAll();

$narrativeEventsStmt = $db->prepare(
    "SELECT created_at, COALESCE(NULLIF(user_name, ''), 'Unknown User') AS user_name, action, module, description
     FROM activity_logs
     WHERE $summaryWhereStr
     ORDER BY created_at DESC
     LIMIT 8"
);
$narrativeEventsStmt->execute($summaryParams);
$narrativeEvents = $narrativeEventsStmt->fetchAll();

$modules = $db->query('SELECT DISTINCT module FROM activity_logs ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);
$actions = $db->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$users   = $db->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll();

$selectedUserLabel = 'All Users';
if ($userId > 0) {
    foreach ($users as $u) {
        if ((int)$u['id'] === $userId) {
            $selectedUserLabel = (string)$u['full_name'];
            break;
        }
    }
}

$isFiltered = ($module !== '' || $action !== '' || $userId > 0 || $dateFrom !== '' || $dateTo !== '');

$pageQuery = $_GET;
unset($pageQuery['page']);
$paginationBase = APP_URL . '/logs.php';
if (!empty($pageQuery)) {
    $paginationBase .= '?' . http_build_query($pageQuery);
}

$narrativeParagraphs = [];
$eventsTotal = (int)($kpis['total_events'] ?? 0);
$usersInvolved = (int)($kpis['users_involved'] ?? 0);
$actionsInvolved = (int)($kpis['action_types'] ?? 0);
$modulesInvolved = (int)($kpis['modules_touched'] ?? 0);

if ($eventsTotal > 0) {
    $narrativeParagraphs[] = sprintf(
        'From %s to %s, the system recorded %s activity event%s involving %s user%s across %s action type%s and %s module%s.',
        $reportDateFrom,
        $reportDateTo,
        number_format($eventsTotal),
        $eventsTotal === 1 ? '' : 's',
        number_format($usersInvolved),
        $usersInvolved === 1 ? '' : 's',
        number_format($actionsInvolved),
        $actionsInvolved === 1 ? '' : 's',
        number_format($modulesInvolved),
        $modulesInvolved === 1 ? '' : 's'
    );

    $topAction = $actionSummary[0] ?? null;
    $topModule = $moduleSummary[0] ?? null;
    $topUser = $userSummary[0] ?? null;

    $focusParts = [];
    if ($topAction) {
        $focusParts[] = sprintf('%s was the most frequent action (%s)', clean((string)$topAction['action']), number_format((int)$topAction['total']));
    }
    if ($topModule) {
        $focusParts[] = sprintf('%s received the highest activity volume (%s)', clean((string)$topModule['module']), number_format((int)$topModule['total']));
    }
    if ($topUser) {
        $focusParts[] = sprintf('%s generated the most logged actions (%s)', clean((string)$topUser['user_name']), number_format((int)$topUser['total']));
    }

    if ($focusParts) {
        $narrativeParagraphs[] = 'Operational highlights: ' . implode('; ', $focusParts) . '.';
    }

    if (!empty($kpis['first_event']) && !empty($kpis['last_event'])) {
        $narrativeParagraphs[] = sprintf(
            'Within the selected range, recorded activity started at %s and latest activity was logged at %s.',
            (string)$kpis['first_event'],
            (string)$kpis['last_event']
        );
    }
} else {
    $narrativeParagraphs[] = sprintf(
        'No system activity logs were recorded from %s to %s for the current filter selection.',
        $reportDateFrom,
        $reportDateTo
    );
}

$actionVerbMap = [
    'CREATE' => 'created',
    'UPDATE' => 'updated',
    'DELETE' => 'deleted',
    'UPLOAD' => 'uploaded',
    'LOGIN'  => 'logged in to',
    'LOGOUT' => 'logged out from',
];

$activityHighlights = [];
foreach ($narrativeEvents as $event) {
    $actionRaw = strtoupper((string)($event['action'] ?? ''));
    $verb = $actionVerbMap[$actionRaw] ?? strtolower($actionRaw);
    $moduleName = (string)($event['module'] ?? 'system');
    $description = trim((string)($event['description'] ?? ''));
    $actor = (string)($event['user_name'] ?? 'Unknown User');
    $eventTime = (string)($event['created_at'] ?? '');

    $sentence = $actor . ' ' . $verb . ' ' . $moduleName;
    if ($description !== '') {
        $sentence .= ' (' . $description . ')';
    }
    if ($eventTime !== '') {
        $sentence .= ' at ' . $eventTime;
    }
    $activityHighlights[] = $sentence . '.';
}

$dailyChartRows = array_reverse($dailySummary);
$dailyLabels = [];
$dailyTotals = [];
foreach ($dailyChartRows as $row) {
    $dailyLabels[] = (string)($row['log_day'] ?? '');
    $dailyTotals[] = (int)($row['total_events'] ?? 0);
}

$actionChartLabels = [];
$actionChartTotals = [];
foreach (array_slice($actionSummary, 0, 8) as $row) {
    $actionChartLabels[] = (string)($row['action'] ?? '');
    $actionChartTotals[] = (int)($row['total'] ?? 0);
}

$moduleChartLabels = [];
$moduleChartTotals = [];
foreach (array_slice($moduleSummary, 0, 8) as $row) {
    $moduleChartLabels[] = (string)($row['module'] ?? '');
    $moduleChartTotals[] = (int)($row['total'] ?? 0);
}
?>

<style>
.logs-filter-shell,
.logs-report-hero,
.logs-kpi-card,
.logs-summary-card,
.logs-detail-card,
.logs-narrative-card {
    border-radius: 16px;
}

.logs-filter-shell {
    padding: 18px;
    border: 1px solid rgba(148, 163, 184, 0.3);
    background:
        radial-gradient(circle at 12% 10%, rgba(56, 189, 248, 0.16), rgba(56, 189, 248, 0) 36%),
        linear-gradient(165deg, rgba(255,255,255,0.1), rgba(255,255,255,0.03));
    box-shadow: 0 14px 28px rgba(2, 6, 23, 0.14), inset 0 1px 0 rgba(255,255,255,0.22);
}

.logs-filter-form {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 10px;
    align-items: end;
}

.logs-filter-item {
    min-width: 0;
}

.logs-filter-item .form-label {
    font-size: 0.74rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-muted);
}

.logs-filter-item .form-input,
.logs-filter-item .form-select {
    min-height: 40px;
    border-radius: 11px;
    border-color: rgba(148, 163, 184, 0.4);
    background: rgba(255,255,255,0.1);
    transition: border-color .2s ease, box-shadow .2s ease, background-color .2s ease;
}

.logs-filter-item .form-input:focus,
.logs-filter-item .form-select:focus {
    border-color: rgba(56, 189, 248, 0.7);
    box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.18);
    background: rgba(255,255,255,0.16);
}

.logs-filter-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: flex-end;
}

.logs-report-hero {
    padding: 20px;
    margin-top: 14px;
    margin-bottom: 14px;
    border: 1px solid rgba(148, 163, 184, 0.3);
    background:
        radial-gradient(circle at 85% 20%, rgba(14, 165, 233, 0.2), rgba(14, 165, 233, 0) 36%),
        linear-gradient(140deg, rgba(56, 189, 248, 0.16), rgba(16, 185, 129, 0.1) 42%, rgba(255,255,255,0.04));
    box-shadow: 0 14px 28px rgba(2, 6, 23, 0.14);
}

.logs-report-hero-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
    flex-wrap: wrap;
}

.logs-report-title {
    margin: 0;
    font-size: 1.12rem;
    font-weight: 700;
    letter-spacing: 0.01em;
}

.logs-report-meta {
    margin: 8px 0 0;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.logs-meta-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 0.78rem;
    color: var(--text-muted);
    border: 1px solid rgba(148, 163, 184, 0.38);
    background: rgba(255,255,255,0.12);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.2);
}

.logs-kpi-grid {
    margin-bottom: 12px;
}

.logs-kpi-card {
    border: 1px solid rgba(148, 163, 184, 0.28);
    background:
        linear-gradient(168deg, rgba(255,255,255,0.14), rgba(255,255,255,0.04));
    box-shadow: 0 12px 24px rgba(2, 6, 23, 0.12);
    transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
}

.logs-kpi-card:hover {
    transform: translateY(-2px);
    border-color: rgba(56, 189, 248, 0.42);
    box-shadow: 0 16px 28px rgba(2, 6, 23, 0.16);
}

.logs-summary-grid {
    margin-top: 12px;
}

.logs-chart-grid {
    margin-top: 12px;
    display: grid;
    grid-template-columns: 1.25fr .75fr;
    gap: 12px;
}

.logs-chart-card {
    border: 1px solid rgba(148, 163, 184, 0.26);
    border-radius: 16px;
    background:
        radial-gradient(circle at 84% 18%, rgba(56, 189, 248, 0.12), rgba(56, 189, 248, 0) 34%),
        linear-gradient(160deg, rgba(255,255,255,0.08), rgba(255,255,255,0.03));
    box-shadow: 0 12px 24px rgba(2, 6, 23, 0.12);
}

.logs-chart-wrap {
    position: relative;
    min-height: 290px;
    padding: 8px 14px 16px;
}

.logs-chart-wrap canvas {
    width: 100% !important;
    height: 260px !important;
}

.logs-chart-empty {
    display: grid;
    place-items: center;
    min-height: 220px;
    text-align: center;
    color: var(--text-muted);
    font-size: 0.88rem;
}

.logs-summary-card,
.logs-detail-card {
    border: 1px solid rgba(148, 163, 184, 0.26);
    box-shadow: 0 12px 24px rgba(2, 6, 23, 0.12);
    background: linear-gradient(160deg, rgba(255,255,255,0.08), rgba(255,255,255,0.03));
}

.logs-card-title {
    font-size: 0.95rem;
    letter-spacing: 0.025em;
    text-transform: uppercase;
    color: var(--text);
}

.logs-detail-card {
    margin-top: 14px;
}

.logs-detail-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.logs-col-datetime {
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.logs-col-description {
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.logs-summary-card .table-card-header,
.logs-detail-card .table-card-header,
.logs-narrative-card .table-card-header {
    border-bottom: 1px solid rgba(148, 163, 184, 0.22);
    background: linear-gradient(180deg, rgba(255,255,255,0.09), rgba(255,255,255,0.02));
}

.logs-summary-card .data-table thead th,
.logs-detail-card .data-table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: rgba(15, 23, 42, 0.28);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    letter-spacing: 0.04em;
    font-size: 0.73rem;
    text-transform: uppercase;
}

.logs-summary-card .data-table tbody tr,
.logs-detail-card .data-table tbody tr {
    transition: background-color .18s ease;
}

.logs-summary-card .data-table tbody tr:nth-child(odd),
.logs-detail-card .data-table tbody tr:nth-child(odd) {
    background: rgba(255,255,255,0.02);
}

.logs-summary-card .data-table tbody tr:hover,
.logs-detail-card .data-table tbody tr:hover {
    background: rgba(56, 189, 248, 0.08);
}

.logs-detail-card .log-action {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 999px;
    border: 1px solid rgba(148, 163, 184, 0.34);
    font-size: 0.74rem;
    letter-spacing: 0.03em;
}

.logs-narrative-card {
    margin-top: 12px;
    border: 1px solid rgba(148, 163, 184, 0.26);
    box-shadow: 0 12px 24px rgba(2, 6, 23, 0.12);
    background:
        radial-gradient(circle at 88% 14%, rgba(56, 189, 248, 0.2), rgba(56, 189, 248, 0) 35%),
        radial-gradient(circle at 6% 88%, rgba(16, 185, 129, 0.14), rgba(16, 185, 129, 0) 30%),
        linear-gradient(158deg, rgba(255,255,255,0.14), rgba(255,255,255,0.04));
}

.logs-narrative-body {
    padding: 10px 18px 18px;
}

.logs-narrative-body p {
    margin: 8px 0;
    line-height: 1.65;
    color: var(--text-muted);
}

.logs-narrative-lead {
    margin-top: 2px;
    padding: 11px 12px;
    border-radius: 12px;
    border: 1px solid rgba(148, 163, 184, 0.32);
    background: rgba(255,255,255,0.1);
    color: var(--text);
    font-weight: 600;
    line-height: 1.6;
}

.logs-narrative-subtitle {
    margin-top: 14px;
    margin-bottom: 8px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--text-muted);
}

.logs-narrative-subtitle i {
    color: #38bdf8;
}

.logs-narrative-list {
    margin: 6px 0 0;
    padding-left: 0;
    list-style: none;
    display: grid;
    gap: 8px;
}

.logs-narrative-list li {
    margin: 0;
    padding: 10px 12px;
    border-radius: 11px;
    border: 1px solid rgba(148, 163, 184, 0.28);
    background: rgba(255,255,255,0.08);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.14);
    line-height: 1.55;
    color: var(--text);
}

.logs-narrative-list li::marker {
    color: rgba(56, 189, 248, 0.9);
}

@media (max-width: 1200px) {
    .logs-filter-form {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .logs-chart-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 740px) {
    .logs-filter-form {
        grid-template-columns: 1fr;
    }
    .logs-filter-actions {
        justify-content: flex-start;
    }
    .logs-meta-chip {
        width: 100%;
        justify-content: flex-start;
    }
}
</style>

<div class="filter-bar glass-card logs-filter-shell">
    <form method="GET" class="filter-form logs-filter-form">
        <div class="filter-item logs-filter-item">
            <label class="form-label" for="date_from">From</label>
            <input type="date" id="date_from" name="date_from" class="form-input" value="<?= clean($dateFrom) ?>">
        </div>
        <div class="filter-item logs-filter-item">
            <label class="form-label" for="date_to">To</label>
            <input type="date" id="date_to" name="date_to" class="form-input" value="<?= clean($dateTo) ?>">
        </div>
        <div class="filter-item logs-filter-item">
            <label class="form-label" for="user">User</label>
            <select id="user" name="user" class="form-select">
                <option value="">All Users</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= clean($u['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-item logs-filter-item">
            <label class="form-label" for="module">Module</label>
            <select id="module" name="module" class="form-select">
            <option value="">All Modules</option>
            <?php foreach ($modules as $m): ?>
            <option value="<?= clean($m) ?>" <?= $module === $m ? 'selected' : '' ?>><?= ucfirst(clean($m)) ?></option>
            <?php endforeach; ?>
        </select>
        </div>
        <div class="filter-item logs-filter-item">
            <label class="form-label" for="action">Action</label>
            <select id="action" name="action" class="form-select">
            <option value="">All Actions</option>
            <?php foreach ($actions as $a): ?>
            <option value="<?= clean($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= clean($a) ?></option>
            <?php endforeach; ?>
        </select>
        </div>
        <div class="filter-actions logs-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Apply Filters</button>
        <?php if ($isFiltered): ?>
            <a href="<?= APP_URL ?>/logs.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
        </div>
    </form>
</div>

<div class="glass-card logs-report-hero">
    <div class="logs-report-hero-head">
        <div>
            <h3 class="logs-report-title">System Activity Summary Report</h3>
            <div class="logs-report-meta">
                <span class="logs-meta-chip"><i class="fas fa-calendar-day"></i> <?= clean($reportDateFrom) ?> to <?= clean($reportDateTo) ?></span>
                <span class="logs-meta-chip"><i class="fas fa-user"></i> <?= clean($selectedUserLabel) ?></span>
            </div>
        </div>
        <div class="text-muted small">Generated: <?= clean(date('Y-m-d H:i:s')) ?></div>
    </div>
</div>

<div class="stats-grid logs-kpi-grid">
    <div class="stat-card glass-card logs-kpi-card">
        <div class="stat-label">Total Events</div>
        <div class="stat-value"><?= number_format((int)($kpis['total_events'] ?? 0)) ?></div>
    </div>
    <div class="stat-card glass-card logs-kpi-card">
        <div class="stat-label">Users Involved</div>
        <div class="stat-value"><?= number_format((int)($kpis['users_involved'] ?? 0)) ?></div>
    </div>
    <div class="stat-card glass-card logs-kpi-card">
        <div class="stat-label">Action Types</div>
        <div class="stat-value"><?= number_format((int)($kpis['action_types'] ?? 0)) ?></div>
    </div>
    <div class="stat-card glass-card logs-kpi-card">
        <div class="stat-label">Modules Touched</div>
        <div class="stat-value"><?= number_format((int)($kpis['modules_touched'] ?? 0)) ?></div>
    </div>
</div>

<div class="logs-chart-grid">
    <div class="table-card glass-card logs-chart-card">
        <div class="table-card-header">
            <h3 class="card-title logs-card-title">Activity Trend by Day</h3>
        </div>
        <div class="logs-chart-wrap">
            <?php if (!empty($dailyLabels)): ?>
            <canvas id="logsDailyTrendChart" aria-label="Daily activity trend chart" role="img"></canvas>
            <?php else: ?>
            <div class="logs-chart-empty">No daily data available for chart visualization.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-card glass-card logs-chart-card">
        <div class="table-card-header">
            <h3 class="card-title logs-card-title">Action vs Module Share</h3>
        </div>
        <div class="logs-chart-wrap">
            <?php if (!empty($actionChartLabels) || !empty($moduleChartLabels)): ?>
            <canvas id="logsActionModuleChart" aria-label="Action and module share chart" role="img"></canvas>
            <?php else: ?>
            <div class="logs-chart-empty">No action/module data available for chart visualization.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="table-card glass-card logs-narrative-card">
    <div class="table-card-header">
        <h3 class="card-title logs-card-title">Narrative Activity Report</h3>
    </div>
    <div class="logs-narrative-body">
        <?php foreach ($narrativeParagraphs as $i => $paragraph): ?>
        <p class="<?= $i === 0 ? 'logs-narrative-lead' : '' ?>"><?= clean($paragraph) ?></p>
        <?php endforeach; ?>

        <?php if ($activityHighlights): ?>
        <p class="logs-narrative-subtitle"><i class="fas fa-wand-magic-sparkles"></i> Recent Activity Highlights</p>
        <ul class="logs-narrative-list">
            <?php foreach ($activityHighlights as $highlight): ?>
            <li><?= clean($highlight) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<div class="tables-grid logs-summary-grid">
    <div class="table-card glass-card logs-summary-card">
        <div class="table-card-header">
            <h3 class="card-title logs-card-title">Summary by Day</h3>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Total Events</th>
                        <th>Users Involved</th>
                        <th>Action Types</th>
                        <th>Modules Touched</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($dailySummary as $row): ?>
                    <tr>
                        <td><?= clean($row['log_day']) ?></td>
                        <td><?= number_format((int)$row['total_events']) ?></td>
                        <td><?= number_format((int)$row['users_involved']) ?></td>
                        <td><?= number_format((int)$row['action_types']) ?></td>
                        <td><?= number_format((int)$row['modules_touched']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$dailySummary): ?>
                    <tr><td colspan="5" class="text-center text-muted">No summary data for the selected day range.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-card glass-card logs-summary-card">
        <div class="table-card-header">
            <h3 class="card-title logs-card-title">Summary by Action</h3>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($actionSummary as $row): ?>
                    <tr>
                        <td><?= clean($row['action']) ?></td>
                        <td><?= number_format((int)$row['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$actionSummary): ?>
                    <tr><td colspan="2" class="text-center text-muted">No action summary available.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-card glass-card logs-summary-card">
        <div class="table-card-header">
            <h3 class="card-title logs-card-title">Summary by Module</h3>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Module</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($moduleSummary as $row): ?>
                    <tr>
                        <td><?= clean($row['module']) ?></td>
                        <td><?= number_format((int)$row['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$moduleSummary): ?>
                    <tr><td colspan="2" class="text-center text-muted">No module summary available.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-card glass-card logs-summary-card">
        <div class="table-card-header">
            <h3 class="card-title logs-card-title">Top Users by Activity</h3>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($userSummary as $row): ?>
                    <tr>
                        <td><?= clean($row['user_name']) ?></td>
                        <td><?= number_format((int)$row['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$userSummary): ?>
                    <tr><td colspan="2" class="text-center text-muted">No user summary available.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="table-card glass-card logs-detail-card">
    <div class="table-card-header logs-detail-head">
        <h3 class="card-title logs-card-title">Detailed Activity Logs</h3>
        <span class="text-muted small">
            <?= clean($kpis['first_event'] ?? '—') ?> to <?= clean($kpis['last_event'] ?? '—') ?>
        </span>
    </div>

    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Record</th>
                    <th>Description</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
            <?php
            $actionClass = match(strtoupper($log['action'])) {
                'CREATE' => 'log-create',
                'UPDATE' => 'log-update',
                'DELETE' => 'log-delete',
                'UPLOAD' => 'log-upload',
                default  => '',
            };
            ?>
            <tr>
                <td class="logs-col-datetime"><?= clean($log['created_at'] ?? '') ?></td>
                <td><?= clean($log['user_name'] ?? '—') ?></td>
                <td><span class="log-action <?= $actionClass ?>"><?= clean($log['action']) ?></span></td>
                <td><?= clean($log['module']) ?></td>
                <td><?= $log['record_id'] ? '#' . (int)$log['record_id'] : '—' ?></td>
                <td class="logs-col-description"><?= clean($log['description'] ?? '') ?></td>
                <td class="text-muted small"><?= clean($log['ip_address'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?>
            <tr><td colspan="7" class="text-center text-muted">No logs found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= paginationLinks($pag, $paginationBase) ?>

<script>
(function() {
    const dailyLabels = <?= json_encode($dailyLabels) ?>;
    const dailyTotals = <?= json_encode($dailyTotals) ?>;
    const actionLabels = <?= json_encode($actionChartLabels) ?>;
    const actionTotals = <?= json_encode($actionChartTotals) ?>;
    const moduleLabels = <?= json_encode($moduleChartLabels) ?>;
    const moduleTotals = <?= json_encode($moduleChartTotals) ?>;

    window.addEventListener('load', function() {
        if (typeof Chart === 'undefined') return;

        const trendCanvas = document.getElementById('logsDailyTrendChart');
        if (trendCanvas && dailyLabels.length > 0) {
            new Chart(trendCanvas, {
                type: 'line',
                data: {
                    labels: dailyLabels,
                    datasets: [{
                        label: 'Events',
                        data: dailyTotals,
                        borderColor: 'rgba(56, 189, 248, 1)',
                        backgroundColor: 'rgba(56, 189, 248, 0.22)',
                        fill: true,
                        tension: 0.32,
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 },
                            grid: { color: 'rgba(148, 163, 184, 0.18)' }
                        },
                        x: {
                            grid: { color: 'rgba(148, 163, 184, 0.12)' }
                        }
                    }
                }
            });
        }

        const shareCanvas = document.getElementById('logsActionModuleChart');
        if (shareCanvas && (actionLabels.length > 0 || moduleLabels.length > 0)) {
            const labels = actionLabels.concat(moduleLabels.map((m) => 'M: ' + m));
            const values = actionTotals.concat(moduleTotals);

            new Chart(shareCanvas, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: [
                            '#38bdf8', '#0ea5e9', '#0284c7', '#14b8a6', '#10b981', '#22c55e',
                            '#84cc16', '#f59e0b', '#fb7185', '#f97316', '#a78bfa', '#6366f1',
                            '#94a3b8', '#64748b', '#eab308', '#06b6d4'
                        ],
                        borderColor: 'rgba(15, 23, 42, 0.4)',
                        borderWidth: 1
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 10,
                                boxHeight: 10,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        }
                    }
                }
            });
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
