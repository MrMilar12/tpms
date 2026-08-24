<?php
$pageTitle = 'Users';
require_once __DIR__ . '/includes/header.php';

// Require user to have selected a role first
requireRoleSelection();

requireRole(['admin']);

$db    = getDB();
ensureTwoFactorColumns($db);

$search = clean(trim((string)($_GET['q'] ?? '')));
if (strlen($search) > 200) {
    flash('error', 'Search term is too long.');
    redirect(APP_URL . '/users.php');
}

$usersSql = 'SELECT id, username, full_name, email, role, district_id, is_active, COALESCE(twofa_enabled,0) AS twofa_enabled, last_login, created_at
             FROM users';
$usersParams = [];
if ($search !== '') {
    $usersSql .= ' WHERE username LIKE ? OR full_name LIKE ? OR email LIKE ? OR role LIKE ?';
    $like = '%' . $search . '%';
    $usersParams = [$like, $like, $like, $like];
}
$usersSql .= ' ORDER BY created_at DESC';

$usersStmt = $db->prepare($usersSql);
$usersStmt->execute($usersParams);
$users = $usersStmt->fetchAll();

// Get all districts for dropdown
$districtsStmt = $db->prepare('SELECT id, district_name FROM districts ORDER BY district_name');
$districtsStmt->execute();
$allDistricts = $districtsStmt->fetchAll();

$editUser = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT id, username, full_name, email, role, district_id, is_active, COALESCE(twofa_enabled,0) AS twofa_enabled FROM users WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editUser = $stmt->fetch();
}

$errors = [];

