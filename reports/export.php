<?php
require_once __DIR__ . '/../config.php';
require_login();

function escape_csv($value) {
    $value = (string)$value;
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'])) {
        $value = "'" . $value;
    }
    return $value;
}

$from_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$project_filter = intval($_GET['project_id'] ?? 0);
$type = $_GET['type'] ?? 'conversions'; // clicks or conversions
[$sub_sql, $sub_params] = tf_sub_sql($type === 'clicks' ? 'cl' : 'cv');

if ($type === 'clicks') {
    // Export raw clicks
    $sql = "SELECT cl.click_id, cl.project_id, p.project_code, gv.vendor_name, cl.ip_address,
            cl.device_type, cl.is_converted, cl.clicked_at
            FROM clicks cl
            JOIN projects p ON cl.project_id = p.id
            JOIN global_vendors gv ON cl.vendor_id = gv.id
            WHERE cl.clicked_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)" . tf_not_test_sql('cl') . $sub_sql;
    $params = array_merge([$from_date, $to_date], $sub_params);
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
    // Export conversions
    $sql = "SELECT cv.click_id, p.project_code, gv.vendor_name, cv.status,
            cv.client_revenue, cv.vendor_cost, cv.profit, cv.is_manual, cv.converted_at
            FROM conversions cv
            JOIN projects p ON cv.project_id = p.id
            JOIN global_vendors gv ON cv.vendor_id = gv.id
            WHERE cv.converted_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)" . tf_not_test_sql('cv') . $sub_sql;
    $params = array_merge([$from_date, $to_date], $sub_params);
    if ($project_filter) {
        $sql .= " AND cv.project_id = ?";
        $params[] = $project_filter;
    }
    $sql .= " ORDER BY cv.converted_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $filename = 'conversions_' . $from_date . '_to_' . $to_date . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Click ID', 'Project Code', 'Vendor', 'Status', 'Revenue', 'Cost', 'Profit', 'Manual', 'Timestamp']);
    foreach ($rows as $row) {
        fputcsv($output, [
            escape_csv($row['click_id']),
            escape_csv($row['project_code']),
            escape_csv($row['vendor_name']),
            escape_csv($row['status']),
            escape_csv($row['client_revenue']),
            escape_csv($row['vendor_cost']),
            escape_csv($row['profit']),
            escape_csv($row['is_manual'] ? 'Yes' : 'No'),
            escape_csv($row['converted_at']),
        ]);
    }
    fclose($output);
}
exit;
