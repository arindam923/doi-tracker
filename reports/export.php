<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/reporting.php';
require_login();

function escape_csv($value) {
    $value = (string)$value;
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'])) {
        $value = "'" . $value;
    }
    return $value;
}

$report_filters = tf_reporting_normalize_filters($_GET);
$from_date = $report_filters['from'];
$to_date = $report_filters['to'];
$project_filter = $report_filters['project_id'];
$type = $_GET['type'] ?? 'conversions'; // clicks or conversions

if ($type === 'clicks') {
    // Export raw clicks
    $sql = "SELECT cl.click_id, cl.project_id, p.project_code, gv.vendor_name, cl.ip_address,
            cl.device_type, cl.is_converted, cl.clicked_at
            FROM clicks cl
            JOIN projects p ON cl.project_id = p.id
            JOIN global_vendors gv ON cl.vendor_id = gv.id
            WHERE cl.clicked_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)";
    $params = [$from_date, $to_date];
    if ($project_filter) {
        $sql .= " AND cl.project_id = ?";
        $params[] = $project_filter;
    }
    $sql .= " ORDER BY cl.clicked_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $filename = 'clicks_' . $from_date . '_to_' . $to_date . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Click ID', 'Project Code', 'Vendor', 'IP Address', 'Device', 'Converted', 'Timestamp']);
    foreach ($rows as $row) {
        fputcsv($output, [
            escape_csv($row['click_id']),
            escape_csv($row['project_code']),
            escape_csv($row['vendor_name']),
            escape_csv($row['ip_address']),
            escape_csv($row['device_type']),
            escape_csv($row['is_converted'] ? 'Yes' : 'No'),
            escape_csv($row['clicked_at']),
        ]);
    }
    fclose($output);

} else {
    $report = tf_reporting_build_report($pdo, $_GET);
    $rows = $report['rows'];

    $filename = 'conversions_' . $from_date . '_to_' . $to_date . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Group By', 'Group', 'Clicks', 'Conversions', 'Rejected Leads', 'Revenue', 'Cost', 'Profit', 'ROI (%)', 'Conversion Rate (%)', 'EPC']);
    foreach ($rows as $row) {
        fputcsv($output, [
            escape_csv($report['group_label']), escape_csv($row['label']), escape_csv($row['clicks']),
            escape_csv($row['conversions']), escape_csv($row['rejected_leads']), escape_csv($row['revenue']),
            escape_csv($row['cost']), escape_csv($row['profit']), escape_csv($row['roi']),
            escape_csv($row['conversion_rate']), escape_csv($row['epc']),
        ]);
    }
    fclose($output);
}
exit;
