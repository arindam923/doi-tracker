<?php
require_once __DIR__ . '/../config.php';
$vendor = require_vendor_login($pdo);

$global_vendor_id = (int)$vendor['global_vendor_id'];

$project_filter = intval($_GET['project_id'] ?? 0);
$approval_filter = trim($_GET['approval'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 25;

$proj_sql = "SELECT p.id, p.project_code, p.project_name, pv.status AS pivot_status, pv.payout
             FROM project_vendor pv JOIN projects p ON p.id = pv.project_id
             WHERE pv.vendor_id = ? ORDER BY p.project_name";
$proj_stmt = $pdo->prepare($proj_sql);
$proj_stmt->execute([$global_vendor_id]);
$projects = $proj_stmt->fetchAll();

// ── KPIs ───────────────────────────
$kpi = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM clicks c WHERE c.vendor_id = ?) AS clicks,
        (SELECT COUNT(*) FROM conversions cc WHERE cc.vendor_id = ? AND cc.status='complete') AS conversions,
        (SELECT COUNT(*) FROM conversions cc WHERE cc.vendor_id = ? AND cc.status='complete' AND cc.approval_status='pending') AS pending,
        (SELECT COALESCE(SUM(cc.vendor_cost),0) FROM conversions cc WHERE cc.vendor_id = ? AND cc.status='complete') AS vendor_revenue
");
$kpi->execute([$global_vendor_id, $global_vendor_id, $global_vendor_id, $global_vendor_id]);
$k = $kpi->fetch();
$k['clicks'] = (int)($k['clicks'] ?? 0);
$k['conversions'] = (int)($k['conversions'] ?? 0);
$k['pending'] = (int)($k['pending'] ?? 0);
$k['ccr'] = calc_ccr($k['conversions'], $k['clicks']);
$show_network = get_setting($pdo, 'vendor_portal_show_network_economics', '0') === '1';
$k['network_cost'] = 0;
$k['network_profit'] = 0;
if ($show_network) {
    $econ = $pdo->prepare("
        SELECT COALESCE(SUM(cc.client_revenue),0) AS network_cost,
               COALESCE(SUM(cc.profit),0) AS network_profit
        FROM conversions cc WHERE cc.vendor_id = ? AND cc.status='complete'
    ");
    $econ->execute([$global_vendor_id]);
    $econ_row = $econ->fetch() ?: [];
    $k['network_cost'] = $econ_row['network_cost'] ?? 0;
    $k['network_profit'] = $econ_row['network_profit'] ?? 0;
}

// ── 7-day chart data ───────────────────────────
$chart = $pdo->prepare("
    SELECT DATE(c.clicked_at) AS day, COUNT(*) AS clicks
    FROM clicks c WHERE c.vendor_id = ? AND c.clicked_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(c.clicked_at)");
$chart->execute([$global_vendor_id]);
$chart_clicks = [];
foreach ($chart->fetchAll() as $r) $chart_clicks[$r['day']] = (int)$r['clicks'];

$chart2 = $pdo->prepare("
    SELECT DATE(cc.converted_at) AS day, COUNT(*) AS convs, COALESCE(SUM(cc.vendor_cost),0) AS rev
    FROM conversions cc WHERE cc.vendor_id = ? AND cc.status='complete' AND cc.converted_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(cc.converted_at)");
$chart2->execute([$global_vendor_id]);
$chart_conv = []; $chart_rev = [];
foreach ($chart2->fetchAll() as $r) {
    $chart_conv[$r['day']] = (int)$r['convs'];
    $chart_rev[$r['day']]  = (float)$r['rev'];
}

$labels = []; $clicks_series = []; $conv_series = []; $rev_series = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} day"));
    $labels[] = date('D', strtotime($d));
    $clicks_series[] = $chart_clicks[$d] ?? 0;
    $conv_series[]   = $chart_conv[$d] ?? 0;
    $rev_series[]    = round($chart_rev[$d] ?? 0, 2);
}

// ── Recent conversions ─────────────────────────────────────────
$wheres = ["cc.vendor_id = ?", "cc.status = 'complete'"];
$params = [$global_vendor_id];
if ($project_filter) { $wheres[] = "cc.project_id = ?"; $params[] = $project_filter; }
if (in_array($approval_filter, ['pending','approved','rejected'], true)) { $wheres[] = "cc.approval_status = ?"; $params[] = $approval_filter; }
$where_sql = implode(' AND ', $wheres);

$cnt = $pdo->prepare("SELECT COUNT(*) AS c FROM conversions cc WHERE $where_sql");
$cnt->execute($params);
$total = (int)$cnt->fetch()['c'];
$pagination = paginate($total, $per_page, $page);

$conv_stmt = $pdo->prepare("
    SELECT cc.*, p.project_code
    FROM conversions cc JOIN projects p ON p.id = cc.project_id
    WHERE $where_sql
    ORDER BY cc.converted_at DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}");
$conv_stmt->execute($params);
$conversions = $conv_stmt->fetchAll();

$page_title = 'Vendor Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Vendor dashboard for <?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="#4f46e5">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/app.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/app.css'); ?>" rel="stylesheet">
</head>
<body>

    <a class="tf-skip-link" href="#main-content">Skip to main content</a>

    <header class="tf-hero" style="border-radius: 0; margin-bottom: 0; padding: 1rem 1.5rem;">
        <div class="container-fluid" style="position: relative; z-index: 1;">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <a href="<?php echo BASE_URL; ?>/vendor_portal/index.php" class="d-flex align-items-center gap-2 text-white text-decoration-none">
                     <span class="tf-login-logo" style="width: 2.25rem; height: 2.25rem; font-size: 1.1rem; margin: 0;" aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></span>
                    <span class="fw-bold fs-5"><?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?> <span class="tf-hero-pill ms-1">Vendor</span></span>
                </a>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <span class="tf-hero-pill" aria-label="Vendor"><i class="bi bi-person-badge" aria-hidden="true"></i> <?php echo htmlspecialchars($vendor['vendor_code']); ?> — <?php echo htmlspecialchars($vendor['vendor_name']); ?></span>
                    <a href="<?php echo BASE_URL; ?>/vendor_portal/profile.php" class="btn btn-sm btn-outline-light">Profile</a>
                    <a href="<?php echo BASE_URL; ?>/vendor_portal/logout.php" class="btn btn-sm btn-outline-light">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <main id="main-content" class="tf-main" style="height: auto; min-height: calc(100vh - 4.5rem);">
        <div class="tf-page">

            <?php
            $flash = get_flash();
            if ($flash):
                $alertClasses = ['success' => 'alert-success', 'danger' => 'alert-danger', 'warning' => 'alert-warning', 'info' => 'alert-info'];
                $alertClass = $alertClasses[$flash['type']] ?? 'alert-info';
                $live = $flash['type'] === 'danger' ? 'role="alert" aria-live="assertive"' : 'role="status" aria-live="polite"';
            ?>
            <div class="alert <?php echo $alertClass; ?> mb-4" <?php echo $live; ?> id="flash-alert">
                <div class="alert-body"><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                <button type="button" class="alert-close" aria-label="Dismiss" onclick="document.getElementById('flash-alert').remove()">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>
            <?php endif; ?>

            <!-- KPIs -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-4 col-lg-3 col-xl"><article class="tf-stat"><div class="tf-stat-body"><p class="tf-stat-label">Clicks</p><p class="tf-stat-value"><?php echo number_format($k['clicks']); ?></p></div><div class="tf-stat-icon is-blue" aria-hidden="true"><i class="bi bi-cursor-fill"></i></div></article></div>
                <div class="col-6 col-md-4 col-lg-3 col-xl"><article class="tf-stat"><div class="tf-stat-body"><p class="tf-stat-label">Conversions</p><p class="tf-stat-value"><?php echo number_format($k['conversions']); ?></p></div><div class="tf-stat-icon is-indigo" aria-hidden="true"><i class="bi bi-check-circle-fill"></i></div></article></div>
                <div class="col-6 col-md-4 col-lg-3 col-xl"><article class="tf-stat"><div class="tf-stat-body"><p class="tf-stat-label">CCR</p><p class="tf-stat-value"><?php echo $k['ccr']; ?>%</p></div><div class="tf-stat-icon is-amber" aria-hidden="true"><i class="bi bi-percent"></i></div></article></div>
                <div class="col-6 col-md-4 col-lg-3 col-xl"><article class="tf-stat"><div class="tf-stat-body"><p class="tf-stat-label">Revenue</p><p class="tf-stat-value is-currency is-positive"><?php echo format_currency($k['vendor_revenue']); ?></p></div><div class="tf-stat-icon is-emerald" aria-hidden="true"><i class="bi bi-arrow-up-circle-fill"></i></div></article></div>
                <?php if ($show_network): ?>
                <div class="col-6 col-md-4 col-lg-3 col-xl"><article class="tf-stat"><div class="tf-stat-body"><p class="tf-stat-label">Network Cost</p><p class="tf-stat-value is-currency is-negative"><?php echo format_currency($k['network_cost']); ?></p></div><div class="tf-stat-icon is-red" aria-hidden="true"><i class="bi bi-arrow-down-circle-fill"></i></div></article></div>
                <?php endif; ?>
                <div class="col-6 col-md-4 col-lg-3 col-xl"><article class="tf-stat"><div class="tf-stat-body"><p class="tf-stat-label">Pending</p><p class="tf-stat-value is-warning"><?php echo number_format($k['pending']); ?></p></div><div class="tf-stat-icon is-amber" aria-hidden="true"><i class="bi bi-hourglass-split"></i></div></article></div>
                <?php if ($show_network): ?>
                <div class="col-6 col-md-4 col-lg-3 col-xl"><article class="tf-stat"><div class="tf-stat-body"><p class="tf-stat-label">Net Profit</p><p class="tf-stat-value is-currency <?php echo (float)$k['network_profit'] >= 0 ? 'is-positive' : 'is-negative'; ?>"><?php echo format_currency($k['network_profit']); ?></p></div><div class="tf-stat-icon is-slate" aria-hidden="true"><i class="bi bi-calculator-fill"></i></div></article></div>
                <?php endif; ?>
            </div>

            <!-- Chart -->
            <section class="tf-chart-card mb-4" aria-labelledby="vendor-chart-title">
                <div class="tf-chart-header">
                    <div>
                        <h5 id="vendor-chart-title" class="tf-card-title">Last 7 Days</h5>
                        <p class="tf-chart-subtitle">Clicks, conversions and revenue</p>
                    </div>
                </div>
                <div class="card-body" style="height: 300px; position: relative;">
                    <canvas id="vendorChart" role="img" aria-label="Bar and line chart showing last 7 days activity"></canvas>
                </div>
            </section>

            <!-- Attached projects + conversions -->
            <div class="row g-4">
                <div class="col-12 col-lg-3">
                    <section class="tf-card h-100" aria-labelledby="your-projects-title">
                        <div class="tf-card-header">
                            <h5 id="your-projects-title" class="tf-card-title">Your Projects</h5>
                        </div>
                        <ul class="list-group list-group-flush">
                            <?php if (empty($projects)): ?>
                            <li class="list-group-item text-muted small">No projects attached yet.</li>
                            <?php else: foreach ($projects as $p): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold small"><?php echo htmlspecialchars($p['project_code'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-secondary" style="font-size:.75rem"><?php echo htmlspecialchars($p['project_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <a href="<?php echo BASE_URL; ?>/vendor_portal/index.php?project_id=<?php echo (int)$p['id']; ?>" class="btn btn-outline-primary btn-sm" aria-label="Filter by <?php echo htmlspecialchars($p['project_code'], ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-filter" aria-hidden="true"></i></a>
                            </li>
                            <?php endforeach; endif; ?>
                        </ul>
                    </section>
                </div>

                <div class="col-12 col-lg-9">
                    <section class="tf-card" aria-labelledby="recent-conversions-title">
                        <div class="tf-card-header">
                            <h5 id="recent-conversions-title" class="tf-card-title">Recent Conversions</h5>
                            <form method="GET" class="d-flex gap-2">
                                <?php if ($project_filter): ?><input type="hidden" name="project_id" value="<?php echo (int)$project_filter; ?>"><?php endif; ?>
                                <label for="approval-filter" class="tf-visually-hidden">Filter by approval status</label>
                                <select id="approval-filter" name="approval" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">All statuses</option>
                                    <option value="pending" <?php echo $approval_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo $approval_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="rejected" <?php echo $approval_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </form>
                        </div>
                        <div class="tf-table-scroll">
                            <table class="tf-table">
                                <thead>
                                    <tr>
                                        <th>Project</th>
                                        <?php if ($show_network): ?><th class="is-numeric">Amount</th><?php endif; ?>
                                        <th class="is-numeric">Payout</th>
                                        <th>Status</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($conversions)): ?>
                                    <tr class="is-empty"><td colspan="<?php echo $show_network ? '5' : '4'; ?>" class="text-center py-5 text-muted">No conversions in this view.</td></tr>
                                    <?php else: foreach ($conversions as $c): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($c['project_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <?php if ($show_network): ?><td class="is-numeric"><?php echo format_currency($c['sale_amount'], $c['currency'] ?? 'USD'); ?></td><?php endif; ?>
                                        <td class="is-numeric text-success"><?php echo format_currency($c['vendor_cost'], $c['currency'] ?? 'USD'); ?></td>
                                        <td>
                                            <?php $cls = $c['approval_status'] === 'approved' ? 'bg-success' : ($c['approval_status'] === 'rejected' ? 'bg-danger' : 'bg-warning'); ?>
                                            <span class="badge <?php echo $cls; ?>"><?php echo ucfirst($c['approval_status']); ?></span>
                                        </td>
                                        <td class="small text-secondary"><?php echo htmlspecialchars(date('M j, H:i', strtotime($c['converted_at']))); ?></td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="tf-card-footer">
                            <?php echo render_pagination($pagination, BASE_URL . '/vendor_portal/index.php?' . http_build_query($_GET)); ?>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    const labels = <?php echo json_encode($labels); ?>;
    const clicksData = <?php echo json_encode($clicks_series); ?>;
    const convData = <?php echo json_encode($conv_series); ?>;
    const revData = <?php echo json_encode($rev_series); ?>;

    new Chart(document.getElementById('vendorChart'), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label: 'Clicks', data: clicksData, backgroundColor: 'rgba(79,70,229,.75)', borderRadius: 6, order: 2 },
                { label: 'Conversions', data: convData, type: 'line', borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.1)', fill: true, tension: .35, pointRadius: 0, order: 1 },
                { label: 'Revenue', data: revData, type: 'line', borderColor: '#f59e0b', borderDash: [4,4], fill: false, tension: .35, pointRadius: 0, order: 0 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true } }
        }
    });
    </script>
</body>
</html>
