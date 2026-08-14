<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$project_id = intval($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$global_vendor_id = intval($_GET['vendor_id'] ?? $_POST['global_vendor_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
    }
    if (!$project_id || !$global_vendor_id) {
        set_flash('danger', 'Missing project or vendor.');
        redirect(BASE_URL . '/projects/list.php');
    }

    try {
        $pdo->beginTransaction();

        $gv_stmt = $pdo->prepare("SELECT vendor_name FROM global_vendors WHERE id = ?");
        $gv_stmt->execute([$global_vendor_id]);
        $gv = $gv_stmt->fetch();

        // Remove pivot row
        $pdo->prepare("DELETE FROM project_vendor WHERE project_id = ? AND vendor_id = ?")
            ->execute([$project_id, $global_vendor_id]);

        // Remove per-vendor short link (orphan)
        $pdo->prepare("DELETE FROM short_links WHERE project_id = ? AND vendor_id = ?")
            ->execute([$project_id, $global_vendor_id]);

        audit_log($pdo, 'detach', 'project_vendor', $project_id . ':' . $global_vendor_id, [
            'project_id' => $project_id,
            'vendor_id' => $global_vendor_id
        ], null);

        $pdo->commit();
        regenerate_csrf_token();
        set_flash('success', 'Vendor ' . ($gv ? sanitize($gv['vendor_name']) : '') . ' detached from project.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('danger', 'Detach failed: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
}

// GET: show confirm form
if (!$project_id || !$global_vendor_id) {
    set_flash('danger', 'Missing project or vendor.');
    redirect(BASE_URL . '/projects/list.php');
}
$gv_stmt = $pdo->prepare("SELECT * FROM global_vendors WHERE id = ?");
$gv_stmt->execute([$global_vendor_id]);
$gv = $gv_stmt->fetch();
$proj_stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$proj_stmt->execute([$project_id]);
$project = $proj_stmt->fetch();
if (!$gv || !$project) {
    set_flash('danger', 'Project or vendor not found.');
    redirect(BASE_URL . '/projects/list.php');
}

$page_title = 'Detach Vendor';
require_once __DIR__ . '/../helpers/layout_header.php';
?>
<div class="row justify-content-center">
    <div class="col-12 col-lg-6">
        <div class="tf-card">
            <div class="tf-card-header">
                <h5 class="mb-0 fw-semibold">Detach Vendor</h5>
            </div>
            <div class="card-body">
                <p class="mb-3">Are you sure you want to detach <strong><?php echo sanitize($gv['vendor_name']); ?></strong>
                    (<?php echo sanitize($gv['vendor_code']); ?>) from project
                    <strong><?php echo sanitize($project['project_name']); ?></strong>?</p>
                <p class="small text-secondary mb-4">
                    The vendor stays in the global library (you can re-attach it anywhere). Per-vendor short links are removed.
                </p>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    <input type="hidden" name="global_vendor_id" value="<?php echo $global_vendor_id; ?>">
                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $project_id; ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-danger"><i class="bi bi-link-45deg"></i> Yes, Detach Vendor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
