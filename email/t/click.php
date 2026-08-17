<?php
define('TF_TRACKING_REQUEST', true);
require_once __DIR__ . '/../../config.php';

$id = email_send_token_verify($_GET['t'] ?? '');
$dest = $_GET['u'] ?? '';
if (!is_string($dest) || !preg_match('/^https?:\/\//i', $dest)) {
    http_response_code(400);
    exit('Invalid link.');
}

if ($id) {
    try {
        email_mark_engagement($pdo, $id, 'click');
        $dest = email_append_sid($dest, $id);
    } catch (Throwable $e) {
        error_log('email click: ' . $e->getMessage());
    }
}

header('Location: ' . $dest, true, 302);
exit;
