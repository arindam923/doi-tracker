<?php
define('TF_TRACKING_REQUEST', true);
require_once __DIR__ . '/../../config.php';

$id = email_send_token_verify($_GET['t'] ?? '');
$ok = false;
if ($id) {
    try {
        email_mark_engagement($pdo, $id, 'unsubscribed');
        $ok = true;
    } catch (Throwable $e) {
        error_log('email unsub: ' . $e->getMessage());
    }
}

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Unsubscribe</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem;color:#0f172a}</style>
</head>
<body>
<?php if ($ok): ?>
<h1>You are unsubscribed</h1>
<p>You will no longer receive emails from this list.</p>
<?php else: ?>
<h1>Link invalid</h1>
<p>This unsubscribe link is not valid.</p>
<?php endif; ?>
</body>
</html>
