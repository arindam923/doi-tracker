<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/clients/list.php');
}
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Invalid form submission.');
    redirect(BASE_URL . '/clients/list.php');
}

$id = intval($_POST['id'] ?? 0);
$client_id = intval($_POST['client_id'] ?? 0);
if (!$id) redirect(BASE_URL . '/clients/list.php');

$stmt = $pdo->prepare("SELECT * FROM client_documents WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if ($doc) {
    $path = __DIR__ . '/../storage/client_docs/' . $doc['stored_filename'];
    if (is_file($path)) @unlink($path);
    $pdo->prepare("DELETE FROM client_documents WHERE id = ?")->execute([$id]);
    audit_log($pdo, 'delete_document', 'client', $client_id, null, ['filename' => $doc['original_filename']]);
    regenerate_csrf_token();
    set_flash('success', 'Document deleted.');
}

redirect(BASE_URL . '/clients/documents.php?id=' . $client_id);