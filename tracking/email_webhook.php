<?php
/**
 * Resend event webhook for bounce / delivered.
 * Configure in Resend: POST to {BASE_URL}/tracking/email_webhook.php
 */
define('TF_TRACKING_REQUEST', true);
require_once __DIR__ . '/config.php';

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit('invalid');
}

$type = (string)($payload['type'] ?? '');
$data = $payload['data'] ?? [];
$resend_id = $data['email_id'] ?? ($data['id'] ?? null);

if (!$resend_id) {
    http_response_code(200);
    exit('ok');
}

$send = tf_fetch_one($pdo, 'SELECT id FROM email_campaign_sends WHERE resend_id = ?', [$resend_id]);
if (!$send) {
    http_response_code(200);
    exit('ok');
}

try {
    if (stripos($type, 'bounce') !== false || $type === 'email.bounced' || $type === 'email.failed') {
        email_mark_engagement($pdo, (int)$send['id'], 'bounced');
    } elseif (stripos($type, 'delivered') !== false || $type === 'email.delivered') {
        email_mark_engagement($pdo, (int)$send['id'], 'delivered');
    }
} catch (Throwable $e) {
    error_log('email webhook: ' . $e->getMessage());
}

http_response_code(200);
exit('ok');
