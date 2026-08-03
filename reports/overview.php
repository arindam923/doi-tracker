<?php
require_once __DIR__ . '/../config.php';
require_login();

$from_raw = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to_raw   = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_raw)) $from_raw = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_raw))   $to_raw   = date('Y-m-d');
$from_date = sanitize($from_raw);
$to_date   = sanitize($to_raw);
if (strtotime($to_raw) < strtotime($from_raw)) {
    $to_date = $from_date;
}
$project_filter = intval($_GET['project_id'] ?? 0);

$where_date = "AND c.converted_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)";
$params_date = [$from_date, $to_date];

$sql = "SELECT COUNT(*) as cnt, COALESCE(SUM(client_revenue),0) as revenue, COALESCE(SUM(vendor_cost),0) as cost, COALESCE(SUM(profit),0) as profit FROM conversions c WHERE c.status = 'complete' $where_date";
if ($project_filter) {
    $sql .= " AND c.project_id = ?";
    $params_date[] = $project_filter;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params_date);
$totals = $stmt->fetch() ?: ['cnt' => 0, 'revenue' => 0, 'cost' => 0, 'profit' => 0];

$totals['cnt']     = (int)($totals['cnt'] ?? 0);
$totals['revenue'] = (float)($totals['revenue'] ?? 0);
$totals['cost']    = (float)($totals['cost'] ?? 0);
$totals['profit']  = (float)($totals['profit'] ?? 0);

$profit_margin = $totals['revenue'] > 0 ? round(($totals['profit'] / $totals['revenue']) * 100, 1) : 0;

$sql = "SELECT COUNT(*) as cnt FROM clicks WHERE clicked_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)";
$params_clicks = [$from_date, $to_date];
if ($project_filter) {
    $sql .= " AND project_id = ?";
    $params_clicks[] = $project_filter;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params_clicks);
$total_clicks = (int)($stmt->fetch()['cnt'] ?? 0);

$ccr = calc_ccr($totals['cnt'], $total_clicks);

$sql = "SELECT p.project_code, p.project_name, p.client_cpi,
        COUNT(c.id) as completes, COALESCE(SUM(c.client_revenue),0) as revenue,
        COALESCE(SUM(c.vendor_cost),0) as cost, COALESCE(SUM(c.profit),0) as profit
        FROM conversions c
        JOIN projects p ON c.project_id = p.id
        WHERE c.status = 'complete' $where_date";
$params_proj = $params_date;
if ($project_filter) {
    $sql .= " AND c.project_id = ?";
    $params_proj[] = $project_filter;
}
$sql .= " GROUP BY c.project_id ORDER BY revenue DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params_proj);
$project_breakdown = $stmt->fetchAll();

$sql = "SELECT v.vendor_name, v.vendor_cpi,
        COUNT(c.id) as completes, COALESCE(SUM(c.client_revenue),0) as revenue,
        COALESCE(SUM(c.vendor_cost),0) as cost, COALESCE(SUM(c.profit),0) as profit
        FROM conversions c
        JOIN vendors v ON c.vendor_id = v.id
        WHERE c.status = 'complete' $where_date";
$params_vend = $params_date;
if ($project_filter) {
    $sql .= " AND c.project_id = ?";
    $params_vend[] = $project_filter;
}
$sql .= " GROUP BY c.vendor_id ORDER BY revenue DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params_vend);
$vendor_breakdown = $stmt->fetchAll();

$chart_labels = [];
$chart_revenue = [];
$chart_cost = [];

$period_days = (strtotime($to_date) - strtotime($from_date)) / 86400;
$interval = $period_days > 60 ? 7 : 1;

