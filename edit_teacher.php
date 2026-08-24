<?php
ob_start(); // Buffer output to allow redirect after header included
$pageTitle = 'Edit Teacher';
require_once __DIR__ . '/includes/header.php';
requireRole(['admin', 'hr']);

$db = getDB();
ensureTeacherPlanningSchema($db);
$token = $_GET['id'] ?? '';
$schoolCtxRaw = trim((string)($_GET['school'] ?? ($_POST['school_context'] ?? '')));
$schoolCtx = 0;
if ($schoolCtxRaw !== '') {
    if (ctype_digit($schoolCtxRaw)) {
        $schoolCtx = (int)$schoolCtxRaw;
    } else {
        $decodedSchoolCtx = decryptId($schoolCtxRaw);
        if ($decodedSchoolCtx !== false) {
            $schoolCtx = (int)$decodedSchoolCtx;
        } else {
            logActivity('DENY', 'teachers', null, 'Blocked invalid school context in edit teacher URL.');
            flash('error', 'Invalid school context.');
            redirect(APP_URL . '/teachers.php');
        }
    }

    if ($schoolCtx > 0) {
        $ctxCheck = $db->prepare('SELECT id FROM schools WHERE id = ? LIMIT 1');
        $ctxCheck->execute([$schoolCtx]);
        if (!$ctxCheck->fetchColumn()) {
            logActivity('DENY', 'teachers', null, 'Blocked non-existent school context in edit teacher URL.');
            flash('error', 'School context is invalid.');
            redirect(APP_URL . '/teachers.php');
        }
    }
}

$id = isset($_GET['id']) ? (decryptId($token) ?: 0) : (int)($_POST['id'] ?? 0);
if (!$id) { flash('error', 'Invalid teacher.'); redirect(APP_URL . '/teachers.php'); }

$stmt = $db->prepare('SELECT * FROM teachers WHERE id = ?');
$stmt->execute([$id]);
$teacher = $stmt->fetch();
if (!$teacher) { flash('error', 'Teacher not found.'); redirect(APP_URL . '/teachers.php'); }

$schools = $db->query('SELECT s.id, s.school_name, d.district_name AS district FROM schools s LEFT JOIN districts d ON s.district_id = d.id ORDER BY s.school_name')->fetchAll();
$errors  = [];
$data    = $teacher; // Prefill

