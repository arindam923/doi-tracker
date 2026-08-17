<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$id = intval($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/email/templates.php');

$stmt = $pdo->prepare("SELECT * FROM email_templates WHERE id = ?");
$stmt->execute([$id]);
$tpl = $stmt->fetch();
if (!$tpl) {
    set_flash('danger', 'Template not found.');
    redirect(BASE_URL . '/email/templates.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/email/template_edit.php?id=' . $id);
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
        redirect(BASE_URL . '/email/template_edit.php?id=' . $id);
    }

    try {
        if ($is_default) {
            $pdo->prepare("UPDATE email_templates SET is_default = 0")->execute();
        }

        $stmt = $pdo->prepare("UPDATE email_templates SET name=?, subject=?, html_body=?, is_default=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$name, $subject, $html_body, $is_default, $id]);

        audit_log($pdo, 'update', 'email_template', $id, null, ['name' => $name]);
        set_flash('success', 'Template updated.');
        redirect(BASE_URL . '/email/templates.php');
    } catch (Throwable $e) {
        error_log('Template update failed: ' . $e->getMessage());
        set_flash('danger', 'Failed to update template.');
        redirect(BASE_URL . '/email/template_edit.php?id=' . $id);
    }
}

$page_title = 'Edit Template';
require_once __DIR__ . '/../helpers/layout_header.php';
?>


<div class="tf-page">
    <div class="tf-page-header">
        <div>
            <h1>Edit template</h1>
            <p>Update HTML and default status. Preview is on the right.</p>
        </div>
    </div>
    <form method="POST" class="tf-form" novalidate>
        <?php echo csrf_field(); ?>
        <div class="tf-tpl-editor">
            <div class="tf-card">
                <div class="tf-card-body">
                    <div class="tf-form-row">
                        <div class="tf-field col-12">
                            <label for="name" class="tf-label">Name <span class="tf-required" aria-hidden="true">*</span></label>
                            <input type="text" id="name" name="name" class="form-control" value="<?php echo sanitize($tpl['name']); ?>" required>
                        </div>
                    </div>
                    <div class="tf-form-row">
                        <div class="tf-field col-12">
                            <label for="subject" class="tf-label">Subject <span class="tf-required" aria-hidden="true">*</span></label>
                            <input type="text" id="subject" name="subject" class="form-control" value="<?php echo sanitize($tpl['subject']); ?>" required>
                        </div>
                    </div>
                    <div class="tf-form-row">
                        <div class="tf-field col-12">
                            <label for="html_body" class="tf-label">HTML body <span class="tf-required" aria-hidden="true">*</span></label>
                            <textarea id="html_body" name="html_body" class="form-control" rows="18" required placeholder="<html>...</html>"><?php echo sanitize($tpl['html_body']); ?></textarea>
                            <p class="tf-help">Merge fields: <code>{{name}}</code>, <code>{{first_name}}</code>, <code>{{email}}</code>, <code>{{country}}</code>, <code>{{unsubscribe}}</code>.</p>
                        </div>
                    </div>
                    <div class="tf-form-row">
                        <div class="tf-field col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1" <?php echo $tpl['is_default'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_default">Make this the default template</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <a href="<?php echo BASE_URL; ?>/email/templates.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save template</button>
                    </div>
                </div>
            </div>
            <div class="tf-tpl-live">
                <div class="tf-card">
                    <div class="tf-card-header">
                        <h5 class="tf-card-title">Live preview</h5>
                    </div>
                    <div class="tf-card-body">
                        <iframe id="tplPreview" title="Template preview" sandbox=""></iframe>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
<script>
(function () {
    const source = document.getElementById('html_body');
    const frame = document.getElementById('tplPreview');
    function paint() {
        frame.srcdoc = source.value || '<p style="font-family:sans-serif;color:#78716c;padding:2rem;">Start typing HTML to preview.</p>';
    }
    source.addEventListener('input', paint);
    paint();
})();
</script>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
