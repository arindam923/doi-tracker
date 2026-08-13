<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/projects/list.php');
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Invalid form submission.');
    redirect(BASE_URL . '/projects/list.php');
}

$id = intval($_POST['id'] ?? 0);
$project_id = intval($_POST['project_id'] ?? 0);
if (!$id || !$project_id) redirect(BASE_URL . '/projects/list.php');

$pdo->prepare("DELETE FROM campaign_notes WHERE id = ? AND project_id = ?")->execute([$id, $project_id]);
audit_log($pdo, 'delete_note', 'project', $project_id, null, ['note_id' => $id]);

regenerate_csrf_token();
set_flash('success', 'Note deleted.');
redirect(BASE_URL . '/projects/detail.php?id=' . $project_id . '#notes');