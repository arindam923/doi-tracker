<?php
require_once __DIR__ . '/../config.php';
require_login();

$all_time = !empty($_GET['all_time']) || ($_GET['preset'] ?? '') === 'all';
// Accept both project_id and id (defensive — some links pass ?id=)
$project_filter = intval($_GET['project_id'] ?? $_GET['id'] ?? 0);
$breakdown_group = $_GET['group'] ?? 'project';
if (!in_array($breakdown_group, ['project','vendor'], true)) $breakdown_group = 'project';

// Date preset
$preset = $_GET['preset'] ?? '30d';
$today_d = date('Y-m-d');
$this_week_start = date('Y-m-d', strtotime('monday this week'));
$this_week_end   = date('Y-m-d', strtotime('sunday this week'));
$last_week_start = date('Y-m-d', strtotime('monday last week'));
$last_week_end   = date('Y-m-d', strtotime('sunday last week'));
$last_month_start = date('Y-m-01', strtotime('first day of last month'));
$last_month_end   = date('Y-m-t', strtotime('last day of last month'));

if ($preset === 'today') { $from_date = $today_d; $to_date = $today_d; }
elseif ($preset === 'yesterday') { $from_date = date('Y-m-d', strtotime('-1 day')); $to_date = $from_date; }
elseif ($preset === '7d') { $from_date = date('Y-m-d', strtotime('-7 days')); $to_date = $today_d; }
elseif ($preset === '30d') { $from_date = date('Y-m-d', strtotime('-30 days')); $to_date = $today_d; }
elseif ($preset === 'thisweek') { $from_date = $this_week_start; $to_date = $this_week_end; }
elseif ($preset === 'lastweek') { $from_date = $last_week_start; $to_date = $last_week_end; }
elseif ($preset === 'thismonth') { $from_date = date('Y-m-01'); $to_date = $today_d; }
elseif ($preset === 'lastmonth') { $from_date = $last_month_start; $to_date = $last_month_end; }
elseif ($preset === 'all') { $all_time = true; $from_date = '2000-01-01'; $to_date = $today_d; }
else { $preset = 'custom'; }

// Smart default: when a project is selected, use its start_date if available
$default_from = date('Y-m-d', strtotime('-30 days'));
if ($project_filter) {
    $proj_lookup = $pdo->prepare("SELECT start_date, created_at FROM projects WHERE id = ?");
    $proj_lookup->execute([$project_filter]);
    $proj_row = $proj_lookup->fetch();
    if ($proj_row && !empty($proj_row['start_date'])) {
        $default_from = $proj_row['start_date'];
    } elseif ($proj_row && !empty($proj_row['created_at'])) {
        // Project has no start_date — fall back to creation date
        $default_from = date('Y-m-d', strtotime($proj_row['created_at']));
    }
}

$from_raw = $_GET['from'] ?? $default_from;
$to_raw   = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_raw)) $from_raw = $default_from;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_raw))   $to_raw   = date('Y-m-d');
$from_date = sanitize($from_raw);
$to_date   = sanitize($to_raw);
if (strtotime($to_raw) < strtotime($from_raw)) {
    $to_date = $from_date;
}

// Preset overrides manual dates unless user picked 'custom'
if (isset($_GET['preset']) && $_GET['preset'] !== 'custom') {
    // keep the preset-set from/to
}

// "All time" mode: ignore the date filter in SQL by using a wide range
if ($all_time) {
    $from_date = '2000-01-01';
    $to_date   = date('Y-m-d');
}

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

// Rejected count + clicks for EPC
$rej = $pdo->prepare("SELECT COUNT(*) as cnt FROM conversions c WHERE c.status = 'complete' AND c.approval_status = 'rejected' $where_date");
$rej_params = $params_date;
if ($project_filter) $rej_params[] = $project_filter;
$rej->execute($rej_params);
$totals['rejected'] = (int)$rej->fetch()['cnt'];

$sql = "SELECT COUNT(*) as cnt FROM clicks WHERE clicked_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)";
$params_clicks = [$from_date, $to_date];
if ($project_filter) {
    $sql .= " AND project_id = ?";
    $params_clicks[] = $project_filter;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params_clicks);
