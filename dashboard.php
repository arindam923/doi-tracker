<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();

// ─── Fetch Stats ───
$today = date('Y-m-d');

$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM projects WHERE status = 'live'");
$stmt->execute();
$active_projects = $stmt->fetch()['cnt'];

$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM clicks WHERE DATE(clicked_at) = ?");
$stmt->execute([$today]);
$clicks_today = $stmt->fetch()['cnt'];

$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM conversions WHERE DATE(converted_at) = ? AND status = 'complete'");
$stmt->execute([$today]);
$completes_today = $stmt->fetch()['cnt'];

$stmt = $pdo->prepare("SELECT COALESCE(SUM(client_revenue), 0) as total FROM conversions WHERE DATE(converted_at) = ? AND status = 'complete'");
$stmt->execute([$today]);
$revenue_today = $stmt->fetch()['total'];

$stmt = $pdo->prepare("SELECT COALESCE(SUM(vendor_cost), 0) as total FROM conversions WHERE DATE(converted_at) = ? AND status = 'complete'");
$stmt->execute([$today]);
$cost_today = $stmt->fetch()['total'];

$profit_today = $revenue_today - $cost_today;

// ─── Recent Projects ───
$stmt = $pdo->prepare("
    SELECT p.*, c.client_name
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    ORDER BY p.updated_at DESC
    LIMIT 10
");
$stmt->execute();
$recent_projects = $stmt->fetchAll();

// ─── Chart Data: Last 7 days — single queries ───
$chart_labels = [];
$chart_clicks = [];
$chart_conversions = [];

$chart_click_data = [];
$chart_conv_data = [];

$cc_stmt = $pdo->prepare("SELECT DATE(clicked_at) as d, COUNT(*) as cnt FROM clicks WHERE clicked_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(clicked_at)");
$cc_stmt->execute();
while ($row = $cc_stmt->fetch()) { $chart_click_data[$row['d']] = $row['cnt']; }

$ccv_stmt = $pdo->prepare("SELECT DATE(converted_at) as d, COUNT(*) as cnt FROM conversions WHERE status = 'complete' AND converted_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(converted_at)");
$ccv_stmt->execute();
while ($row = $ccv_stmt->fetch()) { $chart_conv_data[$row['d']] = $row['cnt']; }

for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chart_labels[] = date('M d', strtotime($d));
    $chart_clicks[] = $chart_click_data[$d] ?? 0;
    $chart_conversions[] = $chart_conv_data[$d] ?? 0;
}

$ccr_today = calc_ccr($completes_today, $clicks_today);

$page_title = 'Dashboard';
require_once __DIR__ . '/helpers/layout_header.php';
?>

