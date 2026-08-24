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
$userRole = strtolower($user['role'] ?? '');

// Only PSDS/SDC can access this page
if (!in_array($userRole, ['psds', 'sdc'], true)) {
    redirect(APP_URL . '/dashboard');
}

if (!isset($_SESSION['available_districts_for_setup'])) {
    redirect(APP_URL . '/dashboard');
}

// Get all available districts
$districtsStmt = $db->prepare('SELECT id, district_name FROM districts ORDER BY district_name');
$districtsStmt->execute();
$allDistricts = $districtsStmt->fetchAll(PDO::FETCH_ASSOC);

// If no districts exist, show error
if (empty($allDistricts)) {
    flash('error', 'No districts available. Please contact system administrator.');
    redirect(APP_URL . '/dashboard');
}

// Get already assigned districts (if any)
$userDistricts = getUserDistricts($db, (int)$user['id']);

// Handle district assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $districtId = !empty($_POST['district_id']) ? (int)$_POST['district_id'] : 0;
    
    if (!$districtId) {
        flash('error', 'You must select a district.');
        redirect(APP_URL . '/setup-districts');
    }
    
    // Update user's district assignment
    $db->prepare('UPDATE users SET district_id = ?, updated_at = NOW() WHERE id = ?')->execute([$districtId, (int)$user['id']]);
    
    logActivity('DISTRICT_SETUP', 'users', (int)$user['id'], "Assigned to district ID: " . $districtId);
    unset($_SESSION['available_districts_for_setup']);
    
    flash('success', 'Districts assigned successfully!');
    redirect(APP_URL . '/select-district');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Your Districts - TPMS</title>
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
.district-setup-wrapper {
    min-height: 100vh;
    display: flex;
    align-items: stretch;
    padding: 0;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    position: relative;
    overflow: hidden;
}

.district-setup-wrapper::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.05)" fill-opacity="1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,128C672,128,768,160,864,160C960,160,1056,128,1152,122.7C1248,117,1344,107,1392,101.3L1440,96L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
    background-size: cover;
    pointer-events: none;
}

.district-setup-sidebar {
    flex: 0 0 35%;
    background: rgba(0, 0, 0, 0.15);
    padding: 60px 40px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    color: white;
    z-index: 5;
}

.district-setup-sidebar h2 {
    font-size: 32px;
    font-weight: 900;
    margin-bottom: 20px;
    line-height: 1.2;
}

.district-setup-sidebar p {
    font-size: 16px;
    opacity: 0.9;
    margin-bottom: 30px;
    line-height: 1.6;
}

.district-setup-sidebar .info-item {
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    padding: 16px;
    border-radius: 12px;
    margin-bottom: 16px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    font-size: 13px;
}

.district-setup-sidebar .info-item strong {
    color: #d1fae5;
    margin-bottom: 6px;
    display: block;
}

.district-setup-sidebar .info-item span {
    opacity: 0.85;
    line-height: 1.5;
    display: block;
}

.district-setup-content {
    flex: 1;
    padding: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow-y: auto;
    z-index: 10;
}

.district-setup-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 24px;
    padding: 60px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15), 0 0 1px rgba(255, 255, 255, 0.5) inset;
    max-width: 600px;
    width: 100%;
    border: 1px solid rgba(255, 255, 255, 0.3);
    animation: slideUp 0.6s ease-out;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(40px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.district-setup-header {
    text-align: center;
    margin-bottom: 40px;
}

.district-setup-header h1 {
    margin: 0 0 12px;
    color: #0f172a;
    font-size: 36px;
    font-weight: 900;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    letter-spacing: -0.5px;
}

.district-setup-header p {
    margin: 0;
    color: #64748b;
    font-size: 16px;
    line-height: 1.6;
}

.districts-selection-list {
    display: grid;
    gap: 12px;
    margin-bottom: 30px;
    max-height: 450px;
    overflow-y: auto;
    padding: 4px;
}

.districts-selection-list::-webkit-scrollbar {
    width: 8px;
}

.districts-selection-list::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 10px;
}

.districts-selection-list::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}

.districts-selection-list::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.district-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.district-item:hover {
    border-color: #10b981;
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    transform: translateX(4px);
}

.district-item input[type="checkbox"] {
    width: 22px;
    height: 22px;
    cursor: pointer;
    accent-color: #10b981;
    flex-shrink: 0;
}

.district-item input[type="checkbox"]:checked {
    transform: scale(1.1);
}

