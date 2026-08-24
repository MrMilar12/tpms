<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
sendSecurityHeaders();

// Must be logged in
if (!isLoggedIn()) {
    redirect(APP_URL . '/login');
}

$user = currentUser();
$db = getDB();

// Get user's assigned district from users table
$userRole = strtolower($user['role'] ?? '');
$userDistrict = null;

// Fetch the user's assigned district from users table
$userStmt = $db->prepare('SELECT district_id FROM users WHERE id = ? LIMIT 1');
$userStmt->execute([(int)$user['id']]);
$userDistrict = (int)($userStmt->fetchColumn() ?? 0);

// Get the district details
$districts = [];
if ($userDistrict > 0) {
    $districtStmt = $db->prepare('SELECT id, district_name FROM districts WHERE id = ? LIMIT 1');
    $districtStmt->execute([$userDistrict]);
    $districtData = $districtStmt->fetch(PDO::FETCH_ASSOC);
    if ($districtData) {
        $districts = [$districtData];
    }
}

if (empty($districts)) {
    // No district assigned, go back to dashboard
    redirect(APP_URL . '/dashboard');
}

// Get available district IDs for validation
$availableDistrictIds = array_column($districts, 'id');

// Handle district selection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $selectedDistrictId = (int)($_POST['district_id'] ?? 0);
    
    // Verify the selected district is in the available list
    if (in_array($selectedDistrictId, $availableDistrictIds, true)) {
        // Save district to users table
        $db->prepare('UPDATE users SET district_id = ? WHERE id = ?')
            ->execute([$selectedDistrictId, (int)$user['id']]);
        
        // Set in session
        setSessionDistrict($selectedDistrictId);
        unset($_SESSION['available_districts']);
        unset($_SESSION['need_district_selection']);
        
        logActivity('LOGIN', 'district_selection', null, "Selected district: $selectedDistrictId for role: $userRole");
        
        // Route based on user role
        if (needsOnboarding()) {
            redirect(APP_URL . '/first-login-setup');
        }
        redirect(APP_URL . '/dashboard');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Your District - TPMS</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/fonts/inter/inter.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    </style>
</head>
<body>

<style>
.district-selection-wrapper {
    min-height: 100vh;
    display: flex;
    align-items: stretch;
    padding: 0;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    position: relative;
    overflow: hidden;
}

.district-selection-wrapper::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.05)" fill-opacity="1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,128C672,128,768,160,864,160C960,160,1056,128,1152,122.7C1248,117,1344,107,1392,101.3L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
    background-size: cover;
    pointer-events: none;
    z-index: 1;
}

.district-selection-sidebar {
    flex: 0 0 35%;
    background: rgba(0, 0, 0, 0.15);
    padding: 60px 40px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    color: white;
    z-index: 5;
}

.district-selection-sidebar h2 {
    font-size: 32px;
    font-weight: 900;
    margin-bottom: 20px;
    line-height: 1.2;
}

.district-selection-sidebar p {
    font-size: 16px;
    opacity: 0.9;
    margin-bottom: 30px;
    line-height: 1.6;
}

.district-selection-sidebar .info-item {
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    padding: 16px;
    border-radius: 12px;
    margin-bottom: 16px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    font-size: 13px;
}

.district-selection-sidebar .info-item strong {
    color: #dbeafe;
    margin-bottom: 6px;
    display: block;
}

.district-selection-sidebar .info-item span {
    opacity: 0.85;
    line-height: 1.5;
    display: block;
}

.district-selection-content {
    flex: 1;
    padding: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
}

.district-selection-card {
    background: white;
    border-radius: 16px;
    padding: 40px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    max-width: 500px;
    width: 100%;
}

.district-selection-header {
    text-align: center;
    margin-bottom: 30px;
}

.district-selection-header h2 {
    margin: 0 0 10px;
    color: #0f172a;
    font-size: 28px;
    font-weight: 800;
}

.district-selection-header p {
    margin: 0;
    color: #64748b;
    font-size: 14px;
}

.district-selection-header .user-info {
    margin-top: 12px;
    padding: 12px;
    background: #f0f9ff;
    border-radius: 8px;
    border-left: 4px solid #3b82f6;
}

.district-selection-header .user-info strong {
    color: #0f172a;
}

.district-selection-header .user-info .role-badge {
    display: inline-block;
    background: #3b82f6;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    margin-left: 8px;
    font-weight: 600;
}

.districts-grid {
    display: grid;
    gap: 12px;
    margin-bottom: 24px;
}

.district-option {
    position: relative;
}

