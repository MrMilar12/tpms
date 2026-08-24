<?php
$pageTitle = 'ALS Centers';
require_once __DIR__ . '/includes/header.php';

// Require user to have selected a role
requireRoleSelection();

$db     = getDB();
$search = clean(trim($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));

// Input length validation
if (strlen($search) > 500) {
    flash('error', 'Search term is too long.');
    redirect(APP_URL . '/als');
}

$subtype = strtolower(trim($_GET['subtype'] ?? 'all'));
$allowedSubtypes = ['all', 'cblc', 'sblc', 'als-shs'];
if (!in_array($subtype, $allowedSubtypes, true)) {
    $subtype = 'all';
}

$conditions = [];
$params = [];

// Filter by ALS type
$conditions[] = "LOWER(COALESCE(s.school_type, '')) = 'als'";

if ($search !== '') {
    $conditions[] = '(s.school_name LIKE ? OR d.district_name LIKE ? OR s.school_id_code LIKE ? OR s.municipality LIKE ? OR s.als_subtype LIKE ?)';
    $params = array_merge($params, array_fill(0, 5, '%' . $search . '%'));
}

if ($subtype !== 'all') {
    $conditions[] = "LOWER(COALESCE(s.als_subtype, '')) = ?";
    $params[] = $subtype;
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Count by subtype
$subtypeCounts = ['all' => 0, 'cblc' => 0, 'sblc' => 0, 'als-shs' => 0];
foreach ($db->query("SELECT LOWER(COALESCE(als_subtype, '')) AS st, COUNT(*) AS c FROM schools WHERE LOWER(COALESCE(school_type, '')) = 'als' GROUP BY st") as $r) {
    $subKey = strtolower($r['st'] ?? '');
    if ($subKey === 'cblc') {
        $subtypeCounts['cblc'] = (int)$r['c'];
    } elseif ($subKey === 'sblc') {
        $subtypeCounts['sblc'] = (int)$r['c'];
    } elseif ($subKey === 'als-shs') {
        $subtypeCounts['als-shs'] = (int)$r['c'];
    }
    $subtypeCounts['all'] += (int)$r['c'];
}

$total  = $db->prepare("SELECT COUNT(*) FROM schools s LEFT JOIN districts d ON s.district_id = d.id $where");
$total->execute($params);
$total  = (int)$total->fetchColumn();
$pag    = paginate($total, $page);

$stmt = $db->prepare(
    "SELECT s.*, d.district_name AS district,
            (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id) AS teacher_count
     FROM schools s
     LEFT JOIN districts d ON s.district_id = d.id
     $where ORDER BY s.als_subtype, s.school_name LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$pag['per_page'], $pag['offset']]));
$centers = $stmt->fetchAll();
?>

<div class="filter-bar glass-card">
    <form method="GET" class="filter-form">
        <?php if ($subtype !== 'all'): ?>
        <input type="hidden" name="subtype" value="<?= clean($subtype) ?>">
        <?php endif; ?>
        <div class="search-box">
            <i class="fas fa-search search-icon"></i>
            <input type="text" name="q" class="form-input" placeholder="Search ALS centers…" value="<?= clean($search) ?>">
        </div>
        <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
        <?php if ($search): ?>
        <a href="<?= APP_URL ?>/als.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
        <?php endif; ?>
    </form>
    <?php if (canEdit()): ?>
    <div class="filter-actions">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('bulkUploadAlsModal').style.display='flex'">
            <i class="fas fa-file-upload"></i> Bulk Upload
        </button>
        <button class="btn btn-primary" onclick="document.getElementById('addAlsModal').style.display='flex'">
            <i class="fas fa-plus"></i> Add ALS Center
        </button>
    </div>
    <?php endif; ?>
</div>

<div class="filter-bar glass-card" style="padding:10px 14px;justify-content:flex-end">
    <div class="filter-actions" style="margin-left:auto">
        <button type="button" class="btn btn-ghost btn-sm" id="alsViewListBtn">
            <i class="fas fa-list"></i> List
        </button>
        <button type="button" class="btn btn-ghost btn-sm" id="alsViewCardBtn">
            <i class="fas fa-th-large"></i> Card
        </button>
    </div>
</div>

<div class="upload-tabs" style="margin:10px 0 14px">
    <a class="upload-tab <?= $subtype === 'all' ? 'active' : '' ?>" href="<?= APP_URL ?>/als.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>">
        <i class="fas fa-sitemap"></i> All ALS Centers (<?= number_format($subtypeCounts['all']) ?>)
    </a>
</div>

