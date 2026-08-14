<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();

// ─── Fetch Stats ───
$today = date('Y-m-d');
$today_label = date('l, F j, Y');

$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM projects WHERE status = 'live'");
$stmt->execute();
$active_projects = (int)$stmt->fetch()['cnt'];

$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM clicks WHERE DATE(clicked_at) = ?");
$stmt->execute([$today]);
$clicks_today = (int)$stmt->fetch()['cnt'];

$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM conversions WHERE DATE(converted_at) = ? AND status = 'complete'");
$stmt->execute([$today]);
$completes_today = (int)$stmt->fetch()['cnt'];

$stmt = $pdo->prepare("SELECT COALESCE(SUM(client_revenue), 0) as total FROM conversions WHERE DATE(converted_at) = ? AND status = 'complete'");
$stmt->execute([$today]);
$revenue_today = (float)$stmt->fetch()['total'];

$stmt = $pdo->prepare("SELECT COALESCE(SUM(vendor_cost), 0) as total FROM conversions WHERE DATE(converted_at) = ? AND status = 'complete'");
$stmt->execute([$today]);
$cost_today = (float)$stmt->fetch()['total'];

$profit_today = $revenue_today - $cost_today;
$profit_margin = $revenue_today > 0 ? round(($profit_today / $revenue_today) * 100, 1) : 0;
$ccr_today = calc_ccr($completes_today, $clicks_today);

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

// ─── Chart Data: Last 7 days ───
$chart_click_data = [];
$chart_conv_data = [];

$cc_stmt = $pdo->prepare("SELECT DATE(clicked_at) as d, COUNT(*) as cnt FROM clicks WHERE clicked_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(clicked_at)");
$cc_stmt->execute();
while ($row = $cc_stmt->fetch()) { $chart_click_data[$row['d']] = (int)$row['cnt']; }

$ccv_stmt = $pdo->prepare("SELECT DATE(converted_at) as d, COUNT(*) as cnt FROM conversions WHERE status = 'complete' AND converted_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(converted_at)");
$ccv_stmt->execute();
while ($row = $ccv_stmt->fetch()) { $chart_conv_data[$row['d']] = (int)$row['cnt']; }

$chart_labels = [];
$chart_clicks = [];
$chart_conversions = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chart_labels[] = date('M d', strtotime($d));
    $chart_clicks[] = $chart_click_data[$d] ?? 0;
    $chart_conversions[] = $chart_conv_data[$d] ?? 0;
}

$first_name = explode(' ', $user['username'] ?? 'there')[0];
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$page_title = 'Dashboard';
require_once __DIR__ . '/helpers/layout_header.php';
?>

<!-- Hero Header -->
<section class="tf-hero" aria-labelledby="dashboard-greeting">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-4 tf-hero-content">
        <div>
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <span class="tf-hero-pill"><span class="dot" aria-hidden="true"></span>Today</span>
                <span class="tf-hero-pill"><i class="bi bi-calendar-event" aria-hidden="true"></i> <?php echo $today_label; ?></span>
            </div>
            <h1 id="dashboard-greeting" class="tf-hero-title"><?php echo $greeting; ?>, <?php echo sanitize($first_name); ?></h1>
            <p class="tf-hero-subtitle">Here's what's happening with your campaigns today</p>
        </div>
        <div class="tf-hero-metrics">
            <div>
                <div class="tf-hero-metric-label">Net Profit</div>
                <div class="tf-hero-metric-value is-accent"><?php echo format_currency($profit_today); ?></div>
            </div>
            <div>
                <div class="tf-hero-metric-label">Revenue</div>
                <div class="tf-hero-metric-value"><?php echo format_currency($revenue_today); ?></div>
            </div>
            <div>
                <div class="tf-hero-metric-label">Cost</div>
                <div class="tf-hero-metric-value"><?php echo format_currency($cost_today); ?></div>
            </div>
        </div>
    </div>
