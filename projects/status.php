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

function json_or_redirect($is_ajax, $success, $message, $redirect_url = null, $extra = []) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
        exit;
    }
    set_flash($success ? 'success' : 'danger', $message);
    redirect($redirect_url ?: BASE_URL . '/projects/list.php');
}

$project_id = intval($_POST['project_id'] ?? 0);
$campaign_status = tf_campaign_status_for_request($_POST['new_status'] ?? '');

if (!$project_id || $campaign_status === null) {
    json_or_redirect($is_ajax, false, 'Invalid request.', BASE_URL . '/projects/list.php');
}

$new_status = tf_operational_status_for_campaign($campaign_status);

$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) {
    json_or_redirect($is_ajax, false, 'Project not found.', BASE_URL . '/projects/list.php');
}

$old_status = $project['status'];

// Persist the client-facing lifecycle and its corresponding traffic state.
$pdo->prepare("UPDATE projects SET status = ?, campaign_status = ? WHERE id = ?")
    ->execute([$new_status, $campaign_status, $project_id]);

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
    ->execute(['status_change', $project_id, 'success', "Campaign status changed: {$project['campaign_status']} → {$campaign_status}; traffic state: {$old_status} → {$new_status}"]);

audit_log($pdo, 'status_change', 'project', $project_id,
    ['status' => $old_status, 'campaign_status' => $project['campaign_status']],
    ['status' => $new_status, 'campaign_status' => $campaign_status]);

if ($is_ajax) {
    $campaign_label = tf_campaign_status()[$campaign_status] ?? $campaign_status;
    json_or_redirect(true, true, 'Campaign status changed to ' . $campaign_label . '.', null, [
        'campaign_status' => $campaign_status,
        'campaign_status_label' => $campaign_label,
        'status' => $new_status,
        'status_html' => status_badge($new_status),
    ]);
}

set_flash('success', 'Campaign status changed to ' . (tf_campaign_status()[$campaign_status] ?? $campaign_status) . '.');
redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
