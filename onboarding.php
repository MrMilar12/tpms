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

if (!needsOnboarding()) {
    redirect(APP_URL . '/dashboard');
}

$user = currentUser();
$userId = (int)($user['id'] ?? 0);
$userRole = strtolower($user['role'] ?? '');

// Role-specific welcome messages
$roleMessages = [
    'psds' => [
        'title' => 'Welcome, PSDS',
        'subtitle' => 'Public Schools Division Supervisor',
        'description' => 'You now have access to district-wide teacher and school management. Manage your assigned districts, view comprehensive reporting, and coordinate staffing across your division.',
        'icon' => 'fa-crown',
        'color' => '#667eea'
    ],
    'sdc' => [
        'title' => 'Welcome, SDC',
        'subtitle' => 'Schools Division Coordinator',
        'description' => 'You now have access to your district\'s teacher and school management systems. Monitor staffing, view reports, and manage personnel information for your district.',
        'icon' => 'fa-chart-line',
        'color' => '#f59e0b'
    ],
    'unit_head' => [
        'title' => 'Welcome, Unit Head',
        'subtitle' => 'School Management',
        'description' => 'You now have access to your school\'s teacher and personnel management. View your staff, manage assignments, and access school-wide reports and analytics.',
        'icon' => 'fa-school',
        'color' => '#06b6d4'
    ],
    'default' => [
        'title' => 'Welcome',
        'subtitle' => 'Teacher Personnel Management System',
        'description' => 'You now have access to the system. Get started by exploring the dashboard and familiarizing yourself with the available features.',
        'icon' => 'fa-rocket',
        'color' => '#6366f1'
    ]
];

$roleMessage = $roleMessages[$userRole] ?? $roleMessages['default'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$postedCsrf)) {
        $error = 'Session expired. Please try again.';
    } else {
        // Redirect to first login setup wizard
        redirect(APP_URL . '/first-login-setup');
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome - <?= APP_NAME ?></title>
<link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/images/logo.png">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/fonts/inter/inter.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= urlencode((string)(is_file(__DIR__ . '/assets/css/style.css') ? filemtime(__DIR__ . '/assets/css/style.css') : '1')) ?>">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }

body.welcome-page {
    min-height: 100vh;
    display: flex;
    align-items: stretch;
    justify-content: center;
    padding: clamp(10px, 3vw, 24px);
    background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
    position: relative;
    overflow-y: auto;
}

/* Animated background orbs */
body.welcome-page::before,
body.welcome-page::after {
    content: '';
    position: absolute;
    border-radius: 999px;
    filter: blur(80px);
    opacity: 0.4;
    animation: float 15s ease-in-out infinite;
    pointer-events: none;
}

body.welcome-page::before {
    width: clamp(250px, 40vw, 500px);
    height: clamp(250px, 40vw, 500px);
    background: radial-gradient(circle, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0));
    top: clamp(-125px, -20vw, -250px);
    left: clamp(-125px, -20vw, -250px);
}

body.welcome-page::after {
    width: clamp(200px, 30vw, 400px);
    height: clamp(200px, 30vw, 400px);
    background: radial-gradient(circle, rgba(251, 113, 133, 0.25), rgba(251, 113, 133, 0));
    bottom: clamp(-100px, -15vw, -200px);
    right: clamp(-100px, -15vw, -200px);
    animation-delay: -5s;
}

@keyframes float {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(30px); }
}

.welcome-container {
    position: relative;
    z-index: 10;
    max-width: 700px;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: clamp(8px, 2vw, 16px);
}

.welcome-card {
    background: rgba(255, 255, 255, 0.97);
    backdrop-filter: blur(20px);
    border-radius: clamp(18px, 4vw, 28px);
    padding: clamp(20px, 5vw, 60px) clamp(16px, 4vw, 48px);
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.2), 0 0 1px rgba(255, 255, 255, 0.5) inset;
    border: 1px solid rgba(255, 255, 255, 0.8);
    animation: slideUpIn 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
    position: relative;
    overflow: visible;
    width: 100%;
}

.welcome-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.8), transparent);
    pointer-events: none;
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

.welcome-icon {
    width: clamp(70px, 12vw, 100px);
    height: clamp(70px, 12vw, 100px);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: clamp(36px, 6vw, 48px);
    margin: 0 auto 40px;
    background: linear-gradient(135deg, <?= $roleMessage['color'] ?>22 0%, <?= $roleMessage['color'] ?>08 100%);
    border: 2px solid <?= $roleMessage['color'] ?>55;
    box-shadow: 0 20px 50px <?= $roleMessage['color'] ?>30, inset 0 0 0 1px rgba(255, 255, 255, 0.8);
    color: <?= $roleMessage['color'] ?>;
    animation: iconPulse 2.5s ease-in-out infinite;
}

