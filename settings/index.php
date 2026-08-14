<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/settings/index.php');
    }

    $keys = ['site_name', 'default_currency', 'session_timeout_hours', 'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_user', 'resend_api_key', 'email_from_address', 'email_from_name', 'ip_enrichment_enabled', 'global_postback_enabled', 'vendor_login_enabled', 'global_postback_url', 'strict_target_device', 'email_rate_per_minute'];
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
        <div class="tf-card">
            <div class="tf-card-header">
                <h5 class="mb-0 fw-semibold">General Settings</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="site_name" class="tf-label">Site Name</label>
                            <input type="text" id="site_name" name="site_name" class="form-control"
                                   value="<?php echo sanitize($settings['site_name'] ?? 'Track Flow'); ?>">
                        </div>

                        <div class="col-12 col-md-3">
                            <label for="default_currency" class="tf-label">Default Currency</label>
                            <select id="default_currency" name="default_currency" class="form-select">
                                <?php foreach (['USD','EUR','GBP','INR','AED','SAR','CAD','AUD'] as $cur): ?>
                                <option value="<?php echo $cur; ?>" <?php echo ($settings['default_currency'] ?? 'USD') === $cur ? 'selected' : ''; ?>><?php echo $cur; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-3">
                            <label for="session_timeout_hours" class="tf-label">Session Timeout (hrs)</label>
                            <input type="number" id="session_timeout_hours" name="session_timeout_hours" class="form-control"
                                   value="<?php echo $settings['session_timeout_hours'] ?? 8; ?>" min="1" max="72">
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="fw-semibold mb-3">Email (Resend) Configuration</h6>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="resend_api_key" class="tf-label">Resend API Key</label>
                            <input type="password" id="resend_api_key" name="resend_api_key" class="form-control"
                                   value="<?php echo sanitize($settings['resend_api_key'] ?? ''); ?>"
                                   placeholder="re_...">
                            <p class="form-text">Get your API key from <a href="https://resend.com" target="_blank" rel="noopener">resend.com</a></p>
                        </div>

                        <div class="col-12 col-md-3">
                            <label for="email_from_address" class="tf-label">From Address</label>
                            <input type="email" id="email_from_address" name="email_from_address" class="form-control"
                                   value="<?php echo sanitize($settings['email_from_address'] ?? 'noreply@yourdomain.com'); ?>"
                                   placeholder="noreply@yourdomain.com">
                        </div>

                        <div class="col-12 col-md-3">
                            <label for="email_from_name" class="tf-label">From Name</label>
                            <input type="text" id="email_from_name" name="email_from_name" class="form-control"
                                   value="<?php echo sanitize($settings['email_from_name'] ?? 'Track Flow'); ?>"
                                   placeholder="Track Flow">
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="fw-semibold mb-3">Tracking & Integrations</h6>

                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label for="ip_enrichment_enabled" class="tf-label">IP Geo / ISP Enrichment</label>
                            <select id="ip_enrichment_enabled" name="ip_enrichment_enabled" class="form-select">
                                <option value="0" <?php echo ($settings['ip_enrichment_enabled'] ?? '1') === '0' ? 'selected' : ''; ?>>Disabled</option>
                                <option value="1" <?php echo ($settings['ip_enrichment_enabled'] ?? '1') === '1' ? 'selected' : ''; ?>>Enabled (ipapi.co, cached 24h)</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="strict_target_device" class="tf-label">Target Device Policy</label>
                            <select id="strict_target_device" name="strict_target_device" class="form-select">
                                <option value="0" <?php echo ($settings['strict_target_device'] ?? '0') === '0' ? 'selected' : ''; ?>>Log-only mismatch (allow all)</option>
                                <option value="1" <?php echo ($settings['strict_target_device'] ?? '0') === '1' ? 'selected' : ''; ?>>Block mismatches (strict)</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="email_rate_per_minute" class="tf-label">Email Send Rate (per minute)</label>
                            <input type="number" id="email_rate_per_minute" name="email_rate_per_minute" class="form-control"
                                   value="<?php echo (int)($settings['email_rate_per_minute'] ?? 50); ?>" min="1" max="1000">
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-12 col-md-6">
                            <label for="global_postback_enabled" class="tf-label">Global Postback</label>
                            <select id="global_postback_enabled" name="global_postback_enabled" class="form-select">
                                <option value="0" <?php echo ($settings['global_postback_enabled'] ?? '0') === '0' ? 'selected' : ''; ?>>Disabled</option>
                                <option value="1" <?php echo ($settings['global_postback_enabled'] ?? '0') === '1' ? 'selected' : ''; ?>>Enabled (fire for every conversion)</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="vendor_login_enabled" class="tf-label">Vendor Portal</label>
                            <select id="vendor_login_enabled" name="vendor_login_enabled" class="form-select">
                                <option value="0" <?php echo ($settings['vendor_login_enabled'] ?? '0') === '0' ? 'selected' : ''; ?>>Closed</option>
                                <option value="1" <?php echo ($settings['vendor_login_enabled'] ?? '0') === '1' ? 'selected' : ''; ?>>Open (vendor self-service)</option>
                            </select>
                            <p class="form-text mb-0">Vendor login URL: <a href="<?php echo BASE_URL; ?>/vendor_portal/auth.php" target="_blank" rel="noopener"><?php echo BASE_URL; ?>/vendor_portal/auth.php</a></p>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-12">
                            <label for="global_postback_url" class="tf-label">Global Postback URL</label>
                            <input type="url" id="global_postback_url" name="global_postback_url" class="form-control font-monospace small"
                                   value="<?php echo sanitize($settings['global_postback_url'] ?? ''); ?>"
                                   placeholder="https://example.com/pb?click={click_id}&status={status}&payout={payout}&conversion={conversion_id}&sale={sale_amount}&currency={currency}">
                            <p class="form-text mb-0">
                                Macros: <code>{click_id}</code>, <code>{status}</code>, <code>{payout}</code>, <code>{conversion_id}</code>, <code>{sale_amount}</code>, <code>{currency}</code>
                            </p>
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="fw-semibold mb-3">SMTP Configuration</h6>

                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label for="smtp_enabled" class="tf-label">SMTP Enabled</label>
                            <select id="smtp_enabled" name="smtp_enabled" class="form-select">
                                <option value="0" <?php echo ($settings['smtp_enabled'] ?? '0') === '0' ? 'selected' : ''; ?>>No</option>
                                <option value="1" <?php echo ($settings['smtp_enabled'] ?? '0') === '1' ? 'selected' : ''; ?>>Yes</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="smtp_host" class="tf-label">SMTP Host</label>
                            <input type="text" id="smtp_host" name="smtp_host" class="form-control"
                                   value="<?php echo sanitize($settings['smtp_host'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-3">
                            <label for="smtp_port" class="tf-label">SMTP Port</label>
                            <input type="text" id="smtp_port" name="smtp_port" class="form-control"
                                   value="<?php echo sanitize($settings['smtp_port'] ?? '587'); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="smtp_user" class="tf-label">SMTP Username</label>
                            <input type="text" id="smtp_user" name="smtp_user" class="form-control"
                                   value="<?php echo sanitize($settings['smtp_user'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="smtp_pass" class="tf-label">SMTP Password</label>
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
