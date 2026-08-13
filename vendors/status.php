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
$vendor_id = intval($_POST['vendor_id'] ?? 0);
$action = $_POST['action'] ?? '';

if (!$project_id || !$vendor_id || !in_array($action, ['active', 'hold', 'closed'], true)) {
    redirect(BASE_URL . '/projects/list.php');
}

$stmt = $pdo->prepare("SELECT pv.*, gv.vendor_name FROM project_vendor pv JOIN global_vendors gv ON gv.id = pv.vendor_id WHERE pv.project_id = ? AND pv.vendor_id = ?");
$stmt->execute([$project_id, $vendor_id]);
$pv = $stmt->fetch();
if (!$pv) {
    set_flash('danger', 'Vendor not found on this project.');
    redirect(BASE_URL . '/projects/list.php');
}

$old_status = $pv['status'];
$pdo->prepare("UPDATE project_vendor SET status = ? WHERE project_id = ? AND vendor_id = ?")->execute([$action, $project_id, $vendor_id]);

$pdo->prepare("INSERT INTO logs (log_type, project_id, vendor_id, status, message) VALUES (?, ?, ?, ?, ?)")
    ->execute(['vendor_change', $project_id, $vendor_id, 'success', "Vendor status changed: {$old_status} → {$action}"]);

set_flash('success', 'Vendor status changed to ' . ucfirst($action) . '.');
redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