@keyframes iconPulse {
    0%, 100% {
        transform: scale(1);
        box-shadow: 0 20px 50px <?= $roleMessage['color'] ?>30, inset 0 0 0 1px rgba(255, 255, 255, 0.8);
    }
    50% {
        transform: scale(1.1);
        box-shadow: 0 30px 60px <?= $roleMessage['color'] ?>40, inset 0 0 0 1px rgba(255, 255, 255, 1);
    }
}

.welcome-header {
    display: flex;
    flex-direction: column;
    text-align: center;
    margin-bottom: 50px;
    align-items: center;
    justify-content: flex-start;
}

.welcome-header-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}

.welcome-header-text {
    flex: 1;
    min-width: 200px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.welcome-header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: center;
    opacity: 0;
    animation: fadeInDown 0.6s ease 0.15s forwards;
}

.welcome-kicker {
    margin: 0 0 12px;
    font-size: clamp(10px, 1.5vw, 12px);
    font-weight: 800;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    color: #64748b;
    opacity: 0;
    animation: fadeInDown 0.6s ease 0.1s forwards;
}

.welcome-title {
    margin: 0 0 8px;
    font-size: clamp(24px, 4.5vw, 48px);
    font-weight: 900;
    color: #0f172a;
    line-height: 1.1;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    opacity: 0;
    animation: fadeInDown 0.6s ease 0.2s forwards;
}

.welcome-subtitle {
    margin: 0;
    font-size: clamp(12px, 2vw, 16px);
    color: #64748b;
    font-weight: 600;
    letter-spacing: 0.02em;
    opacity: 0;
    animation: fadeInDown 0.6s ease 0.3s forwards;
}

.welcome-content {
    text-align: center;
    margin: 0 0 0;
}

.welcome-description {
    margin: 0 0 clamp(24px, 4vw, 48px);
    font-size: clamp(14px, 2vw, 17px);
    color: #475569;
    line-height: 1.8;
    opacity: 0;
    animation: fadeInDown 0.6s ease 0.4s forwards;
}

.welcome-highlights {
    display: grid;
    gap: clamp(12px, 2vw, 24px);
    margin: 0 0 clamp(24px, 4vw, 56px);
    padding: clamp(16px, 3vw, 40px);
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: clamp(16px, 3vw, 24px);
    border: 1px solid #e2e8f0;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
    opacity: 0;
    animation: fadeInUp 0.6s ease 0.5s forwards;
}

@keyframes fadeInDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.highlight-item {
    display: flex;
    gap: 16px;
    align-items: flex-start;
    text-align: left;
    padding: 12px;
    border-radius: 12px;
    transition: all 0.3s ease;
}

.highlight-item:hover {
    background: rgba(255, 255, 255, 0.5);
    transform: translateX(4px);
}

.highlight-item i {
    color: <?= $roleMessage['color'] ?>;
    font-size: 20px;
    margin-top: 2px;
    flex-shrink: 0;
    width: 24px;
    text-align: center;
    filter: drop-shadow(0 2px 4px <?= $roleMessage['color'] ?>20);
}

.highlight-item p {
    margin: 0;
    font-size: 15px;
    color: #334155;
    line-height: 1.6;
}

.highlight-item strong {
    color: #0f172a;
    font-weight: 700;
}

.welcome-actions {
    display: none;
}

.welcome-form {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
}

.welcome-btn {
    padding: clamp(10px, 2vw, 14px) clamp(20px, 4vw, 32px);
    border-radius: clamp(12px, 3vw, 16px);
    border: 0;
    font-weight: 700;
    font-size: clamp(13px, 2vw, 16px);
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: clamp(6px, 1vw, 10px);
    position: relative;
    overflow: hidden;
    min-height: 44px;
    -webkit-tap-highlight-color: transparent;
    white-space: nowrap;
}

.welcome-btn::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(105deg, rgba(255,255,255,0), rgba(255,255,255,0.3), rgba(255,255,255,0));
    transform: translateX(-100%);
    transition: transform 0.6s;
    pointer-events: none;
}

.welcome-btn:hover::before {
    transform: translateX(100%);
}

.btn-proceed {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #ec4899 100%);
    color: white;
    box-shadow: 0 15px 40px rgba(102, 126, 234, 0.35);
    font-size: clamp(13px, 2vw, 16px);
    letter-spacing: 0.01em;
    min-width: auto;
}

.btn-proceed:hover {
    transform: translateY(-3px);
    box-shadow: 0 20px 50px rgba(102, 126, 234, 0.45);
}

