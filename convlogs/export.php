<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$search = trim($_GET['search'] ?? '');
$project_filter = intval($_GET['project_id'] ?? 0);
$vendor_filter = intval($_GET['vendor_id'] ?? 0);
$from_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');

$where = ["cv.converted_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)"];
$params = [$from_date, $to_date];

if ($search) { $where[] = "(cv.click_id LIKE ? OR cv.transaction_id LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($project_filter) { $where[] = "cv.project_id = ?"; $params[] = $project_filter; }
if ($vendor_filter) { $where[] = "cv.vendor_id = ?"; $params[] = $vendor_filter; }

$stmt = $pdo->prepare("
    SELECT cv.*, p.project_code, gv.vendor_name, c.clicked_at, c.country_code
    FROM conversions cv
    JOIN projects p ON cv.project_id = p.id
    JOIN global_vendors gv ON cv.vendor_id = gv.id
    LEFT JOIN clicks c ON cv.click_id = c.click_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY cv.converted_at DESC
");
$stmt->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="convlogs_' . $from_date . '_to_' . $to_date . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Click ID', 'Project', 'Vendor', 'Country', 'Click Time', 'Convert Time', 'Time Diff (s)', 'Status', 'Approval', 'Revenue', 'Sale Amount', 'Currency', 'Payout', 'Profit', 'Transaction ID', 'Sub1', 'Sub2', 'Sub3', 'Sub4', 'Sub5']);
while ($r = $stmt->fetch()) {
    fputcsv($out, [
        $r['click_id'], $r['project_code'], $r['vendor_name'], $r['country_code'],
        $r['clicked_at'], $r['converted_at'], $r['time_diff_seconds'],
        $r['status'], $r['approval_status'],
        $r['client_revenue'], $r['sale_amount'], $r['currency'],
        $r['vendor_cost'], $r['profit'], $r['transaction_id'],
        $r['sub1'], $r['sub2'], $r['sub3'], $r['sub4'], $r['sub5'],
    ]);
}
fclose($out);