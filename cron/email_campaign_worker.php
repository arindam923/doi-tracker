<?php
/**
 * TRACK FLOW — Email Campaign Worker (Item #11)
 * Processes due email campaigns: selects recipients by GEO, skips bad statuses,
 * respects daily/total limits, sends via Resend, and updates live counts.
 *
 * Suggested cron: every 1-5 minutes
 *   * * * * * cd /path/to/doi-tracker && php cron/email_campaign_worker.php >> storage/logs/email_campaign_worker.log 2>&1
 */

declare(ticks=1);
require_once __DIR__ . '/../config.php';

$log_dir = __DIR__ . '/../storage/logs';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);

function campaign_log($msg) {
    $line = '[' . date('c') . '] ' . $msg . PHP_EOL;
    $log_dir = __DIR__ . '/../storage/logs';
    if (is_dir($log_dir) && is_writable($log_dir)) {
        @file_put_contents($log_dir . '/email_campaign_worker.log', $line, FILE_APPEND | LOCK_EX);
    }
    error_log('email_campaign_worker: ' . $msg);
}

$resend_api_key = get_setting($pdo, 'resend_api_key');
$from_address   = get_setting($pdo, 'email_from_address', 'noreply@yourdomain.com');
$from_name      = get_setting($pdo, 'email_from_name', 'Track Flow');

if (empty($resend_api_key)) {
    campaign_log('no resend_api_key configured; aborting');
    exit(0);
}

function send_via_resend($api_key, $from_address, $from_name, $to_email, $to_name, $subject, $html) {
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json',
    ]);
    $payload = [
        'from' => ($from_name ? $from_name . ' ' : '') . '<' . $from_address . '>',
        'to' => [$to_email],
        'subject' => $subject,
        'html' => $html,
    ];
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    return [$http_code, $response, $curl_error];
}

$pdo->beginTransaction();

