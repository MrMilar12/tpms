<?php
// ============================================================
// TPMS - Teacher Profiling Management System
// Configuration File
// ============================================================

// Database (can be overridden in hosting env: TPMS_DB_HOST/NAME/USER/PASS)
define('DB_HOST', getenv('TPMS_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('TPMS_DB_NAME') ?: 'tpms');
define('DB_USER', getenv('TPMS_DB_USER') ?: 'root');
define('DB_PASS', getenv('TPMS_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// define('DB_HOST', 'localhost');
// define('DB_NAME', 'depedaur_tpms');
// define('DB_USER', 'depedaur_tpms');
// define('DB_PASS', 'Planning@01');
// define('DB_CHARSET', 'utf8mb4');
// Application
define('APP_NAME', 'TPMS');
define('APP_FULL_NAME', 'Teacher Profiling Management System');
define('APP_VERSION', '1.10.0');
define('BASE_PATH', __DIR__);

// Build APP_URL dynamically so redirects work on localhost, LAN IPs, cPanel,
// and subfolder deployments without leaking filesystem paths.
$forcedAppUrl = trim((string)(getenv('TPMS_APP_URL') ?: ''));
$forcedAppUrlIsValid = $forcedAppUrl !== ''
    && preg_match('#^https?://#i', $forcedAppUrl)
    && !preg_match('#^https?://[^/]+/(?:home|public_html)(?:/|$)#i', $forcedAppUrl)
    && !preg_match('#^https?://[^/]+/.*/(?:home|public_html)(?:/|$)#i', $forcedAppUrl);

if ($forcedAppUrlIsValid) {
    $appUrl = rtrim($forcedAppUrl, '/');
} elseif (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
    $appUrl = 'http://localhost/tpms';
} else {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
    );

    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'];

    $appPath = '';
    $requestPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($requestPath !== '') {
        $requestPath = str_replace('\\', '/', $requestPath);

        // Ignore malformed filesystem-like paths such as /C:/xampp/htdocs/tpms/...
        // Also ignore Windows absolute paths
        if (!preg_match('#^/[A-Za-z]:/#', $requestPath) && !preg_match('#^[A-Za-z]:#', $requestPath)) {
            $requestDir = str_replace('\\', '/', dirname($requestPath));
            $requestDir = rtrim($requestDir, '/');
            // Skip if in a known subdirectory (actions, assets, includes, uploads)
            if ($requestDir !== '/' && $requestDir !== '.' && !preg_match('#^[A-Za-z]:#', $requestDir) && !preg_match('#/(actions|assets|includes|uploads)$#', $requestDir)) {
                $appPath = $requestDir;
            }
        }
    }

    if ($appPath === '') {
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptName !== '' && !preg_match('#^(?:[A-Za-z]:|/)#', $scriptName)) {
            $scriptName = '';
        }

        // Further check: reject SCRIPT_NAME if it contains Windows paths
        if ($scriptName !== '' && preg_match('#[A-Za-z]:#', $scriptName)) {
            $scriptName = '';
        }

        if ($scriptName !== '') {
            $scriptDir = str_replace('\\', '/', dirname($scriptName));
            $scriptDir = rtrim($scriptDir, '/');
            if ($scriptDir !== '/' && $scriptDir !== '.' && !preg_match('#^[A-Za-z]:#', $scriptDir) && !preg_match('#/(actions|assets|includes|uploads)$#', $scriptDir)) {
                $appPath = $scriptDir;
            }
        }
    }

    if ($appPath === '' || preg_match('#[A-Za-z]:#', $appPath)) {
        $docRootReal = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $basePathReal = realpath(BASE_PATH);
        $resolvedPath = '';

        if (is_string($docRootReal) && $docRootReal !== '' && is_string($basePathReal) && $basePathReal !== '') {
            $docRootNorm = rtrim(str_replace('\\', '/', $docRootReal), '/');
            $basePathNorm = str_replace('\\', '/', $basePathReal);

            if ($docRootNorm !== '' && str_starts_with($basePathNorm, $docRootNorm)) {
                $relative = trim(substr($basePathNorm, strlen($docRootNorm)), '/');
                $resolvedPath = $relative !== '' ? ('/' . $relative) : '';
            }
        }

        if ($resolvedPath !== '') {
            $appPath = $resolvedPath;
        } elseif (stripos((string)$host, 'localhost') !== false || strpos((string)$host, '127.0.0.1') !== false) {
            $appPath = '/tpms';
        } else {
            $appPath = '';
        }
    }

    $appUrl = $scheme . '://' . $host . $appPath;
}

define('APP_URL', $appUrl);

// File Uploads
define('UPLOAD_PATH', BASE_PATH . '/assets/uploads/photos/');
define('UPLOAD_URL', APP_URL . '/assets/uploads/photos/');
define('MAX_PHOTO_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMG_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// Pagination
define('ITEMS_PER_PAGE', 25);

// Session
define('SESSION_TIMEOUT', 7200); // 2 hours

// Teacher Requirement Planning defaults
define('PLANNING_DEFAULT_MAX_STUDENTS_PER_CLASS', 45);
define('PLANNING_DEFAULT_MAX_CLASSES_PER_TEACHER', 6);
define('PLANNING_DEFAULT_MAX_TEACHING_LOAD_HOURS', 30);
define('PLANNING_DEFAULT_STUDENT_TEACHER_RATIO', 35);
define('PLANNING_DEFAULT_UTILIZATION_THRESHOLD', 90);
define('PLANNING_DEFAULT_HOURS_PER_CLASS_WEEK', 5);

// URL Encryption – secret key for encrypting ID parameters in URLs
// IMPORTANT: Move this to environment variable for production!
// Set env: TPMS_ENCRYPT_KEY="your-secure-random-key"
define('ENCRYPT_KEY', getenv('TPMS_ENCRYPT_KEY') ?: 'TPMS@SDOAurora#2025!SecureKey$XY');
if (getenv('TPMS_ENCRYPT_KEY') === false && php_sapi_name() !== 'cli') {
    error_log('WARNING: ENCRYPT_KEY is using hardcoded fallback. Set TPMS_ENCRYPT_KEY environment variable.');
}

// Bulk Upload – Minimum required headers (flexible matching, case-insensitive)
define('REQUIRED_UPLOAD_HEADERS', [
    'Employee Number',
    'Last Name',
    'First Name',
]);

// Map Upload Header → DB Column  (all variants, case-insensitive in process_upload.php)
define('UPLOAD_COLUMN_MAP', [
    // ── Core identity ─────────────────────────────────────────────
    'Employee Number'                        => 'employee_number',
    'Employee Num'                           => 'employee_number',
    'Employee No'                            => 'employee_number',
    'Employee No.'                           => 'employee_number',
    'Employee ID'                            => 'employee_number',
    'Last Name'                              => 'last_name',
    'Surname'                                => 'last_name',
    'Family Name'                            => 'last_name',
    'First Name'                             => 'first_name',
    'Given Name'                             => 'first_name',
    'Middle Name'                            => 'middle_name',
    'Extension Name'                         => 'extension_name',
    'Extension'                              => 'extension_name',
    // ── Address ───────────────────────────────────────────────
    'House/Street Address'                   => 'house_street',
    'House No./Street/Sitio'                 => 'house_street',
    'Street Address'                         => 'house_street',
    'House No. / Street / Sitio'             => 'house_street',
    'House No. / Lot / Block No. / Street / Sitio / Subdivision' => 'house_street',
    'Barangay'                               => 'barangay',
    'City/Municipality'                      => 'municipality',
    'City / Municipality'                    => 'municipality',
    'Municipality'                           => 'municipality',
    'City'                                   => 'municipality',
    'Province'                               => 'province',
    // ── Personal ───────────────────────────────────────────────────────────────
    'Date of Birth'                          => 'birthdate',
    'Date of Birt'                           => 'birthdate',
    'Birthdate'                              => 'birthdate',
    'Birth Date'                             => 'birthdate',
    'DOB'                                    => 'birthdate',
    'Date of Birthday'                       => 'birthdate',
    'Gender'                                 => 'gender',
    'Civil Status'                           => 'civil_status',
    'Civil Stat'                             => 'civil_status',
    // PWD variants
    'PWD Status'                             => 'pwd_status',
    'PWD Sta'                                => 'pwd_status',
    'Are you a PWD (Person With Disability)?' => 'pwd_status',
    'PWD'                                    => 'pwd_status',
    // Contact
    'Contact Number'                         => 'contact_number',
    'Contact Num'                            => 'contact_number',
    'Mobile Number'                          => 'contact_number',
    // Email
    'Email Address'                          => 'email_address',
    'DepEd Email Address'                    => 'email_address',
    'Email'                                  => 'email_address',
    // ── Employment ────────────────────────────────────────────
    'Position'                               => 'position',
    'Position / Designation'                 => 'position',
    // Item / Plantilla number
    'Item Number'                            => 'item_number',
    'Plantilla Item No'                      => 'item_number',
    'Plantilla Item No.'                     => 'item_number',
    'Salary Grade'                           => 'salary_grade',
    // Appointment type / description
    'Appointment Type'                       => 'appointment_type',
    'Appointment'                            => 'appointment_type',
    'Please identify the description that best fits you.' => 'appointment_type',
    // Appointment date
    'Original Appointment Date'              => 'original_appointment_date',
    'Original Appoi'                         => 'original_appointment_date',
    'Date of Original Appointment'           => 'original_appointment_date',
    'Original Appt. Date'                    => 'original_appointment_date',
    // School
    'School ID Code'                         => 'school_id_code_upload',
    'School ID Co'                           => 'school_id_code_upload',
    'School ID No.'                          => 'school_id_code_upload',
    'School ID No'                           => 'school_id_code_upload',
    'School Name and ID'                     => 'school_id_code_upload',
    'Plantilla Station'                      => 'plantilla_station',
    'Plantilla School Station'               => 'plantilla_station',
    'Plantilla Schoo Station'                => 'plantilla_station',
    'Current Station'                        => 'current_station',
    'Current School Station'                 => 'current_station',
    'Current School Stati'                   => 'current_station',
    'Current Teaching/Detail Station'        => 'current_station',
    'Detail Station'                         => 'current_station',
    'Current Detail Station'                 => 'current_station',
    'School Name'                            => 'school_name_upload',
    'Name of School'                         => 'school_name_upload',
    'Assigned School'                        => 'school_name_upload',
    'District'                               => 'district_upload',
    'District Name'                          => 'district_upload',
    // School Type and ALS Subtype
    'School Type'                            => 'school_type_upload',
    'Type'                                   => 'school_type_upload',
    'ALS Subtype'                            => 'als_subtype_upload',
    'ALS Type'                               => 'als_subtype_upload',
    // Teaching details
    'Grade Level'                            => 'grade_level',
    'Grade Level/s Taug'                     => 'grade_level',
    'Grade Level/s Taught'                   => 'grade_level',
    'Specialization'                         => 'specialization',
    'Area of Specialization'                 => 'specialization',
    'Subjects'                               => 'subjects',
    'Subjects/s Taught'                      => 'subjects',
    'Current Teaching'                       => 'subjects',
    'Subjects Handled'                       => 'subjects',
    'Subject/s Being Handled'                => 'subjects',
    'Maximum Teaching Load'                  => 'max_teaching_load_hours',
    'Maximum Teaching Load (Hours/Week)'     => 'max_teaching_load_hours',
    'Max Teaching Load'                      => 'max_teaching_load_hours',
    'Current Teaching Load'                  => 'current_teaching_load_hours',
    'Current Teaching Load (Hours/Week)'     => 'current_teaching_load_hours',
    'Classes Handled'                        => 'classes_handled',
    'No. of Classes Handled'                 => 'classes_handled',
    'Maximum Classes'                        => 'max_classes',
    'Max Classes'                            => 'max_classes',
    'Advisory Class'                         => 'advisory_class',
    // ── Education ─────────────────────────────────────────────
    'Highest Education'                      => 'highest_education',
    'Highest Educational Attaim'             => 'highest_education',
    'Highest Educational Attainment'         => 'highest_education',
    'Field of Study'                         => 'field_of_study',
    'Field of Study / Course'                => 'field_of_study',
    'Eligibility'                            => 'csee_eligibility',
    'Eligibilty'                             => 'csee_eligibility',
    'CSEE / Eligibility'                     => 'csee_eligibility',
    'CSEE/Eligibility'                       => 'csee_eligibility',
    // ── Privacy ───────────────────────────────────────────────
    'Data Privacy Consent'                   => 'data_privacy_consent',
    'Data Privacy Notice'                    => 'data_privacy_consent',
    'Data Privacy Consent (RA 10173)'        => 'data_privacy_consent',
    'I consent to the collection of my personal information.' => 'data_privacy_consent',
]);

// ──── ALS Subtypes (Alternative Learning System) ─────────────────
// Maps ALS subtype variants to standard values
define('ALS_SUBTYPES', [
    'CBLC'               => 'CBLC',
    'Community-Based'    => 'CBLC',
    'Community Based'    => 'CBLC',
    'Community-Based Community Learning Centers' => 'CBLC',
    
    'SBLC'               => 'SBLC',
    'School-Based'       => 'SBLC',
    'School Based'       => 'SBLC',
    'School-Based Learning Centers' => 'SBLC',
    
    'ALS-SHS'            => 'ALS-SHS',
    'ALS SHS'            => 'ALS-SHS',
    'ALS Senior High'    => 'ALS-SHS',
    'ALS Senior High School' => 'ALS-SHS',
]);

// Timezone
date_default_timezone_set('Asia/Manila');

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
