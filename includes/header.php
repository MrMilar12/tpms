<?php
// includes/header.php – included at top of every page
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
startSecureSession();
sendSecurityHeaders();
requireLogin();

// CRITICAL: Enforce role selection if role is NULL in database or has default 'viewer' role
// This is a safety net to ensure users MUST select a real role
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentUser = currentUser();
$userRole = $currentUser['role'] ?? null;
if ($userRole === null || $userRole === '' || strtolower($userRole) === 'null' || strtolower($userRole) === 'viewer') {
    // User has no real role assigned - force them to select one
    // But don't redirect from select-role.php itself to avoid infinite loop
    if ($currentPage !== 'select-role' && $currentPage !== 'login' && $currentPage !== 'logout') {
        redirect(APP_URL . '/select-role');
    }
}

$currentUserPhoto = trim((string)($currentUser['profile_photo'] ?? ''));
$currentUserPhotoUrl = $currentUserPhoto !== '' ? (UPLOAD_URL . rawurlencode($currentUserPhoto)) : '';

$currentUserId = (int)($currentUser['id'] ?? 0);
$db = getDB();

// Ensure district is set from session (set during login from users.district_id)
if (getSessionDistrict() === null) {
    $districtStmt = $db->prepare('SELECT district_id FROM users WHERE id = ? LIMIT 1');
    $districtStmt->execute([$currentUserId]);
    $userDistrict = (int)($districtStmt->fetchColumn() ?? 0);
    if ($userDistrict > 0) {
        setSessionDistrict($userDistrict);
    }
}

// Get selected district for display
$selectedDistrictId = getSessionDistrict();
$selectedDistrictName = '';
if ($selectedDistrictId !== null) {
    $districtStmt = $db->prepare('SELECT district_name FROM districts WHERE id = ? LIMIT 1');
    $districtStmt->execute([$selectedDistrictId]);
    $selectedDistrictName = trim((string)($districtStmt->fetchColumn() ?? ''));
}

// Active page detection (already set above, but for clarity at this line)

$isOnboardingPreview = isset($_GET['onboarding_preview']) && $_GET['onboarding_preview'] === '1';

if (needsOnboarding() && $currentPage !== 'first-login-setup' && !$isOnboardingPreview) {
    redirect(APP_URL . '/first-login-setup');
}

$serverPreferredTheme = trim((string)($currentUser['preferred_theme'] ?? ''));
$serverPreferredLayout = trim((string)($currentUser['preferred_layout'] ?? ''));
$serverPreferredAppearanceJson = trim((string)($currentUser['preferred_appearance_json'] ?? ''));

if ($isOnboardingPreview) {
    $previewTheme = trim((string)($_GET['preview_theme'] ?? ''));
    $previewLayout = trim((string)($_GET['preview_layout'] ?? ''));
    $previewDensity = trim((string)($_GET['preview_density'] ?? ''));
    $previewBgPalette = trim((string)($_GET['preview_bg_palette'] ?? ''));
    $previewBgEffects = trim((string)($_GET['preview_bg_effects'] ?? ''));
    $previewGlassTone = trim((string)($_GET['preview_glass_tone'] ?? ''));
    $previewAccentColor = strtolower(trim((string)($_GET['preview_accent_color'] ?? '')));
    $previewBgTintColor = strtolower(trim((string)($_GET['preview_bg_tint_color'] ?? '')));
    $previewTeacherTagColor = strtolower(trim((string)($_GET['preview_teacher_tag_color'] ?? '')));
    $previewSchoolHeadColor = strtolower(trim((string)($_GET['preview_school_head_color'] ?? '')));

    $allowedThemes = ['glass', 'frosted-glass', 'ios', 'pastel-sky', 'pastel-sunset'];
    $allowedLayouts = ['default', 'app'];
    $allowedDensity = ['comfortable', 'compact'];
    $allowedBgPalette = ['theme', 'custom-color'];
    $allowedBgEffects = ['off', 'soft', 'vivid', 'immersive', 'color-flow'];
    $allowedGlassTone = ['soft', 'balanced', 'strong'];

    $previewAppearance = [];
    if ($serverPreferredAppearanceJson !== '') {
        $decodedAppearance = json_decode($serverPreferredAppearanceJson, true);
        if (is_array($decodedAppearance)) {
            $previewAppearance = $decodedAppearance;
        }
    }

    if (in_array($previewTheme, $allowedThemes, true)) {
        $serverPreferredTheme = $previewTheme;
        $previewAppearance['theme'] = $previewTheme;
    }
    if (in_array($previewLayout, $allowedLayouts, true)) {
        $serverPreferredLayout = $previewLayout;
        $previewAppearance['layout'] = $previewLayout;
    }
    if (in_array($previewDensity, $allowedDensity, true)) {
        $previewAppearance['density'] = $previewDensity;
    }
    if (in_array($previewBgPalette, $allowedBgPalette, true)) {
        $previewAppearance['bgPalette'] = $previewBgPalette;
    }
    if (in_array($previewBgEffects, $allowedBgEffects, true)) {
        $previewAppearance['bgEffects'] = $previewBgEffects;
    }
    if (in_array($previewGlassTone, $allowedGlassTone, true)) {
        $previewAppearance['glassTone'] = $previewGlassTone;
    }

    $hexPattern = '/^#[0-9a-f]{6}$/';
    if (preg_match($hexPattern, $previewAccentColor) === 1) {
        $previewAppearance['accentColor'] = $previewAccentColor;
    }
    if (preg_match($hexPattern, $previewBgTintColor) === 1) {
        $previewAppearance['bgTintColor'] = $previewBgTintColor;
    }
    if (preg_match($hexPattern, $previewTeacherTagColor) === 1) {
        $previewAppearance['teacherTagColor'] = $previewTeacherTagColor;
    }
    if (preg_match($hexPattern, $previewSchoolHeadColor) === 1) {
        $previewAppearance['schoolHeadColor'] = $previewSchoolHeadColor;
    }

    $encodedPreviewAppearance = json_encode($previewAppearance, JSON_UNESCAPED_SLASHES);
    if (is_string($encodedPreviewAppearance) && $encodedPreviewAppearance !== '[]' && $encodedPreviewAppearance !== '{}') {
        $serverPreferredAppearanceJson = $encodedPreviewAppearance;
    }
}