.btn-proceed:active {
    transform: translateY(0);
}

@media (hover: none) {
    .btn-proceed:hover {
        transform: none;
    }
}

.welcome-footer {
    margin-top: clamp(16px, 3vw, 32px);
    padding-top: clamp(16px, 3vw, 32px);
    border-top: 2px solid #e2e8f0;
    text-align: center;
    font-size: clamp(10px, 1.5vw, 13px);
    color: #94a3b8;
    opacity: 0;
    animation: fadeInUp 0.6s ease 0.7s forwards;
}

.welcome-footer strong {
    color: #667eea;
    display: block;
    margin-bottom: clamp(3px, 0.5vw, 6px);
    font-weight: 700;
}

/* ── RESPONSIVE BREAKPOINTS ───────────────────────────────── */

/* Tablet: 768px to 1024px */
@media (min-width: 769px) and (max-width: 1024px) {
    body.welcome-page {
        padding: clamp(16px, 2vw, 20px);
    }

    .welcome-card {
        padding: clamp(40px, 5vw, 54px) clamp(30px, 4vw, 40px);
        border-radius: clamp(22px, 4vw, 26px);
    }

    .welcome-header {
        margin-bottom: clamp(28px, 5vw, 40px);
    }

    .welcome-header-top {
        margin-bottom: 12px;
    }

    .welcome-icon {
        width: clamp(80px, 12vw, 90px);
        height: clamp(80px, 12vw, 90px);
        font-size: clamp(40px, 8vw, 44px);
        margin-bottom: clamp(24px, 4vw, 36px);
    }

    .welcome-btn {
        padding: clamp(11px, 1.5vw, 13px) clamp(24px, 3vw, 30px);
        font-size: clamp(14px, 2vw, 15px);
        min-height: 48px;
    }
}

/* Tablet/Mobile: 480px to 768px */
@media (max-width: 768px) {
    body.welcome-page {
        padding: clamp(12px, 2.5vw, 16px);
    }

    .welcome-container {
        padding: clamp(8px, 2vw, 12px);
    }

    .welcome-card {
        padding: clamp(24px, 4vw, 48px) clamp(16px, 3.5vw, 32px);
        border-radius: clamp(18px, 3.5vw, 24px);
    }

    .welcome-header {
        margin-bottom: clamp(24px, 4vw, 40px);
    }

    .welcome-header-top {
        flex-direction: column;
        margin-bottom: 8px;
    }

    .welcome-header-text {
        width: 100%;
        min-width: auto;
    }

    .welcome-header-actions {
        width: 100%;
    }

    .welcome-form {
        width: 100%;
    }

    .welcome-btn {
        flex: 1;
        min-width: 120px;
    }

    .welcome-header-top {
        flex-direction: column;
        margin-bottom: 8px;
    }

    .welcome-header-text {
        width: 100%;
        min-width: auto;
    }

    .welcome-header-actions {
        width: 100%;
    }

    .welcome-form {
        width: 100%;
    }

    .welcome-btn {
        flex: 1;
        min-width: 120px;
    }

    .welcome-icon {
        width: clamp(64px, 14vw, 85px);
        height: clamp(64px, 14vw, 85px);
        font-size: clamp(32px, 8vw, 42px);
        margin-bottom: clamp(20px, 3vw, 28px);
    }

    .welcome-title {
        font-size: clamp(26px, 5vw, 36px);
        line-height: 1.15;
        margin-bottom: clamp(4px, 1vw, 8px);
    }

    .welcome-subtitle {
        font-size: clamp(12px, 2.5vw, 15px);
        margin-bottom: clamp(8px, 1vw, 12px);
    }

    .welcome-kicker {
        font-size: clamp(9px, 1.8vw, 12px);
        margin-bottom: clamp(8px, 1vw, 12px);
    }

    .welcome-description {
        font-size: clamp(13px, 2.5vw, 15px);
        margin-bottom: clamp(20px, 3vw, 32px);
        line-height: 1.7;
    }

    .welcome-highlights {
        padding: clamp(14px, 2.5vw, 28px);
        gap: clamp(10px, 1.8vw, 16px);
        margin-bottom: clamp(20px, 3vw, 40px);
        border-radius: clamp(14px, 2.5vw, 18px);
    }

    .highlight-item {
        padding: clamp(6px, 1.5vw, 10px) clamp(6px, 1.5vw, 8px);
        gap: clamp(10px, 2vw, 12px);
        border-radius: clamp(8px, 1.5vw, 10px);
    }

    .highlight-item i {
        font-size: clamp(14px, 3vw, 20px);
        width: clamp(18px, 4vw, 24px);
        margin-top: clamp(1px, 0.5vw, 2px);
    }

    .highlight-item p {
        font-size: clamp(12px, 2vw, 14px);
        line-height: 1.5;
    }

    .welcome-btn {
        padding: clamp(9px, 1.5vw, 11px) clamp(14px, 2.5vw, 20px);
        font-size: clamp(11px, 2vw, 13px);
        min-height: 40px;
        gap: clamp(5px, 1vw, 8px);
        border-radius: clamp(10px, 2.5vw, 12px);
    }

    .welcome-footer {
        margin-top: clamp(16px, 2.5vw, 24px);
        padding-top: clamp(16px, 2.5vw, 24px);
        font-size: clamp(11px, 1.5vw, 12px);
    }

    .welcome-footer strong {
        margin-bottom: clamp(2px, 0.5vw, 4px);
        font-size: clamp(11px, 1.5vw, 12px);
    }
}