$currentSchoolName = (string)($teacher['school_name_raw'] ?? '');
$currentDistrictName = (string)($teacher['district_raw'] ?? '');
foreach ($schools as $sc) {
    if ((int)($teacher['school_id'] ?? 0) === (int)$sc['id']) {
        $currentSchoolName = (string)($sc['school_name'] ?? $currentSchoolName);
        $currentDistrictName = (string)($sc['district'] ?? $currentDistrictName);
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    $fields = [
        'employee_number','last_name','first_name','middle_name','extension_name',
        'house_street','barangay','municipality','province',
        'birthdate','gender','civil_status','pwd_status','contact_number','email_address',
        'position','item_number','salary_grade','appointment_type','original_appointment_date',
        'plantilla_station','current_station','specialization','subjects',
        'highest_education',
        'field_of_study','csee_eligibility'
    ];
    foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '');

    // School assignment is managed via Transfer action on the Teachers page.
    // Keep current school-related values unchanged in edit form saves.
    $data['school_id'] = $teacher['school_id'] ?? null;
    $data['school_id_code_raw'] = $teacher['school_id_code_raw'] ?? '';
    $data['school_name_raw'] = $teacher['school_name_raw'] ?? '';
    $data['district_raw'] = $teacher['district_raw'] ?? '';
    
    // Special handling for grade_level (comes from hidden field that captures checkboxes)
    $data['grade_level'] = trim($_POST['grade_level_hidden'] ?? '');
    
    // Special handling for checkbox fields (default to 'No' if not checked)
    $data['data_privacy_consent'] = isset($_POST['data_privacy_consent']) ? 'Yes' : 'No';

    $required = ['employee_number','last_name','first_name','gender','position'];
    foreach ($required as $r) {
        if ($data[$r] === '') $errors[$r] = 'This field is required.';
    }
    if ($data['email_address'] !== '' && !filter_var($data['email_address'], FILTER_VALIDATE_EMAIL)) {
        $errors['email_address'] = 'Invalid email.';
    }

    if ($confirmPassword === '') {
        $errors['confirm_password'] = 'Password confirmation is required to update this teacher.';
    } else {
        $me = (int)(currentUser()['id'] ?? 0);
        $pwStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $pwStmt->execute([$me]);
        $passwordHash = (string)$pwStmt->fetchColumn();
        if ($passwordHash === '' || !password_verify($confirmPassword, $passwordHash)) {
            $errors['confirm_password'] = 'Invalid password.';
        }
    }


    // Duplicate check excluding self
    if ($data['employee_number'] !== '') {
        $dup = $db->prepare('SELECT id FROM teachers WHERE employee_number = ? AND id != ?');
        $dup->execute([$data['employee_number'], $id]);
        if ($dup->fetch()) $errors['employee_number'] = 'Employee number already in use.';
    }

    // Photo
    if (!empty($_FILES['profile_photo']['name'])) {
        $result = uploadPhoto($_FILES['profile_photo'], $teacher['profile_photo']);
        if ($result === false) {
            $errors['profile_photo'] = 'Invalid photo.';
        } else {
            $data['profile_photo'] = $result;
        }
    } else {
        $data['profile_photo'] = $teacher['profile_photo'];
    }

    if (!$errors) {
        // Sanitize nullable INT / DATE / ENUM fields
        $data['birthdate']                 = $data['birthdate'] !== '' ? $data['birthdate'] : null;
        $data['original_appointment_date'] = $data['original_appointment_date'] !== '' ? $data['original_appointment_date'] : null;
        $data['pwd_status']                = $data['pwd_status'] !== '' ? $data['pwd_status'] : 'No';

        // Explicit whitelist – never touch created_at / created_by
        $writableCols = [
            'employee_number', 'last_name', 'first_name', 'middle_name', 'extension_name',
            'house_street', 'barangay', 'municipality', 'province',
            'birthdate', 'gender', 'civil_status', 'pwd_status', 'contact_number', 'email_address',
            'position', 'item_number', 'salary_grade', 'appointment_type', 'original_appointment_date',
            'plantilla_station', 'current_station', 'grade_level', 'specialization', 'subjects', 'highest_education',
            'field_of_study', 'csee_eligibility', 'data_privacy_consent', 'profile_photo',
        ];

        // Keep only columns that exist in current teachers schema (prevents unknown-column fatals).
        try {
            $teacherCols = [];
            foreach ($db->query('SHOW COLUMNS FROM teachers')->fetchAll() as $colMeta) {
                $teacherCols[] = $colMeta['Field'];
            }
            $writableCols = array_values(array_filter($writableCols, fn($c) => in_array($c, $teacherCols, true)));
        } catch (Throwable $e) {
            // If schema check fails, proceed with conservative column list to avoid blocking form submission
            error_log('TPMS Schema check failed: ' . $e->getMessage());
        }

        $setCols  = implode(', ', array_map(fn($c) => "$c = ?", $writableCols));
        $vals     = array_map(fn($c) => $data[$c] ?? null, $writableCols);
        $vals[]   = $id;

        try {
            $db->prepare("UPDATE teachers SET $setCols, updated_at = NOW() WHERE id = ?")->execute($vals);
            logActivity('UPDATE', 'teachers', $id, 'Updated teacher: '.$data['first_name'].' '.$data['last_name']);
            flash('success', 'Teacher updated successfully.');
            $nextSchoolCtx = (int)($teacher['school_id'] ?? 0);
            redirect(APP_URL . '/view_teacher.php?id=' . encryptId($id) . ($nextSchoolCtx > 0 ? '&school=' . urlencode(encryptId($nextSchoolCtx)) : ''));
        } catch (Throwable $e) {
            error_log('TPMS Edit Teacher Error: ' . $e->getMessage());
            flash('error', 'Unable to update teacher record. Please check input values and try again.');
            redirect(APP_URL . '/edit_teacher.php?id=' . encryptId($id) . ($schoolCtx > 0 ? '&school=' . urlencode(encryptId($schoolCtx)) : ''));
        }
    }
}
?>

