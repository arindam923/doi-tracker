<?php
/**
 * TRACK FLOW — Email batch sender (Phase 7)
 * Processes `sent_emails` rows with status = 'queued'/'retrying'.
 *
 * Crontab (every minute):
 *   * * * * * cd /path/to/doi-tracker && php cron/email_batch.php >> storage/logs/email_batch.log 2>&1
 *
 * Rate limiting: token bucket (email_bucket_tokens + email_bucket_last_ts settings),
 * default email_rate_per_minute (50/min). On 429 we stop this run and let the
 * next cron tick pick up where it left off. Failures retry up to 3 times with
 * exponential backoff before being marked failed.
 */

declare(ticks=1);

require_once __DIR__ . '/../config.php';

$log_dir = __DIR__ . '/../storage/logs';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
$started = microtime(true);
$sent = 0;
$failed = 0;

function batch_log($msg) {
    $line = '[' . date('c') . '] ' . $msg . PHP_EOL;
    $log_dir = __DIR__ . '/../storage/logs';
    if (is_dir($log_dir) && is_writable($log_dir)) {
        @file_put_contents($log_dir . '/email_batch.log', $line, FILE_APPEND | LOCK_EX);
    }
    error_log('email_batch: ' . $msg);
}

// Graceful shutdown on long runs (e.g. ctrl-c / SIGTERM)
function batch_shutdown() {
    global $sent, $failed, $started;
    batch_log("run finished: sent={$sent} failed={$failed} in " . round(microtime(true) - $started, 2) . 's');
}
register_shutdown_function('batch_shutdown');

// ── Rate-limit gate (token bucket) ─────────────────────────────
$rate_per_minute = max(1, (int)get_setting($pdo, 'email_rate_per_minute', '50'));
$tokens = (float)get_setting($pdo, 'email_bucket_tokens', (string)$rate_per_minute);
$last_ts = (float)get_setting($pdo, 'email_bucket_last_ts', '0');

$now = microtime(true);
if ($last_ts > 0) {
    $tokens = min($rate_per_minute, $tokens + (($now - $last_ts) / 60) * $rate_per_minute);
} else {
    $tokens = $rate_per_minute;
}
set_setting($pdo, 'email_bucket_last_ts', (string)$now);

// ── Resend config ──────────────────────────────────────────────
$resend_api_key = get_setting($pdo, 'resend_api_key');
$from_address   = get_setting($pdo, 'email_from_address', 'noreply@yourdomain.com');
$from_name      = get_setting($pdo, 'email_from_name', 'Track Flow');
if (empty($resend_api_key)) {
    batch_log('no resend_api_key configured; aborting run');
    exit(0);
}

function send_via_resend($api_key, $from, $email_data) {
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
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
    return [$http_code, $response, $curl_error];
}

// ── Process queue ──────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT * FROM sent_emails
    WHERE status IN ('queued','retrying')
      AND (scheduled_for IS NULL OR scheduled_for <= NOW())
    ORDER BY created_at ASC
    LIMIT 500
");
$stmt->execute();
$queue = $stmt->fetchAll();

batch_log('processing ' . count($queue) . ' queued emails');

foreach ($queue as $email) {
    // Rate limit: stop when bucket is empty (let next cron tick continue)
    if ($tokens < 1) {
        batch_log('rate limit reached (' . round($tokens, 2) . ' tokens left); pausing');
        break;
    }

    if (microtime(true) - $started > 60) {
        batch_log('60s runtime budget exceeded; pausing for next tick');
        break;
    }

    $email_data = [
        'from' => $from_name . ' <' . $from_address . '>',
        'to' => [$email['recipient_email']],
        'subject' => $email['subject'],
        'text' => $email['body'],
    ];

    list($http_code, $response, $curl_error) = send_via_resend($resend_api_key, $from_address, $email_data);

    // 429 → respect Retry-After, stop this run
    if ($http_code === 429) {
        batch_log('Resend 429 rate limit; pausing');
        break;
    }

    $tokens--;

    if ($http_code === 200) {
        $result = json_decode($response, true);
        $pdo->prepare("UPDATE sent_emails SET status = 'sent', resend_id = ?, error_message = NULL, retry_count = 0 WHERE id = ?")
            ->execute([$result['id'] ?? null, $email['id']]);
        $sent++;
    } else {
        $retry_count = (int)$email['retry_count'];
        $error = $curl_error ?: 'HTTP ' . $http_code . ': ' . substr($response, 0, 300);

        if ($retry_count >= 3) {
            $pdo->prepare("UPDATE sent_emails SET status = 'failed', error_message = ?, retry_count = ? WHERE id = ?")
                ->execute([$error, $retry_count, $email['id']]);
            $failed++;
            batch_log("email #{$email['id']} permanently failed: {$error}");
        } else {
            // Exponential backoff: 5s, 20s, 60s
            $backoff = [0, 5, 20, 60][$retry_count + 1] ?? 60;
            $next_at = date('Y-m-d H:i:s', time() + $backoff);
            $pdo->prepare("UPDATE sent_emails SET status = 'retrying', error_message = ?, retry_count = ?, scheduled_for = ? WHERE id = ?")
                ->execute([$error, $retry_count + 1, $next_at, $email['id']]);
            batch_log("email #{$email['id']} retry {$retry_count}+1 in {$backoff}s: {$error}");
        }
    }

    // Small throttle between sends
    usleep(150000); // 150ms
}

set_setting($pdo, 'email_bucket_tokens', (string)$tokens);
batch_log("done: sent={$sent} failed={$failed}");
