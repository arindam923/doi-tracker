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

// Get client info for country
$client_stmt = $pdo->prepare("SELECT country FROM clients WHERE id = ?");
$client_stmt->execute([$project['client_id']]);
$client_data = $client_stmt->fetch();
$country = $project['country_target'] ?: ($client_data['country'] ?? 'XX');

$new_code = generate_project_code($pdo, $country);
$new_token = generate_postback_token();

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        INSERT INTO projects (project_code, project_name, client_id, client_survey_link, postback_token,
            client_cpi, vendor_default_cpi, total_quota, country_target, start_date, end_date, description, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $new_code, $project['project_name'] . ' (Copy)', $project['client_id'], $project['client_survey_link'],
        $new_token, $project['client_cpi'], $project['vendor_default_cpi'], $project['total_quota'],
        $project['country_target'], $project['start_date'], $project['end_date'], $project['description'],
        $_SESSION['user_id']
    ]);
    $new_project_id = $pdo->lastInsertId();

    // Clone vendors
    $vstmt = $pdo->prepare("SELECT * FROM vendors WHERE project_id = ?");
    $vstmt->execute([$id]);
    $vendors = $vstmt->fetchAll();

    foreach ($vendors as $v) {
        $pdo->prepare("INSERT INTO vendors (project_id, vendor_name, contact_info, vendor_cpi, postback_url, allowed_clicks_limit, status, notes) VALUES (?, ?, ?, ?, ?, ?, 'active', ?)")
            ->execute([$new_project_id, $v['vendor_name'], $v['contact_info'], $v['vendor_cpi'], $v['postback_url'], $v['allowed_clicks_limit'], $v['notes']]);
    }

    $pdo->prepare("INSERT INTO logs (log_type, project_id, status, message) VALUES (?, ?, ?, ?)")
        ->execute(['status_change', $new_project_id, 'success', 'Project cloned from #' . $id . ' (' . $project['project_code'] . ')']);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('Project clone failed: ' . $e->getMessage());
    set_flash('danger', 'Failed to clone project. Please try again.');
    redirect(BASE_URL . '/projects/list.php');
}

set_flash('success', 'Project cloned successfully. New code: ' . $new_code);
redirect(BASE_URL . '/projects/edit.php?id=' . $new_project_id);
