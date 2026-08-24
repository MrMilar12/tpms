<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
sendSecurityHeaders();

// Redirect if already logged in
if (isLoggedIn()) {
    if (needsOnboarding()) {
        redirect(APP_URL . '/first-login-setup');
    }
    redirect(APP_URL . '/dashboard');
}

$error = '';
$msg   = '';

if (isset($_GET['cancel_2fa']) && $_GET['cancel_2fa'] === '1') {
    unset($_SESSION['pending_2fa']);
}

if (isset($_GET['msg']) && $_GET['msg'] === 'timeout') {
    $msg = 'Your session has expired. Please log in again.';
}

$isTwoFactorStep = hasPendingTwoFactorChallenge();

/** Route every successfully authenticated account through the same role flow. */
function routeAuthenticatedUser(array $user): never {
    $userRole = strtolower((string)($user['role'] ?? $_SESSION['role'] ?? ''));
    $userId = (int)($user['id'] ?? $_SESSION['user_id'] ?? 0);

    if ($userRole === '' || $userRole === 'null' || $userRole === 'viewer') {
        $_SESSION['pending_role_selection'] = true;
        redirect(APP_URL . '/select-role');
    }

    if (in_array($userRole, ['psds', 'sdc'], true)) {
        $assignedDistricts = getUserDistricts(getDB(), $userId);
        if (!$assignedDistricts) {
            $_SESSION['available_districts_for_setup'] = true;
            redirect(APP_URL . '/setup-districts');
        }

        $_SESSION['available_districts'] = $assignedDistricts;
        if (count($assignedDistricts) > 1) {
            $_SESSION['need_district_selection'] = true;
            redirect(APP_URL . '/select-district');
        }

        setSessionDistrict((int)$assignedDistricts[0]);
        $_SESSION['need_district_selection'] = false;
    }

    if ($userRole === 'unit_head') {
        redirect(APP_URL . '/onboarding');
    }

    if (needsOnboarding()) {
        redirect(APP_URL . '/first-login-setup');
    }

    redirect(APP_URL . '/dashboard');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameForThrottle = trim((string)($_POST['username'] ?? ''));

    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $postedCsrf)) {
        $error = 'Session expired. Please try again.';
    }

    if ($error === '' && !canAttemptLogin($usernameForThrottle)) {
        $error = 'Too many login attempts. Please wait a few minutes before trying again.';
    }

    $mode = $_POST['mode'] ?? 'password';

    if ($error === '' && $mode === 'cancel_2fa') {
        unset($_SESSION['pending_2fa']);
        $isTwoFactorStep = false;
        $msg = 'Two-factor challenge canceled. Please log in again.';
    } elseif ($error === '' && ($mode === 'totp' || $isTwoFactorStep)) {
        $code = trim($_POST['totp_code'] ?? '');
        if (!preg_match('/^\d{6}$/', preg_replace('/\D+/', '', $code))) {
            $error = 'Enter the 6-digit authenticator code.';
        } elseif (!verifyTwoFactorLoginCode($code)) {
            recordFailedLoginAttempt($usernameForThrottle !== '' ? $usernameForThrottle : (string)($_SESSION['pending_2fa']['username'] ?? ''));
            $error = 'Invalid or expired authenticator code.';
        } else {
            clearFailedLoginAttempts((string)($_SESSION['pending_2fa']['username'] ?? $usernameForThrottle));
            
            routeAuthenticatedUser([]);
        }

        $isTwoFactorStep = hasPendingTwoFactorChallenge();
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (strlen($username) > 80 || strlen($password) > 255) {
            $error = 'Invalid credentials format.';
        }

        if ($error === '' && ($username === '' || $password === '')) {
            $error = 'Please enter your username and password.';
        }

        if ($error === '') {
            $user = authenticateCredentials($username, $password);
            if ($user === false) {
                recordFailedLoginAttempt($username);
                $error = 'Invalid username or password.';
                error_log('Failed login attempt for username: ' . $username . ' from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            } else {
                $is2faEnabled = (int)($user['twofa_enabled'] ?? 0) === 1;
                $secret = trim((string)($user['twofa_secret'] ?? ''));
                if ($is2faEnabled && $secret !== '') {
                    beginTwoFactorChallenge($user);
                    $isTwoFactorStep = true;
                    $msg = 'Enter the code from your authenticator app to continue.';
                } else {
                    clearFailedLoginAttempts($username);
                    finalizeLogin($user);
                    
                    routeAuthenticatedUser($user);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login – <?= APP_FULL_NAME ?></title>
<link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/images/logo.png">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/fonts/inter/inter.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<style>
/* Login page redesign v3 - bright, clean, premium */
body.login-v2 {
    min-height: 100vh;
    margin: 0;
    color: #11223a;
    background:
        radial-gradient(circle at 10% 10%, rgba(14, 165, 233, 0.18), transparent 42%),
        radial-gradient(circle at 88% 85%, rgba(20, 184, 166, 0.16), transparent 45%),
        linear-gradient(155deg, #f8fbff 0%, #eef6ff 52%, #eaf4f3 100%);
}

.login-scene {
    position: fixed;
    inset: 0;
    pointer-events: none;
    overflow: hidden;
}

.login-scene::before,
.login-scene::after {
    content: "";
    position: absolute;
    width: 460px;
    height: 460px;
    border-radius: 50%;
    filter: blur(52px);
    opacity: 0.24;
}

.login-scene::before {
    background: rgba(14, 165, 233, 0.40);
    top: -90px;
    left: -80px;
    animation: floatBlobA 15s ease-in-out infinite;
}

.login-scene::after {
    background: rgba(16, 185, 129, 0.30);
    right: -100px;
    bottom: -120px;
    animation: floatBlobB 16s ease-in-out infinite;
}

.login-wrapper {
    position: relative;
    z-index: 2;
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 30px;
}

.login-shell {
    position: relative;
    width: min(1120px, 100%);
    display: grid;
    grid-template-columns: 1.14fr 0.86fr;
    border-radius: 24px;
    overflow: hidden;
    border: 1px solid rgba(148, 163, 184, 0.28);
    box-shadow: 0 22px 52px rgba(15, 23, 42, 0.16), inset 0 1px 0 rgba(255,255,255,0.42);
    background:
        linear-gradient(155deg, rgba(255,255,255,0.62), rgba(255,255,255,0.38)),
        rgba(232, 244, 252, 0.34);
    backdrop-filter: blur(10px) saturate(112%);
    -webkit-backdrop-filter: blur(10px) saturate(112%);
    animation: shellReveal .7s cubic-bezier(.2, .75, .2, 1) both;
}

.login-shell .shell-right-glow {
    display: none;
}

.login-shell .shell-right-glow::before {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: linear-gradient(90deg, rgba(255,255,255,0.01), rgba(255,255,255,0.10) 70%, rgba(255,255,255,0.01));
    opacity: 0.36;
    animation: shellRightInnerPulse 6.4s ease-in-out infinite;
}

.login-shell .shell-right-glow::after {
    content: "";
    position: absolute;
    top: -40%;
    right: 14%;
    width: 42%;
    height: 180%;
    transform: rotate(12deg);
    background: linear-gradient(180deg, rgba(255,255,255,0), rgba(255,255,255,0.24), rgba(255,255,255,0));
    filter: blur(1px);
    opacity: 0.38;
    animation: shellRightSheen 6.6s ease-in-out infinite;
}

.login-shell::before {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        linear-gradient(136deg, rgba(255,255,255,0.52) 0%, rgba(255,255,255,0.22) 24%, rgba(255,255,255,0) 52%),
        radial-gradient(circle at 90% 8%, rgba(255,255,255,0.28), rgba(255,255,255,0) 34%);
    opacity: .55;
}

.login-shell::after {
    content: "";
    position: absolute;
    top: -78%;
    left: -24%;
    width: 34%;
    height: 255%;
    pointer-events: none;
    transform: rotate(18deg);
    background: linear-gradient(180deg, rgba(255,255,255,0) 14%, rgba(255,255,255,0.32) 45%, rgba(255,255,255,0) 76%);
    opacity: .42;
}

.login-hero-pane {
    position: relative;
    overflow: hidden;
    padding: 52px 48px;
    border-right: 1px solid rgba(148, 163, 184, 0.28);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 28px;
    background:
        radial-gradient(circle at 78% 20%, rgba(56, 189, 248, 0.18), rgba(56, 189, 248, 0) 40%),
        linear-gradient(165deg, rgba(207, 250, 254, 0.72), rgba(219, 234, 254, 0.48));
}

.login-hero-pane .hero-right-glow {
    position: absolute;
    top: 10%;
    right: -1px;
    width: 2px;
    height: 80%;
    border-radius: 999px;
    pointer-events: none;
    background: linear-gradient(180deg, rgba(56, 189, 248, 0), rgba(56, 189, 248, 0.95), rgba(16, 185, 129, 0.88), rgba(56, 189, 248, 0));
    box-shadow: 0 0 10px rgba(56, 189, 248, 0.55), 0 0 22px rgba(16, 185, 129, 0.35);
    opacity: 0.9;
    animation: heroRightGlowFlow 4.8s ease-in-out infinite;
}

.login-hero-pane::before,
.login-hero-pane::after {
    content: "";
    position: absolute;
    border-radius: 999px;
    pointer-events: none;
}

.login-hero-pane::before {
    width: 260px;
    height: 260px;
    right: -90px;
    top: -80px;
    background: radial-gradient(circle at center, rgba(14, 165, 233, 0.24), rgba(14, 165, 233, 0));
}

.login-hero-pane::after {
    width: 220px;
    height: 220px;
    left: -70px;
    bottom: -90px;
    background: radial-gradient(circle at center, rgba(16, 185, 129, 0.20), rgba(16, 185, 129, 0));
}

.hero-top .brand-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 13px;
    border-radius: 999px;
    background: rgba(14, 116, 144, 0.10);
    color: #0f766e;
    font-size: 12px;
    letter-spacing: 0.08em;
    border: 1px solid rgba(15, 118, 110, 0.24);
    font-weight: 600;
}

.hero-top {
    position: relative;
    z-index: 1;
    animation: fadeUp .65s ease both;
    animation-delay: .12s;
}

.hero-title {
    margin: 18px 0 10px;
    font-size: clamp(2rem, 3vw, 2.85rem);
    line-height: 1.08;
    font-weight: 800;
    color: #0f172a;
}

.hero-subtitle {
    margin: 0;
    color: #1e3a5f;
    font-size: 1rem;
    line-height: 1.68;
    max-width: 44ch;
}

.hero-accent-card {
    position: relative;
    z-index: 1;
    margin-top: 22px;
    padding: 16px 16px 14px;
    border-radius: 14px;
    border: 1px solid rgba(148, 163, 184, 0.28);
    background: rgba(255, 255, 255, 0.70);
    box-shadow: 0 14px 28px rgba(14, 24, 47, 0.09);
    backdrop-filter: blur(4px);
    animation: fadeUp .7s ease both;
    animation-delay: .24s;
}

.hero-accent-quote {
    margin: 0;
    color: #0f172a;
    font-size: 0.92rem;
    line-height: 1.55;
}

.hero-accent-source {
    margin: 8px 0 0;
    color: #334155;
    font-size: 0.78rem;
    letter-spacing: 0.02em;
}

.hero-mini-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.hero-mini-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.74rem;
    font-weight: 600;
    color: #0f766e;
    background: rgba(20, 184, 166, 0.11);
    border: 1px solid rgba(20, 184, 166, 0.24);
    padding: 5px 9px;
    border-radius: 999px;
    animation: tagIn .45s ease both;
}

.hero-mini-tag:nth-child(1) {
    animation-delay: .34s;
}

.hero-mini-tag:nth-child(2) {
    animation-delay: .42s;
}

.hero-mini-tag:nth-child(3) {
    animation-delay: .5s;
}

.hero-points {
    display: grid;
    gap: 13px;
    margin-top: 18px;
}

.hero-point {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    color: #1e3a5f;
    font-size: 0.95rem;
}

.hero-point i {
    color: #0ea5e9;
    margin-top: 2px;
}

.hero-footer {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}

.hero-stat {
    border: 1px solid rgba(148, 163, 184, 0.3);
    border-radius: 12px;
    padding: 12px 11px;
    background: linear-gradient(160deg, rgba(255,255,255,0.72), rgba(255,255,255,0.48));
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.54);
    backdrop-filter: blur(8px);
}

.hero-stat strong {
    display: block;
    color: #0f172a;
    font-size: 1.02rem;
}

.hero-stat span {
    color: #334155;
    font-size: 0.78rem;
}

.login-form-pane {
    position: relative;
    padding: 44px 38px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    background:
        linear-gradient(170deg, rgba(255, 255, 255, 0.92), rgba(241, 250, 255, 0.82));
    backdrop-filter: blur(7px) saturate(106%);
    -webkit-backdrop-filter: blur(7px) saturate(106%);
    border-left: 1px solid rgba(255,255,255,0.24);
    animation: fadeUp .65s ease both;
    animation-delay: .18s;
}

.login-form-pane::before {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: linear-gradient(180deg, rgba(255,255,255,0.20), rgba(255,255,255,0.04) 24%, rgba(255,255,255,0));
}

.login-form-pane::after {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        linear-gradient(120deg, rgba(255,255,255,0.14), rgba(255,255,255,0) 42%),
        radial-gradient(circle at 88% 14%, rgba(56, 189, 248, 0.14), rgba(56, 189, 248, 0) 45%);
    opacity: 0.14;
}

.login-form-pane > * {
    position: relative;
    z-index: 1;
}

.login-panel-head {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 14px;
}

.login-panel-head .login-logo {
    margin: 0;
    width: 64px;
    height: 64px;
    border-radius: 14px;
}

.login-intro-title {
    margin: 0;
    font-size: 1.32rem;
    line-height: 1.2;
    color: #0f172a;
    letter-spacing: 0.01em;
}

.login-intro-subtitle {
    margin: 6px 0 0;
    color: #334155;
    font-size: 0.88rem;
    line-height: 1.45;
}

.login-form-pane > .alert {
    margin-bottom: 14px;
}

.login-form-pane .login-form {
    padding: 16px;
    border-radius: 16px;
    border: 1px solid rgba(148, 163, 184, 0.3);
    background: rgba(255,255,255,0.82);
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.06), inset 0 1px 0 rgba(255,255,255,0.82);
}

.login-form-pane .form-label {
    font-size: 0.75rem;
    letter-spacing: 0.05em;
}

.login-form-pane .login-field {
    border-color: rgba(148, 163, 184, 0.45);
    background: rgba(255,255,255,0.96);
}

.login-form-pane .login-field:hover {
    background: rgba(255,255,255,0.98);
}

.login-form-pane .login-field-input {
    font-size: 0.93rem;
    min-height: 47px;
}

.login-form-pane .login-field-input::placeholder {
    color: #6b7280;
}

.login-form-pane #loginBtn,
.login-form-pane #verifyBtn {
    margin-top: 4px;
    min-height: 48px;
    border-radius: 13px;
}

.login-secondary-form {
    margin-top: 10px;
}

.login-secondary-btn {
    border-radius: 12px;
    border: 1px solid rgba(148, 163, 184, 0.36);
    background: rgba(255,255,255,0.62);
}

.login-brand {
    margin-bottom: 22px;
    text-align: left;
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 13px 15px;
    border-radius: 18px;
    border: 1px solid rgba(148, 163, 184, 0.3);
    background:
        linear-gradient(160deg, rgba(255,255,255,0.78), rgba(240,248,255,0.62));
    box-shadow: 0 10px 20px rgba(15, 23, 42, 0.07), inset 0 1px 0 rgba(255,255,255,0.5);
    backdrop-filter: blur(12px);
    animation: fadeUp .55s ease both;
    animation-delay: .28s;
}

.login-brand-copy {
    min-width: 0;
}

.login-logo {
    width: 72px;
    height: 72px;
    border-radius: 16px;
    padding: 11px;
    display: grid;
    place-items: center;
    background:
        radial-gradient(circle at 30% 18%, rgba(255,255,255,0.98), rgba(220, 246, 255, 0.84));
    border: 1px solid rgba(14, 165, 233, 0.32);
    box-shadow: 0 12px 24px rgba(3, 105, 161, 0.16), inset 0 1px 0 rgba(255,255,255,0.78);
    animation: logoFloat 6s ease-in-out infinite;
}

.login-title {
    margin: 0 0 4px;
    color: #0f172a;
    font-size: 1.95rem;
    letter-spacing: 0.012em;
    line-height: 1.04;
    background: linear-gradient(110deg, #0f172a 0%, #0b5d8f 60%, #0f766e 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
}

.login-subtitle {
    margin: 0;
    color: #2563eb;
    font-size: 0.93rem;
    font-weight: 500;
}

.login-form {
    display: grid;
    gap: 15px;
}

.login-form .form-group {
    opacity: 0;
    animation: fadeUp .5s ease both;
}

.login-form .form-group:nth-child(2) {
    animation-delay: .32s;
}

.login-form .form-group:nth-child(3) {
    animation-delay: .4s;
}

.form-label {
    color: #1e3a5f;
    font-size: 0.84rem;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    margin-bottom: 7px;
    display: block;
    font-weight: 700;
}

.login-field {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid rgba(148, 163, 184, 0.4);
    border-radius: 14px;
    background: linear-gradient(160deg, rgba(255,255,255,0.78), rgba(248,252,255,0.64));
    padding: 0 13px;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.54), 0 6px 14px rgba(148, 163, 184, 0.08);
    backdrop-filter: blur(4px);
    transition: border-color .22s ease, box-shadow .22s ease, background-color .22s ease, transform .22s ease;
}

.login-field::after {
    content: "";
    position: absolute;
    left: 12px;
    right: 12px;
    bottom: 0;
    height: 2px;
    border-radius: 999px;
    background: linear-gradient(90deg, #0284c7, #0d9488);
    opacity: 0;
    transform: scaleX(.35);
    transform-origin: center;
    transition: opacity .2s ease, transform .2s ease;
}

.login-field:hover {
    border-color: rgba(56, 189, 248, 0.42);
    box-shadow: 0 8px 20px rgba(14, 165, 233, 0.08);
    background: rgba(255,255,255,0.88);
    transform: translateY(-0.5px);
}

.login-field:focus-within {
    border-color: rgba(2, 132, 199, 0.72);
    box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.13), 0 12px 26px rgba(14, 165, 233, 0.12);
    background: rgba(255,255,255,0.94);
}

.login-field:hover::after,
.login-field:focus-within::after {
    opacity: .95;
    transform: scaleX(1);
}

.login-field-icon {
    color: #0284c7;
    font-size: 0.88rem;
    transition: color .2s ease, transform .2s ease;
}

.login-field:hover .login-field-icon,
.login-field:focus-within .login-field-icon {
    color: #0369a1;
    transform: translateY(-1px) scale(1.04);
}

.login-field-input {
    flex: 1;
    min-height: 48px;
    border: 0;
    background: transparent;
    color: #0f172a;
    padding: 11px 0;
    font-size: 0.95rem;
}

.login-field-input::placeholder {
    color: #64748b;
}

.toggle-password {
    border: 0;
    background: transparent;
    color: #3b82f6;
    cursor: pointer;
    transition: color .2s ease, transform .2s ease;
}

.toggle-password:hover {
    color: #0b5d8f;
    transform: scale(1.06);
}

#loginBtn {
    position: relative;
    overflow: hidden;
    margin-top: 6px;
    min-height: 50px;
    border-radius: 14px;
    border: 1px solid rgba(14, 165, 233, 0.5);
    background:
        linear-gradient(160deg, rgba(255,255,255,0.22) 0%, rgba(255,255,255,0.04) 30%, rgba(255,255,255,0) 56%),
        linear-gradient(132deg, #0284c7, #0891b2 48%, #0d9488 100%);
    color: #eaffff;
    font-weight: 700;
    font-size: 0.95rem;
    letter-spacing: 0.03em;
    box-shadow: 0 14px 30px rgba(2, 132, 199, 0.24), 0 8px 18px rgba(13, 148, 136, 0.22), inset 0 1px 0 rgba(255,255,255,0.42);
    animation: buttonBreathe 3s ease-in-out infinite;
    animation-delay: .45s;
}

#loginBtn::after {
    content: "";
    position: absolute;
    top: 0;
    left: -120%;
    width: 65%;
    height: 100%;
    background: linear-gradient(110deg, transparent 0%, rgba(255,255,255,0.34) 50%, transparent 100%);
    transition: left .55s ease;
}