try {
    $campaign = $pdo->query("
        SELECT ec.*, p.project_code, p.project_name, p.country_target
        FROM email_campaigns ec
        JOIN projects p ON ec.project_id = p.id
        WHERE ec.status = 'running'
        ORDER BY ec.created_at ASC
        LIMIT 1
        FOR UPDATE SKIP LOCKED
    ")->fetch();

    if (!$campaign) {
        $pdo->commit();
        campaign_log('no due campaign found');
        exit(0);
    }

    $campaign_id = (int)$campaign['id'];

    $today_count = (int)$pdo->prepare("SELECT COUNT(*) FROM email_campaign_sends WHERE campaign_id = ? AND DATE(created_at) = CURDATE() AND status IN ('sent','delivered','opened','clicked','converted','bounced','failed')")->execute([$campaign_id]) && $pdo->prepare("SELECT COUNT(*) FROM email_campaign_sends WHERE campaign_id = ? AND DATE(created_at) = CURDATE() AND status IN ('sent','delivered','opened','clicked','converted','bounced','failed')")->fetchColumn();

    $total_sent = (int)$pdo->prepare("SELECT COUNT(*) FROM email_campaign_sends WHERE campaign_id = ? AND status IN ('sent','delivered','opened','clicked','converted','bounced','failed')")->execute([$campaign_id]) && $pdo->prepare("SELECT COUNT(*) FROM email_campaign_sends WHERE campaign_id = ? AND status IN ('sent','delivered','opened','clicked','converted','bounced','failed')")->fetchColumn();

    if (($campaign['daily_limit'] > 0 && $today_count >= $campaign['daily_limit']) || ($campaign['total_limit'] > 0 && $total_sent >= $campaign['total_limit'])) {
        $pdo->prepare("UPDATE email_campaigns SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$campaign_id]);
        $pdo->commit();
        campaign_log('campaign ' . $campaign_id . ' completed by limits');
        exit(0);
    }

    $batch_size = 50;
    $today_remaining = $campaign['daily_limit'] > 0 ? max(0, $campaign['daily_limit'] - $today_count) : $batch_size;
    $total_remaining = $campaign['total_limit'] > 0 ? max(0, $campaign['total_limit'] - $total_sent) : $batch_size;
    $batch_size = (int)min($batch_size, $today_remaining, $total_remaining);
    if ($batch_size <= 0) {
        $pdo->prepare("UPDATE email_campaigns SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$campaign_id]);
        $pdo->commit();
        campaign_log('campaign ' . $campaign_id . ' no remaining quota');
        exit(0);
    }

    $vendor_id = (int)$campaign['vendor_id'];
    $project_id = (int)$campaign['project_id'];

    $allowed_countries = [];
    $geo_rows = $pdo->prepare("SELECT country_code FROM campaign_geo WHERE project_id = ?");
    $geo_rows->execute([$project_id]);
    foreach ($geo_rows->fetchAll() as $g) {
        $allowed_countries[] = strtoupper(substr($g['country_code'], 0, 2));
    }

    $exclude_statuses = ["'unsubscribed'", "'bounced'", "'invalid'"];
    $existing_sql = "SELECT recipient_email FROM email_campaign_sends WHERE campaign_id = " . (int)$campaign_id . " AND status NOT IN ('queued','skipped')";
    $existing = $pdo->query($existing_sql)->fetchAll(PDO::FETCH_COLUMN);
    $existing_map = array_flip(array_map('strtolower', $existing));

    $sql = "SELECT ele.id, ele.email, ele.name, ele.country FROM email_list_entries ele
            JOIN email_lists el ON el.id = ele.list_id
            WHERE (el.vendor_id = ? OR el.project_id = ?)
              AND ele.is_unsubscribed = 0
              AND ele.status NOT IN (" . implode(',', $exclude_statuses) . ")
              AND LOWER(ele.email) NOT IN (" . implode(',', array_fill(0, count($existing_map), '?')) . ")";
    $params = [$vendor_id, $project_id];
    $params = array_merge($params, array_keys($existing_map));
    $sql .= " LIMIT " . (int)$batch_size;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recipients = $stmt->fetchAll();

    if (empty($recipients)) {
        $pdo->prepare("UPDATE email_campaigns SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$campaign_id]);
        $pdo->commit();
        campaign_log('campaign ' . $campaign_id . ' no eligible recipients');
        exit(0);
    }

    $subject = $campaign['subject'];
    $html_body = $campaign['html_body'];
    $from_name_use = $campaign['from_name'] ?: $from_name;
    $from_email_use = $campaign['from_email'] ?: $from_address;
    $sent = 0;

    foreach ($recipients as $row) {
        $country = strtoupper(substr($row['country'] ?? '', 0, 2));
        if (!empty($allowed_countries) && !in_array($country, $allowed_countries, true)) {
            continue;
        }

        $personalized_html = str_replace(
            ['{{name}}', '{{email}}', '{{country}}'],
            [$row['name'] ?? '', $row['email'], $country ?: ''],
            $html_body
        );
        $personalized_subject = str_replace(
            ['{{name}}', '{{email}}', '{{country}}'],
            [$row['name'] ?? '', $row['email'], $country ?: ''],
            $subject
        );

        [$http_code, $response, $curl_error] = send_via_resend(
            $resend_api_key,
            $from_email_use,
            $from_name_use,
            $row['email'],
            $row['name'] ?? '',
            $personalized_subject,
            $personalized_html
        );

        $status = 'failed';
        $resend_id = null;
        $error_message = null;

        if ($http_code === 200 || $http_code === 202) {
            $decoded = json_decode($response, true);
            $resend_id = $decoded['id'] ?? null;
            if ($resend_id) {
                $status = 'sent';
            } else {
                $status = 'failed';
                $error_message = 'missing_resend_id';
            }
        } else {
            $error_message = 'http_' . $http_code . ($curl_error ? ': ' . $curl_error : '');
        }

        $insert = $pdo->prepare("
            INSERT INTO email_campaign_sends (campaign_id, project_id, vendor_id, list_id, entry_id, recipient_email, recipient_name, country, status, resend_id, error_message, sent_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE status = VALUES(status), resend_id = VALUES(resend_id), error_message = VALUES(error_message), sent_at = VALUES(sent_at)
        ");
        $insert->execute([
            $campaign_id,
            $project_id,
            $vendor_id,
           0,
            (int)$row['id'],
            $row['email'],
            $row['name'] ?? null,
            $country ?: null,
            $status,
            $resend_id,
            $error_message,
            $status === 'sent' ? date('Y-m-d H:i:s') : null,
        ]);

        $sent++;
    }

    if ($sent > 0) {
        $pdo->prepare("UPDATE email_campaigns SET sent_count = sent_count + ?, status = 'running', started_at = COALESCE(started_at, NOW()), updated_at = NOW() WHERE id = ?")->execute([$sent, $campaign_id]);
    }

    $pdo->commit();
    campaign_log('campaign ' . $campaign_id . ' sent=' . $sent . ' checked=' . count($recipients));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    campaign_log('campaign worker error: ' . $e->getMessage());
}
