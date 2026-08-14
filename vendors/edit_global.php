<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$id = intval($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/vendors/global.php');

$stmt = $pdo->prepare("SELECT * FROM global_vendors WHERE id = ?");
$stmt->execute([$id]);
$vendor = $stmt->fetch();
if (!$vendor) {
    set_flash('danger', 'Vendor not found.');
    redirect(BASE_URL . '/vendors/global.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/vendors/edit_global.php?id=' . $id);
    }

    $vendor_name = trim($_POST['vendor_name'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telegram = trim($_POST['telegram'] ?? '');
    $skype = trim($_POST['skype'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $traffic_type = $_POST['traffic_type'] ?? 'Other';
    $vendor_status = $_POST['vendor_status'] ?? 'approved';
    $default_payout = floatval($_POST['default_payout'] ?? 0);
    $currency = $_POST['currency'] ?? 'USD';
    $daily_cap = intval($_POST['daily_cap'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if (empty($vendor_name)) {
        set_flash('danger', 'Vendor name is required.');
        redirect(BASE_URL . '/vendors/edit_global.php?id=' . $id);
    }
    if (!in_array($traffic_type, tf_traffic_types(), true)) $traffic_type = 'Other';
    if (!array_key_exists($vendor_status, tf_vendor_statuses())) $vendor_status = 'approved';
    if (!in_array($currency, tf_currencies(), true)) $currency = 'USD';

    $pdo->prepare("UPDATE global_vendors SET vendor_name=?, company_name=?, contact_person=?, email=?, telegram=?, skype=?, phone=?, traffic_type=?, vendor_status=?, default_payout=?, currency=?, daily_cap=?, notes=?, updated_at=NOW() WHERE id=?")
        ->execute([$vendor_name, $company_name, $contact_person, $email, $telegram, $skype, $phone, $traffic_type, $vendor_status, $default_payout, $currency, $daily_cap, $notes, $id]);

    audit_log($pdo, 'update', 'vendor', $id, null, ['vendor_name' => $vendor_name]);

    regenerate_csrf_token();
    set_flash('success', 'Vendor updated.');
    redirect(BASE_URL . '/vendors/global.php');
}

$page_title = 'Edit Vendor';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="mb-3">
    <a href="<?php echo BASE_URL; ?>/vendors/global.php" class="text-decoration-none text-secondary d-inline-flex align-items-center gap-1 small fw-semibold">
        <i class="bi bi-arrow-left"></i>Back to Vendors
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <div class="tf-card">
            <div class="tf-card-header">
                <div class="d-flex flex-column">
                    <h5 class="mb-0 fw-semibold">Edit: <?php echo sanitize($vendor['vendor_name']); ?></h5>
                    <small class="text-muted"><code><?php echo sanitize($vendor['vendor_code']); ?></code></small>
                </div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-8">
                            <label for="vendor_name" class="tf-label">Vendor Name <span class="text-danger">*</span></label>
                            <input type="text" id="vendor_name" name="vendor_name" class="form-control"
                                   value="<?php echo sanitize($vendor['vendor_name']); ?>" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="vendor_status" class="tf-label">Master Status</label>
                            <select id="vendor_status" name="vendor_status" class="form-select">
                                <?php foreach (tf_vendor_statuses() as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo ($vendor['vendor_status'] ?? 'approved') === $key ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-8">
                            <label for="company_name" class="tf-label">Company Name</label>
                            <input type="text" id="company_name" name="company_name" class="form-control"
                                   value="<?php echo sanitize($vendor['company_name'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="traffic_type" class="tf-label">Traffic Type</label>
                            <select id="traffic_type" name="traffic_type" class="form-select">
                                <?php foreach (tf_traffic_types() as $t): ?>
                                <option value="<?php echo $t; ?>" <?php echo ($vendor['traffic_type'] ?? 'Other') === $t ? 'selected' : ''; ?>><?php echo sanitize($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="contact_person" class="tf-label">Contact Person</label>
                            <input type="text" id="contact_person" name="contact_person" class="form-control"
                                   value="<?php echo sanitize($vendor['contact_person'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="email" class="tf-label">Email</label>
                            <input type="email" id="email" name="email" class="form-control"
                                   value="<?php echo sanitize($vendor['email'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="phone" class="tf-label">Phone</label>
                            <input type="text" id="phone" name="phone" class="form-control"
                                   value="<?php echo sanitize($vendor['phone'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="telegram" class="tf-label">Telegram</label>
                            <input type="text" id="telegram" name="telegram" class="form-control"
                                   value="<?php echo sanitize($vendor['telegram'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="skype" class="tf-label">Skype</label>
                            <input type="text" id="skype" name="skype" class="form-control"
                                   value="<?php echo sanitize($vendor['skype'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="currency" class="tf-label">Currency</label>
                            <select id="currency" name="currency" class="form-select">
                                <?php foreach (tf_currencies() as $c): ?>
                                <option value="<?php echo $c; ?>" <?php echo ($vendor['currency'] ?? 'USD') === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-6">
                            <label for="default_payout" class="tf-label">Default Payout (per conversion)</label>
                            <input type="number" id="default_payout" name="default_payout" class="form-control"
                                   value="<?php echo $vendor['default_payout']; ?>" step="0.01" min="0">
                        </div>

                        <div class="col-6 col-md-6">
                            <label for="daily_cap" class="tf-label">Daily Cap</label>
                            <input type="number" id="daily_cap" name="daily_cap" class="form-control"
                                   value="<?php echo (int)$vendor['daily_cap']; ?>" min="0">
                            <p class="form-text mb-0 small">0 = unlimited</p>
                        </div>

                        <div class="col-12">
                            <label for="notes" class="tf-label">Notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="3"><?php echo sanitize($vendor['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                        <a href="<?php echo BASE_URL; ?>/vendors/global.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
