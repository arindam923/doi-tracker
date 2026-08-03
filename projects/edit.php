<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$id = intval($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/projects/list.php');

$stmt = $pdo->prepare("SELECT p.*, c.client_name FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$id]);
$project = $stmt->fetch();
if (!$project) {
    set_flash('danger', 'Project not found.');
    redirect(BASE_URL . '/projects/list.php');
}

$clients_list = $pdo->query("SELECT id, client_name, client_code FROM clients WHERE is_active = 1 ORDER BY client_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/projects/edit.php?id=' . $id);
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

    if (!empty($errors)) {
        set_flash('danger', implode(' | ', $errors));
        $_SESSION['edit_form_data'] = $_POST;
        redirect(BASE_URL . '/projects/edit.php?id=' . $id);
    }

    $stmt = $pdo->prepare("
        UPDATE projects SET project_name=?, client_id=?, client_survey_link=?, client_cpi=?, vendor_default_cpi=?,
            total_quota=?, country_target=?, start_date=?, end_date=?, description=?
        WHERE id=?
    ");
    $stmt->execute([
        $project_name, $client_id, $client_survey_link, $client_cpi, $vendor_default_cpi,
        $total_quota, $country_target, $start_date ?: null, $end_date ?: null, $description, $id
    ]);

    regenerate_csrf_token();
    set_flash('success', 'Project updated successfully.');
    redirect(BASE_URL . '/projects/detail.php?id=' . $id);
}

$page_title = 'Edit Project';
$edit_form = $_SESSION['edit_form_data'] ?? null;
unset($_SESSION['edit_form_data']);

require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-10">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <div class="d-flex flex-column">
                    <h5 class="mb-0 fw-semibold">Edit: <?php echo sanitize($project['project_name']); ?></h5>
                    <code class="small text-muted" style="font-family: ui-monospace, monospace;"><?php echo sanitize($project['project_code']); ?></code>
                </div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <?php $f = $edit_form; ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-8">
                            <label for="project_name" class="form-label small fw-semibold text-secondary">Project Name <span class="text-danger">*</span></label>
                            <input type="text" id="project_name" name="project_name" class="form-control"
                                   value="<?php echo sanitize($f['project_name'] ?? $project['project_name']); ?>" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="client_id" class="form-label small fw-semibold text-secondary">Client <span class="text-danger">*</span></label>
                            <select id="client_id" name="client_id" class="form-select" required>
                                <?php foreach ($clients_list as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($f['client_id'] ?? $project['client_id']) == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($c['client_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label for="client_survey_link" class="form-label small fw-semibold text-secondary">Client Survey Link</label>
                            <input type="url" id="client_survey_link" name="client_survey_link" class="form-control"
                                   value="<?php echo sanitize($f['client_survey_link'] ?? ($project['client_survey_link'] ?? '')); ?>">
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="client_cpi" class="form-label small fw-semibold text-secondary">Client CPI</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" id="client_cpi" name="client_cpi" class="form-control"
                                       value="<?php echo sanitize($f['client_cpi'] ?? $project['client_cpi']); ?>" step="0.01" min="0">
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="vendor_default_cpi" class="form-label small fw-semibold text-secondary">Default Vendor Payout</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" id="vendor_default_cpi" name="vendor_default_cpi" class="form-control"
                                       value="<?php echo sanitize($f['vendor_default_cpi'] ?? $project['vendor_default_cpi']); ?>" step="0.01" min="0">
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="total_quota" class="form-label small fw-semibold text-secondary">Total Quota</label>
                            <input type="number" id="total_quota" name="total_quota" class="form-control"
                                   value="<?php echo sanitize($f['total_quota'] ?? $project['total_quota']); ?>" min="0">
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="country_target" class="form-label small fw-semibold text-secondary">Target Country</label>
                            <input type="text" id="country_target" name="country_target" class="form-control"
                                   value="<?php echo sanitize($f['country_target'] ?? ($project['country_target'] ?? '')); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="start_date" class="form-label small fw-semibold text-secondary">Start Date</label>
                            <input type="date" id="start_date" name="start_date" class="form-control"
                                   value="<?php echo sanitize($f['start_date'] ?? ($project['start_date'] ?? '')); ?>">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="end_date" class="form-label small fw-semibold text-secondary">End Date</label>
                            <input type="date" id="end_date" name="end_date" class="form-control"
                                   value="<?php echo sanitize($f['end_date'] ?? ($project['end_date'] ?? '')); ?>">
                        </div>

                        <div class="col-12">
                            <label for="description" class="form-label small fw-semibold text-secondary">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="3"><?php echo sanitize($f['description'] ?? ($project['description'] ?? '')); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-3 mt-3 border-top">
                        <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $id; ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i>Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
