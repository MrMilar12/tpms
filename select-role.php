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

// Only allow access if user has NO role (NULL/empty) or has 'viewer' (default/incomplete role)
$userRole = $user['role'] ?? null;
if ($userRole !== null && $userRole !== '' && strtolower($userRole) !== 'null' && strtolower($userRole) !== 'viewer') {
    // User already has a real role, redirect to dashboard
    redirect(APP_URL . '/dashboard');
}

// Handle role selection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $selectedRole = strtolower(trim($_POST['role'] ?? ''));
    
    // Validate role choice - only specific roles allowed (not 'viewer' or generic roles)
    $allowedRoles = ['psds', 'sdc', 'unit_head', 'hr', 'admin'];
    if (!in_array($selectedRole, $allowedRoles, true)) {
        flash('error', 'Invalid role selected.');
        redirect(APP_URL . '/select-role');
    }
    
    // Update user role in database
    $updateStmt = $db->prepare('UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?');
    $result = $updateStmt->execute([$selectedRole, (int)$user['id']]);
    
    if (!$result || $updateStmt->rowCount() === 0) {
        flash('error', 'Failed to save role selection. Please try again.');
        redirect(APP_URL . '/select-role');
    }
    
    // Verify the update was successful by reading from database
    $verifyStmt = $db->prepare('SELECT role FROM users WHERE id = ?');
    $verifyStmt->execute([(int)$user['id']]);
    $verifyResult = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$verifyResult || strtolower($verifyResult['role'] ?? '') !== $selectedRole) {
        flash('error', 'Role change verification failed. Please try again.');
        redirect(APP_URL . '/select-role');
    }
    
    // Update session with new role
    $_SESSION['role'] = $selectedRole;
    
    logActivity('ROLE_SELECTION', 'users', (int)$user['id'], "User selected role: $selectedRole");
    unset($_SESSION['pending_role_selection']);
    
    // If Unit Head, redirect to onboarding
    if ($selectedRole === 'unit_head') {
        // Force session to save before redirecting
        session_write_close();
        redirect(APP_URL . '/first-login-setup');
    }
    
    // For PSDS/SDC, redirect to district assignment
    if (in_array($selectedRole, ['psds', 'sdc'], true)) {
        $_SESSION['available_districts_for_setup'] = true;
        // Force session to save before redirecting
        session_write_close();
        redirect(APP_URL . '/setup-districts');
    }
    
    // Force session to save before redirecting
    session_write_close();
    redirect(APP_URL . '/dashboard');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Your Role - TPMS</title>
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
.role-selection-wrapper {
    min-height: 100vh;
    display: flex;
    align-items: stretch;
    padding: 0;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    position: relative;
    overflow: hidden;
}

.role-selection-wrapper::before {
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

.role-selection-sidebar {
    flex: 0 0 35%;
    background: rgba(0, 0, 0, 0.15);
    padding: 60px 40px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    color: white;
    z-index: 5;
    overflow-y: auto;
    max-height: 100vh;
}

.role-selection-sidebar h2 {
    font-size: clamp(20px, 4vw, 32px);
    font-weight: 900;
    margin-bottom: 20px;
    line-height: 1.2;
}

.role-selection-sidebar p {
    font-size: clamp(13px, 2vw, 16px);
    opacity: 0.9;
    margin-bottom: 30px;
    line-height: 1.6;
}

.role-selection-sidebar .role-info-item {
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 16px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.role-selection-sidebar .role-info-item strong {
    display: block;
    font-size: clamp(13px, 1.5vw, 15px);
    margin-bottom: 8px;
    color: #e0e7ff;
}

.role-selection-sidebar .role-info-item span {
    font-size: clamp(11px, 1.2vw, 13px);
    opacity: 0.85;
    line-height: 1.5;
}

.role-selection-content {
    flex: 1;
    padding: clamp(20px, 5vw, 60px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    overflow-y: auto;
    max-height: 100vh;
}

.role-selection-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 24px;
    padding: clamp(20px, 5vw, 70px);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15), 0 0 1px rgba(255, 255, 255, 0.5) inset;
    max-width: 900px;
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

.role-selection-header {
    text-align: center;
    margin-bottom: clamp(20px, 4vw, 55px);
}

.role-selection-header h1 {
    margin: 0 0 12px;
    color: #0f172a;
    font-size: clamp(24px, 5vw, 42px);
    font-weight: 900;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    letter-spacing: -0.5px;
    line-height: 1.2;
}

.role-selection-header p {
    margin: 0 0 12px;
    color: #64748b;
    font-size: clamp(14px, 2.5vw, 17px);
    line-height: 1.6;
}

.user-welcome {
    margin-top: 28px;
    padding: clamp(16px, 3vw, 28px);
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.05) 100%);
    border-radius: 18px;
    border: 2px solid rgba(102, 126, 234, 0.2);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.08);
}