$navLinks = [
    ['href' => 'dashboard',  'icon' => 'tachometer-alt',     'label' => 'Dashboard',   'page' => 'dashboard'],
    ['href' => 'profile',    'icon' => 'user-shield',        'label' => 'My Profile',  'page' => 'profile'],
    ['href' => 'my_activity','icon' => 'user-clock',         'label' => 'My Activity', 'page' => 'my_activity'],
    ['href' => 'teachers',   'icon' => 'chalkboard-teacher', 'label' => 'Teachers',    'page' => 'teachers'],
    ['href' => 'schools',    'icon' => 'school',             'label' => 'Schools',     'page' => 'schools'],
    ['href' => 'chat',       'icon' => 'comments',           'label' => 'Team Chat',   'page' => 'chat'],
    ['href' => 'retirement_watch', 'icon' => 'user-clock', 'label' => 'Retirement Watch', 'page' => 'retirement_watch'],
    ['href' => 'districts',  'icon' => 'map-location-dot',   'label' => 'Districts',   'page' => 'districts', 'hideForRoles' => ['psds', 'sdc']],
    ['href' => 'reports',    'icon' => 'chart-bar',          'label' => 'Reports',     'page' => 'reports'],
   
    ['href' => 'appearance', 'icon' => 'palette',            'label' => 'Appearance',  'page' => 'appearance'],
    ['href' => 'updates',    'icon' => 'history',            'label' => 'Updates',     'page' => 'updates'],
    ['href' => 'chatbot',    'icon' => 'robot',              'label' => 'Tala AI',  'page' => 'chatbot'],
];
if (isAdmin()) {
    $navLinks[] = ['href' => 'users', 'icon' => 'users-cog',      'label' => 'Users', 'page' => 'users'];
    $navLinks[] = ['href' => 'logs',  'icon' => 'clipboard-list', 'label' => 'Logs',  'page' => 'logs'];
}

$dockPages = ['dashboard', 'teachers', 'schools', 'chat', 'chatbot'];
$dockLinks = [];
foreach ($dockPages as $dockPage) {
    foreach ($navLinks as $link) {
        if ($link['page'] === $dockPage) {
            $dockLinks[] = $link;
            break;
        }
    }
}