<div class="form-page-wrap">
<form method="POST" action="<?= APP_URL ?>/edit_teacher?id=<?= encryptId($id) ?><?= $schoolCtx > 0 ? '&school=' . urlencode(encryptId($schoolCtx)) : '' ?>" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="school_context" value="<?= clean($schoolCtx > 0 ? encryptId($schoolCtx) : '') ?>">

    <div class="form-section glass-card">
        <div class="section-header"><h3><i class="fas fa-user"></i> Personal Information</h3></div>
        <div class="form-grid">
            <div class="form-group photo-group" style="grid-column:span 1; grid-row:span 3; display:flex; flex-direction:column; align-items:center; gap:.75rem;">
                <div class="photo-preview" id="photoPreview">
                    <?php if ($data['profile_photo']): ?>
                    <img src="<?= UPLOAD_URL . clean($data['profile_photo']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:50%">
                    <?php else: ?><i class="fas fa-user fa-3x"></i><?php endif; ?>
                </div>
                <label class="btn btn-ghost btn-sm">
                    <i class="fas fa-camera"></i> Change Photo
                    <input type="file" name="profile_photo" id="photoInput" accept="image/*" style="display:none" onchange="previewPhoto(this)">
                </label>
                <?php if (!empty($errors['profile_photo'])): ?><span class="form-error"><?= clean($errors['profile_photo']) ?></span><?php endif; ?>
            </div>

            <?php
            $textFields = [
                ['employee_number','Employee Number','text',true],
                ['last_name','Last Name','text',true],
                ['first_name','First Name','text',true],
                ['middle_name','Middle Name','text',false],
                ['extension_name','Extension (Jr./III)','text',false],
                ['contact_number','Contact Number','text',false],
                ['email_address','Email Address','email',false],
            ];
            foreach ($textFields as [$name, $label, $type, $req]):
            ?>
            <div class="form-group">
                <label class="form-label <?= $req ? 'required' : '' ?>"><?= $label ?></label>
                <input type="<?= $type ?>" name="<?= $name ?>"
                       class="form-input <?= isset($errors[$name]) ? 'is-invalid' : '' ?>"
                       value="<?= clean($data[$name] ?? '') ?>">
                <?php if (!empty($errors[$name])): ?><span class="form-error"><?= clean($errors[$name]) ?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div class="form-group date-enhanced-field">
                <label class="form-label">Date of Birth</label>
                <div class="date-enhanced-control">
                    <input type="date" name="birthdate" id="birthdateInput"
                           class="form-input"
                           value="<?= clean($data['birthdate'] ?? '') ?>"
                           max="<?= date('Y-m-d') ?>">
                    <button type="button" class="date-picker-btn" data-target="birthdateInput" aria-label="Open birthdate picker" title="Open calendar">
                        <i class="fas fa-calendar-days"></i>
                    </button>
                </div>
                <div class="date-enhanced-meta">
                    <span class="date-enhanced-display" id="birthdateDisplay">No date selected</span>
                    <div class="date-enhanced-actions">
                        <button type="button" class="date-chip-btn" data-action="clear" data-target="birthdateInput">Clear</button>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label required">Gender</label>
                <select name="gender" class="form-select">
                    <option value="">Select…</option>
                    <option value="Male"   <?= ($data['gender'] ?? '') === 'Male'   ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= ($data['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                </select>
                <?php if (!empty($errors['gender'])): ?><span class="form-error"><?= clean($errors['gender']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label">Civil Status</label>
                <select name="civil_status" class="form-select">
                    <option value="">Select…</option>
                    <?php foreach (['Single','Married','Widowed','Separated','Annulled'] as $cs): ?>
                    <option value="<?= $cs ?>" <?= ($data['civil_status'] ?? '') === $cs ? 'selected' : '' ?>><?= $cs ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">PWD Status</label>
                <select name="pwd_status" class="form-select">
                    <option value="No"  <?= ($data['pwd_status'] ?? '') === 'No'  ? 'selected' : '' ?>>No</option>
                    <option value="Yes" <?= ($data['pwd_status'] ?? '') === 'Yes' ? 'selected' : '' ?>>Yes</option>
                </select>
            </div>
        </div>
    </div>

    <div class="form-section glass-card">
        <div class="section-header"><h3><i class="fas fa-map-marker-alt"></i> Complete Residential Address</h3></div>
        <div class="form-grid">
            <div class="form-group" style="grid-column:span 2">
                <label class="form-label">House No. / Lot / Block No. / Street / Sitio / Subdivision</label>
                <input type="text" name="house_street" class="form-input"
                       value="<?= clean($data['house_street'] ?? '') ?>" placeholder="e.g. Lot 5 Block 3, Rizal St., Poblacion">
            </div>
            <div class="form-group">
                <label class="form-label">Barangay</label>
                <input type="text" name="barangay" class="form-input"
                       value="<?= clean($data['barangay'] ?? '') ?>" placeholder="e.g. Brgy. Poblacion">
            </div>
            <div class="form-group">
                <label class="form-label">City / Municipality</label>
                <input type="text" name="municipality" class="form-input"
                       value="<?= clean($data['municipality'] ?? '') ?>" placeholder="e.g. Baler">
            </div>
            <div class="form-group">
                <label class="form-label">Province</label>
                <input type="text" name="province" class="form-input"
                       value="<?= clean($data['province'] ?? '') ?>" placeholder="e.g. Aurora">
            </div>
        </div>
    </div>

    <div class="form-section glass-card">
        <div class="section-header"><h3><i class="fas fa-briefcase"></i> Employment</h3></div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label required">Position / Designation</label>
                <?php
                $positions = [
                    'Teaching' => ['Teacher I','Teacher II','Teacher III',
                        'Master Teacher I','Master Teacher II','Master Teacher III','Master Teacher IV',
                        'Special Education Teacher I','Special Education Teacher II','Special Education Teacher III'],
                    'Leadership' => ['Head Teacher I','Head Teacher II','Head Teacher III',
                        'Head Teacher IV','Head Teacher V','Head Teacher VI',
                        'Principal I','Principal II','Principal III','Principal IV'],
                    'Non-Teaching' => ['Guidance Counselor I','School Librarian I',
                        'ALS Mobile Teacher','SPED Teacher'],
                ];
                $positionOptions = array_values(array_unique(array_merge(...array_values($positions))));
                if (($data['position'] ?? '') !== '' && !in_array($data['position'], $positionOptions, true)) {
                    $positionOptions[] = (string)$data['position'];
                }
                ?>
                <input type="text" name="position" list="positionOptionsEdit"
                       class="form-input <?= isset($errors['position']) ? 'is-invalid' : '' ?>"
                       value="<?= clean($data['position'] ?? '') ?>"
                       placeholder="Type to search (e.g. Master)">
                <datalist id="positionOptionsEdit">
                    <?php foreach ($positionOptions as $p): ?>
                    <option value="<?= clean($p) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <?php if (!empty($errors['position'])): ?><span class="form-error"><?= clean($errors['position']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="form-label">Item Number</label>
                <input type="text" name="item_number" class="form-input" value="<?= clean($data['item_number'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Salary Grade</label>
                <input type="text" name="salary_grade" class="form-input" value="<?= clean($data['salary_grade'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Original Appt. Date</label>
                <div class="date-enhanced-control">
                    <input type="date" name="original_appointment_date" id="appointmentDateInput"
                           class="form-input"
                           value="<?= clean($data['original_appointment_date'] ?? '') ?>"
                           max="<?= date('Y-m-d') ?>">
                    <button type="button" class="date-picker-btn" data-target="appointmentDateInput" aria-label="Open appointment date picker" title="Open calendar">
                        <i class="fas fa-calendar-days"></i>
                    </button>
                </div>
                <div class="date-enhanced-meta">
                    <span class="date-enhanced-display" id="appointmentDateDisplay">No date selected</span>
                    <div class="date-enhanced-actions">
                        <button type="button" class="date-chip-btn" data-action="today" data-target="appointmentDateInput">Today</button>
                        <button type="button" class="date-chip-btn" data-action="clear" data-target="appointmentDateInput">Clear</button>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Appointment Type</label>
                <select name="appointment_type" class="form-select">
                    <option value="">Select…</option>
                    <?php foreach (['Permanent','Provisional','Substitute','Casual','Contractual','Co-terminus'] as $at): ?>
                    <option value="<?= $at ?>" <?= ($data['appointment_type'] ?? '') === $at ? 'selected' : '' ?>><?= $at ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">School Station</label>
                <input type="text" class="form-input" value="<?= clean($currentSchoolName !== '' ? $currentSchoolName : '—') ?>" readonly>
                <small class="text-muted">School transfer is disabled in Edit Teacher. Use Transfer in <a href="<?= APP_URL ?>/teachers.php" style="text-decoration:underline">Teachers page</a>.</small>
            </div>
            <div class="form-group">
                <label class="form-label">Plantilla School Station</label>
                <input type="text" name="plantilla_station" class="form-input" value="<?= clean($data['plantilla_station'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Current School Station</label>
                <input type="text" name="current_station" class="form-input" value="<?= clean($data['current_station'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">District</label>
                <input type="text" class="form-input" value="<?= clean($currentDistrictName !== '' ? $currentDistrictName : '—') ?>" readonly>
            </div>
            <div class="form-group" style="grid-column:span 2">
                <label class="form-label">Grade Level/s Taught</label>
                <div class="grade-checkbox-grid">
                    <?php
                    $allLevels = ['Kindergarten','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6',
                                  'Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12',
                                  'ELEM','JHS','SHS'];
                    $selLevels = array_map('trim', explode(',', $data['grade_level'] ?? ''));
                    foreach ($allLevels as $lv): ?>
                    <label class="checkbox-label-sm">
                        <input type="checkbox" name="grade_levels[]" value="<?= $lv ?>"
                               <?= in_array($lv, $selLevels) ? 'checked' : '' ?>
                               onchange="syncGradeLevels()">
                        <span><?= $lv ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="grade_level_hidden" id="grade_level_hidden"
                       value="<?= clean($data['grade_level'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Specialization</label>
                <input type="text" name="specialization" class="form-input" value="<?= clean($data['specialization'] ?? '') ?>">
            </div>
            <div class="form-group" style="grid-column:span 2">
                <label class="form-label">Subjects/s Taught</label>
                <textarea name="subjects" class="form-input" rows="2"><?= clean($data['subjects'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="form-section glass-card">
        <div class="section-header"><h3><i class="fas fa-graduation-cap"></i> Education</h3></div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Highest Education</label>
                <select name="highest_education" class="form-select">
                    <option value="">Select…</option>
                    <?php foreach (["Bachelor's Degree",'With Masteral Units',"Master's Degree",'With Doctoral Units','Doctorate Degree'] as $ed): ?>
                    <option value="<?= $ed ?>" <?= ($data['highest_education'] ?? '') === $ed ? 'selected' : '' ?>><?= $ed ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Field of Study</label>
                <input type="text" name="field_of_study" class="form-input" value="<?= clean($data['field_of_study'] ?? '') ?>">
            </div>
            <div class="form-group" style="grid-column:span 2">
                <label class="form-label">CSEE / Eligibility</label>
                <input type="text" name="csee_eligibility" class="form-input" value="<?= clean($data['csee_eligibility'] ?? '') ?>">
            </div>
        </div>
    </div>

    <div class="form-section glass-card">
        <div class="section-header"><h3><i class="fas fa-shield-alt"></i> Data Privacy</h3></div>
        <div style="padding:0 1.5rem 1.5rem">
            <label class="checkbox-label">
                <input type="checkbox" name="data_privacy_consent" value="Yes"
                       <?= ($data['data_privacy_consent'] ?? '') === 'Yes' ? 'checked' : '' ?>>
                <span>RA 10173 – Data Privacy consent given.</span>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <input type="hidden" name="confirm_password" id="teacherConfirmPassword" value="">
        <a href="<?= APP_URL ?>/view_teacher.php?id=<?= encryptId($id) ?><?= $schoolCtx > 0 ? '&school=' . urlencode(encryptId($schoolCtx)) : '' ?>" class="btn btn-ghost"><i class="fas fa-arrow-left"></i> Cancel</a>
        <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Update Teacher</button>
    </div>
</form>
</div>

<script>
// @ts-nocheck
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const p = document.getElementById('photoPreview');
            p.innerHTML = `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover;border-radius:50%">`;
        };
        reader.readAsDataURL(input.files[0]);
    }
}
function syncGradeLevels() {
    const checked = [...document.querySelectorAll('input[name="grade_levels[]"]:checked')]
                      .map(cb => cb.value);
    document.getElementById('grade_level_hidden').value = checked.join(', ');
}
syncGradeLevels();

function parseIsoDate(value) {
    if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
    const [y, m, d] = value.split('-').map(Number);
    const dt = new Date(y, m - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== m - 1 || dt.getDate() !== d) return null;
    return dt;
}

function formatPrettyDate(value) {
    const dt = parseIsoDate(value);
    if (!dt) return '';
    return dt.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
}

function yearsFromDate(value) {
    const dt = parseIsoDate(value);
    if (!dt) return null;
    const now = new Date();
    let years = now.getFullYear() - dt.getFullYear();
    const monthDiff = now.getMonth() - dt.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && now.getDate() < dt.getDate())) {
        years -= 1;
    }
    return years;
}

