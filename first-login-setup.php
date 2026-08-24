<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

startSecureSession();
sendSecurityHeaders();
requireLogin();

$db = getDB();
ensureUserOnboardingColumns($db);

$user = currentUser();
$userId = (int)($user['id'] ?? 0);

// Load user's saved theme, layout, and density
$userTheme = $user['preferred_theme'] ?? 'frosted-glass';
$userLayout = $user['preferred_layout'] ?? 'default';
$userDensity = 'comfortable'; // Default density

// Try to parse appearance settings if stored as JSON
if (!empty($user['preferred_appearance_json'])) {
    try {
        $settings = json_decode($user['preferred_appearance_json'], true);
        if (is_array($settings)) {
            $userDensity = $settings['density'] ?? 'comfortable';
        }
    } catch (Throwable) {}
}

// Store in session for quick access
$_SESSION['user_theme'] = $userTheme;
$_SESSION['user_layout'] = $userLayout;
$_SESSION['user_density'] = $userDensity;

// Only show first login setup if onboarding is not completed
if (!needsOnboarding()) {
    redirect(APP_URL . '/dashboard');
}

// Get current step (default to 1)
$step = (int)($_GET['step'] ?? 1);
if ($step < 1 || $step > 4) $step = 1;

// If user has completed onboarding but hasn't activated 2FA, skip directly to Step 3
if (!empty($user['onboarding_completed_at']) && !$_SESSION['twofa_enabled']) {
    $step = 3;
}

// Initialize 2FA variables
$twoFaSecret = null;
$twoFaQrUri = null;

// Generate 2FA secret for Step 3 if needed
if ($step === 3 && !isset($_SESSION['setup_2fa_secret'])) {
    require_once __DIR__ . '/includes/auth.php';
    $_SESSION['setup_2fa_secret'] = generateTotpSecret();
    error_log("Step 3: Generated NEW 2FA secret: " . substr($_SESSION['setup_2fa_secret'], 0, 8) . "...");
} else if ($step === 3) {
    error_log("Step 3: Using existing 2FA secret from session: " . substr($_SESSION['setup_2fa_secret'] ?? 'NONE', 0, 8) . "...");
}