.district-item label {
    flex: 1;
    cursor: pointer;
    color: #0f172a;
    font-weight: 600;
    margin: 0;
    font-size: 15px;
}

.district-count {
    display: inline-block;
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(5, 150, 105, 0.05) 100%);
    color: #059669;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 700;
    margin-left: auto;
    flex-shrink: 0;
    border: 1px solid rgba(16, 185, 129, 0.2);
}

.selected-districts-info {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08) 0%, rgba(5, 150, 105, 0.04) 100%);
    border: 2px solid #dcfce7;
    border-left: 4px solid #10b981;
    padding: 18px;
    border-radius: 12px;
    margin-bottom: 25px;
    display: none;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.selected-districts-info.show {
    display: block;
}

.selected-districts-info strong {
    color: #059669;
    display: block;
    margin-bottom: 10px;
    font-size: 15px;
}

.selected-districts-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.selected-district-badge {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    padding: 7px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 700;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
}

.setup-actions {
    display: flex;
    gap: 14px;
    justify-content: center;
    flex-wrap: wrap;
}

.btn-setup-continue {
    padding: 15px 40px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: 0;
    border-radius: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 16px;
    min-width: 180px;
    box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
}

.btn-setup-continue:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 35px rgba(16, 185, 129, 0.4);
}

.btn-setup-continue:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.btn-setup-back {
    padding: 15px 40px;
    background: white;
    color: #64748b;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 16px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-setup-back:hover {
    background: #f1f5f9;
    color: #0f172a;
    border-color: #cbd5e1;
}

.setup-info-box {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.08) 0%, rgba(5, 150, 105, 0.04) 100%);
    border-left: 4px solid #10b981;
    padding: 16px;
    border-radius: 12px;
    font-size: 14px;
    color: #334155;
    margin-top: 25px;
    line-height: 1.6;
    border: 1px solid rgba(16, 185, 129, 0.1);
}

@media (max-width: 1024px) {
    .district-setup-wrapper {
        flex-direction: column;
    }
    
    .district-setup-sidebar {
        flex: 0 0 auto;
        padding: 40px;
        max-width: 100%;
    }
    
    .district-setup-content {
        flex: 1;
        padding: 40px 20px;
    }
    
    .district-setup-card {
        max-width: 100%;
    }
}

@media (max-width: 768px) {
    .district-setup-card {
        padding: 40px 30px;
    }
    
    .district-setup-header h1 {
        font-size: 28px;
    }
    
    .districts-selection-list {
        max-height: 350px;
    }
    
    .district-setup-sidebar {
        padding: 30px 20px;
    }
    
    .district-setup-sidebar h2 {
        font-size: 24px;
    }
}

@media (max-width: 640px) {
    .district-setup-card {
        padding: 25px 20px;
        border-radius: 20px;
    }
    
    .district-setup-header h1 {
        font-size: 24px;
    }
    
    .district-setup-header {
        margin-bottom: 30px;
    }
    
    .district-item {
        padding: 12px 14px;
    }
    
    .district-count {
        display: none;
    }
    
    .setup-actions {
        gap: 10px;
    }
    
    .btn-setup-continue,
    .btn-setup-back {
        padding: 12px 24px;
        font-size: 14px;
        min-width: 120px;
    }
}

.district-card {
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.district-card:hover {
    border-color: #3b82f6 !important;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.98), rgba(59, 130, 246, 0.05)) !important;
    transform: translateY(-8px);
    box-shadow: 0 12px 40px rgba(59, 130, 246, 0.25) !important;
}

.district-card.selected {
    border-color: #3b82f6 !important;
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.99), rgba(59, 130, 246, 0.08)) !important;
    box-shadow: 0 12px 40px rgba(59, 130, 246, 0.3), inset 0 0 20px rgba(59, 130, 246, 0.1) !important;
    transform: scale(1.05);
}

.district-card.faded {
    opacity: 0.3;
    pointer-events: none;
    filter: grayscale(100%);
    transform: scale(0.95);
}

.card-checkmark {
    position: absolute;
    top: 15px;
    right: 15px;
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5em;
    opacity: 0;
    transform: scale(0);
    transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
}

.district-card.selected .card-checkmark {
    opacity: 1;
    transform: scale(1);
}

@media (max-width: 1024px) {
    div[style*="grid-template-columns: repeat(auto-fit"] {
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)) !important;
    }
}

@media (max-width: 768px) {
    div[style*="grid-template-columns: repeat(auto-fit"] {
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) !important;
        gap: 20px !important;
    }
    
    .district-card {
        padding: 25px !important;
    }
}