<!-- Tree View for ALS Subtypes -->
<div class="glass-card" style="padding:12px 16px;margin-bottom:14px">
    <div style="display:flex;flex-direction:column;gap:6px">
        <div style="font-weight:600;font-size:0.95em;color:#666;margin-bottom:4px">
            <i class="fas fa-stream"></i> Filter by Subtype
        </div>
        <a href="<?= APP_URL ?>/als.php?subtype=cblc<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" 
           class="tree-item <?= $subtype === 'cblc' ? 'active' : '' ?>"
           style="display:flex;align-items:center;gap:8px;padding:6px 12px;border-radius:4px;text-decoration:none;transition:all 0.2s;<?= $subtype === 'cblc' ? 'background:rgba(59,130,246,0.15);color:#3b82f6;font-weight:500' : 'color:#555;hover:background:#f0f0f0' ?>">
            <i class="fas fa-circle" style="font-size:0.6em;color:#60a5fa"></i>
            <span>CBLC - Community-Based Learning</span>
            <span style="margin-left:auto;font-size:0.9em;color:#999">(<?= number_format($subtypeCounts['cblc']) ?>)</span>
        </a>
        <a href="<?= APP_URL ?>/als.php?subtype=sblc<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" 
           class="tree-item <?= $subtype === 'sblc' ? 'active' : '' ?>"
           style="display:flex;align-items:center;gap:8px;padding:6px 12px;border-radius:4px;text-decoration:none;transition:all 0.2s;<?= $subtype === 'sblc' ? 'background:rgba(52,211,153,0.15);color:#10b981;font-weight:500' : 'color:#555;hover:background:#f0f0f0' ?>">
            <i class="fas fa-circle" style="font-size:0.6em;color:#34d399"></i>
            <span>SBLC - School-Based Learning</span>
            <span style="margin-left:auto;font-size:0.9em;color:#999">(<?= number_format($subtypeCounts['sblc']) ?>)</span>
        </a>
        <a href="<?= APP_URL ?>/als.php?subtype=als-shs<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" 
           class="tree-item <?= $subtype === 'als-shs' ? 'active' : '' ?>"
           style="display:flex;align-items:center;gap:8px;padding:6px 12px;border-radius:4px;text-decoration:none;transition:all 0.2s;<?= $subtype === 'als-shs' ? 'background:rgba(251,191,36,0.15);color:#f59e0b;font-weight:500' : 'color:#555;hover:background:#f0f0f0' ?>">
            <i class="fas fa-circle" style="font-size:0.6em;color:#fbbf24"></i>
            <span>ALS-SHS - Senior High School</span>
            <span style="margin-left:auto;font-size:0.9em;color:#999">(<?= number_format($subtypeCounts['als-shs']) ?>)</span>
        </a>
    </div>
</div>

<div class="results-info">
    <?= number_format($total) ?> ALS center<?= $total !== 1 ? 's' : '' ?> found
</div>

