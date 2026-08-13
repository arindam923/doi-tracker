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
$existing_geo = $pdo->prepare("SELECT country_code FROM campaign_geo WHERE project_id = ?");
$existing_geo->execute([$id]);
$existing_geo_codes = array_column($existing_geo->fetchAll(), 'country_code');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/projects/edit.php?id=' . $id);
    }

    $project_name = trim($_POST['project_name'] ?? '');
    $client_id = intval($_POST['client_id'] ?? 0);
    $client_survey_link = trim($_POST['client_survey_link'] ?? '');
    $preview_link = trim($_POST['preview_link'] ?? '');
    $client_cpi = floatval($_POST['client_cpi'] ?? 0);
    $vendor_default_cpi = floatval($_POST['vendor_default_cpi'] ?? 0);
    $total_quota = intval($_POST['total_quota'] ?? 0);
    $daily_cap = intval($_POST['daily_cap'] ?? 0);
    $country_target = trim($_POST['country_target'] ?? '');
    $campaign_type = $_POST['campaign_type'] ?? ($project['campaign_type'] ?? 'CPL');
    $vertical = $_POST['vertical'] ?? 'Other';
    $conversion_type = $_POST['conversion_type'] ?? 'SOI';
    $target_device = $_POST['target_device'] ?? 'All';
    $campaign_status = $_POST['campaign_status'] ?? 'live';
    $visibility = $_POST['visibility'] ?? 'private';
    $currency = $_POST['currency'] ?? 'USD';
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $geo_codes = $_POST['geo_codes'] ?? [];

    $errors = [];
    if (empty($project_name)) $errors[] = 'Project name is required.';
    if (!$client_id) $errors[] = 'Please select a client.';
    if (!in_array($campaign_type, ['CPL', 'CPC', 'CPA', 'CPS', 'CPI', 'CPM', 'RevShare', 'Hybrid'], true)) $campaign_type = 'CPL';
    if (!in_array($vertical, tf_verticals(), true)) $vertical = 'Other';
    if (!in_array($conversion_type, tf_conversion_types(), true)) $conversion_type = 'SOI';
    if (!in_array($target_device, tf_target_devices(), true)) $target_device = 'All';
    if (!array_key_exists($campaign_status, tf_campaign_status())) $campaign_status = 'live';
    if (!array_key_exists($visibility, tf_visibility())) $visibility = 'private';
    if (!in_array($currency, tf_currencies(), true)) $currency = 'USD';

    if (!empty($errors)) {
        set_flash('danger', implode(' | ', $errors));
        $_SESSION['edit_form_data'] = $_POST;
        redirect(BASE_URL . '/projects/edit.php?id=' . $id);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            UPDATE projects SET project_name=?, client_id=?, client_survey_link=?, preview_link=?, client_cpi=?, vendor_default_cpi=?,
                currency=?, total_quota=?, daily_cap=?, country_target=?, campaign_type=?,
                vertical=?, conversion_type=?, target_device=?, campaign_status=?, visibility=?,
                start_date=?, end_date=?, description=?
            WHERE id=?
        ");
        $stmt->execute([
            $project_name, $client_id, $client_survey_link, $preview_link, $client_cpi, $vendor_default_cpi,
            $currency, $total_quota, $daily_cap, $country_target, $campaign_type,
            $vertical, $conversion_type, $target_device, $campaign_status, $visibility,
            $start_date ?: null, $end_date ?: null, $description, $id
        ]);

        // Sync campaign_geo
        $pdo->prepare("DELETE FROM campaign_geo WHERE project_id = ?")->execute([$id]);
        if (is_array($geo_codes) && !empty($geo_codes)) {
            $geo_insert = $pdo->prepare("INSERT IGNORE INTO campaign_geo (project_id, country_code, country_name) VALUES (?, ?, ?)");
            foreach ($geo_codes as $code) {
                $code = strtoupper(substr(trim($code), 0, 2));
                if (preg_match('/^[A-Z]{2}$/', $code)) {
                    $name = tf_countries()[$code] ?? null;
                    $geo_insert->execute([$id, $code, $name]);
                }
            }
        }

        ensure_project_short_code($pdo, $id, $project['short_code'] ?? null);

        audit_log($pdo, 'update', 'project', $id, null, ['project_name' => $project_name]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Project edit failed: ' . $e->getMessage());
        set_flash('danger', 'Failed to update project.');
        redirect(BASE_URL . '/projects/edit.php?id=' . $id);
    }

    regenerate_csrf_token();
    set_flash('success', 'Project updated successfully.');
    redirect(BASE_URL . '/projects/detail.php?id=' . $id);
}

