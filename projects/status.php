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

// AJAX detection: requests with X-Requested-With: XMLHttpRequest get JSON back, otherwise redirect.
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

function json_or_redirect($is_ajax, $success, $message, $redirect_url = null) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => $success, 'message' => $message]);
        exit;
    }
    set_flash($success ? 'success' : 'danger', $message);
    redirect($redirect_url ?: BASE_URL . '/projects/list.php');
}

$project_id = intval($_POST['project_id'] ?? 0);
$new_status = $_POST['new_status'] ?? '';

if (!$project_id || !in_array($new_status, ['live', 'hold', 'closed', 'archived'], true)) {
    json_or_redirect($is_ajax, false, 'Invalid request.', BASE_URL . '/projects/list.php');
}

$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) {
    json_or_redirect($is_ajax, false, 'Project not found.', BASE_URL . '/projects/list.php');
}

$old_status = $project['status'];

// Update project status + keep campaign_status in sync
$pdo->prepare("UPDATE projects SET status = ?, campaign_status = ? WHERE id = ?")
    ->execute([$new_status, $new_status, $project_id]);

// Cascade vendor status (don't touch vendors on 'archived' — preserve their state)
if ($new_status === 'hold' || $new_status === 'closed') {
    $vendor_status = $new_status === 'hold' ? 'hold' : 'closed';
    $pdo->prepare("UPDATE project_vendor SET status = ? WHERE project_id = ? AND status = 'active'")
        ->execute([$vendor_status, $project_id]);
} elseif ($new_status === 'live') {
    // Only resume held vendors, not intentionally closed ones
    $pdo->prepare("UPDATE project_vendor SET status = 'active' WHERE project_id = ? AND status = 'hold'")
        ->execute([$project_id]);
}

// Log
$pdo->prepare("INSERT INTO logs (log_type, project_id, status, message) VALUES (?, ?, ?, ?)")
    ->execute(['status_change', $project_id, 'success', "Status changed: {$old_status} → {$new_status}"]);

if ($is_ajax) {
    json_or_redirect(true, true, 'Status changed to ' . ucfirst($new_status) . '.', null);
}

set_flash('success', 'Project status changed to ' . ucfirst($new_status) . '.');
redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
