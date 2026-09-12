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
$action = $_POST['action'] ?? '';
$stmt = $pdo->prepare('SELECT * FROM email_campaigns WHERE id = ?');
$stmt->execute([$id]);
$campaign = $stmt->fetch();
if (!$campaign) {
    set_flash('danger', 'Campaign not found.');
    redirect(BASE_URL . '/projects/list.php');
}

$project_id = (int)$campaign['project_id'];
$redirect = BASE_URL . '/projects/detail.php?id=' . $project_id . '#email-campaigns';
$status = $campaign['status'];
$next = null;

if ($action === 'launch' && in_array($status, ['draft', 'paused', 'scheduled'], true)) {
    $next = 'running';
} elseif ($action === 'pause' && $status === 'running') {
    $next = 'paused';
} elseif ($action === 'resume' && $status === 'paused') {
    $next = 'running';
} elseif ($action === 'launch' && $status === 'completed') {
    if (empty($campaign['is_multi_step'])) {
        set_flash('danger', 'Completed campaigns cannot be relaunched. Create a new campaign.');
        redirect($redirect);
    }
    $next = 'running';
} elseif ($action === 'resend' && in_array($status, ['completed','running','paused'], true)) {
    if (empty($campaign['is_multi_step'])) {
        set_flash('danger', 'Only multi-step campaigns allow manual resend.');
        redirect($redirect);
    }
    $next = 'running';
}

if (!$next) {
    set_flash('danger', 'That status change is not allowed.');
    redirect($redirect);
}

$pdo->prepare("UPDATE email_campaigns SET status = ?, started_at = COALESCE(started_at, IF(? = 'running', NOW(), started_at)), completed_at = NULL, updated_at = NOW() WHERE id = ?")
    ->execute([$next, $next, $id]);
audit_log($pdo, $action, 'email_campaign', $id, ['status' => $status], ['status' => $next]);
$labels = ['launch' => 'Campaign launched.', 'pause' => 'Campaign paused.', 'resume' => 'Campaign resumed.'];
set_flash('success', $labels[$action] ?? 'Campaign updated.');
redirect($redirect);
