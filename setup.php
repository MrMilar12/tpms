<?php
// ============================================================
// TPMS – One-Time Setup / Installer
// Run once: http://localhost/tpms/setup.php
// DELETE this file after setup is complete.
// ============================================================

require_once __DIR__ . '/config.php';
// NOTE: we do NOT call getDB() here because the database may not exist yet.
// setup.php uses its own bootstrap connection (no dbname) to create it first.

/**
 * Open a PDO connection without specifying a database name so we can
 * CREATE DATABASE IF NOT EXISTS before anything else.
 */
function setupBootstrapDB(): PDO {
    $dsn = sprintf('mysql:host=%s;charset=%s', DB_HOST, DB_CHARSET);
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);
}

function getSetupLockFilePath(): string {
    return BASE_PATH . '/.setup.lock';
}

// ── Check if already set up (admin table + user exists) ─────
$alreadySetUp = false;
$setupLocked = is_file(getSetupLockFilePath());
try {
    $testPdo = setupBootstrapDB();
    $dbs = $testPdo->query("SHOW DATABASES LIKE '" . DB_NAME . "'")->fetchAll();
    if ($dbs) {
        $testPdo->exec("USE `" . DB_NAME . "`");
        $tbl = $testPdo->query("SHOW TABLES LIKE 'users'")->fetch();
        if ($tbl) {
            $cnt = $testPdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
            $alreadySetUp = (int)$cnt > 0;
        }
    }
    unset($testPdo);
} catch (PDOException $e) {
    error_log('TPMS setup connection error: ' . $e->getMessage());
    $connectError = 'Cannot connect to MySQL server. Check credentials and service status, then try again.';
}

if ($alreadySetUp || $setupLocked) {
    $setupLocked = true;
}