$f = $project;
$page_title = 'Edit Project';
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

                    <div class="row g-3">
                        <div class="col-12 col-md-8">
                            <label for="project_name" class="form-label small fw-semibold text-secondary">Project Name <span class="text-danger">*</span></label>
                            <input type="text" id="project_name" name="project_name" class="form-control"
                                   value="<?php echo sanitize($f['project_name']); ?>" required>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="client_id" class="form-label small fw-semibold text-secondary">Client <span class="text-danger">*</span></label>
                            <select id="client_id" name="client_id" class="form-select" required>
                                <?php foreach ($clients_list as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo $f['client_id'] == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($c['client_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label for="client_survey_link" class="form-label small fw-semibold text-secondary">Client Link</label>
                            <input type="url" id="client_survey_link" name="client_survey_link" class="form-control"
                                   value="<?php echo sanitize($f['client_survey_link'] ?? ''); ?>">
                        </div>

                        <div class="col-12">
                            <label for="preview_link" class="form-label small fw-semibold text-secondary">Preview Link</label>
                            <input type="url" id="preview_link" name="preview_link" class="form-control"
                                   value="<?php echo sanitize($f['preview_link'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-2">
                            <label for="client_cpi" class="form-label small fw-semibold text-secondary">Payout</label>
                            <input type="number" id="client_cpi" name="client_cpi" class="form-control"
                                   value="<?php echo sanitize($f['client_cpi']); ?>" step="0.01" min="0">
                        </div>

                        <div class="col-6 col-md-2">
                            <label for="vendor_default_cpi" class="form-label small fw-semibold text-secondary">Default Vendor Payout</label>
                            <input type="number" id="vendor_default_cpi" name="vendor_default_cpi" class="form-control"
                                   value="<?php echo sanitize($f['vendor_default_cpi']); ?>" step="0.01" min="0">
                        </div>

                        <div class="col-6 col-md-2">
                            <label for="currency" class="form-label small fw-semibold text-secondary">Currency</label>
                            <select id="currency" name="currency" class="form-select">
                                <?php $cur = $f['currency'] ?? 'USD'; foreach (tf_currencies() as $c): ?>
                                <option value="<?php echo $c; ?>" <?php echo $cur === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-2">
                            <label for="total_quota" class="form-label small fw-semibold text-secondary">Total Quota</label>
                            <input type="number" id="total_quota" name="total_quota" class="form-control"
                                   value="<?php echo (int)$f['total_quota']; ?>" min="0">
                        </div>

                        <div class="col-6 col-md-2">
                            <label for="daily_cap" class="form-label small fw-semibold text-secondary">Daily Cap</label>
                            <input type="number" id="daily_cap" name="daily_cap" class="form-control"
                                   value="<?php echo (int)($f['daily_cap'] ?? 0); ?>" min="0">
                        </div>

                        <div class="col-6 col-md-2">
                            <label for="campaign_type" class="form-label small fw-semibold text-secondary">Campaign Type</label>
                            <select id="campaign_type" name="campaign_type" class="form-select">
                                <?php $ct = $f['campaign_type'] ?? 'CPL'; foreach (tf_campaign_types() as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo $ct === $key ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="vertical" class="form-label small fw-semibold text-secondary">Project Type (Vertical)</label>
                            <select id="vertical" name="vertical" class="form-select">
                                <?php $v = $f['vertical'] ?? 'Other'; foreach (tf_verticals() as $vt): ?>
                                <option value="<?php echo $vt; ?>" <?php echo $v === $vt ? 'selected' : ''; ?>><?php echo sanitize($vt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="conversion_type" class="form-label small fw-semibold text-secondary">Conversion Type</label>
                            <select id="conversion_type" name="conversion_type" class="form-select">
                                <?php $cv = $f['conversion_type'] ?? 'SOI'; foreach (tf_conversion_types() as $ct2): ?>
                                <option value="<?php echo $ct2; ?>" <?php echo $cv === $ct2 ? 'selected' : ''; ?>><?php echo sanitize($ct2); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="target_device" class="form-label small fw-semibold text-secondary">Target Device</label>
                            <select id="target_device" name="target_device" class="form-select">
                                <?php $td = $f['target_device'] ?? 'All'; foreach (tf_target_devices() as $dv): ?>
                                <option value="<?php echo $dv; ?>" <?php echo $td === $dv ? 'selected' : ''; ?>><?php echo sanitize($dv); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="campaign_status" class="form-label small fw-semibold text-secondary">Campaign Status</label>
                            <select id="campaign_status" name="campaign_status" class="form-select">
                                <?php $cs = $f['campaign_status'] ?? 'live'; foreach (tf_campaign_status() as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo $cs === $key ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="visibility" class="form-label small fw-semibold text-secondary">Visibility</label>
                            <select id="visibility" name="visibility" class="form-select">
                                <?php $vis = $f['visibility'] ?? 'private'; foreach (tf_visibility() as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo $vis === $key ? 'selected' : ''; ?>><?php echo sanitize($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold text-secondary">Campaign GEO <span class="text-muted">(searchable multi-select)</span></label>
                            <input type="text" id="geo_search" class="form-control mb-2" placeholder="Type to filter countries…">
                            <select id="geo_codes" name="geo_codes[]" multiple size="8" class="form-select">
                                <?php foreach (tf_countries() as $code => $name): ?>
                                <option value="<?php echo $code; ?>" <?php echo in_array($code, $existing_geo_codes, true) ? 'selected' : ''; ?>><?php echo $code; ?> — <?php echo sanitize($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="country_target" class="form-label small fw-semibold text-secondary">Primary Country</label>
                            <input type="text" id="country_target" name="country_target" class="form-control"
                                   value="<?php echo sanitize($f['country_target'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="start_date" class="form-label small fw-semibold text-secondary">Start Date</label>
                            <input type="date" id="start_date" name="start_date" class="form-control"
                                   value="<?php echo sanitize($f['start_date'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-3">
                            <label for="end_date" class="form-label small fw-semibold text-secondary">End Date</label>
                            <input type="date" id="end_date" name="end_date" class="form-control"
                                   value="<?php echo sanitize($f['end_date'] ?? ''); ?>">
                        </div>

                        <div class="col-12">
                            <label for="description" class="form-label small fw-semibold text-secondary">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="3"><?php echo sanitize($f['description'] ?? ''); ?></textarea>
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

<script>
document.getElementById('geo_search').addEventListener('input', function(e) {
    const term = e.target.value.toLowerCase();
    const sel = document.getElementById('geo_codes');
    for (const opt of sel.options) {
        opt.hidden = term && !opt.text.toLowerCase().includes(term);
    }
});
</script>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