.district-option input[type="radio"] {
    position: absolute;
    opacity: 0;
    cursor: pointer;
}

.district-option label {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
}

.district-option input[type="radio"]:checked + label {
    border-color: #3b82f6;
    background: #eff6ff;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.district-checkbox {
    width: 20px;
    height: 20px;
    border: 2px solid #cbd5e1;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: all 0.3s ease;
}

.district-option input[type="radio"]:checked + label .district-checkbox {
    background: #3b82f6;
    border-color: #3b82f6;
    color: white;
}

.district-info {
    flex: 1;
}

.district-name {
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 4px;
}

.district-meta {
    font-size: 12px;
    color: #64748b;
}

.district-selection-actions {
    display: flex;
    gap: 12px;
}

.btn-submit {
    flex: 1;
    padding: 12px 16px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: 0;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 15px;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
}

.btn-submit:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-logout {
    padding: 12px 16px;
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 15px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.btn-logout:hover {
    background: #e2e8f0;
    color: #0f172a;
}

@media (max-width: 1024px) {
    .district-selection-wrapper {
        flex-direction: column;
    }
    
    .district-selection-sidebar {
        flex: 0 0 auto;
        padding: 40px;
        max-width: 100%;
    }
    
    .district-selection-content {
        flex: 1;
        padding: 40px 20px;
    }
}

@media (max-width: 640px) {
    .district-selection-card {
        padding: 24px;
    }
    
    .district-selection-header h2 {
        font-size: 22px;
    }
    
    .district-selection-actions {
        flex-direction: column;
    }
}
</style>

<div class="district-selection-wrapper">
    <!-- Left Sidebar with Instructions -->
    <div class="district-selection-sidebar">
        <h2><i class="fas fa-info-circle" style="margin-right: 12px;"></i>District Selection</h2>
        <p>You've assigned multiple districts. Now pick which one you'll work with today.</p>
        
        <div class="info-item">
            <strong><i class="fas fa-map"></i> Your Districts</strong>
            <span>You have access to <?php echo count($districts); ?> district(s). All are listed on the right.</span>
        </div>
        
        <div class="info-item">
            <strong><i class="fas fa-user"></i> Active District</strong>
            <span>Your selection will be your active working district for today.</span>
        </div>
        
        <div class="info-item">
            <strong><i class="fas fa-sync"></i> Can Switch</strong>
            <span>You can change your active district anytime from the dashboard menu.</span>
        </div>
    </div>

    <!-- Right Content with Form -->
    <div class="district-selection-content">
        <div class="district-selection-card glass-card">
            <div class="district-selection-header">
                <h2><i class="fas fa-map-pin"></i> Select Your District</h2>
                <p>Choose which district you want to manage today</p>
                <div class="user-info">
                    <strong><?= clean($user['full_name']) ?></strong>
                    <span class="role-badge"><?= getRoleDisplayName($user['role']) ?></span>
                </div>
            </div>

        <form method="POST" class="district-form">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            
            <div class="districts-grid">
                <?php foreach ($districts as $d): ?>
                <div class="district-option">
                    <input type="radio" name="district_id" id="dist_<?= (int)$d['id'] ?>" 
                           value="<?= (int)$d['id'] ?>" 
                           <?= !isset($_POST['district_id']) && $d === reset($districts) ? 'checked' : '' ?>>
                    <label for="dist_<?= (int)$d['id'] ?>">
                        <div class="district-checkbox">
                            <i class="fas fa-check" style="font-size: 10px;"></i>
                        </div>
                        <div class="district-info">
                            <div class="district-name"><?= clean($d['district_name']) ?></div>
                            <div class="district-meta"><i class="fas fa-bookmark"></i> District Selection</div>
                        </div>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="district-selection-actions">
                <button type="submit" class="btn-submit">
                    <i class="fas fa-arrow-right"></i> Continue to Dashboard
                </button>
            </div>
        </form>

        <div style="text-align: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
            <a href="<?= APP_URL ?>/actions/logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i> Switch Account
            </a>
        </div>
        </div>
    </div>
</div>

<script src="<?= APP_URL ?>/assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script>
    // Flash messages
    <?php if (isset($_SESSION['flash']['message'])): ?>
        Swal.fire({
            icon: '<?= $_SESSION['flash']['type'] ?>',
            title: '<?= $_SESSION['flash']['type'] === 'error' ? 'Error' : 'Success' ?>',
            text: '<?= addslashes($_SESSION['flash']['message']) ?>',
            confirmButtonText: 'OK'
        });
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>
</script>
</body>
</html>