$stylePath = __DIR__ . '/../assets/css/style.css';
$styleVersion = is_file($stylePath) ? (string)filemtime($stylePath) : '1';
$isAppWindow = isset($_GET['app_window']) && $_GET['app_window'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= clean($pageTitle ?? APP_FULL_NAME) ?> – <?= APP_NAME ?></title>
<meta name="description" content="Teacher Profiling Management System">
<link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/images/logo.png">

<script>
(function() {
    var forceAppWindowLayout = <?= $isAppWindow ? 'true' : 'false' ?>;
    var serverPreferredTheme = <?= json_encode($serverPreferredTheme) ?>;
    var serverPreferredLayout = <?= json_encode($serverPreferredLayout) ?>;
    var serverPreferredAppearanceJson = <?= json_encode($serverPreferredAppearanceJson) ?>;

    function isValidHexColor(value) {
        return /^#[0-9a-fA-F]{6}$/.test(String(value || ''));
    }

    function hexToRgb(hex) {
        var normalized = String(hex || '#6366f1').trim();
        if (!isValidHexColor(normalized)) normalized = '#6366f1';
        var int = parseInt(normalized.slice(1), 16);
        return {
            r: (int >> 16) & 255,
            g: (int >> 8) & 255,
            b: int & 255
        };
    }

    function darkenHex(hex, amount) {
        var rgb = hexToRgb(hex);
        var clamp = function(v) { return Math.max(0, Math.min(255, v)); };
        var toHex = function(v) { return clamp(v).toString(16).padStart(2, '0'); };
        return '#' + toHex(rgb.r - amount) + toHex(rgb.g - amount) + toHex(rgb.b - amount);
    }

    function rgbaFromHex(hex, alpha) {
        var rgb = hexToRgb(hex);
        return 'rgba(' + rgb.r + ', ' + rgb.g + ', ' + rgb.b + ', ' + alpha + ')';
    }

    function contrastTextFromHex(hex) {
        var rgb = hexToRgb(hex);
        var brightness = ((rgb.r * 299) + (rgb.g * 587) + (rgb.b * 114)) / 1000;
        return brightness >= 160 ? '#0f172a' : '#f8fafc';
    }

    function applyReadableMode(theme, bgPalette, tintHex) {
        var tint = isValidHexColor(tintHex) ? String(tintHex).toLowerCase() : '#c4b5fd';
        var rgb = hexToRgb(tint);
        var brightness = ((rgb.r * 299) + (rgb.g * 587) + (rgb.b * 114)) / 1000;
        var isLightTint = brightness >= 160;
        var nextTheme = String(theme || 'ios');
        var nextBgPalette = String(bgPalette || 'theme');
        var mode = 'light-text';

        if (nextTheme === 'frosted-glass' && nextBgPalette !== 'custom-color') {
            mode = 'light-text';
        } else if (nextBgPalette === 'custom-color') {
            mode = isLightTint ? 'dark-text' : 'light-text';
        } else if (nextTheme === 'ios' || nextTheme === 'pastel-sky' || nextTheme === 'pastel-sunset') {
            mode = 'dark-text';
        }

        document.documentElement.setAttribute('data-readable-mode', mode);
    }

    function applyAccentColor(hex) {
        var accent = isValidHexColor(hex) ? String(hex).toLowerCase() : '#6366f1';
        var rootStyle = document.documentElement.style;
        rootStyle.setProperty('--user-accent', accent);
        rootStyle.setProperty('--user-accent-dark', darkenHex(accent, 26));
        rootStyle.setProperty('--user-accent-glow', rgbaFromHex(accent, 0.28));
    }

    function applyBackgroundTint(hex) {
        var tint = isValidHexColor(hex) ? String(hex).toLowerCase() : '#c4b5fd';
        var rootStyle = document.documentElement.style;
        var rgb = hexToRgb(tint);
        var brightness = ((rgb.r * 299) + (rgb.g * 587) + (rgb.b * 114)) / 1000;
        var isLight = brightness >= 160;
        rootStyle.setProperty('--user-bg-solid', tint);
        rootStyle.setProperty('--user-bg-tint-soft', rgbaFromHex(tint, 0.24));
        rootStyle.setProperty('--user-bg-tint-mid', rgbaFromHex(tint, 0.18));
        rootStyle.setProperty('--user-bg-tint-deep', rgbaFromHex(tint, 0.14));
        rootStyle.setProperty('--auto-text', isLight ? '#0f172a' : '#f8fafc');
        rootStyle.setProperty('--auto-text-muted', isLight ? '#334155' : '#cbd5e1');
        rootStyle.setProperty('--auto-text-sub', isLight ? '#475569' : '#94a3b8');
        rootStyle.setProperty('--auto-glass-bg', isLight ? 'rgba(255, 255, 255, 0.72)' : 'rgba(15, 23, 42, 0.52)');
        rootStyle.setProperty('--auto-glass-border', isLight ? 'rgba(15, 23, 42, 0.16)' : 'rgba(148, 163, 184, 0.34)');
        rootStyle.setProperty('--auto-select-option-bg', isLight ? '#ffffff' : '#1e293b');
        rootStyle.setProperty('--auto-select-option-text', isLight ? '#0f172a' : '#f8fafc');
    }

    function applyFrostedCardTint(theme, bgPalette, tintHex) {
        var rootStyle = document.documentElement.style;
        var nextTheme = String(theme || 'ios');
        var nextBgPalette = String(bgPalette || 'theme');

        if (nextTheme === 'frosted-glass' && nextBgPalette === 'custom-color') {
            var tint = isValidHexColor(tintHex) ? String(tintHex).toLowerCase() : '#c4b5fd';
            var rgb = hexToRgb(tint);
            var brightness = ((rgb.r * 299) + (rgb.g * 587) + (rgb.b * 114)) / 1000;
            var isLight = brightness >= 160;
            rootStyle.setProperty('--app-card-bg', rgbaFromHex(tint, isLight ? 0.5 : 0.42));
            rootStyle.setProperty('--app-card-border', rgbaFromHex(tint, isLight ? 0.78 : 0.68));
            rootStyle.setProperty('--app-card-tint-soft', rgbaFromHex(tint, isLight ? 0.56 : 0.46));
            rootStyle.setProperty('--app-card-tint-mid', rgbaFromHex(tint, isLight ? 0.38 : 0.28));
            return;
        }

        rootStyle.removeProperty('--app-card-bg');
        rootStyle.removeProperty('--app-card-border');
        rootStyle.removeProperty('--app-card-tint-soft');
        rootStyle.removeProperty('--app-card-tint-mid');
    }

    function applyTeacherCardColors(tagHex, headHex) {
        var tag = isValidHexColor(tagHex) ? String(tagHex).toLowerCase() : '#94a3b8';
        var head = isValidHexColor(headHex) ? String(headHex).toLowerCase() : '#ef4444';
        var rootStyle = document.documentElement.style;
        rootStyle.setProperty('--teacher-card-tint-soft', rgbaFromHex(tag, 0.24));
        rootStyle.setProperty('--teacher-card-tint-mid', rgbaFromHex(tag, 0.16));
        rootStyle.setProperty('--teacher-card-base', rgbaFromHex(tag, 0.22));
        rootStyle.setProperty('--teacher-card-border', rgbaFromHex(tag, 0.44));
        rootStyle.setProperty('--teacher-tag-bg', rgbaFromHex(tag, 0.2));
        rootStyle.setProperty('--teacher-tag-border', rgbaFromHex(tag, 0.46));
        rootStyle.setProperty('--teacher-tag-text', contrastTextFromHex(tag));
        rootStyle.setProperty('--teacher-head-soft', rgbaFromHex(head, 0.26));
        rootStyle.setProperty('--teacher-head-mid', rgbaFromHex(head, 0.14));
        rootStyle.setProperty('--teacher-head-border', rgbaFromHex(head, 0.72));
        rootStyle.setProperty('--teacher-head-shadow', rgbaFromHex(head, 0.3));
        rootStyle.setProperty('--teacher-head-chip-bg', rgbaFromHex(head, 0.2));
        rootStyle.setProperty('--teacher-head-chip-border', rgbaFromHex(head, 0.6));
        rootStyle.setProperty('--teacher-head-chip-text', contrastTextFromHex(head));
        rootStyle.setProperty('--teacher-head-badge-bg', rgbaFromHex(head, 0.22));
        rootStyle.setProperty('--teacher-head-badge-border', rgbaFromHex(head, 0.66));
        rootStyle.setProperty('--teacher-head-badge-text', contrastTextFromHex(head));
        rootStyle.setProperty('--teacher-head-icon', head);
    }

    try {
        var raw = localStorage.getItem('tpmsAppearance');
        var allowedThemes = ['glass', 'frosted-glass', 'ios', 'pastel-sky', 'pastel-sunset'];
        var allowedLayouts = ['default', 'app'];

        var serverAppearance = {};
        if (serverPreferredAppearanceJson) {
            try {
                var parsedAppearance = JSON.parse(serverPreferredAppearanceJson);
                if (parsedAppearance && typeof parsedAppearance === 'object') {
                    serverAppearance = parsedAppearance;
                }
            } catch (appearanceParseErr) {}
        }

        if (allowedThemes.indexOf(serverPreferredTheme) !== -1) {
            serverAppearance.theme = serverPreferredTheme;
        }
        if (allowedLayouts.indexOf(serverPreferredLayout) !== -1) {
            serverAppearance.layout = serverPreferredLayout;
        }

        if (!raw) {
            if (Object.keys(serverAppearance).length > 0) {
                raw = JSON.stringify(serverAppearance);
                localStorage.setItem('tpmsAppearance', raw);
            }
        }

        if (forceAppWindowLayout) {
            document.documentElement.setAttribute('data-layout', 'app');
        }
        if (!raw) {
            document.documentElement.setAttribute('data-theme', 'ios');
            document.documentElement.setAttribute('data-layout', forceAppWindowLayout ? 'app' : 'app');
            document.documentElement.setAttribute('data-bg-effects', 'soft');
            applyReadableMode('ios', 'theme', '#c4b5fd');
            return;
        }
        var prefs = JSON.parse(raw);
        var nextTheme = prefs && prefs.theme ? String(prefs.theme) : 'ios';
        var nextBgPalette = prefs && prefs.bgPalette ? String(prefs.bgPalette) : 'theme';
        var nextBgTint = prefs && prefs.bgTintColor ? String(prefs.bgTintColor) : '#c4b5fd';
        if (prefs && prefs.theme) {
            document.documentElement.setAttribute('data-theme', prefs.theme);
        }
        if (prefs && prefs.density) {
            document.documentElement.setAttribute('data-density', prefs.density);
        }
        if (prefs && prefs.layout) {
            document.documentElement.setAttribute('data-layout', forceAppWindowLayout ? 'app' : prefs.layout);
        }
        if (prefs && prefs.bgPalette) {
            document.documentElement.setAttribute('data-bg-palette', prefs.bgPalette);
        }
        if (prefs && prefs.bgEffects) {
            document.documentElement.setAttribute('data-bg-effects', prefs.bgEffects);
        } else {
            document.documentElement.setAttribute('data-bg-effects', 'soft');
        }
        if (prefs && prefs.glassTone) {
            document.documentElement.setAttribute('data-glass-tone', prefs.glassTone);
        }
        if (prefs && prefs.accentColor) {
            applyAccentColor(prefs.accentColor);
        }
        if (prefs && prefs.bgTintColor) {
            applyBackgroundTint(prefs.bgTintColor);
        }
        applyFrostedCardTint(nextTheme, nextBgPalette, nextBgTint);
        applyReadableMode(nextTheme, nextBgPalette, nextBgTint);
        applyTeacherCardColors(prefs && prefs.teacherTagColor, prefs && prefs.schoolHeadColor);
    } catch (e) {}
})();
</script>

<!-- Inter Font (offline) -->
<link rel="stylesheet" href="<?= APP_URL ?>/assets/fonts/inter/inter.css">

<!-- Font Awesome 6 (offline) -->
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">

<!-- SweetAlert2 CSS (offline) -->
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/sweetalert2/sweetalert2.min.css">

<!-- Main stylesheet -->
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= urlencode($styleVersion) ?>">

<?php if (!empty($extraCss)): foreach ($extraCss as $css): ?>
<link rel="stylesheet" href="<?= clean($css) ?>">
<?php endforeach; endif; ?>
</head>
<body class="<?= $isAppWindow ? 'app-window-embed' : '' ?>">

<div class="ambient-glass-scene" aria-hidden="true">
    <span class="ambient-noise"></span>
    <span class="ambient-light ambient-light-1"></span>
    <span class="ambient-light ambient-light-2"></span>
    <span class="ambient-orb ambient-orb-1"></span>
    <span class="ambient-orb ambient-orb-2"></span>
    <span class="ambient-orb ambient-orb-3"></span>
    <span class="ambient-orb ambient-orb-4"></span>
    <span class="ambient-orb ambient-orb-5"></span>
    <span class="ambient-orb ambient-orb-6"></span>
    <span class="ambient-orb ambient-orb-7"></span>
    <span class="ambient-orb ambient-orb-8"></span>
    <span class="ambient-orb ambient-orb-9"></span>
    <span class="ambient-orb ambient-orb-10"></span>
</div>

<script>
(function globalMessageNotifier() {
    var currentPage = <?= json_encode($currentPage) ?>;
    var notificationsUrl = <?= json_encode(APP_URL . '/actions/message_notifications.php') ?>;
    var chatUrl = <?= json_encode(APP_URL . '/chat.php') ?>;
    var pollIntervalMs = 4000;
    var isPolling = false;

    function injectNotificationStyles() {
        if (document.getElementById('tpmsGlobalNotifierStyles')) {
            return;
        }

        var style = document.createElement('style');
        style.id = 'tpmsGlobalNotifierStyles';
        style.textContent = ''
            + '.tpms-msg-toast.swal2-popup{'
            + 'border:1px solid rgba(56,189,248,.35);'
            + 'border-radius:16px;'
            + 'backdrop-filter:blur(14px) saturate(125%);'
            + '-webkit-backdrop-filter:blur(14px) saturate(125%);'
            + 'background:linear-gradient(150deg, rgba(8,47,73,.86), rgba(15,23,42,.88));'
            + 'box-shadow:0 14px 36px rgba(2,6,23,.38), inset 0 1px 0 rgba(255,255,255,.14);'
            + 'color:#e2e8f0;'
            + 'padding:12px 14px 12px 12px;'
            + '}'
            + '.tpms-msg-toast .swal2-title{font-size:.95rem;font-weight:800;letter-spacing:.01em;color:#f8fafc;margin:0 0 4px;}'
            + '.tpms-msg-toast .swal2-html-container{margin:0;font-size:.8rem;line-height:1.45;color:#cbd5e1;}'
            + '.tpms-msg-toast .swal2-actions{margin-top:10px;gap:8px;}'
            + '.tpms-msg-toast .swal2-confirm{background:linear-gradient(135deg,#0ea5e9,#22c55e) !important;border:0 !important;box-shadow:0 8px 20px rgba(14,165,233,.28);font-weight:700;}'
            + '.tpms-msg-toast .swal2-cancel{background:rgba(148,163,184,.16) !important;color:#e2e8f0 !important;border:1px solid rgba(148,163,184,.28) !important;font-weight:700;}'
            + '.tpms-msg-toast .swal2-timer-progress-bar{background:linear-gradient(90deg, rgba(56,189,248,.9), rgba(34,197,94,.9));}'
            + '.tpms-chat-badge{margin-left:8px;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:linear-gradient(135deg,#ef4444,#f97316);color:#fff;font-size:.68rem;font-weight:800;display:inline-flex;align-items:center;justify-content:center;line-height:1;border:1px solid rgba(255,255,255,.35);box-shadow:0 0 0 0 rgba(239,68,68,.5);animation:tpmsChatPulse 1.8s infinite;}'
            + '@keyframes tpmsChatPulse{0%{box-shadow:0 0 0 0 rgba(239,68,68,.55)}70%{box-shadow:0 0 0 9px rgba(239,68,68,0)}100%{box-shadow:0 0 0 0 rgba(239,68,68,0)}}';
        document.head.appendChild(style);
    }

    function updateChatBadges(totalCount) {
        var anchors = document.querySelectorAll('a[href$="/chat"], a[href$="/chat.php"]');
        anchors.forEach(function(anchor) {
            var badge = anchor.querySelector('.tpms-chat-badge');
            if (totalCount > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'tpms-chat-badge';
                    anchor.appendChild(badge);
                }
                badge.textContent = totalCount > 99 ? '99+' : String(totalCount);
            } else if (badge) {
                badge.remove();
            }
        });
    }

    function canShowAlert() {
        return typeof Swal !== 'undefined' && currentPage !== 'chat';
    }

    function showMessageToast(payload) {
        if (!canShowAlert()) {
            return;
        }

        var directCount = Number(payload.unread_direct || 0);
        var groupCount = Number(payload.group_activity || 0);
        var totalCount = Number(payload.total_unread || 0);
        if (totalCount <= 0) {
            return;
        }

        var lines = [];
        if (directCount > 0) {
            lines.push(directCount + ' unread direct message' + (directCount !== 1 ? 's' : ''));
        }
        if (groupCount > 0) {
            lines.push(groupCount + ' active group chat' + (groupCount !== 1 ? 's' : ''));
        }

        Swal.fire({
            toast: true,
            position: 'bottom-end',
            icon: 'info',
            title: 'New Messages',
            html: lines.join('<br>'),
            customClass: {
                popup: 'tpms-msg-toast'
            },
            showConfirmButton: true,
            confirmButtonText: 'Open Chat',
            showCancelButton: true,
            cancelButtonText: 'Dismiss',
            timer: 10000,
            timerProgressBar: true,
            backdrop: false,
            allowOutsideClick: true,
            allowEscapeKey: true,
            returnFocus: false
        }).then(function(result) {
            if (result.isConfirmed) {
                window.location.href = chatUrl;
            }
        });
    }

    function handlePayload(payload) {
        if (!payload || payload.ok !== true) {
            return;
        }

        var total = Number(payload.total_unread || 0);
        var signature = String(payload.signature || '');
        var storageKey = 'tpms-global-message-alert-signature';
        var seenSignature = window.sessionStorage.getItem(storageKey) || '';

        updateChatBadges(total);

        var shouldNotify = total > 0 && signature !== '' && signature !== seenSignature;
        if (shouldNotify) {
            showMessageToast(payload);
            window.sessionStorage.setItem(storageKey, signature);
        }
    }

    function pollNow() {
        if (isPolling) {
            return;
        }
        isPolling = true;

        fetch(notificationsUrl + '?_=' + Date.now(), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function(response) {
            if (!response.ok) {
                throw new Error('Notification poll failed with status ' + response.status);
            }
            return response.json();
        }).then(function(data) {
            handlePayload(data);
        }).catch(function() {
            // Silent fail to avoid interrupting user workflow.
        }).finally(function() {
            isPolling = false;
        });
    }

    function startPolling() {
        injectNotificationStyles();
        pollNow();
        window.setInterval(function() {
            if (document.visibilityState === 'visible') {
                pollNow();
            }
        }, pollIntervalMs);
    }

    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            pollNow();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startPolling, { once: true });
    } else {
        startPolling();
    }
})();
</script>

