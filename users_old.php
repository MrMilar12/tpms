<?php
$pageTitle = 'Users';
require_once __DIR__ . '/includes/header.php';
requireRole(['admin']);

$db    = getDB();
ensureTwoFactorColumns($db);

$search = clean(trim((string)($_GET['q'] ?? '')));
if (strlen($search) > 200) {
    flash('error', 'Search term is too long.');
    redirect(APP_URL . '/users.php');
}

$usersSql = 'SELECT id, username, full_name, email, role, is_active, COALESCE(twofa_enabled,0) AS twofa_enabled, last_login, created_at
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

$editUser = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT id, username, full_name, email, role, is_active, COALESCE(twofa_enabled,0) AS twofa_enabled FROM users WHERE id = ?');
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
        $active   = isset($_POST['is_active']) ? 1 : 0;
        $twofaEnabled = isset($_POST['twofa_enabled']) ? 1 : 0;
        $regenerate2fa = isset($_POST['regenerate_2fa']) ? 1 : 0;
        $password = $_POST['password']        ?? '';
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($username === '')  $errors['username']  = 'Required.';
        if ($fullName === '')  $errors['full_name']  = 'Required.';
        if (!in_array($role, ['admin','hr','school_head','viewer'], true)) $errors['role'] = 'Invalid role.';

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
                        'is_active' => $active,
                        'twofa_enabled' => $twofaEnabled,
                    ];
                }
            }

            if (!$errors && $uid > 0) {
                // Update
                $sql = 'UPDATE users SET username=?, full_name=?, email=?, role=?, is_active=?, twofa_enabled=?, twofa_secret=?, updated_at=NOW() WHERE id=?';
                $params = [$username, $fullName, $email ?: null, $role, $active, $twofaEnabled, $twofaSecret, $uid];
                if ($password !== '') {
                    $sql    = 'UPDATE users SET username=?, full_name=?, email=?, role=?, is_active=?, twofa_enabled=?, twofa_secret=?, password_hash=?, updated_at=NOW() WHERE id=?';
                    $params = [$username, $fullName, $email ?: null, $role, $active, $twofaEnabled, $twofaSecret, password_hash($password, PASSWORD_BCRYPT), $uid];
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
                            'INSERT INTO users (username, password_hash, full_name, email, role, is_active, twofa_enabled, twofa_secret) VALUES (?,?,?,?,?,?,?,?)'
                        )->execute([$username, password_hash($password, PASSWORD_BCRYPT), $fullName, $email ?: null, $role, $active, $twofaEnabled, $twofaSecret]);
                        logActivity('CREATE', 'users', (int)$db->lastInsertId(), "Created user: $username");
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
?>

<!-- ── Users Table ──────────────────────────────────────────── -->
<style>
.user-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}

.user-card {
    padding: 14px;
    border: 1px solid rgba(148, 163, 184, 0.28);
}

.user-card .teacher-mini {
    margin-bottom: 10px;
}

.user-card-meta {
    display: grid;
    gap: 8px;
    margin-bottom: 12px;
}

.user-card-row {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: center;
}

.user-card-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}
</style>

<div class="filter-bar glass-card">
    <form method="GET" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <div class="topbar-title">Manage System Users</div>
        <div class="search-box" style="min-width:260px;max-width:420px;">
            <i class="fas fa-search search-icon"></i>
            <input type="text" name="q" class="form-input" placeholder="Search username, name, email, role..." value="<?= clean($search) ?>">
        </div>
        <button type="submit" class="btn btn-ghost"><i class="fas fa-filter"></i> Search</button>
        <?php if ($search !== ''): ?>
        <a href="<?= APP_URL ?>/users.php" class="btn btn-ghost"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
    </form>
    <div class="filter-actions">
        <button type="button" class="btn btn-ghost" id="usersViewListBtn">
            <i class="fas fa-list"></i> List
        </button>
        <button type="button" class="btn btn-ghost" id="usersViewCardBtn">
            <i class="fas fa-th-large"></i> Card
        </button>
        <button class="btn btn-primary"
                onclick="document.getElementById('userModal').style.display='flex'">
            <i class="fas fa-user-plus"></i> Add User
        </button>
    </div>
</div>