.user-welcome strong {
    color: #0f172a;
    display: block;
    margin-bottom: 8px;
    font-size: clamp(14px, 2vw, 18px);
    font-weight: 700;
}

.user-welcome small {
    color: #64748b;
    font-size: clamp(12px, 1.5vw, 15px);
    line-height: 1.5;
}

.roles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: clamp(12px, 3vw, 28px);
    margin-bottom: 45px;
}

.role-option {
    position: relative;
}

.role-option input[type="radio"] {
    position: absolute;
    opacity: 0;
    cursor: pointer;
}

.role-option label {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: clamp(12px, 2vw, 20px);
    padding: clamp(24px, 4vw, 48px) clamp(16px, 3vw, 32px);
    border: 2px solid #e2e8f0;
    border-radius: 20px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    position: relative;
    text-align: center;
    min-height: clamp(120px, 25vw, 320px);
    justify-content: center;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
}

.role-option:nth-child(1) label {
    border-color: #667eea;
    background: linear-gradient(135deg, #f0f7ff 0%, #f5f3ff 100%);
}

.role-option:nth-child(1) input[type="radio"]:checked + label {
    border-color: #667eea;
    background: linear-gradient(135deg, #dbeafe 0%, #ede9fe 100%);
    box-shadow: 0 0 0 6px rgba(102, 126, 234, 0.15), 0 20px 40px rgba(102, 126, 234, 0.25);
    transform: translateY(-8px) scale(1.02);
}

.role-option:nth-child(2) label {
    border-color: #f59e0b;
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
}

.role-option:nth-child(2) input[type="radio"]:checked + label {
    border-color: #f59e0b;
    background: linear-gradient(135deg, #fde68a 0%, #fcd34d 100%);
    box-shadow: 0 0 0 6px rgba(245, 158, 11, 0.15), 0 20px 40px rgba(245, 158, 11, 0.25);
    transform: translateY(-8px) scale(1.02);
}

.role-option:nth-child(3) label {
    border-color: #06b6d4;
    background: linear-gradient(135deg, #ecfdf5 0%, #cffafe 100%);
}

.role-option:nth-child(3) input[type="radio"]:checked + label {
    border-color: #06b6d4;
    background: linear-gradient(135deg, #a5f3fc 0%, #67e8f9 100%);
    box-shadow: 0 0 0 6px rgba(6, 182, 212, 0.15), 0 20px 40px rgba(6, 182, 212, 0.25);
    transform: translateY(-8px) scale(1.02);
}

.role-icon {
    width: clamp(40px, 12vw, 90px);
    height: clamp(40px, 12vw, 90px);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: clamp(18px, 5vw, 42px);
    transition: all 0.3s ease;
    border: 3px solid rgba(0, 0, 0, 0.1);
    flex-shrink: 0;
}

.role-option:nth-child(1) .role-icon {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #667eea;
}

.role-option:nth-child(1) input[type="radio"]:checked + label .role-icon {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    transform: scale(1.2) rotate(-5deg);
    border-color: #667eea;
    box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
}

.role-option:nth-child(2) .role-icon {
    background: linear-gradient(135deg, #fde68a 0%, #fcd34d 100%);
    color: #d97706;
}

.role-option:nth-child(2) input[type="radio"]:checked + label .role-icon {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    transform: scale(1.2) rotate(-5deg);
    border-color: #f59e0b;
    box-shadow: 0 10px 25px rgba(245, 158, 11, 0.4);
}

.role-option:nth-child(3) .role-icon {
    background: linear-gradient(135deg, #a5f3fc 0%, #67e8f9 100%);
    color: #0891b2;
}

.role-option:nth-child(3) input[type="radio"]:checked + label .role-icon {
    background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
    color: white;
    transform: scale(1.2) rotate(-5deg);
    border-color: #06b6d4;
    box-shadow: 0 10px 25px rgba(6, 182, 212, 0.4);
}

.role-name {
    font-weight: 800;
    color: #0f172a;
    font-size: clamp(12px, 3vw, 20px);
    margin: 0;
    letter-spacing: -0.3px;
    line-height: 1.3;
}

.role-description {
    font-size: clamp(10px, 2vw, 14px);
    color: #64748b;
    margin: 0;
    line-height: 1.5;
}

.role-info-box {
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.08) 0%, rgba(118, 75, 162, 0.04) 100%);
    border-left: 4px solid #667eea;
    padding: 18px;
    border-radius: 12px;
    margin-bottom: 30px;
    font-size: clamp(12px, 1.5vw, 14px);
    color: #334155;
    line-height: 1.7;
}

.role-info-box ul {
    margin: 10px 0 0;
    padding-left: 20px;
}

.role-info-box li {
    margin: 6px 0;
}

.role-selection-actions {
    display: flex;
    gap: clamp(10px, 2vw, 18px);
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 8px;
}

.btn-role-submit {
    padding: clamp(12px, 2.5vw, 18px) clamp(20px, 4vw, 50px);
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: 0;
    border-radius: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: clamp(14px, 2vw, 17px);
    min-width: auto;
    min-height: 44px;
    box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
}

.btn-role-submit:hover {
    transform: translateY(-4px);
    box-shadow: 0 15px 40px rgba(102, 126, 234, 0.4);
}

.btn-role-submit:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.btn-role-cancel {
    padding: clamp(12px, 2.5vw, 18px) clamp(20px, 4vw, 50px);
    background: white;
    color: #64748b;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: clamp(14px, 2vw, 17px);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 44px;
}

.btn-role-cancel:hover {
    background: #f1f5f9;
    color: #0f172a;
    border-color: #cbd5e1;
}

/* â”€â”€ RESPONSIVE BREAKPOINTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

@media (max-width: 1200px) {
    .role-selection-sidebar {
        padding: 50px 35px;
    }
    
    .role-selection-content {
        padding: clamp(20px, 4vw, 50px);
    }
}

@media (max-width: 1024px) {
    .role-selection-wrapper {
        flex-direction: column;
    }
    
    .role-selection-sidebar {
        flex: 0 0 auto;
        padding: clamp(20px, 4vw, 40px);
        max-width: 100%;
        max-height: auto;
        justify-content: flex-start;
    }
    
    .role-selection-sidebar h2 {
        font-size: clamp(20px, 4vw, 28px);
    }
    
    .role-selection-content {
        flex: 1;
        padding: clamp(20px, 4vw, 40px);
        min-height: auto;
        max-height: auto;
    }
    
    .role-selection-card {
        padding: clamp(20px, 4vw, 40px);
    }
    
    .role-selection-header h1 {
        font-size: clamp(20px, 4vw, 36px);
    }
    
    .role-selection-header {
        margin-bottom: clamp(20px, 3vw, 40px);
    }
}

@media (max-width: 768px) {
    .role-selection-sidebar {
        padding: clamp(16px, 3vw, 30px);
    }
    
    .role-selection-sidebar .role-info-item {
        padding: clamp(12px, 2vw, 16px);
        margin-bottom: 12px;
    }
    
    .role-selection-content {
        padding: clamp(16px, 3vw, 30px);
    }
    
    .role-selection-card {
        padding: clamp(16px, 3vw, 30px);
        border-radius: 18px;
    }
    
    .roles-grid {
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: clamp(10px, 2vw, 16px);
        margin-bottom: clamp(20px, 3vw, 30px);
    }
    
    .role-option label {
        min-height: clamp(100px, 20vw, 160px);
        padding: clamp(16px, 2vw, 24px) clamp(12px, 2vw, 16px);
        gap: clamp(8px, 1.5vw, 12px);
    }
    
    .role-option:nth-child(1) input[type="radio"]:checked + label {
        transform: translateY(-4px) scale(1.01);
    }
    
    .role-option:nth-child(2) input[type="radio"]:checked + label {
        transform: translateY(-4px) scale(1.01);
    }
    
    .role-option:nth-child(3) input[type="radio"]:checked + label {
        transform: translateY(-4px) scale(1.01);
    }
}

@media (max-width: 640px) {
    .role-selection-wrapper {
        min-height: 100vh;
        overflow-y: auto;
    }
    
    .role-selection-sidebar {
        padding: clamp(14px, 2.5vw, 20px);
    }
    
    .role-selection-sidebar h2 {
        font-size: clamp(16px, 3.5vw, 20px);
        margin-bottom: 10px;
    }
    
    .role-selection-sidebar p {
        font-size: clamp(12px, 2vw, 13px);
        margin-bottom: 15px;
    }
    
    .role-selection-sidebar .role-info-item {
        padding: clamp(10px, 2vw, 12px);
        margin-bottom: 10px;
        border-radius: 10px;
    }
    
    .role-selection-content {
        padding: clamp(12px, 2.5vw, 20px);
    }
    
    .role-selection-card {
        padding: clamp(12px, 2.5vw, 20px);
        border-radius: 16px;
    }
    
    .role-selection-header h1 {
        font-size: clamp(18px, 3.5vw, 22px);
        margin-bottom: 6px;
    }
    
    .role-selection-header {
        margin-bottom: clamp(16px, 3vw, 20px);
    }
    
    .user-welcome {
        margin-top: 14px;
        padding: clamp(12px, 2vw, 16px);
        border-radius: 12px;
    }
    
    .roles-grid {
        grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
        gap: clamp(8px, 1.5vw, 12px);
        margin-bottom: clamp(16px, 2.5vw, 20px);
    }
    
    .role-option label {
        min-height: clamp(90px, 18vw, 120px);
        padding: clamp(12px, 2vw, 16px) clamp(10px, 1.5vw, 12px);
        gap: clamp(6px, 1vw, 10px);
        border-radius: 14px;
    }
    
    .role-icon {
        width: clamp(32px, 8vw, 48px);
        height: clamp(32px, 8vw, 48px);
        font-size: clamp(14px, 3vw, 20px);
        border: 2px solid rgba(0, 0, 0, 0.1);
    }
    
    .role-option:nth-child(1) input[type="radio"]:checked + label .role-icon {
        transform: scale(1.1);
    }
    
    .role-option:nth-child(2) input[type="radio"]:checked + label .role-icon {
        transform: scale(1.1);
    }
    
    .role-option:nth-child(3) input[type="radio"]:checked + label .role-icon {
        transform: scale(1.1);
    }
    
    .role-name {
        font-size: clamp(11px, 2.5vw, 14px);
    }
    
    .role-description {
        font-size: clamp(9px, 1.5vw, 11px);
        line-height: 1.4;
    }
    
    .role-selection-actions {
        gap: clamp(8px, 1.5vw, 10px);
        margin-top: 16px;
    }
    
    .btn-role-submit,
    .btn-role-cancel {
        padding: clamp(10px, 1.5vw, 12px) clamp(16px, 3vw, 20px);
        font-size: clamp(12px, 1.8vw, 14px);
        min-height: 44px;
        flex: 1;
        max-width: calc(50% - 4px);
    }
}

@media (max-width: 480px) {
    .role-selection-wrapper::before {
        display: none;
    }
    
    .role-selection-sidebar h2 {
        font-size: clamp(14px, 3vw, 18px);
    }
    
    .role-selection-sidebar p {
        font-size: clamp(11px, 1.8vw, 12px);
    }
    
    .role-selection-header h1 {
        font-size: clamp(16px, 3vw, 20px);
    }
    
    .role-selection-card {
        padding: clamp(10px, 2vw, 15px);
    }
    
    .roles-grid {
        grid-template-columns: 1fr;
        gap: clamp(6px, 1.2vw, 8px);
    }
    
    .role-option label {
        min-height: clamp(80px, 16vw, 100px);
        padding: clamp(10px, 1.5vw, 12px) clamp(8px, 1vw, 10px);
        gap: clamp(6px, 0.8vw, 8px);
    }
    
    .role-icon {
        width: clamp(28px, 6vw, 40px);
        height: clamp(28px, 6vw, 40px);
        font-size: clamp(12px, 2.5vw, 18px);
    }
    
    .role-name {
        font-size: clamp(10px, 2.2vw, 12px);
    }
    
    .role-description {
        font-size: clamp(8px, 1.2vw, 10px);
    }
    
    .btn-role-submit,
    .btn-role-cancel {
        padding: clamp(10px, 1.2vw, 12px) clamp(14px, 2vw, 16px);
        font-size: clamp(12px, 1.6vw, 13px);
        min-width: 100%;
        flex: 1 1 100%;
        min-height: 44px;
    }
}

@media (max-width: 360px) {
    .role-selection-wrapper {
        flex-direction: column;
    }
    
    .role-selection-sidebar {
        padding: 12px 10px;
    }
    
    .role-selection-sidebar h2 {
        font-size: 14px;
        margin-bottom: 8px;
    }
    
    .role-selection-sidebar p {
        font-size: 10px;
        margin-bottom: 10px;
    }
    
    .role-selection-sidebar .role-info-item {
        padding: 8px;
        margin-bottom: 8px;
        border-radius: 8px;
    }
    
    .role-selection-sidebar .role-info-item strong {
        font-size: 10px;
    }
    
    .role-selection-sidebar .role-info-item span {
        font-size: 9px;
    }
    
    .role-selection-content {
        padding: 10px;
    }
    
    .role-selection-card {
        padding: 10px;
        border-radius: 14px;
    }
    
    .role-selection-header h1 {
        font-size: 16px;
    }
    
    .role-selection-header p {
        font-size: 11px;
    }
    
    .user-welcome {
        margin-top: 10px;
        padding: 10px;
    }
    
    .user-welcome strong {
        font-size: 12px;
    }
    
    .user-welcome small {
        font-size: 10px;
    }
    
    .roles-grid {
        grid-template-columns: 1fr;
        gap: 6px;
        margin-bottom: 12px;
    }
    
    .role-option label {
        min-height: 80px;
        padding: 10px 8px;
        gap: 6px;
    }
    
    .role-icon {
        width: 28px;
        height: 28px;
        font-size: 12px;
    }
    
    .role-name {
        font-size: 10px;
    }
    
    .role-description {
        font-size: 8px;
    }
    
    .role-selection-actions {
        gap: 6px;
        margin-top: 10px;
    }
    
    .btn-role-submit,
    .btn-role-cancel {
        padding: 10px 12px;
        font-size: 11px;
        min-width: auto;
        flex: 1;
        min-height: 40px;
    }
}

/* â”€â”€ RESPONSIVE BREAKPOINTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

@media (max-width: 1200px) {
@media (max-width: 1024px) {
    .role-selection-wrapper {
        flex-direction: column;
    }
    
    .role-selection-sidebar {
        flex: 0 0 auto;
        padding: clamp(20px, 4vw, 40px);
        max-width: 100%;
        max-height: auto;
        justify-content: flex-start;
    }
    
    .role-selection-sidebar h2 {
        font-size: clamp(20px, 4vw, 28px);
    }
    
    .role-selection-content {
        flex: 1;
        padding: clamp(20px, 4vw, 40px);
        min-height: auto;
        max-height: auto;
    }
    
    .role-selection-card {
        padding: clamp(20px, 4vw, 40px);
    }
    
    .role-selection-header h1 {
        font-size: clamp(20px, 4vw, 36px);
    }
    
    .role-selection-header {
        margin-bottom: clamp(20px, 3vw, 40px);
    }
}

@media (max-width: 768px) {
    .role-selection-sidebar {
        padding: clamp(16px, 3vw, 30px);
    }
    
    .role-selection-sidebar .role-info-item {
        padding: clamp(12px, 2vw, 16px);
        margin-bottom: 12px;
    }
    
    .role-selection-content {
        padding: clamp(16px, 3vw, 30px);
    }
    
    .role-selection-card {
        padding: clamp(16px, 3vw, 30px);
        border-radius: 18px;
    }
    
    .roles-grid {
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: clamp(10px, 2vw, 16px);
        margin-bottom: clamp(20px, 3vw, 30px);
    }
    
    .role-option label {
        min-height: clamp(100px, 20vw, 160px);
        padding: clamp(16px, 2vw, 24px) clamp(12px, 2vw, 16px);
        gap: clamp(8px, 1.5vw, 12px);
    }
    
    .role-option:nth-child(1) input[type="radio"]:checked + label {
        transform: translateY(-4px) scale(1.01);
    }
    
    .role-option:nth-child(2) input[type="radio"]:checked + label {
        transform: translateY(-4px) scale(1.01);
    }
    
    .role-option:nth-child(3) input[type="radio"]:checked + label {
        transform: translateY(-4px) scale(1.01);
    }
}

@media (max-width: 640px) {
    .role-selection-wrapper {
        min-height: 100vh;
        overflow-y: auto;
    }
    
    .role-selection-sidebar {
        padding: clamp(14px, 2.5vw, 20px);
    }
    
    .role-selection-sidebar h2 {
        font-size: clamp(16px, 3.5vw, 20px);
        margin-bottom: 10px;
    }
    
    .role-selection-sidebar p {
        font-size: clamp(12px, 2vw, 13px);
        margin-bottom: 15px;
    }
    
    .role-selection-sidebar .role-info-item {
        padding: clamp(10px, 2vw, 12px);
        margin-bottom: 10px;
        border-radius: 10px;
    }
    
    .role-selection-content {
        padding: clamp(12px, 2.5vw, 20px);
    }
    
    .role-selection-card {
        padding: clamp(12px, 2.5vw, 20px);
        border-radius: 16px;
    }
    
    .role-selection-header h1 {
        font-size: clamp(18px, 3.5vw, 22px);
        margin-bottom: 6px;
    }
    
    .role-selection-header {
        margin-bottom: clamp(16px, 3vw, 20px);
    }
    
    .user-welcome {
        margin-top: 14px;
        padding: clamp(12px, 2vw, 16px);
        border-radius: 12px;
    }
    
    .roles-grid {
        grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
        gap: clamp(8px, 1.5vw, 12px);
        margin-bottom: clamp(16px, 2.5vw, 20px);
    }
    
    .role-option label {
        min-height: clamp(90px, 18vw, 120px);
        padding: clamp(12px, 2vw, 16px) clamp(10px, 1.5vw, 12px);
        gap: clamp(6px, 1vw, 10px);
        border-radius: 14px;
    }
    
    .role-icon {
        width: clamp(32px, 8vw, 48px);
        height: clamp(32px, 8vw, 48px);
        font-size: clamp(14px, 3vw, 20px);
        border: 2px solid rgba(0, 0, 0, 0.1);
    }
    
    .role-option:nth-child(1) input[type="radio"]:checked + label .role-icon {
        transform: scale(1.1);
    }
    
    .role-option:nth-child(2) input[type="radio"]:checked + label .role-icon {
        transform: scale(1.1);
    }
    
    .role-option:nth-child(3) input[type="radio"]:checked + label .role-icon {
        transform: scale(1.1);
    }
    
    .role-name {
        font-size: clamp(11px, 2.5vw, 14px);
    }
    
    .role-description {
        font-size: clamp(9px, 1.5vw, 11px);
        line-height: 1.4;
    }
    
    .role-selection-actions {
        gap: clamp(8px, 1.5vw, 10px);
        margin-top: 16px;
    }
    
    .btn-role-submit,
    .btn-role-cancel {
        padding: clamp(10px, 1.5vw, 12px) clamp(16px, 3vw, 20px);
        font-size: clamp(12px, 1.8vw, 14px);
        min-height: 44px;
        flex: 1;
        max-width: calc(50% - 4px);
    }
}

@media (max-width: 480px) {
    .role-selection-wrapper::before {
        display: none;
    }
    
    .role-selection-sidebar h2 {
        font-size: clamp(14px, 3vw, 18px);
    }
    
    .role-selection-sidebar p {
        font-size: clamp(11px, 1.8vw, 12px);
    }
    
    .role-selection-header h1 {
        font-size: clamp(16px, 3vw, 20px);
    }
    
    .role-selection-card {
        padding: clamp(10px, 2vw, 15px);
    }
    
    .roles-grid {
        grid-template-columns: 1fr;
        gap: clamp(6px, 1.2vw, 8px);
    }
    
    .role-option label {
        min-height: clamp(80px, 16vw, 100px);
        padding: clamp(10px, 1.5vw, 12px) clamp(8px, 1vw, 10px);
        gap: clamp(6px, 0.8vw, 8px);
    }
    
    .role-icon {
        width: clamp(28px, 6vw, 40px);
        height: clamp(28px, 6vw, 40px);
        font-size: clamp(12px, 2.5vw, 18px);
    }
    
    .role-name {
        font-size: clamp(10px, 2.2vw, 12px);
    }
    
    .role-description {
        font-size: clamp(8px, 1.2vw, 10px);
    }
    
    .btn-role-submit,
    .btn-role-cancel {
        padding: clamp(10px, 1.2vw, 12px) clamp(14px, 2vw, 16px);
        font-size: clamp(12px, 1.6vw, 13px);
        min-width: 100%;
        flex: 1 1 100%;
        min-height: 44px;
    }
}

@media (max-width: 360px) {
    .role-selection-wrapper {
        flex-direction: column;
    }
    
    .role-selection-sidebar {
        padding: 12px 10px;
    }
    
    .role-selection-sidebar h2 {
        font-size: 14px;
        margin-bottom: 8px;
    }
    
    .role-selection-sidebar p {
        font-size: 10px;
        margin-bottom: 10px;
    }
    
    .role-selection-sidebar .role-info-item {
        padding: 8px;
        margin-bottom: 8px;
        border-radius: 8px;
    }
    
    .role-selection-sidebar .role-info-item strong {
        font-size: 10px;
    }
    
    .role-selection-sidebar .role-info-item span {
        font-size: 9px;
    }
    
    .role-selection-content {
        padding: 10px;
    }
    
    .role-selection-card {
        padding: 10px;
        border-radius: 14px;
    }
    
    .role-selection-header h1 {
        font-size: 16px;
    }
    
    .role-selection-header p {
        font-size: 11px;
    }
    
    .user-welcome {
        margin-top: 10px;
        padding: 10px;
    }
    
    .user-welcome strong {
        font-size: 12px;
    }
    
    .user-welcome small {
        font-size: 10px;
    }
    
    .roles-grid {
        grid-template-columns: 1fr;
        gap: 6px;
        margin-bottom: 12px;
    }
    
    .role-option label {
        min-height: 80px;
        padding: 10px 8px;
        gap: 6px;
    }
    
    .role-icon {
        width: 28px;
        height: 28px;
        font-size: 12px;
    }
    
    .role-name {
        font-size: 10px;
    }
    
    .role-description {
        font-size: 8px;
    }
    
    .role-selection-actions {
        gap: 6px;
        margin-top: 10px;
    }
    
    .btn-role-submit,
    .btn-role-cancel {
        padding: 10px 12px;
        font-size: 11px;
        min-width: auto;
        flex: 1;
        min-height: 40px;
    }
}
</style>

<div class="role-selection-wrapper">
    <!-- Left Sidebar with Instructions -->
    <div class="role-selection-sidebar">
        <h2><i class="fas fa-info-circle" style="margin-right: 12px;"></i>Role Guide</h2>
        <p>Select the role that matches your position. You'll then set up your districts.</p>
        
        <div class="role-info-item">
            <strong><i class="fas fa-map-location-dot"></i> PSDS</strong>
            <span>Public Schools Division Supervisor - Manage provincial-level education data and coordination across all divisions</span>
        </div>
        
        <div class="role-info-item">
            <strong><i class="fas fa-sitemap"></i> SDC</strong>
            <span>Schools Division Coordinator - Manage division-level schools and personnel within your assigned division</span>
        </div>
        
        <div class="role-info-item">
            <strong><i class="fas fa-user-tie"></i> Unit Head</strong>
            <span>Unit Head - Manage unit-level operations and day-to-day activities</span>
        </div>
    </div>

    <!-- Right Content with Form -->
    <div class="role-selection-content">
        <div class="role-selection-card glass-card">
            <div class="role-selection-header">
                <h1><i class="fas fa-crown"></i> Choose Your Role</h1>
                <p>Select the role that best describes your position</p>
                <div class="user-welcome">
                    <strong>Welcome, <?= clean($user['full_name']) ?></strong>
                    <small>Please select your role to continue</small>
                </div>
            </div>

        <form method="POST" class="role-form">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            
            <div class="roles-grid">
                <!-- PSDS Option -->
                <div class="role-option">
                    <input type="radio" name="role" id="role_psds" value="psds">
                    <label for="role_psds">
                        <div class="role-icon">
                            <i class="fas fa-map-location-dot"></i>
                        </div>
                        <p class="role-name">PSDS</p>
                        <p class="role-description">Public Schools Division Supervisor</p>
                    </label>
                </div>

                <!-- SDC Option -->
                <div class="role-option">
                    <input type="radio" name="role" id="role_sdc" value="sdc">
                    <label for="role_sdc">
                        <div class="role-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <p class="role-name">SDC</p>
                        <p class="role-description">Schools Division Coordinator</p>
                    </label>
                </div>

                <!-- Unit Head Option -->
                <div class="role-option">
                    <input type="radio" name="role" id="role_unit_head" value="unit_head">
                    <label for="role_unit_head">
                        <div class="role-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <p class="role-name">Unit Head</p>
                        <p class="role-description">Unit Head Operations</p>
                    </label>
                </div>
            </div>

            <div class="role-selection-actions" style="margin-top: 30px;">
                <button type="submit" class="btn-role-submit">
                    <i class="fas fa-arrow-right"></i> Continue
                </button>
                <a href="<?= APP_URL ?>/actions/logout.php" class="btn-role-cancel">
                    <i class="fas fa-sign-out-alt"></i> Cancel
                </a>
            </div>
        </form>
        </div>
    </div>
</div>

<script>
document.querySelector('.role-form').addEventListener('submit', function(e) {
    const selectedRole = document.querySelector('input[name="role"]:checked');
    if (!selectedRole) {
        e.preventDefault();
        alert('Please select a role to continue.');
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

