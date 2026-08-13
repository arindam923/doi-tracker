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

// Build a simple bounded table given filters_json (group_by / project_id).
function build_report_rows($pdo, $filters) {
    $group_by = ($filters['group_by'] ?? 'project');
    $project_id = (int)($filters['project_id'] ?? 0);
    $days = (int)($filters['days'] ?? 30);

    $where = ["c.status = 'complete'", "c.converted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"];
    $params = [$days];

    $row_fields = [
        'project' => "p.project_code AS label",
        'vendor'  => "gv.vendor_name AS label",
        'client'  => "cl.client_name AS label",
        'country' => "COALESCE(cl2.country_code,'XX') AS label",
        'device'  => "COALESCE(cl2.device_type,'unknown') AS label",
    ];
    if (!isset($row_fields[$group_by])) $group_by = 'project';
    $field_sql = $row_fields[$group_by];

    $joins = " JOIN projects p ON c.project_id = p.id";
    switch ($group_by) {
        case 'project': $group_col = 'c.project_id'; break;
        case 'vendor':  $joins .= " JOIN global_vendors gv ON c.vendor_id = gv.id"; $group_col = 'c.vendor_id'; break;
        case 'client':  $joins .= " JOIN clients cl ON p.client_id = cl.id"; $group_col = 'cl.id'; break;
        case 'country': $joins .= " LEFT JOIN clicks cl2 ON c.click_id = cl2.click_id"; $group_col = 'cl2.country_code'; break;
        default:        $joins .= " LEFT JOIN clicks cl2 ON c.click_id = cl2.click_id"; $group_col = 'cl2.device_type'; break;
    }

    if ($project_id) {
        $where[] = "c.project_id = ?";
        $params[] = $project_id;
    }

    $sql = "SELECT $field_sql AS label,
                   COUNT(c.id) AS completes,
                   ROUND(COALESCE(SUM(c.client_revenue),0),2) AS revenue,
                   ROUND(COALESCE(SUM(c.vendor_cost),0),2) AS cost,
                   ROUND(COALESCE(SUM(c.profit),0),2) AS profit
            FROM conversions c $joins
            WHERE " . implode(' AND ', $where) . "
            GROUP BY $group_col
            ORDER BY revenue DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
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
        fputcsv($out, ['Group', 'Completes', 'Revenue', 'Cost', 'Profit']);
        foreach ($data as $d) {
            fputcsv($out, [$d['label'], $d['completes'], $d['revenue'], $d['cost'], $d['profit']]);
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
