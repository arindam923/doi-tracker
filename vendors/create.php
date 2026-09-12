<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/email.php';
require_role(['super_admin', 'campaign_manager']);

$project_id = intval($_GET['project_id'] ?? 0);
$project = null;
if ($project_id) {
    $stmt = $pdo->prepare("SELECT p.*, c.default_currency FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    if (!$project) $project_id = 0;
}

$default_currency = $project['currency'] ?? ($project['default_currency'] ?? 'USD');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/vendors/create.php' . ($project_id ? '?project_id=' . $project_id : ''));
    }

    $vendor_name = trim($_POST['vendor_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telegram = trim($_POST['telegram'] ?? '');
    $skype = trim($_POST['skype'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $traffic_type = implode(',', tf_normalize_traffic_types($_POST['traffic_type'] ?? []));
    $vendor_status = $_POST['vendor_status'] ?? 'approved';
    $default_payout = floatval($_POST['default_payout'] ?? 0);
    $currency = $_POST['currency'] ?? $default_currency;
    $daily_cap = intval($_POST['daily_cap'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $global_postback_url = trim($_POST['global_postback_url'] ?? '');

    // Per-project fields (only when attaching to a project)
    $payout = floatval($_POST['payout'] ?? $default_payout);
    $postback_url = trim($_POST['postback_url'] ?? '');
    $allowed_clicks_limit = intval($_POST['allowed_clicks_limit'] ?? 0);

    $errors = [];
    if (empty($vendor_name)) $errors[] = 'Vendor name is required.';
    if (!tf_is_valid_postback_url($global_postback_url)) $errors[] = 'Global postback URL must be a valid HTTP or HTTPS URL.';
    if (!tf_is_valid_postback_url($postback_url)) $errors[] = 'Project override postback URL must be a valid HTTP or HTTPS URL.';
    if (!array_key_exists($vendor_status, tf_vendor_statuses())) $vendor_status = 'approved';
    if (!in_array($currency, tf_currencies(), true)) $currency = $default_currency;

    if (!empty($errors)) {
        set_flash('danger', implode(' | ', $errors));
        $_SESSION['form_data'] = $_POST;
        redirect(BASE_URL . '/vendors/create.php' . ($project_id ? '?project_id=' . $project_id : ''));
    }

    $pdo->beginTransaction();

    try {
        // Generate vendor_code: V###### (auto-increment-style)
        $vc_stmt = $pdo->query("
            SELECT MAX(CAST(SUBSTRING(combined.vendor_code, 2) AS UNSIGNED)) as max_seq FROM (
                SELECT vendor_code FROM global_vendors WHERE vendor_code REGEXP '^V[0-9]+$'
            ) combined
        ");
        $next = (int)($vc_stmt->fetch()['max_seq'] ?? 0) + 1;
        $vendor_code = 'V' . str_pad((string)$next, 6, '0', STR_PAD_LEFT);

        // Upsert into global_vendors (master library)
        $gv_check = $pdo->prepare("SELECT id FROM global_vendors WHERE vendor_code = ? OR (email <> '' AND email = ?)");
        $gv_check->execute([$vendor_code, $email]);
        $gv = $gv_check->fetch();
        if ($gv) {
            $global_vendor_id = (int)$gv['id'];
            $pdo->prepare("
                UPDATE global_vendors SET
                    vendor_name = COALESCE(NULLIF(?, ''), vendor_name),
                    contact_person = COALESCE(NULLIF(?, ''), contact_person),
                    email = COALESCE(NULLIF(?, ''), email),
                    telegram = COALESCE(NULLIF(?, ''), telegram),
                    skype = COALESCE(NULLIF(?, ''), skype),
                    phone = COALESCE(NULLIF(?, ''), phone),
                    traffic_type = ?,
                    vendor_status = ?,
                    default_payout = GREATEST(default_payout, ?),
                    currency = ?,
                    daily_cap = GREATEST(daily_cap, ?),
                    notes = CONCAT_WS(' | ', NULLIF(notes, ''), NULLIF(?, '')),
                    global_postback_url = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$vendor_name, $contact_person, $email, $telegram, $skype, $phone, $traffic_type, $vendor_status, $default_payout, $currency, $daily_cap, $notes, $global_postback_url, $global_vendor_id]);
        } else {
            $pdo->prepare("
                INSERT INTO global_vendors
                    (vendor_code, vendor_name, contact_person, email, telegram, skype, phone, global_postback_url, traffic_type, vendor_status, default_payout, currency, daily_cap, notes, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ")->execute([$vendor_code, $vendor_name, $contact_person, $email, $telegram, $skype, $phone, $global_postback_url, $traffic_type, $vendor_status, $default_payout, $currency, $daily_cap, $notes, (int)($_SESSION['user_id'] ?? 0)]);
            $global_vendor_id = (int)$pdo->lastInsertId();
        }

        if (tf_traffic_type_includes($traffic_type, 'Email') && function_exists('ensure_vendor_email_list')) {
            ensure_vendor_email_list($pdo, $global_vendor_id, (int)($_SESSION['user_id'] ?? 0));
        }

        // Attach to project if requested
        if ($project_id && $project) {
            $pv_status = ($vendor_status === 'suspended' || $vendor_status === 'blacklisted') ? 'hold' : 'active';
            $pdo->prepare("
                INSERT INTO project_vendor
                    (project_id, vendor_id, payout, currency, status, postback_url, allowed_clicks_limit, daily_cap, assigned_by, assigned_at, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE
                    payout = VALUES(payout),
                    currency = VALUES(currency),
                    status = VALUES(status),
                    postback_url = VALUES(postback_url),
                    allowed_clicks_limit = VALUES(allowed_clicks_limit),
                    daily_cap = VALUES(daily_cap),
                    notes = VALUES(notes)
            ")->execute([$project_id, $global_vendor_id, $payout, $currency, $pv_status, $postback_url, $allowed_clicks_limit, $daily_cap, (int)($_SESSION['user_id'] ?? 0), $notes]);

            ensure_tracking_short_link($pdo, $project_id, $global_vendor_id);
        }

        audit_log($pdo, 'create', 'vendor', $global_vendor_id, null, [
            'vendor_name' => $vendor_name, 'vendor_code' => $vendor_code,
            'project_id' => $project_id ?: null
        ]);
        $pdo->prepare("INSERT INTO logs (log_type, project_id, vendor_id, status, message) VALUES (?, ?, ?, ?, ?)")
            ->execute(['vendor_change', $project_id ?: null, $global_vendor_id, 'success', 'Vendor added: ' . $vendor_name]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('danger', 'Failed to add vendor: ' . $e->getMessage());
        redirect(BASE_URL . '/vendors/create.php' . ($project_id ? '?project_id=' . $project_id : ''));
    }

    regenerate_csrf_token();
    if ($project_id) {
        set_flash('success', 'Vendor "' . $vendor_name . '" added and attached to project.');
        redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
    }
    set_flash('success', 'Vendor "' . $vendor_name . '" created.');
    redirect(BASE_URL . '/vendors/global.php');
}

$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);

$page_title = 'Add Vendor';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-10">
        <div class="tf-card">
            <div class="tf-card-header">
                <h5 class="mb-0 fw-semibold">
                    Add Vendor
                    <?php if ($project): ?>to: <?php echo sanitize($project['project_name']); ?><?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-8">
                            <label for="vendor_name" class="tf-label">Vendor Name <span class="text-danger">*</span></label>
                            <input type="text" id="vendor_name" name="vendor_name" class="form-control"
                                   value="<?php echo sanitize($form_data['vendor_name'] ?? ''); ?>" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="tf-label">Vendor Code</label>
                            <input type="text" class="form-control" value="(auto-generated)" disabled>
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="traffic_type" class="tf-label">Traffic Type</label>
                            <select id="traffic_type" name="traffic_type[]" class="form-select" multiple size="5">
                                <?php $tt = tf_normalize_traffic_types($form_data['traffic_type'] ?? []); foreach (tf_traffic_types() as $t): ?>
                                <option value="<?php echo $t; ?>" <?php echo in_array($t, $tt, true) ? 'selected' : ''; ?>><?php echo sanitize($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-text mb-0 small">Hold Ctrl/Cmd to select multiple traffic types.</p>
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="vendor_status" class="tf-label">Vendor Status</label>
                            <select id="vendor_status" name="vendor_status" class="form-select">
                                <?php $vs = $form_data['vendor_status'] ?? 'approved'; foreach (tf_vendor_statuses() as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo $vs === $key ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="contact_person" class="tf-label">Contact Person</label>
                            <input type="text" id="contact_person" name="contact_person" class="form-control"
                                   value="<?php echo sanitize($form_data['contact_person'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="email" class="tf-label">Email</label>
                            <input type="email" id="email" name="email" class="form-control"
                                   value="<?php echo sanitize($form_data['email'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="phone" class="tf-label">Phone</label>
                            <input type="text" id="phone" name="phone" class="form-control"
                                   value="<?php echo sanitize($form_data['phone'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="telegram" class="tf-label">Telegram</label>
                            <input type="text" id="telegram" name="telegram" class="form-control"
                                   value="<?php echo sanitize($form_data['telegram'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="skype" class="tf-label">Skype</label>
                            <input type="text" id="skype" name="skype" class="form-control"
                                   value="<?php echo sanitize($form_data['skype'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-4">
                            <label for="currency" class="tf-label">Currency</label>
                            <select id="currency" name="currency" class="form-select">
                                <?php $cur = $form_data['currency'] ?? $default_currency; foreach (tf_currencies() as $c): ?>
                                <option value="<?php echo $c; ?>" <?php echo $cur === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-6">
                            <label for="default_payout" class="tf-label">Default Payout (per conversion)</label>
                            <input type="number" id="default_payout" name="default_payout" class="form-control"
                                   value="<?php echo sanitize($form_data['default_payout'] ?? '0.00'); ?>" step="0.01" min="0">
                            <p class="form-text mb-0 small">Used as the default when this vendor is attached to a project.</p>
                        </div>

                        <div class="col-12">
                            <label for="global_postback_url" class="tf-label">Global Postback URL</label>
                            <input type="url" id="global_postback_url" name="global_postback_url" class="form-control"
                                   value="<?php echo sanitize($form_data['global_postback_url'] ?? ''); ?>"
                                   placeholder="https://vendor.com/postback?click_id={click_id}&status={status}&payout={payout}&tx={transaction_id}&s1={sub1}">
                            <p class="form-text mb-0 small">Reusable default for future project assignments. Macros: <code>{click_id}</code>, <code>{status}</code>, <code>{sale_amount}</code>, <code>{currency}</code>, <code>{payout}</code>, <code>{transaction_id}</code>, <code>{sub1}</code>…<code>{sub5}</code>.</p>
                        </div>

                        <div class="col-6 col-md-6">
                            <label for="daily_cap" class="tf-label">Daily Cap</label>
                            <input type="number" id="daily_cap" name="daily_cap" class="form-control"
                                   value="<?php echo sanitize($form_data['daily_cap'] ?? '0'); ?>" min="0">
                            <p class="form-text mb-0 small">0 = unlimited</p>
                        </div>

                        <?php if ($project): ?>
                        <div class="col-12">
                            <hr>
                            <p class="small fw-semibold text-uppercase text-muted mb-2">Project Settings (<?php echo sanitize($project['project_name']); ?>)</p>
                        </div>
                        <div class="col-6 col-md-4">
                            <label for="payout" class="tf-label">Project Payout</label>
                            <input type="number" id="payout" name="payout" class="form-control"
                                   value="<?php echo sanitize($form_data['payout'] ?? ($project['vendor_default_cpi'] ?? '0.00')); ?>" step="0.01" min="0">
                        </div>
                        <div class="col-6 col-md-4">
                            <label for="allowed_clicks_limit" class="tf-label">Total Click Limit</label>
                            <input type="number" id="allowed_clicks_limit" name="allowed_clicks_limit" class="form-control"
                                   value="<?php echo sanitize($form_data['allowed_clicks_limit'] ?? '0'); ?>" min="0">
                            <p class="form-text mb-0 small">0 = unlimited</p>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="postback_url" class="tf-label">Project Override Postback URL</label>
                            <input type="url" id="postback_url" name="postback_url" class="form-control"
                                   value="<?php echo sanitize($form_data['postback_url'] ?? ''); ?>"
                                   placeholder="https://vendor.com/postback?click_id={click_id}&status={status}&payout={payout}&tx={transaction_id}&s1={sub1}">
                            <p class="form-text mb-0 small">Optional project-specific override. Blank uses the Global Postback URL and follows future global changes. Supports <code>{click_id}</code>, <code>{status}</code>, <code>{sale_amount}</code>, <code>{currency}</code>, <code>{payout}</code>, <code>{transaction_id}</code>, <code>{sub1}</code>…<code>{sub5}</code>.</p>
                        </div>
                        <?php endif; ?>

                        <div class="col-12">
                            <label for="notes" class="tf-label">Notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="2"><?php echo sanitize($form_data['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                        <a href="<?php echo $project ? (BASE_URL . '/projects/detail.php?id=' . $project_id) : (BASE_URL . '/vendors/global.php'); ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i>Add Vendor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($project): ?>
<script>
(function () {
    var globalPostback = document.getElementById('global_postback_url');
    var projectOverride = document.getElementById('postback_url');
    if (!globalPostback || !projectOverride) return;

    var userEdited = projectOverride.value !== '';
    projectOverride.addEventListener('input', function () {
        userEdited = true;
    });
    globalPostback.addEventListener('input', function () {
        if (!userEdited) projectOverride.placeholder = globalPostback.value || 'Blank uses global postback';
    });
    if (!userEdited) projectOverride.placeholder = globalPostback.value || 'Blank uses global postback';
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
