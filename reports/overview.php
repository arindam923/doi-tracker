<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/reporting.php';
require_login();

$report_input = $_GET;
if (empty($report_input['project_id']) && !empty($report_input['id'])) $report_input['project_id'] = $report_input['id'];
$legacy_presets = [
    'today' => [date('Y-m-d'), date('Y-m-d')],
    'yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    '7d' => [date('Y-m-d', strtotime('-7 days')), date('Y-m-d')],
    '30d' => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
    'thisweek' => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))],
    'lastweek' => [date('Y-m-d', strtotime('monday last week')), date('Y-m-d', strtotime('sunday last week'))],
    'thismonth' => [date('Y-m-01'), date('Y-m-d')],
    'lastmonth' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'all' => ['2000-01-01', date('Y-m-d')],
];
if (isset($legacy_presets[$report_input['preset'] ?? '']) && !isset($report_input['from'], $report_input['to'])) {
    [$report_input['from'], $report_input['to']] = $legacy_presets[$report_input['preset']];
}
$filters = tf_reporting_normalize_filters($report_input, ['period' => 'daily', 'group' => 'project']);
$report = tf_reporting_build_report($pdo, $report_input);
$totals = $report['totals'];
$projects = $pdo->query("SELECT id, project_code, project_name FROM projects ORDER BY project_name")->fetchAll();
$clients = $pdo->query("SELECT id, client_name FROM clients ORDER BY client_name")->fetchAll();
$vendors = $pdo->query("SELECT id, vendor_name FROM global_vendors ORDER BY vendor_name")->fetchAll();
$page_title = 'Reporting';
require_once __DIR__ . '/../helpers/layout_header.php';

$query = static function (array $overrides = []) use ($filters) {
    return '?' . http_build_query(array_merge($filters, $overrides));
};
$period_labels = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'custom' => 'Custom Date Range'];
$metric_currency = static function ($value) { return format_currency($value); };
?>

<div class="report-hero"><div class="d-flex align-items-center flex-wrap gap-2 position-relative" style="z-index:1;"><span class="hero-pill"><span class="dot"></span><?php echo sanitize($period_labels[$filters['period']]); ?> Report</span><span class="hero-pill"><i class="bi bi-calendar3"></i><?php echo sanitize($filters['from']); ?> – <?php echo sanitize($filters['to']); ?></span></div></div>