<div class="table-card glass-card" id="usersListView">
    <div class="table-scroll">
        <table class="data-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Username</th>
                    <th>Role</th>
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
                <td><?= roleBadge($u['role']) ?></td>
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
            <tr><td colspan="7" class="text-center text-muted">No users found<?= $search !== '' ? ' for "' . clean($search) . '"' : '' ?>.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="user-card-grid" id="usersCardView" style="display:none; margin-top:12px;">
    <?php foreach ($users as $u): ?>
    <div class="user-card glass-card">
        <div class="teacher-mini">
            <div class="mini-avatar"><?= strtoupper(substr($u['full_name'],0,1)) ?></div>
            <div>
                <div><?= clean($u['full_name']) ?></div>
                <div class="text-muted small"><?= clean($u['email'] ?? '') ?></div>
            </div>
        </div>

        <div class="user-card-meta">
            <div class="user-card-row">
                <span class="text-muted small">Username</span>
                <code><?= clean($u['username']) ?></code>
            </div>
            <div class="user-card-row">
                <span class="text-muted small">Role</span>
                <span><?= roleBadge($u['role']) ?></span>
            </div>
            <div class="user-card-row">
                <span class="text-muted small">2FA</span>
                <span>
                    <?php if ((int)$u['twofa_enabled'] === 1): ?>
                    <span class="badge badge-green">Enabled</span>
                    <?php else: ?>
                    <span class="badge badge-red">Disabled</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="user-card-row">
                <span class="text-muted small">Status</span>
                <span>
                    <?php if ($u['is_active']): ?>
                    <span class="badge badge-green">Active</span>
                    <?php else: ?>
                    <span class="badge badge-red">Inactive</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="user-card-row">
                <span class="text-muted small">Last Login</span>
                <span><?= $u['last_login'] ? formatDate($u['last_login']) : '<span class="text-muted">Never</span>' ?></span>
            </div>
        </div>

        <div class="user-card-actions">
            <a href="?edit=<?= (int)$u['id'] ?>" class="btn btn-sm btn-secondary">
                <i class="fas fa-edit"></i>
            </a>
            <?php if ($u['id'] !== currentUser()['id']): ?>
            <form method="POST" style="display:inline" id="toggleUserCardForm<?= (int)$u['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="confirm_password" id="toggleCardPwd<?= (int)$u['id'] ?>" value="">
                <button type="button" class="btn btn-sm btn-ghost" title="Toggle Active" onclick="confirmToggleUser(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(clean($u['username']), ENT_QUOTES, 'UTF-8') ?>', 'toggleUserCardForm', 'toggleCardPwd')">
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
</div>

