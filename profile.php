<?php
$pageTitle = 'My Profile';
require_once __DIR__ . '/includes/header.php';

// Require user to have selected a role
requireRoleSelection();

$db = getDB();
ensureTwoFactorColumns($db);
ensureUserProfilePhotoColumn($db);

$uid = (int)(currentUser()['id'] ?? 0);
$stmt = $db->prepare('SELECT id, username, full_name, email, role, profile_photo, COALESCE(twofa_enabled,0) AS twofa_enabled, twofa_secret, district_id, is_active, last_login, created_at, updated_at FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$uid]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

// Get district name - selected district from users table
$selectedDistrictName = '';

// Get currently selected district for filtering (from users.district_id)
$selectedDistrictId = getSessionDistrict();
if ($selectedDistrictId !== null) {
    $selectedStmt = $db->prepare('SELECT district_name FROM districts WHERE id = ? LIMIT 1');
    $selectedStmt->execute([$selectedDistrictId]);
    $selectedDistrictName = trim((string)($selectedStmt->fetchColumn() ?? ''));
}

$allowedEditSections = ['info', 'password', 'photo', 'security'];
$requestedEdit = trim((string)($_GET['edit'] ?? ''));
$activeEdit = in_array($requestedEdit, $allowedEditSections, true) ? $requestedEdit : '';

$unlockUntil = (int)($_SESSION['profile_edit_unlock_until'] ?? 0);
if ($unlockUntil < time()) {
    unset($_SESSION['profile_edit_unlock_until']);
    $unlockUntil = 0;
}
$profileEditUnlocked = $unlockUntil >= time();
$unlockError = '';

if (!$account) {
    flash('error', 'Unable to load profile.');
    redirect(APP_URL . '/dashboard.php');
}

$errors = [];

function verifyCurrentPasswordForProfile(PDO $db, int $uid, string $currentPassword): bool {
    if ($currentPassword === '') {
        return false;
    }
    $pwStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $pwStmt->execute([$uid]);
    $hash = (string)$pwStmt->fetchColumn();
    return $hash !== '' && password_verify($currentPassword, $hash);
}

function isStrongPassword(string $password): bool {
    if (strlen($password) < 10) return false;
    if (!preg_match('/[A-Z]/', $password)) return false;
    if (!preg_match('/[a-z]/', $password)) return false;
    if (!preg_match('/\d/', $password)) return false;
    if (!preg_match('/[^A-Za-z0-9]/', $password)) return false;
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = trim((string)($_POST['action'] ?? ''));
    $currentPassword = (string)($_POST['current_password'] ?? '');

    if ($action === 'unlock_edit') {
        $section = trim((string)($_POST['edit_section'] ?? ''));
        if (!in_array($section, $allowedEditSections, true)) {
            $section = 'info';
        }

        if (!verifyCurrentPasswordForProfile($db, $uid, (string)($_POST['unlock_password'] ?? ''))) {
            $unlockError = 'Incorrect password. Please try again.';
            $activeEdit = $section;
        } else {
            $_SESSION['profile_edit_unlock_until'] = time() + 300;
            redirect(APP_URL . '/profile.php?edit=' . urlencode($section));
        }
    }

    if (in_array($action, ['update_info', 'change_password', 'update_2fa', 'update_photo'], true) && !$profileEditUnlocked) {
        flash('error', 'Edit session locked. Confirm your password first.');
        redirect(APP_URL . '/profile.php');
    }

    if ($action === 'update_info') {
        if (!$errors) {
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));

            if ($fullName === '') $errors['full_name'] = 'Full name is required.';
            if ($username === '') $errors['username'] = 'Username is required.';
            if (strlen($username) > 80 || !preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
                $errors['username'] = 'Username may only use letters, numbers, underscore, dot, and dash.';
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email address.';
            }

            if (!$errors) {
                $dup = $db->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
                $dup->execute([$username, $uid]);
                if ($dup->fetchColumn()) {
                    $errors['username'] = 'Username is already taken.';
                }
            }

            if (!$errors) {
                $db->prepare('UPDATE users SET full_name = ?, username = ?, email = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$fullName, $username, $email !== '' ? $email : null, $uid]);

                $_SESSION['full_name'] = $fullName;
                $_SESSION['username'] = $username;

                logActivity('UPDATE', 'profile', $uid, 'Updated personal profile information.');
                flash('success', 'Profile information updated successfully.');
                redirect(APP_URL . '/profile.php?edit=info');
            }
        }
        $activeEdit = 'info';
    }

    if ($action === 'change_password') {
        if (!$errors) {
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if (!isStrongPassword($newPassword)) {
                $errors['new_password'] = 'Use at least 10 characters with upper, lower, number, and special character.';
            }
            if ($newPassword !== $confirmPassword) {
                $errors['confirm_password'] = 'New password and confirmation do not match.';
            }

            if (!$errors) {
                $db->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([password_hash($newPassword, PASSWORD_BCRYPT), $uid]);

                unset($_SESSION['profile_edit_unlock_until']);
                logActivity('UPDATE', 'profile', $uid, 'Changed account password.');
                flash('success', 'Password changed successfully.');
                redirect(APP_URL . '/profile.php');
            }
        }
        $activeEdit = 'password';
    }

    if ($action === 'update_2fa') {
        if (!$errors) {
            $enable2fa = isset($_POST['twofa_enabled']) ? 1 : 0;
            $regenerate = isset($_POST['regenerate_2fa']) ? 1 : 0;
            $existingSecret = trim((string)($_POST['existing_twofa_secret'] ?? ''));
            $twofaSecret = null;

            if ($enable2fa === 1) {
                if ($regenerate === 1 || $existingSecret === '') {
                    $twofaSecret = generateTotpSecret();
                } else {
                    $twofaSecret = $existingSecret;
                }
            }

            $db->prepare('UPDATE users SET twofa_enabled = ?, twofa_secret = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$enable2fa, $twofaSecret, $uid]);

            logActivity('UPDATE', 'profile', $uid, $enable2fa ? 'Updated authenticator 2FA settings (enabled).' : 'Updated authenticator 2FA settings (disabled).');
            flash('success', 'Authenticator settings updated successfully.');
            redirect(APP_URL . '/profile.php?edit=security');
        }
        $activeEdit = 'security';
    }

    if ($action === 'update_photo') {
        if (!$errors) {
            if (empty($_FILES['profile_photo']['name'])) {
                $errors['profile_photo'] = 'Please choose an image file.';
            } else {
                $uploaded = uploadPhoto($_FILES['profile_photo'], (string)($account['profile_photo'] ?? ''));
                if ($uploaded === false) {
                    $errors['profile_photo'] = 'Invalid photo. Use JPG, PNG, or WEBP up to 5MB.';
                } else {
                    $db->prepare('UPDATE users SET profile_photo = ?, updated_at = NOW() WHERE id = ?')
                        ->execute([$uploaded, $uid]);

                    $_SESSION['profile_photo'] = $uploaded;
                    logActivity('UPDATE', 'profile', $uid, 'Updated profile picture.');
                    flash('success', 'Profile picture updated successfully.');
                    redirect(APP_URL . '/profile.php?edit=photo');
                }
            }
        }
        $activeEdit = 'photo';
    }
}

// Reload latest values for display after any validation errors.
$stmt->execute([$uid]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

// Reload selected district name
$selectedDistrictName = '';
$selectedDistrictId = getSessionDistrict();
if ($selectedDistrictId !== null) {
    $selectedStmt = $db->prepare('SELECT district_name FROM districts WHERE id = ? LIMIT 1');
    $selectedStmt->execute([$selectedDistrictId]);
    $selectedDistrictName = trim((string)($selectedStmt->fetchColumn() ?? ''));
}

$secretPreview = trim((string)($account['twofa_secret'] ?? ''));
$otpUri = $secretPreview !== '' ? buildTotpUri((string)$account['username'], $secretPreview) : '';
$qrCodeUrlPrimary = $otpUri !== ''
    ? 'https://quickchart.io/qr?size=220&text=' . rawurlencode($otpUri)
    : '';
$qrCodeUrlFallback = $otpUri !== ''
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($otpUri)
    : '';

$idTokenPayload = [
    'scope' => 'profile_id',
    'uid' => (int)$account['id'],
    'iat' => time(),
    'exp' => strtotime('+3 years'),
];
$idEncryptedToken = encryptSecureToken($idTokenPayload);
$idVerifyUrl = $idEncryptedToken !== false
    ? APP_URL . '/id_verify.php?t=' . rawurlencode($idEncryptedToken)
    : '';
$idCardQrPrimary = $idVerifyUrl !== ''
    ? 'https://quickchart.io/qr?size=240&text=' . rawurlencode($idVerifyUrl)
    : '';
$idCardQrFallback = $idVerifyUrl !== ''
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . rawurlencode($idVerifyUrl)
    : '';
$idInitial = strtoupper(substr((string)$account['full_name'], 0, 1));
$idNumber = 'TPMS-' . str_pad((string)$account['id'], 6, '0', STR_PAD_LEFT);
$idValidUntil = date('F d, Y', strtotime('+3 years'));
$profilePhotoUrl = !empty($account['profile_photo'])
    ? (UPLOAD_URL . rawurlencode((string)$account['profile_photo']))
    : '';
?>

<style>
.profile-id-wrap {
    grid-column: 2;
    grid-row: 1 / span 2;
    align-self: start;
}
.profile-id-card {
    border: 1px solid rgba(148, 163, 184, .35);
    border-radius: 18px;
    padding: 0;
    background:
        radial-gradient(circle at 92% 8%, rgba(56, 189, 248, .22), transparent 28%),
        radial-gradient(circle at 4% 96%, rgba(30, 64, 175, .22), transparent 34%),
        linear-gradient(160deg, #f8fafc, #e2e8f0 58%, #f1f5f9);
    box-shadow: 0 18px 42px rgba(15, 23, 42, .18);
    overflow: hidden;
    position: relative;
}
.profile-id-card::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: linear-gradient(115deg, rgba(255,255,255,.5) 0%, transparent 35%, rgba(255,255,255,.35) 58%, transparent 86%);
    pointer-events: none;
}
.profile-layout {
    grid-template-columns: minmax(360px, 1fr) minmax(320px, 440px) !important;
    align-items: start;
}
.profile-gradient-card {
    position: relative;
    overflow: hidden;
    border: 1px solid var(--glass-border);
    background:
        radial-gradient(circle at 92% 10%, color-mix(in srgb, var(--primary-glow) 45%, transparent), transparent 34%),
        linear-gradient(170deg, var(--glass-bg), color-mix(in srgb, var(--glass-bg) 70%, transparent));
    box-shadow: 0 14px 34px rgba(15, 23, 42, .16);
}
.profile-gradient-card::before {
    content: '';
    position: absolute;
    inset: 0;
    padding: 1px;
    border-radius: inherit;
    background: linear-gradient(120deg, rgba(99,102,241,.55), rgba(14,165,233,.55), rgba(16,185,129,.45), rgba(99,102,241,.55));
    background-size: 260% 260%;
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
    animation: profileBorderFlow 10s linear infinite;
    opacity: .56;
    z-index: 0;
}
.profile-gradient-card > * {
    position: relative;
    z-index: 1;
}
@keyframes profileBorderFlow {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}
.profile-account-card,
.profile-password-card {
    grid-column: 1;
}
.profile-photo-card {
    grid-column: 1;
}
.profile-security-card {
    grid-column: 1 / -1;
}
.profile-id-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin: 0;
    padding: 12px 16px;
    color: #f8fafc;
    background: linear-gradient(95deg, #0f172a, #1d4ed8 54%, #0ea5e9);
}
.profile-id-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 800;
    letter-spacing: .015em;
    line-height: 1.1;
}
.profile-id-badge {
    font-size: .68rem;
    font-weight: 700;
    border: 1px solid rgba(248, 250, 252, .4);
    background: rgba(15, 23, 42, .34);
    color: #e2e8f0;
    padding: 4px 10px;
    border-radius: 999px;
    text-transform: uppercase;
    letter-spacing: .08em;
}
.profile-id-security-strip {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 6px 16px;
    font-size: .66rem;
    font-weight: 700;
    letter-spacing: .11em;
    text-transform: uppercase;
    color: #0f172a;
    background: repeating-linear-gradient(
        -45deg,
        rgba(14, 116, 144, .14) 0 8px,
        rgba(14, 116, 144, .05) 8px 16px
    );
    border-top: 1px solid rgba(148, 163, 184, .24);
    border-bottom: 1px solid rgba(148, 163, 184, .28);
}
.profile-id-main {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 136px;
    gap: 10px;
    align-items: start;
    padding: 12px 12px 10px;
}
.profile-id-person {
    display: grid;
    grid-template-columns: 56px minmax(0,1fr);
    gap: 10px;
    align-items: center;
    margin-bottom: 10px;
}
.profile-id-avatar {
    width: 56px;
    height: 68px;
    border-radius: 9px;
    display: grid;
    place-items: center;
    font-weight: 800;
    font-size: 1.35rem;
    color: #fff;
    background:
        linear-gradient(145deg, rgba(15, 23, 42, .06), rgba(15, 23, 42, .24)),
        linear-gradient(135deg, #1d4ed8, #0ea5e9);
    box-shadow: 0 10px 22px rgba(14, 116, 144, .34);
    border: 2px solid rgba(255, 255, 255, .88);
}
.profile-id-name {
    font-size: .94rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
    text-transform: uppercase;
}
.profile-id-role {
    margin-top: 3px;
    color: #1e293b;
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: .03em;
}
.profile-id-number {
    margin-top: 5px;
    color: #475569;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .08em;
}
.profile-id-meta {
    display: grid;
    gap: 4px;
    margin-bottom: 7px;
}
.profile-id-meta-row {
    display: flex;
    gap: 8px;
    align-items: baseline;
    color: #334155;
    font-size: .76rem;
}
.profile-id-meta-row strong {
    min-width: 66px;
    color: #0f172a;
    letter-spacing: .02em;
}
.profile-id-sign {
    border-top: 1px dashed rgba(71, 85, 105, .45);
    margin-top: 8px;
    padding-top: 6px;
    max-width: 180px;
    font-size: .66rem;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .06em;
}
.profile-id-qr {
    border: 1px solid rgba(148, 163, 184, .38);
    border-radius: 12px;
    background: #fff;
    padding: 5px;
    position: relative;
}
.profile-id-qr::after {
    content: 'SECURE QR';
    position: absolute;
    bottom: 4px;
    left: 50%;
    transform: translateX(-50%);
    font-size: .52rem;
    font-weight: 800;
    letter-spacing: .08em;
    color: #475569;
    background: rgba(255, 255, 255, .88);
    padding: 1px 6px;
    border-radius: 999px;
}
.profile-id-qr img {
    width: 100%;
    height: auto;
    display: block;
}
.profile-id-foot {
    margin-top: 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    border-top: 1px solid rgba(148, 163, 184, .3);
    padding: 8px 12px 10px;
    background: rgba(255, 255, 255, .48);
}
.profile-id-mini {
    display: grid;
    gap: 2px;
    color: #334155;
    font-size: .64rem;
    letter-spacing: .04em;
    text-transform: uppercase;
}
.profile-id-avatar.profile-id-avatar-photo {
    padding: 0;
    overflow: hidden;
    background: #fff;
}
.profile-id-avatar.profile-id-avatar-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.profile-photo-preview {
    width: 88px;
    height: 88px;
    border-radius: 14px;
    border: 2px solid rgba(148,163,184,.35);
    overflow: hidden;
    background: rgba(241,245,249,.8);
    display: grid;
    place-items: center;
    font-weight: 800;
    color: #0f172a;
}
.profile-photo-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.profile-view-list {
    display: grid;
    gap: 8px;
}
.profile-view-row {
    display: grid;
    grid-template-columns: 120px minmax(0, 1fr);
    gap: 10px;
    font-size: .92rem;
    padding: 6px 0;
    border-bottom: 1px dashed rgba(148,163,184,.3);
}
.profile-view-row:last-child {
    border-bottom: 0;
}
.profile-view-row strong {
    color: var(--text-muted);
    font-weight: 700;
}
.profile-view-row span {
    color: var(--text);
    font-weight: 500;
    word-break: break-word;
}
.profile-lock-note {
    margin-top: 8px;
    font-size: .8rem;
    color: var(--text-sub);
}
.swal2-popup.tpms-swal {
    border-radius: 16px !important;
    border: 1px solid var(--glass-border) !important;
    background:
        radial-gradient(circle at 14% 12%, color-mix(in srgb, var(--primary-glow) 46%, transparent), transparent 36%),
        radial-gradient(circle at 88% 88%, color-mix(in srgb, var(--secondary) 20%, transparent), transparent 38%),
        var(--auto-glass-bg, var(--glass-bg)) !important;
    color: var(--auto-text, var(--text)) !important;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    box-shadow: 0 20px 50px rgba(15, 23, 42, .22) !important;
}
.swal2-title.tpms-swal-title {
    color: var(--auto-text, var(--text)) !important;
    font-size: 1.15rem !important;
}
.swal2-html-container.tpms-swal-text {
    color: var(--auto-text-muted, var(--text-muted)) !important;
}
.swal2-input.tpms-swal-input {
    background: var(--auto-select-option-bg, rgba(255, 255, 255, .94)) !important;
    border: 1px solid var(--glass-border) !important;
    color: var(--auto-select-option-text, var(--text)) !important;
    box-shadow: none !important;
}
.swal2-input.tpms-swal-input:focus {
    border-color: color-mix(in srgb, var(--primary) 74%, transparent) !important;
}
.swal2-confirm.tpms-swal-confirm {
    background: linear-gradient(135deg, #6366f1, #0ea5e9) !important;
    box-shadow: 0 8px 20px rgba(59, 130, 246, .35) !important;
}
.swal2-cancel.tpms-swal-cancel {
    background: color-mix(in srgb, var(--glass-bg) 78%, transparent) !important;
    border: 1px solid var(--glass-border) !important;
    color: var(--auto-text, var(--text)) !important;
}
@media (max-width: 760px) {
    .profile-layout {
        grid-template-columns: 1fr !important;
    }
    .profile-account-card,
    .profile-password-card,
    .profile-photo-card,
    .profile-id-wrap,
    .profile-security-card {
        grid-column: 1;
        grid-row: auto;
    }
    .profile-id-main {
        grid-template-columns: 1fr;
    }
    .profile-id-qr {
        max-width: 132px;
    }
    .profile-id-head {
        align-items: flex-start;
        flex-direction: column;
    }
    .profile-id-security-strip {
        gap: 6px;
        font-size: .62rem;
    }
    .profile-view-row {
        grid-template-columns: 1fr;
        gap: 2px;
    }
}
@media print {
    #profileIdPrintHost {
        display: none;
    }
    body.id-print-mode .sidebar,
    body.id-print-mode .main-wrapper,
    body.id-print-mode .sidebar-overlay,
    body.id-print-mode .app-drawer,
    body.id-print-mode .app-drawer-backdrop,
    body.id-print-mode .app-dock {
        display: none !important;
    }
    body.id-print-mode #profileIdPrintHost {
        display: block !important;
        position: fixed;
        inset: 0;
        background: #fff;
        z-index: 999999;
    }
    body.id-print-mode #profileIdPrintHost .profile-id-card-print {
        width: 86mm;
        max-width: 86mm;
        margin: 12mm auto 0;
        box-shadow: none !important;
    }
    body.id-print-mode #profileIdPrintHost .profile-id-foot .btn {
        display: none !important;
    }
    .sidebar,
    .topbar,
    .filter-bar,
    .chart-card:not(.profile-id-wrap),
    .page-footer,
    .mobile-nav,
    .mobile-fab {
        display: none !important;
    }
    .profile-id-wrap,
    .profile-id-card {
        display: block !important;
        box-shadow: none !important;
        border-color: #cbd5e1 !important;
    }
    .profile-id-card {
        max-width: 86mm;
        border-radius: 10px !important;
    }
    body,
    .main-content,
    .content-wrap {
        background: #fff !important;
    }
}
</style>

