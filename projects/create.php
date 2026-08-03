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
    $country_target = trim($_POST['country_target'] ?? '');
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $errors = [];
    if (empty($project_name)) $errors[] = 'Project name is required.';
    if (!$client_id) $errors[] = 'Please select a client.';
    if (empty($client_survey_link)) $errors[] = 'Client survey link is required.';

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

    $stmt = $pdo->prepare("
        INSERT INTO projects (project_code, project_name, client_id, client_survey_link, postback_token,
            client_cpi, vendor_default_cpi, total_quota, country_target, start_date, end_date, description, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $project_code, $project_name, $client_id, $client_survey_link, $postback_token,
        $client_cpi, $vendor_default_cpi, $total_quota, $country_target,
        $start_date ?: null, $end_date ?: null, $description, $_SESSION['user_id']
    ]);

    $new_id = $pdo->lastInsertId();

    if (!$new_id) {
        set_flash('danger', 'Failed to create project. Please try again.');
        redirect(BASE_URL . '/projects/create.php');
    }

    $pdo->prepare("INSERT INTO logs (log_type, project_id, status, message) VALUES (?, ?, ?, ?)")
        ->execute(['status_change', $new_id, 'success', 'Project created: ' . $project_code]);

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
    <div class="col-12 col-xl-10">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 fw-semibold">Project Details</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="projectForm">
                    <?php echo csrf_field(); ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-8">
                            <label for="project_name" class="form-label small fw-semibold text-secondary">Project Name <span class="text-danger">*</span></label>
                            <input type="text" id="project_name" name="project_name" class="form-control"
                                   value="<?php echo sanitize($form_data['project_name'] ?? ''); ?>" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="client_id" class="form-label small fw-semibold text-secondary">Client <span class="text-danger">*</span></label>
                            <select id="client_id" name="client_id" class="form-select" required>
                                <option value="">Select Client</option>
                                <?php foreach ($clients_list as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($form_data['client_id'] ?? '') == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($c['client_name']); ?> (<?php echo sanitize($c['client_code']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label for="client_survey_link" class="form-label small fw-semibold text-secondary">Client Survey / Registration Link <span class="text-danger">*</span></label>
                            <input type="url" id="client_survey_link" name="client_survey_link" class="form-control"
                                   value="<?php echo sanitize($form_data['client_survey_link'] ?? ''); ?>" required
                                   placeholder="https://client.com/survey?...">
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="client_cpi" class="form-label small fw-semibold text-secondary">Client CPI (Revenue)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" id="client_cpi" name="client_cpi" class="form-control"
                                       value="<?php echo sanitize($form_data['client_cpi'] ?? '0.00'); ?>" step="0.01" min="0">
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="vendor_default_cpi" class="form-label small fw-semibold text-secondary">Default Vendor Payout</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" id="vendor_default_cpi" name="vendor_default_cpi" class="form-control"
                                       value="<?php echo sanitize($form_data['vendor_default_cpi'] ?? '0.00'); ?>" step="0.01" min="0">
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="total_quota" class="form-label small fw-semibold text-secondary">Total Quota</label>
                            <input type="number" id="total_quota" name="total_quota" class="form-control"
                                   value="<?php echo sanitize($form_data['total_quota'] ?? '0'); ?>" min="0">
                            <p class="form-text">0 = unlimited</p>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="country_target" class="form-label small fw-semibold text-secondary">Target Country</label>
                            <input type="text" id="country_target" name="country_target" class="form-control"
                                   value="<?php echo sanitize($form_data['country_target'] ?? ''); ?>"
                                   placeholder="e.g. India, US">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="start_date" class="form-label small fw-semibold text-secondary">Start Date</label>
                            <input type="date" id="start_date" name="start_date" class="form-control"
                                   value="<?php echo sanitize($form_data['start_date'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="end_date" class="form-label small fw-semibold text-secondary">End Date</label>
                            <input type="date" id="end_date" name="end_date" class="form-control"
                                   value="<?php echo sanitize($form_data['end_date'] ?? ''); ?>">
                        </div>

                        <div class="col-12">
                            <label for="description" class="form-label small fw-semibold text-secondary">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="3"
                                      placeholder="Campaign brief: audience, vertical, requirements..."><?php echo sanitize($form_data['description'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                        <a href="<?php echo BASE_URL; ?>/projects/list.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i>Create Project</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
