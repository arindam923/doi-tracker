<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/clients/create.php');
    }

    $client_name = trim($_POST['client_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $skype = trim($_POST['skype'] ?? '');
    $telegram = trim($_POST['telegram'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $default_currency = trim($_POST['default_currency'] ?? 'USD');
    $payment_terms = trim($_POST['payment_terms'] ?? '');
    $billing_address = trim($_POST['billing_address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $errors = [];
    if (empty($client_name)) $errors[] = 'Client name is required.';

    // Auto-generate client code: first 4 letters of name + 4 random digits
    if (empty($errors)) {
        $base = strtoupper(preg_replace('/[^a-zA-Z]/', '', $client_name));
        $base = substr($base, 0, 4);
        if (strlen($base) < 2) $base = str_pad($base, 2, 'X');

        $max_attempts = 50;
        for ($i = 0; $i < $max_attempts; $i++) {
            $client_code = $base . str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $check = $pdo->prepare("SELECT id FROM clients WHERE client_code = ?");
            $check->execute([$client_code]);
            if (!$check->fetch()) break;
        }
    }

    if (!empty($errors)) {
        set_flash('danger', implode(' | ', $errors));
        $_SESSION['form_data'] = $_POST;
        redirect(BASE_URL . '/clients/create.php');
    }

    $postback_token = generate_postback_token();
    $stmt = $pdo->prepare("INSERT INTO clients (client_name, client_code, postback_token, contact_person, email, phone, skype, telegram, country, default_currency, payment_terms, billing_address, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$client_name, $client_code, $postback_token, $contact_person, $email, $phone, $skype, $telegram, $country, $default_currency, $payment_terms, $billing_address, $notes]);

    audit_log($pdo, 'create', 'client', $pdo->lastInsertId(), null, ['client_name' => $client_name]);

    regenerate_csrf_token();
    set_flash('success', 'Client "' . $client_name . '" created successfully.');
    redirect(BASE_URL . '/clients/list.php');
}

$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);

$page_title = 'Add New Client';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="tf-card">
            <div class="tf-card-header">
                <h5 class="mb-0 fw-semibold">Client Details</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="clientForm">
                    <?php echo csrf_field(); ?>

                    <div class="row g-3">
                        <div class="col-12">
                            <label for="client_name" class="tf-label">Client Name <span class="text-danger">*</span></label>
                            <input type="text" id="client_name" name="client_name" class="form-control"
                                   value="<?php echo sanitize($form_data['client_name'] ?? ''); ?>" required>
                        </div>



                        <div class="col-12 col-md-4">
                            <label for="contact_person" class="tf-label">Contact Person</label>
                            <input type="text" id="contact_person" name="contact_person" class="form-control"
                                   value="<?php echo sanitize($form_data['contact_person'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="email" class="tf-label">Email</label>
                            <input type="email" id="email" name="email" class="form-control"
                                   value="<?php echo sanitize($form_data['email'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="phone" class="tf-label">Phone</label>
                            <input type="text" id="phone" name="phone" class="form-control"
                                   value="<?php echo sanitize($form_data['phone'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="skype" class="tf-label">Skype</label>
                            <input type="text" id="skype" name="skype" class="form-control"
                                   value="<?php echo sanitize($form_data['skype'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="telegram" class="tf-label">Telegram</label>
                            <input type="text" id="telegram" name="telegram" class="form-control"
                                   value="<?php echo sanitize($form_data['telegram'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="country" class="tf-label">Country</label>
                            <input type="text" id="country" name="country" class="form-control"
                                   value="<?php echo sanitize($form_data['country'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="default_currency" class="tf-label">Default Currency</label>
                            <select id="default_currency" name="default_currency" class="form-select">
                                <?php foreach (tf_currencies() as $cur): ?>
                                <option value="<?php echo $cur; ?>" <?php echo ($form_data['default_currency'] ?? 'USD') === $cur ? 'selected' : ''; ?>><?php echo $cur; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="payment_terms" class="tf-label">Payment Terms</label>
                            <select id="payment_terms" name="payment_terms" class="form-select">
                                <option value="">Select…</option>
                                <?php foreach (['Net 15', 'Net 30', 'Net 45', 'Net 60', 'Prepaid', 'COD', 'Custom'] as $term): ?>
                                <option value="<?php echo $term; ?>" <?php echo ($form_data['payment_terms'] ?? '') === $term ? 'selected' : ''; ?>><?php echo $term; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label for="billing_address" class="tf-label">Billing Address</label>
                            <textarea id="billing_address" name="billing_address" class="form-control" rows="2"
                                      placeholder="Street, city, state, postal code, country"><?php echo sanitize($form_data['billing_address'] ?? ''); ?></textarea>
                        </div>

                        <div class="col-12">
                            <label for="notes" class="tf-label">Notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="3"><?php echo sanitize($form_data['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                        <a href="<?php echo BASE_URL; ?>/clients/list.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i>Create Client</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
