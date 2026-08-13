<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/convlogs/list.php');
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Invalid form submission.');
    redirect(BASE_URL . '/convlogs/list.php');
}

$id = intval($_POST['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/convlogs/list.php');

$pdo->prepare("UPDATE conversions SET approval_status = 'rejected' WHERE id = ?")->execute([$id]);
audit_log($pdo, 'reject_conversion', 'conversion', $id);

regenerate_csrf_token();
set_flash('success', 'Conversion rejected.');
redirect($_POST['redirect'] ?? BASE_URL . '/convlogs/list.php');