$current = strtotime($from_date);
$end = strtotime($to_date);
while ($current <= $end) {
    $d = date('Y-m-d', $current);
    $chart_labels[] = date('M d', $current);

    $sql = "SELECT COALESCE(SUM(client_revenue),0) as rev, COALESCE(SUM(vendor_cost),0) as cost FROM conversions WHERE status = 'complete' AND DATE(converted_at) = ?";
    $params_d = [$d];
    if ($project_filter) { $sql .= " AND project_id = ?"; $params_d[] = $project_filter; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params_d);
    $row = $stmt->fetch() ?: ['rev' => 0, 'cost' => 0];
    $chart_revenue[] = (float)($row['rev'] ?? 0);
    $chart_cost[] = (float)($row['cost'] ?? 0);

    $current += 86400 * $interval;
}

$projects_list = $pdo->query("SELECT id, project_code, project_name FROM projects ORDER BY project_name")->fetchAll();

$page_title = 'Revenue Report';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<style>
    .stat-tile { padding: 1rem 1.25rem; }
    .stat-tile .stat-label { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: #64748b; font-weight: 600; margin-bottom: .5rem; }
    .stat-tile .stat-value { font-size: 1.5rem; font-weight: 700; color: #0f172a; line-height: 1.1; }
    .table-reports thead th { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; color: #64748b; font-weight: 600; background: #f8fafc; }
    .table-reports tbody td { vertical-align: middle; padding: .85rem 1rem; }
    .table-reports code { background: #f1f5f9; color: #475569; padding: .125rem .5rem; border-radius: 4px; font-size: .75rem; }
</style>

<!-- Date Filter -->
<div class="card border-0 shadow-sm mb-4">
    <form method="GET" class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label for="from" class="form-label small fw-semibold text-secondary">From</label>
                <input type="date" id="from" name="from" class="form-control" value="<?php echo $from_date; ?>">
            </div>
            <div class="col-12 col-md-3">
                <label for="to" class="form-label small fw-semibold text-secondary">To</label>
                <input type="date" id="to" name="to" class="form-control" value="<?php echo $to_date; ?>">
            </div>
            <div class="col-12 col-md-4">
                <label for="project_id" class="form-label small fw-semibold text-secondary">Project</label>
                <select id="project_id" name="project_id" class="form-select">
                    <option value="">All Projects</option>
                    <?php foreach ($projects_list as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $project_filter == $p['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($p['project_code'] . ' — ' . $p['project_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex justify-content-end gap-2">
                <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i>Filter</button>
                <a href="<?php echo BASE_URL; ?>/reports/export.php?from=<?php echo $from_date; ?>&to=<?php echo $to_date; ?>&project_id=<?php echo $project_filter; ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-download"></i></a>
            </div>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 stat-tile">
            <div class="stat-label">Conversions</div>
            <div class="stat-value"><?php echo number_format($totals['cnt']); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 stat-tile">
            <div class="stat-label">Clicks</div>
            <div class="stat-value"><?php echo number_format($total_clicks); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 stat-tile">
            <div class="stat-label">CCR</div>
            <div><span class="badge bg-<?php echo ccr_color($ccr); ?>" style="font-size: 1rem; padding: .4rem .8rem;"><?php echo $ccr; ?>%</span></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 stat-tile">
            <div class="stat-label">Revenue</div>
            <div class="stat-value text-success" style="font-size: 1.25rem;"><?php echo format_currency($totals['revenue']); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 stat-tile">
            <div class="stat-label">Cost</div>
            <div class="stat-value text-danger" style="font-size: 1.25rem;"><?php echo format_currency($totals['cost']); ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm h-100 stat-tile">
            <div class="stat-label">Profit</div>
            <div class="stat-value <?php echo $totals['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>" style="font-size: 1.25rem;"><?php echo format_currency($totals['profit']); ?></div>
            <div class="small text-muted mt-1"><?php echo $profit_margin; ?>% margin</div>
        </div>
    </div>
</div>

<!-- Chart -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-3">
        <h5 class="mb-0 fw-semibold">Revenue vs Cost Over Time</h5>
    </div>
    <div class="card-body">
        <div style="height: 280px;">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>
</div>

<!-- Per-Project Breakdown -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-3">
        <h5 class="mb-0 fw-semibold">Revenue by Project</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-reports align-middle mb-0">
            <thead>
                <tr>
                    <th>Project</th>
                    <th class="text-end">Completes</th>
                    <th class="text-end">CPI</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">Cost</th>
                    <th class="text-end">Profit</th>
                    <th class="text-end">Margin</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($project_breakdown)): ?>
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                        <i class="bi bi-bar-chart d-block mb-2" style="font-size: 2.5rem; color: #cbd5e1;"></i>
                        <p class="fw-semibold text-dark mb-1">No data in this period</p>
                        <p class="small mb-0">Try expanding the date range or selecting a different project.</p>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($project_breakdown as $pb):
                    $margin = $pb['revenue'] > 0 ? round(($pb['profit'] / $pb['revenue']) * 100, 1) : 0;
                ?>
                <tr>
                    <td>
                        <strong><?php echo sanitize($pb['project_code']); ?></strong>
                        <div class="small text-muted mt-1"><?php echo sanitize($pb['project_name']); ?></div>
                    </td>
                    <td class="text-end"><?php echo number_format($pb['completes']); ?></td>
                    <td class="text-end"><?php echo format_currency($pb['client_cpi']); ?></td>
                    <td class="text-end fw-semibold text-success"><?php echo format_currency($pb['revenue']); ?></td>
                    <td class="text-end text-danger"><?php echo format_currency($pb['cost']); ?></td>
                    <td class="text-end fw-semibold <?php echo $pb['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo format_currency($pb['profit']); ?></td>
                    <td class="text-end"><?php echo $margin; ?>%</td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Per-Vendor Breakdown -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom py-3">
        <h5 class="mb-0 fw-semibold">Revenue by Vendor</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-reports align-middle mb-0">
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th class="text-end">Completes</th>
                    <th class="text-end">CPI</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">Cost</th>
                    <th class="text-end">Profit</th>
                    <th class="text-end">Margin</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendor_breakdown)): ?>
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                        <i class="bi bi-people d-block mb-2" style="font-size: 2.5rem; color: #cbd5e1;"></i>
                        <p class="fw-semibold text-dark mb-0">No vendor data in this period</p>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($vendor_breakdown as $vb):
                    $margin = $vb['revenue'] > 0 ? round(($vb['profit'] / $vb['revenue']) * 100, 1) : 0;
                ?>
                <tr>
                    <td><strong><?php echo sanitize($vb['vendor_name']); ?></strong></td>
                    <td class="text-end"><?php echo number_format($vb['completes']); ?></td>
                    <td class="text-end"><?php echo format_currency($vb['vendor_cpi']); ?></td>
                    <td class="text-end fw-semibold text-success"><?php echo format_currency($vb['revenue']); ?></td>
                    <td class="text-end text-danger"><?php echo format_currency($vb['cost']); ?></td>
                    <td class="text-end fw-semibold <?php echo $vb['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo format_currency($vb['profit']); ?></td>
                    <td class="text-end"><?php echo $margin; ?>%</td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$cl_json = json_encode($chart_labels);
$cr_json = json_encode($chart_revenue);
$cc_json = json_encode($chart_cost);

$extra_js = <<<EOT
<script>
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#64748b';

new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: {$cl_json},
        datasets: [
            { label: 'Revenue', data: {$cr_json}, borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.1)', fill: true, tension: 0.3, pointRadius: 3, pointHoverRadius: 5, borderWidth: 2 },
            { label: 'Cost', data: {$cc_json}, borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,0.1)', fill: true, tension: 0.3, pointRadius: 3, pointHoverRadius: 5, borderWidth: 2 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 8, padding: 20 } } },
        scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9', drawBorder: false } }, x: { grid: { display: false, drawBorder: false } } }
    }
});
</script>
EOT;

require_once __DIR__ . '/../helpers/layout_footer.php';
?>
