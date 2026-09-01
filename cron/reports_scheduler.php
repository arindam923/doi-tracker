<?php
/**
 * TRACK FLOW — Scheduled reports runner (Phase 6, Item #22)
 *
 * Scans `scheduled_reports` for rows where next_run_at <= NOW() and is_active = 1,
 * builds the bounded CSV using the same aggregation inputs as reports/overview.php,
 * and emails it to recipients via Resend. Then rolls next_run_at forward.
 *
 * This is deliberately a minimal wireframe: heavy pipelining (PDF attachments,
 * multi-format, retry queues) can be layered on top without changing the schema.
 *
 * Crontab (every 5 minutes):
 *   * / 5 * * * * cd /path/to/doi-tracker && php cron/reports_scheduler.php >> storage/logs/reports_scheduler.log 2>&1
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/reporting.php';

$log_dir = __DIR__ . '/../storage/logs';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);

function sched_log($msg) {
    $log_dir = __DIR__ . '/../storage/logs';
    $line = '[' . date('c') . '] ' . $msg . PHP_EOL;
    if (is_dir($log_dir) && is_writable($log_dir)) {
        @file_put_contents($log_dir . '/reports_scheduler.log', $line, FILE_APPEND | LOCK_EX);
    }
    error_log('reports_scheduler: ' . $msg);
}

function sched_next_run($frequency, $from_ts) {
    switch ($frequency) {
        case 'hourly':      return strtotime('+1 hour', $from_ts);
        case 'weekly':      return strtotime('+1 week', $from_ts);
        case 'monthly':     return strtotime('+1 month', $from_ts);
        case 'custom_cron': return strtotime('+1 day', $from_ts); // best-effort
        default:            return strtotime('+1 day', $from_ts); // daily
    }
}

// Build scheduled rows through the same reporting engine used by the UI/export.
function build_report_rows($pdo, $filters) {
    $days = max(1, (int)($filters['days'] ?? 30));
    $to = $filters['to'] ?? date('Y-m-d');
    $from = $filters['from'] ?? date('Y-m-d', strtotime('-' . $days . ' days', strtotime($to)));
    return tf_reporting_build_report($pdo, [
        'period' => $filters['period'] ?? 'custom',
        'group' => $filters['group_by'] ?? 'project',
        'from' => $from,
        'to' => $to,
        'project_id' => $filters['project_id'] ?? 0,
        'client_id' => $filters['client_id'] ?? 0,
        'vendor_id' => $filters['vendor_id'] ?? 0,
    ])['rows'];
}

$rows = $pdo->query("SELECT * FROM scheduled_reports WHERE is_active = 1 AND (next_run_at IS NULL OR next_run_at <= NOW()) LIMIT 20")->fetchAll();

if (!$rows) {
    exit(0);
}

$resend_api_key = get_setting($pdo, 'resend_api_key');
$from_address   = get_setting($pdo, 'email_from_address', 'noreply@yourdomain.com');
$from_name      = get_setting($pdo, 'email_from_name', 'Track Flow');

foreach ($rows as $report) {
    try {
        $filters = json_decode((string)$report['filters_json'], true) ?: [];
        $data = build_report_rows($pdo, $filters);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Group', 'Clicks', 'Conversions', 'Rejected Leads', 'Revenue', 'Cost', 'Profit', 'ROI (%)', 'Conversion Rate (%)', 'EPC']);
        foreach ($data as $d) {
            fputcsv($out, [$d['label'], $d['clicks'], $d['conversions'], $d['rejected_leads'], $d['revenue'], $d['cost'], $d['profit'], $d['roi'], $d['conversion_rate'], $d['epc']]);
        }
        rewind($out);
        $csv_body = stream_get_contents($out);
        fclose($out);

        if (empty($csv_body)) $csv_body = "No data in the reporting window.\n";

        $now = date('Y-m-d H:i:s');
        sched_log("delivering report #{$report['id']} '{$report['title']}' (" . count($data) . " rows)");

        // Send via Resend (attachment support exists; plain text here keeps it simple)
        if ($resend_api_key && !empty($report['recipients_csv'])) {
            $recipients = array_map('trim', explode(',', $report['recipients_csv']));
            $subject = 'Report: ' . $report['title'] . ' — ' . date('Y-m-d');
            foreach ($recipients as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                $ch = curl_init('https://api.resend.com/emails');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $resend_api_key, 'Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'from' => $from_name . ' <' . $from_address . '>',
                    'to' => [$email],
                    'subject' => $subject,
                    'text' => 'Attached is your scheduled report "' . $report['title'] . '".',
                    'attachments' => [[
                        'filename' => 'report.csv',
                        'content' => base64_encode($csv_body),
                    ]],
                ]));
                curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                curl_exec($ch);
                curl_close($ch);
            }
        }

        $pdo->prepare("UPDATE scheduled_reports SET last_run_at = ?, next_run_at = ? WHERE id = ?")
            ->execute([
                $now,
                date('Y-m-d H:i:s', sched_next_run($report['frequency'], time())),
                $report['id'],
            ]);
    } catch (Throwable $e) {
        sched_log("report #{$report['id']} failed: " . $e->getMessage());
    }
}

sched_log('done');