<!-- Add/Edit User Modal -->
<div class="modal-overlay" id="userModal" style="display:<?= $editUser ? 'flex' : 'none' ?>">
    <div class="modal glass-card" style="max-width:520px">
        <div class="modal-header">
            <h3 class="modal-title"><?= $editUser ? 'Edit User' : 'Add User' ?></h3>
            <button class="modal-close" onclick="document.getElementById('userModal').style.display='none'">×</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="uid" value="<?= $editUser ? (int)$editUser['id'] : 0 ?>">

            <div class="form-group" style="margin-bottom:12px">
                <label class="form-label required">Full Name</label>
                <input type="text" name="full_name" class="form-input" required
                       value="<?= clean($editUser['full_name'] ?? '') ?>">
                <?php if (!empty($errors['full_name'])): ?><span class="form-error"><?= $errors['full_name'] ?></span><?php endif; ?>
            </div>
            <div class="form-group" style="margin-bottom:12px">
                <label class="form-label required">Username</label>
                <input type="text" name="username" class="form-input" required
                       value="<?= clean($editUser['username'] ?? '') ?>">
                <?php if (!empty($errors['username'])): ?><span class="form-error"><?= $errors['username'] ?></span><?php endif; ?>
            </div>
            <div class="form-group" style="margin-bottom:12px">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input"
                       value="<?= clean($editUser['email'] ?? '') ?>">
            </div>
            <div class="form-group" style="margin-bottom:12px">
                <label class="form-label">Password <?= $editUser ? '(leave blank to keep)' : '' ?></label>
                <div class="input-with-toggle">
                    <input type="password" name="password" id="newPassword" class="form-input"
                           <?= !$editUser ? 'required' : '' ?> placeholder="<?= $editUser ? 'Leave blank to keep current' : 'Set password' ?>">
                    <button type="button" class="toggle-password" data-target="newPassword">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <?php if (!empty($errors['password'])): ?><span class="form-error"><?= $errors['password'] ?></span><?php endif; ?>
            </div>
            <div class="form-group" style="margin-bottom:12px">
                <label class="form-label required">Role</label>
                <select name="role" class="form-select">
                    <?php foreach (['admin'=>'Admin','hr'=>'HR / Personnel','school_head'=>'School Head','viewer'=>'Viewer'] as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= ($editUser['role'] ?? 'viewer') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="checkbox-label" style="margin-bottom:12px">
                <input type="checkbox" name="twofa_enabled" value="1" <?= ((int)($editUser['twofa_enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
                <span>Require Authenticator App (2FA)</span>
            </label>

            <div class="form-group" style="margin-bottom:12px">
                <label class="form-label">Authenticator Secret</label>
                <input type="text" class="form-input" value="Hidden for security" readonly>
                <small class="text-muted">For security, existing authenticator secrets are never displayed. Use regenerate to issue a new secret.</small>
            </div>
            <label class="checkbox-label" style="margin-bottom:12px">
                <input type="checkbox" name="regenerate_2fa" value="1">
                <span>Regenerate authenticator secret on save</span>
            </label>
            <label class="checkbox-label" style="margin-bottom:16px">
                <input type="checkbox" name="is_active" value="1" <?= ($editUser['is_active'] ?? 1) ? 'checked' : '' ?>>
                <span>Account is Active</span>
            </label>
            <?php if ($editUser): ?>
            <input type="hidden" name="confirm_password" id="editUserConfirmPassword" value="">
            <?php endif; ?>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('userModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary"><?= $editUser ? 'Update User' : 'Create User' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Delete User Modal -->
<div class="modal-overlay" id="deleteUserModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 class="modal-title">Delete User</h3>
        <p class="modal-body">Are you sure you want to delete user <strong id="delUserName"></strong>?</p>
        <div class="modal-actions">
            <button onclick="document.getElementById('deleteUserModal').style.display='none'" class="btn btn-ghost">Cancel</button>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="uid" id="delUserId">
                <input type="hidden" name="confirm_password" id="delUserPassword">
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    </div>
</div>

<script>
async function confirmDeleteUser(id, name) {
    document.getElementById('delUserName').textContent = name;
    document.getElementById('delUserId').value = id;
    const passwordInput = document.getElementById('delUserPassword');
    if (passwordInput) passwordInput.value = '';

    if (typeof Swal !== 'undefined') {
        const res = await Swal.fire({
            title: 'Confirm Password',
            input: 'password',
            inputPlaceholder: 'Enter your password',
            inputAttributes: {
                autocapitalize: 'off',
                autocorrect: 'off',
                autocomplete: 'current-password',
            },
            showCancelButton: true,
            confirmButtonText: 'Continue',
            preConfirm: (value) => {
                if (!value) {
                    Swal.showValidationMessage('Password is required.');
                    return false;
                }
                return value;
            },
        });
        if (!res.isConfirmed) return;
        if (passwordInput) passwordInput.value = res.value;
    } else {
        const pwd = prompt('Enter your password to confirm deletion:');
        if (!pwd) return;
        if (passwordInput) passwordInput.value = pwd;
    }

    document.getElementById('deleteUserModal').style.display = 'flex';
}

async function confirmToggleUser(id, username, formPrefix = 'toggleUserForm', pwdPrefix = 'togglePwd') {
    const form = document.getElementById(formPrefix + id);
    const pwdInput = document.getElementById(pwdPrefix + id);
    if (!form || !pwdInput) return;

    if (typeof Swal !== 'undefined') {
        const res = await Swal.fire({
            title: 'Confirm Password',
            text: 'Confirm status change for ' + username,
            input: 'password',
            inputPlaceholder: 'Enter your password',
            inputAttributes: {
                autocapitalize: 'off',
                autocorrect: 'off',
                autocomplete: 'current-password',
            },
            showCancelButton: true,
            confirmButtonText: 'Confirm',
            preConfirm: (value) => {
                if (!value) {
                    Swal.showValidationMessage('Password is required.');
                    return false;
                }
                return value;
            },
        });
        if (!res.isConfirmed) return;
        pwdInput.value = res.value;
    } else {
        const pwd = prompt('Enter your password to confirm status change:');
        if (!pwd) return;
        pwdInput.value = pwd;
    }

    form.submit();
}

(function () {
    const listBtn = document.getElementById('usersViewListBtn');
    const cardBtn = document.getElementById('usersViewCardBtn');
    const listView = document.getElementById('usersListView');
    const cardView = document.getElementById('usersCardView');
    const storageKey = 'usersViewMode';

    if (!listBtn || !cardBtn || !listView || !cardView) return;

    function applyMode(mode) {
        const isCard = mode === 'card';
        listView.style.display = isCard ? 'none' : 'block';
        cardView.style.display = isCard ? 'grid' : 'none';

        listBtn.classList.toggle('btn-primary', !isCard);
        listBtn.classList.toggle('btn-ghost', isCard);
        cardBtn.classList.toggle('btn-primary', isCard);
        cardBtn.classList.toggle('btn-ghost', !isCard);

        try { localStorage.setItem(storageKey, isCard ? 'card' : 'list'); } catch (e) {}
    }

    listBtn.addEventListener('click', function () { applyMode('list'); });
    cardBtn.addEventListener('click', function () { applyMode('card'); });

    let initialMode = 'list';
    try { initialMode = localStorage.getItem(storageKey) || 'list'; } catch (e) {}
    applyMode(initialMode === 'card' ? 'card' : 'list');
})();

async function promptEditUserPassword(message) {
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

document.querySelector('#userModal form')?.addEventListener('submit', async function(e) {
    const uid = Number(this.querySelector('input[name="uid"]')?.value || 0);
    if (!uid || this.dataset.confirmed === '1') return;
    e.preventDefault();
    const pwd = await promptEditUserPassword('Enter your password to save user changes:');
    if (!pwd) return;
    const confirmField = document.getElementById('editUserConfirmPassword');
    if (confirmField) confirmField.value = pwd;
    this.dataset.confirmed = '1';
    this.submit();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