<div class="table-card glass-card" id="alsListView">
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Center Name</th>
                    <th>School ID</th>
                    <th>Subtype</th>
                    <th>Municipality</th>
                    <th>District</th>
                    <th class="text-center">Teachers</th>
                    <?php if (canEdit()): ?><th class="text-center">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($centers as $c): ?>
            <tr>
                <td><strong><?= clean($c['school_name']) ?></strong></td>
                <td><?= clean($c['school_id_code'] ?? '—') ?></td>
                <td>
                    <span class="badge" style="background:<?= $c['als_subtype'] === 'CBLC' ? '#60a5fa' : ($c['als_subtype'] === 'SBLC' ? '#34d399' : '#fbbf24') ?>">
                        <?= clean($c['als_subtype'] ?? '—') ?>
                    </span>
                </td>
                <td><?= clean($c['municipality'] ?? '—') ?></td>
                <td><?= clean($c['district'] ?? '—') ?></td>
                <td class="text-center">
                    <a href="<?= APP_URL ?>/teachers.php?school=<?= urlencode(encryptId((int)$c['id'])) ?>" class="badge badge-blue">
                        <?= number_format((int)$c['teacher_count']) ?>
                    </a>
                </td>
                <?php if (canEdit()): ?>
                <td class="text-center">
                    <button class="btn btn-sm btn-secondary"
                            onclick="editAls(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(clean($c['school_name']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($c['school_id_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($c['municipality'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($c['als_subtype'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($c['district'] ?? ''), ENT_QUOTES, 'UTF-8') ?>')">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-danger"
                            onclick="confirmDeleteAls(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(clean($c['school_name']), ENT_QUOTES, 'UTF-8') ?>')">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (!$centers): ?>
            <tr><td colspan="7" class="text-center text-muted">No ALS centers found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="school-card-grid" id="alsCardView" style="display:none">
    <?php foreach ($centers as $c): ?>
    <div class="school-card glass-card">
        <div class="school-card-head">
            <h4><?= clean($c['school_name']) ?></h4>
            <span class="badge" style="background:<?= $c['als_subtype'] === 'CBLC' ? '#60a5fa' : ($c['als_subtype'] === 'SBLC' ? '#34d399' : '#fbbf24') ?>">
                <?= clean($c['als_subtype'] ?? 'Unknown') ?>
            </span>
        </div>
        <div class="school-card-meta">
            <span><i class="fas fa-id-card"></i> <?= clean($c['school_id_code'] ?? '—') ?></span>
            <span><i class="fas fa-city"></i> <?= clean($c['municipality'] ?? '—') ?></span>
            <span><i class="fas fa-map-pin"></i> <?= clean($c['district'] ?? '—') ?></span>
            <span><i class="fas fa-users"></i> <?= number_format((int)$c['teacher_count']) ?> Teachers</span>
        </div>
        <div class="school-card-actions">
            <a href="<?= APP_URL ?>/teachers.php?school=<?= urlencode(encryptId((int)$c['id'])) ?>" class="btn btn-sm btn-ghost">
                <i class="fas fa-users"></i> View Teachers
            </a>
            <?php if (canEdit()): ?>
            <button class="btn btn-sm btn-secondary"
                    onclick="editAls(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(clean($c['school_name']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($c['school_id_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($c['municipality'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($c['als_subtype'] ?? ''), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(clean($c['district'] ?? ''), ENT_QUOTES, 'UTF-8') ?>')">
                <i class="fas fa-edit"></i>
            </button>
            <button class="btn btn-sm btn-danger"
                    onclick="confirmDeleteAls(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(clean($c['school_name']), ENT_QUOTES, 'UTF-8') ?>')">
                <i class="fas fa-trash"></i>
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$centers): ?>
    <div class="empty-state glass-card">
        <i class="fas fa-school fa-3x"></i>
        <p>No ALS centers found.</p>
    </div>
    <?php endif; ?>
</div>

