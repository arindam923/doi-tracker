<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$clients_list = $pdo->query("SELECT id, client_name, client_code FROM clients WHERE is_active = 1 ORDER BY client_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/projects/create.php');
    }

    $project_name = trim($_POST['project_name'] ?? '');
    $client_id = intval($_POST['client_id'] ?? 0);
    $client_survey_link = trim($_POST['client_survey_link'] ?? '');
    $client_cpi = floatval($_POST['client_cpi'] ?? 0);
    $vendor_default_cpi = floatval($_POST['vendor_default_cpi'] ?? 0);
    $total_quota = intval($_POST['total_quota'] ?? 0);
    $daily_cap = intval($_POST['daily_cap'] ?? 0);
    $geo_codes = tf_normalize_geo_codes($_POST['geo_codes'] ?? []);
    $country_target = $geo_codes[0] ?? '';
    $campaign_type = $_POST['campaign_type'] ?? 'CPL';
    $vertical = $_POST['vertical'] ?? 'Other';
    $conversion_type = $_POST['conversion_type'] ?? 'SOI';
    $target_device = $_POST['target_device'] ?? 'All';
    $campaign_status = $_POST['campaign_status'] ?? 'live';
    $visibility = $_POST['visibility'] ?? 'private';
    $currency = $_POST['currency'] ?? 'USD';
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $errors = [];
    if (empty($project_name)) $errors[] = 'Project name is required.';
    if (!$client_id) $errors[] = 'Please select a client.';
    if (empty($client_survey_link)) $errors[] = 'Client link is required.';
    if (!in_array($campaign_type, ['CPL', 'CPC', 'CPA', 'CPS', 'CPI', 'CPM', 'RevShare', 'Hybrid'], true)) $campaign_type = 'CPL';
    if (!in_array($vertical, tf_verticals(), true)) $vertical = 'Other';
    if (!in_array($conversion_type, tf_conversion_types(), true)) $conversion_type = 'SOI';
    if (!in_array($target_device, tf_target_devices(), true)) $target_device = 'All';
    if (!array_key_exists($campaign_status, tf_campaign_status())) $campaign_status = 'live';
    if (!array_key_exists($visibility, tf_visibility())) $visibility = 'private';
    if (!in_array($currency, tf_currencies(), true)) $currency = 'USD';

    if (!empty($errors)) {
        set_flash('danger', implode(' | ', $errors));
        $_SESSION['form_data'] = $_POST;
        redirect(BASE_URL . '/projects/create.php');
    }

    $client_stmt = $pdo->prepare("SELECT client_code, country FROM clients WHERE id = ?");
    $client_stmt->execute([$client_id]);
    $client_data = $client_stmt->fetch();
    $country = $country_target ?: ($client_data['country'] ?? 'XX');

    $project_code = generate_project_code($pdo, $country);
    $postback_token = generate_postback_token();

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO projects (project_code, project_name, client_id, client_survey_link, postback_token,
                client_cpi, vendor_default_cpi, currency, total_quota, daily_cap, country_target, campaign_type,
                vertical, conversion_type, target_device, campaign_status, visibility, start_date, end_date, description, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $project_code, $project_name, $client_id, $client_survey_link, $postback_token,
            $client_cpi, $vendor_default_cpi, $currency, $total_quota, $daily_cap, $country_target, $campaign_type,
            $vertical, $conversion_type, $target_device, $campaign_status, $visibility,
            $start_date ?: null, $end_date ?: null, $description, $_SESSION['user_id']
        ]);

        $new_id = $pdo->lastInsertId();
        if (!$new_id) throw new Exception('Insert failed');

        ensure_project_short_code($pdo, $new_id);

        if (!empty($geo_codes)) {
            $geo_insert = $pdo->prepare("INSERT IGNORE INTO campaign_geo (project_id, country_code, country_name) VALUES (?, ?, ?)");
            $names = tf_countries();
            foreach ($geo_codes as $code) {
                $geo_insert->execute([$new_id, $code, $names[$code] ?? $code]);
            }
        }

        $pdo->prepare("INSERT INTO logs (log_type, project_id, status, message) VALUES (?, ?, ?, ?)")
            ->execute(['status_change', $new_id, 'success', 'Project created: ' . $project_code]);

        audit_log($pdo, 'create', 'project', $new_id, null, ['project_code' => $project_code, 'project_name' => $project_name]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Project create failed: ' . $e->getMessage());
        set_flash('danger', 'Failed to create project. Please try again.');
        redirect(BASE_URL . '/projects/create.php');
    }

    regenerate_csrf_token();
    set_flash('success', 'Project "' . $project_name . '" created. Code: ' . $project_code);
    redirect(BASE_URL . '/projects/detail.php?id=' . $new_id);
}

