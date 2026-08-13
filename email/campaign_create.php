<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$project_id = intval($_GET['project_id'] ?? 0);
if (!$project_id) redirect(BASE_URL . '/projects/list.php');

$stmt = $pdo->prepare("SELECT p.*, c.default_currency FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) {
    set_flash('danger', 'Project not found.');
    redirect(BASE_URL . '/projects/list.php');
}

$vendors = $pdo->prepare("SELECT pv.vendor_id AS id, gv.vendor_name FROM project_vendor pv JOIN global_vendors gv ON gv.id = pv.vendor_id WHERE pv.project_id = ? ORDER BY gv.vendor_name");
$vendors->execute([$project_id]);
$vendors = $vendors->fetchAll();

$lists = $pdo->prepare("
    SELECT l.id, l.name, l.vendor_id, gv.vendor_name
    FROM email_lists l
    JOIN global_vendors gv ON gv.id = l.vendor_id
    JOIN project_vendor pv ON pv.vendor_id = l.vendor_id AND pv.project_id = ?
    ORDER BY l.name
");
$lists->execute([$project_id]);
$lists = $lists->fetchAll();

$templates = $pdo->query("SELECT id, name, subject FROM email_templates ORDER BY is_default DESC, name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/email/campaign_create.php?project_id=' . $project_id);
    }

    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $list_id = intval($_POST['list_id'] ?? 0);
    $template_id = intval($_POST['template_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $html_body = trim($_POST['html_body'] ?? '');
    $from_name = trim($_POST['from_name'] ?? '');
    $from_email = trim($_POST['from_email'] ?? '');
    $daily_limit = intval($_POST['daily_limit'] ?? 1000);
    $total_limit = intval($_POST['total_limit'] ?? 0);
    $is_multi_step = !empty($_POST['is_multi_step']) ? 1 : 0;

    $errors = [];
    if (!$vendor_id) $errors[] = 'Please select a vendor.';
    if (!$list_id) $errors[] = 'Please select a central email list.';
    $assigned = $pdo->prepare("SELECT 1 FROM project_vendor WHERE project_id = ? AND vendor_id = ?");
    $assigned->execute([$project_id, $vendor_id]);
    if ($vendor_id && !$assigned->fetch()) $errors[] = 'Vendor is not assigned to this project.';
    $owned = $pdo->prepare("SELECT 1 FROM email_lists WHERE id = ? AND vendor_id = ?");
    $owned->execute([$list_id, $vendor_id]);
    if ($list_id && !$owned->fetch()) $errors[] = 'List must belong to the selected Email vendor.';
    if ($name === '') $errors[] = 'Campaign name is required.';
    if ($subject === '') $errors[] = 'Subject is required.';
    if ($html_body === '') $errors[] = 'HTML body is required.';
    if ($daily_limit < 1) $errors[] = 'Daily limit must be at least 1.';

    if (!empty($errors)) {
        set_flash('danger', implode(' | ', $errors));
        $_SESSION['form_data'] = $_POST;
        redirect(BASE_URL . '/email/campaign_create.php?project_id=' . $project_id);
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO email_campaigns (project_id, vendor_id, list_id, template_id, name, subject, html_body, from_name, from_email, daily_limit, total_limit, is_multi_step, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, NOW(), NOW())");
        $stmt->execute([$project_id, $vendor_id, $list_id, $template_id ?: null, $name, $subject, $html_body, $from_name ?: null, $from_email ?: null, $daily_limit, $total_limit, $is_multi_step, $_SESSION['user_id'] ?? null]);

        $new_id = (int)$pdo->lastInsertId();
        audit_log($pdo, 'create', 'email_campaign', $new_id, null, ['project_id' => $project_id, 'vendor_id' => $vendor_id, 'name' => $name]);
        set_flash('success', 'Campaign created.');
        redirect(BASE_URL . '/email/campaigns.php?project_id=' . $project_id);
    } catch (Throwable $e) {
        error_log('Campaign create failed: ' . $e->getMessage());
        set_flash('danger', 'Failed to create campaign.');
        redirect(BASE_URL . '/email/campaign_create.php?project_id=' . $project_id);
    }
}

$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);
$page_title = 'New Email Campaign';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="tf-page">
    <div class="tf-card">
        <div class="tf-card-header">
            <div>
                <h5 class="tf-card-title">New Email Campaign</h5>
                <p class="tf-card-subtitle">Project: <code><?php echo sanitize($project['project_code']); ?></code> — <?php echo sanitize($project['project_name']); ?></p>
            </div>
        </div>
        <div class="tf-card-body">
            <form method="POST" class="tf-form" novalidate>
                <?php echo csrf_field(); ?>
                <div class="tf-form-row">
                    <div class="tf-field col-12 col-md-6">
                        <label for="vendor_id" class="tf-label">Email Vendor <span class="tf-required" aria-hidden="true">*</span></label>
                        <select id="vendor_id" name="vendor_id" class="form-select" required>
                            <option value="">Select Vendor</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?php echo (int)$v['id']; ?>" <?php echo ($form_data['vendor_id'] ?? '') == $v['id'] ? 'selected' : ''; ?>><?php echo sanitize($v['vendor_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tf-field col-12 col-md-6">
                        <label for="list_id" class="tf-label">Email List <span class="tf-required" aria-hidden="true">*</span></label>
                        <select id="list_id" name="list_id" class="form-select" required>
                            <option value="">Select list</option>
                            <?php foreach ($lists as $lst): ?>
                                <option value="<?php echo (int)$lst['id']; ?>" <?php echo ($form_data['list_id'] ?? '') == $lst['id'] ? 'selected' : ''; ?>><?php echo sanitize($lst['name']); ?> — <?php echo sanitize($lst['vendor_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tf-field col-12 col-md-6">
                        <label for="template_id" class="tf-label">Template</label>
                        <select id="template_id" name="template_id" class="form-select" onchange="applyTemplate()">
                            <option value="">Custom / Blank</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>" data-subject="<?php echo sanitize($t['subject']); ?>" data-html="<?php echo sanitize($t['html_body']); ?>"><?php echo sanitize($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="tf-form-row">
                    <div class="tf-field col-12">
                        <label for="name" class="tf-label">Campaign Name <span class="tf-required" aria-hidden="true">*</span></label>
                        <input type="text" id="name" name="name" class="form-control" value="<?php echo sanitize($form_data['name'] ?? ''); ?>" required>
                    </div>
                </div>
                <div class="tf-form-row">
                    <div class="tf-field col-12">
                        <label for="subject" class="tf-label">Subject <span class="tf-required" aria-hidden="true">*</span></label>
                        <input type="text" id="subject" name="subject" class="form-control" value="<?php echo sanitize($form_data['subject'] ?? ''); ?>" required>
                    </div>
                </div>
                <div class="tf-form-row">
                    <div class="tf-field col-12">
                        <label for="html_body" class="tf-label">HTML Body <span class="tf-required" aria-hidden="true">*</span></label>
                        <textarea id="html_body" name="html_body" class="form-control" rows="14" required placeholder="<html>...</html>"><?php echo sanitize($form_data['html_body'] ?? ''); ?></textarea>
                        <p class="tf-help">Supports standard HTML. Merge field example: <code>{{name}}</code>, <code>{{email}}</code>, <code>{{country}}</code>.</p>
                    </div>
                </div>
                <div class="tf-form-row">
                    <div class="tf-field col-6 col-md-3">
                        <label for="daily_limit" class="tf-label">Daily Limit <span class="tf-required" aria-hidden="true">*</span></label>
                        <input type="number" id="daily_limit" name="daily_limit" class="form-control" value="<?php echo sanitize($form_data['daily_limit'] ?? '1000'); ?>" min="1" required>
                    </div>
                    <div class="tf-field col-6 col-md-3">
                        <label for="total_limit" class="tf-label">Total Limit</label>
                        <input type="number" id="total_limit" name="total_limit" class="form-control" value="<?php echo sanitize($form_data['total_limit'] ?? '0'); ?>" min="0">
                        <p class="tf-help">0 = unlimited</p>
                    </div>
                    <div class="tf-field col-6 col-md-3">
                        <label for="from_name" class="tf-label">From Name</label>
                        <input type="text" id="from_name" name="from_name" class="form-control" value="<?php echo sanitize($form_data['from_name'] ?? ''); ?>">
                    </div>
                    <div class="tf-field col-6 col-md-3">
                        <label for="from_email" class="tf-label">From Email</label>
                        <input type="email" id="from_email" name="from_email" class="form-control" value="<?php echo sanitize($form_data['from_email'] ?? ''); ?>">
                    </div>
                </div>
                <div class="tf-form-row">
                    <div class="tf-field col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_multi_step" name="is_multi_step" value="1" <?php echo !empty($form_data['is_multi_step']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_multi_step">Allow multiple emails to same recipient for this campaign</label>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <a href="<?php echo BASE_URL; ?>/email/campaigns.php?project_id=<?php echo (int)$project_id; ?>" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Create Campaign</button>
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