function verifyCurrentUserPassword(PDO $db, string $password): bool {
    if ($password === '') {
        return false;
    }

    $uid = (int)(currentUser()['id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }

    $pwStmt = $db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $pwStmt->execute([$uid]);
    $hash = (string)$pwStmt->fetchColumn();
    return $hash !== '' && password_verify($password, $hash);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $uid      = (int)($_POST['uid'] ?? 0);
        $username = trim($_POST['username']  ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email']     ?? '');
        $role     = $_POST['role']            ?? 'viewer';
        $districtId = !empty($_POST['district_id']) ? (int)$_POST['district_id'] : null;
        $active   = isset($_POST['is_active']) ? 1 : 0;
        $twofaEnabled = isset($_POST['twofa_enabled']) ? 1 : 0;
        $regenerate2fa = isset($_POST['regenerate_2fa']) ? 1 : 0;
        $password = $_POST['password']        ?? '';
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        
        // For PSDS/SDC, district_id is required
        if (in_array($role, ['psds', 'sdc', 'unit_head'], true) && !$districtId) {
            $errors['district_id'] = 'A district must be selected for ' . strtoupper($role) . '.';
        }

        if ($username === '')  $errors['username']  = 'Required.';
        if ($fullName === '')  $errors['full_name']  = 'Required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
        if (!in_array($role, ['admin','hr','school_head','viewer','psds','sdc','unit_head'], true)) $errors['role'] = 'Invalid role.';

        if (!$errors) {
            $twofaSecret = null;
            if ($twofaEnabled === 1) {
                if ($uid > 0 && $regenerate2fa === 0) {
                    $secretStmt = $db->prepare('SELECT twofa_secret FROM users WHERE id = ? LIMIT 1');
                    $secretStmt->execute([$uid]);
                    $existingSecret = trim((string)$secretStmt->fetchColumn());
                    if ($existingSecret !== '') {
                        $twofaSecret = $existingSecret;
                    } else {
                        $twofaSecret = generateTotpSecret();
                    }
                } else {
                    $twofaSecret = generateTotpSecret();
                }
            }

            if ($uid > 0) {
                if (!verifyCurrentUserPassword($db, $confirmPassword)) {
                    $errors['confirm_password'] = 'Password confirmation is required and must be valid.';
                }

                if ($errors) {
                    $editUser = [
                        'id' => $uid,
                        'username' => $username,
                        'full_name' => $fullName,
                        'email' => $email,
                        'role' => $role,
                        'district_id' => $districtId,
                        'is_active' => $active,
                        'twofa_enabled' => $twofaEnabled,
                    ];
                }
            }

            if ($uid > 0 && !$errors) {
                // Update
                $sql = 'UPDATE users SET username=?, full_name=?, email=?, role=?, district_id=?, is_active=?, twofa_enabled=?, twofa_secret=?, updated_at=NOW() WHERE id=?';
                $params = [$username, $fullName, $email ?: null, $role, $districtId, $active, $twofaEnabled, $twofaSecret, $uid];
                if ($password !== '') {
                    $sql    = 'UPDATE users SET username=?, full_name=?, email=?, role=?, district_id=?, is_active=?, twofa_enabled=?, twofa_secret=?, password_hash=?, updated_at=NOW() WHERE id=?';
                    $params = [$username, $fullName, $email ?: null, $role, $districtId, $active, $twofaEnabled, $twofaSecret, password_hash($password, PASSWORD_BCRYPT), $uid];
                }
                $db->prepare($sql)->execute($params);
                
                logActivity('UPDATE', 'users', $uid, "Updated user: $username");
                flash('success', 'User updated.');
            } elseif (!$errors) {
                // Create – password required
                if ($password === '') {
                    $errors['password'] = 'Password is required for new users.';
                } else {
                    // Check duplicate username
                    $dup = $db->prepare('SELECT id FROM users WHERE username = ?');
                    $dup->execute([$username]);
                    if ($dup->fetch()) {
                        $errors['username'] = 'Username already taken.';
                    } else {
                        $db->prepare(
                            'INSERT INTO users (username, password_hash, full_name, email, role, district_id, is_active, twofa_enabled, twofa_secret) VALUES (?,?,?,?,?,?,?,?,?)'
                        )->execute([$username, password_hash($password, PASSWORD_BCRYPT), $fullName, $email ?: null, $role, $districtId, $active, $twofaEnabled, $twofaSecret]);
                        $newUserId = (int)$db->lastInsertId();

                        if ($districtId && in_array($role, ['psds', 'sdc', 'unit_head'], true)) {
                            // Initialize the multi-district mapping as well as
                            // the backwards-compatible primary district value.
                            getUserDistricts($db, $newUserId);
                            $db->prepare('INSERT INTO user_districts (user_id, district_id) VALUES (?, ?)
                                          ON DUPLICATE KEY UPDATE assigned_at = assigned_at')
                                ->execute([$newUserId, $districtId]);
                        }
                        
                        logActivity('CREATE', 'users', $newUserId, "Created user: $username");
                        flash('success', 'User created.');
                        redirect(APP_URL . '/users.php');
                    }
                }
            }
            if (!$errors) redirect(APP_URL . '/users.php');
        }
    } elseif ($action === 'toggle') {
        $uid = (int)($_POST['uid'] ?? 0);
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        if (!verifyCurrentUserPassword($db, $confirmPassword)) {
            flash('error', 'Invalid password. User status was not changed.');
            redirect(APP_URL . '/users.php');
        }
        if ($uid && $uid !== currentUser()['id']) {
            $db->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?')->execute([$uid]);
            flash('success', 'User status toggled.');
        }
        redirect(APP_URL . '/users.php');
    } elseif ($action === 'delete') {
        $uid = (int)($_POST['uid'] ?? 0);
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        if (!verifyCurrentUserPassword($db, $confirmPassword)) {
            flash('error', 'Invalid password. User was not deleted.');
            redirect(APP_URL . '/users.php');
        }
        if ($uid && $uid !== currentUser()['id']) {
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            logActivity('DELETE', 'users', $uid, 'Deleted user ID: ' . $uid);
            flash('success', 'User deleted.');
        }
        redirect(APP_URL . '/users.php');
    }
}

$isEditingUser = is_array($editUser) && (int)($editUser['id'] ?? 0) > 0;
$showUserModal = $isEditingUser;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save' && $errors) {
    $showUserModal = true;
    if (!$isEditingUser) {
        $editUser = [
            'id' => 0,
            'username' => trim((string)($_POST['username'] ?? '')),
            'full_name' => trim((string)($_POST['full_name'] ?? '')),
            'email' => trim((string)($_POST['email'] ?? '')),
            'role' => (string)($_POST['role'] ?? ''),
            'district_id' => (int)($_POST['district_id'] ?? 0),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'twofa_enabled' => isset($_POST['twofa_enabled']) ? 1 : 0,
        ];
    }
}

// Function to get district name
function getDistrictName($db, $districtId) {
    if (!$districtId) return '—';
    $stmt = $db->prepare('SELECT district_name FROM districts WHERE id = ? LIMIT 1');
    $stmt->execute([$districtId]);
    return clean($stmt->fetchColumn() ?? '—');
}

// Function to get role badge with color
function getRoleBadgeWithColor($role) {
    $colors = [
        'admin' => '#ef4444',
        'hr' => '#3b82f6',
        'school_head' => '#8b5cf6',
        'unit_head' => '#06b6d4',
        'psds' => '#ec4899',
        'sdc' => '#f59e0b',
        'viewer' => '#6b7280'
    ];
    $labels = [
        'admin' => 'Administrator',
        'hr' => 'HR / Personnel',
        'school_head' => 'School Head',
        'unit_head' => 'Unit Head',
        'psds' => 'PSDS',
        'sdc' => 'SDC',
        'viewer' => 'Viewer'
    ];
    $color = $colors[$role] ?? '#6b7280';
    $label = $labels[$role] ?? ucfirst($role);
    return '<span class="badge" style="background:' . $color . ';color:white">' . $label . '</span>';
}
?>

<style>
.user-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 14px;
    margin-top: 12px;
}

.user-card {
    padding: 16px;
    border: 1px solid rgba(148, 163, 184, 0.28);
    transition: all 0.3s ease;
}

.user-card:hover {
    border-color: rgba(59, 130, 246, 0.5);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
}

.user-card-header {
    display: flex;
    gap: 12px;
    margin-bottom: 14px;
    align-items: flex-start;
}

.user-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 18px;
    flex-shrink: 0;
}