// ── Schema definitions ───────────────────────────────────────
$statements = [

    // 1. Districts (must be before schools for FK)
    "CREATE TABLE IF NOT EXISTS districts (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        district_name VARCHAR(100) NOT NULL,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_district_name (district_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 2. Schools
    "CREATE TABLE IF NOT EXISTS schools (
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        school_name    VARCHAR(255) NOT NULL,
        school_id_code VARCHAR(50)  DEFAULT NULL,
        municipality   VARCHAR(100) DEFAULT NULL,
        -- Supported values: Public, Private, ALS, Elementary, JHS, SHS
        school_type    VARCHAR(100) DEFAULT NULL,
        als_subtype    VARCHAR(100) DEFAULT NULL,
        district_id    INT UNSIGNED DEFAULT NULL,
        school_year    VARCHAR(20)  DEFAULT NULL,
        learner_count  INT UNSIGNED DEFAULT 0,
        total_sections INT UNSIGNED DEFAULT 0,
        total_required_classes INT UNSIGNED DEFAULT 0,
        hours_per_class_week DECIMAL(5,2) DEFAULT 5,
        learners_per_teacher INT UNSIGNED DEFAULT 35,
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_school_district FOREIGN KEY (district_id)
            REFERENCES districts(id) ON DELETE SET NULL ON UPDATE CASCADE,
        INDEX idx_school_code (school_id_code),
        INDEX idx_municipality (municipality),
        INDEX idx_school_type (school_type),
        INDEX idx_als_subtype (als_subtype),
        INDEX idx_district_id (district_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 3. Teachers
    "CREATE TABLE IF NOT EXISTS teachers (
        id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employee_number             VARCHAR(50)  UNIQUE NOT NULL,
        last_name                   VARCHAR(100) NOT NULL,
        first_name                  VARCHAR(100) NOT NULL,
        middle_name                 VARCHAR(100) DEFAULT NULL,
        extension_name              VARCHAR(20)  DEFAULT NULL,
        house_street                VARCHAR(255) DEFAULT NULL,
        barangay                    VARCHAR(100) DEFAULT NULL,
        municipality                VARCHAR(100) DEFAULT NULL,
        province                    VARCHAR(100) DEFAULT NULL,
        birthdate                   DATE         DEFAULT NULL,
        gender                      ENUM('Male','Female') DEFAULT NULL,
        civil_status                VARCHAR(30)  DEFAULT NULL,
        pwd_status                  VARCHAR(10)  DEFAULT 'No',
        contact_number              VARCHAR(30)  DEFAULT NULL,
        email_address               VARCHAR(150) DEFAULT NULL,
        position                    VARCHAR(100) DEFAULT NULL,
        item_number                 VARCHAR(50)  DEFAULT NULL,
        salary_grade                VARCHAR(20)  DEFAULT NULL,
        appointment_type            VARCHAR(50)  DEFAULT NULL,
        original_appointment_date   DATE         DEFAULT NULL,
        school_id                   INT UNSIGNED DEFAULT NULL,
        school_id_code_raw          VARCHAR(50)  DEFAULT NULL,
        school_name_raw             VARCHAR(255) DEFAULT NULL,
        district_raw                VARCHAR(100) DEFAULT NULL,
        plantilla_station           VARCHAR(255) DEFAULT NULL,
        current_station             VARCHAR(255) DEFAULT NULL,
        grade_level                 VARCHAR(255) DEFAULT NULL,
        max_teaching_load_hours     DECIMAL(5,2) DEFAULT NULL,
        current_teaching_load_hours DECIMAL(5,2) DEFAULT 0,
        classes_handled             INT UNSIGNED DEFAULT 0,
        students_handled            INT UNSIGNED DEFAULT 0,
        max_classes                 INT UNSIGNED DEFAULT NULL,
        advisory_class              VARCHAR(120) DEFAULT NULL,
        specialization              VARCHAR(150) DEFAULT NULL,
        subjects                    TEXT         DEFAULT NULL,
        highest_education           VARCHAR(100) DEFAULT NULL,
        field_of_study              VARCHAR(150) DEFAULT NULL,
        csee_eligibility            VARCHAR(150) DEFAULT NULL,
        profile_photo               VARCHAR(255) DEFAULT NULL,
        data_privacy_consent        VARCHAR(10)  DEFAULT 'No',
        created_by                  INT UNSIGNED DEFAULT NULL,
        created_at                  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at                  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_teacher_school FOREIGN KEY (school_id)
            REFERENCES schools(id) ON DELETE SET NULL ON UPDATE CASCADE,
        INDEX idx_last_name        (last_name),
        INDEX idx_first_name       (first_name),
        INDEX idx_gender           (gender),
        INDEX idx_position         (position),
        INDEX idx_school_id        (school_id),
        INDEX idx_specialization   (specialization),
        INDEX idx_birthdate        (birthdate),
        INDEX idx_appointment_type (appointment_type),
        FULLTEXT idx_ft_name       (last_name, first_name, middle_name, specialization)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 4. Users
    "CREATE TABLE IF NOT EXISTS users (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username      VARCHAR(80)  UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        full_name     VARCHAR(150) NOT NULL,
        email         VARCHAR(150) DEFAULT NULL,
        role          ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head') DEFAULT NULL,
        district_id   INT UNSIGNED DEFAULT NULL,
        profile_photo VARCHAR(255) DEFAULT NULL,
        preferred_theme VARCHAR(40) DEFAULT NULL,
        preferred_layout VARCHAR(20) DEFAULT NULL,
        onboarding_completed_at DATETIME NULL DEFAULT NULL,
        preferred_appearance_json MEDIUMTEXT NULL DEFAULT NULL,
        is_active     TINYINT(1)   DEFAULT 1,
        twofa_enabled TINYINT(1)   DEFAULT 0,
        twofa_secret  VARCHAR(64)  DEFAULT NULL,
        dashboard_tour_completed TINYINT(1) DEFAULT 0,
        last_login    TIMESTAMP    NULL DEFAULT NULL,
        created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_role      (role),
        INDEX idx_is_active (is_active),
        INDEX idx_district_id (district_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 5. Upload logs
    "CREATE TABLE IF NOT EXISTS upload_logs (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        file_name     VARCHAR(255) NOT NULL,
        total_rows    INT UNSIGNED DEFAULT 0,
        imported_rows INT UNSIGNED DEFAULT 0,
        skipped_rows  INT UNSIGNED DEFAULT 0,
        error_rows    INT UNSIGNED DEFAULT 0,
        uploaded_by   INT UNSIGNED DEFAULT NULL,
        created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_upload_user FOREIGN KEY (uploaded_by)
            REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
        INDEX idx_upload_date (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 6. Activity logs
    "CREATE TABLE IF NOT EXISTS activity_logs (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED DEFAULT NULL,
        user_name   VARCHAR(150) DEFAULT NULL,
        action      VARCHAR(50)  NOT NULL,
        module      VARCHAR(50)  NOT NULL,
        record_id   INT UNSIGNED DEFAULT NULL,
        description TEXT         DEFAULT NULL,
        ip_address  VARCHAR(45)  DEFAULT NULL,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_log_user   (user_id),
        INDEX idx_log_module (module),
        INDEX idx_log_date   (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS planning_settings (
        id                                TINYINT UNSIGNED PRIMARY KEY,
        max_students_per_class            INT UNSIGNED NOT NULL DEFAULT 45,
        max_classes_per_teacher           INT UNSIGNED NOT NULL DEFAULT 6,
        max_teaching_load_hours           DECIMAL(5,2) NOT NULL DEFAULT 30,
        recommended_student_teacher_ratio INT UNSIGNED NOT NULL DEFAULT 35,
        utilization_threshold_pct         DECIMAL(5,2) NOT NULL DEFAULT 90,
        default_hours_per_class_week      DECIMAL(5,2) NOT NULL DEFAULT 5,
        created_at                        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at                        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 7. Login attempts (for brute-force mitigation)
    "CREATE TABLE IF NOT EXISTS auth_login_attempts (
        id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username             VARCHAR(80) DEFAULT NULL,
        ip_address           VARCHAR(45) NOT NULL,
        attempted_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_attempted_at       (attempted_at),
        INDEX idx_username_attempted (username, attempted_at),
        INDEX idx_ip_attempted       (ip_address, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

// ── Seed data ────────────────────────────────────────────────
$seeds = [
    "INSERT IGNORE INTO districts (id, district_name) VALUES (1,'District I'),(2,'District II'),(3,'District III')",
    "INSERT IGNORE INTO planning_settings (id, max_students_per_class, max_classes_per_teacher, max_teaching_load_hours, recommended_student_teacher_ratio, utilization_threshold_pct, default_hours_per_class_week)
        VALUES (1, 45, 6, 30, 35, 90, 5)",
    "INSERT IGNORE INTO schools (school_name, school_id_code, municipality, school_type, als_subtype, district_id) VALUES
        ('Sample National High School',         '300001', 'Baler', 'Public',      NULL,      1),
        ('Barangay Elementary School',          '300002', 'Baler', 'Elementary',  NULL,      1),
        ('Sample Junior High School',           '300003', 'Maria Aurora', 'JHS',  NULL,      2),
        ('Sample Senior High School',           '300004', 'Dipaculao', 'SHS',     NULL,      2),
        ('Aurora Community Learning Center',    '300005', 'San Luis', 'ALS',      'CBLC',    3),
        ('Aurora School-Based Learning Center', '300006', 'Dilasag', 'ALS',       'SBLC',    3),
        ('Aurora ALS Senior High',              '300007', 'Casiguran', 'ALS',     'ALS-SHS', 3)",
];

// ── POST: run setup ──────────────────────────────────────────
$log      = [];
$errors   = [];
$done     = false;

if (isset($connectError)) {
    $errors[] = $connectError;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$setupLocked) {
    $adminUser  = trim($_POST['admin_user']     ?? 'admin');
    $adminName  = trim($_POST['admin_name']     ?? 'System Administrator');
    $adminPass  = $_POST['admin_password']       ?? '';
    $adminPass2 = $_POST['admin_password2']      ?? '';

    if ($adminUser  === '')         $errors[] = 'Admin username is required.';
    if ($adminName  === '')         $errors[] = 'Admin full name is required.';
    if (strlen($adminPass) < 8)    $errors[] = 'Password must be at least 8 characters.';
    if ($adminPass !== $adminPass2) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        try {
            $db = setupBootstrapDB();

            // 1. Create database
            $db->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $db->exec("USE `" . DB_NAME . "`");
            $log[] = ['ok', 'Database `' . DB_NAME . '` ready.'];

            // 2. Tables
            foreach ($statements as $sql) {
                $db->exec($sql);
                $log[] = ['ok', 'Table created/verified.'];
            }

            // 3. Seeds
            foreach ($seeds as $sql) {
                try {
                    $db->exec($sql);
                    $log[] = ['ok', 'Seed data inserted.'];
                } catch (PDOException $e) {
                    $log[] = ['warn', 'Seed note: ' . $e->getMessage()];
                }
            }

            // 4. Admin user
            $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
            $stmt->execute([$adminUser]);
            if ($stmt->fetch()) {
                $db->prepare('UPDATE users SET password_hash=?, full_name=?, role="admin", is_active=1 WHERE username=?')
                   ->execute([$hash, $adminName, $adminUser]);
                $log[] = ['ok', "Admin user '$adminUser' updated."];
            } else {
                $db->prepare('INSERT INTO users (username, password_hash, full_name, role, is_active) VALUES (?,?,?,?,1)')
                   ->execute([$adminUser, $hash, $adminName, 'admin']);
                $log[] = ['ok', "Admin user '$adminUser' created."];
            }

            @file_put_contents(getSetupLockFilePath(), "Setup completed on " . date('c') . PHP_EOL, FILE_APPEND);
            $done = true;

        } catch (PDOException $e) {
            error_log('TPMS setup error: ' . $e->getMessage());
            $errors[] = 'Setup failed due to a database operation error. Check server logs for details.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TPMS – Setup</title>
<link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/images/logo.png">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/fonts/inter/inter.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="login-body">
<div class="login-bg">
    <div class="login-orb orb1"></div>
    <div class="login-orb orb2"></div>
    <div class="login-orb orb3"></div>
</div>
<div class="login-wrapper">
    <div class="login-card glass-card" style="max-width:500px">
        <div class="login-brand">
            <div class="login-logo"><img src="<?= APP_URL ?>/assets/images/logo.png" alt="TalaGuro Logo" style="width:100%;height:100%;object-fit:contain;"></div>
            <h1 class="login-title">TPMS Setup</h1>
            <p class="login-subtitle">Database initialization &amp; admin account</p>
        </div>

        <?php if ($done): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> Setup complete! Please delete <code>setup.php</code> now.
        </div>
        <div style="margin:16px 0;font-size:13px;color:var(--text-muted)">
            <?php foreach ($log as [$t, $m]): ?>
            <div style="padding:4px 0"><i class="fas fa-<?= $t === 'ok' ? 'check' : 'exclamation' ?>" style="color:<?= $t === 'ok' ? '#34d399' : '#fbbf24' ?>"></i> <?= htmlspecialchars($m) ?></div>
            <?php endforeach; ?>
        </div>
        <a href="<?= APP_URL ?>/login.php" class="btn btn-primary btn-full">
            <i class="fas fa-sign-in-alt"></i> Go to Login
        </a>

        <?php elseif ($setupLocked): ?>
        <div class="alert alert-warning">
            <i class="fas fa-shield-halved"></i>
            Setup is locked because installation was already completed.
        </div>
        <div style="margin-top:16px;font-size:13px;color:var(--text-muted);text-align:center">
            Delete setup.php from your server to reduce attack surface.
        </div>
        <div style="margin-top:16px">
            <a href="<?= APP_URL ?>/login.php" class="btn btn-primary btn-full">
                <i class="fas fa-sign-in-alt"></i> Go to Login
            </a>
        </div>

        <?php else: ?>

        <?php if ($errors): ?>
        <div class="alert alert-error" style="margin-bottom:16px">
            <ul style="margin:0;padding-left:18px">
                <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="alert alert-warning" style="margin-bottom:16px;font-size:12px">
            <i class="fas fa-exclamation-triangle"></i>
            This installer creates the database, all tables, and the admin user.
            <strong>Delete setup.php after use.</strong>
        </div>

        <form method="POST" class="login-form" style="gap:14px">
            <div class="form-group">
                <label class="form-label">Admin Username</label>
                <div class="login-field">
                    <i class="fas fa-user login-field-icon"></i>
                    <input type="text" name="admin_user" class="form-input login-field-input"
                           value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>"
                           autocomplete="username" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Admin Full Name</label>
                <div class="login-field">
                    <i class="fas fa-id-card login-field-icon"></i>
                    <input type="text" name="admin_name" class="form-input login-field-input"
                           value="<?= htmlspecialchars($_POST['admin_name'] ?? 'System Administrator') ?>"
                           autocomplete="name" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Password <small style="color:var(--text-sub)">(min 8 chars)</small></label>
                <div class="login-field input-with-toggle">
                    <i class="fas fa-lock login-field-icon"></i>
                    <input type="password" name="admin_password" id="pw1"
                           class="form-input login-field-input"
                           autocomplete="new-password" required>
                    <button type="button" class="toggle-password" data-target="pw1" title="Show / hide">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <div class="login-field input-with-toggle">
                    <i class="fas fa-lock login-field-icon"></i>
                    <input type="password" name="admin_password2" id="pw2"
                           class="form-input login-field-input"
                           autocomplete="new-password" required>
                    <button type="button" class="toggle-password" data-target="pw2" title="Show / hide">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-full btn-lg">
                <i class="fas fa-rocket"></i> Run Setup
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js"></script>
</body>
</html>
