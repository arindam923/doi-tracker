<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin']);

$action = $_GET['action'] ?? 'list';
$edit_id = intval($_GET['id'] ?? 0);

// ─── Handle POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/settings/vendor_portal_users.php');
    }

    $post_action = $_POST['post_action'] ?? 'create';

    if ($post_action === 'create') {
        $global_vendor_id = intval($_POST['global_vendor_id'] ?? 0);
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        $errors = [];
        if (!$global_vendor_id) $errors[] = 'Vendor selection is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';

        if (empty($errors)) {
        $existing_stmt = $pdo->prepare("SELECT id FROM vendor_portal_users WHERE email = ?");
        $existing_stmt->execute([$email]);
        $existing_row = $existing_stmt->fetch();
        if ($existing_row) {
                $errors[] = 'That email is already linked to a portal account.';
            } else {
                $gv_stmt = $pdo->prepare("SELECT id, vendor_code, vendor_name FROM global_vendors WHERE id = ?");
                $gv_stmt->execute([$global_vendor_id]);
                $gv = $gv_stmt->fetch();
                if (!$gv) {
                    $errors[] = 'Selected vendor does not exist.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $pdo->prepare("INSERT INTO vendor_portal_users (global_vendor_id, email, password_hash, is_active) VALUES (?, ?, ?, 1)")
                            ->execute([$global_vendor_id, $email, $hash]);

                        audit_log($pdo, 'create', 'vendor_portal_user', $pdo->lastInsertId(), null, [
                            'global_vendor_id' => $global_vendor_id,
                            'vendor_code' => $gv['vendor_code'],
                            'email' => $email,
                        ]);

                        $pdo->commit();
                        set_flash('success', 'Portal account created for ' . sanitize($gv['vendor_name']) . ' (' . sanitize($gv['vendor_code']) . ')');
                        redirect(BASE_URL . '/settings/vendor_portal_users.php');
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $errors[] = 'Failed: ' . $e->getMessage();
                    }
                }
            }
        }
        if (!empty($errors)) set_flash('danger', implode(' | ', $errors));
        redirect(BASE_URL . '/settings/vendor_portal_users.php');
    }

    if ($post_action === 'update') {
        $vpu_id = intval($_POST['vpu_id'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $change_password = trim($_POST['new_password'] ?? '');

        $pdo->prepare("UPDATE vendor_portal_users SET is_active = ?, updated_at = NOW() WHERE id = ?")->execute([$is_active, $vpu_id]);

        if (strlen($change_password) >= 8) {
            $hash = password_hash($change_password, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE vendor_portal_users SET password_hash = ? WHERE id = ?")->execute([$hash, $vpu_id]);
            audit_log($pdo, 'edit', 'vendor_portal_user', $vpu_id, null, ['password_changed' => true]);
        }

        audit_log($pdo, 'update', 'vendor_portal_user', $vpu_id, null, ['is_active' => $is_active]);
        set_flash('success', 'Vendor portal account updated.');
        redirect(BASE_URL . '/settings/vendor_portal_users.php');
    }

    if ($post_action === 'delete') {
        $vpu_id = intval($_POST['vpu_id'] ?? 0);
        $pdo->prepare("DELETE FROM vendor_portal_users WHERE id = ?")->execute([$vpu_id]);
        audit_log($pdo, 'delete', 'vendor_portal_user', $vpu_id);
        set_flash('success', 'Portal account deleted.');
        redirect(BASE_URL . '/settings/vendor_portal_users.php');
    }
}

// ─── GET: render ────────────────────────────────────────────────
$global_vendors = $pdo->query("SELECT id, vendor_code, vendor_name, email, vendor_status FROM global_vendors ORDER BY vendor_name")->fetchAll();

$page_title = 'Vendor Portal Accounts';
$page_actions = '<a href="' . BASE_URL . '/settings/vendor_portal_users.php?action=new" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Add Account</a>';
require_once __DIR__ . '/../helpers/layout_header.php';
?>


<div class="tf-card mb-4" id="newAccountCard" style="<?php echo $action === 'new' ? '' : 'display:none;'; ?>">
    <div class="tf-card-header">
        <h5 class="mb-0 fw-semibold">Create Portal Account</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="post_action" value="create">
            <div class="row g-3">
                <div class="col-12">
                    <label class="tf-label">Global Vendor <span class="text-danger">*</span></label>
                    <select name="global_vendor_id" class="form-select" required>
                        <option value="">Select a vendor…</option>
                        <?php foreach ($global_vendors as $gv): ?>
                        <option value="<?php echo (int)$gv['id']; ?>">
                            <?php echo sanitize($gv['vendor_code']); ?> — <?php echo sanitize($gv['vendor_name']); ?> <?php echo !empty($gv['company_name']) ? '(' . sanitize($gv['company_name']) . ')' : ''; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="tf-label">Email Address <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" required maxlength="150" placeholder="vendor@example.com">
                    <p class="form-text mb-0">This email receives magic-link / password-reset emails.</p>
                </div>
                <div class="col-12">
                    <label class="tf-label">Password <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control" required minlength="8" maxlength="255">
                    <p class="form-text mb-0">Min 8 characters.</p>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                <a href="<?php echo BASE_URL; ?>/settings/vendor_portal_users.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i>Create Account</button>
            </div>
        </form>
    </div>
</div>

<?php
$stmt = $pdo->prepare("
    SELECT vpu.*, gv.vendor_code, gv.vendor_name, gv.company_name
    FROM vendor_portal_users vpu
    JOIN global_vendors gv ON gv.id = vpu.global_vendor_id
    ORDER BY vpu.created_at DESC
");
$stmt->execute();
$accounts = $stmt->fetchAll();
?>

<div class="tf-card">
    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-semibold"><?php echo number_format(count($accounts)); ?> portal account(s)</h5>
        <?php if ($action !== 'new'): ?>
        <a href="<?php echo BASE_URL; ?>/settings/vendor_portal_users.php?action=new" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i>Add Account</a>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-vpu align-middle mb-0">
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Last Login</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($accounts)): ?>
                <tr><td colspan="6" class="text-center py-5 text-muted">No portal accounts have been created yet.</td></tr>
                <?php else: foreach ($accounts as $a): ?>
                <tr>
                    <td>
                        <code><?php echo sanitize($a['vendor_code']); ?></code>
                        <div class="fw-semibold"><?php echo sanitize($a['vendor_name']); ?></div>
                        <?php if (!empty($a['company_name'])): ?><div class="small text-secondary"><?php echo sanitize($a['company_name']); ?></div><?php endif; ?>
                    </td>
                    <td><?php echo sanitize($a['email']); ?></td>
                    <td><?php echo $a['is_active']
                        ? '<span class="badge bg-success">Active</span>'
                        : '<span class="badge bg-light text-dark border">Suspended</span>'; ?></td>
                    <td class="small text-secondary"><?php echo sanitize(date('M j, Y H:i', strtotime($a['created_at']))); ?></td>
                    <td class="small text-secondary"><?php echo $a['last_login_at'] ? time_ago($a['last_login_at']) : '<span class="text-muted">Never</span>'; ?></td>
                    <td class="text-end">
                        <form method="POST" class="d-inline" onsubmit="return confirm('Suspend this portal account?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="post_action" value="update">
                            <input type="hidden" name="vpu_id" value="<?php echo (int)$a['id']; ?>">
                            <input type="hidden" name="is_active" value="0">
                            <button type="submit" class="btn btn-outline-warning btn-sm" title="Suspend">
                                <i class="bi bi-pause"></i>
                            </button>
                        </form>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Activate this portal account?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="post_action" value="update">
                            <input type="hidden" name="vpu_id" value="<?php echo (int)$a['id']; ?>">
                            <input type="hidden" name="is_active" value="1">
                            <button type="submit" class="btn btn-outline-success btn-sm" title="Activate">
                                <i class="bi bi-play"></i>
                            </button>
                        </form>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this portal account? The vendor remains in the global library.');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="post_action" value="delete">
                            <input type="hidden" name="vpu_id" value="<?php echo (int)$a['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>