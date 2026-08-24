<?php
$pageTitle = 'My Activity';
require_once __DIR__ . '/includes/header.php';

requireRoleSelection();

$db = getDB();
$me = (int)(currentUser()['id'] ?? 0);

if ($me <= 0) {
    flash('error', 'Unable to load activity logs for this session.');
    redirect(APP_URL . '/dashboard.php');
}

$module = trim((string)($_GET['module'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$fromDate = trim((string)($_GET['from'] ?? ''));
$toDate = trim((string)($_GET['to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));

$where = ['user_id = ?'];
$params = [$me];

if ($module !== '') {
    $where[] = 'module = ?';
    $params[] = $module;
}
if ($action !== '') {
    $where[] = 'action = ?';
    $params[] = $action;
}
if ($q !== '') {
    $where[] = '(description LIKE ? OR module LIKE ? OR action LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($fromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $where[] = 'created_at >= ?';
    $params[] = $fromDate . ' 00:00:00';
}
if ($toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $where[] = 'created_at <= ?';
    $params[] = $toDate . ' 23:59:59';
}

$whereSql = implode(' AND ', $where);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM activity_logs WHERE $whereSql");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pag = paginate($total, $page, 50);

$listStmt = $db->prepare(
    "SELECT action, module, description, ip_address, created_at
     FROM activity_logs
     WHERE $whereSql
     ORDER BY created_at DESC
     LIMIT ? OFFSET ?"
);
$listStmt->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
$logs = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$moduleStmt = $db->prepare('SELECT DISTINCT module FROM activity_logs WHERE user_id = ? ORDER BY module');
$moduleStmt->execute([$me]);
$modules = $moduleStmt->fetchAll(PDO::FETCH_COLUMN);

$actionStmt = $db->prepare('SELECT DISTINCT action FROM activity_logs WHERE user_id = ? ORDER BY action');
$actionStmt->execute([$me]);
$actions = $actionStmt->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="filter-bar glass-card">
    <form method="GET" class="filter-form" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <select name="module" class="form-select" onchange="this.form.submit()">
            <option value="">All Modules</option>
            <?php foreach ($modules as $m): ?>
            <option value="<?= clean((string)$m) ?>" <?= $module === (string)$m ? 'selected' : '' ?>><?= ucfirst(clean((string)$m)) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="action" class="form-select" onchange="this.form.submit()">
            <option value="">All Actions</option>
            <?php foreach ($actions as $a): ?>
            <option value="<?= clean((string)$a) ?>" <?= $action === (string)$a ? 'selected' : '' ?>><?= clean((string)$a) ?></option>
            <?php endforeach; ?>
        </select>

        <input type="text" name="q" class="form-input" placeholder="Search description..." value="<?= clean($q) ?>">
        <input type="date" name="from" class="form-input" value="<?= clean($fromDate) ?>">
        <input type="date" name="to" class="form-input" value="<?= clean($toDate) ?>">

        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
        <?php if ($module !== '' || $action !== '' || $q !== '' || $fromDate !== '' || $toDate !== ''): ?>
        <a href="<?= APP_URL ?>/my_activity.php" class="btn btn-ghost btn-sm"><i class="fas fa-rotate-left"></i> Reset</a>
        <?php endif; ?>
    </form>
</div>

<div class="table-card glass-card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <h3 class="card-title"><i class="fas fa-user-clock"></i> My Activity Timeline</h3>
        <span class="text-muted small"><?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?></span>
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Description</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
            <?php
                $actionLabel = strtoupper((string)($log['action'] ?? ''));
                $actionClass = match($actionLabel) {
                    'CREATE' => 'log-create',
                    'UPDATE' => 'log-update',
                    'DELETE' => 'log-delete',
                    'LOGIN' => 'log-login',
                    'LOGOUT' => 'log-logout',
                    default => 'log-default',
                };
            ?>
            <tr>
                <td><?= clean((string)($log['created_at'] ?? '')) ?></td>
                <td><span class="log-pill <?= $actionClass ?>"><?= clean($actionLabel !== '' ? $actionLabel : '—') ?></span></td>
                <td><?= ucfirst(clean((string)($log['module'] ?? '—'))) ?></td>
                <td><?= clean((string)($log['description'] ?? '')) ?></td>
                <td><?= clean((string)($log['ip_address'] ?? '—')) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?>
            <tr><td colspan="5" class="text-center text-muted">No activity logs found for your account.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= paginationLinks($pag, APP_URL . '/my_activity.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>

<style>
.log-pill {
    display: inline-flex;
    align-items: center;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .04em;
    border: 1px solid rgba(148, 163, 184, .35);
    background: rgba(148, 163, 184, .2);
    color: #f8fafc;
}
.log-create { border-color: rgba(74, 222, 128, .45); background: rgba(22, 101, 52, .35); }
.log-update { border-color: rgba(56, 189, 248, .45); background: rgba(7, 89, 133, .35); }
.log-delete { border-color: rgba(248, 113, 113, .5); background: rgba(127, 29, 29, .4); }
.log-login { border-color: rgba(59, 130, 246, .45); background: rgba(30, 64, 175, .35); }
.log-logout { border-color: rgba(251, 191, 36, .45); background: rgba(120, 53, 15, .35); }
.log-default { border-color: rgba(148, 163, 184, .35); background: rgba(51, 65, 85, .35); }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