$total_clicks = (int)($stmt->fetch()['cnt'] ?? 0);

$profit_margin = $totals['revenue'] > 0 ? round(($totals['profit'] / $totals['revenue']) * 100, 1) : 0;
$roi = $totals['cost'] > 0 ? round((($totals['revenue'] - $totals['cost']) / $totals['cost']) * 100, 1) : 0;
$epc = $total_clicks > 0 ? round($totals['revenue'] / $total_clicks, 4) : 0;

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

$sql = "SELECT gv.vendor_name, pv.payout AS vendor_cpi,
        COUNT(c.id) as completes, COALESCE(SUM(c.client_revenue),0) as revenue,
        COALESCE(SUM(c.vendor_cost),0) as cost, COALESCE(SUM(c.profit),0) as profit
        FROM conversions c
        JOIN global_vendors gv ON c.vendor_id = gv.id
        LEFT JOIN project_vendor pv ON pv.vendor_id = c.vendor_id AND pv.project_id = c.project_id
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

// ─── Look up the project's start / last conversion date for the empty-state hint ───
$project_info = null;
if ($project_filter) {
    $pi = $pdo->prepare("SELECT p.project_name, p.project_code, p.start_date, p.status, (SELECT MAX(converted_at) FROM conversions WHERE project_id = p.id AND status='complete') AS last_conv FROM projects p WHERE p.id = ?");
    $pi->execute([$project_filter]);
    $project_info = $pi->fetch();
}

// ─── Chart data: cap visual range to 90 days max for performance ───
$chart_labels = [];
$chart_revenue = [];
$chart_cost = [];

$period_days = (strtotime($to_date) - strtotime($from_date)) / 86400;
if ($all_time || $period_days > 90) {
    $interval = max(1, (int)floor($period_days / 90));
} else {
    $interval = 1;
}

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


<!-- Hero Header -->
<div class="report-hero">
    <div class="d-flex align-items-center flex-wrap gap-2 position-relative" style="z-index: 1;">
        <span class="hero-pill"><span class="dot"></span><?php echo $all_time ? 'All time' : 'Reporting period'; ?></span>
        <?php if ($project_filter && $project_info): ?>
        <span class="hero-pill"><i class="bi bi-folder2-open"></i> <?php echo sanitize($project_info['project_code']); ?></span>
        <?php endif; ?>
        <span class="hero-pill" style="background: transparent; padding-left: 0;">
            <i class="bi bi-calendar3"></i>
            <?php if ($all_time): ?>
            All-time financial performance
            <?php else: ?>
            <?php echo date('M j', strtotime($from_date)); ?> – <?php echo date('M j, Y', strtotime($to_date)); ?>
            <?php endif; ?>
        </span>
    </div>
</div>

