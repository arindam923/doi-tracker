<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$project_id = intval($_GET['project_id'] ?? 0);
if (!$project_id) redirect(BASE_URL . '/projects/list.php');

$stmt = $pdo->prepare("SELECT p.*, c.default_currency FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) {
    set_flash('danger', 'Project not found.');
    redirect(BASE_URL . '/projects/list.php');
}
$currency = $project['default_currency'] ?? 'USD';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/vendors/create.php?project_id=' . $project_id);
    }

    $vendor_name = trim($_POST['vendor_name'] ?? '');
    $contact_info = trim($_POST['contact_info'] ?? '');
    $vendor_cpi = floatval($_POST['vendor_cpi'] ?? $project['vendor_default_cpi']);
    $postback_url = trim($_POST['postback_url'] ?? '');
    $allowed_clicks_limit = intval($_POST['allowed_clicks_limit'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    $errors = [];
    if (empty($vendor_name)) $errors[] = 'Vendor name is required.';

    if (!empty($errors)) {
        set_flash('danger', implode(' | ', $errors));
        $_SESSION['form_data'] = $_POST;
        redirect(BASE_URL . '/vendors/create.php?project_id=' . $project_id);
    }

    $stmt = $pdo->prepare("INSERT INTO vendors (project_id, vendor_name, contact_info, vendor_cpi, postback_url, allowed_clicks_limit, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$project_id, $vendor_name, $contact_info, $vendor_cpi, $postback_url, $allowed_clicks_limit, $notes]);

    $new_id = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO logs (log_type, project_id, vendor_id, status, message) VALUES (?, ?, ?, ?, ?)")
        ->execute(['vendor_change', $project_id, $new_id, 'success', 'Vendor added: ' . $vendor_name]);

    regenerate_csrf_token();
    set_flash('success', 'Vendor "' . $vendor_name . '" added.');
    redirect(BASE_URL . '/vendors/list.php?project_id=' . $project_id);
}

$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);

$page_title = 'Add Vendor';
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
                <h5 class="mb-0 fw-semibold">Add Vendor to: <?php echo sanitize($project['project_name']); ?></h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-8">
                            <label for="vendor_name" class="form-label small fw-semibold text-secondary">Vendor Name <span class="text-danger">*</span></label>
                            <input type="text" id="vendor_name" name="vendor_name" class="form-control"
                                   value="<?php echo sanitize($form_data['vendor_name'] ?? ''); ?>" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="vendor_cpi" class="form-label small fw-semibold text-secondary">Vendor Payout per Conversion</label>
                            <div class="input-group">
                                <span class="input-group-text"><?php echo currency_symbol($currency); ?></span>
                                <input type="number" id="vendor_cpi" name="vendor_cpi" class="form-control"
                                       value="<?php echo sanitize($form_data['vendor_cpi'] ?? $project['vendor_default_cpi']); ?>" step="0.01" min="0">
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="contact_info" class="form-label small fw-semibold text-secondary">Contact Info</label>
                            <input type="text" id="contact_info" name="contact_info" class="form-control"
                                   value="<?php echo sanitize($form_data['contact_info'] ?? ''); ?>"
                                   placeholder="Email, WhatsApp, Skype...">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="allowed_clicks_limit" class="form-label small fw-semibold text-secondary">Click Limit</label>
                            <input type="number" id="allowed_clicks_limit" name="allowed_clicks_limit" class="form-control"
                                   value="<?php echo sanitize($form_data['allowed_clicks_limit'] ?? '0'); ?>" min="0">
                            <p class="form-text">0 = unlimited</p>
                        </div>

                        <div class="col-12">
                            <label for="postback_url" class="form-label small fw-semibold text-secondary">Vendor Postback URL</label>
                            <input type="url" id="postback_url" name="postback_url" class="form-control"
                                   value="<?php echo sanitize($form_data['postback_url'] ?? ''); ?>"
                                   placeholder="https://vendor.com/postback?click_id={click_id}">
                            <p class="form-text">Use <code style="font-family: ui-monospace, monospace; font-size: .85em;">{click_id}</code> as placeholder for the click ID.</p>
                        </div>

                        <div class="col-12">
                            <label for="notes" class="form-label small fw-semibold text-secondary">Notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="2"><?php echo sanitize($form_data['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                        <a href="<?php echo BASE_URL; ?>/vendors/list.php?project_id=<?php echo $project_id; ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i>Add Vendor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