<?php if (!$isAppWindow): ?>
<!-- Sidebar overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="tala-os-welcome" id="talaOsWelcome" aria-hidden="true">
    <div class="tala-os-welcome-panel">
        <div class="tala-os-welcome-orb">
            <img src="<?= APP_URL ?>/assets/images/logo.png" alt="Tala OS logo" class="tala-os-welcome-logo">
        </div>
        <div class="tala-os-welcome-kicker">Switching Workspace</div>
        <div class="tala-os-welcome-title">Welcome to Tala OS</div>
        <div class="tala-os-welcome-text">Preparing your desktop view...</div>
    </div>
</div>

<!-- App drawer overlay (app view) -->
<div class="app-drawer-backdrop" id="appDrawerBackdrop"></div>

<!-- ========================================================
     SIDEBAR
     ======================================================== -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><img src="<?= APP_URL ?>/assets/images/logo.png" alt="TalaGuro Logo" style="width:100%;height:100%;object-fit:contain;"></div>
        <div class="brand-text">
            <span class="brand-name">TalaGuro<br></span>
            <span class="brand-sub">Teacher Profiling</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($navLinks as $link): ?>
        <?php 
        // Skip link if it's hidden for current user's role
        if (!empty($link['hideForRoles'])) {
            $userRole = strtolower($currentUser['role'] ?? '');
            if (in_array($userRole, $link['hideForRoles'], true)) {
                continue;
            }
        }
        ?>
        <a href="<?= APP_URL ?>/<?= $link['href'] ?>"
              class="nav-item <?= $currentPage === $link['page'] ? 'active' : '' ?>"<?= $link['page'] === 'chatbot' ? ' data-tala-link="1"' : '' ?>
              data-app-window-link="1"
              data-app-window-title="<?= htmlspecialchars($link['label'], ENT_QUOTES) ?>"
              data-app-id="<?= htmlspecialchars($link['page'], ENT_QUOTES) ?>"
              data-app-icon="fas fa-<?= htmlspecialchars($link['icon'], ENT_QUOTES) ?>"
           data-label="<?= htmlspecialchars($link['label'], ENT_QUOTES) ?>"
           title="<?= htmlspecialchars($link['label'], ENT_QUOTES) ?>">
            <i class="fas fa-<?= $link['icon'] ?> nav-icon"></i>
            <span class="nav-label"><?= $link['label'] ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar">
                <?php if ($currentUserPhotoUrl !== ''): ?>
                <img src="<?= clean($currentUserPhotoUrl) ?>" alt="User photo" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                <?php else: ?>
                <?= strtoupper(substr($currentUser['full_name'], 0, 1)) ?>
                <?php endif; ?>
            </div>
            <div class="user-details">
                <span class="user-name"><?= clean($currentUser['full_name']) ?></span>
                <span class="user-role"><?= ucfirst(str_replace('_', ' ', $currentUser['role'])) ?></span>
                <?php if ($selectedDistrictName !== ''): ?>
                <span class="user-district" style="font-size: 0.75rem; color: var(--text-muted, #94a3b8); margin-top: 4px; display: block; font-weight: 500;">📍 <?= clean($selectedDistrictName) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</aside>