@media (max-width: 600px) {
    div[style*="grid-template-columns: repeat(auto-fit"] {
        grid-template-columns: 1fr !important;
        gap: 15px !important;
    }
    
    .district-card {
        padding: 20px !important;
    }
    
    h1 {
        font-size: 1.8em !important;
    }
}
</style>

<div style="min-height: 100vh; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 40px 20px; display: flex; flex-direction: column;">
    
    <!-- Header Section -->
    <div style="text-align: center; margin-bottom: 50px;">
        <div style="font-size: 3.5em; margin-bottom: 15px; color: #fff;">
            <i class="fas fa-map-pin"></i>
        </div>
        <h1 style="margin: 0 0 10px 0; font-size: 2.2em; color: #fff; font-weight: 700;">Assign Your District</h1>
        <p style="margin: 0; color: rgba(255, 255, 255, 0.9); font-size: 1.1em;">
            Select the district you will manage as <strong><?= strtoupper($userRole) ?></strong>
        </p>
    </div>

    <!-- Form Container -->
    <form method="POST" style="flex: 1; display: flex; flex-direction: column;">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="district_id" id="districtInput" required>
        
        <!-- Districts Grid -->
        <div style="flex: 1; display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 25px; margin-bottom: 40px; align-content: start;">
            <?php foreach ($allDistricts as $d): ?>
            <div class="district-card" 
                 onclick="selectDistrict(<?= (int)$d['id'] ?>, this)"
                 style="position: relative; padding: 35px; border: 3px solid rgba(255, 255, 255, 0.3); border-radius: 16px; cursor: pointer; transition: all 0.3s ease; background: rgba(255, 255, 255, 0.95); text-align: center; backdrop-filter: blur(10px); box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);"
                 data-district-id="<?= (int)$d['id'] ?>">
                <div class="card-checkmark">
                    <i class="fas fa-check"></i>
                </div>
                <div style="font-size: 3em; margin-bottom: 15px; color: #3b82f6;">
                    <i class="fas fa-map"></i>
                </div>
                <div style="font-weight: 700; color: #000; margin-bottom: 12px; font-size: 1.3em;">
                    <?= clean($d['district_name']) ?>
                </div>
                <div style="font-size: 1em; color: #6b7280; background: #f3f4f6; padding: 10px 15px; border-radius: 8px; display: inline-block;">
                    <i class="fas fa-school"></i>
                    <?php 
                    $countStmt = $db->prepare('SELECT COUNT(*) FROM schools WHERE district_id = ?');
                    $countStmt->execute([(int)$d['id']]);
                    echo (int)$countStmt->fetchColumn();
                    ?> schools
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Button Section -->
        <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap; padding-top: 20px; border-top: 2px solid rgba(255, 255, 255, 0.2);">
            <button type="submit" id="continueBtn" style="padding: 15px 50px; background: linear-gradient(135deg, #fff, #f0f4f6); color: #667eea; border: none; border-radius: 10px; font-size: 1.05em; font-weight: 700; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2); min-width: 200px;">
                <i class="fas fa-arrow-right"></i> Continue
            </button>
            <a href="<?= APP_URL ?>/actions/logout.php" style="padding: 15px 50px; background: rgba(255, 255, 255, 0.2); color: #fff; border: 2px solid rgba(255, 255, 255, 0.5); border-radius: 10px; font-size: 1.05em; font-weight: 700; text-align: center; text-decoration: none; cursor: pointer; transition: all 0.3s ease; min-width: 200px;">
                <i class="fas fa-arrow-left"></i> Go Back
            </a>
        </div>
    </form>
</div>

<script>
const districtInput = document.getElementById('districtInput');
const form = document.querySelector('form');
const districtCards = document.querySelectorAll('.district-card');

function selectDistrict(districtId, cardElement) {
    // Set the hidden input value
    districtInput.value = districtId;
    
    // Remove selected class from all cards and add faded class
    districtCards.forEach(card => {
        if (card !== cardElement) {
            card.classList.remove('selected');
            card.classList.add('faded');
        }
    });
    
    // Add selected class to clicked card and remove faded class
    cardElement.classList.add('selected');
    cardElement.classList.remove('faded');
    
    console.log('District selected: ' + districtId);
}

form.addEventListener('submit', function(e) {
    if (!districtInput.value) {
        e.preventDefault();
        alert('Please select a district to continue.');
    }
});
</script>

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
