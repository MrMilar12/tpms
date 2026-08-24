<?php
$pageTitle = 'Teacher Requirement Planning';
require_once __DIR__ . '/includes/header.php';

// Require user to have selected a role
requireRoleSelection();

$db = getDB();
ensureTeacherPlanningSchema($db);

$resolveSchoolId = static function (?string $raw): int {
    $value = trim((string)$raw);
    if ($value === '') {
        return 0;
    }
    if (ctype_digit($value)) {
        return (int)$value;
    }
    $decoded = decryptId($value);
    return $decoded === false ? 0 : (int)$decoded;
};

$requestedSchoolToken = trim((string)($_POST['school'] ?? $_GET['school'] ?? ''));
$requestedSchoolId = $resolveSchoolId($requestedSchoolToken);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_teacher_workload'])) {
    verifyCsrf();

    if (!canEdit()) {
        flash('error', 'Permission denied.');
        redirect(APP_URL . '/requirement_planning.php');
    }

    $postedSchoolId = $resolveSchoolId((string)($_POST['school'] ?? ''));
    if ($postedSchoolId <= 0) {
        flash('error', 'Invalid school selection.');
        redirect(APP_URL . '/requirement_planning.php');
    }

    $snapshot = computeSchoolTeacherPlanning($db, $postedSchoolId);
    if (!$snapshot) {
        flash('error', 'School not found for planning update.');
        redirect(APP_URL . '/requirement_planning.php');
    }

    $allowedTeacherIds = [];
    foreach (($snapshot['teacher_rows'] ?? []) as $row) {
        $tid = (int)($row['id'] ?? 0);
        if ($tid > 0) {
            $allowedTeacherIds[$tid] = true;
        }
    }

    if (!$allowedTeacherIds) {
        flash('error', 'No teachers linked to this school.');
        redirect(APP_URL . '/requirement_planning.php?school=' . urlencode(encryptId($postedSchoolId)));
    }

    $loadRows = (array)($_POST['current_teaching_load_hours'] ?? []);
    $classesRows = (array)($_POST['classes_handled'] ?? []);
    $studentsRows = (array)($_POST['students_handled'] ?? []);
    $maxLoadRows = (array)($_POST['max_teaching_load_hours'] ?? []);
    $maxClassesRows = (array)($_POST['max_classes'] ?? []);

    $updateStmt = $db->prepare(
        'UPDATE teachers
         SET current_teaching_load_hours = :current_load,
             classes_handled = :classes_handled,
             students_handled = :students_handled,
             max_teaching_load_hours = :max_load,
             max_classes = :max_classes,
             updated_at = NOW()
         WHERE id = :teacher_id'
    );

    $updated = 0;
    $db->beginTransaction();
    try {
        foreach ($allowedTeacherIds as $teacherId => $_) {
            $currentLoad = max(0, min(80, (float)($loadRows[$teacherId] ?? 0)));
            $classesHandled = max(0, min(40, (int)($classesRows[$teacherId] ?? 0)));
            $studentsHandled = max(0, min(500, (int)($studentsRows[$teacherId] ?? 0)));

            $maxLoadInput = trim((string)($maxLoadRows[$teacherId] ?? ''));
            $maxClassInput = trim((string)($maxClassesRows[$teacherId] ?? ''));
            $maxLoad = $maxLoadInput === '' ? null : max(1, min(80, (float)$maxLoadInput));
            $maxClasses = $maxClassInput === '' ? null : max(1, min(40, (int)$maxClassInput));

            $updateStmt->execute([
                'current_load' => $currentLoad,
                'classes_handled' => $classesHandled,
                'students_handled' => $studentsHandled,
                'max_load' => $maxLoad,
                'max_classes' => $maxClasses,
                'teacher_id' => $teacherId,
            ]);
            $updated++;
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('TPMS planning teacher workload save failed: ' . $e->getMessage());
        flash('error', 'Unable to save teacher planning data right now.');
        redirect(APP_URL . '/requirement_planning.php?school=' . urlencode(encryptId($postedSchoolId)));
    }

    logActivity('UPDATE', 'teacher_planning_workload', $postedSchoolId, 'Updated planning workload fields for ' . $updated . ' teacher(s).');
    flash('success', 'Updated planning workload for ' . $updated . ' teacher(s).');
    redirect(APP_URL . '/requirement_planning.php?school=' . urlencode(encryptId($postedSchoolId)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_school_parameters'])) {
    verifyCsrf();

    if (!canEdit()) {
        flash('error', 'Permission denied.');
        redirect(APP_URL . '/requirement_planning.php');
    }

    $postedSchoolId = $resolveSchoolId((string)($_POST['school'] ?? ''));
    if ($postedSchoolId <= 0) {
        flash('error', 'Invalid school selection.');
        redirect(APP_URL . '/requirement_planning.php');
    }

    $studentCount = max(0, (int)($_POST['learner_count'] ?? 0));

    $st = $db->prepare('UPDATE schools SET learner_count = ?, updated_at = NOW() WHERE id = ?');
    $st->execute([$studentCount, $postedSchoolId]);

    logActivity('UPDATE', 'planning_school_parameters', $postedSchoolId, 'Updated planning student count to ' . $studentCount . '.');
    flash('success', 'Planning parameter updated: student count saved.');
    redirect(APP_URL . '/requirement_planning.php?school=' . urlencode(encryptId($postedSchoolId)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_planning_settings'])) {
    requireRole(['admin']);
    verifyCsrf();

    if (savePlanningSettings($db, $_POST)) {
        logActivity('UPDATE', 'planning_settings', 1, 'Updated teacher requirement planning settings.');
        flash('success', 'Planning settings updated successfully.');
    } else {
        flash('error', 'Unable to save planning settings.');
    }

    $returnSchool = trim((string)($_POST['school'] ?? $_GET['school'] ?? ''));
    redirect(APP_URL . '/requirement_planning.php' . ($returnSchool !== '' ? '?school=' . urlencode($returnSchool) : ''));
}

$settings = getPlanningSettings($db);
$schools = $db->query(
    'SELECT s.id, s.school_name, d.district_name
     FROM schools s
     LEFT JOIN districts d ON s.district_id = d.id
     ORDER BY s.school_name'
)->fetchAll();

$schoolId = $requestedSchoolId;
if ($schoolId <= 0 && !empty($schools)) {
    $schoolId = (int)$schools[0]['id'];
}

$plan = $schoolId > 0 ? computeSchoolTeacherPlanning($db, $schoolId, $settings) : null;

$subjectFilterOptions = [];
if ($plan && !empty($plan['teacher_rows'])) {
    foreach ($plan['teacher_rows'] as $teacherRow) {
        $subjectsRaw = trim((string)($teacherRow['subjects'] ?? ''));
        if ($subjectsRaw === '') {
            continue;
        }
        foreach (explode(',', $subjectsRaw) as $subjectPiece) {
            $subjectLabel = trim($subjectPiece);
            if ($subjectLabel === '') {
                continue;
            }
            $subjectFilterOptions[strtolower($subjectLabel)] = $subjectLabel;
        }
    }
    asort($subjectFilterOptions, SORT_NATURAL | SORT_FLAG_CASE);
}

$divisionSnapshot = [];
$totalShortage = 0;
$overloadedSchoolCount = 0;
$totalPossibleRetirees = 0;
foreach ($schools as $schoolRow) {
    $item = computeSchoolTeacherPlanning($db, (int)$schoolRow['id'], $settings);
    if (!$item) continue;

    $shortage = (int)$item['summary']['teacher_shortage'];
    $overloaded = (int)$item['summary']['overloaded_teachers'];
    if ($overloaded > 0) {
        $overloadedSchoolCount++;
    }
    $totalShortage += $shortage;
    $totalPossibleRetirees += (int)($item['summary']['possible_retirees'] ?? 0);

    $divisionSnapshot[] = [
        'id' => (int)$schoolRow['id'],
        'school_name' => (string)$schoolRow['school_name'],
        'district_name' => (string)($schoolRow['district_name'] ?? ''),
        'teachers' => (int)$item['summary']['total_teachers'],
        'students' => (int)$item['summary']['total_students'],
        'shortage' => $shortage,
        'recommended' => (int)$item['summary']['recommended_teachers'],
    ];
}

usort($divisionSnapshot, static fn($a, $b) => $b['shortage'] <=> $a['shortage']);
$topShortageSchools = array_slice($divisionSnapshot, 0, 8);
?>

<div class="stats-grid" style="--cols:5">
    <div class="stat-card glass-card planning-stat-click" data-action="focus-school-select" role="button" tabindex="0" title="Go to school selector">
        <div class="stat-icon icon-blue"><i class="fas fa-school"></i></div>
        <div class="stat-body"><div class="stat-value"><?= number_format(count($schools)) ?></div><div class="stat-label">Schools Analyzed</div></div>
    </div>
    <div class="stat-card glass-card planning-stat-click" data-action="jump-shortage" role="button" tabindex="0" title="Go to teacher shortage summary">
        <div class="stat-icon icon-orange"><i class="fas fa-user-plus"></i></div>
        <div class="stat-body"><div class="stat-value"><?= number_format($totalShortage) ?></div><div class="stat-label">Division Teacher Shortage</div></div>
    </div>
    <div class="stat-card glass-card planning-stat-click" data-action="filter-overloaded" role="button" tabindex="0" title="Show overloaded teachers">
        <div class="stat-icon icon-red"><i class="fas fa-person-circle-exclamation"></i></div>
        <div class="stat-body"><div class="stat-value"><?= number_format($overloadedSchoolCount) ?></div><div class="stat-label">Schools With Overloads</div></div>
    </div>
    <div class="stat-card glass-card planning-stat-click" data-action="open-advanced-settings" role="button" tabindex="0" title="Open planning settings">
        <div class="stat-icon icon-cyan"><i class="fas fa-sliders"></i></div>
        <div class="stat-body"><div class="stat-value"><?= number_format((int)$settings['recommended_student_teacher_ratio']) ?>:1</div><div class="stat-label">Planning Ratio</div></div>
    </div>
    <div class="stat-card glass-card planning-stat-click" data-action="filter-possible-retirees" role="button" tabindex="0" title="Show possible retirees">
        <div class="stat-icon icon-red"><i class="fas fa-user-clock"></i></div>
        <div class="stat-body"><div class="stat-value"><?= number_format($totalPossibleRetirees) ?></div><div class="stat-label">Possible Retirees</div></div>
    </div>
</div>

<div id="planningControls" class="planning-controls-grid" style="margin-top:12px;display:grid;gap:12px;">
    <div class="planning-primary-grid">
        <div class="planning-control-card glass-card planning-school-card" style="padding:12px;">
            <div class="planning-card-head">
                <h3><i class="fas fa-school"></i> Select School</h3>
                <span class="text-muted small">Choose one school to edit planning inputs.</span>
            </div>
            <form method="GET" class="filter-form" id="planningSchoolForm" style="margin-top:10px;display:grid;grid-template-columns:1fr;gap:8px;">
                <input type="text" id="planningSchoolSearchInput" class="form-input" placeholder="Type to search school or district...">
                <select name="school" class="form-select" id="planningSchoolSelect" onchange="this.form.submit()">
                    <?php foreach ($schools as $s): ?>
                    <?php $schoolLabel = clean($s['school_name']) . (!empty($s['district_name']) ? ' - ' . clean($s['district_name']) : ''); ?>
                    <option value="<?= urlencode(encryptId((int)$s['id'])) ?>" data-label="<?= htmlspecialchars(strtolower((string)$schoolLabel), ENT_QUOTES, 'UTF-8') ?>" <?= (int)$s['id'] === $schoolId ? 'selected' : '' ?>>
                        <?= $schoolLabel ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if (canEdit() && $plan): ?>
        <div class="planning-control-card glass-card planning-parameter-card" style="padding:12px;">
            <div class="planning-card-head">
                <h3><i class="fas fa-sliders"></i> School Planning Parameter</h3>
                <span class="badge badge-blue">Current: <?= clean((string)$plan['school']['school_name']) ?></span>
            </div>
            <form method="POST" class="filter-form planning-parameter-form" style="margin-top:10px;">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="save_school_parameters" value="1">
                <input type="hidden" name="school" value="<?= urlencode(encryptId($schoolId)) ?>">
                <div class="form-group">
                    <label class="form-label">Student Count</label>
                    <input type="number" name="learner_count" class="form-input" min="0" step="1" value="<?= (int)($plan['school']['learner_count'] ?? 0) ?>">
                </div>
                <button type="submit" class="btn btn-secondary planning-btn-student"><i class="fas fa-save"></i> Save Student Count</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <?php if (isAdmin()): ?>
    <div class="planning-settings-trigger-wrap">
        <button type="button" id="openAdvancedSettingsModal" class="btn planning-btn-settings-trigger">
            <i class="fas fa-sliders-h"></i> Advanced Planning Settings
        </button>
    </div>

    <div id="advancedPlanningSettingsModal" class="planning-modal" aria-hidden="true">
        <div class="planning-modal-dialog planning-modal-dialog-wide" role="dialog" aria-modal="true" aria-labelledby="advancedPlanningSettingsTitle">
            <div class="planning-modal-header">
                <h4 id="advancedPlanningSettingsTitle"><i class="fas fa-sliders-h"></i> Advanced Planning Settings</h4>
                <button type="button" class="planning-modal-close" id="closeAdvancedSettingsModal" aria-label="Close">&times;</button>
            </div>
            <div class="planning-modal-body">
                <div class="planning-settings-intro">
                    Fine-tune division-wide planning rules used in teacher requirement calculations.
                </div>
                <form method="POST" class="filter-form planning-settings-form" style="margin-top:10px;">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="save_planning_settings" value="1">
                    <input type="hidden" name="school" value="<?= $schoolId > 0 ? urlencode(encryptId($schoolId)) : '' ?>">

                    <div class="planning-settings-group">
                        <h4><i class="fas fa-users"></i> Class Capacity Rules</h4>
                        <div class="planning-settings-grid">
                            <div class="form-group">
                                <label class="form-label">Max Students per Class</label>
                                <input type="number" name="max_students_per_class" class="form-input" min="1" max="100" value="<?= clean((string)$settings['max_students_per_class']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Student-Teacher Ratio</label>
                                <input type="number" id="recommendedRatioInput" name="recommended_student_teacher_ratio" class="form-input" min="1" max="100" value="<?= clean((string)$settings['recommended_student_teacher_ratio']) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="planning-settings-group">
                        <h4><i class="fas fa-chalkboard-teacher"></i> Teacher Workload Rules</h4>
                        <div class="planning-settings-grid">
                            <div class="form-group">
                                <label class="form-label">Max Classes per Teacher</label>
                                <input type="number" name="max_classes_per_teacher" class="form-input" min="1" max="20" value="<?= clean((string)$settings['max_classes_per_teacher']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Max Teaching Load (hrs/wk)</label>
                                <input type="number" name="max_teaching_load_hours" class="form-input" min="1" max="80" step="0.5" value="<?= clean((string)$settings['max_teaching_load_hours']) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="planning-settings-group">
                        <h4><i class="fas fa-calculator"></i> Planning Formula Controls</h4>
                        <div class="planning-settings-grid">
                            <div class="form-group">
                                <label class="form-label">Utilization Threshold (%)</label>
                                <input type="number" name="utilization_threshold_pct" class="form-input" min="1" max="100" step="0.5" value="<?= clean((string)$settings['utilization_threshold_pct']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Hours per Class per Week</label>
                                <input type="number" name="default_hours_per_class_week" class="form-input" min="0.5" max="20" step="0.5" value="<?= clean((string)$settings['default_hours_per_class_week']) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="planning-settings-actions">
                        <button type="submit" class="btn btn-primary planning-btn-settings"><i class="fas fa-save"></i> Save Planning Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var input = document.getElementById('planningSchoolSearchInput');
    var select = document.getElementById('planningSchoolSelect');
    var ratioInput = document.getElementById('recommendedRatioInput');
    if (!input || !select) return;

    var advancedSettingsModal = document.getElementById('advancedPlanningSettingsModal');
    var openAdvancedSettingsModalBtn = document.getElementById('openAdvancedSettingsModal');
    var closeAdvancedSettingsModalBtn = document.getElementById('closeAdvancedSettingsModal');

    function openAdvancedSettingsModal() {
        if (!advancedSettingsModal) return;
        advancedSettingsModal.classList.add('is-open');
        advancedSettingsModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (ratioInput) {
            setTimeout(function () { ratioInput.focus(); }, 250);
        }
    }

    input.addEventListener('input', function () {
        var q = String(input.value || '').toLowerCase().trim();
        var options = Array.prototype.slice.call(select.options || []);
        var firstVisible = null;

        options.forEach(function (opt) {
            var label = String(opt.getAttribute('data-label') || opt.text || '').toLowerCase();
            var show = q === '' || label.indexOf(q) !== -1;
            opt.hidden = !show;
            if (show && !firstVisible) firstVisible = opt;
        });

        if (firstVisible) {
            select.value = firstVisible.value;
        }
    });

    function runStatAction(action) {
        var controls = document.getElementById('planningControls');
        var summary = document.getElementById('schoolSummaryCard');
        var workload = document.getElementById('teacherWorkloadCard');
        var teacherStatusFilter = document.getElementById('teacherStatusFilter');
        var teacherRetirementFilter = document.getElementById('teacherRetirementFilter');

        if (action === 'focus-school-select') {
            if (controls) {
                controls.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            if (select) {
                setTimeout(function () { select.focus(); }, 250);
            }
            return;
        }

        if (action === 'jump-shortage' && summary) {
            summary.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }

        if (action === 'filter-overloaded') {
            if (teacherStatusFilter) {
                teacherStatusFilter.value = 'overloaded';
                var statusEvt = document.createEvent('HTMLEvents');
                statusEvt.initEvent('change', true, false);
                teacherStatusFilter.dispatchEvent(statusEvt);
            }
            if (workload) {
                workload.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            return;
        }

        if (action === 'filter-possible-retirees') {
            if (teacherRetirementFilter) {
                teacherRetirementFilter.value = 'possible retiree';
                var retireEvt = document.createEvent('HTMLEvents');
                retireEvt.initEvent('change', true, false);
                teacherRetirementFilter.dispatchEvent(retireEvt);
            }
            if (workload) {
                workload.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            return;
        }

        if (action === 'open-advanced-settings' && advancedSettingsModal) {
            openAdvancedSettingsModal();
        }
    }

    function closeAdvancedSettingsModal() {
        if (!advancedSettingsModal) return;
        advancedSettingsModal.classList.remove('is-open');
        advancedSettingsModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    if (openAdvancedSettingsModalBtn && advancedSettingsModal) {
        openAdvancedSettingsModalBtn.addEventListener('click', openAdvancedSettingsModal);
    }

    if (closeAdvancedSettingsModalBtn) {
        closeAdvancedSettingsModalBtn.addEventListener('click', closeAdvancedSettingsModal);
    }

    if (advancedSettingsModal) {
        advancedSettingsModal.addEventListener('click', function (event) {
            if (event.target === advancedSettingsModal) {
                closeAdvancedSettingsModal();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && advancedSettingsModal && advancedSettingsModal.classList.contains('is-open')) {
            closeAdvancedSettingsModal();
        }
    });

    var statCards = Array.prototype.slice.call(document.querySelectorAll('.planning-stat-click'));
    statCards.forEach(function (card) {
        card.addEventListener('click', function () {
            runStatAction(card.getAttribute('data-action') || '');
        });
        card.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                runStatAction(card.getAttribute('data-action') || '');
            }
        });
    });
})();
</script>

<?php if ($plan): ?>
<?php $sum = $plan['summary']; ?>
<div class="charts-grid" style="margin-top:12px;grid-template-columns:2fr 1.2fr;">
    <div id="schoolSummaryCard" class="chart-card glass-card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-building"></i> School Summary Dashboard</h3></div>
        <div class="table-scroll">
            <table class="data-table">
                <tbody>
                    <tr><td>School Name</td><td><strong><?= clean((string)$plan['school']['school_name']) ?></strong></td></tr>
                    <tr><td>School Year</td><td><?= clean((string)($plan['school']['school_year'] ?: 'Not set')) ?></td></tr>
                    <tr><td>Total Students</td><td><?= number_format((int)$sum['total_students']) ?></td></tr>
                    <tr><td>Total Students Assigned To Teachers</td><td><?= number_format((int)($sum['used_students'] ?? 0)) ?></td></tr>
                    <tr><td>Total Teachers</td><td><?= number_format((int)$sum['total_teachers']) ?></td></tr>
                    <tr><td>Student-Teacher Ratio</td><td><?= $sum['student_teacher_ratio_actual'] !== null ? clean((string)$sum['student_teacher_ratio_actual']) . ':1' : 'N/A' ?></td></tr>
                    <tr><td>Total Sections</td><td><?= number_format((int)$sum['total_sections']) ?></td></tr>
                    <tr><td>Required Classes</td><td><?= number_format((int)$sum['required_classes']) ?></td></tr>
                    <tr><td>Required Teaching Hours</td><td><?= number_format((float)$sum['required_teaching_hours'], 1) ?></td></tr>
                    <tr><td>Current Teaching Capacity</td><td><?= number_format((float)$sum['capacity_hours'], 1) ?></td></tr>
                    <tr><td>Class Capacity</td><td><?= number_format((int)$sum['capacity_classes']) ?></td></tr>
                    <tr><td>Teachers Needed</td><td><strong><?= number_format((int)$sum['recommended_teachers']) ?></strong></td></tr>
                    <tr><td>Possible Retirees (Age 60+)</td><td><?= number_format((int)($sum['possible_retirees'] ?? 0)) ?></td></tr>
                    <tr><td>Teacher Shortage</td><td><span class="badge <?= (int)$sum['teacher_shortage'] > 0 ? 'badge-danger' : 'badge-green' ?>"><?= number_format((int)$sum['teacher_shortage']) ?></span></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="chart-card glass-card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-scale-balanced"></i> Decision Logic</h3></div>
        <div style="display:grid;gap:10px;padding:4px 2px 2px">
            <div class="badge badge-blue" style="justify-content:space-between;display:flex;padding:10px 12px">Student Ratio Need <strong><?= number_format((int)$sum['teachers_needed_ratio']) ?></strong></div>
            <div class="badge badge-cyan" style="justify-content:space-between;display:flex;padding:10px 12px">Teaching Load Need <strong><?= number_format((int)$sum['teachers_needed_load']) ?></strong></div>
            <div class="badge badge-orange" style="justify-content:space-between;display:flex;padding:10px 12px">Class Assignment Need <strong><?= number_format((int)$sum['teachers_needed_classes']) ?></strong></div>
            <div class="badge badge-green" style="justify-content:space-between;display:flex;padding:12px">Recommended Teachers <strong><?= number_format((int)$sum['recommended_teachers']) ?></strong></div>
        </div>
        <div style="margin-top:12px">
            <?php foreach ($plan['recommendations'] as $rec): ?>
            <div style="padding:8px 10px;border:1px solid rgba(148,163,184,.2);border-radius:10px;margin-bottom:8px;background:rgba(15,23,42,.35)">
                <i class="fas fa-lightbulb" style="color:#fbbf24"></i> <?= clean($rec) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div id="teacherWorkloadCard" class="table-card glass-card" style="margin-top:12px;">
    <div class="card-header workload-header">
        <h3><i class="fas fa-chalkboard-teacher"></i> Teacher Workload Dashboard</h3>
        <span class="badge badge-cyan workload-count"><?= number_format(count($plan['teacher_rows'])) ?> teachers</span>
    </div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="save_teacher_workload" value="1">
        <input type="hidden" name="school" value="<?= urlencode(encryptId($schoolId)) ?>">
    <div class="workload-toolbar">
        <div class="workload-toolbar-actions">
            <button type="button" class="btn btn-secondary" id="openTeacherFilterModal"><i class="fas fa-filter"></i> Filter Teachers</button>
            <span id="planningDirtyBadge" class="badge badge-orange" style="display:none;">Unsaved changes</span>
        </div>
    </div>

    <div id="teacherFilterModal" class="planning-modal" aria-hidden="true">
        <div class="planning-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="teacherFilterModalTitle">
            <div class="planning-modal-header">
                <h4 id="teacherFilterModalTitle"><i class="fas fa-filter"></i> Teacher Filters</h4>
                <button type="button" class="planning-modal-close" id="closeTeacherFilterModal" aria-label="Close">&times;</button>
            </div>
            <div class="planning-modal-body">
                <div class="workload-filter-grid">
                    <div class="workload-filter-field">
                        <label class="form-label" for="teacherFilterInput">Search Teacher</label>
                        <input type="text" id="teacherFilterInput" class="form-input" placeholder="Search name, position, specialization, subject...">
                    </div>
                    <div class="workload-filter-field">
                        <label class="form-label" for="teacherStatusFilter">Status</label>
                        <select id="teacherStatusFilter" class="form-select">
                            <option value="all">All Status</option>
                            <option value="overloaded">Overloaded</option>
                            <option value="full load">Full Load</option>
                            <option value="available">Available</option>
                        </select>
                    </div>
                    <div class="workload-filter-field">
                        <label class="form-label" for="teacherRetirementFilter">Retirement</label>
                        <select id="teacherRetirementFilter" class="form-select">
                            <option value="all">All</option>
                            <option value="possible retiree">Possible Retiree (60+)</option>
                            <option value="near retirement">Near Retirement (55-59)</option>
                            <option value="not near retirement">Not Near Retirement</option>
                        </select>
                    </div>
                    <div class="workload-filter-field">
                        <label class="form-label" for="teacherSubjectFilter">Subject</label>
                        <select id="teacherSubjectFilter" class="form-select">
                            <option value="all">All Subjects</option>
                            <?php foreach ($subjectFilterOptions as $subjectValue => $subjectLabel): ?>
                            <option value="<?= clean((string)$subjectValue) ?>"><?= clean((string)$subjectLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="planning-modal-footer">
                <button type="button" class="btn btn-secondary" id="closeTeacherFilterModalFooter">Close</button>
            </div>
        </div>
    </div>
    <div class="planning-live-kpis workload-live-kpis">
        <div class="workload-kpi workload-kpi-blue"><span>Total Current Load</span><strong id="liveTotalLoad">0.0</strong></div>
        <div class="workload-kpi workload-kpi-cyan"><span>Handled Classes</span><strong id="liveTotalClasses">0</strong></div>
        <div class="workload-kpi workload-kpi-green"><span>Handled Students</span><strong id="liveTotalStudents">0</strong></div>
        <div class="workload-kpi workload-kpi-red"><span>Possible Retirees</span><strong id="livePossibleRetirees">0</strong></div>
        <div class="workload-kpi workload-kpi-orange"><span>Visible Teachers</span><strong id="liveVisibleTeachers">0</strong></div>
    </div>
    <div class="table-scroll">
        <table class="data-table workload-table">
            <thead>
                <tr>
                    <th>Teacher</th>
                    <th>Position</th>
                    <th>Specialization</th>
                    <th>Subjects</th>
                    <th>Current Load (hrs)</th>
                    <th>Max Load</th>
                    <th>Handled Class(es)</th>
                    <th>Max Classes</th>
                    <th>Students Handled</th>
                    <th>Age</th>
                    <th>Retirement</th>
                    <th>Utilization</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plan['teacher_rows'] as $tr): ?>
                <?php $teacherId = (int)($tr['id'] ?? 0); ?>
                <tr class="planning-row"
                    data-text="<?= htmlspecialchars(strtolower(trim((string)(($tr['name'] ?? '') . ' ' . ($tr['position'] ?? '') . ' ' . ($tr['specialization'] ?? '') . ' ' . ($tr['subjects'] ?? '')))), ENT_QUOTES, 'UTF-8') ?>"
                    data-subjects="<?= htmlspecialchars(strtolower(trim((string)($tr['subjects'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"
                    data-retirement="<?= htmlspecialchars(strtolower((string)($tr['retirement_risk'] ?? 'not near retirement')), ENT_QUOTES, 'UTF-8') ?>">
                    <td><?= clean($tr['name']) ?></td>
                    <td><?= clean($tr['position'] ?: '—') ?></td>
                    <td><?= clean($tr['specialization'] ?: '—') ?></td>
                    <td><?= clean($tr['subjects'] ?: '—') ?></td>
                    <td>
                        <input type="number" name="current_teaching_load_hours[<?= $teacherId ?>]" class="form-input planning-input planning-current-load"
                               min="0" max="80" step="0.5" value="<?= clean((string)$tr['current_load']) ?>" style="min-width:110px;">
                    </td>
                    <td>
                        <input type="number" name="max_teaching_load_hours[<?= $teacherId ?>]" class="form-input planning-input planning-max-load"
                               min="1" max="80" step="0.5" value="<?= clean((string)$tr['max_load']) ?>" style="min-width:110px;">
                    </td>
                    <td>
                        <input type="number" name="classes_handled[<?= $teacherId ?>]" class="form-input planning-input planning-classes-handled"
                               min="0" max="40" step="1" value="<?= clean((string)$tr['classes_handled']) ?>" style="min-width:110px;">
                    </td>
                    <td>
                        <input type="number" name="max_classes[<?= $teacherId ?>]" class="form-input planning-input planning-max-classes"
                               min="1" max="40" step="1" value="<?= clean((string)$tr['max_classes']) ?>" style="min-width:110px;">
                    </td>
                    <td>
                        <input type="number" name="students_handled[<?= $teacherId ?>]" class="form-input planning-input planning-students-handled"
                               min="0" max="500" step="1" value="<?= clean((string)($tr['students_handled'] ?? 0)) ?>" style="min-width:120px;">
                    </td>
                    <td><?= $tr['age'] !== null ? number_format((int)$tr['age']) : 'N/A' ?></td>
                    <td>
                        <?php
                            $retirementRisk = (string)($tr['retirement_risk'] ?? 'Not Near Retirement');
                            $retirementBadge = $retirementRisk === 'Possible Retiree'
                                ? 'badge-danger'
                                : ($retirementRisk === 'Near Retirement' ? 'badge-orange' : 'badge-green');
                        ?>
                        <span class="badge <?= $retirementBadge ?>"><?= clean($retirementRisk) ?></span>
                    </td>
                    <td><span class="planning-utilization"><?= number_format((float)$tr['utilization_pct'], 1) ?>%</span></td>
                    <td class="planning-status-cell">
                        <?php
                            $status = (string)$tr['status'];
                            $badge = $status === 'Overloaded' ? 'badge-danger' : ($status === 'Full Load' ? 'badge-blue' : 'badge-green');
                        ?>
                        <span class="badge <?= $badge ?>"><?= clean($status) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($plan['teacher_rows'])): ?>
                <tr><td colspan="13" class="text-center text-muted">No teacher records are linked to this school.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (!empty($plan['teacher_rows']) && canEdit()): ?>
    <div class="workload-footer-actions" style="padding:10px 12px;display:flex;justify-content:flex-end;">
        <button type="submit" class="btn btn-primary planning-btn-workload"><i class="fas fa-save"></i> Save Teacher Workload Inputs</button>
    </div>
    <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<style>
.planning-stat-click {
    cursor: pointer;
    transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
}
.planning-stat-click:hover,
.planning-stat-click:focus-visible {
    transform: translateY(-2px);
    border-color: rgba(56, 189, 248, .45);
    box-shadow: 0 10px 22px rgba(2, 132, 199, .18);
}
.planning-settings-trigger-wrap {
    display: flex;
    justify-content: flex-end;
}
.btn.planning-btn-settings-trigger {
    background: linear-gradient(135deg, rgba(234,88,12,.95), rgba(194,65,12,.95));
    border: 1px solid rgba(154,52,18,.9);
    color: #ffffff;
    font-weight: 600;
    box-shadow: 0 8px 20px rgba(194,65,12,.25);
}
.btn.planning-btn-settings-trigger:hover {
    background: linear-gradient(135deg, rgba(194,65,12,.98), rgba(154,52,18,.98));
}
.planning-primary-grid {
    display: grid;
    grid-template-columns: 1.2fr 1fr;
    gap: 12px;
}
.planning-card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.planning-card-head h3 {
    margin: 0;
    font-size: 1rem;
    color: #f1f5f9;
    display: flex;
    align-items: center;
    gap: 8px;
}
.planning-school-card {
    background: linear-gradient(160deg, rgba(30, 41, 59, .65), rgba(15, 23, 42, .45));
}
.planning-parameter-card {
    background: linear-gradient(160deg, rgba(6, 78, 59, .35), rgba(15, 23, 42, .5));
}
.planning-parameter-form {
    display: grid;
    grid-template-columns: minmax(170px, 1fr) auto;
    align-items: end;
    gap: 10px;
}
.planning-settings-intro {
    margin-top: 0;
    padding: 10px;
    border-radius: 10px;
    background: rgba(2, 132, 199, .12);
    border: 1px solid rgba(56, 189, 248, .25);
    color: #dbeafe;
    font-size: .9rem;
}
.planning-settings-form {
    display: grid;
    gap: 12px;
}
.planning-settings-group {
    border: 1px solid rgba(148, 163, 184, .22);
    border-radius: 10px;
    padding: 10px;
    background: rgba(15, 23, 42, .2);
}
.planning-settings-group h4 {
    margin: 0 0 8px;
    font-size: .95rem;
    color: #e2e8f0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.planning-settings-group h4 i {
    color: #38bdf8;
}
.planning-settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 10px;
}
.planning-settings-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 4px;
}
.planning-settings-actions .btn {
    min-width: 220px;
}
.workload-header {
    align-items: center;
}
.workload-count {
    font-weight: 600;
}
.workload-toolbar {
    padding: 12px;
    border-top: 1px solid rgba(148,163,184,.2);
    border-bottom: 1px solid rgba(148,163,184,.2);
    background: rgba(15, 23, 42, .28);
    display: grid;
    gap: 10px;
}
.planning-modal {
    position: fixed;
    inset: 0;
    background: rgba(2, 6, 23, .7);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
    z-index: 1200;
}
.planning-modal.is-open {
    display: flex;
}
.planning-modal-dialog {
    width: min(680px, 100%);
    border: 1px solid rgba(148,163,184,.3);
    border-radius: 14px;
    background: linear-gradient(165deg, rgba(30, 41, 59, .96), rgba(15, 23, 42, .96));
    box-shadow: 0 22px 50px rgba(2, 6, 23, .55);
}
.planning-modal-dialog-wide {
    width: min(920px, 100%);
}
.planning-modal-header,
.planning-modal-footer {
    padding: 12px 14px;
    border-bottom: 1px solid rgba(148,163,184,.2);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.planning-modal-footer {
    border-top: 1px solid rgba(148,163,184,.2);
    border-bottom: 0;
    justify-content: flex-end;
}
.planning-modal-header h4 {
    margin: 0;
    color: #f1f5f9;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.planning-modal-body {
    padding: 14px;
}
.planning-modal-close {
    border: 0;
    background: transparent;
    color: #cbd5e1;
    font-size: 1.4rem;
    cursor: pointer;
    line-height: 1;
}
.planning-modal-close:hover {
    color: #f8fafc;
}
.workload-filter-grid {
    display: grid;
    grid-template-columns: minmax(260px, 1.3fr) minmax(160px, .7fr) minmax(180px, .8fr);
    gap: 10px;
    align-items: end;
}
.workload-filter-field {
    display: grid;
    gap: 6px;
}
.workload-toolbar-actions {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
}
.workload-live-kpis {
    padding: 10px 12px;
    display: grid;
    grid-template-columns: repeat(4, minmax(150px, 1fr));
    gap: 8px;
}
.workload-kpi {
    border-radius: 10px;
    border: 1px solid rgba(148,163,184,.26);
    padding: 8px 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}
.workload-kpi span {
    font-size: .82rem;
    color: #dbeafe;
}
.workload-kpi strong {
    font-size: .96rem;
    color: #f8fafc;
}
.workload-kpi-blue { background: rgba(37, 99, 235, .18); }
.workload-kpi-cyan { background: rgba(8, 145, 178, .18); }
.workload-kpi-green { background: rgba(22, 163, 74, .18); }
.workload-kpi-red { background: rgba(220, 38, 38, .18); }
.workload-kpi-orange { background: rgba(234, 88, 12, .18); }
.workload-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
}
.workload-table tbody tr:nth-child(even) {
    background: rgba(148,163,184,.06);
}
.workload-footer-actions {
    border-top: 1px solid rgba(148,163,184,.2);
}
.btn.planning-btn-student {
    background: linear-gradient(135deg, #16a34a, #15803d);
    border-color: #166534;
    color: #ffffff;
}
.btn.planning-btn-student:hover {
    background: linear-gradient(135deg, #15803d, #166534);
}
.btn.planning-btn-settings {
    background: linear-gradient(135deg, #ea580c, #c2410c);
    border-color: #9a3412;
    color: #ffffff;
}
.btn.planning-btn-settings:hover {
    background: linear-gradient(135deg, #c2410c, #9a3412);
}
.btn.planning-btn-inputs {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    border-color: #1e40af;
    color: #ffffff;
}
.btn.planning-btn-inputs:hover {
    background: linear-gradient(135deg, #1d4ed8, #1e40af);
}
.btn.planning-btn-workload {
    background: linear-gradient(135deg, #0891b2, #0e7490);
    border-color: #155e75;
    color: #ffffff;
}
.btn.planning-btn-workload:hover {
    background: linear-gradient(135deg, #0e7490, #155e75);
}
@media (max-width: 900px) {
    .planning-primary-grid { grid-template-columns: 1fr; }
    .planning-parameter-form { grid-template-columns: 1fr; }
    .planning-settings-trigger-wrap { justify-content: stretch; }
    .planning-btn-settings-trigger { width: 100%; }
    .workload-filter-grid { grid-template-columns: 1fr; }
    .workload-toolbar-actions { justify-content: stretch; }
    .workload-toolbar-actions .btn { width: 100%; }
    .workload-live-kpis { grid-template-columns: repeat(2,minmax(120px,1fr)); }
    .planning-settings-actions .btn { min-width: 100%; }
}
</style>

<?php if ($plan): ?>
<script>
(function () {
    var threshold = <?= json_encode((float)$settings['utilization_threshold_pct']) ?>;
    var filterInput = document.getElementById('teacherFilterInput');
    var statusFilter = document.getElementById('teacherStatusFilter');
    var retirementFilter = document.getElementById('teacherRetirementFilter');
    var subjectFilter = document.getElementById('teacherSubjectFilter');
    var dirtyBadge = document.getElementById('planningDirtyBadge');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.planning-row'));
    var inputs = Array.prototype.slice.call(document.querySelectorAll('.planning-input'));
    var totalLoadEl = document.getElementById('liveTotalLoad');
    var totalClassesEl = document.getElementById('liveTotalClasses');
    var totalStudentsEl = document.getElementById('liveTotalStudents');
    var possibleRetireesEl = document.getElementById('livePossibleRetirees');
    var visibleTeachersEl = document.getElementById('liveVisibleTeachers');
    var teacherFilterModal = document.getElementById('teacherFilterModal');
    var openTeacherFilterModalBtn = document.getElementById('openTeacherFilterModal');
    var closeTeacherFilterModalBtn = document.getElementById('closeTeacherFilterModal');
    var closeTeacherFilterModalFooterBtn = document.getElementById('closeTeacherFilterModalFooter');

    function openTeacherFilterModal() {
        if (!teacherFilterModal) return;
        teacherFilterModal.classList.add('is-open');
        teacherFilterModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (filterInput) filterInput.focus();
    }

    function closeTeacherFilterModal() {
        if (!teacherFilterModal) return;
        teacherFilterModal.classList.remove('is-open');
        teacherFilterModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    if (openTeacherFilterModalBtn) {
        openTeacherFilterModalBtn.addEventListener('click', openTeacherFilterModal);
    }
    if (closeTeacherFilterModalBtn) {
        closeTeacherFilterModalBtn.addEventListener('click', closeTeacherFilterModal);
    }
    if (closeTeacherFilterModalFooterBtn) {
        closeTeacherFilterModalFooterBtn.addEventListener('click', closeTeacherFilterModal);
    }
    if (teacherFilterModal) {
        teacherFilterModal.addEventListener('click', function (event) {
            if (event.target === teacherFilterModal) {
                closeTeacherFilterModal();
            }
        });
    }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && teacherFilterModal && teacherFilterModal.classList.contains('is-open')) {
            closeTeacherFilterModal();
        }
    });

    function n(v) {
        var x = parseFloat(v);
        return isNaN(x) ? 0 : x;
    }

    function updateRowState(row) {
        var currentLoadInput = row.querySelector('.planning-current-load');
        var maxLoadInput = row.querySelector('.planning-max-load');
        var classesHandledInput = row.querySelector('.planning-classes-handled');
        var maxClassesInput = row.querySelector('.planning-max-classes');

        var currentLoad = n(currentLoadInput ? currentLoadInput.value : 0);
        var maxLoad = Math.max(1, n(maxLoadInput ? maxLoadInput.value : 0));
        var classesHandled = n(classesHandledInput ? classesHandledInput.value : 0);
        var maxClasses = Math.max(1, n(maxClassesInput ? maxClassesInput.value : 0));
        var utilization = maxLoad > 0 ? (currentLoad / maxLoad) * 100 : 0;

        var status = 'Available';
        var badgeClass = 'badge-green';
        if (currentLoad > maxLoad || classesHandled > maxClasses) {
            status = 'Overloaded';
            badgeClass = 'badge-danger';
        } else if (utilization >= threshold) {
            status = 'Full Load';
            badgeClass = 'badge-blue';
        }

        var utilEl = row.querySelector('.planning-utilization');
        if (utilEl) {
            utilEl.textContent = utilization.toFixed(1) + '%';
        }

        var statusCell = row.querySelector('.planning-status-cell');
        if (statusCell) {
            statusCell.innerHTML = '<span class="badge ' + badgeClass + '">' + status + '</span>';
            row.setAttribute('data-status', status.toLowerCase());
        }
    }

    function applyFilters() {
        var q = ((filterInput && filterInput.value) || '').toLowerCase().trim();
        var sf = ((statusFilter && statusFilter.value) || 'all').toLowerCase();
        var rf = ((retirementFilter && retirementFilter.value) || 'all').toLowerCase();
        var subj = ((subjectFilter && subjectFilter.value) || 'all').toLowerCase();
        var visible = 0;

        rows.forEach(function (row) {
            var text = String(row.getAttribute('data-text') || '');
            var status = String(row.getAttribute('data-status') || '');
            var retirement = String(row.getAttribute('data-retirement') || 'not near retirement');
            var subjects = String(row.getAttribute('data-subjects') || '');
            var passQ = q === '' || text.indexOf(q) !== -1;
            var passS = sf === 'all' || status === sf;
            var passR = rf === 'all' || retirement === rf;
            var passSubj = subj === 'all' || subjects.indexOf(subj) !== -1;
            var show = passQ && passS && passR && passSubj;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (visibleTeachersEl) {
            visibleTeachersEl.textContent = String(visible);
        }
    }

    function updateTotals() {
        var totalLoad = 0;
        var totalClasses = 0;
        var totalStudents = 0;
        var possibleRetirees = 0;

        rows.forEach(function (row) {
            if (row.style.display === 'none') return;
            var currentLoadInput = row.querySelector('.planning-current-load');
            var classesHandledInput = row.querySelector('.planning-classes-handled');
            var studentsHandledInput = row.querySelector('.planning-students-handled');
            var retirement = String(row.getAttribute('data-retirement') || 'not near retirement');

            totalLoad += n(currentLoadInput ? currentLoadInput.value : 0);
            totalClasses += n(classesHandledInput ? classesHandledInput.value : 0);
            totalStudents += n(studentsHandledInput ? studentsHandledInput.value : 0);
            if (retirement === 'possible retiree') possibleRetirees++;
        });

        if (totalLoadEl) totalLoadEl.textContent = totalLoad.toFixed(1);
        if (totalClassesEl) totalClassesEl.textContent = String(Math.round(totalClasses));
        if (totalStudentsEl) totalStudentsEl.textContent = String(Math.round(totalStudents));
        if (possibleRetireesEl) possibleRetireesEl.textContent = String(possibleRetirees);
    }

    function refreshAll() {
        rows.forEach(updateRowState);
        applyFilters();
        updateTotals();
    }

    if (filterInput) {
        filterInput.addEventListener('input', function () {
            applyFilters();
            updateTotals();
        });
    }
    if (statusFilter) {
        statusFilter.addEventListener('change', function () {
            applyFilters();
            updateTotals();
        });
    }
    if (retirementFilter) {
        retirementFilter.addEventListener('change', function () {
            applyFilters();
            updateTotals();
        });
    }
    if (subjectFilter) {
        subjectFilter.addEventListener('change', function () {
            applyFilters();
            updateTotals();
        });
    }

    inputs.forEach(function (el) {
        el.addEventListener('input', function () {
            dirtyBadge.style.display = '';
            var row = el.closest('.planning-row');
            if (row) updateRowState(row);
            applyFilters();
            updateTotals();
        });
    });

    refreshAll();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php';
