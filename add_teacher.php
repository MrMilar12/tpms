<?php
ob_start(); // Buffer output to allow redirect after header included
$pageTitle = 'Add Teacher';
require_once __DIR__ . '/includes/header.php';
requireRole(['admin', 'hr']);

$db      = getDB();
ensureTeacherPlanningSchema($db);
$schools = $db->query('SELECT s.id, s.school_name, d.district_name AS district FROM schools s LEFT JOIN districts d ON s.district_id = d.id ORDER BY s.school_name')->fetchAll();
$errors  = [];
$data    = [];
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
            logActivity('DENY', 'teachers', null, 'Blocked invalid school context in add teacher URL.');
            flash('error', 'Invalid school context.');
            redirect(APP_URL . '/teachers.php');
        }
    }

    if ($schoolCtx > 0) {
        $ctxCheck = $db->prepare('SELECT id FROM schools WHERE id = ? LIMIT 1');
        $ctxCheck->execute([$schoolCtx]);
        if (!$ctxCheck->fetchColumn()) {
            logActivity('DENY', 'teachers', null, 'Blocked non-existent school context in add teacher URL.');
            flash('error', 'School context is invalid.');
            redirect(APP_URL . '/teachers.php');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $schoolCtx > 0) {
    $data['school_id'] = $schoolCtx;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $fields = [
        'school_id_code_raw', 'employee_number', 'last_name', 'first_name', 'middle_name',
        'extension_name', 'house_street', 'barangay', 'municipality', 'province',
        'birthdate', 'gender', 'civil_status',
        'pwd_status', 'contact_number', 'email_address',
        'position', 'item_number', 'salary_grade', 'appointment_type',
        'original_appointment_date', 'school_id', 'school_name_raw', 'plantilla_station', 'current_station', 'district_raw',
        'specialization', 'subjects', 'highest_education', 'field_of_study',
        'csee_eligibility'
    ];
    foreach ($fields as $f) {
        $data[$f] = trim($_POST[$f] ?? '');
    }

    // Resolve school_id from selected school, school code, or station/name fields.
    $matchedSchool = resolveSchoolFromTeacherData($db, $data);
    if ($matchedSchool) {
        $data['school_id'] = (int)$matchedSchool['id'];
        if (($data['school_id_code_raw'] ?? '') === '') $data['school_id_code_raw'] = (string)($matchedSchool['school_id_code'] ?? '');
        if (($data['school_name_raw'] ?? '') === '') $data['school_name_raw'] = (string)($matchedSchool['school_name'] ?? '');
        if (($data['district_raw'] ?? '') === '') $data['district_raw'] = (string)($matchedSchool['district_name'] ?? '');
    }
    
    // Special handling for grade_level (comes from hidden field that captures checkboxes)
    $data['grade_level'] = trim($_POST['grade_level_hidden'] ?? '');
    
    // Special handling for checkbox fields (default to 'No' if not checked)
    $data['data_privacy_consent'] = isset($_POST['data_privacy_consent']) ? 'Yes' : 'No';

    // Required field validation
    $required = ['employee_number', 'last_name', 'first_name', 'gender', 'position'];
    foreach ($required as $r) {
        if ($data[$r] === '') {
            $errors[$r] = 'This field is required.';
        }
    }

    $schoolHints = trim(
        (string)($data['school_id_code_raw'] ?? '')
        . (string)($data['school_name_raw'] ?? '')
        . (string)($data['plantilla_station'] ?? '')
        . (string)($data['current_station'] ?? '')
    );
    if ((int)($data['school_id'] ?? 0) === 0 && $schoolHints !== '') {
        $errors['school_id'] = 'School was not matched. Select School Station or use a valid School ID Code/School Name.';
    }

    // Email format
    if ($data['email_address'] !== '' && !filter_var($data['email_address'], FILTER_VALIDATE_EMAIL)) {
        $errors['email_address'] = 'Invalid email format.';
    }

    // Duplicate employee number
    if ($data['employee_number'] !== '') {
        $dup = $db->prepare('SELECT id FROM teachers WHERE employee_number = ?');
        $dup->execute([$data['employee_number']]);
        if ($dup->fetch()) {
            $errors['employee_number'] = 'Employee number already exists.';
        }
    }

    // Photo upload
    $photoFile = null;
    if (!empty($_FILES['profile_photo']['name'])) {
        $result = uploadPhoto($_FILES['profile_photo']);
        if ($result === false) {
            $errors['profile_photo'] = 'Invalid photo. Use JPG/PNG/WEBP, max 5 MB.';
        } else {
            $photoFile = $result;
        }
    }

    if (!$errors) {
        // Sanitize nullable INT / DATE / ENUM fields before INSERT
        $data['school_id']                 = !empty($data['school_id']) ? (int)$data['school_id'] : null;
        $data['birthdate']                 = $data['birthdate'] !== '' ? $data['birthdate'] : null;
        $data['original_appointment_date'] = $data['original_appointment_date'] !== '' ? $data['original_appointment_date'] : null;
        $data['pwd_status']                = $data['pwd_status'] !== '' ? $data['pwd_status'] : 'No';
        $data['profile_photo']             = $photoFile;
        $data['created_by']                = currentUser()['id'];

        // Keep only columns that exist in current teachers schema (prevents unknown-column fatals).
        try {
            $teacherCols = [];
            foreach ($db->query('SHOW COLUMNS FROM teachers')->fetchAll() as $colMeta) {
                $teacherCols[] = $colMeta['Field'];
            }
            $data = array_intersect_key($data, array_flip($teacherCols));
        } catch (Throwable $e) {
            // If schema check fails, proceed with all known fields to avoid blocking form submission
            error_log('TPMS Schema check failed: ' . $e->getMessage());
        }

        try {
            $cols = implode(', ', array_keys($data));
            $phs  = implode(', ', array_fill(0, count($data), '?'));
            $db->prepare("INSERT INTO teachers ($cols) VALUES ($phs)")->execute(array_values($data));
            $newId = $db->lastInsertId();

            logActivity('CREATE', 'teachers', (int)$newId,
                'Added teacher: ' . $data['first_name'] . ' ' . $data['last_name']);
            flash('success', 'Teacher added successfully.');
            $nextSchoolCtx = (int)($data['school_id'] ?? 0);
            redirect(APP_URL . '/view_teacher.php?id=' . encryptId((int)$newId) . ($nextSchoolCtx > 0 ? '&school=' . urlencode(encryptId($nextSchoolCtx)) : ''));
        } catch (Throwable $e) {
            error_log('TPMS Add Teacher Error: ' . $e->getMessage());
            flash('error', 'Unable to save teacher record. Please check required fields and try again.');
            redirect(APP_URL . '/add_teacher.php' . ($schoolCtx > 0 ? '?school=' . urlencode(encryptId($schoolCtx)) : ''));
        }
    }
}
?>

<div class="form-page-wrap">
<form method="POST" action="<?= APP_URL ?>/add_teacher<?= $schoolCtx > 0 ? '?school=' . urlencode(encryptId($schoolCtx)) : '' ?>" enctype="multipart/form-data" novalidate id="teacherForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="school_context" value="<?= clean($schoolCtx > 0 ? encryptId($schoolCtx) : '') ?>">

    <!-- ── Personal Information ── -->
    <div class="form-section glass-card">
        <div class="section-header">
            <h3><i class="fas fa-user"></i> Personal Information</h3>
        </div>
        <div class="form-grid">
            <div class="form-group photo-group" style="grid-column: span 1; grid-row: span 3; align-items:center; justify-content:center; display:flex; flex-direction:column; gap:.75rem;">
                <div class="photo-preview" id="photoPreview">
                    <i class="fas fa-user fa-3x"></i>
                </div>
                <label class="btn btn-ghost btn-sm">
                    <i class="fas fa-camera"></i> Upload Photo
                    <input type="file" name="profile_photo" id="photoInput" accept="image/*" style="display:none" onchange="previewPhoto(this)">
                </label>
                <small class="text-muted">JPG/PNG/WEBP · Max 5MB</small>
                <?php if (!empty($errors['profile_photo'])): ?>
                <span class="form-error"><?= clean($errors['profile_photo']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                  <label class="form-label">School ID Code</label>
                  <input type="text" name="school_id_code_raw" class="form-input"
                      value="<?= clean($data['school_id_code_raw'] ?? '') ?>" placeholder="e.g. 300001">
                 </div>

                 <div class="form-group">
                <label class="form-label required">Employee Number</label>
                <input type="text" name="employee_number" class="form-input <?= isset($errors['employee_number']) ? 'is-invalid' : '' ?>"
                       value="<?= clean($data['employee_number'] ?? '') ?>" placeholder="e.g. 123456">
                <?php if (!empty($errors['employee_number'])): ?><span class="form-error"><?= clean($errors['employee_number']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label required">Last Name</label>
                <input type="text" name="last_name" class="form-input <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>"
                       value="<?= clean($data['last_name'] ?? '') ?>">
                <?php if (!empty($errors['last_name'])): ?><span class="form-error"><?= clean($errors['last_name']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label required">First Name</label>
                <input type="text" name="first_name" class="form-input <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>"
                       value="<?= clean($data['first_name'] ?? '') ?>">
                <?php if (!empty($errors['first_name'])): ?><span class="form-error"><?= clean($errors['first_name']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">Middle Name</label>
                <input type="text" name="middle_name" class="form-input" value="<?= clean($data['middle_name'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Extension (Jr./Sr./III)</label>
                <input type="text" name="extension_name" class="form-input" value="<?= clean($data['extension_name'] ?? '') ?>" placeholder="Jr., Sr., III…">
            </div>

            <div class="form-group">
                <label class="form-label">Date of Birth</label>
                <input type="date" name="birthdate" class="form-input" value="<?= clean($data['birthdate'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label required">Gender</label>
                <select name="gender" class="form-select <?= isset($errors['gender']) ? 'is-invalid' : '' ?>">
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
                    <option value="No"  <?= ($data['pwd_status'] ?? 'No') === 'No'  ? 'selected' : '' ?>>No</option>
                    <option value="Yes" <?= ($data['pwd_status'] ?? '') === 'Yes' ? 'selected' : '' ?>>Yes</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Contact Number</label>
                <input type="text" name="contact_number" class="form-input" value="<?= clean($data['contact_number'] ?? '') ?>" placeholder="09xxxxxxxxx">
            </div>

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email_address" class="form-input <?= isset($errors['email_address']) ? 'is-invalid' : '' ?>"
                       value="<?= clean($data['email_address'] ?? '') ?>">
                <?php if (!empty($errors['email_address'])): ?><span class="form-error"><?= clean($errors['email_address']) ?></span><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Complete Address ── -->
    <div class="form-section glass-card">
        <div class="section-header">
            <h3><i class="fas fa-map-marker-alt"></i> Complete Residential Address</h3>
        </div>
        <div class="form-grid">
            <div class="form-group" style="grid-column: span 2">
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
        <div class="section-header">
            <h3><i class="fas fa-briefcase"></i> Employment Information</h3>
        </div>
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
                ?>
                <input type="text" name="position" list="positionOptionsAdd"
                       class="form-input <?= isset($errors['position']) ? 'is-invalid' : '' ?>"
                       value="<?= clean($data['position'] ?? '') ?>"
                       placeholder="Type to search (e.g. Master)">
                <datalist id="positionOptionsAdd">
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
                <input type="text" name="salary_grade" class="form-input" value="<?= clean($data['salary_grade'] ?? '') ?>" placeholder="e.g. SG-11">
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
                <label class="form-label">Original Appointment Date</label>
                <input type="date" name="original_appointment_date" class="form-input" value="<?= clean($data['original_appointment_date'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">School Station</label>
                <select name="school_id" class="form-select">
                    <option value="">Select school…</option>
                    <?php foreach ($schools as $sc): ?>
                    <option value="<?= (int)$sc['id'] ?>"
                        <?= ((int)($data['school_id'] ?? 0)) === (int)$sc['id'] ? 'selected' : '' ?>>
                        <?= clean($sc['school_name']) ?> (<?= clean($sc['district']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['school_id'])): ?><span class="form-error"><?= clean($errors['school_id']) ?></span><?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">Plantilla School Station</label>
                <input type="text" name="plantilla_station" class="form-input"
                       value="<?= clean($data['plantilla_station'] ?? '') ?>" placeholder="School where plantilla item is assigned">
            </div>

            <div class="form-group">
                <label class="form-label">Current School Station</label>
                <input type="text" name="current_station" class="form-input"
                       value="<?= clean($data['current_station'] ?? '') ?>" placeholder="Current teaching/detail station">
            </div>

            <div class="form-group">
                <label class="form-label">District</label>
                <input type="text" name="district_raw" class="form-input"
                       value="<?= clean($data['district_raw'] ?? '') ?>" placeholder="e.g. District I">
            </div>

            <div class="form-group" style="grid-column: span 2">
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
                <input type="text" name="specialization" class="form-input" value="<?= clean($data['specialization'] ?? '') ?>" placeholder="e.g. Math, English, Science…">
            </div>

            <div class="form-group" style="grid-column: span 2">
                <label class="form-label">Subjects/s Taught</label>
                <textarea name="subjects" class="form-input" rows="2" placeholder="Comma-separated list of subjects"><?= clean($data['subjects'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
    </div>

    <!-- ── Educational Background ── -->
    <div class="form-section glass-card">
        <div class="section-header">
            <h3><i class="fas fa-graduation-cap"></i> Education & Eligibility</h3>
        </div>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Highest Educational Attainment</label>
                <select name="highest_education" class="form-select">
                    <option value="">Select…</option>
                    <?php foreach (['Bachelor\'s Degree','With Masteral Units','Master\'s Degree','With Doctoral Units','Doctorate Degree'] as $ed): ?>
                    <option value="<?= $ed ?>" <?= ($data['highest_education'] ?? '') === $ed ? 'selected' : '' ?>><?= $ed ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Field of Study / Course</label>
                <input type="text" name="field_of_study" class="form-input" value="<?= clean($data['field_of_study'] ?? '') ?>">
            </div>

            <div class="form-group" style="grid-column: span 2">
                <label class="form-label">CSEE / Eligibility</label>
                <input type="text" name="csee_eligibility" class="form-input" value="<?= clean($data['csee_eligibility'] ?? '') ?>" placeholder="e.g. LET Passer, CSEE…">
            </div>
        </div>
    </div>

    <!-- ── Data Privacy ── -->
    <div class="form-section glass-card">
        <div class="section-header">
            <h3><i class="fas fa-shield-alt"></i> Data Privacy (RA 10173)</h3>
        </div>
        <div class="form-group" style="padding: 0 1.5rem 1.5rem">
            <label class="checkbox-label">
                <input type="checkbox" name="data_privacy_consent" value="Yes"
                       <?= ($data['data_privacy_consent'] ?? '') === 'Yes' ? 'checked' : '' ?>>
                <span>The teacher has provided written consent for data collection and processing in compliance with the Data Privacy Act of 2012 (RA 10173).</span>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <a href="<?= APP_URL ?>/teachers.php<?= $schoolCtx > 0 ? '?school=' . urlencode(encryptId($schoolCtx)) : '' ?>" class="btn btn-ghost">
            <i class="fas fa-arrow-left"></i> Cancel
        </a>
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-save"></i> Save Teacher
        </button>
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
// Init on page load (for validation repopulation)
syncGradeLevels();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
