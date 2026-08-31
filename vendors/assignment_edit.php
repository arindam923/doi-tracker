<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$project_id = intval($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$vendor_id = intval($_GET['vendor_id'] ?? $_POST['vendor_id'] ?? 0);
if (!$project_id || !$vendor_id) {
    redirect(BASE_URL . '/projects/list.php');
}

$stmt = $pdo->prepare("
    SELECT pv.*, gv.vendor_name, gv.vendor_code, gv.global_postback_url, p.project_name, p.project_code, p.daily_cap AS campaign_daily_cap, p.currency AS project_currency
    FROM project_vendor pv
    JOIN global_vendors gv ON gv.id = pv.vendor_id
    JOIN projects p ON p.id = pv.project_id
    WHERE pv.project_id = ? AND pv.vendor_id = ?
");
$stmt->execute([$project_id, $vendor_id]);
$row = $stmt->fetch();
if (!$row) {
    set_flash('danger', 'Vendor is not attached to this project.');
    redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/vendors/assignment_edit.php?project_id=' . $project_id . '&vendor_id=' . $vendor_id);
    }

    $payout = floatval($_POST['payout'] ?? 0);
    $daily_cap = intval($_POST['daily_cap'] ?? 0);
    $status = $_POST['status'] ?? 'active';
    if (!in_array($status, ['active', 'hold', 'closed'], true)) {
        $status = 'active';
    }
    $postback_url = trim($_POST['postback_url'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    if ($payout < 0) $payout = 0;
    if ($daily_cap < 0) $daily_cap = 0;
    if (!tf_is_valid_postback_url($postback_url)) {
        set_flash('danger', 'Project override postback URL must be a valid HTTP or HTTPS URL.');
        redirect(BASE_URL . '/vendors/assignment_edit.php?project_id=' . $project_id . '&vendor_id=' . $vendor_id);
    }
    $postback_url = tf_resolve_postback_url($postback_url, $row['global_postback_url'] ?? '');

    $pdo->prepare("
        UPDATE project_vendor
        SET payout = ?, daily_cap = ?, status = ?, postback_url = ?, notes = ?
        WHERE project_id = ? AND vendor_id = ?
    ")->execute([$payout, $daily_cap, $status, $postback_url, $notes, $project_id, $vendor_id]);

    audit_log($pdo, 'update', 'project_vendor', $project_id . ':' . $vendor_id, [
        'payout' => $row['payout'],
        'daily_cap' => $row['daily_cap'],
        'status' => $row['status'],
        'postback_configured' => !empty($row['postback_url']),
    ], [
        'payout' => $payout,
        'daily_cap' => $daily_cap,
        'status' => $status,
        'postback_configured' => $postback_url !== '',
    ]);

    regenerate_csrf_token();
    set_flash('success', 'Assignment updated for ' . sanitize($row['vendor_name']) . '.');
    redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
}

$page_title = 'Edit vendor assignment';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="mb-4">
    <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $project_id; ?>" class="tf-back-link">
        <i class="bi bi-arrow-left"></i>Back to Project
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-12 col-lg-7">
        <div class="tf-card">
            <div class="tf-card-header">
                <h5 class="mb-0 fw-semibold">Edit assignment</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    <?php echo sanitize($row['vendor_name']); ?> (<?php echo sanitize($row['vendor_code']); ?>)
                    on <?php echo sanitize($row['project_code']); ?> — <?php echo sanitize($row['project_name']); ?>.
                    This does not change the vendor library default payout.
                </p>
                <?php if ((int)$row['campaign_daily_cap'] > 0): ?>
                <p class="small">Campaign daily cap: <strong><?php echo number_format((int)$row['campaign_daily_cap']); ?></strong> completes. Vendor cap is independent; campaign cap is still the hard ceiling.</p>
                <?php endif; ?>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    <input type="hidden" name="vendor_id" value="<?php echo $vendor_id; ?>">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="tf-label" for="payout">Payout</label>
                            <input type="number" step="0.01" min="0" id="payout" name="payout" class="form-control" value="<?php echo sanitize((string)$row['payout']); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="tf-label" for="daily_cap">Daily cap (completes)</label>
                            <input type="number" min="0" id="daily_cap" name="daily_cap" class="form-control" value="<?php echo (int)$row['daily_cap']; ?>">
                            <div class="form-text">0 = no vendor-level daily limit</div>
                        </div>
                        <div class="col-6">
                            <label class="tf-label" for="status">Status</label>
                            <select id="status" name="status" class="form-select">
                                <?php foreach (['active' => 'Active', 'hold' => 'Hold', 'closed' => 'Closed'] as $k => $lab): ?>
                                <option value="<?php echo $k; ?>" <?php echo $row['status'] === $k ? 'selected' : ''; ?>><?php echo $lab; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="tf-label" for="postback_url">Project Override Postback URL</label>
                            <input type="text" id="postback_url" name="postback_url" class="form-control" value="<?php echo sanitize($row['postback_url'] ?? ''); ?>" placeholder="https://…">
                            <div class="form-text">Global vendor postback: <code><?php echo sanitize($row['global_postback_url'] ?? 'Not configured'); ?></code>. Clear this field and save to restore the global default.</div>
                        </div>
                        <div class="col-12">
                            <label class="tf-label" for="notes">Notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="3"><?php echo sanitize($row['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $project_id; ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save assignment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
