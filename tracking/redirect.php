<?php
/**
 * Track Flow — Short-link redirector (Phase 4)
 * Resolves /c/CODE or /go/CODE without exposing internal IDs.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';

$code = trim($_GET['c'] ?? '');
if (!$code || !preg_match('/^[A-Za-z0-9_-]{4,32}$/', $code)) {
    http_response_code(400);
    die('Invalid tracking code.');
}

$stmt = $pdo->prepare("
    SELECT sl.project_id, sl.vendor_id
    FROM short_links sl
    WHERE sl.code = ?
    LIMIT 1
");
$stmt->execute([$code]);
$row = $stmt->fetch();

if (!$row || empty($row['project_id']) || empty($row['vendor_id'])) {
    http_response_code(404);
    die('Tracking link not found.');
}

// Pass resolved IDs through PHP only; never expose them in a browser redirect.
define('TF_INTERNAL_CLICK_REQUEST', true);
$_GET['project_id'] = (int)$row['project_id'];
$_GET['vendor_id'] = (int)$row['vendor_id'];
require __DIR__ . '/click.php';