.user-info {
    flex: 1;
}

.user-info h4 {
    margin: 0 0 4px 0;
    font-size: 15px;
    color: #11223a;
}

.user-info .text-muted {
    font-size: 13px;
    color: #64748b;
}

.user-meta-grid {
    display: grid;
    gap: 8px;
    margin-bottom: 12px;
    padding: 12px 0;
    border-top: 1px solid #e2e8f0;
    border-bottom: 1px solid #e2e8f0;
}

.user-meta-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
}

.user-meta-label {
    color: #64748b;
    font-weight: 500;
}

.user-meta-value {
    color: #11223a;
    font-weight: 500;
}

.user-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    flex-wrap: wrap;
}

/* Modal Styles */
.user-modal-form {
    display: grid;
    gap: 16px;
}

.form-section {
    display: grid;
    gap: 12px;
    padding-bottom: 16px;
    border-bottom: 1px solid #e2e8f0;
}

.form-section:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.form-section-title {
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    color: #64748b;
    letter-spacing: 0.5px;
}

.form-two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

@media (max-width: 640px) {
    .form-two-col {
        grid-template-columns: 1fr;
    }
}

.district-checkboxes {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 8px;
    padding: 12px;
    background: #f8fafc;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
}

.checkbox-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.checkbox-item input[type="checkbox"] {
    cursor: pointer;
}

.checkbox-item label {
    cursor: pointer;
    font-size: 13px;
}
</style>

