<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/email/templates.php');
}

if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Invalid form submission.');
    redirect(BASE_URL . '/email/templates.php');
}

$id = intval($_POST['id'] ?? 0);
if ($id) {
    $stmt = $pdo->prepare("SELECT id, name, is_default FROM email_templates WHERE id = ?");
    $stmt->execute([$id]);
    $tpl = $stmt->fetch();
    if ($tpl && !$tpl['is_default']) {
        $pdo->prepare("DELETE FROM email_templates WHERE id = ?")->execute([$id]);
        audit_log($pdo, 'delete', 'email_template', $id, ['name' => $tpl['name']], null);
        set_flash('success', 'Template deleted.');
    }
}

redirect(BASE_URL . '/email/templates.php');
