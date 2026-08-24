<?php
$pageTitle = 'Districts';
require_once __DIR__ . '/includes/header.php';

$db = getDB();
$errors = [];
$search = clean(trim((string)($_GET['q'] ?? '')));
$selectedDistrict = trim((string)($_GET['district'] ?? ''));

// Input length validation
if (strlen($search) > 500 || strlen($selectedDistrict) > 255) {
    flash('error', 'Filter parameters are too long.');
    redirect(APP_URL . '/districts');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (!canEdit()) {
        flash('error', 'Permission denied.');
        redirect(APP_URL . '/districts.php');
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $districtName = trim((string)($_POST['district_name'] ?? ''));

        if ($districtName === '') {
            $errors['district_name'] = 'District name is required.';
        }

        if (!$errors) {
            $dup = $db->prepare('SELECT id FROM districts WHERE LOWER(TRIM(district_name)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1');
            $dup->execute([$districtName, $id]);
            if ($dup->fetchColumn()) {
                $errors['district_name'] = 'District already exists.';
            }
        }

        if (!$errors) {
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE districts SET district_name = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$districtName, $id]);
                logActivity('UPDATE', 'districts', $id, 'Updated district: ' . $districtName);
                flash('success', 'District updated successfully.');
            } else {
                $stmt = $db->prepare('INSERT INTO districts (district_name) VALUES (?)');
                $stmt->execute([$districtName]);
                $newId = (int)$db->lastInsertId();
                logActivity('CREATE', 'districts', $newId, 'Created district: ' . $districtName);
                flash('success', 'District created successfully.');
            }
            redirect(APP_URL . '/districts.php');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash('error', 'Invalid district.');
            redirect(APP_URL . '/districts.php');
        }

        $stmt = $db->prepare('SELECT district_name FROM districts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $districtName = (string)$stmt->fetchColumn();
        if ($districtName === '') {
            flash('error', 'District not found.');
            redirect(APP_URL . '/districts.php');
        }

        $db->prepare('DELETE FROM districts WHERE id = ?')->execute([$id]);
        logActivity('DELETE', 'districts', $id, 'Deleted district: ' . $districtName);
        flash('success', 'District deleted successfully. Schools linked to it were unassigned.');
        redirect(APP_URL . '/districts.php');
    }
}

$districtRows = $db->prepare(
    'SELECT d.id, d.district_name, d.created_at, d.updated_at,
            (SELECT COUNT(*) FROM schools s WHERE s.district_id = d.id) AS school_count,
            (SELECT COUNT(*)
             FROM teachers t
             INNER JOIN schools s ON t.school_id = s.id
             WHERE s.district_id = d.id
                OR LOWER(TRIM(COALESCE(NULLIF(t.district_raw, ""), d.district_name))) = LOWER(TRIM(d.district_name))
            ) AS teacher_count
     FROM districts d
     WHERE d.district_name LIKE ?
     ORDER BY d.district_name'
);
$districtRows->execute(['%' . $search . '%']);
$districts = $districtRows->fetchAll(PDO::FETCH_ASSOC);

$districtCount = count($districts);
$totalSchools = 0;
$totalTeachers = 0;
foreach ($districts as $district) {
    $totalSchools += (int)($district['school_count'] ?? 0);
    $totalTeachers += (int)($district['teacher_count'] ?? 0);
}

$selectedDistrictSchools = [];
$selectedDistrictTeacherTotal = 0;
if ($selectedDistrict !== '') {
    $schoolStmt = $db->prepare(
        'SELECT s.id, s.school_name, s.school_type,
                (SELECT COUNT(*) FROM teachers t WHERE t.school_id = s.id) AS teacher_count
         FROM schools s
         INNER JOIN districts d ON s.district_id = d.id
         WHERE LOWER(TRIM(d.district_name)) = LOWER(TRIM(?))
         ORDER BY s.school_name'
    );
    $schoolStmt->execute([$selectedDistrict]);
    $selectedDistrictSchools = $schoolStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($selectedDistrictSchools as $schoolRow) {
        $selectedDistrictTeacherTotal += (int)($schoolRow['teacher_count'] ?? 0);
    }
}
?>