<!-- ── Users Management ──────────────────────────────────────── -->
<div class="filter-bar glass-card">
    <div style="display: flex; align-items: center; gap: 12px; flex: 1; flex-wrap: wrap;">
        <div class="topbar-title" style="white-space: nowrap;">Manage System Users</div>
        <form method="GET" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex:1;min-width:260px;">
            <div class="search-box" style="flex: 1; min-width: 200px;">
                <i class="fas fa-search search-icon"></i>
                <input type="text" name="q" class="form-input" placeholder="Search users…" value="<?= clean($search) ?>">
            </div>
            <button type="submit" class="btn btn-ghost btn-sm"><i class="fas fa-search"></i></button>
            <?php if ($search !== ''): ?>
            <a href="<?= APP_URL ?>/users.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>
    <div class="filter-actions">
        <button type="button" class="btn btn-ghost btn-sm" id="usersViewListBtn">
            <i class="fas fa-list"></i> List
        </button>
        <button type="button" class="btn btn-ghost btn-sm" id="usersViewCardBtn">
            <i class="fas fa-th-large"></i> Card
        </button>
        <button class="btn btn-primary" onclick="document.getElementById('userModal').style.display='flex'">
            <i class="fas fa-user-plus"></i> Add User
        </button>
    </div>
</div>

<!-- ── List View ──────────────────────────────────────────────── -->
<div class="table-card glass-card" id="usersListView">
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>District</th>
                    <th>2FA</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td>
                    <div class="teacher-mini">
                        <div class="mini-avatar"><?= strtoupper(substr($u['full_name'],0,1)) ?></div>
                        <div>
                            <div><?= clean($u['full_name']) ?></div>
                            <div class="text-muted small"><?= clean($u['email'] ?? '') ?></div>
                        </div>
                    </div>
                </td>
                <td><code><?= clean($u['username']) ?></code></td>
                <td><?= getRoleBadgeWithColor($u['role']) ?></td>
                <td><?= in_array($u['role'], ['psds', 'sdc', 'unit_head'], true) ? getDistrictName($db, $u['district_id']) : '<span class="text-muted">—</span>' ?></td>
                <td>
                    <?php if ((int)$u['twofa_enabled'] === 1): ?>
                    <span class="badge badge-green">Enabled</span>
                    <?php else: ?>
                    <span class="badge badge-red">Disabled</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($u['is_active']): ?>
                    <span class="badge badge-green">Active</span>
                    <?php else: ?>
                    <span class="badge badge-red">Inactive</span>
                    <?php endif; ?>
                </td>
                <td><?= $u['last_login'] ? formatDate($u['last_login']) : '<span class="text-muted">Never</span>' ?></td>
                <td class="text-center">
                    <a href="?edit=<?= (int)$u['id'] ?>" class="btn btn-sm btn-secondary">
                        <i class="fas fa-edit"></i>
                    </a>
                    <?php if ($u['id'] !== currentUser()['id']): ?>
                    <form method="POST" style="display:inline" id="toggleUserForm<?= (int)$u['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                        <input type="hidden" name="confirm_password" id="togglePwd<?= (int)$u['id'] ?>" value="">
                        <button type="button" class="btn btn-sm btn-ghost" title="Toggle Active" onclick="confirmToggleUser(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(clean($u['username']), ENT_QUOTES, 'UTF-8') ?>')">
                            <i class="fas fa-power-off"></i>
                        </button>
                    </form>
                    <button class="btn btn-sm btn-danger" onclick="confirmDeleteUser(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(clean($u['username']), ENT_QUOTES, 'UTF-8') ?>')">
                        <i class="fas fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?>
            <tr><td colspan="8" class="text-center text-muted">No users found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Card View ──────────────────────────────────────────────── -->
