<?php
/**
 * Track Flow — Per-campaign traffic export (CSV).
 * CSV is used because InfinityFree often lacks a writable temp dir / ZipArchive,
 * which made the previous XLSX builder return HTTP 500.
 *
 * URL: /projects/export.php?id=<project_id>&from=YYYY-MM-DD&to=YYYY-MM-DD
 */

require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$project_id = intval($_GET['id'] ?? $_GET['project_id'] ?? 0);
if (!$project_id) {
    set_flash('danger', 'Project id is required.');
    redirect(BASE_URL . '/projects/list.php');
}

try {
    $stmt = $pdo->prepare("SELECT p.*, c.client_name, c.client_code FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
} catch (Throwable $e) {
    error_log('export project lookup: ' . $e->getMessage());
    $project = null;
}

if (!$project) {
    set_flash('danger', 'Project not found.');
    redirect(BASE_URL . '/projects/list.php');
}

$from_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($from_date === '') {
    $start = (string)($project['start_date'] ?? '');
    $from_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && strpos($start, '0000-') !== 0
        ? $start
        : date('Y-m-d', strtotime('-365 days'));
}
if (strtotime($to_date) < strtotime($from_date)) {
    $to_date = $from_date;
}
$from_dt = $from_date . ' 00:00:00';
$to_dt   = $to_date . ' 23:59:59';

$code = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($project['project_code'] ?? 'project'));
$filename = 'trackflow_' . $code . '_' . $from_date . '_to_' . $to_date . '.csv';

try {
    $clicks_stmt = $pdo->prepare("
        SELECT cl.*, gv.vendor_name
        FROM clicks cl
        LEFT JOIN global_vendors gv ON cl.vendor_id = gv.id
        WHERE cl.project_id = ?
          AND (cl.clicked_at BETWEEN ? AND ? OR cl.clicked_at IS NULL)
        ORDER BY cl.clicked_at DESC
    ");
    $clicks_stmt->execute([$project_id, $from_dt, $to_dt]);
    $clicks = $clicks_stmt->fetchAll();

    $conv_stmt = $pdo->prepare("
        SELECT cv.*, gv.vendor_name
        FROM conversions cv
        LEFT JOIN global_vendors gv ON cv.vendor_id = gv.id
        WHERE cv.project_id = ?
          AND (cv.converted_at BETWEEN ? AND ? OR cv.converted_at IS NULL)
        ORDER BY cv.converted_at DESC
    ");
    $conv_stmt->execute([$project_id, $from_dt, $to_dt]);
    $conversions = $conv_stmt->fetchAll();
} catch (Throwable $e) {
    error_log('export traffic query: ' . $e->getMessage());
    set_flash('danger', 'Export failed. Please try again.');
    redirect(BASE_URL . '/projects/detail.php?id=' . $project_id);
}

$complete = array_filter($conversions, static function ($row) {
    return ($row['status'] ?? '') === 'complete';
});
$total_clicks = count($clicks);
$total_conv = count($complete);
$total_rev = 0.0;
$total_cost = 0.0;
$total_profit = 0.0;
foreach ($complete as $row) {
    $total_rev += (float)($row['client_revenue'] ?? 0);
    $total_cost += (float)($row['vendor_cost'] ?? 0);
    $total_profit += (float)($row['profit'] ?? 0);
}

if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Track Flow — Traffic Export']);
fputcsv($out, ['Project', ($project['project_code'] ?? '') . ' — ' . ($project['project_name'] ?? '')]);
fputcsv($out, ['Client', $project['client_name'] ?? '-']);
fputcsv($out, ['Campaign Type', $project['campaign_type'] ?? 'CPL']);
fputcsv($out, ['Date Range', $from_date . ' → ' . $to_date]);
fputcsv($out, ['Exported At', date('Y-m-d H:i:s')]);
fputcsv($out, []);
fputcsv($out, ['Summary']);
fputcsv($out, ['Total Clicks', $total_clicks]);
fputcsv($out, ['Total Conversions', $total_conv]);
fputcsv($out, ['Total Revenue', $total_rev]);
fputcsv($out, ['Total Cost', $total_cost]);
fputcsv($out, ['Total Profit', $total_profit]);
fputcsv($out, []);
fputcsv($out, ['Clicks']);
fputcsv($out, ['Click ID', 'Vendor', 'IP', 'Device', 'Country', 'Language', 'ISP', 'Referrer', 'User Agent', 'Converted', 'Clicked At']);

foreach ($clicks as $c) {
    $country = $c['country_code'] ?? ($c['country_detected'] ?? '');
    fputcsv($out, [
        $c['click_id'] ?? '',
        $c['vendor_name'] ?? '',
        $c['ip_address'] ?? '',
        $c['device_type'] ?? '',
        $country,
        $c['browser_lang'] ?? '',
        $c['isp'] ?? '',
        $c['referrer'] ?? '',
        $c['user_agent'] ?? '',
        !empty($c['is_converted']) ? 'Yes' : 'No',
        $c['clicked_at'] ?? '',
    ]);
}

fputcsv($out, []);
fputcsv($out, ['Conversions']);
fputcsv($out, ['Click ID', 'Vendor', 'Status', 'Revenue', 'Cost', 'Profit', 'Manual', 'Converted At']);

foreach ($conversions as $cv) {
    fputcsv($out, [
        $cv['click_id'] ?? '',
        $cv['vendor_name'] ?? '',
        $cv['status'] ?? '',
        (float)($cv['client_revenue'] ?? 0),
        (float)($cv['vendor_cost'] ?? 0),
        (float)($cv['profit'] ?? 0),
        !empty($cv['is_manual']) ? 'Yes' : 'No',
        $cv['converted_at'] ?? '',
    ]);
}

fclose($out);
exit;
