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

// Resend config
$resend_api_key = get_setting($pdo, 'resend_api_key');
$from_address = get_setting($pdo, 'email_from_address', 'noreply@yourdomain.com');
$from_name = get_setting($pdo, 'email_from_name', 'Ternfluenzy');

if (empty($resend_api_key)) {
    set_flash('danger', 'Resend API key not configured. Go to Settings to set it up.');
    redirect(BASE_URL . '/email/compose.php');
}

if (empty($subject) || empty($body)) {
    set_flash('danger', 'Subject and message are required.');
    redirect(BASE_URL . '/email/compose.php');
}

// Build recipient list
$recipients = [];

if ($recipient_mode === 'all') {
    $stmt = $pdo->query("SELECT id, client_name, email FROM clients WHERE is_active = 1 AND email != '' AND email IS NOT NULL");
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

// Send via Resend API
$sent_count = 0;
$error_count = 0;

foreach ($recipients as $recipient) {
    $email_data = [
        'from' => $from_name . ' <' . $from_address . '>',
        'to' => [$recipient['email']],
        'subject' => $subject,
        'text' => $body,
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $resend_api_key,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($email_data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $resend_id = null;
    $status = 'sent';
    $error_message = null;

    if ($http_code === 200) {
        $result = json_decode($response, true);
        $resend_id = $result['id'] ?? null;
        $sent_count++;
    } else {
        $status = 'failed';
        $error_message = $curl_error ?: 'HTTP ' . $http_code . ': ' . $response;
        $error_count++;
    }

    // Log to database
    $log_stmt = $pdo->prepare("INSERT INTO sent_emails (recipient_email, recipient_name, client_id, subject, body, status, resend_id, error_message, sent_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $log_stmt->execute([
        $recipient['email'],
        $recipient['client_name'],
        $recipient['id'],
        $subject,
        $body,
        $status,
        $resend_id,
        $error_message,
        $_SESSION['user_id'],
    ]);
}

$total = count($recipients);
$message = "Email sent to {$sent_count}/{$total} recipients.";
if ($error_count > 0) {
    $message .= " {$error_count} failed. Check email history for details.";
    set_flash('warning', $message);
} else {
    set_flash('success', $message);
}

redirect(BASE_URL . '/email/history.php');
