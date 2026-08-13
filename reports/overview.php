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

<style>
    /* ─── Page header ───────────────────────────────────────── */
    .report-hero {
        background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
        border-radius: 1rem;
        padding: 1rem 1.5rem;
        color: #fff;
        margin-bottom: 1.25rem;
    }
    .report-hero .hero-pill {
        display: inline-flex; align-items: center; gap: .375rem;
        background: rgba(255, 255, 255, .12); backdrop-filter: blur(6px);
        padding: .375rem .75rem; border-radius: 9999px;
        font-size: .75rem; font-weight: 500;
    }
    .report-hero .hero-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: #10b981; box-shadow: 0 0 8px #10b981; }

    /* ─── Filter bar ────────────────────────────────────────── */
    .filter-bar {
        background: #fff;
        border-radius: .75rem;
        border: 1px solid #e2e8f0;
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
    }
    .filter-bar label.form-label {
        font-size: .7rem; text-transform: uppercase; letter-spacing: .06em;
        font-weight: 600; color: #64748b; margin-bottom: .375rem;
    }
    .filter-bar .form-control,
    .filter-bar .form-select {
        border: 1px solid #e2e8f0; border-radius: .5rem; font-size: .875rem;
        transition: border-color .15s ease, box-shadow .15s ease;
    }
    .filter-bar .form-control:focus,
    .filter-bar .form-select:focus {
        border-color: #818cf8; box-shadow: 0 0 0 3px rgba(99, 102, 241, .12);
    }
    .filter-bar .btn { border-radius: .5rem; font-size: .875rem; font-weight: 500; }

    /* ─── All-time switch card ───────────────────────────────── */
    .form-switch-card {
        display: flex; align-items: center; gap: .5rem;
        height: calc(2.25rem + 2px); /* match form-control height */
        padding: 0 .875rem;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: .5rem;
        cursor: pointer;
        transition: all .15s ease;
        user-select: none;
    }
    .form-switch-card .form-switch-input {
        width: 2.25rem; height: 1.25rem;
        margin: 0; cursor: pointer;
        background-color: #cbd5e1;
        border-color: #cbd5e1;
    }
    .form-switch-card .form-switch-input:checked {
        background-color: #4f46e5;
        border-color: #4f46e5;
    }
    .form-switch-card .form-switch-input:focus {
        box-shadow: 0 0 0 3px rgba(99, 102, 241, .2);
        border-color: #818cf8;
    }
    .form-switch-card .form-switch-label {
        font-size: .8125rem; font-weight: 600; color: #475569;
        cursor: pointer; margin: 0; display: flex; align-items: center; gap: .375rem;
        white-space: nowrap;
    }
    .form-switch-card.is-active .form-switch-label { color: #4338ca; }
    .form-switch-card .form-switch-label i { font-size: 1rem; }

    /* ─── Stat cards ────────────────────────────────────────── */
    .kpi-card {
        border: 1px solid #e2e8f0;
        border-radius: .875rem;
        background: #fff;
        padding: 1rem 1.125rem;
        height: 100%;
        transition: transform .18s ease, box-shadow .18s ease;
        position: relative;
        overflow: hidden;
    }
    .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px -4px rgba(15, 23, 42, .08); }
    .kpi-card .kpi-icon {
        width: 2.25rem; height: 2.25rem; border-radius: .5rem;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem; flex-shrink: 0;
    }
    .kpi-card .kpi-label {
        font-size: .6875rem; text-transform: uppercase; letter-spacing: .05em;
        font-weight: 600; color: #64748b; margin: 0 0 .25rem 0;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .kpi-card .kpi-value {
        font-size: 1.375rem; font-weight: 700; color: #0f172a; line-height: 1.1;
        margin: 0; letter-spacing: -0.01em;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .kpi-card .kpi-value.is-currency { font-size: 1.25rem; }
    .kpi-card .kpi-meta { font-size: .75rem; color: #64748b; margin-top: .25rem; }
    .kpi-card.kpi-conversions .kpi-icon { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1d4ed8; }
    .kpi-card.kpi-clicks .kpi-icon { background: linear-gradient(135deg, #e0e7ff, #c7d2fe); color: #4338ca; }
    .kpi-card.kpi-ccr .kpi-icon { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #b45309; }
    .kpi-card.kpi-revenue .kpi-icon { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #047857; }
    .kpi-card.kpi-cost .kpi-icon { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #b91c1c; }
    .kpi-card.kpi-profit .kpi-icon { background: linear-gradient(135deg, #ddd6fe, #c4b5fd); color: #5b21b6; }
    .kpi-card.kpi-profit.is-positive { background: linear-gradient(135deg, #ecfdf5 0%, #fff 60%); border-color: #a7f3d0; }
    .kpi-card.kpi-profit.is-negative { background: linear-gradient(135deg, #fef2f2 0%, #fff 60%); border-color: #fecaca; }
    .kpi-card .kpi-trend { display: inline-flex; align-items: center; gap: .25rem; font-size: .75rem; font-weight: 600; }
    .kpi-card .kpi-text { min-width: 0; flex: 1 1 0; }

    /* Compact KPI variant — used for secondary metrics */
    .kpi-card.kpi-sm { padding: .75rem 1rem; background: #f8fafc; }
    .kpi-card.kpi-sm .kpi-icon { width: 1.875rem; height: 1.875rem; font-size: .875rem; }
    .kpi-card.kpi-sm .kpi-label { font-size: .65rem; margin-bottom: 0; }
    .kpi-card.kpi-sm .kpi-value { font-size: 1.125rem; }
    .kpi-card.kpi-sm .kpi-value.is-currency { font-size: 1.05rem; }

    /* ─── Chart card ────────────────────────────────────────── */
    .chart-card { border: 1px solid #e2e8f0; border-radius: .875rem; background: #fff; }
    .chart-card .chart-header {
        padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9;
        display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .75rem;
    }
    .chart-card .chart-header h5 { font-weight: 600; margin: 0; }
    .chart-card .chart-legend { display: flex; gap: 1rem; align-items: center; }
    .chart-card .chart-legend .legend-dot {
        display: inline-flex; align-items: center; gap: .375rem;
        font-size: .8125rem; color: #475569; font-weight: 500;
    }
    .chart-card .chart-legend .legend-dot::before {
        content: ''; width: 10px; height: 10px; border-radius: 50%;
    }
    .chart-card .chart-legend .legend-dot.legend-revenue::before { background: #10b981; }
    .chart-card .chart-legend .legend-dot.legend-cost::before { background: #ef4444; }
    .chart-card .chart-legend .legend-dot.legend-profit::before { background: #4f46e5; }

    /* ─── Chart toolbar (legend + view toggle) ─────────────── */
    .chart-toolbar { display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; }
    .chart-view-toggle {
        display: inline-flex; background: #f1f5f9; border-radius: .5rem;
        padding: 3px; border: 1px solid #e2e8f0;
    }
    .chart-view-toggle .view-btn {
        border: 0; background: transparent;
        padding: .375rem .625rem; border-radius: .375rem;
        font-size: .875rem; color: #64748b;
        cursor: pointer; transition: all .15s ease;
        display: inline-flex; align-items: center; gap: .25rem;
    }
    .chart-view-toggle .view-btn:hover { color: #0f172a; }
    .chart-view-toggle .view-btn.is-active {
        background: #fff; color: #4f46e5;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .08);
    }
    .chart-view-toggle .view-btn i { font-size: 1rem; }

    /* ─── Tables ────────────────────────────────────────────── */
    .data-card { border: 1px solid #e2e8f0; border-radius: .875rem; background: #fff; overflow: hidden; }
    .data-card .data-header {
        padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9;
        display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .75rem;
    }
    .data-card .data-header h5 { font-weight: 600; margin: 0; }
    .table-reports thead th {
        font-size: .7rem; letter-spacing: .06em; text-transform: uppercase;
        color: #64748b; font-weight: 600; background: #f8fafc;
        border-bottom: 1px solid #e2e8f0; padding: .75rem 1rem;
    }
    .table-reports tbody td {
        vertical-align: middle; padding: 1rem;
        border-bottom: 1px solid #f1f5f9;
    }
    .table-reports tbody tr:last-child td { border-bottom: 0; }
    .table-reports tbody tr { transition: background-color .15s ease; }
    .table-reports tbody tr:hover { background: #f8fafc; }
    .table-reports .project-cell strong { color: #0f172a; font-weight: 600; }
    .table-reports .project-cell .proj-name { font-size: .8125rem; color: #64748b; margin-top: .125rem; }
    .table-reports .margin-pill {
        display: inline-flex; align-items: center; gap: .25rem;
        padding: .25rem .5rem; border-radius: 9999px;
        font-size: .75rem; font-weight: 600;
    }
    .margin-pill.is-positive { background: #d1fae5; color: #047857; }
    .margin-pill.is-neutral  { background: #fef3c7; color: #b45309; }
    .margin-pill.is-negative { background: #fee2e2; color: #b91c1c; }
    .table-reports .empty-state { padding: 3rem 1rem; text-align: center; }
    .table-reports .empty-state .empty-icon {
        width: 4rem; height: 4rem; border-radius: 1rem;
        background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
        color: #94a3b8; font-size: 1.75rem;
        display: inline-flex; align-items: center; justify-content: center;
        margin-bottom: 1rem;
    }

    /* Preset pills */
    .preset-pills { display: flex; flex-wrap: wrap; gap: .5rem; }
    .preset-pill {
        border: 1px solid #e2e8f0; background: #f8fafc; color: #475569; border-radius: 9999px;
        padding: .25rem .75rem; font-size: .75rem; font-weight: 600; cursor: pointer; transition: all .15s ease;
    }
    .preset-pill:hover { border-color: #c7d2fe; background: #eef2ff; color: #4338ca; }
    .preset-pill.active { background: #4f46e5; color: #fff; border-color: #4f46e5; box-shadow: 0 2px 6px rgba(79,70,229,.2); }

    /* Segmented tab control (Project / Vendor toggle) */
    .tf-segmented {
        display: inline-flex; background: #f1f5f9; border-radius: .5rem;
        padding: 3px; border: 1px solid #e2e8f0;
    }
    .tf-segmented-btn {
        padding: .35rem .75rem; border-radius: .375rem;
        font-size: .8125rem; color: #64748b; text-decoration: none;
        font-weight: 500; transition: all .15s ease;
    }
    .tf-segmented-btn:hover { color: #0f172a; text-decoration: none; }
    .tf-segmented-btn.is-active {
        background: #fff; color: #4f46e5;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .08);
    }

    /* Stack chart toolbar items on small screens */
    @media (max-width: 767.98px) {
        .chart-toolbar { flex-direction: column; align-items: flex-start !important; gap: .5rem; }
        .chart-view-toggle { align-self: stretch; justify-content: center; }
    }
</style>

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
                <label class="form-label small fw-semibold text-secondary">Date Presets</label>
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
            <div class="chart-view-toggle" role="tablist">
                <button type="button" class="view-btn is-active" data-view="bar">
                    <i class="bi bi-bar-chart-fill"></i>
                </button>
                <button type="button" class="view-btn" data-view="line">
                    <i class="bi bi-graph-up"></i>
                </button>
            </div>
        </div>
    </div>
    <div class="card-body p-4">
        <div style="height: 360px; position: relative;">
            <canvas id="revenueChart"></canvas>
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
            { label: 'Profit',  data: profitData, type: 'line', borderColor: '#4f46e5', backgroundColor: 'rgba(79, 70, 229, .08)', borderWidth: 2, tension: 0.35, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#4f46e5', pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2, fill: false, order: 1 }
        ];
    }
    return [
        { label: 'Revenue', data: revData,  borderColor: '#10b981', backgroundColor: revenueLine, fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#10b981', pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2, borderWidth: 2.5, order: 2 },
        { label: 'Cost',    data: costData, borderColor: '#ef4444', backgroundColor: costLine,    fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#ef4444', pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2, borderWidth: 2.5, order: 3 },
        { label: 'Profit',  data: profitData, borderColor: '#4f46e5', backgroundColor: 'rgba(79, 70, 229, .05)', fill: false, tension: 0.4, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#4f46e5', pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2, borderWidth: 2, borderDash: [4, 4], order: 1 }
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
            borderColor: 'rgba(99, 102, 241, .35)',
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
