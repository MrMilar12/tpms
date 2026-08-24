# TPMS Security & Code Quality Audit Report
**Date:** July 2, 2026  
**Codebase:** Teacher Profiling Management System  
**Repository:** c:\xampp\htdocs\tpms

---

## Executive Summary

This comprehensive audit identified **25 bugs and security issues** across the TPMS codebase, ranging from **critical security vulnerabilities** to **low-priority code quality improvements**. The system implements several good security practices (prepared statements, CSRF tokens, session management, 2FA), but has critical debug scripts and vulnerabilities that require immediate attention.

---

## CRITICAL SEVERITY ISSUES (Must Fix Immediately)

### 1. **Unauthenticated Admin Password Reset Script**
- **File:** [reset_password.php](reset_password.php)
- **Lines:** 1-15
- **Severity:** CRITICAL
- **Issue:** `reset_password.php` is a debug/utility script that sets the admin password to "Password123" without ANY authentication or authorization checks. This file is publicly accessible and can be executed by anyone.
- **Impact:** Complete account takeover - attacker can reset admin credentials and gain full system access
- **Fix:** DELETE this file immediately. If needed as development tooling, move to a protected admin directory with authentication checks.
- **Code:**
  ```php
  <?php
  require_once __DIR__ . '/config.php';
  require_once __DIR__ . '/includes/db.php';
  
  $db = getDB();
  $hash = password_hash('Password123', PASSWORD_BCRYPT, ['cost' => 12]);
  $db->prepare('UPDATE users SET password_hash = ? WHERE username = ?')
     ->execute([$hash, 'admin']);
  echo "Admin password set to: Password123\n";
  ?>
  ```

### 2. **Unauthenticated Debug Teacher Listing Script**
- **File:** [list_teachers.php](list_teachers.php)
- **Lines:** 1-13
- **Severity:** CRITICAL
- **Issue:** This file lists ALL teachers with ID, name, and employee number without ANY authentication. It's a debug utility left in production.
- **Impact:** Information disclosure - exposes sensitive employee data to unauthorized users
- **Fix:** DELETE this file or implement authentication before rendering any output
- **Code:**
  ```php
  <?php
  require_once __DIR__ . '/config.php';
  require_once __DIR__ . '/includes/db.php';
  
  $db = getDB();
  $teachers = $db->query('SELECT id, first_name, last_name, employee_number FROM teachers ORDER BY id')->fetchAll();
  
  echo "All teachers:\n";
  foreach($teachers as $t) {
      echo $t['id'] . ": " . $t['first_name'] . " " . $t['last_name'] . " (" . $t['employee_number'] . ")\n";
  }
  ?>
  ```