<!-- ========================================================
     APP DRAWER (IOS-LIKE)
     ======================================================== -->
<aside class="app-drawer" id="appDrawer" aria-hidden="true">
    <div class="app-drawer-head">
        <div class="app-drawer-title">Apps</div>
        <button type="button" class="app-drawer-close" id="appDrawerClose" aria-label="Close app drawer">
            <i class="fas fa-xmark"></i>
        </button>
    </div>
    <div class="app-drawer-grid">
        <?php foreach ($navLinks as $link): ?>
        <?php 
        // Skip link if it's hidden for current user's role
        if (!empty($link['hideForRoles'])) {
            $userRole = strtolower($currentUser['role'] ?? '');
            if (in_array($userRole, $link['hideForRoles'], true)) {
                continue;
            }
        }
        ?>
        <a href="<?= APP_URL ?>/<?= $link['href'] ?>"
              class="app-tile <?= $currentPage === $link['page'] ? 'active' : '' ?>"<?= $link['page'] === 'chatbot' ? ' data-tala-link="1"' : '' ?>
              data-app-window-link="1"
              data-app-window-title="<?= htmlspecialchars($link['label'], ENT_QUOTES) ?>"
              data-app-id="<?= htmlspecialchars($link['page'], ENT_QUOTES) ?>"
              data-app-icon="fas fa-<?= htmlspecialchars($link['icon'], ENT_QUOTES) ?>"
           title="<?= htmlspecialchars($link['label'], ENT_QUOTES) ?>">
            <span class="app-tile-icon"><i class="fas fa-<?= $link['icon'] ?>"></i></span>
            <span class="app-tile-label"><?= $link['label'] ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</aside>

