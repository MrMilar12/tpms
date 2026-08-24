<?php
$pageTitle = 'Bulk Upload';
require_once __DIR__ . '/includes/header.php';

// Require user to have selected a role
requireRoleSelection();

requireRole(['admin', 'hr']);

$uploadHistory = getDB()->query(
    'SELECT u.*, us.full_name AS uploader_name FROM upload_logs u
     LEFT JOIN users us ON u.uploaded_by = us.id
     ORDER BY u.created_at DESC LIMIT 20'
)->fetchAll();
?>

<div class="upload-page">

    <!-- Tab Bar -->
    <div class="upload-tabs">
        <button class="upload-tab active" data-tab="teachers"><i class="fas fa-chalkboard-teacher"></i> Teachers</button>
        <button class="upload-tab" data-tab="schools"><i class="fas fa-school"></i> Schools</button>
    </div>

    <!-- ═══════════════ TEACHERS PANEL ═══════════════ -->
    <div id="panel-teachers" class="upload-panel">
    <!-- Instructions Card -->
    <div class="upload-info glass-card">
        <div class="card-header">
            <h3><i class="fas fa-info-circle"></i> Upload Instructions</h3>
        </div>
        <div class="upload-steps">
            <div class="step"><div class="step-num">1</div><div class="step-text"><strong>Prepare your file</strong><br>Use Excel (.xlsx) or CSV format with the exact required headers.</div></div>
            <div class="step"><div class="step-num">2</div><div class="step-text"><strong>Required Headers</strong><br>All headers listed below must be present.</div></div>
            <div class="step"><div class="step-num">3</div><div class="step-text"><strong>Upload & Validate</strong><br>System will check headers and preview data before saving.</div></div>
            <div class="step"><div class="step-num">4</div><div class="step-text"><strong>Confirm</strong><br>Review the preview, then click <em>Import</em> to save all records.</div></div>
        </div>

        <div class="required-headers">
            <strong>Minimum Required Columns:</strong>
            <div class="header-chips">
                <?php foreach (REQUIRED_UPLOAD_HEADERS as $h): ?>
                <span class="chip" style="background:rgba(16,185,129,.18);border-color:rgba(16,185,129,.3);color:#6ee7b7"><?= clean($h) ?></span>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:10px;font-size:12px;color:var(--text-muted)">
                <i class="fas fa-info-circle" style="color:var(--primary)"></i>
                Also supports <strong>Google Form responses</strong> (XLSX export) — headers are matched flexibly (case-insensitive).
                Additional mapped columns: Date of Birth, Gender, PWD Status, Position, Grade Level, Specialization, and school matching via <strong>School ID Code</strong> or <strong>School Name and ID</strong>.
            </div>
        </div>

        <a href="<?= APP_URL ?>/assets/templates/upload_template.csv" class="btn btn-ghost btn-sm" download>
            <i class="fas fa-download"></i> Download Template
        </a>
    </div>

    <!-- Upload Form -->
    <div class="upload-form-card glass-card">
        <div class="card-header">
            <h3><i class="fas fa-file-upload"></i> Upload File</h3>
        </div>
        <form method="POST" action="<?= APP_URL ?>/actions/process_upload.php"
              enctype="multipart/form-data" id="uploadForm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="drop-zone" id="dropZone">
                <div class="drop-zone-content">
                    <i class="fas fa-cloud-upload-alt fa-4x"></i>
                    <p class="drop-zone-text">Drag & drop your file here</p>
                    <p class="drop-zone-sub">or</p>
                    <label class="btn btn-primary">
                        <i class="fas fa-folder-open"></i> Browse File
                        <input type="file" name="upload_file" id="uploadFile"
                               accept=".xlsx,.csv" style="display:none" required>
                    </label>
                    <p class="drop-zone-info">Supported: .xlsx, .csv · Max 20 MB</p>
                </div>
                <div class="file-selected" id="fileSelected" style="display:none">
                    <i class="fas fa-file-excel file-icon"></i>
                    <span id="fileName"></span>
                    <button type="button" class="btn btn-sm btn-ghost" id="clearFile">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <div class="upload-options">
                <label class="checkbox-label">
                    <input type="checkbox" name="skip_duplicates" value="1" checked>
                    <span>Skip duplicate employee numbers (recommended)</span>
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="update_existing" value="1">
                    <span>Update existing records (overwrite)</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-lg btn-full" id="uploadBtn" disabled>
                <i class="fas fa-upload"></i> Validate & Import
            </button>
        </form>
    </div>
    </div><!-- /panel-teachers -->

    <!-- ═══════════════ SCHOOLS PANEL ═══════════════ -->
    <div id="panel-schools" class="upload-panel" style="display:none">

    <!-- Schools Instructions -->
    <div class="upload-info glass-card">
        <div class="card-header">
            <h3><i class="fas fa-info-circle"></i> School Upload Instructions</h3>
        </div>
        <div class="upload-steps">
            <div class="step"><div class="step-num">1</div><div class="step-text"><strong>Prepare your file</strong><br>Use Excel (.xlsx) or CSV with the required headers below.</div></div>
            <div class="step"><div class="step-num">2</div><div class="step-text"><strong>District auto-creation</strong><br>If a District Name is provided and not yet in the system, it will be created automatically.</div></div>
            <div class="step"><div class="step-num">3</div><div class="step-text"><strong>Duplicates</strong><br>Schools are matched by School ID Code. Use the options below to skip or overwrite existing records.</div></div>
        </div>
        <div class="required-headers">
            <strong>Required Columns:</strong>
            <div class="header-chips">
                <span class="chip" style="background:rgba(16,185,129,.18);border-color:rgba(16,185,129,.3);color:#6ee7b7">School Name</span>
                <span class="chip" style="background:rgba(16,185,129,.18);border-color:rgba(16,185,129,.3);color:#6ee7b7">School ID Code</span>
            </div>
            <div style="margin-top:10px;font-size:12px;color:var(--text-muted)">
                <i class="fas fa-info-circle" style="color:var(--primary)"></i>
                Also accepts common variants like <strong>Name of School</strong>, <strong>School ID No.</strong>, and <strong>District</strong>.
                Optional columns like <strong>Municipality</strong>, <strong>School Type</strong>, and <strong>ALS Subtype</strong> are supported.
                Supported School Type values: <strong>Public</strong>, <strong>Private</strong>, <strong>ALS</strong>, <strong>Elementary</strong>, <strong>JHS</strong>, <strong>SHS</strong>.
            </div>
        </div>
        <a href="<?= APP_URL ?>/assets/templates/school_upload_template.csv" class="btn btn-ghost btn-sm" download>
            <i class="fas fa-download"></i> Download School Template
        </a>
    </div>

    <!-- Schools Upload Form -->
    <div class="upload-form-card glass-card">
        <div class="card-header">
            <h3><i class="fas fa-file-upload"></i> Upload Schools File</h3>
        </div>
        <form method="POST" action="<?= APP_URL ?>/actions/process_school_upload.php"
              enctype="multipart/form-data" id="schoolUploadForm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="drop-zone" id="schoolDropZone">
                <div class="drop-zone-content" id="schoolDropContent">
                    <i class="fas fa-cloud-upload-alt fa-4x"></i>
                    <p class="drop-zone-text">Drag & drop your file here</p>
                    <p class="drop-zone-sub">or</p>
                    <label class="btn btn-primary">
                        <i class="fas fa-folder-open"></i> Browse File
                        <input type="file" name="upload_file" id="schoolUploadFile"
                               accept=".xlsx,.csv,.xls" style="display:none" required>
                    </label>
                    <p class="drop-zone-info">Supported: .xlsx, .csv &middot; Max 20 MB</p>
                </div>
                <div class="file-selected" id="schoolFileSelected" style="display:none">
                    <i class="fas fa-file-excel file-icon"></i>
                    <span id="schoolFileName"></span>
                    <button type="button" class="btn btn-sm btn-ghost" id="clearSchoolFile">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="upload-options">
                <label class="checkbox-label">
                    <input type="checkbox" name="skip_duplicates" value="1" checked>
                    <span>Skip duplicate School ID Codes (recommended)</span>
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="update_existing" value="1">
                    <span>Update existing school records (overwrite)</span>
                </label>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-full" id="schoolUploadBtn" disabled>
                <i class="fas fa-upload"></i> Validate & Import
            </button>
        </form>
    </div>
    </div><!-- /panel-schools -->

    <!-- Upload History -->
    <div class="table-card glass-card">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Upload History</h3>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th class="text-center">Total</th>
                        <th class="text-center">Imported</th>
                        <th class="text-center">Skipped</th>
                        <th class="text-center">Errors</th>
                        <th>By</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($uploadHistory as $u): ?>
                <tr>
                    <td><?= clean($u['file_name']) ?></td>
                    <td class="text-center"><?= number_format((int)$u['total_rows']) ?></td>
                    <td class="text-center"><span class="badge badge-green"><?= number_format((int)$u['imported_rows']) ?></span></td>
                    <td class="text-center"><span class="badge badge-orange"><?= number_format((int)$u['skipped_rows']) ?></span></td>
                    <td class="text-center"><span class="badge badge-red"><?= number_format((int)$u['error_rows']) ?></span></td>
                    <td><?= clean($u['uploader_name'] ?? '—') ?></td>
                    <td><?= formatDate($u['created_at'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$uploadHistory): ?>
                <tr><td colspan="7" class="text-center text-muted">No uploads yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// ── File upload UX ──────────────────────────────────────────
const dropZone   = document.getElementById('dropZone');
const fileInput  = document.getElementById('uploadFile');
const fileSelected = document.getElementById('fileSelected');
const fileName   = document.getElementById('fileName');
const uploadBtn  = document.getElementById('uploadBtn');
const clearBtn   = document.getElementById('clearFile');

function setFile(file) {
    if (!file) return;
    fileName.textContent = file.name;
    fileSelected.style.display = 'flex';
    dropZone.querySelector('.drop-zone-content').style.display = 'none';
    uploadBtn.disabled = false;
}

fileInput.addEventListener('change', () => setFile(fileInput.files[0]));

clearBtn.addEventListener('click', () => {
    fileInput.value = '';
    fileSelected.style.display = 'none';
    dropZone.querySelector('.drop-zone-content').style.display = 'flex';
    uploadBtn.disabled = true;
});

['dragover','dragenter'].forEach(ev => dropZone.addEventListener(ev, e => {
    e.preventDefault(); dropZone.classList.add('dragover');
}));
['dragleave','drop'].forEach(ev => dropZone.addEventListener(ev, e => {
    e.preventDefault(); dropZone.classList.remove('dragover');
    if (ev === 'drop' && e.dataTransfer.files.length) {
        const dt = new DataTransfer();
        dt.items.add(e.dataTransfer.files[0]);
        fileInput.files = dt.files;
        setFile(e.dataTransfer.files[0]);
    }
}));

document.getElementById('uploadForm').addEventListener('submit', function() {
    uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';
    uploadBtn.disabled = true;
});

// ── School upload UX ────────────────────────────────────────
const schoolDropZone    = document.getElementById('schoolDropZone');
const schoolFileInput   = document.getElementById('schoolUploadFile');
const schoolFileSelected = document.getElementById('schoolFileSelected');
const schoolFileName    = document.getElementById('schoolFileName');
const schoolUploadBtn   = document.getElementById('schoolUploadBtn');
const schoolDropContent = document.getElementById('schoolDropContent');

function setSchoolFile(file) {
    if (!file) return;
    schoolFileName.textContent = file.name;
    schoolFileSelected.style.display = 'flex';
    schoolDropContent.style.display = 'none';
    schoolUploadBtn.disabled = false;
}

schoolFileInput.addEventListener('change', () => setSchoolFile(schoolFileInput.files[0]));

document.getElementById('clearSchoolFile').addEventListener('click', () => {
    schoolFileInput.value = '';
    schoolFileSelected.style.display = 'none';
    schoolDropContent.style.display = 'flex';
    schoolUploadBtn.disabled = true;
});

['dragover','dragenter'].forEach(ev => schoolDropZone.addEventListener(ev, e => {
    e.preventDefault(); schoolDropZone.classList.add('dragover');
}));
['dragleave','drop'].forEach(ev => schoolDropZone.addEventListener(ev, e => {
    e.preventDefault(); schoolDropZone.classList.remove('dragover');
    if (ev === 'drop' && e.dataTransfer.files.length) {
        const dt = new DataTransfer();
        dt.items.add(e.dataTransfer.files[0]);
        schoolFileInput.files = dt.files;
        setSchoolFile(e.dataTransfer.files[0]);
    }
}));

document.getElementById('schoolUploadForm').addEventListener('submit', function() {
    schoolUploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';
    schoolUploadBtn.disabled = true;
});

// ── Tab switching ───────────────────────────────────────────
const tabs   = document.querySelectorAll('.upload-tab');
const panels = document.querySelectorAll('.upload-panel');

function switchTab(tabName) {
    tabs.forEach(t => t.classList.toggle('active', t.dataset.tab === tabName));
    panels.forEach(p => p.style.display = p.id === 'panel-' + tabName ? '' : 'none');
    localStorage.setItem('uploadTab', tabName);
}

tabs.forEach(t => t.addEventListener('click', () => switchTab(t.dataset.tab)));

// Restore last tab (or use URL hash)
const savedTab = location.hash === '#schools' ? 'schools'
               : (localStorage.getItem('uploadTab') || 'teachers');
switchTab(savedTab);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
