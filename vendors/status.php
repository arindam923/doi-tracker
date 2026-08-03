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

if (!$id || !in_array($action, ['active', 'paused', 'closed'])) {
    redirect(BASE_URL . '/projects/list.php');
}

$stmt = $pdo->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->execute([$id]);
$vendor = $stmt->fetch();
if (!$vendor) {
    set_flash('danger', 'Vendor not found.');
    redirect(BASE_URL . '/projects/list.php');
}

$old_status = $vendor['status'];
$pdo->prepare("UPDATE vendors SET status = ? WHERE id = ?")->execute([$action, $id]);

$pdo->prepare("INSERT INTO logs (log_type, project_id, vendor_id, status, message) VALUES (?, ?, ?, ?, ?)")
    ->execute(['vendor_change', $vendor['project_id'], $id, 'success', "Vendor status changed: {$old_status} → {$action}"]);

set_flash('success', 'Vendor status changed to ' . ucfirst($action) . '.');
redirect(BASE_URL . '/vendors/list.php?project_id=' . $vendor['project_id']);
