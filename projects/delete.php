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
if (!$id) redirect(BASE_URL . '/projects/list.php');

$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$id]);
$project = $stmt->fetch();
if (!$project) {
    set_flash('danger', 'Project not found.');
    redirect(BASE_URL . '/projects/list.php');
}

// Delete related records first (due to foreign keys)
$pdo->prepare("DELETE FROM logs WHERE project_id = ?")->execute([$id]);
$pdo->prepare("DELETE FROM conversions WHERE project_id = ?")->execute([$id]);
$pdo->prepare("DELETE FROM clicks WHERE project_id = ?")->execute([$id]);
$pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$id]);

set_flash('success', 'Project "' . sanitize($project['project_name']) . '" deleted.');
redirect(BASE_URL . '/projects/list.php');