if ($step === 3) {
    $twoFaSecret = $_SESSION['setup_2fa_secret'] ?? null;
    error_log("Step 3: twoFaSecret = " . ($twoFaSecret ? substr($twoFaSecret, 0, 8) . "..." : "NULL"));
    if ($twoFaSecret) {
        $twoFaQrUri = buildTotpUri($user['username'], $twoFaSecret);
        error_log("Step 3: QR URI = " . substr($twoFaQrUri, 0, 50) . "...");
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$postedCsrf)) {
        $error = 'Session expired. Please try again.';
    } else {
        $nextStep = (int)($_POST['next_step'] ?? $step + 1);
        $action = $_POST['action'] ?? 'next';
        
        // Debug: Log form submission
        error_log("Form submitted - Current Step: $step, Next Step: $nextStep, Action: $action");
        error_log("POST data: " . print_r($_POST, true));
        
        // Save appearance settings when moving from Step 2 to Step 3
        if ($step === 2 && $nextStep === 3) {
            error_log("Processing Step 2 to Step 3 transition");
            try {
                $theme = clean($_POST['theme'] ?? 'frosted-glass');
                $layout = clean($_POST['layout'] ?? 'default');
                $density = clean($_POST['density'] ?? 'comfortable');
                
                error_log("Theme: $theme, Layout: $layout, Density: $density");
                
                $settings = json_encode([
                    'theme' => $theme,
                    'layout' => $layout,
                    'density' => $density
                ]);
                
                $stmt = $db->prepare('
                    UPDATE users 
                    SET preferred_theme = ?, preferred_layout = ?, preferred_appearance_json = ?, updated_at = NOW() 
                    WHERE id = ? LIMIT 1
                ');
                $stmt->execute([$theme, $layout, $settings, $userId]);
                
                $_SESSION['user_theme'] = $theme;
                $_SESSION['user_layout'] = $layout;
                $_SESSION['user_density'] = $density;
                
                logActivity('appearance_updated', 'User updated appearance preferences', $userId);
                
                error_log("Redirecting to step 3");
                // Redirect to next step
                redirect(APP_URL . '/first-login-setup?step=' . $nextStep);
            } catch (Exception $e) {
                error_log("Error in Step 2-3 transition: " . $e->getMessage());
                $error = 'Failed to save appearance settings: ' . $e->getMessage();
            }
        }
        // Process 2FA activation and move to Step 4 (Step 3 to Step 4)
        elseif ($step === 3 && $nextStep === 4) {
            // Handle 2FA activation or skip
            $twoFaEnabled = false;
            
            error_log("Step 3: Action = '$action', TOTP Code = '" . ($_POST['totp_code'] ?? 'EMPTY') . "'");
            
            // Check if user clicked skip - don't allow it, force activation
            if ($action === 'skip_2fa') {
                $error = 'Two-factor authentication is required. Please enter your authenticator code to continue.';
                error_log("Step 3: User attempted to skip 2FA - NOT ALLOWED");
            } elseif ($action === 'activate_2fa') {
                if (empty($_POST['totp_code'])) {
                    $error = 'Please enter the 6-digit code from your authenticator app.';
                } else {
                    // Verify TOTP code and activate 2FA
                    require_once __DIR__ . '/includes/auth.php';
                    $totp_code = (string)($_POST['totp_code'] ?? '');
                    $setup_secret = $_SESSION['setup_2fa_secret'] ?? null;
                    
                    error_log("Step 3: Verifying 2FA - Secret = '" . ($setup_secret ? substr($setup_secret, 0, 8) . '...' : 'NULL') . "', Code = '$totp_code'");
                    
                    if ($setup_secret && verifyTotpCode($setup_secret, $totp_code)) {
                        $twoFaEnabled = true;
                        error_log("Step 3: 2FA verification SUCCESSFUL");
                    } else {
                        $error = 'Invalid authenticator code. Please try again.';
                        error_log("Step 3: 2FA verification FAILED - " . ($setup_secret ? "Code mismatch" : "No secret in session"));
                    }
                }
            }
            
            if (empty($error)) {
                // Save 2FA settings temporarily in session for Step 4
                $_SESSION['temp_twofa_enabled'] = $twoFaEnabled;
                $_SESSION['temp_twofa_secret'] = $twoFaEnabled ? ($_SESSION['setup_2fa_secret'] ?? null) : null;
                error_log("Step 3: Proceeding to Step 4 - 2FA Enabled = " . ($twoFaEnabled ? 'YES' : 'NO'));
                redirect(APP_URL . '/first-login-setup?step=' . $nextStep);
            } else {
                error_log("Step 3: Error occurred - $error");
            }
        }
        // Process agreement acceptance and complete onboarding (Step 4 to Step 5)
        elseif ($step === 4 && $nextStep > 4) {
            // Verify agreement acceptance
            $agreementAccepted = isset($_POST['accept_agreement']) && $_POST['accept_agreement'] === '1';
            
            if (!$agreementAccepted) {
                $error = 'You must accept the User Confidentiality, Data Privacy, and Acceptable Use Agreement to continue.';
            }
            
            // Save agreement acceptance and complete onboarding
            if (empty($error)) {
                try {
                    // Get current appearance settings
                    $currentSettings = $db->prepare('SELECT preferred_theme, preferred_layout, preferred_appearance_json FROM users WHERE id = ?');
                    $currentSettings->execute([$userId]);
                    $result = $currentSettings->fetch();
                    
                    error_log("Step 4: Current appearance settings - Theme: " . ($result['preferred_theme'] ?? 'NULL') . ", Layout: " . ($result['preferred_layout'] ?? 'NULL'));
                    
                    // Get 2FA settings from session
                    $twoFaEnabled = $_SESSION['temp_twofa_enabled'] ?? false;
                    $setupSecret = $_SESSION['temp_twofa_secret'] ?? null;
                    
                    error_log("Step 4: Saving 2FA settings - Enabled: " . ($twoFaEnabled ? 'YES' : 'NO') . ", Secret: " . ($setupSecret ? 'SET' : 'NULL'));
                    
                    // Always mark onboarding complete when agreement is accepted
                    $stmt = $db->prepare('
                        UPDATE users 
                        SET preferred_theme = ?, preferred_layout = ?, preferred_appearance_json = ?, 
                            twofa_enabled = ?, twofa_secret = ?,
                            terms_accepted_at = NOW(),
                            onboarding_completed_at = NOW(), updated_at = NOW() 
                        WHERE id = ? LIMIT 1
                    ');
                    
                    $updateData = [
                        $result['preferred_theme'] ?? 'frosted-glass',
                        $result['preferred_layout'] ?? 'default',
                        $result['preferred_appearance_json'] ?? json_encode(['theme' => 'frosted-glass', 'layout' => 'default', 'density' => 'comfortable']),
                        ($twoFaEnabled ? 1 : 0),
                        ($twoFaEnabled ? $setupSecret : null),
                        $userId
                    ];
                    
                    error_log("Step 4: Executing update with data: " . json_encode([
                        'theme' => $updateData[0],
                        'layout' => $updateData[1],
                        'twofa_enabled' => $updateData[3],
                        'twofa_secret_set' => ($updateData[4] ? 'YES' : 'NO'),
                        'user_id' => $updateData[5]
                    ]));
                    
                    $stmt->execute($updateData);
                    
                    error_log("Step 4: Database update successful - Rows affected: " . $stmt->rowCount());
                    
                    $_SESSION['needs_onboarding'] = false;
                    $_SESSION['twofa_enabled'] = $twoFaEnabled;
                    
                    // Log activity
                    if ($twoFaEnabled) {
                        logActivity('2fa_enabled', 'Two-factor authentication activated', $userId);
                    } else {
                        logActivity('2fa_skipped', 'User skipped two-factor authentication setup', $userId);
                    }
                    
                    logActivity('terms_accepted', 'User accepted confidentiality and privacy agreement', $userId);
                    
                    // Clear 2FA setup session
                    unset($_SESSION['setup_2fa_secret']);
                    unset($_SESSION['temp_twofa_enabled']);
                    unset($_SESSION['temp_twofa_secret']);
                    
                    redirect(APP_URL . '/dashboard');
                } catch (Throwable $e) {
                    error_log('First login setup error: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
                    $error = 'Failed to save settings. Error: ' . $e->getMessage();
                }
            }
        } else {
            // Go to next step (Step 1 to Step 2, or other navigation)
            redirect(APP_URL . '/first-login-setup?step=' . $nextStep);
        }
    }
}

$csrf = csrfToken();
$appName = APP_NAME ?? 'TPMS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>First Login Setup - <?= $appName ?></title>
    
    <!-- Apply theme early to prevent flash -->
    <script>
        const savedTheme = '<?= $userTheme ?>';
        const savedLayout = '<?= $userLayout ?>';
        const savedDensity = '<?= $userDensity ?>';
        
        document.documentElement.setAttribute('data-theme', savedTheme);
        document.documentElement.setAttribute('data-layout', savedLayout);
        document.documentElement.setAttribute('data-density', savedDensity);
    </script>
    
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/images/logo.png">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/fonts/inter/inter.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= urlencode((string)(is_file(__DIR__ . '/assets/css/style.css') ? filemtime(__DIR__ . '/assets/css/style.css') : '1')) ?>">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }

        body.setup-page {
            min-height: 100vh;
            display: flex;
            align-items: stretch;
            justify-content: center;
            padding: clamp(10px, 3vw, 24px);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            position: relative;
            overflow-y: auto;
        }

        /* Theme variations */
        body.setup-page.theme-frosted {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
        }

        body.setup-page.theme-dark {
            background: linear-gradient(135deg, #1f2937 0%, #0f172a 50%, #111827 100%);
        }

        body.setup-page.theme-light {
            background: linear-gradient(135deg, #f0f4ff 0%, #e0e7ff 50%, #ede9ff 100%);
        }

        .setup-container {
            position: relative;
            z-index: 10;
            max-width: clamp(300px, 95vw, 900px);
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: clamp(8px, 2vw, 16px);
        }

        .setup-card {
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(20px);
            border-radius: clamp(18px, 4vw, 28px);
            padding: clamp(20px, 4vw, 50px) clamp(16px, 3.5vw, 40px);
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.2), 0 0 1px rgba(255, 255, 255, 0.5) inset;
            border: 1px solid rgba(255, 255, 255, 0.8);
            animation: slideUpIn 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
            transition: all 0.3s ease;
            width: 100%;
        }

        /* Layout variations */
        .setup-card.layout-default {
            max-width: 900px;
            padding: 50px 40px;
        }

        .setup-card.layout-compact {
            max-width: 700px;
            padding: 40px 30px;
        }

        .setup-card.layout-wide {
            max-width: 1100px;
            padding: 60px 50px;
        }

        /* Density variations */
        .setup-card.density-comfortable {
            line-height: 1.8;
        }

        .setup-card.density-comfortable .setup-options,
        .setup-card.density-comfortable .theme-grid,
        .setup-card.density-comfortable .update-item {
            gap: 24px;
        }

        .setup-card.density-compact {
            line-height: 1.4;
        }

        .setup-card.density-compact .setup-options,
        .setup-card.density-compact .theme-grid,
        .setup-card.density-compact .update-item {
            gap: 12px;
        }

        .setup-card.density-spacious {
            line-height: 2;
        }

        .setup-card.density-spacious .setup-options,
        .setup-card.density-spacious .theme-grid,
        .setup-card.density-spacious .update-item {
            gap: 32px;
        }

        /* Card always maintains glass-morphism appearance regardless of theme */
        .setup-card {
            background: rgba(255, 255, 255, 0.97) !important;
            border: 1px solid rgba(255, 255, 255, 0.8) !important;
            color: #0f172a !important;
            backdrop-filter: blur(20px) !important;
        }

        /* Appearance option labels - Base styling */
        label[for="frosted"],
        label[for="dark"],
        label[for="light"],
        label[for="layout-default"],
        label[for="layout-compact"],
        label[for="layout-wide"],
        label[for="density-comfortable"],
        label[for="density-compact"],
        label[for="density-spacious"] {
            display: block;
            padding: 16px;
            border-radius: 14px;
            border: 2px solid #e0e7ff;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            background-color: transparent;
            box-shadow: none;
            transform: scale(1);
        }

        /* Checked state styling - using adjacent sibling selector */
        input[type="radio"]:checked + label {
            border-color: #6366f1;
            background-color: rgba(99, 102, 241, 0.08);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            transform: scale(1.02);
        }

        body.setup-page.theme-dark .setup-title,
        body.setup-page.theme-dark .setup-subtitle,
        body.setup-page.theme-dark .option-category-title {
            color: #f3f4f6;
        }

        body.setup-page.theme-dark .option-card,
        body.setup-page.theme-dark .theme-card {
            background: #1f2937;
            border-color: #374151;
            color: #d1d5db;
        }

        /* Light theme card adaptation */
        body.setup-page.theme-light .setup-card {
            background: rgba(249, 250, 251, 0.98);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
        }

        .theme-label {
            display: inline-block;
            margin-bottom: 8px;
            padding: 4px 12px;
            background: #f0f4ff;
            color: #667eea;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        body.setup-page.theme-dark .theme-label {
            background: #374151;
            color: #93c5fd;
        }

        body.setup-page.theme-light .theme-label {
            background: #dbeafe;
            color: #1e40af;
        }

        @keyframes slideUpIn {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .setup-header {
            margin-bottom: clamp(24px, 4vw, 48px);
            text-align: center;
        }

        .setup-steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
            gap: clamp(8px, 2vw, 16px);
            margin-bottom: clamp(24px, 4vw, 48px);
        }

        .step-indicator {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: clamp(6px, 1vw, 12px);
            opacity: 0.5;
            transition: opacity 0.3s ease;
        }

        .step-indicator.active {
            opacity: 1;
        }

        .step-number {
            width: clamp(36px, 8vw, 44px);
            height: clamp(36px, 8vw, 44px);
            border-radius: 50%;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: clamp(12px, 2vw, 16px);
            color: #667eea;
            border: 2px solid #e2e8f0;
        }

        .step-indicator.active .step-number {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
        }

        .step-label {
            font-size: clamp(10px, 1.5vw, 13px);
            font-weight: 600;
            color: #64748b;
            text-align: center;
            line-height: 1.3;
        }

        .setup-title {
            font-size: clamp(20px, 4.5vw, 36px);
            font-weight: 900;
            color: #0f172a;
            margin-bottom: clamp(8px, 1.5vw, 12px);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1.2;
        }

        .setup-subtitle {
            font-size: clamp(13px, 2vw, 16px);
            color: #64748b;
            font-weight: 500;
        }

        .setup-content {
            display: none;
        }

        .setup-content.active {
            display: block;
            animation: fadeIn 0.4s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .update-item {
            padding: clamp(14px, 2.5vw, 20px);
            border-radius: clamp(12px, 2vw, 16px);
            border: 2px solid #e2e8f0;
            margin-bottom: clamp(10px, 2vw, 16px);
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            transition: all 0.3s ease;
        }

        .update-title {
            font-weight: 700;
            color: #0f172a;
            margin-bottom: clamp(4px, 1vw, 6px);
            display: flex;
            align-items: center;
            gap: clamp(6px, 1.5vw, 10px);
            font-size: clamp(12px, 2vw, 15px);
        }

        .update-title i {
            color: #667eea;
            font-size: clamp(14px, 2.5vw, 18px);
        }

        .update-desc {
            font-size: clamp(12px, 1.8vw, 14px);
            color: #64748b;
            margin: 0;
            line-height: 1.5;
        }

        .theme-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: clamp(10px, 2vw, 16px);
            margin-bottom: clamp(20px, 3vw, 32px);
        }

        .theme-card {
            padding: clamp(12px, 2vw, 16px);
            border-radius: clamp(12px, 2vw, 16px);
            border: 2px solid #e2e8f0;
            text-align: center;
            background: white;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .theme-preview {
            width: 100%;
            height: clamp(50px, 12vw, 80px);
            border-radius: clamp(6px, 1vw, 8px);
            margin-bottom: clamp(8px, 1.5vw, 12px);
            border: 1px solid #e2e8f0;
        }

        .theme-name {
            font-weight: 600;
            font-size: clamp(11px, 1.5vw, 13px);
            color: #0f172a;
        }

        .setup-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: clamp(16px, 3vw, 32px);
            margin: clamp(20px, 3vw, 40px) 0;
        }

        .option-category {
            display: flex;
            flex-direction: column;
            gap: clamp(10px, 2vw, 16px);
        }

        .option-category-title {
            font-weight: 700;
            font-size: clamp(11px, 1.8vw, 14px);
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .option-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: clamp(8px, 1.5vw, 12px);
        }

        .option-card {
            padding: clamp(10px, 1.5vw, 14px) clamp(8px, 1.5vw, 10px);
            border-radius: clamp(10px, 1.8vw, 12px);
            border: 2px solid #e2e8f0;
            text-align: center;
            background: white;
            transition: all 0.3s ease;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: clamp(6px, 1vw, 8px);
            min-height: clamp(80px, 18vw, 100px);
            justify-content: center;
        }

        .option-card i {
            font-size: clamp(16px, 3.5vw, 24px);
            color: #667eea;
        }

        .option-card p {
            margin: 0;
            font-size: clamp(10px, 1.5vw, 12px);
            font-weight: 600;
            color: #0f172a;
            line-height: 1.3;
        }

        .setup-actions {
            display: flex;
            gap: clamp(8px, 2vw, 12px);
            justify-content: center;
            flex-wrap: wrap;
            margin-top: clamp(24px, 4vw, 48px);
        }

        .setup-btn {
            padding: clamp(10px, 1.5vw, 14px) clamp(16px, 3vw, 28px);
            border-radius: clamp(11px, 2vw, 12px);
            border: 0;
            font-weight: 700;
            font-size: clamp(12px, 1.8vw, 14px);
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: clamp(6px, 1vw, 8px);
            min-height: 44px;
            -webkit-tap-highlight-color: transparent;
        }

        .btn-back {
            background: #f1f5f9;
            color: #334155;
        }

        .btn-back:hover {
            background: #e2e8f0;
        }

        .btn-next {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            min-width: auto;
        }

        .btn-next:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 40px rgba(102, 126, 234, 0.35);
        }

        .btn-skip {
            background: #94a3b8;
            color: white;
            min-width: auto;
        }

        .btn-skip:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 40px rgba(148, 163, 184, 0.35);
            background: #64748b;
        }

        @media (hover: none) {
            .btn-next:hover {
                transform: none;
            }
            .btn-skip:hover {
                transform: none;
            }
        }

        .progress-info {
            text-align: center;
            font-size: clamp(10px, 1.5vw, 13px);
            color: #94a3b8;
            margin-top: clamp(16px, 2.5vw, 24px);
            line-height: 1.5;
        }

        /* ── RESPONSIVE BREAKPOINTS ───────────────────────────────── */

        @media (max-width: 1024px) {
            .setup-card {
                padding: clamp(28px, 4vw, 40px) clamp(20px, 3vw, 30px);
            }
        }

        @media (max-width: 768px) {
            body.setup-page {
                padding: clamp(10px, 2vw, 16px);
            }

            .setup-card {
                padding: clamp(18px, 3.5vw, 40px) clamp(14px, 3vw, 28px);
                border-radius: clamp(16px, 3vw, 24px);
            }

            .setup-header {
                margin-bottom: clamp(20px, 3vw, 36px);
            }

            .setup-steps {
                grid-template-columns: repeat(auto-fit, minmax(70px, 1fr));
                gap: clamp(6px, 1.5vw, 12px);
                margin-bottom: clamp(20px, 3vw, 36px);
            }

            .theme-grid {
                grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
                gap: clamp(8px, 1.5vw, 12px);
                margin-bottom: clamp(16px, 2.5vw, 24px);
            }

            .setup-options {
                grid-template-columns: 1fr;
                gap: clamp(16px, 3vw, 24px);
                margin: clamp(16px, 2.5vw, 24px) 0;
            }

            .option-cards {
                grid-template-columns: repeat(3, 1fr);
                gap: clamp(8px, 1.5vw, 10px);
            }

            .setup-actions {
                gap: clamp(8px, 1.5vw, 10px);
                margin-top: clamp(20px, 3vw, 36px);
            }

            .setup-btn {
                padding: clamp(9px, 1.2vw, 12px) clamp(14px, 2.5vw, 24px);
                font-size: clamp(11px, 1.6vw, 13px);
                min-height: 42px;
            }
        }

        @media (max-width: 480px) {
            body.setup-page {
                padding: clamp(8px, 1.5vw, 12px);
                align-items: flex-start;
            }

            .setup-container {
                min-height: auto;
                padding: clamp(6px, 1vw, 8px);
                margin-top: clamp(8px, 1.5vw, 12px);
                margin-bottom: clamp(8px, 1.5vw, 12px);
            }

            .setup-card {
                padding: clamp(14px, 2.5vw, 28px) clamp(12px, 2.5vw, 16px);
                border-radius: clamp(14px, 2.5vw, 20px);
            }

            .setup-header {
                margin-bottom: clamp(16px, 2.5vw, 24px);
            }

            .setup-title {
                font-size: clamp(18px, 4vw, 22px);
                line-height: 1.2;
                margin-bottom: clamp(4px, 1vw, 8px);
            }

            .setup-subtitle {
                font-size: clamp(11px, 1.8vw, 12px);
            }

            .setup-steps {
                grid-template-columns: repeat(auto-fit, minmax(60px, 1fr));
                gap: clamp(6px, 1.2vw, 8px);
                margin-bottom: clamp(14px, 2vw, 20px);
            }

            .step-number {
                width: clamp(32px, 7vw, 36px);
                height: clamp(32px, 7vw, 36px);
                font-size: clamp(11px, 1.8vw, 13px);
            }

            .step-label {
                font-size: clamp(8px, 1.2vw, 10px);
                line-height: 1.2;
            }

            .update-item {
                padding: clamp(10px, 2vw, 14px);
                margin-bottom: clamp(8px, 1.5vw, 10px);
                border-radius: clamp(10px, 1.8vw, 12px);
            }

            .update-title {
                gap: clamp(5px, 1vw, 8px);
                font-size: clamp(11px, 1.6vw, 12px);
            }

            .update-title i {
                font-size: clamp(12px, 2vw, 16px);
            }

            .update-desc {
                font-size: clamp(10px, 1.5vw, 12px);
            }

            .theme-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: clamp(8px, 1.5vw, 10px);
                margin-bottom: clamp(12px, 2vw, 16px);
            }

            .theme-card {
                padding: clamp(10px, 1.5vw, 12px);
            }

            .theme-preview {
                height: clamp(40px, 10vw, 50px);
                margin-bottom: clamp(6px, 1vw, 8px);
            }

            .theme-name {
                font-size: clamp(9px, 1.3vw, 11px);
            }

            .setup-options {
                grid-template-columns: 1fr;
                gap: clamp(12px, 2vw, 16px);
                margin: clamp(12px, 2vw, 16px) 0;
            }

            .option-category {
                gap: clamp(8px, 1.5vw, 10px);
            }

            .option-category-title {
                font-size: clamp(10px, 1.5vw, 11px);
            }

            .option-cards {
                grid-template-columns: repeat(3, 1fr);
                gap: clamp(6px, 1vw, 8px);
            }

            .option-card {
                padding: clamp(8px, 1.2vw, 10px) clamp(6px, 1vw, 8px);
                min-height: clamp(70px, 15vw, 80px);
                gap: clamp(4px, 0.8vw, 6px);
                border-radius: clamp(10px, 1.5vw, 12px);
            }

            .option-card i {
                font-size: clamp(12px, 2.5vw, 18px);
            }

            .option-card p {
                font-size: clamp(9px, 1.3vw, 10px);
            }

            .setup-actions {
                flex-direction: column;
                gap: clamp(8px, 1.5vw, 10px);
                margin-top: clamp(16px, 2.5vw, 24px);
            }

            .setup-btn {
                width: 100%;
                padding: clamp(9px, 1.2vw, 11px) clamp(12px, 2vw, 16px);
                font-size: clamp(11px, 1.5vw, 12px);
                min-height: 40px;
                gap: clamp(4px, 0.8vw, 6px);
            }

            .progress-info {
                font-size: clamp(9px, 1.3vw, 10px);
                margin-top: clamp(12px, 2vw, 16px);
            }
        }

        @media (max-width: 360px) {
            body.setup-page {
                padding: 6px;
                align-items: flex-start;
            }

            .setup-container {
                min-height: auto;
                padding: 4px;
                margin-top: 6px;
                margin-bottom: 6px;
            }

            .setup-card {
                padding: 12px 10px;
                border-radius: 14px;
            }

            .setup-header {
                margin-bottom: 12px;
            }

            .setup-title {
                font-size: 16px;
                margin-bottom: 3px;
            }

            .setup-subtitle {
                font-size: 10px;
            }

            .setup-steps {
                gap: 6px;
                margin-bottom: 12px;
                grid-template-columns: repeat(auto-fit, minmax(50px, 1fr));
            }

            .step-number {
                width: 30px;
                height: 30px;
                font-size: 11px;
            }

            .step-label {
                font-size: 8px;
            }

            .update-item {
                padding: 10px;
                margin-bottom: 8px;
            }

            .update-title {
                gap: 5px;
                font-size: 11px;
            }

            .update-title i {
                font-size: 12px;
            }

            .update-desc {
                font-size: 10px;
            }

            .theme-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
                margin-bottom: 12px;
            }

            .theme-card {
                padding: 10px;
            }

            .theme-preview {
                height: 40px;
                margin-bottom: 6px;
            }

            .theme-name {
                font-size: 9px;
            }

            .setup-options {
                grid-template-columns: 1fr;
                gap: 10px;
                margin: 10px 0;
            }

            .option-category {
                gap: 8px;
            }

            .option-category-title {
                font-size: 9px;
            }

            .option-cards {
                grid-template-columns: repeat(3, 1fr);
                gap: 6px;
            }

            .option-card {
                padding: 8px 6px;
                min-height: 70px;
                gap: 4px;
                border-radius: 10px;
            }

            .option-card i {
                font-size: 12px;
            }

            .option-card p {
                font-size: 8px;
            }

            .setup-actions {
                flex-direction: column;
                gap: 8px;
                margin-top: 12px;
            }

            .setup-btn {
                width: 100%;
                padding: 8px 10px;
                font-size: 10px;
                min-height: 38px;
                gap: 4px;
            }

            .progress-info {
                font-size: 8px;
                margin-top: 10px;
            }
        }

        .theme-option {
            position: relative;
            cursor: pointer;
        }

        .theme-option input {
            display: none;
        }

        .theme-option input:checked + .theme-card {
            border-color: #667eea;
            background: linear-gradient(135deg, #f0f4ff 0%, #ede9ff 100%);
            transform: scale(1.05);
        }

        .frosted-glass-preview {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(255, 255, 255, 0.3));
            backdrop-filter: blur(8px);
        }

        .dark-preview {
            background: linear-gradient(135deg, #1f2937, #0f172a);
        }

        .light-preview {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-color: #cbd5e1 !important;
        }

        .option-item {
            position: relative;
            cursor: pointer;
        }

        .option-item input {
            display: none;
        }

        .option-item input:checked + .option-card {
            border-color: #667eea;
            background: linear-gradient(135deg, #f0f4ff 0%, #ede9ff 100%);
            transform: scale(1.05);
        }
    </style>
</head>
<body class="setup-page">
<script>
    // Apply initial theme class for background gradient
    const initialTheme = '<?= $userTheme ?>';
    const themeClass = initialTheme === 'frosted-glass' ? 'theme-frosted' : 'theme-' + initialTheme;
    document.body.classList.add(themeClass);
</script>
<div class="setup-container">
    <div class="setup-card">
        <?php if (!empty($error)): ?>
            <div style="background: #fee2e2; border: 2px solid #fecaca; border-radius: 8px; padding: 16px; margin-bottom: 24px; color: #991b1b;">
                <strong>Error:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <!-- Step Indicators -->
        <div class="setup-steps">
            <div class="step-indicator <?= $step >= 1 ? 'active' : '' ?>">
                <div class="step-number">1</div>
                <div class="step-label">System<br>Updates</div>
            </div>
            <div class="step-indicator <?= $step >= 2 ? 'active' : '' ?>">
                <div class="step-number">2</div>
                <div class="step-label">Appearance<br>Settings</div>
            </div>
            <div class="step-indicator <?= $step >= 3 ? 'active' : '' ?>">
                <div class="step-number">3</div>
                <div class="step-label">Security<br>Setup</div>
            </div>
            <div class="step-indicator <?= $step >= 4 ? 'active' : '' ?>">
                <div class="step-number">4</div>
                <div class="step-label">Accept<br>Agreement</div>
            </div>
            <div class="step-indicator <?= $step > 4 ? 'active' : '' ?>">
                <div class="step-number">✓</div>
                <div class="step-label">Complete</div>
            </div>
        </div>

        <div class="setup-header">
            <h1 class="setup-title"><?= $step === 1 ? 'What\'s New' : ($step === 2 ? 'Choose Your Style' : ($step === 3 ? 'Secure Your Account' : 'Accept Agreement')) ?></h1>
            <p class="setup-subtitle">
                <?= $step === 1 
                    ? 'Review the latest system updates' 
                    : ($step === 2 ? 'Customize your interface appearance' : ($step === 3 ? 'Enable two-factor authentication for better security' : 'Review and accept the confidentiality and privacy agreement'))
                ?>
            </p>
            <?php if ($step === 2): ?>
                <span class="theme-label" id="theme-indicator">Frosted Glass Theme</span>
            <?php endif; ?>
        </div>

        <!-- Step 1: System Updates -->
        <form method="POST" style="width: 100%; <?= $step === 1 ? '' : 'display: none;' ?>">
            <input type="hidden" name="csrf_token" value="<?= clean($csrf) ?>">
            <input type="hidden" name="next_step" value="<?= $step + 1 ?>">
            
            <div class="setup-content <?= $step === 1 ? 'active' : '' ?>">
                <div class="update-item">
                    <div class="update-title">
                        <i class="fas fa-rocket"></i>
                        System Version 1.9.1 Released
                    </div>
                    <p class="update-desc">Latest performance improvements and bug fixes applied to your instance.</p>
                </div>
                <div class="update-item">
                    <div class="update-title">
                        <i class="fas fa-bolt"></i>
                        Enhanced Performance
                    </div>
                    <p class="update-desc">Optimized database queries and faster data loading across all modules.</p>
                </div>
                <div class="update-item">
                    <div class="update-title">
                        <i class="fas fa-shield-alt"></i>
                        Security Improvements
                    </div>
                    <p class="update-desc">New authentication protocols and enhanced data protection mechanisms.</p>
                </div>
                <div class="update-item">
                    <div class="update-title">
                        <i class="fas fa-paint-brush"></i>
                        UI/UX Refinements
                    </div>
                    <p class="update-desc">Improved interface design and user experience across all pages.</p>
                </div>
            </div>

            <!-- Actions for Step 1 -->
            <div class="setup-actions" style="<?= $step === 1 ? '' : 'display: none;' ?>">
                <button type="submit" class="setup-btn btn-next">
                    Next
                    <i class="fas fa-arrow-right"></i>
                </button>
            </div>

            <div class="progress-info" style="<?= $step === 1 ? '' : 'display: none;' ?>">
                Step 1 of 3 — Welcome to TPMS Setup
            </div>
        </form>

        <!-- Step 2: Appearance Settings -->
        <form method="POST" style="width: 100%; <?= $step === 2 ? '' : 'display: none;' ?>">
            <input type="hidden" name="csrf_token" value="<?= clean($csrf) ?>">
            <input type="hidden" name="next_step" value="3">

            <div class="setup-content <?= $step === 2 ? 'active' : '' ?>">
                <!-- Theme Selection -->
                <div style="margin-bottom: 40px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 20px;">
                        <i class="fas fa-palette" style="font-size: 18px; color: #6366f1;"></i>
                        <label style="font-size: 16px; font-weight: 700; color: #0f172a; margin: 0;">Choose Theme</label>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 16px;">
                        <!-- Frosted Glass Theme -->
                        <div style="position: relative;">
                            <input type="radio" id="frosted" name="theme" value="frosted-glass" <?= $userTheme === 'frosted-glass' ? 'checked' : '' ?> style="position: absolute; opacity: 0; cursor: pointer;">
                            <label for="frosted">
                                <div style="width: 100%; height: 64px; background: linear-gradient(135deg, #0b1226, #1f1a45); border-radius: 8px; margin-bottom: 10px; box-shadow: inset 0 1px 2px rgba(255,255,255,0.1);"></div>
                                <p style="font-size: 13px; font-weight: 600; color: #0f172a; margin: 0;">Frosted Glass</p>
                            </label>
                        </div>

                        <!-- Dark Theme -->
                        <div style="position: relative;">
                            <input type="radio" id="dark" name="theme" value="dark" <?= $userTheme === 'dark' ? 'checked' : '' ?> style="position: absolute; opacity: 0; cursor: pointer;">
                            <label for="dark">
                                <div style="width: 100%; height: 64px; background: linear-gradient(135deg, #1e293b, #0f172a); border-radius: 8px; margin-bottom: 10px;"></div>
                                <p style="font-size: 13px; font-weight: 600; color: #0f172a; margin: 0;">Dark</p>
                            </label>
                        </div>

                        <!-- Light Theme -->
                        <div style="position: relative;">
                            <input type="radio" id="light" name="theme" value="light" <?= $userTheme === 'light' ? 'checked' : '' ?> style="position: absolute; opacity: 0; cursor: pointer;">
                            <label for="light">
                                <div style="width: 100%; height: 64px; background: linear-gradient(135deg, #f8fafc, #e2e8f0); border-radius: 8px; margin-bottom: 10px; border: 1px solid #cbd5e1;"></div>
                                <p style="font-size: 13px; font-weight: 600; color: #0f172a; margin: 0;">Light</p>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Layout Selection -->
                <div style="margin-bottom: 40px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 20px;">
                        <i class="fas fa-columns" style="font-size: 18px; color: #0ea5e9;"></i>
                        <label style="font-size: 16px; font-weight: 700; color: #0f172a; margin: 0;">Layout Style</label>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 16px;">
                        <!-- Default Layout -->
                        <div style="position: relative;">
                            <input type="radio" id="layout-default" name="layout" value="default" <?= $userLayout === 'default' ? 'checked' : '' ?> style="position: absolute; opacity: 0; cursor: pointer;">
                            <label for="layout-default">
                                <div style="width: 100%; height: 64px; background: #f1f5f9; border-radius: 8px; margin-bottom: 10px; position: relative;">
                                    <div style="position: absolute; left: 4px; top: 4px; width: 12px; height: 12px; background: #6366f1; border-radius: 2px;"></div>
                                    <div style="position: absolute; left: 20px; top: 6px; width: 28px; height: 8px; background: #cbd5e1; border-radius: 2px;"></div>
                                    <div style="position: absolute; left: 4px; bottom: 4px; width: 48px; height: 12px; background: #e2e8f0; border-radius: 2px;"></div>
                                </div>
                                <p style="font-size: 13px; font-weight: 600; color: #0f172a; margin: 0;">Default</p>
                            </label>
                        </div>

                        <!-- Compact Layout -->
                        <div style="position: relative;">
                            <input type="radio" id="layout-compact" name="layout" value="compact" <?= $userLayout === 'compact' ? 'checked' : '' ?> style="position: absolute; opacity: 0; cursor: pointer;">
                            <label for="layout-compact">
                                <div style="width: 100%; height: 64px; background: #f1f5f9; border-radius: 8px; margin-bottom: 10px; position: relative;">
                                    <div style="position: absolute; left: 4px; top: 4px; width: 10px; height: 10px; background: #6366f1; border-radius: 2px;"></div>
                                    <div style="position: absolute; left: 18px; top: 5px; width: 24px; height: 6px; background: #cbd5e1; border-radius: 2px;"></div>
                                    <div style="position: absolute; left: 4px; bottom: 4px; width: 42px; height: 10px; background: #e2e8f0; border-radius: 2px;"></div>
                                </div>
                                <p style="font-size: 13px; font-weight: 600; color: #0f172a; margin: 0;">Compact</p>
                            </label>
                        </div>

                        <!-- Wide Layout -->
                        <div style="position: relative;">
                            <input type="radio" id="layout-wide" name="layout" value="wide" <?= $userLayout === 'wide' ? 'checked' : '' ?> style="position: absolute; opacity: 0; cursor: pointer;">
                            <label for="layout-wide">
                                <div style="width: 100%; height: 64px; background: #f1f5f9; border-radius: 8px; margin-bottom: 10px; position: relative;">
                                    <div style="position: absolute; left: 4px; top: 4px; width: 14px; height: 14px; background: #6366f1; border-radius: 2px;"></div>
                                    <div style="position: absolute; left: 22px; top: 7px; width: 32px; height: 10px; background: #cbd5e1; border-radius: 2px;"></div>
                                    <div style="position: absolute; left: 4px; bottom: 4px; width: 54px; height: 14px; background: #e2e8f0; border-radius: 2px;"></div>
                                </div>
                                <p style="font-size: 13px; font-weight: 600; color: #0f172a; margin: 0;">Wide</p>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Density Selection -->
                <div>
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 20px;">
                        <i class="fas fa-compress" style="font-size: 18px; color: #10b981;"></i>
                        <label style="font-size: 16px; font-weight: 700; color: #0f172a; margin: 0;">Spacing Density</label>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 16px;">
                        <!-- Comfortable Density -->
                        <div style="position: relative;">
                            <input type="radio" id="density-comfortable" name="density" value="comfortable" <?= $userDensity === 'comfortable' ? 'checked' : '' ?> style="position: absolute; opacity: 0; cursor: pointer;">
                            <label for="density-comfortable">
                                <div style="width: 100%; height: 64px; background: #f1f5f9; border-radius: 8px; margin-bottom: 10px; position: relative;">
                                    <div style="position: absolute; left: 8px; top: 8px; width: 40px; height: 8px; background: #6366f1; border-radius: 2px;"></div>
                                    <div style="position: absolute; left: 8px; top: 22px; width: 40px; height: 8px; background: #e2e8f0; border-radius: 2px;"></div>
                                    <div style="position: absolute; left: 8px; bottom: 8px; width: 40px; height: 8px; background: #cbd5e1; border-radius: 2px;"></div>
                                </div>
                                <p style="font-size: 13px; font-weight: 600; color: #0f172a; margin: 0;">Comfortable</p>
                            </label>
                        </div>

                        <!-- Compact Density -->
                        <div style="position: relative;">
                            <input type="radio" id="density-compact" name="density" value="compact" <?= $userDensity === 'compact' ? 'checked' : '' ?> style="position: absolute; opacity: 0; cursor: pointer;">
                            <label for="density-compact">
                                <div style="width: 100%; height: 64px; background: #f1f5f9; border-radius: 8px; margin-bottom: 10px; position: relative;">
                                    <div style="position: absolute; left: 8px; top: 6px; width: 40px; height: 6px; background: #6366f1; border-radius: 2px;"></div>
                                    <div style="position: absolute; left: 8px; top: 16px; width: 40px; height: 6px; background: #e2e8f0; border-radius: 2px;"></div>
                                    <div style="position: absolute; left: 8px; bottom: 6px; width: 40px; height: 6px; background: #cbd5e1; border-radius: 2px;"></div>
                                </div>
                                <p style="font-size: 13px; font-weight: 600; color: #0f172a; margin: 0;">Compact</p>
                            </label>
                        </div>

                        <!-- Spacious Density -->
                        <div style="position: relative;">
                            <input type="radio" id="density-spacious" name="density" value="spacious" <?= $userDensity === 'spacious' ? 'checked' : '' ?> style="position: absolute; opacity: 0; cursor: pointer;">
                            <label for="density-spacious">
                                <div style="width: 100%; height: 64px; background: #f1f5f9; border-radius: 8px; margin-bottom: 10px; position: relative;">
                                    <div style="position: absolute; left: 8px; top: 4px; width: 40px; height: 9px; background: #6366f1; border-radius: 2px;"></div>
                                    <div style="position: absolute; left: 8px; top: 24px; width: 40px; height: 9px; background: #e2e8f0; border-radius: 2px;"></div>
                                    <div style="position: absolute; left: 8px; bottom: 4px; width: 40px; height: 9px; background: #cbd5e1; border-radius: 2px;"></div>
                                </div>
                                <p style="font-size: 13px; font-weight: 600; color: #0f172a; margin: 0;">Spacious</p>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions for Step 2 -->
            <div class="setup-actions" style="<?= $step === 2 ? '' : 'display: none;' ?>">
                <?php if ($step > 1): ?>
                    <a href="<?= APP_URL ?>/first-login-setup?step=<?= $step - 1 ?>" class="setup-btn btn-back">
                        <i class="fas fa-arrow-left"></i>
                        Back
                    </a>
                <?php endif; ?>

                <button type="submit" class="setup-btn btn-next">
                    Next
                    <i class="fas fa-arrow-right"></i>
                </button>
            </div>

            <div class="progress-info" style="<?= $step === 2 ? '' : 'display: none;' ?>">
                Step 2 of 3 — Choose your preferences
            </div>
        </form>

        <!-- Step 3: Two-Factor Authentication -->
        <form method="POST" style="width: 100%; <?= $step === 3 ? '' : 'display: none;' ?>" id="step3-form">
            <input type="hidden" name="csrf_token" value="<?= clean($csrf) ?>">
            <input type="hidden" name="next_step" value="4">
            
            <div class="setup-content <?= $step === 3 ? 'active' : '' ?>">
                <div style="text-align: center; margin-bottom: 32px;">
                    <div style="background: #f0f4ff; border-radius: 12px; padding: 24px; margin-bottom: 24px; border: 2px solid #e0e7ff;">
                        <p style="font-size: 14px; color: #475569; margin-bottom: 8px;"><strong>Step 1: Download an Authenticator App</strong></p>
                        <p style="font-size: 13px; color: #64748b; line-height: 1.6;">
                            Download one of these free authenticator apps on your smartphone:
                        </p>
                        <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 12px;">
                            <span style="background: white; padding: 8px 12px; border-radius: 8px; font-size: 12px; color: #0f172a;"><i class="fas fa-mobile"></i> Google Authenticator</span>
                            <span style="background: white; padding: 8px 12px; border-radius: 8px; font-size: 12px; color: #0f172a;"><i class="fas fa-mobile"></i> Microsoft Authenticator</span>
                            <span style="background: white; padding: 8px 12px; border-radius: 8px; font-size: 12px; color: #0f172a;"><i class="fas fa-mobile"></i> Authy</span>
                        </div>
                    </div>

                    <div style="background: #f0f4ff; border-radius: 12px; padding: 24px; margin-bottom: 24px; border: 2px solid #e0e7ff;">
                        <p style="font-size: 14px; color: #475569; margin-bottom: 16px;"><strong>Step 2: Scan the QR Code</strong></p>
                        <p style="font-size: 13px; color: #64748b; margin-bottom: 16px;">Open your authenticator app and scan this QR code:</p>
                        
                        <?php if ($twoFaQrUri): ?>
                            <div style="display: flex; justify-content: center; margin-bottom: 16px;">
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($twoFaQrUri) ?>" alt="2FA QR Code" style="border: 2px solid #667eea; border-radius: 8px; padding: 8px; background: white;">
                            </div>
                        <?php endif; ?>

                        <p style="font-size: 13px; color: #64748b; margin-bottom: 16px;">Or enter this code manually if QR scanning doesn't work:</p>
                        <div style="background: white; border: 2px dashed #667eea; border-radius: 8px; padding: 12px; font-family: monospace; font-size: 16px; color: #0f172a; word-break: break-all; letter-spacing: 2px;">
                            <?= $twoFaSecret ? chunk_split($twoFaSecret, 4, ' ') : 'N/A' ?>
                        </div>
                    </div>

                    <div style="background: #f0f4ff; border-radius: 12px; padding: 24px; border: 2px solid #e0e7ff;">
                        <p style="font-size: 14px; color: #475569; margin-bottom: 16px;"><strong>Step 3: Verify Your Code</strong></p>
                        <p style="font-size: 13px; color: #64748b; margin-bottom: 16px;">Enter the 6-digit code from your authenticator app:</p>
                        
                        <input type="text" name="totp_code" placeholder="000000" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" 
                               style="width: 100%; padding: 12px; font-size: 18px; text-align: center; letter-spacing: 4px; border: 2px solid #e0e7ff; border-radius: 8px; font-family: monospace;"
                               required>
                    </div>
                </div>
            </div>

            <!-- Actions for Step 3 -->
            <div class="setup-actions" style="<?= $step === 3 ? '' : 'display: none;' ?>">
                <?php if ($step > 1): ?>
                    <a href="<?= APP_URL ?>/first-login-setup?step=<?= $step - 1 ?>" class="setup-btn btn-back">
                        <i class="fas fa-arrow-left"></i>
                        Back
                    </a>
                <?php endif; ?>

                <button type="submit" name="action" value="activate_2fa" class="setup-btn btn-next" style="flex: 1; margin-left: auto;">
                    <i class="fas fa-check"></i>
                    Verify & Activate
                </button>
            </div>

            <div class="progress-info" style="<?= $step === 3 ? '' : 'display: none;' ?>">
                Step 3 of 4 — You can enable 2FA later in Settings if you skip now.
            </div>
        </form>

        <!-- Step 4: Privacy Agreement -->
        <form method="POST" style="width: 100%; <?= $step === 4 ? '' : 'display: none;' ?>" id="step4-form">
            <input type="hidden" name="csrf_token" value="<?= clean($csrf) ?>">
            <input type="hidden" name="next_step" value="5">
            
            <div class="setup-content <?= $step === 4 ? 'active' : '' ?>">
                <div style="max-height: 400px; overflow-y: auto; background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 12px; padding: 24px; margin-bottom: 24px; font-size: 13px; line-height: 1.6; color: #334155;">
                    <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin-top: 0; margin-bottom: 16px;">TalaGuro System User Confidentiality, Data Privacy, and Acceptable Use Agreement</h3>
                    
                    <h4 style="font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 12px;">Confidentiality and Data Privacy Agreement</h4>
                    <p>By selecting <strong>"I Agree"</strong> and accessing the TalaGuro System, you acknowledge that you have carefully read, understood, and voluntarily agree to comply with the following terms and conditions governing the access, use, processing, storage, and protection of information contained within the TalaGuro System.</p>
                    
                    <h4 style="font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 12px;">1. Confidentiality</h4>
                    <p>You acknowledge that all information, records, documents, reports, communications, digital files, images, statistics, and other data stored, processed, transmitted, or displayed within the TalaGuro System are confidential and are intended solely for authorized official use.</p>
                    
                    <h4 style="font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 12px;">2. Data Privacy Compliance</h4>
                    <p>You understand that the TalaGuro System processes personal information protected under the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>. You agree to process personal information only for legitimate, specific, and lawful purposes consistent with your official duties.</p>
                    
                    <h4 style="font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 12px;">3. Authorized Use</h4>
                    <p>Access to the TalaGuro System is granted solely for official government and educational purposes. You agree to:</p>
                    <ul style="margin: 8px 0; padding-left: 20px;">
                        <li>Use the system only within the scope of your assigned duties</li>
                        <li>Access only information necessary to perform your official functions</li>
                        <li>Follow all applicable DepEd policies and system procedures</li>
                    </ul>
                    
                    <h4 style="font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 12px;">4. Protection of Login Credentials</h4>
                    <p>Your user account is personal and shall not be shared. You agree to keep your username, password, and authentication codes confidential and to immediately report any unauthorized access.</p>
                    
                    <h4 style="font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 12px;">5. Proper Handling of Information</h4>
                    <p>You agree that you shall not copy, download, share, print, photograph, or store confidential information on unauthorized devices or applications.</p>
                    
                    <h4 style="font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 12px;">6. Cybersecurity Responsibilities</h4>
                    <p>To help maintain system security, you agree that you shall not attempt to bypass security controls, access restricted modules without authorization, introduce malware, or interfere with system operations.</p>
                    
                    <h4 style="font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 12px;">7. Audit Logs and Monitoring</h4>
                    <p>You understand that all activities within the TalaGuro System may be recorded, monitored, and audited for security, compliance, and legal purposes. These records may be used as official evidence during investigations.</p>
                    
                    <h4 style="font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 12px;">8. User Accountability</h4>
                    <p>You are personally accountable for every action performed using your account. Unauthorized access, disclosure, or misuse of confidential information may result in administrative sanctions, suspension of access, or criminal prosecution.</p>
                    
                    <h4 style="font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 12px;">9. Consent</h4>
                    <p>By selecting <strong>"I Agree"</strong>, you certify that you have read and understood this agreement, voluntarily consent to lawful processing of your personal information, and agree to comply with all applicable policies and requirements.</p>
                </div>
                
                <!-- Acceptance Checkbox -->
                <div style="background: #f0f4ff; border: 2px solid #e0e7ff; border-radius: 12px; padding: 20px; margin-bottom: 24px;">
                    <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; margin: 0;">
                        <input type="checkbox" name="accept_agreement" value="1" required 
                               id="acceptAgreement"
                               style="width: 20px; height: 20px; margin-top: 2px; cursor: pointer; accent-color: #6366f1;">
                        <span style="font-size: 14px; color: #0f172a; line-height: 1.5;">
                            I have read and understood the TalaGuro System User Confidentiality, Data Privacy, and Acceptable Use Agreement, and I agree to comply with all terms and conditions.
                        </span>
                    </label>
                </div>
            </div>

            <!-- Actions for Step 4 -->
            <div class="setup-actions" style="<?= $step === 4 ? '' : 'display: none;' ?>">
                <a href="<?= APP_URL ?>/first-login-setup?step=3" class="setup-btn btn-back">
                    <i class="fas fa-arrow-left"></i>
                    Back
                </a>

                <button type="submit" class="setup-btn btn-next" id="submitBtn" disabled style="opacity: 0.5; cursor: not-allowed;">
                    <i class="fas fa-check"></i>
                    I Agree & Complete Setup
                </button>
            </div>

            <div class="progress-info" style="<?= $step === 4 ? '' : 'display: none;' ?>">
                Step 4 of 4 — Accept the agreement to complete your setup
            </div>
        </form>
    </div>
</div>

<script>
// Enable/disable submit button based on checkbox
document.addEventListener('DOMContentLoaded', function() {
    const acceptCheckbox = document.getElementById('acceptAgreement');
    const submitBtn = document.getElementById('submitBtn');
    
    if (acceptCheckbox && submitBtn) {
        // Initial state
        submitBtn.disabled = !acceptCheckbox.checked;
        submitBtn.style.opacity = acceptCheckbox.checked ? '1' : '0.5';
        submitBtn.style.cursor = acceptCheckbox.checked ? 'pointer' : 'not-allowed';
        
        // Handle checkbox change
        acceptCheckbox.addEventListener('change', function() {
            submitBtn.disabled = !this.checked;
            submitBtn.style.opacity = this.checked ? '1' : '0.5';
            submitBtn.style.cursor = this.checked ? 'pointer' : 'not-allowed';
        });
    }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const html = document.documentElement;
    const body = document.body;

    // Get all forms and find Step 2 form by checking for theme radio
    const allForms = document.querySelectorAll('form');
    let step2Form = null;
    allForms.forEach(form => {
        if (form.querySelector('input[name="theme"]')) {
            step2Form = form;
        }
    });
    
    // Add form submit handler for debugging
    if (step2Form) {
        step2Form.addEventListener('submit', function(e) {
            console.log('Step 2 form submitting...');
            const formData = new FormData(this);
            console.log('Form data:', {
                csrf_token: formData.get('csrf_token'),
                next_step: formData.get('next_step'),
                theme: formData.get('theme'),
                layout: formData.get('layout'),
                density: formData.get('density')
            });
        });
    }

    // Attach change event to all radio buttons for theme/layout/density changes
    const themeRadios = document.querySelectorAll('input[type="radio"][name="theme"]');
    const layoutRadios = document.querySelectorAll('input[type="radio"][name="layout"]');
    const densityRadios = document.querySelectorAll('input[type="radio"][name="density"]');
    
    // Handle theme changes - apply background gradient dynamically
    themeRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.checked) {
                // Remove previous theme classes
                body.classList.remove('theme-frosted', 'theme-dark', 'theme-light');
                
                // Apply new theme class and attribute
                const themeClass = 'theme-' + this.value.replace('-glass', '');
                body.classList.add(themeClass);
                html.setAttribute('data-theme', this.value);
                body.setAttribute('data-theme', this.value);
                console.log('Theme changed to:', this.value);
            }
        });
    });
    
    // Handle layout changes
    layoutRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.checked) {
                html.setAttribute('data-layout', this.value);
                body.setAttribute('data-layout', this.value);
                console.log('Layout changed to:', this.value);
            }
        });
    });
    
    // Handle density changes
    densityRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.checked) {
                html.setAttribute('data-density', this.value);
                body.setAttribute('data-density', this.value);
                console.log('Density changed to:', this.value);
            }
        });
    });
});
</script>
</body>
</html>