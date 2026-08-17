<?php
/**
 * TRACK FLOW — Email Campaign Worker
 *
 * Selects recipients from the vendor's canonical email database by GEO,
 * skips unsubscribed/bounced/invalid, respects daily/total limits, and
 * resumes the next day without completing the campaign on daily cap.
 *
 *   * * * * * cd /path/to/doi-tracker && php cron/email_campaign_worker.php >> storage/logs/email_campaign_worker.log 2>&1
 */

declare(ticks=1);
require_once __DIR__ . '/../config.php';

$log_dir = __DIR__ . '/../storage/logs';
if (!is_dir($log_dir)) {
    @mkdir($log_dir, 0755, true);
}

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

[$rate_per_minute, $tokens, $now] = email_rate_tokens_load($pdo);

try {
    $pdo->beginTransaction();
    $campaign = $pdo->query("
        SELECT ec.*, p.project_code, p.project_name
        FROM email_campaigns ec
        JOIN projects p ON ec.project_id = p.id
        WHERE ec.status = 'running'
        ORDER BY ec.created_at ASC
        LIMIT 1
        FOR UPDATE SKIP LOCKED
    ")->fetch();

    if (!$campaign) {
        $pdo->commit();
        campaign_log('no running campaign found');
        email_rate_tokens_save($pdo, $tokens, $now);
        exit(0);
    }

    $campaign_id = (int)$campaign['id'];
    $vendor_id = (int)$campaign['vendor_id'];
    $project_id = (int)$campaign['project_id'];
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    campaign_log('lock error: ' . $e->getMessage());
    email_rate_tokens_save($pdo, $tokens, $now);
    exit(1);
}

$today_count = email_count_scalar(
    $pdo,
    "SELECT COUNT(*) FROM email_campaign_sends
     WHERE campaign_id = ?
       AND DATE(COALESCE(sent_at, created_at)) = CURDATE()
       AND status IN ('sent','delivered','opened','clicked','converted','bounced','failed')",
    [$campaign_id]
);

$total_sent = email_count_scalar(
    $pdo,
    "SELECT COUNT(*) FROM email_campaign_sends
     WHERE campaign_id = ?
       AND status IN ('sent','delivered','opened','clicked','converted','bounced','failed')",
    [$campaign_id]
);

$daily_limit = (int)$campaign['daily_limit'];
$total_limit = (int)$campaign['total_limit'];

if ($total_limit > 0 && $total_sent >= $total_limit) {
    $pdo->prepare("UPDATE email_campaigns SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$campaign_id]);
    campaign_log('campaign ' . $campaign_id . ' completed by total_limit');
    email_rate_tokens_save($pdo, $tokens, $now);
    exit(0);
}

if ($daily_limit > 0 && $today_count >= $daily_limit) {
    campaign_log('campaign ' . $campaign_id . ' daily limit reached (' . $today_count . '/' . $daily_limit . '); waiting until tomorrow');
    email_rate_tokens_save($pdo, $tokens, $now);
    exit(0);
}

$batch_size = 50;
$today_remaining = $daily_limit > 0 ? max(0, $daily_limit - $today_count) : $batch_size;
$total_remaining = $total_limit > 0 ? max(0, $total_limit - $total_sent) : $batch_size;
$batch_size = (int)min($batch_size, $today_remaining, $total_remaining, max(0, (int)floor($tokens)));
if ($batch_size <= 0) {
    campaign_log('campaign ' . $campaign_id . ' no remaining quota or rate tokens');
    email_rate_tokens_save($pdo, $tokens, $now);
    exit(0);
}

$geos = [];
$geo_rows = $pdo->prepare('SELECT country_code FROM campaign_geo WHERE project_id = ?');
$geo_rows->execute([$project_id]);
foreach ($geo_rows->fetchAll() as $g) {
    $code = strtoupper(substr($g['country_code'], 0, 2));
    if ($code !== '') {
        $geos[] = $code;
    }
}

if (!$geos) {
    $pdo->prepare("UPDATE email_campaigns SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$campaign_id]);
    campaign_log('campaign ' . $campaign_id . ' no campaign GEO configured; not sending globally');
    email_rate_tokens_save($pdo, $tokens, $now);
    exit(0);
}

$list_id = ensure_vendor_email_list($pdo, $vendor_id);
$ph = implode(',', array_fill(0, count($geos), '?'));
$sql = "SELECT ele.id, ele.email, ele.name, ele.first_name, ele.last_name, ele.country, ele.list_id
        FROM email_list_entries ele
        JOIN email_lists el ON el.id = ele.list_id
        WHERE el.vendor_id = ?
          AND ele.status = 'active'
          AND ele.is_unsubscribed = 0
          AND UPPER(ele.country) IN ($ph)
          AND NOT EXISTS (
              SELECT 1 FROM email_campaign_sends s
              WHERE s.campaign_id = ? AND LOWER(s.recipient_email) = LOWER(ele.email)
          )
        ORDER BY ele.id ASC
        LIMIT " . (int)$batch_size;
$params = array_merge([$vendor_id], $geos, [$campaign_id]);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recipients = $stmt->fetchAll();

if (empty($recipients)) {
    $pdo->prepare("UPDATE email_campaigns SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$campaign_id]);
    campaign_log('campaign ' . $campaign_id . ' no eligible recipients remaining');
    email_rate_tokens_save($pdo, $tokens, $now);
    exit(0);
}

$from_name_use = $campaign['from_name'] ?: $from_name;
$from_email_use = $campaign['from_email'] ?: $from_address;
$sent = 0;
$failed = 0;

foreach ($recipients as $row) {
    if ($tokens < 1) {
        campaign_log('rate limit reached; pausing until next tick');
        break;
    }

    $insert = $pdo->prepare("
        INSERT INTO email_campaign_sends
            (campaign_id, project_id, vendor_id, list_id, entry_id, recipient_email, recipient_name, country, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'queued', NOW())
    ");
    try {
        $name = $row['name'] ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $insert->execute([
            $campaign_id,
            $project_id,
            $vendor_id,
            $row['list_id'] ?: $list_id,
            (int)$row['id'],
            $row['email'],
            $name !== '' ? $name : null,
            strtoupper(substr((string)$row['country'], 0, 2)) ?: null,
        ]);
    } catch (PDOException $e) {
        continue;
    }
    $send_id = (int)$pdo->lastInsertId();
    if ($send_id < 1) {
        continue;
    }

    $personalized_html = email_personalize($campaign['html_body'], $row);
    $personalized_html = email_inject_tracking($personalized_html, $send_id);
    $personalized_subject = email_personalize($campaign['subject'], $row);

    [$http_code, $response, $curl_error] = email_send_via_resend(
        $resend_api_key,
        $from_email_use,
        $from_name_use,
        $row['email'],
        $personalized_subject,
        $personalized_html
    );

    $tokens--;

    if ($http_code === 429) {
        $pdo->prepare("DELETE FROM email_campaign_sends WHERE id = ? AND status = 'queued'")->execute([$send_id]);
        campaign_log('Resend 429; pausing');
        break;
    }

    $status = 'failed';
    $resend_id = null;
    $error_message = null;
    if ($http_code === 200 || $http_code === 202) {
        $decoded = json_decode($response, true);
        $resend_id = $decoded['id'] ?? null;
        if ($resend_id) {
            $status = 'sent';
        } else {
            $error_message = 'missing_resend_id';
        }
    } else {
        $error_message = 'http_' . $http_code . ($curl_error ? ': ' . $curl_error : '');
    }

    $pdo->prepare("
        UPDATE email_campaign_sends
        SET status = ?, resend_id = ?, error_message = ?, sent_at = ?
        WHERE id = ?
    ")->execute([
        $status,
        $resend_id,
        $error_message,
        $status === 'sent' ? date('Y-m-d H:i:s') : null,
        $send_id,
    ]);

    if ($status === 'sent') {
        $sent++;
        $pdo->prepare("UPDATE email_list_entries SET last_emailed_at = NOW(), total_emails_sent = total_emails_sent + 1 WHERE id = ?")
            ->execute([(int)$row['id']]);
        $pdo->prepare("UPDATE email_campaigns SET sent_count = sent_count + 1, started_at = COALESCE(started_at, NOW()), updated_at = NOW() WHERE id = ?")
            ->execute([$campaign_id]);
    } else {
        $failed++;
        $pdo->prepare("UPDATE email_campaigns SET failed_count = failed_count + 1, updated_at = NOW() WHERE id = ?")
            ->execute([$campaign_id]);
    }

    usleep(150000);
}

email_rate_tokens_save($pdo, $tokens, microtime(true));
campaign_log('campaign ' . $campaign_id . ' sent=' . $sent . ' failed=' . $failed . ' checked=' . count($recipients));