<div class="filter-bar glass-card">
    <div class="topbar-title">My Profile</div>
</div>

<form method="POST" id="profileUnlockForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="unlock_edit">
    <input type="hidden" name="edit_section" id="unlockEditSection" value="">
    <input type="hidden" name="unlock_password" id="unlockPasswordField" value="">
</form>

<div class="charts-grid profile-layout" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr));">
    <div class="chart-card glass-card profile-id-wrap profile-gradient-card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-id-card"></i> Profile ID Card</h3>
        </div>
        <div class="profile-id-card" id="profileIdCard">
            <div class="profile-id-head">
                <div class="profile-id-brand">
                    <img src="<?= APP_URL ?>/assets/images/logo.png" alt="TPMS" style="width:30px;height:30px;object-fit:contain;">
                    <span>
                        <?= clean(APP_FULL_NAME) ?><br>
                        <small style="opacity:.8;font-weight:600;letter-spacing:.06em;">OFFICIAL IDENTIFICATION</small>
                    </span>
                </div>
                <span class="profile-id-badge">Employee ID</span>
            </div>

            <div class="profile-id-security-strip">
                <span>Department of Education</span>
                <span>Teacher Profiling System</span>
                <span>Non-transferable</span>
            </div>

            <div class="profile-id-main">
                <div>
                    <div class="profile-id-person">
                        <div class="profile-id-avatar <?= $profilePhotoUrl !== '' ? 'profile-id-avatar-photo' : '' ?>">
                            <?php if ($profilePhotoUrl !== ''): ?>
                            <img src="<?= clean($profilePhotoUrl) ?>" alt="Profile Photo">
                            <?php else: ?>
                            <?= clean($idInitial) ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="profile-id-name"><?= clean((string)$account['full_name']) ?></div>
                            <div class="profile-id-role"><?= clean(ucfirst(str_replace('_', ' ', (string)$account['role']))) ?></div>
                            <div class="profile-id-number"><?= clean($idNumber) ?></div>
                        </div>
                    </div>

                    <div class="profile-id-meta">
                        <div class="profile-id-meta-row"><strong>User ID</strong><span><?= clean((string)$account['id']) ?></span></div>
                        <div class="profile-id-meta-row"><strong>Username</strong><span><?= clean((string)$account['username']) ?></span></div>
                        <div class="profile-id-meta-row"><strong>Email</strong><span><?= clean((string)($account['email'] ?: 'N/A')) ?></span></div>
                        <div class="profile-id-meta-row"><strong>Issued</strong><span><?= clean(date('F d, Y')) ?></span></div>
                        <div class="profile-id-meta-row"><strong>Valid Until</strong><span><?= clean($idValidUntil) ?></span></div>
                    </div>
                    <div class="profile-id-sign">Authorized Signature</div>
                </div>

                <div class="profile-id-qr">
                    <img
                        src="<?= clean($idCardQrPrimary) ?>"
                        data-fallback-src="<?= clean($idCardQrFallback) ?>"
                        id="profileIdQrImage"
                        alt="Profile ID QR Code"
                    >
                </div>
            </div>

            <div class="profile-id-foot">
                <div class="profile-id-mini">
                    <span>Scan QR for TPMS encrypted validation</span>
                    <span>This card remains property of <?= clean(APP_NAME) ?></span>
                </div>
                <button type="button" class="btn btn-ghost" id="printProfileCardBtn"><i class="fas fa-print"></i> Print ID Card</button>
            </div>
        </div>
    </div>

    <div class="chart-card glass-card profile-account-card profile-gradient-card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-user"></i> Account Information</h3>
        </div>
        <?php if ($activeEdit === 'info' && $profileEditUnlocked): ?>
        <form method="POST" class="form-grid" style="grid-template-columns:1fr;gap:12px;">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_info">

            <div class="form-group">
                <label class="form-label required">Full Name</label>
                <input type="text" name="full_name" class="form-input" value="<?= clean($_POST['full_name'] ?? (string)$account['full_name']) ?>" required>
                <?php if (!empty($errors['full_name'])): ?><span class="form-error"><?= clean($errors['full_name']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label required">Username</label>
                <input type="text" name="username" class="form-input" value="<?= clean($_POST['username'] ?? (string)$account['username']) ?>" required>
                <?php if (!empty($errors['username'])): ?><span class="form-error"><?= clean($errors['username']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" value="<?= clean($_POST['email'] ?? (string)($account['email'] ?? '')) ?>">
                <?php if (!empty($errors['email'])): ?><span class="form-error"><?= clean($errors['email']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">Role</label>
                <input type="text" class="form-input" value="<?= clean(ucfirst(str_replace('_', ' ', (string)$account['role']))) ?>" readonly>
            </div>

            <div class="modal-actions" style="justify-content:flex-end;">
                <a href="<?= APP_URL ?>/profile.php" class="btn btn-ghost">Close Edit</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Information</button>
            </div>
        </form>
        <?php else: ?>
        <div class="profile-view-list">
            <div class="profile-view-row"><strong>User ID</strong><span><?= clean((string)$account['id']) ?></span></div>
            <div class="profile-view-row"><strong>Full Name</strong><span><?= clean((string)$account['full_name']) ?></span></div>
            <div class="profile-view-row"><strong>Username</strong><span><?= clean((string)$account['username']) ?></span></div>
            <div class="profile-view-row"><strong>Email</strong><span><?= clean((string)($account['email'] ?: 'N/A')) ?></span></div>
            <div class="profile-view-row"><strong>Role</strong><span><?= clean(ucfirst(str_replace('_', ' ', (string)$account['role']))) ?></span></div>
            <div class="profile-view-row"><strong>District</strong><span><?= $selectedDistrictName !== '' ? clean($selectedDistrictName) : '<span style="color: #ef4444;">Not Assigned</span>' ?></span></div>
            <div class="profile-view-row"><strong>2FA Status</strong><span><?= (int)$account['twofa_enabled'] === 1 ? '<span style="color: #22c55e;">✓ Enabled</span>' : '<span style="color: #ef4444;">✗ Disabled</span>' ?></span></div>
            <div class="profile-view-row"><strong>Account Status</strong><span><?= (int)$account['is_active'] === 1 ? '<span style="color: #22c55e;">Active</span>' : '<span style="color: #ef4444;">Inactive</span>' ?></span></div>
            <div class="profile-view-row"><strong>Last Login</strong><span><?= !empty($account['last_login']) ? clean(date('F d, Y \a\t g:i A', strtotime((string)$account['last_login']))) : 'Never' ?></span></div>
            <div class="profile-view-row"><strong>Account Created</strong><span><?= clean(date('F d, Y \a\t g:i A', strtotime((string)$account['created_at']))) ?></span></div>
            <div class="profile-view-row"><strong>Last Updated</strong><span><?= clean(date('F d, Y \a\t g:i A', strtotime((string)$account['updated_at']))) ?></span></div>
        </div>
        <div class="modal-actions" style="justify-content:flex-end;margin-top:12px;">
            <a href="<?= APP_URL ?>/profile.php?edit=info" class="btn btn-primary profile-edit-trigger" data-edit-section="info"><i class="fas fa-pen"></i> Edit Information</a>
        </div>
        <div class="profile-lock-note">Editing requires password confirmation.</div>
        <?php endif; ?>
    </div>

    <div class="chart-card glass-card profile-password-card profile-gradient-card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-key"></i> Change Password</h3>
        </div>
        <?php if ($activeEdit === 'password' && $profileEditUnlocked): ?>
        <form method="POST" class="form-grid" style="grid-template-columns:1fr;gap:12px;">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="change_password">

            <div class="form-group">
                <label class="form-label required">New Password</label>
                <input type="password" name="new_password" class="form-input" autocomplete="new-password" required>
                <?php if (!empty($errors['new_password'])): ?><span class="form-error"><?= clean($errors['new_password']) ?></span><?php endif; ?>
                <small class="text-muted">Minimum 10 characters including uppercase, lowercase, number, and symbol.</small>
            </div>

            <div class="form-group">
                <label class="form-label required">Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-input" autocomplete="new-password" required>
                <?php if (!empty($errors['confirm_password'])): ?><span class="form-error"><?= clean($errors['confirm_password']) ?></span><?php endif; ?>
            </div>

            <div class="modal-actions" style="justify-content:flex-end;">
                <a href="<?= APP_URL ?>/profile.php" class="btn btn-ghost">Close Edit</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-lock"></i> Update Password</button>
            </div>
        </form>
        <?php else: ?>
        <div class="profile-view-list">
            <div class="profile-view-row"><strong>Password</strong><span>Hidden for security</span></div>
            <div class="profile-view-row"><strong>Policy</strong><span>At least 10 chars with uppercase, lowercase, number, and symbol.</span></div>
        </div>
        <div class="modal-actions" style="justify-content:flex-end;margin-top:12px;">
            <a href="<?= APP_URL ?>/profile.php?edit=password" class="btn btn-primary profile-edit-trigger" data-edit-section="password"><i class="fas fa-pen"></i> Edit Password</a>
        </div>
        <div class="profile-lock-note">Editing requires password confirmation and resets the current unlock.</div>
        <?php endif; ?>
    </div>

    <div class="chart-card glass-card profile-photo-card profile-gradient-card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-camera"></i> Profile Picture</h3>
        </div>
        <?php if ($activeEdit === 'photo' && $profileEditUnlocked): ?>
        <form method="POST" enctype="multipart/form-data" class="form-grid" style="grid-template-columns:1fr;gap:12px;">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_photo">

            <div class="form-group" style="display:flex;gap:12px;align-items:center;">
                <div class="profile-photo-preview">
                    <?php if ($profilePhotoUrl !== ''): ?>
                    <img src="<?= clean($profilePhotoUrl) ?>" alt="Current profile photo">
                    <?php else: ?>
                    <?= clean($idInitial) ?>
                    <?php endif; ?>
                </div>
                <div class="text-muted" style="font-size:.82rem;line-height:1.45;">
                    Upload a clear headshot for your profile and ID card.
                    <br>Allowed: JPG, PNG, WEBP. Max size: 5MB.
                </div>
            </div>

            <div class="form-group">
                <label class="form-label required">Select Photo</label>
                <input type="file" name="profile_photo" class="form-input" accept="image/jpeg,image/png,image/webp" required>
                <?php if (!empty($errors['profile_photo'])): ?><span class="form-error"><?= clean($errors['profile_photo']) ?></span><?php endif; ?>
            </div>

            <div class="modal-actions" style="justify-content:flex-end;">
                <a href="<?= APP_URL ?>/profile.php" class="btn btn-ghost">Close Edit</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Upload Profile Picture</button>
            </div>
        </form>
        <?php else: ?>
        <div class="form-group" style="display:flex;gap:12px;align-items:center;">
            <div class="profile-photo-preview">
                <?php if ($profilePhotoUrl !== ''): ?>
                <img src="<?= clean($profilePhotoUrl) ?>" alt="Current profile photo">
                <?php else: ?>
                <?= clean($idInitial) ?>
                <?php endif; ?>
            </div>
            <div class="text-muted" style="font-size:.82rem;line-height:1.45;">
                Profile photo is shown on your ID card and account avatar.
            </div>
        </div>
        <div class="modal-actions" style="justify-content:flex-end;">
            <a href="<?= APP_URL ?>/profile.php?edit=photo" class="btn btn-primary profile-edit-trigger" data-edit-section="photo"><i class="fas fa-pen"></i> Edit Photo</a>
        </div>
        <div class="profile-lock-note">Editing requires password confirmation.</div>
        <?php endif; ?>
    </div>

    <div class="chart-card glass-card profile-security-card profile-gradient-card" style="grid-column:1 / -1;">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-shield-halved"></i> Authenticator Security</h3>
        </div>
        <?php if ($activeEdit === 'security' && $profileEditUnlocked): ?>
        <form method="POST" class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_2fa">
            <input type="hidden" name="existing_twofa_secret" value="<?= clean($secretPreview) ?>">

            <div class="form-group" style="grid-column:1 / -1;">
                <label class="checkbox-label">
                    <input type="checkbox" name="twofa_enabled" value="1" <?= (int)($account['twofa_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <span>Enable authenticator app login verification (2FA)</span>
                </label>
            </div>

            <div class="form-group" style="grid-column:1 / -1;">
                <label class="checkbox-label">
                    <input type="checkbox" name="regenerate_2fa" value="1">
                    <span>Regenerate authenticator secret</span>
                </label>
            </div>

            <div class="form-group" style="grid-column:1 / -1;">
                <label class="form-label">Current Authenticator Secret</label>
                <input type="text" class="form-input" value="<?= clean($secretPreview) ?>" readonly placeholder="Secret appears after enabling 2FA">
                <?php if ($secretPreview !== ''): ?>
                <small class="text-muted">OTP URI: <?= clean(buildTotpUri((string)$account['username'], $secretPreview)) ?></small>
                <?php else: ?>
                <small class="text-muted">Enable 2FA and save to generate your authenticator secret.</small>
                <?php endif; ?>
            </div>

            <?php if ($qrCodeUrlPrimary !== ''): ?>
            <div class="form-group" style="grid-column:1 / -1;">
                <label class="form-label">Authenticator QR Code</label>
                <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start;">
                    <img
                        src="<?= clean($qrCodeUrlPrimary) ?>"
                        data-fallback-src="<?= clean($qrCodeUrlFallback) ?>"
                        alt="Authenticator QR Code"
                        width="220"
                        height="220"
                        id="authQrImage"
                        style="border:1px solid rgba(148,163,184,.35);border-radius:10px;background:#fff;padding:8px;"
                    >
                    <div class="text-muted" style="max-width:460px;line-height:1.5;">
                        Scan this QR code with Google Authenticator or Microsoft Authenticator.
                        If scanning is unavailable, use the secret key above and add it manually.
                        <br><br>
                        If QR is still not visible, open this link:
                        <a href="<?= clean($qrCodeUrlPrimary) ?>" target="_blank" rel="noopener" style="text-decoration:underline;">Open QR in new tab</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="modal-actions" style="grid-column:1 / -1;justify-content:flex-end;">
                <a href="<?= APP_URL ?>/profile.php" class="btn btn-ghost">Close Edit</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-shield-halved"></i> Save Security Settings</button>
            </div>
        </form>
        <?php else: ?>
        <div class="profile-view-list">
            <div class="profile-view-row"><strong>2FA Status</strong><span><?= (int)($account['twofa_enabled'] ?? 0) === 1 ? 'Enabled' : 'Disabled' ?></span></div>
            <div class="profile-view-row"><strong>Secret</strong><span><?= $secretPreview !== '' ? 'Configured' : 'Not yet configured' ?></span></div>
        </div>
        <div class="modal-actions" style="justify-content:flex-end;margin-top:12px;">
            <a href="<?= APP_URL ?>/profile.php?edit=security" class="btn btn-primary profile-edit-trigger" data-edit-section="security"><i class="fas fa-pen"></i> Edit Security</a>
        </div>
        <div class="profile-lock-note">Editing requires password confirmation.</div>
        <?php endif; ?>
    </div>
</div>

<?php if ($otpUri !== ''): ?>
<script>
(function() {
    const img = document.getElementById('authQrImage');
    if (!img) return;

    let triedFallback = false;
    img.addEventListener('error', function() {
        const fallbackSrc = img.getAttribute('data-fallback-src') || '';
        if (!triedFallback && fallbackSrc) {
            triedFallback = true;
            img.src = fallbackSrc;
            return;
        }

        img.style.display = 'none';
    });
})();
</script>
<?php endif; ?>

<script>
(function() {
    const unlockForm = document.getElementById('profileUnlockForm');
    const unlockSection = document.getElementById('unlockEditSection');
    const unlockPassword = document.getElementById('unlockPasswordField');
    const editTriggers = document.querySelectorAll('.profile-edit-trigger');
    const isUnlocked = <?= $profileEditUnlocked ? 'true' : 'false' ?>;
    const pendingEditSection = <?= json_encode(($activeEdit !== '' && !$profileEditUnlocked) ? $activeEdit : '') ?>;
    const unlockError = <?= json_encode($unlockError) ?>;

    function submitUnlock(section, password) {
        if (!unlockForm || !unlockSection || !unlockPassword) return;
        unlockSection.value = section;
        unlockPassword.value = password;
        unlockForm.submit();
    }

    async function openUnlockPrompt(section, errorText) {
        if (typeof Swal === 'undefined') {
            const fallback = window.prompt('Enter password to continue editing:') || '';
            if (fallback.trim() !== '') submitUnlock(section, fallback.trim());
            return;
        }

        const result = await Swal.fire({
            title: 'Confirm Password',
            text: errorText || 'Enter your current password to unlock editing.',
            input: 'password',
            inputPlaceholder: 'Current password',
            inputAttributes: { autocapitalize: 'off', autocorrect: 'off', autocomplete: 'current-password' },
            showCancelButton: true,
            confirmButtonText: 'Unlock',
            cancelButtonText: 'Cancel',
            customClass: {
                popup: 'tpms-swal',
                title: 'tpms-swal-title',
                htmlContainer: 'tpms-swal-text',
                input: 'tpms-swal-input',
                confirmButton: 'tpms-swal-confirm',
                cancelButton: 'tpms-swal-cancel'
            },
            inputValidator: (value) => {
                if (!value || !value.trim()) return 'Password is required.';
                return undefined;
            }
        });

        if (result.isConfirmed && result.value) {
            submitUnlock(section, result.value.trim());
            return;
        }

        if (pendingEditSection) {
            window.location.href = <?= json_encode(APP_URL . '/profile.php') ?>;
        }
    }

    editTriggers.forEach(function(link) {
        link.addEventListener('click', function(e) {
            if (isUnlocked) return;
            e.preventDefault();
            const section = link.getAttribute('data-edit-section') || 'info';
            openUnlockPrompt(section, 'Enter your current password to continue.');
        });
    });

    if (pendingEditSection) {
        openUnlockPrompt(pendingEditSection, unlockError || 'Enter your current password to continue.');
    }

    const printBtn = document.getElementById('printProfileCardBtn');
    const card = document.getElementById('profileIdCard');

    function getPrintHost() {
        let host = document.getElementById('profileIdPrintHost');
        if (!host) {
            host = document.createElement('div');
            host.id = 'profileIdPrintHost';
            document.body.appendChild(host);
        }
        return host;
    }

    if (printBtn) {
        printBtn.addEventListener('click', function() {
            if (!card) return;
            const host = getPrintHost();
            host.innerHTML = '';

            const clone = card.cloneNode(true);
            clone.id = 'profileIdCardPrint';
            clone.classList.add('profile-id-card-print');
            host.appendChild(clone);

            document.body.classList.add('id-print-mode');

            // Allow layout to apply before opening print dialog.
            setTimeout(function() {
                window.print();
            }, 60);
        });
    }

    window.addEventListener('afterprint', function() {
        document.body.classList.remove('id-print-mode');
        const host = document.getElementById('profileIdPrintHost');
        if (host) host.innerHTML = '';
    });

    const qrImg = document.getElementById('profileIdQrImage');
    if (!qrImg) return;

    let fallbackTried = false;
    qrImg.addEventListener('error', function() {
        const fallbackSrc = qrImg.getAttribute('data-fallback-src') || '';
        if (!fallbackTried && fallbackSrc) {
            fallbackTried = true;
            qrImg.src = fallbackSrc;
            return;
        }
        qrImg.alt = 'Profile QR unavailable';
        qrImg.style.opacity = '.45';
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>