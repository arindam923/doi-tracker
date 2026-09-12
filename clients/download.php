<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    die('Missing document id.');
}

$stmt = $pdo->prepare("SELECT * FROM client_documents WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die('Document not found.');
}

if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,4}$/', $doc['stored_filename'])) {
    http_response_code(400);
    die('Invalid file.');
}
$path = __DIR__ . '/../storage/client_docs/' . $doc['stored_filename'];
if (!is_file($path)) {
    http_response_code(404);
    die('File missing on disk.');
}
$real = realpath($path);
$base = realpath(__DIR__ . '/../storage/client_docs');
if ($real === false || $base === false || strpos($real, $base) !== 0) {
    http_response_code(400);
    die('Invalid file path.');
}

// Best-effort MIME detection
$ext = strtolower(pathinfo($doc['stored_filename'], PATHINFO_EXTENSION));
$mime = match ($ext) {
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'csv'  => 'text/csv',
    'txt'  => 'text/plain',
    'png'  => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'zip'  => 'application/zip',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . rawurlencode($doc['original_filename']) . '"; filename*=UTF-8\'\'' . rawurlencode($doc['original_filename']));
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;