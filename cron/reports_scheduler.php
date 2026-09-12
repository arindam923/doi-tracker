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

function weekly_next_run(PDO $pdo, $from_ts = null) {
    $from_ts = $from_ts ?: time();
    $day = max(1, min(7, (int)get_setting($pdo, 'weekly_report_day', '1')));
    $time_str = trim((string)get_setting($pdo, 'weekly_report_time', '09:00'));
    if (!preg_match('/^\d{2}:\d{2}$/', $time_str)) $time_str = '09:00';
    [$hh,$mm] = array_map('intval', explode(':', $time_str));
    $hh = max(0,min(23,$hh)); $mm = max(0,min(59,$mm));
    $days_map = [1=>'monday',2=>'tuesday',3=>'wednesday',4=>'thursday',5=>'friday',6=>'saturday',7=>'sunday'];
    $target = $days_map[$day];
    $candidate = strtotime("next $target $hh:$mm:00", $from_ts);
    if ($candidate <= $from_ts) $candidate = strtotime("+1 week", $candidate);
    return $candidate;
}

function sched_next_run($frequency, $from_ts, PDO $pdo = null) {
    switch ($frequency) {
        case 'hourly':      return strtotime('+1 hour', $from_ts);
        case 'weekly':      return $pdo ? weekly_next_run($pdo, $from_ts) : strtotime('+1 week', $from_ts);
        case 'monthly':     return strtotime('+1 month', $from_ts);
        case 'custom_cron': return strtotime('+1 day', $from_ts);
        default:            return strtotime('+1 day', $from_ts);
    }
}

function sync_settings_driven_reports(PDO $pdo): void {
    $day = max(1,min(7,(int)get_setting($pdo,'weekly_report_day','1')));
    $time_str = trim((string)get_setting($pdo,'weekly_report_time','09:00'));
    if (!preg_match('/^\d{2}:\d{2}$/',$time_str)) $time_str='09:00';
    $next = date('Y-m-d H:i:s', weekly_next_run($pdo, time()));

    $client_enabled = get_setting($pdo,'weekly_client_reports_enabled','0')==='1';
    if ($client_enabled) {
        foreach ($pdo->query("SELECT id, client_name, email FROM clients WHERE is_active=1 AND email IS NOT NULL AND email!=''")->fetchAll() as $c) {
            $title = 'Auto Weekly — Client #' . $c['id'];
            $exists = $pdo->prepare("SELECT id, recipients_csv, next_run_at FROM scheduled_reports WHERE title=? LIMIT 1");
            $exists->execute([$title]);
            $row = $exists->fetch();
            $fj = json_encode(['period'=>'weekly','group_by'=>'project','client_id'=>(int)$c['id']]);
            if ($row) {
                $pdo->prepare("UPDATE scheduled_reports SET recipients_csv=?, filters_json=?, frequency='weekly', is_active=1, next_run_at=COALESCE(next_run_at,?) WHERE id=?")->execute([$c['email'],$fj,$next,$row['id']]);
            } else {
                $pdo->prepare("INSERT INTO scheduled_reports (owner_id,title,report_type,group_by,filters_json,frequency,recipients_csv,next_run_at,is_active,created_at) VALUES (1,?,'overview','project',?,'weekly',?,?,1,NOW())")->execute([$title,$fj,$c['email'],$next]);
            }
        }
    } else {
        $pdo->exec("UPDATE scheduled_reports SET is_active=0 WHERE title LIKE 'Auto Weekly — Client #%'");
    }

    $vendor_enabled = get_setting($pdo,'weekly_vendor_reports_enabled','0')==='1';
    if ($vendor_enabled) {
        foreach ($pdo->query("SELECT id, vendor_name, email FROM global_vendors WHERE vendor_status='approved' AND email IS NOT NULL AND email!=''")->fetchAll() as $v) {
            $title = 'Auto Weekly — Vendor #' . $v['id'];
            $exists = $pdo->prepare("SELECT id FROM scheduled_reports WHERE title=? LIMIT 1");
            $exists->execute([$title]);
            $row = $exists->fetch();
            $fj = json_encode(['period'=>'weekly','group_by'=>'project','vendor_id'=>(int)$v['id']]);
            if ($row) {
                $pdo->prepare("UPDATE scheduled_reports SET recipients_csv=?, filters_json=?, frequency='weekly', is_active=1, next_run_at=COALESCE(next_run_at,?) WHERE id=?")->execute([$v['email'],$fj,$next,$row['id']]);
            } else {
                $pdo->prepare("INSERT INTO scheduled_reports (owner_id,title,report_type,group_by,filters_json,frequency,recipients_csv,next_run_at,is_active,created_at) VALUES (1,?,'overview','project',?,'weekly',?,?,1,NOW())")->execute([$title,$fj,$v['email'],$next]);
            }
        }
    } else {
        $pdo->exec("UPDATE scheduled_reports SET is_active=0 WHERE title LIKE 'Auto Weekly — Vendor #%'");
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

try { sync_settings_driven_reports($pdo); } catch (Throwable $e) { sched_log('sync failed: '.$e->getMessage()); }

try {
    $pdo->exec("SELECT * FROM scheduled_reports WHERE is_active=1 AND next_run_at<=NOW() FOR UPDATE");
} catch (Throwable $e) {}
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

        $next_ts = $report['frequency']==='weekly' ? weekly_next_run($pdo, time()) : sched_next_run($report['frequency'], time(), $pdo);
        $pdo->prepare("UPDATE scheduled_reports SET last_run_at = ?, next_run_at = ? WHERE id = ?")
            ->execute([
                $now,
                date('Y-m-d H:i:s', $next_ts),
                $report['id'],
            ]);
    } catch (Throwable $e) {
        sched_log("report #{$report['id']} failed: " . $e->getMessage());
    }
}

sched_log('done');