/* Mobile: 361px to 480px */
@media (max-width: 480px) {
    body.welcome-page {
        padding: clamp(10px, 2vw, 14px);
        min-height: 100vh;
        align-items: flex-start;
    }

    .welcome-container {
        min-height: auto;
        padding: clamp(8px, 1.5vw, 10px);
        margin-top: clamp(10px, 2vw, 16px);
        margin-bottom: clamp(10px, 2vw, 16px);
    }

    .welcome-card {
        padding: clamp(18px, 3vw, 32px) clamp(14px, 3vw, 20px);
        border-radius: clamp(16px, 3vw, 20px);
        width: 100%;
    }

    .welcome-header {
        margin-bottom: clamp(18px, 3vw, 28px);
    }

    .welcome-header-top {
        flex-direction: column;
        gap: 8px;
        margin-bottom: 8px;
    }

    .welcome-header-text {
        width: 100%;
        min-width: auto;
    }

    .welcome-header-actions {
        width: 100%;
    }

    .welcome-form {
        width: 100%;
        flex-direction: column;
    }

    .welcome-btn {
        width: 100%;
        min-width: auto;
    }

    .welcome-icon {
        width: clamp(54px, 12vw, 70px);
        height: clamp(54px, 12vw, 70px);
        font-size: clamp(26px, 6vw, 36px);
        margin-bottom: clamp(16px, 2.5vw, 20px);
    }

    .welcome-title {
        font-size: clamp(20px, 4.5vw, 28px);
        line-height: 1.2;
        margin-bottom: clamp(3px, 0.8vw, 6px);
    }

    .welcome-subtitle {
        font-size: clamp(11px, 2vw, 13px);
        line-height: 1.3;
    }

    .welcome-kicker {
        font-size: clamp(8px, 1.5vw, 11px);
        margin-bottom: clamp(6px, 1vw, 10px);
        letter-spacing: 0.1em;
    }

    .welcome-description {
        font-size: clamp(12px, 2.2vw, 14px);
        margin-bottom: clamp(16px, 2.5vw, 24px);
        line-height: 1.6;
    }

    .welcome-highlights {
        padding: clamp(12px, 2vw, 18px);
        gap: clamp(8px, 1.5vw, 12px);
        margin-bottom: clamp(16px, 2.5vw, 28px);
        border-radius: clamp(12px, 2.5vw, 14px);
    }

    .highlight-item {
        padding: clamp(5px, 1vw, 8px) clamp(4px, 1vw, 6px);
        gap: clamp(8px, 1.5vw, 10px);
        border-radius: clamp(8px, 1.5vw, 10px);
        flex-direction: row;
    }

    .highlight-item i {
        font-size: clamp(12px, 2.5vw, 16px);
        width: clamp(16px, 3vw, 20px);
        min-width: clamp(16px, 3vw, 20px);
        margin-top: 0;
    }

    .highlight-item p {
        font-size: clamp(11px, 1.8vw, 13px);
        line-height: 1.4;
    }

    .highlight-item strong {
        display: block;
        margin-bottom: clamp(1px, 0.3vw, 2px);
    }

    .welcome-actions {
        width: 100%;
    }

    .welcome-btn {
        padding: clamp(9px, 1.2vw, 11px) clamp(14px, 2.5vw, 22px);
        font-size: clamp(11px, 1.8vw, 13px);
        min-height: 40px;
        gap: clamp(5px, 0.8vw, 8px);
        border-radius: clamp(11px, 2vw, 14px);
    }

    .welcome-footer {
        margin-top: clamp(12px, 2vw, 20px);
        padding-top: clamp(12px, 2vw, 20px);
        font-size: clamp(10px, 1.5vw, 11px);
        border-top: 1px solid #e2e8f0;
    }

    .welcome-footer strong {
        margin-bottom: clamp(2px, 0.3vw, 4px);
        font-size: clamp(10px, 1.5vw, 11px);
    }
}

