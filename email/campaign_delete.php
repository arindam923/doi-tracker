<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/email/campaigns.php');
}

if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Invalid form submission.');
    redirect(BASE_URL . '/email/campaigns.php');
}

$id = intval($_POST['id'] ?? 0);
if ($id) {
    $stmt = $pdo->prepare("SELECT id, name FROM email_campaigns WHERE id = ?");
    $stmt->execute([$id]);
    $campaign = $stmt->fetch();
    if ($campaign) {
        $pdo->prepare("DELETE FROM email_campaign_sends WHERE campaign_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM email_campaigns WHERE id = ?")->execute([$id]);
        audit_log($pdo, 'delete', 'email_campaign', $id, ['name' => $campaign['name']], null);
        set_flash('success', 'Campaign deleted.');
    }
}

redirect(BASE_URL . '/email/campaigns.php');
