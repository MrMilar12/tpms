<?php
// ============================================================
// Authentication & Session Management
// ============================================================

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443')
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        );

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_set_cookie_params([
            'lifetime' => SESSION_TIMEOUT,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $isHttps,
            'samesite' => 'Strict'
        ]);
        session_start();
    }
}

function sendSecurityHeaders(): void {
    if (headers_sent()) return;
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function getClientIpAddress(): string {
    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $candidate = trim((string)$parts[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    $realIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP)) {
        return $realIp;
    }

    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $remote !== '' ? $remote : '0.0.0.0';
}

function ensureAuthAttemptTable(PDO $db): void {
    static $checked = false;
    if ($checked) return;

    $db->exec("CREATE TABLE IF NOT EXISTS auth_login_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) DEFAULT NULL,
        ip_address VARCHAR(45) NOT NULL,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_attempted_at (attempted_at),
        INDEX idx_username_attempted (username, attempted_at),
        INDEX idx_ip_attempted (ip_address, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $checked = true;
}

function canAttemptLogin(?string $username = null): bool {
    $windowSec = 900; // 15 minutes
    $maxByIp = 5;     // 5 attempts per IP per 15 minutes (stricter)
    $maxByUser = 3;   // 3 attempts per username per 15 minutes (stricter)

    $db = getDB();
    ensureAuthAttemptTable($db);

    $ip = getClientIpAddress();
    $normalizedUser = trim((string)$username);
    $cutoff = date('Y-m-d H:i:s', time() - $windowSec);

    $ipStmt = $db->prepare('SELECT COUNT(*) FROM auth_login_attempts WHERE ip_address = ? AND attempted_at >= ?');
    $ipStmt->execute([$ip, $cutoff]);
    $ipFails = (int)$ipStmt->fetchColumn();
    if ($ipFails >= $maxByIp) {
        return false;
    }

    if ($normalizedUser !== '') {
        $userStmt = $db->prepare('SELECT COUNT(*) FROM auth_login_attempts WHERE username = ? AND attempted_at >= ?');
        $userStmt->execute([$normalizedUser, $cutoff]);
        $userFails = (int)$userStmt->fetchColumn();
        if ($userFails >= $maxByUser) {
            return false;
        }
    }

    return true;
}

function recordFailedLoginAttempt(?string $username = null): void {
    $db = getDB();
    ensureAuthAttemptTable($db);

    $ip = getClientIpAddress();
    $normalizedUser = trim((string)$username);
    $normalizedUser = $normalizedUser !== '' ? $normalizedUser : null;

    $db->prepare('INSERT INTO auth_login_attempts (username, ip_address) VALUES (?, ?)')
        ->execute([$normalizedUser, $ip]);

    // Keep table compact by pruning old records.
    $db->exec('DELETE FROM auth_login_attempts WHERE attempted_at < (NOW() - INTERVAL 2 DAY)');
}

function clearFailedLoginAttempts(?string $username = null): void {
    $db = getDB();
    ensureAuthAttemptTable($db);

    $ip = getClientIpAddress();
    $normalizedUser = trim((string)$username);
    if ($normalizedUser !== '') {
        $db->prepare('DELETE FROM auth_login_attempts WHERE (username = ? OR ip_address = ?)')
            ->execute([$normalizedUser, $ip]);
        return;
    }

    $db->prepare('DELETE FROM auth_login_attempts WHERE ip_address = ?')->execute([$ip]);
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && array_key_exists('role', $_SESSION);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/login');
        exit();
    }
    // Timeout check
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        try {
            logActivity('TIMEOUT', 'auth', (int)($_SESSION['user_id'] ?? 0), 'Session expired due to inactivity.');
        } catch (Throwable) {}
        session_unset();
        session_destroy();
        header('Location: ' . APP_URL . '/login?msg=timeout');
        exit();
    }
    $_SESSION['last_activity'] = time();
}

function requireRole(array $roles): void {
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        try {
            logActivity(
                'DENY',
                'auth',
                (int)($_SESSION['user_id'] ?? 0),
                'Access denied. Required roles: ' . implode(', ', $roles) . '; current role: ' . (string)($_SESSION['role'] ?? 'unknown')
            );
        } catch (Throwable) {}
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Access denied. Insufficient permissions.'];
        redirect(APP_URL . '/dashboard');
    }
}