<!-- Date Filter -->
<div class="filter-bar">
    <form method="GET" id="reportFilterForm">
        <input type="hidden" name="preset" id="presetInput" value="<?php echo $preset; ?>">

        <div class="row g-2 mb-3">
            <div class="col-12">
                <label class="tf-label">Date Presets</label>
                <div class="preset-pills">
                    <?php
                    $preset_opts = [
                        'today' => 'Today',
                        'yesterday' => 'Yesterday',
                        '7d' => '7 Days',
                        'thisweek' => 'This Week',
                        'lastweek' => 'Last Week',
                        'thismonth' => 'This Month',
                        'lastmonth' => 'Last Month',
                        '30d' => '30 Days',
                        'all' => 'All Time',
                    ];
                    foreach ($preset_opts as $k => $label):
                        $active = ($preset === $k) || ($k === 'all' && $all_time);
                        $cls = $active ? 'active' : '';
                    ?>
                    <button type="button" class="preset-pill <?php echo $cls; ?>" data-preset="<?php echo $k; ?>"><?php echo $label; ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="row g-3 align-items-end">
            <div class="col-6 col-lg-2">
                <label for="from" class="form-label">From</label>
                <input type="date" id="from" name="from" class="form-control" value="<?php echo $all_time ? '' : $from_date; ?>" <?php echo $all_time ? 'disabled' : ''; ?>>
            </div>
            <div class="col-6 col-lg-2">
                <label for="to" class="form-label">To</label>
                <input type="date" id="to" name="to" class="form-control" value="<?php echo $all_time ? '' : $to_date; ?>" <?php echo $all_time ? 'disabled' : ''; ?>>
            </div>
            <div class="col-12 col-lg-3">
                <label for="project_id" class="form-label">Project</label>
                <select id="project_id" name="project_id" class="form-select">
                    <option value="">All projects</option>
                    <?php foreach ($projects_list as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $project_filter == $p['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($p['project_code'] . ' — ' . $p['project_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-lg-2">
                <label for="all_time_toggle" class="form-label d-none d-lg-block">&nbsp;</label>
                <div class="form-switch-card <?php echo $all_time ? 'is-active' : ''; ?>">
                    <input type="checkbox" id="all_time_toggle" name="all_time" value="1" class="form-switch-input" <?php echo $all_time ? 'checked' : ''; ?>>
                    <label for="all_time_toggle" class="form-switch-label">
                        <i class="bi bi-infinity"></i>
                        <span>All time</span>
                    </label>
                </div>
            </div>
            <div class="col-12 col-lg-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-funnel-fill"></i> Apply
                </button>
                <a href="<?php echo BASE_URL; ?>/reports/overview.php" class="btn btn-outline-secondary" title="Reset filters">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
                <a href="<?php echo BASE_URL; ?>/reports/export.php?from=<?php echo $from_date; ?>&to=<?php echo $to_date; ?>&project_id=<?php echo $project_filter; ?>" class="btn btn-outline-success" title="Download CSV">
                    <i class="bi bi-download"></i>
                </a>
            </div>
        </div>
    </form>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-3 align-items-stretch">
    <div class="col-6 col-lg-3">
        <div class="kpi-card kpi-revenue">
            <div class="d-flex align-items-center gap-2">
                <div class="kpi-icon"><i class="bi bi-arrow-up-circle-fill"></i></div>
                <div class="kpi-text">
                    <p class="kpi-label">Revenue</p>
                    <p class="kpi-value is-currency text-success"><?php echo format_currency($totals['revenue']); ?></p>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="kpi-card kpi-cost">
            <div class="d-flex align-items-center gap-2">
                <div class="kpi-icon"><i class="bi bi-arrow-down-circle-fill"></i></div>
                <div class="kpi-text">
                    <p class="kpi-label">Cost</p>
                    <p class="kpi-value is-currency text-danger"><?php echo format_currency($totals['cost']); ?></p>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="kpi-card kpi-profit <?php echo $totals['profit'] >= 0 ? 'is-positive' : 'is-negative'; ?>">
            <div class="d-flex align-items-center gap-2">
                <div class="kpi-icon"><i class="bi bi-wallet2"></i></div>
                <div class="kpi-text">
                    <p class="kpi-label">Profit</p>
                    <p class="kpi-value is-currency <?php echo $totals['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo format_currency($totals['profit']); ?></p>
                    <p class="kpi-meta"><?php echo $profit_margin; ?>% margin</p>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="kpi-card kpi-conversions">
            <div class="d-flex align-items-center gap-2">
                <div class="kpi-icon"><i class="bi bi-check-circle-fill"></i></div>
                <div class="kpi-text">
                    <p class="kpi-label">Conversions</p>
                    <p class="kpi-value"><?php echo number_format($totals['cnt']); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Secondary metrics (compact) -->
<div class="row g-2 mb-4 align-items-stretch">
    <div class="col-6 col-md-4">
        <div class="kpi-card kpi-sm kpi-clicks">
            <div class="d-flex align-items-center gap-2">
                <div class="kpi-icon"><i class="bi bi-cursor-fill"></i></div>
                <div class="kpi-text">
                    <p class="kpi-label">Clicks</p>
                    <p class="kpi-value"><?php echo number_format($total_clicks); ?></p>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="kpi-card kpi-sm kpi-ccr">
            <div class="d-flex align-items-center gap-2">
                <div class="kpi-icon"><i class="bi bi-percent"></i></div>
                <div class="kpi-text">
                    <p class="kpi-label">CCR</p>
                    <p class="kpi-value"><?php echo $ccr; ?>%</p>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="kpi-card kpi-sm">
            <div class="d-flex align-items-center gap-2">
                <div class="kpi-icon" style="background: linear-gradient(135deg, #fce7f3, #fbcfe8); color: #9d174d;"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="kpi-text">
                    <p class="kpi-label">ROI</p>
                    <p class="kpi-value <?php echo $roi >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo $roi; ?>%</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart -->
<div class="chart-card mb-4">
    <div class="chart-header">
        <div>
            <h5>Revenue vs Cost</h5>
            <p class="small text-secondary mb-0">Daily breakdown across the selected period</p>
        </div>
        <div class="chart-toolbar">
            <div class="chart-legend">
                <span class="legend-dot legend-revenue">Revenue</span>
                <span class="legend-dot legend-cost">Cost</span>
                <span class="legend-dot legend-profit">Profit</span>
            </div>
            <div class="chart-view-toggle" role="tablist" aria-label="Chart view">
                <button type="button" class="view-btn is-active" data-view="bar" role="tab" aria-selected="true" aria-label="Bar chart">
                    <i class="bi bi-bar-chart-fill" aria-hidden="true"></i>
                </button>
                <button type="button" class="view-btn" data-view="line" role="tab" aria-selected="false" aria-label="Line chart">
                    <i class="bi bi-graph-up" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>
    <div class="card-body p-4">
        <div class="tf-chart-body" style="height: 360px;">
            <canvas id="revenueChart" role="img" aria-label="Revenue, cost, and profit over the selected period"></canvas>
        </div>
    </div>
</div>

<!-- Breakdown (Project / Vendor) -->
<?php
$rows = $breakdown_group === 'vendor' ? $vendor_breakdown : $project_breakdown;
$row_label = $breakdown_group === 'vendor' ? 'Vendor' : 'Project';
$row_label_plural = $breakdown_group === 'vendor' ? 'vendors' : 'projects';
$row_count = count($rows);
$empty_title = $breakdown_group === 'vendor' ? 'No vendor data in this period' : 'No conversion data in this period';
$empty_icon = $breakdown_group === 'vendor' ? 'bi-people' : 'bi-bar-chart';
$empty_hint = $breakdown_group === 'vendor'
    ? 'Enable <em>All time</em> or widen the date range to see historical vendor performance.'
    : 'Try the <em>All time</em> toggle, or pick a wider date range.';
?>
<div class="data-card mb-4">
    <div class="data-header">
        <div>
            <h5>Revenue by <?php echo $row_label; ?></h5>
            <p class="small text-secondary mb-0"><?php echo $breakdown_group === 'vendor' ? 'Top traffic sources by profitability' : 'Performance breakdown for each campaign'; ?></p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php
            // Build tab URLs that preserve current filters
            $base_params = $_GET;
            $tab_url = function($g) use ($base_params) {
                $base_params['group'] = $g;
                return '?' . http_build_query($base_params);
            };
            ?>
            <div class="tf-segmented" role="tablist">
                <a href="<?php echo $tab_url('project'); ?>" class="tf-segmented-btn <?php echo $breakdown_group === 'project' ? 'is-active' : ''; ?>" role="tab">Project</a>
                <a href="<?php echo $tab_url('vendor'); ?>" class="tf-segmented-btn <?php echo $breakdown_group === 'vendor' ? 'is-active' : ''; ?>" role="tab">Vendor</a>
            </div>
            <span class="badge bg-light text-dark border"><?php echo $row_count; ?> <?php echo $row_label_plural; ?></span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-reports align-middle mb-0">
            <thead>
                <tr>
                    <th><?php echo $row_label; ?></th>
                    <th class="text-end">Completes</th>
                    <th class="text-end">CPI</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">Cost</th>
                    <th class="text-end">Profit</th>
                    <th class="text-end">Margin</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="7" class="empty-state">
                        <div class="empty-icon mx-auto"><i class="bi <?php echo $empty_icon; ?>"></i></div>
                        <p class="fw-semibold text-dark mb-2"><?php echo $empty_title; ?></p>
                        <?php if ($breakdown_group === 'project' && $project_info): ?>
                        <ul class="list-unstyled small text-secondary mb-2 text-start mx-auto" style="max-width: 28rem;">
                            <?php if ($project_info['start_date']): ?>
                            <li class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-calendar-event text-muted"></i>
                                <span>Started <strong><?php echo sanitize($project_info['start_date']); ?></strong></span>
                            </li>
                            <?php endif; ?>
                            <?php if ($project_info['last_conv']): ?>
                            <li class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-clock-history text-muted"></i>
                                <span>Last conversion: <strong><?php echo sanitize(substr($project_info['last_conv'], 0, 10)); ?></strong></span>
                            </li>
                            <?php else: ?>
                            <li class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-info-circle text-muted"></i>
                                <span>No conversions yet</span>
                            </li>
                            <?php endif; ?>
                        </ul>
                        <?php endif; ?>
                        <p class="small text-secondary mb-0"><?php echo $empty_hint; ?></p>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($rows as $r):
                    $row_name = $breakdown_group === 'vendor' ? $r['vendor_name'] : $r['project_name'];
                    $row_code = $breakdown_group === 'vendor' ? null : $r['project_code'];
                    $row_cpi = $breakdown_group === 'vendor' ? $r['vendor_cpi'] : $r['client_cpi'];
                    $margin = $r['revenue'] > 0 ? round(($r['profit'] / $r['revenue']) * 100, 1) : 0;
                    $margin_class = $margin >= 20 ? 'is-positive' : ($margin < 0 ? 'is-negative' : 'is-neutral');
                ?>
                <tr>
                    <td class="project-cell">
                        <?php if ($row_code): ?>
                        <strong><?php echo sanitize($row_code); ?></strong>
                        <div class="proj-name"><?php echo sanitize($row_name); ?></div>
                        <?php else: ?>
                        <strong><?php echo sanitize($row_name); ?></strong>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?php echo number_format($r['completes']); ?></td>
                    <td class="text-end text-secondary"><?php echo format_currency($row_cpi); ?></td>
                    <td class="text-end fw-semibold text-success"><?php echo format_currency($r['revenue']); ?></td>
                    <td class="text-end text-danger"><?php echo format_currency($r['cost']); ?></td>
                    <td class="text-end fw-semibold <?php echo $r['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo format_currency($r['profit']); ?></td>
                    <td class="text-end">
                        <span class="margin-pill <?php echo $margin_class; ?>"><?php echo $margin; ?>%</span>
                    </td>
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
Chart.defaults.borderColor = '#f1f5f9';

const revLabels = {$cl_json};
const revData = {$cr_json};
const costData = {$cc_json};
const profitData = revData.map((r, i) => +(r - costData[i]).toFixed(2));

const fmtUSD = (v) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(v);
const axisFmt = (v) => v >= 1000 ? '$' + (v/1000).toFixed(1) + 'k' : '$' + v;

// Color helpers
const revenueBar = (ctx) => {
    const c = ctx.chart.ctx;
    const g = c.createLinearGradient(0, 0, 0, 320);
    g.addColorStop(0, 'rgba(16, 185, 129, .95)');
    g.addColorStop(1, 'rgba(16, 185, 129, .55)');
    return g;
};
const costBar = (ctx) => {
    const c = ctx.chart.ctx;
    const g = c.createLinearGradient(0, 0, 0, 320);
    g.addColorStop(0, 'rgba(239, 68, 68, .85)');
    g.addColorStop(1, 'rgba(239, 68, 68, .45)');
    return g;
};
const revenueLine = (ctx) => {
    const c = ctx.chart.ctx;
    const g = c.createLinearGradient(0, 0, 0, 320);
    g.addColorStop(0, 'rgba(16, 185, 129, .28)');
    g.addColorStop(1, 'rgba(16, 185, 129, 0)');
    return g;
};
const costLine = (ctx) => {
    const c = ctx.chart.ctx;
    const g = c.createLinearGradient(0, 0, 0, 320);
    g.addColorStop(0, 'rgba(239, 68, 68, .22)');
    g.addColorStop(1, 'rgba(239, 68, 68, 0)');
    return g;
};

const sharedDatasets = (view) => {
    if (view === 'bar') {
        return [
            { label: 'Revenue', data: revData,  backgroundColor: revenueBar, hoverBackgroundColor: '#059669', borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: false, barPercentage: 0.7, categoryPercentage: 0.7, order: 2 },
            { label: 'Cost',    data: costData, backgroundColor: costBar,    hoverBackgroundColor: '#dc2626', borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: false, barPercentage: 0.7, categoryPercentage: 0.7, order: 3 },
            { label: 'Profit',  data: profitData, type: 'line', borderColor: '#0f766e', backgroundColor: 'rgba(15, 118, 110, .08)', borderWidth: 2, tension: 0.35, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#0f766e', pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2, fill: false, order: 1 }
        ];
    }
    return [
        { label: 'Revenue', data: revData,  borderColor: '#10b981', backgroundColor: revenueLine, fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#10b981', pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2, borderWidth: 2.5, order: 2 },
        { label: 'Cost',    data: costData, borderColor: '#ef4444', backgroundColor: costLine,    fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#ef4444', pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2, borderWidth: 2.5, order: 3 },
        { label: 'Profit',  data: profitData, borderColor: '#0f766e', backgroundColor: 'rgba(15, 118, 110, .05)', fill: false, tension: 0.4, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#0f766e', pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2, borderWidth: 2, borderDash: [4, 4], order: 1 }
    ];
};

const baseOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    animation: { duration: 600, easing: 'easeOutCubic' },
    plugins: {
        legend: { display: false },
        tooltip: {
            enabled: true,
            backgroundColor: 'rgba(15, 23, 42, .96)',
            titleColor: '#f8fafc',
            titleFont: { size: 12, weight: '600' },
            bodyColor: '#cbd5e1',
            bodyFont: { size: 13 },
            padding: { top: 10, right: 12, bottom: 10, left: 12 },
            cornerRadius: 10,
            borderColor: 'rgba(20, 184, 166, .35)',
            borderWidth: 1,
            displayColors: true,
            boxWidth: 8,
            boxHeight: 8,
            usePointStyle: true,
            boxPadding: 4,
            callbacks: {
                label: (ctx) => '  ' + ctx.dataset.label + ':  ' + fmtUSD(ctx.parsed.y)
            }
        }
    },
    scales: {
        x: {
            grid: { display: false, drawBorder: false },
            ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 16, padding: 8, color: '#94a3b8', font: { size: 11 } }
        },
        y: {
            beginAtZero: true,
            grid: { color: 'rgba(148, 163, 184, .14)', drawBorder: false, tickLength: 0 },
            border: { display: false },
            ticks: { padding: 12, color: '#94a3b8', font: { size: 11 }, callback: axisFmt }
        }
    }
};

let currentView = 'bar';
let revChart = null;
const revCtx = document.getElementById('revenueChart');

function renderChart(view) {
    if (revChart) revChart.destroy();
    revChart = new Chart(revCtx, {
        type: view === 'bar' ? 'bar' : 'line',
        data: { labels: revLabels, datasets: sharedDatasets(view) },
        options: baseOptions
    });
}

renderChart('bar');

// View toggle
document.querySelectorAll('.chart-view-toggle .view-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.chart-view-toggle .view-btn').forEach(b => b.classList.remove('is-active'));
        btn.classList.add('is-active');
        renderChart(btn.dataset.view);
    });
});

// Preset pills submit the filter form
const form = document.getElementById('reportFilterForm');
const presetInput = document.getElementById('presetInput');
document.querySelectorAll('.preset-pill').forEach(btn => {
    btn.addEventListener('click', () => {
        if (btn.dataset.preset === 'all') {
            // All time is handled by the checkbox; set preset=all as a marker
            presetInput.value = 'all';
            const cb = document.getElementById('all_time_toggle');
            if (cb) cb.checked = true;
        } else {
            presetInput.value = btn.dataset.preset;
        }
        form.submit();
    });
});
</script>
EOT;

require_once __DIR__ . '/../helpers/layout_footer.php';
?>
