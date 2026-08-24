-- ============================================================
-- TPMS – Teacher Profiling Management System
-- Database Schema v1.2 (Normalized)
-- Run this in phpMyAdmin or MySQL CLI:
--   mysql -u root -p < database.sql
-- OR use setup.php for automatic setup with correct password hash.
-- ============================================================

CREATE DATABASE IF NOT EXISTS tpms
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE tpms;

-- ────────────────────────────────────────────────────────────
-- DISTRICTS (lookup / reference table)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS districts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    district_name   VARCHAR(100) NOT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_district_name (district_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- SCHOOLS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS schools (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_name     VARCHAR(255) NOT NULL,
    school_id_code  VARCHAR(50)  DEFAULT NULL,
    municipality    VARCHAR(100) DEFAULT NULL,
    -- Supported values: Public, Private, ALS, Elementary, JHS, SHS
    school_type     VARCHAR(100) DEFAULT NULL,
    als_subtype     VARCHAR(100) DEFAULT NULL,
    district_id     INT UNSIGNED DEFAULT NULL,
    school_year     VARCHAR(20)  DEFAULT NULL,
    learner_count   INT UNSIGNED DEFAULT 0,
    total_sections  INT UNSIGNED DEFAULT 0,
    total_required_classes INT UNSIGNED DEFAULT 0,
    hours_per_class_week DECIMAL(5,2) DEFAULT 5,
    learners_per_teacher INT UNSIGNED DEFAULT 35,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_school_district FOREIGN KEY (district_id)
        REFERENCES districts(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_school_code  (school_id_code),
    INDEX idx_municipality (municipality),
    INDEX idx_school_type  (school_type),
    INDEX idx_als_subtype  (als_subtype),
    INDEX idx_district_id  (district_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- TEACHERS (main personnel table)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS teachers (
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

    -- Employment
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

    -- Education
    highest_education           VARCHAR(100) DEFAULT NULL,
    field_of_study              VARCHAR(150) DEFAULT NULL,
    csee_eligibility            VARCHAR(150) DEFAULT NULL,

    -- Photo & Privacy
    profile_photo               VARCHAR(255) DEFAULT NULL,
    data_privacy_consent        VARCHAR(10)  DEFAULT 'No',

    -- Meta
    created_by                  INT UNSIGNED DEFAULT NULL,
    created_at                  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign keys
    CONSTRAINT fk_teacher_school FOREIGN KEY (school_id)
        REFERENCES schools(id) ON DELETE SET NULL ON UPDATE CASCADE,

    -- Indexes for common filters/searches
    INDEX idx_last_name       (last_name),
    INDEX idx_first_name      (first_name),
    INDEX idx_gender          (gender),
    INDEX idx_position        (position),
    INDEX idx_school_id       (school_id),
    INDEX idx_specialization  (specialization),
    INDEX idx_birthdate       (birthdate),
    INDEX idx_appointment_type(appointment_type),
    FULLTEXT idx_ft_name      (last_name, first_name, middle_name, specialization)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- USERS (system accounts)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(80)  UNIQUE NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(150) DEFAULT NULL,
    role            ENUM('admin','hr','school_head','viewer','psds','sdc','unit_head') DEFAULT NULL,
    district_id     INT UNSIGNED DEFAULT NULL,
    profile_photo   VARCHAR(255) DEFAULT NULL,
    preferred_theme VARCHAR(40)  DEFAULT NULL,
    preferred_layout VARCHAR(20) DEFAULT NULL,
    onboarding_completed_at DATETIME NULL DEFAULT NULL,
    preferred_appearance_json MEDIUMTEXT NULL DEFAULT NULL,
    is_active       TINYINT(1)   DEFAULT 1,
    twofa_enabled   TINYINT(1)   DEFAULT 0,
    twofa_secret    VARCHAR(64)  DEFAULT NULL,
    dashboard_tour_completed TINYINT(1) DEFAULT 0,
    last_login      TIMESTAMP    NULL DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_role      (role),
    INDEX idx_is_active (is_active),
    INDEX idx_district_id (district_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- UPLOAD LOGS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS upload_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_name       VARCHAR(255) NOT NULL,
    total_rows      INT UNSIGNED DEFAULT 0,
    imported_rows   INT UNSIGNED DEFAULT 0,
    skipped_rows    INT UNSIGNED DEFAULT 0,
    error_rows      INT UNSIGNED DEFAULT 0,
    uploaded_by     INT UNSIGNED DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_upload_user FOREIGN KEY (uploaded_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_upload_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- ACTIVITY LOGS
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS activity_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED DEFAULT NULL,
    user_name       VARCHAR(150) DEFAULT NULL,
    action          VARCHAR(50)  NOT NULL,
    module          VARCHAR(50)  NOT NULL,
    record_id       INT UNSIGNED DEFAULT NULL,
    description     TEXT         DEFAULT NULL,
    ip_address      VARCHAR(45)  DEFAULT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_user   (user_id),
    INDEX idx_log_module (module),
    INDEX idx_log_date   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ────────────────────────────────────────────────────────────
-- PLANNING SETTINGS (Teacher Requirement Planning)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS planning_settings (
    id                                  TINYINT UNSIGNED PRIMARY KEY,
    max_students_per_class              INT UNSIGNED NOT NULL DEFAULT 45,
    max_classes_per_teacher             INT UNSIGNED NOT NULL DEFAULT 6,
    max_teaching_load_hours             DECIMAL(5,2) NOT NULL DEFAULT 30,
    recommended_student_teacher_ratio   INT UNSIGNED NOT NULL DEFAULT 35,
    utilization_threshold_pct           DECIMAL(5,2) NOT NULL DEFAULT 90,
    default_hours_per_class_week        DECIMAL(5,2) NOT NULL DEFAULT 5,
    created_at                          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at                          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO planning_settings
    (id, max_students_per_class, max_classes_per_teacher, max_teaching_load_hours, recommended_student_teacher_ratio, utilization_threshold_pct, default_hours_per_class_week)
VALUES
    (1, 45, 6, 30, 35, 90, 5);
-- ────────────────────────────────────────────────────────────
-- AUTH LOGIN ATTEMPTS (brute-force mitigation)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS auth_login_attempts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(80) DEFAULT NULL,
    ip_address      VARCHAR(45) NOT NULL,
    attempted_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempted_at        (attempted_at),
    INDEX idx_username_attempted  (username, attempted_at),
    INDEX idx_ip_attempted        (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────
-- SEED: Default Admin User
-- Username: admin   Password: Admin@2024  (change after first login!)
-- Hash generated with: password_hash('Admin@2024', PASSWORD_BCRYPT, ['cost'=>12])
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO users (username, password_hash, full_name, role, is_active)
VALUES (
    'admin',
    '$2y$12$QItRSj7Da/1JnEDpEPmoaOQ6PqAPVSfFbY0hnwiQx1P18XiH2jBgS',
    'System Administrator',
    'admin',
    1
);

-- ────────────────────────────────────────────────────────────
-- SEED: Sample Districts
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO districts (id, district_name) VALUES
(1, 'District I'),
(2, 'District II'),
(3, 'District III');

-- ────────────────────────────────────────────────────────────
-- SEED: Sample Schools
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO schools (school_name, school_id_code, municipality, school_type, als_subtype, district_id) VALUES
('Sample National High School',        '300001', 'Baler', 'Public',      NULL,      1),
('Barangay Elementary School',         '300002', 'Baler', 'Elementary',  NULL,      1),
('Sample Junior High School',          '300003', 'Maria Aurora', 'JHS',  NULL,      2),
('Sample Senior High School',          '300004', 'Dipaculao', 'SHS',     NULL,      2),
('Aurora Community Learning Center',   '300005', 'San Luis', 'ALS',      'CBLC',    3),
('Aurora School-Based Learning Center','300006', 'Dilasag', 'ALS',       'SBLC',    3),
('Aurora ALS Senior High',             '300007', 'Casiguran', 'ALS',     'ALS-SHS', 3);

-- ────────────────────────────────────────────────────────────
-- SEED: Sample Teachers (demonstation only)
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO teachers
    (employee_number, last_name, first_name, middle_name, birthdate,
     gender, position, appointment_type, school_id, grade_level,
     specialization, highest_education, data_privacy_consent)
VALUES
    ('EMP001','dela Cruz','Maria','Santos','1985-03-15',
     'Female','Teacher I','Permanent',1,'Grade 7-10','Mathematics',"Bachelor's Degree",'Yes'),
    ('EMP002','Reyes','Jose','Andres','1978-07-22',
     'Male','Teacher III','Permanent',1,'Grade 11-12','English',"Master's Degree",'Yes'),
    ('EMP003','Santos','Ana','Lim','1990-11-05',
     'Female','Teacher II','Permanent',2,'Grade 1-3','Science',"Bachelor's Degree",'Yes'),
    ('EMP004','Bautista','Carlo','Ramos','1983-04-18',
     'Male','Master Teacher I','Permanent',3,'Grade 4-6','Filipino',"With Masteral Units",'Yes'),
    ('EMP005','Garcia','Luz','Torres','1995-09-30',
     'Female','Teacher I','Provisional',4,'Grade 7-8','TLE',"Bachelor's Degree",'Yes');
