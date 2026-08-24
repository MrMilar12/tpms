<?php
$pageTitle = 'ID Verification';
require_once __DIR__ . '/includes/header.php';

// Require user to have selected a role
requireRoleSelection();

$db = getDB();
$token = trim((string)($_GET['t'] ?? ''));
$payload = decryptSecureToken($token);
$isValid = false;
$message = 'Invalid or tampered QR token.';
$verifiedUser = null;

if ($payload !== false) {
    $uid = (int)($payload['uid'] ?? 0);
    $exp = (int)($payload['exp'] ?? 0);
    $scope = (string)($payload['scope'] ?? '');

    if ($uid > 0 && $scope === 'profile_id' && $exp >= time()) {
        $stmt = $db->prepare('SELECT id, username, full_name, email, role, is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $verifiedUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($verifiedUser) {
            $isValid = true;
            $message = 'Valid TPMS encrypted ID.';
        } else {
            $message = 'Token is valid but user no longer exists.';
        }
    } elseif ($exp > 0 && $exp < time()) {
        $message = 'QR token is expired.';
    }
}
?>

<div class="filter-bar glass-card">
    <div class="topbar-title"><i class="fas fa-qrcode"></i> ID Verification</div>
</div>

<div class="charts-grid" style="grid-template-columns:minmax(320px,760px);justify-content:center;">
    <div class="chart-card glass-card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-shield-halved"></i> Verification Result</h3>
        </div>

        <?php if ($isValid && $verifiedUser): ?>
        <div class="alert alert-success" style="margin-bottom:14px;">
            <strong><?= clean($message) ?></strong>
        </div>
        <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
            <div class="form-group">
                <label class="form-label">User ID</label>
                <input type="text" class="form-input" value="<?= clean((string)$verifiedUser['id']) ?>" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" class="form-input" value="<?= clean((string)$verifiedUser['full_name']) ?>" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" class="form-input" value="<?= clean((string)$verifiedUser['username']) ?>" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">Role</label>
                <input type="text" class="form-input" value="<?= clean(ucfirst(str_replace('_', ' ', (string)$verifiedUser['role']))) ?>" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="text" class="form-input" value="<?= clean((string)($verifiedUser['email'] ?: 'N/A')) ?>" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <input type="text" class="form-input" value="<?= ((int)$verifiedUser['is_active'] === 1) ? 'Active' : 'Inactive' ?>" readonly>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-error">
            <strong><?= clean($message) ?></strong>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
