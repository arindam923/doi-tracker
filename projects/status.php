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

$project_id = intval($_POST['project_id'] ?? 0);
$new_status = $_POST['new_status'] ?? '';

if (!$project_id || !in_array($new_status, ['live', 'hold', 'closed'])) {
    set_flash('danger', 'Invalid request.');
    redirect(BASE_URL . '/projects/list.php');
}

$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) {
    set_flash('danger', 'Project not found.');
    redirect(BASE_URL . '/projects/list.php');
}

$old_status = $project['status'];

// Update project status
$pdo->prepare("UPDATE projects SET status = ? WHERE id = ?")->execute([$new_status, $project_id]);

// Cascade vendor status
if ($new_status === 'hold' || $new_status === 'closed') {
    $vendor_status = $new_status === 'hold' ? 'paused' : 'closed';
    $pdo->prepare("UPDATE vendors SET status = ? WHERE project_id = ? AND status = 'active'")
        ->execute([$vendor_status, $project_id]);
} elseif ($new_status === 'live') {
    // Only resume paused vendors, not intentionally closed ones
    $pdo->prepare("UPDATE vendors SET status = 'active' WHERE project_id = ? AND status = 'paused'")
        ->execute([$project_id]);
}

// Log
$pdo->prepare("INSERT INTO logs (log_type, project_id, status, message) VALUES (?, ?, ?, ?)")
    ->execute(['status_change', $project_id, 'success', "Status changed: {$old_status} → {$new_status}"]);

set_flash('success', 'Project status changed to ' . ucfirst($new_status) . '.');
redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