<div class="filter-bar glass-card">
    <form method="GET" class="filter-form">
        <?php if ($selectedDistrict !== ''): ?>
        <input type="hidden" name="district" value="<?= clean($selectedDistrict) ?>">
        <?php endif; ?>
        <div class="search-box">
            <i class="fas fa-search search-icon"></i>
            <input type="text" name="q" class="form-input" placeholder="Search districts…" value="<?= clean($search) ?>">
        </div>
        <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
        <?php if ($search !== ''): ?>
        <a href="<?= APP_URL ?>/districts.php<?= $selectedDistrict !== '' ? '?district=' . urlencode($selectedDistrict) : '' ?>" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
        <?php endif; ?>
    </form>
    <?php if (canEdit()): ?>
    <div class="filter-actions">
        <button type="button" class="btn btn-ghost btn-sm" id="districtsViewListBtn">
            <i class="fas fa-list"></i> List
        </button>
        <button type="button" class="btn btn-ghost btn-sm" id="districtsViewCardBtn">
            <i class="fas fa-th-large"></i> Card
        </button>
        <button type="button" class="btn btn-primary" onclick="openDistrictModal()">
            <i class="fas fa-plus"></i> Add District
        </button>
    </div>
    <?php else: ?>
    <div class="filter-actions">
        <button type="button" class="btn btn-ghost btn-sm" id="districtsViewListBtn">
            <i class="fas fa-list"></i> List
        </button>
        <button type="button" class="btn btn-ghost btn-sm" id="districtsViewCardBtn">
            <i class="fas fa-th-large"></i> Card
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if ($selectedDistrict !== ''): ?>
<div class="results-info" style="margin-top:10px;display:flex;align-items:center;gap:8px;">
    <i class="fas fa-circle-info" style="color:#67e8f9;"></i>
    District selected: <strong><?= clean($selectedDistrict) ?></strong>. School cards are opened in a modal.
</div>
<?php endif; ?>



<div class="table-card glass-card" id="districtsListView">
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>District Name</th>
                    <th class="text-center">Schools</th>
                    <th class="text-center">Teachers</th>
                    <th>Created</th>
                    <th>Updated</th>
                    <?php if (canEdit()): ?><th class="text-center">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($districts as $district): ?>
            <tr>
                <td>
                    <a href="<?= APP_URL ?>/districts.php?district=<?= urlencode((string)$district['district_name']) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" style="text-decoration:none;color:#93c5fd;font-weight:700;">
                        <?= clean($district['district_name']) ?>
                    </a>
                </td>
                <td class="text-center">
                    <a href="<?= APP_URL ?>/schools.php?district=<?= urlencode((string)$district['district_name']) ?>" class="badge badge-blue" title="View schools in this district">
                        <?= number_format((int)$district['school_count']) ?>
                    </a>
                </td>
                <td class="text-center"><?= number_format((int)$district['teacher_count']) ?></td>
                <td><?= formatDate($district['created_at'] ?? null) ?></td>
                <td><?= formatDate($district['updated_at'] ?? null) ?></td>
                <?php if (canEdit()): ?>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-secondary"
                            onclick="editDistrict(<?= (int)$district['id'] ?>, '<?= htmlspecialchars(clean($district['district_name']), ENT_QUOTES, 'UTF-8') ?>')">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-danger"
                            onclick="confirmDeleteDistrict(<?= (int)$district['id'] ?>, '<?= htmlspecialchars(clean($district['district_name']), ENT_QUOTES, 'UTF-8') ?>')">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (!$districts): ?>
            <tr>
                <td colspan="<?= canEdit() ? 6 : 5 ?>" class="text-center text-muted">No districts found.</td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="school-card-grid" id="districtsCardView" style="display:none;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
    <?php foreach ($districts as $district): ?>
    <div class="school-card glass-card">
        <div class="school-card-head" style="align-items:flex-start;">
            <h4 style="margin:0;">
                <a href="<?= APP_URL ?>/districts.php?district=<?= urlencode((string)$district['district_name']) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" style="text-decoration:none;color:#93c5fd;">
                    <?= clean($district['district_name']) ?>
                </a>
            </h4>
        </div>
        <div class="school-card-meta" style="margin-top:10px;display:grid;gap:6px;">
            <span><i class="fas fa-school"></i> Schools: <strong><?= number_format((int)$district['school_count']) ?></strong></span>
            <span><i class="fas fa-users"></i> Teachers: <strong><?= number_format((int)$district['teacher_count']) ?></strong></span>
            <span><i class="fas fa-calendar-plus"></i> Created: <?= formatDate($district['created_at'] ?? null) ?></span>
            <span><i class="fas fa-clock-rotate-left"></i> Updated: <?= formatDate($district['updated_at'] ?? null) ?></span>
        </div>
        <div class="school-card-actions" style="margin-top:10px;">
            <a href="<?= APP_URL ?>/schools.php?district=<?= urlencode((string)$district['district_name']) ?>" class="btn btn-sm btn-ghost">
                <i class="fas fa-school"></i> View Schools
            </a>
            <?php if (canEdit()): ?>
            <button type="button" class="btn btn-sm btn-secondary"
                    onclick="editDistrict(<?= (int)$district['id'] ?>, '<?= htmlspecialchars(clean($district['district_name']), ENT_QUOTES, 'UTF-8') ?>')">
                <i class="fas fa-edit"></i>
            </button>
            <button type="button" class="btn btn-sm btn-danger"
                    onclick="confirmDeleteDistrict(<?= (int)$district['id'] ?>, '<?= htmlspecialchars(clean($district['district_name']), ENT_QUOTES, 'UTF-8') ?>')">
                <i class="fas fa-trash"></i>
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$districts): ?>
    <div class="empty-state glass-card">
        <i class="fas fa-map fa-3x"></i>
        <p>No districts found.</p>
    </div>
    <?php endif; ?>
