<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$search = trim($_GET['search'] ?? '');
$vendor_filter = intval($_GET['vendor_id'] ?? 0);
$project_filter = intval($_GET['project_id'] ?? 0);
$country_filter = trim($_GET['country'] ?? '');
$from_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-7 days'));
$to_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');

$where = ["c.clicked_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)"];
$params = [$from_date, $to_date];

if ($search) { $where[] = "(c.click_id LIKE ? OR c.ip_address LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($vendor_filter) { $where[] = "c.vendor_id = ?"; $params[] = $vendor_filter; }
if ($project_filter) { $where[] = "c.project_id = ?"; $params[] = $project_filter; }
if ($country_filter !== '') { $where[] = "c.country_code = ?"; $params[] = strtoupper(substr($country_filter, 0, 2)); }

$stmt = $pdo->prepare("
    SELECT c.click_id, c.clicked_at, p.project_code, gv.vendor_name, c.ip_address,
           c.country_code, c.device_type, c.browser, c.os, c.isp, c.browser_lang, c.user_agent, c.is_converted,
           c.referrer, c.sub1, c.sub2, c.sub3, c.sub4, c.sub5
    FROM clicks c
    JOIN global_vendors gv ON c.vendor_id = gv.id
    JOIN projects p ON c.project_id = p.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.clicked_at DESC
");
$stmt->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="clicklogs_' . $from_date . '_to_' . $to_date . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Click ID', 'Date', 'Project', 'Vendor', 'IP', 'Country', 'Device', 'Browser', 'OS', 'Language', 'ISP', 'Status', 'Sub1', 'Sub2', 'Sub3', 'Sub4', 'Sub5', 'Referrer', 'User Agent']);
while ($r = $stmt->fetch()) {
    fputcsv($out, [
        $r['click_id'], $r['clicked_at'], $r['project_code'], $r['vendor_name'], $r['ip_address'],
        $r['country_code'], $r['device_type'], $r['browser'], $r['os'], $r['browser_lang'], $r['isp'],
        $r['is_converted'] ? 'converted' : 'pending',
        $r['sub1'], $r['sub2'], $r['sub3'], $r['sub4'], $r['sub5'],
        substr((string)$r['referrer'], 0, 500),
        substr((string)$r['user_agent'], 0, 1000),
    ]);
}
fclose($out);