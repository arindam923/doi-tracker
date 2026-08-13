<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/email/compose.php');
}

if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('danger', 'Invalid form submission.');
    redirect(BASE_URL . '/email/compose.php');
}

$recipient_mode = $_POST['recipient_mode'] ?? 'selected';
$subject = trim($_POST['subject'] ?? '');
$body = trim($_POST['body'] ?? '');
$client_ids = $_POST['client_ids'] ?? [];
$list_id = intval($_POST['list_id'] ?? 0);

// Resend config is validated at send-time by the cron worker, but we check it
// exists so users get early feedback.
if (empty(get_setting($pdo, 'resend_api_key'))) {
    set_flash('danger', 'Resend API key not configured. Go to Settings to set it up.');
    redirect(BASE_URL . '/email/compose.php');
}

if (empty($subject) || empty($body)) {
    set_flash('danger', 'Subject and message are required.');
    redirect(BASE_URL . '/email/compose.php');
}

// ── Build recipient list ──────────────────────────────────────
$recipients = [];

if ($recipient_mode === 'all') {
    $stmt = $pdo->query("SELECT id, client_name, email FROM clients WHERE is_active = 1 AND email != '' AND email IS NOT NULL");
    $recipients = $stmt->fetchAll();
} elseif ($recipient_mode === 'list') {
    if (!$list_id) {
        set_flash('danger', 'Please pick an email list.');
        redirect(BASE_URL . '/email/compose.php');
    }
    $stmt = $pdo->prepare("SELECT e.email, e.name AS client_name, NULL AS id FROM email_list_entries e WHERE e.list_id = ? AND e.is_unsubscribed = 0 AND e.email != ''");
    $stmt->execute([$list_id]);
    $recipients = $stmt->fetchAll();
} else {
    if (empty($client_ids) || !is_array($client_ids)) {
        set_flash('danger', 'Please select at least one recipient.');
        redirect(BASE_URL . '/email/compose.php');
    }
    $placeholders = implode(',', array_fill(0, count($client_ids), '?'));
    $stmt = $pdo->prepare("SELECT id, client_name, email FROM clients WHERE id IN ($placeholders) AND email != '' AND email IS NOT NULL");
    $stmt->execute($client_ids);
    $recipients = $stmt->fetchAll();
}

if (empty($recipients)) {
    set_flash('danger', 'No valid recipients found.');
    redirect(BASE_URL . '/email/compose.php');
}

// ── Queue for the cron worker ─────────────────────────────────
$batch_id = date('YmdHis') . '-' . bin2hex(random_bytes(4));
$queued = 0;

$insert = $pdo->prepare("
    INSERT INTO sent_emails
        (recipient_email, recipient_name, client_id, subject, body, status, resend_id, error_message, sent_by, batch_id, retry_count, scheduled_for, created_at)
    VALUES (?, ?, ?, ?, ?, 'queued', NULL, NULL, ?, ?, 0, NOW(), NOW())
");
$pdo->beginTransaction();
foreach ($recipients as $recipient) {
    $insert->execute([
        $recipient['email'],
        $recipient['client_name'] ?? null,
        $recipient['id'] ?? null,
        $subject,
        $body,
        $_SESSION['user_id'] ?? null,
        $batch_id,
    ]);
    $queued++;
}
$pdo->commit();

set_flash('success', "Email queued for {$queued} recipient(s). A background worker will deliver them shortly.");
redirect(BASE_URL . '/email/history.php?batch_id=' . $batch_id);