</div>

<?php if ($selectedDistrict !== ''): ?>
<div class="modal-overlay" id="districtSchoolsModal" style="display:none;">
    <div class="modal glass-card" style="max-width:980px;width:min(980px,95vw);max-height:88vh;overflow:auto;border:1px solid rgba(56,189,248,.35);background:linear-gradient(160deg, rgba(2,132,199,.14), rgba(15,23,42,.9));">
        <div class="modal-header" style="border-bottom:1px solid rgba(148,163,184,.25);padding-bottom:10px;">
            <h3 class="modal-title" style="display:flex;align-items:center;gap:8px;">
                <i class="fas fa-map-location-dot" style="color:#67e8f9;"></i>
                <?= clean($selectedDistrict) ?> Schools
            </h3>
            <button class="modal-close" onclick="closeDistrictSchoolsModal()">×</button>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 14px 0;">
            <span class="badge badge-blue" style="padding:6px 10px;"><?= number_format(count($selectedDistrictSchools)) ?> Schools</span>
            <span class="badge badge-green" style="padding:6px 10px;"><?= number_format($selectedDistrictTeacherTotal) ?> Teachers</span>
        </div>

        <?php if ($selectedDistrictSchools): ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
            <?php foreach ($selectedDistrictSchools as $school): ?>
            <a href="<?= APP_URL ?>/teachers.php?school=<?= urlencode(encryptId((int)$school['id'])) ?>"
               style="display:block;padding:12px;border-radius:12px;border:1px solid rgba(148,163,184,.22);background:linear-gradient(160deg, rgba(15,23,42,.55), rgba(30,41,59,.42));text-decoration:none;transition:.2s transform,.2s border-color,.2s box-shadow;"
               onmouseover="this.style.transform='translateY(-2px)';this.style.borderColor='rgba(56,189,248,.55)';this.style.boxShadow='0 10px 24px rgba(2,132,199,.15)'"
               onmouseout="this.style.transform='';this.style.borderColor='rgba(148,163,184,.22)';this.style.boxShadow='none'">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                    <strong style="color:#f8fafc;line-height:1.35;"><?= clean($school['school_name']) ?></strong>
                    <span class="badge badge-green" style="white-space:nowrap;"><?= number_format((int)$school['teacher_count']) ?></span>
                </div>
                <div style="margin-top:8px;display:flex;justify-content:space-between;align-items:center;color:#cbd5e1;font-size:12px;">
                    <span><i class="fas fa-tag"></i> <?= clean($school['school_type'] ?: 'Untagged') ?></span>
                    <span><i class="fas fa-users"></i> Open Teachers</span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state" style="padding:20px 10px;">
            <i class="fas fa-school fa-2x" style="opacity:.75"></i>
            <p>No schools linked to this district yet.</p>
        </div>
        <?php endif; ?>

        <div class="modal-actions" style="margin-top:14px;justify-content:space-between;">
            <a href="<?= APP_URL ?>/districts.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>" class="btn btn-ghost btn-sm">
                <i class="fas fa-filter-circle-xmark"></i> Clear District
            </a>
            <button type="button" class="btn btn-primary btn-sm" onclick="closeDistrictSchoolsModal()">
                <i class="fas fa-check"></i> Done
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (canEdit()): ?>
<div class="modal-overlay" id="districtModal" style="display:none">
    <div class="modal glass-card" style="max-width:520px">
        <div class="modal-header">
            <h3 class="modal-title" id="districtModalTitle">Add District</h3>
            <button class="modal-close" onclick="closeDistrictModal()">×</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="districtId" value="">
            <div class="form-group">
                <label class="form-label required">District Name</label>
                <input type="text" name="district_name" id="districtName" class="form-input" required placeholder="e.g. District I">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeDistrictModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save District</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="deleteDistrictModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 class="modal-title">Delete District</h3>
        <p class="modal-body">Are you sure you want to delete <strong id="deleteDistrictName"></strong>? Linked schools will be unassigned.</p>
        <div class="modal-actions">
            <button type="button" class="btn btn-ghost" onclick="document.getElementById('deleteDistrictModal').style.display='none'">Cancel</button>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteDistrictId" value="">
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openDistrictModal() {
    document.getElementById('districtModalTitle').textContent = 'Add District';
    document.getElementById('districtId').value = '';
    document.getElementById('districtName').value = '';
    document.getElementById('districtModal').style.display = 'flex';
}