function updateDateDisplay(inputId, displayId, mode) {
    const input = document.getElementById(inputId);
    const display = document.getElementById(displayId);
    if (!input || !display) return;

    const pretty = formatPrettyDate(input.value);
    if (!pretty) {
        display.textContent = 'No date selected';
        return;
    }

    if (mode === 'birth') {
        const age = yearsFromDate(input.value);
        display.textContent = age !== null && age >= 0 ? `${pretty} (${age} years old)` : pretty;
        return;
    }

    if (mode === 'appointment') {
        const service = yearsFromDate(input.value);
        display.textContent = service !== null && service >= 0 ? `${pretty} (${service} years in service)` : pretty;
        return;
    }

    display.textContent = pretty;
}

document.querySelectorAll('.date-picker-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
        const target = document.getElementById(btn.dataset.target || '');
        if (!target) return;
        if (typeof target.showPicker === 'function') {
            target.showPicker();
        } else {
            target.focus();
            target.click();
        }
    });
});

document.querySelectorAll('.date-chip-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
        const target = document.getElementById(btn.dataset.target || '');
        if (!target) return;

        if (btn.dataset.action === 'today') {
            const today = new Date();
            const iso = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-');
            target.value = iso;
        } else if (btn.dataset.action === 'clear') {
            target.value = '';
        }

        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
    });
});