$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);

$page_title = 'Create New Project';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-11 col-xl-10">
        <div class="tf-card mb-4">
            <div class="tf-card-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="text-primary">
                        <i class="bi bi-folder-plus"></i>
                    </div>
                    <div>
                        <h5 class="mb-0 fw-semibold">Create New Project</h5>
                        <p class="text-muted small mb-0">Define a new campaign project and configure its targeting, budget, and status.</p>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" id="projectForm" novalidate>
                    <?php echo csrf_field(); ?>

                    <div class="row g-4">

                        <div class="col-12">
                            <div class="p-3 rounded-3 border mb-0">
                                <p class="small fw-semibold text-uppercase text-muted mb-3 tracking-wide">Campaign Identity</p>
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <label for="project_name" class="tf-label">Project Name <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white"><i class="bi bi-tag text-secondary"></i></span>
                                            <input type="text" id="project_name" name="project_name" class="form-control"
                                                   value="<?php echo sanitize($form_data['project_name'] ?? ''); ?>" required placeholder="e.g., Summer Health Leads">
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label for="client_id" class="tf-label">Client <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white"><i class="bi bi-building text-secondary"></i></span>
                                            <select id="client_id" name="client_id" class="form-select" required>
                                                <option value="">Select Client</option>
                                                <?php foreach ($clients_list as $c): ?>
                                                <option value="<?php echo $c['id']; ?>" <?php echo ($form_data['client_id'] ?? '') == $c['id'] ? 'selected' : ''; ?>>
                                                    <?php echo sanitize($c['client_name']); ?> (<?php echo sanitize($c['client_code']); ?>)
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label for="client_survey_link" class="tf-label">Client Link <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white"><i class="bi bi-link-45deg text-secondary"></i></span>
                                            <input type="url" id="client_survey_link" name="client_survey_link" class="form-control"
                                                   value="<?php echo sanitize($form_data['client_survey_link'] ?? ''); ?>" required
                                                   placeholder="https://client.com/survey?...">
                                        </div>
                                        <div class="form-text">The destination URL where users land after clicking. The <code>click_id</code> parameter is appended automatically.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="p-3 rounded-3 border mb-0">
                                <p class="small fw-semibold text-uppercase text-muted mb-3 tracking-wide">Budget & Quotas</p>
                                <div class="row g-3">
                                    <div class="col-6 col-md-3">
                                        <label for="client_cpi" class="tf-label">Payout</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white">$</span>
                                            <input type="number" id="client_cpi" name="client_cpi" class="form-control"
                                                   value="<?php echo sanitize($form_data['client_cpi'] ?? '0.00'); ?>" step="0.01" min="0">
                                        </div>
                                    </div>

                                    <div class="col-6 col-md-3">
                                        <label for="vendor_default_cpi" class="tf-label">Default Vendor Payout</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white">$</span>
                                            <input type="number" id="vendor_default_cpi" name="vendor_default_cpi" class="form-control"
                                                   value="<?php echo sanitize($form_data['vendor_default_cpi'] ?? '0.00'); ?>" step="0.01" min="0">
                                        </div>
                                    </div>

                                    <div class="col-6 col-md-3">
                                        <label for="currency" class="tf-label">Currency</label>
                                        <select id="currency" name="currency" class="form-select">
                                            <?php $cur = $form_data['currency'] ?? 'USD'; foreach (tf_currencies() as $c): ?>
                                            <option value="<?php echo $c; ?>" <?php echo $cur === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6 col-md-3">
                                        <label for="total_quota" class="tf-label">Total Quota</label>
                                        <input type="number" id="total_quota" name="total_quota" class="form-control"
                                               value="<?php echo sanitize($form_data['total_quota'] ?? '0'); ?>" min="0">
                                        <div class="form-text">0 = unlimited</div>
                                    </div>

                                    <div class="col-6 col-md-3">
                                        <label for="daily_cap" class="tf-label">Daily Cap (completes)</label>
                                        <input type="number" id="daily_cap" name="daily_cap" class="form-control"
                                               value="<?php echo sanitize($form_data['daily_cap'] ?? '0'); ?>" min="0">
                                        <div class="form-text">0 = unlimited</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="p-3 rounded-3 border mb-0">
                                <p class="small fw-semibold text-uppercase text-muted mb-3 tracking-wide">Campaign Configuration</p>
                                <div class="row g-3">
                                    <div class="col-6 col-md-3">
                                        <label for="campaign_type" class="tf-label">Campaign Type</label>
                                        <select id="campaign_type" name="campaign_type" class="form-select">
                                            <?php $ct = $form_data['campaign_type'] ?? 'CPL'; foreach (tf_campaign_types() as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $ct === $key ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6 col-md-3">
                                        <label for="vertical" class="tf-label">Project Type (Vertical)</label>
                                        <select id="vertical" name="vertical" class="form-select">
                                            <?php $v = $form_data['vertical'] ?? 'Other'; foreach (tf_verticals() as $vt): ?>
                                            <option value="<?php echo $vt; ?>" <?php echo $v === $vt ? 'selected' : ''; ?>><?php echo sanitize($vt); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6 col-md-3">
                                        <label for="conversion_type" class="tf-label">Conversion Type</label>
                                        <select id="conversion_type" name="conversion_type" class="form-select">
                                            <?php $cv = $form_data['conversion_type'] ?? 'SOI'; foreach (tf_conversion_types() as $ct2): ?>
                                            <option value="<?php echo $ct2; ?>" <?php echo $cv === $ct2 ? 'selected' : ''; ?>><?php echo sanitize($ct2); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6 col-md-3">
                                        <label for="target_device" class="tf-label">Target Device</label>
                                        <select id="target_device" name="target_device" class="form-select">
                                            <?php $td = $form_data['target_device'] ?? 'All'; foreach (tf_target_devices() as $dv): ?>
                                            <option value="<?php echo $dv; ?>" <?php echo $td === $dv ? 'selected' : ''; ?>><?php echo sanitize($dv); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6 col-md-3">
                                        <label for="campaign_status" class="tf-label">Campaign Status</label>
                                        <select id="campaign_status" name="campaign_status" class="form-select">
                                            <?php $cs = $form_data['campaign_status'] ?? 'live'; foreach (tf_campaign_status() as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $cs === $key ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6 col-md-3">
                                        <label for="visibility" class="tf-label">Visibility</label>
                                        <select id="visibility" name="visibility" class="form-select">
                                            <?php $vis = $form_data['visibility'] ?? 'private'; foreach (tf_visibility() as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $vis === $key ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="p-3 rounded-3 border mb-0">
                                <p class="small fw-semibold text-uppercase text-muted mb-3 tracking-wide">Geography & Schedule</p>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="tf-label">Campaign GEO</label>
                                        <?php
                                        if (function_exists('tf_geo_picker_html')) {
                                            echo tf_geo_picker_html((array)($form_data['geo_codes'] ?? []));
                                        } else {
                                            echo '<div class="alert alert-warning py-2">Country list could not be loaded.</div>';
                                        }
                                        ?>
                                        <div class="form-text">Search and select one or more target countries. The first selected country is used in the project code.</div>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label for="start_date" class="tf-label">Start Date</label>
                                        <input type="date" id="start_date" name="start_date" class="form-control"
                                               value="<?php echo sanitize($form_data['start_date'] ?? ''); ?>">
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label for="end_date" class="tf-label">End Date</label>
                                        <input type="date" id="end_date" name="end_date" class="form-control"
                                               value="<?php echo sanitize($form_data['end_date'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="p-3 rounded-3 border mb-0">
                                <p class="small fw-semibold text-uppercase text-muted mb-3 tracking-wide">Additional Information</p>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label for="description" class="tf-label">Description</label>
                                        <textarea id="description" name="description" class="form-control" rows="3"
                                                  placeholder="Campaign brief: audience, vertical, requirements..."><?php echo sanitize($form_data['description'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-4 mt-4 border-top">
                        <a href="<?php echo BASE_URL; ?>/projects/list.php" class="btn btn-outline-secondary px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg"></i> Create Project</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
