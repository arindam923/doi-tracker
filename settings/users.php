<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin']);

// ─── Handle Password Change ───
if (isset($_GET['action']) && $_GET['action'] === 'change_password') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            set_flash('danger', 'Invalid form submission.');
            redirect(BASE_URL . '/settings/users.php?action=change_password');
        }

        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if (strlen($new_pass) < 8) {
            set_flash('danger', 'Password must be at least 8 characters.');
            redirect(BASE_URL . '/settings/users.php?action=change_password');
        }
        if ($new_pass !== $confirm_pass) {
            set_flash('danger', 'Passwords do not match.');
            redirect(BASE_URL . '/settings/users.php?action=change_password');
        }

        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $_SESSION['user_id']]);
        unset($_SESSION['force_password_change']);

        set_flash('success', 'Password changed successfully.');
        redirect(BASE_URL . '/dashboard.php');
    }

    $page_title = 'Change Password';
    require_once __DIR__ . '/../helpers/layout_header.php';
    ?>
    <div class="row justify-content-center">
        <div class="col-12 col-md-7 col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-semibold">Change Your Password</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <div class="mb-3">
                            <label for="new_password" class="form-label small fw-semibold text-secondary">New Password</label>
                            <input type="password" id="new_password" name="new_password" class="form-control" minlength="8" required>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label small fw-semibold text-secondary">Confirm Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="8" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Change Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../helpers/layout_footer.php';
    exit;
}

// ─── Handle Create/Edit User ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/settings/users.php');
    }

    $edit_id = intval($_POST['edit_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'campaign_manager';
    $allowed_roles = ['super_admin', 'campaign_manager', 'viewer'];
    if (!in_array($role, $allowed_roles)) {
        $role = 'campaign_manager';
    }
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (empty($username)) {
        set_flash('danger', 'Username is required.');
        redirect(BASE_URL . '/settings/users.php');
    }

    if ($edit_id) {
        if ($password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET username=?, email=?, password=?, role=?, is_active=? WHERE id=?")
                ->execute([$username, $email, $hash, $role, $is_active, $edit_id]);
        } else {
            $pdo->prepare("UPDATE users SET username=?, email=?, role=?, is_active=? WHERE id=?")
                ->execute([$username, $email, $role, $is_active, $edit_id]);
        }
        set_flash('success', 'User updated.');
    } else {
        if (strlen($password) < 8) {
            set_flash('danger', 'Password must be at least 8 characters.');
            redirect(BASE_URL . '/settings/users.php');
        }

        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            set_flash('danger', 'Username already exists.');
            redirect(BASE_URL . '/settings/users.php');
        }

        if (!empty($email)) {
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                set_flash('danger', 'Email already in use.');
                redirect(BASE_URL . '/settings/users.php');
            }
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, email, password, role, is_active) VALUES (?, ?, ?, ?, ?)")
            ->execute([$username, $email, $hash, $role, $is_active]);
        set_flash('success', 'User created.');
    }

    regenerate_csrf_token();
    redirect(BASE_URL . '/settings/users.php');
}

$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();

$page_title = 'User Management';
$page_actions = '<button class="btn btn-primary btn-sm" data-tf-modal-open="userModal" id="addUserBtn"><i class="bi bi-plus-lg"></i>Add User</button>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<style>
    .table-users thead th { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: #64748b; font-weight: 600; background: #f8fafc; }
    .table-users tbody td { vertical-align: middle; padding: .85rem 1rem; }
</style>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover table-users align-middle mb-0">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><strong><?php echo sanitize($u['username']); ?></strong></td>
                    <td><?php echo sanitize($u['email'] ?? '-'); ?></td>
                    <td><span class="badge bg-primary"><?php echo sanitize($u['role']); ?></span></td>
                    <td><?php echo $u['is_active']
                        ? '<span class="badge bg-success">Active</span>'
                        : '<span class="badge bg-light">Suspended</span>'; ?></td>
                    <td><?php echo $u['last_login'] ? time_ago($u['last_login']) : '<span class="text-muted">Never</span>'; ?></td>
                    <td><?php echo time_ago($u['created_at']); ?></td>
                    <td>
                        <button class="btn btn-outline-primary btn-sm" data-edit-user='<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8'); ?>' aria-label="Edit user">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- User Modal -->
<div id="userModal" class="tf-modal" hidden role="dialog" aria-modal="true" aria-labelledby="userModal-title">
    <div class="tf-modal-backdrop" data-tf-modal-close></div>
    <div class="tf-modal-dialog">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="edit_id" id="edit_id" value="">
            <div class="tf-modal-header">
                <h3 id="userModal-title" class="tf-modal-title">Add User</h3>
                <button type="button" class="tf-modal-close" data-tf-modal-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="tf-modal-body">
                <div class="mb-3">
                    <label for="username" class="form-label small fw-semibold text-secondary">Username <span class="text-danger">*</span></label>
                    <input type="text" id="username" name="username" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="email" class="form-label small fw-semibold text-secondary">Email</label>
                    <input type="email" id="email" name="email" class="form-control">
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label small fw-semibold text-secondary" id="password_label">Password <span class="text-danger">*</span></label>
                    <input type="password" id="password" name="password" class="form-control" minlength="8">
                    <p class="form-text" id="password_help">Min 8 characters</p>
                </div>
                <div class="mb-3">
                    <label for="role" class="form-label small fw-semibold text-secondary">Role</label>
                    <select id="role" name="role" class="form-select">
                        <option value="campaign_manager">Campaign Manager</option>
                        <option value="viewer">Viewer (Read-only)</option>
                        <option value="super_admin">Super Admin</option>
                    </select>
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="is_active" name="is_active" checked>
                    <label class="form-check-label" for="is_active">Active</label>
                </div>
            </div>
            <div class="tf-modal-footer">
                <button type="button" class="btn btn-secondary" data-tf-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function resetUserForm() {
    document.getElementById('edit_id').value = '';
    document.getElementById('username').value = '';
    document.getElementById('email').value = '';
    document.getElementById('password').value = '';
    document.getElementById('password').required = true;
    document.getElementById('role').value = 'campaign_manager';
    document.getElementById('is_active').checked = true;
    document.getElementById('userModal-title').textContent = 'Add User';
    document.getElementById('password_label').innerHTML = 'Password <span class="text-danger">*</span>';
    document.getElementById('password_help').textContent = 'Min 8 characters';
}

function editUser(user) {
    document.getElementById('edit_id').value = user.id;
    document.getElementById('username').value = user.username;
    document.getElementById('email').value = user.email || '';
    document.getElementById('password').value = '';
    document.getElementById('password').required = false;
    document.getElementById('role').value = user.role;
    document.getElementById('is_active').checked = user.is_active == 1;
    document.getElementById('userModal-title').textContent = 'Edit User';
    document.getElementById('password_label').innerHTML = 'New Password <span class="small text-muted fw-normal">(leave blank to keep current)</span>';
    document.getElementById('password_help').textContent = '';
    openModal('userModal');
}

document.getElementById('addUserBtn').addEventListener('click', resetUserForm);
document.querySelectorAll('[data-edit-user]').forEach(btn => {
    btn.addEventListener('click', () => {
        editUser(JSON.parse(btn.dataset.editUser));
    });
});

if (Math.random() < 0.02) {
    fetch('<?php echo BASE_URL; ?>/auth.php?action=cleanup_ratelimits', { method: 'GET', mode: 'no-cors' });
}
</script>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