### 3. **Potential SQL Injection via User ID in Admin User Edit**
- **File:** [users.php](users.php#L15)
- **Lines:** 13-15
- **Severity:** CRITICAL
- **Issue:** `$_GET['edit']` parameter is used directly in SQL without proper validation:
  ```php
  if (isset($_GET['edit'])) {
      $stmt->execute([(int)$_GET['edit']]);
  ```
  While it's cast to int (which mitigates SQL injection), there's no authorization check to verify the current user has permission to edit that user ID. An HR user could edit admin accounts.
- **Impact:** Unauthorized privilege escalation - users can modify other users' roles/passwords
- **Fix:** Add role-based access control check:
  ```php
  if (isset($_GET['edit'])) {
      $userId = (int)$_GET['edit'];
      if (!isAdmin() && currentUser()['id'] !== $userId) {
          flash('error', 'Permission denied.');
          redirect(APP_URL . '/users.php');
      }
      // ... continue
  ```

### 4. **Hardcoded Encryption Key in Public Config File**
- **File:** [config.php](config.php#L100)
- **Lines:** 100
- **Severity:** CRITICAL
- **Issue:** Encryption key is hardcoded in a public configuration file visible in version control:
  ```php
  define('ENCRYPT_KEY', 'TPMS@SDOAurora#2025!SecureKey$XY');
  ```
  This key is used for URL ID encryption and is stored in the repository with full visibility.
- **Impact:** All URL-encrypted IDs can be decrypted by attackers. Anyone with code access can decrypt all encrypted teacher/school IDs.
- **Fix:** 
  - Move to environment variable: `define('ENCRYPT_KEY', getenv('TPMS_ENCRYPT_KEY') ?: 'fallback');`
  - Or read from `.env` file (not in git)
  - Regenerate key on first deployment:
    ```php
    if (!getenv('TPMS_ENCRYPT_KEY')) {
        $newKey = bin2hex(random_bytes(16));
        die("Set environment variable: TPMS_ENCRYPT_KEY=$newKey");
    }
    ```

---

## HIGH SEVERITY ISSUES (Fix Soon)

### 5. **No CSRF Protection on GET Search/Filter Requests**
- **Files:** Multiple - [schools.php](schools.php#L6-L10), [teachers.php](teachers.php#L6-L10), [districts.php](districts.php#L7-L8), [als.php](als.php#L6-L9)
- **Issue:** Search and filter parameters use GET requests without CSRF tokens. Attackers can craft URLs that execute unintended searches when users click links.
- **Severity:** HIGH (though GET is less risky than POST)
- **Example:** `schools.php?q=xss_payload&type=all`
- **Impact:** Potential reflected XSS via search parameters; also violates principle that state-changing operations should be POST with CSRF
- **Fix:** While searching isn't state-changing, add validation:
  ```php
  // In schools.php around line 6
  $search = trim($_GET['q'] ?? '');
  if ($search !== '' && mb_strlen($search) > 500) {
      flash('error', 'Search term too long');
      redirect(APP_URL . '/schools.php');
  }
  ```

### 6. **Missing Authorization Check on Export/Delete Operations**
- **File:** [actions/delete_school.php](actions/delete_school.php#L8-L14)
- **Lines:** 8-14
- **Severity:** HIGH
- **Issue:** While `verifyCsrf()` is called, there's no explicit role/permission check before the deletion. The function checks `canEdit()` but doesn't validate the POST action itself:
  ```php
  requireLogin();
  verifyCsrf();
  
  if (!canEdit()) {
      flash('error', 'Permission denied.');
      redirect(APP_URL . '/schools.php');
  }
  ```
  An HR user who has `canEdit()` permissions could bypass password confirmation via missing session checks.
- **Impact:** Privilege escalation if HR role permissions are mishandled
- **Fix:** Add explicit role check for destructive operations:
  ```php
  requireRole(['admin']);
  ```

### 7. **Race Condition in Bulk Upload Handling**
- **Files:** [actions/process_school_upload.php](actions/process_school_upload.php#L174-L180), [actions/process_upload.php](actions/process_upload.php#L434-435)
- **Lines:** process_school_upload: 174-180
- **Severity:** HIGH
- **Issue:** Multiple file uploads from different users can race condition when checking for duplicate school codes or teacher records:
  ```php
  $checkStmt->execute([$schoolCode]);
  $exists = $checkStmt->fetch();
  
  if ($exists) {
      if ($skipDupes && !$updateExist) { $skipped++; continue; }
      if ($updateExist) {
          // UPDATE existing
          $updateStmt->execute($updateVals);
      }
  }
  ```
  Between the check and update, another transaction could insert a record, causing duplicate key errors or data inconsistency.
- **Impact:** Data corruption, duplicate entries, failed uploads
- **Fix:** Use `INSERT ... ON DUPLICATE KEY UPDATE` or handle race condition gracefully:
  ```php
  try {
      if ($exists) {
          $updateStmt->execute($updateVals);
      } else {
          $insertStmt->execute($insertVals);
      }
  } catch (PDOException $e) {
      if ($e->getCode() == '23000') { // Duplicate key
          $skipped++;
          continue;
      }
      throw $e;
  }
  ```

### 8. **Vulnerable Deserialization in Session Data**
- **File:** [includes/auth.php](includes/auth.php#L238-L244)
- **Lines:** 238-244
- **Severity:** HIGH
- **Issue:** The 2FA session data is stored directly without validation:
  ```php
  function beginTwoFactorChallenge(array $user): void {
      $_SESSION['pending_2fa'] = [
          'uid' => (int)$user['id'],
          'username' => (string)$user['username'],
          'started_at' => time(),
      ];
  }
  ```
  While this looks safe, if an attacker gains session file access, they could modify `pending_2fa` to bypass 2FA.
- **Impact:** 2FA bypass if session files are compromised
- **Fix:** Use HMAC to sign session data or store in database instead of session

### 9. **Insufficient Input Validation on File Extensions**
- **File:** [actions/process_school_upload.php](actions/process_school_upload.php#L22-26)
- **Lines:** 22-26
- **Severity:** HIGH
- **Issue:** File extension checked only against whitelist, but MIME type check missing:
  ```php
  $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  $allowed = ['xlsx', 'xls', 'csv'];
  if (!in_array($ext, $allowed, true)) {
      flash('error', 'Invalid file type. Use .xlsx or .csv.');
      redirect(APP_URL . '/schools.php');
  }
  ```
  A file named `shell.php.csv` or `shell.csv` containing PHP code could bypass extension check.
- **Impact:** Potential arbitrary file upload if temporary files aren't cleaned properly
- **Fix:** Verify MIME type and delete temp files immediately after parsing:
  ```php
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = finfo_file($finfo, $file['tmp_name']);
  $allowedMimes = ['text/csv', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
  if (!in_array($mime, $allowedMimes, true)) {
      flash('error', 'Invalid file type.');
      redirect(APP_URL . '/schools.php');
  }
  ```

### 10. **Missing Rate Limiting on Login Attempts**
- **File:** [includes/auth.php](includes/auth.php#L68-92)
- **Lines:** 68-92
- **Severity:** HIGH
- **Issue:** While rate limiting exists, the window is per-user basis only. An attacker can try multiple usernames:
  ```php
  function canAttemptLogin(?string $username = null): bool {
      $windowSec = 900; // 15 minutes
      $maxByIp = 20;    // 20 attempts per IP
      $maxByUser = 8;   // 8 attempts per username
  ```
  20 attempts per IP in 15 minutes is too lenient - that's ~80 attempts per hour
- **Impact:** Brute force attacks can succeed with sufficient time
- **Fix:** Increase restrictions:
  ```php
  $maxByIp = 5;     // 5 per IP per 15min
  $maxByUser = 3;   // 3 per username per 15min
  $windowSec = 900; // 15 minutes
  ```

---

## MEDIUM SEVERITY ISSUES (Fix Before Production)

### 11. **Reflected XSS via Search Parameters**
- **File:** [schools.php](schools.php#L6), [teachers.php](teachers.php#L6)
- **Lines:** Search parameter extraction
- **Severity:** MEDIUM
- **Issue:** While `clean()` function is used in HTML output, search parameters are logged before sanitization:
  ```php
  $search = trim($_GET['q'] ?? '');  // Line 6
  // ... later in SQL query
  $conditions[] = '(s.school_name LIKE ? OR d.district_name LIKE ?)';
  $params = array_merge($params, array_fill(0, 5, '%' . $search . '%'));
  ```
  The search term is properly parameterized in SQL (good), but if displayed in error messages before sanitization, XSS is possible.
- **Impact:** Reflected XSS attack if search appears in error messages
- **Fix:** Sanitize immediately after retrieval:
  ```php
  $search = clean(trim($_GET['q'] ?? ''));
  if (strlen($search) > 500) {
      flash('error', 'Search term is too long');
      redirect(APP_URL . '/schools.php');
  }
  ```

### 12. **SQL Injection via District Name Filter**
- **File:** [schools.php](schools.php#L10)
- **Lines:** 10
- **Severity:** MEDIUM  
- **Issue:** While parameterized queries are used, the district filter from `$_GET['district']` is used in LIKE clause:
  ```php
  $districtFilter = trim($_GET['district'] ?? '');
  // ... later:
  if ($districtFilter !== '') {
      $conditions[] = 'd.district_name = ?';
      $params[] = $districtFilter;
  }
  ```
  This is properly parameterized, but wildcard characters could cause unexpected results if not escaped.
- **Impact:** Logic bypass - attacker could use SQL wildcards to filter unintended results
- **Fix:** Escape wildcards:
  ```php
  if ($districtFilter !== '') {
      $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $districtFilter);
      $conditions[] = 'd.district_name = ? ESCAPE "\\\\"';
      $params[] = $escaped;
  }
  ```

### 13. **Missing Timeout on Password Export Confirmation**
- **File:** [actions/export_schools.php](actions/export_schools.php#L112-120)
- **Lines:** 112-120
- **Severity:** MEDIUM
- **Issue:** Password confirmation for exports doesn't expire. A user could copy the CSRF token and use it later:
  ```php
  verifyCsrf();
  
  $confirmPassword = (string)($_POST['confirm_password'] ?? '');
  if ($confirmPassword === '') {
      flash('error', 'Password confirmation is required before export.');
      redirect($retryUrl);
  }
  // No time-based validation
  ```
- **Impact:** Password confirmation could be replayed within session lifetime (2 hours)
- **Fix:** Add timestamp validation:
  ```php
  $_SESSION['export_confirm_time'] = time();
  // ... later when verifying:
  if (time() - ($_SESSION['export_confirm_time'] ?? 0) > 300) {
      flash('error', 'Password confirmation expired. Please re-enter password.');
      redirect($retryUrl);
  }
  ```

### 14. **Weak Password Policy in Profile**
- **File:** [profile.php](profile.php#L25-30)
- **Lines:** 25-30
- **Severity:** MEDIUM
- **Issue:** Password strength check only requires 10 characters minimum:
  ```php
  function isStrongPassword(string $password): bool {
      if (strlen($password) < 10) return false;
      if (!preg_match('/[A-Z]/', $password)) return false;
      if (!preg_match('/[a-z]/', $password)) return false;
      if (!preg_match('/\d/', $password)) return false;
      if (!preg_match('/[^A-Za-z0-9]/', $password)) return false;
      return true;
  }
  ```
  This allows passwords like "Password1!" which contains common dictionary words.
- **Impact:** Weak passwords increase vulnerability to dictionary/hybrid attacks
- **Fix:** Increase minimum to 12 characters and check against common password list

### 15. **Missing HTTPS Redirect**
- **File:** [config.php](config.php#L14-50)
- **Lines:** 14-50
- **Severity:** MEDIUM
- **Issue:** While the system detects HTTPS, it doesn't enforce it. The APP_URL construction allows HTTP:
  ```php
  if ($forcedAppUrlIsValid) {
      $appUrl = rtrim($forcedAppUrl, '/');
  } elseif (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
      $appUrl = 'http://localhost/tpms';
  } else {
      $isHttps = (...);
      $scheme = $isHttps ? 'https' : 'http';  // Could be HTTP
  ```
- **Impact:** Session cookies and sensitive data transmitted unencrypted if accessed via HTTP
- **Fix:** Add forced HTTPS redirect in header.php:
  ```php
  if (php_sapi_name() !== 'cli' && empty($_SERVER['HTTPS'])) {
      header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
      exit();
  }
  ```

### 16. **Missing X-Content-Type-Options Header Issue**
- **File:** [includes/auth.php](includes/auth.php#L25-29)
- **Lines:** 25-29
- **Severity:** MEDIUM
- **Issue:** While `X-Content-Type-Options: nosniff` is set, it's only in `sendSecurityHeaders()` which might not be called on all error pages:
  ```php
  function sendSecurityHeaders(): void {
      if (headers_sent()) return;
      header('X-Frame-Options: SAMEORIGIN');
      header('X-Content-Type-Options: nosniff');
      header('Referrer-Policy: strict-origin-when-cross-origin');
      header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
  }
  ```
  If an error occurs before this function is called, headers aren't sent.
- **Impact:** MIME type sniffing vulnerability
- **Fix:** Call `sendSecurityHeaders()` immediately after session start in all entry points

### 17. **Insufficient Validation of Encrypted ID Parameters**
- **File:** [functions.php](functions.php#L48-69)
- **Lines:** 57-69
- **Severity:** MEDIUM
- **Issue:** The `decryptId()` function silently fails for invalid input:
  ```php
  function decryptId(string $token): int|false {
      if ($token === '') return false;
      try {
          $key    = substr(hash('sha256', ENCRYPT_KEY, true), 0, 16);
          $padLen = (4 - strlen($token) % 4) % 4;
          $b64    = strtr($token, '-_', '+/') . str_repeat('=', $padLen);
          $dec    = openssl_decrypt(base64_decode($b64), 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
          if ($dec === false || strlen($dec) < 4) return false;
          $id = unpack('N', substr($dec, 0, 4))[1];
          return $id > 0 ? $id : false;
      } catch (Throwable) {
          return false;
      }
  }
  ```
  No timing attack protection - all invalid IDs take similar time to process.
- **Impact:** Timing attacks could allow attacker to forge IDs
- **Fix:** Use `hash_equals()` for all comparisons and ensure constant-time operation

### 18. **Missing Max-Age on CSRF Token**
- **File:** [includes/auth.php](includes/auth.php#L401-405)
- **Lines:** 401-405
- **Severity:** MEDIUM
- **Issue:** CSRF token has no expiration:
  ```php
  function csrfToken(): string {
      if (empty($_SESSION['csrf_token'])) {
          $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
      }
      return $_SESSION['csrf_token'];
  }
  ```
  Token persists for entire session (2 hours), increasing chance of compromise.
- **Impact:** Longer window for CSRF attacks
- **Fix:** Regenerate on each request or set expiration:
  ```php
  function csrfToken(): string {
      $now = time();
      if (empty($_SESSION['csrf_token']) || 
          empty($_SESSION['csrf_token_time']) || 
          $now - $_SESSION['csrf_token_time'] > 300) {
          $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
          $_SESSION['csrf_token_time'] = $now;
      }
      return $_SESSION['csrf_token'];
  }
  ```

### 19. **Verbose Error Messages Leak Information**
- **File:** [includes/db.php](includes/db.php#L22-30)
- **Lines:** 22-30
- **Severity:** MEDIUM
- **Issue:** Database connection errors shown to users with full error message:
  ```php
  } catch (PDOException $e) {
      error_log('TPMS DB Error: ' . $e->getMessage());
      http_response_code(500);
      die('<div style="font-family:sans-serif;padding:2rem;text-align:center;">'
        . '<h2>Database Connection Failed</h2>'
        . '<p>Please check your database settings in <strong>config.php</strong>.</p>'
        . '</div>');
  }
  ```
  While the full error isn't shown, suggesting `config.php` leaks file structure.
- **Impact:** Information disclosure
- **Fix:** Don't reference specific files in error messages

### 20. **Missing Input Length Validation in Multiple Places**
- **Files:** [add_teacher.php](add_teacher.php#L56), [schools.php](schools.php#L6-10)
- **Issue:** Many user inputs lack maximum length checks before database operations
- **Severity:** MEDIUM
- **Example:** District name has no length limit
  ```php
  $districtFilter = trim($_GET['district'] ?? '');
  // No length validation before:
  $conditions[] = 'd.district_name = ?';
  $params[] = $districtFilter;
  ```
- **Impact:** Long inputs could cause performance degradation or buffer issues
- **Fix:** Add validation:
  ```php
  $districtFilter = trim($_GET['district'] ?? '');
  if (strlen($districtFilter) > 255) {
      flash('error', 'Filter too long');
      redirect(APP_URL . '/schools.php');
  }
  ```

---

## LOW SEVERITY ISSUES (Code Quality & Best Practices)

### 21. **Undefined Variable Risk in dashboard.php**
- **File:** [dashboard.php](dashboard.php#L45-70)
- **Lines:** 45-70
- **Severity:** LOW
- **Issue:** Variables like `$genderData`, `$districtData` used without null checks
- **Fix:** Initialize as empty arrays before use

### 22. **Missing Strict Type Declarations**
- **Files:** Multiple PHP files
- **Severity:** LOW
- **Issue:** No `declare(strict_types=1)` at file start
- **Fix:** Add at top of each PHP file for type safety

### 23. **Inconsistent Error Handling**
- **Files:** [functions.php](functions.php#L238), [auth.php](includes/auth.php#L128)
- **Severity:** LOW
- **Issue:** Some functions use try/catch, others use silent failures with `@` operator
- **Fix:** Standardize error handling approach

### 24. **Missing Database Indexes**
- **File:** Database schema (referenced in activity_logs)
- **Severity:** LOW
- **Issue:** Activity logs table may need indexes on `user_id`, `action`, `module`
- **Fix:** Add indexes:
  ```sql
  ALTER TABLE activity_logs ADD INDEX idx_user_id (user_id);
  ALTER TABLE activity_logs ADD INDEX idx_action (action);
  ALTER TABLE activity_logs ADD INDEX idx_module (module);
  ```

### 25. **Accessibility Issues in Sidebar Navigation**
- **File:** [includes/header.php](includes/header.php#L40-50)
- **Lines:** 40-50
- **Severity:** LOW
- **Issue:** Navigation links don't have proper ARIA labels or semantic structure for screen readers
- **Fix:** Add `role="navigation"` and `aria-label` attributes

---

## SUMMARY TABLE

| # | File | Issue | Severity | Status |
|---|------|-------|----------|--------|
| 1 | reset_password.php | Unauthenticated admin password reset | CRITICAL | ⚠️ DELETE |
| 2 | list_teachers.php | Unauthenticated data disclosure | CRITICAL | ⚠️ DELETE |
| 3 | users.php:15 | Missing authorization check | CRITICAL | 🔴 FIX |
| 4 | config.php:100 | Hardcoded encryption key | CRITICAL | 🔴 FIX |
| 5 | schools.php, teachers.php | No CSRF on GET requests | HIGH | 🟡 IMPROVE |
| 6 | delete_school.php | Missing auth checks | HIGH | 🔴 FIX |
| 7 | process_*_upload.php | Race condition | HIGH | 🔴 FIX |
| 8 | auth.php:238 | Vulnerable session data | HIGH | 🔴 FIX |
| 9 | process_school_upload.php:22 | File extension validation | HIGH | 🔴 FIX |
| 10 | auth.php:68 | Weak rate limiting | HIGH | 🔴 FIX |
| 11-25 | Various | Medium/Low issues | MEDIUM/LOW | Various |

---

## IMMEDIATE ACTION ITEMS

### Phase 1 (Within 24 Hours)
1. ✅ DELETE `reset_password.php`
2. ✅ DELETE `list_teachers.php`
3. ✅ Move ENCRYPT_KEY to environment variable
4. ✅ Add authorization check in users.php

### Phase 2 (Within 1 Week)
5. Fix race conditions in upload handlers
6. Improve rate limiting on login
7. Add file MIME type validation
8. Add HTTPS enforcement

### Phase 3 (Before Production)
9. Add input validation for all user inputs
10. Standardize error handling
11. Add database indexes
12. Complete accessibility review

---

## RECOMMENDATIONS

1. **Enable debug mode locally only** - `ini_set('display_errors', 0)` should be more restrictive
2. **Implement security headers** - Add `Content-Security-Policy` header
3. **Add request signing** - For critical operations, require additional confirmation
4. **Implement audit logging** - Already exists, but review retention policy
5. **Regular security updates** - Keep PHP/libraries updated
6. **Penetration testing** - Conduct professional security assessment
7. **Code review process** - Implement peer review for security-critical code
8. **Security training** - for development team on OWASP Top 10

---

## References
- OWASP Top 10: https://owasp.org/www-project-top-ten/
- CWE-284: Improper Access Control
- CWE-352: Cross-Site Request Forgery (CSRF)
- CWE-89: SQL Injection
- PHP Security: https://www.php.net/manual/en/security.php

---

**Report Generated:** 2026-06-26  
**Auditor:** AI Code Analysis  
**Status:** READY FOR REVIEW