function ensureTwoFactorColumns(PDO $db): void {
    static $checked = false;
    if ($checked) return;

    $cols = [];
    foreach ($db->query('SHOW COLUMNS FROM users')->fetchAll() as $c) {
        $cols[] = $c['Field'];
    }

    if (!in_array('twofa_enabled', $cols, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN twofa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');
    }
    if (!in_array('twofa_secret', $cols, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN twofa_secret VARCHAR(64) DEFAULT NULL AFTER twofa_enabled');
    }

    $checked = true;
}

function ensureUserProfilePhotoColumn(PDO $db): void {
    static $checked = false;
    if ($checked) return;

    $cols = [];
    foreach ($db->query('SHOW COLUMNS FROM users')->fetchAll() as $c) {
        $cols[] = $c['Field'];
    }

    if (!in_array('profile_photo', $cols, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL AFTER role');
    }

    $checked = true;
}

function ensureUserOnboardingColumns(PDO $db): void {
    static $checked = false;
    if ($checked) return;

    $cols = [];
    foreach ($db->query('SHOW COLUMNS FROM users')->fetchAll() as $c) {
        $cols[] = $c['Field'];
    }

    // District-scoped roles read this during finalizeLogin(). Older TPMS
    // databases can already contain PSDS/SDC roles without this newer column,
    // which otherwise turns a successful login into an HTTP 500 response.
    if (!in_array('district_id', $cols, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN district_id INT UNSIGNED DEFAULT NULL AFTER role');
    }
    if (!in_array('preferred_theme', $cols, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN preferred_theme VARCHAR(40) DEFAULT NULL AFTER profile_photo');
    }
    if (!in_array('preferred_layout', $cols, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN preferred_layout VARCHAR(20) DEFAULT NULL AFTER preferred_theme');
    }
    if (!in_array('onboarding_completed_at', $cols, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN onboarding_completed_at DATETIME NULL DEFAULT NULL AFTER preferred_layout');
    }
    if (!in_array('preferred_appearance_json', $cols, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN preferred_appearance_json MEDIUMTEXT NULL DEFAULT NULL AFTER onboarding_completed_at');
    }
    if (!in_array('twofa_enabled', $cols, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN twofa_enabled TINYINT(1) DEFAULT 0 AFTER preferred_appearance_json');
    }
    if (!in_array('twofa_secret', $cols, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN twofa_secret VARCHAR(64) DEFAULT NULL AFTER twofa_enabled');
    }
    if (!in_array('terms_accepted_at', $cols, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN terms_accepted_at DATETIME NULL DEFAULT NULL AFTER twofa_secret');
    }

    $checked = true;
}

function needsOnboarding(): bool {
    return (bool)($_SESSION['needs_onboarding'] ?? false);
}

function base32Decode(string $input): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $clean = strtoupper(preg_replace('/[^A-Z2-7]/', '', $input));
    if ($clean === '') return '';

    $bits = '';
    $len = strlen($clean);
    for ($i = 0; $i < $len; $i++) {
        $val = strpos($alphabet, $clean[$i]);
        if ($val === false) return '';
        $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
    }

    $out = '';
    $bitLen = strlen($bits);
    for ($i = 0; $i + 8 <= $bitLen; $i += 8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

function generateTotpSecret(int $length = 32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $max = strlen($alphabet) - 1;
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[random_int(0, $max)];
    }
    return $secret;
}

function normalizeTotpCode(string $code): string {
    return preg_replace('/\D+/', '', trim($code));
}

function getTotpCode(string $base32Secret, ?int $timeSlice = null): string {
    $timeSlice = $timeSlice ?? (int)floor(time() / 30);
    $secret = base32Decode($base32Secret);
    if ($secret === '') return '';

    $binaryTime = pack('N*', 0, $timeSlice);
    $hash = hash_hmac('sha1', $binaryTime, $secret, true);
    $offset = ord($hash[19]) & 0x0F;
    $truncated = (
        ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF)
    );

    $code = $truncated % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

function verifyTotpCode(string $base32Secret, string $code, int $window = 1): bool {
    $normalized = normalizeTotpCode($code);
    error_log("TOTP Verification: Normalized code = '$normalized'");
    
    if (!preg_match('/^\d{6}$/', $normalized)) {
        error_log("TOTP Verification: Code format invalid (not 6 digits)");
        return false;
    }

    $slice = (int)floor(time() / 30);
    error_log("TOTP Verification: Current time slice = $slice");
    
    for ($i = -$window; $i <= $window; $i++) {
        $expected = getTotpCode($base32Secret, $slice + $i);
        error_log("TOTP Verification: Slice offset $i, expected code = '$expected'");
        
        if ($expected !== '' && hash_equals($expected, $normalized)) {
            error_log("TOTP Verification: Code VALID ✓");
            return true;
        }
    }
    error_log("TOTP Verification: Code INVALID ✗");
    return false;
}

function buildTotpUri(string $username, string $secret): string {
    $issuer = APP_NAME;
    $label = rawurlencode($issuer . ':' . $username);
    return 'otpauth://totp/' . $label
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&digits=6&period=30';
}

function authenticateCredentials(string $username, string $password): array|false {
    $db = getDB();
    ensureTwoFactorColumns($db);
    ensureUserProfilePhotoColumn($db);
    ensureUserOnboardingColumns($db);

    $stmt = $db->prepare(
        'SELECT id, username, password_hash, full_name, role, profile_photo, is_active,
                COALESCE(twofa_enabled, 0) AS twofa_enabled, twofa_secret
         FROM users WHERE username = ? LIMIT 1'
    );
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
        try {
            logActivity('LOGIN_FAIL', 'auth', null, 'Failed login attempt for username: ' . trim($username));
        } catch (Throwable) {}
        usleep(random_int(250000, 500000));
        return false;
    }

    return $user;
}

function finalizeLogin(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']       = (int)$user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['full_name']     = $user['full_name'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['profile_photo'] = (string)($user['profile_photo'] ?? '');
    $_SESSION['last_activity'] = time();
    unset($_SESSION['pending_2fa']);

    $db = getDB();
    ensureUserOnboardingColumns($db);
    $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([(int)$user['id']]);

    $prefStmt = $db->prepare(
        'SELECT preferred_theme, preferred_layout, onboarding_completed_at, preferred_appearance_json, twofa_enabled, district_id
         FROM users WHERE id = ? LIMIT 1'
    );
    $prefStmt->execute([(int)$user['id']]);
    $pref = $prefStmt->fetch() ?: [];

    $_SESSION['preferred_theme'] = trim((string)($pref['preferred_theme'] ?? ''));
    $_SESSION['preferred_layout'] = trim((string)($pref['preferred_layout'] ?? ''));
    $_SESSION['preferred_appearance_json'] = trim((string)($pref['preferred_appearance_json'] ?? ''));
    
    // Set district from users table (assigned district)
    $_SESSION['selected_district_id'] = (int)($pref['district_id'] ?? 0) > 0 ? (int)$pref['district_id'] : null;
    
    // User needs onboarding if:
    // 1. onboarding_completed_at is NULL/empty, OR
    // 2. twofa_enabled is 0/false (2FA not activated)
    $_SESSION['twofa_enabled'] = (int)($pref['twofa_enabled'] ?? 0) === 1;
    $_SESSION['needs_onboarding'] = empty($pref['onboarding_completed_at']) || !$_SESSION['twofa_enabled'];

    try {
        logActivity('LOGIN', 'auth', (int)$user['id'], 'User logged in successfully.');
    } catch (Throwable) {}
}

function beginTwoFactorChallenge(array $user): void {
    $_SESSION['pending_2fa'] = [
        'uid' => (int)$user['id'],
        'username' => (string)$user['username'],
        'started_at' => time(),
    ];

    try {
        logActivity('2FA_CHALLENGE', 'auth', (int)$user['id'], 'Two-factor authentication challenge started.');
    } catch (Throwable) {}
}

function hasPendingTwoFactorChallenge(): bool {
    $pending = $_SESSION['pending_2fa'] ?? null;
    if (!is_array($pending)) return false;
    $started = (int)($pending['started_at'] ?? 0);
    if ($started <= 0 || (time() - $started) > 300) {
        unset($_SESSION['pending_2fa']);
        return false;
    }
    return (int)($pending['uid'] ?? 0) > 0;
}

function verifyTwoFactorLoginCode(string $code): bool {
    if (!hasPendingTwoFactorChallenge()) return false;

    $uid = (int)($_SESSION['pending_2fa']['uid'] ?? 0);
    if ($uid <= 0) return false;

    $db = getDB();
    ensureTwoFactorColumns($db);
    ensureUserProfilePhotoColumn($db);
    $stmt = $db->prepare(
        'SELECT id, username, full_name, role, profile_photo, is_active,
                COALESCE(twofa_enabled,0) AS twofa_enabled, twofa_secret
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    if (!$user || !$user['is_active']) return false;

    $enabled = (int)($user['twofa_enabled'] ?? 0) === 1;
    $secret  = trim((string)($user['twofa_secret'] ?? ''));
    if (!$enabled || $secret === '') return false;

    if (!verifyTotpCode($secret, $code)) {
        try {
            logActivity('2FA_FAIL', 'auth', (int)$uid, 'Invalid 2FA code submitted.');
        } catch (Throwable) {}
        return false;
    }

    finalizeLogin($user);
    try {
        logActivity('2FA_SUCCESS', 'auth', (int)$uid, 'Two-factor authentication verified successfully.');
    } catch (Throwable) {}
    return true;
}

function login(string $username, string $password): bool {
    $user = authenticateCredentials($username, $password);
    if ($user === false) return false;
    finalizeLogin($user);
    return true;
}

function logout(): void {
    try {
        if (isset($_SESSION['user_id'])) {
            logActivity('LOGOUT', 'auth', (int)$_SESSION['user_id'], 'User logged out.');
        }
    } catch (Throwable) {}

    session_unset();
    session_destroy();
    redirect(APP_URL . '/login');
}

function currentUser(): array {
    return [
        'id'        => $_SESSION['user_id']   ?? 0,
        'username'  => $_SESSION['username']  ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'profile_photo' => $_SESSION['profile_photo'] ?? '',
        'role'      => $_SESSION['role'] ?? null,  // Keep NULL roles as NULL, don't default to 'viewer'
        'preferred_theme' => $_SESSION['preferred_theme'] ?? '',
        'preferred_layout' => $_SESSION['preferred_layout'] ?? '',
        'preferred_appearance_json' => $_SESSION['preferred_appearance_json'] ?? '',
        'needs_onboarding' => (bool)($_SESSION['needs_onboarding'] ?? false),
        'twofa_enabled' => (bool)($_SESSION['twofa_enabled'] ?? false),
    ];
}

function canEdit(): bool {
    return in_array($_SESSION['role'] ?? '', ['admin', 'hr'], true);
}

function isAdmin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function csrfToken(): string {
    $now = time();
    $tokenExpiry = 300; // 5 minutes
    
    if (empty($_SESSION['csrf_token']) || 
        empty($_SESSION['csrf_token_time']) || 
        $now - $_SESSION['csrf_token_time'] > $tokenExpiry) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = $now;
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Session expired or invalid form token. Please try again.'];
        $fallback = APP_URL . '/dashboard';
        $target = $fallback;

        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            $app = parse_url(APP_URL);
            $ref = parse_url($referer);

            $appHost = strtolower((string)($app['host'] ?? ''));
            $appPort = (int)($app['port'] ?? 0);
            $appPath = rtrim((string)($app['path'] ?? ''), '/');

            $refHost = strtolower((string)($ref['host'] ?? ''));
            $refPort = (int)($ref['port'] ?? 0);
            $refPath = (string)($ref['path'] ?? '');

            $sameHost = ($refHost !== '' && $refHost === $appHost);
            $samePort = ($appPort === 0 || $refPort === 0 || $appPort === $refPort);
            $pathOk = ($appPath === '' || str_starts_with($refPath, $appPath . '/') || $refPath === $appPath);

            if ($sameHost && $samePort && $pathOk) {
                $target = $referer;
            }
        }

        redirect($target);
    }
}