#loginBtn:hover {
    filter: brightness(1.06);
    transform: translateY(-1.5px);
}

#loginBtn:hover::after {
    left: 145%;
}

/* Smooth boot/logo motion tuning for login page */
.boot-logo-core {
    animation: tgBootCoreFloat 2.8s ease-in-out infinite;
    will-change: transform;
    transform: translateZ(0);
}

.boot-logo-image {
    animation: tgBootLogoPulse 1.45s ease-in-out infinite alternate;
    transform-origin: center;
    will-change: transform, filter;
    filter: drop-shadow(0 8px 16px rgba(14, 165, 233, 0.24));
}

.boot-logo-ring {
    animation: tgBootRingSpin 2.7s linear infinite;
    will-change: transform;
}

.boot-logo-glow {
    animation: tgBootGlowPulse 1.9s ease-in-out infinite;
}

.boot-logo-title {
    animation: tgBootTitleRise 0.75s ease-out both, tgBootTitleGlow 2.4s ease-in-out infinite;
}

@keyframes tgBootCoreFloat {
    0%, 100% { transform: translate3d(0, 0, 0); }
    50% { transform: translate3d(0, -4px, 0); }
}

@keyframes tgBootLogoPulse {
    from { transform: scale(0.96); filter: drop-shadow(0 6px 12px rgba(14, 165, 233, 0.18)); }
    to { transform: scale(1.04); filter: drop-shadow(0 10px 20px rgba(14, 165, 233, 0.3)); }
}

