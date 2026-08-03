<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$project_id = intval($_GET['project_id'] ?? 0);
if (!$project_id) redirect(BASE_URL . '/projects/list.php');

$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) {
    set_flash('danger', 'Project not found.');
    redirect(BASE_URL . '/projects/list.php');
}

$vendors = $pdo->prepare("SELECT * FROM vendors WHERE project_id = ? ORDER BY vendor_name");
$vendors->execute([$project_id]);
$vendors = $vendors->fetchAll();

// ─── Handle POST ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/tracking/manual.php?project_id=' . $project_id);
    }

    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $custom_click_id = trim($_POST['click_id'] ?? '');

    if (!$vendor_id || empty($reason)) {
        set_flash('danger', 'Vendor and reason are required.');
        redirect(BASE_URL . '/tracking/manual.php?project_id=' . $project_id);
    }

    $click_id = $custom_click_id ?: bin2hex(random_bytes(16));

    if ($custom_click_id) {
        $check = $pdo->prepare("SELECT * FROM clicks WHERE click_id = ?");
        $check->execute([$click_id]);
        $existing = $check->fetch();

        if ($existing) {
            if ($existing['is_converted']) {
                set_flash('danger', 'This click is already converted.');
                redirect(BASE_URL . '/tracking/manual.php?project_id=' . $project_id);
            }
            $vendor_id = $existing['vendor_id'];
        }
    }

    $vstmt = $pdo->prepare("SELECT vendor_cpi FROM vendors WHERE id = ? AND project_id = ?");
    $vstmt->execute([$vendor_id, $project_id]);
    $vendor = $vstmt->fetch();

    if (!$vendor) {
        set_flash('danger', 'Vendor not found or does not belong to this project.');
        redirect(BASE_URL . '/tracking/manual.php?project_id=' . $project_id);
    }

    $revenue = $project['client_cpi'];
    $cost = $vendor['vendor_cpi'];
    $profit = $revenue - $cost;

    if (!$custom_click_id) {
        $pdo->prepare("INSERT INTO clicks (click_id, project_id, vendor_id, ip_address, user_agent, is_converted) VALUES (?, ?, ?, 'manual', 'manual', 1)")
            ->execute([$click_id, $project_id, $vendor_id]);
        $pdo->prepare("UPDATE projects SET clicks_count = clicks_count + 1 WHERE id = ?")->execute([$project_id]);
    } else {
        $pdo->prepare("UPDATE clicks SET is_converted = 1 WHERE click_id = ?")->execute([$click_id]);
    }

    $pdo->prepare("INSERT INTO conversions (click_id, project_id, vendor_id, status, client_revenue, vendor_cost, profit, is_manual) VALUES (?, ?, ?, 'complete', ?, ?, ?, 1)")
        ->execute([$click_id, $project_id, $vendor_id, $revenue, $cost, $profit]);

    $pdo->prepare("UPDATE projects SET completes_count = completes_count + 1 WHERE id = ?")->execute([$project_id]);

    if ($project['total_quota'] > 0) {
        $count_stmt = $pdo->prepare("SELECT completes_count FROM projects WHERE id = ?");
        $count_stmt->execute([$project_id]);
        $new_count = $count_stmt->fetch()['completes_count'];

        if ($new_count >= $project['total_quota']) {
            $pdo->prepare("UPDATE projects SET status = 'hold' WHERE id = ?")->execute([$project_id]);
            $pdo->prepare("UPDATE vendors SET status = 'paused' WHERE project_id = ? AND status = 'active'")->execute([$project_id]);
        }
    }

    $pdo->prepare("INSERT INTO logs (log_type, project_id, vendor_id, click_id, status, message) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute(['manual', $project_id, $vendor_id, $click_id, 'success', 'Manual conversion: ' . $reason]);

    regenerate_csrf_token();
    set_flash('success', 'Manual conversion recorded.');
    redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
}

$page_title = 'Manual Conversion';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="mb-4">
    <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $project_id; ?>" class="tf-back-link">
        <i class="bi bi-arrow-left"></i>Back to Project
    </a>
</div>

<div class="max-w-2xl mx-auto">
    <div class="tf-card">
        <div class="tf-card-header">
            <h2 class="tf-card-title">Record Manual Conversion</h2>
        </div>
        <div class="tf-card-body">
            <div class="alert alert-info">
                <div class="alert-body"><i class="bi bi-info-circle"></i> Manual conversions are flagged in the system and displayed separately in reports.</div>
            </div>

            <form method="POST" class="tf-form">
                <?php echo csrf_field(); ?>

                <div class="tf-field">
                    <label for="vendor_id" class="tf-label">Vendor <span class="tf-required">*</span></label>
                    <select id="vendor_id" name="vendor_id" class="form-select" required>
                        <option value="">Select Vendor</option>
                        <?php foreach ($vendors as $v): ?>
                        <option value="<?php echo $v['id']; ?>"><?php echo sanitize($v['vendor_name']); ?> (CPI: <?php echo format_currency($v['vendor_cpi']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="tf-field">
                    <label for="click_id" class="tf-label">Click ID <span class="text-xs text-slate-500 font-normal">(optional)</span></label>
                    <input type="text" id="click_id" name="click_id" class="form-control tf-mono"
                           placeholder="Leave blank to auto-generate">
                    <p class="tf-help">Enter an existing click_id to convert it, or leave blank to create a new one.</p>
                </div>

                <div class="tf-field">
                    <label for="reason" class="tf-label">Reason <span class="tf-required">*</span></label>
                    <textarea id="reason" name="reason" class="form-control" rows="3"
                              placeholder="Why is this being recorded manually?" required></textarea>
                </div>

                <div class="form-actions">
                    <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $project_id; ?>" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i>Record Conversion</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>