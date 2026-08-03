<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$id = intval($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/clients/list.php');

$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) {
    set_flash('danger', 'Client not found.');
    redirect(BASE_URL . '/clients/list.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/clients/edit.php?id=' . $id);
    }

    $client_name = trim($_POST['client_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $default_currency = trim($_POST['default_currency'] ?? 'USD');
    $notes = trim($_POST['notes'] ?? '');

    $errors = [];
    if (empty($client_name)) $errors[] = 'Client name is required.';

    if (!empty($errors)) {
        set_flash('danger', implode(' | ', $errors));
        $_SESSION['edit_form_data'] = $_POST;
        redirect(BASE_URL . '/clients/edit.php?id=' . $id);
    }

    $stmt = $pdo->prepare("UPDATE clients SET client_name=?, contact_person=?, email=?, phone=?, country=?, default_currency=?, notes=? WHERE id=?");
    $stmt->execute([$client_name, $contact_person, $email, $phone, $country, $default_currency, $notes, $id]);

    regenerate_csrf_token();
    set_flash('success', 'Client updated successfully.');
    redirect(BASE_URL . '/clients/list.php');
}

$page_title = 'Edit Client';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <div class="d-flex flex-column">
                    <h5 class="mb-0 fw-semibold">Edit: <?php echo sanitize($client['client_name']); ?></h5>
                    <code class="small text-muted" style="font-family: ui-monospace, monospace;"><?php echo sanitize($client['client_code']); ?></code>
                </div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-8">
                            <label for="client_name" class="form-label small fw-semibold text-secondary">Client Name <span class="text-danger">*</span></label>
                            <input type="text" id="client_name" name="client_name" class="form-control"
                                   value="<?php echo sanitize($client['client_name']); ?>" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-semibold text-secondary">Client Code</label>
                            <input type="text" class="form-control" value="<?php echo sanitize($client['client_code']); ?>" disabled>
                            <p class="form-text">Cannot be changed</p>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="contact_person" class="form-label small fw-semibold text-secondary">Contact Person</label>
                            <input type="text" id="contact_person" name="contact_person" class="form-control"
                                   value="<?php echo sanitize($client['contact_person'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="email" class="form-label small fw-semibold text-secondary">Email</label>
                            <input type="email" id="email" name="email" class="form-control"
                                   value="<?php echo sanitize($client['email'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="phone" class="form-label small fw-semibold text-secondary">Phone</label>
                            <input type="text" id="phone" name="phone" class="form-control"
                                   value="<?php echo sanitize($client['phone'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="country" class="form-label small fw-semibold text-secondary">Country</label>
                            <input type="text" id="country" name="country" class="form-control"
                                   value="<?php echo sanitize($client['country'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="default_currency" class="form-label small fw-semibold text-secondary">Default Currency</label>
                            <select id="default_currency" name="default_currency" class="form-select">
                                <?php foreach (['USD','EUR','GBP','INR','AED','SAR','CAD','AUD'] as $cur): ?>
                                <option value="<?php echo $cur; ?>" <?php echo ($client['default_currency'] ?? 'USD') === $cur ? 'selected' : ''; ?>><?php echo $cur; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label for="notes" class="form-label small fw-semibold text-secondary">Notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="3"><?php echo sanitize($client['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                        <a href="<?php echo BASE_URL; ?>/clients/list.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
