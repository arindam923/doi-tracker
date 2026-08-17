<?php
define('TF_TRACKING_REQUEST', true);
require_once __DIR__ . '/../../config.php';

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$id = email_send_token_verify($_GET['t'] ?? '');
if ($id) {
    try {
        email_mark_engagement($pdo, $id, 'open');
    } catch (Throwable $e) {
        error_log('email open pixel: ' . $e->getMessage());
    }
}

echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
exit;
