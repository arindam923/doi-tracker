<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$filters = tf_clicklogs_filters($_GET);
$from_date = $filters['from'];
$to_date = $filters['to'];
$where_sql = $filters['where_sql'];
$params = $filters['params'];

try {
    $stmt = $pdo->prepare("
    SELECT c.click_id, c.clicked_at, p.project_code, gv.vendor_name, c.ip_address,
           c.country_code, c.device_type, c.browser, c.os, c.isp, c.browser_lang, c.user_agent, c.is_converted,
           c.referrer, c.sub1, c.sub2, c.sub3, c.sub4, c.sub5
    FROM clicks c
    JOIN global_vendors gv ON c.vendor_id = gv.id
    JOIN projects p ON c.project_id = p.id
    $where_sql
    ORDER BY c.clicked_at DESC
");
    $stmt->execute($params);
} catch (Throwable $e) {
    error_log('clicklogs export failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Export failed.');
}

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