['input', 'change'].forEach((evt) => {
    document.getElementById('birthdateInput')?.addEventListener(evt, () => updateDateDisplay('birthdateInput', 'birthdateDisplay', 'birth'));
    document.getElementById('appointmentDateInput')?.addEventListener(evt, () => updateDateDisplay('appointmentDateInput', 'appointmentDateDisplay', 'appointment'));
});

updateDateDisplay('birthdateInput', 'birthdateDisplay', 'birth');
updateDateDisplay('appointmentDateInput', 'appointmentDateDisplay', 'appointment');

async function promptTeacherPassword(message) {
    if (typeof Swal !== 'undefined') {
        const res = await Swal.fire({
            title: 'Confirm Password',
            text: message,
            input: 'password',
            inputPlaceholder: 'Current password',
            inputAttributes: { autocomplete: 'current-password', autocapitalize: 'off', autocorrect: 'off' },
            showCancelButton: true,
            confirmButtonText: 'Continue',
            cancelButtonText: 'Cancel',
            preConfirm: (value) => {
                if (!value) {
                    Swal.showValidationMessage('Password is required.');
                    return false;
                }
                return value;
            }
        });
        return res.isConfirmed ? res.value : '';
    }
    return prompt(message) || '';
}

document.querySelector('form[method="POST"]')?.addEventListener('submit', async function(e) {
    if (this.dataset.confirmed === '1') return;
    e.preventDefault();
    const pwd = await promptTeacherPassword('Enter your password to update this teacher record:');
    if (!pwd) return;
    const confirmField = document.getElementById('teacherConfirmPassword');
    if (confirmField) confirmField.value = pwd;
    this.dataset.confirmed = '1';
    this.submit();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