<div class="user-card-grid" id="usersCardView" style="display:none;">
    <?php foreach ($users as $u): ?>
    <div class="user-card glass-card">
        <div class="user-card-header">
            <div class="user-avatar"><?= strtoupper(substr($u['full_name'],0,1)) ?></div>
            <div class="user-info">
                <h4><?= clean($u['full_name']) ?></h4>
                <div class="text-muted"><?= clean($u['email'] ?? 'No email') ?></div>
            </div>
        </div>

        <div class="user-meta-grid">
            <div class="user-meta-row">
                <span class="user-meta-label">Username</span>
                <code style="font-size:12px"><?= clean($u['username']) ?></code>
            </div>
            <div class="user-meta-row">
                <span class="user-meta-label">Role</span>
                <span><?= getRoleBadgeWithColor($u['role']) ?></span>
            </div>
            <?php if (in_array($u['role'], ['psds', 'sdc', 'unit_head'], true)): ?>
            <div class="user-meta-row">
                <span class="user-meta-label">District</span>
                <span><?= getDistrictName($db, $u['district_id']) ?></span>
            </div>
            <?php endif; ?>
            <div class="user-meta-row">
                <span class="user-meta-label">2FA</span>
                <span>
                    <?php if ((int)$u['twofa_enabled'] === 1): ?>
                    <span class="badge badge-green">Enabled</span>
                    <?php else: ?>
                    <span class="badge badge-red">Disabled</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="user-meta-row">
                <span class="user-meta-label">Status</span>
                <span>
                    <?php if ($u['is_active']): ?>
                    <span class="badge badge-green">Active</span>
                    <?php else: ?>
                    <span class="badge badge-red">Inactive</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="user-meta-row">
                <span class="user-meta-label">Last Login</span>
                <span><?= $u['last_login'] ? formatDate($u['last_login']) : '<span class="text-muted">Never</span>' ?></span>
            </div>
        </div>

        <div class="user-actions">
            <a href="?edit=<?= (int)$u['id'] ?>" class="btn btn-sm btn-secondary">
                <i class="fas fa-edit"></i>
            </a>
            <?php if ($u['id'] !== currentUser()['id']): ?>
            <form method="POST" style="display:inline" id="toggleUserCardForm<?= (int)$u['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="confirm_password" id="toggleCardPwd<?= (int)$u['id'] ?>" value="">
                <button type="button" class="btn btn-sm btn-ghost" title="Toggle Active" onclick="confirmToggleUser(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(clean($u['username']), ENT_QUOTES, 'UTF-8') ?>')">
                    <i class="fas fa-power-off"></i>
                </button>
            </form>
            <button class="btn btn-sm btn-danger" onclick="confirmDeleteUser(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(clean($u['username']), ENT_QUOTES, 'UTF-8') ?>')">
                <i class="fas fa-trash"></i>
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$users): ?>
    <div style="grid-column: 1/-1; text-align: center; padding: 40px; color: #64748b;">
        <i class="fas fa-users fa-2x" style="margin-bottom: 12px; opacity: 0.5;"></i>
        <p>No users found.</p>
    </div>
    <?php endif; ?>
</div>