<!-- ========================================================
     MAIN WRAPPER
     ======================================================== -->
<div class="main-wrapper">

    <!-- Top bar -->
    <header class="topbar">
        <div class="topbar-left">
            <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation" title="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <div class="topbar-title-group topbar-current-app" id="topbarCurrentApp">
                <div class="topbar-title" id="topbarCurrentAppTitle"><?= clean($pageTitle ?? APP_FULL_NAME) ?></div>
                <div class="topbar-subtitle" id="topbarCurrentAppSubtitle">Current app</div>
            </div>
            <a href="<?= APP_URL ?>/appearance.php" class="topbar-theme-settings topbar-appearance-left" title="Open appearance settings" data-app-window-link="1" data-app-window-title="Appearance" data-app-id="appearance" data-app-icon="fas fa-sliders">
                <i class="fas fa-sliders"></i>
                <span>Appearance</span>
            </a>
        </div>
        <div class="topbar-right">
            <div class="topbar-actions">
                <span class="date-display"><i class="fas fa-calendar-day"></i><span><?= date('F j, Y') ?></span></span>
                <button type="button" class="topbar-theme-btn" id="quickThemeBtn" title="Change theme">
                    <i class="fas fa-circle-half-stroke"></i>
                    <span>Theme</span>
                </button>
                <div class="topbar-user" title="<?= clean($currentUser['full_name']) ?>">
                    <div class="topbar-user-avatar">
                        <?php if ($currentUserPhotoUrl !== ''): ?>
                        <img src="<?= clean($currentUserPhotoUrl) ?>" alt="User photo" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                        <?php else: ?>
                        <?= strtoupper(substr($currentUser['full_name'], 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div class="topbar-user-meta">
                        <span class="topbar-user-name"><?= clean($currentUser['full_name']) ?></span>
                        <span class="topbar-user-role"><?= ucfirst(str_replace('_', ' ', $currentUser['role'])) ?></span>
                        <?php if ($selectedDistrictName !== ''): ?>
                        <span class="topbar-user-district" style="font-size: 0.65rem; color: var(--text-muted, #94a3b8); margin-top: 2px; display: block; font-weight: 500;">📍 <?= clean($selectedDistrictName) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="<?= APP_URL ?>/actions/logout" class="topbar-logout" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <?php 
    // District selector bar is hidden
    ?>

    <style>
    /* District selector styles - element hidden */
    .district-selector-bar {
        display: none;
    }
    </style>

    <nav class="app-dock" id="appDock" aria-label="Quick apps">
        <div class="app-dock-main" id="appDockMain">
            <button type="button" class="app-dock-item app-dock-drawer" id="dockDrawerBtn" title="Open app drawer">
                <span class="app-dock-icon"><i class="fas fa-grip"></i></span>
                <span class="app-dock-label">Apps</span>
            </button>
            <?php foreach ($dockLinks as $link): ?>
            <a href="<?= APP_URL ?>/<?= $link['href'] ?>"
                  class="app-dock-item <?= $currentPage === $link['page'] ? 'active' : '' ?>"<?= $link['page'] === 'chatbot' ? ' data-tala-link="1"' : '' ?>
                  data-app-window-link="1"
                  data-app-window-title="<?= htmlspecialchars($link['label'], ENT_QUOTES) ?>"
                  data-app-id="<?= htmlspecialchars($link['page'], ENT_QUOTES) ?>"
                  data-app-icon="fas fa-<?= htmlspecialchars($link['icon'], ENT_QUOTES) ?>"
               title="<?= htmlspecialchars($link['label'], ENT_QUOTES) ?>">
                <span class="app-dock-icon"><i class="fas fa-<?= $link['icon'] ?>"></i></span>
                <span class="app-dock-label"><?= $link['label'] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="app-dock-divider" id="appDockDivider" aria-hidden="true" hidden></div>
        <div class="app-dock-minimized" id="appDockMinimized" aria-label="Minimized apps" hidden></div>
    </nav>

    <div class="app-window-stage" id="appWindowStage" aria-live="polite"></div>
    <div class="app-window-minibar" id="appWindowMinibar" aria-label="Minimized windows"></div>
    <div class="app-window-empty" id="appWindowEmpty">
        <div class="app-window-empty-title">Desktop mode</div>
        <div class="app-window-empty-text">Open apps from the dock or sidebar and they will appear as separate windows.</div>
    </div>

    <!-- Page content starts here -->
    <main class="page-content">
<?php else: ?>
    <main class="page-content app-window-page-content">
<?php endif; ?>