<?= paginationLinks($pag, APP_URL . '/' . basename($_SERVER['PHP_SELF']) . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>

<!-- Bulk Upload ALS Modal -->
<div class="modal-overlay" id="bulkUploadAlsModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-header">
            <h3 class="modal-title">Bulk Upload ALS Centers</h3>
            <button class="modal-close" onclick="document.getElementById('bulkUploadAlsModal').style.display='none'">×</button>
        </div>
        <form method="POST" action="<?= APP_URL ?>/actions/process_school_upload.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="form-group" style="margin-bottom:8px">
                <a href="<?= APP_URL ?>/assets/templates/school_upload_template.csv" class="btn btn-ghost btn-sm" download>
                    <i class="fas fa-download"></i> Download Sample CSV
                </a>
            </div>
            <div class="form-group" style="font-size:13px;color:var(--text-muted)">
                Required headers: <strong>School Name</strong> and <strong>School ID Code</strong>.
                For ALS records, set <strong>School Type</strong> to <strong>ALS</strong> and use <strong>ALS Subtype</strong> values: <strong>CBLC</strong>, <strong>SBLC</strong>, or <strong>ALS-SHS</strong>.
            </div>
            <div class="form-group">
                <label class="form-label required">Upload File (.xlsx, .csv)</label>
                <input type="file" name="upload_file" class="form-input" accept=".xlsx,.csv" required>
                <small style="color:#999">Include "School Type" and "ALS Subtype" columns for proper categorization</small>
            </div>
            <div class="form-group" style="display:flex;gap:12px;flex-wrap:wrap">
                <label><input type="checkbox" name="skip_duplicates" value="1" checked> Skip duplicates</label>
                <label><input type="checkbox" name="update_existing" value="1"> Update existing</label>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('bulkUploadAlsModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- Add/Edit ALS Modal -->
<div class="modal-overlay" id="addAlsModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-header">
            <h3 class="modal-title" id="alsModalTitle">Add ALS Center</h3>
            <button class="modal-close" onclick="closeAlsModal()">×</button>
        </div>
        <form method="POST" action="<?= APP_URL ?>/actions/save_school.php" id="alsForm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="id" id="alsId" value="">
            <input type="hidden" name="school_type" id="alsType" value="ALS">
            <div class="form-group">
                <label class="form-label required">Center Name</label>
                <input type="text" name="school_name" id="alsName" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">School ID Code</label>
                <input type="text" name="school_id_code" id="alsIdCode" class="form-input" placeholder="e.g. 400001">
            </div>
            <div class="form-group">
                <label class="form-label">Municipality</label>
                <input type="text" name="municipality" id="alsMunicipality" class="form-input" placeholder="e.g. Baler">
            </div>
            <div class="form-group">
                <label class="form-label required">ALS Subtype</label>
                <select name="als_subtype" id="alsSubtype" class="form-input" required>
                    <option value="">Select subtype</option>
                    <option value="CBLC">CBLC - Community-Based Community Learning Centers</option>
                    <option value="SBLC">SBLC - School-Based Learning Centers</option>
                    <option value="ALS-SHS">ALS-SHS - ALS Senior High School</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">District</label>
                <input type="text" name="district" id="alsDistrict" class="form-input" placeholder="e.g. District I">
            </div>
            <input type="hidden" name="confirm_password" id="alsConfirmPassword" value="">
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeAlsModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Center</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirm -->
<div class="modal-overlay" id="deleteAlsModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 class="modal-title">Delete ALS Center</h3>
        <p class="modal-body">Are you sure you want to delete <strong id="deleteAlsName"></strong>? Teachers assigned to this center will be unassigned.</p>
        <div class="modal-actions">
            <button onclick="document.getElementById('deleteAlsModal').style.display='none'" class="btn btn-ghost">Cancel</button>
            <form method="POST" action="<?= APP_URL ?>/actions/delete_school.php">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="id" id="deleteAlsId">
                <input type="hidden" name="confirm_password" id="deleteAlsConfirmPassword">
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    </div>
</div>

<script>
function editAls(id, name, code, municipality, subtype, district) {
    document.getElementById('alsModalTitle').textContent = 'Edit ALS Center';
    document.getElementById('alsId').value       = id;
    document.getElementById('alsName').value     = name;
    document.getElementById('alsIdCode').value   = code;
    document.getElementById('alsMunicipality').value = municipality;
    document.getElementById('alsSubtype').value  = subtype;
    document.getElementById('alsDistrict').value = district;
    document.getElementById('alsConfirmPassword').value = '';
    document.getElementById('addAlsModal').style.display = 'flex';
}

function closeAlsModal() {
    document.getElementById('addAlsModal').style.display = 'none';
    document.getElementById('alsForm').reset();
    document.getElementById('alsId').value = '';
    document.getElementById('alsType').value = 'ALS';
    document.getElementById('alsModalTitle').textContent = 'Add ALS Center';
    document.getElementById('alsConfirmPassword').value = '';
}

function confirmDeleteAls(id, name) {
    document.getElementById('deleteAlsName').textContent = name;
    document.getElementById('deleteAlsId').value = id;
    document.getElementById('deleteAlsModal').style.display = 'flex';
}

async function promptAlsPassword(message) {
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

document.getElementById('alsForm')?.addEventListener('submit', async function(e) {
    if (this.dataset.confirmed === '1') return;
    if (!document.getElementById('alsId').value) return;
    e.preventDefault();
    const pwd = await promptAlsPassword('Enter your password to save changes to this ALS center:');
    if (!pwd) return;
    document.getElementById('alsConfirmPassword').value = pwd;
    this.dataset.confirmed = '1';
    this.submit();
});

document.querySelector('#deleteAlsModal form')?.addEventListener('submit', async function(e) {
    if (this.dataset.confirmed === '1') return;
    e.preventDefault();
    const pwd = await promptAlsPassword('Enter your password to delete this ALS center:');
    if (!pwd) return;
    document.getElementById('deleteAlsConfirmPassword').value = pwd;
    this.dataset.confirmed = '1';
    this.submit();
});

function setAlsView(mode) {
    const listWrap = document.getElementById('alsListView');
    const cardWrap = document.getElementById('alsCardView');
    const listBtn  = document.getElementById('alsViewListBtn');
    const cardBtn  = document.getElementById('alsViewCardBtn');

    if (mode === 'card') {
        listWrap.style.display = 'none';
        cardWrap.style.display = 'grid';
        listBtn.classList.remove('btn-primary');
        listBtn.classList.add('btn-ghost');
        cardBtn.classList.remove('btn-ghost');
        cardBtn.classList.add('btn-primary');
    } else {
        listWrap.style.display = '';
        cardWrap.style.display = 'none';
        cardBtn.classList.remove('btn-primary');
        cardBtn.classList.add('btn-ghost');
        listBtn.classList.remove('btn-ghost');
        listBtn.classList.add('btn-primary');
    }
    localStorage.setItem('alsViewMode', mode);
}

document.getElementById('alsViewListBtn').addEventListener('click', () => setAlsView('list'));
document.getElementById('alsViewCardBtn').addEventListener('click', () => setAlsView('card'));
setAlsView(localStorage.getItem('alsViewMode') || 'card');
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