@keyframes tgBootRingSpin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@keyframes tgBootGlowPulse {
    0%, 100% { opacity: 0.6; transform: scale(0.96); }
    50% { opacity: 0.95; transform: scale(1.08); }
}

@keyframes tgBootTitleRise {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes tgBootTitleGlow {
    0%, 100% { text-shadow: 0 0 0 rgba(255,255,255,0); }
    50% { text-shadow: 0 0 18px rgba(186, 230, 253, 0.45); }
}

.login-form-pane.is-submitting {
    filter: saturate(0.92);
}

.login-form-pane form.is-submitting {
    pointer-events: none;
}

.login-form-pane button[type="submit"].is-loading {
    position: relative;
    isolation: isolate;
    pointer-events: none;
    cursor: wait;
    opacity: 0.92;
}

.login-form-pane button[type="submit"].is-loading i {
    animation: spin 0.9s linear infinite;
}

.login-form-pane button[type="submit"].is-loading::before {
    content: "";
    position: absolute;
    inset: 0;
    z-index: 0;
    border-radius: inherit;
    background: linear-gradient(100deg, rgba(255,255,255,0.08), rgba(255,255,255,0.22), rgba(255,255,255,0.08));
    opacity: 0.85;
    animation: loginBtnLoad 1.1s ease-in-out infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@keyframes loginBtnLoad {
    0% { transform: translateX(-6%); opacity: 0.72; }
    50% { transform: translateX(6%); opacity: 0.95; }
    100% { transform: translateX(-6%); opacity: 0.72; }
}

.alert {
    border-radius: 12px;
    margin-bottom: 12px;
    border: 1px solid transparent;
}

.alert-warning {
    background: rgba(251, 191, 36, 0.14);
    border-color: rgba(245, 158, 11, 0.35);
    color: #92400e;
}

.alert-error {
    background: rgba(239, 68, 68, 0.09);
    border-color: rgba(252, 165, 165, 0.30);
    color: #991b1b;
}

.login-footer {
    margin-top: 16px;
    color: #334155;
    font-size: 0.82rem;
}

.login-form-pane > .login-logo {
    margin: 0 auto 8px;
}

.login-divider {
    height: 1px;
    margin-bottom: 12px;
    background: linear-gradient(90deg, transparent, rgba(148, 163, 184, 0.45), transparent);
}

@keyframes floatBlobA {
    0%, 100% { transform: translate(0, 0); }
    50% { transform: translate(20px, 24px); }
}

@keyframes floatBlobB {
    0%, 100% { transform: translate(0, 0); }
    50% { transform: translate(-18px, -20px); }
}

@keyframes shellReveal {
    from {
        opacity: 0;
        transform: translateY(16px) scale(.985);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@keyframes fadeUp {
    from {
        opacity: 0;
        transform: translateY(14px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes tagIn {
    from {
        opacity: 0;
        transform: translateY(8px) scale(.96);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@keyframes logoFloat {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-4px);
    }
}

@keyframes buttonBreathe {
    0%, 100% {
        box-shadow: 0 12px 24px rgba(13, 148, 136, 0.24);
    }
    50% {
        box-shadow: 0 16px 28px rgba(13, 148, 136, 0.32);
    }
}

@keyframes heroRightGlowFlow {
    0%, 100% {
        opacity: 0.72;
        transform: translateY(0) scaleY(0.96);
        box-shadow: 0 0 8px rgba(56, 189, 248, 0.45), 0 0 18px rgba(16, 185, 129, 0.28);
    }
    50% {
        opacity: 1;
        transform: translateY(-4px) scaleY(1.03);
        box-shadow: 0 0 12px rgba(56, 189, 248, 0.7), 0 0 28px rgba(16, 185, 129, 0.45);
    }
}

@keyframes shellRightGlowPulse {
    0%, 100% {
        opacity: 0.48;
        transform: translateX(0);
        filter: saturate(110%);
    }
    50% {
        opacity: 0.74;
        transform: translateX(-2px);
        filter: saturate(124%);
    }
}

@keyframes shellRightGradientDrift {
    0%, 100% {
        background-position: 100% 16%, 92% 84%, 0% 50%;
    }
    50% {
        background-position: 96% 24%, 88% 74%, 100% 50%;
    }
}

@keyframes shellRightInnerPulse {
    0%, 100% {
        opacity: 0.22;
    }
    50% {
        opacity: 0.48;
    }
}

@keyframes shellRightSheen {
    0%, 100% {
        opacity: 0.42;
        transform: rotate(12deg) translateY(0);
    }
    50% {
        opacity: 0.78;
        transform: rotate(12deg) translateY(-12px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .login-scene::before,
    .login-scene::after,
    .login-shell,
    .hero-top,
    .hero-accent-card,
    .hero-mini-tag,
    .login-brand,
    .login-logo,
    .login-form .form-group,
    .login-form-pane,
    .login-form-pane::after,
    .login-hero-pane .hero-right-glow,
    .login-shell .shell-right-glow,
    .login-shell .shell-right-glow::before,
    .login-shell .shell-right-glow::after {
        animation: none !important;
    }
    /* Animated form groups start transparent. When motion is disabled they
       must be made visible explicitly because the fadeUp animation never
       runs to apply its final frame. */
    .login-form .form-group {
        opacity: 1;
        transform: none;
    }
    .boot-logo-core,
    .boot-logo-image,
    .boot-logo-ring,
    .boot-logo-glow,
    .boot-logo-title {
        animation: none !important;
    }
    #loginBtn::after {
        transition: none !important;
    }
}

@media (max-width: 980px) {
    .login-shell .shell-right-glow {
        display: none;
    }
    .login-shell {
        grid-template-columns: 1fr;
        width: min(680px, 100%);
        border-radius: 20px;
    }
    .login-hero-pane {
        border-right: 0;
        border-bottom: 1px solid rgba(148, 163, 184, 0.20);
        padding: 28px 22px;
    }
    .hero-accent-card {
        margin-top: 14px;
    }
    .hero-footer {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .login-form-pane {
        padding: 22px 18px;
    }
    .login-brand {
        margin-bottom: 18px;
    }
}

@media (max-width: 640px) {
    body.login-v2 {
        background: transparent;
    }
    .login-shell {
        width: 100%;
        min-height: 100dvh;
        border-radius: 0;
        border-left: 0;
        border-right: 0;
        background: linear-gradient(180deg, rgba(255,255,255,0.3), rgba(255,255,255,0.12));
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        box-shadow: none;
    }
    .login-wrapper {
        padding: 0;
        min-height: 100dvh;
        align-items: stretch;
    }
    .login-hero-pane {
        display: none;
    }
    .login-brand,
    .login-form,
    .login-footer,
    .login-form-pane > .alert,
    .login-form-pane > .login-form,
    .login-form-pane > .login-footer,
    .login-form-pane > .login-panel-head {
        width: 100%;
        margin-left: 0;
        margin-right: 0;
    }
    .login-brand {
        background: transparent;
        border: 1px solid rgba(148, 163, 184, 0.34);
        box-shadow: none;
        backdrop-filter: none;
        -webkit-backdrop-filter: none;
    }
    .login-form {
        padding: 14px;
        border-radius: 16px;
        border: 1px solid rgba(148, 163, 184, 0.3);
        background: transparent;
        box-shadow: none;
        backdrop-filter: none;
        -webkit-backdrop-filter: none;
    }
    .login-form .login-field {
        background: transparent;
    }
    .login-form .login-field:focus-within {
        background: transparent;
    }
    .login-footer {
        text-align: center;
        margin-top: 12px;
    }
    #loginBtn,
    #verifyBtn {
        min-height: 48px;
    }
    .login-form-pane {
        min-height: 100dvh;
        padding: max(18px, env(safe-area-inset-top)) 0 max(18px, env(safe-area-inset-bottom));
        justify-content: center;
        align-items: center;
        background: transparent;
        backdrop-filter: none;
        -webkit-backdrop-filter: none;
        border-left: 0;
    }
    .login-panel-head {
        gap: 12px;
        margin-bottom: 12px;
    }
    .login-panel-head .login-logo {
        width: 56px;
        height: 56px;
        padding: 7px;
    }
    .login-intro-title {
        font-size: 1.16rem;
    }
    .login-intro-subtitle {
        font-size: 0.82rem;
    }
    .login-brand {
        padding: 10px 12px;
        border-radius: 14px;
        gap: 12px;
        margin-bottom: 14px;
    }
    .login-logo,
    .login-form-pane > .login-logo {
        width: 58px;
        height: 58px;
        padding: 8px;
    }
    .login-title {
        font-size: 1.42rem;
        color: #0f172a;
        background: none;
        -webkit-background-clip: border-box;
        background-clip: border-box;
        -webkit-text-fill-color: currentColor;
    }
    .login-form {
        gap: 12px;
    }
    .form-label {
        margin-bottom: 6px;
        font-size: 0.76rem;
    }
    .login-field {
        border-radius: 12px;
        padding: 0 10px;
    }
    .login-field-input {
        min-height: 46px;
        font-size: 0.92rem;
        padding: 9px 0;
    }
    #loginBtn {
        min-height: 46px;
        border-radius: 12px;
        font-size: 0.9rem;
    }
    .login-footer {
        margin-top: 14px;
        font-size: 0.78rem;
        line-height: 1.45;
    }
}

@media (max-width: 420px) {
    .login-form-pane {
        padding: max(14px, env(safe-area-inset-top)) 0 max(14px, env(safe-area-inset-bottom));
        justify-content: center;
        align-items: center;
    }
    .login-brand,
    .login-form,
    .login-footer,
    .login-form-pane > .alert,
    .login-form-pane > .login-form,
    .login-form-pane > .login-footer,
    .login-form-pane > .login-panel-head {
        width: 100%;
    }
    .login-brand {
        margin-bottom: 12px;
        border-radius: 12px;
    }
    .login-logo,
    .login-form-pane > .login-logo {
        width: 52px;
        height: 52px;
        padding: 7px;
    }
    .login-title {
        font-size: 1.28rem;
    }
    .login-subtitle {
        font-size: 0.84rem;
    }
    #loginBtn,
    #verifyBtn {
        min-height: 46px;
    }
}
</style>
<noscript>
<style>
.boot-logo-screen { display: none !important; }
.login-wrapper { opacity: 1 !important; transform: none !important; }
</style>
</noscript>
</head>
<body class="login-body login-v2 booting">

<div id="bootLogo" class="boot-logo-screen" aria-hidden="true">
    <div class="boot-logo-core">
        <div class="boot-logo-ring"></div>
        <img src="<?= APP_URL ?>/assets/images/logo.png" alt="TalaGuro Logo" class="boot-logo-image">
        <div class="boot-logo-glow"></div>
    </div>
    <div class="boot-logo-title">TalaGuro</div>
</div>

<div class="login-scene" aria-hidden="true"></div>

<div class="login-wrapper">
    <div class="login-shell">
        <span class="shell-right-glow" aria-hidden="true"></span>
        <aside class="login-hero-pane">
            <span class="hero-right-glow" aria-hidden="true"></span>
            <div class="hero-top">
                <span class="brand-chip"><i class="fas fa-wand-magic-sparkles"></i> Welcome</span>
             
                <h2 class="hero-title">TalaGuro</h2>
                <p class="hero-subtitle">Smart Teacher Data for Smarter Planning</p>
                      
                <div class="hero-accent-card">
                    <p class="hero-accent-quote">"Great planning starts with data people can trust every day."</p>
                    <p class="hero-accent-source">Education planning support system</p>
                    
                    <div class="hero-mini-tags">
                        <span class="hero-mini-tag"><i class="fas fa-circle-check"></i> Data-first</span>
                        <span class="hero-mini-tag"><i class="fas fa-circle-check"></i> School-ready</span>
                        <span class="hero-mini-tag"><i class="fas fa-circle-check"></i> Team-aligned</span>
                    </div>
                </div>
            </div>
        </aside>

        <section class="login-form-pane">
                <div class="login-panel-head">
                    <div class="login-logo">
                        <img src="<?= APP_URL ?>/assets/images/logo.png" alt="TalaGuro Logo" style="width:100%;height:100%;object-fit:contain;">
                    </div>
                    <div class="login-intro">
                        <?php if ($isTwoFactorStep): ?>
                        <h3 class="login-intro-title">Two-Factor Verification</h3>
                        <p class="login-intro-subtitle">Complete sign in with your 6-digit authenticator code.</p>
                        <?php else: ?>
                        <h3 class="login-intro-title">Welcome Back</h3>
                        <p class="login-intro-subtitle">Sign in to continue to your teacher planning workspace.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($msg): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-clock"></i> <?= clean($msg) ?>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= clean($error) ?>
                </div>
                <?php endif; ?>
            <?php if ($isTwoFactorStep): ?>
            <form method="POST" action="" class="login-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="mode" value="totp">

                <div class="form-group">
                    <label class="form-label" for="totp_code">Authenticator Code</label>
                    <div class="login-field">
                        <i class="fas fa-mobile-screen-button login-field-icon"></i>
                        <input
                            type="text"
                            id="totp_code"
                            name="totp_code"
                            class="form-input login-field-input"
                            placeholder="000000"
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            autocomplete="one-time-code"
                            required
                            autofocus>
                    </div>
                    <small class="text-muted">Open your authenticator app and enter the 6-digit code.</small>
                </div>

                <button type="submit" class="btn btn-primary btn-full btn-lg" id="verifyBtn">
                    <i class="fas fa-shield-halved"></i> Verify & Continue
                </button>
            </form>

            <form method="POST" action="" class="login-secondary-form">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="mode" value="cancel_2fa">
                <button type="submit" class="btn btn-ghost btn-full login-secondary-btn">Use Different Account</button>
            </form>
            <?php else: ?>
            <form method="POST" action="" class="login-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="mode" value="password">
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <div class="login-field">
                        <i class="fas fa-user login-field-icon"></i>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-input login-field-input"
                            placeholder="Type your username"
                            value="<?= clean($_POST['username'] ?? '') ?>"
                            autocomplete="username"
                            required
                            autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="login-field input-with-toggle">
                        <i class="fas fa-lock login-field-icon"></i>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-input login-field-input"
                            placeholder="Type your password"
                            autocomplete="current-password"
                            required>
                        <button type="button" class="toggle-password" data-target="password" title="Show / hide password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-full btn-lg" id="loginBtn">
                    <i class="fas fa-sign-in-alt"></i> Log In
                </button>
            </form>
            <?php endif; ?>

            <div class="login-footer">
                <div class="login-divider"></div>
                 <p class="text-muted small" style="margin-top:4px"><?= APP_FULL_NAME ?> &middot; v<?= APP_VERSION ?><br> as of 2026 Data</p>
            </div>
        </section>
    </div>
</div>

<script src="<?= APP_URL ?>/assets/js/main.js"></script>
<script>
(function() {
    const pane = document.querySelector('.login-form-pane');
    if (!pane) return;

    function resetLoadingStates() {
        pane.classList.remove('is-submitting');
        pane.querySelectorAll('form').forEach((form) => {
            form.classList.remove('is-submitting');
            delete form.dataset.submitting;
        });
        pane.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.classList.remove('is-loading');
            button.disabled = false;
            if (button.dataset.originalHtml) {
                button.innerHTML = button.dataset.originalHtml;
            }
        });
    }

    resetLoadingStates();
    window.addEventListener('pageshow', resetLoadingStates);

    const forms = pane.querySelectorAll('form');
    forms.forEach((form) => {
        form.addEventListener('submit', function(ev) {
            if (this.dataset.submitting === '1') {
                ev.preventDefault();
                return;
            }

            const submitter = ev.submitter || this.querySelector('button[type="submit"]');
            if (!submitter) {
                this.dataset.submitting = '1';
                return;
            }

            this.dataset.submitting = '1';
            this.classList.add('is-submitting');
            pane.classList.add('is-submitting');

            const submitMode = String(this.querySelector('input[name="mode"]')?.value || '').toLowerCase();
            submitter.classList.add('is-loading');
            submitter.disabled = true;

            if (!submitter.dataset.originalHtml) {
                submitter.dataset.originalHtml = submitter.innerHTML;
            }

            if (submitMode === 'totp') {
                submitter.innerHTML = '<i class="fas fa-spinner"></i> Verifying...';
            } else if (submitMode === 'cancel_2fa') {
                submitter.innerHTML = '<i class="fas fa-spinner"></i> Canceling...';
            } else {
                submitter.innerHTML = '<i class="fas fa-spinner"></i> Signing in...';
            }
        });
    });
})();
</script>
<script>
(function() {
    const boot = document.getElementById('bootLogo');
    const body = document.body;
    if (!boot || !body) return;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const splashTime = reducedMotion ? 300 : 900;
    let started = false;
    let done = false;

    function hideBoot() {
        if (done) return;
        done = true;
        boot.classList.add('hide');
        body.classList.remove('booting');
        setTimeout(function() { boot.remove(); }, 520);
    }

    function startBootTimer() {
        if (started) return;
        started = true;
        setTimeout(hideBoot, splashTime);
    }

    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        startBootTimer();
    } else {
        document.addEventListener('DOMContentLoaded', startBootTimer, { once: true });
        window.addEventListener('load', startBootTimer, { once: true });
        window.addEventListener('pageshow', startBootTimer, { once: true });
    }

    // Failsafe: never allow splash to get stuck.
    setTimeout(hideBoot, 2200);
})();
</script>
</body>
</html>
