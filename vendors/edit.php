<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$id = intval($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/projects/list.php');

$stmt = $pdo->prepare("SELECT v.*, p.project_name, c.default_currency FROM vendors v JOIN projects p ON v.project_id = p.id LEFT JOIN clients c ON p.client_id = c.id WHERE v.id = ?");
$stmt->execute([$id]);
$vendor = $stmt->fetch();
if (!$vendor) {
    set_flash('danger', 'Vendor not found.');
    redirect(BASE_URL . '/projects/list.php');
}

$project_id = $vendor['project_id'];
$currency = $vendor['default_currency'] ?? 'USD';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/vendors/edit.php?id=' . $id);
    }

    $vendor_name = trim($_POST['vendor_name'] ?? '');
    $contact_info = trim($_POST['contact_info'] ?? '');
    $vendor_cpi = floatval($_POST['vendor_cpi'] ?? 0);
    $postback_url = trim($_POST['postback_url'] ?? '');
    $allowed_clicks_limit = intval($_POST['allowed_clicks_limit'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if (empty($vendor_name)) {
        set_flash('danger', 'Vendor name is required.');
        $_SESSION['edit_form_data'] = $_POST;
        redirect(BASE_URL . '/vendors/edit.php?id=' . $id);
    }

    $stmt = $pdo->prepare("UPDATE vendors SET vendor_name=?, contact_info=?, vendor_cpi=?, postback_url=?, allowed_clicks_limit=?, notes=? WHERE id=?");
    $stmt->execute([$vendor_name, $contact_info, $vendor_cpi, $postback_url, $allowed_clicks_limit, $notes, $id]);

    regenerate_csrf_token();
    set_flash('success', 'Vendor updated.');
    redirect(BASE_URL . '/vendors/list.php?project_id=' . $project_id);
}

$page_title = 'Edit Vendor';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="mb-3">
    <a href="<?php echo BASE_URL; ?>/vendors/list.php?project_id=<?php echo $project_id; ?>" class="text-decoration-none text-secondary d-inline-flex align-items-center gap-1 small fw-semibold">
        <i class="bi bi-arrow-left"></i>Back to Vendors
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <div class="d-flex flex-column">
                    <h5 class="mb-0 fw-semibold">Edit: <?php echo sanitize($vendor['vendor_name']); ?></h5>
                    <small class="text-muted"><?php echo sanitize($vendor['project_name']); ?></small>
                </div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-8">
                            <label for="vendor_name" class="form-label small fw-semibold text-secondary">Vendor Name <span class="text-danger">*</span></label>
                            <input type="text" id="vendor_name" name="vendor_name" class="form-control"
                                   value="<?php echo sanitize($vendor['vendor_name']); ?>" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="vendor_cpi" class="form-label small fw-semibold text-secondary">Vendor Payout per Conversion</label>
                            <div class="input-group">
                                <span class="input-group-text"><?php echo currency_symbol($currency); ?></span>
                                <input type="number" id="vendor_cpi" name="vendor_cpi" class="form-control"
                                       value="<?php echo $vendor['vendor_cpi']; ?>" step="0.01" min="0">
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="contact_info" class="form-label small fw-semibold text-secondary">Contact Info</label>
                            <input type="text" id="contact_info" name="contact_info" class="form-control"
                                   value="<?php echo sanitize($vendor['contact_info'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="allowed_clicks_limit" class="form-label small fw-semibold text-secondary">Click Limit</label>
                            <input type="number" id="allowed_clicks_limit" name="allowed_clicks_limit" class="form-control"
                                   value="<?php echo $vendor['allowed_clicks_limit']; ?>" min="0">
                        </div>

                        <div class="col-12">
                            <label for="postback_url" class="form-label small fw-semibold text-secondary">Vendor Postback URL</label>
                            <input type="url" id="postback_url" name="postback_url" class="form-control"
                                   value="<?php echo sanitize($vendor['postback_url'] ?? ''); ?>">
                        </div>

                        <div class="col-12">
                            <label for="notes" class="form-label small fw-semibold text-secondary">Notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="2"><?php echo sanitize($vendor['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                        <a href="<?php echo BASE_URL; ?>/vendors/list.php?project_id=<?php echo $project_id; ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
