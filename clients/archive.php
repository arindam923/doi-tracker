<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (!$id || !in_array($action, ['archive', 'restore'])) {
    redirect(BASE_URL . '/clients/list.php');
}

// CSRF check for POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Invalid form submission.');
        redirect(BASE_URL . '/clients/list.php');
    }
} else {
    // GET requests not allowed for state changes
    set_flash('danger', 'Invalid request method.');
    redirect(BASE_URL . '/clients/list.php');
}

$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) {
    set_flash('danger', 'Client not found.');
    redirect(BASE_URL . '/clients/list.php');
}

if ($action === 'archive') {
    $pdo->prepare("UPDATE clients SET is_active = 0 WHERE id = ?")->execute([$id]);
    set_flash('success', 'Client "' . sanitize($client['client_name']) . '" archived.');
} else {
    $pdo->prepare("UPDATE clients SET is_active = 1 WHERE id = ?")->execute([$id]);
    set_flash('success', 'Client "' . sanitize($client['client_name']) . '" restored.');
}

redirect(BASE_URL . '/clients/list.php');