</section>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4 col-xl-2">
        <article class="tf-stat">
            <div class="tf-stat-body">
                <p class="tf-stat-label">Active Projects</p>
                <p class="tf-stat-value"><?php echo number_format($active_projects); ?></p>
            </div>
            <div class="tf-stat-icon is-indigo" aria-hidden="true"><i class="bi bi-folder2-open"></i></div>
        </article>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <article class="tf-stat">
            <div class="tf-stat-body">
                <p class="tf-stat-label">Clicks Today</p>
                <p class="tf-stat-value"><?php echo number_format($clicks_today); ?></p>
            </div>
            <div class="tf-stat-icon is-blue" aria-hidden="true"><i class="bi bi-cursor-fill"></i></div>
        </article>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <article class="tf-stat">
            <div class="tf-stat-body">
                <p class="tf-stat-label">Completes Today</p>
                <p class="tf-stat-value"><?php echo number_format($completes_today); ?></p>
            </div>
            <div class="tf-stat-icon is-emerald" aria-hidden="true"><i class="bi bi-check-circle-fill"></i></div>
        </article>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <article class="tf-stat">
            <div class="tf-stat-body">
                <p class="tf-stat-label">CCR Today</p>
                <p class="tf-stat-value"><?php echo $ccr_today; ?>%</p>
            </div>
            <div class="tf-stat-icon is-amber" aria-hidden="true"><i class="bi bi-percent"></i></div>
        </article>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <article class="tf-stat">
            <div class="tf-stat-body">
                <p class="tf-stat-label">Revenue Today</p>
                <p class="tf-stat-value is-currency is-positive"><?php echo format_currency($revenue_today); ?></p>
            </div>
            <div class="tf-stat-icon is-emerald" aria-hidden="true"><i class="bi bi-arrow-up-circle-fill"></i></div>
        </article>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <article class="tf-stat">
            <div class="tf-stat-body">
                <p class="tf-stat-label">Cost Today</p>
                <p class="tf-stat-value is-currency is-negative"><?php echo format_currency($cost_today); ?></p>
            </div>
            <div class="tf-stat-icon is-red" aria-hidden="true"><i class="bi bi-arrow-down-circle-fill"></i></div>
        </article>
    </div>
</div>

<!-- Chart & Recent Projects -->
<div class="row g-3 mb-4">
    <div class="col-12 col-xl-8">
        <section class="tf-chart-card h-100" aria-labelledby="chart-title">
            <div class="tf-chart-header">
                <div>
                    <h5 id="chart-title">Activity Overview</h5>
                    <p class="tf-chart-subtitle">Clicks and conversions over the last 7 days</p>
                </div>
                <div class="tf-chart-toolbar">
                    <div class="tf-chart-legend" aria-hidden="true">
                        <span class="tf-chart-legend-item"><span class="tf-chart-legend-dot" style="background: var(--tf-primary-500);"></span>Clicks</span>
                        <span class="tf-chart-legend-item"><span class="tf-chart-legend-dot" style="background: var(--tf-success-600);"></span>Conversions</span>
                    </div>
                    <div class="tf-chart-toggle" role="tablist" aria-label="Chart view">
                        <button type="button" class="view-btn is-active" data-view="bar" role="tab" aria-selected="true" aria-label="Bar chart view">
                            <i class="bi bi-bar-chart-fill" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="view-btn" data-view="line" role="tab" aria-selected="false" aria-label="Line chart view">
                            <i class="bi bi-graph-up" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="tf-chart-body" style="height: 360px;">
                <p class="tf-visually-hidden">Last 7 days: <?php echo (int)array_sum($chart_clicks); ?> clicks and <?php echo (int)array_sum($chart_conversions); ?> conversions.</p>
                <canvas id="dashboardChart" role="img" aria-label="Chart showing clicks and conversions over the last 7 days"></canvas>
            </div>
        </section>
    </div>

    <div class="col-12 col-xl-4">
        <section class="tf-card h-100" aria-labelledby="recent-projects-title">
            <div class="tf-card-header">
                <div>
                    <h5 id="recent-projects-title" class="tf-card-title">Recent Projects</h5>
                    <p class="tf-card-subtitle">Latest campaign activity</p>
                </div>
                <a href="<?php echo BASE_URL; ?>/projects/list.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="tf-card-body is-flush" style="max-height: 420px; overflow-y: auto;">
                <?php if (empty($recent_projects)): ?>
                    <div class="tf-empty">
                        <i class="bi bi-folder2-open" aria-hidden="true"></i>
                        <h5>No projects yet</h5>
                        <p>Get started by creating your first campaign project.</p>
                            <a href="<?php echo BASE_URL; ?>/projects/create.php" class="tf-empty-action" aria-label="Create new project">
                                <span class="tf-create-icon" aria-hidden="true">+</span>
                                <span>Create Project</span>
                            </a>
                    </div>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach (array_slice($recent_projects, 0, 5) as $p): ?>
                        <li class="project-row">
                            <div class="proj-icon" aria-hidden="true"><i class="bi bi-folder2-open"></i></div>
                            <div class="min-w-0 flex-grow-1">
                                <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $p['id']; ?>" class="project-name d-block text-truncate">
                                    <?php echo sanitize($p['project_name']); ?>
                                    <span class="tf-visually-hidden">project code <?php echo sanitize($p['project_code']); ?></span>
                                </a>
                                <div class="d-flex align-items-center gap-2 mt-1">
                                    <span class="project-code"><?php echo sanitize($p['project_code']); ?></span>
                                    <span class="client-name text-truncate"><?php echo sanitize($p['client_name'] ?? '—'); ?></span>
                                </div>
                            </div>
                            <div class="flex-shrink-0">
                                <?php echo status_badge($p['status']); ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
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
Chart.defaults.borderColor = '#f1f5f9';

