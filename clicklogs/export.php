<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$search = trim($_GET['search'] ?? '');
$vendor_filter = intval($_GET['vendor_id'] ?? 0);
$project_filter = intval($_GET['project_id'] ?? 0);
$country_filter = trim($_GET['country'] ?? '');
$device_filter = trim($_GET['device'] ?? '');
$browser_filter = trim($_GET['browser'] ?? '');
$isp_filter = trim($_GET['isp'] ?? '');
$click_id_filter = trim($_GET['click_id'] ?? '');
$os_filter = trim($_GET['os'] ?? '');
$subs = tf_request_subs();
$from_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-7 days'));
$to_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$as_excel = ($_GET['format'] ?? '') === 'excel';

$where = ["c.clicked_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)"];
$params = [$from_date, $to_date];

if ($search) { $where[] = "(c.click_id LIKE ? OR c.ip_address LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($vendor_filter) { $where[] = "c.vendor_id = ?"; $params[] = $vendor_filter; }
if ($project_filter) { $where[] = "c.project_id = ?"; $params[] = $project_filter; }
if ($country_filter !== '') { $where[] = "c.country_code = ?"; $params[] = strtoupper(substr($country_filter, 0, 2)); }
if ($device_filter !== '') { $where[] = "c.device_type = ?"; $params[] = $device_filter; }
if ($browser_filter !== '') { $where[] = "c.browser LIKE ?"; $params[] = "%$browser_filter%"; }
if ($isp_filter !== '') { $where[] = "c.isp LIKE ?"; $params[] = "%$isp_filter%"; }
if ($click_id_filter) { $where[] = "c.click_id = ?"; $params[] = $click_id_filter; }
if ($os_filter !== '') { $where[] = "c.os LIKE ?"; $params[] = '%' . $os_filter . '%'; }
foreach ($subs as $key => $value) {
    if ($value === '') {
        continue;
    }
    $where[] = "c.$key = ?";
    $params[] = $value;
}

$stmt = $pdo->prepare("
    SELECT c.click_id, c.clicked_at, p.project_code, gv.vendor_name, c.ip_address,
           c.country_code, c.device_type, c.browser, c.os, c.isp, c.is_converted, c.is_test,
           c.user_agent, c.referrer, c.sub1, c.sub2, c.sub3, c.sub4, c.sub5
    FROM clicks c
    JOIN global_vendors gv ON c.vendor_id = gv.id
    JOIN projects p ON c.project_id = p.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.clicked_at DESC
");
$stmt->execute($params);

$headers = ['Click ID', 'Date', 'Project', 'Vendor', 'IP', 'Country', 'Device', 'Browser', 'OS', 'ISP', 'Status', 'Test', 'Sub1', 'Sub2', 'Sub3', 'Sub4', 'Sub5', 'Referrer'];
$rows = [];
while ($r = $stmt->fetch()) {
    $rows[] = [
        $r['click_id'], $r['clicked_at'], $r['project_code'], $r['vendor_name'], $r['ip_address'],
        $r['country_code'], $r['device_type'], $r['browser'], $r['os'], $r['isp'],
        $r['is_converted'] ? 'converted' : 'pending',
        !empty($r['is_test']) ? 'test' : '',
        $r['sub1'], $r['sub2'], $r['sub3'], $r['sub4'], $r['sub5'],
        substr((string)$r['referrer'], 0, 200),
    ];
}

$filename = 'clicklogs_' . $from_date . '_to_' . $to_date;
if ($as_excel) {
    tf_output_excel($filename . '.xls', $headers, $rows);
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, $headers);
foreach ($rows as $row) {
    fputcsv($out, $row);
}
fclose($out);
