<?php
/**
 * Track Flow — Opaque short-link resolver.
 * /c/{code} and /go/{code} resolve internally and never expose project/vendor IDs.
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';

$code = trim($_GET['c'] ?? '');
$row = tf_resolve_active_short_link($pdo, $code);
if (!$row) {
    http_response_code(404);
    die('Tracking link not found.');
}

if (!defined('TF_INTERNAL_CLICK')) {
    define('TF_INTERNAL_CLICK', true);
}
$tf_click_project_id = (int)$row['project_id'];
$tf_click_vendor_id = (int)$row['vendor_id'];

require __DIR__ . '/click.php';