<style>
    /* Dashboard polish on top of Bootstrap */
    .stat-card { transition: transform .15s ease, box-shadow .15s ease; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1.25rem rgba(15, 23, 42, .08) !important; }
    .stat-card .stat-label { font-size: .7rem; letter-spacing: .06em; color: #64748b; }
    .stat-card .stat-value { font-size: 1.875rem; line-height: 1.1; color: #0f172a; font-weight: 700; margin: 0; }
    .stat-card .stat-icon { width: 3rem; height: 3rem; flex-shrink: 0; }

    .project-row { padding: 1rem 1.25rem; border-bottom: 1px solid #e2e8f0; transition: background-color .15s ease; }
    .project-row:last-child { border-bottom: 0; }
    .project-row:hover { background-color: #f8fafc; }
    .project-row .project-name { color: #0f172a; font-weight: 600; font-size: .875rem; text-decoration: none; }
    .project-row .project-name:hover { color: #4f46e5; }
    .project-row .project-code { background: #f1f5f9; color: #475569; padding: .125rem .5rem; border-radius: 4px; font-size: .75rem; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .badge.ccr-badge { font-size: .9rem; padding: .5rem .9rem; font-weight: 600; }
</style>

<div class="container-fluid p-0">

    <!-- Main Stats -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card stat-card border-0 shadow-sm h-100">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div class="flex-grow-1 min-w-0">
                            <p class="stat-label text-uppercase fw-semibold mb-2">Active Projects</p>
                            <h2 class="stat-value"><?php echo number_format($active_projects); ?></h2>
                        </div>
                        <div class="stat-icon rounded-3 d-flex align-items-center justify-content-center bg-primary-subtle text-primary">
                            <i class="bi bi-folder2-open fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card stat-card border-0 shadow-sm h-100">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div class="flex-grow-1 min-w-0">
                            <p class="stat-label text-uppercase fw-semibold mb-2">Clicks Today</p>
                            <h2 class="stat-value"><?php echo number_format($clicks_today); ?></h2>
                        </div>
                        <div class="stat-icon rounded-3 d-flex align-items-center justify-content-center bg-info-subtle text-info">
                            <i class="bi bi-cursor-fill fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card stat-card border-0 shadow-sm h-100">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div class="flex-grow-1 min-w-0">
                            <p class="stat-label text-uppercase fw-semibold mb-2">Completes Today</p>
                            <h2 class="stat-value"><?php echo number_format($completes_today); ?></h2>
                        </div>
                        <div class="stat-icon rounded-3 d-flex align-items-center justify-content-center bg-success-subtle text-success">
                            <i class="bi bi-check-circle-fill fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card stat-card border-0 shadow-sm h-100">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div class="flex-grow-1 min-w-0">
                            <p class="stat-label text-uppercase fw-semibold mb-2">Revenue Today</p>
                            <h2 class="stat-value"><?php echo format_currency($revenue_today); ?></h2>
                        </div>
                        <div class="stat-icon rounded-3 d-flex align-items-center justify-content-center bg-warning-subtle text-warning-emphasis">
                            <i class="bi bi-currency-dollar fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Profit Summary -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="card stat-card border-0 shadow-sm h-100">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div class="flex-grow-1 min-w-0">
                            <p class="stat-label text-uppercase fw-semibold mb-2">Total Cost Today</p>
                            <h2 class="stat-value text-danger"><?php echo format_currency($cost_today); ?></h2>
                        </div>
                        <div class="stat-icon rounded-3 d-flex align-items-center justify-content-center bg-danger-subtle text-danger">
                            <i class="bi bi-receipt fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="card stat-card border-0 shadow-sm h-100">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div class="flex-grow-1 min-w-0">
                            <p class="stat-label text-uppercase fw-semibold mb-2">Gross Profit Today</p>
                            <h2 class="stat-value <?php echo $profit_today >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo format_currency($profit_today); ?>
                            </h2>
                        </div>
                        <div class="stat-icon rounded-3 d-flex align-items-center justify-content-center <?php echo $profit_today >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'; ?>">
                            <i class="bi bi-graph-up-arrow fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="card stat-card border-0 shadow-sm h-100">
                <div class="card-body p-3 p-md-4">
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div class="flex-grow-1 min-w-0">
                            <p class="stat-label text-uppercase fw-semibold mb-2">CCR Today</p>
                            <span class="badge ccr-badge bg-<?php echo ccr_color($ccr_today); ?>"><?php echo $ccr_today; ?>%</span>
                        </div>
                        <div class="stat-icon rounded-3 d-flex align-items-center justify-content-center bg-primary-subtle text-primary">
                            <i class="bi bi-percent fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart & Recent Projects -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 fw-semibold">Clicks &amp; Conversions</h5>
                    <span class="badge bg-light text-secondary border">Last 7 Days</span>
                </div>
                <div class="card-body">
                    <div style="height: 320px;">
                        <canvas id="dashboardChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 fw-semibold">Recent Projects</h5>
                    <a href="<?php echo BASE_URL; ?>/projects/list.php" class="text-decoration-none small fw-semibold">View All</a>
                </div>
                <div style="max-height: 380px; overflow-y: auto;">
                    <?php if (empty($recent_projects)): ?>
                        <div class="text-center py-5 px-3 text-muted">
                            <i class="bi bi-folder2-open d-block mb-2" style="font-size: 2.5rem; color: #cbd5e1;"></i>
                            <p class="fw-semibold text-dark mb-2">No projects yet</p>
                            <a href="<?php echo BASE_URL; ?>/projects/create.php" class="btn btn-primary btn-sm">
                                <i class="bi bi-plus-lg"></i> Create Project
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($recent_projects, 0, 5) as $p): ?>
                        <div class="project-row d-flex align-items-center justify-content-between gap-2">
                            <div class="min-w-0 flex-grow-1">
                                <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $p['id']; ?>" class="project-name d-block text-truncate mb-1">
                                    <?php echo sanitize($p['project_name']); ?>
                                </a>
                                <div class="d-flex align-items-center gap-2 small text-muted">
                                    <span class="project-code"><?php echo sanitize($p['project_code']); ?></span>
                                    <span>&bull;</span>
                                    <span class="text-truncate"><?php echo sanitize($p['client_name'] ?? '-'); ?></span>
                                </div>
                            </div>
                            <div class="flex-shrink-0">
                                <?php echo status_badge($p['status']); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<?php
$chart_labels_json = json_encode($chart_labels);
$chart_clicks_json = json_encode($chart_clicks);
$chart_conv_json = json_encode($chart_conversions);

$extra_js = <<<EOT
<script>
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#64748b';

new Chart(document.getElementById('dashboardChart'), {
    type: 'bar',
    data: {
        labels: {$chart_labels_json},
        datasets: [
            {
                label: 'Clicks',
                data: {$chart_clicks_json},
                backgroundColor: 'rgba(99, 102, 241, 0.85)',
                hoverBackgroundColor: 'rgba(79, 70, 229, 1)',
                borderRadius: 4,
                borderSkipped: false,
                barPercentage: 0.6,
                categoryPercentage: 0.8
            },
            {
                label: 'Conversions',
                data: {$chart_conv_json},
                backgroundColor: 'rgba(16, 185, 129, 0.85)',
                hoverBackgroundColor: 'rgba(5, 150, 105, 1)',
                borderRadius: 4,
                borderSkipped: false,
                barPercentage: 0.6,
                categoryPercentage: 0.8
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 8, padding: 20 } },
            tooltip: {
                backgroundColor: 'rgba(15, 23, 42, 0.9)',
                titleFont: { size: 13, weight: '600' },
                bodyFont: { size: 13 },
                padding: 12,
                cornerRadius: 8,
                displayColors: false
            }
        },
        scales: {
            y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9', drawBorder: false } },
            x: { grid: { display: false, drawBorder: false } }
        },
        interaction: { intersect: false, mode: 'index' }
    }
});
</script>
EOT;

require_once __DIR__ . '/helpers/layout_footer.php';
?>