const labels = {$chart_labels_json};
const clicksData = {$chart_clicks_json};
const convData = {$chart_conv_json};

const clicksBar = (ctx) => {
    const c = ctx.chart.ctx;
    const g = c.createLinearGradient(0, 0, 0, 320);
    g.addColorStop(0, 'rgba(20, 184, 166, .95)');
    g.addColorStop(1, 'rgba(20, 184, 166, .55)');
    return g;
};
const convBar = (ctx) => {
    const c = ctx.chart.ctx;
    const g = c.createLinearGradient(0, 0, 0, 320);
    g.addColorStop(0, 'rgba(16, 185, 129, .95)');
    g.addColorStop(1, 'rgba(16, 185, 129, .55)');
    return g;
};
const clicksLine = (ctx) => {
    const c = ctx.chart.ctx;
    const g = c.createLinearGradient(0, 0, 0, 320);
    g.addColorStop(0, 'rgba(20, 184, 166, .28)');
    g.addColorStop(1, 'rgba(20, 184, 166, 0)');
    return g;
};
const convLine = (ctx) => {
    const c = ctx.chart.ctx;
    const g = c.createLinearGradient(0, 0, 0, 320);
    g.addColorStop(0, 'rgba(16, 185, 129, .25)');
    g.addColorStop(1, 'rgba(16, 185, 129, 0)');
    return g;
};

const buildDatasets = (view) => view === 'bar'
    ? [
        { label: 'Clicks', data: clicksData, backgroundColor: clicksBar, hoverBackgroundColor: '#0f766e', borderRadius: { topLeft: 6, topRight: 6 }, borderSkipped: false, barPercentage: 0.65, categoryPercentage: 0.7 },
        { label: 'Conversions', data: convData, backgroundColor: convBar, hoverBackgroundColor: '#059669', borderRadius: { topLeft: 6, topRight: 6 }, borderSkipped: false, barPercentage: 0.65, categoryPercentage: 0.7 }
      ]
    : [
        { label: 'Clicks', data: clicksData, borderColor: '#14b8a6', backgroundColor: clicksLine, fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#14b8a6', pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2, borderWidth: 2.5 },
        { label: 'Conversions', data: convData, borderColor: '#10b981', backgroundColor: convLine, fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#10b981', pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2, borderWidth: 2.5 }
      ];

const baseOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    animation: { duration: 600, easing: 'easeOutCubic' },
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: 'rgba(15, 23, 42, .96)',
            titleColor: '#f8fafc',
            titleFont: { size: 12, weight: '700' },
            bodyColor: '#cbd5e1',
            bodyFont: { size: 13 },
            padding: { top: 10, right: 12, bottom: 10, left: 12 },
            cornerRadius: 10,
            borderColor: 'rgba(20, 184, 166, .35)',
            borderWidth: 1,
            displayColors: true,
            boxWidth: 8, boxHeight: 8, usePointStyle: true, boxPadding: 4
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
            ticks: { padding: 12, color: '#94a3b8', font: { size: 11 }, precision: 0 }
        }
    }
};

let chart = null;
const ctx = document.getElementById('dashboardChart');

function renderChart(view) {
    if (chart) chart.destroy();
    chart = new Chart(ctx, {
        type: view === 'bar' ? 'bar' : 'line',
        data: { labels, datasets: buildDatasets(view) },
        options: baseOptions
    });
}

renderChart('bar');

document.querySelectorAll('.tf-chart-toggle .view-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tf-chart-toggle .view-btn').forEach(b => {
            b.classList.remove('is-active');
            b.setAttribute('aria-selected', 'false');
        });
        btn.classList.add('is-active');
        btn.setAttribute('aria-selected', 'true');
        renderChart(btn.dataset.view);
    });
});
</script>
EOT;

require_once __DIR__ . '/helpers/layout_footer.php';
?>
