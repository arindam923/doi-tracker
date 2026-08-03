<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/settings/index.php');
    }

    $keys = ['site_name', 'default_currency', 'session_timeout_hours', 'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_user', 'resend_api_key', 'email_from_address', 'email_from_name'];
    foreach ($keys as $key) {
        if (isset($_POST[$key])) {
            set_setting($pdo, $key, trim($_POST[$key]));
        }
    }

    if (isset($_POST['smtp_pass']) && !empty($_POST['smtp_pass'])) {
        set_setting($pdo, 'smtp_pass', encrypt_value(trim($_POST['smtp_pass'])));
    }

    regenerate_csrf_token();
    set_flash('success', 'Settings saved.');
    redirect(BASE_URL . '/settings/index.php');
}

$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$page_title = 'System Settings';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 fw-semibold">General Settings</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="site_name" class="form-label small fw-semibold text-secondary">Site Name</label>
                            <input type="text" id="site_name" name="site_name" class="form-control"
                                   value="<?php echo sanitize($settings['site_name'] ?? 'Ternfluenzy'); ?>">
                        </div>

                        <div class="col-12 col-md-3">
                            <label for="default_currency" class="form-label small fw-semibold text-secondary">Default Currency</label>
                            <select id="default_currency" name="default_currency" class="form-select">
                                <?php foreach (['USD','EUR','GBP','INR','AED','SAR','CAD','AUD'] as $cur): ?>
                                <option value="<?php echo $cur; ?>" <?php echo ($settings['default_currency'] ?? 'USD') === $cur ? 'selected' : ''; ?>><?php echo $cur; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-3">
                            <label for="session_timeout_hours" class="form-label small fw-semibold text-secondary">Session Timeout (hrs)</label>
                            <input type="number" id="session_timeout_hours" name="session_timeout_hours" class="form-control"
                                   value="<?php echo $settings['session_timeout_hours'] ?? 8; ?>" min="1" max="72">
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="fw-semibold mb-3">Email (Resend) Configuration</h6>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="resend_api_key" class="form-label small fw-semibold text-secondary">Resend API Key</label>
                            <input type="password" id="resend_api_key" name="resend_api_key" class="form-control"
                                   value="<?php echo sanitize($settings['resend_api_key'] ?? ''); ?>"
                                   placeholder="re_...">
                            <p class="form-text">Get your API key from <a href="https://resend.com" target="_blank" rel="noopener">resend.com</a></p>
                        </div>

                        <div class="col-12 col-md-3">
                            <label for="email_from_address" class="form-label small fw-semibold text-secondary">From Address</label>
                            <input type="email" id="email_from_address" name="email_from_address" class="form-control"
                                   value="<?php echo sanitize($settings['email_from_address'] ?? 'noreply@yourdomain.com'); ?>"
                                   placeholder="noreply@yourdomain.com">
                        </div>

                        <div class="col-12 col-md-3">
                            <label for="email_from_name" class="form-label small fw-semibold text-secondary">From Name</label>
                            <input type="text" id="email_from_name" name="email_from_name" class="form-control"
                                   value="<?php echo sanitize($settings['email_from_name'] ?? 'Ternfluenzy'); ?>"
                                   placeholder="Ternfluenzy">
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="fw-semibold mb-3">SMTP Configuration</h6>

                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label for="smtp_enabled" class="form-label small fw-semibold text-secondary">SMTP Enabled</label>
                            <select id="smtp_enabled" name="smtp_enabled" class="form-select">
                                <option value="0" <?php echo ($settings['smtp_enabled'] ?? '0') === '0' ? 'selected' : ''; ?>>No</option>
                                <option value="1" <?php echo ($settings['smtp_enabled'] ?? '0') === '1' ? 'selected' : ''; ?>>Yes</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="smtp_host" class="form-label small fw-semibold text-secondary">SMTP Host</label>
                            <input type="text" id="smtp_host" name="smtp_host" class="form-control"
                                   value="<?php echo sanitize($settings['smtp_host'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-3">
                            <label for="smtp_port" class="form-label small fw-semibold text-secondary">SMTP Port</label>
                            <input type="text" id="smtp_port" name="smtp_port" class="form-control"
                                   value="<?php echo sanitize($settings['smtp_port'] ?? '587'); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="smtp_user" class="form-label small fw-semibold text-secondary">SMTP Username</label>
                            <input type="text" id="smtp_user" name="smtp_user" class="form-control"
                                   value="<?php echo sanitize($settings['smtp_user'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="smtp_pass" class="form-label small fw-semibold text-secondary">SMTP Password</label>
                            <input type="password" id="smtp_pass" name="smtp_pass" class="form-control"
                                   value="" placeholder="<?php echo !empty($settings['smtp_pass'] ?? '') ? '(encrypted — leave blank to keep current)' : ''; ?>">
                            <?php if (!empty($settings['smtp_pass'] ?? '')): ?>
                            <p class="form-text">Password is encrypted. Leave blank to keep current.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end pt-3 mt-3 border-top">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i>Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