<div class="filter-bar"><form method="GET">
    <div class="row g-3 align-items-end">
        <div class="col-12 col-lg-3"><label class="form-label">Report Period</label><select name="period" class="form-select"><?php foreach ($period_labels as $key => $label): ?><option value="<?php echo $key; ?>" <?php echo $filters['period'] === $key ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-lg-2"><label class="form-label" for="from">From</label><input type="date" id="from" name="from" class="form-control" value="<?php echo $filters['from']; ?>"></div>
        <div class="col-6 col-lg-2"><label class="form-label" for="to">To</label><input type="date" id="to" name="to" class="form-control" value="<?php echo $filters['to']; ?>"></div>
        <div class="col-12 col-lg-2"><label class="form-label">Group By</label><select name="group" class="form-select"><?php foreach (tf_reporting_groups() as $group): ?><option value="<?php echo $group; ?>" <?php echo $filters['group'] === $group ? 'selected' : ''; ?>><?php echo ucfirst($group); ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-lg-3 d-flex gap-2"><button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-funnel-fill"></i> Apply</button><a class="btn btn-outline-secondary" href="<?php echo BASE_URL; ?>/reports/overview.php" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a><a class="btn btn-outline-success" href="<?php echo BASE_URL; ?>/reports/export.php<?php echo $query(); ?>" title="Download CSV"><i class="bi bi-download"></i></a></div>
    </div>
    <div class="row g-3 mt-1">
        <div class="col-12 col-lg-4"><label class="form-label">Project</label><select name="project_id" class="form-select"><option value="0">All projects</option><?php foreach ($projects as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $filters['project_id'] === (int)$p['id'] ? 'selected' : ''; ?>><?php echo sanitize($p['project_code'] . ' — ' . $p['project_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-lg-4"><label class="form-label">Client</label><select name="client_id" class="form-select"><option value="0">All clients</option><?php foreach ($clients as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $filters['client_id'] === (int)$c['id'] ? 'selected' : ''; ?>><?php echo sanitize($c['client_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-lg-4"><label class="form-label">Vendor</label><select name="vendor_id" class="form-select"><option value="0">All vendors</option><?php foreach ($vendors as $v): ?><option value="<?php echo (int)$v['id']; ?>" <?php echo $filters['vendor_id'] === (int)$v['id'] ? 'selected' : ''; ?>><?php echo sanitize($v['vendor_name']); ?></option><?php endforeach; ?></select></div>
    </div>
</form></div>

<div class="row g-3 mb-3 align-items-stretch">
<?php foreach ([['Revenue', $metric_currency($totals['revenue']), 'kpi-revenue'], ['Cost', $metric_currency($totals['cost']), 'kpi-cost'], ['Profit', $metric_currency($totals['profit']), 'kpi-profit'], ['ROI', $totals['roi'] . '%', 'kpi-profit'], ['Conversion Rate', $totals['conversion_rate'] . '%', 'kpi-conversions'], ['EPC', $metric_currency($totals['epc']), 'kpi-clicks'], ['Rejected Leads', number_format($totals['rejected_leads']), 'kpi-cost'], ['Clicks', number_format($totals['clicks']), 'kpi-clicks']] as $card): ?><div class="col-6 col-lg-3"><div class="kpi-card <?php echo $card[2]; ?>"><p class="kpi-label"><?php echo $card[0]; ?></p><p class="kpi-value"><?php echo $card[1]; ?></p></div></div><?php endforeach; ?>
</div>

<div class="chart-card mb-4"><div class="chart-header"><div><h5>Performance Trend</h5><p class="small text-secondary mb-0"><?php echo sanitize($period_labels[$filters['period']]); ?> buckets</p></div></div><div class="card-body p-4"><div class="tf-chart-body" style="height:360px"><canvas id="reportChart" role="img" aria-label="Revenue, cost, conversions, and rejected leads"></canvas></div></div></div>

<div class="data-card mb-4"><div class="data-header"><div><h5>Performance by <?php echo sanitize($report['group_label']); ?></h5><p class="small text-secondary mb-0">Revenue, cost, profit, ROI, conversion rate, EPC, and rejected leads</p></div><span class="badge bg-light text-dark"><?php echo count($report['rows']); ?> groups</span></div><div class="table-responsive"><table class="table table-hover table-reports align-middle mb-0"><thead><tr><th><?php echo sanitize($report['group_label']); ?></th><th class="text-end">Clicks</th><th class="text-end">Conversions</th><th class="text-end">Rejected Leads</th><th class="text-end">Revenue</th><th class="text-end">Cost</th><th class="text-end">Profit</th><th class="text-end">ROI</th><th class="text-end">Conversion Rate</th><th class="text-end">EPC</th></tr></thead><tbody><?php if (!$report['rows']): ?><tr><td colspan="10" class="text-center py-5 text-muted">No report data for this period.</td></tr><?php else: foreach ($report['rows'] as $row): ?><tr><td class="fw-semibold"><?php echo sanitize($row['label']); ?></td><td class="text-end"><?php echo number_format($row['clicks']); ?></td><td class="text-end"><?php echo number_format($row['conversions']); ?></td><td class="text-end text-danger"><?php echo number_format($row['rejected_leads']); ?></td><td class="text-end text-success"><?php echo $metric_currency($row['revenue']); ?></td><td class="text-end text-danger"><?php echo $metric_currency($row['cost']); ?></td><td class="text-end <?php echo $row['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo $metric_currency($row['profit']); ?></td><td class="text-end"><?php echo $row['roi']; ?>%</td><td class="text-end"><?php echo $row['conversion_rate']; ?>%</td><td class="text-end"><?php echo $metric_currency($row['epc']); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div>

<?php $chart_labels = array_column($report['trend'], 'bucket'); $chart_revenue = array_column($report['trend'], 'revenue'); $chart_cost = array_column($report['trend'], 'cost'); $chart_conversions = array_column($report['trend'], 'conversions'); $chart_rejected = array_column($report['trend'], 'rejected_leads'); ?><script>
const reportChart = document.getElementById('reportChart');
if (reportChart && typeof Chart !== 'undefined') new Chart(reportChart, {type:'bar', data:{labels:<?php echo json_encode($chart_labels); ?>, datasets:[{label:'Revenue',data:<?php echo json_encode($chart_revenue); ?>,backgroundColor:'#10b981'},{label:'Cost',data:<?php echo json_encode($chart_cost); ?>,backgroundColor:'#f87171'},{label:'Conversions',data:<?php echo json_encode($chart_conversions); ?>,backgroundColor:'#0f766e'},{label:'Rejected Leads',data:<?php echo json_encode($chart_rejected); ?>,backgroundColor:'#f59e0b'}]}, options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true}}}});
</script>
<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