/* Extra Small Mobile: up to 360px */
@media (max-width: 360px) {
    body.welcome-page {
        padding: 8px;
        align-items: flex-start;
    }

    .welcome-container {
        padding: 6px;
        margin-top: 8px;
        margin-bottom: 8px;
        min-height: auto;
    }

    .welcome-card {
        padding: 16px 12px;
        border-radius: 16px;
        width: 100%;
    }

    .welcome-header {
        margin-bottom: 16px;
    }

    .welcome-icon {
        width: 50px;
        height: 50px;
        font-size: 24px;
        margin-bottom: 14px;
    }

    .welcome-title {
        font-size: 18px;
        line-height: 1.15;
        margin-bottom: 3px;
    }

    .welcome-subtitle {
        font-size: 10px;
        line-height: 1.3;
    }

    .welcome-kicker {
        font-size: 8px;
        margin-bottom: 6px;
        letter-spacing: 0.1em;
    }

    .welcome-description {
        font-size: 11px;
        margin-bottom: 14px;
        line-height: 1.5;
    }

    .welcome-highlights {
        padding: 10px;
        gap: 8px;
        margin-bottom: 14px;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
    }

    .highlight-item {
        padding: 4px 4px;
        gap: 7px;
        border-radius: 6px;
        flex-direction: row;
    }

    .highlight-item i {
        font-size: 11px;
        width: 14px;
        min-width: 14px;
        margin-top: 0;
        margin-right: 2px;
    }

    .highlight-item p {
        font-size: 10px;
        line-height: 1.35;
    }

    .highlight-item strong {
        display: block;
        margin-bottom: 1px;
        font-size: 9px;
    }

    .welcome-actions {
        width: 100%;
        margin-bottom: 4px;
    }

    .welcome-btn {
        padding: 8px 12px;
        font-size: 10px;
        min-height: 36px;
        gap: 5px;
        border-radius: 10px;
    }

    .welcome-footer {
        margin-top: 10px;
        padding-top: 10px;
        font-size: 9px;
        border-top: 1px solid #e2e8f0;
    }

    .welcome-footer strong {
        margin-bottom: 2px;
        font-size: 9px;
    }

    body.welcome-page::before {
        width: 200px;
        height: 200px;
        top: -100px;
        left: -100px;
        opacity: 0.2;
    }

    body.welcome-page::after {
        width: 160px;
        height: 160px;
        bottom: -80px;
        right: -80px;
        opacity: 0.2;
    }
}
</style>
</head>
<body class="welcome-page">
<div class="welcome-container">
    <div class="welcome-card">
        <div class="welcome-icon">
            <i class="fas <?= $roleMessage['icon'] ?>"></i>
        </div>

        <div class="welcome-header">
            <div class="welcome-header-top">
                <div class="welcome-header-text">
                    <p class="welcome-kicker"><?= APP_FULL_NAME ?></p>
                    <h1 class="welcome-title"><?= clean($roleMessage['title']) ?></h1>
                    <p class="welcome-subtitle"><?= clean($roleMessage['subtitle']) ?></p>
                </div>
                <div class="welcome-header-actions">
                    <form method="POST" class="welcome-form">
                        <input type="hidden" name="csrf_token" value="<?= clean($csrf) ?>">
                        <button type="submit" class="welcome-btn btn-proceed">
                            Get Started
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="welcome-content">
            <p class="welcome-description">
                <?= clean($roleMessage['description']) ?>
            </p>

            <div class="welcome-highlights">
                <div class="highlight-item">
                    <i class="fas fa-chart-pie"></i>
                    <p><strong>Dashboard:</strong> Get real-time insights into your personnel and schools</p>
                </div>
                <div class="highlight-item">
                    <i class="fas fa-users"></i>
                    <p><strong>Teachers:</strong> Manage and track teacher information and assignments</p>
                </div>
                <div class="highlight-item">
                    <i class="fas fa-building"></i>
                    <p><strong>Schools:</strong> View and manage school details and statistics</p>
                </div>
                <div class="highlight-item">
                    <i class="fas fa-file-export"></i>
                    <p><strong>Reports:</strong> Generate comprehensive reports for planning and analysis</p>
                </div>
            </div>
        </div>

        <div class="welcome-footer">
            <strong>Pro Tip:</strong> Customize your appearance settings anytime in the Appearance menu.
        </div>
    </div>
</div>
</body>
</html>
