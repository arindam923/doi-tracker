<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/projects/list.php');
}

if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Invalid form submission.');
    redirect(BASE_URL . '/projects/list.php');
}

$id = intval($_POST['id'] ?? 0);
$project_id = 0;
if ($id) {
    $stmt = $pdo->prepare('SELECT id, name, project_id FROM email_campaigns WHERE id = ?');
    $stmt->execute([$id]);
    $campaign = $stmt->fetch();
    if ($campaign) {
        $project_id = (int)$campaign['project_id'];
        $pdo->prepare('DELETE FROM email_campaign_sends WHERE campaign_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM email_campaigns WHERE id = ?')->execute([$id]);
        audit_log($pdo, 'delete', 'email_campaign', $id, ['name' => $campaign['name']], null);
        set_flash('success', 'Campaign deleted.');
    }
}

if ($project_id) {
    redirect(BASE_URL . '/projects/detail.php?id=' . $project_id . '#email-campaigns');
}
redirect(BASE_URL . '/projects/list.php');
