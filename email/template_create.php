<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$page_title = 'New Email Template';
require_once __DIR__ . '/../helpers/layout_header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/email/template_create.php');
    }

    $name = trim($_POST['name'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $html_body = trim($_POST['html_body'] ?? '');
    $is_default = !empty($_POST['is_default']) ? 1 : 0;

    $errors = [];
    if ($name === '') $errors[] = 'Template name is required.';
    if ($subject === '') $errors[] = 'Subject is required.';
    if ($html_body === '') $errors[] = 'HTML body is required.';

    if (!empty($errors)) {
        set_flash('danger', implode(' | ', $errors));
        $_SESSION['form_data'] = $_POST;
        redirect(BASE_URL . '/email/template_create.php');
    }

    try {
        if ($is_default) {
            $pdo->prepare("UPDATE email_templates SET is_default = 0")->execute();
        }

        $stmt = $pdo->prepare("INSERT INTO email_templates (name, subject, html_body, is_default, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$name, $subject, $html_body, $is_default, $_SESSION['user_id'] ?? null]);

        $new_id = (int)$pdo->lastInsertId();
        audit_log($pdo, 'create', 'email_template', $new_id, null, ['name' => $name]);
        set_flash('success', 'Template created.');
        redirect(BASE_URL . '/email/templates.php');
    } catch (Throwable $e) {
        error_log('Template create failed: ' . $e->getMessage());
        set_flash('danger', 'Failed to create template.');
        redirect(BASE_URL . '/email/template_create.php');
    }
}

$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);
?>


<div class="tf-page">
    <div class="tf-card">
        <div class="tf-card-header">
            <div>
                <h5 class="tf-card-title">New Email Template</h5>
                <p class="tf-card-subtitle">Create a reusable HTML email template for campaigns.</p>
            </div>
        </div>
        <div class="tf-card-body">
            <form method="POST" class="tf-form" novalidate>
                <?php echo csrf_field(); ?>
                <div class="tf-form-row">
                    <div class="tf-field col-12">
                        <label for="name" class="tf-label">Template Name <span class="tf-required" aria-hidden="true">*</span></label>
                        <input type="text" id="name" name="name" class="form-control" value="<?php echo sanitize($form_data['name'] ?? ''); ?>" required placeholder="e.g. Welcome Series">
                    </div>
                </div>
                <div class="tf-form-row">
                    <div class="tf-field col-12">
                        <label for="subject" class="tf-label">Subject <span class="tf-required" aria-hidden="true">*</span></label>
                        <input type="text" id="subject" name="subject" class="form-control" value="<?php echo sanitize($form_data['subject'] ?? ''); ?>" required placeholder="e.g. Welcome to {{company}}">
                    </div>
                </div>
                <div class="tf-form-row">
                    <div class="tf-field col-12">
                        <label for="html_body" class="tf-label">HTML Body <span class="tf-required" aria-hidden="true">*</span></label>
                        <textarea id="html_body" name="html_body" class="form-control" rows="14" required placeholder="<html>...</html>"><?php echo sanitize($form_data['html_body'] ?? ''); ?></textarea>
                        <p class="tf-help">Supports standard HTML. Example merge fields: <code>{{name}}</code>, <code>{{email}}</code>, <code>{{country}}</code>.</p>
                    </div>
                </div>
                <div class="tf-form-row">
                    <div class="tf-field col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1" <?php echo !empty($form_data['is_default']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_default">Make this the default template</label>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <a href="<?php echo BASE_URL; ?>/email/templates.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Create Template</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