function editDistrict(id, name) {
    document.getElementById('districtModalTitle').textContent = 'Edit District';
    document.getElementById('districtId').value = id;
    document.getElementById('districtName').value = name;
    document.getElementById('districtModal').style.display = 'flex';
}

function closeDistrictModal() {
    document.getElementById('districtModal').style.display = 'none';
}

function confirmDeleteDistrict(id, name) {
    document.getElementById('deleteDistrictName').textContent = name;
    document.getElementById('deleteDistrictId').value = id;
    document.getElementById('deleteDistrictModal').style.display = 'flex';
}

function setDistrictsView(mode) {
    const listWrap = document.getElementById('districtsListView');
    const cardWrap = document.getElementById('districtsCardView');
    const listBtn = document.getElementById('districtsViewListBtn');
    const cardBtn = document.getElementById('districtsViewCardBtn');

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

    localStorage.setItem('districtsViewMode', mode);
}

function openDistrictSchoolsModal() {
    const modal = document.getElementById('districtSchoolsModal');
    if (modal) modal.style.display = 'flex';
}

function closeDistrictSchoolsModal() {
    const modal = document.getElementById('districtSchoolsModal');
    if (modal) modal.style.display = 'none';
}

<?php if ($selectedDistrict !== ''): ?>
document.addEventListener('DOMContentLoaded', function () {
    openDistrictSchoolsModal();
});
<?php endif; ?>

document.getElementById('districtsViewListBtn')?.addEventListener('click', () => setDistrictsView('list'));
document.getElementById('districtsViewCardBtn')?.addEventListener('click', () => setDistrictsView('card'));

const savedDistrictsViewMode = localStorage.getItem('districtsViewMode') || 'list';
const initialDistrictsViewMode = window.matchMedia('(max-width: 640px)').matches ? 'card' : savedDistrictsViewMode;
setDistrictsView(initialDistrictsViewMode);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
