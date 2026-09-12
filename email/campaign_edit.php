<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$id = intval($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/projects/list.php');

$stmt = $pdo->prepare("SELECT ec.*, p.project_code, p.project_name, p.country_target FROM email_campaigns ec JOIN projects p ON ec.project_id = p.id WHERE ec.id = ?");
$stmt->execute([$id]);
$campaign = $stmt->fetch();
if (!$campaign) {
    set_flash('danger', 'Campaign not found.');
        redirect(BASE_URL . '/email/campaign_detail.php?id=' . $id);
}

$vendors = $pdo->prepare("SELECT pv.vendor_id AS id, gv.vendor_name FROM project_vendor pv JOIN global_vendors gv ON gv.id = pv.vendor_id WHERE pv.project_id = ? AND FIND_IN_SET('Email', gv.traffic_type) ORDER BY gv.vendor_name");
$vendors->execute([$campaign['project_id']]);
$vendors = $vendors->fetchAll();

email_ensure_starter_templates($pdo, $_SESSION['user_id'] ?? null);
$templates = $pdo->query("SELECT id, name, subject, html_body FROM email_templates ORDER BY is_default DESC, name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/email/campaign_edit.php?id=' . $id);
    }

    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $template_id = intval($_POST['template_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $html_body = trim($_POST['html_body'] ?? '');
    if (!empty($_FILES['html_file']['tmp_name']) && is_uploaded_file($_FILES['html_file']['tmp_name'])) {
        $html_body = (string)file_get_contents($_FILES['html_file']['tmp_name']);
    }
    $from_name = trim($_POST['from_name'] ?? '');
    $from_email = trim($_POST['from_email'] ?? '');
    $daily_limit = intval($_POST['daily_limit'] ?? 1000);
    $total_limit = intval($_POST['total_limit'] ?? 0);
    $is_multi_step = !empty($_POST['is_multi_step']) ? 1 : 0;
    $geo_codes = tf_normalize_geo_codes($_POST['geo_codes'] ?? []);

    $errors = [];
    if (!$vendor_id) $errors[] = 'Please select a vendor.';
    if ($name === '') $errors[] = 'Campaign name is required.';
    if ($subject === '') $errors[] = 'Subject is required.';
    if ($html_body === '') $errors[] = 'HTML body is required.';
    if ($daily_limit < 1) $errors[] = 'Daily limit must be at least 1.';

    if (!empty($errors)) {
        set_flash('danger', implode(' | ', $errors));
        redirect(BASE_URL . '/email/campaign_edit.php?id=' . $id);
    }

    try {
        $stmt = $pdo->prepare("UPDATE email_campaigns SET vendor_id=?, template_id=?, name=?, subject=?, html_body=?, from_name=?, from_email=?, daily_limit=?, total_limit=?, is_multi_step=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$vendor_id, $template_id ?: null, $name, $subject, $html_body, $from_name ?: null, $from_email ?: null, $daily_limit, $total_limit, $is_multi_step, $id]);
        email_campaign_save_geos($pdo, $id, $geo_codes);

        audit_log($pdo, 'update', 'email_campaign', $id, null, ['name' => $name]);
        set_flash('success', 'Campaign updated.');
        redirect(BASE_URL . '/email/campaign_detail.php?id=' . $id);
    } catch (Throwable $e) {
        error_log('Campaign update failed: ' . $e->getMessage());
        set_flash('danger', 'Failed to update campaign.');
        redirect(BASE_URL . '/email/campaign_edit.php?id=' . $id);
    }
}

$page_title = 'Edit Campaign';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="tf-page">
    <div class="tf-card">
        <div class="tf-card-header">
            <div>
                <h5 class="tf-card-title">Edit Campaign</h5>
                <p class="tf-card-subtitle">Project: <code><?php echo sanitize($campaign['project_code']); ?></code> — <?php echo sanitize($campaign['project_name']); ?></p>
            </div>
        </div>
        <div class="tf-card-body">
            <form method="POST" class="tf-form" enctype="multipart/form-data" novalidate>
                <?php echo csrf_field(); ?>
                <div class="tf-form-row">
                    <div class="tf-field col-12 col-md-6">
                        <label for="vendor_id" class="tf-label">Email Vendor <span class="tf-required" aria-hidden="true">*</span></label>
                        <select id="vendor_id" name="vendor_id" class="form-select" required>
                            <option value="">Select Vendor</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?php echo (int)$v['id']; ?>" <?php echo $campaign['vendor_id'] == $v['id'] ? 'selected' : ''; ?>><?php echo sanitize($v['vendor_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tf-field col-12 col-md-6">
                        <label for="template_id" class="tf-label">Template</label>
                        <select id="template_id" name="template_id" class="form-select" onchange="applyTemplate()">
                            <option value="">Custom / Blank</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>" data-subject="<?php echo sanitize($t['subject']); ?>" data-html="<?php echo sanitize($t['html_body']); ?>" <?php echo $campaign['template_id'] == $t['id'] ? 'selected' : ''; ?>><?php echo sanitize($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="tf-form-row">
                    <div class="tf-field col-12">
                        <label for="name" class="tf-label">Campaign Name <span class="tf-required" aria-hidden="true">*</span></label>
                        <input type="text" id="name" name="name" class="form-control" value="<?php echo sanitize($campaign['name']); ?>" required>
                    </div>
                </div>
                <div class="tf-form-row">
                    <div class="tf-field col-12">
                        <label for="subject" class="tf-label">Subject <span class="tf-required" aria-hidden="true">*</span></label>
                        <input type="text" id="subject" name="subject" class="form-control" value="<?php echo sanitize($campaign['subject']); ?>" required>
                    </div>
                </div>
                <div class="tf-form-row">
                    <div class="tf-field col-12">
                        <label for="html_body" class="tf-label">HTML Body <span class="tf-required" aria-hidden="true">*</span></label>
                        <textarea id="html_body" name="html_body" class="form-control" rows="14" placeholder="<html>...</html>"><?php echo sanitize($campaign['html_body']); ?></textarea>
                    </div>
                </div>
                <div class="tf-form-row">
                    <div class="tf-field col-12">
                        <label for="html_file" class="tf-label">Replace HTML from file</label>
                        <input type="file" id="html_file" name="html_file" class="form-control" accept=".html,.htm,text/html">
                    </div>
                </div>
                <div class="tf-form-row">
                    <div class="tf-field col-6 col-md-3">
                        <label for="daily_limit" class="tf-label">Daily Limit <span class="tf-required" aria-hidden="true">*</span></label>
                        <input type="number" id="daily_limit" name="daily_limit" class="form-control" value="<?php echo (int)$campaign['daily_limit']; ?>" min="1" required>
                    </div>
                    <div class="tf-field col-6 col-md-3">
                        <label for="total_limit" class="tf-label">Total Limit</label>
                        <input type="number" id="total_limit" name="total_limit" class="form-control" value="<?php echo (int)$campaign['total_limit']; ?>" min="0">
                        <p class="tf-help">0 = unlimited</p>
                    </div>
                    <div class="tf-field col-6 col-md-3">
                        <label for="from_name" class="tf-label">From Name</label>
                        <input type="text" id="from_name" name="from_name" class="form-control" value="<?php echo sanitize($campaign['from_name'] ?? ''); ?>">
                    </div>
                    <div class="tf-field col-6 col-md-3">
                        <label for="from_email" class="tf-label">From Email</label>
                        <input type="email" id="from_email" name="from_email" class="form-control" value="<?php echo sanitize($campaign['from_email'] ?? ''); ?>">
                    </div>
                </div>
                <?php $campaign_geos = email_campaign_geos($pdo, $id, (int)$campaign['project_id']); $is_override = !empty($campaign_geos) || $pdo->query("SELECT COUNT(*) FROM email_campaign_geo WHERE campaign_id=".(int)$id)->fetchColumn() > 0; ?>
                <div class="tf-form-row">
                    <div class="tf-field col-12">
                        <label class="tf-label">Target GEO</label>
                        <?php echo tf_geo_picker_html($campaign_geos); ?>
                        <p class="tf-help">Leave empty to use project GEO (<?php echo sanitize(implode(', ', tf_normalize_geo_codes(array_column($pdo->query("SELECT country_code FROM campaign_geo WHERE project_id=".(int)$campaign['project_id'])->fetchAll(), 'country_code')))); ?>). Campaign selection overrides project.</p>
                    </div>
                </div>
                <div class="tf-form-row">
                    <div class="tf-field col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_multi_step" name="is_multi_step" value="1" <?php echo $campaign['is_multi_step'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_multi_step">Allow manual resend — same email can receive this campaign multiple times</label>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo (int)$campaign['project_id']; ?>#email-campaigns" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function applyTemplate() {
    const sel = document.getElementById('template_id');
    const subject = document.getElementById('subject');
    const html = document.getElementById('html_body');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.subject) {
        subject.value = opt.dataset.subject;
        html.value = opt.dataset.html;
    }
}
</script>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
