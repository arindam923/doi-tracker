<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/projects/list.php');
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Invalid form submission.');
    redirect(BASE_URL . '/projects/list.php');
}

$project_id = intval($_POST['project_id'] ?? 0);
$body = trim($_POST['body'] ?? '');

if (!$project_id || empty($body)) {
    set_flash('warning', 'Note body is required.');
    redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
}

$pdo->prepare("INSERT INTO campaign_notes (project_id, body, created_by) VALUES (?, ?, ?)")
    ->execute([$project_id, $body, $_SESSION['user_id'] ?? null]);

audit_log($pdo, 'add_note', 'project', $project_id, null, ['preview' => substr($body, 0, 80)]);

regenerate_csrf_token();
set_flash('success', 'Note added.');
redirect(BASE_URL . '/projects/detail.php?id=' . $project_id . '#notes');