<!-- ── Add/Edit User Modal ──────────────────────────────────── -->
<div class="modal-overlay" id="userModal" style="display:<?= $showUserModal ? 'flex' : 'none' ?>">
    <div class="modal glass-card" style="max-width:600px;max-height:90vh;overflow-y:auto">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas <?= $isEditingUser ? 'fa-user-edit' : 'fa-user-plus' ?>"></i>
                <?= $isEditingUser ? 'Edit User' : 'Add New User' ?>
            </h3>
            <button class="modal-close" onclick="closeUserModal()">×</button>
        </div>
        <form method="POST" action="" class="user-modal-form">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="uid" value="<?= $isEditingUser ? (int)$editUser['id'] : 0 ?>">

            <!-- Basic Info Section -->
            <div class="form-section">
                <div class="form-section-title">Basic Information</div>
                
                <div class="form-group">
                    <label class="form-label required">Full Name</label>
                    <input type="text" name="full_name" class="form-input" required
                           value="<?= clean($editUser['full_name'] ?? '') ?>"
                           placeholder="e.g. John Doe">
                    <?php if (!empty($errors['full_name'])): ?><span class="form-error"><?= $errors['full_name'] ?></span><?php endif; ?>
                </div>
                
                <div class="form-two-col">
                    <div class="form-group">
                        <label class="form-label required">Username</label>
                        <input type="text" name="username" class="form-input" required
                               value="<?= clean($editUser['username'] ?? '') ?>"
                               placeholder="e.g. john.doe">
                        <?php if (!empty($errors['username'])): ?><span class="form-error"><?= $errors['username'] ?></span><?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input"
                               value="<?= clean($editUser['email'] ?? '') ?>"
                               placeholder="john@example.com">
                        <?php if (!empty($errors['email'])): ?><span class="form-error"><?= clean($errors['email']) ?></span><?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Role & Permissions Section -->
            <div class="form-section">
                <div class="form-section-title">Role & Permissions</div>
                
                <div class="form-group">
                    <label class="form-label required">User Role</label>
                    <select name="role" id="userRole" class="form-select" required onchange="updateDistrictFieldVisibility()">
                        <option value="">Select a role...</option>
                        <option value="admin" <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>>🔴 Administrator - Full system access</option>
                        <option value="hr" <?= ($editUser['role'] ?? '') === 'hr' ? 'selected' : '' ?>>🔵 HR / Personnel - Teachers & staff management</option>
                        <option value="school_head" <?= ($editUser['role'] ?? '') === 'school_head' ? 'selected' : '' ?>>🟣 School Head - School operations</option>
                        <option value="unit_head" <?= ($editUser['role'] ?? '') === 'unit_head' ? 'selected' : '' ?>>🔷 Unit Head - Unit operations</option>
                        <option value="psds" <?= ($editUser['role'] ?? '') === 'psds' ? 'selected' : '' ?>>🩷 PSDS - Public Schools Division Supervisor</option>
                        <option value="sdc" <?= ($editUser['role'] ?? '') === 'sdc' ? 'selected' : '' ?>>🟧 SDC - Schools Division Coordinator</option>
                        <option value="viewer" <?= ($editUser['role'] ?? '') === 'viewer' ? 'selected' : '' ?>>⚫ Viewer - Read-only access</option>
                    </select>
                </div>

                <!-- District Selection for PSDS/SDC/Unit Head -->
                <div id="districtFieldContainer" style="display:none;">
                    <div class="form-group">
                        <label class="form-label">Assigned District</label>
                        <select name="district_id" id="districtSelect" class="form-input">
                            <option value="">— No District —</option>
                            <?php foreach ($allDistricts as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= ((int)($editUser['district_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
                                <?= clean($d['district_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['district_id'])): ?><span class="form-error"><?= $errors['district_id'] ?></span><?php endif; ?>
                        <small class="text-muted">Select the district this user is assigned to.</small>
                    </div>
                </div>
            </div>

            <!-- Password Section -->
            <div class="form-section">
                <div class="form-section-title">Authentication</div>
                
                <div class="form-group">
                    <label class="form-label <?= !$isEditingUser ? 'required' : '' ?>">Password <?= $isEditingUser ? '(leave blank to keep current)' : '' ?></label>
                    <div class="input-with-toggle">
                        <input type="password" name="password" id="newPassword" class="form-input"
                               <?= !$isEditingUser ? 'required' : '' ?>
                               placeholder="<?= $isEditingUser ? 'Leave blank to keep current' : 'Set password' ?>">
                        <button type="button" class="toggle-password" data-target="newPassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <?php if (!empty($errors['password'])): ?><span class="form-error"><?= $errors['password'] ?></span><?php endif; ?>
                </div>

                <label class="checkbox-label">
                    <input type="checkbox" name="twofa_enabled" value="1" <?= ((int)($editUser['twofa_enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
                    <span><strong>Require 2FA</strong> - User must use authenticator app</span>
                </label>

                <?php if ($isEditingUser): ?>
                <label class="checkbox-label">
                    <input type="checkbox" name="regenerate_2fa" value="1">
                    <span>Regenerate authenticator secret on save</span>
                </label>
                <?php endif; ?>
            </div>

            <!-- Status Section -->
            <div class="form-section">
                <label class="checkbox-label">
                    <input type="checkbox" name="is_active" value="1" <?= ($editUser['is_active'] ?? 1) ? 'checked' : '' ?>>
                    <span><strong>Account is Active</strong></span>
                </label>
            </div>

            <!-- Confirmation -->
            <?php if ($isEditingUser): ?>
            <div class="form-section">
                <div class="form-group">
                    <label class="form-label required">Confirm Your Password</label>
                    <input type="password" name="confirm_password" id="editUserConfirmPassword" class="form-input" required
                           placeholder="Enter your password to confirm changes">
                    <?php if (!empty($errors['confirm_password'])): ?><span class="form-error"><?= $errors['confirm_password'] ?></span><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Actions -->
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeUserModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas <?= $isEditingUser ? 'fa-save' : 'fa-user-plus' ?>"></i>
                    <?= $isEditingUser ? 'Update User' : 'Create User' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function closeUserModal() {
    document.getElementById('userModal').style.display = 'none';
    document.getElementById('userRole').value = '';
    updateDistrictFieldVisibility();
}

function updateDistrictFieldVisibility() {
    const role = document.getElementById('userRole').value;
    const container = document.getElementById('districtFieldContainer');
    if (['psds', 'sdc', 'unit_head'].includes(role)) {
        container.style.display = 'block';
    } else {
        container.style.display = 'none';
    }
}

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
    updateDistrictFieldVisibility();
});

function setUsersView(mode) {
    const listWrap = document.getElementById('usersListView');
    const cardWrap = document.getElementById('usersCardView');
    const listBtn  = document.getElementById('usersViewListBtn');
    const cardBtn  = document.getElementById('usersViewCardBtn');

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
    localStorage.setItem('usersViewMode', mode);
}

document.getElementById('usersViewListBtn')?.addEventListener('click', () => setUsersView('list'));
document.getElementById('usersViewCardBtn')?.addEventListener('click', () => setUsersView('card'));
setUsersView(localStorage.getItem('usersViewMode') || 'list');

// Existing functions
async function promptPassword(message) {
    if (typeof Swal !== 'undefined') {
        const res = await Swal.fire({
            title: 'Confirm Password',
            text: message,
            input: 'password',
            inputPlaceholder: 'Your password',
            inputAttributes: { autocomplete: 'current-password', autocapitalize: 'off' },
            showCancelButton: true,
            confirmButtonText: 'Continue',
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

function confirmToggleUser(id, username) {
    Swal.fire({
        title: 'Toggle User Status',
        text: 'Toggle active status for ' + username + '?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes'
    }).then(r => {
        if (r.isConfirmed) {
            promptPassword('Enter your password:').then(pwd => {
                if (pwd) {
                    document.getElementById('togglePwd' + id).value = pwd;
                    document.getElementById('toggleUserForm' + id).submit();
                }
            });
        }
    });
}

function confirmDeleteUser(id, username) {
    Swal.fire({
        title: 'Delete User',
        text: 'Permanently delete user: ' + username + '?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#ef4444'
    }).then(r => {
        if (r.isConfirmed) {
            promptPassword('Enter your password to confirm:').then(pwd => {
                if (pwd) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="csrf_token" value="${document.querySelector('[name="csrf_token"]').value}">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="uid" value="${id}">
                        <input type="hidden" name="confirm_password" value="${pwd}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
